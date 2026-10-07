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
$opening = 0.0;
$closing = 0.0;
$openingDisplay = 0.0;
$closingDisplay = 0.0;
$isDebitNormal = true;
$limit = 20;
$isGrouping = false;
$children = [];
$groupAggregate = 0.0;
$totalDr = 0.0;
$totalCr = 0.0;

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

        // Totals from entries shown
        foreach ($entries as $e) {
            $totalDr += (float)$e['debit'];
            $totalCr += (float)$e['credit'];
        }
    } else {
        $childStmt = $conn->prepare("
            SELECT id, code, name, account_type, is_contra, is_postable
            FROM gl_accounts
            WHERE parent_id = ? AND status = 1
            ORDER BY code
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

$typeMeta = [
    'ASSET'     => ['label' => 'Asset',      'color' => '#1e40af'],
    'LIABILITY' => ['label' => 'Liability',  'color' => '#c2410c'],
    'EQUITY'    => ['label' => 'Equity',     'color' => '#6b21a8'],
    'REVENUE'   => ['label' => 'Revenue',    'color' => '#047857'],
    'EXPENSE'   => ['label' => 'Expense',    'color' => '#b91c1c'],
];

$accId = $account ? (int)$account['id'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    
    <title>Quick Ledger</title>
    <style>
        body { background: #eef1f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }

        .ql-wrap {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .ql-head { margin-bottom: 24px; }
        .ql-head h1 {
            margin: 0; font-size: 32px; font-weight: 700;
            color: #1e293b; letter-spacing: -0.5px;
        }
        .ql-head .sub { color: #64748b; font-size: 15px; margin-top: 6px; }

        .picker-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            padding: 22px 26px;
            margin-bottom: 24px;
        }
        .picker-card label {
            font-size: 12px; font-weight: 700;
            color: #64748b; text-transform: uppercase;
            letter-spacing: 1px; margin-bottom: 8px; display: block;
        }
        .picker-row {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 14px; align-items: end;
        }
        @media (max-width: 800px) { .picker-row { grid-template-columns: 1fr; } }
        .picker-input,
        .picker-select {
            width: 100%; height: 46px;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px; font-size: 15px;
            background: #fff; color: #0f172a;
        }
        .picker-input:focus,
        .picker-select:focus {
            outline: none; border-color: #1e40af;
            box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
        }
        .quick-periods {
            display: flex; gap: 8px;
            flex-wrap: wrap; margin-top: 12px;
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
        .quick-periods .period-btn.active {
            background: #1e40af; color: #fff;
        }

        .summary-card {
            background: #fff; border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            padding: 22px 26px; margin-bottom: 24px;
            display: flex; justify-content: space-between;
            align-items: center; flex-wrap: wrap; gap: 20px;
        }
        .summary-card .left { display: flex; align-items: center; gap: 16px; }
        .summary-card .acc-badge {
            display: inline-block; padding: 4px 12px;
            border-radius: 20px; font-size: 11px;
            font-weight: 700; text-transform: uppercase;
            letter-spacing: 1px; color: #fff;
        }
        .summary-card .acc-badge.grouping { background: #475569; }
        .summary-card .acc-name {
            font-size: 20px; font-weight: 700;
            color: #0f172a; line-height: 1.2;
        }
        .summary-card .acc-code {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 14px; color: #64748b; margin-top: 3px;
        }
        .summary-card .right {
            display: flex; gap: 32px; align-items: center;
        }
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

        .entries-card {
            background: #fff; border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            overflow: hidden;
        }
        .entries-header {
            padding: 16px 24px; background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex; justify-content: space-between;
            align-items: center; flex-wrap: wrap; gap: 12px;
        }
        .entries-header .title {
            font-size: 15px; font-weight: 700; color: #1e293b;
        }
        .entries-header .sub {
            font-size: 12px; color: #64748b; margin-top: 2px;
        }
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

        .entries-table {
            width: 100%; border-collapse: collapse; font-size: 14px;
        }
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
        .entries-table tbody td {
            padding: 13px 16px;
            color: #1e293b; vertical-align: top;
        }

        .entries-table .date-col {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 13px; color: #475569;
            white-space: nowrap; width: 110px;
        }
        .entries-table .desc-col .txn-desc {
            font-size: 14px; color: #0f172a; font-weight: 500;
        }
        .entries-table .desc-col .txn-meta {
            font-size: 11px; color: #94a3b8;
            margin-top: 4px; display: flex;
            gap: 8px; flex-wrap: wrap; align-items: center;
        }
        .entries-table .desc-col .ref-badge {
            display: inline-block; padding: 1px 7px;
            background: #e0e7ff; color: #3730a3;
            border-radius: 3px; font-size: 9px;
            font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .entries-table .desc-col .line-memo {
            font-size: 12px; color: #64748b;
            font-style: italic; margin-top: 4px;
        }

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

        /* Totals row */
        .entries-table .totals-row td {
            background: #f0f5ff;
            color: #1e293b;
            font-weight: 700;
            font-size: 13px;
            padding: 14px 16px;
            border-top: 2px solid #1e40af;
            border-bottom: 2px solid #1e40af;
        }
        .entries-table .totals-row .total-debit { color: #047857; font-weight: 800; }
        .entries-table .totals-row .total-credit { color: #b91c1c; font-weight: 800; }

        .empty-state {
            padding: 60px 30px; text-align: center;
            color: #94a3b8; font-size: 15px; font-style: italic;
        }
        .empty-state .big-icon {
            font-size: 48px; color: #cbd5e1;
            margin-bottom: 12px; display: block;
        }

        .children-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 12px; padding: 24px 26px;
        }
        .child-card {
            display: flex; justify-content: space-between;
            align-items: center; gap: 14px;
            padding: 14px 18px; background: #f8fafc;
            border: 1px solid #e2e8f0; border-radius: 8px;
            text-decoration: none; color: #1e293b;
            transition: all 0.15s;
        }
        .child-card:hover {
            background: #eff6ff; border-color: #bfdbfe;
            text-decoration: none; color: #1e293b;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(30, 64, 175, 0.08);
        }
        .child-card .left {
            display: flex; flex-direction: column;
            gap: 3px; min-width: 0;
        }
        .child-card .code {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 12px; color: #64748b; font-weight: 600;
        }
        .child-card .name {
            font-size: 14px; font-weight: 500;
            color: #0f172a; white-space: nowrap;
            overflow: hidden; text-overflow: ellipsis;
        }
        .child-card .balance {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 14px; font-weight: 700;
            color: #1e40af; white-space: nowrap;
            flex-shrink: 0;
        }
        .child-card .balance.zero { color: #94a3b8; font-weight: 500; }

        .no-account {
            background: #fff; border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            padding: 60px 30px; text-align: center;
        }
        .no-account .big-icon {
            font-size: 56px; color: #cbd5e1;
            display: block; margin-bottom: 16px;
        }
        .no-account h2 {
            font-size: 20px; font-weight: 700;
            color: #1e293b; margin: 0 0 8px 0;
        }
        .no-account p {
            font-size: 15px; color: #64748b; margin: 0;
        }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="ql-wrap">

    <div class="ql-head">
        <h1>Quick Ledger</h1>
        <div class="sub">Pick an account to see its recent transactions.</div>
    </div>

    <!-- Account picker -->
    <div class="picker-card">
        <form method="get" action="">
            <div class="picker-row">
                <div>
                    <label for="id">Account</label>
                    <select name="id" id="id" class="picker-select" onchange="this.form.submit()">
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
                <div>
                    <label for="from">From</label>
                    <input type="date" id="from" name="from" class="picker-input"
                           value="<?php echo htmlspecialchars($fromDate); ?>">
                </div>
                <div>
                    <label for="to">To</label>
                    <input type="date" id="to" name="to" class="picker-input"
                           value="<?php echo htmlspecialchars($toDate); ?>">
                </div>
            </div>
            <div class="quick-periods">
                <a href="?id=<?php echo $accId; ?>&from=<?php echo date('Y-m-01'); ?>&to=<?php echo date('Y-m-d'); ?>"
                   class="period-btn <?php echo ($fromDate === date('Y-m-01') && $toDate === date('Y-m-d')) ? 'active' : ''; ?>">
                    This Month
                </a>
                <a href="?id=<?php echo $accId; ?>&from=<?php echo date('Y-m-d', strtotime('-3 months')); ?>&to=<?php echo date('Y-m-d'); ?>"
                   class="period-btn">
                    Last 3 Months
                </a>
                <a href="?id=<?php echo $accId; ?>&from=<?php echo date('Y-01-01'); ?>&to=<?php echo date('Y-m-d'); ?>"
                   class="period-btn">
                    This Year
                </a>
                <a href="?id=<?php echo $accId; ?>&from=2000-01-01&to=<?php echo date('Y-m-d'); ?>"
                   class="period-btn">
                    All Time
                </a>
            </div>
        </form>
    </div>

    <?php if (!$account): ?>

        <div class="no-account">
            <span class="glyphicon glyphicon-book big-icon"></span>
            <h2>No account selected</h2>
            <p>Pick an account above to see its recent transactions.</p>
        </div>

    <?php else: ?>

        <!-- Account summary -->
        <div class="summary-card">
            <div class="left">
                <div>
                    <div class="acc-name" style="margin-top: 8px;">
                        <?php echo htmlspecialchars($account['name']); ?>
                    </div>
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

            <!-- Grouping: show children -->
            <div class="entries-card">
                <div class="entries-header">
                    <div>
                        <div class="title">Child Accounts</div>
                        <div class="sub">
                            This is a grouping account. Click any child to see its ledger.
                        </div>
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
                            <a href="gl_account_add.php?parent_id=<?php echo $accId; ?>"
                               style="color: #1e40af; font-weight: 600; font-size: 14px;">
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

            <!-- Leaf account: show entries -->
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
                    <table class="entries-table">
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
                                $rowClass = $isReversed ? 'reversed-row' : '';
                            ?>
                                <tr class="<?php echo $rowClass; ?>">
                                    <td class="date-col">
                                        <?php echo date('M j, Y', strtotime($e['entry_date'])); ?>
                                    </td>
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
                                            <div class="line-memo">
                                                <?php echo htmlspecialchars($e['memo']); ?>
                                            </div>
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

                            <!-- Totals row -->
                            <tr class="totals-row">
                                <td colspan="2" style="text-align: right;">
                                    <span class="glyphicon glyphicon-sum"></span>
                                    Period Totals
                                    <span style="color: #64748b; font-weight: 500;">
                                        (<?php echo count($entries); ?> shown)
                                    </span>
                                </td>
                                <td class="amount-col">
                                    <span class="total-debit"><?php echo number_format($totalDr, 2); ?></span>
                                </td>
                                <td class="amount-col">
                                    <span class="total-credit"><?php echo number_format($totalCr, 2); ?></span>
                                </td>
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

</body>
</html>
<?php $conn->close(); ?>