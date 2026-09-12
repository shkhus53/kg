<?php

namespace App\Services;

use App\Exceptions\SessionHasPendingAssignmentsException;
use App\Models\AttendanceEvent;
use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\ExtraPresent;
use App\Models\Khidmatguzar;
use App\Models\SessionReopenEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Locked Phase 4 attendance mutation rules:
 *  - only against an `active` Duty Session
 *  - source `Scanned`/`Status` never influence current_status
 *  - first valid mutation wins under concurrency (row-locked, re-validated
 *    inside the transaction)
 *  - Extra Present never creates a Duty Assignment
 */
class AttendanceService
{
    /**
     * @return array{result: string, assignment?: DutyAssignment}
     */
    public function markPresent(DutySession $session, int $assignmentId, User $actor, ?string $remark = null): array
    {
        return DB::transaction(function () use ($session, $assignmentId, $actor, $remark) {
            $lockedSession = DutySession::whereKey($session->id)->lockForUpdate()->first();

            if (! $lockedSession->isActive()) {
                return ['result' => 'session_not_active'];
            }

            $assignment = DutyAssignment::where('id', $assignmentId)
                ->where('duty_session_id', $session->id)
                ->lockForUpdate()
                ->first();

            if (! $assignment) {
                return ['result' => 'not_found'];
            }

            if ($assignment->current_status === 'present') {
                return ['result' => 'already_present', 'assignment' => $assignment];
            }

            // Late arrival: an operator marked Absent too early, the person
            // then showed up. Absent -> Present is an allowed correction;
            // Present -> Absent (see markAbsent) is not.
            $wasAbsent = $assignment->current_status === 'absent';

            $this->applyPresent($assignment, $actor, $remark);

            return ['result' => $wasAbsent ? 'corrected' : 'marked', 'assignment' => $assignment->fresh()];
        });
    }

    /**
     * Mark a selected set of pending assignments Present in one transaction.
     * Assignments no longer pending (raced by another operator) are skipped,
     * not silently absorbed — the caller gets the full breakdown back.
     *
     * @param  array<int>  $assignmentIds
     * @return array{marked: array<int>, corrected: array<int>, already_present: array<int>, not_found: array<int>}
     */
    public function markPresentMany(DutySession $session, array $assignmentIds, User $actor, ?string $remark = null): array
    {
        return DB::transaction(function () use ($session, $assignmentIds, $actor, $remark) {
            $lockedSession = DutySession::whereKey($session->id)->lockForUpdate()->first();

            $outcome = ['marked' => [], 'corrected' => [], 'already_present' => [], 'not_found' => []];

            if (! $lockedSession->isActive()) {
                $outcome['session_not_active'] = true;

                return $outcome;
            }

            $assignments = DutyAssignment::whereIn('id', $assignmentIds)
                ->where('duty_session_id', $session->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($assignmentIds as $id) {
                $assignment = $assignments->get($id);

                if (! $assignment) {
                    $outcome['not_found'][] = $id;

                    continue;
                }

                if ($assignment->current_status === 'present') {
                    $outcome['already_present'][] = $id;

                    continue;
                }

                $wasAbsent = $assignment->current_status === 'absent';

                $this->applyPresent($assignment, $actor, $remark);
                $outcome[$wasAbsent ? 'corrected' : 'marked'][] = $id;
            }

            return $outcome;
        });
    }

    /**
     * @return array{result: string, assignment?: DutyAssignment}
     */
    public function markAbsent(DutySession $session, int $assignmentId, User $actor, ?string $remark = null): array
    {
        return DB::transaction(function () use ($session, $assignmentId, $actor, $remark) {
            $lockedSession = DutySession::whereKey($session->id)->lockForUpdate()->first();

            if (! $lockedSession->isActive()) {
                return ['result' => 'session_not_active'];
            }

            $assignment = DutyAssignment::where('id', $assignmentId)
                ->where('duty_session_id', $session->id)
                ->lockForUpdate()
                ->first();

            if (! $assignment) {
                return ['result' => 'not_found'];
            }

            if ($assignment->current_status === 'present') {
                return ['result' => 'already_present', 'assignment' => $assignment];
            }

            if ($assignment->current_status === 'absent') {
                return ['result' => 'already_absent', 'assignment' => $assignment];
            }

            $assignment->update([
                'current_status' => 'absent',
                'attendance_marked_at' => now(),
                'attendance_marked_by' => $actor->id,
            ]);

            AttendanceEvent::create([
                'duty_assignment_id' => $assignment->id,
                'duty_session_id' => $session->id,
                'khidmatguzar_id' => $assignment->khidmatguzar_id,
                'action' => 'absent',
                'performed_by' => $actor->id,
                'performed_at' => now(),
                'remark' => $remark,
            ]);

            return ['result' => 'marked', 'assignment' => $assignment->fresh()];
        });
    }

    /**
     * Mark every currently-Pending assignment in this session Absent, in
     * one transaction. Idempotent: a second call with nothing left pending
     * returns 'nothing_pending' and mutates nothing.
     *
     * @return array{result: string, count: int}
     */
    public function markAllRemainingAbsent(DutySession $session, User $actor): array
    {
        return DB::transaction(function () use ($session, $actor) {
            $lockedSession = DutySession::whereKey($session->id)->lockForUpdate()->first();

            if (! $lockedSession->isActive()) {
                return ['result' => 'session_not_active', 'count' => 0];
            }

            $pending = DutyAssignment::where('duty_session_id', $session->id)
                ->where('current_status', 'pending')
                ->lockForUpdate()
                ->get(['id', 'khidmatguzar_id']);

            if ($pending->isEmpty()) {
                return ['result' => 'nothing_pending', 'count' => 0];
            }

            $now = now();

            DutyAssignment::whereIn('id', $pending->pluck('id'))->update([
                'current_status' => 'absent',
                'attendance_marked_at' => $now,
                'attendance_marked_by' => $actor->id,
            ]);

            AttendanceEvent::insert($pending->map(fn ($a) => [
                'duty_assignment_id' => $a->id,
                'duty_session_id' => $session->id,
                'khidmatguzar_id' => $a->khidmatguzar_id,
                'action' => 'absent',
                'context' => 'bulk',
                'performed_by' => $actor->id,
                'performed_at' => $now,
                'remark' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());

            return ['result' => 'marked', 'count' => $pending->count()];
        });
    }

    /**
     * Active -> Closing -> Closed in one transaction. 'Closing' exists only
     * as a transient in-transaction lock state here: if Pending assignments
     * remain, an exception forces the whole transaction (including the
     * transient status write) to roll back, leaving the session exactly as
     * it was — still Active.
     *
     * @return array{result: string, pending_count?: int, session?: DutySession}
     */
    public function closeSession(DutySession $session, User $actor): array
    {
        try {
            return DB::transaction(function () use ($session, $actor) {
                $lockedSession = DutySession::whereKey($session->id)->lockForUpdate()->first();

                if ($lockedSession->status !== 'active') {
                    return ['result' => 'invalid_state'];
                }

                $lockedSession->update(['status' => 'closing']);

                $pendingCount = DutyAssignment::where('duty_session_id', $session->id)
                    ->where('current_status', 'pending')
                    ->lockForUpdate()
                    ->count();

                if ($pendingCount > 0) {
                    throw new SessionHasPendingAssignmentsException($pendingCount);
                }

                $lockedSession->update([
                    'status' => 'closed',
                    'closed_at' => now(),
                    'closed_by' => $actor->id,
                    'is_reopened_for_correction' => false,
                ]);

                SessionReopenEvent::where('duty_session_id', $session->id)
                    ->whereNull('closed_at')
                    ->update(['closed_at' => now()]);

                return ['result' => 'closed', 'session' => $lockedSession->fresh()];
            });
        } catch (SessionHasPendingAssignmentsException $e) {
            return ['result' => 'pending_remain', 'pending_count' => $e->pendingCount];
        }
    }

    /**
     * Rule 6: reopening a Closed session requires the reopen_sessions
     * permission (Admin always has it; an Admin may also grant it to a
     * specific Operator/Viewer without a new role) — only for correction.
     * This never resets status to draft/pending, it goes straight back to
     * 'active' so the existing attendance state machine (and every existing
     * mutation guard) applies unchanged. The reason is mandatory and
     * recorded immutably; a correction made after reopening is just a
     * normal AttendanceEvent, never a rewrite of the original one. The
     * route itself is already gated by the same permission — this check is
     * deliberate defense-in-depth, since this method is also callable
     * directly by other code, not just the HTTP layer.
     *
     * @return array{result: string, session?: DutySession}
     */
    public function reopenSession(DutySession $session, User $actor, string $reason, ?string $detail = null): array
    {
        if (! $actor->hasPermission('reopen_sessions')) {
            return ['result' => 'forbidden'];
        }

        return DB::transaction(function () use ($session, $actor, $reason, $detail) {
            $lockedSession = DutySession::whereKey($session->id)->lockForUpdate()->first();

            if ($lockedSession->status !== 'closed') {
                return ['result' => 'invalid_state'];
            }

            $now = now();

            $lockedSession->update([
                'status' => 'active',
                'is_reopened_for_correction' => true,
                'reopened_at' => $now,
                'reopened_by' => $actor->id,
            ]);

            SessionReopenEvent::create([
                'duty_session_id' => $session->id,
                'reopened_by' => $actor->id,
                'reason' => $reason,
                'detail' => $detail,
                'reopened_at' => $now,
            ]);

            return ['result' => 'reopened', 'session' => $lockedSession->fresh()];
        });
    }

    /**
     * Extra Present for a Khidmatguzar already known to the master but not
     * scheduled in this session. Rule 4/5: Gender is mandatory on every
     * Extra Present; an existing master Gender is never silently
     * overwritten by this form, only backfilled if it was blank.
     *
     * @return array{result: string, extraPresent?: ExtraPresent}
     */
    public function markExtraPresentKnown(DutySession $session, Khidmatguzar $khidmatguzar, Department $department, string $gender, User $actor, ?string $remark = null): array
    {
        return DB::transaction(function () use ($session, $khidmatguzar, $department, $gender, $actor, $remark) {
            $lockedSession = DutySession::whereKey($session->id)->lockForUpdate()->first();

            if (! $lockedSession->isActive()) {
                return ['result' => 'session_not_active'];
            }

            if (! $this->departmentInSession($session, $department)) {
                return ['result' => 'invalid_department'];
            }

            // Rule 9: lock this Khidmatguzar's row before checking for a
            // scheduled assignment — DutyListImportService::commit() takes
            // the same lock before creating one, so the two paths cannot
            // race each other into a "both scheduled and extra" state.
            $lockedKhidmatguzar = Khidmatguzar::whereKey($khidmatguzar->id)->lockForUpdate()->first();

            if (! $lockedKhidmatguzar->gender) {
                $lockedKhidmatguzar->update(['gender' => $gender]);
            }

            if (DutyAssignment::where('duty_session_id', $session->id)->where('khidmatguzar_id', $lockedKhidmatguzar->id)->exists()) {
                return ['result' => 'now_scheduled'];
            }

            if ($existing = ExtraPresent::where('duty_session_id', $session->id)->where('khidmatguzar_id', $lockedKhidmatguzar->id)->first()) {
                return ['result' => 'already_extra', 'extraPresent' => $existing];
            }

            return $this->insertExtraPresent($session, $lockedKhidmatguzar, $department, $actor, $remark);
        });
    }

    /**
     * Extra Present for an ITS Number not present anywhere in the
     * Khidmatguzar master — creates the master record too, in the same
     * transaction. ITS uniqueness at the DB level is the final race guard.
     *
     * @return array{result: string, extraPresent?: ExtraPresent, khidmatguzar?: Khidmatguzar}
     */
    public function markExtraPresentNew(DutySession $session, string $itsId, string $fullName, string $gender, Department $department, User $actor, ?string $remark = null): array
    {
        return DB::transaction(function () use ($session, $itsId, $fullName, $gender, $department, $actor, $remark) {
            $lockedSession = DutySession::whereKey($session->id)->lockForUpdate()->first();

            if (! $lockedSession->isActive()) {
                return ['result' => 'session_not_active'];
            }

            if (! $this->departmentInSession($session, $department)) {
                return ['result' => 'invalid_department'];
            }

            try {
                $khidmatguzar = Khidmatguzar::create(['its_id' => $itsId, 'full_name' => $fullName, 'gender' => $gender]);
            } catch (QueryException $e) {
                if (! $this->isDuplicateKeyError($e)) {
                    throw $e;
                }
                // Another operator created this ITS concurrently — reuse it,
                // never a duplicate master identity.
                $khidmatguzar = Khidmatguzar::where('its_id', $itsId)->firstOrFail();
            }

            // Rule 9 — see markExtraPresentKnown() for why this lock matters.
            $khidmatguzar = Khidmatguzar::whereKey($khidmatguzar->id)->lockForUpdate()->first();

            if (DutyAssignment::where('duty_session_id', $session->id)->where('khidmatguzar_id', $khidmatguzar->id)->exists()) {
                return ['result' => 'now_scheduled', 'khidmatguzar' => $khidmatguzar];
            }

            if ($existing = ExtraPresent::where('duty_session_id', $session->id)->where('khidmatguzar_id', $khidmatguzar->id)->first()) {
                return ['result' => 'already_extra', 'extraPresent' => $existing, 'khidmatguzar' => $khidmatguzar];
            }

            $result = $this->insertExtraPresent($session, $khidmatguzar, $department, $actor, $remark);
            $result['khidmatguzar'] = $khidmatguzar;

            return $result;
        });
    }

    private function applyPresent(DutyAssignment $assignment, User $actor, ?string $remark): void
    {
        $assignment->update([
            'current_status' => 'present',
            'attendance_marked_at' => now(),
            'attendance_marked_by' => $actor->id,
        ]);

        AttendanceEvent::create([
            'duty_assignment_id' => $assignment->id,
            'duty_session_id' => $assignment->duty_session_id,
            'khidmatguzar_id' => $assignment->khidmatguzar_id,
            'action' => 'present',
            'performed_by' => $actor->id,
            'performed_at' => now(),
            'remark' => $remark,
        ]);
    }

    /**
     * @return array{result: string, extraPresent?: ExtraPresent}
     */
    private function insertExtraPresent(DutySession $session, Khidmatguzar $khidmatguzar, Department $department, User $actor, ?string $remark): array
    {
        try {
            $extraPresent = ExtraPresent::create([
                'duty_session_id' => $session->id,
                'khidmatguzar_id' => $khidmatguzar->id,
                'its_id_snapshot' => $khidmatguzar->its_id,
                'full_name_snapshot' => $khidmatguzar->full_name,
                'department_id' => $department->id,
                'department_name_snapshot' => $department->name,
                'marked_by' => $actor->id,
                'marked_at' => now(),
                'remark' => $remark,
            ]);
        } catch (QueryException $e) {
            if (! $this->isDuplicateKeyError($e)) {
                throw $e;
            }

            $existing = ExtraPresent::where('duty_session_id', $session->id)->where('khidmatguzar_id', $khidmatguzar->id)->firstOrFail();

            return ['result' => 'already_extra', 'extraPresent' => $existing];
        }

        return ['result' => 'marked', 'extraPresent' => $extraPresent];
    }

    private function departmentInSession(DutySession $session, Department $department): bool
    {
        return DutyAssignment::where('duty_session_id', $session->id)
            ->where('department_id', $department->id)
            ->exists();
    }

    private function isDuplicateKeyError(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
