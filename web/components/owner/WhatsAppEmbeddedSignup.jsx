"use client";

import { useCallback, useEffect, useRef, useState } from "react";

const FB_SDK_URL = "https://connect.facebook.net/en_US/sdk.js";
const SIGNUP_TIMEOUT_MS = 180000;

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
  const [waitingForWhatsapp, setWaitingForWhatsapp] = useState(false);
  const signupRef = useRef({ code: null, phoneNumberId: null, wabaId: null });
  const listenerRef = useRef(null);
  const timeoutRef = useRef(null);
  const finishSignupRef = useRef(null);

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
    setWaitingForWhatsapp(false);
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

  async function startSignup() {
    if (!config?.enabled) {
      toast("Embedded Signup is not configured. Set WHATSAPP_META_APP_ID and WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID on the server.", "error");
      return;
    }

    const configId = String(config.configId || config.config_id || "").trim();
    const appId = String(config.appId || config.app_id || "").trim();
    if (!configId || !appId) {
      toast(
        "Embedded Signup Config ID is missing from the API. On Railway API set WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID=1410549307656959 and redeploy.",
        "error",
      );
      return;
    }

    const ready = await loadFacebookSdk(appId, config.graphVersion || config.graph_version);
    if (!ready || !window.FB) {
      toast("Could not load Meta SDK. Check your connection or ad blockers.", "error");
      return;
    }

    clearSignupTimeout();
    signupRef.current = { code: null, phoneNumberId: null, wabaId: null };
    setWaitingForWhatsapp(false);
    setLoading(true);

    timeoutRef.current = setTimeout(() => {
      toast(
        "Meta signup timed out. Allow popups for this site, complete the WhatsApp Business steps in the popup until it closes, then try again.",
        "error",
      );
      resetSignup();
    }, SIGNUP_TIMEOUT_MS);

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

    window.FB.login(
      (response) => {
        if (response.authResponse?.code) {
          signupRef.current.code = response.authResponse.code;
          maybeFinish();
          if (!signupRef.current.phoneNumberId || !signupRef.current.wabaId) {
            setWaitingForWhatsapp(true);
            toast("Facebook OK — keep going in the popup: create/select WhatsApp Business Account and phone number until it closes.", "info");
          }
          return;
        }

        if (response.status === "not_authorized") {
          toast("WhatsApp signup cancelled — approve all permissions in the popup.", "info");
        } else if (response.authResponse?.accessToken && !response.authResponse?.code) {
          toast(
            "Meta returned a token instead of a code. Use an Embedded Signup config with System-user access token.",
            "error",
          );
        } else {
          toast(
            "Meta closed without an auth code. Use the NeoTalab app Admin Facebook account, allow popups, and finish every WhatsApp step until the popup closes.",
            "error",
          );
        }
        resetSignup();
      },
      {
        config_id: configId,
        response_type: "code",
        override_default_response_type: true,
        extras: {
          setup: {},
          featureType: "",
          sessionInfoVersion: "3",
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
    <div style={{ display: "grid", gap: 8 }}>
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
    </div>
  );
}
