import { getPhoneCountry } from "@/lib/phoneCountries";

export function digitsOnly(value) {
  return String(value ?? "").replace(/\D/g, "");
}

/** Combine country dial code + local digits into E.164 (+9613123456). */
export function buildWhatsappNumber(countryCode, localNumber) {
  const country = getPhoneCountry(countryCode);
  const dialDigits = digitsOnly(country.dial);
  let localDigits = digitsOnly(localNumber);

  if (!dialDigits || !localDigits) return "";

  if (localDigits.startsWith("0")) {
    localDigits = localDigits.replace(/^0+/, "");
  }

  return `+${dialDigits}${localDigits}`;
}

/** Returns an i18n key under join.err.* */
export function validateLocalPhone(localNumber) {
  const digits = digitsOnly(localNumber).replace(/^0+/, "");
  if (digits.length < 6) return "join.err.phoneShort";
  if (digits.length > 12) return "join.err.phoneLong";
  return "";
}

export function validateWhatsappNumber(number) {
  if (!/^\+[1-9]\d{6,14}$/.test(number)) {
    return "join.err.phoneInvalid";
  }
  return "";
}
