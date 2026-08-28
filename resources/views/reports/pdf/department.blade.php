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
            {{ $session->name }} &middot; {{ $session->date->format('d M Y') }}
        @else
            {{ \Carbon\Carbon::parse($from)->format('d M Y') }} &ndash; {{ \Carbon\Carbon::parse($to)->format('d M Y') }}
        @endif
        <br>Generated {{ now()->format('d M Y H:i') }} {{ config('app.timezone') }}
    </div>
</div>

<h2 class="section">Departments ({{ $departments->count() }})</h2>
<table>
    <thead>
        <tr><th>Department</th><th>Scheduled</th><th>Present</th><th>Absent</th><th>Pending</th><th>Rate</th></tr>
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
        </tr>
        @endforeach
    </tbody>
</table>

</body>
</html>
