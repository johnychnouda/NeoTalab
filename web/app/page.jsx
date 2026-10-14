import Link from "next/link";
import { SiteShell } from "@/components/public/SiteShell";
import { company } from "@/lib/company";
import { getSiteLang } from "@/lib/site-lang";
import { siteText } from "@/lib/site-i18n";
import Icon from "@/components/public/Icon";
import OrderDemo from "@/components/public/OrderDemo";
import { orderDemo } from "@/lib/order-demo";

export const metadata = {
  title: "NeoTalab — AI WhatsApp ordering & booking for local businesses",
  description:
    "NeoTalab turns your business WhatsApp into an AI sales assistant: customers order or book in any language, drivers and staff update orders with one tap, and you see everything in your WhatsApp Business app.",
  alternates: { canonical: "/" },
};

export default async function Home() {
  const lang = await getSiteLang();
  const t = siteText(lang).home;
  const demo = orderDemo(lang);
  const jsonLd = {
    "@context": "https://schema.org",
    "@type": "Organization",
    name: company.legalName,
    brand: company.brand,
    url: company.siteUrl,
    logo: `${company.siteUrl}/Logo.png`,
    email: company.email,
    ...(company.phone ? { telephone: company.phone } : {}),
    ...(company.address ? { address: { "@type": "PostalAddress", streetAddress: company.address, addressCountry: company.country } } : {}),
  };

  return (
    <SiteShell>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd) }} />

      <section className="hero">
        <div className="hero-copy">
          <span className="hero-badge"><Icon name="shield" size={16} />{t.badge}</span>
          <h1>{t.h1a}<span>{t.h1b}</span></h1>
          <p>{t.intro}</p>
          <div className="hero-actions">
            <Link href="/join" className="btn-hero primary">{t.trial}<Icon name="arrow" size={18} /></Link>
            <Link href="#demo" className="btn-hero ghost">{t.seeHow}</Link>
          </div>
          <ul className="hero-trust">
            {t.trust.map((item) => <li key={item}><Icon name="check" size={16} />{item}</li>)}
          </ul>
        </div>
      </section>

      <section className="section" id="demo">
        <div className="section-head">
          <h2>{demo.title}</h2>
          <p className="lead">{demo.lead}</p>
        </div>
        <OrderDemo demo={demo} />
      </section>

      <section className="section" id="features">
        <div className="section-head">
          <h2>{t.featuresTitle}</h2>
          <p className="lead">{t.featuresLead}</p>
        </div>
        <div className="features">
          {t.features.map(([icon, title, text]) => (
            <div className="feature" key={title}>
              <span className="card-icon"><Icon name={icon} /></span>
              <div>
                <h3>{title}</h3>
                <p>{text}{icon === "shield" ? <> <Link href="/privacy">{t.privacyLink}</Link></> : null}</p>
              </div>
            </div>
          ))}
        </div>
      </section>

      <section className="section faq" id="faq">
        <div className="section-head"><h2>{t.faqTitle}</h2></div>
        <div className="faq-list">
          {t.faq.map(([q, a]) => (
            <details key={q}><summary>{q}</summary><p>{a}</p></details>
          ))}
        </div>
      </section>

      <section className="cta-band" id="start">
        <h2>{t.ctaTitle}</h2>
        <p>{t.ctaLead}</p>
        <ol className="steps">
          {t.steps.map(([title, text], i) => (
            <li key={title} className="step">
              <span className="step-num">{i + 1}</span>
              <h3>{title}</h3>
              <p>{text}</p>
            </li>
          ))}
        </ol>
        <div className="hero-actions">
          <Link href="/join" className="btn-hero primary">{t.apply}<Icon name="arrow" size={18} /></Link>
          <Link href="/contact" className="btn-hero ghost">{t.contactUs}</Link>
        </div>
      </section>
    </SiteShell>
  );
}
