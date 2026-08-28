<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DutyListImportController;
use App\Http\Controllers\DutySessionController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/sessions', [DutySessionController::class, 'index'])->name('sessions.index');

    Route::middleware('role:admin,operator')->group(function () {
        Route::get('/sessions/create', [DutySessionController::class, 'create'])->name('sessions.create');
        Route::post('/sessions', [DutySessionController::class, 'store'])->name('sessions.store');
        Route::post('/sessions/{dutySession}/activate', [DutySessionController::class, 'activate'])->name('sessions.activate');

        Route::get('/sessions/{dutySession}/import', [DutyListImportController::class, 'create'])->name('sessions.imports.create');
        Route::post('/sessions/{dutySession}/import', [DutyListImportController::class, 'store'])->name('sessions.imports.store');
        Route::post('/sessions/{dutySession}/import/{token}/confirm', [DutyListImportController::class, 'confirm'])->name('sessions.imports.confirm');

        Route::get('/sessions/{dutySession}/attendance', [AttendanceController::class, 'live'])->name('attendance.shell.live');
        Route::post('/sessions/{dutySession}/attendance/present', [AttendanceController::class, 'present'])->name('attendance.present');
        Route::post('/sessions/{dutySession}/attendance/absent', [AttendanceController::class, 'absent'])->name('attendance.absent');
        Route::post('/sessions/{dutySession}/attendance/extra-present', [AttendanceController::class, 'extraPresent'])->name('attendance.extra-present');

        Route::get('/sessions/{dutySession}/attendance/pending', [AttendanceController::class, 'pending'])->name('attendance.shell.pending');
        Route::post('/sessions/{dutySession}/attendance/absent-all', [AttendanceController::class, 'absentAll'])->name('attendance.absent-all');

        Route::get('/sessions/{dutySession}/close', [DutySessionController::class, 'closeSummary'])->name('sessions.close-summary');
        Route::post('/sessions/{dutySession}/close', [DutySessionController::class, 'close'])->name('sessions.close');
    });

    Route::get('/sessions/{dutySession}/attendance/list', [AttendanceController::class, 'list'])->name('attendance.shell.list');

    Route::get('/sessions/{dutySession}', [DutySessionController::class, 'show'])->name('sessions.show');

    Route::get('/analytics', [AnalyticsController::class, 'overview'])->name('analytics.overview');
    Route::get('/analytics/departments', [AnalyticsController::class, 'departments'])->name('analytics.departments');
    Route::get('/analytics/insights', [AnalyticsController::class, 'insights'])->name('analytics.insights');

    Route::get('/khidmatguzars', [AnalyticsController::class, 'directory'])->name('analytics.profile-search');
    Route::get('/khidmatguzars/{khidmatguzar}', [AnalyticsController::class, 'profile'])->name('analytics.profile');

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
