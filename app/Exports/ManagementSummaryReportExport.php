<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Built entirely from ReportService::managementSummary() — no calculation
 * logic here, only presentation. Same numbers as the on-screen Management
 * Summary and its PDF twin.
 */
class ManagementSummaryReportExport implements WithMultipleSheets
{
    public function __construct(private readonly array $report) {}

    public function sheets(): array
    {
        $subtitle = $this->report['from'].' to '.$this->report['to'].' — Generated '.$this->report['generated_at']->format('d M Y H:i').' IST';

        $operations = new ArraySheet(
            'Operations',
            ['Sessions', 'Scheduled', 'Present', 'Absent', 'Pending', 'Attendance Rate', 'Extra Present'],
            [[
                $this->report['sessions'], $this->report['scheduled'], $this->report['present'],
                $this->report['absent'], $this->report['pending'],
                $this->report['rate'] !== null ? $this->report['rate'].'%' : '—', $this->report['extra'],
            ]],
            reportTitle: 'KG Attendance — Management Summary',
            subtitle: $subtitle,
        );

        $planning = new ArraySheet(
            'Planning',
            ['Planned', 'Actual Assigned', 'Planning Gap', 'Underplanned Departments'],
            [[
                $this->report['planned'], $this->report['actual'],
                $this->report['planningGap'], $this->report['underplannedDepartments'],
            ]],
            subtitle: $subtitle,
        );

        $quality = new ArraySheet(
            'Quality',
            ['Imports', 'Invalid Rows', 'Corrections', 'Reopened Sessions'],
            [[
                $this->report['imports'], $this->report['invalidRows'],
                $this->report['corrections'], $this->report['reopenedSessions'],
            ]],
            subtitle: $subtitle,
        );

        $attention = new ArraySheet(
            'Attention',
            ['Severity', 'Title', 'Reason'],
            $this->report['topAlerts']->map(fn ($a) => [strtoupper($a['severity']), $a['title'], $a['reason']])->all(),
            subtitle: $subtitle,
        );

        return [$operations, $planning, $quality, $attention];
    }
}
