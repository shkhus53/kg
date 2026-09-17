<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\ImportBatch;
use App\Models\Khidmatguzar;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Report Builder bulk Present/Absent + gender filter.
 *
 * `build_reports` (see the page) and `mark_attendance` (see the bulk
 * actions) are deliberately separate permissions — a user can view the
 * report without being allowed to mutate attendance from it.
 */
class ReportBuilderBulkAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function department(): Department
    {
        $name = 'DEPT-'.uniqid();

        return Department::create(['name' => $name, 'normalized_key' => Department::normalize($name)]);
    }

    private function dutySession(string $status = 'active'): DutySession
    {
        return DutySession::create(['name' => 'S-'.uniqid(), 'date' => now()->format('Y-m-d'), 'status' => $status]);
    }

    private function assignment(DutySession $session, Department $dept, string $status = 'pending', ?string $gender = null): DutyAssignment
    {
        $batch = ImportBatch::create([
            'duty_session_id' => $session->id, 'uploaded_by' => User::factory()->admin()->create()->id, 'original_filename' => 'f.csv',
            'file_type' => 'csv', 'status' => 'completed',
        ]);
        $kg = Khidmatguzar::create(['its_id' => (string) random_int(10000000, 99999999), 'full_name' => 'Person', 'gender' => $gender]);

        return DutyAssignment::create([
            'duty_session_id' => $session->id, 'import_batch_id' => $batch->id, 'khidmatguzar_id' => $kg->id,
            'department_id' => $dept->id, 'source_row_number' => 2, 'assignment_fingerprint' => 'fp-'.uniqid(),
            'venue_name_raw' => $dept->name, 'full_name_snapshot' => $kg->full_name, 'current_status' => $status,
        ]);
    }

    public function test_gender_filter_narrows_results(): void
    {
        $admin = $this->admin();
        $session = $this->dutySession();
        $dept = $this->department();

        $this->assignment($session, $dept, 'pending', 'M');
        $this->assignment($session, $dept, 'pending', 'Female');

        $response = $this->actingAs($admin)->get(route('reports.builder', ['gender' => 'Male']));

        $response->assertOk();
        $response->assertSee('1', false);
    }

    public function test_bulk_mark_present_updates_pending_assignments(): void
    {
        $admin = $this->admin();
        $session = $this->dutySession();
        $dept = $this->department();

        $a1 = $this->assignment($session, $dept, 'pending');
        $a2 = $this->assignment($session, $dept, 'pending');

        $response = $this->actingAs($admin)->post(route('reports.builder.mark-present'), [
            'assignment_ids' => [$a1->id, $a2->id],
        ]);

        $response->assertRedirect();
        $this->assertSame('present', $a1->fresh()->current_status);
        $this->assertSame('present', $a2->fresh()->current_status);
    }

    public function test_bulk_mark_absent_skips_present_rows(): void
    {
        $admin = $this->admin();
        $session = $this->dutySession();
        $dept = $this->department();

        $pending = $this->assignment($session, $dept, 'pending');
        $present = $this->assignment($session, $dept, 'present');

        $this->actingAs($admin)->post(route('reports.builder.mark-absent'), [
            'assignment_ids' => [$pending->id, $present->id],
        ]);

        $this->assertSame('absent', $pending->fresh()->current_status);
        $this->assertSame('present', $present->fresh()->current_status, 'Present -> Absent must never happen, even for an admin.');
    }

    public function test_bulk_actions_skip_closed_sessions(): void
    {
        $admin = $this->admin();
        $closed = $this->dutySession('closed');
        $dept = $this->department();

        $a = $this->assignment($closed, $dept, 'pending');

        $this->actingAs($admin)->post(route('reports.builder.mark-present'), [
            'assignment_ids' => [$a->id],
        ]);

        $this->assertSame('pending', $a->fresh()->current_status, 'Closed sessions must stay read-only.');
    }

    public function test_mark_attendance_permission_required_independent_of_build_reports(): void
    {
        // Operator: has mark_attendance by role default, but not build_reports.
        $operator = User::factory()->operator()->create();
        $session = $this->dutySession();
        $dept = $this->department();
        $a = $this->assignment($session, $dept, 'pending');

        $this->actingAs($operator)->post(route('reports.builder.mark-present'), [
            'assignment_ids' => [$a->id],
        ])->assertForbidden();

        // Grant build_reports without mark_attendance: can view, still can't mutate.
        $viewerOnly = User::factory()->operator()->create();
        UserPermissionOverride::create(['user_id' => $viewerOnly->id, 'permission' => 'build_reports', 'effect' => 'allow']);
        UserPermissionOverride::create(['user_id' => $viewerOnly->id, 'permission' => 'mark_attendance', 'effect' => 'deny']);

        $this->actingAs($viewerOnly)->get(route('reports.builder'))->assertOk();
        $this->actingAs($viewerOnly)->post(route('reports.builder.mark-present'), [
            'assignment_ids' => [$a->id],
        ])->assertForbidden();
    }

    public function test_absent_to_present_correction_requires_permission(): void
    {
        $session = $this->dutySession();
        $dept = $this->department();
        $absentRow = $this->assignment($session, $dept, 'absent');

        // Operator has mark_attendance + build_reports override but not correct_attendance.
        $operator = User::factory()->operator()->create();
        UserPermissionOverride::create(['user_id' => $operator->id, 'permission' => 'build_reports', 'effect' => 'allow']);

        $this->actingAs($operator)->post(route('reports.builder.mark-present'), [
            'assignment_ids' => [$absentRow->id],
        ])->assertForbidden();

        $this->assertSame('absent', $absentRow->fresh()->current_status);

        $admin = $this->admin();
        $this->actingAs($admin)->post(route('reports.builder.mark-present'), [
            'assignment_ids' => [$absentRow->id],
        ]);

        $this->assertSame('present', $absentRow->fresh()->current_status);
    }
}
