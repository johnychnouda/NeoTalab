"use client";

import { useCallback, useEffect, useState } from "react";
import { useMerchant } from "../../layout";
import { useLang } from "@/lib/i18n";
import { useLiveRefresh } from "@/lib/live";
import { ConfirmModal, useToast } from "@/components/ui";
import { EmptyState, ErrorBanner, EMPTY_ICONS } from "@/components/owner/ui";

/** What the WhatsApp assistant remembers (owner "remember:" notes and rating lessons). */
export default function MemoriesPage() {
  const { api } = useMerchant();
  const { t, locale } = useLang();
  const toast = useToast();
  const [rows, setRows] = useState(null);
  const [error, setError] = useState("");
  const [confirm, setConfirm] = useState(null);

  const load = useCallback(async () => {
    try {
      setRows((await api("GET", "/memories")).memories || []);
      setError("");
    } catch (e) {
      setError(e.message);
    }
  }, [api]);

  useEffect(() => { load(); }, [load]);
  useLiveRefresh(load);

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>{t("memories.title")}</h1>
          <p className="page-header-sub">{t("memories.sub")}</p>
        </div>
        {rows?.length > 0 && (
          <button className="btn-sm btn-sm-red" onClick={() => setConfirm({ type: "clear" })}>
            {t("memories.clear")}
          </button>
        )}
      </div>

      <ErrorBanner message={error} />
      {rows === null && !error && <div className="loader">{t("db.loading")}</div>}
      {rows && rows.length === 0 && (
        <EmptyState icon={EMPTY_ICONS.memory} title={t("memories.empty")} />
      )}

      {rows && rows.length > 0 && (
        <div style={{ display: "grid", gap: 10 }}>
          {rows.map((m) => (
            <div className="driver-card" key={m.id}>
              <div className="tm-status" style={{ marginBottom: 8 }}>
                <span className="pill">{m.scope}</span>
                <span className="pill">{m.source}</span>
                {m.hits > 0 && <span className="pill">{t("memories.hits", { n: m.hits })}</span>}
              </div>
              <p style={{ margin: 0 }}>{m.content}</p>
              {(m.customer_phone || m.customer_name) && (
                <div className="mk-hint" dir="ltr" style={{ marginTop: 8 }}>
                  {m.customer_name || "—"} · {m.customer_phone}
                </div>
              )}
              {m.updated_at && (
                <div className="mk-hint" style={{ marginTop: 4 }}>
                  {new Date(m.updated_at).toLocaleString(locale)}
                </div>
              )}
              <div style={{ display: "flex", justifyContent: "flex-end", marginTop: 10 }}>
                <button className="btn-sm btn-sm-red" onClick={() => setConfirm({ type: "one", id: m.id })}>
                  {t("db.remove")}
                </button>
              </div>
            </div>
          ))}
        </div>
      )}

      {confirm?.type === "one" && (
        <ConfirmModal
          title={t("memories.delete")}
          message={t("memories.deleteConfirm")}
          danger
          onClose={() => setConfirm(null)}
          onConfirm={async () => {
            try {
              await api("DELETE", `/memories/${confirm.id}`);
              toast(t("db.saved"));
              setConfirm(null);
              load();
            } catch (e) { toast(e.message, "error"); }
          }}
        />
      )}
      {confirm?.type === "clear" && (
        <ConfirmModal
          title={t("memories.clear")}
          message={t("memories.clearConfirm")}
          danger
          onClose={() => setConfirm(null)}
          onConfirm={async () => {
            try {
              await api("DELETE", "/memories");
              toast(t("db.saved"));
              setConfirm(null);
              load();
            } catch (e) { toast(e.message, "error"); }
          }}
        />
      )}
    </div>
  );
}
