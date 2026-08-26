<p>
    The Playground at <code>/app/agents/&#123;id&#125;/playground</code> is a
    full sandbox for testing your agent before publishing. Conversations here
    are flagged <code>is_playground</code> and never count toward your billing
    meter, conversation analytics, or the Inbox.
</p>

<h2>What you can test</h2>

<ul>
    <li>
        <strong>Site type</strong> — switch between
        <code>ecommerce</code>, <code>saas</code>, <code>documentation</code>,
        <code>help_center</code>, <code>marketing</code>,
        <code>internal_kb</code>, or <code>generic</code>
        without mutating the agent's persisted column. Useful for "what would
        my agent say if treated as ecommerce vs documentation?".
    </li>
    <li>
        <strong>Page context</strong> — paste a URL and metadata
        (title, description, og tags, JSON-LD, h1/h2, visible body text) so
        the agent answers as if the visitor were on a specific page. Six
        ready-made templates (Shopify product, SaaS pricing, Docusaurus
        docs, help-center article, marketing landing, blank) cover the
        common shapes.
    </li>
    <li>
        <strong>Reply language</strong> — pick any locale the workspace
        ships translations for (132 by default, auto-discovered from
        <code>lang/*.json</code>). Useful for sanity-checking that the
        agent answers in the right language and that RTL scripts mirror.
    </li>
    <li>
        <strong>Sample prompts</strong> — buyer-realistic test prompts
        per vertical (5–6 chips per site type) so you can fire a typical
        question with one click.
    </li>
    <li>
        <strong>Compare mode</strong> — toggle to split the chat into two
        columns and run the same question against two different site types
        side-by-side. The differentiator for picking the right vertical.
    </li>
</ul>

<h2>Right-pane tabs</h2>

<h3>Scenario</h3>
<p>
    Site type override + reply language + sample prompts. The capability
    badges below the site-type select show which rich blocks
    (<code>product_card</code>, <code>pricing_card</code>,
    <code>case_study_card</code>, <code>escalation_button</code>) the
    selected vertical can emit.
</p>
<div class="callout callout-warn">
    <p>
        <strong>Note: not every capability has a widget renderer yet.</strong>
        Today the widget's <code>RENDERABLE</code> set in
        <code>resources/widget/src/core/capabilities.ts</code> only
        whitelists <code>ticket_escalation</code> (the
        <code>escalation_button</code> block). Other blocks
        (<code>product_card</code>, <code>pricing_card</code>,
        <code>case_study_card</code>) are emitted by the server when the
        LLM asks for them, but the public widget will silently drop
        them until their renderer is added to the
        <code>RENDERABLE</code> set + a Preact component in
        <code>ui/blocks.tsx</code>. The Playground previews them
        because it ships every renderer for design purposes — what you
        see in Playground may be richer than what your visitors see in
        the live widget.
    </p>
</div>

<h3>Page context</h3>
<p>
    Form-driven editor for the <code>page_context</code> the visitor would
    send. Use the
    <em>Use template</em> dropdown to drop in a realistic Shopify product page
    or Stripe pricing page, then tweak the URL / title / og fields. JSON-LD
    accepts pasted JSON.
</p>

<h3>Diagnostics</h3>
<p>
    Read-only inspection of the last turn:
</p>
<ul>
    <li><strong>Latency</strong> — first-token and total time.</li>
    <li><strong>Confidence</strong> — the strongest grounding signal
        (retrieval similarity OR page-context baseline 0.85).</li>
    <li><strong>Retrieved sources</strong> — top-k chunks with both ANN
        and rerank scores plus a snippet, ordered as the LLM saw them.</li>
    <li><strong>Tool calls</strong> — every server-side tool fired during
        the turn, with arguments.</li>
    <li><strong>Inline blocks</strong> — count and types of rich blocks
        the LLM emitted (e.g. <code>product_card</code>).</li>
    <li><strong>System prompt</strong> — the full system message used,
        including the vertical fragment and any custom system prompt.
        Click to expand.</li>
</ul>

<h2>Endpoints</h2>

<p>
    All three are session-authenticated and scoped by
    <code>AgentPolicy::update</code>:
</p>

<ul>
    <li>
        <code>GET /app/agents/&#123;agent&#125;/playground</code> —
        Inertia render with the verticals preview map keyed by slug.
    </li>
    <li>
        <code>POST /app/agents/&#123;agent&#125;/playground/stream</code> —
        SSE stream. Body:
        <code>&#123;message, conversation_id?, site_type_override?,
        language_override?, page_context?&#125;</code>.
        Emits <code>start</code>, <code>retrieval</code>,
        <code>prompt</code>, <code>token</code>, <code>block</code>,
        <code>tool_call</code>, <code>done</code> events.
    </li>
    <li>
        <code>POST /app/agents/&#123;agent&#125;/playground/reset</code> —
        clears the cached conversation history. Body:
        <code>&#123;conversation_id&#125;</code>.
    </li>
</ul>

<h2>Notes</h2>

<ul>
    <li>
        Playground turns reuse the same Redis history key as production
        widget conversations. <em>Reset</em> is the explicit way to start
        from an empty context — closing the tab leaves history in place
        for two hours.
    </li>
    <li>
        Site-type and language overrides do <strong>not</strong> mutate
        the agent's persisted columns. Once you've found the combination
        that works, head to <em>Site type</em> in the agent's settings to
        save it.
    </li>
    <li>
        Page-context payloads are sanitized server-side identically to
        the visitor widget — same key allow-list, same 8 KB cap, same
        canonical-URL collapse.
    </li>
</ul>
