import { apiFetch } from "@/lib/api";

/**
 * Owner activity history, stored on the server (same on every device).
 * Types: merchant | billing | bot | system
 */
const ICONS = { merchant: "🏪", billing: "💳", bot: "🤖", system: "⚙️" };

/** Fire-and-forget: never blocks or fails the owner's action. */
export function logActivity(type, message) {
  apiFetch("owner", "POST", "/admin/activity", { type, message }).catch(() => {});
}

export async function getActivity() {
  const { activity = [] } = await apiFetch("owner", "GET", "/admin/activity");
  return activity.map((a) => ({ ...a, icon: ICONS[a.type] || "•" }));
}
