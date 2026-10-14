/** Lebanese mobile numbers (WhatsApp needs a mobile line): 3x xxx xxx or 70/71/76/78/79/81 xxx xxx. */

export function digitsOnly(value) {
  return String(value ?? "").replace(/\D/g, "");
}

/** Local digits without +961 / 00961 / a leading 0, e.g. "03 123 456" → "3123456". */
function localDigits(value) {
  let d = digitsOnly(value);
  if (d.startsWith("00961")) d = d.slice(5);
  else if (d.startsWith("961") && d.length > 8) d = d.slice(3);
  return d.replace(/^0+/, "");
}

const LB_MOBILE = /^(3\d{6}|(70|71|76|78|79|81)\d{6})$/;

/** Returns an i18n error key, or "" when the number is a valid Lebanese mobile. */
export function lebaneseMobileError(value) {
  const d = localDigits(value);
  if (!d) return "jn.err.phoneEmpty";
  return LB_MOBILE.test(d) ? "" : "jn.err.phone";
}

/** E.164, e.g. "70 123 456" → "+96170123456". */
export function toLebaneseWhatsapp(value) {
  return `+961${localDigits(value)}`;
}

/** Readable form for messages, e.g. "+961 70 123 456". */
export function formatLebaneseMobile(value) {
  const d = localDigits(value);
  const parts = d.length === 7 ? [d.slice(0, 1), d.slice(1, 4), d.slice(4)] : [d.slice(0, 2), d.slice(2, 5), d.slice(5)];
  return `+961 ${parts.join(" ")}`;
}
