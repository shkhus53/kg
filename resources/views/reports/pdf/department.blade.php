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
            {{ $session->name }} <span class="sep">&middot;</span> {{ $session->date->format('d M Y') }}
        @else
            {{ \Carbon\Carbon::parse($from)->format('d M Y') }} &ndash; {{ \Carbon\Carbon::parse($to)->format('d M Y') }}
        @endif
        <br>Generated {{ now()->format('d M Y H:i') }} {{ config('app.timezone') }}
    </div>
</div>

<h2 class="section">Departments ({{ $departments->count() }})</h2>
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

</body>
</html>
