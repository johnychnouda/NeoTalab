/**
 * AI message parsing service
 * Feature #3 — multilingual + voice order parsing
 *
 * Simulates AI parsing. In production, connect to OpenAI/Claude/Gemini.
 */
import { logger } from "../utils/logger.js";

/**
 * Parse an inbound WhatsApp message and return structured intent + data
 */
export async function parseInboundMessage(msg, customer, shop) {
  try {
    let text = "";

    if (msg.type === "text") {
      text = msg.text?.body || "";
    } else if (msg.type === "audio") {
      // Feature #3 — voice note → transcribe
      text = await transcribeAudio(msg.audio?.id, shop.id);
    } else if (msg.type === "interactive") {
      // Button reply (#4)
      const reply = msg.interactive?.button_reply || msg.interactive?.list_reply;
      if (reply?.id?.startsWith("confirm_")) {
        return { intent: "confirm_order", orderId: reply.id.replace("confirm_", "") };
      }
      if (reply?.id?.startsWith("cancel_")) {
        return { intent: "cancel_order", orderId: reply.id.replace("cancel_", "") };
      }
      text = reply?.title || "";
    } else if (msg.type === "location") {
      return {
        intent: "location",
        lat: msg.location?.latitude,
        lng: msg.location?.longitude,
        rawMessage: JSON.stringify(msg.location),
      };
    }

    if (!text.trim()) return null;

    const locale = detectLocale(text, customer.preferred_locale);

    // Classify intent
    const intent = classifyIntent(text, locale);

    if (intent === "order") {
      const items = parseOrderItems(text, locale);
      return { intent, items, rawMessage: text, locale };
    }

    if (intent === "wish_payment") {
      const amount = extractWishAmount(text);
      return { intent, amount, rawMessage: text };
    }

    if (intent === "status") {
      return { intent: "status", rawMessage: text };
    }

    if (intent === "human_handoff") {
      return { intent: "human_handoff", message: text, rawMessage: text };
    }

    return { intent: "unknown", rawMessage: text };
  } catch (e) {
    logger.error("AI parsing error", { error: e.message });
    return null;
  }
}

/**
 * Detect message language (#3, #64)
 */
export function detectLocale(text, fallback = "ar") {
  const arabicPattern = /[؀-ۿ]/;
  const frenchPattern = /\b(bonjour|merci|commande|livraison|je|veux|voudrais)\b/i;

  if (arabicPattern.test(text)) return "ar";
  if (frenchPattern.test(text)) return "fr";
  if (/[a-zA-Z]/.test(text)) return "en";
  return fallback;
}

/**
 * Classify the intent of a message
 */
function classifyIntent(text, locale) {
  const t = text.toLowerCase().trim();

  // Order keywords
  const orderKeywords = {
    en: ["order", "want", "i'd like", "give me", "can i get", "menu", "buy"],
    ar: ["طلب", "أريد", "عايز", "بدي", "اطلب", "اعطني"],
    fr: ["commande", "je veux", "je voudrais", "donnez-moi"],
  };

  // Status keywords
  const statusKeywords = {
    en: ["where", "status", "when", "order status", "my order"],
    ar: ["وين", "أين", "متى", "طلبي", "وضع الطلب"],
    fr: ["où est", "statut", "commande"],
  };

  // Wish payment keywords
  const wishKeywords = ["wish", "ويش", "sent", "بعتلك", "أرسلت", "دفعت", "$"];

  // Human handoff
  const humanKeywords = ["help", "مساعدة", "human", "person", "speak", "agent", "مشكلة"];

  const keywords = orderKeywords[locale] || orderKeywords.en;
  const statusKw = statusKeywords[locale] || statusKeywords.en;

  if (wishKeywords.some(k => t.includes(k))) return "wish_payment";
  if (humanKeywords.some(k => t.includes(k))) return "human_handoff";
  if (statusKw.some(k => t.includes(k))) return "status";
  if (keywords.some(k => t.includes(k)) || /\d/.test(t)) return "order";

  return "unknown";
}

/**
 * Parse order items from natural language
 * In production: replace with LLM call
 */
function parseOrderItems(text, locale) {
  const items = [];

  // Pattern: "2 shawarmas" / "اثنين شاورما" / "2x burger"
  const patterns = [
    /(\d+)\s*x?\s*([a-zA-Z؀-ۿ\s]+?)(?:,|and|و|$)/gi,
    /([a-zA-Z؀-ۿ\s]+?)\s*x(\d+)/gi,
  ];

  for (const pattern of patterns) {
    let match;
    while ((match = pattern.exec(text)) !== null) {
      const qty = parseInt(match[1]);
      const name = match[2]?.trim();
      if (qty && name && name.length > 1) {
        // Parse modifiers: "no X", "without X", "extra X", "بدون X"
        const modifiers = parseModifiers(text, name);
        items.push({ name, quantity: qty, modifiers });
      }
    }
  }

  // If no pattern matched, treat whole message as 1 item
  if (!items.length && text.trim()) {
    items.push({ name: text.trim().slice(0, 50), quantity: 1, modifiers: [] });
  }

  return items;
}

function parseModifiers(text, itemName) {
  const modifiers = [];
  const noPatterns = [
    /no\s+(\w+)/gi,
    /without\s+(\w+)/gi,
    /بدون\s+(\S+)/gi,
    /من غير\s+(\S+)/gi,
  ];
  const extraPatterns = [
    /extra\s+(\w+)/gi,
    /زيادة\s+(\S+)/gi,
  ];

  for (const p of noPatterns) {
    let m;
    while ((m = p.exec(text)) !== null) {
      modifiers.push({ name: `no ${m[1]}`, price_delta: 0 });
    }
  }
  for (const p of extraPatterns) {
    let m;
    while ((m = p.exec(text)) !== null) {
      modifiers.push({ name: `extra ${m[1]}`, price_delta: 0.5 });
    }
  }

  return modifiers;
}

function extractWishAmount(text) {
  const match = text.match(/\$?\s*(\d+(?:\.\d{1,2})?)/);
  return match ? parseFloat(match[1]) : null;
}

async function transcribeAudio(audioId, merchantId) {
  // In production: download audio from WA, send to Whisper/Google STT
  logger.info("Audio transcription requested", { audioId, merchantId });
  return ""; // placeholder
}
