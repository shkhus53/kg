<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Services\OfflineSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Phase 2 offline attendance: provisioning + batch sync. Both routes sit in
 * the existing session-cookie authenticated `web` group — no token/API
 * guard was introduced, since the client is always same-origin and the
 * app already exposes a CSRF token to it.
 */
class OfflineSyncController extends Controller
{
    public function __construct(private readonly OfflineSyncService $offlineSync) {}

    /**
     * A Closed session is never provisioned for offline use — there is
     * nothing to mark. Draft/active/an admin-reopened (still 'active')
     * session all provision normally.
     */
    public function provision(DutySession $dutySession): JsonResponse
    {
        if ($dutySession->isClosed()) {
            return response()->json(['message' => 'This session is closed and cannot be provisioned for offline attendance.'], 422);
        }

        $assignments = DutyAssignment::where('duty_session_id', $dutySession->id)
            ->with(['khidmatguzar:id,its_id,full_name,gender', 'department:id,name'])
            ->get()
            ->map(fn (DutyAssignment $a) => [
                'assignment_id' => $a->id,
                'khidmatguzar_id' => $a->khidmatguzar_id,
                'its_id' => $a->khidmatguzar->its_id,
                'full_name' => $a->khidmatguzar->full_name,
                'department_id' => $a->department_id,
                'department_name' => $a->department->name,
                'block_name' => $a->block_name,
                'day' => $a->day,
                'day_alias' => $a->day_alias,
                'seat' => $a->seat,
                'current_status' => $a->current_status,
            ]);

        $departments = Department::whereIn('id', $assignments->pluck('department_id')->unique())
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'session' => [
                'id' => $dutySession->id,
                'name' => $dutySession->name,
                'date' => $dutySession->date->format('Y-m-d'),
                'status' => $dutySession->status,
                'h_year' => $dutySession->h_year,
                'miqaat' => $dutySession->miqaat,
            ],
            'assignments' => $assignments->values(),
            'departments' => $departments,
            'package_version' => (string) Str::uuid(),
            'provisioned_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Batch sync. Each event is independently idempotent and independently
     * validated — one bad/duplicate event never aborts the others.
     */
    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'events' => ['required', 'array', 'max:200'],
            'events.*.event_id' => ['required', 'string'],
            'events.*.session_id' => ['nullable', 'integer'],
            'events.*.action' => ['required', 'string'],
        ]);

        $results = $this->offlineSync->processBatch($validated['events'], $request->user());

        return response()->json(['results' => $results]);
    }
}
