# Report Builder: Bulk Present/Absent + Gender Filter

## Problem

`reports/builder` (`app/Http/Controllers/ReportController.php:176`) is a read-only,
filterable, cross-session attendance listing. Operators currently have no way to
correct or mark attendance from this screen — they must go to the live attendance
screen for each duty session individually. There is also no way to filter the
report by gender.

## Scope

1. Add a `gender` filter to the existing filter set (from, to, session, department,
   operator, status).
2. Add bulk "Mark Present" / "Mark Absent" actions to the results table: one
   checkbox per row, one "select all" checkbox, two action buttons.

## Design

### Gender filter

- Options come from `Khidmatguzar::whereNotNull('gender')->distinct()->pluck('gender')`
  — not hardcoded — so it always matches whatever values actually exist in the data.
- Wired into `ReportService::attendanceDetailQuery()` and `attendanceDetailTotals()`
  as an additional `->when($filters['gender'] ?? null, ...)` clause. Both queries
  currently join `duty_sessions` only; they gain a join to `khidmatguzars` (via
  `duty_assignments.khidmatguzar_id`) to filter on `khidmatguzars.gender`.
- `resolveBuilderFilters()` in the controller gains `'gender' => $request->query('gender')`.

### Row selection

- Each result row gets `<input type="checkbox" name="assignment_ids[]" value="{id}">`.
- A single header checkbox (JS) toggles all currently-visible row checkboxes —
  page-scope only, not "select all matching filters across all pages".
- A row whose `duty_session.status !== 'active'` renders its checkbox `disabled`
  with a tooltip ("session closed") — bulk actions never touch closed sessions,
  matching the rule already enforced everywhere attendance is written
  (`AttendanceService::markPresent`/`markAbsent`, both check `isActive()`).

### Bulk actions

- Two buttons below the table: "Mark Present", "Mark Absent". Disabled until at
  least one checkbox is checked (JS). Each submits a POST with the checked
  `assignment_ids[]`.
- Two new routes, gated by `can:mark_attendance` (in addition to the existing
  `can:build_reports` gate on the page itself — viewing the report and acting on
  it are separate permissions):
  - `POST /reports/builder/mark-present` → `ReportController::builderMarkPresent`
  - `POST /reports/builder/mark-absent` → `ReportController::builderMarkAbsent`
- Selected assignment IDs are grouped by `duty_session_id` (a filtered page can
  span multiple sessions/dates) and each group is passed to the existing
  `AttendanceService::markPresentMany()` / a new `markAbsentMany()` (mirroring
  `markPresentMany`'s structure) for that session.
- Absent correction rule (unchanged from the live screen): before any writes,
  if any selected assignment is currently `present` and the actor lacks
  `correct_attendance`, the **entire** bulk-absent request is rejected — no
  partial application. This check spans all session-groups in the request, not
  just one.
- A session-group is skipped (not silently dropped — reported back) if that
  session is not active, in case of a race between page load and submission.
- Response: redirect back to `reports.builder` with the current query string
  preserved, plus a flash summary (marked / skipped-closed / already-that-status
  / correction-blocked counts).

## Out of scope

- "Select all across all pages" (only current page's checked rows are acted on).
- Changing the `build_reports` / `mark_attendance` permission definitions
  themselves — only reusing them.
- Any change to the live attendance screen or its existing bulk-absent-all
  endpoint.

## Testing

- Feature test: gender filter narrows results correctly.
- Feature test: bulk present marks selected pending rows present, skips rows in
  closed sessions, skips rows in inactive sessions gracefully.
- Feature test: bulk absent on a mix of pending + present rows is rejected
  wholesale for an actor without `correct_attendance`, and succeeds for one with it.
- Feature test: `mark_attendance` permission is required to hit the two new
  routes at all (403 without it, independent of `build_reports`).
