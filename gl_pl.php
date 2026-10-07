<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

$fromDate = isset($_GET['from']) && strtotime($_GET['from']) ? $_GET['from'] : date('Y-m-01');
$toDate   = isset($_GET['to'])   && strtotime($_GET['to'])   ? $_GET['to']   : date('Y-m-d');

$stmt = $conn->prepare("
    SELECT
        a.id, a.code, a.name, a.account_type, a.is_contra,
        COALESCE(SUM(l.debit),  0) AS total_debit,
        COALESCE(SUM(l.credit), 0) AS total_credit
    FROM gl_accounts a
    JOIN gl_journal_lines l ON l.account_id = a.id
    JOIN gl_transactions t ON t.id = l.transaction_id
    WHERE a.is_postable = 1 AND a.status = 1
      AND a.account_type IN ('REVENUE', 'EXPENSE')
      AND t.entry_date BETWEEN ? AND ?
    GROUP BY a.id, a.code, a.name, a.account_type, a.is_contra
    ORDER BY a.code
");
$stmt->bind_param("ss", $fromDate, $toDate);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$revenue = []; $expenses = [];
$totalRevenue = 0; $totalExpenses = 0;

foreach ($rows as $r) {
    $net = (float)$r['total_debit'] - (float)$r['total_credit'];
    $isContra = (int)$r['is_contra'] === 1;
    if ($r['account_type'] === 'REVENUE') {
        $signed = $isContra ? $net : -$net;
        if (abs($signed) < 0.005) continue;
        $revenue[] = [
            'id' => (int)$r['id'],
            'code' => $r['code'],
            'name' => $r['name'],
            'amount' => $signed,
            'is_contra' => $isContra,
        ];
        $totalRevenue += $signed;
    } else {
        $signed = $net;
        if (abs($signed) < 0.005) continue;
        $expenses[] = [
            'id' => (int)$r['id'],
            'code' => $r['code'],
            'name' => $r['name'],
            'amount' => $signed,
        ];
        $totalExpenses += $signed;
    }
}

$netProfit = $totalRevenue - $totalExpenses;
$profitMargin = $totalRevenue > 0 ? ($netProfit / $totalRevenue) * 100 : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
   
    <title>Profit &amp; Loss — <?php echo htmlspecialchars($fromDate); ?> to <?php echo htmlspecialchars($toDate); ?></title>
    <style>
        * { box-sizing: border-box; }

        body {
            background: #eef1f5;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            margin: 0;
            padding: 0;
            color: #1e293b;
        }

        .report-wrap {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }

        /* ---------- Header ---------- */
        .report-head {
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
            color: #fff;
            padding: 34px 40px 30px 40px;
            border-radius: 12px 12px 0 0;
            box-shadow: 0 4px 16px rgba(30, 64, 175, 0.15);
        }
        .report-head .company {
            font-size: 12px;
            letter-spacing: 2.5px;
            text-transform: uppercase;
            opacity: 0.8;
            margin-bottom: 10px;
            font-weight: 600;
        }
        .report-head h1 {
            margin: 0;
            font-size: 34px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        .report-head .period {
            font-size: 15px;
            opacity: 0.9;
            margin-top: 8px;
        }

        /* ---------- Toolbar ---------- */
        .toolbar {
            background: #fff;
            border-radius: 0 0 12px 12px;
            padding: 20px 40px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            margin-bottom: 24px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .toolbar-row {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }
        .toolbar-row .spacer { flex: 1; }

        .date-group {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f8fafc;
            padding: 8px 14px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .date-group label {
            font-size: 12px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin: 0;
        }
        .date-group input[type=date] {
            height: 36px;
            padding: 6px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 14px;
            background: #fff;
            color: #0f172a;
        }
        .date-group input[type=date]:focus {
            outline: none;
            border-color: #1e40af;
            box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
        }

        .btn-tool {
            height: 40px;
            padding: 0 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            text-decoration: none;
            transition: all 0.15s;
            background: #fff;
            color: #475569;
            border-color: #cbd5e1;
            white-space: nowrap;
        }
        .btn-tool:hover {
            background: #f8fafc;
            border-color: #94a3b8;
            color: #1e293b;
            text-decoration: none;
        }
        .btn-tool.primary {
            background: #1e40af;
            color: #fff;
            border-color: #1e40af;
        }
        .btn-tool.primary:hover {
            background: #1e3a8a;
            border-color: #1e3a8a;
        }
        .btn-tool.export-copy  { color: #6366f1; border-color: #c7d2fe; background: #eef2ff; }
        .btn-tool.export-copy:hover  { background: #6366f1; color: #fff; border-color: #6366f1; }
        .btn-tool.export-csv   { color: #059669; border-color: #a7f3d0; background: #ecfdf5; }
        .btn-tool.export-csv:hover   { background: #059669; color: #fff; border-color: #059669; }
        .btn-tool.export-excel { color: #0369a1; border-color: #bae6fd; background: #f0f9ff; }
        .btn-tool.export-excel:hover { background: #0369a1; color: #fff; border-color: #0369a1; }
        .btn-tool.export-pdf   { color: #dc2626; border-color: #fecaca; background: #fef2f2; }
        .btn-tool.export-pdf:hover   { background: #dc2626; color: #fff; border-color: #dc2626; }

        /* Search */
        .search-box {
            position: relative;
            flex: 1;
            max-width: 320px;
            min-width: 180px;
        }
        .search-box input {
            width: 100%;
            height: 40px;
            padding: 0 36px 0 40px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            background: #fff;
            color: #0f172a;
        }
        .search-box input:focus {
            outline: none;
            border-color: #1e40af;
            box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
        }
        .search-box .glyphicon-search {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 14px;
        }
        .search-box .clear-search {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            cursor: pointer;
            font-size: 14px;
            display: none;
        }
        .search-box input:not(:placeholder-shown) + .clear-search { display: block; }

        /* Column dropdown */
        .dropdown-columns {
            position: relative;
            display: inline-block;
        }
        .dropdown-columns .columns-menu {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 6px;
            background: #fff;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
            padding: 8px;
            z-index: 100;
            min-width: 200px;
        }
        .dropdown-columns.open .columns-menu { display: block; }
        .columns-menu label {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            color: #1e293b;
            margin: 0;
        }
        .columns-menu label:hover { background: #f1f5f9; }
        .columns-menu input[type=checkbox] { cursor: pointer; }

        /* View toggle */
        .view-toggle {
            display: inline-flex;
            background: #f1f5f9;
            border-radius: 8px;
            padding: 3px;
            border: 1px solid #e2e8f0;
        }
        .view-toggle button {
            padding: 6px 14px;
            border: none;
            background: transparent;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
        }
        .view-toggle button.active {
            background: #fff;
            color: #1e40af;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }

        /* ---------- Summary strip ---------- */
        .summary-strip {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        @media (max-width: 800px) { .summary-strip { grid-template-columns: 1fr; } }

        .summary-tile {
            background: #fff;
            border-radius: 12px;
            padding: 20px 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border-left: 5px solid;
        }
        .summary-tile.revenue { border-color: #059669; }
        .summary-tile.expense { border-color: #dc2626; }
        .summary-tile.profit  { border-color: #1e40af; }
        .summary-tile.loss    { border-color: #dc2626; background: #fef2f2; }

        .summary-tile .label {
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            margin-bottom: 8px;
        }
        .summary-tile .value {
            font-size: 28px;
            font-weight: 700;
            font-family: 'SF Mono', 'Monaco', monospace;
            font-variant-numeric: tabular-nums;
            line-height: 1.1;
            color: #0f172a;
            letter-spacing: -0.5px;
        }
        .summary-tile .value.revenue-val { color: #047857; }
        .summary-tile .value.expense-val { color: #b91c1c; }
        .summary-tile .value.profit-val  { color: #1e40af; }
        .summary-tile .value.loss-val    { color: #b91c1c; }
        .summary-tile .sub {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 6px;
        }

        /* ---------- Sections ---------- */
        .section-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            overflow: hidden;
            margin-bottom: 24px;
        }
        .section-header {
            padding: 16px 26px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .section-header .title {
            font-size: 14px;
            font-weight: 700;
            color: #1e293b;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .section-header .badge-count {
            display: inline-block;
            padding: 2px 10px;
            background: #e2e8f0;
            color: #475569;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0;
            text-transform: none;
        }
        .section-header .subtotal-mini {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
        }

        /* ---------- Table view ---------- */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 15px;
        }
        .data-table thead th {
            padding: 14px 18px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            background: #fff;
            border-bottom: 2px solid #e2e8f0;
        }
        .data-table thead th.amount-col { text-align: right; }
        .data-table thead th.action-col { text-align: center; width: 100px; }

        .data-table tbody tr {
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.15s;
        }
        .data-table tbody tr:hover { background: #f8fafc; }
        .data-table tbody td {
            padding: 14px 18px;
            color: #1e293b;
            vertical-align: middle;
        }

        .data-table .acc-code {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 14px;
            font-weight: 600;
            color: #475569;
            width: 100px;
        }
        .data-table .acc-name {
            font-size: 15px;
            font-weight: 500;
            color: #0f172a;
        }
        .data-table .acc-name .contra-badge {
            display: inline-block;
            margin-left: 8px;
            padding: 2px 8px;
            background: #fef3c7;
            color: #92400e;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            vertical-align: middle;
        }
        .data-table .amount-col {
            text-align: right;
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 15px;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }
        .data-table .amount-col.revenue-amount { color: #047857; }
        .data-table .amount-col.expense-amount { color: #b91c1c; }

        .data-table .action-col {
            text-align: center;
        }
        .data-table .action-col .row-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            padding: 6px 12px;
            border-radius: 6px;
            background: #eff6ff;
            color: #1e40af;
            text-decoration: none;
            transition: all 0.15s;
            border: 1px solid #bfdbfe;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }
        .data-table .action-col .row-action:hover {
            background: #1e40af;
            color: #fff;
            border-color: #1e40af;
            text-decoration: none;
        }

        .data-table .subtotal-row td {
            padding: 16px 18px;
            background: #f0f5ff;
            font-weight: 700;
            font-size: 15px;
            color: #1e293b;
            border-top: 2px solid #c9d7ef;
            border-bottom: 2px solid #c9d7ef;
        }
        .data-table .subtotal-row .amount-col {
            font-size: 16px;
            font-weight: 800;
        }

        /* ---------- Card view ---------- */
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 14px;
            padding: 22px 26px;
        }
        .item-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 18px 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            transition: all 0.15s;
            position: relative;
        }
        .item-card:hover {
            border-color: #bfdbfe;
            box-shadow: 0 4px 14px rgba(30, 64, 175, 0.1);
            transform: translateY(-2px);
        }
        .item-card .card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
        }
        .item-card .card-code {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 12px;
            font-weight: 700;
            color: #64748b;
            background: #f1f5f9;
            padding: 3px 9px;
            border-radius: 5px;
        }
        .item-card .card-amount {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 20px;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            letter-spacing: -0.3px;
        }
        .item-card.revenue-card .card-amount { color: #047857; }
        .item-card.expense-card .card-amount { color: #b91c1c; }
        .item-card .card-name {
            font-size: 15px;
            font-weight: 600;
            color: #0f172a;
            line-height: 1.35;
        }
        .item-card .card-name .contra-badge {
            display: inline-block;
            margin-left: 8px;
            padding: 1px 7px;
            background: #fef3c7;
            color: #92400e;
            border-radius: 4px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            vertical-align: middle;
        }
        .item-card .card-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            color: #1e40af;
            text-decoration: none;
            padding-top: 10px;
            border-top: 1px solid #f1f5f9;
            margin-top: 4px;
        }
        .item-card .card-action:hover { color: #1e3a8a; text-decoration: none; }

        /* ---------- Net Profit Card ---------- */
        .net-card {
            background: linear-gradient(135deg, #064e3b 0%, #065f46 100%);
            color: #fff;
            border-radius: 14px;
            padding: 32px 36px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 24px;
            box-shadow: 0 8px 24px rgba(6, 78, 59, 0.25);
        }
        .net-card.loss {
            background: linear-gradient(135deg, #7f1d1d 0%, #991b1b 100%);
            box-shadow: 0 8px 24px rgba(127, 29, 29, 0.25);
        }
        .net-card .left .label {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: rgba(255, 255, 255, 0.75);
            margin-bottom: 10px;
        }
        .net-card .left .value {
            font-size: 44px;
            font-weight: 800;
            font-family: 'SF Mono', 'Monaco', monospace;
            font-variant-numeric: tabular-nums;
            letter-spacing: -1px;
            line-height: 1;
        }
        .net-card .right {
            text-align: right;
        }
        .net-card .right .sub-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: rgba(255, 255, 255, 0.7);
            font-weight: 700;
            margin-bottom: 4px;
        }
        .net-card .right .sub-val {
            font-size: 20px;
            font-weight: 700;
            font-family: 'SF Mono', 'Monaco', monospace;
            color: #fff;
        }
        .net-card .right .sub-val.muted { opacity: 0.75; font-size: 16px; }

        /* ---------- Empty ---------- */
        .empty-state {
            padding: 50px 30px;
            text-align: center;
            color: #94a3b8;
            font-size: 14px;
            font-style: italic;
        }

        /* ---------- Filter highlight ---------- */
        .hidden-column { display: none !important; }
        .filtered-out { display: none !important; }

        /* ============================================================
           PRINT — Excel-style, single page, no school name/logo
           ============================================================ */
        @media print {
            @page {
                size: A4 portrait;
                margin: 12mm 10mm;
            }

            body {
                background: #fff;
                color: #000;
                font-size: 10pt;
                font-family: Calibri, Arial, sans-serif;
            }

            .report-wrap { max-width: 100%; margin: 0; padding: 0; }

            /* Hide chrome */
            .toolbar,
            .view-toggle,
            .search-box,
            .dropdown-columns,
            .btn-tool,
            .report-head .company,
            .cards-grid,
            .col-action,
            .section-header .badge-count,
            .summary-strip, .navbar {
                display: none !important;
            }

            /* Compact centered header */
            .report-head {
                background: #fff !important;
                color: #000 !important;
                padding: 0 0 6pt 0;
                border-radius: 0;
                box-shadow: none;
                border-bottom: 2pt solid #000;
                margin-bottom: 8pt;
                text-align: center;
            }
            .report-head h1 {
                font-size: 14pt;
                font-weight: bold;
                margin: 0;
                letter-spacing: 0;
            }
            .report-head .period {
                font-size: 9pt;
                opacity: 1;
                color: #333 !important;
                margin-top: 2pt;
                font-style: italic;
            }

            /* Sections */
            .section-card {
                box-shadow: none;
                border-radius: 0;
                border: none;
                margin-bottom: 8pt;
                page-break-inside: avoid;
            }
            .section-header {
                background: #e8e8e8 !important;
                padding: 4pt 6pt !important;
                border: 0.5pt solid #808080;
                border-bottom: none;
            }
            .section-header .title {
                font-size: 10pt;
                font-weight: bold;
                letter-spacing: 0;
                text-transform: none;
                color: #000;
                gap: 0;
            }
            .section-header .title .glyphicon { display: none !important; }
            .section-header .subtotal-mini {
                font-size: 10pt;
                font-weight: bold;
                color: #000;
            }

            /* Grid table */
            .data-table {
                font-size: 9pt;
                border-collapse: collapse;
                border: 0.5pt solid #808080;
                width: 100%;
            }
            .data-table thead th {
                background: #e8e8e8 !important;
                color: #000 !important;
                font-size: 8.5pt;
                font-weight: bold;
                text-transform: none;
                letter-spacing: 0;
                padding: 3pt 6pt !important;
                border: 0.5pt solid #808080;
                text-align: left;
            }
            .data-table thead th.amount-col { text-align: right; }

            .data-table tbody td {
                padding: 2.5pt 6pt !important;
                border: 0.5pt solid #c0c0c0;
                font-size: 9pt;
                color: #000;
            }
            .data-table .acc-code,
            .data-table .acc-name {
                font-family: Calibri, Arial, sans-serif;
                font-size: 9pt;
                font-weight: normal;
                color: #000;
            }
            .data-table .acc-name .contra-badge {
                background: none;
                color: #000;
                border: none;
                padding: 0;
                font-size: 8pt;
                font-style: italic;
            }
            .data-table .amount-col {
                font-family: Calibri, Arial, sans-serif;
                font-size: 9pt;
                font-weight: normal;
                color: #000 !important;
                text-align: right;
            }

            .data-table .subtotal-row td {
                background: #f2f2f2 !important;
                font-size: 9pt;
                font-weight: bold;
                padding: 3.5pt 6pt !important;
                border: 0.5pt solid #808080;
                color: #000;
            }

            /* Net Profit — summary row style */
            .net-card {
                background: #fff !important;
                color: #000 !important;
                border-radius: 0;
                box-shadow: none;
                padding: 0 !important;
                margin-bottom: 0 !important;
                border: 0.5pt solid #808080;
                border-top: 2pt solid #000;
                display: block !important;
                margin-top: 12pt !important;
                page-break-inside: avoid;
            }
            .net-card .left {
                display: flex !important;
                justify-content: space-between;
                align-items: baseline;
                padding: 6pt 10pt !important;
                background: #e8e8e8 !important;
                border-bottom: 0.5pt solid #808080;
            }
            .net-card .left .label {
                font-size: 11pt;
                font-weight: bold;
                color: #000 !important;
                text-transform: none;
                letter-spacing: 0;
                margin: 0;
                opacity: 1;
            }
            .net-card .left .value {
                font-size: 12pt;
                font-weight: bold;
                font-family: Calibri, Arial, sans-serif;
                letter-spacing: 0;
                color: #000 !important;
            }
            .net-card .right {
                display: none !important;
            }

            /* Footer note */
            .report-wrap > div[style*="text-align: center"] {
                font-size: 8pt;
                color: #666 !important;
                padding: 8pt 0 0 0 !important;
                text-align: center;
            }

            .data-table tr { page-break-inside: avoid; }
            .data-table thead { display: table-header-group; }
            .data-table tfoot { display: table-footer-group; }

            html, body { height: auto !important; }
        }

        @media (max-width: 700px) {
            .report-head { padding: 24px 20px; }
            .report-head h1 { font-size: 24px; }
            .toolbar { padding: 16px 20px; }
            .toolbar-row { gap: 8px; }
            .btn-tool { font-size: 12px; padding: 0 10px; height: 36px; }
            .summary-strip { gap: 12px; }
            .summary-tile { padding: 16px 18px; }
            .summary-tile .value { font-size: 22px; }
            .data-table { font-size: 13px; }
            .data-table thead th { padding: 10px 10px; font-size: 10px; }
            .data-table tbody td { padding: 10px 10px; }
            .cards-grid { grid-template-columns: 1fr; padding: 16px; }
            .net-card { padding: 24px 20px; }
            .net-card .left .value { font-size: 32px; }
        }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="report-wrap">

    <!-- Header -->
    <div class="report-head">
        <div class="company">Dar-e-Arqm School</div>
        <h1>Profit &amp; Loss Statement</h1>
        <div class="period">
            For the period <?php echo date('F j, Y', strtotime($fromDate)); ?>
            through <?php echo date('F j, Y', strtotime($toDate)); ?>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
        <div class="toolbar-row">
            <form method="get" action="" style="display: contents;">
                <div class="date-group">
                    <label for="from">From</label>
                    <input type="date" id="from" name="from" value="<?php echo htmlspecialchars($fromDate); ?>">
                </div>
                <div class="date-group">
                    <label for="to">To</label>
                    <input type="date" id="to" name="to" value="<?php echo htmlspecialchars($toDate); ?>">
                </div>
                <button type="submit" class="btn-tool primary">
                    <span class="glyphicon glyphicon-refresh"></span> Update
                </button>
            </form>
            <span class="spacer"></span>

            <div class="view-toggle" id="viewToggle">
                <button type="button" class="active" data-view="table">
                    <span class="glyphicon glyphicon-th-list"></span> Table
                </button>
                <button type="button" data-view="card">
                    <span class="glyphicon glyphicon-th-large"></span> Cards
                </button>
            </div>
        </div>

        <div class="toolbar-row">
            <div class="search-box">
                <span class="glyphicon glyphicon-search"></span>
                <input type="text" id="searchInput" placeholder="Search accounts..." autocomplete="off">
                <span class="glyphicon glyphicon-remove-circle clear-search" id="clearSearch"></span>
            </div>

            <div class="dropdown-columns" id="columnsDropdown">
                <button type="button" class="btn-tool" id="columnsBtn">
                    <span class="glyphicon glyphicon-list"></span> Columns
                    <span class="glyphicon glyphicon-triangle-bottom" style="font-size: 9px; margin-left: 4px;"></span>
                </button>
                <div class="columns-menu">
                    <label><input type="checkbox" class="col-toggle" data-col="col-code" checked> Code</label>
                    <label><input type="checkbox" class="col-toggle" data-col="col-name" checked> Account Name</label>
                    <label><input type="checkbox" class="col-toggle" data-col="col-amount" checked> Amount</label>
                    <label><input type="checkbox" class="col-toggle" data-col="col-action" checked> Action</label>
                </div>
            </div>

            <span class="spacer"></span>

            <button type="button" class="btn-tool export-copy" onclick="copyToClipboard()">
                <span class="glyphicon glyphicon-copy"></span> Copy
            </button>
            <button type="button" class="btn-tool export-csv" onclick="exportCSV()">
                <span class="glyphicon glyphicon-file"></span> CSV
            </button>
            <button type="button" class="btn-tool export-excel" onclick="exportExcel()">
                <span class="glyphicon glyphicon-save"></span> Excel
            </button>
            <button type="button" class="btn-tool export-pdf" onclick="window.print()">
                <span class="glyphicon glyphicon-print"></span> PDF / Print
            </button>
        </div>
    </div>

    <!-- Summary tiles -->
    <div class="summary-strip">
        <div class="summary-tile revenue">
            <div class="label">Total Revenue</div>
            <div class="value revenue-val"><?php echo number_format($totalRevenue, 2); ?></div>
            <div class="sub"><?php echo count($revenue); ?> revenue accounts</div>
        </div>
        <div class="summary-tile expense">
            <div class="label">Total Expenses</div>
            <div class="value expense-val"><?php echo number_format($totalExpenses, 2); ?></div>
            <div class="sub"><?php echo count($expenses); ?> expense accounts</div>
        </div>
        <div class="summary-tile <?php echo $netProfit >= 0 ? 'profit' : 'loss'; ?>">
            <div class="label"><?php echo $netProfit >= 0 ? 'Net Profit' : 'Net Loss'; ?></div>
            <div class="value <?php echo $netProfit >= 0 ? 'profit-val' : 'loss-val'; ?>">
                <?php echo number_format(abs($netProfit), 2); ?>
            </div>
            <div class="sub">
                <?php if ($totalRevenue > 0): ?>
                    Margin: <?php echo number_format($profitMargin, 1); ?>%
                <?php else: ?>
                    No revenue to compute margin
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Revenue Section -->
    <div class="section-card">
        <div class="section-header">
            <div class="title">
                <span class="glyphicon glyphicon-usd"></span>
                Revenue
                <span class="badge-count"><?php echo count($revenue); ?></span>
            </div>
            <div class="subtotal-mini"><?php echo number_format($totalRevenue, 2); ?></div>
        </div>

        <?php if (empty($revenue)): ?>
            <div class="empty-state">No revenue in this period.</div>
        <?php else: ?>

            <table class="data-table" id="revenueTable">
                <thead>
                    <tr>
                        <th class="col-code" style="width: 100px;">Code</th>
                        <th class="col-name">Account Name</th>
                        <th class="col-amount amount-col">Amount</th>
                        <th class="col-action action-col">Open</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($revenue as $r): ?>
                        <tr class="data-row" data-search="<?php echo htmlspecialchars(strtolower($r['code'] . ' ' . $r['name'])); ?>">
                            <td class="acc-code col-code"><?php echo htmlspecialchars($r['code']); ?></td>
                            <td class="acc-name col-name">
                                <?php echo htmlspecialchars($r['name']); ?>
                                <?php if (!empty($r['is_contra'])): ?>
                                    <span class="contra-badge">Contra</span>
                                <?php endif; ?>
                            </td>
                            <td class="amount-col revenue-amount col-amount">
                                <?php if ((float)$r['amount'] < 0): ?>
                                    (<?php echo number_format(abs($r['amount']), 2); ?>)
                                <?php else: ?>
                                    <?php echo number_format($r['amount'], 2); ?>
                                <?php endif; ?>
                            </td>
                            <td class="action-col col-action">
                                <a href="gl_account_ledger.php?id=<?php echo (int)$r['id']; ?>&from=<?php echo urlencode($fromDate); ?>&to=<?php echo urlencode($toDate); ?>"
                                   class="row-action" title="Open ledger">
                                    <span class="glyphicon glyphicon-new-window"></span> Ledger
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="subtotal-row">
                        <td colspan="2" style="text-align: right;">Net Revenue</td>
                        <td class="amount-col col-amount"><?php echo number_format($totalRevenue, 2); ?></td>
                        <td class="col-action"></td>
                    </tr>
                </tfoot>
            </table>

            <div class="cards-grid" id="revenueCards" style="display: none;">
                <?php foreach ($revenue as $r): ?>
                    <div class="item-card revenue-card data-card"
                         data-search="<?php echo htmlspecialchars(strtolower($r['code'] . ' ' . $r['name'])); ?>">
                        <div class="card-top">
                            <span class="card-code"><?php echo htmlspecialchars($r['code']); ?></span>
                            <span class="card-amount">
                                <?php if ((float)$r['amount'] < 0): ?>
                                    (<?php echo number_format(abs($r['amount']), 2); ?>)
                                <?php else: ?>
                                    <?php echo number_format($r['amount'], 2); ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="card-name">
                            <?php echo htmlspecialchars($r['name']); ?>
                            <?php if (!empty($r['is_contra'])): ?>
                                <span class="contra-badge">Contra</span>
                            <?php endif; ?>
                        </div>
                        <a href="gl_account_ledger.php?id=<?php echo (int)$r['id']; ?>&from=<?php echo urlencode($fromDate); ?>&to=<?php echo urlencode($toDate); ?>"
                           class="card-action">
                            <span class="glyphicon glyphicon-new-window"></span> Ledger
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>
    </div>

    <!-- Expenses Section -->
    <div class="section-card">
        <div class="section-header">
            <div class="title">
                <span class="glyphicon glyphicon-shopping-cart"></span>
                Expenses
                <span class="badge-count"><?php echo count($expenses); ?></span>
            </div>
            <div class="subtotal-mini"><?php echo number_format($totalExpenses, 2); ?></div>
        </div>

        <?php if (empty($expenses)): ?>
            <div class="empty-state">No expenses in this period.</div>
        <?php else: ?>

            <table class="data-table" id="expensesTable">
                <thead>
                    <tr>
                        <th class="col-code" style="width: 100px;">Code</th>
                        <th class="col-name">Account Name</th>
                        <th class="col-amount amount-col">Amount</th>
                        <th class="col-action action-col">Open</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expenses as $e): ?>
                        <tr class="data-row" data-search="<?php echo htmlspecialchars(strtolower($e['code'] . ' ' . $e['name'])); ?>">
                            <td class="acc-code col-code"><?php echo htmlspecialchars($e['code']); ?></td>
                            <td class="acc-name col-name"><?php echo htmlspecialchars($e['name']); ?></td>
                            <td class="amount-col expense-amount col-amount">
                                <?php echo number_format($e['amount'], 2); ?>
                            </td>
                            <td class="action-col col-action">
                                <a href="gl_account_ledger.php?id=<?php echo (int)$e['id']; ?>&from=<?php echo urlencode($fromDate); ?>&to=<?php echo urlencode($toDate); ?>"
                                   class="row-action" title="Open ledger">
                                    <span class="glyphicon glyphicon-new-window"></span> Ledger
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="subtotal-row">
                        <td colspan="2" style="text-align: right;">Total Expenses</td>
                        <td class="amount-col col-amount"><?php echo number_format($totalExpenses, 2); ?></td>
                        <td class="col-action"></td>
                    </tr>
                </tfoot>
            </table>

            <div class="cards-grid" id="expensesCards" style="display: none;">
                <?php foreach ($expenses as $e): ?>
                    <div class="item-card expense-card data-card"
                         data-search="<?php echo htmlspecialchars(strtolower($e['code'] . ' ' . $e['name'])); ?>">
                        <div class="card-top">
                            <span class="card-code"><?php echo htmlspecialchars($e['code']); ?></span>
                            <span class="card-amount"><?php echo number_format($e['amount'], 2); ?></span>
                        </div>
                        <div class="card-name"><?php echo htmlspecialchars($e['name']); ?></div>
                        <a href="gl_account_ledger.php?id=<?php echo (int)$e['id']; ?>&from=<?php echo urlencode($fromDate); ?>&to=<?php echo urlencode($toDate); ?>"
                           class="card-action">
                            <span class="glyphicon glyphicon-new-window"></span> Ledger
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>
    </div>

    <!-- Net Profit Card -->
    <div class="net-card <?php echo $netProfit < 0 ? 'loss' : ''; ?>">
        <div class="left">
            <div class="label"><?php echo $netProfit >= 0 ? 'Net Profit' : 'Net Loss'; ?></div>
            <div class="value"><?php echo number_format(abs($netProfit), 2); ?></div>
        </div>
        <div class="right">
            <div class="sub-label">Revenue</div>
            <div class="sub-val"><?php echo number_format($totalRevenue, 2); ?></div>
            <div class="sub-label" style="margin-top: 12px;">Expenses</div>
            <div class="sub-val muted"><?php echo number_format($totalExpenses, 2); ?></div>
        </div>
    </div>

    <div style="text-align: center; padding: 24px 0; font-size: 12px; color: #94a3b8;">
        <strong>Net Profit = Net Revenue − Total Expenses</strong> ·
        Contra accounts (like Discounts Given) reduce gross revenue.
    </div>

</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
$(document).ready(function() {

    $('#viewToggle button').on('click', function() {
        $('#viewToggle button').removeClass('active');
        $(this).addClass('active');
        var view = $(this).data('view');

        if (view === 'card') {
            $('.data-table').hide();
            $('.cards-grid').show();
        } else {
            $('.data-table').show();
            $('.cards-grid').hide();
        }
    });

    $('#searchInput').on('input', function() {
        var q = $(this).val().toLowerCase().trim();
        $('.data-row, .data-card').each(function() {
            var haystack = $(this).data('search') || '';
            if (q === '' || haystack.indexOf(q) !== -1) {
                $(this).removeClass('filtered-out');
            } else {
                $(this).addClass('filtered-out');
            }
        });
    });

    $('#clearSearch').on('click', function() {
        $('#searchInput').val('').trigger('input').focus();
    });

    $('#columnsBtn').on('click', function(e) {
        e.stopPropagation();
        $('#columnsDropdown').toggleClass('open');
    });

    $(document).on('click', function(e) {
        if (!$(e.target).closest('#columnsDropdown').length) {
            $('#columnsDropdown').removeClass('open');
        }
    });

    $('.col-toggle').on('change', function() {
        var col = $(this).data('col');
        if ($(this).is(':checked')) {
            $('.' + col).removeClass('hidden-column');
        } else {
            $('.' + col).addClass('hidden-column');
        }
    });

});

/* ---------- Report data extraction ---------- */

function buildReportData() {
    var rows = [];

    $('#revenueTable tbody tr').each(function() {
        var code = $(this).find('.col-code').text().trim();
        var name = $(this).find('.col-name').text().trim();
        var amountText = $(this).find('.col-amount').text().trim();
        if (code && name) {
            rows.push({
                section: 'Revenue',
                code: code,
                name: name,
                amountText: amountText,
                amountNum: parseAmount(amountText)
            });
        }
    });

    $('#expensesTable tbody tr').each(function() {
        var code = $(this).find('.col-code').text().trim();
        var name = $(this).find('.col-name').text().trim();
        var amountText = $(this).find('.col-amount').text().trim();
        if (code && name) {
            rows.push({
                section: 'Expense',
                code: code,
                name: name,
                amountText: amountText,
                amountNum: parseAmount(amountText)
            });
        }
    });

    return rows;
}

function parseAmount(text) {
    if (!text) return 0;
    var isNegative = text.indexOf('(') !== -1 && text.indexOf(')') !== -1;
    var cleaned = text.replace(/[(),\s]/g, '');
    var num = parseFloat(cleaned) || 0;
    return isNegative ? -num : num;
}

/* ---------- Copy ---------- */

function copyToClipboard() {
    var rows = buildReportData();
    var headers = ['Section', 'Code', 'Account', 'Amount'];
    var lines = [headers.join('\t')];
    rows.forEach(function(r) {
        lines.push([r.section, r.code, r.name, r.amountText].join('\t'));
    });
    var text = lines.join('\n');

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function() {
            showToast('Copied to clipboard');
        }).catch(function() {
            fallbackCopy(text);
        });
    } else {
        fallbackCopy(text);
    }
}

function fallbackCopy(text) {
    var textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    try {
        document.execCommand('copy');
        showToast('Copied to clipboard');
    } catch (e) {
        alert('Copy failed. Your browser may not allow clipboard access.');
    }
    document.body.removeChild(textarea);
}

/* ---------- CSV ---------- */

function exportCSV() {
    var rows = buildReportData();
    var csv = 'Section,Code,Account,Amount\n';
    rows.forEach(function(r) {
        csv += '"' + r.section + '","' + r.code + '","' +
               r.name.replace(/"/g, '""') + '",' + r.amountNum + '\n';
    });

    var totalRev = 0, totalExp = 0;
    rows.forEach(function(r) {
        if (r.section === 'Revenue') totalRev += r.amountNum;
        else totalExp += r.amountNum;
    });
    var netProfit = totalRev - totalExp;

    csv += '\n';
    csv += '"Total Revenue","","",' + totalRev + '\n';
    csv += '"Total Expenses","","",' + totalExp + '\n';
    csv += '"' + (netProfit >= 0 ? 'Net Profit' : 'Net Loss') + '","","",' + netProfit + '\n';

    var blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
    var link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'pl_report_' + new Date().toISOString().slice(0, 10) + '.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    showToast('CSV downloaded');
}

/* ---------- Excel (real .xlsx) ---------- */

function exportExcel() {
    if (typeof XLSX === 'undefined') {
        alert('Excel library failed to load. Check your internet connection and try again.');
        return;
    }

    var rows = buildReportData();
    var data = [['Section', 'Code', 'Account', 'Amount']];
    rows.forEach(function(r) {
        data.push([r.section, r.code, r.name, r.amountNum]);
    });

    var totalRev = 0, totalExp = 0;
    rows.forEach(function(r) {
        if (r.section === 'Revenue') totalRev += r.amountNum;
        else totalExp += r.amountNum;
    });
    var netProfit = totalRev - totalExp;

    data.push([]);
    data.push(['Total Revenue', '', '', totalRev]);
    data.push(['Total Expenses', '', '', totalExp]);
    data.push([netProfit >= 0 ? 'Net Profit' : 'Net Loss', '', '', netProfit]);

    var ws = XLSX.utils.aoa_to_sheet(data);

    ws['!cols'] = [
        { wch: 14 },
        { wch: 10 },
        { wch: 45 },
        { wch: 18 },
    ];

    var wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'P&L');

    var dateStr = new Date().toISOString().slice(0, 10);
    XLSX.writeFile(wb, 'pl_report_' + dateStr + '.xlsx');
    showToast('Excel file downloaded');
}

/* ---------- Toast ---------- */

function showToast(msg) {
    var $t = $('<div style="position:fixed;bottom:24px;right:24px;background:#1e293b;color:#fff;padding:12px 20px;border-radius:8px;font-size:14px;font-weight:600;box-shadow:0 8px 24px rgba(0,0,0,0.15);z-index:9999;">'
        + '<span class="glyphicon glyphicon-ok-sign" style="margin-right:8px;color:#4ade80;"></span>'
        + msg + '</div>');
    $('body').append($t);
    setTimeout(function() {
        $t.fadeOut(300, function() { $(this).remove(); });
    }, 2200);
}
</script>

</body>
</html>
<?php $conn->close(); ?>