"use client";

import { useCallback, useEffect, useState } from "react";
import { timeAgo } from "@/lib/api";
import { useLang } from "@/lib/i18n";
import { useLiveRefresh } from "@/lib/live";
import { Skeleton, useToast } from "@/components/ui";
import { EmptyState, EMPTY_ICONS } from "@/components/owner/ui";
import LaunchChecklist from "@/components/owner/LaunchChecklist";

const LEVEL = {
  warning: { key: "ow.sys.warning", tone: "warn" },
  error: { key: "ow.sys.error", tone: "bad" },
  critical: { key: "ow.sys.critical", tone: "bad" },
  alert: { key: "ow.sys.critical", tone: "bad" },
  emergency: { key: "ow.sys.critical", tone: "bad" },
};

/** Inbox → System: problems the platform logged (failed backups, WhatsApp/AI errors…). */
export default function SystemAlerts({ api, onCountChange }) {
  const { t, locale } = useLang();
  const toast = useToast();
  const [showFixed, setShowFixed] = useState(false);
  const [alerts, setAlerts] = useState(null);
  const [open, setOpen] = useState(null);

  const load = useCallback(async () => {
    try {
      const { alerts: list = [] } = await api("GET", `/admin/system-alerts${showFixed ? "?resolved=1" : ""}`);
      setAlerts(list);
      if (!showFixed) onCountChange?.(list.length);
    } catch (e) {
      // A failed background refresh keeps the list on screen; only the first load reports it.
      setAlerts((cur) => {
        if (cur === null) toast(e.message, "error");
        return cur ?? [];
      });
    }
  }, [api, showFixed, onCountChange, toast]);

  useEffect(() => { setAlerts(null); load(); }, [load]);
  useLiveRefresh(load);

  async function resolve(id) {
    try { await api("POST", `/admin/system-alerts/${id}/resolve`); load(); } catch (e) { toast(e.message, "error"); }
  }

  async function resolveAll() {
    try { await api("POST", "/admin/system-alerts/resolve-all"); load(); } catch (e) { toast(e.message, "error"); }
  }

  return (
    <>
      <LaunchChecklist api={api} />
      <p className="inbox-tab-desc">{t("ow.sys.desc")}</p>
      <div className="dash-toolbar">
        <div className="filter-pills">
          {[[false, "ow.sys.open"], [true, "ow.sys.fixedTab"]].map(([fixed, key]) => (
            <button key={key} type="button" className={`pill-btn pill-tone-neutral ${showFixed === fixed ? "active" : ""}`} onClick={() => setShowFixed(fixed)}>
              {t(key)}
            </button>
          ))}
        </div>
        {!showFixed && alerts?.length > 0 && (
          <button type="button" className="btn-sm" onClick={resolveAll}>{t("ow.sys.fixAll")}</button>
        )}
      </div>

      {alerts === null && (
        <div style={{ display: "grid", gap: 10 }}>
          {Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} h={84} br={12} />)}
        </div>
      )}

      {alerts?.length === 0 && (
        <EmptyState icon={EMPTY_ICONS.check}
          title={showFixed ? t("ow.sys.fixedEmpty") : t("ow.sys.clear")}
          description={showFixed ? null : t("ow.sys.clearDesc")} />
      )}

      {alerts?.map((a) => {
        const level = LEVEL[a.level] || LEVEL.warning;
        const details = Object.entries(a.context || {});
        return (
          <article key={a.id} className={`sys-alert ${level.tone}`}>
            <div className="sys-alert-top">
              <span className={`sys-level ${level.tone}`}>{t(level.key)}</span>
              <span className="sys-meta">
                {t("ow.sys.times", { n: a.occurrences })} · {timeAgo(a.last_seen_at, locale)}
                {a.notified_at && <> · {t("ow.sys.notified")}</>}
              </span>
            </div>
            <p className="sys-msg" dir="auto">{a.message}</p>
            {a.shop_name && <p className="sys-shop" dir="auto">{a.shop_name}</p>}
            <div className="sys-actions">
              {details.length > 0 && (
                <button type="button" className="sys-link" onClick={() => setOpen(open === a.id ? null : a.id)} aria-expanded={open === a.id}>
                  {open === a.id ? t("ow.sys.hide") : t("ow.sys.details")}
                </button>
              )}
              {!a.resolved_at && <button type="button" className="btn-sm" onClick={() => resolve(a.id)}>{t("ow.sys.fix")}</button>}
            </div>
            {open === a.id && (
              <dl className="sys-details" dir="ltr">
                {details.map(([k, v]) => (
                  <div key={k}><dt>{k}</dt><dd>{typeof v === "string" ? v : JSON.stringify(v)}</dd></div>
                ))}
              </dl>
            )}
          </article>
        );
      })}
    </>
  );
}
