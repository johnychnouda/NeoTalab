const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];

const state = {
  scenarios: [],
  scenario: null,
  customer: null,
  run: String(Date.now()).slice(-6),
  since: 0,
  busy: false,
  pins: { beirut: [33.8938, 35.5018], out_of_zone: [34.4367, 35.8362] },
  coverage: null,
};

function phoneDigits(p) {
  return String(p || "").replace(/\D/g, "");
}

function newCustomer() {
  state.run = String(Date.now()).slice(-6);
  state.customer = `96177${state.run}`;
  $("#customer-phone").textContent = "+" + state.customer;
  clearThread($("#customer-thread"));
  clearThread($("#shop-thread"));
  clearThread($("#driver-thread"));
  addSystem($("#customer-thread"), "New customer session. Use chips or type freely — this hits the real NeoTalab lab webhook.");
}

function clearThread(el) {
  el.innerHTML = "";
}

function addSystem(thread, text) {
  const d = document.createElement("div");
  d.className = "bubble in";
  d.innerHTML = `<span class="who">system</span>${escapeHtml(text)}`;
  thread.appendChild(d);
  thread.scrollTop = thread.scrollHeight;
}

function escapeHtml(s) {
  return String(s)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;");
}

function addBubble(thread, { who, text, dir, buttons = [], lists = [], onTap }) {
  const d = document.createElement("div");
  d.className = `bubble ${dir}`;
  let html = `<span class="who">${escapeHtml(who)}</span>${escapeHtml(text || "")}`;
  const taps = [...buttons, ...lists];
  if (taps.length) {
    html += `<div class="btns">${taps
      .map(
        (b) =>
          `<button type="button" data-id="${escapeHtml(b.id)}" data-kind="${buttons.includes(b) ? "button" : "list"}">${escapeHtml(b.title || b.id)}</button>`
      )
      .join("")}</div>`;
  }
  d.innerHTML = html;
  if (onTap) {
    $$(".btns button", d).forEach((btn) =>
      btn.addEventListener("click", () => onTap(btn.dataset.id, btn.dataset.kind))
    );
  }
  thread.appendChild(d);
  thread.scrollTop = thread.scrollHeight;
}

async function api(path, opts = {}) {
  const res = await fetch(path, {
    headers: { "Content-Type": "application/json", Accept: "application/json" },
    ...opts,
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || res.statusText);
  return data;
}

function setBusy(v) {
  state.busy = v;
  $$("button, select, input").forEach((el) => {
    if (el.dataset.keep) return;
    el.disabled = v;
  });
  $("#status").textContent = v ? "Waiting for NeoTalab…" : "Ready";
}

function routeMessage(msg) {
  const to = phoneDigits(msg.to);
  const customer = phoneDigits(state.customer);
  const driver = phoneDigits(state.scenario?.driver_phone);

  if (to === customer) {
    addBubble($("#customer-thread"), {
      who: "shop bot",
      text: msg.text,
      dir: "in",
      buttons: msg.buttons,
      lists: msg.lists,
      onTap: (id, kind) => sendCustomerTap(id, kind),
    });
    if (msg.text) {
      addBubble($("#shop-thread"), { who: "to customer", text: msg.text, dir: "in", buttons: msg.buttons, lists: msg.lists });
    }
  } else if (driver && to === driver) {
    addBubble($("#driver-thread"), {
      who: "shop → driver",
      text: msg.text,
      dir: "in",
      buttons: msg.buttons,
      lists: msg.lists,
      onTap: (id) => sendDriver({ button: id }),
    });
  }
}

function applyMessages(messages) {
  for (const m of messages || []) {
    if (m.t && m.t > state.since) state.since = m.t;
    routeMessage(m);
  }
}

async function sendCustomer(payload, label) {
  if (!state.scenario) return;
  setBusy(true);
  try {
    if (label) addBubble($("#customer-thread"), { who: "you", text: label, dir: "out" });
    else if (payload.text) addBubble($("#customer-thread"), { who: "you", text: payload.text, dir: "out" });
    else if (payload.location) addBubble($("#customer-thread"), { who: "you", text: `📍 ${payload.location.join(", ")}`, dir: "out" });
    else if (payload.button || payload.list_id) addBubble($("#customer-thread"), { who: "you", text: `tapped ${payload.button || payload.list_id}`, dir: "out" });
    else if (payload.media) addBubble($("#customer-thread"), { who: "you", text: `sent ${payload.media}`, dir: "out" });
    else if (payload.event === "call") addBubble($("#customer-thread"), { who: "you", text: "📞 calling…", dir: "out" });

    const data = await api("/api/customer", {
      method: "POST",
      body: JSON.stringify({
        phone_id: state.scenario.phone_id,
        customer: state.customer,
        run: state.run,
        quiet: 2,
        cap: 45,
        ...payload,
      }),
    });
    applyMessages(data.messages);
    if (!(data.messages || []).length) {
      addSystem($("#customer-thread"), "No bot reply yet. Lab queue may still be catching up — try again.");
    }
  } catch (e) {
    addSystem($("#customer-thread"), "Error: " + e.message);
  } finally {
    setBusy(false);
  }
}

async function sendCustomerTap(id, kind) {
  if (kind === "list") return sendCustomer({ list_id: id });
  return sendCustomer({ button: id });
}

async function sendShop(text) {
  if (!state.scenario || !state.customer) return;
  setBusy(true);
  try {
    addBubble($("#shop-thread"), { who: "shop phone", text, dir: "out" });
    const data = await api("/api/shop", {
      method: "POST",
      body: JSON.stringify({
        phone_id: state.scenario.phone_id,
        customer: state.customer,
        driver: state.scenario.driver_phone,
        text,
      }),
    });
    applyMessages(data.messages);
  } catch (e) {
    addSystem($("#shop-thread"), "Error: " + e.message);
  } finally {
    setBusy(false);
  }
}

async function sendDriver(payload) {
  if (!state.scenario?.driver_phone) {
    addSystem($("#driver-thread"), "No driver on this scenario — use shop out/done for self-delivery.");
    return;
  }
  setBusy(true);
  try {
    const label = payload.text || payload.button;
    addBubble($("#driver-thread"), { who: "driver", text: label, dir: "out" });
    const data = await api("/api/driver", {
      method: "POST",
      body: JSON.stringify({
        phone_id: state.scenario.phone_id,
        driver: state.scenario.driver_phone,
        ...payload,
      }),
    });
    applyMessages(data.messages);
  } catch (e) {
    addSystem($("#driver-thread"), "Error: " + e.message);
  } finally {
    setBusy(false);
  }
}

function renderHints() {
  const s = state.scenario;
  const shopHints = $("#shop-hints");
  const driverHints = $("#driver-hints");
  const customerChips = $("#customer-chips");
  shopHints.innerHTML = "";
  driverHints.innerHTML = "";
  customerChips.innerHTML = "";

  (s?.customer_chips || []).forEach((h) => {
    const b = document.createElement("button");
    b.type = "button";
    b.textContent = h;
    b.addEventListener("click", () => sendCustomer({ text: h }));
    customerChips.appendChild(b);
  });
  (s?.shop_hints || []).forEach((h) => {
    const b = document.createElement("button");
    b.type = "button";
    b.textContent = h;
    b.addEventListener("click", () => sendShop(h));
    shopHints.appendChild(b);
  });
  (s?.driver_hints || []).forEach((h) => {
    const b = document.createElement("button");
    b.type = "button";
    b.textContent = h;
    b.addEventListener("click", () => sendDriver({ text: h }));
    driverHints.appendChild(b);
  });

  $("#blurb").innerHTML = s
    ? `<strong>${escapeHtml(s.name)}</strong> — ${escapeHtml(s.blurb)}<br><span class="meta">Hint: ${escapeHtml(s.customer_hint)}</span>`
    : "Pick a production scenario.";
  const hasDriver = Boolean(s?.with_driver && s?.driver_phone);
  $("#driver-pane").classList.toggle("dim", !hasDriver);
  $("#driver-input").disabled = !hasDriver;
  $("#driver-send").disabled = !hasDriver;
}

function renderCoverage() {
  const c = state.coverage || {};
  const body = $("#coverage-body");
  body.innerHTML = ["customer", "shop", "driver", "types"]
    .map((k) => {
      const items = c[k] || [];
      return `<section><h3>${escapeHtml(k)}</h3><ul>${items.map((i) => `<li>${escapeHtml(i)}</li>`).join("")}</ul></section>`;
    })
    .join("");
}

async function selectScenario(key) {
  state.scenario = state.scenarios.find((s) => s.key === key) || null;
  renderHints();
  newCustomer();
  if (state.scenario) {
    try {
      await api("/api/prepare", { method: "POST", body: "{}" });
    } catch (_) {}
  }
}

async function seedCart() {
  const s = state.scenario;
  if (!s?.p1) {
    addSystem($("#customer-thread"), "No product id on this scenario — order by typing instead.");
    return;
  }
  setBusy(true);
  try {
    await api("/api/seed-cart", {
      method: "POST",
      body: JSON.stringify({
        merchant_id: s.id,
        customer: state.customer,
        product_id: s.p1,
        qty: 2,
        variant_id: s.variant || null,
        modifier_ids: s.mod ? [s.mod] : [],
      }),
    });
    addSystem($("#customer-thread"), "Cart seeded (2 × product with size/extra if restaurant). Type done to checkout.");
  } catch (e) {
    addSystem($("#customer-thread"), "Seed cart error: " + e.message);
  } finally {
    setBusy(false);
  }
}

async function setMode(mode) {
  if (!state.scenario) return;
  try {
    await api("/api/mode", {
      method: "POST",
      body: JSON.stringify({ merchant_id: state.scenario.id, mode }),
    });
    addSystem($("#shop-thread"), `Shop mode set to “${mode}” (production Settings → Orders).`);
  } catch (e) {
    addSystem($("#shop-thread"), "Mode error: " + e.message);
  }
}

async function boot() {
  try {
    const health = await api("/api/health");
    if (!health.ok || health.api_up === false || health.queue === false || health.mock === false) {
      $("#health").textContent = "Lab recovering… refresh if errors persist";
      $("#health").className = "meta bad";
    } else {
      $("#health").textContent = "Lab connected · full WhatsApp surface";
      $("#health").className = "meta ok";
    }
  } catch (e) {
    $("#health").textContent = "Lab offline — run: cd qa && ./start_playground.sh";
    $("#health").className = "meta bad";
  }

  try {
    const data = await api("/api/scenarios");
    state.scenarios = data.scenarios || [];
    state.pins = data.pins || state.pins;
    state.coverage = data.coverage;
    renderCoverage();
    const sel = $("#scenario");
    sel.innerHTML = state.scenarios
      .map((s) => `<option value="${s.key}">${s.label}</option>`)
      .join("");
    if (state.scenarios[0]) await selectScenario(state.scenarios[0].key);
  } catch (e) {
    $("#blurb").textContent = "No scenarios. Run setup_lab.sh → s01 → s02 → seed_playground.py";
  }

  $("#scenario").addEventListener("change", (e) => selectScenario(e.target.value));
  $("#new-customer").addEventListener("click", () => newCustomer());
  $("#toggle-coverage").addEventListener("click", () => $("#coverage").classList.toggle("hidden"));
  $("#send-pin").addEventListener("click", () => sendCustomer({ location: state.pins.beirut }, "📍 Beirut pin"));
  $("#send-out-pin").addEventListener("click", () => sendCustomer({ location: state.pins.out_of_zone }, "📍 Out of zone pin"));
  $("#send-photo").addEventListener("click", () => sendCustomer({ media: "photo" }));
  $("#send-voice").addEventListener("click", () => sendCustomer({ media: "voice" }));
  $("#send-call").addEventListener("click", () => sendCustomer({ event: "call" }));
  $("#seed-cart").addEventListener("click", () => seedCart());
  $$("button.mode").forEach((b) => b.addEventListener("click", () => setMode(b.dataset.mode)));

  $("#customer-form").addEventListener("submit", (e) => {
    e.preventDefault();
    const input = $("#customer-input");
    const text = input.value.trim();
    if (!text) return;
    input.value = "";
    sendCustomer({ text });
  });
  $("#shop-form").addEventListener("submit", (e) => {
    e.preventDefault();
    const input = $("#shop-input");
    const text = input.value.trim();
    if (!text) return;
    input.value = "";
    sendShop(text);
  });
  $("#driver-form").addEventListener("submit", (e) => {
    e.preventDefault();
    const input = $("#driver-input");
    const text = input.value.trim();
    if (!text) return;
    input.value = "";
    sendDriver({ text });
  });
}

boot();
