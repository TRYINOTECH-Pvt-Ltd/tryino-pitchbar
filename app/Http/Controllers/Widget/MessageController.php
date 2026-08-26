<?php

namespace App\Http\Controllers\Widget;

use App\Services\Rag\RagPipeline;
use App\Services\Widget\WidgetJwt;
use App\Support\CanonicalUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController
{
    public function __construct(
        private WidgetJwt $jwt,
        private RagPipeline $rag,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->bearerToken() ?? $request->header('X-Widget-Token');
        if (! is_string($token)) {
            return response()->json(['error' => ['code' => 'missing_token']], 401);
        }

        try {
            $claims = $this->jwt->verify($token);
        } catch (\Throwable $e) {
            // Flat code only — JWT decode messages leak algorithm + claim names.
            return response()->json(['error' => ['code' => 'invalid_token']], 401);
        }

        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'page_context' => ['nullable', 'array'],
        ]);

        $conversationId = (string) ($claims['conversation_id'] ?? '');
        if ($conversationId === '') {
            return response()->json(['error' => ['code' => 'invalid_token']], 401);
        }

        $pageContext = $this->sanitizePageContext($data['page_context'] ?? null);

        $result = $this->rag->handle($conversationId, $data['message'], pageContext: $pageContext);

        return response()->json(['data' => $result]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sanitizePageContext(mixed $raw): ?array
    {
        if (! is_array($raw) || $raw === []) {
            return null;
        }
        $encoded = json_encode($raw, JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded) || strlen($encoded) > 8192) {
            return null;
        }
        $allowed = ['url', 'title', 'description', 'og', 'twitter', 'json_ld', 'h1', 'h2', 'visible_text'];
        $clean = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $raw)) {
                $clean[$key] = $raw[$key];
            }
        }

        // Canonicalize page_context.url — matches CrawlPageJob's stored
        // Document.url so citations and Knowledge dedup line up.
        if (isset($clean['url']) && is_string($clean['url'])) {
            $canonical = CanonicalUrl::for($clean['url']);
            if ($canonical !== null) {
                $clean['url'] = $canonical;
            }
        }

        return $clean === [] ? null : $clean;
    }
}
