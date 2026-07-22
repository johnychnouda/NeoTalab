/** Sum real subscription payments by period */

export function paymentsInMonth(payments, month, year) {
  return payments.filter((p) => {
    if (!p.paid_at) return false;
    const d = new Date(p.paid_at);
    return d.getMonth() === month && d.getFullYear() === year;
  });
}

export function paymentsInYear(payments, year) {
  return payments.filter((p) => {
    if (!p.paid_at) return false;
    return new Date(p.paid_at).getFullYear() === year;
  });
}

export function sumPaymentAmounts(payments) {
  return payments.reduce((s, p) => s + parseFloat(p.amount || 0), 0);
}

/** Current subscription fee for a merchant (monthly or full yearly amount). */
export function merchantSubscriptionFee(m) {
  return m.billing_cycle === "yearly" ? (m.yearly_fee || 0) : (m.monthly_fee || 0);
}

/** Monthly recurring revenue from paying merchants (yearly plans amortized to /mo). */
export function monthlyRecurringRevenue(merchants) {
  return merchants
    .filter((m) => m.subscription_status === "paid" && m.status === "active")
    .reduce((sum, m) => {
      if (m.billing_cycle === "yearly") return sum + (m.yearly_fee || 0) / 12;
      return sum + (m.monthly_fee || 0);
    }, 0);
}

/** Cash expected from merchants marked paid in a calendar month — uses current fee, like Billing. */
export function collectedInMonth(merchants, month, year) {
  return merchants
    .filter((m) => {
      if (!m.last_payment_at) return false;
      const d = new Date(m.last_payment_at);
      return d.getMonth() === month && d.getFullYear() === year;
    })
    .reduce((sum, m) => sum + merchantSubscriptionFee(m), 0);
}

export function collectedInYear(merchants, year) {
  return merchants
    .filter((m) => m.last_payment_at && new Date(m.last_payment_at).getFullYear() === year)
    .reduce((sum, m) => sum + merchantSubscriptionFee(m), 0);
}

export async function fetchAllMerchantPayments(api, merchants) {
  const results = await Promise.all(
    merchants.map(async (m) => {
      try {
        const { payments = [] } = await api("GET", `/api/owner/merchants/${m.id}/payments`);
        return payments.map((p) => ({ ...p, merchantId: m.id, shop_name: m.shop_name }));
      } catch {
        return [];
      }
    })
  );
  return results.flat();
}
