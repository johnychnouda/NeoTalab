"use client";

import { useCallback, useEffect, useState } from "react";
import { useOwner } from "../layout";
import { getRenewalDate, merchantFee } from "@/lib/api";
import { logActivity } from "@/lib/activity";
import { Modal, ConfirmModal, useToast, WhatsAppIcon, Skeleton } from "@/components/ui";
import { PageHeader } from "@/components/owner/ui";
import { BILLING_FILTERS, pillClass } from "@/lib/pillTones";
import { useLang } from "@/lib/i18n";
import { useLiveRefresh } from "@/lib/live";
import { trialDaysLeft as trialDaysLeftFrom, calendarDaysBetween } from "@/lib/trial";

const SEVEN_DAYS = 7 * 24 * 60 * 60 * 1000;
/** Pending payments this many days past the renewal date count as overdue. */
const OVERDUE_DAYS = 3;

/** WhatsApp text the owner sends from their own phone to a shop that hasn't paid. */
function buildReminderMessage(t, locale, m) {
  const rd = getRenewalDate(m);
  return t("ow.msg.reminder", {
    shop: m.shop_name,
    date: rd ? rd.toLocaleDateString(locale, { month: "long", day: "numeric", year: "numeric" }) : "—",
    amount: `$${merchantFee(m)}`,
  });
}

export default function BillingPage() {
  const { api } = useOwner();
  const { t, locale } = useLang();
  const toast = useToast();

  const [merchants, setMerchants] = useState([]);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState("");
  const [confirm, setConfirm] = useState(null);
  const [remindersModal, setRemindersModal] = useState(false);

  const load = useCallback(async () => {
    try {
      const { merchants: m } = await api("GET", "/admin/merchants");
      setMerchants(m);
    } catch (e) { console.error(e); }
    setLoading(false);
  }, [api]);

  useEffect(() => { load(); }, [load]);
  useLiveRefresh(load);

  // Deep-link: ?m=<id> scrolls to merchant row, ?filter=pending|trial pre-selects filter
  useEffect(() => {
    if (!merchants.length) return;
    const params = new URLSearchParams(window.location.search);
    const mid = params.get("m");
    const filterParam = params.get("filter");
    if (filterParam) setFilter(filterParam);
    if (mid) {
      setTimeout(() => {
        const row = document.getElementById(`billing-row-${mid}`);
        row?.scrollIntoView({ behavior: "smooth", block: "center" });
        row?.classList.add("action-highlight");
        setTimeout(() => row?.classList.remove("action-highlight"), 2500);
      }, 150);
    }
    if (mid || filterParam) {
      window.history.replaceState({}, "", "/owner/billing");
    }
  }, [merchants]);

  const now = new Date();
  const thisMonth = now.getMonth();
  const thisYear = now.getFullYear();

  const mrrMerchants = merchants.filter((m) => m.subscription_status === "paid");
  const mrr = Math.round(mrrMerchants.reduce((s, m) => s + (m.billing_cycle === "yearly" ? (m.yearly_fee || 0) / 12 : (m.monthly_fee || 0)), 0));
  const arr = mrr * 12;

  const collectedMerchants = merchants.filter((m) => {
    if (!m.last_payment_at) return false;
    const d = new Date(m.last_payment_at);
    return d.getMonth() === thisMonth && d.getFullYear() === thisYear;
  });
  const collected = collectedMerchants.reduce((s, m) => s + merchantFee(m), 0);
  const yearMerchants = merchants.filter((m) => m.last_payment_at && new Date(m.last_payment_at).getFullYear() === thisYear);
  const yearCollected = yearMerchants.reduce((s, m) => s + merchantFee(m), 0);

  const pendingList = merchants.filter((m) => m.subscription_status === "pending");
  const pendingAmount = pendingList.reduce((s, m) => s + merchantFee(m), 0);
  const trialCount = merchants.filter((m) => m.subscription_status === "trial").length;

  const overdueList = merchants.filter((m) => {
    if (m.subscription_status !== "pending") return false;
    const rd = getRenewalDate(m);
    return rd && (Date.now() - rd) >= OVERDUE_DAYS * 86400000;
  });

  const monthName = now.toLocaleDateString(locale, { month: "long" });
  const fmt = (n) => "$" + n.toLocaleString("en-US");

  let list = filter ? merchants.filter((m) => m.subscription_status === filter) : [...merchants];
  const urgency = (m) => {
    const rd = getRenewalDate(m);
    const diff = rd ? rd - now : Infinity;
    if (m.subscription_status === "pending" && rd && diff < 0) return 0;
    if (m.subscription_status === "trial" && rd && diff >= 0 && diff < SEVEN_DAYS) return 1;
    if (m.subscription_status === "paid" && rd && diff >= 0 && diff < SEVEN_DAYS) return 2;
    if (m.subscription_status === "pending") return 3;
    if (m.subscription_status === "trial") return 4;
    return 5;
  };
  list.sort((a, b) => urgency(a) - urgency(b));


  function sendReminder(m) {
    const clean = (m.whatsapp_number || "").replace(/\D/g, "");
    if (!clean) { toast(t("ow.p.noNumber"), "error"); return; }
    window.open(`https://wa.me/${clean}?text=${encodeURIComponent(buildReminderMessage(t, locale, m))}`, "_blank");
    logActivity("billing", t("ow.log.reminded", { shop: m.shop_name }));
  }

  function exportCSV() {
    if (!merchants.length) { toast(t("ow.b.nothingToExport"), "error"); return; }
    const headers = ["Shop", "WhatsApp", "Monthly Fee", "Yearly Fee", "Billing Cycle", "Subscription Status", "Last Payment", "Next Renewal", "Total Collected"];
    const day = (d) => new Date(d).toISOString().split("T")[0];
    const rows = merchants.map((m) => {
      const renewal = getRenewalDate(m);
      return [
        m.shop_name, m.whatsapp_number || "",
        m.monthly_fee || 0, m.yearly_fee || 0, m.billing_cycle || "monthly",
        m.subscription_status || "",
        m.last_payment_at ? day(m.last_payment_at) : "",
        renewal ? day(renewal) : "",
        m.total_fees_collected || 0,
      ].map((v) => `"${String(v).replace(/"/g, '""')}"`).join(",");
    });
    const csv = [headers.join(","), ...rows].join("\n");
    const blob = new Blob([csv], { type: "text/csv" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `neotalab-billing-${new Date().toISOString().split("T")[0]}.csv`;
    a.click();
    URL.revokeObjectURL(url);
    toast(t("ow.b.exported"));
  }

  const pillCounts = {
    "": merchants.length,
    paid: mrrMerchants.length,
    pending: pendingList.length,
    trial: trialCount,
  };

  const head = (
    <thead>
      <tr><th>{t("ow.b.shop")}</th><th>{t("ow.b.fee")}</th><th>{t("ow.b.nextRenewal")}</th><th>{t("th.status")}</th></tr>
    </thead>
  );

  if (loading) {
    return (
      <div>
        <PageHeader title={t("ow.f.billing")} />
        <div className="billing-stats">
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i} className="stat-card">
              <Skeleton w="60%" h={11} />
              <Skeleton w="45%" h={26} style={{ marginTop: 12 }} />
              <Skeleton w="70%" h={11} style={{ marginTop: 10 }} />
            </div>
          ))}
        </div>
        <div className="table-scroll">
          <table className="data-table stack-on-phone">
            {head}
            <tbody>
              {Array.from({ length: 6 }).map((_, i) => (
                <tr key={i}>
                  <td><Skeleton w="65%" h={13} /></td>
                  <td><Skeleton w={54} h={13} /></td>
                  <td><Skeleton w="55%" h={13} /></td>
                  <td><Skeleton w={68} h={20} br={20} /></td>
                  <td><Skeleton w={90} h={26} br={6} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        title={t("ow.f.billing")}
        subtitle={t("ow.b.sub", { paying: mrrMerchants.length, pending: pendingList.length, overdue: overdueList.length })}
      />

      <div className="billing-stats">
        <div className="stat-card">
          <div className="stat-label">{t("ow.b.mrr")}</div>
          <div className="stat-value">{fmt(mrr)}<span className="stat-unit">{t("ow.perMonth")}</span></div>
          <div className="stat-sub">{t("ow.b.fromPaying", { n: mrrMerchants.length })}</div>
          <div className="stat-card-foot">
            <span className="stat-card-foot-label">{t("ow.b.yearProjection")}</span>
            <span className="stat-card-foot-value">{fmt(arr)}{t("ow.perYear")}</span>
          </div>
        </div>
        <div className="stat-card">
          <div className="stat-label">{t("ow.b.collected")} · {monthName} {thisYear}</div>
          <div className="stat-value is-good">{fmt(collected)}</div>
          <div className="stat-sub">{t("ow.b.payments", { n: collectedMerchants.length })}</div>
        </div>
        <div className="stat-card">
          <div className="stat-label">{t("ow.b.collected")} · {thisYear}</div>
          <div className="stat-value is-good">{fmt(yearCollected)}</div>
          <div className="stat-sub">{t("ow.b.payments", { n: yearMerchants.length })}</div>
        </div>
        <div className="stat-card">
          <div className="stat-label">{t("ow.b.outstanding")}</div>
          <div className={`stat-value ${pendingAmount > 0 ? "is-warn" : "is-good"}`}>{fmt(pendingAmount)}</div>
          <div className="stat-sub">
            {pendingList.length > 0 ? t("ow.b.awaiting", { n: pendingList.length }) : t("ow.b.upToDate")}
          </div>
        </div>
      </div>

      <div className="dash-toolbar">
        <div className="filter-pills">
          {BILLING_FILTERS.map((p) => (
            <button key={p.id} type="button" className={pillClass(p.tone, filter === p.id)} onClick={() => setFilter(p.id)}>
              {t(p.key)} <span className="pill-count">{pillCounts[p.id] ?? 0}</span>
            </button>
          ))}
        </div>
        <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
          {overdueList.length > 0 && (
            <button type="button" onClick={() => setRemindersModal(true)} style={{
              display: "inline-flex", alignItems: "center", gap: 6, whiteSpace: "nowrap",
              padding: "6px 14px", borderRadius: 8, fontSize: 12, fontWeight: 700,
              background: "#ff4d4d18", border: "1px solid #ff4d4d40", color: "var(--red)", cursor: "pointer",
            }}>
              📨 {t("ow.b.remindAll")}
              <span style={{ background: "#ff4d4d", color: "#fff", borderRadius: 10, padding: "0 6px", fontSize: 10, fontWeight: 800 }}>{overdueList.length}</span>
            </button>
          )}
          <button type="button" className="btn-sm" onClick={exportCSV}>⬇ {t("ow.b.export")}</button>
        </div>
      </div>

      <div className="table-scroll">
        <table className="data-table stack-on-phone">
          {head}
          <tbody>
            {list.length === 0 && <tr><td colSpan={4} style={{ textAlign: "center", color: "var(--text-muted)", padding: 32 }}>{t("ow.m.none")}</td></tr>}
            {list.map((m) => <BillingRow key={m.id} t={t} locale={locale} m={m} now={now} onRemind={() => sendReminder(m)} />)}
          </tbody>
        </table>
      </div>

      {remindersModal && (
        <Modal title={`${t("ow.b.remindAll")} (${overdueList.length})`} onClose={() => setRemindersModal(false)}>
          <p style={{ color: "var(--text-muted)", fontSize: 13, marginBottom: 16, lineHeight: 1.5 }}>{t("ow.b.remindHint")}</p>
          <div style={{ display: "flex", flexDirection: "column", gap: 8, marginBottom: 16 }}>
            {overdueList.map((m) => {
              const rd = getRenewalDate(m);
              const daysOverdue = rd ? Math.max(0, calendarDaysBetween(rd, new Date())) : 0;
              const clean = (m.whatsapp_number || "").replace(/\D/g, "");
              const waUrl = `https://wa.me/${clean}?text=${encodeURIComponent(buildReminderMessage(t, locale, m))}`;
              return (
                <div key={m.id} style={{ display: "flex", alignItems: "center", justifyContent: "space-between", background: "var(--surface2)", border: "1px solid var(--border)", borderRadius: 8, padding: "10px 14px" }}>
                  <div>
                    <div style={{ fontWeight: 700, fontSize: 13 }}>{m.shop_name}</div>
                    <div style={{ fontSize: 11, color: "var(--red)", marginTop: 2 }}>{t("ow.inbox.overdue", { n: daysOverdue })}</div>
                  </div>
                  <a href={waUrl} target="_blank" rel="noreferrer" onClick={() => logActivity("billing", t("ow.log.reminded", { shop: m.shop_name }))}
                    style={{ display: "inline-flex", alignItems: "center", gap: 6, padding: "6px 12px", background: "#25d36618", border: "1px solid #25d36640", borderRadius: 6, color: "#25d366", fontSize: 12, fontWeight: 700, textDecoration: "none" }}>
                    <WhatsAppIcon /> {t("ow.send")}
                  </a>
                </div>
              );
            })}
          </div>
          <div className="modal-actions">
            <button type="button" className="btn-primary" onClick={() => setRemindersModal(false)}>{t("ow.done")}</button>
          </div>
        </Modal>
      )}

      {confirm && <ConfirmModal {...confirm} onClose={() => setConfirm(null)} />}
    </div>
  );
}

function BillingRow({ t, locale, m, now, onRemind }) {
  const isPaid = m.subscription_status === "paid";
  const isTrial = m.subscription_status === "trial";
  const isPending = m.subscription_status === "pending";
  const isYearly = m.billing_cycle === "yearly";
  const fee = isYearly ? (m.yearly_fee || 0) : (m.monthly_fee || 0);

  const billingColor = isPaid ? "var(--green)" : isTrial ? "#3b82f6" : "var(--orange)";
  const trialDaysLeft = isTrial ? trialDaysLeftFrom(m.trial_ends_at) : null;
  const billingLabel = isPaid ? `✓ ${t("ow.f.paid")}` : isTrial ? (trialDaysLeft !== null ? t("ow.trialDaysShort", { n: trialDaysLeft }) : t("ow.f.trial")) : t("ow.f.pending");

  const renewalDate = getRenewalDate(m);
  const renewalStr = renewalDate
    ? renewalDate.toLocaleDateString(locale, { month: "short", day: "numeric", year: "numeric" })
    : "—";

  let alertBadge = null;
  let renewalStyle = { color: "var(--text-muted)", fontSize: 12 };
  if (renewalDate) {
    const diff = renewalDate - now;
    const badge = (text, color) => (
      <span style={{ background: `${color}22`, color, fontSize: 10, fontWeight: 700, padding: "2px 6px", borderRadius: 4, marginInlineStart: 6, verticalAlign: "middle" }}>{text}</span>
    );
    if (isPending && diff < 0) { alertBadge = badge(t("ow.b.badgeOverdue"), "var(--red)"); renewalStyle = { color: "var(--red)", fontSize: 12, fontWeight: 600 }; }
    else if (isPaid && diff >= 0 && diff < SEVEN_DAYS) { alertBadge = badge(t("ow.b.badgeSoon"), "#f59e0b"); renewalStyle = { color: "#f59e0b", fontSize: 12, fontWeight: 600 }; }
    else if (isTrial && diff >= 0 && diff < SEVEN_DAYS) { alertBadge = badge(t("ow.b.badgeSoon"), "#f59e0b"); renewalStyle = { color: "#f59e0b", fontSize: 12, fontWeight: 600 }; }
    else if (isTrial) { renewalStyle = { color: "#3b82f6", fontSize: 12 }; }
  }

  const isOverdue = isPending && renewalDate && (renewalDate - now) < 0;
  const waClean = (m.whatsapp_number || "").replace(/\D/g, "");

  return (
    <tr id={`billing-row-${m.id}`}>
      <td className="stack-title"><strong>{m.shop_name}</strong></td>
      <td data-label={t("ow.b.fee")}><strong>${fee}</strong>{t(isYearly ? "ow.perYear" : "ow.perMonth")}</td>
      <td data-label={t("ow.b.nextRenewal")}>
        <span style={renewalStyle}>{renewalStr}</span>
        {alertBadge}
      </td>
      <td data-label={t("th.status")}>
        <div className="billing-row-actions">
          <span style={{ color: billingColor, fontWeight: 600, fontSize: 12 }}>{billingLabel}</span>
          {isOverdue && waClean && (
            <button type="button" className="btn-sm" style={{ color: "#f59e0b", borderColor: "#f59e0b40" }} onClick={onRemind}>📨 {t("ow.b.remind")}</button>
          )}
        </div>
      </td>
    </tr>
  );
}
