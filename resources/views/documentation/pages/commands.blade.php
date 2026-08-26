<p>
    Pitchbar ships a handful of <code>php artisan</code> commands beyond
    the standard Laravel surface — they handle queue ticks, vector
    rebuilds, source freshness, demo seeding, and a few admin
    convenience wrappers. Run any of them with <code>--help</code> to
    see options.
</p>

<h2>Operational</h2>

<table>
    <thead><tr><th>Command</th><th>What it does</th></tr></thead>
    <tbody>
        <tr>
            <td><code>php artisan pitchbar:queue-tick</code></td>
            <td>
                Process a bounded batch of queued jobs and exit. Designed
                for external-cron-driven processing on shared hosting
                (Cloudflare Worker → <code>POST /api/v1/internal/queue-tick</code>
                → this command). See
                <a href="/documentation/deployment">Deployment</a> for
                the full external-cron setup.
                <br><br>
                Flags: <code>--queues=analytics,default,index,crawl</code>,
                <code>--max-jobs=20</code>, <code>--max-time=55</code>.
            </td>
        </tr>
        <tr>
            <td><code>php artisan vector:setup</code></td>
            <td>
                Idempotently create the vector index/collection — Vectorize
                on Cloudflare, or Qdrant. Run once on a fresh install
                before the first crawl. Safe to re-run; it short-circuits
                when the index already exists. The legacy
                <code>qdrant:setup</code> alias still works.
            </td>
        </tr>
        <tr>
            <td><code>php artisan vector:rebuild-index</code></td>
            <td>
                Recovery flow for embedding-model changes. Drops the
                Vectorize index, deletes every local Chunk, resets every
                Source to <code>pending</code>, recreates the index at
                the dim that matches the currently-configured embedding
                model, and re-dispatches <code>IndexDocumentJob</code>
                for every Document with persisted text. Use this after
                switching <code>CLOUDFLARE_EMBED_MODEL</code> between
                models of different dimensions (e.g. bge-base 768 →
                bge-m3 1024). Flags: <code>--force</code> to skip the
                confirmation prompt, <code>--dim=N</code> to override
                the resolved dim. See
                <a href="/documentation/knowledge">Knowledge</a> for the
                full model→dim map.
            </td>
        </tr>
        <tr>
            <td><code>php artisan pitchbar:audit-vectors</code></td>
            <td>
                Compare DB chunks against the vector index and report drift
                (chunks present in DB but missing in the index, and vice
                versa). Read-only. Useful after a Vectorize re-provision
                or when investigating "the bot doesn't know about page X."
            </td>
        </tr>
        <tr>
            <td><code>php artisan pitchbar:refresh-stale-sources</code></td>
            <td>
                Re-crawl sources whose content is older than N days
                (default 7). Schedule this nightly via your external cron
                if you want auto-refresh; without it, sources only re-sync
                when the operator clicks Refresh.
            </td>
        </tr>
        <tr>
            <td><code>php artisan pitchbar:sync-oauth-sources</code></td>
            <td>
                Re-ingest Notion / Google Doc sources whose content
                might be stale. Same idea as
                <code>refresh-stale-sources</code> but only walks
                OAuth-backed integrations (where the file may have
                changed inside Notion / Drive without a webhook).
            </td>
        </tr>
        <tr>
            <td><code>php artisan pitchbar:suggest-from-gaps</code></td>
            <td>
                Walk recurring unanswered questions (logged by
                <code>DetectGapJob</code>) and draft
                <code>CuratedAnswer</code> suggestions. Generates an
                admin notification; the curated answer is created as a
                <em>draft</em>, never published.
            </td>
        </tr>
    </tbody>
</table>

<h2>Admin convenience</h2>

<table>
    <thead><tr><th>Command</th><th>What it does</th></tr></thead>
    <tbody>
        <tr>
            <td><code>php artisan pitchbar:make-admin {email}</code></td>
            <td>
                Promote an existing user to <code>super_admin</code>.
                Pass <code>--demote</code> to flip them back to
                customer. Use this for your very first super_admin
                after signup — there's no UI for the role bump.
            </td>
        </tr>
        <tr>
            <td><code>php artisan pitchbar:build-wp-plugin</code></td>
            <td>
                Package <code>wp-plugin/pitchbar/</code> into an
                install-ready zip for distribution. Compiles every
                <code>.po</code> language file to <code>.mo</code>
                automatically before zipping.
            </td>
        </tr>
        <tr>
            <td><code>php artisan pitchbar:seed-demo-agent</code></td>
            <td>
                Create or refresh the demo agent used by the marketing
                site widget. Only meaningful on installs with
                <code>DEMO=true</code> (the public pitchbar.io demo).
                Idempotent.
            </td>
        </tr>
        <tr>
            <td><code>php artisan pitchbar:seed-local-test</code></td>
            <td>
                Create a published "Local Test" agent + script snippet
                for embedding on a sibling project during dev. Useful
                when you're building the widget locally and want a
                consistent agent across restarts.
            </td>
        </tr>
    </tbody>
</table>

<h2>Internal board (Kanban)</h2>

<p>
    The internal admin board at <code>/admin/board</code> is backed by
    these commands. See <a href="/documentation/admin-board">Admin board</a>
    for the full workflow. Quick reference:
</p>

<ul>
    <li><code>php artisan board:add "Title" --body="..." --label=...</code> — append a card to the backlog.</li>
    <li><code>php artisan board:start &lt;id-or-title&gt;</code> — move a card to In Progress.</li>
    <li><code>php artisan board:done  &lt;id-or-title&gt; --test="..." --release="v2.0.0"</code> — close the card with a UI test plan and stamp the release version.</li>
    <li><code>php artisan board:stamp-version vX.Y.Z --status=done</code> — backfill release version on all freshly-Done cards.</li>
    <li><code>php artisan board:seed</code> — seed the initial backlog (idempotent).</li>
</ul>

<h2>Changelog</h2>

<p>See <a href="/documentation/changelog">Changelog</a> for the public release-notes page. The maintainer-side commands:</p>

<ul>
    <li><code>php artisan changelog:add</code> — append a new entry interactively. Writes a new <code>database/changelog-entries/v{version}.md</code> file; the running site reads those Markdown files directly via <code>ChangelogStore</code> (no DB table — the original <code>changelog_entries</code> table was dropped in <code>2026_05_09_120539</code> in favour of the file-based store).</li>
</ul>
