/**
 * Reads the page the visitor is currently looking at and packages it into
 * a small JSON envelope the server can feed straight into the RAG prompt.
 *
 * The whole point: every modern website exposes the same three things
 *   1. <title> + <meta name="description">
 *   2. Open Graph + Twitter card tags (Facebook/Google enforce them)
 *   3. JSON-LD structured data (Schema.org — Google rich snippets)
 *
 * Plus the rendered DOM. So we can describe ANY page (Shopify product,
 * WordPress blog post, custom React app, news article) without site-
 * specific code, just by reading what's already there.
 *
 * Budget: aim for <= 4KB total. Truncate aggressively — the LLM doesn't
 * need the full page, it needs enough signal to know what page is open.
 */

export type PageContext = {
    url: string;
    title: string | null;
    description: string | null;
    og: Record<string, string>;
    twitter: Record<string, string>;
    json_ld: unknown[];
    h1: string | null;
    h2: string[];
    visible_text: string | null;
    /**
     * Optional CMS-provided context blob. Populated by adapters like the
     * Pitchbar WordPress plugin via `<script data-page-context="…">`.
     * Server-side retrieval prefers fields here over the heuristic DOM
     * scrape because the CMS knows authoritative values (post id, post
     * type, taxonomies, Woo product fields). Absent for raw HTML sites.
     */
    cms: Record<string, unknown> | null;
};

const MAX_CMS_BYTES = 8192;

const MAX_VISIBLE_TEXT = 2000;
const MAX_META_VALUE = 500;
const MAX_JSON_LD_PER_BLOCK = 4000;
const MAX_JSON_LD_BLOCKS = 4;
const MAX_H2 = 6;

/**
 * Read the current document. Pure — pass in a custom Document for tests.
 */
export function extractPageContext(doc: Document = document): PageContext {
    return {
        url: doc.location?.href ?? '',
        title: cap(
            doc.title || metaContent(doc, 'og:title') || null,
            MAX_META_VALUE,
        ),
        description: cap(
            metaContent(doc, 'description') ||
                metaContent(doc, 'og:description') ||
                null,
            MAX_META_VALUE,
        ),
        og: collectMeta(doc, 'og:'),
        twitter: collectMeta(doc, 'twitter:'),
        json_ld: collectJsonLd(doc),
        h1: cap(textOf(doc.querySelector('h1')), MAX_META_VALUE),
        h2: collectH2(doc),
        visible_text: collectVisibleText(doc),
        cms: extractCmsContext(doc),
    };
}

/**
 * Read an optional `data-page-context` JSON blob attached to the widget
 * loader script tag. CMS adapters (Pitchbar WordPress plugin, future
 * Shopify app, etc.) emit this so we don't have to guess post type,
 * taxonomies, or product fields from the DOM.
 *
 * Returns null when the attribute is absent, empty, oversized, or not
 * valid JSON — the heuristic context still flows in those cases.
 */
export function extractCmsContext(
    doc: Document = document,
): Record<string, unknown> | null {
    const scripts = doc.querySelectorAll(
        'script[data-agent][data-page-context], script[data-agent-id][data-page-context]',
    );

    for (const script of Array.from(scripts)) {
        const raw = script.getAttribute('data-page-context');

        if (raw === null || raw === '' || raw.length > MAX_CMS_BYTES) {
            continue;
        }

        try {
            const parsed = JSON.parse(raw);

            if (parsed !== null && typeof parsed === 'object') {
                return parsed as Record<string, unknown>;
            }
        } catch {
            // malformed JSON — fall through to next script tag
        }
    }

    return null;
}

function cap(value: string | null, max: number): string | null {
    if (value === null) {
        return null;
    }

    const trimmed = value.replace(/\s+/g, ' ').trim();

    if (trimmed === '') {
        return null;
    }

    return trimmed.length > max ? trimmed.slice(0, max) : trimmed;
}

function textOf(el: Element | null): string | null {
    if (el === null) {
        return null;
    }

    return el.textContent?.trim() ?? null;
}

function metaContent(doc: Document, key: string): string | null {
    // Try name= first, then property= (OG uses property, descriptions use name)
    const byName = doc.querySelector(`meta[name="${cssEscape(key)}"]`);

    if (byName) {
        return byName.getAttribute('content');
    }

    const byProp = doc.querySelector(`meta[property="${cssEscape(key)}"]`);

    return byProp ? byProp.getAttribute('content') : null;
}

/**
 * Collect every meta tag whose name/property starts with a prefix
 * (e.g. "og:" or "twitter:") into a flat key→value map. Strips the
 * prefix from the key so {og:title, og:image} → {title, image}.
 */
function collectMeta(doc: Document, prefix: string): Record<string, string> {
    const out: Record<string, string> = {};
    const nodes = doc.querySelectorAll(
        `meta[name^="${cssEscape(prefix)}"], meta[property^="${cssEscape(prefix)}"]`,
    );
    nodes.forEach((node) => {
        const raw =
            node.getAttribute('property') ?? node.getAttribute('name') ?? '';
        const content = node.getAttribute('content') ?? '';

        if (raw === '' || content === '') {
            return;
        }

        const key = raw.slice(prefix.length);

        if (key === '' || out[key] !== undefined) {
            return;
        }

        const capped = cap(content, MAX_META_VALUE);

        if (capped !== null) {
            out[key] = capped;
        }
    });

    return out;
}

/**
 * Parse every <script type="application/ld+json"> block, dropping ones
 * that are too big or invalid. Limit total blocks so a page with many
 * Recipe cards doesn't blow our budget.
 */
function collectJsonLd(doc: Document): unknown[] {
    const out: unknown[] = [];
    const scripts = doc.querySelectorAll('script[type="application/ld+json"]');

    for (const script of Array.from(scripts)) {
        if (out.length >= MAX_JSON_LD_BLOCKS) {
            break;
        }

        const raw = script.textContent ?? '';

        if (raw.length === 0 || raw.length > MAX_JSON_LD_PER_BLOCK) {
            continue;
        }

        try {
            const parsed = JSON.parse(raw);
            out.push(parsed);
        } catch {
            // malformed JSON-LD — skip silently
        }
    }

    return out;
}

function collectH2(doc: Document): string[] {
    const out: string[] = [];
    const nodes = doc.querySelectorAll('h2');
    nodes.forEach((node) => {
        if (out.length >= MAX_H2) {
            return;
        }

        const txt = cap(node.textContent ?? null, MAX_META_VALUE);

        if (txt !== null) {
            out.push(txt);
        }
    });

    return out;
}

/**
 * Pull a representative chunk of visible body text, ignoring chrome
 * (nav/header/footer/aside) and scripts/styles. We don't need the full
 * page — the first ~2KB of "real content" is enough for the LLM to
 * understand what page the visitor is on.
 */
function collectVisibleText(doc: Document): string | null {
    const root = doc.querySelector('main, article, [role="main"]') ?? doc.body;

    if (!root) {
        return null;
    }

    const clone = root.cloneNode(true) as Element;
    // Drop chrome/script/style nodes from the clone.
    clone
        .querySelectorAll(
            'nav, header, footer, aside, script, style, noscript, svg, iframe',
        )
        .forEach((n) => n.remove());

    const text = (clone.textContent ?? '').replace(/\s+/g, ' ').trim();

    if (text === '') {
        return null;
    }

    return text.length > MAX_VISIBLE_TEXT
        ? text.slice(0, MAX_VISIBLE_TEXT)
        : text;
}

/**
 * Native CSS.escape isn't on every browser the widget targets (we still
 * see IE11-era polyfills in the wild). For our colon-prefixed selectors
 * this minimal escape is enough.
 */
function cssEscape(value: string): string {
    return value.replace(/(["\\])/g, '\\$1');
}
