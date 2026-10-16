"use client";

import { useCallback, useEffect, useLayoutEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";

/**
 * Themed dropdown that replaces the browser's native select. The list opens in a layer above the
 * page (never cut off by cards or modals), flips upward near the bottom of the screen, and works
 * with the keyboard: arrows move, Enter picks, Esc closes, typing a letter jumps to it.
 * Options: { value, label, hint?, icon? }.
 */
export default function FormSelect({
  value,
  onChange,
  options,
  placeholder = "Select…",
  "aria-label": ariaLabel,
  id,
  invalid = false,
  disabled = false,
  size,
}) {
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(-1);
  const [pos, setPos] = useState(null);
  const rootRef = useRef(null);
  const panelRef = useRef(null);

  const index = options.findIndex((o) => String(o.value) === String(value));
  const selected = options[index];

  const place = useCallback(() => {
    const r = rootRef.current?.getBoundingClientRect();
    if (!r) return;
    const height = Math.min(320, options.length * 46 + 12);
    const below = window.innerHeight - r.bottom;
    const up = below < height + 16 && r.top > below;
    setPos({ left: r.left, width: Math.max(r.width, 200), top: up ? undefined : r.bottom + 6, bottom: up ? window.innerHeight - r.top + 6 : undefined });
  }, [options.length]);

  function show() {
    if (disabled) return;
    place();
    setActive(index >= 0 ? index : 0);
    setOpen(true);
  }

  function pick(i) {
    const opt = options[i];
    if (!opt) return;
    onChange(String(opt.value));
    setOpen(false);
    rootRef.current?.querySelector("button")?.focus();
  }

  useLayoutEffect(() => { if (open) place(); }, [open, place]);

  useEffect(() => {
    if (!open) return undefined;
    const outside = (e) => {
      if (!rootRef.current?.contains(e.target) && !panelRef.current?.contains(e.target)) setOpen(false);
    };
    const reflow = (e) => { if (!panelRef.current?.contains(e.target)) place(); };
    document.addEventListener("mousedown", outside);
    window.addEventListener("resize", place);
    window.addEventListener("scroll", reflow, true);
    return () => {
      document.removeEventListener("mousedown", outside);
      window.removeEventListener("resize", place);
      window.removeEventListener("scroll", reflow, true);
    };
  }, [open, place]);

  useEffect(() => {
    if (open) panelRef.current?.querySelector(`[data-i="${active}"]`)?.scrollIntoView({ block: "nearest" });
  }, [open, active]);

  useEffect(() => { if (disabled) setOpen(false); }, [disabled]);

  function onKeyDown(e) {
    if (!open) {
      if (["ArrowDown", "ArrowUp", "Enter", " "].includes(e.key)) { e.preventDefault(); show(); }
      return;
    }
    if (e.key === "Escape") { e.stopPropagation(); setOpen(false); return; } // closes the list, not the modal around it
    if (e.key === "Tab") { setOpen(false); return; }
    if (e.key === "ArrowDown") { e.preventDefault(); setActive((i) => Math.min(options.length - 1, i + 1)); }
    else if (e.key === "ArrowUp") { e.preventDefault(); setActive((i) => Math.max(0, i - 1)); }
    else if (e.key === "Home") { e.preventDefault(); setActive(0); }
    else if (e.key === "End") { e.preventDefault(); setActive(options.length - 1); }
    else if (e.key === "Enter" || e.key === " ") { e.preventDefault(); pick(active); }
    else if (e.key.length === 1) {
      const k = e.key.toLowerCase();
      const hit = options.findIndex((o, i) => i > active && String(o.label).toLowerCase().startsWith(k));
      const first = options.findIndex((o) => String(o.label).toLowerCase().startsWith(k));
      if (hit >= 0 || first >= 0) setActive(hit >= 0 ? hit : first);
    }
  }

  const listId = id ? `${id}-list` : undefined;

  return (
    <div className={`form-select${open ? " open" : ""}${invalid ? " is-invalid" : ""}${disabled ? " is-disabled" : ""}${size === "sm" ? " sm" : ""}`} ref={rootRef}>
      <button
        type="button"
        id={id}
        className={`form-select-trigger${selected ? "" : " is-placeholder"}${invalid ? " is-invalid" : ""}`}
        onClick={() => (open ? setOpen(false) : show())}
        onKeyDown={onKeyDown}
        aria-label={ariaLabel}
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-controls={open ? listId : undefined}
        disabled={disabled}
      >
        {selected?.icon && <span className="form-select-icon" aria-hidden>{selected.icon}</span>}
        <span className="form-select-value">{selected?.label ?? placeholder}</span>
        <span className="form-select-chevron" aria-hidden>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M6 9l6 6 6-6" /></svg>
        </span>
      </button>

      {open && pos && createPortal(
        <ul ref={panelRef} id={listId} className="form-select-panel" role="listbox" aria-label={ariaLabel}
          style={{ position: "fixed", left: pos.left, width: pos.width, top: pos.top, bottom: pos.bottom }}>
          {options.map((opt, i) => {
            const isSel = i === index;
            return (
              <li key={opt.value === "" ? "__empty" : opt.value} role="option" aria-selected={isSel}>
                <button type="button" data-i={i} tabIndex={-1}
                  className={`form-select-item${isSel ? " active" : ""}${i === active ? " focus" : ""}`}
                  onMouseEnter={() => setActive(i)} onClick={() => pick(i)}>
                  {opt.icon && <span className="form-select-icon" aria-hidden>{opt.icon}</span>}
                  <span className="form-select-text">
                    <span>{opt.label}</span>
                    {opt.hint && <small>{opt.hint}</small>}
                  </span>
                  {isSel && (
                    <span className="form-select-check" aria-hidden>
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M20 6L9 17l-5-5" /></svg>
                    </span>
                  )}
                </button>
              </li>
            );
          })}
        </ul>,
        document.body,
      )}
    </div>
  );
}
