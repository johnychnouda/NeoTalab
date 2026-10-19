"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { timeAgo } from "@/lib/api";
import { trialDaysLeft as trialDaysLeftFrom } from "@/lib/trial";
import { logActivity } from "@/lib/activity";
import { useToast, WhatsAppIcon, generateOtp, Modal } from "@/components/ui";
import FormSelect from "@/components/FormSelect";
import { merchantLoginEmail } from "@/lib/merchantLoginEmail";
import { openWelcomeWhatsApp } from "@/lib/welcomeWhatsApp";
import { PANEL_TABS, pillClass } from "@/lib/pillTones";
import { useLang } from "@/lib/i18n";
import { useLiveRefresh } from "@/lib/live";
import { joinOptionLabel, JOIN_BUSINESS_TYPES, JOIN_LEBANON_REGIONS, JOIN_CITY_OTHER, getLebanonCitiesForRegion } from "@/lib/joinFormOptions";
import { lebaneseMobileError, toLebaneseWhatsapp } from "@/lib/phone";
import WhatsAppEmbeddedSignup from "@/components/owner/WhatsAppEmbeddedSignup";

/**
 * Slide-in side panel for a single merchant (Info / Billing / WhatsApp tabs).
 */
const pic = (d) => (
  <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{d}</svg>
);
const PI = {
  phone: pic(<><rect x="6" y="2" width="12" height="20" rx="2.5" /><path d="M11 18h2" /></>),
  screen: pic(<><rect x="2" y="4" width="20" height="13" rx="2" /><path d="M8 21h8M12 17v4" /></>),
  link: pic(<><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7" /><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7" /></>),
  pause: pic(<><rect x="6" y="5" width="4" height="14" rx="1" /><rect x="14" y="5" width="4" height="14" rx="1" /></>),
  stop: pic(<rect x="6" y="6" width="12" height="12" rx="2" />),
  play: pic(<path d="M7 4.5v15l12-7.5z" />),
};

export default function MerchantPanel({ merchant: m, initialTab = "info", api, onClose, onChanged, onConfirm, onModal }) {
  const [tab, setTab] = useState(initialTab);
  const [mounted, setMounted] = useState(false);
  const toast = useToast();
  const { t } = useLang();

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

  const botDot = m.bot_status === "active" ? "#25d366" : m.bot_status === "error" ? "#ff4d4d" : "#555";
  const subColor = m.subscription_status === "paid" ? "var(--green)" : m.subscription_status === "trial" ? "#3b82f6" : "#f59e0b";
  const subBg = m.subscription_status === "paid" ? "#25d36618" : m.subscription_status === "trial" ? "#3b82f618" : "#f59e0b18";
  const subLabel = t(m.subscription_status === "paid"
    ? (m.bot_status === "active" ? "ow.f.active" : "ow.f.paid")
    : m.subscription_status === "trial" ? "ow.f.trial" : "ow.f.pending");

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
          <button className="modal-close" onClick={onClose} type="button" aria-label={t("ow.cmd.close")}>✕</button>
        </div>
        <div className="side-panel-body">
          <div className="panel-hero">
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
                          {t(m.bot_status === "active" ? "ow.wa.ok" : m.bot_status === "error" ? "ow.wa.error" : "ow.wa.notSet")}
                        </span>
                      </>
                    )}
                  </>
                )}
              </div>
            </div>
          </div>

          <div className="filter-pills panel-pills">
            {PANEL_TABS.filter((p) => p.id !== "bot" || m.status !== "suspended").map((p) => (
              <button key={p.id} type="button" className={pillClass(p.tone, tab === p.id)} onClick={() => setTab(p.id)}>
                {t(p.key)}
              </button>
            ))}
          </div>

          <div className="panel-content">
            {tab === "info" && <InfoTab t={t} m={m} api={api} toast={toast} onClose={onClose} onChanged={onChanged} onConfirm={onConfirm} />}
            {tab === "billing" && <BillingTab t={t} m={m} api={api} toast={toast} onChanged={onChanged} onConfirm={onConfirm} />}
            {tab === "bot" && <BotTab t={t} m={m} api={api} toast={toast} onChanged={onChanged} onModal={onModal} />}
          </div>
        </div>
      </div>
    </>,
    document.body,
  );
}

/* ── Info tab ───────────────────────────────────────────── */

async function sendWelcomeWA(t, api, merchantId, shopName, waNumber, otp, loginEmail, toast, options = {}) {
  const regenerateAccess = !!options.regenerateAccess;
  const popup = options.popup || null;
  const shopSlug = options.shopSlug || null;
  const code = otp || (regenerateAccess ? generateOtp() : null);

  if (api && merchantId) {
    try {
      const data = await api("POST", `/admin/merchants/${merchantId}/welcome`, {
        otp: code,
        loginEmail,
        regenerateAccess,
      });

      if (data.sent) {
        try { popup?.close(); } catch { /* noop */ }
        toast?.(t("ow.p.accessSent"));
        return { ok: true, ...data };
      }

      // Password was reset but platform WhatsApp failed, open chat with regain-access message
      const email = data.loginEmail || loginEmail;
      const accessCode = data.otp || code;
      const variant = regenerateAccess ? "regain" : "welcome";
      openWelcomeWhatsApp(t, waNumber, shopName, accessCode, email, popup, variant, shopSlug);
      toast?.(t("ow.p.tapSend"));
      return { ok: true, openedChat: true, ...data };
    } catch (e) {
      if (regenerateAccess) {
        try { popup?.close(); } catch { /* noop */ }
        toast?.(e.message, "error");
        return { ok: false };
      }
      toast?.(e.message, "error");
    }
  }

  const variant = regenerateAccess ? "regain" : "welcome";
  if (!openWelcomeWhatsApp(t, waNumber, shopName, code, loginEmail, popup, variant, shopSlug)) {
    try { popup?.close(); } catch { /* noop */ }
    return { ok: false };
  }
  toast?.(t("ow.p.tapSend"), "info");
  return { ok: true, openedChat: true, otp: code, loginEmail };
}

function InfoTab({ t, m, api, toast, onClose, onChanged, onConfirm }) {
  const { locale } = useLang();
  const [editing, setEditing] = useState(false);
  const waClean = (m.whatsapp_number || "").replace(/\D/g, "");
  const location = [m.city, joinOptionLabel(t, m.region)].filter(Boolean).join(", ") || "—";
  const memberSince = m.created_at
    ? new Date(m.created_at).toLocaleDateString(locale, { day: "numeric", month: "long", year: "numeric" })
    : "—";

  const impersonate = () => onConfirm({
    title: t("ow.p.openDash"),
    message: <>{t("ow.p.openDashMsg")} <strong>{m.shop_name}</strong></>,
    confirmLabel: t("ow.p.openDash"),
    onConfirm: async () => {
      try {
        const data = await api("POST", `/admin/merchants/${m.id}/impersonate`);
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
    title: t("ow.p.suspend"),
    message: <><strong>{m.shop_name}</strong> — {t("ow.p.suspendMsg")}</>,
    confirmLabel: t("ow.p.suspend"),
    danger: true,
    onConfirm: async () => {
      try {
        await api("PATCH", `/admin/merchants/${m.id}`, { status: "suspended" });
        logActivity("merchant", t("ow.log.suspended", { shop: m.shop_name }));
        toast(t("ow.log.suspended", { shop: m.shop_name }));
        onClose(); onChanged();
      } catch (e) { toast(e.message, "error"); }
    },
  });

  const endTrial = () => onConfirm({
    title: t("ow.p.endTrial"),
    message: <><strong>{m.shop_name}</strong> — {t("ow.p.endTrialMsg")}</>,
    confirmLabel: t("ow.p.endTrial"),
    onConfirm: async () => {
      try {
        await api("PATCH", `/admin/merchants/${m.id}`, { status: "suspended", subscription_status: "pending" });
        logActivity("merchant", t("ow.log.trialEnded", { shop: m.shop_name }));
        toast(t("ow.log.trialEnded", { shop: m.shop_name }));
        onClose(); onChanged();
      } catch (e) { toast(e.message, "error"); }
    },
  });

  const activate = async () => {
    try {
      await api("PATCH", `/admin/merchants/${m.id}`, { status: "active" });
      logActivity("merchant", t("ow.log.activated", { shop: m.shop_name }));
      toast(t("ow.log.activated", { shop: m.shop_name }));
      onClose(); onChanged();
    } catch (e) { toast(e.message, "error"); }
  };

  const remove = () => onConfirm({
    title: t("ow.p.remove"),
    message: <><strong>{m.shop_name}</strong> — {t("ow.p.removeMsg")}<br /><br /><strong>{t("ow.p.cannotUndo")}</strong></>,
    confirmLabel: t("ow.p.removeBtn"),
    danger: true,
    onConfirm: async () => {
      try {
        await api("DELETE", `/admin/merchants/${m.id}`);
        logActivity("merchant", t("ow.log.removed", { shop: m.shop_name }));
        toast(t("ow.log.removed", { shop: m.shop_name }));
        onClose(); onChanged();
      } catch (e) { toast(e.message, "error"); }
    },
  });

  const welcome = async () => {
    if (!m.whatsapp_number) {
      toast(t("ow.p.noNumber"), "error");
      return;
    }
    // Open blank tab during click so WhatsApp isn't blocked after the API call
    const popup = window.open("about:blank", "_blank");
    const result = await sendWelcomeWA(
      t,
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
      logActivity("merchant", t("ow.log.accessResent", { shop: m.shop_name }));
    }
  };

  const resendAccess = () => {
    if (!m.whatsapp_number) {
      toast(t("ow.p.noNumber"), "error");
      return;
    }
    onConfirm({
      title: t("ow.p.resend"),
      message: <><strong>{m.shop_name}</strong> — {t("ow.p.resendMsg")}</>,
      confirmLabel: t("ow.p.resend"),
      onConfirm: () => welcome(),
    });
  };

  const ownerPhone = (m.owner_phone || "").replace(/\D/g, "");
  const dashUrl = `${typeof window !== "undefined" ? window.location.origin : "https://neotalab.com"}/backoffice/${m.slug || ""}`;
  const copy = (text) => {
    navigator.clipboard?.writeText(text).then(() => toast(t("ow.p.copied"))).catch(() => {});
  };

  return (
    <>
      <div className="info-owner-card">
        <div className="info-owner-text">
          <div className="info-contact-label">{t("ow.p.ownerTitle")}</div>
          <div className="info-owner-name">{m.owner_name || t("ow.p.noOwner")}</div>
          {m.owner_phone && <div className="info-owner-phone" dir="ltr">{m.owner_phone}</div>}
        </div>
        {ownerPhone && (
          <button type="button" className="info-owner-msg" onClick={() => window.open(`https://wa.me/${ownerPhone}`, "_blank")}>
            <WhatsAppIcon size={18} /> {t("ow.p.messageOwner")}
          </button>
        )}
      </div>

      <div className="panel-group-title">{t("ow.p.groupContact")}</div>
      <CopyRow label={t("ow.p.loginEmail")} value={m.login_email} onCopy={copy} t={t} />
      <CopyRow label={t("ow.p.dashAddress")} value={m.slug ? dashUrl : ""} onCopy={copy} t={t} />
      <div className="pinfo-row"><div className="pinfo-label">WhatsApp</div><div className="pinfo-value" dir="ltr">{m.whatsapp_number || "—"}</div></div>
      <div className="pinfo-row"><div className="pinfo-label">{t("ow.m.business")}</div><div className="pinfo-value">{joinOptionLabel(t, m.business_type) || "—"}</div></div>
      <div className="pinfo-row"><div className="pinfo-label">{t("ow.m.location")}</div><div className="pinfo-value">{location}</div></div>
      <div className="pinfo-row"><div className="pinfo-label">{t("ow.p.since")}</div><div className="pinfo-value">{memberSince}</div></div>
      <button type="button" className="paction-btn" onClick={() => setEditing(true)}>✏️ {t("ow.edit")}</button>
      {editing && <EditShopModal t={t} m={m} api={api} toast={toast} onClose={() => setEditing(false)}
        onSaved={(updated) => { setEditing(false); onChanged(updated); }} />}

      <div className="panel-group-title">{t("ow.p.groupDashboard")}</div>
      {m.status === "suspended" ? (
        <button className="paction-btn" disabled style={{ opacity: 0.4, cursor: "not-allowed" }}>
          {PI.screen} {t("ow.p.dashOff")}
        </button>
      ) : (
        <button className="paction-btn paction-primary" onClick={impersonate}>
          {PI.screen} {t("ow.p.openDashOf", { shop: m.shop_name })}
        </button>
      )}
      {m.status !== "suspended" && (
        <div className="info-actions-grid">
          <button className="info-action-tile" onClick={resendAccess}>
            <span className="info-action-icon">{PI.link}</span>
            <span>{t("ow.p.resend")}</span>
          </button>
          {m.subscription_status === "pending" && waClean && (
            <button className="info-action-tile" onClick={welcome}>
              <span className="info-action-icon"><WhatsAppIcon size={16} /></span>
              <span>{t("ow.p.sendWelcome")}</span>
            </button>
          )}
        </div>
      )}

      <div className="panel-group-title">{t("ow.p.groupAccount")}</div>
      {m.status === "suspended" ? (
        <button className="info-action-tile info-action-success" style={{ width: "100%" }} onClick={activate}>
          <span className="info-action-icon">{PI.play}</span>
          <span>{t("ow.p.activate")}</span>
        </button>
      ) : m.subscription_status === "trial" ? (
        <button className="info-action-tile info-action-warn" style={{ width: "100%" }} onClick={endTrial}>
          <span className="info-action-icon">{PI.stop}</span>
          <span>{t("ow.p.endTrial")}</span>
        </button>
      ) : (
        <button className="info-action-tile info-action-warn" style={{ width: "100%" }} onClick={suspend}>
          <span className="info-action-icon">{PI.pause}</span>
          <span>{t("ow.p.suspend")}</span>
        </button>
      )}

      <div className="panel-group-title panel-group-danger">{t("ow.p.groupDanger")}</div>
      <button className="remove-merchant-btn" onClick={remove}>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><polyline points="3 6 5 6 21 6" /><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6" /><path d="M10 11v6M14 11v6" /><path d="M9 6V4a2 2 0 012-2h2a2 2 0 012 2v2" /></svg>
        {t("ow.p.remove")}
      </button>
    </>
  );
}

/** A detail row with a copy button, for values the owner needs to share (login email, dashboard address). */
function CopyRow({ label, value, onCopy, t }) {
  return (
    <div className="pinfo-row">
      <div className="pinfo-label">{label}</div>
      <div className="pinfo-value pinfo-copy">
        <span dir="ltr" className="pinfo-ltr">{value || "—"}</span>
        {value && <button type="button" className="pinfo-copy-btn" onClick={() => onCopy(value)}>{t("ow.p.copy")}</button>}
      </div>
    </div>
  );
}

/* ── Billing tab ────────────────────────────────────────── */

function BillingTab({ t, m, api, toast, onChanged, onConfirm }) {
  const { locale } = useLang();
  const isPaid = m.subscription_status === "paid";
  const isTrial = m.subscription_status === "trial";
  const trialDaysLeft = isTrial ? trialDaysLeftFrom(m.trial_ends_at) : null;
  const billingColor = m.overdue ? "var(--red)" : isPaid ? "var(--green)" : isTrial ? "#a78bfa" : "var(--orange)";
  const billingLabel = m.overdue ? t("ow.b.overdue") : isPaid ? t("ow.f.paid") : isTrial ? (trialDaysLeft !== null ? t("ow.trialDaysShort", { n: trialDaysLeft }) : t("ow.f.trial")) : t("ow.f.pending");
  const fmtDate = (d) => new Date(d).toLocaleDateString(locale, { month: "long", day: "numeric", year: "numeric" });

  const [cycle, setCycle] = useState(m.billing_cycle === "yearly" ? "yearly" : "monthly");
  const [monthlyFee, setMonthlyFee] = useState(String(m.monthly_fee || 0));
  const [yearlyFee, setYearlyFee] = useState(String(m.yearly_fee || 0));
  const [payments, setPayments] = useState(null);

  useEffect(() => {
    setCycle(m.billing_cycle === "yearly" ? "yearly" : "monthly");
    setMonthlyFee(String(m.monthly_fee || 0));
    setYearlyFee(String(m.yearly_fee || 0));
  }, [m.id, m.billing_cycle, m.monthly_fee, m.yearly_fee]);

  const loadPayments = useCallback(
    () => api("GET", `/admin/merchants/${m.id}/payments`)
      .then((d) => setPayments(d.payments || []))
      .catch(() => setPayments((cur) => cur ?? [])),
    [api, m.id],
  );

  useEffect(() => { setPayments(null); loadPayments(); }, [loadPayments]);
  useLiveRefresh(loadPayments);

  const isYearly = cycle === "yearly";
  const activeFee = isYearly ? (m.yearly_fee || 0) : (m.monthly_fee || 0);
  const per = (yearly) => t(yearly ? "ow.perYear" : "ow.perMonth");

  let renewalDate = null;
  let renewalStr = "—";
  let renewalColor = "var(--text)";
  if (m.last_payment_at) {
    renewalDate = new Date(m.last_payment_at);
    if (m.billing_cycle === "yearly") renewalDate.setFullYear(renewalDate.getFullYear() + 1);
    else renewalDate.setMonth(renewalDate.getMonth() + 1);
    renewalStr = fmtDate(renewalDate);
    const daysLeft = Math.ceil((renewalDate - Date.now()) / 86400000);
    if (daysLeft < 0) renewalColor = "var(--red)";
    else if (daysLeft <= 7) renewalColor = "#f59e0b";
  }

  // Lock cycle + current fee while the paid period is still active.
  const periodLocked = !!(isPaid && renewalDate && renewalDate.getTime() > Date.now());
  const periodDaysLeft = periodLocked
    ? Math.max(1, Math.ceil((renewalDate.getTime() - Date.now()) / 86400000))
    : null;

  const renewalLabel = isTrial ? t("ow.b.trialEnds") : t("ow.b.nextRenewal");
  const renewalVal = isTrial ? (m.trial_ends_at ? fmtDate(m.trial_ends_at) : "—") : renewalStr;
  const renewalClr = isTrial ? (trialDaysLeft !== null && trialDaysLeft <= 2 ? "var(--orange)" : "#a78bfa") : renewalColor;
  const num = (v) => parseFloat(String(v).replace(/,/g, "")) || 0;
  // Save only appears once a fee or the cycle really changed.
  const billingDirty = num(monthlyFee) !== Number(m.monthly_fee || 0)
    || num(yearlyFee) !== Number(m.yearly_fee || 0)
    || cycle !== (m.billing_cycle === "yearly" ? "yearly" : "monthly");

  const markPaid = () => {
    if (!activeFee) {
      toast(t("ow.b.setFeeFirst"), "error");
      return;
    }
    onConfirm({
      title: t("ow.b.markPaid"),
      message: <><strong>{m.shop_name}</strong> — {t(isYearly ? "ow.b.markPaidYear" : "ow.b.markPaidMonth", { fee: `$${activeFee}` })}</>,
      confirmLabel: t("ow.b.markPaid"),
      onConfirm: async () => {
        try {
          const today = new Date().toISOString().split("T")[0];
          await api("PATCH", `/admin/merchants/${m.id}`, { subscriptionStatus: "paid", lastPaymentAt: today, status: "active" });
          logActivity("billing", t("ow.log.paid", { shop: m.shop_name, fee: `$${activeFee}${per(isYearly)}` }));
          setPayments((p) => [{ id: `pay_new_${Date.now()}`, paid_at: today, amount: activeFee, method: "Manual", cycle }, ...(p || [])]);
          toast(t("ow.log.paid", { shop: m.shop_name, fee: `$${activeFee}${per(isYearly)}` }));
          onChanged();
        } catch (e) { toast(e.message, "error"); }
      },
    });
  };

  const switchCycle = () => {
    const target = isYearly ? "monthly" : "yearly";
    const targetLabel = t(target === "yearly" ? "ow.yearly" : "ow.monthly");
    const fee = target === "yearly" ? num(yearlyFee) : num(monthlyFee);
    if (!fee) {
      toast(t("ow.b.setFeeFirst"), "error");
      return;
    }
    onConfirm({
      title: t("ow.b.switchTo", { cycle: targetLabel }),
      message: <><strong>{m.shop_name}</strong> — {t("ow.b.switchMsg", { cycle: targetLabel, fee: `$${fee}${per(target === "yearly")}` })}{periodDaysLeft != null && <> {t("ow.b.switchDaysLost", { n: periodDaysLeft })}</>}</>,
      confirmLabel: t("ow.b.switchTo", { cycle: targetLabel }),
      onConfirm: async () => {
        try {
          const today = new Date().toISOString().split("T")[0];
          await api("PATCH", `/admin/merchants/${m.id}`, {
            billingCycle: target,
            monthlyFee: num(monthlyFee),
            yearlyFee: num(yearlyFee),
            subscriptionStatus: "paid",
            lastPaymentAt: today,
            status: "active",
          });
          logActivity("billing", t("ow.log.switched", { shop: m.shop_name, fee: `$${fee}${per(target === "yearly")}` }));
          setCycle(target);
          setPayments((p) => [{ id: `pay_new_${Date.now()}`, paid_at: today, amount: fee, method: "Manual", cycle: target }, ...(p || [])]);
          toast(t("ow.log.switched", { shop: m.shop_name, fee: `$${fee}${per(target === "yearly")}` }));
          onChanged();
        } catch (e) { toast(e.message, "error"); }
      },
    });
  };

  async function saveBilling() {
    try {
      await api("PATCH", `/admin/merchants/${m.id}`, {
        billingCycle: cycle,
        monthlyFee: num(monthlyFee),
        yearlyFee: num(yearlyFee),
      });
      logActivity("billing", t("ow.log.feeUpdated", { shop: m.shop_name, fee: `$${isYearly ? num(yearlyFee) : num(monthlyFee)}${per(isYearly)}` }));
      toast(t("db.saved"));
      onChanged();
    } catch (e) { toast(e.message, "error"); }
  }

  const fmtFee = (v) => v.replace(/[^0-9]/g, "").replace(/\B(?=(\d{3})+(?!\d))/g, ",");
  const switchTarget = isYearly ? "monthly" : "yearly";
  const switchFee = switchTarget === "yearly" ? yearlyFee : monthlyFee;
  const setSwitchFee = switchTarget === "yearly" ? setYearlyFee : setMonthlyFee;
  const feeInput = { background: "var(--surface)", border: "1px solid var(--border)", borderRadius: 7, outline: "none", fontWeight: 700, color: "var(--text)", textAlign: "end" };

  return (
    <>
      <div style={{ background: "var(--surface2)", border: "1px solid var(--border)", borderRadius: 16, overflow: "hidden", marginBottom: 20 }}>
        <div style={{ padding: "18px 20px", display: "flex", alignItems: "center", justifyContent: "space-between", gap: 10 }}>
          <div>
            <div style={{ fontSize: 24, fontWeight: 800, letterSpacing: -0.5 }}>
              ${activeFee}<span style={{ fontSize: 13, fontWeight: 500, color: "var(--text-muted)" }}>{per(isYearly)}</span>
            </div>
            <div style={{ fontSize: 12, color: "var(--text-muted)", marginTop: 3 }}>
              {renewalLabel}: <span style={{ color: renewalClr, fontWeight: 600 }}>{renewalVal}{isTrial && trialDaysLeft !== null ? ` · ${t("ow.trialDaysShort", { n: trialDaysLeft })}` : ""}</span>
            </div>
          </div>
          {(!isTrial || m.overdue) && <span style={{ fontSize: 12, fontWeight: 700, padding: "5px 12px", borderRadius: 20, border: `1px solid ${billingColor}40`, color: billingColor, background: `${billingColor}12`, whiteSpace: "nowrap" }}>
            {periodLocked && !m.overdue ? `🔒 ${t("ow.f.paid")}` : billingLabel}
          </span>}
        </div>
        {!isPaid && (
          <div style={{ borderTop: "1px solid var(--border)", padding: "12px 20px", display: "flex", justifyContent: "flex-end" }}>
            <button type="button" onClick={markPaid} style={{ background: "transparent", border: "1.5px solid var(--green)", color: "var(--green)", fontSize: 13, fontWeight: 700, padding: "7px 18px", borderRadius: 8, cursor: "pointer" }}>
              ✓ {t("ow.b.markPaid")}
            </button>
          </div>
        )}
      </div>

      {periodLocked ? (
        <div style={{ background: "var(--surface2)", border: "1px solid var(--border)", borderRadius: 12, padding: "12px 14px" }}>
          <div style={{ fontSize: 12, color: "var(--text-muted)", marginBottom: 10 }}>
            🔒 {t("ow.b.locked", { n: periodDaysLeft })}
          </div>
          <div style={{ display: "flex", alignItems: "center", gap: 8, flexWrap: "wrap" }}>
            <span style={{ fontSize: 12, color: "var(--text-muted)" }}>
              {t("ow.b.switchTo", { cycle: t(switchTarget === "yearly" ? "ow.yearly" : "ow.monthly") })}
            </span>
            <div style={{ display: "flex", alignItems: "center", gap: 6, marginInlineStart: "auto" }} dir="ltr">
              <span style={{ fontSize: 13, color: "var(--text-muted)", fontWeight: 600 }}>$</span>
              <input type="text" inputMode="numeric" className="no-arrows" placeholder="0" value={switchFee}
                onChange={(e) => setSwitchFee(fmtFee(e.target.value))} aria-label={t("ow.b.fee")}
                style={{ ...feeInput, width: 72, padding: "5px 8px", fontSize: 13 }} />
              <button type="button" onClick={switchCycle}
                style={{ background: "var(--green)", border: "none", color: "#fff", fontSize: 12, fontWeight: 700, padding: "6px 12px", borderRadius: 7, cursor: "pointer" }}>
                {t("ow.b.switch")}
              </button>
            </div>
          </div>
        </div>
      ) : (
        <div style={{ background: "var(--surface2)", border: "1px solid var(--border)", borderRadius: 16, overflow: "hidden" }}>
          <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", padding: "14px 20px", borderBottom: "1px solid var(--border)" }}>
            <span style={{ fontSize: 14, fontWeight: 500 }}>{t("ow.b.cycle")}</span>
            <div style={{ display: "flex", background: "var(--surface)", border: "1px solid var(--border)", borderRadius: 8, padding: 3, gap: 3 }}>
              {["monthly", "yearly"].map((v) => (
                <button key={v} type="button" onClick={() => setCycle(v)}
                  style={{
                    padding: "5px 14px", borderRadius: 6, border: "none", fontSize: 12, fontWeight: 700, cursor: "pointer",
                    background: cycle === v ? "var(--green)" : "transparent",
                    color: cycle === v ? "#fff" : "var(--text-muted)",
                  }}>
                  {t(v === "yearly" ? "ow.yearly" : "ow.monthly")}
                </button>
              ))}
            </div>
          </div>

          <div style={{ padding: "14px 20px", borderBottom: "1px solid var(--border)", display: "flex", alignItems: "center", justifyContent: "space-between" }}>
            <span style={{ fontSize: 14, fontWeight: 500 }}>{t("ow.b.fee")}</span>
            <div style={{ display: "flex", alignItems: "center", gap: 4 }} dir="ltr">
              <span style={{ fontSize: 14, color: "var(--text-muted)", fontWeight: 600 }}>$</span>
              <input type="text" inputMode="numeric" className="no-arrows" placeholder="0" aria-label={t("ow.b.fee")}
                value={isYearly ? yearlyFee : monthlyFee}
                onChange={(e) => {
                  const v = fmtFee(e.target.value);
                  if (isYearly) setYearlyFee(v); else setMonthlyFee(v);
                }}
                style={{ ...feeInput, width: 90, padding: "6px 10px", fontSize: 14 }} />
              <span style={{ fontSize: 12, color: "var(--text-muted)" }}>{per(isYearly)}</span>
            </div>
          </div>

          <div style={{ padding: "12px 20px", display: "flex", justifyContent: "flex-end" }}>
            {billingDirty && <button type="button" className="btn-primary" onClick={saveBilling}>{t("db.save")}</button>}
          </div>
        </div>
      )}

      {/* ── Payment history ── */}
      <div style={{ background: "var(--surface2)", border: "1px solid var(--border)", borderRadius: 16, overflow: "hidden", marginTop: 20 }}>
        <div style={{ padding: "14px 20px", borderBottom: "1px solid var(--border)", display: "flex", justifyContent: "space-between", alignItems: "center" }}>
          <span style={{ fontSize: 14, fontWeight: 600 }}>{t("ow.b.history")}</span>
          {payments?.length > 0 && (
            <span style={{ fontSize: 12, color: "var(--green)", fontWeight: 700 }}>
              {t("ow.b.total", { amount: `$${payments.reduce((sum, p) => sum + (p.amount || 0), 0).toLocaleString("en-US")}` })}
            </span>
          )}
        </div>
        {payments === null && <div style={{ padding: 20, fontSize: 12, color: "var(--text-muted)" }}>{t("db.loading")}</div>}
        {payments?.length === 0 && <div style={{ padding: 20, fontSize: 12, color: "var(--text-muted)" }}>{t("ow.b.noPayments")}</div>}
        {payments?.map((p) => (
          <div key={p.id} style={{ display: "flex", alignItems: "center", justifyContent: "space-between", padding: "11px 20px", borderBottom: "1px solid var(--border)" }}>
            <div style={{ fontSize: 13, fontWeight: 700 }}>
              ${(p.amount || 0).toLocaleString("en-US")}<span style={{ fontWeight: 500, color: "var(--text-muted)", fontSize: 11 }}>{per(p.cycle === "yearly")}</span>
            </div>
            <div style={{ fontSize: 12, color: "var(--text-muted)" }}>
              {p.paid_at ? new Date(p.paid_at).toLocaleDateString(locale, { month: "short", day: "numeric", year: "numeric" }) : "—"}
            </div>
          </div>
        ))}
      </div>

      <div style={{ background: "var(--surface2)", border: "1px solid var(--border)", borderRadius: 16, overflow: "hidden", marginTop: 20 }}>
        <div style={{ padding: "14px 20px", borderBottom: "1px solid var(--border)", fontSize: 14, fontWeight: 600 }}>{t("ow.b.feeHistory")}</div>
        {(m.fee_history || []).length === 0 && <div style={{ padding: 20, fontSize: 12, color: "var(--text-muted)" }}>{t("ow.b.noHistory")}</div>}
        {(m.fee_history || []).map((h, i) => (
          <div key={i} style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 10, padding: "11px 20px", borderBottom: "1px solid var(--border)", fontSize: 13 }}>
            <span>${h.monthly_fee} {per(false)} · ${h.yearly_fee} {per(true)}</span>
            <span style={{ color: "var(--text-muted)", fontSize: 12, whiteSpace: "nowrap" }}>{new Date(h.at).toLocaleDateString(locale, { month: "short", day: "numeric", year: "numeric" })}</span>
          </div>
        ))}
      </div>
    </>
  );
}

/* ── WhatsApp tab ───────────────────────────────────────── */

function BotTab({ t, m, api, toast, onChanged, onModal }) {
  const { locale } = useLang();
  const [testing, setTesting] = useState(false);
  const retryTimer = useRef(null);

  useEffect(() => () => clearTimeout(retryTimer.current), []);

  const isActive = m.bot_status === "active";
  const isError = m.bot_status === "error";
  const statusBg = isActive ? "#25d36618" : isError ? "#ff4d4d18" : "#33333330";
  const statusBorder = isActive ? "#25d36640" : isError ? "#ff4d4d40" : "#44444440";
  const statusColor = isActive ? "var(--green)" : isError ? "var(--red)" : "var(--text-muted)";
  const statusLabel = `● ${t(isActive ? "ow.wa.ok" : isError ? "ow.wa.error" : "ow.wa.notSet")}`;
  const lastChecked = m.bot_last_checked ? timeAgo(m.bot_last_checked, locale) : t("ow.never");

  const credsOk = !!(m.bot_phone_id && (m.bot_token || m.bot_connected_via === "embedded_signup" || m.bot_connected_via === "manual"));
  const botOn = m.bot_mode !== "manual";

  function handleConnected(merchant) {
    if (!merchant) return;
    logActivity("bot", t("ow.log.connected", { shop: m.shop_name }));
    onChanged(merchant);
    setTimeout(testConnection, 800);
  }

  async function restart() {
    try {
      await api("POST", `/admin/merchants/${m.id}/bot/restart`);
      toast(t("ow.log.restarted", { shop: m.shop_name }));
      logActivity("bot", t("ow.log.restarted", { shop: m.shop_name }));
      onChanged({ ...m, bot_status: "active", bot_error: null, bot_last_checked: new Date().toISOString() });
    } catch (e) {
      toast(e.message, "error");
    }
  }

  async function reRegisterWebhook() {
    try {
      await api("POST", `/admin/merchants/${m.id}/bot/register-webhook`);
      toast(t("ow.wa.webhookDone"));
      onChanged({ ...m, bot_status: "active", bot_error: null, bot_last_checked: new Date().toISOString() });
    } catch (e) {
      toast(e.message, "error");
    }
  }

  function scheduleRetry(minutes) {
    clearTimeout(retryTimer.current);
    retryTimer.current = setTimeout(restart, minutes * 60000);
    toast(t("ow.wa.retryIn", { n: minutes }));
  }

  async function testConnection() {
    setTesting(true);
    try {
      const data = await api("POST", `/admin/merchants/${m.id}/bot/test`);
      if (data.success !== false) {
        toast(`✅ ${t("ow.wa.testOk")}`);
        onChanged({ ...m, bot_status: "active", bot_error: null, bot_last_checked: new Date().toISOString() });
      } else {
        toast(data.error || t("ow.wa.testFail"), "error");
        onChanged({ ...m, bot_status: "error", bot_error: data.error || t("ow.wa.testFail"), bot_last_checked: new Date().toISOString() });
      }
    } catch (e) {
      toast(e.message, "error");
    }
    setTesting(false);
  }

  const reconnect = () => document.getElementById(`wa-embedded-signup-${m.id}`)?.click();
  const metaStatus = { label: `↗ ${t("ow.wa.metaStatus")}`, action: () => window.open("https://metastatus.com", "_blank") };
  let quickFixes = [];
  if (isError) {
    const err = (m.bot_error || "").toLowerCase();
    if (err.match(/401|token|unauthorized|expired/)) {
      quickFixes = [
        { label: `🔄 ${t("ow.wa.reconnect")}`, primary: true, action: reconnect },
        { label: "↗ Meta Business Suite", action: () => window.open("https://business.facebook.com/settings/whatsapp-business-accounts", "_blank") },
      ];
    } else if (err.match(/webhook|not responding|no messages/)) {
      quickFixes = [
        { label: t("ow.wa.restart"), primary: true, action: restart },
        { label: t("ow.wa.webhook"), action: reRegisterWebhook },
      ];
    } else if (err.match(/missing|credentials|phone number id/)) {
      quickFixes = [{ label: t("ow.wa.connect"), primary: true, action: reconnect }];
    } else if (err.match(/banned|disabled|spam/)) {
      quickFixes = [
        { label: t("ow.wa.switchNumber"), primary: true, action: () => onModal({ type: "switchNumber", merchant: m }) },
        { label: `↗ ${t("ow.wa.appeal")}`, action: () => window.open("https://www.facebook.com/help/contact/1638046109571035", "_blank") },
      ];
    } else if (err.match(/rate|too many|limit/)) {
      quickFixes = [
        { label: `⏱ ${t("ow.wa.retryIn", { n: 15 })}`, primary: true, action: () => scheduleRetry(15) },
        { label: `⏱ ${t("ow.wa.retryIn", { n: 60 })}`, action: () => scheduleRetry(60) },
      ];
    } else if (err.match(/outage|service|down/)) {
      quickFixes = [{ label: `🔄 ${t("ow.wa.retryIn", { n: 1 })}`, primary: true, action: () => scheduleRetry(1) }, metaStatus];
    } else {
      quickFixes = [{ label: t("ow.wa.restart"), primary: true, action: restart }, metaStatus];
    }
  }

  const strip = (label, color, border, text, ltr) => (
    <div style={{ background: "var(--surface2)", border: `1px solid ${border}`, borderRadius: 8, padding: "9px 12px", minWidth: 0 }}>
      <div style={{ fontSize: 10, fontWeight: 700, color: "var(--text-muted)", letterSpacing: 0.6, marginBottom: 3 }}>{label}</div>
      <div style={{ fontSize: 12, fontWeight: 700, color, overflowWrap: ltr ? "anywhere" : "break-word" }} dir={ltr ? "ltr" : undefined}>{text}</div>
    </div>
  );

  return (
    <>
      <div style={{ background: statusBg, border: `1px solid ${statusBorder}`, borderRadius: 10, padding: "14px 16px", marginBottom: 10 }}>
        <div style={{ fontSize: 15, fontWeight: 800, color: statusColor }}>{statusLabel}</div>
        <div style={{ fontSize: 11, color: "var(--text-muted)", marginTop: 2 }}>{t("ow.wa.lastChecked")}: {lastChecked}</div>
        {isError && m.bot_error && (
          <>
            <div style={{ marginTop: 8, paddingTop: 8, borderTop: `1px solid ${statusBorder}`, fontSize: 12, color: "#ff6b6b", lineHeight: 1.5 }} dir="auto">{m.bot_error}</div>
            {quickFixes.length > 0 && (
              <div style={{ marginTop: 8, display: "flex", gap: 6, flexWrap: "wrap" }}>
                {quickFixes.map((f) => (
                  <button key={f.label} type="button" onClick={f.action} style={{
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

      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(118px, 1fr))", gap: 8, marginBottom: 14 }}>
        {strip(t("ow.wa.number"), credsOk ? "var(--green)" : "var(--text-muted)", credsOk ? "#25d36630" : "var(--border)", m.bot_display_phone || m.bot_phone_id || "—", true)}
        {strip(t("ow.wa.connection"), credsOk ? "var(--green)" : "var(--red)", credsOk ? "#25d36630" : "#ff4d4d40", t(credsOk ? "ow.wa.connected" : "ow.wa.notSet"))}
        {strip(t("ow.wa.assistant"), credsOk && botOn ? "var(--green)" : "#f59e0b", "var(--border)", t(!credsOk ? "ow.wa.notLive" : botOn ? "ow.wa.assistantOn" : "ow.wa.assistantOff"))}
      </div>

      <p style={{ fontSize: 12, color: "var(--text-muted)", marginBottom: 12, lineHeight: 1.5 }}>
        {t(credsOk ? "ow.wa.connectedHint" : "ow.wa.connectHint")}
      </p>
      {(!credsOk || isError) && (
        <WhatsAppEmbeddedSignup
          merchantId={m.id}
          api={api}
          toast={toast}
          connected={credsOk}
          buttonId={`wa-embedded-signup-${m.id}`}
          onConnected={handleConnected}
        />
      )}

      {/* Test and Restart only make sense once the number is connected. */}
      {credsOk && (
        <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 8, marginTop: isError ? 10 : 0 }}>
          <button type="button" className="paction-btn" style={{ justifyContent: "center", marginBottom: 0 }} onClick={testConnection} disabled={testing}>
            {testing ? t("ow.wa.testing") : `📡 ${t("ow.wa.test")}`}
          </button>
          <button type="button" className="paction-btn" style={{ justifyContent: "center", marginBottom: 0 }} onClick={restart}>
            {PI.play} {t("ow.wa.restart")}
          </button>
        </div>
      )}
    </>
  );
}

/** Fix what the shop entered on the join form: name, contact number, type of business, address. */
function EditShopModal({ t, m, api, toast, onClose, onSaved }) {
  const knownCity = (region, city) => getLebanonCitiesForRegion(region).some((c) => c.value === city);
  const [name, setName] = useState(m.shop_name || "");
  const [wa, setWa] = useState(m.whatsapp_number || "");
  const [type, setType] = useState(m.business_type || "");
  const [region, setRegion] = useState(m.region || "");
  const [city, setCity] = useState(knownCity(m.region, m.city) ? m.city : m.city ? JOIN_CITY_OTHER : "");
  const [cityOther, setCityOther] = useState(knownCity(m.region, m.city) ? "" : m.city || "");
  const [street, setStreet] = useState(m.street || "");
  const [busy, setBusy] = useState(false);
  const cities = getLebanonCitiesForRegion(region);

  async function save() {
    const number = lebaneseMobileError(wa) ? wa.trim() : toLebaneseWhatsapp(wa);
    setBusy(true);
    try {
      const data = await api("PATCH", `/admin/merchants/${m.id}`, {
        shopName: name.trim(), whatsappNumber: number, region, street: street.trim(),
        city: city === JOIN_CITY_OTHER || !cities.length ? cityOther.trim() : city,
        ...(type && type !== m.business_type ? { businessType: type } : {}),
      });
      toast(t("ow.edit.saved"));
      onSaved(data?.merchant);
    } catch (e) { toast(e.message, "error"); }
    setBusy(false);
  }

  return (
    <Modal title={t("ow.edit")} onClose={onClose}
      footer={<div className="modal-footer-actions">
        <button type="button" className="btn-sm" onClick={onClose}>{t("db.cancel")}</button>
        <button type="button" className="btn-primary" onClick={save} disabled={busy || !name.trim()}>{busy ? "…" : t("db.save")}</button>
      </div>}>
      <div className="field"><label htmlFor="es-name">{t("jn.shopName")}</label>
        <input id="es-name" value={name} onChange={(e) => setName(e.target.value)} /></div>
      <div className="field"><label htmlFor="es-wa">{t("ow.edit.wa")}</label>
        <input id="es-wa" dir="ltr" inputMode="tel" value={wa} onChange={(e) => setWa(e.target.value)} placeholder="+961 70 123 456" /></div>
      <div className="field"><label htmlFor="es-type">{t("jn.businessType")}</label>
        <FormSelect id="es-type" value={type} onChange={setType} aria-label={t("jn.businessType")}
          options={JOIN_BUSINESS_TYPES.map((o) => ({ value: o.value, label: t(o.labelKey) }))} />
        <div className="mk-hint">{t("ow.edit.typeHint")}</div></div>
      <div className="modal-row">
        <div className="field"><label htmlFor="es-region">{t("jn.region")}</label>
          <FormSelect id="es-region" value={region} onChange={(v) => { setRegion(v); setCity(""); }} aria-label={t("jn.region")}
            options={JOIN_LEBANON_REGIONS.map((o) => ({ value: o.value, label: t(o.labelKey) }))} /></div>
        <div className="field"><label htmlFor="es-city">{t("jn.city")}</label>
          {cities.length ? (
            <FormSelect id="es-city" value={city} onChange={setCity} aria-label={t("jn.city")}
              options={cities.map((o) => ({ value: o.value, label: t(o.labelKey) }))} />
          ) : (
            <input id="es-city" value={cityOther} onChange={(e) => setCityOther(e.target.value)} />
          )}</div>
      </div>
      {city === JOIN_CITY_OTHER && cities.length > 0 && (
        <div className="field"><label htmlFor="es-city-other">{t("jn.cityOther")}</label>
          <input id="es-city-other" value={cityOther} onChange={(e) => setCityOther(e.target.value)} /></div>
      )}
      <div className="field"><label htmlFor="es-street">{t("jn.street")}</label>
        <input id="es-street" value={street} onChange={(e) => setStreet(e.target.value)} /></div>
    </Modal>
  );
}
