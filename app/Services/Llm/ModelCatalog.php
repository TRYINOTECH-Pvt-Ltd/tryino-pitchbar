<?php

namespace App\Services\Llm;

/**
 * Curated list of EVERY chat model we recommend or accept per provider,
 * with the baseline time-to-first-token (ms) admins can expect from a
 * typical North-American / European edge. The settings page renders
 * these as a grouped dropdown (Fast / Medium / Slow) so buyers see
 * every option a provider exposes — free, paid, experimental, legacy —
 * not just our top picks. Live measurement (ModelLatencyProbe) refines
 * the per-install number; the catalogue is the starting estimate.
 *
 * Numbers come from each provider's public latency dashboards and our
 * own measurements as of 2026-06. Update the catalogue when a vendor
 * ships a new model — it's intentionally hand-curated so we are
 * deliberate about tier/cost/tool-support flags.
 *
 * Schema:
 *   id              vendor model identifier (passed to the API verbatim)
 *   label           short human label (dropdown row, before the icons)
 *   provider        cloudflare | openai | openrouter
 *   ttft_ms         expected time-to-first-token in ms (lower = snappier)
 *   tier            fast | medium | slow (drives a colour pill in UI)
 *   cost            free | $ | $$ | $$$ (relative spend per 1M tokens)
 *   context_tokens  max context window
 *   supports_tools  true if OpenAI-style function-calling works reliably
 *   recommended     true → "Recommended" star in UI
 *   notes           short caveat shown as helper text under the option
 *
 * Cost tag semantics:
 *   free   No API spend. Only OpenRouter `:free` variants qualify
 *          (rate-limited, fair use). Cloudflare's daily free Neuron
 *          allowance is NOT enough to qualify a model as `free` — the
 *          model still meters once the allowance runs out.
 *   $      Under ~$1 per 1M tokens. Tiny + small (7B-12B) open-weights,
 *          GPT-3.5 Turbo, Claude Haiku, Gemini Flash, Mistral Small.
 *   $$     ~$1-$10 per 1M tokens. Mid-size (24B-32B) open-weights, o1
 *          mini, GPT-4o mini class, Mixtral 8x22B, Mistral Large,
 *          Claude 3 Sonnet, Sonar Pro.
 *   $$$    Above $10 per 1M tokens. GPT-5 / GPT-4o / GPT-4.1 flagships,
 *          Claude 3.5 Sonnet / 3 Opus, o1 / o3 reasoning, Llama 3.3 70B
 *          via Cloudflare (Neuron-heavy), Llama 3.1 405B, GPT-OSS 120B.
 */
class ModelCatalog
{
    public const TIER_FAST = 'fast';

    public const TIER_MEDIUM = 'medium';

    public const TIER_SLOW = 'slow';

    public const PROVIDER_CLOUDFLARE = 'cloudflare';

    public const PROVIDER_OPENAI = 'openai';

    public const PROVIDER_OPENROUTER = 'openrouter';

    /**
     * @return array<int, array{
     *   id: string,
     *   label: string,
     *   provider: string,
     *   ttft_ms: int,
     *   tier: string,
     *   cost: string,
     *   context_tokens: int,
     *   supports_tools: bool,
     *   recommended: bool,
     *   notes: string,
     * }>
     */
    public function all(): array
    {
        return array_merge(
            $this->cloudflareEntries(),
            $this->openaiEntries(),
            $this->openrouterEntries(),
        );
    }

    /**
     * @return array<int, array{
     *   id: string, label: string, provider: string, ttft_ms: int, tier: string,
     *   cost: string, context_tokens: int, supports_tools: bool, recommended: bool, notes: string
     * }>
     */
    public function forProvider(string $provider): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (array $entry) => $entry['provider'] === $provider,
        ));
    }

    /**
     * Embedding-model catalogue. Embeddings have a different shape than
     * chat models — what matters is the vector dimension (must match the
     * Vectorize index), the maximum input length, and the language
     * coverage. Switching to a different `dimensions` value forces a
     * full re-index of every workspace's chunks; the UI guards against
     * this with an explicit confirmation banner.
     *
     * @return array<int, array{
     *   id: string,
     *   label: string,
     *   provider: string,
     *   dimensions: int,
     *   max_input_tokens: int,
     *   languages: string,
     *   cost: string,
     *   recommended: bool,
     *   notes: string,
     * }>
     */
    public function embedModelsForProvider(string $provider): array
    {
        return match ($provider) {
            self::PROVIDER_CLOUDFLARE => $this->cloudflareEmbedEntries(),
            self::PROVIDER_OPENAI => $this->openaiEmbedEntries(),
            self::PROVIDER_OPENROUTER => $this->openrouterEmbedEntries(),
            default => [],
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function cloudflareEmbedEntries(): array
    {
        return [
            // ── BGE family (BAAI) — verified live ──
            $this->embedEntry('@cf/baai/bge-base-en-v1.5', 'BGE Base EN v1.5', self::PROVIDER_CLOUDFLARE, 768, 512, 'en', '$', true, 'Default. Matches the current VECTOR_DIM (768). Fastest English embedder.'),
            $this->embedEntry('@cf/baai/bge-large-en-v1.5', 'BGE Large EN v1.5', self::PROVIDER_CLOUDFLARE, 1024, 512, 'en', '$', false, 'Higher recall, 1024 dims. Re-indexes the entire workspace if switched from 768.'),
            $this->embedEntry('@cf/baai/bge-small-en-v1.5', 'BGE Small EN v1.5', self::PROVIDER_CLOUDFLARE, 384, 512, 'en', '$', false, 'Cheapest. 384 dims — fast lookups, lower recall.'),
            $this->embedEntry('@cf/baai/bge-m3', 'BGE M3 (multilingual)', self::PROVIDER_CLOUDFLARE, 1024, 8192, 'multilingual', '$', false, 'Multilingual + 8k input window. Pick for non-English sites.'),

            // ── Other verified providers ──
            $this->embedEntry('@cf/google/embeddinggemma-300m', 'EmbeddingGemma 300M', self::PROVIDER_CLOUDFLARE, 768, 2048, 'multilingual', '$', false, 'Google Gemma-derived embedder. 768 dims, 2k input window.'),
            $this->embedEntry('@cf/qwen/qwen3-embedding-0.6b', 'Qwen 3 Embedding 0.6B', self::PROVIDER_CLOUDFLARE, 1024, 8192, 'multilingual', '$', false, 'Qwen 3 family embedder. 1024 dims, 8k input.'),
            $this->embedEntry('@cf/pfnet/plamo-embedding-1b', 'PLaMo Embedding 1B', self::PROVIDER_CLOUDFLARE, 2048, 4096, 'ja', '$$', false, 'Japanese-tuned. 2048 dims — large index footprint.'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function openaiEmbedEntries(): array
    {
        return [
            $this->embedEntry('text-embedding-3-small', 'Text Embedding 3 Small', self::PROVIDER_OPENAI, 1536, 8191, 'multilingual', '$', true, 'Default. Cheapest OpenAI embedder. 1536 dims, $0.02/1M tokens.'),
            $this->embedEntry('text-embedding-3-large', 'Text Embedding 3 Large', self::PROVIDER_OPENAI, 3072, 8191, 'multilingual', '$$', false, 'Higher recall. 3072 dims, $0.13/1M tokens. Re-indexes the workspace if switched from 1536.'),
            $this->embedEntry('text-embedding-ada-002', 'Text Embedding Ada 002', self::PROVIDER_OPENAI, 1536, 8191, 'multilingual', '$$', false, 'Legacy. $0.10/1M tokens. Replaced by text-embedding-3-small at lower cost.'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function openrouterEmbedEntries(): array
    {
        // OpenRouter has no native embedding endpoint. Most installs use
        // the OpenAI embedding models as a fallback (OPENROUTER_EMBED_MODEL
        // defaults to text-embedding-3-small in AppServiceProvider). Surface
        // the same OpenAI shortlist here so the buyer can pick from the same
        // dropdown without remembering the trick.
        return [
            $this->embedEntry('text-embedding-3-small', 'Text Embedding 3 Small (via OpenAI)', self::PROVIDER_OPENROUTER, 1536, 8191, 'multilingual', '$', true, 'Default. Calls OpenAI directly — needs OPENAI_API_KEY too.'),
            $this->embedEntry('text-embedding-3-large', 'Text Embedding 3 Large (via OpenAI)', self::PROVIDER_OPENROUTER, 3072, 8191, 'multilingual', '$$', false, 'Higher recall via OpenAI. 3072 dims — re-indexes if switching from 1536.'),
            $this->embedEntry('text-embedding-ada-002', 'Text Embedding Ada 002 (via OpenAI)', self::PROVIDER_OPENROUTER, 1536, 8191, 'multilingual', '$$', false, 'Legacy via OpenAI. Use 3-small instead.'),
        ];
    }

    /**
     * @return array{
     *   id: string, label: string, provider: string, ttft_ms: int, tier: string,
     *   cost: string, context_tokens: int, supports_tools: bool, recommended: bool, notes: string
     * }|null
     */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Cloudflare Workers AI text-generation catalogue.
     *
     * EVERY entry below was verified against the public model index at
     * https://developers.cloudflare.com/workers-ai/models/ (audit run
     * 2026-06-09). A prior revision of this catalogue carried ~40
     * hallucinated / delisted entries that the live API would 404 on.
     * If a model ID does not appear in Cloudflare's published list,
     * it does not belong here — use the "Refresh from Cloudflare" UI
     * button to surface any models we haven't catalogued yet.
     *
     * TTFT estimates are conservative midpoints for the parameter
     * class (1B→90ms, 7-8B→160-220ms, 12-32B→260-320ms, 70B+→360-480ms).
     * Real numbers vary by region and Cloudflare colo; the live probe
     * (ModelLatencyProbe) tells the buyer their actual figure.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cloudflareEntries(): array
    {
        return [
            // ── Meta Llama 4 (current MoE flagship) ──
            $this->entry('@cf/meta/llama-4-scout-17b-16e-instruct', 'Llama 4 Scout 17B (16 experts)', self::PROVIDER_CLOUDFLARE, 220, self::TIER_FAST, '$$', 128000, true, false, 'Llama 4 MoE — 17B active params, 16 experts. Long context.'),

            // ── Meta Llama 3.3 (current 70B flagship) ──
            $this->entry('@cf/meta/llama-3.3-70b-instruct-fp8-fast', 'Llama 3.3 70B (fp8 fast)', self::PROVIDER_CLOUDFLARE, 180, self::TIER_FAST, '$$$', 24000, true, true, 'Default. Fastest 70B on Cloudflare. Tool-calling reliable.'),

            // ── Meta Llama 3.2 (small + vision) ──
            $this->entry('@cf/meta/llama-3.2-11b-vision-instruct', 'Llama 3.2 11B Vision Instruct', self::PROVIDER_CLOUDFLARE, 280, self::TIER_MEDIUM, '$$', 128000, false, false, 'Multimodal (vision + text). Skip for text-only chat.'),
            $this->entry('@cf/meta/llama-3.2-3b-instruct', 'Llama 3.2 3B', self::PROVIDER_CLOUDFLARE, 110, self::TIER_FAST, '$', 8000, true, false, 'Tiny + cheap. Snappy on simple FAQs.'),
            $this->entry('@cf/meta/llama-3.2-1b-instruct', 'Llama 3.2 1B', self::PROVIDER_CLOUDFLARE, 90, self::TIER_FAST, '$', 8000, false, false, 'Sub-second TTFT but low coherence. Use for guardrails / classification.'),

            // ── Meta Llama 3.1 ──
            $this->entry('@cf/meta/llama-3.1-70b-instruct', 'Llama 3.1 70B', self::PROVIDER_CLOUDFLARE, 320, self::TIER_MEDIUM, '$$$', 24000, true, false, 'Older 3.1 70B. Use only when 3.3 tools misbehave.'),
            $this->entry('@cf/meta/llama-3.1-8b-instruct-fast', 'Llama 3.1 8B (fast)', self::PROVIDER_CLOUDFLARE, 120, self::TIER_FAST, '$', 8000, true, false, 'Snappy, smaller 8B. Pick for high-traffic FAQ sites.'),
            $this->entry('@cf/meta/llama-3.1-8b-instruct-fp8', 'Llama 3.1 8B (fp8)', self::PROVIDER_CLOUDFLARE, 140, self::TIER_FAST, '$', 8000, true, false, 'fp8-quantised 8B. Cheaper memory, similar speed.'),
            $this->entry('@cf/meta/llama-3.1-8b-instruct-awq', 'Llama 3.1 8B (awq)', self::PROVIDER_CLOUDFLARE, 160, self::TIER_FAST, '$', 8000, true, false, 'AWQ-quantised 8B. Lowest cold-start latency.'),
            $this->entry('@cf/meta/llama-3.1-8b-instruct', 'Llama 3.1 8B', self::PROVIDER_CLOUDFLARE, 200, self::TIER_FAST, '$', 8000, true, false, 'Standard fp16 8B. Slowest of the 8B variants.'),

            // ── Meta Llama 3 (legacy) ──
            $this->entry('@cf/meta/llama-3-8b-instruct', 'Llama 3 8B', self::PROVIDER_CLOUDFLARE, 220, self::TIER_FAST, '$', 8000, false, false, 'Legacy. Prefer Llama 3.1 8B-fast.'),
            $this->entry('@cf/meta/llama-3-8b-instruct-awq', 'Llama 3 8B (awq)', self::PROVIDER_CLOUDFLARE, 240, self::TIER_FAST, '$', 8000, false, false, 'Legacy AWQ build of Llama 3 8B.'),

            // ── Meta Llama 2 (very legacy) ──
            $this->entry('@cf/meta-llama/llama-2-7b-chat-hf-lora', 'Llama 2 7B Chat (HF LoRA)', self::PROVIDER_CLOUDFLARE, 260, self::TIER_FAST, '$', 4000, false, false, 'LoRA-adapter variant. Quality below 3.x.'),
            $this->entry('@cf/meta/llama-2-7b-chat-fp16', 'Llama 2 7B Chat (fp16)', self::PROVIDER_CLOUDFLARE, 260, self::TIER_FAST, '$', 4000, false, false, 'Legacy Llama 2. Quality below 3.x — pick only for archival reasons.'),
            $this->entry('@cf/meta/llama-2-7b-chat-int8', 'Llama 2 7B Chat (int8)', self::PROVIDER_CLOUDFLARE, 280, self::TIER_FAST, '$', 4000, false, false, 'int8 Llama 2. Same caveat as fp16.'),

            // ── Llama Guard (moderation, not chat) ──
            $this->entry('@cf/meta/llama-guard-3-8b', 'Llama Guard 3 8B', self::PROVIDER_CLOUDFLARE, 220, self::TIER_FAST, '$', 8000, false, false, 'Safety classifier, not a chat model. Use for moderation pipelines.'),

            // ── Mistral ──
            $this->entry('@cf/mistralai/mistral-small-3.1-24b-instruct', 'Mistral Small 3.1 24B', self::PROVIDER_CLOUDFLARE, 280, self::TIER_MEDIUM, '$$', 32000, true, false, 'European-origin model. Solid tool-calling.'),
            $this->entry('@cf/mistral/mistral-7b-instruct-v0.2-lora', 'Mistral 7B Instruct v0.2 (LoRA)', self::PROVIDER_CLOUDFLARE, 220, self::TIER_FAST, '$', 32000, false, false, 'LoRA-adapter Mistral 7B v0.2.'),
            $this->entry('@cf/mistral/mistral-7b-instruct-v0.1', 'Mistral 7B Instruct v0.1', self::PROVIDER_CLOUDFLARE, 240, self::TIER_FAST, '$', 8000, false, false, 'Original 7B Mistral.'),

            // ── Google Gemma ──
            $this->entry('@cf/google/gemma-4-26b-a4b-it', 'Gemma 4 26B (A4B)', self::PROVIDER_CLOUDFLARE, 300, self::TIER_MEDIUM, '$$', 8000, false, false, 'Gemma 4 sparse 26B (4B active). Newest Gemma family.'),
            $this->entry('@cf/google/gemma-3-12b-it', 'Gemma 3 12B Instruct', self::PROVIDER_CLOUDFLARE, 260, self::TIER_MEDIUM, '$$', 8000, true, false, 'Google open-weights 12B.'),
            $this->entry('@cf/google/gemma-7b-it-lora', 'Gemma 7B Instruct (LoRA)', self::PROVIDER_CLOUDFLARE, 200, self::TIER_FAST, '$', 8000, false, false, 'LoRA-adapter Gemma 7B.'),
            $this->entry('@cf/google/gemma-2b-it-lora', 'Gemma 2B Instruct (LoRA)', self::PROVIDER_CLOUDFLARE, 100, self::TIER_FAST, '$', 2000, false, false, 'Tiny LoRA-adapter. Limited context.'),

            // ── Qwen ──
            $this->entry('@cf/qwen/qwen3-30b-a3b-fp8', 'Qwen 3 30B A3B (fp8)', self::PROVIDER_CLOUDFLARE, 280, self::TIER_MEDIUM, '$$', 32000, true, false, 'Qwen 3 MoE — 30B params, 3B active. fp8-quantised.'),
            $this->entry('@cf/qwen/qwq-32b', 'Qwen QwQ 32B', self::PROVIDER_CLOUDFLARE, 320, self::TIER_MEDIUM, '$$', 32000, false, false, 'Reasoning preview. Verbose; slower TTFT.'),
            $this->entry('@cf/qwen/qwen2.5-coder-32b-instruct', 'Qwen 2.5 Coder 32B', self::PROVIDER_CLOUDFLARE, 280, self::TIER_MEDIUM, '$$', 32000, false, false, 'Strong on code. Limited tool-calling.'),

            // ── DeepSeek ──
            $this->entry('@cf/deepseek-ai/deepseek-r1-distill-qwen-32b', 'DeepSeek R1 Distill Qwen 32B', self::PROVIDER_CLOUDFLARE, 360, self::TIER_MEDIUM, '$$', 32000, false, false, 'Reasoning-tuned 32B. Verbose chain-of-thought.'),

            // ── OpenAI gpt-oss ──
            $this->entry('@cf/openai/gpt-oss-120b', 'GPT-OSS 120B (OpenAI weights)', self::PROVIDER_CLOUDFLARE, 480, self::TIER_SLOW, '$$$', 128000, true, false, 'OpenAI open-weights 120B. Heavyweight, slow TTFT.'),
            $this->entry('@cf/openai/gpt-oss-20b', 'GPT-OSS 20B (OpenAI weights)', self::PROVIDER_CLOUDFLARE, 240, self::TIER_FAST, '$$', 128000, true, false, 'OpenAI open-weights 20B. Strong for the size.'),

            // ── Microsoft Phi ──
            $this->entry('@cf/microsoft/phi-2', 'Phi-2', self::PROVIDER_CLOUDFLARE, 120, self::TIER_FAST, '$', 2000, false, false, 'Tiny 2.7B Phi. Toy scale.'),

            // ── Moonshot AI Kimi ──
            $this->entry('@cf/moonshotai/kimi-k2.6', 'Kimi K2.6', self::PROVIDER_CLOUDFLARE, 300, self::TIER_MEDIUM, '$$', 128000, false, false, 'Moonshot AI Kimi K2.6 — long-context chat.'),
            $this->entry('@cf/moonshotai/kimi-k2.5', 'Kimi K2.5', self::PROVIDER_CLOUDFLARE, 320, self::TIER_MEDIUM, '$$', 128000, false, false, 'Older Kimi K2.5. Prefer K2.6.'),

            // ── NVIDIA Nemotron ──
            $this->entry('@cf/nvidia/nemotron-3-120b-a12b', 'Nemotron 3 120B (A12B)', self::PROVIDER_CLOUDFLARE, 460, self::TIER_SLOW, '$$$', 128000, true, false, 'NVIDIA Nemotron 3 MoE — 120B params, 12B active.'),

            // ── IBM Granite ──
            $this->entry('@cf/ibm-granite/granite-4.0-h-micro', 'Granite 4.0 H Micro', self::PROVIDER_CLOUDFLARE, 140, self::TIER_FAST, '$', 8000, false, false, 'IBM Granite 4 hybrid micro. Enterprise-tuned.'),

            // ── AI Singapore SEA-LION ──
            $this->entry('@cf/aisingapore/gemma-sea-lion-v4-27b-it', 'Gemma SEA-LION v4 27B', self::PROVIDER_CLOUDFLARE, 320, self::TIER_MEDIUM, '$$', 8000, false, false, 'Gemma fine-tune for Southeast Asian languages.'),

            // ── Z.AI / Zhipu GLM ──
            $this->entry('@cf/zai-org/glm-4.7-flash', 'GLM 4.7 Flash', self::PROVIDER_CLOUDFLARE, 240, self::TIER_FAST, '$$', 32000, false, false, 'Zhipu GLM 4.7 Flash — quick general chat.'),

            // ── Defog SQL ──
            $this->entry('@cf/defog/sqlcoder-7b-2', 'SQLCoder 7B v2', self::PROVIDER_CLOUDFLARE, 200, self::TIER_FAST, '$', 4000, false, false, 'SQL-specialised. Skip for general chat.'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function openaiEntries(): array
    {
        return [
            // ── GPT-5 family (current flagship) ──
            $this->entry('gpt-5', 'GPT-5', self::PROVIDER_OPENAI, 620, self::TIER_MEDIUM, '$$$', 200000, true, false, 'Flagship. Slow + expensive — pick only for hard reasoning.'),
            $this->entry('gpt-5-mini', 'GPT-5 mini', self::PROVIDER_OPENAI, 240, self::TIER_FAST, '$', 128000, true, true, 'Recommended. Fast, smart, cheap. Replaces gpt-4o-mini for new agents.'),
            $this->entry('gpt-5-nano', 'GPT-5 nano', self::PROVIDER_OPENAI, 160, self::TIER_FAST, '$', 128000, true, false, 'Snappiest GPT-5. Quality below mini.'),

            // ── GPT-4.1 family ──
            $this->entry('gpt-4.1', 'GPT-4.1', self::PROVIDER_OPENAI, 540, self::TIER_MEDIUM, '$$$', 200000, true, false, 'Long-context + strong reasoning. Slower than gpt-4o.'),
            $this->entry('gpt-4.1-mini', 'GPT-4.1 mini', self::PROVIDER_OPENAI, 260, self::TIER_FAST, '$', 200000, true, false, 'Cheaper 4.1 with 200k context.'),
            $this->entry('gpt-4.1-nano', 'GPT-4.1 nano', self::PROVIDER_OPENAI, 180, self::TIER_FAST, '$', 200000, true, false, 'Smallest 4.1. Snappy + cheap.'),

            // ── GPT-4o family ──
            $this->entry('gpt-4o', 'GPT-4o', self::PROVIDER_OPENAI, 720, self::TIER_MEDIUM, '$$$', 128000, true, false, 'Legacy flagship. Slower than 5/4.1; prefer those.'),
            $this->entry('gpt-4o-mini', 'GPT-4o mini', self::PROVIDER_OPENAI, 280, self::TIER_FAST, '$', 128000, true, true, 'Cheap + fast. Default for most agents.'),
            $this->entry('gpt-4o-2024-11-20', 'GPT-4o (2024-11-20)', self::PROVIDER_OPENAI, 720, self::TIER_MEDIUM, '$$$', 128000, true, false, 'Pinned Nov 2024 GPT-4o.'),
            $this->entry('gpt-4o-2024-08-06', 'GPT-4o (2024-08-06)', self::PROVIDER_OPENAI, 720, self::TIER_MEDIUM, '$$$', 128000, true, false, 'Pinned Aug 2024 GPT-4o.'),
            $this->entry('gpt-4o-realtime-preview', 'GPT-4o Realtime (preview)', self::PROVIDER_OPENAI, 380, self::TIER_MEDIUM, '$$$', 128000, true, false, 'Voice/realtime channel. Overkill for chat-only.'),
            $this->entry('gpt-4o-audio-preview', 'GPT-4o Audio (preview)', self::PROVIDER_OPENAI, 380, self::TIER_MEDIUM, '$$$', 128000, true, false, 'Audio I/O channel. Overkill for text chat.'),
            $this->entry('chatgpt-4o-latest', 'ChatGPT-4o latest', self::PROVIDER_OPENAI, 720, self::TIER_MEDIUM, '$$$', 128000, true, false, 'Auto-updated ChatGPT model. Breaking changes possible.'),

            // ── GPT-4 / 3.5 (legacy) ──
            $this->entry('gpt-4-turbo', 'GPT-4 Turbo', self::PROVIDER_OPENAI, 900, self::TIER_SLOW, '$$$', 128000, true, false, 'Legacy. Skip for new agents.'),
            $this->entry('gpt-4', 'GPT-4', self::PROVIDER_OPENAI, 1100, self::TIER_SLOW, '$$$', 8000, true, false, 'Very legacy. Use only with a specific contract obligation.'),
            $this->entry('gpt-3.5-turbo', 'GPT-3.5 Turbo', self::PROVIDER_OPENAI, 350, self::TIER_FAST, '$', 16000, true, false, 'Cheap legacy. Quality well below mini.'),

            // ── o1/o3/o4 reasoning ──
            $this->entry('o1', 'o1 (reasoning)', self::PROVIDER_OPENAI, 1800, self::TIER_SLOW, '$$$', 200000, false, false, 'Reasoning-mode. Slow chain-of-thought; pick for complex tasks.'),
            $this->entry('o1-mini', 'o1 mini (reasoning)', self::PROVIDER_OPENAI, 900, self::TIER_SLOW, '$$', 128000, false, false, 'Cheaper reasoning. Still slow.'),
            $this->entry('o1-preview', 'o1 preview', self::PROVIDER_OPENAI, 1800, self::TIER_SLOW, '$$$', 200000, false, false, 'Old o1 preview. Use o1 instead.'),
            $this->entry('o3', 'o3 (reasoning)', self::PROVIDER_OPENAI, 1600, self::TIER_SLOW, '$$$', 200000, true, false, 'Newer reasoning model with tool-calling.'),
            $this->entry('o3-mini', 'o3 mini', self::PROVIDER_OPENAI, 700, self::TIER_MEDIUM, '$$', 200000, true, false, 'Cheaper o3. Tool-calling stable.'),
            $this->entry('o4-mini', 'o4 mini', self::PROVIDER_OPENAI, 580, self::TIER_MEDIUM, '$$', 200000, true, false, 'Latest reasoning mini. Good cost/quality for STEM.'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function openrouterEntries(): array
    {
        return [
            // ── Meta Llama free + paid ──
            $this->entry('meta-llama/llama-3.3-70b-instruct:free', 'Llama 3.3 70B (free)', self::PROVIDER_OPENROUTER, 220, self::TIER_FAST, 'free', 24000, true, true, 'Default. Free tier, rate-limited but solid.'),
            $this->entry('meta-llama/llama-3.3-70b-instruct', 'Llama 3.3 70B', self::PROVIDER_OPENROUTER, 220, self::TIER_FAST, '$', 24000, true, false, 'Paid Llama 3.3 — no rate limits.'),
            $this->entry('meta-llama/llama-3.1-405b-instruct', 'Llama 3.1 405B', self::PROVIDER_OPENROUTER, 600, self::TIER_MEDIUM, '$$', 128000, true, false, 'Biggest open-weights model.'),
            $this->entry('meta-llama/llama-3.1-70b-instruct', 'Llama 3.1 70B', self::PROVIDER_OPENROUTER, 240, self::TIER_FAST, '$', 24000, true, false, 'Paid 3.1 70B.'),
            $this->entry('meta-llama/llama-3.2-3b-instruct:free', 'Llama 3.2 3B (free)', self::PROVIDER_OPENROUTER, 140, self::TIER_FAST, 'free', 8000, false, false, 'Tiny free model. Quick FAQ replies.'),

            // ── Anthropic Claude ──
            $this->entry('anthropic/claude-3.5-sonnet', 'Claude 3.5 Sonnet', self::PROVIDER_OPENROUTER, 520, self::TIER_MEDIUM, '$$$', 200000, true, false, 'Strong reasoning + nuance. Slow-ish TTFT.'),
            $this->entry('anthropic/claude-3.5-haiku', 'Claude 3.5 Haiku', self::PROVIDER_OPENROUTER, 240, self::TIER_FAST, '$', 200000, true, false, 'Fast Anthropic. Good cost/quality.'),
            $this->entry('anthropic/claude-3-opus', 'Claude 3 Opus', self::PROVIDER_OPENROUTER, 1200, self::TIER_SLOW, '$$$', 200000, true, false, 'Highest quality Anthropic. Slow + expensive.'),
            $this->entry('anthropic/claude-3-sonnet', 'Claude 3 Sonnet', self::PROVIDER_OPENROUTER, 480, self::TIER_MEDIUM, '$$', 200000, true, false, 'Legacy Sonnet. Prefer 3.5.'),
            $this->entry('anthropic/claude-3-haiku', 'Claude 3 Haiku', self::PROVIDER_OPENROUTER, 240, self::TIER_FAST, '$', 200000, true, false, 'Legacy Haiku. Prefer 3.5.'),

            // ── Google Gemini ──
            $this->entry('google/gemini-2.0-flash-exp:free', 'Gemini 2.0 Flash (free exp)', self::PROVIDER_OPENROUTER, 180, self::TIER_FAST, 'free', 1000000, true, false, 'Experimental free Gemini 2.0. 1M context.'),
            $this->entry('google/gemini-flash-1.5', 'Gemini 1.5 Flash', self::PROVIDER_OPENROUTER, 200, self::TIER_FAST, '$', 1000000, true, false, '1M context. Picky on tool-call format.'),
            $this->entry('google/gemini-pro-1.5', 'Gemini 1.5 Pro', self::PROVIDER_OPENROUTER, 580, self::TIER_MEDIUM, '$$', 2000000, true, false, '2M context. Slower than Flash.'),
            $this->entry('google/gemini-pro', 'Gemini Pro', self::PROVIDER_OPENROUTER, 460, self::TIER_MEDIUM, '$', 32000, true, false, 'Legacy Gemini Pro.'),

            // ── Mistral ──
            $this->entry('mistralai/mistral-small-latest', 'Mistral Small', self::PROVIDER_OPENROUTER, 260, self::TIER_FAST, '$', 32000, true, false, 'EU provider. GDPR-friendly.'),
            $this->entry('mistralai/mistral-medium', 'Mistral Medium', self::PROVIDER_OPENROUTER, 400, self::TIER_MEDIUM, '$$', 32000, true, false, 'Mid-tier Mistral.'),
            $this->entry('mistralai/mistral-large', 'Mistral Large', self::PROVIDER_OPENROUTER, 540, self::TIER_MEDIUM, '$$', 128000, true, false, 'Flagship Mistral.'),
            $this->entry('mistralai/mistral-7b-instruct:free', 'Mistral 7B Instruct (free)', self::PROVIDER_OPENROUTER, 220, self::TIER_FAST, 'free', 32000, false, false, 'Free 7B Mistral.'),
            $this->entry('mistralai/mixtral-8x7b-instruct', 'Mixtral 8x7B', self::PROVIDER_OPENROUTER, 280, self::TIER_FAST, '$', 32000, false, false, 'MoE Mixtral. Good cost/quality.'),
            $this->entry('mistralai/mixtral-8x22b-instruct', 'Mixtral 8x22B', self::PROVIDER_OPENROUTER, 420, self::TIER_MEDIUM, '$$', 64000, false, false, 'Bigger MoE Mixtral.'),

            // ── DeepSeek ──
            $this->entry('deepseek/deepseek-chat:free', 'DeepSeek Chat (free)', self::PROVIDER_OPENROUTER, 240, self::TIER_FAST, 'free', 64000, true, false, 'Free DeepSeek. Strong cost/quality.'),
            $this->entry('deepseek/deepseek-chat', 'DeepSeek Chat', self::PROVIDER_OPENROUTER, 240, self::TIER_FAST, '$', 64000, true, false, 'Paid DeepSeek Chat — no rate limits.'),
            $this->entry('deepseek/deepseek-r1:free', 'DeepSeek R1 (free)', self::PROVIDER_OPENROUTER, 600, self::TIER_MEDIUM, 'free', 64000, false, false, 'Reasoning-tuned. Slower.'),
            $this->entry('deepseek/deepseek-r1', 'DeepSeek R1', self::PROVIDER_OPENROUTER, 600, self::TIER_MEDIUM, '$', 64000, false, false, 'Paid R1.'),

            // ── Qwen ──
            $this->entry('qwen/qwen-2.5-72b-instruct', 'Qwen 2.5 72B', self::PROVIDER_OPENROUTER, 360, self::TIER_MEDIUM, '$', 32000, true, false, 'Open-weights flagship Qwen.'),
            $this->entry('qwen/qwen-2.5-7b-instruct', 'Qwen 2.5 7B', self::PROVIDER_OPENROUTER, 180, self::TIER_FAST, '$', 32000, true, false, 'Small Qwen 2.5.'),
            $this->entry('qwen/qwen-2.5-coder-32b-instruct', 'Qwen 2.5 Coder 32B', self::PROVIDER_OPENROUTER, 280, self::TIER_MEDIUM, '$', 32000, false, false, 'Code-specialised.'),

            // ── Perplexity Sonar ──
            $this->entry('perplexity/sonar', 'Perplexity Sonar', self::PROVIDER_OPENROUTER, 380, self::TIER_MEDIUM, '$', 127000, false, false, 'Live web search built-in.'),
            $this->entry('perplexity/sonar-reasoning', 'Perplexity Sonar Reasoning', self::PROVIDER_OPENROUTER, 700, self::TIER_SLOW, '$$', 127000, false, false, 'Reasoning + web search. Slow.'),
            $this->entry('perplexity/sonar-pro', 'Perplexity Sonar Pro', self::PROVIDER_OPENROUTER, 460, self::TIER_MEDIUM, '$$', 200000, false, false, 'Pro tier of Sonar.'),

            // ── NVIDIA / Cohere / Misc ──
            $this->entry('nvidia/llama-3.1-nemotron-70b-instruct', 'Nemotron 70B (NVIDIA)', self::PROVIDER_OPENROUTER, 280, self::TIER_FAST, '$', 128000, true, false, 'NVIDIA-tuned Llama 3.1 70B.'),
            $this->entry('cohere/command-r-plus', 'Command R+', self::PROVIDER_OPENROUTER, 380, self::TIER_MEDIUM, '$$', 128000, true, false, 'Cohere flagship. Strong tool-calling.'),
            $this->entry('cohere/command-r', 'Command R', self::PROVIDER_OPENROUTER, 280, self::TIER_FAST, '$', 128000, true, false, 'Cheaper Command R.'),
            $this->entry('microsoft/phi-3.5-mini-128k-instruct', 'Phi-3.5 Mini (128k)', self::PROVIDER_OPENROUTER, 160, self::TIER_FAST, '$', 128000, false, false, 'Microsoft Phi. Snappy.'),
            $this->entry('x-ai/grok-2', 'Grok 2 (xAI)', self::PROVIDER_OPENROUTER, 460, self::TIER_MEDIUM, '$$', 128000, true, false, 'xAI Grok 2.'),
            $this->entry('x-ai/grok-2-mini', 'Grok 2 mini', self::PROVIDER_OPENROUTER, 260, self::TIER_FAST, '$', 128000, true, false, 'Cheaper Grok 2.'),
        ];
    }

    /**
     * @return array{
     *   id: string, label: string, provider: string, dimensions: int,
     *   max_input_tokens: int, languages: string, cost: string,
     *   recommended: bool, notes: string,
     * }
     */
    private function embedEntry(
        string $id,
        string $label,
        string $provider,
        int $dimensions,
        int $maxInputTokens,
        string $languages,
        string $cost,
        bool $recommended,
        string $notes,
    ): array {
        return [
            'id' => $id,
            'label' => $label,
            'provider' => $provider,
            'dimensions' => $dimensions,
            'max_input_tokens' => $maxInputTokens,
            'languages' => $languages,
            'cost' => $cost,
            'recommended' => $recommended,
            'notes' => $notes,
        ];
    }

    /**
     * @return array{
     *   id: string, label: string, provider: string, ttft_ms: int, tier: string,
     *   cost: string, context_tokens: int, supports_tools: bool, recommended: bool, notes: string
     * }
     */
    private function entry(
        string $id,
        string $label,
        string $provider,
        int $ttftMs,
        string $tier,
        string $cost,
        int $contextTokens,
        bool $supportsTools,
        bool $recommended,
        string $notes,
    ): array {
        return [
            'id' => $id,
            'label' => $label,
            'provider' => $provider,
            'ttft_ms' => $ttftMs,
            'tier' => $tier,
            'cost' => $cost,
            'context_tokens' => $contextTokens,
            'supports_tools' => $supportsTools,
            'recommended' => $recommended,
            'notes' => $notes,
        ];
    }
}
