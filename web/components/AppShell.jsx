"use client";

import { useEffect, useRef, useState } from "react";
import { usePathname } from "next/navigation";
import { useLang } from "@/lib/i18n";

/**
 * Responsive app chrome: fixed sidebar on desktop/tablet landscape,
 * off-canvas drawer + top bar on phones and narrow tablets.
 */
export default function AppShell({ brand = "NeoTalab", children, sidebar, className = "", topbarExtra = null }) {
  const { t } = useLang();
  const [navOpen, setNavOpen] = useState(false);
  const pathname = usePathname();
  const openBtnRef = useRef(null);
  const closeBtnRef = useRef(null);

  useEffect(() => {
    setNavOpen(false);
  }, [pathname]);

  // Panels and modals render into <body>, outside the shell; "<className>-body" lets them share its styles.
  useEffect(() => {
    if (!className) return undefined;
    const tag = `${className}-body`;
    document.body.classList.add(tag);
    return () => document.body.classList.remove(tag);
  }, [className]);

  useEffect(() => {
    if (!navOpen) return undefined;
    const onKey = (e) => {
      if (e.key === "Escape") setNavOpen(false);
    };
    document.addEventListener("keydown", onKey);
    document.body.classList.add("nav-lock");
    closeBtnRef.current?.focus();
    const openBtn = openBtnRef.current;
    return () => {
      document.removeEventListener("keydown", onKey);
      document.body.classList.remove("nav-lock");
      // Back to the menu button, unless the drawer closed because a link took focus elsewhere.
      if (openBtn?.offsetParent && document.activeElement?.closest?.(".sidebar, .sidebar-backdrop")) openBtn.focus();
    };
  }, [navOpen]);

  return (
    <div className={`app-shell${navOpen ? " nav-open" : ""}${className ? ` ${className}` : ""}`}>
      <header className="mobile-topbar">
        <button
          type="button"
          ref={openBtnRef}
          className="mobile-nav-btn"
          aria-label={t("nav.openMenu")}
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
        {topbarExtra}
      </header>

      <button
        type="button"
        className="sidebar-backdrop"
        aria-label={t("nav.closeMenu")}
        tabIndex={navOpen ? 0 : -1}
        onClick={() => setNavOpen(false)}
      />

      <aside className="sidebar">
        <div className="sidebar-mobile-head">
          <span className="sidebar-mobile-title">{brand}</span>
          <button
            type="button"
            ref={closeBtnRef}
            className="sidebar-close-btn"
            aria-label={t("nav.closeMenu")}
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
