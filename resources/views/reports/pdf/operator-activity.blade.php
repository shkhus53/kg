<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
@include('reports.pdf._styles')
</head>
<body>

<div class="doc-header">
    <h1>Operator Activity Report</h1>
    <div class="meta">
        {{ \Carbon\Carbon::parse($from)->format('d M Y') }} &ndash; {{ \Carbon\Carbon::parse($to)->format('d M Y') }}
        <br>Generated {{ now()->toIst()->format('d M Y H:i') }} IST
    </div>
</div>

<p class="muted">Operational workload only — not a ranking.</p>

<h2 class="section">Operators ({{ $operators->count() }})</h2>
<table class="data">
    <thead>
        <tr>
            <th>Operator</th><th>Role</th>
            <th class="num">Total</th><th class="num">Present</th><th class="num">Absent</th>
            <th class="num">Corrections</th><th class="num">Extra</th><th>Last Activity</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($operators as $row)
        <tr>
            <td>{{ $row['user']->name }}</td>
            <td>{{ ucfirst($row['user']->role) }}</td>
            <td class="num">{{ $row['total_actions'] }}</td>
            <td class="num">{{ $row['present_count'] }}</td>
            <td class="num">{{ $row['absent_count'] }}</td>
            <td class="num">{{ $row['corrections_count'] }}</td>
            <td class="num">{{ $row['extra_count'] }}</td>
            <td>{{ $row['last_activity'] ? \Carbon\Carbon::parse($row['last_activity'])->toIst()->format('d M Y H:i') : '—' }}</td>
        </tr>
        @empty
        <tr><td colspan="8">No attendance activity in this period.</td></tr>
        @endforelse
    </tbody>
</table>

</body>
</html>
