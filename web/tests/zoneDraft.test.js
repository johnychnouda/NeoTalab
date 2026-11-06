import { describe, expect, it } from "vitest";
import { DEFAULT_ROWS, isRing, minutesFor, planSave, rowsFromZones, rowsProblem } from "@/lib/zoneDraft";

const shop = { lat: 33.9, lng: 35.6 };
const ringName = (km) => `Up to ${km} km`;
const circle = (id, km, extra = {}) => ({
  id, name: ringName(km), color: "#25d366", delivery_fee: "2.00", minimum_order: "0.00", delivery_mins: 14,
  area: { type: "circle", center: [shop.lat, shop.lng], radius: km * 1000 }, ...extra,
});
const drawn = (id) => ({ id, name: "Jounieh", color: "#3b82f6", delivery_fee: "3.00", minimum_order: "5.00", delivery_mins: 20, area: { type: "polygon", points: [[1, 1], [1, 2], [2, 2]] } });

describe("distance rows", () => {
  it("shows circles around the shop as rows, nearest first, and everything else as drawn areas", () => {
    const { rings, custom } = rowsFromZones([circle("b", 6), circle("a", 3), drawn("d"), circle("far", 4, { area: { type: "circle", center: [34.5, 36], radius: 4000 } })], shop);
    expect(rings.map((r) => [r.id, r.km, r.fee])).toEqual([["a", "3", "2"], ["b", "6", "2"]]);
    expect(custom.map((r) => r.id)).toEqual(["d", "far"]);
  });

  it("treats a shop without a pin as having no rows to show, so nothing is lost", () => {
    const { rings, custom } = rowsFromZones([circle("a", 3), drawn("d")], null);
    expect(rings).toEqual([]);
    expect(custom.map((r) => r.id)).toEqual(["a", "d"]);
  });

  it("starts a new shop on 3, 6 and 10 km", () => {
    expect(DEFAULT_ROWS.map((r) => r.km)).toEqual(["3", "6", "10"]);
  });

  it("estimates a delivery time from the distance", () => {
    expect(minutesFor(3000)).toBe(14);
  });
});

describe("what stops a save", () => {
  const row = (km, fee = "1", min = "") => ({ km, fee, min });

  it("asks for a fee: empty is not free delivery, 0 is", () => {
    expect(rowsProblem([row("3", "")])).toEqual({ index: 0, kind: "fee" });
    expect(rowsProblem([row("3", " ")])).toEqual({ index: 0, kind: "fee" });
    expect(rowsProblem([row("3", "0")])).toBeNull();
  });

  it("keeps the distance between 0.5 and 60 km", () => {
    expect(rowsProblem([row("")])).toEqual({ index: 0, kind: "km" });
    expect(rowsProblem([row("0.2")])).toEqual({ index: 0, kind: "km" });
    expect(rowsProblem([row("61")])).toEqual({ index: 0, kind: "km" });
    expect(rowsProblem([row("0.5"), row("60")])).toBeNull();
  });

  it("refuses two rows with the same distance", () => {
    expect(rowsProblem([row("3"), row("6"), row("3.0")])).toEqual({ index: 2, kind: "dupe" });
  });

  it("refuses a negative minimum order", () => {
    expect(rowsProblem([row("3", "1", "-1")])).toEqual({ index: 0, kind: "min" });
  });
});

describe("saving the rows", () => {
  const row = (id, km, fee = "2", min = "") => ({ id, km: String(km), fee, min, name: id ? ringName(km) : "", mins: 14 });

  it("creates new rows around the shop pin, named after their distance", () => {
    const plan = planSave({ rings: [row(null, 4, "1.5", "5")], custom: [], zones: [], shop, ringName });
    expect(plan.remove).toEqual([]);
    expect(plan.update).toEqual([]);
    expect(plan.create).toHaveLength(1);
    expect(plan.create[0]).toMatchObject({
      name: "Up to 4 km", deliveryFee: 1.5, minimumOrder: 5, deliveryMins: minutesFor(4000),
      area: { type: "circle", center: [33.9, 35.6], radius: 4000 },
    });
  });

  it("updates a row that already exists and keeps a name the owner gave it while the distance is the same", () => {
    const zones = [circle("a", 3, { name: "Near" })];
    const kept = planSave({ rings: [{ ...row("a", 3, "2.5"), name: "Near" }], custom: [], zones, shop, ringName });
    expect(kept.update[0]).toMatchObject({ id: "a", body: { name: "Near", deliveryFee: 2.5 } });
    const moved = planSave({ rings: [{ ...row("a", 5), name: "Near" }], custom: [], zones, shop, ringName });
    expect(moved.update[0].body).toMatchObject({ name: "Up to 5 km", area: { radius: 5000 } });
  });

  it("removes zones whose row was taken away, drawn areas included", () => {
    const zones = [circle("a", 3), circle("b", 6), drawn("d")];
    const plan = planSave({ rings: [row("a", 3)], custom: [], zones, shop, ringName });
    expect(plan.remove.sort()).toEqual(["b", "d"]);
  });

  it("keeps a drawn area's shape: only its prices are sent, never a new area", () => {
    const zones = [drawn("d")];
    const custom = [{ id: "d", name: "Jounieh", fee: "4", min: "", mins: 20, color: "#3b82f6" }];
    const plan = planSave({ rings: [], custom, zones, shop, ringName });
    expect(plan.update).toHaveLength(1);
    expect(plan.update[0].body).not.toHaveProperty("area");
    expect(plan.update[0].body).toMatchObject({ name: "Jounieh", deliveryFee: 4, minimumOrder: 0, deliveryMins: 20 });
    expect(plan.remove).toEqual([]);
  });

  it("gives each row its own colour in distance order", () => {
    const plan = planSave({ rings: [row(null, 3), row(null, 6)], custom: [], zones: [], shop, ringName });
    expect(plan.create[0].color).not.toBe(plan.create[1].color);
  });

  it("isRing is true only for a circle centred on the shop", () => {
    expect(isRing(circle("a", 3), shop)).toBe(true);
    expect(isRing(circle("a", 3, { area: { type: "circle", center: [33.95, 35.6], radius: 3000 } }), shop)).toBe(false);
    expect(isRing(drawn("d"), shop)).toBe(false);
    expect(isRing(circle("a", 3), null)).toBe(false);
  });
});
