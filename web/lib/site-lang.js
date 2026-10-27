import { cookies } from "next/headers";

export const SITE_LANGS = ["en", "ar", "fr"];

/** Public-site language, chosen with the header switcher (cookie shared with the dashboards' LangProvider). */
export async function getSiteLang() {
  const value = (await cookies()).get("nt_lang")?.value;
  return SITE_LANGS.includes(value) ? value : "en";
}

// Browser-tab titles of the public pages. Descriptions stay in English for search engines.
const PAGE_TITLES = {
  en: {
    home: "NeoTalab — AI WhatsApp ordering & booking for local businesses",
    join: "Join NeoTalab — Start Your Free Trial",
    contact: "Contact — NeoTalab",
    privacy: "Privacy Policy — NeoTalab",
    terms: "Terms of Service — NeoTalab",
    deletion: "Data Deletion — NeoTalab",
    payment: "Payment — NeoTalab",
    reset: "Choose a new password · NeoTalab",
  },
  ar: {
    home: "NeoTalab — طلبات وحجوزات عبر واتساب بالذكاء الاصطناعي للأعمال المحلية",
    join: "انضم إلى NeoTalab — ابدأ تجربتك المجانية",
    contact: "التواصل — NeoTalab",
    privacy: "سياسة الخصوصية — NeoTalab",
    terms: "شروط الخدمة — NeoTalab",
    deletion: "حذف البيانات — NeoTalab",
    payment: "الدفع — NeoTalab",
    reset: "اختر كلمة سر جديدة · NeoTalab",
  },
  fr: {
    home: "NeoTalab — Commandes et réservations WhatsApp par IA pour les commerces locaux",
    join: "Rejoindre NeoTalab — Commencez votre essai gratuit",
    contact: "Contact — NeoTalab",
    privacy: "Politique de confidentialité — NeoTalab",
    terms: "Conditions d'utilisation — NeoTalab",
    deletion: "Suppression des données — NeoTalab",
    payment: "Paiement — NeoTalab",
    reset: "Choisissez un nouveau mot de passe · NeoTalab",
  },
};

/** Page metadata with the tab title in the visitor's language. */
export async function pageMetadata(page, rest = {}) {
  return { ...rest, title: PAGE_TITLES[await getSiteLang()][page] };
}
