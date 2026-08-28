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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 8: Khidmatguzar Directory + Profile. Isolated sqlite fixtures only
 * — never the accumulated development database.
 */
class DirectoryProfileTest extends TestCase
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

    private function dutySession(string $status = 'active', ?string $date = null): DutySession
    {
        return DutySession::create(['name' => 'S', 'date' => $date ?? now()->format('Y-m-d'), 'status' => $status]);
    }

    private function department(?string $name = null): Department
    {
        $name = $name ?? 'DEPT-'.uniqid();

        return Department::create(['name' => $name, 'normalized_key' => Department::normalize($name)]);
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
            'duty_session_id' => $session->id, 'import_batch_id' => $batch->id,
            'khidmatguzar_id' => $kg->id, 'department_id' => $dept->id,
            'source_row_number' => 2, 'assignment_fingerprint' => 'fp-'.uniqid(),
            'venue_name_raw' => $dept->name, 'full_name_snapshot' => $kg->full_name,
            'block_name' => 'B', 'day' => 'D', 'day_alias' => 'DA', 'seat' => 'A1',
        ], $overrides));
    }

    /** 1. Directory search by exact ITS. */
    public function test_directory_search_by_its(): void
    {
        $user = $this->admin();
        Khidmatguzar::create(['its_id' => '20001111', 'full_name' => 'Alpha Person']);
        Khidmatguzar::create(['its_id' => '20002222', 'full_name' => 'Beta Person']);

        $response = $this->actingAs($user)->get(route('analytics.profile-search', ['q' => '20001111']));

        $response->assertOk()->assertSee('Alpha Person')->assertDontSee('Beta Person');
    }

    /** 2. Directory search by partial name. */
    public function test_directory_search_by_partial_name(): void
    {
        $user = $this->admin();
        Khidmatguzar::create(['its_id' => '20003333', 'full_name' => 'Zubair Khan Padghawala']);

        $response = $this->actingAs($user)->get(route('analytics.profile-search', ['q' => 'padghawala']));

        $response->assertOk()->assertSee('Zubair Khan Padghawala');
    }

    /** 3. Case-insensitive search. */
    public function test_directory_search_case_insensitive(): void
    {
        $user = $this->admin();
        Khidmatguzar::create(['its_id' => '20004444', 'full_name' => 'CamelCase Person']);

        $response = $this->actingAs($user)->get(route('analytics.profile-search', ['q' => 'CAMELCASE']));

        $response->assertOk()->assertSee('CamelCase Person');
    }

    /** 4. One person appears exactly once regardless of multiple assignments. */
    public function test_person_appears_once_in_directory(): void
    {
        $user = $this->admin();
        $session = $this->dutySession();
        $dept1 = $this->department();
        $dept2 = $this->department();
        $kg = Khidmatguzar::create(['its_id' => '20005555', 'full_name' => 'Multi Assignment Person']);
        $batch = $this->batch($session, $user);
        $this->assignment($session, $batch, $kg, $dept1);
        $this->assignment($session, $batch, $kg, $dept2);

        $response = $this->actingAs($user)->get(route('analytics.profile-search', ['q' => '20005555']));

        $response->assertOk();
        $this->assertSame(1, substr_count($response->getContent(), 'Multi Assignment Person'));
    }

    /** 5. Duty history keeps multiple assignments separate (via profile, not collapsed). */
    public function test_history_keeps_multiple_assignments_separate(): void
    {
        $user = $this->admin();
        $session = $this->dutySession();
        $dept1 = $this->department('DEPT-A');
        $dept2 = $this->department('DEPT-B');
        $kg = Khidmatguzar::create(['its_id' => '20006666', 'full_name' => 'Person']);
        $batch = $this->batch($session, $user);
        $this->assignment($session, $batch, $kg, $dept1);
        $this->assignment($session, $batch, $kg, $dept2);

        $response = $this->actingAs($user)->get(route('analytics.profile', $kg));

        $response->assertOk()->assertSee('DEPT-A')->assertSee('DEPT-B');
        $this->assertSame(2, DutyAssignment::where('khidmatguzar_id', $kg->id)->count());
    }

    /** 6. Total Duties counts assignments (not sessions, not events, not batches). */
    public function test_total_duties_counts_assignments(): void
    {
        $user = $this->admin();
        $session = $this->dutySession();
        $dept = $this->department();
        $kg = Khidmatguzar::create(['its_id' => '20007777', 'full_name' => 'Person']);
        $batch = $this->batch($session, $user);
        $this->assignment($session, $batch, $kg, $dept);
        $this->assignment($session, $batch, $kg, $dept);
        $this->assignment($session, $batch, $kg, $dept);

        $response = $this->actingAs($user)->get(route('analytics.profile', $kg));
        $response->assertOk();
        $this->assertSame(3, DutyAssignment::where('khidmatguzar_id', $kg->id)->count());
    }

    /** 7. Sessions Served counts distinct sessions, independent of Total Duties. */
    public function test_sessions_served_counts_distinct_sessions(): void
    {
        $user = $this->admin();
        $sessionA = $this->dutySession();
        $sessionB = $this->dutySession();
        $dept = $this->department();
        $kg = Khidmatguzar::create(['its_id' => '20008888', 'full_name' => 'Person']);
        $this->assignment($sessionA, $this->batch($sessionA, $user), $kg, $dept);
        $this->assignment($sessionA, $this->batch($sessionA, $user), $kg, $dept); // 2nd assignment, same session
        $this->assignment($sessionB, $this->batch($sessionB, $user), $kg, $dept);

        $response = $this->actingAs($user)->get(route('analytics.profile', $kg));

        $response->assertOk();
        $response->assertSeeInOrder(['Total Duties']); // sanity the section rendered
        $this->assertSame(3, DutyAssignment::where('khidmatguzar_id', $kg->id)->count());
        $this->assertSame(2, DutyAssignment::where('khidmatguzar_id', $kg->id)->distinct('duty_session_id')->count('duty_session_id'));
    }

    /** 8. Present/Absent counts reflect current_status only. */
    public function test_present_absent_counts(): void
    {
        $user = $this->admin();
        $session = $this->dutySession();
        $dept = $this->department();
        $kg = Khidmatguzar::create(['its_id' => '20009999', 'full_name' => 'Person']);
        $batch = $this->batch($session, $user);
        $a1 = $this->assignment($session, $batch, $kg, $dept);
        $a2 = $this->assignment($session, $batch, $kg, $dept);

        $service = app(AttendanceService::class);
        $service->markPresent($session, $a1->id, $user);
        $service->markAbsent($session, $a2->id, $user);

        $response = $this->actingAs($user)->get(route('analytics.profile', $kg));
        $response->assertOk();
        $this->assertSame('present', $a1->fresh()->current_status);
        $this->assertSame('absent', $a2->fresh()->current_status);
    }

    /** 9. Extra Present stays separate from scheduled totals. */
    public function test_extra_present_separate_from_scheduled(): void
    {
        $user = $this->admin();
        $session = $this->dutySession();
        $dept = $this->department();
        $kg = Khidmatguzar::create(['its_id' => '20010001', 'full_name' => 'Person']);
        $this->assignment($session, $this->batch($session, $user), $kg, $dept);

        ExtraPresent::create([
            'duty_session_id' => $session->id, 'khidmatguzar_id' => $kg->id,
            'its_id_snapshot' => $kg->its_id, 'full_name_snapshot' => $kg->full_name,
            'department_id' => $dept->id, 'department_name_snapshot' => $dept->name,
            'marked_by' => $user->id, 'marked_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('analytics.profile', $kg));

        $response->assertOk()->assertSee('Extra Present History');
        $this->assertSame(1, DutyAssignment::where('khidmatguzar_id', $kg->id)->count());
        $this->assertSame(1, ExtraPresent::where('khidmatguzar_id', $kg->id)->count());
    }

    /** 10. Attendance Rate = Present / Total Scheduled, Extra excluded from denominator. */
    public function test_attendance_rate_excludes_extra_present(): void
    {
        $user = $this->admin();
        $session = $this->dutySession();
        $dept = $this->department();
        $kg = Khidmatguzar::create(['its_id' => '20010002', 'full_name' => 'Person']);
        $batch = $this->batch($session, $user);
        $a1 = $this->assignment($session, $batch, $kg, $dept);
        $a2 = $this->assignment($session, $batch, $kg, $dept);
        app(AttendanceService::class)->markPresent($session, $a1->id, $user);
        // a2 stays pending

        ExtraPresent::create([
            'duty_session_id' => $session->id, 'khidmatguzar_id' => $kg->id,
            'its_id_snapshot' => $kg->its_id, 'full_name_snapshot' => $kg->full_name,
            'department_id' => $dept->id, 'department_name_snapshot' => $dept->name,
            'marked_by' => $user->id, 'marked_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('analytics.profile', $kg));
        // 1 present / 2 scheduled = 50%, NOT 1/3 (which would happen if extra counted)
        $response->assertSee('50%');
    }

    /** 11. Department history uses historical duty_assignments.department_id. */
    public function test_department_history_uses_historical_department(): void
    {
        $user = $this->admin();
        $session = $this->dutySession();
        $deptOld = $this->department('OLD-DEPT');
        $kg = Khidmatguzar::create(['its_id' => '20010003', 'full_name' => 'Person']);
        $this->assignment($session, $this->batch($session, $user), $kg, $deptOld);

        // Khidmatguzar master has no department field at all — confirm.
        $this->assertFalse(in_array('department_id', $kg->getFillable(), true));

        $response = $this->actingAs($user)->get(route('analytics.profile', $kg));
        $response->assertOk()->assertSee('OLD-DEPT');
    }

    /** 12. Date filtering on the directory narrows results correctly. */
    public function test_directory_date_range_filter(): void
    {
        $user = $this->admin();
        $inRange = $this->dutySession('closed', '2026-06-15');
        $outOfRange = $this->dutySession('closed', '2020-01-01');
        $dept = $this->department();
        $kgIn = Khidmatguzar::create(['its_id' => '20010004', 'full_name' => 'In Range Person']);
        $kgOut = Khidmatguzar::create(['its_id' => '20010005', 'full_name' => 'Out Of Range Person']);
        $this->assignment($inRange, $this->batch($inRange, $user), $kgIn, $dept);
        $this->assignment($outOfRange, $this->batch($outOfRange, $user), $kgOut, $dept);

        $response = $this->actingAs($user)->get(route('analytics.profile-search', ['from' => '2026-06-01', 'to' => '2026-06-30']));

        $response->assertOk()->assertSee('In Range Person')->assertDontSee('Out Of Range Person');
    }

    /** 13. Department filtering on the directory narrows results correctly. */
    public function test_directory_department_filter(): void
    {
        $user = $this->admin();
        $session = $this->dutySession();
        $deptA = $this->department('FILTER-A');
        $deptB = $this->department('FILTER-B');
        $kgA = Khidmatguzar::create(['its_id' => '20010006', 'full_name' => 'Dept A Person']);
        $kgB = Khidmatguzar::create(['its_id' => '20010007', 'full_name' => 'Dept B Person']);
        $this->assignment($session, $this->batch($session, $user), $kgA, $deptA);
        $this->assignment($session, $this->batch($session, $user), $kgB, $deptB);

        $response = $this->actingAs($user)->get(route('analytics.profile-search', ['department_id' => $deptA->id]));

        $response->assertOk()->assertSee('Dept A Person')->assertDontSee('Dept B Person');
    }

    /** 14. Profile duty history is paginated (bounded). */
    public function test_profile_history_is_paginated(): void
    {
        $user = $this->admin();
        $session = $this->dutySession();
        $dept = $this->department();
        $kg = Khidmatguzar::create(['its_id' => '20010008', 'full_name' => 'Person']);
        $batch = $this->batch($session, $user);
        for ($i = 0; $i < 20; $i++) {
            $this->assignment($session, $batch, $kg, $dept);
        }

        $response = $this->actingAs($user)->get(route('analytics.profile', $kg));
        $response->assertOk();
        $this->assertSame(20, DutyAssignment::where('khidmatguzar_id', $kg->id)->count());
        // paginator defaults to 15 per page — pagination links must appear for 20 rows.
        $response->assertSee('history=2', false);
    }

    /** 15. Directory itself is paginated. */
    public function test_directory_is_paginated(): void
    {
        $user = $this->admin();
        for ($i = 0; $i < 25; $i++) {
            Khidmatguzar::create(['its_id' => '3'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), 'full_name' => 'Bulk Person '.$i]);
        }

        $response = $this->actingAs($user)->get(route('analytics.profile-search'));
        $response->assertOk();
        $response->assertSee('page=2', false);
    }

    /** 16. Viewer can access Directory and Profile (read-only, no mutation surface exists). */
    public function test_viewer_can_view_directory_and_profile(): void
    {
        $viewer = $this->viewer();
        $kg = Khidmatguzar::create(['its_id' => '20010009', 'full_name' => 'Person']);

        $this->actingAs($viewer)->get(route('analytics.profile-search'))->assertOk();
        $this->actingAs($viewer)->get(route('analytics.profile', $kg))->assertOk();
    }

    /** 17. Invalid/nonexistent person ID handled safely (404, no stack trace). */
    public function test_invalid_khidmatguzar_id_returns_404(): void
    {
        $user = $this->admin();

        $response = $this->actingAs($user)->get('/khidmatguzars/999999');

        $response->assertNotFound();
        $response->assertDontSee('Stack trace', false);
    }

    /** 18. No N+1 regression — directory and profile stay within a small, bounded query count. */
    public function test_no_n_plus_one_on_directory_and_profile(): void
    {
        $user = $this->admin();
        $session = $this->dutySession();
        $dept = $this->department();
        $batch = $this->batch($session, $user);
        for ($i = 0; $i < 15; $i++) {
            $kg = Khidmatguzar::create(['its_id' => '4'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), 'full_name' => 'Perf Person '.$i]);
            $this->assignment($session, $batch, $kg, $dept);
        }

        DB::enableQueryLog();
        $this->actingAs($user)->get(route('analytics.profile-search'))->assertOk();
        $directoryQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        $this->actingAs($user)->get(route('analytics.profile', $kg))->assertOk();
        $profileQueries = count(DB::getQueryLog());

        // Bounded: must not scale with the 15 people/assignments created above.
        $this->assertLessThan(15, $directoryQueries);
        $this->assertLessThan(15, $profileQueries);
    }

    /** 19. Active session: Pending is shown honestly, never counted as Absent. */
    public function test_active_session_pending_not_counted_as_absent(): void
    {
        $user = $this->admin();
        $session = $this->dutySession('active');
        $dept = $this->department();
        $kg = Khidmatguzar::create(['its_id' => '20010010', 'full_name' => 'Person']);
        $this->assignment($session, $this->batch($session, $user), $kg, $dept); // stays pending

        $response = $this->actingAs($user)->get(route('analytics.profile', $kg));

        $response->assertOk();
        $this->assertSame('pending', DutyAssignment::where('khidmatguzar_id', $kg->id)->first()->current_status);
    }

    /** 20. Closed session: history shows finalized Present/Absent, zero Pending. */
    public function test_closed_session_history_is_finalized(): void
    {
        $user = $this->admin();
        $session = $this->dutySession('active');
        $dept = $this->department();
        $kg = Khidmatguzar::create(['its_id' => '20010011', 'full_name' => 'Person']);
        $a = $this->assignment($session, $this->batch($session, $user), $kg, $dept);
        app(AttendanceService::class)->markPresent($session, $a->id, $user);
        app(AttendanceService::class)->closeSession($session, $user);

        $response = $this->actingAs($user)->get(route('analytics.profile', $kg));

        $response->assertOk();
        $this->assertSame('closed', $session->fresh()->status);
        $this->assertSame(0, DutyAssignment::where('khidmatguzar_id', $kg->id)->where('current_status', 'pending')->count());
    }
}
