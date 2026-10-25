import Link from "next/link";
import { SiteShell } from "@/components/public/SiteShell";
import { company } from "@/lib/company";
import { getSiteLang } from "@/lib/site-lang";
import { siteText } from "@/lib/site-i18n";
import Icon from "@/components/public/Icon";
import OrderDemo from "@/components/public/OrderDemo";
import PriceSwitch from "@/components/public/PriceSwitch";
import { orderDemo } from "@/lib/order-demo";
import { getPlan } from "@/lib/api";

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
  const plan = await getPlan();
  const days = plan?.trial_days ?? 3;
  const arDays = days === 1 ? "يوم واحد" : days === 2 ? "يومين" : days <= 10 ? `${days} أيام` : `${days} يومًا`;
  const n = (text) => text.replace("{n}", days).replace("{days}", arDays);
  // As in the chats: the dollar sign after the amount ("30$"); other currencies keep their code.
  const money = (v) => {
    const amount = new Intl.NumberFormat("en-US", { minimumFractionDigits: v % 1 ? 2 : 0, maximumFractionDigits: v % 1 ? 2 : 0 }).format(v);
    return plan.currency === "USD" ? `${amount}$` : `${amount} ${plan.currency}`;
  };
  // The yearly plan is the highlighted one when the owner portal gives it a price.
  const best = plan?.yearly_price > 0 ? "yearly" : "monthly";
  const saving = plan && plan.monthly_price > 0 ? Math.round((1 - plan.yearly_price / (12 * plan.monthly_price)) * 100) : 0;
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
            {t.trust.map((item) => <li key={item}><Icon name="check" size={16} />{n(item)}</li>)}
          </ul>
        </div>
      </section>

      <section className="section" id="demo">
        <div className="section-head">
          <h2>{demo.title}</h2>
        </div>
        <OrderDemo demo={demo} />
      </section>

      <section className="section section-band" id="features">
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

      {plan && plan.monthly_price > 0 ? (
        <section className="section" id="pricing">
          <div className="section-head">
            <h2>{t.pricingTitle}</h2>
            <p className="lead">{n(t.pricingLead)}</p>
          </div>
          {/* Prices and trial days come from the owner portal; a plan without a price is not shown. */}
          <PriceSwitch
            plans={[["monthly", plan.monthly_price, t.perMonth], ["yearly", plan.yearly_price, t.perYear]].filter(([, price]) => price > 0)
              .map(([key, price, per]) => ({ key, label: t[key], price: money(price), per }))}
            initial={best} save={saving > 0 ? t.save.replace("{pct}", saving) : null}
            included={t.included} cta={t.trial} label={t.pricingTitle} />
          <p className="plans-note">{t.metaFees}</p>
        </section>
      ) : null}

      <section className="section faq" id="faq">
        <div className="section-head"><h2>{t.faqTitle}</h2></div>
        <div className="faq-list">
          {t.faq.map(([q, a]) => (
            <details key={q}><summary>{n(q)}</summary><p>{n(a)}</p></details>
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
          <Link href="/contact" className="btn-hero primary">{t.contactUs}<Icon name="arrow" size={18} /></Link>
        </div>
      </section>
    </SiteShell>
  );
}
