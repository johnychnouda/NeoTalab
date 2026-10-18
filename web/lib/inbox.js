import { getRenewalDate } from "@/lib/api";
import { mapPin } from "@/components/LocationPicker";
import { joinOptionLabel } from "@/lib/joinFormOptions";

/** Renewals this close (in days) show up as "renews soon". */
const RENEWAL_WARNING_DAYS = 3;

/** Live alerts, things that still need owner action. `t` is the useLang() translator. */
export function buildInboxAlerts(merchants, requests, t) {
  const items = [];

  for (const r of requests) {
    items.push({
      id: `req_${r.id}`,
      category: "requests",
      urgency: 1,
      icon: "📥",
      color: "var(--blue)",
      title: r.shop_name,
      sub: joinOptionLabel(t, r.business_type) || t("ow.inbox.joinRequest"),
      detail: r.message || (mapPin(r.location) ? `https://www.google.com/maps?q=${mapPin(r.location)}` : [r.street, r.city, joinOptionLabel(t, r.region)].filter(Boolean).join(", ")),
      actionUrl: `/owner?filter=requests&req=${r.id}`,
      requestId: r.id,
    });
  }

  for (const m of merchants) {
    if (m.bot_status === "error") {
      items.push({
        id: `bot_${m.id}`,
        category: "bots",
        urgency: 0,
        icon: "🤖",
        color: "var(--red)",
        title: m.shop_name,
        sub: t("ow.inbox.waDown"),
        detail: m.bot_error || t("ow.inbox.waDownDetail"),
        actionUrl: `/owner?m=${m.id}&tab=bot`,
        merchantId: m.id,
      });
    }
    const rd = getRenewalDate(m);
    if (rd) {
      const daysLeft = Math.ceil((rd - Date.now()) / 86400000);
      if (daysLeft > 0 && daysLeft <= RENEWAL_WARNING_DAYS) {
        items.push({
          id: `due_${m.id}`,
          category: "billing",
          urgency: 2,
          icon: "💳",
          color: "var(--orange)",
          title: m.shop_name,
          sub: t("ow.inbox.renewsIn", { n: daysLeft }),
          detail: t("ow.inbox.renewsDetail"),
          actionUrl: `/owner/billing?m=${m.id}&filter=pending`,
          merchantId: m.id,
        });
      } else if (daysLeft <= 0 && m.subscription_status === "pending") {
        const overdue = Math.abs(daysLeft);
        items.push({
          id: `due_${m.id}`,
          category: "billing",
          urgency: 1,
          icon: "💳",
          color: "var(--red)",
          title: m.shop_name,
          sub: overdue === 0 ? t("ow.inbox.dueToday") : t("ow.inbox.overdue", { n: overdue }),
          detail: t("ow.inbox.overdueDetail"),
          actionUrl: `/owner/billing?m=${m.id}&filter=pending`,
          merchantId: m.id,
        });
      }
    }
    if (m.subscription_status === "trial" && m.trial_ends_at) {
      const left = Math.ceil((new Date(m.trial_ends_at) - Date.now()) / 86400000);
      if (left >= 0 && left <= 2) {
        items.push({
          id: `trial_${m.id}`,
          category: "billing",
          urgency: 3,
          icon: "⏳",
          color: "#a78bfa",
          title: m.shop_name,
          sub: left === 0 ? t("ow.inbox.trialToday") : t("ow.inbox.trialLeft", { n: left }),
          detail: t("ow.inbox.trialDetail"),
          actionUrl: `/owner/billing?m=${m.id}&filter=trial`,
          merchantId: m.id,
        });
      }
    }
  }

  return items.sort((a, b) => a.urgency - b.urgency);
}
