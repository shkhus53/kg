<?php

namespace App\Http\Controllers;

use App\Models\AttendanceEvent;
use App\Models\DutySession;
use App\Models\SyncedEvent;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Session Command Center (Phase 3): a live-updating operational summary for
 * one Duty Session. Polling-based, per the approved scope — no websocket
 * infrastructure. Every number here comes from the exact same formulas the
 * rest of the app already uses (ReportService::sessionCounters/
 * departmentBreakdown) — this controller computes nothing of its own beyond
 * the recent-activity feed and the offline-sync "attention" count, both of
 * which are plain reads of existing tables (AttendanceEvent, SyncedEvent).
 *
 * Access is intentionally as broad as the existing session/analytics
 * screens (any authenticated role) — no new permission was introduced,
 * matching the standing "keep current broad visibility" decision.
 *
 * Known limitation (documented, not implemented): there is no
 * device/operator connectivity panel here. That would require devices to
 * report their local queue state to the server (a heartbeat), which this
 * phase does not add — the server has no visibility into another device's
 * IndexedDB queue.
 */
class CommandCenterController extends Controller
{
    private const RECENT_ACTIVITY_LIMIT = 15;

    public function __construct(private readonly ReportService $reports) {}

    public function show(DutySession $dutySession): View
    {
        return view('sessions.command-center', [
            'dutySession' => $dutySession,
            ...$this->snapshot($dutySession),
        ]);
    }

    public function data(DutySession $dutySession): JsonResponse
    {
        return response()->json($this->snapshot($dutySession));
    }

    /**
     * @return array{counters:array,departments:Collection,recentActivity:Collection,attention:array}
     */
    private function snapshot(DutySession $dutySession): array
    {
        return [
            'counters' => $this->reports->sessionCounters($dutySession),
            'departments' => $this->reports->departmentBreakdown(sessionId: $dutySession->id),
            'recentActivity' => $this->recentActivity($dutySession),
            'attention' => $this->attention($dutySession),
        ];
    }

    private function recentActivity(DutySession $dutySession)
    {
        return AttendanceEvent::where('duty_session_id', $dutySession->id)
            ->with(['khidmatguzar:id,its_id,full_name', 'performedBy:id,name', 'dutyAssignment:id,department_id', 'dutyAssignment.department:id,name'])
            ->orderByDesc('performed_at')
            ->limit(self::RECENT_ACTIVITY_LIMIT)
            ->get()
            ->map(fn (AttendanceEvent $e) => [
                'timestamp' => $e->performed_at->toIst()->format('H:i'),
                'description' => ucfirst($e->action).' — '.($e->khidmatguzar->full_name ?? 'Unknown').($e->dutyAssignment?->department ? ' ('.$e->dutyAssignment->department->name.')' : ''),
                'actor' => $e->performedBy->name ?? '—',
                'action' => $e->action,
            ]);
    }

    /**
     * @return array{count:int,conflict:int,rejected:int,operator_mismatch:int}
     */
    private function attention(DutySession $dutySession): array
    {
        $rows = SyncedEvent::where('duty_session_id', $dutySession->id)
            ->whereIn('last_result', ['conflict', 'rejected', 'operator_mismatch'])
            ->selectRaw('last_result, COUNT(*) as c')
            ->groupBy('last_result')
            ->pluck('c', 'last_result');

        return [
            'count' => (int) $rows->sum(),
            'conflict' => (int) ($rows['conflict'] ?? 0),
            'rejected' => (int) ($rows['rejected'] ?? 0),
            'operator_mismatch' => (int) ($rows['operator_mismatch'] ?? 0),
        ];
    }
}
