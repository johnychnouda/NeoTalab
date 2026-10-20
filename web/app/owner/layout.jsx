"use client";

import { createContext, useContext, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { apiFetch, getToken, setToken, clearToken } from "@/lib/api";
import { buildInboxAlerts } from "@/lib/inbox";
import { useLiveRefresh } from "@/lib/live";
import AppShell from "@/components/AppShell";
import Brand from "@/components/Brand";
import { DashLangSwitch, DashProfile } from "@/components/DashSidebar";
import { AuthShell, AuthField, PasswordField, AuthAlert, AuthSubmit, ICONS, EMAIL_RE } from "@/components/auth/AuthShell";
import { AuthLoading } from "@/components/owner/ui";
import { useLang } from "@/lib/i18n";

const OwnerContext = createContext({});
export function useOwner() {
  return useContext(OwnerContext);
}

const api = (method, path, body) => apiFetch("owner", method, path, body);

const NAV = [
  { href: "/owner", key: "ow.nav.merchants", icon: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z" /></svg> },
  { href: "/owner/billing", key: "ow.f.billing", icon: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><rect x="1" y="4" width="22" height="16" rx="2" /><line x1="1" y1="10" x2="23" y2="10" /></svg> },
  { href: "/owner/usage", key: "ow.nav.usage", icon: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="12" cy="12" r="9" /><path d="M14.5 9.5c-.5-1-1.5-1.5-2.5-1.5-1.4 0-2.5.8-2.5 2s1.1 1.6 2.5 2 2.5.8 2.5 2-1.1 2-2.5 2c-1 0-2-.5-2.5-1.5M12 6.5V8M12 16v1.5" /></svg> },
  { href: "/owner/inbox", key: "ow.nav.inbox", icon: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M22 12h-6l-2 3H10l-2-3H4" /><path d="M5.45 5.11L2 12v6a2 2 0 002 2h16a2 2 0 002-2v-6l-3.45-6.89A2 2 0 0016.76 4H7.24a2 2 0 00-1.79 1.11z" /></svg> },
  { href: "/owner/settings", key: "ow.nav.settings", icon: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z" /></svg> },
];

export default function OwnerLayout({ children }) {
  const [authed, setAuthed] = useState(null);
  const [platformName, setPlatformName] = useState("NeoTalab");
  const [paletteOpen, setPaletteOpen] = useState(false);
  const [alertCount, setAlertCount] = useState(0);
  const [requestCount, setRequestCount] = useState(0);
  const pathname = usePathname();
  const { t } = useLang();

  useEffect(() => {
    const token = getToken("owner");
    if (token) {
      setAuthed(true);
      setPlatformName(localStorage.getItem("nt_platform_name") || "NeoTalab");
    } else {
      setAuthed(false);
    }
  }, []);

  // Expired or revoked token (see lib/api.js): back to the login screen instead of an empty dashboard.
  useEffect(() => {
    function onExpired(e) {
      if (e.detail?.role !== "owner") return;
      setPaletteOpen(false);
      setAuthed(false);
    }
    window.addEventListener("nt:auth-expired", onExpired);
    return () => window.removeEventListener("nt:auth-expired", onExpired);
  }, []);

  // Inbox badge: counts update on their own (see lib/live.js).
  async function pollInbox() {
    try {
      const [{ merchants = [] }, { requests = [] }, { alerts: system = [] }] = await Promise.all([
        api("GET", "/admin/merchants"),
        api("GET", "/admin/onboarding"),
        api("GET", "/admin/system-alerts").catch(() => ({ alerts: [] })),
      ]);
      const alerts = buildInboxAlerts(merchants, requests, (k) => k);
      setAlertCount(alerts.length + system.length);
      setRequestCount(alerts.filter((i) => i.category === "requests").length);
    } catch { /* inbox poll — ignore transient errors */ }
  }
  useEffect(() => { if (authed) pollInbox(); }, [authed]);
  useLiveRefresh(pollInbox, { enabled: !!authed });

  useEffect(() => {
    const h = (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === "k") {
        e.preventDefault();
        setPaletteOpen((o) => !o);
      }
    };
    window.addEventListener("keydown", h);
    return () => window.removeEventListener("keydown", h);
  }, []);

  function logout() {
    api("POST", "/auth/logout").catch(() => {});
    clearToken("owner");
    setAuthed(false);
  }

  if (authed === null) return <AuthLoading />;
  if (authed === false) return <LoginScreen onLoggedIn={() => {
    setPlatformName(localStorage.getItem("nt_platform_name") || "NeoTalab");
    setAuthed(true);
  }} />;

  return (
    <OwnerContext.Provider value={{ api, platformName, setPlatformName }}>
      <AppShell brand={platformName} className="dash" sidebar={(
        <>
          <div className="dash-side-head" dir="ltr">
            <Brand size={30} />
            <span className="dash-side-badge">{t("ow.badge")}</span>
          </div>

          <button type="button" className="sidebar-search" onClick={() => setPaletteOpen(true)}>
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="11" cy="11" r="8" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>
            <span>{t("ow.search")}</span>
            <kbd>⌘K</kbd>
          </button>

          <nav aria-label={t("ow.portal")}>
            {NAV.map((item) => {
              const active = pathname === item.href || (item.href === "/owner/inbox" && pathname.startsWith("/owner/inbox"));
              return (
                <Link key={item.href} href={item.href} className={`nav-item ${active ? "active" : ""}`} aria-current={active ? "page" : undefined}>
                  {item.icon}
                  <span>{t(item.key)}</span>
                  {item.href === "/owner" && requestCount > 0 && (
                    <span className="nav-badge">{requestCount}</span>
                  )}
                  {item.href === "/owner/inbox" && alertCount > 0 && (
                    <span className="nav-badge">{alertCount > 9 ? "9+" : alertCount}</span>
                  )}
                </Link>
              );
            })}
          </nav>

          <div className="sidebar-spacer" />

          <div className="dash-side-foot">
            <DashLangSwitch />
            <DashProfile name={platformName} role={t("ow.role")} onLogout={logout} />
          </div>
        </>
      )}>
        <div className="dash-page">{children}</div>
        <CommandPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} />
      </AppShell>
    </OwnerContext.Provider>
  );
}

function CommandPalette({ open, onClose }) {
  const router = useRouter();
  const { t } = useLang();
  const pathname = usePathname();
  const [query, setQuery] = useState("");
  const [sel, setSel] = useState(0);
  const [merchants, setMerchants] = useState([]);
  const inputRef = useRef(null);

  useEffect(() => {
    if (!open) return;
    setQuery("");
    setSel(0);
    setTimeout(() => inputRef.current?.focus(), 30);
    api("GET", "/admin/merchants")
      .then((d) => setMerchants(d.merchants || []))
      .catch(() => {});
  }, [open]);

  useEffect(() => {
    document.querySelector(".cmdk-item.active")?.scrollIntoView({ block: "nearest" });
  }, [sel]);

  if (!open) return null;

  const q = query.toLowerCase().trim();

  function openMerchant(m) {
    if (pathname === "/owner") {
      window.dispatchEvent(new CustomEvent("nt-open-merchant", { detail: m.id }));
    } else {
      router.push(`/owner?m=${m.id}`);
    }
  }

  const pageItems = NAV
    .filter((n) => !q || t(n.key).toLowerCase().includes(q))
    .map((n) => ({ key: `p_${n.href}`, group: t("ow.cmd.pages"), label: t(n.key), icon: n.icon, sub: "", run: () => router.push(n.href) }));

  const merchItems = (q
    ? merchants.filter((m) => m.shop_name?.toLowerCase().includes(q) || (m.whatsapp_number || "").includes(q))
    : merchants.slice(0, 5)
  ).map((m) => ({
    key: `m_${m.id}`,
    group: t("ow.nav.merchants"),
    label: m.shop_name,
    icon: <span style={{ fontWeight: 800, fontSize: 11, color: "var(--green)" }}>{m.shop_name.split(" ").map((w) => w[0]).join("").slice(0, 2).toUpperCase()}</span>,
    sub: t(m.status === "suspended" ? "ow.f.suspended" : m.subscription_status === "paid" ? "ow.f.active" : m.subscription_status === "trial" ? "ow.f.trial" : "ow.f.pending"),
    run: () => openMerchant(m),
  }));

  const items = [...pageItems, ...merchItems];
  const selIdx = Math.min(sel, Math.max(items.length - 1, 0));

  function onKeyDown(e) {
    if (e.key === "ArrowDown") { e.preventDefault(); setSel((s) => Math.min(s + 1, items.length - 1)); }
    else if (e.key === "ArrowUp") { e.preventDefault(); setSel((s) => Math.max(s - 1, 0)); }
    else if (e.key === "Enter" && items[selIdx]) { items[selIdx].run(); onClose(); }
    else if (e.key === "Escape") onClose();
  }

  let lastGroup = null;

  return (
    <div
      className="cmdk-overlay"
      onMouseDown={(e) => { if (e.target === e.currentTarget) onClose(); }}
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div className="cmdk" onMouseDown={(e) => e.stopPropagation()} onClick={(e) => e.stopPropagation()}>
        <div className="cmdk-input-row">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="11" cy="11" r="8" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>
          <input ref={inputRef} className="cmdk-input" placeholder={t("ow.cmd.placeholder")}
            value={query} onChange={(e) => { setQuery(e.target.value); setSel(0); }} onKeyDown={onKeyDown} />
          <span className="topbar-kbd">esc</span>
        </div>
        <div className="cmdk-list">
          {items.length === 0 && <div className="cmdk-empty">{t("ow.cmd.none", { q: query })}</div>}
          {items.map((it, i) => {
            const showGroup = it.group !== lastGroup;
            lastGroup = it.group;
            return (
              <div key={it.key}>
                {showGroup && <div className="cmdk-group">{it.group}</div>}
                <button className={`cmdk-item ${i === selIdx ? "active" : ""}`}
                  onMouseEnter={() => setSel(i)}
                  onClick={() => { it.run(); onClose(); }}>
                  <span className="cmdk-icon">{it.icon}</span>
                  {it.label}
                  {it.sub && <span className="cmdk-item-sub">{it.sub}</span>}
                </button>
              </div>
            );
          })}
        </div>
        <div className="cmdk-footer">
          <span><kbd>↑↓</kbd> {t("ow.cmd.move")}</span>
          <span><kbd>↵</kbd> {t("ow.cmd.open")}</span>
          <span><kbd>esc</kbd> {t("ow.cmd.close")}</span>
        </div>
      </div>
    </div>
  );
}

function LoginScreen({ onLoggedIn }) {
  const { t } = useLang();
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
    if (!password) next.password = t("auth.needOwnerPassword");
    setErrors(next);
    setError("");
    if (Object.keys(next).length) {
      document.getElementById(next.email ? "ow-email" : "ow-pass")?.focus();
      return;
    }
    setBusy(true);
    try {
      const data = await api("POST", "/auth/login", { email: mail, password });
      setToken("owner", data.token);
      if (data.merchant?.name) localStorage.setItem("nt_platform_name", data.merchant.name);
      else if (data.user?.name) localStorage.setItem("nt_platform_name", data.user.name);
      onLoggedIn(data.user);
    } catch (err) {
      setError(err.message);
      setBusy(false);
    }
  }

  return (
    <AuthShell title={t("ow.portal")} subtitle={t("ow.login.sub")}>
      <form onSubmit={submit} noValidate>
        <AuthAlert>{error}</AuthAlert>
        <AuthField
          id="ow-email" label={t("login.email")} icon={ICONS.mail} error={errors.email}
          type="email" inputMode="email" autoComplete="username" autoCapitalize="none" spellCheck={false} autoFocus
          value={email} placeholder="you@neotalab.com"
          onChange={(e) => { setEmail(e.target.value); if (errors.email) setErrors({ ...errors, email: "" }); }}
        />
        <PasswordField
          id="ow-pass" label={t("login.password")} error={errors.password}
          autoComplete="current-password" value={password} placeholder={t("auth.passwordPh")}
          onChange={(e) => { setPassword(e.target.value); if (errors.password) setErrors({ ...errors, password: "" }); }}
        />
        <AuthSubmit busy={busy} busyLabel={t("ow.login.busy")}>{t("login.btn")}</AuthSubmit>
      </form>
      <p className="auth-secure">{ICONS.lock}{t("auth.ownerOnly")}</p>
    </AuthShell>
  );
}
