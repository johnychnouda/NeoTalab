"use client";

import { useCallback, useEffect, useState } from "react";
import { useMerchant } from "../../layout";
import { useLang } from "@/lib/i18n";

export default function AnalyticsPage() {
  const { api } = useMerchant();
  const { t } = useLang();

  const [days, setDays] = useState("30");
  const [data, setData] = useState(null);

  const load = useCallback(async () => {
    try {
      const d = await api("GET", `/api/analytics/dashboard?days=${days}`);
      setData(d);
    } catch (e) { console.error(e); }
  }, [api, days]);

  useEffect(() => { load(); }, [load]);

  const s = data?.summary || {};

  return (
    <div>
      <div className="page-header">
        <h1>{t("analytics.title")}</h1>
        <select className="filter-select" value={days} onChange={(e) => setDays(e.target.value)}>
          <option value="7">7 days</option>
          <option value="30">30 days</option>
          <option value="90">90 days</option>
        </select>
      </div>

      <div className="stats-grid">
        <Stat label="Completed Orders" value={s.completed_orders || 0} />
        <Stat label="Revenue" value={`$${parseFloat(s.gross_revenue || 0).toFixed(2)}`} />
        <Stat label="Avg Order" value={`$${parseFloat(s.avg_order_value || 0).toFixed(2)}`} />
        <Stat label="Cancelled" value={s.cancelled_orders || 0} color="var(--red)" />
        <Stat label="Rejected" value={s.rejected_orders || 0} color="var(--red)" />
        <Stat label="Avg Response" value={`${parseFloat(s.avg_response_mins || 0).toFixed(1)}m`} />
      </div>

      <div className="section-title" style={{ marginTop: 24 }}>{t("analytics.topProducts")}</div>
      <table className="data-table">
        <thead><tr><th>{t("th.product")}</th><th>{t("th.qty")} Sold</th><th>{t("th.revenue")}</th></tr></thead>
        <tbody>
          {(data?.topProducts || []).length === 0 && <tr><td colSpan={3} className="loader">No data</td></tr>}
          {(data?.topProducts || []).map((p) => (
            <tr key={p.product_name}>
              <td><strong>{p.product_name}</strong></td>
              <td>{p.qty}</td>
              <td>${parseFloat(p.revenue || 0).toFixed(2)}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <div className="section-title" style={{ marginTop: 24 }}>{t("analytics.drivers")}</div>
      <table className="data-table">
        <thead><tr><th>{t("th.driver")}</th><th>{t("th.deliveries")}</th><th>{t("analytics.avgDelivery")}</th></tr></thead>
        <tbody>
          {(data?.driverStats || []).length === 0 && <tr><td colSpan={3} className="loader">No data</td></tr>}
          {(data?.driverStats || []).map((d) => (
            <tr key={d.name}>
              <td>{d.name}</td>
              <td>{d.deliveries}</td>
              <td>{parseFloat(d.avg_delivery_mins || 0).toFixed(0)} min</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function Stat({ label, value, color }) {
  return (
    <div className="stat-card">
      <div className="stat-label">{label}</div>
      <div className="stat-value" style={color ? { color } : undefined}>{value}</div>
    </div>
  );
}
