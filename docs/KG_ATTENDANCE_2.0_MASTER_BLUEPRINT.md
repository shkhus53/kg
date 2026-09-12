# KG Attendance 2.0 — Master Blueprint

Read-only analysis and specification. No files modified. Built on top of the prior [full audit](KG_ATTENDANCE_2.0_AUDIT.md) and [software analysis](SOFTWARE_ANALYSIS.md), re-validated against current code where it matters for design decisions (tailwind.config.js, app.css, bottom-nav/badge/stat-card components read directly for this pass).

Legend used throughout: **[CODE]** = confirmed by reading the repository. **[RULE]** = your finalized business rule/decision. **[DESIGN]** = recommended design, not yet built. **[INFERENCE]** = architectural judgment call, flagged for review. **[QUESTION]** = genuinely unresolved, listed in Section 41.

---

## 1. Executive Summary

KG Attendance today is a well-built, narrow-scope Laravel app: transactional attendance engine, snapshot-on-write history, single-source-of-truth reporting, minimal PWA, thin Android WebView wrapper. It has no offline capability, no realtime UI, no admin audit surface, no reopen workflow, and one real data-integrity hole (`assignment_fingerprint` uniqueness). 2.0 keeps everything that already works and adds four genuinely new subsystems — **Offline Attendance**, **Realtime Session Command Center**, **Admin Audit Center**, **Permissions abstraction** — plus a visual evolution (motion, selective glass, drill-down analytics) that stays unmistakably KG Attendance: navy (`#0F1E3D`), white cards, slate borders, emerald/red/orange/blue/violet status tones, `rounded-2xl`/`3xl`, bottom-nav shell, mobile-first `max-w-md` container **[CODE: tailwind.config.js, badge.blade.php, stat-card.blade.php, bottom-nav.blade.php]**.

The single highest-leverage architectural decision in this blueprint: **offline attendance and realtime dashboard both need an event-sourced attendance layer** (append-only local + server event log, not just a `current_status` column) to be built correctly. The existing `AttendanceEvent` table **[CODE]** already is this — it's the accepted-and-recorded half of an event log. 2.0 extends the same shape to the client: a local event queue that mirrors the server's event log, reconciled by a sync engine that treats server `AttendanceEvent` rows as authoritative history rather than resolving conflicts by last-write-wins.

---

## 2. Current System Understanding

Re-validated (not re-derived from scratch — full detail lives in the [software analysis doc](SOFTWARE_ANALYSIS.md) and [audit doc](KG_ATTENDANCE_2.0_AUDIT.md)):

- Laravel 13 / PHP 8.3, Blade + Tailwind + light Alpine, **zero AJAX/fetch/Livewire/Turbo anywhere** **[CODE]** — every mutation is currently a full-page form POST + redirect. This is the biggest technical fact shaping 2.0: realtime and offline both require introducing a client-side data layer that doesn't exist today, in a codebase that has deliberately avoided one.
- Core entities: `DutySession` (draft→active→closing→closed, one-directional, no reopen) → `ImportBatch` → `DutyAssignment` (scheduling unit, `current_status` pending/present/absent) → `AttendanceEvent` (audit log). `ExtraPresent` is a parallel table. `Khidmatguzar`/`Department` are shared master records with snapshot columns on assignment/extra-present rows for historical accuracy **[CODE]**.
- `AttendanceService` wraps every mutation in `DB::transaction()` + `lockForUpdate()`, locking the `DutySession` row first, then the target `DutyAssignment` row(s) **[CODE]**.
- `ReportService` is the single query layer behind preview screens, PDF (dompdf), and Excel (maatwebsite/excel) — verified no divergence between the three outputs **[CODE]**.
- Auth is ITS-number based, roles are a flat `users.role` enum (admin/operator/viewer), enforced entirely by route-group middleware, no policy classes, no department scoping **[CODE]**.
- Known gaps carried forward from the prior audit, now reframed as 2.0 inputs: no unique DB constraint on `assignment_fingerprint`; no audit trail for master-data overwrites on import; Rule 9 (scheduled vs extra-present) has a narrow unlocked race; Extra Present form collects only ITS/Name/Department, no Gender; no session-reopen code path exists at all; `APP_DEBUG=true` ships as the `.env.example` default.
- Visual system, confirmed by direct file read for this pass: `navy` scale `950 #0B1730 / 900 #0F1E3D / 800 #152B52 / 700 #1D3A6B` **[CODE: tailwind.config.js]**, Figtree font, `rounded-3xl` custom radius, status tones `slate` (neutral/gray), `blue` (info), `emerald` (green/success/present), `orange` (warning/pending), `violet` (purple/extra), `red` (danger/absent) **[CODE: badge.blade.php, stat-card.blade.php]**, white surfaces on `slate-50` background with `border-slate-100/200`, `shadow-sm`/`shadow-lg`, bottom-nav is a fixed white bar with a raised emerald circular FAB for the primary action **[CODE: bottom-nav.blade.php]**.

---

## 3. Existing Architecture

Unchanged from the prior audit's Section 2 — see [SOFTWARE_ANALYSIS.md](SOFTWARE_ANALYSIS.md) for the full breakdown (models, migrations, routes, controllers, services, views, Android, PWA). Restated only where it constrains 2.0 decisions:

- **No API layer exists.** Every route returns Blade views or redirects. 2.0's offline sync and realtime dashboard both require a genuine JSON API surface (versioned, authenticated, distinct from the Blade routes) that does not exist today — this is new infrastructure, not a modification of existing routes.
- **No background job/queue usage found** beyond the scaffolded `jobs`/`failed_jobs` tables (unused) **[CODE]**. Realtime broadcasting (Laravel Echo/Reverb/Pusher-style) and any async sync-processing will be the first real use of Laravel's queue system in this app.
- **Android wrapper is a WebView, not a native shell with its own storage** **[CODE: MainActivity.java]** — offline storage must live in the browser/WebView layer (IndexedDB), not in a native Android database, unless the Android app itself is upgraded (out of scope per your instruction not to force a native rewrite).

---

## 4. Business Rule Validation

Your ten finalized rules (Section 4 of your prompt) validated against actual code, updated from the prior audit now that Rules 3, 4/5, and 6 have final answers:

| # | Rule | Code status today | 2.0 requirement |
|---|---|---|---|
| 1 | Multi-department per person | **[CODE] Implemented**, no unique constraint blocks it (correct — it shouldn't). | No change needed. |
| 2 | Multi-file import merge | **[CODE] Implemented**, but `commit()` has no DB-level dedup backstop. | **[P0]** Add unique constraint on `(duty_session_id, assignment_fingerprint)`. |
| 3 | Latest non-blank wins | **[CODE] Partially wrong today** — `updateOrCreate` currently overwrites with blanks (nulls out good data). Your rule is explicit: blank cell must NOT overwrite. | **[P0]** Change `DutyListImportService::commit()` to skip null/blank fields per-column when updating an existing `Khidmatguzar`, and log the diff (see Section 12). |
| 4/5 | Extra Present requires ITS+Name+Gender+Department (Idara=Department), ITS mandatory | **[CODE] Gender is entirely missing from the form/validation/model write today.** "Department" is already the correct field name in code (`department_id`) — no renaming needed, your "Idara means Department" note maps directly onto the existing schema. | **[P0]** Add `gender` to the Extra Present validation rules, form, and `AttendanceService::markExtraPresentKnown/New` writes (column already exists and is nullable on `khidmatguzars` — no migration needed for storage, only for making it required at the app layer). |
| 6 | Admin-only reopen, reason required, no reset to Pending, fully auditable | **[CODE] Does not exist at all.** | **[P1]** New feature — full design in Section 19 (state machine) and Section 6 (Admin Reopen/Correction). |
| 7 | 1–5 concurrent operators, current locking sufficient | **[CODE] Confirmed sufficient** — the `DutySession`-row-lock-first pattern only becomes a throughput concern at 10–20+ concurrent operators (measured against your stated 1–5 ceiling with wide margin). **No concurrency re-architecture needed.** | No change. Do not build a more granular locking scheme — it would be solving a problem that doesn't exist at your stated scale. |
| 8 | Multiple assignments shown, operator picks | **[CODE] Correctly implemented** — verified in `AttendanceController::live` + `attendance/live.blade.php`, each assignment its own checkbox row. | Carry forward unchanged into 2.0's redesigned live-attendance screen. |
| 9 | Scheduled/Extra-Present mutual exclusion | **[CODE] App-level only**, narrow race exists between import-commit and extra-present-marking (neither locks against the other). | **[P0]** Close via consistent locking — see Section 13 (Data Integrity Engine). |
| 10 | Present ÷ Scheduled × 100, Extra excluded | **[CODE] Confirmed consistent across all 13 calculation sites found** (ReportService, AnalyticsController, DutySessionController, live.blade.php). | Preserve exactly. Every new analytics/dashboard surface in 2.0 must reuse the existing calculation, never reimplement it. |

---

## 5. Product Vision

KG Attendance 2.0 is the same tool, faster and more trustworthy under real field conditions: an operator can mark attendance with zero network and trust it will land correctly later; an admin can see a session's live state without refreshing, investigate any disputed record down to the exact event, and correct a closed session without losing the original trail. Nothing about the tool's scope changes — one organization, one attendance model, Present/Scheduled as the only rate definition — the investment goes entirely into making that one job faster, more resilient, and more auditable, not into expanding what the product does.

---

## 6. Advanced Feature Architecture

For each Core 2.0 feature: purpose, what it touches, and its priority tier (cross-referenced to Section 37).

| Feature | Purpose | Touches | Priority |
|---|---|---|---|
| Ultra-Fast Attendance | Reduce the 3-page-load minimum for marking one person present; autofocus, inline feedback, faster repeat-search | `attendance/live.blade.php`, `AttendanceController` | P1 |
| Smart Assignment Resolution | Correctly surface and disambiguate multi-assignment people (already correct — polish only) | `attendance/live.blade.php` | P1 |
| Data Integrity Engine | Close the fingerprint/Rule-9/master-data gaps at the DB level, not just app level | migrations, `AttendanceService`, `DutyListImportService` | P0 |
| Admin Audit Center | Searchable, filterable global timeline of every attendance/master-data/session-lifecycle event | new `AnalyticsController`-sibling, new views | P1 |
| Intelligent Import Center | Diff-aware import preview (NEW/UPDATED/UNCHANGED/WARNING/ERROR), import history, blank-preservation | `DutyListImportService`, `imports/*.blade.php` | P1 |
| Drill-Down Analytics | Overview → Department → Status → Member → Assignment → Event navigation | `AnalyticsController`, new routes | P2 |
| Khidmatguzar 360° | Single-page complete person view (already exists as `analytics/profile` — extend, don't rebuild) | `AnalyticsController::profile`, `analytics/profile.blade.php` | P2 |
| Operator Activity Analytics | Operational (not leaderboard) stats per operator | new controller method, new view | P2 |
| Advanced Permissions | Replace hardcoded 3-role middleware with a real permission-abstraction layer | new `Permission`/`Role` layer, `EnsureUserHasRole` refactor | P1 |
| Admin Reopen/Correction | Rule 6 in full | `DutySessionController`, `AttendanceService`, new migration | P1 |
| Offline Attendance | Full offline queue + sync engine | new frontend data layer, new API routes, new sync tables | P1 (foundational — see Phase sequencing note in Section 38) |
| Realtime Session Command Center | Live-updating admin dashboard | Laravel broadcasting, new dashboard view | P2 |
| Offline Sync + Conflict Resolution | Server-side reconciliation of queued events | new sync API, new conflict-review UI | P1 (paired with Offline Attendance) |
| Device/Operator Connectivity Monitoring | Admin visibility into who/what is online/offline/syncing | piggybacks on sync heartbeat, new dashboard widget | P2 |
| Operational Alerts | Surface conflicts/anomalies needing attention, in-app only | dashboard widget, no push notifications | P2 |
| Department Intelligence | Department-level drill-down (largely exists in `analytics/departments`) | extend existing controller/view | P2 |
| Advanced Reporting | Saved filters, operator/audit/import/sync report types | `ReportService` extensions | P2 |
| Saved Report Configurations | Persist a user's report filter presets | new small table, new UI affordance | P3 |
| Import History/Diff | Show exactly what changed between imports | extends Intelligent Import Center | P1 |
| Attendance Trends | Session-over-session and department-over-time trend views | extends existing `analytics/overview` trend chart | P2 |
| Anomaly Detection | Flag only clearly justified patterns (e.g., same device marking implausibly many people in seconds) — not speculative ML | sync engine + simple threshold rules | P3 |

---

## 7. Offline Attendance Architecture

**[DESIGN]** — this is genuinely new infrastructure; nothing in the current codebase does anything like this.

### Conceptual flow (matches your Section 7 exactly, made concrete)

```
Server (Laravel)
  → GET /api/sessions/{id}/provision
      returns: session metadata, all DutyAssignments (with khidmatguzar/department
      snapshots), all ExtraPresent records, server clock timestamp, provisioning
      version token
  → client stores this in IndexedDB, scoped by session_id
Operator marks attendance while offline
  → write a local AttendanceQueueEvent row (IndexedDB), status = 'queued'
  → UI reflects it immediately as OFFLINE QUEUED (Section 18)
Connectivity returns
  → Sync Engine wakes (online event + periodic retry timer)
  → POST /api/sync/events  (batch of queued events)
  → Server validates each event against current server state
  → Server responds per-event: ACCEPTED | REJECTED(reason) | CONFLICT(details)
  → Client updates local event status accordingly
  → Conflicts surface to Admin (Section 15), never silently resolved by the device
```

### Local storage design

- **IndexedDB**, not `localStorage` — needed for structured queries (by session, by sync status) and larger capacity. One database per app origin (already true for this PWA), object stores: `provisionedAssignments`, `provisionedExtraPresents`, `queuedEvents`, `syncLog`.
- **`queuedEvents` schema** (per your required metadata list, Section 7):
  ```
  {
    local_event_id: uuid,        // client-generated, globally unique
    session_id, assignment_id (nullable — extra-present events have none),
    khidmatguzar_id,
    operator_user_id,
    device_id: uuid,             // generated once per browser/device, persisted in IndexedDB
    action: 'present' | 'absent' | 'extra_present',
    context: 'individual' | 'bulk',
    local_sequence_number: int,  // monotonic per-device counter, breaks timestamp ties
    local_timestamp: iso8601,    // device clock — untrusted, see Section 33
    sync_status: 'queued' | 'syncing' | 'synced' | 'conflict' | 'rejected',
    server_event_id: nullable,   // populated once accepted
    rejection_reason: nullable
  }
  ```
- **`device_id`** is generated once client-side on first load and persisted — this is the "device identity" your spec requires. It is *not* a security boundary (see Section 33) — it's an operational/debugging identifier ("which tablet queued this").

### Why not last-write-wins (your explicit requirement)

The server's `AttendanceEvent` table is already an append-only log **[CODE]** — the natural extension is to make sync **event-append, not state-overwrite**. A queued `present` event syncing against a `DutyAssignment` that the server shows as already `absent` (marked by another operator, or by admin correction, while this device was offline) is a genuine conflict, not something to silently resolve — it must be queued into a `SyncConflict` record for Admin review (Section 15), never auto-applied. The only case that auto-resolves is the trivial one: the event syncs against a still-`pending` assignment exactly as the device last saw it — that's the common case and applies immediately.

### Server-side validation on sync (authoritative)

For each incoming event, the server re-runs the **same `AttendanceService` transaction logic** used for online marking (lock the assignment row, check current state, apply the same business rules — Present→Absent still blocked, Rule 9 still enforced) — sync is not a separate code path with separate rules, it calls into the existing service. The only addition is: if the assignment's `current_status` has diverged from what the client's `local_timestamp`/last-known-state implies, mark it a conflict instead of applying blindly.

### Explicit answers to your required edge-case list (Section 7)

| Concern | Design answer |
|---|---|
| Pre-session provisioning | Operator explicitly opens a session while online at shift start; provisioning is a deliberate action (button: "Download session for offline use"), not automatic/background, so storage use and staleness are visible and controllable. |
| Data available offline | Full assignment list + snapshots + extra-present list for the *currently provisioned* session only — not all sessions ever, to bound storage. |
| Offline session lifetime | Provisioned data is valid until the operator re-provisions or the session closes server-side (discovered on next sync attempt) — no arbitrary TTL, but a "provisioned 6h ago, refresh recommended" banner past a threshold (e.g. 4 hours) so stale data is visible, not silent. |
| Changed session data while offline | If admin edits an assignment (rare — no such UI exists today, would be new in 2.0) while a device is offline, that device's local copy is stale; sync response for any event touching that assignment includes updated server state, surfaced to the operator as "this assignment changed since you went offline — review." |
| Duplicate event handling | `local_event_id` (client UUID) is idempotency key server-side — a resynced/retried event with the same ID is deduped, never double-applied. |
| Assignment changes | Covered above — surfaced as a soft conflict on sync, not blocked. |
| Session closure while offline | If admin closes the session while a device has queued events, those events sync against a `closed` session; server rejects them (mirrors today's "closed sessions block mutation" rule) and surfaces to admin as "N offline events could not be applied — session was closed" for manual review/reopen decision. |
| Correction events | Absent→Present correction queues exactly like any other event; same sync/conflict rules apply. |
| Operator/device identity | `operator_user_id` from the authenticated session (real identity, trusted); `device_id` is a soft operational label (untrusted, see Security). |
| Stale session handling | See "offline session lifetime" above. |
| Clock differences | `local_timestamp` is stored but **never trusted for ordering across devices** — `local_sequence_number` (per-device monotonic) orders events within one device's queue; cross-device ordering uses server-assigned `performed_at` at accept-time, exactly as today. |
| Failed sync | Event stays `queued`, retried with exponential backoff; after N failures, surfaced to the operator as "having trouble syncing — will keep trying" (not a dead-end error). |
| Retries | Automatic, backoff-based, resumable after app restart (queue lives in IndexedDB, survives page reload/app kill). |
| Conflicts | Never auto-resolved; queued for Admin (Section 15). |
| Admin review | Section 15/20 — dedicated Sync & Conflict Center. |
| Sync history | `syncLog` object store locally + server-side `AttendanceEvent`/new `SyncAttempt` table for audit. |
| Audit history | Every accepted sync event produces a normal `AttendanceEvent` row indistinguishable in the audit trail from an online-marked one, except a `source: 'offline_sync'` flag for traceability. |
| Storage limits | IndexedDB quota varies by browser/device; provisioning is scoped to one session at a time specifically to keep this bounded — a full-Miqaat multi-thousand-assignment session's provisioned payload should be tens of KB to low MB (JSON of assignment rows), not a concern at your stated 1–5 device scale. |
| Recovery after restart | IndexedDB persists across browser/app restarts by design — no additional recovery logic needed beyond re-reading the queue on boot. |

### Sync status the operator always sees (your Section 27 requirement)

`ONLINE` / `OFFLINE` / `SYNCING (n)` / `SYNC COMPLETE` / `CONFLICT (n)` as a persistent header badge — component spec in Section 27 (Component System) as **Connectivity Badge** / **Sync Status**.

---

## 8. Real-Time Architecture

**[DESIGN]** — first genuine realtime feature in this codebase.

**Transport**: Laravel's native broadcasting (Reverb, self-hosted, or Pusher-compatible) over WebSockets, scoped to **private channels per duty session** (`private-session.{id}`) — not a global firehose. Only the Session Command Center and a small connectivity-status widget subscribe; **[RULE — your Section 8 instruction]** everything else stays request/response, matching "do not make every page unnecessarily real-time."

**Events broadcast** (small, deliberate set, not "everything"):
- `AttendanceMarked` (assignment id, new status, department, operator) — drives live counters and recent-activity feed.
- `DeviceConnectivityChanged` (device id, operator, online/offline) — drives the connectivity panel.
- `SyncConflictRaised` (conflict id, summary) — drives the "attention needed" panel.
- `SessionStatusChanged` (draft/active/closing/closed/reopened) — drives session-wide UI state.

**What is *not* broadcast**: individual report generation, analytics drill-down state, import progress (poll-on-demand is sufficient there), anything on the Viewer role's read-only pages. This keeps the websocket surface small and battery/data-friendly on mobile per your performance constraints (Section 32).

**Reconnection UX** (your Section 26 requirement, verbatim pattern):
```
LIVE DISCONNECTED
Last updated 08:42
Trying to reconnect...
```
Normal page functionality (viewing already-loaded data, even marking attendance if online via the regular HTTP path) remains usable during a broadcast disconnect — the live layer is additive, never a blocking dependency.

**Why not poll instead of websockets**: polling every few seconds from up to 5 concurrent operator devices plus any admin dashboards open is a small, acceptable load either way at your stated scale — but websockets give sub-second feel for the Command Center's "live" promise at genuinely lower total request volume than aggressive polling, and Laravel Reverb removes the third-party-service dependency Pusher would introduce. **[INFERENCE]** — if self-hosting a websocket server is operationally undesirable, a 5-second poll on the Command Center only (not site-wide) is an acceptable fallback with no other architecture change.

---

## 9. Session Command Center

**[DESIGN]** — new top-level Admin screen, the most important new surface in 2.0.

Layout derived directly from existing component language (`stat-card`, `badge`, card grid) — not invented from scratch:

```
┌─────────────────────────────────────────┐
│ [Session Name]            [ACTIVE badge] │  ← page-header pattern, existing
├─────────────────────────────────────────┤
│  ATTENDANCE                               │
│  ┌───────────────────────────────────┐   │
│  │      82.4%          (glass ring)   │   │  ← new: Glass Metric Card, big
│  │  Present 1,284 · Absent 274 · 19   │   │     numeral, selective glass per
│  └───────────────────────────────────┘   │     Section 20 candidate list
│                                            │
│  DEPARTMENTS                              │
│  [stat-card compact ×N, tappable →drill]  │  ← reuses existing stat-card
│                                            │
│  LIVE OPERATIONS                          │
│  14 operators · 11 online · 2 offline     │  ← new: Connectivity Badge row
│  7 events syncing                         │
│                                            │
│  RECENT ACTIVITY                          │
│  08:41 Marked Present — Dept X   [live]   │  ← new: Live Activity Item list,
│  08:41 Import completed                   │     realtime-appended, subtle
│  08:42 Device came online                 │     fade-in (Section 26 motion)
│                                            │
│  ⚠ ATTENTION NEEDED                       │
│  3 sync conflicts →                       │  ← tappable, routes to Sync &
│  1 unusual pattern →                      │     Conflict Center (Section 15)
└─────────────────────────────────────────┘
```

Mobile: single column, exactly as above, scrollable. Desktop: department grid becomes a real grid (3-4 columns) plus a persistent right-rail for "Live Operations" + "Attention Needed" so they never scroll out of view during a live shift — this is the one screen where the desktop layout genuinely diverges from "stacked mobile," per your Section 22 instruction ("desktop should use available space," "command center layouts").

Update mechanism: realtime-pushed deltas (Section 8) animate counters (Section 19 motion — animated counter, GPU-friendly) rather than a full page re-render.

---

## 10. Drill-Down Analytics Architecture

**[DESIGN]**, extending the existing `AnalyticsController` rather than replacing it.

Hierarchy exactly as specified:

```
Overview  →  Department  →  Attendance Status  →  Member List  →  Khidmatguzar  →  Assignment  →  Attendance Event
```

Implementation approach: this is **routing + breadcrumb state, not new data**. `AnalyticsController::overview`/`departments`/`directory`/`profile` **[CODE, already exist]** already produce most of these levels individually; 2.0's job is to link them together with a consistent breadcrumb component and consistent query-param-based filtering (`?department=X&status=absent` flows into the member list, which flows into `/khidmatguzars/{id}` — already a real route **[CODE]**). The one genuinely new level is **Assignment → Attendance Event** — today `AttendanceEvent` rows are stored but never surfaced in any UI **[CODE — confirmed, no view reads this model directly]**; 2.0 adds a per-assignment event-history panel (small, inline on the Khidmatguzar 360° page, not a separate screen) showing the literal `AttendanceEvent` rows for that assignment.

**Breadcrumb component** (Section 27 component list — Drill-down Breadcrumb): sticky under the page header, shows the current path (`Overview / Finance / Absent / Ahmed Ali`), each segment tappable to jump back; on mobile collapses to `← Absent (3 of 4)` style with a back arrow rather than a full trail, since horizontal space is scarce — full trail on desktop/tablet-landscape.

---

## 11. Information Architecture

Building directly on your Section 38 skeleton, reconciled against actual current routes **[CODE: routes/web.php]**:

**Operator** (role: operator, matches `canManageSessions()` **[CODE]**):
- Home (dashboard — exists)
- Sessions (list — exists)
- Current/Active Session → Live Attendance (exists, gets 2.0 UX upgrade)
- Offline Queue (new — visible only when queued/conflicted events exist, otherwise absent from nav to avoid clutter)
- Profile/Device State (new small screen — device id, last sync, app version)

**Admin** (role: admin):
- Everything Operator has, plus:
- Session Command Center (new, becomes the primary landing surface for an active session, replacing the plain `sessions/show` as the "main" view when a session is active)
- Departments, Khidmatguzars/Analytics, Imports, Reports (exist, extended)
- Operator Analytics (new)
- Audit Center (new)
- Sync & Conflict Center (new)
- Permissions (new — only meaningfully populated once the permission-abstraction layer, Section 21, has more than 3 fixed roles to manage)
- System/Devices (new — connectivity monitoring, folded into Command Center for now rather than a separate top-level page; see Section 39 for the "why" per-screen)

**Viewer**: unchanged from today **[RULE — "define appropriate read-only access based on current behavior"]** — read-only access to reports, analytics, session show, attendance list, exactly as now **[CODE: routes/web.php, outside the `role:admin,operator` group]**. Viewer does **not** get the Command Center's live operational detail (device/sync internals) — that's operational surface, not reporting surface — but does get the drill-down analytics.

**Navigation surfaces**:
- **Mobile**: bottom-nav, extended from today's 4 items + FAB **[CODE: bottom-nav.blade.php]** — Home, Sessions, [FAB: Live Attendance], Reports, More (→ Analytics, Khidmatguzars, **+ new: Audit Center / Sync Center for Admin only**, kept inside "More" to avoid bottom-nav overcrowding).
- **Desktop**: a persistent left sidebar replaces the bottom-nav at `lg:` breakpoint and above — this is new (today's app has no desktop-specific nav **[CODE — confirmed, same bottom-nav renders at all breakpoints today]**) and is the one navigation-shape change in 2.0, justified directly by your Section 22 instruction to use desktop space properly rather than stretching mobile chrome.
- **Contextual nav**: within Session Command Center / Drill-down Analytics, the breadcrumb (Section 10) is the contextual navigation; it does not replace the global nav, it sits below the page header.

---

## 12. Complete Screen Inventory

Every screen for 2.0. Existing screens marked **[EXISTS]** (extended, not rebuilt); new screens marked **[NEW]**.

| Screen | Role | Entry points | Key info | Primary actions | Notes |
|---|---|---|---|---|---|
| Login **[EXISTS]** | all | direct URL | ITS + password | Sign in | No change beyond autofocus already present |
| Dashboard **[EXISTS]** | all | bottom-nav Home | session counts, gender summary, recent sessions | Go to active session | Admin sees Command Center link when a session is active |
| Sessions List **[EXISTS]** | all | bottom-nav Sessions | all sessions, status badges | Create (admin/op), open | unchanged |
| Create Session **[EXISTS]** | admin/op | Sessions List | form | Save as draft | unchanged |
| Session Detail **[EXISTS]** | all | Sessions List | import batches, activate/close | Activate, Import, Close, **Reopen (admin, closed only) [NEW]** | Reopen button + reason dropdown added |
| Session Command Center **[NEW]** | admin (viewer: read-only variant) | Dashboard, Sessions List (active session) | live counters, dept progress, live ops, recent activity, attention needed | drill into department/conflict | Section 9 |
| Import Wizard: Upload **[EXISTS]** | admin/op | Session Detail | file drop | Upload | mobile file-picker + desktop drag/drop |
| Import Preview **[EXISTS→upgraded]** | admin/op | after upload | NEW/UPDATED/UNCHANGED/WARNING/ERROR breakdown **[NEW]** | Confirm, Cancel | Section 12 (Intelligent Import Center) |
| Import Diff **[NEW]** | admin/op | Import Preview, Import History | per-field before/after for changed master records | Confirm, flag for review | new — closes the master-data audit gap |
| Import History **[NEW]** | admin | Session Detail | list of past batches for this session, linked to diffs | View diff | today only shows batch metadata, not diffs |
| Live Attendance **[EXISTS→upgraded]** | admin/op | bottom-nav FAB | ITS/name search, member card, assignment list, action buttons | Mark Present/Absent, Correction, Extra Present | Section 14, 17 |
| Attendance List (all/present/pending/extra tabs) **[EXISTS]** | all | Live Attendance, Session Detail | filterable list | search, filter by dept | unchanged structurally |
| Pending **[EXISTS]** | admin/op | Attendance List | remaining pending | Mark all absent | unchanged |
| Offline Queue **[NEW]** | op | Profile/Device State, badge tap | queued/syncing/conflicted local events | Retry sync, view conflict detail | Section 7/27 |
| Sync & Conflict Center **[NEW]** | admin | Command Center "attention needed" | pending/synced/failed events, conflicts with local-vs-server diff | Resolve conflict, dismiss | Section 15 |
| Device/Connectivity Panel **[NEW, folded into Command Center]** | admin | Command Center | online/offline devices, last seen, pending sync count | — (informational) | Not a standalone top-level page — see IA note |
| Analytics Overview **[EXISTS]** | all | bottom-nav More | gender, trend, top-5 dept | drill to department | unchanged, becomes entry point for drill-down |
| Department Drill-down **[EXISTS→extended]** | all | Analytics Overview | dept breakdown | drill to status → member list | Section 10 |
| Khidmatguzar Directory **[EXISTS]** | all | bottom-nav More | searchable list, lifetime stats | open profile | unchanged |
| Khidmatguzar 360° (Profile) **[EXISTS→extended]** | all | Directory, drill-down | identity, history, extra-present, **event timeline [NEW]** | — | Section 11 |
| Operator Analytics **[NEW]** | admin | Audit Center or dedicated nav item | per-operator action counts, activity over time | drill to operator's event list | Section 6 — explicitly not a leaderboard |
| Audit Center **[NEW]** | admin | dedicated nav item, Command Center | global filterable event timeline | search/filter, reconstruct a member's history | Section 14 |
| Audit Event Detail **[NEW]** | admin | Audit Center row | full context of one event (who/what/when/session/assignment/device/source) | — | supports dispute investigation |
| Reports Index **[EXISTS]** | all | bottom-nav Reports | list of report types | open a report | extended with new report types (operator, audit, import, sync) |
| Report Preview/PDF/Excel **[EXISTS→extended]** | all | Reports Index | per-type preview | Export PDF (desktop-favored), Export Excel (mobile-favored) | Section 23 |
| Permissions **[NEW]** | admin | dedicated nav item | role/permission matrix | edit (only meaningful once >3 roles exist) | Section 21 — thin UI over the new abstraction |
| Profile/Device State **[NEW]** | op | bottom-nav (operator) | this device's id, last sync, app version, connectivity | Force sync, clear local cache | small, operational |

For every screen: **empty/loading/error states** follow the unified patterns in Section 27 (Empty State, Skeleton, Error State components) rather than being redesigned per-screen — this directly fixes the "inconsistent empty-state polish" finding from the prior UI/UX audit. **Offline state**: any screen that depends on live data (Command Center, Sync Center, Analytics) shows a "last updated" timestamp + stale-data treatment (Section 26) when the realtime channel is disconnected; Live Attendance and Offline Queue are explicitly designed to remain fully usable offline (that's their purpose).

---

## 13. User Journeys

All 24 journeys from your Section 40, condensed to steps/states/failure-cases (full screen-by-screen detail lives implicitly in Section 12's inventory — repeating every screen name per journey here would be redundant per your own instruction to avoid a generic document).

1. **Operator logs in** → Login screen → ITS+password → Dashboard. *Failure*: bad credentials → inline error, rate-limited after 5 attempts **[CODE, existing]**.
2. **Opens active session** → Dashboard shows active session card → tap → Session Detail or (if role permits) Command Center.
3. **Marks attendance online** → Live Attendance → ITS search (autofocused) → single match → Mark Present → immediate inline success state (Section 19 motion) → next search ready. *Audit*: `AttendanceEvent` created.
4. **Multiple assignments** → search → sees N assignment rows → selects one or several → bulk-confirms (now with a confirmation step, closing UX Finding #3 from the prior audit) → each marked independently.
5. **Extra Present** → search returns no match → Extra Present form → ITS+Name+Gender+Department (Rule 4/5, Gender now required) → submit → creates `Khidmatguzar` if new + `ExtraPresent` row, blocked if `DutyAssignment` already exists for that person (Rule 9).
6. **Works fully offline** → connectivity badge shows OFFLINE → search works against provisioned local data → mark actions queue locally, UI shows "Saved offline — will sync automatically" (success framing, per Section 27) → operator continues normally.
7. **Device reconnects** → badge flips to SYNCING (n) → automatic background sync begins, no operator action required.
8. **Offline events synchronize** → each event validated server-side via the same `AttendanceService` path → ACCEPTED (badge decrements) or CONFLICT (routed to Section 15).
9. **Sync conflict occurs** → operator sees "2 events need review" (non-blocking, doesn't stop them from continuing to work) → admin notified via Command Center attention panel.
10. **Admin resolves conflict** → Sync & Conflict Center → sees local-vs-server diff explained plainly (Section 15) → chooses to keep server state, apply the queued event as a correction, or discard — decision recorded as a new `AttendanceEvent`, never a silent rewrite.
11. **Admin imports Excel** → Import Wizard → preview shows NEW/UPDATED/UNCHANGED/WARNING/ERROR counts and a diff for UPDATED rows → confirm → `ImportBatch` + diff log created.
12. **Existing member master data changes** → import diff explicitly flags "Full Name changed: 'Mohammed Ali' → 'Mohammed A. Ali'" per field → admin can see it before confirming, not after.
13. **Blank Excel values imported** → diff shows "Idara: unchanged (new file blank, kept existing value)" — explicit, not silent (Rule 3).
14. **Admin views Command Center** → Section 9 layout, live-updating.
15. **Drills to absent members** → Command Center department card → tap Absent count → filtered member list → tap member → Khidmatguzar 360°.
16. **Opens member history** → Khidmatguzar 360° → full assignment/attendance/extra-present/event timeline for that person.
17. **Reviews audit history** → Audit Center → filters by session/ITS/operator/date → sees full chronological event list.
18. **Reopens closed session** → Session Detail → Reopen (admin only) → reason dropdown (your 6 options + Other+detail) → session state becomes `active` again with a `reopened_for_correction` flag (not reset to draft/pending — Rule 6) → `SessionReopened` audit event created.
19. **Corrects attendance** → same Live Attendance / Attendance List UI, now unlocked because session is reopened → correction creates a new `AttendanceEvent`, never edits the old one.
20. **Closes session again** → Close flow (existing, unchanged) → new `SessionClosed` audit event, second closure distinguishable in the timeline from the first.
21. **Exports report** → Reports Index → mobile/tablet nudged toward Excel, desktop offered both (Section 23) → generated via unchanged `ReportService`.
22. **Reviews operator analytics** → Operator Analytics screen → per-operator action counts, activity-over-time, explicitly framed as workload/operational data.
23. **Reviews device connectivity** → Command Center → device panel → online/offline/last-seen list.
24. **Viewer reviews analytics** → same Analytics/Drill-down screens as Admin, Command Center's operational internals (device/sync detail) hidden.

---

## 14. Operator UX

Structure per your Section 17, mapped onto real components:

```
Header: session name + status badge + Connectivity Badge (Online/Offline/Syncing)
Primary action: ITS input, autofocused, numeric keypad, 8-digit pattern (fixes prior audit Finding #4)
Member card (on match): identity + department + assignment(s) — Smart Assignment
  Resolution renders one row per assignment when >1 exists (already correct, Rule 8)
Attendance action: Mark Present / Mark Absent / Correction, each with consistent
  disable-on-submit state (fixes prior audit Finding #2) and consistent confirmation
  pattern for anything bulk/destructive (fixes Finding #3)
Feedback: inline success/error/offline-queued/syncing state (Section 27), motion per
  Section 19 (button compress → check animation → ready for next), never blocking
Next person: search field re-focuses automatically after a successful mark
```

This is evolution, not invention — every element in this flow already exists in some form **[CODE]**; the changes are: autofocus, consistent feedback states, offline awareness, and the animation layer.

---

## 15. Admin UX

Admin's job, per your Section 10 answerability checklist, all confirmed answerable today except reopen (Rule 6, being built) and disputed-record investigation (Audit Center, being built) — see Section 4 table and prior audit Section 9. 2.0 adds: Command Center as the default landing view for an active session (replacing plain session-show as the "what's happening now" surface), Audit Center for investigation, Sync & Conflict Center for offline reconciliation, Operator Analytics for workload visibility, Reopen for correction workflow. Desktop admin gets multi-column layouts (Section 22) — department grids, side-by-side member detail (list on the left, detail panel on the right, no full navigation away from the list), persistent filters that don't require re-selecting on every drill-down step.

---

## 16. Viewer UX

Unchanged scope from today **[RULE]**: reports, analytics, session show, attendance list — all read-only, no mutation controls rendered at all (not just disabled — per the existing pattern where Live Attendance's FAB is simply absent/grayed for non-managers **[CODE: bottom-nav.blade.php]**). Viewer gets the new Drill-Down Analytics and Khidmatguzar 360° upgrades (these are reporting surfaces) but not the Command Center's live operational internals (device connectivity, sync queue state) or the Audit Center (investigation tooling, implicitly an admin capability given it exposes operator-level scrutiny) — **[INFERENCE, flagged as a question in Section 41]** whether Viewer should see the Audit Center in a read-only form.

---

## 17. Attendance State UX

**Session states** — visual treatment and available actions per state:

| State | Visual | Available actions | Notes |
|---|---|---|---|
| DRAFT | slate badge | Activate, Edit, Delete | unchanged from today |
| ACTIVE | emerald badge | mark attendance, import, close | unchanged |
| CLOSING | blue/transient — **[CODE: today this never persists visibly, it's an in-transaction state]** | none (system-only) | 2.0 keeps this as a true transient — no UI needs to render it since it never outlives one request |
| CLOSED | red/slate badge | Reopen (admin only), view reports | new: Reopen button appears only for admin |
| REOPENED FOR CORRECTION | orange/amber badge, distinct from ACTIVE | mark/correct attendance, close again | **new state** — not in the current enum; needs a schema addition (Section 34) so it's visually distinguishable from a normal active session, per your instruction "reopening does NOT reset to Pending" |

**Attendance states** (per assignment, in the live UI):

| State | Visual | Actions available | Confirmation | Animation |
|---|---|---|---|---|
| NOT MARKED (pending) | slate/neutral | Mark Present, Mark Absent | Absent: confirm; Present: no confirm (frequent, low-risk) | none at rest |
| PRESENT | emerald | (none — terminal unless corrected) | — | check animation on transition in |
| ABSENT | red | Correction → Present | confirm (existing pattern, kept) | subtle shake-free fade to red |
| CORRECTION AVAILABLE | red + amber "Correct to Present" affordance, **visually distinct chip, not a plain caption line** (fixes prior audit Finding #5) | Correct to Present | confirm | — |
| OFFLINE QUEUED | slate with a small clock/cloud-off icon | (queued — no further action until synced) | — | subtle pulsing icon, not a spinner (calmer, per Section 19 "not flashy") |
| SYNCING | slate with spinner icon | — | — | spinner, GPU-friendly |
| SYNCED | brief emerald flash then settles to PRESENT/ABSENT's normal treatment | — | — | brief flash, ~300ms, then normal |
| CONFLICT | amber/orange with a flag icon | View conflict (routes to Sync Center) | — | none — deliberately calm, this is an attention state not an alarm |
| REJECTED | red with an info icon + reason text | View reason, re-attempt if applicable | — | none |

Accessibility for every state: color is never the only signal — each has a distinct icon and a text label, addressing the prior audit's Finding re: color-only status indication.

---

## 18. Import Center UX

Upgrades `imports/create.blade.php` and `imports/preview.blade.php` **[CODE, existing]** rather than replacing them:

- **Upload**: existing drag/drop-capable file input, extended with a clearer mobile file-picker affordance (today's form works but isn't visually distinguished for touch — small polish, not a rebuild).
- **Preview** gains four visible buckets instead of today's single valid/invalid split **[CODE: today only tracks valid_rows/invalid_rows/exact_duplicate_rows/cross_batch_duplicate_rows]**: **NEW** (new Khidmatguzar or new assignment), **UPDATED** (existing Khidmatguzar, at least one field changed), **UNCHANGED** (existing Khidmatguzar, no field differs), **WARNING** (e.g., cross-batch duplicate, treated as informational not blocking), **ERROR** (missing required field, blocks that row). This maps directly onto existing `ImportBatch` counter columns plus new ones needed for the UPDATED/UNCHANGED distinction (Section 34).
- **Diff view** (new): for every UPDATED row, show field-by-field old→new, with blank-preserving fields explicitly marked "kept existing value" rather than looking like nothing happened (Rule 3 UX requirement).
- **Import History**: existing session-detail batch list extended with a "View Diff" link per batch, backed by the new audit-log table (Section 34).
- **Rollback**: **[INFERENCE]** full rollback (undo a committed import) is higher-risk than your prompt's "safe review/rollback strategy where appropriate" phrasing suggests is mandatory — recommending this stay **preview-time prevention only** (get it right before confirming) rather than post-commit rollback, since undoing an import after attendance may already have been marked against its assignments creates its own data-integrity problem. Flagged as a question in Section 41.

---

## 19. Audit Center UX

Global timeline, matching your Section 14 example exactly:

```
09:02 — Operator A marked Present         [Assignment #1234, Session: Miqaat Day 2]
09:05 — Admin reopened session            [Reason: Attendance correction required]
09:07 — Admin corrected assignment        [Assignment #1234]
09:08 — Admin marked Present for Assignment B
09:15 — Session closed
```

Filters: session, ITS, name, department, operator, action, status, date/time, device, online/offline source — a filter bar (mobile: bottom sheet, desktop: persistent sidebar, per Section 27 Filter Bar/Filter Sheet component). Each row expands to full event detail (Audit Event Detail screen — Section 12). A member's full history is reconstructable by filtering to their ITS — this is the same query shape as Khidmatguzar 360°'s history tab, so the two share a component (Audit Timeline / Audit Event) rather than duplicating logic. Historical events are visually immutable — no edit affordance ever appears on a past event row, only "create a correction" which produces a new event.

---

## 20. Sync & Conflict UX

Per your Section 15 spec:

```
PENDING (3)     SYNCED (241)     FAILED (0)     CONFLICTS (2)

[Conflict card]
  Ahmed Ali · ITS 12345678 · Finance Dept
  Locally: marked Present at 14:02 (offline, Device #A3)
  Server currently: marked Absent at 14:05 (Operator B, online)
  Why: both events happened before this device could sync
  [ Keep Server's Absent ]  [ Apply Present as Correction ]  [ Discuss / Dismiss ]
```

The explicit local-vs-server-vs-reason format directly satisfies your "never make the operator guess" requirement — every conflict card states what happened locally, what the server currently says, why, and the admin's exact options, with no ambiguous "resolve" button that hides the decision.

---

## 21. Permissions Architecture

**[DESIGN]** — replaces the current hardcoded `EnsureUserHasRole` string-match middleware **[CODE]** with a proper abstraction, per your explicit instruction ("build a proper permissions architecture... future department-scoped permissions should be possible without major restructuring... do not introduce department restrictions in 2.0 unless necessary").

**Approach**: introduce a `Permission` enum/table (e.g. `mark_attendance`, `manage_sessions`, `view_reports`, `manage_imports`, `reopen_sessions`, `resolve_conflicts`, `manage_permissions`) and a `role_permissions` mapping table, seeded so that today's three roles (admin/operator/viewer) map to exactly the permission sets they have today — **behavior does not change in 2.0**, only the mechanism. `EnsureUserHasRole` becomes `EnsurePermission` checking `$user->can($permission)` (Laravel's native Gate/Policy system, not a bespoke reimplementation). Route groups in `web.php` change from `role:admin,operator` to `can:manage_sessions` etc. — a mechanical, low-risk refactor since the actual access boundaries don't move.

**Why now, not later**: retrofitting a permission table onto three already-hardcoded roles later is strictly harder than building it now while the access model is still simple and well-understood — this is the correct moment to do it, precisely because nothing about *current* behavior needs to change yet.

**Explicitly not built in 2.0**: department-scoped permissions (an operator restricted to their own department's data) — the permission table's shape makes this addable later (a `department_id` scope column on a future permission-assignment, not on the enum itself) without another migration of the whole auth system, but no UI or enforcement for it ships now, per your explicit instruction.

---

## 22. Analytics Architecture

Extends `AnalyticsController`/`ReportService` **[CODE, existing]**, never reimplements the rate formula (Rule 10) — every new analytics surface (Operator Analytics, Attendance Trends, Integrity/anomaly panel) calls into the same `scheduled`/`present`/`extra` calculation helpers already in `ReportService`/`AnalyticsController`, confirmed as the single consistent implementation across 13 sites in the prior audit.

- **Session**: unchanged — rate, present/absent/pending/scheduled/extra, department comparison, trend (all exist).
- **Department**: unchanged — scheduled/present/absent/pending/rate, extended with member drill-down (Section 10) as the new capability.
- **Operator** (new): actions, present, absent, corrections, extra-present counts, activity over time, last activity, workload comparison — sourced from `AttendanceEvent.performed_by`, which already carries this data **[CODE]**; this is a new query/view, not new data collection.
- **Trends** (new/extended): session comparison, department trends over time, operational activity — the existing `analytics/overview` trend chart extends to a longer window and a department-comparison variant.
- **Integrity** (new): anomalies (e.g., an operator marking an implausible number of people in a short window), duplicate-attempt counts (from rejected sync events), corrections count, conflicts count — every metric here must trace to a real, explainable event (your "avoid meaningless charts" instruction) — no speculative ML-derived "risk score."

---

## 23. Reporting Architecture

`ReportService`'s single-source-of-truth principle is **validated and preserved unchanged** **[CODE, confirmed no divergence between preview/PDF/Excel across all 4 existing report types]**. New report types (Operator Activity, Audit, Import, Sync/Conflict) are added as new methods on the same service, following the identical pattern — never a parallel query path.

**Large-report device guidance** (Rule — your Section 5 "Large Reports" decision): mobile/tablet viewports show Excel as the recommended/primary export button with PDF available but visually secondary; desktop/laptop shows both with equal weight. This is a UI presentation decision (button ordering/emphasis), not a backend limit — **[RULE]** "do not impose arbitrary limits unless actual performance testing proves necessary," so no row-count cap is added; the prior audit's dompdf-large-table performance risk (Section 11 of the audit) remains a known characteristic to monitor, not something 2.0 preemptively restricts.

---

## 24. UI Design System

Base palette **[CODE — extending, not replacing, tailwind.config.js's actual tokens]**:

| Token | Value | Usage |
|---|---|---|
| `navy-950` | `#0B1730` | deepest surface, dark-mode-adjacent accents |
| `navy-900` | `#0F1E3D` | primary brand color, active nav state, PWA theme-color **[CODE: manifest.json]** |
| `navy-800` | `#152B52` | hover/pressed states on navy elements |
| `navy-700` | `#1D3A6B` | lighter navy accents, gradients kept subtle |
| `slate-50` | Tailwind default | page background **[CODE]** |
| `white` | — | card/surface background **[CODE]** |
| `slate-100/200` | Tailwind default | borders **[CODE: badge/stat-card/bottom-nav all use these]** |
| `slate-400/500/600/900` | Tailwind default | muted text / body text / headings |
| `emerald-500/600` | Tailwind default | success / Present / online |
| `red-500/600` | Tailwind default | danger / Absent / rejected |
| `orange-500` | Tailwind default | warning / Pending / conflict |
| `blue-600` | Tailwind default | info / analytics accents **[CODE: bottom-nav "More" icon]** |
| `violet-600` | Tailwind default | Extra Present / a distinct fifth category **[CODE: badge "purple" tone]** |

**New tokens needed for 2.0** (extending, following the same naming convention — added to `tailwind.config.js theme.extend.colors`, never replacing existing keys):
- `offline`: a desaturated slate/amber blend — recommend reusing `slate-400` with an icon, not inventing a new hue, to avoid a sixth arbitrary color; if a distinct hue is preferred, `amber-500` (adjacent to but distinguishable from the existing `orange` "pending" tone) — **[QUESTION, Section 41]**.
- `syncing`: reuse `blue-500` (already "info," syncing is informational-in-progress).
- `conflict`: reuse `orange-600` (slightly deeper than the existing "pending" orange, to read as more urgent) — again, prefer reusing the existing five-tone system over inventing new hues.
- `glass surface`: `white/70` with `backdrop-blur-md` (Tailwind utilities, no new color token needed — see Section 25).

**Typography**: current app uses Figtree via Tailwind defaults with ad-hoc Tailwind text-size utilities per view (no defined type scale found in code **[CODE — confirmed no custom `fontSize` scale in tailwind.config.js]**). 2.0 defines an explicit scale, still Figtree:

| Role | Size/weight |
|---|---|
| Display (Command Center hero numeral) | `text-4xl font-bold` |
| Page title | `text-xl font-semibold` (matches existing `page-header` usage pattern) |
| Section title | `text-sm font-semibold uppercase tracking-wide text-slate-500` (matches existing badge/label styling already in use) |
| Body | `text-sm text-slate-700` |
| Caption | `text-xs text-slate-500` (matches existing `stat-card` label styling) |
| Numeric metrics | `text-lg font-semibold tabular-nums` — **tabular-nums is new**, needed so live-updating counters (Section 8/19) don't visually jitter as digit widths change |

**Spacing**: Tailwind's default scale, unchanged — the existing app already uses it consistently (`p-3`, `gap-3`, `px-2.5 py-1`, etc. **[CODE]**) — no new scale needed, just documented as "use Tailwind defaults, no arbitrary values."

**Radius**: `rounded-lg` (nav items), `rounded-xl` (dropdowns/menus), `rounded-2xl` (cards — dominant pattern **[CODE: stat-card, badge uses `rounded-full`]**), `rounded-3xl` (custom token, already defined **[CODE: tailwind.config.js]** — reserved for hero/feature cards like the new Command Center attendance-rate card).

**Elevation**: `shadow-sm` (base card, existing default), `shadow-lg` (raised/floating — existing FAB pattern **[CODE: bottom-nav emerald button]**), new: `shadow-xl` + subtle `ring-1 ring-black/5` for modal/dialog surfaces (not currently used anywhere in the app — dialogs today are just native `confirm()` **[CODE, confirmed]**, so this is genuinely new).

**Glass**: see Section 25 in full.

**Icons**: current icon usage is hand-authored inline SVG, stroke-width 2, `h-4/h-5/h-6 w-*` sizing, `stroke-linecap="round"` **[CODE — consistent across bottom-nav, badge usages]** — this is effectively a Heroicons-outline-style hand-drawn set. 2.0 formalizes this as **Heroicons (outline, stroke-width 2)** as the canonical icon family (closest match to what's already hand-authored, avoids a visual mismatch, avoids adding a large icon-font dependency) with a documented size scale (`h-4 w-4` inline/small, `h-5 w-5` buttons, `h-6 w-6` nav) and a hard rule: every icon-only interactive element gets an `aria-label` (directly fixes the prior audit's Finding #11 — 32px icon-only buttons with no accessible name).

---

## 25. Glassmorphism System

Selective, per your explicit "restrained" instruction — applied only to the candidate list you named, using CSS that degrades gracefully:

```css
.glass-surface {
  background: rgb(255 255 255 / 0.72);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  border: 1px solid rgb(255 255 255 / 0.4);
  box-shadow: 0 8px 32px rgb(15 30 61 / 0.08); /* navy-tinted shadow, not generic black */
}
@supports not (backdrop-filter: blur(1px)) {
  .glass-surface { background: rgb(255 255 255 / 0.95); } /* solid fallback, no blur */
}
```

- **Opacity**: 70-75% white base — enough transparency to read as "glass," never below ~70% (protects text contrast).
- **Blur**: 12px max on primary surfaces (Command Center hero card, filter sheets); 8px on smaller floating elements (connectivity badge) — capped low deliberately, per your performance instruction (Section 32) and mid-range Android GPU cost of large blur radii.
- **Border**: 1px white/40% — reads as a light rim, not a hard edge, consistent with "restrained."
- **Shadow**: navy-tinted (`rgb(15 30 61 / 0.08)`), not a generic black shadow — ties the glass effect back to the brand navy rather than looking like a generic Bootstrap-era shadow.
- **Fallback**: `@supports not (backdrop-filter)` → solid near-opaque white, identical layout, zero blur — same component, no separate "low-end" variant to maintain.
- **Where applied** (your candidate list, confirmed as the full scope — nowhere else): Session Command Center hero attendance-rate card, summary/metric cards on that screen only, the bottom-nav's "More" flyout (already a floating surface today **[CODE: bottom-nav.blade.php line 61-62]** — upgrade its existing white background to glass), filter sheets (mobile bottom-sheet filters), modal/dialog surfaces (new in 2.0), live-activity feed panel, connectivity indicator badge, and the top 1-2 analytics summary panels on Drill-down Analytics. **Not** applied to: standard list rows, form fields, standard content cards, the bottom-nav bar itself (stays solid white — it's a persistent anchor, glass there would reduce legibility during scroll), any Viewer-role screen where "restrained premium" isn't the goal (reporting screens stay plain and maximally legible).

---

## 26. Motion System

**[DESIGN]** — the app currently has essentially zero animation beyond Alpine's default `x-transition` on the "More" dropdown **[CODE, confirmed]**. 2.0 introduces a deliberate, small motion vocabulary:

| Interaction | Motion | Duration | Easing | Notes |
|---|---|---|---|---|
| Button press (any primary action) | scale to 0.97 | 100ms | ease-out | `transform`, GPU-friendly, no layout shift |
| Mark Present/Absent success | button compresses → brief checkmark icon swap-in → card settles | ~250ms total | ease-out | never delays the actual server write — animation plays optimistically alongside the request, per your explicit instruction |
| Checkbox/toggle | native-feel scale+opacity on the check glyph | 120ms | ease-out | no custom checkbox reinvention — style the native input's pseudo-state |
| State transition (Present/Absent/Queued/Syncing/Synced/Conflict) | crossfade + slight vertical settle (4px) | 180ms | ease-in-out | consistent across every state badge in the app |
| Drill-down navigation (open detail) | new panel slides up (mobile) / fades+scales in (desktop) | 200ms | ease-out | mirrors native app-like feel without a full router |
| Card expand (e.g. conflict card detail) | height auto-animate via `grid-template-rows` trick or `interpolate-size` where supported, else instant | 200ms | ease-in-out | avoid animating `height: auto` directly (janky) |
| Dialog/modal open | backdrop fade + panel scale-from-0.96 | 150ms | ease-out | pairs with new glass modal surface (Section 25) |
| Live counters (Command Center) | animated count-up/down on delta, not per-frame flicker | 400ms per delta | ease-out | tabular-nums (Section 24) prevents jitter |
| New activity item appears | fade+slide-in from top of the feed | 200ms | ease-out | capped: only animate the newest 1-2 items, older items reflow instantly (avoids jank on rapid activity) |
| Success/warning/error toast | slide-in from top (mobile) / top-right (desktop), auto-dismiss | 3s visible + 200ms transitions | ease-out | |

**Implementation**: pure CSS transitions/animations (`transform`, `opacity` only, per your explicit GPU-friendly instruction) plus Alpine.js's existing `x-transition` directive for the DOM-presence cases (already the app's pattern **[CODE]**) — **no animation library dependency added**, this stays within the existing Blade+Alpine+Tailwind stack. `prefers-reduced-motion: reduce` disables all non-essential motion (everything except the instant state-change itself) via a single CSS media query wrapping the transition declarations — one global rule, not per-component logic.

---

## 27. Component System

Full spec for the components in your Section 25 list judged non-trivial enough to warrant one (trivial ones like Toast/Pagination follow standard patterns and aren't detailed further):

**App Shell / Bottom Navigation [EXISTS, extended]** — adds a desktop sidebar variant at `lg:` breakpoint (new); mobile behavior unchanged from today **[CODE]**.

**Metric Card / Glass Metric Card** — `stat-card` component **[CODE, existing]** extended with a `glass` boolean prop that swaps its background/border classes to the glass-surface treatment (Section 25); everything else (icon bubble, value, label, tone system) unchanged.

**Attendance Action** (new) — the Present/Absent/Correct button group on the live-attendance member card; states: default, disabled-during-submit, success (post-mark), offline-queued. Mobile: full-width stacked buttons. Desktop: inline row.

**Assignment Selector** (new, formalizing existing inline markup **[CODE: attendance/live.blade.php multi-match branch]**) — checkbox-per-assignment list, each row showing department/block/seat/status; already-actioned rows shown disabled+dimmed (existing pattern, kept).

**Member Card** — identity + department + assignment(s) + action, the core unit of the live-attendance screen; reused (read-only variant, no action buttons) in Drill-down member lists.

**Status Badge [EXISTS, extended]** — `badge` component **[CODE]** gains no new tone-color additions (Section 24 deliberately reuses the existing five tones) but gains an icon-slot prop so status is never color-only (accessibility fix).

**Connectivity Badge / Sync Status** (new) — small pill, top of screen: icon + label (`Online`/`Offline`/`Syncing (3)`/`Conflict (2)`), tappable to open Offline Queue or Sync Center depending on role.

**Live Activity Item** (new) — timestamp + description + optional tone-colored icon, used in Command Center feed and Audit Timeline (shared component, different data source).

**Department Card** — extends `stat-card` compact variant **[CODE]** with a tap target that routes into drill-down.

**Drill-down Breadcrumb** (new) — Section 10 detail.

**Filter Bar / Filter Sheet** (new) — desktop: persistent horizontal bar of selects/inputs above a list. Mobile: a single "Filters" button opening a bottom sheet (glass surface, Section 25) with the same controls stacked vertically. One underlying form, two presentations via CSS/Alpine, not two implementations.

**ITS Input** (new, formalizing existing markup) — `inputmode="numeric"`, `pattern="\d{8}"`, `maxlength="8"`, autofocus where it's the primary action — codifies the fix for prior audit Findings #1 and #4 as a reusable component so future screens don't regress it.

**Import Dropzone / Import Preview / Import Diff** — Section 18 detail.

**Audit Timeline / Audit Event** — Section 19 detail.

**Conflict Card** — Section 20 detail.

**Confirmation Dialog** (new, standardized) — replaces the current three inconsistent patterns (native `confirm()`, custom Alpine card, no confirmation) identified in the prior audit with one component: glass-surfaced modal (Section 25), consistent copy pattern ("Are you sure you want to X? This [does/does not] affect Y."), used for every destructive/bulk action app-wide.

**Reopen Dialog / Reason Dropdown** (new) — modal with the 6 reason options + conditional "Other" detail textarea, per Rule 6.

**Skeleton / Loading State** (new, standardized) — replaces the current "nothing visibly happens" gap on filter/report submissions (prior audit Finding #14) with a consistent skeleton-card pattern matching each screen's actual card shapes, shown during any GET-triggered reload that takes over ~300ms.

**Data Table** (new, desktop-only pattern) — for Audit Center, Operator Analytics, and any dense admin list where cards would waste horizontal space at `lg:`+ breakpoints; mobile equivalent is always the existing card-list pattern, never a horizontally-scrolled table.

**Chart** — existing chart usage in `analytics/overview` (whatever charting is currently used, kept) extended with the same tone palette (Section 24), no new charting library unless the current one can't support the trend/comparison views needed (**[QUESTION]** — depends on what's actually in use today; flagged in Section 41 since chart library wasn't part of this audit's scope and should be verified before Phase 5 work begins).

---

## 28. Mobile UX

Primary operational platform, confirmed by the existing `max-w-md` mobile-first container **[CODE]** and the whole app's design center of gravity. 2.0 additions: autofocus on primary inputs (fixes prior Finding #1), 44px minimum touch targets enforced on every icon-only control (fixes Finding #11 — hamburger/back-arrow grow from `h-8 w-8` to `h-11 w-11` or gain a larger invisible tap-padding via `p-2.5` wrapping), one-handed reachability for the bottom-nav FAB (already correctly bottom-anchored **[CODE]**), outdoor/field legibility (existing navy-on-white contrast is already strong — no change needed, verified against the actual palette values), rapid-repeat-action support (auto-refocus after mark, per Section 14).

---

## 29. Desktop UX

The one place 2.0 genuinely departs from "just the mobile layout, wider": Session Command Center (multi-column + persistent right-rail, Section 9), Audit Center and Operator Analytics (real data tables, Section 27), Drill-down Analytics (side-by-side list+detail rather than full navigation, Section 15), and global navigation itself (sidebar replaces bottom-nav at `lg:`+, Section 11). Everything else — Live Attendance, Sessions List, Reports — keeps its mobile-first card layout simply centered/max-widthed on desktop, matching the app's existing `sm:max-w-2xl lg:max-w-4xl` pattern **[CODE: layouts/app.blade.php]**, since these screens are used identically regardless of device and don't benefit from a desktop-specific rearrangement.

---

## 30. Responsive Design

| Breakpoint | Behavior |
|---|---|
| Small phone (<375px, Tailwind default base) | single column, all cards full-width, bottom-nav 5-icon layout unchanged |
| Large phone (375-640px) | unchanged from small phone, more breathing room |
| Tablet portrait (`sm:` 640px+) | container widens to `max-w-2xl` **[CODE, existing pattern]**, department/metric card grids go 2-up |
| Tablet landscape (~900px+, between `sm:` and `lg:`) | Command Center's department grid goes 3-up; sidebar nav not yet shown (bottom-nav retained — enough width for cards, not quite enough for a comfortable sidebar+content split) |
| Laptop (`lg:` 1024px+) | sidebar navigation replaces bottom-nav; Command Center right-rail appears; Audit Center/Operator Analytics switch from cards to data tables |
| Desktop (`xl:` 1280px+) | container widens to `max-w-4xl`+ for dense admin screens **[CODE, existing pattern extended]**; drill-down becomes side-by-side list+detail |
| Large desktop (`2xl:` 1536px+) | additional horizontal room used for a third analytics column (e.g., trend chart alongside department table) rather than stretching existing components wider than their comfortable reading width |

Stack→expand transitions: metric cards (1-col → 2-col → 3-col → 4-col grid across breakpoints), filter controls (sheet → persistent bar at `lg:`+), navigation (bottom-nav → sidebar at `lg:`), analytics drill-down (full-navigate → split-view at `lg:`+).

---

## 31. Accessibility

Direct fixes to every finding from the prior UI/UX audit, plus your Section 31 checklist:

- **Icon-only buttons**: every one gets `aria-label`; minimum 44px hit target (via padding, not necessarily visual size) — fixes prior Finding #11.
- **Color-only status**: every status badge/state (Section 17 table) pairs color with an icon and/or text label — fixes the implicit color-only risk in today's green/red/orange system.
- **Keyboard navigation**: all interactive elements reachable via Tab in logical order; the new Confirmation Dialog and Filter Sheet trap focus while open and return focus on close (standard modal a11y pattern, genuinely new since today's only "dialog" is the native browser `confirm()` which already handles this correctly).
- **Visible focus**: Tailwind's default focus-ring utilities applied consistently (today inconsistently present — audit as part of Phase 1).
- **ARIA labels**: icon buttons, live-region (`aria-live="polite"`) on the Command Center's live counters and activity feed so screen-reader users get the same "new activity" signal sighted users get from animation.
- **Semantic HTML**: form labels properly associated (`for`/`id`) — fixes the prior audit's file-upload-zone label gap.
- **Reduced motion**: Section 26's `prefers-reduced-motion` rule.
- **Touch targets**: Section 28.
- **Accessible dialogs/dropdowns/tables**: standard focus-trap/ARIA-role patterns on the new Confirmation Dialog, Filter Sheet, and Data Table components (Section 27) — built in from the start rather than retrofitted, since these are new components.

---

## 32. Performance

Evaluated explicitly against mid-range Android (the Android WebView wrapper's actual target device class **[CODE: android/app/build.gradle minSdk 23]**):

| Concern | 2.0 approach |
|---|---|
| Animations | `transform`/`opacity` only (Section 26), no layout-triggering properties, capped duration ≤400ms |
| Glass blur | 12px max, only 6-8 surfaces app-wide (Section 25), `@supports` fallback removes blur entirely on unsupported/low-power contexts |
| Charts | reuse existing charting approach, no added library unless verified necessary (Section 27) |
| Realtime connections | one WebSocket connection, subscribed only while Command Center is open (unsubscribe on navigate-away), not a site-wide persistent connection |
| Offline local storage | IndexedDB, scoped to one provisioned session at a time — bounded size (Section 7) |
| Large lists | Data Table (desktop) paginated server-side, same as today's existing pagination pattern **[CODE, existing across AnalyticsController/ReportController]** — no client-side virtualization needed at your stated data volumes unless a future scale review says otherwise |
| Imports | chunked reading recommended as a P2 item (carried from prior audit) — not blocking for 2.0's UI work, but the Import Diff feature should be built against a chunk-aware pipeline from the start to avoid redoing it |
| PDFs | unchanged dompdf pipeline, device-aware export guidance (Section 23) mitigates the one known large-PDF risk rather than re-architecting report generation |
| Reports | unchanged `ReportService`, `departmentDetailReport()`'s per-department query loop refactor (carried from prior audit, P2) should land before Advanced Reporting features pile more load on it |

---

## 33. Security

- **Authentication/authorization**: unchanged ITS-based auth **[CODE]**, now routed through the Section 21 permission abstraction rather than hardcoded role strings — no behavior change, cleaner enforcement surface.
- **CSRF**: unchanged, already correctly applied app-wide **[CODE, verified in prior audit]** — new API routes (sync, provisioning) need explicit consideration: token-based (Sanctum-style) auth for the sync API rather than relying on CSRF-protected session cookies, since these calls originate from a background sync process that may fire without a fresh page load's CSRF token — **[DESIGN]** use Laravel Sanctum's SPA-mode cookie authentication (already same-origin, no separate token management needed) rather than introducing a separate API token scheme.
- **Session security**: fix the `.env.example` `APP_DEBUG`/`SESSION_SECURE_COOKIE` defaults carried from the prior audit — this becomes more important, not less, once new authenticated JSON endpoints exist.
- **Audit integrity**: `AttendanceEvent` rows are never edited, only appended **[CODE, existing pattern]** — 2.0 extends this same immutability guarantee to the new master-data-change log and sync-event log.
- **Offline device trust**: `device_id` (Section 7) is **explicitly not a security boundary** — it is a soft operational label only. The actual security boundary remains the authenticated `operator_user_id` from the normal Laravel session/Sanctum token; a compromised device can only act as whatever that operator's real account permits, exactly as an online session would. This directly answers your "must not create an easy path for unauthorized attendance manipulation" concern: offline doesn't grant any new capability, it just delays confirmation of an already-authorized action.
- **Event signing/replay protection**: **[INFERENCE]** full cryptographic event-signing is disproportionate at your stated 1-5 device scale and single-organization trust model — the `local_event_id` idempotency key (Section 7) already prevents replay/duplicate-application, and server-side re-validation through the real `AttendanceService` business logic (not a separate trust-the-client path) is the actual defense. Recommend **not** building event signing in 2.0 unless a specific threat (e.g., a stolen/shared device) makes it necessary — flagged as a judgment call, not a hard requirement, since your prompt says "if appropriate."
- **Sync authorization**: every sync request re-authenticates via the normal session/Sanctum token — an offline queue does not grant any elevated or bypassed permission; if the operator's session has since been revoked (e.g., account disabled), sync requests fail auth exactly as any other request would.
- **Stale credentials**: if a queued event's session token has expired by the time connectivity returns, the sync attempt prompts a normal re-login before resubmitting — queued data survives locally, is not lost, but requires a fresh authenticated session to actually sync (cannot be sync'd "as" a credential that's no longer valid).
- **Export access**: unchanged — Reports remain accessible to all authenticated roles including Viewer, per existing design **[CODE, confirmed intentional in prior audit]**.
- **Sensitive information exposure**: the two carried-forward prior-audit findings (`APP_DEBUG` default, no master-data audit trail) are explicitly P0 in this blueprint's roadmap (Section 37) — 2.0 should not ship new features on top of an unfixed audit gap.

---

## 34. Edge Cases

Every item from your Section 34 list, resolved:

| Edge case | Resolution |
|---|---|
| Duplicate ITS | Prevented at DB level (`its_id` unique, existing **[CODE]**) — unchanged. |
| Same person, multiple departments | Rule 1 — supported by design, unchanged. |
| Multiple assignments | Rule 8 — shown separately, operator picks, unchanged (already correct). |
| Extra Present + scheduled conflict | Rule 9 — blocked, closing the current race via consistent locking (Section 4/13). |
| Unknown ITS | Supported — creates new `Khidmatguzar` inline, now also collecting Gender (Rule 4/5). |
| Blank Excel fields | Rule 3 — preserved, not overwritten; explicit in Import Diff UI. |
| Changed master data | Rule 3 — updated when non-blank, logged in new audit table. |
| Multiple imports | Rule 2 — merged, cross-batch dedup now DB-constraint-backed. |
| Duplicate file | Caught by fingerprint dedup (existing mechanism, now DB-backed). |
| Malformed file | Existing validation (mimes/size) + row-level ERROR bucket in new import preview. |
| Session closes during offline period | Section 7 — sync attempt rejected, surfaced to admin for manual reopen decision. |
| Device reconnects after long outage | Section 7 — provisioned data may be stale; sync surfaces server's current state for any changed assignment. |
| Duplicate sync | `local_event_id` idempotency key deduplicates server-side. |
| Conflict | Section 15/20 — never auto-resolved, admin-reviewed. |
| Operator logs out with pending events | Local queue persists in IndexedDB regardless of login state (tied to device, not session) — resumes syncing on next login from that device, or from the Offline Queue screen. |
| Incorrect device clock | `local_timestamp` never trusted for cross-device ordering (Section 7) — server `performed_at` at accept-time is authoritative. |
| Session data changes while device offline | Covered under "changed session data" in Section 7. |
| Assignment changes while device offline | Same — surfaced as a soft conflict on sync. |
| Admin reopens session | Rule 6 — full flow in Section 13/19. |
| Correction after sync | Creates a new `AttendanceEvent`, same as any correction — no special case needed. |
| Realtime connection failure | Section 8/26 — "LIVE DISCONNECTED, last updated..." banner, app remains otherwise usable. |
| Server unavailable | Offline-queue behavior applies identically whether the cause is device connectivity or server downtime — the client can't distinguish, and doesn't need to; it just queues and retries either way. |
| Browser refresh while offline | IndexedDB queue survives refresh (persistent storage, not in-memory) — confirmed as a design requirement in Section 7. |
| Storage quota exceeded | Provisioning is scoped to one session at a time specifically to avoid this (Section 7) — if quota is somehow exceeded, the provisioning action itself fails visibly with a clear error rather than partially succeeding. |
| Browser/device clears local data | Unsynced queued events would be lost — this is an inherent risk of any offline-first design; mitigate by syncing eagerly/frequently (not waiting for a "big batch") and by never treating "saved offline" as equivalent to "safely persisted forever" in UI copy — say "will sync automatically" (per your Section 27 instruction), not "saved permanently," which is honest about this residual risk without alarming the operator. |

---

## 35. Data Model / Database Changes

New tables/columns needed (migrations only — no execution in this phase):

- **`duty_assignments`**: change `assignment_fingerprint` index from plain to `unique(['duty_session_id', 'assignment_fingerprint'])` (P0, closes Rule 2's gap).
- **`duty_sessions`**: add `status` enum value `reopened` (or a separate boolean `is_reopened_for_correction` + keep `active` — **[QUESTION, Section 41]** which modeling is cleaner given existing `isActive()`/`isClosed()` helper methods), add `reopen_reason` enum column (matching your 6 options + other), `reopen_detail` text nullable, `reopened_at`/`reopened_by` (mirroring the existing `closed_at`/`closed_by` pattern **[CODE]**).
- **New table `session_reopen_log`** (or fold into a generic audit table, Section 34's audit design) — one row per reopen event, since a session could conceivably be reopened more than once; keeps `duty_sessions` itself lean while preserving full history.
- **New table `khidmatguzar_change_log`** (or generic `audit_log` with a polymorphic subject) — captures import-driven master-data field changes: `khidmatguzar_id`, `import_batch_id`, `field`, `old_value`, `new_value`, `changed_at`. This is the audit-trail fix for Rule 3.
- **New table `sync_events`** (server-side mirror of the client's queue, for the Sync & Conflict Center) — `local_event_id` (unique, idempotency key), `device_id`, `operator_user_id`, `session_id`, `assignment_id`, `action`, `local_timestamp`, `local_sequence_number`, `status` (accepted/conflict/rejected), `server_attendance_event_id` (nullable FK once accepted), `conflict_reason` nullable, `resolved_by`/`resolved_at`/`resolution` nullable (for admin conflict resolution).
- **`khidmatguzars`**: no schema change needed for Gender on Extra Present — column already exists and is nullable **[CODE]**; only the app-layer validation changes.
- **New `permissions`/`role_permissions` tables** (Section 21) — standard Laravel Gate-backed permission tables, seeded to match current role behavior exactly.
- **`import_batches`**: add counters for `updated_khidmatguzars`/`unchanged_khidmatguzars` (distinct from today's `existing_khidmatguzars`, which doesn't currently distinguish "existing and identical" from "existing and changed") to support the Import Diff bucket UI.

All additive (new tables/columns, one index change) — no destructive migrations, no data loss risk, consistent with "historical integrity" as a standing principle.

---

## 36. API / Event Architecture

New JSON API surface (versioned under `/api/v1/`, Sanctum-cookie-authenticated, distinct from the existing Blade routes which remain untouched):

- `GET /api/v1/sessions/{id}/provision` — offline provisioning payload.
- `POST /api/v1/sync/events` — batch event sync, returns per-event accept/conflict/reject.
- `GET /api/v1/sync/status` — current device's queue status (for the Offline Queue/Profile screen).
- `GET /api/v1/sessions/{id}/live-summary` — polling fallback for the Command Center if websockets aren't used (Section 8's fallback option).

Broadcast events (Section 8): `AttendanceMarked`, `DeviceConnectivityChanged`, `SyncConflictRaised`, `SessionStatusChanged` — implemented as standard Laravel `ShouldBroadcast` events on private per-session channels, authorized via the existing permission layer (Section 21) so a Viewer, if ever given Command Center access, only receives events for sessions they're allowed to see.

---

## 37. Keep / Improve / Add / Do Not Touch

**KEEP**
- `AttendanceService`'s transactional, row-locked state machine.
- `ReportService` as single source of truth.
- Snapshot-on-write historical integrity pattern.
- `AttendanceEvent` audit model and its immutability.
- Separate `ExtraPresent` model.
- Existing session lifecycle states (draft/active/closing/closed) as the base — extended, not replaced.
- Laravel/Blade/Tailwind/PWA/Android-WebView foundation.
- The approved visual identity: navy/slate/white palette, card language, bottom-nav shell, mobile-first container widths, Figtree typography, existing five status tones.
- Rule 7's assessment: current concurrency architecture, unchanged, no new locking scheme.

**IMPROVE**
- `assignment_fingerprint` uniqueness (DB-level).
- Master-data update logic (blank-preservation + audit trail).
- Extra Present form (add Gender).
- Import preview (diff-aware buckets).
- `departmentDetailReport()` query pattern.
- Live-attendance UX consistency (autofocus, submit feedback, confirmation patterns).
- Icon-only button accessibility.
- `.env.example` security defaults.
- Permission enforcement mechanism (behavior unchanged, implementation modernized).

**ADD**
- Session reopen workflow (Rule 6).
- Offline Attendance + Sync Engine.
- Realtime Session Command Center.
- Admin Audit Center.
- Sync & Conflict Center.
- Operator Analytics.
- Drill-down analytics navigation layer.
- Motion system.
- Selective glassmorphism.
- Desktop sidebar navigation (admin-facing dense screens only).
- Permission abstraction layer.

**DO NOT TOUCH**
- The approved color palette and its five-tone status system.
- The bottom-nav shell's core shape/position (mobile).
- The multi-assignment "show all, operator picks" UX (already correct per Rule 8).
- The Present ÷ Scheduled × 100 rate formula and its 13 existing calculation sites.
- Viewer's current read-only scope (unless Section 41's question about Audit Center visibility resolves otherwise).
- The Android WebView wrapper's architecture (no native rewrite).
- The `ReportService`-as-source-of-truth principle.

---

## 38. P0–P3 Prioritization

**P0 — Critical** (data-integrity/security fixes, prerequisite to building anything else on top of them):
- `assignment_fingerprint` unique constraint + `commit()` catch-and-retry.
- Master-data blank-preservation fix + audit log table.
- `.env.example` `APP_DEBUG`/session-cookie defaults.
- Rule 9 race closure (consistent locking between import-commit and extra-present paths).
- Extra Present Gender field.

**P1 — High**:
- Session Reopen workflow (Rule 6, full).
- Permission abstraction layer.
- Live-attendance UX consistency fixes (autofocus, feedback, confirmation, accessibility).
- Offline Attendance core (provisioning + local queue + basic sync — without full conflict-UI polish yet).
- Intelligent Import Center (diff-aware preview + history).

**P2 — Medium**:
- Realtime Session Command Center.
- Sync & Conflict Center (full admin resolution UI).
- Admin Audit Center.
- Operator Analytics.
- Drill-down analytics navigation.
- Motion system, selective glassmorphism.
- Desktop sidebar/dense-layout screens.
- `departmentDetailReport()` performance refactor.

**P3 — Later**:
- Saved report configurations.
- Anomaly detection.
- Chunked import pipeline (only if/when import volumes actually grow — carried as a "when needed" item, not a fixed deadline).
- Any future department-scoped permission enforcement (architecture allows it later, per Section 21 — not built now).

---

## 39. Phased Implementation Roadmap

Sequencing adjusted from your suggested Phase 0-8 order based on one dependency finding: **Offline Attendance and Realtime Command Center both depend on the P0 data-integrity fixes being in place first** (you don't want to build a sync engine that has to reconcile against a `duty_assignments` table that can still silently double-insert), and **the Permission abstraction should land before Reopen/Audit Center**, since both new features need real permission checks (`reopen_sessions`, `view_audit_log`) rather than being bolted onto the string-based role check one more time.

**Phase 0 — Architecture & Design System** *(foundation, no user-visible change yet)*
- Objectives: lock in design tokens (Section 24), component inventory (Section 27), motion/glass CSS utilities (Sections 25-26) as a Blade-component library; stand up the permission abstraction (Section 21) with behavior-identical seeding.
- DB changes: `permissions`/`role_permissions` tables.
- Backend: `EnsurePermission` middleware replacing `EnsureUserHasRole` (same route groups, new mechanism).
- Frontend: new shared Blade components built but not yet wired into every screen.
- Testing: permission-parity tests (every existing role-gated route still behaves identically).
- Rollout: fully internal, no operator-visible change.

**Phase 1 — Data Integrity & Business Rule Completion** *(the P0 list)*
- Objectives: close every P0 gap from Section 38.
- DB changes: `assignment_fingerprint` unique index, `khidmatguzar_change_log` table.
- Backend: `DutyListImportService::commit()` blank-preservation logic + audit writes, `AttendanceService` Rule 9 locking fix, Extra Present Gender validation.
- Frontend: Extra Present form gains a Gender field; Import Diff UI (first version).
- Testing: full regression against the existing `BusinessInvariantsTest`/`AttendanceCorrectionTest`/etc. suite plus new tests for blank-preservation and the fingerprint constraint.
- Migration risk: low (additive constraint + new table) — the one thing to verify before deploying the unique index is that no existing duplicate fingerprints already exist in production data (a one-time check query, not a code change).
- Rollout: can ship independently of every later phase.

**Phase 2 — Core UX Modernization + Attendance Speed**
- Objectives: live-attendance UX fixes (Section 14/17/27), accessibility fixes (Section 31), motion system wired into existing interactions, Confirmation Dialog component replacing the three inconsistent patterns.
- DB changes: none.
- Backend: minimal (mostly view/Alpine changes).
- Frontend: the bulk of the work — `attendance/live.blade.php` and related partials rebuilt on the new component library from Phase 0.
- Testing: browser-level interaction tests for the corrected confirmation/feedback patterns; accessibility audit pass.
- Rollout: highest-visibility phase for operators — recommend a short beta with a small operator group before full rollout, since this touches the single most-used screen.

**Phase 3 — Session Reopen Workflow**
- Objectives: Rule 6 in full.
- DB changes: `duty_sessions` reopen columns, `session_reopen_log`.
- Backend: `AttendanceService`/`DutySessionController` reopen logic, gated by the Phase 0 permission layer (`reopen_sessions`).
- Frontend: Reopen Dialog + Reason Dropdown component, session-detail button, new `REOPENED FOR CORRECTION` state badge.
- Testing: state-machine tests (reopen → correct → close-again → audit trail intact).
- Rollout: admin-only feature, low blast radius, can ship independently.

**Phase 4 — Offline Attendance (Foundation)**
- Objectives: provisioning API, local IndexedDB queue, basic online-resume sync (happy path only — conflicts land in Phase 5).
- DB changes: `sync_events` table.
- Backend: new `/api/v1/` surface (Section 36), Sanctum cookie auth setup.
- Frontend: first genuine client-side JS module in the app (IndexedDB wrapper, queue manager, connectivity detection) — the one place this blueprint introduces meaningfully more JavaScript than exists today, justified directly by the offline requirement.
- Testing: this phase needs the most new testing infrastructure — simulated offline/online transitions, IndexedDB persistence across reload, idempotent replay of the same `local_event_id`.
- Migration risk: medium — first time this app has shipped a non-trivial client-side data layer; recommend a feature flag so it can be limited to a pilot device/session before wide rollout.
- Rollout: pilot with 1-2 devices in a real low-connectivity session before broader use.

**Phase 5 — Offline Conflict Resolution + Realtime Command Center**
- Objectives: Sync & Conflict Center (admin UI), Session Command Center with live broadcasting.
- DB changes: none beyond Phase 4's `sync_events` (adds resolution columns already specified there).
- Backend: broadcast events (Section 36), conflict-resolution endpoints.
- Frontend: Command Center screen (Section 9), Conflict Card component (Section 20), WebSocket client wiring.
- Testing: conflict-scenario integration tests (two devices, one offline, marking the same assignment differently); realtime event delivery tests.
- Rollout: Command Center can ship to admins even slightly ahead of full offline rollout (it's useful for online-only sessions too) — conflict resolution UI only becomes load-bearing once Phase 4's offline queue is in real use.

**Phase 6 — Analytics, Drill-Down & Audit Center**
- Objectives: drill-down navigation layer, Khidmatguzar 360° extension, Operator Analytics, Admin Audit Center.
- DB changes: none beyond what Phase 1's `khidmatguzar_change_log` already provides for the audit feed.
- Backend: new `AnalyticsController` methods, audit-query endpoints.
- Frontend: breadcrumb component, Audit Timeline/Event components, Data Table component (desktop).
- Testing: query-correctness tests (every new analytics number must match the Rule 10 formula exactly — regression-test against the existing 13 calculation sites).
- Rollout: read-only additions, low risk, can ship incrementally per sub-feature.

**Phase 7 — Advanced Reporting & Import Center**
- Objectives: Import Diff full version, Import History, new report types (operator/audit/import/sync), saved report configurations (P3, only if time allows).
- DB changes: `import_batches` new counter columns.
- Backend: `ReportService` extensions, `DutyListImportService` diff computation.
- Frontend: Import Diff view (Section 18), device-aware export button styling (Section 23).
- Testing: report-parity tests (new report types must follow the existing preview/PDF/Excel-agree pattern, verified via the same approach the prior audit used to confirm the existing 4 report types agree).
- Rollout: incremental, admin-facing, low risk.

**Phase 8 — Hardening, Performance, Accessibility & QA**
- Objectives: full accessibility audit pass, performance validation on actual mid-range Android hardware, `departmentDetailReport()` refactor, any P2/P3 items not yet addressed, full regression pass across the entire test strategy (Section 40).
- Rollout: this phase is a gate, not a feature — nothing in Phases 1-7 should be considered "done" for production until its corresponding tests here pass.

---

## 40. Testing Strategy

Extends the existing PHPUnit Feature-test-heavy approach **[CODE, confirmed strong existing coverage: BusinessInvariantsTest, AttendanceCorrectionTest, DepartmentDetailReportTest, DirectoryProfileTest, GenderReportingTest, ReportExportTest, ItsAuthenticationTest]** — new tests follow the same Feature-test pattern where possible, with new categories added only where genuinely needed.

| Area | Test type | Notes |
|---|---|---|
| Business rules (updated Rule 3/4/5) | Feature (extend `BusinessInvariantsTest`) | blank-preservation, Gender-required-on-Extra-Present |
| Fingerprint uniqueness | Feature + a dedicated migration-safety test | assert DB rejects a duplicate insert directly, not just app-level |
| Reopen workflow | Feature (new `SessionReopenTest`) | full reopen→correct→close-again→audit-trail-intact cycle |
| Rule 9 race closure | Feature, ideally with a genuine concurrency simulation (parallel processes or a deliberately-interleaved transaction test) | the prior audit noted no existing test exercises true concurrency — this is the one area where a new test *type* is warranted |
| Offline events / sync | Feature, hitting the new `/api/v1/sync/events` endpoint directly with crafted payloads (idempotency, conflict, rejection paths) | server-side; does not require a real browser |
| Duplicate sync | Feature | same `local_event_id` submitted twice → second is a no-op, not a duplicate `AttendanceEvent` |
| Conflicts | Feature | craft a scenario where server state has diverged from a queued event's assumed prior state |
| Realtime events | Unit/Feature on the broadcast event classes (assert correct channel/payload), plus a manual QA pass for actual delivery (browser-level realtime testing is typically manual/exploratory, not automated, for a project this size) |
| Permissions | Feature (parity tests from Phase 0 — every existing role-gated route behaves identically under the new mechanism) |
| Reports | Feature (extend `ReportExportTest`/`DepartmentDetailReportTest` pattern for new report types) |
| Responsive UI / accessibility | Manual QA checklist + browser-based smoke test (open each new screen at each breakpoint, verify no horizontal scroll, verify focus order) — full automated visual-regression tooling is a larger investment than this project's current test suite uses and is not recommended as a new dependency unless a specific recurring regression justifies it |
| Reduced motion | Manual QA (toggle OS setting, verify animations disable) |
| Low-end Android | Manual QA on actual mid-range hardware (or Chrome DevTools CPU/network throttling as a proxy) before Phase 8 sign-off |
| Intermittent connectivity | Manual QA + DevTools offline-toggle simulation for the offline queue's happy and conflict paths |

Unit tests remain minimal by design (matching the existing codebase's near-total preference for Feature tests over Unit tests **[CODE, confirmed — only one untouched example Unit test exists today]**) — new Unit tests are added only for genuinely pure-logic pieces (e.g., a fingerprint-computation helper, a diff-computation helper) where a Feature test would be needlessly heavy.

---

## 41. Risks & Tradeoffs

- **Offline sync is the highest-complexity new subsystem in this blueprint** relative to the app's current total complexity — it introduces the app's first meaningful client-side JavaScript, its first non-Blade API surface, and its first genuinely hard-to-test-automatically feature (real device connectivity transitions). Recommend treating Phase 4 as the project's biggest single risk item and giving it the most generous testing/pilot runway (Section 39 already reflects this with a feature-flagged pilot rollout).
- **Realtime infrastructure (Reverb/websockets) is new operational surface** — someone now needs to run/monitor a websocket server (or a third-party service) that didn't exist before. This is a real ongoing operational cost, not just a one-time build cost — worth confirming there's appetite for that before Phase 5.
- **The `REOPENED FOR CORRECTION` session state is a schema/state-machine change to a system whose entire value proposition is historical accuracy** — get this migration and its tests right before shipping; a bug here has outsized consequences given the "never lose attendance history" principle this whole app is built around.
- **Permission-abstraction refactor (Phase 0) touches every access-controlled route** — low logical risk (behavior is meant to be identical) but broad surface area; the parity-test requirement in Section 40 is not optional, it's the only thing that makes this refactor safe.
- **Desktop sidebar navigation is the one place this blueprint changes shipped, working UI structure** (not just adds to it) — worth confirming this is genuinely wanted before Phase 2/6, since it's a bigger change to muscle memory for any admin who already uses the app on desktop today, however rare that currently is.
- **Glassmorphism and motion are the most subjective parts of this document** — "restrained," "premium," "calm" are judgment calls; recommend a small internal design review (even just 2-3 real screens built and looked at on an actual phone) before committing the full component library to the glass/motion treatment described here, rather than approving Sections 25-26 purely from this written spec.

---

## 42. Genuine Unresolved Questions

Only questions that cannot be resolved from code, existing docs, or your finalized rules — with options and a recommended default for each.

1. **Should Viewer see the Admin Audit Center in read-only form?** *Why it matters*: the Audit Center exposes operator-level scrutiny (who marked what, when) — this may be more than "read-only reporting" was ever meant to include for Viewer. *Options*: (a) No — Audit Center is admin-only. (b) Yes, fully read-only. (c) Yes, but without the operator-identity column (show "what happened" without "who did it"). *Recommended default*: (a) No — keeps Viewer's scope exactly as it is today, safest interpretation of "define appropriate read-only access based on current behavior," and can be loosened later without any schema change.

2. **Should `duty_sessions.status` gain a literal `reopened` enum value, or should reopening be modeled as `active` + a separate `is_reopened_for_correction` flag?** *Why it matters*: affects every place that currently checks `status === 'active'` **[CODE, e.g. `isActive()` in `DutySession.php`]** — a new enum value means auditing every such check to make sure "reopened" is treated as "active enough to mark attendance" everywhere it needs to be; a flag avoids that but adds a second thing to check everywhere `isActive()` is used today. *Options*: (a) new enum value `reopened`, with `isActive()` updated to return true for it. (b) keep `active`, add boolean flag, `statusTone()`/UI reads the flag for badge color. *Recommended default*: (b) — smaller blast radius against existing `isActive()`/`isClosed()` call sites, and the badge-color distinction (Section 17) only needs a UI-layer check, not a state-machine-wide one.

3. **Is full cryptographic event-signing for offline events actually needed, or is server-side re-validation (Section 33) sufficient given the 1-5 device, single-organization trust model?** *Why it matters*: signing adds real implementation complexity (key management, device enrollment) that may not be proportionate. *Options*: (a) no signing, rely on authenticated-session + idempotency-key + server-side business-rule re-validation (as designed in Section 33). (b) add HMAC event signing keyed per-device. *Recommended default*: (a) — proportionate to stated scale and threat model; revisit only if a specific incident (device theft, credential sharing) makes it necessary.

4. **What charting library, if any, currently renders the existing `analytics/overview` trend chart?** *Why it matters*: Section 27's Chart component and Section 6's Attendance Trends/Drill-Down analytics depend on knowing whether the existing chart approach can support new comparison views, or whether a library decision is needed. *Options*: (a) verify current implementation first (quick code check, not a product decision) before Phase 6 begins. (b) assume a library swap is needed and plan for it now. *Recommended default*: (a) — this is actually a **[CODE]**-answerable question, not a product one; it wasn't in scope for this audit pass and should simply be checked before Phase 6 starts, not decided speculatively here.

5. **Should import rollback (undoing a committed import) be built, or is preview-time prevention sufficient?** *Why it matters*: your prompt says "safe review/rollback strategy where appropriate" — "appropriate" is doing real work in that sentence, and rollback after attendance may already be marked against an import's assignments is a genuinely hard problem (what happens to those `AttendanceEvent` rows?). *Options*: (a) preview-time only — no rollback, get the diff right before confirming (Section 18's recommendation). (b) build limited rollback, only allowed when zero attendance has been marked yet against the batch's assignments. *Recommended default*: (a) for 2.0; (b) is a reasonable P3/future item if a real incident demonstrates the need, but shouldn't be built speculatively given the added complexity of safely unwinding a partially-attended import.

6. **What exact hex/token should represent "offline" and "conflict" states, given the existing five-tone system (gray/blue/emerald/orange/violet) doesn't have a natural sixth slot?** *Why it matters*: purely a design decision, but affects the token table in Section 24. *Options*: (a) reuse existing tones with icon differentiation only (offline=slate/gray, conflict=deeper orange) — no new hues. (b) introduce one new hue (e.g., amber, distinct from orange) for conflict specifically, since it's a genuinely different concept from "pending." *Recommended default*: (a) — consistent with your explicit "do not invent a completely different palette" instruction; icon+label differentiation (already required everywhere per Section 31) makes a sixth hue unnecessary.

---

## Final Recommendation

Sequence the P0 data-integrity fixes and the permission-abstraction refactor first (Phases 0-1) — they're small, low-risk, and everything else in this blueprint is safer to build once they're done. Then invest the largest, most careful engineering effort in Offline Attendance (Phase 4) specifically, since it's the one genuinely new architectural capability with real failure modes if rushed — pilot it narrowly before wide rollout. Everything else in this document (Command Center, Audit Center, drill-down analytics, motion/glass polish) is lower-risk, additive, and can be sequenced flexibly around real operational feedback from the first few phases rather than committed to rigidly up front.
