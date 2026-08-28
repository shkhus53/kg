<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\ImportBatch;
use App\Models\Khidmatguzar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the report-design pass (professional PDF/Excel styling): both must
 * keep generating without error, for all three report types, including the
 * empty-data edge cases the styling code (AfterSheet event, page setup)
 * touches directly.
 */
class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    private function seedSession(): DutySession
    {
        $user = User::factory()->admin()->create();
        $session = DutySession::create(['name' => 'Export Test Session', 'date' => now()->format('Y-m-d'), 'status' => 'active']);
        $dept = Department::create(['name' => 'EXPORTDEPT', 'normalized_key' => Department::normalize('EXPORTDEPT')]);
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $user->id, 'original_filename' => 'x.csv', 'file_type' => 'csv', 'status' => 'completed']);
        $kg = Khidmatguzar::create(['its_id' => '88888888', 'full_name' => 'Export Person', 'gender' => 'M']);
        DutyAssignment::create([
            'duty_session_id' => $session->id, 'import_batch_id' => $batch->id,
            'khidmatguzar_id' => $kg->id, 'department_id' => $dept->id,
            'source_row_number' => 1, 'assignment_fingerprint' => hash('sha256', uniqid()),
            'venue_name_raw' => $dept->name, 'full_name_snapshot' => $kg->full_name,
            'gender_snapshot' => 'M', 'current_status' => 'present',
        ]);

        return $session;
    }

    public function test_session_pdf_and_excel_generate(): void
    {
        $user = User::factory()->admin()->create();
        $session = $this->seedSession();

        $this->actingAs($user)->get(route('reports.session.pdf', $session))->assertOk();
        $this->actingAs($user)->get(route('reports.session.excel', $session))->assertOk();
    }

    public function test_department_pdf_and_excel_generate_including_empty_scope(): void
    {
        $user = User::factory()->admin()->create();
        $this->seedSession();

        $this->actingAs($user)->get(route('reports.department.pdf'))->assertOk();
        $this->actingAs($user)->get(route('reports.department.excel'))->assertOk();

        // Empty date range: department rows collection is empty — the
        // AfterSheet styling code must not choke on a header-only sheet.
        $this->actingAs($user)
            ->get(route('reports.department.pdf', ['from' => '2000-01-01', 'to' => '2000-01-02']))
            ->assertOk();
    }

    public function test_khidmatguzar_pdf_and_excel_generate(): void
    {
        $user = User::factory()->admin()->create();
        $session = $this->seedSession();
        $kg = $session->dutyAssignments()->first()->khidmatguzar;

        $this->actingAs($user)->get(route('reports.khidmatguzar.pdf', $kg))->assertOk();
        $this->actingAs($user)->get(route('reports.khidmatguzar.excel', $kg))->assertOk();
    }
}
