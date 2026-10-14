"use client";

import { useCallback, useEffect, useState } from "react";
import { useOwner } from "../layout";
import { PageHeader, EmptyState, ErrorBanner, EMPTY_ICONS, InfoNote } from "@/components/owner/ui";
import { Skeleton } from "@/components/ui";
import { useLang } from "@/lib/i18n";

const usd = (n, digits) => { const v = Number(n || 0); return `$${v.toFixed(digits ?? (v > 0 && v < 1 ? 3 : 2))}`; };

function lastMonths(locale, count = 12) {
  const out = [];
  const d = new Date();
  d.setDate(1);
  for (let i = 0; i < count; i++) {
    out.push({
      value: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`,
      label: d.toLocaleDateString(locale, { month: "long", year: "numeric" }),
    });
    d.setMonth(d.getMonth() - 1);
  }
  return out;
}

/**
 * What each shop costs to run this month: the AI assistant (paid by NeoTalab) and the WhatsApp
 * messages its number sent (billed by Meta to the shop; the first 1,000 a month are free).
 */
export default function UsagePage() {
  const { api } = useOwner();
  const { t: tr, locale } = useLang();
  const months = lastMonths(locale);
  const [month, setMonth] = useState(months[0].value);
  const [data, setData] = useState(null);
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    setData(null);
    setError("");
    try {
      setData(await api("GET", `/admin/usage?month=${month}`));
    } catch (e) {
      setError(e.message);
    }
  }, [api, month]);

  useEffect(() => { load(); }, [load]);

  const rows = (data?.rows || []).filter((r) => r.orders || r.ai_requests || r.wa_messages || r.wa_templates);
  const t = data?.totals || {};

  return (
    <div>
      <PageHeader title={tr("ow.nav.usage")} subtitle={tr("ow.u.sub")}>
        <select className="mode-select" value={month} onChange={(e) => setMonth(e.target.value)} aria-label={tr("ow.u.month")}>
          {months.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
        </select>
      </PageHeader>

      <ErrorBanner message={error} />

      <div className="stats-grid">
        <div className="stat-card"><div className="stat-label">{tr("ow.u.aiCost")}</div><div className="stat-value">{data ? usd(t.ai_cost) : "…"}</div></div>
        <div className="stat-card"><div className="stat-label">{tr("th.orders")}</div><div className="stat-value">{data ? t.orders : "…"}</div></div>
        <div className="stat-card"><div className="stat-label">{tr("ow.u.aiPerOrder")}</div><div className="stat-value">{data && t.orders ? usd(t.ai_cost / t.orders, 3) : "—"}</div></div>
        <div className="stat-card"><div className="stat-label">{tr("ow.u.metaBills")}</div><div className="stat-value">{data ? usd(t.meta_cost) : "…"}</div></div>
      </div>

      {!data && !error && <Skeleton h={180} br={12} />}

      {data && rows.length === 0 && (
        <EmptyState icon={EMPTY_ICONS.chart} title={tr("ow.u.none")} description={tr("ow.u.noneDesc")} />
      )}

      {rows.length > 0 && (
        <div className="table-scroll">
          <table className="data-table stack-on-phone">
            <thead>
              <tr>
                <th>{tr("ow.b.shop")}</th>
                <th>{tr("th.orders")}</th>
                <th>{tr("ow.u.aiReplies")}</th>
                <th>{tr("ow.u.aiCostCol")}</th>
                <th>{tr("ow.u.aiPerOrder")}</th>
                <th>{tr("ow.u.cache")}</th>
                <th>{tr("ow.u.waSent")}</th>
                <th>{tr("ow.u.freeLeft")}</th>
                <th>{tr("ow.u.metaBill")}</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.merchant_id}>
                  <td className="stack-title"><strong>{r.shop}</strong></td>
                  <td data-label={tr("th.orders")}>{r.orders}</td>
                  <td data-label={tr("ow.u.aiReplies")}>{r.ai_requests}</td>
                  <td data-label={tr("ow.u.aiCostCol")}>{usd(r.ai_cost)}</td>
                  <td data-label={tr("ow.u.aiPerOrder")}>{r.ai_cost_per_order != null ? usd(r.ai_cost_per_order, 3) : "—"}</td>
                  <td data-label={tr("ow.u.cache")}>{Math.round((r.ai_cached_share || 0) * 100)}%</td>
                  <td data-label={tr("ow.u.waSent")}>{r.wa_messages + r.wa_templates}</td>
                  <td data-label={tr("ow.u.freeLeft")}>{r.wa_free_left}</td>
                  <td data-label={tr("ow.u.metaBill")}>{usd(r.meta_cost)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <InfoNote>{tr("ow.u.note")}</InfoNote>
    </div>
  );
}
