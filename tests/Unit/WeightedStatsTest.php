<?php

namespace Tests\Unit;

use App\Support\WeightedStats;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the zero-weighted-median outlier fix: a
 * department's demand history is very often mostly zero (Extra-Present-
 * only departments are the extreme case, Scheduled = 0 every time), which
 * drives the overall weighted median to 0. A naive "cap at 2x median"
 * rule would then erase the only real positive evidence. The business
 * invariant under test: genuine positive historical Operational Demand
 * must never be erased merely because the weighted median is 0.
 */
class WeightedStatsTest extends TestCase
{
    public function test_zero_median_with_one_positive_value_preserves_it(): void
    {
        // [0, 0, 0, 20] -> weighted median is 0, but 20 is the only real
        // evidence of demand and must survive undamped.
        $damped = WeightedStats::dampOutliers([0, 0, 0, 20], [1, 1, 1, 1]);

        $this->assertSame([0.0, 0.0, 0.0, 20.0], array_map('floatval', $damped));
    }

    public function test_zero_median_with_single_positive_among_two_zeros(): void
    {
        $damped = WeightedStats::dampOutliers([0, 0, 10], [1, 1, 1]);

        $this->assertSame(10.0, (float) $damped[2]);
    }

    public function test_zero_median_with_one_zero_one_positive(): void
    {
        $damped = WeightedStats::dampOutliers([0, 5], [1, 1]);

        $this->assertSame(5.0, (float) $damped[1]);
    }

    public function test_all_zero_values_remain_zero(): void
    {
        $damped = WeightedStats::dampOutliers([0, 0, 0], [1, 1, 1]);

        $this->assertSame([0.0, 0.0, 0.0], array_map('floatval', $damped));
    }

    public function test_extra_present_only_department_sparse_positive_demand_is_preserved(): void
    {
        // Mirrors a real extra-present-only department: Scheduled is 0 in
        // every historical session, only Extra Present ever contributes,
        // and only one session actually had any.
        $demand = [0, 0, 8]; // present=0 always, extra=[0,0,8]
        $damped = WeightedStats::dampOutliers($demand, [1, 1, 1]);

        $this->assertSame(8.0, (float) $damped[2], 'The sole positive demand observation must not be erased.');
    }

    public function test_zero_median_with_several_positive_values_still_protects_against_a_genuine_outlier(): void
    {
        // Enough positive evidence (three sessions around 20) to judge a
        // genuine anomaly against: the one session at 500 must be capped,
        // while the legitimate ~20s are untouched.
        $damped = WeightedStats::dampOutliers([0, 0, 20, 20, 20, 500], [1, 1, 1, 1, 1, 1]);

        $this->assertSame(20.0, (float) $damped[2]);
        $this->assertSame(20.0, (float) $damped[3]);
        $this->assertSame(20.0, (float) $damped[4]);
        $this->assertLessThan(500.0, $damped[5]);
        $this->assertEqualsWithDelta(40.0, $damped[5], 0.01); // capped at 2x the positive-only median (20)
    }

    public function test_positive_median_case_still_caps_normally(): void
    {
        // Unchanged behavior for the ordinary (non-zero-median) case.
        $damped = WeightedStats::dampOutliers([10.0, 11.0, 9.0, 100.0], [1.0, 1.0, 1.0, 1.0]);

        $this->assertLessThan(100.0, max($damped));
        $this->assertSame(10.0, $damped[0]);
    }
}
