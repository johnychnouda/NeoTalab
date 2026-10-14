"use client";

import { Suspense, useCallback, useEffect, useState } from "react";
import { useSearchParams } from "next/navigation";
import { useMerchant } from "../../layout";
import { useLang } from "@/lib/i18n";
import { formatMoney } from "@/lib/money";
import { Modal, ConfirmModal, useToast } from "@/components/ui";

const KINDS = ["restaurant", "grocery", "pharmacy", "retail", "flowers", "water", "laundry", "services", "other"];
const num = (v) => (v === "" || v === null || v === undefined ? null : Number(v));

export default function SettingsPage() {
  return <Suspense fallback={null}><Settings /></Suspense>;
}

/**
 * Shop setup, in sections: Shop · Orders · Delivery areas · Opening hours · Payments · Reports & chats.
 * ?tab= opens a section directly (links from the Get started checklist).
 */
function Settings() {
  const { api, refreshProfile, shop } = useMerchant();
  const { t } = useLang();
  const toast = useToast();
  const params = useSearchParams();

  const [tab, setTab] = useState(params.get("tab") || "shop");
  const [p, setProfile] = useState(null);
  const [hours, setHours] = useState([]);
  const [zones, setZones] = useState([]);
  const [modal, setModal] = useState(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      const [{ profile }, { hours: h }, { zones: z }] = await Promise.all([
        api("GET", "/settings/profile"),
        api("GET", "/settings/hours"),
        api("GET", "/settings/zones"),
      ]);
      setProfile(profile || {});
      setHours((h || []).map((x) => ({
        dayOfWeek: x.day_of_week, opensAt: x.opens_at?.slice(0, 5) || "09:00", closesAt: x.closes_at?.slice(0, 5) || "23:00", isClosed: !!x.is_closed,
      })));
      setZones(z || []);
    } catch (e) { toast(e.message, "error"); }
  }, [api, toast]);

  useEffect(() => { load(); }, [load]);
  useEffect(() => { const q = params.get("tab"); if (q) setTab(q); }, [params]);

  if (!p) return <div className="loader">{t("db.loading")}</div>;

  const setP = (key, val) => setProfile((x) => ({ ...x, [key]: val }));
  const bookingsOnly = p.business_mode === "appointments";
  const delivers = !bookingsOnly && (p.fulfillment || "delivery") !== "pickup";

  async function save(body, reload = false) {
    setBusy(true);
    try {
      await api("PATCH", "/settings/profile", body);
      await refreshProfile?.();
      toast(t("db.saved"));
      if (reload) load();
    } catch (e) { toast(e.message, "error"); }
    setBusy(false);
  }

  const tabs = [
    ["shop", t("db.set.tab.shop")],
    ["orders", t("db.set.tab.orders")],
    ...(delivers ? [["areas", t("db.set.tab.areas")]] : []),
    ["hours", t("db.set.tab.hours")],
    ...(!bookingsOnly ? [["payments", t("db.set.tab.payments")]] : []),
    ["alerts", t("db.set.tab.alerts")],
  ];
  const hint = (text) => <div className="mk-hint">{text}</div>;
  const yesNo = (key, on = "15") => (
    <select style={{ maxWidth: 140 }} value={Number(p[key] ?? on) > 0 ? on : "0"} onChange={(e) => setP(key, e.target.value)}>
      <option value={on}>{t("ops.yes")}</option>
      <option value="0">{t("ops.no")}</option>
    </select>
  );

  return (
    <div className="set">
      <div className="page-header"><h1>{t("settings.title")}</h1></div>

      <div className="bo-htabs" role="tablist">
        {tabs.map(([id, label]) => (
          <button key={id} role="tab" aria-selected={tab === id} className={`bo-htab ${tab === id ? "active" : ""}`} onClick={() => setTab(id)}>{label}</button>
        ))}
      </div>

      {tab === "shop" && (
        <section className="settings-section">
          <div className="field"><label htmlFor="s-name">{t("db.set.shopName")}</label>
            <input id="s-name" value={p.shop_name || ""} onChange={(e) => setP("shop_name", e.target.value)} placeholder="Foren Al Hara · فرن الحارة" /></div>
          <div className="field"><label htmlFor="s-kind">{t("mk.kind")}</label>
            <select id="s-kind" value={p.business_kind || "other"} onChange={(e) => setP("business_kind", e.target.value)}>
              {KINDS.map((k) => <option key={k} value={k}>{t(`mk.kind.${k}`)}</option>)}
            </select>{hint(t("mk.kindHint"))}</div>
          <div className="field"><label htmlFor="s-wa">{t("db.set.waNumber")}</label>
            <input id="s-wa" dir="ltr" value={p.ops_whatsapp || ""} onChange={(e) => setP("ops_whatsapp", e.target.value)} placeholder="+96170123456" /></div>
          <div className="field"><label htmlFor="s-lang">{t("db.set.language")}</label>
            <select id="s-lang" value={p.default_locale || "ar"} onChange={(e) => setP("default_locale", e.target.value)}>
              <option value="ar">العربية</option><option value="en">English</option><option value="fr">Français</option>
            </select></div>
          {delivers && (
            <div className="field"><label htmlFor="s-loc">{t("ops.shopLocation")}</label>
              <input id="s-loc" dir="ltr" value={p.shop_location || ""} onChange={(e) => setP("shop_location", e.target.value)} placeholder="https://maps.app.goo.gl/…" />
              {hint(t("ops.shopLocationHint"))}</div>
          )}
          <button className="btn-primary" disabled={busy} onClick={() => save({
            shopName: p.shop_name, opsWhatsapp: p.ops_whatsapp, defaultLocale: p.default_locale,
            business_kind: p.business_kind, shop_location: p.shop_location || null,
          }, true)}>{t("db.save")}</button>
        </section>
      )}

      {tab === "orders" && (
        <section className="settings-section">
          <div className="field"><label htmlFor="o-mode">{t("ops.businessMode")}</label>
            <select id="o-mode" value={p.business_mode || "orders"} onChange={(e) => setP("business_mode", e.target.value)}>
              <option value="orders">{t("ops.bmOrders")}</option>
              <option value="appointments">{t("ops.bmAppointments")}</option>
              <option value="both">{t("ops.bmBoth")}</option>
            </select></div>
          {p.business_mode && p.business_mode !== "orders" && (<>
            <div className="field"><label htmlFor="o-cap">{t("ops.capacity")}</label>
              <input id="o-cap" type="number" min="1" max="50" style={{ maxWidth: 120 }} value={p.appointment_capacity ?? 1} onChange={(e) => setP("appointment_capacity", e.target.value)} /></div>
            <div className="field"><label htmlFor="o-step">{t("ops.slotStep")}</label>
              <select id="o-step" style={{ maxWidth: 160 }} value={p.slot_step_mins ?? 30} onChange={(e) => setP("slot_step_mins", e.target.value)}>
                {[10, 15, 20, 30, 45, 60].map((m) => <option key={m} value={m}>{m} min</option>)}
              </select></div>
            <div className="field"><label htmlFor="o-rem">{t("ops.reminder")}</label>
              <input id="o-rem" type="number" min="0" max="2880" style={{ maxWidth: 120 }} value={p.reminder_mins ?? 120} onChange={(e) => setP("reminder_mins", e.target.value)} /></div>
          </>)}
          {!bookingsOnly && (<>
            <div className="field"><label htmlFor="o-ful">{t("ops.fulfillment")}</label>
              <select id="o-ful" value={p.fulfillment || "delivery"} onChange={(e) => setP("fulfillment", e.target.value)}>
                <option value="delivery">{t("ops.fDelivery")}</option>
                <option value="pickup">{t("ops.fPickup")}</option>
                <option value="both">{t("ops.fBoth")}</option>
              </select></div>
            {(p.fulfillment === "pickup" || p.fulfillment === "both") && (
              <div className="field"><label htmlFor="o-pick">{t("ops.pickupAddress")}</label>
                <input id="o-pick" value={p.pickup_address || ""} onChange={(e) => setP("pickup_address", e.target.value)} /></div>
            )}
            <div className="field"><label htmlFor="o-unit">{t("ops.timing")}</label>
              <select id="o-unit" value={p.timing_unit || "minutes"} onChange={(e) => setP("timing_unit", e.target.value)}>
                <option value="minutes">{t("ops.tMinutes")}</option>
                <option value="hours">{t("ops.tHours")}</option>
                <option value="days">{t("ops.tDays")}</option>
              </select></div>
            {(p.timing_unit || "minutes") === "minutes" && (
              <div className="field"><label htmlFor="o-prep">{t("ops.prep")}</label>
                <input id="o-prep" type="number" min="1" max="240" style={{ maxWidth: 120 }} value={p.prep_time_mins ?? ""} onChange={(e) => setP("prep_time_mins", e.target.value)} />
                {hint(t("ops.prepHint"))}</div>
            )}
            {p.timing_unit === "hours" && (
              <div className="field"><label htmlFor="o-ph">{t("ops.prepHours")}</label>
                <input id="o-ph" type="number" min="1" max="72" style={{ maxWidth: 120 }} value={p.prep_hours ?? 2} onChange={(e) => setP("prep_hours", e.target.value)} />
                {hint(t("ops.prepHint"))}</div>
            )}
            {p.timing_unit === "days" && (
              <div className="field"><label htmlFor="o-dmin">{t("ops.days")}</label>
                <div className="mk-row">
                  <input id="o-dmin" type="number" min="0" max="60" style={{ maxWidth: 90 }} value={p.delivery_days_min ?? 1} onChange={(e) => setP("delivery_days_min", e.target.value)} />
                  <span>{t("ops.daysTo")}</span>
                  <input type="number" min="0" max="60" style={{ maxWidth: 90 }} value={p.delivery_days_max ?? 2} onChange={(e) => setP("delivery_days_max", e.target.value)} aria-label={t("ops.daysTo")} />
                </div>{hint(t("ops.daysHint"))}</div>
            )}
            {p.timing_unit !== "days" && (
              <div className="field"><label>{t("ops.late")}</label>{yesNo("late_alert_mins")}{hint(t("ops.lateHint"))}</div>
            )}
            <div className="field"><label htmlFor="o-lbp">{t("ops.lbp")}</label>
              <input id="o-lbp" type="number" min="0" step="100" style={{ maxWidth: 180 }} value={p.lbp_rate ?? ""} onChange={(e) => setP("lbp_rate", e.target.value)} placeholder="89500" />
              {hint(t("ops.lbpHint"))}</div>
            <div className="field"><label>{t("ops.rating")}</label>{yesNo("rating_delay_mins", "30")}{hint(t("ops.ratingHint"))}</div>
          </>)}
          <button className="btn-primary" disabled={busy} onClick={() => save({
            business_mode: p.business_mode || "orders", appointment_capacity: num(p.appointment_capacity), slot_step_mins: num(p.slot_step_mins), reminder_mins: num(p.reminder_mins),
            fulfillment: p.fulfillment || "delivery", pickup_address: p.pickup_address || null,
            timing_unit: p.timing_unit || "minutes", prep_time_mins: num(p.prep_time_mins), prep_hours: num(p.prep_hours),
            delivery_days_min: num(p.delivery_days_min), delivery_days_max: num(p.delivery_days_max),
            late_alert_mins: num(p.late_alert_mins), lbp_rate: num(p.lbp_rate), rating_delay_mins: num(p.rating_delay_mins),
          }, true)}>{t("db.save")}</button>
        </section>
      )}

      {tab === "areas" && (
        <section className="settings-section">
          {zones.length === 0 && <p className="mk-hint">{t("db.zone.empty")}</p>}
          {zones.map((z) => (
            <div className="zone-row" key={z.id}>
              <span className="zone-name">{z.name}</span>
              <span className="zone-fee">{formatMoney(z.delivery_fee, shop.currency)}</span>
              <span className="mk-hint" style={{ margin: 0 }}>{t("db.zone.minShort")} {formatMoney(z.minimum_order, shop.currency)} · {z.delivery_mins ?? 15} {t("zones.driveShort")}</span>
              <button className="btn-sm btn-sm-red" onClick={() => setModal({ type: "deleteZone", zone: z })} aria-label={t("db.remove")}>✕</button>
            </div>
          ))}
          <button className="btn-primary" style={{ marginTop: 12 }} onClick={() => setModal({ type: "addZone" })}>+ {t("db.zone.add")}</button>
        </section>
      )}

      {tab === "hours" && (
        <section className="settings-section">
          <div className="hours-grid">
            {hours.map((h) => (
              <div className="hours-row" key={h.dayOfWeek}>
                <span className="hours-day">{t(`db.day.${h.dayOfWeek}`)}</span>
                <label className="mk-check"><input type="checkbox" checked={h.isClosed}
                  onChange={(e) => setHours(hours.map((x) => (x.dayOfWeek === h.dayOfWeek ? { ...x, isClosed: e.target.checked } : x)))} /> {t("db.set.closed")}</label>
                <input type="time" className="time-input" value={h.opensAt} disabled={h.isClosed} aria-label={t(`db.day.${h.dayOfWeek}`)}
                  onChange={(e) => setHours(hours.map((x) => (x.dayOfWeek === h.dayOfWeek ? { ...x, opensAt: e.target.value } : x)))} />
                <span className="mk-hint" style={{ margin: 0 }}>–</span>
                <input type="time" className="time-input" value={h.closesAt} disabled={h.isClosed} aria-label={t(`db.day.${h.dayOfWeek}`)}
                  onChange={(e) => setHours(hours.map((x) => (x.dayOfWeek === h.dayOfWeek ? { ...x, closesAt: e.target.value } : x)))} />
              </div>
            ))}
          </div>
          <button className="btn-primary" style={{ marginTop: 16 }} disabled={busy} onClick={async () => {
            setBusy(true);
            try { await api("PUT", "/settings/hours", { hours }); toast(t("db.saved")); } catch (e) { toast(e.message, "error"); }
            setBusy(false);
          }}>{t("db.save")}</button>
        </section>
      )}

      {tab === "payments" && (
        <section className="settings-section">
          <p className="mk-hint" style={{ fontSize: 13, lineHeight: 1.6 }}>{t("db.whish.intro")}</p>
          <p><strong>{p.whish_connected ? t("db.whish.connected") : t("db.whish.notConnected")}</strong></p>
          <div className="field"><label htmlFor="w-ch">{t("db.whish.channel")}</label>
            <input id="w-ch" dir="ltr" value={p.whish_channel || ""} onChange={(e) => setP("whish_channel", e.target.value)} placeholder="10199" /></div>
          <div className="field"><label htmlFor="w-sec">{t("db.whish.secret")}</label>
            <input id="w-sec" dir="ltr" type="password" autoComplete="new-password" value={p.whish_secret_new || ""}
              onChange={(e) => setP("whish_secret_new", e.target.value)} placeholder={p.whish_connected ? t("db.whish.secretSaved") : t("db.whish.secretPh")} /></div>
          {p.whish_sandbox_allowed && (
            <label className="mk-check" style={{ marginBottom: 14 }}><input type="checkbox" checked={!!p.whish_sandbox} onChange={(e) => setP("whish_sandbox", e.target.checked)} /> {t("db.whish.test")}</label>
          )}
          <div className="field"><label htmlFor="w-to">{t("db.whish.timeout")}</label>
            <input id="w-to" type="number" min="5" max="120" style={{ maxWidth: 100 }} value={p.wish_timeout_mins || 15} onChange={(e) => setP("wish_timeout_mins", e.target.value)} /></div>
          <button className="btn-primary" disabled={busy} onClick={() => save({
            whish_channel: p.whish_channel || "", ...(p.whish_secret_new ? { whish_secret: p.whish_secret_new } : {}),
            whish_sandbox: !!p.whish_sandbox, wishTimeoutMins: parseInt(p.wish_timeout_mins, 10) || 15,
          }, true)}>{t("db.save")}</button>
        </section>
      )}

      {tab === "alerts" && (
        <section className="settings-section">
          <div className="field"><label htmlFor="a-rep">{t("ops.report")}</label>
            <input id="a-rep" dir="ltr" value={p.report_whatsapp || ""} onChange={(e) => setP("report_whatsapp", e.target.value)} placeholder="+96170123456" />
            {hint(t("ops.reportHint"))}</div>
          <div className="field"><label htmlFor="a-hour">{t("ops.reportHour")}</label>
            <select id="a-hour" style={{ maxWidth: 140 }} value={p.report_hour ?? 23} onChange={(e) => setP("report_hour", e.target.value)}>
              {Array.from({ length: 24 }, (_, h) => <option key={h} value={h}>{String(h).padStart(2, "0")}:00</option>)}
            </select></div>
          <div className="field"><label htmlFor="a-pause">{t("ops.pause")}</label>
            <input id="a-pause" type="number" min="1" max="1440" style={{ maxWidth: 120 }} value={p.bot_pause_mins ?? 60} onChange={(e) => setP("bot_pause_mins", e.target.value)} />
            {hint(t("ops.pauseHint"))}</div>
          <button className="btn-primary" disabled={busy} onClick={() => save({
            report_whatsapp: p.report_whatsapp || null, report_hour: num(p.report_hour), bot_pause_mins: num(p.bot_pause_mins),
          })}>{t("db.save")}</button>
        </section>
      )}

      {modal?.type === "addZone" && (
        <ZoneModal t={t} onClose={() => setModal(null)} onSubmit={async (vals) => {
          if (!vals.name) { toast(t("db.zone.nameRequired"), "error"); return; }
          try { await api("POST", "/settings/zones", vals); setModal(null); load(); toast(t("db.saved")); }
          catch (e) { toast(e.message, "error"); }
        }} />
      )}
      {modal?.type === "deleteZone" && (
        <ConfirmModal title={t("db.remove")} message={<><strong>{modal.zone.name}</strong> — {t("db.zone.remove")}</>} danger
          onClose={() => setModal(null)}
          onConfirm={async () => {
            try { await api("DELETE", `/settings/zones/${modal.zone.id}`); load(); toast(t("db.saved")); }
            catch (e) { toast(e.message, "error"); }
          }} />
      )}
    </div>
  );
}

function ZoneModal({ t, onClose, onSubmit }) {
  const [name, setName] = useState("");
  const [fee, setFee] = useState("");
  const [min, setMin] = useState("");
  const [drive, setDrive] = useState("15");
  return (
    <Modal title={t("db.zone.add")} onClose={onClose} compact
      footer={<div className="modal-footer-actions">
        <button type="button" className="btn-sm" onClick={onClose}>{t("db.cancel")}</button>
        <button type="button" className="btn-primary" onClick={() => onSubmit({
          name: name.trim(), deliveryFee: parseFloat(fee) || 0, minimumOrder: parseFloat(min) || 0, deliveryMins: parseInt(drive, 10) || 15,
        })}>{t("db.add")}</button>
      </div>}>
      <div className="field"><label htmlFor="z-name">{t("db.zone.name")}</label><input id="z-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Jbeil · جبيل" autoFocus /></div>
      <div className="modal-row">
        <div className="field"><label htmlFor="z-fee">{t("db.zone.fee")}</label><input id="z-fee" type="number" step="0.5" min="0" value={fee} onChange={(e) => setFee(e.target.value)} placeholder="1.50" /></div>
        <div className="field"><label htmlFor="z-min">{t("db.zone.min")}</label><input id="z-min" type="number" step="0.5" min="0" value={min} onChange={(e) => setMin(e.target.value)} placeholder="5.00" /></div>
      </div>
      <div className="field"><label htmlFor="z-drive">{t("zones.drive")}</label><input id="z-drive" type="number" min="1" max="240" style={{ maxWidth: 120 }} value={drive} onChange={(e) => setDrive(e.target.value)} /></div>
    </Modal>
  );
}
