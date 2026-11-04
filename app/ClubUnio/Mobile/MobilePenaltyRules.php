<?php

namespace App\ClubUnio\Mobile;

/** Same ten-attempt scoring as the original SVS shoot/save game. */
final class MobilePenaltyRules
{
    public static function score(string $mode, array $opponents, mixed $choices): int
    {
        if (! in_array($mode, ['shoot','save'], true) || ! array_is_list($opponents) || count($opponents) !== 10
            || ! is_array($choices) || ! array_is_list($choices) || count($choices) !== 10) throw new \InvalidArgumentException('Ten attempts required');
        $score = $streak = 0;
        foreach ($choices as $index => $lane) {
            if (! is_int($lane) || $lane < -1 || $lane > 2 || ! is_int($opponents[$index]) || $opponents[$index] < 0 || $opponents[$index] > 2) throw new \InvalidArgumentException('Invalid attempt');
            $hit = $lane >= 0 && ($mode === 'shoot' ? $lane !== $opponents[$index] : $lane === $opponents[$index]);
            $streak = $hit ? $streak + 1 : 0;
            if ($hit) $score += 100 + 20 * $streak;
        }
        return $score;
    }
}
