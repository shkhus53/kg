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
        {{ $khidmatguzar->full_name }} &middot; ITS: {{ $khidmatguzar->its_id }}
        @if ($khidmatguzar->jamaat) &middot; {{ $khidmatguzar->jamaat }} @endif
        <br>Generated {{ now()->format('d M Y H:i') }} {{ config('app.timezone') }}
    </div>
</div>

<h2 class="section">Summary</h2>
<table class="summary-table">
    <tr><td>Full Name</td><td>{{ $khidmatguzar->full_name }}</td></tr>
    <tr><td>ITS Number</td><td>{{ $khidmatguzar->its_id }}</td></tr>
    <tr><td>Jamaat</td><td>{{ $khidmatguzar->jamaat ?: 'N/A' }}</td></tr>
    <tr><td>Total Scheduled Duties</td><td>{{ $total }}</td></tr>
    <tr><td>Present</td><td>{{ $present }}</td></tr>
    <tr><td>Absent</td><td>{{ $absent }}</td></tr>
    <tr><td>Pending</td><td>{{ $pending }}</td></tr>
    <tr><td>Extra Present</td><td>{{ $extraCount }}</td></tr>
    <tr><td>Attendance Rate</td><td class="rate">{{ $rate !== null ? $rate.'%' : 'N/A' }}</td></tr>
</table>

@if ($departmentBreakdown->isNotEmpty())
<h2 class="section">Department Breakdown</h2>
<table>
    <thead><tr><th>Department</th><th>Duties</th><th>Present</th><th>Absent</th><th>Rate</th></tr></thead>
    <tbody>
        @foreach ($departmentBreakdown as $d)
        <tr>
            <td>{{ $d->department_name }}</td>
            <td>{{ $d->duties }}</td>
            <td>{{ $d->present }}</td>
            <td>{{ $d->absent }}</td>
            <td>{{ $d->rate }}%</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

<h2 class="section">Duty History ({{ $history->count() }})</h2>
<table>
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
<table>
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
