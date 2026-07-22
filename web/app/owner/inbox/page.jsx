"use client";

import { Suspense, useCallback, useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { useOwner } from "../layout";
import { buildInboxAlerts } from "@/lib/inbox";
import { getActivity, clearActivity } from "@/lib/activity";
import { timeAgo } from "@/lib/api";
import { ConfirmModal, Skeleton } from "@/components/ui";
import { PageHeader, EmptyState } from "@/components/owner/ui";
import { INBOX_ALERT_FILTERS, INBOX_TABS, pillClass } from "@/lib/pillTones";

const HISTORY_META = {
  merchant: { label: "Merchant", color: "var(--green)" },
  billing: { label: "Billing", color: "#818cf8" },
  bot: { label: "Bot", color: "var(--red)" },
  broadcast: { label: "Broadcast", color: "var(--blue)" },
  system: { label: "System", color: "var(--text-muted)" },
};

function dayLabel(iso) {
  const d = new Date(iso);
  const today = new Date();
  const yesterday = new Date(Date.now() - 86400000);
  const same = (a, b) => a.toDateString() === b.toDateString();
  if (same(d, today)) return "Today";
  if (same(d, yesterday)) return "Yesterday";
  return d.toLocaleDateString("en-US", { weekday: "long", month: "long", day: "numeric" });
}

export default function InboxPage() {
  return (
    <Suspense fallback={
      <div>
        <PageHeader title="Inbox" subtitle="Loading…" />
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
  const router = useRouter();
  const searchParams = useSearchParams();
  const tab = searchParams.get("tab") === "history" ? "history" : "alerts";

  const [alerts, setAlerts] = useState([]);
  const [history, setHistory] = useState([]);
  const [loading, setLoading] = useState(true);
  const [alertFilter, setAlertFilter] = useState("all");
  const [confirm, setConfirm] = useState(null);

  const loadAlerts = useCallback(async () => {
    try {
      const [{ merchants = [] }, { requests = [] }] = await Promise.all([
        api("GET", "/api/owner/merchants?limit=100"),
        api("GET", "/api/owner/onboarding"),
      ]);
      setAlerts(buildInboxAlerts(merchants, requests));
    } catch { setAlerts([]); }
    setLoading(false);
  }, [api]);

  useEffect(() => { loadAlerts(); }, [loadAlerts]);
  useEffect(() => { setHistory(getActivity()); }, [tab]);

  function setTab(next) {
    const q = next === "history" ? "?tab=history" : "";
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
    const label = dayLabel(e.at);
    const last = historyGroups[historyGroups.length - 1];
    if (last && last.label === label) last.items.push(e);
    else historyGroups.push({ label, items: [e] });
  }

  return (
    <div>
      <PageHeader
        title="Inbox"
        subtitle={tab === "alerts"
          ? (alerts.length ? `${alerts.length} item${alerts.length !== 1 ? "s" : ""} need your action` : "Nothing urgent right now")
          : `${history.length} completed action${history.length !== 1 ? "s" : ""} logged`}
      >
        {tab === "history" && history.length > 0 && (
          <button type="button" className="btn-sm btn-sm-red" onClick={() => setConfirm({
            title: "Clear history",
            message: "Delete all logged actions? This cannot be undone.",
            confirmLabel: "Clear history",
            danger: true,
            onConfirm: () => { clearActivity(); setHistory([]); },
          })}>
            Clear history
          </button>
        )}
      </PageHeader>

      <div className="filter-pills filter-pills-bar">
        {INBOX_TABS.map((t) => (
          <button key={t.id} type="button" className={pillClass(t.tone, tab === t.id)} onClick={() => setTab(t.id)}>
            {t.label}
            {t.id === "alerts" && alerts.length > 0 && <span className="pill-count">{alerts.length}</span>}
            {t.id === "history" && history.length > 0 && <span className="pill-count">{history.length}</span>}
          </button>
        ))}
      </div>

      {tab === "alerts" && (
        <>
          <p className="inbox-tab-desc">Live issues across your platform — fix these to keep merchants running.</p>
          <div className="filter-row">
            <div className="filter-pills">
              {INBOX_ALERT_FILTERS.map((f) => (
                <button key={f.id} type="button" className={pillClass(f.tone, alertFilter === f.id)} onClick={() => setAlertFilter(f.id)}>
                  {f.label} <span className="pill-count">{alertCounts[f.id] ?? 0}</span>
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
            <EmptyState icon="✓" title="All clear" description="No merchants need your attention right now. New join requests, bot errors, and overdue payments will show up here." />
          )}

          {!loading && alertList.map((item) => (
            <button key={item.id} type="button" className={`alert-card alert-${item.category}`} onClick={() => openAlert(item)}>
              <div className="alert-card-accent" style={{ background: item.color }} />
              <div className="alert-card-body">
                <div className="alert-card-top">
                  <span className="alert-card-title">{item.title}</span>
                  <span className="alert-card-action">Take action →</span>
                </div>
                <div className="alert-card-sub" style={{ color: item.color }}>{item.sub}</div>
                {item.detail && <div className="alert-card-detail">{item.detail}</div>}
              </div>
            </button>
          ))}
        </>
      )}

      {tab === "history" && (
        <>
          <p className="inbox-tab-desc">A record of actions you&apos;ve already taken — approvals, payments, bot fixes, and settings changes.</p>

          {history.length === 0 && (
            <EmptyState icon="📝" title="No history yet" description="When you approve merchants, mark payments, or update bots, those completed actions will appear here." />
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
                        <div className="history-msg">{e.message}</div>
                        <div className="history-meta">
                          <span style={{ color: meta.color, fontWeight: 700 }}>{meta.label}</span>
                          {" · "}{timeAgo(e.at)}
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

      {confirm && <ConfirmModal {...confirm} onClose={() => setConfirm(null)} />}
    </div>
  );
}
