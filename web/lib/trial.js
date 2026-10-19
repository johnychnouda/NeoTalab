/**
 * Whole calendar days from today until the trial ends (0 once it is today or past).
 * A trial that ends on the 21st has 2 days left on the 19th, however late in the day it is.
 */
export function trialDaysLeft(endsAt) {
  if (!endsAt) return null;
  const end = new Date(endsAt);
  const now = new Date();
  const endDay = Date.UTC(end.getFullYear(), end.getMonth(), end.getDate());
  const today = Date.UTC(now.getFullYear(), now.getMonth(), now.getDate());
  return Math.max(0, Math.round((endDay - today) / 86400000));
}
