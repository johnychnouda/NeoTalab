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
          <SiteNavLinks links={[["demo", t.nav.demo], ["features", t.nav.features], ["faq", t.nav.faq], ["start", t.nav.start]]} />
        </nav>
        <div className="site-nav">
          <LangSwitch current={lang} />
          <Link href="/backoffice" className="site-nav-cta">{t.nav.login}</Link>
        </div>
      </header>
      <main className="site-main">{children}</main>
      <SiteFooter lang={lang} />
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
      <div className="site-footer-bottom">© {new Date().getFullYear()} {company.legalName}. {t.rights}</div>
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
