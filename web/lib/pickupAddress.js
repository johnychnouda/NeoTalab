/**
 * The pickup address line follows the pin the owner picks on the map (the place's own address from
 * Google), unless the owner has typed in that box during this visit: typed words are never replaced.
 * Returns the new text, or null when the box should stay as it is.
 */
export function addressAfterPin({ current, picked, touched }) {
  const next = String(picked || "").trim();
  if (!next || touched) return null;
  return next === String(current || "").trim() ? null : next;
}
