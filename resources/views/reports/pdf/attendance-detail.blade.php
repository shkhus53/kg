<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
@include('reports.pdf._styles')
<style>
    /*
     * This report can span many pages (one row per DutyAssignment, easily
     * 5+ pages). The shared .doc-header above is `position: fixed`, which
     * dompdf repeats in the top page-margin band on EVERY page — that
     * margin band was sized (@page margin-top: 100px) to fit the banner
     * once on page 1, but a table's repeating <thead> (display:
     * table-header-group) starts immediately at the top of the CONTENT
     * box on every page, i.e. right at the edge of that same margin band.
     * On a single-page report the two never meet; here, with pages of
     * pure table rows, the repeated fixed banner collides with/clips the
     * repeating column header. Fix: keep the banner in normal document
     * flow (so it renders exactly once, on page 1, pushing content down
     * naturally) and shrink the page margin to an ordinary size — the
     * <thead> repeat is then the ONLY thing that repeats per page, with
     * no fixed element sharing its space. Scoped to this one template via
     * a later <style> block, so no other (single-page) report's layout
     * changes.
     */
    @page {
        margin: 30px 30px 60px 30px;
        @bottom-left { content: "KG Attendance"; font-size: 8px; color: #94a3b8; }
        @bottom-center { content: "Page " counter(page) " of " counter(pages); font-size: 9px; color: #94a3b8; }
    }
    .doc-header { position: static; top: auto; left: auto; right: auto; height: auto; margin: 0 0 16px 0; }
</style>
</head>
<body>

<div class="doc-header">
    <h1>Attendance Detail Report</h1>
    <div class="meta">
        {{ $filters['from'] ?? 'All dates' }} &ndash; {{ $filters['to'] ?? 'All dates' }}
        <br>Generated {{ now()->toIst()->format('d M Y H:i') }} IST
    </div>
</div>

<h2 class="section">Summary</h2>
<table class="data">
    <thead><tr><th>Scheduled</th><th>Present</th><th>Absent</th><th>Pending</th><th>Attendance Rate</th></tr></thead>
    <tbody>
        <tr>
            <td class="num">{{ $totals['scheduled'] }}</td>
            <td class="num">{{ $totals['present'] }}</td>
            <td class="num">{{ $totals['absent'] }}</td>
            <td class="num">{{ $totals['pending'] }}</td>
            <td class="num">{{ $totals['rate'] !== null ? $totals['rate'].'%' : '—' }}</td>
        </tr>
    </tbody>
</table>

<h2 class="section">Detail ({{ $rows->count() }})</h2>
<table class="data">
    <thead><tr><th>ITS</th><th>Full Name</th><th>Department</th><th>Session</th><th>Date</th><th>Status</th></tr></thead>
    <tbody>
        @forelse ($rows as $row)
        <tr>
            <td>{{ $row->khidmatguzar?->its_id }}</td>
            <td>{{ $row->khidmatguzar?->full_name ?? $row->full_name_snapshot }}</td>
            <td>{{ $row->department?->name }}</td>
            <td>{{ $row->dutySession?->name }}</td>
            <td>{{ $row->dutySession?->date?->format('d M Y') }}</td>
            <td>{{ ucfirst($row->current_status) }}</td>
        </tr>
        @empty
        <tr><td colspan="6">No assignments match these filters.</td></tr>
        @endforelse
    </tbody>
</table>

</body>
</html>
