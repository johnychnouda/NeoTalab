/**
 * Automatically send WhatsApp reminders before merchant subscriptions renew.
 */
import { query } from "../db.js";
import { logger } from "../utils/logger.js";
import { sendPlatformWhatsApp } from "./PlatformWhatsAppService.js";

const MS_PER_DAY = 86400000;

const DEFAULT_REMINDER_MSG = `Hello {shop} 👋

Your NeoTalab subscription renews in {days} day(s) — on {date}.

💳 Amount due: {amount}

Please settle your payment before then to keep your WhatsApp bot running smoothly.

Need help? Just reply here! 🙌`;

async function ensureReminderSchema() {
  await query(`
    ALTER TABLE merchants ADD COLUMN IF NOT EXISTS last_payment_at TIMESTAMPTZ
  `);
  await query(`
    ALTER TABLE merchants ADD COLUMN IF NOT EXISTS billing_cycle TEXT NOT NULL DEFAULT 'monthly'
  `);
  await query(`
    ALTER TABLE merchants ADD COLUMN IF NOT EXISTS yearly_fee NUMERIC(10,2)
  `);
  await query(`
    CREATE TABLE IF NOT EXISTS subscription_reminder_log (
      id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
      merchant_id   UUID NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
      renewal_date  DATE NOT NULL,
      sent_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      UNIQUE (merchant_id, renewal_date)
    )
  `);
}

export function getMerchantRenewalDate(m) {
  if (m.trial_ends_at && !m.last_payment_at && !m.subscription_ends) {
    return new Date(m.trial_ends_at);
  }
  if (m.subscription_ends) return new Date(m.subscription_ends);
  if (m.last_payment_at) {
    const d = new Date(m.last_payment_at);
    if (m.billing_cycle === "yearly") d.setFullYear(d.getFullYear() + 1);
    else d.setMonth(d.getMonth() + 1);
    return d;
  }
  return null;
}

function merchantFee(m) {
  const cycle = m.billing_cycle || "monthly";
  const amount = cycle === "yearly"
    ? parseFloat(m.yearly_fee ?? m.monthly_fee ?? 0)
    : parseFloat(m.monthly_fee ?? 0);
  return `$${Number.isInteger(amount) ? amount : amount.toFixed(2)}`;
}

function formatRenewalDate(d) {
  return d.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" });
}

function daysUntil(date) {
  const now = new Date();
  const today = Date.UTC(now.getFullYear(), now.getMonth(), now.getDate());
  const target = Date.UTC(date.getFullYear(), date.getMonth(), date.getDate());
  return Math.round((target - today) / MS_PER_DAY);
}

function buildMessage(template, m, renewal, daysLeft) {
  return template
    .replace(/{shop}/g, m.shop_name)
    .replace(/{days}/g, String(daysLeft))
    .replace(/{date}/g, formatRenewalDate(renewal))
    .replace(/{amount}/g, merchantFee(m));
}

function recipientPhone(m) {
  return m.ops_whatsapp || m.whatsapp_number;
}

async function alreadySent(merchantId, renewalDate) {
  const dateStr = renewalDate.toISOString().slice(0, 10);
  const { rows } = await query(
    `SELECT id FROM subscription_reminder_log
     WHERE merchant_id = $1 AND renewal_date = $2::date`,
    [merchantId, dateStr]
  );
  return rows.length > 0;
}

async function logSent(merchantId, renewalDate) {
  const dateStr = renewalDate.toISOString().slice(0, 10);
  await query(
    `INSERT INTO subscription_reminder_log (merchant_id, renewal_date)
     VALUES ($1, $2::date)
     ON CONFLICT (merchant_id, renewal_date) DO NOTHING`,
    [merchantId, dateStr]
  );
}

/**
 * Find merchants due for a reminder today and send WhatsApp messages.
 */
export async function processRenewalReminders() {
  await ensureReminderSchema();

  const { rows: psRows } = await query("SELECT * FROM platform_settings WHERE id = 1");
  const ps = psRows[0];
  if (!ps) {
    logger.debug("Renewal reminders skipped — no platform settings");
    return { sent: 0, skipped: 0 };
  }

  const remindDays = ps.overdue_remind_days ?? 3;
  const template = ps.overdue_reminder_msg || DEFAULT_REMINDER_MSG;

  const { rows: merchants } = await query(`
    SELECT id, shop_name, whatsapp_number, ops_whatsapp, status,
           trial_ends_at, subscription_ends, last_payment_at,
           billing_cycle, monthly_fee, yearly_fee
    FROM merchants
    WHERE status IN ('active', 'pending')
  `);

  let sent = 0;
  let skipped = 0;

  for (const m of merchants) {
    const renewal = getMerchantRenewalDate(m);
    if (!renewal) {
      skipped++;
      continue;
    }

    const daysLeft = daysUntil(renewal);
    if (daysLeft !== remindDays) {
      skipped++;
      continue;
    }

    const phone = recipientPhone(m);
    if (!phone) {
      skipped++;
      continue;
    }

    if (await alreadySent(m.id, renewal)) {
      skipped++;
      continue;
    }

    const text = buildMessage(template, m, renewal, daysLeft);

    try {
      await sendPlatformWhatsApp(phone, text);
      await logSent(m.id, renewal);
      sent++;
    } catch (err) {
      logger.error("Renewal reminder failed", { merchantId: m.id, shop: m.shop_name, error: err.message });
    }
  }

  if (sent > 0) {
    logger.info("Renewal reminders processed", { sent, skipped, remindDays });
  } else {
    logger.debug("Renewal reminders processed", { sent, skipped, remindDays });
  }

  return { sent, skipped };
}
