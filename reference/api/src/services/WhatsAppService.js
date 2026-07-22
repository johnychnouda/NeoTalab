/**
 * WhatsApp Business API service
 * Feature #1, #4, #72, #73, #74
 *
 * In development: logs messages to console (simulated)
 * In production: calls Meta Cloud API
 */
import { query } from "../db.js";
import { logger } from "../utils/logger.js";

const GRAPH_API = "https://graph.facebook.com/v20.0";

/**
 * Send a WhatsApp message on behalf of a merchant
 * @param {string} merchantId
 * @param {string} toPhone
 * @param {object} message  { type: "text"|"interactive", text?, interactive? }
 */
export async function sendWhatsApp(merchantId, toPhone, message) {
  // Get merchant WA credentials
  const { rows } = await query(
    "SELECT wa_phone_id, wa_access_token FROM merchants WHERE id = $1",
    [merchantId]
  );
  const merchant = rows[0];

  if (!merchant?.wa_phone_id || !merchant?.wa_access_token) {
    // Simulated mode — just log
    logger.info("📱 [WA SIMULATED] Message", {
      to: toPhone,
      type: message.type,
      content: message.text || JSON.stringify(message.interactive).slice(0, 100),
    });
    return { simulated: true };
  }

  // Build payload
  const payload = {
    messaging_product: "whatsapp",
    recipient_type: "individual",
    to: toPhone,
    type: message.type,
  };

  if (message.type === "text") {
    payload.text = { body: message.text, preview_url: false };
  } else if (message.type === "interactive") {
    payload.interactive = message.interactive;
  } else if (message.type === "template") {
    payload.template = message.template;
  }

  // Send to Meta API
  try {
    const response = await fetch(`${GRAPH_API}/${merchant.wa_phone_id}/messages`, {
      method: "POST",
      headers: {
        Authorization: `Bearer ${merchant.wa_access_token}`,
        "Content-Type": "application/json",
      },
      body: JSON.stringify(payload),
    });

    const data = await response.json();

    if (!response.ok) {
      logger.error("WhatsApp API error", { status: response.status, data });
      throw new Error(`WA API error: ${data.error?.message || response.status}`);
    }

    logger.debug("WhatsApp message sent", { to: toPhone, messageId: data.messages?.[0]?.id });
    return data;
  } catch (err) {
    logger.error("Failed to send WhatsApp message", { to: toPhone, error: err.message });
    // Feature #73 — retry logic (simplified: log failure, would queue for retry in prod)
    throw err;
  }
}

/**
 * Send a WhatsApp template message (for first-time outreach)
 */
export async function sendTemplate(merchantId, toPhone, templateName, components = []) {
  return sendWhatsApp(merchantId, toPhone, {
    type: "template",
    template: {
      name: templateName,
      language: { code: "en" },
      components,
    },
  });
}
