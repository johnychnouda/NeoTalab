/** The NeoTalab logo + wordmark ("Neo" white, "Talab" green) used on every dark surface. */
export default function Brand({ size = 36, className = "" }) {
  return (
    <span className={`site-brand ${className}`.trim()} dir="ltr" style={{ fontSize: Math.round(size * 0.6) }}>
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img src="/logo.svg" alt="" width={size} height={size} style={{ width: size, height: size, maxWidth: "none" }} />
      <span><span className="brand-neo">Neo</span><span className="brand-talab">Talab</span></span>
    </span>
  );
}
