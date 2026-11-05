import { test, expect } from "@playwright/test";
import { watchPage, horizontalOverflow } from "./helpers.js";

// Every page in English, Arabic and French (the desktop, tablet and phone projects run this file each), and every tab on
// it: the right direction, no sideways scroll, no crash or server error, and no translation key shown instead of words.
const slug = process.env.E2E_SHOP_SLUG;
const LANGS = [["en", "ltr"], ["ar", "rtl"], ["fr", "ltr"]];
const TABS = ".sset-tabs button, .inbox-filters button, .wa-tabs button, [role=tab]";
const RAW_KEY = /\b(?:db|ow|nav|site|join|auth|common)\.[a-z][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)+\b/;

async function check(page, label, dir, problems, found) {
  await page.waitForLoadState("networkidle");
  await expect(page.locator("html"), label).toHaveAttribute("dir", dir);
  const over = await horizontalOverflow(page);
  if (over) found.push(`${label}: sideways scroll ${JSON.stringify(over)}`);
  const text = await page.locator("body").innerText();
  const raw = text.match(RAW_KEY);
  if (raw) found.push(`${label}: translation key shown: ${raw[0]}`);
  for (const p of problems.splice(0).filter((p) => !/maps|google|gstatic/i.test(p))) found.push(`${label}: ${p}`);
}

async function visit(page, path, code, dir, problems, found) {
  await page.goto(path);
  await check(page, `${path} (${code})`, dir, problems, found);
  const tabs = page.locator(TABS);
  const n = await tabs.count();
  for (let i = 0; i < n; i++) {
    const tab = tabs.nth(i);
    if (!(await tab.isVisible())) continue;
    const name = (await tab.innerText()).trim().slice(0, 24);
    await tab.click();
    await check(page, `${path} (${code}) tab "${name}"`, dir, problems, found);
  }
}

for (const [code, dir] of LANGS) {
  test.describe(`${code}`, () => {
    test(`public pages`, async ({ page, context, baseURL }) => {
      await context.addCookies([{ name: "nt_lang", value: code, url: baseURL }]);
      const problems = watchPage(page); const found = [];
      for (const path of ["/", "/join", "/contact", "/privacy", "/terms", "/data-deletion", "/reset-password", "/backoffice", "/owner"]) {
        await visit(page, path, code, dir, problems, found);
      }
      expect(found).toEqual([]);
    });

    test.describe("shop", () => {
      test.use({ storageState: "e2e/.auth/shop.json" });
      test(`shop dashboard, every page and tab`, async ({ page, context, baseURL }) => {
        await context.addCookies([{ name: "nt_lang", value: code, url: baseURL }]);
        const problems = watchPage(page); const found = [];
        for (const sub of ["", "/menu", "/drivers", "/analytics", "/settings"]) {
          await visit(page, `/backoffice/${slug}${sub}`, code, dir, problems, found);
        }
        expect(found).toEqual([]);
      });
    });

    test.describe("owner", () => {
      test.use({ storageState: "e2e/.auth/owner.json" });
      test(`owner portal, every page and tab`, async ({ page, context, baseURL }) => {
        await context.addCookies([{ name: "nt_lang", value: code, url: baseURL }]);
        const problems = watchPage(page); const found = [];
        for (const path of ["/owner", "/owner/inbox", "/owner/billing", "/owner/usage", "/owner/settings"]) {
          await visit(page, path, code, dir, problems, found);
        }
        expect(found).toEqual([]);
      });
    });
  });
}
