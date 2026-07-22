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
import SetMenu from "@/components/owner/SetMenu";
import {
  SetCard,
  SetCardHeader,
  SetField,
  SetNote,
  SET_ICONS,
  passwordStrength,
} from "@/components/owner/SettingsUI";

const DEFAULT_RENEWAL_MSG = `Hello {shop} 👋

Your NeoTalab subscription renews in {days} day(s) — on {date}.

💳 Amount due: {amount}

Please settle your payment before then to keep your WhatsApp bot running smoothly.

Need help? Just reply here! 🙌`;

const REMIND_OPTIONS = [
  { value: "1", label: "1 day before" },
  { value: "3", label: "3 days before" },
  { value: "5", label: "5 days before" },
  { value: "7", label: "7 days before" },
  { value: "14", label: "14 days before" },
];
const PLATFORM_NAME = "NeoTalab";
const CURRENCY = "USD";
const CUR = "$";

const TAB_SUBS = {
  pricing: "Default subscription fees and trial rules for new merchants.",
  whatsapp: "Platform WhatsApp credentials for welcomes and billing reminders.",
  notifications: "Automatic WhatsApp sent before subscriptions renew.",
  account: "Password and session security for the owner portal.",
};

const VALID_TABS = new Set(SETTINGS_TABS.map((t) => t.id));

function syncNotificationLocal(s) {
  if (typeof window === "undefined") return;
  localStorage.setItem("nt_overdue_remind_days", String(s.overdueDays ?? "3"));
  localStorage.setItem("nt_overdue_msg", s.overdueMsg || DEFAULT_RENEWAL_MSG);
  localStorage.setItem("nt_currency", CURRENCY);
}

function fmtPasswordChanged(iso) {
  if (!iso) return "Never";
  return new Date(iso).toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" });
}

function fmtMoney(n, sym) {
  if (!n && n !== 0) return "—";
  const num = Number(n);
  return `${sym}${Number.isInteger(num) ? num.toLocaleString("en-US") : num.toLocaleString("en-US", { maximumFractionDigits: 2 })}`;
}

function calcPricing(monthly, yearly) {
  const annual = monthly * 12;
  if (!monthly || !yearly) {
    return { saved: 0, savingsPct: 0, savingsNote: "", yearlySub: "Per shop / year", warning: null, invalid: false };
  }
  if (yearly < monthly) {
    return {
      saved: 0,
      savingsPct: 0,
      savingsNote: "",
      yearlySub: "Lower than monthly fee",
      warning: "Yearly fee is less than one month — check your numbers.",
      invalid: true,
    };
  }
  if (yearly >= annual) {
    return {
      saved: 0,
      savingsPct: 0,
      savingsNote: yearly === annual ? "Same as monthly × 12" : "More than monthly × 12",
      yearlySub: yearly === annual ? "No discount vs monthly" : "Costs more than paying monthly",
      warning: yearly > annual ? "Yearly plan costs more than 12 monthly payments — merchants will pick monthly." : null,
      invalid: yearly > annual,
    };
  }
  const saved = annual - yearly;
  const savingsPct = Math.round((saved / annual) * 100);
  return {
    saved,
    savingsPct,
    savingsNote: `${savingsPct}% off vs monthly`,
    yearlySub: `${savingsPct}% off vs monthly`,
    warning: null,
    invalid: false,
  };
}

function PassInput({ value, onChange, placeholder, show, onToggle, autoComplete, id }) {
  return (
    <div className="set-pass">
      <input id={id} type={show ? "text" : "password"} value={value} onChange={onChange} placeholder={placeholder} autoComplete={autoComplete} />
      <button type="button" onClick={onToggle}>{show ? "Hide" : "Show"}</button>
    </div>
  );
}

function SaveBtn({ saving, label, onClick, className = "" }) {
  return (
    <button type="button" className={`btn-primary set-save-btn ${className}`.trim()} disabled={!!saving} onClick={onClick}>
      {saving ? "Saving…" : label}
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
  return (
    <Suspense fallback={
      <div className="set-page">
        <PageHeader title="Settings" subtitle="Loading…" />
        <SettingsSkeleton />
      </div>
    }>
      <OwnerSettingsContent />
    </Suspense>
  );
}

function OwnerSettingsContent() {
  const { api, setPlatformName } = useOwner();
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
  const [s, setS] = useState({
    price: "29", yearlyPrice: "290", trialDays: "7", graceDays: "3",
    waPhoneId: "", waToken: "", waVerifyToken: "",
    overdueDays: "3", overdueMsg: DEFAULT_RENEWAL_MSG,
  });
  const [newPass, setNewPass] = useState("");
  const [confirmPass, setConfirmPass] = useState("");
  const [currentPass, setCurrentPass] = useState("");
  const [passwordChangedAt, setPasswordChangedAt] = useState(null);
  const [confirmRevoke, setConfirmRevoke] = useState(false);

  const set = (k, v) => setS((x) => ({ ...x, [k]: v }));
  const cur = CUR;
  const waConfigured = !!(s.waPhoneId?.trim() && s.waToken?.trim());
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
      const { settings } = await api("GET", "/api/owner/settings");
      const next = {
        price: settings.subscriptionPrice != null ? String(settings.subscriptionPrice) : "29",
        yearlyPrice: settings.subscriptionYearlyPrice != null ? String(settings.subscriptionYearlyPrice) : "290",
        trialDays: settings.trialDays != null ? String(settings.trialDays) : "7",
        graceDays: settings.gracePeriodDays != null ? String(settings.gracePeriodDays) : "3",
        waPhoneId: settings.waPhoneId ?? "",
        waToken: settings.waToken ?? "",
        waVerifyToken: settings.waVerifyToken ?? "",
        overdueDays: REMIND_OPTIONS.some((o) => o.value === String(settings.overdueDays))
          ? String(settings.overdueDays)
          : "3",
        overdueMsg: settings.overdueReminderMsg ?? DEFAULT_RENEWAL_MSG,
      };
      setS(next);
      setPasswordChangedAt(settings.passwordChangedAt ?? null);
      syncNotificationLocal(next);
      localStorage.setItem("nt_platform_name", PLATFORM_NAME);
      localStorage.setItem("nt_currency", CURRENCY);
      setPlatformName(PLATFORM_NAME);

      const patch = {};
      if (settings.timezone && settings.timezone !== tz) patch.timezone = tz;
      if (settings.currency && settings.currency !== CURRENCY) patch.currency = CURRENCY;
      if (settings.platformName && settings.platformName !== PLATFORM_NAME) patch.platformName = PLATFORM_NAME;
      if (Object.keys(patch).length) await api("PATCH", "/api/owner/settings", patch);
    } catch (e) {
      console.error(e);
      toast("Could not load settings — is the API running?", "error");
    }
    setLoading(false);
  }, [api, setPlatformName, toast]);

  useEffect(() => {
    const q = searchParams.get("tab");
    if (q && !VALID_TABS.has(q)) router.replace("/owner/settings?tab=pricing", { scroll: false });
  }, [searchParams, router]);

  useEffect(() => { load(); }, [load]);

  async function runSave(key, fn) {
    setSaving(key);
    try { await fn(); } finally { setSaving(""); }
  }

  async function changePassword() {
    if (!currentPass || !newPass) { toast("Fill in all fields", "error"); return; }
    if (newPass.length < 8) { toast("Password must be at least 8 characters", "error"); return; }
    if (newPass !== confirmPass) { toast("Passwords don't match", "error"); return; }
    await runSave("save", async () => {
      const res = await api("PATCH", "/api/owner/password", { currentPassword: currentPass, newPassword: newPass });
      if (res.token) setToken("owner", res.token);
      if (res.passwordChangedAt) setPasswordChangedAt(res.passwordChangedAt);
      setCurrentPass(""); setNewPass(""); setConfirmPass("");
      toast("Password updated!");
    }).catch((e) => toast(e.message, "error"));
  }

  async function signOutEverywhere() {
    await runSave("revoke", async () => {
      const res = await api("POST", "/api/owner/sessions/revoke");
      if (res.token) setToken("owner", res.token);
      toast("Signed out on all other devices");
    }).catch((e) => toast(e.message, "error"));
  }

  async function savePricing() {
    const price = parseFloat(s.price);
    const yearlyPrice = parseFloat(s.yearlyPrice);
    if (!price || price < 1 || !yearlyPrice || yearlyPrice < 1) { toast("Enter valid prices", "error"); return; }
    if (yearlyPrice < price) { toast("Yearly fee must be at least the monthly fee", "error"); return; }
    if (yearlyPrice > price * 12) { toast("Yearly fee can't exceed 12× the monthly fee", "error"); return; }
    await runSave("save", async () => {
      await api("PATCH", "/api/owner/settings", {
        subscriptionPrice: price, subscriptionYearlyPrice: yearlyPrice,
        trialDays: parseInt(s.trialDays) || 0, gracePeriodDays: parseInt(s.graceDays) || 3,
      });
      logActivity("system", `Pricing updated — ${cur}${price}/mo · ${cur}${yearlyPrice}/yr`);
      toast("Saved!");
    }).catch((e) => toast(e.message, "error"));
  }

  async function saveWhatsApp() {
    await runSave("save", async () => {
      await api("PATCH", "/api/owner/settings", { waPhoneId: s.waPhoneId, waToken: s.waToken, waVerifyToken: s.waVerifyToken });
      toast("Saved!");
    }).catch((e) => toast(e.message, "error"));
  }

  async function saveNotifications() {
    if (!s.overdueMsg.trim()) { toast("Message can't be empty", "error"); return; }
    const days = parseInt(s.overdueDays, 10);
    if (!REMIND_OPTIONS.some((o) => o.value === String(days))) { toast("Choose a reminder timing", "error"); return; }
    await runSave("save", async () => {
      await api("PATCH", "/api/owner/settings", {
        overdueDays: days,
        overdueReminderMsg: s.overdueMsg.trim(),
      });
      syncNotificationLocal(s);
      toast("Saved!");
    }).catch((e) => toast(e.message, "error"));
  }

  function insertVar(v) {
    set("overdueMsg", s.overdueMsg ? `${s.overdueMsg}${/[ \n]$/.test(s.overdueMsg) ? "" : " "}${v}` : v);
  }

  function resetOverdueMsg() {
    set("overdueMsg", DEFAULT_RENEWAL_MSG);
  }

  const msgIsDefault = s.overdueMsg.trim() === DEFAULT_RENEWAL_MSG.trim();

  const monthly = parseFloat(s.price) || 0;
  const yearly = parseFloat(s.yearlyPrice) || 0;
  const pricing = calcPricing(monthly, yearly);
  const { saved, savingsPct, yearlySub, warning: pricingWarning } = pricing;

  const headerAction = !loading && (
    tab === "pricing" ? <SaveBtn saving={saving} label="Save pricing" onClick={savePricing} />
    : tab === "whatsapp" ? <SaveBtn saving={saving} label="Save WhatsApp" onClick={saveWhatsApp} />
    : tab === "notifications" ? <SaveBtn saving={saving} label="Save message" onClick={saveNotifications} />
    : tab === "account" ? <SaveBtn saving={saving} label="Update password" onClick={changePassword} />
    : null
  );

  const VAR_TAGS = [
    { v: "{shop}", label: "Shop name" },
    { v: "{days}", label: "Days until renewal" },
    { v: "{date}", label: "Renewal date" },
    { v: "{amount}", label: "Amount due" },
  ];

  return (
    <div className="set-page">
      <PageHeader title="Settings" subtitle={TAB_SUBS[tab]}>{headerAction}</PageHeader>

      <div className="set-tab-shell">
        {SETTINGS_TABS.map((t) => (
          <button key={t.id} type="button" className={`set-tab-btn ${pillClass(t.tone, tab === t.id)}`} onClick={() => selectTab(t.id)}>
            <span className="set-tab-icon">{SET_ICONS[t.id]}</span>
            {t.label}
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
                title="Subscription fees"
                desc="Applied to new merchants. Override per shop from the Merchants page."
              />
              <div className="set-card-body">
                {pricingWarning && <SetNote tone="warn">{pricingWarning}</SetNote>}
                <div className="set-kpis">
                  <div className="set-kpi">
                    <div className="set-kpi-label">Monthly</div>
                    <div className="set-kpi-value">{fmtMoney(monthly, cur)}</div>
                    <div className="set-kpi-sub">Per shop / month</div>
                  </div>
                  <div className={`set-kpi${saved > 0 ? " set-kpi-accent" : ""}${pricing.invalid ? " set-kpi-warn" : ""}`}>
                    <div className="set-kpi-label">Yearly</div>
                    <div className="set-kpi-value">{fmtMoney(yearly, cur)}</div>
                    <div className="set-kpi-sub">
                      {saved > 0
                        ? `${savingsPct}% off · ${fmtMoney(saved, cur)} saved per shop`
                        : yearlySub || "Per shop / year"}
                    </div>
                  </div>
                </div>

                <div className="set-form-block">
                  <div className="set-form-block-title">Billing amounts</div>
                  <div className="set-grid">
                    <SetField label="Monthly fee">
                      <div className="set-affix">
                        <span className="set-affix-prefix">{cur.trim() || "$"}</span>
                        <input type="number" value={s.price} onChange={(e) => set("price", e.target.value)} min="1" />
                        <span className="set-affix-suffix">/mo</span>
                      </div>
                    </SetField>
                    <SetField label="Yearly fee">
                      <div className="set-affix">
                        <span className="set-affix-prefix">{cur.trim() || "$"}</span>
                        <input type="number" value={s.yearlyPrice} onChange={(e) => set("yearlyPrice", e.target.value)} min="1" />
                        <span className="set-affix-suffix">/yr</span>
                      </div>
                    </SetField>
                  </div>
                </div>

                <div className="set-form-block">
                  <div className="set-form-block-title">Trial & grace</div>
                  <div className="set-grid">
                    <SetField label="Free trial">
                      <div className="set-affix">
                        <input type="number" value={s.trialDays} onChange={(e) => set("trialDays", e.target.value)} min="0" max="90" />
                        <span className="set-affix-suffix">days</span>
                      </div>
                    </SetField>
                    <SetField label="Grace period" hint="After due date before marked overdue.">
                      <div className="set-affix">
                        <input type="number" value={s.graceDays} onChange={(e) => set("graceDays", e.target.value)} min="0" max="30" />
                        <span className="set-affix-suffix">days</span>
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
                title="WhatsApp API"
                desc="Platform number for welcomes and payment reminders — not individual shop bots."
                badge={
                  <span className={`set-badge ${waConfigured ? "ok" : "warn"}`}>
                    <span className="set-badge-dot" />
                    {waConfigured ? "Connected" : "Not configured"}
                  </span>
                }
              />
              <SetNote tone="wa">
                Each merchant connects their own bot under Merchants → Bot. This number is only for platform-level messages.
              </SetNote>
              <div className="set-card-body">
                <div className="set-grid">
                  <SetField label="Phone number ID" className="span-full">
                    <input className="set-mono" value={s.waPhoneId} onChange={(e) => set("waPhoneId", e.target.value)} placeholder="From Meta Business Suite" />
                  </SetField>
                  <SetField label="Access token">
                    <PassInput value={s.waToken} onChange={(e) => set("waToken", e.target.value)} placeholder="EAAxxxxxx…" show={showWaToken} onToggle={() => setShowWaToken((v) => !v)} />
                  </SetField>
                  <SetField label="Webhook verify token">
                    <input className="set-mono" value={s.waVerifyToken} onChange={(e) => set("waVerifyToken", e.target.value)} placeholder="Your secret token" />
                  </SetField>
                </div>
              </div>
              <div className="set-card-foot between">
                <a href="https://business.facebook.com/settings/whatsapp-business-accounts" target="_blank" rel="noreferrer" className="set-link set-link-btn">
                  Open Meta Business Suite ↗
                </a>
              </div>
            </SetCard>
          )}

          {tab === "notifications" && (
            <SetCard>
              <SetCardHeader
                icon={SET_ICONS.notifications}
                tone="blue"
                title="Renewal reminders"
                desc="WhatsApp message merchants receive before their subscription ends."
                badge={
                  <span className="set-badge ok">
                    <span className="set-badge-dot" />
                    Automatic
                  </span>
                }
              />
              <div className="set-card-body">
                <SetField label="Remind before renewal" className="set-field-narrow" hint="How many days before the subscription ends.">
                  <SetMenu
                    value={s.overdueDays}
                    onChange={(v) => set("overdueDays", v)}
                    options={REMIND_OPTIONS}
                    aria-label="Days before renewal"
                  />
                </SetField>

                <div className="set-form-block set-form-block-spaced">
                  <div className="set-form-block-title">Message</div>
                  <div className="set-notify-toolbar">
                    <div className="set-var-chips">
                      {VAR_TAGS.map(({ v, label }) => (
                        <button key={v} type="button" className="set-var-chip" title={label} onClick={() => insertVar(v)}>
                          {v}
                        </button>
                      ))}
                    </div>
                    <div className="set-notify-toolbar-right">
                      {!msgIsDefault && (
                        <button type="button" className="set-text-btn" onClick={resetOverdueMsg}>Reset default</button>
                      )}
                      <span className="set-editor-count">{s.overdueMsg.length} chars</span>
                    </div>
                  </div>
                  <textarea
                    rows={9}
                    className="set-textarea set-textarea-compact"
                    value={s.overdueMsg}
                    onChange={(e) => set("overdueMsg", e.target.value)}
                    placeholder="Write your reminder message…"
                  />
                </div>
              </div>
            </SetCard>
          )}

          {tab === "account" && (
            <SetCard>
              <SetCardHeader
                icon={SET_ICONS.account}
                tone="neutral"
                title="Security"
                desc="Manage your owner portal password and active sessions."
              />
              <div className="set-card-body">
                <div className="set-form-block">
                  <div className="set-form-block-title">Password</div>
                  <p className="set-security-meta">
                    Last changed: <strong>{fmtPasswordChanged(passwordChangedAt)}</strong>
                  </p>
                  <div className="set-grid">
                    <SetField label="Current password" className="span-full set-field-narrow" htmlFor="current-pass">
                      <PassInput id="current-pass" value={currentPass} onChange={(e) => setCurrentPass(e.target.value)} placeholder="Enter current password" show={showCurrentPass} onToggle={() => setShowCurrentPass((v) => !v)} autoComplete="current-password" />
                    </SetField>
                    <SetField label="New password" htmlFor="new-pass">
                      <PassInput id="new-pass" value={newPass} onChange={(e) => setNewPass(e.target.value)} placeholder="Min. 8 characters" show={showNewPass} onToggle={() => setShowNewPass((v) => !v)} autoComplete="new-password" />
                      {newPass && (
                        <div className="set-strength">
                          <div className="set-strength-track">
                            <div className={`set-strength-fill set-strength-${strength.tone}`} style={{ width: strength.width }} />
                          </div>
                          {strength.label && <span className={`set-strength-label set-strength-${strength.tone}`}>{strength.label}</span>}
                        </div>
                      )}
                    </SetField>
                    <SetField label="Confirm password" htmlFor="confirm-pass">
                      <PassInput id="confirm-pass" value={confirmPass} onChange={(e) => setConfirmPass(e.target.value)} placeholder="Repeat new password" show={showNewPass} onToggle={() => setShowNewPass((v) => !v)} autoComplete="new-password" />
                      {passMatch && <p className="set-hint set-hint-ok">Passwords match</p>}
                      {passMismatch && <p className="set-hint set-hint-err">Passwords don't match</p>}
                    </SetField>
                  </div>
                </div>

                <div className="set-form-block set-form-block-spaced">
                  <div className="set-form-block-title">Sessions</div>
                  <p className="set-hint set-security-hint">
                    End all other active logins. You will stay signed in on this device.
                  </p>
                  <button
                    type="button"
                    className="btn-sm set-signout-btn"
                    disabled={saving === "revoke"}
                    onClick={() => setConfirmRevoke(true)}
                  >
                    {saving === "revoke" ? "Signing out…" : "Sign out everywhere"}
                  </button>
                </div>
              </div>
            </SetCard>
          )}

        </div>
      )}

      {confirmRevoke && (
        <ConfirmModal
          title="Sign out everywhere?"
          message="This ends all other active sessions. You will stay signed in on this device."
          confirmLabel="Sign out everywhere"
          danger
          onConfirm={signOutEverywhere}
          onClose={() => setConfirmRevoke(false)}
        />
      )}
    </div>
  );
}
