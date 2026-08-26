<?php

namespace App\Services\Widget;

use App\Services\I18n\TranslationLoader;

class WidgetCopy
{
    /**
     * Locked allow-list of keys the widget UI renders. Keeping this
     * list small means the /init payload stays tight (every widget
     * request ships the full map) and translators know exactly which
     * strings need to look perfect on the visitor side.
     *
     * Keys are English source strings — the same `lang/{locale}.json`
     * dictionary the admin SPA uses, so we never duplicate
     * translations.
     */
    public const KEYS = [
        // Launcher + composer
        'Ask anything',
        'Ask a question…',
        'Send',
        'Send message',
        'Menu',
        'Type a message...',
        'Loading...',
        'Close',
        'Clear conversation',

        // Panel headers
        'Live support',
        'AI assistant',
        'Welcome',
        'Get started',

        // Handoff banners (Phase 2 routing)
        'Connect to a human',
        'Talk to a human',
        'Connecting you to an operator…',
        'Connecting you with someone…',
        'An operator is joining you in a moment.',
        'An agent joined the chat',
        ':name joined the chat',
        'Agent is typing…',
        ':name is typing…',
        'Demo',
        'Live agent',
        'Live demo · ask the sandbox agent anything',
        "We'll follow up by email.",
        "No one's around right now",
        "Drop your email and we'll reach out as soon as someone's free.",
        "We're closed right now",
        "We're closed right now.",
        "We're back :time.",
        ":next Drop your email and we'll follow up first thing.",
        'soon',

        // Voice input
        'Voice input',
        'Start voice input',
        'Stop voice input',
        'Voice input not supported in this browser',

        // Demo / sandbox
        'Sandbox agent — for product demonstration only',
        'This is a sandbox agent for product demonstration. Conversations are not stored against any live workspace.',
        'Powered by :name',
        'Continue',
        'Sources',
        'Ask anything about this site.',
        'live agent',
        'Retry',
        'thinking…',
        'Connect me with a human',
        'Product',
        'Plan',
        'Case study',
        'Read more',
        'View',
        'Select an option',
        'Dismiss',
        'Starting chat…',
        'Start chat',
        'Share your details so we can pick up where the chat leaves off.',
        "Leave your details — we'll get back to you.",
        'Please fill in ":label".',
        'Please enter a valid email address.',
        'Could not save your details. Please try again.',

        // Satisfaction prompt
        'Was this helpful?',
        'Yes',
        'No',
        'Anything to add? (optional)',
        'Skip',
        'Thanks for the feedback!',
        'Thanks for chatting!',
        'How was this conversation?',
        'Leave a comment (optional)',
        'Submit rating',
        'Thanks for your feedback!',

        // Lead form / pre-chat gate
        'Email',
        'Email address',
        'Your email',
        'Your name',
        'Phone number',
        'Your message',
        'Submit',
        'Required',
        'Optional',
        'Done',
        'Sending…',
        'Something went wrong.',
        'Please try again.',
        'Reset to default',
    ];

    public function __construct(private readonly TranslationLoader $loader) {}

    /**
     * Build the widget copy map for a given locale. Missing keys fall
     * back to the English source so the widget never renders blank.
     *
     * @return array<string, string>
     */
    public function for(string $locale): array
    {
        $dict = $this->loader->jsonFor($locale);
        $out = [];
        foreach (self::KEYS as $key) {
            $out[$key] = $dict[$key] ?? $key;
        }

        return $out;
    }
}
