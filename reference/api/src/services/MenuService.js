import { products } from "../data/seed.js";

/** #8 #9 #10 #11 #12 */
export function getAvailableProducts(schedule = "default") {
  return products.filter((p) => {
    if (!p.is_available) return false;
    if (p.track_inventory && p.stock_quantity <= 0) return false;
    return true;
  });
}

export function validateLineItems(lines) {
  const errors = [];
  for (const line of lines) {
    const p = products.find((x) => x.id === line.product_id);
    if (!p || !p.is_available) {
      errors.push({ product_id: line.product_id, reason: "unavailable" });
      continue;
    }
    if (p.track_inventory && line.quantity > p.stock_quantity) {
      errors.push({ product_id: line.product_id, reason: "insufficient_stock", max: p.stock_quantity });
    }
  }
  return errors;
}

export function calcLineTotal(line) {
  const p = products.find((x) => x.id === line.product_id);
  if (!p) return 0;
  const mod = (line.modifiers || []).reduce((s, m) => s + (m.price_delta || 0), 0);
  return (p.base_price + mod) * line.quantity;
}
