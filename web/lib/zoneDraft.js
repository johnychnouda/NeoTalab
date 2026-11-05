/** The name a delivery area is saved under: what the owner typed, else a circle's size ("Up to 2 km"), else none. */
export function zoneNameFor(draft, suggestion) {
  const typed = String(draft.name ?? "").trim();
  return typed || (draft.area?.type === "circle" ? suggestion : "");
}

/** What stops the area from being saved: "name", "fee" (an empty fee is not free delivery, 0 is), or null. */
export function zoneProblem(draft, suggestion) {
  if (!zoneNameFor(draft, suggestion)) return "name";
  if (String(draft.fee ?? "").trim() === "") return "fee";
  return null;
}
