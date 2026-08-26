import {
    BookOpen,
    Building,
    Globe,
    LifeBuoy,
    Megaphone,
    ShoppingBag,
    Sparkles,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

// Slug union — kept in sync with VerticalPresets::SLUGS on the backend.
// Adding a slug here requires the corresponding preset class to exist
// server-side; otherwise the apply endpoint will reject it.
export type VerticalId =
    | 'ecommerce'
    | 'documentation'
    | 'saas'
    | 'help_center'
    | 'marketing'
    | 'internal_kb'
    | 'generic';

export type VerticalMeta = {
    id: VerticalId;
    name: string;
    shortDesc: string;
    icon: LucideIcon;
};

export const VERTICALS: VerticalMeta[] = [
    {
        id: 'ecommerce',
        name: 'E-commerce',
        shortDesc: 'Online store with products, pricing, and checkout',
        icon: ShoppingBag,
    },
    {
        id: 'documentation',
        name: 'Documentation',
        shortDesc: 'Technical docs, API references, and guides',
        icon: BookOpen,
    },
    {
        id: 'saas',
        name: 'SaaS',
        shortDesc: 'Software product with pricing, features, and signup',
        icon: Sparkles,
    },
    {
        id: 'help_center',
        name: 'Help center',
        shortDesc: 'Support articles, FAQs, and ticket triage',
        icon: LifeBuoy,
    },
    {
        id: 'marketing',
        name: 'Marketing site',
        shortDesc: 'Lead-capture pages, blog, and top-of-funnel content',
        icon: Megaphone,
    },
    {
        id: 'internal_kb',
        name: 'Internal KB',
        shortDesc: 'Employee wiki, runbooks, and internal documentation',
        icon: Building,
    },
    {
        id: 'generic',
        name: 'Generic',
        shortDesc: "I'll configure this myself later",
        icon: Globe,
    },
];

export const VERTICAL_BY_ID: Record<VerticalId, VerticalMeta> =
    Object.fromEntries(VERTICALS.map((v) => [v.id, v])) as Record<
        VerticalId,
        VerticalMeta
    >;

export type Confidence = 'high' | 'likely' | 'guess';

// Thresholds calibrated against real-world detector output. Even a clean
// match (e.g. docusaurus.io → documentation, vercel.com → saas) tops out
// near 0.5 because few sites stack 3+ strong signals on the homepage.
// Treating 0.5+ as "high" matches user intuition: the detector is
// confident; treating 0.3+ as "likely" so the amber pill fires for
// borderline-but-correct guesses (help.shopify.com, stripe.com).
export function confidenceBucket(score: number): Confidence {
    if (score >= 0.5) {
        return 'high';
    }

    if (score >= 0.3) {
        return 'likely';
    }

    return 'guess';
}

export const CONFIDENCE_LABEL: Record<Confidence, string> = {
    high: 'High confidence',
    likely: 'Likely',
    guess: 'Best guess',
};

export type DetectionResult = {
    type: VerticalId;
    confidence: number;
    alternatives: { type: VerticalId; confidence: number }[];
    signals: string[];
};

// True when a slug looks like one of the known verticals — useful for
// validating server payloads before passing them to UI helpers.
export function isVerticalId(value: unknown): value is VerticalId {
    return typeof value === 'string' && VERTICALS.some((v) => v.id === value);
}
