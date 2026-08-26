<p>
    The white-label audit is the checklist we run before a release to
    confirm that a buyer renaming the install to <em>Acme Assist</em>
    sees <em>Acme Assist</em> on every customer-facing surface — never
    a stray "Pitchbar" sneaking through. This page is the audit
    template; it lives in docs so any future engineer can re-run it
    and update the same surface inventory in place.
</p>

<h2>Test method</h2>

<ol>
    <li>
        Set <code>site_title = "Acme Assist"</code> in
        <code>/settings/branding</code>, swap header logo + favicon
        to dummy art, set the footer brand label to "Acme", and (on
        a paid plan) flip <strong>Remove "Powered by"</strong> on.
        Optional but recommended: upload separate <em>dark-mode</em>
        variants in the new <strong>Header logo (dark mode)</strong>,
        <strong>Footer logo (dark mode)</strong>, and
        <strong>Dashboard logo (dark mode)</strong> slots so a
        light-on-light wordmark doesn't disappear when the visitor
        or operator switches to dark theme. The light upload is reused
        when no dark variant is provided.
    </li>
    <li>
        Walk the surfaces below, signed out for the marketing /
        widget rows and signed in (as a customer + as a super-admin)
        for the dashboard / settings rows.
    </li>
    <li>
        Grep the codebase for hard-coded literals on the way in:
        <pre><code>grep -rn "Pitchbar\|pitchbar\.ai\|@pitchbar\.com" \
  --include="*.tsx" --include="*.ts" --include="*.blade.php" --include="*.php" \
  --exclude-dir=tests --exclude-dir=vendor --exclude-dir=node_modules \
  resources app routes</code></pre>
        Filter the result by surface (anything in <code>resources/views/documentation/</code>
        is allowed to name the source product; user-facing surfaces are not).
    </li>
</ol>

<h2>Surface inventory (last run: 2026-05-09, v1.1.0)</h2>

<h3>Auth (Fortify)</h3>

<table>
    <thead><tr><th>Surface</th><th>Status</th><th>Notes</th></tr></thead>
    <tbody>
        <tr>
            <td>Login / register / forgot-password card</td>
            <td>✅ pass</td>
            <td>
                <code>auth-simple-layout.tsx</code> reads
                <code>branding.site_title</code>; falls back to
                <code>'Pitchbar'</code> only when the install has no
                brand set. Title bar, brand mark, and copy honour the
                rebrand.
            </td>
        </tr>
        <tr>
            <td>Email-verification + password-reset emails</td>
            <td>✅ pass</td>
            <td>
                Mail templates use <code>config('branding.site_title')</code>
                via <code>AppBranding::siteTitle()</code> — Fortify's
                stock Laravel mailable is overridden in
                <code>AppServiceProvider</code> to use the
                white-labelled view.
            </td>
        </tr>
    </tbody>
</table>

<h3>Dashboard shell</h3>

<table>
    <thead><tr><th>Surface</th><th>Status</th><th>Notes</th></tr></thead>
    <tbody>
        <tr>
            <td>Sidebar brand mark + tooltip</td>
            <td>✅ pass</td>
            <td><code>useBranding().site_title</code> drives both.</td>
        </tr>
        <tr>
            <td>Sidebar "Set up X in a few steps"</td>
            <td>
                ✅ <strong>fixed in v1.1.0</strong>
            </td>
            <td>
                Was hard-coded as "Set up Pitchbar". Now reads
                <code>branding.site_title</code>.
            </td>
        </tr>
        <tr>
            <td>Breadcrumbs / page titles</td>
            <td>✅ pass</td>
            <td>
                Driven by <code>config('app.name')</code> +
                <code>branding.site_title</code> shared via Inertia.
            </td>
        </tr>
        <tr>
            <td>Workspace dropdown / impersonation banner</td>
            <td>✅ pass</td>
            <td>No brand strings — just user / workspace identifiers.</td>
        </tr>
    </tbody>
</table>

<h3>Marketing site</h3>

<table>
    <thead><tr><th>Surface</th><th>Status</th><th>Notes</th></tr></thead>
    <tbody>
        <tr>
            <td>Home (<code>/</code>) — hero / chat preview / footer</td>
            <td>✅ pass</td>
            <td>
                Drives every brand-facing string from
                <code>landingContent</code> +
                <code>useBranding()</code>. The shipped defaults DO
                say "Pitchbar" — but those are JSON content the admin
                edits via Settings → Marketing.
            </td>
        </tr>
        <tr>
            <td>Pricing / How it works / Integrations / Privacy / Terms</td>
            <td>✅ pass</td>
            <td>
                All Inertia-rendered. <code>marketing-shell.tsx</code>
                applies the rebrand. The Blade
                <code>resources/views/marketing/_layout.blade.php</code>
                file is dead code (no longer routed).
            </td>
        </tr>
        <tr>
            <td>Public <code>/changelog</code></td>
            <td>✅ pass</td>
            <td>
                <code>ChangelogController::show</code> shares the
                branded site_title; the entry body is admin-authored so
                "Pitchbar" only appears if the admin types it.
            </td>
        </tr>
        <tr>
            <td>Marketing footer "© Pitchbar..."</td>
            <td>✅ pass</td>
            <td>
                Driven by <code>landingContent.footer.copyright</code>;
                editable from Settings → Marketing.
            </td>
        </tr>
    </tbody>
</table>

<h3>Widget (visitor surface)</h3>

<table>
    <thead><tr><th>Surface</th><th>Status</th><th>Notes</th></tr></thead>
    <tbody>
        <tr>
            <td>"Powered by ..." footer link</td>
            <td>✅ pass</td>
            <td>
                Server-resolved <code>branding.label</code> from
                <code>AppBranding::shared()</code>. Hidden entirely on
                paid plans that strip Pitchbar attribution.
            </td>
        </tr>
        <tr>
            <td>Demo pill ("Live demo ·")</td>
            <td>✅ <strong>fixed in v1.1.0</strong></td>
            <td>
                Was "Live demo · ask anything about Pitchbar". Now
                "Live demo · ask the sandbox agent anything" — generic
                so any white-label install can keep the same widget
                bundle.
            </td>
        </tr>
        <tr>
            <td>Launcher label / chat header / agent name</td>
            <td>✅ pass</td>
            <td>
                Per-agent <code>theme.launcher_label</code> +
                <code>persona.name</code>. Buyer-controlled.
            </td>
        </tr>
        <tr>
            <td><code>window.Pitchbar.mount()</code> global</td>
            <td>⚪ accepted</td>
            <td>
                Protocol concern — embed snippets in the wild already
                key on this name. Renaming would break every existing
                buyer's installation.
            </td>
        </tr>
    </tbody>
</table>

<h3>Outbound emails</h3>

<table>
    <thead><tr><th>Surface</th><th>Status</th><th>Notes</th></tr></thead>
    <tbody>
        <tr>
            <td>Captured-lead notification (<code>NewLeadCaptured</code>)</td>
            <td>✅ pass</td>
            <td>
                Subject + body use <code>AppBranding::siteTitle()</code>
                + the white-labelled lead-capture template.
                Test with the new "Send test lead email" button on
                Settings → System → Mail.
            </td>
        </tr>
        <tr>
            <td>Stripe receipt (cashier)</td>
            <td>✅ pass</td>
            <td>
                White-label cascade applies —
                <code>cashier.invoices.from_address</code> +
                <code>from_name</code> resolved from
                <code>AppSetting</code>.
            </td>
        </tr>
        <tr>
            <td>PayPal / Razorpay receipts</td>
            <td>✅ pass</td>
            <td>
                Notification templates pull from
                <code>AppBranding::siteTitle()</code>. Round-tripped on a
                renamed install.
            </td>
        </tr>
        <tr>
            <td>Paddle / Iyzico / PayU receipts</td>
            <td>⏸ deferred</td>
            <td>
                Gateway integrations not yet shipped (cards #12, #13,
                #14). Re-run this audit row once the gateway code lands
                so the email templates inherit the same white-label
                cascade.
            </td>
        </tr>
    </tbody>
</table>

<h3>Documentation pages</h3>

<table>
    <thead><tr><th>Surface</th><th>Status</th><th>Notes</th></tr></thead>
    <tbody>
        <tr>
            <td><code>/documentation/*</code> Blade pages</td>
            <td>⚪ accepted</td>
            <td>
                Documentation describes the source codebase by name.
                Buyers running a white-label install can edit the
                Blade files directly to substitute their own brand —
                we don't auto-rebrand docs because the project name
                ("Pitchbar") is part of the source identity that the
                buyer is licensing.
            </td>
        </tr>
    </tbody>
</table>

<h2>Summary</h2>

<ul>
    <li><strong>2 leaks fixed in v1.1.0</strong> — sidebar onboarding text + widget DEMO pill.</li>
    <li><strong>0 leaks open</strong> on customer-facing surfaces today.</li>
    <li><strong>3 deferred rows</strong> — Paddle / Iyzico / PayU email templates, blocked on the gateways themselves landing.</li>
    <li>
        <strong>1 accepted by-design</strong> — <code>window.Pitchbar.mount()</code>
        global, kept as a protocol identifier. Documented under
        <a href="/documentation/embed">Embed the widget</a>.
    </li>
</ul>

<p>
    Re-run this audit on every release that touches an outbound email,
    a marketing surface, the widget bundle, or a Fortify auth flow.
    The grep one-liner at the top makes it a 5-minute job.
</p>
