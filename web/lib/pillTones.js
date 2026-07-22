/** Shared pill tones for the owner portal — gray when idle, filled color when selected */

export const MERCHANT_FILTERS = [
  { id: "requests", label: "Requests", tone: "blue" },
  { id: "", label: "All", tone: "neutral" },
  { id: "active", label: "Active", tone: "green" },
  { id: "trial", label: "Trial", tone: "indigo" },
  { id: "pending", label: "Pending", tone: "orange" },
  { id: "suspended", label: "Suspended", tone: "red" },
  { id: "bot_issues", label: "Bot Issues", tone: "rose" },
];

export const BILLING_FILTERS = [
  { id: "", label: "All", tone: "neutral" },
  { id: "paid", label: "Paid", tone: "green" },
  { id: "pending", label: "Pending", tone: "orange" },
  { id: "trial", label: "Trial", tone: "indigo" },
];

export const ANALYTICS_FILTERS = [
  { id: "paying", label: "Who pays me", tone: "green" },
  { id: "late", label: "Late on payment", tone: "orange" },
  { id: "all", label: "All shops", tone: "neutral" },
];

export const INBOX_ALERT_FILTERS = [
  { id: "all", label: "All", tone: "neutral" },
  { id: "requests", label: "Requests", tone: "blue" },
  { id: "bots", label: "Bots", tone: "rose" },
  { id: "billing", label: "Billing", tone: "orange" },
];

export const INBOX_TABS = [
  { id: "alerts", label: "Needs attention", tone: "orange" },
  { id: "history", label: "History", tone: "neutral" },
];

export const SETTINGS_TABS = [
  { id: "pricing", label: "Pricing", tone: "orange" },
  { id: "whatsapp", label: "WhatsApp", tone: "green" },
  { id: "notifications", label: "Notifications", tone: "blue" },
  { id: "account", label: "Security", tone: "neutral" },
];

export const PANEL_TABS = [
  { id: "info", label: "Info", tone: "neutral" },
  { id: "billing", label: "Billing", tone: "orange" },
  { id: "bot", label: "Bot", tone: "rose" },
];

export function pillClass(tone, active) {
  return `pill-btn pill-tone-${tone || "neutral"}${active ? " active" : ""}`;
}
