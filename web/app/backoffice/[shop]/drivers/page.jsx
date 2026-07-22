"use client";

import { useCallback, useEffect, useState } from "react";
import { useMerchant } from "../../layout";
import { useLang } from "@/lib/i18n";
import { Modal, ConfirmModal, useToast } from "@/components/ui";

const STATUS_COLOR = { on_duty: "#25d366", off_duty: "#888", on_break: "#f59e0b", paused: "#3b82f6" };

export default function DriversPage() {
  const { api } = useMerchant();
  const { t } = useLang();
  const toast = useToast();

  const [drivers, setDrivers] = useState(null);
  const [error, setError] = useState("");
  const [modal, setModal] = useState(null);

  const load = useCallback(async () => {
    try {
      const { drivers: d } = await api("GET", "/api/drivers");
      setDrivers(d);
    } catch (e) { setError(e.message); }
  }, [api]);

  useEffect(() => { load(); }, [load]);

  async function setStatus(id, status) {
    try { await api("POST", `/api/drivers/${id}/status`, { status }); load(); } catch { }
  }

  return (
    <div>
      <div className="page-header">
        <h1>{t("drivers.title")}</h1>
        <button className="btn-primary" onClick={() => setModal({ type: "add" })}>+ {t("drivers.add")}</button>
      </div>

      {error && <div style={{ color: "var(--red)", padding: 20 }}>{error}</div>}
      {drivers === null && !error && <div className="loader">Loading…</div>}
      {drivers && drivers.length === 0 && (
        <div className="empty-state"><div className="empty-icon">🛵</div><div>No drivers yet</div></div>
      )}

      <div className="drivers-grid">
        {drivers && drivers.map((d) => (
          <div className="driver-card" key={d.id}>
            <div style={{ display: "flex", alignItems: "center", gap: 12, marginBottom: 12 }}>
              <div className="driver-avatar">{d.name.charAt(0).toUpperCase()}</div>
              <div>
                <div className="driver-name">{d.name}</div>
                <div style={{ color: "var(--text-muted)", fontSize: 12 }}>{d.phone}</div>
              </div>
            </div>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 8 }}>
              <span style={{ color: STATUS_COLOR[d.status] || "#888", fontSize: 12, fontWeight: 700, textTransform: "uppercase" }}>
                {d.status.replace(/_/g, " ")}
              </span>
              {d.availability === "busy"
                ? <span style={{ fontSize: 11, color: "#8b5cf6", background: "#8b5cf618", padding: "2px 8px", borderRadius: 20 }}>🔴 On delivery</span>
                : <span style={{ fontSize: 11, color: "var(--green)", background: "var(--green-dim)", padding: "2px 8px", borderRadius: 20 }}>Available</span>}
            </div>
            <div style={{ fontSize: 12, color: "var(--text-muted)", marginBottom: 10 }}>
              📦 {d.total_deliveries} deliveries{d.cash_on_hand ? ` · 💵 $${parseFloat(d.cash_on_hand).toFixed(2)} cash` : ""}
            </div>
            <div style={{ display: "flex", gap: 6 }}>
              <select className="filter-select" style={{ flex: 1, fontSize: 12, padding: "5px 8px" }}
                value={d.status} onChange={(e) => setStatus(d.id, e.target.value)}>
                <option value="on_duty">On Duty</option>
                <option value="off_duty">Off Duty</option>
                <option value="on_break">Break</option>
              </select>
              <button className="btn-sm btn-sm-red" onClick={() => setModal({ type: "remove", driver: d })}>Remove</button>
            </div>
          </div>
        ))}
      </div>

      {modal?.type === "add" && (
        <AddDriverModal onClose={() => setModal(null)} onSubmit={async (vals) => {
          if (!vals.name) { toast("Name is required", "error"); return; }
          try {
            await api("POST", "/api/drivers", vals);
            setModal(null); load(); toast("Driver added!");
          } catch (e) { toast(e.message, "error"); }
        }} />
      )}
      {modal?.type === "remove" && (
        <ConfirmModal title="Deactivate Driver"
          message="Deactivate this driver? They won't receive new deliveries."
          danger onClose={() => setModal(null)}
          onConfirm={async () => {
            try { await api("DELETE", `/api/drivers/${modal.driver.id}`); load(); toast("Driver deactivated"); }
            catch (e) { toast(e.message, "error"); }
          }} />
      )}
    </div>
  );
}

function AddDriverModal({ onClose, onSubmit }) {
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [wa, setWa] = useState("");
  const [password, setPassword] = useState("");
  return (
    <Modal title="Add Driver" onClose={onClose}>
      <div className="field"><label>Full Name</label><input value={name} onChange={(e) => setName(e.target.value)} placeholder="Rami Khalil" /></div>
      <div className="field"><label>Phone</label><input value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="+9613001001" /></div>
      <div className="field"><label>WhatsApp</label><input value={wa} onChange={(e) => setWa(e.target.value)} placeholder="+9613001001" /></div>
      <div className="field"><label>Password</label><input type="password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder="••••••••" /></div>
      <div className="modal-actions">
        <button className="btn-sm" onClick={onClose}>Cancel</button>
        <button className="btn-primary" onClick={() => onSubmit({ name: name.trim(), phone, whatsappNumber: wa, password })}>Add Driver</button>
      </div>
    </Modal>
  );
}
