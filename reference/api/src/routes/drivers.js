/**
 * Driver routes
 * Features: #40,#41,#42,#43,#44,#46,#47,#48,#53
 */
import { Router } from "express";
import bcrypt from "bcrypt";
import { v4 as uuidv4 } from "uuid";
import { query, transaction } from "../db.js";
import { requireAuth, requireMerchant } from "../middleware/auth.js";
import { ok, created, err, notFound } from "../utils/response.js";
import { sendWhatsApp } from "../services/WhatsAppService.js";

const router = Router();
router.use(requireAuth, requireMerchant);

function getMerchantId(req) {
  return req.params.merchantId || req.auth.merchantId;
}

// GET /drivers
router.get("/", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { rows } = await query(
      `SELECT d.id, d.name, d.phone, d.whatsapp_number, d.status,
              d.availability, d.cash_on_hand, d.total_deliveries,
              d.is_active, d.last_ping_at,
              o.id as active_order_id,
              c.phone as active_order_customer
       FROM drivers d
       LEFT JOIN orders o ON o.id = d.active_order_id
       LEFT JOIN customers c ON c.id = o.customer_id
       WHERE d.merchant_id = $1
       ORDER BY d.name`,
      [merchantId]
    );
    ok(res, { drivers: rows });
  } catch (e) { next(e); }
});

// GET /drivers/:id
router.get("/:id", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { rows } = await query(
      "SELECT * FROM drivers WHERE id = $1 AND merchant_id = $2",
      [req.params.id, merchantId]
    );
    if (!rows[0]) return notFound(res, "Driver not found");
    const driver = { ...rows[0] };
    delete driver.password_hash;
    ok(res, { driver });
  } catch (e) { next(e); }
});

// POST /drivers
router.post("/", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { name, phone, whatsappNumber, password } = req.body;
    if (!name || !phone || !password) return err(res, "name, phone, password required");

    const existing = await query(
      "SELECT id FROM drivers WHERE merchant_id = $1 AND phone = $2",
      [merchantId, phone]
    );
    if (existing.rows.length) return err(res, "Driver with this phone already exists", 409);

    const passwordHash = await bcrypt.hash(password, 12);
    const { rows } = await query(
      `INSERT INTO drivers (merchant_id, name, phone, whatsapp_number, password_hash)
       VALUES ($1,$2,$3,$4,$5)
       RETURNING id, name, phone, whatsapp_number, status, availability`,
      [merchantId, name, phone, whatsappNumber || phone, passwordHash]
    );
    created(res, { driver: rows[0] });
  } catch (e) { next(e); }
});

// PATCH /drivers/:id
router.patch("/:id", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { name, phone, whatsappNumber, status, isActive } = req.body;

    const { rows } = await query(
      `UPDATE drivers SET
         name = COALESCE($1, name),
         phone = COALESCE($2, phone),
         whatsapp_number = COALESCE($3, whatsapp_number),
         status = COALESCE($4, status),
         is_active = COALESCE($5, is_active)
       WHERE id = $6 AND merchant_id = $7
       RETURNING id, name, phone, status, availability, is_active`,
      [name, phone, whatsappNumber, status, isActive, req.params.id, merchantId]
    );
    if (!rows[0]) return notFound(res, "Driver not found");
    ok(res, { driver: rows[0] });
  } catch (e) { next(e); }
});

// DELETE /drivers/:id (deactivate)
router.delete("/:id", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { rows } = await query(
      "UPDATE drivers SET is_active = FALSE WHERE id = $1 AND merchant_id = $2 RETURNING id",
      [req.params.id, merchantId]
    );
    if (!rows[0]) return notFound(res, "Driver not found");
    ok(res, { message: "Driver deactivated" });
  } catch (e) { next(e); }
});

// POST /drivers/:id/status — driver sets their own status (#44)
router.post("/:id/status", async (req, res, next) => {
  try {
    const { status } = req.body;
    const validStatuses = ["on_duty", "off_duty", "on_break", "paused"];
    if (!validStatuses.includes(status)) return err(res, "Invalid status");

    const merchantId = getMerchantId(req);
    const { rows } = await query(
      `UPDATE drivers SET status = $1 WHERE id = $2 AND merchant_id = $3
       RETURNING id, name, status, availability`,
      [status, req.params.id, merchantId]
    );
    if (!rows[0]) return notFound(res, "Driver not found");
    ok(res, { driver: rows[0] });
  } catch (e) { next(e); }
});

// POST /drivers/:id/delivery-tap  (#47 — "left store" or "delivered")
router.post("/:id/delivery-tap", async (req, res, next) => {
  try {
    const { tap, orderId } = req.body;
    if (!["left_store", "delivered"].includes(tap)) return err(res, "tap must be left_store or delivered");

    const merchantId = getMerchantId(req);

    if (tap === "left_store") {
      const { rows } = await query(
        `UPDATE orders SET status = 'picked_up', picked_up_at = NOW()
         WHERE id = $1 AND driver_id = $2 AND status IN ('assigned','ready')
         RETURNING *`,
        [orderId, req.params.id]
      );
      if (!rows[0]) return err(res, "Order not found or invalid state");

      // Notify customer (#48)
      const { rows: cust } = await query(
        "SELECT phone, preferred_locale FROM customers WHERE id = $1",
        [rows[0].customer_id]
      );
      if (cust[0]) {
        await sendWhatsApp(merchantId, cust[0].phone, {
          type: "text",
          text: `🛵 Your driver has picked up your order and is on the way!\n\nETA ~${rows[0].eta_mins || "??"} minutes. We'll notify you when they're nearby.`,
        });
      }

      ok(res, { message: "Marked as picked up", order: rows[0] });

    } else {
      // delivered
      await transaction(async (client) => {
        const { rows } = await client.query(
          `UPDATE orders SET status = 'delivered', delivered_at = NOW()
           WHERE id = $1 AND driver_id = $2 AND status = 'picked_up'
           RETURNING *`,
          [orderId, req.params.id]
        );
        if (!rows[0]) throw Object.assign(new Error("Order not found"), { status: 404 });
        const order = rows[0];

        // Free driver (#43)
        await client.query(
          `UPDATE drivers SET availability = 'available', active_order_id = NULL,
             total_deliveries = total_deliveries + 1,
             cash_on_hand = cash_on_hand + $1
           WHERE id = $2`,
          [order.payment_method === "cash" ? order.total : 0, req.params.id]
        );

        // Update customer stats
        await client.query(
          `UPDATE customers SET total_orders = total_orders + 1,
             total_spent = total_spent + $1, last_order_at = NOW()
           WHERE id = $2`,
          [order.total, order.customer_id]
        );

        // Send receipt to customer (#58)
        const { rows: cust } = await client.query(
          "SELECT phone, preferred_locale, name FROM customers WHERE id = $1",
          [order.customer_id]
        );
        const { rows: items } = await client.query(
          "SELECT * FROM order_items WHERE order_id = $1", [orderId]
        );

        if (cust[0]) {
          const receiptText = buildReceipt(order, items, cust[0]);
          await sendWhatsApp(merchantId, cust[0].phone, { type: "text", text: receiptText });
        }

        return rows[0];
      });

      ok(res, { message: "Order delivered and receipt sent" });
    }
  } catch (e) { next(e); }
});

// GET /drivers/:id/shifts  (#53)
router.get("/:id/shifts", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { rows } = await query(
      `SELECT ds.*, d.name as driver_name
       FROM driver_shifts ds
       JOIN drivers d ON d.id = ds.driver_id
       WHERE ds.driver_id = $1 AND d.merchant_id = $2
       ORDER BY ds.started_at DESC LIMIT 30`,
      [req.params.id, merchantId]
    );
    ok(res, { shifts: rows });
  } catch (e) { next(e); }
});

// POST /drivers/:id/shift/start
router.post("/:id/shift/start", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { rows } = await query(
      `INSERT INTO driver_shifts (driver_id)
       SELECT $1 FROM drivers WHERE id = $1 AND merchant_id = $2
       RETURNING *`,
      [req.params.id, merchantId]
    );
    if (!rows[0]) return notFound(res, "Driver not found");

    await query("UPDATE drivers SET status = 'on_duty' WHERE id = $1", [req.params.id]);
    ok(res, { shift: rows[0] });
  } catch (e) { next(e); }
});

// POST /drivers/:id/shift/end
router.post("/:id/shift/end", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { rows } = await query(
      `UPDATE driver_shifts SET ended_at = NOW()
       WHERE driver_id = $1 AND ended_at IS NULL
       RETURNING *`,
      [req.params.id]
    );
    await query(
      "UPDATE drivers SET status = 'off_duty', cash_on_hand = 0 WHERE id = $1",
      [req.params.id]
    );
    ok(res, { shift: rows[0] || null, message: "Shift ended" });
  } catch (e) { next(e); }
});

function buildReceipt(order, items, customer) {
  const lines = items.map(i => `  • ${i.product_name} x${i.quantity} — $${i.item_total}`).join("\n");
  return `🧾 *Receipt — Joe's Snacks*\n\nHi ${customer.name || "there"}! Here's your order summary:\n\n${lines}\n\n──────────────\nSubtotal:    $${order.subtotal}\nDelivery:    $${order.delivery_fee}\nTotal:       $${order.total}\n\nPayment: ${order.payment_method === "cash" ? "Cash on delivery" : "Wish"}\n\nThank you! We hope you enjoy your order 🙏`;
}

export default router;
