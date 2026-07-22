/**
 * Driver dispatch service
 * Features: #41, #42, #43, #44, #46
 */
import { query, transaction } from "../db.js";
import { sendWhatsApp } from "./WhatsAppService.js";
import { logger } from "../utils/logger.js";

const DISPATCH_TIMEOUT_MINS = 3;

/**
 * Auto-assign next available staff driver (#41)
 */
export async function dispatchDriver(orderId, merchantId) {
  try {
    const { rows: drivers } = await query(
      `SELECT d.* FROM drivers d
       WHERE d.merchant_id = $1
         AND d.status = 'on_duty'
         AND d.availability = 'available'
         AND d.active_order_id IS NULL
         AND d.is_active = TRUE
       ORDER BY d.total_deliveries ASC, d.id ASC
       LIMIT 1`,
      [merchantId]
    );

    if (!drivers.length) {
      logger.warn("No available drivers", { orderId, merchantId });
      const { rows: merchant } = await query(
        "SELECT ops_whatsapp FROM merchants WHERE id = $1", [merchantId]
      );
      if (merchant[0]) {
        await sendWhatsApp(merchantId, merchant[0].ops_whatsapp, {
          type: "text",
          text: `⚠️ *No available drivers!*\n\nOrder #${orderId.slice(-6).toUpperCase()} needs a driver.\nPlease assign one manually or ask a driver to go on duty.`,
        });
      }
      return null;
    }

    const driver = drivers[0];
    const timeoutAt = new Date(Date.now() + DISPATCH_TIMEOUT_MINS * 60 * 1000);

    await query(
      `INSERT INTO dispatch_attempts (order_id, driver_id, timeout_at) VALUES ($1,$2,$3)`,
      [orderId, driver.id, timeoutAt]
    );

    const { rows: orderRows } = await query(
      `SELECT o.*, c.name as customer_name
       FROM orders o JOIN customers c ON c.id = o.customer_id
       WHERE o.id = $1`,
      [orderId]
    );
    const order = orderRows[0];

    await sendWhatsApp(merchantId, driver.whatsapp_number, {
      type: "interactive",
      interactive: {
        type: "button",
        body: {
          text: `🚨 *New Delivery — ${order?.delivery_building || "Address TBC"}*\n\n` +
                `📦 Order #${orderId.slice(-6).toUpperCase()}\n` +
                `💰 ${order?.payment_method === "cash" ? `Cash $${order.total}` : "Prepaid"}\n\n` +
                `You have ${DISPATCH_TIMEOUT_MINS} minutes to accept.`,
        },
        action: {
          buttons: [
            { type: "reply", reply: { id: `drv_accept_${orderId}`, title: "✅ Accept" } },
            { type: "reply", reply: { id: `drv_reject_${orderId}`, title: "❌ Reject" } },
          ],
        },
      },
    });

    setTimeout(() => checkDispatchTimeout(orderId, driver.id, merchantId), DISPATCH_TIMEOUT_MINS * 60 * 1000);

    logger.info("Driver dispatched", { orderId, driverId: driver.id });
    return driver;
  } catch (e) {
    logger.error("Dispatch error", { orderId, error: e.message });
    throw e;
  }
}

export async function handleDriverResponse(orderId, driverId, response) {
  const { rows: attempts } = await query(
    `SELECT * FROM dispatch_attempts
     WHERE order_id = $1 AND driver_id = $2 AND response IS NULL
     ORDER BY sent_at DESC LIMIT 1`,
    [orderId, driverId]
  );
  if (!attempts[0]) return;

  await query(
    `UPDATE dispatch_attempts SET response = $1, responded_at = NOW() WHERE id = $2`,
    [response, attempts[0].id]
  );

  if (response === "accepted") {
    await transaction(async (client) => {
      await client.query(
        `UPDATE orders SET driver_id = $1, status = 'assigned', assigned_at = NOW() WHERE id = $2`,
        [driverId, orderId]
      );
      await client.query(
        `UPDATE drivers SET availability = 'busy', active_order_id = $1 WHERE id = $2`,
        [orderId, driverId]
      );
    });
  } else {
    const { rows: order } = await query("SELECT merchant_id FROM orders WHERE id = $1", [orderId]);
    if (order[0]) await dispatchDriver(orderId, order[0].merchant_id);
  }
}

async function checkDispatchTimeout(orderId, driverId, merchantId) {
  try {
    const { rows } = await query(
      `SELECT id FROM dispatch_attempts WHERE order_id = $1 AND driver_id = $2 AND response IS NULL`,
      [orderId, driverId]
    );
    if (!rows[0]) return;

    await query(
      `UPDATE dispatch_attempts SET response = 'timeout', responded_at = NOW()
       WHERE order_id = $1 AND driver_id = $2 AND response IS NULL`,
      [orderId, driverId]
    );

    await dispatchDriver(orderId, merchantId);
  } catch (e) {
    logger.error("Timeout check error", { error: e.message });
  }
}
