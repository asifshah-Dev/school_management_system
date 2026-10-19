<?php // TESTING 12345
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
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

// --- Fetch all postable accounts ---
$accounts = $conn->query("
    SELECT id, code, name, account_type, is_contra
    FROM gl_accounts
    WHERE is_postable = 1 AND status = 1
    ORDER BY account_type, code
")->fetch_all(MYSQLI_ASSOC);

// --- Opening balance and movement ---
$opening = [];
$movement = [];
$closing = [];

foreach ($accounts as $a) {
    $id = (int)$a['id'];

    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(l.debit), 0) AS dr, COALESCE(SUM(l.credit), 0) AS cr
        FROM gl_journal_lines l
        JOIN gl_transactions t ON t.id = l.transaction_id
        WHERE l.account_id = ? AND t.entry_date < ?
    ");
    $stmt->bind_param("is", $id, $fromDate);
    $stmt->execute();
    $o = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $opening[$id] = (float)$o['dr'] - (float)$o['cr'];

    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(l.debit), 0) AS dr, COALESCE(SUM(l.credit), 0) AS cr
        FROM gl_journal_lines l
        JOIN gl_transactions t ON t.id = l.transaction_id
        WHERE l.account_id = ? AND t.entry_date BETWEEN ? AND ?
    ");
    $stmt->bind_param("iss", $id, $fromDate, $toDate);
    $stmt->execute();
    $m = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $movement[$id] = ['dr' => (float)$m['dr'], 'cr' => (float)$m['cr']];

    $closing[$id] = $opening[$id] + $movement[$id]['dr'] - $movement[$id]['cr'];
}

// --- Sum totals ---
$totalOpenDr = 0; $totalOpenCr = 0;
$totalRangeDr = 0; $totalRangeCr = 0;
$totalCloseDr = 0; $totalCloseCr = 0;

foreach ($accounts as $a) {
    $id = (int)$a['id'];
    $open  = $opening[$id];
    $mvDr  = $movement[$id]['dr'];
    $mvCr  = $movement[$id]['cr'];
    $close = $closing[$id];

    if ($open > 0.005)   $totalOpenDr += $open; else if ($open < -0.005) $totalOpenCr += abs($open);
    $totalRangeDr += $mvDr;
    $totalRangeCr += $mvCr;
    if ($close > 0.005)  $totalCloseDr += $close; else if ($close < -0.005) $totalCloseCr += abs($close);
}

$balancedClose = abs($totalCloseDr - $totalCloseCr) < 0.01;

// --- Build sections ---
$byType = ['ASSET'=>[], 'LIABILITY'=>[], 'EQUITY'=>[], 'REVENUE'=>[], 'EXPENSE'=>[]];
foreach ($accounts as $a) $byType[$a['account_type']][] = $a;

$sections = [];
foreach ($byType as $type => $list) {
    if (empty($list)) continue;

    $hasActivity = false;
    foreach ($list as $a) {
        $id = (int)$a['id'];
        if (abs($opening[$id]) > 0.005 || abs($movement[$id]['dr']) > 0.005 || abs($movement[$id]['cr']) > 0.005) {
            $hasActivity = true; break;
        }
    }
    if (!$hasActivity) continue;

    $rows = [];
    foreach ($list as $a) {
        $id = (int)$a['id'];
        $open  = $opening[$id];
        $mvDr  = $movement[$id]['dr'];
        $mvCr  = $movement[$id]['cr'];
        $close = $closing[$id];

        if (abs($open) < 0.005 && abs($mvDr) < 0.005 && abs($mvCr) < 0.005) continue;

        $rows[] = [
            'id'         => $id,
            'code'       => $a['code'],
            'name'       => $a['name'],
            'is_contra'  => $a['is_contra'],
            'open_dr'    => $open > 0 ? $open : 0,
            'open_cr'    => $open < 0 ? -$open : 0,
            'mv_dr'      => $mvDr,
            'mv_cr'      => $mvCr,
            'close_dr'   => $close > 0 ? $close : 0,
            'close_cr'   => $close < 0 ? -$close : 0,
        ];
    }

    $sections[] = [
        'type' => $type,
        'label' => gl_type_label($type) . 's',
        'rows' => $rows,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Trial Balance</title>

<!-- Load jQuery FIRST -->
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<!-- Font Awesome 6 to match navbar -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<style>
    body { background: #f5f7fa; font-family: 'Inter', sans-serif; color: #1e293b; padding-bottom: 40px; }
    .report-wrap { max-width: 1400px; margin: 24px auto; padding: 0 20px; }

    /* ---------- Header ---------- */
    .report-head { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: #fff; padding: 20px 24px; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; }
    .report-head h1 { margin: 0; font-size: 19px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .report-head .period { font-size: 12px; opacity: 0.88; margin-top: 4px; display: flex; gap: 16px; flex-wrap: wrap; }
    .report-head .period strong { font-weight: 700; color: #fff; }

    /* ---------- Toolbar ---------- */
    .toolbar { background: #fff; border-radius: 0 0 12px 12px; padding: 16px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); margin-bottom: 16px; display: flex; flex-direction: column; gap: 12px; }
    .toolbar-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .toolbar-row .spacer { flex: 1; }

    .date-group { display: flex; align-items: center; gap: 8px; }
    .date-group label { font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin: 0; }
    .date-group input[type=date] { height: 34px; padding: 5px 10px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 12.5px; background: #fff; color: #0f172a; font-family: inherit; }
    .date-group input[type=date]:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.12); }

    .btn-tool { height: 34px; padding: 0 14px; border-radius: 7px; font-size: 12.5px; font-weight: 600; border: 1px solid; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; transition: all 0.15s; background: #fff; color: #475569; border-color: #cbd5e1; white-space: nowrap; font-family: inherit; }
    .btn-tool:hover { background: #f8fafc; border-color: #94a3b8; color: #1e293b; text-decoration: none; }
    .btn-tool.primary { background: #2563eb; color: #fff; border-color: #2563eb; }
    .btn-tool.primary:hover { background: #1d4ed8; border-color: #1d4ed8; color: #fff;}
    .btn-tool.export-copy  { color: #4f46e5; border-color: #c7d2fe; background: #eef2ff; }
    .btn-tool.export-copy:hover  { background: #4f46e5; color: #fff; border-color: #4f46e5; }
    .btn-tool.export-csv   { color: #059669; border-color: #a7f3d0; background: #ecfdf5; }
    .btn-tool.export-csv:hover   { background: #059669; color: #fff; border-color: #059669; }
    .btn-tool.export-excel { color: #0284c7; border-color: #bae6fd; background: #f0f9ff; }
    .btn-tool.export-excel:hover { background: #0284c7; color: #fff; border-color: #0284c7; }
    .btn-tool.export-pdf   { color: #dc2626; border-color: #fecaca; background: #fef2f2; }
    .btn-tool.export-pdf:hover   { background: #dc2626; color: #fff; border-color: #dc2626; }

    /* Search & Columns Wrapper */
    .search-group { display: flex; align-items: center; gap: 10px; flex: 1; max-width: 520px; min-width: 280px; }
    .search-box { position: relative; flex: 1; }
    .search-box input { width: 100%; height: 34px; padding: 0 32px 0 32px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 12.5px; background: #fff; color: #0f172a; font-family: inherit; }
    .search-box input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.12); }
    .search-box .fa-search { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 12px; pointer-events: none; }
    .search-box .clear-search { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; cursor: pointer; font-size: 12px; display: none; }
    .search-box input:not(:placeholder-shown) + .clear-search { display: block; }

    /* Column dropdown */
    .dropdown-columns { position: relative; display: inline-block; flex-shrink: 0; }
    .dropdown-columns .columns-menu { display: none; position: absolute; top: 100%; right: 0; margin-top: 6px; background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,0.12); padding: 6px; z-index: 100; min-width: 200px; }
    .dropdown-columns.open .columns-menu { display: block; }
    .columns-menu label { display: flex; align-items: center; gap: 8px; padding: 6px 10px; border-radius: 5px; cursor: pointer; font-size: 12px; font-weight: 500; color: #1e293b; margin: 0; }
    .columns-menu label:hover { background: #f1f5f9; }
    .columns-menu input[type=checkbox] { cursor: pointer; margin: 0; }

    /* View toggle */
    .view-toggle { display: inline-flex; background: #f1f5f9; border-radius: 7px; padding: 2px; border: 1px solid #e2e8f0; flex-shrink: 0; }
    .view-toggle button { padding: 5px 12px; border: none; background: transparent; border-radius: 5px; font-size: 11.5px; font-weight: 600; color: #64748b; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; transition: all 0.15s; font-family: inherit; }
    .view-toggle button.active { background: #fff; color: #2563eb; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }

    /* ---------- Summary strip ---------- */
    .summary-strip { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 16px; }
    @media (max-width: 800px) { .summary-strip { grid-template-columns: 1fr; } }

    .summary-tile { background: #fff; border-radius: 10px; padding: 14px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border-left: 4px solid; }
    .summary-tile.opening { border-color: #64748b; }
    .summary-tile.range   { border-color: #2563eb; }
    .summary-tile.closing { border-color: #059669; }
    .summary-tile.bad     { border-color: #dc2626; background: #fef2f2; }

    .summary-tile .label { font-size: 9.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
    .summary-tile .value { font-size: 20px; font-weight: 700; font-family: 'SF Mono', monospace; font-variant-numeric: tabular-nums; line-height: 1.1; color: #0f172a; letter-spacing: -0.3px; }
    .summary-tile .value.opening-val { color: #475569; }
    .summary-tile .value.range-val   { color: #2563eb; }
    .summary-tile .value.closing-val { color: #047857; }
    .summary-tile .value.bad-val     { color: #b91c1c; }
    .summary-tile .sub { font-size: 10.5px; color: #94a3b8; margin-top: 3px; }

    /* ---------- Section cards ---------- */
    .section-card { background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); overflow: hidden; margin-bottom: 16px; }
    .section-header { padding: 12px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
    .section-header .title { font-size: 13px; font-weight: 700; color: #1e293b; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 8px; }
    .section-header .badge-count { display: inline-block; padding: 2px 9px; background: #e2e8f0; color: #475569; border-radius: 12px; font-size: 10px; font-weight: 700; letter-spacing: 0; text-transform: none; }

    /* ---------- Table view ---------- */
    .data-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
    .data-table thead th { padding: 9px 10px; text-align: left; font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
    .data-table thead th.subhead { background: #eff6ff; color: #1e40af; text-align: center; font-size: 10px; border-bottom: 1px solid #c9d7ef; }
    .data-table thead th.amount-col { text-align: right; }
    .data-table thead th.action-col { text-align: center; width: 100px; }

    .data-table tbody tr { border-bottom: 1px solid #f1f5f9; transition: background 0.15s; }
    .data-table tbody tr:hover { background: #fafbfc; }
    .data-table tbody td { padding: 9px 10px; color: #1e293b; vertical-align: middle; }

    .data-table .acc-code { font-family: 'SF Mono', monospace; font-size: 11.5px; font-weight: 600; color: #475569; width: 80px; }
    .data-table .acc-name { font-size: 12.5px; font-weight: 500; color: #0f172a; }
    .data-table .acc-name a { color: #0f172a; text-decoration: none; border-bottom: 1px dashed transparent; transition: all 0.15s; }
    .data-table .acc-name a:hover { color: #2563eb; border-bottom-color: #2563eb; }
    .data-table .acc-name .contra-badge { display: inline-block; margin-left: 6px; padding: 1px 7px; background: #fef3c7; color: #92400e; border-radius: 4px; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; vertical-align: middle; }
    
    .data-table .amount-col { text-align: right; font-family: 'SF Mono', monospace; font-size: 12px; font-weight: 600; font-variant-numeric: tabular-nums; white-space: nowrap; width: 100px; }
    .data-table .amount-col.zero { color: #cbd5e1; font-weight: 400; }
    .data-table .amount-col.open  { color: #475569; }
    .data-table .amount-col.mv    { color: #2563eb; }
    .data-table .amount-col.close { color: #047857; }

    .data-table .action-col { text-align: center; }
    .data-table .action-col .row-action { display: inline-flex; align-items: center; justify-content: center; gap: 5px; padding: 4px 10px; border-radius: 6px; background: #eff6ff; color: #1e40af; text-decoration: none; transition: all 0.15s; border: 1px solid #bfdbfe; font-size: 11px; font-weight: 600; white-space: nowrap; }
    .data-table .action-col .row-action:hover { background: #2563eb; color: #fff; border-color: #2563eb; text-decoration: none; }

    /* ---------- Card view ---------- */
    .cards-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; padding: 16px 20px; }
    .item-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 9px; padding: 14px 16px; display: flex; flex-direction: column; gap: 8px; transition: all 0.15s; position: relative; }
    .item-card:hover { border-color: #bfdbfe; box-shadow: 0 4px 14px rgba(37,99,235,0.1); transform: translateY(-2px); }
    .item-card .card-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
    .item-card .card-code { font-family: 'SF Mono', monospace; font-size: 11px; font-weight: 700; color: #64748b; background: #f1f5f9; padding: 2px 8px; border-radius: 5px; }
    .item-card .card-name { font-size: 13.5px; font-weight: 600; color: #0f172a; line-height: 1.35; }
    .item-card .card-name .contra-badge { display: inline-block; margin-left: 6px; padding: 1px 6px; background: #fef3c7; color: #92400e; border-radius: 4px; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; vertical-align: middle; }
    
    .item-card .card-metrics { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; padding-top: 8px; border-top: 1px solid #f1f5f9; }
    .item-card .metric { text-align: center; }
    .item-card .metric .lbl { font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; color: #94a3b8; margin-bottom: 2px; }
    .item-card .metric .val { font-family: 'SF Mono', monospace; font-size: 12px; font-weight: 700; font-variant-numeric: tabular-nums; color: #0f172a; }
    .item-card .metric .val.open  { color: #475569; }
    .item-card .metric .val.mv    { color: #2563eb; }
    .item-card .metric .val.close { color: #047857; }
    .item-card .metric .val.zero  { color: #cbd5e1; font-weight: 400; }
    
    .item-card .card-action { display: inline-flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 600; color: #2563eb; text-decoration: none; padding-top: 8px; border-top: 1px solid #f1f5f9; margin-top: 4px; }
    .item-card .card-action:hover { color: #1d4ed8; text-decoration: none; }

    /* ---------- Bottom Summary Card ---------- */
    .tb-bottom { border-radius: 10px; padding: 20px 24px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); background: linear-gradient(135deg, #1e293b 0%, #334155 100%); color: #fff; }
    .tb-bottom.balanced { background: linear-gradient(135deg, #064e3b 0%, #065f46 100%); box-shadow: 0 4px 12px rgba(6,78,59,0.25); }
    .tb-bottom.unbalanced { background: linear-gradient(135deg, #7f1d1d 0%, #991b1b 100%); box-shadow: 0 4px 12px rgba(127,29,29,0.25); }
    .tb-bottom .left .label { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: rgba(255,255,255,0.75); margin-bottom: 6px; }
    .tb-bottom .left .value { font-size: 28px; font-weight: 800; font-family: 'SF Mono', monospace; font-variant-numeric: tabular-nums; letter-spacing: -0.5px; line-height: 1; }
    .tb-bottom .right { text-align: right; }
    .tb-bottom .right .sub-label { font-size: 9.5px; text-transform: uppercase; letter-spacing: 1px; color: rgba(255,255,255,0.7); font-weight: 700; margin-bottom: 2px; }
    .tb-bottom .right .sub-val { font-size: 14px; font-weight: 700; font-family: 'SF Mono', monospace; color: #fff; }

    /* ---------- Empty / Filter ---------- */
    .empty-state { padding: 40px 20px; text-align: center; color: #94a3b8; font-size: 12.5px; font-style: italic; }
    .hidden-column { display: none !important; }
    .filtered-out { display: none !important; }

    /* ============================================================
       PRINT — Excel-style, single page, no school name/logo
       ============================================================ */
    @media print {
        @page { size: A4 landscape; margin: 10mm 8mm; }
        body { background: #fff; color: #000; font-size: 9pt; font-family: Calibri, Arial, sans-serif; padding-bottom: 0; }
        .report-wrap { max-width: 100%; margin: 0; padding: 0; }
        
        .toolbar, .view-toggle, .search-box, .dropdown-columns, .btn-tool, .report-head .company, .cards-grid, .col-action, .section-header .badge-count, .summary-strip, .navbar { display: none !important; }
        
        .report-head { background: #fff !important; color: #000 !important; padding: 0 0 4pt 0; border-radius: 0; box-shadow: none; border-bottom: 2pt solid #000; margin-bottom: 6pt; text-align: center; display: block; }
        .report-head h1 { font-size: 13pt; font-weight: bold; margin: 0; letter-spacing: 0; }
        .report-head .period { font-size: 8pt; opacity: 1; color: #333 !important; margin-top: 2pt; font-style: italic; display: block; }
        .report-head .period span { display: block; }
        
        .section-card { box-shadow: none; border-radius: 0; border: none; margin-bottom: 4pt; page-break-inside: avoid; }
        .section-header { background: #e8e8e8 !important; padding: 3pt 5pt !important; border: 0.5pt solid #808080; border-bottom: none; }
        .section-header .title { font-size: 9pt; font-weight: bold; letter-spacing: 0; text-transform: none; color: #000; gap: 0; }
        .section-header .title .fa-solid { display: none !important; }
        .section-header .badge-count { display: none !important; }
        
        .data-table { font-size: 7.5pt; border-collapse: collapse; border: 0.5pt solid #808080; width: 100%; }
        .data-table thead th { background: #e8e8e8 !important; color: #000 !important; font-size: 7pt; font-weight: bold; text-transform: none; letter-spacing: 0; padding: 2pt 4pt !important; border: 0.5pt solid #808080; text-align: left; }
        .data-table thead th.subhead { background: #dcdcdc !important; text-align: center; font-size: 7pt; }
        .data-table thead th.amount-col { text-align: right; }
        
        .data-table tbody td { padding: 1.5pt 4pt !important; border: 0.5pt solid #c0c0c0; font-size: 7.5pt; color: #000; }
        .data-table .acc-code, .data-table .acc-name, .data-table .acc-name a { font-family: Calibri, Arial, sans-serif; font-size: 7.5pt; font-weight: normal; color: #000; text-decoration: none; }
        .data-table .acc-name .contra-badge { background: none; color: #000; border: none; padding: 0; font-size: 7pt; font-style: italic; }
        .data-table .amount-col { font-family: Calibri, Arial, sans-serif; font-size: 7.5pt; font-weight: normal; color: #000 !important; text-align: right; }
        .data-table .amount-col.zero { color: #aaa !important; }
        
        .tb-bottom { background: #fff !important; color: #000 !important; border-radius: 0; box-shadow: none; padding: 0 !important; margin-bottom: 0 !important; border: 0.5pt solid #808080; border-top: 2pt solid #000; display: block !important; margin-top: 10pt !important; page-break-inside: avoid; }
        .tb-bottom .left { display: flex !important; justify-content: space-between; align-items: baseline; padding: 5pt 8pt !important; background: #e8e8e8 !important; border-bottom: 0.5pt solid #808080; }
        .tb-bottom .left .label { font-size: 10pt; font-weight: bold; color: #000 !important; text-transform: none; letter-spacing: 0; margin: 0; opacity: 1; }
        .tb-bottom .left .value { font-size: 11pt; font-weight: bold; font-family: Calibri, Arial, sans-serif; letter-spacing: 0; color: #000 !important; }
        .tb-bottom .right { display: none !important; }
        
        .report-wrap > div[style*="text-align: center"] { font-size: 7pt; color: #666 !important; padding: 6pt 0 0 0 !important; text-align: center; }
        
        .data-table tr { page-break-inside: avoid; }
        .data-table thead { display: table-header-group; }
        .data-table tfoot { display: table-footer-group; }
        html, body { height: auto !important; }
    }

    @media (max-width: 900px) {
        .report-head { padding: 16px 20px; }
        .toolbar { padding: 12px 16px; }
        .toolbar-row { gap: 8px; }
        .btn-tool { font-size: 11.5px; padding: 0 10px; height: 34px; }
        .summary-strip { gap: 10px; }
        .summary-tile { padding: 12px 14px; }
        .summary-tile .value { font-size: 18px; }
        .data-table { font-size: 11.5px; }
        .data-table thead th { padding: 6px 6px; font-size: 9px; }
        .data-table tbody td { padding: 6px 6px; }
        .cards-grid { grid-template-columns: 1fr; padding: 12px; }
        .tb-bottom { padding: 16px; }
        .tb-bottom .left .value { font-size: 22px; }
        .search-group { max-width: 100%; width: 100%; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="report-wrap">

    <!-- Header -->
    <div class="report-head">
        <div>
            <h1><i class="fa-solid fa-scale-balanced"></i> Trial Balance</h1>
            <div class="period">
                <span><strong>Period:</strong> <?php echo date('F j, Y', strtotime($fromDate)); ?> — <?php echo date('F j, Y', strtotime($toDate)); ?></span>
                <span><strong>As of:</strong> <?php echo date('F j, Y', strtotime($toDate)); ?></span>
            </div>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
        <div class="toolbar-row">
            <form method="get" action="" style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <div class="date-group">
                    <label for="from">From</label>
                    <input type="date" id="from" name="from" value="<?php echo htmlspecialchars($fromDate); ?>">
                </div>
                <div class="date-group">
                    <label for="to">To</label>
                    <input type="date" id="to" name="to" value="<?php echo htmlspecialchars($toDate); ?>">
                </div>
                <button type="submit" class="btn-tool primary">
                    <i class="fa-solid fa-rotate-right"></i> Update
                </button>
            </form>
            <span class="spacer"></span>

            <div class="view-toggle" id="viewToggle">
                <button type="button" class="active" data-view="table">
                    <i class="fa-solid fa-list"></i> Table
                </button>
                <button type="button" data-view="card">
                    <i class="fa-solid fa-table-cells-large"></i> Cards
                </button>
            </div>
        </div>

        <div class="toolbar-row">
            <div class="search-group">
                <div class="search-box">
                    
                    <input type="text" id="searchInput" placeholder="Search accounts..." autocomplete="off">
                    <i class="fa-solid fa-circle-xmark clear-search" id="clearSearch"></i>
                </div>

                <div class="dropdown-columns" id="columnsDropdown">
                    <button type="button" class="btn-tool" id="columnsBtn">
                        <i class="fa-solid fa-table-columns"></i> Columns
                        <i class="fa-solid fa-caret-down" style="font-size: 10px; margin-left: 2px;"></i>
                    </button>
                    <div class="columns-menu">
                        <label><input type="checkbox" class="col-toggle" data-col="col-code" checked> Code</label>
                        <label><input type="checkbox" class="col-toggle" data-col="col-name" checked> Account Name</label>
                        <label><input type="checkbox" class="col-toggle" data-col="col-opening" checked> Opening Balance</label>
                        <label><input type="checkbox" class="col-toggle" data-col="col-movement" checked> Period Movement</label>
                        <label><input type="checkbox" class="col-toggle" data-col="col-closing" checked> Closing Balance</label>
                        <label><input type="checkbox" class="col-toggle" data-col="col-action" checked> Action</label>
                    </div>
                </div>
            </div>

            <span class="spacer"></span>

            <button type="button" class="btn-tool export-copy" onclick="copyToClipboard()">
                <i class="fa-solid fa-copy"></i> Copy
            </button>
            <button type="button" class="btn-tool export-csv" onclick="exportCSV()">
                <i class="fa-solid fa-file-csv"></i> CSV
            </button>
            <button type="button" class="btn-tool export-excel" onclick="exportExcel()">
                <i class="fa-solid fa-file-excel"></i> Excel
            </button>
            <button type="button" class="btn-tool export-pdf" onclick="window.print()">
                <i class="fa-solid fa-print"></i> Print
            </button>
        </div>
    </div>

    <!-- Summary tiles -->
    <div class="summary-strip">
        <div class="summary-tile opening">
            <div class="label">Opening Balance (As of <?php echo date('M j', strtotime($fromDate . ' -1 day')); ?>)</div>
            <div class="value opening-val"><?php echo number_format($totalOpenDr, 2); ?></div>
            <div class="sub">Total debit-side balances</div>
        </div>
        <div class="summary-tile range">
            <div class="label">Period Movement</div>
            <div class="value range-val"><?php echo number_format($totalRangeDr, 2); ?></div>
            <div class="sub">Total debits posted in range</div>
        </div>
        <div class="summary-tile <?php echo $balancedClose ? 'closing' : 'bad'; ?>">
            <div class="label"><?php echo $balancedClose ? 'Closing Balanced ✓' : 'Closing Out of Balance ✗'; ?></div>
            <div class="value <?php echo $balancedClose ? 'closing-val' : 'bad-val'; ?>">
                <?php echo number_format($totalCloseDr, 2); ?>
            </div>
            <div class="sub">
                <?php if ($balancedClose): ?>
                    Debits = Credits
                <?php else: ?>
                    Off by <?php echo number_format(abs($totalCloseDr - $totalCloseCr), 2); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Sections -->
    <?php if (empty($sections)): ?>
        <div class="section-card">
            <div class="empty-state">No activity in this period.</div>
        </div>
    <?php else: ?>

        <?php foreach ($sections as $secIdx => $sec): ?>
            <div class="section-card">
                <div class="section-header">
                    <div class="title">
                        <i class="fa-solid fa-tag"></i>
                        <?php echo htmlspecialchars($sec['label']); ?>
                        <span class="badge-count"><?php echo count($sec['rows']); ?></span>
                    </div>
                </div>

                <!-- Table view -->
                <table class="data-table tb-section-table" id="section-table-<?php echo $secIdx; ?>">
                    <thead>
                        <tr>
                            <th class="col-code" rowspan="2" style="width: 80px;">Code</th>
                            <th class="col-name" rowspan="2">Account</th>
                            <th class="col-opening subhead" colspan="2">Opening Balance</th>
                            <th class="col-movement subhead" colspan="2">Period Movement</th>
                            <th class="col-closing subhead" colspan="2">Closing Balance</th>
                            <th class="col-action action-col" rowspan="2">Open</th>
                        </tr>
                        <tr>
                            <th class="col-opening amount-col">Debit</th>
                            <th class="col-opening amount-col">Credit</th>
                            <th class="col-movement amount-col">Debit</th>
                            <th class="col-movement amount-col">Credit</th>
                            <th class="col-closing amount-col">Debit</th>
                            <th class="col-closing amount-col">Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sec['rows'] as $r): ?>
                            <tr class="data-row" data-search="<?php echo htmlspecialchars(strtolower($r['code'] . ' ' . $r['name'])); ?>">
                                <td class="acc-code col-code"><?php echo htmlspecialchars($r['code']); ?></td>
                                <td class="acc-name col-name">
                                    <a href="gl_account_ledger.php?id=<?php echo (int)$r['id']; ?>&from=<?php echo urlencode($fromDate); ?>&to=<?php echo urlencode($toDate); ?>">
                                        <?php echo htmlspecialchars($r['name']); ?>
                                    </a>
                                    <?php if (!empty($r['is_contra'])): ?>
                                        <span class="contra-badge">Contra</span>
                                    <?php endif; ?>
                                </td>
                                <td class="amount-col open col-opening <?php echo $r['open_dr'] == 0 ? 'zero' : ''; ?>"><?php echo $r['open_dr'] == 0 ? '—' : number_format($r['open_dr'], 2); ?></td>
                                <td class="amount-col open col-opening <?php echo $r['open_cr'] == 0 ? 'zero' : ''; ?>"><?php echo $r['open_cr'] == 0 ? '—' : number_format($r['open_cr'], 2); ?></td>
                                <td class="amount-col mv col-movement <?php echo $r['mv_dr'] == 0 ? 'zero' : ''; ?>"><?php echo $r['mv_dr'] == 0 ? '—' : number_format($r['mv_dr'], 2); ?></td>
                                <td class="amount-col mv col-movement <?php echo $r['mv_cr'] == 0 ? 'zero' : ''; ?>"><?php echo $r['mv_cr'] == 0 ? '—' : number_format($r['mv_cr'], 2); ?></td>
                                <td class="amount-col close col-closing <?php echo $r['close_dr'] == 0 ? 'zero' : ''; ?>"><?php echo $r['close_dr'] == 0 ? '—' : number_format($r['close_dr'], 2); ?></td>
                                <td class="amount-col close col-closing <?php echo $r['close_cr'] == 0 ? 'zero' : ''; ?>"><?php echo $r['close_cr'] == 0 ? '—' : number_format($r['close_cr'], 2); ?></td>
                                <td class="action-col col-action">
                                    <a href="gl_account_ledger.php?id=<?php echo (int)$r['id']; ?>&from=<?php echo urlencode($fromDate); ?>&to=<?php echo urlencode($toDate); ?>"
                                       class="row-action" title="Open ledger">
                                        <i class="fa-solid fa-arrow-up-right-from-square"></i> Ledger
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Card view -->
                <div class="cards-grid" id="section-cards-<?php echo $secIdx; ?>" style="display: none;">
                    <?php foreach ($sec['rows'] as $r): ?>
                        <div class="item-card data-card"
                             data-search="<?php echo htmlspecialchars(strtolower($r['code'] . ' ' . $r['name'])); ?>">
                            <div class="card-top">
                                <span class="card-code"><?php echo htmlspecialchars($r['code']); ?></span>
                            </div>
                            <div class="card-name">
                                <?php echo htmlspecialchars($r['name']); ?>
                                <?php if (!empty($r['is_contra'])): ?>
                                    <span class="contra-badge">Contra</span>
                                <?php endif; ?>
                            </div>
                            <div class="card-metrics">
                                <div class="metric">
                                    <div class="lbl">Opening</div>
                                    <div class="val open <?php echo ($r['open_dr'] == 0 && $r['open_cr'] == 0) ? 'zero' : ''; ?>">
                                        <?php
                                        if ($r['open_dr'] > 0) echo number_format($r['open_dr'], 2) . ' Dr';
                                        elseif ($r['open_cr'] > 0) echo number_format($r['open_cr'], 2) . ' Cr';
                                        else echo '—';
                                        ?>
                                    </div>
                                </div>
                                <div class="metric">
                                    <div class="lbl">Movement</div>
                                    <div class="val mv <?php echo ($r['mv_dr'] == 0 && $r['mv_cr'] == 0) ? 'zero' : ''; ?>">
                                        <?php
                                        if ($r['mv_dr'] > 0) echo number_format($r['mv_dr'], 2) . ' Dr';
                                        elseif ($r['mv_cr'] > 0) echo number_format($r['mv_cr'], 2) . ' Cr';
                                        else echo '—';
                                        ?>
                                    </div>
                                </div>
                                <div class="metric">
                                    <div class="lbl">Closing</div>
                                    <div class="val close <?php echo ($r['close_dr'] == 0 && $r['close_cr'] == 0) ? 'zero' : ''; ?>">
                                        <?php
                                        if ($r['close_dr'] > 0) echo number_format($r['close_dr'], 2) . ' Dr';
                                        elseif ($r['close_cr'] > 0) echo number_format($r['close_cr'], 2) . ' Cr';
                                        else echo '—';
                                        ?>
                                    </div>
                                </div>
                            </div>
                            <a href="gl_account_ledger.php?id=<?php echo (int)$r['id']; ?>&from=<?php echo urlencode($fromDate); ?>&to=<?php echo urlencode($toDate); ?>"
                               class="card-action">
                                <i class="fa-solid fa-arrow-up-right-from-square"></i> Ledger
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- Grand Total -->
        <div class="section-card">
            <div class="section-header">
                <div class="title">
                    <i class="fa-solid fa-chart-bar"></i>
                    Grand Total
                </div>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th rowspan="2">Category</th>
                        <th class="subhead" colspan="2">Opening</th>
                        <th class="subhead" colspan="2">Movement</th>
                        <th class="subhead" colspan="2">Closing</th>
                    </tr>
                    <tr>
                        <th class="amount-col">Debit</th>
                        <th class="amount-col">Credit</th>
                        <th class="amount-col">Debit</th>
                        <th class="amount-col">Credit</th>
                        <th class="amount-col">Debit</th>
                        <th class="amount-col">Credit</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="data-row" style="background: #f0f5ff; font-weight: 700;">
                        <td style="font-size: 13px;">TOTAL</td>
                        <td class="amount-col"><?php echo number_format($totalOpenDr, 2); ?></td>
                        <td class="amount-col"><?php echo number_format($totalOpenCr, 2); ?></td>
                        <td class="amount-col"><?php echo number_format($totalRangeDr, 2); ?></td>
                        <td class="amount-col"><?php echo number_format($totalRangeCr, 2); ?></td>
                        <td class="amount-col"><?php echo number_format($totalCloseDr, 2); ?></td>
                        <td class="amount-col"><?php echo number_format($totalCloseCr, 2); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

    <?php endif; ?>

    <!-- Bottom Summary -->
    <div class="tb-bottom <?php echo $balancedClose ? 'balanced' : 'unbalanced'; ?>">
        <div class="left">
            <div class="label"><?php echo $balancedClose ? 'Closing Balanced' : 'Closing Out of Balance'; ?></div>
            <div class="value">
                <?php
                if ($balancedClose) {
                    echo number_format($totalCloseDr, 2);
                } else {
                    echo number_format(abs($totalCloseDr - $totalCloseCr), 2);
                }
                ?>
            </div>
        </div>
        <div class="right">
            <div class="sub-label">Total Debits</div>
            <div class="sub-val"><?php echo number_format($totalCloseDr, 2); ?></div>
            <div class="sub-label" style="margin-top: 8px;">Total Credits</div>
            <div class="sub-val"><?php echo number_format($totalCloseCr, 2); ?></div>
        </div>
    </div>

    <div style="text-align: center; padding: 16px 0; font-size: 11.5px; color: #94a3b8;">
        <strong>Opening + Period Movement = Closing</strong> · Debits must equal credits at every column.
    </div>

</div>

<script>
jQuery(document).ready(function($) {

    /* ---- View toggle ---- */
    $('#viewToggle button').on('click', function() {
        $('#viewToggle button').removeClass('active');
        $(this).addClass('active');
        var view = $(this).data('view');

        if (view === 'card') {
            $('.tb-section-table').hide();
            $('.cards-grid').show();
        } else {
            $('.tb-section-table').show();
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
    jQuery('.section-card').each(function() {
        var sectionTitle = jQuery(this).find('.section-header .title').first().clone();
        sectionTitle.find('.badge-count').remove();
        sectionTitle.find('.fa-solid').remove();
        var sectionName = sectionTitle.text().trim();

        if (!sectionName || sectionName.indexOf('Grand') !== -1) return;

        jQuery(this).find('tbody tr.data-row').each(function() {
            var code = jQuery(this).find('.col-code').text().trim();
            var name = jQuery(this).find('.col-name').text().trim();

            if (!code || !name) return;

            var cells = jQuery(this).find('td.amount-col');
            if (cells.length < 6) return;

            var openDr = cells.eq(0).text().trim();
            var openCr = cells.eq(1).text().trim();
            var mvDr   = cells.eq(2).text().trim();
            var mvCr   = cells.eq(3).text().trim();
            var closeDr = cells.eq(4).text().trim();
            var closeCr = cells.eq(5).text().trim();

            rows.push({
                section: sectionName,
                code: code,
                name: name,
                openDrText: openDr, openDrNum: parseAmount(openDr),
                openCrText: openCr, openCrNum: parseAmount(openCr),
                mvDrText:   mvDr,   mvDrNum:   parseAmount(mvDr),
                mvCrText:   mvCr,   mvCrNum:   parseAmount(mvCr),
                closeDrText: closeDr, closeDrNum: parseAmount(closeDr),
                closeCrText: closeCr, closeCrNum: parseAmount(closeCr),
            });
        });
    });
    return rows;
}

function parseAmount(text) {
    if (!text || text === '—') return 0;
    var isNegative = text.indexOf('(') !== -1 && text.indexOf(')') !== -1;
    var cleaned = text.replace(/[(),\s]/g, '');
    var num = parseFloat(cleaned) || 0;
    return isNegative ? -num : num;
}

/* ---------- Copy ---------- */

function copyToClipboard() {
    var rows = buildReportData();
    var headers = ['Section', 'Code', 'Account', 'Opening Dr', 'Opening Cr', 'Movement Dr', 'Movement Cr', 'Closing Dr', 'Closing Cr'];
    var lines = [headers.join('\t')];
    rows.forEach(function(r) {
        lines.push([
            r.section, r.code, r.name,
            r.openDrText, r.openCrText,
            r.mvDrText, r.mvCrText,
            r.closeDrText, r.closeCrText
        ].join('\t'));
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
        alert('Copy failed.');
    }
    document.body.removeChild(textarea);
}

/* ---------- CSV ---------- */

function exportCSV() {
    var rows = buildReportData();
    var csv = 'Section,Code,Account,Opening Dr,Opening Cr,Movement Dr,Movement Cr,Closing Dr,Closing Cr\n';
    rows.forEach(function(r) {
        csv += '"' + r.section + '","' + r.code + '","' +
               r.name.replace(/"/g, '""') + '",' +
               r.openDrNum + ',' + r.openCrNum + ',' +
               r.mvDrNum   + ',' + r.mvCrNum   + ',' +
               r.closeDrNum + ',' + r.closeCrNum + '\n';
    });

    var blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
    var link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'trial_balance_' + new Date().toISOString().slice(0, 10) + '.csv';
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
    var data = [['Section', 'Code', 'Account', 'Opening Dr', 'Opening Cr', 'Movement Dr', 'Movement Cr', 'Closing Dr', 'Closing Cr']];
    rows.forEach(function(r) {
        data.push([
            r.section, r.code, r.name,
            r.openDrNum, r.openCrNum,
            r.mvDrNum, r.mvCrNum,
            r.closeDrNum, r.closeCrNum
        ]);
    });

    var ws = XLSX.utils.aoa_to_sheet(data);

    ws['!cols'] = [
        { wch: 14 }, { wch: 10 }, { wch: 40 },
        { wch: 14 }, { wch: 14 }, { wch: 14 }, { wch: 14 }, { wch: 14 }, { wch: 14 },
    ];

    var wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Trial Balance');

    var dateStr = new Date().toISOString().slice(0, 10);
    XLSX.writeFile(wb, 'trial_balance_' + dateStr + '.xlsx');
    showToast('Excel file downloaded');
}

/* ---------- Toast ---------- */

function showToast(msg) {
    var $t = jQuery('<div style="position:fixed;bottom:24px;right:24px;background:#1e293b;color:#fff;padding:12px 20px;border-radius:8px;font-size:13px;font-weight:600;box-shadow:0 8px 24px rgba(0,0,0,0.15);z-index:9999;"><i class="fa-solid fa-check-circle" style="margin-right:8px;color:#4ade80;"></i>' + msg + '</div>');
    jQuery('body').append($t);
    setTimeout(function() {
        $t.fadeOut(300, function() { jQuery(this).remove(); });
    }, 2200);
}
</script>

</body>
</html>
<?php $conn->close(); ?>