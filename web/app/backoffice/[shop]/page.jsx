"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { useMerchant } from "../layout";
import { useLang } from "@/lib/i18n";
import { ErrorBanner } from "@/components/owner/ui";

/** Where each setup step is done in the dashboard (WhatsApp is connected by NeoTalab). */
const WHERE = {
  business: "settings", menu: "menu", hours: "settings?tab=hours", zones: "settings?tab=areas",
  pickup: "settings?tab=orders", team: "drivers", whish: "settings?tab=payments", report: "settings?tab=alerts",
};

/**
 * Dashboard home: the shop's setup checklist. Orders themselves are handled on WhatsApp.
 */
export default function ShopHome() {
  const { api, shopSlug, shopName } = useMerchant();
  const { t } = useLang();
  const [setup, setSetup] = useState(null);
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    try { setSetup((await api("GET", "/settings/setup")).setup); } catch (e) { setError(e.message); }
  }, [api]);

  useEffect(() => { load(); }, [load]);

  if (error) return <ErrorBanner message={error} />;
  if (!setup) return <div className="loader">{t("db.loading")}</div>;

  const required = setup.steps.filter((s) => s.required);
  const optional = setup.steps.filter((s) => !s.required);
  const done = setup.steps.filter((s) => s.done).length;

  const row = (step) => (
    <li key={step.key} className={`gs-step ${step.done ? "done" : ""}`}>
      <span className="gs-tick" aria-hidden="true">{step.done && <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round"><path d="m5 12 5 5 9-10" /></svg>}</span>
      <div className="gs-text">
        <strong>{t(`db.step.${step.key}`)}</strong>
        <span>{t(`db.step.${step.key}.desc`)}</span>
      </div>
      {WHERE[step.key] && (
        <Link className={step.done ? "btn-sm" : "btn-primary gs-go"} href={`/backoffice/${shopSlug}/${WHERE[step.key]}`}>
          {step.done ? t("mk.edit") : t("db.open")}
        </Link>
      )}
    </li>
  );

  return (
    <div className="gs">
      <div className="page-header">
        <div>
          <h1>{shopName || t("db.home.title")}</h1>
          <p className="page-header-sub">{t("db.home.title")}</p>
        </div>
      </div>

      <section className={`gs-banner ${setup.ready ? "ok" : ""}`}>
        <div className="gs-ring" style={{ "--p": `${Math.round((done / setup.steps.length) * 100)}%` }}>
          <span>{done}/{setup.steps.length}</span>
        </div>
        <div>
          <strong>{setup.ready ? t("db.home.ready") : t("db.home.notReady")}</strong>
          <p>{t("db.home.waNumber")}: <span dir="ltr">{setup.whatsapp_number || t("db.home.notConnected")}</span>
            {" · "}{t("db.team.driver")}: {setup.drivers} · {t("db.team.staff")}: {setup.staff}</p>
        </div>
      </section>

      <h2 className="gs-h">{t("db.home.required")}</h2>
      <ol className="gs-list">{required.map(row)}</ol>

      <h2 className="gs-h">{t("db.home.optional")}</h2>
      <ol className="gs-list">{optional.map(row)}</ol>
    </div>
  );
}
