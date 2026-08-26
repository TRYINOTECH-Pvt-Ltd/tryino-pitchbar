<p>
    Pitchbar's self-improvement loop watches every conversation and
    flags questions your agent could not answer confidently. Those
    questions cluster into <strong>content gaps</strong> at
    <code>/app/analytics/content-gaps</code>. Treat the page as a
    prioritised backlog of what to teach your bot next.
</p>

<h2>How a gap is detected</h2>

<p>
    After every visitor turn — once the stream has already emitted
    <code>done</code>, so the visitor's latency is unaffected —
    <code>DetectGapJob</code> runs <strong>inline</strong>
    (<code>dispatchSync</code>) with the user message and the agent id.
    It is deliberately NOT left on the <code>analytics</code> queue:
    gap-recording is the only visible surface of the self-improvement
    loop, and a production worker started without that queue in its list
    would silently strand every gap unwritten. Running it inline — the
    same guarantee <code>PersistTurnJob</code> has — means a misconfigured
    worker can never drop a gap. The follow-up email is the only queued
    part (see below). The job:
</p>

<ol>
    <li>Normalises the question (lowercase + collapse whitespace) and
        hashes it.</li>
    <li>Looks up an existing gap with the same hash for this agent;
        if found, increments <code>occurrences</code> and refreshes
        <code>last_seen_at</code>.</li>
    <li>Otherwise inserts a new row in <code>content_gaps</code>
        with <code>status=open</code>.</li>
</ol>

<p>
    The job is single-attempt — duplicating a gap row is harmless,
    but retrying after a transient DB hiccup would over-inflate
    <code>occurrences</code>.
</p>

<h2>What the UI shows</h2>

<ul>
    <li><strong>Agent</strong> picker — narrow to one bot when you
        have several.</li>
    <li><strong>Status</strong> — Open (default), Answered (someone
        added a curated answer / source), Ignored (won't fix).</li>
    <li><strong>Window</strong> — last 7 / 30 / 90 days, or all
        time.</li>
</ul>

<p>
    Each row carries the question text, the count of times it has
    been asked, the last time it was asked, and the agent it belongs
    to.
</p>

<h2>Actions</h2>

<ul>
    <li><strong>Curated answer</strong> — jumps to the curated-answers
        editor for that agent with the question pre-filled. Once you
        save the curated answer, the next visitor who asks that
        question gets a direct response without going through
        retrieval.</li>
    <li><strong>Resolve</strong> — marks the gap as
        <code>answered</code> (the source / curated answer that
        addresses it is live).</li>
    <li><strong>Ignore</strong> — marks <code>ignored</code> (out of
        scope for this bot, e.g. visitor asked about a product the
        workspace doesn't sell).</li>
</ul>

<h2>Who can do what</h2>

<table>
    <thead><tr><th>Role</th><th>View gaps</th><th>Resolve / Ignore</th></tr></thead>
    <tbody>
        <tr><td>Owner / Admin</td><td>Yes</td><td>Yes</td></tr>
        <tr><td>Editor</td><td>Yes</td><td>Yes</td></tr>
        <tr><td>Viewer</td><td>Yes</td><td>No</td></tr>
    </tbody>
</table>

<h2>Why this matters</h2>

<p>
    Most chatbots silently degrade — a question never gets answered,
    the visitor bounces, no one notices. Pitchbar turns those silent
    failures into a triage queue. Working the gap queue weekly is the
    fastest path to a high-quality agent.
</p>
