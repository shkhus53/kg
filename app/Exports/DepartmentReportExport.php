<?php

namespace App\Exports;

use App\Support\Gender;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * All-departments management workbook: Executive Summary (org-wide totals +
 * gender), Department Summary (one row per department), Detailed Attendance
 * (every scheduled record across all departments), Extra Present (fully
 * separate). Built entirely from ReportService::departmentReport() — no
 * calculation logic lives here, only presentation.
 */
class DepartmentReportExport implements WithMultipleSheets
{
    public function __construct(private readonly array $report) {}

    public function sheets(): array
    {
        $r = $this->report;
        $g = $r['genderBreakdown'];

        $scopeLabel = $r['session'] ? $r['session']->name.' ('.$r['session']->date->format('d M Y').')'
            : $r['from'].' to '.$r['to'];

        $executive = new ArraySheet(
            'Executive Summary',
            ['Field', 'Value'],
            [
                ['Departments', $r['departments']->count()],
                ['', ''],
                ['Total Scheduled', $r['scheduled']],
                ['Present', $r['present']],
                ['Absent', $r['absent']],
                ['Pending', $r['pending']],
                ['Attendance Rate', $r['rate'] !== null ? $r['rate'].'%' : 'N/A'],
                ['Extra Present', $r['extraCount']],
                ['', ''],
                ['Overall — Male', $g['scheduled']['male']],
                ['Overall — Female', $g['scheduled']['female']],
                ['Overall — Unknown', $g['scheduled']['unknown']],
                ['Present — Male', $g['present']['male']],
                ['Present — Female', $g['present']['female']],
                ['Present — Unknown', $g['present']['unknown']],
                ['Absent — Male', $g['absent']['male']],
                ['Absent — Female', $g['absent']['female']],
                ['Absent — Unknown', $g['absent']['unknown']],
                ['Pending — Male', $g['pending']['male']],
                ['Pending — Female', $g['pending']['female']],
                ['Pending — Unknown', $g['pending']['unknown']],
                ['', ''],
                ['Generated At', now()->format('d M Y H:i')],
            ],
            reportTitle: 'KG Attendance — Department Report',
            subtitle: $scopeLabel,
        );

        $deptRows = $r['departments']->map(fn ($d) => [
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

        $departmentSummary = new ArraySheet(
            'Department Summary',
            ['Department', 'Scheduled', 'Present', 'Absent', 'Pending', 'Attendance Rate', 'Male', 'Female', 'Unknown'],
            $deptRows,
            landscape: true,
        );

        $detailRows = $r['assignments']->values()->map(fn ($a, $i) => [
            $i + 1,
            $a->full_name_snapshot,
            $a->khidmatguzar->its_id,
            Gender::shortLabel($a->gender_snapshot),
            $a->department->name,
            $a->block_name,
            $a->seat,
            $a->day_alias ?: $a->day,
            ucfirst($a->current_status),
            $a->attendance_marked_at?->format('d M Y H:i'),
        ])->all();

        $detail = new ArraySheet(
            'Detailed Attendance',
            ['Sr. No.', 'Name', 'ITS Number', 'Gender', 'Department', 'Block', 'Seat', 'Day', 'Status', 'Marked At'],
            $detailRows,
            landscape: true,
            statusColumn: 9,
        );

        $extraRows = $r['extraPresents']->values()->map(fn ($e, $i) => [
            $i + 1,
            $e->full_name_snapshot,
            $e->its_id_snapshot,
            $e->department_name_snapshot,
            $e->marked_at->format('d M Y H:i'),
            $e->markedBy?->name,
            $e->remark,
        ])->all();

        $extra = new ArraySheet(
            'Extra Present',
            ['Sr. No.', 'Name', 'ITS Number', 'Department', 'Marked At', 'Marked By', 'Remark'],
            $extraRows,
            reportTitle: 'Extra Present — Not Part of Scheduled Attendance',
            subtitle: $scopeLabel,
            landscape: true,
        );

        return [$executive, $departmentSummary, $detail, $extra];
    }
}
