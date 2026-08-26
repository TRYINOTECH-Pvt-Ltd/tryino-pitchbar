<p>
    The application changelog at <code>/changelog</code> is the
    public-facing release-notes surface for buyers. Authoring sits at
    <code>/admin/changelog</code> (super_admin only).
</p>

<h2>Format</h2>

<p>
    Each entry is one row keyed by an immutable <strong>version</strong>
    (semver: <code>v1.4.0</code>). Entries follow the
    <a href="https://keepachangelog.com" target="_blank" rel="noopener">Keep
    a Changelog</a> convention — markdown with sections under
    <code>## Added</code> / <code>## Changed</code> /
    <code>## Fixed</code> / <code>## Removed</code> /
    <code>## Security</code>. The public renderer supports headings,
    bullet lists, <code>**bold**</code>, and <code>`code`</code> spans;
    everything else passes through as plain text.
</p>

<h2>Status lifecycle</h2>

<table>
    <thead><tr><th>Status</th><th>What it does</th></tr></thead>
    <tbody>
        <tr>
            <td><code>draft</code></td>
            <td>Default on create. Invisible to buyers; the public <code>/changelog</code> page filters drafts out.</td>
        </tr>
        <tr>
            <td><code>published</code></td>
            <td>Visible to buyers. The entry's <code>version</code> becomes <strong>immutable</strong> — buyers may have linked to <code>/changelog#v1.4.0</code> and silently changing what that anchor refers to would rewrite history. To renumber, archive the entry first and create a new one.</td>
        </tr>
        <tr>
            <td><code>archived</code></td>
            <td>Hidden from the public listing but kept in the table so old buyer linkbacks still resolve. Use this instead of hard-deleting.</td>
        </tr>
    </tbody>
</table>

<h2>JSON feed</h2>

<p>
    <code>/changelog.json</code> serves the same published entries as
    machine-readable JSON, capped at the latest 100. Buyers can scrape
    it for embedding "what's new" widgets in their own dashboards or
    subscribing via a Make/Zapier polling trigger.
</p>

<pre><code>{
  "brand": "Pitchbar",
  "entries": [
    {
      "version": "v1.4.0",
      "released_at": "2026-05-09T00:00:00+00:00",
      "title": "Workflow visual editor + Phase 2 step types",
      "body": "## Added\n- React Flow canvas\n…"
    }
  ]
}</code></pre>

<h2>What's-new banner</h2>

<p>
    Authenticated workspace members see a slim "What's new in vX.Y.Z"
    banner across the top of the admin shell whenever a published
    entry is newer than the user's
    <code>users.last_changelog_seen_at</code> timestamp. Dismissing
    the banner POSTs to <code>/changelog/seen</code> and stamps the
    timestamp; the banner stays hidden until the next entry lands.
    Super-admins don't see the banner — they author the entries
    themselves.
</p>

<h2>CLI</h2>

<p>
    <code>php artisan changelog:add</code> drops an entry from the
    terminal — useful for committing release notes alongside the code
    that ships them.
</p>

<pre><code>php artisan changelog:add v1.4.0 \
  --title="Workflow visual editor" \
  --status=published \
  --body="$(cat &lt;&lt;'EOT'
## Added
- React Flow canvas at /app/workflows/{id}/canvas
- Branch / tag_lead / webhook step types

## Fixed
- Mobile homepage hero overflow on viewports under 480px
EOT
)"</code></pre>

<p>
    Idempotent on <code>version</code> — re-running with the same
    semver leaves the existing row untouched. To edit a published
    entry, use the admin form at <code>/admin/changelog/{id}/edit</code>.
</p>

<h2>Persistence</h2>

<p>
    Changelog entries live as a single JSON file at
    <code>storage/app/private/changelog-entries.json</code> — same
    pattern as the internal Kanban board. Storage is intentionally
    NOT a database table, so <code>php artisan migrate:fresh</code>
    during development never wipes release notes.
</p>

<p>
    On every read, the store auto-seeds itself from source markdown
    files at <code>database/changelog-entries/v*.md</code>. New
    versions on disk are inserted; existing entries are never
    overwritten (admin edits in the UI are the source of truth post-
    bootstrap). This gives a "ship the source in git, sync to
    disk on first request" workflow with zero manual steps —
    deploying the new code, hitting <code>/changelog</code> once,
    the entries appear.
</p>

<h3>Source markdown shape</h3>

<pre><code>---
version: v1.1.0
title: Visual workflow editor, customizable lead form, and widget polish
released_at: 2026-05-09
---

## New features

**Visual workflow editor.** Build branching chat flows on a
drag-and-drop canvas...

## Improvements

...

## Fixes

...
</code></pre>

<p>
    Three frontmatter fields are required: <code>version</code>,
    <code>title</code>, and <code>released_at</code>. Body is plain
    markdown rendered by the public changelog page.
</p>

<h3>Disabling the bootstrap</h3>

<p>
    Set <code>changelog.bootstrap_dir</code> to an empty string in
    your config or environment overlay. Useful when you want an empty
    changelog and want to author entries from scratch via the admin
    UI.
</p>
