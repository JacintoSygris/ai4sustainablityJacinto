import { defineConfig } from "@playwright/test"

// End-to-end tests against a running stack (local "like production" stack by default).
// Required: E2E_EMAIL / E2E_PASSWORD of a verified user. See tests/e2e/README.md.
const baseURL = process.env.E2E_BASE_URL?.trim() || "https://localhost:8443"

export default defineConfig({
  testDir: "./tests/e2e",
  timeout: 180_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [["list"], ["html", { open: "never", outputFolder: "test-results/html" }]],
  outputDir: "test-results/artifacts",
  use: {
    baseURL,
    ignoreHTTPSErrors: true,
    // The installed system Chrome avoids downloading Playwright's own Chromium build.
    // E2E_BROWSER_PATH points at another Chromium-based browser (e.g. Brave) instead.
    ...(process.env.E2E_BROWSER_PATH?.trim()
      ? { launchOptions: { executablePath: process.env.E2E_BROWSER_PATH.trim(), slowMo: Number(process.env.E2E_SLOW_MO ?? 0) || 0 } }
      : { channel: process.env.E2E_BROWSER_CHANNEL?.trim() || "chrome", launchOptions: { slowMo: Number(process.env.E2E_SLOW_MO ?? 0) || 0 } }),
    locale: "es-ES",
    viewport: { width: 1280, height: 900 },
    screenshot: "only-on-failure",
    trace: "retain-on-failure",
    video: "off",
  },
})
