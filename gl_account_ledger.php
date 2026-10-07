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

if (!$account) {
    $_SESSION['message'] = "Account not found.";
    $_SESSION['message_type'] = "danger";
    header("Location: gl_trial_balance.php");
    exit();
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

// --- Opening balance ---
$openStmt = $conn->prepare("
    SELECT COALESCE(SUM(l.debit), 0) AS dr, COALESCE(SUM(l.credit), 0) AS cr
    FROM gl_journal_lines l
    JOIN gl_transactions t ON t.id = l.transaction_id
    WHERE l.account_id = ? AND t.entry_date < ?
");
$openStmt->bind_param("is", $account['id'], $fromDate);
$openStmt->execute();
$o = $openStmt->get_result()->fetch_assoc();
$openStmt->close();
$openingNetRaw = (float)$o['dr'] - (float)$o['cr'];

$isContra = !empty($account['is_contra']);
$isDebitNormal = in_array($account['account_type'], ['ASSET','EXPENSE'], true) || $isContra;
$openingDisplay = $isDebitNormal ? $openingNetRaw : -$openingNetRaw;

// --- Movements in range ---
$stmt = $conn->prepare("
    SELECT
        t.id AS txn_id,
        t.entry_date,
        t.description,
        t.reference_type,
        t.reference_id,
        t.posted_by,
        t.status AS txn_status,
        t.reversal_of,
        l.id AS line_id,
        l.debit,
        l.credit,
        l.memo,
        u.username AS posted_by_name
    FROM gl_journal_lines l
    JOIN gl_transactions t ON t.id = l.transaction_id
    LEFT JOIN users u ON u.id = t.posted_by
    WHERE l.account_id = ?
      AND t.entry_date BETWEEN ? AND ?
    ORDER BY t.entry_date ASC, t.id ASC, l.line_no ASC
");
$stmt->bind_param("iss", $account['id'], $fromDate, $toDate);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$runningRaw = $openingNetRaw;
$totalDr = 0; $totalCr = 0;
foreach ($rows as &$r) {
    $r['debit']  = (float)$r['debit'];
    $r['credit'] = (float)$r['credit'];
    $runningRaw += $r['debit'] - $r['credit'];
    $r['running_raw'] = $runningRaw;
    $r['running_display'] = $isDebitNormal ? $runningRaw : -$runningRaw;
    $totalDr += $r['debit'];
    $totalCr += $r['credit'];
}
unset($r);

$closingRaw = $runningRaw;
$closingDisplay = $isDebitNormal ? $closingRaw : -$closingRaw;

// --- For grouping accounts: children ---
$isGrouping = ((int)$account['is_postable'] === 0);
$children = [];
$groupAggregate = 0.0;

if ($isGrouping) {
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

// Helper for the period filter URLs
$accId = (int)$account['id'];
$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
   
    <title><?php echo htmlspecialchars($account['code'] . ' — ' . $account['name']); ?></title>
    <style>
        body { background: #eef1f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }

        .led-container {
            max-width: 1300px;
            margin: 30px auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .led-header {
            padding: 30px 40px;
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
            color: #fff;
        }
        .led-header .company {
            font-size: 12px; letter-spacing: 2px; text-transform: uppercase;
            opacity: 0.75; margin-bottom: 8px; font-weight: 600;
        }
        .led-header h1 {
            margin: 0; font-size: 26px; font-weight: 700;
            letter-spacing: -0.4px;
            display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
        }
        .led-header h1 .code {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 20px;
            opacity: 0.85;
            font-weight: 600;
        }
        .led-header .period {
            font-size: 14px; opacity: 0.85; margin-top: 8px;
        }

        .type-pill {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            background: rgba(255, 255, 255, 0.15);
            color: #fff;
        }
        .type-pill.grouping {
            background: rgba(255, 255, 255, 0.3);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.4);
        }

        .led-controls {
            padding: 20px 40px;
            background: #f8fafc;
            border-bottom: 1px solid #e5e9ef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
        }
        .led-controls form {
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        }
        .led-controls label {
            font-size: 13px; color: #475569; font-weight: 500; margin: 0;
        }
        .led-controls input[type=date] {
            height: 38px; padding: 6px 12px;
            border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px;
            background: #fff;
        }
        .btn-action {
            height: 38px; padding: 0 18px; border-radius: 6px;
            font-size: 13px; font-weight: 600; border: none;
            cursor: pointer; display: inline-flex; align-items: center;
            gap: 6px; transition: all 0.15s;
            text-decoration: none;
        }
        .btn-primary { background: #1e40af; color: #fff; }
        .btn-primary:hover { background: #1e3a8a; color: #fff; text-decoration: none; }
        .btn-ghost { background: #fff; border: 1px solid #cbd5e1; color: #475569; }
        .btn-ghost:hover { background: #f8fafc; color: #1e293b; text-decoration: none; }

        /* Period filter strip */
        .period-strip {
            padding: 14px 40px;
            background: #fff;
            border-bottom: 1px solid #e5e9ef;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }
        .period-strip .label {
            font-size: 12px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-right: 6px;
        }
        .period-btn {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            background: #f1f5f9;
            color: #475569;
            border: 1px solid transparent;
            transition: all 0.15s;
        }
        .period-btn:hover {
            background: #e2e8f0;
            color: #1e293b;
            text-decoration: none;
        }
        .period-btn.active {
            background: #1e40af;
            color: #fff;
        }

        .led-body { padding: 30px 40px 40px 40px; }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 30px;
        }
        @media (max-width: 900px) { .summary-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 500px) { .summary-grid { grid-template-columns: 1fr; } }

        .sum-card {
            padding: 18px 20px;
            background: #fff;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            border-left: 4px solid #94a3b8;
        }
        .sum-card.opening  { border-left-color: #64748b; }
        .sum-card.debits   { border-left-color: #059669; }
        .sum-card.credits  { border-left-color: #dc2626; }
        .sum-card.closing  { border-left-color: #1e40af; background: #f0f5ff; }

        .sum-card .lbl {
            font-size: 11px; color: #64748b; text-transform: uppercase;
            letter-spacing: 1.2px; font-weight: 700; margin-bottom: 8px;
        }
        .sum-card .val {
            font-size: 22px; font-weight: 700;
            font-family: 'SF Mono', 'Monaco', monospace;
            font-variant-numeric: tabular-nums;
            color: #0f172a;
        }
        .sum-card .val.pos { color: #047857; }
        .sum-card .val.neg { color: #b91c1c; }

        .children-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 12px;
        }
        .child-card {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            padding: 14px 18px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            text-decoration: none;
            color: #1e293b;
            transition: all 0.15s;
        }
        .child-card:hover {
            background: #eff6ff;
            border-color: #bfdbfe;
            text-decoration: none;
            color: #1e293b;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(30, 64, 175, 0.08);
        }
        .child-card .left { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
        .child-card .code {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 12px; color: #64748b; font-weight: 600;
        }
        .child-card .name {
            font-size: 14px; font-weight: 500; color: #0f172a;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .child-card .balance {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 14px; font-weight: 700; color: #1e40af;
            white-space: nowrap; flex-shrink: 0;
        }
        .child-card .balance.zero { color: #94a3b8; font-weight: 500; }

        .grouping-panel {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 28px 30px;
        }
        .grouping-panel .icon-wrap { text-align: center; margin-bottom: 20px; }
        .grouping-panel .icon-wrap .glyphicon { font-size: 48px; color: #bfdbfe; }
        .grouping-panel h2 {
            text-align: center; font-size: 20px; font-weight: 700;
            color: #1e293b; margin: 0 0 8px 0;
        }
        .grouping-panel .desc {
            text-align: center; font-size: 14px; color: #64748b;
            max-width: 560px; margin: 0 auto 28px auto; line-height: 1.6;
        }

        .ledger-table-wrap {
            background: #fff;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }

        .ledger-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .ledger-table thead th {
            background: #1e293b;
            color: #fff;
            padding: 14px 10px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            text-align: left;
            white-space: nowrap;
        }
        .ledger-table thead th.amount-col { text-align: right; }

        .ledger-table tbody tr { border-bottom: 1px solid #f1f5f9; }
        .ledger-table tbody tr:hover { background: #f8fafc; }
        .ledger-table tbody tr.reversed-row { background: #fef5f5; opacity: 0.75; }
        .ledger-table tbody tr.reversed-row:hover { background: #fdecec; }
        .ledger-table tbody td {
            padding: 11px 10px;
            vertical-align: top;
            color: #1e293b;
        }

        .date-col {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 12px; color: #475569;
            white-space: nowrap; width: 90px;
        }
        .desc-col { min-width: 240px; max-width: 380px; }
        .desc-col .txn-desc { font-weight: 500; color: #0f172a; }
        .desc-col .txn-meta {
            font-size: 11px; color: #94a3b8; margin-top: 3px;
            display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
        }
        .desc-col .txn-meta .ref-badge {
            padding: 1px 6px; background: #e0e7ff;
            color: #3730a3; border-radius: 3px;
            font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.5px; font-size: 9px;
        }
        .desc-col .txn-meta .user { color: #94a3b8; }
        .desc-col .line-memo {
            font-size: 11px; color: #64748b;
            font-style: italic; margin-top: 4px;
        }

        .amount-col {
            text-align: right;
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 12px;
            font-variant-numeric: tabular-nums;
            width: 110px; white-space: nowrap;
        }
        .amount-col.zero { color: #cbd5e1; }
        .amount-col.debit { color: #047857; font-weight: 600; }
        .amount-col.credit { color: #b91c1c; font-weight: 600; }

        .balance-col {
            text-align: right;
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 12px;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            width: 120px; white-space: nowrap;
            background: #f8fafc;
        }
        .balance-col.pos { color: #1e40af; }
        .balance-col.neg { color: #b91c1c; }

        .status-reversed {
            display: inline-block; padding: 2px 6px;
            background: #fee2e2; color: #991b1b;
            border-radius: 3px; font-size: 9px;
            font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-reversal {
            display: inline-block; padding: 2px 6px;
            background: #fef3c7; color: #92400e;
            border-radius: 3px; font-size: 9px;
            font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .opening-row td {
            background: #f1f5f9;
            font-weight: 600; font-size: 13px;
            color: #334155; padding: 14px 10px;
            border-top: 2px solid #cbd5e1;
            border-bottom: 1px solid #cbd5e1;
        }

        /* Totals row */
        .totals-row td {
            background: #f0f5ff;
            color: #1e293b;
            font-weight: 700;
            font-size: 13px;
            padding: 14px 10px;
            border-top: 2px solid #1e40af;
            border-bottom: 2px solid #1e40af;
        }
        .totals-row .total-debit { color: #047857; font-weight: 800; }
        .totals-row .total-credit { color: #b91c1c; font-weight: 800; }

        .closing-row td {
            background: #1e293b;
            color: #fff;
            font-weight: 700;
            font-size: 14px;
            padding: 16px 10px;
            border-top: 3px double #1e40af;
        }
        .closing-row .balance-col { background: #1e293b; color: #fff; font-size: 14px; }

        .empty-state {
            padding: 50px 30px;
            text-align: center;
            color: #94a3b8;
            font-size: 14px;
            font-style: italic;
        }

        .footer-note {
            padding: 20px 40px;
            background: #f8fafc;
            border-top: 1px solid #e5e9ef;
            font-size: 12px;
            color: #64748b;
            line-height: 1.6;
        }

        @media print {
            body { background: #fff; }
            .led-container { box-shadow: none; margin: 0; border-radius: 0; max-width: none; }
            .led-controls { display: none !important; }
            .period-strip { display: none !important; }
            .led-body { padding: 20px; }
            .footer-note { background: #fff; }
        }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="led-container">

    <div class="led-header">
        <div class="company">Dar-e-Arqm School</div>
        <h1>
            <span class="code"><?php echo htmlspecialchars($account['code']); ?></span>
            <?php echo htmlspecialchars($account['name']); ?>
            <span class="type-pill"><?php echo gl_type_label($account['account_type']); ?></span>
            <?php if (!empty($account['is_contra'])): ?>
                <span class="type-pill">Contra</span>
            <?php endif; ?>
            <?php if ($isGrouping): ?>
                <span class="type-pill grouping">Grouping</span>
            <?php endif; ?>
        </h1>
        <div class="period">
            <?php echo date('F j, Y', strtotime($fromDate)); ?>
            through
            <?php echo date('F j, Y', strtotime($toDate)); ?>
        </div>
    </div>

    <div class="led-controls">
        <form method="get" action="">
            <input type="hidden" name="id" value="<?php echo $accId; ?>">
            <label for="from">From:</label>
            <input type="date" id="from" name="from" value="<?php echo htmlspecialchars($fromDate); ?>">
            <label for="to">To:</label>
            <input type="date" id="to" name="to" value="<?php echo htmlspecialchars($toDate); ?>">
            <button type="submit" class="btn-action btn-primary">
                <span class="glyphicon glyphicon-refresh"></span> Apply
            </button>
        </form>
        <div>
            <a href="gl_trial_balance.php" class="btn-action btn-ghost">
                <span class="glyphicon glyphicon-arrow-left"></span> Back
            </a>
            <button type="button" class="btn-action btn-ghost" onclick="window.print()" style="margin-left: 8px;">
                <span class="glyphicon glyphicon-print"></span> Print
            </button>
        </div>
    </div>

    <!-- Period filter strip -->
    <div class="period-strip">
        <span class="label">Quick Period:</span>

        <?php
        $thisMonthFrom = date('Y-m-01');
        $thisMonthTo   = date('Y-m-d');
        $last3From     = date('Y-m-d', strtotime('-3 months'));
        $thisYearFrom  = date('Y-01-01');
        $allFrom       = '2000-01-01';

        $isThisMonth = ($fromDate === $thisMonthFrom && $toDate === $thisMonthTo);
        $isLast3     = ($fromDate === $last3From && $toDate === $thisMonthTo);
        $isThisYear  = ($fromDate === $thisYearFrom && $toDate === $thisMonthTo);
        $isAllTime   = ($fromDate === $allFrom && $toDate === $thisMonthTo);
        ?>

        <a href="?id=<?php echo $accId; ?>&from=<?php echo urlencode($thisMonthFrom); ?>&to=<?php echo urlencode($thisMonthTo); ?>"
           class="period-btn <?php echo $isThisMonth ? 'active' : ''; ?>">
            This Month
        </a>
        <a href="?id=<?php echo $accId; ?>&from=<?php echo urlencode($last3From); ?>&to=<?php echo urlencode($thisMonthTo); ?>"
           class="period-btn <?php echo $isLast3 ? 'active' : ''; ?>">
            Last 3 Months
        </a>
        <a href="?id=<?php echo $accId; ?>&from=<?php echo urlencode($thisYearFrom); ?>&to=<?php echo urlencode($thisMonthTo); ?>"
           class="period-btn <?php echo $isThisYear ? 'active' : ''; ?>">
            This Year
        </a>
        <a href="?id=<?php echo $accId; ?>&from=<?php echo urlencode($allFrom); ?>&to=<?php echo urlencode($thisMonthTo); ?>"
           class="period-btn <?php echo $isAllTime ? 'active' : ''; ?>">
            All Time
        </a>
    </div>

    <div class="led-body">

        <?php if ($isGrouping): ?>
            <!-- GROUPING ACCOUNT -->
            <div class="summary-grid">
                <div class="sum-card opening">
                    <div class="lbl">Grouping Account</div>
                    <div class="val" style="font-size: 16px; padding-top: 4px;">No direct entries</div>
                </div>
                <div class="sum-card">
                    <div class="lbl">Children</div>
                    <div class="val"><?php echo count($children); ?></div>
                </div>
                <div class="sum-card">
                    <div class="lbl">Account Type</div>
                    <div class="val" style="font-size: 16px; padding-top: 4px;"><?php echo gl_type_label($account['account_type']); ?></div>
                </div>
                <div class="sum-card closing">
                    <div class="lbl">Aggregate Balance</div>
                    <div class="val <?php echo $groupAggregate < 0 ? 'neg' : 'pos'; ?>">
                        <?php echo number_format(abs($groupAggregate), 2); ?>
                    </div>
                </div>
            </div>

            <div class="grouping-panel">
                <div class="icon-wrap">
                    <span class="glyphicon glyphicon-folder-open"></span>
                </div>
                <h2>This is a grouping account.</h2>
                <p class="desc">
                    Grouping accounts don't hold money directly. Transactions post to the child accounts below.
                    Click any child to see its detailed ledger.
                </p>

                <?php if (empty($children)): ?>
                    <div style="text-align: center; padding: 20px; color: #94a3b8; font-size: 14px; font-style: italic;">
                        No child accounts yet.
                        <a href="gl_account_add.php?parent_id=<?php echo $accId; ?>" style="color: #1e40af; font-weight: 600;">
                            Add a child account &rarr;
                        </a>
                    </div>
                <?php else: ?>
                    <div class="children-grid">
                        <?php foreach ($children as $c): ?>
                            <a href="gl_account_ledger.php?id=<?php echo (int)$c['id']; ?>&from=<?php echo urlencode($fromDate); ?>&to=<?php echo urlencode($toDate); ?>"
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
            <!-- LEAF ACCOUNT -->

            <div class="summary-grid">
                <div class="sum-card opening">
                    <div class="lbl">Opening Balance</div>
                    <div class="val <?php echo $openingDisplay < 0 ? 'neg' : ''; ?>">
                        <?php echo number_format(abs($openingDisplay), 2); ?>
                    </div>
                </div>
                <div class="sum-card debits">
                    <div class="lbl">Total Debits</div>
                    <div class="val"><?php echo number_format($totalDr, 2); ?></div>
                </div>
                <div class="sum-card credits">
                    <div class="lbl">Total Credits</div>
                    <div class="val"><?php echo number_format($totalCr, 2); ?></div>
                </div>
                <div class="sum-card closing">
                    <div class="lbl">Closing Balance</div>
                    <div class="val <?php echo $closingDisplay < 0 ? 'neg' : ''; ?>">
                        <?php echo number_format(abs($closingDisplay), 2); ?>
                    </div>
                </div>
            </div>

            <div class="ledger-table-wrap">
                <?php if (empty($rows)): ?>
                    <div class="empty-state">
                        No transactions on this account during the selected period.
                    </div>
                <?php else: ?>
                    <table class="ledger-table">
                        <thead>
                            <tr>
                                <th style="width: 90px;">Date</th>
                                <th>Description</th>
                                <th class="amount-col">Debit</th>
                                <th class="amount-col">Credit</th>
                                <th class="amount-col">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Opening row -->
                            <tr class="opening-row">
                                <td><?php echo date('M j, Y', strtotime($fromDate . ' -1 day')); ?></td>
                                <td colspan="3" style="text-align: right;">Opening Balance</td>
                                <td class="balance-col <?php echo $openingDisplay < 0 ? 'neg' : 'pos'; ?>">
                                    <?php echo number_format(abs($openingDisplay), 2); ?>
                                </td>
                            </tr>

                            <?php foreach ($rows as $r):
                                $isReversed = $r['txn_status'] === 'REVERSED';
                                $isReversal = !empty($r['reversal_of']);
                                $rowClass = $isReversed ? 'reversed-row' : '';
                            ?>
                            <tr class="<?php echo $rowClass; ?>">
                                <td class="date-col">
                                    <?php echo date('M j, Y', strtotime($r['entry_date'])); ?>
                                </td>
                                <td class="desc-col">
                                    <div class="txn-desc">
                                        <?php echo htmlspecialchars($r['description']); ?>
                                        <?php if ($isReversed): ?>
                                            <span class="status-reversed">Reversed</span>
                                        <?php endif; ?>
                                        <?php if ($isReversal): ?>
                                            <span class="status-reversal">Reversal of #<?php echo (int)$r['reversal_of']; ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="txn-meta">
                                        <?php if ($r['reference_type']): ?>
                                            <span class="ref-badge"><?php echo htmlspecialchars($r['reference_type']); ?></span>
                                        <?php endif; ?>
                                        <?php if ($r['reference_id']): ?>
                                            <span>#<?php echo (int)$r['reference_id']; ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($r['posted_by_name'])): ?>
                                            <span class="user">
                                                <span class="glyphicon glyphicon-user"></span>
                                                <?php echo htmlspecialchars($r['posted_by_name']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <a href="gl_transaction_view.php?id=<?php echo (int)$r['txn_id']; ?>"
                                           style="color: #1e40af; text-decoration: none; font-weight: 600;">
                                            View Txn #<?php echo (int)$r['txn_id']; ?> &rarr;
                                        </a>
                                    </div>
                                    <?php if (!empty($r['memo'])): ?>
                                        <div class="line-memo">
                                            <?php echo htmlspecialchars($r['memo']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="amount-col <?php echo $r['debit'] == 0 ? 'zero' : 'debit'; ?>">
                                    <?php echo $r['debit'] == 0 ? '—' : number_format($r['debit'], 2); ?>
                                </td>
                                <td class="amount-col <?php echo $r['credit'] == 0 ? 'zero' : 'credit'; ?>">
                                    <?php echo $r['credit'] == 0 ? '—' : number_format($r['credit'], 2); ?>
                                </td>
                                <td class="balance-col <?php echo $r['running_display'] < 0 ? 'neg' : 'pos'; ?>">
                                    <?php echo number_format(abs($r['running_display']), 2); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>

                            <!-- Totals row -->
                            <tr class="totals-row">
                                <td colspan="2" style="text-align: right;">
                                    <span class="glyphicon glyphicon-sum"></span>
                                    Period Totals
                                    <span style="color: #64748b; font-weight: 500;">
                                        (<?php echo count($rows); ?> entries)
                                    </span>
                                </td>
                                <td class="amount-col">
                                    <span class="total-debit"><?php echo number_format($totalDr, 2); ?></span>
                                </td>
                                <td class="amount-col">
                                    <span class="total-credit"><?php echo number_format($totalCr, 2); ?></span>
                                </td>
                                <td class="balance-col" style="background: #f0f5ff;">
                                    <?php
                                    // Period net movement in display direction
                                    $netMovementRaw = $totalDr - $totalCr;
                                    $netMovementDisplay = $isDebitNormal ? $netMovementRaw : -$netMovementRaw;
                                    ?>
                                    <span style="color: <?php echo $netMovementDisplay >= 0 ? '#047857' : '#b91c1c'; ?>;">
                                        <?php echo ($netMovementDisplay >= 0 ? '+' : '−') . number_format(abs($netMovementDisplay), 2); ?>
                                    </span>
                                </td>
                            </tr>

                            <!-- Closing row -->
                            <tr class="closing-row">
                                <td><?php echo date('M j, Y', strtotime($toDate)); ?></td>
                                <td colspan="3" style="text-align: right;">Closing Balance</td>
                                <td class="balance-col">
                                    <?php echo number_format(abs($closingDisplay), 2); ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

        <?php endif; ?>

    </div>

    <div class="footer-note">
        <?php if ($isGrouping): ?>
            <strong>Grouping account:</strong> This account doesn't hold money directly. Click any child account above to see its ledger.
        <?php else: ?>
            <strong>Running Balance</strong> is calculated in the natural direction of this account.
            <?php if ($isDebitNormal): ?>
                (Debit-normal — a positive balance means the account is <em>net debit</em>.)
            <?php else: ?>
                (Credit-normal — a positive balance means the account is <em>net credit</em>.)
            <?php endif; ?>
            <br>
            <strong>Period Totals</strong> row shows the sum of debits and credits within the selected date range.
            Reversed transactions are grayed out but still shown for audit purposes.
        <?php endif; ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

</body>
</html>
<?php $conn->close(); ?>