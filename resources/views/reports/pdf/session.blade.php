<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
@include('reports.pdf._styles')
</head>
<body>

<div class="doc-header">
    <h1>Session Attendance Report</h1>
    <div class="meta">
        {{ $dutySession->name }} &middot; {{ $dutySession->date->format('d M Y') }} &middot; Status: {{ strtoupper($dutySession->status) }}
        @unless($dutySession->isClosed()) (not final) @endunless
        <br>Generated {{ now()->format('d M Y H:i') }} {{ config('app.timezone') }}
    </div>
</div>

<h2 class="section">Summary</h2>
<table class="summary-table">
    <tr><td>Total Scheduled</td><td>{{ $scheduled }}</td></tr>
    <tr><td>Present</td><td>{{ $present }}</td></tr>
    <tr><td>Absent</td><td>{{ $absent }}</td></tr>
    <tr><td>Pending</td><td>{{ $pending }}</td></tr>
    <tr><td>Extra Present</td><td>{{ $extraCount }}</td></tr>
    <tr><td>Attendance Rate</td><td class="rate">{{ $rate !== null ? $rate.'%' : 'N/A' }}</td></tr>
</table>

<h2 class="section">Gender Breakdown</h2>
<table>
    <thead>
        <tr><th></th><th>Male</th><th>Female</th><th>Unknown</th></tr>
    </thead>
    <tbody>
        @foreach (['scheduled' => 'Scheduled', 'present' => 'Present', 'absent' => 'Absent', 'pending' => 'Pending'] as $key => $label)
        <tr>
            <td>{{ $label }}</td>
            <td>{{ $genderBreakdown[$key]['male'] }}</td>
            <td>{{ $genderBreakdown[$key]['female'] }}</td>
            <td>{{ $genderBreakdown[$key]['unknown'] }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

@if ($departments->isNotEmpty())
<h2 class="section">Department Summary</h2>
<table>
    <thead>
        <tr><th>Department</th><th>Scheduled</th><th>Present</th><th>Absent</th><th>Pending</th><th>Rate</th><th>Male</th><th>Female</th><th>Unknown</th></tr>
    </thead>
    <tbody>
        @foreach ($departments as $d)
        <tr>
            <td>{{ $d->department_name }}</td>
            <td>{{ $d->scheduled }}</td>
            <td>{{ $d->present }}</td>
            <td>{{ $d->absent }}</td>
            <td>{{ $d->pending }}</td>
            <td>{{ $d->rate }}%</td>
            <td>{{ $d->genderBreakdown['scheduled']['male'] }}</td>
            <td>{{ $d->genderBreakdown['scheduled']['female'] }}</td>
            <td>{{ $d->genderBreakdown['scheduled']['unknown'] }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

<h2 class="section">Attendance Detail ({{ $assignments->count() }})</h2>
<table>
    <thead>
        <tr><th>Name</th><th>ITS</th><th>Gender</th><th>Department</th><th>Block</th><th>Seat</th><th>Day</th><th>Status</th><th>Marked At</th></tr>
    </thead>
    <tbody>
        @foreach ($assignments as $a)
        <tr>
            <td>{{ $a->full_name_snapshot }}</td>
            <td>{{ $a->khidmatguzar->its_id }}</td>
            <td>{{ \App\Support\Gender::shortLabel($a->gender_snapshot) }}</td>
            <td>{{ $a->department->name }}</td>
            <td>{{ $a->block_name }}</td>
            <td>{{ $a->seat }}</td>
            <td>{{ $a->day_alias ?: $a->day }}</td>
            <td><span class="badge badge-{{ $a->current_status }}">{{ $a->current_status }}</span></td>
            <td>{{ $a->attendance_marked_at?->format('d M H:i') }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

@if ($extraPresents->isNotEmpty())
<h2 class="section">Extra Present ({{ $extraPresents->count() }})</h2>
<table>
    <thead>
        <tr><th>Name</th><th>ITS</th><th>Department</th><th>Marked At</th><th>Marked By</th><th>Remark</th></tr>
    </thead>
    <tbody>
        @foreach ($extraPresents as $e)
        <tr>
            <td>{{ $e->full_name_snapshot }}</td>
            <td>{{ $e->its_id_snapshot }}</td>
            <td>{{ $e->department_name_snapshot }}</td>
            <td>{{ $e->marked_at->format('d M Y H:i') }}</td>
            <td>{{ $e->markedBy?->name }}</td>
            <td>{{ $e->remark }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

</body>
</html>
