<?php

namespace App\Listeners;

use App\Actions\Agents\PublishAgent;
use App\Models\Agent;
use App\Models\Source;
use Illuminate\Support\Facades\DB;

/**
 * The first time any source for an agent finishes indexing, auto-publish
 * the agent if it's still a draft. This removes a manual click for the
 * common happy-path: register → URL crawls → indexed → live.
 */
class AutoPublishOnFirstIndex
{
    public function __construct(private PublishAgent $publish) {}

    public function handle(Source $source): void
    {
        if ($source->status !== 'indexed') {
            return;
        }

        // Race condition guard. Two sources finishing indexing within
        // the same tick (sitemap fan-out, parallel queue workers) both
        // pass the `is_published=false` check and both pass the
        // `hasPriorIndexedSource=false` check (each excludes itself,
        // neither sees the sibling yet). Both then call publish →
        // duplicate AgentVersion rows + "agent.published" audit fired
        // twice. Wrap the read-modify-write in a transaction with a
        // row-level lock on the agent; the second listener blocks
        // until the first commits, then sees is_published=true and
        // bails on line 79.
        //
        // CLAUDE.md §2 justification for `withoutGlobalScopes()`:
        // queue listener runs without an authenticated request, so
        // CurrentWorkspace resolves to null and the
        // BelongsToAgent/BelongsToWorkspace scopes can't bind. The
        // agent is identified by the source's own agent_id (which
        // implies workspace via the source row); no cross-tenant
        // read path.
        DB::transaction(function () use ($source) {
            $agent = Agent::query()
                ->withoutGlobalScopes()
                ->whereKey($source->agent_id)
                ->lockForUpdate()
                ->first();

            if ($agent === null || $agent->is_published) {
                return;
            }

            // CLAUDE.md §2: explicit `agent_id =` clause carries the
            // tenancy guarantee — Source.agent_id is workspace-scoped.
            // Source has no SoftDeletes so any prior source row that
            // exists is by definition still in the table; a deleted
            // prior source is gone and the "is this the first index?"
            // question is correctly answered by row existence alone.
            $hasPriorIndexedSource = Source::query()
                ->withoutWorkspaceScope()
                ->where('agent_id', $agent->id)
                ->where('id', '!=', $source->id)
                ->where('status', 'indexed')
                ->exists();

            if ($hasPriorIndexedSource) {
                return;
            }

            $this->publish->handle($agent);
        });
    }
}
