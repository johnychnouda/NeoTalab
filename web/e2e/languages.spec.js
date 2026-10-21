import { test, expect } from "@playwright/test";
import { watchPage, horizontalOverflow } from "./helpers.js";

// Arabic (right-to-left) and French must lay out without sideways scrolling on the shop dashboard.
test.describe("shop dashboard in Arabic and French", () => {
  test.use({ storageState: "e2e/.auth/shop.json" });
  const slug = process.env.E2E_SHOP_SLUG;
  for (const [code, dir] of [["ar", "rtl"], ["fr", "ltr"]]) {
    test(`${code}: direction ${dir}, main pages fit the screen`, async ({ page, context }) => {
      await context.addCookies([{ name: "nt_lang", value: code, url: "http://localhost:3001" }]);
      const problems = watchPage(page);
      for (const path of [`/backoffice/${slug}`, `/backoffice/${slug}/menu`, `/backoffice/${slug}/drivers`, `/backoffice/${slug}/analytics`, `/backoffice/${slug}/settings`]) {
        await page.goto(path);
        await page.waitForLoadState("networkidle");
        await expect(page.locator("html")).toHaveAttribute("dir", dir);
        expect(await horizontalOverflow(page), `sideways scroll on ${path} (${code})`).toBeNull();
      }
      expect(problems.filter((p) => !/maps|google|gstatic/i.test(p))).toEqual([]);
    });
  }
});
