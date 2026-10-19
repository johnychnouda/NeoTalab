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
                  <span className="pv-status" style={{ color: subColor, borderColor: `${subColor}40`, background: subBg }}>{subLabel}</span>
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

  const ownerName = m.owner_name && m.owner_name !== m.shop_name ? m.owner_name : null;
  const blocked = m.status === "suspended";

  return (
    <div className="pv">
      <div className="pv-owner">
        <div className="pv-owner-text">
          <div className="pv-label">{t("ow.p.ownerTitle")}</div>
          <div className="pv-owner-name">{ownerName || m.owner_phone || t("ow.p.noOwner")}</div>
          {ownerName && m.owner_phone && <div className="pv-sub" dir="ltr">{m.owner_phone}</div>}
        </div>
        {ownerPhone && (
          <button type="button" className="pv-wa" title={t("ow.p.messageOwner")} aria-label={t("ow.p.messageOwner")}
            onClick={() => window.open(`https://wa.me/${ownerPhone}`, "_blank")}>
            <WhatsAppIcon size={20} />
          </button>
        )}
      </div>

      <div className="pv-grid">
        <div className="pv-tile">
          <div className="pv-label">WhatsApp</div>
          <div className="pv-value" dir="ltr">{m.whatsapp_number || "—"}</div>
        </div>
        <div className="pv-tile">
          <div className="pv-label">{t("ow.m.business")}</div>
          <div className="pv-value">{joinOptionLabel(t, m.business_type) || "—"}</div>
        </div>
        <div className="pv-tile">
          <div className="pv-label">{t("ow.m.location")}</div>
          {m.map_url
            ? <a className="pv-btn pv-small" href={m.map_url} target="_blank" rel="noopener noreferrer">📍 {t("ow.p.openMaps")}</a>
            : <div className="pv-sub">{t("ow.p.locationNotSet")}</div>}
        </div>
        <div className="pv-tile">
          <div className="pv-label">{t("ow.p.since")}</div>
          <div className="pv-value">{memberSince}</div>
        </div>
      </div>

      <div className="pv-tile">
        <div className="pv-label">{t("ow.p.loginEmail")}</div>
        <div className="pv-login">
          <span className="pv-mono" dir="ltr">{m.login_email || "—"}</span>
          {m.login_email && <button type="button" className="pv-btn pv-small" onClick={() => copy(m.login_email)}>{t("ow.p.copy")}</button>}
        </div>
        <div className="pv-actions">
          <button type="button" className="pv-btn pv-primary pv-grow" disabled={blocked} onClick={impersonate}>{PI.screen} {t("ow.p.openDash")}</button>
          {m.slug && <button type="button" className="pv-btn pv-grow" onClick={() => copy(dashUrl)}>{t("ow.p.copyLink")}</button>}
        </div>
      </div>

      <div className="pv-actions">
        <button type="button" className="pv-btn pv-grow" onClick={() => setEditing(true)}>✏️ {t("ow.edit")}</button>
        {!blocked && <button type="button" className="pv-btn pv-grow" onClick={resendAccess}>{PI.link} {t("ow.p.resend")}</button>}
        {!blocked && m.subscription_status === "pending" && waClean && (
          <button type="button" className="pv-btn pv-grow" onClick={welcome}>{t("ow.p.sendWelcome")}</button>
        )}
      </div>

      <div className="pv-footer">
        {blocked ? (
          <button type="button" className="pv-btn pv-green" onClick={activate}>{PI.play} {t("ow.p.activate")}</button>
        ) : m.subscription_status === "trial" ? (
          <button type="button" className="pv-btn pv-warn" onClick={endTrial}>{t("ow.p.endTrial")}</button>
        ) : (
          <button type="button" className="pv-btn pv-warn" onClick={suspend}>{t("ow.p.suspend")}</button>
        )}
        <button type="button" className="pv-link-danger" onClick={remove}>{t("ow.p.remove")}</button>
      </div>

      {editing && <EditShopModal t={t} m={m} api={api} toast={toast} onClose={() => setEditing(false)}
        onSaved={(updated) => { setEditing(false); onChanged(updated); }} />}
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


  const renewalLabel = isTrial ? t("ow.b.trialEnds") : t("ow.b.nextRenewal");
  const renewalVal = isTrial ? (m.trial_ends_at ? fmtDate(m.trial_ends_at) : "—") : renewalStr;
  const renewalClr = isTrial ? (trialDaysLeft !== null && trialDaysLeft <= 2 ? "var(--orange)" : "#a78bfa") : renewalColor;
  const num = (v) => parseFloat(String(v).replace(/,/g, "")) || 0;
  // Save only appears once a fee or the cycle really changed.
  const billingDirty = num(monthlyFee) !== Number(m.monthly_fee || 0)
    || num(yearlyFee) !== Number(m.yearly_fee || 0)
    || cycle !== (m.billing_cycle === "yearly" ? "yearly" : "monthly");


  // A cycle change waits for the shop's next payment; it never marks the shop paid.
  async function saveBilling() {
    try {
      await api("PATCH", `/admin/merchants/${m.id}`, {
        nextBillingCycle: cycle,
        monthlyFee: num(monthlyFee),
        yearlyFee: num(yearlyFee),
      });
      logActivity("billing", t("ow.log.feeUpdated", { shop: m.shop_name, fee: `$${isYearly ? num(yearlyFee) : num(monthlyFee)}${per(isYearly)}` }));
      toast(t("db.saved"));
      onChanged();
    } catch (e) { toast(e.message, "error"); }
  }

  const fmtFee = (v) => v.replace(/[^0-9]/g, "").replace(/\B(?=(\d{3})+(?!\d))/g, ",");
  const historyRows = [
    ...(payments || []).map((p) => ({ key: p.id, at: p.paid_at, text: `$${(p.amount || 0).toLocaleString("en-US")} · ${t(p.cycle === "yearly" ? "ow.yearly" : "ow.monthly")}` })),
    ...(m.fee_history || []).map((h, i) => ({ key: `fee-${i}`, at: h.at, text: `${t("ow.b.feeChanged")} · $${h.monthly_fee} / $${h.yearly_fee}` })),
  ].sort((x, y) => new Date(y.at) - new Date(x.at)).slice(0, 3);

  return (
    <div className="pv">
      <div className="pv-price">
        <div>
          <div className="pv-big">${activeFee}<span>{per(isYearly)}</span></div>
          <div className="pv-sub">{renewalLabel}: <span style={{ color: renewalClr, fontWeight: 600 }}>{renewalVal}</span></div>
        </div>
        <span className="pv-status" style={{ color: billingColor, borderColor: `${billingColor}40`, background: `${billingColor}12` }}>{billingLabel}</span>
      </div>

      <div className="pv-tile">
        <div className="pv-row">
          <span className="pv-label">{t("ow.b.cycle")}</span>
          <div className="pv-seg">
            {["monthly", "yearly"].map((v) => (
              <button key={v} type="button" className={cycle === v ? "is-on" : ""} onClick={() => setCycle(v)}>
                {t(v === "yearly" ? "ow.yearly" : "ow.monthly")}
              </button>
            ))}
          </div>
        </div>
        {m.next_billing_cycle && m.next_billing_cycle !== m.billing_cycle && (
          <div className="pv-note">{t("ow.b.nextCycle", { cycle: t(m.next_billing_cycle === "yearly" ? "ow.yearly" : "ow.monthly") })}</div>
        )}
        <div className="pv-row">
          <span className="pv-label">{t("ow.b.fee")}</span>
          <div className="pv-fee" dir="ltr">
            <span>$</span>
            <input type="text" inputMode="numeric" className="no-arrows" placeholder="0" aria-label={t("ow.b.fee")}
              value={isYearly ? yearlyFee : monthlyFee}
              onChange={(e) => {
                const v = fmtFee(e.target.value);
                if (isYearly) setYearlyFee(v); else setMonthlyFee(v);
              }} />
            <small>{per(isYearly)}</small>
          </div>
        </div>
        {billingDirty && <button type="button" className="pv-btn pv-primary pv-full" onClick={saveBilling}>{t("db.save")}</button>}
      </div>

      <div className="pv-tile">
        <div className="pv-label">{t("ow.b.recent")}</div>
        {historyRows.length === 0 && <div className="pv-sub">{t("ow.b.noActivity")}</div>}
        {historyRows.map((r) => (
          <div key={r.key} className="pv-hist">
            <span>{r.text}</span>
            <span>{r.at ? new Date(r.at).toLocaleDateString(locale, { month: "short", day: "numeric", year: "numeric" }) : "—"}</span>
          </div>
        ))}
      </div>
    </div>
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

  return (
    <div className="pv">
      <div className="pv-status-card" style={{ background: statusBg, borderColor: statusBorder }}>
        <div>
          <div className="pv-big-sm" style={{ color: statusColor }}>● {t(credsOk ? "ow.wa.connected" : "ow.wa.notSet")}</div>
          <div className="pv-sub">{t("ow.wa.lastChecked")}: {lastChecked}</div>
        </div>
      </div>

      <div className="pv-grid">
        <div className="pv-tile">
          <div className="pv-label">{t("ow.wa.number")}</div>
          <div className="pv-value" dir="ltr">{m.bot_display_phone || m.bot_phone_id || "—"}</div>
        </div>
        <div className="pv-tile">
          <div className="pv-label">{t("ow.wa.assistant")}</div>
          <div className="pv-value" style={{ color: credsOk && botOn ? "var(--green)" : "#f59e0b" }}>
            {t(!credsOk ? "ow.wa.notLive" : botOn ? "ow.wa.assistantOn" : "ow.wa.assistantOff")}
          </div>
        </div>
      </div>

      {!credsOk && (
        <WhatsAppEmbeddedSignup
          merchantId={m.id}
          api={api}
          toast={toast}
          connected={credsOk}
          buttonId={`wa-embedded-signup-${m.id}`}
          onConnected={handleConnected}
        />
      )}

      {credsOk && isError && quickFixes.length > 0 && (
        <div className="pv-actions">
          {quickFixes.map((f, i) => (
            <button key={i} type="button" className={`pv-btn pv-grow ${f.primary ? "pv-primary" : ""}`} onClick={f.action}>{f.label}</button>
          ))}
        </div>
      )}

      {credsOk && (
        <div className="pv-actions">
          <button type="button" className="pv-btn pv-grow" disabled={testing} onClick={testConnection}>
            {testing ? t("ow.wa.testing") : `📡 ${t("ow.wa.test")}`}
          </button>
          <button type="button" className="pv-btn pv-grow" onClick={restart}>{PI.play} {t("ow.wa.restart")}</button>
        </div>
      )}
    </div>
  );
}

/** Fix what the shop entered on the join form: name, contact number, type of business, address. */
function EditShopModal({ t, m, api, toast, onClose, onSaved }) {
  const knownCity = (region, city) => getLebanonCitiesForRegion(region).some((c) => c.value === city);
  const [name, setName] = useState(m.shop_name || "");
  const [ownerName, setOwnerName] = useState(m.owner_name && m.owner_name !== m.shop_name ? m.owner_name : "");
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
        shopName: name.trim(), ownerName: ownerName.trim() || null, whatsappNumber: number, region, street: street.trim(),
        city: city === JOIN_CITY_OTHER || !cities.length ? cityOther.trim() : city,
        ...(type && type !== m.business_type ? { businessType: type } : {}),
      });
      toast(t("ow.edit.saved"));
      onSaved(data?.merchant);
    } catch (e) { toast(e.message, "error"); }
    setBusy(false);
  }

  return (
    <Modal title={t("ow.edit")} onClose={onClose} className="esm"
      footer={<div className="esm-actions">
        <button type="button" className="pv-btn" onClick={onClose}>{t("db.cancel")}</button>
        <button type="button" className="pv-btn pv-primary" onClick={save} disabled={busy || !name.trim()}>{busy ? "…" : t("db.save")}</button>
      </div>}>
      <div className="esm-grid">
        <div className="field"><label htmlFor="es-name">{t("jn.shopName")}</label>
          <input id="es-name" value={name} onChange={(e) => setName(e.target.value)} /></div>
        <div className="field"><label htmlFor="es-owner">{t("ow.edit.ownerName")}</label>
          <input id="es-owner" value={ownerName} onChange={(e) => setOwnerName(e.target.value)} /></div>
        <div className="field"><label htmlFor="es-wa">{t("ow.edit.wa")}</label>
          <input id="es-wa" dir="ltr" inputMode="tel" value={wa} onChange={(e) => setWa(e.target.value)} placeholder="+961 70 123 456" /></div>
        <div className="field"><label htmlFor="es-type">{t("jn.businessType")}</label>
          <FormSelect id="es-type" value={type} onChange={setType} aria-label={t("jn.businessType")}
            options={JOIN_BUSINESS_TYPES.map((o) => ({ value: o.value, label: t(o.labelKey) }))} /></div>
        <div className="field"><label htmlFor="es-region">{t("jn.region")}</label>
          <FormSelect id="es-region" value={region} onChange={(v) => { setRegion(v); setCity(""); }} aria-label={t("jn.region")}
            options={JOIN_LEBANON_REGIONS.map((o) => ({ value: o.value, label: t(o.labelKey) }))} /></div>
        <div className="field"><label htmlFor="es-city">{t("jn.city")}</label>
          {cities.length && city !== JOIN_CITY_OTHER ? (
            <FormSelect id="es-city" value={city} onChange={setCity} aria-label={t("jn.city")}
              options={cities.map((o) => ({ value: o.value, label: t(o.labelKey) }))} />
          ) : (
            <input id="es-city" value={cityOther} onChange={(e) => setCityOther(e.target.value)} placeholder={t("jn.cityOther")} />
          )}</div>
        <div className="field esm-wide"><label htmlFor="es-street">{t("jn.street")}</label>
          <input id="es-street" value={street} onChange={(e) => setStreet(e.target.value)} /></div>
      </div>
    </Modal>
  );
}
