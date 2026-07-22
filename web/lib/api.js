export const API = process.env.NEXT_PUBLIC_API_URL || "http://127.0.0.1:8001/api/v1";

const TOKEN_KEYS = { owner: "nt_owner_token", merchant: "nt_merchant_token" };

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

function mapOwnerCreateBody(body = {}) {
  return {
    shopName: body.shopName ?? body.shop_name ?? body.name,
    whatsappNumber: body.whatsappNumber ?? body.whatsapp_number ?? body.wa,
    password: body.password ?? body.pass,
    businessType: body.businessType ?? body.business_type ?? body.btype,
    street: body.street,
    city: body.city,
    region: body.region,
    subscriptionStatus: body.subscriptionStatus ?? body.subscription_status,
    trialEndsAt: body.trialEndsAt ?? body.trial_ends_at,
    forcePasswordChange: body.forcePasswordChange ?? body.force_password_change,
  };
}

function mapOwnerMerchantPatch(body = {}) {
  const mapped = {};
  if (body.status !== undefined) mapped.status = body.status;
  if (body.plan !== undefined) mapped.plan = body.plan;
  if (body.billingCycle !== undefined) mapped.billingCycle = body.billingCycle;
  if (body.billing_cycle !== undefined) mapped.billing_cycle = body.billing_cycle;
  if (body.monthlyFee !== undefined) mapped.monthlyFee = body.monthlyFee;
  if (body.monthly_fee !== undefined) mapped.monthly_fee = body.monthly_fee;
  if (body.yearlyFee !== undefined) mapped.yearlyFee = body.yearlyFee;
  if (body.yearly_fee !== undefined) mapped.yearly_fee = body.yearly_fee;
  if (body.subscriptionStatus !== undefined) mapped.subscriptionStatus = body.subscriptionStatus;
  if (body.subscription_status !== undefined) mapped.subscription_status = body.subscription_status;
  if (body.lastPaymentAt !== undefined) mapped.lastPaymentAt = body.lastPaymentAt;
  if (body.last_payment_at !== undefined) mapped.last_payment_at = body.last_payment_at;
  if (body.trialEndsAt !== undefined) mapped.trialEndsAt = body.trialEndsAt;
  if (body.trial_ends_at !== undefined) mapped.trial_ends_at = body.trial_ends_at;
  return mapped;
}

function mapOwnerBotPatch(body = {}) {
  return {
    phoneNumberId: body.phoneNumberId ?? body.phone_number_id,
    accessToken: body.accessToken ?? body.access_token,
    tokenExpires: body.tokenExpires ?? body.token_expires,
  };
}

function mapSettingsProfileBody(body = {}) {
  const mapped = {};
  if (body.shopName !== undefined) mapped.shopName = body.shopName;
  if (body.shop_name !== undefined) mapped.shop_name = body.shop_name;
  if (body.shopNameAr !== undefined) mapped.shopNameAr = body.shopNameAr;
  if (body.shop_name_ar !== undefined) mapped.shop_name_ar = body.shop_name_ar;
  if (body.opsWhatsapp !== undefined) mapped.opsWhatsapp = body.opsWhatsapp;
  if (body.ops_whatsapp !== undefined) mapped.ops_whatsapp = body.ops_whatsapp;
  if (body.defaultLocale !== undefined) mapped.defaultLocale = body.defaultLocale;
  if (body.default_locale !== undefined) mapped.default_locale = body.default_locale;
  if (body.wishNumber !== undefined) mapped.wishNumber = body.wishNumber;
  if (body.wish_number !== undefined) mapped.wish_number = body.wish_number;
  if (body.wishAutoConfirm !== undefined) mapped.wishAutoConfirm = body.wishAutoConfirm;
  if (body.wish_auto_confirm !== undefined) mapped.wish_auto_confirm = body.wish_auto_confirm;
  if (body.wishTimeoutMins !== undefined) mapped.wishTimeoutMins = body.wishTimeoutMins;
  if (body.wish_timeout_mins !== undefined) mapped.wish_timeout_mins = body.wish_timeout_mins;
  return mapped;
}

function mapZoneBody(body = {}) {
  return {
    name: body.name,
    deliveryFee: body.deliveryFee ?? body.delivery_fee,
    minimumOrder: body.minimumOrder ?? body.minimum_order,
  };
}

function mapDriverBody(body = {}) {
  return {
    name: body.name,
    phone: body.phone,
    whatsappNumber: body.whatsappNumber ?? body.whatsapp_number,
    password: body.password,
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

/** Map legacy dashboard paths to Laravel `/api/v1` routes (where implemented). */
function mapRequest(method, path, body) {
  if (method === "POST" && (path === "/auth/owner/login" || path === "/auth/merchant/login")) {
    return {
      method: "POST",
      path: "/auth/login",
      body: {
        email: body?.email ?? body?.whatsappNumber ?? body?.whatsapp,
        password: body?.password,
      },
      authKind: path.includes("owner") ? "owner" : "merchant",
    };
  }

  if (method === "POST" && path === "/auth/logout") {
    return { method: "POST", path: "/auth/logout", body: null };
  }

  if (method === "GET" && path === "/auth/me") {
    return { method: "GET", path: "/auth/me" };
  }

  if (method === "GET" && path.startsWith("/api/owner/onboarding")) {
    return { method: "GET", path: "/admin/onboarding" };
  }

  const onboardingApprove = path.match(/^\/api\/owner\/onboarding\/([^/]+)\/approve$/);
  if (method === "POST" && onboardingApprove) {
    return { method: "POST", path: `/admin/onboarding/${onboardingApprove[1]}/approve`, body };
  }

  const onboardingReject = path.match(/^\/api\/owner\/onboarding\/([^/]+)$/);
  if (method === "DELETE" && onboardingReject) {
    return { method: "DELETE", path: `/admin/onboarding/${onboardingReject[1]}`, body: null };
  }

  if (method === "GET" && path === "/api/settings/profile") {
    return { method: "GET", path: "/settings/profile" };
  }

  if (method === "PATCH" && path === "/api/settings/profile") {
    return { method: "PATCH", path: "/settings/profile", body: mapSettingsProfileBody(body) };
  }

  if (method === "PATCH" && path === "/api/settings/mode") {
    return { method: "PATCH", path: "/settings/mode", body };
  }

  if (method === "GET" && path === "/api/settings/hours") {
    return { method: "GET", path: "/settings/hours" };
  }

  if (method === "PUT" && path === "/api/settings/hours") {
    return { method: "PUT", path: "/settings/hours", body };
  }

  if (method === "GET" && path === "/api/settings/zones") {
    return { method: "GET", path: "/settings/zones" };
  }

  if (method === "POST" && path === "/api/settings/zones") {
    return { method: "POST", path: "/settings/zones", body: mapZoneBody(body) };
  }

  const zoneDelete = path.match(/^\/api\/settings\/zones\/([^/]+)$/);
  if (method === "DELETE" && zoneDelete) {
    return { method: "DELETE", path: `/settings/zones/${zoneDelete[1]}`, body: null };
  }

  if (method === "PATCH" && path === "/api/merchant/change-password") {
    return { method: "POST", path: "/auth/set-initial-password", body: { password: body?.newPassword } };
  }

  if (method === "GET" && path === "/api/orders/live") {
    return { method: "GET", path: "/orders/live" };
  }

  if (method === "GET" && path.startsWith("/api/orders")) {
    return { method: "GET", path: path.replace("/api/orders", "/orders") };
  }

  const orderAction = path.match(/^\/api\/orders\/([^/]+)\/(accept|reject|cancel|preparing|ready)$/);
  if (method === "POST" && orderAction) {
    return { method: "POST", path: `/orders/${orderAction[1]}/${orderAction[2]}`, body };
  }

  if (method === "GET" && path.startsWith("/api/customers")) {
    return { method: "GET", path: path.replace("/api/customers", "/customers") };
  }

  const customerAction = path.match(/^\/api\/customers\/([^/]+)\/(block|unblock)$/);
  if (method === "POST" && customerAction) {
    return { method: "POST", path: `/customers/${customerAction[1]}/${customerAction[2]}`, body };
  }

  if (method === "GET" && path === "/api/drivers") {
    return { method: "GET", path: "/drivers" };
  }

  if (method === "POST" && path === "/api/drivers") {
    return { method: "POST", path: "/drivers", body: mapDriverBody(body) };
  }

  const driverStatus = path.match(/^\/api\/drivers\/([^/]+)\/status$/);
  if (method === "POST" && driverStatus) {
    return { method: "POST", path: `/drivers/${driverStatus[1]}/status`, body };
  }

  const driverDelete = path.match(/^\/api\/drivers\/([^/]+)$/);
  if (method === "DELETE" && driverDelete) {
    return { method: "DELETE", path: `/drivers/${driverDelete[1]}`, body: null };
  }

  if (method === "GET" && path.startsWith("/api/analytics/")) {
    return { method: "GET", path: path.replace("/api/analytics", "/analytics") };
  }

  if (method === "POST" && path === "/api/owner/broadcast") {
    return { method: "POST", path: "/admin/broadcast", body: { message: body?.message } };
  }

  if (method === "POST" && path === "/api/owner/merchants") {
    return { method: "POST", path: "/admin/merchants", body: mapOwnerCreateBody(body) };
  }

  const merchantPayments = path.match(/^\/api\/owner\/merchants\/([^/]+)\/payments$/);
  if (method === "GET" && merchantPayments) {
    return { method: "GET", path: `/admin/merchants/${merchantPayments[1]}/payments` };
  }

  const merchantImpersonate = path.match(/^\/api\/owner\/merchants\/([^/]+)\/impersonate$/);
  if (method === "POST" && merchantImpersonate) {
    return { method: "POST", path: `/admin/merchants/${merchantImpersonate[1]}/impersonate`, body: null };
  }

  const embeddedSignupConfig = path === "/api/owner/whatsapp/embedded-signup/config";
  if (method === "GET" && embeddedSignupConfig) {
    return { method: "GET", path: "/admin/whatsapp/embedded-signup/config" };
  }

  const embeddedSignupConnect = path.match(/^\/api\/owner\/merchants\/([^/]+)\/whatsapp\/embedded-signup$/);
  if (method === "POST" && embeddedSignupConnect) {
    return {
      method: "POST",
      path: `/admin/merchants/${embeddedSignupConnect[1]}/whatsapp/embedded-signup`,
      body: {
        code: body.code,
        phoneNumberId: body.phoneNumberId || body.phone_number_id,
        wabaId: body.wabaId || body.waba_id,
      },
    };
  }

  const merchantBotAction = path.match(/^\/api\/owner\/merchants\/([^/]+)\/bot\/(test|restart|register-webhook)$/);
  if (method === "POST" && merchantBotAction) {
    return { method: "POST", path: `/admin/merchants/${merchantBotAction[1]}/bot/${merchantBotAction[2]}`, body: null };
  }

  const merchantBotPatch = path.match(/^\/api\/owner\/merchants\/([^/]+)\/bot$/);
  if (method === "PATCH" && merchantBotPatch) {
    return {
      method: "PATCH",
      path: `/admin/merchants/${merchantBotPatch[1]}/bot`,
      body: mapOwnerBotPatch(body),
    };
  }

  const merchantWelcome = path.match(/^\/api\/owner\/merchants\/([^/]+)\/welcome$/);
  if (method === "POST" && merchantWelcome) {
    return {
      method: "POST",
      path: `/admin/merchants/${merchantWelcome[1]}/welcome`,
      body: {
        otp: body?.otp,
        loginEmail: body?.loginEmail || body?.login_email,
        regenerateAccess: body?.regenerateAccess ?? body?.regenerate_access ?? false,
      },
    };
  }

  const merchantOne = path.match(/^\/api\/owner\/merchants\/([^/]+)$/);
  if (method === "PATCH" && merchantOne) {
    return {
      method: "PATCH",
      path: `/admin/merchants/${merchantOne[1]}`,
      body: mapOwnerMerchantPatch(body),
    };
  }

  if (method === "DELETE" && merchantOne) {
    return { method: "DELETE", path: `/admin/merchants/${merchantOne[1]}`, body: null };
  }

  if (method === "GET" && path.startsWith("/api/owner/merchants")) {
    return { method: "GET", path: "/admin/merchants" };
  }

  if (method === "GET" && path.startsWith("/api/menu/categories")) {
    return { method: "GET", path: "/categories" };
  }

  if (method === "POST" && path === "/api/menu/categories") {
    return {
      method: "POST",
      path: "/categories",
      body: { name: body.name, name_ar: body.nameAr || body.name_ar },
    };
  }

  const catPatch = path.match(/^\/api\/menu\/categories\/([^/]+)$/);
  if (method === "PATCH" && catPatch) {
    return {
      method: "PATCH",
      path: `/categories/${catPatch[1]}`,
      body: { name: body.name, name_ar: body.nameAr ?? body.name_ar },
    };
  }

  const catDelete = path.match(/^\/api\/menu\/categories\/([^/]+)$/);
  if (method === "DELETE" && catDelete) {
    return { method: "DELETE", path: `/categories/${catDelete[1]}` };
  }

  if (method === "GET" && path.startsWith("/api/menu/products")) {
    const inactive = path.includes("includeInactive");
    return {
      method: "GET",
      path: inactive ? "/products?per_page=100" : "/products?per_page=100&is_active=1",
    };
  }

  if (method === "POST" && path === "/api/menu/products") {
    return {
      method: "POST",
      path: "/products",
      body: {
        name: body.name,
        name_ar: body.nameAr ?? body.name_ar,
        description: body.description,
        price: body.price,
        category_id: body.categoryId ?? body.category_id,
        prep_time_mins: body.prepTimeMins ?? body.prep_time_mins,
      },
    };
  }

  const prodPatch = path.match(/^\/api\/menu\/products\/([^/]+)$/);
  if (method === "PATCH" && prodPatch) {
    const mapped = {};
    if (body.name !== undefined) mapped.name = body.name;
    if (body.nameAr !== undefined) mapped.name_ar = body.nameAr;
    if (body.name_ar !== undefined) mapped.name_ar = body.name_ar;
    if (body.description !== undefined) mapped.description = body.description || null;
    if (body.categoryId !== undefined) mapped.category_id = body.categoryId || null;
    if (body.category_id !== undefined) mapped.category_id = body.category_id || null;
    if (body.price !== undefined) mapped.price = body.price;
    if (body.prepTimeMins !== undefined) mapped.prep_time_mins = body.prepTimeMins;
    if (body.isActive !== undefined) mapped.is_active = body.isActive;
    if (body.isSoldOut !== undefined) mapped.is_available = !body.isSoldOut;
    return { method: "PATCH", path: `/products/${prodPatch[1]}`, body: mapped };
  }

  const prodDelete = path.match(/^\/api\/menu\/products\/([^/]+)$/);
  if (method === "DELETE" && prodDelete) {
    return { method: "DELETE", path: `/products/${prodDelete[1]}` };
  }

  if (method === "GET" && path === "/api/owner/settings") {
    return { method: "GET", path: "/admin/platform-settings" };
  }

  if (method === "GET" && path === "/api/owner/settings/whatsapp-token") {
    return { method: "GET", path: "/admin/platform-settings/whatsapp-access-token" };
  }

  if (method === "PATCH" && path === "/api/owner/settings") {
    return { method: "PATCH", path: "/admin/platform-settings", body: mapOwnerSettingsBody(body) };
  }

  if (method === "PATCH" && path === "/api/owner/password") {
    return {
      method: "PATCH",
      path: "/auth/password",
      body: {
        current_password: body.currentPassword,
        password: body.newPassword,
        password_confirmation: body.newPassword,
      },
    };
  }

  if (method === "POST" && path === "/api/owner/sessions/revoke") {
    return { method: "POST", path: "/auth/revoke-other-sessions", body: null };
  }

  return null;
}

function mapOwnerSettingsBody(body = {}) {
  const mapped = {};
  if (body.platformName !== undefined) mapped.platform_name = body.platformName;
  if (body.currency !== undefined) mapped.currency = body.currency;
  if (body.timezone !== undefined) mapped.timezone = body.timezone;
  if (body.subscriptionPrice !== undefined) mapped.subscription_price = body.subscriptionPrice;
  if (body.subscriptionYearlyPrice !== undefined) mapped.subscription_yearly_price = body.subscriptionYearlyPrice;
  if (body.trialDays !== undefined) mapped.trial_days = body.trialDays;
  if (body.gracePeriodDays !== undefined) mapped.grace_period_days = body.gracePeriodDays;
  if (body.waPhoneId !== undefined) mapped.wa_phone_id = body.waPhoneId;
  if (body.waToken !== undefined) mapped.wa_access_token = body.waToken;
  if (body.waVerifyToken !== undefined) mapped.wa_verify_token = body.waVerifyToken;
  if (body.overdueDays !== undefined) mapped.overdue_remind_days = body.overdueDays;
  if (body.overdueReminderMsg !== undefined) mapped.overdue_reminder_msg = body.overdueReminderMsg;
  return mapped;
}

function normalizeResponse(method, originalPath, raw) {
  const data = unwrap(raw);

  if (originalPath.startsWith("/api/owner/merchants") || originalPath.startsWith("/admin/merchants")) {
    if (method === "GET" && !originalPath.match(/\/merchants\/[^/?]+(\/|$)/)) {
      const list = Array.isArray(data) ? data : [];
      return { merchants: list.map(normalizeMerchant) };
    }

    if (originalPath.includes("/payments")) {
      return { payments: raw?.payments ?? data?.payments ?? [] };
    }

    if (method === "POST" && originalPath.includes("/impersonate")) {
      return {
        token: raw?.token,
        message: raw?.message,
        merchant: normalizeMerchant(unwrap(raw?.merchant) ?? raw?.merchant),
      };
    }

    if (method === "POST" && originalPath.includes("/bot/")) {
      return raw;
    }

    if (method === "PATCH" && originalPath.includes("/bot")) {
      const merchant = normalizeMerchant(unwrap(raw?.merchant) ?? unwrap(raw) ?? raw?.merchant);
      return { merchant, message: raw?.message };
    }

    if (method === "PATCH" || method === "POST" || method === "DELETE") {
      const merchant = normalizeMerchant(unwrap(raw?.merchant) ?? unwrap(raw) ?? raw?.merchant);
      if (merchant?.id) return { merchant, message: raw?.message };
      return raw;
    }
  }

  if (originalPath === "/api/owner/broadcast") {
    return raw;
  }

  if (originalPath.startsWith("/api/owner/onboarding") || originalPath.startsWith("/admin/onboarding")) {
    const list = unwrapList(raw?.requests ?? data?.requests ?? data);
    return { requests: list };
  }

  if (originalPath === "/api/owner/settings/whatsapp-token") {
    return {
      waToken: raw?.wa_access_token ?? data?.wa_access_token ?? null,
      message: raw?.message ?? data?.message,
    };
  }

  if (originalPath.startsWith("/api/owner/settings")) {
    return { settings: data };
  }

  if (originalPath === "/api/owner/password" || originalPath === "/api/owner/sessions/revoke" || originalPath === "/api/merchant/change-password") {
    return raw;
  }

  if (originalPath.includes("/api/settings/profile")) {
    return { profile: raw?.profile ?? data?.profile ?? data };
  }

  if (originalPath.includes("/api/settings/hours")) {
    return { hours: raw?.hours ?? unwrapList(data) };
  }

  if (originalPath.includes("/api/settings/zones")) {
    if (method === "DELETE") return raw;
    if (method === "POST") return raw;
    return { zones: raw?.zones ?? unwrapList(data) };
  }

  if (originalPath.includes("/api/settings/mode")) {
    return raw;
  }

  if (originalPath.includes("/api/orders")) {
    return { orders: raw?.orders ?? [] };
  }

  if (originalPath.includes("/api/customers")) {
    return { customers: raw?.customers ?? unwrapList(data) };
  }

  if (originalPath.includes("/api/drivers")) {
    if (method === "DELETE") return raw;
    return { drivers: raw?.drivers ?? unwrapList(data) };
  }

  if (originalPath.includes("/api/analytics/")) {
    return raw;
  }

  if (originalPath.includes("/api/menu/categories") || (method !== "DELETE" && String(originalPath).includes("/categories"))) {
    if (method === "DELETE") return raw;
    const list = Array.isArray(data) ? data : data ? [data] : [];
    return { categories: list };
  }

  if (originalPath.includes("/api/menu/products") || String(originalPath).includes("/products")) {
    if (method === "DELETE") return raw;
    const list = Array.isArray(data) ? data : data ? [data] : [];
    return { products: list.map(normalizeProduct) };
  }

  if (originalPath.includes("/auth/") && raw?.token) {
    const user = raw.user?.data ?? raw.user;
    const merchant = raw.merchant?.data ?? raw.merchant;
    return {
      token: raw.token,
      user: {
        ...user,
        shopName: merchant?.name,
        password_changed_at: user?.password_changed_at ?? null,
        roles: user?.roles ?? [],
      },
      merchant,
    };
  }

  return raw;
}

/**
 * Call the Laravel API. No demo fallback — failures surface as errors.
 */
export async function apiFetch(role, method, path, body) {
  const mapped = mapRequest(method, path, body);
  if (!mapped) {
    throw new Error(`"${path}" is not wired to the API yet.`);
  }

  const token = getToken(role);
  const opts = { method: mapped.method, headers: { Accept: "application/json" } };

  if (mapped.body !== null && mapped.body !== undefined) {
    opts.headers["Content-Type"] = "application/json";
    opts.body = JSON.stringify(mapped.body);
  }

  if (token) opts.headers.Authorization = `Bearer ${token}`;

  let r;
  try {
    r = await fetch(API + mapped.path, opts);
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
    if (r.status === 401 && token) {
      clearToken(role);
      if (typeof window !== "undefined") {
        window.dispatchEvent(new CustomEvent("nt:auth-expired", { detail: { role } }));
      }
    }
    throw new Error(apiErrorMessage(data, r.status));
  }

  const result = normalizeResponse(method, path, data);

  if (mapped.authKind === "owner") {
    const roles = result.user?.roles ?? [];
    if (!roles.includes(OWNER_ROLE)) {
      throw new Error("This account is not a platform administrator.");
    }
  }

  if (mapped.authKind === "merchant") {
    const roles = result.user?.roles ?? [];
    if (!roles.some((r) => MERCHANT_ROLES.includes(r))) {
      throw new Error("This account is not a merchant user.");
    }
  }

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

export function submitOnboardingApplication(payload) {
  return publicPost("/onboarding/apply", payload);
}

export function timeAgo(dateStr) {
  if (!dateStr) return "—";
  const diff = Date.now() - new Date(dateStr);
  const mins = Math.floor(diff / 60000);
  const hours = Math.floor(diff / 3600000);
  const days = Math.floor(diff / 86400000);
  if (mins < 2) return "Just now";
  if (mins < 60) return `${mins}m ago`;
  if (hours < 24) return `${hours}h ago`;
  if (days === 1) return "Yesterday";
  if (days < 7) return `${days}d ago`;
  return new Date(dateStr).toLocaleDateString("en-US", { month: "short", day: "numeric" });
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
