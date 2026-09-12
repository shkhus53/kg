<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Direct line-for-line PHP port of the Gregorian<->Hijri conversion
 * algorithm from the reference Dawoodi Bohra Mumineen Calendar
 * implementation (mygulamali/mumineen_calendar_js,
 * source/assets/javascripts/_lib/hijri_date.js) — fetched and verified
 * from that repository, not a generic/assumed Islamic calendar library.
 *
 * This matters: the tabular Islamic calendar has more than one commonly
 * used leap-year (Kabisa) remainder set (e.g. the "Kuwaiti algorithm"
 * uses {2,5,7,10,13,16,18,21,24,26,29}). The Mumineen/Bohra calendar uses
 * a DIFFERENT set — {2,5,8,10,13,16,19,21,24,27,29} — confirmed directly
 * from the reference source below. Using a generic library's leap-year
 * table would silently produce wrong Hijri dates for this community's
 * calendar, which is exactly why this is ported from the named
 * authoritative source rather than installed from a general-purpose
 * package.
 *
 * The AJD (Astronomical Julian Date) epoch and Gregorian<->JD conversion
 * (gregorianToAjd/ajdToGregorian) are the standard, textbook Julian Day
 * Number algorithm (Meeus) — not Bohra-specific, and identical in the
 * reference source.
 */
class HijriDate
{
    /** Hijri year remainders (year % 30) that are 355-day Kabisa (leap) years — Bohra/Misri-specific, verified against the reference source. */
    private const KABISA_YEAR_REMAINDERS = [2, 5, 8, 10, 13, 16, 19, 21, 24, 27, 29];

    /** Cumulative days at the START of each Hijri month (index 0 = end of month 1), for a non-leap 354-day year. */
    private const DAYS_IN_YEAR = [30, 59, 89, 118, 148, 177, 207, 236, 266, 295, 325];

    /** Cumulative days elapsed at the end of each year within one 30-year cycle. */
    private const DAYS_IN_30_YEARS = [
        354, 708, 1063, 1417, 1771, 2126, 2480, 2834, 3189, 3543,
        3898, 4252, 4606, 4961, 5315, 5669, 6024, 6378, 6732, 7087,
        7441, 7796, 8150, 8504, 8859, 9213, 9567, 9922, 10276, 10631,
    ];

    private const EPOCH_AJD = 1948083.5;

    public const MONTH_NAMES_LONG = [
        'Moharram al-Haraam', 'Safar al-Muzaffar', 'Rabi al-Awwal', 'Rabi al-Aakhar',
        'Jumada al-Ula', 'Jumada al-Ukhra', 'Rajab al-Asab', 'Shabaan al-Karim',
        'Ramadaan al-Moazzam', 'Shawwal al-Mukarram', 'Zilqadah al-Haraam', 'Zilhaj al-Haraam',
    ];

    public const MONTH_NAMES_SHORT = [
        'Moharram', 'Safar', 'Rabi I', 'Rabi II', 'Jumada I', 'Jumada II',
        'Rajab', 'Shabaan', 'Ramadaan', 'Shawwal', 'Zilqadah', 'Zilhaj',
    ];

    public function __construct(
        public readonly int $year,
        public readonly int $month, // 0-11
        public readonly int $day,   // 1-30
    ) {}

    public static function isKabisa(int $year): bool
    {
        return in_array((($year % 30) + 30) % 30, self::KABISA_YEAR_REMAINDERS, true);
    }

    public static function daysInMonth(int $year, int $month): int
    {
        return (($month === 11 && self::isKabisa($year)) || $month % 2 === 0) ? 30 : 29;
    }

    public function dayOfYear(): int
    {
        return $this->month === 0 ? $this->day : self::DAYS_IN_YEAR[$this->month - 1] + $this->day;
    }

    public function toAjd(): float
    {
        $y30 = intdiv($this->year, 30);
        $ajd = self::EPOCH_AJD + $y30 * 10631 + $this->dayOfYear();
        if ($this->year % 30 !== 0) {
            $ajd += self::DAYS_IN_30_YEARS[$this->year - $y30 * 30 - 1];
        }

        return $ajd;
    }

    public static function fromAjd(float $ajd): self
    {
        $left = floor($ajd - self::EPOCH_AJD);
        $y30 = intdiv((int) $left, 10631);
        $left -= $y30 * 10631;

        $i = 0;
        while ($left > self::DAYS_IN_30_YEARS[$i]) {
            $i++;
        }
        $year = $y30 * 30 + $i;
        if ($i > 0) {
            $left -= self::DAYS_IN_30_YEARS[$i - 1];
        }

        // DAYS_IN_YEAR only has 11 entries (cumulative day-count boundaries
        // between the 12 months) — once $left exceeds the last boundary it
        // simply means "the 12th month" (index 11), so the loop must stop
        // at the array bound rather than reading past it. The reference JS
        // relies on `left > undefined` being false to stop naturally; PHP
        // has no such implicit behavior (an out-of-range read compares as
        // if it were 0, which would loop forever), so the bound is explicit.
        $i = 0;
        while ($i < count(self::DAYS_IN_YEAR) && $left > self::DAYS_IN_YEAR[$i]) {
            $i++;
        }
        $month = $i;
        $day = (int) ($i > 0 ? $left - self::DAYS_IN_YEAR[$i - 1] : $left);

        return new self($year, $month, $day);
    }

    /**
     * Standard Julian Day Number algorithm (Meeus, "Astronomical
     * Algorithms") — the same formula used by the reference JS source,
     * not Bohra-specific.
     */
    public static function gregorianToAjd(Carbon $date): float
    {
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');
        $day = (int) $date->format('j')
            + $date->hour / 24 + $date->minute / 1440 + $date->second / 86400;

        if ($month < 3) {
            $year--;
            $month += 12;
        }

        $isJulian = $year < 1582 || ($year === 1582 && ($month < 10 || ($month === 10 && $day < 5)));
        $b = 0;
        if (! $isJulian) {
            $a = intdiv($year, 100);
            $b = 2 - $a + intdiv($a, 4);
        }

        return floor(365.25 * ($year + 4716)) + floor(30.6001 * ($month + 1)) + $day + $b - 1524.5;
    }

    public static function ajdToGregorian(float $ajd): Carbon
    {
        $z = floor($ajd + 0.5);
        if ($z < 2299161) {
            $a = $z;
        } else {
            $alpha = floor(($z - 1867216.25) / 36524.25);
            $a = $z + 1 + $alpha - floor(0.25 * $alpha);
        }

        $b = $a + 1524;
        $c = floor(($b - 122.1) / 365.25);
        $d = floor(365.25 * $c);
        $e = floor(($b - $d) / 30.6001);

        $day = (int) ($b - $d - floor(30.6001 * $e));
        $month = $e < 14 ? (int) $e - 1 : (int) $e - 13;
        $year = (int) ($month > 2 ? $c - 4716 : $c - 4715);

        return Carbon::create($year, $month, $day);
    }

    public static function fromGregorian(Carbon $date): self
    {
        return self::fromAjd(self::gregorianToAjd($date));
    }

    public function toGregorian(): Carbon
    {
        return self::ajdToGregorian($this->toAjd());
    }

    public function monthName(): string
    {
        return self::MONTH_NAMES_LONG[$this->month];
    }

    public function shortMonthName(): string
    {
        return self::MONTH_NAMES_SHORT[$this->month];
    }

    public function format(): string
    {
        return "{$this->day} {$this->monthName()} {$this->year}H";
    }

    public function previousMonth(): self
    {
        return $this->month === 0 ? new self($this->year - 1, 11, 1) : new self($this->year, $this->month - 1, 1);
    }

    public function nextMonth(): self
    {
        return $this->month === 11 ? new self($this->year + 1, 0, 1) : new self($this->year, $this->month + 1, 1);
    }
}
