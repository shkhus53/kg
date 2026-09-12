<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\CommandCenterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DutyListImportController;
use App\Http\Controllers\DutySessionController;
use App\Http\Controllers\OfflineSyncController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/sessions', [DutySessionController::class, 'index'])->name('sessions.index');

    Route::middleware('can:manage_sessions')->group(function () {
        Route::get('/sessions/create', [DutySessionController::class, 'create'])->name('sessions.create');
        Route::post('/sessions', [DutySessionController::class, 'store'])->name('sessions.store');
        Route::post('/sessions/{dutySession}/activate', [DutySessionController::class, 'activate'])->name('sessions.activate');

        Route::get('/sessions/{dutySession}/import', [DutyListImportController::class, 'create'])->name('sessions.imports.create');
        Route::post('/sessions/{dutySession}/import', [DutyListImportController::class, 'store'])->name('sessions.imports.store');
        Route::post('/sessions/{dutySession}/import/{token}/confirm', [DutyListImportController::class, 'confirm'])->name('sessions.imports.confirm');
        Route::get('/sessions/{dutySession}/imports/{importBatch}/diff', [DutyListImportController::class, 'diff'])->name('sessions.imports.diff');

        Route::get('/sessions/{dutySession}/close', [DutySessionController::class, 'closeSummary'])->name('sessions.close-summary');
        Route::post('/sessions/{dutySession}/close', [DutySessionController::class, 'close'])->name('sessions.close');
    });

    // Attendance marking (online and offline) is its own permission — not
    // reused from manage_sessions — so it can later diverge (e.g. an
    // operator authorized to mark attendance without full session
    // management) without touching every route again.
    Route::middleware('can:mark_attendance')->group(function () {
        Route::get('/attendance', [AttendanceController::class, 'liveRedirect'])->name('attendance.shell.live-redirect');
        Route::get('/sessions/{dutySession}/attendance', [AttendanceController::class, 'live'])->name('attendance.shell.live');
        Route::post('/sessions/{dutySession}/attendance/present', [AttendanceController::class, 'present'])->name('attendance.present');
        Route::post('/sessions/{dutySession}/attendance/absent', [AttendanceController::class, 'absent'])->name('attendance.absent');
        Route::post('/sessions/{dutySession}/attendance/extra-present', [AttendanceController::class, 'extraPresent'])->name('attendance.extra-present');

        Route::get('/sessions/{dutySession}/attendance/pending', [AttendanceController::class, 'pending'])->name('attendance.shell.pending');
        Route::post('/sessions/{dutySession}/attendance/absent-all', [AttendanceController::class, 'absentAll'])->name('attendance.absent-all');

        Route::get('/sessions/{dutySession}/offline/provision', [OfflineSyncController::class, 'provision'])->name('offline.provision');
        Route::post('/sync/attendance-events', [OfflineSyncController::class, 'sync'])->name('offline.sync');
    });

    Route::middleware('can:reopen_sessions')->group(function () {
        Route::post('/sessions/{dutySession}/reopen', [DutySessionController::class, 'reopen'])->name('sessions.reopen');
    });

    Route::middleware('can:view_audit_log')->group(function () {
        Route::get('/audit', [AuditLogController::class, 'index'])->name('audit.index');
        Route::get('/analytics/operators', [AnalyticsController::class, 'operators'])->name('analytics.operators');
        Route::get('/reports/operators/pdf', [ReportController::class, 'operatorActivityPdf'])->name('reports.operators.pdf');
        Route::get('/reports/operators/excel', [ReportController::class, 'operatorActivityExcel'])->name('reports.operators.excel');
    });

    Route::get('/sessions/{dutySession}/attendance/list', [AttendanceController::class, 'list'])->name('attendance.shell.list');

    Route::get('/sessions/{dutySession}', [DutySessionController::class, 'show'])->name('sessions.show');
    Route::get('/sessions/{dutySession}/command-center', [CommandCenterController::class, 'show'])->name('sessions.command-center');
    Route::get('/sessions/{dutySession}/command-center/data', [CommandCenterController::class, 'data'])->name('sessions.command-center.data');

    Route::get('/analytics', [AnalyticsController::class, 'overview'])->name('analytics.overview');
    Route::get('/analytics/departments', [AnalyticsController::class, 'departments'])->name('analytics.departments');
    Route::get('/analytics/insights', [AnalyticsController::class, 'insights'])->name('analytics.insights');

    Route::get('/khidmatguzars', [AnalyticsController::class, 'directory'])->name('analytics.profile-search');
    Route::get('/khidmatguzars/{khidmatguzar}', [AnalyticsController::class, 'profile'])->name('analytics.profile');
    Route::get('/assignments/{dutyAssignment}', [AnalyticsController::class, 'assignmentDetail'])->name('analytics.assignment');

    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');

    Route::get('/reports/sessions/{dutySession}', [ReportController::class, 'sessionPreview'])->name('reports.session');
    Route::get('/reports/sessions/{dutySession}/pdf', [ReportController::class, 'sessionPdf'])->name('reports.session.pdf');
    Route::get('/reports/sessions/{dutySession}/excel', [ReportController::class, 'sessionExcel'])->name('reports.session.excel');

    Route::get('/reports/departments', [ReportController::class, 'departmentPreview'])->name('reports.department');
    Route::get('/reports/departments/pdf', [ReportController::class, 'departmentPdf'])->name('reports.department.pdf');
    Route::get('/reports/departments/excel', [ReportController::class, 'departmentExcel'])->name('reports.department.excel');

    Route::get('/reports/khidmatguzars/{khidmatguzar}', [ReportController::class, 'khidmatguzarPreview'])->name('reports.khidmatguzar');
    Route::get('/reports/khidmatguzars/{khidmatguzar}/pdf', [ReportController::class, 'khidmatguzarPdf'])->name('reports.khidmatguzar.pdf');
    Route::get('/reports/khidmatguzars/{khidmatguzar}/excel', [ReportController::class, 'khidmatguzarExcel'])->name('reports.khidmatguzar.excel');
});

require __DIR__.'/auth.php';
