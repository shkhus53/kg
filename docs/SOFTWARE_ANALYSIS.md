# KG-Attendance — Software Analysis

Deep technical analysis of app. Generated 2026-09-11.

## 1. Overview

Khidmatguzar duty-attendance system. Laravel backend, server-rendered Blade + Alpine.js sprinkles (no SPA), PWA-wrapped, plus native Android WebView shell pointing at deployed site. Core purpose: import duty-roster Excel/CSV per session, mark people present/absent live, generate PDF/Excel reports.

## 2. Tech Stack

**Backend**
- PHP `^8.3`, Laravel `^13.17`
- Models use PHP 8.3 attributes (`#[Fillable([...])]`, `#[Hidden([...])]`) instead of classic `$fillable`/`$hidden` properties
- `barryvdh/laravel-dompdf ^3.1` — PDF export
- `maatwebsite/excel ^3.1.68` — Excel import/export
- `laravel/tinker`, dev: `laravel/breeze ^2.4` (origin scaffold, heavily customized — ITS-based auth replaces email/password reset/registration), `laravel/pail`, `laravel/pint`, `phpunit/phpunit ^12.5.12`, `mockery`, `fakerphp/faker`

**Frontend**
- Vite `^8.0.0` + `laravel-vite-plugin ^3.1`
- Tailwind CSS `^3.1.0` + `@tailwindcss/forms` + `@tailwindcss/vite ^4.0.0` (mixed v3/v4 tooling)
- Alpine.js `^3.4.2` — used only for bottom-nav "More" dropdown ([bottom-nav.blade.php](resources/views/components/shell/bottom-nav.blade.php))
- [resources/js/app.js](resources/js/app.js) minimal: just boots Alpine. No custom JS modules, no SPA framework.

Classic server-rendered Blade app with full-page navigation.

## 3. Domain Models (`app/Models/`)

| Model | Key fields | Relations |
|---|---|---|
| `Department` | `name`, `normalized_key` | hasMany DutyAssignment. Static `normalize()` dedupes venue names on import |
| `Khidmatguzar` | `its_id`, `full_name`, `gender`, `idara`, `jamaat`, `jamiaat` | hasMany DutyAssignment, ExtraPresent |
| `DutySession` | `name`, `date`, `h_year`, `miqaat`, `remarks`, `status`, `closed_at`, `closed_by` | hasMany ImportBatch/DutyAssignment/ExtraPresent, belongsTo User(closed_by). Helpers `isActive()`, `isClosed()`, `statusTone()` |
| `DutyAssignment` | fks (session/batch/khidmatguzar/department), `source_row_number`, `assignment_fingerprint`, `block_name`, `day`, `day_alias`, `seat`, `category`, `venue_name_raw`, `current_status`, `attendance_marked_at/by`, person-snapshot fields, raw-only unused source fields | belongsTo all fks + User(attendanceMarkedBy), hasMany AttendanceEvent |
| `AttendanceEvent` | `duty_assignment_id`, `duty_session_id`, `khidmatguzar_id`, `action`(present/absent), `context`(individual/bulk), `performed_by`, `performed_at`, `remark` | audit log |
| `ExtraPresent` | `duty_session_id`, `khidmatguzar_id`, `its_id_snapshot`, `full_name_snapshot`, `department_id`, `department_name_snapshot`, `marked_by`, `marked_at`, `remark` | unscheduled attendee |
| `ImportBatch` | `duty_session_id`, `uploaded_by`, `original_filename`, `file_type`, `status`, row counters (`total_rows`, `valid_rows`, `invalid_rows`, `exact_duplicate_rows`, `cross_batch_duplicate_rows`, `new/existing_khidmatguzars`, `new/existing_departments`), `error_summary`(array cast) | belongsTo DutySession/User, hasMany DutyAssignment |
| `User` | `name`, `its_number`, `email`, `password`, `role` | password hashed cast, hidden password/remember_token. Helpers `isAdmin()`, `isOperator()`, `isViewer()`, `canManageSessions()` |

## 4. Database Schema (migrations, chronological)

1. `create_users_table` — users(id, name, email unique, password, `role` enum[admin,operator,viewer], remember_token) + sessions table
2. `create_cache_table`
3. `create_jobs_table` — jobs, job_batches, failed_jobs
4. `create_departments_table` — id, name, `normalized_key` unique
5. `create_khidmatguzars_table` — id, `its_id` unique, full_name, gender, idara, jamaat, jamiaat
6. `create_duty_sessions_table` — id, name, date, h_year, miqaat, remarks, `status` enum[draft,active,closing,closed] default draft
7. `create_import_batches_table` — fk duty_session cascade, fk uploaded_by→users, filename/type, status enum[completed,failed], row counters, `error_summary` json
8. `create_duty_assignments_table` — fks (session cascade, batch cascade, khidmatguzar, department), fingerprint, source fields, `current_status` enum[pending,present,absent] default pending, person snapshots, h_year/miqaat, raw-only fields. Indexes: `(duty_session_id, assignment_fingerprint)`, `(duty_session_id, khidmatguzar_id)`
9. `add_cross_batch_duplicate_rows_to_import_batches_table`
10. `add_attendance_fields_to_duty_assignments_table` — `attendance_marked_at`, `attendance_marked_by`(fk users)
11. `create_attendance_events_table`
12. `create_extra_presents_table` — unique `(duty_session_id, khidmatguzar_id)`
13. `add_close_fields_to_duty_sessions_table` — `closed_at`, `closed_by`
14. `add_context_to_attendance_events_table` — `context` enum[individual,bulk] default individual
15. `add_analytics_indexes` — `(department_id, current_status)`, `(khidmatguzar_id, current_status)` on duty_assignments; index on duty_sessions.date
16. `add_its_number_to_users_table` — `users.its_number` string(8) nullable unique, backfill loop for existing rows; login moved to ITS-based auth, email kept NOT NULL via synthetic placeholder to avoid doctrine/dbal dependency

## 5. Routes

**`routes/web.php`** — `/` → redirect `dashboard`. All under `auth` middleware:
- `GET /dashboard` → `DashboardController` (invokable)
- `GET /sessions` → `DutySessionController@index`
- Nested `role:admin,operator` group: session create/store/activate, import create/store/confirm, attendance live-redirect/live/present/absent/extra-present/pending/absent-all, close-summary/close
- Auth-only (all roles incl. viewer): attendance list, session show, analytics overview/departments/insights/profile-search/profile (`/khidmatguzars`, `/khidmatguzars/{khidmatguzar}`), reports index + session/department/khidmatguzar preview+pdf+excel

**`routes/auth.php`** — `guest`: GET/POST `login`. `auth`: POST `logout`. No registration/password-reset/email-verification routes — self-registration intentionally absent.

## 6. Controllers (`app/Http/Controllers/`)

- `Controller.php` — empty base
- `Auth/AuthenticatedSessionController.php` — login/logout via `LoginRequest::authenticate()`, session regenerate
- `DashboardController` (invokable) — draft/active session counts, latest session counts, `ReportService::sessionGenderSummary`, 5 recent sessions
- `DutySessionController` — `index`, `create`, `store`, `show` (eager-loads importBatches.uploadedBy), `activate` (draft→active only), `closeSummary`, `close` (delegates `AttendanceService::closeSession`)
- `DutyListImportController` — Excel/CSV wizard: `create`, `store` (validates xlsx/xls/csv max 10MB, parses+builds preview, caches preview 30min by UUID token), `confirm` (commits from cache). Private `isLockedForImport()` blocks when session closing/closed
- `AttendanceController` — `liveRedirect`, `live` (search ITS or name), `present` (single/bulk `assignment_ids[]`), `absent`, `extraPresent` (known vs brand-new ITS), `pending`, `absentAll`, `list` (tabs all/present/pending/extra + search/department filter)
- `AnalyticsController` — `overview` (date range/dept/session filters, gender breakdown, trend, top-5 depts), `departments`, `insights` (best/worst dept rate, most active session, multi-assignment count, pending-in-active count), `directory` (paginated, correlated-subquery stats), `profile` (single person full stats + history, paginated)
- `ReportController` — GET-only, all roles read-only: `index`, session/department/khidmatguzar × preview/pdf/excel. Department branches to detail-report if `department_id` given

## 7. Services / Support / Exports / Imports / Rules

- [AttendanceService.php](app/Services/AttendanceService.php) — core state machine, `DB::transaction` + `lockForUpdate()`. `markPresent`, `markPresentMany`, `markAbsent`, `markAllRemainingAbsent` (idempotent), `closeSession` (active→closing→closed, rolls back if pending remain via `SessionHasPendingAssignmentsException`), `markExtraPresentKnown`, `markExtraPresentNew`. Business rules: Present→Absent blocked (correction only Absent→Present); Extra Present never creates DutyAssignment; source Status/Scanned columns never drive `current_status`
- [ReportService.php](app/Services/ReportService.php) — single source of truth for report data (preview/PDF/Excel share same methods): `sessionReport`, `sessionGenderSummary`, `departmentReport`, `khidmatguzarReport`, `departmentDetailReport`. `safeFilenamePart()` slug-based, prevents path traversal
- [DutyListImportService.php](app/Services/DutyListImportService.php) — hardcoded `COLUMN_MAP` (22 headers), `REQUIRED_COLUMNS` (its_id, full_name, venue_name). `parse()` (BOM-stripping header match), `buildPreview()` (classifies invalid/exact-dup/cross-batch-dup rows, counts new/existing dept+person), `commit()` (one transaction: `Department::firstOrCreate`, `Khidmatguzar::updateOrCreate`, creates DutyAssignment pending). `fingerprint()` = sha256(its_id + normalized venue + block/day/day_alias/seat)
- [app/Support/Gender.php](app/Support/Gender.php) — single source of truth for gender bucketing (raw data mixes M/Male/F/Female/null): `caseSql()` for SQL, `shortLabel()` mirrors in PHP
- [app/Rules/ValidItsNumber.php](app/Rules/ValidItsNumber.php) — exactly 8 digits regex
- [app/Imports/RawSheetImport.php](app/Imports/RawSheetImport.php) — `ToArray` concern, raw 2D array, no heading-row auto-format
- [app/Exports/ArraySheet.php](app/Exports/ArraySheet.php) — reusable styled worksheet (`FromArray`/`ShouldAutoSize`/`WithEvents`/`WithTitle`). Navy header, zebra stripes, borders, autofilter, frozen pane, print setup, status-column color coding (green/red/orange). Has documented workaround for maatwebsite/excel bug silently blanking literal `0` (loose-null-comparison quirk) via `setCellValueExplicit`
- `SessionAttendanceExport` — 4-sheet: Summary, Departments, Attendance, Extra Present
- `DepartmentReportExport` — 5-sheet all-departments: Executive Summary, Department Summary, Gender Breakdown, Detailed Attendance (with Session/Session Date columns to disambiguate repeated ITS), Extra Present
- `DepartmentDetailReportExport` — 3-sheet: Department Summary (real gender matrix), Detailed Attendance, Extra Present
- `KhidmatguzarReportExport` — 4-sheet: Profile Summary, Department Breakdown, Duty History, Extra Present

## 8. Views (`resources/views/`)

- `layouts/app.blade.php` — PWA meta, Vite directive, mobile-first max-width container, bottom-nav, sw.js registration
- `layouts/guest.blade.php`
- `components/shell/` — badge, card, info-card, button, user-menu, page-header, stat-card, gender-breakdown, bottom-nav
- `components/` — application-logo, input-error, text-input, input-label, auth-session-status (Breeze-derived)
- `sessions/` — create, show, index, close
- `imports/` — create, preview
- `attendance/` — list, live, pending
- `analytics/` — _tabs, overview, departments, insights, profile-search, profile
- `reports/` — index, session, khidmatguzar, department, department-detail + `reports/pdf/` subfolder for dompdf
- `auth/login.blade.php`, `dashboard.blade.php`

Tailwind utilities throughout, custom `text-navy-900` color. Alpine only for bottom-nav "More" popover.

## 9. Android App (`android/`)

Native Android WebView wrapper — NOT Capacitor/Cordova (no bridge infra, no config.xml).

- **AndroidManifest.xml**: package `com.binatechnologies.kgattendance`, single `MainActivity` (portrait-locked launcher), `INTERNET` permission only, `usesCleartextTraffic="false"`
- **MainActivity.java**: loads hardcoded `START_URL = "https://kg.bina-technologies.com"` into full-screen WebView
  - Edge-to-edge inset handling: applies system-bar insets as WebView margins (not padding) so `position: fixed` bottom nav clears system bars
  - `DownloadManager`-based download interception (stock WebView drops `Content-Disposition: attachment` responses)
  - File-chooser support for Excel/CSV import picker
  - Cookie manager accepts cookies (Laravel session auth + download auth)
  - External-host links open in external browser via `Intent.ACTION_VIEW`
- **build.gradle (app)**: compileSdk 35, minSdk 23, targetSdk 35, single dep `androidx.appcompat:appcompat:1.7.0`
- **root build.gradle**: forces single Kotlin stdlib version 1.8.22 (fixes duplicate-class conflict)
- CI: `.github/workflows/android-apk.yml` builds APK

Thin native shell over the deployed PWA — no offline logic, no native UI beyond chrome handling.

## 10. Auth & Permissions

- Standard Laravel session guard, Eloquent User provider. No Sanctum/Passport/Fortify, no Spatie/permission
- **Login is ITS-number-based**: `LoginRequest` validates `its_number` (8 digits) + password, `Auth::attempt()` matches directly on `its_number` column. RateLimiter lockout (5 attempts, keyed its_number+ip)
- **No self-registration**: accounts only via `php artisan app:create-admin` (interactive prompts, no password CLI arg to avoid shell-history leakage), always creates role=admin, synthetic placeholder email `its{itsNumber}@no-email.local`
- **Roles**: plain string column `users.role` enum(admin/operator/viewer). No roles table, no policies
- **Enforcement**: custom `EnsureUserHasRole` middleware (aliased `role`), `abort(403)` unless role in list. Applied `role:admin,operator` around mutation routes. No per-method policy classes — enforcement is purely route-grouping

## 11. Testing (`tests/`)

PHPUnit 12, Feature-heavy (~2026 lines), `tests/Unit/ExampleTest.php` is untouched default.

| File | Lines | Covers |
|---|---|---|
| `BusinessInvariantsTest.php` | 374 | dup rejection, same-ITS-diff-dept, pending↔present/absent, present→absent blocked, extra-present uniqueness, unknown-ITS creates person, viewer blocked, closed-session blocks mutation, bulk-absent idempotency, close-rejected-with-pending |
| `AttendanceCorrectionTest.php` | 335 | Absent→Present correction flow, no dup rows, event preservation, closed-session block, counter reconciliation |
| `DepartmentDetailReportTest.php` | 351 | summary totals, gender matrix, row counts, Excel sheet/row assertions, PDF content, empty scope, unknown gender |
| `DirectoryProfileTest.php` | 402 | search by ITS/name, dedup, multi-assignment history, present/absent counts, extra-present excluded from rate denominator, filters, pagination, N+1 prevention |
| `GenderReportingTest.php` | 174 | gender-bucket reconciliation, null→Unknown, historical uses `gender_snapshot` not live `gender` |
| `ReportExportTest.php` | 75 | PDF+Excel smoke tests incl. empty scope |
| `BottomNavTest.php` | 43 | Search FAB redirect, More-menu routes |
| `Auth/AuthenticationTest.php` | 54 | Breeze-derived baseline login/logout |
| `Auth/ItsAuthenticationTest.php` | 157 | ITS format edge cases, leading-zero preserved, unknown ITS rejected, session persists |
| `Console/CreateAdminCommandTest.php` | 61 | valid creation, invalid format, dup ITS, mismatched password confirmation |

## 12. Config Notes

- **`config/excel.php`**: `imports.read_only = true` (consistent with RawSheetImport reading plain arrays), `exports.strict_null_comparison = false` (root cause of the zero-blanking bug `ArraySheet` works around), `chunk_size = 1000`
- **`config/dompdf.php`**: standard defaults, `chroot` set to app root (security: dompdf can't read outside app), `allowed_protocols` incl. data/file/http/https

## 13. PWA

Real, intentionally minimal PWA:
- **manifest.json**: name "Khidmatguzar", `start_url: /dashboard`, `display: standalone`, theme `#0F1E3D`, portrait-primary, icons 192/512/maskable-512
- **sw.js**: never caches navigations or non-GET (attendance data must never be stale) — only cache-first for `/build/` and `/icons/`. Explicitly for installability only, not offline attendance. Versioned `kg-static-v1`, purges stale on activate
- Apple PWA meta tags present alongside standard ones

## 14. Architectural Themes

1. **Snapshot-on-write pattern** — person/department/session fields (`*_snapshot`, `h_year`, `miqaat`) copied onto `DutyAssignment`/`ExtraPresent` at creation time so historical reports never drift when master data (e.g. `Khidmatguzar.gender`) later changes. Enforced by `GenderReportingTest`.
2. **Fingerprint-based dedup** — sha256 of (its_id + normalized venue + block/day/day_alias/seat) is the identity of "same assignment," used both within-file and cross-batch.
3. **Strict state machine** — `pending → present/absent`, correction only `absent → present`, enforced in `AttendanceService` with row locks + transactions, never trusted to raw source status columns.
4. **Shared read path for reports** — `ReportService` guarantees preview screen, PDF, and Excel export always agree, since all three call the same aggregation methods.
5. **Route-group-based authorization** — no policy classes; viewer vs operator/admin boundary is entirely which route group a URL falls in.
6. **Thin native shell** — Android app is not a separate codebase; it is a WebView pointed at the production URL, so all logic/UI lives in the one Laravel app.

## 15. Notable Risks / Watch Items

- Gender/role/status are plain string/enum columns, not extracted into lookup tables — fine at current scale, would need migration if roles multiply.
- No policy classes — adding a 4th role or finer-grained permissions means touching route groups by hand across `web.php`, easy to miss one.
- `maatwebsite/excel` zero-blanking bug worked around manually in `ArraySheet` — if package internals change, recheck this workaround.
- Hardcoded Android `START_URL` — changing the deployed domain requires a new APK build/release, not just a server-side change.
- `config/excel.php` `read_only=true` for imports means any cell styling in uploaded files is ignored by design — acceptable since `RawSheetImport` only needs raw values.
