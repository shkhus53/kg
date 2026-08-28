<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class KhidmatguzarReportExport implements WithMultipleSheets
{
    public function __construct(private readonly array $report) {}

    public function sheets(): array
    {
        $r = $this->report;
        $kg = $r['khidmatguzar'];

        $summary = new ArraySheet(
            'Profile Summary',
            ['Field', 'Value'],
            [
                ['Full Name', $kg->full_name],
                ['ITS Number', $kg->its_id],
                ['Jamaat', $kg->jamaat],
                ['', ''],
                ['Total Scheduled Duties', $r['total']],
                ['Present', $r['present']],
                ['Absent', $r['absent']],
                ['Pending', $r['pending']],
                ['Extra Present', $r['extraCount']],
                ['Attendance Rate', $r['rate'] !== null ? $r['rate'].'%' : 'N/A'],
                ['Generated At', now()->format('d M Y H:i')],
            ],
            reportTitle: 'KG Attendance — Khidmatguzar Report',
            subtitle: $kg->full_name.' · ITS '.$kg->its_id,
        );

        $deptRows = $r['departmentBreakdown']->map(fn ($d) => [
            $d->department_name,
            $d->duties,
            $d->present,
            $d->absent,
            $d->rate.'%',
        ])->all();

        $departments = new ArraySheet(
            'Department Breakdown',
            ['Department', 'Duties', 'Present', 'Absent', 'Rate'],
            $deptRows,
            landscape: true,
        );

        $historyRows = $r['history']->map(fn ($a) => [
            $a->dutySession->date->format('d M Y'),
            $a->dutySession->name,
            $a->department->name,
            $a->block_name,
            $a->seat,
            ucfirst($a->current_status),
        ])->all();

        $history = new ArraySheet(
            'Duty History',
            ['Date', 'Session', 'Department', 'Block', 'Seat', 'Status'],
            $historyRows,
            landscape: true,
        );

        $extraRows = $r['extraHistory']->map(fn ($e) => [
            $e->marked_at->format('d M Y'),
            $e->dutySession->name,
            $e->department_name_snapshot,
            $e->marked_at->format('H:i'),
            $e->remark,
        ])->all();

        $extra = new ArraySheet(
            'Extra Present',
            ['Date', 'Session', 'Department', 'Marked At', 'Remark'],
            $extraRows,
            landscape: true,
        );

        return [$summary, $departments, $history, $extra];
    }
}
