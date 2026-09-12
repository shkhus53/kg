<?php

namespace App\Services;

use App\Models\AttendanceEvent;
use App\Models\Department;
use App\Models\DutySession;
use App\Models\Khidmatguzar;
use App\Models\SyncedEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Thin translation + idempotency layer between the offline sync API and
 * AttendanceService, which remains the sole attendance business-rule
 * authority. This class duplicates no business rule — every mutation goes
 * through the exact same AttendanceService methods the online path uses.
 */
class OfflineSyncService
{
    public function __construct(private readonly AttendanceService $attendance) {}

    /**
     * @param  array<int,array<string,mixed>>  $events
     * @return array<string,array{status:string,detail?:string}>
     */
    public function processBatch(array $events, User $actor): array
    {
        $results = [];

        foreach ($events as $event) {
            $eventId = (string) ($event['event_id'] ?? '');

            if ($eventId === '') {
                continue; // malformed, nothing we can key a result on — client bug, not logged
            }

            $results[$eventId] = $this->processOne($event, $eventId, $actor);
        }

        return $results;
    }

    /**
     * @return array{status:string,detail?:string}
     */
    private function processOne(array $event, string $eventId, User $actor): array
    {
        return DB::transaction(function () use ($event, $eventId, $actor) {
            $existing = SyncedEvent::where('event_id', $eventId)->lockForUpdate()->first();

            if ($existing) {
                $existing->increment('attempt_count');
                $existing->update(['last_attempted_at' => now()]);

                // Every terminal outcome (accepted/duplicate/conflict/
                // rejected) already represents a real, completed attempt —
                // never re-invoke AttendanceService for it, regardless of who
                // is asking.
                if ($existing->last_result !== 'operator_mismatch') {
                    return ['status' => $existing->last_result, 'detail' => $existing->detail];
                }

                // operator_mismatch is NOT terminal — nothing was ever
                // applied. The event's owner is whatever operator_user_id
                // was recorded the FIRST time this event_id was seen —
                // immutable from then on; a resent payload's claim is never
                // consulted again, only this persisted value is
                // authoritative. Only that original owner's retry proceeds.
                if ($existing->operator_user_id !== null && $existing->operator_user_id === $actor->id) {
                    return $this->applyAndFinalize($event, $actor, $existing);
                }

                return ['status' => 'operator_mismatch', 'detail' => $existing->detail];
            }

            $claimedOperatorId = isset($event['operator_user_id']) && $event['operator_user_id'] !== null
                ? (int) $event['operator_user_id']
                : null;

            try {
                $ledger = SyncedEvent::create([
                    'event_id' => $eventId,
                    'duty_session_id' => $event['session_id'] ?? null,
                    'assignment_id' => $event['assignment_id'] ?? null,
                    'khidmatguzar_id' => $event['khidmatguzar_id'] ?? null,
                    'action' => (string) ($event['action'] ?? 'unknown'),
                    'context' => $event['context'] ?? 'individual',
                    'payload' => $event['payload'] ?? null,
                    'operator_user_id' => $claimedOperatorId,
                    'synced_by_user_id' => $actor->id,
                    'device_id' => $event['device_id'] ?? null,
                    'local_sequence' => $event['local_sequence'] ?? null,
                    'local_timestamp' => $event['local_timestamp'] ?? null,
                    'package_version' => $event['package_version'] ?? null,
                    'attempt_count' => 1,
                    'first_received_at' => now(),
                    'last_attempted_at' => now(),
                    'last_result' => 'processing',
                ]);
            } catch (QueryException $e) {
                if ($this->isDuplicateKeyError($e)) {
                    // Lost a race to create the ledger row — re-fetch and
                    // recurse through the same logic as a normal retry.
                    return $this->processOne($event, $eventId, $actor);
                }
                throw $e;
            }

            // An event with no claimed operator is never silently adopted by
            // whoever happens to sync it — it is missing required identity
            // and is rejected outright, recorded for audit, and left
            // unapplied. This closes the exact gap a compromised or buggy
            // client (or a stale offline shell) could otherwise exploit to
            // have an anonymous action attributed to whichever operator
            // later authenticates.
            if ($claimedOperatorId === null) {
                $ledger->update([
                    'last_result' => 'rejected',
                    'detail' => 'Event is missing a claimed operator identity and cannot be attributed or applied.',
                ]);

                return ['status' => 'rejected', 'detail' => $ledger->detail];
            }

            // Operator identity is never trusted from the client for
            // AUTHORIZATION — the authenticated actor on this request is
            // what's actually checked. A mismatch is rejected and recorded,
            // never reassigned.
            if ($claimedOperatorId !== $actor->id) {
                $ledger->update([
                    'last_result' => 'operator_mismatch',
                    'detail' => $this->mismatchDetail(),
                ]);

                return ['status' => 'operator_mismatch', 'detail' => $ledger->detail];
            }

            return $this->applyAndFinalize($event, $actor, $ledger);
        });
    }

    private function mismatchDetail(): string
    {
        return 'Queued by a different operator than the account currently signed in on this device.';
    }

    /**
     * @return array{status:string,detail?:string}
     */
    private function applyAndFinalize(array $event, User $actor, SyncedEvent $ledger): array
    {
        $outcome = $this->apply($event, $actor);

        $ledger->update([
            'synced_by_user_id' => $actor->id,
            'last_result' => $outcome['status'],
            'detail' => $outcome['detail'] ?? null,
            'synced_at' => $outcome['status'] === 'accepted' ? now() : null,
            'attendance_event_id' => $outcome['attendance_event_id'] ?? null,
            'extra_present_id' => $outcome['extra_present_id'] ?? null,
        ]);

        return ['status' => $outcome['status'], 'detail' => $outcome['detail'] ?? null];
    }

    /**
     * @return array{status:string,detail?:string,attendance_event_id?:int,extra_present_id?:int}
     */
    private function apply(array $event, User $actor): array
    {
        $session = DutySession::find($event['session_id'] ?? null);

        if (! $session) {
            return ['status' => 'rejected', 'detail' => 'Session not found.'];
        }

        try {
            return match ($event['action'] ?? null) {
                'present' => $this->applyPresence('present', $session, $event, $actor),
                'absent' => $this->applyPresence('absent', $session, $event, $actor),
                'extra_present' => $this->applyExtraPresent($session, $event, $actor),
                default => ['status' => 'rejected', 'detail' => 'Unknown action.'],
            };
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'retryable_failure', 'detail' => 'Temporary server error — will retry automatically.'];
        }
    }

    private function applyPresence(string $action, DutySession $session, array $event, User $actor): array
    {
        $assignmentId = (int) ($event['assignment_id'] ?? 0);

        if (! $assignmentId) {
            return ['status' => 'rejected', 'detail' => 'Missing assignment.'];
        }

        $remark = $event['payload']['remark'] ?? null;

        $result = $action === 'present'
            ? $this->attendance->markPresent($session, $assignmentId, $actor, $remark)
            : $this->attendance->markAbsent($session, $assignmentId, $actor, $remark);

        return $this->mapAssignmentOutcome($assignmentId, $result);
    }

    private function mapAssignmentOutcome(int $assignmentId, array $result): array
    {
        $accepted = in_array($result['result'], ['marked', 'corrected', 'already_present', 'already_absent'], true);

        if ($accepted) {
            $attendanceEventId = AttendanceEvent::where('duty_assignment_id', $assignmentId)->latest('id')->value('id');

            return ['status' => 'accepted', 'detail' => $result['result'], 'attendance_event_id' => $attendanceEventId];
        }

        return match ($result['result']) {
            'session_not_active' => ['status' => 'rejected', 'detail' => 'Session is not active — it may have been closed while this device was offline.'],
            'not_found' => ['status' => 'rejected', 'detail' => 'Assignment not found — provisioned data may be stale, re-provision this session.'],
            default => ['status' => 'rejected', 'detail' => 'Could not apply attendance.'],
        };
    }

    private function applyExtraPresent(DutySession $session, array $event, User $actor): array
    {
        $payload = $event['payload'] ?? [];
        $its = trim((string) ($payload['its'] ?? ''));
        $gender = (string) ($payload['gender'] ?? '');
        $departmentId = (int) ($payload['department_id'] ?? 0);
        $remark = $payload['remark'] ?? null;

        if ($its === '' || $gender === '' || ! $departmentId) {
            return ['status' => 'rejected', 'detail' => 'Missing required Extra Present fields (ITS, Gender, Department).'];
        }

        $department = Department::find($departmentId);
        if (! $department) {
            return ['status' => 'rejected', 'detail' => 'Department not found.'];
        }

        $khidmatguzar = Khidmatguzar::where('its_id', $its)->first();

        if ($khidmatguzar) {
            $result = $this->attendance->markExtraPresentKnown($session, $khidmatguzar, $department, $gender, $actor, $remark);
        } else {
            $fullName = trim((string) ($payload['full_name'] ?? ''));
            if ($fullName === '') {
                return ['status' => 'rejected', 'detail' => 'Full Name is required to create a new Khidmatguzar.'];
            }
            $result = $this->attendance->markExtraPresentNew($session, $its, $fullName, $gender, $department, $actor, $remark);
        }

        return match ($result['result']) {
            'marked', 'already_extra' => ['status' => 'accepted', 'detail' => $result['result'], 'extra_present_id' => $result['extraPresent']->id],
            'now_scheduled' => ['status' => 'conflict', 'detail' => 'This person now has a scheduled assignment in this session — search again.'],
            'session_not_active' => ['status' => 'rejected', 'detail' => 'Session is not active — it may have been closed while this device was offline.'],
            'invalid_department' => ['status' => 'rejected', 'detail' => 'That department is not part of this session.'],
            default => ['status' => 'rejected', 'detail' => 'Could not record Extra Present.'],
        };
    }

    private function isDuplicateKeyError(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
