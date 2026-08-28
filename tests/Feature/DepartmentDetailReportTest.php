<?php

namespace Tests\Feature;

use App\Exports\DepartmentDetailReportExport;
use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\ImportBatch;
use App\Models\Khidmatguzar;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class DepartmentDetailReportTest extends TestCase
{
    use RefreshDatabase;

    private function makeAssignment(DutySession $session, Department $dept, ImportBatch $batch, ?string $gender, string $status, string $its = null): DutyAssignment
    {
        $kg = Khidmatguzar::create(['its_id' => $its ?? (string) random_int(10000000, 99999999), 'full_name' => 'Dept Detail Person', 'gender' => $gender]);

        return DutyAssignment::create([
            'duty_session_id' => $session->id, 'import_batch_id' => $batch->id,
            'khidmatguzar_id' => $kg->id, 'department_id' => $dept->id,
            'source_row_number' => 1, 'assignment_fingerprint' => hash('sha256', uniqid()),
            'venue_name_raw' => $dept->name, 'full_name_snapshot' => $kg->full_name,
            'gender_snapshot' => $gender, 'current_status' => $status,
        ]);
    }

    private function seedTwoDepartments(): array
    {
        $user = User::factory()->admin()->create();
        $session = DutySession::create(['name' => 'Dept Detail Session', 'date' => now()->format('Y-m-d'), 'status' => 'active']);
        $deptA = Department::create(['name' => 'ALPHA DEPT', 'normalized_key' => Department::normalize('ALPHA DEPT')]);
        $deptB = Department::create(['name' => 'BETA DEPT', 'normalized_key' => Department::normalize('BETA DEPT')]);
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $user->id, 'original_filename' => 'd.csv', 'file_type' => 'csv', 'status' => 'completed']);

        // Department A: 4 assignments (2 present, 1 absent, 1 pending), mixed gender incl. unknown.
        $this->makeAssignment($session, $deptA, $batch, 'M', 'present');
        $this->makeAssignment($session, $deptA, $batch, 'Female', 'present');
        $this->makeAssignment($session, $deptA, $batch, 'F', 'absent');
        $this->makeAssignment($session, $deptA, $batch, null, 'pending');

        // Department B: 2 assignments, untouched by A's filters — proves per-department isolation.
        $this->makeAssignment($session, $deptB, $batch, 'Male', 'present');
        $this->makeAssignment($session, $deptB, $batch, 'F', 'absent');

        $extraKg = Khidmatguzar::create(['its_id' => (string) random_int(10000000, 99999999), 'full_name' => 'Dept Extra Person']);
        app(AttendanceService::class)->markExtraPresentKnown($session, $extraKg, $deptA, $user);

        return [$session, $deptA, $deptB, $user];
    }

    public function test_department_summary_totals(): void
    {
        [$session, $deptA] = $this->seedTwoDepartments();

        $report = app(ReportService::class)->departmentDetailReport([$deptA->id], $session->date->format('Y-m-d'), $session->date->format('Y-m-d'), $session->id);
        $section = $report['sections']->first();

        $this->assertSame(4, $section['scheduled']);
        $this->assertSame(2, $section['present']);
        $this->assertSame(1, $section['absent']);
        $this->assertSame(1, $section['pending']);
    }

    public function test_department_gender_breakdown(): void
    {
        [$session, $deptA] = $this->seedTwoDepartments();

        $report = app(ReportService::class)->departmentDetailReport([$deptA->id], $session->date->format('Y-m-d'), $session->date->format('Y-m-d'), $session->id);
        $g = $report['sections']->first()['genderBreakdown'];

        $this->assertSame(1, $g['scheduled']['male']);
        $this->assertSame(2, $g['scheduled']['female']); // Female + F collapse
        $this->assertSame(1, $g['scheduled']['unknown']);
    }

    public function test_detailed_attendance_member_count_matches_scheduled(): void
    {
        [$session, $deptA] = $this->seedTwoDepartments();

        $report = app(ReportService::class)->departmentDetailReport([$deptA->id], $session->date->format('Y-m-d'), $session->date->format('Y-m-d'), $session->id);
        $section = $report['sections']->first();

        $this->assertSame($section['scheduled'], $section['assignments']->count());
    }

    public function test_detailed_rows_match_department_membership_only(): void
    {
        [$session, $deptA, $deptB] = $this->seedTwoDepartments();

        $report = app(ReportService::class)->departmentDetailReport([$deptA->id], $session->date->format('Y-m-d'), $session->date->format('Y-m-d'), $session->id);
        $section = $report['sections']->first();

        foreach ($section['assignments'] as $a) {
            $this->assertSame($deptA->id, $a->department_id);
        }
        $this->assertSame(4, $section['assignments']->count()); // not deptB's 2
    }

    public function test_scheduled_present_absent_pending_reconciliation(): void
    {
        [$session, $deptA] = $this->seedTwoDepartments();

        $section = app(ReportService::class)->departmentDetailReport([$deptA->id], $session->date->format('Y-m-d'), $session->date->format('Y-m-d'), $session->id)['sections']->first();

        $this->assertSame($section['scheduled'], $section['present'] + $section['absent'] + $section['pending']);
    }

    public function test_gender_reconciliation(): void
    {
        [$session, $deptA] = $this->seedTwoDepartments();

        $section = app(ReportService::class)->departmentDetailReport([$deptA->id], $session->date->format('Y-m-d'), $session->date->format('Y-m-d'), $session->id)['sections']->first();
        $g = $section['genderBreakdown'];

        $this->assertSame($section['scheduled'], $g['scheduled']['male'] + $g['scheduled']['female'] + $g['scheduled']['unknown']);
        foreach (['male', 'female', 'unknown'] as $bucket) {
            $this->assertSame($g['scheduled'][$bucket], $g['present'][$bucket] + $g['absent'][$bucket] + $g['pending'][$bucket]);
        }
    }

    public function test_extra_present_remains_separate_from_scheduled(): void
    {
        [$session, $deptA] = $this->seedTwoDepartments();

        $section = app(ReportService::class)->departmentDetailReport([$deptA->id], $session->date->format('Y-m-d'), $session->date->format('Y-m-d'), $session->id)['sections']->first();

        $this->assertSame(4, $section['scheduled']); // unaffected by the 1 extra present
        $this->assertSame(1, $section['extraCount']);
    }

    public function test_excel_contains_expected_three_sheets(): void
    {
        [$session, $deptA] = $this->seedTwoDepartments();
        $data = app(ReportService::class)->departmentDetailReport([$deptA->id], $session->date->format('Y-m-d'), $session->date->format('Y-m-d'), $session->id);

        $path = storage_path('app/private/test-dept-sheets.xlsx');
        \Maatwebsite\Excel\Facades\Excel::store(new DepartmentDetailReportExport($data), 'test-dept-sheets.xlsx', 'local');

        $spreadsheet = IOFactory::load($path);
        $titles = array_map(fn ($s) => $s->getTitle(), $spreadsheet->getAllSheets());

        $this->assertSame(['Department Summary', 'Detailed Attendance', 'Extra Present'], $titles);

        unlink($path);
    }

    public function test_excel_detailed_attendance_row_count_matches_scheduled(): void
    {
        [$session, $deptA] = $this->seedTwoDepartments();
        $data = app(ReportService::class)->departmentDetailReport([$deptA->id], $session->date->format('Y-m-d'), $session->date->format('Y-m-d'), $session->id);

        \Maatwebsite\Excel\Facades\Excel::store(new DepartmentDetailReportExport($data), 'test-dept-count.xlsx', 'local');
        $path = storage_path('app/private/test-dept-count.xlsx');

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Detailed Attendance');

        $this->assertSame(4 + 1, $sheet->getHighestRow()); // 4 data rows + 1 header row

        unlink($path);
    }

    public function test_excel_extra_present_sheet_contains_correct_records(): void
    {
        [$session, $deptA] = $this->seedTwoDepartments();
        $data = app(ReportService::class)->departmentDetailReport([$deptA->id], $session->date->format('Y-m-d'), $session->date->format('Y-m-d'), $session->id);

        \Maatwebsite\Excel\Facades\Excel::store(new DepartmentDetailReportExport($data), 'test-dept-extra.xlsx', 'local');
        $path = storage_path('app/private/test-dept-extra.xlsx');

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Extra Present');

        // title + subtitle + blank spacer + header + 1 data row
        $this->assertSame(5, $sheet->getHighestRow());
        $this->assertSame('Extra Present — Not Part of Scheduled Attendance', $sheet->getCell('A1')->getValue());
        $this->assertSame('Dept Extra Person', $sheet->getCell('B5')->getValue());

        unlink($path);
    }

    public function test_pdf_contains_department_details_and_attendance(): void
    {
        [$session, $deptA] = $this->seedTwoDepartments();
        $data = app(ReportService::class)->departmentDetailReport([$deptA->id], $session->date->format('Y-m-d'), $session->date->format('Y-m-d'), $session->id);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.pdf.department-detail', $data)->setPaper('a4', 'portrait');
        $content = $pdf->output();

        $this->assertStringStartsWith('%PDF-', $content);
        $this->assertGreaterThan(1000, strlen($content));
    }

    public function test_empty_department_scope_does_not_error(): void
    {
        [$session, $deptA] = $this->seedTwoDepartments();

        $data = app(ReportService::class)->departmentDetailReport([$deptA->id], '2000-01-01', '2000-01-02', null);
        $section = $data['sections']->first();

        $this->assertSame(0, $section['scheduled']);
        $this->assertSame(0, $section['assignments']->count());
        $this->assertSame(0, $section['genderBreakdown']['scheduled']['male']);

        // Must not error when rendering either.
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.pdf.department-detail', $data);
        $this->assertStringStartsWith('%PDF-', $pdf->output());

        \Maatwebsite\Excel\Facades\Excel::store(new DepartmentDetailReportExport($data), 'test-dept-empty.xlsx', 'local');
        unlink(storage_path('app/private/test-dept-empty.xlsx'));
    }

    public function test_unknown_gender_handled_not_excluded(): void
    {
        [$session, $deptA] = $this->seedTwoDepartments();

        $section = app(ReportService::class)->departmentDetailReport([$deptA->id], $session->date->format('Y-m-d'), $session->date->format('Y-m-d'), $session->id)['sections']->first();

        $this->assertSame(1, $section['genderBreakdown']['pending']['unknown']);
    }

    public function test_existing_session_and_khidmatguzar_exports_unaffected(): void
    {
        $user = User::factory()->admin()->create();
        $session = DutySession::create(['name' => 'Regression Session', 'date' => now()->format('Y-m-d'), 'status' => 'active']);

        $this->actingAs($user)->get(route('reports.session.pdf', $session))->assertOk();
        $this->actingAs($user)->get(route('reports.session.excel', $session))->assertOk();

        $kg = Khidmatguzar::create(['its_id' => (string) random_int(10000000, 99999999), 'full_name' => 'Regression Person']);
        $this->actingAs($user)->get(route('reports.khidmatguzar.pdf', $kg))->assertOk();
        $this->actingAs($user)->get(route('reports.khidmatguzar.excel', $kg))->assertOk();
    }

    public function test_all_departments_summary_still_works_without_department_id(): void
    {
        $user = User::factory()->admin()->create();
        $this->seedTwoDepartments();

        $response = $this->actingAs($user)->get(route('reports.department'));
        $response->assertOk();
        $response->assertViewIs('reports.department');
    }

    public function test_single_department_selection_renders_detail_view(): void
    {
        [$session, $deptA] = $this->seedTwoDepartments();
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->get(route('reports.department', ['department_id' => $deptA->id]));
        $response->assertOk();
        $response->assertViewIs('reports.department-detail');
    }

    public function test_all_departments_excel_has_four_sheets_with_correct_row_counts(): void
    {
        [$session, $deptA, $deptB] = $this->seedTwoDepartments();
        $data = app(ReportService::class)->departmentReport($session->date->format('Y-m-d'), $session->date->format('Y-m-d'), $session->id);

        \Maatwebsite\Excel\Facades\Excel::store(new \App\Exports\DepartmentReportExport($data), 'test-all-dept.xlsx', 'local');
        $path = storage_path('app/private/test-all-dept.xlsx');

        $spreadsheet = IOFactory::load($path);
        $titles = array_map(fn ($s) => $s->getTitle(), $spreadsheet->getAllSheets());
        $this->assertSame(['Executive Summary', 'Department Summary', 'Gender Breakdown', 'Detailed Attendance', 'Extra Present'], $titles);

        // 4 (deptA) + 2 (deptB) = 6 scheduled assignments across both departments.
        $detail = $spreadsheet->getSheetByName('Detailed Attendance');
        $this->assertSame(6 + 1, $detail->getHighestRow());

        $deptSummary = $spreadsheet->getSheetByName('Department Summary');
        $this->assertSame(2 + 1, $deptSummary->getHighestRow()); // 2 departments + header

        unlink($path);
    }

    public function test_all_departments_executive_summary_reconciles(): void
    {
        [$session] = $this->seedTwoDepartments();
        $data = app(ReportService::class)->departmentReport($session->date->format('Y-m-d'), $session->date->format('Y-m-d'), $session->id);

        $this->assertSame(6, $data['scheduled']);
        $this->assertSame($data['scheduled'], $data['present'] + $data['absent'] + $data['pending']);
        $g = $data['genderBreakdown'];
        $this->assertSame($data['scheduled'], $g['scheduled']['male'] + $g['scheduled']['female'] + $g['scheduled']['unknown']);
        $this->assertSame(1, $data['extraCount']); // extra present stays outside scheduled
    }

    public function test_department_summary_gender_matrix_reconciles_in_generated_excel(): void
    {
        [$session, $deptA] = $this->seedTwoDepartments();
        $data = app(ReportService::class)->departmentDetailReport([$deptA->id], $session->date->format('Y-m-d'), $session->date->format('Y-m-d'), $session->id);

        \Maatwebsite\Excel\Facades\Excel::store(new DepartmentDetailReportExport($data), 'test-dept-matrix.xlsx', 'local');
        $path = storage_path('app/private/test-dept-matrix.xlsx');

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Department Summary');

        // Header: Metric | Male | Female | Unknown | Total
        $this->assertSame(['Metric', 'Male', 'Female', 'Unknown', 'Total'], [
            $sheet->getCell('A4')->getValue(), $sheet->getCell('B4')->getValue(),
            $sheet->getCell('C4')->getValue(), $sheet->getCell('D4')->getValue(), $sheet->getCell('E4')->getValue(),
        ]);

        // row5=blank spacer, row6=dept name, row7=report date, row8=Scheduled.
        $this->assertSame('Scheduled', $sheet->getCell('A8')->getValue());
        $male = $sheet->getCell('B8')->getValue();
        $female = $sheet->getCell('C8')->getValue();
        $unknown = $sheet->getCell('D8')->getValue();
        $total = $sheet->getCell('E8')->getValue();
        $this->assertSame($total, $male + $female + $unknown);
        $this->assertSame(4, $total); // matches seedTwoDepartments()'s department A: 4 assignments

        unlink($path);
    }

    public function test_all_departments_gender_breakdown_sheet_reconciles(): void
    {
        [$session, $deptA, $deptB] = $this->seedTwoDepartments();
        $data = app(ReportService::class)->departmentReport($session->date->format('Y-m-d'), $session->date->format('Y-m-d'), $session->id);

        \Maatwebsite\Excel\Facades\Excel::store(new \App\Exports\DepartmentReportExport($data), 'test-all-dept-gender.xlsx', 'local');
        $path = storage_path('app/private/test-all-dept-gender.xlsx');

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Gender Breakdown');

        $this->assertSame(
            ['Department', 'Scheduled M', 'Scheduled F', 'Scheduled U', 'Present M', 'Present F', 'Present U', 'Absent M', 'Absent F', 'Absent U', 'Pending M', 'Pending F', 'Pending U'],
            array_map(fn ($c) => $sheet->getCell($c.'1')->getValue(), ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M'])
        );

        // Department Summary's compact table must not have gender columns anymore.
        $deptSummary = $spreadsheet->getSheetByName('Department Summary');
        $this->assertSame(
            ['Department', 'Scheduled', 'Present', 'Absent', 'Pending', 'Attendance Rate'],
            array_map(fn ($c) => $deptSummary->getCell($c.'1')->getValue(), ['A', 'B', 'C', 'D', 'E', 'F'])
        );

        unlink($path);
    }
}
