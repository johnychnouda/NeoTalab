/**
 * NeoTalab Merchant Backoffice — JS
 */

const API = "http://localhost:8787";
let token = localStorage.getItem("nt_merchant_token");
let shopProfile = {};
let _cachedProducts = [];
let _cachedCategories = [];
let _liveRefreshTimer = null;

// ── API helper ─────────────────────────────────────────────
async function api(method, path, body) {
  const opts = { method, headers: { "Content-Type": "application/json" } };
  if (token && token !== "demo") opts.headers["Authorization"] = `Bearer ${token}`;
  if (body) opts.body = JSON.stringify(body);
  try {
    const r = await fetch(API + path, opts);
    const data = await r.json();
    if (!r.ok) throw new Error(data.error || "Error");
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
  // Auth
  if (path.includes("/auth/merchant/login")) return { token: "demo", user: { shopName: "Joe's Snacks" } };

  if (path.includes("/settings/profile")) return { profile: { shop_name: "Joe's Snacks", shop_name_ar: "مطعم جو", ops_whatsapp: "+9613001001", mode: "auto", default_locale: "ar", wish_number: "+9613001001", wish_auto_confirm: true, wish_timeout_mins: 10 } };

  if (path.includes("/orders/live")) return { orders: [
    { id: "ord-0000001", status: "confirmed", customer_name: "Ali Hassan", customer_phone: "+9613100100", items: [{productName:"Shawarma x2",itemTotal:12}], subtotal: 12, delivery_fee: 1.5, total: 13.5, payment_method: "cash", eta_mins: 25, created_at: new Date().toISOString() },
    { id: "ord-0000002", status: "preparing", customer_name: "Rania K.", customer_phone: "+9613200200", items: [{productName:"Burger x1",itemTotal:8},{productName:"Pepsi x1",itemTotal:1.5}], subtotal: 9.5, delivery_fee: 1.5, total: 11, payment_method: "wish", eta_mins: 15, created_at: new Date(Date.now()-300000).toISOString() },
    { id: "ord-0000003", status: "assigned", customer_name: "Georges N.", customer_phone: "+9613300300", driver_name: "Rami K.", items: [{productName:"Pepsi x3",itemTotal:4.5}], subtotal: 4.5, delivery_fee: 1.5, total: 6, payment_method: "cash", eta_mins: 10, created_at: new Date(Date.now()-900000).toISOString() },
  ]};

  if (path.includes("/orders")) return { orders: [
    { id: "ord-0000004", status: "delivered", customer_name: "Ali Hassan", items: [{productName:"Shawarma x2"}], total: 13.5, payment_method: "cash", created_at: new Date(Date.now()-3600000).toISOString() },
    { id: "ord-0000005", status: "delivered", customer_name: "Rania K.", items: [{productName:"Burger x1"}], total: 9.5, payment_method: "wish", created_at: new Date(Date.now()-7200000).toISOString() },
    { id: "ord-0000006", status: "cancelled", customer_name: "Maya S.", items: [{productName:"Pepsi x2"}], total: 3, payment_method: "cash", created_at: new Date(Date.now()-10800000).toISOString() },
    { id: "ord-0000007", status: "rejected", customer_name: "Hadi M.", items: [{productName:"Shawarma x1"}], total: 7.5, payment_method: "cash", created_at: new Date(Date.now()-14400000).toISOString() },
  ]};

  if (path.includes("/menu/categories")) return { categories: [
    { id: "cat1", name: "Sandwiches", name_ar: "سندويشات", product_count: 2, is_active: true },
    { id: "cat2", name: "Drinks", name_ar: "مشروبات", product_count: 1, is_active: true },
    { id: "cat3", name: "Sides", name_ar: "مقبلات", product_count: 1, is_active: true },
  ]};

  if (path.includes("/menu/products")) return { products: [
    { id: "p1", name: "Shawarma", name_ar: "شاورما", price: 6, category_id: "cat1", category_name: "Sandwiches", is_active: true, is_sold_out: false, prep_time_mins: 10 },
    { id: "p2", name: "Burger", name_ar: "برغر", price: 8, category_id: "cat1", category_name: "Sandwiches", is_active: true, is_sold_out: false, prep_time_mins: 12 },
    { id: "p3", name: "Pepsi", name_ar: "بيبسي", price: 1.5, category_id: "cat2", category_name: "Drinks", is_active: true, is_sold_out: false, prep_time_mins: 0 },
    { id: "p4", name: "French Fries", name_ar: "بطاطا مقلية", price: 3, category_id: "cat3", category_name: "Sides", is_active: true, is_sold_out: true, prep_time_mins: 8 },
  ]};

  if (path.includes("/drivers")) return { drivers: [
    { id: "d1", name: "Rami Khalil", phone: "+9613400100", status: "on_duty", availability: "available", total_deliveries: 48, cash_on_hand: 35, is_active: true },
    { id: "d2", name: "Walid Nassar", phone: "+9613400200", status: "off_duty", availability: "available", total_deliveries: 22, cash_on_hand: 0, is_active: true },
    { id: "d3", name: "Hassan Ali", phone: "+9613400300", status: "on_duty", availability: "busy", total_deliveries: 15, cash_on_hand: 12, is_active: true },
  ]};

  if (path.includes("/customers")) return { customers: [
    { id: "c1", name: "Ali Hassan", phone: "+9613100100", total_orders: 12, total_spent: 145.5, is_blocked: false },
    { id: "c2", name: "Rania K.", phone: "+9613200200", total_orders: 5, total_spent: 62, is_blocked: false },
    { id: "c3", name: "Georges N.", phone: "+9613300300", total_orders: 1, total_spent: 6, is_blocked: false },
    { id: "c4", name: "Maya Salameh", phone: "+9613400400", total_orders: 8, total_spent: 98, is_blocked: true },
  ]};

  if (path.includes("/analytics/dashboard")) return {
    summary: { completed_orders: 128, cancelled_orders: 8, rejected_orders: 3, gross_revenue: 1640.5, avg_order_value: 12.8, avg_response_mins: 2.3 },
    topProducts: [
      {product_name:"Shawarma", qty:89, revenue:534},
      {product_name:"Burger", qty:54, revenue:432},
      {product_name:"Pepsi", qty:48, revenue:72},
      {product_name:"French Fries", qty:32, revenue:96},
    ],
    driverStats: [
      {name:"Rami Khalil", deliveries:48, avg_delivery_mins:22},
      {name:"Walid Nassar", deliveries:22, avg_delivery_mins:25},
      {name:"Hassan Ali", deliveries:15, avg_delivery_mins:28},
    ]
  };

  if (path.includes("/settings/hours")) return { hours: Array.from({length:7},(_,i)=>({day_of_week:i, opens_at:"09:00:00", closes_at:"23:00:00", is_closed: i===0})) };

  if (path.includes("/settings/zones")) return { zones: [
    { id: "z1", name: "Jounieh Center", delivery_fee: 1.5, minimum_order: 5 },
    { id: "z2", name: "Zouk Mosbeh", delivery_fee: 2, minimum_order: 8 },
    { id: "z3", name: "Dbayeh", delivery_fee: 2.5, minimum_order: 10 },
  ]};

  // Default: no-op success for PATCH/POST/DELETE
  return { success: true };
}

// ── Toast ──────────────────────────────────────────────────
function toast(message, type = "success") {
  const el = document.createElement("div");
  el.className = `bo-toast ${type}`;
  el.innerHTML = `<span>${type === "success" ? "✅" : "❌"}</span> ${message}`;
  document.getElementById("toast-container").appendChild(el);
  requestAnimationFrame(() => requestAnimationFrame(() => el.classList.add("show")));
  setTimeout(() => { el.classList.remove("show"); setTimeout(() => el.remove(), 350); }, 3000);
}

// ── Confirm modal ───────────────────────────────────────────
function confirmAction(title, message, onConfirm, danger = false) {
  openModal(title, `
    <p style="color:var(--text-muted);font-size:14px;line-height:1.5;margin-bottom:20px">${message}</p>
    <div class="modal-actions">
      <button class="btn-sm" onclick="closeModal()">Cancel</button>
      <button class="${danger ? "btn-sm" : "btn-primary"}" id="confirm-action-btn" style="${danger ? "color:var(--red);border-color:#ff4d4d40" : ""}">Confirm</button>
    </div>
  `);
  document.getElementById("confirm-action-btn").onclick = () => { closeModal(); onConfirm(); };
}

// ── Auth ───────────────────────────────────────────────────
document.getElementById("login-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const btn = document.getElementById("login-btn");
  const errEl = document.getElementById("login-error");
  btn.disabled = true;
  btn.textContent = "Signing in...";
  errEl.style.display = "none";
  try {
    const data = await api("POST", "/auth/merchant/login", {
      whatsappNumber: document.getElementById("login-whatsapp").value,
      password: document.getElementById("login-password").value,
    });
    token = data.token || "demo";
    localStorage.setItem("nt_merchant_token", token);
    if (data.user?.forcePasswordChange) {
      document.getElementById("login-screen").style.display = "none";
      document.getElementById("force-change-screen").style.display = "flex";
      localStorage.setItem("nt_pending_shop_name", data.user?.shopName || "");
    } else {
      showApp(data.user?.shopName);
    }
  } catch (err) {
    errEl.textContent = err.message;
    errEl.style.display = "block";
    btn.disabled = false;
    btn.textContent = "Sign in";
  }
});

document.getElementById("force-change-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const btn = document.getElementById("fcp-btn");
  const errEl = document.getElementById("fcp-error");
  const newPass = document.getElementById("fcp-new").value;
  const confirm = document.getElementById("fcp-confirm").value;
  errEl.style.display = "none";
  if (newPass.length < 8) { errEl.textContent = "Password must be at least 8 characters."; errEl.style.display = "block"; return; }
  if (newPass !== confirm) { errEl.textContent = "Passwords don't match."; errEl.style.display = "block"; return; }
  btn.disabled = true; btn.textContent = "Saving…";
  try {
    await api("PATCH", "/api/merchant/change-password", { newPassword: newPass, forceChange: true });
    const shopName = localStorage.getItem("nt_pending_shop_name") || "";
    localStorage.removeItem("nt_pending_shop_name");
    document.getElementById("force-change-screen").style.display = "none";
    showApp(shopName);
  } catch (err) {
    errEl.textContent = err.message || "Failed to update password.";
    errEl.style.display = "block";
    btn.disabled = false; btn.textContent = "Set Password & Continue";
  }
});

document.getElementById("logout-btn").addEventListener("click", () => {
  token = null;
  localStorage.removeItem("nt_merchant_token");
  localStorage.removeItem("nt_pending_shop_name");
  if (_liveRefreshTimer) clearInterval(_liveRefreshTimer);
  document.getElementById("app").style.display = "none";
  document.getElementById("force-change-screen").style.display = "none";
  document.getElementById("login-screen").style.display = "flex";
});

function showApp(shopName) {
  document.getElementById("login-screen").style.display = "none";
  document.getElementById("app").style.display = "flex";
  if (shopName) {
    const nameEl = document.getElementById("shop-name-nav");
    if (nameEl) nameEl.textContent = shopName;
    localStorage.setItem("nt_shop_name", shopName);
  }
  applyI18n();
  navigateTo("orders");
  loadProfile();
  startClock();
  // Auto-refresh live orders every 30 seconds
  if (_liveRefreshTimer) clearInterval(_liveRefreshTimer);
  _liveRefreshTimer = setInterval(() => {
    if (document.querySelector("#page-orders.active")) loadLiveOrders();
  }, 30000);
}

// Auto-login if token exists
if (token) showApp(localStorage.getItem("nt_shop_name") || "My Shop");

// ── Clock ──────────────────────────────────────────────────
function startClock() {
  const el = document.getElementById("orders-clock");
  if (!el) return;
  const tick = () => el.textContent = new Date().toLocaleTimeString("en-US", { hour:"2-digit", minute:"2-digit", second:"2-digit" });
  tick();
  setInterval(tick, 1000);
}

// ── Navigation ─────────────────────────────────────────────
document.querySelectorAll(".nav-item").forEach(item => {
  item.addEventListener("click", (e) => { e.preventDefault(); navigateTo(item.dataset.page); });
});

function navigateTo(page) {
  document.querySelectorAll(".page").forEach(p => p.classList.remove("active"));
  document.querySelectorAll(".nav-item").forEach(n => n.classList.remove("active"));
  document.getElementById(`page-${page}`)?.classList.add("active");
  document.querySelector(`[data-page="${page}"]`)?.classList.add("active");
  const loaders = { orders: loadOrders, menu: loadMenu, drivers: loadDrivers, customers: loadCustomers, analytics: loadAnalytics, settings: loadSettings };
  loaders[page]?.();
}

document.getElementById("shop-mode").addEventListener("change", async (e) => {
  try { await api("PATCH", "/api/settings/mode", { mode: e.target.value }); }
  catch {}
});

async function loadProfile() {
  try {
    const { profile } = await api("GET", "/api/settings/profile");
    shopProfile = profile;
    document.getElementById("shop-name-nav").textContent = profile.shop_name;
    document.getElementById("shop-mode").value = profile.mode || "auto";
    localStorage.setItem("nt_shop_name", profile.shop_name);
  } catch {}
}

// ── ORDERS ─────────────────────────────────────────────────
async function loadOrders() {
  await Promise.all([loadLiveOrders(), loadOrderHistory()]);
}

async function loadLiveOrders() {
  const el = document.getElementById("live-orders");
  const badge = document.getElementById("live-orders-badge");
  try {
    const { orders } = await api("GET", "/api/orders/live");
    badge.textContent = orders.length;
    badge.style.display = orders.length ? "inline" : "none";
    if (!orders.length) {
      el.innerHTML = `<div class="empty-state"><div class="empty-icon">🟢</div><div>No active orders right now</div></div>`;
      return;
    }
    el.innerHTML = orders.map(o => renderOrderCard(o)).join("");
  } catch(e) { el.innerHTML = `<div class="empty-state">${e.message}</div>`; }
}

function renderOrderCard(o) {
  const statusColors = {
    confirmed: "#25d366", preparing: "#f59e0b", ready: "#3b82f6",
    assigned: "#8b5cf6", picked_up: "#ec4899", pending_payment: "#f59e0b"
  };
  const color = statusColors[o.status] || "#888";
  const items = (o.items||[]).map(i => i.productName || "").join(", ");
  const age = Math.floor((Date.now() - new Date(o.created_at)) / 60000);

  let actions = "";
  if (o.status === "pending_payment") {
    actions = `<button class="btn-primary btn-sm-action" onclick="acceptOrder('${o.id}')">✅ Accept</button>
               <button class="btn-sm" onclick="rejectOrder('${o.id}')">Reject</button>`;
  } else if (o.status === "confirmed") {
    actions = `<button class="btn-primary btn-sm-action" onclick="markPreparing('${o.id}')">🍳 Preparing</button>
               <button class="btn-sm btn-sm-red" onclick="cancelOrder('${o.id}')">Cancel</button>`;
  } else if (o.status === "preparing") {
    actions = `<button class="btn-primary btn-sm-action" onclick="markReady('${o.id}')">✅ Ready</button>`;
  } else if (o.status === "ready") {
    actions = `<span style="color:var(--blue);font-size:12px;font-weight:600">⏳ Awaiting driver pickup</span>`;
  } else if (o.status === "assigned") {
    actions = `<span style="color:#8b5cf6;font-size:12px;font-weight:600">🛵 Driver assigned</span>`;
  } else if (o.status === "picked_up") {
    actions = `<span style="color:#ec4899;font-size:12px;font-weight:600">🚀 On the way</span>`;
  }

  return `<div class="order-card" style="border-left:3px solid ${color}">
    <div class="order-card-header">
      <span class="order-id">#${o.id?.slice(-6).toUpperCase()}</span>
      <span style="color:${color};font-size:11px;font-weight:700;text-transform:uppercase">${o.status.replace(/_/g," ")}</span>
    </div>
    <div class="order-customer"><strong>${o.customer_name || o.customer_phone}</strong></div>
    <div class="order-items" style="font-size:12px;color:var(--text-muted);margin:4px 0">${items}</div>
    <div class="order-meta">
      <span>💰 $${parseFloat(o.total||0).toFixed(2)} · ${o.payment_method}</span>
      ${o.eta_mins ? `<span>⏱ ~${o.eta_mins}m</span>` : ""}
      ${o.driver_name ? `<span>🛵 ${o.driver_name}</span>` : ""}
      <span style="color:var(--text-muted)">${age}m ago</span>
    </div>
    <div class="order-card-actions">${actions}</div>
  </div>`;
}

async function acceptOrder(id)    { try { await api("POST", `/api/orders/${id}/accept`); loadLiveOrders(); } catch(e) { toast(e.message, "error"); } }
function rejectOrder(id) {
  openModal("Reject Order", `
    <div class="field"><label>Rejection reason <span style="color:var(--text-muted);font-weight:400">(optional)</span></label><input id="reject-reason" placeholder="e.g. Out of stock, kitchen closed…"></div>
    <div class="modal-actions">
      <button class="btn-sm" onclick="closeModal()">Cancel</button>
      <button class="btn-primary" onclick="submitRejectOrder('${id}')">Reject Order</button>
    </div>
  `);
}
async function submitRejectOrder(id) {
  const reason = document.getElementById("reject-reason")?.value || "";
  try { await api("POST", `/api/orders/${id}/reject`, { reason }); closeModal(); loadLiveOrders(); }
  catch(e) { toast(e.message, "error"); }
}
async function markPreparing(id)  { try { await api("POST", `/api/orders/${id}/preparing`, { extraMins: 0 }); loadLiveOrders(); } catch(e) { toast(e.message, "error"); } }
async function markReady(id)      { try { await api("POST", `/api/orders/${id}/ready`); loadLiveOrders(); } catch(e) { toast(e.message, "error"); } }
function cancelOrder(id) {
  confirmAction("Cancel Order", "Cancel this order? The customer will be notified via WhatsApp.", async () => {
    try { await api("POST", `/api/orders/${id}/cancel`); loadLiveOrders(); loadOrderHistory(); }
    catch(e) { toast(e.message, "error"); }
  }, true);
}

async function loadOrderHistory() {
  const date = document.getElementById("order-date").value;
  const status = document.getElementById("order-status-filter").value;
  let url = "/api/orders?limit=50";
  if (date) url += `&date=${date}`;
  if (status) url += `&status=${status}`;
  const tbody = document.querySelector("#orders-history-table tbody");
  tbody.innerHTML = `<tr><td colspan="7" class="loader">Loading...</td></tr>`;
  try {
    const { orders } = await api("GET", url);
    const hist = status
      ? orders
      : orders.filter(o => ["delivered","cancelled","rejected"].includes(o.status));
    tbody.innerHTML = hist.map(o => `<tr>
      <td style="font-family:monospace;font-size:11px">#${o.id?.slice(-6).toUpperCase()}</td>
      <td>${o.customer_name || "—"}</td>
      <td style="font-size:12px;color:var(--text-muted)">${(o.items||[]).map(i=>i.productName||"").join(", ").slice(0,40)}</td>
      <td>$${parseFloat(o.total||0).toFixed(2)}</td>
      <td style="text-transform:capitalize">${o.payment_method||"—"}</td>
      <td><span class="pill pill-${o.status==="delivered"?"active":o.status==="cancelled"?"suspended":"pending"}">${o.status}</span></td>
      <td style="color:var(--text-muted);font-size:12px">${new Date(o.created_at).toLocaleTimeString("en-US",{hour:"2-digit",minute:"2-digit"})}</td>
    </tr>`).join("") || `<tr><td colspan="7" class="loader">No past orders</td></tr>`;
  } catch(e) { tbody.innerHTML = `<tr><td colspan="7" style="color:var(--red);padding:16px">${e.message}</td></tr>`; }
}

document.getElementById("order-date").addEventListener("change", loadOrderHistory);
document.getElementById("order-status-filter").addEventListener("change", loadOrderHistory);

// ── MENU ───────────────────────────────────────────────────
async function loadMenu() {
  try {
    const [{ categories }, { products }] = await Promise.all([
      api("GET", "/api/menu/categories"),
      api("GET", "/api/menu/products?includeInactive=true"),
    ]);
    _cachedCategories = categories;
    _cachedProducts = products;
    const el = document.getElementById("menu-list");
    if (!categories.length) {
      el.innerHTML = `<div class="empty-state"><div class="empty-icon">🍽️</div><div>Add categories and products to build your menu</div></div>`;
      return;
    }
    el.innerHTML = categories.map(cat => {
      const prods = products.filter(p => p.category_id === cat.id || p.category_name === cat.name);
      return `<div class="menu-category">
        <div class="menu-cat-header">
          <span class="menu-cat-name">${cat.name}</span>
          ${cat.name_ar ? `<span style="color:var(--text-muted);font-size:12px;margin-left:8px" dir="rtl">${cat.name_ar}</span>` : ""}
          <span class="menu-cat-count">${prods.length} items</span>
          <button class="btn-sm" onclick="openAddProductModal('${cat.id}')">+ Product</button>
          <button class="btn-sm" onclick="editCategory('${cat.id}','${cat.name}','${cat.name_ar||""}')">Edit</button>
          <button class="btn-sm btn-sm-red" onclick="deleteCategory('${cat.id}','${cat.name}')">Delete</button>
        </div>
        <div class="menu-products">
          ${prods.map(p => `<div class="product-row ${!p.is_active ? "product-inactive" : ""}">
            <div class="product-info">
              <span class="product-name">${p.name}</span>
              ${p.name_ar ? `<span class="product-name-ar" dir="rtl">${p.name_ar}</span>` : ""}
            </div>
            <div class="product-meta">
              <span class="product-price">$${parseFloat(p.price).toFixed(2)}</span>
              ${p.prep_time_mins ? `<span style="font-size:11px;color:var(--text-muted)">⏱ ${p.prep_time_mins}m</span>` : ""}
              ${p.is_sold_out ? `<span class="pill pill-suspended" style="font-size:10px">Sold out</span>` : ""}
              ${!p.is_active ? `<span class="pill pill-pending" style="font-size:10px">Hidden</span>` : ""}
            </div>
            <div class="product-actions">
              <button class="btn-sm" onclick="toggleSoldOut('${p.id}',${p.is_sold_out})">${p.is_sold_out ? "✅ In Stock" : "🚫 Sold Out"}</button>
              <button class="btn-sm" onclick="editProduct('${p.id}')">Edit</button>
              <button class="btn-sm btn-sm-red" onclick="deleteProduct('${p.id}','${p.name}')">✕</button>
            </div>
          </div>`).join("") || `<div style="padding:12px;color:var(--text-muted);font-size:13px">No products yet</div>`}
        </div>
      </div>`;
    }).join("");
  } catch(e) { document.getElementById("menu-list").innerHTML = `<div style="color:var(--red)">${e.message}</div>`; }
}

function openAddCategoryModal() {
  openModal("Add Category", `
    <div class="field"><label>Name (EN)</label><input id="cat-name" placeholder="Sandwiches"></div>
    <div class="field"><label>Name (AR)</label><input id="cat-name-ar" dir="rtl" placeholder="سندويشات"></div>
    <div class="modal-actions">
      <button class="btn-sm" onclick="closeModal()">Cancel</button>
      <button class="btn-primary" onclick="submitAddCategory()">Add</button>
    </div>
  `);
}
async function submitAddCategory() {
  const name = document.getElementById("cat-name").value.trim();
  if (!name) { toast("Category name is required", "error"); return; }
  try { await api("POST", "/api/menu/categories", { name, nameAr: document.getElementById("cat-name-ar").value }); closeModal(); loadMenu(); }
  catch(e) { toast(e.message, "error"); }
}

function editCategory(id, name, nameAr) {
  openModal("Edit Category", `
    <div class="field"><label>Name (EN)</label><input id="ecat-name" value="${name}"></div>
    <div class="field"><label>Name (AR)</label><input id="ecat-name-ar" dir="rtl" value="${nameAr}"></div>
    <div class="modal-actions">
      <button class="btn-sm" onclick="closeModal()">Cancel</button>
      <button class="btn-primary" onclick="submitEditCategory('${id}')">Save</button>
    </div>
  `);
}
async function submitEditCategory(id) {
  try {
    await api("PATCH", `/api/menu/categories/${id}`, { name: document.getElementById("ecat-name").value, nameAr: document.getElementById("ecat-name-ar").value });
    closeModal(); loadMenu();
  } catch(e) { toast(e.message, "error"); }
}
function deleteCategory(id, name) {
  confirmAction("Delete Category", `Delete <strong>${name}</strong>? Products in it will be uncategorized.`, async () => {
    try { await api("DELETE", `/api/menu/categories/${id}`); loadMenu(); }
    catch(e) { toast(e.message, "error"); }
  }, true);
}

function openAddProductModal(categoryId="") {
  openModal("Add Product", `
    <div class="modal-row">
      <div class="field"><label>Name (EN)</label><input id="p-name" placeholder="Shawarma"></div>
      <div class="field"><label>Name (AR)</label><input id="p-name-ar" dir="rtl" placeholder="شاورما"></div>
    </div>
    <div class="modal-row">
      <div class="field"><label>Price ($)</label><input id="p-price" type="number" step="0.5" placeholder="6.00"></div>
      <div class="field"><label>Prep Time (mins)</label><input id="p-prep" type="number" placeholder="15"></div>
    </div>
    <div class="modal-actions">
      <button class="btn-sm" onclick="closeModal()">Cancel</button>
      <button class="btn-primary" onclick="submitAddProduct('${categoryId}')">Add Product</button>
    </div>
  `);
}
async function submitAddProduct(categoryId) {
  const name = document.getElementById("p-name").value.trim();
  const price = parseFloat(document.getElementById("p-price").value);
  if (!name || isNaN(price)) { toast("Name and price are required", "error"); return; }
  try {
    await api("POST", "/api/menu/products", {
      name,
      nameAr: document.getElementById("p-name-ar").value,
      price,
      prepTimeMins: parseInt(document.getElementById("p-prep").value) || 15,
      categoryId: categoryId || undefined,
    });
    closeModal(); loadMenu();
  } catch(e) { toast(e.message, "error"); }
}

async function toggleSoldOut(id, cur) {
  try { await api("PATCH", `/api/menu/products/${id}`, { isSoldOut: !cur }); loadMenu(); }
  catch(e) { toast(e.message, "error"); }
}

function editProduct(id) {
  const p = _cachedProducts.find(x => x.id === id);
  openModal("Edit Product", `
    <div class="modal-row">
      <div class="field"><label>Name (EN)</label><input id="ep-name" value="${p?.name||""}"></div>
      <div class="field"><label>Name (AR)</label><input id="ep-name-ar" dir="rtl" value="${p?.name_ar||""}"></div>
    </div>
    <div class="modal-row">
      <div class="field"><label>Price ($)</label><input id="ep-price" type="number" step="0.5" value="${p?.price||""}"></div>
      <div class="field"><label>Prep Time (mins)</label><input id="ep-prep" type="number" value="${p?.prep_time_mins||""}"></div>
    </div>
    <div class="field checkbox-field">
      <input type="checkbox" id="ep-active" ${p?.is_active!==false ? "checked" : ""}>
      <label for="ep-active">Visible in menu</label>
    </div>
    <div class="modal-actions">
      <button class="btn-sm" onclick="closeModal()">Cancel</button>
      <button class="btn-primary" onclick="submitEditProduct('${id}')">Save</button>
    </div>
  `);
}
async function submitEditProduct(id) {
  try {
    const body = {};
    const name = document.getElementById("ep-name").value.trim();
    const nameAr = document.getElementById("ep-name-ar").value.trim();
    const price = parseFloat(document.getElementById("ep-price").value);
    const prep = parseInt(document.getElementById("ep-prep").value);
    if (name) body.name = name;
    if (nameAr) body.nameAr = nameAr;
    if (!isNaN(price)) body.price = price;
    if (!isNaN(prep)) body.prepTimeMins = prep;
    body.isActive = document.getElementById("ep-active").checked;
    await api("PATCH", `/api/menu/products/${id}`, body);
    closeModal(); loadMenu();
  } catch(e) { toast(e.message, "error"); }
}

function deleteProduct(id, name) {
  confirmAction("Remove Product", `Remove <strong>${name}</strong> from the menu?`, async () => {
    try { await api("DELETE", `/api/menu/products/${id}`); loadMenu(); }
    catch(e) { toast(e.message, "error"); }
  }, true);
}

// ── DRIVERS ────────────────────────────────────────────────
async function loadDrivers() {
  const el = document.getElementById("drivers-grid");
  el.innerHTML = `<div class="loader">Loading...</div>`;
  try {
    const { drivers } = await api("GET", "/api/drivers");
    if (!drivers.length) { el.innerHTML = `<div class="empty-state"><div class="empty-icon">🛵</div><div>No drivers yet</div></div>`; return; }
    const statusColor = { on_duty: "#25d366", off_duty: "#888", on_break: "#f59e0b", paused: "#3b82f6" };
    el.innerHTML = drivers.map(d => `<div class="driver-card">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
        <div class="driver-avatar">${d.name.charAt(0).toUpperCase()}</div>
        <div>
          <div class="driver-name">${d.name}</div>
          <div style="color:var(--text-muted);font-size:12px">${d.phone}</div>
        </div>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <span style="color:${statusColor[d.status]||"#888"};font-size:12px;font-weight:700;text-transform:uppercase">${d.status.replace(/_/g," ")}</span>
        ${d.availability==="busy" ? `<span style="font-size:11px;color:#8b5cf6;background:#8b5cf618;padding:2px 8px;border-radius:20px">🔴 On delivery</span>` : `<span style="font-size:11px;color:var(--green);background:var(--green-dim);padding:2px 8px;border-radius:20px">Available</span>`}
      </div>
      <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px">
        📦 ${d.total_deliveries} deliveries${d.cash_on_hand ? ` · 💵 $${parseFloat(d.cash_on_hand).toFixed(2)} cash` : ""}
      </div>
      <div style="display:flex;gap:6px">
        <select class="filter-select" style="flex:1;font-size:12px;padding:5px 8px" onchange="setDriverStatus('${d.id}',this.value)">
          <option ${d.status==="on_duty"?"selected":""} value="on_duty">On Duty</option>
          <option ${d.status==="off_duty"?"selected":""} value="off_duty">Off Duty</option>
          <option ${d.status==="on_break"?"selected":""} value="on_break">Break</option>
        </select>
        <button class="btn-sm btn-sm-red" onclick="removeDriver('${d.id}')">Remove</button>
      </div>
    </div>`).join("");
  } catch(e) { el.innerHTML = `<div style="color:var(--red);padding:20px">${e.message}</div>`; }
}

function openAddDriverModal() {
  openModal("Add Driver", `
    <div class="field"><label>Full Name</label><input id="d-name" placeholder="Rami Khalil"></div>
    <div class="field"><label>Phone</label><input id="d-phone" placeholder="+9613001001"></div>
    <div class="field"><label>WhatsApp</label><input id="d-wa" placeholder="+9613001001"></div>
    <div class="field"><label>Password</label><input id="d-pass" type="password" placeholder="••••••••"></div>
    <div class="modal-actions">
      <button class="btn-sm" onclick="closeModal()">Cancel</button>
      <button class="btn-primary" onclick="submitAddDriver()">Add Driver</button>
    </div>
  `);
}
async function submitAddDriver() {
  const name = document.getElementById("d-name").value.trim();
  if (!name) { toast("Name is required", "error"); return; }
  try {
    await api("POST", "/api/drivers", {
      name,
      phone: document.getElementById("d-phone").value,
      whatsappNumber: document.getElementById("d-wa").value,
      password: document.getElementById("d-pass").value,
    });
    closeModal(); loadDrivers();
    toast("Driver added!");
  } catch(e) { toast(e.message, "error"); }
}
async function setDriverStatus(id, status) { try { await api("POST", `/api/drivers/${id}/status`, { status }); } catch {} }
function removeDriver(id) {
  confirmAction("Deactivate Driver", "Deactivate this driver? They won't receive new deliveries.", async () => {
    try { await api("DELETE", `/api/drivers/${id}`); loadDrivers(); toast("Driver deactivated"); }
    catch(e) { toast(e.message, "error"); }
  }, true);
}

// ── CUSTOMERS ──────────────────────────────────────────────
let cstTimeout;
document.getElementById("customer-search").addEventListener("input", () => { clearTimeout(cstTimeout); cstTimeout = setTimeout(loadCustomers, 400); });
document.getElementById("customer-blocked-filter").addEventListener("change", loadCustomers);

async function loadCustomers() {
  const search = document.getElementById("customer-search").value;
  const blocked = document.getElementById("customer-blocked-filter").value;
  let url = "/api/customers?limit=50";
  if (search) url += `&search=${encodeURIComponent(search)}`;
  if (blocked !== "") url += `&blocked=${blocked}`;
  const tbody = document.querySelector("#customers-table tbody");
  tbody.innerHTML = `<tr><td colspan="6" class="loader">Loading...</td></tr>`;
  try {
    const { customers } = await api("GET", url);
    tbody.innerHTML = customers.map(c => `<tr>
      <td><strong>${c.name||"—"}</strong></td>
      <td style="font-size:12px">${c.phone}</td>
      <td>${c.total_orders||0}</td>
      <td>$${parseFloat(c.total_spent||0).toFixed(2)}</td>
      <td>${c.is_blocked
        ? `<span class="pill pill-suspended">Blocked</span>`
        : `<span class="pill pill-active">Active</span>`
      }</td>
      <td>${c.is_blocked
        ? `<button class="btn-sm" onclick="unblockCustomer('${c.id}')">Unblock</button>`
        : `<button class="btn-sm btn-sm-red" onclick="blockCustomer('${c.id}')">Block</button>`
      }</td>
    </tr>`).join("") || `<tr><td colspan="6" class="loader">No customers</td></tr>`;
  } catch(e) { tbody.innerHTML = `<tr><td colspan="6" style="color:var(--red)">${e.message}</td></tr>`; }
}

function blockCustomer(id) {
  openModal("Block Customer", `
    <div class="field"><label>Block reason <span style="color:var(--text-muted);font-weight:400">(optional)</span></label><input id="block-reason" placeholder="e.g. Repeated cancellations, fraud…"></div>
    <div class="modal-actions">
      <button class="btn-sm" onclick="closeModal()">Cancel</button>
      <button class="btn-sm" id="block-confirm-btn" style="color:var(--red);border-color:#ff4d4d40">Block</button>
    </div>
  `);
  document.getElementById("block-confirm-btn").onclick = async () => {
    const reason = document.getElementById("block-reason")?.value || "";
    try { await api("POST", `/api/customers/${id}/block`, { reason }); closeModal(); loadCustomers(); toast("Customer blocked"); }
    catch(e) { toast(e.message, "error"); }
  };
}
async function unblockCustomer(id) {
  try { await api("POST", `/api/customers/${id}/unblock`); loadCustomers(); toast("Customer unblocked"); }
  catch(e) { toast(e.message, "error"); }
}

// ── ANALYTICS ─────────────────────────────────────────────
document.getElementById("analytics-days").addEventListener("change", loadAnalytics);

async function loadAnalytics() {
  const days = document.getElementById("analytics-days").value;
  try {
    const { summary, topProducts, driverStats } = await api("GET", `/api/analytics/dashboard?days=${days}`);
    document.getElementById("analytics-stats").innerHTML = `
      <div class="stat-card"><div class="stat-label">Completed Orders</div><div class="stat-value">${summary.completed_orders||0}</div></div>
      <div class="stat-card"><div class="stat-label">Revenue</div><div class="stat-value">$${parseFloat(summary.gross_revenue||0).toFixed(2)}</div></div>
      <div class="stat-card"><div class="stat-label">Avg Order</div><div class="stat-value">$${parseFloat(summary.avg_order_value||0).toFixed(2)}</div></div>
      <div class="stat-card"><div class="stat-label">Cancelled</div><div class="stat-value" style="color:var(--red)">${summary.cancelled_orders||0}</div></div>
      <div class="stat-card"><div class="stat-label">Rejected</div><div class="stat-value" style="color:var(--red)">${summary.rejected_orders||0}</div></div>
      <div class="stat-card"><div class="stat-label">Avg Response</div><div class="stat-value">${parseFloat(summary.avg_response_mins||0).toFixed(1)}m</div></div>
    `;
    document.querySelector("#top-products-table tbody").innerHTML = (topProducts||[]).map(p =>
      `<tr><td><strong>${p.product_name}</strong></td><td>${p.qty}</td><td>$${parseFloat(p.revenue||0).toFixed(2)}</td></tr>`
    ).join("") || `<tr><td colspan="3" class="loader">No data</td></tr>`;
    document.querySelector("#driver-stats-table tbody").innerHTML = (driverStats||[]).map(d =>
      `<tr><td>${d.name}</td><td>${d.deliveries}</td><td>${parseFloat(d.avg_delivery_mins||0).toFixed(0)} min</td></tr>`
    ).join("") || `<tr><td colspan="3" class="loader">No data</td></tr>`;
  } catch(e) { console.error(e); }
}

// ── SETTINGS ───────────────────────────────────────────────
const DAYS = ["Sunday","Monday","Tuesday","Wednesday","Thursday","Friday","Saturday"];

async function loadSettings() {
  try {
    const [{ profile }, { hours }, { zones }] = await Promise.all([
      api("GET", "/api/settings/profile"),
      api("GET", "/api/settings/hours"),
      api("GET", "/api/settings/zones"),
    ]);
    if (profile) {
      document.getElementById("s-name").value = profile.shop_name||"";
      document.getElementById("s-name-ar").value = profile.shop_name_ar||"";
      document.getElementById("s-ops").value = profile.ops_whatsapp||"";
      document.getElementById("s-locale").value = profile.default_locale||"ar";
      document.getElementById("s-wish").value = profile.wish_number||"";
      document.getElementById("s-wish-auto").checked = profile.wish_auto_confirm!==false;
      document.getElementById("s-wish-timeout").value = profile.wish_timeout_mins||10;
    }
    document.getElementById("hours-grid").innerHTML = (hours||[]).map(h => `
      <div class="hours-row">
        <span class="hours-day">${DAYS[h.day_of_week]}</span>
        <input type="checkbox" id="cls-${h.day_of_week}" ${h.is_closed?"checked":""} onchange="toggleDayClosed(${h.day_of_week})">
        <label for="cls-${h.day_of_week}" style="font-size:12px;color:var(--text-muted)">Closed</label>
        <input type="time" id="open-${h.day_of_week}" value="${h.opens_at?.slice(0,5)||"09:00"}" class="time-input" ${h.is_closed?"disabled":""}>
        <span style="color:var(--text-muted);font-size:12px">–</span>
        <input type="time" id="close-${h.day_of_week}" value="${h.closes_at?.slice(0,5)||"23:00"}" class="time-input" ${h.is_closed?"disabled":""}>
      </div>
    `).join("");
    document.getElementById("zones-list").innerHTML = (zones||[]).map(z => `
      <div class="zone-row">
        <span class="zone-name">${z.name}</span>
        <span class="zone-fee">$${parseFloat(z.delivery_fee).toFixed(2)}</span>
        <span style="font-size:11px;color:var(--text-muted)">min $${parseFloat(z.minimum_order||0).toFixed(2)}</span>
        <button class="btn-sm btn-sm-red" onclick="deleteZone('${z.id}')">✕</button>
      </div>
    `).join("") || `<div style="color:var(--text-muted);font-size:13px;padding:8px 0">No zones added yet</div>`;
  } catch(e) { console.error(e); }
}

function toggleDayClosed(day) {
  const c = document.getElementById(`cls-${day}`).checked;
  document.getElementById(`open-${day}`).disabled = c;
  document.getElementById(`close-${day}`).disabled = c;
}

// ── Settings tab switcher ──────────────────────────────────
function showBOSettingsTab(tab) {
  ["profile","wish","hours","zones"].forEach(t => {
    const content = document.getElementById(`bosettings-${t}`);
    const nav = document.getElementById(`bosnav-${t}`);
    if (content) content.style.display = t === tab ? "" : "none";
    if (nav) nav.classList.toggle("active", t === tab);
  });
}

async function saveProfile() {
  try {
    await api("PATCH", "/api/settings/profile", {
      shopName: document.getElementById("s-name").value,
      shopNameAr: document.getElementById("s-name-ar").value,
      opsWhatsapp: document.getElementById("s-ops").value,
      defaultLocale: document.getElementById("s-locale").value,
    });
    toast("Profile saved!");
  } catch(e) { toast(e.message, "error"); }
}
async function saveWishSettings() {
  try {
    await api("PATCH", "/api/settings/profile", {
      wishNumber: document.getElementById("s-wish").value,
      wishAutoConfirm: document.getElementById("s-wish-auto").checked,
      wishTimeoutMins: parseInt(document.getElementById("s-wish-timeout").value),
    });
    toast("Wish settings saved!");
  } catch(e) { toast(e.message, "error"); }
}
async function saveHours() {
  try {
    const hours = Array.from({length:7}, (_,i) => ({
      dayOfWeek: i,
      opensAt: document.getElementById(`open-${i}`)?.value || "09:00",
      closesAt: document.getElementById(`close-${i}`)?.value || "23:00",
      isClosed: document.getElementById(`cls-${i}`)?.checked || false,
    }));
    await api("PUT", "/api/settings/hours", { hours });
    toast("Opening hours saved!");
  } catch(e) { toast(e.message, "error"); }
}

function openAddZoneModal() {
  openModal("Add Delivery Zone", `
    <div class="field"><label>Zone Name</label><input id="z-name" placeholder="Jounieh Center"></div>
    <div class="modal-row">
      <div class="field"><label>Delivery Fee ($)</label><input id="z-fee" type="number" step="0.5" placeholder="1.50"></div>
      <div class="field"><label>Min Order ($)</label><input id="z-min" type="number" step="0.5" placeholder="5.00"></div>
    </div>
    <div class="modal-actions">
      <button class="btn-sm" onclick="closeModal()">Cancel</button>
      <button class="btn-primary" onclick="submitAddZone()">Add Zone</button>
    </div>
  `);
}
async function submitAddZone() {
  const name = document.getElementById("z-name").value.trim();
  if (!name) { toast("Zone name is required", "error"); return; }
  try {
    await api("POST", "/api/settings/zones", {
      name,
      deliveryFee: parseFloat(document.getElementById("z-fee").value) || 0,
      minimumOrder: parseFloat(document.getElementById("z-min").value) || 0,
    });
    closeModal(); loadSettings();
    toast("Zone added!");
  } catch(e) { toast(e.message, "error"); }
}
function deleteZone(id) {
  confirmAction("Remove Zone", "Remove this delivery zone?", async () => {
    try { await api("DELETE", `/api/settings/zones/${id}`); loadSettings(); toast("Zone removed"); }
    catch(e) { toast(e.message, "error"); }
  }, true);
}

// ── Modal ──────────────────────────────────────────────────
function openModal(title, body) {
  document.getElementById("modal-title").textContent = title;
  document.getElementById("modal-body").innerHTML = body;
  document.getElementById("modal-overlay").style.display = "flex";
}
function closeModal() { document.getElementById("modal-overlay").style.display = "none"; }
document.getElementById("modal-close").addEventListener("click", closeModal);
document.getElementById("modal-overlay").addEventListener("click", (e) => {
  if (e.target === document.getElementById("modal-overlay")) closeModal();
});

// ── Expose to global scope ──────────────────────────────────
window.acceptOrder = acceptOrder;
window.rejectOrder = rejectOrder;
window.submitRejectOrder = submitRejectOrder;
window.markPreparing = markPreparing;
window.markReady = markReady;
window.cancelOrder = cancelOrder;
window.openAddCategoryModal = openAddCategoryModal;
window.submitAddCategory = submitAddCategory;
window.editCategory = editCategory;
window.submitEditCategory = submitEditCategory;
window.deleteCategory = deleteCategory;
window.openAddProductModal = openAddProductModal;
window.submitAddProduct = submitAddProduct;
window.toggleSoldOut = toggleSoldOut;
window.editProduct = editProduct;
window.submitEditProduct = submitEditProduct;
window.deleteProduct = deleteProduct;
window.openAddDriverModal = openAddDriverModal;
window.submitAddDriver = submitAddDriver;
window.setDriverStatus = setDriverStatus;
window.removeDriver = removeDriver;
window.blockCustomer = blockCustomer;
window.unblockCustomer = unblockCustomer;
window.saveProfile = saveProfile;
window.saveWishSettings = saveWishSettings;
window.saveHours = saveHours;
window.toggleDayClosed = toggleDayClosed;
window.openAddZoneModal = openAddZoneModal;
window.submitAddZone = submitAddZone;
window.deleteZone = deleteZone;
window.closeModal = closeModal;
window.loadOrders = loadOrders;
window.setLang = setLang;
window.showBOSettingsTab = showBOSettingsTab;
