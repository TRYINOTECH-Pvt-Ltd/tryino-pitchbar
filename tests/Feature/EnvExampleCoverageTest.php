<?php

/**
 * Cheap drift check: every env key the app actually uses must be documented
 * in .env.example so new contributors / production deploys see what to set.
 *
 * If you add a new env key to config/services.php (or anywhere referenced via
 * env('FOO_BAR')) and forget the example file, this test fails.
 */
test('.env.example documents every config-referenced env key we ship', function () {
    $required = [
        // RAG / crawler tuning
        'RAG_CONFIDENCE_THRESHOLD',
        'RAG_RERANK_ENABLED',
        'CRAWL_MAX_PAGES_PER_SOURCE',

        // OAuth integrations
        'NOTION_CLIENT_ID',
        'NOTION_CLIENT_SECRET',
        'NOTION_REDIRECT_URI',
        'GOOGLE_CLIENT_ID',
        'GOOGLE_CLIENT_SECRET',
        'GOOGLE_REDIRECT_URI',

        // Marketing site
        'MARKETING_DEMO_AGENT_ID',

        // Cloudflare (already there but confirms no regression)
        'CLOUDFLARE_ACCOUNT_ID',
        'CLOUDFLARE_API_TOKEN',
        'CLOUDFLARE_VECTORIZE_INDEX',

        // Reverb (live takeover relies on these)
        'REVERB_APP_KEY',
        'REVERB_HOST',
        'REVERB_PORT',
        'REVERB_SCHEME',

        // Widget JWT
        'WIDGET_JWT_SECRET',
    ];

    $envExample = file_get_contents(base_path('.env.example'));
    expect($envExample)->not->toBeFalse();

    foreach ($required as $key) {
        expect(str_contains((string) $envExample, $key))
            ->toBeTrue("Missing {$key} from .env.example");
    }
});
