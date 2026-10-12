<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
/* ==== AUTHORIZATION GATE ==== */
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
/* ==== END AUTHORIZATION GATE ==== */
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

$budgetId = (int)($_GET['id'] ?? 0);
if ($budgetId <= 0) { header('Location: gl_budget.php'); exit; }

$budget = $conn->query("
    SELECT b.*, s.title AS session_title, s.from_dated, s.to_dated
    FROM gl_budgets b
    LEFT JOIN sessions s ON s.id = b.session_id
    WHERE b.id = $budgetId
    LIMIT 1
")->fetch_assoc();
if (!$budget) { http_response_code(404); die('Budget not found.'); }

$months = gl_budget_month_boundaries($budget);
$budgetMap = gl_budget_lines_map($conn, $budgetId);
$accounts = gl_list_accounts($conn, null, true);
$accounts = array_values(array_filter($accounts, fn($a) => in_array($a['account_type'], ['REVENUE','EXPENSE'], true)));

/* Fetch actuals for every month in one go */
$actuals = [];   // [account_id][month_index] = amount
foreach ($months as $m) {
    $mActuals = gl_budget_actuals_for_month($conn, $m['start'], $m['end']);
    foreach ($mActuals as $accId => $amt) {
        $actuals[$accId][$m['index']] = $amt;
    }
}

/* Filters */
$filterMonth = (int)($_GET['month'] ?? 0);   // 0 = all
$filterType  = $_GET['type']  ?? '';          // '' = all, REVENUE, EXPENSE
$onlyOver    = isset($_GET['over']);
$view        = $_GET['view']  ?? 'table';     // table | card

/* Build rows */
$rows = [];
$sums = ['budget' => 0, 'actual' => 0];

foreach ($accounts as $acc) {
    if ($filterType && $acc['account_type'] !== $filterType) continue;
    $accId = (int)$acc['id'];

    $budgetAmt = 0.0;
    $actualAmt = 0.0;

    if ($filterMonth > 0) {
        $budgetAmt = $budgetMap[$accId][$filterMonth] ?? 0;
        $actualAmt = $actuals[$accId][$filterMonth] ?? 0;
    } else {
        for ($i = 1; $i <= 12; $i++) {
            $budgetAmt += $budgetMap[$accId][$i] ?? 0;
            $actualAmt += $actuals[$accId][$i] ?? 0;
        }
    }

    if (abs($budgetAmt) < 0.005 && abs($actualAmt) < 0.005) continue;

    $variance = $budgetAmt - $actualAmt;

    // Determine favourable/unfavourable
    // Revenue: favourable if actual >= budget (positive variance)
    // Expense: favourable if actual <= budget (positive variance)
    $favourable = ($variance >= 0);
    $usedPct    = $budgetAmt != 0 ? ($actualAmt / $budgetAmt) * 100 : ($actualAmt != 0 ? 999 : 0);

    $status = 'ok';
    if ($budgetAmt == 0 && $actualAmt != 0)       $status = 'unbudgeted';
    elseif ($usedPct > 100)                       $status = 'over';
    elseif ($usedPct >= 85)                       $status = 'warn';
    elseif ($usedPct >= 0)                        $status = 'under';

    if ($onlyOver && $status !== 'over' && $status !== 'unbudgeted') continue;

    $rows[] = [
        'account'    => $acc,
        'budget'     => $budgetAmt,
        'actual'     => $actualAmt,
        'variance'   => $variance,
        'used_pct'   => $usedPct,
        'status'     => $status,
        'favourable' => $favourable,
    ];

    $sums['budget'] += $budgetAmt;
    $sums['actual'] += $actualAmt;
}

$sumVariance = $sums['budget'] - $sums['actual'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Budget vs Actual — <?= htmlspecialchars($budget['title']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<style>
    * { box-sizing: border-box; }
    body { background: #eef1f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 0; color: #1e293b; }
    .wrap { max-width: 1500px; margin: 30px auto; padding: 0 20px; }

    .head {
        background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
        color: #fff; padding: 30px 40px 26px 40px; border-radius: 12px 12px 0 0;
        box-shadow: 0 4px 16px rgba(30,64,175,0.15);
        display: flex; justify-content: space-between; align-items: flex-end;
        gap: 20px; flex-wrap: wrap;
    }
    .head h1 { margin: 0; font-size: 28px; font-weight: 700; }
    .head .sub { font-size: 14px; opacity: 0.9; margin-top: 6px; }

    .btn-white {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 10px 18px; background: #ffffff; color: #1e40af;
        border: 1px solid #ffffff; border-radius: 8px;
        text-decoration: none; font-size: 14px; font-weight: 600;
        line-height: 1; font-family: inherit; cursor: pointer;
    }
    .btn-white:hover { background: #dbeafe; border-color: #dbeafe; color: #1e40af; text-decoration: none; }

    .card { background: #fff; padding: 30px 40px 40px 40px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

    .toolbar {
        display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;
        margin-bottom: 24px; padding-bottom: 20px;
        border-bottom: 1px solid #e2e8f0;
    }
    .field-inline { display: flex; flex-direction: column; gap: 5px; }
    .field-inline label { font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 1px; }
    .field-inline select, .field-inline input {
        height: 40px; padding: 8px 12px;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 14px; font-family: inherit; background: #fff;
    }
    .btn {
        display: inline-flex; align-items: center; gap: 8px;
        height: 40px; padding: 0 18px;
        border-radius: 8px;
        font-size: 13px; font-weight: 600; font-family: inherit;
        cursor: pointer; line-height: 1;
        text-decoration: none; border: 1px solid;
    }
    .btn-primary { background: #1e40af; color: #fff; border-color: #1e40af; }
    .btn-primary:hover { background: #1e3a8a; border-color: #1e3a8a; color: #fff; text-decoration: none; }
    .btn-ghost { background: #f1f5f9; color: #334155; border-color: #e2e8f0; }
    .btn-ghost:hover { background: #e2e8f0; color: #1e293b; text-decoration: none; }

    /* Hero */
    .hero-bar {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(200px,1fr));
        gap: 14px; margin-bottom: 24px;
    }
    .hero-box {
        background: #f8fafc; border: 1px solid #e2e8f0;
        border-radius: 10px; padding: 18px 20px; border-left: 5px solid;
    }
    .hero-box .lbl { font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
    .hero-box .val { font-family: 'SF Mono','Monaco',monospace; font-size: 22px; font-weight: 800; color: #0f172a; }
    .hero-box.s-budget { border-color: #1e40af; }
    .hero-box.s-actual { border-color: #b45309; }
    .hero-box.s-var    { border-color: #64748b; }

    /* Status pills */
    .pill { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
    .pill.ok         { background: #d1fae5; color: #065f46; }
    .pill.warn       { background: #fef3c7; color: #92400e; }
    .pill.over       { background: #fee2e2; color: #991b1b; }
    .pill.unbudgeted { background: #ede9fe; color: #6d28d9; }

    /* Table */
    table.tbl { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; }
    table.tbl thead th {
        background: #f8fafc; font-size: 11px; color: #64748b; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px;
        padding: 12px; text-align: left;
        border-bottom: 2px solid #e2e8f0;
    }
    table.tbl tbody td { padding: 11px 12px; font-size: 13px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    table.tbl tbody tr:hover td { background: #f8fafc; }
    table.tbl tfoot td { padding: 12px; font-weight: 700; background: #eff6ff; border-top: 2px solid #1e40af; font-size: 13px; }
    .num { font-family: 'SF Mono','Monaco',monospace; text-align: right; font-variant-numeric: tabular-nums; }

    .var-pos { color: #047857; font-weight: 700; }
    .var-neg { color: #b91c1c; font-weight: 700; }

    /* Progress bar */
    .bar-wrap { width: 100px; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden; display: inline-block; vertical-align: middle; }
    .bar-fill { height: 100%; }
    .bar-fill.ok         { background: #059669; }
    .bar-fill.warn       { background: #f59e0b; }
    .bar-fill.over       { background: #dc2626; }
    .bar-fill.unbudgeted { background: #6d28d9; }
    .bar-label { font-size: 11px; color: #64748b; margin-left: 6px; font-family: 'SF Mono',monospace; }

    /* Card view */
    .card-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 14px; }
    .acct-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px 20px; border-left: 5px solid; }
    .acct-card.ok         { border-color: #059669; }
    .acct-card.warn       { border-color: #f59e0b; }
    .acct-card.over       { border-color: #dc2626; }
    .acct-card.unbudgeted { border-color: #6d28d9; }
    .acct-card .code-name { font-weight: 700; color: #0f172a; font-size: 14px; margin-bottom: 4px; }
    .acct-card .code-name small { color: #94a3b8; font-family: 'SF Mono',monospace; margin-right: 6px; }
    .acct-card .row2 { display: flex; justify-content: space-between; margin-top: 12px; font-size: 12px; }
    .acct-card .row2 .lbl { color: #64748b; }
    .acct-card .row2 .val { font-family: 'SF Mono',monospace; font-weight: 700; }
    .acct-card .pct-bar { margin-top: 12px; }

    .empty-state { padding: 60px; text-align: center; color: #94a3b8; font-style: italic; font-size: 14px; }

    @media print {
        @page { size: A4 landscape; margin: 10mm; }
        .no-print { display: none !important; }
        body { background: #fff; }
        .head { background: #fff !important; color: #000 !important; border-bottom: 2px solid #000; box-shadow: none; }
        .head h1 { color: #000; font-size: 16pt; }
        .head .sub { color: #333; }
        .card { box-shadow: none; padding: 0; }
        table.tbl thead th { background: #eee; }
        .hero-box { border: 1px solid #999; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <h1><?= htmlspecialchars($budget['title']) ?> — Budget vs Actual</h1>
            <div class="sub">
                <?= htmlspecialchars($budget['session_title']) ?>
                &middot; <?= htmlspecialchars($budget['from_dated']) ?> → <?= htmlspecialchars($budget['to_dated']) ?>
                &middot; Status: <strong><?= htmlspecialchars($budget['status']) ?></strong>
                &middot; Showing: <strong><?= $filterMonth > 0 ? htmlspecialchars($months[$filterMonth-1]['label']) : 'Whole session' ?></strong>
            </div>
        </div>
        <a href="gl_budget.php" class="btn-white no-print">
            <span class="glyphicon glyphicon-arrow-left"></span> All Budgets
        </a>
    </div>

    <div class="card">

        <form method="get" class="toolbar no-print">
            <input type="hidden" name="id" value="<?= (int)$budgetId ?>">

            <div class="field-inline">
                <label>Month</label>
                <select name="month">
                    <option value="0">Whole session</option>
                    <?php foreach ($months as $m): ?>
                        <option value="<?= (int)$m['index'] ?>" <?= $filterMonth === (int)$m['index'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($m['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field-inline">
                <label>Account type</label>
                <select name="type">
                    <option value="">All</option>
                    <option value="REVENUE" <?= $filterType === 'REVENUE' ? 'selected' : '' ?>>Revenue only</option>
                    <option value="EXPENSE" <?= $filterType === 'EXPENSE' ? 'selected' : '' ?>>Expense only</option>
                </select>
            </div>

            <label style="display:flex; align-items:center; gap:6px; font-size:13px; color:#475569; margin-top:18px;">
                <input type="checkbox" name="over" value="1" <?= $onlyOver ? 'checked' : '' ?>>
                Only overspends / unbudgeted
            </label>

            <button type="submit" class="btn btn-primary" style="margin-top:18px;">
                <span class="glyphicon glyphicon-filter"></span> Apply
            </button>
            <a href="gl_budget_report.php?id=<?= (int)$budgetId ?>" class="btn btn-ghost" style="margin-top:18px;">Reset</a>
            <button type="button" class="btn btn-ghost" onclick="window.print()" style="margin-top:18px;">
                <span class="glyphicon glyphicon-print"></span> Print
            </button>
            <button type="button" class="btn btn-ghost" onclick="exportExcel()" style="margin-top:18px;">
                <span class="glyphicon glyphicon-download-alt"></span> Excel
            </button>
            <button type="button" class="btn btn-ghost" onclick="exportCSV()" style="margin-top:18px;">
                <span class="glyphicon glyphicon-save"></span> CSV
            </button>
        </form>

        <div class="hero-bar">
            <div class="hero-box s-budget">
                <div class="lbl">Total Budget</div>
                <div class="val"><?= number_format($sums['budget'], 2) ?></div>
            </div>
            <div class="hero-box s-actual">
                <div class="lbl">Total Actual</div>
                <div class="val"><?= number_format($sums['actual'], 2) ?></div>
            </div>
            <div class="hero-box s-var">
                <div class="lbl">Variance</div>
                <div class="val" style="color:<?= $sumVariance >= 0 ? '#047857' : '#b91c1c' ?>;">
                    <?= number_format($sumVariance, 2) ?>
                </div>
            </div>
            <div class="hero-box" style="border-color:#0891b2;">
                <div class="lbl">Accounts</div>
                <div class="val"><?= count($rows) ?></div>
            </div>
        </div>

        <?php if (empty($rows)): ?>
            <div class="empty-state">No accounts match the current filters.</div>
        <?php else: ?>
            <table class="tbl" id="reportTable">
                <thead>
                    <tr>
                        <th style="width:100px;">Code</th>
                        <th>Account</th>
                        <th style="width:110px;">Type</th>
                        <th class="num" style="width:130px;">Budget</th>
                        <th class="num" style="width:130px;">Actual</th>
                        <th class="num" style="width:130px;">Variance</th>
                        <th style="width:180px;">Used</th>
                        <th style="width:120px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        $acc = $r['account'];
                        $variance = $r['variance'];
                        $varianceCls = $variance >= 0 ? 'var-pos' : 'var-neg';
                        $barPct = min(100, max(0, $r['used_pct']));
                    ?>
                        <tr>
                            <td><code style="color:#94a3b8;"><?= htmlspecialchars($acc['code']) ?></code></td>
                            <td><strong><?= htmlspecialchars($acc['name']) ?></strong></td>
                            <td>
                                <?php if ($acc['account_type'] === 'REVENUE'): ?>
                                    <span style="color:#1e40af; font-weight:700;">Revenue</span>
                                <?php else: ?>
                                    <span style="color:#dc2626; font-weight:700;">Expense</span>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= number_format($r['budget'], 2) ?></td>
                            <td class="num"><?= number_format($r['actual'], 2) ?></td>
                            <td class="num <?= $varianceCls ?>"><?= number_format($variance, 2) ?></td>
                            <td>
                                <span class="bar-wrap">
                                    <span class="bar-fill <?= $r['status'] ?>" style="width:<?= number_format($barPct, 0) ?>%;"></span>
                                </span>
                                <span class="bar-label"><?= number_format($r['used_pct'], 1) ?>%</span>
                            </td>
                            <td><span class="pill <?= $r['status'] ?>"><?= str_replace('unbudgeted','Unbudgeted',$r['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3">TOTAL</td>
                        <td class="num"><?= number_format($sums['budget'], 2) ?></td>
                        <td class="num"><?= number_format($sums['actual'], 2) ?></td>
                        <td class="num <?= $sumVariance >= 0 ? 'var-pos' : 'var-neg' ?>"><?= number_format($sumVariance, 2) ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>

    </div>
</div>

<script>
function exportCSV() {
    var rows = [];
    document.querySelectorAll('#reportTable tr').forEach(function(tr){
        var cells = tr.querySelectorAll('th,td');
        var row = [];
        cells.forEach(function(c){ row.push('"' + c.innerText.trim().replace(/"/g,'""') + '"'); });
        if (row.length) rows.push(row.join(','));
    });
    var blob = new Blob([rows.join('\n')], {type:'text/csv;charset=utf-8;'});
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'budget_actuals_<?= (int)$budgetId ?>.csv';
    a.click();
}
function exportExcel() {
    var tbl = document.getElementById('reportTable');
    var wb = XLSX.utils.table_to_book(tbl, { sheet: "Budget vs Actual" });
    XLSX.writeFile(wb, 'budget_actuals_<?= (int)$budgetId ?>.xlsx');
}
</script>

</body>
</html>