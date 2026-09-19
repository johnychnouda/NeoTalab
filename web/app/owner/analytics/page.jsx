"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { useOwner } from "../layout";
import { getRenewalDate, merchantFee } from "@/lib/api";
import {
  collectedInMonth,
  collectedInYear,
  fetchAllMerchantPayments,
  monthlyRecurringRevenue,
  paymentsInMonth,
} from "@/lib/revenue";
import { ANALYTICS_FILTERS, pillClass } from "@/lib/pillTones";
import { Skeleton } from "@/components/ui";
import { PageHeader } from "@/components/owner/ui";

function fmtMoney(n) {
  return "$" + Number(n || 0).toLocaleString("en-US");
}

function fmtRenewal(m) {
  const rd = getRenewalDate(m);
  if (!rd) return "—";
  const days = Math.ceil((rd - Date.now()) / 86400000);
  if (days < 0) return `${Math.abs(days)}d overdue`;
  if (days === 0) return "Due today";
  return `Due in ${days}d`;
}

function statusPill(m) {
  if (m.subscription_status === "paid") return { label: "Paid", color: "var(--green)" };
  if (m.subscription_status === "trial") return { label: "Trial", color: "#60a5fa" };
  return { label: "Pending", color: "var(--orange)" };
}

export default function OwnerAnalyticsPage() {
  const { api } = useOwner();
  const [merchants, setMerchants] = useState([]);
  const [payments, setPayments] = useState([]);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState("paying");

  const load = useCallback(async () => {
    try {
      const { merchants: m } = await api("GET", "/api/owner/merchants?limit=100");
      const list = m || [];
      setMerchants(list);
      const allPayments = await fetchAllMerchantPayments(api, list);
      setPayments(allPayments);
    } catch (e) {
      console.error("Analytics error", e);
    }
    setLoading(false);
  }, [api]);

  useEffect(() => { load(); }, [load]);

  const now = new Date();
  const thisMonth = now.getMonth();
  const thisYear = now.getFullYear();
  const monthName = now.toLocaleDateString("en-US", { month: "long" });

  const monthPayments = paymentsInMonth(payments, thisMonth, thisYear);
  const mrr = Math.round(monthlyRecurringRevenue(merchants));
  const revenueThisMonth = collectedInMonth(merchants, thisMonth, thisYear);
  const revenueThisYear = collectedInYear(merchants, thisYear);

  const activeShops = merchants.filter((m) => m.status === "active");
  const paying = merchants.filter((m) => m.subscription_status === "paid" && m.status === "active");
  const onTrial = activeShops.filter((m) => m.subscription_status === "trial");
  const late = merchants.filter((m) => {
    if (m.subscription_status !== "pending") return false;
    const rd = getRenewalDate(m);
    return rd && Date.now() > rd.getTime();
  });

  const pillCounts = { paying: paying.length, late: late.length, all: activeShops.length };
  const list = filter === "paying" ? paying : filter === "late" ? late : activeShops;

  if (loading) {
    return (
      <div className="analytics-page">
        <PageHeader title="Analytics" subtitle="Platform snapshot" />
        <div className="billing-stats">
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i} className="stat-card">
              <Skeleton w="55%" h={11} />
              <Skeleton w="45%" h={28} style={{ marginTop: 12 }} />
              <Skeleton w="70%" h={11} style={{ marginTop: 10 }} />
            </div>
          ))}
        </div>
        <Skeleton w="100%" h={280} br={12} />
      </div>
    );
  }

  return (
    <div className="analytics-page">
      <PageHeader
        title="Analytics"
        subtitle={`${fmtMoney(mrr)}/mo recurring · ${paying.length} paid · ${late.length} late`}
      />

      <div className="billing-stats">
        <div className="stat-card">
          <div className="stat-label">Shops on NeoTalab</div>
          <div className="stat-value">{activeShops.length}</div>
          <div className="stat-sub">{paying.length} paid · {onTrial.length} on trial</div>
        </div>
        <div className="stat-card">
          <div className="stat-label">Revenue · {monthName} {thisYear}</div>
          <div className="stat-value" style={{ color: "var(--green)" }}>{fmtMoney(revenueThisMonth)}</div>
          <div className="stat-sub">
            {monthPayments.length} payment{monthPayments.length !== 1 ? "s" : ""} recorded · MRR {fmtMoney(mrr)}
          </div>
        </div>
        <div className="stat-card">
          <div className="stat-label">Revenue · {thisYear}</div>
          <div className="stat-value" style={{ color: "#818cf8" }}>{fmtMoney(revenueThisYear)}</div>
          <div className="stat-sub">
            Based on current fees for shops paid this year
          </div>
        </div>
        <div className="stat-card">
          <div className="stat-label">Late on payment</div>
          <div className="stat-value" style={{ color: late.length ? "var(--orange)" : "var(--green)" }}>{late.length}</div>
          <div className="stat-sub">{late.length ? "Follow up on Billing" : "All caught up"}</div>
        </div>
      </div>

      <div className="analytics-toolbar">
        <div className="filter-pills">
          {ANALYTICS_FILTERS.map((p) => (
            <button key={p.id} type="button" className={pillClass(p.tone, filter === p.id)} onClick={() => setFilter(p.id)}>
              {p.label} <span className="pill-count">{pillCounts[p.id] ?? 0}</span>
            </button>
          ))}
        </div>
        <Link
          href={filter === "all" ? "/owner" : "/owner/billing"}
          className="set-link set-link-btn analytics-toolbar-link"
        >
          {filter === "all" ? "Open Merchants" : "Open Billing"} ↗
        </Link>
      </div>

      <div className="table-scroll">
<table className="data-table">
        <thead>
          <tr>
            {filter === "paying" && (
              <>
                <th>Shop</th>
                <th>Plan</th>
                <th>Fee</th>
                <th>Renewal</th>
              </>
            )}
            {filter === "late" && (
              <>
                <th>Shop</th>
                <th>Amount due</th>
                <th>Overdue</th>
              </>
            )}
            {filter === "all" && (
              <>
                <th>Shop</th>
                <th>Status</th>
                <th>City</th>
              </>
            )}
          </tr>
        </thead>
        <tbody>
          {list.length === 0 && (
            <tr>
              <td colSpan={4} className="analytics-table-empty">
                {filter === "paying" && "No paying shops yet."}
                {filter === "late" && "No overdue accounts — you're all set."}
                {filter === "all" && "No active shops yet."}
              </td>
            </tr>
          )}
          {filter === "paying" && paying.map((m) => (
            <tr key={m.id}>
              <td><strong>{m.shop_name}</strong></td>
              <td className="td-muted">{m.billing_cycle === "yearly" ? "Yearly" : "Monthly"}</td>
              <td><strong>{fmtMoney(merchantFee(m))}</strong></td>
              <td className="td-muted">{fmtRenewal(m)}</td>
            </tr>
          ))}
          {filter === "late" && late.map((m) => (
            <tr key={m.id}>
              <td><strong>{m.shop_name}</strong></td>
              <td className="td-warn"><strong>{fmtMoney(merchantFee(m))}</strong></td>
              <td className="td-danger">{fmtRenewal(m)}</td>
            </tr>
          ))}
          {filter === "all" && activeShops.map((m) => {
            const sub = statusPill(m);
            return (
              <tr key={m.id}>
                <td><strong>{m.shop_name}</strong></td>
                <td>
                  <span className="pill" style={{ color: sub.color, borderColor: `${sub.color}40`, background: `${sub.color}12` }}>
                    {sub.label}
                  </span>
                </td>
                <td className="td-muted">{m.city || "—"}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
      </div>
    </div>
  );
}
