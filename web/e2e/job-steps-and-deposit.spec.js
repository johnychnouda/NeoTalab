import { test, expect } from "@playwright/test";
import { watchPage, horizontalOverflow } from "./helpers.js";

const slug = process.env.E2E_SHOP_SLUG;
test.use({ storageState: "e2e/.auth/shop.json" });

const API = process.env.E2E_API_URL || "http://127.0.0.1:8011/api/v1";

// Job steps are for repairs and custom work, so a restaurant's Settings does not show them (see DECISIONS.md):
// this test runs the shop as a kind of shop that uses them, and puts its kind back afterwards. The kind is
// changed and restored with plain requests (not the page), so it is restored even if the page has crashed.
test("shop settings: job steps are typed one per line, saved and still there after a reload", async ({ page, request }) => {
  const problems = watchPage(page);
  await page.goto(`/backoffice/${slug}/settings`);
  await page.waitForLoadState("networkidle");
  const token = await page.evaluate(() => localStorage.getItem("nt_merchant_token"));
  const headers = { Authorization: `Bearer ${token}`, Accept: "application/json" };
  const profile = async () => {
    const j = await (await request.get(`${API}/settings/profile`, { headers })).json();
    return j.profile || j.data?.profile || j.data || j;
  };
  const before = await profile();
  // Start clean (typing steps that are already saved changes nothing, so no Save button would appear).
  await request.patch(`${API}/settings/profile`, { headers, data: { business_kind: "other", job_stages: [] } });
  try {
    await runJobSteps(page, problems);
  } finally {
    await request.patch(`${API}/settings/profile`, { headers, data: { business_kind: before.business_kind, job_stages: before.job_stages || [] } });
  }
});

async function runJobSteps(page, problems) {
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
}
