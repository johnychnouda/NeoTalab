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
            <Link href="#how" className="btn-hero ghost">{t.seeHow}</Link>
          </div>
          <ul className="hero-trust">
            {t.trust.map((item) => <li key={item}><Icon name="check" size={16} />{item}</li>)}
          </ul>
        </div>
      </section>

      <section className="section" id="demo">
        <h2>{demo.title}</h2>
        <p className="lead">{demo.lead}</p>
        <OrderDemo demo={demo} />
      </section>

      <section className="section" id="how">
        <h2>{t.stepsTitle}</h2>
        <ol className="steps">
          {t.steps.map(([title, text], i) => (
            <li key={title} className="step">
              <span className="step-num">{i + 1}</span>
              <h3>{title}</h3>
              <p>{text}</p>
            </li>
          ))}
        </ol>
      </section>

      <section className="section">
        <h2>{t.rolesTitle}</h2>
        <p className="lead">{t.howLead}</p>
        <div className="cards">
          {[["chat", t.customersTitle, t.customers], ["store", t.youTitle, t.you], ["scooter", t.teamTitle, t.team]].map(([icon, title, items]) => (
            <div className="card" key={title}>
              <span className="card-icon"><Icon name={icon} /></span>
              <h3>{title}</h3>
              <ul className="ticks">{items.map((item) => <li key={item}><Icon name="check" size={16} />{item}</li>)}</ul>
            </div>
          ))}
        </div>
      </section>

      <section className="section" id="payments">
        <h2>{t.payTitle}</h2>
        <p className="lead">{t.payLead}</p>
        <div className="cards">
          {t.pay.map(([title, text], i) => (
            <div className={`card${i === 2 ? " card-accent" : ""}`} key={title}>
              <span className="card-icon"><Icon name={["bag", "cash", "lock"][i]} /></span>
              <h3>{title}</h3>
              <p>{text}</p>
            </div>
          ))}
        </div>
      </section>

      <section className="section" id="businesses">
        <h2>{t.bizTitle}</h2>
        <p className="lead">{t.bizLead}</p>
        <div className="chips">{t.businesses.map((b) => <span key={b} className="chip">{b}</span>)}</div>
      </section>

      <section className="section">
        <h2>{t.whyTitle}</h2>
        <div className="cards cards-4">
          {t.why.map(([title, text], i) => (
            <div className="card" key={title}>
              <span className="card-icon"><Icon name={["clock", "tag", "chat"][i]} /></span>
              <h3>{title}</h3>
              <p>{text}</p>
            </div>
          ))}
          <div className="card">
            <span className="card-icon"><Icon name="shield" /></span>
            <h3>{t.privacyTitle}</h3>
            <p>{t.privacyText} <Link href="/privacy">{t.privacyLink}</Link>.</p>
          </div>
        </div>
      </section>

      <section className="section faq">
        <h2>{t.faqTitle}</h2>
        <div className="faq-list">
          {t.faq.map(([q, a]) => (
            <details key={q}><summary>{q}</summary><p>{a}</p></details>
          ))}
        </div>
      </section>

      <section className="cta-band">
        <h2>{t.ctaTitle}</h2>
        <p>{t.ctaLead}</p>
        <div className="hero-actions">
          <Link href="/join" className="btn-hero primary">{t.apply}<Icon name="arrow" size={18} /></Link>
          <Link href="/contact" className="btn-hero ghost">{t.contactUs}</Link>
        </div>
      </section>
    </SiteShell>
  );
}
