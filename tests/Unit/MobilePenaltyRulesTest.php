<?php

namespace Tests\Unit;

use App\ClubUnio\Mobile\MobilePenaltyRules;
use PHPUnit\Framework\TestCase;

class MobilePenaltyRulesTest extends TestCase
{
    public function test_original_two_modes_maximum_misses_and_streaks(): void
    {
        $opponents = array_fill(0, 10, 1);
        $this->assertSame(2100, MobilePenaltyRules::score('shoot', $opponents, array_fill(0, 10, 0)));
        $this->assertSame(2100, MobilePenaltyRules::score('save', $opponents, array_fill(0, 10, 1)));
        $this->assertSame(0, MobilePenaltyRules::score('shoot', $opponents, array_fill(0, 10, 1)));
        $this->assertSame(0, MobilePenaltyRules::score('save', $opponents, array_fill(0, 10, -1)));
        $this->assertSame(380, MobilePenaltyRules::score('shoot', $opponents, [0,0,-1,2,-1,-1,-1,-1,-1,-1]));
    }
    public function test_invalid_modes_counts_sparse_arrays_nonintegers_and_lanes_are_rejected(): void
    {
        foreach ([['invalid', array_fill(0, 10, 1), array_fill(0, 10, 0)],
            ['shoot', [0], array_fill(0, 10, 0)], ['shoot', array_fill(0, 10, 1), [0]],
            ['shoot', array_fill(0, 10, 1), array_fill(1, 10, 0)],
            ['shoot', array_fill(0, 10, 1), array_fill(0, 10, '0')],
            ['shoot', array_fill(0, 10, 1), array_fill(0, 10, true)],
            ['shoot', array_fill(0, 10, 1), array_fill(0, 10, 3)],
            ['shoot', array_fill(0, 10, 9), array_fill(0, 10, 0)]] as $case) {
            try { MobilePenaltyRules::score(...$case); $this->fail('Invalid round was accepted'); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }
}
