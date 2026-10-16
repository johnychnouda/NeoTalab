"use client";

import { useCallback, useEffect, useState } from "react";
import { useMerchant } from "../../layout";
import { WaLink } from "@/components/ui";
import { EmptyState, ErrorBanner, EMPTY_ICONS } from "@/components/owner/ui";
import { useLang } from "@/lib/i18n";
import { useLiveRefresh } from "@/lib/live";

const STATUS = { booked: "pill-active", completed: "pill-cancelled", no_show: "pill-pending", cancelled: "pill-suspended" };

// Read-only schedule: bookings are made and managed on WhatsApp.
export default function AppointmentsPage() {
  const { api } = useMerchant();
  const { t, lang } = useLang();
  const [data, setData] = useState(null);
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    try { setData(await api("GET", "/appointments")); } catch (e) { setError(e.message); }
  }, [api]);

  useEffect(() => { load(); }, [load]);
  useLiveRefresh(load);

  const byDay = (data?.appointments || []).reduce((acc, a) => {
    const day = a.starts_at.slice(0, 10);
    (acc[day] = acc[day] || []).push(a);
    return acc;
  }, {});

  return (
    <div>
      <div className="page-header">
        <h1>{t("db.book.title")}</h1>
        <button className="btn-sm" onClick={load}>↻ {t("db.refresh")}</button>
      </div>
      <p className="page-intro">{t("db.book.hint")}</p>
      <ErrorBanner message={error} />
      {data === null && !error && <div className="loader">{t("db.loading")}</div>}
      {data && Object.keys(byDay).length === 0 && <EmptyState icon={EMPTY_ICONS.calendar} title={t("db.book.empty")} />}
      {Object.entries(byDay).map(([day, list]) => (
        <div key={day} style={{ marginBottom: 22 }}>
          <div className="section-title">{new Date(`${day}T12:00:00`).toLocaleDateString(lang === "ar" ? "ar-LB" : lang === "fr" ? "fr-FR" : "en-US", { weekday: "long", day: "numeric", month: "short" })}</div>
          <div className="table-scroll">
            <table className="data-table stack-on-phone">
              <thead><tr><th>{t("db.book.time")}</th><th>{t("db.book.service")}</th><th>{t("th.customer")}</th><th>{t("db.book.price")}</th><th>{t("th.status")}</th></tr></thead>
              <tbody>
                {list.map((a) => (
                  <tr key={a.id}>
                    <td className="stack-title" style={{ whiteSpace: "nowrap" }}>{a.starts_at.slice(11)}–{a.ends_at}</td>
                    <td data-label={t("db.book.service")}>{a.service}</td>
                    <td data-label={t("th.customer")}><span><WaLink number={a.customer_phone} /> {a.customer_name || ""}</span></td>
                    <td data-label={t("db.book.price")}>{a.price.toFixed(2)} {data.currency}</td>
                    <td data-label={t("th.status")}><span className={`pill ${STATUS[a.status] || ""}`}>{t(`db.book.${a.status}`)}</span></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      ))}
    </div>
  );
}
