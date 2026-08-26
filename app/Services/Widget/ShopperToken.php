<?php

namespace App\Services\Widget;

use App\Models\WorkspaceApiToken;
use App\Scopes\WorkspaceScope;
use App\Support\HmacSignature;

/**
 * Short-lived signed token a CMS adapter (today: the WordPress plugin)
 * attaches to the widget loader when a logged-in customer is browsing.
 * Carries `wp_user_id` + `email_hash` claims so Pitchbar can offer
 * personalised flows (e.g. the `lookup_order` tool).
 *
 * Wire format:
 *
 *   base64url({claims_json}).{HmacSignature::sign(claims_json, secret)}
 *
 *   secret = workspace_api_tokens.shopper_signing_secret (plaintext)
 *
 * The secret is generated alongside the API bearer at token-issue
 * time, returned ONCE in the issuance flash, and stored plaintext
 * server-side. The plugin fetches it on first handshake. Its blast
 * radius is limited: leaking it lets an attacker forge a shopper
 * claim, but the claim is silently dropped on signature mismatch and
 * the visitor remains anonymous — the API surface itself stays gated
 * by the hashed bearer.
 */
class ShopperToken
{
    public const SOURCE_WORDPRESS = 'wordpress';

    /**
     * Issue a shopper token. Mirror of the plugin-side signer; used in
     * tests and for any future server-side issuance flows.
     *
     * @param  array{wp_user_id:int|string, email_hash?:string, source?:string}  $claims
     */
    public static function issue(array $claims, string $signingSecret): string
    {
        $payload = [
            'wp_user_id' => (string) ($claims['wp_user_id'] ?? ''),
            'email_hash' => (string) ($claims['email_hash'] ?? ''),
            'source' => (string) ($claims['source'] ?? self::SOURCE_WORDPRESS),
        ];
        $json = (string) json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = HmacSignature::sign($signingSecret, $json);

        return self::b64UrlEncode($json).'.'.$signature;
    }

    /**
     * Verify a shopper token against every active API token in the
     * given workspace, trying each `shopper_signing_secret` until one
     * matches. Returns the decoded claims on success, null otherwise.
     *
     * @return array{wp_user_id:string, email_hash:string, source:string}|null
     */
    public static function verifyForWorkspace(string $token, string $workspaceId): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$encodedPayload, $signatureHeader] = $parts;

        $payloadJson = self::b64UrlDecode($encodedPayload);
        if ($payloadJson === null) {
            return null;
        }

        $candidates = WorkspaceApiToken::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $workspaceId)
            ->whereNull('revoked_at')
            ->whereNotNull('shopper_signing_secret')
            ->get(['id', 'shopper_signing_secret']);

        foreach ($candidates as $candidate) {
            $secret = (string) $candidate->shopper_signing_secret;
            if ($secret === '') {
                continue;
            }
            if (HmacSignature::verify($signatureHeader, $secret, $payloadJson)) {
                return self::shapeClaims($payloadJson);
            }
        }

        return null;
    }

    /**
     * Explicit-secret verification path. Useful for unit tests and any
     * callsite that already has the signing secret resolved.
     *
     * @return array{wp_user_id:string, email_hash:string, source:string}|null
     */
    public static function verifyWithSecret(string $token, string $secret): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$encodedPayload, $signatureHeader] = $parts;

        $payloadJson = self::b64UrlDecode($encodedPayload);
        if ($payloadJson === null) {
            return null;
        }
        if (! HmacSignature::verify($signatureHeader, $secret, $payloadJson)) {
            return null;
        }

        return self::shapeClaims($payloadJson);
    }

    /**
     * @return array{wp_user_id:string, email_hash:string, source:string}|null
     */
    private static function shapeClaims(string $payloadJson): ?array
    {
        $decoded = json_decode($payloadJson, true);
        if (! is_array($decoded)) {
            return null;
        }

        $wpUserId = (string) ($decoded['wp_user_id'] ?? '');
        $source = (string) ($decoded['source'] ?? '');

        if ($wpUserId === '' || $source === '') {
            return null;
        }

        return [
            'wp_user_id' => $wpUserId,
            'email_hash' => (string) ($decoded['email_hash'] ?? ''),
            'source' => $source,
        ];
    }

    private static function b64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function b64UrlDecode(string $encoded): ?string
    {
        $padded = $encoded;
        $remainder = strlen($encoded) % 4;
        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
