<?php

namespace App\Http\Controllers\Admin;

use App\Jobs\Conversations\BuildConversationExportJob;
use App\Models\ConversationExport;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConversationExportController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        $request->user()->can('manageMembers', $workspace) || abort(403);

        $exports = ConversationExport::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('created_at')
            ->limit(25)
            ->get()
            ->map(fn (ConversationExport $e) => [
                'id' => $e->id,
                'format' => $e->format,
                'status' => $e->status,
                'filters' => $e->filters,
                'row_count' => $e->row_count,
                'file_size' => $e->file_size,
                'completed_at' => $e->completed_at?->toIso8601String(),
                'expires_at' => $e->expires_at?->toIso8601String(),
                'error' => $e->error,
                'download_url' => $e->status === ConversationExport::STATUS_READY
                    ? route('conversations.export.download', ['conversationExport' => $e->id])
                    : null,
            ]);

        return response()->json(['exports' => $exports]);
    }

    public function store(Request $request): JsonResponse
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        $request->user()->can('manageMembers', $workspace) || abort(403);

        $data = $request->validate([
            'format' => ['required', 'in:csv,json'],
            'agent_id' => ['nullable', 'string', 'max:36'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $export = ConversationExport::create([
            'workspace_id' => $workspace->id,
            'requested_by_user_id' => $request->user()->id,
            'format' => $data['format'],
            'filters' => array_filter([
                'agent_id' => $data['agent_id'] ?? null,
                'from' => $data['from'] ?? null,
                'to' => $data['to'] ?? null,
            ]),
            'status' => ConversationExport::STATUS_PENDING,
        ]);

        BuildConversationExportJob::dispatch($export->id);

        return response()->json([
            'id' => $export->id,
            'status' => $export->status,
        ], 202);
    }

    public function download(Request $request, ConversationExport $conversationExport): Response|StreamedResponse
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        abort_unless($conversationExport->workspace_id === $workspace->id, 404);
        $request->user()->can('manageMembers', $workspace) || abort(403);

        abort_unless($conversationExport->status === ConversationExport::STATUS_READY, 409, 'Export not ready.');
        abort_if($conversationExport->expires_at !== null && $conversationExport->expires_at->isPast(), 410, 'Export expired.');

        $disk = Storage::disk('local');
        abort_unless($disk->exists($conversationExport->file_path), 404, 'Export file missing.');

        $mime = $conversationExport->format === 'json' ? 'application/json' : 'text/csv';
        $filename = sprintf('conversations-%s.%s', $conversationExport->id, $conversationExport->format);

        return $disk->download($conversationExport->file_path, $filename, [
            'Content-Type' => $mime,
        ]);
    }
}
