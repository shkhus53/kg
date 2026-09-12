# KG Attendance — Full System Audit & 2.0 Discovery

Read-only audit. No files modified, no migrations run, no packages installed. Generated 2026-09-11.

---

## 1. Executive Summary

**What's good:** The attendance state machine (`AttendanceService`) is the strongest part of the codebase — every mutation is transactional, row-locked, and idempotent, with a real DB-level fallback (unique constraints + caught `QueryException`) on the two paths that matter most (`khidmatguzars.its_id`, `extra_presents` composite unique). Snapshot-on-write for historical accuracy is correctly and consistently applied. `ReportService` as single source of truth for preview/PDF/Excel is a genuinely good architectural call — no report-parity bugs found. Multi-assignment-per-person is correctly modeled and correctly surfaced in the live UI (each assignment shown separately, operator picks). Security posture is solid for an internal tool: no SQLi, no XSS, no CSRF gaps, no exploitable mass-assignment path today.

**What's weak:** Three of the ten stated "official" business rules are not implemented at all or only partially (Rule 4/5 gender+idara on Extra Present, Rule 6 admin reopen, Rule 3's blank-overwrite hazard). The one duplicate-prevention mechanism that matters most for data integrity — `assignment_fingerprint` — has no DB-level unique constraint, unlike its two siblings (`its_id`, extra-present composite) which do. There's a real, if narrow, race allowing a person to become both scheduled and extra-present simultaneously (Rule 9 hole). Master-data overwrites on re-import are completely unaudited — a bad file can silently corrupt a person's name/gender/idara with no trace of what changed or who did it. `APP_DEBUG=true` ships as the `.env.example` default, which is a real production risk if ever copied verbatim. The live-attendance screen (the single most-used screen, used hundreds of times per shift) has fixable friction: no autofocus, inconsistent submit feedback, inconsistent confirmation patterns for destructive actions.

**Overall system health:** Solid foundation, not a rewrite candidate. Laravel/Blade/Alpine + WebView-wrapper architecture is appropriate for this app's actual requirements (single org, no offline need, low JS complexity). The gaps are concentrated: business-rule completeness, one missing unique index, one audit-trail gap, and UX polish on the operator's core workflow — not systemic architecture problems.

---

## 2. Current Architecture

- **Stack**: PHP 8.3, Laravel `^13.17`, PHP 8.3 attribute-based Eloquent config (`#[Fillable]`/`#[Hidden]`), `maatwebsite/excel ^3.1.68`, `barryvdh/laravel-dompdf ^3.1`. Frontend: Vite + Tailwind v3/v4 mixed tooling, Alpine.js used only for the bottom-nav dropdown. No SPA framework, no `fetch`/`axios`/Livewire/Turbo anywhere in the codebase (confirmed via repo-wide grep) — every mutation is a classic form POST + full-page redirect.
- **Auth**: ITS-number-based (not email), session-guard, no Sanctum/Passport. Roles are a flat `users.role` enum (admin/operator/viewer), enforced entirely by route-group middleware (`EnsureUserHasRole`), no policy classes.
- **Data model core**: `DutySession` (draft→active→closing→closed, one-directional) owns `ImportBatch`es, which produce `DutyAssignment` rows (the scheduling unit), which accumulate `AttendanceEvent`s (audit log) as `current_status` transitions. `ExtraPresent` is a separate table for unscheduled attendees. `Khidmatguzar` and `Department` are shared master records; snapshot columns on `DutyAssignment`/`ExtraPresent` freeze their values at write time.
- **Import pipeline**: Excel/CSV → `RawSheetImport` (loads whole file into memory, no chunking) → `DutyListImportService::parse/buildPreview/commit` → fingerprint-based dedup (sha256 of its_id+venue+block/day/day_alias/seat), app-level only, no DB unique constraint backing it.
- **Reporting**: `ReportService` is the single query layer for preview screens, PDF (dompdf), and Excel (maatwebsite/excel via a shared `ArraySheet` styling class) — guarantees the three outputs never disagree.
- **Android**: thin native WebView shell (`MainActivity.java`) pointed at a hardcoded production URL — not Capacitor/Cordova, no separate app logic, just chrome/inset/download handling.
- **PWA**: real but deliberately minimal — service worker never caches navigations or API responses (attendance data must never be stale), only caches hashed static assets.

---

## 3. Business Rule Compliance

| # | Rule | Current Implementation | Gap | Risk | Required Decision |
|---|---|---|---|---|---|
| 1 | Multiple departments per person per session | **Implemented.** No unique constraint on `(session, khidmatguzar)`; dedup is per-fingerprint not per-person. Tested (`BusinessInvariantsTest`). | None | None | None |
| 2 | Multiple duty-list files merged into one session | **Implemented.** `buildPreview()` checks cross-batch fingerprints at read time; `commit()` never re-checks under a DB constraint. | Concurrent imports of overlapping files can both pass preview and both commit → true duplicate assignment rows. | Medium (data integrity, only under concurrent import) | None — this is a bug to fix, not a business decision |
| 3 | Newer Excel value replaces master data for existing ITS | **Implemented via `updateOrCreate`,** but blank cells in the new file **null out** previously good values (no "don't overwrite good data with blank" guard). No audit trail of what changed. | Silent data corruption from sloppy re-uploads; no way to see what changed or revert. | High (silent, undetectable corruption + no audit) | Should blank cells in a re-import be ignored (keep old value) or authoritative (always overwrite, even to blank)? |
| 4/5 | Extra Present requires ITS + Name + Gender + Idara, ITS mandatory | **Partially implemented.** ITS required (enforced); Name required only when ITS is unknown. **Gender and Idara are not collected at all** — no field in the form, no validation rule, not stored. | Rule 4 is simply not built. | Medium (data completeness for Extra Present records) | Do you want Gender/Idara added to the Extra Present form as required fields (this is a real feature gap, not a bug)? |
| 6 | Only Admin can reopen a Closed session | **Not implemented.** Zero code, no route, no `reopen()` method anywhere. `closed` is a terminal state today. | Feature doesn't exist. | Low until needed operationally | Is "reopen" needed for 2.0? What triggers it (correction after close, wrong-session-closed-early)? Should reopening re-open all assignments to pending, or just unlock corrections? |
| 7 | Concurrent operators — architecture safety | **Architecture is safe** for the common case (row-level locks + transactions correctly prevent double-processing on the same assignment). One narrow gap: `markExtraPresentKnown/New`'s existence-check isn't locked against a concurrent import commit (see Rule 9). | See Rule 9 | Low-Medium (narrow race window) | None — this is a bug to fix |
| 8 | Multiple assignments shown separately, operator picks | **Implemented correctly.** Live UI renders one checkbox per assignment; `markPresentMany` only processes submitted IDs. | None found | None | None |
| 9 | Scheduled + Extra Present mutually exclusive | **Implemented at app level only, no DB constraint.** Real race: an import committing a new assignment for person X concurrently with an extra-present marking for X in the same session can leave X in both states — neither transaction locks against the other. | Data integrity race, narrow window but real. | Medium | None — fix direction is technical (see roadmap) |
| 10 | Attendance Rate = Present/Scheduled×100, Extra excluded | **Implemented consistently** — verified across 13 separate calculation sites (ReportService, AnalyticsController, DutySessionController, live.blade.php). Extra Present never enters any denominator. | Test coverage gap: no test explicitly asserts extra-present doesn't affect rate (indirectly covered, not directly). | Low | None |

---

## 4. Database Audit

**Preserve (correct decisions):**
- `khidmatguzars.its_id` UNIQUE, `departments.normalized_key` UNIQUE, `extra_presents` UNIQUE `(duty_session_id, khidmatguzar_id)` — all three are genuine DB-level race guards, and two of three (its_id, extra_presents) are correctly exploited in application code via caught `QueryException` fallback.
- Full snapshot columns on `DutyAssignment`/`ExtraPresent` — correct historical-integrity design.
- Restrict-by-default FKs to `khidmatguzars`/`departments`/`users` (no cascade) — correctly prevents deleting a person/department/user from silently destroying attendance history.
- Composite analytics indexes `(department_id, current_status)`, `(khidmatguzar_id, current_status)`, `duty_sessions.date` — correctly cover the actual filter/aggregate patterns in `AnalyticsController`.

**Gaps found:**
- **`assignment_fingerprint` has no unique index** — only a plain composite index `(duty_session_id, assignment_fingerprint)`. This is the one dedup mechanism that matters most and is the only one of the three without a DB backstop. **Fix: add `unique(['duty_session_id','assignment_fingerprint'])`, wrap `commit()`'s insert in a duplicate-key catch like `insertExtraPresent()` already does.**
- **Cascade chain risk**: `DutySession` → `ImportBatch`/`DutyAssignment` (cascade) → `AttendanceEvent` (cascade); `DutySession` → `ExtraPresent` (cascade). Deleting one `DutySession` row would silently destroy its entire attendance history — inconsistent with the stated "historical integrity" goal. Currently dormant (no delete route exists for `DutySession`), but the schema doesn't defend against a future delete feature being added carelessly.
- **`Department::firstOrCreate` in `DutyListImportService::commit()`** has no try/catch around the possible race-induced `QueryException`, unlike the `its_id` and extra-present paths — inconsistent hardening.
- **`extra_presents.khidmatguzar_id` has no explicit index** — currently covered incidentally by the FK's auto-created index, not a deliberate choice; fragile if that FK's implicit index ever changes.
- No index on `khidmatguzars.full_name` for the directory's default alphabetical sort — will filesort at scale.
- Rule 9's cross-table gap (Section 3) has no practical DB-level fix in plain Laravel migrations without a trigger — flagged as accepted residual risk unless deliberately built.

**Scalability**: `duty_assignments` and `attendance_events` grow unbounded with usage — both are otherwise adequately indexed for current query patterns. The `add_its_number_to_users_table` backfill loop is an unchunked N-row pattern but operates only on the small `users` table — fine today, not a safe template to copy for large tables later.

---

## 5. Attendance Engine Audit

**State machine as actually implemented** (verified in code, not assumed): `draft → active` (`DutySessionController::activate`, guarded), `active → closing → closed` (`AttendanceService::closeSession`, `closing` is a transient in-transaction state that either commits to `closed` or rolls back to `active` if pending assignments remain). **No transition out of `closed` exists anywhere** — confirmed via repo-wide grep for "reopen," zero matches.

**Transitions**: `pending → present`, `pending → absent`, `absent → present` (correction only — reverse direction is blocked, confirmed in `AttendanceService::markPresent`). All wrapped in `DB::transaction()` + `lockForUpdate()` on both the session row and the target assignment row(s).

**Concurrency safety — verified by tracing two simultaneous `markPresent` calls on the same assignment**: both transactions attempt `SELECT ... FOR UPDATE` on the same row; one blocks until the other commits; the second re-reads post-lock and correctly returns `already_present` — no double-processing, no lost update. This is sound.

**Gap**: every mutating method locks the entire `DutySession` row first, before locking the specific `DutyAssignment` row. This means all operators marking attendance within the *same active session* serialize on that one session row — a de facto throughput ceiling. At small operator counts (a handful of scanners) this is invisible; at 10–50 concurrent operators it becomes the actual bottleneck, independent of how many distinct assignment rows they're touching. This is a deliberate simplification for correctness (it prevents a race with `closeSession`), not an oversight, but should be revisited if concurrent-operator scale becomes real.

**Second gap**: `markExtraPresentKnown/New`'s check for an existing `DutyAssignment` is unlocked — see Rule 9 in Section 3.

**Audit trail**: every attendance action produces an `AttendanceEvent` (who, when, what, individual/bulk context) — this part is complete and well-designed. What's missing is an audit trail for *master-data* overwrites during import (Section 12).

---

## 6. Import Audit

**Multi-file → one merged roster**: architecturally supported (Rule 2) — repeat imports append new `ImportBatch`es and `DutyAssignment`s to the same session, with fingerprint-based cross-batch dedup checked at preview time. The gap is that this check is read-only and not re-verified with a DB constraint inside `commit()`'s transaction (see Section 4).

**Pipeline mechanics**: `RawSheetImport` (a `ToArray` concern) loads the **entire file into memory** in one shot — no chunking. `config/excel.php`'s `chunk_size = 1000` setting is **not applied anywhere in this pipeline** (it only affects `FromQuery`/`WithChunkReading` importers, and this pipeline uses neither) — confirmed dead configuration. `commit()` inserts rows **one at a time** (`Department::firstOrCreate`, `Khidmatguzar::updateOrCreate`, `DutyAssignment::create()` per row), not in bulk, though department/person lookups are cached per-batch to avoid redundant queries.

**At scale**: fine at hundreds to a few thousand rows. Starts to strain memory around 10–20k rows, and by 50–100k rows risks PHP `memory_limit` exhaustion (no streaming) and a multi-thousand-round-trip `commit()` transaction (lock hold time grows with row count). Given Miqaat-scale imports (thousands of duty assignments across departments) this is a realistic future pain point, not a current emergency.

**Master-data replacement**: correctly overwrites on updateOrCreate, but with the blank-cell-nulls-good-data hazard from Rule 3, and zero audit trail — this is the most consequential import-related gap.

---

## 7. UI/UX Audit

Confirmed: **zero AJAX/fetch/Livewire/Turbo anywhere in the codebase** — every mutation is a full-page form POST + redirect. This is a deliberate architectural choice (simplicity, WebView compatibility) with real UX cost on a screen used hundreds of times per shift.

**Top findings** (full list of 15 in the source audit; highlights below):
1. **ITS input on the live-attendance screen is not autofocused** — every visit requires an extra tap before the numeric keyboard appears, on the single most-repeated action in the app.
2. **Submit feedback is inconsistent** — the absent→present correction form disables itself on submit; the plain present/absent/bulk/extra-present forms give zero feedback, risking accidental double-submits on slow networks.
3. **Confirmation patterns are inconsistent across three different destructive actions** — native `confirm()` dialog for session activate/close, a custom Alpine confirm card for "mark all remaining absent," and *no confirmation at all* for the bulk multi-assignment present action (the broader, riskier action is less guarded than the narrower one).
4. **ITS field allows up to 20 characters with no digit pattern**, unlike the login form which enforces exactly 8 digits — malformed input just silently returns "not found."
5. Icon-only header buttons (hamburger, back-arrow) are 32px, under the 44px touch-target guideline, with no `aria-label`.
6. Auto-submitting department filter `<select>` with no warning — jarring on flaky mobile connections, fails WCAG "no unexpected context change on input."
7. Empty states are inconsistently polished — one screen has a well-designed icon+CTA empty state, most others are bare one-line text.

---

## 8. Operator Workflow Audit

Minimum path from bottom-nav to marking one person present (single-assignment case): tap Live Attendance FAB → page load → type ITS (no autofocus, extra tap) → tap Search → page load → tap Mark Present → page load. **3 full page reloads minimum**, 4+ if using name-search fallback. Multiple assignments are correctly and clearly presented as separate, individually selectable rows — this is a genuine strength, not a gap. Correction (absent→present) is functionally correct but visually under-emphasized (a plain gray caption line next to a prominent red "already absent" box) — an operator scanning quickly could miss that a different action is available. Extra Present entry doesn't warn the operator upfront if no departments exist yet in the session (the disabled state only appears after they've filled the form).

---

## 9. Admin Workflow Audit

Answerable today directly from the dashboard/analytics/reports without extra tooling: active session identity, present/absent/pending/extra counts (dashboard + close-summary + analytics overview), department attendance quality (analytics/departments + insights), what imports happened (session show page lists import batches with uploader). **Not currently answerable without a DB console**: "what changed in the last import" (no diff/audit for master-data overwrites), "who marked this specific record and can I dispute/reverse it beyond the built-in absent→present correction" (AttendanceEvent has the who/when, but there's no admin UI surfacing a searchable audit log — it's implicit in the data model, not exposed). Session reopening (Rule 6) doesn't exist, so "can I safely reopen a mistakenly closed session" is currently answered "no."

---

## 10. Analytics Audit

**Current state**: overview (date/dept/session filters, gender breakdown, session trend, top-5 departments), department breakdown, insights (best/worst department, most active session, multi-assignment count, pending-in-active), full Khidmatguzar directory with per-person lifetime stats via correlated subqueries, individual profile pages. All verified to correctly exclude Extra Present from rate denominators and correctly use `gender_snapshot` (not live `gender`) for historical reports — this was specifically tested (`GenderReportingTest`) and confirmed in code.

**Query cost model** (verified, not assumed): the directory's six correlated subqueries per row are bounded by pagination (≈120 subquery executions per 20-row page, not per full table) — this scales acceptably into the tens of thousands of Khidmatguzar rows. The part that actually degrades linearly with total table size, regardless of pagination, is the **leading-wildcard `LIKE '%term%'` search** on `full_name`/`jamaat` — no B-tree index can serve this, and it will become the real bottleneck (full table scan) well before the correlated subqueries do, once the Khidmatguzar table reaches tens of thousands of rows.

**Suggested additions**:
- **Must Have**: none identified as blocking — current analytics correctly serve the stated business rules.
- **Valuable**: a searchable admin-facing audit log view (surfacing `AttendanceEvent` + a future master-data-change log) for dispute investigation; a "recently changed master data" report tied to Rule 3's overwrite behavior.
- **Nice to Have**: department-over-time trend (not just per-session), operator-level activity stats (who marked how many, useful for staffing).
- **Avoid**: any real-time/live-updating dashboard given the deliberate no-AJAX architecture — would require a disproportionate rearchitecture for a benefit not currently requested.

---

## 11. Reporting Audit

`ReportService` is confirmed to be the single source of truth for preview/PDF/Excel across all four report types — no divergence found between what a preview shows and what gets exported, because both call the identical service methods. Multi-assignment people are represented correctly (each assignment is its own row in detailed exports, not collapsed). Extra Present is consistently a separate sheet/section, never merged into scheduled-attendance totals. Historical snapshots are respected throughout.

**Cost concern**: `departmentDetailReport()` (used for the "select specific departments" detail report) runs 3 separate queries **per department** in a `.map()` loop — fine for a handful of departments, but at 30–50+ departments selected simultaneously this becomes 90–150+ queries, and its sibling `departmentReport()` (the "all departments" report) already avoids this pattern with grouped aggregate queries — the detail-report loop should be refactored to match. **dompdf-specific risk**: large "Detailed Attendance" PDF tables (thousands of rows, e.g. a full-Miqaat all-departments-no-date-filter export) risk slow rendering or memory/timeout failures — the Excel path handles the same row counts fine; this is dompdf's known characteristic, not a code bug.

---

## 12. Security Audit

| Severity | Finding | Location |
|---|---|---|
| **HIGH** | `APP_DEBUG=true` ships as the `.env.example` default — if copied to production verbatim, any unhandled exception (including one triggered by unvalidated `from`/`to` report date params) leaks stack traces, SQL with bound values, and file paths. | `.env.example:4` |
| **HIGH** | No audit trail for Khidmatguzar master-data overwrites during re-import — `updateOrCreate` silently replaces name/gender/idara/jamaat/jamiaat with no before/after log. A bad or tampered upload corrupts the master record untraceably. | `app/Services/DutyListImportService.php:275-284` |
| **MEDIUM** | File upload accepts xlsx/xls/csv up to 10MB with no row/decompressed-size cap — a crafted file could still cause elevated memory use (modest DoS, mitigated by role-gating the route to admin/operator only). Cell values (including formula-injection payloads like `=HYPERLINK(...)`) are stored and later re-emitted into Excel exports without sanitization — inert in Blade (fully escaped) but live again once re-exported to Excel. | `app/Http/Controllers/DutyListImportController.php:34-36`, `app/Exports/SessionAttendanceExport.php` |
| **MEDIUM** | Session cookie `secure` flag isn't set in `.env.example` (defaults to not-secure); combined with the debug flag, a rushed production deploy could ship both misconfigured. | `config/session.php:172` |
| **LOW-MEDIUM** | Report date-range params (`from`/`to`) aren't format-validated before hitting `whereBetween` — not SQL-injectable (parameterized), but a malformed value could trigger a DB error that, combined with `APP_DEBUG=true`, discloses internals. | `app/Http/Controllers/ReportController.php:151-152` |
| **LOW** | Login rate-limiting is keyed `its_number+ip` — bypassable via IP rotation (standard Laravel Breeze limitation, not unique to this app). No account-level lockout independent of IP. | `app/Http/Requests/Auth/LoginRequest.php:70-73` |
| **LOW** (latent, not exploitable today) | `User.role` is mass-assignable; no current write path uses raw request data to write it, but a future naive "edit profile" feature could introduce privilege escalation. | `app/Models/User.php:13` |
| **INFORMATIONAL** | No IDOR protection on route-model-bound resources (`/khidmatguzars/{id}`, `/sessions/{id}`) — but this matches the stated design (single org, all authenticated staff see all data, differentiated only by write permission). Not a finding unless that scope assumption changes. | `routes/web.php` |
| OK, verified clean | CSRF (no exceptions configured), SQL injection (all raw SQL is hardcoded strings, all user input parameterized), XSS (zero unescaped Blade output found repo-wide) | — |

---

## 13. Performance Audit

**N+1 queries**: none found — the codebase is consistently disciplined about eager-loading (`with()`) everywhere a relation is accessed in a loop.

**Real bottlenecks identified** (in order of when they'd actually bite):
1. **Directory free-text search** (`full_name`/`jamaat` leading-wildcard `LIKE`) — degrades to full table scan regardless of pagination, starts hurting around 20–50k Khidmatguzar rows. No plain-index fix exists; needs FULLTEXT or a different search strategy if this scale is expected.
2. **Import pipeline memory/row-by-row commit** — fine to a few thousand rows, real risk by 50–100k rows (unchunked `ToArray`, per-row `Eloquent::create()` calls, `chunk_size` config confirmed unused/dead).
3. **`departmentDetailReport()`'s per-department query loop** — starts costing noticeably at 30–50+ departments selected in one export.
4. **`DutySession` row-lock serializing all attendance mutations within one active session** — invisible at small operator counts, becomes the real throughput ceiling at 10–50+ truly concurrent operators on the same session.
5. **dompdf on large detailed-attendance tables** — risk of slow/memory-heavy rendering past roughly 3–5k rows in one PDF.

None of these are urgent at current likely scale (single organization, per-session attendance in the hundreds to low thousands) — they're the correct list to revisit if/when KG Attendance scales to multi-thousand-person Miqaats or many simultaneous operators.

---

## 14. Architecture Recommendations

**KEEP:**
- Snapshot-on-write pattern (person/department data frozen at assignment/extra-present creation time).
- Fingerprint-based duplicate detection concept (the *mechanism* needs a DB backstop, not a redesign — see Section 4).
- Transactional attendance state machine with row locking in `AttendanceService`.
- Shared `ReportService` as single source of truth for preview/PDF/Excel.
- Extra Present as a fully separate table/concept from scheduled attendance.
- Server-rendered Blade + minimal Alpine architecture — appropriate given no offline requirement, single-org scope, and WebView deployment. **Should KG Attendance remain server-rendered?** Yes — there is no product requirement here (real-time multi-user collaboration, offline capability, complex client-side state) that would justify the cost of an SPA rewrite. The full-page-reload UX friction identified in Section 7 can be substantially fixed with targeted, incremental improvements (autofocus, consistent Alpine-based submit-disable, consistent confirmation patterns) without abandoning the server-rendered model.
- PWA approach (installability only, no offline attendance claim) — correctly scoped, don't expand it into an offline-first system without a clear business need (attendance-marking while genuinely offline is a different, much harder problem: conflict resolution, sync, etc.).
- Android WebView wrapper — correctly thin; don't invest in native features unless a specific WebView limitation is blocking a real requirement.

**CHANGE:**
- Add unique DB constraint on `(duty_session_id, assignment_fingerprint)` — this is a bug fix, not a design change.
- Add a master-data-change audit log for Khidmatguzar overwrites during import.
- Fix `APP_DEBUG` default in `.env.example`.
- Refactor `departmentDetailReport()`'s per-department loop to match `departmentReport()`'s grouped-query pattern.
- Convert the import pipeline to chunked reading + bulk inserts once row counts routinely exceed a few thousand.

**INVESTIGATE (needs a product decision first, not a pure engineering call):**
- Whether/how session reopening should work (Rule 6) — state machine design question, not yet a technical one.
- Whether Gender/Idara should be added to Extra Present (Rule 4/5) — feature scope question.
- Whether the `DutySession`-row-lock concurrency ceiling needs addressing now or can wait — depends entirely on expected concurrent-operator count, which is a business/operational fact, not something derivable from code.

---

## 15. KG Attendance 2.0 Vision

Same visual identity (navy/slate Tailwind palette, mobile-first card layout, bottom-nav shell) — the goal is not a redesign, it's removing friction from the one workflow that runs hundreds of times per shift. Concretely: the live-attendance screen becomes fast enough that an operator never thinks about the interface — autofocus by default, instant visual confirmation of every action (even within the existing full-page-reload model, via consistent disable-on-submit styling), one consistent confirmation pattern for every destructive/bulk action instead of three different ones today, and the correction affordance visually distinct enough that it's never missed under time pressure. Underneath, the three real integrity gaps (fingerprint uniqueness, master-data audit trail, Rule 9's race) get closed so the data operators are staring at is provably correct, not just usually correct. Admin gets a genuine audit-log view instead of the current implicit "it's in the database somewhere" state. Everything else — architecture, PWA scope, Android wrapper, reporting engine — stays as-is; it's already doing its job.

---

## 16. Prioritized Upgrade Roadmap

### P0 — Critical (fix before any major upgrade work)
- Add unique constraint on `(duty_session_id, assignment_fingerprint)` + catch-and-retry in `commit()`. *Files: migration, `DutyListImportService.php`. Complexity: low. Risk: low (additive constraint). Dependency: none.*
- Fix `APP_DEBUG=false` default in `.env.example`; add a deploy-time check. *Complexity: trivial. Risk: none.*
- Add master-data-change audit log for import overwrites. *Files: new migration + table, `DutyListImportService::commit()`. Complexity: medium. Risk: low.*
- Close the Rule 9 race (lock `DutyAssignment`/`ExtraPresent` existence-checks consistently across both the import-commit and extra-present-marking code paths). *Files: `AttendanceService.php`, `DutyListImportService.php`. Complexity: medium. Risk: medium (touches core state machine — needs the existing test suite to stay green).*

### P1 — High Value
- Live-attendance UX fixes: autofocus ITS field, consistent submit-disable across all four mutation forms, consistent confirmation pattern for destructive/bulk actions, visually distinguish the absent→present correction affordance. *Files: `attendance/live.blade.php` + related partials. Complexity: low-medium (mostly Blade/Alpine, no backend changes). Risk: low.*
- Decide and implement Rule 6 (session reopening) if operationally needed. *Files: new controller method/route, `DutySession` model, `AttendanceService`. Complexity: medium. Risk: medium (state machine change) — blocked on product decision (Q-list below).*
- Decide and implement Rule 4/5 (Gender/Idara on Extra Present) if required. *Files: `AttendanceController.php`, migration (or reuse existing nullable columns), `attendance/live.blade.php` form. Complexity: low. Risk: low.*
- Fix Rule 3's blank-overwrite hazard (skip overwriting a field when the new file's cell is blank, unless explicitly intended otherwise). *Files: `DutyListImportService.php`. Complexity: low. Risk: low — blocked on product decision.*

### P2 — Valuable
- Chunked import pipeline (switch `RawSheetImport` to `WithChunkReading`, bulk inserts in `commit()`) — needed once import sizes routinely exceed a few thousand rows. *Complexity: medium-high (touches preview/dedup logic). Risk: medium.*
- Refactor `departmentDetailReport()` to grouped queries instead of per-department loop. *Complexity: low-medium. Risk: low.*
- Admin-facing audit-log view (surfacing `AttendanceEvent` + new master-data-change log for investigation). *Complexity: medium. Risk: low.*
- Add `khidmatguzars.full_name` index; revisit directory search strategy (FULLTEXT) if search volume grows. *Complexity: low. Risk: low.*
- Session-row-lock scope reduction if concurrent-operator count is expected to grow past ~10-20. *Complexity: high (core concurrency logic). Risk: high — needs careful test coverage before touching.*

### P3 — Future (not necessary now)
- Any real-time/live-updating dashboard.
- Offline attendance marking.
- Department-scoped (tenant-style) authorization, unless the org structure changes to require it.
- dompdf pagination/streaming improvements — only if a specific large export is actually failing in practice.

---

## 17. Questions for Husain

**Must answer before development:**
1. Rule 6 — what should trigger a session reopen (correction needed after close, wrong session closed by mistake)? Should reopening reset all assignments to `pending`, or just unlock the correction flow while keeping current statuses? *Cannot be determined from code — requires product decision.*
2. Rule 3 — when a re-imported file has a blank cell for a field that previously had a value, should the blank be ignored (keep old value) or treated as authoritative (clear the field)? This directly affects whether the current behavior is a bug or intended. *Cannot be determined from code.*
3. Rule 4/5 — is Gender/Idara on Extra Present a hard requirement for 2.0, or was it aspirational when the rules were written? If required, should it block submission (like ITS) or just be strongly encouraged? *Cannot be determined from code.*

**Important:**
4. What's the realistic expected concurrent-operator count during a live session — a handful of scanners, or dozens? This determines whether the `DutySession`-row-lock concurrency ceiling (Section 13/16) needs addressing now or can be deferred.
5. What's the realistic expected import file size (rows per Excel file, files per session)? Determines whether the chunked-import work (P2) should move up in priority.
6. Should there be an admin-facing UI for investigating a disputed attendance record (search by person/session, see full event history), or is direct DB/Tinker access by a developer an acceptable process for now?
7. Is the "any authenticated user can see any record" access model (Section 12, IDOR finding) intentional for the foreseeable future, or is department-scoped visibility (e.g., an operator only sees their own department) something 2.0 should introduce?

**Optional:**
8. Is there appetite for adding operator-level activity stats (who marked how many, useful for staffing/accountability) to analytics?
9. Should large "all departments, full date range" exports be capped or restricted to Excel-only (skip PDF) to avoid the dompdf performance risk identified in Section 11, or is this not a scenario that occurs in practice?
10. Any plans for the app to support more than one organization/tenant sharing the same instance? (Currently assumed no — confirms the IDOR finding is informational, not a real gap.)
