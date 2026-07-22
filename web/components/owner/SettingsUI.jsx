"use client";

import { WhatsAppIcon } from "@/components/ui";

/** Shared building blocks for owner Settings */

export function SetCard({ children, className = "" }) {
  return <div className={`set-card ${className}`.trim()}>{children}</div>;
}

export function SetCardHeader({ icon, tone = "green", title, desc, badge }) {
  return (
    <div className="set-card-head">
      <div className="set-card-head-row">
        <div className="set-card-head-main">
          {icon && (
            <span className={`set-icon set-icon-${tone}`} aria-hidden>
              {icon}
            </span>
          )}
          <div>
            <h2 className="set-card-title">{title}</h2>
            {desc && <p className="set-card-desc">{desc}</p>}
          </div>
        </div>
        {badge}
      </div>
    </div>
  );
}

export function SetDivider() {
  return <div className="set-card-divider" role="separator" />;
}

export function SetSection({ title, desc, children }) {
  return (
    <div className="set-card-body set-section">
      {title && <h3 className="set-section-title">{title}</h3>}
      {desc && <p className="set-card-desc">{desc}</p>}
      {children}
    </div>
  );
}

export function SetField({ label, hint, children, className = "", htmlFor }) {
  return (
    <div className={`set-field ${className}`.trim()}>
      {label && <label htmlFor={htmlFor}>{label}</label>}
      {children}
      {hint && <p className="set-hint">{hint}</p>}
    </div>
  );
}

export function SetNote({ children, tone = "info" }) {
  return <div className={`set-note set-note-${tone}`}>{children}</div>;
}

export function SetAside({ title, children }) {
  return (
    <aside className="set-aside">
      {title && <div className="set-aside-title">{title}</div>}
      <div className="set-aside-body">{children}</div>
    </aside>
  );
}

export function SetRuleCard({ label, value, children }) {
  return (
    <div className="set-rule">
      <div className="set-rule-label">{label}</div>
      {children}
      {value && <div className="set-rule-value">{value}</div>}
    </div>
  );
}

export function SetStep({ n, title, desc, done }) {
  return (
    <div className={`set-step${done ? " done" : ""}`}>
      <span className="set-step-num">{done ? "✓" : n}</span>
      <div>
        <div className="set-step-title">{title}</div>
        {desc && <div className="set-step-desc">{desc}</div>}
      </div>
    </div>
  );
}

export function passwordStrength(pw) {
  if (!pw) return { score: 0, label: "", width: "0%" };
  let score = 0;
  if (pw.length >= 8) score++;
  if (pw.length >= 12) score++;
  if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) score++;
  if (/\d/.test(pw)) score++;
  if (/[^A-Za-z0-9]/.test(pw)) score++;
  const capped = Math.min(score, 4);
  const labels = ["", "Weak", "Fair", "Good", "Strong"];
  const tones = ["", "weak", "fair", "good", "strong"];
  return {
    score: capped,
    label: labels[capped],
    tone: tones[capped],
    width: `${(capped / 4) * 100}%`,
  };
}

export const SET_ICONS = {
  pricing: (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
      <line x1="12" y1="1" x2="12" y2="23" />
      <path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6" />
    </svg>
  ),
  whatsapp: <WhatsAppIcon size={16} color="currentColor" />,
  notifications: (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
      <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9" />
      <path d="M13.73 21a2 2 0 01-3.46 0" />
    </svg>
  ),
  account: (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
      <rect x="3" y="11" width="18" height="11" rx="2" />
      <path d="M7 11V7a5 5 0 0110 0v4" />
    </svg>
  ),
};
