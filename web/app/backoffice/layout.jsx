"use client";

import { createContext, useContext, useEffect, useState } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { apiFetch, getToken, setToken, clearToken } from "@/lib/api";
import { useLang } from "@/lib/i18n";
import { useToast } from "@/components/ui";

const MerchantContext = createContext({});
export function useMerchant() {
  return useContext(MerchantContext);
}

const api = (method, path, body) => apiFetch("merchant", method, path, body);

const SECTIONS = new Set(["menu", "drivers", "customers", "analytics", "settings"]);

function navItems(shopSlug) {
  const base = shopSlug ? `/backoffice/${shopSlug}` : "/backoffice";
  return [
    { href: base, key: "nav.orders", icon: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg> },
    { href: `${base}/menu`, key: "nav.menu", icon: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M12 2L2 7l10 5 10-5-10-5z" /><path d="M2 17l10 5 10-5" /><path d="M2 12l10 5 10-5" /></svg> },
    { href: `${base}/drivers`, key: "nav.drivers", icon: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="12" cy="12" r="10" /><path d="M12 8v4l3 3" /></svg> },
    { href: `${base}/customers`, key: "nav.customers", icon: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M23 21v-2a4 4 0 00-3-3.87" /><path d="M16 3.13a4 4 0 010 7.75" /></svg> },
    { href: `${base}/analytics`, key: "nav.analytics", icon: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><line x1="18" y1="20" x2="18" y2="10" /><line x1="12" y1="20" x2="12" y2="4" /><line x1="6" y1="20" x2="6" y2="14" /></svg> },
    { href: `${base}/settings`, key: "nav.settings", icon: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="12" cy="12" r="3" /><path d="M19.07 4.93a10 10 0 010 14.14M16.24 7.76a6 6 0 010 8.49M4.93 4.93a10 10 0 000 14.14M7.76 7.76a6 6 0 000 8.49" /></svg> },
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
  const { t, lang, setLang } = useLang();
  const toast = useToast();
  const pathname = usePathname();
  const router = useRouter();

  async function loadProfile() {
    try {
      const { profile } = await api("GET", "/api/settings/profile");
      if (profile) {
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
        const { profile } = await api("GET", "/api/settings/profile");
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
    try { await api("PATCH", "/api/settings/mode", { mode: newMode }); } catch { /* ignore */ }
  }

  function logout() {
    api("POST", "/auth/logout").catch(() => {});
    forceMerchantLogout();
    setAuthed(false);
    router.replace("/backoffice");
  }

  if (authed === null) return <div className="loader">Loading…</div>;
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
        toast={toast}
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

  const items = navItems(shopSlug);
  const { section } = parseBackofficePath(pathname);

  return (
    <MerchantContext.Provider value={{ api, shopName, shopSlug, refreshProfile: loadProfile }}>
      <div className="app-shell">
        <aside className="sidebar">
          <div className="sidebar-top">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src="/Logo1.png" alt="NeoTalab" style={{ width: "100%", height: "auto" }} />
            <div className="mode-selector">
              <span className="mode-label">{t("mode.label")}</span>
              <select className="mode-select" value={mode} onChange={(e) => changeMode(e.target.value)}>
                <option value="auto">{t("mode.auto")}</option>
                <option value="manual">{t("mode.manual")}</option>
              </select>
            </div>
          </div>
          <nav>
            {items.map((item) => {
              const itemSection = item.href.split("/").slice(3).join("/") || "";
              const active = itemSection === section;
              return (
                <Link key={item.href} href={item.href}
                  className={`nav-item ${active ? "active" : ""}`}>
                  {item.icon}
                  <span>{t(item.key)}</span>
                </Link>
              );
            })}
          </nav>
          <div className="sidebar-footer">
            <div className="lang-switcher">
              {["en", "ar", "fr"].map((code) => (
                <button key={code} className={`lang-btn ${lang === code ? "active" : ""}`} onClick={() => setLang(code)}>
                  {code.toUpperCase()}
                </button>
              ))}
            </div>
            <button className="btn-logout" onClick={logout}>{t("nav.logout")}</button>
          </div>
        </aside>
        <main className="main">{children}</main>
      </div>
    </MerchantContext.Provider>
  );
}

function LoginScreen({ t, onLoggedIn }) {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  async function submit(e) {
    e.preventDefault();
    setBusy(true);
    setError("");
    try {
      const data = await api("POST", "/auth/merchant/login", { email, password });
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
    <div className="login-wrap">
      <div className="login-card">
        <div className="login-logo">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src="/Logo1.png" alt="NeoTalab" />
        </div>
        <h2>{t("login.title")}</h2>
        <p className="login-sub">{t("login.sub")}</p>
        <form onSubmit={submit}>
          <div className="field">
            <label>{t("login.email")}</label>
            <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="you@yourshop.com" autoComplete="email" />
          </div>
          <div className="field">
            <label>{t("login.password")}</label>
            <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder="Password or 6-digit OTP" autoComplete="current-password" />
          </div>
          {error && <div className="login-error">{error}</div>}
          <button type="submit" className="btn-primary" disabled={busy} style={{ width: "100%" }}>
            {busy ? "Signing in…" : t("login.btn")}
          </button>
        </form>
      </div>
    </div>
  );
}

function ForcePasswordScreen({ toast, onDone }) {
  const [newPass, setNewPass] = useState("");
  const [confirm, setConfirm] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  async function submit(e) {
    e.preventDefault();
    setError("");
    if (newPass.length < 8) { setError("Password must be at least 8 characters."); return; }
    if (newPass !== confirm) { setError("Passwords don't match."); return; }
    setBusy(true);
    try {
      await api("PATCH", "/api/merchant/change-password", { newPassword: newPass, forceChange: true });
      onDone();
    } catch (err) {
      setError(err.message || "Failed to update password.");
      setBusy(false);
    }
  }

  return (
    <div className="login-wrap">
      <div className="login-card">
        <div className="login-logo">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src="/Logo1.png" alt="NeoTalab" />
        </div>
        <h2>Set Your Password</h2>
        <p className="login-sub">You logged in with an activation code. Set a personal password to secure your account.</p>
        <form onSubmit={submit}>
          <div className="field">
            <label>New Password</label>
            <input type="password" value={newPass} onChange={(e) => setNewPass(e.target.value)} placeholder="Min 8 characters" autoComplete="new-password" />
          </div>
          <div className="field">
            <label>Confirm Password</label>
            <input type="password" value={confirm} onChange={(e) => setConfirm(e.target.value)} placeholder="Repeat password" autoComplete="new-password" />
          </div>
          {error && <div className="login-error">{error}</div>}
          <button type="submit" className="btn-primary" disabled={busy} style={{ width: "100%" }}>
            {busy ? "Saving…" : "Set Password & Continue"}
          </button>
        </form>
      </div>
    </div>
  );
}
