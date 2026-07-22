"use client";

import { useCallback, useEffect, useState } from "react";
import { useMerchant } from "../../layout";
import { useLang } from "@/lib/i18n";
import { Modal, useToast } from "@/components/ui";

export default function CustomersPage() {
  const { api } = useMerchant();
  const { t } = useLang();
  const toast = useToast();

  const [customers, setCustomers] = useState(null);
  const [error, setError] = useState("");
  const [search, setSearch] = useState("");
  const [blockedFilter, setBlockedFilter] = useState("");
  const [blockModal, setBlockModal] = useState(null);
  const [blockReason, setBlockReason] = useState("");

  const load = useCallback(async () => {
    let url = "/api/customers?limit=50";
    if (search) url += `&search=${encodeURIComponent(search)}`;
    if (blockedFilter !== "") url += `&blocked=${blockedFilter}`;
    try {
      const { customers: c } = await api("GET", url);
      setCustomers(c);
      setError("");
    } catch (e) { setError(e.message); setCustomers([]); }
  }, [api, search, blockedFilter]);

  useEffect(() => {
    const timer = setTimeout(load, search ? 400 : 0);
    return () => clearTimeout(timer);
  }, [load, search]);

  async function unblock(id) {
    try { await api("POST", `/api/customers/${id}/unblock`); load(); toast("Customer unblocked"); }
    catch (e) { toast(e.message, "error"); }
  }

  return (
    <div>
      <div className="page-header">
        <h1>{t("customers.title")}</h1>
      </div>
      <div className="filters">
        <input type="text" className="search-input" placeholder="Search by name or phone..."
          value={search} onChange={(e) => setSearch(e.target.value)} />
        <select className="filter-select" value={blockedFilter} onChange={(e) => setBlockedFilter(e.target.value)}>
          <option value="">All</option>
          <option value="false">Active</option>
          <option value="true">Blocked</option>
        </select>
      </div>
      <table className="data-table">
        <thead>
          <tr>
            <th>{t("th.customer")}</th><th>{t("th.phone")}</th><th>{t("th.orders")}</th>
            <th>{t("th.spent")}</th><th>{t("th.status")}</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
          {customers === null && <tr><td colSpan={6} className="loader">Loading...</td></tr>}
          {error && <tr><td colSpan={6} style={{ color: "var(--red)" }}>{error}</td></tr>}
          {customers && !error && customers.length === 0 && <tr><td colSpan={6} className="loader">No customers</td></tr>}
          {customers && customers.map((c) => (
            <tr key={c.id}>
              <td><strong>{c.name || "—"}</strong></td>
              <td style={{ fontSize: 12 }}>{c.phone}</td>
              <td>{c.total_orders || 0}</td>
              <td>${parseFloat(c.total_spent || 0).toFixed(2)}</td>
              <td>{c.is_blocked
                ? <span className="pill pill-suspended">Blocked</span>
                : <span className="pill pill-active">Active</span>}</td>
              <td>{c.is_blocked
                ? <button className="btn-sm" onClick={() => unblock(c.id)}>Unblock</button>
                : <button className="btn-sm btn-sm-red" onClick={() => { setBlockReason(""); setBlockModal(c); }}>Block</button>}</td>
            </tr>
          ))}
        </tbody>
      </table>

      {blockModal && (
        <Modal title="Block Customer" onClose={() => setBlockModal(null)}>
          <div className="field">
            <label>Block reason <span style={{ color: "var(--text-muted)", fontWeight: 400 }}>(optional)</span></label>
            <input value={blockReason} onChange={(e) => setBlockReason(e.target.value)} placeholder="e.g. Repeated cancellations, fraud…" />
          </div>
          <div className="modal-actions">
            <button className="btn-sm" onClick={() => setBlockModal(null)}>Cancel</button>
            <button className="btn-sm" style={{ color: "var(--red)", borderColor: "#ff4d4d40" }}
              onClick={async () => {
                try {
                  await api("POST", `/api/customers/${blockModal.id}/block`, { reason: blockReason });
                  setBlockModal(null); load(); toast("Customer blocked");
                } catch (e) { toast(e.message, "error"); }
              }}>Block</button>
          </div>
        </Modal>
      )}
    </div>
  );
}
