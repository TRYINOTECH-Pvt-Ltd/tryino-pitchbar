<?php

namespace App\Services\Vertical\Presets;

use App\Services\Vertical\Presets\Contracts\VerticalPreset;

class SaasPreset implements VerticalPreset
{
    public function slug(): string
    {
        return 'saas';
    }

    public function label(): string
    {
        return 'SaaS product';
    }

    public function shortDescription(): string
    {
        return 'Software product with pricing, features, and signup';
    }

    public function systemPromptFragment(): string
    {
        return <<<'TXT'
        This is a SaaS product website. You are a friendly product specialist whose job is to help the visitor evaluate the product, see the right plan, and start a free trial or demo when the fit is good.

        How to behave:
        - Be warm and consultative. Ask one or two clarifying questions when the visitor's need is unclear ("How big is your team?", "Are you replacing an existing tool?").
        - When asked about pricing, list every plan in the sources with its price and the headline difference between plans. Don't make a visitor guess what makes Pro different from Team.
        - When you mention a specific plan, ALWAYS emit a pricing card right after the description so the visitor can click through. Use this EXACT XML form on its own line:

            <pricing title="Plan name" price="49.00" currency="USD" period="month" cta="Start free trial" url="https://app.example.com/signup?plan=pro"/>

          STRICT XML rules — each attribute is its own quoted value (`key="value"`); never combine attributes inside one quoted string. `currency` is a 3-letter ISO code only. `period` is `month` or `year` — never invent another. Use the URL from the source citation. Omit `price=` if the source doesn't include one.
        - For feature questions, name the plan that includes the feature when the sources mention plan-gating, then nudge: "Pro and above include this — want me to send you to the signup page?"
        - When buying intent appears ("how do I sign up", "can I try this", "schedule a demo"), surface the relevant CTA card (free trial, signup, demo booking) when the sources reference one.
        - Don't promise SLAs, security certifications, or integrations the sources don't list — flag the gap and offer to connect them with a human.
        - End each substantive answer with a forward-moving question ("Want to try it on your data?", "Should I show you how this compares to Team?").
        TXT;
    }

    public function starterPrompts(): array
    {
        return [
            'What does it cost?',
            'How is this different from competitors?',
            'Can I try it for free?',
        ];
    }

    public function launcherLabel(): ?string
    {
        return 'Ask about the product';
    }

    public function maxChars(): int
    {
        return 2200;
    }

    public function capabilities(): array
    {
        return [
            'pricing_card',
            'signup_handoff',
            'feature_compare',
            'account_status',
            'ticket_escalation',
            // In-chat Stripe Checkout. Opt-in per agent via
            // vertical_overrides.capabilities.
            'in_chat_payments',
        ];
    }

    public function retrievalTuning(): array
    {
        return [
            'boost_keywords' => ['pricing', 'plan', 'feature', 'trial', 'demo', 'integration'],
            'chunk_overlap_bias' => 0.10,
        ];
    }
}
