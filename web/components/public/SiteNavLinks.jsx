"use client";

import { useEffect, useState } from "react";

/** Header links to the homepage sections; the one on screen is highlighted. */
export default function SiteNavLinks({ links }) {
  const [active, setActive] = useState(null);

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

  return (
    <div className="site-links">
      {links.map(([id, label]) => (
        <a key={id} href={`/#${id}`} className={active === id ? "active" : undefined} aria-current={active === id ? "location" : undefined}>
          {label}
        </a>
      ))}
    </div>
  );
}
