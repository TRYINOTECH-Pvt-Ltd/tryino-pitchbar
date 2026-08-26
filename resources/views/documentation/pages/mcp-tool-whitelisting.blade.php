<p>
    Pitchbar exposes tools to the LLM only when the workspace admin
    has explicitly enabled them for that agent. Discovery never auto-enables;
    a new tool is always disabled until acknowledged.
</p>

<h2>Why per-tool whitelist</h2>

<ul>
    <li>A visitor-facing chatbot must NEVER inherit a write-permission tool by accident.</li>
    <li>The same MCP server may expose tools that are safe (search) and unsafe (delete).
        The admin should be able to enable one without the other.</li>
    <li>Different agents may need different subsets — the marketing-site bot doesn't need
        the same write access as the internal support bot.</li>
</ul>

<h2>Destructive tools</h2>

<p>
    MCP defines an optional annotation flag <code>destructiveHint</code>. Pitchbar
    mirrors that into a "Destructive" badge on the tool row. Enabling a destructive
    tool requires a confirmation modal that shows the input schema so the admin can
    inspect exactly what arguments the LLM will pass.
</p>

<h2>Schema drift behaviour</h2>

<p>
    When a refresh discovers that an existing tool's <code>input_schema</code>
    has changed since the last sync, every active grant for that tool is automatically
    flipped to disabled. The admin must re-approve the tool with the new schema visible.
    Rationale: the LLM cannot guess the new parameter shape, and a parameter that has
    grown wider may carry new security implications.
</p>

<h2>Tombstoned tools</h2>

<p>
    When a server stops reporting a tool, Pitchbar marks the row tombstoned
    (<code>removed_at</code> is set) rather than deleting it. Existing audit-log
    references survive; tombstoned tools never reach the LLM payload again until they
    reappear in a refresh.
</p>
