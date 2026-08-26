import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';

const __dirname = dirname(fileURLToPath(import.meta.url));

/**
 * Links in the assistant reply must be clickable. A client reported that
 * markdown links and bare URLs rendered as literal text (e.g. the booking
 * page showed "[our booking page](https://…/book/276)" instead of a link).
 * renderRichText now turns markdown links, bare http(s) URLs, and
 * mailto:/tel: into real <a> elements — proven here across all engines.
 *
 * Self-contained: mocks /init + /messages/stream, serves the built bundle.
 * Prereq: `npm run build:widget`.
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

// A reply with a markdown link, a mailto markdown link, and a bare URL.
const DONE_TEXT =
    'Book online via [our booking page](https://shop.example.com/book/276) ' +
    'or email [us](mailto:hi@example.com). More at https://shop.example.com/help.';

const SSE = [
    'event: start',
    'data: {"conversation_id":"test-convo"}',
    '',
    'event: token',
    'data: {"t":"Book online…"}',
    '',
    `event: done`,
    `data: ${JSON.stringify({ text: DONE_TEXT, message_id: 'm-test', citations: [] })}`,
    '',
    '',
].join('\n');

const FIXTURE_HTML = `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>widget host</title></head><body><h1>host page</h1><script src="${ORIGIN}/widget.js" data-agent-id="test-agent"></script></body></html>`;

test.beforeEach(async ({ page }) => {
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

test('markdown links, mailto, and bare URLs render as clickable anchors', async ({
    page,
}) => {
    const pageErrors: string[] = [];
    page.on('pageerror', (e) => pageErrors.push(e.message));

    await page.goto(`${ORIGIN}/`);

    const root = page.locator('#pitchbar-root');
    const input = root.locator('input').first();
    await expect(input).toBeVisible();

    await input.click();
    await input.fill('how can I book?');
    await input.press('Enter');

    // Markdown link → <a href> with the label as its text, not the raw
    // "[label](url)" string.
    const booking = root.locator('a[href="https://shop.example.com/book/276"]');
    await expect(booking).toBeVisible();
    await expect(booking).toHaveText('our booking page');
    await expect(booking).toHaveAttribute('target', '_blank');

    // mailto markdown link.
    await expect(root.locator('a[href="mailto:hi@example.com"]')).toHaveText(
        'us',
    );

    // Bare URL → clickable (trailing period excluded from the href).
    await expect(
        root.locator('a[href="https://shop.example.com/help"]'),
    ).toBeVisible();

    // The raw markdown syntax must NOT survive as literal text.
    await expect(root).not.toContainText(
        '](https://shop.example.com/book/276)',
    );

    expect(
        pageErrors,
        `uncaught page errors: ${pageErrors.join(' | ')}`,
    ).toEqual([]);
});
