<?php

namespace App\Http\Middleware;

use App\Models\WorkspaceApiToken;
use App\Scopes\WorkspaceScope;
use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates first-party integrations (today: WordPress plugin)
 * via a workspace-scoped bearer token. The plaintext token is hashed
 * sha256 and compared in constant time against workspace_api_tokens.
 *
 * On success the request is bound to the token's workspace via
 * CurrentWorkspace so every downstream BelongsToWorkspace global
 * scope filters correctly — no controller has to remember.
 *
 * Optional ability check: register as `auth.api_token:wp:integration`
 * to require a specific ability on the token. Without an argument the
 * middleware only verifies the token is valid + active.
 */
class AuthenticateApiToken
{
    public function __construct(private CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next, ?string $ability = null): Response
    {
        $plaintext = $this->extractToken($request);
        if ($plaintext === null) {
            return $this->unauthenticated('missing_token');
        }

        $hash = hash('sha256', $plaintext);

        $token = WorkspaceApiToken::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('token_hash', $hash)
            ->whereNull('revoked_at')
            ->first();

        if ($token === null) {
            return $this->unauthenticated('invalid_token');
        }

        if ($ability !== null && ! $token->hasAbility($ability)) {
            return $this->unauthenticated('missing_ability', 403);
        }

        $this->current->set($token->workspace_id);
        $request->attributes->set('api_token', $token);
        $request->attributes->set('workspace_id', $token->workspace_id);

        $this->touchLastUsed($token);

        try {
            return $next($request);
        } finally {
            $this->current->clear();
        }
    }

    private function extractToken(Request $request): ?string
    {
        $bearer = $request->bearerToken();
        if (is_string($bearer) && $bearer !== '') {
            return $bearer;
        }

        $header = $request->header('X-Pitchbar-Token');
        if (is_string($header) && $header !== '') {
            return $header;
        }

        return null;
    }

    /**
     * Throttle last_used_at writes to once per minute per token so a
     * busy WP site doesn't generate a write per request.
     */
    private function touchLastUsed(WorkspaceApiToken $token): void
    {
        $key = 'api-token:last-used:'.$token->id;
        Cache::remember($key, 60, function () use ($token) {
            $token->forceFill(['last_used_at' => now()])->save();

            return true;
        });
    }

    private function unauthenticated(string $code, int $status = 401): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => match ($code) {
                    'missing_token' => 'API token missing.',
                    'invalid_token' => 'API token is invalid or has been revoked.',
                    'missing_ability' => 'API token does not have the required ability.',
                    default => 'Unauthenticated.',
                },
            ],
        ], $status);
    }
}
