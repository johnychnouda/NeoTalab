"use client";

import { useEffect, useRef, useState } from "react";

/** Custom dropdown — styled menu list (not native select) */
export default function SetMenu({ value, onChange, options, "aria-label": ariaLabel }) {
  const [open, setOpen] = useState(false);
  const rootRef = useRef(null);

  const selected = options.find((o) => String(o.value) === String(value)) ?? options[0];

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
    <div className={`set-menu${open ? " open" : ""}`} ref={rootRef}>
      <button
        type="button"
        className="set-menu-trigger"
        onClick={() => setOpen((v) => !v)}
        aria-label={ariaLabel}
        aria-haspopup="listbox"
        aria-expanded={open}
      >
        <span className="set-menu-trigger-icon" aria-hidden>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7v5l3 2" />
          </svg>
        </span>
        <span className="set-menu-trigger-text">{selected?.label}</span>
        <span className="set-menu-trigger-chevron" aria-hidden>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
            <path d="M6 9l6 6 6-6" />
          </svg>
        </span>
      </button>

      {open && (
        <ul className="set-menu-panel" role="listbox" aria-label={ariaLabel}>
          {options.map((opt) => {
            const active = String(opt.value) === String(value);
            return (
              <li key={opt.value} role="option" aria-selected={active}>
                <button
                  type="button"
                  className={`set-menu-item${active ? " active" : ""}`}
                  onClick={() => pick(opt.value)}
                >
                  <span>{opt.label}</span>
                  {active && (
                    <span className="set-menu-check" aria-hidden>
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
