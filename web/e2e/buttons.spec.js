import { test, expect } from "@playwright/test";
import { watchPage } from "./helpers.js";

// Clicks every visible button on a page, one at a time, and checks that nothing breaks.
// Buttons that delete, send, save, pay, sign out, connect a number or change access are skipped on purpose.
const SKIP = /delete|remove|send|save|submit|sign ?out|log ?out|logout|connect|remind|remind|block|unblock|approve|reject|resend|restart|test|generate|rotate|impersonate|end trial|activate|reactivate|pay|confirm|discard|apply|copy|share|download|export|verify|reset|password|حذف|إرسال|حفظ|تسجيل الخروج|ربط|تذكير|إلغاء الحظر|supprimer|envoyer|enregistrer|déconnexion|connecter|relance|valider/i;
const PAGES = {
  owner: ["/owner", "/owner/billing", "/owner/inbox", "/owner/usage", "/owner/settings?tab=pricing", "/owner/settings?tab=alerts", "/owner/settings?tab=security"],
  shop: ["/backoffice/SLUG", "/backoffice/SLUG/menu", "/backoffice/SLUG/drivers", "/backoffice/SLUG/analytics", "/backoffice/SLUG/settings"],
};

async function clickEveryHarmlessButton(page, problems, label) {
  const names = await page.getByRole("button").evaluateAll((els) =>
    els.filter((e) => e.offsetParent !== null && !e.disabled).map((e) => (e.innerText || e.getAttribute("aria-label") || "").trim().slice(0, 60)),
  );
  const seen = new Set();
  let clicked = 0;
  for (const name of names) {
    if (!name || seen.has(name) || SKIP.test(name)) continue;
    seen.add(name);
    const button = page.getByRole("button", { name: name, exact: true }).first();
    if (!(await button.isVisible().catch(() => false))) continue;
    const before = problems.length;
    await button.click({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(400);
    await page.keyboard.press("Escape");
    const newProblems = problems.slice(before).filter((p) => !/maps|google|gstatic/i.test(p));
    expect(newProblems, `after clicking "${name}" on ${label}`).toEqual([]);
    clicked++;
  }
  return clicked;
}

test.describe("owner portal: every harmless button", () => {
  test.use({ storageState: "e2e/.auth/owner.json" });
  for (const path of PAGES.owner) {
    test(`clicks the harmless buttons on ${path}`, async ({ page }) => {
      test.skip(!process.env.E2E_OWNER_EMAIL, "set owner test login");
      const problems = watchPage(page);
      await page.goto(path);
      await page.waitForLoadState("networkidle");
      const clicked = await clickEveryHarmlessButton(page, problems, path);
      console.log(`${path}: clicked ${clicked} buttons`);
    });
  }
});

test.describe("shop dashboard: every harmless button", () => {
  test.use({ storageState: "e2e/.auth/shop.json" });
  for (const raw of PAGES.shop) {
    const path = raw.replace("SLUG", process.env.E2E_SHOP_SLUG || "");
    test(`clicks the harmless buttons on ${path}`, async ({ page }) => {
      test.skip(!process.env.E2E_SHOP_SLUG, "set shop test login");
      const problems = watchPage(page);
      await page.goto(path);
      await page.waitForLoadState("networkidle");
      const clicked = await clickEveryHarmlessButton(page, problems, path);
      console.log(`${path}: clicked ${clicked} buttons`);
    });
  }
});
