import { merchantLoginEmail } from "@/lib/merchantLoginEmail";

function loginUrl(shopSlug) {
  const origin = typeof window !== "undefined" ? window.location.origin : "";
  const path = shopSlug ? `/backoffice/${shopSlug}` : "/backoffice";
  return origin ? `${origin}${path}` : path;
}

/** First-time approval welcome message. */
export function buildWelcomeMessage(shopName, otp, loginEmail, shopSlug) {
  const email = loginEmail || merchantLoginEmail(shopName);
  const otpLine = otp ? `🔐 Activation code: *${otp}*\n` : "";
  return `Hello! 👋 Welcome to NeoTalab!\n\nYour merchant account for *${shopName}* is ready.\n\n🔗 Login: ${loginUrl(shopSlug)}\n📧 Email: ${email}\n${otpLine}Use the activation code as your first password — you'll set your own password right after.\n\nNeed help? Just reply here! 🙌`;
}

/** Resend Access — regain / recover login credentials. */
export function buildRegainAccessMessage(shopName, otp, loginEmail, shopSlug) {
  const email = loginEmail || merchantLoginEmail(shopName);
  const otpLine = otp ? `🔐 Temporary access code: *${otp}*\n` : "";
  return `Hello! 🔐 Here's how to regain access to your NeoTalab account for *${shopName}*.\n\nWe reset your login so you can get back into the merchant portal.\n\n🔗 Login: ${loginUrl(shopSlug)}\n📧 Email: ${email}\n${otpLine}Sign in with this temporary code, then set a new password right away.\n\nIf you didn't ask for this, reply here and we'll help.`;
}

export function buildAccessMessage(shopName, otp, loginEmail, variant = "welcome", shopSlug) {
  return variant === "regain"
    ? buildRegainAccessMessage(shopName, otp, loginEmail, shopSlug)
    : buildWelcomeMessage(shopName, otp, loginEmail, shopSlug);
}

export function welcomeWaMeUrl(waNumber, shopName, otp, loginEmail, variant = "welcome", shopSlug) {
  const clean = (waNumber || "").replace(/\D/g, "");
  if (!clean) return null;
  const msg = buildAccessMessage(shopName, otp, loginEmail, variant, shopSlug);
  return `https://wa.me/${clean}?text=${encodeURIComponent(msg)}`;
}

/**
 * Open WhatsApp (app or web) with credentials.
 * Call during a user click — or pass a popup opened earlier with about:blank
 * so async approve flows aren't blocked by popup blockers.
 */
export function openWelcomeWhatsApp(waNumber, shopName, otp, loginEmail, existingPopup = null, variant = "welcome", shopSlug) {
  const url = welcomeWaMeUrl(waNumber, shopName, otp, loginEmail, variant, shopSlug);
  if (!url) return false;
  if (existingPopup && !existingPopup.closed) {
    existingPopup.location.href = url;
    return true;
  }
  window.open(url, "_blank", "noopener,noreferrer");
  return true;
}
