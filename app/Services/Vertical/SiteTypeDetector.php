<?php

namespace App\Services\Vertical;

/**
 * Heuristic vertical classifier. Reads the structured signals produced by
 * MetadataExtractor and picks the vertical with the highest weighted
 * score. Returns the alternatives so the admin UI can render a "or pick
 * one of these" affordance.
 *
 * Rule-based on purpose. We need explainable signals (so the UI can
 * show the admin WHY we guessed e-commerce) and a deterministic output
 * across reruns. A learned model might score higher in aggregate but
 * loses both properties.
 */
class SiteTypeDetector
{
    /**
     * Score below which we don't trust any guess. Forces a `generic`
     * fallback rather than presenting a low-confidence wrong answer.
     */
    private const MIN_CONFIDENCE_FLOOR = 0.25;

    public function __construct(private MetadataExtractor $metadata) {}

    /**
     * @return array{
     *   type: string,
     *   confidence: float,
     *   alternatives: array<int, array{type: string, confidence: float}>,
     *   signals: array<int, string>,
     * }
     */
    public function detect(string $html, string $url): array
    {
        $meta = $this->metadata->extract($html, $url);

        // scores keyed by vertical slug
        $scores = [
            'ecommerce' => 0.0,
            'documentation' => 0.0,
            'saas' => 0.0,
            'help_center' => 0.0,
            'marketing' => 0.0,
            'internal_kb' => 0.0,
        ];
        $signals = [];

        $ogType = strtolower((string) ($meta['og']['type'] ?? ''));
        $generator = strtolower((string) ($meta['generator'] ?? ''));
        $jsonLd = array_map(static fn ($t) => strtolower((string) $t), $meta['json_ld_types']);
        $path = strtolower($meta['url_path']);
        $host = $meta['url_host'];
        $navLower = array_map('strtolower', $meta['nav_links']);

        // ── E-COMMERCE ─────────────────────────────────────────────
        // SaaS sites often publish JSON-LD `Product`/`Offer` to advertise
        // their software in shopping channels. If we ALSO see strong saas
        // signals (SoftwareApplication / WebApplication / dev subdomain),
        // the Product is software-as-product, not goods. Demote ecommerce
        // unless we have a real commerce signal (cart/checkout path,
        // Shopify/WooCommerce generator, or og:type=product).
        $hasSaasJsonLd = $this->jsonLdMatches($jsonLd, ['softwareapplication', 'webapplication']);
        $hasCommerceTell = ($ogType === 'product')
            || $this->pathMatches($path, ['/products', '/product/', '/shop', '/cart', '/checkout', '/store'])
            || ($generator !== '' && (str_contains($generator, 'shopify') || str_contains($generator, 'woocommerce')));

        if ($ogType === 'product') {
            $scores['ecommerce'] += 0.45;
            $signals[] = 'og:type=product';
        }
        if ($this->jsonLdMatches($jsonLd, ['product', 'offer', 'aggregateoffer'])) {
            // Software-as-Product on a SaaS site shouldn't tip ecommerce.
            if ($hasSaasJsonLd && ! $hasCommerceTell) {
                $signals[] = 'json_ld:Product/Offer (suppressed — software product)';
            } else {
                $scores['ecommerce'] += 0.40;
                $signals[] = 'json_ld:Product/Offer';
            }
        }
        if ($this->pathMatches($path, ['/products', '/product/', '/shop', '/cart', '/checkout', '/store'])) {
            $scores['ecommerce'] += 0.20;
            $signals[] = 'url_path:commerce';
        }
        if ($generator !== '' && str_contains($generator, 'shopify')) {
            $scores['ecommerce'] += 0.40;
            $signals[] = 'generator:shopify';
        }
        if ($generator !== '' && str_contains($generator, 'woocommerce')) {
            $scores['ecommerce'] += 0.30;
            $signals[] = 'generator:woocommerce';
        }

        // ── DOCUMENTATION ──────────────────────────────────────────
        if ($this->jsonLdMatches($jsonLd, ['techarticle', 'apireference'])) {
            $scores['documentation'] += 0.40;
            $signals[] = 'json_ld:TechArticle/APIReference';
        }
        if ($this->pathMatches($path, ['/docs', '/documentation', '/api/', '/reference', '/sdk', '/guide'])) {
            $scores['documentation'] += 0.30;
            $signals[] = 'url_path:docs';
        }
        if ($meta['has_code_blocks']) {
            $scores['documentation'] += 0.20;
            $signals[] = 'has:code_blocks';
        }
        $docGenerators = ['docusaurus', 'mkdocs', 'vitepress', 'mintlify', 'fumadocs', 'sphinx', 'gitbook', 'docsy'];
        foreach ($docGenerators as $g) {
            if ($generator !== '' && str_contains($generator, $g)) {
                $scores['documentation'] += 0.50;
                $signals[] = 'generator:'.$g;
                break;
            }
        }

        // ── SAAS ───────────────────────────────────────────────────
        if ($this->jsonLdMatches($jsonLd, ['softwareapplication', 'webapplication'])) {
            $scores['saas'] += 0.40;
            $signals[] = 'json_ld:SoftwareApplication';
        }
        if ($host !== '' && (str_starts_with($host, 'app.') || str_starts_with($host, 'dashboard.'))) {
            $scores['saas'] += 0.20;
            $signals[] = 'host:saas_subdomain';
        }
        // SaaS marketing pages typically combine pricing + signup CTA in
        // the nav. A single nav term is too weak to be conclusive, but
        // both together is a stronger signal — most marketing sites have
        // ONE of these, SaaS pages have BOTH.
        $hasPricing = $this->navMentionsAny($navLower, ['pricing']);
        $hasSignup = $this->navMentionsAny($navLower, ['sign up', 'signup', 'get started', 'start free', 'try free', 'start trial']);
        if ($hasPricing && $hasSignup) {
            $scores['saas'] += 0.30;
            $signals[] = 'nav:saas_pricing+signup';
        } elseif ($this->navMentionsAny($navLower, ['pricing', 'features', 'login', 'log in', 'sign up', 'signup'])) {
            $scores['saas'] += 0.10;
            $signals[] = 'nav:saas_links';
        }
        // Developer-platform / API-product cues. These score saas because
        // the agent should treat the visitor as evaluating a product, not
        // browsing a blog or shopping for goods.
        if ($host !== '' && (str_starts_with($host, 'developers.') || str_starts_with($host, 'api.'))) {
            $scores['saas'] += 0.15;
            $signals[] = 'host:developer_subdomain';
        }

        // ── HELP CENTER ────────────────────────────────────────────
        // Subdomain-based signal: real help centers often live at
        // help.foo.com, support.foo.com, kb.foo.com, knowledge.foo.com —
        // the help signal is in the HOST, not the path. Without this,
        // help.shopify.com falls through to generic.
        $helpSubdomains = ['help.', 'support.', 'kb.', 'knowledge.', 'docs.', 'faq.'];
        $isHelpHost = false;
        foreach ($helpSubdomains as $sub) {
            if ($host !== '' && str_starts_with($host, $sub) && $sub !== 'docs.') {
                $isHelpHost = true;
                $scores['help_center'] += 0.35;
                $signals[] = 'host:help_subdomain';
                break;
            }
        }
        if ($this->pathMatches($path, ['/help', '/support', '/kb', '/knowledge-base']) && $meta['has_article_tag']) {
            $scores['help_center'] += 0.35;
            $signals[] = 'url_path:help+article';
        }
        if ($isHelpHost && $meta['has_article_tag']) {
            $scores['help_center'] += 0.10;
            $signals[] = 'host:help_subdomain+article';
        }
        if ($this->jsonLdMatches($jsonLd, ['faqpage'])) {
            $scores['help_center'] += 0.30;
            $signals[] = 'json_ld:FAQPage';
        }
        if ($generator !== '' && (str_contains($generator, 'zendesk') || str_contains($generator, 'helpscout') || str_contains($generator, 'intercom'))) {
            $scores['help_center'] += 0.40;
            $signals[] = 'generator:help_platform';
        }

        // ── MARKETING ──────────────────────────────────────────────
        $hasCommerceSignal = $scores['ecommerce'] > 0.0;
        if ($ogType === 'website' && $this->navMentionsAny($navLower, ['pricing', 'features', 'sign up', 'signup'])) {
            $scores['marketing'] += 0.25;
            $signals[] = 'og:website+marketing_nav';
        }
        if (! $hasCommerceSignal && $this->jsonLdMatches($jsonLd, ['article', 'blogposting', 'newsarticle'])) {
            $scores['marketing'] += 0.20;
            $signals[] = 'json_ld:Article';
        }
        if (! $hasCommerceSignal && $generator !== '' && str_contains($generator, 'wordpress')) {
            $scores['marketing'] += 0.15;
            $signals[] = 'generator:wordpress';
        }

        // ── INTERNAL KB ────────────────────────────────────────────
        // Hostnames that look private / non-public.
        if ($host !== '' && ($this->endsWithAny($host, ['.local', '.internal', '.lan']) || ! $this->looksPublicDomain($host))) {
            $scores['internal_kb'] += 0.40;
            $signals[] = 'host:private';
        }

        // Pick the winner.
        arsort($scores);
        $top = array_key_first($scores);
        $topScore = $scores[$top];

        if ($topScore < self::MIN_CONFIDENCE_FLOOR) {
            return [
                'type' => 'generic',
                'confidence' => 0.0,
                'alternatives' => [],
                'signals' => $signals,
            ];
        }

        $alternatives = [];
        foreach ($scores as $slug => $score) {
            if ($slug === $top || $score <= 0.0) {
                continue;
            }
            $alternatives[] = ['type' => $slug, 'confidence' => round(min(1.0, $score), 2)];
            if (count($alternatives) >= 3) {
                break;
            }
        }

        return [
            'type' => $top,
            'confidence' => round(min(1.0, $topScore), 2),
            'alternatives' => $alternatives,
            'signals' => $signals,
        ];
    }

    /**
     * @param  array<int, string>  $jsonLd
     * @param  array<int, string>  $needles
     */
    private function jsonLdMatches(array $jsonLd, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (in_array($needle, $jsonLd, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function pathMatches(string $path, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($path, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $navLower
     * @param  array<int, string>  $needles
     */
    private function navMentionsAny(array $navLower, array $needles): bool
    {
        foreach ($navLower as $link) {
            foreach ($needles as $needle) {
                if (str_contains($link, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $suffixes
     */
    private function endsWithAny(string $haystack, array $suffixes): bool
    {
        foreach ($suffixes as $suffix) {
            if (str_ends_with($haystack, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Crude heuristic — public domains have a public TLD with a real
     * registered name. A bare hostname like "wiki" or "intranet"
     * (no dot) suggests internal.
     */
    private function looksPublicDomain(string $host): bool
    {
        return str_contains($host, '.') && ! str_ends_with($host, '.localhost');
    }
}
