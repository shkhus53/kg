<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\ExtraPresent;
use App\Models\ImportBatch;
use App\Models\Khidmatguzar;
use App\Models\SyncedEvent;
use App\Models\User;
use App\Services\OfflineSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 2 (Offline Attendance) server-side contract: OfflineSyncController
 * + OfflineSyncService. The client (IndexedDB queue, connectivity
 * detection) cannot be exercised by PHPUnit — this covers everything the
 * server is actually responsible for: idempotency, operator-identity
 * enforcement, business-rule delegation to AttendanceService, and
 * provisioning authorization.
 */
class OfflineSyncTest extends TestCase
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

    private function assignment(DutySession $session, User $uploader, string $its, ?Department $dept = null): DutyAssignment
    {
        $dept = $dept ?? $this->department();
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $uploader->id, 'original_filename' => 'f.csv', 'file_type' => 'csv', 'status' => 'completed']);
        $kg = Khidmatguzar::create(['its_id' => $its, 'full_name' => 'Person '.$its]);

        return DutyAssignment::create([
            'duty_session_id' => $session->id, 'import_batch_id' => $batch->id, 'khidmatguzar_id' => $kg->id,
            'department_id' => $dept->id, 'source_row_number' => 2, 'assignment_fingerprint' => 'fp-'.Str::random(10),
            'venue_name_raw' => $dept->name, 'full_name_snapshot' => $kg->full_name,
        ]);
    }

    private function presentEvent(DutySession $session, DutyAssignment $assignment, User $operator, ?string $eventId = null): array
    {
        return [
            'event_id' => $eventId ?? (string) Str::uuid(),
            'session_id' => $session->id,
            'assignment_id' => $assignment->id,
            'khidmatguzar_id' => $assignment->khidmatguzar_id,
            'operator_user_id' => $operator->id,
            'device_id' => 'device-A',
            'action' => 'present',
            'context' => 'individual',
            'payload' => null,
            'local_sequence' => 1,
            'local_timestamp' => now()->toIso8601String(),
            'package_version' => 'pkg-1',
        ];
    }

    // --- Provisioning authorization ---

    public function test_operator_can_provision_active_session(): void
    {
        $operator = $this->operator();
        $session = $this->activeSession();
        $this->assignment($session, $operator, '30000001');

        $this->actingAs($operator)->getJson(route('offline.provision', $session))
            ->assertOk()
            ->assertJsonPath('session.id', $session->id)
            ->assertJsonCount(1, 'assignments');
    }

    public function test_viewer_cannot_provision(): void
    {
        $viewer = User::factory()->viewer()->create();
        $session = $this->activeSession();

        $this->actingAs($viewer)->getJson(route('offline.provision', $session))->assertForbidden();
    }

    public function test_closed_session_cannot_be_provisioned(): void
    {
        $admin = $this->admin();
        $session = DutySession::create(['name' => 'Closed', 'date' => now()->format('Y-m-d'), 'status' => 'closed', 'closed_at' => now(), 'closed_by' => $admin->id]);

        $this->actingAs($admin)->getJson(route('offline.provision', $session))->assertStatus(422);
    }

    // --- Idempotency ---

    public function test_valid_offline_event_is_accepted(): void
    {
        $operator = $this->operator();
        $session = $this->activeSession();
        $assignment = $this->assignment($session, $operator, '30000010');
        $event = $this->presentEvent($session, $assignment, $operator);

        $results = app(OfflineSyncService::class)->processBatch([$event], $operator);

        $this->assertSame('accepted', $results[$event['event_id']]['status']);
        $this->assertSame('present', $assignment->fresh()->current_status);
        $this->assertSame(1, AttendanceEvent::where('duty_assignment_id', $assignment->id)->count());
    }

    public function test_retried_event_does_not_reinvoke_attendance_mutation(): void
    {
        $operator = $this->operator();
        $session = $this->activeSession();
        $assignment = $this->assignment($session, $operator, '30000011');
        $event = $this->presentEvent($session, $assignment, $operator);

        $service = app(OfflineSyncService::class);
        $first = $service->processBatch([$event], $operator);
        $second = $service->processBatch([$event], $operator); // exact same event_id, resent

        $this->assertSame('accepted', $first[$event['event_id']]['status']);
        $this->assertSame('accepted', $second[$event['event_id']]['status']);
        $this->assertSame(1, AttendanceEvent::where('duty_assignment_id', $assignment->id)->count(), 'retry must not create a second AttendanceEvent');
        $this->assertSame(1, SyncedEvent::where('event_id', $event['event_id'])->count());
        $this->assertSame(2, SyncedEvent::where('event_id', $event['event_id'])->value('attempt_count'));
    }

    public function test_multiple_queued_events_sync_correctly_in_one_batch(): void
    {
        $operator = $this->operator();
        $session = $this->activeSession();
        $a1 = $this->assignment($session, $operator, '30000020');
        $a2 = $this->assignment($session, $operator, '30000021');

        $events = [$this->presentEvent($session, $a1, $operator), $this->presentEvent($session, $a2, $operator)];
        $results = app(OfflineSyncService::class)->processBatch($events, $operator);

        $this->assertSame('accepted', $results[$events[0]['event_id']]['status']);
        $this->assertSame('accepted', $results[$events[1]['event_id']]['status']);
        $this->assertSame('present', $a1->fresh()->current_status);
        $this->assertSame('present', $a2->fresh()->current_status);
    }

    public function test_partial_batch_success_one_bad_event_does_not_affect_others(): void
    {
        $operator = $this->operator();
        $session = $this->activeSession();
        $good = $this->assignment($session, $operator, '30000030');

        $goodEvent = $this->presentEvent($session, $good, $operator);
        $badEvent = $this->presentEvent($session, $good, $operator);
        $badEvent['event_id'] = (string) Str::uuid();
        $badEvent['assignment_id'] = 999999; // does not exist

        $results = app(OfflineSyncService::class)->processBatch([$goodEvent, $badEvent], $operator);

        $this->assertSame('accepted', $results[$goodEvent['event_id']]['status']);
        $this->assertSame('rejected', $results[$badEvent['event_id']]['status']);
        $this->assertSame('present', $good->fresh()->current_status);
    }

    public function test_invalid_event_unknown_action_rejected(): void
    {
        $operator = $this->operator();
        $session = $this->activeSession();
        $event = ['event_id' => (string) Str::uuid(), 'session_id' => $session->id, 'action' => 'teleport', 'operator_user_id' => $operator->id, 'device_id' => 'd'];

        $results = app(OfflineSyncService::class)->processBatch([$event], $operator);

        $this->assertSame('rejected', $results[$event['event_id']]['status']);
    }

    public function test_unauthorized_viewer_cannot_hit_sync_endpoint(): void
    {
        $viewer = User::factory()->viewer()->create();
        $session = $this->activeSession();

        $this->actingAs($viewer)->postJson(route('offline.sync'), [
            'events' => [['event_id' => (string) Str::uuid(), 'session_id' => $session->id, 'action' => 'present']],
        ])->assertForbidden();
    }

    public function test_nonexistent_session_rejected(): void
    {
        $operator = $this->operator();
        $event = ['event_id' => (string) Str::uuid(), 'session_id' => 999999, 'assignment_id' => 1, 'action' => 'present', 'operator_user_id' => $operator->id, 'device_id' => 'd'];

        $results = app(OfflineSyncService::class)->processBatch([$event], $operator);

        $this->assertSame('rejected', $results[$event['event_id']]['status']);
    }

    public function test_assignment_not_belonging_to_session_rejected(): void
    {
        $operator = $this->operator();
        $sessionA = $this->activeSession();
        $sessionB = $this->activeSession();
        $assignmentInB = $this->assignment($sessionB, $operator, '30000040');

        // Claim it's for session A, but the assignment belongs to session B.
        $event = $this->presentEvent($sessionA, $assignmentInB, $operator);

        $results = app(OfflineSyncService::class)->processBatch([$event], $operator);

        $this->assertSame('rejected', $results[$event['event_id']]['status']);
        $this->assertSame('pending', $assignmentInB->fresh()->current_status);
    }

    public function test_closed_session_events_rejected_not_silently_discarded(): void
    {
        $operator = $this->operator();
        $admin = $this->admin();
        $session = $this->activeSession();
        $assignment = $this->assignment($session, $operator, '30000050');
        $event = $this->presentEvent($session, $assignment, $operator);

        $session->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $admin->id]);

        $results = app(OfflineSyncService::class)->processBatch([$event], $operator);

        $this->assertSame('rejected', $results[$event['event_id']]['status']);
        $ledger = SyncedEvent::where('event_id', $event['event_id'])->firstOrFail();
        $this->assertSame('rejected', $ledger->last_result);
        $this->assertNotNull($ledger->detail);
    }

    public function test_stale_assignment_id_rejected_with_clear_reason(): void
    {
        $operator = $this->operator();
        $session = $this->activeSession();
        $event = [
            'event_id' => (string) Str::uuid(), 'session_id' => $session->id, 'assignment_id' => 999999,
            'khidmatguzar_id' => null, 'operator_user_id' => $operator->id, 'device_id' => 'd', 'action' => 'present',
        ];

        $results = app(OfflineSyncService::class)->processBatch([$event], $operator);

        $this->assertSame('rejected', $results[$event['event_id']]['status']);
    }

    public function test_rule_9_conflict_scheduled_person_cannot_become_extra_present(): void
    {
        $operator = $this->operator();
        $session = $this->activeSession();
        $dept = $this->department();
        $assignment = $this->assignment($session, $operator, '30000060', $dept);

        $event = [
            'event_id' => (string) Str::uuid(), 'session_id' => $session->id, 'assignment_id' => null,
            'khidmatguzar_id' => $assignment->khidmatguzar_id, 'operator_user_id' => $operator->id, 'device_id' => 'd',
            'action' => 'extra_present', 'context' => 'individual',
            'payload' => ['its' => '30000060', 'gender' => 'Male', 'department_id' => $dept->id],
            'local_sequence' => 1, 'local_timestamp' => now()->toIso8601String(), 'package_version' => 'p',
        ];

        $results = app(OfflineSyncService::class)->processBatch([$event], $operator);

        $this->assertSame('conflict', $results[$event['event_id']]['status']);
        $this->assertSame(0, ExtraPresent::count());
    }

    public function test_multiple_assignments_each_synced_independently(): void
    {
        $operator = $this->operator();
        $session = $this->activeSession();
        $dept1 = $this->department('SEC');
        $dept2 = $this->department('PARK');
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $operator->id, 'original_filename' => 'f.csv', 'file_type' => 'csv', 'status' => 'completed']);
        $kg = Khidmatguzar::create(['its_id' => '30000070', 'full_name' => 'Two Assignments']);
        $a1 = DutyAssignment::create(['duty_session_id' => $session->id, 'import_batch_id' => $batch->id, 'khidmatguzar_id' => $kg->id, 'department_id' => $dept1->id, 'source_row_number' => 2, 'assignment_fingerprint' => 'fp-a', 'venue_name_raw' => $dept1->name, 'full_name_snapshot' => $kg->full_name]);
        $a2 = DutyAssignment::create(['duty_session_id' => $session->id, 'import_batch_id' => $batch->id, 'khidmatguzar_id' => $kg->id, 'department_id' => $dept2->id, 'source_row_number' => 3, 'assignment_fingerprint' => 'fp-b', 'venue_name_raw' => $dept2->name, 'full_name_snapshot' => $kg->full_name]);

        $events = [$this->presentEvent($session, $a1, $operator), $this->presentEvent($session, $a2, $operator)];
        app(OfflineSyncService::class)->processBatch($events, $operator);

        $this->assertSame('present', $a1->fresh()->current_status);
        $this->assertSame('present', $a2->fresh()->current_status);
        $this->assertSame(2, AttendanceEvent::where('khidmatguzar_id', $kg->id)->count());
    }

    public function test_extra_present_known_and_new_via_sync(): void
    {
        $operator = $this->operator();
        $session = $this->activeSession();
        $dept = $this->department();
        // give the session at least one assignment so the department is "in scope"
        $this->assignment($session, $operator, '30000080', $dept);

        $newEvent = [
            'event_id' => (string) Str::uuid(), 'session_id' => $session->id, 'operator_user_id' => $operator->id, 'device_id' => 'd',
            'action' => 'extra_present', 'context' => 'individual',
            'payload' => ['its' => '30000081', 'full_name' => 'New Extra', 'gender' => 'Female', 'department_id' => $dept->id],
            'local_sequence' => 1, 'local_timestamp' => now()->toIso8601String(),
        ];

        $results = app(OfflineSyncService::class)->processBatch([$newEvent], $operator);

        $this->assertSame('accepted', $results[$newEvent['event_id']]['status']);
        $this->assertSame(1, ExtraPresent::where('its_id_snapshot', '30000081')->count());
        $this->assertSame('Female', Khidmatguzar::where('its_id', '30000081')->firstOrFail()->gender);
    }

    public function test_extra_present_missing_gender_rejected(): void
    {
        $operator = $this->operator();
        $session = $this->activeSession();
        $dept = $this->department();
        $this->assignment($session, $operator, '30000090', $dept);

        $event = [
            'event_id' => (string) Str::uuid(), 'session_id' => $session->id, 'operator_user_id' => $operator->id, 'device_id' => 'd',
            'action' => 'extra_present', 'payload' => ['its' => '30000091', 'full_name' => 'No Gender', 'department_id' => $dept->id],
        ];

        $results = app(OfflineSyncService::class)->processBatch([$event], $operator);

        $this->assertSame('rejected', $results[$event['event_id']]['status']);
    }

    public function test_correction_absent_to_present_via_sync(): void
    {
        $operator = $this->operator();
        $session = $this->activeSession();
        $assignment = $this->assignment($session, $operator, '30000100');
        $assignment->update(['current_status' => 'absent']);

        $event = $this->presentEvent($session, $assignment, $operator);
        $results = app(OfflineSyncService::class)->processBatch([$event], $operator);

        $this->assertSame('accepted', $results[$event['event_id']]['status']);
        $this->assertSame('present', $assignment->fresh()->current_status);
    }

    public function test_server_timestamp_is_authoritative_not_client_local_timestamp(): void
    {
        $operator = $this->operator();
        $session = $this->activeSession();
        $assignment = $this->assignment($session, $operator, '30000110');

        $event = $this->presentEvent($session, $assignment, $operator);
        $event['local_timestamp'] = '2020-01-01T00:00:00Z'; // deliberately absurd/old client clock

        app(OfflineSyncService::class)->processBatch([$event], $operator);

        $attendanceEvent = AttendanceEvent::where('duty_assignment_id', $assignment->id)->firstOrFail();
        $this->assertTrue($attendanceEvent->performed_at->greaterThan(now()->subMinute()), 'AttendanceEvent.performed_at must be server time, not the client-supplied local_timestamp');
    }

    // --- Operator identity: the critical scenario set ---
    //
    // Checkpoint requirement: A creates an event offline -> A logs out ->
    // B logs in on the same device -> B cannot claim/sync A's event -> A
    // returns -> A can still sync it successfully. "Logout"/"login" has no
    // separate server-side concept here — identity is entirely a function
    // of which authenticated actor is making the sync request, which is
    // exactly what these tests vary between calls.

    public function test_operator_mismatch_is_rejected_and_never_reassigned(): void
    {
        $userA = $this->operator();
        $userB = $this->operator();
        $session = $this->activeSession();
        $assignment = $this->assignment($session, $userA, '30000120');

        // User A's device queued this while authenticated as A...
        $event = $this->presentEvent($session, $assignment, $userA);

        // ...but the sync request is actually authenticated as B (A logged
        // out, B logged in on the same device).
        $results = app(OfflineSyncService::class)->processBatch([$event], $userB);

        $this->assertSame('operator_mismatch', $results[$event['event_id']]['status']);
        $this->assertSame('pending', $assignment->fresh()->current_status, 'event must not be applied under the wrong identity');

        $ledger = SyncedEvent::where('event_id', $event['event_id'])->firstOrFail();
        $this->assertSame($userA->id, $ledger->operator_user_id, 'claimed operator is preserved as metadata');
        $this->assertSame($userB->id, $ledger->synced_by_user_id, 'actual authenticated actor is recorded separately');
    }

    public function test_original_operator_can_later_sync_their_own_queued_event(): void
    {
        $userA = $this->operator();
        $userB = $this->operator();
        $session = $this->activeSession();
        $assignment = $this->assignment($session, $userA, '30000121');
        $event = $this->presentEvent($session, $assignment, $userA);

        $service = app(OfflineSyncService::class);

        // B tries first (still logged in) and is blocked...
        $blocked = $service->processBatch([$event], $userB);
        $this->assertSame('operator_mismatch', $blocked[$event['event_id']]['status']);

        // ...then B logs out, A logs back in on the same device and syncs
        // the exact same queued event — must succeed normally, not be
        // poisoned by the earlier mismatch attempt.
        $accepted = $service->processBatch([$event], $userA);
        $this->assertSame('accepted', $accepted[$event['event_id']]['status']);
        $this->assertSame('present', $assignment->fresh()->current_status);
    }

    /**
     * A null/missing operator_user_id is never silently adopted by whoever
     * happens to sync it — closes the exact gap the previously-considered
     * "adopt unclaimed events" design would have reopened. The static
     * offline-fallback shell (public/offline.html) no longer creates
     * attendance events at all; this proves the server independently
     * enforces the same rule regardless of what any client sends.
     */
    public function test_event_with_no_claimed_operator_is_rejected_not_adopted(): void
    {
        $actor = $this->operator();
        $session = $this->activeSession();
        $assignment = $this->assignment($session, $actor, '30000122');

        $event = $this->presentEvent($session, $assignment, $actor);
        $event['operator_user_id'] = null;

        $results = app(OfflineSyncService::class)->processBatch([$event], $actor);

        $this->assertSame('rejected', $results[$event['event_id']]['status']);
        $this->assertSame('pending', $assignment->fresh()->current_status, 'an unowned event must never be applied, even under the actor who happens to sync it');

        $ledger = SyncedEvent::where('event_id', $event['event_id'])->firstOrFail();
        $this->assertNull($ledger->operator_user_id);

        // Retrying — including by the very actor who "owns" the assignment —
        // must not resurrect it either; missing identity is terminal.
        $retry = app(OfflineSyncService::class)->processBatch([$event], $actor);
        $this->assertSame('rejected', $retry[$event['event_id']]['status']);
        $this->assertSame('pending', $assignment->fresh()->current_status);
    }

    /**
     * Once an event_id's owner is recorded, resending the same event with a
     * DIFFERENT claimed operator_user_id must not change who it belongs to
     * — only the first-seen, persisted value is ever authoritative.
     */
    public function test_resending_event_with_different_claimed_operator_does_not_change_ownership(): void
    {
        $userA = $this->operator();
        $userC = $this->operator();
        $session = $this->activeSession();
        $assignment = $this->assignment($session, $userA, '30000123');

        $event = $this->presentEvent($session, $assignment, $userA);
        app(OfflineSyncService::class)->processBatch([$event], $userA); // accepted, owner = A

        // Same event_id resent, but now claiming to belong to C.
        $tampered = $event;
        $tampered['operator_user_id'] = $userC->id;
        $result = app(OfflineSyncService::class)->processBatch([$tampered], $userC);

        $this->assertSame('accepted', $result[$event['event_id']]['status'], 'already-accepted terminal outcome, not re-evaluated under the new claim');
        $this->assertSame($userA->id, SyncedEvent::where('event_id', $event['event_id'])->value('operator_user_id'), 'ownership recorded at creation must remain immutable');
    }

    public function test_device_identity_is_recorded_on_the_ledger(): void
    {
        $operator = $this->operator();
        $session = $this->activeSession();
        $assignment = $this->assignment($session, $operator, '30000130');
        $event = $this->presentEvent($session, $assignment, $operator);
        $event['device_id'] = 'device-xyz-123';

        app(OfflineSyncService::class)->processBatch([$event], $operator);

        $this->assertSame('device-xyz-123', SyncedEvent::where('event_id', $event['event_id'])->value('device_id'));
    }

    // --- Audit trail visibility ---

    public function test_conflict_is_visible_in_admin_audit_log(): void
    {
        $admin = $this->admin();
        $operator = $this->operator();
        $session = $this->activeSession();
        $dept = $this->department();
        $assignment = $this->assignment($session, $operator, '30000140', $dept);

        $event = [
            'event_id' => (string) Str::uuid(), 'session_id' => $session->id, 'operator_user_id' => $operator->id, 'device_id' => 'd',
            'action' => 'extra_present', 'payload' => ['its' => '30000140', 'gender' => 'Male', 'department_id' => $dept->id],
        ];
        app(OfflineSyncService::class)->processBatch([$event], $operator);

        $response = $this->actingAs($admin)->get(route('audit.index', ['action' => 'sync_issue']));

        $response->assertOk();
        $response->assertSee('conflict', false);
    }

    public function test_rejected_sync_event_is_never_deleted(): void
    {
        $operator = $this->operator();
        $session = $this->activeSession();
        $event = ['event_id' => (string) Str::uuid(), 'session_id' => $session->id, 'assignment_id' => 999999, 'action' => 'present', 'operator_user_id' => $operator->id, 'device_id' => 'd'];

        app(OfflineSyncService::class)->processBatch([$event], $operator);

        $this->assertDatabaseHas('synced_events', ['event_id' => $event['event_id'], 'last_result' => 'rejected']);
    }
}
