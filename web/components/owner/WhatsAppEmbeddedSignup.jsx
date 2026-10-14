"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useLang } from "@/lib/i18n";

const FB_SDK_URL = "https://connect.facebook.net/en_US/sdk.js";
const SIGNUP_TIMEOUT_MS = 180000;
const POPUP_WATCH_MS = 4000;

let sdkPromise = null;

function loadFacebookSdk(appId, graphVersion) {
  if (typeof window === "undefined") return Promise.resolve(false);
  if (window.FB) return Promise.resolve(true);
  if (!appId) return Promise.resolve(false);
  if (sdkPromise) return sdkPromise;

  sdkPromise = new Promise((resolve) => {
    window.fbAsyncInit = function fbAsyncInit() {
      window.FB.init({
        appId,
        cookie: true,
        xfbml: false,
        version: graphVersion || "v26.0",
      });
      resolve(true);
    };

    const existing = document.getElementById("facebook-jssdk");
    if (existing) {
      existing.addEventListener("load", () => resolve(!!window.FB));
      if (window.FB) resolve(true);
      return;
    }

    const script = document.createElement("script");
    script.id = "facebook-jssdk";
    script.src = FB_SDK_URL;
    script.async = true;
    script.defer = true;
    script.crossOrigin = "anonymous";
    script.onload = () => {
      if (window.FB) resolve(true);
    };
    script.onerror = () => {
      sdkPromise = null;
      resolve(false);
    };
    document.body.appendChild(script);
  });

  return sdkPromise;
}

function parseEmbeddedSignupMessage(event) {
  if (event.origin !== "https://www.facebook.com" && event.origin !== "https://web.facebook.com") {
    return null;
  }
  try {
    const data = typeof event.data === "string" ? JSON.parse(event.data) : event.data;
    if (data?.type !== "WA_EMBEDDED_SIGNUP") return null;
    return data;
  } catch {
    return null;
  }
}

/**
 * Meta WhatsApp Embedded Signup.
 * FB.login MUST run synchronously from the click handler (no await before it),
 * or browsers block the popup and the UI stays on "Connecting…".
 */
export default function WhatsAppEmbeddedSignup({
  merchantId,
  api,
  toast,
  onConnected,
  label,
  reconnectLabel,
  connected = false,
  disabled = false,
  compact = false,
  buttonId,
  // Where the signup result is sent; defaults to the shop's number. NeoTalab's own number uses
  // /admin/whatsapp/platform/embedded-signup.
  endpoint,
}) {
  const { t } = useLang();
  // Callbacks below are memoised; read the translator through a ref so they always use the current language.
  const tRef = useRef(t);
  tRef.current = t;
  const T = (key) => tRef.current(key);
  const [config, setConfig] = useState(null);
  const [sdkReady, setSdkReady] = useState(false);
  const [loading, setLoading] = useState(false);
  const [waitingForWhatsapp, setWaitingForWhatsapp] = useState(false);
  const signupRef = useRef({ code: null, phoneNumberId: null, wabaId: null, coexistence: false });
  const listenerRef = useRef(null);
  const timeoutRef = useRef(null);
  const popupWatchRef = useRef(null);
  const finishSignupRef = useRef(null);
  const openedPopupRef = useRef(false);

  const clearSignupTimeout = useCallback(() => {
    if (timeoutRef.current) {
      clearTimeout(timeoutRef.current);
      timeoutRef.current = null;
    }
    if (popupWatchRef.current) {
      clearTimeout(popupWatchRef.current);
      popupWatchRef.current = null;
    }
  }, []);

  const resetSignup = useCallback(() => {
    clearSignupTimeout();
    signupRef.current = { code: null, phoneNumberId: null, wabaId: null, coexistence: false };
    openedPopupRef.current = false;
    if (listenerRef.current) {
      window.removeEventListener("message", listenerRef.current);
      listenerRef.current = null;
    }
    setWaitingForWhatsapp(false);
    setLoading(false);
  }, [clearSignupTimeout]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const data = await api("GET", "/admin/whatsapp/embedded-signup/config");
        if (cancelled) return;
        setConfig(data);
        if (data?.enabled && data?.appId) {
          const ready = await loadFacebookSdk(data.appId, data.graphVersion || data.graph_version);
          if (!cancelled) setSdkReady(!!ready);
        }
      } catch {
        if (!cancelled) setConfig({ enabled: false });
      }
    })();
    return () => {
      cancelled = true;
      clearSignupTimeout();
      if (listenerRef.current) {
        window.removeEventListener("message", listenerRef.current);
        listenerRef.current = null;
      }
    };
  }, [api, clearSignupTimeout]);

  const finishSignup = useCallback(async () => {
    const { code, phoneNumberId, wabaId, coexistence } = signupRef.current;
    if (!code || !phoneNumberId || !wabaId) {
      toast(T("ow.es.incomplete"), "error");
      resetSignup();
      return;
    }

    clearSignupTimeout();
    setWaitingForWhatsapp(false);

    try {
      const data = await api("POST", endpoint || `/admin/merchants/${merchantId}/whatsapp/embedded-signup`, {
        code,
        phoneNumberId,
        wabaId,
        coexistence: !!coexistence,
      });
      toast(T("ow.es.connected"));
      onConnected?.(data.merchant || data.settings || data.data);
    } catch (e) {
      toast(e.message || T("ow.es.failed"), "error");
    } finally {
      resetSignup();
    }
  }, [api, merchantId, endpoint, onConnected, toast, resetSignup, clearSignupTimeout]);

  finishSignupRef.current = finishSignup;

  const maybeFinish = useCallback(() => {
    const { code, phoneNumberId, wabaId } = signupRef.current;
    if (code && phoneNumberId && wabaId) {
      finishSignupRef.current?.();
    }
  }, []);

  function startSignup() {
    if (!config?.enabled) {
      toast(T("ow.es.config"), "error");
      return;
    }

    const configId = String(config.configId || config.config_id || "").trim();
    const appId = String(config.appId || config.app_id || "").trim();
    if (!configId || !appId) {
      toast(T("ow.es.config"), "error");
      return;
    }

    // No await before FB.login: it would lose the click and the browser would block the popup.
    if (!window.FB || !sdkReady) {
      toast(T("ow.es.sdkLoading"), "error");
      loadFacebookSdk(appId, config.graphVersion || config.graph_version).then((ok) => setSdkReady(!!ok));
      return;
    }

    clearSignupTimeout();
    signupRef.current = { code: null, phoneNumberId: null, wabaId: null, coexistence: false };
    openedPopupRef.current = false;
    setWaitingForWhatsapp(false);
    setLoading(true);

    timeoutRef.current = setTimeout(() => {
      toast(T("ow.es.timeout"), "error");
      resetSignup();
    }, SIGNUP_TIMEOUT_MS);

    // If no popup activity soon, browser almost certainly blocked it.
    popupWatchRef.current = setTimeout(() => {
      if (!openedPopupRef.current && !signupRef.current.code) {
        toast(T("ow.es.noPopup"), "error");
        resetSignup();
      }
    }, POPUP_WATCH_MS);

    if (listenerRef.current) {
      window.removeEventListener("message", listenerRef.current);
    }

    listenerRef.current = (event) => {
      const data = parseEmbeddedSignupMessage(event);
      if (!data) return;
      openedPopupRef.current = true;

      if (data.event === "FINISH" || data.event === "FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING") {
        const payload = data.data || {};
        signupRef.current.phoneNumberId = payload.phone_number_id || payload.phoneNumberId || null;
        signupRef.current.wabaId = payload.waba_id || payload.wabaId || null;
        signupRef.current.coexistence = data.event === "FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING";
        if (!signupRef.current.phoneNumberId || !signupRef.current.wabaId) {
          toast(T("ow.es.noNumber"), "error");
          resetSignup();
          return;
        }
        maybeFinish();
      } else if (data.event === "CANCEL") {
        toast(T("ow.es.cancelled"), "info");
        resetSignup();
      } else if (data.event === "ERROR") {
        const msg = data.data?.error_message || data.data?.message || T("ow.es.failed");
        toast(msg, "error");
        resetSignup();
      }
    };

    window.addEventListener("message", listenerRef.current);

    // Without config_id Facebook shows "Invalid parameter: config_id is required".
    const loginOptions = {
      config_id: configId,
      response_type: "code",
      override_default_response_type: true,
      extras: {
        setup: {},
        // Shops keep the WhatsApp Business app on their number (Meta "coexistence"): they scan a QR code.
        featureType: "whatsapp_business_app_onboarding",
      },
    };

    try {
      window.FB.login((response) => {
        openedPopupRef.current = true;
        if (response.authResponse?.code) {
          signupRef.current.code = response.authResponse.code;
          maybeFinish();
          if (!signupRef.current.phoneNumberId || !signupRef.current.wabaId) {
            setWaitingForWhatsapp(true);
            toast(T("ow.es.keepGoing"), "info");
          }
          return;
        }

        const errMsg = response?.error?.message || "";
        if (/config_id/i.test(errMsg)) {
          toast(T("ow.es.config"), "error");
        } else if (response.status === "not_authorized") {
          toast(T("ow.es.cancelled"), "info");
        } else if (response.authResponse?.accessToken && !response.authResponse?.code) {
          toast(T("ow.es.config"), "error");
        } else {
          toast(T("ow.es.incomplete"), "error");
        }
        resetSignup();
      }, loginOptions);
    } catch (e) {
      toast(e?.message || T("ow.es.noPopup"), "error");
      resetSignup();
    }
  }

  if (!config) {
    return (
      <button type="button" className="paction-btn" disabled style={{ justifyContent: "center" }}>
        {t("db.loading")}
      </button>
    );
  }

  if (!config.enabled) {
    return (
      <p style={{ fontSize: 12, color: "var(--text-muted)", lineHeight: 1.5, margin: 0 }}>{t("ow.es.config")}</p>
    );
  }

  const btnLabel = loading ? t("ow.es.connecting") : connected ? (reconnectLabel || t("ow.wa.reconnect")) : (label || t("ow.wa.connect"));
  const readyHint = !sdkReady ? t("db.loading") : null;
  const configIdPreview = String(config.configId || config.config_id || "").trim();

  return (
    <div style={{ display: "grid", gap: 8 }}>
      <button
        id={buttonId}
        type="button"
        className={compact ? "btn-primary" : "paction-btn paction-primary"}
        style={{ justifyContent: "center", marginBottom: compact ? 0 : undefined, width: compact ? undefined : "100%" }}
        onClick={startSignup}
        disabled={disabled || loading || !sdkReady}
      >
        {connected ? "🔄 " : "📱 "}{readyHint || btnLabel}
      </button>
      {loading && (
        <button
          type="button"
          className="paction-btn"
          style={{ justifyContent: "center", width: "100%" }}
          onClick={() => {
            toast(t("ow.es.cancelled"), "info");
            resetSignup();
          }}
        >
          {t("db.cancel")}
        </button>
      )}
      {waitingForWhatsapp && (
        <p style={{ fontSize: 12, color: "var(--text-muted)", lineHeight: 1.5, margin: 0 }}>{t("ow.es.waiting")}</p>
      )}
      {!loading && sdkReady && !configIdPreview && (
        <p style={{ fontSize: 11, color: "var(--text-muted)", lineHeight: 1.45, margin: 0 }}>{t("ow.es.config")}</p>
      )}
    </div>
  );
}
