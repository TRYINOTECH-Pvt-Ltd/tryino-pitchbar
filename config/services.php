<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // PayPal — REST API client credentials. `mode` flips between
    // sandbox (`sandbox.paypal.com`) and live (`api-m.paypal.com`).
    // Webhook id used to verify event signatures via the verify-signature
    // endpoint. AppSettingsOverrideServiceProvider reads from app_settings
    // and merges into config() at boot, so the admin UI can rotate
    // credentials without a deploy.
    'paypal' => [
        'enabled' => (bool) env('PAYPAL_ENABLED', false),
        'mode' => env('PAYPAL_MODE', 'sandbox'),
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'client_secret' => env('PAYPAL_CLIENT_SECRET'),
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
    ],

    // Razorpay — HTTP Basic auth + HMAC-SHA256 webhook signatures.
    'razorpay' => [
        'enabled' => (bool) env('RAZORPAY_ENABLED', false),
        'key_id' => env('RAZORPAY_API_KEY'),
        'key_secret' => env('RAZORPAY_SECRET_KEY'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
    ],

    // LLM provider — LLM_PROVIDER=cloudflare|openai. If unset and Cloudflare
    // keys are present, Cloudflare wins; otherwise OpenAI; otherwise the fake.
    'llm' => [
        'provider' => env('LLM_PROVIDER', ''),
    ],
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'chat_model' => env('OPENAI_CHAT_MODEL', 'gpt-4o-mini'),
        'embed_model' => env('OPENAI_EMBED_MODEL', 'text-embedding-3-small'),
    ],

    // Vector store provider — VECTOR_PROVIDER=cloudflare|qdrant. Default
    // logic mirrors the LLM provider above.
    'vector' => [
        'provider' => env('VECTOR_PROVIDER', ''),
        'circuit_threshold' => (int) env('VECTOR_CIRCUIT_THRESHOLD', 5),
        'circuit_cooldown' => (int) env('VECTOR_CIRCUIT_COOLDOWN', 60),
        'circuit_window' => (int) env('VECTOR_CIRCUIT_WINDOW', 60),
    ],
    'qdrant' => [
        'url' => env('QDRANT_URL'),
        'api_key' => env('QDRANT_API_KEY'),
        'collection' => env('QDRANT_COLLECTION', 'pitchbar_chunks'),
    ],

    // Embedding vector dimension. Cloudflare bge-base-en-v1.5 = 768.
    // OpenAI text-embedding-3-small = 1536. Override with VECTOR_DIM if needed.
    'vector_dim' => (int) env('VECTOR_DIM', env('LLM_PROVIDER') === 'openai' ? 1536 : 768),

    // Vector collection name. Resolved per-provider so jobs/services don't have to branch.
    'vector_collection' => env('VECTOR_PROVIDER') === 'qdrant'
        ? env('QDRANT_COLLECTION', 'pitchbar_chunks')
        : env('CLOUDFLARE_VECTORIZE_INDEX', 'pitchbar-chunks'),

    // Crawler. CF Browser Rendering preferred when CF keys present;
    // falls back to Browserless, then plain HTTP (free).
    'browserless' => [
        'url' => env('BROWSERLESS_URL', 'https://chrome.browserless.io'),
        'token' => env('BROWSERLESS_TOKEN'),
    ],

    'crawl' => [
        // 500 default. Operators on very large catalogues raise via env.
        'max_pages_per_source' => (int) env('CRAWL_MAX_PAGES_PER_SOURCE', 500),
        'page_dispatch_delay_seconds' => (int) env('CRAWL_PAGE_DISPATCH_DELAY', 2),
        // Crawler response cache. 0 = disabled; otherwise TTL in seconds.
        'response_cache_ttl_seconds' => (int) env('CRAWL_RESPONSE_CACHE_TTL', 300),
    ],

    // Default similarity threshold for new agents. Cloudflare bge-base-en-v1.5
    // scores typically peak around 0.7–0.85 for in-domain matches; OpenAI
    // text-embedding-3-small peaks higher. Tune via RAG_CONFIDENCE_THRESHOLD.
    'rag' => [
        'confidence_threshold' => (float) env('RAG_CONFIDENCE_THRESHOLD', env('LLM_PROVIDER') === 'openai' ? 0.78 : 0.5),
        // ANN candidate multiplier for the reranker: it sees topK * fan_out
        // candidates and keeps topK. Higher = better precision, slower rerank
        // (cross-encoder time scales with candidate count).
        'rerank_fan_out' => (int) env('RAG_RERANK_FAN_OUT', 3),
        // Reranker HTTP timeout (seconds). Kept SHORT so a slow reranker
        // fails fast to the recall-oriented ANN order instead of stalling
        // the whole turn — an 8s reranker stall tips a turn past the
        // widget's own connection timeout, and the visitor sees a generic
        // "something went wrong" instead of a slightly-less-ranked answer.
        'rerank_timeout_seconds' => (float) env('RAG_RERANK_TIMEOUT_SECONDS', 3.0),
        // Adaptive rerank skip — when ANN already returned >= topK candidates
        // that ALL score >= skip_score, the cross-encoder round-trip
        // (500-1,200ms on Workers AI) only reorders chunks that every one
        // goes into the prompt anyway. Skip it and keep ANN order. Default
        // OFF: enabling is an operator decision after baselining with
        // perf:hotpath. skip_score is an ANN cosine and scales with the
        // embedding model, hence the provider-conditional default.
        'rerank_skip' => (bool) env('RAG_RERANK_SKIP', false),
        'rerank_skip_score' => (float) env('RAG_RERANK_SKIP_SCORE', env('LLM_PROVIDER') === 'openai' ? 0.80 : 0.68),

        // LLM query rewriting (condensed standalone question). When a
        // visitor's message is context-dependent (a follow-up / bare
        // fragment), a small fast LLM rewrites it — using the recent
        // conversation — into ONE self-contained search query BEFORE
        // retrieval, so pronouns/ellipsis/language quirks stop poisoning
        // the embedding. Self-contained questions skip the LLM entirely
        // (the gate), keeping the fast path fast. A slow/failed rewrite
        // falls back to the deterministic heuristic (RetrievalQueryBuilder)
        // — the stream never blocks on it.
        //
        // OFF by default: this adds a round-trip to the hot path, so it is
        // an explicit per-install opt-in (RAG_QUERY_REWRITE=true). Tests and
        // unconfigured installs keep the exact heuristic behavior.
        'query_rewrite' => [
            'enabled' => (bool) env('RAG_QUERY_REWRITE', false),
            // Empty = use the provider's default chat model. Set a small
            // fast model (e.g. @cf/meta/llama-3.1-8b-instruct) to shave
            // latency off the rewrite.
            'model' => env('RAG_QUERY_REWRITE_MODEL', ''),
            // Hard ceiling on the rewrite round-trip. On timeout we fall
            // back to the heuristic — better a slightly-worse query than a
            // stalled stream.
            'timeout_ms' => (int) env('RAG_QUERY_REWRITE_TIMEOUT_MS', 2500),
            // How many recent turns of context the rewriter sees.
            'max_history_turns' => (int) env('RAG_QUERY_REWRITE_HISTORY_TURNS', 6),
        ],
    ],

    // Fast router — skips the pre-stream tool-check completion (1-3
    // full non-streaming LLM calls) when a visitor turn carries no
    // tool intent. Default OFF: enabling is an explicit operator
    // decision after baselining with `perf:hotpath`. Thresholds are
    // cosine similarity against per-tool exemplar centroids and scale
    // with the embedding model.
    //
    // bge defaults calibrated in production (2026-06-10):
    // bge-base scores UNRELATED sentence pairs 0.70-0.71 against the
    // tool centroids (anisotropy — its cosine floor is high), so any
    // bar below ~0.75 routes every turn to the tool loop. 0.85 sits
    // above the measured noise; the keyword gate remains the primary
    // tool-intent path either way.
    'fast_router' => [
        'enabled' => (bool) env('FAST_ROUTER_ENABLED', false),
        'threshold' => (float) env('FAST_ROUTER_TOOL_THRESHOLD', env('LLM_PROVIDER') === 'openai' ? 0.45 : 0.85),
        'thresholds' => [
            // Escalation stays the easiest tool to trigger — a missed
            // escalation strands a frustrated visitor, a false positive
            // merely re-adds the legacy tool-check completion.
            'escalate_to_human' => (float) env('FAST_ROUTER_ESCALATE_THRESHOLD', env('LLM_PROVIDER') === 'openai' ? 0.40 : 0.82),
        ],
    ],

    // Stripe Tax (automatic tax calculation). Opt-in per install: the
    // operator must FIRST activate Stripe Tax in their Stripe dashboard
    // (origin address + tax registrations) — enabling this flag without
    // that makes every checkout fail. New subscriptions / invoices /
    // checkout sessions only; never retroactive.
    'stripe_tax' => [
        'enabled' => (bool) env('CASHIER_AUTO_TAX', false),
    ],

    // Per-turn behind-the-scenes traces for the super-admin conversation
    // debugger (route decision, retrieval summary, tool hops, errors).
    // Written by a queued job after the SSE stream closes — never on the
    // hot path. Pruned daily by `turn-traces:prune`.
    'turn_traces' => [
        'enabled' => (bool) env('TURN_TRACES_ENABLED', true),
        'retention_days' => (int) env('TURN_TRACE_RETENTION_DAYS', 14),
    ],

    // Cloudflare — one bill: chat, embeddings, vector DB, browser rendering, R2.
    'cloudflare' => [
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        'chat_model' => env('CLOUDFLARE_CHAT_MODEL', '@cf/meta/llama-3.3-70b-instruct-fp8-fast'),
        // Secondary Cloudflare CHAT model the failover decorator switches
        // to when the primary is slow / cold-starting / 5xx. Lets a
        // single-Cloudflare install self-heal model→model without a second
        // provider key. Default is a fast, GA, broadly-available model so a
        // slow 70B primary degrades to an 8B answer instead of erroring.
        // Set empty to disable the in-provider fallback.
        'chat_model_fallback' => env('CLOUDFLARE_CHAT_MODEL_FALLBACK', '@cf/meta/llama-3.1-8b-instruct'),
        'embed_model' => env('CLOUDFLARE_EMBED_MODEL', '@cf/baai/bge-base-en-v1.5'),
        'ai_gateway_url' => env('CLOUDFLARE_AI_GATEWAY_URL'),
        'vectorize_index' => env('CLOUDFLARE_VECTORIZE_INDEX', 'pitchbar-chunks'),
        'browser_rendering' => env('CLOUDFLARE_BROWSER_RENDERING', true),
        // Daily cap on Browser Rendering calls. <= 0 = unlimited.
        'browser_daily_limit' => (int) env('CLOUDFLARE_BROWSER_DAILY_LIMIT', 0),
        // Workers AI vision OCR fallback. Used by CloudflareVisionCrawler
        // as the last-resort tier when every HTML-based tier (plain HTTP,
        // CF /markdown, CF /content) produced text below the chain
        // threshold. Costs Workers AI Neurons, so the daily cap exists
        // independently of CLOUDFLARE_BROWSER_DAILY_LIMIT.
        'vision_model' => env('CLOUDFLARE_VISION_MODEL', '@cf/meta/llama-3.2-11b-vision-instruct'),
        'vision_daily_limit' => (int) env('CLOUDFLARE_VISION_DAILY_LIMIT', 0),
    ],

    'widget' => [
        // Fall back to APP_KEY so the secret is never a published constant.
        'jwt_secret' => env('WIDGET_JWT_SECRET') ?: env('APP_KEY', ''),
        'jwt_ttl_minutes' => env('WIDGET_JWT_TTL_MINUTES', 60),
    ],

    // The Pitchbar agent used by the live demo widget on the marketing
    // pages. Set MARKETING_DEMO_AGENT_ID to a real, published agent's UUID
    // and add the marketing site URL to that agent's allowed_origins.
    'marketing' => [
        'demo_agent_id' => env('MARKETING_DEMO_AGENT_ID'),
    ],

    // Notion OAuth integration. Create a public integration at
    // https://www.notion.so/my-integrations, set the redirect URI to
    // {APP_URL}/app/oauth/notion/callback, and copy the values here.
    'notion' => [
        'client_id' => env('NOTION_CLIENT_ID'),
        'client_secret' => env('NOTION_CLIENT_SECRET'),
        'redirect_uri' => env('NOTION_REDIRECT_URI'),
    ],

    // Google OAuth (Drive + Docs). Register an OAuth client in the GCP
    // console with the scopes drive.readonly and documents.readonly.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
    ],

];
