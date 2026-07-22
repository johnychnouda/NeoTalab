/**
 * Owner routes — full platform control
 * All routes require owner JWT
 */
import { Router } from "express";
import bcrypt from "bcrypt";
import { v4 as uuidv4 } from "uuid";
import { query, transaction } from "../db.js";
import { requireAuth, requireOwner, signToken } from "../middleware/auth.js";
import { ok, created, err, notFound, unauthorized } from "../utils/response.js";

const router = Router();
router.use(requireAuth, requireOwner);

const DEFAULT_RENEWAL_MSG = `Hello {shop} 👋

Your NeoTalab subscription renews in {days} day(s) — on {date}.

💳 Amount due: {amount}

Please settle your payment before then to keep your WhatsApp bot running smoothly.

Need help? Just reply here! 🙌`;

async function ensurePlatformSettings() {
  await query(
    `INSERT INTO platform_settings (id) VALUES (1) ON CONFLICT (id) DO NOTHING`
  );
}

async function ensureOwnerSecurityColumns() {
  await query(`ALTER TABLE owners ADD COLUMN IF NOT EXISTS password_changed_at TIMESTAMPTZ`);
  await query(`
    ALTER TABLE owners ADD COLUMN IF NOT EXISTS tokens_valid_after TIMESTAMPTZ NOT NULL DEFAULT '1970-01-01'
  `);
}

function rowToSettings(owner, ps) {
  return {
    ownerName: owner.name,
    ownerEmail: owner.email,
    passwordChangedAt: owner.password_changed_at || null,
    platformName: ps.platform_name,
    currency: ps.currency,
    timezone: ps.timezone,
    subscriptionPrice: parseFloat(ps.subscription_price),
    subscriptionYearlyPrice: parseFloat(ps.subscription_yearly_price),
    trialDays: ps.trial_days,
    gracePeriodDays: ps.grace_period_days,
    waPhoneId: ps.wa_phone_id || "",
    waToken: ps.wa_access_token || "",
    waVerifyToken: ps.wa_verify_token || "",
    overdueDays: ps.overdue_remind_days,
    tokenWarnDays: ps.token_warn_days,
    overdueReminderMsg: ps.overdue_reminder_msg || DEFAULT_RENEWAL_MSG,
  };
}

async function loadOwnerSettings(ownerId) {
  await ensurePlatformSettings();
  await ensureOwnerSecurityColumns();
  const [{ rows: ownerRows }, { rows: psRows }] = await Promise.all([
    query(
      "SELECT id, name, email, password_changed_at FROM owners WHERE id = $1",
      [ownerId]
    ),
    query("SELECT * FROM platform_settings WHERE id = 1"),
  ]);
  if (!ownerRows[0] || !psRows[0]) return null;
  return rowToSettings(ownerRows[0], psRows[0]);
}

// ── PLATFORM SETTINGS ────────────────────────────────────────

// GET /owner/settings
router.get("/settings", async (req, res, next) => {
  try {
    const settings = await loadOwnerSettings(req.auth.id);
    if (!settings) return notFound(res, "Owner not found");
    ok(res, { settings });
  } catch (e) { next(e); }
});

// PATCH /owner/settings
router.patch("/settings", async (req, res, next) => {
  try {
    const b = req.body;

    // Owner profile fields
    if (b.ownerName !== undefined || b.ownerEmail !== undefined) {
      const updates = [];
      const values = [];
      if (b.ownerName !== undefined) {
        const name = String(b.ownerName).trim();
        if (!name) return err(res, "Owner name can't be empty");
        values.push(name);
        updates.push(`name = $${values.length}`);
      }
      if (b.ownerEmail !== undefined) {
        const email = String(b.ownerEmail).trim().toLowerCase();
        if (!email) return err(res, "Email can't be empty");
        const dup = await query(
          "SELECT id FROM owners WHERE email = $1 AND id != $2",
          [email, req.auth.id]
        );
        if (dup.rows.length) return err(res, "Email already in use", 409);
        values.push(email);
        updates.push(`email = $${values.length}`);
      }
      values.push(req.auth.id);
      await query(
        `UPDATE owners SET ${updates.join(", ")} WHERE id = $${values.length}`,
        values
      );
    }

    // Platform settings fields
    const psMap = {
      platformName: "platform_name",
      currency: "currency",
      timezone: "timezone",
      subscriptionPrice: "subscription_price",
      subscriptionYearlyPrice: "subscription_yearly_price",
      trialDays: "trial_days",
      gracePeriodDays: "grace_period_days",
      waPhoneId: "wa_phone_id",
      waToken: "wa_access_token",
      waVerifyToken: "wa_verify_token",
      overdueDays: "overdue_remind_days",
      tokenWarnDays: "token_warn_days",
      overdueReminderMsg: "overdue_reminder_msg",
    };

    const psUpdates = [];
    const psValues = [];
    for (const [camel, col] of Object.entries(psMap)) {
      if (b[camel] !== undefined) {
        psValues.push(b[camel]);
        psUpdates.push(`${col} = $${psValues.length}`);
      }
    }

    if (psUpdates.length) {
      await ensurePlatformSettings();
      psValues.push(1);
      await query(
        `UPDATE platform_settings SET ${psUpdates.join(", ")} WHERE id = $${psValues.length}`,
        psValues
      );
    }

    const settings = await loadOwnerSettings(req.auth.id);
    ok(res, { settings, message: "Settings saved" });
  } catch (e) { next(e); }
});

// PATCH /owner/password
router.patch("/password", async (req, res, next) => {
  try {
    await ensureOwnerSecurityColumns();
    const { currentPassword, newPassword } = req.body;
    if (!currentPassword || !newPassword) return err(res, "Current and new password required");
    if (String(newPassword).length < 8) return err(res, "New password must be at least 8 characters");

    const { rows } = await query(
      "SELECT id, name, password_hash FROM owners WHERE id = $1",
      [req.auth.id]
    );
    if (!rows[0]) return notFound(res, "Owner not found");

    const valid = await bcrypt.compare(currentPassword, rows[0].password_hash);
    if (!valid) return unauthorized(res, "Current password is incorrect");

    const passwordHash = await bcrypt.hash(newPassword, 12);
    const { rows: updated } = await query(
      `UPDATE owners
       SET password_hash = $1, password_changed_at = NOW(), tokens_valid_after = NOW()
       WHERE id = $2
       RETURNING password_changed_at`,
      [passwordHash, req.auth.id]
    );

    const token = signToken({ id: rows[0].id, role: "owner", name: rows[0].name });
    ok(res, {
      token,
      passwordChangedAt: updated[0].password_changed_at,
      message: "Password updated",
    });
  } catch (e) { next(e); }
});

// POST /owner/sessions/revoke — sign out everywhere except this device
router.post("/sessions/revoke", async (req, res, next) => {
  try {
    await ensureOwnerSecurityColumns();
    const { rows } = await query(
      "SELECT id, name FROM owners WHERE id = $1",
      [req.auth.id]
    );
    if (!rows[0]) return notFound(res, "Owner not found");

    await query(
      "UPDATE owners SET tokens_valid_after = NOW() WHERE id = $1",
      [req.auth.id]
    );

    const token = signToken({ id: rows[0].id, role: "owner", name: rows[0].name });
    ok(res, { token, message: "Signed out on all other devices" });
  } catch (e) { next(e); }
});

// ── PLATFORM DASHBOARD ──────────────────────────────────────

// GET /owner/stats
router.get("/stats", async (req, res, next) => {
  try {
    const [merchants, orders, customers] = await Promise.all([
      query("SELECT COUNT(*) FROM merchants WHERE status = 'active'"),
      query("SELECT COUNT(*), COALESCE(SUM(total),0) as revenue FROM orders WHERE created_at >= NOW() - INTERVAL '30 days'"),
      query("SELECT COUNT(*) FROM customers WHERE created_at >= NOW() - INTERVAL '30 days'"),
    ]);

    const [pending] = await Promise.all([
      query("SELECT COUNT(*) FROM onboarding_requests WHERE status = 'pending'"),
    ]);

    ok(res, {
      stats: {
        activeMerchants: parseInt(merchants.rows[0].count),
        ordersLast30Days: parseInt(orders.rows[0].count),
        revenueLast30Days: parseFloat(orders.rows[0].revenue),
        newCustomersLast30Days: parseInt(customers.rows[0].count),
        pendingOnboarding: parseInt(pending.rows[0].count),
      },
    });
  } catch (e) { next(e); }
});

// ── MERCHANTS ────────────────────────────────────────────────

// GET /owner/merchants
router.get("/merchants", async (req, res, next) => {
  try {
    const { status, search, limit = 50, offset = 0 } = req.query;

    let sql = `
      SELECT m.id, m.shop_name, m.email, m.whatsapp_number, m.ops_whatsapp,
             m.status, m.plan, m.monthly_fee, m.mode, m.default_locale,
             m.subscription_ends, m.created_at,
             COUNT(DISTINCT o.id) as total_orders,
             COALESCE(SUM(o.total) FILTER (WHERE o.status = 'delivered'), 0) as total_revenue
      FROM merchants m
      LEFT JOIN orders o ON o.merchant_id = m.id
      WHERE 1=1
    `;
    const params = [];

    if (status) {
      params.push(status);
      sql += ` AND m.status = $${params.length}`;
    }
    if (search) {
      params.push(`%${search}%`);
      sql += ` AND (m.shop_name ILIKE $${params.length} OR m.email ILIKE $${params.length} OR m.whatsapp_number ILIKE $${params.length})`;
    }

    sql += ` GROUP BY m.id ORDER BY m.created_at DESC LIMIT $${params.length + 1} OFFSET $${params.length + 2}`;
    params.push(limit, offset);

    const { rows } = await query(sql, params);
    const total = await query("SELECT COUNT(*) FROM merchants WHERE 1=1" + (status ? ` AND status = '${status}'` : ""));

    ok(res, { merchants: rows, total: parseInt(total.rows[0].count) });
  } catch (e) { next(e); }
});

// GET /owner/merchants/:id
router.get("/merchants/:id", async (req, res, next) => {
  try {
    const { rows } = await query(
      `SELECT m.*,
        COUNT(DISTINCT o.id) as total_orders,
        COALESCE(SUM(o.total) FILTER (WHERE o.status = 'delivered'), 0) as total_revenue,
        COUNT(DISTINCT d.id) as total_drivers,
        COUNT(DISTINCT c.id) as total_customers
       FROM merchants m
       LEFT JOIN orders o ON o.merchant_id = m.id
       LEFT JOIN drivers d ON d.merchant_id = m.id
       LEFT JOIN customers c ON c.merchant_id = m.id
       WHERE m.id = $1
       GROUP BY m.id`,
      [req.params.id]
    );

    if (!rows[0]) return notFound(res, "Merchant not found");

    // Remove sensitive fields for response
    const merchant = { ...rows[0] };
    delete merchant.password_hash;
    delete merchant.wa_access_token;

    ok(res, { merchant });
  } catch (e) { next(e); }
});

// POST /owner/merchants — create merchant account
router.post("/merchants", async (req, res, next) => {
  try {
    const {
      shopName, shopNameAr, email, whatsappNumber, opsWhatsapp,
      password, plan = "basic", monthlyFee, address, defaultLocale = "ar",
    } = req.body;

    if (!shopName || !email || !whatsappNumber || !opsWhatsapp || !password) {
      return err(res, "shopName, email, whatsappNumber, opsWhatsapp, password are required");
    }

    const existing = await query(
      "SELECT id FROM merchants WHERE email = $1 OR whatsapp_number = $2",
      [email, whatsappNumber]
    );
    if (existing.rows.length) return err(res, "Email or WhatsApp number already in use", 409);

    const passwordHash = await bcrypt.hash(password, 12);
    const id = uuidv4();

    const { rows } = await query(
      `INSERT INTO merchants (
        id, owner_id, shop_name, shop_name_ar, email, whatsapp_number, ops_whatsapp,
        password_hash, status, plan, monthly_fee, address, default_locale
       ) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,'active',$9,$10,$11,$12)
       RETURNING id, shop_name, email, status, plan`,
      [id, req.auth.id, shopName, shopNameAr, email, whatsappNumber,
       opsWhatsapp, passwordHash, plan, monthlyFee, address, defaultLocale]
    );

    // Seed default shop hours (9am–11pm every day)
    const hourInserts = Array.from({ length: 7 }, (_, i) =>
      query(
        `INSERT INTO shop_hours (merchant_id, day_of_week, opens_at, closes_at)
         VALUES ($1, $2, '09:00', '23:00')`,
        [id, i]
      )
    );
    await Promise.all(hourInserts);

    created(res, { merchant: rows[0] });
  } catch (e) { next(e); }
});

// PATCH /owner/merchants/:id — update status, plan, notes, etc.
router.patch("/merchants/:id", async (req, res, next) => {
  try {
    const allowed = ["status", "plan", "monthly_fee", "owner_notes", "mode",
                     "subscription_starts", "subscription_ends", "trial_ends_at"];
    const updates = [];
    const values = [];

    for (const key of allowed) {
      const camel = key.replace(/_([a-z])/g, (_, c) => c.toUpperCase());
      const val = req.body[camel] ?? req.body[key];
      if (val !== undefined) {
        values.push(val);
        updates.push(`${key} = $${values.length}`);
      }
    }

    if (!updates.length) return err(res, "No valid fields to update");

    values.push(req.params.id);
    const { rows } = await query(
      `UPDATE merchants SET ${updates.join(", ")} WHERE id = $${values.length}
       RETURNING id, shop_name, status, plan, mode, owner_notes`,
      values
    );

    if (!rows[0]) return notFound(res, "Merchant not found");
    ok(res, { merchant: rows[0] });
  } catch (e) { next(e); }
});

// DELETE /owner/merchants/:id  (soft: cancel)
router.delete("/merchants/:id", async (req, res, next) => {
  try {
    const { rows } = await query(
      "UPDATE merchants SET status = 'cancelled' WHERE id = $1 RETURNING id, shop_name",
      [req.params.id]
    );
    if (!rows[0]) return notFound(res, "Merchant not found");
    ok(res, { message: "Merchant cancelled", merchant: rows[0] });
  } catch (e) { next(e); }
});

// POST /owner/merchants/:id/impersonate — get a merchant token (owner access)
router.post("/merchants/:id/impersonate", async (req, res, next) => {
  try {
    const { rows } = await query(
      "SELECT id, shop_name FROM merchants WHERE id = $1",
      [req.params.id]
    );
    if (!rows[0]) return notFound(res, "Merchant not found");

    const token = signToken({
      id: rows[0].id,
      role: "merchant",
      merchantId: rows[0].id,
      name: rows[0].shop_name,
      impersonatedBy: req.auth.id,
    });

    ok(res, {
      token,
      message: `Impersonating ${rows[0].shop_name}`,
      merchant: rows[0],
    });
  } catch (e) { next(e); }
});

// ── ONBOARDING REQUESTS ──────────────────────────────────────

// GET /owner/onboarding
router.get("/onboarding", async (req, res, next) => {
  try {
    const { status = "pending" } = req.query;
    const { rows } = await query(
      `SELECT * FROM onboarding_requests WHERE status = $1 ORDER BY created_at DESC`,
      [status]
    );
    ok(res, { requests: rows });
  } catch (e) { next(e); }
});

// POST /owner/onboarding/:id/approve
router.post("/onboarding/:id/approve", async (req, res, next) => {
  try {
    const { password, monthlyFee, plan = "basic" } = req.body;
    if (!password) return err(res, "Initial password required");

    const { rows: reqRows } = await query(
      "SELECT * FROM onboarding_requests WHERE id = $1 AND status = 'pending'",
      [req.params.id]
    );
    if (!reqRows[0]) return notFound(res, "Request not found");
    const request = reqRows[0];

    const passwordHash = await bcrypt.hash(password, 12);
    const merchantId = uuidv4();

    await transaction(async (client) => {
      await client.query(
        `INSERT INTO merchants (id, owner_id, shop_name, email, whatsapp_number, ops_whatsapp,
          password_hash, status, plan, monthly_fee, address, default_locale)
         VALUES ($1,$2,$3,$4,$5,$5,$6,'active',$7,$8,$9,'ar')`,
        [merchantId, req.auth.id, request.shop_name, request.email || `${merchantId}@neotalab.com`,
         request.whatsapp, passwordHash, plan, monthlyFee, request.city]
      );

      // Default hours
      for (let i = 0; i < 7; i++) {
        await client.query(
          `INSERT INTO shop_hours (merchant_id, day_of_week, opens_at, closes_at)
           VALUES ($1, $2, '09:00', '23:00')`,
          [merchantId, i]
        );
      }

      await client.query(
        `UPDATE onboarding_requests SET status = 'approved', reviewed_by = $1,
         reviewed_at = NOW(), merchant_id = $2 WHERE id = $3`,
        [req.auth.id, merchantId, req.params.id]
      );
    });

    ok(res, { message: "Merchant approved and account created", merchantId });
  } catch (e) { next(e); }
});

// POST /owner/onboarding/:id/reject
router.post("/onboarding/:id/reject", async (req, res, next) => {
  try {
    const { rows } = await query(
      `UPDATE onboarding_requests SET status = 'rejected', reviewed_by = $1, reviewed_at = NOW()
       WHERE id = $2 AND status = 'pending' RETURNING id`,
      [req.auth.id, req.params.id]
    );
    if (!rows[0]) return notFound(res, "Request not found");
    ok(res, { message: "Request rejected" });
  } catch (e) { next(e); }
});

// ── PLATFORM ANALYTICS ───────────────────────────────────────

// GET /owner/analytics
router.get("/analytics", async (req, res, next) => {
  try {
    const { days = 30 } = req.query;

    const [ordersData, merchantsData, topMerchants] = await Promise.all([
      query(`
        SELECT
          DATE(created_at) as date,
          COUNT(*) as orders,
          COALESCE(SUM(total),0) as revenue
        FROM orders
        WHERE created_at >= NOW() - INTERVAL '${parseInt(days)} days'
        GROUP BY DATE(created_at)
        ORDER BY date ASC
      `),
      query(`
        SELECT status, COUNT(*) as count FROM merchants GROUP BY status
      `),
      query(`
        SELECT m.id, m.shop_name,
          COUNT(o.id) as order_count,
          COALESCE(SUM(o.total),0) as revenue
        FROM merchants m
        LEFT JOIN orders o ON o.merchant_id = m.id
          AND o.created_at >= NOW() - INTERVAL '${parseInt(days)} days'
          AND o.status = 'delivered'
        GROUP BY m.id
        ORDER BY revenue DESC
        LIMIT 10
      `),
    ]);

    ok(res, {
      orderTrend: ordersData.rows,
      merchantsByStatus: merchantsData.rows,
      topMerchants: topMerchants.rows,
    });
  } catch (e) { next(e); }
});

export default router;
