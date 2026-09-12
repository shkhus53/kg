<?php

namespace Tests\Feature;

use App\Exports\DepartmentDetailReportExport;
use App\Exports\DepartmentReportExport;
use App\Exports\KhidmatguzarReportExport;
use App\Exports\OperatorActivityReportExport;
use App\Exports\SessionAttendanceExport;
use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\ImportBatch;
use App\Models\Khidmatguzar;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Regression for the audit-confirmed bug: individual attendance/extra-present
 * `marked_at`/`performed_at` timestamps were exported with raw ->format()
 * (server UTC) while the report's own "Generated At" header already used
 * ->toIst(). Every export below must render the SAME operational-IST wall
 * clock time an operator actually acted at, not the raw UTC storage value.
 *
 * 15 Jan 2026 20:00 UTC = 16 Jan 2026 01:30 IST — deliberately crosses
 * midnight so a bug that skips the +5:30 shift is caught by the date
 * component too, not just the hour.
 */
class ReportExportTimezoneTest extends TestCase
{
    use RefreshDatabase;

    /** Flattens every sheet's cell values into one searchable string — a multi-sheet export's relevant data may not be on the active/first sheet. */
    private function flattenAllSheets(string $filename, object $export): string
    {
        $path = storage_path('app/private/'.$filename);
        Excel::store($export, $filename, 'local');
        $spreadsheet = IOFactory::load($path);
        unlink($path);

        $flat = [];
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $flat[] = collect($sheet->toArray())->flatten()->filter()->implode(' | ');
        }

        return implode(' || ', $flat);
    }

    private function seedTz(): array
    {
        Carbon::setTestNow(Carbon::create(2026, 1, 15, 20, 0, 0, 'UTC'));

        $admin = User::factory()->admin()->create();
        $session = DutySession::create(['name' => 'TZ Session', 'date' => '2026-01-15', 'status' => 'active']);
        $dept = Department::create(['name' => 'TZ DEPT', 'normalized_key' => Department::normalize('TZ DEPT')]);
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $admin->id, 'original_filename' => 'tz.csv', 'file_type' => 'csv', 'status' => 'completed']);
        $kg = Khidmatguzar::create(['its_id' => (string) random_int(10000000, 99999999), 'full_name' => 'TZ Person', 'gender' => 'M']);

        $assignment = DutyAssignment::create([
            'duty_session_id' => $session->id, 'import_batch_id' => $batch->id,
            'khidmatguzar_id' => $kg->id, 'department_id' => $dept->id,
            'source_row_number' => 1, 'assignment_fingerprint' => hash('sha256', uniqid()),
            'venue_name_raw' => $dept->name, 'full_name_snapshot' => $kg->full_name,
            'gender_snapshot' => 'M', 'current_status' => 'pending',
        ]);

        app(AttendanceService::class)->markPresent($session, $assignment->id, $admin, null);

        $extraKg = Khidmatguzar::create(['its_id' => (string) random_int(10000000, 99999999), 'full_name' => 'TZ Extra Person', 'gender' => 'M']);
        app(AttendanceService::class)->markExtraPresentKnown($session, $extraKg, $dept, 'Male', $admin);

        return [$session, $dept, $kg, $admin];
    }

    public function test_session_export_marked_at_is_ist_not_utc(): void
    {
        [$session] = $this->seedTz();
        $report = app(ReportService::class)->sessionReport($session);

        $flat = $this->flattenAllSheets('tz-session.xlsx', new SessionAttendanceExport($report));

        $this->assertStringContainsString('16 Jan 2026 01:30', $flat, 'Present mark and Extra Present mark must render in IST, not raw UTC (15 Jan 20:00).');
        $this->assertStringNotContainsString('15 Jan 2026 20:00', $flat);
    }

    public function test_department_export_marked_at_is_ist_not_utc(): void
    {
        [$session, $dept] = $this->seedTz();
        $report = app(ReportService::class)->departmentReport('2026-01-15', '2026-01-15', $session->id);

        $flat = $this->flattenAllSheets('tz-department.xlsx', new DepartmentReportExport($report));

        $this->assertStringContainsString('16 Jan 2026 01:30', $flat);
        $this->assertStringNotContainsString('15 Jan 2026 20:00', $flat);
    }

    public function test_department_detail_export_marked_at_is_ist_not_utc(): void
    {
        [$session, $dept] = $this->seedTz();
        $report = app(ReportService::class)->departmentDetailReport([$dept->id], '2026-01-15', '2026-01-15', $session->id);

        $flat = $this->flattenAllSheets('tz-department-detail.xlsx', new DepartmentDetailReportExport($report));

        $this->assertStringContainsString('16 Jan 2026 01:30', $flat);
        $this->assertStringNotContainsString('15 Jan 2026 20:00', $flat);
    }

    public function test_khidmatguzar_export_marked_at_is_ist_not_utc(): void
    {
        [$session, $dept, $kg] = $this->seedTz();
        $report = app(ReportService::class)->khidmatguzarReport($kg);

        $flat = $this->flattenAllSheets('tz-khidmatguzar.xlsx', new KhidmatguzarReportExport($report));

        $this->assertStringContainsString('16 Jan 2026', $flat, 'date part must be IST-shifted');
        $this->assertStringContainsString('01:30', $flat, 'time part must be IST-shifted');
        $this->assertStringNotContainsString('20:00', $flat);
    }

    public function test_operator_activity_export_last_activity_is_ist_not_utc(): void
    {
        $this->seedTz();
        $report = app(ReportService::class)->operatorActivityReport('2026-01-15', '2026-01-15');

        $flat = $this->flattenAllSheets('tz-operator.xlsx', new OperatorActivityReportExport($report));

        $this->assertStringContainsString('16 Jan 2026 01:30', $flat, 'last_activity must be IST-shifted, not raw UTC MAX(performed_at).');
        $this->assertStringNotContainsString('15 Jan 2026 20:00', $flat);
    }
}
