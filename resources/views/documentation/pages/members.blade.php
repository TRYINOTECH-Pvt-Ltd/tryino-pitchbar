<p>
    A workspace can have many members, each with a role. Roles determine
    what they can do — manage agents, see billing, kick other members.
    This page covers the roles, the invitation flow, and what changes
    when a member is added or removed.
</p>

<h2>Roles</h2>

<p>
    Four roles, in order of authority:
</p>

<table>
    <thead><tr><th>Role</th><th>Can do</th></tr></thead>
    <tbody>
        <tr><td><strong>Owner</strong></td><td>Everything an Admin can plus manage billing. Only role that can subscribe / change plans / cancel.</td></tr>
        <tr><td><strong>Admin</strong></td><td>Manage members and invitations. Manage agents, knowledge, behavior rules, integrations. Read all conversations and leads. Cannot manage billing.</td></tr>
        <tr><td><strong>Editor</strong></td><td>Manage agents, knowledge, behavior rules. Read all conversations and leads. Cannot manage members or billing.</td></tr>
        <tr><td><strong>Viewer</strong></td><td>Read-only. View agents, conversations, leads, analytics. Cannot edit anything.</td></tr>
    </tbody>
</table>

<p>
    Roles live in the <code>workspace_users</code> pivot table; the
    <code>WorkspaceRole</code> enum exposes capability methods —
    <code>canManageAgents()</code> (Owner / Admin / Editor),
    <code>canManageMembers()</code> (Owner / Admin),
    <code>canManageBilling()</code> (Owner only),
    <code>canViewAnalytics()</code> (everyone) — used by the policy layer.
</p>

<h2>Inviting</h2>

<p>
    From <code>/app/members</code>, an Owner or Admin can invite by email.
    The invite gets emailed with a tokenized link to
    <code>/invitations/{token}</code>. Clicking it:
</p>

<ol>
    <li>Shows the invite preview (workspace name, who invited, role).</li>
    <li>Asks the visitor to log in or sign up if they aren't.</li>
    <li>On accept, attaches them to the workspace with the assigned role and redirects to the dashboard.</li>
</ol>

<p>
    Invites expire after 7 days. Owners and Admins can revoke a pending
    invitation from the <strong>Pending invitations</strong> section on
    <code>/app/members</code> — click <strong>Revoke</strong>, confirm,
    and the row is deleted. The revoke writes an
    <code>invitation.revoked</code> entry to the audit log. Already-accepted
    invitations cannot be revoked — remove the member from the
    <strong>Current members</strong> list instead.
</p>

<h2>Changing roles</h2>

<p>
    Owners and Admins can change other members' roles. Admins can't touch
    other Admins or the Owner. The change takes effect immediately — no
    re-login needed.
</p>

<h2>Removing</h2>

<p>
    Removing a member detaches them from the workspace. Their conversations
    they took over stay attributed to them historically (the audit log
    keeps actor IDs). Their personal account survives — they just lose
    access to this workspace.
</p>

<h2>Owner transfer</h2>

<p>
    Every workspace has exactly one Owner. Transferring ownership is a
    two-step:
</p>

<ol>
    <li>The current Owner picks a target member and clicks <strong>Transfer</strong>.</li>
    <li>The target accepts in their notifications. Until they accept, the transfer is pending.</li>
</ol>

<p>
    The original Owner becomes an Admin after the transfer. They can be
    demoted further if needed.
</p>

<h2>Multi-workspace users</h2>

<p>
    A user can be a member of any number of workspaces. The sidebar's
    workspace switcher (the workspace name card above the user avatar)
    lets them jump between memberships and, via the
    <strong>Create new workspace</strong> entry at the bottom of the
    dropdown, mint a new one. Each workspace has its own role for the
    user — an Owner of one might be a Viewer of another. The number of
    workspaces an Owner can mint is capped by
    <code>plans.workspaces_limit</code>; the cap is enforced
    server-side and the dialog surfaces the error inline.
</p>

<p>
    The "current workspace" is resolved from the user's
    <code>default_workspace_id</code> field. Switching writes the new ID.
    All tenant-scoped queries thereafter run against that workspace.
    When a visitor accepts an invitation while their current default is
    a personal/auto-created workspace (one they own themselves), the
    accept handler switches their default to the invited workspace so
    they land in the right place. If their current default is itself
    an invited workspace they're actively using, the accept does NOT
    switch — to avoid yanking them out of context.
</p>

<h2>Audit log</h2>

<p>
    Member changes — invitations sent, accepted, revoked, role changes,
    ownership transfers, removals — write rows to the <code>audit_logs</code>
    table for forensic traceability. There's no UI page for browsing the
    audit log in v1; query the table directly when you need to investigate.
</p>
