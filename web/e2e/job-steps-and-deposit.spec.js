import { test, expect } from "@playwright/test";
import { watchPage, horizontalOverflow } from "./helpers.js";

const slug = process.env.E2E_SHOP_SLUG;
test.use({ storageState: "e2e/.auth/shop.json" });

test("shop settings: job steps are typed one per line, saved and still there after a reload", async ({ page }) => {
  const problems = watchPage(page);
  await page.goto(`/backoffice/${slug}/settings`);
  await page.getByRole("tab", { name: "Orders" }).click();
  const box = page.locator("#o-jobs");
  await expect(box).toBeVisible();

  await box.fill("Car received\n\nWork in progress\n  Ready for pickup  ");
  await page.getByRole("button", { name: "Save", exact: true }).click();
  await expect(page.getByRole("region", { name: /unsaved/i })).toBeHidden();

  await page.reload();
  await page.getByRole("tab", { name: "Orders" }).click();
  await expect(page.locator("#o-jobs")).toHaveValue("Car received\nWork in progress\nReady for pickup");

  // Back to none: a shop that does not use steps is left as it was.
  await page.locator("#o-jobs").fill("");
  await page.getByRole("button", { name: "Save", exact: true }).click();
  await expect(page.getByRole("region", { name: /unsaved/i })).toBeHidden();
  await page.reload();
  await page.getByRole("tab", { name: "Orders" }).click();
  await expect(page.locator("#o-jobs")).toHaveValue("");
  expect(await horizontalOverflow(page)).toBeNull();
  expect(problems.filter((p) => !/maps|google|gstatic|429|422/i.test(p))).toEqual([]);
});
