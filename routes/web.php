<?php

use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\EventController;
use App\Http\Controllers\Admin\MasterDataController;
use App\Http\Controllers\Admin\MiqaatController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VenueController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\CommandCenterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DutyListImportController;
use App\Http\Controllers\DutySessionController;
use App\Http\Controllers\EventPlanController;
use App\Http\Controllers\OfflineSyncController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::middleware('auth')->group(function () {
    Route::middleware('can:view_dashboard')->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
    });

    // Static /sessions/* segments (create, imports) MUST be registered
    // before the {dutySession} wildcard route below — otherwise Laravel's
    // first-match routing treats "create" as a dutySession route
    // parameter and 404s (no session with that key) instead of ever
    // reaching the intended static route.
    Route::middleware('can:create_sessions')->group(function () {
        Route::get('/sessions/create', [DutySessionController::class, 'create'])->name('sessions.create');
        Route::post('/sessions', [DutySessionController::class, 'store'])->name('sessions.store');
    });

    Route::middleware('can:import_duty_list')->group(function () {
        Route::get('/imports', [DutyListImportController::class, 'index'])->name('imports.index');
        Route::get('/sessions/{dutySession}/imports/{importBatch}/diff', [DutyListImportController::class, 'diff'])->name('sessions.imports.diff');
    });

    Route::middleware('can:view_sessions')->group(function () {
        Route::get('/sessions', [DutySessionController::class, 'index'])->name('sessions.index');
        Route::get('/sessions/{dutySession}', [DutySessionController::class, 'show'])->name('sessions.show');
        Route::get('/sessions/{dutySession}/command-center', [CommandCenterController::class, 'show'])->name('sessions.command-center');
        Route::get('/sessions/{dutySession}/command-center/data', [CommandCenterController::class, 'data'])->name('sessions.command-center.data');
    });

    Route::middleware('can:activate_sessions')->group(function () {
        Route::post('/sessions/{dutySession}/activate', [DutySessionController::class, 'activate'])->name('sessions.activate');
    });

    Route::middleware('can:preview_import')->group(function () {
        Route::get('/sessions/{dutySession}/import', [DutyListImportController::class, 'create'])->name('sessions.imports.create');
        Route::post('/sessions/{dutySession}/import', [DutyListImportController::class, 'store'])->name('sessions.imports.store');
    });

    Route::middleware('can:commit_import')->group(function () {
        Route::post('/sessions/{dutySession}/import/{token}/confirm', [DutyListImportController::class, 'confirm'])->name('sessions.imports.confirm');
    });

    Route::middleware('can:close_sessions')->group(function () {
        Route::get('/sessions/{dutySession}/close', [DutySessionController::class, 'closeSummary'])->name('sessions.close-summary');
        Route::post('/sessions/{dutySession}/close', [DutySessionController::class, 'close'])->name('sessions.close');
    });

    Route::middleware('can:reopen_sessions')->group(function () {
        Route::post('/sessions/{dutySession}/reopen', [DutySessionController::class, 'reopen'])->name('sessions.reopen');
    });

    // Same static-before-wildcard ordering requirement as /sessions above.
    Route::middleware('can:manage_planning')->group(function () {
        Route::get('/planning/create', [EventPlanController::class, 'create'])->name('planning.create');
        Route::post('/planning', [EventPlanController::class, 'store'])->name('planning.store');
        Route::put('/planning/{eventPlan}', [EventPlanController::class, 'update'])->name('planning.update');
    });

    Route::middleware('can:view_planning')->group(function () {
        Route::get('/planning', [EventPlanController::class, 'index'])->name('planning.index');
        Route::get('/planning/{eventPlan}', [EventPlanController::class, 'show'])->name('planning.show');
    });

    // Viewing a live session and its pending queue is separate from
    // actually mutating attendance — a Viewer with only view_live_attendance
    // sees the same screens read-only (mutation controls check their own
    // gate at submit time; the shared Blade partial disables them client-
    // side too, but that is convenience, not the security boundary).
    Route::middleware('can:view_live_attendance')->group(function () {
        Route::get('/attendance', [AttendanceController::class, 'liveRedirect'])->name('attendance.shell.live-redirect');
        Route::get('/sessions/{dutySession}/attendance', [AttendanceController::class, 'live'])->name('attendance.shell.live');
        Route::get('/sessions/{dutySession}/attendance/pending', [AttendanceController::class, 'pending'])->name('attendance.shell.pending');
    });

    // Offline provisioning/sync stay under mark_attendance (not the
    // broader view_live_attendance) — provisioning caches the ability to
    // MARK attendance offline, so a view-only account should not be able
    // to set up or push offline mutations any more than it could online.
    Route::middleware('can:mark_attendance')->group(function () {
        Route::get('/sessions/{dutySession}/offline/provision', [OfflineSyncController::class, 'provision'])->name('offline.provision');
        Route::post('/sync/attendance-events', [OfflineSyncController::class, 'sync'])->name('offline.sync');

        Route::post('/sessions/{dutySession}/attendance/present', [AttendanceController::class, 'present'])->name('attendance.present');
        Route::post('/sessions/{dutySession}/attendance/absent', [AttendanceController::class, 'absent'])->name('attendance.absent');
        Route::post('/sessions/{dutySession}/attendance/absent-all', [AttendanceController::class, 'absentAll'])->name('attendance.absent-all');
    });

    Route::middleware('can:mark_extra_present')->group(function () {
        Route::post('/sessions/{dutySession}/attendance/extra-present', [AttendanceController::class, 'extraPresent'])->name('attendance.extra-present');
    });

    Route::middleware('can:view_attendance_history')->group(function () {
        Route::get('/sessions/{dutySession}/attendance/list', [AttendanceController::class, 'list'])->name('attendance.shell.list');
        Route::get('/assignments/{dutyAssignment}', [AnalyticsController::class, 'assignmentDetail'])->name('analytics.assignment');
    });

    Route::middleware('can:manage_masters')->prefix('masters')->name('masters.')->group(function () {
        Route::get('/', [MasterDataController::class, 'index'])->name('index');

        Route::get('/departments', [DepartmentController::class, 'index'])->name('departments.index');
        Route::post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
        Route::get('/departments/{department}/edit', [DepartmentController::class, 'edit'])->name('departments.edit');
        Route::put('/departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
        Route::post('/departments/{department}/toggle', [DepartmentController::class, 'toggleActive'])->name('departments.toggle');

        Route::get('/miqaats', [MiqaatController::class, 'index'])->name('miqaats.index');
        Route::post('/miqaats', [MiqaatController::class, 'store'])->name('miqaats.store');
        Route::get('/miqaats/{miqaat}/edit', [MiqaatController::class, 'edit'])->name('miqaats.edit');
        Route::put('/miqaats/{miqaat}', [MiqaatController::class, 'update'])->name('miqaats.update');
        Route::post('/miqaats/{miqaat}/toggle', [MiqaatController::class, 'toggleActive'])->name('miqaats.toggle');

        Route::get('/events', [EventController::class, 'index'])->name('events.index');
        Route::post('/events', [EventController::class, 'store'])->name('events.store');
        Route::get('/events/{event}/edit', [EventController::class, 'edit'])->name('events.edit');
        Route::put('/events/{event}', [EventController::class, 'update'])->name('events.update');
        Route::post('/events/{event}/toggle', [EventController::class, 'toggleActive'])->name('events.toggle');

        Route::get('/venues', [VenueController::class, 'index'])->name('venues.index');
        Route::post('/venues', [VenueController::class, 'store'])->name('venues.store');
        Route::get('/venues/{venue}/edit', [VenueController::class, 'edit'])->name('venues.edit');
        Route::put('/venues/{venue}', [VenueController::class, 'update'])->name('venues.update');
        Route::post('/venues/{venue}/toggle', [VenueController::class, 'toggleActive'])->name('venues.toggle');
    });

    Route::middleware('can:manage_users')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::put('/users/{user}/password', [UserController::class, 'updatePassword'])->name('users.password.update');
        Route::put('/users/{user}/permissions', [UserController::class, 'updatePermissions'])->name('users.permissions.update');
    });

    Route::middleware('can:view_audit_log')->group(function () {
        Route::get('/audit', [AuditLogController::class, 'index'])->name('audit.index');
        Route::get('/analytics/operators', [AnalyticsController::class, 'operators'])->name('analytics.operators');
        Route::get('/reports/operators/pdf', [ReportController::class, 'operatorActivityPdf'])->name('reports.operators.pdf');
        Route::get('/reports/operators/excel', [ReportController::class, 'operatorActivityExcel'])->name('reports.operators.excel');
    });

    Route::middleware('can:view_analytics')->group(function () {
        Route::get('/analytics', [AnalyticsController::class, 'overview'])->name('analytics.overview');
        Route::get('/analytics/departments', [AnalyticsController::class, 'departments'])->name('analytics.departments');
        Route::get('/analytics/insights', [AnalyticsController::class, 'insights'])->name('analytics.insights');
        Route::get('/analytics/trends', [AnalyticsController::class, 'trends'])->name('analytics.trends');
    });

    Route::middleware('can:view_planning')->group(function () {
        Route::get('/analytics/planning', [AnalyticsController::class, 'planning'])->name('analytics.planning');
    });

    Route::middleware('can:view_alerts')->group(function () {
        Route::get('/analytics/exceptions', [AnalyticsController::class, 'exceptions'])->name('analytics.exceptions');
        Route::get('/analytics/alerts', [AnalyticsController::class, 'alerts'])->name('analytics.alerts');
    });

    Route::middleware('can:view_management_summary')->group(function () {
        Route::get('/analytics/summary', [AnalyticsController::class, 'summary'])->name('analytics.summary');
    });

    Route::middleware('can:view_directory')->group(function () {
        Route::get('/khidmatguzars', [AnalyticsController::class, 'directory'])->name('analytics.profile-search');
    });

    Route::middleware('can:view_member_profile')->group(function () {
        Route::get('/khidmatguzars/{khidmatguzar}', [AnalyticsController::class, 'profile'])->name('analytics.profile');
    });

    Route::middleware('can:view_reports')->group(function () {
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');

        Route::get('/reports/sessions/{dutySession}', [ReportController::class, 'sessionPreview'])->name('reports.session');
        Route::get('/reports/sessions/{dutySession}/pdf', [ReportController::class, 'sessionPdf'])->name('reports.session.pdf');
        Route::get('/reports/sessions/{dutySession}/excel', [ReportController::class, 'sessionExcel'])->name('reports.session.excel');

        Route::get('/reports/departments', [ReportController::class, 'departmentPreview'])->name('reports.department');
        Route::get('/reports/departments/pdf', [ReportController::class, 'departmentPdf'])->name('reports.department.pdf');
        Route::get('/reports/departments/excel', [ReportController::class, 'departmentExcel'])->name('reports.department.excel');

        Route::get('/reports/management-summary/pdf', [ReportController::class, 'managementSummaryPdf'])->name('reports.management-summary.pdf');
        Route::get('/reports/management-summary/excel', [ReportController::class, 'managementSummaryExcel'])->name('reports.management-summary.excel');

        Route::get('/reports/khidmatguzars/{khidmatguzar}', [ReportController::class, 'khidmatguzarPreview'])->name('reports.khidmatguzar');
        Route::get('/reports/khidmatguzars/{khidmatguzar}/pdf', [ReportController::class, 'khidmatguzarPdf'])->name('reports.khidmatguzar.pdf');
        Route::get('/reports/khidmatguzars/{khidmatguzar}/excel', [ReportController::class, 'khidmatguzarExcel'])->name('reports.khidmatguzar.excel');
    });

    Route::middleware('can:build_reports')->group(function () {
        Route::get('/reports/builder', [ReportController::class, 'builder'])->name('reports.builder');
        Route::get('/reports/builder/pdf', [ReportController::class, 'builderPdf'])->name('reports.builder.pdf');
        Route::get('/reports/builder/excel', [ReportController::class, 'builderExcel'])->name('reports.builder.excel');

        Route::middleware('can:mark_attendance')->group(function () {
            Route::post('/reports/builder/mark-present', [ReportController::class, 'builderMarkPresent'])->name('reports.builder.mark-present');
            Route::post('/reports/builder/mark-absent', [ReportController::class, 'builderMarkAbsent'])->name('reports.builder.mark-absent');
        });
    });
});

require __DIR__.'/auth.php';
