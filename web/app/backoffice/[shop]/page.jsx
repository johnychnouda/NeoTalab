"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { useMerchant } from "../layout";
import { useLang } from "@/lib/i18n";
import { useLiveRefresh } from "@/lib/live";
import { ErrorBanner } from "@/components/owner/ui";
import { formatMoney } from "@/lib/money";
import { company } from "@/lib/company";
import { daysUntil } from "@/lib/trial";
import { useToast } from "@/components/ui";
import WhatsAppEmbeddedSignup from "@/components/owner/WhatsAppEmbeddedSignup";

/** Where each setup step is done in the dashboard (WhatsApp is connected by NeoTalab). */
const WHERE = {
  menu: "menu", hours: "settings?tab=hours", zones: "settings?tab=areas",
  pickup: "settings?tab=orders", team: "drivers", whish: "settings?tab=payments", report: "settings?tab=alerts",
};

/**
 * Dashboard home: the shop's setup checklist. Orders themselves are handled on WhatsApp.
 */
export default function ShopHome() {
  const { api, shopSlug, shopName, shop } = useMerchant();
  const { t, locale } = useLang();
  const toast = useToast();
  const [setup, setSetup] = useState(null);
  const [paused, setPaused] = useState([]);
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    try {
      const [setupRes, pausedRes] = await Promise.all([
        api("GET", "/settings/setup"),
        api("GET", "/customers/paused").catch(() => ({ customers: [] })),
      ]);
      setSetup(setupRes.setup);
      setPaused(pausedRes.customers || []);
    } catch (e) { setError(e.message); }
  }, [api]);

  useEffect(() => { load(); }, [load]);
  useLiveRefresh(load);

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
          <p className={`gs-wa ${setup.whatsapp_connected ? "ok" : ""}`}>
            {setup.whatsapp_connected
              ? <>✅ {t("db.home.waConnected")}{setup.whatsapp_number && <> · <span dir="ltr">{setup.whatsapp_number}</span></>}</>
              : <>⏳ {t("db.home.waPending")}</>}
          </p>
        </div>
      </section>

      {!setup.whatsapp_connected && (
        <section className="gs-connect">
          <h2>{t("db.step.whatsapp")}</h2>
          <p>{t("db.step.whatsapp.desc")}</p>
          <WhatsAppEmbeddedSignup
            api={api}
            toast={toast}
            endpoint="/settings/whatsapp/embedded-signup"
            configEndpoint="/settings/whatsapp/embedded-signup/config"
            label={t("db.wa.connectBtn")}
            buttonId="wa-embedded-signup-shop"
            quiet
            onConnected={load}
          />
        </section>
      )}

      {paused.length > 0 && (
        <section className="gs-banner" style={{ marginBottom: 16 }}>
          <div>
            <strong>{t("customers.pausedTitle", { n: paused.length })}</strong>
            <p className="mk-hint" style={{ margin: "4px 0 8px" }}>{t("customers.pausedHint")}</p>
            <Link className="btn-sm" href={`/backoffice/${shopSlug}/customers`}>{t("customers.open")}</Link>
          </div>
        </section>
      )}

      <Plan sub={shop.subscription} shopName={shopName} t={t} locale={locale} />
      <MetaFees url={setup.meta_payment_url} t={t} />

      {done === setup.steps.length ? (
        // Everything is done: the steps fold into one line, still one click away for edits.
        <details className="set-advanced gs-folded">
          <summary><span>{t("db.home.stepsDone")}</span></summary>
          <ol className="gs-list">{setup.steps.map(row)}</ol>
        </details>
      ) : (
        <>
          <h2 className="gs-h">{t("db.home.required")}</h2>
          <ol className="gs-list">{required.map(row)}</ol>

          <h2 className="gs-h">{t("db.home.optional")}</h2>
          <ol className="gs-list">{optional.map(row)}</ol>
        </>
      )}
    </div>
  );
}

/** Meta bills the shop's own WhatsApp account for a few messages: straight to its payment page. */
function MetaFees({ url, t }) {
  if (!url) return null;
  return (
    <section className="gs-plan">
      <div>
        <span className="gs-plan-label">{t("db.fees.title")}</span>
        <p>{t("db.fees.line")}</p>
      </div>
      <a className="btn-sm" href={url} target="_blank" rel="noopener noreferrer">{t("db.fees.pay")}</a>
    </section>
  );
}

/** The shop's NeoTalab subscription: trial days left, next payment, or payment due. */
function Plan({ sub, shopName, t, locale }) {
  if (!sub) return null;
  const date = (iso) => new Date(iso).toLocaleDateString(locale, { day: "numeric", month: "long" });
  const daysLeft = sub.renews_at ? daysUntil(sub.renews_at) : null;
  const fee = formatMoney(sub.fee);
  const per = t(sub.billing_cycle === "yearly" ? "db.sub.year" : "db.sub.month");
  const priced = Number(sub.fee) > 0;
  // A free plan has nothing to pay, whatever its renewal date says.
  const due = sub.status === "pending" || (daysLeft !== null && daysLeft < 0 && (priced || sub.status === "trial"));
  const trial = !due && sub.status === "trial";

  const title = due ? t("db.sub.due")
    : trial ? (daysLeft <= 1 ? t("db.sub.trialLast") : t("db.sub.trial", { n: daysLeft }))
    : t("db.sub.paid");
  const line = due ? t("db.sub.dueDesc") : [
    sub.renews_at && daysLeft >= 0 && t(trial ? "db.sub.trialEnds" : "db.sub.renews", { date: date(sub.renews_at) }),
    priced && t(trial ? "db.sub.then" : "db.sub.price", { fee, per }),
  ].filter(Boolean).join(" ");
  const pay = company.supportWhatsapp
    ? `https://wa.me/${company.supportWhatsapp.replace(/\D/g, "")}?text=${encodeURIComponent(t("db.help.msg", { shop: shopName || "" }))}`
    : "";

  return (
    <section className={`gs-plan ${due ? "due" : trial && daysLeft <= 2 ? "soon" : ""}`}>
      <div>
        <span className="gs-plan-label">{t("db.sub.title")}</span>
        <strong>{title}</strong>
        {line && <p>{line}</p>}
      </div>
      {pay && (due || trial) && (
        <a className={due ? "btn-primary" : "btn-sm"} href={pay} target="_blank" rel="noopener noreferrer">{t("db.sub.pay")}</a>
      )}
    </section>
  );
}
