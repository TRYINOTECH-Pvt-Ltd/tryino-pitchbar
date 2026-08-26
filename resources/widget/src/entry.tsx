import { h, render } from 'preact';
import { App } from './App';

declare global {
    interface Window {
        Pitchbar?: {
            mount: (host?: HTMLElement) => void;
            unmount: () => void;
        };
    }
}

// Accept both `data-agent-id` (direct embeds, legacy) and `data-agent`
// (CMS adapters like the WordPress plugin). Look up either; emit either.
const SCRIPT_TAG_ATTRS = ['data-agent-id', 'data-agent'] as const;
const DEMO_ATTR = 'data-demo';
const SHOPPER_TOKEN_ATTR = 'data-shopper-token';
const LOCALE_ATTR = 'data-locale';

/**
 * Host page's declared language (`<html lang>`), or null. Used as the
 * widget locale when the embed doesn't set an explicit `data-locale`,
 * so the chat follows whatever language the surrounding site renders.
 */
function readPageLang(): string | null {
    try {
        const lang = document.documentElement.getAttribute('lang');

        return lang !== null && lang.trim() !== '' ? lang.trim() : null;
    } catch {
        return null;
    }
}

/**
 * Capture EVERYTHING we need from <script> at module-load time, while
 * `document.currentScript` is still valid. After the synchronous body
 * finishes (i.e., the moment we register a DOMContentLoaded listener
 * or hand control to the browser), `currentScript` becomes null —
 * critically with `async` / `defer`, by the time bootstrap() actually
 * runs there's no way to find our own script tag again.
 *
 * Two things must come from the script tag, NOT from anything else:
 *   1. data-agent-id — which agent to talk to
 *   2. the script src's origin — where the Pitchbar API lives
 *
 * Falling back to window.location.origin for #2 is dangerous: that's
 * the visitor's e-commerce site, not us. POSTs to /api/v1/widget/init
 * would hit their server and 405 / 404 / blow up Symfony / etc.
 */
type Captured = {
    agentId: string | null;
    baseUrl: string;
    demo: boolean;
    shopperToken: string | null;
    locale: string | null;
};

function readAgentId(el: HTMLScriptElement | null): string | null {
    if (el === null) {
        return null;
    }

    for (const attr of SCRIPT_TAG_ATTRS) {
        const value = el.getAttribute(attr);

        if (value !== null && value !== '') {
            return value;
        }
    }

    return null;
}

function captureFromScript(): Captured {
    const current = document.currentScript as HTMLScriptElement | null;

    let agentId = readAgentId(current);
    let src = current?.src ?? null;
    let demoAttr = current?.getAttribute(DEMO_ATTR) ?? null;
    let shopperToken = current?.getAttribute(SHOPPER_TOKEN_ATTR) ?? null;
    let localeAttr = current?.getAttribute(LOCALE_ATTR) ?? null;

    if (!agentId || !src || demoAttr === null) {
        // Fallback: scan every <script src> tag that carries either of
        // our agent-id attributes. Use the LAST one — that's almost
        // always the most recently added, i.e., ours.
        const selector = SCRIPT_TAG_ATTRS.map((a) => `script[${a}]`).join(',');
        const scripts = document.querySelectorAll<HTMLScriptElement>(selector);

        for (let i = scripts.length - 1; i >= 0; i--) {
            const candidate = scripts[i];
            agentId ||= readAgentId(candidate);
            src ||= candidate.src;
            demoAttr = demoAttr ?? candidate.getAttribute(DEMO_ATTR) ?? null;
            shopperToken =
                shopperToken ??
                candidate.getAttribute(SHOPPER_TOKEN_ATTR) ??
                null;
            localeAttr =
                localeAttr ?? candidate.getAttribute(LOCALE_ATTR) ?? null;

            if (agentId && src && demoAttr !== null) {
                break;
            }
        }
    }

    let baseUrl = window.location.origin;

    if (src) {
        try {
            const u = new URL(src);
            baseUrl = `${u.protocol}//${u.host}`;
        } catch {
            // malformed src — keep the fallback
        }
    }

    // Treat the attr as truthy for any non-"false" value so both
    // `data-demo` (no value) and `data-demo="true"` enable the badge.
    const demo = demoAttr !== null && demoAttr.toLowerCase() !== 'false';

    // Explicit `data-locale` wins; otherwise follow the host page's
    // `<html lang>`. Null when neither is present — the server then
    // falls back to the agent's default language.
    const locale =
        localeAttr !== null && localeAttr.trim() !== ''
            ? localeAttr.trim()
            : readPageLang();

    return { agentId, baseUrl, demo, shopperToken, locale };
}

const CAPTURED = captureFromScript();

// Track the live React mount points so unmount() can dispose them
// cleanly. Without this, removing #pitchbar-root from the DOM leaves
// React listeners and timers hanging in the page — fine for a tab
// that's about to navigate away, fragile for SPA marketing pages
// where the operator may toggle the widget on/off without a reload.
const MOUNTS: Array<{ container: HTMLDivElement; mountPoint: HTMLDivElement }> =
    [];

function bootstrap(host?: HTMLElement) {
    const target = host ?? document.body;
    const container = document.createElement('div');
    container.id = 'pitchbar-root';
    target.appendChild(container);

    const shadow = container.attachShadow({ mode: 'open' });
    const mountPoint = document.createElement('div');
    shadow.appendChild(mountPoint);

    render(
        h(App, {
            agentId: CAPTURED.agentId,
            baseUrl: CAPTURED.baseUrl,
            demo: CAPTURED.demo,
            shopperToken: CAPTURED.shopperToken,
            locale: CAPTURED.locale,
        }),
        mountPoint,
    );

    MOUNTS.push({ container, mountPoint });
}

function unmount() {
    // Dispose every live mount (almost always one) and any orphan
    // #pitchbar-root nodes that may have been injected by a previous
    // bundle. Preact's `render(null, root)` is the documented way to
    // unmount; afterwards we yank the container so the DOM is clean.
    while (MOUNTS.length > 0) {
        const entry = MOUNTS.pop();

        if (entry === undefined) {
            break;
        }

        try {
            render(null, entry.mountPoint);
        } catch {
            // Ignore — best-effort cleanup.
        }

        entry.container.remove();
    }

    document
        .querySelectorAll('#pitchbar-root')
        .forEach((node) => node.remove());
}

window.Pitchbar = { mount: bootstrap, unmount };

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => bootstrap());
} else {
    bootstrap();
}
