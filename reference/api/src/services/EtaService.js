/**
 * ETA service
 * Features: #37, #49, #50
 */
import { query } from "../db.js";

/**
 * Compute prep time for an order based on items (#37)
 */
export async function computePrepTime(merchantId, items) {
  if (!items?.length) return 15;

  const productIds = items.map(i => i.productId).filter(Boolean);
  if (!productIds.length) return 15;

  const { rows } = await query(
    `SELECT MAX(prep_time_mins) as max_prep FROM products WHERE id = ANY($1)`,
    [productIds]
  );

  return parseInt(rows[0]?.max_prep || 15);
}

/**
 * Compute ETA: prep + estimated delivery time (#50)
 * In production: use real distance (Google Maps / HERE)
 */
export async function computeEta(merchantId, deliveryLat, deliveryLng, prepMins) {
  // Base prep time
  const prep = prepMins || 15;

  // Estimate delivery time based on zone distance
  // Simple fallback: flat 20 minutes per zone (#49)
  let deliveryMins = 20;

  if (deliveryLat && deliveryLng) {
    // In production: call Maps API for real drive time
    // For now: rough estimate based on GPS distance from shop
    const { rows: merchant } = await query(
      "SELECT address FROM merchants WHERE id = $1", [merchantId]
    );
    // Default: 15-25 mins depending on zone
    deliveryMins = 20;
  }

  return {
    prepMins: prep,
    deliveryMins,
    totalEtaMins: prep + deliveryMins,
  };
}

/**
 * Build ETA text message for customer
 */
export function etaText(etaMins, locale = "ar") {
  const msgs = {
    en: `⏱ Estimated delivery time: ~${etaMins} minutes`,
    ar: `⏱ الوقت المتوقع للتوصيل: ~${etaMins} دقيقة`,
    fr: `⏱ Délai de livraison estimé: ~${etaMins} minutes`,
  };
  return msgs[locale] || msgs.en;
}
