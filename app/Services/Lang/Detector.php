<?php

namespace App\Services\Lang;

class Detector
{
    /**
     * Cheap stop-word-based language detector. Adds < 1ms.
     * Returns ISO 639-1 code or null.
     */
    public function detect(string $text): ?string
    {
        $text = mb_strtolower(trim($text));
        if (mb_strlen($text) < 8) {
            return null;
        }

        // Script-based fast paths
        if (preg_match('/[\p{Hiragana}\p{Katakana}]/u', $text)) {
            return 'ja';
        }
        if (preg_match('/[\p{Han}]/u', $text)) {
            return 'zh';
        }
        if (preg_match('/[\p{Arabic}]/u', $text)) {
            return 'ar';
        }

        $needles = [
            'en' => ['the ', ' and ', ' is ', ' you ', ' for ', ' of ', ' a ', ' to '],
            'es' => [' que ', ' de ', ' la ', ' el ', ' es ', ' los ', ' las ', ' por '],
            'fr' => [' le ', ' la ', ' les ', ' de ', ' que ', ' un ', ' une ', ' est '],
            'de' => [' der ', ' die ', ' das ', ' und ', ' ist ', ' ein ', ' eine ', ' nicht '],
            'pt' => [' o ', ' a ', ' que ', ' de ', ' não ', ' é ', ' os ', ' as '],
        ];

        $padded = ' '.$text.' ';
        $scores = [];
        foreach ($needles as $lang => $words) {
            $score = 0;
            foreach ($words as $w) {
                $score += substr_count($padded, $w);
            }
            $scores[$lang] = $score;
        }

        arsort($scores);
        $top = array_key_first($scores);

        return $scores[$top] > 0 ? (string) $top : null;
    }
}
