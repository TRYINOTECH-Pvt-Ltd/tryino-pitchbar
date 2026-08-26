<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\Crawl\CrawlSourceJob;
use App\Jobs\Crawl\IndexTextSourceJob;
use App\Models\Agent;
use App\Models\Source;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Public REST endpoint for programmatic knowledge ingest. Auth via the
 * workspace API token ability `sources:write` (created in
 * /settings/api-tokens). Workspace is inferred from the token; the
 * caller never specifies it.
 *
 * The shape mirrors the admin SourceController so the same downstream
 * crawl / index pipeline runs end-to-end:
 *   - kind=url        → schedules CrawlSourceJob
 *   - kind=sitemap    → schedules CrawlSourceJob (fans out from sitemap)
 *   - kind=text       → schedules IndexTextSourceJob (skips crawler)
 *
 * Agent must belong to the authenticated workspace. We enforce that
 * with `withoutGlobalScopes` + an explicit workspace_id check (the
 * BelongsToWorkspace scope can't help us here because the token middleware
 * binds CurrentWorkspace but the route-model binding runs first; safer
 * to spell it out).
 */
class SourcesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $workspaceId = $request->attributes->get('workspace_id');
        abort_if($workspaceId === null, 401);

        $sources = Source::query()
            ->withoutGlobalScopes()
            ->whereIn(
                'agent_id',
                Agent::query()->withoutGlobalScopes()
                    ->where('workspace_id', $workspaceId)
                    ->pluck('id'),
            )
            ->latest()
            ->limit(100)
            ->get(['id', 'agent_id', 'type', 'status', 'config', 'last_synced_at', 'created_at']);

        return response()->json([
            'data' => $sources->map(fn (Source $s) => [
                'id' => $s->id,
                'agent_id' => $s->agent_id,
                'kind' => $s->type,
                'status' => $s->status,
                'config' => $s->config,
                'last_synced_at' => $s->last_synced_at?->toIso8601String(),
                'created_at' => $s->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $workspaceId = $request->attributes->get('workspace_id');
        abort_if($workspaceId === null, 401);

        $data = $request->validate([
            'agent_id' => ['required', 'string'],
            'kind' => ['required', Rule::in(['url', 'sitemap', 'text'])],
            'url' => ['required_if:kind,url,sitemap', 'nullable', 'url', 'max:2000'],
            'title' => ['nullable', 'string', 'max:250'],
            'content' => ['required_if:kind,text', 'nullable', 'string', 'min:50', 'max:200000'],
            'source_url' => ['nullable', 'url', 'max:2000'],
        ]);

        // Agent must belong to the token's workspace.
        $agent = Agent::query()->withoutGlobalScopes()
            ->where('id', $data['agent_id'])
            ->where('workspace_id', $workspaceId)
            ->first();

        if ($agent === null) {
            return response()->json([
                'error' => [
                    'code' => 'agent_not_found',
                    'message' => 'Agent not found in this workspace.',
                ],
            ], 404);
        }

        if ($data['kind'] === 'text') {
            $title = $data['title']
                ?? mb_substr(trim((string) preg_replace('/\s+/u', ' ', $data['content'])), 0, 80);

            $source = Source::create([
                'agent_id' => $agent->id,
                'type' => 'text',
                'status' => 'pending',
                'config' => [
                    'title' => $title,
                    'source_url' => $data['source_url'] ?? null,
                ],
            ]);

            IndexTextSourceJob::dispatch(
                $source->id,
                $title,
                $data['content'],
                $data['source_url'] ?? null,
            )->onQueue('index');

            return $this->serialise($source, 201);
        }

        $source = Source::create([
            'agent_id' => $agent->id,
            'type' => $data['kind'],
            'status' => 'pending',
            'config' => ['url' => $data['url']],
        ]);

        CrawlSourceJob::dispatch($source->id)->onQueue('crawl');

        return $this->serialise($source, 201);
    }

    public function show(Request $request, string $sourceId): JsonResponse
    {
        $workspaceId = $request->attributes->get('workspace_id');
        abort_if($workspaceId === null, 401);

        $source = Source::query()->withoutGlobalScopes()
            ->whereIn(
                'agent_id',
                Agent::query()->withoutGlobalScopes()
                    ->where('workspace_id', $workspaceId)
                    ->pluck('id'),
            )
            ->where('id', $sourceId)
            ->first();

        if ($source === null) {
            return response()->json([
                'error' => [
                    'code' => 'source_not_found',
                    'message' => 'Source not found in this workspace.',
                ],
            ], 404);
        }

        return $this->serialise($source);
    }

    private function serialise(Source $source, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => [
                'id' => $source->id,
                'agent_id' => $source->agent_id,
                'kind' => $source->type,
                'status' => $source->status,
                'config' => $source->config,
                'last_synced_at' => $source->last_synced_at?->toIso8601String(),
                'created_at' => $source->created_at?->toIso8601String(),
            ],
        ], $status);
    }
}
