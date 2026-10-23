// Stored-XSS check in the real UI: names containing HTML/JS must render as text.
import { createRequire } from "module";
const require = createRequire(new URL("../web/package.json", import.meta.url).pathname);
const { chromium } = require("@playwright/test");
const AUTH = new URL("../web/e2e/.auth", import.meta.url).pathname;
const slug = process.argv[2];
const b = await chromium.launch();
const out = {};
for (const [who, file, url] of [["shop", "shop.json", `/backoffice/${slug}/menu`], ["shop-start", "shop.json", `/backoffice/${slug}`], ["owner", "owner.json", "/owner"]]) {
  const ctx = await b.newContext({ storageState: `${AUTH}/${file}` });
  const page = await ctx.newPage();
  let dialogs = 0;
  page.on("dialog", async (d) => { dialogs++; await d.dismiss(); });
  await page.goto("http://localhost:3011" + url);
  await page.waitForLoadState("networkidle");
  await page.waitForTimeout(1500);
  const fired = await page.evaluate(() => window.__xss === 1);
  const shown = await page.getByText("onerror", { exact: false }).count();
  out[who] = { dialogs, fired, payloadShownAsText: shown > 0 };
  await ctx.close();
}
console.log(JSON.stringify(out));
await b.close();
