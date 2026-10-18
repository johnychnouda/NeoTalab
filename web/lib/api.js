export const API = process.env.NEXT_PUBLIC_API_URL || "http://127.0.0.1:8001/api/v1";

const TOKEN_KEYS = { owner: "nt_owner_token", merchant: "nt_merchant_token" };

/** Saving anything tells every open page to reload (see lib/live.js), in this tab and other tabs. */
export const DATA_CHANGED_EVENT = "nt:data-changed";
export const DATA_CHANGED_KEY = "nt_data_changed";

export function announceChange() {
  if (typeof window === "undefined") return;
  window.dispatchEvent(new Event(DATA_CHANGED_EVENT));
  try { localStorage.setItem(DATA_CHANGED_KEY, String(Date.now())); } catch { /* storage blocked: this tab still updates */ }
}

const OWNER_ROLE = "platform-super-admin";
const MERCHANT_ROLES = ["merchant-owner", "merchant-admin", "merchant-staff"];

export function getToken(role) {
  if (typeof window === "undefined") return null;
  return localStorage.getItem(TOKEN_KEYS[role]);
}

export function setToken(role, token) {
  localStorage.setItem(TOKEN_KEYS[role], token);
}

export function clearToken(role) {
  localStorage.removeItem(TOKEN_KEYS[role]);
}

function apiErrorMessage(data, status) {
  if (data?.errors) {
    const first = Object.values(data.errors).flat()[0];
    if (first) return first;
  }
  return data?.message || data?.error || `Request failed (${status})`;
}

function unwrap(data) {
  if (data && typeof data === "object" && "data" in data && !("token" in data)) {
    return data.data;
  }
  return data;
}

function normalizeMerchant(m) {
  if (!m) return m;
  const sub = m.subscription_status ?? (m.plan === "trial" ? "trial" : m.status === "active" ? "paid" : m.status);
  return {
    ...m,
    shop_name: m.shop_name ?? m.name,
    whatsapp_number: m.whatsapp_number ?? m.phone,
    subscription_status: sub,
    subscription_ends: m.subscription_ends ?? m.subscription_ends_at,
    last_payment_at: m.last_payment_at ?? null,
    monthly_fee: m.monthly_fee ?? 0,
    yearly_fee: m.yearly_fee ?? 0,
    bot_status: m.bot_status ?? "inactive",
  };
}

function unwrapList(data) {
  if (Array.isArray(data)) return data;
  if (data?.data && Array.isArray(data.data)) return data.data;
  return data ? [data] : [];
}

function normalizeProduct(p) {
  if (!p) return p;
  return {
    ...p,
    is_sold_out: p.is_sold_out ?? !p.is_available,
    category_name: p.category_name ?? p.category?.name,
  };
}

/**
 * Reshape a few responses into what the pages expect (lists under a named key, merchants with
 * the owner portal's field names). Everything else comes back as the server sent it.
 */
function normalizeResponse(method, path, raw) {
  const data = unwrap(raw);
  const route = path.split("?")[0];

  if (route.startsWith("/admin/merchants")) {
    if (method === "GET" && route === "/admin/merchants") {
      return { merchants: (Array.isArray(data) ? data : []).map(normalizeMerchant) };
    }
    if (route.endsWith("/payments")) {
      return { payments: raw?.payments ?? data?.payments ?? [] };
    }
    if (route.endsWith("/impersonate")) {
      return { token: raw?.token, message: raw?.message, merchant: normalizeMerchant(unwrap(raw?.merchant) ?? raw?.merchant) };
    }
    if (route.includes("/bot/")) {
      return raw;
    }
    const merchant = normalizeMerchant(unwrap(raw?.merchant) ?? unwrap(raw) ?? raw?.merchant);
    return merchant?.id ? { ...raw, merchant, message: raw?.message } : raw;
  }

  if (route === "/admin/whatsapp/embedded-signup/config") {
    const v = (a, b) => raw?.[a] ?? raw?.[b] ?? data?.[a] ?? data?.[b];
    return {
      enabled: !!(raw?.enabled ?? data?.enabled),
      appId: v("appId", "app_id") ?? null,
      configId: v("configId", "config_id") ?? null,
      graphVersion: v("graphVersion", "graph_version") ?? "v26.0",
    };
  }

  if (route === "/admin/onboarding" && method === "GET") {
    return { requests: unwrapList(raw?.requests ?? data?.requests ?? data) };
  }

  if (route === "/admin/platform-settings/whatsapp-access-token") {
    return { waToken: raw?.wa_access_token ?? data?.wa_access_token ?? null, message: raw?.message ?? data?.message };
  }

  if (route === "/admin/platform-settings") {
    return { settings: data };
  }

  if (method === "GET") {
    if (route === "/settings/profile") return { profile: raw?.profile ?? data?.profile ?? data };
    if (route === "/settings/hours") return { hours: raw?.hours ?? unwrapList(data) };
    if (route === "/settings/zones") return { zones: raw?.zones ?? unwrapList(data) };
    if (route === "/drivers") return { drivers: raw?.drivers ?? unwrapList(data) };
    if (route === "/categories") return { categories: Array.isArray(data) ? data : [] };
  }

  if (route.startsWith("/products") && method !== "DELETE") {
    const list = Array.isArray(data) ? data : data ? [data] : [];
    return { products: list.map(normalizeProduct) };
  }

  if (route === "/auth/login" && raw?.token) {
    const user = raw.user?.data ?? raw.user;
    const merchant = raw.merchant?.data ?? raw.merchant;
    return {
      token: raw.token,
      user: { ...user, shopName: merchant?.name, password_changed_at: user?.password_changed_at ?? null, roles: user?.roles ?? [] },
      merchant,
    };
  }

  return raw;
}

/**
 * Call the Laravel API: `path` is the `/api/v1` route (see backend/routes/api.php) and `body`
 * is sent as is. No demo fallback, failures surface as errors.
 */
export async function apiFetch(role, method, path, body) {
  const token = getToken(role);
  const opts = { method, headers: { Accept: "application/json" } };

  if (typeof FormData !== "undefined" && body instanceof FormData) {
    opts.body = body; // file upload: the browser sets the multipart boundary
  } else if (body !== null && body !== undefined) {
    opts.headers["Content-Type"] = "application/json";
    opts.body = JSON.stringify(body);
  }

  if (token) opts.headers.Authorization = `Bearer ${token}`;

  let r;
  try {
    r = await fetch(API + path, opts);
  } catch {
    throw new Error(`Cannot reach the API at ${API}. Is the backend running?`);
  }

  let data = {};
  const text = await r.text();
  if (text) {
    try {
      data = JSON.parse(text);
    } catch {
      throw new Error(`Invalid response from API (${r.status})`);
    }
  }

  if (!r.ok) {
    // Sign out only if this token is still the current one (a password change swaps it mid-flight).
    if (r.status === 401 && token && getToken(role) === token) {
      clearToken(role);
      if (typeof window !== "undefined") {
        window.dispatchEvent(new CustomEvent("nt:auth-expired", { detail: { role } }));
      }
    }
    throw new Error(apiErrorMessage(data, r.status));
  }

  const result = normalizeResponse(method, path, data);

  // Signing in: the account must belong to the portal it signs in to.
  const authKind = method === "POST" && path === "/auth/login" ? role : null;

  if (authKind === "owner") {
    const roles = result.user?.roles ?? [];
    if (!roles.includes(OWNER_ROLE)) {
      throw new Error("This account is not a platform administrator.");
    }
  }

  if (authKind === "merchant") {
    const roles = result.user?.roles ?? [];
    if (!roles.some((r) => MERCHANT_ROLES.includes(r))) {
      throw new Error("This account is not a merchant user.");
    }
  }

  if (method !== "GET" && !path.startsWith("/auth/")) announceChange();

  return result;
}

/** Public endpoints (no auth). */
export async function publicPost(path, body) {
  let r;
  try {
    r = await fetch(`${API}${path}`, {
      method: "POST",
      headers: { Accept: "application/json", "Content-Type": "application/json" },
      body: JSON.stringify(body),
    });
  } catch {
    throw new Error(`Cannot reach the API at ${API}. Is the backend running?`);
  }

  let data = {};
  const text = await r.text();
  if (text) {
    try {
      data = JSON.parse(text);
    } catch {
      throw new Error(`Invalid response from API (${r.status})`);
    }
  }

  if (!r.ok) {
    const msg = apiErrorMessage(data, r.status);
    if (r.status >= 500) {
      throw new Error("Something went wrong on our end. Please try again in a moment.");
    }
    throw new Error(msg);
  }

  return data;
}

/** Free-trial length set by the platform owner; null if the API can't be reached. */
export async function getTrialDays() {
  try {
    const r = await fetch(`${API}/plan`, { headers: { Accept: "application/json" } });
    return r.ok ? (await r.json()).trial_days : null;
  } catch {
    return null;
  }
}

/** Prices and trial length set by the platform owner, for the homepage; null if the API can't be reached. */
export async function getPlan() {
  try {
    const r = await fetch(`${API}/plan`, { headers: { Accept: "application/json" }, next: { revalidate: 60 } });
    return r.ok ? await r.json() : null;
  } catch {
    return null;
  }
}

export function submitOnboardingApplication(payload) {
  return publicPost("/onboarding/apply", payload);
}

/** "5 min ago" style label in the given Intl locale (see LOCALES in lib/i18n). */
export function timeAgo(dateStr, locale = "en-GB") {
  if (!dateStr) return "—";
  const diff = Date.now() - new Date(dateStr);
  const mins = Math.floor(diff / 60000);
  const hours = Math.floor(diff / 3600000);
  const days = Math.floor(diff / 86400000);
  const rtf = new Intl.RelativeTimeFormat(locale, { numeric: "auto", style: "short" });
  if (mins < 1) return rtf.format(0, "second");
  if (mins < 60) return rtf.format(-mins, "minute");
  if (hours < 24) return rtf.format(-hours, "hour");
  if (days < 7) return rtf.format(-days, "day");
  return new Date(dateStr).toLocaleDateString(locale, { month: "short", day: "numeric" });
}

export function getRenewalDate(m) {
  if (m.trial_ends_at && !m.last_payment_at && !m.subscription_ends) return new Date(m.trial_ends_at);
  if (m.subscription_ends) return new Date(m.subscription_ends);
  if (m.last_payment_at) {
    const d = new Date(m.last_payment_at);
    if (m.billing_cycle === "yearly") d.setFullYear(d.getFullYear() + 1);
    else d.setMonth(d.getMonth() + 1);
    return d;
  }
  return null;
}

export function merchantFee(m) {
  return m.billing_cycle === "yearly" ? (m.yearly_fee || 0) : (m.monthly_fee || 0);
}
