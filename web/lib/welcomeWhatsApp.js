import { merchantLoginEmail } from "@/lib/merchantLoginEmail";

function loginUrl(shopSlug) {
  const origin = typeof window !== "undefined" ? window.location.origin : "";
  const path = shopSlug ? `/backoffice/${shopSlug}` : "/backoffice";
  return origin ? `${origin}${path}` : path;
}

/**
 * Welcome (first approval) or regain-access message, in the owner's portal language.
 * `t` is the useLang() translator.
 */
export function buildAccessMessage(t, shopName, otp, loginEmail, variant = "welcome", shopSlug) {
  const vars = {
    shop: shopName,
    url: loginUrl(shopSlug),
    email: loginEmail || merchantLoginEmail(shopName),
    code: otp || "—",
  };
  return t(variant === "regain" ? "ow.msg.regain" : "ow.msg.welcome", vars);
}

export function welcomeWaMeUrl(t, waNumber, shopName, otp, loginEmail, variant = "welcome", shopSlug) {
  const clean = (waNumber || "").replace(/\D/g, "");
  if (!clean) return null;
  const msg = buildAccessMessage(t, shopName, otp, loginEmail, variant, shopSlug);
  return `https://wa.me/${clean}?text=${encodeURIComponent(msg)}`;
}

/**
 * Open WhatsApp (app or web) with credentials.
 * Call during a user click, or pass a popup opened earlier with about:blank
 * so async approve flows aren't blocked by popup blockers.
 */
export function openWelcomeWhatsApp(t, waNumber, shopName, otp, loginEmail, existingPopup = null, variant = "welcome", shopSlug) {
  const url = welcomeWaMeUrl(t, waNumber, shopName, otp, loginEmail, variant, shopSlug);
  if (!url) return false;
  if (existingPopup && !existingPopup.closed) {
    existingPopup.location.href = url;
    return true;
  }
  window.open(url, "_blank", "noopener,noreferrer");
  return true;
}
