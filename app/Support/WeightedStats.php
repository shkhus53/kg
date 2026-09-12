<?php

namespace App\Support;

/**
 * Pure math primitives for Phase 3 forecasting — no database access, no
 * business meaning attached here (that lives in HistoricalDemandService /
 * ForecastingService). Kept separate specifically so the arithmetic is
 * independently unit-testable without touching a single database row.
 */
class WeightedStats
{
    /**
     * @param  array<int,float>  $values
     * @param  array<int,float>  $weights
     */
    public static function weightedMean(array $values, array $weights): float
    {
        $weightSum = array_sum($weights);

        if ($weightSum <= 0.0) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($values as $i => $value) {
            $sum += $value * ($weights[$i] ?? 0.0);
        }

        return $sum / $weightSum;
    }

    /**
     * The weighted median: the value at which the cumulative weight first
     * reaches half of the total weight, walking values in ascending order.
     *
     * @param  array<int,float>  $values
     * @param  array<int,float>  $weights
     */
    public static function weightedMedian(array $values, array $weights): float
    {
        if (empty($values)) {
            return 0.0;
        }

        $pairs = [];
        foreach ($values as $i => $value) {
            $pairs[] = ['value' => $value, 'weight' => $weights[$i] ?? 0.0];
        }
        usort($pairs, fn ($a, $b) => $a['value'] <=> $b['value']);

        $totalWeight = array_sum(array_column($pairs, 'weight'));
        if ($totalWeight <= 0.0) {
            // No usable weights — fall back to the plain (unweighted) median.
            $plain = array_column($pairs, 'value');
            $mid = intdiv(count($plain), 2);

            return count($plain) % 2 === 0
                ? ($plain[$mid - 1] + $plain[$mid]) / 2
                : $plain[$mid];
        }

        $cumulative = 0.0;
        foreach ($pairs as $pair) {
            $cumulative += $pair['weight'];
            if ($cumulative >= $totalWeight / 2) {
                return $pair['value'];
            }
        }

        return end($pairs)['value'];
    }

    /**
     * Outlier dampening: any value more than 2x a robust central reference
     * is capped at 2x that reference before it contributes to any
     * mean/stddev calculation. $values themselves are never mutated by the
     * caller's copy — this returns a parallel "damped" array used only for
     * calculation; the original historical rows are never altered.
     *
     * The reference point is normally the weighted median of ALL values.
     * That breaks down for operational data specific to this app: a
     * department's demand history is very often mostly zero with a handful
     * of genuine positive sessions (an Extra-Present-only department is
     * the extreme case — Scheduled is 0 every time). When zeros are the
     * majority, the overall weighted median is 0, and "cap at 2x0 = 0"
     * would erase the ONLY evidence that real demand ever existed — the
     * exact opposite of what outlier protection is for.
     *
     * So: whenever the overall median is <= 0, the reference is
     * recomputed from the POSITIVE values only (the zeros are the
     * legitimate baseline, not noise to protect against):
     *   - no positive values at all -> nothing to damp, return unchanged.
     *   - exactly one positive value -> it is the sole evidence of real
     *     demand, not an outlier relative to anything; never capped.
     *   - two or more positive values -> their own weighted median becomes
     *     the reference, so a genuine cluster of positive demand (e.g.
     *     several sessions all around 20) still protects itself against a
     *     single wildly larger value (e.g. one session at 500), while a
     *     lone or sparse positive observation is always preserved as-is.
     *
     * @param  array<int,float>  $values
     * @param  array<int,float>  $weights
     * @return array<int,float>
     */
    public static function dampOutliers(array $values, array $weights): array
    {
        $median = self::weightedMedian($values, $weights);

        if ($median > 0.0) {
            $cap = $median * 2;

            return array_map(fn ($v) => min($v, $cap), $values);
        }

        $positiveValues = [];
        $positiveWeights = [];
        foreach ($values as $i => $v) {
            if ($v > 0.0) {
                $positiveValues[] = $v;
                $positiveWeights[] = $weights[$i] ?? 0.0;
            }
        }

        if (count($positiveValues) < 2) {
            // Zero or one positive observation: nothing to compare it
            // against, so it can never be judged an outlier. Preserve
            // genuine positive demand rather than erasing it.
            return $values;
        }

        $positiveMedian = self::weightedMedian($positiveValues, $positiveWeights);
        if ($positiveMedian <= 0.0) {
            return $values;
        }

        $cap = $positiveMedian * 2;

        return array_map(fn ($v) => $v > 0.0 ? min($v, $cap) : $v, $values);
    }

    /**
     * Weighted population standard deviation, using the same weights as
     * weightedMean() — used only to build the planning range, never shown
     * as a statistical claim on its own.
     *
     * @param  array<int,float>  $values
     * @param  array<int,float>  $weights
     */
    public static function weightedStdDev(array $values, array $weights): float
    {
        $weightSum = array_sum($weights);
        if ($weightSum <= 0.0 || count($values) < 2) {
            return 0.0;
        }

        $mean = self::weightedMean($values, $weights);
        $variance = 0.0;
        foreach ($values as $i => $value) {
            $variance += ($weights[$i] ?? 0.0) * (($value - $mean) ** 2);
        }
        $variance /= $weightSum;

        return sqrt($variance);
    }

    /**
     * Recency weight: a session 24 months old carries half the weight of a
     * current one. $monthsAgo may be fractional; never negative (a future
     * date — shouldn't happen for historical data — is treated as "now").
     */
    public static function recencyWeight(float $monthsAgo): float
    {
        return 0.5 ** (max(0.0, $monthsAgo) / 24);
    }

    /**
     * Largest-remainder apportionment: given a fixed target total and a set
     * of raw (fractional) shares, returns whole-number allocations that sum
     * EXACTLY to $total. Each share is floored, then the leftover units are
     * given one-by-one to the shares with the largest fractional remainder.
     * This is how department recommendations are guaranteed to reconcile
     * to the headline Recommended Scheduled HR — never independent, always
     * derived from the same underlying raw numbers.
     *
     * @param  array<int|string,float>  $rawShares  keyed by department id
     * @return array<int|string,int>
     */
    public static function apportion(array $rawShares, int $total): array
    {
        if (empty($rawShares)) {
            return [];
        }

        $floors = [];
        $remainders = [];
        foreach ($rawShares as $key => $value) {
            $floors[$key] = (int) floor($value);
            $remainders[$key] = $value - $floors[$key];
        }

        $allocated = array_sum($floors);
        $remaining = $total - $allocated;

        // Negative $remaining (rare: floors already exceed target) is
        // handled the same way in reverse — remove from the smallest
        // remainders first — so the function is total-preserving either way.
        arsort($remainders);
        $keysByRemainder = array_keys($remainders);

        if ($remaining > 0) {
            for ($i = 0; $i < $remaining; $i++) {
                $key = $keysByRemainder[$i % count($keysByRemainder)];
                $floors[$key]++;
            }
        } elseif ($remaining < 0) {
            $keysAscending = array_reverse($keysByRemainder);
            for ($i = 0; $i < abs($remaining); $i++) {
                $key = $keysAscending[$i % count($keysAscending)];
                $floors[$key] = max(0, $floors[$key] - 1);
            }
        }

        return $floors;
    }
}
