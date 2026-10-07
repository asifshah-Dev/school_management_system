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
$profitMargin = $revenue > 0 ? ($netProfit / $revenue) * 100 : 0;

// Trial balance
$tb = $conn->query("SELECT SUM(debit) AS dr, SUM(credit) AS cr FROM gl_journal_lines")->fetch_assoc();
$tbDr = (float)$tb['dr']; $tbCr = (float)$tb['cr'];
$tbBalanced = abs($tbDr - $tbCr) < 0.01;

// Recent 10 transactions
$recent = $conn->query("
    SELECT t.id, t.entry_date, t.description, t.reference_type, t.status,
           (SELECT COALESCE(SUM(l.debit), 0) FROM gl_journal_lines l WHERE l.transaction_id = t.id) AS amt
    FROM gl_transactions t
    ORDER BY t.id DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    
    <title>Accounting Dashboard</title>
    <style>
        * { box-sizing: border-box; }
        body {
            background: #eef1f5;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            margin: 0; padding: 0; color: #1e293b;
        }
        .dash-wrap { max-width: 1300px; margin: 30px auto; padding: 0 20px; }

        /* Header */
        .dash-head {
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
            color: #fff;
            padding: 34px 40px 30px 40px;
            border-radius: 12px 12px 0 0;
            box-shadow: 0 4px 16px rgba(30, 64, 175, 0.15);
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 20px;
            flex-wrap: wrap;
        }
        .dash-head h1 { margin: 0; font-size: 34px; font-weight: 700; letter-spacing: -0.5px; }
        .dash-head .sub { font-size: 15px; opacity: 0.9; margin-top: 8px; }

        .btn-new {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 18px;
            background: rgba(255, 255, 255, 0.15);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px; text-decoration: none;
            font-size: 14px; font-weight: 600;
            transition: all 0.15s;
        }
        .btn-new:hover {
            background: #fff; color: #1e40af; text-decoration: none; border-color: #fff;
        }

        /* Status banner */
        .status-banner {
            background: #fff;
            padding: 18px 30px;
            border-radius: 0 0 12px 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            margin-bottom: 24px;
            display: flex; align-items: center; gap: 14px;
            font-size: 14px; font-weight: 600;
        }
        .status-banner .icon {
            width: 36px; height: 36px; border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 17px;
        }
        .status-banner.ok .icon  { background: #065f46; color: #fff; }
        .status-banner.bad .icon { background: #991b1b; color: #fff; }
        .status-banner.ok  { color: #065f46; }
        .status-banner.bad { color: #991b1b; }

        /* Section title */
        .section-title {
            font-size: 12px; font-weight: 800; color: #64748b;
            text-transform: uppercase; letter-spacing: 2px;
            margin: 32px 0 14px 0;
        }
        .section-title:first-of-type { margin-top: 0; }

        /* Big profit card */
        .profit-card {
            background: linear-gradient(135deg, #064e3b 0%, #065f46 100%);
            color: #fff;
            border-radius: 14px;
            padding: 32px 36px;
            display: flex; justify-content: space-between;
            align-items: center; flex-wrap: wrap; gap: 24px;
            box-shadow: 0 8px 24px rgba(6, 78, 59, 0.25);
            margin-bottom: 24px;
        }
        .profit-card.loss {
            background: linear-gradient(135deg, #7f1d1d 0%, #991b1b 100%);
            box-shadow: 0 8px 24px rgba(127, 29, 29, 0.25);
        }
        .profit-card .label {
            font-size: 13px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 2px;
            color: rgba(255, 255, 255, 0.75);
            margin-bottom: 10px;
        }
        .profit-card .value {
            font-size: 48px; font-weight: 800;
            font-family: 'SF Mono', 'Monaco', monospace;
            font-variant-numeric: tabular-nums;
            letter-spacing: -1.5px; line-height: 1;
        }
        .profit-card .right { text-align: right; }
        .profit-card .right .sub-label {
            font-size: 11px; text-transform: uppercase;
            letter-spacing: 1.5px; color: rgba(255, 255, 255, 0.7);
            font-weight: 700; margin-bottom: 4px;
        }
        .profit-card .right .sub-val {
            font-size: 20px; font-weight: 700;
            font-family: 'SF Mono', 'Monaco', monospace;
            color: #fff;
        }
        .profit-card .right .sub-val.muted { opacity: 0.75; font-size: 16px; }

        /* Metric grid */
        .metric-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 16px;
        }

        .metric-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border-left: 5px solid;
            transition: all 0.15s;
        }
        .metric-card:hover {
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }
        .metric-card.green  { border-color: #059669; }
        .metric-card.blue   { border-color: #2563eb; }
        .metric-card.orange { border-color: #ea580c; }
        .metric-card.red    { border-color: #dc2626; }
        .metric-card.purple { border-color: #7c3aed; }
        .metric-card.teal   { border-color: #0891b2; }

        .metric-card .label {
            font-size: 11px; color: #64748b;
            text-transform: uppercase; letter-spacing: 1.2px;
            font-weight: 700; margin-bottom: 10px;
        }
        .metric-card .value {
            font-size: 26px; font-weight: 700;
            font-family: 'SF Mono', 'Monaco', monospace;
            font-variant-numeric: tabular-nums;
            color: #0f172a;
            letter-spacing: -0.5px;
            line-height: 1.1;
        }
        .metric-card .value.pos { color: #047857; }
        .metric-card .value.neg { color: #b91c1c; }
        .metric-card .sub {
            font-size: 12px; color: #94a3b8; margin-top: 6px;
        }

        /* Quick links */
        .quick-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
        }
        .quick {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px 18px;
            text-decoration: none;
            color: #1e293b;
            display: flex; align-items: center; gap: 12px;
            font-weight: 600; font-size: 14px;
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
            width: 36px; height: 36px; border-radius: 8px;
            background: #e0e7ff; color: #1e40af;
            display: flex; align-items: center; justify-content: center;
            font-size: 15px; flex-shrink: 0;
        }

        /* Recent list */
        .recent-list {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            overflow: hidden;
        }
        .recent-item {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 22px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 14px;
            text-decoration: none;
            color: #1e293b;
            transition: background 0.15s;
            gap: 12px;
        }
        .recent-item:hover { background: #f8fafc; text-decoration: none; color: #1e293b; }
        .recent-item:last-child { border-bottom: none; }
        .recent-item .date {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 12px; color: #64748b;
            width: 60px; flex-shrink: 0;
        }
        .recent-item .desc {
            flex: 1; white-space: nowrap;
            overflow: hidden; text-overflow: ellipsis;
        }
        .recent-item .ref {
            font-size: 10px; padding: 2px 8px;
            background: #e0e7ff; color: #3730a3;
            border-radius: 4px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px;
            flex-shrink: 0;
        }
        .recent-item .amt {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-weight: 700; width: 110px;
            text-align: right; color: #0f172a;
            flex-shrink: 0;
        }
        .recent-item.reversed { opacity: 0.55; }
        .recent-item.reversed .amt { text-decoration: line-through; color: #b91c1c; }

        .empty-state {
            padding: 40px; text-align: center;
            color: #94a3b8; font-style: italic; font-size: 14px;
        }

        @media print {
            @page { size: A4 portrait; margin: 12mm 10mm; }
            body { background: #fff; font-size: 10pt; }
            .quick-grid, .btn-new, .status-banner .icon { display: none !important; }
            .dash-head { background: #fff !important; color: #000 !important; padding: 0 0 8pt 0; border-bottom: 2pt solid #000; border-radius: 0; box-shadow: none; text-align: center; }
            .dash-head h1 { font-size: 14pt; }
            .status-banner { border-radius: 0; box-shadow: none; padding: 6pt 0; border-bottom: 1pt solid #808080; }
            .profit-card { background: #fff !important; color: #000 !important; box-shadow: none; border: 1pt solid #808080; padding: 12pt; }
            .profit-card .value { font-size: 24pt; color: #000 !important; }
            .metric-card { box-shadow: none; border: 1pt solid #808080; border-left: 3pt solid #000; }
            .metric-card .value { font-size: 14pt; color: #000 !important; }
            .recent-list { box-shadow: none; border: 1pt solid #808080; }
        }

        @media (max-width: 700px) {
            .dash-head { padding: 24px 20px; }
            .dash-head h1 { font-size: 24px; }
            .profit-card .value { font-size: 32px; }
            .metric-card .value { font-size: 22px; }
        }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="dash-wrap">

    <!-- Header -->
    <div class="dash-head">
        <div>
            <h1>Accounting Dashboard</h1>
            <div class="sub">
                <?php echo htmlspecialchars($sessionTitle); ?> &middot; <?php echo date('l, F j, Y'); ?>
            </div>
        </div>
        <a href="gl_journal_add.php" class="btn-new">
            <span class="glyphicon glyphicon-plus"></span> Post Manual Entry
        </a>
    </div>

    <!-- Status banner -->
    <div class="status-banner <?php echo $tbBalanced ? 'ok' : 'bad'; ?>">
        <span class="icon">
            <span class="glyphicon glyphicon-<?php echo $tbBalanced ? 'ok' : 'warning-sign'; ?>"></span>
        </span>
        <div>
            <?php if ($tbBalanced): ?>
                Ledger is balanced. Total debits = total credits = <?php echo number_format($tbDr, 2); ?>.
            <?php else: ?>
                Ledger is out of balance by <?php echo number_format(abs($tbDr - $tbCr), 2); ?>. Investigate immediately.
            <?php endif; ?>
        </div>
    </div>

    <!-- Net Profit Hero -->
    <div class="profit-card <?php echo $netProfit < 0 ? 'loss' : ''; ?>">
        <div>
            <div class="label"><?php echo $netProfit >= 0 ? 'Net Profit This Session' : 'Net Loss This Session'; ?></div>
            <div class="value"><?php echo number_format(abs($netProfit), 2); ?></div>
        </div>
        <div class="right">
            <div class="sub-label">Revenue</div>
            <div class="sub-val"><?php echo number_format($revenue, 2); ?></div>
            <div class="sub-label" style="margin-top: 12px;">Expenses</div>
            <div class="sub-val muted"><?php echo number_format($expenses, 2); ?></div>
            <?php if ($revenue > 0): ?>
                <div class="sub-label" style="margin-top: 12px;">Margin</div>
                <div class="sub-val"><?php echo number_format($profitMargin, 1); ?>%</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Cash & Receivables -->
    <div class="section-title">Cash &amp; Receivables</div>
    <div class="metric-grid">
        <div class="metric-card green">
            <div class="label">Cash in Hand</div>
            <div class="value <?php echo $cashInHand < 0 ? 'neg' : ''; ?>"><?php echo number_format($cashInHand, 2); ?></div>
            <div class="sub">Physical cash</div>
        </div>
        <div class="metric-card blue">
            <div class="label">Cash at Bank</div>
            <div class="value <?php echo $cashAtBank < 0 ? 'neg' : ''; ?>"><?php echo number_format($cashAtBank, 2); ?></div>
            <div class="sub">In bank accounts</div>
        </div>
        <div class="metric-card orange">
            <div class="label">Owed by Students</div>
            <div class="value"><?php echo number_format($receivable, 2); ?></div>
            <div class="sub">Fee receivable</div>
        </div>
        <div class="metric-card teal">
            <div class="label">Total Liquidity</div>
            <div class="value"><?php echo number_format($cashInHand + $cashAtBank, 2); ?></div>
            <div class="sub">Cash + Bank combined</div>
        </div>
    </div>

    <!-- Obligations -->
    <div class="section-title">Obligations</div>
    <div class="metric-grid">
        <div class="metric-card red">
            <div class="label">Advance Held</div>
            <div class="value"><?php echo number_format($advanceLiab, 2); ?></div>
            <div class="sub">Owed to students as services</div>
        </div>
        <div class="metric-card red">
            <div class="label">Accounts Payable</div>
            <div class="value"><?php echo number_format($payable, 2); ?></div>
            <div class="sub">Owed to suppliers</div>
        </div>
        <div class="metric-card purple">
            <div class="label">Total Obligations</div>
            <div class="value"><?php echo number_format($advanceLiab + $payable, 2); ?></div>
            <div class="sub">Advance + Payable</div>
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
        <a href="gl_quick_ledger.php" class="quick">
            <span class="ico"><span class="glyphicon glyphicon-flash"></span></span>
            Quick Ledger
        </a>
        <a href="gl_accounts.php" class="quick">
            <span class="ico"><span class="glyphicon glyphicon-tasks"></span></span>
            Chart of Accounts
        </a>
         <a href="gl_voucher_add.php" class="quick">
            <span class="ico"><span class="glyphicon glyphicon-tasks"></span></span>
             Vouchers
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