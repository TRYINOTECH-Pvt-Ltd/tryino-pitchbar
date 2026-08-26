import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';

const __dirname = dirname(fileURLToPath(import.meta.url));

/**
 * Live-handoff regression: the "<Name> joined the chat" notice is a
 * one-time event, NOT a permanent status. A client reported it sitting
 * pinned above the input forever after an operator joined (the header's
 * "Live agent" pill is the persistent indicator). This spec proves the
 * notice appears once on claim and auto-dismisses, while "Live agent"
 * stays — identically in Chromium, WebKit and Firefox.
 *
 * Self-contained: mocks /init, /messages/stream and the human-message
 * long-poll (/conversation/messages), serves the built bundle. No app
 * server / DB / LLM.
 *
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

const SSE = [
    'event: start',
    'data: {"conversation_id":"test-convo"}',
    '',
    'event: token',
    'data: {"t":"One moment."}',
    '',
    'event: done',
    'data: {"text":"One moment.","message_id":"m-test","citations":[]}',
    '',
    '',
].join('\n');

// The operator has claimed and sent one message. Every poll returns the
// same payload — once is_claimed flips false→true the notice arms; later
// polls (already claimed) must NOT re-arm it.
const CLAIMED = {
    data: {
        is_claimed: true,
        human_requested_at: '2026-06-26T10:00:00Z',
        operator: { name: 'Sarah', personalized: true },
        operator_typing: false,
        messages: [
            {
                id: 'op-1',
                role: 'human-agent',
                content: 'Hi, Sarah here — how can I help?',
                at: '2026-06-26T10:00:01Z',
            },
        ],
    },
};

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
    // Human-message long-poll — operator already owns the conversation.
    await page.route(
        `${ORIGIN}/api/v1/widget/conversation/messages**`,
        (route) =>
            route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify(CLAIMED),
            }),
    );
});

test('operator-joined notice shows once then auto-dismisses; "Live agent" pill stays', async ({
    page,
}) => {
    const pageErrors: string[] = [];
    page.on('pageerror', (e) => pageErrors.push(e.message));

    await page.goto(`${ORIGIN}/`);

    const root = page.locator('#pitchbar-root');
    const input = root.locator('input').first();
    await expect(input).toBeVisible();

    // Open the panel + start the human-message poll.
    await input.click();
    await input.fill('I need a person');
    await input.press('Enter');

    // The operator's message lands (proves the poll wired up).
    await expect(root).toContainText('Sarah here');

    // The one-time join notice appears…
    const notice = root.getByText('joined the chat');
    await expect(notice).toBeVisible();

    // …and the persistent live indicator is up.
    await expect(root.getByText('Live agent', { exact: true })).toBeVisible();

    // …then the notice auto-dismisses (~8s deadline; expect polls up to
    // 15s) while "Live agent" remains. This is the regression: before the
    // fix the notice never left.
    await expect(notice).toBeHidden();
    await expect(root.getByText('Live agent', { exact: true })).toBeVisible();

    expect(
        pageErrors,
        `uncaught page errors: ${pageErrors.join(' | ')}`,
    ).toEqual([]);
});
