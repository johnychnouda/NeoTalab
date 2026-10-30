"use client";

import { Suspense, useCallback, useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { useOwner } from "../layout";
import { logActivity } from "@/lib/activity";
import { SETTINGS_TABS, pillClass } from "@/lib/pillTones";
import { getSystemTimezone } from "@/lib/timezone";
import { Skeleton, ConfirmModal, useToast } from "@/components/ui";
import { setToken } from "@/lib/api";
import { PageHeader } from "@/components/owner/ui";
import WhatsAppEmbeddedSignup from "@/components/owner/WhatsAppEmbeddedSignup";
import { useLang } from "@/lib/i18n";
import { ICONS } from "@/components/auth/AuthShell";
import {
  SetCard,
  SetCardHeader,
  SetField,
  SetNote,
  SET_ICONS,
  passwordStrength,
} from "@/components/owner/SettingsUI";


const PLATFORM_NAME = "NeoTalab";
const CURRENCY = "USD";
const CUR = "$";

const TAB_SUBS = {
  pricing: "ow.set.subPricing",
  whatsapp: "ow.set.subWa",
  alerts: "ow.set.subAlerts",
  account: "ow.set.subSecurity",
};

const VALID_TABS = new Set(SETTINGS_TABS.map((t) => t.id));

function fmtPasswordChanged(iso, t, locale) {
  if (!iso) return t("ow.never");
  return new Date(iso).toLocaleDateString(locale, { month: "short", day: "numeric", year: "numeric" });
}

function fmtMoney(n, sym) {
  if (!n && n !== 0) return "—";
  const num = Number(n);
  return `${sym}${Number.isInteger(num) ? num.toLocaleString("en-US") : num.toLocaleString("en-US", { maximumFractionDigits: 2 })}`;
}

/** Yearly-vs-monthly comparison; `yearlySub` / `warning` are i18n keys. */
function calcPricing(monthly, yearly) {
  const annual = monthly * 12;
  if (!monthly || !yearly) return { saved: 0, savingsPct: 0, yearlySub: "ow.set.perShopYear", warning: null, invalid: false };
  if (yearly < monthly) return { saved: 0, savingsPct: 0, yearlySub: "ow.set.perShopYear", warning: "ow.set.warnLow", invalid: true };
  if (yearly >= annual) {
    return {
      saved: 0,
      savingsPct: 0,
      yearlySub: "ow.set.noDiscount",
      warning: yearly > annual ? "ow.set.warnHigh" : null,
      invalid: yearly > annual,
    };
  }
  const saved = annual - yearly;
  return { saved, savingsPct: Math.round((saved / annual) * 100), yearlySub: null, warning: null, invalid: false };
}

function PassInput({ value, onChange, placeholder, show, onToggle, autoComplete, id }) {
  const { t } = useLang();
  return (
    <div className="set-pass">
      <input id={id} dir="ltr" type={show ? "text" : "password"} value={value} onChange={onChange} placeholder={placeholder} autoComplete={autoComplete} />
      {value && (
        <button type="button" onClick={onToggle} aria-label={show ? t("ow.hide") : t("ow.show")} aria-pressed={show}>
          {show ? ICONS.eyeOff : ICONS.eye}
        </button>
      )}
    </div>
  );
}

function SaveBtn({ saving, label, onClick, className = "" }) {
  const { t } = useLang();
  return (
    <button type="button" className={`btn-primary set-save-btn ${className}`.trim()} disabled={!!saving} onClick={onClick}>
      {saving ? t("ow.saving") : label}
    </button>
  );
}

function SettingsSkeleton() {
  return (
    <div className="set-wrap">
      <div className="set-card">
        <div className="set-card-head">
          <Skeleton w="40%" h={20} style={{ marginBottom: 8 }} />
          <Skeleton w="70%" h={14} />
        </div>
        <div className="set-card-body">
          <Skeleton w="100%" h={44} style={{ maxWidth: 380 }} />
        </div>
      </div>
    </div>
  );
}

export default function OwnerSettingsPage() {
  const { t } = useLang();
  return (
    <Suspense fallback={
      <div className="set-page">
        <PageHeader title={t("ow.nav.settings")} subtitle={t("db.loading")} />
        <SettingsSkeleton />
      </div>
    }>
      <OwnerSettingsContent />
    </Suspense>
  );
}

function OwnerSettingsContent() {
  const { api, setPlatformName } = useOwner();
  const { t, locale } = useLang();
  const toast = useToast();
  const router = useRouter();
  const searchParams = useSearchParams();

  const initialTab = VALID_TABS.has(searchParams.get("tab")) ? searchParams.get("tab") : "pricing";
  const [tab, setTab] = useState(initialTab);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState("");
  const [showNewPass, setShowNewPass] = useState(false);
  const [showCurrentPass, setShowCurrentPass] = useState(false);
  const [showWaToken, setShowWaToken] = useState(false);
  const [waTokenConfigured, setWaTokenConfigured] = useState(false);
  const [waQr, setWaQr] = useState({ connected: false, phone: null });
  // What Meta says about the number right now: "checking", or { state: "connected" | "problem" | "not_set", phone, message }.
  const [waLive, setWaLive] = useState(null);
  const [revealedWaToken, setRevealedWaToken] = useState("");
  const [s, setS] = useState({
    price: "30", yearlyPrice: "300", trialDays: "3",
    waPhoneId: "", waToken: "", waVerifyToken: "",
    alertEmail: "", alertWhatsapp: "",
  });
  const [savedAlerts, setSavedAlerts] = useState({ alertEmail: "", alertWhatsapp: "" });
  const [mailReady, setMailReady] = useState(true);
  const [alertTest, setAlertTest] = useState("");
  const [newPass, setNewPass] = useState("");
  const [confirmPass, setConfirmPass] = useState("");
  const [pwOpen, setPwOpen] = useState(false);
  const [currentPass, setCurrentPass] = useState("");
  const [passwordChangedAt, setPasswordChangedAt] = useState(null);
  const [loginEmail, setLoginEmail] = useState("");
  const [newEmail, setNewEmail] = useState("");
  const [emailPass, setEmailPass] = useState("");
  const [showEmailPass, setShowEmailPass] = useState(false);
  const [confirmRevoke, setConfirmRevoke] = useState(false);

  const set = (k, v) => setS((x) => ({ ...x, [k]: v }));
  const cur = CUR;
  const waConfigured = !!(s.waPhoneId?.trim() && (waTokenConfigured || s.waToken?.trim()));
  const strength = passwordStrength(newPass);
  const passMatch = confirmPass.length > 0 && newPass === confirmPass;
  const passMismatch = confirmPass.length > 0 && newPass !== confirmPass;

  function selectTab(id) {
    setTab(id);
    router.replace(`/owner/settings?tab=${id}`, { scroll: false });
  }

  const load = useCallback(async () => {
    const tz = getSystemTimezone();
    setLoading(true);
    try {
      const { settings } = await api("GET", "/admin/platform-settings");
      const next = {
        price: settings.subscriptionPrice != null ? String(settings.subscriptionPrice) : "30",
        yearlyPrice: settings.subscriptionYearlyPrice != null ? String(settings.subscriptionYearlyPrice) : "300",
        trialDays: settings.trialDays != null ? String(settings.trialDays) : "3",
        waPhoneId: settings.waPhoneId ?? "",
        waToken: "",
        waVerifyToken: settings.waVerifyToken ?? "",
        alertEmail: settings.alertEmail ?? "",
        alertWhatsapp: settings.alertWhatsapp ?? "",
      };
      setSavedAlerts({ alertEmail: next.alertEmail, alertWhatsapp: next.alertWhatsapp });
      setMailReady(settings.mailReady !== false);
      setS(next);
      setWaTokenConfigured(!!settings.waTokenConfigured);
      setWaQr({ connected: !!settings.waConnectedByQr, phone: settings.waDisplayPhone || null });
      setRevealedWaToken("");
      setShowWaToken(false);
      setPasswordChangedAt(settings.passwordChangedAt ?? null);
      const me = await api("GET", "/auth/me").catch(() => null);
      const email = me?.user?.data?.email ?? me?.user?.email ?? "";
      setLoginEmail(email);
      setNewEmail(email);
      localStorage.setItem("nt_platform_name", PLATFORM_NAME);
      localStorage.setItem("nt_currency", CURRENCY);
      setPlatformName(PLATFORM_NAME);

      const patch = {};
      if (settings.timezone && settings.timezone !== tz) patch.timezone = tz;
      if (settings.currency && settings.currency !== CURRENCY) patch.currency = CURRENCY;
      if (settings.platformName && settings.platformName !== PLATFORM_NAME) patch.platform_name = PLATFORM_NAME;
      if (Object.keys(patch).length) await api("PATCH", "/admin/platform-settings", patch);
    } catch (e) {
      console.error(e);
      toast(e.message, "error");
    }
    setLoading(false);
  }, [api, setPlatformName, toast]);

  useEffect(() => {
    const q = searchParams.get("tab");
    if (q && !VALID_TABS.has(q)) router.replace("/owner/settings?tab=pricing", { scroll: false });
  }, [searchParams, router]);

  useEffect(() => { load(); }, [load]);

  const checkWa = useCallback(async () => {
    setWaLive("checking");
    try {
      setWaLive(await api("GET", "/admin/platform-settings/whatsapp-status"));
    } catch (e) {
      setWaLive({ state: "problem", message: e.message });
    }
  }, [api]);
  useEffect(() => { if (tab === "whatsapp" && !loading) checkWa(); }, [tab, loading, checkWa]);

  async function runSave(key, fn) {
    setSaving(key);
    try { await fn(); } finally { setSaving(""); }
  }

  async function changePassword() {
    if (!currentPass || !newPass) { toast(t("ow.pw.fillAll"), "error"); return; }
    if (newPass.length < 8) { toast(t("ow.pw.min"), "error"); return; }
    if (newPass !== confirmPass) { toast(t("ow.pw.mismatch"), "error"); return; }
    await runSave("save", async () => {
      const res = await api("PATCH", "/auth/password", { current_password: currentPass, password: newPass, password_confirmation: newPass });
      if (res.token) setToken("owner", res.token);
      if (res.passwordChangedAt) setPasswordChangedAt(res.passwordChangedAt);
      closePassword();
      toast(t("ow.pw.updated"));
    }).catch((e) => toast(e.message, "error"));
  }

  function closePassword() {
    setPwOpen(false);
    setCurrentPass(""); setNewPass(""); setConfirmPass("");
  }

  async function changeEmail() {
    const email = newEmail.trim().toLowerCase();
    if (!email || !emailPass) { toast(t("ow.pw.fillAll"), "error"); return; }
    if (email === loginEmail) { toast(t("ow.em.same"), "error"); return; }
    await runSave("email", async () => {
      const res = await api("PATCH", "/admin/account/email", { email, current_password: emailPass });
      if (res.token) setToken("owner", res.token);
      setLoginEmail(res.email || email);
      setNewEmail(res.email || email);
      setEmailPass("");
      toast(t("ow.em.updated", { email: res.email || email }));
    }).catch((e) => toast(e.message, "error"));
  }

  async function signOutEverywhere() {
    await runSave("revoke", async () => {
      const res = await api("POST", "/auth/revoke-other-sessions");
      if (res.token) setToken("owner", res.token);
      toast(t("ow.pw.signedOut"));
    }).catch((e) => toast(e.message, "error"));
  }

  async function savePricing() {
    const price = parseFloat(s.price);
    const yearlyPrice = parseFloat(s.yearlyPrice);
    const trialDays = parseInt(s.trialDays, 10);
    if (Number.isNaN(price) || Number.isNaN(yearlyPrice)) { toast(t("ow.set.badPrice"), "error"); return; }
    if (price < 1 || yearlyPrice < 1) { toast(t("ow.set.lowPrice"), "error"); return; }
    if (yearlyPrice < price) { toast(t("ow.set.warnLow"), "error"); return; }
    if (yearlyPrice > price * 12) { toast(t("ow.set.warnHigh"), "error"); return; }
    if (!trialDays || trialDays < 1 || trialDays > 90) { toast(t("ow.set.badTrial"), "error"); return; }
    await runSave("save", async () => {
      await api("PATCH", "/admin/platform-settings", {
        subscription_price: price, subscription_yearly_price: yearlyPrice,
        trial_days: trialDays,
      });
      logActivity("system", t("ow.log.pricing", { monthly: `${cur}${price}`, yearly: `${cur}${yearlyPrice}`, days: trialDays }));
      toast(t("db.saved"));
    }).catch((e) => toast(e.message, "error"));
  }

  async function saveWhatsApp() {
    await runSave("save", async () => {
      const payload = { wa_phone_id: s.waPhoneId, wa_verify_token: s.waVerifyToken };
      const nextToken = s.waToken.trim();
      // Don't persist a just-revealed token as a "change" unless the user edited it,
      // empty field on save keeps the existing server token.
      if (nextToken && !revealedWaToken) payload.wa_access_token = nextToken;
      if (nextToken && revealedWaToken && nextToken !== revealedWaToken) payload.wa_access_token = nextToken;
      await api("PATCH", "/admin/platform-settings", payload);
      if (payload.wa_access_token) {
        setWaTokenConfigured(true);
      }
      checkWa();
      setS((x) => ({ ...x, waToken: "" }));
      setRevealedWaToken("");
      setShowWaToken(false);
      toast(t("db.saved"));
    }).catch((e) => toast(e.message, "error"));
  }

  async function saveAlerts() {
    await runSave("save", async () => {
      const saved = { alertEmail: s.alertEmail.trim(), alertWhatsapp: s.alertWhatsapp.trim() };
      await api("PATCH", "/admin/platform-settings", { alert_email: saved.alertEmail || null, alert_whatsapp: saved.alertWhatsapp || null });
      setSavedAlerts(saved);
      setAlertTest("");
      toast(t("db.saved"));
    }).catch((e) => toast(e.message, "error"));
  }

  async function sendTestAlert() {
    if (s.alertEmail.trim() !== savedAlerts.alertEmail || s.alertWhatsapp.trim() !== savedAlerts.alertWhatsapp) {
      toast(t("ow.set.alertSaveFirst"), "error");
      return;
    }
    await runSave("test", async () => {
      const { channels = {} } = await api("POST", "/admin/system-alerts/test");
      const label = (v) => (v === "sent" ? t("ow.set.alertSent") : v === "off" ? t("ow.set.alertOff") : v);
      setAlertTest(t("ow.set.alertResult", { email: label(channels.email), whatsapp: label(channels.whatsapp) }));
    }).catch((e) => toast(e.message, "error"));
  }

  async function toggleWaTokenVisibility() {
    if (showWaToken) {
      setShowWaToken(false);
      setS((x) => ({ ...x, waToken: "" }));
      setRevealedWaToken("");
      return;
    }

    // Typing a new token, just flip input type.
    if (s.waToken.trim() && !revealedWaToken) {
      setShowWaToken(true);
      return;
    }

    if (!waTokenConfigured) {
      setShowWaToken(true);
      return;
    }

    try {
      const res = await api("GET", "/admin/platform-settings/whatsapp-access-token");
      const token = res?.waToken;
      if (!token) {
        toast(t("ow.set.noToken"), "error");
        return;
      }
      setRevealedWaToken(token);
      setS((x) => ({ ...x, waToken: token }));
      setShowWaToken(true);
    } catch (e) {
      toast(e.message, "error");
    }
  }

  const emailEdited = !!loginEmail && newEmail.trim().toLowerCase() !== loginEmail;
  const monthly = parseFloat(s.price) || 0;
  const yearly = parseFloat(s.yearlyPrice) || 0;
  const pricing = calcPricing(monthly, yearly);
  const { saved, savingsPct, yearlySub, warning: pricingWarning } = pricing;

  const headerAction = !loading && (
    tab === "pricing" ? <SaveBtn saving={saving} label={t("db.save")} onClick={savePricing} />
    : tab === "whatsapp" ? <SaveBtn saving={saving} label={t("db.save")} onClick={saveWhatsApp} />
    : tab === "alerts" ? <SaveBtn saving={saving} label={t("db.save")} onClick={saveAlerts} />
    : null
  );

  return (
    <div className="set-page">
      <PageHeader title={t("ow.nav.settings")} subtitle={t(TAB_SUBS[tab])}>{headerAction}</PageHeader>

      <div className="set-tab-shell">
        {SETTINGS_TABS.map((p) => (
          <button key={p.id} type="button" className={`set-tab-btn ${pillClass(p.tone, tab === p.id)}`} onClick={() => selectTab(p.id)}>
            <span className="set-tab-icon">{SET_ICONS[p.id]}</span>
            {t(p.key)}
          </button>
        ))}
      </div>

      {loading ? (
        <SettingsSkeleton />
      ) : (
        <div className="set-wrap" key={tab}>

          {tab === "pricing" && (
            <SetCard>
              <SetCardHeader
                icon={SET_ICONS.pricing}
                tone="orange"
                title={t("ow.set.feesTitle")}
                desc={t("ow.set.feesDesc")}
              />
              <div className="set-card-body">
                {pricingWarning && <SetNote tone="warn">{t(pricingWarning)}</SetNote>}
                <div className="set-kpis">
                  <div className="set-kpi">
                    <div className="set-kpi-label">{t("ow.monthly")}</div>
                    <div className="set-kpi-value">{fmtMoney(monthly, cur)}</div>
                    <div className="set-kpi-sub">{t("ow.set.perShopMonth")}</div>
                  </div>
                  <div className={`set-kpi${saved > 0 ? " set-kpi-accent" : ""}${pricing.invalid ? " set-kpi-warn" : ""}`}>
                    <div className="set-kpi-label">{t("ow.yearly")}</div>
                    <div className="set-kpi-value">{fmtMoney(yearly, cur)}</div>
                    <div className="set-kpi-sub">
                      {saved > 0 ? t("ow.set.saving", { pct: savingsPct, amount: fmtMoney(saved, cur) }) : t(yearlySub)}
                    </div>
                  </div>
                </div>

                <div className="set-form-block">
                  <div className="set-grid">
                    <SetField label={t("ow.set.monthlyFee")} htmlFor="set-monthly">
                      <div className="set-affix" dir="ltr">
                        <span className="set-affix-prefix">{cur}</span>
                        <input id="set-monthly" type="number" value={s.price} onChange={(e) => set("price", e.target.value)} min="1" />
                        <span className="set-affix-suffix">{t("ow.perMonth")}</span>
                      </div>
                    </SetField>
                    <SetField label={t("ow.set.yearlyFee")} htmlFor="set-yearly">
                      <div className="set-affix" dir="ltr">
                        <span className="set-affix-prefix">{cur}</span>
                        <input id="set-yearly" type="number" value={s.yearlyPrice} onChange={(e) => set("yearlyPrice", e.target.value)} min="1" />
                        <span className="set-affix-suffix">{t("ow.perYear")}</span>
                      </div>
                    </SetField>
                  </div>
                </div>

                <div className="set-form-block">
                  <div className="set-form-block-title">{t("ow.set.trialTitle")}</div>
                  <div className="set-grid">
                    <SetField label={t("ow.set.trialLabel")} htmlFor="set-trial" hint={t("ow.set.trialHint")}>
                      <div className="set-affix">
                        <input id="set-trial" type="number" value={s.trialDays} onChange={(e) => set("trialDays", e.target.value)} min="1" max="90" />
                        <span className="set-affix-suffix">{t("ow.days")}</span>
                      </div>
                    </SetField>
                  </div>
                </div>
              </div>
            </SetCard>
          )}

          {tab === "whatsapp" && (
            <SetCard>
              <SetCardHeader
                icon={SET_ICONS.whatsapp}
                tone="green"
                title={t("ow.set.waTitle")}
                desc={t("ow.set.waDesc")}
                badge={
                  <span className={`set-badge ${waLive?.state === "connected" ? "ok" : "warn"}`}>
                    <span className="set-badge-dot" />
                    {waLive === "checking" || waLive === null
                      ? t("ow.wa.checking")
                      : waLive.state === "connected"
                        ? `${t("ow.wa.connected")}${waLive.phone ? ` · ${waLive.phone}` : ""}`
                        : t(waLive.state === "problem" ? "ow.wa.problem" : "ow.wa.notSet")}
                  </span>
                }
              />
              {waLive && waLive !== "checking" && (
                <p className="set-hint" role="status">
                  {waLive.state === "problem" && `${waLive.message} `}
                  {waLive.state === "connected" && waLive.name_status === "NON_EXISTS" && `${t("ow.wa.nameMissing")} `}
                  <button type="button" className="btn-sm" onClick={checkWa}>{t("ow.wa.recheck")}</button>
                </p>
              )}
              <SetNote tone="wa">{t("ow.set.waNote")}</SetNote>
              <div className="set-card-body">
                <div className="set-form-block">
                  <div className="set-form-block-title">{t("ow.set.waQrTitle")}</div>
                  <p className="set-hint">
                    {waQr.connected ? t("ow.set.waQrConnected", { phone: waQr.phone || s.waPhoneId }) : t("ow.set.waQrHint")}
                  </p>
                  <WhatsAppEmbeddedSignup
                    api={api}
                    toast={toast}
                    endpoint="/admin/whatsapp/platform/embedded-signup"
                    connected={waQr.connected}
                    label={t("ow.set.waQrConnect")}
                    buttonId="wa-embedded-signup-platform"
                    onConnected={() => { logActivity("bot", t("ow.set.waQrDone")); load(); }}
                  />
                </div>
                <div className="set-grid">
                  <SetField label={t("ow.set.phoneId")} className="span-full" htmlFor="set-phone-id">
                    <input id="set-phone-id" dir="ltr" className="set-mono" value={s.waPhoneId} onChange={(e) => set("waPhoneId", e.target.value)} placeholder="1234567890" />
                  </SetField>
                  <SetField label={t("ow.set.token")} hint={waTokenConfigured && !s.waToken ? t("ow.set.tokenHint") : undefined}>
                    <PassInput
                      value={s.waToken}
                      onChange={(e) => {
                        setRevealedWaToken("");
                        set("waToken", e.target.value);
                      }}
                      placeholder={waTokenConfigured && !s.waToken ? "••••••••" : "EAA…"}
                      show={showWaToken}
                      onToggle={toggleWaTokenVisibility}
                    />
                  </SetField>
                  <SetField
                    label={t("ow.set.verify")}
                    hint={t("ow.set.verifyHint")}
                    htmlFor="set-verify"
                  >
                    <input id="set-verify" dir="ltr" className="set-mono" value={s.waVerifyToken} onChange={(e) => set("waVerifyToken", e.target.value)} />
                  </SetField>
                </div>
              </div>
              <div className="set-card-foot between">
                <a href="https://business.facebook.com/settings/whatsapp-business-accounts" target="_blank" rel="noreferrer" className="set-link set-link-btn">
                  Meta Business Suite ↗
                </a>
              </div>
            </SetCard>
          )}

          {tab === "alerts" && (
            <SetCard>
              <SetCardHeader icon={SET_ICONS.alerts} tone="green" title={t("ow.set.alertsTitle")} desc={t("ow.set.alertsDesc")} />
              <div className="set-card-body">
                <div className="set-grid">
                  <SetField label={t("ow.set.alertEmail")} className="span-full" htmlFor="set-alert-email"
                    hint={s.alertEmail.trim() && !mailReady ? t("ow.set.alertEmailNotReady") : t("ow.set.alertEmailHint")}>
                    <input id="set-alert-email" type="email" dir="ltr" autoComplete="email" value={s.alertEmail}
                      onChange={(e) => set("alertEmail", e.target.value)} placeholder="you@neotalab.com" />
                  </SetField>
                  <SetField label={t("ow.set.alertWa")} className="span-full" htmlFor="set-alert-wa" hint={t("ow.set.alertWaHint")}>
                    <input id="set-alert-wa" type="tel" dir="ltr" inputMode="tel" value={s.alertWhatsapp}
                      onChange={(e) => set("alertWhatsapp", e.target.value)} placeholder="+961 70 000 000" />
                  </SetField>
                </div>
              </div>
              <div className="set-card-foot between">
                <span className="set-hint" role="status" dir="auto">{alertTest}</span>
                <button type="button" className="btn-sm" onClick={sendTestAlert} disabled={saving === "test" || (!savedAlerts.alertEmail && !savedAlerts.alertWhatsapp)}>
                  {saving === "test" ? t("ow.set.alertTesting") : t("ow.set.alertTest")}
                </button>
              </div>
            </SetCard>
          )}

          {tab === "account" && (
            <SetCard>
              <SetCardHeader
                icon={SET_ICONS.account}
                tone="neutral"
                title={t("ow.set.tabSecurity")}
                desc={t("ow.set.subSecurity")}
              />
              <div className="set-card-body">
                <div className="set-form-block">
                  <div className="set-form-block-title">{t("ow.em.title")}</div>
                  <p className="set-hint set-security-hint">{t("ow.em.hint")}</p>
                  <div className="set-grid">
                    <SetField label={t("login.email")} htmlFor="login-email">
                      <input id="login-email" type="email" dir="ltr" value={newEmail} onChange={(e) => setNewEmail(e.target.value)} autoComplete="email" />
                    </SetField>
                    {emailEdited && (
                      <SetField label={t("ow.pw.current")} htmlFor="email-pass">
                        <PassInput id="email-pass" value={emailPass} onChange={(e) => setEmailPass(e.target.value)} show={showEmailPass} onToggle={() => setShowEmailPass((v) => !v)} autoComplete="current-password" />
                      </SetField>
                    )}
                  </div>
                  {emailEdited && (
                    <div className="set-inline-actions">
                      <button type="button" className="btn-primary btn-sm" onClick={changeEmail} disabled={saving === "email" || !newEmail.trim()}>
                        {saving === "email" ? t("ow.saving") : t("ow.em.update")}
                      </button>
                      <button type="button" className="btn-sm" onClick={() => { setNewEmail(loginEmail); setEmailPass(""); }}>{t("db.cancel")}</button>
                    </div>
                  )}
                </div>

                <div className="set-form-block set-form-block-spaced">
                  <div className="set-form-block-title">{t("login.password")}</div>
                  <p className="set-security-meta">
                    {t("ow.pw.lastChanged")}: <strong>{fmtPasswordChanged(passwordChangedAt, t, locale)}</strong>
                  </p>
                  {!pwOpen && (
                    <button type="button" className="btn-sm" onClick={() => setPwOpen(true)}>{t("ow.pw.update")}</button>
                  )}
                  {pwOpen && (<>
                  <div className="set-grid">
                    <SetField label={t("ow.pw.current")} className="span-full set-field-narrow" htmlFor="current-pass">
                      <PassInput id="current-pass" value={currentPass} onChange={(e) => setCurrentPass(e.target.value)} show={showCurrentPass} onToggle={() => setShowCurrentPass((v) => !v)} autoComplete="current-password" />
                    </SetField>
                    <SetField label={t("ow.pw.new")} htmlFor="new-pass">
                      <PassInput id="new-pass" value={newPass} onChange={(e) => setNewPass(e.target.value)} placeholder={t("ow.pw.min")} show={showNewPass} onToggle={() => setShowNewPass((v) => !v)} autoComplete="new-password" />
                      {newPass && (
                        <div className="set-strength">
                          <div className="set-strength-track">
                            <div className={`set-strength-fill set-strength-${strength.tone}`} style={{ width: strength.width }} />
                          </div>
                          {strength.label && <span className={`set-strength-label set-strength-${strength.tone}`}>{t(strength.label)}</span>}
                        </div>
                      )}
                    </SetField>
                    <SetField label={t("ow.pw.confirm")} htmlFor="confirm-pass">
                      <PassInput id="confirm-pass" value={confirmPass} onChange={(e) => setConfirmPass(e.target.value)} show={showNewPass} onToggle={() => setShowNewPass((v) => !v)} autoComplete="new-password" />
                      {passMatch && <p className="set-hint set-hint-ok">{t("ow.pw.match")}</p>}
                      {passMismatch && <p className="set-hint set-hint-err">{t("ow.pw.mismatch")}</p>}
                    </SetField>
                  </div>
                  <div className="set-inline-actions">
                    <button type="button" className="btn-primary btn-sm" onClick={changePassword} disabled={saving === "save"}>
                      {saving === "save" ? t("ow.saving") : t("db.save")}
                    </button>
                    <button type="button" className="btn-sm" onClick={closePassword}>{t("db.cancel")}</button>
                  </div>
                  </>)}
                </div>

                <div className="set-form-block set-form-block-spaced">
                  <div className="set-form-block-title">{t("ow.pw.sessions")}</div>
                  <p className="set-hint set-security-hint">{t("ow.pw.sessionsHint")}</p>
                  <button
                    type="button"
                    className="btn-sm set-signout-btn"
                    disabled={saving === "revoke"}
                    onClick={() => setConfirmRevoke(true)}
                  >
                    {saving === "revoke" ? t("ow.saving") : t("ow.pw.everywhere")}
                  </button>
                </div>
              </div>
            </SetCard>
          )}

        </div>
      )}

      {confirmRevoke && (
        <ConfirmModal
          title={t("ow.pw.everywhere")}
          message={t("ow.pw.sessionsHint")}
          confirmLabel={t("ow.pw.everywhere")}
          danger
          onConfirm={signOutEverywhere}
          onClose={() => setConfirmRevoke(false)}
        />
      )}
    </div>
  );
}
