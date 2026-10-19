"use client";

import { useCallback, useEffect, useState } from "react";
import { useOwner } from "./layout";
import { timeAgo } from "@/lib/api";
import { trialDaysLeft } from "@/lib/trial";
import { logActivity } from "@/lib/activity";
import { Modal, ConfirmModal, useToast, WaLink, Skeleton, OtpInput, generateOtp, WhatsAppIcon } from "@/components/ui";
import { merchantLoginEmail } from "@/lib/merchantLoginEmail";
import { PageHeader, EmptyState, ErrorBanner, EMPTY_ICONS } from "@/components/owner/ui";
import MerchantPanel from "@/components/owner/MerchantPanel";
import WhatsAppEmbeddedSignup from "@/components/owner/WhatsAppEmbeddedSignup";
import { useLang } from "@/lib/i18n";
import { useLiveRefresh } from "@/lib/live";
import { mapPin } from "@/components/LocationPicker";
import { joinOptionLabel } from "@/lib/joinFormOptions";
import { MERCHANT_FILTERS, pillClass } from "@/lib/pillTones";
import { openWelcomeWhatsApp } from "@/lib/welcomeWhatsApp";

function matchesMerchantSearch(m, term) {
  return m.shop_name?.toLowerCase().includes(term)
    || (m.whatsapp_number || "").includes(term)
    || m.bot_error?.toLowerCase().includes(term);
}

function matchesRequestSearch(r, term) {
  return r.shop_name?.toLowerCase().includes(term)
    || r.contact_name?.toLowerCase().includes(term)
    || (r.whatsapp || "").includes(term)
    || r.business_type?.toLowerCase().includes(term)
    || r.city?.toLowerCase().includes(term)
    || r.region?.toLowerCase().includes(term)
    || r.street?.toLowerCase().includes(term)
    || (r.country || "").toLowerCase().includes(term)
    || r.message?.toLowerCase().includes(term);
}

export default function MerchantsPage() {
  const { api } = useOwner();
  const { t } = useLang();
  const toast = useToast();

  const [merchants, setMerchants] = useState([]);
  const [requests, setRequests] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [search, setSearch] = useState("");
  const [filter, setFilter] = useState("");
  const [panel, setPanel] = useState(null); // { id, tab }
  const [modal, setModal] = useState(null);
  const [confirm, setConfirm] = useState(null);
  const [copied, setCopied] = useState(false);
  const [highlightReq, setHighlightReq] = useState(null);

  const load = useCallback(async () => {
    let loadError = "";
    try {
      const { merchants: m } = await api("GET", "/admin/merchants");
      setMerchants(m || []);
    } catch (e) {
      loadError = e.message;
    }

    try {
      const { requests: r = [] } = await api("GET", "/admin/onboarding");
      setRequests(r);
    } catch (e) {
      if (!loadError) loadError = e.message;
    }

    setError(loadError);
    setLoading(false);
  }, [api]);

  useEffect(() => { load(); }, [load]);
  useLiveRefresh(load);

  // Deep-link: ?m=<id>&tab=bot|billing|info, ?filter=requests, ?req=<requestId>
  useEffect(() => {
    const h = (e) => {
      const detail = e.detail;
      if (typeof detail === "object" && detail?.id) {
        setPanel({ id: detail.id, tab: detail.tab || "info" });
      } else {
        setPanel({ id: detail, tab: "info" });
      }
    };
    window.addEventListener("nt-open-merchant", h);
    return () => window.removeEventListener("nt-open-merchant", h);
  }, []);

  useEffect(() => {
    if (!merchants.length && !requests.length) return;
    const params = new URLSearchParams(window.location.search);
    const mid = params.get("m");
    const tab = params.get("tab") || "info";
    const reqId = params.get("req");
    const filterParam = params.get("filter");

    if (filterParam === "requests" || reqId) setFilter("requests");
    if (mid && merchants.some((x) => x.id === mid)) {
      setPanel({ id: mid, tab: ["info", "billing", "bot"].includes(tab) ? tab : "info" });
    }
    if (reqId) {
      setHighlightReq(reqId);
      setTimeout(() => {
        document.getElementById(`request-${reqId}`)?.scrollIntoView({ behavior: "smooth", block: "center" });
      }, 150);
      setTimeout(() => setHighlightReq(null), 2500);
    }

    if (mid || reqId || filterParam) {
      window.history.replaceState({}, "", "/owner");
    }
  }, [merchants, requests]);

  const counts = {
    requests: requests.length,
    "": merchants.length,
    active: merchants.filter((m) => merchantIsFullyActive(m)).length,
    trial: merchants.filter((m) => m.subscription_status === "trial" && m.bot_status !== "error").length,
    pending: merchants.filter((m) => m.subscription_status === "pending").length,
    suspended: merchants.filter((m) => m.status === "suspended").length,
    bot_issues: merchants.filter((m) => merchantHasBotIssue(m)).length,
  };

  let list = merchants;
  const searchTerm = search.toLowerCase().trim();
  if (searchTerm) {
    list = list.filter((m) => matchesMerchantSearch(m, searchTerm));
  }
  if (filter === "active") list = list.filter((m) => merchantIsFullyActive(m));
  else if (filter === "trial") list = list.filter((m) => m.subscription_status === "trial" && m.bot_status !== "error");
  else if (filter === "pending") list = list.filter((m) => m.subscription_status === "pending");
  else if (filter === "suspended") list = list.filter((m) => m.status === "suspended");

  list = [...list].sort((a, b) => (a.shop_name || "").localeCompare(b.shop_name || ""));

  const filteredRequests = searchTerm
    ? requests.filter((r) => matchesRequestSearch(r, searchTerm))
    : requests;
  const botIssues = merchants.filter((m) => merchantHasBotIssue(m));
  const filteredBotIssues = searchTerm
    ? botIssues.filter((m) => matchesMerchantSearch(m, searchTerm))
    : botIssues;

  const panelMerchant = panel ? merchants.find((m) => m.id === panel.id) : null;

  function copyJoinLink() {
    const url = window.location.origin + "/join";
    navigator.clipboard.writeText(url).then(() => {
      toast(t("ow.m.linkCopied"));
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }).catch(() => toast(t("ow.copyFailed"), "error"));
  }

  function updateMerchant(updated) {
    setMerchants((ms) => ms.map((x) => (x.id === updated.id ? updated : x)));
  }

  return (
    <div>
      <PageHeader
        title={t("ow.nav.merchants")}
        subtitle={t("ow.m.sub", { n: merchants.length, shops: merchants.length, reqs: counts.requests })}
      >
        <button type="button" className="btn-primary" onClick={copyJoinLink} style={{ display: "inline-flex", alignItems: "center", gap: 6, padding: "7px 14px", fontSize: 13 }}>
          {copied ? `✓ ${t("ow.copied")}` : t("ow.m.copyLink")}
        </button>
      </PageHeader>

      <div className="filter-row">
        <input type="text" className="search-input" placeholder={t("ow.m.search")}
          value={search} onChange={(e) => setSearch(e.target.value)} />
        <div className="filter-pills">
          {MERCHANT_FILTERS.map((f) => (
            <button key={f.id} type="button" className={pillClass(f.tone, filter === f.id)} onClick={() => setFilter(f.id)}>
              {t(f.key)} <span className="pill-count">{counts[f.id] ?? 0}</span>
            </button>
          ))}
        </div>
      </div>

      <ErrorBanner message={error} />
      {loading && (
        <div className="merchant-cards">
          {Array.from({ length: 8 }).map((_, i) => (
            <div key={i} className="merchant-card" style={{ cursor: "default", pointerEvents: "none" }}>
              <Skeleton w={44} h={44} br={10} />
              <Skeleton w="75%" h={15} style={{ marginTop: 14 }} />
              <Skeleton w="55%" h={12} style={{ marginTop: 8 }} />
              <Skeleton w={72} h={20} br={20} style={{ marginTop: 14 }} />
            </div>
          ))}
        </div>
      )}

      {!loading && filter === "requests" && (
        <div className="merchant-cards pipeline-mode">
          {requests.length === 0 && (
            <EmptyState icon={EMPTY_ICONS.check} title={t("ow.m.noReqs")} description={t("ow.m.noReqsDesc")} />
          )}
          {requests.length > 0 && filteredRequests.length === 0 && (
            <EmptyState icon={EMPTY_ICONS.search} title={t("ow.noResults")} description={t("ow.noResultsDesc")} />
          )}
          {filteredRequests.map((r) => (
            <RequestCard key={r.id} t={t} request={r} highlighted={highlightReq === r.id}
              onApprove={() => setModal({ type: "approve", request: r })}
              onReject={() => setConfirm({
                title: t("ow.m.rejectTitle"),
                message: <>{t("ow.m.rejectMsg")} <strong>{r.shop_name}</strong></>,
                confirmLabel: t("ow.m.reject"),
                danger: true,
                onConfirm: async () => {
                  try { await api("DELETE", `/admin/onboarding/${r.id}`); logActivity("merchant", t("ow.log.rejected", { shop: r.shop_name })); toast(t("ow.m.rejected")); load(); }
                  catch (e) { toast(e.message, "error"); }
                },
              })}
            />
          ))}
        </div>
      )}

      {!loading && filter === "bot_issues" && (
        <div className="merchant-cards pipeline-mode">
          {botIssues.length === 0 && (
            <EmptyState icon={EMPTY_ICONS.check} title={t("ow.m.noWaIssues")} description={t("ow.m.noWaIssuesDesc")} />
          )}
          {botIssues.length > 0 && filteredBotIssues.length === 0 && (
            <EmptyState icon={EMPTY_ICONS.search} title={t("ow.noResults")} description={t("ow.noResultsDesc")} />
          )}
          {filteredBotIssues.map((m) => (
            <BotIssueCard key={m.id} t={t} m={m}
              onFix={() => setPanel({ id: m.id, tab: "bot" })}
              onOpen={() => setPanel({ id: m.id, tab: "info" })} />
          ))}
        </div>
      )}

      {!loading && filter !== "requests" && filter !== "bot_issues" && (
        <div className="merchant-cards">
          {list.length === 0 && (
            <EmptyState
              icon={EMPTY_ICONS.search}
              title={search ? t("ow.noResults") : t("ow.m.none")}
              description={search ? t("ow.noResultsDesc") : t("ow.m.noneDesc")}
            />
          )}
          {list.map((m) => <MerchantCard key={m.id} t={t} m={m} onClick={() => setPanel({ id: m.id, tab: "info" })} />)}
        </div>
      )}

      {panelMerchant && (
        <MerchantPanel
          merchant={panelMerchant}
          initialTab={panel.tab}
          api={api}
          onClose={() => setPanel(null)}
          onChanged={(updated) => { if (updated) updateMerchant(updated); else load(); }}
          onConfirm={setConfirm}
          onModal={setModal}
        />
      )}

      {modal?.type === "approve" && (
        <ApproveModal
          request={modal.request}
          onClose={() => setModal(null)}
          toast={toast}
          onApproved={() => { setModal(null); load(); }}
          onSubmit={async (password, trial) => {
            const data = await api("POST", `/admin/onboarding/${modal.request.id}/approve`, {
              // The trial length comes from Settings → Pricing (applied by the server).
              password, subscriptionStatus: trial ? "trial" : "pending", forcePasswordChange: true,
            });
            logActivity("merchant", t(trial ? "ow.log.approvedTrial" : "ow.log.approved", { shop: modal.request.shop_name }));
            return data;
          }}
        />
      )}

      {modal?.type === "switchNumber" && (
        <SwitchNumberModal merchant={modal.merchant} api={api} onClose={() => setModal(null)} toast={toast}
          onConnected={(merchant) => {
            setModal(null);
            updateMerchant(merchant);
            toast(t("ow.m.numberSwitched", { shop: merchant.shop_name || modal.merchant.shop_name }));
            logActivity("bot", t("ow.log.numberSwitched", { shop: modal.merchant.shop_name }));
          }} />
      )}

      {confirm && (
        <ConfirmModal {...confirm} onClose={() => setConfirm(null)} />
      )}
    </div>
  );
}

/* ── Cards ──────────────────────────────────────────────── */

function merchantIsFullyActive(m) {
  return m.status === "active"
    && m.subscription_status === "paid"
    && m.bot_status === "active";
}

function merchantNeedsBotSetup(m) {
  if (m.status === "suspended") return false;
  if (m.subscription_status !== "paid" && m.subscription_status !== "trial") return false;
  return m.bot_status !== "active";
}

function merchantHasBotIssue(m) {
  return m.bot_status === "error" || merchantNeedsBotSetup(m);
}

function MerchantCard({ t, m, onClick }) {
  const initials = m.shop_name.split(" ").map((w) => w[0]).join("").slice(0, 2).toUpperCase();

  let statusPill;
  if (m.status === "suspended") {
    statusPill = <span className="pill pill-suspended">{t("ow.f.suspended")}</span>;
  } else if (m.subscription_status === "trial") {
    const days = trialDaysLeft(m.trial_ends_at);
    const expiring = days !== null && days <= 7;
    const c = expiring ? "#f59e0b" : "#3b82f6";
    statusPill = (
      <span className="pill" style={{ background: `${c}18`, color: c, border: `1px solid ${c}30` }}>
        {expiring ? "⚡ " : ""}{days !== null ? t("ow.trialDaysShort", { n: Math.max(0, days) }) : t("ow.f.trial")}
      </span>
    );
  } else if (m.subscription_status === "pending") {
    statusPill = <span className="pill pill-pending">{t("ow.f.pending")}</span>;
  } else if (merchantIsFullyActive(m)) {
    statusPill = <span className="pill pill-active">{t("ow.f.active")}</span>;
  } else {
    statusPill = <span className="pill pill-active">{t("ow.f.paid")}</span>;
  }

  const tokenDaysLeft = m.bot_token_expires ? Math.floor((new Date(m.bot_token_expires) - Date.now()) / 86400000) : null;
  let botColor, botLabel;
  if (m.bot_status === "error") { botColor = "var(--red)"; botLabel = t("ow.wa.error"); }
  else if (m.bot_status === "active") {
    if (tokenDaysLeft !== null && tokenDaysLeft <= 14) { botColor = "#f59e0b"; botLabel = `⚠ ${t("ow.wa.reconnectSoon")}`; }
    else { botColor = "var(--green)"; botLabel = t("ow.wa.ok"); }
  } else { botColor = "#555"; botLabel = t("ow.wa.notSet"); }

  const showBot = m.status !== "suspended" && (m.subscription_status === "paid" || m.subscription_status === "trial");
  const noBotWarning = merchantNeedsBotSetup(m);

  return (
    <div className="merchant-card" onClick={onClick}>
      <div className="card-avatar" style={{ width: 44, height: 44, fontSize: 18, marginBottom: 12 }}>{initials}</div>
      <div className="card-name" style={{ marginBottom: 2 }}>{m.shop_name}</div>
      <div style={{ marginBottom: 12 }}>
        <WaLink number={m.whatsapp_number} />
      </div>
      <div className="card-footer">
        {statusPill}
        {showBot && (
          <span className="bot-indicator" style={{ color: botColor }}>
            <span className="bot-dot" style={{ background: botColor }} />
            {botLabel}
          </span>
        )}
      </div>
      {noBotWarning && (
        <div className="card-warn">⚠ {t("ow.wa.notConnected")}</div>
      )}
    </div>
  );
}

function requestMapsHref(request) {
  const pin = mapPin(request.location);
  return pin ? `https://www.google.com/maps?q=${pin}` : "";
}

function requestLocationSummary(t, request) {
  if (requestMapsHref(request)) return "";
  return [request.street, request.city, joinOptionLabel(t, request.region)].filter(Boolean).join(", ");
}

function PipelineAvatar({ name, color, bg }) {
  const initials = name.split(" ").map((w) => w[0]).join("").slice(0, 2).toUpperCase();
  return <div className="pipeline-avatar" style={{ background: bg, color }}>{initials}</div>;
}

function RequestMetaRow({ label, children, stack }) {
  return (
    <div className={`pipeline-meta-row${stack ? " pipeline-meta-row--stack" : ""}`}>
      <span className="pipeline-meta-label">{label}</span>
      <span className="pipeline-meta-val">{children}</span>
    </div>
  );
}

function RequestCard({ t, request: r, highlighted, onApprove, onReject }) {
  const { locale } = useLang();
  const daysWaiting = Math.floor((Date.now() - new Date(r.created_at)) / 86400000);
  const waitingColor = daysWaiting >= 5 ? "var(--red)" : daysWaiting >= 2 ? "#f59e0b" : "var(--text-muted)";
  const maps = requestMapsHref(r);
  const locationLine = [r.city, joinOptionLabel(t, r.region)].filter(Boolean).join(", ");

  return (
    <div id={`request-${r.id}`} className={`pipeline-card pipeline-card--compact ${highlighted ? "action-highlight" : ""}`}>
      <div className="pipeline-card-top">
        <PipelineAvatar name={r.shop_name} color="#3b82f6" bg="#3b82f618" />
        <div className="pipeline-card-head">
          <div className="pipeline-card-title-row">
            <div className="pipeline-card-name">{r.shop_name}</div>
            <div className="pipeline-card-time" style={{ color: waitingColor }}>{timeAgo(r.created_at, locale)}</div>
          </div>
          {r.contact_name && <div className="pipeline-card-sub">{r.contact_name}</div>}
        </div>
      </div>
      <div className="pipeline-meta">
        <RequestMetaRow label="WhatsApp"><WaLink number={r.whatsapp} /></RequestMetaRow>
        <RequestMetaRow label={t("ow.m.business")}>{joinOptionLabel(t, r.business_type) || "—"}</RequestMetaRow>
        {maps
          ? <RequestMetaRow label={t("ow.m.location")}><a href={maps} target="_blank" rel="noopener noreferrer">{t("db.zone.maps")}</a></RequestMetaRow>
          : locationLine && <RequestMetaRow label={t("ow.m.location")}>{locationLine}</RequestMetaRow>}
        {r.street && !maps && <RequestMetaRow label={t("ow.m.street")}>{r.street}</RequestMetaRow>}
        {r.message && (
          <RequestMetaRow label={t("ow.m.notes")} stack>
            <span className="pipeline-meta-notes">&ldquo;{r.message}&rdquo;</span>
          </RequestMetaRow>
        )}
      </div>
      <div className="pipeline-actions" style={{ justifyContent: "space-between" }}>
        <button className="pipeline-btn" style={{ color: "var(--green)", borderColor: "#25d36640" }} onClick={onApprove}>✓ {t("ow.m.approve")}</button>
        <button className="pipeline-btn" style={{ color: "var(--red)", borderColor: "#ff4d4d40" }} onClick={onReject}>✗ {t("ow.m.reject")}</button>
      </div>
    </div>
  );
}

function BotIssueCard({ t, m, onFix, onOpen }) {
  const { locale } = useLang();
  const subStatus = m.subscription_status || "pending";
  const subColor = subStatus === "paid" ? "var(--green)" : subStatus === "trial" ? "#8b5cf6" : "#f59e0b";
  const subLabel = t(subStatus === "paid" ? "ow.f.paid" : subStatus === "trial" ? "ow.f.trial" : "ow.f.pending");
  const isError = m.bot_status === "error";
  const issueMsg = isError ? (m.bot_error || t("ow.wa.unknownError")) : t("ow.wa.notConnected");
  const issueColor = isError ? "#ef4444" : "#f59e0b";
  const botStatusLabel = `● ${t(isError ? "ow.wa.error" : "ow.wa.notSet")}`;

  return (
    <div className="pipeline-card" style={{ borderColor: `${issueColor}30` }}>
      <div className="pipeline-card-top">
        <PipelineAvatar name={m.shop_name} color={issueColor} bg={`${issueColor}18`} />
        <div style={{ flex: 1, minWidth: 0 }}>
          <div className="pipeline-card-name">{m.shop_name}</div>
          <div className="pipeline-card-sub"><WaLink number={m.whatsapp_number} /></div>
        </div>
        <div style={{ fontSize: 10, color: "var(--text-muted)", whiteSpace: "nowrap", flexShrink: 0, marginLeft: 8 }}>{timeAgo(m.created_at, locale)}</div>
      </div>
      <div className="pipeline-msg" style={{ borderColor: `${issueColor}30`, color: issueColor }}>⚠ {issueMsg}</div>
      <div className="pipeline-meta">
        <div className="pipeline-meta-row">
          <span className="pipeline-meta-label">{t("ow.m.subscription")}</span>
          <span className="pipeline-meta-val" style={{ color: subColor }}>{subLabel}</span>
        </div>
        <div className="pipeline-meta-row">
          <span className="pipeline-meta-label">WhatsApp</span>
          <span className="pipeline-meta-val" style={{ color: issueColor }}>{botStatusLabel}</span>
        </div>
      </div>
      <div className="pipeline-actions" style={{ justifyContent: "space-between" }}>
        <button className="pipeline-btn" style={{ color: "var(--red)", borderColor: "#ef444440" }} onClick={onFix}>🔧 {t("ow.wa.fix")}</button>
        <button className="pipeline-btn" style={{ color: "var(--text-muted)", borderColor: "var(--border)" }} onClick={onOpen}>{t("ow.open")}</button>
      </div>
    </div>
  );
}

/* ── Modals ─────────────────────────────────────────────── */

function ApproveModal({ request, onClose, onSubmit, onApproved, toast }) {
  const { t } = useLang();
  const [otp, setOtp] = useState("");
  const [trial, setTrial] = useState(true);
  const [showDetails, setShowDetails] = useState(false);
  const [busy, setBusy] = useState(false);

  const loginEmail = merchantLoginEmail(request.shop_name);
  const locationSummary = requestLocationSummary(t, request);

  useEffect(() => { setOtp(generateOtp()); }, []);

  function copyCode() {
    if (!otp) return;
    navigator.clipboard.writeText(otp).then(
      () => toast(t("ow.ap.codeCopied")),
      () => toast(t("ow.copyFailed"), "error"),
    );
  }

  async function handleApprove() {
    if (otp.length !== 6) {
      toast(t("ow.ap.codeNeeded"), "error");
      return;
    }

    // Open a blank tab during the click (before await) so popup blockers don't block WhatsApp.
    const popup = window.open("about:blank", "_blank");
    setBusy(true);
    try {
      const data = await onSubmit(otp, trial);
      const email = data?.user?.email || loginEmail;
      const shop = request.shop_name;

      if (data?.welcomeSent) {
        try { popup?.close(); } catch { /* noop */ }
        toast(t("ow.ap.sent", { shop }));
      } else {
        const opened = openWelcomeWhatsApp(
          t,
          request.whatsapp,
          shop,
          otp,
          email,
          popup,
          "welcome",
          data?.merchant?.slug,
        );
        if (opened) {
          toast(t("ow.ap.opened", { shop }));
        } else {
          try { popup?.close(); } catch { /* noop */ }
          toast(t("ow.ap.noNumber", { shop }), "error");
        }
        if (data?.welcomeError) {
          toast(t("ow.ap.platformWaOff"), "info");
        }
      }
      onApproved?.();
    } catch (e) {
      try { popup?.close(); } catch { /* noop */ }
      toast(e.message, "error");
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      compact
      title={t("ow.ap.title", { shop: request.shop_name })}
      onClose={onClose}
      footer={(
        <div className="modal-footer-split">
          <label className="modal-footer-trial">
            <input type="checkbox" checked={trial} onChange={(e) => setTrial(e.target.checked)} />
            <div>
              <strong>{trial ? t("ow.ap.trial") : t("ow.ap.noTrial")}</strong>
              <span>{trial ? t("ow.ap.trialHint") : t("ow.ap.noTrialHint")}</span>
            </div>
          </label>
          <div className="modal-footer-actions">
            <button type="button" className="btn-sm" onClick={onClose} disabled={busy}>{t("db.cancel")}</button>
            <button type="button" className="btn-primary" onClick={handleApprove} disabled={busy} style={{ display: "inline-flex", alignItems: "center", gap: 8 }}>
              <WhatsAppIcon size={16} color="currentColor" />
              {busy ? t("ow.ap.busy") : t("ow.ap.btn")}
            </button>
          </div>
        </div>
      )}
    >
      <div className="approve-summary">
        <div className="approve-summary-main">
          <div className="approve-summary-contact">{request.contact_name || "—"}</div>
          <div className="approve-summary-meta">
            <WaLink number={request.whatsapp} />
            {request.business_type && <span>{joinOptionLabel(t, request.business_type)}</span>}
          </div>
          {requestMapsHref(request)
            ? <div className="approve-summary-loc"><a href={requestMapsHref(request)} target="_blank" rel="noopener noreferrer">{t("db.zone.maps")}</a></div>
            : locationSummary && <div className="approve-summary-loc">{locationSummary}</div>}
          {request.message && <div className="approve-summary-notes">&ldquo;{request.message}&rdquo;</div>}
        </div>
        <button type="button" className="approve-summary-toggle" onClick={() => setShowDetails((v) => !v)}>
          {showDetails ? t("ow.hide") : t("ow.details")}
        </button>
      </div>

      {showDetails && (
        <div className="approve-details">
          {request.contact_name && <RequestMetaRow label={t("ow.m.contact")}>{request.contact_name}</RequestMetaRow>}
          <RequestMetaRow label="WhatsApp"><WaLink number={request.whatsapp} /></RequestMetaRow>
          <RequestMetaRow label={t("ow.m.business")}>{joinOptionLabel(t, request.business_type) || "—"}</RequestMetaRow>
          {request.city && <RequestMetaRow label={t("ow.m.city")}>{request.city}</RequestMetaRow>}
          {request.region && <RequestMetaRow label={t("ow.m.region")}>{joinOptionLabel(t, request.region)}</RequestMetaRow>}
          {request.street && <RequestMetaRow label={t("ow.m.street")}>{request.street}</RequestMetaRow>}
          {request.message && (
            <RequestMetaRow label={t("ow.m.notes")} stack>
              <span className="pipeline-meta-notes">&ldquo;{request.message}&rdquo;</span>
            </RequestMetaRow>
          )}
        </div>
      )}

      <p className="approve-lead">{t("ow.ap.lead")}</p>

      <div className="approve-login-box">
        <div className="approve-login-row">
          <span className="approve-login-label">{t("ow.ap.email")}</span>
          <code className="approve-login-value">{loginEmail || "—"}</code>
        </div>
        <div className="approve-login-row">
          <span className="approve-login-label">{t("ow.ap.dashboard")}</span>
          <span className="approve-login-value approve-login-muted">{typeof window !== "undefined" ? `${window.location.origin}/backoffice` : "/backoffice"}</span>
        </div>
      </div>

      <div className="approve-setup">
        <div className="modal-section-label">{t("ow.ap.code")}</div>
        <div className="modal-otp-inline">
          <OtpInput value={otp} onChange={setOtp} autoFocus />
          <button type="button" className="otp-regen" onClick={() => setOtp(generateOtp())}>{t("ow.ap.newCode")}</button>
          <button type="button" className="otp-regen" onClick={copyCode} disabled={otp.length !== 6}>{t("ow.copy")}</button>
        </div>
      </div>
    </Modal>
  );
}

function SwitchNumberModal({ merchant, api, onClose, onConnected, toast }) {
  const { t } = useLang();
  return (
    <Modal title={t("ow.sw.title", { shop: merchant.shop_name })} onClose={onClose}>
      <p style={{ color: "var(--text-muted)", fontSize: 13, marginBottom: 18, lineHeight: 1.5 }}>{t("ow.sw.desc")}</p>
      <WhatsAppEmbeddedSignup
        merchantId={merchant.id}
        api={api}
        toast={toast}
        compact
        label={t("ow.sw.btn")}
        reconnectLabel={t("ow.sw.btn")}
        onConnected={onConnected}
      />
      <div className="modal-actions" style={{ marginTop: 16 }}>
        <button className="btn-sm" onClick={onClose}>{t("db.cancel")}</button>
      </div>
    </Modal>
  );
}
