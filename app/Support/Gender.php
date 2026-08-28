<?php

namespace App\Support;

/**
 * Single place that knows how raw gender values map to the three reporting
 * buckets. Real data mixes "M"/"Male" and "F"/"Female" (different import
 * batches used different source-file conventions) plus NULL for unknown.
 * This is query-time normalization only — the raw stored value (gender /
 * gender_snapshot) is never rewritten.
 */
class Gender
{
    public const MALE = 'Male';

    public const FEMALE = 'Female';

    public const UNKNOWN = 'Unknown';

    /**
     * A SQL CASE expression bucketing the given column into Male/Female/Unknown.
     * Safe to embed directly in selectRaw() — no user input passes through here.
     */
    public static function caseSql(string $column): string
    {
        return "CASE
            WHEN {$column} IN ('M', 'Male') THEN 'Male'
            WHEN {$column} IN ('F', 'Female') THEN 'Female'
            ELSE 'Unknown'
        END";
    }

    /** Same M/Male, F/Female, else-Unknown bucketing as caseSql(), for PHP-side display. */
    public static function shortLabel(?string $raw): string
    {
        if (in_array($raw, ['M', 'Male'], true)) {
            return 'M';
        }
        if (in_array($raw, ['F', 'Female'], true)) {
            return 'F';
        }

        return 'U';
    }
}
