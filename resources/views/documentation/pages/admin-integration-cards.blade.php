<p>
    The four cards on the public <code>/integrations</code> landing
    page (Notion, Google Docs, Slack, Stripe) ship with default copy.
    Operators can override the <strong>name</strong>,
    <strong>category</strong>, <strong>tagline</strong>, and
    <strong>description</strong> of any card from
    <strong>/settings/system → Integrations → Integration card
    copy</strong>. The icon and accent colour stay controlled by the
    codebase so editing copy can't break the layout.
</p>

<h2>Where the override lives</h2>

<p>
    Stored as JSON on <code>app_settings.integration_cards</code>.
    Resolved at render time by
    <code>App\Support\IntegrationCardsContent::resolve()</code> which
    overlays admin overrides on top of the hardcoded defaults in
    <code>defaults()</code>, keyed by card name (case-insensitive).
</p>

<h2>Per-field fallback semantics</h2>

<ul>
    <li>Override row missing → default card rendered as-is.</li>
    <li>Override row present + field non-blank → admin value wins.</li>
    <li>Override field blank or whitespace → falls back to default for
        that field. Lets the admin "clear" an edit without deleting
        the whole row.</li>
    <li>Submitting an empty <code>integration_cards</code> array via
        the admin form clears all overrides, resetting every card to
        defaults.</li>
</ul>

<h2>Adding a new card</h2>

<p>
    Not exposed in the admin UI by design — the card layout depends
    on a known icon + accent + integration kind. To add a card, edit
    <code>IntegrationCardsContent::defaults()</code> and rebuild.
    The admin form will then surface it as a new editable row.
</p>

<h2>Why icon + accent aren't editable</h2>

<p>
    Both feed Tailwind colour tokens + lucide-react icon imports
    bundled by Vite. Letting the admin type an arbitrary icon name
    or hex code would either silently render an empty box or break
    the page when the build can't tree-shake the import. The trade-
    off is intentional: editable copy gives operators ownership of
    messaging without the foot-guns of layout / styling.
</p>
