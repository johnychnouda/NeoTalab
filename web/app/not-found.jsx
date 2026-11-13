import Link from "next/link";
import { SiteShell } from "@/components/public/SiteShell";
import { getSiteLang } from "@/lib/site-lang";

export const metadata = { title: "Page not found — NeoTalab" };

const T = {
  en: { title: "This page doesn't exist", text: "The link may be old, or the address was mistyped.", home: "Back to home", contact: "Contact us" },
  ar: { title: "هذه الصفحة غير موجودة", text: "قد يكون الرابط قديمًا، أو كُتب العنوان بشكل خاطئ.", home: "العودة إلى الرئيسية", contact: "تواصل معنا" },
  fr: { title: "Cette page n'existe pas", text: "Le lien est peut-être ancien, ou l'adresse a été mal saisie.", home: "Retour à l'accueil", contact: "Nous contacter" },
};

export default async function NotFound() {
  const t = T[await getSiteLang()] ?? T.en;
  return (
    <SiteShell>
      <section className="nf">
        <span className="nf-code">404</span>
        <h1>{t.title}</h1>
        <p>{t.text}</p>
        <div className="hero-actions">
          <Link href="/" className="btn-hero primary">{t.home}</Link>
          <Link href="/contact" className="btn-hero ghost">{t.contact}</Link>
        </div>
      </section>
    </SiteShell>
  );
}
