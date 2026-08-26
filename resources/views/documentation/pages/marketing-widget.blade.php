<div class="callout callout-warning">
    <svg class="callout-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <div class="callout-body">
        <div class="callout-title">Scope: install-level, not per-workspace</div>
        This setting controls the chat widget on the
        <strong>Pitchbar install's own</strong> marketing pages
        (whatever <code>APP_URL</code> serves). It is a platform-level
        setting — only super_admins of the install can configure it.
        Customers (workspace members) who want a chat widget on
        <em>their</em> marketing site embed the standard install
        snippet (see <a href="/documentation/embed">Install snippet</a>)
        on their own server — they don't need this toggle at all.
    </div>
</div>

<p>
    The Pitchbar install's public marketing site (home page + any
    <code>/marketing/*</code> route) can host a real chat widget
    pointed at one of the install's published agents — not just the
    seeded demo sandbox. Configure it under
    <strong>Settings → Marketing → Marketing-site widget</strong>
    (super_admin only).
</p>

<h2>How it works</h2>

<ol>
    <li>
        Toggle <strong>Enable widget on marketing pages</strong>. While
        off, the marketing site has no chat surface (default).
    </li>
    <li>
        Pick an agent from the dropdown. The dropdown lists every
        published agent across every workspace on this install (super_admin
        can mount any agent).
    </li>
    <li>
        Click <strong>Save marketing widget</strong>. The form sends only
        the widget toggle + agent id; the marketing home content editor is
        a separate sibling form on the same page and is unaffected. The
        widget mounts for anonymous visitors only — signed-in admins,
        customers, and platform admins never see it (would look like
        surveillance and pollute your own analytics with internal traffic).
    </li>
</ol>

<h2>Gate logic</h2>

<p>
    The blade gate in <code>resources/views/app.blade.php</code>
    resolves the agent in this order:
</p>

<ol>
    <li>
        <strong>AppSetting override</strong> — when
        <code>marketing_widget_enabled = true</code> AND
        <code>marketing_widget_agent_id</code> resolves to a published
        agent, that agent is mounted with no demo pill.
    </li>
    <li>
        <strong>Explicit choice with invalid agent → no widget.</strong>
        When the toggle is ON but the selected agent is unpublished or
        deleted, the gate mounts <em>nothing</em>. It does not
        silently substitute the demo agent — that would surface a
        stranger's persona name on your marketing site (client
        report 2026-05-23).
    </li>
    <li>
        <strong>DEMO env fallback</strong> — when the toggle was never
        enabled (default false) AND <code>DEMO=true</code> is set in
        the environment, the seeded <code>MarketingDemoAgent</code>
        mounts with the <code>data-demo="true"</code> attribute so
        visitors see a "DEMO" pill.
    </li>
    <li>
        <strong>Nothing</strong> — no script tag emitted at all.
    </li>
</ol>

<h2>Agent name shown to visitors</h2>

<p>
    The widget header label resolves with this fallback chain:
</p>

<ol>
    <li>
        <code>agent.persona.name</code> — set in
        <strong>Agent → Customize → Persona → Display name</strong>.
        This is the visitor-facing persona; treat it as a stage
        name.
    </li>
    <li>
        <code>agent.name</code> — the internal label set in
        <strong>Agent → Settings → Basics → Name</strong>. Used when
        the persona display name is empty, so an agent created
        through the dashboard ships with a sensible header without
        needing to also touch the persona form.
    </li>
    <li>
        Localized literal <code>AI assistant</code> — last-resort
        fallback when both fields are empty (legacy seed data).
    </li>
</ol>

<h2>Toggle changes apply immediately</h2>

<p>
    Saving <strong>Marketing → Marketing-site widget</strong> flushes
    the <code>marketing.demo_agent_id</code> cache (5-minute TTL) and
    the per-id <code>marketing.demo_agent_id.explicit_valid.*</code>
    cache (2-minute TTL) so the next marketing-page request reflects
    the change. Without the flush, toggling off could leave the demo
    widget visible for up to 5 minutes (client report 2026-05-23).
</p>

<p>
    Open marketing tabs also pick up the change without a hard reload.
    The widget mount payload ships as the <code>marketingWidget</code>
    Inertia shared prop, and a small controller component
    (<code>resources/js/components/marketing-widget-mount.tsx</code>)
    reactively injects or tears down the widget <code>&lt;script&gt;</code>
    tag whenever the prop changes. Two trigger paths cover the common
    cases:
</p>

<ol>
    <li>
        <strong>Same-tab navigation</strong> — any Inertia visit
        between marketing pages refreshes shared props; the controller's
        <code>useEffect</code> compares the new <code>agent_id</code>
        to the live DOM and remounts or removes the widget as needed.
    </li>
    <li>
        <strong>Cross-tab focus</strong> — when a backgrounded
        marketing tab regains focus, the controller dispatches a
        <code>router.reload({ only: ['marketingWidget'] })</code>
        partial reload so an admin toggling the widget in a sibling
        tab takes effect on the marketing tab without a manual
        refresh. Client report 2026-05-25.
    </li>
</ol>

<h2>CORS</h2>

<p>
    The agent you pick must have your marketing site's origin (the
    <code>APP_URL</code>) in its <code>allowed_origins</code>. Saving
    the marketing widget with the agent picked auto-appends the current
    <code>APP_URL</code> to the agent's <code>allowed_origins</code> if
    it isn't already there — so the widget works the moment you save.
    Disabling the widget does <em>not</em> remove origins; you can
    safely toggle the widget off and back on without losing CORS
    configuration on the agent.
</p>
