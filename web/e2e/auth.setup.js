import { test as setup } from "@playwright/test";

// Sign in once per account and reuse the session, so repeated tests don't hit the login rate limit.
// The sessions are stored under e2e/.auth (git-ignored).
const OWNER = { email: process.env.E2E_OWNER_EMAIL, password: process.env.E2E_OWNER_PASSWORD };
const SHOP = { email: process.env.E2E_SHOP_EMAIL, password: process.env.E2E_SHOP_PASSWORD, slug: process.env.E2E_SHOP_SLUG };

// The sign-in page and the dashboard can share an address, so success means the password box has gone.
async function login(page, { email, password }, startPath) {
  await page.goto(startPath);
  await page.locator('input[type="email"]').fill(email);
  await page.locator('input[type="password"]').fill(password);
  await page.keyboard.press("Enter");
  await page.locator('input[type="password"]').waitFor({ state: "detached", timeout: 30_000 });
  await page.waitForLoadState("networkidle");
}

setup("platform owner session", async ({ page }) => {
  test_skip_if_missing(OWNER.email, "E2E_OWNER_EMAIL");
  await login(page, OWNER, "/owner");
  if (!(await page.locator('input[type="password"]').count())) {}
  await page.context().storageState({ path: "e2e/.auth/owner.json" });
});

setup("shop session", async ({ page }) => {
  test_skip_if_missing(SHOP.email, "E2E_SHOP_EMAIL");
  await login(page, SHOP, "/backoffice");
  await page.goto(`/backoffice/${SHOP.slug}`);
  await page.waitForLoadState("networkidle");
  await page.context().storageState({ path: "e2e/.auth/shop.json" });
});

function test_skip_if_missing(value, name) {
  if (!value) throw new Error(`set ${name} to run these tests`);
}
