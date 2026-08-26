<?php

namespace App\Services\Llm;

/**
 * Pure decision logic for which LLM providers join the failover chain and in
 * what order. Extracted from AppServiceProvider's container binding so it can
 * be unit-tested directly — the binding short-circuits to FakeOpenAi under
 * runningUnitTests(), which otherwise makes the selection rules untestable
 * (and let a stale-key regression ship unnoticed).
 */
class LlmProviderChain
{
    /**
     * Ordered provider names (primary first) for the given configuration.
     *
     * Rules:
     *  - The explicitly-selected provider ($provider) leads, when available.
     *  - The rest follow in default precedence cloudflare → openai → openrouter.
     *  - OpenRouter is available ONLY when explicitly selected OR when a
     *    Cloudflare/OpenAI provider is also configured. A leftover
     *    OPENROUTER_API_KEY must never bind as the SOLE provider — a stale key
     *    401s ("User not found") and hard-fails every stream.
     *
     * @return list<string>
     */
    public static function order(
        string $provider,
        bool $hasCloudflare,
        bool $hasOpenAi,
        bool $hasOpenRouter,
    ): array {
        $available = [];
        if ($hasCloudflare) {
            $available['cloudflare'] = true;
        }
        if ($hasOpenAi) {
            $available['openai'] = true;
        }
        if ($hasOpenRouter && ($provider === 'openrouter' || $hasCloudflare || $hasOpenAi)) {
            $available['openrouter'] = true;
        }

        $order = [];
        if ($provider !== '' && isset($available[$provider])) {
            $order[] = $provider;
        }
        foreach (['cloudflare', 'openai', 'openrouter'] as $name) {
            if (isset($available[$name]) && ! in_array($name, $order, true)) {
                $order[] = $name;
            }
        }

        return $order;
    }

    /**
     * Expand a provider-name order into concrete failover entries,
     * splitting Cloudflare into a primary + a fallback CHAT model when a
     * distinct fallback model is configured.
     *
     * This is the lever that lets a SINGLE-Cloudflare install self-heal
     * model→model: when the primary model is slow / 5xx / cold-starting,
     * the failover decorator transparently retries a second Cloudflare
     * model (typically a smaller, faster one) BEFORE any cross-provider
     * hop — no OpenAI/OpenRouter key required. It answers the operator
     * question "Cloudflare has other models, why jump to OpenAI?": we try
     * another Cloudflare model first.
     *
     * Non-Cloudflare providers pass through unchanged, carrying a null
     * model that means "use the client's own configured model". The
     * fallback entry is dropped when it's empty or identical to the
     * primary (a same-model retry buys nothing — it hits the same model
     * with the same failure).
     *
     * @param  list<string>  $order  Provider names from {@see self::order()}.
     * @return list<array{name: string, cloudflare_model: string|null}>
     */
    public static function entries(array $order, string $cfPrimaryModel, string $cfFallbackModel): array
    {
        $cfPrimaryModel = trim($cfPrimaryModel);
        $cfFallbackModel = trim($cfFallbackModel);

        $entries = [];
        foreach ($order as $name) {
            if ($name !== 'cloudflare') {
                $entries[] = ['name' => $name, 'cloudflare_model' => null];

                continue;
            }

            $entries[] = [
                'name' => 'cloudflare',
                'cloudflare_model' => $cfPrimaryModel !== '' ? $cfPrimaryModel : null,
            ];

            if ($cfFallbackModel !== '' && $cfFallbackModel !== $cfPrimaryModel) {
                $entries[] = [
                    'name' => 'cloudflare-fallback',
                    'cloudflare_model' => $cfFallbackModel,
                ];
            }
        }

        return $entries;
    }
}
