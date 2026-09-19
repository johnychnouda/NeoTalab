"use client";

import { useCallback, useEffect, useState } from "react";
import { useMerchant } from "../layout";
import { useLang } from "@/lib/i18n";
import { Modal, ConfirmModal, useToast } from "@/components/ui";

const STATUS_COLORS = {
  confirmed: "#25d366", preparing: "#f59e0b", ready: "#3b82f6",
  assigned: "#8b5cf6", picked_up: "#ec4899", pending_payment: "#f59e0b",
};

export default function OrdersPage() {
  const { api } = useMerchant();
  const { t } = useLang();
  const toast = useToast();

  const [liveOrders, setLiveOrders] = useState(null);
  const [history, setHistory] = useState(null);
  const [historyError, setHistoryError] = useState("");
  const [date, setDate] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [clock, setClock] = useState("");
  const [modal, setModal] = useState(null); // { type: "reject"|"cancel", id }
  const [rejectReason, setRejectReason] = useState("");

  const loadLive = useCallback(async () => {
    try {
      const { orders } = await api("GET", "/api/orders/live");
      setLiveOrders(orders);
    } catch (e) {
      setLiveOrders([]);
      toast(e.message, "error");
    }
  }, [api, toast]);

  const loadHistory = useCallback(async () => {
    let url = "/api/orders?limit=50";
    if (date) url += `&date=${date}`;
    if (statusFilter) url += `&status=${statusFilter}`;
    setHistory(null);
    setHistoryError("");
    try {
      const { orders } = await api("GET", url);
      const hist = statusFilter ? orders : orders.filter((o) => ["delivered", "cancelled", "rejected"].includes(o.status));
      setHistory(hist);
    } catch (e) {
      setHistoryError(e.message);
      setHistory([]);
    }
  }, [api, date, statusFilter]);

  useEffect(() => { loadLive(); }, [loadLive]);
  useEffect(() => { loadHistory(); }, [loadHistory]);

  useEffect(() => {
    const clockTimer = setInterval(() => {
      setClock(new Date().toLocaleTimeString("en-US", { hour: "2-digit", minute: "2-digit", second: "2-digit" }));
    }, 1000);
    const refreshTimer = setInterval(loadLive, 30000);
    return () => { clearInterval(clockTimer); clearInterval(refreshTimer); };
  }, [loadLive]);

  async function orderAction(id, action, body) {
    try {
      await api("POST", `/api/orders/${id}/${action}`, body);
      loadLive();
      if (action === "cancel") loadHistory();
    } catch (e) { toast(e.message, "error"); }
  }

  return (
    <div>
      <div className="page-header">
        <h1>{t("orders.title")}</h1>
        <div style={{ display: "flex", gap: 10, alignItems: "center" }}>
          <span className="header-date">{clock}</span>
          <button className="btn-sm" onClick={loadLive}>↻ Refresh</button>
        </div>
      </div>

      <div className="orders-grid">
        {liveOrders === null && <div className="loader">Loading…</div>}
        {liveOrders && liveOrders.length === 0 && (
          <div className="empty-state"><div className="empty-icon">🟢</div><div>No active orders right now</div></div>
        )}
        {liveOrders && liveOrders.map((o) => (
          <OrderCard key={o.id} order={o}
            onAccept={() => orderAction(o.id, "accept")}
            onReject={() => { setRejectReason(""); setModal({ type: "reject", id: o.id }); }}
            onPreparing={() => orderAction(o.id, "preparing", { extraMins: 0 })}
            onReady={() => orderAction(o.id, "ready")}
            onCancel={() => setModal({ type: "cancel", id: o.id })}
          />
        ))}
      </div>

      <div className="section-title" style={{ marginTop: 32 }}>{t("orders.history")}</div>
      <div className="filters">
        <input type="date" className="filter-select" value={date} onChange={(e) => setDate(e.target.value)} />
        <select className="filter-select" value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
          <option value="">All</option>
          <option value="delivered">Delivered</option>
          <option value="cancelled">Cancelled</option>
          <option value="rejected">Rejected</option>
        </select>
      </div>
      <div className="table-scroll">
<table className="data-table">
        <thead>
          <tr>
            <th>#</th><th>{t("th.customer")}</th><th>{t("th.items")}</th><th>{t("th.total")}</th>
            <th>{t("th.payment")}</th><th>{t("th.status")}</th><th>{t("th.time")}</th>
          </tr>
        </thead>
        <tbody>
          {history === null && <tr><td colSpan={7} className="loader">Loading...</td></tr>}
          {historyError && <tr><td colSpan={7} style={{ color: "var(--red)", padding: 16 }}>{historyError}</td></tr>}
          {history && !historyError && history.length === 0 && <tr><td colSpan={7} className="loader">No past orders</td></tr>}
          {history && history.map((o) => (
            <tr key={o.id}>
              <td style={{ fontFamily: "monospace", fontSize: 11 }}>#{o.id?.slice(-6).toUpperCase()}</td>
              <td>{o.customer_name || "—"}</td>
              <td style={{ fontSize: 12, color: "var(--text-muted)" }}>
                {(o.items || []).map((i) => i.productName || "").join(", ").slice(0, 40)}
              </td>
              <td>${parseFloat(o.total || 0).toFixed(2)}</td>
              <td style={{ textTransform: "capitalize" }}>{o.payment_method || "—"}</td>
              <td>
                <span className={`pill pill-${o.status === "delivered" ? "active" : o.status === "cancelled" ? "suspended" : "pending"}`}>
                  {o.status}
                </span>
              </td>
              <td style={{ color: "var(--text-muted)", fontSize: 12 }}>
                {new Date(o.created_at).toLocaleTimeString("en-US", { hour: "2-digit", minute: "2-digit" })}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      </div>

      {modal?.type === "reject" && (
        <Modal title="Reject Order" onClose={() => setModal(null)}>
          <div className="field">
            <label>Rejection reason <span style={{ color: "var(--text-muted)", fontWeight: 400 }}>(optional)</span></label>
            <input value={rejectReason} onChange={(e) => setRejectReason(e.target.value)} placeholder="e.g. Out of stock, kitchen closed…" />
          </div>
          <div className="modal-actions">
            <button className="btn-sm" onClick={() => setModal(null)}>Cancel</button>
            <button className="btn-primary" onClick={() => { orderAction(modal.id, "reject", { reason: rejectReason }); setModal(null); }}>
              Reject Order
            </button>
          </div>
        </Modal>
      )}

      {modal?.type === "cancel" && (
        <ConfirmModal
          title="Cancel Order"
          message="Cancel this order? The customer will be notified via WhatsApp."
          confirmLabel="Confirm"
          danger
          onConfirm={() => orderAction(modal.id, "cancel")}
          onClose={() => setModal(null)}
        />
      )}
    </div>
  );
}

function OrderCard({ order: o, onAccept, onReject, onPreparing, onReady, onCancel }) {
  const color = STATUS_COLORS[o.status] || "#888";
  const items = (o.items || []).map((i) => i.productName || "").join(", ");
  const age = Math.floor((Date.now() - new Date(o.created_at)) / 60000);

  let actions = null;
  if (o.status === "pending_payment") {
    actions = <>
      <button className="btn-primary btn-sm-action" onClick={onAccept}>✅ Accept</button>
      <button className="btn-sm" onClick={onReject}>Reject</button>
    </>;
  } else if (o.status === "confirmed") {
    actions = <>
      <button className="btn-primary btn-sm-action" onClick={onPreparing}>🍳 Preparing</button>
      <button className="btn-sm btn-sm-red" onClick={onCancel}>Cancel</button>
    </>;
  } else if (o.status === "preparing") {
    actions = <button className="btn-primary btn-sm-action" onClick={onReady}>✅ Ready</button>;
  } else if (o.status === "ready") {
    actions = <span style={{ color: "var(--blue)", fontSize: 12, fontWeight: 600 }}>⏳ Awaiting driver pickup</span>;
  } else if (o.status === "assigned") {
    actions = <span style={{ color: "#8b5cf6", fontSize: 12, fontWeight: 600 }}>🛵 Driver assigned</span>;
  } else if (o.status === "picked_up") {
    actions = <span style={{ color: "#ec4899", fontSize: 12, fontWeight: 600 }}>🚀 On the way</span>;
  }

  return (
    <div className="order-card" style={{ borderLeft: `3px solid ${color}` }}>
      <div className="order-card-header">
        <span className="order-id">#{o.id?.slice(-6).toUpperCase()}</span>
        <span style={{ color, fontSize: 11, fontWeight: 700, textTransform: "uppercase" }}>{o.status.replace(/_/g, " ")}</span>
      </div>
      <div className="order-customer"><strong>{o.customer_name || o.customer_phone}</strong></div>
      <div className="order-items">{items}</div>
      <div className="order-meta">
        <span>💰 ${parseFloat(o.total || 0).toFixed(2)} · {o.payment_method}</span>
        {o.eta_mins ? <span>⏱ ~{o.eta_mins}m</span> : null}
        {o.driver_name ? <span>🛵 {o.driver_name}</span> : null}
        <span style={{ color: "var(--text-muted)" }}>{age}m ago</span>
      </div>
      <div className="order-card-actions">{actions}</div>
    </div>
  );
}
