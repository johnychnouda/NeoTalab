/**
 * Message formatting service
 * Features: #58, #61, #62, #64
 */

export function buildOrderSummary(order, status, locale = "ar") {
  const statusMessages = {
    en: {
      confirmed: `✅ *Order Confirmed!*\n\nYour order #${order.id?.slice(-6).toUpperCase()} has been confirmed.\nETA: ~${order.eta_mins || "??"}  minutes.`,
      rejected:  `❌ *Order Rejected*\n\nSorry, we couldn't accept your order.\n${order.reject_reason ? `Reason: ${order.reject_reason}` : ""}`,
      cancelled: `❌ *Order Cancelled*\n\nYour order has been cancelled.\n${order.cancel_reason || ""}`,
    },
    ar: {
      confirmed: `✅ *تم تأكيد طلبك!*\n\nرقم الطلب: #${order.id?.slice(-6).toUpperCase()}\nالوقت المتوقع: ~${order.eta_mins || "??"} دقيقة`,
      rejected:  `❌ *تم رفض الطلب*\n\nعذراً، لم نتمكن من قبول طلبك.\n${order.reject_reason || ""}`,
      cancelled: `❌ *تم إلغاء الطلب*\n\n${order.cancel_reason || ""}`,
    },
    fr: {
      confirmed: `✅ *Commande confirmée!*\n\nCommande #${order.id?.slice(-6).toUpperCase()} confirmée.\nDélai: ~${order.eta_mins || "??"} minutes.`,
      rejected:  `❌ *Commande refusée*\n\nDésolé, nous n'avons pas pu accepter votre commande.`,
      cancelled: `❌ *Commande annulée*`,
    },
  };

  const localeMessages = statusMessages[locale] || statusMessages.en;
  return localeMessages[status] || `Order status: ${status}`;
}

export function buildReceipt(order, items, customer) {
  const locale = customer?.preferred_locale || "ar";
  const itemLines = (items || [])
    .map(i => `  • ${i.product_name} x${i.quantity} — $${parseFloat(i.item_total).toFixed(2)}`)
    .join("\n");

  const receipts = {
    en: `🧾 *Order Receipt*\n\n${itemLines}\n\n──────────────\nSubtotal:    $${parseFloat(order.subtotal).toFixed(2)}\nDelivery:    $${parseFloat(order.delivery_fee).toFixed(2)}\n*Total:      $${parseFloat(order.total).toFixed(2)}*\n\nPayment: ${order.payment_method === "cash" ? "Cash" : "Wish ✓"}\n\nThank you for ordering! 🙏`,
    ar: `🧾 *فاتورة الطلب*\n\n${itemLines}\n\n──────────────\nالمجموع الفرعي: $${parseFloat(order.subtotal).toFixed(2)}\nرسوم التوصيل:  $${parseFloat(order.delivery_fee).toFixed(2)}\n*الإجمالي:      $${parseFloat(order.total).toFixed(2)}*\n\nطريقة الدفع: ${order.payment_method === "cash" ? "كاش" : "ويش ✓"}\n\nشكراً لطلبك! 🙏`,
    fr: `🧾 *Reçu de commande*\n\n${itemLines}\n\n──────────────\nSous-total:  $${parseFloat(order.subtotal).toFixed(2)}\nLivraison:   $${parseFloat(order.delivery_fee).toFixed(2)}\n*Total:      $${parseFloat(order.total).toFixed(2)}*\n\nPaiement: ${order.payment_method === "cash" ? "Espèces" : "Wish ✓"}\n\nMerci pour votre commande! 🙏`,
  };

  return receipts[locale] || receipts.en;
}

export function detectLocale(text, fallback = "ar") {
  if (/[؀-ۿ]/.test(text)) return "ar";
  if (/\b(bonjour|merci|je|commande|livraison)\b/i.test(text)) return "fr";
  if (/[a-zA-Z]/.test(text)) return "en";
  return fallback;
}
