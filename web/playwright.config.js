import { defineConfig, devices } from "@playwright/test";

// The app under test. Default: the local production build on port 3001.
const baseURL = process.env.E2E_BASE_URL || "http://localhost:3001";

export default defineConfig({
  testDir: "./e2e",
  timeout: 60_000,
  expect: { timeout: 10_000 },
  retries: 0,
  reporter: [["list"], ["html", { open: "never", outputFolder: "e2e-report" }]],
  use: {
    baseURL,
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
  },
  projects: [
    { name: "setup", testMatch: /auth\.setup\.js/ },
    { name: "desktop-chromium", dependencies: ["setup"], testIgnore: /auth\.setup\.js/, use: { ...devices["Desktop Chrome"], viewport: { width: 1280, height: 800 } } },
    { name: "phone-chromium", dependencies: ["setup"], testIgnore: /auth\.setup\.js/, use: { ...devices["Pixel 5"] } },
    { name: "tablet-chromium", dependencies: ["setup"], testIgnore: /auth\.setup\.js/, use: { ...devices["Desktop Chrome"], viewport: { width: 820, height: 1180 }, isMobile: true, hasTouch: true } },
    { name: "desktop-firefox", dependencies: ["setup"], testIgnore: /auth\.setup\.js/, use: { ...devices["Desktop Firefox"], viewport: { width: 1280, height: 800 } } },
    { name: "desktop-webkit", dependencies: ["setup"], testIgnore: /auth\.setup\.js/, use: { ...devices["Desktop Safari"], viewport: { width: 1280, height: 800 } } },
  ],
});
