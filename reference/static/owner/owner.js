/**
 * NeoTalab Owner Dashboard
 */

const API = "http://localhost:8787";
let token = localStorage.getItem("nt_owner_token");
let activeMerchantFilter = "";
let _merchants = [];

const WA_LOGO = `<svg width="16" height="16" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;flex-shrink:0"><path fill="#25D366" d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>`;

// ── API ────────────────────────────────────────────────────
async function api(method, path, body) {
  const opts = { method, headers: { "Content-Type": "application/json" } };
  if (token && token !== "demo_owner") opts.headers["Authorization"] = `Bearer ${token}`;
  if (body) opts.body = JSON.stringify(body);
  try {
    const r = await fetch(API + path, opts);
    const data = await r.json();
    if (!r.ok) throw new Error(data.error || "Request failed");
    return data;
  } catch (e) {
    if (e.message.includes("fetch") || e.message.includes("Failed") || e.message.includes("NetworkError") || e.message.includes("Load failed")) {
      return getDemoData(method, path);
    }
    throw e;
  }
}

// ── Demo data ──────────────────────────────────────────────
function getDemoData(method, path) {
  if (path.includes("/auth/owner/login"))
    return { token: "demo_owner", user: { name: "Johny" } };

  if (path.includes("/owner/stats"))
    return { stats: { activeMerchants: 5, ordersLast30Days: 329, mrr: 205, pendingOnboarding: 2, overdueAccounts: 2 } };

  if (path.includes("/owner/merchants"))
    return { merchants: [
      { id:"1", shop_name:"Joe's Snacks",       email:"joe@joes.com",      whatsapp_number:"+9613001001", business_type:"Restaurant / Café",   street:"Hamra Street",       city:"Beirut",   region:"Beirut",         created_at:"2024-03-15", status:"active",    plan:"basic", monthly_fee:29, yearly_fee:290, billing_cycle:"monthly", total_revenue:1840, last_order_at:new Date(Date.now()-3600000).toISOString(),    last_payment_at:"2026-06-01", subscription_status:"paid",    total_fees_collected:294, bot_status:"active",  bot_mode:"auto",   bot_phone_id:"112233445566", bot_token:"EAAxxxxx", bot_token_expires:"2026-08-01", bot_last_checked:new Date(Date.now()-600000).toISOString(),  bot_error:null, bot_recent:[{dir:"in",from:"+9613999001",text:"Hi, what are your hours?",at:new Date(Date.now()-900000).toISOString()},{dir:"out",to:"+9613999001",text:"We're open 9am–11pm daily!",at:new Date(Date.now()-899000).toISOString()},{dir:"in",from:"+9613999002",text:"Can I order a burger?",at:new Date(Date.now()-1800000).toISOString()},{dir:"out",to:"+9613999002",text:"Sure! Here's our menu 🍔",at:new Date(Date.now()-1799000).toISOString()}] },
      { id:"2", shop_name:"Beirut Burgers",     email:"info@bb.com",       whatsapp_number:"+9613002002", business_type:"Restaurant / Café",   street:"Verdun Road",        city:"Beirut",   region:"Beirut",         created_at:"2024-07-01", status:"active",    plan:"basic", monthly_fee:29, yearly_fee:290, billing_cycle:"monthly", total_revenue:1210, last_order_at:new Date(Date.now()-7200000).toISOString(),    last_payment_at:"2026-05-10", subscription_status:"pending", total_fees_collected:116, bot_status:"error",   bot_mode:"auto",   bot_phone_id:"223344556677", bot_token:"EAAyyyyy", bot_token_expires:"2026-07-15", bot_last_checked:new Date(Date.now()-300000).toISOString(),  bot_error:"Token expired — Meta returned 401 Unauthorized. Update the access token to restore the bot.", bot_recent:[] },
      { id:"3", shop_name:"Marina Sweets",      email:"marina@sweets.com", whatsapp_number:"+9613003003", business_type:"Bakery / Sweets",     street:"Jal El Dib Highway", city:"Jounieh",  region:"Mount Lebanon",  created_at:"2024-01-20", status:"suspended", plan:"basic", monthly_fee:29, yearly_fee:290, billing_cycle:"yearly",  total_revenue:580,  last_order_at:new Date(Date.now()-864000000).toISOString(), last_payment_at:"2025-04-01", subscription_status:"pending", total_fees_collected:87,  bot_status:"error",   bot_mode:"manual", bot_phone_id:"334455667788", bot_token:"",         bot_token_expires:null,         bot_last_checked:new Date(Date.now()-3600000).toISOString(), bot_error:"Webhook not responding. No messages received in 24h.", bot_recent:[] },
      { id:"4", shop_name:"Zara Fashion",       email:"zara@fashion.com",  whatsapp_number:"+9613004004", business_type:"Boutique / Clothing", street:"ABC Mall, Floor 2",  city:"Beirut",   region:"Beirut",         created_at:"2025-02-10", status:"active",    plan:"basic", monthly_fee:29, yearly_fee:290, billing_cycle:"monthly", total_revenue:420,  last_order_at:new Date(Date.now()-86400000).toISOString(),  last_payment_at:new Date(Date.now()-25*86400000).toISOString().split("T")[0], subscription_status:"paid",    total_fees_collected:58,  bot_status:"error",  bot_mode:"auto",   bot_phone_id:"445566778899", bot_token:"EAAzzzzz", bot_token_expires:"2026-09-10", bot_error:"Webhook not responding — no messages received in 24h. Check your server or restart the bot.", bot_last_checked:new Date(Date.now()-1200000).toISOString(), bot_recent:[{dir:"in",from:"+9613888001",text:"Do you have size M?",at:new Date(Date.now()-3600000).toISOString()},{dir:"out",to:"+9613888001",text:"Yes! Available in black and white.",at:new Date(Date.now()-3599000).toISOString()}] },
      { id:"5", shop_name:"Al Reef Restaurant", email:"alreef@rest.com",   whatsapp_number:"+9613005005", business_type:"Restaurant / Café",   street:"Marina Street",      city:"Jounieh",  region:"Mount Lebanon",  created_at:"2024-11-05", status:"active",    plan:"basic", monthly_fee:29, yearly_fee:290, billing_cycle:"monthly", total_revenue:980,  last_order_at:new Date(Date.now()-1800000).toISOString(),   last_payment_at:"2026-06-10", subscription_status:"paid",    total_fees_collected:147, bot_status:"active",  bot_mode:"manual",   bot_phone_id:"556677889900", bot_token:"EAAaaaaaa",bot_token_expires:new Date(Date.now()+5*86400000).toISOString().split("T")[0], bot_last_checked:new Date(Date.now()-120000).toISOString(),  bot_error:null, bot_recent:[{dir:"in",from:"+9613777001",text:"Table for 4 tonight?",at:new Date(Date.now()-1800000).toISOString()},{dir:"out",to:"+9613777001",text:"Sorry we're busy right now, call us at 01234567",at:new Date(Date.now()-1799000).toISOString()}] },
      { id:"7", shop_name:"Cedar Coffee",       email:"cedar@coffee.com",  whatsapp_number:"+9613007007", business_type:"Restaurant / Café",   street:"Gemmayze Street",    city:"Beirut",   region:"Beirut",         created_at:"2026-06-20", status:"active",    plan:"basic", monthly_fee:29, yearly_fee:290, billing_cycle:"monthly", total_revenue:0,    last_order_at:null, last_payment_at:null, subscription_status:"trial", trial_ends_at: new Date(Date.now()+4*86400000).toISOString().split("T")[0], total_fees_collected:0, bot_status:"active", bot_mode:"auto", bot_phone_id:"778899001122", bot_token:"EAAcccccc", bot_token_expires:"2026-12-15", bot_last_checked:new Date(Date.now()-1800000).toISOString(), bot_error:null, bot_recent:[] },
      { id:"6", shop_name:"Lara's Boutique",    email:"lara@boutique.com", whatsapp_number:"+9613006006", business_type:"Boutique / Clothing", street:"Kaslik Main Road",   city:"Jounieh",  region:"Mount Lebanon",  created_at:"2025-05-18", status:"active",    plan:"basic", monthly_fee:29, yearly_fee:290, billing_cycle:"yearly",  total_revenue:310,  last_order_at:new Date(Date.now()-172800000).toISOString(), last_payment_at:"2026-06-12", subscription_status:"paid",    total_fees_collected:290, bot_status:"active",  bot_mode:"auto",   bot_phone_id:"667788990011",  bot_token:"EAAbbbbbb", bot_token_expires:"2026-12-01", bot_last_checked:new Date(Date.now()-600000).toISOString(),  bot_error:null, bot_recent:[{dir:"in",from:"+9613555001",text:"Do you have the new summer collection?",at:new Date(Date.now()-172800000).toISOString()},{dir:"out",to:"+9613555001",text:"Yes! Just arrived. Come visit us or check our catalog.",at:new Date(Date.now()-172799000).toISOString()}] },
    ]};

  if (path.includes("/owner/onboarding"))
    return { requests: [
      { id:"r1", shop_name:"Sunset Grill",  contact_name:"Ahmad Khalil", whatsapp:"+9613100100", street:"Hamra Street, Block 3", city:"Beirut",  region:"Beirut",        business_type:"Restaurant / Café",   message:"We take orders manually on WhatsApp — it's chaotic. We need NeoTalab!", created_at: new Date(Date.now()-86400000).toISOString() },
      { id:"r2", shop_name:"Nour Boutique", contact_name:"Nour Kassem",  whatsapp:"+9613200200", street:"Jounieh Highway, ABC", city:"Jounieh", region:"Mount Lebanon",  business_type:"Boutique / Clothing", message:"We sell clothes online and need automated ordering.", created_at: new Date(Date.now()-172800000).toISOString() },
    ]};

  if (path.includes("/owner/analytics"))
    return {
      orderTrend: Array.from({length:30},(_,i)=>({ date:new Date(Date.now()-(29-i)*86400000).toISOString().split("T")[0], orders:Math.floor(8+Math.random()*15), revenue:(Math.random()*200+100).toFixed(2) })),
      feeTrend: [
        {month:"Jan",collected:58},{month:"Feb",collected:87},{month:"Mar",collected:87},
        {month:"Apr",collected:116},{month:"May",collected:145},{month:"Jun",collected:127},
      ],
      merchantsByStatus: [{status:"active",count:"5"},{status:"suspended",count:"1"},{status:"pending",count:"2"}],
      subscription: { mrr:205, collected_this_month:127, overdue_count:2, churn_count:1 },
    };

  if (path.includes("/owner/settings"))
    return { settings: { subscriptionPrice:29, subscriptionYearlyPrice:290, trialDays:7, ownerName:"Johny", platformName:"NeoTalab", waPhoneId:"", waToken:"", waVerifyToken:"" } };

  return { success: true };
}

// ── Toast ──────────────────────────────────────────────────
function toast(message, type = "success") {
  const el = document.createElement("div");
  el.className = `toast ${type}`;
  el.innerHTML = `<span>${type === "success" ? "✅" : "❌"}</span> ${message}`;
  document.getElementById("toast-container").appendChild(el);
  requestAnimationFrame(() => { requestAnimationFrame(() => el.classList.add("show")); });
  setTimeout(() => {
    el.classList.remove("show");
    setTimeout(() => el.remove(), 350);
  }, 3000);
}

// ── Auth ───────────────────────────────────────────────────
document.getElementById("login-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const btn = document.getElementById("login-btn");
  const errEl = document.getElementById("login-error");
  btn.textContent = "Signing in…";
  btn.disabled = true;
  errEl.style.display = "none";
  try {
    const data = await api("POST", "/auth/owner/login", {
      email: document.getElementById("login-email").value,
      password: document.getElementById("login-password").value,
    });
    token = data.token || "demo_owner";
    localStorage.setItem("nt_owner_token", token);
    const ownerName = data.user?.name || "Owner";
    localStorage.setItem("nt_owner_name", ownerName);
    document.getElementById("owner-name").textContent = ownerName;
    showApp("merchants");
  } catch (e) {
    errEl.textContent = e.message;
    errEl.style.display = "block";
    btn.textContent = "Sign in";
    btn.disabled = false;
  }
});

document.getElementById("logout-btn").addEventListener("click", () => {
  token = null;
  localStorage.removeItem("nt_owner_token");
  document.getElementById("app").style.display = "none";
  document.getElementById("login-screen").style.display = "flex";
});

function showApp(page = "merchants") {
  document.getElementById("login-screen").style.display = "none";
  document.getElementById("app").style.display = "flex";
  navigateTo(page);
}

if (token) {
  document.getElementById("owner-name").textContent = localStorage.getItem("nt_owner_name") || "Owner";
  const savedPage = localStorage.getItem("nt_owner_page") || "merchants";
  showApp(savedPage);
}

// ── Navigation ─────────────────────────────────────────────
document.querySelectorAll(".nav-item").forEach(item => {
  item.addEventListener("click", e => { e.preventDefault(); navigateTo(item.dataset.page); });
});

function navigateTo(page) {
  document.querySelectorAll(".page").forEach(p => p.classList.remove("active"));
  document.querySelectorAll(".nav-item").forEach(n => n.classList.remove("active"));
  document.getElementById(`page-${page}`)?.classList.add("active");
  document.querySelector(`[data-page="${page}"]`)?.classList.add("active");
  localStorage.setItem("nt_owner_page", page);
  if (page === "merchants") loadMerchants();
  if (page === "billing")   loadBilling();
  if (page === "analytics") loadAnalytics();
  if (page === "settings")  loadSettings();
}


// ── Merchants ──────────────────────────────────────────────
document.getElementById("merchant-search").addEventListener("input", () => loadMerchants());
document.querySelectorAll(".pill-btn[data-filter]").forEach(btn => {
  btn.addEventListener("click", () => {
    activeMerchantFilter = btn.dataset.filter;
    document.querySelectorAll(".pill-btn[data-filter]").forEach(b => {
      const isActive = b.dataset.filter === activeMerchantFilter;
      b.classList.toggle("active", isActive);
      const badge = b.querySelector("span");
      if (badge) badge.style.background = isActive ? "rgba(255,255,255,0.2)" : "var(--surface2)";
    });
    loadMerchants();
  });
});

async function loadMerchants() {
  const search = document.getElementById("merchant-search").value.toLowerCase();
  const el = document.getElementById("merchant-cards");
  el.innerHTML = `<div class="loader">Loading…</div>`;
  try {
    const [{ merchants }, { requests = [] }] = await Promise.all([
      api("GET", "/api/owner/merchants?limit=100"),
      api("GET", "/api/owner/onboarding"),
    ]);
    _merchants = merchants;

    // Counts for pills
    const counts = {
      "requests":   requests.length,
      "":           merchants.length,
      "active":     merchants.filter(m => m.status === "active" && m.subscription_status === "paid" && m.bot_status !== "error").length,
      "trial":      merchants.filter(m => m.subscription_status === "trial" && m.bot_status !== "error").length,
      "pending":    merchants.filter(m => m.subscription_status === "pending").length,
      "suspended":  merchants.filter(m => m.status === "suspended").length,
      "bot_issues": merchants.filter(m => m.bot_status === "error").length,
    };

    // Update pill counts
    document.querySelectorAll(".pill-btn[data-filter]").forEach(btn => {
      const f = btn.dataset.filter;
      const count = counts[f] ?? 0;
      let label = btn.dataset.label || btn.textContent.replace(/\d+/g, "").trim();
      btn.dataset.label = label;
      const isReqs = f === "requests";
      const isBots = f === "bot_issues";
      const accentColor = isReqs ? "#3b82f6" : isBots ? "#ef4444" : null;
      const badgeBg = activeMerchantFilter === f ? "rgba(255,255,255,0.2)" : accentColor ? `${accentColor}22` : "var(--surface2)";
      const badgeColor = activeMerchantFilter === f ? "" : accentColor && count > 0 ? accentColor : "";
      btn.innerHTML = `${label} <span style="
        display:inline-flex;align-items:center;justify-content:center;
        min-width:18px;height:18px;padding:0 5px;
        background:${badgeBg};border-radius:9px;font-size:10px;font-weight:700;margin-left:5px;
        ${badgeColor ? `color:${badgeColor}` : ""}
      ">${count}</span>`;
    });

    // ── Requests view ─────────────────────────────────────────
    if (activeMerchantFilter === "requests") {
      el.classList.add("pipeline-mode");
      if (!requests.length) {
        el.innerHTML = `<div class="empty"><div class="empty-icon">✓</div><div class="empty-text">No pending requests</div></div>`;
        return;
      }
      const now = Date.now();
      el.innerHTML = requests.map(r => renderPipelineApplied(r, now)).join("");
      return;
    }

    el.classList.remove("pipeline-mode");

    // ── Bot Issues view ───────────────────────────────────────
    if (activeMerchantFilter === "bot_issues") {
      el.classList.add("pipeline-mode");
      const botList = merchants.filter(m => m.bot_status === "error");
      if (!botList.length) {
        el.innerHTML = `<div class="empty"><div class="empty-icon">✓</div><div class="empty-text">No bot issues</div></div>`;
        return;
      }
      const now = Date.now();
      el.innerHTML = botList.map(m => renderPipelineBotIssue(m, now)).join("");
      return;
    }

    el.classList.remove("pipeline-mode");

    // ── Regular merchant filtering ────────────────────────────
    let list = merchants;
    if (search) list = list.filter(m => m.shop_name?.toLowerCase().includes(search) || m.whatsapp_number?.includes(search));
    if      (activeMerchantFilter === "active")    list = list.filter(m => m.status === "active" && m.subscription_status === "paid" && m.bot_status !== "error");
    else if (activeMerchantFilter === "trial")     list = list.filter(m => m.subscription_status === "trial" && m.bot_status !== "error");
    else if (activeMerchantFilter === "pending")   list = list.filter(m => m.subscription_status === "pending");
    else if (activeMerchantFilter === "suspended") list = list.filter(m => m.status === "suspended");

    // Sort
    const sort = document.getElementById("merchant-sort")?.value || "name";
    list.sort((a, b) => {
      if (sort === "revenue")     return (b.total_revenue || 0) - (a.total_revenue || 0);
      if (sort === "last_active") return new Date(b.last_order_at || 0) - new Date(a.last_order_at || 0);
      if (sort === "status") {
        const order = { paid: 0, trial: 1, pending: 2, suspended: 3 };
        return (order[a.subscription_status] ?? 9) - (order[b.subscription_status] ?? 9);
      }
      return (a.shop_name || "").localeCompare(b.shop_name || "");
    });

    if (!list.length) { el.innerHTML = `<div class="empty"><div class="empty-icon">🔍</div><div class="empty-text">No merchants found</div></div>`; return; }
    el.innerHTML = list.map(m => renderMerchantCard(m)).join("");
  } catch (e) { el.innerHTML = `<div style="color:var(--red);padding:20px">${e.message}</div>`; }
}

function renderMerchantCard(m) {
  const initials = m.shop_name.split(" ").map(w=>w[0]).join("").slice(0,2).toUpperCase();

  // Status pill
  let statusPill;
  if (m.status === "suspended") {
    statusPill = `<span class="pill pill-suspended">Suspended</span>`;
  } else if (m.subscription_status === "trial") {
    const days = m.trial_ends_at ? Math.ceil((new Date(m.trial_ends_at) - Date.now()) / 86400000) : null;
    const expiring = days !== null && days <= 7;
    const pillColor = expiring ? "#f59e0b" : "#3b82f6";
    const pillBg    = expiring ? "#f59e0b18" : "#3b82f618";
    const pillBorder= expiring ? "#f59e0b30" : "#3b82f630";
    statusPill = `<span class="pill" style="background:${pillBg};color:${pillColor};border:1px solid ${pillBorder}">
      ${expiring ? "⚡ " : ""}Trial${days !== null ? ` · ${days}d` : ""}
    </span>`;
  } else if (m.subscription_status === "pending") {
    statusPill = `<span class="pill pill-pending">Pending</span>`;
  } else {
    statusPill = `<span class="pill pill-active">Active</span>`;
  }

  // Bot health-aware status
  const tokenDaysLeft = m.bot_token_expires ? Math.floor((new Date(m.bot_token_expires)-Date.now())/86400000) : null;
  let botColor, botLabel, botDot;
  if (m.bot_status === "error") {
    botColor = "var(--red)"; botLabel = "Bot Error";
    botDot = `<span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--red);margin-right:4px"></span>`;
  } else if (m.bot_status === "active") {
    if (tokenDaysLeft !== null && tokenDaysLeft <= 7) {
      botColor = "#ff6b35"; botLabel = "⚠ Token Expiring";
      botDot = `<span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#ff6b35;margin-right:4px"></span>`;
    } else if (tokenDaysLeft !== null && tokenDaysLeft <= 14) {
      botColor = "#f59e0b"; botLabel = "⚠ At Risk";
      botDot = `<span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#f59e0b;margin-right:4px"></span>`;
    } else {
      botColor = "var(--green)"; botLabel = "Bot OK";
      botDot = `<span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--green);margin-right:4px"></span>`;
    }
  } else {
    botColor = "#555"; botLabel = "Not set up";
    botDot = `<span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#444;border:1px dashed #666;margin-right:4px"></span>`;
  }

  // Warning banner: paid/active merchant with no bot configured
  const noBotWarning = (m.subscription_status === "paid" && m.bot_status !== "active" && m.bot_status !== "error")
    ? `<div style="margin-top:8px;padding:5px 8px;background:#f59e0b12;border:1px solid #f59e0b30;border-radius:6px;font-size:10px;color:#f59e0b;font-weight:600">⚠ Bot not configured</div>`
    : "";

  return `<div class="merchant-card" onclick="openMerchantPanel('${m.id}')">
    <div class="card-avatar" style="width:44px;height:44px;font-size:18px;margin-bottom:12px">${initials}</div>
    <div class="card-name" style="margin-bottom:2px">${m.shop_name}</div>
    <div style="margin-bottom:12px">
      ${m.whatsapp_number ? `
        <a href="https://wa.me/${m.whatsapp_number.replace(/\D/g,'')}" target="_blank"
          onclick="event.stopPropagation()"
          style="display:inline-flex;align-items:center;gap:5px;color:#25D366;font-size:12px;font-weight:600;text-decoration:none;opacity:.85;transition:opacity .15s"
          onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='.85'">
          ${WA_LOGO} ${m.whatsapp_number}
        </a>
      ` : ""}
    </div>
    <div style="display:flex;align-items:center;justify-content:space-between">
      ${statusPill}
      ${m.status !== "suspended" && (m.subscription_status === "paid" || m.subscription_status === "trial") ? `<span style="font-size:11px;font-weight:600;color:${botColor};display:flex;align-items:center">${botDot}${botLabel}</span>` : ""}
    </div>
    ${m.status !== "suspended" && m.subscription_status === "paid" ? noBotWarning : ""}
  </div>`;
}

function toggleDropdown(id, e) {
  e.stopPropagation();
  document.querySelectorAll(".card-dropdown").forEach(d => { if (d.id !== id) d.classList.remove("open"); });
  document.getElementById(id)?.classList.toggle("open");
}
document.addEventListener("click", () => document.querySelectorAll(".card-dropdown").forEach(d => d.classList.remove("open")));

// ── Merchant Panel ─────────────────────────────────────────
let _activePanelTab = "info";
let _activePanelId = null;

function openMerchantPanel(id, tab = "info") {
  _activePanelId = id;
  _activePanelTab = tab;
  const m = _merchants.find(x => x.id === id);
  if (!m) return;

  const initials = m.shop_name.split(" ").map(w=>w[0]).join("").slice(0,2).toUpperCase();
  const botDot = m.bot_status==="active"?"#25d366":m.bot_status==="error"?"#ff4d4d":"#555";
  const subColor = m.subscription_status==="paid"?"var(--green)":m.subscription_status==="trial"?"#3b82f6":"#f59e0b";
  const subBg    = m.subscription_status==="paid"?"#25d36618":m.subscription_status==="trial"?"#3b82f618":"#f59e0b18";
  const subLabel = m.subscription_status==="paid"?"Active":m.subscription_status==="trial"?"Trial":"Pending";

  document.getElementById("panel-body").innerHTML = `
    <!-- Hero header -->
    <div class="panel-hero">
      <div class="panel-hero-avatar">${initials}</div>
      <div class="panel-hero-info">
        <div class="panel-hero-name">${m.shop_name}</div>
        <div class="panel-hero-badges">
          ${m.status !== "suspended" ? `
            <span style="background:${subBg};color:${subColor};border:1px solid ${subColor}30;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700">${subLabel}</span>
            ${m.subscription_status === "paid" || m.subscription_status === "trial" ? `
              <span class="panel-bot-dot" style="background:${botDot}"></span>
              <span style="font-size:11px;color:${botDot}">${m.bot_status==="active"?"Bot Running":m.bot_status==="error"?"Bot Error":"Bot Not Set Up"}</span>
            ` : ""}
          ` : ""}
        </div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="panel-tab-bar">
      <button class="panel-tab ${tab==="info"?"active":""}" onclick="switchPanelTab('info')">Info</button>
      <button class="panel-tab ${tab==="billing"?"active":""}" onclick="switchPanelTab('billing')">Billing</button>
      ${m.status !== "suspended" ? `<button class="panel-tab ${tab==="bot"?"active":""}" onclick="switchPanelTab('bot')">Bot</button>` : ""}
    </div>

    <!-- Content -->
    <div id="panel-tab-content" class="panel-content"></div>
  `;

  switchPanelTab(tab);
  document.getElementById("panel-overlay").classList.add("open");
  document.getElementById("side-panel").classList.add("open");
}

function switchPanelTab(tab) {
  _activePanelTab = tab;
  const m = _merchants.find(x => x.id === _activePanelId);
  if (!m) return;
  document.querySelectorAll(".panel-tab").forEach(t => t.classList.toggle("active", t.dataset.tab === tab || t.textContent.trim().toLowerCase() === tab));
  const el = document.getElementById("panel-tab-content");
  if (!el) return;
  if (tab === "info") el.innerHTML = renderPanelInfo(m);
  else if (tab === "billing") el.innerHTML = renderPanelBilling(m);
  else if (tab === "bot") el.innerHTML = renderPanelBot(m);
}

function renderPanelInfo(m) {
  const waClean = (m.whatsapp_number||"").replace(/\D/g,"");
  const lastActive = m.last_order_at ? timeAgo(new Date(m.last_order_at)) : "No orders yet";
  const revenue = "$" + parseFloat(m.total_revenue||0).toLocaleString();
  const location = [m.city, m.region].filter(Boolean).join(", ") || "—";
  const memberSince = m.created_at ? new Date(m.created_at).toLocaleDateString("en-US",{month:"long",year:"numeric"}) : "—";

  return `
    <!-- Contact card -->
    <div class="info-contact-card">
      <div class="info-contact-row">
        <span class="info-contact-icon">📱</span>
        <div>
          <div class="info-contact-label">WhatsApp</div>
          <div class="info-contact-value">${m.whatsapp_number||"—"}</div>
        </div>
      </div>
    </div>

    <!-- Business details -->
    <div class="pinfo-row">
      <div class="pinfo-label">Business Type</div>
      <div class="pinfo-value">${m.business_type||"—"}</div>
    </div>
    <div class="pinfo-row">
      <div class="pinfo-label">Location</div>
      <div class="pinfo-value">${location}</div>
    </div>
    <div class="pinfo-row">
      <div class="pinfo-label">Member Since</div>
      <div class="pinfo-value">${memberSince}</div>
    </div>
    <div class="panel-divider"></div>

    <!-- Primary action -->
    ${m.status === "suspended"
      ? `<button class="paction-btn" disabled style="opacity:0.4;cursor:not-allowed;background:var(--surface2);border:1px solid var(--border);color:var(--text-muted)" title="Account is suspended — activate to access dashboard">
          <span>🖥</span> Dashboard Unavailable
        </button>`
      : `<button class="paction-btn paction-primary" onclick="impersonateMerchant('${m.id}','${m.shop_name}')">
          <span>🖥</span> Open ${m.shop_name}'s Dashboard
        </button>`
    }

    ${waClean?`<button class="paction-btn paction-green" onclick="window.open('https://wa.me/${waClean}','_blank')">
      ${WA_LOGO} Message on WhatsApp
    </button>
    ${m.subscription_status === "pending" && m.status !== "suspended"
      ? `<button class="paction-btn" style="background:var(--surface2);border:1px solid var(--border);color:var(--text)" onclick="sendWelcomeWA('${m.shop_name}','${m.whatsapp_number||""}')">
      ${WA_LOGO} <span style="margin-left:4px">Send Welcome Message</span>
    </button>` : ""}
    `:""}

    <!-- Secondary actions grid -->
    <div class="info-actions-grid">
      ${m.status !== "suspended" ? `
        ${m.subscription_status !== "paid" ? `<button class="info-action-tile" onclick="sendWelcomeWA('${m.shop_name}','${m.whatsapp_number||''}')">
          <span class="info-action-icon">🔗</span>
          <span>Resend Access</span>
        </button>` : ""}
        ${m.subscription_status === "trial"
          ? `<button class="info-action-tile info-action-warn" onclick="endTrial('${m.id}','${m.shop_name}')">
              <span class="info-action-icon">⏹</span>
              <span>End Trial</span>
            </button>`
          : `<button class="info-action-tile info-action-warn" onclick="suspendMerchant('${m.id}')">
              <span class="info-action-icon">⏸</span>
              <span>Suspend</span>
            </button>`
        }
      ` : `
        <button class="info-action-tile info-action-success" style="grid-column:1/-1" onclick="activateMerchant('${m.id}')">
          <span class="info-action-icon">▶</span>
          <span>Activate</span>
        </button>
      `}
    </div>

    <!-- Danger zone -->
    <div class="panel-divider"></div>
    <button class="remove-merchant-btn" onclick="removeMerchant('${m.id}','${m.shop_name}')">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
      Remove from System
    </button>
  `;
}

function renderPanelBilling(m) {
  const isPaid = m.subscription_status === "paid";
  const isTrial = m.subscription_status === "trial";
  const trialDaysLeft = isTrial && m.trial_ends_at ? Math.ceil((new Date(m.trial_ends_at) - Date.now()) / 86400000) : null;
  const billingColor = isPaid ? "var(--green)" : isTrial ? "#a78bfa" : "var(--orange)";
  const billingLabel = isPaid ? "Paid" : isTrial ? `Trial${trialDaysLeft !== null ? ` — ${trialDaysLeft} day${trialDaysLeft !== 1 ? "s" : ""} left` : ""}` : "Pending";

  // Renewal = last payment + 1 month or 1 year depending on billing cycle
  const isYearly = m.billing_cycle === "yearly";
  const activeFee = isYearly ? (m.yearly_fee || 0) : (m.monthly_fee || 0);
  let renewalStr = "—";
  let renewalColor = "var(--text)";
  if (m.last_payment_at) {
    const renewal = new Date(m.last_payment_at);
    if (isYearly) renewal.setFullYear(renewal.getFullYear() + 1);
    else renewal.setMonth(renewal.getMonth() + 1);
    renewalStr = renewal.toLocaleDateString("en-US",{month:"long",day:"numeric",year:"numeric"});
    const daysLeft = Math.ceil((renewal - Date.now()) / 86400000);
    if (daysLeft < 0) renewalColor = "var(--red)";
    else if (daysLeft <= 7) renewalColor = "#f59e0b";
  }

  const monthlyFeeVal = (m.monthly_fee || 0).toLocaleString();
  const yearlyFeeVal  = (m.yearly_fee  || 0).toLocaleString();
  const renewalLabel  = isTrial ? "Trial Ends" : "Next Renewal";
  const renewalVal    = isTrial
    ? (m.trial_ends_at ? new Date(m.trial_ends_at).toLocaleDateString("en-US",{month:"long",day:"numeric",year:"numeric"}) : "—")
    : renewalStr;
  const renewalClr    = isTrial ? (trialDaysLeft !== null && trialDaysLeft <= 2 ? "var(--orange)" : "#a78bfa") : renewalColor;

  return `
    <!-- Subscription overview card -->
    <div style="background:var(--surface2);border:1px solid var(--border);border-radius:16px;overflow:hidden;margin-bottom:20px">

      <div style="padding:18px 20px;display:flex;align-items:center;justify-content:space-between">
        <div>
          <div style="font-size:24px;font-weight:800;letter-spacing:-.5px;color:var(--text)">
            $${activeFee}<span style="font-size:13px;font-weight:500;color:var(--text-muted)"> / ${isYearly ? "year" : "month"}</span>
          </div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:3px">${renewalLabel}: <span style="color:${renewalClr};font-weight:600">${renewalVal}</span></div>
        </div>
        <span style="font-size:12px;font-weight:700;padding:5px 12px;border-radius:20px;border:1px solid ${billingColor}40;color:${billingColor};background:${billingColor}12">${billingLabel}</span>
      </div>

      ${!isPaid && !isTrial ? `
        <div style="border-top:1px solid var(--border);padding:12px 20px;display:flex;justify-content:flex-end">
          <button onclick="markPaid('${m.id}','${m.shop_name}')"
            style="background:transparent;border:1.5px solid var(--green);color:var(--green);font-size:13px;font-weight:700;padding:7px 18px;border-radius:8px;cursor:pointer;transition:all .15s"
            onmouseover="this.style.background='var(--green-dim)'"
            onmouseout="this.style.background='transparent'">
            ✓ Mark as Paid
          </button>
        </div>
      ` : ""}
    </div>

    <!-- Billing settings rows -->
    <div style="background:var(--surface2);border:1px solid var(--border);border-radius:16px;overflow:hidden">

      <!-- Billing cycle row -->
      <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid var(--border)">
        <span style="font-size:14px;font-weight:500;color:var(--text)">Billing Cycle</span>
        <div id="billing-cycle-toggle-${m.id}" style="display:flex;background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:3px;gap:3px">
          <button data-val="monthly" onclick="billingToggle('${m.id}','monthly')"
            style="padding:5px 14px;border-radius:6px;border:none;font-size:12px;font-weight:700;cursor:pointer;transition:all .15s;${!isYearly ? 'background:var(--green);color:#fff' : 'background:transparent;color:var(--text-muted)'}">
            Monthly
          </button>
          <button data-val="yearly" onclick="billingToggle('${m.id}','yearly')"
            style="padding:5px 14px;border-radius:6px;border:none;font-size:12px;font-weight:700;cursor:pointer;transition:all .15s;${isYearly ? 'background:var(--green);color:#fff' : 'background:transparent;color:var(--text-muted)'}">
            Yearly
          </button>
        </div>
      </div>

      <!-- Fee row -->
      <div id="billing-fee-monthly-${m.id}" style="padding:14px 20px;border-bottom:1px solid var(--border);${isYearly ? 'display:none' : ''}">
        <div style="display:flex;align-items:center;justify-content:space-between">
          <span style="font-size:14px;font-weight:500;color:var(--text)">Monthly Fee</span>
          <div style="display:flex;align-items:center;gap:4px">
            <span style="font-size:14px;color:var(--text-muted);font-weight:600">$</span>
            <input id="billing-monthly-fee-${m.id}" type="text" inputmode="numeric" class="no-arrows" placeholder="0"
              value="${monthlyFeeVal}"
              oninput="window.fmtFeeInput(this)"
              style="width:90px;background:var(--surface);border:1px solid var(--border);border-radius:7px;outline:none;padding:6px 10px;font-size:14px;font-weight:700;color:var(--text);text-align:right;transition:border-color .15s"
              onfocus="this.style.borderColor='var(--green)'"
              onblur="this.style.borderColor='var(--border)'">
          </div>
        </div>
      </div>

      <div id="billing-fee-yearly-${m.id}" style="padding:14px 20px;border-bottom:1px solid var(--border);${!isYearly ? 'display:none' : ''}">
        <div style="display:flex;align-items:center;justify-content:space-between">
          <span style="font-size:14px;font-weight:500;color:var(--text)">Yearly Fee</span>
          <div style="display:flex;align-items:center;gap:4px">
            <span style="font-size:14px;color:var(--text-muted);font-weight:600">$</span>
            <input id="billing-yearly-fee-${m.id}" type="text" inputmode="numeric" class="no-arrows" placeholder="0"
              value="${yearlyFeeVal}"
              oninput="window.fmtFeeInput(this)"
              style="width:90px;background:var(--surface);border:1px solid var(--border);border-radius:7px;outline:none;padding:6px 10px;font-size:14px;font-weight:700;color:var(--text);text-align:right;transition:border-color .15s"
              onfocus="this.style.borderColor='var(--green)'"
              onblur="this.style.borderColor='var(--border)'">
          </div>
        </div>
      </div>

      <!-- Save row -->
      <div style="padding:12px 20px;display:flex;justify-content:flex-end">
        <button onclick="saveBilling('${m.id}')"
          style="background:var(--green);color:#fff;font-size:13px;font-weight:700;padding:8px 20px;border-radius:8px;border:none;cursor:pointer;transition:opacity .15s"
          onmouseover="this.style.opacity='.85'"
          onmouseout="this.style.opacity='1'">
          Save Changes
        </button>
      </div>
    </div>
  `;
}

function renderPanelBot(m) {
  const isActive = m.bot_status==="active";
  const isError  = m.bot_status==="error";
  const statusBg   = isActive?"#25d36618":isError?"#ff4d4d18":"#33333330";
  const statusBorder= isActive?"#25d36640":isError?"#ff4d4d40":"#44444440";
  const statusColor = isActive?"var(--green)":isError?"var(--red)":"var(--text-muted)";
  const statusLabel = isActive?"● Running":isError?"● Error":"● Not set up";
  const lastChecked = m.bot_last_checked ? timeAgo(new Date(m.bot_last_checked)) : "Never";

  const tokenExpiry = m.bot_token_expires ? new Date(m.bot_token_expires) : null;
  const daysLeft = tokenExpiry ? Math.floor((tokenExpiry-Date.now())/86400000) : null;
  // Escalating token alert (skip if error is already about the token)
  const errIsToken = isError && (m.bot_error||"").toLowerCase().match(/token|401|unauthorized|expired/);
  let tokenAlert = null;
  if (daysLeft !== null && !errIsToken) {
    if (daysLeft <= 0)       tokenAlert = { color:"#ff4d4d", bg:"#ff4d4d15", border:"#ff4d4d40", icon:"🚨", msg:"Token has <strong>expired</strong> — update it now to restore the bot." };
    else if (daysLeft === 1) tokenAlert = { color:"#ff4d4d", bg:"#ff4d4d15", border:"#ff4d4d40", icon:"🚨", msg:"Token expires <strong>tomorrow</strong> — update now or the bot goes down." };
    else if (daysLeft <= 7)  tokenAlert = { color:"#ff6b35", bg:"#ff6b3515", border:"#ff6b3540", icon:"🔴", msg:`Token expires in <strong>${daysLeft} days</strong> — update urgently.` };
    else if (daysLeft <= 14) tokenAlert = { color:"#f59e0b", bg:"#f59e0b15", border:"#f59e0b40", icon:"🟠", msg:`Token expires in <strong>${daysLeft} days</strong> — update it soon.` };
    else if (daysLeft <= 30) tokenAlert = { color:"#f59e0b", bg:"#f59e0b10", border:"#f59e0b30", icon:"⚠️", msg:`Token expires in <strong>${daysLeft} days</strong>.` };
  }

  // Quick fix shortcuts per error type
  const btnGreen   = `padding:6px 12px;border-radius:7px;font-size:11px;font-weight:700;cursor:pointer;background:var(--green);color:#fff;border:none`;
  const btnOutline = `padding:6px 12px;border-radius:7px;font-size:11px;font-weight:700;cursor:pointer;background:transparent;color:var(--text-muted);border:1px solid var(--border)`;
  let quickFixes = [];
  if (isError) {
    const err = (m.bot_error||"").toLowerCase();
    if (err.match(/401|token|unauthorized|expired/)) {
      quickFixes = [
        { label:"✏️ Update Token",        style:btnGreen,   action:`document.getElementById('bot-token-${m.id}').focus();document.getElementById('bot-token-${m.id}').scrollIntoView({behavior:'smooth',block:'center'})` },
        { label:"↗ Meta Business Suite", style:btnOutline, action:`window.open('https://business.facebook.com/settings/whatsapp-business-accounts','_blank')` },
      ];
    } else if (err.match(/webhook|not responding|no messages/)) {
      quickFixes = [
        { label:"▶ Restart Bot",          style:btnGreen,   action:`restartBot('${m.id}')` },
        { label:"🔗 Re-register Webhook", style:btnOutline, action:`reRegisterWebhook('${m.id}')` },
      ];
    } else if (err.match(/missing|credentials|phone number id/)) {
      quickFixes = [
        { label:"📱 Fill Credentials", style:btnGreen, action:`document.getElementById('bot-phone-id-${m.id}').focus();document.getElementById('bot-phone-id-${m.id}').scrollIntoView({behavior:'smooth',block:'center'})` },
      ];
    } else if (err.match(/banned|disabled|spam/)) {
      quickFixes = [
        { label:"📱 Switch Number",  style:btnGreen,   action:`switchWhatsappNumber('${m.id}')` },
        { label:"↗ Appeal to Meta", style:btnOutline, action:`window.open('https://www.facebook.com/help/contact/1638046109571035','_blank')` },
      ];
    } else if (err.match(/rate|too many|limit/)) {
      quickFixes = [
        { label:"⏱ Retry in 15 min", style:btnGreen,   action:`scheduleRetry('${m.id}',15)` },
        { label:"⏱ Retry in 1 hour", style:btnOutline, action:`scheduleRetry('${m.id}',60)` },
      ];
    } else if (err.match(/outage|service|down/)) {
      quickFixes = [
        { label:"🔄 Auto-retry",  style:btnGreen,   action:`autoRetryBot('${m.id}')` },
        { label:"↗ Meta Status", style:btnOutline, action:`window.open('https://metastatus.com','_blank')` },
      ];
    } else if (err.match(/version|deprecated/)) {
      quickFixes = [
        { label:"↗ Meta API Docs", style:btnOutline, action:`window.open('https://developers.facebook.com/docs/whatsapp','_blank')` },
      ];
    } else {
      quickFixes = [
        { label:"▶ Restart Bot",  style:btnGreen,   action:`restartBot('${m.id}')` },
        { label:"↗ Meta Status", style:btnOutline, action:`window.open('https://metastatus.com','_blank')` },
      ];
    }
  }

  // Compact health strip — token status
  let tokenStripColor, tokenStripBorder, tokenStripText;
  if (daysLeft === null) {
    tokenStripColor = "var(--text-muted)"; tokenStripBorder = "var(--border)"; tokenStripText = "Not set";
  } else if (daysLeft <= 0) {
    tokenStripColor = "var(--red)";  tokenStripBorder = "#ff4d4d40"; tokenStripText = "Expired!";
  } else if (daysLeft <= 7) {
    tokenStripColor = "#ff6b35"; tokenStripBorder = "#ff6b3540"; tokenStripText = daysLeft + "d — urgent";
  } else if (daysLeft <= 14) {
    tokenStripColor = "#f59e0b"; tokenStripBorder = "#f59e0b40"; tokenStripText = daysLeft + "d — soon";
  } else if (daysLeft <= 30) {
    tokenStripColor = "#f59e0b"; tokenStripBorder = "#f59e0b30"; tokenStripText = daysLeft + "d left";
  } else {
    tokenStripColor = "var(--green)"; tokenStripBorder = "#25d36630"; tokenStripText = daysLeft + "d left";
  }

  // Credentials status
  const credsOk    = !!(m.bot_phone_id && m.bot_token);
  const credColor  = credsOk ? "var(--green)" : "var(--red)";
  const credBorder = credsOk ? "#25d36630" : "#ff4d4d40";
  const credText   = credsOk ? "Saved" : "Missing";

  // Bot mode
  const modeText   = m.bot_mode === "auto" ? "Auto" : m.bot_mode === "manual" ? "Manual" : "Not set";
  const modeColor  = m.bot_mode === "auto" ? "var(--green)" : m.bot_mode === "manual" ? "#f59e0b" : "var(--text-muted)";
  const modeBorder = m.bot_mode === "auto" ? "#25d36630" : m.bot_mode === "manual" ? "#f59e0b30" : "var(--border)";

  const SAVE_ICON = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>`;
  const TEST_ICON = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0"><path d="M5 12.55a11 11 0 0114.08 0"/><path d="M1.42 9a16 16 0 0121.16 0"/><path d="M8.53 16.11a6 6 0 016.95 0"/><circle cx="12" cy="20" r="1" fill="currentColor"/></svg>`;

  return `
    <!-- Status card -->
    <div style="background:${statusBg};border:1px solid ${statusBorder};border-radius:10px;padding:14px 16px;margin-bottom:10px">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <div>
          <div style="font-size:15px;font-weight:800;color:${statusColor}">${statusLabel}</div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:2px">Last checked: ${lastChecked}</div>
        </div>
      </div>
      ${isError&&m.bot_error?`
        <div style="margin-top:8px;padding-top:8px;border-top:1px solid ${statusBorder};font-size:12px;color:#ff6b6b;line-height:1.5">${m.bot_error}</div>
        ${quickFixes.length?`<div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">
          ${quickFixes.map(f=>`<button onclick="${f.action}" style="${f.style}">${f.label}</button>`).join("")}
        </div>`:""}
      `:""}
    </div>

    <!-- Compact health strip -->
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:14px">
      <div style="background:var(--surface2);border:1px solid ${tokenStripBorder};border-radius:8px;padding:9px 12px">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);letter-spacing:.6px;margin-bottom:3px">TOKEN</div>
        <div style="font-size:12px;font-weight:700;color:${tokenStripColor}">${tokenStripText}</div>
      </div>
      <div style="background:var(--surface2);border:1px solid ${credBorder};border-radius:8px;padding:9px 12px">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);letter-spacing:.6px;margin-bottom:3px">CREDENTIALS</div>
        <div style="font-size:12px;font-weight:700;color:${credColor}">${credText}</div>
      </div>
      <div style="background:var(--surface2);border:1px solid ${modeBorder};border-radius:8px;padding:9px 12px">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);letter-spacing:.6px;margin-bottom:3px">MODE</div>
        <div style="font-size:12px;font-weight:700;color:${modeColor}">${modeText}</div>
      </div>
    </div>

    <!-- Credentials -->
    <div class="panel-section-label">WhatsApp Credentials</div>
    <div class="pcred-field"><label>Phone Number ID</label><input id="bot-phone-id-${m.id}" value="${m.bot_phone_id||""}" placeholder="Meta Phone Number ID"></div>
    <div class="pcred-field"><label>Access Token</label>
      <div style="position:relative">
        <input id="bot-token-${m.id}" value="${m.bot_token||""}" placeholder="EAAxxxxxx…" type="password" style="padding-right:50px">
        <button onclick="toggleTokenVisibility('bot-token-${m.id}',this)" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:11px">Show</button>
      </div>
    </div>
    <div class="pcred-field"><label>Token Expiry Date</label><input id="bot-token-exp-${m.id}" type="date" value="${m.bot_token_expires?m.bot_token_expires.split("T")[0]:""}"></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:4px">
      <button class="paction-btn paction-primary" style="display:flex;align-items:center;justify-content:center;gap:8px" onclick="saveBotCredentials('${m.id}')">${SAVE_ICON} Save</button>
      <button class="paction-btn" style="display:flex;align-items:center;justify-content:center;gap:8px" onclick="testBotConnection('${m.id}')">${TEST_ICON} Test Connection</button>
    </div>
  `;
}

function closePanel() {
  document.getElementById("panel-overlay").classList.remove("open");
  document.getElementById("side-panel").classList.remove("open");
  _activePanelId = null;
}

// ── Bot actions ────────────────────────────────────────────
async function testBotConnection(id) {
  const btn = event.target;
  const originalHTML = btn.innerHTML;
  btn.textContent = "Testing…"; btn.disabled = true;

  // Read current values from inputs if they exist in the DOM, else fall back to merchant data
  const m0 = _merchants.find(x=>x.id===id);
  const phoneIdEl = document.getElementById(`bot-phone-id-${id}`);
  const tokenEl   = document.getElementById(`bot-token-${id}`);
  const expiryEl  = document.getElementById(`bot-token-exp-${id}`);
  const phoneId = phoneIdEl ? phoneIdEl.value.trim() : (m0?.bot_phone_id || "");
  const token   = tokenEl   ? tokenEl.value.trim()   : (m0?.bot_token || "");
  const expiry  = expiryEl  ? expiryEl.value         : (m0?.bot_token_expires || "");

  // Simulate network delay
  await new Promise(r => setTimeout(r, 1200));

  const m = _merchants.find(x=>x.id===id);

  try {
    // In live mode, let the server decide
    const data = await api("POST", `/api/owner/merchants/${id}/bot/test`);
    if (data.success !== false) {
      if (m) { m.bot_status="active"; m.bot_error=null; m.bot_last_checked=new Date().toISOString(); }
      toast("✅ Bot connection is working!");
      openMerchantPanel(id, "bot");
    } else {
      toast(data.error || "Connection failed", "error");
    }
  } catch {
    // Sync whatever the user typed back into the merchant object so re-render shows it
    if (m) {
      m.bot_phone_id = phoneId;
      m.bot_token = token;
      m.bot_token_expires = expiry;
    }

    // Demo mode — simulate based on credential state
    if (!phoneId || !token) {
      if (m) { m.bot_status="error"; m.bot_error="Missing credentials — Phone Number ID and Access Token are required."; m.bot_last_checked=new Date().toISOString(); }
      toast("Connection failed: missing credentials", "error");
    } else if (expiry && new Date(expiry) < new Date()) {
      if (m) { m.bot_status="error"; m.bot_error="Token expired — Meta returned 401 Unauthorized. Update the access token to restore the bot."; m.bot_last_checked=new Date().toISOString(); }
      toast("Connection failed: token is expired", "error");
    } else {
      if (m) { m.bot_status="active"; m.bot_error=null; m.bot_last_checked=new Date().toISOString(); }
      toast("Bot connection is working!");
    }
    openMerchantPanel(id, "bot");
  }

  btn.innerHTML = originalHTML; btn.disabled = false;
}

async function restartBot(id) {
  const m = _merchants.find(x=>x.id===id);
  try {
    await api("POST", `/api/owner/merchants/${id}/bot/restart`);
    if (m) { m.bot_status="active"; m.bot_error=null; m.bot_last_checked=new Date().toISOString(); }
    toast(`Bot restarted for ${m?.shop_name}`);
    openMerchantPanel(id, "bot");
  } catch { toast("Bot restarted (demo mode)"); }
}

async function saveBotCredentials(id) {
  const phoneId = document.getElementById(`bot-phone-id-${id}`)?.value?.trim();
  const token   = document.getElementById(`bot-token-${id}`)?.value?.trim();
  const expiry  = document.getElementById(`bot-token-exp-${id}`)?.value;
  if (!phoneId || !token) { toast("Phone Number ID and Token are required", "error"); return; }
  try {
    await api("PATCH", `/api/owner/merchants/${id}/bot`, { phoneNumberId: phoneId, accessToken: token, tokenExpires: expiry });
    const m = _merchants.find(x=>x.id===id);
    if (m) { m.bot_phone_id=phoneId; m.bot_token=token; m.bot_token_expires=expiry; }
    toast("Credentials saved! Testing connection…");
    setTimeout(() => testBotConnection(id), 800);
  } catch { toast("Saved (demo mode)"); }
}

async function setBotMode(id, mode) {
  try {
    await api("PATCH", `/api/owner/merchants/${id}/bot`, { mode });
    const m = _merchants.find(x=>x.id===id);
    if (m) m.bot_mode = mode;
    toast(`Bot mode set to ${mode}`);
    openMerchantPanel(id, "bot");
  } catch { toast("Mode updated (demo mode)"); }
}

// ── Bot prevention & fix tools ─────────────────────────────
const _botRetryTimers = {};

function scheduleRetry(id, minutes) {
  clearTimeout(_botRetryTimers[id]);
  const m = _merchants.find(x=>x.id===id);
  _botRetryTimers[id] = setTimeout(async () => {
    toast(`Auto-retrying bot for ${m?.shop_name}…`);
    await restartBot(id);
  }, minutes * 60000);
  toast(`Bot will auto-retry in ${minutes} minute${minutes>1?"s":""}`, "success");
}

async function autoRetryBot(id) {
  const delays = [1, 5, 15, 60];
  let attempt = 0;
  const m = _merchants.find(x=>x.id===id);
  toast(`Auto-retry enabled for ${m?.shop_name} — will keep trying until back online`);
  const tryNext = () => {
    const delay = delays[Math.min(attempt, delays.length-1)];
    _botRetryTimers[id] = setTimeout(async () => {
      attempt++;
      try {
        await api("POST", `/api/owner/merchants/${id}/bot/restart`);
        const updated = _merchants.find(x=>x.id===id);
        if (updated) { updated.bot_status="active"; updated.bot_error=null; updated.bot_last_checked=new Date().toISOString(); }
        toast(`✅ ${m?.shop_name} bot is back online after ${attempt} attempt${attempt>1?"s":""}!`);
        openMerchantPanel(id, "bot");
        return;
      } catch {}
      tryNext();
    }, delay * 60000);
  };
  tryNext();
}

async function reRegisterWebhook(id) {
  const m = _merchants.find(x=>x.id===id);
  try {
    await api("POST", `/api/owner/merchants/${id}/bot/register-webhook`);
    toast(`Webhook re-registered for ${m?.shop_name}`);
  } catch {
    toast(`Webhook re-registration sent (demo mode)`);
    if (m) { m.bot_status="active"; m.bot_error=null; m.bot_last_checked=new Date().toISOString(); }
    setTimeout(() => openMerchantPanel(id, "bot"), 800);
  }
}

function switchWhatsappNumber(id) {
  const m = _merchants.find(x=>x.id===id);
  openModal(`Switch WhatsApp Number — ${m?.shop_name}`, `
    <p style="color:var(--text-muted);font-size:13px;margin-bottom:18px;line-height:1.5">
      Enter the credentials for your new WhatsApp number. This replaces the current (banned/disabled) number and restarts the bot.
    </p>
    <div class="field"><label>New Phone Number ID</label><input id="switch-phone-id" placeholder="Meta Phone Number ID"></div>
    <div class="field"><label>New Access Token</label><input id="switch-token" type="password" placeholder="EAAxxxxxx…"></div>
    <div class="field"><label>Token Expiry Date</label><input id="switch-expiry" type="date"></div>
    <div class="modal-actions">
      <button class="btn-sm" onclick="closeModal()">Cancel</button>
      <button class="btn-primary" onclick="submitSwitchNumber('${id}')">Switch & Restart Bot</button>
    </div>
  `);
}

async function submitSwitchNumber(id) {
  const phoneId = document.getElementById("switch-phone-id")?.value?.trim();
  const token   = document.getElementById("switch-token")?.value?.trim();
  const expiry  = document.getElementById("switch-expiry")?.value;
  if (!phoneId || !token || !expiry) { toast("All fields are required", "error"); return; }
  closeModal();
  const m = _merchants.find(x=>x.id===id);
  if (m) { m.bot_phone_id=phoneId; m.bot_token=token; m.bot_token_expires=expiry; }
  try {
    await api("PATCH", `/api/owner/merchants/${id}/bot`, { phoneNumberId:phoneId, accessToken:token, tokenExpires:expiry });
    toast(`Number switched! Restarting bot for ${m?.shop_name}…`);
    setTimeout(() => restartBot(id), 800);
  } catch {
    toast(`Number switched (demo)! Restarting bot…`);
    if (m) { m.bot_status="active"; m.bot_error=null; m.bot_last_checked=new Date().toISOString(); }
    setTimeout(() => openMerchantPanel(id, "bot"), 800);
  }
}

// ── Merchant actions ───────────────────────────────────────

const BUSINESS_TYPES = ["Restaurant / Café","Bakery / Sweets","Supermarket / Grocery","Boutique / Clothing","Pharmacy","Electronics","Flower Shop","Pet Shop","Home & Furniture","General Retail","Other"];
const LEBANON_REGIONS = ["Beirut","Mount Lebanon","North Lebanon","South Lebanon","Bekaa","Nabatieh","Akkar","Baalbek-Hermel"];

function openAddMerchantModal() {
  openModal("Add Merchant", `
    <div class="field"><label>Shop Name</label><input id="m-name" placeholder="e.g. Joe's Snacks"></div>
    <div class="field"><label>WhatsApp Number <span style="color:var(--text-muted);font-size:11px">(used to log in)</span></label><input id="m-wa" placeholder="+9613001001"></div>
    <div class="field"><label>Password <span style="color:var(--text-muted);font-size:11px">(merchant must change on first login)</span></label><input id="m-pass" type="password" placeholder="••••••••"></div>
    <div class="field"><label>Business Type</label>
      <select id="m-btype">
        <option value="">Select type…</option>
        ${BUSINESS_TYPES.map(t=>`<option value="${t}">${t}</option>`).join("")}
      </select>
    </div>
    <div class="field"><label>Street / Area</label><input id="m-street" placeholder="e.g. Hamra Street, Block 3"></div>
    <div class="modal-row">
      <div class="field"><label>City</label><input id="m-city" placeholder="e.g. Beirut"></div>
      <div class="field"><label>Region</label>
        <select id="m-region">
          <option value="">Select region…</option>
          ${LEBANON_REGIONS.map(r=>`<option value="${r}">${r}</option>`).join("")}
        </select>
      </div>
    </div>
    <div class="field" style="background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:14px;display:flex;align-items:center;justify-content:space-between">
      <div>
        <div style="font-size:13px;font-weight:600">Start with 7-day Trial</div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:2px">Merchant won't be charged until trial ends</div>
      </div>
      <label style="position:relative;display:inline-block;width:40px;height:22px;flex-shrink:0">
        <input type="checkbox" id="m-trial" checked style="opacity:0;width:0;height:0">
        <span style="position:absolute;cursor:pointer;inset:0;background:#333;border-radius:22px;transition:.2s" id="m-trial-track"></span>
        <span style="position:absolute;content:'';height:16px;width:16px;left:3px;top:3px;background:white;border-radius:50%;transition:.2s" id="m-trial-thumb"></span>
      </label>
    </div>
    <div class="modal-actions">
      <button class="btn-sm" onclick="closeModal()">Cancel</button>
      <button class="btn-primary" onclick="submitAddMerchant()">Create Merchant</button>
    </div>
  `);
  // Toggle visual
  const cb = document.getElementById("m-trial");
  const track = document.getElementById("m-trial-track");
  const thumb = document.getElementById("m-trial-thumb");
  const update = () => { track.style.background = cb.checked ? "var(--green)" : "#333"; thumb.style.left = cb.checked ? "21px" : "3px"; };
  update(); cb.addEventListener("change", update);
}

async function submitAddMerchant() {
  const shopName = document.getElementById("m-name").value.trim();
  const whatsapp = document.getElementById("m-wa").value.trim();
  const password = document.getElementById("m-pass").value;
  if (!shopName || !whatsapp || !password) { toast("Shop name, WhatsApp and password are required", "error"); return; }
  const trial = document.getElementById("m-trial").checked;
  const trialEndsAt = trial ? new Date(Date.now() + 7*86400000).toISOString().split("T")[0] : null;
  try {
    await api("POST", "/api/owner/merchants", {
      shopName,
      whatsappNumber: whatsapp,
      password,
      businessType: document.getElementById("m-btype").value,
      street: document.getElementById("m-street").value,
      city: document.getElementById("m-city").value,
      region: document.getElementById("m-region").value,
      subscriptionStatus: trial ? "trial" : "pending",
      trialEndsAt,
      forcePasswordChange: true,
    });
    closeModal(); loadMerchants();
    toast("Merchant created successfully!");
  } catch (e) { toast(e.message, "error"); }
}

async function markPaid(id, name) {
  const m = _billingMerchants.find(x => x.id === id) || _merchants.find(x => x.id === id);
  const cycle = m?.billing_cycle === "yearly" ? "year" : "month";
  const fee   = m?.billing_cycle === "yearly" ? `$${m.yearly_fee || 0}/yr` : `$${m?.monthly_fee || 0}/mo`;
  confirmModal({
    title: "Mark as Paid",
    message: `Mark <strong>${name}</strong> as paid for this ${cycle}? <span style="color:var(--text-muted);font-size:12px">(${fee})</span>`,
    confirmLabel: "Mark as Paid",
    confirmClass: "btn-primary",
    onConfirm: async () => {
      try {
        await api("PATCH", `/api/owner/merchants/${id}`, { subscriptionStatus: "paid", lastPaymentAt: new Date().toISOString().split("T")[0] });
        toast(`${name} marked as paid`);
        closePanel(); loadMerchants();
        // Refresh billing table if it's currently open
        if (document.getElementById("page-billing")?.classList.contains("active")) loadBilling();
      } catch (e) { toast(e.message, "error"); }
    }
  });
}

async function impersonateMerchant(id, name) {
  confirmModal({
    title: "Open Dashboard",
    message: `You're about to log in as <strong>${name}</strong> and open their backoffice dashboard.`,
    confirmLabel: "Open Dashboard",
    confirmClass: "btn-primary",
    onConfirm: async () => {
      try {
        const data = await api("POST", `/api/owner/merchants/${id}/impersonate`);
        localStorage.setItem("nt_merchant_token", data.token || "demo");
      } catch { localStorage.setItem("nt_merchant_token", "demo"); }
      window.open("../backoffice/index.html", "_blank");
    }
  });
}

async function suspendMerchant(id) {
  const m = _merchants.find(x => x.id === id);
  confirmModal({
    title: "Suspend Merchant",
    message: `Suspend <strong>${m?.shop_name}</strong>? Their bot will stop and they won't be able to log in until reactivated.`,
    confirmLabel: "Suspend",
    confirmClass: "btn-danger",
    onConfirm: async () => {
      try {
        await api("PATCH", `/api/owner/merchants/${id}`, { status:"suspended" });
        toast(`${m?.shop_name} suspended`);
        closePanel(); loadMerchants();
      } catch (e) { toast(e.message, "error"); }
    }
  });
}

function endTrial(id, shopName) {
  confirmModal({
    title: "End Trial",
    message: `End the trial for <strong>${shopName}</strong>? Their account will be suspended until they complete payment.`,
    confirmLabel: "End Trial",
    confirmClass: "btn-primary",
    onConfirm: async () => {
      try {
        await api("PATCH", `/api/owner/merchants/${id}`, { status:"suspended", subscription_status:"pending" });
        toast(`${shopName}'s trial ended`);
        closePanel(); loadMerchants();
      } catch (e) { toast(e.message, "error"); }
    }
  });
}

async function activateMerchant(id) {
  const m = _merchants.find(x => x.id === id);
  try {
    await api("PATCH", `/api/owner/merchants/${id}`, { status:"active" });
    toast(`${m?.shop_name} activated`);
    closePanel(); loadMerchants();
  } catch (e) { toast(e.message, "error"); }
}

function removeMerchant(id, name) {
  confirmModal({
    title: "Remove Merchant",
    message: `This will permanently delete <strong>${name}</strong> and all their data — orders, customers, drivers, and bot configuration.<br><br>This <strong>cannot be undone</strong>.`,
    confirmLabel: "Remove Permanently",
    confirmClass: "btn-danger",
    onConfirm: async () => {
      try {
        await api("DELETE", `/api/owner/merchants/${id}`);
        toast(`${name} removed from the system`);
        closePanel(); loadMerchants();
      } catch (e) { toast(e.message, "error"); }
    }
  });
}

function fmtFeeInput(el) {
  const pos = el.selectionStart;
  const raw = el.value.replace(/[^0-9]/g, "");
  const formatted = raw.replace(/\B(?=(\d{3})+(?!\d))/g, ",");
  el.value = formatted;
}

function updateEditFee(cycle) {
  document.getElementById("fee-monthly").style.display = cycle === "monthly" ? "block" : "none";
  document.getElementById("fee-yearly").style.display  = cycle === "yearly"  ? "block" : "none";
}

function editMerchant(id) {
  const m = _merchants.find(x => x.id === id);
  const cycle = m?.billing_cycle || "monthly";

  const feeInput = (inputId, val) => `
    <div style="display:flex;align-items:center;background:var(--bg);border:1px solid var(--border);border-radius:8px;overflow:hidden"
         onfocusin="this.style.borderColor='var(--green)'" onfocusout="this.style.borderColor='var(--border)'">
      <span style="padding:9px 12px;color:var(--text-muted);font-size:14px;font-weight:600;border-right:1px solid var(--border);background:var(--surface2)">$</span>
      <input id="${inputId}" type="text" inputmode="numeric" value="${val.toLocaleString()}" class="no-arrows"
        oninput="fmtFeeInput(this)"
        style="border:none;background:transparent;color:var(--text);font-size:14px;padding:9px 12px;width:100%;outline:none">
    </div>`;

  openModal(`Edit — ${m?.shop_name}`, `
    <div class="field">
      <label style="display:block;margin-bottom:8px">Billing Cycle</label>
      <div style="display:flex;gap:8px" id="edit-billing-cycle">
        ${["monthly","yearly"].map(v => `
          <button type="button" data-val="${v}" onclick="
            document.querySelectorAll('#edit-billing-cycle button').forEach(b=>{b.style.background='var(--surface2)';b.style.borderColor='var(--border)';b.style.color='var(--text-muted)'});
            this.style.background='var(--green-dim)';this.style.borderColor='var(--green)';this.style.color='var(--green)';
            updateEditFee(this.dataset.val)"
            style="padding:8px 18px;border-radius:8px;border:1px solid;font-size:13px;font-weight:600;cursor:pointer;
              background:${v===cycle?'var(--green-dim)':'var(--surface2)'};
              border-color:${v===cycle?'var(--green)':'var(--border)'};
              color:${v===cycle?'var(--green)':'var(--text-muted)'}"
          >${v.charAt(0).toUpperCase()+v.slice(1)}</button>`).join("")}
      </div>
    </div>
    <div id="fee-monthly" style="margin-top:20px;display:${cycle==='monthly'?'block':'none'}">
      <div class="field">
        <label style="display:block;margin-bottom:8px">Monthly Fee</label>
        ${feeInput("edit-monthly-fee", m?.monthly_fee||29)}
      </div>
    </div>
    <div id="fee-yearly" style="margin-top:20px;display:${cycle==='yearly'?'block':'none'}">
      <div class="field">
        <label style="display:block;margin-bottom:8px">Yearly Fee</label>
        ${feeInput("edit-yearly-fee", m?.yearly_fee||290)}
      </div>
    </div>
    <div class="modal-actions">
      <button class="btn-sm" onclick="closeModal()">Cancel</button>
      <button class="btn-primary" onclick="submitEditMerchant('${id}')">Save</button>
    </div>
  `);
}

async function submitEditMerchant(id) {
  try {
    const getToggle = groupId => {
      const active = document.querySelector(`#${groupId} button[style*="var(--green-dim)"]`);
      return active?.dataset?.val || document.querySelector(`#${groupId} button`)?.dataset?.val;
    };
    await api("PATCH", `/api/owner/merchants/${id}`, {
      billingCycle: getToggle("edit-billing-cycle"),
      monthlyFee: parseFloat(document.getElementById("edit-monthly-fee").value.replace(/,/g,"")) || 0,
      yearlyFee: parseFloat(document.getElementById("edit-yearly-fee").value.replace(/,/g,"")) || 0,
    });
    closeModal(); loadMerchants();
    toast("Merchant updated");
  } catch (e) { toast(e.message, "error"); }
}

function billingToggle(id, cycle) {
  // Update pill buttons
  const grp = document.getElementById(`billing-cycle-toggle-${id}`);
  if (!grp) return;
  grp.querySelectorAll("button").forEach(btn => {
    const active = btn.dataset.val === cycle;
    btn.style.background = active ? "var(--green)" : "transparent";
    btn.style.color = active ? "#fff" : "var(--text-muted)";
  });
  // Show/hide fee inputs
  const monthly = document.getElementById(`billing-fee-monthly-${id}`);
  const yearly  = document.getElementById(`billing-fee-yearly-${id}`);
  if (monthly) monthly.style.display = cycle === "monthly" ? "" : "none";
  if (yearly)  yearly.style.display  = cycle === "yearly"  ? "" : "none";
}

async function saveBilling(id) {
  try {
    const grp = document.getElementById(`billing-cycle-toggle-${id}`);
    const activeBtn = grp?.querySelector("button[style*='var(--green)']");
    const cycle = activeBtn?.dataset?.val || "monthly";
    await api("PATCH", `/api/owner/merchants/${id}`, {
      billingCycle: cycle,
      monthlyFee: parseFloat(document.getElementById(`billing-monthly-fee-${id}`)?.value.replace(/,/g,"")) || 0,
      yearlyFee:  parseFloat(document.getElementById(`billing-yearly-fee-${id}`)?.value.replace(/,/g,"")) || 0,
    });
    loadMerchants();
    toast("Billing updated");
  } catch (e) { toast(e.message, "error"); }
}

// ── Pipeline ───────────────────────────────────────────────
function timeAgo(dateStr) {
  if (!dateStr) return "—";
  const diff = Date.now() - new Date(dateStr);
  const mins  = Math.floor(diff / 60000);
  const hours = Math.floor(diff / 3600000);
  const days  = Math.floor(diff / 86400000);
  if (mins < 2)   return "Just now";
  if (mins < 60)  return `${mins}m ago`;
  if (hours < 24) return `${hours}h ago`;
  if (days === 1) return "Yesterday";
  if (days < 7)   return `${days}d ago`;
  return new Date(dateStr).toLocaleDateString("en-US", { month: "short", day: "numeric" });
}

let _pipelineTab = "applied";
let _pipelineData = { requests: [], merchants: [] };

const PIPELINE_STAGES = [
  { id: "applied",    label: "Applied",         color: "#3b82f6", bg: "#3b82f618" },
  { id: "trial",      label: "Trial",            color: "#8b5cf6", bg: "#8b5cf618" },
  { id: "pending",    label: "Pending Payment",  color: "#f59e0b", bg: "#f59e0b18" },
  { id: "bot_issues", label: "Bot Issues",       color: "#ef4444", bg: "#ef444418" },
  { id: "active",     label: "Active",           color: "#25d366", bg: "#25d36618" },
];

async function loadPipeline() {
  const grid = document.getElementById("pipeline-grid");
  const tabsEl = document.getElementById("pipeline-tabs");
  if (!grid || !tabsEl) return;
  grid.innerHTML = `<div style="color:var(--text-muted);font-size:13px;padding:20px">Loading…</div>`;

  try {
    const [{ requests = [] }, { merchants = [] }] = await Promise.all([
      api("GET", "/api/owner/onboarding"),
      api("GET", "/api/owner/merchants"),
    ]);
    _pipelineData = { requests, merchants };

    // Update nav badge with pending applications count
    const badge = document.getElementById("merchant-requests-badge");
    if (badge) { badge.textContent = requests.length; badge.style.display = requests.length > 0 ? "" : "none"; }

    renderPipelineOverview();
    renderPipelineTabs();
    renderPipelineGrid();
  } catch (e) {
    grid.innerHTML = `<div style="color:var(--red);padding:20px">${e.message}</div>`;
  }
}

function renderPipelineOverview() {
  const el = document.getElementById("pipeline-overview");
  if (!el) return;
  el.innerHTML = PIPELINE_STAGES.map(s => {
    const count = getPipelineItems(s.id).length;
    return `<div class="poverview-card" onclick="switchPipelineTab('${s.id}')" style="cursor:pointer">
      <div class="poverview-dot" style="background:${s.color}"></div>
      <div>
        <div class="poverview-count" style="color:${s.color}">${count}</div>
        <div class="poverview-label">${s.label}</div>
      </div>
    </div>`;
  }).join("");
}

function getPipelineItems(tab) {
  const { requests, merchants } = _pipelineData;
  if (tab === "applied")    return requests;
  if (tab === "trial")      return merchants.filter(m => m.subscription_status === "trial" && m.bot_status !== "error");
  if (tab === "pending")    return merchants.filter(m => m.subscription_status === "pending")
                                            .sort((a, b) => {
                                              const dA = getRenewalDate(a); const dB = getRenewalDate(b);
                                              return (dA ? dA.getTime() : 0) - (dB ? dB.getTime() : 0);
                                            });
  if (tab === "bot_issues") return merchants.filter(m => m.bot_status === "error");
  if (tab === "active")     return merchants.filter(m => m.subscription_status === "paid" && m.status === "active" && m.bot_status !== "error");
  return [];
}

function renderPipelineTabs() {
  const tabsEl = document.getElementById("pipeline-tabs");
  if (!tabsEl) return;
  tabsEl.innerHTML = PIPELINE_STAGES.map(s => {
    const count = getPipelineItems(s.id).length;
    const isActive = _pipelineTab === s.id;
    return `<button class="pill-btn ${isActive ? "active" : ""}"
      onclick="switchPipelineTab('${s.id}')"
      style="${isActive ? `background:${s.bg};border-color:${s.color}40;color:${s.color}` : ""}">
      ${s.label}
      <span style="display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;
        background:${isActive ? "rgba(255,255,255,0.18)" : "var(--surface2)"};
        border-radius:9px;font-size:10px;font-weight:700;margin-left:5px">${count}</span>
    </button>`;
  }).join("");
}

function switchPipelineTab(tab) {
  _pipelineTab = tab;
  renderPipelineTabs();
  renderPipelineGrid();
}

function renderPipelineGrid() {
  const grid = document.getElementById("pipeline-grid");
  if (!grid) return;
  const now = Date.now();
  const items = getPipelineItems(_pipelineTab);

  if (!items.length) {
    grid.innerHTML = `<div class="empty"><div class="empty-icon">✓</div><div class="empty-text">Nothing here right now</div></div>`;
    return;
  }

  const renders = { applied: renderPipelineApplied, trial: renderPipelineTrial, pending: renderPipelinePending, bot_issues: renderPipelineBotIssue, active: renderPipelineActive };
  grid.innerHTML = items.map(item => renders[_pipelineTab](item, now)).join("");
}

function pipelineAvatar(name, color, bg) {
  const initials = name.split(" ").map(w=>w[0]).join("").slice(0,2).toUpperCase();
  return `<div class="pipeline-avatar" style="background:${bg};color:${color}">${initials}</div>`;
}

function stuckBadge(days, warn = 3, danger = 7) {
  if (days < warn) return "";
  const col = days >= danger ? "var(--red)" : "#f59e0b";
  return `<span class="pipeline-stuck" style="background:${col}18;color:${col}">⏱ Waiting ${days}d</span>`;
}

function waLinkHtml(number) {
  if (!number) return "—";
  const clean = number.replace(/\D/g, "");
  if (!clean) return "—";
  const icon = `<svg width="13" height="13" viewBox="0 0 24 24" fill="#25d366" style="flex-shrink:0;margin-right:4px;vertical-align:middle" xmlns="http://www.w3.org/2000/svg"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.124.558 4.122 1.532 5.857L.054 23.5a.5.5 0 0 0 .609.61l5.805-1.522A11.95 11.95 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.885 0-3.65-.51-5.17-1.402l-.36-.214-3.862 1.013 1.03-3.763-.235-.375A9.96 9.96 0 0 1 2 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/></svg>`;
  return `<a href="https://wa.me/${clean}" target="_blank" style="color:#25d366;text-decoration:none;font-weight:600;display:inline-flex;align-items:center">${icon}${number}</a>`;
}

function formatDaysAhead(days) {
  if (days === null || days === undefined) return "—";
  if (days <= 0) return "Today";
  if (days < 30) return `${days}d`;
  if (days < 365) return `~${Math.round(days / 30)}mo`;
  return `~${Math.round(days / 365)}yr`;
}

function renderPipelineApplied(r, now) {
  const daysWaiting = Math.floor((now - new Date(r.created_at)) / 86400000);
  const appliedAt   = timeAgo(r.created_at);
  const waClean = r.whatsapp ? r.whatsapp.replace(/\D/g, "") : "";
  const waIcon = `<svg width="13" height="13" viewBox="0 0 24 24" fill="#25d366" style="flex-shrink:0;margin-right:4px;vertical-align:middle" xmlns="http://www.w3.org/2000/svg"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.124.558 4.122 1.532 5.857L.054 23.5a.5.5 0 0 0 .609.61l5.805-1.522A11.95 11.95 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.885 0-3.65-.51-5.17-1.402l-.36-.214-3.862 1.013 1.03-3.763-.235-.375A9.96 9.96 0 0 1 2 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/></svg>`;
  const waLink = waClean
    ? `<a href="https://wa.me/${waClean}" target="_blank" style="color:#25d366;text-decoration:none;font-weight:600;display:inline-flex;align-items:center">${waIcon}${r.whatsapp}</a>`
    : "—";
  const locationLine = [r.street, r.city, r.region].filter(Boolean).join(", ");
  const stuck = stuckBadge(daysWaiting, 2, 5);
  const waitingColor = daysWaiting >= 5 ? "var(--red)" : daysWaiting >= 2 ? "#f59e0b" : "var(--text-muted)";
  const waitingText  = daysWaiting === 0 ? "Today" : daysWaiting === 1 ? "Yesterday" : `${daysWaiting}d ago`;
  return `
    <div class="pipeline-card">
      <div class="pipeline-card-top">
        ${pipelineAvatar(r.shop_name, "#3b82f6", "#3b82f618")}
        <div style="flex:1;min-width:0;overflow:hidden">
          <div class="pipeline-card-name">${r.shop_name}</div>
          ${r.contact_name ? `<div class="pipeline-card-sub">${r.contact_name}</div>` : ""}
        </div>
        <div style="font-size:10px;color:var(--text-muted);white-space:nowrap;flex-shrink:0;margin-left:8px">${appliedAt}</div>
      </div>
      ${r.message ? `<div class="pipeline-msg">"${r.message}"</div>` : ""}
      <div class="pipeline-meta">
        <div class="pipeline-meta-row">
          <span class="pipeline-meta-label">WhatsApp</span>
          <span class="pipeline-meta-val">${waLink}</span>
        </div>
        <div class="pipeline-meta-row">
          <span class="pipeline-meta-label">Business</span>
          <span class="pipeline-meta-val">${r.business_type || "—"}</span>
        </div>
        ${locationLine ? `<div class="pipeline-meta-row">
          <span class="pipeline-meta-label">Location</span>
          <span class="pipeline-meta-val" style="text-align:right;max-width:62%">${locationLine}</span>
        </div>` : ""}
        <div class="pipeline-meta-row">
          <span class="pipeline-meta-label">Waiting</span>
          <span class="pipeline-meta-val">
            ${stuck || `<span style="color:${waitingColor}">${waitingText}</span>`}
          </span>
        </div>
      </div>
      <div class="pipeline-actions" style="justify-content:space-between">
        <button class="pipeline-btn" style="color:var(--green);border-color:#25d36640" onclick="approveRequest('${r.id}','${r.shop_name}','${r.whatsapp||""}')">✓ Approve</button>
        <button class="pipeline-btn" style="color:var(--red);border-color:#ff4d4d40" onclick="rejectRequest('${r.id}')">✗ Reject</button>
      </div>
    </div>`;
}

function renderPipelineTrial(m, now) {
  const daysLeft = m.trial_ends_at ? Math.ceil((new Date(m.trial_ends_at) - now) / 86400000) : null;
  const expiring = daysLeft !== null && daysLeft <= 7;
  const joinedAt = timeAgo(m.created_at);
  const botColor = m.bot_status === "active" ? "var(--green)" : m.bot_status === "error" ? "var(--red)" : "var(--text-muted)";
  const botLabel = m.bot_status === "active" ? "● Running" : m.bot_status === "error" ? "● Error" : "● Not set up";
  return `
    <div class="pipeline-card">
      <div class="pipeline-card-top">
        ${pipelineAvatar(m.shop_name, "#8b5cf6", "#8b5cf618")}
        <div style="flex:1;min-width:0">
          <div class="pipeline-card-name">${m.shop_name}
            ${expiring && daysLeft > 0 ? `<span class="pipeline-stuck" style="background:#f59e0b18;color:#f59e0b">⚡ Expiring</span>` : ""}
          </div>
          <div class="pipeline-card-sub">${waLinkHtml(m.whatsapp_number)}</div>
        </div>
        <div style="font-size:10px;color:var(--text-muted);white-space:nowrap;flex-shrink:0;margin-left:8px">${joinedAt}</div>
      </div>
      <div class="pipeline-meta">
        <div class="pipeline-meta-row">
          <span class="pipeline-meta-label">Trial ends</span>
          <span class="pipeline-meta-val" style="color:${expiring ? "#f59e0b" : "var(--text)"}">
            ${daysLeft === null ? "—" : daysLeft <= 0 ? "Ended" : `${daysLeft}d left`}
          </span>
        </div>
        <div class="pipeline-meta-row">
          <span class="pipeline-meta-label">Bot</span>
          <span class="pipeline-meta-val" style="color:${botColor}">${botLabel}</span>
        </div>
        <div class="pipeline-meta-row">
          <span class="pipeline-meta-label">Billing cycle</span>
          <span class="pipeline-meta-val" style="text-transform:capitalize">${m.billing_cycle || "monthly"}</span>
        </div>
      </div>
      <div class="pipeline-actions" style="justify-content:space-between">
        <button class="pipeline-btn" style="color:#8b5cf6;border-color:#8b5cf640" onclick="openMerchantPanel('${m.id}')">Open</button>
        <div style="display:flex;gap:7px">
          ${m.bot_status === "error" ? `<button class="pipeline-btn" style="color:var(--red);border-color:#ff4d4d40" onclick="openMerchantPanel('${m.id}','bot')">Fix Bot</button>` : ""}
          ${expiring ? `<button class="pipeline-btn" style="color:#f59e0b;border-color:#f59e0b40" onclick="sendWelcomeWA('${m.shop_name}','${m.whatsapp_number||""}')">📨 Nudge</button>` : ""}
        </div>
      </div>
    </div>`;
}

function renderPipelinePending(m, now) {
  const rd = getRenewalDate(m);
  const daysOverdue = rd ? Math.ceil((now - rd) / 86400000) : null;
  const isOverdue = daysOverdue !== null && daysOverdue > 0;
  const isSuspended = m.status === "suspended";
  const fee = m.billing_cycle === "yearly" ? (m.yearly_fee || 0) : (m.monthly_fee || 0);
  const waClean = (m.whatsapp_number || "").replace(/\D/g, "");
  return `
    <div class="pipeline-card" style="${isOverdue ? "border-color:#ff4d4d30" : ""}">
      <div class="pipeline-card-top">
        ${pipelineAvatar(m.shop_name, "#f59e0b", "#f59e0b18")}
        <div style="flex:1;min-width:0">
          <div class="pipeline-card-name">${m.shop_name}
            ${isSuspended
              ? `<span class="pipeline-stuck" style="background:#ff4d4d18;color:var(--red)">Suspended</span>`
              : isOverdue ? `<span class="pipeline-stuck" style="background:#ff4d4d18;color:var(--red)">Overdue ${daysOverdue}d</span>` : ""}
          </div>
          <div class="pipeline-card-sub">${waLinkHtml(m.whatsapp_number)}</div>
        </div>
      </div>
      <div class="pipeline-meta">
        <div class="pipeline-meta-row">
          <span class="pipeline-meta-label">Amount due</span>
          <span class="pipeline-meta-val" style="color:#f59e0b">$${fee}</span>
        </div>
        <div class="pipeline-meta-row">
          <span class="pipeline-meta-label">Status</span>
          <span class="pipeline-meta-val" style="color:${isSuspended ? "var(--red)" : isOverdue ? "var(--red)" : "#f59e0b"}">
            ${isSuspended ? "Suspended" : isOverdue ? `Overdue ${daysOverdue}d` : rd ? `Due ${new Date(rd).toLocaleDateString("en-US",{month:"short",day:"numeric"})}` : "Awaiting payment"}
          </span>
        </div>
        <div class="pipeline-meta-row">
          <span class="pipeline-meta-label">Billing cycle</span>
          <span class="pipeline-meta-val" style="text-transform:capitalize">${m.billing_cycle || "monthly"}</span>
        </div>
      </div>
      <div class="pipeline-actions" style="justify-content:space-between">
        <button class="pipeline-btn" style="color:var(--green);border-color:#25d36640" onclick="markPaid('${m.id}','${m.shop_name}')">✓ Mark Paid</button>
        ${isSuspended
          ? `<button class="pipeline-btn" style="color:var(--green);border-color:#25d36640" onclick="activateMerchant('${m.id}')">▶ Activate</button>`
          : waClean ? `<button class="pipeline-btn" style="color:#f59e0b;border-color:#f59e0b40" onclick="sendOverdueReminder('${m.id}','${m.shop_name}','${m.whatsapp_number||""}')">📨 Remind</button>` : ""}
      </div>
    </div>`;
}

function renderPipelineBotIssue(m, now) {
  const subStatus = m.subscription_status || "unknown";
  const subColor  = subStatus === "paid" ? "var(--green)" : subStatus === "trial" ? "#8b5cf6" : "#f59e0b";
  const subLabel  = subStatus === "paid" ? "Active" : subStatus === "trial" ? "Trial" : subStatus;
  const errorMsg  = m.bot_error || "Unknown error";
  const joinedAt  = timeAgo(m.created_at);
  return `
    <div class="pipeline-card" style="border-color:#ef444430">
      <div class="pipeline-card-top">
        ${pipelineAvatar(m.shop_name, "#ef4444", "#ef444418")}
        <div style="flex:1;min-width:0">
          <div class="pipeline-card-name">${m.shop_name}</div>
          <div class="pipeline-card-sub">${waLinkHtml(m.whatsapp_number)}</div>
        </div>
        <div style="font-size:10px;color:var(--text-muted);white-space:nowrap;flex-shrink:0;margin-left:8px">${joinedAt}</div>
      </div>
      <div class="pipeline-msg" style="border-color:#ef444430;color:#ef4444">⚠ ${errorMsg}</div>
      <div class="pipeline-meta">
        <div class="pipeline-meta-row">
          <span class="pipeline-meta-label">Subscription</span>
          <span class="pipeline-meta-val" style="color:${subColor};text-transform:capitalize">${subLabel}</span>
        </div>
        <div class="pipeline-meta-row">
          <span class="pipeline-meta-label">Bot status</span>
          <span class="pipeline-meta-val" style="color:var(--red)">● Error</span>
        </div>
        <div class="pipeline-meta-row">
          <span class="pipeline-meta-label">Billing cycle</span>
          <span class="pipeline-meta-val" style="text-transform:capitalize">${m.billing_cycle || "monthly"}</span>
        </div>
      </div>
      <div class="pipeline-actions" style="justify-content:space-between">
        <button class="pipeline-btn" style="color:var(--red);border-color:#ef444440" onclick="openMerchantPanel('${m.id}','bot')">🔧 Fix Bot</button>
        <button class="pipeline-btn" style="color:var(--text-muted);border-color:var(--border)" onclick="openMerchantPanel('${m.id}')">Open</button>
      </div>
    </div>`;
}

function renderPipelineActive(m, now) {
  const lastPaid = m.last_payment_at ? new Date(m.last_payment_at).toLocaleDateString("en-US",{month:"short",day:"numeric"}) : "—";
  const rd = getRenewalDate(m);
  const daysToRenew = rd ? Math.ceil((rd - now) / 86400000) : null;
  const soonRenew = daysToRenew !== null && daysToRenew <= 7;
  const botOk = m.bot_status === "active";
  const botColor = botOk ? "var(--green)" : "var(--red)";
  const botLabel = botOk ? "● Running" : "● Error";
  const waClean = (m.whatsapp_number || "").replace(/\D/g, "");
  return `
    <div class="pipeline-card" style="${!botOk ? "border-color:#ff4d4d30" : ""}">
      <div class="pipeline-card-top">
        ${pipelineAvatar(m.shop_name, "#25d366", "#25d36618")}
        <div style="flex:1;min-width:0">
          <div class="pipeline-card-name">${m.shop_name}</div>
          <div class="pipeline-card-sub">${waLinkHtml(m.whatsapp_number)}</div>
        </div>
      </div>
      <div class="pipeline-meta">
        <div class="pipeline-meta-row">
          <span class="pipeline-meta-label">Last paid</span>
          <span class="pipeline-meta-val">${lastPaid}</span>
        </div>
        <div class="pipeline-meta-row">
          <span class="pipeline-meta-label">Renews in</span>
          <span class="pipeline-meta-val">
            ${soonRenew
              ? `<span class="pipeline-stuck" style="background:#f59e0b18;color:#f59e0b">⏰ ${formatDaysAhead(daysToRenew)}</span>`
              : `<span style="color:var(--text)">${daysToRenew === null ? "—" : formatDaysAhead(daysToRenew)}</span>`}
          </span>
        </div>
        <div class="pipeline-meta-row">
          <span class="pipeline-meta-label">Bot</span>
          <span class="pipeline-meta-val" style="color:${botColor}">${botLabel}</span>
        </div>
      </div>
      <div class="pipeline-actions" style="justify-content:space-between">
        <button class="pipeline-btn" style="color:var(--green);border-color:#25d36640" onclick="openMerchantPanel('${m.id}')">Open</button>
        <div style="display:flex;gap:7px">
          ${!botOk ? `<button class="pipeline-btn" style="color:var(--red);border-color:#ff4d4d40" onclick="openMerchantPanel('${m.id}','bot')">🔧 Fix Bot</button>` : ""}
          ${soonRenew && waClean ? `<button class="pipeline-btn" style="color:#f59e0b;border-color:#f59e0b40" onclick="window.open('https://wa.me/${waClean}','_blank')">📨 Notify</button>` : ""}
        </div>
      </div>
    </div>`;
}

// ── Billing tab ────────────────────────────────────────────
let _billingMerchants = [];
let _billingFilter = "";

function exportBillingCSV() {
  if (!_billingMerchants.length) { toast("No billing data to export", "error"); return; }
  const sym = localStorage.getItem("nt_currency") || "USD";
  const headers = ["Shop", "WhatsApp", "Plan", "Monthly Fee", "Yearly Fee", "Billing Cycle", "Subscription Status", "Last Payment", "Next Renewal", "Total Collected"];
  const rows = _billingMerchants.map(m => {
    const renewal = getRenewalDate(m);
    return [
      m.shop_name,
      m.whatsapp_number || "",
      m.plan || "basic",
      m.monthly_fee || 0,
      m.yearly_fee || 0,
      m.billing_cycle || "monthly",
      m.subscription_status || "",
      m.last_payment_at ? new Date(m.last_payment_at).toLocaleDateString() : "—",
      renewal ? new Date(renewal).toLocaleDateString() : "—",
      m.total_fees_collected || 0,
    ].map(v => `"${String(v).replace(/"/g, '""')}"`).join(",");
  });
  const csv = [headers.join(","), ...rows].join("\n");
  const blob = new Blob([csv], { type: "text/csv" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = `neotalab-billing-${new Date().toISOString().split("T")[0]}.csv`;
  a.click();
  URL.revokeObjectURL(url);
  toast("Billing exported!");
}

async function loadBilling() {
  try {
    const { merchants } = await api("GET", "/api/owner/merchants?limit=100");
    _billingMerchants = merchants;

    // Helper: get the fee for a merchant based on billing cycle
    const merchantFee = m => m.billing_cycle === "yearly"
      ? (m.yearly_fee || 0)
      : (m.monthly_fee || 0);

    // MRR = actual paid merchants only
    // Yearly subscribers contribute yearly_fee/12 to MRR
    const mrrMerchants = merchants.filter(m => m.subscription_status === "paid");
    const mrr = Math.round(mrrMerchants.reduce((s, m) => {
      const fee = m.billing_cycle === "yearly" ? (m.yearly_fee || 0) / 12 : (m.monthly_fee || 0);
      return s + fee;
    }, 0));
    const mrrCount = mrrMerchants.length;

    // Collected This Month = merchants whose last_payment_at is in the current calendar month/year
    // Use the actual fee they paid (yearly or monthly)
    const now = new Date();
    const thisMonth = now.getMonth();
    const thisYear = now.getFullYear();
    const collectedMerchants = merchants.filter(m => {
      if (!m.last_payment_at) return false;
      const d = new Date(m.last_payment_at);
      return d.getMonth() === thisMonth && d.getFullYear() === thisYear;
    });
    const collected = collectedMerchants.reduce((s, m) => s + merchantFee(m), 0);
    const monthName = now.toLocaleDateString("en-US", { month: "long" });

    // Pending — count + total dollar amount outstanding
    const pendingList = merchants.filter(m => m.subscription_status === "pending");
    const pendingAmount = pendingList.reduce((s, m) => s + merchantFee(m), 0);

    // Trial count
    const trialCount = merchants.filter(m => m.subscription_status === "trial").length;

    // ARR = MRR × 12
    const arr = Math.round(mrr * 12);

    // Collected This Year = merchants whose last_payment_at is in the current calendar year
    const yearMerchants = merchants.filter(m => {
      if (!m.last_payment_at) return false;
      return new Date(m.last_payment_at).getFullYear() === thisYear;
    });
    const yearCollected = yearMerchants.reduce((s, m) => s + merchantFee(m), 0);

    const fmt = n => "$" + n.toLocaleString("en-US");

    // Stats
    document.getElementById("b-mrr").textContent = `${fmt(mrr)}/mo`;
    document.getElementById("b-mrr-sub").textContent = `from ${mrrCount} paying merchant${mrrCount !== 1 ? "s" : ""}`;
    document.getElementById("b-arr").textContent = `${fmt(arr)}/yr`;
    document.getElementById("b-collected-label").textContent = `Collected · ${monthName} ${thisYear}`;
    document.getElementById("b-collected").textContent = fmt(collected);
    document.getElementById("b-collected-sub").textContent = `${collectedMerchants.length} payment${collectedMerchants.length !== 1 ? "s" : ""} received`;
    document.getElementById("b-year-label").textContent = `Collected · ${thisYear}`;
    document.getElementById("b-year").textContent = fmt(yearCollected);
    document.getElementById("b-year-sub").textContent = `${yearMerchants.length} payment${yearMerchants.length !== 1 ? "s" : ""} since Jan 1`;
    document.getElementById("b-overdue").textContent = fmt(pendingAmount);
    document.getElementById("b-overdue").style.color = pendingAmount > 0 ? "var(--orange)" : "var(--green)";
    document.getElementById("b-overdue-sub").textContent = pendingList.length > 0
      ? `${pendingList.length} merchant${pendingList.length !== 1 ? "s" : ""} awaiting payment`
      : "all merchants up to date";

    // Dynamic filter pills with counts
    const total = merchants.length;
    const paidCount = mrrCount;
    const pillsEl = document.getElementById("billing-pills");
    pillsEl.innerHTML = [
      { label: "All",     filter: "",         count: total },
      { label: "Paid",    filter: "paid",     count: paidCount },
      { label: "Pending", filter: "pending",  count: pendingList.length },
      { label: "Trial",   filter: "trial",    count: trialCount },
    ].map(p => `
      <button class="pill-btn ${_billingFilter === p.filter ? "active" : ""}"
        onclick="filterBilling(this,'${p.filter}')">
        ${p.label}
        <span style="
          display:inline-flex;align-items:center;justify-content:center;
          min-width:18px;height:18px;padding:0 5px;
          background:${_billingFilter === p.filter ? "rgba(255,255,255,0.2)" : "var(--surface2)"};
          border-radius:9px;font-size:10px;font-weight:700;margin-left:5px;
        ">${p.count}</span>
      </button>`).join("");

    // "Send All Reminders" button — show when overdue merchants exist
    const thresholdDays = parseInt(localStorage.getItem("nt_overdue_remind_days") || "3");
    const overdueCount = merchants.filter(m => {
      if (m.subscription_status !== "pending") return false;
      const rd = getRenewalDate(m);
      return rd && (Date.now() - rd) >= thresholdDays * 86400000;
    }).length;
    const reminderActionsEl = document.getElementById("billing-reminder-actions");
    if (reminderActionsEl) {
      reminderActionsEl.innerHTML = overdueCount > 0
        ? `<button onclick="sendAllOverdueReminders()" style="
            display:inline-flex;align-items:center;gap:6px;
            padding:6px 14px;border-radius:8px;font-size:12px;font-weight:700;
            background:#ff4d4d18;border:1px solid #ff4d4d40;color:var(--red);cursor:pointer
          ">📨 Send Overdue Reminders <span style="
            background:#ff4d4d;color:#fff;border-radius:10px;
            padding:0 6px;font-size:10px;font-weight:800
          ">${overdueCount}</span></button>`
        : "";
    }

    renderBillingTable();
  } catch (e) { console.error(e); }
}

function filterBilling(btn, filter) {
  _billingFilter = filter;
  // Update active state on pills
  document.querySelectorAll("#billing-pills .pill-btn").forEach(b => {
    const isActive = b === btn;
    b.classList.toggle("active", isActive);
    const badge = b.querySelector("span");
    if (badge) badge.style.background = isActive ? "rgba(255,255,255,0.2)" : "var(--surface2)";
  });
  renderBillingTable();
}

function getRenewalDate(m) {
  // Trial: use trial_ends_at regardless of current status
  // (covers both active trial and pending-from-trial where last_payment_at is null)
  if (m.trial_ends_at && !m.last_payment_at) {
    return new Date(m.trial_ends_at);
  }
  if (m.last_payment_at) {
    const d = new Date(m.last_payment_at);
    if (m.billing_cycle === "yearly") d.setFullYear(d.getFullYear() + 1);
    else d.setMonth(d.getMonth() + 1);
    return d;
  }
  return null;
}

function renderBillingTable() {
  const now = new Date();
  const sevenDays = 7 * 24 * 60 * 60 * 1000;

  let list = [..._billingMerchants];
  if (_billingFilter) {
    list = list.filter(m => m.subscription_status === _billingFilter);
  }

  // Sort by urgency
  const urgency = m => {
    const rd = getRenewalDate(m);
    const diff = rd ? rd - now : Infinity;
    const isPaid   = m.subscription_status === "paid";
    const isTrial  = m.subscription_status === "trial";
    const isPending = m.subscription_status === "pending";
    if (isPending && rd && diff < 0)              return 0; // OVERDUE
    if (isTrial  && rd && diff >= 0 && diff < sevenDays) return 1; // EXPIRING
    if (isPaid   && rd && diff >= 0 && diff < sevenDays) return 2; // SOON
    if (isPending)                                return 3; // Pending
    if (isTrial)                                  return 4; // Trial
    return 5;                                              // Paid
  };
  list.sort((a, b) => urgency(a) - urgency(b));

  const tbody = document.querySelector("#billing-table tbody");
  if (!list.length) {
    tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:32px">No merchants</td></tr>`;
    return;
  }

  tbody.innerHTML = list.map(m => {
    const isPaid   = m.subscription_status === "paid";
    const isTrial  = m.subscription_status === "trial";
    const isPending = m.subscription_status === "pending";
    const isYearly = m.billing_cycle === "yearly";
    const cycle    = isYearly ? "yr" : "mo";
    const fee      = isYearly ? (m.yearly_fee || 0) : (m.monthly_fee || 0);

    // Status pill
    const billingColor = isPaid ? "var(--green)" : isTrial ? "#3b82f6" : "var(--orange)";
    const trialDaysLeft = isTrial && m.trial_ends_at
      ? Math.ceil((new Date(m.trial_ends_at) - Date.now()) / 86400000) : null;
    const billingLabel = isPaid ? "✓ Paid"
      : isTrial ? `Trial — ${trialDaysLeft}d left`
      : "Pending";

    // Renewal date
    const renewalDate = getRenewalDate(m);
    const renewalStr = renewalDate
      ? renewalDate.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" })
      : "—";

    // Alert badge + row color
    let alertBadge = "";
    let renewalStyle = "color:var(--text-muted);font-size:12px";

    if (renewalDate) {
      const diff = renewalDate - now;
      if (isPending && diff < 0) {
        // Pending AND renewal date has passed → truly overdue
        alertBadge = `<span style="background:#ff4d4d22;color:var(--red);font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px;margin-left:6px;vertical-align:middle">OVERDUE</span>`;
        renewalStyle = "color:var(--red);font-size:12px;font-weight:600";
      } else if (isPaid && diff >= 0 && diff < sevenDays) {
        // Paid but renewal coming up soon
        alertBadge = `<span style="background:#f59e0b22;color:#f59e0b;font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px;margin-left:6px;vertical-align:middle">SOON</span>`;
        renewalStyle = "color:#f59e0b;font-size:12px;font-weight:600";
      } else if (isTrial && diff >= 0 && diff < sevenDays) {
        // Trial expiring within 7 days
        alertBadge = `<span style="background:#f59e0b22;color:#f59e0b;font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px;margin-left:6px;vertical-align:middle">EXPIRING</span>`;
        renewalStyle = "color:#f59e0b;font-size:12px;font-weight:600";
      } else if (isTrial) {
        renewalStyle = "color:#3b82f6;font-size:12px";
      }
    }

    const renewalCell = isTrial
      ? `<span style="${renewalStyle}">Trial ends ${renewalStr}</span>${alertBadge}`
      : `<span style="${renewalStyle}">${renewalStr}</span>${alertBadge}`;

    // Action column
    const isOverdue = isPending && renewalDate && (renewalDate - now) < 0;
    const waCleanBilling = (m.whatsapp_number || "").replace(/\D/g, "");
    const action = isPending
      ? `<div style="display:flex;flex-direction:column;gap:5px;align-items:flex-start">
           <button class="btn-sm" onclick="markPaid('${m.id}','${m.shop_name}')" style="color:var(--green);border-color:var(--green)">✓ Mark Paid</button>
           ${isOverdue && waCleanBilling ? `<button class="btn-sm" onclick="sendOverdueReminder('${m.id}','${m.shop_name}','${m.whatsapp_number||""}')" style="color:#f59e0b;border-color:#f59e0b40;font-size:10px">📨 Remind</button>` : ""}
         </div>`
      : "—";

    return `<tr>
      <td><strong>${m.shop_name}</strong></td>
      <td><strong>$${fee}/${cycle}</strong></td>
      <td>${renewalCell}</td>
      <td><span style="color:${billingColor};font-weight:600;font-size:12px">${billingLabel}</span></td>
      <td>${action}</td>
    </tr>`;
  }).join("");
}

// ── Reset merchant password ────────────────────────────────
function resetMerchantPassword(id, name) {
  openModal(`Reset Password — ${name}`, `
    <p style="color:var(--text-muted);font-size:13px;margin-bottom:16px">Set a new password for this merchant. They will need to use it on their next login.</p>
    <div class="field"><label>New Password</label><input type="password" id="rp-pass" placeholder="Min 8 characters"></div>
    <div class="field"><label>Confirm Password</label><input type="password" id="rp-confirm" placeholder="Repeat password"></div>
    <div class="modal-actions">
      <button class="btn-sm" onclick="closeModal()">Cancel</button>
      <button class="btn-primary" onclick="submitResetPassword('${id}','${name}')">Reset Password</button>
    </div>
  `);
}

async function submitResetPassword(id, name) {
  const pass = document.getElementById("rp-pass").value;
  const confirm = document.getElementById("rp-confirm").value;
  if (pass.length < 8) { toast("Password must be at least 8 characters", "error"); return; }
  if (pass !== confirm) { toast("Passwords don't match", "error"); return; }
  try {
    await api("PATCH", `/api/owner/merchants/${id}/reset-password`, { password: pass });
    closeModal();
    toast(`Password reset for ${name}`);
  } catch (e) { toast(e.message, "error"); }
}

// ── Join link helpers ──────────────────────────────────────
function getJoinUrl() { return window.location.origin + "/join"; }

function copyJoinLink() {
  const url = getJoinUrl();
  // Keep settings input in sync
  const settingsInput = document.getElementById("s-join-link");
  if (settingsInput) settingsInput.value = url;
  navigator.clipboard.writeText(url).then(() => {
    toast("Join link copied!");
    // Fancy UI feedback on the merchants page header button
    const btn = document.getElementById("copy-join-btn");
    const label = document.getElementById("copy-join-label");
    if (btn && label) {
      btn.style.borderColor = "var(--green)";
      btn.style.color = "var(--green)";
      const icon = document.getElementById("copy-join-icon");
      if (icon) icon.outerHTML = `<svg id="copy-join-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>`;
      label.textContent = "Copied!";
      setTimeout(() => {
        btn.style.borderColor = "";
        btn.style.color = "";
        const restored = document.getElementById("copy-join-icon");
        if (restored) restored.outerHTML = `<svg id="copy-join-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>`;
        label.textContent = "Copy Join Link";
      }, 2000);
    }
  }).catch(() => toast("Copy failed", "error"));
}

function shareJoinWA() {
  const url = getJoinUrl();
  const msg = `Join NeoTalab and automate your WhatsApp orders! 🚀\n\nFill in your details here and we'll set you up:\n${url}`;
  window.open(`https://wa.me/?text=${encodeURIComponent(msg)}`, "_blank");
}

// ── Send Welcome WhatsApp ──────────────────────────────────
function sendWelcomeWA(shopName, waNumber, otp) {
  const clean = (waNumber||"").replace(/\D/g,"");
  if (!clean) { toast("No WhatsApp number on file", "error"); return; }
  const loginUrl = window.location.origin + "/backoffice";
  const otpLine = otp ? `🔐 Activation Code: *${otp}*\n` : "";
  const msg = `Hello! 👋 Welcome to NeoTalab!\n\nYour merchant account for *${shopName}* is ready.\n\n🔗 Login here: ${loginUrl}\n📱 Username: ${waNumber}\n${otpLine}\nEnter this code to log in — you'll be asked to set your own password.\n\nNeed help? Just reply here! 🙌`;
  window.open(`https://wa.me/${clean}?text=${encodeURIComponent(msg)}`, "_blank");
}

// ── Broadcast ──────────────────────────────────────────────
function openBroadcast() {
  const recipientCount = _merchants.filter(m => m.status === "active" || m.subscription_status === "trial").length;
  openModal("Broadcast Message", `
    <div style="display:flex;align-items:center;gap:10px;background:var(--green-dim);border:1px solid #25d36630;border-radius:10px;padding:12px 16px;margin-bottom:20px">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
      <div>
        <div style="font-size:13px;font-weight:700;color:var(--green)">Sending to ${recipientCount} merchant${recipientCount !== 1 ? "s" : ""}</div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:1px">All active and trial merchants</div>
      </div>
    </div>
    <div class="field">
      <label>Your Message</label>
      <textarea id="bc-message" placeholder="e.g. We'll have a 2-hour maintenance window tonight at 11pm. Thank you for your patience!" style="min-height:130px"></textarea>
      <div style="display:flex;justify-content:flex-end;margin-top:4px"><span id="bc-char" style="font-size:11px;color:var(--text-muted)">0 characters</span></div>
    </div>
    <div id="bc-preview" style="display:none;background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:4px">
      <div style="font-size:10px;text-transform:uppercase;letter-spacing:0.8px;color:var(--text-muted);margin-bottom:8px">Preview</div>
      <div id="bc-preview-text" style="font-size:13px;line-height:1.6;white-space:pre-wrap"></div>
    </div>
    <div class="modal-actions">
      <button class="btn-sm" onclick="closeModal()">Cancel</button>
      <button class="btn-primary" onclick="submitBroadcast()" style="display:flex;align-items:center;gap:6px">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"/><path d="M22 2L15 22l-4-9-9-4 20-7z"/></svg>
        Send to ${recipientCount} Merchant${recipientCount !== 1 ? "s" : ""}
      </button>
    </div>
  `);
  document.getElementById("bc-message").addEventListener("input", e => {
    const val = e.target.value;
    document.getElementById("bc-char").textContent = `${val.length} character${val.length !== 1 ? "s" : ""}`;
    const prev = document.getElementById("bc-preview");
    const prevText = document.getElementById("bc-preview-text");
    if (val.trim()) { prev.style.display = "block"; prevText.textContent = val.trim(); }
    else prev.style.display = "none";
  });
}

async function submitBroadcast() {
  const message = document.getElementById("bc-message").value.trim();
  if (!message) { toast("Please enter a message", "error"); return; }
  confirmModal({
    title: "Send Broadcast",
    message: `Send this message to <strong>all active merchants</strong>?<br><br><em style="color:var(--text-muted)">"${message}"</em>`,
    confirmLabel: "Send to All",
    confirmClass: "btn-primary",
    onConfirm: async () => {
      try {
        await api("POST", "/api/owner/broadcast", { message });
        closeModal();
        toast("Broadcast sent to all active merchants!");
      } catch (e) { toast(e.message, "error"); }
    }
  });
}

// ── Onboarding approve / reject ────────────────────────────
function approveRequest(id, shopName, waNumber) {
  openModal(`Approve — ${shopName}`, `
    <p style="color:var(--text-muted);font-size:13px;margin-bottom:18px;line-height:1.5">
      Create a merchant account for <strong>${shopName}</strong> and start their trial period.
    </p>
    <div class="field">
      <label>Initial password <span style="color:var(--text-muted);font-size:11px">(merchant must change on first login)</span></label>
      <input id="approve-pass" type="password" placeholder="Min 8 characters">
    </div>
    <div class="field" style="background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:14px;display:flex;align-items:center;justify-content:space-between">
      <div>
        <div style="font-size:13px;font-weight:600">Start 7-day trial</div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:2px">No charge until trial ends</div>
      </div>
      <input type="checkbox" id="approve-trial" checked style="width:18px;height:18px;cursor:pointer;accent-color:var(--green)">
    </div>
    <div class="modal-actions">
      <button class="btn-sm" onclick="closeModal()">Cancel</button>
      <button class="btn-primary" onclick="submitApprove('${id}','${shopName}','${(waNumber||"").replace(/'/g,"\\'")}')">Approve & Create</button>
    </div>
  `);
}

async function submitApprove(id, shopName, waNumber) {
  const password = document.getElementById("approve-pass")?.value || "";
  if (password.length < 8) { toast("Password must be at least 8 characters", "error"); return; }
  const trial = document.getElementById("approve-trial")?.checked ?? true;
  const trialEndsAt = trial ? new Date(Date.now() + 7*86400000).toISOString().split("T")[0] : null;
  try {
    await api("POST", `/api/owner/onboarding/${id}/approve`, {
      password, subscriptionStatus: trial ? "trial" : "pending", trialEndsAt, forcePasswordChange: true,
    });
    closeModal();
    toast(`${shopName} approved! Account created.`);
    loadMerchants();
  } catch (e) { toast(e.message, "error"); }
}

function rejectRequest(id) {
  confirmModal({
    title: "Reject Request",
    message: "Reject and permanently delete this onboarding request?",
    confirmLabel: "Reject",
    confirmClass: "btn-danger",
    onConfirm: async () => {
      try {
        await api("DELETE", `/api/owner/onboarding/${id}`);
        toast("Request rejected");
        loadMerchants();
      } catch (e) { toast(e.message, "error"); }
    }
  });
}

// ── Analytics ──────────────────────────────────────────────
async function loadAnalytics() {
  const currency = localStorage.getItem("nt_currency") || "USD";
  const sym = currency === "USD" ? "$" : currency === "EUR" ? "€" : currency === "GBP" ? "£" : currency + " ";

  try {
    const [{ stats }, { merchants }, { orderTrend, feeTrend, merchantsByStatus, subscription }] = await Promise.all([
      api("GET", "/api/owner/stats"),
      api("GET", "/api/owner/merchants?limit=100"),
      api("GET", "/api/owner/analytics"),
    ]);

    // KPI cards
    const active = merchants.filter(m => m.status === "active" && m.subscription_status === "paid").length;
    const trial  = merchants.filter(m => m.subscription_status === "trial").length;
    const mrr    = subscription?.mrr || stats?.mrr || 0;
    const collected = subscription?.collected_this_month || 0;
    const overdue   = subscription?.overdue_count || stats?.overdueAccounts || 0;

    set("an-mrr",       `${sym}${mrr.toLocaleString()}`);
    set("an-mrr-sub",   `Yearly projection: ${sym}${(mrr * 12).toLocaleString()}`);
    set("an-active",    active);
    set("an-active-sub",`${trial} on trial · ${merchants.length} total`);
    set("an-collected", `${sym}${collected.toLocaleString()}`);
    set("an-collected-sub", collected >= mrr ? "✅ On track" : `${sym}${(mrr - collected).toLocaleString()} remaining`);
    set("an-overdue",   overdue);
    set("an-overdue-sub", overdue > 0 ? "Action needed" : "All accounts current");

    // Fee collection bar chart
    renderBarChart("an-fee-chart", feeTrend, "month", "collected", sym);

    // Merchant status horizontal bars
    renderStatusBars("an-status-chart", merchantsByStatus, merchants.length);

    // Order trend line chart
    renderLineChart("an-orders-chart", orderTrend, "orders", "var(--green)", "orders");

    // Revenue trend line chart
    renderLineChart("an-revenue-chart", orderTrend, "revenue", "#818cf8", sym);

  } catch(e) { console.error("Analytics error", e); }
}

function set(id, val) {
  const el = document.getElementById(id);
  if (el) el.textContent = val;
}

function renderBarChart(containerId, data, labelKey, valueKey, sym = "$") {
  const el = document.getElementById(containerId);
  if (!el || !data?.length) return;
  const values = data.map(d => parseFloat(d[valueKey]) || 0);
  const max = Math.max(...values, 1);
  el.innerHTML = `<div class="bar-chart">${data.map((d, i) => {
    const pct = Math.round((values[i] / max) * 100);
    return `<div class="bar-col">
      <div class="bar-val">${sym}${values[i]}</div>
      <div class="bar-fill" style="height:${Math.max(pct, 2)}%" title="${sym}${values[i]}"></div>
      <div class="bar-label">${d[labelKey]}</div>
    </div>`;
  }).join("")}</div>`;
}

function renderLineChart(containerId, data, valueKey, color, sym = "") {
  const el = document.getElementById(containerId);
  if (!el || !data?.length) return;
  const values = data.map(d => parseFloat(d[valueKey]) || 0);
  const max = Math.max(...values, 1);
  const min = Math.min(...values, 0);
  const range = max - min || 1;
  const W = 500, H = 120, padL = 8, padR = 8, padT = 8, padB = 24;
  const cW = W - padL - padR, cH = H - padT - padB;
  const pts = values.map((v, i) => {
    const x = padL + (i / (values.length - 1)) * cW;
    const y = padT + (1 - (v - min) / range) * cH;
    return `${x.toFixed(1)},${y.toFixed(1)}`;
  });
  const area = `${padL},${padT + cH} ${pts.join(" ")} ${padL + cW},${padT + cH}`;
  const uid = containerId;
  // X axis labels (show every ~5th)
  const labels = data.map((d, i) => {
    if (i % Math.ceil(data.length / 7) !== 0 && i !== data.length - 1) return "";
    const x = padL + (i / (values.length - 1)) * cW;
    const label = d.date ? d.date.slice(5) : d.month || i;
    return `<text x="${x.toFixed(1)}" y="${H - 4}" text-anchor="middle" fill="#666" font-size="8">${label}</text>`;
  }).join("");
  el.innerHTML = `<svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" style="width:100%;height:100%">
    <defs>
      <linearGradient id="lg-${uid}" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" stop-color="${color}" stop-opacity="0.25"/>
        <stop offset="100%" stop-color="${color}" stop-opacity="0"/>
      </linearGradient>
    </defs>
    <polygon points="${area}" fill="url(#lg-${uid})"/>
    <polyline points="${pts.join(" ")}" fill="none" stroke="${color}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
    ${labels}
  </svg>`;
}

function renderStatusBars(containerId, statusData, total) {
  const el = document.getElementById(containerId);
  if (!el || !statusData?.length) return;
  const colorMap = { active: "var(--green)", trial: "#3b82f6", pending: "var(--orange)", suspended: "var(--red)" };
  const t = Math.max(total, 1);
  el.innerHTML = `<div class="status-bars">${statusData.map(s => {
    const color = colorMap[s.status] || "#666";
    const count = parseInt(s.count) || 0;
    const pct = Math.round((count / t) * 100);
    return `<div class="sbar-row">
      <div class="sbar-meta">
        <span class="sbar-name" style="text-transform:capitalize">${s.status}</span>
        <span class="sbar-count" style="color:${color}">${count}</span>
      </div>
      <div class="sbar-track"><div class="sbar-fill" style="width:${pct}%;background:${color}"></div></div>
    </div>`;
  }).join("")}</div>`;
}

// ── Settings ───────────────────────────────────────────────
async function loadSettings() {
  // Local preferences
  const warnDays    = localStorage.getItem("nt_token_warn_days") || "7";
  const overdueDays = localStorage.getItem("nt_overdue_remind_days") || "3";
  const savedMsg    = localStorage.getItem("nt_overdue_msg");

  const tokenWarnEl = document.getElementById("s-token-warn");
  if (tokenWarnEl) tokenWarnEl.value = warnDays;

  const overdueSelectEl = document.getElementById("s-overdue-days");
  if (overdueSelectEl) overdueSelectEl.value = overdueDays;

  const overdueMsgEl = document.getElementById("s-overdue-msg");
  if (overdueMsgEl && savedMsg) overdueMsgEl.value = savedMsg;

  // Currency (local)
  const currency = localStorage.getItem("nt_currency") || "USD";
  const currEl = document.getElementById("s-currency");
  if (currEl) currEl.value = currency;

  // Join link — always build from current origin, no need to wait for API
  const joinLinkEl = document.getElementById("s-join-link");
  if (joinLinkEl) joinLinkEl.value = getJoinUrl();

  try {
    const { settings } = await api("GET", "/api/owner/settings");
    if (settings.subscriptionPrice)       document.getElementById("s-price").value              = settings.subscriptionPrice;
    if (settings.subscriptionYearlyPrice) document.getElementById("s-yearly-price").value        = settings.subscriptionYearlyPrice;
    if (settings.trialDays != null)       document.getElementById("s-trial-days").value          = settings.trialDays;
    if (settings.gracePeriodDays != null) { const el = document.getElementById("s-grace-days"); if (el) el.value = settings.gracePeriodDays; }
    if (settings.ownerName)               document.getElementById("s-owner-name").value          = settings.ownerName;
    if (settings.ownerEmail)              { const el = document.getElementById("s-owner-email"); if (el) el.value = settings.ownerEmail; }
    if (settings.platformName)            document.getElementById("s-platform-name").value       = settings.platformName;
    if (settings.currency)                { const el = document.getElementById("s-currency"); if (el) el.value = settings.currency; localStorage.setItem("nt_currency", settings.currency); }
    if (settings.timezone)                { const el = document.getElementById("s-timezone"); if (el) el.value = settings.timezone; }
    if (settings.joinLink)                document.getElementById("s-join-link").value           = settings.joinLink;
    if (settings.waPhoneId)               document.getElementById("s-wa-phone-id").value         = settings.waPhoneId;
    if (settings.waToken)                 document.getElementById("s-wa-token").value            = settings.waToken;
    if (settings.waVerifyToken)           document.getElementById("s-wa-verify").value           = settings.waVerifyToken;
    updateSavingsNote();
  } catch (e) { console.error(e); }
}

// Merged: overdue reminder + token warning saved together
async function saveNotificationSettings() {
  const overdueDays = document.getElementById("s-overdue-days").value;
  const tokenWarn   = document.getElementById("s-token-warn").value;
  const msg         = document.getElementById("s-overdue-msg").value.trim();
  if (!msg) { toast("Reminder message can't be empty", "error"); return; }
  localStorage.setItem("nt_overdue_remind_days", overdueDays);
  localStorage.setItem("nt_overdue_msg", msg);
  localStorage.setItem("nt_token_warn_days", tokenWarn);
  try {
    await api("PATCH", "/api/owner/settings", { overdueDays: parseInt(overdueDays), tokenWarnDays: parseInt(tokenWarn), overdueReminderMsg: msg });
  } catch(e) { /* silently continue — localStorage is the fallback */ }
  toast("Notification settings saved!");
}

// Legacy aliases (used by any remaining call sites)
function saveAlertSettings()    { saveNotificationSettings(); }
function saveReminderSettings() { saveNotificationSettings(); }

function buildReminderMessage(m) {
  const template = localStorage.getItem("nt_overdue_msg") ||
    `Hello {shop} 👋\n\nYour NeoTalab subscription is overdue by {days} day(s).\n\n💳 Amount due: ${"{amount}"}\n\nPlease settle your payment to keep your WhatsApp bot running smoothly. If you've already paid, let us know and we'll update your account.\n\nNeed help? Just reply here! 🙌`;
  const renewalDate = getRenewalDate(m);
  const daysOverdue = renewalDate ? Math.ceil((Date.now() - renewalDate) / 86400000) : "—";
  const fee = m.billing_cycle === "yearly" ? (m.yearly_fee || 0) : (m.monthly_fee || 0);
  return template
    .replace(/{shop}/g, m.shop_name)
    .replace(/{days}/g, daysOverdue)
    .replace(/{amount}/g, fee);
}

function sendOverdueReminder(id, name, waNumber) {
  const m = _billingMerchants.find(x => x.id === id) || _merchants.find(x => x.id === id);
  if (!m) { toast("Merchant not found", "error"); return; }
  const clean = (waNumber || "").replace(/\D/g, "");
  if (!clean) { toast("No WhatsApp number on file", "error"); return; }
  const msg = buildReminderMessage(m);
  window.open(`https://wa.me/${clean}?text=${encodeURIComponent(msg)}`, "_blank");
  toast(`Reminder opened for ${name}`);
}

function sendAllOverdueReminders() {
  const now = Date.now();
  const thresholdDays = parseInt(localStorage.getItem("nt_overdue_remind_days") || "3");
  const threshold = thresholdDays * 86400000;

  const overdue = _billingMerchants.filter(m => {
    if (m.subscription_status !== "pending") return false;
    const rd = getRenewalDate(m);
    return rd && (now - rd) >= threshold;
  });

  if (!overdue.length) { toast("No merchants meet the reminder threshold", "error"); return; }

  openModal(`Send Overdue Reminders (${overdue.length})`, `
    <p style="color:var(--text-muted);font-size:13px;margin-bottom:16px;line-height:1.5">
      The following merchants are overdue by ≥${thresholdDays} day(s). Click each to open WhatsApp with a pre-filled reminder.
    </p>
    <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px">
      ${overdue.map(m => {
        const rd = getRenewalDate(m);
        const daysOverdue = rd ? Math.ceil((Date.now() - rd) / 86400000) : "—";
        const clean = (m.whatsapp_number || "").replace(/\D/g, "");
        const msg = buildReminderMessage(m);
        const waUrl = `https://wa.me/${clean}?text=${encodeURIComponent(msg)}`;
        return `<div style="display:flex;align-items:center;justify-content:space-between;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:10px 14px">
          <div>
            <div style="font-weight:700;font-size:13px">${m.shop_name}</div>
            <div style="font-size:11px;color:var(--red);margin-top:2px">Overdue ${daysOverdue} day${daysOverdue !== 1 ? "s" : ""}</div>
          </div>
          <a href="${waUrl}" target="_blank" style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:#25d36618;border:1px solid #25d36640;border-radius:6px;color:#25d366;font-size:12px;font-weight:700;text-decoration:none">
            ${WA_LOGO} Send
          </a>
        </div>`;
      }).join("")}
    </div>
    <div class="modal-actions">
      <button class="btn-primary" onclick="closeModal()">Done</button>
    </div>
  `);
}

function showSettingsTab(tab) {
  const tabs = ["account", "platform", "pricing", "whatsapp", "notifications"];
  tabs.forEach(t => {
    document.getElementById("stab-" + t).classList.toggle("active", t === tab);
    document.getElementById("stab-content-" + t).style.display = t === tab ? "block" : "none";
  });
}

function togglePasswordForm() { /* no-op — password form is always visible in new design */ }

async function savePlatformSettings() {
  const name     = (document.getElementById("s-platform-name").value || "").trim();
  const currency = document.getElementById("s-currency").value;
  const timezone = document.getElementById("s-timezone")?.value || "UTC";
  if (!name) { toast("Platform name can't be empty", "error"); return; }
  localStorage.setItem("nt_currency", currency);
  try {
    await api("PATCH", "/api/owner/settings", { platformName: name, currency, timezone });
    toast("Platform settings saved!");
  } catch(e) { toast(e.message, "error"); }
}

// Legacy aliases kept for any lingering references
function savePlatformName() { savePlatformSettings(); }
function saveCurrency()     { savePlatformSettings(); }

async function saveOwnerProfile() {
  const name  = (document.getElementById("s-owner-name").value || "").trim();
  const email = (document.getElementById("s-owner-email")?.value || "").trim();
  if (!name) { toast("Name can't be empty", "error"); return; }
  try {
    await api("PATCH", "/api/owner/settings", { ownerName: name, ownerEmail: email });
    localStorage.setItem("nt_owner_name", name);
    const nameEl = document.getElementById("owner-name");
    if (nameEl) nameEl.textContent = name;
    toast("Profile saved!");
  } catch (e) { toast(e.message, "error"); }
}

// Legacy alias
async function saveOwnerName() { return saveOwnerProfile(); }

async function changeOwnerPassword() {
  const current = document.getElementById("s-current-pass").value;
  const newPass = document.getElementById("s-new-pass").value;
  const confirm = document.getElementById("s-confirm-pass").value;
  if (!current || !newPass) { toast("Fill in all fields", "error"); return; }
  if (newPass.length < 8) { toast("New password must be at least 8 characters", "error"); return; }
  if (newPass !== confirm) { toast("Passwords don't match", "error"); return; }
  try {
    await api("PATCH", "/api/owner/password", { currentPassword: current, newPassword: newPass });
    document.getElementById("s-current-pass").value = "";
    document.getElementById("s-new-pass").value = "";
    document.getElementById("s-confirm-pass").value = "";
    toast("Password updated successfully!");
  } catch (e) { toast(e.message, "error"); }
}

function updateSavingsNote() {
  const monthly = parseFloat(document.getElementById("s-price")?.value) || 0;
  const yearly  = parseFloat(document.getElementById("s-yearly-price")?.value) || 0;
  const note = document.getElementById("s-savings-note");
  if (!note) return;
  if (monthly > 0 && yearly > 0) {
    const saved = (monthly * 12) - yearly;
    const pct   = Math.round((saved / (monthly * 12)) * 100);
    note.textContent = saved > 0
      ? `Yearly saves merchants $${saved}/yr (${pct}% off — equivalent to ${Math.round(saved/monthly)} months free)`
      : saved === 0 ? "Yearly and monthly cost the same — consider offering a discount."
      : "⚠️ Yearly fee is more expensive than monthly × 12.";
  } else {
    note.textContent = "";
  }
}

async function savePricing() {
  const price       = parseFloat(document.getElementById("s-price").value);
  const yearlyPrice = parseFloat(document.getElementById("s-yearly-price").value);
  const trialDays   = parseInt(document.getElementById("s-trial-days").value) || 0;
  const graceDays   = parseInt(document.getElementById("s-grace-days")?.value) || 3;
  if (!price || price < 1) { toast("Enter a valid monthly price", "error"); return; }
  if (!yearlyPrice || yearlyPrice < 1) { toast("Enter a valid yearly price", "error"); return; }
  try {
    await api("PATCH", "/api/owner/settings", { subscriptionPrice: price, subscriptionYearlyPrice: yearlyPrice, trialDays, gracePeriodDays: graceDays });
    toast("Pricing saved!");
  } catch (e) { toast(e.message, "error"); }
}

async function saveWhatsAppConfig() {
  try {
    await api("PATCH", "/api/owner/settings", {
      waPhoneId: document.getElementById("s-wa-phone-id").value,
      waToken: document.getElementById("s-wa-token").value,
      waVerifyToken: document.getElementById("s-wa-verify").value,
    });
    toast("WhatsApp config saved!");
  } catch (e) { toast(e.message, "error"); }
}


// ── Helpers ────────────────────────────────────────────────

// ── Modal ──────────────────────────────────────────────────
function confirmModal({ title, message, confirmLabel = "Confirm", confirmClass = "btn-primary", onConfirm }) {
  openModal(title, `
    <p style="color:var(--text-muted);font-size:14px;margin-bottom:20px;line-height:1.5">${message}</p>
    <div class="modal-actions">
      <button class="btn-sm" onclick="closeModal()">Cancel</button>
      <button class="${confirmClass}" id="confirm-modal-btn">${confirmLabel}</button>
    </div>
  `);
  document.getElementById("confirm-modal-btn").onclick = () => { closeModal(); onConfirm(); };
}

function openModal(title, body) {
  document.getElementById("modal-title").textContent = title;
  document.getElementById("modal-body").innerHTML = body;
  document.getElementById("modal-overlay").style.display = "flex";
}
function closeModal() { document.getElementById("modal-overlay").style.display = "none"; }
document.getElementById("modal-close").addEventListener("click", closeModal);
document.getElementById("modal-overlay").addEventListener("click", e => { if (e.target===document.getElementById("modal-overlay")) closeModal(); });

// ── Globals ────────────────────────────────────────────────
window.toggleTokenVisibility = (id, btn) => { const el = document.getElementById(id); el.type = el.type==="password"?"text":"password"; btn.textContent = el.type==="password"?"Show":"Hide"; };
window.navigateTo = navigateTo;
window.openMerchantPanel = openMerchantPanel;
window.closePanel = closePanel;
window.toggleDropdown = toggleDropdown;
window.markPaid = markPaid;
window.impersonateMerchant = impersonateMerchant;
window.suspendMerchant = suspendMerchant;
window.activateMerchant = activateMerchant;
window.removeMerchant = removeMerchant;
window.editMerchant = editMerchant;
window.submitEditMerchant = submitEditMerchant;
window.submitAddMerchant = submitAddMerchant;
window.approveRequest = approveRequest;
window.submitApprove = submitApprove;
window.rejectRequest = rejectRequest;
window.closeModal = closeModal;
window.switchPanelTab = switchPanelTab;
window.testBotConnection = testBotConnection;
window.restartBot = restartBot;
window.saveBotCredentials = saveBotCredentials;
window.setBotMode = setBotMode;
window.scheduleRetry = scheduleRetry;
window.autoRetryBot = autoRetryBot;
window.reRegisterWebhook = reRegisterWebhook;
window.switchWhatsappNumber = switchWhatsappNumber;
window.submitSwitchNumber = submitSwitchNumber;
window.resetMerchantPassword = resetMerchantPassword;
window.submitResetPassword = submitResetPassword;
window.openBroadcast = openBroadcast;
window.openAddMerchantModal = openAddMerchantModal;
window.submitBroadcast = submitBroadcast;
window.showSettingsTab = showSettingsTab;
window.togglePasswordForm = togglePasswordForm;
window.copyJoinLink = copyJoinLink;
window.savePlatformSettings = savePlatformSettings;
window.savePlatformName = savePlatformName;
window.saveCurrency = saveCurrency;
window.saveOwnerName = saveOwnerName;
window.saveOwnerProfile = saveOwnerProfile;
window.changeOwnerPassword = changeOwnerPassword;
window.savePricing = savePricing;
window.updateSavingsNote = updateSavingsNote;
window.saveWhatsAppConfig = saveWhatsAppConfig;
window.saveNotificationSettings = saveNotificationSettings;
window.saveAlertSettings = saveAlertSettings;
window.saveReminderSettings = saveReminderSettings;
window.sendOverdueReminder = sendOverdueReminder;
window.sendAllOverdueReminders = sendAllOverdueReminders;
window.exportBillingCSV = exportBillingCSV;
window.loadPipeline = loadPipeline;
window.switchPipelineTab = switchPipelineTab;
window.fmtFeeInput = fmtFeeInput;
window.updateEditFee = updateEditFee;
window.billingToggle = billingToggle;
window.saveBilling = saveBilling;
window.endTrial = endTrial;
window.sendWelcomeWA = sendWelcomeWA;
window.shareJoinWA = shareJoinWA;
window.filterBilling = filterBilling;
