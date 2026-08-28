<?php

namespace App\Http\Controllers;

use App\Models\DutySession;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $latestSession = DutySession::withCount([
            'dutyAssignments',
            'dutyAssignments as present_count' => fn ($q) => $q->where('current_status', 'present'),
            'dutyAssignments as pending_count' => fn ($q) => $q->where('current_status', 'pending'),
            'extraPresents as extra_count',
        ])->latest('date')->latest('id')->first();

        return view('dashboard', [
            'draftCount' => DutySession::where('status', 'draft')->count(),
            'activeCount' => DutySession::where('status', 'active')->count(),
            'latestSession' => $latestSession,
            'recentSessions' => DutySession::latest('date')->latest('id')->take(5)->get(),
        ]);
    }
}
