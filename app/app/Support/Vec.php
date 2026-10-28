<?php

namespace App\Support;

final class Vec
{
    /**
     * Cosine similarity in [-1, 1]. Zero vectors score 0.0.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function cosineSimilarity(array $a, array $b): float
    {
        $a = array_values($a);
        $b = array_values($b);
        if (count($a) !== count($b)) {
            throw new \InvalidArgumentException(
                'Embedding lengths differ: '.count($a).' vs '.count($b).'.'
            );
        }
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        foreach ($a as $i => $x) {
            $y = $b[$i];
            $dot += $x * $y;
            $normA += $x * $x;
            $normB += $y * $y;
        }
        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return max(-1.0, min(1.0, $dot / sqrt($normA * $normB)));
    }

    /**
     * Cosine distance (1 - similarity) in [0, 2].
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function cosineDistance(array $a, array $b): float
    {
        return 1.0 - self::cosineSimilarity($a, $b);
    }
}
