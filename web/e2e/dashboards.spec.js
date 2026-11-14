import { test, expect } from "@playwright/test";
import { watchPage, horizontalOverflow } from "./helpers.js";

// Test logins come from the environment (never stored in the repository).
const OWNER = { email: process.env.E2E_OWNER_EMAIL, password: process.env.E2E_OWNER_PASSWORD };
const SHOP = { email: process.env.E2E_SHOP_EMAIL, password: process.env.E2E_SHOP_PASSWORD, slug: process.env.E2E_SHOP_SLUG };

// Sessions are created once by auth.setup.js; these tests reuse them.
test.use({ storageState: undefined });

test.describe("platform owner portal", () => {
  test.use({ storageState: "e2e/.auth/owner.json" });
  test.skip(!OWNER.email, "set E2E_OWNER_EMAIL and E2E_OWNER_PASSWORD");

  for (const path of ["/owner", "/owner/billing", "/owner/inbox", "/owner/usage", "/owner/settings?tab=pricing", "/owner/settings?tab=whatsapp", "/owner/settings?tab=alerts", "/owner/settings?tab=security"]) {
    test(`owner ${path} loads, no errors, no sideways scroll`, async ({ page }) => {
      const problems = watchPage(page);
      await page.goto(path);
      await page.waitForLoadState("networkidle");
      expect(await horizontalOverflow(page), `sideways scroll on ${path}`).toBeNull();
      const real = problems.filter((p) => !/maps|google|gstatic/i.test(p));
      expect(real, `errors on ${path}`).toEqual([]);
    });
  }

  test("inbox wording: no manual 'mark as paid' and correct heading", async ({ page }) => {
    await page.goto("/owner/inbox");
    await page.waitForLoadState("networkidle");
    const text = await page.locator("body").innerText();
    expect(text).not.toMatch(/Mark it as paid/i);
    expect(text).not.toMatch(/\b1 things\b/);
  });
});

test.describe("shop dashboard", () => {
  test.use({ storageState: "e2e/.auth/shop.json" });
  test.skip(!SHOP.email || !SHOP.slug, "set E2E_SHOP_EMAIL, E2E_SHOP_PASSWORD and E2E_SHOP_SLUG");

  const base = `/backoffice/${SHOP.slug}`;
  for (const path of [base, `${base}/menu`, `${base}/drivers`, `${base}/analytics`, `${base}/settings`]) {
    test(`shop ${path} loads, no errors, no sideways scroll`, async ({ page }) => {
      const problems = watchPage(page);
      await page.goto(path);
      await page.waitForLoadState("networkidle");
      expect(await horizontalOverflow(page), `sideways scroll on ${path}`).toBeNull();
      const real = problems.filter((p) => !/maps|google|gstatic/i.test(p));
      expect(real, `errors on ${path}`).toEqual([]);
    });
  }

  for (const tab of ["Shop & account", "Orders", "Delivery areas", "Opening hours", "Payments", "Report & chats"]) {
    test(`shop settings tab "${tab}" opens`, async ({ page }) => {
      const problems = watchPage(page);
      await page.goto(`${base}/settings`);
      await page.getByRole("tab", { name: tab }).click();
      await expect(page.getByRole("tab", { name: tab })).toHaveAttribute("aria-selected", "true");
      const real = problems.filter((p) => !/maps|google|gstatic/i.test(p));
      expect(real).toEqual([]);
    });
  }

  test.describe("settings, tidied", () => {
    const open = async (page, tab) => {
      await page.goto(`${base}/settings`);
      await page.getByRole("tab", { name: tab }).click();
      await page.waitForLoadState("networkidle");
    };
    const profile = (page) => page.evaluate(async (api) => {
      const r = await fetch(`${api}/settings/profile`, { headers: { Authorization: `Bearer ${localStorage.getItem("nt_merchant_token")}`, Accept: "application/json" } });
      const j = await r.json();
      return j.profile || j.data?.profile || j.data || j;
    }, process.env.E2E_API_URL || "http://127.0.0.1:8011/api/v1");

    test("the Shop & account tab holds the shop's name and the password change", async ({ page }) => {
      await open(page, "Shop & account");
      await expect(page.getByLabel(/Shop name/i)).toBeVisible();
      await expect(page.getByRole("button", { name: "Change password" })).toBeVisible();
      await expect(page.getByRole("tab", { name: "Account", exact: true })).toHaveCount(0);
    });

    test("money settings are together: the lira switch is on Payments, not on Orders", async ({ page }) => {
      await open(page, "Payments");
      await expect(page.getByText(/Lebanese lira/i).first()).toBeVisible();
      await expect(page.getByText("Whish transfer")).toBeVisible();
      await open(page, "Orders");
      await expect(page.getByText(/Lebanese lira/i)).toHaveCount(0);
    });

    test("job steps show only for shops that need them", async ({ page }) => {
      await open(page, "Orders");
      const p = await profile(page);
      const fixedPrice = ["restaurant", "grocery"].includes(p.business_kind) && !(p.job_stages || []).length;
      const steps = page.locator("#o-jobs");
      if (fixedPrice) { await expect(steps).toHaveCount(0); }
      else { await expect(steps).toBeVisible(); }
    });

    test("rating, delivery time and gifts are not settings any more: they are part of every order", async ({ page }) => {
      await open(page, "Orders");
      for (const gone of ["Ask customers to rate", "Customers choose the delivery day and time", "Gifts: send it to someone else", "Order options"]) {
        await expect(page.getByText(gone)).toHaveCount(0);
      }
    });

    test("the Whish badge says a number is saved, not that something is connected, and explains how it works", async ({ page }) => {
      await open(page, "Payments");
      await expect(page.getByText(/Number saved|Not set/)).toBeVisible();
      await expect(page.getByText("Connected", { exact: true })).toHaveCount(0);
      await expect(page.getByText(/How it works: after you accept an order/)).toBeVisible();
    });

    test("on a wide screen the cards sit in two columns; on a narrow one in one", async ({ page }) => {
      await open(page, "Payments");
      const lefts = await page.locator(".sset-cols > .sset-card").evaluateAll((els) => els.map((e) => Math.round(e.getBoundingClientRect().left)));
      const width = page.viewportSize().width;
      const columns = new Set(lefts).size;
      if (width >= 1280) expect(columns).toBe(2);
      if (width < 900) expect(columns).toBe(1);
    });
  });
});

// Delivery areas are distance rows ("up to 3 km: 1$"); there is no drawing on the map. The shop's pin and areas are set
// with plain requests and put back afterwards.
test.describe("delivery areas as distance rows", () => {
  test.use({ storageState: "e2e/.auth/shop.json" });
  const API = process.env.E2E_API_URL || "http://127.0.0.1:8011/api/v1";

  test("the owner types distances and fees, saves, and the rows are still there after a reload", async ({ page, request }) => {
    const problems = watchPage(page);
    await page.goto(`/backoffice/${SHOP.slug}/settings`);
    await page.waitForLoadState("networkidle");
    const token = await page.evaluate(() => localStorage.getItem("nt_merchant_token"));
    const headers = { Authorization: `Bearer ${token}`, Accept: "application/json" };
    const zones = async () => (await (await request.get(`${API}/settings/zones`, { headers })).json()).zones;
    const profile = async () => { const j = await (await request.get(`${API}/settings/profile`, { headers })).json(); return j.profile || j; };
    const before = await profile();
    const savedZones = await zones();
    for (const z of savedZones) await request.delete(`${API}/settings/zones/${z.id}`, { headers });
    await request.patch(`${API}/settings/profile`, { headers, data: { shop_location: "33.893800, 35.501800" } });
    try {
      await page.goto(`/backoffice/${SHOP.slug}/settings?tab=areas`);
      await expect(page.getByRole("button", { name: /draw a shape/i })).toHaveCount(0);
      const km = page.getByLabel("km"); const fee = page.getByLabel("fee");
      await expect(km).toHaveCount(3); // a new shop starts on 3, 6 and 10 km
      await expect(km.nth(0)).toHaveValue("3");

      await fee.nth(0).fill("1.5");
      await page.getByRole("button", { name: /add a distance/i }).click();
      await expect(km).toHaveCount(4);
      await km.nth(3).fill("3"); // the same as the first row
      await fee.nth(3).fill("9");
      await page.getByRole("button", { name: "Save delivery areas" }).click();
      await expect(page.getByText(/two rows have the same distance/i)).toBeVisible();
      expect(await zones()).toHaveLength(0); // nothing was saved

      await km.nth(3).fill("15");
      await page.getByRole("button", { name: "Save delivery areas" }).click();
      await expect(page.getByRole("button", { name: "Save delivery areas" })).toBeDisabled();
      const saved = await zones();
      expect(saved.map((z) => [z.area.type, z.area.radius, Number(z.delivery_fee)]).sort((a, b) => a[1] - b[1]))
        .toEqual([["circle", 3000, 1.5], ["circle", 6000, 2], ["circle", 10000, 3], ["circle", 15000, 9]]);

      await page.reload();
      await expect(page.getByLabel("km")).toHaveCount(4);
      await page.getByRole("button", { name: "remove" }).nth(3).click(); // taking a row away
      await page.getByRole("button", { name: "Save delivery areas" }).click();
      await expect(page.getByRole("button", { name: "Save delivery areas" })).toBeDisabled();
      expect(await zones()).toHaveLength(3);
      expect(await horizontalOverflow(page)).toBeNull();
      expect(problems.filter((p) => !/maps|google|gstatic/i.test(p))).toEqual([]);
    } finally {
      for (const z of await zones()) await request.delete(`${API}/settings/zones/${z.id}`, { headers });
      for (const z of savedZones) {
        await request.post(`${API}/settings/zones`, { headers, data: { name: z.name, area: z.area, color: z.color, deliveryFee: Number(z.delivery_fee), minimumOrder: Number(z.minimum_order), deliveryMins: z.delivery_mins } });
      }
      await request.patch(`${API}/settings/profile`, { headers, data: { shop_location: before.shop_location || "" } });
    }
  });
});
