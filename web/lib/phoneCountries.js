/** ISO 3166-1 alpha-2 codes with E.164 dial prefixes for join / contact forms. */
export const DEFAULT_PHONE_COUNTRY = "LB";

export const PHONE_COUNTRIES = [
  { code: "LB", name: "Lebanon", dial: "+961", flag: "🇱🇧", placeholder: "3 123 456", cityPlaceholder: "Beirut", regionPlaceholderKey: "join.regionPh.LB" },
  { code: "AE", name: "United Arab Emirates", dial: "+971", flag: "🇦🇪", placeholder: "50 123 4567", cityPlaceholder: "Dubai", regionPlaceholderKey: "join.regionPh.AE" },
  { code: "SA", name: "Saudi Arabia", dial: "+966", flag: "🇸🇦", placeholder: "50 123 4567", cityPlaceholder: "Riyadh", regionPlaceholderKey: "join.regionPh.SA" },
  { code: "JO", name: "Jordan", dial: "+962", flag: "🇯🇴", placeholder: "7 9012 3456", cityPlaceholder: "Amman", regionPlaceholderKey: "join.regionPh.JO" },
  { code: "SY", name: "Syria", dial: "+963", flag: "🇸🇾", placeholder: "944 123 456", cityPlaceholder: "Damascus", regionPlaceholderKey: "join.regionPh.SY" },
  { code: "IQ", name: "Iraq", dial: "+964", flag: "🇮🇶", placeholder: "790 123 4567", cityPlaceholder: "Baghdad", regionPlaceholderKey: "join.regionPh.IQ" },
  { code: "EG", name: "Egypt", dial: "+20", flag: "🇪🇬", placeholder: "10 1234 5678", cityPlaceholder: "Cairo", regionPlaceholderKey: "join.regionPh.EG" },
  { code: "KW", name: "Kuwait", dial: "+965", flag: "🇰🇼", placeholder: "5012 3456", cityPlaceholder: "Kuwait City", regionPlaceholderKey: "join.regionPh.KW" },
  { code: "QA", name: "Qatar", dial: "+974", flag: "🇶🇦", placeholder: "3312 3456", cityPlaceholder: "Doha", regionPlaceholderKey: "join.regionPh.QA" },
  { code: "BH", name: "Bahrain", dial: "+973", flag: "🇧🇭", placeholder: "3612 3456", cityPlaceholder: "Manama", regionPlaceholderKey: "join.regionPh.BH" },
  { code: "OM", name: "Oman", dial: "+968", flag: "🇴🇲", placeholder: "9212 3456", cityPlaceholder: "Muscat", regionPlaceholderKey: "join.regionPh.OM" },
  { code: "PS", name: "Palestine", dial: "+970", flag: "🇵🇸", placeholder: "59 123 4567", cityPlaceholder: "Ramallah", regionPlaceholderKey: "join.regionPh.PS" },
  { code: "TR", name: "Turkey", dial: "+90", flag: "🇹🇷", placeholder: "532 123 4567", cityPlaceholder: "Istanbul", regionPlaceholderKey: "join.regionPh.TR" },
  { code: "FR", name: "France", dial: "+33", flag: "🇫🇷", placeholder: "6 12 34 56 78", cityPlaceholder: "Paris", regionPlaceholderKey: "join.regionPh.FR" },
  { code: "GB", name: "United Kingdom", dial: "+44", flag: "🇬🇧", placeholder: "7911 123456", cityPlaceholder: "London", regionPlaceholderKey: "join.regionPh.GB" },
  { code: "US", name: "United States", dial: "+1", flag: "🇺🇸", placeholder: "202 555 0123", cityPlaceholder: "New York", regionPlaceholderKey: "join.regionPh.US" },
  { code: "CA", name: "Canada", dial: "+1", flag: "🇨🇦", placeholder: "416 555 0123", cityPlaceholder: "Toronto", regionPlaceholderKey: "join.regionPh.CA" },
  { code: "DE", name: "Germany", dial: "+49", flag: "🇩🇪", placeholder: "151 12345678", cityPlaceholder: "Berlin", regionPlaceholderKey: "join.regionPh.DE" },
  { code: "AU", name: "Australia", dial: "+61", flag: "🇦🇺", placeholder: "412 345 678", cityPlaceholder: "Sydney", regionPlaceholderKey: "join.regionPh.AU" },
];

/** @deprecated alias — same list used for business location country */
export const BUSINESS_COUNTRIES = PHONE_COUNTRIES;

export function getPhoneCountry(code) {
  return PHONE_COUNTRIES.find((c) => c.code === code) ?? PHONE_COUNTRIES[0];
}

export function getRegionPlaceholderKey(code) {
  return getPhoneCountry(code).regionPlaceholderKey ?? "join.regionPlaceholder";
}
