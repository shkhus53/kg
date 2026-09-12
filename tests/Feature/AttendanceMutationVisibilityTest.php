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
 * Audit-confirmed gap: attendance/live.blade.php and attendance/pending.blade.php
 * rendered Present/Absent/Extra-Present/correction controls unconditionally —
 * gated only on session-active state, never on the actor's actual permission.
 * Route middleware already blocked the POST server-side (not a security
 * bypass), but an unauthorized user still SAW actions they could not perform.
 * These tests prove the Blade-level fix: the control is present only when
 * the effective permission allows it, read-only information stays visible
 * either way, and the server-side rejection is unchanged.
 */
class AttendanceMutationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        return User::factory()->operator()->create();
    }

    private function viewer(): User
    {
        return User::factory()->viewer()->create();
    }

    private function activeSession(): DutySession
    {
        return DutySession::create(['name' => 'S', 'date' => now()->format('Y-m-d'), 'status' => 'active']);
    }

    private function department(string $name = 'Department A'): Department
    {
        return Department::firstOrCreate(['normalized_key' => Department::normalize($name)], ['name' => $name]);
    }

    private function assignment(DutySession $session, Department $dept, string $its, string $status = 'pending'): DutyAssignment
    {
        $admin = User::where('role', 'admin')->first() ?? User::factory()->admin()->create();
        $batch = ImportBatch::firstOrCreate(
            ['duty_session_id' => $session->id, 'original_filename' => 'f.csv'],
            ['uploaded_by' => $admin->id, 'file_type' => 'csv', 'status' => 'completed']
        );
        $kg = Khidmatguzar::firstOrCreate(['its_id' => $its], ['full_name' => 'Person '.$its]);

        return DutyAssignment::create([
            'duty_session_id' => $session->id, 'import_batch_id' => $batch->id, 'khidmatguzar_id' => $kg->id,
            'department_id' => $dept->id, 'source_row_number' => 1, 'assignment_fingerprint' => 'fp-'.uniqid(),
            'venue_name_raw' => $dept->name, 'full_name_snapshot' => $kg->full_name, 'current_status' => $status,
        ]);
    }

    // ===================================================================
    // Live Attendance — single pending match (mark_attendance)
    // ===================================================================

    public function test_operator_with_mark_attendance_sees_present_and_absent_controls(): void
    {
        $session = $this->activeSession();
        $dept = $this->department();
        $this->assignment($session, $dept, '30111111', 'pending');

        $response = $this->actingAs($this->operator())
            ->get(route('attendance.shell.live', [$session, 'its' => '30111111']));

        $response->assertOk();
        $response->assertSee(route('attendance.present', $session), false);
        $response->assertSee('Mark Present');
        $response->assertSee('Mark Absent');
    }

    public function test_viewer_without_mark_attendance_does_not_see_present_or_absent_controls(): void
    {
        $session = $this->activeSession();
        $dept = $this->department();
        $this->assignment($session, $dept, '30111112', 'pending');

        $response = $this->actingAs($this->viewer())
            ->get(route('attendance.shell.live', [$session, 'its' => '30111112']));

        // The page itself must still load — view_live_attendance is a
        // separate permission the viewer does have.
        $response->assertOk();
        $response->assertDontSee('Mark Present');
        $response->assertDontSee('Mark Absent');
        // Read-only information must remain visible.
        $response->assertSee('Person 30111112');
        $response->assertSee($dept->name);
    }

    public function test_viewer_direct_post_to_present_is_still_rejected_server_side(): void
    {
        $session = $this->activeSession();
        $dept = $this->department();
        $assignment = $this->assignment($session, $dept, '30111113', 'pending');

        $response = $this->actingAs($this->viewer())
            ->post(route('attendance.present', $session), ['assignment_ids' => [$assignment->id]]);

        $response->assertForbidden();
        $this->assertSame('pending', $assignment->fresh()->current_status);
    }

    // ===================================================================
    // Live Attendance — correction (Absent -> Present, correct_attendance)
    // ===================================================================

    public function test_operator_without_correct_attendance_does_not_see_the_correction_control(): void
    {
        $session = $this->activeSession();
        $dept = $this->department();
        $this->assignment($session, $dept, '30111114', 'absent');

        // Operator's role defaults include mark_attendance/mark_extra_present
        // but NOT correct_attendance (config/permissions.php role_defaults).
        $response = $this->actingAs($this->operator())
            ->get(route('attendance.shell.live', [$session, 'its' => '30111114']));

        $response->assertOk();
        $response->assertSee('Already marked Absent');
        $response->assertDontSee('Person arrived late? Correct this to Present.');
    }

    public function test_operator_with_correct_attendance_override_sees_the_correction_control(): void
    {
        $session = $this->activeSession();
        $dept = $this->department();
        $this->assignment($session, $dept, '30111115', 'absent');

        $operator = $this->operator();
        UserPermissionOverride::create([
            'user_id' => $operator->id, 'permission' => 'correct_attendance', 'effect' => 'allow',
            'created_by' => $operator->id, 'updated_by' => $operator->id,
        ]);

        $response = $this->actingAs($operator)
            ->get(route('attendance.shell.live', [$session, 'its' => '30111115']));

        $response->assertOk();
        $response->assertSee('Person arrived late? Correct this to Present.');
    }

    public function test_operator_direct_post_correction_without_permission_is_still_rejected_server_side(): void
    {
        $session = $this->activeSession();
        $dept = $this->department();
        $assignment = $this->assignment($session, $dept, '30111116', 'absent');

        $response = $this->actingAs($this->operator())
            ->post(route('attendance.present', $session), ['assignment_ids' => [$assignment->id]]);

        $response->assertForbidden();
        $this->assertSame('absent', $assignment->fresh()->current_status);
    }

    // ===================================================================
    // Live Attendance — Extra Present (mark_extra_present)
    // ===================================================================

    public function test_operator_with_mark_extra_present_sees_the_extra_present_form(): void
    {
        $session = $this->activeSession();
        $this->department();

        $response = $this->actingAs($this->operator())
            ->get(route('attendance.shell.live', [$session, 'its' => '30199999']));

        $response->assertOk();
        $response->assertSee('Not in Today\'s List');
        $response->assertSee('Mark Extra Present');
    }

    public function test_viewer_without_mark_extra_present_does_not_see_the_extra_present_form(): void
    {
        $session = $this->activeSession();
        $this->department();

        $response = $this->actingAs($this->viewer())
            ->get(route('attendance.shell.live', [$session, 'its' => '30199998']));

        $response->assertOk();
        $response->assertSee('Not in Today\'s List');
        $response->assertDontSee('Mark Extra Present');
    }

    public function test_viewer_direct_post_extra_present_is_still_rejected_server_side(): void
    {
        $session = $this->activeSession();
        $this->department();

        $response = $this->actingAs($this->viewer())->post(route('attendance.extra-present', $session), [
            'its' => '30199997', 'full_name' => 'X', 'gender' => 'Male', 'department_id' => $this->department()->id,
        ]);

        $response->assertForbidden();
    }

    // ===================================================================
    // Pending Attendance
    // ===================================================================

    public function test_operator_sees_present_and_absent_controls_on_pending_page(): void
    {
        $session = $this->activeSession();
        $dept = $this->department();
        $this->assignment($session, $dept, '30122221', 'pending');

        $response = $this->actingAs($this->operator())->get(route('attendance.shell.pending', $session));

        $response->assertOk();
        $response->assertSee('Person 30122221');
        $response->assertSee(route('attendance.present', $session), false);
        $response->assertSee(route('attendance.absent', $session), false);
    }

    public function test_viewer_does_not_see_present_or_absent_controls_on_pending_page_but_sees_the_list(): void
    {
        $session = $this->activeSession();
        $dept = $this->department();
        $this->assignment($session, $dept, '30122222', 'pending');

        $response = $this->actingAs($this->viewer())->get(route('attendance.shell.pending', $session));

        $response->assertOk();
        // Read-only info stays visible.
        $response->assertSee('Person 30122222');
        $response->assertSee($dept->name);
        // But no mutation forms.
        $response->assertDontSee(route('attendance.present', $session), false);
        $response->assertDontSee(route('attendance.absent', $session), false);
    }

    public function test_viewer_direct_post_absent_on_pending_is_still_rejected_server_side(): void
    {
        $session = $this->activeSession();
        $dept = $this->department();
        $assignment = $this->assignment($session, $dept, '30122223', 'pending');

        $response = $this->actingAs($this->viewer())
            ->post(route('attendance.absent', $session), ['assignment_id' => $assignment->id]);

        $response->assertForbidden();
        $this->assertSame('pending', $assignment->fresh()->current_status);
    }
}
