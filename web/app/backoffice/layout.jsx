"use client";

import { createContext, useContext, useEffect, useState } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { apiFetch, getToken, setToken, clearToken } from "@/lib/api";
import { useLang } from "@/lib/i18n";
import { useLiveRefresh } from "@/lib/live";
import AppShell from "@/components/AppShell";
import Brand from "@/components/Brand";
import { DashLangSwitch, DashProfile } from "@/components/DashSidebar";
import { AuthShell, AuthField, PasswordField, AuthAlert, AuthSubmit, ICONS, EMAIL_RE } from "@/components/auth/AuthShell";
import { company } from "@/lib/company";

const MerchantContext = createContext({});
export function useMerchant() {
  return useContext(MerchantContext);
}

const api = (method, path, body) => apiFetch("merchant", method, path, body);

const SECTIONS = new Set(["menu", "appointments", "drivers", "customers", "analytics", "settings"]);

const ICON = {
  home: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z" /></svg>,
  menu: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><rect x="4" y="3" width="16" height="18" rx="2.5" /><path d="M8 8h8M8 12h8M8 16h5" /></svg>,
  team: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="6" cy="17" r="2.5" /><circle cx="18" cy="17" r="2.5" /><path d="M8.5 17h7M15 6h2.5l2 8.5M15 6v4H9.5l-1 4.5" /></svg>,
  customers: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M23 21v-2a4 4 0 00-3-3.87" /><path d="M16 3.13a4 4 0 010 7.75" /></svg>,
  bookings: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><rect x="3" y="4" width="18" height="18" rx="2" /><line x1="16" y1="2" x2="16" y2="6" /><line x1="8" y1="2" x2="8" y2="6" /><line x1="3" y1="10" x2="21" y2="10" /></svg>,
  reports: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><line x1="18" y1="20" x2="18" y2="10" /><line x1="12" y1="20" x2="12" y2="4" /><line x1="6" y1="20" x2="6" y2="14" /></svg>,
  settings: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 0 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z" /></svg>,
};

/** Sidebar: Get started, the shop's menu (named for its type), team, customers, bookings (if any), reports, settings. */
function navItems(shopSlug, shop, t) {
  const base = shopSlug ? `/backoffice/${shopSlug}` : "/backoffice";
  const bookings = shop.business_mode === "appointments" || shop.business_mode === "both";
  return [
    { href: base, section: "", label: t("db.nav.home"), icon: ICON.home },
    { href: `${base}/menu`, section: "menu", label: t(`mk.title.${shop.menu_profile?.word || "menu"}`), icon: ICON.menu },
    { href: `${base}/drivers`, section: "drivers", label: t("db.nav.team"), icon: ICON.team },
    { href: `${base}/customers`, section: "customers", label: t("db.cust.title"), icon: ICON.customers },
    ...(bookings ? [{ href: `${base}/appointments`, section: "appointments", label: t("db.nav.bookings"), icon: ICON.bookings }] : []),
    { href: `${base}/analytics`, section: "analytics", label: t("db.nav.reports"), icon: ICON.reports },
    { href: `${base}/settings`, section: "settings", label: t("nav.settings"), icon: ICON.settings },
  ];
}

function parseBackofficePath(pathname) {
  const parts = (pathname || "").split("/").filter(Boolean);
  // ["backoffice"] | ["backoffice", shop] | ["backoffice", shop, section]
  if (parts[0] !== "backoffice") return { shopParam: null, section: "" };
  const second = parts[1] || null;
  if (!second) return { shopParam: null, section: "" };
  if (SECTIONS.has(second)) return { shopParam: null, section: second };
  return { shopParam: second, section: parts[2] || "" };
}

function forceMerchantLogout() {
  clearToken("merchant");
  localStorage.removeItem("nt_shop_name");
  localStorage.removeItem("nt_shop_slug");
  localStorage.removeItem("nt_pending_shop_name");
}

export default function BackofficeLayout({ children }) {
  const [authed, setAuthed] = useState(null); // null = loading, false = login, "force" = force pw change, true = ok
  const [shopName, setShopName] = useState("");
  const [shopSlug, setShopSlug] = useState("");
  const [mode, setMode] = useState("auto");
  const [shop, setShop] = useState({});
  const { t } = useLang();
  const pathname = usePathname();
  const router = useRouter();

  async function loadProfile() {
    try {
      const { profile } = await api("GET", "/settings/profile");
      if (profile) {
        setShop(profile);
        setShopName(profile.shop_name || "");
        if (profile.slug) {
          setShopSlug(profile.slug);
          localStorage.setItem("nt_shop_slug", profile.slug);
        }
        setMode(profile.mode || "auto");
        localStorage.setItem("nt_shop_name", profile.shop_name || "");
      }
    } catch { /* ignore */ }
  }

  useEffect(() => {
    function onExpired(e) {
      if (e.detail?.role !== "merchant") return;
      forceMerchantLogout();
      setAuthed(false);
    }
    window.addEventListener("nt:auth-expired", onExpired);
    return () => window.removeEventListener("nt:auth-expired", onExpired);
  }, []);

  useEffect(() => {
    async function bootstrap() {
      const token = getToken("merchant");
      if (!token) {
        setAuthed(false);
        return;
      }

      try {
        const { profile } = await api("GET", "/settings/profile");
        if (profile) setShop(profile);
        if (profile?.shop_name) {
          setShopName(profile.shop_name);
          localStorage.setItem("nt_shop_name", profile.shop_name);
        } else {
          setShopName(localStorage.getItem("nt_shop_name") || "My Shop");
        }
        const slug = profile?.slug || localStorage.getItem("nt_shop_slug") || "";
        if (slug) {
          setShopSlug(slug);
          localStorage.setItem("nt_shop_slug", slug);
        }
        if (profile?.mode) setMode(profile.mode);
        setAuthed(true);
      } catch {
        forceMerchantLogout();
        setAuthed(false);
      }
    }

    bootstrap();
  }, []);

  // Shop name, mode (auto/manual)… follow changes made elsewhere, e.g. from the owner portal.
  useLiveRefresh(loadProfile, { enabled: authed === true });

  // Keep session honest while the tab stays open (e.g. after Resend Access).
  useEffect(() => {
    if (authed !== true) return undefined;
    const id = setInterval(async () => {
      try {
        await api("GET", "/auth/me");
      } catch {
        /* 401 handler clears session via nt:auth-expired */
      }
    }, 15000);
    return () => clearInterval(id);
  }, [authed]);

  // Canonical shop URL: /backoffice/{slug}/...
  useEffect(() => {
    if (authed !== true || !shopSlug) return;
    const { shopParam, section } = parseBackofficePath(pathname);
    const target = section
      ? `/backoffice/${shopSlug}/${section}`
      : `/backoffice/${shopSlug}`;
    if (shopParam !== shopSlug) {
      router.replace(target);
    }
  }, [authed, shopSlug, pathname, router]);

  async function changeMode(newMode) {
    setMode(newMode);
    try { await api("PATCH", "/settings/mode", { mode: newMode }); } catch { /* ignore */ }
  }

  function logout() {
    api("POST", "/auth/logout").catch(() => {});
    forceMerchantLogout();
    setAuthed(false);
    router.replace("/backoffice");
  }

  if (authed === null) return <div className="loader">{t("db.loading")}</div>;
  if (authed === false) {
    return (
      <LoginScreen
        t={t}
        onLoggedIn={(user) => {
          if (user?.forcePasswordChange) {
            localStorage.setItem("nt_pending_shop_name", user?.shopName || "");
            if (user?.shopSlug) localStorage.setItem("nt_shop_slug", user.shopSlug);
            setAuthed("force");
          } else {
            setShopName(user?.shopName || "My Shop");
            setShopSlug(user?.shopSlug || "");
            localStorage.setItem("nt_shop_name", user?.shopName || "My Shop");
            if (user?.shopSlug) localStorage.setItem("nt_shop_slug", user.shopSlug);
            setAuthed(true);
            loadProfile();
            if (user?.shopSlug) router.replace(`/backoffice/${user.shopSlug}`);
          }
        }}
      />
    );
  }
  if (authed === "force") {
    return (
      <ForcePasswordScreen
        onDone={() => {
          const name = localStorage.getItem("nt_pending_shop_name") || "";
          const slug = localStorage.getItem("nt_shop_slug") || "";
          localStorage.removeItem("nt_pending_shop_name");
          setShopName(name);
          setShopSlug(slug);
          setAuthed(true);
          loadProfile();
          if (slug) router.replace(`/backoffice/${slug}`);
        }}
      />
    );
  }

  const items = navItems(shopSlug, shop, t);
  const { section } = parseBackofficePath(pathname);

  return (
    <MerchantContext.Provider value={{ api, shop, shopName, shopSlug, refreshProfile: loadProfile }}>
      <AppShell brand={shopName || "NeoTalab"} className="dash"
        topbarExtra={(
          <button type="button" role="switch" aria-checked={mode !== "manual"} aria-label={t("mode.toggle")}
            className={`bot-chip ${mode !== "manual" ? "on" : "off"}`} onClick={() => changeMode(mode === "manual" ? "auto" : "manual")}>
            <span className="bot-switch-dot" aria-hidden="true" />{mode !== "manual" ? t("mode.chipOn") : t("mode.chipOff")}
          </button>
        )}
        sidebar={(
        <>
          <div className="dash-side-head" dir="ltr">
            <Brand size={30} />
          </div>

          <BotSwitch on={mode !== "manual"} t={t} onChange={(on) => changeMode(on ? "auto" : "manual")} />

          <nav aria-label={shopName || "NeoTalab"}>
            {items.map((item) => {
              const active = item.section === section;
              return (
                <Link key={item.href} href={item.href} className={`nav-item ${active ? "active" : ""}`} aria-current={active ? "page" : undefined}>
                  {item.icon}
                  <span>{item.label}</span>
                </Link>
              );
            })}
          </nav>
          <div className="sidebar-spacer" />
          <div className="dash-side-foot">
            <DashLangSwitch />
            <DashProfile name={shopName || "NeoTalab"} role={t("db.shopOwner")} onLogout={logout} />
          </div>
        </>
      )}>
        <div className="dash-page">{children}</div>
      </AppShell>
    </MerchantContext.Provider>
  );
}

const SUPPORT_WA = company.whatsapp ? `https://wa.me/${company.whatsapp.replace(/\D/g, "")}` : "";

/** Big, clear on/off switch for the WhatsApp bot (auto = on, manual = the merchant replies). */
function BotSwitch({ on, onChange, t }) {
  return (
    <button type="button" role="switch" aria-checked={on} aria-label={t("mode.toggle")}
      className={`bot-switch ${on ? "on" : "off"}`} onClick={() => onChange(!on)}>
      <span className="bot-switch-dot" aria-hidden="true" />
      <span className="bot-switch-text">
        <strong>{on ? t("mode.on") : t("mode.off")}</strong>
        <span>{on ? t("mode.onSub") : t("mode.offSub")}</span>
      </span>
      <span className="bot-switch-track" aria-hidden="true"><span /></span>
    </button>
  );
}

function LoginScreen({ t, onLoggedIn }) {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [errors, setErrors] = useState({});
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  async function submit(e) {
    e.preventDefault();
    const next = {};
    const mail = email.trim();
    if (!mail) next.email = t("auth.needEmail");
    else if (!EMAIL_RE.test(mail)) next.email = t("auth.badEmail");
    if (!password) next.password = t("auth.needPassword");
    setErrors(next);
    setError("");
    if (Object.keys(next).length) {
      document.getElementById(next.email ? "bo-email" : "bo-pass")?.focus();
      return;
    }
    setBusy(true);
    try {
      const data = await api("POST", "/auth/login", { email: mail, password: password.trim() });
      setToken("merchant", data.token);
      onLoggedIn({
        shopName: data.merchant?.name ?? data.user?.shopName,
        shopSlug: data.merchant?.slug || "",
        forcePasswordChange: data.user?.password_changed_at == null,
      });
    } catch (err) {
      setError(err.message);
      setBusy(false);
    }
  }

  return (
    <AuthShell
      title={t("auth.welcome")}
      subtitle={t("auth.welcomeSub")}
      below={(
        <p className="auth-below">
          {t("auth.new")} <Link href="/join">{t("auth.apply")}</Link>
        </p>
      )}
    >
      <form onSubmit={submit} noValidate>
        <AuthAlert>{error}</AuthAlert>
        <AuthField
          id="bo-email" label={t("login.email")} icon={ICONS.mail} error={errors.email}
          type="email" inputMode="email" autoComplete="username" autoCapitalize="none" spellCheck={false} autoFocus
          value={email} placeholder={t("auth.emailPh")}
          onChange={(e) => { setEmail(e.target.value); if (errors.email) setErrors({ ...errors, email: "" }); }}
        />
        <PasswordField
          id="bo-pass" label={t("login.password")} error={errors.password} hint={t("auth.codeHint")}
          autoComplete="current-password" value={password} placeholder={t("auth.passwordPh")}
          onChange={(e) => { setPassword(e.target.value); if (errors.password) setErrors({ ...errors, password: "" }); }}
        />
        <AuthSubmit busy={busy} busyLabel={t("db.login.signingIn")}>{t("login.btn")}</AuthSubmit>
      </form>
      {SUPPORT_WA && (
        <div className="auth-help">
          <span>{t("auth.forgot")}</span>
          <a href={SUPPORT_WA} target="_blank" rel="noopener noreferrer">{ICONS.whatsapp}{t("auth.forgotLink")}</a>
        </div>
      )}
    </AuthShell>
  );
}

function ForcePasswordScreen({ onDone }) {
  const { t } = useLang();
  const [newPass, setNewPass] = useState("");
  const [confirm, setConfirm] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const longEnough = newPass.length >= 8;
  const matches = confirm.length > 0 && newPass === confirm;

  async function submit(e) {
    e.preventDefault();
    setError("");
    if (!longEnough) { setError(t("db.pw.tooShort")); return; }
    if (!matches) { setError(t("db.pw.mismatch")); return; }
    setBusy(true);
    try {
      await api("POST", "/auth/set-initial-password", { password: newPass });
      onDone();
    } catch (err) {
      setError(err.message);
      setBusy(false);
    }
  }

  return (
    <AuthShell title={t("db.pw.title")} subtitle={t("db.pw.sub")}>
      <form onSubmit={submit} noValidate>
        <AuthAlert>{error}</AuthAlert>
        <PasswordField
          id="pw-new" label={t("db.pw.new")} autoComplete="new-password" autoFocus
          value={newPass} placeholder={t("db.pw.min")} onChange={(e) => setNewPass(e.target.value)}
        />
        <PasswordField
          id="pw-confirm" label={t("db.pw.confirm")} autoComplete="new-password"
          value={confirm} placeholder={t("db.pw.repeat")} onChange={(e) => setConfirm(e.target.value)}
        />
        <ul className="auth-rules">
          <li className={longEnough ? "ok" : ""}>{longEnough ? ICONS.check : ICONS.dot}{t("auth.ruleLen")}</li>
          <li className={matches ? "ok" : ""}>{matches ? ICONS.check : ICONS.dot}{t("auth.ruleMatch")}</li>
        </ul>
        <AuthSubmit busy={busy} busyLabel={t("db.pw.saving")}>{t("db.pw.save")}</AuthSubmit>
      </form>
    </AuthShell>
  );
}
