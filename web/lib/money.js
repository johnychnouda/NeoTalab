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
