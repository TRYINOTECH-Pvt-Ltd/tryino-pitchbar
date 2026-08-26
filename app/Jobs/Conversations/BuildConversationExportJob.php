<?php

namespace App\Jobs\Conversations;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\ConversationExport;
use App\Models\Lead;
use App\Models\Message;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class BuildConversationExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public string $exportId) {}

    public function handle(): void
    {
        $export = ConversationExport::query()
            ->withoutWorkspaceScope()
            ->find($this->exportId);

        if ($export === null) {
            return;
        }

        $export->forceFill(['status' => ConversationExport::STATUS_PROCESSING])->save();

        try {
            $payload = $this->collect($export);
            $body = $export->format === 'json'
                ? $this->renderJson($payload)
                : $this->renderCsv($payload);

            $path = sprintf('exports/conversations/%s.%s', $export->id, $export->format);
            Storage::disk('local')->put($path, $body);

            $export->forceFill([
                'status' => ConversationExport::STATUS_READY,
                'file_path' => $path,
                'file_size' => strlen($body),
                'row_count' => count($payload),
                'expires_at' => now()->addDays(7),
                'completed_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            Log::error('conversation_export.failed', [
                'export_id' => $export->id,
                'error' => $e->getMessage(),
            ]);
            $export->forceFill([
                'status' => ConversationExport::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ])->save();
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collect(ConversationExport $export): array
    {
        $agentIds = Agent::query()
            ->withoutGlobalScopes()
            ->where('workspace_id', $export->workspace_id)
            ->pluck('id');

        if ($agentIds->isEmpty()) {
            return [];
        }

        $filters = $export->filters ?? [];

        $query = Conversation::query()
            ->withoutGlobalScopes()
            ->whereIn('agent_id', $agentIds);

        if (! empty($filters['agent_id']) && $agentIds->contains($filters['agent_id'])) {
            $query->where('agent_id', $filters['agent_id']);
        }

        if (! empty($filters['from'])) {
            $query->where('started_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('started_at', '<=', $filters['to']);
        }

        $rows = [];
        $query->orderBy('started_at')->chunkById(200, function ($conversations) use (&$rows): void {
            $ids = $conversations->pluck('id');
            $leads = Lead::query()
                ->withoutGlobalScopes()
                ->whereIn('conversation_id', $ids)
                ->get()
                ->keyBy('conversation_id');
            $messages = Message::query()
                ->whereIn('conversation_id', $ids)
                ->orderBy('created_at')
                ->get()
                ->groupBy('conversation_id');

            foreach ($conversations as $c) {
                $lead = $leads->get($c->id);
                $rows[] = [
                    'conversation_id' => $c->id,
                    'agent_id' => $c->agent_id,
                    'page_url' => $c->page_url,
                    'lang' => $c->lang,
                    'started_at' => $c->started_at?->toIso8601String(),
                    'ended_at' => $c->ended_at?->toIso8601String(),
                    'message_count' => (int) $c->message_count,
                    'is_lead' => (bool) $c->is_lead,
                    'satisfaction' => $c->satisfaction,
                    'satisfaction_comment' => $c->satisfaction_comment,
                    'lead_email' => $lead?->email,
                    'lead_phone' => $lead?->phone,
                    'lead_name' => $lead?->name,
                    'lead_status' => $lead?->status,
                    'messages' => ($messages->get($c->id) ?? collect())->map(fn (Message $m) => [
                        'role' => $m->role,
                        'content' => $m->content,
                        'created_at' => $m->created_at?->toIso8601String(),
                    ])->values()->all(),
                ];
            }
        });

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $payload
     */
    private function renderJson(array $payload): string
    {
        return (string) json_encode([
            'generated_at' => now()->toIso8601String(),
            'count' => count($payload),
            'conversations' => $payload,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  list<array<string, mixed>>  $payload
     */
    private function renderCsv(array $payload): string
    {
        $handle = fopen('php://temp', 'w+');
        $columns = [
            'conversation_id', 'agent_id', 'page_url', 'lang',
            'started_at', 'ended_at', 'message_count', 'is_lead',
            'satisfaction', 'satisfaction_comment',
            'lead_email', 'lead_phone', 'lead_name', 'lead_status',
            'first_user_message', 'last_assistant_message',
        ];
        fputcsv($handle, $columns);

        foreach ($payload as $row) {
            $messages = $row['messages'] ?? [];
            $firstUser = collect($messages)->firstWhere('role', 'user');
            $lastAssistant = collect($messages)->reverse()->firstWhere('role', 'assistant');

            fputcsv($handle, [
                $row['conversation_id'],
                $row['agent_id'],
                $row['page_url'],
                $row['lang'],
                $row['started_at'],
                $row['ended_at'],
                $row['message_count'],
                $row['is_lead'] ? '1' : '0',
                $row['satisfaction'],
                $row['satisfaction_comment'],
                $row['lead_email'],
                $row['lead_phone'],
                $row['lead_name'],
                $row['lead_status'],
                $firstUser['content'] ?? null,
                $lastAssistant['content'] ?? null,
            ]);
        }

        rewind($handle);
        $out = stream_get_contents($handle);
        fclose($handle);

        return $out !== false ? $out : '';
    }
}
