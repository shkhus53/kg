<?php

namespace App\Exports;

use App\Support\Gender;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class SessionAttendanceExport implements WithMultipleSheets
{
    public function __construct(private readonly array $report) {}

    public function sheets(): array
    {
        $r = $this->report;
        $session = $r['dutySession'];
        $g = $r['genderBreakdown'];

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
            ['', ''],
            ['Male Scheduled', $g['scheduled']['male']],
            ['Female Scheduled', $g['scheduled']['female']],
            ['Unknown Scheduled', $g['scheduled']['unknown']],
            ['Male Present', $g['present']['male']],
            ['Female Present', $g['present']['female']],
            ['Unknown Present', $g['present']['unknown']],
            ['Male Absent', $g['absent']['male']],
            ['Female Absent', $g['absent']['female']],
            ['Unknown Absent', $g['absent']['unknown']],
            ['Male Pending', $g['pending']['male']],
            ['Female Pending', $g['pending']['female']],
            ['Unknown Pending', $g['pending']['unknown']],
            ['Generated At', now()->format('d M Y H:i')],
        ]);

        $attendanceRows = $r['assignments']->map(fn ($a) => [
            $a->full_name_snapshot,
            $a->khidmatguzar->its_id,
            Gender::shortLabel($a->gender_snapshot),
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
            ['Full Name', 'ITS Number', 'Gender', 'Department', 'Block', 'Seat', 'Day', 'Status', 'Marked At', 'Marked By'],
            $attendanceRows,
        );

        $departmentRows = $r['departments']->map(fn ($d) => [
            $d->department_name,
            $d->scheduled,
            $d->present,
            $d->absent,
            $d->pending,
            $d->rate.'%',
            $d->genderBreakdown['scheduled']['male'],
            $d->genderBreakdown['scheduled']['female'],
            $d->genderBreakdown['scheduled']['unknown'],
        ])->all();

        $departments = new ArraySheet(
            'Departments',
            ['Department', 'Scheduled', 'Present', 'Absent', 'Pending', 'Rate', 'Male', 'Female', 'Unknown'],
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
