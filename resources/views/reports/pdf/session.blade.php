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
        {{ $dutySession->name }} <span class="sep">&middot;</span> {{ $dutySession->date->format('d M Y') }} <span class="sep">&middot;</span> {{ strtoupper($dutySession->status) }}
        @unless($dutySession->isClosed()) (not final) @endunless
        <br>Generated {{ now()->format('d M Y H:i') }} {{ config('app.timezone') }}
    </div>
</div>

<h2 class="section">Summary</h2>
<table class="kpi-grid">
    <tr>
        <td class="kpi-blue"><span class="kpi-value">{{ $scheduled }}</span><span class="kpi-label">Scheduled</span></td>
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

<h2 class="section">Gender Breakdown</h2>
<table class="data gender-table">
    <thead>
        <tr><th></th><th class="num">Male</th><th class="num">Female</th><th class="num">Unknown</th></tr>
    </thead>
    <tbody>
        @foreach (['scheduled' => 'Scheduled', 'present' => 'Present', 'absent' => 'Absent', 'pending' => 'Pending'] as $key => $label)
        <tr>
            <td>{{ $label }}</td>
            <td class="num g-male">{{ $genderBreakdown[$key]['male'] }}</td>
            <td class="num g-female">{{ $genderBreakdown[$key]['female'] }}</td>
            <td class="num g-unknown">{{ $genderBreakdown[$key]['unknown'] }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

@if ($departments->isNotEmpty())
<h2 class="section">Department Summary</h2>
<table class="data">
    <thead>
        <tr><th>Department</th><th class="num">Scheduled</th><th class="num">Present</th><th class="num">Absent</th><th class="num">Pending</th><th class="num">Rate</th><th class="num">Male</th><th class="num">Female</th><th class="num">Unknown</th></tr>
    </thead>
    <tbody>
        @foreach ($departments as $d)
        <tr>
            <td>{{ $d->department_name }}</td>
            <td class="num">{{ $d->scheduled }}</td>
            <td class="num">{{ $d->present }}</td>
            <td class="num">{{ $d->absent }}</td>
            <td class="num">{{ $d->pending }}</td>
            <td class="num">{{ $d->rate }}%</td>
            <td class="num g-male">{{ $d->genderBreakdown['scheduled']['male'] }}</td>
            <td class="num g-female">{{ $d->genderBreakdown['scheduled']['female'] }}</td>
            <td class="num g-unknown">{{ $d->genderBreakdown['scheduled']['unknown'] }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

<h2 class="section">Attendance Detail ({{ $assignments->count() }})</h2>
<table class="data">
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
<table class="data">
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
