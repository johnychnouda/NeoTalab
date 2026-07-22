/**
 * NeoTalab — synced 3-phone demo
 * Pickup vs delivery · Wish payment gate · full delivery tracking
 */
const chat = (role) => document.getElementById("chat-" + role);
const btnGroups = {};

const ORDER = {
  id: "ORD-2026-00042",
  items: "2× Chicken shawarma, 1× Pepsi",
  itemsShort: "2× Shawarma, 1× Pepsi",
  subtotal: "400,000 ل.ل",
  deliveryFee: "25,000 ل.ل",
  total: "425,000 ل.ل",
  address: "Jounieh — core",
  wish: "+961 70 123 456",
  eta: "~18 min",
  prepMins: 12,
};
const SAVED_ADDRESS = {
  building: "Marina Tower",
  floor: "8",
  extra: "Gate B, black door",
};

const state = {
  phase: "idle",
  fulfillment: null,
  payment: null,
  wishPaid: false,
  driver: "Rami",
  preparing: false,
  locationShared: false,
  buildingName: "",
  deliveryFloor: "",
  deliveryAdditionalInfo: "",
  trackStage: null, // preparing | on_way | nearby | arrived (delivery)
};

const HINTS = {
  start: "<strong>Customer</strong> — Send your order (Arabic, English, French, or voice)",
  draft: "<strong>Customer</strong> — Confirm, Edit cart, or Cancel",
  fulfillment: "<strong>Customer</strong> — Pickup or Delivery",
  payment: "<strong>Customer</strong> — Cash or Wish",
  wish_detecting: "<strong>Customer</strong> — send Wish payment · system will auto-confirm",
  merchant_confirm: "<strong>Merchant</strong> — notification only (busy mode · order auto-confirms)",
  merchant_pickup: "<strong>Merchant</strong> — Ready for pickup only (preparing is automatic)",
  merchant_delivery: "<strong>Merchant</strong> — notifications only (driver auto-assigned)",
  location: "<strong>Customer</strong> — map pin, then building + floor",
  driver_run: "<strong>Driver</strong> — Left store, then Delivered · customer can ask order status",
  feedback: "<strong>Customer</strong> — optional feedback (skip anytime)",
  closed: "Order complete — Restart demo",
  cancelled: "Cancelled — Restart demo",
};

const TURN = {
  start: "customer",
  draft: "customer",
  fulfillment: "customer",
  payment: "customer",
  wish_detecting: "customer",
  merchant_pickup: "merchant",
  merchant_delivery: "merchant",
  location: "customer",
  driver_run: "driver",
  feedback: "customer",
};

function time() {
  return new Date().toLocaleTimeString("en-US", { hour: "2-digit", minute: "2-digit" });
}

function setHint() {
  document.getElementById("hint").innerHTML = HINTS[state.phase] || "";
}

function setActiveCol() {
  const turn = TURN[state.phase];
  ["customer", "merchant", "driver"].forEach((r) => {
    const col = document.getElementById("col-" + r);
    const badge = document.getElementById("badge-" + r);
    const on = r === turn;
    col.classList.toggle("active", on);
    col.classList.toggle("waiting", !on && !["closed", "cancelled", "wish_detecting"].includes(state.phase));
    if (state.phase === "wish_detecting" && r === "customer") col.classList.remove("waiting");
    badge.style.display = on ? "inline-block" : "none";
  });
}

function clearChat(role) {
  chat(role).innerHTML = "";
  clearBtns(role);
}

function draftBubbleHtml() {
  return (
    featTag([3, 11, 12, 37]) +
    "<strong>Order draft</strong><div class='draft-lines'>" +
    "<div class='line'>2× Chicken shawarma <em>(no onion)</em></div>" +
    "<div class='line'>1× Pepsi 330ml</div>" +
    "<div class='line'>Subtotal: " + ORDER.subtotal + "</div>" +
    (state.fulfillment === "delivery"
      ? "<div class='line'>Delivery (" + ORDER.address + "): " + ORDER.deliveryFee + "</div>" +
        "<div class='line'><strong>Total: " + ORDER.total + "</strong></div>"
      : "<div class='line'><em>+ delivery fee if you choose delivery</em></div>") +
    "<div class='line'>⏱️ Prep estimate: ~" + ORDER.prepMins + " min</div></div>"
  );
}

function addBubble(role, html, dir) {
  const el = document.createElement("div");
  el.className = "bubble " + (dir || "in");
  el.innerHTML = html + '<span class="time">' + time() + "</span>";
  const c = chat(role);
  c.appendChild(el);
  c.scrollTop = c.scrollHeight;
}

function addSys(role, text) {
  const el = document.createElement("div");
  el.className = "bubble sys";
  el.textContent = text;
  chat(role).appendChild(el);
  chat(role).scrollTop = chat(role).scrollHeight;
}

function clearBtns(role) {
  if (btnGroups[role]) {
    btnGroups[role].remove();
    delete btnGroups[role];
  }
}

function addBtns(role, opts, fn) {
  clearBtns(role);
  const wrap = document.createElement("div");
  wrap.className = "btns";
  opts.forEach((o) => {
    const b = document.createElement("button");
    b.type = "button";
    b.className = "btn";
    b.textContent = o.label;
    if (o.disabled) b.disabled = true;
    b.onclick = () => {
      if (b.disabled) return;
      addBubble(role, o.label, "out");
      if (fn(o.id, o.label, o) === false) return;
    };
    wrap.appendChild(b);
  });
  chat(role).appendChild(wrap);
  chat(role).scrollTop = chat(role).scrollHeight;
  btnGroups[role] = wrap;
}

function formatDeliveryForStaff() {
  if (state.fulfillment !== "delivery") return ORDER.address;
  let line =
    "🏢 " + state.buildingName + ", Floor " + state.deliveryFloor +
    "<br>📍 " + ORDER.address;
  if (state.deliveryAdditionalInfo) {
    line += "<br>ℹ️ " + state.deliveryAdditionalInfo;
  }
  return line;
}

function notifyMerchantPending() {
  clearChat("merchant");
  addBubble(
    "merchant",
    "<strong>Incoming " + ORDER.id + "</strong><br>Ali · +961 3 456 789<br>" +
      ORDER.itemsShort + "<br>" + ORDER.total + "<br>" +
      state.fulfillment.toUpperCase() + " · " + formatDeliveryForStaff()
  );
  if (state.payment === "wish") {
    addSys("merchant", "Listening for Wish payment to " + ORDER.wish + "…");
  }
}

/** Busy-mode: no merchant tap — order continues automatically */
function autoConfirmOrder() {
  state.wishPaid = state.payment === "wish";
  addBubble("customer", "✅ <strong>Order confirmed!</strong> #" + ORDER.id);
  if (state.payment === "wish") {
    addBubble("customer", "💳 Wish payment received — thank you!");
  }
  clearChat("merchant");
  addBubble(
    "merchant",
    "✅ <strong>" + ORDER.id + " auto-confirmed</strong><br>" +
      (state.payment === "wish" ? "Wish payment detected in your account" : "Cash — auto-accept (busy mode)") +
      "<br><em>No action needed — NeoTalab is handling the order</em>"
  );
  proceedAfterConfirm();
}

function proceedAfterConfirm() {
  logFeature(37);
  logFeature(54);
  addBubble(
    "customer",
    featTag(37) + "🍳 <strong>Preparing</strong> your order…<br><em>Estimated prep: ~" + ORDER.prepMins + " min</em>"
  );
  if (state.fulfillment === "pickup") {
    state.phase = "merchant_pickup";
    state.trackStage = "preparing";
    setHint();
    setActiveCol();
    addBubble("merchant", "PICKUP — tap when ready:");
    addBtns("merchant", [{ id: "ready", label: "Ready for pickup" }], onMerchantPickup);
    showOrderStatusButton();
  } else {
    state.phase = "merchant_delivery";
    state.trackStage = "preparing";
    setHint();
    setActiveCol();
    autoAssignDriver();
  }
}

function buildOrderStatusReply() {
  const ref = "#" + ORDER.id;
  if (state.fulfillment === "pickup") {
    if (state.phase === "merchant_pickup") {
      return "📋 <strong>" + ref + "</strong><br>Status: <strong>Preparing</strong><br>We will notify you when it is ready for pickup.";
    }
    return "📋 <strong>" + ref + "</strong><br>Status update is not available for this step.";
  }
  const d = state.driver;
  switch (state.trackStage) {
    case "preparing":
      return (
        "📋 <strong>" + ref + "</strong><br>Status: <strong>Preparing</strong><br>" +
        d + " is assigned and will deliver your order."
      );
    case "on_way":
      return (
        "📋 <strong>" + ref + "</strong><br>Status: <strong>On the way</strong><br>" +
        d + " is heading to you.<br>Estimated arrival: <strong>" + ORDER.eta + "</strong>"
      );
    case "nearby":
      return "📋 <strong>" + ref + "</strong><br>Status: <strong>Nearby</strong><br>" + d + " is close to your location.";
    case "arrived":
      return (
        "📋 <strong>" + ref + "</strong><br>Status: <strong>Arrived</strong><br>" +
        d + " is at your building (" + state.buildingName + ", Floor " + state.deliveryFloor + ")."
      );
    default:
      return "📋 <strong>" + ref + "</strong><br>We are checking your order status…";
  }
}

function onOrderStatusAsk() {
  logFeature(61);
  addBubble("customer", featTag(61) + buildOrderStatusReply());
  showOrderStatusButton();
  return false;
}

function showOrderStatusButton() {
  if (!["merchant_pickup", "merchant_delivery", "driver_run"].includes(state.phase)) return;
  addBtns("customer", [{ id: "track", label: "📍 Where is my order?" }], onOrderStatusAsk);
}

function autoAssignDriver() {
  clearBtns("merchant");
  state.phase = "driver_run";
  setHint();
  setActiveCol();
  logFeature(40);
  logFeature(41);
  logFeature(43);
  addBubble(
    "merchant",
    featTag([41, 43, 40]) +
      "<strong>" + state.driver + "</strong> assigned (next available staff driver).<br>Ahmad on another delivery."
  );
  addBubble(
    "customer",
    featTag(41) + "🛵 <strong>" + state.driver + "</strong> from Joe's Snacks will deliver your order."
  );
  clearChat("driver");
  addBubble(
    "driver",
    "<strong>Joe's Snacks</strong> — assigned<br>" + ORDER.id + "<br>" + ORDER.items +
      "<br>🏢 " + state.buildingName + " · Floor " + state.deliveryFloor +
      (state.deliveryAdditionalInfo ? "<br>ℹ️ " + state.deliveryAdditionalInfo : "") +
      "<br>📍 " + ORDER.address +
      "<br>" + ORDER.total +
      "<br><em>2 taps only — live tracking updates the customer automatically</em>"
  );
  addBtns("driver", [{ id: "left", label: "Left the store — on my way" }], onDriverLeft);
  showOrderStatusButton();
}

/** Driver tapped once — live tracking handles nearby/arrived (no driving distractions) */
function onDriverLeft() {
  clearBtns("driver");
  state.trackStage = "on_way";
  logFeature(47);
  logFeature(48);
  logFeature(50);
  ORDER.eta = "~14 min";
  addBubble("driver", featTag(48) + "On the way — live tracking is active. Focus on the road.");
  addBubble("customer", featTag(48) + "🛵 <strong>" + state.driver + " is heading to you</strong>");
  addBubble("customer", featTag(50) + "⏱️ <strong>Estimated arrival: " + ORDER.eta + "</strong><br><em>Updated from prep + distance</em>");
  addBubble("merchant", state.driver + " left the store — live tracking on.");
  addSys("driver", "Nearby & arrived updates are automatic");
  showOrderStatusButton();
  simulateDeliveryTracking();
}

function simulateDeliveryTracking() {
  setTimeout(() => {
    state.trackStage = "nearby";
    addBubble("customer", featTag(48) + "📍 <strong>" + state.driver + " is nearby</strong>");
    addBubble("merchant", state.driver + " is nearby (automatic update).");
    showOrderStatusButton();
  }, 2500);
  setTimeout(() => {
    state.trackStage = "arrived";
    addBubble("customer", featTag(48) + "✅ <strong>" + state.driver + " has arrived</strong>");
    addBubble("merchant", state.driver + " has arrived (automatic update).");
    addSys("driver", "Tap Order delivered when you hand the food to the customer");
    addBtns("driver", [{ id: "done", label: "Order delivered" }], onOrderDelivered);
    showOrderStatusButton();
  }, 5000);
}

function onOrderDelivered() {
  clearBtns("driver");
  clearBtns("customer");
  state.trackStage = null;
  addBubble("driver", "Delivery logged. You're free.");
  addBubble("merchant", "✅ <strong>" + ORDER.id + " delivered</strong> by " + state.driver + ".");
  addBubble("customer", "🎉 Order delivered! Thank you Ali.");
  sendReceipt();
  addSys("driver", "Available for next delivery");
  askFeedback();
}

function sendReceipt() {
  logFeature(58);
  const pay = state.payment === "wish" ? "Wish" : "Cash";
  const type = state.fulfillment === "delivery" ? "Delivery" : "Pickup";
  addBubble(
    "customer",
    featTag(58) + "🧾 <strong>Receipt — Joe's Snacks</strong><br>" +
      "#" + ORDER.id + "<br>" +
      ORDER.items + "<br>" +
      "<strong>" + ORDER.total + "</strong> · " + pay + "<br>" +
      type + " · Thank you"
  );
}

/** Optional friendly feedback — not a rating survey */
function askFeedback() {
  state.phase = "feedback";
  setHint();
  setActiveCol();
  logFeature(59);
  setTimeout(() => {
    addBubble(
      "customer",
      featTag(59) +
        "Thank you for ordering from <strong>Joe's Snacks</strong>.<br>We hope everything met your expectations.<br>Your feedback is welcome and optional."
    );
    addBtns(
      "customer",
      [
        { id: "good", label: "👍 Everything was perfect" },
        { id: "share", label: "💬 Leave feedback" },
        { id: "skip", label: "Skip" },
      ],
      onFeedbackChoice
    );
  }, 400);
}

function onFeedbackChoice(id) {
  clearBtns("customer");
  if (id === "skip") {
    addBubble("customer", "No problem — see you next time!", "out");
    finishOrder();
    return;
  }
  if (id === "good") {
    addBubble(
      "customer",
      "Thank you! 🙏<br>We're glad you enjoyed your order.<br>We look forward to serving you again."
    );
    addBubble(
      "merchant",
      "✅ <strong>Customer satisfaction confirmed</strong><br>" + ORDER.id + " · Ali<br>No issues reported."
    );
    finishOrder();
    return;
  }
  addBubble(
    "customer",
    "Please share your feedback below and tap Send.<br><em>On WhatsApp you can type or send a voice note.</em>"
  );
  showFeedbackComposer("customer");
}

function showFeedbackComposer(role) {
  clearFeedbackComposer(role);
  const chatEl = chat(role);
  const wrap = document.createElement("div");
  wrap.className = "feedback-compose";
  wrap.id = "feedback-compose-" + role;
  const ta = document.createElement("textarea");
  ta.className = "feedback-input";
  ta.placeholder = "Type your feedback here…";
  ta.rows = 3;
  const send = document.createElement("button");
  send.type = "button";
  send.className = "btn feedback-send";
  send.textContent = "Send feedback";
  send.onclick = () => {
    const text = ta.value.trim();
    if (!text) {
      addSys(role, "Please type your feedback or tap Skip on the previous step.");
      return;
    }
    clearFeedbackComposer(role);
    submitFeedback(text);
  };
  wrap.appendChild(ta);
  wrap.appendChild(send);
  chatEl.appendChild(wrap);
  chatEl.scrollTop = chatEl.scrollHeight;
  ta.focus();
}

function clearFeedbackComposer(role) {
  const el = document.getElementById("feedback-compose-" + role);
  if (el) el.remove();
}

function submitFeedback(message) {
  clearBtns("customer");
  clearFeedbackComposer("customer");
  addBubble("customer", message, "out");
  addBubble("customer", "Thank you — Joe's Snacks received your feedback.");
  addBubble(
    "merchant",
    "💬 <strong>Feedback on " + ORDER.id + "</strong><br>Ali:<br>\"" + message + "\""
  );
  finishOrder();
}

function finishOrder() {
  state.phase = "closed";
  setHint();
  setActiveCol();
  logFeature(68);
  logFeature(69);
  addBubble("merchant", featTag(68) + "📊 <strong>EOD summary</strong> — 47 orders · 18.4M ل.ل · Cash/Wish split");
  addBubble("merchant", featTag(69) + "📈 Analytics: busiest 7–9 PM · top item Shawarma · avg delivery 28 min");
  document.getElementById("hint").textContent =
    "Demo complete — " + demoFeatureShownCount() + " features shown · Restart to try cash or cancel";
}

function startDemo() {
  ORDER.eta = "~18 min";
  Object.assign(state, {
    phase: "idle",
    fulfillment: null,
    payment: null,
    wishPaid: false,
    preparing: false,
    locationShared: false,
    buildingName: "",
    deliveryFloor: "",
    deliveryAdditionalInfo: "",
    trackStage: null,
  });
  resetFeaturePanel();
  clearFeedbackComposer("customer");
  clearDeliveryDetailsComposer("customer");
  clearChat("customer");
  clearChat("merchant");
  clearChat("driver");
  logFeature(1);
  addSys("merchant", "Waiting for customer orders…");
  addSys("driver", "Rami · on duty · Ahmad delivering ORD-41");
  logFeature(44);
  logFeature(35);
  addBubble(
    "customer",
    featTag(35) + "Welcome to <strong>Joe's Snacks</strong> 🟢 We're open until 11:00 PM.<br>Send your order here — Arabic, English, or French."
  );
  state.phase = "start";
  setHint();
  setActiveCol();
  addBubble("customer", "مرحبا! بدي ٢ شاورما و واحد بيبسي وصلني عالجل", "out");
  logFeature(64);
  setTimeout(showDraftStep, 600);
}

function showDraftStep() {
  logFeature(3);
  logFeature(8);
  logFeature(11);
  logFeature(12);
  state.phase = "draft";
  setHint();
  setActiveCol();
  addBubble("customer", draftBubbleHtml());
  addBtns(
    "customer",
    [
      { id: "confirm", label: "✅ Confirm order" },
      { id: "edit", label: "✏️ Edit cart" },
      { id: "cancel", label: "❌ Cancel" },
    ],
    onDraft
  );
}

function onDraft(id) {
  if (id === "cancel") {
    logFeature(56);
    logFeature(54);
    state.phase = "cancelled";
    clearBtns("customer");
    addBubble("customer", featTag(56) + "Order cancelled (before preparing).");
    setHint();
    setActiveCol();
    return;
  }
  if (id === "edit") {
    logFeature(13);
    addBubble(
      "customer",
      featTag(13) + "✏️ <strong>Cart updated</strong><br>3× Chicken shawarma (no onion)<br>1× Pepsi<br><strong>Total: 505,000 ل.ل</strong>"
    );
    ORDER.total = "505,000 ل.ل";
    return false;
  }
  clearBtns("customer");
  state.phase = "fulfillment";
  setHint();
  setActiveCol();
  logFeature(4);
  addBubble("customer", featTag(4) + "Pickup or delivery?");
  addBtns(
    "customer",
    [
      { id: "pickup", label: "🏪 Pickup" },
      { id: "delivery", label: "🛵 Delivery" },
    ],
    onFulfillment
  );
}

function onFulfillment(id) {
  clearBtns("customer");
  state.fulfillment = id;
  if (id === "delivery") {
    state.phase = "location";
    setHint();
    setActiveCol();
    addBubble(
      "customer",
      "🛵 <strong>Delivery address</strong> — 2 steps<br><br>" +
        "<strong>Step 1:</strong> Send your <strong>WhatsApp location</strong> so we can deliver to you."
    );
    addBtns("customer", [{ id: "loc", label: "📍 Send my location" }], onLocationShared);
    return;
  }
  askPayment();
}

function onLocationShared() {
  clearBtns("customer");
  state.locationShared = true;
  logFeature(22);
  addBubble("customer", featTag(22) + "📍 Location shared", "out");
  logFeature(25);
  addBubble(
    "customer",
    featTag(25) +
      "✓ <strong>Jounieh — core</strong> · delivery available<br>Fee <strong>" +
      ORDER.deliveryFee +
      "</strong> added to your total."
  );
  addBubble(
    "customer",
    "✓ Location received.<br><br><strong>Step 2:</strong> Building, floor, and <strong>additional info</strong>."
  );
  addBtns(
    "customer",
    [{ id: "saved", label: "🏠 Use saved address" }],
    (id) => {
      if (id === "saved") {
        clearBtns("customer");
        clearDeliveryDetailsComposer("customer");
        applySavedAddress();
      }
    }
  );
  showDeliveryDetailsComposer("customer");
}

function applySavedAddress() {
  logFeature(18);
  state.buildingName = SAVED_ADDRESS.building;
  state.deliveryFloor = SAVED_ADDRESS.floor;
  state.deliveryAdditionalInfo = SAVED_ADDRESS.extra;
  addBubble(
    "customer",
    featTag(18) +
      "🏢 " +
      SAVED_ADDRESS.building +
      ", Floor " +
      SAVED_ADDRESS.floor +
      "<br>ℹ️ " +
      SAVED_ADDRESS.extra,
    "out"
  );
  addBubble("customer", "✓ Saved address applied (#18).");
  askPayment();
}

function showDeliveryDetailsComposer(role) {
  clearDeliveryDetailsComposer(role);
  const chatEl = chat(role);
  const wrap = document.createElement("div");
  wrap.className = "feedback-compose delivery-details-compose";
  wrap.id = "delivery-details-" + role;
  const building = document.createElement("input");
  building.type = "text";
  building.className = "feedback-input delivery-field";
  building.placeholder = "Building name (required)";
  const floor = document.createElement("input");
  floor.type = "text";
  floor.className = "feedback-input delivery-field";
  floor.placeholder = "Floor (required)";
  const extra = document.createElement("input");
  extra.type = "text";
  extra.className = "feedback-input delivery-field";
  extra.placeholder = "Additional info (optional) — gate, landmark…";
  const cont = document.createElement("button");
  cont.type = "button";
  cont.className = "btn feedback-send";
  cont.textContent = "Continue";
  cont.onclick = () => {
    const b = building.value.trim();
    const f = floor.value.trim();
    const x = extra.value.trim();
    if (!b || !f) {
      addSys(role, "Please enter building name and floor.");
      return;
    }
    state.buildingName = b;
    state.deliveryFloor = f;
    state.deliveryAdditionalInfo = x;
    logFeature(23);
    if (x) logFeature(24);
    clearDeliveryDetailsComposer(role);
    let out = featTag([23, 24]) + "🏢 " + b + ", Floor " + f;
    if (x) out += "<br>ℹ️ " + x;
    addBubble(role, out, "out");
    addBubble(role, "Thanks — your delivery details are complete.");
    askPayment();
  };
  wrap.appendChild(building);
  wrap.appendChild(floor);
  wrap.appendChild(extra);
  wrap.appendChild(cont);
  chatEl.appendChild(wrap);
  chatEl.scrollTop = chatEl.scrollHeight;
  building.focus();
}

function clearDeliveryDetailsComposer(role) {
  const el = document.getElementById("delivery-details-" + role);
  if (el) el.remove();
}

function askPayment() {
  state.phase = "payment";
  setHint();
  setActiveCol();
  addBubble("customer", "How will you pay?");
  addBtns(
    "customer",
    [
      { id: "cash", label: "💵 Cash" },
      { id: "wish", label: "📱 Wish" },
    ],
    onPayment
  );
}

function onPayment(id) {
  clearBtns("customer");
  state.payment = id;
  logFeature(id === "wish" ? 29 : 28);
  logFeature(5);
  logFeature(62);
  notifyMerchantPending();
  if (id === "wish") {
    state.phase = "wish_detecting";
    setHint();
    setActiveCol();
    addBubble(
      "customer",
      "Send <strong>" + ORDER.total + "</strong> to Wish:<br><strong>" + ORDER.wish + "</strong>"
    );
    addBubble("customer", "⏳ Waiting for payment to reach Joe's account…");
    setTimeout(() => {
      addSys("customer", "Wish payment detected");
      autoConfirmOrder();
    }, 2800);
  } else {
    addBubble("customer", "💵 Cash on delivery selected.");
    setTimeout(() => autoConfirmOrder(), 800);
  }
}

function onMerchantPickup(id) {
  if (id === "ready") {
    clearBtns("merchant");
    addBubble("customer", "✅ <strong>Ready for pickup!</strong><br>Collect at Joe's Snacks.");
    addBubble("merchant", "Customer notified — ready for pickup.");
    addSys("driver", "No delivery for this order");
    askFeedback();
  }
}

document.getElementById("reset").onclick = startDemo;
initFeaturePanel();
startDemo();
