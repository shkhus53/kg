<?php

namespace App\Services;

use App\Support\HijriDate;
use Illuminate\Support\Facades\Cache;

/**
 * Dashboard Hijri date + Miqaat lookup. Deliberately isolated from every
 * KG Attendance business concept (DutySession/Event/EventPlan) — this
 * service knows nothing about attendance, and nothing in the attendance
 * domain feeds it. Its only inputs are:
 *   1. the operational "today" (Asia/Kolkata, via now()->toIst())
 *   2. resources/data/mumineen_miqaats.json — Miqaat data fetched
 *      verbatim from the reference Mumineen Calendar implementation
 *      (mygulamali/mumineen_calendar_js, source/data/miqaats.json),
 *      keyed by Hijri month/day (0-11 / 1-30), recurring every year.
 *
 * A KG Attendance Event named e.g. "Qadambosi Bethak" can NEVER surface
 * here — this class has no dependency on the Event/DutySession/EventPlan
 * models at all, so there is no code path by which one could leak in.
 */
class MumineenCalendarService
{
    private const DATA_PATH = 'data/mumineen_miqaats.json';

    /**
     * @return array<string,array<int,array{title:string,priority:int}>> keyed "month-day"
     */
    private function miqaatsByMonthDay(): array
    {
        return Cache::rememberForever('mumineen_calendar.miqaats_by_month_day', function () {
            $raw = json_decode(file_get_contents(resource_path(self::DATA_PATH)), true) ?? [];

            $byKey = [];
            foreach ($raw as $entry) {
                $key = $entry['month'].'-'.$entry['date'];
                $byKey[$key] = collect($entry['miqaats'])
                    ->map(fn ($m) => ['title' => $m['title'], 'priority' => $m['priority'] ?? 3])
                    ->sortBy('priority')
                    ->values()
                    ->all();
            }

            return $byKey;
        });
    }

    public function today(): HijriDate
    {
        return HijriDate::fromGregorian(now()->toIst());
    }

    /**
     * @return array<int,array{title:string,priority:int}>
     */
    public function miqaatsOn(HijriDate $date): array
    {
        return $this->miqaatsByMonthDay()["{$date->month}-{$date->day}"] ?? [];
    }

    /**
     * @return array<int,array{title:string,priority:int}>
     */
    public function todaysMiqaats(): array
    {
        return $this->miqaatsOn($this->today());
    }

    /**
     * One Hijri month laid out as full calendar weeks (leading/trailing
     * cells from the adjacent month included so every week has 7 days),
     * each cell carrying both its Hijri and Gregorian date plus any
     * Miqaats — the single "merged" data structure the calendar modal
     * renders, never two separate calendars.
     *
     * @return array<int,array<int,array{hijri:array{year:int,month:int,day:int},gregorian:array{year:int,month:int,day:int,iso:string},isToday:bool,miqaats:array}|null>>
     */
    public function monthGrid(HijriDate $anchor): array
    {
        $todayIso = now()->toIst()->toDateString();
        $daysInMonth = HijriDate::daysInMonth($anchor->year, $anchor->month);

        $cellFor = function (HijriDate $hijri) use ($todayIso) {
            $gregorian = $hijri->toGregorian();

            return [
                'hijri' => ['year' => $hijri->year, 'month' => $hijri->month, 'day' => $hijri->day],
                'gregorian' => [
                    'year' => (int) $gregorian->format('Y'),
                    'month' => (int) $gregorian->format('n'),
                    'day' => (int) $gregorian->format('j'),
                    'iso' => $gregorian->toDateString(),
                ],
                'isToday' => $gregorian->toDateString() === $todayIso,
                'miqaats' => $this->miqaatsOn($hijri),
            ];
        };

        $cells = [];
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $cells[] = $cellFor(new HijriDate($anchor->year, $anchor->month, $day));
        }

        // Pad to full weeks (Sunday-first) using the first/last cell's own
        // actual Gregorian weekday — never a fabricated date, just enough
        // leading/trailing blanks to align the grid.
        $firstWeekday = (int) HijriDate::ajdToGregorian((new HijriDate($anchor->year, $anchor->month, 1))->toAjd())->dayOfWeek;
        $lastWeekday = (int) HijriDate::ajdToGregorian((new HijriDate($anchor->year, $anchor->month, $daysInMonth))->toAjd())->dayOfWeek;

        $leading = array_fill(0, $firstWeekday, null);
        $trailing = array_fill(0, 6 - $lastWeekday, null);

        return array_chunk([...$leading, ...$cells, ...$trailing], 7);
    }

    /**
     * A window of consecutive Hijri months (current ± $span), for
     * client-side month navigation inside the calendar modal without any
     * fetch/reload — the whole window is embedded once as JSON.
     *
     * @return array<int,array{year:int,month:int,monthName:string,weeks:array}>
     */
    public function calendarWindow(int $span = 6): array
    {
        $anchor = $this->today();
        $months = [];

        $cursor = $anchor;
        for ($i = 0; $i < $span; $i++) {
            $cursor = $cursor->previousMonth();
        }

        for ($i = 0; $i < $span * 2 + 1; $i++) {
            $months[] = [
                'year' => $cursor->year,
                'month' => $cursor->month,
                'monthName' => $cursor->monthName(),
                'weeks' => $this->monthGrid($cursor),
            ];
            $cursor = $cursor->nextMonth();
        }

        return $months;
    }
}
