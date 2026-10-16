"use client";

import { useCallback, useEffect, useState } from "react";
import { useMerchant } from "../../layout";
import { useLang } from "@/lib/i18n";
import { useLiveRefresh } from "@/lib/live";
import { formatMoney } from "@/lib/money";
import { WaLink } from "@/components/ui";
import { ErrorBanner } from "@/components/owner/ui";

export default function AnalyticsPage() {
  const { api, shop } = useMerchant();
  const { t } = useLang();

  const [days, setDays] = useState("30");
  const [data, setData] = useState(null);
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    try {
      const d = await api("GET", `/analytics/dashboard?days=${days}`);
      setData(d);
      setError("");
    } catch (e) { setError(e.message); }
  }, [api, days]);

  useEffect(() => { load(); }, [load]);
  useLiveRefresh(load);

  const s = data?.summary || {};

  return (
    <div>
      <div className="page-header">
        <h1>{t("db.nav.reports")}</h1>
        <select className="filter-select" value={days} onChange={(e) => setDays(e.target.value)}>
          <option value="7">7 {t("db.rep.days")}</option>
          <option value="30">30 {t("db.rep.days")}</option>
          <option value="90">90 {t("db.rep.days")}</option>
        </select>
      </div>

      <ErrorBanner message={error} />

      <div className="stats-grid cols-3">
        <Stat label={t("db.rep.completed")} value={s.completed_orders || 0} />
        <Stat label={t("db.rep.revenue")} value={formatMoney(s.gross_revenue, shop.currency)} />
        <Stat label={t("db.rep.avg")} value={formatMoney(s.avg_order_value, shop.currency)} />
        <Stat label={t("db.rep.cancelled")} value={s.cancelled_orders || 0} warn={s.cancelled_orders > 0} />
        <Stat label={t("db.rep.rejected")} value={s.rejected_orders || 0} warn={s.rejected_orders > 0} />
        <Stat label={t("db.rep.response")} value={s.avg_response_mins > 0 ? `${Math.round(s.avg_response_mins)} min` : "—"} />
      </div>

      <div className="section-title">{t("db.rep.top")}</div>
      <div className="table-scroll">
        <table className="data-table stack-on-phone">
        <thead><tr><th>{t("th.product")}</th><th>{t("db.rep.sold")}</th><th>{t("th.revenue")}</th></tr></thead>
        <tbody>
          {(data?.topProducts || []).length === 0 && <tr><td colSpan={3} className="loader">{t("db.rep.noData")}</td></tr>}
          {(data?.topProducts || []).map((p) => (
            <tr key={p.product_name}>
              <td className="stack-title"><strong>{p.product_name}</strong></td>
              <td data-label={t("db.rep.sold")}>{p.qty}</td>
              <td data-label={t("th.revenue")}>{formatMoney(p.revenue, shop.currency)}</td>
            </tr>
          ))}
        </tbody>
      </table>
      </div>

      <div className="section-title">
        {t("db.rep.ratings")} {data?.ratings?.average != null ? `— ${data.ratings.average} / 5 (${data.ratings.count})` : ""}
      </div>
      <div className="table-scroll">
        <table className="data-table stack-on-phone">
        <thead><tr><th>#</th><th>{t("th.customer")}</th><th>{t("db.rep.rating")}</th><th>{t("th.time")}</th></tr></thead>
        <tbody>
          {(data?.ratings?.low || []).length === 0 && <tr><td colSpan={4} className="loader">{t("db.rep.noLow")}</td></tr>}
          {(data?.ratings?.low || []).map((r) => (
            <tr key={`${r.number}-${r.rated_at}`}>
              <td className="stack-title">#{r.number}</td>
              <td data-label={t("th.customer")}><span><WaLink number={r.customer_phone} /> {r.customer_name || ""}</span></td>
              <td data-label={t("db.rep.rating")}><span className="stars" aria-label={`${r.rating} / 5`}>{"★".repeat(r.rating)}<span>{"★".repeat(5 - r.rating)}</span></span></td>
              <td data-label={t("th.time")} style={{ color: "var(--text-muted)", fontSize: 12 }}>{r.rated_at ? new Date(r.rated_at).toLocaleString() : ""}</td>
            </tr>
          ))}
        </tbody>
      </table>
      </div>

      <div className="section-title">{t("db.rep.drivers")}</div>
      <div className="table-scroll">
        <table className="data-table stack-on-phone">
        <thead><tr><th>{t("th.driver")}</th><th>{t("th.deliveries")}</th><th>{t("db.rep.avgDelivery")}</th></tr></thead>
        <tbody>
          {(data?.driverStats || []).length === 0 && <tr><td colSpan={3} className="loader">{t("db.rep.noData")}</td></tr>}
          {(data?.driverStats || []).map((d) => (
            <tr key={d.name}>
              <td className="stack-title">{d.name}</td>
              <td data-label={t("th.deliveries")}>{d.deliveries}</td>
              <td data-label={t("db.rep.avgDelivery")}>{parseFloat(d.avg_delivery_mins || 0).toFixed(0)} min</td>
            </tr>
          ))}
        </tbody>
      </table>
      </div>
    </div>
  );
}

function Stat({ label, value, warn }) {
  return (
    <div className="stat-card">
      <div className="stat-label">{label}</div>
      <div className={`stat-value ${warn ? "is-bad" : ""}`}>{value}</div>
    </div>
  );
}
