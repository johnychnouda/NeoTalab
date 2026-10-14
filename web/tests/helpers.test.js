import { describe, expect, it } from "vitest";
import { currencyLabel, formatMoney } from "@/lib/money";
import { formatLebaneseMobile, lebaneseMobileError, toLebaneseWhatsapp } from "@/lib/phone";
import { collectedInMonth, monthlyRecurringRevenue, paymentsInMonth, sumPaymentAmounts } from "@/lib/revenue";

describe("money", () => {
  it("formats each currency", () => {
    expect(formatMoney(12)).toBe("$12.00");
    expect(formatMoney("150000", "LBP")).toBe("150,000 LBP");
    expect(formatMoney(3.5, "EUR")).toBe("3.50 EUR");
    expect(formatMoney(null)).toBe("$0.00");
    expect(currencyLabel("USD")).toBe("$");
    expect(currencyLabel("LBP")).toBe("LBP");
  });
});

describe("Lebanese phone numbers", () => {
  it("accepts mobile numbers in any common form", () => {
    for (const n of ["03 123 456", "70123456", "+961 71 123 456", "0096181123456", "961 3 123 456"]) {
      expect(lebaneseMobileError(n), n).toBe("");
    }
  });

  it("rejects empty, landline and short numbers", () => {
    expect(lebaneseMobileError("")).toBe("jn.err.phoneEmpty");
    expect(lebaneseMobileError("01 123 456")).toBe("jn.err.phone");
    expect(lebaneseMobileError("70 123")).toBe("jn.err.phone");
  });

  it("writes numbers for WhatsApp and for reading", () => {
    expect(toLebaneseWhatsapp("03 123 456")).toBe("+9613123456");
    expect(toLebaneseWhatsapp("70 123 456")).toBe("+96170123456");
    expect(formatLebaneseMobile("03123456")).toBe("+961 3 123 456");
    expect(formatLebaneseMobile("+96170123456")).toBe("+961 70 123 456");
  });
});

describe("platform revenue", () => {
  const merchants = [
    { status: "active", subscription_status: "paid", billing_cycle: "monthly", monthly_fee: 30, last_payment_at: "2026-03-05T10:00:00Z" },
    { status: "active", subscription_status: "paid", billing_cycle: "yearly", yearly_fee: 240, last_payment_at: "2026-03-20T10:00:00Z" },
    { status: "active", subscription_status: "unpaid", billing_cycle: "monthly", monthly_fee: 30 },
    { status: "suspended", subscription_status: "paid", billing_cycle: "monthly", monthly_fee: 30 },
  ];

  it("counts only active, paying shops, yearly plans spread over 12 months", () => {
    expect(monthlyRecurringRevenue(merchants)).toBe(50);
  });

  it("adds up what was collected in a month", () => {
    expect(collectedInMonth(merchants, 2, 2026)).toBe(270);
    expect(collectedInMonth(merchants, 3, 2026)).toBe(0);
  });

  it("adds up recorded payments", () => {
    const payments = [{ amount: "30.00", paid_at: "2026-03-05T10:00:00Z" }, { amount: "20", paid_at: "2026-04-01T10:00:00Z" }, { amount: "5" }];
    expect(paymentsInMonth(payments, 2, 2026)).toHaveLength(1);
    expect(sumPaymentAmounts(payments)).toBe(55);
  });
});
