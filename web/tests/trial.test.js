import { describe, expect, it } from "vitest";
import { daysUntil, trialDaysLeft } from "@/lib/trial";

// Local-time dates, so the result does not depend on the machine's time zone.
const morning = new Date(2026, 10, 6, 9, 38);

describe("days until a date", () => {
  it("counts calendar days: a trial ending on the 8th has 2 days left on the morning of the 6th, however late it ends", () => {
    expect(daysUntil(new Date(2026, 10, 8, 10, 30), morning)).toBe(2);
    expect(daysUntil(new Date(2026, 10, 8, 23, 59), morning)).toBe(2);
    expect(daysUntil(new Date(2026, 10, 8, 0, 5), morning)).toBe(2);
  });

  it("is 0 on the last day and negative afterwards", () => {
    expect(daysUntil(new Date(2026, 10, 6, 20, 0), morning)).toBe(0);
    expect(daysUntil(new Date(2026, 10, 5, 10, 0), morning)).toBe(-1);
  });

  it("the trial helper never goes below 0", () => {
    expect(trialDaysLeft(new Date(2026, 10, 1, 10, 0).toISOString())).toBe(0);
    expect(trialDaysLeft(null)).toBeNull();
  });
});
