<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Built entirely from ReportService::attendanceDetailQuery()/Totals() — no
 * calculation logic here, only presentation. Exports the FULL filtered set
 * (never just the on-screen page), matching the PDF twin exactly.
 */
class AttendanceDetailReportExport implements WithMultipleSheets
{
    public function __construct(
        private readonly Collection $rows,
        private readonly array $totals,
        private readonly array $filters,
    ) {}

    public function sheets(): array
    {
        $subtitle = ($this->filters['from'] ?? 'All dates').' to '.($this->filters['to'] ?? 'All dates');

        $detail = new ArraySheet(
            'Attendance Detail',
            ['ITS', 'Full Name', 'Department', 'Session', 'Session Date', 'Status'],
            $this->rows->map(fn ($row) => [
                $row->khidmatguzar?->its_id,
                $row->khidmatguzar?->full_name ?? $row->full_name_snapshot,
                $row->department?->name,
                $row->dutySession?->name,
                $row->dutySession?->date?->format('d M Y'),
                ucfirst($row->current_status),
            ])->all(),
            reportTitle: 'KG Attendance — Attendance Detail Report',
            subtitle: $subtitle,
            landscape: true,
            statusColumn: 5,
        );

        $summary = new ArraySheet(
            'Summary',
            ['Scheduled', 'Present', 'Absent', 'Pending', 'Attendance Rate'],
            [[
                $this->totals['scheduled'], $this->totals['present'], $this->totals['absent'],
                $this->totals['pending'], $this->totals['rate'] !== null ? $this->totals['rate'].'%' : '—',
            ]],
            subtitle: $subtitle,
        );

        return [$detail, $summary];
    }
}
