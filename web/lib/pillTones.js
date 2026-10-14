/** Shared pill tones for the owner portal, gray when idle, filled color when selected. `key` is an i18n key. */

export const MERCHANT_FILTERS = [
  { id: "requests", key: "ow.f.requests", tone: "blue" },
  { id: "", key: "ow.f.all", tone: "neutral" },
  { id: "active", key: "ow.f.active", tone: "green" },
  { id: "trial", key: "ow.f.trial", tone: "indigo" },
  { id: "pending", key: "ow.f.pending", tone: "orange" },
  { id: "suspended", key: "ow.f.suspended", tone: "red" },
  { id: "bot_issues", key: "ow.f.waIssues", tone: "rose" },
];

export const BILLING_FILTERS = [
  { id: "", key: "ow.f.all", tone: "neutral" },
  { id: "paid", key: "ow.f.paid", tone: "green" },
  { id: "pending", key: "ow.f.pending", tone: "orange" },
  { id: "trial", key: "ow.f.trial", tone: "indigo" },
];

export const INBOX_ALERT_FILTERS = [
  { id: "all", key: "ow.f.all", tone: "neutral" },
  { id: "requests", key: "ow.f.requests", tone: "blue" },
  { id: "bots", key: "ow.f.whatsapp", tone: "rose" },
  { id: "billing", key: "ow.f.billing", tone: "orange" },
];

export const INBOX_TABS = [
  { id: "alerts", key: "ow.inbox.tabAlerts", tone: "green" },
  { id: "system", key: "ow.inbox.tabSystem", tone: "green" },
  { id: "history", key: "ow.inbox.tabHistory", tone: "green" },
];

export const SETTINGS_TABS = [
  { id: "pricing", key: "ow.set.tabPricing", tone: "green" },
  { id: "whatsapp", key: "ow.f.whatsapp", tone: "green" },
  { id: "alerts", key: "ow.set.tabAlerts", tone: "green" },
  { id: "account", key: "ow.set.tabSecurity", tone: "green" },
];

export const PANEL_TABS = [
  { id: "info", key: "ow.panel.tabInfo", tone: "green" },
  { id: "billing", key: "ow.f.billing", tone: "green" },
  { id: "bot", key: "ow.f.whatsapp", tone: "green" },
];

export function pillClass(tone, active) {
  return `pill-btn pill-tone-${tone || "neutral"}${active ? " active" : ""}`;
}
