/** PRODUCT-SCOPE checklist — ✓ only when this demo actually shows the feature */
const REMOVED = new Set([15, 19, 26, 33, 45, 52, 55, 60, 63, 67, 71]);

/** Features this HTML demo can demonstrate (if you play the story / optional branches) */
const DEMO_CAN_SHOW = new Set([
  1, 3, 4, 5, 8, 11, 12, 13, 18, 22, 23, 24, 25, 28, 29, 35, 37, 40, 41, 43, 44, 47, 48, 50,
  54, 56, 58, 59, 61, 62, 64, 68, 69,
]);

const FEATURE_META = {
  1: ["Core", "WhatsApp-first operations"],
  2: ["Core", "Backoffice setup only"],
  3: ["Core", "AI order draft"],
  4: ["Core", "Interactive buttons"],
  5: ["Core", "Busy mode"],
  6: ["Core", "Manual mode"],
  7: ["Core", "Commerce OS positioning"],
  8: ["Menu", "Basic menu"],
  9: ["Menu", "Sold out"],
  10: ["Menu", "Stock quantity"],
  11: ["Menu", "Menu engine"],
  12: ["Menu", "Modifiers"],
  13: ["Menu", "Cart engine"],
  14: ["Menu", "Partial availability"],
  16: ["Menu", "Order notes"],
  17: ["Customer", "Profile + block"],
  18: ["Customer", "Saved addresses"],
  20: ["Customer", "Favorites"],
  21: ["Customer", "AI memory"],
  22: ["Delivery", "Location pin"],
  23: ["Delivery", "Building + floor"],
  24: ["Delivery", "Additional info"],
  25: ["Delivery", "Zones + fee + min"],
  27: ["Delivery", "Outside zone → pickup"],
  28: ["Pay", "Cash"],
  29: ["Pay", "Wish auto-confirm"],
  30: ["Pay", "Wish wrong amount"],
  31: ["Pay", "Wish timeout"],
  32: ["Pay", "Cash reconciliation"],
  34: ["Ops", "Fraud protection"],
  35: ["Ops", "Shop hours"],
  36: ["Ops", "Scheduled orders"],
  37: ["Ops", "Prep time engine"],
  38: ["Ops", "Kitchen delay"],
  39: ["Ops", "Rush queue"],
  40: ["Driver", "In-house staff drivers"],
  41: ["Driver", "Auto-assign (next available)"],
  42: ["Driver", "Manual assign"],
  43: ["Driver", "One active delivery"],
  44: ["Driver", "Driver states"],
  46: ["Driver", "Reassign timeout"],
  47: ["Driver", "2 taps only"],
  48: ["Driver", "Live tracking"],
  49: ["Driver", "Simple ETA"],
  50: ["Driver", "Dynamic ETA"],
  51: ["Driver", "Failure flows"],
  53: ["Driver", "Shift stats"],
  54: ["Status", "Full lifecycle"],
  56: ["Status", "Cancel rules"],
  57: ["Status", "Merchant reject"],
  58: ["Close", "Receipt"],
  59: ["Close", "Feedback"],
  61: ["Status", "Where is my order?"],
  62: ["Merchant", "Notifications"],
  64: ["i18n", "Per-role language"],
  65: ["i18n", "Merchant no lang label"],
  66: ["Merchant", "Human handoff"],
  68: ["Merchant", "EOD summary"],
  69: ["Merchant", "Analytics"],
  70: ["Marketing", "Promotions"],
  72: ["Platform", "Webhook security"],
  73: ["Platform", "Message retry"],
  74: ["Platform", "Outage recovery"],
  75: ["Platform", "Production app"],
  76: ["Platform", "Wish API"],
  77: ["Platform", "GPS/geofence"],
  78: ["Platform", "Backoffice any language"],
};

const DEMO_FEATURES = Object.entries(FEATURE_META).map(([id, [g, n]]) => ({
  id: +id,
  g,
  n,
  inDemo: DEMO_CAN_SHOW.has(+id),
}));

const featureSeen = new Set();

function featTag(ids) {
  const s = Array.isArray(ids) ? ids.join(",") : String(ids);
  return '<span class="feat-tag">#' + s + "</span> ";
}

/** Call only when the story visibly demonstrates this feature in this run */
function logFeature(id) {
  if (REMOVED.has(id)) return;
  if (!DEMO_CAN_SHOW.has(id)) return;
  featureSeen.add(id);
  renderFeaturePanel();
}

function renderFeaturePanel() {
  const el = document.getElementById("feature-list");
  if (!el) return;
  el.innerHTML = DEMO_FEATURES.map((f) => {
    const seen = featureSeen.has(f.id);
    const cls = "feat-row" + (seen ? " seen" : "") + (!f.inDemo ? " not-in-demo" : "");
    let mark = "";
    if (seen) mark = '<span class="feat-check">✓</span>';
    else if (!f.inDemo) mark = '<span class="feat-na" title="Not shown in this demo">—</span>';
    return (
      '<div class="' + cls + '">' +
      '<span class="feat-id">#' + f.id + "</span>" +
      '<span class="feat-g">' + f.g + "</span>" +
      "<span>" + f.n + "</span>" +
      mark +
      "</div>"
    );
  }).join("");
  const count = document.getElementById("feat-count");
  const shown = featureSeen.size;
  const inDemo = DEMO_CAN_SHOW.size;
  if (count) {
    count.textContent =
      shown + " shown now · " + inDemo + " can show in demo · " + (DEMO_FEATURES.length - inDemo) + " not in demo";
  }
}

function resetFeaturePanel() {
  featureSeen.clear();
  renderFeaturePanel();
}

function initFeaturePanel() {
  renderFeaturePanel();
}

function demoFeatureShownCount() {
  return featureSeen.size;
}
