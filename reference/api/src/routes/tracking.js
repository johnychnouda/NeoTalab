/**
 * Tracking routes
 * Features: #48, #49, #50, #77
 */
import { Router } from "express";
import { query } from "../db.js";
import { requireAuth } from "../middleware/auth.js";
import { ok, err, notFound } from "../utils/response.js";
import { sendWhatsApp } from "../services/WhatsAppService.js";

const router = Router();
router.use(requireAuth);

// POST /tracking/:orderId/ping  (#48, #77)
router.post("/:orderId/ping", async (req, res, next) => {
  try {
    const { lat, lng, event } = req.body;
    if (!lat || !lng) return err(res, "lat and lng required");

    const { rows: orderRows } = await query(
      `SELECT o.*, m.id as merchant_id
       FROM orders o JOIN merchants m ON m.id = o.merchant_id
       WHERE o.id = $1 AND o.driver_id = $2`,
      [req.params.orderId, req.auth.id]
    );
    if (!orderRows[0]) return notFound(res, "Order not found");
    const order = orderRows[0];

    // Save ping
    await query(
      `INSERT INTO tracking_pings (order_id, driver_id, lat, lng, event)
       VALUES ($1,$2,$3,$4,$5)`,
      [req.params.orderId, req.auth.id, lat, lng, event || "ping"]
    );

    // Update driver location
    await query(
      "UPDATE drivers SET last_lat = $1, last_lng = $2, last_ping_at = NOW() WHERE id = $3",
      [lat, lng, req.auth.id]
    );

    // Geofencing — notify customer when nearby (#48)
    if (event === "nearby") {
      const { rows: cust } = await query(
        "SELECT phone, preferred_locale FROM customers WHERE id = $1",
        [order.customer_id]
      );
      if (cust[0]) {
        await sendWhatsApp(order.merchant_id, cust[0].phone, {
          type: "text",
          text: "🛵 Your driver is nearby! Please be ready to receive your order.",
        });
      }
    }

    if (event === "arrived") {
      const { rows: cust } = await query(
        "SELECT phone FROM customers WHERE id = $1", [order.customer_id]
      );
      if (cust[0]) {
        await sendWhatsApp(order.merchant_id, cust[0].phone, {
          type: "text",
          text: "✅ Your driver has arrived! Please come to the door.",
        });
      }
    }

    ok(res, { recorded: true, event: event || "ping" });
  } catch (e) { next(e); }
});

// GET /tracking/:orderId/live  — last known driver position
router.get("/:orderId/live", async (req, res, next) => {
  try {
    const { rows } = await query(
      `SELECT tp.lat, tp.lng, tp.event, tp.created_at,
              d.name as driver_name
       FROM tracking_pings tp
       JOIN drivers d ON d.id = tp.driver_id
       WHERE tp.order_id = $1
       ORDER BY tp.created_at DESC LIMIT 1`,
      [req.params.orderId]
    );

    if (!rows[0]) return ok(res, { position: null });

    // Simple ETA fallback (#49)
    const eta = await query(
      "SELECT eta_mins, picked_up_at FROM orders WHERE id = $1",
      [req.params.orderId]
    );

    let remainingEta = null;
    if (eta.rows[0]?.picked_up_at && eta.rows[0]?.eta_mins) {
      const elapsed = (Date.now() - new Date(eta.rows[0].picked_up_at).getTime()) / 60000;
      remainingEta = Math.max(0, Math.round(eta.rows[0].eta_mins - elapsed));
    }

    ok(res, {
      position: {
        lat: rows[0].lat,
        lng: rows[0].lng,
        driverName: rows[0].driver_name,
        lastUpdated: rows[0].created_at,
        event: rows[0].event,
      },
      remainingEtaMins: remainingEta,
    });
  } catch (e) { next(e); }
});

export default router;
