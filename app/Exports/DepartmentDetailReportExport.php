<?php

namespace App\Exports;

use App\Support\Gender;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Detailed single-(or multi-)department workbook: Department Summary,
 * Detailed Attendance (member-level, flat across all selected departments —
 * disambiguated by the Department column), and Extra Present as a fully
 * separate sheet. Built from ReportService::departmentDetailReport() —
 * no calculation logic lives here, only presentation.
 */
class DepartmentDetailReportExport implements WithMultipleSheets
{
    public function __construct(private readonly array $report) {}

    public function sheets(): array
    {
        $sections = $this->report['sections'];
        $scopeLabel = $this->report['session']
            ? $this->report['session']->name.' ('.$this->report['session']->date->format('d M Y').')'
            : $this->report['from'].' to '.$this->report['to'];

        $deptNames = $sections->map(fn ($s) => $s['department']->name)->implode(', ');

        $summaryRows = [];
        foreach ($sections as $section) {
            $g = $section['genderBreakdown'];
            $summaryRows[] = ['', ''];
            $summaryRows[] = [$section['department']->name, ''];
            $summaryRows[] = ['Report Date / Session', $scopeLabel];
            $summaryRows[] = ['Total Scheduled', $section['scheduled']];
            $summaryRows[] = ['Present', $section['present']];
            $summaryRows[] = ['Absent', $section['absent']];
            $summaryRows[] = ['Pending', $section['pending']];
            $summaryRows[] = ['Attendance Rate', $section['rate'] !== null ? $section['rate'].'%' : 'N/A'];
            $summaryRows[] = ['Extra Present', $section['extraCount']];
            $summaryRows[] = ['Scheduled — Male', $g['scheduled']['male']];
            $summaryRows[] = ['Scheduled — Female', $g['scheduled']['female']];
            $summaryRows[] = ['Scheduled — Unknown', $g['scheduled']['unknown']];
            $summaryRows[] = ['Present — Male', $g['present']['male']];
            $summaryRows[] = ['Present — Female', $g['present']['female']];
            $summaryRows[] = ['Present — Unknown', $g['present']['unknown']];
            $summaryRows[] = ['Absent — Male', $g['absent']['male']];
            $summaryRows[] = ['Absent — Female', $g['absent']['female']];
            $summaryRows[] = ['Absent — Unknown', $g['absent']['unknown']];
            $summaryRows[] = ['Pending — Male', $g['pending']['male']];
            $summaryRows[] = ['Pending — Female', $g['pending']['female']];
            $summaryRows[] = ['Pending — Unknown', $g['pending']['unknown']];
        }

        $summary = new ArraySheet(
            'Department Summary',
            ['Field', 'Value'],
            $summaryRows,
            reportTitle: 'KG Attendance — Department Report',
            subtitle: $deptNames.' · '.$scopeLabel,
        );

        $detailRows = [];
        $sr = 0;
        foreach ($sections as $section) {
            foreach ($section['assignments'] as $a) {
                $sr++;
                $detailRows[] = [
                    $sr,
                    $a->full_name_snapshot,
                    $a->khidmatguzar->its_id,
                    Gender::shortLabel($a->gender_snapshot),
                    $section['department']->name,
                    $a->block_name,
                    $a->seat,
                    $a->day_alias ?: $a->day,
                    ucfirst($a->current_status),
                    $a->attendance_marked_at?->format('d M Y H:i'),
                ];
            }
        }

        $detail = new ArraySheet(
            'Detailed Attendance',
            ['Sr. No.', 'Name', 'ITS Number', 'Gender', 'Department', 'Block', 'Seat', 'Day', 'Status', 'Marked At'],
            $detailRows,
            landscape: true,
            statusColumn: 9,
        );

        $extraRows = [];
        $sr = 0;
        foreach ($sections as $section) {
            foreach ($section['extraPresents'] as $e) {
                $sr++;
                $extraRows[] = [
                    $sr,
                    $e->full_name_snapshot,
                    $e->its_id_snapshot,
                    $section['department']->name,
                    $e->marked_at->format('d M Y H:i'),
                    $e->markedBy?->name,
                    $e->remark,
                ];
            }
        }

        $extra = new ArraySheet(
            'Extra Present',
            ['Sr. No.', 'Name', 'ITS Number', 'Department', 'Marked At', 'Marked By', 'Remark'],
            $extraRows,
            reportTitle: 'Extra Present — Not Part of Scheduled Attendance',
            subtitle: $scopeLabel,
            landscape: true,
        );

        return [$summary, $detail, $extra];
    }
}
