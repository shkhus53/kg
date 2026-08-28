<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class SessionAttendanceExport implements WithMultipleSheets
{
    public function __construct(private readonly array $report) {}

    public function sheets(): array
    {
        $r = $this->report;
        $session = $r['dutySession'];

        $summary = new ArraySheet('Summary', ['Field', 'Value'], [
            ['Session Name', $session->name],
            ['Date', $session->date->format('d M Y')],
            ['Status', ucfirst($session->status)],
            ['Total Scheduled', $r['scheduled']],
            ['Present', $r['present']],
            ['Absent', $r['absent']],
            ['Pending', $r['pending']],
            ['Extra Present', $r['extraCount']],
            ['Attendance Rate', $r['rate'] !== null ? $r['rate'].'%' : 'N/A'],
            ['Generated At', now()->format('d M Y H:i')],
        ]);

        $attendanceRows = $r['assignments']->map(fn ($a) => [
            $a->full_name_snapshot,
            $a->khidmatguzar->its_id,
            $a->department->name,
            $a->block_name,
            $a->seat,
            $a->day_alias ?: $a->day,
            ucfirst($a->current_status),
            $a->attendance_marked_at?->format('d M Y H:i'),
            $a->attendanceMarkedBy?->name,
        ])->all();

        $attendance = new ArraySheet(
            'Attendance',
            ['Full Name', 'ITS Number', 'Department', 'Block', 'Seat', 'Day', 'Status', 'Marked At', 'Marked By'],
            $attendanceRows,
        );

        $departmentRows = $r['departments']->map(fn ($d) => [
            $d->department_name,
            $d->scheduled,
            $d->present,
            $d->absent,
            $d->pending,
            $d->rate.'%',
        ])->all();

        $departments = new ArraySheet(
            'Departments',
            ['Department', 'Scheduled', 'Present', 'Absent', 'Pending', 'Rate'],
            $departmentRows,
        );

        $extraRows = $r['extraPresents']->map(fn ($e) => [
            $e->full_name_snapshot,
            $e->its_id_snapshot,
            $e->department_name_snapshot,
            $e->marked_at->format('d M Y H:i'),
            $e->markedBy?->name,
            $e->remark,
        ])->all();

        $extra = new ArraySheet(
            'Extra Present',
            ['Full Name', 'ITS Number', 'Department', 'Marked At', 'Marked By', 'Remark'],
            $extraRows,
        );

        return [$summary, $departments, $attendance, $extra];
    }
}
