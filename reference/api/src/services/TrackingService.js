/** #48 #77 Live tracking + geofencing */
import { tenant, branch } from "../data/seed.js";

export function processDriverPing(orderId, { lat, lng }) {
  const distM = haversineM(lat, lng, branch.latitude, branch.longitude);
  const events = [];
  if (distM <= tenant.geofence_arrived_meters) events.push({ type: "arrived", feature: 48 });
  else if (distM <= tenant.geofence_nearby_meters) events.push({ type: "nearby", feature: 48 });
  return { order_id: orderId, distance_m: Math.round(distM), events, eta_min: Math.max(5, Math.round(distM / 80)) };
}

/** #50 Dynamic ETA */
export function computeEtaMinutes(order, driver) {
  const prep = order.prep_minutes || 12;
  const distM = driver?.lat
    ? haversineM(driver.lat, driver.lng, branch.latitude, branch.longitude)
    : 2000;
  return prep + Math.max(5, Math.round(distM / 80));
}

function haversineM(lat1, lon1, lat2, lon2) {
  const R = 6371000;
  const toRad = (d) => (d * Math.PI) / 180;
  const dLat = toRad(lat2 - lat1);
  const dLon = toRad(lon2 - lon1);
  const a =
    Math.sin(dLat / 2) ** 2 +
    Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLon / 2) ** 2;
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}
