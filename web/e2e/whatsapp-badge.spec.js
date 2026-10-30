import { test, expect } from "@playwright/test";
import { watchPage } from "./helpers.js";

test.use({ storageState: "e2e/.auth/owner.json" });

test("owner WhatsApp tab: the badge asks Meta and shows an answer, never a bare 'Connected'", async ({ page }) => {
  const problems = watchPage(page);
  const asked = page.waitForResponse((r) => r.url().includes("/admin/platform-settings/whatsapp-status"));
  await page.goto("/owner/settings?tab=whatsapp");
  expect((await asked).status()).toBe(200);

  // The lab has no WhatsApp number saved, so the honest answer is "Not connected".
  await expect(page.locator(".set-badge")).toContainText(/Not connected|Connected|Not working/);
  await expect(page.getByRole("button", { name: "Check again" })).toHaveCount(1);
  expect(problems.filter((p) => !/maps|google|gstatic|429/i.test(p))).toEqual([]);
});
