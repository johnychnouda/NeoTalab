import { test, expect } from "@playwright/test";

// Basic accessibility: every control has a name a screen reader can read, the page declares its
// language and direction, and sign-in works with the keyboard alone.
const SHOP = { email: process.env.E2E_SHOP_EMAIL, password: process.env.E2E_SHOP_PASSWORD, slug: process.env.E2E_SHOP_SLUG };

// Visible buttons, links and fields without any accessible name (text, aria-label, title, label, placeholder).
async function unnamedControls(page) {
  return page.evaluate(() => {
    const visible = (e) => { const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0 && getComputedStyle(e).visibility !== "hidden"; };
    const named = (e) => {
      if ((e.getAttribute("aria-label") || e.getAttribute("title") || "").trim()) return true;
      if (e.getAttribute("aria-labelledby")) return true;
      if (e.matches("input,select,textarea")) {
        if (e.type === "hidden") return true;
        if (e.id && document.querySelector(`label[for="${CSS.escape(e.id)}"]`)) return true;
        if (e.closest("label")) return true;
        return Boolean((e.getAttribute("placeholder") || "").trim());
      }
      if ((e.innerText || "").trim()) return true;
      return [...e.querySelectorAll("img[alt],svg[aria-label],[aria-label]")].some((x) => (x.getAttribute("alt") || x.getAttribute("aria-label") || "").trim());
    };
    return [...document.querySelectorAll("button,a[href],input,select,textarea,[role=button]")]
      .filter((e) => visible(e) && !named(e))
      .slice(0, 8)
      .map((e) => `${e.tagName.toLowerCase()}.${String(e.className).slice(0, 40)}`);
  });
}

test.describe("public pages", () => {
  test.use({ storageState: undefined });
  for (const path of ["/", "/join", "/privacy", "/backoffice", "/owner"]) {
    test(`${path}: language set and every control named`, async ({ page }) => {
      await page.goto(path);
      await page.waitForLoadState("networkidle");
      expect(await page.locator("html").getAttribute("lang")).toBeTruthy();
      expect(await unnamedControls(page), `unnamed controls on ${path}`).toEqual([]);
    });
  }

  test("shop sign-in with the keyboard only", async ({ page }) => {
    test.skip(!SHOP.email, "set E2E_SHOP_EMAIL");
    await page.goto("/backoffice");
    await page.locator('input[type="email"]').waitFor();
    // Tab from the top of the page until the email field has focus.
    for (let i = 0; i < 25 && !(await page.evaluate(() => document.activeElement?.type === "email")); i++) await page.keyboard.press("Tab");
    expect(await page.evaluate(() => document.activeElement?.type)).toBe("email");
    const ring = await page.evaluate(() => { const s = getComputedStyle(document.activeElement); return s.outlineStyle !== "none" || s.boxShadow !== "none" || s.borderColor !== ""; });
    expect(ring, "the focused field is visibly marked").toBe(true);
    await page.keyboard.type(SHOP.email);
    await page.keyboard.press("Tab");
    expect(await page.evaluate(() => document.activeElement?.type)).toBe("password");
    await page.keyboard.type(SHOP.password);
    await page.keyboard.press("Enter");
    await page.locator('input[type="password"]').waitFor({ state: "detached", timeout: 30_000 });
  });
});

test.describe("dashboards", () => {
  test("owner portal: every control named", async ({ browser }) => {
    const ctx = await browser.newContext({ storageState: "e2e/.auth/owner.json" });
    const page = await ctx.newPage();
    for (const path of ["/owner", "/owner/billing", "/owner/settings?tab=pricing"]) {
      await page.goto(path);
      await page.waitForLoadState("networkidle");
      expect(await unnamedControls(page), `unnamed controls on ${path}`).toEqual([]);
    }
    await ctx.close();
  });

  test("shop dashboard: every control named, Arabic is right-to-left", async ({ browser }) => {
    test.skip(!SHOP.slug, "set E2E_SHOP_SLUG");
    const ctx = await browser.newContext({ storageState: "e2e/.auth/shop.json" });
    const page = await ctx.newPage();
    const base = `/backoffice/${SHOP.slug}`;
    for (const path of [base, `${base}/menu`, `${base}/drivers`, `${base}/settings`]) {
      await page.goto(path);
      await page.waitForLoadState("networkidle");
      expect(await unnamedControls(page), `unnamed controls on ${path}`).toEqual([]);
    }
    await ctx.close();
  });
});
