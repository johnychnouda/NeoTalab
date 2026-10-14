import { cookies } from "next/headers";

export const SITE_LANGS = ["en", "ar", "fr"];

/** Public-site language, chosen with the header switcher (cookie shared with the dashboards' LangProvider). */
export async function getSiteLang() {
  const value = (await cookies()).get("nt_lang")?.value;
  return SITE_LANGS.includes(value) ? value : "en";
}
