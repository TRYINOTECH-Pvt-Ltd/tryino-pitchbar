import type { VerticalId } from '@/lib/verticals';

/**
 * Page-context templates the admin can drop into the playground to
 * simulate "the visitor is on a Shopify product page" or "on the Stripe
 * pricing page". Mirrors the shape MessageStreamController sanitizes
 * (url, title, description, og, twitter, json_ld, h1, h2, visible_text)
 * PLUS the WordPress companion plugin's CMS extension fields (source,
 * post_id, post_type, permalink, categories, tags, woo). The playground
 * rehearses both the heuristic-DOM and the CMS-authoritative paths so a
 * WP/Woo admin can sanity-check their agent before installing the plugin
 * on a real site.
 */
export type WooContextPayload = {
    id?: number;
    sku?: string;
    name?: string;
    permalink?: string;
    price?: string;
    regular_price?: string;
    sale_price?: string;
    currency?: string;
    stock_status?: string;
    on_sale?: boolean;
};

export type PageContextPayload = {
    url?: string;
    title?: string;
    description?: string;
    og?: Record<string, string | number>;
    twitter?: Record<string, string>;
    json_ld?: unknown;
    h1?: string;
    h2?: string[];
    visible_text?: string;
    // WordPress companion plugin extensions. Matches `PageContext::collect()`
    // on the plugin side. All optional; older clients keep working.
    source?: 'wordpress';
    site_url?: string;
    page_url?: string;
    page_title?: string;
    post_id?: number;
    post_type?: string;
    permalink?: string;
    categories?: string[];
    tags?: string[];
    woo?: WooContextPayload;
};

/**
 * Sandbox-only WordPress shopper attestation. The playground sends
 * this to PlaygroundStreamController, which mirrors what
 * InitController writes when the WP plugin's real signed
 * `data-shopper-token` verifies on a live page. Lets an admin
 * rehearse a logged-in-WooCommerce-customer turn (including the
 * lookup_order tool path) without standing up a real WP install.
 *
 * Never used to authenticate real visitors; the route is already
 * gated by `can('update', $agent)`.
 */
export type ShopperSimulation = {
    enabled: boolean;
    wp_user_id: number | null;
    email: string;
};

export type PageTemplate = {
    id: string;
    label: string;
    vertical: VerticalId;
    description: string;
    payload: PageContextPayload;
};

export const PAGE_TEMPLATES: PageTemplate[] = [
    {
        id: 'blank',
        label: 'No page context',
        vertical: 'generic',
        description:
            'Visitor has no page metadata — same baseline as not sending any context.',
        payload: {},
    },
    {
        id: 'shopify-product',
        label: 'Shopify product page',
        vertical: 'ecommerce',
        description:
            'Product detail page with og:type=product, JSON-LD Product, and a buy CTA.',
        payload: {
            url: 'https://shop.example.com/products/red-canvas-tote',
            title: 'Red canvas tote — Acme Goods',
            description:
                'Heavy-duty 16oz canvas tote, reinforced stitching, fits a 15" laptop.',
            og: {
                type: 'product',
                site_name: 'Acme Goods',
                'price:amount': '49.00',
                'price:currency': 'USD',
                availability: 'in stock',
                brand: 'Acme',
            },
            json_ld: {
                '@context': 'https://schema.org',
                '@type': 'Product',
                name: 'Red canvas tote',
                offers: {
                    '@type': 'Offer',
                    price: '49.00',
                    priceCurrency: 'USD',
                    availability: 'https://schema.org/InStock',
                },
            },
            h1: 'Red canvas tote',
            h2: ['Specs', 'Shipping', 'Reviews'],
            visible_text:
                'Built for the long haul. 16oz cotton canvas, double-stitched seams. ' +
                'Free shipping on orders over $75. 30-day returns.',
        },
    },
    {
        id: 'stripe-pricing',
        label: 'SaaS pricing page',
        vertical: 'saas',
        description:
            'Three-plan pricing comparison with Offer JSON-LD and a "Start free trial" CTA.',
        payload: {
            url: 'https://app.example.com/pricing',
            title: 'Pricing — ExampleApp',
            description:
                'Choose a plan that fits your team. Start free, upgrade anytime.',
            og: {
                type: 'website',
                site_name: 'ExampleApp',
            },
            json_ld: {
                '@context': 'https://schema.org',
                '@type': 'Product',
                name: 'ExampleApp',
                offers: [
                    { '@type': 'Offer', name: 'Starter', price: '0' },
                    { '@type': 'Offer', name: 'Pro', price: '29' },
                    { '@type': 'Offer', name: 'Team', price: '99' },
                ],
            },
            h1: 'Pricing',
            h2: ['Starter', 'Pro', 'Team', 'Frequently asked questions'],
            visible_text:
                'Starter — Free forever. Pro — $29/mo, billed annually. ' +
                'Team — $99/mo, billed annually. All plans include unlimited projects.',
        },
    },
    {
        id: 'docusaurus-page',
        label: 'Docs / API reference page',
        vertical: 'documentation',
        description:
            'Documentation site with code blocks, og:type=article, and a Generator: Docusaurus signal.',
        payload: {
            url: 'https://docs.example.com/api/auth',
            title: 'Authentication — ExampleApp Docs',
            description:
                'Auth API endpoints, request/response shapes, and code samples.',
            og: { type: 'article', site_name: 'ExampleApp Docs' },
            h1: 'Authentication',
            h2: [
                'Issuing a token',
                'Refreshing a token',
                'Revoking a token',
                'Errors',
            ],
            visible_text:
                'Issue a token with POST /v1/auth/token. Send your client_id and ' +
                'client_secret in the body. The response includes an access_token, ' +
                'refresh_token, and an expires_in field in seconds.',
        },
    },
    {
        id: 'help-article',
        label: 'Help center article',
        vertical: 'help_center',
        description:
            'Support article shaped like Intercom / Zendesk, with FAQPage JSON-LD.',
        payload: {
            url: 'https://help.example.com/articles/reset-password',
            title: 'How to reset your password — ExampleApp Help',
            description:
                "Steps to reset your password if you can't sign in or want to rotate it.",
            og: { type: 'article', site_name: 'ExampleApp Help' },
            json_ld: {
                '@context': 'https://schema.org',
                '@type': 'FAQPage',
                mainEntity: [
                    {
                        '@type': 'Question',
                        name: 'I forgot my password',
                        acceptedAnswer: {
                            '@type': 'Answer',
                            text: 'Click "Forgot password" on the sign-in page.',
                        },
                    },
                ],
            },
            h1: 'How to reset your password',
            h2: [
                'If you can sign in',
                "If you can't sign in",
                'Troubleshooting',
            ],
            visible_text:
                'If you can sign in, open Settings → Security → Change password. ' +
                'If you can\'t sign in, click "Forgot password" on the login screen ' +
                'and follow the email link.',
        },
    },
    {
        id: 'wordpress-post',
        label: 'WordPress post / page',
        vertical: 'marketing',
        description:
            'Mirrors what the Pitchbar WordPress plugin sends for a regular WP post — `source: "wordpress"`, post_id, post_type, taxonomy, plus the same og/json_ld a theme would emit.',
        payload: {
            source: 'wordpress',
            site_url: 'https://shop.example.com/',
            page_url:
                'https://shop.example.com/blog/how-to-style-a-canvas-tote',
            page_title: 'How to style a canvas tote — Acme Goods',
            url: 'https://shop.example.com/blog/how-to-style-a-canvas-tote',
            title: 'How to style a canvas tote — Acme Goods',
            description:
                'Five outfit pairings for a 16oz canvas tote. Weekend, work, travel, gym, errands.',
            permalink:
                'https://shop.example.com/blog/how-to-style-a-canvas-tote',
            post_id: 142,
            post_type: 'post',
            categories: ['style-guides', 'totes'],
            tags: ['canvas', 'summer'],
            og: { type: 'article', site_name: 'Acme Goods' },
            h1: 'How to style a canvas tote',
            h2: ['Weekend', 'Work', 'Travel', 'Gym', 'Errands'],
            visible_text:
                'A 16oz canvas tote works for almost any outing — pair it with a ' +
                'linen shirt and slip-ons for weekend errands, or dress it up with a ' +
                'blazer for casual-Friday at the office. Free shipping on orders over $75.',
        },
    },
    {
        id: 'wordpress-woo-product',
        label: 'WooCommerce product page',
        vertical: 'ecommerce',
        description:
            'Exact payload the WP plugin emits on `is_product()` — nested `woo` object with id/sku/price/currency/stock_status, plus the regular WP fields and Product JSON-LD. Use to rehearse <product/> emission + the lookup_order tool.',
        payload: {
            source: 'wordpress',
            site_url: 'https://shop.example.com/',
            page_url: 'https://shop.example.com/product/blue-tee',
            page_title: 'Blue tee — Acme Goods',
            url: 'https://shop.example.com/product/blue-tee',
            title: 'Blue tee — Acme Goods',
            description:
                '100% combed ring-spun cotton, double-stitched seams, classic crew neck. Sizes XS–XXL.',
            permalink: 'https://shop.example.com/product/blue-tee',
            post_id: 9001,
            post_type: 'product',
            categories: ['tees', 'summer'],
            tags: [],
            og: {
                type: 'product',
                site_name: 'Acme Goods',
                'price:amount': '29.00',
                'price:currency': 'USD',
                availability: 'in stock',
            },
            json_ld: {
                '@context': 'https://schema.org',
                '@type': 'Product',
                name: 'Blue tee',
                sku: 'T-BLU-M',
                offers: {
                    '@type': 'Offer',
                    price: '29.00',
                    priceCurrency: 'USD',
                    availability: 'https://schema.org/InStock',
                },
            },
            woo: {
                id: 9001,
                sku: 'T-BLU-M',
                name: 'Blue tee',
                permalink: 'https://shop.example.com/product/blue-tee',
                price: '29.00',
                regular_price: '39.00',
                sale_price: '29.00',
                currency: 'USD',
                stock_status: 'instock',
                on_sale: true,
            },
            h1: 'Blue tee',
            h2: ['Description', 'Sizing', 'Reviews'],
            visible_text:
                '100% combed ring-spun cotton, double-stitched seams, classic crew neck. ' +
                'Sizes XS–XXL. On sale from $39 to $29. Free shipping over $75. 30-day returns.',
        },
    },
    {
        id: 'marketing-landing',
        label: 'Marketing landing page',
        vertical: 'marketing',
        description:
            'Top-of-funnel landing page with a "Book a demo" CTA and a testimonial section.',
        payload: {
            url: 'https://example.com/',
            title: 'ExampleApp — The fastest way to ship',
            description:
                'Teams of every size use ExampleApp to ship features faster.',
            og: { type: 'website', site_name: 'ExampleApp' },
            h1: 'Ship features faster.',
            h2: [
                'Trusted by teams everywhere',
                'Built for engineers',
                'Frequently asked questions',
                'Book a demo',
            ],
            visible_text:
                'Loved by 10,000+ teams. ExampleApp gives you the tools to ship ' +
                'twice as fast. Book a demo or start your free trial today.',
        },
    },
];

/**
 * Sample buyer-realistic prompts per vertical. Rendered as clickable
 * chips so the admin can fire a typical question without typing.
 *
 * Generic gets a "warm-up" set that works on any agent regardless of
 * site type — useful for the very first manual smoke test.
 */
export const SAMPLE_PROMPTS: Record<VerticalId, string[]> = {
    ecommerce: [
        "What's the price of this?",
        'Do you ship internationally?',
        "What's your return policy?",
        "Recommend a product for my partner's birthday",
        'Is this in stock right now?',
    ],
    documentation: [
        'How do I install this?',
        'Show me an authentication example',
        'What does the rate-limit error look like?',
        'How do I refresh an access token?',
        'Where is the changelog?',
    ],
    saas: [
        'Show me your pricing',
        'Whats the difference between Pro and Team?',
        'Can I get a demo?',
        'Do you have a free trial?',
        'How do you compare to {Competitor}?',
    ],
    help_center: [
        'I forgot my password',
        "I can't sign in — please help",
        'Where are my invoices?',
        'How do I cancel my subscription?',
        'Connect me with a human',
    ],
    marketing: [
        'What does ExampleApp do?',
        'Who is this for?',
        'Show me a customer success story',
        'How long does setup take?',
        'Can I book a demo?',
    ],
    internal_kb: [
        'What is our PTO policy?',
        'How do I request access to a Notion page?',
        'Where is the on-call runbook?',
        'Who owns the billing pipeline?',
    ],
    generic: [
        'What is this site about?',
        'How can you help me?',
        'What can you do?',
        "Tell me what you've been trained on",
    ],
};

/**
 * Look up a template by id. Returns the blank template when the id is
 * unknown — keeps the form non-empty when the dropdown lands in a weird
 * state during fast clicks.
 */
export function pageTemplateById(id: string): PageTemplate {
    return PAGE_TEMPLATES.find((t) => t.id === id) ?? PAGE_TEMPLATES[0]!;
}
