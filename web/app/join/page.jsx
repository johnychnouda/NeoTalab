"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import FormSelect from "@/components/FormSelect";
import { preloadGoogleMaps } from "@/lib/googleMaps";
import LocationPicker from "@/components/LocationPicker";
import { getTrialDays, submitOnboardingApplication } from "@/lib/api";
import { useLang } from "@/lib/i18n";
import { JOIN_BUSINESS_TYPES, JOIN_BUSINESS_TYPE_OTHER } from "@/lib/joinFormOptions";
import { COUNTRIES, DEFAULT_COUNTRY, getCountry, mobileError, toWhatsapp, formatMobile } from "@/lib/countries";

const STEPS = ["jn.step.shop", "jn.step.place", "jn.step.contact"];

const TYPE_ICONS = {
  "Restaurant / Café": "🍽️",
  "Bakery / Sweets": "🥐",
  "Supermarket / Grocery": "🛒",
  "Butcher / Fruits & Vegetables": "🥩",
  Pharmacy: "💊",
  "Clothing / Shoes": "👕",
  "Electronics / Phones": "📱",
  "Flowers / Gifts": "💐",
  "Water / Gas delivery": "💧",
  "Laundry / Dry cleaning": "🧺",
  "Salon / Barber / Beauty": "💇",
  "Clinic / Dentist": "🩺",
  "Pet Shop": "🐾",
  "Home & Furniture": "🛋️",
  "General Retail": "🛍️",
  Other: "➕",
};

const INITIAL = {
  shopName: "",
  businessType: "",
  businessTypeOther: "",
  country: DEFAULT_COUNTRY,
  location: "",
  contactName: "",
  phone: "",
  message: "",
  acceptedTerms: false,
  companyWebsite: "", // honeypot: people never see it, bots fill it
};

/** Field → i18n error key for the given step (empty object = step is complete). */
function stepErrors(step, v) {
  const e = {};
  if (step === 0) {
    if (!v.shopName.trim()) e.shopName = "jn.err.shopName";
    if (!v.businessType) e.businessType = "jn.err.businessType";
    else if (v.businessType === JOIN_BUSINESS_TYPE_OTHER && !v.businessTypeOther.trim()) e.businessTypeOther = "jn.err.businessTypeOther";
  }
  if (step === 1 && !v.location.trim()) e.location = "jn.err.location";
  if (step === 2) {
    if (!v.contactName.trim()) e.contactName = "jn.err.contactName";
    const phoneErr = mobileError(v.country, v.phone);
    if (phoneErr) e.phone = phoneErr;
    if (!v.acceptedTerms) e.acceptedTerms = "jn.err.terms";
  }
  return e;
}

export default function JoinPage() {
  useEffect(() => { preloadGoogleMaps(); }, []); // step 2 shows the map: start loading it now
  const { t, lang } = useLang();
  const [step, setStep] = useState(0);
  const [vals, setVals] = useState(INITIAL);
  const [errors, setErrors] = useState({});
  const [formError, setFormError] = useState("");
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);
  const [trialDays, setTrialDays] = useState(null);
  const cardRef = useRef(null);

  useEffect(() => { getTrialDays().then(setTrialDays); }, []);

  // Each new step (and the thank-you screen) starts at the top of the card on phones.
  useEffect(() => {
    const top = cardRef.current?.getBoundingClientRect().top;
    if (top !== undefined && top < 0) cardRef.current.scrollIntoView({ behavior: "smooth", block: "start" });
  }, [step, done]);

  function set(key, value) {
    setVals((v) => ({ ...v, [key]: value }));
    setErrors((e) => {
      if (!e[key]) return e;
      const next = { ...e };
      delete next[key];
      return next;
    });
    setFormError("");
  }

  function showErrors(found) {
    setErrors(found);
    const first = Object.keys(found)[0];
    document.getElementById(`jn-${first}`)?.focus({ preventScroll: false });
  }

  function next() {
    const found = stepErrors(step, vals);
    if (Object.keys(found).length) return showErrors(found);
    setErrors({});
    setStep((s) => s + 1);
  }

  async function submit() {
    const found = stepErrors(2, vals);
    if (Object.keys(found).length) return showErrors(found);

    setBusy(true);
    setFormError("");
    try {
      await submitOnboardingApplication({
        shopName: vals.shopName.trim(),
        businessType: vals.businessType === JOIN_BUSINESS_TYPE_OTHER ? vals.businessTypeOther.trim() : vals.businessType,
        country: vals.country,
        location: vals.location.trim(),
        contactName: vals.contactName.trim(),
        whatsapp: toWhatsapp(vals.country, vals.phone),
        message: vals.message.trim() || undefined,
        acceptedTerms: true,
        companyWebsite: vals.companyWebsite,
      });
      setDone(true);
    } catch (err) {
      const msg = String(err.message || "");
      setFormError(
        err.status === 429 ? t("jn.err.tooMany")
          : msg.toLowerCase().includes("already pending") ? t("jn.err.duplicate")
          : t("jn.err.generic"),
      );
    } finally {
      setBusy(false);
    }
  }

  const days = trialDays || 3;
  const country = getCountry(vals.country);
  const trialLabel = t("jn.trial", { days });

  return (
    <main className="jn-page">
      <section className="jn-pitch" aria-labelledby="jn-title">
        <span className="jn-badge">🎁 {trialLabel}</span>
        <h1 id="jn-title">{t("jn.title")}</h1>
        <p className="jn-lead">{t("jn.lead")}</p>
        <ul className="jn-perks">
          <li>{t("jn.perk.chat")}</li>
          <li>{t("jn.perk.ai")}</li>
          <li>{t("jn.perk.free", { days })}</li>
        </ul>
      </section>

      <section ref={cardRef} className="jn-card" aria-live="polite">
        {done ? (
          <Success t={t} phone={formatMobile(vals.country, vals.phone)} days={days} />
        ) : (
          <form onSubmit={(e) => { e.preventDefault(); step < 2 ? next() : submit(); }} noValidate>
            <Progress step={step} t={t} />

            {step === 0 && (
              <div className="jn-step" key="s0">
                <Field id="shopName" label={t("jn.shopName")} error={errors.shopName} t={t}>
                  <input id="jn-shopName" value={vals.shopName} maxLength={150} autoComplete="organization"
                    onChange={(e) => set("shopName", e.target.value)} placeholder={t("jn.ph.shopName")} />
                </Field>

                <fieldset className={`jn-field${errors.businessType ? " is-invalid" : ""}`}>
                  <legend>{t("jn.businessType")}</legend>
                  <div className="jn-types" role="radiogroup" aria-label={t("jn.businessType")}>
                    {JOIN_BUSINESS_TYPES.map((o, i) => (
                      <button key={o.value} id={i === 0 ? "jn-businessType" : undefined} type="button" role="radio"
                        aria-checked={vals.businessType === o.value}
                        className={`jn-type${vals.businessType === o.value ? " is-on" : ""}`}
                        onClick={() => set("businessType", o.value)}>
                        <span aria-hidden="true">{TYPE_ICONS[o.value] || "🏪"}</span>{t(o.labelKey)}
                      </button>
                    ))}
                  </div>
                  {errors.businessType && <p className="jn-error" role="alert">{t(errors.businessType)}</p>}
                </fieldset>

                {vals.businessType === JOIN_BUSINESS_TYPE_OTHER && (
                  <Field id="businessTypeOther" label={t("jn.businessTypeOther")} error={errors.businessTypeOther} t={t}>
                    <input id="jn-businessTypeOther" value={vals.businessTypeOther} maxLength={60}
                      onChange={(e) => set("businessTypeOther", e.target.value)} placeholder={t("jn.ph.businessTypeOther")} />
                  </Field>
                )}
              </div>
            )}

            {step === 1 && (
              <div className="jn-step" key="s1">
                {COUNTRIES.length > 1 && (
                  <Field id="country" label={t("jn.country")} t={t}>
                    <FormSelect id="jn-country" value={vals.country} aria-label={t("jn.country")}
                      options={COUNTRIES.map((c) => ({ value: c.code, label: `${c.flag} ${c.name[lang] || c.name.en}` }))}
                      onChange={(v) => set("country", v)} />
                  </Field>
                )}
                <Field id="location" label={t("jn.loc")} hint={errors.location ? undefined : t("jn.loc.hint")} error={errors.location} t={t}>
                  <LocationPicker value={vals.location} onChange={(v) => set("location", v)} t={t} inputId="jn-location" compact />
                </Field>
              </div>
            )}

            {step === 2 && (
              <div className="jn-step" key="s2">
                <Field id="contactName" label={t("jn.contactName")} error={errors.contactName} t={t}>
                  <input id="jn-contactName" value={vals.contactName} maxLength={120} autoComplete="name"
                    onChange={(e) => set("contactName", e.target.value)} placeholder={t("jn.ph.contactName")} />
                </Field>
                <Field id="phone" label={t("jn.phone")} hint={t("jn.hint.phone")} error={errors.phone}
                  errorVars={{ country: country.name[lang] || country.name.en, example: country.example }} t={t}>
                  <div className="jn-phone" dir="ltr">
                    <span>{country.flag} +{country.dial}</span>
                    <input id="jn-phone" type="tel" inputMode="tel" autoComplete="tel-national" value={vals.phone}
                      onChange={(e) => set("phone", e.target.value)} placeholder={country.example} />
                  </div>
                </Field>
                <div className="jn-note">
                  <strong>💡 {t("jn.waBusiness.title")}</strong>
                  <span>{t("jn.waBusiness.body")}</span>
                </div>
                <Field id="message" label={<>{t("jn.message")} <span className="jn-optional">{t("jn.optional")}</span></>} t={t}>
                  <textarea id="jn-message" rows={2} maxLength={2000} value={vals.message}
                    onChange={(e) => set("message", e.target.value)} placeholder={t("jn.ph.message")} />
                </Field>
                <label className={`jn-check${errors.acceptedTerms ? " is-invalid" : ""}`}>
                  <input id="jn-acceptedTerms" type="checkbox" checked={vals.acceptedTerms} onChange={(e) => set("acceptedTerms", e.target.checked)} />
                  <span>
                    {t("jn.terms.before")} <Link href="/terms" target="_blank">{t("jn.terms.terms")}</Link> {t("jn.terms.and")}{" "}
                    <Link href="/privacy" target="_blank">{t("jn.terms.privacy")}</Link>
                  </span>
                </label>
                {errors.acceptedTerms && <p className="jn-error" role="alert">{t(errors.acceptedTerms)}</p>}
              </div>
            )}

            <div className="jn-honeypot" aria-hidden="true">
              <label htmlFor="jn-website">Website</label>
              <input id="jn-website" tabIndex={-1} autoComplete="off" value={vals.companyWebsite} onChange={(e) => set("companyWebsite", e.target.value)} />
            </div>

            {formError && <p className="jn-form-error" role="alert">{formError}</p>}

            <div className="jn-actions">
              {step > 0 && (
                <button type="button" className="jn-back" onClick={() => { setErrors({}); setStep((s) => s - 1); }} disabled={busy}>
                  {t("jn.back")}
                </button>
              )}
              <button type="submit" className="btn-primary jn-next" disabled={busy}>
                {step < 2 ? t("jn.next") : busy ? t("jn.sending") : t("jn.submit")}
              </button>
            </div>
            <p className="jn-safe">🔒 {t("jn.safe")}</p>
          </form>
        )}
      </section>
    </main>
  );
}

function Field({ id, label, hint, error, errorVars, children, t }) {
  return (
    <div className={`jn-field${error ? " is-invalid" : ""}`}>
      <label htmlFor={`jn-${id}`}>{label}</label>
      {children}
      {error ? <p className="jn-error" role="alert">{t(error, errorVars)}</p> : hint && <p className="jn-hint">{hint}</p>}
    </div>
  );
}

function Progress({ step, t }) {
  return (
    <div className="jn-progress">
      <div className="jn-progress-top">
        <span>{t("jn.stepOf", { n: step + 1, total: STEPS.length })}</span>
        <strong>{t(STEPS[step])}</strong>
      </div>
      <div className="jn-bar" role="progressbar" aria-valuemin={1} aria-valuemax={STEPS.length} aria-valuenow={step + 1}>
        {STEPS.map((k, i) => <span key={k} className={i <= step ? "is-on" : ""} />)}
      </div>
    </div>
  );
}

function Success({ t, phone, days }) {
  return (
    <div className="jn-success">
      <div className="jn-success-icon" aria-hidden="true">✓</div>
      <h2>{t("jn.done.title")}</h2>
      <p>{t("jn.done.body", { phone: `\u2066${phone}\u2069` })}</p>
      <ol className="jn-next-steps">
        <li><strong>{t("jn.done.s1")}</strong><span>{t("jn.done.s1b")}</span></li>
        <li><strong>{t("jn.done.s2")}</strong><span>{t("jn.done.s2b")}</span></li>
        <li><strong>{t("jn.done.s3", { days })}</strong><span>{t("jn.done.s3b")}</span></li>
      </ol>
      <Link href="/" className="jn-back jn-home">{t("jn.done.home")}</Link>
    </div>
  );
}
