<p>
    <code>/admin/users</code> lists every user account on the platform —
    super-admins, customers, and members invited into any workspace. The
    page supports search by name/email, role promotion, BYOK per-user
    overrides, edit, impersonate, and (since v1.1) <strong>delete</strong>.
</p>

<h2>Delete</h2>

<p>
    Super-admin only. Click the <strong>Delete</strong> button on any
    user row (the button is hidden on your own row). A confirm dialog
    spells out the consequence — they lose access to every workspace and
    the row is soft-deleted for audit.
</p>

<h3>Safety rails</h3>

<p>The controller refuses four cases:</p>

<ul>
    <li>
        <strong>Self-delete</strong> — you cannot delete the account you
        are signed into. Would lock you out of admin.
    </li>
    <li>
        <strong>Last super-admin</strong> — defence-in-depth check; the
        controller refuses if the target is a super-admin and no other
        live super-admin exists.
    </li>
    <li>
        <strong>Workspace owner</strong> — if the user still owns one or
        more workspaces, the delete returns an error toast naming the
        count. Transfer ownership at
        <code>/admin/workspaces/{id}</code> first (or via the workspace's
        own Members &amp; roles page) and retry.
    </li>
    <li>
        <strong>Non-super-admin actor</strong> — the route is in the
        platform-admin group; a customer hitting <code>DELETE
        /admin/users/{id}</code> directly returns 404 to hide the
        endpoint's existence.
    </li>
</ul>

<h3>What soft-delete touches</h3>

<ul>
    <li>
        <code>users.deleted_at</code> is set to the current timestamp.
        Subsequent reads via Eloquent's default scope exclude the row.
    </li>
    <li>
        All <code>workspace_users</code> pivot rows for the user are
        deleted so a re-invited account doesn't collide on the unique
        composite key.
    </li>
    <li>
        Foreign-key references in <code>conversations</code>,
        <code>leads</code>, <code>audit_logs</code>, and other tables
        with a <code>user_id</code> column are left intact — soft-delete
        preserves the row, so those joins keep working.
    </li>
    <li>
        An <code>AuditLog</code> entry with
        <code>action=platform_user.deleted</code> is written under the
        acting admin's default workspace, including the deleted email +
        role for forensic recovery.
    </li>
</ul>

<h3>Customer-initiated deletion (different code path)</h3>

<p>
    When a customer deletes their <em>own</em> account from
    <code>/settings/profile</code>, the row is
    <strong>hard-deleted</strong> via <code>forceDelete()</code> — a
    customer-initiated deletion is treated as a GDPR right-to-erasure
    request, so the data goes away for real. Only the platform-admin
    delete is soft.
</p>

<h2>Restoring a soft-deleted user</h2>

<p>
    There is no admin UI for restore yet. To bring back a soft-deleted
    account run:
</p>

<pre><code>php artisan tinker --execute 'App\Models\User::withTrashed()->where("email","x@y.com")->first()->restore();'</code></pre>

<p>
    The user's workspace memberships are NOT restored — they need a
    fresh invitation. If the original workspace is gone too,
    <code>default_workspace_id</code> will be stale and the next visit
    redirects them through the standard onboarding flow.
</p>
