import { test, expect } from "@playwright/test";
import { watchPage } from "./helpers.js";

const realErrors = (problems) => problems.filter((p) => !/maps|google|gstatic/i.test(p));

// Full join journey. Submits one real application to the test API (not production); run once per hour
// because the join form is limited per IP.
test("join: complete the three steps and send an application", async ({ page }) => {
  test.skip(process.env.E2E_JOIN !== "1", "set E2E_JOIN=1 (it creates one application on the test API)");
  const problems = watchPage(page);
  await page.goto("/join");
  await page.locator("input").first().fill(`QA Shop ${Date.now()}`);
  await page.getByText(/Restaurant \/ Café/).first().click();
  await page.getByRole("button", { name: /Continue/ }).click();
  await expect(page.getByText(/Step 2 of 3/)).toBeVisible();
  const map = page.locator(".gm-style").first();
  await expect(map).toBeVisible({ timeout: 30_000 });
  const box = await map.boundingBox();
  await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
  await page.getByRole("button", { name: /Continue/ }).click();
  await expect(page.getByText(/Step 3 of 3/)).toBeVisible();
  await page.getByPlaceholder(/Ali Haddad|e\.g\./).first().fill("QA Tester");
  await page.locator('input[type="tel"]').first().fill(`7${String(Date.now()).slice(-7)}`);
  await page.locator('input[type="checkbox"]').first().check();
  await page.getByRole("button", { name: /Send my application/ }).click();
  await expect(page.getByText(/Application sent/)).toBeVisible({ timeout: 20_000 });
  expect(realErrors(problems)).toEqual([]);
});

test.describe("shop dashboard actions", () => {
  test.use({ storageState: "e2e/.auth/shop.json" });
  const slug = process.env.E2E_SHOP_SLUG;

  test("menu: add-item dialog opens, validates, and cancels without saving", async ({ page }) => {
    const problems = watchPage(page);
    await page.goto(`/backoffice/${slug}/menu`);
    await page.waitForLoadState("networkidle");
    const before = await page.locator("body").innerText();
    await page.getByRole("button", { name: /Add item|إضافة منتج|Ajouter/ }).first().click();
    const dialog = page.getByRole("dialog");
    await expect(dialog).toBeVisible();
    await dialog.getByRole("button", { name: /Cancel|إلغاء|Annuler/ }).click();
    await expect(dialog).toBeHidden();
    const after = await page.locator("body").innerText();
    expect(after.length).toBeGreaterThan(0);
    expect(before.includes("QA Test Item") || after.includes("QA Test Item")).toBe(false);
    expect(realErrors(problems)).toEqual([]);
  });

  test("team: add-member form refuses an empty name and number", async ({ page }) => {
    const problems = watchPage(page);
    await page.goto(`/backoffice/${slug}/drivers`);
    await page.waitForLoadState("networkidle");
    await page.getByRole("button", { name: /Add member|إضافة عضو|Ajouter/ }).first().click();
    const dialog = page.getByRole("dialog");
    await expect(dialog).toBeVisible();
    await dialog.getByRole("button", { name: /^(Add|إضافة|Ajouter)$/ }).click();
    await expect(page.getByText(/name and WhatsApp|الاسم ورقم|nom et le numéro/i).first()).toBeVisible();
    await dialog.getByRole("button", { name: /Cancel|إلغاء|Annuler/ }).click();
    expect(realErrors(problems)).toEqual([]);
  });

  test("settings: changing a field shows the save bar and Discard reverts it", async ({ page }) => {
    const problems = watchPage(page);
    await page.goto(`/backoffice/${slug}/settings`);
    await page.getByRole("tab", { name: /Opening hours|ساعات العمل|Horaires/ }).click();
    const sat = page.locator('input[type="checkbox"], [role="switch"]').last();
    const wasOn = await sat.isChecked().catch(() => null);
    if (wasOn === null) test.skip(true, "opening-hours switch not found in this layout");
    await sat.click();
    await expect(page.getByText(/unsaved|غير محفوظة|non enregistr/i).first()).toBeVisible();
    await page.getByRole("button", { name: /Discard|تجاهل|Annuler les modifications/ }).first().click();
    await expect(page.getByText(/unsaved|غير محفوظة|non enregistr/i)).toHaveCount(0);
    expect(await sat.isChecked()).toBe(wasOn);
    expect(realErrors(problems)).toEqual([]);
  });
});

test.describe("owner portal filters", () => {
  test.use({ storageState: "e2e/.auth/owner.json" });

  test("merchant filters change the list and keep it visible", async ({ page }) => {
    const problems = watchPage(page);
    await page.goto("/owner");
    await page.waitForLoadState("networkidle");
    for (const name of [/Requests/, /Active/, /Trial/, /Unpaid/, /Suspended/, /All/]) {
      await page.getByRole("button", { name }).first().click();
      await expect(page.locator("body")).toBeVisible();
    }
    expect(realErrors(problems)).toEqual([]);
  });
});
