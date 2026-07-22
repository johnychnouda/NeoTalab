/**
 * Owner activity log — stored in localStorage (client-side).
 * Each entry: { id, type, icon, message, at }.
 * Types: merchant | billing | bot | broadcast | system
 */

const KEY = "nt_owner_activity";
const MAX_ENTRIES = 300;

function read() {
  if (typeof window === "undefined") return [];
  try {
    const list = JSON.parse(localStorage.getItem(KEY)) || [];
    // Drop legacy demo seed rows (pre-2026-07)
    const real = list.filter((e) => !String(e.id).startsWith("seed_"));
    if (real.length !== list.length) write(real);
    return real;
  } catch { return []; }
}

function write(list) {
  try { localStorage.setItem(KEY, JSON.stringify(list.slice(0, MAX_ENTRIES))); } catch { /* storage full */ }
}

export function logActivity(type, message, icon) {
  const entry = {
    id: `a_${Date.now()}_${Math.random().toString(36).slice(2, 7)}`,
    type,
    icon: icon || DEFAULT_ICONS[type] || "•",
    message,
    at: new Date().toISOString(),
  };
  write([entry, ...read()]);
  return entry;
}

export function getActivity() {
  return read();
}

export function clearActivity() {
  try { localStorage.removeItem(KEY); } catch { /* noop */ }
}

const DEFAULT_ICONS = {
  merchant: "🏪",
  billing: "💳",
  bot: "🤖",
  broadcast: "📣",
  system: "⚙️",
};

