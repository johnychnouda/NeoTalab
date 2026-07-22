"use client";

/** Styled native select — matches owner settings form controls */
export default function SetSelect({ value, onChange, children, id, "aria-label": ariaLabel }) {
  return (
    <div className="set-select">
      <select id={id} value={value} onChange={onChange} aria-label={ariaLabel}>
        {children}
      </select>
      <span className="set-select-chevron" aria-hidden>
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
          <path d="M6 9l6 6 6-6" />
        </svg>
      </span>
    </div>
  );
}
