import { zones, branch } from "../data/seed.js";

/** #25 #27 */
export function checkZone(lat, lng, subtotal) {
  const z = zones.find((x) => x.is_active);
  if (!z) return { ok: false, reason: "no_zone" };
  const dist = haversineM(branch.latitude, branch.longitude, lat, lng);
  if (dist > z.radius_meters) {
    return { ok: false, reason: "outside_zone", suggest_pickup: true };
  }
  if (subtotal < z.min_order_amount) {
    return { ok: false, reason: "below_minimum", minimum: z.min_order_amount };
  }
  let fee = z.fee;
  if (z.free_delivery_over && subtotal >= z.free_delivery_over) fee = 0;
  if (z.surge_multiplier) fee = Math.round(fee * z.surge_multiplier);
  return { ok: true, zone: z, fee, distance_m: Math.round(dist) };
}

function haversineM(lat1, lon1, lat2, lon2) {
  const R = 6371000;
  const toR = (d) => (d * Math.PI) / 180;
  const dLat = toR(lat2 - lat1);
  const dLon = toR(lon2 - lon1);
  const a =
    Math.sin(dLat / 2) ** 2 +
    Math.cos(toR(lat1)) * Math.cos(toR(lat2)) * Math.sin(dLon / 2) ** 2;
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}
