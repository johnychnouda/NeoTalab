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

export function EmptyState({ icon = "📭", title, description, actionLabel, onAction, actionClassName = "empty-action" }) {
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
