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

  for (const tab of ["Shop & account", "Orders", "Delivery areas", "Opening hours", "Payments", "Reports & chats"]) {
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
      await expect(page.getByText("Whish Pay")).toBeVisible();
      await open(page, "Orders");
      await expect(page.getByText(/Lebanese lira/i)).toHaveCount(0);
    });

    test("repairs and custom work options show only for shops that need them", async ({ page }) => {
      await open(page, "Orders");
      const p = await profile(page);
      const fixedPrice = ["restaurant", "grocery", "water"].includes(p.business_kind) && !p.quotes;
      const quotes = page.getByText("Quote a price for each job");
      const steps = page.locator("#o-jobs");
      if (fixedPrice) { await expect(quotes).toHaveCount(0); await expect(steps).toHaveCount(0); }
      else { await expect(quotes).toBeVisible(); await expect(steps).toBeVisible(); }
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
