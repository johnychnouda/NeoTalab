"use client";

import { Suspense, useCallback, useEffect, useRef, useState } from "react";
import { useSearchParams } from "next/navigation";
import { useMerchant } from "../../layout";
import { useLang } from "@/lib/i18n";
import { setToken } from "@/lib/api";
import { useToast } from "@/components/ui";
import { writeUrlParam } from "@/lib/urlParam";
import FormSelect from "@/components/FormSelect";
import Segmented from "@/components/Segmented";
import LocationPicker, { mapPin } from "@/components/LocationPicker";
import { ICONS } from "@/components/auth/AuthShell";
import ZoneEditor from "@/components/ZoneEditor";
import { JOIN_LEBANON_REGIONS } from "@/lib/joinFormOptions";
import { addressAfterPin } from "@/lib/pickupAddress";

const LBP_RATE = 89500;

const iconProps = { viewBox: "0 0 24 24", width: 22, height: 22, fill: "none", stroke: "currentColor", strokeWidth: 1.8, strokeLinecap: "round", strokeLinejoin: "round" };
/** Drawn icons for the three ways customers get an order. */
const FULFILLMENT_ICONS = {
  delivery: <svg {...iconProps}><circle cx="6" cy="17" r="2.5" /><circle cx="18" cy="17" r="2.5" /><path d="M8.5 17h5l2-7h-3M15.5 10l1.5 7M6 17l-1-5h5" /></svg>,
  pickup: <svg {...iconProps}><path d="M4 9l1.5-5h13L20 9M4 9v10h16V9M4 9h16M9 19v-5h6v5" /></svg>,
  both: <svg {...iconProps}><path d="M7 7h12l-3-3M17 17H5l3 3" /></svg>,
};
const num = (v) => (v === "" || v === null || v === undefined ? null : Number(v));

/**
 * What each tab sends to PATCH /settings/profile. A tab counts as changed when its body differs
 * from the saved profile, and one Save sends every changed tab together.
 */
const GROUPS = {
  shop: (p) => ({ shopName: p.shop_name || "", opsWhatsapp: p.ops_whatsapp || "", defaultLocale: p.default_locale || "en" }),
  orders: (p) => ({
    auto_accept: p.auto_accept !== false, fulfillment: p.fulfillment || "delivery", pickup_address: p.pickup_address || null, shop_location: p.shop_location || null,
    region: p.region || null, city: p.city || null,
    prep_time_mins: num(p.prep_time_mins), prep_hours: num(p.prep_hours),
    delivery_days_min: num(p.delivery_days_min), delivery_days_max: num(p.delivery_days_max),
    late_alert_mins: num(p.late_alert_mins),
  }),
  payments: (p) => ({
    whish_number: p.whish_number || "", wishTimeoutMins: parseInt(p.wish_timeout_mins, 10) || 15, lbp_rate: num(p.lbp_rate),
  }),
  alerts: (p) => ({ report_whatsapp: p.report_whatsapp || null, report_hour: num(p.report_hour), bot_pause_mins: num(p.bot_pause_mins) }),
};
// The job steps are typed one per line; empty lines are dropped, at most 8 are kept.
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
// Lebanese numbers typed any way ("+961 70 123 456", "0096170123456", "70 123 456") compare equal.

export default function SettingsPage() {
  return <Suspense fallback={null}><Settings /></Suspense>;
}

/**
 * Shop setup, in tabs: Shop · Orders · Delivery areas · Opening hours · Payments · Reports & chats.
 * Edits on any tab wait in one "unsaved changes" bar; delivery areas save as soon as they are added.
 * ?tab= opens a tab directly (links from the Get started checklist).
 */
function Settings() {
  const { api, refreshProfile, shop } = useMerchant();
  const { t } = useLang();
  const toast = useToast();
  const params = useSearchParams();

  const [picked, pickTab] = useState(params.get("tab") || "shop");
  const setTab = (id) => { pickTab(id); writeUrlParam("tab", id, "shop"); };
  const [saved, setSaved] = useState(null);
  const [editArea, setEditArea] = useState(false);
  // The number customers chat with (connected from the home page), shown read-only on the Shop tab.
  const [waInfo, setWaInfo] = useState(null);
  // Did the owner type the pickup address during this visit? Typed words are never replaced by the map's address.
  const typedAddress = useRef(false);
  const [p, setProfile] = useState(null);
  const [savedHours, setSavedHours] = useState([]);
  const [hours, setHours] = useState([]);
  const [zones, setZones] = useState([]);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      const [{ profile }, { hours: h }, { zones: z }, setupRes] = await Promise.all([
        api("GET", "/settings/profile"),
        api("GET", "/settings/hours"),
        api("GET", "/settings/zones"),
        api("GET", "/settings/setup").catch(() => null),
      ]);
      setWaInfo(setupRes?.setup ? { connected: !!setupRes.setup.whatsapp_connected, number: setupRes.setup.whatsapp_number || "" } : null);
      const rows = (h || []).map((x) => ({
        dayOfWeek: x.day_of_week, opensAt: x.opens_at?.slice(0, 5) || "09:00", closesAt: x.closes_at?.slice(0, 5) || "23:00", isClosed: !!x.is_closed,
      }));
      setSaved(profile || {});
      setProfile(profile || {});
      setSavedHours(rows);
      setHours(rows);
      setZones(z || []);
    } catch (e) { toast(e.message, "error"); }
  }, [api, toast]);

  const loadZones = useCallback(async () => {
    try { setZones((await api("GET", "/settings/zones")).zones || []); } catch (e) { toast(e.message, "error"); }
  }, [api, toast]);

  useEffect(() => { load(); }, [load]);
  useEffect(() => { const q = params.get("tab"); if (q) pickTab(q); }, [params]);

  // A new pin (dropped, searched or pasted) fills the governorate and city. The saved pin never does, so a
  // city typed by hand survives a reload. Waits a moment so a pasted link is looked up once, not per letter.
  const pin = p?.shop_location || "";
  useEffect(() => {
    if (!pin || pin === (saved?.shop_location || "")) return undefined;
    const timer = setTimeout(async () => {
      try {
        const area = await api("POST", "/settings/locate", { location: pin });
        setProfile((x) => ({ ...x, ...(area.region ? { region: area.region } : {}), ...(area.city ? { city: area.city } : {}) }));
      } catch { /* the owner can pick the area by hand */ }
    }, 800);
    return () => clearTimeout(timer);
  }, [pin, saved, api]);

  const changed = p && saved ? Object.keys(GROUPS).filter((g) => !same(GROUPS[g](p), GROUPS[g](saved))) : [];
  const hoursChanged = !same(hours, savedHours);
  const dirty = changed.length > 0 || hoursChanged;

  // Leaving the page (refresh, close, typing a new address) with unsaved edits asks first.
  useEffect(() => {
    if (!dirty) return undefined;
    const warn = (e) => { e.preventDefault(); e.returnValue = ""; };
    window.addEventListener("beforeunload", warn);
    return () => window.removeEventListener("beforeunload", warn);
  }, [dirty]);

  if (!p) return <div className="loader">{t("db.loading")}</div>;

  const setP = (key, val) => setProfile((x) => ({ ...x, [key]: val }));
  const delivers = (p.fulfillment || "delivery") !== "pickup";
  const pickup = ["pickup", "both"].includes(p.fulfillment);
  // Choosing a place on the map (search, tap, drag) fills the pickup address line with that place's address.
  const onPinAddress = (picked) => setProfile((cur) => {
    const next = cur && addressAfterPin({ current: cur.pickup_address, picked, touched: typedAddress.current });
    return next ? { ...cur, pickup_address: next } : cur;
  });
  const unit = p.timing_unit || "minutes";
  // Governorate and city come from the map pin: one line, with a way to change them.
  const regionLabel = JOIN_LEBANON_REGIONS.find((o) => o.value === p.region)?.labelKey;
  const areaKnown = !!(p.region || p.city);
  const lbpOn = Number(p.lbp_rate) > 0;

  async function saveAll() {
    setBusy(true);
    try {
      if (changed.length) {
        await api("PATCH", "/settings/profile", Object.assign({}, ...changed.map((g) => GROUPS[g](p))));
      }
      if (hoursChanged) await api("PUT", "/settings/hours", { hours });
      await refreshProfile?.();
      await load();
      toast(t("db.saved"));
    } catch (e) { toast(e.message, "error"); }
    setBusy(false);
  }

  function discard() {
    setProfile(saved);
    setHours(savedHours);
  }

  function copyHours() {
    const from = hours.find((h) => !h.isClosed);
    if (!from) return;
    setHours(hours.map((h) => (h.isClosed ? h : { ...h, opensAt: from.opensAt, closesAt: from.closesAt })));
    toast(t("db.set.copied", { day: t(`db.day.${from.dayOfWeek}`) }));
  }

  const tabs = [
    ["shop", t("db.set.tab.shopAccount"), changed.includes("shop")],
    ["orders", t("db.set.tab.orders"), changed.includes("orders")],
    ...(delivers ? [["areas", t("db.set.tab.areas"), false]] : []),
    ["hours", t("db.set.tab.hours"), hoursChanged],
    ["payments", t("db.set.tab.payments"), changed.includes("payments")],
    ["alerts", t("db.set.tab.alerts"), changed.includes("alerts")],
  ];
  const tab = tabs.some(([id]) => id === picked) ? picked : "shop"; // a tab this shop doesn't have opens Shop

  const warnings = [
    delivers && zones.length === 0 && { text: t("db.set.warnZones"), go: "areas" },
    pickup && !mapPin(p.shop_location) && { text: t("db.set.warnPickup"), go: "orders" },
  ].filter(Boolean);

  return (
    <div className={`sset${tab === "areas" ? " sset-wide" : ""}${["shop", "orders", "payments", "alerts"].includes(tab) ? " sset-cards" : ""}`}>
      <div className="page-header"><h1>{t("settings.title")}</h1></div>

      {warnings.map((w) => (
        <div className="sset-warn" key={w.text} role="status">
          <span>{w.text}</span>
          {tab !== w.go && <button type="button" className="btn-sm" onClick={() => setTab(w.go)}>{t("db.set.fix")}</button>}
        </div>
      ))}

      <div className="sset-tabs" role="tablist">
        {tabs.map(([id, label, edited]) => (
          <button key={id} type="button" role="tab" aria-selected={tab === id} className={`sset-tab ${tab === id ? "active" : ""}`} onClick={() => setTab(id)}>
            {label}{edited && <span className="sset-dot" aria-label={t("db.set.unsaved")} />}
          </button>
        ))}
      </div>

      {tab === "shop" && (
        <div className="sset-cols">
          <Card title={t("db.set.card.shop")} desc={t("db.set.card.shopDesc")}>
            <Field id="s-name" label={t("db.set.shopName")}>
              <input id="s-name" value={p.shop_name || ""} onChange={(e) => setP("shop_name", e.target.value)} placeholder="Foren Al Hara · فرن الحارة" />
            </Field>
            {waInfo?.connected && (
              <Field id="s-chat" label={t("db.set.chatNumber")}>
                <div className="sset-readonly" id="s-chat">
                  <span dir="ltr">{waInfo.number}</span>
                  <span className="sset-badge ok">{t("db.set.chatNumberOk")}</span>
                </div>
              </Field>
            )}
            <Field id="s-wa" label={t("db.set.contactWa")} hint={t("db.set.contactWaHint")}>
              <input id="s-wa" dir="ltr" inputMode="tel" value={p.ops_whatsapp || ""} onChange={(e) => setP("ops_whatsapp", e.target.value)} placeholder="+961 70 123 456" />
            </Field>
          </Card>
          <div className="sset-stack">
            <Card title={t("db.set.card.lang")} desc={t("db.set.card.langDesc")}>
              <Segmented id="s-lang" aria-label={t("db.set.card.lang")} value={p.default_locale || "en"} onChange={(v) => setP("default_locale", v)}
                options={[{ value: "en", label: "English" }, { value: "ar", label: "العربية" }, { value: "fr", label: "Français" }]} />
            </Card>
          <PasswordCard api={api} t={t} toast={toast} />
          </div>
        </div>
      )}

      {tab === "orders" && (<div className="sset-cols">
        {(<>
          {/* The short cards stack on the left and the tall location card sits beside them, so timing is never pushed below the map. */}
          <div className="sset-stack">
            <Card title={t("ops.fulfillment")} desc={t("ops.fulfillmentDesc")}>
              <Segmented id="o-ful" cards aria-label={t("ops.fulfillment")} value={p.fulfillment || "delivery"} onChange={(v) => setP("fulfillment", v)}
                options={["delivery", "pickup", "both"].map((f) => ({ value: f, icon: FULFILLMENT_ICONS[f], label: t(`ops.fs.${f}`), hint: t(`ops.fs.${f}Hint`) }))} />
              <Switch checked={p.auto_accept !== false} onChange={(on) => setP("auto_accept", on)}
                label={t("ops.autoAccept")} hint={t("ops.autoAcceptHint")} />
              {delivers && (
                <button type="button" className="sset-next" onClick={() => setTab("areas")}>{t("ops.nextAreas")}</button>
              )}
            </Card>

            <Card title={t("ops.timing")} desc={t("ops.timingDesc")}>
              {unit === "minutes" && (
                <Field id="o-prep" label={t("ops.prep")} hint={t("ops.prepHint")} narrow>
                  <input id="o-prep" type="number" min="1" max="240" value={p.prep_time_mins ?? ""} onChange={(e) => setP("prep_time_mins", e.target.value)} />
                </Field>
              )}
              {unit === "hours" && (
                <Field id="o-ph" label={t("ops.prepHours")} hint={t("ops.prepHint")} narrow>
                  <input id="o-ph" type="number" min="1" max="72" value={p.prep_hours ?? 2} onChange={(e) => setP("prep_hours", e.target.value)} />
                </Field>
              )}
              {unit === "days" && (
                <Field id="o-dmin" label={t("ops.days")} hint={t("ops.daysHint")}>
                  <div className="sset-inline">
                    <input id="o-dmin" type="number" min="0" max="60" value={p.delivery_days_min ?? 1} onChange={(e) => setP("delivery_days_min", e.target.value)} />
                    <span>{t("ops.daysTo")}</span>
                    <input type="number" min="0" max="60" value={p.delivery_days_max ?? 2} onChange={(e) => setP("delivery_days_max", e.target.value)} aria-label={t("ops.daysTo")} />
                  </div>
                </Field>
              )}
              {unit !== "days" && (
                <Switch checked={Number(p.late_alert_mins ?? 15) > 0} onChange={(on) => setP("late_alert_mins", on ? "15" : "0")}
                  label={t("ops.late")} hint={t("ops.lateHint")} />
              )}
            </Card>

          </div>

          <Card title={t(pickup && !delivers ? "ops.pickupAddress" : "ops.shopLocation")}
            desc={t(pickup && delivers ? "ops.shopLocationBoth" : pickup ? "ops.pickupDesc" : "ops.shopLocationHint")}>
            {pickup && (
              <Field id="o-paddr" label={t("ops.pickupLine")}>
                <input id="o-paddr" maxLength={300} value={p.pickup_address || ""} placeholder={t("ops.pickupLinePh")} onChange={(e) => { typedAddress.current = true; setP("pickup_address", e.target.value); }} />
              </Field>
            )}
            <Field id="o-loc" label={t("ops.pinLabel")} hint={pickup && !delivers ? t("ops.pickupMapHint") : undefined}>
              <LocationPicker value={p.shop_location || ""} onChange={(v) => setP("shop_location", v)} onAddress={pickup ? onPinAddress : undefined} t={t} compact />
            </Field>
            {areaKnown && !editArea ? (
              <div className="sset-area">
                <span><span className="sset-area-k">{t("ops.areaLine")}</span> {[regionLabel && t(regionLabel), p.city].filter(Boolean).join(" · ")}</span>
                <button type="button" className="set-link set-link-btn" onClick={() => setEditArea(true)}>{t("ops.changeArea")}</button>
              </div>
            ) : (
              <>
                <Field id="o-region" label={t("jn.region")} hint={t("ops.areaHint")}>
                  <FormSelect id="o-region" value={p.region || ""} onChange={(v) => setP("region", v)} aria-label={t("jn.region")}
                    options={[{ value: "", label: t("jn.ph.region") }, ...JOIN_LEBANON_REGIONS.map((o) => ({ value: o.value, label: t(o.labelKey) }))]} />
                </Field>
                <Field id="o-city" label={t("jn.city")}>
                  <input id="o-city" value={p.city || ""} onChange={(e) => setP("city", e.target.value)} placeholder={t("jn.cityOther")} />
                </Field>
              </>
            )}
          </Card>
        </>)}
      </div>)}

      {tab === "areas" && (
        <Card title={t("db.zone.title")} desc={t("db.zone.intro")}>
          <ZoneEditor api={api} toast={toast} t={t} currency={shop.currency} shopPin={saved?.shop_location || ""}
            zones={zones} onChanged={loadZones} onSetShopLocation={() => setTab("orders")} />
        </Card>
      )}

      {tab === "hours" && (
        <Card title={t("db.set.tab.hours")} desc={t("db.set.hoursDesc")}
          action={<button type="button" className="btn-sm" onClick={copyHours} disabled={!hours.some((h) => !h.isClosed)}>{t("db.set.copyAll")}</button>}>
          <ul className="sset-hours">
            {[...hours].sort((a, b) => ((a.dayOfWeek + 6) % 7) - ((b.dayOfWeek + 6) % 7)).map((h) => {
              const update = (patch) => setHours(hours.map((x) => (x.dayOfWeek === h.dayOfWeek ? { ...x, ...patch } : x)));
              const day = t(`db.day.${h.dayOfWeek}`);
              return (
                <li key={h.dayOfWeek} className={h.isClosed ? "closed" : ""}>
                  <span className="sset-hours-day">{day}</span>
                  <button type="button" role="switch" aria-checked={!h.isClosed} aria-label={`${day}: ${t("db.set.open")}`}
                    className={`sset-switch ${h.isClosed ? "" : "on"}`} onClick={() => update({ isClosed: !h.isClosed })}><span /></button>
                  {h.isClosed ? (
                    <span className="sset-hours-closed">{t("db.set.closed")}</span>
                  ) : (
                    <span className="sset-hours-times">
                      <input type="time" value={h.opensAt} aria-label={day} onChange={(e) => update({ opensAt: e.target.value })} />
                      <span aria-hidden="true">–</span>
                      <input type="time" value={h.closesAt} aria-label={day} onChange={(e) => update({ closesAt: e.target.value })} />
                    </span>
                  )}
                </li>
              );
            })}
          </ul>
        </Card>
      )}

      {tab === "payments" && (<div className="sset-cols">
        <Card title={t("db.set.card.money")} desc={t("db.set.card.moneyDesc")}>
          <Switch checked={lbpOn} onChange={(on) => setP("lbp_rate", on ? String(Number(saved.lbp_rate) > 0 ? saved.lbp_rate : LBP_RATE) : "")}
            label={t("db.set.lbpOn")} hint={t("db.set.lbpOnHint")} />
          {lbpOn && (
            <Field id="o-lbp" label={t("ops.lbp")}>
              <div className="sset-inline">
                <span>{t("db.lbp.prefix")}</span>
                <input id="o-lbp" type="number" min="1" step="100" value={p.lbp_rate ?? ""} onChange={(e) => setP("lbp_rate", e.target.value)} />
                <span>{t("db.lbp.suffix")}</span>
              </div>
            </Field>
          )}
        </Card>
        <Card title={t("db.whish.title")} desc={t("db.whish.intro")}
          action={<span className={`sset-badge ${p.whish_connected ? "ok" : ""}`}>{p.whish_connected ? t("db.whish.ok") : t("db.whish.notConnected")}</span>}>
          <ol className="sset-steps">
            {["s1", "s2", "s3"].map((k, i) => <li key={k}><span aria-hidden="true">{i + 1}</span>{t(`db.whish.${k}`)}</li>)}
          </ol>
          <Field id="w-no" label={t("db.whish.number")}>
            <input id="w-no" dir="ltr" inputMode="numeric" value={p.whish_number || ""} onChange={(e) => setP("whish_number", e.target.value)} placeholder={t("db.whish.numberPh")} />
          </Field>
          <Field id="w-to" label={t("db.whish.timeout")}>
            <div className="sset-inline">
              <input id="w-to" type="number" min="5" max="120" value={p.wish_timeout_mins || 15} onChange={(e) => setP("wish_timeout_mins", e.target.value)} />
              <span>{t("db.unit.minutes")}</span>
            </div>
          </Field>
        </Card>
      </div>)}

      {tab === "alerts" && (<div className="sset-cols">
        <Card title={t("db.set.card.report")} desc={t("db.set.card.reportDesc")}>
          <div className="sset-grid wide-first">
            <Field id="a-rep" label={t("ops.report")} hint={t("ops.reportHint")}>
              <input id="a-rep" dir="ltr" inputMode="tel" value={p.report_whatsapp || ""} onChange={(e) => setP("report_whatsapp", e.target.value)} placeholder="+961 70 123 456" />
            </Field>
            <Field id="a-hour" label={t("ops.reportHour")}>
              <FormSelect id="a-hour" value={p.report_hour ?? 23} onChange={(v) => setP("report_hour", v)}
                options={Array.from({ length: 24 }, (_, h) => ({ value: h, label: `${String(h).padStart(2, "0")}:00` }))} />
            </Field>
          </div>
        </Card>
        <Card title={t("db.set.card.chats")} desc={t("db.set.card.chatsDesc")}>
          <ul className="sset-signs">
            <li>{t("ops.pauseSend")}</li>
            <li>{t("ops.playSend")}</li>
          </ul>
          <Field id="a-pause" label={t("ops.pause")}>
            <div className="sset-inline">
              <input id="a-pause" type="number" min="1" max="1440" value={p.bot_pause_mins ?? 60} onChange={(e) => setP("bot_pause_mins", e.target.value)} />
              <span>{t("db.unit.minutes")}</span>
            </div>
          </Field>
        </Card>
      </div>)}

      {dirty && (
        <div className="sset-savebar" role="region" aria-label={t("db.set.unsaved")}>
          <span>{t("db.set.unsaved")}</span>
          <div>
            <button type="button" className="btn-sm" onClick={discard} disabled={busy}>{t("db.set.discard")}</button>
            <button type="button" className="btn-primary" onClick={saveAll} disabled={busy}>{busy ? "…" : t("db.save")}</button>
          </div>
        </div>
      )}

    </div>
  );
}

/** Change password. Saves on its own (not through the unsaved-changes bar); other devices are signed out. */
function PasswordCard({ api, t, toast }) {
  const [cur, setCur] = useState("");
  const [next, setNext] = useState("");
  const [again, setAgain] = useState("");
  const [busy, setBusy] = useState(false);
  const [open, setOpen] = useState(false);
  const [shown, setShown] = useState(false);
  const mismatch = again.length > 0 && next !== again;
  const ready = cur && next.length >= 8 && next === again;

  function close() {
    setOpen(false); setShown(false);
    setCur(""); setNext(""); setAgain("");
  }

  async function submit(e) {
    e.preventDefault();
    if (!ready) return;
    setBusy(true);
    try {
      const { token } = await api("PATCH", "/auth/password", { current_password: cur, password: next, password_confirmation: again });
      if (token) setToken("merchant", token);
      close();
      toast(t("db.acc.changed"));
    } catch (err) { toast(err.message, "error"); }
    setBusy(false);
  }

  return (
    <Card title={t("db.acc.pw")} desc={t("db.acc.pwDesc")}>
      {!open && <button type="button" className="btn-sm" onClick={() => setOpen(true)}>{t("db.acc.pw")}</button>}
      {open && (
      <form onSubmit={submit}>
        <Field id="pw-cur" label={t("db.acc.current")}>
          <PwInput id="pw-cur" autoComplete="current-password" value={cur} onChange={setCur} shown={shown} onToggle={() => setShown((v) => !v)} t={t} />
        </Field>
        <div className="sset-grid">
          <Field id="pw-new" label={t("db.acc.new")} hint={t("db.acc.min")}>
            <PwInput id="pw-new" autoComplete="new-password" value={next} onChange={setNext} shown={shown} onToggle={() => setShown((v) => !v)} t={t} />
          </Field>
          <Field id="pw-again" label={t("db.acc.confirm")} hint={mismatch ? t("db.acc.mismatch") : null}>
            <PwInput id="pw-again" autoComplete="new-password" value={again} onChange={setAgain} shown={shown} onToggle={() => setShown((v) => !v)} t={t} invalid={mismatch} />
          </Field>
        </div>
        <div className="set-inline-actions">
          <button type="submit" className="btn-primary btn-sm" disabled={!ready || busy}>{busy ? "…" : t("db.save")}</button>
          <button type="button" className="btn-sm" onClick={close}>{t("db.cancel")}</button>
        </div>
      </form>
      )}
    </Card>
  );
}

/** Password box with an eye icon that appears once something is typed. */
function PwInput({ id, value, onChange, shown, onToggle, autoComplete, invalid, t }) {
  return (
    <div className="set-pass">
      <input id={id} dir="ltr" type={shown ? "text" : "password"} autoComplete={autoComplete} value={value}
        onChange={(e) => onChange(e.target.value)} aria-invalid={invalid || undefined} />
      {value && (
        <button type="button" onClick={onToggle} aria-label={t(shown ? "auth.hide" : "auth.show")} aria-pressed={shown}>
          {shown ? ICONS.eyeOff : ICONS.eye}
        </button>
      )}
    </div>
  );
}

function Card({ title, desc, action, children }) {
  return (
    <section className="sset-card">
      <header>
        <div>
          <h2>{title}</h2>
          {desc && <p>{desc}</p>}
        </div>
        {action}
      </header>
      {children}
    </section>
  );
}

function Field({ id, label, hint, narrow, children }) {
  return (
    <div className={`sset-field ${narrow ? "narrow" : ""}`}>
      <label htmlFor={id}>{label}</label>
      {children}
      {hint && <p className="sset-hint">{hint}</p>}
    </div>
  );
}

/** A yes/no setting as one row: the words on one side, the switch on the other. */
function Switch({ checked, onChange, label, hint }) {
  return (
    <button type="button" role="switch" aria-checked={checked} className="sset-switch-row" onClick={() => onChange(!checked)}>
      <span className="sset-switch-text">
        <strong>{label}</strong>
        {hint && <span>{hint}</span>}
      </span>
      <span className={`sset-switch ${checked ? "on" : ""}`} aria-hidden="true"><span /></span>
    </button>
  );
}
