<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Widget\InitController;
use App\Models\Agent;
use App\Services\Widget\WidgetJwt;
use Closure;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-validates the request Origin against the bearer JWT's agent's
 * `allowed_origins` on every privileged widget endpoint. Defence-in-
 * depth on top of the JWT signature check: even if a token leaks
 * (browser dev-tools, leaked log, XSS on a victim site, MITM on
 * cleartext deployments), an attacker can't replay it from
 * attacker.com because the Origin header still has to match what
 * the workspace owner explicitly listed.
 *
 * Skipped for /widget/init — that endpoint issues the JWT in the
 * first place and enforces the same allowed_origins check inline.
 *
 * Hot-path budget: one JWT decode (no signature verify; controllers
 * do that authoritatively) + one Agent fetch via the Eloquent
 * instance cache. The Agent row is rehydrated lazily; subsequent
 * controller code reuses the same instance.
 */
class VerifyWidgetOrigin
{
    public function __construct(private readonly WidgetJwt $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ?? $request->header('X-Widget-Token');
        if (! is_string($token) || $token === '') {
            // Token missing — let the controller's own auth handler
            // return the canonical 401. We don't enforce Origin
            // before the token is even known.
            return $next($request);
        }

        // Decode without signature verification — the controller
        // will verify cryptographically. We only need agent_id here
        // to look up allowed_origins.
        $agentId = $this->extractAgentId($token);
        if ($agentId === null) {
            return $next($request);
        }

        $agent = Agent::query()
            ->withoutGlobalScopes()
            ->find($agentId);
        if ($agent === null) {
            return $next($request);
        }

        // Prefer Origin header; fall back to Referer's {scheme}://{host}
        // prefix only (drop path/query so a full-URL Referer can't spoof
        // the allowlist match).
        $origin = $request->headers->get('Origin');
        if ($origin === null || $origin === '') {
            $referer = $request->headers->get('Referer');
            if (is_string($referer) && $referer !== '') {
                $scheme = parse_url($referer, PHP_URL_SCHEME);
                $host = parse_url($referer, PHP_URL_HOST);
                $port = parse_url($referer, PHP_URL_PORT);
                if (is_string($scheme) && is_string($host) && $host !== '') {
                    $origin = $scheme.'://'.$host.($port ? ':'.$port : '');
                }
            }
        }

        if (! $this->originAllowed($origin, $agent)) {
            return response()->json([
                'error' => [
                    'code' => 'origin_forbidden',
                    'message' => 'Origin is not allowed for this agent.',
                ],
            ], 403)->header('Access-Control-Allow-Origin', $origin ?? '*');
        }

        return $next($request);
    }

    private function extractAgentId(string $token): ?string
    {
        // JWT format: header.payload.signature — decode the middle
        // segment without invoking the firebase/php-jwt verifier
        // (saves us a duplicate signature check; the controller
        // does the authoritative verification). Bail on any parse
        // error and let the controller's check own the 401.
        $segments = explode('.', $token);
        if (count($segments) !== 3) {
            return null;
        }
        try {
            $payload = (array) json_decode(
                JWT::urlsafeB64Decode($segments[1]),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (\Throwable) {
            return null;
        }
        $agentId = $payload['agent_id'] ?? null;

        return is_string($agentId) && $agentId !== '' ? $agentId : null;
    }

    /**
     * Mirrors `InitController::originAllowed` so the policy is
     * identical at issuance and at every privileged call: empty
     * list = deny, `*` = allow (no Origin required), specific
     * entries = exact normalised match. Drifting from init would
     * mean an agent that successfully issued a JWT could see its
     * own follow-up calls rejected — worse UX, no security gain.
     */
    private function originAllowed(?string $origin, Agent $agent): bool
    {
        $allowed = array_values(array_filter(array_map(
            static fn ($o) => InitController::normaliseOrigin((string) $o),
            (array) ($agent->allowed_origins ?? []),
        ), static fn (string $o) => $o !== ''));

        if ($allowed === []) {
            return false;
        }
        if (in_array('*', $allowed, true)) {
            return true;
        }
        if ($origin === null) {
            return false;
        }
        $normalised = InitController::normaliseOrigin($origin);

        return $normalised !== '' && in_array($normalised, $allowed, true);
    }
}
