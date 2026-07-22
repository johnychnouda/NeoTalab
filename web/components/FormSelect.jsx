"use client";

import { useEffect, useRef, useState } from "react";

/**
 * Custom dropdown for forms — replaces native select so the menu matches field width and theme.
 */
export default function FormSelect({
  value,
  onChange,
  options,
  placeholder = "Select…",
  "aria-label": ariaLabel,
  id,
  invalid = false,
}) {
  const [open, setOpen] = useState(false);
  const rootRef = useRef(null);

  const selected = options.find((o) => String(o.value) === String(value));
  const display = selected?.label ?? placeholder;

  useEffect(() => {
    if (!open) return;
    function onPointerDown(e) {
      if (!rootRef.current?.contains(e.target)) setOpen(false);
    }
    function onKeyDown(e) {
      if (e.key === "Escape") setOpen(false);
    }
    document.addEventListener("mousedown", onPointerDown);
    document.addEventListener("keydown", onKeyDown);
    return () => {
      document.removeEventListener("mousedown", onPointerDown);
      document.removeEventListener("keydown", onKeyDown);
    };
  }, [open]);

  function pick(next) {
    onChange(String(next));
    setOpen(false);
  }

  return (
    <div className={`form-select${open ? " open" : ""}${invalid ? " is-invalid" : ""}`} ref={rootRef}>
      <button
        type="button"
        id={id}
        className={`form-select-trigger${selected ? "" : " is-placeholder"}${invalid ? " is-invalid" : ""}`}
        onClick={() => setOpen((v) => !v)}
        aria-label={ariaLabel}
        aria-haspopup="listbox"
        aria-expanded={open}
      >
        <span className="form-select-value">{display}</span>
        <span className="form-select-chevron" aria-hidden>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
            <path d="M6 9l6 6 6-6" />
          </svg>
        </span>
      </button>

      {open && (
        <ul className="form-select-panel" role="listbox" aria-label={ariaLabel}>
          {options.map((opt) => {
            const active = String(opt.value) === String(value);
            return (
              <li key={opt.value || "__empty"} role="option" aria-selected={active}>
                <button
                  type="button"
                  className={`form-select-item${active ? " active" : ""}`}
                  onClick={() => pick(opt.value)}
                >
                  <span>{opt.label}</span>
                  {active && (
                    <span className="form-select-check" aria-hidden>
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                        <path d="M20 6L9 17l-5-5" />
                      </svg>
                    </span>
                  )}
                </button>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
