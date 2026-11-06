/**
 * Delivery areas are rows: "up to N km from the shop, this fee, this minimum order". A row is saved as a circle
 * centred on the shop pin. Areas drawn on the map before (shapes, or circles somewhere else) are kept as they are:
 * the owner can change their prices or remove them, not redraw them.
 */
export const COLORS = ["#25d366", "#3b82f6", "#f59e0b", "#a855f7", "#ef4444", "#14b8a6", "#eab308", "#ec4899"];
export const DEFAULT_ROWS = [{ km: "3", fee: "1", min: "" }, { km: "6", fee: "2", min: "" }, { km: "10", fee: "3", min: "" }];
export const MAX_ROWS = 8;
export const MIN_KM = 0.5;
export const MAX_KM = 60;

export const minutesFor = (metres) => Math.round(5 + (metres / 1000) * 3);

function metresBetween(lat1, lng1, lat2, lng2) {
  const rad = (d) => (d * Math.PI) / 180;
  const a = Math.sin(rad(lat2 - lat1) / 2) ** 2 + Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(rad(lng2 - lng1) / 2) ** 2;
  return 2 * 6371000 * Math.asin(Math.min(1, Math.sqrt(a)));
}

/** A circle centred on the shop pin (within 100 m): the kind of area a row makes. */
export function isRing(zone, shop) {
  const a = zone.area;
  return Boolean(shop && a?.type === "circle" && metresBetween(a.center[0], a.center[1], shop.lat, shop.lng) <= 100);
}

const text = (v) => String(Number(v));
const minText = (v) => (Number(v) > 0 ? text(v) : "");

/** Splits the shop's saved zones into rows (nearest first) and areas drawn earlier. */
export function rowsFromZones(zones, shop) {
  const rings = zones.filter((z) => isRing(z, shop)).sort((a, b) => a.area.radius - b.area.radius).map((z) => ({
    id: z.id, km: text(z.area.radius / 1000), fee: text(z.delivery_fee), min: minText(z.minimum_order), name: z.name, mins: z.delivery_mins ?? 15,
  }));
  const custom = zones.filter((z) => !isRing(z, shop)).map((z) => ({
    id: z.id, name: z.name, fee: text(z.delivery_fee), min: minText(z.minimum_order), mins: z.delivery_mins ?? 15, color: z.color || COLORS[0],
  }));
  return { rings, custom };
}

/** What stops the rows from being saved: { index, kind } of the first problem (kind: km, dupe, fee, min), or null. */
export function rowsProblem(rows) {
  const seen = new Set();
  for (const [index, r] of rows.entries()) {
    if (r.km !== undefined) {
      const km = Number(r.km);
      if (String(r.km).trim() === "" || !(km >= MIN_KM && km <= MAX_KM)) return { index, kind: "km" };
      if (seen.has(km)) return { index, kind: "dupe" };
      seen.add(km);
    }
    if (String(r.fee ?? "").trim() === "") return { index, kind: "fee" }; // an empty fee is not free delivery, 0 is
    if (Number(r.min || 0) < 0) return { index, kind: "min" };
  }
  return null;
}

/**
 * The requests that bring the saved zones in line with the rows: create the new ones, update the changed
 * ones, remove the zones whose row is gone. A row keeps a name the owner gave it while its distance is unchanged.
 */
export function planSave({ rings, custom, zones, shop, ringName }) {
  const byId = new Map(zones.map((z) => [z.id, z]));
  const money = (r) => ({ deliveryFee: parseFloat(r.fee) || 0, minimumOrder: parseFloat(r.min) || 0 });
  const create = [];
  const update = [];
  [...rings].sort((a, b) => Number(a.km) - Number(b.km)).forEach((r, i) => {
    const radius = Math.round(Number(r.km) * 1000);
    const before = r.id ? byId.get(r.id) : null;
    const keepName = before && before.area?.radius === radius && r.name;
    const body = {
      name: keepName ? r.name : ringName(text(r.km)), color: before?.color || COLORS[i % COLORS.length],
      area: { type: "circle", center: [shop.lat, shop.lng], radius }, ...money(r), deliveryMins: minutesFor(radius),
    };
    if (r.id) update.push({ id: r.id, body }); else create.push(body);
  });
  for (const r of custom) {
    update.push({ id: r.id, body: { name: r.name, color: r.color, ...money(r), deliveryMins: r.mins } });
  }
  const kept = new Set([...rings, ...custom].map((r) => r.id).filter(Boolean));
  return { create, update, remove: zones.map((z) => z.id).filter((id) => !kept.has(id)) };
}
