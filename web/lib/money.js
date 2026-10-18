/** "12.00$" for US dollars (the way prices are written in Lebanon), "12.00 EUR" / "150,000 LBP" for the shop's other currencies. */
export function formatMoney(value, currency = "USD") {
  const n = Number(value || 0);
  if (!currency || currency === "USD") return `${n.toFixed(2)}$`;
  if (currency === "LBP") return `${Math.round(n).toLocaleString("en-US")} LBP`;
  return `${n.toFixed(2)} ${currency}`;
}

/** Short symbol for a price field label: "$" or the currency code. */
export function currencyLabel(currency = "USD") {
  return !currency || currency === "USD" ? "$" : currency;
}

/** Lira for a price, rounded up to the next 1,000 like the WhatsApp chat does; null without a rate. */
export function toLbp(value, rate) {
  const n = Number(value);
  if (!rate || value === "" || value === null || value === undefined || Number.isNaN(n)) return null;
  return Math.ceil((n * rate) / 1000) * 1000;
}

export const formatLbp = (n) => `${Math.round(n).toLocaleString("en-US")} LBP`;
