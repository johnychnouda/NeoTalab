import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { buildInboxAlerts } from "@/lib/inbox";

const t = (key, vars) => (vars ? `${key} ${JSON.stringify(vars)}` : key);
const at = (day, hour = 10) => new Date(2026, 10, day, hour, 30).toISOString();
const billing = (merchants) => buildInboxAlerts(merchants, [], t).filter((a) => a.category === "billing");

describe("billing reminders in the owner inbox", () => {
  beforeEach(() => { vi.useFakeTimers(); vi.setSystemTime(new Date(2026, 10, 6, 9, 38)); });
  afterEach(() => vi.useRealTimers());

  it("a shop on trial with 2 days left gets one reminder, not two", () => {
    const shop = { id: "a", shop_name: "Shop A", subscription_status: "trial", trial_ends_at: at(8) };
    const items = billing([shop]);
    expect(items).toHaveLength(1);
    expect(items[0].id).toBe("trial_a");
    expect(items[0].sub).toContain("trialLeft");
  });

  it("a shop on trial with 3 days left still gets the payment-due-soon reminder", () => {
    const shop = { id: "b", shop_name: "Shop B", subscription_status: "trial", trial_ends_at: at(9) };
    expect(billing([shop]).map((a) => a.id)).toEqual(["due_b"]);
  });

  it("a paid shop renewing in 2 days gets the payment-due-soon reminder", () => {
    const shop = { id: "c", shop_name: "Shop C", subscription_status: "paid", last_payment_at: at(8).replace("2026-11", "2026-10"), billing_cycle: "monthly" };
    expect(billing([shop]).map((a) => a.id)).toEqual(["due_c"]);
  });

  it("each of two trial shops gets its own single reminder", () => {
    const shops = [
      { id: "a", shop_name: "Shop A", subscription_status: "trial", trial_ends_at: at(8) },
      { id: "d", shop_name: "Shop D", subscription_status: "trial", trial_ends_at: at(7) },
    ];
    expect(billing(shops).map((a) => a.id).sort()).toEqual(["trial_a", "trial_d"]);
  });
});
