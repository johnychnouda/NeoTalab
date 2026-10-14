import { digitsOnly, lebaneseMobileError, toLebaneseWhatsapp, formatLebaneseMobile } from "@/lib/phone";

/**
 * Countries a shop can apply from on /join. Keep in sync with App\Support\Countries (backend),
 * which gives each new shop its time zone and currency.
 */
const ALL_COUNTRIES = [
  { code: "LB", dial: "961", flag: "🇱🇧", name: { en: "Lebanon", ar: "لبنان", fr: "Liban" }, example: "70 123 456" },
  { code: "AE", dial: "971", flag: "🇦🇪", name: { en: "United Arab Emirates", ar: "الإمارات", fr: "Émirats arabes unis" }, example: "50 123 4567" },
  { code: "SA", dial: "966", flag: "🇸🇦", name: { en: "Saudi Arabia", ar: "السعودية", fr: "Arabie saoudite" }, example: "50 123 4567" },
  { code: "QA", dial: "974", flag: "🇶🇦", name: { en: "Qatar", ar: "قطر", fr: "Qatar" }, example: "3312 3456" },
  { code: "KW", dial: "965", flag: "🇰🇼", name: { en: "Kuwait", ar: "الكويت", fr: "Koweït" }, example: "5001 2345" },
  { code: "BH", dial: "973", flag: "🇧🇭", name: { en: "Bahrain", ar: "البحرين", fr: "Bahreïn" }, example: "3600 1234" },
  { code: "OM", dial: "968", flag: "🇴🇲", name: { en: "Oman", ar: "عُمان", fr: "Oman" }, example: "9212 3456" },
  { code: "JO", dial: "962", flag: "🇯🇴", name: { en: "Jordan", ar: "الأردن", fr: "Jordanie" }, example: "7 9012 3456" },
  { code: "IQ", dial: "964", flag: "🇮🇶", name: { en: "Iraq", ar: "العراق", fr: "Irak" }, example: "790 123 4567" },
  { code: "EG", dial: "20", flag: "🇪🇬", name: { en: "Egypt", ar: "مصر", fr: "Égypte" }, example: "100 123 4567" },
  { code: "CY", dial: "357", flag: "🇨🇾", name: { en: "Cyprus", ar: "قبرص", fr: "Chypre" }, example: "96 123456" },
  { code: "FR", dial: "33", flag: "🇫🇷", name: { en: "France", ar: "فرنسا", fr: "France" }, example: "6 12 34 56 78" },
];

/** Where NeoTalab is open for new shops right now (Lebanon only). Same list as Countries::ENABLED. */
const ENABLED = ["LB"];

export const COUNTRIES = ALL_COUNTRIES.filter((c) => ENABLED.includes(c.code));

export const DEFAULT_COUNTRY = "LB";

export function getCountry(code) {
  return ALL_COUNTRIES.find((c) => c.code === code) || ALL_COUNTRIES[0];
}

/** National digits without the country code, "00" prefix or leading 0. */
function nationalDigits(country, value) {
  const { dial } = getCountry(country);
  let d = digitsOnly(value);
  if (d.startsWith(`00${dial}`)) d = d.slice(2 + dial.length);
  else if (d.startsWith(dial) && d.length > dial.length + 6) d = d.slice(dial.length);
  return d.replace(/^0+/, "");
}

/** Returns an i18n error key, or "" when the number looks like a mobile of that country. */
export function mobileError(country, value) {
  if (country === "LB") return lebaneseMobileError(value);
  const d = nationalDigits(country, value);
  if (!d) return "jn.err.phoneEmpty";
  return d.length >= 7 && d.length <= 11 ? "" : "jn.err.phoneIntl";
}

/** E.164, e.g. ("AE", "050 123 4567") → "+971501234567". */
export function toWhatsapp(country, value) {
  if (country === "LB") return toLebaneseWhatsapp(value);
  return `+${getCountry(country).dial}${nationalDigits(country, value)}`;
}

/** Readable form for messages, e.g. "+971 501234567". */
export function formatMobile(country, value) {
  if (country === "LB") return formatLebaneseMobile(value);
  return `+${getCountry(country).dial} ${nationalDigits(country, value)}`;
}
