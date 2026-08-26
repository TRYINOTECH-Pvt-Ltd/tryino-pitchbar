import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';

const __dirname = dirname(fileURLToPath(import.meta.url));

/**
 * Cross-browser smoke test for the embeddable widget. Runs in Chromium,
 * WebKit (Safari engine) and Firefox via playwright.config.ts. Fully
 * self-contained — mocks /init + /messages/stream and serves the built
 * bundle, so it needs no app server / DB / LLM.
 *
 * Prereq: `npm run build:widget` (reads public/widget/<hashed>.js).
 */

const ROOT = resolve(__dirname, '../..');
const ORIGIN = 'https://widget-test.local';

function widgetBundle(): string {
    const manifest = JSON.parse(
        readFileSync(resolve(ROOT, 'public/widget/manifest.json'), 'utf8'),
    ) as { file: string };

    return readFileSync(resolve(ROOT, 'public/widget', manifest.file), 'utf8');
}

const INIT = JSON.parse(
    readFileSync(resolve(__dirname, 'fixtures/init.json'), 'utf8'),
);

// Canned SSE reply. Blank lines (the '' entries) are the \n\n message
// boundaries the widget's reader splits on.
const SSE = [
    'event: start',
    'data: {"conversation_id":"test-convo"}',
    '',
    'event: token',
    'data: {"t":"Hello"}',
    '',
    'event: token',
    'data: {"t":" from the"}',
    '',
    'event: token',
    'data: {"t":" cross-browser"}',
    '',
    'event: token',
    'data: {"t":" test"}',
    '',
    'event: done',
    'data: {"text":"Hello from the cross-browser test","message_id":"m-test","citations":[]}',
    '',
    '',
].join('\n');

const FIXTURE_HTML = `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>widget host</title></head><body><h1>host page</h1><script src="${ORIGIN}/widget.js" data-agent-id="test-agent"></script></body></html>`;

test.beforeEach(async ({ page }) => {
    // Catch-all FIRST so the specific routes below (registered later) win —
    // Playwright gives precedence to the most recently registered handler.
    await page.route(`${ORIGIN}/api/v1/widget/**`, (route) =>
        route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: '{"data":{}}',
        }),
    );
    await page.route(`${ORIGIN}/`, (route) =>
        route.fulfill({ contentType: 'text/html', body: FIXTURE_HTML }),
    );
    await page.route(`${ORIGIN}/widget.js`, (route) =>
        route.fulfill({
            contentType: 'application/javascript',
            body: widgetBundle(),
        }),
    );
    await page.route(`${ORIGIN}/api/v1/widget/init`, (route) =>
        route.fulfill({
            contentType: 'application/json',
            body: JSON.stringify({ data: INIT }),
        }),
    );
    await page.route(`${ORIGIN}/api/v1/widget/messages/stream`, (route) =>
        route.fulfill({
            status: 200,
            contentType: 'text/event-stream',
            body: SSE,
        }),
    );
});

test('mounts, takes input and streams a reply — identically in every engine', async ({
    page,
}) => {
    // Any uncaught exception is exactly the class of bug that breaks a single
    // engine (a missing API, a syntax the engine can't parse). Fail on it.
    const pageErrors: string[] = [];
    page.on('pageerror', (e) => pageErrors.push(e.message));

    await page.goto(`${ORIGIN}/`);

    // The omnibar input renders inside the open shadow root (Playwright
    // locators pierce it automatically).
    const input = page.locator('#pitchbar-root input').first();
    await expect(input).toBeVisible();

    await input.click();
    await input.fill('hello, what do you offer?');
    await input.press('Enter');

    // The mocked stream's tokens must render — proves fetch-streaming + SSE
    // parsing work in this engine, not just that the widget mounted.
    await expect(page.locator('#pitchbar-root')).toContainText(
        'cross-browser test',
    );

    expect(
        pageErrors,
        `uncaught page errors: ${pageErrors.join(' | ')}`,
    ).toEqual([]);
});
