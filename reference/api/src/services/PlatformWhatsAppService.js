/**
 * Send WhatsApp messages from the platform owner account (billing reminders, etc.)
 */
import { query } from "../db.js";
import { logger } from "../utils/logger.js";

const GRAPH_API = "https://graph.facebook.com/v20.0";

function normalizePhone(phone) {
  return String(phone || "").replace(/\D/g, "");
}

async function loadPlatformCredentials() {
  const { rows } = await query(
    "SELECT wa_phone_id, wa_access_token FROM platform_settings WHERE id = 1"
  );
  return rows[0] || {};
}

/**
 * Send a text message from the platform WhatsApp number
 * @param {string} toPhone
 * @param {string} text
 */
export async function sendPlatformWhatsApp(toPhone, text) {
  const to = normalizePhone(toPhone);
  if (!to) throw new Error("Recipient phone required");

  const { wa_phone_id: phoneId, wa_access_token: token } = await loadPlatformCredentials();

  if (!phoneId || !token) {
    logger.info("📱 [PLATFORM WA SIMULATED]", { to, preview: text.slice(0, 120) });
    return { simulated: true };
  }

  const response = await fetch(`${GRAPH_API}/${phoneId}/messages`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      messaging_product: "whatsapp",
      recipient_type: "individual",
      to,
      type: "text",
      text: { body: text, preview_url: false },
    }),
  });

  const data = await response.json();
  if (!response.ok) {
    logger.error("Platform WhatsApp API error", { status: response.status, data });
    throw new Error(data.error?.message || `WA API error ${response.status}`);
  }

  logger.info("Platform WhatsApp reminder sent", { to, messageId: data.messages?.[0]?.id });
  return data;
}
