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

        // One unified table: scalar KPI/metadata rows carry their value in the
        // Total column only; the four status rows (Scheduled/Present/Absent/
        // Pending) are a real Male/Female/Unknown/Total matrix, so gender
        // composition for every status is visible without a second table or
        // a 20-column sheet. Reconciles both ways: horizontally (Male+Female+
        // Unknown=Total) and vertically (Present+Absent+Pending=Scheduled).
        $summaryRows = [];
        foreach ($sections as $section) {
            $g = $section['genderBreakdown'];
            $summaryRows[] = ['', '', '', '', ''];
            $summaryRows[] = [$section['department']->name, '', '', '', ''];
            $summaryRows[] = ['Report Date / Session', '', '', '', $scopeLabel];
            $summaryRows[] = ['Scheduled', $g['scheduled']['male'], $g['scheduled']['female'], $g['scheduled']['unknown'], $section['scheduled']];
            $summaryRows[] = ['Present', $g['present']['male'], $g['present']['female'], $g['present']['unknown'], $section['present']];
            $summaryRows[] = ['Absent', $g['absent']['male'], $g['absent']['female'], $g['absent']['unknown'], $section['absent']];
            $summaryRows[] = ['Pending', $g['pending']['male'], $g['pending']['female'], $g['pending']['unknown'], $section['pending']];
            $summaryRows[] = ['Attendance Rate', '', '', '', $section['rate'] !== null ? $section['rate'].'%' : 'N/A'];
            $summaryRows[] = ['Extra Present', '', '', '', $section['extraCount']];
        }

        $summary = new ArraySheet(
            'Department Summary',
            ['Metric', 'Male', 'Female', 'Unknown', 'Total'],
            $summaryRows,
            reportTitle: 'KG Attendance — Department Report',
            subtitle: $deptNames.' · '.$scopeLabel,
        );

        // A person can legitimately hold a separate assignment in this same
        // department across different sessions (recurring roster) — same ITS
        // can genuinely show different statuses. Session + Session Date give
        // the reader that context instead of an unexplained-looking conflict.
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
                    $a->dutySession->name,
                    $a->dutySession->date->format('d M Y'),
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
            ['Sr. No.', 'Name', 'ITS Number', 'Gender', 'Department', 'Session', 'Session Date', 'Block', 'Seat', 'Day', 'Status', 'Marked At'],
            $detailRows,
            landscape: true,
            statusColumn: 11,
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
