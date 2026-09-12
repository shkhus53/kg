<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
@include('reports.pdf._styles')
</head>
<body>

<div class="doc-header">
    <h1>Management Summary</h1>
    <div class="meta">
        {{ \Carbon\Carbon::parse($from)->format('d M Y') }} &ndash; {{ \Carbon\Carbon::parse($to)->format('d M Y') }}
        <br>Generated {{ $generated_at->format('d M Y H:i') }} IST
    </div>
</div>

<h2 class="section">Operations</h2>
<table class="data">
    <thead><tr><th>Sessions</th><th>Scheduled</th><th>Present</th><th>Absent</th><th>Pending</th><th>Attendance Rate</th><th>Extra Present</th></tr></thead>
    <tbody>
        <tr>
            <td class="num">{{ $sessions }}</td>
            <td class="num">{{ $scheduled }}</td>
            <td class="num">{{ $present }}</td>
            <td class="num">{{ $absent }}</td>
            <td class="num">{{ $pending }}</td>
            <td class="num">{{ $rate !== null ? $rate.'%' : '—' }}</td>
            <td class="num">{{ $extra }}</td>
        </tr>
    </tbody>
</table>
<p class="muted">Attendance Rate = Present ÷ Scheduled × 100. Extra Present is never part of this denominator.</p>

<h2 class="section">Planning</h2>
<table class="data">
    <thead><tr><th>Planned</th><th>Actual Assigned</th><th>Planning Gap</th><th>Underplanned Departments</th></tr></thead>
    <tbody>
        <tr>
            <td class="num">{{ $planned }}</td>
            <td class="num">{{ $actual }}</td>
            <td class="num">{{ $planningGap >= 0 ? '+' : '' }}{{ $planningGap }}</td>
            <td class="num">{{ $underplannedDepartments }}</td>
        </tr>
    </tbody>
</table>
<p class="muted">Actual Assigned counts DutyAssignment rows (never unique ITS) — the same ITS in two departments counts as two.</p>

<h2 class="section">Quality</h2>
<table class="data">
    <thead><tr><th>Imports</th><th>Invalid Rows</th><th>Corrections</th><th>Reopened Sessions</th></tr></thead>
    <tbody>
        <tr>
            <td class="num">{{ $imports }}</td>
            <td class="num">{{ $invalidRows }}</td>
            <td class="num">{{ $corrections }}</td>
            <td class="num">{{ $reopenedSessions }}</td>
        </tr>
    </tbody>
</table>

<h2 class="section">Attention ({{ $highAlerts }} High, {{ $mediumAlerts }} Medium)</h2>
<table class="data">
    <thead><tr><th>Severity</th><th>Title</th><th>Reason</th></tr></thead>
    <tbody>
        @forelse ($topAlerts as $alert)
        <tr>
            <td>{{ strtoupper($alert['severity']) }}</td>
            <td>{{ $alert['title'] }}</td>
            <td>{{ $alert['reason'] }}</td>
        </tr>
        @empty
        <tr><td colspan="3">No alerts in this period.</td></tr>
        @endforelse
    </tbody>
</table>
@if ($highAlerts + $mediumAlerts > $topAlerts->count())
<p class="muted">Showing the top {{ $topAlerts->count() }} of {{ $highAlerts + $mediumAlerts }} alerts — see the Alert Center for the full list.</p>
@endif

</body>
</html>
