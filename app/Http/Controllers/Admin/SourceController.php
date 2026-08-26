<?php

namespace App\Http\Controllers\Admin;

use App\Jobs\Crawl\CrawlPageJob;
use App\Jobs\Crawl\CrawlSourceJob;
use App\Jobs\Crawl\IndexDocumentJob;
use App\Jobs\Crawl\IndexTextSourceJob;
use App\Jobs\Crawl\IngestGoogleDocJob;
use App\Jobs\Crawl\IngestNotionPageJob;
use App\Jobs\Crawl\SyncGoogleSheetJob;
use App\Jobs\Crawl\SyncSqlSourceJob;
use App\Models\Agent;
use App\Models\Document;
use App\Models\IntegrationConnection;
use App\Models\Source;
use App\Models\Workspace;
use App\Services\Billing\PlanLimits;
use App\Services\Crawl\SiteDiscoverer;
use App\Services\Crawl\SourceRetrier;
use App\Services\Integrations\Google\GoogleClient;
use App\Services\Integrations\Google\GoogleException;
use App\Services\Integrations\Google\GoogleTokenStore;
use App\Services\Integrations\Google\SheetsClient;
use App\Support\AuditLogger;
use App\Support\Pagination;
use App\Support\SourceErrorPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class SourceController
{
    public function __construct(
        private readonly PlanLimits $limits,
        private readonly SourceRetrier $retrier,
    ) {}

    /**
     * Centralised "can this workspace add another source?" check.
     * Every storeX endpoint calls this before the Source::create row
     * is written so the plan cap is enforced no matter which add-source
     * tab the admin used.
     */
    private function enforceSourceLimit(Agent $agent): ?RedirectResponse
    {
        $workspace = Workspace::query()->find($agent->workspace_id);
        if ($workspace === null) {
            return null;
        }

        $check = $this->limits->check($workspace, PlanLimits::RESOURCE_SOURCE);
        if (! $check['allowed']) {
            return back()->with('error', $this->limits->reasonFor(PlanLimits::RESOURCE_SOURCE, (int) $check['limit']));
        }

        return null;
    }

    public function index(Request $request, Agent $agent): Response
    {
        $request->user()->can('view', $agent) || abort(403);

        $q = trim((string) $request->query('q', ''));

        $sourcesQuery = $agent->sources()->latest();

        if ($q !== '') {
            // Sources don't have a `title` column themselves — searchable
            // text lives in `config` (JSON) and on related Documents. We
            // match on a few common config fields plus the type, and on
            // any indexed Document URL/title for the source.
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
            $sourcesQuery->where(function ($w) use ($like) {
                $w->where('type', 'like', $like)
                    ->orWhere('status', 'like', $like)
                    ->orWhere('config', 'like', $like)
                    ->orWhereIn('id', function ($sub) use ($like) {
                        $sub->select('source_id')
                            ->from('documents')
                            ->where(function ($d) use ($like) {
                                $d->where('url', 'like', $like)
                                    ->orWhere('title', 'like', $like);
                            });
                    });
            });
        }

        $paginator = $sourcesQuery->paginate(25)->withQueryString();
        $sourceIds = collect($paginator->items())->pluck('id');

        // Pre-load the latest Document per source so we can show real titles
        // (Notion page name, Google Doc name) instead of the raw scheme URL
        // we store internally.
        $titlesBySource = Document::query()->withoutWorkspaceScope()
            ->whereIn('source_id', $sourceIds)
            ->select(['source_id', 'title', 'url'])
            ->orderByDesc('fetched_at')
            ->get()
            ->groupBy('source_id')
            ->map(fn ($group) => $group->first());

        $sources = collect($paginator->items())->map(fn (Source $s) => [
            'id' => $s->id,
            'type' => $s->type,
            'status' => $s->status,
            'config' => $s->config,
            'last_synced_at' => $s->last_synced_at?->toIso8601String(),
            // `error` is the customer-safe presenter output. The raw
            // upstream string (auth headers, JSON bodies, vendor names)
            // stays in `error_raw` for operator log-spelunking + support
            // attachments — not shown directly in the list UI.
            'error' => SourceErrorPresenter::present($s->error),
            'error_raw' => $s->error,
            'created_at' => $s->created_at?->toIso8601String(),
            'progress' => $this->progressFor($s),
            'display' => $this->displayFor($s, $titlesBySource->get($s->id)),
            'is_global' => (bool) $s->is_global,
        ]);

        // Workspace integration state surfaced to the Sources page so
        // the Notion + Google Docs tabs can show "connect first" hints
        // up-front instead of letting the operator paste a URL and hit
        // a server-side validation error. Buyer report 2026-05-29:
        // "no Google Docs tab" — the tab is now present but the UI
        // gates it on this flag so the buyer's mental model maps
        // cleanly onto the Integrations page.
        $integrations = IntegrationConnection::query()
            ->where('workspace_id', $agent->workspace_id)
            ->where('status', 'active')
            ->whereIn('kind', ['google', 'notion'])
            ->pluck('kind')
            ->all();

        return Inertia::render('app/agents/sources', [
            'agent' => $agent->only('id', 'name'),
            'sources' => $sources,
            'pagination' => Pagination::meta($paginator),
            'filters' => ['q' => $q],
            'integrations' => [
                'google' => in_array('google', $integrations, true),
                'notion' => in_array('notion', $integrations, true),
            ],
        ]);
    }

    /**
     * Toggle a source's "answer on every page" flag. Global sources
     * (contact details, address, opening hours) get the current-page
     * boost from any page during retrieval, so their facts are reachable
     * everywhere — not walled to the one page they were crawled from.
     * Instant: retrieval reads the DB flag and the toggle bumps the
     * per-agent global cache version so stale cached answers clear at once.
     */
    public function updateGlobal(Request $request, Source $source): RedirectResponse
    {
        $agent = $source->agent()->withoutWorkspaceScope()->firstOrFail();
        $request->user()->can('update', $agent) || abort(403);

        $data = $request->validate([
            'is_global' => ['required', 'boolean'],
        ]);

        $source->update(['is_global' => $data['is_global']]);
        Source::forgetGlobalCache($source->agent_id);

        return back()->with('success', $data['is_global']
            ? 'This source now answers on every page.'
            : 'This source no longer answers on every page.');
    }

    /**
     * Picks a human-friendly label + sublabel for the Sources list. For URL
     * sources the URL is already friendly. For Notion / Google Doc sources
     * we surface the indexed Document's title plus the source kind.
     *
     * @return array{title: string, subtitle: string, link: ?string}
     */
    private function displayFor(Source $source, ?Document $latestDoc): array
    {
        $config = (array) ($source->config ?? []);

        if ($source->type === 'notion') {
            return [
                'title' => $latestDoc?->title ?: 'Notion page',
                'subtitle' => 'Notion · '.($config['notion_page_id'] ?? ''),
                'link' => null,
            ];
        }

        if ($source->type === 'google_doc') {
            $fileId = $config['google_file_id'] ?? '';

            return [
                'title' => $latestDoc?->title ?: 'Google Doc',
                'subtitle' => 'Google Doc',
                'link' => $fileId !== '' ? "https://docs.google.com/document/d/{$fileId}/edit" : null,
            ];
        }

        if ($source->type === 'google_sheet') {
            $spreadsheetId = $config['spreadsheet_id'] ?? '';
            $sheetTitle = $config['sheet_title'] ?? '';
            $link = $spreadsheetId !== ''
                ? "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/edit"
                : null;

            return [
                'title' => $latestDoc?->title ?: ($sheetTitle ?: 'Google Sheet'),
                'subtitle' => $sheetTitle !== ''
                    ? "Google Sheet · {$sheetTitle}"
                    : 'Google Sheet',
                'link' => $link,
            ];
        }

        if ($source->type === 'text') {
            $sourceUrl = $config['source_url'] ?? null;

            return [
                'title' => $latestDoc?->title ?: ($config['title'] ?? 'Pasted content'),
                'subtitle' => $sourceUrl ? 'Pasted from '.parse_url($sourceUrl, PHP_URL_HOST) : 'Pasted text',
                'link' => is_string($sourceUrl) ? $sourceUrl : null,
            ];
        }

        if ($source->type === 'file') {
            // Uploaded files have no URL by definition. Use the
            // captured filename list (one entry per file in the batch)
            // instead of the "(no url)" placeholder buyer reported.
            $filenames = (array) ($config['filenames'] ?? []);
            $first = is_string($filenames[0] ?? null) ? $filenames[0] : null;
            $count = count($filenames);

            return [
                'title' => $first ?? 'Uploaded file',
                'subtitle' => $count > 1
                    ? "Uploaded file · {$count} files in batch"
                    : 'Uploaded file',
                'link' => null,
            ];
        }

        if ($source->type === 'sql') {
            // SQL database source — surface the configured driver +
            // a friendly title instead of falling through to "(no url)".
            $title = $config['title'] ?? 'SQL database';
            $driver = strtoupper((string) ($config['driver'] ?? ''));

            return [
                'title' => $title,
                'subtitle' => $driver !== '' ? "SQL · {$driver}" : 'SQL database',
                'link' => null,
            ];
        }

        if ($source->type === 'auto') {
            // The "Auto-indexed from visitors" bucket — one row per agent
            // collecting every page a visitor landed on that we hadn't
            // crawled yet. No single canonical URL, so we link to the
            // most recently captured one.
            return [
                'title' => 'Auto-indexed from visitors',
                'subtitle' => 'Pages added automatically as visitors browse',
                'link' => $latestDoc?->url,
            ];
        }

        $url = $config['url'] ?? '';

        return [
            'title' => $url !== '' ? $url : '(no url)',
            'subtitle' => $source->type,
            'link' => $url !== '' ? $url : null,
        ];
    }

    public function store(Request $request, Agent $agent): RedirectResponse
    {
        $request->user()->can('update', $agent) || abort(403);

        if ($redirect = $this->enforceSourceLimit($agent)) {
            return $redirect;
        }

        $data = $request->validate([
            'type' => ['required', 'in:url,sitemap,feed'],
            'url' => ['required', 'url', 'max:2000'],
        ]);

        // Google Docs / Sheets links pasted into the URL form would go to
        // the web crawler, which cannot read docs.google.com (a JS app
        // behind Google's bot protection) — the source would fail forever
        // with a cryptic error (client report 2026-07-04). Absorb the
        // mistake: document links become a proper Google Doc source when
        // Google is connected; otherwise (and for sheets, which need a
        // tab name) guide the user to the right place.
        if (str_contains(strtolower($data['url']), 'docs.google.com')) {
            return $this->absorbGoogleUrl($request, $agent, $data['url']);
        }

        if ($data['type'] === 'sitemap'
            && ! preg_match('/\.xml($|\?)/i', $data['url'])
            && ! str_contains(strtolower($data['url']), 'sitemap')) {
            return back()->withErrors([
                'url' => 'Sitemap URL should end in .xml or contain "sitemap" in the path. For a single page, choose "Single URL" instead.',
            ])->withInput();
        }

        $source = Source::create([
            'agent_id' => $agent->id,
            'type' => $data['type'],
            'status' => 'pending',
            'config' => ['url' => $data['url']],
        ]);

        AuditLogger::log(
            workspaceId: $agent->workspace_id,
            action: 'source.created',
            entityType: 'source',
            entityId: $source->id,
            after: ['type' => $source->type, 'url' => $data['url']],
            request: $request,
        );

        CrawlSourceJob::dispatch($source->id)->onQueue('crawl');

        return back()->with('success', 'Source added; crawl started.');
    }

    /**
     * A docs.google.com link landed in the plain-URL form. The web
     * crawler can never read it, so either transparently create the
     * proper Google Doc source (Google connected + it's a document
     * link) or send the user to the tab that can.
     */
    private function absorbGoogleUrl(Request $request, Agent $agent, string $url): RedirectResponse
    {
        if (str_contains(strtolower($url), '/spreadsheets/')) {
            return back()->withErrors([
                'url' => 'This is a Google Sheet. Add it via the Google Sheet tab (it needs the sheet/tab name) instead of as a website URL.',
            ])->withInput();
        }

        $fileId = $this->normalizeGoogleFileId($url);
        if ($fileId === null) {
            return back()->withErrors([
                'url' => 'This looks like a Google Docs link, but no document id was found in it. Copy the URL from the browser address bar while the doc is open.',
            ])->withInput();
        }

        $hasConnection = IntegrationConnection::query()
            ->where('workspace_id', $agent->workspace_id)
            ->where('kind', 'google')
            ->where('status', 'active')
            ->exists();

        if (! $hasConnection) {
            return back()->withErrors([
                'url' => 'This is a Google Doc — the web crawler cannot read it. Connect Google under Integrations, then add it via the Google Docs tab.',
            ])->withInput();
        }

        $source = Source::create([
            'agent_id' => $agent->id,
            'type' => 'google_doc',
            'status' => 'pending',
            'config' => ['google_file_id' => $fileId],
        ]);

        AuditLogger::log(
            workspaceId: $agent->workspace_id,
            action: 'source.created',
            entityType: 'source',
            entityId: $source->id,
            after: ['type' => 'google_doc', 'google_file_id' => $fileId, 'absorbed_from' => 'url_form'],
            request: $request,
        );

        IngestGoogleDocJob::dispatch($source->id)->onQueue('crawl');

        return back()->with('success', 'That link is a Google Doc — added it as a Google Doc source and queued it for indexing.');
    }

    /**
     * Plain-text paste source — the bulletproof escape hatch when crawling
     * a URL doesn't work (anti-bot challenges, JS-only SPAs, paywalls).
     * The user just dumps the content into a textarea; we skip the crawler
     * entirely and feed it straight into the index pipeline.
     */
    /**
     * Add a SQL database (MySQL or Postgres) as a knowledge source.
     * Owner pastes connection details + a read-only SELECT; we encrypt
     * the credentials at-rest and run the saved query on a schedule
     * via SyncSqlSourceJob. The connector enforces SELECT-only +
     * read-only-transaction + SSRF allowlist.
     */
    public function storeSql(Request $request, Agent $agent): RedirectResponse
    {
        $request->user()->can('update', $agent) || abort(403);

        if ($redirect = $this->enforceSourceLimit($agent)) {
            return $redirect;
        }

        $data = $request->validate([
            'driver' => ['required', 'in:mysql,pgsql'],
            'host' => ['required', 'string', 'max:253'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'max:120'],
            'password' => ['nullable', 'string', 'max:512'],
            'query' => ['required', 'string', 'min:10', 'max:8000'],
            'title' => ['nullable', 'string', 'max:120'],
            'title_column' => ['nullable', 'string', 'max:120'],
            'body_column' => ['nullable', 'string', 'max:120'],
        ]);

        if (! preg_match('/^select\b/i', trim((string) $data['query']))) {
            return back()->withErrors([
                'query' => 'Query must start with SELECT (read-only).',
            ])->withInput();
        }

        $source = Source::create([
            'agent_id' => $agent->id,
            'type' => 'sql',
            'status' => 'pending',
            'config' => [
                'driver' => $data['driver'],
                'query' => $data['query'],
                'title' => $data['title'] ?? sprintf('%s database', strtoupper($data['driver'])),
                'title_column' => $data['title_column'] ?? null,
                'body_column' => $data['body_column'] ?? null,
            ],
            'credentials_encrypted' => [
                'driver' => $data['driver'],
                'host' => $data['host'],
                'port' => (int) $data['port'],
                'database' => $data['database'],
                'username' => $data['username'],
                'password' => (string) ($data['password'] ?? ''),
            ],
        ]);
        SyncSqlSourceJob::dispatch($source->id)->onQueue('crawl');

        return back()->with('success', 'SQL database queued for indexing.');
    }

    public function storeText(Request $request, Agent $agent): RedirectResponse
    {
        $request->user()->can('update', $agent) || abort(403);

        if ($redirect = $this->enforceSourceLimit($agent)) {
            return $redirect;
        }

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:250'],
            'body' => ['required', 'string', 'min:50', 'max:200000'],
            'source_url' => ['nullable', 'url', 'max:2000'],
        ]);

        $title = $data['title'] ?? mb_substr(trim((string) preg_replace('/\s+/u', ' ', $data['body'])), 0, 80);

        $source = Source::create([
            'agent_id' => $agent->id,
            'type' => 'text',
            'status' => 'pending',
            'config' => [
                'title' => $title,
                'source_url' => $data['source_url'] ?? null,
                // Persist the body so the operator can edit it later
                // (updateText prefills from here). It only ever lived in
                // the embedded chunks before, which can't be edited.
                'body' => $data['body'],
            ],
        ]);

        IndexTextSourceJob::dispatch($source->id, $title, $data['body'], $data['source_url'] ?? null)
            ->onQueue('index');

        return back()->with('success', 'Content added — indexing now.');
    }

    /**
     * Update a pasted-text source in place — edit the title / body / source
     * URL and re-index, instead of forcing a delete-and-recreate. The new
     * body replaces the old (IndexTextSourceJob purges the prior document +
     * vectors on re-run).
     */
    public function updateText(Request $request, Source $source): RedirectResponse
    {
        $request->user()->can('update', $source) || abort(403);
        abort_unless($source->type === 'text', 404);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:250'],
            'body' => ['required', 'string', 'min:50', 'max:200000'],
            'source_url' => ['nullable', 'url', 'max:2000'],
        ]);

        $title = $data['title'] ?? mb_substr(trim((string) preg_replace('/\s+/u', ' ', $data['body'])), 0, 80);

        $source->forceFill([
            'status' => 'pending',
            'error' => null,
            'config' => [
                'title' => $title,
                'source_url' => $data['source_url'] ?? null,
                'body' => $data['body'],
            ],
        ])->save();

        IndexTextSourceJob::dispatch($source->id, $title, $data['body'], $data['source_url'] ?? null)
            ->onQueue('index');

        return back()->with('success', 'Content updated — re-indexing now.');
    }

    /**
     * Add a Notion page as a source. Accepts either a notion.so URL
     * (we extract the trailing 32-char id) or a raw page ID.
     *
     * The workspace must already have an active 'notion' IntegrationConnection
     * for this to be useful — otherwise the ingest job will fail with a
     * helpful "Notion is not connected" error and the source flips to
     * status='failed'.
     */
    public function storeNotion(Request $request, Agent $agent): RedirectResponse
    {
        $request->user()->can('update', $agent) || abort(403);

        if ($redirect = $this->enforceSourceLimit($agent)) {
            return $redirect;
        }

        $data = $request->validate([
            'page' => ['required', 'string', 'max:500'],
        ]);

        $pageId = $this->normalizeNotionPageId($data['page']);
        if ($pageId === null) {
            return back()->withErrors(['page' => 'Could not find a Notion page id in that URL.'])->withInput();
        }

        $hasConnection = IntegrationConnection::query()
            ->where('workspace_id', $agent->workspace_id)
            ->where('kind', 'notion')
            ->where('status', 'active')
            ->exists();

        if (! $hasConnection) {
            return back()->withErrors([
                'page' => 'Notion is not connected for this workspace. Connect it in Integrations first.',
            ])->withInput();
        }

        $source = Source::create([
            'agent_id' => $agent->id,
            'type' => 'notion',
            'status' => 'pending',
            'config' => ['notion_page_id' => $pageId],
        ]);
        IngestNotionPageJob::dispatch($source->id)->onQueue('crawl');

        return back()->with('success', 'Notion page queued for indexing.');
    }

    /**
     * Add a Google Doc as a source. Accepts a docs.google.com/document/d/{id}
     * URL or a raw file id.
     */
    public function storeGoogleDoc(Request $request, Agent $agent): RedirectResponse
    {
        $request->user()->can('update', $agent) || abort(403);

        if ($redirect = $this->enforceSourceLimit($agent)) {
            return $redirect;
        }

        $data = $request->validate([
            'doc' => ['required', 'string', 'max:500'],
        ]);

        $fileId = $this->normalizeGoogleFileId($data['doc']);
        if ($fileId === null) {
            return back()->withErrors(['doc' => 'Could not find a Google Doc id in that URL.'])->withInput();
        }

        $hasConnection = IntegrationConnection::query()
            ->where('workspace_id', $agent->workspace_id)
            ->where('kind', 'google')
            ->where('status', 'active')
            ->exists();

        if (! $hasConnection) {
            return back()->withErrors([
                'doc' => 'Google is not connected for this workspace. Connect it in Integrations first.',
            ])->withInput();
        }

        $source = Source::create([
            'agent_id' => $agent->id,
            'type' => 'google_doc',
            'status' => 'pending',
            'config' => ['google_file_id' => $fileId],
        ]);
        IngestGoogleDocJob::dispatch($source->id)->onQueue('crawl');

        return back()->with('success', 'Google Doc queued for indexing.');
    }

    private function normalizeGoogleFileId(string $input): ?string
    {
        // URL form: https://docs.google.com/document/d/{file_id}/edit
        if (preg_match('#/document/d/([a-zA-Z0-9_-]+)#', $input, $m) === 1) {
            return $m[1];
        }
        // Raw id (Google file IDs are typically 25-44 base64url-safe chars).
        if (preg_match('/^[a-zA-Z0-9_-]{25,}$/', trim($input)) === 1) {
            return trim($input);
        }

        return null;
    }

    private function normalizeGoogleSpreadsheetId(string $input): ?string
    {
        // URL form: https://docs.google.com/spreadsheets/d/{id}/edit
        if (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $input, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/^[a-zA-Z0-9_-]{25,}$/', trim($input)) === 1) {
            return trim($input);
        }

        return null;
    }

    /**
     * Probe the spreadsheet — returns the workbook title plus every sheet
     * tab inside it so the React picker can let the user pick a specific
     * tab (Google's API needs a tab name, not just a workbook id).
     */
    public function googleSheetMetadata(
        Request $request,
        Agent $agent,
        GoogleClient $client,
        GoogleTokenStore $tokenStore,
        SheetsClient $sheets,
    ): JsonResponse {
        $request->user()->can('update', $agent) || abort(403);

        $data = $request->validate([
            'spreadsheet' => ['required', 'string', 'max:500'],
        ]);

        $spreadsheetId = $this->normalizeGoogleSpreadsheetId($data['spreadsheet']);
        if ($spreadsheetId === null) {
            return response()->json([
                'ok' => false,
                'error' => 'Could not find a Google Sheets id in that URL.',
            ], 422);
        }

        $connection = IntegrationConnection::query()
            ->where('workspace_id', $agent->workspace_id)
            ->where('kind', 'google')
            ->where('status', 'active')
            ->first();

        if (! $connection) {
            return response()->json([
                'ok' => false,
                'error' => 'Google is not connected for this workspace. Connect it in Integrations first.',
            ], 422);
        }

        try {
            $accessToken = $tokenStore->activeAccessToken($connection);
            $metadata = $sheets->metadata($spreadsheetId, $accessToken);
        } catch (GoogleException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'spreadsheet_id' => $spreadsheetId,
            'title' => $metadata['title'],
            'sheets' => $metadata['sheets'],
        ]);
    }

    /**
     * Persist a Google Sheets source. Requires `spreadsheet` (URL or raw
     * id) + `sheet_title` (the tab name from the metadata probe).
     */
    public function storeGoogleSheet(Request $request, Agent $agent): RedirectResponse
    {
        $request->user()->can('update', $agent) || abort(403);

        if ($redirect = $this->enforceSourceLimit($agent)) {
            return $redirect;
        }

        $data = $request->validate([
            'spreadsheet' => ['required', 'string', 'max:500'],
            'sheet_title' => ['required', 'string', 'max:255'],
        ]);

        $spreadsheetId = $this->normalizeGoogleSpreadsheetId($data['spreadsheet']);
        if ($spreadsheetId === null) {
            return back()
                ->withErrors(['spreadsheet' => 'Could not find a Google Sheets id in that URL.'])
                ->withInput();
        }

        $hasConnection = IntegrationConnection::query()
            ->where('workspace_id', $agent->workspace_id)
            ->where('kind', 'google')
            ->where('status', 'active')
            ->exists();

        if (! $hasConnection) {
            return back()->withErrors([
                'spreadsheet' => 'Google is not connected for this workspace. Connect it in Integrations first.',
            ])->withInput();
        }

        $source = Source::create([
            'agent_id' => $agent->id,
            'type' => 'google_sheet',
            'status' => 'pending',
            'config' => [
                'spreadsheet_id' => $spreadsheetId,
                'sheet_title' => $data['sheet_title'],
            ],
        ]);
        SyncGoogleSheetJob::dispatch($source->id)->onQueue('crawl');

        return back()->with('success', 'Google Sheet queued for indexing.');
    }

    private function normalizeNotionPageId(string $input): ?string
    {
        // Accept raw 32-char id, or the URL form ".../page-name-{32-char-id}".
        $hex = preg_replace('/[^a-f0-9]/i', '', $input) ?? '';
        if (strlen($hex) >= 32) {
            $tail = substr($hex, -32);

            // Format with hyphens: 8-4-4-4-12
            return substr($tail, 0, 8).'-'.substr($tail, 8, 4).'-'.substr($tail, 12, 4).'-'.substr($tail, 16, 4).'-'.substr($tail, 20, 12);
        }

        return null;
    }

    public function destroy(Request $request, Source $source): RedirectResponse
    {
        $request->user()->can('delete', $source) || abort(403);

        $agentId = $source->agent_id;
        $snapshot = ['type' => $source->type, 'url' => $source->url ?? null, 'status' => $source->status];
        $workspaceId = $source->agent?->workspace_id;
        $source->delete();

        if ($workspaceId !== null) {
            AuditLogger::log(
                workspaceId: $workspaceId,
                action: 'source.deleted',
                entityType: 'source',
                entityId: $source->id,
                before: $snapshot,
                request: $request,
            );
        }

        // Explicit redirect to the agent's sources page rather than
        // `back()`. Buyer reported the trash button leaving the admin
        // on a blank page — `back()` falls through to the Referer
        // header which the Inertia router sometimes omits on DELETE
        // requests, leaving the resolved location pointing at the
        // workspace root with no agent context so the sources page
        // re-renders with the now-stale `agent` route binding.
        return redirect()
            ->route('agents.sources.index', ['agent' => $agentId])
            ->with('success', 'Source removed.');
    }

    /**
     * Auto-discover crawlable URLs for a domain — robots.txt sitemaps,
     * /sitemap.xml, plus probed common paths. Used by the onboarding flow
     * so the user doesn't have to paste URLs one-by-one.
     */
    public function discover(Request $request, Agent $agent, SiteDiscoverer $discoverer): JsonResponse
    {
        $request->user()->can('update', $agent) || abort(403);

        $data = $request->validate([
            'url' => ['required', 'url', 'max:2000'],
            'max' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $result = $discoverer->discover($data['url'], (int) ($data['max'] ?? 50));

        return response()->json([
            'data' => [
                'root' => $result['root'],
                'sitemap_urls' => $result['sitemap_urls'],
                'probed_urls' => $result['probed_urls'],
                'total' => count($result['sitemap_urls']) + count($result['probed_urls']),
            ],
        ]);
    }

    /**
     * Bulk-add the URLs the user picked from /discover. Each URL becomes its
     * own type=url Source so the existing CrawlPageJob → IndexDocumentJob
     * pipeline takes over.
     */
    public function bulkStore(Request $request, Agent $agent): RedirectResponse
    {
        $request->user()->can('update', $agent) || abort(403);

        $data = $request->validate([
            'urls' => ['required', 'array', 'min:1', 'max:200'],
            'urls.*' => ['required', 'url', 'max:2000'],
        ]);

        // Bulk-add respects the workspace cap: only adds sources up to
        // the remaining quota and reports the shortfall instead of
        // silently 500ing on the (limit+1)th insert. NULL plan = no cap.
        $workspace = Workspace::query()->find($agent->workspace_id);
        $remaining = null;
        if ($workspace !== null) {
            $check = $this->limits->check($workspace, PlanLimits::RESOURCE_SOURCE);
            if (! $check['allowed']) {
                return back()->with('error', $this->limits->reasonFor(PlanLimits::RESOURCE_SOURCE, (int) $check['limit']));
            }
            $remaining = $check['remaining'];
        }

        $urls = $data['urls'];
        $skipped = 0;
        if ($remaining !== null && count($urls) > $remaining) {
            $skipped = count($urls) - $remaining;
            $urls = array_slice($urls, 0, $remaining);
        }

        foreach ($urls as $url) {
            $source = Source::create([
                'agent_id' => $agent->id,
                'type' => 'url',
                'status' => 'pending',
                'config' => ['url' => $url],
            ]);
            CrawlSourceJob::dispatch($source->id)->onQueue('crawl');
        }

        $msg = count($urls).' source(s) queued.';
        if ($skipped > 0) {
            $msg .= " {$skipped} skipped — plan limit reached.";
        }

        return back()->with($skipped > 0 ? 'warning' : 'success', $msg);
    }

    public function reindex(Request $request, Source $source): RedirectResponse
    {
        $request->user()->can('update', $source) || abort(403);

        // The actual type→pipeline dispatch lives in SourceRetrier — the
        // SAME implementation the pitchbar:retry-sources self-healing
        // sweep uses, so the button and the automatic retries can never
        // drift apart again (the "No URL configured" bug existed twice
        // precisely because this map was duplicated).
        try {
            $result = $this->retrier->retry($source);
        } catch (\Throwable $e) {
            // Dispatch can throw when the queue backend is unreachable
            // (Redis down, network blip on a Horizon-driven install).
            Log::error('source.reindex.dispatch_failed', [
                'source_id' => $source->id,
                'error' => $e->getMessage(),
            ]);
            $source->forceFill([
                'status' => 'failed',
                'error' => 'Queue unavailable: '.$e->getMessage(),
            ])->save();

            return back()->with('error', 'Could not queue reindex — queue backend is unreachable. Check Redis / queue worker status.');
        }

        if (! $result['ok']) {
            return match ($result['reason']) {
                'text_body_missing' => back()->with('error', 'This pasted-text source has no saved content to re-index. Edit it to add content, or delete and re-add it.'),
                'auto_empty' => back()->with('error', 'Nothing to reindex yet — pages are added here automatically as visitors browse.'),
                'no_documents' => back()->with('error', 'Nothing to reindex — re-upload the file.'),
                default => back()->with('error', 'Could not reindex this source.'),
            };
        }

        if ($result['reason'] === 'file_text_missing') {
            return back()->with(
                'warning',
                'The original file is no longer on disk (uploads from before text persistence). The already-indexed content keeps working — re-upload the file to refresh it.',
            );
        }

        if ($source->type === 'file') {
            return back()->with('success', "Reindex queued for {$result['queued']} segment(s).");
        }

        if ($source->type === 'auto') {
            return back()->with('success', "Reindex queued for {$result['queued']} page(s).");
        }

        if ($source->type === 'text') {
            return back()->with('success', 'Re-indexing the saved text. To change the content itself, edit this source.');
        }

        return back()->with('success', 'Reindex queued.');
    }

    /**
     * Returns a sample of what was actually extracted for this source so the
     * user can sanity-check whether the AI is seeing real content. Used by
     * the "Preview" button on the Sources page.
     */
    public function preview(Request $request, Source $source): JsonResponse
    {
        $request->user()->can('view', $source) || abort(403);

        $documents = Document::query()->withoutWorkspaceScope()
            ->where('source_id', $source->id)
            ->orderByDesc('fetched_at')
            ->limit(5)
            ->get();

        $payload = $documents->map(function (Document $d) {
            $chunks = \DB::table('chunks')
                ->where('document_id', $d->id)
                ->orderBy('ord')
                ->limit(20)
                ->get(['id', 'ord', 'token_count', 'text']);

            return [
                'id' => $d->id,
                'url' => $d->url,
                'title' => $d->title,
                'fetched_at' => $d->fetched_at?->toIso8601String(),
                'chunks_count' => \DB::table('chunks')->where('document_id', $d->id)->count(),
                // First chunk preview kept for back-compat with the legacy UI.
                'first_chunk_preview' => mb_substr((string) ($chunks->first()->text ?? ''), 0, 800),
                'chunks' => $chunks->map(fn ($c) => [
                    'id' => $c->id,
                    'ord' => (int) $c->ord,
                    'tokens' => (int) $c->token_count,
                    'text' => mb_substr((string) $c->text, 0, 4000),
                    'chars' => mb_strlen((string) $c->text),
                ]),
            ];
        });

        return response()->json([
            'data' => [
                'source' => [
                    'id' => $source->id,
                    'type' => $source->type,
                    'status' => $source->status,
                    'error' => SourceErrorPresenter::present($source->error),
                    'error_raw' => $source->error,
                ],
                'progress' => $this->progressFor($source),
                'documents' => $payload,
            ],
        ]);
    }

    /**
     * Per-source progress: number of unique URLs that produced documents
     * (= pages successfully indexed). For type=url this is 0 or 1; for
     * type=sitemap it's 0..N where N is the page cap.
     *
     * @return array{pages_indexed: int, pages_total: ?int}
     */
    private function progressFor(Source $source): array
    {
        $indexed = (int) Document::query()->withoutWorkspaceScope()
            ->where('source_id', $source->id)
            ->distinct()
            ->count('url');

        return [
            'pages_indexed' => $indexed,
            'pages_total' => $source->type === 'sitemap'
                ? (int) config('services.crawl.max_pages_per_source', 25)
                : ($source->type === 'url' ? 1 : null),
        ];
    }
}
