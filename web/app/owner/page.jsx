"use client";

import { useCallback, useEffect, useState } from "react";
import { useOwner } from "./layout";
import { timeAgo } from "@/lib/api";
import { logActivity } from "@/lib/activity";
import { Modal, ConfirmModal, useToast, WaLink, Skeleton, OtpInput, generateOtp, WhatsAppIcon } from "@/components/ui";
import { merchantLoginEmail } from "@/lib/merchantLoginEmail";
import { PageHeader, EmptyState, ErrorBanner } from "@/components/owner/ui";
import MerchantPanel from "@/components/owner/MerchantPanel";
import WhatsAppEmbeddedSignup from "@/components/owner/WhatsAppEmbeddedSignup";
import { getPhoneCountry } from "@/lib/phoneCountries";
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
      const { merchants: m } = await api("GET", "/api/owner/merchants?limit=100");
      setMerchants(m || []);
    } catch (e) {
      loadError = e.message;
      setMerchants([]);
    }

    try {
      const { requests: r = [] } = await api("GET", "/api/owner/onboarding");
      setRequests(r);
    } catch {
      setRequests([]);
    }

    setError(loadError);
    setLoading(false);
  }, [api]);

  useEffect(() => { load(); }, [load]);

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
      toast("Join link copied!");
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }).catch(() => toast("Copy failed", "error"));
  }

  function updateMerchant(updated) {
    setMerchants((ms) => ms.map((x) => (x.id === updated.id ? updated : x)));
  }

  return (
    <div>
      <PageHeader
        title="Merchants"
        subtitle={`${merchants.length} shops on the platform · ${counts.requests} pending request${counts.requests !== 1 ? "s" : ""}`}
      >
        <button type="button" className="btn-outline-green" onClick={() => setModal({ type: "broadcast" })}>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M22 2L11 13" /><path d="M22 2L15 22l-4-9-9-4 20-7z" /></svg>
          Broadcast
        </button>
        <button type="button" className="btn-primary" onClick={copyJoinLink} style={{ display: "inline-flex", alignItems: "center", gap: 6, padding: "7px 14px", fontSize: 13 }}>
          {copied ? "✓ Copied!" : "Copy Join Link"}
        </button>
      </PageHeader>

      <div className="filter-row">
        <input type="text" className="search-input" placeholder="Search by name or WhatsApp…"
          value={search} onChange={(e) => setSearch(e.target.value)} />
        <div className="filter-pills">
          {MERCHANT_FILTERS.map((f) => (
            <button key={f.id} type="button" className={pillClass(f.tone, filter === f.id)} onClick={() => setFilter(f.id)}>
              {f.label} <span className="pill-count">{counts[f.id] ?? 0}</span>
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
            <EmptyState icon="✓" title="No pending requests" description="When merchants apply through your join link, they'll appear here for approval." actionLabel="Copy Join Link" actionClassName="btn-primary" onAction={copyJoinLink} />
          )}
          {requests.length > 0 && filteredRequests.length === 0 && (
            <EmptyState icon="🔍" title="No requests found" description="Try a different search term or clear the search box." />
          )}
          {filteredRequests.map((r) => (
            <RequestCard key={r.id} request={r} highlighted={highlightReq === r.id}
              onApprove={() => setModal({ type: "approve", request: r })}
              onReject={() => setConfirm({
                title: "Reject Request",
                message: "Reject and permanently delete this onboarding request?",
                confirmLabel: "Reject",
                danger: true,
                onConfirm: async () => {
                  try { await api("DELETE", `/api/owner/onboarding/${r.id}`); logActivity("merchant", `Join request from ${r.shop_name} rejected`); toast("Request rejected"); load(); }
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
            <EmptyState icon="🤖" title="No bot issues" description="All paying merchants have a configured WhatsApp bot." />
          )}
          {botIssues.length > 0 && filteredBotIssues.length === 0 && (
            <EmptyState icon="🔍" title="No bot issues found" description="Try a different search term or clear the search box." />
          )}
          {filteredBotIssues.map((m) => (
            <BotIssueCard key={m.id} m={m}
              onFix={() => setPanel({ id: m.id, tab: "bot" })}
              onOpen={() => setPanel({ id: m.id, tab: "info" })} />
          ))}
        </div>
      )}

      {!loading && filter !== "requests" && filter !== "bot_issues" && (
        <div className="merchant-cards">
          {list.length === 0 && (
            <EmptyState
              icon="🔍"
              title="No merchants found"
              description={search ? "Try a different search term or clear your filters." : "Share your join link — merchants apply at /join and you approve them under Requests."}
              actionLabel={!search ? "Copy Join Link" : undefined}
              actionClassName="btn-primary"
              onAction={!search ? copyJoinLink : undefined}
            />
          )}
          {list.map((m) => <MerchantCard key={m.id} m={m} onClick={() => setPanel({ id: m.id, tab: "info" })} />)}
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

      {modal?.type === "broadcast" && (
        <BroadcastModal
          recipientCount={merchants.filter((m) => m.status === "active" || m.subscription_status === "trial").length}
          onClose={() => setModal(null)}
          onSubmit={(message) => setConfirm({
            title: "Send Broadcast",
            message: `Send this message to <strong>all active merchants</strong>?<br /><br /><em style="color:var(--text-muted)">"${message}"</em>`,
            confirmLabel: "Send to All",
            onConfirm: async () => {
              try {
                await api("POST", "/api/owner/broadcast", { message });
                logActivity("broadcast", `Broadcast sent to all active merchants: "${message.slice(0, 80)}${message.length > 80 ? "…" : ""}"`);
                setModal(null);
                toast("Broadcast sent to all active merchants!");
              } catch (e) { toast(e.message, "error"); }
            },
          })}
        />
      )}

      {modal?.type === "approve" && (
        <ApproveModal
          request={modal.request}
          onClose={() => setModal(null)}
          toast={toast}
          onApproved={() => { setModal(null); load(); }}
          onSubmit={async (password, trial) => {
            const trialEndsAt = trial ? new Date(Date.now() + 7 * 86400000).toISOString().split("T")[0] : null;
            const data = await api("POST", `/api/owner/onboarding/${modal.request.id}/approve`, {
              password, subscriptionStatus: trial ? "trial" : "pending", trialEndsAt, forcePasswordChange: true,
            });
            logActivity("merchant", `${modal.request.shop_name} approved from onboarding${trial ? " — 7-day trial started" : ""}`);
            return data;
          }}
        />
      )}

      {modal?.type === "switchNumber" && (
        <SwitchNumberModal merchant={modal.merchant} api={api} onClose={() => setModal(null)} toast={toast}
          onConnected={(merchant) => {
            setModal(null);
            updateMerchant(merchant);
            toast(`Number switched for ${merchant.shop_name || modal.merchant.shop_name}`);
            logActivity("bot", `${modal.merchant.shop_name} WhatsApp number switched via Embedded Signup`);
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

function MerchantCard({ m, onClick }) {
  const initials = m.shop_name.split(" ").map((w) => w[0]).join("").slice(0, 2).toUpperCase();

  let statusPill;
  if (m.status === "suspended") {
    statusPill = <span className="pill pill-suspended">Suspended</span>;
  } else if (m.subscription_status === "trial") {
    const days = m.trial_ends_at ? Math.ceil((new Date(m.trial_ends_at) - Date.now()) / 86400000) : null;
    const expiring = days !== null && days <= 7;
    const c = expiring ? "#f59e0b" : "#3b82f6";
    statusPill = (
      <span className="pill" style={{ background: `${c}18`, color: c, border: `1px solid ${c}30` }}>
        {expiring ? "⚡ " : ""}Trial{days !== null ? ` · ${days}d` : ""}
      </span>
    );
  } else if (m.subscription_status === "pending") {
    statusPill = <span className="pill pill-pending">Pending</span>;
  } else if (merchantIsFullyActive(m)) {
    statusPill = <span className="pill pill-active">Active</span>;
  } else {
    statusPill = <span className="pill pill-active">Paid</span>;
  }

  const tokenDaysLeft = m.bot_token_expires ? Math.floor((new Date(m.bot_token_expires) - Date.now()) / 86400000) : null;
  let botColor, botLabel;
  if (m.bot_status === "error") { botColor = "var(--red)"; botLabel = "Bot Error"; }
  else if (m.bot_status === "active") {
    if (tokenDaysLeft !== null && tokenDaysLeft <= 7) { botColor = "#ff6b35"; botLabel = "⚠ Token Expiring"; }
    else if (tokenDaysLeft !== null && tokenDaysLeft <= 14) { botColor = "#f59e0b"; botLabel = "⚠ At Risk"; }
    else { botColor = "var(--green)"; botLabel = "Bot OK"; }
  } else { botColor = "#555"; botLabel = "Not set up"; }

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
        <div className="card-warn">⚠ Bot not configured</div>
      )}
    </div>
  );
}

function requestLocationSummary(request) {
  const countryName = request.country ? (getPhoneCountry(request.country)?.name ?? request.country) : null;
  return [request.street, request.city, request.region, countryName].filter(Boolean).join(", ");
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

function RequestCard({ request: r, highlighted, onApprove, onReject }) {
  const daysWaiting = Math.floor((Date.now() - new Date(r.created_at)) / 86400000);
  const waitingColor = daysWaiting >= 5 ? "var(--red)" : daysWaiting >= 2 ? "#f59e0b" : "var(--text-muted)";
  const waitingText = daysWaiting === 0 ? "Today" : daysWaiting === 1 ? "Yesterday" : `${daysWaiting}d ago`;
  const countryName = r.country ? (getPhoneCountry(r.country)?.name ?? r.country) : null;
  const locationLine = [r.city, r.region, countryName].filter(Boolean).join(", ");

  return (
    <div id={`request-${r.id}`} className={`pipeline-card pipeline-card--compact ${highlighted ? "action-highlight" : ""}`}>
      <div className="pipeline-card-top">
        <PipelineAvatar name={r.shop_name} color="#3b82f6" bg="#3b82f618" />
        <div className="pipeline-card-head">
          <div className="pipeline-card-title-row">
            <div className="pipeline-card-name">{r.shop_name}</div>
            <div className="pipeline-card-time">{timeAgo(r.created_at)}</div>
          </div>
          {r.contact_name && <div className="pipeline-card-sub">{r.contact_name}</div>}
        </div>
      </div>
      <div className="pipeline-meta">
        <RequestMetaRow label="WhatsApp"><WaLink number={r.whatsapp} /></RequestMetaRow>
        <RequestMetaRow label="Business">{r.business_type || "—"}</RequestMetaRow>
        {locationLine && <RequestMetaRow label="Location">{locationLine}</RequestMetaRow>}
        {r.street && <RequestMetaRow label="Street">{r.street}</RequestMetaRow>}
        {r.message && (
          <RequestMetaRow label="Notes" stack>
            <span className="pipeline-meta-notes">&ldquo;{r.message}&rdquo;</span>
          </RequestMetaRow>
        )}
        <RequestMetaRow label="Waiting">
          <span style={{ color: waitingColor }}>{waitingText}</span>
        </RequestMetaRow>
      </div>
      <div className="pipeline-actions" style={{ justifyContent: "space-between" }}>
        <button className="pipeline-btn" style={{ color: "var(--green)", borderColor: "#25d36640" }} onClick={onApprove}>✓ Approve</button>
        <button className="pipeline-btn" style={{ color: "var(--red)", borderColor: "#ff4d4d40" }} onClick={onReject}>✗ Reject</button>
      </div>
    </div>
  );
}

function BotIssueCard({ m, onFix, onOpen }) {
  const subStatus = m.subscription_status || "unknown";
  const subColor = subStatus === "paid" ? "var(--green)" : subStatus === "trial" ? "#8b5cf6" : "#f59e0b";
  const subLabel = subStatus === "paid" ? "Paid" : subStatus === "trial" ? "Trial" : subStatus;
  const isError = m.bot_status === "error";
  const issueMsg = isError ? (m.bot_error || "Unknown error") : "WhatsApp bot not configured yet";
  const issueColor = isError ? "#ef4444" : "#f59e0b";
  const botStatusLabel = isError ? "● Error" : "● Not set up";

  return (
    <div className="pipeline-card" style={{ borderColor: `${issueColor}30` }}>
      <div className="pipeline-card-top">
        <PipelineAvatar name={m.shop_name} color={issueColor} bg={`${issueColor}18`} />
        <div style={{ flex: 1, minWidth: 0 }}>
          <div className="pipeline-card-name">{m.shop_name}</div>
          <div className="pipeline-card-sub"><WaLink number={m.whatsapp_number} /></div>
        </div>
        <div style={{ fontSize: 10, color: "var(--text-muted)", whiteSpace: "nowrap", flexShrink: 0, marginLeft: 8 }}>{timeAgo(m.created_at)}</div>
      </div>
      <div className="pipeline-msg" style={{ borderColor: `${issueColor}30`, color: issueColor }}>⚠ {issueMsg}</div>
      <div className="pipeline-meta">
        <div className="pipeline-meta-row">
          <span className="pipeline-meta-label">Subscription</span>
          <span className="pipeline-meta-val" style={{ color: subColor, textTransform: "capitalize" }}>{subLabel}</span>
        </div>
        <div className="pipeline-meta-row">
          <span className="pipeline-meta-label">Bot status</span>
          <span className="pipeline-meta-val" style={{ color: issueColor }}>{botStatusLabel}</span>
        </div>
        <div className="pipeline-meta-row">
          <span className="pipeline-meta-label">Billing cycle</span>
          <span className="pipeline-meta-val" style={{ textTransform: "capitalize" }}>{m.billing_cycle || "monthly"}</span>
        </div>
      </div>
      <div className="pipeline-actions" style={{ justifyContent: "space-between" }}>
        <button className="pipeline-btn" style={{ color: "var(--red)", borderColor: "#ef444440" }} onClick={onFix}>🔧 Fix Bot</button>
        <button className="pipeline-btn" style={{ color: "var(--text-muted)", borderColor: "var(--border)" }} onClick={onOpen}>Open</button>
      </div>
    </div>
  );
}

/* ── Modals ─────────────────────────────────────────────── */

function BroadcastModal({ recipientCount, onClose, onSubmit }) {
  const [message, setMessage] = useState("");
  const toast = useToast();

  return (
    <Modal title="Broadcast Message" onClose={onClose}>
      <div style={{ display: "flex", alignItems: "center", gap: 10, background: "var(--green-dim)", border: "1px solid #25d36630", borderRadius: 10, padding: "12px 16px", marginBottom: 20 }}>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--green)" strokeWidth="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M23 21v-2a4 4 0 00-3-3.87" /><path d="M16 3.13a4 4 0 010 7.75" /></svg>
        <div>
          <div style={{ fontSize: 13, fontWeight: 700, color: "var(--green)" }}>Sending to {recipientCount} merchant{recipientCount !== 1 ? "s" : ""}</div>
          <div style={{ fontSize: 11, color: "var(--text-muted)", marginTop: 1 }}>All active and trial merchants</div>
        </div>
      </div>
      <div className="field">
        <label>Your Message</label>
        <textarea value={message} onChange={(e) => setMessage(e.target.value)}
          placeholder="e.g. We'll have a 2-hour maintenance window tonight at 11pm. Thank you for your patience!"
          style={{ minHeight: 130 }} />
        <div style={{ display: "flex", justifyContent: "flex-end", marginTop: 4 }}>
          <span style={{ fontSize: 11, color: "var(--text-muted)" }}>{message.length} character{message.length !== 1 ? "s" : ""}</span>
        </div>
      </div>
      {message.trim() && (
        <div style={{ background: "var(--surface2)", border: "1px solid var(--border)", borderRadius: 10, padding: 14, marginBottom: 4 }}>
          <div style={{ fontSize: 10, textTransform: "uppercase", letterSpacing: 0.8, color: "var(--text-muted)", marginBottom: 8 }}>Preview</div>
          <div style={{ fontSize: 13, lineHeight: 1.6, whiteSpace: "pre-wrap" }}>{message.trim()}</div>
        </div>
      )}
      <div className="modal-actions">
        <button className="btn-sm" onClick={onClose}>Cancel</button>
        <button className="btn-primary" onClick={() => {
          if (!message.trim()) { toast("Please enter a message", "error"); return; }
          onSubmit(message.trim());
        }}>Send to {recipientCount} Merchant{recipientCount !== 1 ? "s" : ""}</button>
      </div>
    </Modal>
  );
}

function ApproveModal({ request, onClose, onSubmit, onApproved, toast }) {
  const [otp, setOtp] = useState("");
  const [trial, setTrial] = useState(true);
  const [showDetails, setShowDetails] = useState(false);
  const [busy, setBusy] = useState(false);

  const loginEmail = merchantLoginEmail(request.shop_name);
  const locationSummary = requestLocationSummary(request);
  const countryName = request.country ? (getPhoneCountry(request.country)?.name ?? request.country) : null;

  useEffect(() => { setOtp(generateOtp()); }, []);

  function copyCode() {
    if (!otp) return;
    navigator.clipboard.writeText(otp).then(
      () => toast("Activation code copied"),
      () => toast("Could not copy code", "error"),
    );
  }

  async function handleApprove() {
    if (otp.length !== 6) {
      toast("Enter a 6-digit activation code", "error");
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
        toast(`${shop} approved — welcome sent via WhatsApp.`);
      } else {
        const opened = openWelcomeWhatsApp(
          request.whatsapp,
          shop,
          otp,
          email,
          popup,
          "welcome",
          data?.merchant?.slug,
        );
        if (opened) {
          toast(`${shop} approved — WhatsApp opened. Tap Send to deliver the login credentials.`);
        } else {
          try { popup?.close(); } catch { /* noop */ }
          toast(`${shop} approved — no WhatsApp number on file. Share the code manually.`, "error");
        }
        if (data?.welcomeError) {
          toast(`Platform WhatsApp not configured (${data.welcomeError}). Use the opened chat to send.`, "info");
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
      title={`Approve — ${request.shop_name}`}
      onClose={onClose}
      footer={(
        <div className="modal-footer-split">
          <label className="modal-footer-trial">
            <input type="checkbox" checked={trial} onChange={(e) => setTrial(e.target.checked)} />
            <div>
              <strong>{trial ? "Start 7-day trial" : "No trial"}</strong>
              <span>{trial ? "Free until trial ends" : "Subscription stays pending until paid"}</span>
            </div>
          </label>
          <div className="modal-footer-actions">
            <button type="button" className="btn-sm" onClick={onClose} disabled={busy}>Cancel</button>
            <button type="button" className="btn-primary" onClick={handleApprove} disabled={busy} style={{ display: "inline-flex", alignItems: "center", gap: 8 }}>
              <WhatsAppIcon size={16} color="currentColor" />
              {busy ? "Activating…" : "Approve & Send via WhatsApp"}
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
            {request.business_type && <span>{request.business_type}</span>}
          </div>
          {locationSummary && <div className="approve-summary-loc">{locationSummary}</div>}
          {request.message && <div className="approve-summary-notes">&ldquo;{request.message}&rdquo;</div>}
        </div>
        <button type="button" className="approve-summary-toggle" onClick={() => setShowDetails((v) => !v)}>
          {showDetails ? "Hide" : "Details"}
        </button>
      </div>

      {showDetails && (
        <div className="approve-details">
          {request.contact_name && <RequestMetaRow label="Contact">{request.contact_name}</RequestMetaRow>}
          <RequestMetaRow label="WhatsApp"><WaLink number={request.whatsapp} /></RequestMetaRow>
          <RequestMetaRow label="Business">{request.business_type || "—"}</RequestMetaRow>
          {countryName && <RequestMetaRow label="Country">{countryName}</RequestMetaRow>}
          {request.city && <RequestMetaRow label="City">{request.city}</RequestMetaRow>}
          {request.region && <RequestMetaRow label="Region">{request.region}</RequestMetaRow>}
          {request.street && <RequestMetaRow label="Street">{request.street}</RequestMetaRow>}
          {request.message && (
            <RequestMetaRow label="Notes" stack>
              <span className="pipeline-meta-notes">&ldquo;{request.message}&rdquo;</span>
            </RequestMetaRow>
          )}
        </div>
      )}

      <p className="approve-lead">
        The merchant signs in at <strong>/backoffice</strong> with the email below and this code as their first password.
      </p>

      <div className="approve-login-box">
        <div className="approve-login-row">
          <span className="approve-login-label">Login email</span>
          <code className="approve-login-value">{loginEmail || "—"}</code>
        </div>
        <div className="approve-login-row">
          <span className="approve-login-label">Backoffice</span>
          <span className="approve-login-value approve-login-muted">{typeof window !== "undefined" ? `${window.location.origin}/backoffice` : "/backoffice"}</span>
        </div>
      </div>

      <div className="approve-setup">
        <div className="modal-section-label">Activation code</div>
        <div className="modal-otp-inline">
          <OtpInput value={otp} onChange={setOtp} autoFocus />
          <button type="button" className="otp-regen" onClick={() => setOtp(generateOtp())}>New</button>
          <button type="button" className="otp-regen" onClick={copyCode} disabled={otp.length !== 6}>Copy</button>
        </div>
      </div>
    </Modal>
  );
}

function SwitchNumberModal({ merchant, api, onClose, onConnected, toast }) {
  return (
    <Modal title={`Switch WhatsApp Number — ${merchant.shop_name}`} onClose={onClose}>
      <p style={{ color: "var(--text-muted)", fontSize: 13, marginBottom: 18, lineHeight: 1.5 }}>
        Connect a new WhatsApp Business number through Meta Embedded Signup. This replaces the current (banned/disabled) number and restarts the bot.
      </p>
      <WhatsAppEmbeddedSignup
        merchantId={merchant.id}
        api={api}
        toast={toast}
        compact
        label="Connect New Number"
        reconnectLabel="Connect New Number"
        onConnected={onConnected}
      />
      <div className="modal-actions" style={{ marginTop: 16 }}>
        <button className="btn-sm" onClick={onClose}>Cancel</button>
      </div>
    </Modal>
  );
}
