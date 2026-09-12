<?php

namespace App\Exports;

use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Built entirely from ReportService::operatorActivityReport() — no
 * calculation logic here, only presentation. Operational workload only,
 * not a leaderboard; rows are listed alphabetically-then-by-activity as the
 * on-screen view already sorts them, not re-ranked here.
 */
class OperatorActivityReportExport implements WithMultipleSheets
{
    public function __construct(private readonly array $report) {}

    public function sheets(): array
    {
        $rows = $this->report['operators']->map(fn ($row) => [
            $row['user']->name,
            ucfirst($row['user']->role),
            $row['total_actions'],
            $row['present_count'],
            $row['absent_count'],
            $row['corrections_count'],
            $row['extra_count'],
            $row['last_activity'] ? Carbon::parse($row['last_activity'])->format('d M Y H:i') : null,
        ])->all();

        $sheet = new ArraySheet(
            'Operator Activity',
            ['Operator', 'Role', 'Total Actions', 'Present', 'Absent', 'Corrections', 'Extra Present', 'Last Activity'],
            $rows,
            reportTitle: 'KG Attendance — Operator Activity Report',
            subtitle: $this->report['from'].' to '.$this->report['to'],
            landscape: true,
        );

        return [$sheet];
    }
}
