<?php

namespace App\Support;

/**
 * Strips raw provider error envelopes (Cloudflare Workers AI, OpenAI,
 * OpenRouter) down to an operator-facing line + actionable next step.
 *
 * The /admin/settings/system health probes used to render the raw
 * 401 envelope verbatim:
 *
 *     Workers AI 401: {"success":false,"result":[],"messages":[],
 *     "error":[{"code":2009,"message":"Unauthorized"}]}
 *
 * That's useless to a buyer who doesn't know what code 2009 means or
 * which env var to rotate. This presenter mirrors SourceErrorPresenter
 * for the LLM health probes — same "hide the leaky internals, show a
 * fix path" pattern.
 *
 * Note: this is operator-facing (super_admin running probes from
 * /settings/system), not visitor-facing. We're not trying to hide the
 * fact that the LLM is misconfigured — we're translating the failure
 * into a sentence the operator can act on.
 */
class LlmErrorPresenter
{
    /**
     * @return string|null Null when the raw message is empty (caller
     *                     typically substitutes their own generic copy).
     */
    public static function present(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $needle = strtolower($raw);

        // Cloudflare Workers AI — preserved error code 2009 (auth
        // failure) is the same whether the surface is /chat/completions
        // or /embed, but the message is always "Unauthorized" so we can
        // pattern on the HTTP code prefix + the substring.
        if (str_contains($needle, 'workers ai')
            && (str_contains($needle, '401') || str_contains($needle, 'unauthorized') || str_contains($needle, '"code":2009'))) {
            return 'Workers AI rejected the API token (401 Unauthorized). Most common cause: CLOUDFLARE_ACCOUNT_ID does not match the account the token was issued for. Verify in Settings → System → AI that both values come from the SAME Cloudflare account. The token only needs "Workers AI: Read" scope (no AI Gateway scope required).';
        }

        if (str_contains($needle, 'workers ai') && (str_contains($needle, '403') || str_contains($needle, 'forbidden'))) {
            return 'Workers AI returned 403 Forbidden. The token is valid but lacks permission for this model or account — check CLOUDFLARE_ACCOUNT_ID matches the token, and confirm Workers AI is enabled for that account.';
        }

        if (str_contains($needle, 'workers ai') && str_contains($needle, '404')) {
            // Most 404s here are "model not found" because the configured
            // model slug is wrong (e.g. typo in CLOUDFLARE_CHAT_MODEL).
            return 'Workers AI returned 404 — usually the configured model slug is wrong. Verify CLOUDFLARE_CHAT_MODEL and CLOUDFLARE_EMBED_MODEL in Settings → System → AI.';
        }

        if (str_contains($needle, 'workers ai') && str_contains($needle, '429')) {
            return 'Workers AI is rate-limiting this account. Retry in a minute, or upgrade the Workers AI plan if this keeps happening on real traffic.';
        }

        if (str_contains($needle, 'workers ai timeout')) {
            return 'Workers AI took too long to respond. Likely a transient cold-start — retry the probe; if it persists, switch CLOUDFLARE_CHAT_MODEL to a smaller variant.';
        }

        if (str_contains($needle, 'workers ai')
            && (str_contains($needle, '500') || str_contains($needle, '502') || str_contains($needle, '503'))) {
            return 'Workers AI is currently unavailable (5xx from Cloudflare). Try again in a minute. If this persists, watch https://www.cloudflarestatus.com/.';
        }

        // Cloudflare Vectorize — dimension mismatch (codes 40006 on
        // /query, 40012 on /upsert) when the operator changed
        // CLOUDFLARE_EMBED_MODEL but the existing index was provisioned
        // at the old dim. Buyer-reported (whispbar 2026-05-15, then
        // again from the playground surface). The raw envelope leaks
        // "Vectorize POST indexes/.../query failed: {...code:40006...}"
        // — replace with the rebuild-index recovery hint.
        if (str_contains($needle, 'vectorize')
            && (str_contains($needle, 'expected') && str_contains($needle, 'dimensions'))) {
            return 'The Vectorize index dimension does not match the configured embedding model. Run `php artisan vector:rebuild-index` on the server to drop + recreate the index at the model\'s native dim, then re-index Sources. The Knowledge documentation has the full recovery procedure.';
        }

        if (str_contains($needle, 'vectorize')
            && (str_contains($needle, '40006') || str_contains($needle, '40012'))) {
            return 'Vectorize rejected the vector payload (dim mismatch or malformed). Run `php artisan vector:rebuild-index` if the index was created at a different embedding model.';
        }

        // Generic Vectorize failures — strip the verbose envelope and
        // point at the platform health page.
        if (str_contains($needle, 'vectorize')
            && (str_contains($needle, '401') || str_contains($needle, 'unauthorized'))) {
            return 'Vectorize rejected the API token (401 Unauthorized). Rotate CLOUDFLARE_API_TOKEN in Settings → System → AI.';
        }

        if (str_contains($needle, 'vectorize')
            && str_contains($needle, '404')
            && str_contains($needle, 'not found')) {
            return 'The Vectorize index does not exist yet. Run `php artisan qdrant:setup` to provision it.';
        }

        // OpenAI / OpenRouter mirror the same HTTP-code shape but their
        // error envelopes look different. Keep these branches simple —
        // most installs on those providers diagnose from the dashboard.
        if (str_contains($needle, 'incorrect api key') || str_contains($needle, 'invalid api key')) {
            return 'The configured AI API key is invalid. Rotate it in Settings → System → AI.';
        }

        if (str_contains($needle, 'insufficient_quota') || str_contains($needle, 'quota')) {
            return 'The configured AI provider returned a quota error. Check the billing dashboard for the provider in question.';
        }

        // cURL / network errors. These split four ways — conflating them
        // sends operators down the wrong path. The biggest offender: a
        // Guzzle READ timeout (slow / cold-starting model overrunning the
        // per-call timeout) surfaces as "cURL error 28: Operation timed
        // out after 25000 milliseconds", which contains "curl error" and
        // used to fall into the firewall/DNS line below. A timeout is NOT
        // a network-config fault — the connection succeeded, the provider
        // was just too slow — so it gets its own branch FIRST. (blengi
        // 2026-06-26: Llama 3.3 70B cold starts overran the playground's
        // tool timeout and the operator was told to check their firewall.)
        if (str_contains($needle, 'operation timed out')
            || str_contains($needle, 'timed out after')
            || str_contains($needle, 'curl error 28')) {
            return 'The AI provider accepted the request but did not respond in time (the call timed out). This is almost always a slow or cold-starting model under load — not a firewall or DNS problem. Retry the message; if it keeps happening, switch CLOUDFLARE_CHAT_MODEL to a faster variant in Settings → System → AI, or add a second provider so failover can absorb a slow primary.';
        }

        // DNS — the hostname didn't resolve. cURL 6 / getaddrinfo.
        if (str_contains($needle, 'could not resolve host')
            || str_contains($needle, 'curl error 6')
            || str_contains($needle, 'name or service not known')) {
            return 'Could not resolve the AI provider\'s hostname (DNS lookup failed). Check the server\'s DNS resolver and outbound network access to the provider.';
        }

        // Connection refused / blocked — cURL 7 / firewall / no route.
        if (str_contains($needle, 'failed to connect')
            || str_contains($needle, 'connection refused')
            || str_contains($needle, 'curl error 7')
            || str_contains($needle, 'no route to host')
            || str_contains($needle, 'network is unreachable')) {
            return 'Could not open a connection to the AI provider (connection refused or blocked). Check the server\'s outbound firewall and that the provider host is reachable on the expected port.';
        }

        // Any other cURL transport error (TLS handshake, empty reply,
        // recv failure) — generic network line.
        if (str_contains($needle, 'curl error')) {
            return 'Could not reach the configured AI provider (network error talking to the provider). Check the server\'s outbound network — firewall, DNS, and TLS.';
        }

        // Catch-all: strip a "Workers AI {code}:" / "Workers AI embed
        // {code}:" prefix and the JSON envelope, so an unknown code at
        // least shows the HTTP status without the raw `{"success":...}`
        // body bleeding through.
        if (preg_match('/^workers ai( embed)? (\d{3}):/i', trim($raw), $m) === 1) {
            $surface = $m[1] ? 'Workers AI embed' : 'Workers AI';

            return "{$surface} returned HTTP {$m[2]}. Check Settings → System → AI for the active provider configuration.";
        }

        // Vectorize fallback: strip the verbose envelope prefix so the
        // operator at least sees "something broke in the vector store"
        // without the 500-byte Cloudflare JSON dump.
        if (preg_match('/^vectorize\s+\w+\s+[^\s]+\s+failed:/i', trim($raw), $m) === 1) {
            return 'Vectorize call failed. Check Settings → System → AI for the active provider configuration, and verify the index exists at the dimension matching the embedding model.';
        }

        // Truncate anything else to a sensible length so we never spew
        // a 5KB error body into a JSON response.
        return mb_strimwidth(trim($raw), 0, 200, '…');
    }
}
