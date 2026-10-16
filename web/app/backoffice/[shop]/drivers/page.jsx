"use client";

import { useCallback, useEffect, useState } from "react";
import { useMerchant } from "../../layout";
import { useLang } from "@/lib/i18n";
import { useLiveRefresh } from "@/lib/live";
import { Modal, ConfirmModal, useToast } from "@/components/ui";
import { EmptyState, ErrorBanner, EMPTY_ICONS } from "@/components/owner/ui";

/**
 * The shop's team: drivers (get deliveries) and staff (accept orders, open/close the shop).
 * Everyone works from their own WhatsApp; this page only adds or removes them.
 */
export default function TeamPage() {
  const { api } = useMerchant();
  const { t } = useLang();
  const toast = useToast();

  const [team, setTeam] = useState(null);
  const [error, setError] = useState("");
  const [modal, setModal] = useState(null);

  const load = useCallback(async () => {
    try { setTeam((await api("GET", "/drivers")).drivers); } catch (e) { setError(e.message); }
  }, [api]);

  useEffect(() => { load(); }, [load]);
  useLiveRefresh(load);

  const groups = [
    ["staff", (team || []).filter((m) => m.role === "staff")],
    ["driver", (team || []).filter((m) => m.role !== "staff")],
  ];

  return (
    <div>
      <div className="page-header">
        <h1>{t("db.team.title")}</h1>
        <button className="btn-primary" onClick={() => setModal({ type: "add" })}>+ {t("db.team.add")}</button>
      </div>

      <ErrorBanner message={error} />
      {team === null && !error && <div className="loader">{t("db.loading")}</div>}
      {team && team.length === 0 && (
        <EmptyState icon={EMPTY_ICONS.team} title={t("db.team.empty")} actionLabel={`+ ${t("db.team.add")}`} actionClassName="btn-primary" onAction={() => setModal({ type: "add" })} />
      )}

      {team && team.length > 0 && groups.map(([role, members]) => (
        <section key={role} className="tm-group">
          <h2 className="gs-h">{t(role === "staff" ? "db.team.staff" : "db.team.driver")}</h2>
          <p className="mk-hint" style={{ marginBottom: 12 }}>{t(role === "staff" ? "db.team.staffHow" : "db.team.driverHow")}</p>
          {members.length === 0 && <p className="mk-hint">—</p>}
          <div className="drivers-grid">
            {members.map((m) => (
              <div className="driver-card" key={m.id}>
                <div className="tm-head">
                  <div className="driver-avatar">{m.name.charAt(0).toUpperCase()}</div>
                  <div style={{ minWidth: 0 }}>
                    <div className="driver-name">{m.name}</div>
                    <div className="mk-hint" style={{ margin: 0 }} dir="ltr">{m.whatsapp_number || m.phone}</div>
                  </div>
                </div>
                {role === "driver" && (
                  <div className="tm-status">
                    <span className={`pill ${m.status === "on_duty" ? "pill-active" : ""}`}>{t(m.status === "on_duty" ? "db.team.onShift" : "db.team.offShift")}</span>
                    {m.status === "on_duty" && m.availability === "busy" && <span className="pill pill-suspended">{t("db.team.onDelivery")}</span>}
                  </div>
                )}
                {role === "driver" && (
                  <div className="mk-hint" style={{ marginBottom: 10 }}>{m.total_deliveries || 0} {t("db.team.deliveries")}</div>
                )}
                <div style={{ display: "flex", gap: 6, justifyContent: "flex-end" }}>
                  <button className="btn-sm" onClick={() => setModal({ type: "edit", member: m })}>{t("mk.edit")}</button>
                  <button className="btn-sm btn-sm-red" onClick={() => setModal({ type: "remove", member: m })} aria-label={`${t("db.remove")} ${m.name}`}>✕</button>
                </div>
              </div>
            ))}
          </div>
        </section>
      ))}

      {(modal?.type === "add" || modal?.type === "edit") && (
        <MemberModal t={t} member={modal.member} onClose={() => setModal(null)} onSubmit={async (vals) => {
          if (!vals.name || !vals.phone) { toast(t("db.team.required"), "error"); return; }
          try {
            if (modal.member) await api("PATCH", `/drivers/${modal.member.id}`, vals);
            else await api("POST", "/drivers", vals);
            setModal(null); load(); toast(t(modal.member ? "db.saved" : "db.team.added"));
          } catch (e) { toast(e.message, "error"); }
        }} />
      )}

      {modal?.type === "remove" && (
        <ConfirmModal title={t("db.remove")} message={<><strong>{modal.member.name}</strong> {t("db.team.removeConfirm")}</>} danger
          onClose={() => setModal(null)}
          onConfirm={async () => {
            try { await api("DELETE", `/drivers/${modal.member.id}`); load(); toast(t("db.saved")); }
            catch (e) { toast(e.message, "error"); }
          }} />
      )}
    </div>
  );
}

function MemberModal({ t, member, onClose, onSubmit }) {
  const [name, setName] = useState(member?.name || "");
  const [phone, setPhone] = useState(member?.whatsapp_number || member?.phone || "");
  const [role, setRole] = useState(member && member.role !== "staff" ? "driver" : "staff");
  return (
    <Modal title={t(member ? "db.team.edit" : "db.team.add")} onClose={onClose} compact
      footer={<div className="modal-footer-actions">
        <button type="button" className="btn-sm" onClick={onClose}>{t("db.cancel")}</button>
        <button type="button" className="btn-primary" onClick={() => onSubmit({ name: name.trim(), phone: phone.trim(), role })}>{t(member ? "db.save" : "db.add")}</button>
      </div>}>
      <div className="field"><label>{t("db.team.role")}</label>
        <div className="tm-roles">
          {["staff", "driver"].map((r) => (
            <button key={r} type="button" className={`tm-role ${role === r ? "on" : ""}`} onClick={() => setRole(r)}>
              <strong>{t(r === "staff" ? "db.team.staff" : "db.team.driver")}</strong>
              <span>{t(r === "staff" ? "db.team.staffHow" : "db.team.driverHow")}</span>
            </button>
          ))}
        </div>
      </div>
      <div className="field"><label htmlFor="tm-name">{t("db.team.name")}</label><input id="tm-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Rami Khalil" /></div>
      <div className="field"><label htmlFor="tm-phone">{t("db.team.number")}</label><input id="tm-phone" dir="ltr" inputMode="tel" value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="70 123 456" />
        <div className="mk-hint">{t("db.team.numberHint")}</div></div>
    </Modal>
  );
}
