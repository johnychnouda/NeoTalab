import Link from "next/link";
import { company } from "@/lib/company";
import { getSiteLang } from "@/lib/site-lang";
import { siteText } from "@/lib/site-i18n";
import CookieConsent from "@/components/public/CookieConsent";
import LangSwitch from "@/components/public/LangSwitch";
import SiteNavLinks from "@/components/public/SiteNavLinks";
import Brand from "@/components/Brand";

/** Company address and country in the visitor's language (English uses the configured values). */
export function place(lang) {
  const t = siteText(lang).place;
  return { address: company.address, country: lang === "en" ? company.country : t.country };
}

export async function SiteShell({ children }) {
  const lang = await getSiteLang();
  const t = siteText(lang);

  return (
    <div className="site">
      <header className="site-header" dir="ltr">
        <Link href="/" aria-label="NeoTalab home" style={{ textDecoration: "none" }}><Brand size={38} /></Link>
        <nav className="site-sections" aria-label={t.nav.sections} dir={lang === "ar" ? "rtl" : "ltr"}>
          <SiteNavLinks links={[["demo", t.nav.demo], ["features", t.nav.features], ["pricing", t.nav.pricing], ["faq", t.nav.faq], ["start", t.nav.start]]} menuLabel={t.nav.menu} login={["/backoffice", t.nav.login]} />
        </nav>
        <div className="site-nav">
          <LangSwitch current={lang} />
          <Link href="/backoffice" className="site-nav-cta">{t.nav.login}</Link>
        </div>
      </header>
      <main className="site-main">{children}</main>
      <SiteFooter lang={lang} />
      {company.whatsapp ? (
        <a className="wa-float" href={`https://wa.me/${company.whatsapp.replace(/\D/g, "")}?text=${encodeURIComponent(t.home.chatHello)}`}
          target="_blank" rel="noopener noreferrer" aria-label={t.home.chatUs} title={t.home.chatUs}>
          <svg width="30" height="30" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 00-8.6 15.1L2 22l5-1.3A10 10 0 1012 2zm0 18.2a8.2 8.2 0 01-4.2-1.1l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1112 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.7.8-.8 1-.3.2-.5.1a6.7 6.7 0 01-3.3-2.9c-.3-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 00-.7.3 3 3 0 00-.9 2.2 5.2 5.2 0 001.1 2.7 11.8 11.8 0 004.5 4c1.7.7 2.3.8 3.2.7a2.7 2.7 0 001.8-1.3 2.2 2.2 0 00.2-1.3c-.1-.1-.3-.2-.6-.3z" /></svg>
        </a>
      ) : null}
      <CookieConsent measurementId={company.gaMeasurementId} text={t.cookie} />
    </div>
  );
}

function SiteFooter({ lang }) {
  const t = siteText(lang).footer;
  const p = place(lang);

  return (
    <footer className="site-footer">
      <div className="site-footer-grid">
        <div>
          <div className="site-footer-brand"><Brand size={32} /></div>
          <p>{t.tagline}</p>
        </div>
        <div>
          <div className="site-footer-title">{t.company}</div>
          <p>{company.legalName}</p>
          {p.address ? <p>{p.address}</p> : null}
          <p>{p.country}</p>
        </div>
        <div>
          <div className="site-footer-title">{t.contact}</div>
          <p><a href={`mailto:${company.email}`}>{company.email}</a></p>
          {company.phone ? <p><a href={`tel:${company.phone.replace(/\s/g, "")}`} dir="ltr">{company.phone}</a></p> : null}
          {company.whatsapp ? <p><a href={`https://wa.me/${company.whatsapp.replace(/\D/g, "")}`}>WhatsApp <span dir="ltr">{company.whatsapp}</span></a></p> : null}
        </div>
        <div>
          <div className="site-footer-title">{t.legal}</div>
          <p><Link href="/privacy">{t.privacy}</Link></p>
          <p><Link href="/terms">{t.terms}</Link></p>
          <p><Link href="/data-deletion">{t.deletion}</Link></p>
        </div>
      </div>
      <div className="site-footer-bottom">
        {company.legalName !== company.brand ? (
          <p>{company.brand} {t.productOf} <bdi>{[company.legalName, p.address].filter(Boolean).join(", ")}</bdi>{lang === "ar" ? "، " : ", "}{p.country}.</p>
        ) : null}
        © {new Date().getFullYear()} {company.legalName}. {t.rights}
      </div>
    </footer>
  );
}

/** `title` and `children` may be objects keyed by language ({ en, ar, fr }). */
export async function LegalPage({ title, children }) {
  const lang = await getSiteLang();
  const pick = (v) => (v && typeof v === "object" && !Array.isArray(v) && ("en" in v) ? (v[lang] ?? v.en) : v);

  return (
    <SiteShell>
      <article className="legal">
        <h1>{pick(title)}</h1>
        <p className="legal-updated">{siteText(lang).footer.updated} {company.policiesUpdated}</p>
        {pick(children)}
      </article>
    </SiteShell>
  );
}
