import { test, expect } from "@playwright/test";
import { watchPage, horizontalOverflow } from "./helpers.js";

const PAGES = ["/", "/join", "/contact", "/privacy", "/terms", "/data-deletion", "/pay/success", "/pay/failure"];

for (const path of PAGES) {
  test(`public page ${path} loads cleanly`, async ({ page }) => {
    const problems = watchPage(page);
    const response = await page.goto(path);
    expect(response.status(), `HTTP status for ${path}`).toBeLessThan(400);
    await page.waitForLoadState("networkidle");
    const overflow = await horizontalOverflow(page);
    expect(overflow, `sideways scroll on ${path}`).toBeNull();
    // Google Maps may log its own warnings; anything else is a real problem.
    const real = problems.filter((p) => !/maps|google|gstatic/i.test(p));
    expect(real, `errors on ${path}`).toEqual([]);
  });
}

test("join form refuses to continue without a shop name", async ({ page }) => {
  await page.goto("/join");
  await page.getByText(/Restaurant \/ Café/).first().click();
  await page.getByRole("button", { name: /Continue/ }).click();
  // Still on step 1, with a message asking for the shop name.
  await expect(page.getByText(/Step 1 of 3/)).toBeVisible();
  await expect(page.getByText(/shop name|name is required|Enter your shop/i).first()).toBeVisible();
});

test("Arabic site is right-to-left and French is left-to-right", async ({ page, context }) => {
  await context.addCookies([{ name: "nt_lang", value: "ar", url: "http://localhost" }]);
  await page.goto("/");
  await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
  await context.clearCookies();
  await context.addCookies([{ name: "nt_lang", value: "fr", url: "http://localhost" }]);
  await page.goto("/");
  await expect(page.locator("html")).toHaveAttribute("dir", "ltr");
});
