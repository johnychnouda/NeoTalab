"use client";

import { useEffect, useState } from "react";
import { usePathname } from "next/navigation";

/**
 * Responsive app chrome: fixed sidebar on desktop/tablet landscape,
 * off-canvas drawer + top bar on phones and narrow tablets.
 */
export default function AppShell({ brand = "NeoTalab", children, sidebar }) {
  const [navOpen, setNavOpen] = useState(false);
  const pathname = usePathname();

  useEffect(() => {
    setNavOpen(false);
  }, [pathname]);

  useEffect(() => {
    if (!navOpen) return undefined;
    const onKey = (e) => {
      if (e.key === "Escape") setNavOpen(false);
    };
    document.addEventListener("keydown", onKey);
    document.body.classList.add("nav-lock");
    return () => {
      document.removeEventListener("keydown", onKey);
      document.body.classList.remove("nav-lock");
    };
  }, [navOpen]);

  return (
    <div className={`app-shell${navOpen ? " nav-open" : ""}`}>
      <header className="mobile-topbar">
        <button
          type="button"
          className="mobile-nav-btn"
          aria-label="Open menu"
          aria-expanded={navOpen}
          onClick={() => setNavOpen(true)}
        >
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" aria-hidden="true">
            <line x1="4" y1="7" x2="20" y2="7" />
            <line x1="4" y1="12" x2="20" y2="12" />
            <line x1="4" y1="17" x2="20" y2="17" />
          </svg>
        </button>
        <div className="mobile-topbar-brand">{brand}</div>
      </header>

      <button
        type="button"
        className="sidebar-backdrop"
        aria-label="Close menu"
        tabIndex={navOpen ? 0 : -1}
        onClick={() => setNavOpen(false)}
      />

      <aside className="sidebar" aria-hidden={false}>
        <div className="sidebar-mobile-head">
          <span className="sidebar-mobile-title">{brand}</span>
          <button
            type="button"
            className="sidebar-close-btn"
            aria-label="Close menu"
            onClick={() => setNavOpen(false)}
          >
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" aria-hidden="true">
              <line x1="18" y1="6" x2="6" y2="18" />
              <line x1="6" y1="6" x2="18" y2="18" />
            </svg>
          </button>
        </div>
        {sidebar}
      </aside>

      <main className="main">{children}</main>
    </div>
  );
}
