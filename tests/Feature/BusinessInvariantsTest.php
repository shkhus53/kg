<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\ExtraPresent;
use App\Models\ImportBatch;
use App\Models\Khidmatguzar;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\DutyListImportService;
use App\Services\ReportService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 7.5 automated foundation for the critical business invariants that,
 * until now, were only proven manually per-phase. Uses isolated sqlite
 * in-memory test data — never the accumulated development database.
 */
class BusinessInvariantsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function viewer(): User
    {
        return User::factory()->viewer()->create();
    }

    private function activeSession(): DutySession
    {
        return DutySession::create([
            'name' => 'Test Session', 'date' => now()->format('Y-m-d'), 'status' => 'active',
        ]);
    }

    private function department(?string $name = null): Department
    {
        $name = $name ?? 'DEPT-'.uniqid();

        return Department::create(['name' => $name, 'normalized_key' => Department::normalize($name)]);
    }

    private function khidmatguzar(string $its): Khidmatguzar
    {
        return Khidmatguzar::create(['its_id' => $its, 'full_name' => 'Test Person '.$its]);
    }

    private function batch(DutySession $session, User $user): ImportBatch
    {
        return ImportBatch::create([
            'duty_session_id' => $session->id, 'uploaded_by' => $user->id,
            'original_filename' => 'test.csv', 'file_type' => 'csv', 'status' => 'completed',
        ]);
    }

    private function assignment(DutySession $session, ImportBatch $batch, Khidmatguzar $kg, Department $dept, array $overrides = []): DutyAssignment
    {
        return DutyAssignment::create(array_merge([
            'duty_session_id' => $session->id,
            'import_batch_id' => $batch->id,
            'khidmatguzar_id' => $kg->id,
            'department_id' => $dept->id,
            'source_row_number' => 2,
            'assignment_fingerprint' => hash('sha256', $kg->its_id.'|'.$dept->normalized_key.'|block|day|alias|A1'),
            'venue_name_raw' => $dept->name,
            'full_name_snapshot' => $kg->full_name,
            'block_name' => 'Block', 'day' => 'Day', 'day_alias' => 'Alias', 'seat' => 'A1',
        ], $overrides));
    }

    /** 1. Import duplicate protection (within-file). */
    public function test_import_rejects_within_file_duplicate_row(): void
    {
        $session = $this->activeSession();
        $rows = [
            ['row_number' => 2, 'data' => $this->rowData('11110001', 'SECURITY')],
            ['row_number' => 3, 'data' => $this->rowData('11110001', 'SECURITY')], // exact duplicate
        ];

        $preview = app(DutyListImportService::class)->buildPreview($session, $rows);

        $this->assertSame(1, $preview['valid_rows']);
        $this->assertCount(1, $preview['exact_duplicate_rows']);
    }

    /** 2. Cross-batch duplicate protection. */
    public function test_import_rejects_cross_batch_duplicate(): void
    {
        $session = $this->activeSession();
        $user = $this->admin();
        $dept = $this->department();
        $kg = $this->khidmatguzar('11110002');
        $batch = $this->batch($session, $user);
        $importService = app(DutyListImportService::class);
        $rowData = array_merge($this->rowData('11110002', 'SECURITY'), [
            'block_name' => 'blk', 'day' => 'd', 'day_alias' => 'da', 'seat' => 'A1',
        ]);
        $this->assignment($session, $batch, $kg, $dept, [
            'assignment_fingerprint' => $importService->fingerprint($rowData),
        ]);

        $rows = [['row_number' => 2, 'data' => $rowData]];

        $preview = $importService->buildPreview($session, $rows);

        $this->assertSame(0, $preview['valid_rows']);
        $this->assertCount(1, $preview['cross_batch_duplicate_rows']);
    }

    /** 3. Multiple legitimate assignments for the same ITS are never collapsed. */
    public function test_same_its_different_department_creates_two_assignments(): void
    {
        $session = $this->activeSession();
        $user = $this->admin();
        $dept1 = $this->department('SECURITY');
        $dept2 = $this->department('PARKING');
        $kg = $this->khidmatguzar('11110003');
        $batch = $this->batch($session, $user);

        $this->assignment($session, $batch, $kg, $dept1, ['assignment_fingerprint' => 'fp-a']);
        $this->assignment($session, $batch, $kg, $dept2, ['assignment_fingerprint' => 'fp-b']);

        $this->assertSame(2, DutyAssignment::where('khidmatguzar_id', $kg->id)->count());
    }

    /** 4. Pending -> Present transition. */
    public function test_mark_present_transitions_pending_to_present(): void
    {
        [$session, $assignment, $user] = $this->pendingAssignment();

        $result = app(AttendanceService::class)->markPresent($session, $assignment->id, $user);

        $this->assertSame('marked', $result['result']);
        $this->assertSame('present', $assignment->fresh()->current_status);
        $this->assertNotNull($assignment->fresh()->attendance_marked_at);
        $this->assertSame(1, \App\Models\AttendanceEvent::where('duty_assignment_id', $assignment->id)->count());
    }

    /** 5. Pending -> Absent transition, and Present cannot be re-marked Absent. */
    public function test_mark_absent_transitions_pending_and_blocks_reversal(): void
    {
        [$session, $assignment, $user] = $this->pendingAssignment();
        $service = app(AttendanceService::class);

        $result = $service->markAbsent($session, $assignment->id, $user);
        $this->assertSame('marked', $result['result']);
        $this->assertSame('absent', $assignment->fresh()->current_status);

        // present -> absent must be blocked (locked business rule)
        [$session2, $assignment2, $user2] = $this->pendingAssignment();
        $service->markPresent($session2, $assignment2->id, $user2);
        $blocked = $service->markAbsent($session2, $assignment2->id, $user2);
        $this->assertSame('already_present', $blocked['result']);
        $this->assertSame('present', $assignment2->fresh()->current_status);
    }

    /** 6. Extra Present uniqueness — no duplicate per session+person. */
    public function test_extra_present_is_unique_per_session_and_person(): void
    {
        $session = $this->activeSession();
        $user = $this->admin();
        $dept = $this->department();
        $kg = $this->khidmatguzar('11110004');
        $this->batch($session, $user); // gives the session at least one department in scope
        $this->assignment($session, $this->batch($session, $user), $this->khidmatguzar('scope'), $dept, ['assignment_fingerprint' => 'fp-scope']);

        $service = app(AttendanceService::class);
        $first = $service->markExtraPresentKnown($session, $kg, $dept, $user);
        $second = $service->markExtraPresentKnown($session, $kg, $dept, $user);

        $this->assertSame('marked', $first['result']);
        $this->assertSame('already_extra', $second['result']);
        $this->assertSame(1, ExtraPresent::where('duty_session_id', $session->id)->where('khidmatguzar_id', $kg->id)->count());
    }

    /** 7. Unknown ITS creates exactly one Khidmatguzar + one Extra Present, no Duty Assignment. */
    public function test_unknown_its_creates_khidmatguzar_and_extra_present_only(): void
    {
        $session = $this->activeSession();
        $user = $this->admin();
        $dept = $this->department();
        $this->assignment($session, $this->batch($session, $user), $this->khidmatguzar('scope2'), $dept, ['assignment_fingerprint' => 'fp-scope2']);

        $result = app(AttendanceService::class)->markExtraPresentNew($session, '99990001', 'Brand New Person', $dept, $user);

        $this->assertSame('marked', $result['result']);
        $this->assertSame(1, Khidmatguzar::where('its_id', '99990001')->count());
        $this->assertSame(1, ExtraPresent::where('its_id_snapshot', '99990001')->count());
        $this->assertSame(0, DutyAssignment::whereHas('khidmatguzar', fn ($q) => $q->where('its_id', '99990001'))->count());
    }

    /** 8. Viewer cannot mutate — activation, present, absent, extra-present, close all forbidden. */
    public function test_viewer_cannot_mutate_attendance(): void
    {
        $viewer = $this->viewer();
        $session = DutySession::create(['name' => 'Draft', 'date' => now()->format('Y-m-d'), 'status' => 'draft']);

        $this->actingAs($viewer)->post(route('sessions.activate', $session))->assertForbidden();
        $this->actingAs($viewer)->post(route('attendance.present', $session), ['assignment_ids' => [1]])->assertForbidden();
        $this->actingAs($viewer)->post(route('attendance.absent', $session), ['assignment_id' => 1])->assertForbidden();
        $this->actingAs($viewer)->post(route('attendance.extra-present', $session), ['its' => '1', 'department_id' => 1])->assertForbidden();
        $this->actingAs($viewer)->post(route('sessions.close', $session))->assertForbidden();
    }

    /** 9. Closed session blocks every attendance mutation, leaving state untouched. */
    public function test_closed_session_blocks_mutation(): void
    {
        [$session, $assignment, $user] = $this->pendingAssignment();
        $session->update(['status' => 'closed']);

        $result = app(AttendanceService::class)->markPresent($session, $assignment->id, $user);

        $this->assertSame('session_not_active', $result['result']);
        $this->assertSame('pending', $assignment->fresh()->current_status);
    }

    /** 10. Bulk Absent marks every Pending assignment, one event each, present untouched, idempotent. */
    public function test_bulk_absent_marks_all_pending_once_and_is_idempotent(): void
    {
        $session = $this->activeSession();
        $user = $this->admin();
        $dept = $this->department();
        $batch = $this->batch($session, $user);

        $present = $this->assignment($session, $batch, $this->khidmatguzar('11110010'), $dept, ['assignment_fingerprint' => 'fp1']);
        $pending1 = $this->assignment($session, $batch, $this->khidmatguzar('11110011'), $dept, ['assignment_fingerprint' => 'fp2']);
        $pending2 = $this->assignment($session, $batch, $this->khidmatguzar('11110012'), $dept, ['assignment_fingerprint' => 'fp3']);

        $service = app(AttendanceService::class);
        $service->markPresent($session, $present->id, $user);

        $outcome = $service->markAllRemainingAbsent($session, $user);
        $this->assertSame('marked', $outcome['result']);
        $this->assertSame(2, $outcome['count']);
        $this->assertSame('present', $present->fresh()->current_status);
        $this->assertSame('absent', $pending1->fresh()->current_status);
        $this->assertSame('absent', $pending2->fresh()->current_status);

        // idempotent repeat
        $again = $service->markAllRemainingAbsent($session, $user);
        $this->assertSame('nothing_pending', $again['result']);
        $this->assertSame(1, \App\Models\AttendanceEvent::where('duty_assignment_id', $pending1->id)->count());
    }

    /** 11. Close rejected while Pending > 0; session remains active. */
    public function test_close_rejected_with_pending_remaining(): void
    {
        [$session, $assignment, $user] = $this->pendingAssignment();

        $result = app(AttendanceService::class)->closeSession($session, $user);

        $this->assertSame('pending_remain', $result['result']);
        $this->assertSame('active', $session->fresh()->status);
        $this->assertNull($session->fresh()->closed_at);
    }

    /** 12. Successful close invariant: Pending=0, Scheduled=Present+Absent. */
    public function test_successful_close_invariant(): void
    {
        $session = $this->activeSession();
        $user = $this->admin();
        $dept = $this->department();
        $batch = $this->batch($session, $user);
        $a1 = $this->assignment($session, $batch, $this->khidmatguzar('11110020'), $dept, ['assignment_fingerprint' => 'fp10']);
        $a2 = $this->assignment($session, $batch, $this->khidmatguzar('11110021'), $dept, ['assignment_fingerprint' => 'fp11']);

        $service = app(AttendanceService::class);
        $service->markPresent($session, $a1->id, $user);
        $service->markAbsent($session, $a2->id, $user);

        $result = $service->closeSession($session, $user);

        $this->assertSame('closed', $result['result']);
        $session->refresh();
        $this->assertSame('closed', $session->status);
        $this->assertNotNull($session->closed_at);
        $this->assertSame($user->id, $session->closed_by);
        $this->assertSame(0, DutyAssignment::where('duty_session_id', $session->id)->where('current_status', 'pending')->count());
    }

    /** 13. Report counting matches DB exactly and never uses attendance_events or import rows as the unit. */
    public function test_report_counts_use_duty_assignments_not_events_or_batches(): void
    {
        $session = $this->activeSession();
        $user = $this->admin();
        $dept = $this->department();
        $batch = $this->batch($session, $user);
        $a1 = $this->assignment($session, $batch, $this->khidmatguzar('11110030'), $dept, ['assignment_fingerprint' => 'fp20']);
        $a2 = $this->assignment($session, $batch, $this->khidmatguzar('11110031'), $dept, ['assignment_fingerprint' => 'fp21']);

        $service = app(AttendanceService::class);
        // Mutate the same assignment twice (idempotent) to prove events != duties.
        $service->markPresent($session, $a1->id, $user);
        $service->markPresent($session, $a1->id, $user);
        $service->markAbsent($session, $a2->id, $user);

        $report = app(ReportService::class)->sessionReport($session);

        $this->assertSame(2, $report['scheduled']);
        $this->assertSame(1, $report['present']);
        $this->assertSame(1, $report['absent']);
        $this->assertSame(1, \App\Models\AttendanceEvent::where('duty_assignment_id', $a1->id)->count());
    }

    /** 14. A failed transaction leaves no partial state (mirrors the manual Phase 2-5 rollback tests). */
    public function test_failed_transaction_leaves_no_partial_state(): void
    {
        $session = $this->activeSession();
        $user = $this->admin();
        $dept = $this->department();

        $threw = false;
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($dept) {
                Khidmatguzar::create(['its_id' => '11110040', 'full_name' => 'Partial Person']);
                // Force a real DB error inside the same transaction.
                \Illuminate\Support\Facades\DB::table('extra_presents')->insert([
                    'duty_session_id' => 999999, // FK violation: session does not exist
                    'khidmatguzar_id' => 1,
                    'its_id_snapshot' => '11110040',
                    'full_name_snapshot' => 'Partial Person',
                    'department_id' => $dept->id,
                    'department_name_snapshot' => $dept->name,
                    'marked_by' => 1,
                    'marked_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } catch (QueryException $e) {
            $threw = true;
        }

        $this->assertTrue($threw);
        $this->assertSame(0, Khidmatguzar::where('its_id', '11110040')->count());
        $this->assertSame(0, ExtraPresent::where('its_id_snapshot', '11110040')->count());
    }

    // --- helpers ---

    private function rowData(string $its, string $venue): array
    {
        return [
            'h_year' => '1448', 'miqaat' => 'Test', 'its_id' => $its, 'full_name' => 'Person '.$its,
            'gender' => 'Male', 'age' => '30', 'category' => 'Test', 'idara' => 'I', 'jamaat' => 'J', 'jamiaat' => 'JM',
            'venue_name' => $venue, 'block_name' => 'block', 'day' => 'day', 'day_alias' => 'alias', 'seat' => 'A1',
            'status' => 'Allocated', 'allocated_user_name' => 'sys', 'allocated_date' => '2026-01-01',
            'deallocated_user_name' => '', 'deallocated_date' => '', 'scanned' => 'N',
            'acc_child_below_5yrs' => '0', 'multiple_acc_child_above_4yrs' => '0',
        ];
    }

    /** @return array{0: DutySession, 1: DutyAssignment, 2: User} */
    private function pendingAssignment(): array
    {
        $session = $this->activeSession();
        $user = $this->admin();
        $dept = $this->department();
        $kg = $this->khidmatguzar('1'.random_int(1000000, 9999999));
        $batch = $this->batch($session, $user);
        $assignment = $this->assignment($session, $batch, $kg, $dept, ['assignment_fingerprint' => 'fp-'.uniqid()]);

        return [$session, $assignment, $user];
    }
}
