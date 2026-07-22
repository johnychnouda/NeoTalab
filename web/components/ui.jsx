"use client";

import { createContext, useCallback, useContext, useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";

/* ── Toasts ─────────────────────────────────────────────── */

const ToastContext = createContext(() => {});

export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([]);

  const toast = useCallback((message, type = "success") => {
    const id = Date.now() + Math.random();
    setToasts((t) => [...t, { id, message, type, show: false }]);
    requestAnimationFrame(() => {
      setToasts((t) => t.map((x) => (x.id === id ? { ...x, show: true } : x)));
    });
    setTimeout(() => {
      setToasts((t) => t.map((x) => (x.id === id ? { ...x, show: false } : x)));
      setTimeout(() => setToasts((t) => t.filter((x) => x.id !== id)), 350);
    }, 3000);
  }, []);

  return (
    <ToastContext.Provider value={toast}>
      {children}
      <div className="toast-container">
        {toasts.map((t) => (
          <div key={t.id} className={`toast ${t.type} ${t.show ? "show" : ""}`}>
            <span>{t.type === "success" ? "✅" : "❌"}</span> {t.message}
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  );
}

export function useToast() {
  return useContext(ToastContext);
}

/* ── Modal ──────────────────────────────────────────────── */

export function Modal({ title, onClose, children, footer, wide = false, compact = false, closeOnOutside = true }) {
  const [mounted, setMounted] = useState(false);

  useEffect(() => {
    setMounted(true);
  }, []);

  useEffect(() => {
    const prev = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => {
      document.body.style.overflow = prev;
    };
  }, []);

  useEffect(() => {
    if (!onClose) return undefined;
    function onKey(e) {
      if (e.key === "Escape") onClose();
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  function dismissOutside(e) {
    if (!closeOnOutside || !onClose) return;
    if (e.target === e.currentTarget) onClose();
  }

  if (!mounted) return null;

  return createPortal(
    <div
      className="modal-overlay"
      onMouseDown={dismissOutside}
      onClick={dismissOutside}
    >
      <div
        className={`modal${wide ? " modal-wide" : ""}${compact ? " modal-compact" : ""}`}
        role="dialog"
        aria-modal="true"
        aria-labelledby="modal-title"
        onMouseDown={(e) => e.stopPropagation()}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="modal-header">
          <h3 id="modal-title">{title}</h3>
          <button type="button" className="modal-close" onClick={onClose} aria-label="Close">✕</button>
        </div>
        <div className="modal-body">{children}</div>
        {footer ? <div className="modal-footer">{footer}</div> : null}
      </div>
    </div>,
    document.body,
  );
}

export function ConfirmModal({ title, message, confirmLabel = "Confirm", danger = false, onConfirm, onClose }) {
  return (
    <Modal title={title} onClose={onClose}>
      <p style={{ color: "var(--text-muted)", fontSize: 14, lineHeight: 1.5, marginBottom: 20 }}
        dangerouslySetInnerHTML={{ __html: message }} />
      <div className="modal-actions">
        <button className="btn-sm" onClick={onClose}>Cancel</button>
        <button
          className={danger ? "btn-danger" : "btn-primary"}
          onClick={() => { onClose(); onConfirm(); }}
        >
          {confirmLabel}
        </button>
      </div>
    </Modal>
  );
}

/* ── Skeleton loader ────────────────────────────────────── */

export function Skeleton({ w = "100%", h = 14, br = 8, style }) {
  return <div className="skeleton" style={{ width: w, height: h, borderRadius: br, ...style }} />;
}

/* ── OTP / activation code input ────────────────────────── */

export function OtpInput({ length = 6, value = "", onChange, autoFocus = false }) {
  const refs = useRef([]);

  function emit(next) {
    onChange(next.replace(/\D/g, "").slice(0, length));
  }

  function handleChange(i, raw) {
    const d = raw.replace(/\D/g, "").slice(-1);
    const chars = value.split("");
    while (chars.length < length) chars.push("");
    chars[i] = d;
    emit(chars.join(""));
    if (d && i < length - 1) refs.current[i + 1]?.focus();
  }

  function handleKeyDown(i, e) {
    if (e.key === "Backspace" && !value[i] && i > 0) {
      refs.current[i - 1]?.focus();
    }
    if (e.key === "ArrowLeft" && i > 0) refs.current[i - 1]?.focus();
    if (e.key === "ArrowRight" && i < length - 1) refs.current[i + 1]?.focus();
  }

  function handlePaste(e) {
    e.preventDefault();
    const pasted = e.clipboardData.getData("text").replace(/\D/g, "").slice(0, length);
    emit(pasted);
    refs.current[Math.min(pasted.length, length - 1)]?.focus();
  }

  return (
    <div className="otp-input" onPaste={handlePaste}>
      {Array.from({ length }).map((_, i) => (
        <input
          key={i}
          ref={(el) => { refs.current[i] = el; }}
          className={`otp-box no-arrows${value[i] ? " filled" : ""}`}
          type="text"
          inputMode="numeric"
          autoComplete={i === 0 ? "one-time-code" : "off"}
          maxLength={1}
          value={value[i] || ""}
          onChange={(e) => handleChange(i, e.target.value)}
          onKeyDown={(e) => handleKeyDown(i, e)}
          onFocus={(e) => e.target.select()}
          autoFocus={autoFocus && i === 0}
          aria-label={`Digit ${i + 1} of ${length}`}
        />
      ))}
    </div>
  );
}

export function generateOtp(length = 6) {
  const min = 10 ** (length - 1);
  return String(Math.floor(min + Math.random() * (9 * min)));
}

/* ── WhatsApp helpers ───────────────────────────────────── */

export function WhatsAppIcon({ size = 14, color = "#25D366" }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style={{ verticalAlign: "middle", flexShrink: 0 }}>
      <path fill={color} d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
    </svg>
  );
}

export function WaLink({ number }) {
  if (!number) return <>—</>;
  const clean = number.replace(/\D/g, "");
  if (!clean) return <>—</>;
  return (
    <a href={`https://wa.me/${clean}`} target="_blank" rel="noreferrer"
      onClick={(e) => e.stopPropagation()}
      style={{ color: "#25d366", textDecoration: "none", fontWeight: 600, display: "inline-flex", alignItems: "center", gap: 4 }}>
      <WhatsAppIcon size={13} /> {number}
    </a>
  );
}
