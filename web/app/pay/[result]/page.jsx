import { notFound } from "next/navigation";
import Brand from "@/components/Brand";
import { getSiteLang } from "@/lib/site-lang";

export const metadata = { title: "Payment — NeoTalab", robots: { index: false } };

const T = {
  en: {
    okTitle: "Payment sent", okBody: "Go back to WhatsApp. As soon as Whish confirms your payment, the shop confirms your order there.",
    koTitle: "Payment not completed", koBody: "Nothing was charged. Go back to WhatsApp to try again or choose cash.",
    back: "Back to WhatsApp",
  },
  ar: {
    okTitle: "تم إرسال الدفع", okBody: "عُد إلى واتساب. فور تأكيد Whish لدفعك، يؤكّد المحل طلبك هناك.",
    koTitle: "لم تكتمل عملية الدفع", koBody: "لم يُخصم أي مبلغ. عُد إلى واتساب للمحاولة مجددًا أو اختر الدفع نقدًا.",
    back: "العودة إلى واتساب",
  },
  fr: {
    okTitle: "Paiement envoyé", okBody: "Retournez sur WhatsApp. Dès que Whish confirme votre paiement, le commerce y confirme votre commande.",
    koTitle: "Paiement non abouti", koBody: "Aucun montant n'a été débité. Retournez sur WhatsApp pour réessayer ou payer en espèces.",
    back: "Retour à WhatsApp",
  },
};

// Whish sends the customer here after paying. This page never confirms anything by itself: the
// order is confirmed on WhatsApp only once NeoTalab has verified the payment with Whish.
export default async function PaymentResult({ params, searchParams }) {
  const { result } = await params;
  if (result !== "success" && result !== "failure") notFound(); // the only two results Whish sends back
  const { lang } = await searchParams;
  const ok = result === "success";
  const t = T[lang] || T[await getSiteLang()]; // ?lang= is the customer's WhatsApp language

  return (
    <div className="login-wrap">
      <div className="login-card" style={{ maxWidth: 440, textAlign: "center" }}>
        <div className="login-logo" style={{ marginBottom: 20 }}><Brand size={36} /></div>
        <div style={{ fontSize: 44, marginBottom: 10 }} aria-hidden="true">{ok ? "✅" : "❌"}</div>
        <h2 style={{ fontSize: 20, fontWeight: 800, marginBottom: 10 }}>{ok ? t.okTitle : t.koTitle}</h2>
        <p style={{ color: "var(--text-muted)", fontSize: 14, lineHeight: 1.6 }}>{ok ? t.okBody : t.koBody}</p>
        <a href="whatsapp://" className="btn-primary" style={{ display: "inline-flex", marginTop: 18 }}>{t.back}</a>
      </div>
    </div>
  );
}
