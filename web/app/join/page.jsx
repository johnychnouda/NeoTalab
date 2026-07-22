"use client";

import { useState } from "react";
import Link from "next/link";
import FormSelect from "@/components/FormSelect";
import { submitOnboardingApplication } from "@/lib/api";
import { useLang } from "@/lib/i18n";
import { JOIN_BUSINESS_TYPES, JOIN_BUSINESS_TYPE_OTHER, JOIN_LEBANON_REGIONS } from "@/lib/joinFormOptions";
import { DEFAULT_PHONE_COUNTRY, getPhoneCountry, getRegionPlaceholderKey, PHONE_COUNTRIES } from "@/lib/phoneCountries";
import { buildWhatsappNumber, validateLocalPhone, validateWhatsappNumber } from "@/lib/phone";

const LANGS = [
  { code: "en", label: "EN" },
  { code: "ar", label: "AR" },
  { code: "fr", label: "FR" },
];

const INITIAL = {
  shopName: "",
  businessType: "",
  businessTypeOther: "",
  businessCountry: DEFAULT_PHONE_COUNTRY,
  city: "",
  region: "",
  street: "",
  message: "",
  contactName: "",
  phoneCountry: DEFAULT_PHONE_COUNTRY,
  phoneLocal: "",
  confirmPhoneLocal: "",
  acceptedTerms: false,
  companyWebsite: "",
};

const FIELD_ORDER = [
  "shopName",
  "businessType",
  "businessTypeOther",
  "city",
  "region",
  "street",
  "contactName",
  "phoneLocal",
  "confirmPhoneLocal",
  "acceptedTerms",
];

function collectRequiredFieldErrors(vals) {
  const errors = {};
  if (!vals.shopName.trim()) errors.shopName = true;
  if (!vals.businessType) errors.businessType = true;
  else if (vals.businessType === JOIN_BUSINESS_TYPE_OTHER && !vals.businessTypeOther.trim()) {
    errors.businessTypeOther = true;
  }
  if (!vals.city.trim()) errors.city = true;
  if (!vals.region.trim()) errors.region = true;
  if (!vals.street.trim()) errors.street = true;
  if (!vals.contactName.trim()) errors.contactName = true;
  if (!vals.phoneLocal.trim()) errors.phoneLocal = true;
  if (!vals.confirmPhoneLocal.trim()) errors.confirmPhoneLocal = true;
  if (!vals.acceptedTerms) errors.acceptedTerms = true;
  return errors;
}

function JoinLangSwitcher() {
  const { lang, setLang } = useLang();
  return (
    <div className="lang-switcher">
      {LANGS.map(({ code, label }) => (
        <button
          key={code}
          type="button"
          className={`lang-btn${lang === code ? " active" : ""}`}
          onClick={() => setLang(code)}
        >
          {label}
        </button>
      ))}
    </div>
  );
}

export default function JoinPage() {
  const { t } = useLang();
  const [vals, setVals] = useState(INITIAL);
  const [error, setError] = useState("");
  const [fieldErrors, setFieldErrors] = useState({});
  const [busy, setBusy] = useState(false);
  const [submitted, setSubmitted] = useState(false);

  const set = (key, value) => {
    setVals((v) => ({ ...v, [key]: value }));
    setFieldErrors((prev) => {
      const clearsBothPhones = key === "phoneLocal" || key === "confirmPhoneLocal";
      if (!prev[key] && !(clearsBothPhones && (prev.phoneLocal || prev.confirmPhoneLocal))) return prev;
      const next = { ...prev };
      delete next[key];
      if (clearsBothPhones) {
        delete next.phoneLocal;
        delete next.confirmPhoneLocal;
      }
      return next;
    });
    if (error) setError("");
  };

  const fieldClass = (key) => (fieldErrors[key] ? "field field-invalid" : "field");

  const scrollToField = (key) => {
    requestAnimationFrame(() => {
      document.getElementById(`join-field-${key}`)?.scrollIntoView({ behavior: "smooth", block: "center" });
    });
  };

  const scrollToSubmitFeedback = () => {
    requestAnimationFrame(() => {
      document.getElementById("join-submit-area")?.scrollIntoView({ behavior: "smooth", block: "center" });
    });
  };

  const showValidation = (message, errors, focusKey) => {
    setFieldErrors(errors);
    setError(message);
    const first = focusKey || FIELD_ORDER.find((k) => errors[k]);
    if (first) scrollToField(first);
    scrollToSubmitFeedback();
  };

  const setBusinessCountry = (code) => {
    setVals((v) => ({
      ...v,
      businessCountry: code,
      region: code === "LB" ? v.region : "",
    }));
    setFieldErrors((prev) => {
      const next = { ...prev };
      delete next.region;
      return next;
    });
    if (error) setError("");
  };

  const businessCountry = getPhoneCountry(vals.businessCountry);
  const phoneCountry = getPhoneCountry(vals.phoneCountry);
  const isLebanon = vals.businessCountry === "LB";
  const isOtherBusinessType = vals.businessType === JOIN_BUSINESS_TYPE_OTHER;

  const businessTypeOptions = JOIN_BUSINESS_TYPES.map((item) => ({
    value: item.value,
    label: t(item.labelKey),
  }));
  const countryOptions = PHONE_COUNTRIES.map((c) => ({
    value: c.code,
    label: `${c.flag} ${c.name}`,
  }));
  const phoneCountryOptions = PHONE_COUNTRIES.map((c) => ({
    value: c.code,
    label: `${c.flag} ${c.name} (${c.dial})`,
  }));
  const regionOptions = JOIN_LEBANON_REGIONS.map((item) => ({
    value: item.value,
    label: t(item.labelKey),
  }));

  async function handleSubmit(e) {
    e.preventDefault();
    setError("");
    setFieldErrors({});

    // Honeypot — bots only; show fake success without hitting the API
    if (vals.companyWebsite.trim()) {
      setSubmitted(true);
      return;
    }

    const whatsapp = buildWhatsappNumber(vals.phoneCountry, vals.phoneLocal);
    const businessType = vals.businessType === JOIN_BUSINESS_TYPE_OTHER
      ? vals.businessTypeOther.trim()
      : vals.businessType;
    const payload = {
      shopName: vals.shopName.trim(),
      businessType,
      country: vals.businessCountry,
      city: vals.city.trim(),
      region: vals.region.trim(),
      street: vals.street.trim(),
      message: vals.message.trim() || undefined,
      contactName: vals.contactName.trim(),
      whatsapp,
      acceptedTerms: vals.acceptedTerms,
    };

    const requiredErrors = collectRequiredFieldErrors(vals);
    if (Object.keys(requiredErrors).length > 0) {
      showValidation(t("join.err.required"), requiredErrors);
      return;
    }

    if (vals.phoneLocal.trim() !== vals.confirmPhoneLocal.trim()) {
      showValidation(t("join.err.waMatch"), { phoneLocal: true, confirmPhoneLocal: true }, "phoneLocal");
      return;
    }

    const localErr = validateLocalPhone(vals.phoneLocal);
    if (localErr) {
      showValidation(t(localErr), { phoneLocal: true }, "phoneLocal");
      return;
    }

    const whatsappErr = validateWhatsappNumber(whatsapp);
    if (whatsappErr) {
      showValidation(t(whatsappErr), { phoneLocal: true }, "phoneLocal");
      return;
    }

    setBusy(true);
    try {
      await submitOnboardingApplication(payload);
      setSubmitted(true);
    } catch (err) {
      const msg = String(err.message || "");
      const friendly = msg.toLowerCase().includes("already pending")
        ? t("join.err.duplicateWhatsapp")
        : msg || t("join.err.generic");
      showValidation(friendly, {}, null);
      setBusy(false);
    }
  }

  return (
    <div className="login-wrap">
      <div className={`login-card join-form-card${submitted ? " join-form-card--success" : ""}`}>
        <JoinLangSwitcher />

        <div className="login-logo">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src="/Logo1.png" alt="NeoTalab" />
        </div>

        {submitted ? (
          <div className="join-success">
            <div className="join-success-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                <polyline points="20 6 9 17 4 12" />
              </svg>
            </div>
            <h2>{t("join.success.title")}</h2>
            <p className="join-success-body">{t("join.success.body")}</p>
          </div>
        ) : (
          <>
            <div className="join-trial-badge">{t("join.trial")}</div>
            <h2>{t("join.title")}</h2>
            <p className="login-sub">{t("join.sub")}</p>

            <form onSubmit={handleSubmit} noValidate>
            <p className="join-section-title">{t("join.section.business")}</p>
            <div id="join-field-shopName" className={fieldClass("shopName")}>
              <label>{t("join.shopName")}</label>
              <input value={vals.shopName} onChange={(e) => set("shopName", e.target.value)} placeholder="Joe's Snacks" required />
            </div>
            <div id="join-field-businessType" className={fieldClass("businessType")}>
              <label>{t("join.businessType")}</label>
              <FormSelect
                value={vals.businessType}
                onChange={(v) => {
                  set("businessType", v);
                  if (v !== JOIN_BUSINESS_TYPE_OTHER) set("businessTypeOther", "");
                }}
                placeholder={t("join.selectType")}
                options={businessTypeOptions}
                aria-label={t("join.businessType")}
                invalid={!!fieldErrors.businessType}
              />
            </div>
            {isOtherBusinessType && (
              <div id="join-field-businessTypeOther" className={fieldClass("businessTypeOther")}>
                <label>{t("join.businessTypeOther")}</label>
                <input
                  value={vals.businessTypeOther}
                  onChange={(e) => set("businessTypeOther", e.target.value)}
                  placeholder={t("join.businessTypeOtherPlaceholder")}
                  maxLength={60}
                  required
                />
              </div>
            )}

            <p className="join-section-title">{t("join.section.location")}</p>
            <div className="field">
              <label>{t("join.businessCountry")}</label>
              <FormSelect
                value={vals.businessCountry}
                onChange={setBusinessCountry}
                options={countryOptions}
                aria-label={t("join.businessCountry")}
              />
            </div>
            <div className="join-field-row">
              <div id="join-field-city" className={fieldClass("city")}>
                <label>{t("join.city")}</label>
                <input
                  value={vals.city}
                  onChange={(e) => set("city", e.target.value)}
                  placeholder={businessCountry.cityPlaceholder || "City"}
                  required
                />
              </div>
              <div id="join-field-region" className={fieldClass("region")}>
                <label>{isLebanon ? t("join.governorate") : t("join.stateProvince")}</label>
                {isLebanon ? (
                  <FormSelect
                    value={vals.region}
                    onChange={(v) => set("region", v)}
                    placeholder={t("join.selectRegion")}
                    options={regionOptions}
                    aria-label={t("join.governorate")}
                    invalid={!!fieldErrors.region}
                  />
                ) : (
                  <input
                    value={vals.region}
                    onChange={(e) => set("region", e.target.value)}
                    placeholder={t(getRegionPlaceholderKey(vals.businessCountry))}
                    required
                  />
                )}
              </div>
            </div>
            <div id="join-field-street" className={fieldClass("street")}>
              <label>{t("join.street")}</label>
              <input
                value={vals.street}
                onChange={(e) => set("street", e.target.value)}
                placeholder={t("join.streetPlaceholder")}
                autoComplete="street-address"
                required
              />
            </div>
            <div className="field">
              <label>
                {t("join.message")}{" "}
                <span className="join-optional">({t("join.optional")})</span>
              </label>
              <textarea
                value={vals.message}
                onChange={(e) => set("message", e.target.value)}
                placeholder={t("join.messagePlaceholder")}
                rows={3}
              />
            </div>

            <p className="join-section-title">{t("join.section.contact")}</p>
            <div id="join-field-contactName" className={fieldClass("contactName")}>
              <label>{t("join.yourName")}</label>
              <input value={vals.contactName} onChange={(e) => set("contactName", e.target.value)} placeholder="Ahmad Khalil" required />
            </div>
            <div className="field">
              <label>{t("join.waCountry")}</label>
              <FormSelect
                value={vals.phoneCountry}
                onChange={(v) => set("phoneCountry", v)}
                options={phoneCountryOptions}
                aria-label={t("join.waCountry")}
              />
            </div>
            <div id="join-field-phoneLocal" className={fieldClass("phoneLocal")}>
              <label>
                {t("join.waNumber")}{" "}
                <span className="join-optional">({t("join.waHint")})</span>
              </label>
              <div className="join-phone-input">
                <span className="join-phone-dial">{phoneCountry.dial}</span>
                <input
                  type="tel"
                  value={vals.phoneLocal}
                  onChange={(e) => set("phoneLocal", e.target.value)}
                  placeholder={phoneCountry.placeholder}
                  inputMode="tel"
                  autoComplete="tel-national"
                  required
                />
              </div>
            </div>
            <div id="join-field-confirmPhoneLocal" className={fieldClass("confirmPhoneLocal")}>
              <label>{t("join.confirmWa")}</label>
              <div className="join-phone-input">
                <span className="join-phone-dial">{phoneCountry.dial}</span>
                <input
                  type="tel"
                  value={vals.confirmPhoneLocal}
                  onChange={(e) => set("confirmPhoneLocal", e.target.value)}
                  placeholder={t("join.confirmWaPlaceholder")}
                  inputMode="tel"
                  autoComplete="off"
                  required
                />
              </div>
            </div>

            <div className="join-honeypot" aria-hidden="true">
              <input
                type="text"
                name="hp_field"
                value={vals.companyWebsite}
                onChange={(e) => set("companyWebsite", e.target.value)}
                tabIndex={-1}
                autoComplete="off"
              />
            </div>

            <div id="join-submit-area">
            <label id="join-field-acceptedTerms" className={`join-consent${fieldErrors.acceptedTerms ? " field-invalid" : ""}`}>
              <input type="checkbox" checked={vals.acceptedTerms} onChange={(e) => set("acceptedTerms", e.target.checked)} />
              <span>
                {t("join.consent")}{" "}
                <Link href="/terms" target="_blank">{t("join.terms")}</Link>
                {" "}{t("join.and")}{" "}
                <Link href="/privacy" target="_blank">{t("join.privacy")}</Link>
              </span>
            </label>

            <button type="submit" className="btn-primary" disabled={busy || !vals.acceptedTerms} style={{ width: "100%" }}>
              {busy ? t("join.submitting") : t("join.submit")}
            </button>
            {error && (
              <div className="login-error join-form-error-bottom" role="alert" aria-live="polite">
                {error}
              </div>
            )}
            </div>
          </form>
          </>
        )}

        <p className="join-footer">{t("join.footer")}</p>
      </div>
    </div>
  );
}
