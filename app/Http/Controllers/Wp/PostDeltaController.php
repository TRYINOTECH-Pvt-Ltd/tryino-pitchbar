<?php

namespace App\Http\Controllers\Wp;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Services\Integrations\WordPress\PostPayload;
use App\Services\Integrations\WordPress\WordPressIngestService;
use App\Support\CurrentWorkspace;
use App\Support\WpAgentStamper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Single-post delta from the WordPress plugin's save_post /
 * wp_trash_post / deleted_post listeners. Body:
 *
 *  {
 *      "agent_id": "uuid",
 *      "site_url": "https://shop.example.com",
 *      "plugin_version": "1.0.0",
 *      "action": "upsert" | "delete",
 *      "post": { wp_id, post_type, permalink, title, content_html, excerpt, content_hash, modified_at, language, taxonomy_terms }
 *  }
 *
 * Delete is a silent no-op when the post is unknown.
 */
class PostDeltaController extends Controller
{
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
            'action' => ['required', 'string', 'in:upsert,delete'],
            'post' => ['required', 'array'],
            'post.wp_id' => ['required'],
            'post.post_type' => ['required_if:action,upsert', 'nullable', 'string', 'max:64'],
            'post.permalink' => ['required_if:action,upsert', 'nullable', 'string', 'url', 'max:1000'],
            'post.title' => ['required_if:action,upsert', 'nullable', 'string', 'max:500'],
            'post.content_html' => ['required_if:action,upsert', 'nullable', 'string'],
            'post.excerpt' => ['nullable', 'string', 'max:5000'],
            'post.content_hash' => ['required_if:action,upsert', 'nullable', 'string', 'size:64'],
            'post.modified_at' => ['nullable', 'string', 'max:64'],
            'post.language' => ['nullable', 'string', 'max:16'],
            'post.taxonomy_terms' => ['nullable', 'array'],
            'post.taxonomy_terms.*' => ['string', 'max:160'],
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

        // Stamp the most-recent plugin signal — single-post deltas are
        // the most common heartbeat on a live site (every save_post),
        // so /app/integrations reflects "connected" within seconds.
        $this->stamper->touch($data['agent_id'], $request);

        $externalId = 'wp:'.(string) $data['post']['wp_id'];

        if ($data['action'] === 'delete') {
            $deleted = $this->service->delete($agent, $externalId);

            return new JsonResponse(['data' => ['action' => 'delete', 'deleted' => $deleted]], 200);
        }

        $source = $this->service->resolveSource($agent, $data['site_url'], $data['plugin_version']);
        $payload = PostPayload::fromArray($data['post']);
        $result = $this->service->upsertOne($agent, $source, $payload);

        return new JsonResponse(['data' => ['action' => 'upsert', 'result' => $result]], 200);
    }
}
