<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

// --- Resolve account ---
$account = null;
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $account = gl_get_account($conn, (int)$_GET['id']);
} elseif (isset($_GET['code']) && $_GET['code'] !== '') {
    $account = gl_get_account_by_code($conn, trim($_GET['code']));
}

// --- Load accounts for picker ---
$allAccounts = gl_list_accounts($conn, null, true);
$accountsByType = ['ASSET' => [], 'LIABILITY' => [], 'EQUITY' => [], 'REVENUE' => [], 'EXPENSE' => []];
foreach ($allAccounts as $a) {
    $accountsByType[$a['account_type']][] = $a;
}

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

// --- Data ---
$entries = [];
$opening = 0.0; $closing = 0.0;
$openingDisplay = 0.0; $closingDisplay = 0.0;
$isDebitNormal = true;
$limit = 20;
$isGrouping = false;
$children = []; $groupAggregate = 0.0;
$totalDr = 0.0; $totalCr = 0.0;

if ($account) {
    $isGrouping = ((int)$account['is_postable'] === 0);
    if (!$isGrouping) {
        $balances = gl_quick_ledger_balances($conn, (int)$account['id'], $fromDate, $toDate);
        $opening  = $balances['opening'];
        $closing  = $balances['closing'];
        $entries  = gl_quick_ledger($conn, (int)$account['id'], $fromDate, $toDate, $limit);

        $isContra = !empty($account['is_contra']);
        $isDebitNormal = in_array($account['account_type'], ['ASSET','EXPENSE'], true) || $isContra;
        $openingDisplay = $isDebitNormal ? $opening : -$opening;
        $closingDisplay = $isDebitNormal ? $closing : -$closing;

        foreach ($entries as $e) {
            $totalDr += (float)$e['debit'];
            $totalCr += (float)$e['credit'];
        }
    } else {
        $childStmt = $conn->prepare("
            SELECT id, code, name, account_type, is_contra, is_postable
            FROM gl_accounts WHERE parent_id = ? AND status = 1 ORDER BY code
        ");
        $childStmt->bind_param("i", $account['id']);
        $childStmt->execute();
        $children = $childStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $childStmt->close();

        foreach ($children as &$c) {
            $raw = gl_account_balance($conn, (int)$c['id'], $toDate);
            $cIsContra = !empty($c['is_contra']);
            $cIsDebitNormal = in_array($c['account_type'], ['ASSET','EXPENSE'], true) || $cIsContra;
            $c['balance_display'] = $cIsDebitNormal ? $raw : -$raw;
            $groupAggregate += $c['balance_display'];
        }
        unset($c);
    }
}

$accId = $account ? (int)$account['id'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
   
    <title>Quick Ledger</title>
    <style>
        * { box-sizing: border-box; }
        body {
            background: #eef1f5;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            margin: 0; padding: 0; color: #1e293b;
        }
        .ql-wrap { max-width: 1200px; margin: 30px auto; padding: 0 20px; }

        /* Header */
        .ql-head {
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
            color: #fff;
            padding: 32px 40px 28px 40px;
            border-radius: 12px 12px 0 0;
            box-shadow: 0 4px 16px rgba(30, 64, 175, 0.15);
            display: flex; justify-content: space-between;
            align-items: flex-end; gap: 20px; flex-wrap: wrap;
        }
        .ql-head h1 { margin: 0; font-size: 32px; font-weight: 700; letter-spacing: -0.5px; }
        .ql-head .sub { font-size: 15px; opacity: 0.9; margin-top: 8px; }

        /* Toolbar / Picker */
        .toolbar {
            background: #fff;
            border-radius: 0 0 12px 12px;
            padding: 22px 40px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            margin-bottom: 24px;
            display: flex; flex-direction: column; gap: 14px;
        }
        .toolbar-row { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
        .toolbar-row .spacer { flex: 1; }

        .picker-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 14px;
            align-items: end;
        }
        @media (max-width: 800px) { .picker-grid { grid-template-columns: 1fr; } }
        .picker-group {
            display: flex; flex-direction: column; gap: 6px;
        }
        .picker-group label {
            font-size: 11px; font-weight: 700; color: #64748b;
            text-transform: uppercase; letter-spacing: 1px; margin: 0;
        }
        .picker-group input,
        .picker-group select {
            height: 44px; padding: 10px 14px;
            border: 1px solid #cbd5e1; border-radius: 8px;
            font-size: 15px; background: #fff; color: #0f172a;
        }
        .picker-group input:focus,
        .picker-group select:focus {
            outline: none; border-color: #1e40af;
            box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
        }

        .btn-tool {
            height: 40px; padding: 0 16px; border-radius: 8px;
            font-size: 13px; font-weight: 600; border: 1px solid;
            cursor: pointer; display: inline-flex; align-items: center;
            gap: 7px; text-decoration: none; transition: all 0.15s;
            background: #fff; color: #475569; border-color: #cbd5e1;
            white-space: nowrap;
        }
        .btn-tool:hover { background: #f8fafc; border-color: #94a3b8; color: #1e293b; text-decoration: none; }
        .btn-tool.primary { background: #1e40af; color: #fff; border-color: #1e40af; }
        .btn-tool.primary:hover { background: #1e3a8a; border-color: #1e3a8a; }
        .btn-tool.export-copy  { color: #6366f1; border-color: #c7d2fe; background: #eef2ff; }
        .btn-tool.export-copy:hover  { background: #6366f1; color: #fff; border-color: #6366f1; }
        .btn-tool.export-csv   { color: #059669; border-color: #a7f3d0; background: #ecfdf5; }
        .btn-tool.export-csv:hover   { background: #059669; color: #fff; border-color: #059669; }
        .btn-tool.export-excel { color: #0369a1; border-color: #bae6fd; background: #f0f9ff; }
        .btn-tool.export-excel:hover { background: #0369a1; color: #fff; border-color: #0369a1; }
        .btn-tool.export-pdf   { color: #dc2626; border-color: #fecaca; background: #fef2f2; }
        .btn-tool.export-pdf:hover   { background: #dc2626; color: #fff; border-color: #dc2626; }

        .quick-periods {
            display: flex; gap: 8px;
            flex-wrap: wrap; margin-top: 6px;
        }
        .quick-periods .period-btn {
            padding: 6px 14px; border-radius: 20px;
            font-size: 13px; font-weight: 600;
            text-decoration: none; background: #f1f5f9;
            color: #475569; border: 1px solid transparent;
            transition: all 0.15s;
        }
        .quick-periods .period-btn:hover {
            background: #e2e8f0; color: #1e293b; text-decoration: none;
        }
        .quick-periods .period-btn.active { background: #1e40af; color: #fff; }

        /* Summary card */
        .summary-card {
            background: #fff; border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            padding: 22px 26px; margin-bottom: 24px;
            display: flex; justify-content: space-between;
            align-items: center; flex-wrap: wrap; gap: 20px;
        }
        .summary-card .acc-name { font-size: 20px; font-weight: 700; color: #0f172a; }
        .summary-card .acc-code {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 14px; color: #64748b; margin-top: 3px;
        }
        .summary-card .right { display: flex; gap: 32px; align-items: center; }
        .balance-block { text-align: right; }
        .balance-block .lbl {
            font-size: 10px; color: #64748b;
            text-transform: uppercase; letter-spacing: 1px;
            font-weight: 700; margin-bottom: 4px;
        }
        .balance-block .val {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 20px; font-weight: 700;
            color: #0f172a; font-variant-numeric: tabular-nums;
        }
        .balance-block.closing .val { color: #1e40af; font-size: 22px; }

        /* Entries card */
        .entries-card {
            background: #fff; border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            overflow: hidden; margin-bottom: 24px;
        }
        .entries-header {
            padding: 16px 24px; background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex; justify-content: space-between;
            align-items: center; flex-wrap: wrap; gap: 12px;
        }
        .entries-header .title { font-size: 15px; font-weight: 700; color: #1e293b; }
        .entries-header .sub { font-size: 12px; color: #64748b; margin-top: 2px; }
        .entries-header .link-full {
            font-size: 13px; font-weight: 600;
            color: #1e40af; text-decoration: none;
            padding: 6px 14px;
            border: 1px solid #bfdbfe; background: #eff6ff;
            border-radius: 6px; transition: all 0.15s;
        }
        .entries-header .link-full:hover {
            background: #1e40af; color: #fff; text-decoration: none;
        }

        .entries-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .entries-table thead th {
            padding: 12px 16px; text-align: left;
            font-size: 10px; font-weight: 700;
            color: #64748b; text-transform: uppercase;
            letter-spacing: 1px; background: #fff;
            border-bottom: 2px solid #e2e8f0;
        }
        .entries-table thead th.amount-col { text-align: right; }
        .entries-table tbody tr { border-bottom: 1px solid #f1f5f9; }
        .entries-table tbody tr:hover { background: #f8fafc; }
        .entries-table tbody tr.reversed-row { background: #fef5f5; opacity: 0.7; }
        .entries-table tbody td { padding: 13px 16px; color: #1e293b; vertical-align: top; }

        .date-col { font-family: 'SF Mono', 'Monaco', monospace; font-size: 13px; color: #475569; white-space: nowrap; width: 110px; }
        .desc-col .txn-desc { font-size: 14px; color: #0f172a; font-weight: 500; }
        .desc-col .txn-meta {
            font-size: 11px; color: #94a3b8; margin-top: 4px;
            display: flex; gap: 8px; flex-wrap: wrap; align-items: center;
        }
        .desc-col .ref-badge {
            display: inline-block; padding: 1px 7px;
            background: #e0e7ff; color: #3730a3;
            border-radius: 3px; font-size: 9px;
            font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .desc-col .line-memo { font-size: 12px; color: #64748b; font-style: italic; margin-top: 4px; }

        .status-badge {
            display: inline-block; padding: 1px 7px;
            border-radius: 3px; font-size: 9px;
            font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.5px; margin-left: 6px;
        }
        .status-badge.reversed { background: #fee2e2; color: #991b1b; }
        .status-badge.reversal { background: #fef3c7; color: #92400e; }

        .entries-table .amount-col {
            text-align: right;
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 14px; font-variant-numeric: tabular-nums;
            width: 130px; white-space: nowrap;
        }
        .entries-table .amount-col.debit { color: #047857; font-weight: 600; }
        .entries-table .amount-col.credit { color: #b91c1c; font-weight: 600; }
        .entries-table .amount-col.zero { color: #cbd5e1; }

        .entries-table .totals-row td {
            background: #f0f5ff; color: #1e293b;
            font-weight: 700; font-size: 13px;
            padding: 14px 16px;
            border-top: 2px solid #1e40af; border-bottom: 2px solid #1e40af;
        }
        .entries-table .totals-row .total-debit { color: #047857; font-weight: 800; }
        .entries-table .totals-row .total-credit { color: #b91c1c; font-weight: 800; }

        .empty-state { padding: 60px 30px; text-align: center; color: #94a3b8; font-size: 15px; font-style: italic; }
        .empty-state .big-icon { font-size: 48px; color: #cbd5e1; margin-bottom: 12px; display: block; }

        .children-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 12px; padding: 24px 26px;
        }
        .child-card {
            display: flex; justify-content: space-between;
            align-items: center; gap: 14px;
            padding: 14px 18px; background: #f8fafc;
            border: 1px solid #e2e8f0; border-radius: 8px;
            text-decoration: none; color: #1e293b; transition: all 0.15s;
        }
        .child-card:hover {
            background: #eff6ff; border-color: #bfdbfe;
            text-decoration: none; color: #1e293b;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(30, 64, 175, 0.08);
        }
        .child-card .left { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
        .child-card .code { font-family: 'SF Mono', 'Monaco', monospace; font-size: 12px; color: #64748b; font-weight: 600; }
        .child-card .name { font-size: 14px; font-weight: 500; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .child-card .balance { font-family: 'SF Mono', 'Monaco', monospace; font-size: 14px; font-weight: 700; color: #1e40af; white-space: nowrap; flex-shrink: 0; }
        .child-card .balance.zero { color: #94a3b8; font-weight: 500; }

        .no-account {
            background: #fff; border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            padding: 60px 30px; text-align: center;
        }
        .no-account .big-icon { font-size: 56px; color: #cbd5e1; display: block; margin-bottom: 16px; }
        .no-account h2 { font-size: 20px; font-weight: 700; color: #1e293b; margin: 0 0 8px 0; }
        .no-account p { font-size: 15px; color: #64748b; margin: 0; }

        /* Print */
        @media print {
            @page { size: A4 portrait; margin: 12mm 10mm; }
            body { background: #fff; font-size: 10pt; font-family: Calibri, Arial, sans-serif; }
            .ql-wrap { max-width: 100%; margin: 0; padding: 0; }
            .toolbar, .quick-periods, .entries-header .link-full, .navbar, .btn-tool { display: none !important; }
            .ql-head {
                background: #fff !important; color: #000 !important;
                padding: 0 0 6pt 0; border-radius: 0; box-shadow: none;
                border-bottom: 2pt solid #000; margin-bottom: 8pt; text-align: center;
            }
            .ql-head h1 { font-size: 14pt; }
            .ql-head .sub { font-size: 9pt; color: #333 !important; }
            .summary-card { box-shadow: none; border-radius: 0; border-bottom: 1pt solid #808080; padding: 6pt 0; }
            .entries-card { box-shadow: none; border-radius: 0; border: 0.5pt solid #808080; }
            .entries-header { background: #e8e8e8 !important; padding: 4pt 8pt !important; border-bottom: 0.5pt solid #808080; }
            .entries-header .title { font-size: 10pt; font-weight: bold; color: #000; }
            .entries-header .sub { font-size: 8pt; color: #333; }
            .entries-table { font-size: 8pt; }
            .entries-table thead th { background: #e8e8e8 !important; color: #000 !important; font-size: 7.5pt; text-transform: none; padding: 2pt 4pt !important; border: 0.5pt solid #808080; }
            .entries-table tbody td { padding: 2pt 4pt !important; border: 0.5pt solid #c0c0c0; font-size: 8pt; color: #000; }
            .date-col, .entries-table .amount-col { font-family: Calibri, Arial, sans-serif; font-size: 8pt; color: #000 !important; }
            .entries-table .totals-row td { background: #f2f2f2 !important; color: #000; padding: 2pt 4pt !important; border: 0.5pt solid #808080; font-size: 8pt; }
            .status-badge, .ref-badge { background: none !important; color: #000; padding: 0; font-size: 7pt; text-transform: none; }
            .entries-table tbody tr.reversed-row { background: #f5f5f5 !important; opacity: 1; }
        }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="ql-wrap">

    <div class="ql-head">
        <div>
            <h1>Quick Ledger</h1>
            <div class="sub">Pick an account to see its recent transactions.</div>
        </div>
    </div>

    <div class="toolbar">
        <form method="get" action="" style="display: contents;">
            <div class="picker-grid">
                <div class="picker-group">
                    <label for="id">Account</label>
                    <select name="id" id="id" onchange="this.form.submit()">
                        <option value="">— Choose an account —</option>
                        <?php foreach ($accountsByType as $type => $list): ?>
                            <?php if (empty($list)) continue; ?>
                            <optgroup label="<?php echo gl_type_label($type); ?>s">
                                <?php foreach ($list as $a): ?>
                                    <option value="<?php echo (int)$a['id']; ?>"
                                        <?php echo ($account && (int)$account['id'] === (int)$a['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($a['code'] . ' — ' . $a['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="picker-group">
                    <label for="from">From</label>
                    <input type="date" id="from" name="from" value="<?php echo htmlspecialchars($fromDate); ?>">
                </div>
                <div class="picker-group">
                    <label for="to">To</label>
                    <input type="date" id="to" name="to" value="<?php echo htmlspecialchars($toDate); ?>">
                </div>
            </div>
            <div class="quick-periods">
                <a href="?id=<?php echo $accId; ?>&from=<?php echo date('Y-m-01'); ?>&to=<?php echo date('Y-m-d'); ?>"
                   class="period-btn <?php echo ($fromDate === date('Y-m-01') && $toDate === date('Y-m-d')) ? 'active' : ''; ?>">
                    This Month
                </a>
                <a href="?id=<?php echo $accId; ?>&from=<?php echo date('Y-m-d', strtotime('-3 months')); ?>&to=<?php echo date('Y-m-d'); ?>"
                   class="period-btn">Last 3 Months</a>
                <a href="?id=<?php echo $accId; ?>&from=<?php echo date('Y-01-01'); ?>&to=<?php echo date('Y-m-d'); ?>"
                   class="period-btn">This Year</a>
                <a href="?id=<?php echo $accId; ?>&from=2000-01-01&to=<?php echo date('Y-m-d'); ?>"
                   class="period-btn">All Time</a>
            </div>
        </form>
        <div class="toolbar-row">
            <span class="spacer"></span>
            <?php if ($account && !$isGrouping): ?>
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
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$account): ?>

        <div class="no-account">
            <span class="glyphicon glyphicon-book big-icon"></span>
            <h2>No account selected</h2>
            <p>Pick an account above to see its recent transactions.</p>
        </div>

    <?php else: ?>

        <div class="summary-card">
            <div class="left">
                <div>
                    <div class="acc-name"><?php echo htmlspecialchars($account['name']); ?></div>
                   
                </div>
            </div>
            <div class="right">
                <?php if ($isGrouping): ?>
                    <div class="balance-block closing">
                        <div class="lbl">Aggregate Balance</div>
                        <div class="val"><?php echo number_format(abs($groupAggregate), 2); ?></div>
                    </div>
                <?php else: ?>
                    <div class="balance-block">
                        <div class="lbl">Opening Balance</div>
                        <div class="val"><?php echo number_format(abs($openingDisplay), 2); ?></div>
                    </div>
                    <div class="balance-block closing">
                        <div class="lbl">Closing Balance</div>
                        <div class="val"><?php echo number_format(abs($closingDisplay), 2); ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($isGrouping): ?>
            <div class="entries-card">
                <div class="entries-header">
                    <div>
                        <div class="title">Child Accounts</div>
                        <div class="sub">This is a grouping account. Click any child to see its ledger.</div>
                    </div>
                    <a href="gl_account_ledger.php?id=<?php echo $accId; ?>&from=<?php echo urlencode($fromDate); ?>&to=<?php echo urlencode($toDate); ?>"
                       class="link-full">
                        <span class="glyphicon glyphicon-new-window"></span> Open Full View
                    </a>
                </div>
                <?php if (empty($children)): ?>
                    <div class="empty-state">
                        <span class="glyphicon glyphicon-folder-open big-icon"></span>
                        No child accounts yet.
                        <div style="margin-top: 12px;">
                            <a href="gl_account_add.php?parent_id=<?php echo $accId; ?>" style="color: #1e40af; font-weight: 600;">
                                + Add a child account
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="children-grid">
                        <?php foreach ($children as $c): ?>
                            <a href="gl_quick_ledger.php?id=<?php echo (int)$c['id']; ?>&from=<?php echo urlencode($fromDate); ?>&to=<?php echo urlencode($toDate); ?>"
                               class="child-card">
                                <div class="left">
                                    <span class="code"><?php echo htmlspecialchars($c['code']); ?></span>
                                    <span class="name"><?php echo htmlspecialchars($c['name']); ?></span>
                                </div>
                                <span class="balance <?php echo abs($c['balance_display']) < 0.005 ? 'zero' : ''; ?>">
                                    <?php echo number_format(abs($c['balance_display']), 2); ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="entries-card">
                <div class="entries-header">
                    <div>
                        <div class="title">
                            Recent Transactions
                            <span style="color: #64748b; font-weight: 500; font-size: 13px;">
                                (showing last <?php echo count($entries); ?>)
                            </span>
                        </div>
                        <div class="sub">
                            <?php echo date('M j, Y', strtotime($fromDate)); ?>
                            through <?php echo date('M j, Y', strtotime($toDate)); ?>
                        </div>
                    </div>
                    <a href="gl_account_ledger.php?id=<?php echo $accId; ?>&from=<?php echo urlencode($fromDate); ?>&to=<?php echo urlencode($toDate); ?>"
                       class="link-full">
                        <span class="glyphicon glyphicon-new-window"></span> Open Full Ledger
                    </a>
                </div>

                <?php if (empty($entries)): ?>
                    <div class="empty-state">
                        <span class="glyphicon glyphicon-inbox big-icon"></span>
                        No transactions in this period.
                    </div>
                <?php else: ?>
                    <table class="entries-table" id="quickTable">
                        <thead>
                            <tr>
                                <th style="width: 110px;">Date</th>
                                <th>Description</th>
                                <th class="amount-col">Debit</th>
                                <th class="amount-col">Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($entries as $e):
                                $isReversed = $e['status'] === 'REVERSED';
                                $isReversal = !empty($e['reversal_of']);
                            ?>
                                <tr class="<?php echo $isReversed ? 'reversed-row' : ''; ?> data-row">
                                    <td class="date-col"><?php echo date('M j, Y', strtotime($e['entry_date'])); ?></td>
                                    <td class="desc-col">
                                        <div class="txn-desc">
                                            <?php echo htmlspecialchars($e['description']); ?>
                                            <?php if ($isReversed): ?>
                                                <span class="status-badge reversed">Reversed</span>
                                            <?php endif; ?>
                                            <?php if ($isReversal): ?>
                                                <span class="status-badge reversal">Reversal</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="txn-meta">
                                            <?php if ($e['reference_type']): ?>
                                                <span class="ref-badge"><?php echo htmlspecialchars($e['reference_type']); ?></span>
                                            <?php endif; ?>
                                            <?php if ($e['reference_id']): ?>
                                                <span>#<?php echo (int)$e['reference_id']; ?></span>
                                            <?php endif; ?>
                                            <a href="gl_transaction_view.php?id=<?php echo (int)$e['txn_id']; ?>"
                                               style="color: #1e40af; text-decoration: none; font-weight: 600;">
                                                View Txn #<?php echo (int)$e['txn_id']; ?> &rarr;
                                            </a>
                                        </div>
                                        <?php if (!empty($e['memo'])): ?>
                                            <div class="line-memo"><?php echo htmlspecialchars($e['memo']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="amount-col <?php echo $e['debit'] > 0 ? 'debit' : 'zero'; ?>">
                                        <?php echo $e['debit'] > 0 ? number_format((float)$e['debit'], 2) : '—'; ?>
                                    </td>
                                    <td class="amount-col <?php echo $e['credit'] > 0 ? 'credit' : 'zero'; ?>">
                                        <?php echo $e['credit'] > 0 ? number_format((float)$e['credit'], 2) : '—'; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <tr class="totals-row">
                                <td colspan="2" style="text-align: right;">
                                    <span class="glyphicon glyphicon-sum"></span>
                                    Period Totals
                                    <span style="color: #64748b; font-weight: 500;">(<?php echo count($entries); ?> shown)</span>
                                </td>
                                <td class="amount-col"><span class="total-debit"><?php echo number_format($totalDr, 2); ?></span></td>
                                <td class="amount-col"><span class="total-credit"><?php echo number_format($totalCr, 2); ?></span></td>
                            </tr>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>

</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
function buildData() {
    var rows = [];
    $('#quickTable tbody tr.data-row').each(function() {
        var date = $(this).find('.date-col').text().trim();
        var desc = $(this).find('.txn-desc').clone().children().remove().end().text().trim();
        var cells = $(this).find('td.amount-col');
        var dr = cells.eq(0).text().trim();
        var cr = cells.eq(1).text().trim();
        if (date) rows.push([date, desc, dr, cr]);
    });
    return rows;
}
function parseAmt(t) {
    if (!t || t === '—') return 0;
    var n = parseFloat(t.replace(/[,\s]/g, ''));
    return isNaN(n) ? 0 : n;
}
function copyToClipboard() {
    var rows = buildData();
    var lines = ['Date\tDescription\tDebit\tCredit'];
    rows.forEach(function(r) { lines.push(r.join('\t')); });
    var text = lines.join('\n');
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(function() { showToast('Copied'); }).catch(function() { fallbackCopy(text); });
    } else {
        fallbackCopy(text);
    }
}
function fallbackCopy(text) {
    var t = document.createElement('textarea');
    t.value = text; t.style.position = 'fixed'; t.style.opacity = '0';
    document.body.appendChild(t); t.select();
    try { document.execCommand('copy'); showToast('Copied'); } catch(e) { alert('Copy failed'); }
    document.body.removeChild(t);
}
function exportCSV() {
    var rows = buildData();
    var csv = 'Date,Description,Debit,Credit\n';
    rows.forEach(function(r) {
        csv += '"' + r[0] + '","' + r[1].replace(/"/g, '""') + '",' + parseAmt(r[2]) + ',' + parseAmt(r[3]) + '\n';
    });
    var blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
    var link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'quick_ledger_' + new Date().toISOString().slice(0,10) + '.csv';
    document.body.appendChild(link); link.click(); document.body.removeChild(link);
    showToast('CSV downloaded');
}
function exportExcel() {
    if (typeof XLSX === 'undefined') { alert('Excel library not loaded'); return; }
    var rows = buildData();
    var data = [['Date', 'Description', 'Debit', 'Credit']];
    rows.forEach(function(r) { data.push([r[0], r[1], parseAmt(r[2]), parseAmt(r[3])]); });
    var ws = XLSX.utils.aoa_to_sheet(data);
    ws['!cols'] = [{wch:12},{wch:50},{wch:14},{wch:14}];
    var wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Quick Ledger');
    XLSX.writeFile(wb, 'quick_ledger_' + new Date().toISOString().slice(0,10) + '.xlsx');
    showToast('Excel downloaded');
}
function showToast(msg) {
    var $t = $('<div style="position:fixed;bottom:24px;right:24px;background:#1e293b;color:#fff;padding:12px 20px;border-radius:8px;font-size:14px;font-weight:600;box-shadow:0 8px 24px rgba(0,0,0,0.15);z-index:9999;"><span class="glyphicon glyphicon-ok-sign" style="margin-right:8px;color:#4ade80;"></span>' + msg + '</div>');
    $('body').append($t);
    setTimeout(function() { $t.fadeOut(300, function() { $(this).remove(); }); }, 2200);
}
</script>

</body>
</html>
<?php $conn->close(); ?>