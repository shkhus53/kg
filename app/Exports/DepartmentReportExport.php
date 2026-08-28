<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class DepartmentReportExport implements WithMultipleSheets
{
    public function __construct(private readonly array $report) {}

    public function sheets(): array
    {
        $r = $this->report;

        $scopeLabel = $r['session'] ? $r['session']->name.' ('.$r['session']->date->format('d M Y').')'
            : $r['from'].' to '.$r['to'];

        $summary = new ArraySheet('Summary', ['Field', 'Value'], [
            ['Scope', $scopeLabel],
            ['Departments', $r['departments']->count()],
            ['Generated At', now()->format('d M Y H:i')],
        ]);

        $rows = $r['departments']->map(fn ($d) => [
            $d->department_name,
            $d->scheduled,
            $d->present,
            $d->absent,
            $d->pending,
            $d->rate.'%',
        ])->all();

        $details = new ArraySheet(
            'Department Details',
            ['Department', 'Scheduled', 'Present', 'Absent', 'Pending', 'Rate'],
            $rows,
        );

        return [$summary, $details];
    }
}
