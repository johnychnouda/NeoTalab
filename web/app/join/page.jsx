"use client";

import { useState } from "react";
import Link from "next/link";
import FormSelect from "@/components/FormSelect";
import { submitOnboardingApplication } from "@/lib/api";
import { useLang } from "@/lib/i18n";
import { JOIN_BUSINESS_TYPES, JOIN_BUSINESS_TYPE_OTHER, JOIN_LEBANON_REGIONS } from "@/lib/joinFormOptions";
import { DEFAULT_PHONE_COUNTRY, getPhoneCountry, PHONE_COUNTRIES } from "@/lib/phoneCountries";
import { buildWhatsappNumber, validateLocalPhone, validateWhatsappNumber } from "@/lib/phone";

const LANGS = [
  { code: "en", label: "EN" },
  { code: "ar", label: "AR" },
  { code: "fr", label: "FR" },
];

const STEPS = [
  { id: 0, labelKey: "join.step.shop", fields: ["shopName", "businessType", "businessTypeOther"] },
  { id: 1, labelKey: "join.step.location", fields: ["city", "region", "street"] },
  { id: 2, labelKey: "join.step.whatsapp", fields: ["contactName", "phoneLocal", "confirmPhoneLocal", "whatsappBusinessOk", "acceptedTerms"] },
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
  whatsappBusinessOk: false,
  acceptedTerms: false,
  companyWebsite: "",
};

function errorsForStep(stepId, vals) {
  const errors = {};
  if (stepId === 0) {
    if (!vals.shopName.trim()) errors.shopName = true;
    if (!vals.businessType) errors.businessType = true;
    else if (vals.businessType === JOIN_BUSINESS_TYPE_OTHER && !vals.businessTypeOther.trim()) {
      errors.businessTypeOther = true;
    }
  }
  if (stepId === 1) {
    if (!vals.city.trim()) errors.city = true;
    if (!vals.region.trim()) errors.region = true;
    if (!vals.street.trim()) errors.street = true;
  }
  if (stepId === 2) {
    if (!vals.contactName.trim()) errors.contactName = true;
    if (!vals.phoneLocal.trim()) errors.phoneLocal = true;
    if (!vals.confirmPhoneLocal.trim()) errors.confirmPhoneLocal = true;
    if (!vals.whatsappBusinessOk) errors.whatsappBusinessOk = true;
    if (!vals.acceptedTerms) errors.acceptedTerms = true;
  }
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

function JoinPipeline({ step, t }) {
  return (
    <ol className="join-pipeline" aria-label={t("join.pipelineLabel")}>
      {STEPS.map((s, i) => {
        const done = i < step;
        const active = i === step;
        return (
          <li key={s.id} className={`join-pipeline-step${done ? " is-done" : ""}${active ? " is-active" : ""}`}>
            <span className="join-pipeline-dot" aria-hidden="true">
              {done ? (
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" width="12" height="12">
                  <polyline points="20 6 9 17 4 12" />
                </svg>
              ) : (
                i + 1
              )}
            </span>
            <span className="join-pipeline-label">{t(s.labelKey)}</span>
            {i < STEPS.length - 1 && <span className="join-pipeline-line" aria-hidden="true" />}
          </li>
        );
      })}
    </ol>
  );
}

export default function JoinPage() {
  const { t } = useLang();
  const [step, setStep] = useState(0);
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

  const showStepErrors = (message, errors) => {
    setFieldErrors(errors);
    setError(message);
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

  function goNext() {
    setError("");
    const errors = errorsForStep(step, vals);
    if (Object.keys(errors).length > 0) {
      showStepErrors(t("join.err.required"), errors);
      return;
    }
    setFieldErrors({});
    setStep((s) => Math.min(s + 1, STEPS.length - 1));
  }

  function goBack() {
    setError("");
    setFieldErrors({});
    setStep((s) => Math.max(s - 1, 0));
  }

  async function handleSubmit(e) {
    e.preventDefault();
    setError("");
    setFieldErrors({});

    const stepErrors = errorsForStep(2, vals);
    if (Object.keys(stepErrors).length > 0) {
      showStepErrors(
        stepErrors.whatsappBusinessOk && !stepErrors.acceptedTerms && Object.keys(stepErrors).length === 1
          ? t("join.err.whatsappBusiness")
          : stepErrors.acceptedTerms && Object.keys(stepErrors).length === 1
            ? t("join.err.terms")
            : t("join.err.required"),
        stepErrors,
      );
      return;
    }

    if (vals.phoneLocal.trim() !== vals.confirmPhoneLocal.trim()) {
      showStepErrors(t("join.err.waMatch"), { phoneLocal: true, confirmPhoneLocal: true });
      return;
    }

    const localErr = validateLocalPhone(vals.phoneLocal);
    if (localErr) {
      showStepErrors(t(localErr), { phoneLocal: true });
      return;
    }

    const whatsapp = buildWhatsappNumber(vals.phoneCountry, vals.phoneLocal);
    const whatsappErr = validateWhatsappNumber(whatsapp);
    if (whatsappErr) {
      showStepErrors(t(whatsappErr), { phoneLocal: true });
      return;
    }

    const businessType = vals.businessType === JOIN_BUSINESS_TYPE_OTHER
      ? vals.businessTypeOther.trim()
      : vals.businessType;

    setBusy(true);
    try {
      await submitOnboardingApplication({
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
      });
      setSubmitted(true);
    } catch (err) {
      const msg = String(err.message || "");
      const friendly = msg.toLowerCase().includes("already pending")
        ? t("join.err.duplicateWhatsapp")
        : msg || t("join.err.generic");
      showStepErrors(friendly, {});
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

            <JoinPipeline step={step} t={t} />

            <form
              onSubmit={(e) => {
                e.preventDefault();
                if (step < STEPS.length - 1) goNext();
                else handleSubmit(e);
              }}
              noValidate
            >
              {step === 0 && (
                <div className="join-step-panel" key="step-0">
                  <p className="join-step-title">{t("join.step.shopTitle")}</p>
                  <div id="join-field-shopName" className={fieldClass("shopName")}>
                    <label>{t("join.shopName")}</label>
                    <input value={vals.shopName} onChange={(e) => set("shopName", e.target.value)} placeholder="" autoFocus />
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
                        placeholder=""
                        maxLength={60}
                      />
                    </div>
                  )}
                </div>
              )}

              {step === 1 && (
                <div className="join-step-panel" key="step-1">
                  <p className="join-step-title">{t("join.step.locationTitle")}</p>
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
                        placeholder=""
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
                          placeholder=""
                        />
                      )}
                    </div>
                  </div>
                  <div id="join-field-street" className={fieldClass("street")}>
                    <label>{t("join.street")}</label>
                    <input
                      value={vals.street}
                      onChange={(e) => set("street", e.target.value)}
                      placeholder=""
                      autoComplete="street-address"
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
                      placeholder=""
                      rows={2}
                    />
                  </div>
                </div>
              )}

              {step === 2 && (
                <div className="join-step-panel" key="step-2">
                  <p className="join-step-title">{t("join.step.whatsappTitle")}</p>
                  <div id="join-field-contactName" className={fieldClass("contactName")}>
                    <label>{t("join.yourName")}</label>
                    <input value={vals.contactName} onChange={(e) => set("contactName", e.target.value)} placeholder="" />
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
                    <label>{t("join.waNumber")}</label>
                    <p className="join-field-hint">{t("join.waHintBusiness")}</p>
                    <div className="join-phone-input">
                      <span className="join-phone-dial">{phoneCountry.dial}</span>
                      <input
                        type="tel"
                        value={vals.phoneLocal}
                        onChange={(e) => set("phoneLocal", e.target.value)}
                        placeholder=""
                        inputMode="tel"
                        autoComplete="tel-national"
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
                        placeholder=""
                        inputMode="tel"
                        autoComplete="off"
                      />
                    </div>
                  </div>

                  <label id="join-field-whatsappBusinessOk" className={`join-consent${fieldErrors.whatsappBusinessOk ? " field-invalid" : ""}`}>
                    <input
                      type="checkbox"
                      checked={vals.whatsappBusinessOk}
                      onChange={(e) => set("whatsappBusinessOk", e.target.checked)}
                    />
                    <span>{t("join.whatsappBusinessConfirm")}</span>
                  </label>

                  <label id="join-field-acceptedTerms" className={`join-consent${fieldErrors.acceptedTerms ? " field-invalid" : ""}`}>
                    <input type="checkbox" checked={vals.acceptedTerms} onChange={(e) => set("acceptedTerms", e.target.checked)} />
                    <span>
                      {t("join.consent")}{" "}
                      <Link href="/terms" target="_blank">{t("join.terms")}</Link>
                      {" "}{t("join.and")}{" "}
                      <Link href="/privacy" target="_blank">{t("join.privacy")}</Link>
                    </span>
                  </label>
                </div>
              )}

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

              <div id="join-submit-area" className="join-nav">
                {step > 0 ? (
                  <button type="button" className="join-btn-secondary" onClick={goBack} disabled={busy}>
                    {t("join.back")}
                  </button>
                ) : (
                  <span />
                )}
                {step < STEPS.length - 1 ? (
                  <button type="submit" className="btn-primary join-btn-next">
                    {t("join.next")}
                  </button>
                ) : (
                  <button type="submit" className="btn-primary join-btn-next" disabled={busy}>
                    {busy ? t("join.submitting") : t("join.submit")}
                  </button>
                )}
              </div>

              {error && (
                <div className="login-error join-form-error-bottom" role="alert" aria-live="polite">
                  {error}
                </div>
              )}
            </form>
          </>
        )}

        <p className="join-footer">{t("join.footer")}</p>
      </div>
    </div>
  );
}
