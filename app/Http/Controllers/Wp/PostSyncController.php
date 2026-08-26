<?php

namespace App\Http\Controllers\Wp;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Services\Integrations\WordPress\WordPressIngestService;
use App\Support\CurrentWorkspace;
use App\Support\WpAgentStamper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bulk WordPress post ingest. Plugin pages WP_Query in batches of 50
 * and POSTs each page here. Body:
 *
 *  {
 *      "agent_id": "uuid",
 *      "site_url": "https://shop.example.com",
 *      "plugin_version": "1.0.0",
 *      "posts": [
 *          { "wp_id": 42, "post_type": "post", "permalink": "...", "title": "...", "content_html": "...", "excerpt": "...", "content_hash": "...", "modified_at": "...", "language": "en", "taxonomy_terms": ["news"] }
 *      ]
 *  }
 *
 * Response: { data: { accepted, queued, skipped_unchanged } }
 *
 * Auth: `auth.api_token:wp:integration` resolves the workspace.
 * Integrity: `hmac.signature` verifies the request body wasn't tampered.
 */
class PostSyncController extends Controller
{
    public const MAX_BATCH_SIZE = 50;

    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly WordPressIngestService $service,
        private readonly WpAgentStamper $stamper,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agent_id' => ['required', 'string', 'uuid'],
            'site_url' => ['required', 'string', 'url', 'max:500'],
            'plugin_version' => ['required', 'string', 'max:32'],
            'posts' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH_SIZE],
            'posts.*.wp_id' => ['required'],
            'posts.*.post_type' => ['required', 'string', 'max:64'],
            'posts.*.permalink' => ['required', 'string', 'url', 'max:1000'],
            'posts.*.title' => ['required', 'string', 'max:500'],
            // `present, nullable, string` accepts an empty string OR null.
            // Pre-fix this was `required, string` which rejected the whole
            // batch the first time any post had no body content (featured-
            // image-only, builder-owned posts with empty post_content,
            // stub pages). Laravel's global ConvertEmptyStringsToNull
            // middleware turns "" into null pre-validation, so we need
            // nullable too — otherwise the rejection comes back as "must
            // be a string" instead of "is required" but the buyer's batch
            // still fails. Ingest service handles empty content gracefully
            // (queued_empty, title still goes into Documents).
            // Hard cap at 2MB per post. Larger sources should split.
            'posts.*.content_html' => ['present', 'nullable', 'string', 'max:2097152'],
            'posts.*.excerpt' => ['nullable', 'string', 'max:5000'],
            'posts.*.content_hash' => ['required', 'string', 'size:64'],
            'posts.*.modified_at' => ['nullable', 'string', 'max:64'],
            'posts.*.language' => ['nullable', 'string', 'max:16'],
            'posts.*.taxonomy_terms' => ['nullable', 'array'],
            'posts.*.taxonomy_terms.*' => ['string', 'max:160'],
        ]);

        $workspace = $this->current->get();
        abort_unless($workspace !== null, 401);

        $agent = Agent::query()
            ->where('workspace_id', $workspace->id)
            ->where('id', $data['agent_id'])
            ->first();

        if ($agent === null) {
            return new JsonResponse([
                'error' => ['code' => 'agent_not_found', 'message' => 'Agent does not belong to this workspace.'],
            ], 404);
        }

        // Stamp the most-recent plugin signal onto the agent so the
        // /app/integrations page can show "WordPress connected".
        $this->stamper->touch($data['agent_id'], $request);

        $result = $this->service->upsertBatch(
            $agent,
            $data['site_url'],
            $data['plugin_version'],
            $data['posts'],
        );

        return new JsonResponse(['data' => $result], 200);
    }
}
