<p>
    Visitors who hit a wall with the AI agent can ask for a human and
    actually get one — in real time, using the same Conversations menu
    your team already uses to read transcripts.
</p>

<h2>Visitor flow</h2>

<ol class="mt-2 list-decimal space-y-1.5 ps-6 text-[15px] leading-7 text-slate-700">
    <li>
        The widget header carries an always-visible
        <strong>Talk to a human</strong> button. Visitors don't need to
        wait for the LLM to surface a pill — one click reaches a human
        from any conversation state. The button stays for every agent
        whose vertical includes the <code>ticket_escalation</code>
        capability (every preset ships with it enabled by default).
    </li>
    <li>
        The agent also detects human-handoff intent in the message
        body — <code>HumanIntentDetector</code> matches against ~40
        phrases (<em>"talk to a human"</em>, <em>"connect me to an
        agent"</em>, <em>"I need to speak with someone"</em>, etc.).
        Typing one of those short-circuits the LLM and goes straight
        to the handoff flow.
    </li>
    <li>
        On click / intent-match, three things happen instantly:
        <ul class="mt-1 list-disc space-y-0.5 ps-6 text-sm">
            <li>
                The conversation is flagged with a <code>human_requested_at</code>
                timestamp on the server.
            </li>
            <li>
                A <code>HumanRequestedEvent</code> broadcasts to
                the workspace's Reverb private channel so dashboard
                tabs update in real time.
            </li>
            <li>
                <code>NotifyOperatorsHumanRequestedJob</code> fans out
                database + mail notifications to every workspace
                member with <code>live_chat_available=true</code>.
            </li>
        </ul>
    </li>
    <li>
        The widget shows a <strong>"Connecting you with someone…"</strong>
        banner. The visitor stays on this banner for up to <strong>2
        minutes</strong>. While the conversation is in the waiting
        window, the bot is silent — every subsequent visitor message
        is queued for the operator instead of getting an LLM reply.
    </li>
    <li>
        If an operator claims within the window, a one-time
        <strong>"Sarah joined the chat"</strong> notice appears (or
        <strong>"An agent joined"</strong> if you've turned off
        personalization in workspace settings). It's a transient
        announcement — it shows for about 8 seconds and then clears
        itself, so it never sits pinned above the input. The ongoing
        "a human is here" state is carried by the pulsing
        <strong>"Live agent"</strong> pill in the chat header, which
        stays for as long as the operator owns the conversation.
    </li>
    <li>
        If no operator claims after the 2-minute timeout, the widget
        surfaces <em>"No one's around right now — drop your email and
        we'll reach out as soon as someone's free."</em> The lead form
        opens for out-of-band follow-up. The notification still went
        out, so operators can claim the conversation from the dashboard
        later.
    </li>
    <li>
        The operator's replies appear inline as chat bubbles with an
        "Operator" label. The visitor's messages flow back to the
        operator's console live (3-second polling).
    </li>
</ol>

<h2>Operator flow</h2>

<ol class="mt-2 list-decimal space-y-1.5 ps-6 text-[15px] leading-7 text-slate-700">
    <li>
        The <strong>Conversations</strong> sidebar entry shows a red
        badge with the count of conversations waiting for a human.
    </li>
    <li>
        Open <a href="/app/conversations" class="font-semibold underline-offset-2 hover:underline">/app/conversations</a>
        and switch to the <strong>Needs human</strong> filter pill.
        Each row shows when the visitor clicked the human button, what
        page they're on, and their captured email if any.
    </li>
    <li>
        Click into a row → the thread shows live messages. Click
        <strong>Claim</strong> at the top right to take the conversation.
    </li>
    <li>
        While you have the conversation claimed:
        <ul class="mt-1 list-disc space-y-0.5 ps-6 text-sm">
            <li>The bot will <strong>not</strong> auto-respond.</li>
            <li>Use the reply box at the bottom (Cmd / Ctrl + Enter sends).</li>
            <li>Visitor messages appear in real time — the page polls every 2 seconds while you're claimed.</li>
        </ul>
    </li>
    <li>
        Click <strong>Release</strong> when you're done. The server
        clears <code>claimed_by_user_id</code>, <code>claimed_at</code>,
        <code>human_requested_at</code>, and the operator-typing window,
        then posts a one-line system message to the chat
        ("The operator has stepped away. The AI assistant is back to
        help."). The visitor's "Connecting you with someone…" banner
        disappears within the next poll; the conversation history stays
        intact for analytics + future reference, and the AI resumes
        on the next visitor message.
    </li>
</ol>

<h2>Workspace settings</h2>

<p>
    A few knobs live in <a href="/settings/system" class="font-semibold underline-offset-2 hover:underline">Settings</a> on
    the workspace level:
</p>

<ul class="mt-2 list-disc space-y-2 ps-6 text-[15px] leading-7 text-slate-700">
    <li>
        <strong>Live chat personalize</strong> (default ON). When on,
        the visitor sees the operator's real name in the joined
        banner. Turn it off for regulated industries (legal,
        healthcare, finance) where individual operator identities
        shouldn't be exposed — the visitor sees "An agent from
        &lt;Brand&gt;" instead.
    </li>
    <li>
        <strong>Workspace name</strong> (<a href="/settings/workspace" class="font-semibold underline-offset-2 hover:underline">/settings/workspace</a>) — the label the team sees in
        the sidebar and on outgoing emails. Editable by Owner and
        Admin roles; Editors and below see a 403.
    </li>
</ul>

<h2>Notifications</h2>

<p>
    Two cascades fire when a visitor asks for a human:
</p>

<ul class="mt-2 list-disc space-y-2 ps-6 text-[15px] leading-7 text-slate-700">
    <li>
        <strong><code>HumanRequestedEvent</code> broadcast</strong> on
        the workspace's Reverb private channel. Every open dashboard
        tab picks it up and updates the Conversations sidebar badge +
        plays a sonner toast in real time.
    </li>
    <li>
        <strong><code>NotifyOperatorsHumanRequestedJob</code></strong>
        — queued database + mail notification (<code>HumanRequestedNotification</code>)
        fanned out to every workspace member with
        <code>users.live_chat_available = true</code>. Members who
        had the dashboard closed still get an email so they can claim
        the conversation on next sign-in.
    </li>
</ul>

<p>
    If a captured lead also fires, the lead-captured email + dashboard
    toast cascade runs in addition. The handoff notification is
    idempotent: a second click within the 2-minute waiting window
    does not re-broadcast or re-notify.
</p>

<h2>Smart routing</h2>

<p>
    The server picks one of three responses based on operator
    availability and your business hours when a visitor asks for a
    human:
</p>

<ul class="mt-2 list-disc space-y-2 ps-6 text-[15px] leading-7 text-slate-700">
    <li>
        <strong>Queued (operator online).</strong> An operator is
        online and within your business hours. The visitor sees
        <em>"Connecting you with someone…"</em>, the conversation gets
        flagged, the bot stays silent, and operators get notified.
        The wait is capped at 2 minutes
        (<code>RequestHumanController::WAIT_TIMEOUT_SECONDS = 120</code>).
    </li>
    <li>
        <strong>Queued anyway (no operators online).</strong> Within
        business hours but nobody's marked themselves <em>Receive
        live chats</em>. <strong>Still queues + still notifies</strong> —
        the database + mail notification reaches every member with
        the live-chat opt-in, so they can claim the conversation
        when they open the dashboard next. The visitor sees the same
        "Connecting you…" banner for the 2-minute window. After the
        timeout, the widget flips to <em>"No one's around right now
        — drop your email and we'll reach out."</em>
    </li>
    <li>
        <strong>Offline — after hours.</strong> Outside the business
        hours you've configured. Visitor sees <em>"We're closed right
        now. We're back &lt;day at time&gt;. Drop your email and we'll
        follow up first thing."</em>
    </li>
</ul>

<h2>Operator opt-in</h2>

<p>
    Each workspace member sets their availability individually in
    <a href="/settings/profile" class="font-semibold underline-offset-2 hover:underline">Profile settings</a>:
</p>

<ul class="mt-2 list-disc space-y-1 ps-6 text-[15px] leading-7 text-slate-700">
    <li>
        <strong>Receive live chats</strong> checkbox — when on AND your
        admin tab has been active in the last 2 minutes, you count as
        an available operator.
    </li>
    <li>
        Heartbeat fires every 60s while the tab is in the foreground,
        pauses when the tab is hidden.
    </li>
</ul>

<h2>Business hours</h2>

<p>
    Configure in
    <a href="/app/settings/live-chat" class="font-semibold underline-offset-2 hover:underline">Settings → Live chat</a>.
    JSON-edited for now (a visual grid editor lands in the next
    release):
</p>

<pre class="mt-3 overflow-x-auto rounded-md border bg-slate-50 p-3 text-[13px] leading-6 text-slate-700"><code>{
  "enabled": true,
  "timezone": "America/New_York",
  "schedule": {
    "monday":    [{"start": "09:00", "end": "17:00"}],
    "tuesday":   [{"start": "09:00", "end": "17:00"}],
    "wednesday": [
      {"start": "09:00", "end": "12:00"},
      {"start": "13:00", "end": "17:00"}
    ],
    "thursday":  [{"start": "09:00", "end": "17:00"}],
    "friday":    [{"start": "09:00", "end": "17:00"}],
    "saturday":  [],
    "sunday":    []
  }
}</code></pre>

<ul class="mt-3 list-disc space-y-1 ps-6 text-[15px] leading-7 text-slate-700">
    <li>Day keys lowercase. 24-hour <code>HH:mm</code>. Empty array = closed all day.</li>
    <li>Multiple windows per day are supported (e.g. lunch break).</li>
    <li>Timezone is any IANA identifier — the server validates against PHP's tz database.</li>
    <li>Set <code>"enabled": false</code> (or leave the whole field blank) to stay always-on.</li>
</ul>

<h2>Slack / Teams notifications</h2>

<p>
    In <a href="/app/settings/live-chat" class="font-semibold underline-offset-2 hover:underline">Settings → Live chat</a>,
    paste an incoming-webhook URL for either platform. When a visitor
    asks for a human, a queued listener fires a compact ping with the
    conversation URL so an operator can jump straight in from
    Slack / Teams without opening the dashboard.
</p>

<ul class="mt-2 list-disc space-y-1 ps-6 text-[15px] leading-7 text-slate-700">
    <li>
        Slack — uses the standard <code>incoming-webhook</code> URL from
        the Slack app config. Slack auto-unfurls the conversation link.
    </li>
    <li>
        Microsoft Teams — incoming-webhook URL from the Teams channel
        connector. We post as a MessageCard with an "Open conversation"
        button.
    </li>
</ul>

<h2>Auto-fallback for unclaimed conversations</h2>

<p>
    Visitors should never sit on a "Connecting you…" bubble forever.
    The widget runs a client-side 2-minute timer the moment the
    handoff is requested (see <code>RequestHumanController::WAIT_TIMEOUT_SECONDS</code>).
    When the timer fires without a claim, the banner flips to the
    "No one's around" copy and the lead form opens so the visitor
    can leave their email. The conversation row stays flagged on
    the server so the operator can still claim later — the
    notification cascade fires once on the initial request, so
    operators see the queued conversation in the Conversations
    sidebar regardless of whether the visitor stayed on the page.
</p>

<h2>Operator polish (Phase 3)</h2>

<h3>Canned replies</h3>

<p>
    Save the replies your team types over and over (password reset
    instructions, refund policy, shipping ETAs) at
    <a href="/app/settings/canned-replies" class="font-semibold underline-offset-2 hover:underline">Settings → Canned replies</a>.
    Each entry has a short label (what operators search by) and the
    full reply text. Reorder with the up/down handles — most-used
    replies should sit at the top.
</p>

<p>
    In any live conversation, click the <strong>Canned reply</strong>
    button above the textarea. Fuzzy-search the label or content,
    pick one, and the textarea fills in. The operator can edit before
    hitting Send.
</p>

<h3>Internal notes</h3>

<p>
    Toggle the reply box from <strong>Reply</strong> to
    <strong>Internal note</strong> (the textarea turns amber). Internal
    notes are visible to other operators in the conversation thread
    but <em>never</em> sent to the visitor. Useful for handoff
    context: "Visitor seems frustrated — I tried X already, please
    pick up." Auto-generated transfer audit messages also use this
    role.
</p>

<h3>Typing indicators</h3>

<p>Both directions, no setup required:</p>
<ul class="mt-2 list-disc space-y-1 ps-6 text-[15px] leading-7 text-slate-700">
    <li>The visitor sees <em>"&lt;Operator&gt; is typing…"</em> above
        their chat while you're typing in the operator console.</li>
    <li>The operator sees a three-dot bubble in the message log while
        the visitor is typing in the widget.</li>
</ul>
<p class="mt-2">
    Implemented as 5-second self-expiring server-side timestamps; both
    sides poll the existing endpoints, so no extra infrastructure is
    needed.
</p>

<h3>Conversation transfer</h3>

<p>
    Click the <strong>Transfer</strong> button in the reply bar. Online
    teammates surface at the top with a green "Online" badge; offline
    teammates are still listed (you may want to hand off to someone
    who'll claim later). Picking a target reassigns the claim,
    broadcasts to the visitor's widget so the "joined the chat" banner
    refreshes to the new operator's name, and drops a system note in
    the thread for context.
</p>

<h3>Business-hours grid editor</h3>

<p>
    The Phase 2 JSON textarea is replaced by a visual 7-day grid at
    <a href="/app/settings/live-chat" class="font-semibold underline-offset-2 hover:underline">Settings → Live chat</a>.
    Click the "+ Window" button on any day to add another open block
    (lunch break, split shifts), or check "Closed" to take that day
    off. Timezone picker has the 15 most common IANA zones plus a
    "Custom…" option for any zone PHP recognizes.
</p>

<h2>Tags + ratings + UI rebuild (Phase 4)</h2>

<h3>Conversation tags</h3>

<p>
    Categorize conversations so your team can filter and report on them.
    Manage the list at
    <a href="/app/settings/tags" class="font-semibold underline-offset-2 hover:underline">Settings → Tags</a>:
    each tag has a label (max 60 chars) and a hex color that drives the
    chip background.
</p>

<p>
    In any conversation thread, the end-side panel has a Tags
    section. Click a chip's <strong>×</strong> to detach;
    <strong>+ Tag</strong> opens a fuzzy-search dropdown of unselected
    workspace tags.
</p>

<p>
    Conversations list: a "Tags" select dropdown joins the existing
    Needs human / Live now filter pills. Each row also surfaces
    applied tags as compact chips alongside the existing badges.
</p>

<h3>Satisfaction ratings</h3>

<p>
    After an operator releases the conversation, the visitor sees a
    "Was this helpful?" prompt with thumbs up / down buttons + an
    optional comment field. The first rating is locked server-side;
    later submissions update the comment only — buyers complained
    about Intercom-style "rating overwritten" surprises.
</p>

<p>
    Operator side: the end-pane context panel surfaces the rating
    + comment under the visitor card. The Conversations list also
    shows a 👍 / 👎 chip on each row when a rating exists.
</p>

<h3>Conversation thread UI rebuild</h3>

<p>
    The thread page is now a true help-desk surface:
</p>

<ul class="mt-2 list-disc space-y-1 ps-6 text-[15px] leading-7 text-slate-700">
    <li><strong>Two-pane layout</strong> on desktop (≥ md) — chat on
        the left, context sidebar on the right. The sidebar collapses
        to a Sheet drawer with a "Details" button on mobile.</li>
    <li><strong>Right-pane sidebar</strong> sections: visitor (anon
        ID, language, returning flag, page URL with link icon), lead
        (email mailto / phone tel: links), tags, satisfaction signal,
        timing tile (claim age / waiting time / started-at).</li>
    <li><strong>Compact unified action bar</strong> at the top: live
        pill, status badges, claim / release / force-release buttons
        in one cluster.</li>
    <li><strong>Composer</strong> stays at the bottom with Reply /
        Note tabs, canned reply picker, transfer dropdown, and typing
        debouncer (all carried over from Phase 3).</li>
</ul>

<h2>Routes shipped (cumulative)</h2>

<ul class="mt-2 list-disc space-y-1 ps-6 text-[15px] leading-7 text-slate-700">
    <li><code>POST /app/conversations/&#123;id&#125;/note</code> — internal note</li>
    <li><code>POST /app/conversations/&#123;id&#125;/typing</code> — operator typing hint</li>
    <li><code>POST /app/conversations/&#123;id&#125;/transfer</code> — reassign claim</li>
    <li><code>POST / DELETE /app/conversations/&#123;id&#125;/tags/&#123;tagId&#125;</code> — attach / detach tag</li>
    <li><code>POST /api/v1/widget/typing</code> — visitor typing hint (JWT)</li>
    <li><code>POST /api/v1/widget/satisfaction</code> — visitor rating (JWT)</li>
    <li><code>GET / POST / PATCH / DELETE /app/settings/canned-replies/…</code> — CRUD + reorder</li>
    <li><code>GET / POST / PATCH / DELETE /app/settings/tags/…</code> — workspace tag CRUD</li>
</ul>
