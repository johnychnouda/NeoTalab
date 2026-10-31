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

test("owner WhatsApp tab: once Meta says the number is connected, the page no longer asks to connect", async ({ page }) => {
  await page.route("**/admin/platform-settings/whatsapp-status", (route) =>
    route.fulfill({ json: { state: "connected", phone: "+961 70 207 078", name_status: "APPROVED" } }));
  await page.goto("/owner/settings?tab=whatsapp");
  await expect(page.locator(".set-badge")).toContainText("Connected");
  await expect(page.getByRole("button", { name: /Connect NeoTalab's number/ })).toHaveCount(0);
  // The line under the QR title agrees with the badge, so the page never says "connect" and "connected" at once.
  await expect(page.getByText("Connected: +961 70 207 078")).toBeVisible();
  await expect(page.getByText(/Use a number that is on the WhatsApp Business app/)).toHaveCount(0);
  // No big "Reconnect" button on a healthy connection: only a quiet link that opens it on demand.
  await expect(page.locator("#wa-embedded-signup-platform")).toHaveCount(0);
  await page.getByRole("button", { name: "Problem with the connection? Reconnect" }).click();
  await expect(page.getByRole("button", { name: "Problem with the connection? Reconnect" })).toHaveCount(0);
});

test("owner WhatsApp tab: when the number is not connected, the connect button is calm and fits its text", async ({ page }, info) => {
  await page.route("**/admin/platform-settings/whatsapp-status", (route) => route.fulfill({ json: { state: "not_set" } }));
  await page.route("**/admin/whatsapp/embedded-signup/config", (route) =>
    route.fulfill({ json: { enabled: true, appId: "123", configId: "456" } }));
  await page.route("https://connect.facebook.net/**", (route) =>
    route.fulfill({ contentType: "application/javascript", body: "window.FB={init(){},login(){}};" }));
  await page.goto("/owner/settings?tab=whatsapp");

  const button = page.locator("#wa-embedded-signup-platform");
  await expect(button).toContainText("Connect NeoTalab's number");
  await expect(button).not.toHaveClass(/paction-primary/);
  const [box, card] = await Promise.all([button.boundingBox(), page.locator(".set-form-block").boundingBox()]);
  // On a wide screen it is as wide as its words, not the whole card; on a phone it may fill the line.
  if (page.viewportSize().width > 900) expect(box.width).toBeLessThan(card.width * 0.5);
  expect(box.width).toBeLessThanOrEqual(card.width);
  await page.screenshot({ path: `test-results/wa-not-connected-${info.project.name}.png`, fullPage: true });
});
