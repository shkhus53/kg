<style>
    @page {
        margin: 90px 30px 60px 30px;
        @bottom-center {
            content: "Page " counter(page) " of " counter(pages);
            font-size: 9px;
            color: #94a3b8;
        }
    }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1e293b; }
    .doc-header {
        position: fixed; top: -70px; left: 0px; right: 0px; height: 60px;
        border-bottom: 2px solid #0F1E3D; padding-bottom: 8px;
    }
    .doc-header h1 { font-size: 16px; color: #0F1E3D; margin: 0 0 2px 0; }
    .doc-header .meta { font-size: 9px; color: #64748b; }
    h2.section { font-size: 13px; color: #0F1E3D; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; margin: 18px 0 8px 0; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    th { background: #f1f5f9; text-align: left; padding: 5px 6px; font-size: 9.5px; border-bottom: 1px solid #cbd5e1; }
    td { padding: 5px 6px; font-size: 9.5px; border-bottom: 1px solid #f1f5f9; }
    .summary-table td:first-child { color: #64748b; width: 40%; }
    .summary-table td:last-child { font-weight: bold; }
    .badge { padding: 1px 6px; border-radius: 8px; font-size: 8.5px; font-weight: bold; text-transform: uppercase; }
    .badge-present { background: #d1fae5; color: #059669; }
    .badge-absent { background: #fee2e2; color: #dc2626; }
    .badge-pending { background: #ffedd5; color: #ea580c; }
    .badge-extra { background: #ede9fe; color: #7c3aed; }
    .rate { font-size: 20px; font-weight: bold; color: #059669; }
    .muted { color: #94a3b8; font-size: 9px; }
</style>
