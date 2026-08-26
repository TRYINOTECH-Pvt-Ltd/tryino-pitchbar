<?php

namespace App\Services\Tools\Contracts;

/**
 * Optional interface a Tool may implement to feed the fast router's
 * intent gates. Tools that don't implement it still route — the
 * ToolExemplarStore falls back to embedding the tool's description()
 * as a single exemplar and the keyword gate simply has nothing to
 * match. That fallback is what keeps future namespaced tools (e.g.
 * MCP `server_label_tool`) routable with zero extra code.
 */
interface HasIntentSignals
{
    /**
     * High-precision substring needles. Lowercased substring scan per
     * turn — cheap (<1ms) and immune to embedding availability. Tune
     * for precision over recall: a false positive merely re-adds the
     * legacy tool-check completion; a false negative still has the
     * embedding gate behind it.
     *
     * @return list<string>
     */
    public function intentKeywords(): array;

    /**
     * Exemplar visitor utterances. Embedded offline (queued job), mean-
     * pooled into a single centroid, and compared against the turn's
     * query embedding by cosine similarity.
     *
     * @return list<string>
     */
    public function intentExemplars(): array;
}
