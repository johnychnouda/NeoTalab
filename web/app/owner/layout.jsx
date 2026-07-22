"use client";

import { createContext, useContext, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { apiFetch, getToken, setToken, clearToken } from "@/lib/api";
import { buildInboxAlerts } from "@/lib/inbox";
import { AuthLoading } from "@/components/owner/ui";

const OwnerContext = createContext({});
export function useOwner() {
  return useContext(OwnerContext);
}

const api = (method, path, body) => apiFetch("owner", method, path, body);

const NAV = [
  { href: "/owner", label: "Merchants", icon: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z" /></svg> },
  { href: "/owner/billing", label: "Billing", icon: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><rect x="1" y="4" width="22" height="16" rx="2" /><line x1="1" y1="10" x2="23" y2="10" /></svg> },
  { href: "/owner/analytics", label: "Analytics", icon: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><line x1="18" y1="20" x2="18" y2="10" /><line x1="12" y1="20" x2="12" y2="4" /><line x1="6" y1="20" x2="6" y2="14" /><line x1="3" y1="20" x2="21" y2="20" /></svg> },
  { href: "/owner/inbox", label: "Inbox", icon: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M22 12h-6l-2 3H10l-2-3H4" /><path d="M5.45 5.11L2 12v6a2 2 0 002 2h16a2 2 0 002-2v-6l-3.45-6.89A2 2 0 0016.76 4H7.24a2 2 0 00-1.79 1.11z" /></svg> },
  { href: "/owner/settings", label: "Settings", icon: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z" /></svg> },
];

export default function OwnerLayout({ children }) {
  const [authed, setAuthed] = useState(null);
  const [platformName, setPlatformName] = useState("NeoTalab");
  const [paletteOpen, setPaletteOpen] = useState(false);
  const [alertCount, setAlertCount] = useState(0);
  const [requestCount, setRequestCount] = useState(0);
  const pathname = usePathname();

  useEffect(() => {
    const token = getToken("owner");
    if (token) {
      setAuthed(true);
      setPlatformName(localStorage.getItem("nt_platform_name") || "NeoTalab");
    } else {
      setAuthed(false);
    }
  }, []);

  useEffect(() => {
    if (!authed) return;
    let cancelled = false;
    async function poll() {
      try {
        const [{ merchants = [] }, { requests = [] }] = await Promise.all([
          api("GET", "/api/owner/merchants?limit=100"),
          api("GET", "/api/owner/onboarding"),
        ]);
        if (cancelled) return;
        const alerts = buildInboxAlerts(merchants, requests);
        setAlertCount(alerts.length);
        setRequestCount(alerts.filter((i) => i.category === "requests").length);
      } catch { /* inbox poll — ignore transient errors */ }
    }
    poll();
    const t = setInterval(poll, 60000);
    return () => { cancelled = true; clearInterval(t); };
  }, [authed]);

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

  const platformInitials = platformName.split(" ").map((w) => w[0]).join("").slice(0, 2).toUpperCase() || "NT";

  return (
    <OwnerContext.Provider value={{ api, platformName, setPlatformName }}>
      <div className="app-shell">
        <aside className="sidebar">
          <div className="sidebar-logo">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src="/Logo1.png" alt="NeoTalab" />
          </div>
          <div className="sidebar-role">Owner Portal</div>

          <button type="button" className="sidebar-search" onClick={() => setPaletteOpen(true)}>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="11" cy="11" r="8" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>
            <span>Search…</span>
            <kbd>⌘K</kbd>
          </button>

          <nav>
            {NAV.map((item) => (
              <Link key={item.href} href={item.href}
                className={`nav-item ${pathname === item.href || (item.href === "/owner/inbox" && pathname.startsWith("/owner/inbox")) ? "active" : ""}`}>
                {item.icon}
                {item.label}
                {item.href === "/owner" && requestCount > 0 && (
                  <span className="nav-badge">{requestCount}</span>
                )}
                {item.href === "/owner/inbox" && alertCount > 0 && (
                  <span className="nav-badge">{alertCount > 9 ? "9+" : alertCount}</span>
                )}
              </Link>
            ))}
          </nav>

          <div className="sidebar-spacer" />

          <div className="sidebar-footer">
            <div className="owner-profile">
              <div className="owner-avatar">{platformInitials}</div>
              <div>
                <div className="owner-name">{platformName}</div>
                <div className="owner-role">Platform Owner</div>
              </div>
            </div>
            <button type="button" className="btn-logout" onClick={logout}>Sign out</button>
          </div>
        </aside>

        <main className="main">{children}</main>

        <CommandPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} />
      </div>
    </OwnerContext.Provider>
  );
}

function CommandPalette({ open, onClose }) {
  const router = useRouter();
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
    api("GET", "/api/owner/merchants?limit=100")
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
    .filter((n) => !q || n.label.toLowerCase().includes(q))
    .map((n) => ({ key: `p_${n.href}`, group: "Pages", label: n.label, icon: n.icon, sub: "", run: () => router.push(n.href) }));

  const merchItems = (q
    ? merchants.filter((m) => m.shop_name?.toLowerCase().includes(q) || (m.whatsapp_number || "").includes(q))
    : merchants.slice(0, 5)
  ).map((m) => ({
    key: `m_${m.id}`,
    group: "Merchants",
    label: m.shop_name,
    icon: <span style={{ fontWeight: 800, fontSize: 11, color: "var(--green)" }}>{m.shop_name.split(" ").map((w) => w[0]).join("").slice(0, 2).toUpperCase()}</span>,
    sub: m.status === "suspended" ? "Suspended" : m.subscription_status === "paid" ? "Active" : m.subscription_status === "trial" ? "Trial" : "Pending",
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
          <input ref={inputRef} className="cmdk-input" placeholder="Search pages or merchants…"
            value={query} onChange={(e) => { setQuery(e.target.value); setSel(0); }} onKeyDown={onKeyDown} />
          <span className="topbar-kbd">esc</span>
        </div>
        <div className="cmdk-list">
          {items.length === 0 && <div className="cmdk-empty">No results for &quot;{query}&quot;</div>}
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
          <span><kbd>↑↓</kbd> navigate</span>
          <span><kbd>↵</kbd> open</span>
          <span><kbd>esc</kbd> close</span>
        </div>
      </div>
    </div>
  );
}

function LoginScreen({ onLoggedIn }) {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  async function submit(e) {
    e.preventDefault();
    setBusy(true);
    setError("");
    try {
      const data = await api("POST", "/auth/owner/login", { email, password });
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
    <div className="login-wrap">
      <div className="login-card">
        <div className="login-logo">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src="/Logo1.png" alt="NeoTalab" />
        </div>
        <h2>Owner Portal</h2>
        <p className="login-sub">Platform management dashboard</p>
        <form onSubmit={submit}>
          <div className="field">
            <label>Email</label>
            <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="Email address" autoComplete="email" />
          </div>
          <div className="field">
            <label>Password</label>
            <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder="••••••••" autoComplete="current-password" />
          </div>
          {error && <div className="login-error">{error}</div>}
          <button type="submit" className="btn-primary" disabled={busy} style={{ width: "100%" }}>
            {busy ? "Signing in…" : "Sign in"}
          </button>
        </form>
      </div>
    </div>
  );
}
