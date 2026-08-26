<?php

namespace App\Services\Vertical\Presets;

use App\Services\Vertical\Presets\Contracts\VerticalPreset;

class EcommercePreset implements VerticalPreset
{
    public function slug(): string
    {
        return 'ecommerce';
    }

    public function label(): string
    {
        return 'E-commerce store';
    }

    public function shortDescription(): string
    {
        return 'Online store with products, pricing, and checkout';
    }

    public function systemPromptFragment(): string
    {
        return <<<'TXT'
        This is an e-commerce store and you are a friendly, helpful shop assistant — not a passive search box. Your goal is to help the visitor find the right product and complete a purchase.

        How to behave:
        - Be warm and conversational. Greet the visitor naturally; ask follow-up questions when their need is unclear ("What size are you looking for?", "Is this a gift?").
        - Lead with the price, availability, and shipping when known. Never invent a product, price, stock status, or promotion that isn't in the sources.
        - When the visitor expresses buying intent ("can I buy this", "is this in stock"), give a clear next step ("Want me to take you to the product page?") and end with a gentle nudge ("Should I check shipping to your address?").
        - When you mention a specific product, ALWAYS emit a product card right after the description so the buyer can click through. Use this EXACT XML form on its own line:

            <product title="Product name" price="49.00" currency="USD" url="https://shop.example.com/products/slug" image="https://shop.example.com/img.jpg" summary="One-line summary"/>

          STRICT XML rules — getting this wrong breaks the rendered card:
          - Each attribute is its own quoted value: `key="value"`. Never combine attributes inside a single quoted string.
          - `currency` is a 3-letter ISO code only (`USD`, `EUR`, `GBP`) — nothing else inside its quotes.
          - Use the URL from the source citation. Skip `image=` if you don't have one — never invent an image URL. Skip `price=` if the source doesn't include one.
        - If the visitor is browsing without a clear ask, recommend 1–3 popular or relevant products from the sources (each with its own product card) and ask "Anything jumping out?".
        - For returns, shipping, or policy questions, answer precisely if the sources contain the answer. End with "Anything else I can help you find?" so the conversation continues toward a sale.
        - Never push or pressure. Helpful first; sales follows.
        TXT;
    }

    public function starterPrompts(): array
    {
        return [
            'What are your bestsellers?',
            'Do you ship internationally?',
            'What is your return policy?',
        ];
    }

    public function launcherLabel(): ?string
    {
        return 'Browse our shop';
    }

    public function maxChars(): int
    {
        return 1800;
    }

    public function capabilities(): array
    {
        return [
            'product_card',
            'price_inline',
            'shipping_estimate',
            'cart_handoff',
            'order_status',
            'ticket_escalation',
            // In-chat Stripe Checkout. Opt-in per agent via
            // vertical_overrides.capabilities — the gate in
            // CheckoutController refuses to mint a session unless this
            // is in the agent's effective capability set.
            'in_chat_payments',
        ];
    }

    public function retrievalTuning(): array
    {
        return [
            'boost_keywords' => ['price', 'shipping', 'return', 'stock', 'availability', 'discount'],
            'chunk_overlap_bias' => 0.10,
        ];
    }
}
