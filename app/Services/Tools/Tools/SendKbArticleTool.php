<?php

namespace App\Services\Tools\Tools;

use App\Models\Agent;
use App\Models\CuratedAnswer;
use App\Models\Workspace;
use App\Services\Tools\Contracts\HasIntentSignals;
use App\Services\Tools\Contracts\Tool;
use Illuminate\Support\Str;

/**
 * Returns a published Knowledge Base article URL the visitor can open
 * for the full write-up — and embeds the title + a short excerpt in
 * the chat reply via an inline `kb_article` block.
 *
 * The LLM passes a `slug` argument; the tool looks the article up
 * scoped to the agent first (operator-pinned), then any sibling
 * agent in the same workspace so multi-agent workspaces can share a
 * single canonical "support" agent's articles.
 */
class SendKbArticleTool implements HasIntentSignals, Tool
{
    public function name(): string
    {
        return 'send_kb_article';
    }

    public function description(): string
    {
        return 'Recommend a published Knowledge Base article. Pass the article slug; the visitor sees a card with the title + excerpt + a link to the full article.';
    }

    public function capability(): string
    {
        return 'kb_article_card';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slug' => [
                    'type' => 'string',
                    'description' => 'The article slug. Pull this from the curated answers you have in context.',
                ],
            ],
            'required' => ['slug'],
        ];
    }

    public function execute(array $args, Agent $agent, array $context = []): array
    {
        $slug = trim((string) ($args['slug'] ?? ''));
        if ($slug === '') {
            return [
                'result' => [
                    'success' => false,
                    'error' => 'slug is required',
                ],
            ];
        }

        $workspace = Workspace::query()->withoutGlobalScopes()->find($agent->workspace_id);
        if ($workspace === null) {
            return [
                'result' => [
                    'success' => false,
                    'error' => 'workspace not found',
                ],
            ];
        }

        // Agent-pinned first.
        $article = CuratedAnswer::query()
            ->withoutGlobalScopes()
            ->where('agent_id', $agent->id)
            ->where('slug', $slug)
            ->where('enabled', true)
            ->where('kb_published', true)
            ->first();

        if ($article === null) {
            // Workspace-wide fallback.
            $siblingAgentIds = Agent::query()
                ->withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->pluck('id');

            $article = CuratedAnswer::query()
                ->withoutGlobalScopes()
                ->whereIn('agent_id', $siblingAgentIds)
                ->where('slug', $slug)
                ->where('enabled', true)
                ->where('kb_published', true)
                ->first();
        }

        if ($article === null) {
            return [
                'result' => [
                    'success' => false,
                    'error' => 'article not found or not published',
                ],
            ];
        }

        $title = $article->displayTitle();
        $excerpt = Str::limit(
            (string) preg_replace('/\s+/u', ' ', strip_tags((string) $article->answer)),
            240,
        );
        $url = '/kb/'.rawurlencode((string) $workspace->slug).'/'.rawurlencode((string) $article->slug);

        return [
            'result' => [
                'success' => true,
                'slug' => $article->slug,
                'title' => $title,
                'excerpt' => $excerpt,
                'url' => $url,
            ],
            'block' => [
                'type' => 'kb_article',
                'payload' => [
                    'slug' => $article->slug,
                    'title' => $title,
                    'excerpt' => $excerpt,
                    'url' => $url,
                ],
            ],
        ];
    }

    /**
     * Knowledge-adjacent tool: no high-precision keywords exist, so the
     * keyword gate stays empty and routing relies on the embedding gate
     * with the conservative default threshold.
     *
     * @return list<string>
     */
    public function intentKeywords(): array
    {
        return [];
    }

    /** @return list<string> */
    public function intentExemplars(): array
    {
        return [
            'send me the guide for setting this up',
            'do you have an article about this',
            'share the documentation link please',
        ];
    }
}
