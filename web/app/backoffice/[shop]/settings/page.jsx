"use client";

import { useCallback, useEffect, useState } from "react";
import { useMerchant } from "../../layout";
import { useLang } from "@/lib/i18n";
import { Modal, ConfirmModal, useToast } from "@/components/ui";

const DAYS = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
const TABS = [
  { id: "profile", key: "settings.profile" },
  { id: "wish", key: "settings.wish" },
  { id: "hours", key: "settings.hours" },
  { id: "zones", key: "settings.zones" },
];

export default function SettingsPage() {
  const { api, refreshProfile } = useMerchant();
  const { t } = useLang();
  const toast = useToast();

  const [tab, setTab] = useState("profile");
  const [profile, setProfile] = useState({});
  const [hours, setHours] = useState([]);
  const [zones, setZones] = useState([]);
  const [modal, setModal] = useState(null);

  const load = useCallback(async () => {
    try {
      const [{ profile: p }, { hours: h }, { zones: z }] = await Promise.all([
        api("GET", "/api/settings/profile"),
        api("GET", "/api/settings/hours"),
        api("GET", "/api/settings/zones"),
      ]);
      if (p) setProfile(p);
      setHours((h || []).map((x) => ({
        dayOfWeek: x.day_of_week,
        opensAt: x.opens_at?.slice(0, 5) || "09:00",
        closesAt: x.closes_at?.slice(0, 5) || "23:00",
        isClosed: !!x.is_closed,
      })));
      setZones(z || []);
    } catch (e) { console.error(e); }
  }, [api]);

  useEffect(() => { load(); }, [load]);

  const setP = (key, val) => setProfile((p) => ({ ...p, [key]: val }));

  async function saveProfile() {
    try {
      await api("PATCH", "/api/settings/profile", {
        shopName: profile.shop_name,
        shopNameAr: profile.shop_name_ar,
        opsWhatsapp: profile.ops_whatsapp,
        defaultLocale: profile.default_locale,
      });
      await refreshProfile?.();
      toast("Profile saved!");
    } catch (e) { toast(e.message, "error"); }
  }

  async function saveWish() {
    try {
      await api("PATCH", "/api/settings/profile", {
        wishNumber: profile.wish_number,
        wishAutoConfirm: profile.wish_auto_confirm !== false,
        wishTimeoutMins: parseInt(profile.wish_timeout_mins) || 10,
      });
      toast("Wish settings saved!");
    } catch (e) { toast(e.message, "error"); }
  }

  async function saveHours() {
    try {
      await api("PUT", "/api/settings/hours", { hours });
      toast("Opening hours saved!");
    } catch (e) { toast(e.message, "error"); }
  }

  const setHour = (day, patch) => setHours((h) => h.map((x) => (x.dayOfWeek === day ? { ...x, ...patch } : x)));

  return (
    <div>
      <div className="page-header">
        <h1>{t("settings.title")}</h1>
      </div>

      <div className="bo-htabs">
        {TABS.map((x) => (
          <button key={x.id} className={`bo-htab ${tab === x.id ? "active" : ""}`} onClick={() => setTab(x.id)}>
            {t(x.key)}
          </button>
        ))}
      </div>

      {tab === "profile" && (
        <div className="settings-grid">
          <div className="settings-section">
            <h3>{t("settings.profile")}</h3>
            <div className="field"><label>{t("settings.shopName")}</label>
              <input value={profile.shop_name || ""} onChange={(e) => setP("shop_name", e.target.value)} placeholder="Joe's Snacks" /></div>
            <div className="field"><label>{t("settings.shopNameAr")}</label>
              <input value={profile.shop_name_ar || ""} onChange={(e) => setP("shop_name_ar", e.target.value)} dir="rtl" placeholder="مطعم جو" /></div>
            <div className="field"><label>{t("settings.opsWa")}</label>
              <input value={profile.ops_whatsapp || ""} onChange={(e) => setP("ops_whatsapp", e.target.value)} placeholder="+9613001001" /></div>
            <div className="field"><label>{t("settings.locale")}</label>
              <select value={profile.default_locale || "ar"} onChange={(e) => setP("default_locale", e.target.value)}>
                <option value="ar">العربية (Arabic)</option>
                <option value="en">English</option>
                <option value="fr">Français</option>
              </select></div>
            <button className="btn-primary" onClick={saveProfile}>Save Profile</button>
          </div>
        </div>
      )}

      {tab === "wish" && (
        <div className="settings-grid">
          <div className="settings-section">
            <h3>{t("settings.wish")}</h3>
            <div className="field"><label>{t("settings.wishNumber")}</label>
              <input value={profile.wish_number || ""} onChange={(e) => setP("wish_number", e.target.value)} placeholder="+9613001001" /></div>
            <div className="field checkbox-field">
              <input type="checkbox" id="wish-auto" checked={profile.wish_auto_confirm !== false}
                onChange={(e) => setP("wish_auto_confirm", e.target.checked)} />
              <label htmlFor="wish-auto">{t("settings.wishAuto")}</label>
            </div>
            <div className="field"><label>{t("settings.wishTimeout")}</label>
              <input type="number" style={{ maxWidth: 100 }} value={profile.wish_timeout_mins || 10}
                onChange={(e) => setP("wish_timeout_mins", e.target.value)} /></div>
            <button className="btn-primary" onClick={saveWish}>Save</button>
          </div>
        </div>
      )}

      {tab === "hours" && (
        <div className="settings-grid">
          <div className="settings-section">
            <h3>{t("settings.hours")}</h3>
            <div className="hours-grid">
              {hours.map((h) => (
                <div className="hours-row" key={h.dayOfWeek}>
                  <span className="hours-day">{DAYS[h.dayOfWeek]}</span>
                  <input type="checkbox" id={`cls-${h.dayOfWeek}`} checked={h.isClosed}
                    onChange={(e) => setHour(h.dayOfWeek, { isClosed: e.target.checked })} />
                  <label htmlFor={`cls-${h.dayOfWeek}`} style={{ fontSize: 12, color: "var(--text-muted)" }}>Closed</label>
                  <input type="time" className="time-input" value={h.opensAt} disabled={h.isClosed}
                    onChange={(e) => setHour(h.dayOfWeek, { opensAt: e.target.value })} />
                  <span style={{ color: "var(--text-muted)", fontSize: 12 }}>–</span>
                  <input type="time" className="time-input" value={h.closesAt} disabled={h.isClosed}
                    onChange={(e) => setHour(h.dayOfWeek, { closesAt: e.target.value })} />
                </div>
              ))}
            </div>
            <button className="btn-primary" style={{ marginTop: 16 }} onClick={saveHours}>Save Hours</button>
          </div>
        </div>
      )}

      {tab === "zones" && (
        <div className="settings-grid">
          <div className="settings-section">
            <h3>{t("settings.zones")}</h3>
            {zones.length === 0 && <div style={{ color: "var(--text-muted)", fontSize: 13, padding: "8px 0" }}>No zones added yet</div>}
            {zones.map((z) => (
              <div className="zone-row" key={z.id}>
                <span className="zone-name">{z.name}</span>
                <span className="zone-fee">${parseFloat(z.delivery_fee).toFixed(2)}</span>
                <span style={{ fontSize: 11, color: "var(--text-muted)" }}>min ${parseFloat(z.minimum_order || 0).toFixed(2)}</span>
                <button className="btn-sm btn-sm-red" onClick={() => setModal({ type: "deleteZone", zone: z })}>✕</button>
              </div>
            ))}
            <button className="btn-sm" style={{ marginTop: 12 }} onClick={() => setModal({ type: "addZone" })}>+ Add Zone</button>
          </div>
        </div>
      )}

      {modal?.type === "addZone" && (
        <AddZoneModal onClose={() => setModal(null)} onSubmit={async (vals) => {
          if (!vals.name) { toast("Zone name is required", "error"); return; }
          try {
            await api("POST", "/api/settings/zones", vals);
            setModal(null); load(); toast("Zone added!");
          } catch (e) { toast(e.message, "error"); }
        }} />
      )}
      {modal?.type === "deleteZone" && (
        <ConfirmModal title="Remove Zone" message="Remove this delivery zone?" danger
          onClose={() => setModal(null)}
          onConfirm={async () => {
            try { await api("DELETE", `/api/settings/zones/${modal.zone.id}`); load(); toast("Zone removed"); }
            catch (e) { toast(e.message, "error"); }
          }} />
      )}
    </div>
  );
}

function AddZoneModal({ onClose, onSubmit }) {
  const [name, setName] = useState("");
  const [fee, setFee] = useState("");
  const [min, setMin] = useState("");
  return (
    <Modal title="Add Delivery Zone" onClose={onClose}>
      <div className="field"><label>Zone Name</label><input value={name} onChange={(e) => setName(e.target.value)} placeholder="Jounieh Center" /></div>
      <div className="modal-row">
        <div className="field"><label>Delivery Fee ($)</label><input type="number" step="0.5" value={fee} onChange={(e) => setFee(e.target.value)} placeholder="1.50" /></div>
        <div className="field"><label>Min Order ($)</label><input type="number" step="0.5" value={min} onChange={(e) => setMin(e.target.value)} placeholder="5.00" /></div>
      </div>
      <div className="modal-actions">
        <button className="btn-sm" onClick={onClose}>Cancel</button>
        <button className="btn-primary" onClick={() => onSubmit({
          name: name.trim(),
          deliveryFee: parseFloat(fee) || 0,
          minimumOrder: parseFloat(min) || 0,
        })}>Add Zone</button>
      </div>
    </Modal>
  );
}
