<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Workspace;
use Illuminate\Support\Str;

/**
 * Builds + verifies the signed payload a `link_with_context` CTA appends
 * to its outbound URL.
 *
 * Outgoing shape:
 *
 *     <base-url>?pitchbar_ctx=<base64url-json>&pitchbar_ts=<unix>&pitchbar_sig=<hex-hmac>
 *
 * The receiving site copies the workspace's `cta_context_secret` from
 * Settings → API tokens and verifies the request with:
 *
 *     $expected = hash_hmac('sha256', $rawCtx . '.' . $rawTs, $secret);
 *     if (! hash_equals($expected, $sig)) abort(401);
 *
 * A 5-minute replay window is enforced by comparing the unix timestamp
 * field to `now()`. Beyond that window the verifier rejects even if the
 * signature is valid — prevents replays of intercepted URLs.
 *
 * Whitelisted forward fields (operator-selected per-CTA):
 *   - conversation_id
 *   - visitor_email      (only when a Lead is attached)
 *   - visitor_name       (only when a Lead is attached)
 *   - captured_fields    (the Lead.fields JSON minus sensitive keys)
 *   - page_url           (where the chat happened)
 *   - agent_id
 *
 * Operators who want the full transcript subscribe to the outbound
 * webhook — too big and too privacy-sensitive for URL transport.
 */
final class CtaContextSigner
{
    public const REPLAY_WINDOW_SECONDS = 300;

    public const ALLOWED_FIELDS = [
        'conversation_id',
        'visitor_email',
        'visitor_name',
        'captured_fields',
        'page_url',
        'agent_id',
    ];

    /**
     * Append signed context params to a base URL. Returns the URL
     * verbatim when no fields are forwarded so the existing `link` CTA
     * shape stays a no-op fast path.
     *
     * @param  array<int, string>  $forwardFields  Subset of ALLOWED_FIELDS
     */
    public function buildSignedUrl(
        string $baseUrl,
        array $forwardFields,
        Conversation $conversation,
        Workspace $workspace,
    ): string {
        $forwardFields = array_values(array_intersect($forwardFields, self::ALLOWED_FIELDS));
        if ($forwardFields === []) {
            return $baseUrl;
        }

        $payload = $this->buildPayload($forwardFields, $conversation);
        $secret = $this->ensureSecret($workspace);

        $ts = (string) time();
        $ctx = $this->base64UrlEncode((string) json_encode($payload));
        $sig = hash_hmac('sha256', $ctx.'.'.$ts, $secret);

        $sep = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl
            .$sep.'pitchbar_ctx='.$ctx
            .'&pitchbar_ts='.$ts
            .'&pitchbar_sig='.$sig;
    }

    /**
     * Constant-time verify of a received payload + signature. Caller
     * passes the raw query string values (already URL-decoded by
     * whatever HTTP server received them).
     */
    public function verify(
        string $ctx,
        string $ts,
        string $sig,
        Workspace $workspace,
    ): bool {
        $secret = $workspace->cta_context_secret;
        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $tsInt = (int) $ts;
        if ($tsInt <= 0 || abs(time() - $tsInt) > self::REPLAY_WINDOW_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $ctx.'.'.$ts, $secret);

        return hash_equals($expected, $sig);
    }

    /**
     * Decode the verified payload back into an associative array.
     * Returns null on malformed JSON / non-string input.
     */
    public function decode(string $ctx): ?array
    {
        $json = $this->base64UrlDecode($ctx);
        if ($json === null) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Generate + persist a fresh workspace secret. Idempotent: returns
     * the existing secret if one is already stored.
     */
    public function ensureSecret(Workspace $workspace): string
    {
        if (is_string($workspace->cta_context_secret) && $workspace->cta_context_secret !== '') {
            return $workspace->cta_context_secret;
        }

        $secret = 'pbar_ctx_'.Str::random(48);
        $workspace->forceFill(['cta_context_secret' => $secret])->save();

        return $secret;
    }

    /**
     * @param  array<int, string>  $forwardFields
     * @return array<string, mixed>
     */
    private function buildPayload(array $forwardFields, Conversation $conversation): array
    {
        $lead = null;
        if (
            in_array('visitor_email', $forwardFields, true)
            || in_array('visitor_name', $forwardFields, true)
            || in_array('captured_fields', $forwardFields, true)
        ) {
            $lead = Lead::query()->withoutGlobalScopes()
                ->where('conversation_id', $conversation->id)
                ->latest()
                ->first();
        }

        $payload = [];
        foreach ($forwardFields as $field) {
            $payload[$field] = match ($field) {
                'conversation_id' => $conversation->id,
                'agent_id' => $conversation->agent_id,
                'page_url' => $conversation->page_url,
                'visitor_email' => $lead?->email,
                'visitor_name' => $lead?->name,
                'captured_fields' => $this->stripSensitive((array) ($lead?->fields ?? [])),
                default => null,
            };
        }

        return $payload;
    }

    /**
     * Remove keys that look like passwords or tokens from captured
     * fields. Defence-in-depth — operators shouldn't ship those into
     * the CTA payload, but if they did, we don't relay them.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function stripSensitive(array $fields): array
    {
        $blocked = ['password', 'pwd', 'secret', 'token', 'api_key', 'apikey', 'private'];

        return array_filter(
            $fields,
            fn ($_value, $key) => ! collect($blocked)->contains(
                fn ($needle) => stripos((string) $key, $needle) !== false,
            ),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $encoded): ?string
    {
        $padded = $encoded.str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
