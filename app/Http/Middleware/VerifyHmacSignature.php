<?php

namespace App\Http\Middleware;

use App\Models\WorkspaceApiToken;
use App\Support\HmacSignature;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the X-Pitchbar-Signature HMAC on incoming WP plugin
 * requests. Must run AFTER `auth.api_token` so the plaintext token is
 * available as the HMAC shared secret on the request.
 *
 * The token plaintext is reconstructed from the Authorization header
 * because we never store it post-issue — the bearer the plugin sends
 * IS the shared secret. The middleware re-derives the sha256 hash to
 * confirm the bearer is alive, then uses the bearer string itself to
 * verify the signature.
 *
 * Reject reasons → 401 + a code in the body: missing_signature,
 * malformed_signature, replay_window, signature_mismatch.
 */
class VerifyHmacSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('X-Pitchbar-Signature');
        if (! is_string($header) || $header === '') {
            return $this->unauthenticated('missing_signature');
        }

        $bearer = $request->bearerToken();
        if (! is_string($bearer) || $bearer === '') {
            // Should never happen — `auth.api_token` runs first — but
            // belt-and-suspenders against a misordered route group.
            return $this->unauthenticated('missing_bearer');
        }

        // Verify the bearer still resolves to a live (non-revoked) token.
        // `auth.api_token` already did this; we re-check to make sure no
        // other middleware in between flushed the request body.
        $token = WorkspaceApiToken::query()
            ->withoutGlobalScopes()
            ->where('token_hash', hash('sha256', $bearer))
            ->whereNull('revoked_at')
            ->first();

        if ($token === null) {
            return $this->unauthenticated('invalid_token');
        }

        $body = (string) $request->getContent();

        if (! HmacSignature::verify($header, $bearer, $body)) {
            return $this->unauthenticated('signature_mismatch');
        }

        return $next($request);
    }

    private function unauthenticated(string $code): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => match ($code) {
                    'missing_signature' => 'X-Pitchbar-Signature header missing.',
                    'missing_bearer' => 'API token missing.',
                    'invalid_token' => 'API token is invalid or has been revoked.',
                    'signature_mismatch' => 'HMAC signature did not verify.',
                    default => 'Unauthenticated.',
                },
            ],
        ], 401);
    }
}
