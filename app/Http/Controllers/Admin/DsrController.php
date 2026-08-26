<?php

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use App\Models\DsrRequest;
use App\Models\Visitor;
use App\Services\Gdpr\Eraser;
use App\Services\Gdpr\Exporter;
use App\Services\Gdpr\VisitorResolver;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DsrController
{
    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly VisitorResolver $resolver,
        private readonly Exporter $exporter,
        private readonly Eraser $eraser,
    ) {}

    public function lookup(Request $request): JsonResponse
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        $request->user()->can('manageMembers', $workspace) || abort(403);

        $data = $request->validate([
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'visitor_id' => ['nullable', 'string', 'max:36'],
            'anonymous_id' => ['nullable', 'string', 'max:64'],
        ]);

        if (empty(array_filter($data, fn ($v) => is_string($v) && trim($v) !== ''))) {
            throw ValidationException::withMessages([
                'email' => 'Provide at least one of email, visitor_id, or anonymous_id.',
            ]);
        }

        $visitors = $this->resolver->resolve($workspace, $data);

        return response()->json([
            'matches' => $visitors->map(fn (Visitor $v) => [
                'visitor_id' => $v->id,
                'anonymous_id' => $v->anonymous_id,
                'country' => $v->country,
                'first_seen_at' => $v->first_seen_at?->toIso8601String(),
                'last_seen_at' => $v->last_seen_at?->toIso8601String(),
                'visit_count' => $v->visit_count,
            ])->values(),
            'count' => $visitors->count(),
        ]);
    }

    public function export(Request $request): JsonResponse
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        $request->user()->can('manageMembers', $workspace) || abort(403);

        $data = $request->validate([
            'visitor_id' => ['required', 'string', 'max:36'],
        ]);

        $visitors = $this->resolver->resolve($workspace, ['visitor_id' => $data['visitor_id']]);
        $visitor = $visitors->first();

        if ($visitor === null) {
            abort(404, 'Visitor not found in this workspace.');
        }

        $payload = $this->exporter->export($visitor);

        $dsr = DB::transaction(function () use ($workspace, $request, $visitor, $payload) {
            $row = DsrRequest::create([
                'workspace_id' => $workspace->id,
                'action' => DsrRequest::ACTION_EXPORT,
                'lookup_visitor_id' => $visitor->id,
                'source' => DsrRequest::SOURCE_ADMIN,
                'requested_by_user_id' => $request->user()->id,
                'status' => DsrRequest::STATUS_COMPLETED,
                'matched_visitor_ids' => [$visitor->id],
                'result_payload' => $payload,
                'completed_at' => now(),
            ]);

            AuditLog::create([
                'workspace_id' => $workspace->id,
                'user_id' => $request->user()->id,
                'action' => 'dsr.exported',
                'entity_type' => 'dsr_request',
                'entity_id' => $row->id,
                'before' => [],
                'after' => ['visitor_id' => $visitor->id],
                'ip' => $request->ip(),
                'ua' => substr((string) $request->userAgent(), 0, 240),
                'created_at' => now(),
            ]);

            return $row;
        });

        return response()->json([
            'dsr_request_id' => $dsr->id,
            'payload' => $payload,
        ]);
    }

    public function erase(Request $request): JsonResponse
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        $request->user()->can('manageMembers', $workspace) || abort(403);

        $data = $request->validate([
            'visitor_id' => ['required', 'string', 'max:36'],
            'confirm_typed' => ['required', 'string', 'in:ERASE'],
        ]);

        $visitors = $this->resolver->resolve($workspace, ['visitor_id' => $data['visitor_id']]);
        $visitor = $visitors->first();

        if ($visitor === null) {
            abort(404, 'Visitor not found in this workspace.');
        }

        $visitorId = $visitor->id;
        $this->eraser->erase($visitor);

        $dsr = DsrRequest::create([
            'workspace_id' => $workspace->id,
            'action' => DsrRequest::ACTION_DELETE,
            'lookup_visitor_id' => $visitorId,
            'source' => DsrRequest::SOURCE_ADMIN,
            'requested_by_user_id' => $request->user()->id,
            'status' => DsrRequest::STATUS_COMPLETED,
            'matched_visitor_ids' => [$visitorId],
            'completed_at' => now(),
        ]);

        AuditLog::create([
            'workspace_id' => $workspace->id,
            'user_id' => $request->user()->id,
            'action' => 'dsr.erased',
            'entity_type' => 'dsr_request',
            'entity_id' => $dsr->id,
            'before' => ['visitor_id' => $visitorId],
            'after' => [],
            'ip' => $request->ip(),
            'ua' => substr((string) $request->userAgent(), 0, 240),
            'created_at' => now(),
        ]);

        return response()->json([
            'dsr_request_id' => $dsr->id,
            'status' => 'completed',
        ]);
    }
}
