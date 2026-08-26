<p>
    Pitchbar ships endpoints to satisfy GDPR Article 15 (right of
    access) and Article 17 (right to erasure) for any visitor that
    interacted with your widget. Workspace admins can look up, export,
    or erase a visitor's data from <code>/app/dsr</code> endpoints.
</p>

<h2>Who can use it</h2>

<p>
    Admin and Owner roles only. Viewer and Editor get
    <code>403 Forbidden</code>. The policy lives in
    <code>App\Policies\DsrPolicy</code> and the gate is
    <code>manageMembers</code> (same capability used for invite/remove
    member).
</p>

<h2>Lookup — find a visitor</h2>

<p>
    <code>POST /app/dsr/lookup</code>. Provide any one of:
</p>

<ul>
    <li><code>email</code> — matches against
        <code>leads.email</code> in the workspace.</li>
    <li><code>visitor_id</code> — exact visitor uuid.</li>
    <li><code>anonymous_id</code> — the widget's local-storage
        anonymous id (visible in the visitor's browser dev tools).</li>
</ul>

<p>
    Response is a JSON list of matching visitors with country, first /
    last seen timestamps, and visit count. Cross-workspace lookups are
    blocked — an admin in workspace A cannot find a visitor that
    interacted only with workspace B.
</p>

<h2>Export — Article 15 data portability</h2>

<p>
    <code>POST /app/dsr/export</code> with body
    <code>{visitor_id: "..."}</code>. Returns the visitor's full
    history as JSON: visitor row (sans IP hash), every conversation
    with its messages, every lead record, all events
    (CTA clicks, satisfaction submits, etc.). The export is also
    persisted (encrypted at rest) in <code>dsr_requests</code> for the
    audit trail.
</p>

<p>
    Every export writes one <code>audit_logs</code> row with
    <code>action=dsr.exported</code> for the workspace's compliance
    record.
</p>

<h2>Erase — Article 17 right to be forgotten</h2>

<p>
    <code>POST /app/dsr/erase</code> with body
    <code>{visitor_id: "...", confirm_typed: "ERASE"}</code>. The
    <code>confirm_typed</code> guard prevents accidental wipes — the
    string must be exactly <code>ERASE</code> (case sensitive).
</p>

<p>
    Erasure runs inside a database transaction and:
</p>

<ol>
    <li>Nulls <code>leads.email</code>, <code>leads.phone</code>,
        <code>leads.name</code>; clears <code>leads.fields</code>.</li>
    <li>Hard-deletes the visitor row. The
        <code>conversations.visitor_id</code> foreign key has
        <code>ON DELETE SET NULL</code>, so conversations themselves
        survive with their messages intact, but the visitor link is
        gone.</li>
    <li>Hard-deletes <code>events</code> rows tied to the visitor's
        conversations.</li>
    <li>Writes <code>audit_logs.action=dsr.erased</code> for the
        compliance record.</li>
</ol>

<p>
    Messages are deliberately retained because they carry no
    personalised text in the default configuration — they are model
    output and visitor questions to the bot. If your buyers paste PII
    into the chat box itself, ask your customer-success contact about
    full-message erasure as a separate workflow.
</p>

<h2>Note on the AI model</h2>

<p>
    Pitchbar uses Retrieval-Augmented Generation, not fine-tuning, so
    the underlying LLM has not been trained on your visitor's data.
    The conversation content was sent to your LLM provider
    (Cloudflare Workers AI, OpenAI, or OpenRouter) at inference time
    — those providers' data handling is governed by the contract
    between you and them, not by Pitchbar.
</p>

<h2>Audit trail retention</h2>

<p>
    GDPR also covers audit logs themselves; however, the audit row
    for a DSR is itself excluded from any subsequent DSR request to
    keep the compliance record provable. Document this carve-out in
    your privacy policy.
</p>
