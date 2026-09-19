"use client";

import { useCallback, useEffect, useState } from "react";
import { useOwner } from "../layout";
import { getRenewalDate, merchantFee } from "@/lib/api";
import { logActivity } from "@/lib/activity";
import { Modal, ConfirmModal, useToast, WhatsAppIcon, Skeleton } from "@/components/ui";
import { PageHeader } from "@/components/owner/ui";
import { BILLING_FILTERS, pillClass } from "@/lib/pillTones";

const SEVEN_DAYS = 7 * 24 * 60 * 60 * 1000;

function buildReminderMessage(m) {
  const template = (typeof window !== "undefined" && localStorage.getItem("nt_overdue_msg")) ||
    `Hello {shop} 👋\n\nYour NeoTalab subscription renews in {days} day(s) — on {date}.\n\n💳 Amount due: ${"{amount}"}\n\nPlease settle your payment before then to keep your WhatsApp bot running smoothly.\n\nNeed help? Just reply here! 🙌`;
  const rd = getRenewalDate(m);
  const daysLeft = rd ? Math.max(0, Math.ceil((rd - Date.now()) / 86400000)) : "—";
  const dateStr = rd
    ? rd.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" })
    : "—";
  return template
    .replace(/{shop}/g, m.shop_name)
    .replace(/{days}/g, daysLeft)
    .replace(/{date}/g, dateStr)
    .replace(/{amount}/g, merchantFee(m));
}

export default function BillingPage() {
  const { api } = useOwner();
  const toast = useToast();

  const [merchants, setMerchants] = useState([]);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState("");
  const [confirm, setConfirm] = useState(null);
  const [remindersModal, setRemindersModal] = useState(false);

  const load = useCallback(async () => {
    try {
      const { merchants: m } = await api("GET", "/api/owner/merchants?limit=100");
      setMerchants(m);
    } catch (e) { console.error(e); }
    setLoading(false);
  }, [api]);

  useEffect(() => { load(); }, [load]);

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

  const thresholdDays = parseInt((typeof window !== "undefined" && localStorage.getItem("nt_overdue_remind_days")) || "3");
  const overdueList = merchants.filter((m) => {
    if (m.subscription_status !== "pending") return false;
    const rd = getRenewalDate(m);
    return rd && (Date.now() - rd) >= thresholdDays * 86400000;
  });

  const monthName = now.toLocaleDateString("en-US", { month: "long" });
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

  function markPaid(m) {
    const cycle = m.billing_cycle === "yearly" ? "year" : "month";
    const fee = m.billing_cycle === "yearly" ? `$${m.yearly_fee || 0}/yr` : `$${m.monthly_fee || 0}/mo`;
    const isTrial = m.subscription_status === "trial";
    setConfirm({
      title: "Mark as Paid",
      message: isTrial
        ? `End the trial early and mark <strong>${m.shop_name}</strong> as paid for this ${cycle}? <span style="color:var(--text-muted);font-size:12px">(${fee})</span>`
        : `Mark <strong>${m.shop_name}</strong> as paid for this ${cycle}? <span style="color:var(--text-muted);font-size:12px">(${fee})</span>`,
      confirmLabel: "Mark as Paid",
      onConfirm: async () => {
        try {
          await api("PATCH", `/api/owner/merchants/${m.id}`, {
            subscriptionStatus: "paid",
            lastPaymentAt: new Date().toISOString().split("T")[0],
            status: "active",
          });
          logActivity("billing", `${m.shop_name} marked as paid — ${fee}`);
          toast(`${m.shop_name} marked as paid`);
          load();
        } catch (e) { toast(e.message, "error"); }
      },
    });
  }

  function sendReminder(m) {
    const clean = (m.whatsapp_number || "").replace(/\D/g, "");
    if (!clean) { toast("No WhatsApp number on file", "error"); return; }
    window.open(`https://wa.me/${clean}?text=${encodeURIComponent(buildReminderMessage(m))}`, "_blank");
    logActivity("billing", `Overdue reminder sent to ${m.shop_name} via WhatsApp`);
    toast(`Reminder opened for ${m.shop_name}`);
  }

  function exportCSV() {
    if (!merchants.length) { toast("No billing data to export", "error"); return; }
    const headers = ["Shop", "WhatsApp", "Plan", "Monthly Fee", "Yearly Fee", "Billing Cycle", "Subscription Status", "Last Payment", "Next Renewal", "Total Collected"];
    const rows = merchants.map((m) => {
      const renewal = getRenewalDate(m);
      return [
        m.shop_name, m.whatsapp_number || "", m.plan || "basic",
        m.monthly_fee || 0, m.yearly_fee || 0, m.billing_cycle || "monthly",
        m.subscription_status || "",
        m.last_payment_at ? new Date(m.last_payment_at).toLocaleDateString() : "—",
        renewal ? new Date(renewal).toLocaleDateString() : "—",
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
    toast("Billing exported!");
  }

  const pillCounts = {
    "": merchants.length,
    paid: mrrMerchants.length,
    pending: pendingList.length,
    trial: trialCount,
  };

  if (loading) {
    return (
      <div>
        <PageHeader title="Billing" />
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
<table className="data-table">
          <thead><tr><th>Shop</th><th>Fee</th><th>Next Renewal</th><th>Status</th><th>Action</th></tr></thead>
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
        title="Billing"
        subtitle={`${mrrMerchants.length} paying · ${pendingList.length} pending · ${overdueList.length} overdue`}
      />

      <div className="billing-stats">
        <div className="stat-card">
          <div className="stat-label">Monthly Revenue</div>
          <div className="stat-value">{fmt(mrr)}/mo</div>
          <div style={{ fontSize: 12, color: "var(--text-muted)", marginTop: 6 }}>
            from {mrrMerchants.length} paying merchant{mrrMerchants.length !== 1 ? "s" : ""}
          </div>
          <div style={{ marginTop: 14, paddingTop: 12, borderTop: "1px solid var(--border)", display: "flex", justifyContent: "space-between", alignItems: "center" }}>
            <span style={{ fontSize: 12, color: "var(--text-muted)" }}>Yearly projection</span>
            <span style={{ fontSize: 14, fontWeight: 700, color: "#818cf8" }}>{fmt(arr)}/yr</span>
          </div>
        </div>
        <div className="stat-card">
          <div className="stat-label">Collected · {monthName} {thisYear}</div>
          <div className="stat-value" style={{ color: "var(--green)" }}>{fmt(collected)}</div>
          <div style={{ fontSize: 12, color: "var(--text-muted)", marginTop: 6 }}>
            {collectedMerchants.length} payment{collectedMerchants.length !== 1 ? "s" : ""} received
          </div>
        </div>
        <div className="stat-card">
          <div className="stat-label">Collected · {thisYear}</div>
          <div className="stat-value" style={{ color: "var(--green)" }}>{fmt(yearCollected)}</div>
          <div style={{ fontSize: 12, color: "var(--text-muted)", marginTop: 6 }}>
            {yearMerchants.length} payment{yearMerchants.length !== 1 ? "s" : ""} since Jan 1
          </div>
        </div>
        <div className="stat-card">
          <div className="stat-label">Outstanding</div>
          <div className="stat-value" style={{ color: pendingAmount > 0 ? "var(--orange)" : "var(--green)" }}>{fmt(pendingAmount)}</div>
          <div style={{ fontSize: 12, color: "var(--text-muted)", marginTop: 6 }}>
            {pendingList.length > 0 ? `${pendingList.length} merchant${pendingList.length !== 1 ? "s" : ""} awaiting payment` : "all merchants up to date"}
          </div>
        </div>
      </div>

      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", flexWrap: "wrap", gap: 10, marginBottom: 14 }}>
        <div className="filter-pills">
          {BILLING_FILTERS.map((p) => (
            <button key={p.id} type="button" className={pillClass(p.tone, filter === p.id)} onClick={() => setFilter(p.id)}>
              {p.label} <span className="pill-count">{pillCounts[p.id] ?? 0}</span>
            </button>
          ))}
        </div>
        <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
          {overdueList.length > 0 && (
            <button onClick={() => setRemindersModal(true)} style={{
              display: "inline-flex", alignItems: "center", gap: 6,
              padding: "6px 14px", borderRadius: 8, fontSize: 12, fontWeight: 700,
              background: "#ff4d4d18", border: "1px solid #ff4d4d40", color: "var(--red)", cursor: "pointer",
            }}>
              📨 Send Overdue Reminders
              <span style={{ background: "#ff4d4d", color: "#fff", borderRadius: 10, padding: "0 6px", fontSize: 10, fontWeight: 800 }}>{overdueList.length}</span>
            </button>
          )}
          <button className="btn-sm" onClick={exportCSV}>⬇ Export CSV</button>
        </div>
      </div>

      <div className="table-scroll">
<table className="data-table">
        <thead><tr><th>Shop</th><th>Fee</th><th>Next Renewal</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
          {list.length === 0 && <tr><td colSpan={5} style={{ textAlign: "center", color: "var(--text-muted)", padding: 32 }}>No merchants</td></tr>}
          {list.map((m) => <BillingRow key={m.id} m={m} now={now} onMarkPaid={() => markPaid(m)} onRemind={() => sendReminder(m)} />)}
        </tbody>
      </table>
      </div>

      {remindersModal && (
        <Modal title={`Send Overdue Reminders (${overdueList.length})`} onClose={() => setRemindersModal(false)}>
          <p style={{ color: "var(--text-muted)", fontSize: 13, marginBottom: 16, lineHeight: 1.5 }}>
            The following merchants are overdue by ≥{thresholdDays} day(s). Click each to open WhatsApp with a pre-filled reminder.
          </p>
          <div style={{ display: "flex", flexDirection: "column", gap: 8, marginBottom: 16 }}>
            {overdueList.map((m) => {
              const rd = getRenewalDate(m);
              const daysOverdue = rd ? Math.ceil((Date.now() - rd) / 86400000) : "—";
              const clean = (m.whatsapp_number || "").replace(/\D/g, "");
              const waUrl = `https://wa.me/${clean}?text=${encodeURIComponent(buildReminderMessage(m))}`;
              return (
                <div key={m.id} style={{ display: "flex", alignItems: "center", justifyContent: "space-between", background: "var(--surface2)", border: "1px solid var(--border)", borderRadius: 8, padding: "10px 14px" }}>
                  <div>
                    <div style={{ fontWeight: 700, fontSize: 13 }}>{m.shop_name}</div>
                    <div style={{ fontSize: 11, color: "var(--red)", marginTop: 2 }}>Overdue {daysOverdue} day{daysOverdue !== 1 ? "s" : ""}</div>
                  </div>
                  <a href={waUrl} target="_blank" rel="noreferrer" style={{ display: "inline-flex", alignItems: "center", gap: 6, padding: "6px 12px", background: "#25d36618", border: "1px solid #25d36640", borderRadius: 6, color: "#25d366", fontSize: 12, fontWeight: 700, textDecoration: "none" }}>
                    <WhatsAppIcon /> Send
                  </a>
                </div>
              );
            })}
          </div>
          <div className="modal-actions">
            <button className="btn-primary" onClick={() => setRemindersModal(false)}>Done</button>
          </div>
        </Modal>
      )}

      {confirm && <ConfirmModal {...confirm} onClose={() => setConfirm(null)} />}
    </div>
  );
}

function BillingRow({ m, now, onMarkPaid, onRemind }) {
  const isPaid = m.subscription_status === "paid";
  const isTrial = m.subscription_status === "trial";
  const isPending = m.subscription_status === "pending";
  const isYearly = m.billing_cycle === "yearly";
  const fee = isYearly ? (m.yearly_fee || 0) : (m.monthly_fee || 0);

  const billingColor = isPaid ? "var(--green)" : isTrial ? "#3b82f6" : "var(--orange)";
  const trialDaysLeft = isTrial && m.trial_ends_at ? Math.ceil((new Date(m.trial_ends_at) - Date.now()) / 86400000) : null;
  const billingLabel = isPaid ? "✓ Paid" : isTrial ? `Trial — ${trialDaysLeft}d left` : "Pending";

  const renewalDate = getRenewalDate(m);
  const renewalStr = renewalDate
    ? renewalDate.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" })
    : "—";

  let alertBadge = null;
  let renewalStyle = { color: "var(--text-muted)", fontSize: 12 };
  if (renewalDate) {
    const diff = renewalDate - now;
    const badge = (text, color) => (
      <span style={{ background: `${color}22`, color, fontSize: 10, fontWeight: 700, padding: "2px 6px", borderRadius: 4, marginLeft: 6, verticalAlign: "middle" }}>{text}</span>
    );
    if (isPending && diff < 0) { alertBadge = badge("OVERDUE", "var(--red)"); renewalStyle = { color: "var(--red)", fontSize: 12, fontWeight: 600 }; }
    else if (isPaid && diff >= 0 && diff < SEVEN_DAYS) { alertBadge = badge("SOON", "#f59e0b"); renewalStyle = { color: "#f59e0b", fontSize: 12, fontWeight: 600 }; }
    else if (isTrial && diff >= 0 && diff < SEVEN_DAYS) { alertBadge = badge("EXPIRING", "#f59e0b"); renewalStyle = { color: "#f59e0b", fontSize: 12, fontWeight: 600 }; }
    else if (isTrial) { renewalStyle = { color: "#3b82f6", fontSize: 12 }; }
  }

  const isOverdue = isPending && renewalDate && (renewalDate - now) < 0;
  const waClean = (m.whatsapp_number || "").replace(/\D/g, "");

  return (
    <tr id={`billing-row-${m.id}`}>
      <td><strong>{m.shop_name}</strong></td>
      <td><strong>${fee}/{isYearly ? "yr" : "mo"}</strong></td>
      <td>
        <span style={renewalStyle}>{isTrial ? `Trial ends ${renewalStr}` : renewalStr}</span>
        {alertBadge}
      </td>
      <td><span style={{ color: billingColor, fontWeight: 600, fontSize: 12 }}>{billingLabel}</span></td>
      <td>
        {(isPending || isTrial) ? (
          <div className="billing-row-actions">
            <button className="btn-sm" style={{ color: "var(--green)", borderColor: "var(--green)" }} onClick={onMarkPaid}>✓ Mark Paid</button>
            {isPending && isOverdue && waClean && (
              <button className="btn-sm" style={{ color: "#f59e0b", borderColor: "#f59e0b40" }} onClick={onRemind}>📨 Remind</button>
            )}
          </div>
        ) : "—"}
      </td>
    </tr>
  );
}
