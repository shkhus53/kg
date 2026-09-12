<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\ExtraPresent;
use App\Models\ImportBatch;
use App\Models\Khidmatguzar;
use App\Models\KhidmatguzarChangeLog;
use App\Models\SessionReopenEvent;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\DutyListImportService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 0/1 of the KG Attendance 2.0 blueprint: assignment-fingerprint DB
 * uniqueness, Rule 3 (blank-preserving master data + audit log), Rule 4/5
 * (Gender mandatory on Extra Present), Rule 9 (scheduled/Extra Present
 * mutual exclusion under the new locking), and Rule 6 (admin-only reopen).
 */
class Phase1BusinessRulesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function operator(): User
    {
        return User::factory()->operator()->create();
    }

    private function activeSession(): DutySession
    {
        return DutySession::create(['name' => 'Test Session', 'date' => now()->format('Y-m-d'), 'status' => 'active']);
    }

    private function department(?string $name = null): Department
    {
        $name = $name ?? 'DEPT-'.uniqid();

        return Department::create(['name' => $name, 'normalized_key' => Department::normalize($name)]);
    }

    private function rowData(string $its, string $venue, array $overrides = []): array
    {
        return array_merge([
            'h_year' => '1448', 'miqaat' => 'Test', 'its_id' => $its, 'full_name' => 'Person '.$its,
            'gender' => 'Male', 'age' => '30', 'category' => 'Test', 'idara' => 'Original Idara', 'jamaat' => 'J', 'jamiaat' => 'JM',
            'venue_name' => $venue, 'block_name' => 'block', 'day' => 'day', 'day_alias' => 'alias', 'seat' => 'A1',
            'status' => 'Allocated', 'allocated_user_name' => 'sys', 'allocated_date' => '2026-01-01',
            'deallocated_user_name' => '', 'deallocated_date' => '', 'scanned' => 'N',
            'acc_child_below_5yrs' => '0', 'multiple_acc_child_above_4yrs' => '0',
        ], $overrides);
    }

    /** Fingerprint uniqueness is now enforced at the DB level, not just in-app. */
    public function test_assignment_fingerprint_is_unique_at_database_level(): void
    {
        $session = $this->activeSession();
        $user = $this->admin();
        $dept = $this->department();
        $kg = Khidmatguzar::create(['its_id' => '20000001', 'full_name' => 'Person']);
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $user->id, 'original_filename' => 'f.csv', 'file_type' => 'csv', 'status' => 'completed']);

        $attrs = [
            'duty_session_id' => $session->id, 'import_batch_id' => $batch->id, 'khidmatguzar_id' => $kg->id,
            'department_id' => $dept->id, 'source_row_number' => 2, 'assignment_fingerprint' => 'fp-unique-test',
            'venue_name_raw' => $dept->name, 'full_name_snapshot' => $kg->full_name,
        ];
        DutyAssignment::create($attrs);

        $this->expectException(QueryException::class);
        DutyAssignment::create($attrs);
    }

    /** Rule 3: a non-blank re-import value replaces master data, and the change is logged. */
    public function test_reimport_updates_only_non_blank_fields_and_logs_the_change(): void
    {
        $session = $this->activeSession();
        $user = $this->admin();
        $service = app(DutyListImportService::class);

        $rows1 = [['row_number' => 2, 'data' => $this->rowData('20000002', 'DEPT-A', ['idara' => 'Idara One', 'jamaat' => 'Jamaat One'])]];
        $preview1 = $service->buildPreview($session, $rows1);
        $service->commit($session, $preview1['valid'], $user, 'file1.csv', 'csv', $preview1);

        $kg = Khidmatguzar::where('its_id', '20000002')->firstOrFail();
        $this->assertSame('Idara One', $kg->idara);
        $this->assertSame('Jamaat One', $kg->jamaat);

        // Second file: Idara blank (must NOT overwrite), Jamaat changed (must overwrite + log).
        $rows2 = [['row_number' => 2, 'data' => $this->rowData('20000002', 'DEPT-B', ['idara' => '', 'jamaat' => 'Jamaat Two'])]];
        $preview2 = $service->buildPreview($session, $rows2);
        $service->commit($session, $preview2['valid'], $user, 'file2.csv', 'csv', $preview2);

        $kg->refresh();
        $this->assertSame('Idara One', $kg->idara, 'blank cell must not overwrite existing value');
        $this->assertSame('Jamaat Two', $kg->jamaat, 'non-blank cell must overwrite');

        $this->assertSame(1, KhidmatguzarChangeLog::where('khidmatguzar_id', $kg->id)->where('field', 'jamaat')->count());
        $this->assertSame(0, KhidmatguzarChangeLog::where('khidmatguzar_id', $kg->id)->where('field', 'idara')->count());

        $log = KhidmatguzarChangeLog::where('khidmatguzar_id', $kg->id)->where('field', 'jamaat')->firstOrFail();
        $this->assertSame('Jamaat One', $log->old_value);
        $this->assertSame('Jamaat Two', $log->new_value);
    }

    /** Rule 4/5: Extra Present requires ITS + Name + Gender + Department; Gender backfills a blank master record. */
    public function test_extra_present_requires_gender_and_route_validation_enforces_it(): void
    {
        $session = $this->activeSession();
        $user = $this->operator();
        $dept = $this->department();
        DutyAssignment::create([
            'duty_session_id' => $session->id, 'import_batch_id' => ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $user->id, 'original_filename' => 'f.csv', 'file_type' => 'csv', 'status' => 'completed'])->id,
            'khidmatguzar_id' => Khidmatguzar::create(['its_id' => '20000010', 'full_name' => 'Scope Person'])->id,
            'department_id' => $dept->id, 'source_row_number' => 2, 'assignment_fingerprint' => 'fp-scope-gender',
            'venue_name_raw' => $dept->name, 'full_name_snapshot' => 'Scope Person',
        ]);

        // Missing gender is rejected by validation.
        $this->actingAs($user)->post(route('attendance.extra-present', $session), [
            'its' => '20000011', 'full_name' => 'New Person', 'department_id' => $dept->id,
        ])->assertSessionHasErrors('gender');

        // With gender, a new Khidmatguzar is created with it set.
        $this->actingAs($user)->post(route('attendance.extra-present', $session), [
            'its' => '20000011', 'full_name' => 'New Person', 'gender' => 'Female', 'department_id' => $dept->id,
        ])->assertRedirect();

        $this->assertSame('Female', Khidmatguzar::where('its_id', '20000011')->firstOrFail()->gender);
    }

    /** Rule 9: a scheduled assignment blocks Extra Present for the same person+session. */
    public function test_scheduled_assignment_blocks_extra_present_for_same_person(): void
    {
        $session = $this->activeSession();
        $user = $this->admin();
        $dept = $this->department();
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $user->id, 'original_filename' => 'f.csv', 'file_type' => 'csv', 'status' => 'completed']);
        $kg = Khidmatguzar::create(['its_id' => '20000020', 'full_name' => 'Already Scheduled']);
        DutyAssignment::create([
            'duty_session_id' => $session->id, 'import_batch_id' => $batch->id, 'khidmatguzar_id' => $kg->id,
            'department_id' => $dept->id, 'source_row_number' => 2, 'assignment_fingerprint' => 'fp-rule9',
            'venue_name_raw' => $dept->name, 'full_name_snapshot' => $kg->full_name,
        ]);

        $result = app(AttendanceService::class)->markExtraPresentKnown($session, $kg, $dept, 'Male', $user);

        $this->assertSame('now_scheduled', $result['result']);
        $this->assertSame(0, ExtraPresent::where('khidmatguzar_id', $kg->id)->count());
    }

    /** Rule 6: only Admin can reopen a Closed session; reason is mandatory and audited; reopen does not reset to draft/pending. */
    public function test_admin_only_reopen_workflow_is_audited_and_does_not_reset_status(): void
    {
        $admin = $this->admin();
        $operator = $this->operator();
        $session = DutySession::create(['name' => 'Closed Session', 'date' => now()->format('Y-m-d'), 'status' => 'closed', 'closed_at' => now(), 'closed_by' => $admin->id]);

        // Operator forbidden.
        $this->actingAs($operator)->post(route('sessions.reopen', $session), ['reason' => 'other', 'detail' => 'x'])->assertForbidden();

        // "Other" without detail is rejected.
        $this->actingAs($admin)->post(route('sessions.reopen', $session), ['reason' => 'other'])->assertSessionHasErrors('detail');

        // Valid reopen.
        $this->actingAs($admin)->post(route('sessions.reopen', $session), [
            'reason' => 'incorrect_attendance_marked',
        ])->assertRedirect(route('sessions.show', $session));

        $session->refresh();
        $this->assertSame('active', $session->status, 'reopen must not reset to draft/pending');
        $this->assertTrue($session->is_reopened_for_correction);
        $this->assertSame($admin->id, $session->reopened_by);

        $event = SessionReopenEvent::where('duty_session_id', $session->id)->firstOrFail();
        $this->assertSame('incorrect_attendance_marked', $event->reason);
        $this->assertNull($event->closed_at);

        // Closing again clears the flag and stamps the reopen event's closed_at.
        app(AttendanceService::class)->closeSession($session, $admin);
        $session->refresh();
        $this->assertSame('closed', $session->status);
        $this->assertFalse($session->is_reopened_for_correction);
        $this->assertNotNull($event->fresh()->closed_at);
    }

    /**
     * Session lifecycle (activate_sessions) became Admin-only by default
     * under the granular permission system — an Operator no longer
     * activates sessions by default, only an Admin (or an Operator an
     * Admin explicitly grants activate_sessions to).
     */
    public function test_activate_sessions_permission_defaults_to_admin_only(): void
    {
        $viewer = User::factory()->viewer()->create();
        $operator = $this->operator();
        $admin = User::factory()->admin()->create();
        $draft = DutySession::create(['name' => 'D', 'date' => now()->format('Y-m-d'), 'status' => 'draft']);

        $this->actingAs($viewer)->post(route('sessions.activate', $draft))->assertForbidden();
        $this->actingAs($operator)->post(route('sessions.activate', $draft))->assertForbidden();
        $this->actingAs($admin)->post(route('sessions.activate', $draft))->assertRedirect();
    }
}
