import Link from "next/link";
import { LegalPage, place } from "@/components/public/SiteShell";
import { company } from "@/lib/company";
import { getSiteLang, pageMetadata } from "@/lib/site-lang";

export function generateMetadata() {
  return pageMetadata("contact", {
    description: "Contact NeoTalab: sales, support and privacy requests.",
    alternates: { canonical: "/contact" },
  });
}

const T = {
  en: {
    title: "Contact us", reply: "We usually answer within one business day.", company: "Company", email: "Email", phone: "Phone",
    want: "Want NeoTalab for your business?", apply: "Apply for a free trial", applyRest: " — we connect your WhatsApp number and set up your menu with you.",
    privacy: "Privacy requests", see: "See ", deletion: "data deletion instructions", and: " and our ", policy: "privacy policy",
  },
  ar: {
    title: "تواصل معنا", reply: "نردّ عادةً خلال يوم عمل واحد.", company: "الشركة", email: "البريد الإلكتروني", phone: "الهاتف",
    want: "تريد NeoTalab لعملك؟", apply: "قدّم طلب تجربة مجانية", applyRest: " — نربط رقم واتساب الخاص بك ونجهّز قائمتك معك.",
    privacy: "طلبات الخصوصية", see: "راجع ", deletion: "تعليمات حذف البيانات", and: " و", policy: "سياسة الخصوصية",
  },
  fr: {
    title: "Nous contacter", reply: "Nous répondons généralement sous un jour ouvré.", company: "Société", email: "E-mail", phone: "Téléphone",
    want: "Vous voulez NeoTalab pour votre commerce ?", apply: "Demandez un essai gratuit", applyRest: " — nous connectons votre numéro WhatsApp et préparons votre menu avec vous.",
    privacy: "Demandes de confidentialité", see: "Consultez les ", deletion: "instructions de suppression des données", and: " et notre ", policy: "politique de confidentialité",
  },
};

export default async function ContactPage() {
  const lang = await getSiteLang();
  const t = T[lang];
  const p = place(lang);
  const wa = company.whatsapp ? company.whatsapp.replace(/\D/g, "") : "";

  return (
    <LegalPage title={t.title}>
      <p>{t.reply}</p>

      <h2>{t.company}</h2>
      <p>
        {company.legalName}<br />
        {p.address ? <>{p.address}<br /></> : null}
        {p.country}
      </p>

      <h2>{t.email}</h2>
      <p><a href={`mailto:${company.email}`}>{company.email}</a></p>

      {company.phone ? (<><h2>{t.phone}</h2><p><a href={`tel:${company.phone.replace(/\s/g, "")}`} dir="ltr">{company.phone}</a></p></>) : null}
      {wa ? (<><h2>WhatsApp</h2><p><a href={`https://wa.me/${wa}`} dir="ltr">{company.whatsapp}</a></p></>) : null}

      <h2>{t.want}</h2>
      <p><Link href="/join">{t.apply}</Link>{t.applyRest}</p>

      <h2>{t.privacy}</h2>
      <p>{t.see}<Link href="/data-deletion">{t.deletion}</Link>{t.and}<Link href="/privacy">{t.policy}</Link>.</p>
    </LegalPage>
  );
}
