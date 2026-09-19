"use client";

import { useCallback, useEffect, useRef, useState } from "react";

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
        version: graphVersion || "v21.0",
      });
      resolve(true);
    };

    const existing = document.getElementById("facebook-jssdk");
    if (existing) {
      existing.addEventListener("load", () => resolve(!!window.FB));
      // If script already loaded before listener, FB.init may have run
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
  label = "Connect WhatsApp",
  reconnectLabel = "Reconnect WhatsApp",
  connected = false,
  disabled = false,
  compact = false,
  buttonId,
}) {
  const [config, setConfig] = useState(null);
  const [sdkReady, setSdkReady] = useState(false);
  const [loading, setLoading] = useState(false);
  const [waitingForWhatsapp, setWaitingForWhatsapp] = useState(false);
  const signupRef = useRef({ code: null, phoneNumberId: null, wabaId: null });
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
    signupRef.current = { code: null, phoneNumberId: null, wabaId: null };
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
        const data = await api("GET", "/api/owner/whatsapp/embedded-signup/config");
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
    const { code, phoneNumberId, wabaId } = signupRef.current;
    if (!code || !phoneNumberId || !wabaId) {
      toast("Signup incomplete — finish every WhatsApp step in the Meta popup, then try again.", "error");
      resetSignup();
      return;
    }

    clearSignupTimeout();
    setWaitingForWhatsapp(false);

    try {
      const data = await api("POST", `/api/owner/merchants/${merchantId}/whatsapp/embedded-signup`, {
        code,
        phoneNumberId,
        wabaId,
      });
      toast(data.message || "WhatsApp connected!");
      onConnected?.(data.merchant || data.data);
    } catch (e) {
      toast(e.message || "Embedded signup failed", "error");
    } finally {
      resetSignup();
    }
  }, [api, merchantId, onConnected, toast, resetSignup, clearSignupTimeout]);

  finishSignupRef.current = finishSignup;

  const maybeFinish = useCallback(() => {
    const { code, phoneNumberId, wabaId } = signupRef.current;
    if (code && phoneNumberId && wabaId) {
      finishSignupRef.current?.();
    }
  }, []);

  function startSignup() {
    if (!config?.enabled) {
      toast("Embedded Signup is not configured on the server.", "error");
      return;
    }

    const configId = String(config.configId || config.config_id || "").trim();
    const appId = String(config.appId || config.app_id || "").trim();
    if (!configId || !appId) {
      toast(
        "Meta Config ID is missing from the API. On Railway (API service) set WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID to your Facebook Login for Business Configuration ID, then redeploy.",
        "error",
      );
      return;
    }

    // Critical: do NOT await before FB.login — that loses the user gesture and blocks popups.
    if (!window.FB || !sdkReady) {
      toast("Meta SDK is still loading. Wait 2 seconds, then click Connect WhatsApp again.", "error");
      loadFacebookSdk(appId, config.graphVersion || config.graph_version).then((ok) => setSdkReady(!!ok));
      return;
    }

    clearSignupTimeout();
    signupRef.current = { code: null, phoneNumberId: null, wabaId: null };
    openedPopupRef.current = false;
    setWaitingForWhatsapp(false);
    setLoading(true);

    timeoutRef.current = setTimeout(() => {
      toast(
        "Meta signup timed out. Allow popups for this site, complete every WhatsApp step until the popup closes, then try again.",
        "error",
      );
      resetSignup();
    }, SIGNUP_TIMEOUT_MS);

    // If no popup activity soon, browser almost certainly blocked it.
    popupWatchRef.current = setTimeout(() => {
      if (!openedPopupRef.current && !signupRef.current.code) {
        toast(
          "No Meta popup opened. In Chrome: address bar → Pop-ups and redirects → Allow for this site, then click Connect again.",
          "error",
        );
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
        if (!signupRef.current.phoneNumberId || !signupRef.current.wabaId) {
          toast("Meta finished without a phone number. Add/select a WhatsApp Business number in the popup and try again.", "error");
          resetSignup();
          return;
        }
        maybeFinish();
      } else if (data.event === "CANCEL") {
        toast("WhatsApp signup cancelled.", "info");
        resetSignup();
      } else if (data.event === "ERROR") {
        const msg = data.data?.error_message || data.data?.message || "Meta Embedded Signup error.";
        toast(msg, "error");
        resetSignup();
      }
    };

    window.addEventListener("message", listenerRef.current);

    // Exact Meta Embedded Signup options — config_id must be present or Facebook shows
    // "Invalid parameter: config_id is required".
    const loginOptions = {
      config_id: configId,
      response_type: "code",
      override_default_response_type: true,
      extras: {
        setup: {},
        sessionInfoVersion: "3",
      },
    };

    if (typeof window !== "undefined") {
      // Help diagnose production Railway misconfig in DevTools.
      console.info("[NeoTalab] Starting Embedded Signup", { appId, configId });
    }

    try {
      window.FB.login((response) => {
        openedPopupRef.current = true;
        if (response.authResponse?.code) {
          signupRef.current.code = response.authResponse.code;
          maybeFinish();
          if (!signupRef.current.phoneNumberId || !signupRef.current.wabaId) {
            setWaitingForWhatsapp(true);
            toast("Facebook OK — keep going in the Meta popup until WhatsApp setup finishes and it closes.", "info");
          }
          return;
        }

        const errMsg = response?.error?.message || "";
        if (/config_id/i.test(errMsg)) {
          toast(
            "Meta says config_id is required. Set WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID on the Railway API service to your Login for Business Configuration ID, then redeploy.",
            "error",
          );
        } else if (response.status === "not_authorized") {
          toast("WhatsApp signup cancelled — approve all permissions in the popup.", "info");
        } else if (response.authResponse?.accessToken && !response.authResponse?.code) {
          toast(
            "Meta returned a token instead of a code. In Meta, create a Login for Business configuration with System-user access token / code response.",
            "error",
          );
        } else {
          toast(
            "Meta closed without an auth code. If Facebook said “config_id is required”, fix WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID on Railway. Otherwise finish every WhatsApp step until the popup closes.",
            "error",
          );
        }
        resetSignup();
      }, loginOptions);
    } catch (e) {
      toast(e?.message || "Could not open Meta signup. Allow popups and try again.", "error");
      resetSignup();
    }
  }

  if (!config) {
    return (
      <button type="button" className="paction-btn" disabled style={{ justifyContent: "center" }}>
        Loading Meta…
      </button>
    );
  }

  if (!config.enabled) {
    return (
      <p style={{ fontSize: 12, color: "var(--text-muted)", lineHeight: 1.5, margin: 0 }}>
        Embedded Signup is disabled — configure <code>WHATSAPP_META_APP_ID</code> and{" "}
        <code>WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID</code> in backend env.
      </p>
    );
  }

  const btnLabel = loading ? "Connecting…" : connected ? reconnectLabel : label;
  const readyHint = !sdkReady ? "Preparing Meta…" : null;
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
            toast("Connection cancelled.", "info");
            resetSignup();
          }}
        >
          Cancel
        </button>
      )}
      {waitingForWhatsapp && (
        <p style={{ fontSize: 12, color: "var(--text-muted)", lineHeight: 1.5, margin: 0 }}>
          Waiting for WhatsApp setup in the Meta popup (Business account + phone number). Facebook login alone is not enough.
        </p>
      )}
      {!loading && sdkReady && (
        <p style={{ fontSize: 11, color: "var(--text-muted)", lineHeight: 1.45, margin: 0 }}>
          {configIdPreview
            ? `Meta config ready (…${configIdPreview.slice(-4)}). A Facebook popup must open with WhatsApp steps — not only a Facebook profile page.`
            : "Meta Config ID missing on server. Set WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID on Railway API, then redeploy."}
        </p>
      )}
    </div>
  );
}
