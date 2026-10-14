"use client";

import Link from "next/link";
import Script from "next/script";
import { useEffect, useState } from "react";

const KEY = "nt_cookie_consent"; // "granted" | "denied"

/**
 * Google Analytics only after an explicit "Accept"; nothing is loaded or stored by Google otherwise.
 */
export default function CookieConsent({ measurementId, text }) {
  const [choice, setChoice] = useState(null); // null = not loaded yet, "" = not chosen

  useEffect(() => {
    try { setChoice(localStorage.getItem(KEY) || ""); } catch { setChoice(""); }
  }, []);

  if (!measurementId) return null;

  const decide = (value) => {
    try { localStorage.setItem(KEY, value); } catch { /* private mode */ }
    setChoice(value);
  };

  return (
    <>
      {choice === "granted" && (
        <>
          <Script src={`https://www.googletagmanager.com/gtag/js?id=${measurementId}`} strategy="afterInteractive" />
          <Script id="ga4" strategy="afterInteractive">
            {`window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','${measurementId}',{anonymize_ip:true});`}
          </Script>
        </>
      )}
      {choice === "" && (
        <div className="cookie-banner" role="dialog" aria-label="Cookie consent">
          <p>
            {text.text}
            {" "}<Link href="/privacy#analytics">{text.more}</Link>
          </p>
          <div className="cookie-actions">
            <button type="button" className="btn-hero ghost" onClick={() => decide("denied")}>{text.decline}</button>
            <button type="button" className="btn-hero primary" onClick={() => decide("granted")}>{text.accept}</button>
          </div>
        </div>
      )}
    </>
  );
}
