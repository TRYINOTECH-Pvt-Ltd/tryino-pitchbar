<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\CuratedAnswer;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Public-facing Knowledge Base. Curated answers an operator marks
 * `kb_published=true` become citable help-center pages at
 * /kb/{workspace-slug}/{article-slug}. The workspace's own brand
 * stamps the page; nothing about Pitchbar leaks unless the operator
 * is on a plan without branding removal.
 *
 * Rate-limited per IP so a scraper can't drain the whole catalogue.
 */
class KnowledgeBaseController extends Controller
{
    public function index(Request $request, string $workspaceSlug): View
    {
        $this->throttle($request);

        $workspace = $this->workspaceOr404($workspaceSlug);
        $articles = $this->publishedArticlesFor($workspace);

        return view('kb.index', [
            'workspace' => $workspace,
            'articles' => $articles,
            'site_title' => (string) ($workspace->name ?? config('app.name')),
        ]);
    }

    public function show(Request $request, string $workspaceSlug, string $articleSlug): View
    {
        $this->throttle($request);

        $workspace = $this->workspaceOr404($workspaceSlug);
        $article = $this->publishedArticlesFor($workspace)
            ->firstWhere('slug', $articleSlug);

        abort_if($article === null, 404);

        return view('kb.show', [
            'workspace' => $workspace,
            'article' => $article,
            'site_title' => (string) ($workspace->name ?? config('app.name')),
        ]);
    }

    /**
     * @return Collection<int, CuratedAnswer>
     */
    private function publishedArticlesFor(Workspace $workspace)
    {
        $agentIds = Agent::query()
            ->withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('is_published', true)
            ->pluck('id');

        return CuratedAnswer::query()
            ->withoutGlobalScopes()
            ->whereIn('agent_id', $agentIds)
            ->where('enabled', true)
            ->where('kb_published', true)
            ->whereNotNull('slug')
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(500)
            ->get();
    }

    private function workspaceOr404(string $slug): Workspace
    {
        $workspace = Workspace::query()
            ->withoutGlobalScopes()
            ->where('slug', $slug)
            ->first();

        abort_if($workspace === null, 404);

        return $workspace;
    }

    private function throttle(Request $request): void
    {
        $key = 'kb:'.($request->ip() ?? 'anon');
        if (RateLimiter::tooManyAttempts($key, 120)) {
            abort(429);
        }
        RateLimiter::hit($key, 60);
    }
}
