/**
 * Protocol allowlist for LLM-emitted URLs that render into `<a href>`,
 * `<img src>`, or `window.open`. Returns null on anything outside the
 * allowlist (callers fall back to a safe placeholder).
 *
 * Relative URLs are accepted — they resolve against the widget's host
 * page, which the workspace owner controls.
 */

const ALLOWED_PROTOCOLS = new Set(['http:', 'https:', 'mailto:', 'tel:']);

const IMAGE_PROTOCOLS = new Set(['http:', 'https:']);

function parseUrl(raw: string): URL | null {
    try {
        // Absolute URL — parses without base.
        return new URL(raw);
    } catch {
        try {
            // Relative URL — needs a base to parse. Use the widget's
            // host page as base; we'll only inspect protocol below.
            return new URL(raw, window.location.origin);
        } catch {
            return null;
        }
    }
}

export function safeHref(raw: string | undefined | null): string | null {
    if (typeof raw !== 'string') {
        return null;
    }

    const trimmed = raw.trim();

    if (trimmed === '' || trimmed === '#') {
        return null;
    }

    const parsed = parseUrl(trimmed);

    if (parsed === null) {
        return null;
    }

    if (!ALLOWED_PROTOCOLS.has(parsed.protocol)) {
        return null;
    }

    return trimmed;
}

export function safeImageSrc(raw: string | undefined | null): string | null {
    if (typeof raw !== 'string') {
        return null;
    }

    const trimmed = raw.trim();

    if (trimmed === '') {
        return null;
    }

    const parsed = parseUrl(trimmed);

    if (parsed === null) {
        return null;
    }

    // Tighter for <img src>: no mailto:/tel:/javascript: ever.
    if (!IMAGE_PROTOCOLS.has(parsed.protocol)) {
        return null;
    }

    return trimmed;
}
