"use client";

import { useCallback, useEffect, useState } from "react";
import { useMerchant } from "../../layout";
import { useLang } from "@/lib/i18n";
import Segmented from "@/components/Segmented";
import { useLiveRefresh } from "@/lib/live";
import { formatMoney } from "@/lib/money";
import { Modal, useToast } from "@/components/ui";

export default function CustomersPage() {
  const { api, shop } = useMerchant();
  const { t } = useLang();
  const toast = useToast();

  const [customers, setCustomers] = useState(null);
  const [error, setError] = useState("");
  const [search, setSearch] = useState("");
  const [blockedFilter, setBlockedFilter] = useState("");
  const [blockModal, setBlockModal] = useState(null);
  const [blockReason, setBlockReason] = useState("");

  const load = useCallback(async () => {
    let url = "/customers?limit=50";
    if (search) url += `&search=${encodeURIComponent(search)}`;
    if (blockedFilter !== "") url += `&blocked=${blockedFilter}`;
    try {
      const { customers: c } = await api("GET", url);
      setCustomers(c);
      setError("");
    } catch (e) { setError(e.message); }
  }, [api, search, blockedFilter]);

  useEffect(() => {
    const timer = setTimeout(load, search ? 400 : 0);
    return () => clearTimeout(timer);
  }, [load, search]);
  useLiveRefresh(load);

  async function unblock(id) {
    try { await api("POST", `/customers/${id}/unblock`); load(); toast(t("db.saved")); }
    catch (e) { toast(e.message, "error"); }
  }

  return (
    <div>
      <div className="page-header">
        <h1>{t("db.cust.title")}</h1>
      </div>
      <div className="filters">
        <input type="text" className="search-input" placeholder={t("db.cust.search")}
          value={search} onChange={(e) => setSearch(e.target.value)} />
        <Segmented aria-label={t("db.cust.all")} value={blockedFilter} onChange={setBlockedFilter}
          options={[{ value: "", label: t("db.cust.all") }, { value: "false", label: t("db.cust.active") }, { value: "true", label: t("db.cust.blocked") }]} />
      </div>
      <div className="table-scroll">
<table className="data-table stack-on-phone">
        <thead>
          <tr>
            <th>{t("th.customer")}</th><th>{t("th.phone")}</th><th>{t("th.orders")}</th>
            <th>{t("th.spent")}</th><th>{t("th.status")}</th><th />
          </tr>
        </thead>
        <tbody>
          {customers === null && <tr><td colSpan={6} className="loader">{t("db.loading")}</td></tr>}
          {error && <tr><td colSpan={6} style={{ color: "var(--red)" }}>{error}</td></tr>}
          {customers && !error && customers.length === 0 && <tr><td colSpan={6} className="loader">{t("db.cust.empty")}</td></tr>}
          {customers && customers.map((c) => (
            <tr key={c.id}>
              <td className="stack-title"><strong>{c.name || "—"}</strong></td>
              <td data-label={t("th.phone")} style={{ fontSize: 12 }}><span dir="ltr">{c.phone}</span></td>
              <td data-label={t("th.orders")}>{c.total_orders || 0}</td>
              <td data-label={t("th.spent")}>{formatMoney(c.total_spent, shop.currency)}</td>
              <td data-label={t("th.status")}>{c.is_blocked
                ? <span className="pill pill-suspended">{t("db.cust.blocked")}</span>
                : <span className="pill pill-active">{t("db.cust.active")}</span>}</td>
              <td className="stack-actions">{c.is_blocked
                ? <button className="btn-sm" onClick={() => unblock(c.id)}>{t("db.cust.unblock")}</button>
                : <button className="btn-sm btn-sm-red" onClick={() => { setBlockReason(""); setBlockModal(c); }}>{t("db.cust.block")}</button>}</td>
            </tr>
          ))}
        </tbody>
      </table>
      </div>

      {blockModal && (
        <Modal title={t("db.cust.blockTitle")} onClose={() => setBlockModal(null)}>
          <div className="field">
            <p className="mk-hint" style={{ marginBottom: 10 }}>{t("db.cust.blockHint")}</p>
            <label>{t("db.cust.reason")}</label>
            <input value={blockReason} onChange={(e) => setBlockReason(e.target.value)}  />
          </div>
          <div className="modal-actions">
            <button className="btn-sm" onClick={() => setBlockModal(null)}>{t("db.cancel")}</button>
            <button className="btn-sm" style={{ color: "var(--red)", borderColor: "#ff4d4d40" }}
              onClick={async () => {
                try {
                  await api("POST", `/customers/${blockModal.id}/block`, { reason: blockReason });
                  setBlockModal(null); load(); toast(t("db.saved"));
                } catch (e) { toast(e.message, "error"); }
              }}>{t("db.cust.block")}</button>
          </div>
        </Modal>
      )}
    </div>
  );
}
