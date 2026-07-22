import { getRenewalDate } from "@/lib/api";
import { getPhoneCountry } from "@/lib/phoneCountries";

/** Live alerts — things that still need owner action */
export function buildInboxAlerts(merchants, requests) {
  const thresholdDays = parseInt(
    (typeof window !== "undefined" && localStorage.getItem("nt_overdue_remind_days")) || "3"
  );
  const items = [];

  for (const r of requests) {
    items.push({
      id: `req_${r.id}`,
      category: "requests",
      urgency: 1,
      icon: "📥",
      color: "var(--blue)",
      title: r.shop_name,
      sub: r.business_type || "Join request",
      detail: r.message || [
        r.street,
        r.city,
        r.region,
        r.country ? (getPhoneCountry(r.country)?.name ?? r.country) : null,
      ].filter(Boolean).join(", "),
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
        sub: "Bot is down",
        detail: m.bot_error || "WhatsApp bot needs attention",
        actionUrl: `/owner?m=${m.id}&tab=bot`,
        merchantId: m.id,
      });
    }
    const rd = getRenewalDate(m);
    if (rd) {
      const daysLeft = Math.ceil((rd - Date.now()) / 86400000);
      if (daysLeft > 0 && daysLeft <= thresholdDays) {
        items.push({
          id: `due_${m.id}`,
          category: "billing",
          urgency: 2,
          icon: "💳",
          color: "var(--orange)",
          title: m.shop_name,
          sub: `Renews in ${daysLeft} day${daysLeft !== 1 ? "s" : ""}`,
          detail: "Subscription renewal coming up — confirm payment or send a reminder",
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
          sub: overdue === 0 ? "Renewal due today" : `Overdue ${overdue} day${overdue !== 1 ? "s" : ""}`,
          detail: "Payment is past due — mark as paid or follow up",
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
          sub: left === 0 ? "Trial ends today" : `Trial · ${left}d left`,
          detail: "Trial is ending soon — convert or suspend",
          actionUrl: `/owner/billing?m=${m.id}&filter=trial`,
          merchantId: m.id,
        });
      }
    }
  }

  return items.sort((a, b) => a.urgency - b.urgency);
}
