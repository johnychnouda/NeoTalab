"use client";

import { Suspense, useCallback, useEffect, useState } from "react";
import { useSearchParams } from "next/navigation";
import { useMerchant } from "../../layout";
import { useLang } from "@/lib/i18n";
import { setToken } from "@/lib/api";
import { useToast } from "@/components/ui";
import FormSelect from "@/components/FormSelect";
import Segmented from "@/components/Segmented";
import LocationPicker, { mapPin } from "@/components/LocationPicker";
import { ICONS } from "@/components/auth/AuthShell";
import ZoneEditor from "@/components/ZoneEditor";
import { JOIN_LEBANON_REGIONS } from "@/lib/joinFormOptions";

const LBP_RATE = 89500;
const num = (v) => (v === "" || v === null || v === undefined ? null : Number(v));

/**
 * What each tab sends to PATCH /settings/profile. A tab counts as changed when its body differs
 * from the saved profile, and one Save sends every changed tab together.
 */
const GROUPS = {
  shop: (p) => ({ shopName: p.shop_name || "", opsWhatsapp: p.ops_whatsapp || "" }),
  orders: (p) => ({
    appointment_capacity: num(p.appointment_capacity), slot_step_mins: num(p.slot_step_mins), reminder_mins: num(p.reminder_mins),
    fulfillment: p.fulfillment || "delivery", pickup_address: p.pickup_address || null, shop_location: p.shop_location || null,
    region: p.region || null, city: p.city || null,
    prep_time_mins: num(p.prep_time_mins), prep_hours: num(p.prep_hours),
    delivery_days_min: num(p.delivery_days_min), delivery_days_max: num(p.delivery_days_max),
    late_alert_mins: num(p.late_alert_mins), lbp_rate: num(p.lbp_rate), rating_delay_mins: num(p.rating_delay_mins),
    scheduled_orders: !!p.scheduled_orders, gift_orders: !!p.gift_orders,
  }),
  payments: (p) => ({
    whish_channel: p.whish_channel || "", ...(p.whish_secret_new ? { whish_secret: p.whish_secret_new } : {}),
    whish_sandbox: !!p.whish_sandbox, wishTimeoutMins: parseInt(p.wish_timeout_mins, 10) || 15,
  }),
  alerts: (p) => ({ report_whatsapp: p.report_whatsapp || null, report_hour: num(p.report_hour), bot_pause_mins: num(p.bot_pause_mins) }),
};
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
// Lebanese numbers typed any way ("+961 70 123 456", "0096170123456", "70 123 456") compare equal.
const localDigits = (n) => String(n || "").replace(/\D+/g, "").replace(/^00/, "").replace(/^961/, "").replace(/^0+/, "");
const sameNumber = (a, b) => localDigits(a) !== "" && localDigits(a) === localDigits(b);

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

  const [picked, setTab] = useState(params.get("tab") || "shop");
  const [saved, setSaved] = useState(null);
  const [p, setProfile] = useState(null);
  const [savedHours, setSavedHours] = useState([]);
  const [hours, setHours] = useState([]);
  const [zones, setZones] = useState([]);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      const [{ profile }, { hours: h }, { zones: z }] = await Promise.all([
        api("GET", "/settings/profile"),
        api("GET", "/settings/hours"),
        api("GET", "/settings/zones"),
      ]);
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
  useEffect(() => { const q = params.get("tab"); if (q) setTab(q); }, [params]);

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
  const bookingsOnly = p.business_mode === "appointments";
  const delivers = !bookingsOnly && (p.fulfillment || "delivery") !== "pickup";
  const pickup = !bookingsOnly && ["pickup", "both"].includes(p.fulfillment);
  const unit = p.timing_unit || "minutes";
  const lbpOn = Number(p.lbp_rate) > 0;

  async function saveAll() {
    // The shop number can't message itself, so the nightly report would never arrive.
    if (changed.includes("alerts") && p.report_whatsapp && [p.whatsapp_display, p.ops_whatsapp].some((n) => sameNumber(n, p.report_whatsapp))) {
      toast(t("ops.reportIsShop"), "error");
      return;
    }
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
    ["shop", t("db.set.tab.shop"), changed.includes("shop")],
    ["orders", t("db.set.tab.orders"), changed.includes("orders")],
    ...(delivers ? [["areas", t("db.set.tab.areas"), false]] : []),
    ["hours", t("db.set.tab.hours"), hoursChanged],
    ...(!bookingsOnly ? [["payments", t("db.set.tab.payments"), changed.includes("payments")]] : []),
    ["alerts", t("db.set.tab.alerts"), changed.includes("alerts")],
    ["account", t("db.set.tab.account"), false],
  ];
  const tab = tabs.some(([id]) => id === picked) ? picked : "shop"; // a tab this shop doesn't have opens Shop

  const warnings = [
    delivers && zones.length === 0 && { text: t("db.set.warnZones"), go: "areas" },
    pickup && !mapPin(p.shop_location) && { text: t("db.set.warnPickup"), go: "orders" },
  ].filter(Boolean);

  return (
    <div className={`sset${tab === "areas" ? " sset-wide" : ""}`}>
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
        <Card title={t("db.set.card.shop")} desc={t("db.set.card.shopDesc")}>
          <Field id="s-name" label={t("db.set.shopName")}>
            <input id="s-name" value={p.shop_name || ""} onChange={(e) => setP("shop_name", e.target.value)} placeholder="Foren Al Hara · فرن الحارة" />
          </Field>
          <Field id="s-wa" label={t("db.set.contactWa")} hint={t("db.set.contactWaHint")}>
            <input id="s-wa" dir="ltr" inputMode="tel" value={p.ops_whatsapp || ""} onChange={(e) => setP("ops_whatsapp", e.target.value)} placeholder="+961 70 123 456" />
          </Field>
        </Card>
      )}

      {tab === "orders" && (<>
        {p.business_mode && p.business_mode !== "orders" && (
          <Card title={t("db.set.card.bookings")}>
            <div className="sset-grid three">
              <Field id="o-cap" label={t("ops.capacity")}>
                <input id="o-cap" type="number" min="1" max="50" value={p.appointment_capacity ?? 1} onChange={(e) => setP("appointment_capacity", e.target.value)} />
              </Field>
              <Field id="o-step" label={t("ops.slotStep")}>
                <FormSelect id="o-step" value={p.slot_step_mins ?? 30} onChange={(v) => setP("slot_step_mins", v)}
                  options={[10, 15, 20, 30, 45, 60].map((m) => ({ value: m, label: `${m} min` }))} />
              </Field>
              <Field id="o-rem" label={t("ops.reminder")}>
                <input id="o-rem" type="number" min="0" max="2880" value={p.reminder_mins ?? 120} onChange={(e) => setP("reminder_mins", e.target.value)} />
              </Field>
            </div>
          </Card>
        )}
        {p.business_mode && p.business_mode !== "orders" && <TeamCard api={api} t={t} toast={toast} />}

        {!bookingsOnly && (<>
          <Card title={t("db.set.card.getting")}>
            <Field id="o-ful" label={t("ops.fulfillment")}>
              <Segmented id="o-ful" cards aria-label={t("ops.fulfillment")} value={p.fulfillment || "delivery"} onChange={(v) => setP("fulfillment", v)}
                options={["delivery", "pickup", "both"].map((f) => ({ value: f, icon: { delivery: "🛵", pickup: "🏪", both: "🔁" }[f], label: t(`ops.fs.${f}`), hint: t(`ops.fs.${f}Hint`) }))} />
            </Field>
            <Field id="o-loc" label={t(pickup && !delivers ? "ops.pickupAddress" : "ops.shopLocation")}
              hint={t(pickup && delivers ? "ops.shopLocationBoth" : pickup ? "ops.pickupMapHint" : "ops.shopLocationHint")}>
              <LocationPicker value={p.shop_location || ""} onChange={(v) => setP("shop_location", v)} t={t} />
            </Field>
            <Field id="o-region" label={t("jn.region")} hint={t("ops.areaHint")}>
              <FormSelect id="o-region" value={p.region || ""} onChange={(v) => setP("region", v)} aria-label={t("jn.region")}
                options={[{ value: "", label: t("jn.ph.region") }, ...JOIN_LEBANON_REGIONS.map((o) => ({ value: o.value, label: t(o.labelKey) }))]} />
            </Field>
            <Field id="o-city" label={t("jn.city")}>
              <input id="o-city" value={p.city || ""} onChange={(e) => setP("city", e.target.value)} placeholder={t("jn.cityOther")} />
            </Field>
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

          <Card title={t("db.set.card.after")}>
            <Switch checked={lbpOn} onChange={(on) => setP("lbp_rate", on ? String(Number(saved.lbp_rate) > 0 ? saved.lbp_rate : LBP_RATE) : "")}
              label={t("db.set.lbpOn")} hint={t("db.set.lbpOnHint")} />
            {lbpOn && (
              <Field id="o-lbp" label={t("ops.lbp")} narrow>
                <input id="o-lbp" type="number" min="1" step="100" value={p.lbp_rate ?? ""} onChange={(e) => setP("lbp_rate", e.target.value)} />
              </Field>
            )}
            <Switch checked={Number(p.rating_delay_mins ?? 30) > 0} onChange={(on) => setP("rating_delay_mins", on ? "30" : "0")}
              label={t("ops.rating")} hint={t("ops.ratingHint")} />
            <Switch checked={!!p.scheduled_orders} onChange={(on) => setP("scheduled_orders", on)}
              label={t("ops.scheduled")} hint={t("ops.scheduledHint")} />
            <Switch checked={!!p.gift_orders} onChange={(on) => setP("gift_orders", on)}
              label={t("ops.gift")} hint={t("ops.giftHint")} />
          </Card>
        </>)}
      </>)}

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
            {hours.map((h) => {
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

      {tab === "payments" && (
        <Card title="Whish Pay" desc={t("db.whish.intro")}
          action={<span className={`sset-badge ${p.whish_connected ? "ok" : ""}`}>{p.whish_connected ? t("db.whish.ok") : t("db.whish.notConnected")}</span>}>
          <div className="sset-grid">
            <Field id="w-ch" label={t("db.whish.channel")}>
              <input id="w-ch" dir="ltr" value={p.whish_channel || ""} onChange={(e) => setP("whish_channel", e.target.value)} placeholder={t("db.whish.channelPh")} />
            </Field>
            <Field id="w-sec" label={t("db.whish.secret")}>
              <input id="w-sec" dir="ltr" type="password" autoComplete="new-password" value={p.whish_secret_new || ""}
                onChange={(e) => setP("whish_secret_new", e.target.value)} placeholder={p.whish_connected ? t("db.whish.secretSaved") : t("db.whish.secretPh")} />
            </Field>
          </div>
          <Field id="w-to" label={t("db.whish.timeout")} narrow>
            <input id="w-to" type="number" min="5" max="120" value={p.wish_timeout_mins || 15} onChange={(e) => setP("wish_timeout_mins", e.target.value)} />
          </Field>
          {p.whish_sandbox_allowed && (
            <Switch checked={!!p.whish_sandbox} onChange={(on) => setP("whish_sandbox", on)} label={t("db.whish.test")} />
          )}
        </Card>
      )}

      {tab === "alerts" && (<>
        <Card title={t("db.set.card.report")}>
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
        <Card title={t("db.set.card.chats")}>
          <Field id="a-pause" label={t("ops.pause")} hint={t("ops.pauseHint")} narrow>
            <input id="a-pause" type="number" min="1" max="1440" value={p.bot_pause_mins ?? 60} onChange={(e) => setP("bot_pause_mins", e.target.value)} />
          </Field>
        </Card>
      </>)}

      {tab === "account" && <PasswordCard api={api} t={t} toast={toast} />}

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

/**
 * Stylists, barbers, doctors customers can book with by name. Each has their own calendar; with
 * nobody listed the shop books by "customers at the same time". Saved right away.
 */
function TeamCard({ api, t, toast }) {
  const [team, setTeam] = useState(null);
  const [services, setServices] = useState([]);
  const [name, setName] = useState("");

  const load = useCallback(async () => {
    try {
      const [{ providers }, res] = await Promise.all([api("GET", "/providers"), api("GET", "/products?per_page=200")]);
      setTeam(providers || []);
      setServices((res.products || []).filter((x) => x.duration_mins));
    } catch (e) { toast(e.message, "error"); }
  }, [api, toast]);
  useEffect(() => { load(); }, [load]);

  async function run(fn) {
    try { await fn(); await load(); } catch (e) { toast(e.message, "error"); }
  }
  const add = (e) => { e.preventDefault(); if (name.trim()) run(async () => { await api("POST", "/providers", { name: name.trim() }); setName(""); }); };
  const toggle = (person, id) => {
    const all = services.map((x) => x.id);
    const current = person.service_ids?.length ? person.service_ids : all;
    const next = current.includes(id) ? current.filter((x) => x !== id) : [...current, id];
    run(() => api("PATCH", `/providers/${person.id}`, { service_ids: next.length === all.length ? null : next }));
  };

  return (
    <Card title={t("ops.team.title")} desc={t("ops.team.desc")}>
      {team === null ? <div className="loader">{t("db.loading")}</div> : (<>
        {team.map((person) => (
          <div className="sset-team-row" key={person.id}>
            <strong>{person.name}</strong>
            {services.length > 1 && (
              <div className="sset-team-services">
                {services.map((x) => (
                  <label key={x.id} className="mk-check">
                    <input type="checkbox" checked={!person.service_ids?.length || person.service_ids.includes(x.id)} onChange={() => toggle(person, x.id)} /> {x.name}
                  </label>
                ))}
              </div>
            )}
            <button type="button" className="btn-sm" onClick={() => run(() => api("DELETE", `/providers/${person.id}`))} aria-label={`${t("mk.delete")} ${person.name}`}>✕</button>
          </div>
        ))}
        <form className="sset-team-add" onSubmit={add}>
          <input value={name} onChange={(e) => setName(e.target.value)} placeholder={t("ops.team.namePh")} aria-label={t("ops.team.namePh")} maxLength={80} />
          <button type="submit" className="btn-sm">{t("ops.team.add")}</button>
        </form>
        <p className="sset-hint">{t(team.length ? "ops.team.hintOn" : "ops.team.hintOff")}</p>
      </>)}
    </Card>
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
