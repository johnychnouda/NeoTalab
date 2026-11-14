"use client";

import { useCallback, useEffect, useState } from "react";
import { useMerchant } from "../../layout";
import { useLang } from "@/lib/i18n";
import { useLiveRefresh } from "@/lib/live";
import { ConfirmModal, useToast } from "@/components/ui";
import { EmptyState, ErrorBanner, EMPTY_ICONS } from "@/components/owner/ui";
import Segmented from "@/components/Segmented";

/**
 * Customers who messaged the shop: block spam, resume paused bots, anonymize for privacy,
 * and review Bad/Okay rating notes.
 */
export default function CustomersPage() {
  const { api } = useMerchant();
  const { t, locale } = useLang();
  const toast = useToast();

  const [tab, setTab] = useState("all");
  const [search, setSearch] = useState("");
  const [rows, setRows] = useState(null);
  const [paused, setPaused] = useState([]);
  const [low, setLow] = useState([]);
  const [error, setError] = useState("");
  const [confirm, setConfirm] = useState(null);

  const load = useCallback(async () => {
    try {
      const blocked = tab === "blocked" ? true : tab === "all" ? null : undefined;
      const q = new URLSearchParams();
      if (search.trim()) q.set("search", search.trim());
      if (blocked === true) q.set("blocked", "1");
      if (blocked === false) q.set("blocked", "0");
      q.set("limit", "100");
      const [{ customers }, pausedRes, analytics] = await Promise.all([
        api("GET", `/customers?${q}`),
        api("GET", "/customers/paused").catch(() => ({ customers: [] })),
        api("GET", "/analytics/dashboard?days=90").catch(() => null),
      ]);
      setRows(customers || []);
      setPaused(pausedRes.customers || []);
      setLow(analytics?.ratings?.low || []);
      setError("");
    } catch (e) {
      setError(e.message);
    }
  }, [api, search, tab]);

  useEffect(() => { load(); }, [load]);
  useLiveRefresh(load);

  const act = async (fn, ok) => {
    try { await fn(); toast(ok); setConfirm(null); load(); }
    catch (e) { toast(e.message, "error"); }
  };

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>{t("customers.title")}</h1>
          <p className="page-header-sub">{t("customers.sub")}</p>
        </div>
      </div>

      <ErrorBanner message={error} />

      {paused.length > 0 && (
        <section className="gs-banner" style={{ marginBottom: 16 }}>
          <div>
            <strong>{t("customers.pausedTitle", { n: paused.length })}</strong>
            <p className="mk-hint" style={{ margin: "4px 0 0" }}>{t("customers.pausedHint")}</p>
          </div>
        </section>
      )}

      <div className="dash-toolbar">
      <Segmented
        value={tab}
        onChange={setTab}
        aria-label={t("customers.title")}
        options={[
          { value: "all", label: t("customers.tabAll") },
          { value: "blocked", label: t("customers.tabBlocked") },
          { value: "feedback", label: t("customers.tabFeedback") },
        ]}
      />

      {tab !== "feedback" && (
        <input
          type="search"
          className="search-input cust-search"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder={t("customers.search")}
          aria-label={t("customers.search")}
        />
      )}
      </div>

      {rows === null && !error && <div className="loader">{t("db.loading")}</div>}

      {tab === "feedback" && (
        <div style={{ marginTop: 16 }}>
          {low.length === 0 && <EmptyState icon={EMPTY_ICONS.check} title={t("customers.feedbackEmpty")} />}
          {low.map((r) => (
            <div className="driver-card" key={`${r.number}-${r.rated_at}`} style={{ marginBottom: 10 }}>
              <div className="driver-name">#{r.number} · {r.rating}/5</div>
              <div className="mk-hint" dir="ltr">{r.customer_name || "—"} · {r.customer_phone}</div>
              {r.issue && <p style={{ margin: "8px 0 0" }}>{r.issue}</p>}
              {r.rated_at && (
                <div className="mk-hint" style={{ marginTop: 6 }}>
                  {new Date(r.rated_at).toLocaleString(locale)}
                </div>
              )}
            </div>
          ))}
        </div>
      )}

      {tab !== "feedback" && rows && rows.length === 0 && (
        <EmptyState icon={EMPTY_ICONS.team} title={t("customers.empty")} description={search ? undefined : t("customers.emptyDesc")} />
      )}

      {tab !== "feedback" && rows && rows.length > 0 && (
        <div className="drivers-grid" style={{ marginTop: 16 }}>
          {rows.map((c) => {
            const isPaused = paused.some((p) => p.id === c.id);
            return (
              <div className="driver-card" key={c.id}>
                <div className="driver-name">{c.name || t("customers.unnamed")}</div>
                <div className="mk-hint" dir="ltr">{c.phone}</div>
                <div className="tm-status" style={{ marginTop: 8 }}>
                  {c.is_blocked && <span className="pill pill-suspended">{t("customers.blocked")}</span>}
                  {isPaused && <span className="pill pill-pending">{t("customers.paused")}</span>}
                  <span className="pill">{c.total_orders || 0} {t("customers.orders")}</span>
                </div>
                <div style={{ display: "flex", flexWrap: "wrap", gap: 6, marginTop: 10, justifyContent: "flex-end" }}>
                  {isPaused && (
                    <button className="btn-sm" onClick={() => act(() => api("POST", `/customers/${c.id}/resume`), t("customers.resumed"))}>
                      {t("customers.resume")}
                    </button>
                  )}
                  {c.is_blocked ? (
                    <button className="btn-sm" onClick={() => act(() => api("POST", `/customers/${c.id}/unblock`), t("customers.unblocked"))}>
                      {t("customers.unblock")}
                    </button>
                  ) : (
                    <button className="btn-sm btn-sm-red" onClick={() => setConfirm({ type: "block", customer: c })}>
                      {t("customers.block")}
                    </button>
                  )}
                  <button className="btn-sm" onClick={() => setConfirm({ type: "anon", customer: c })}>
                    {t("customers.anonymize")}
                  </button>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {confirm?.type === "block" && (
        <ConfirmModal
          title={t("customers.block")}
          message={<>{t("customers.blockConfirm")} <strong dir="ltr">{confirm.customer.phone}</strong></>}
          danger
          onClose={() => setConfirm(null)}
          onConfirm={() => act(() => api("POST", `/customers/${confirm.customer.id}/block`, { reason: "blocked by shop" }), t("customers.blockedOk"))}
        />
      )}
      {confirm?.type === "anon" && (
        <ConfirmModal
          title={t("customers.anonymize")}
          message={t("customers.anonymizeConfirm")}
          danger
          onClose={() => setConfirm(null)}
          onConfirm={() => act(() => api("POST", `/customers/${confirm.customer.id}/anonymize`), t("customers.anonymized"))}
        />
      )}
    </div>
  );
}
