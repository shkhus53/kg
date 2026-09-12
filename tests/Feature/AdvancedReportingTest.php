<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\ImportBatch;
use App\Models\Khidmatguzar;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 8 (Advanced Reporting): Operator Activity report — PDF/Excel export
 * built from ReportService::operatorActivityReport(), the same method the
 * on-screen Operator Analytics page (Phase 6) now also calls, so the two
 * can never disagree.
 */
class AdvancedReportingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function activeSession(): DutySession
    {
        return DutySession::create(['name' => 'S', 'date' => now()->format('Y-m-d'), 'status' => 'active']);
    }

    private function department(): Department
    {
        $name = 'DEPT-'.uniqid();

        return Department::create(['name' => $name, 'normalized_key' => Department::normalize($name)]);
    }

    private function assignment(DutySession $session, ImportBatch $batch, Department $dept): DutyAssignment
    {
        $kg = Khidmatguzar::create(['its_id' => (string) random_int(10000000, 99999999), 'full_name' => 'Person']);

        return DutyAssignment::create([
            'duty_session_id' => $session->id, 'import_batch_id' => $batch->id, 'khidmatguzar_id' => $kg->id,
            'department_id' => $dept->id, 'source_row_number' => 2, 'assignment_fingerprint' => 'fp-'.uniqid(),
            'venue_name_raw' => $dept->name, 'full_name_snapshot' => $kg->full_name,
        ]);
    }

    public function test_report_service_and_onscreen_view_agree(): void
    {
        $admin = $this->admin();
        $operator = User::factory()->operator()->create(['name' => 'Shared Source Operator']);
        $session = $this->activeSession();
        $dept = $this->department();
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $admin->id, 'original_filename' => 'f.csv', 'file_type' => 'csv', 'status' => 'completed']);
        $assignment = $this->assignment($session, $batch, $dept);
        app(AttendanceService::class)->markPresent($session, $assignment->id, $operator);

        $from = now()->subDays(30)->format('Y-m-d');
        $to = now()->format('Y-m-d');

        $direct = app(ReportService::class)->operatorActivityReport($from, $to);
        $viaController = $this->actingAs($admin)->get(route('analytics.operators', ['from' => $from, 'to' => $to]))->viewData('operators');

        $this->assertSame(
            $direct['operators']->firstWhere('user.name', 'Shared Source Operator')['total_actions'],
            $viaController->firstWhere('user.name', 'Shared Source Operator')['total_actions'],
        );
    }

    public function test_only_admin_can_export_operator_activity_report(): void
    {
        $this->actingAs($this->admin())->get(route('reports.operators.pdf'))->assertOk();
        $this->actingAs(User::factory()->operator()->create())->get(route('reports.operators.pdf'))->assertForbidden();
        $this->actingAs(User::factory()->viewer()->create())->get(route('reports.operators.excel'))->assertForbidden();
    }

    public function test_pdf_export_succeeds_and_excel_export_succeeds(): void
    {
        $admin = $this->admin();
        $operator = User::factory()->operator()->create(['name' => 'Export Test Operator']);
        $session = $this->activeSession();
        $dept = $this->department();
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $admin->id, 'original_filename' => 'f.csv', 'file_type' => 'csv', 'status' => 'completed']);
        $assignment = $this->assignment($session, $batch, $dept);
        app(AttendanceService::class)->markPresent($session, $assignment->id, $operator);

        $this->actingAs($admin)->get(route('reports.operators.pdf'))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->actingAs($admin)->get(route('reports.operators.excel'))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_pdf_export_handles_empty_period(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('reports.operators.pdf', ['from' => '2000-01-01', 'to' => '2000-01-02']))
            ->assertOk();
    }
}
