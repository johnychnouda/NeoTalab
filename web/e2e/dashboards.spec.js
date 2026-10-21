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

  for (const tab of ["Orders", "Delivery areas", "Opening hours", "Payments", "Reports & chats", "Account"]) {
    test(`shop settings tab "${tab}" opens`, async ({ page }) => {
      const problems = watchPage(page);
      await page.goto(`${base}/settings`);
      await page.getByRole("tab", { name: tab }).click();
      await expect(page.getByRole("tab", { name: tab })).toHaveAttribute("aria-selected", "true");
      const real = problems.filter((p) => !/maps|google|gstatic/i.test(p));
      expect(real).toEqual([]);
    });
  }
});
