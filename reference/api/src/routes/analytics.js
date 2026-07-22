/**
 * Analytics routes
 * Features: #68, #69
 */
import { Router } from "express";
import { query } from "../db.js";
import { requireAuth, requireMerchant } from "../middleware/auth.js";
import { ok } from "../utils/response.js";

const router = Router();
router.use(requireAuth, requireMerchant);

function getMerchantId(req) {
  return req.params.merchantId || req.auth.merchantId;
}

// GET /analytics/dashboard
router.get("/dashboard", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { days = 30 } = req.query;
    const d = parseInt(days);

    const [summary, trend, topProducts, topCustomers, paymentBreakdown, driverStats] = await Promise.all([
      // Summary totals
      query(`
        SELECT
          COUNT(*) FILTER (WHERE status = 'delivered') as completed_orders,
          COUNT(*) FILTER (WHERE status = 'cancelled') as cancelled_orders,
          COUNT(*) FILTER (WHERE status = 'rejected') as rejected_orders,
          COALESCE(SUM(total) FILTER (WHERE status = 'delivered'), 0) as gross_revenue,
          COALESCE(AVG(total) FILTER (WHERE status = 'delivered'), 0) as avg_order_value,
          COALESCE(AVG(EXTRACT(EPOCH FROM (accepted_at - created_at))/60) FILTER (WHERE accepted_at IS NOT NULL), 0) as avg_response_mins
        FROM orders
        WHERE merchant_id = $1 AND created_at >= NOW() - INTERVAL '${d} days'
      `, [merchantId]),

      // Daily trend
      query(`
        SELECT
          DATE(created_at) as date,
          COUNT(*) FILTER (WHERE status = 'delivered') as orders,
          COALESCE(SUM(total) FILTER (WHERE status = 'delivered'), 0) as revenue
        FROM orders
        WHERE merchant_id = $1 AND created_at >= NOW() - INTERVAL '${d} days'
        GROUP BY DATE(created_at)
        ORDER BY date ASC
      `, [merchantId]),

      // Top products
      query(`
        SELECT oi.product_name, SUM(oi.quantity) as qty, SUM(oi.item_total) as revenue
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        WHERE o.merchant_id = $1 AND o.status = 'delivered'
          AND o.created_at >= NOW() - INTERVAL '${d} days'
        GROUP BY oi.product_name
        ORDER BY qty DESC LIMIT 5
      `, [merchantId]),

      // Top customers
      query(`
        SELECT c.name, c.phone, COUNT(o.id) as orders,
               COALESCE(SUM(o.total), 0) as total_spent
        FROM customers c
        JOIN orders o ON o.customer_id = c.id
        WHERE o.merchant_id = $1 AND o.status = 'delivered'
          AND o.created_at >= NOW() - INTERVAL '${d} days'
        GROUP BY c.id
        ORDER BY total_spent DESC LIMIT 5
      `, [merchantId]),

      // Payment breakdown
      query(`
        SELECT payment_method, COUNT(*) as count,
               COALESCE(SUM(total), 0) as total
        FROM orders
        WHERE merchant_id = $1 AND status = 'delivered'
          AND created_at >= NOW() - INTERVAL '${d} days'
        GROUP BY payment_method
      `, [merchantId]),

      // Driver performance
      query(`
        SELECT d.name,
          COUNT(o.id) as deliveries,
          COALESCE(AVG(EXTRACT(EPOCH FROM (o.delivered_at - o.picked_up_at))/60), 0) as avg_delivery_mins
        FROM drivers d
        JOIN orders o ON o.driver_id = d.id
        WHERE d.merchant_id = $1 AND o.status = 'delivered'
          AND o.created_at >= NOW() - INTERVAL '${d} days'
        GROUP BY d.id
        ORDER BY deliveries DESC
      `, [merchantId]),
    ]);

    ok(res, {
      period: `${d} days`,
      summary: summary.rows[0],
      trend: trend.rows,
      topProducts: topProducts.rows,
      topCustomers: topCustomers.rows,
      paymentBreakdown: paymentBreakdown.rows,
      driverStats: driverStats.rows,
    });
  } catch (e) { next(e); }
});

// GET /analytics/daily-summary  (#68)
router.get("/daily-summary", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const date = req.query.date || new Date().toISOString().split("T")[0];

    const { rows } = await query(
      `SELECT
        COUNT(*) FILTER (WHERE status = 'delivered') as completed,
        COUNT(*) FILTER (WHERE status = 'cancelled') as cancelled,
        COUNT(*) FILTER (WHERE status = 'rejected') as rejected,
        COALESCE(SUM(total) FILTER (WHERE status = 'delivered'), 0) as revenue,
        COALESCE(SUM(total) FILTER (WHERE status = 'delivered' AND payment_method = 'cash'), 0) as cash_revenue,
        COALESCE(SUM(total) FILTER (WHERE status = 'delivered' AND payment_method = 'wish'), 0) as wish_revenue,
        COUNT(DISTINCT customer_id) as unique_customers
       FROM orders
       WHERE merchant_id = $1 AND DATE(created_at) = $2`,
      [merchantId, date]
    );

    // Build WhatsApp-formatted EOD message
    const s = rows[0];
    const eodMessage = `📊 *End of Day Summary — ${date}*\n\n` +
      `✅ Completed: ${s.completed} orders\n` +
      `❌ Cancelled: ${s.cancelled}\n` +
      `🚫 Rejected: ${s.rejected}\n\n` +
      `💰 Total Revenue: $${parseFloat(s.revenue).toFixed(2)}\n` +
      `  • Cash: $${parseFloat(s.cash_revenue).toFixed(2)}\n` +
      `  • Wish: $${parseFloat(s.wish_revenue).toFixed(2)}\n\n` +
      `👥 Unique customers: ${s.unique_customers}\n\n` +
      `_NeoTalab — powered by AI 🤖_`;

    ok(res, { date, stats: rows[0], eodMessage });
  } catch (e) { next(e); }
});

// GET /analytics/orders-by-hour
router.get("/orders-by-hour", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { days = 7 } = req.query;

    const { rows } = await query(`
      SELECT EXTRACT(HOUR FROM created_at)::int as hour, COUNT(*) as orders
      FROM orders
      WHERE merchant_id = $1
        AND created_at >= NOW() - INTERVAL '${parseInt(days)} days'
        AND status = 'delivered'
      GROUP BY hour ORDER BY hour
    `, [merchantId]);

    ok(res, { hourlyDistribution: rows });
  } catch (e) { next(e); }
});

export default router;
