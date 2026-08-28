<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
@include('reports.pdf._styles')
</head>
<body>

<div class="doc-header">
    <h1>Department Attendance Report</h1>
    <div class="meta">
        @if ($session)
            {{ $session->name }} <span class="sep">&middot;</span> {{ $session->date->format('d M Y') }} <span class="sep">&middot;</span> {{ strtoupper($session->status) }}
        @else
            {{ \Carbon\Carbon::parse($from)->format('d M Y') }} &ndash; {{ \Carbon\Carbon::parse($to)->format('d M Y') }}
        @endif
        <br>Generated {{ now()->format('d M Y H:i') }} {{ config('app.timezone') }}
    </div>
</div>

@foreach ($sections as $section)
    @if (!$loop->first)
        <div style="page-break-before: always;"></div>
    @endif

    <h2 class="section" style="font-size: 15px; border-bottom-width: 3px;">{{ $section['department']->name }}</h2>

    <table class="kpi-grid">
        <tr>
            <td class="kpi-blue"><span class="kpi-value">{{ $section['scheduled'] }}</span><span class="kpi-label">Scheduled</span></td>
            <td class="kpi-green"><span class="kpi-value">{{ $section['present'] }}</span><span class="kpi-label">Present</span></td>
            <td class="kpi-red"><span class="kpi-value">{{ $section['absent'] }}</span><span class="kpi-label">Absent</span></td>
            <td class="kpi-orange"><span class="kpi-value">{{ $section['pending'] }}</span><span class="kpi-label">Pending</span></td>
            <td class="kpi-violet"><span class="kpi-value">{{ $section['extraCount'] }}</span><span class="kpi-label">Extra Present</span></td>
        </tr>
    </table>
    <p style="text-align: center; margin: 10px 0 4px 0;">
        <span class="muted">Attendance Rate</span><br>
        <span class="rate">{{ $section['rate'] !== null ? $section['rate'].'%' : 'N/A' }}</span>
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
                <td class="num g-male">{{ $section['genderBreakdown'][$key]['male'] }}</td>
                <td class="num g-female">{{ $section['genderBreakdown'][$key]['female'] }}</td>
                <td class="num g-unknown">{{ $section['genderBreakdown'][$key]['unknown'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <h2 class="section">Detailed Attendance ({{ $section['assignments']->count() }})</h2>
    <table class="data">
        <thead>
            <tr><th>Sr.</th><th>Name</th><th>ITS</th><th>Gender</th><th>Block</th><th>Seat</th><th>Day</th><th>Status</th><th>Marked At</th></tr>
        </thead>
        <tbody>
            @foreach ($section['assignments'] as $a)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $a->full_name_snapshot }}</td>
                <td>{{ $a->khidmatguzar->its_id }}</td>
                <td>{{ \App\Support\Gender::shortLabel($a->gender_snapshot) }}</td>
                <td>{{ $a->block_name }}</td>
                <td>{{ $a->seat }}</td>
                <td>{{ $a->day_alias ?: $a->day }}</td>
                <td><span class="badge badge-{{ $a->current_status }}">{{ $a->current_status }}</span></td>
                <td>{{ $a->attendance_marked_at?->format('d M H:i') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <table class="data" style="margin-top: 4px;">
        <thead><tr><th colspan="4">{{ $section['department']->name }} — Totals</th></tr></thead>
        <tbody>
            <tr>
                <td>Scheduled: <strong>{{ $section['scheduled'] }}</strong></td>
                <td>Present: <strong>{{ $section['present'] }}</strong></td>
                <td>Absent: <strong>{{ $section['absent'] }}</strong></td>
                <td>Pending: <strong>{{ $section['pending'] }}</strong></td>
            </tr>
            <tr>
                <td class="g-male">Male: <strong>{{ $section['genderBreakdown']['scheduled']['male'] }}</strong></td>
                <td class="g-female">Female: <strong>{{ $section['genderBreakdown']['scheduled']['female'] }}</strong></td>
                <td class="g-unknown">Unknown: <strong>{{ $section['genderBreakdown']['scheduled']['unknown'] }}</strong></td>
                <td>Rate: <strong>{{ $section['rate'] !== null ? $section['rate'].'%' : 'N/A' }}</strong></td>
            </tr>
        </tbody>
    </table>

    @if ($section['extraPresents']->isNotEmpty())
    <h2 class="section">Extra Present ({{ $section['extraPresents']->count() }})</h2>
    <table class="data">
        <thead>
            <tr><th>Sr.</th><th>Name</th><th>ITS</th><th>Marked At</th><th>Marked By</th><th>Remark</th></tr>
        </thead>
        <tbody>
            @foreach ($section['extraPresents'] as $e)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $e->full_name_snapshot }}</td>
                <td>{{ $e->its_id_snapshot }}</td>
                <td>{{ $e->marked_at->format('d M Y H:i') }}</td>
                <td>{{ $e->markedBy?->name }}</td>
                <td>{{ $e->remark }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif
@endforeach

</body>
</html>
