import { readFileSync } from "node:fs";
import { test, expect } from "@playwright/test";
import { watchPage, horizontalOverflow } from "./helpers.js";

// Dragging the edge of a circle on the map fills in its row. Google's map cannot load in the test browser (its key
// works only on neotalab.com), so a small stand-in map is put in its place: circles you can change the radius of,
// that tell their listeners. The shop's pin and zones are set with plain requests and put back afterwards.
const slug = process.env.E2E_SHOP_SLUG;
const API = process.env.E2E_API_URL || "http://127.0.0.1:8011/api/v1";
test.use({ storageState: "e2e/.auth/shop.json" });

const STAND_IN = () => {
  const made = [];
  class Obj { constructor(o = {}) { this.o = { ...o }; this.l = {}; } addListener(e, f) { (this.l[e] ||= []).push(f); return { remove() {} }; }
    fire(e) { (this.l[e] || []).forEach((f) => f()); } setMap() {} setVisible(v) { this.o.visible = v; } setPosition(p) { this.o.position = p; } setLabel(l) { this.o.label = l; } }
  class Circle extends Obj { constructor(o) { super(o); made.push(this); } getRadius() { return this.o.radius; } setRadius(r) { this.o.radius = r; this.fire("radius_changed"); }
    getBounds() { return {}; } }
  class Bounds { extend() {} union() {} isEmpty() { return true; } }
  window.__circles = made;
  window.google = { maps: { Map: class extends Obj { fitBounds() {} setCenter() {} setZoom() {} setOptions() {} }, Circle, Polygon: class extends Obj { getBounds() { return null; } },
    Marker: Obj, Size: class {}, Point: class {}, LatLngBounds: Bounds, SymbolPath: { CIRCLE: 0 }, geometry: { spherical: { computeOffset: (p) => p } } } };
};

for (const [code] of [["en"], ["ar"], ["fr"]]) {
  test(`${code}: dragging a circle's edge fills in its row, rounded, and typing moves the circle`, async ({ page, request, context, baseURL }) => {
    await context.addCookies([{ name: "nt_lang", value: code, url: baseURL }]);
    const problems = watchPage(page);
    await page.addInitScript(STAND_IN);
    // The login token comes from the saved login, not from a page load (the shop API allows 120 requests a minute).
    const token = JSON.parse(readFileSync("e2e/.auth/shop.json", "utf8")).origins.flatMap((o) => o.localStorage).find((i) => i.name === "nt_merchant_token")?.value;
    const headers = { Authorization: `Bearer ${token}`, Accept: "application/json" };
    const zones = async () => {
      const res = await request.get(`${API}/settings/zones`, { headers });
      expect(res.status(), await res.text()).toBe(200);
      return (await res.json()).zones;
    };
    const profile = async () => { const j = await (await request.get(`${API}/settings/profile`, { headers })).json(); return j.profile || j; };
    const before = await profile();
    const saved = await zones();
    for (const z of saved) await request.delete(`${API}/settings/zones/${z.id}`, { headers });
    await request.patch(`${API}/settings/profile`, { headers, data: { shop_location: "33.893800, 35.501800" } });
    try {
      await page.goto(`/backoffice/${slug}/settings?tab=areas`);
      await expect(page.getByLabel("km")).toHaveCount(3);
      await page.waitForFunction(() => window.__circles?.length === 3);
      const radius = (i) => page.evaluate((n) => window.__circles[n].getRadius(), i);
      expect(await radius(0)).toBe(3000);

      // Drag the first circle's edge to 2366 m: its row says 2.4 and the Save button wakes up.
      await page.evaluate(() => window.__circles[0].setRadius(2366));
      await expect(page.getByLabel("km").nth(0)).toHaveValue("2.4");
      await expect(page.getByRole("button", { name: /^(Save delivery areas|احفظ مناطق التوصيل|Enregistrer les zones de livraison)$/ })).toBeEnabled();
      // Dragging past the limits stops at 0.5 and 60 km.
      await page.evaluate(() => window.__circles[1].setRadius(90000));
      await expect(page.getByLabel("km").nth(1)).toHaveValue("60");
      await page.evaluate(() => window.__circles[1].setRadius(10));
      await expect(page.getByLabel("km").nth(1)).toHaveValue("0.5");

      // Typing a distance moves the circle, and does not round what was typed.
      await page.getByLabel("km").nth(2).fill("7.35");
      expect(await radius(2)).toBe(7350);
      await expect(page.getByLabel("km").nth(2)).toHaveValue("7.35");

      // Saving keeps the dragged distance.
      await page.getByLabel("km").nth(1).fill("6");
      await page.getByRole("button", { name: /^(Save delivery areas|احفظ مناطق التوصيل|Enregistrer les zones de livraison)$/ }).click();
      await expect.poll(async () => (await zones()).map((z) => z.area.radius).sort((a, b) => a - b)).toEqual([2400, 6000, 7350]);
      expect(await horizontalOverflow(page)).toBeNull();
      expect(problems.filter((p) => !/maps|google|gstatic/i.test(p))).toEqual([]);
    } finally {
      for (const z of await zones()) await request.delete(`${API}/settings/zones/${z.id}`, { headers });
      for (const z of saved) {
        await request.post(`${API}/settings/zones`, { headers, data: { name: z.name, area: z.area, color: z.color, deliveryFee: Number(z.delivery_fee), minimumOrder: Number(z.minimum_order), deliveryMins: z.delivery_mins } });
      }
      await request.patch(`${API}/settings/profile`, { headers, data: { shop_location: before.shop_location || "" } });
    }
  });
}
