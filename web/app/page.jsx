import Link from "next/link";
import { SiteShell } from "@/components/public/SiteShell";
import { company } from "@/lib/company";
import { getSiteLang } from "@/lib/site-lang";
import { siteText } from "@/lib/site-i18n";
import Icon from "@/components/public/Icon";
import OrderDemo from "@/components/public/OrderDemo";
import { orderDemo } from "@/lib/order-demo";
import { getPlan } from "@/lib/api";
import { testimonials } from "@/lib/testimonials";

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

      <section className="proof" aria-label={t.proof.builtOn}>
        <ul className="proof-stats">
          {t.proof.stats.map(([value, label]) => (
            <li key={label}><strong dir="ltr">{value.replace("{shops}", demo.scenarios.length)}</strong><span>{label}</span></li>
          ))}
        </ul>
        <p className="proof-partners">
          <span><Icon name="shield" size={16} />{t.proof.builtOn}</span>
          <span>
            {t.proof.payWith}
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src="/whish.png" alt="" width={18} height={18} /><strong>Whish</strong>
          </span>
        </p>
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

      {testimonials.length > 0 ? (
        <section className="section" id="voices">
          <div className="section-head"><h2>{t.voicesTitle}</h2></div>
          <div className="voices">
            {testimonials.map((v) => (
              <figure className="voice" key={v.name}>
                <blockquote>{v.quote[lang] || v.quote.en}</blockquote>
                <figcaption><strong>{v.name}</strong><span>{[v.shop, v.city].filter(Boolean).join(" · ")}</span></figcaption>
              </figure>
            ))}
          </div>
        </section>
      ) : null}

      {plan && plan.monthly_price > 0 ? (
        <section className="section" id="pricing">
          <div className="section-head">
            <h2>{t.pricingTitle}</h2>
            <p className="lead">{n(t.pricingLead)}</p>
          </div>
          <div className="plans">
            {[["monthly", plan.monthly_price, t.perMonth], ["yearly", plan.yearly_price, t.perYear]].map(([key, price, per]) => (
              <div className={`plan${key === "yearly" ? " plan-best" : ""}`} key={key}>
                <div className="plan-top">
                  <h3>{t[key]}</h3>
                  {key === "yearly" && saving > 0 ? <span className="plan-save">{t.save.replace("{pct}", saving)}</span> : null}
                </div>
                <p className="plan-price"><strong dir="ltr">{money(price)}</strong><span>{per}</span></p>
                <ul className="ticks">
                  {t.included.map((item) => <li key={item}><Icon name="check" size={16} />{item}</li>)}
                </ul>
                <Link href="/join" className={`btn-hero ${key === "yearly" ? "primary" : "ghost"}`}>{n(t.trialButton)}</Link>
              </div>
            ))}
          </div>
        </section>
      ) : null}

      <section className="section faq" id="faq">
        <div className="section-head"><h2>{t.faqTitle}</h2></div>
        <div className="faq-list">
          {t.faq.map(([q, a]) => (
            <details key={q}><summary>{q}</summary><p>{n(a)}</p></details>
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
