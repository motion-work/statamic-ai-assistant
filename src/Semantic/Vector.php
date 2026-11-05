<?php

namespace MotionWork\StatamicAiAssistant\Semantic;

/** Small vector maths for unit-length embeddings. */
final class Vector
{
    /**
     * @param  list<float>  $vector
     * @return list<float>
     */
    public static function normalize(array $vector): array
    {
        $length = sqrt(array_sum(array_map(fn ($v) => $v * $v, $vector)));

        return $length > 0 ? array_map(fn ($v) => $v / $length, array_values($vector)) : array_values($vector);
    }

    /**
     * Dot product of two vectors with the same keys (unpack() keys rows from 1).
     *
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    public static function dot(array $a, array $b): float
    {
        $sum = 0.0;
        foreach ($a as $i => $value) {
            $sum += $value * ($b[$i] ?? 0.0);
        }

        return $sum;
    }
}
