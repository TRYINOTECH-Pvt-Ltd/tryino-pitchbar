<p>
    Pitchbar agents adapt to the kind of site they live on. When you set
    up an agent, the system can auto-detect whether you're running an
    e-commerce store, a documentation site, a SaaS product, a help center,
    a marketing site, or an internal knowledge base — and load a
    <strong>vertical preset</strong> tuned for that context.
</p>

<h2>What a preset changes</h2>

<p>
    Each preset bundles four kinds of defaults:
</p>

<ul>
    <li>
        <strong>System prompt fragment</strong> — appended to the LLM
        system prompt at every turn. Sits after retrieval data and
        <em>before</em> any custom system prompt you write, so your
        custom instructions always win.
    </li>
    <li>
        <strong>Starter prompts</strong> — the chips visitors see on
        first open. Filled in for new agents; left alone if you've
        already curated your own list.
    </li>
    <li>
        <strong>Launcher label</strong> — the call-to-action on the
        widget's closed orb (e.g. "Browse our shop", "Search docs").
    </li>
    <li>
        <strong>Capabilities</strong> — a list of rich-UI affordances
        the widget reads on init. Phase 3 renderers ship today for
        product cards, pricing cards, case-study cards, and the
        escalation button (see <code>resources/widget/src/ui/blocks.tsx</code>);
        more renderers (code blocks, FAQ accordions, etc.) follow as
        the capability list grows.
    </li>
</ul>

<h2>The seven verticals</h2>

<table>
    <thead>
        <tr><th>Slug</th><th>For sites that…</th><th>Capabilities (locked in this version)</th></tr>
    </thead>
    <tbody>
        <tr>
            <td><code>ecommerce</code></td>
            <td>Sell products. Pricing, stock, returns, shipping.</td>
            <td><code>product_card</code>, <code>price_inline</code>, <code>shipping_estimate</code>, <code>cart_handoff</code>, <code>order_status</code>, <code>ticket_escalation</code></td>
        </tr>
        <tr>
            <td><code>documentation</code></td>
            <td>Publish technical docs, API references, SDK guides.</td>
            <td><code>code_block</code>, <code>api_reference_card</code>, <code>version_picker</code>, <code>troubleshoot_steps</code>, <code>ticket_escalation</code></td>
        </tr>
        <tr>
            <td><code>saas</code></td>
            <td>Sell software with pricing, features, and signup.</td>
            <td><code>pricing_card</code>, <code>signup_handoff</code>, <code>feature_compare</code>, <code>account_status</code>, <code>ticket_escalation</code></td>
        </tr>
        <tr>
            <td><code>help_center</code></td>
            <td>Run a public support / FAQ surface with article and ticket flows.</td>
            <td><code>ticket_escalation</code>, <code>kb_article_card</code>, <code>sentiment_routing</code>, <code>ticketing</code></td>
        </tr>
        <tr>
            <td><code>marketing</code></td>
            <td>Capture leads, run case studies, do top-of-funnel content.</td>
            <td><code>lead_capture_inline</code>, <code>demo_booking</code>, <code>case_study_card</code>, <code>ticket_escalation</code></td>
        </tr>
        <tr>
            <td><code>internal_kb</code></td>
            <td>Serve an employee wiki, runbooks, or compliance docs.</td>
            <td><code>policy_lookup</code>, <code>team_handoff</code>, <code>auth_aware</code>, <code>ticket_escalation</code></td>
        </tr>
        <tr>
            <td><code>generic</code></td>
            <td>None of the above, or you'll configure everything yourself.</td>
            <td><code>ticket_escalation</code> only — every vertical exposes this so the LLM can always hand off to a human.</td>
        </tr>
    </tbody>
</table>

<h2>Auto-detection</h2>

<p>
    During onboarding (after you paste your website URL) Pitchbar fetches
    the homepage, scores a handful of structured signals, and picks the
    highest-scoring vertical. The signals it weighs:
</p>

<ul>
    <li>
        <code>og:type</code> meta tag (e.g. <code>product</code>,
        <code>website</code>, <code>article</code>)
    </li>
    <li>
        JSON-LD <code>@type</code> values (Schema.org entities like
        <code>Product</code>, <code>SoftwareApplication</code>,
        <code>FAQPage</code>, <code>TechArticle</code>)
    </li>
    <li>
        <code>generator</code> meta (Shopify, WooCommerce, Docusaurus,
        Mintlify, Zendesk, WordPress…)
    </li>
    <li>
        URL path patterns (<code>/products</code>, <code>/docs</code>,
        <code>/help</code>, <code>/api</code>)
    </li>
    <li>
        Hostname signals (<code>app.</code> / <code>dashboard.</code>
        subdomains for SaaS; <code>.local</code> /
        <code>.internal</code> for KB)
    </li>
    <li>
        Presence of <code>&lt;article&gt;</code> tags and
        <code>&lt;pre&gt;&lt;code&gt;</code> blocks
    </li>
</ul>

<p>
    Each signal carries a weight; the highest-summing vertical wins. If
    no vertical clears a confidence floor of <strong>0.25</strong>, the
    detector falls back to <code>generic</code> and asks you to pick
    manually.
</p>

<p>
    Auto-detection is best-effort. JS-heavy SPAs that ship empty SSR
    HTML, or auth-walled hosts, fall back to <code>generic</code> —
    you can always pick a vertical manually from the radio cards.
</p>

<h2>Changing a vertical later</h2>

<p>
    Open <code>/app/agents/{id}/vertical</code> from the agent's nav
    tiles. You can:
</p>

<ul>
    <li>
        <strong>Re-detect</strong> if your site has changed (e.g. you
        added a Shopify storefront to a previously content-only site).
    </li>
    <li>
        <strong>Pick a different vertical</strong> via the radio grid.
        If your customisations would be overwritten, a confirmation
        dialog explains exactly what gets replaced.
    </li>
</ul>

<p>
    The system prompt fragment is <em>runtime-applied</em> — it isn't
    baked into the agent record, so changing the vertical instantly
    changes the prompt with no stale text. Capabilities are runtime too:
    the widget receives the live list on every <code>/init</code> call.
    Starter prompts and launcher label are merged into the agent record
    on apply, so you can edit them in Customize without losing your work
    next time you re-apply the same vertical.
</p>

<h2>Overriding capabilities</h2>

<p>
    Each agent has an optional <code>vertical_overrides</code> JSON
    column. Set <code>vertical_overrides.capabilities = [...]</code> to
    replace the preset's capability list with your own. The widget reads
    the override; the LLM still sees the preset's system fragment so
    your overrides don't accidentally weaken the prompt contract.
</p>

<h2>Multi-tenancy &amp; security</h2>

<p>
    Both endpoints (<code>POST /app/agents/{agent}/vertical/detect</code>
    and <code>POST /app/agents/{agent}/vertical/apply</code>) are gated
    by <code>AgentPolicy::update</code> — only members of the agent's
    workspace can call them. Cross-workspace access returns 403.
    <code>vertical_signals</code> caches the most recent detection on
    the agent row so the admin UI can show "auto-detected on …"
    without re-fetching.
</p>

<h2>What's next</h2>

<p>
    Phase 1 ships the data model, detection, presets, admin UX, and
    exposes <code>capabilities</code> to the widget. Phase 2 (tool /
    function calling) is partially shipped — the <code>ToolRegistry</code>
    in <code>app/Services/Tools/ToolRegistry.php</code> is wired, and
    the first tool (<code>EscalateToHumanTool</code>) ships gated to
    the <code>ticket_escalation</code> capability. Vertical-specific
    tools (<code>product_lookup</code> for e-commerce, etc.) follow as
    integrations land. Phase 3 (rich-message renderers in the widget)
    is partially shipped — <code>resources/widget/src/ui/blocks.tsx</code>
    renders product, pricing, case-study, and escalation blocks the
    LLM emits via inline-XML markers; additional renderers follow as
    we extend the capability list.
</p>
