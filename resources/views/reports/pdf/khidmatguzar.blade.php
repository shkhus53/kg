<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
@include('reports.pdf._styles')
</head>
<body>

<div class="doc-header">
    <h1>Khidmatguzar Attendance Report</h1>
    <div class="meta">
        {{ $khidmatguzar->full_name }} <span class="sep">&middot;</span> ITS: {{ $khidmatguzar->its_id }}
        @if ($khidmatguzar->jamaat) <span class="sep">&middot;</span> {{ $khidmatguzar->jamaat }} @endif
        <br>Generated {{ now()->format('d M Y H:i') }} {{ config('app.timezone') }}
    </div>
</div>

<h2 class="section">Summary</h2>
<table class="kpi-grid">
    <tr>
        <td class="kpi-blue"><span class="kpi-value">{{ $total }}</span><span class="kpi-label">Total Duties</span></td>
        <td class="kpi-green"><span class="kpi-value">{{ $present }}</span><span class="kpi-label">Present</span></td>
        <td class="kpi-red"><span class="kpi-value">{{ $absent }}</span><span class="kpi-label">Absent</span></td>
        <td class="kpi-orange"><span class="kpi-value">{{ $pending }}</span><span class="kpi-label">Pending</span></td>
        <td class="kpi-violet"><span class="kpi-value">{{ $extraCount }}</span><span class="kpi-label">Extra Present</span></td>
    </tr>
</table>
<p style="text-align: center; margin: 10px 0 4px 0;">
    <span class="muted">Attendance Rate</span><br>
    <span class="rate">{{ $rate !== null ? $rate.'%' : 'N/A' }}</span>
</p>

@if ($departmentBreakdown->isNotEmpty())
<h2 class="section">Department Breakdown</h2>
<table class="data">
    <thead><tr><th>Department</th><th class="num">Duties</th><th class="num">Present</th><th class="num">Absent</th><th class="num">Rate</th></tr></thead>
    <tbody>
        @foreach ($departmentBreakdown as $d)
        <tr>
            <td>{{ $d->department_name }}</td>
            <td class="num">{{ $d->duties }}</td>
            <td class="num">{{ $d->present }}</td>
            <td class="num">{{ $d->absent }}</td>
            <td class="num">{{ $d->rate }}%</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

<h2 class="section">Duty History ({{ $history->count() }})</h2>
<table class="data">
    <thead><tr><th>Date</th><th>Session</th><th>Department</th><th>Block</th><th>Seat</th><th>Status</th></tr></thead>
    <tbody>
        @foreach ($history as $a)
        <tr>
            <td>{{ $a->dutySession->date->format('d M Y') }}</td>
            <td>{{ $a->dutySession->name }}</td>
            <td>{{ $a->department->name }}</td>
            <td>{{ $a->block_name }}</td>
            <td>{{ $a->seat }}</td>
            <td><span class="badge badge-{{ $a->current_status }}">{{ $a->current_status }}</span></td>
        </tr>
        @endforeach
    </tbody>
</table>

@if ($extraHistory->isNotEmpty())
<h2 class="section">Extra Present History ({{ $extraHistory->count() }})</h2>
<table class="data">
    <thead><tr><th>Date</th><th>Session</th><th>Department</th><th>Marked At</th><th>Remark</th></tr></thead>
    <tbody>
        @foreach ($extraHistory as $e)
        <tr>
            <td>{{ $e->marked_at->format('d M Y') }}</td>
            <td>{{ $e->dutySession->name }}</td>
            <td>{{ $e->department_name_snapshot }}</td>
            <td>{{ $e->marked_at->format('H:i') }}</td>
            <td>{{ $e->remark }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

</body>
</html>
