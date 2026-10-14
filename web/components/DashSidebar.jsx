"use client";

import { useLang } from "@/lib/i18n";

/** Sidebar bits shared by the owner portal and the shop dashboard. */

export function DashLangSwitch() {
  const { lang, setLang } = useLang();
  return (
    <div className="dash-lang" role="group" aria-label="Language" dir="ltr">
      {[["en", "EN"], ["ar", "عربي"], ["fr", "FR"]].map(([code, label]) => (
        <button key={code} type="button" className={lang === code ? "active" : ""} aria-pressed={lang === code} onClick={() => setLang(code)}>
          {label}
        </button>
      ))}
    </div>
  );
}

export function DashProfile({ name, role, onLogout }) {
  const { t } = useLang();
  const initials = (name || "").split(/\s+/).filter(Boolean).map((w) => w[0]).join("").slice(0, 2).toUpperCase() || "NT";
  return (
    <div className="dash-profile">
      <div className="owner-avatar">{initials}</div>
      <div className="dash-profile-text">
        <div className="owner-name">{name}</div>
        {role && <div className="owner-role">{role}</div>}
      </div>
      <button type="button" className="dash-logout" onClick={onLogout} aria-label={t("nav.logout")} title={t("nav.logout")}>
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" /></svg>
      </button>
    </div>
  );
}
