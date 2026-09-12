<?php

namespace Tests\Unit;

use App\Support\HijriDate;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the ported conversion algorithm exactly matches the reference
 * source (mygulamali/mumineen_calendar_js) it was fetched from — the
 * Bohra-specific Kabisa (leap year) remainder set, and round-trip
 * correctness of the Gregorian<->AJD<->Hijri chain.
 */
class HijriDateTest extends TestCase
{
    public function test_kabisa_remainders_match_the_verified_bohra_specific_set(): void
    {
        // {2,5,8,10,13,16,19,21,24,27,29} — confirmed from the reference
        // source's KABISA_YEAR_REMAINDERS constant. This is NOT the same
        // as the generic "Kuwaiti algorithm" set {2,5,7,10,13,16,18,21,24,26,29}
        // — years 8 vs 7, 19 vs 18, 27 vs 26 differ, which is exactly why a
        // generic Islamic calendar library must not be substituted here.
        $bohraLeapYears = [2, 5, 8, 10, 13, 16, 19, 21, 24, 27, 29];
        foreach (range(1, 30) as $remainder) {
            $expected = in_array($remainder % 30, $bohraLeapYears, true);
            $this->assertSame($expected, HijriDate::isKabisa($remainder), "year remainder {$remainder}");
        }
    }

    public function test_generic_kuwaiti_algorithm_leap_years_do_not_all_match(): void
    {
        // Sanity check that we are NOT accidentally using the generic set.
        $this->assertFalse(HijriDate::isKabisa(7), 'year 7 is a leap year under the generic Kuwaiti algorithm but not under the Bohra set used here.');
        $this->assertTrue(HijriDate::isKabisa(8), 'year 8 is the Bohra-specific leap year in this position.');
    }

    public function test_gregorian_to_hijri_to_gregorian_round_trips_exactly(): void
    {
        foreach ([
            '2026-09-12', '2026-01-01', '2000-02-29', '1990-07-04',
            '2050-12-31', '1582-10-20', '1900-01-01',
        ] as $dateString) {
            $gregorian = Carbon::createFromFormat('Y-m-d', $dateString)->startOfDay();
            $hijri = HijriDate::fromGregorian($gregorian);
            $roundTripped = $hijri->toGregorian();

            $this->assertSame($gregorian->toDateString(), $roundTripped->toDateString(), "round-trip failed for {$dateString}");
        }
    }

    public function test_days_in_month_is_29_or_30_only(): void
    {
        foreach (range(1440, 1460) as $year) {
            foreach (range(0, 11) as $month) {
                $days = HijriDate::daysInMonth($year, $month);
                $this->assertContains($days, [29, 30]);
            }
        }
    }

    public function test_month_29_dhul_hijjah_has_30_days_only_in_a_kabisa_year(): void
    {
        // Month index 11 = Zilhaj/Dhul Hijjah, the only month whose length
        // depends on the leap-year rule rather than being fixed by parity.
        $this->assertSame(30, HijriDate::daysInMonth(1442, 11), '1442 % 30 = 2, a Kabisa year.');
        $this->assertSame(29, HijriDate::daysInMonth(1441, 11), '1441 % 30 = 1, not a Kabisa year.');
    }

    public function test_next_and_previous_month_wrap_years_correctly(): void
    {
        $lastMonthOfYear = new HijriDate(1448, 11, 1);
        $this->assertSame(1449, $lastMonthOfYear->nextMonth()->year);
        $this->assertSame(0, $lastMonthOfYear->nextMonth()->month);

        $firstMonthOfYear = new HijriDate(1448, 0, 1);
        $this->assertSame(1447, $firstMonthOfYear->previousMonth()->year);
        $this->assertSame(11, $firstMonthOfYear->previousMonth()->month);
    }

    public function test_month_name_formatting(): void
    {
        $date = new HijriDate(1448, 4, 30); // month index 4 = Jumada al-Ula
        $this->assertSame('Jumada al-Ula', $date->monthName());
        $this->assertSame('30 Jumada al-Ula 1448H', $date->format());
    }
}
