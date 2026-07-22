/**
 * Customer routes
 * Features: #17, #18, #65
 */
import { Router } from "express";
import { query } from "../db.js";
import { requireAuth, requireMerchant } from "../middleware/auth.js";
import { ok, created, err, notFound } from "../utils/response.js";

const router = Router();
router.use(requireAuth, requireMerchant);

function getMerchantId(req) {
  return req.params.merchantId || req.auth.merchantId;
}

// GET /customers
router.get("/", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { search, blocked, limit = 50, offset = 0 } = req.query;

    let sql = `
      SELECT c.*, COUNT(o.id) as order_count
      FROM customers c
      LEFT JOIN orders o ON o.customer_id = c.id AND o.status = 'delivered'
      WHERE c.merchant_id = $1
    `;
    const params = [merchantId];

    if (blocked !== undefined) {
      params.push(blocked === "true");
      sql += ` AND c.is_blocked = $${params.length}`;
    }
    if (search) {
      params.push(`%${search}%`);
      sql += ` AND (c.name ILIKE $${params.length} OR c.phone ILIKE $${params.length})`;
    }

    sql += ` GROUP BY c.id ORDER BY c.last_order_at DESC NULLS LAST
             LIMIT $${params.length + 1} OFFSET $${params.length + 2}`;
    params.push(limit, offset);

    const { rows } = await query(sql, params);
    ok(res, { customers: rows });
  } catch (e) { next(e); }
});

// GET /customers/:phone
router.get("/by-phone/:phone", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { rows } = await query(
      `SELECT c.*, json_agg(ca ORDER BY ca.is_default DESC, ca.created_at DESC) as addresses
       FROM customers c
       LEFT JOIN customer_addresses ca ON ca.customer_id = c.id
       WHERE c.merchant_id = $1 AND c.phone = $2
       GROUP BY c.id`,
      [merchantId, req.params.phone]
    );
    if (!rows[0]) return notFound(res, "Customer not found");
    ok(res, { customer: rows[0] });
  } catch (e) { next(e); }
});

// GET /customers/:id
router.get("/:id", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { rows } = await query(
      `SELECT c.*, json_agg(ca ORDER BY ca.is_default DESC) FILTER (WHERE ca.id IS NOT NULL) as addresses
       FROM customers c
       LEFT JOIN customer_addresses ca ON ca.customer_id = c.id
       WHERE c.id = $1 AND c.merchant_id = $2
       GROUP BY c.id`,
      [req.params.id, merchantId]
    );
    if (!rows[0]) return notFound(res, "Customer not found");

    // Recent orders
    const { rows: orders } = await query(
      `SELECT id, status, total, created_at FROM orders
       WHERE customer_id = $1 ORDER BY created_at DESC LIMIT 10`,
      [req.params.id]
    );

    ok(res, { customer: rows[0], recentOrders: orders });
  } catch (e) { next(e); }
});

// POST /customers/:id/block  (#17)
router.post("/:id/block", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { reason } = req.body;
    const { rows } = await query(
      `UPDATE customers SET is_blocked = TRUE, block_reason = $1
       WHERE id = $2 AND merchant_id = $3 RETURNING id, phone, is_blocked`,
      [reason, req.params.id, merchantId]
    );
    if (!rows[0]) return notFound(res, "Customer not found");
    ok(res, { customer: rows[0], message: "Customer blocked" });
  } catch (e) { next(e); }
});

// POST /customers/:id/unblock
router.post("/:id/unblock", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { rows } = await query(
      `UPDATE customers SET is_blocked = FALSE, block_reason = NULL
       WHERE id = $2 AND merchant_id = $3 RETURNING id, phone, is_blocked`,
      [req.params.id, merchantId]
    );
    if (!rows[0]) return notFound(res, "Customer not found");
    ok(res, { customer: rows[0], message: "Customer unblocked" });
  } catch (e) { next(e); }
});

// POST /customers/:id/addresses  (#18)
router.post("/:id/addresses", async (req, res, next) => {
  try {
    const { label, lat, lng, building, floor, additional, isDefault = false } = req.body;
    if (!building) return err(res, "Building name required");

    if (isDefault) {
      await query(
        "UPDATE customer_addresses SET is_default = FALSE WHERE customer_id = $1",
        [req.params.id]
      );
    }

    const { rows } = await query(
      `INSERT INTO customer_addresses (customer_id, label, lat, lng, building, floor, additional, is_default)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8) RETURNING *`,
      [req.params.id, label, lat, lng, building, floor, additional, isDefault]
    );
    created(res, { address: rows[0] });
  } catch (e) { next(e); }
});

// DELETE /customers/:id/addresses/:addressId
router.delete("/:id/addresses/:addressId", async (req, res, next) => {
  try {
    const { rows } = await query(
      "DELETE FROM customer_addresses WHERE id = $1 AND customer_id = $2 RETURNING id",
      [req.params.addressId, req.params.id]
    );
    if (!rows[0]) return notFound(res, "Address not found");
    ok(res, { message: "Address deleted" });
  } catch (e) { next(e); }
});

export default router;
