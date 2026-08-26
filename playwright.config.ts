import { defineConfig, devices } from '@playwright/test';

/**
 * Cross-browser smoke tests for the embeddable chat widget.
 *
 * The widget is "our real application" — it must work identically in Chrome,
 * Safari and Firefox. This config runs the same specs against all three real
 * engines (Chromium / WebKit / Gecko) so a Safari- or Firefox-only regression
 * fails CI instead of reaching a customer's site.
 *
 * The specs are fully self-contained: they mock /init + /messages/stream and
 * serve the built widget bundle, so no app server, DB, or LLM is required.
 * Run with `npm run test:widget` (after `npm run build:widget`).
 */
export default defineConfig({
    testDir: './tests/Browser',
    timeout: 30_000,
    expect: { timeout: 15_000 },
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: 0,
    reporter: process.env.CI ? 'github' : 'list',
    use: {
        ignoreHTTPSErrors: true,
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
        { name: 'webkit', use: { ...devices['Desktop Safari'] } },
        { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
    ],
});
