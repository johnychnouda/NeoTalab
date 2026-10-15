// Public business identity shown on the website, privacy policy and terms. Meta compares these
// with the documents used for Business Verification, so they come from Railway variables (set on
// the web service, then redeploy). The defaults below are the business that verified NeoTalab with
// Meta; anything left empty is simply not shown (no phone, no analytics).
const env = (v, fallback = "") => (v && String(v).trim()) || fallback;

export const company = {
  brand: "NeoTalab",
  legalName: env(process.env.NEXT_PUBLIC_COMPANY_LEGAL_NAME, "Bechaalany Connect"),
  address: env(process.env.NEXT_PUBLIC_COMPANY_ADDRESS, "Property No. 85, Al Mraijat, Bekaa"),
  country: env(process.env.NEXT_PUBLIC_COMPANY_COUNTRY, "Lebanon"),
  email: env(process.env.NEXT_PUBLIC_CONTACT_EMAIL, "support@neotalab.com"),
  phone: env(process.env.NEXT_PUBLIC_CONTACT_PHONE),
  whatsapp: env(process.env.NEXT_PUBLIC_CONTACT_WHATSAPP),
  siteUrl: env(process.env.NEXT_PUBLIC_SITE_URL, "https://neotalab.com"),
  // Meta Business Settings → Brand safety → Domains → "Meta-tag verification" content value.
  facebookDomainVerification: env(process.env.NEXT_PUBLIC_FB_DOMAIN_VERIFICATION),
  // Google Search Console "HTML tag" verification value.
  googleSiteVerification: env(process.env.NEXT_PUBLIC_GOOGLE_SITE_VERIFICATION),
  // Google Analytics 4 measurement ID (G-XXXXXXX). Loaded only after the visitor accepts cookies.
  gaMeasurementId: env(process.env.NEXT_PUBLIC_GA_MEASUREMENT_ID),
  policiesUpdated: "5 October 2026",
};
