/**
 * Whole calendar days from today until the trial ends (0 once it is today or past).
 * A trial that ends on the 21st has 2 days left on the 19th, however late in the day it is.
 */
export function trialDaysLeft(endsAt) {
  if (!endsAt) return null;
  return Math.max(0, daysUntil(endsAt));
}

/** Whole calendar days from now until a date: 0 on the day itself, negative once the day has passed. */
export function daysUntil(date, now = new Date()) {
  return calendarDaysBetween(now, date);
}

/** Whole calendar days from one date to another (negative when the second is earlier). */
export function calendarDaysBetween(from, to) {
  const a = new Date(from);
  const b = new Date(to);
  const dayA = Date.UTC(a.getFullYear(), a.getMonth(), a.getDate());
  const dayB = Date.UTC(b.getFullYear(), b.getMonth(), b.getDate());
  return Math.round((dayB - dayA) / 86400000);
}
