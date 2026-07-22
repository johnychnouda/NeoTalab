/**
 * WhatsApp webhook — inbound message handling
 * Features: #1,#3,#4,#5,#6,#29,#30,#31,#35,#61,#66,#72,#73,#74
 */
import { Router } from "express";
import crypto from "crypto";
import { query, transaction } from "../db.js";
import { ok, err } from "../utils/response.js";
import { parseInboundMessage } from "../services/AIService.js";
import { isShopOpen } from "../services/ShopHoursService.js";
import { sendWhatsApp } from "../services/WhatsAppService.js";
import { logger } from "../utils/logger.js";

const router = Router();

// GET /webhooks/whatsapp/:merchantId — Meta verification (#72)
router.get("/:merchantId", async (req, res) => {
  const { merchantId } = req.params;
  const mode = req.query["hub.mode"];
  const token = req.query["hub.verify_token"];
  const challenge = req.query["hub.challenge"];

  const { rows } = await query(
    "SELECT wa_webhook_secret FROM merchants WHERE id = $1",
    [merchantId]
  ).catch(() => ({ rows: [] }));

  const verifyToken = rows[0]?.wa_webhook_secret || process.env.WHATSAPP_VERIFY_TOKEN || "neotalab-verify";

  if (mode === "subscribe" && token === verifyToken) {
    logger.info("WhatsApp webhook verified", { merchantId });
    return res.status(200).send(challenge);
  }
  res.status(403).send("Forbidden");
});

// POST /webhooks/whatsapp/:merchantId — inbound messages
router.post("/:merchantId", async (req, res) => {
  const { merchantId } = req.params;

  // Verify signature (#72)
  const appSecret = process.env.WHATSAPP_APP_SECRET;
  if (appSecret && process.env.NODE_ENV === "production") {
    const sig = req.headers["x-hub-signature-256"] || "";
    const expected = "sha256=" + crypto.createHmac("sha256", appSecret)
      .update(JSON.stringify(req.body)).digest("hex");
    if (!crypto.timingSafeEqual(Buffer.from(sig), Buffer.from(expected))) {
      logger.warn("Invalid webhook signature", { merchantId });
      return res.status(403).json({ error: "invalid_signature" });
    }
  }

  // Acknowledge immediately (#73 — reliability)
  res.status(200).json({ ok: true });

  // Process asynchronously
  setImmediate(() => processWebhook(merchantId, req.body));
});

async function processWebhook(merchantId, body) {
  try {
    const entry = body?.entry?.[0];
    const change = entry?.changes?.[0];
    const value = change?.value;
    const messages = value?.messages;

    if (!messages?.length) return;

    const merchant = await query(
      `SELECT id, shop_name, mode, auto_accept_orders, default_locale,
              wish_number, wish_auto_confirm, wish_timeout_mins
       FROM merchants WHERE id = $1 AND status = 'active'`,
      [merchantId]
    );
    if (!merchant.rows[0]) return;
    const shop = merchant.rows[0];

    for (const msg of messages) {
      await processMessage(shop, msg);
    }
  } catch (e) {
    logger.error("Webhook processing error", { merchantId, error: e.message });
  }
}

async function processMessage(shop, msg) {
  const waMessageId = msg.id;
  const fromPhone = msg.from;
  const merchantId = shop.id;

  // Idempotency check (#72)
  try {
    await query(
      `INSERT INTO whatsapp_messages (merchant_id, wa_message_id, direction, from_number, to_number, message_type, content)
       VALUES ($1,$2,'inbound',$3,$4,$5,$6)`,
      [merchantId, waMessageId, fromPhone, shop.shop_name, msg.type, JSON.stringify(msg)]
    );
  } catch {
    logger.debug("Duplicate message ignored", { waMessageId });
    return; // duplicate
  }

  // Get or create customer (#17, #65)
  let customer = await query(
    "SELECT * FROM customers WHERE merchant_id = $1 AND phone = $2",
    [merchantId, fromPhone]
  );
  if (!customer.rows[0]) {
    const result = await query(
      `INSERT INTO customers (merchant_id, phone, preferred_locale)
       VALUES ($1, $2, $3) RETURNING *`,
      [merchantId, fromPhone, shop.default_locale]
    );
    customer = { rows: [result.rows[0]] };
  }
  const cust = customer.rows[0];

  // Block check (#17)
  if (cust.is_blocked) {
    logger.debug("Blocked customer, ignoring", { phone: fromPhone });
    return;
  }

  // Shop open check (#35)
  const shopOpen = await isShopOpen(shop.id);
  if (!shopOpen && shop.mode !== "manual") {
    await sendWhatsApp(merchantId, fromPhone, {
      type: "text",
      text: getClosedMessage(cust.preferred_locale, shop.shop_name),
    });
    return;
  }

  // Manual mode — forward to merchant (#6)
  if (shop.mode === "manual") {
    await sendWhatsApp(merchantId, fromPhone, {
      type: "text",
      text: getManualModeMessage(cust.preferred_locale),
    });
    // Notify merchant of incoming message (#62, #66)
    await query(
      "UPDATE merchants SET mode = 'manual' WHERE id = $1", [merchantId]
    );
    return;
  }

  // AI parsing (#3)
  const parsed = await parseInboundMessage(msg, cust, shop);
  if (!parsed) return;

  // Handle different intents
  switch (parsed.intent) {
    case "order":
      await handleOrderIntent(merchantId, shop, cust, parsed);
      break;
    case "status":
      await handleStatusIntent(merchantId, shop, cust);
      break;
    case "human_handoff":
      await handleHumanHandoff(merchantId, shop, cust, parsed.message);
      break;
    case "wish_payment":
      await handleWishPayment(merchantId, shop, cust, parsed);
      break;
    default:
      await sendWhatsApp(merchantId, cust.phone, {
        type: "text",
        text: getDefaultReply(cust.preferred_locale, shop.shop_name),
      });
  }
}

async function handleOrderIntent(merchantId, shop, cust, parsed) {
  try {
    // Build order draft (#13)
    const order = await createOrderDraft(merchantId, shop, cust, parsed);

    // Send confirmation to customer (#4 — interactive buttons)
    const summary = buildOrderConfirmationMessage(order, cust.preferred_locale);
    await sendWhatsApp(merchantId, cust.phone, {
      type: "interactive",
      interactive: {
        type: "button",
        body: { text: summary },
        action: {
          buttons: [
            { type: "reply", reply: { id: `confirm_${order.id}`, title: "✅ Confirm" } },
            { type: "reply", reply: { id: `cancel_${order.id}`, title: "❌ Cancel" } },
          ],
        },
      },
    });
  } catch (e) {
    logger.error("Order intent error", { error: e.message });
    await sendWhatsApp(merchantId, cust.phone, {
      type: "text",
      text: "Sorry, something went wrong processing your order. Please try again.",
    });
  }
}

async function handleStatusIntent(merchantId, shop, cust) {
  // Find latest active order (#61)
  const { rows } = await query(
    `SELECT id, status, eta_mins FROM orders
     WHERE customer_id = $1 AND status NOT IN ('delivered','cancelled','rejected')
     ORDER BY created_at DESC LIMIT 1`,
    [cust.id]
  );

  if (!rows[0]) {
    await sendWhatsApp(merchantId, cust.phone, {
      type: "text",
      text: "You don't have any active orders right now.",
    });
    return;
  }

  const statusReplies = {
    confirmed:  "✅ Your order is confirmed and being prepared!",
    preparing:  `🍳 Your order is being prepared. ETA ~${rows[0].eta_mins || "??"} mins.`,
    assigned:   `🛵 Driver is on the way! ETA ~${rows[0].eta_mins || "??"} mins.`,
    picked_up:  "🛵 Your driver has your order and is heading to you!",
  };

  await sendWhatsApp(merchantId, cust.phone, {
    type: "text",
    text: statusReplies[rows[0].status] || "Your order is being processed.",
  });
}

async function handleHumanHandoff(merchantId, shop, cust, message) {
  // Feature #66 — notify merchant for manual handling
  await sendWhatsApp(merchantId, cust.phone, {
    type: "text",
    text: "I'm connecting you with a team member. Please hold on.",
  });

  // In production: notify ops_whatsapp of the merchant
  const { rows } = await query(
    "SELECT ops_whatsapp FROM merchants WHERE id = $1", [merchantId]
  );
  if (rows[0]) {
    await sendWhatsApp(merchantId, rows[0].ops_whatsapp, {
      type: "text",
      text: `⚠️ *Human handoff requested*\n\nCustomer: ${cust.phone}\nMessage: "${message}"\n\nPlease respond manually.`,
    });
  }
}

async function handleWishPayment(merchantId, shop, cust, parsed) {
  // Find pending order awaiting Wish
  const { rows } = await query(
    `SELECT * FROM orders WHERE customer_id = $1 AND status = 'pending_payment'
     AND payment_method = 'wish' ORDER BY created_at DESC LIMIT 1`,
    [cust.id]
  );
  if (!rows[0]) return;
  const order = rows[0];

  const sentAmount = parseFloat(parsed.amount);
  const expected = parseFloat(order.total);

  if (Math.abs(sentAmount - expected) > 0.5) {
    // Wrong amount (#30)
    await sendWhatsApp(merchantId, cust.phone, {
      type: "text",
      text: `❌ Wrong amount received. Expected $${expected.toFixed(2)} but got $${sentAmount.toFixed(2)}. Please send the correct amount to ${shop.wish_number}.`,
    });
    return;
  }

  // Auto-confirm Wish (#29)
  if (shop.wish_auto_confirm) {
    await query(
      `UPDATE orders SET payment_status = 'paid', status = 'confirmed',
         wish_amount_sent = $1, accepted_at = NOW()
       WHERE id = $2`,
      [sentAmount, order.id]
    );
    await sendWhatsApp(merchantId, cust.phone, {
      type: "text",
      text: `✅ Payment confirmed! $${sentAmount.toFixed(2)} received via Wish.\n\nYour order is now being prepared. We'll update you shortly!`,
    });
  }
}

async function createOrderDraft(merchantId, shop, cust, parsed) {
  // Get menu products for matching
  const { rows: products } = await query(
    "SELECT * FROM products WHERE merchant_id = $1 AND is_active = TRUE AND is_sold_out = FALSE",
    [merchantId]
  );

  let subtotal = 0;
  const orderItems = [];

  for (const item of parsed.items || []) {
    const product = products.find(p =>
      p.name.toLowerCase().includes(item.name.toLowerCase()) ||
      (p.name_ar && p.name_ar.toLowerCase().includes(item.name.toLowerCase()))
    );
    if (!product) continue;

    const itemTotal = product.price * item.quantity;
    subtotal += itemTotal;
    orderItems.push({
      productId: product.id,
      productName: product.name,
      unitPrice: product.price,
      quantity: item.quantity,
      modifiers: item.modifiers || [],
      itemTotal,
    });
  }

  // Get delivery zone
  const { rows: zones } = await query(
    `SELECT * FROM delivery_zones WHERE merchant_id = $1
     AND is_active = TRUE ORDER BY sort_order LIMIT 1`,
    [merchantId]
  );
  const zone = zones[0];
  const deliveryFee = zone?.delivery_fee || 0;
  const total = subtotal + deliveryFee;

  const orderId = (await query(
    `INSERT INTO orders (merchant_id, customer_id, status, subtotal, delivery_fee, total, locale, raw_message)
     VALUES ($1,$2,'draft',$3,$4,$5,$6,$7) RETURNING id`,
    [merchantId, cust.id, subtotal, deliveryFee, total, cust.preferred_locale, parsed.rawMessage]
  )).rows[0].id;

  for (const item of orderItems) {
    await query(
      `INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity, modifiers, item_total)
       VALUES ($1,$2,$3,$4,$5,$6,$7)`,
      [orderId, item.productId, item.productName, item.unitPrice, item.quantity,
       JSON.stringify(item.modifiers), item.itemTotal]
    );
  }

  return { id: orderId, subtotal, deliveryFee, total, items: orderItems };
}

function buildOrderConfirmationMessage(order, locale) {
  const itemLines = order.items.map(i => `• ${i.productName} x${i.quantity} — $${i.itemTotal.toFixed(2)}`).join("\n");
  return `🛒 *Order Summary*\n\n${itemLines}\n\n──────────────\nSubtotal: $${order.subtotal.toFixed(2)}\nDelivery: $${order.deliveryFee.toFixed(2)}\n*Total: $${order.total.toFixed(2)}*\n\nConfirm your order?`;
}

function getClosedMessage(locale, shopName) {
  const msgs = {
    ar: `عذراً، ${shopName} مغلق حالياً. يرجى المحاولة مرة أخرى خلال ساعات العمل.`,
    fr: `Désolé, ${shopName} est fermé actuellement. Veuillez réessayer pendant les heures d'ouverture.`,
    en: `Sorry, ${shopName} is currently closed. Please try again during business hours.`,
  };
  return msgs[locale] || msgs.en;
}

function getManualModeMessage(locale) {
  const msgs = {
    ar: "نحن في وضع يدوي حالياً. سيتواصل معك أحد موظفينا قريباً.",
    fr: "Nous sommes en mode manuel. Un de nos agents vous contactera bientôt.",
    en: "We are currently in manual mode. A team member will reach out to you shortly.",
  };
  return msgs[locale] || msgs.en;
}

function getDefaultReply(locale, shopName) {
  const msgs = {
    ar: `مرحباً بك في ${shopName}! كيف يمكنني مساعدتك؟ أرسل قائمة طلبك أو اكتب "قائمة" لرؤية منتجاتنا.`,
    en: `Welcome to ${shopName}! How can I help you? Send your order or type "menu" to see our products.`,
    fr: `Bienvenue chez ${shopName}! Comment puis-je vous aider? Envoyez votre commande ou tapez "menu".`,
  };
  return msgs[locale] || msgs.en;
}

export default router;
