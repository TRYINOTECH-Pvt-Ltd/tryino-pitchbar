<?php

use App\Jobs\Crawl\CrawlPageJob;

/**
 * detectBlocker() is a private guard in CrawlPageJob that pre-2026-06
 * had an overly-broad bot-challenge regex. The standalone tokens
 * `cloudflare`, `attention required`, `access denied`, and `just a
 * moment` matched legitimate pages that mentioned Cloudflare as their
 * backend provider, used "Attention required" on form labels, or
 * shipped loading copy that read "Just a moment...". These tests pin
 * the tightened regex so a future relaxation can't regress us.
 *
 * Reflection is used because detectBlocker is correctly private —
 * exposing it just for tests would invite callers downstream. The
 * project already uses ReflectionMethod for private-method tests
 * elsewhere (tests/Feature/Wp/*, tests/Unit/Services/Parsers/*).
 */
function detect(string $title, string $text): ?string
{
    $job = new CrawlPageJob('source-id', 'https://example.com');
    $ref = new ReflectionMethod($job, 'detectBlocker');
    $ref->setAccessible(true);

    return $ref->invoke($job, $title, $text);
}

test('regression: page mentioning Cloudflare as backend is NOT flagged', function () {
    // Verbatim copy from a customer how-it-works page that previously
    // tripped the false-positive (17 legitimate Cloudflare mentions).
    $body = 'From URL to live sales agent in under 5 minutes. '
        .'Acme reads your site and builds an agent grounded in your content. '
        .'AI providers → Cloudflare (Workers AI + Vectorize), with OpenAI as fallback. '
        .'Crawled pages are extracted (Readability), chunked along semantic '
        .'boundaries (~500 tokens with overlap), embedded with Cloudflare '
        .'bge-base-en-v1.5 or OpenAI text-embedding-3-small. '
        .'Cloudflare Browser Rendering handles JS-heavy sites; Cloudflare Cron Worker '
        .'kicks off scheduled jobs. Default 0.5 threshold is tuned for '
        .'Cloudflare bge-base — raise to 0.78 if you switch to OpenAI embeddings.';

    expect(detect('How it works — Acme', $body))->toBeNull();
});

test('regression: page mentioning Cloudflare in a non-challenge context is NOT flagged', function () {
    $body = str_repeat('Cloudflare is our hosting provider. ', 10);

    expect(detect('Architecture', $body))->toBeNull();
});

test('positive: real Cloudflare challenge page IS flagged via title', function () {
    // CF emits this exact title on the JS-challenge interstitial.
    $title = 'Attention Required! | Cloudflare';
    $body = 'Please complete the security check to access this site.';

    expect(detect($title, $body))->toBe('Page is a bot-challenge / verification gate');
});

test('positive: CF challenge page IS flagged via cf-please-wait class', function () {
    $body = '<div class="cf-please-wait">Checking your browser before accessing example.com</div>';

    expect(detect('', $body))->toBe('Page is a bot-challenge / verification gate');
});

test('positive: CF challenge page IS flagged via cf-error-details class', function () {
    $body = '<section class="cf-error-details">Ray ID: 8a9b...</section>';

    expect(detect('', $body))->toBe('Page is a bot-challenge / verification gate');
});

test('positive: CF JS-challenge URL parameter IS flagged', function () {
    $body = 'Redirecting via __cf_chl_jschl=eyJ... to complete verification.';

    expect(detect('', $body))->toBe('Page is a bot-challenge / verification gate');
});

test('positive: "Verifying you are human" phrase IS flagged', function () {
    $body = 'Verifying you are human. This may take a few seconds.';

    expect(detect('', $body))->toBe('Page is a bot-challenge / verification gate');
});

test('positive: DDoS protection page IS flagged via Cloudflare-specific phrase', function () {
    // CF's "Under Attack" mode and Turnstile interstitial both emit this.
    $body = 'DDoS protection by Cloudflare. Performance & security by Cloudflare.';

    expect(detect('', $body))->toBe('Page is a bot-challenge / verification gate');
});

test('adversarial: standalone "attention required" form label is NOT flagged', function () {
    // Form validation copy uses "Attention required" — must not collide
    // with the CF challenge title "Attention Required! | Cloudflare".
    $body = 'Attention required: please fill in the email field to continue.';

    expect(detect('Sign up', $body))->toBeNull();
});

test('adversarial: standalone "access denied" copy is NOT flagged as bot-challenge', function () {
    // "Access denied" appears on legitimate login-required pages; the
    // login-wall regex is the correct match — bot-challenge must stay quiet.
    $body = 'Access denied. Please sign in to continue viewing this article.';

    // Login wall fires first (correct), not bot-challenge.
    expect(detect('Sign in', $body))->toBe('Page is behind a login wall');
});

test('adversarial: "just a moment" as loading copy is NOT flagged', function () {
    $body = 'Just a moment while we save your changes...';

    expect(detect('Profile', $body))->toBeNull();
});

test('existing login wall detection still fires on real login wall', function () {
    $body = 'Please sign in to continue. Login required to view this content.';

    expect(detect('Sign in', $body))->toBe('Page is behind a login wall');
});

test('existing paywall detection still fires on real paywall', function () {
    $body = 'Subscribe to read the rest of this article. Premium content for subscribers only.';

    expect(detect('Article', $body))->toBe('Page is behind a paywall');
});

test('existing JS-required detection still fires when crawler hit shell', function () {
    $body = 'JavaScript is required to use this site. Please enable JavaScript in your browser to continue.';

    expect(detect('App', $body))->toBe("Page requires JavaScript (the crawler couldn't render it)");
});

test('existing cookie-consent detection still fires on cookie wall', function () {
    $body = 'We use cookies to improve your experience. Cookie policy applies.';

    expect(detect('', $body))->toBe('Page is a cookie-consent gate');
});
