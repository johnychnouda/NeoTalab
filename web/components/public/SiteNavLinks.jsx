"use client";

import { useEffect, useRef, useState } from "react";

/**
 * Header links to the homepage sections; the one on screen is highlighted.
 * On phones and tablets they fold into a ☰ menu so the header stays one line; on small phones the
 * merchant login moves into that menu too.
 */
export default function SiteNavLinks({ links, menuLabel, login }) {
  const [active, setActive] = useState(null);
  const [open, setOpen] = useState(false);
  const rootRef = useRef(null);

  useEffect(() => {
    const sections = links.map(([id]) => document.getElementById(id)).filter(Boolean);
    if (!sections.length) return;
    const onScroll = () => {
      const line = window.innerHeight * 0.35;
      let current = null;
      for (const s of sections) if (s.getBoundingClientRect().top <= line) current = s.id;
      const atBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4;
      setActive(atBottom ? sections[sections.length - 1].id : current);
    };
    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });
    window.addEventListener("resize", onScroll);
    return () => { window.removeEventListener("scroll", onScroll); window.removeEventListener("resize", onScroll); };
  }, [links]);

  // The menu closes on Escape or a tap anywhere else.
  useEffect(() => {
    if (!open) return;
    const onKey = (e) => { if (e.key === "Escape") setOpen(false); };
    const onTap = (e) => { if (!rootRef.current?.contains(e.target)) setOpen(false); };
    document.addEventListener("keydown", onKey);
    document.addEventListener("pointerdown", onTap);
    return () => { document.removeEventListener("keydown", onKey); document.removeEventListener("pointerdown", onTap); };
  }, [open]);

  return (
    <div className="site-links-wrap" ref={rootRef}>
      <button type="button" className="site-menu-btn" aria-expanded={open} aria-controls="site-links" aria-label={menuLabel} onClick={() => setOpen(!open)}>
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
          {open ? <path d="M6 6l12 12M18 6L6 18" /> : <path d="M4 7h16M4 12h16M4 17h16" />}
        </svg>
      </button>
      <div className={`site-links${open ? " open" : ""}`} id="site-links">
        {links.map(([id, label]) => (
          <a key={id} href={`/#${id}`} className={active === id ? "active" : undefined} aria-current={active === id ? "location" : undefined} onClick={() => setOpen(false)}>
            {label}
          </a>
        ))}
        {login && <a href={login[0]} className="site-links-login">{login[1]}</a>}
      </div>
    </div>
  );
}
