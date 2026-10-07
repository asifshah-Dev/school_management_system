<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

// Current session
$sessionRow = $conn->query("
    SELECT id, title, from_dated, to_dated FROM sessions
    WHERE status = 0 ORDER BY id DESC LIMIT 1
")->fetch_assoc();
$sessionStart = $sessionRow ? $sessionRow['from_dated'] : date('Y-01-01');
$sessionEnd   = $sessionRow ? $sessionRow['to_dated']   : date('Y-12-31');
$sessionTitle = $sessionRow ? $sessionRow['title']      : 'Current Session';

function acct_balance($conn, $code, $asOf = null) {
    $acc = gl_get_account_by_code($conn, $code);
    if (!$acc) return 0.0;
    return gl_account_balance($conn, (int)$acc['id'], $asOf ?: date('Y-m-d'));
}

$cashInHand  = acct_balance($conn, '1010');
$cashAtBank  = acct_balance($conn, '1020');
$receivable  = acct_balance($conn, '1030');
$advanceLiab = -acct_balance($conn, '2020');
$payable     = -acct_balance($conn, '2010');

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(CASE WHEN a.is_contra = 0 THEN l.credit - l.debit ELSE l.debit - l.credit END), 0) AS net
    FROM gl_journal_lines l
    JOIN gl_transactions t ON t.id = l.transaction_id
    JOIN gl_accounts a ON a.id = l.account_id
    WHERE a.account_type = 'REVENUE' AND a.is_postable = 1
      AND t.entry_date BETWEEN ? AND ?
");
$stmt->bind_param("ss", $sessionStart, $sessionEnd);
$stmt->execute();
$revenue = (float)$stmt->get_result()->fetch_assoc()['net'];
$stmt->close();

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(l.debit - l.credit), 0) AS net
    FROM gl_journal_lines l
    JOIN gl_transactions t ON t.id = l.transaction_id
    JOIN gl_accounts a ON a.id = l.account_id
    WHERE a.account_type = 'EXPENSE' AND a.is_postable = 1
      AND t.entry_date BETWEEN ? AND ?
");
$stmt->bind_param("ss", $sessionStart, $sessionEnd);
$stmt->execute();
$expenses = (float)$stmt->get_result()->fetch_assoc()['net'];
$stmt->close();

$netProfit = $revenue - $expenses;

// Trial balance
$tb = $conn->query("SELECT SUM(debit) AS dr, SUM(credit) AS cr FROM gl_journal_lines")->fetch_assoc();
$tbDr = (float)$tb['dr']; $tbCr = (float)$tb['cr'];
$tbBalanced = abs($tbDr - $tbCr) < 0.01;

// Recent 5 transactions
$recent = $conn->query("
    SELECT t.id, t.entry_date, t.description, t.reference_type, t.status,
           (SELECT COALESCE(SUM(l.debit), 0) FROM gl_journal_lines l WHERE l.transaction_id = t.id) AS amt
    FROM gl_transactions t
    ORDER BY t.id DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once('meta_inc.php'); ?>
    <title>Accounting Dashboard</title>
    <style>
        body { background: #eef1f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }

        .dash {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        .dash-head {
            margin-bottom: 24px;
        }
        .dash-head h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
            color: #1e293b;
        }
        .dash-head .sub {
            color: #64748b;
            font-size: 14px;
            margin-top: 4px;
        }

        .status-banner {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 28px;
        }
        .status-banner.ok  { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
        .status-banner.bad { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .status-banner .icon {
            width: 32px; height: 32px; border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 16px;
        }
        .status-banner.ok .icon  { background: #065f46; color: #fff; }
        .status-banner.bad .icon { background: #991b1b; color: #fff; }

        .section-title {
            font-size: 11px;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin: 28px 0 12px 0;
        }
        .section-title:first-of-type { margin-top: 0; }

        .grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }
        @media (max-width: 900px) {
            .grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 600px) {
            .grid { grid-template-columns: 1fr; }
        }

        .card {
            background: #fff;
            border-radius: 10px;
            padding: 20px 22px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
            border-left: 4px solid #94a3b8;
            transition: all 0.2s;
        }
        .card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            transform: translateY(-1px);
        }
        .card.green  { border-color: #059669; }
        .card.blue   { border-color: #2563eb; }
        .card.orange { border-color: #ea580c; }
        .card.red    { border-color: #dc2626; }
        .card.purple { border-color: #7c3aed; }
        .card.teal   { border-color: #0891b2; }

        .card .label {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            font-weight: 700;
            margin-bottom: 10px;
        }
        .card .value {
            font-size: 24px;
            font-weight: 700;
            font-family: 'SF Mono', 'Monaco', monospace;
            font-variant-numeric: tabular-nums;
            color: #0f172a;
            letter-spacing: -0.5px;
            line-height: 1.1;
        }
        .card .value.pos { color: #047857; }
        .card .value.neg { color: #b91c1c; }
        .card .sub {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 6px;
        }

        .profit-card {
            grid-column: span 3;
            background: linear-gradient(135deg, #064e3b 0%, #065f46 100%);
            color: #fff;
            border-radius: 10px;
            padding: 30px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        .profit-card.loss {
            background: linear-gradient(135deg, #7f1d1d 0%, #991b1b 100%);
        }
        @media (max-width: 900px) {
            .profit-card { grid-column: span 2; }
        }
        @media (max-width: 600px) {
            .profit-card { grid-column: span 1; }
        }

        .profit-card .left .label {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.75);
            text-transform: uppercase;
            letter-spacing: 1.5px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .profit-card .left .value {
            font-size: 40px;
            font-weight: 800;
            font-family: 'SF Mono', 'Monaco', monospace;
            font-variant-numeric: tabular-nums;
            letter-spacing: -1px;
            line-height: 1;
        }
        .profit-card .right {
            text-align: right;
        }
        .profit-card .right .sub-label {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.7);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .profit-card .right .sub-val {
            font-size: 16px;
            font-weight: 600;
            font-family: 'SF Mono', 'Monaco', monospace;
        }
        .profit-card .right .sub-val.pos { color: #86efac; }
        .profit-card .right .sub-val.neg { color: #fca5a5; }

        .quick-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }
        @media (max-width: 900px) {
            .quick-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 600px) {
            .quick-grid { grid-template-columns: 1fr; }
        }

        .quick {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px 18px;
            text-decoration: none;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.15s;
        }
        .quick:hover {
            border-color: #1e40af;
            background: #f0f5ff;
            color: #1e40af;
            text-decoration: none;
            transform: translateX(2px);
        }
        .quick .ico {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            background: #e0e7ff;
            color: #1e40af;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }

        .recent-list {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
            overflow: hidden;
        }
        .recent-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 13px;
            text-decoration: none;
            color: #1e293b;
            transition: background 0.15s;
            gap: 12px;
        }
        .recent-item:hover { background: #f8fafc; text-decoration: none; color: #1e293b; }
        .recent-item:last-child { border-bottom: none; }
        .recent-item .date {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 12px;
            color: #64748b;
            width: 60px;
            flex-shrink: 0;
        }
        .recent-item .desc {
            flex: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .recent-item .ref {
            font-size: 10px;
            padding: 2px 8px;
            background: #e0e7ff;
            color: #3730a3;
            border-radius: 3px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            flex-shrink: 0;
        }
        .recent-item .amt {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-weight: 700;
            width: 100px;
            text-align: right;
            color: #0f172a;
            flex-shrink: 0;
        }
        .recent-item.reversed { opacity: 0.55; }
        .recent-item.reversed .amt { text-decoration: line-through; color: #b91c1c; }

        .empty-state {
            padding: 30px;
            text-align: center;
            color: #94a3b8;
            font-style: italic;
            font-size: 13px;
        }

        @media print {
            .quick-grid { display: none; }
            body { background: #fff; }
        }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="dash">

    <div class="dash-head">
        <h1>Accounting Dashboard</h1>
        <div class="sub">
            <?php echo htmlspecialchars($sessionTitle); ?> &middot; <?php echo date('l, F j, Y'); ?>
        </div>
    </div>

    <!-- Status -->
    <div class="status-banner <?php echo $tbBalanced ? 'ok' : 'bad'; ?>">
        <span class="icon">
            <span class="glyphicon glyphicon-<?php echo $tbBalanced ? 'ok' : 'warning-sign'; ?>"></span>
        </span>
        <div>
            <?php if ($tbBalanced): ?>
                Ledger is balanced. Total debits = total credits = <?php echo number_format($tbDr, 2); ?>.
            <?php else: ?>
                Ledger is out of balance by <?php echo number_format(abs($tbDr - $tbCr), 2); ?>. Please investigate immediately.
            <?php endif; ?>
        </div>
    </div>

    <!-- Net Profit — the headline number -->
    <div class="profit-card <?php echo $netProfit < 0 ? 'loss' : ''; ?>">
        <div class="left">
            <div class="label"><?php echo $netProfit >= 0 ? 'Net Profit This Session' : 'Net Loss This Session'; ?></div>
            <div class="value"><?php echo number_format(abs($netProfit), 2); ?></div>
        </div>
        <div class="right">
            <div class="sub-label">Revenue</div>
            <div class="sub-val pos"><?php echo number_format($revenue, 2); ?></div>
            <div class="sub-label" style="margin-top: 12px;">Expenses</div>
            <div class="sub-val neg"><?php echo number_format($expenses, 2); ?></div>
        </div>
    </div>

    <!-- Cash & Receivables -->
    <div class="section-title">Cash &amp; Receivables</div>
    <div class="grid">
        <div class="card green">
            <div class="label">Cash in Hand</div>
            <div class="value"><?php echo number_format($cashInHand, 2); ?></div>
            <div class="sub">Physical cash</div>
        </div>
        <div class="card blue">
            <div class="label">Cash at Bank</div>
            <div class="value"><?php echo number_format($cashAtBank, 2); ?></div>
            <div class="sub">In bank accounts</div>
        </div>
        <div class="card orange">
            <div class="label">Owed by Students</div>
            <div class="value"><?php echo number_format($receivable, 2); ?></div>
            <div class="sub">Fee receivable</div>
        </div>
    </div>

    <!-- Obligations -->
    <div class="section-title">Obligations</div>
    <div class="grid">
        <div class="card red">
            <div class="label">Advance Held</div>
            <div class="value"><?php echo number_format($advanceLiab, 2); ?></div>
            <div class="sub">Owed to students as services</div>
        </div>
        <div class="card red">
            <div class="label">Accounts Payable</div>
            <div class="value"><?php echo number_format($payable, 2); ?></div>
            <div class="sub">Owed to suppliers</div>
        </div>
        <div class="card teal">
            <div class="label">Total Liquidity</div>
            <div class="value"><?php echo number_format($cashInHand + $cashAtBank, 2); ?></div>
            <div class="sub">Cash + Bank combined</div>
        </div>
    </div>

    <!-- Quick Links -->
    <div class="section-title">Reports &amp; Actions</div>
    <div class="quick-grid">
        <a href="gl_trial_balance.php" class="quick">
            <span class="ico"><span class="glyphicon glyphicon-list-alt"></span></span>
            Trial Balance
        </a>
        <a href="gl_balance_sheet.php" class="quick">
            <span class="ico"><span class="glyphicon glyphicon-briefcase"></span></span>
            Balance Sheet
        </a>
        <a href="gl_pl.php" class="quick">
            <span class="ico"><span class="glyphicon glyphicon-signal"></span></span>
            Profit &amp; Loss
        </a>
        <a href="gl_transactions.php" class="quick">
            <span class="ico"><span class="glyphicon glyphicon-book"></span></span>
            Journal Entries
        </a>
        <a href="gl_journal_add.php" class="quick">
            <span class="ico"><span class="glyphicon glyphicon-plus"></span></span>
            Post Manual Entry
        </a>
        <a href="gl_accounts.php" class="quick">
            <span class="ico"><span class="glyphicon glyphicon-tasks"></span></span>
            Chart of Accounts
        </a>
    </div>

    <!-- Recent Activity -->
    <div class="section-title">Recent Activity</div>
    <div class="recent-list">
        <?php if (empty($recent)): ?>
            <div class="empty-state">No recent transactions</div>
        <?php else: ?>
            <?php foreach ($recent as $r): ?>
                <a href="gl_transaction_view.php?id=<?php echo (int)$r['id']; ?>"
                   class="recent-item <?php echo $r['status'] === 'REVERSED' ? 'reversed' : ''; ?>">
                    <span class="date"><?php echo date('M j', strtotime($r['entry_date'])); ?></span>
                    <span class="desc"><?php echo htmlspecialchars($r['description']); ?></span>
                    <span class="ref"><?php echo htmlspecialchars($r['reference_type'] ?: 'MANUAL'); ?></span>
                    <span class="amt"><?php echo number_format((float)$r['amt'], 2); ?></span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

</body>
</html>
<?php $conn->close(); ?>