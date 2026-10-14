"use client";

import { useState } from "react";
import Link from "next/link";
import Brand from "@/components/Brand";
import { useLang } from "@/lib/i18n";

export const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

const LANGS = [["en", "EN"], ["ar", "عربي"], ["fr", "FR"]];

/** Full-page sign-in frame: logo + language switch on top, a centred card, legal links below. */
export function AuthShell({ title, subtitle, children, below }) {
  const { t, lang, setLang } = useLang();
  return (
    <div className="auth-page">
      {/* Same layout in every language: logo left, language switch right. */}
      <header className="auth-top" dir="ltr">
        <Link href="/" aria-label="NeoTalab"><Brand size={32} /></Link>
        <div className="auth-lang" role="group" aria-label="Language">
          {LANGS.map(([code, label]) => (
            <button key={code} type="button" className={lang === code ? "active" : ""} aria-pressed={lang === code} onClick={() => setLang(code)}>
              {label}
            </button>
          ))}
        </div>
      </header>

      <main className="auth-main">
        <div className="auth-card">
          <h1 className="auth-title">{title}</h1>
          {subtitle && <p className="auth-sub">{subtitle}</p>}
          {children}
        </div>
        {below}
      </main>

      <footer className="auth-foot">
        <span>© {new Date().getFullYear()} NeoTalab</span>
        <Link href="/privacy">{t("auth.privacy")}</Link>
        <Link href="/terms">{t("auth.terms")}</Link>
      </footer>
    </div>
  );
}

/** Labelled input with a leading icon, an optional trailing control, a hint and an inline error. */
export function AuthField({ id, label, icon, error, hint, trailing, ...input }) {
  const describedBy = [error && `${id}-err`, hint && `${id}-hint`].filter(Boolean).join(" ") || undefined;
  return (
    <div className={`auth-field ${error ? "invalid" : ""}`}>
      <label htmlFor={id}>{label}</label>
      <div className="auth-input">
        {icon && <span className="auth-icon" aria-hidden="true">{icon}</span>}
        <input id={id} dir="ltr" aria-invalid={!!error} aria-describedby={describedBy} {...input} />
        {trailing}
      </div>
      {error && <p id={`${id}-err`} className="auth-err">{error}</p>}
      {hint && !error && <p id={`${id}-hint`} className="auth-hint">{hint}</p>}
    </div>
  );
}

/** Password input with a show/hide toggle. */
export function PasswordField(props) {
  const { t } = useLang();
  const [shown, setShown] = useState(false);
  return (
    <AuthField
      {...props}
      icon={ICONS.lock}
      type={shown ? "text" : "password"}
      trailing={(
        <button type="button" className="auth-eye" onClick={() => setShown((v) => !v)} aria-label={shown ? t("auth.hide") : t("auth.show")} aria-pressed={shown}>
          {shown ? ICONS.eyeOff : ICONS.eye}
        </button>
      )}
    />
  );
}

export function AuthAlert({ children }) {
  if (!children) return null;
  return (
    <div className="auth-alert" role="alert">
      {ICONS.alert}
      <span>{children}</span>
    </div>
  );
}

export function AuthSubmit({ busy, busyLabel, children, disabled }) {
  return (
    <button type="submit" className="auth-submit" disabled={busy || disabled} aria-busy={busy}>
      {busy && <span className="auth-spinner" aria-hidden="true" />}
      {busy ? busyLabel : children}
    </button>
  );
}

const svg = (d) => (
  <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">{d}</svg>
);

export const ICONS = {
  mail: svg(<><rect x="3" y="5" width="18" height="14" rx="2" /><path d="m3 7 9 6 9-6" /></>),
  lock: svg(<><rect x="4" y="11" width="16" height="10" rx="2" /><path d="M8 11V7a4 4 0 0 1 8 0v4" /></>),
  eye: svg(<><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z" /><circle cx="12" cy="12" r="3" /></>),
  eyeOff: svg(<><path d="M9.9 5.1A10 10 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4.2M6.6 6.6A17 17 0 0 0 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.4-1.6" /><path d="M14.1 14.1a3 3 0 0 1-4.2-4.2M3 3l18 18" /></>),
  alert: svg(<><circle cx="12" cy="12" r="9" /><path d="M12 8v4M12 16h.01" /></>),
  check: svg(<path d="m5 12 5 5 9-10" />),
  dot: svg(<circle cx="12" cy="12" r="3" />),
  whatsapp: (
    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.2a8.2 8.2 0 0 1-4.2-1.1l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.6.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6a2.7 2.7 0 0 0 1.8-1.2 2.2 2.2 0 0 0 .1-1.3c0-.1-.2-.2-.5-.3z" /></svg>
  ),
};
