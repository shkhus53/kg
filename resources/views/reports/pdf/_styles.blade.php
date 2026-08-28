<style>
    @page {
        margin: 100px 30px 60px 30px;
        @bottom-left {
            content: "KG Attendance";
            font-size: 8px;
            color: #94a3b8;
        }
        @bottom-center {
            content: "Page " counter(page) " of " counter(pages);
            font-size: 9px;
            color: #94a3b8;
        }
    }

    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1e293b; }

    /* ---- Header banner (fixed, repeats on every page) ---- */
    .doc-header {
        position: fixed; top: -90px; left: 0px; right: 0px; height: 78px;
        background: #0F1E3D; color: #ffffff;
        padding: 14px 18px;
    }
    .doc-header h1 { font-size: 17px; font-weight: bold; color: #ffffff; margin: 0 0 4px 0; }
    .doc-header .meta { font-size: 9.5px; color: rgba(255,255,255,0.72); }
    .doc-header .meta .sep { color: rgba(255,255,255,0.35); padding: 0 4px; }

    /* ---- Section headings ---- */
    h2.section {
        font-size: 12.5px; font-weight: bold; color: #0F1E3D;
        margin: 20px 0 8px 0; padding: 0 0 5px 0;
        border-bottom: 2px solid #0F1E3D;
    }

    /* ---- KPI stat grid (table-based — dompdf has no flexbox) ---- */
    table.kpi-grid { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 4px 0 6px 0; }
    table.kpi-grid td {
        border: 1px solid #e2e8f0; border-radius: 6px;
        padding: 10px 8px; text-align: center; width: 20%;
    }
    table.kpi-grid .kpi-value { display: block; font-size: 19px; font-weight: bold; line-height: 1.1; }
    table.kpi-grid .kpi-label { display: block; margin-top: 3px; font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.4px; color: #64748b; }
    .kpi-blue .kpi-value { color: #0F1E3D; }
    .kpi-green .kpi-value { color: #059669; }
    .kpi-red .kpi-value { color: #dc2626; }
    .kpi-orange .kpi-value { color: #ea580c; }
    .kpi-violet .kpi-value { color: #7c3aed; }

    /* ---- Data tables ---- */
    table.data { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    table.data thead { display: table-header-group; }
    table.data tr { page-break-inside: avoid; }
    table.data th {
        background: #0F1E3D; color: #ffffff; text-align: left;
        padding: 6px 7px; font-size: 9px; text-transform: uppercase; letter-spacing: 0.3px;
    }
    table.data td { padding: 6px 7px; font-size: 9.5px; border-bottom: 1px solid #f1f5f9; }
    table.data tbody tr:nth-child(even) { background: #f8fafc; }
    table.data .num { text-align: right; }

    /* ---- Two-column field/value summary (used sparingly) ---- */
    .summary-table td:first-child { color: #64748b; width: 40%; }
    .summary-table td:last-child { font-weight: bold; }

    /* ---- Gender mini-table ---- */
    table.gender-table th { background: #eef2f7; color: #0F1E3D; }
    table.gender-table .g-male { color: #2563eb; font-weight: bold; }
    table.gender-table .g-female { color: #7c3aed; font-weight: bold; }
    table.gender-table .g-unknown { color: #64748b; }

    /* ---- Badges ---- */
    .badge { display: inline-block; padding: 2px 8px; border-radius: 9px; font-size: 8px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3px; }
    .badge-present { background: #d1fae5; color: #059669; }
    .badge-absent { background: #fee2e2; color: #dc2626; }
    .badge-pending { background: #ffedd5; color: #ea580c; }
    .badge-extra { background: #ede9fe; color: #7c3aed; }

    .rate { font-size: 20px; font-weight: bold; color: #059669; }
    .muted { color: #94a3b8; font-size: 9px; }
    .card { border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 14px; margin-bottom: 10px; }
</style>
