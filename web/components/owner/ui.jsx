"use client";

import { Skeleton } from "@/components/ui";

export function PageHeader({ title, subtitle, children }) {
  return (
    <div className="page-header">
      <div>
        <h1>{title}</h1>
        {subtitle && <p className="page-header-sub">{subtitle}</p>}
      </div>
      {children && <div className="page-header-actions">{children}</div>}
    </div>
  );
}

const ic = (d) => (
  <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{d}</svg>
);

/** Line icons for empty states (instead of emoji, which render differently on every device). */
export const EMPTY_ICONS = {
  check: ic(<><circle cx="12" cy="12" r="9" /><path d="m8 12 3 3 5-6" /></>),
  search: ic(<><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></>),
  chart: ic(<><path d="M4 20V10M10 20V4M16 20v-7M22 20H2" /></>),
  history: ic(<><path d="M3 12a9 9 0 1 0 3-6.7L3 8" /><path d="M3 3v5h5M12 7v5l3 2" /></>),
  team: ic(<><circle cx="9" cy="8" r="3.5" /><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 7M21.5 20a6.5 6.5 0 0 0-4-6" /></>),
  calendar: ic(<><rect x="3" y="4.5" width="18" height="16" rx="2" /><path d="M16 2.5v4M8 2.5v4M3 10h18" /></>),
  menu: ic(<><rect x="4" y="3" width="16" height="18" rx="2.5" /><path d="M8 8h8M8 12h8M8 16h5" /></>),
  store: ic(<><path d="M4 10v10h16V10M3 6l2-3h14l2 3v2a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0z" /><path d="M10 20v-5h4v5" /></>),
};

/** Muted info line with an icon, for explanations under a table. */
export function InfoNote({ children }) {
  return (
    <p className="dash-note">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 11v5M12 8h.01" /></svg>
      <span>{children}</span>
    </p>
  );
}

export function EmptyState({ icon = EMPTY_ICONS.store, title, description, actionLabel, onAction, actionClassName = "empty-action" }) {
  return (
    <div className="empty">
      <div className="empty-icon">{icon}</div>
      <div className="empty-text">{title}</div>
      {description && <p className="empty-desc">{description}</p>}
      {actionLabel && onAction && (
        <button type="button" className={actionClassName} onClick={onAction}>{actionLabel}</button>
      )}
    </div>
  );
}

export function ErrorBanner({ message }) {
  if (!message) return null;
  return <div className="error-banner">{message}</div>;
}

export function AuthLoading() {
  return (
    <div className="login-wrap">
      <div className="login-card" style={{ maxWidth: 420 }}>
        <Skeleton w={200} h={48} style={{ margin: "0 auto 28px" }} />
        <Skeleton w="55%" h={22} style={{ marginBottom: 8 }} />
        <Skeleton w="80%" h={13} style={{ marginBottom: 28 }} />
        <Skeleton w="100%" h={42} style={{ marginBottom: 14 }} />
        <Skeleton w="100%" h={42} style={{ marginBottom: 20 }} />
        <Skeleton w="100%" h={44} br={8} />
      </div>
    </div>
  );
}
