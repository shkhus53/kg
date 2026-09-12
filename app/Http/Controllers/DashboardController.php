<?php

namespace App\Http\Controllers;

use App\Models\DutySession;
use App\Services\MumineenCalendarService;
use App\Services\ReportService;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(ReportService $reports, MumineenCalendarService $calendar): View
    {
        $latestSession = DutySession::withCount([
            'dutyAssignments',
            'dutyAssignments as present_count' => fn ($q) => $q->where('current_status', 'present'),
            'dutyAssignments as pending_count' => fn ($q) => $q->where('current_status', 'pending'),
            'extraPresents as extra_count',
        ])->latest('date')->latest('id')->first();

        $hijriToday = $calendar->today();

        return view('dashboard', [
            'draftCount' => DutySession::where('status', 'draft')->count(),
            'activeCount' => DutySession::where('status', 'active')->count(),
            'latestSession' => $latestSession,
            'latestSessionGender' => $latestSession ? $reports->sessionGenderSummary($latestSession) : null,
            'recentSessions' => DutySession::latest('date')->latest('id')->take(5)->get(),
            // Mumineen calendar — entirely separate from KG Attendance's
            // own Event/DutySession/EventPlan data (see
            // MumineenCalendarService's class docblock).
            'hijriToday' => $hijriToday,
            'todaysMiqaats' => $calendar->todaysMiqaats(),
            'calendarWindow' => $calendar->calendarWindow(),
            'calendarAnchorIndex' => 6, // calendarWindow() is built ±6 months around today, so today's month is always at this fixed index
        ]);
    }
}
