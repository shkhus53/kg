<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\ImportBatch;
use App\Models\Khidmatguzar;
use App\Models\SessionReopenEvent;
use App\Models\SyncedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Final-audit finding: Audit Log filtered timestamp columns (performed_at,
 * reopened_at, last_attempted_at) with whereDate() while those columns are
 * written in UTC — an event at, say, 01:00 IST is stored as 19:30 UTC the
 * PREVIOUS day, so a whereDate() comparison against the UTC calendar date
 * silently missed it when an admin searched "today" in IST. Fixed via
 * AuditLogController::istDateBoundsToUtc(). These tests prove the actual
 * boundary is now correct, not just that a config value exists.
 *
 * IST is UTC+5:30, so the IST day '2026-09-12' spans:
 *   2026-09-11 18:30:00 UTC  (IST 00:00:00)
 *   through
 *   2026-09-12 18:29:59 UTC  (IST 23:59:59)
 */
class AuditLogTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function tzSession(): DutySession
    {
        return DutySession::create(['name' => 'TZ Test Session', 'date' => '2026-09-12', 'status' => 'closed']);
    }

    private function eventAtUtc(DutySession $session, User $actor, string $utcDateTime): AttendanceEvent
    {
        $khidmatguzar = Khidmatguzar::create(['its_id' => (string) random_int(10000000, 99999999), 'full_name' => 'TZ Test Person '.str_replace([' ', ':'], '-', $utcDateTime).'-'.uniqid()]);
        $department = Department::create(['name' => 'TZ-DEPT-'.uniqid(), 'normalized_key' => 'tz-dept-'.uniqid()]);
        $batch = ImportBatch::create([
            'duty_session_id' => $session->id,
            'uploaded_by' => $actor->id,
            'original_filename' => 'tz-test.csv',
            'file_type' => 'csv',
            'status' => 'completed',
            'total_rows' => 1,
            'valid_rows' => 1,
        ]);

        $assignment = DutyAssignment::create([
            'duty_session_id' => $session->id,
            'import_batch_id' => $batch->id,
            'khidmatguzar_id' => $khidmatguzar->id,
            'department_id' => $department->id,
            'source_row_number' => 1,
            'venue_name_raw' => $department->name,
            'assignment_fingerprint' => 'tz-test-'.uniqid(),
            'current_status' => 'present',
            'full_name_snapshot' => $khidmatguzar->full_name,
        ]);

        return AttendanceEvent::create([
            'duty_assignment_id' => $assignment->id,
            'duty_session_id' => $session->id,
            'khidmatguzar_id' => $khidmatguzar->id,
            'action' => 'present',
            'performed_by' => $actor->id,
            'performed_at' => Carbon::parse($utcDateTime, 'UTC'),
        ]);
    }

    /** IST 2026-09-12 00:30 = UTC 2026-09-11 19:00 — the exact window the old whereDate() bug missed. */
    public function test_event_at_0030_ist_appears_when_filtering_that_ist_date(): void
    {
        $session = $this->tzSession();
        $admin = $this->admin();
        $event = $this->eventAtUtc($session, $admin, '2026-09-11 19:00:00');

        $response = $this->actingAs($admin)->get(route('audit.index', ['date_from' => '2026-09-12', 'date_to' => '2026-09-12']));

        $response->assertOk();
        $response->assertSee($event->khidmatguzar->full_name);
    }

    /** IST 2026-09-12 05:29 = UTC 2026-09-11 23:59 — one minute before the old boundary would have started working by luck. */
    public function test_event_at_0529_ist_appears_when_filtering_that_ist_date(): void
    {
        $session = $this->tzSession();
        $admin = $this->admin();
        $event = $this->eventAtUtc($session, $admin, '2026-09-11 23:59:00');

        $response = $this->actingAs($admin)->get(route('audit.index', ['date_from' => '2026-09-12', 'date_to' => '2026-09-12']));

        $response->assertOk()->assertSee($event->khidmatguzar->full_name);
    }

    /** IST 2026-09-12 05:30 = UTC 2026-09-12 00:00 — the instant the UTC and IST calendar dates start agreeing again. */
    public function test_event_at_0530_ist_appears_correctly(): void
    {
        $session = $this->tzSession();
        $admin = $this->admin();
        $event = $this->eventAtUtc($session, $admin, '2026-09-12 00:00:00');

        $response = $this->actingAs($admin)->get(route('audit.index', ['date_from' => '2026-09-12', 'date_to' => '2026-09-12']));

        $response->assertOk()->assertSee($event->khidmatguzar->full_name);
    }

    /** IST 2026-09-12 23:59 = UTC 2026-09-12 18:29 — the far edge of the IST day. */
    public function test_event_at_2359_ist_appears_correctly(): void
    {
        $session = $this->tzSession();
        $admin = $this->admin();
        $event = $this->eventAtUtc($session, $admin, '2026-09-12 18:29:00');

        $response = $this->actingAs($admin)->get(route('audit.index', ['date_from' => '2026-09-12', 'date_to' => '2026-09-12']));

        $response->assertOk()->assertSee($event->khidmatguzar->full_name);
    }

    /** IST 2026-09-11 23:00 (the previous IST day) must NOT appear in a '2026-09-12' search. */
    public function test_event_from_previous_ist_date_does_not_incorrectly_appear(): void
    {
        $session = $this->tzSession();
        $admin = $this->admin();
        $event = $this->eventAtUtc($session, $admin, '2026-09-11 17:30:00'); // = 2026-09-11 23:00 IST

        $response = $this->actingAs($admin)->get(route('audit.index', ['date_from' => '2026-09-12', 'date_to' => '2026-09-12']));

        $response->assertOk()->assertDontSee($event->khidmatguzar->full_name);
    }

    /** A date-RANGE search (two IST calendar days) includes an event near the start of day 1 and the end of day 2, and excludes one before/after the range. */
    public function test_date_range_filtering_across_ist_dates_works_correctly(): void
    {
        $session = $this->tzSession();
        $admin = $this->admin();

        $inRangeStart = $this->eventAtUtc($session, $admin, '2026-09-10 18:35:00'); // 2026-09-11 00:05 IST
        $inRangeEnd = $this->eventAtUtc($session, $admin, '2026-09-12 18:00:00'); // 2026-09-12 23:30 IST
        $beforeRange = $this->eventAtUtc($session, $admin, '2026-09-10 18:00:00'); // 2026-09-10 23:30 IST — before range
        $afterRange = $this->eventAtUtc($session, $admin, '2026-09-12 18:30:00'); // 2026-09-13 00:00 IST — after range

        $response = $this->actingAs($admin)->get(route('audit.index', ['date_from' => '2026-09-11', 'date_to' => '2026-09-12']));

        $response->assertOk();
        $response->assertSee($inRangeStart->khidmatguzar->full_name);
        $response->assertSee($inRangeEnd->khidmatguzar->full_name);
        $response->assertDontSee($beforeRange->khidmatguzar->full_name);
        $response->assertDontSee($afterRange->khidmatguzar->full_name);
    }

    /** Session reopen events use the exact same IST boundary conversion as attendance events. */
    public function test_reopen_events_use_the_same_correct_date_semantics(): void
    {
        $session = $this->tzSession();
        $admin = $this->admin();

        $reopen = SessionReopenEvent::create([
            'duty_session_id' => $session->id,
            'reopened_by' => $admin->id,
            'reason' => 'other',
            'detail' => 'TZ boundary test detail',
            'reopened_at' => Carbon::parse('2026-09-11 19:15:00', 'UTC'), // 2026-09-12 00:45 IST
        ]);

        $sameDayHit = $this->actingAs($admin)->get(route('audit.index', ['date_from' => '2026-09-12', 'date_to' => '2026-09-12']));
        $sameDayHit->assertOk()->assertSee($reopen->detail);

        $previousDayMiss = $this->actingAs($admin)->get(route('audit.index', ['date_from' => '2026-09-11', 'date_to' => '2026-09-11']));
        $previousDayMiss->assertOk()->assertDontSee($reopen->detail);
    }

    /** Offline-sync issue timestamps (last_attempted_at) use the same correct semantics. */
    public function test_synced_event_timestamp_filter_uses_the_same_correct_semantics(): void
    {
        $session = $this->tzSession();
        $admin = $this->admin();

        $synced = SyncedEvent::create([
            'event_id' => (string) Str::uuid(),
            'duty_session_id' => $session->id,
            'action' => 'present',
            'operator_user_id' => $admin->id,
            'synced_by_user_id' => $admin->id,
            'attempt_count' => 1,
            'first_received_at' => Carbon::parse('2026-09-11 19:05:00', 'UTC'),
            'last_attempted_at' => Carbon::parse('2026-09-11 19:05:00', 'UTC'), // 2026-09-12 00:35 IST
            'last_result' => 'rejected',
            'detail' => 'TZ sync boundary test',
        ]);

        $sameDayHit = $this->actingAs($admin)->get(route('audit.index', ['date_from' => '2026-09-12', 'date_to' => '2026-09-12']));
        $sameDayHit->assertOk()->assertSee($synced->detail);

        $previousDayMiss = $this->actingAs($admin)->get(route('audit.index', ['date_from' => '2026-09-11', 'date_to' => '2026-09-11']));
        $previousDayMiss->assertOk()->assertDontSee($synced->detail);
    }
}
