import { describe, expect, it } from "vitest";
import { addressAfterPin } from "@/lib/pickupAddress";

describe("the pickup address follows the pin", () => {
  it("fills an empty box", () => {
    expect(addressAfterPin({ current: "", picked: "Mestita St, Byblos", touched: false })).toBe("Mestita St, Byblos");
  });

  it("replaces an earlier address the owner did not just type", () => {
    expect(addressAfterPin({ current: "Jounieh", picked: "Mestita St, Byblos", touched: false })).toBe("Mestita St, Byblos");
  });

  it("never replaces what the owner typed in this visit", () => {
    expect(addressAfterPin({ current: "Behind the pharmacy", picked: "Mestita St, Byblos", touched: true })).toBeNull();
  });

  it("does nothing when Google gave no address or the same one", () => {
    expect(addressAfterPin({ current: "A", picked: "  ", touched: false })).toBeNull();
    expect(addressAfterPin({ current: "A St", picked: "A St", touched: false })).toBeNull();
  });
});
