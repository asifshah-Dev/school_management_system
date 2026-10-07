<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

// --- Date range ---
$fromDate = date('Y-m-01');
$toDate   = date('Y-m-d');
if (isset($_GET['from']) && $_GET['from'] !== '') {
    $t = date_create($_GET['from']);
    if ($t !== false) $fromDate = $t->format('Y-m-d');
}
if (isset($_GET['to']) && $_GET['to'] !== '') {
    $t = date_create($_GET['to']);
    if ($t !== false) $toDate = $t->format('Y-m-d');
}

// --- Helper: signed balance of an account as of a date ---
function bs_balance($conn, $account, $asOf) {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) AS net
        FROM gl_journal_lines l
        JOIN gl_transactions t ON t.id = l.transaction_id
        WHERE l.account_id = ? AND t.entry_date <= ?
    ");
    $stmt->bind_param("is", $account['id'], $asOf);
    $stmt->execute();
    $net = (float)$stmt->get_result()->fetch_assoc()['net'];
    $stmt->close();
    $isDebitNormal = in_array($account['account_type'], ['ASSET','EXPENSE'], true) || (int)$account['is_contra'] === 1;
    return $isDebitNormal ? $net : -$net;
}

// --- Fetch all postable accounts ---
$accounts = $conn->query("
    SELECT id, code, name, account_type, is_contra
    FROM gl_accounts
    WHERE is_postable = 1 AND status = 1
    ORDER BY account_type, code
")->fetch_all(MYSQLI_ASSOC);

$assets = []; $liabilities = []; $equity = []; $revenue = []; $expenses = [];

$totalAssets = 0; $totalLiabilities = 0; $totalEquity = 0;
$totalRevenue = 0; $totalExpenses = 0;
$changeAssets = 0; $changeLiabilities = 0; $changeEquity = 0;
$changeRevenue = 0; $changeExpenses = 0;

$previousDate = date('Y-m-d', strtotime($fromDate . ' -1 day'));

foreach ($accounts as $a) {
    $id = (int)$a['id'];

    $balCurrent  = bs_balance($conn, $a, $toDate);
    $balPrevious = bs_balance($conn, $a, $previousDate);
    $change = $balCurrent - $balPrevious;

    if (abs($balCurrent) < 0.005 && abs($change) < 0.005) continue;

    $entry = [
        'id' => $id,
        'code' => $a['code'],
        'name' => $a['name'],
        'is_contra' => $a['is_contra'],
        'balance' => $balCurrent,
        'change' => $change,
    ];

    switch ($a['account_type']) {
        case 'ASSET':     $assets[] = $entry;      $totalAssets += $balCurrent;      $changeAssets += $change; break;
        case 'LIABILITY': $liabilities[] = $entry; $totalLiabilities += $balCurrent; $changeLiabilities += $change; break;
        case 'EQUITY':    $equity[] = $entry;      $totalEquity += $balCurrent;      $changeEquity += $change; break;
        case 'REVENUE':   $revenue[] = $entry;     $totalRevenue += $balCurrent;     $changeRevenue += $change; break;
        case 'EXPENSE':   $expenses[] = $entry;    $totalExpenses += $balCurrent;    $changeExpenses += $change; break;
    }
}

$netIncome = $totalRevenue - $totalExpenses;
$changeNetIncome = $changeRevenue - $changeExpenses;

$totalRight = $totalLiabilities + $totalEquity + $netIncome;
$changeTotalRight = $changeLiabilities + $changeEquity + $changeNetIncome;

$difference = $totalAssets - $totalRight;
$balanced = abs($difference) < 0.01;

// Helper for formatting changes
function fmtChange($val) {
    if (abs($val) < 0.005) return ['text' => '—', 'class' => 'zero'];
    $sign = $val > 0 ? '+' : '−';
    return [
        'text' => $sign . number_format(abs($val), 2),
        'class' => $val > 0 ? 'pos' : 'neg'
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    
    <title>Balance Sheet — <?php echo htmlspecialchars($toDate); ?></title>
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
            max-width: 1300px;
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
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
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
        .dropdown-columns { position: relative; display: inline-block; }
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
            display: flex; align-items: center; gap: 10px;
            padding: 8px 12px; border-radius: 6px;
            cursor: pointer; font-size: 13px;
            font-weight: 500; color: #1e293b; margin: 0;
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
        .summary-tile.assets      { border-color: #1e40af; }
        .summary-tile.liabilities { border-color: #c2410c; }
        .summary-tile.equity      { border-color: #6b21a8; }
        .summary-tile.ok          { border-color: #059669; }
        .summary-tile.bad         { border-color: #dc2626; background: #fef2f2; }

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
        .summary-tile .value.assets-val      { color: #1e40af; }
        .summary-tile .value.liabilities-val { color: #c2410c; }
        .summary-tile .value.equity-val      { color: #6b21a8; }
        .summary-tile .value.ok-val          { color: #047857; }
        .summary-tile .value.bad-val         { color: #b91c1c; }
        .summary-tile .sub {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 6px;
        }

        /* ---------- Section cards ---------- */
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
        .data-table thead th.change-col { text-align: right; width: 130px; }

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
        .data-table .change-col {
            text-align: right;
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 13px;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }
        .data-table .change-col.pos { color: #047857; }
        .data-table .change-col.neg { color: #b91c1c; }
        .data-table .change-col.zero { color: #cbd5e1; font-weight: 400; }

        .data-table .action-col { text-align: center; }
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
        .data-table .subtotal-row .amount-col { font-size: 16px; font-weight: 800; }
        .data-table .subtotal-row .change-col { font-size: 14px; font-weight: 800; color: #1e293b !important; }

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
            color: #0f172a;
        }
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
        .item-card .card-change {
            font-size: 12px;
            color: #64748b;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 6px;
            border-top: 1px solid #f1f5f9;
        }
        .item-card .card-change .lbl { font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.8px; font-size: 10px; }
        .item-card .card-change .val { font-family: 'SF Mono', 'Monaco', monospace; font-weight: 700; font-size: 13px; }
        .item-card .card-change .val.pos { color: #047857; }
        .item-card .card-change .val.neg { color: #b91c1c; }
        .item-card .card-change .val.zero { color: #cbd5e1; font-weight: 400; }
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

        /* ---------- Bottom Summary Card ---------- */
        .bs-bottom {
            border-radius: 14px;
            padding: 32px 36px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 24px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
            color: #fff;
        }
        .bs-bottom.balanced {
            background: linear-gradient(135deg, #064e3b 0%, #065f46 100%);
            box-shadow: 0 8px 24px rgba(6, 78, 59, 0.25);
        }
        .bs-bottom.unbalanced {
            background: linear-gradient(135deg, #7f1d1d 0%, #991b1b 100%);
            box-shadow: 0 8px 24px rgba(127, 29, 29, 0.25);
        }
        .bs-bottom .left .label {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: rgba(255, 255, 255, 0.75);
            margin-bottom: 10px;
        }
        .bs-bottom .left .value {
            font-size: 40px;
            font-weight: 800;
            font-family: 'SF Mono', 'Monaco', monospace;
            font-variant-numeric: tabular-nums;
            letter-spacing: -1px;
            line-height: 1;
        }
        .bs-bottom .right { text-align: right; }
        .bs-bottom .right .sub-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: rgba(255, 255, 255, 0.7);
            font-weight: 700;
            margin-bottom: 4px;
        }
        .bs-bottom .right .sub-val {
            font-size: 20px;
            font-weight: 700;
            font-family: 'SF Mono', 'Monaco', monospace;
            color: #fff;
        }

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
                display: block;
            }

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
            .data-table thead th.change-col { text-align: right; }

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
            .data-table .amount-col,
            .data-table .change-col {
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

            .bs-bottom {
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
            .bs-bottom .left {
                display: flex !important;
                justify-content: space-between;
                align-items: baseline;
                padding: 6pt 10pt !important;
                background: #e8e8e8 !important;
                border-bottom: 0.5pt solid #808080;
            }
            .bs-bottom .left .label {
                font-size: 11pt;
                font-weight: bold;
                color: #000 !important;
                text-transform: none;
                letter-spacing: 0;
                margin: 0;
                opacity: 1;
            }
            .bs-bottom .left .value {
                font-size: 12pt;
                font-weight: bold;
                font-family: Calibri, Arial, sans-serif;
                letter-spacing: 0;
                color: #000 !important;
            }
            .bs-bottom .right { display: none !important; }

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
            .bs-bottom { padding: 24px 20px; }
            .bs-bottom .left .value { font-size: 32px; }
        }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="report-wrap">

    <!-- Header -->
    <div class="report-head">
       
        <h1>Balance Sheet</h1>
        <div class="period">
            <span><strong>Snapshot:</strong> <?php echo date('F j, Y', strtotime($toDate)); ?></span>
            <span><strong>Change from:</strong> <?php echo date('F j, Y', strtotime($fromDate)); ?></span>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
        <div class="toolbar-row">
            <form method="get" action="" style="display: contents;">
                <div class="date-group">
                    <label for="from">Change From</label>
                    <input type="date" id="from" name="from" value="<?php echo htmlspecialchars($fromDate); ?>">
                </div>
                <div class="date-group">
                    <label for="to">Snapshot As Of</label>
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
                    <label><input type="checkbox" class="col-toggle" data-col="col-amount" checked> Balance</label>
                    <label><input type="checkbox" class="col-toggle" data-col="col-change" checked> Change</label>
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
        <div class="summary-tile assets">
            <div class="label">Total Assets</div>
            <div class="value assets-val"><?php echo number_format($totalAssets, 2); ?></div>
            <div class="sub"><?php echo count($assets); ?> asset accounts</div>
        </div>
        <div class="summary-tile liabilities">
            <div class="label">Total Liabilities</div>
            <div class="value liabilities-val"><?php echo number_format($totalLiabilities, 2); ?></div>
            <div class="sub"><?php echo count($liabilities); ?> liability accounts</div>
        </div>
        <div class="summary-tile <?php echo $balanced ? 'ok' : 'bad'; ?>">
            <div class="label"><?php echo $balanced ? 'Balanced ✓' : 'Out of Balance ✗'; ?></div>
            <div class="value <?php echo $balanced ? 'ok-val' : 'bad-val'; ?>">
                <?php echo number_format(abs($difference), 2); ?>
            </div>
            <div class="sub"><?php echo $balanced ? 'Assets = Liabilities + Equity' : 'Difference (investigate)'; ?></div>
        </div>
    </div>

    <!-- ASSETS SECTION -->
    <div class="section-card">
        <div class="section-header">
            <div class="title">
                <span class="glyphicon glyphicon-home"></span>
                Assets
                <span class="badge-count"><?php echo count($assets); ?></span>
            </div>
            <div class="subtotal-mini"><?php echo number_format($totalAssets, 2); ?></div>
        </div>

        <?php if (empty($assets)): ?>
            <div class="empty-state">No assets recorded.</div>
        <?php else: ?>

            <table class="data-table" id="assetsTable">
                <thead>
                    <tr>
                        <th class="col-code" style="width: 100px;">Code</th>
                        <th class="col-name">Account Name</th>
                        <th class="col-amount amount-col">Balance</th>
                        <th class="col-change change-col">Change</th>
                        <th class="col-action action-col">Open</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assets as $a):
                        $chg = fmtChange($a['change']);
                    ?>
                        <tr class="data-row" data-search="<?php echo htmlspecialchars(strtolower($a['code'] . ' ' . $a['name'])); ?>">
                            <td class="acc-code col-code"><?php echo htmlspecialchars($a['code']); ?></td>
                            <td class="acc-name col-name">
                                <?php echo htmlspecialchars($a['name']); ?>
                                <?php if (!empty($a['is_contra'])): ?>
                                    <span class="contra-badge">Contra</span>
                                <?php endif; ?>
                            </td>
                            <td class="amount-col col-amount"><?php echo number_format($a['balance'], 2); ?></td>
                            <td class="change-col <?php echo $chg['class']; ?>"><?php echo $chg['text']; ?></td>
                            <td class="action-col col-action">
                                <a href="gl_account_ledger.php?id=<?php echo (int)$a['id']; ?>&from=<?php echo urlencode($fromDate); ?>&to=<?php echo urlencode($toDate); ?>"
                                   class="row-action" title="Open ledger">
                                    <span class="glyphicon glyphicon-new-window"></span> Ledger
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="subtotal-row">
                        <td colspan="2" style="text-align: right;">Total Assets</td>
                        <td class="amount-col col-amount"><?php echo number_format($totalAssets, 2); ?></td>
                        <td class="change-col"><?php $chg = fmtChange($changeAssets); echo $chg['text']; ?></td>
                        <td class="col-action"></td>
                    </tr>
                </tfoot>
            </table>

            <div class="cards-grid" id="assetsCards" style="display: none;">
                <?php foreach ($assets as $a):
                    $chg = fmtChange($a['change']);
                ?>
                    <div class="item-card data-card"
                         data-search="<?php echo htmlspecialchars(strtolower($a['code'] . ' ' . $a['name'])); ?>">
                        <div class="card-top">
                            <span class="card-code"><?php echo htmlspecialchars($a['code']); ?></span>
                            <span class="card-amount"><?php echo number_format($a['balance'], 2); ?></span>
                        </div>
                        <div class="card-name">
                            <?php echo htmlspecialchars($a['name']); ?>
                            <?php if (!empty($a['is_contra'])): ?>
                                <span class="contra-badge">Contra</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-change">
                            <span class="lbl">Change</span>
                            <span class="val <?php echo $chg['class']; ?>"><?php echo $chg['text']; ?></span>
                        </div>
                        <a href="gl_account_ledger.php?id=<?php echo (int)$a['id']; ?>&from=<?php echo urlencode($fromDate); ?>&to=<?php echo urlencode($toDate); ?>"
                           class="card-action">
                            <span class="glyphicon glyphicon-new-window"></span> Ledger
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>
    </div>

    <!-- LIABILITIES SECTION -->
    <div class="section-card">
        <div class="section-header">
            <div class="title">
                <span class="glyphicon glyphicon-transfer"></span>
                Liabilities
                <span class="badge-count"><?php echo count($liabilities); ?></span>
            </div>
            <div class="subtotal-mini"><?php echo number_format($totalLiabilities, 2); ?></div>
        </div>

        <?php if (empty($liabilities)): ?>
            <div class="empty-state">No liabilities recorded.</div>
        <?php else: ?>

            <table class="data-table" id="liabilitiesTable">
                <thead>
                    <tr>
                        <th class="col-code" style="width: 100px;">Code</th>
                        <th class="col-name">Account Name</th>
                        <th class="col-amount amount-col">Balance</th>
                        <th class="col-change change-col">Change</th>
                        <th class="col-action action-col">Open</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($liabilities as $l):
                        $chg = fmtChange($l['change']);
                    ?>
                        <tr class="data-row" data-search="<?php echo htmlspecialchars(strtolower($l['code'] . ' ' . $l['name'])); ?>">
                            <td class="acc-code col-code"><?php echo htmlspecialchars($l['code']); ?></td>
                            <td class="acc-name col-name"><?php echo htmlspecialchars($l['name']); ?></td>
                            <td class="amount-col col-amount"><?php echo number_format($l['balance'], 2); ?></td>
                            <td class="change-col <?php echo $chg['class']; ?>"><?php echo $chg['text']; ?></td>
                            <td class="action-col col-action">
                                <a href="gl_account_ledger.php?id=<?php echo (int)$l['id']; ?>&from=<?php echo urlencode($fromDate); ?>&to=<?php echo urlencode($toDate); ?>"
                                   class="row-action" title="Open ledger">
                                    <span class="glyphicon glyphicon-new-window"></span> Ledger
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="subtotal-row">
                        <td colspan="2" style="text-align: right;">Total Liabilities</td>
                        <td class="amount-col col-amount"><?php echo number_format($totalLiabilities, 2); ?></td>
                        <td class="change-col"><?php $chg = fmtChange($changeLiabilities); echo $chg['text']; ?></td>
                        <td class="col-action"></td>
                    </tr>
                </tfoot>
            </table>

            <div class="cards-grid" id="liabilitiesCards" style="display: none;">
                <?php foreach ($liabilities as $l):
                    $chg = fmtChange($l['change']);
                ?>
                    <div class="item-card data-card"
                         data-search="<?php echo htmlspecialchars(strtolower($l['code'] . ' ' . $l['name'])); ?>">
                        <div class="card-top">
                            <span class="card-code"><?php echo htmlspecialchars($l['code']); ?></span>
                            <span class="card-amount"><?php echo number_format($l['balance'], 2); ?></span>
                        </div>
                        <div class="card-name"><?php echo htmlspecialchars($l['name']); ?></div>
                        <div class="card-change">
                            <span class="lbl">Change</span>
                            <span class="val <?php echo $chg['class']; ?>"><?php echo $chg['text']; ?></span>
                        </div>
                        <a href="gl_account_ledger.php?id=<?php echo (int)$l['id']; ?>&from=<?php echo urlencode($fromDate); ?>&to=<?php echo urlencode($toDate); ?>"
                           class="card-action">
                            <span class="glyphicon glyphicon-new-window"></span> Ledger
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>
    </div>

    <!-- EQUITY SECTION -->
    <div class="section-card">
        <div class="section-header">
            <div class="title">
                <span class="glyphicon glyphicon-briefcase"></span>
                Equity
                <span class="badge-count"><?php echo count($equity); ?></span>
            </div>
            <div class="subtotal-mini"><?php echo number_format($totalEquity + $netIncome, 2); ?></div>
        </div>

        <?php if (empty($equity) && abs($netIncome) < 0.005): ?>
            <div class="empty-state">No equity recorded.</div>
        <?php else: ?>

            <table class="data-table" id="equityTable">
                <thead>
                    <tr>
                        <th class="col-code" style="width: 100px;">Code</th>
                        <th class="col-name">Account Name</th>
                        <th class="col-amount amount-col">Balance</th>
                        <th class="col-change change-col">Change</th>
                        <th class="col-action action-col">Open</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($equity as $e):
                        $chg = fmtChange($e['change']);
                    ?>
                        <tr class="data-row" data-search="<?php echo htmlspecialchars(strtolower($e['code'] . ' ' . $e['name'])); ?>">
                            <td class="acc-code col-code"><?php echo htmlspecialchars($e['code']); ?></td>
                            <td class="acc-name col-name"><?php echo htmlspecialchars($e['name']); ?></td>
                            <td class="amount-col col-amount"><?php echo number_format($e['balance'], 2); ?></td>
                            <td class="change-col <?php echo $chg['class']; ?>"><?php echo $chg['text']; ?></td>
                            <td class="action-col col-action">
                                <a href="gl_account_ledger.php?id=<?php echo (int)$e['id']; ?>&from=<?php echo urlencode($fromDate); ?>&to=<?php echo urlencode($toDate); ?>"
                                   class="row-action" title="Open ledger">
                                    <span class="glyphicon glyphicon-new-window"></span> Ledger
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <tr class="data-row" data-search="current period net income retained earnings">
                        <td class="acc-code col-code">—</td>
                        <td class="acc-name col-name"><em>Current Period Net Income</em></td>
                        <td class="amount-col col-amount"><em><?php echo number_format($netIncome, 2); ?></em></td>
                        <td class="change-col <?php $chg = fmtChange($changeNetIncome); echo $chg['class']; ?>"><em><?php echo $chg['text']; ?></em></td>
                        <td class="action-col col-action"></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="subtotal-row">
                        <td colspan="2" style="text-align: right;">Total Equity</td>
                        <td class="amount-col col-amount"><?php echo number_format($totalEquity + $netIncome, 2); ?></td>
                        <td class="change-col"><?php $chg = fmtChange($changeEquity + $changeNetIncome); echo $chg['text']; ?></td>
                        <td class="col-action"></td>
                    </tr>
                </tfoot>
            </table>

            <div class="cards-grid" id="equityCards" style="display: none;">
                <?php foreach ($equity as $e):
                    $chg = fmtChange($e['change']);
                ?>
                    <div class="item-card data-card"
                         data-search="<?php echo htmlspecialchars(strtolower($e['code'] . ' ' . $e['name'])); ?>">
                        <div class="card-top">
                            <span class="card-code"><?php echo htmlspecialchars($e['code']); ?></span>
                            <span class="card-amount"><?php echo number_format($e['balance'], 2); ?></span>
                        </div>
                        <div class="card-name"><?php echo htmlspecialchars($e['name']); ?></div>
                        <div class="card-change">
                            <span class="lbl">Change</span>
                            <span class="val <?php echo $chg['class']; ?>"><?php echo $chg['text']; ?></span>
                        </div>
                        <a href="gl_account_ledger.php?id=<?php echo (int)$e['id']; ?>&from=<?php echo urlencode($fromDate); ?>&to=<?php echo urlencode($toDate); ?>"
                           class="card-action">
                            <span class="glyphicon glyphicon-new-window"></span> Ledger
                        </a>
                    </div>
                <?php endforeach; ?>
                <div class="item-card data-card" data-search="current period net income">
                    <div class="card-top">
                        <span class="card-code">—</span>
                        <span class="card-amount"><em><?php echo number_format($netIncome, 2); ?></em></span>
                    </div>
                    <div class="card-name"><em>Current Period Net Income</em></div>
                    <div class="card-change">
                        <span class="lbl">Change</span>
                        <span class="val <?php $chg = fmtChange($changeNetIncome); echo $chg['class']; ?>"><?php echo $chg['text']; ?></span>
                    </div>
                </div>
            </div>

        <?php endif; ?>
    </div>

    <!-- Bottom Summary: Assets vs L+E -->
    <div class="bs-bottom <?php echo $balanced ? 'balanced' : 'unbalanced'; ?>">
        <div class="left">
            <div class="label">Assets</div>
            <div class="value"><?php echo number_format($totalAssets, 2); ?></div>
        </div>
        <div class="right">
            <div class="sub-label">Liabilities + Equity</div>
            <div class="sub-val"><?php echo number_format($totalRight, 2); ?></div>
            <div class="sub-label" style="margin-top: 12px;">
                <?php echo $balanced ? 'Balanced ✓' : 'Off by'; ?>
            </div>
            <div class="sub-val">
                <?php echo $balanced ? '—' : number_format(abs($difference), 2); ?>
            </div>
        </div>
    </div>

    <div style="text-align: center; padding: 24px 0; font-size: 12px; color: #94a3b8;">
        <strong>Assets = Liabilities + Equity</strong> ·
        Current period profit is included in Equity as "Net Income".
    </div>

</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
$(document).ready(function() {

    /* ---- View toggle ---- */
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

    /* ---- Search ---- */
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

    /* ---- Columns dropdown ---- */
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

    function pushSection(tableId, sectionName) {
        $('#' + tableId + ' tbody tr').each(function() {
            var code = $(this).find('.col-code').text().trim();
            var name = $(this).find('.col-name').text().trim();
            var balText = $(this).find('.col-amount').text().trim();
            var chgText = $(this).find('.col-change').text().trim();
            if (code && name) {
                rows.push({
                    section: sectionName,
                    code: code,
                    name: name,
                    balanceText: balText,
                    balanceNum: parseAmount(balText),
                    changeText: chgText,
                    changeNum: parseSignedAmount(chgText)
                });
            }
        });
    }

    pushSection('assetsTable', 'Assets');
    pushSection('liabilitiesTable', 'Liabilities');
    pushSection('equityTable', 'Equity');

    return rows;
}

function parseAmount(text) {
    if (!text) return 0;
    var isNegative = text.indexOf('(') !== -1 && text.indexOf(')') !== -1;
    var cleaned = text.replace(/[(),\s]/g, '');
    var num = parseFloat(cleaned) || 0;
    return isNegative ? -num : num;
}

function parseSignedAmount(text) {
    if (!text || text === '—') return 0;
    var isNegative = text.indexOf('−') !== -1 || text.indexOf('-') === 0;
    var cleaned = text.replace(/[−+\-,\s]/g, '');
    var num = parseFloat(cleaned) || 0;
    return isNegative ? -num : num;
}

/* ---------- Copy ---------- */

function copyToClipboard() {
    var rows = buildReportData();
    var headers = ['Section', 'Code', 'Account', 'Balance', 'Change'];
    var lines = [headers.join('\t')];
    rows.forEach(function(r) {
        lines.push([r.section, r.code, r.name, r.balanceText, r.changeText].join('\t'));
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
    var csv = 'Section,Code,Account,Balance,Change\n';
    rows.forEach(function(r) {
        csv += '"' + r.section + '","' + r.code + '","' +
               r.name.replace(/"/g, '""') + '",' + r.balanceNum + ',' + r.changeNum + '\n';
    });

    // Section totals
    var tAssets = 0, tLiab = 0, tEquity = 0;
    rows.forEach(function(r) {
        if (r.section === 'Assets') tAssets += r.balanceNum;
        else if (r.section === 'Liabilities') tLiab += r.balanceNum;
        else if (r.section === 'Equity') tEquity += r.balanceNum;
    });

    csv += '\n';
    csv += '"Total Assets","","",' + tAssets + ',\n';
    csv += '"Total Liabilities","","",' + tLiab + ',\n';
    csv += '"Total Equity","","",' + tEquity + ',\n';

    var blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
    var link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'balance_sheet_' + new Date().toISOString().slice(0, 10) + '.csv';
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
    var data = [['Section', 'Code', 'Account', 'Balance', 'Change']];
    rows.forEach(function(r) {
        data.push([r.section, r.code, r.name, r.balanceNum, r.changeNum]);
    });

    var tAssets = 0, tLiab = 0, tEquity = 0;
    var changeAssets = 0, changeLiab = 0, changeEquity = 0;
    rows.forEach(function(r) {
        if (r.section === 'Assets') { tAssets += r.balanceNum; changeAssets += r.changeNum; }
        else if (r.section === 'Liabilities') { tLiab += r.balanceNum; changeLiab += r.changeNum; }
        else if (r.section === 'Equity') { tEquity += r.balanceNum; changeEquity += r.changeNum; }
    });

    data.push([]);
    data.push(['Total Assets', '', '', tAssets, changeAssets]);
    data.push(['Total Liabilities', '', '', tLiab, changeLiab]);
    data.push(['Total Equity', '', '', tEquity, changeEquity]);

    var ws = XLSX.utils.aoa_to_sheet(data);

    ws['!cols'] = [
        { wch: 14 },
        { wch: 10 },
        { wch: 45 },
        { wch: 18 },
        { wch: 16 },
    ];

    var wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Balance Sheet');

    var dateStr = new Date().toISOString().slice(0, 10);
    XLSX.writeFile(wb, 'balance_sheet_' + dateStr + '.xlsx');
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