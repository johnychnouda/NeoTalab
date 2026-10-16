"use client";

import { useRef } from "react";

/**
 * A row of 2–6 short choices, one always picked (a radio group). Used instead of a dropdown when
 * every option fits on screen, so the choice is visible without opening anything.
 * Options: { value, label, icon?, hint? }. `block` stretches it to the full width; `cards` shows each
 * choice as a card with its hint underneath.
 */
export default function Segmented({ value, onChange, options, block = false, cards = false, size, "aria-label": ariaLabel, id }) {
  const ref = useRef(null);
  const index = Math.max(0, options.findIndex((o) => String(o.value) === String(value)));

  function move(e) {
    const step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[e.key];
    if (!step) return;
    e.preventDefault();
    // In Arabic the row runs right to left, so left/right arrows swap.
    const rtl = getComputedStyle(ref.current).direction === "rtl" && (e.key === "ArrowLeft" || e.key === "ArrowRight");
    const next = (index + (rtl ? -step : step) + options.length) % options.length;
    onChange(String(options[next].value));
    ref.current?.querySelectorAll("button")[next]?.focus();
  }

  return (
    <div ref={ref} id={id} role="radiogroup" aria-label={ariaLabel} className={`seg${block ? " block" : ""}${cards ? " cards" : ""}${size === "sm" ? " sm" : ""}`} onKeyDown={move}>
      {options.map((o, i) => (
        <button key={o.value} type="button" role="radio" aria-checked={i === index} tabIndex={i === index ? 0 : -1}
          className="seg-opt" onClick={() => onChange(String(o.value))}>
          {o.icon && <span className="seg-icon" aria-hidden>{o.icon}</span>}
          {cards ? (
            <span className="seg-text"><strong>{o.label}</strong>{o.hint && <small>{o.hint}</small>}</span>
          ) : o.label}
        </button>
      ))}
    </div>
  );
}
