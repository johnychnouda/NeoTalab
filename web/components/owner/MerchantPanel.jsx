"use client";

import { useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { timeAgo } from "@/lib/api";
import { logActivity } from "@/lib/activity";
import { useToast, WhatsAppIcon, generateOtp } from "@/components/ui";
import { merchantLoginEmail } from "@/lib/merchantLoginEmail";
import { openWelcomeWhatsApp } from "@/lib/welcomeWhatsApp";
import { PANEL_TABS, pillClass } from "@/lib/pillTones";
import WhatsAppEmbeddedSignup from "@/components/owner/WhatsAppEmbeddedSignup";

/**
 * Slide-in side panel for a single merchant (Info / Billing / Bot tabs).
 * Ported from the legacy owner dashboard.
 */
export default function MerchantPanel({ merchant: m, initialTab = "info", api, onClose, onChanged, onConfirm, onModal }) {
  const [tab, setTab] = useState(initialTab);
  const [mounted, setMounted] = useState(false);
  const toast = useToast();

  useEffect(() => { setTab(initialTab); }, [initialTab, m?.id]);
  useEffect(() => { setMounted(true); }, []);

  useEffect(() => {
    if (!m) return undefined;
    function onKey(e) {
      if (e.key === "Escape") onClose?.();
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [m, onClose]);

  if (!m || !mounted) return null;

  const initials = m.shop_name.split(" ").map((w) => w[0]).join("").slice(0, 2).toUpperCase();
  const botDot = m.bot_status === "active" ? "#25d366" : m.bot_status === "error" ? "#ff4d4d" : "#555";
  const subColor = m.subscription_status === "paid" ? "var(--green)" : m.subscription_status === "trial" ? "#3b82f6" : "#f59e0b";
  const subBg = m.subscription_status === "paid" ? "#25d36618" : m.subscription_status === "trial" ? "#3b82f618" : "#f59e0b18";
  const subLabel = m.subscription_status === "paid"
    ? (m.bot_status === "active" ? "Active" : "Paid")
    : m.subscription_status === "trial" ? "Trial" : "Pending";

  return createPortal(
    <>
      <div
        className="panel-overlay open"
        onMouseDown={(e) => {
          if (e.target === e.currentTarget) onClose?.();
        }}
        aria-hidden
      />
      <div className="side-panel open" role="dialog" aria-modal="true">
        <div className="side-panel-header">
          <button className="modal-close" onClick={onClose} type="button" aria-label="Close">✕</button>
        </div>
        <div className="side-panel-body">
          <div className="panel-hero">
            <div className="panel-hero-avatar">{initials}</div>
            <div>
              <div className="panel-hero-name">{m.shop_name}</div>
              <div className="panel-hero-badges">
                {m.status !== "suspended" && (
                  <>
                    <span style={{ background: subBg, color: subColor, border: `1px solid ${subColor}30`, padding: "2px 8px", borderRadius: 20, fontSize: 11, fontWeight: 700 }}>{subLabel}</span>
                    {(m.subscription_status === "paid" || m.subscription_status === "trial") && (
                      <>
                        <span className="panel-bot-dot" style={{ background: botDot }} />
                        <span style={{ fontSize: 11, color: botDot }}>
                          {m.bot_status === "active" ? "Bot Running" : m.bot_status === "error" ? "Bot Error" : "Bot Not Set Up"}
                        </span>
                      </>
                    )}
                  </>
                )}
              </div>
            </div>
          </div>

          <div className="filter-pills panel-pills">
            {PANEL_TABS.filter((t) => t.id !== "bot" || m.status !== "suspended").map((t) => (
              <button key={t.id} type="button" className={pillClass(t.tone, tab === t.id)} onClick={() => setTab(t.id)}>
                {t.label}
              </button>
            ))}
          </div>

          <div className="panel-content">
            {tab === "info" && <InfoTab m={m} api={api} toast={toast} onClose={onClose} onChanged={onChanged} onConfirm={onConfirm} />}
            {tab === "billing" && <BillingTab m={m} api={api} toast={toast} onChanged={onChanged} onConfirm={onConfirm} />}
            {tab === "bot" && <BotTab m={m} api={api} toast={toast} onChanged={onChanged} onModal={onModal} />}
          </div>
        </div>
      </div>
    </>,
    document.body,
  );
}

/* ── Info tab ───────────────────────────────────────────── */

export async function sendWelcomeWA(api, merchantId, shopName, waNumber, otp, loginEmail, toast, options = {}) {
  const regenerateAccess = !!options.regenerateAccess;
  const popup = options.popup || null;
  const shopSlug = options.shopSlug || null;
  const code = otp || (regenerateAccess ? generateOtp() : null);

  if (api && merchantId) {
    try {
      const data = await api("POST", `/api/owner/merchants/${merchantId}/welcome`, {
        otp: code,
        loginEmail,
        regenerateAccess,
      });

      if (data.sent) {
        try { popup?.close(); } catch { /* noop */ }
        toast?.(data.message || "Welcome message sent via WhatsApp");
        return { ok: true, ...data };
      }

      // Password was reset but platform WhatsApp failed — open chat with regain-access message
      const email = data.loginEmail || loginEmail;
      const accessCode = data.otp || code;
      const variant = regenerateAccess ? "regain" : "welcome";
      openWelcomeWhatsApp(waNumber, shopName, accessCode, email, popup, variant, shopSlug);
      toast?.(data.message || (regenerateAccess
        ? "Access reset — WhatsApp opened with regain-access message. Tap Send."
        : "WhatsApp opened. Tap Send."));
      if (data.welcomeError) {
        toast?.(`Platform WhatsApp unavailable (${data.welcomeError})`, "info");
      }
      return { ok: true, openedChat: true, ...data };
    } catch (e) {
      if (regenerateAccess) {
        try { popup?.close(); } catch { /* noop */ }
        toast?.(e.message || "Could not reset merchant access", "error");
        return { ok: false };
      }
      toast?.(e.message || "Could not send welcome via platform WhatsApp", "error");
    }
  }

  const variant = regenerateAccess ? "regain" : "welcome";
  if (!openWelcomeWhatsApp(waNumber, shopName, code, loginEmail, popup, variant, shopSlug)) {
    try { popup?.close(); } catch { /* noop */ }
    return { ok: false };
  }
  toast?.(regenerateAccess
    ? "Opened WhatsApp with regain-access credentials — tap Send"
    : "Opened WhatsApp with login credentials — tap Send", "info");
  return { ok: true, openedChat: true, otp: code, loginEmail };
}

function InfoTab({ m, api, toast, onClose, onChanged, onConfirm }) {
  const waClean = (m.whatsapp_number || "").replace(/\D/g, "");
  const location = [m.city, m.region].filter(Boolean).join(", ") || "—";
  const memberSince = m.created_at
    ? new Date(m.created_at).toLocaleDateString("en-GB", { day: "numeric", month: "long", year: "numeric" })
    : "—";

  const impersonate = () => onConfirm({
    title: "Open Dashboard",
    message: `You're about to log in as <strong>${m.shop_name}</strong> and open their backoffice dashboard.`,
    confirmLabel: "Open Dashboard",
    onConfirm: async () => {
      try {
        const data = await api("POST", `/api/owner/merchants/${m.id}/impersonate`);
        localStorage.setItem("nt_merchant_token", data.token);
        if (m.slug) localStorage.setItem("nt_shop_slug", m.slug);
        if (m.shop_name) localStorage.setItem("nt_shop_name", m.shop_name);
      } catch (e) {
        toast(e.message, "error");
        return;
      }
      window.open(m.slug ? `/backoffice/${m.slug}` : "/backoffice", "_blank");
    },
  });

  const suspend = () => onConfirm({
    title: "Suspend Merchant",
    message: `Suspend <strong>${m.shop_name}</strong>? Their bot will stop and they won't be able to log in until reactivated.`,
    confirmLabel: "Suspend",
    danger: true,
    onConfirm: async () => {
      try {
        await api("PATCH", `/api/owner/merchants/${m.id}`, { status: "suspended" });
        logActivity("merchant", `${m.shop_name} suspended`);
        toast(`${m.shop_name} suspended`);
        onClose(); onChanged();
      } catch (e) { toast(e.message, "error"); }
    },
  });

  const endTrial = () => onConfirm({
    title: "End Trial",
    message: `End the trial for <strong>${m.shop_name}</strong>? Their account will be suspended until they complete payment.`,
    confirmLabel: "End Trial",
    onConfirm: async () => {
      try {
        await api("PATCH", `/api/owner/merchants/${m.id}`, { status: "suspended", subscription_status: "pending" });
        logActivity("merchant", `${m.shop_name}'s trial ended — account suspended pending payment`);
        toast(`${m.shop_name}'s trial ended`);
        onClose(); onChanged();
      } catch (e) { toast(e.message, "error"); }
    },
  });

  const activate = async () => {
    try {
      await api("PATCH", `/api/owner/merchants/${m.id}`, { status: "active" });
      logActivity("merchant", `${m.shop_name} activated`);
      toast(`${m.shop_name} activated`);
      onClose(); onChanged();
    } catch (e) { toast(e.message, "error"); }
  };

  const remove = () => onConfirm({
    title: "Remove Merchant",
    message: `This will permanently delete <strong>${m.shop_name}</strong> and all their data — orders, customers, drivers, and bot configuration.<br /><br />This <strong>cannot be undone</strong>.`,
    confirmLabel: "Remove Permanently",
    danger: true,
    onConfirm: async () => {
      try {
        await api("DELETE", `/api/owner/merchants/${m.id}`);
        logActivity("merchant", `${m.shop_name} permanently removed from the platform`);
        toast(`${m.shop_name} removed from the system`);
        onClose(); onChanged();
      } catch (e) { toast(e.message, "error"); }
    },
  });

  const welcome = async () => {
    if (!m.whatsapp_number) {
      toast("No WhatsApp number on file", "error");
      return;
    }
    // Open blank tab during click so WhatsApp isn't blocked after the API call
    const popup = window.open("about:blank", "_blank");
    const result = await sendWelcomeWA(
      api,
      m.id,
      m.shop_name,
      m.whatsapp_number,
      null,
      merchantLoginEmail(m.shop_name),
      toast,
      { regenerateAccess: true, popup, shopSlug: m.slug },
    );
    if (result?.ok) {
      logActivity("merchant", `Access credentials resent to ${m.shop_name}`);
    }
  };

  const resendAccess = () => {
    if (!m.whatsapp_number) {
      toast("No WhatsApp number on file", "error");
      return;
    }
    onConfirm({
      title: "Resend Access",
      message: `Reset login for <strong>${m.shop_name}</strong> and send a new temporary access code?<br /><br />They will be signed out everywhere and must log in again with the new code.`,
      confirmLabel: "Resend Access",
      onConfirm: () => welcome(),
    });
  };

  return (
    <>
      <div className="info-contact-card">
        <div className="info-contact-row">
          <span className="info-contact-icon">📱</span>
          <div>
            <div className="info-contact-label">WhatsApp</div>
            <div className="info-contact-value">{m.whatsapp_number || "—"}</div>
          </div>
        </div>
      </div>

      <div className="pinfo-row"><div className="pinfo-label">Business Type</div><div className="pinfo-value">{m.business_type || "—"}</div></div>
      <div className="pinfo-row"><div className="pinfo-label">Location</div><div className="pinfo-value">{location}</div></div>
      <div className="pinfo-row"><div className="pinfo-label">Member Since</div><div className="pinfo-value">{memberSince}</div></div>
      <div className="panel-divider" />

      {m.status === "suspended" ? (
        <button className="paction-btn" disabled style={{ opacity: 0.4, cursor: "not-allowed" }}>
          <span>🖥</span> Dashboard Unavailable
        </button>
      ) : (
        <button className="paction-btn paction-primary" onClick={impersonate}>
          <span>🖥</span> Open {m.shop_name}&apos;s Dashboard
        </button>
      )}

      {waClean && (
        <>
          <button className="paction-btn paction-green" onClick={() => window.open(`https://wa.me/${waClean}`, "_blank")}>
            <WhatsAppIcon size={18} /> Message on WhatsApp
          </button>
          {m.subscription_status === "pending" && m.status !== "suspended" && (
            <button className="paction-btn" onClick={welcome}>
              <WhatsAppIcon size={18} /> Send Welcome Message
            </button>
          )}
        </>
      )}

      <div className="info-actions-grid">
        {m.status !== "suspended" ? (
          <>
            <button className="info-action-tile" onClick={resendAccess}>
              <span className="info-action-icon">🔗</span>
              <span>Resend Access</span>
            </button>
            {m.subscription_status === "trial" ? (
              <button className="info-action-tile info-action-warn" onClick={endTrial}>
                <span className="info-action-icon">⏹</span>
                <span>End Trial</span>
              </button>
            ) : (
              <button className="info-action-tile info-action-warn" onClick={suspend}>
                <span className="info-action-icon">⏸</span>
                <span>Suspend</span>
              </button>
            )}
          </>
        ) : (
          <button className="info-action-tile info-action-success" style={{ gridColumn: "1/-1" }} onClick={activate}>
            <span className="info-action-icon">▶</span>
            <span>Activate</span>
          </button>
        )}
      </div>

      <div className="panel-divider" />
      <button className="remove-merchant-btn" onClick={remove}>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><polyline points="3 6 5 6 21 6" /><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6" /><path d="M10 11v6M14 11v6" /><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2" /></svg>
        Remove from System
      </button>
    </>
  );
}

/* ── Billing tab ────────────────────────────────────────── */

function BillingTab({ m, api, toast, onChanged, onConfirm }) {
  const isPaid = m.subscription_status === "paid";
  const isTrial = m.subscription_status === "trial";
  const trialDaysLeft = isTrial && m.trial_ends_at ? Math.ceil((new Date(m.trial_ends_at) - Date.now()) / 86400000) : null;
  const billingColor = isPaid ? "var(--green)" : isTrial ? "#a78bfa" : "var(--orange)";
  const billingLabel = isPaid ? "Paid" : isTrial ? `Trial${trialDaysLeft !== null ? ` — ${trialDaysLeft} day${trialDaysLeft !== 1 ? "s" : ""} left` : ""}` : "Pending";

  const [cycle, setCycle] = useState(m.billing_cycle === "yearly" ? "yearly" : "monthly");
  const [monthlyFee, setMonthlyFee] = useState(String(m.monthly_fee || 0));
  const [yearlyFee, setYearlyFee] = useState(String(m.yearly_fee || 0));
  const [payments, setPayments] = useState(null);

  useEffect(() => {
    setCycle(m.billing_cycle === "yearly" ? "yearly" : "monthly");
    setMonthlyFee(String(m.monthly_fee || 0));
    setYearlyFee(String(m.yearly_fee || 0));
  }, [m.id, m.billing_cycle, m.monthly_fee, m.yearly_fee]);

  useEffect(() => {
    let cancelled = false;
    api("GET", `/api/owner/merchants/${m.id}/payments`)
      .then((d) => { if (!cancelled) setPayments(d.payments || []); })
      .catch(() => { if (!cancelled) setPayments([]); });
    return () => { cancelled = true; };
  }, [api, m.id]);

  const isYearly = cycle === "yearly";
  const activeFee = isYearly ? (m.yearly_fee || 0) : (m.monthly_fee || 0);

  let renewalDate = null;
  let renewalStr = "—";
  let renewalColor = "var(--text)";
  if (m.last_payment_at) {
    renewalDate = new Date(m.last_payment_at);
    if ((m.billing_cycle === "yearly" ? "yearly" : "monthly") === "yearly") {
      renewalDate.setFullYear(renewalDate.getFullYear() + 1);
    } else {
      renewalDate.setMonth(renewalDate.getMonth() + 1);
    }
    renewalStr = renewalDate.toLocaleDateString("en-US", { month: "long", day: "numeric", year: "numeric" });
    const daysLeft = Math.ceil((renewalDate - Date.now()) / 86400000);
    if (daysLeft < 0) renewalColor = "var(--red)";
    else if (daysLeft <= 7) renewalColor = "#f59e0b";
  }

  // Lock cycle + current fee while the paid period is still active.
  const periodLocked = !!(isPaid && renewalDate && renewalDate.getTime() > Date.now());
  const periodDaysLeft = periodLocked
    ? Math.max(1, Math.ceil((renewalDate.getTime() - Date.now()) / 86400000))
    : null;

  const renewalLabel = isTrial ? "Trial Ends" : "Next Renewal";
  const renewalVal = isTrial
    ? (m.trial_ends_at ? new Date(m.trial_ends_at).toLocaleDateString("en-US", { month: "long", day: "numeric", year: "numeric" }) : "—")
    : renewalStr;
  const renewalClr = isTrial ? (trialDaysLeft !== null && trialDaysLeft <= 2 ? "var(--orange)" : "#a78bfa") : renewalColor;

  const markPaid = () => {
    if (!activeFee) {
      toast("Set a monthly or yearly fee before marking as paid", "error");
      return;
    }
    onConfirm({
    title: "Mark as Paid",
    message: isTrial
      ? `End the trial early and mark <strong>${m.shop_name}</strong> as paid for this ${isYearly ? "year" : "month"}?`
      : `Mark <strong>${m.shop_name}</strong> as paid for this ${isYearly ? "year" : "month"}?`,
    confirmLabel: "Mark as Paid",
    onConfirm: async () => {
      try {
        const today = new Date().toISOString().split("T")[0];
        await api("PATCH", `/api/owner/merchants/${m.id}`, {
          subscriptionStatus: "paid",
          lastPaymentAt: today,
          status: "active",
        });
        logActivity("billing", `${m.shop_name} marked as paid — $${activeFee}/${isYearly ? "yr" : "mo"}`);
        setPayments((p) => [{ id: `pay_new_${Date.now()}`, paid_at: today, amount: activeFee, method: "Manual", cycle }, ...(p || [])]);
        toast(`${m.shop_name} marked as paid`);
        onChanged();
      } catch (e) { toast(e.message, "error"); }
    },
  });
  };

  const switchCycle = () => {
    const target = isYearly ? "monthly" : "yearly";
    const targetLabel = target === "yearly" ? "Yearly" : "Monthly";
    const fee = target === "yearly"
      ? (parseFloat(String(yearlyFee).replace(/,/g, "")) || 0)
      : (parseFloat(String(monthlyFee).replace(/,/g, "")) || 0);
    if (!fee) {
      toast(`Set a ${target} fee before switching`, "error");
      return;
    }
    const daysNote = periodDaysLeft != null
      ? ` The remaining <strong>${periodDaysLeft} day${periodDaysLeft === 1 ? "" : "s"}</strong> on their current ${isYearly ? "year" : "month"} will end early.`
      : "";
    onConfirm({
      title: `Switch to ${targetLabel}`,
      message: `Switch <strong>${m.shop_name}</strong> to <strong>${targetLabel}</strong> at <strong>$${fee}/${target === "yearly" ? "year" : "month"}</strong>?${daysNote}<br /><br />A new paid period starts today.`,
      confirmLabel: `Switch to ${targetLabel}`,
      onConfirm: async () => {
        try {
          const today = new Date().toISOString().split("T")[0];
          await api("PATCH", `/api/owner/merchants/${m.id}`, {
            billingCycle: target,
            monthlyFee: parseFloat(String(monthlyFee).replace(/,/g, "")) || 0,
            yearlyFee: parseFloat(String(yearlyFee).replace(/,/g, "")) || 0,
            subscriptionStatus: "paid",
            lastPaymentAt: today,
            status: "active",
          });
          logActivity("billing", `${m.shop_name} switched to ${target} — $${fee}/${target === "yearly" ? "yr" : "mo"}`);
          setCycle(target);
          setPayments((p) => [{
            id: `pay_new_${Date.now()}`,
            paid_at: today,
            amount: fee,
            method: "Manual",
            cycle: target,
          }, ...(p || [])]);
          toast(`Switched to ${targetLabel}`);
          onChanged();
        } catch (e) { toast(e.message, "error"); }
      },
    });
  };

  async function saveBilling() {
    try {
      await api("PATCH", `/api/owner/merchants/${m.id}`, {
        billingCycle: cycle,
        monthlyFee: parseFloat(monthlyFee.replace(/,/g, "")) || 0,
        yearlyFee: parseFloat(yearlyFee.replace(/,/g, "")) || 0,
      });
      logActivity("billing", `${m.shop_name} billing updated — ${cycle}, $${cycle === "yearly" ? yearlyFee : monthlyFee}/${cycle === "yearly" ? "yr" : "mo"}`);
      toast("Billing updated");
      onChanged();
    } catch (e) { toast(e.message, "error"); }
  }

  const fmtFee = (v) => v.replace(/[^0-9]/g, "").replace(/\B(?=(\d{3})+(?!\d))/g, ",");
  const periodWord = isYearly ? "year" : "month";
  const switchTarget = isYearly ? "monthly" : "yearly";
  const switchFee = switchTarget === "yearly" ? yearlyFee : monthlyFee;
  const setSwitchFee = switchTarget === "yearly" ? setYearlyFee : setMonthlyFee;

  return (
    <>
      <div style={{ background: "var(--surface2)", border: "1px solid var(--border)", borderRadius: 16, overflow: "hidden", marginBottom: 20 }}>
        <div style={{ padding: "18px 20px", display: "flex", alignItems: "center", justifyContent: "space-between" }}>
          <div>
            <div style={{ fontSize: 24, fontWeight: 800, letterSpacing: -0.5 }}>
              ${activeFee}<span style={{ fontSize: 13, fontWeight: 500, color: "var(--text-muted)" }}> / {periodWord}</span>
            </div>
            <div style={{ fontSize: 12, color: "var(--text-muted)", marginTop: 3 }}>
              {renewalLabel}: <span style={{ color: renewalClr, fontWeight: 600 }}>{renewalVal}</span>
            </div>
          </div>
          <span style={{ fontSize: 12, fontWeight: 700, padding: "5px 12px", borderRadius: 20, border: `1px solid ${billingColor}40`, color: billingColor, background: `${billingColor}12` }}>
            {periodLocked ? "Paid · locked" : billingLabel}
          </span>
        </div>
        {!isPaid && (
          <div style={{ borderTop: "1px solid var(--border)", padding: "12px 20px", display: "flex", justifyContent: "flex-end" }}>
            <button onClick={markPaid} style={{ background: "transparent", border: "1.5px solid var(--green)", color: "var(--green)", fontSize: 13, fontWeight: 700, padding: "7px 18px", borderRadius: 8, cursor: "pointer" }}>
              ✓ Mark as Paid
            </button>
          </div>
        )}
      </div>

      {periodLocked ? (
        <div style={{ background: "var(--surface2)", border: "1px solid var(--border)", borderRadius: 12, padding: "12px 14px" }}>
          <div style={{ display: "flex", alignItems: "center", gap: 8, marginBottom: 10 }}>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--green)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
              <rect x="3" y="11" width="18" height="11" rx="2" />
              <path d="M7 11V7a5 5 0 0110 0v4" />
            </svg>
            <span style={{ fontSize: 12, color: "var(--text-muted)" }}>
              {isYearly ? "Yearly" : "Monthly"} fee locked
              {periodDaysLeft != null ? ` · ${periodDaysLeft}d left` : ""}
            </span>
          </div>
          <div style={{ display: "flex", alignItems: "center", gap: 8, flexWrap: "wrap" }}>
            <span style={{ fontSize: 12, color: "var(--text-muted)", whiteSpace: "nowrap" }}>
              Switch to {switchTarget === "yearly" ? "Yearly" : "Monthly"}
            </span>
            <div style={{ display: "flex", alignItems: "center", gap: 6, marginLeft: "auto" }}>
              <span style={{ fontSize: 13, color: "var(--text-muted)", fontWeight: 600 }}>$</span>
              <input
                type="text"
                inputMode="numeric"
                className="no-arrows"
                placeholder="0"
                value={switchFee}
                onChange={(e) => setSwitchFee(fmtFee(e.target.value))}
                style={{
                  width: 72, background: "var(--surface)", border: "1px solid var(--border)", borderRadius: 7,
                  outline: "none", padding: "5px 8px", fontSize: 13, fontWeight: 700, color: "var(--text)", textAlign: "right",
                }}
              />
              <button
                type="button"
                onClick={switchCycle}
                style={{
                  background: "var(--green)", border: "none", color: "#fff",
                  fontSize: 12, fontWeight: 700, padding: "6px 12px", borderRadius: 7, cursor: "pointer",
                }}
              >
                Switch
              </button>
            </div>
          </div>
          <div style={{ marginTop: 8, fontSize: 11, color: "var(--text-muted)", lineHeight: 1.4 }}>
            Remaining {periodWord} days end early · new {switchTarget} period starts today
          </div>
        </div>
      ) : (
        <div style={{ background: "var(--surface2)", border: "1px solid var(--border)", borderRadius: 16, overflow: "hidden" }}>
          <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", padding: "14px 20px", borderBottom: "1px solid var(--border)" }}>
            <span style={{ fontSize: 14, fontWeight: 500 }}>Billing Cycle</span>
            <div style={{ display: "flex", background: "var(--surface)", border: "1px solid var(--border)", borderRadius: 8, padding: 3, gap: 3 }}>
              {["monthly", "yearly"].map((v) => (
                <button
                  key={v}
                  type="button"
                  onClick={() => setCycle(v)}
                  style={{
                    padding: "5px 14px", borderRadius: 6, border: "none", fontSize: 12, fontWeight: 700, cursor: "pointer",
                    background: cycle === v ? "var(--green)" : "transparent",
                    color: cycle === v ? "#fff" : "var(--text-muted)",
                  }}
                >
                  {v.charAt(0).toUpperCase() + v.slice(1)}
                </button>
              ))}
            </div>
          </div>

          <div style={{ padding: "14px 20px", borderBottom: "1px solid var(--border)" }}>
            <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between" }}>
              <span style={{ fontSize: 14, fontWeight: 500 }}>{isYearly ? "Yearly" : "Monthly"} Fee</span>
              <div style={{ display: "flex", alignItems: "center", gap: 4 }}>
                <span style={{ fontSize: 14, color: "var(--text-muted)", fontWeight: 600 }}>$</span>
                <input
                  type="text"
                  inputMode="numeric"
                  className="no-arrows"
                  placeholder="0"
                  value={isYearly ? yearlyFee : monthlyFee}
                  onChange={(e) => {
                    const v = fmtFee(e.target.value);
                    if (isYearly) setYearlyFee(v); else setMonthlyFee(v);
                  }}
                  style={{
                    width: 90, background: "var(--surface)", border: "1px solid var(--border)", borderRadius: 7,
                    outline: "none", padding: "6px 10px", fontSize: 14, fontWeight: 700, color: "var(--text)", textAlign: "right",
                  }}
                />
              </div>
            </div>
          </div>

          <div style={{ padding: "12px 20px", display: "flex", justifyContent: "flex-end" }}>
            <button
              type="button"
              onClick={saveBilling}
              style={{
                background: "var(--green)", color: "#fff",
                fontSize: 13, fontWeight: 700, padding: "8px 20px", borderRadius: 8, border: "none", cursor: "pointer",
              }}
            >
              Save Changes
            </button>
          </div>
        </div>
      )}

      {/* ── Payment history ── */}
      <div style={{ background: "var(--surface2)", border: "1px solid var(--border)", borderRadius: 16, overflow: "hidden", marginTop: 20 }}>
        <div style={{ padding: "14px 20px", borderBottom: "1px solid var(--border)", display: "flex", justifyContent: "space-between", alignItems: "center" }}>
          <span style={{ fontSize: 14, fontWeight: 600 }}>Payment History</span>
          {payments?.length > 0 && (
            <span style={{ fontSize: 12, color: "var(--green)", fontWeight: 700 }}>
              ${payments.reduce((s, p) => s + (p.amount || 0), 0).toLocaleString()} total
            </span>
          )}
        </div>
        {payments === null && <div style={{ padding: 20, fontSize: 12, color: "var(--text-muted)" }}>Loading…</div>}
        {payments?.length === 0 && <div style={{ padding: 20, fontSize: 12, color: "var(--text-muted)" }}>No payments recorded yet</div>}
        {payments?.map((p) => (
          <div key={p.id} style={{ display: "flex", alignItems: "center", justifyContent: "space-between", padding: "11px 20px", borderBottom: "1px solid var(--border)" }}>
            <div>
              <div style={{ fontSize: 13, fontWeight: 700 }}>${(p.amount || 0).toLocaleString()} <span style={{ fontWeight: 500, color: "var(--text-muted)", fontSize: 11 }}>/ {p.cycle === "yearly" ? "year" : "month"}</span></div>
              <div style={{ fontSize: 11, color: "var(--text-muted)", marginTop: 1 }}>
                {p.paid_at ? new Date(p.paid_at).toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" }) : "—"}
              </div>
            </div>
            <span style={{ fontSize: 11, fontWeight: 700, color: "var(--text-muted)", border: "1px solid var(--border)", borderRadius: 20, padding: "3px 10px" }}>{p.method || "—"}</span>
          </div>
        ))}
      </div>
    </>
  );
}

/* ── Bot tab ────────────────────────────────────────────── */

function BotTab({ m, api, toast, onChanged, onModal }) {
  const [testing, setTesting] = useState(false);
  const [showManual, setShowManual] = useState(false);
  const [phoneNumberId, setPhoneNumberId] = useState(m.bot_phone_id || "");
  const [accessToken, setAccessToken] = useState("");
  const [savingManual, setSavingManual] = useState(false);
  const retryTimer = useRef(null);

  useEffect(() => () => clearTimeout(retryTimer.current), []);

  const isActive = m.bot_status === "active";
  const isError = m.bot_status === "error";
  const statusBg = isActive ? "#25d36618" : isError ? "#ff4d4d18" : "#33333330";
  const statusBorder = isActive ? "#25d36640" : isError ? "#ff4d4d40" : "#44444440";
  const statusColor = isActive ? "var(--green)" : isError ? "var(--red)" : "var(--text-muted)";
  const statusLabel = isActive ? "● Running" : isError ? "● Error" : "● Not connected";
  const lastChecked = m.bot_last_checked ? timeAgo(m.bot_last_checked) : "Never";

  const credsOk = !!(m.bot_phone_id && (m.bot_token || m.bot_connected_via === "embedded_signup" || m.bot_connected_via === "manual"));
  const modeText = m.bot_mode === "auto" ? "Auto" : m.bot_mode === "manual" ? "Manual" : "Auto";
  const modeColor = m.bot_mode === "manual" ? "#f59e0b" : "var(--green)";

  function handleConnected(merchant) {
    if (!merchant) return;
    logActivity("bot", `${m.shop_name} WhatsApp connected via Meta Embedded Signup`);
    onChanged(merchant);
    setTimeout(testConnection, 800);
  }

  async function saveManual() {
    const phone = phoneNumberId.trim();
    const token = accessToken.trim();
    if (!phone || !token) {
      toast("Phone Number ID and Access Token are required.", "error");
      return;
    }
    setSavingManual(true);
    try {
      const data = await api("PATCH", `/api/owner/merchants/${m.id}/bot`, {
        phoneNumberId: phone,
        accessToken: token,
      });
      toast(data.message || "WhatsApp connected manually.");
      logActivity("bot", `${m.shop_name} WhatsApp connected manually`);
      onChanged(data.merchant || data.data);
      setAccessToken("");
      setShowManual(false);
      setTimeout(testConnection, 800);
    } catch (e) {
      toast(e.message || "Manual connect failed", "error");
    } finally {
      setSavingManual(false);
    }
  }

  async function restart() {
    try {
      await api("POST", `/api/owner/merchants/${m.id}/bot/restart`);
      toast(`Bot restarted for ${m.shop_name}`);
      logActivity("bot", `${m.shop_name} bot restarted`);
      onChanged({ ...m, bot_status: "active", bot_error: null, bot_last_checked: new Date().toISOString() });
    } catch (e) {
      toast(e.message, "error");
    }
  }

  async function reRegisterWebhook() {
    try {
      await api("POST", `/api/owner/merchants/${m.id}/bot/register-webhook`);
      toast(`Webhook re-registered for ${m.shop_name}`);
      onChanged({ ...m, bot_status: "active", bot_error: null, bot_last_checked: new Date().toISOString() });
    } catch (e) {
      toast(e.message, "error");
    }
  }

  function scheduleRetry(minutes) {
    clearTimeout(retryTimer.current);
    retryTimer.current = setTimeout(restart, minutes * 60000);
    toast(`Bot will auto-retry in ${minutes} minute${minutes > 1 ? "s" : ""}`);
  }

  async function testConnection() {
    setTesting(true);
    await new Promise((r) => setTimeout(r, 1200));
    try {
      const data = await api("POST", `/api/owner/merchants/${m.id}/bot/test`);
      if (data.success !== false) {
        toast("✅ Bot connection is working!");
        onChanged({ ...m, bot_status: "active", bot_error: null, bot_last_checked: new Date().toISOString() });
      } else {
        toast(data.error || "Connection failed", "error");
        onChanged({ ...m, bot_status: "error", bot_error: data.error || "Connection failed", bot_last_checked: new Date().toISOString() });
      }
    } catch (e) {
      toast(e.message, "error");
    }
    setTesting(false);
  }

  let quickFixes = [];
  if (isError) {
    const err = (m.bot_error || "").toLowerCase();
    if (err.match(/401|token|unauthorized|expired/)) {
      quickFixes = [
        { label: "🔄 Reconnect WhatsApp", primary: true, action: () => document.getElementById(`wa-embedded-signup-${m.id}`)?.click() },
        { label: "↗ Meta Business Suite", action: () => window.open("https://business.facebook.com/settings/whatsapp-business-accounts", "_blank") },
      ];
    } else if (err.match(/webhook|not responding|no messages/)) {
      quickFixes = [
        { label: "▶ Restart Bot", primary: true, action: restart },
        { label: "🔗 Re-register Webhook", action: reRegisterWebhook },
      ];
    } else if (err.match(/missing|credentials|phone number id/)) {
      quickFixes = [{ label: "📱 Connect WhatsApp", primary: true, action: () => document.getElementById(`wa-embedded-signup-${m.id}`)?.click() }];
    } else if (err.match(/banned|disabled|spam/)) {
      quickFixes = [
        { label: "📱 Switch Number", primary: true, action: () => onModal({ type: "switchNumber", merchant: m }) },
        { label: "↗ Appeal to Meta", action: () => window.open("https://www.facebook.com/help/contact/1638046109571035", "_blank") },
      ];
    } else if (err.match(/rate|too many|limit/)) {
      quickFixes = [
        { label: "⏱ Retry in 15 min", primary: true, action: () => scheduleRetry(15) },
        { label: "⏱ Retry in 1 hour", action: () => scheduleRetry(60) },
      ];
    } else if (err.match(/outage|service|down/)) {
      quickFixes = [
        { label: "🔄 Auto-retry", primary: true, action: () => scheduleRetry(1) },
        { label: "↗ Meta Status", action: () => window.open("https://metastatus.com", "_blank") },
      ];
    } else {
      quickFixes = [
        { label: "▶ Restart Bot", primary: true, action: restart },
        { label: "↗ Meta Status", action: () => window.open("https://metastatus.com", "_blank") },
      ];
    }
  }

  const strip = (label, color, border, text) => (
    <div style={{ background: "var(--surface2)", border: `1px solid ${border}`, borderRadius: 8, padding: "9px 12px" }}>
      <div style={{ fontSize: 10, fontWeight: 700, color: "var(--text-muted)", letterSpacing: 0.6, marginBottom: 3 }}>{label}</div>
      <div style={{ fontSize: 12, fontWeight: 700, color }}>{text}</div>
    </div>
  );

  const phoneLabel = m.bot_display_phone || m.bot_phone_id || "Not connected";
  const viaLabel = m.bot_connected_via === "embedded_signup" ? "Meta Embedded Signup" : m.bot_connected_via === "manual" ? "Manual" : "—";

  return (
    <>
      <div style={{ background: statusBg, border: `1px solid ${statusBorder}`, borderRadius: 10, padding: "14px 16px", marginBottom: 10 }}>
        <div style={{ fontSize: 15, fontWeight: 800, color: statusColor }}>{statusLabel}</div>
        <div style={{ fontSize: 11, color: "var(--text-muted)", marginTop: 2 }}>Last checked: {lastChecked}</div>
        {isError && m.bot_error && (
          <>
            <div style={{ marginTop: 8, paddingTop: 8, borderTop: `1px solid ${statusBorder}`, fontSize: 12, color: "#ff6b6b", lineHeight: 1.5 }}>{m.bot_error}</div>
            {quickFixes.length > 0 && (
              <div style={{ marginTop: 8, display: "flex", gap: 6, flexWrap: "wrap" }}>
                {quickFixes.map((f) => (
                  <button key={f.label} onClick={f.action} style={{
                    padding: "6px 12px", borderRadius: 7, fontSize: 11, fontWeight: 700, cursor: "pointer",
                    background: f.primary ? "var(--green)" : "transparent",
                    color: f.primary ? "#fff" : "var(--text-muted)",
                    border: f.primary ? "none" : "1px solid var(--border)",
                  }}>{f.label}</button>
                ))}
              </div>
            )}
          </>
        )}
      </div>

      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 8, marginBottom: 14 }}>
        {strip("PHONE", credsOk ? "var(--green)" : "var(--text-muted)", credsOk ? "#25d36630" : "var(--border)", phoneLabel)}
        {strip("CONNECTION", credsOk ? "var(--green)" : "var(--red)", credsOk ? "#25d36630" : "#ff4d4d40", credsOk ? "Connected" : "Not connected")}
        {strip("MODE", modeColor, "var(--border)", modeText)}
      </div>

      <div className="panel-section-label">WhatsApp Connection</div>
      <p style={{ fontSize: 12, color: "var(--text-muted)", marginBottom: 12, lineHeight: 1.5 }}>
        Prefer Meta Embedded Signup. If it hangs, use manual Phone Number ID + token from Meta → WhatsApp → Step 1. Try it out.
        {viaLabel !== "—" && <> Connected via <strong>{viaLabel}</strong>.</>}
      </p>
      <WhatsAppEmbeddedSignup
          merchantId={m.id}
          api={api}
          toast={toast}
          connected={credsOk}
          buttonId={`wa-embedded-signup-${m.id}`}
          onConnected={handleConnected}
        />

      <button
        type="button"
        className="paction-btn"
        style={{ justifyContent: "center", marginTop: 10, marginBottom: 0, width: "100%" }}
        onClick={() => setShowManual((v) => !v)}
      >
        {showManual ? "Hide manual connect" : "Connect manually (Phone ID + Token)"}
      </button>

      {showManual && (
        <div style={{ marginTop: 10, padding: 12, border: "1px solid var(--border)", borderRadius: 10, background: "var(--surface2)" }}>
          <div className="field" style={{ marginBottom: 8 }}>
            <label style={{ fontSize: 11, fontWeight: 700, color: "var(--text-muted)" }}>Phone Number ID</label>
            <input
              value={phoneNumberId}
              onChange={(e) => setPhoneNumberId(e.target.value)}
              placeholder="e.g. 1288929074293606"
              style={{ width: "100%", marginTop: 4 }}
            />
          </div>
          <div className="field" style={{ marginBottom: 10 }}>
            <label style={{ fontSize: 11, fontWeight: 700, color: "var(--text-muted)" }}>Temporary Access Token</label>
            <input
              type="password"
              value={accessToken}
              onChange={(e) => setAccessToken(e.target.value)}
              placeholder="EAA…"
              style={{ width: "100%", marginTop: 4 }}
            />
          </div>
          <p style={{ fontSize: 11, color: "var(--text-muted)", lineHeight: 1.45, marginBottom: 10 }}>
            Meta → NeoTalab → Use cases → Connect on WhatsApp → Step 1. Try it out. Copy Phone number ID and Generate access token.
          </p>
          <button
            type="button"
            className="paction-btn paction-primary"
            style={{ justifyContent: "center", width: "100%", marginBottom: 0 }}
            onClick={saveManual}
            disabled={savingManual}
          >
            {savingManual ? "Saving…" : "Save & Test Connection"}
          </button>
        </div>
      )}

      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 8, marginTop: 10 }}>
        <button className="paction-btn" style={{ justifyContent: "center", marginBottom: 0 }} onClick={testConnection} disabled={testing || !credsOk}>
          {testing ? "Testing…" : "📡 Test Connection"}
        </button>
        <button className="paction-btn" style={{ justifyContent: "center", marginBottom: 0 }} onClick={restart} disabled={!credsOk}>
          ▶ Restart Bot
        </button>
      </div>
    </>
  );
}
