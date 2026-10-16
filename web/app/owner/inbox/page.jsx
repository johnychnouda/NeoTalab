"use client";

import { Suspense, useCallback, useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { useOwner } from "../layout";
import { buildInboxAlerts } from "@/lib/inbox";
import { getActivity } from "@/lib/activity";
import { useLiveRefresh } from "@/lib/live";
import { timeAgo } from "@/lib/api";
import { Skeleton } from "@/components/ui";
import { PageHeader, EmptyState, EMPTY_ICONS } from "@/components/owner/ui";
import SystemAlerts from "@/components/owner/SystemAlerts";
import { INBOX_ALERT_FILTERS, INBOX_TABS, pillClass } from "@/lib/pillTones";
import { useLang } from "@/lib/i18n";

const HISTORY_META = {
  merchant: { key: "ow.hist.merchant", color: "var(--green)" },
  billing: { key: "ow.f.billing", color: "#818cf8" },
  bot: { key: "ow.f.whatsapp", color: "var(--red)" },
  system: { key: "ow.nav.settings", color: "var(--text-muted)" },
};

function dayLabel(iso, t, locale) {
  const d = new Date(iso);
  const today = new Date();
  const yesterday = new Date(Date.now() - 86400000);
  const same = (a, b) => a.toDateString() === b.toDateString();
  if (same(d, today)) return t("ow.today");
  if (same(d, yesterday)) return t("ow.yesterday");
  return d.toLocaleDateString(locale, { weekday: "long", month: "long", day: "numeric" });
}

export default function InboxPage() {
  const { t } = useLang();
  return (
    <Suspense fallback={
      <div>
        <PageHeader title={t("ow.nav.inbox")} subtitle={t("db.loading")} />
        <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} h={88} br={12} />
          ))}
        </div>
      </div>
    }>
      <InboxContent />
    </Suspense>
  );
}

function InboxContent() {
  const { api } = useOwner();
  const { t, lang, locale } = useLang();
  const router = useRouter();
  const searchParams = useSearchParams();
  const tab = ["history", "system"].includes(searchParams.get("tab")) ? searchParams.get("tab") : "alerts";

  const [alerts, setAlerts] = useState([]);
  const [history, setHistory] = useState([]);
  const [loading, setLoading] = useState(true);
  const [alertFilter, setAlertFilter] = useState("all");
  const [systemCount, setSystemCount] = useState(0);

  const loadAlerts = useCallback(async () => {
    try {
      const [{ merchants = [] }, { requests = [] }] = await Promise.all([
        api("GET", "/admin/merchants"),
        api("GET", "/admin/onboarding"),
      ]);
      setAlerts(buildInboxAlerts(merchants, requests, t));
    } catch { /* keep what is on screen; the next refresh tries again */ }
    setLoading(false);
    // eslint-disable-next-line react-hooks/exhaustive-deps -- `t` is rebuilt every render; `lang` is what changes it.
  }, [api, lang]);

  const loadSystemCount = useCallback(
    () => api("GET", "/admin/system-alerts").then(({ alerts: list = [] }) => setSystemCount(list.length)).catch(() => {}),
    [api],
  );
  const loadHistory = useCallback(() => getActivity().then(setHistory).catch(() => {}), []);

  useEffect(() => { loadAlerts(); }, [loadAlerts]);
  useEffect(() => { loadSystemCount(); }, [loadSystemCount]);
  useEffect(() => { if (tab === "history") loadHistory(); }, [tab, loadHistory]);
  useLiveRefresh(() => Promise.all([loadAlerts(), loadSystemCount(), tab === "history" ? loadHistory() : null]));

  function setTab(next) {
    const q = next === "alerts" ? "" : `?tab=${next}`;
    router.replace(`/owner/inbox${q}`);
  }

  function openAlert(item) {
    router.push(item.actionUrl || "/owner");
  }

  const alertList = alertFilter === "all" ? alerts : alerts.filter((i) => i.category === alertFilter);
  const alertCounts = {
    all: alerts.length,
    requests: alerts.filter((i) => i.category === "requests").length,
    bots: alerts.filter((i) => i.category === "bots").length,
    billing: alerts.filter((i) => i.category === "billing").length,
  };

  const historyGroups = [];
  for (const e of history) {
    const label = dayLabel(e.at, t, locale);
    const last = historyGroups[historyGroups.length - 1];
    if (last && last.label === label) last.items.push(e);
    else historyGroups.push({ label, items: [e] });
  }

  return (
    <div>
      <PageHeader
        title={t("ow.nav.inbox")}
        subtitle={tab === "alerts"
          ? (alerts.length ? t("ow.inbox.needAction", { n: alerts.length }) : t("ow.inbox.nothing"))
          : tab === "system" ? t("ow.sys.sub") : t("ow.inbox.historySub")}
      />

      <div className="filter-pills filter-pills-bar">
        {INBOX_TABS.map((p) => (
          <button key={p.id} type="button" className={pillClass(p.tone, tab === p.id)} onClick={() => setTab(p.id)}>
            {t(p.key)}
            {p.id === "alerts" && alerts.length > 0 && <span className="pill-count">{alerts.length}</span>}
            {p.id === "system" && systemCount > 0 && <span className="pill-count">{systemCount}</span>}
          </button>
        ))}
      </div>

      {tab === "alerts" && (
        <>
          <p className="inbox-tab-desc">{t("ow.inbox.alertsDesc")}</p>
          <div className="filter-row">
            <div className="filter-pills">
              {INBOX_ALERT_FILTERS.map((f) => (
                <button key={f.id} type="button" className={pillClass(f.tone, alertFilter === f.id)} onClick={() => setAlertFilter(f.id)}>
                  {t(f.key)} <span className="pill-count">{alertCounts[f.id] ?? 0}</span>
                </button>
              ))}
            </div>
          </div>

          {loading && (
            <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
              {Array.from({ length: 4 }).map((_, i) => (
                <Skeleton key={i} h={88} br={12} />
              ))}
            </div>
          )}

          {!loading && alertList.length === 0 && (
            <EmptyState icon={EMPTY_ICONS.check} title={t("ow.inbox.clear")} description={t("ow.inbox.clearDesc")} />
          )}

          {!loading && alertList.map((item) => (
            <button key={item.id} type="button" className={`alert-card alert-${item.category}`} onClick={() => openAlert(item)}>
              <div className="alert-card-accent" style={{ background: item.color }} />
              <div className="alert-card-body">
                <div className="alert-card-top">
                  <span className="alert-card-title">{item.title}</span>
                  <span className="alert-card-action">{t("ow.inbox.act")}</span>
                </div>
                <div className="alert-card-sub" style={{ color: item.color }}>{item.sub}</div>
                {item.detail && <div className="alert-card-detail">{item.detail}</div>}
              </div>
            </button>
          ))}
        </>
      )}

      {tab === "system" && <SystemAlerts api={api} onCountChange={setSystemCount} />}

      {tab === "history" && (
        <>
          <p className="inbox-tab-desc">{t("ow.inbox.historyDesc")}</p>

          {history.length === 0 && (
            <EmptyState icon={EMPTY_ICONS.history} title={t("ow.inbox.noHistory")} description={t("ow.inbox.noHistoryDesc")} />
          )}

          {historyGroups.map((g) => (
            <div key={g.label} className="history-day">
              <div className="history-day-label">{g.label}</div>
              <div className="history-list">
                {g.items.map((e) => {
                  const meta = HISTORY_META[e.type] || HISTORY_META.system;
                  return (
                    <div key={e.id} className="history-row">
                      <div className="history-dot" style={{ background: meta.color }} />
                      <div className="history-row-body">
                        <div className="history-msg" dir="auto">{e.message}</div>
                        <div className="history-meta">
                          <span style={{ color: meta.color, fontWeight: 700 }}>{t(meta.key)}</span>
                          {" · "}{timeAgo(e.at, locale)}
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          ))}
        </>
      )}

    </div>
  );
}
