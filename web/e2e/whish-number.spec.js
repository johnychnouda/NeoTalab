import { test, expect } from "@playwright/test";
import { watchPage, horizontalOverflow } from "./helpers.js";

const slug = process.env.E2E_SHOP_SLUG;
test.use({ storageState: "e2e/.auth/shop.json" });

test("shop settings: the Whish number is validated, saved and still there after a reload", async ({ page }) => {
  const problems = watchPage(page);
  await page.goto(`/backoffice/${slug}/settings`);
  await page.getByRole("tab", { name: "Payments" }).click();
  const field = page.locator("#w-no");

  await field.fill("not a number");
  await page.getByRole("button", { name: "Save", exact: true }).click();
  await expect(page.getByText(/digits only/i).first()).toBeVisible();

  await field.fill("70 123 456");
  await page.getByRole("button", { name: "Save", exact: true }).click();
  await expect(page.getByRole("region", { name: /unsaved/i })).toBeHidden();

  await page.reload();
  await page.getByRole("tab", { name: "Payments" }).click();
  await expect(page.locator("#w-no")).toHaveValue(/70\s?123\s?456/);
  await expect(page.locator(".sset-badge.ok")).toBeVisible();
  expect(await horizontalOverflow(page)).toBeNull();
  expect(problems.filter((p) => !/maps|google|gstatic|429|422/i.test(p))).toEqual([]);
});
