<p>
    Bulk-export your workspace's conversations as CSV or JSON for
    retention, analytics, or compliance handover. Admin and Owner
    roles only.
</p>

<h2>How it works</h2>

<ol>
    <li>Admin POSTs to <code>/app/conversation-exports</code> with
        format (<code>csv</code> or <code>json</code>) and optional
        filters (<code>agent_id</code>, <code>from</code>,
        <code>to</code>).</li>
    <li>A <code>ConversationExport</code> row is inserted with
        <code>status=pending</code> and
        <code>BuildConversationExportJob</code> is queued.</li>
    <li>The job runs in the background, streaming conversations in
        chunks of 200 to a private-disk file at
        <code>storage/app/private/exports/conversations/{uuid}.{format}</code>.</li>
    <li>On completion, <code>status=ready</code>, the file size +
        row count are stamped, and <code>expires_at</code> is set 7
        days out.</li>
    <li>The requesting admin downloads from
        <code>/app/conversation-exports/{id}/download</code>. The
        endpoint streams the file with the right MIME and rejects
        the request if the export is not ready, expired, or belongs
        to another workspace.</li>
</ol>

<h2>What's in the export</h2>

<p>
    Each row carries the conversation's lifecycle metadata, the
    visitor's lead profile (email / phone / name / status), and
    every message in the conversation. CSV format flattens the
    transcript to <code>first_user_message</code> +
    <code>last_assistant_message</code> for spreadsheet use; JSON
    keeps the full message array per conversation.
</p>

<h2>Retention</h2>

<p>
    Exports expire 7 days after they're created. The
    <code>conversations:prune-exports</code> Artisan command runs
    nightly at 02:15 UTC and deletes both the DB row and the file.
    Re-trigger the export if you need it again after expiry.
</p>

<h2>Tenant isolation</h2>

<p>
    The build job only reads conversations whose
    <code>agent_id</code> belongs to the requesting workspace.
    Download requests verify <code>workspace_id</code> match before
    streaming. A leaked export id is harmless without an authed
    session in the right workspace.
</p>

<h2>Limits</h2>

<p>
    The job has a 600-second hard timeout. Workspaces with very
    large conversation volume should narrow with date / agent
    filters; we'll lift the timeout if buyers ask. The export file
    is held on the application's local private disk; very large
    files may push the disk usage warning thresholds on shared
    hosts.
</p>
