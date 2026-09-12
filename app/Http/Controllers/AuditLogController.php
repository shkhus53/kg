<?php

namespace App\Http\Controllers;

use App\Models\AttendanceEvent;
use App\Models\Department;
use App\Models\DutySession;
use App\Models\ImportBatch;
use App\Models\MasterDataChangeLog;
use App\Models\SessionReopenEvent;
use App\Models\SyncedEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Admin Audit Log foundation (Rule 6 + Phase 1 requirement): a single,
 * filterable, reconstructable timeline. Historical events are never
 * rewritten here — this only reads AttendanceEvent and SessionReopenEvent,
 * both append-only.
 */
class AuditLogController extends Controller
{
    private const PER_PAGE = 30;

    /**
     * date_from/date_to arrive as plain Y-m-d strings from the date picker,
     * meant as operational (IST) calendar dates — "everything that happened
     * on 12 September, IST". The timestamp columns filtered against
     * (performed_at, reopened_at, last_attempted_at) are written in UTC
     * (config('app.timezone') never changes for storage — see
     * config/app.php 'operational_timezone'). whereDate() on those columns
     * would compare the UTC calendar date instead, silently dropping any
     * event in the ~5:30 window where the IST and UTC dates disagree
     * (e.g. an event at 01:00 IST is 19:30 UTC the PREVIOUS day). Converting
     * the IST day boundary to its UTC instant first, then comparing the raw
     * timestamp, is correct regardless of which side of midnight UTC the
     * event actually falls on.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function istDateBoundsToUtc(array $filters): array
    {
        $tz = config('app.operational_timezone');

        $from = $filters['date_from']
            ? Carbon::parse($filters['date_from'], $tz)->startOfDay()->setTimezone('UTC')
            : null;

        $to = $filters['date_to']
            ? Carbon::parse($filters['date_to'], $tz)->endOfDay()->setTimezone('UTC')
            : null;

        return [$from, $to];
    }

    public function index(Request $request): View
    {
        $filters = [
            'session_id' => $request->query('session_id'),
            'department_id' => $request->query('department_id'),
            'operator_id' => $request->query('operator_id'),
            'q' => trim((string) $request->query('q', '')),
            'action' => $request->query('action'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
        ];

        $events = $this->attendanceEvents($filters);
        $reopens = $this->reopenEvents($filters);
        $syncIssues = $this->syncIssueEvents($filters);
        $imports = $this->importEvents($filters);
        $masterDataChanges = $this->masterDataEvents($filters);

        $merged = $events->concat($reopens)->concat($syncIssues)->concat($imports)->concat($masterDataChanges)->sortByDesc('timestamp')->values();

        $page = max(1, (int) $request->query('page', 1));
        $totalPages = max(1, (int) ceil($merged->count() / self::PER_PAGE));

        return view('audit.index', [
            'events' => $merged->forPage($page, self::PER_PAGE)->values(),
            'page' => $page,
            'totalPages' => $totalPages,
            'filters' => $filters,
            'sessions' => DutySession::orderByDesc('date')->get(['id', 'name']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'operators' => User::whereIn('role', ['admin', 'operator'])->orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function attendanceEvents(array $filters)
    {
        $query = AttendanceEvent::query()
            ->with(['dutyAssignment.department', 'khidmatguzar', 'dutySession', 'performedBy']);

        if ($filters['session_id']) {
            $query->where('duty_session_id', $filters['session_id']);
        }
        if ($filters['department_id']) {
            $query->whereHas('dutyAssignment', fn ($q) => $q->where('department_id', $filters['department_id']));
        }
        if ($filters['operator_id']) {
            $query->where('performed_by', $filters['operator_id']);
        }
        if ($filters['action'] && $filters['action'] !== 'session_reopened') {
            $query->where('action', $filters['action']);
        }
        if ($filters['q'] !== '') {
            $q = $filters['q'];
            $query->whereHas('khidmatguzar', fn ($k) => $k->where('its_id', 'like', "%{$q}%")->orWhere('full_name', 'like', "%{$q}%"));
        }
        [$from, $to] = $this->istDateBoundsToUtc($filters);
        if ($from) {
            $query->where('performed_at', '>=', $from);
        }
        if ($to) {
            $query->where('performed_at', '<=', $to);
        }
        if ($filters['action'] === 'session_reopened') {
            return collect();
        }

        return $query->orderByDesc('performed_at')->limit(500)->get()->map(fn (AttendanceEvent $e) => [
            'timestamp' => $e->performed_at,
            'type' => $e->action,
            'description' => ucfirst($e->action).' — '.($e->khidmatguzar->full_name ?? 'Unknown').' (ITS '.($e->khidmatguzar->its_id ?? '—').')'.($e->context === 'bulk' ? ' (bulk)' : ''),
            'actor' => $e->performedBy->name ?? '—',
            'session' => $e->dutySession->name ?? '—',
            'department' => $e->dutyAssignment->department->name ?? null,
            'remark' => $e->remark,
        ]);
    }

    private function reopenEvents(array $filters)
    {
        // Reopen events have no person/department dimension — excluded once
        // that kind of filter narrows the search, rather than shown out of
        // context.
        if ($filters['department_id'] || $filters['q'] !== '') {
            return collect();
        }
        if ($filters['action'] && $filters['action'] !== 'session_reopened') {
            return collect();
        }

        $query = SessionReopenEvent::query()->with(['dutySession', 'reopenedBy']);

        if ($filters['session_id']) {
            $query->where('duty_session_id', $filters['session_id']);
        }
        if ($filters['operator_id']) {
            $query->where('reopened_by', $filters['operator_id']);
        }
        [$from, $to] = $this->istDateBoundsToUtc($filters);
        if ($from) {
            $query->where('reopened_at', '>=', $from);
        }
        if ($to) {
            $query->where('reopened_at', '<=', $to);
        }

        return $query->orderByDesc('reopened_at')->limit(200)->get()->map(fn (SessionReopenEvent $r) => [
            'timestamp' => $r->reopened_at,
            'type' => 'session_reopened',
            'description' => 'Session reopened — '.$r->reasonLabel().($r->detail ? ': '.$r->detail : ''),
            'actor' => $r->reopenedBy->name ?? '—',
            'session' => $r->dutySession->name ?? '—',
            'department' => null,
            'remark' => null,
        ]);
    }

    /**
     * Phase 2: offline sync conflicts/rejections/operator-mismatches must be
     * visible here (never silently discarded). 'accepted'/'duplicate' are
     * skipped — those are already represented by the resulting
     * AttendanceEvent/ExtraPresent, showing them again would be noise.
     * 'retryable_failure' is deliberately excluded too — transient by
     * design, resolves on its own retry.
     */
    private function syncIssueEvents(array $filters)
    {
        if ($filters['department_id'] || $filters['q'] !== '') {
            return collect();
        }
        if ($filters['action'] && $filters['action'] !== 'sync_issue') {
            return collect();
        }

        $query = SyncedEvent::query()->whereIn('last_result', ['conflict', 'rejected', 'operator_mismatch']);

        if ($filters['session_id']) {
            $query->where('duty_session_id', $filters['session_id']);
        }
        if ($filters['operator_id']) {
            $query->where('synced_by_user_id', $filters['operator_id']);
        }
        [$from, $to] = $this->istDateBoundsToUtc($filters);
        if ($from) {
            $query->where('last_attempted_at', '>=', $from);
        }
        if ($to) {
            $query->where('last_attempted_at', '<=', $to);
        }

        $sessionNames = DutySession::pluck('name', 'id');

        return $query->orderByDesc('last_attempted_at')->limit(200)->get()->map(fn (SyncedEvent $s) => [
            'timestamp' => $s->last_attempted_at,
            'type' => 'sync_issue',
            'description' => 'Offline sync '.str_replace('_', ' ', $s->last_result).' ('.$s->action.'): '.($s->detail ?? 'no detail'),
            'actor' => $s->syncedBy->name ?? '—',
            'session' => $sessionNames[$s->duty_session_id] ?? '—',
            'department' => null,
            'remark' => null,
        ]);
    }

    /**
     * Duty-list import history, surfaced centrally rather than only via the
     * separate Import Center — ImportBatch rows are append-only (one row
     * per upload, never updated after creation), so this is genuine
     * historical data, not a fabricated event. Has no department dimension
     * (an import batch spans every department in the file), so — like
     * reopen/sync-issue events above — excluded once a department filter or
     * ITS/name search narrows the view to something an import row can't
     * meaningfully match.
     */
    private function importEvents(array $filters)
    {
        if ($filters['department_id'] || $filters['q'] !== '') {
            return collect();
        }
        if ($filters['action'] && $filters['action'] !== 'import') {
            return collect();
        }

        $query = ImportBatch::query()->with(['dutySession', 'uploadedBy']);

        if ($filters['session_id']) {
            $query->where('duty_session_id', $filters['session_id']);
        }
        if ($filters['operator_id']) {
            $query->where('uploaded_by', $filters['operator_id']);
        }
        [$from, $to] = $this->istDateBoundsToUtc($filters);
        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }

        return $query->orderByDesc('created_at')->limit(200)->get()->map(fn (ImportBatch $b) => [
            'timestamp' => $b->created_at,
            'type' => 'import',
            'description' => 'Duty list imported — '.$b->original_filename.' ('.ucfirst($b->status).', '.$b->valid_rows.' valid / '.$b->total_rows.' rows)',
            'actor' => $b->uploadedBy->name ?? '—',
            'session' => $b->dutySession->name ?? '—',
            'department' => null,
            'remark' => null,
        ]);
    }

    /**
     * Master-data (Department/Miqaat/Event/Venue) field changes, read from
     * the existing append-only MasterDataChangeLog rather than a second
     * audit mechanism. Has no session/department-of-attendance dimension of
     * its own (entity_type/entity_id are the master record itself, e.g. a
     * Department row being edited — not a session's department), so
     * excluded once a session or department filter narrows the view, same
     * as the other session-less categories above.
     */
    private function masterDataEvents(array $filters)
    {
        if ($filters['session_id'] || $filters['department_id'] || $filters['q'] !== '') {
            return collect();
        }
        if ($filters['action'] && $filters['action'] !== 'master_data_change') {
            return collect();
        }

        $query = MasterDataChangeLog::query()->with('changedBy');

        if ($filters['operator_id']) {
            $query->where('changed_by', $filters['operator_id']);
        }
        [$from, $to] = $this->istDateBoundsToUtc($filters);
        if ($from) {
            $query->where('changed_at', '>=', $from);
        }
        if ($to) {
            $query->where('changed_at', '<=', $to);
        }

        return $query->orderByDesc('changed_at')->limit(200)->get()->map(fn (MasterDataChangeLog $c) => [
            'timestamp' => $c->changed_at,
            'type' => 'master_data_change',
            'description' => ucfirst($c->entity_type).' #'.$c->entity_id.' — '.$c->field.' changed from "'.($c->old_value ?? '—').'" to "'.($c->new_value ?? '—').'"',
            'actor' => $c->changedBy->name ?? '—',
            'session' => '—',
            'department' => null,
            'remark' => null,
        ]);
    }
}
