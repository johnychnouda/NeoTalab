/**
 * Order routes — full lifecycle
 * Features: #3,#4,#5,#6,#13,#16,#28,#29,#30,#31,#32,#36,#37,#38,#39,
 *           #54,#56,#57,#58,#59,#61
 */
import { Router } from "express";
import { v4 as uuidv4 } from "uuid";
import { query, transaction } from "../db.js";
import { requireAuth, requireMerchant } from "../middleware/auth.js";
import { ok, created, err, notFound } from "../utils/response.js";
import { computeEta, computePrepTime } from "../services/EtaService.js";
import { dispatchDriver } from "../services/DispatchService.js";
import { sendWhatsApp } from "../services/WhatsAppService.js";
import { detectLocale, buildOrderSummary, buildReceipt } from "../services/MessageService.js";

const router = Router();
router.use(requireAuth, requireMerchant);

function getMerchantId(req) {
  return req.params.merchantId || req.auth.merchantId;
}

// ── LIST / SEARCH ────────────────────────────────────────────

// GET /orders
router.get("/", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { status, date, limit = 50, offset = 0 } = req.query;

    let sql = `
      SELECT o.*,
        c.name as customer_name, c.phone as customer_phone,
        d.name as driver_name,
        json_agg(json_build_object(
          'id', oi.id, 'productName', oi.product_name,
          'quantity', oi.quantity, 'unitPrice', oi.unit_price,
          'modifiers', oi.modifiers, 'itemTotal', oi.item_total
        )) as items
      FROM orders o
      JOIN customers c ON c.id = o.customer_id
      LEFT JOIN drivers d ON d.id = o.driver_id
      LEFT JOIN order_items oi ON oi.order_id = o.id
      WHERE o.merchant_id = $1
    `;
    const params = [merchantId];

    if (status) {
      params.push(status);
      sql += ` AND o.status = $${params.length}`;
    }
    if (date) {
      params.push(date);
      sql += ` AND DATE(o.created_at) = $${params.length}`;
    }

    sql += ` GROUP BY o.id, c.name, c.phone, d.name
             ORDER BY o.created_at DESC
             LIMIT $${params.length + 1} OFFSET $${params.length + 2}`;
    params.push(limit, offset);

    const { rows } = await query(sql, params);
    ok(res, { orders: rows });
  } catch (e) { next(e); }
});

// GET /orders/live — active orders (merchant dashboard live view)
router.get("/live", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { rows } = await query(
      `SELECT o.*,
        c.name as customer_name, c.phone as customer_phone,
        d.name as driver_name, d.phone as driver_phone,
        json_agg(json_build_object(
          'productName', oi.product_name, 'quantity', oi.quantity,
          'itemTotal', oi.item_total, 'modifiers', oi.modifiers
        )) as items
       FROM orders o
       JOIN customers c ON c.id = o.customer_id
       LEFT JOIN drivers d ON d.id = o.driver_id
       LEFT JOIN order_items oi ON oi.order_id = o.id
       WHERE o.merchant_id = $1
         AND o.status NOT IN ('delivered','cancelled','rejected')
       GROUP BY o.id, c.name, c.phone, d.name, d.phone
       ORDER BY o.created_at ASC`,
      [merchantId]
    );
    ok(res, { orders: rows });
  } catch (e) { next(e); }
});

// GET /orders/:id
router.get("/:id", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { rows } = await query(
      `SELECT o.*,
        c.name as customer_name, c.phone as customer_phone,
        d.name as driver_name, d.phone as driver_phone,
        json_agg(json_build_object(
          'id', oi.id, 'productName', oi.product_name, 'quantity', oi.quantity,
          'unitPrice', oi.unit_price, 'itemTotal', oi.item_total, 'modifiers', oi.modifiers
        )) as items
       FROM orders o
       JOIN customers c ON c.id = o.customer_id
       LEFT JOIN drivers d ON d.id = o.driver_id
       LEFT JOIN order_items oi ON oi.order_id = o.id
       WHERE o.id = $1 AND o.merchant_id = $2
       GROUP BY o.id, c.name, c.phone, d.name, d.phone`,
      [req.params.id, merchantId]
    );
    if (!rows[0]) return notFound(res, "Order not found");
    ok(res, { order: rows[0] });
  } catch (e) { next(e); }
});

// ── MERCHANT ACTIONS ─────────────────────────────────────────

// POST /orders/:id/accept  (#5, #54)
router.post("/:id/accept", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { rows } = await query(
      `UPDATE orders SET status = 'confirmed', accepted_at = NOW()
       WHERE id = $1 AND merchant_id = $2 AND status = 'pending_payment'
       RETURNING *`,
      [req.params.id, merchantId]
    );
    if (!rows[0]) return notFound(res, "Order not found or cannot be accepted");
    const order = rows[0];

    // Notify customer
    const { rows: cust } = await query(
      "SELECT phone, preferred_locale FROM customers WHERE id = $1", [order.customer_id]
    );
    if (cust[0]) {
      await sendWhatsApp(merchantId, cust[0].phone, {
        type: "text",
        text: buildOrderSummary(order, "confirmed", cust[0].preferred_locale),
      });
    }

    // Auto dispatch driver (#41)
    await dispatchDriver(order.id, merchantId);

    ok(res, { order, message: "Order accepted and driver dispatch initiated" });
  } catch (e) { next(e); }
});

// POST /orders/:id/reject  (#57)
router.post("/:id/reject", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { reason } = req.body;

    const { rows } = await query(
      `UPDATE orders SET status = 'rejected', reject_reason = $1
       WHERE id = $2 AND merchant_id = $3 AND status IN ('pending_payment','confirmed')
       RETURNING *`,
      [reason, req.params.id, merchantId]
    );
    if (!rows[0]) return notFound(res, "Order not found or cannot be rejected");

    const { rows: cust } = await query(
      "SELECT phone, preferred_locale FROM customers WHERE id = $1", [rows[0].customer_id]
    );
    if (cust[0]) {
      await sendWhatsApp(merchantId, cust[0].phone, {
        type: "text",
        text: buildOrderSummary(rows[0], "rejected", cust[0].preferred_locale),
      });
    }

    ok(res, { order: rows[0], message: "Order rejected" });
  } catch (e) { next(e); }
});

// POST /orders/:id/cancel  (#56)
router.post("/:id/cancel", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { reason, by = "merchant" } = req.body;

    const { rows } = await query(
      `UPDATE orders SET status = 'cancelled', cancel_reason = $1,
         cancelled_by = $2, cancelled_at = NOW()
       WHERE id = $3 AND merchant_id = $4
         AND status NOT IN ('delivered','cancelled','rejected')
       RETURNING *`,
      [reason, by, req.params.id, merchantId]
    );
    if (!rows[0]) return notFound(res, "Order not found or cannot be cancelled");

    // Free up driver if assigned
    if (rows[0].driver_id) {
      await query(
        "UPDATE drivers SET availability = 'available', active_order_id = NULL WHERE id = $1",
        [rows[0].driver_id]
      );
    }

    ok(res, { order: rows[0], message: "Order cancelled" });
  } catch (e) { next(e); }
});

// POST /orders/:id/preparing  (#37, #54)
router.post("/:id/preparing", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { extraMins = 0 } = req.body; // kitchen delay #38

    const { rows } = await query(
      `UPDATE orders SET status = 'preparing', preparing_at = NOW(),
         eta_mins = eta_mins + $1
       WHERE id = $2 AND merchant_id = $3 AND status = 'confirmed'
       RETURNING *`,
      [extraMins, req.params.id, merchantId]
    );
    if (!rows[0]) return notFound(res, "Order not found");
    ok(res, { order: rows[0] });
  } catch (e) { next(e); }
});

// POST /orders/:id/ready
router.post("/:id/ready", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { rows } = await query(
      `UPDATE orders SET status = 'ready', ready_at = NOW()
       WHERE id = $1 AND merchant_id = $2 AND status = 'preparing'
       RETURNING *`,
      [req.params.id, merchantId]
    );
    if (!rows[0]) return notFound(res, "Order not found");
    ok(res, { order: rows[0] });
  } catch (e) { next(e); }
});

// POST /orders/:id/assign-driver  (#42)
router.post("/:id/assign-driver", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { driverId } = req.body;
    if (!driverId) return err(res, "driverId required");

    const { rows: driverRows } = await query(
      `SELECT * FROM drivers WHERE id = $1 AND merchant_id = $2
         AND status = 'on_duty' AND availability = 'available'`,
      [driverId, merchantId]
    );
    if (!driverRows[0]) return err(res, "Driver not available");

    await transaction(async (client) => {
      await client.query(
        `UPDATE orders SET driver_id = $1, status = 'assigned', assigned_at = NOW()
         WHERE id = $2 AND merchant_id = $3`,
        [driverId, req.params.id, merchantId]
      );
      await client.query(
        `UPDATE drivers SET availability = 'busy', active_order_id = $1 WHERE id = $2`,
        [req.params.id, driverId]
      );
    });

    // Notify driver via WhatsApp (#47)
    const { rows: orderRows } = await query(
      `SELECT o.*, c.phone as customer_phone FROM orders o
       JOIN customers c ON c.id = o.customer_id
       WHERE o.id = $1`, [req.params.id]
    );
    if (orderRows[0]) {
      await sendWhatsApp(merchantId, driverRows[0].whatsapp_number, {
        type: "text",
        text: `🛵 New delivery assigned!\n\n📦 Order #${req.params.id.slice(-6).toUpperCase()}\n📍 ${orderRows[0].delivery_building}, Floor ${orderRows[0].delivery_floor}\n💰 ${orderRows[0].payment_method === 'cash' ? `Collect $${orderRows[0].total}` : 'Prepaid'}\n\nReply ACCEPT or REJECT`,
      });
    }

    ok(res, { message: "Driver assigned", driverId });
  } catch (e) { next(e); }
});

// POST /orders/:id/note  (#16)
router.post("/:id/note", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { note } = req.body;
    const { rows } = await query(
      `UPDATE orders SET merchant_note = $1 WHERE id = $2 AND merchant_id = $3 RETURNING id`,
      [note, req.params.id, merchantId]
    );
    if (!rows[0]) return notFound(res, "Order not found");
    ok(res, { message: "Note saved" });
  } catch (e) { next(e); }
});

// POST /orders/:id/feedback  (#59)
router.post("/:id/feedback", async (req, res, next) => {
  try {
    const { text, sentiment } = req.body;
    const { rows } = await query(
      `UPDATE orders SET feedback_text = $1, feedback_sentiment = $2
       WHERE id = $3 AND status = 'delivered' RETURNING id`,
      [text, sentiment, req.params.id]
    );
    if (!rows[0]) return notFound(res, "Order not found");
    ok(res, { message: "Feedback saved" });
  } catch (e) { next(e); }
});

// GET /orders/:id/status-reply  (#61)
router.get("/:id/status-reply", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { rows } = await query(
      `SELECT o.status, o.eta_mins, o.picked_up_at, o.delivered_at,
              d.name as driver_name
       FROM orders o LEFT JOIN drivers d ON d.id = o.driver_id
       WHERE o.id = $1 AND o.merchant_id = $2`,
      [req.params.id, merchantId]
    );
    if (!rows[0]) return notFound(res, "Order not found");

    const order = rows[0];
    const replies = {
      confirmed:  "✅ Your order has been confirmed and is being prepared!",
      preparing:  `🍳 Your order is being prepared. ETA ~${order.eta_mins || "??"} minutes.`,
      ready:      "✅ Your order is ready and waiting for the driver!",
      assigned:   `🛵 Driver ${order.driver_name || ""} is on the way! ETA ~${order.eta_mins || "??"} min.`,
      picked_up:  `🛵 ${order.driver_name || "Your driver"} has picked up your order and is heading to you!`,
      delivered:  "✅ Your order has been delivered! Enjoy your meal 🙏",
      cancelled:  "❌ Your order has been cancelled.",
      rejected:   "❌ Sorry, we couldn't accept your order at this time.",
    };

    ok(res, { status: order.status, reply: replies[order.status] || "We're processing your order." });
  } catch (e) { next(e); }
});

export default router;
