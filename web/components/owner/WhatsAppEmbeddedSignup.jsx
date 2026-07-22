"use client";

import { useCallback, useEffect, useRef, useState } from "react";

const FB_SDK_URL = "https://connect.facebook.net/en_US/sdk.js";

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
    script.onerror = () => resolve(false);
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
 * Meta WhatsApp Embedded Signup button — replaces manual Phone Number ID entry.
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
  const [loading, setLoading] = useState(false);
  const signupRef = useRef({ code: null, phoneNumberId: null, wabaId: null });
  const listenerRef = useRef(null);
  const timeoutRef = useRef(null);

  const clearSignupTimeout = useCallback(() => {
    if (timeoutRef.current) {
      clearTimeout(timeoutRef.current);
      timeoutRef.current = null;
    }
  }, []);

  const resetSignup = useCallback(() => {
    clearSignupTimeout();
    signupRef.current = { code: null, phoneNumberId: null, wabaId: null };
    if (listenerRef.current) {
      window.removeEventListener("message", listenerRef.current);
      listenerRef.current = null;
    }
    setLoading(false);
  }, [clearSignupTimeout]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const data = await api("GET", "/api/owner/whatsapp/embedded-signup/config");
        if (!cancelled) setConfig(data);
        if (data?.enabled && data?.appId) {
          await loadFacebookSdk(data.appId, data.graphVersion);
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
      toast("Signup incomplete — try again.", "error");
      resetSignup();
      return;
    }

    clearSignupTimeout();

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

  const maybeFinish = useCallback(() => {
    const { code, phoneNumberId, wabaId } = signupRef.current;
    if (code && phoneNumberId && wabaId) {
      finishSignup();
    }
  }, [finishSignup]);

  async function startSignup() {
    if (!config?.enabled) {
      toast("Embedded Signup is not configured. Set WHATSAPP_META_APP_ID and WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID on the server.", "error");
      return;
    }

    const ready = await loadFacebookSdk(config.appId, config.graphVersion);
    if (!ready || !window.FB) {
      toast("Could not load Meta SDK. Check your connection or ad blockers.", "error");
      return;
    }

    clearSignupTimeout();
    signupRef.current = { code: null, phoneNumberId: null, wabaId: null };
    setLoading(true);

    timeoutRef.current = setTimeout(() => {
      toast(
        "Meta signup timed out. Click Continue in the popup and finish all WhatsApp steps until it closes. Also add localhost to Meta App settings → Basic → App domains.",
        "error",
      );
      resetSignup();
    }, 120000);

    if (listenerRef.current) {
      window.removeEventListener("message", listenerRef.current);
    }

    listenerRef.current = (event) => {
      const data = parseEmbeddedSignupMessage(event);
      if (!data) return;

      if (data.event === "FINISH" || data.event === "FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING") {
        const payload = data.data || {};
        signupRef.current.phoneNumberId = payload.phone_number_id || payload.phoneNumberId || null;
        signupRef.current.wabaId = payload.waba_id || payload.wabaId || null;
        maybeFinish();
      } else if (data.event === "CANCEL") {
        toast("WhatsApp signup cancelled.", "info");
        resetSignup();
      }
    };

    window.addEventListener("message", listenerRef.current);

    window.FB.login(
      (response) => {
        if (response.authResponse?.code) {
          signupRef.current.code = response.authResponse.code;
          maybeFinish();
          if (!signupRef.current.phoneNumberId || !signupRef.current.wabaId) {
            toast("Meta login OK — finish the WhatsApp number setup in the popup.", "info");
          }
          return;
        }

        if (response.status === "not_authorized") {
          toast("WhatsApp signup cancelled.", "info");
        } else {
          toast("Meta login did not return an authorization code. Close the popup and try again.", "error");
        }
        resetSignup();
      },
      {
        config_id: config.configId,
        response_type: "code",
        override_default_response_type: true,
        extras: {
          setup: {},
          featureType: "",
          sessionInfoVersion: "4",
        },
      },
    );
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

  return (
    <button
      id={buttonId}
      type="button"
      className={compact ? "btn-primary" : "paction-btn paction-primary"}
      style={{ justifyContent: "center", marginBottom: compact ? 0 : undefined, width: compact ? undefined : "100%" }}
      onClick={startSignup}
      disabled={disabled || loading}
    >
      {connected ? "🔄 " : "📱 "}{btnLabel}
    </button>
  );
}
