<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");

/* ───────── Session ───────── */
$sessionRow = $conn->query("SELECT id, title, from_dated, to_dated FROM sessions WHERE status = 0 ORDER BY id DESC LIMIT 1")->fetch_assoc();
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

/* AP */
$apRecon = gl_ap_reconciliation($conn);
$payable = $apRecon['sub'];
$suppliersWithBalance = 0;
foreach (gl_list_suppliers($conn, false) as $s) {
    if (abs((float)$s['ap_balance']) > 0.01) $suppliersWithBalance++;
}

/* Bank recon */
$brTableExists = false;
$chk = $conn->query("SHOW TABLES LIKE 'bank_reconciliations'");
if ($chk && $chk->num_rows > 0) $brTableExists = true;

$brDraftCount = 0; $brCompletedCount = 0; $brLatestDate = null; $brLatestBalanced = false;
if ($brTableExists) {
    $brDraftCount     = (int)$conn->query("SELECT COUNT(*) AS c FROM bank_reconciliations WHERE status='DRAFT'")->fetch_assoc()['c'];
    $brCompletedCount = (int)$conn->query("SELECT COUNT(*) AS c FROM bank_reconciliations WHERE status='COMPLETED'")->fetch_assoc()['c'];
    $brLatest = $conn->query("SELECT statement_date, difference FROM bank_reconciliations WHERE status='COMPLETED' ORDER BY statement_date DESC, id DESC LIMIT 1")->fetch_assoc();
    if ($brLatest) {
        $brLatestDate = $brLatest['statement_date'];
        $brLatestBalanced = abs((float)$brLatest['difference']) < 0.01;
    }
}

/* Revenue / Expenses */
$stmt = $conn->prepare("SELECT COALESCE(SUM(CASE WHEN a.is_contra = 0 THEN l.credit - l.debit ELSE l.debit - l.credit END), 0) AS net FROM gl_journal_lines l JOIN gl_transactions t ON t.id = l.transaction_id JOIN gl_accounts a ON a.id = l.account_id WHERE a.account_type = 'REVENUE' AND a.is_postable = 1 AND t.entry_date BETWEEN ? AND ?");
$stmt->bind_param("ss", $sessionStart, $sessionEnd);
$stmt->execute();
$revenue = (float)$stmt->get_result()->fetch_assoc()['net'];
$stmt->close();

$stmt = $conn->prepare("SELECT COALESCE(SUM(l.debit - l.credit), 0) AS net FROM gl_journal_lines l JOIN gl_transactions t ON t.id = l.transaction_id JOIN gl_accounts a ON a.id = l.account_id WHERE a.account_type = 'EXPENSE' AND a.is_postable = 1 AND t.entry_date BETWEEN ? AND ?");
$stmt->bind_param("ss", $sessionStart, $sessionEnd);
$stmt->execute();
$expenses = (float)$stmt->get_result()->fetch_assoc()['net'];
$stmt->close();

$netProfit = $revenue - $expenses;
$profitMargin = $revenue > 0 ? ($netProfit / $revenue) * 100 : 0;

/* Recent */
$recent = $conn->query("SELECT t.id, t.entry_date, t.description, t.reference_type, t.status, (SELECT COALESCE(SUM(l.debit), 0) FROM gl_journal_lines l WHERE l.transaction_id = t.id) AS amt FROM gl_transactions t ORDER BY t.id DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);

/* Session closure */
$closedCurrentSession = false;
$currentSessionClosure = null;
if ($sessionRow) {
    $chkClosure = $conn->prepare("SELECT id, closed_at, closed_by, total_revenue, total_expenses, net_profit FROM gl_session_closures WHERE session_id = ? LIMIT 1");
    $chkClosure->bind_param("i", $sessionRow['id']);
    $chkClosure->execute();
    $currentSessionClosure = $chkClosure->get_result()->fetch_assoc();
    $chkClosure->close();
    if ($currentSessionClosure) $closedCurrentSession = true;
}

/* ───────── All accounts + totals per type + top accounts ───────── */
$allAccounts = gl_list_accounts($conn, null, true);
$accountsWithBalances = [];
$totalByType = ['ASSET' => 0.0, 'LIABILITY' => 0.0, 'EQUITY' => 0.0, 'REVENUE' => 0.0, 'EXPENSE' => 0.0];
$countByType = ['ASSET' => 0, 'LIABILITY' => 0, 'EQUITY' => 0, 'REVENUE' => 0, 'EXPENSE' => 0];

foreach ($allAccounts as $a) {
    $raw = gl_account_balance($conn, (int)$a['id'], date('Y-m-d'));
    $isContra = !empty($a['is_contra']);
    $signed = $raw;
    if (!$isContra && in_array($a['account_type'], ['LIABILITY','EQUITY','REVENUE'], true)) {
        $signed = -$raw;
    }
    $accountsWithBalances[] = [
        'id'    => (int)$a['id'],
        'code'  => $a['code'],
        'name'  => $a['name'],
        'type'  => $a['account_type'],
        'balance' => $signed,
        'is_contra' => $isContra,
    ];
    $totalByType[$a['account_type']] += $signed;
    if (abs($signed) >= 0.005) $countByType[$a['account_type']]++;
}

/* Top 6 accounts by |balance| */
usort($accountsWithBalances, function($a, $b) {
    return abs($b['balance']) <=> abs($a['balance']);
});
$topAccounts = array_slice($accountsWithBalances, 0, 6);
usort($accountsWithBalances, function($a, $b) { return strcmp($a['code'], $b['code']); });

$typeMeta = [
    'ASSET'     => ['label' => 'Assets',      'short' => 'A', 'color' => '#2563eb', 'border' => '#2563eb', 'icon' => 'fa-wallet'],
    'LIABILITY' => ['label' => 'Liabilities', 'short' => 'L', 'color' => '#ea580c', 'border' => '#ea580c', 'icon' => 'fa-file-invoice-dollar'],
    'EQUITY'    => ['label' => 'Equity',      'short' => 'E', 'color' => '#7c3aed', 'border' => '#7c3aed', 'icon' => 'fa-briefcase'],
    'REVENUE'   => ['label' => 'Revenue',     'short' => 'R', 'color' => '#059669', 'border' => '#059669', 'icon' => 'fa-coins'],
    'EXPENSE'   => ['label' => 'Expenses',    'short' => 'X', 'color' => '#dc2626', 'border' => '#dc2626', 'icon' => 'fa-shopping-cart'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Accounting Dashboard</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    body { background: #f5f7fa; font-family: 'Inter', sans-serif; color: #1e293b; padding-bottom: 40px; }
    .wrap { max-width: 1300px; margin: 24px auto; padding: 0 20px; }

    /* Header */
    .head { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: #fff; padding: 20px 24px; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; }
    .head h1 { margin: 0; font-size: 19px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .head .sub { font-size: 12px; opacity: 0.88; margin-top: 4px; }
    .btn-new { padding: 8px 14px; border-radius: 7px; font-size: 12px; font-weight: 700; background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.3); text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
    .btn-new:hover { background: #fff; color: #1d4ed8; border-color: #fff; text-decoration: none; }

    .content { background: #fff; padding: 18px 22px 22px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); margin-bottom: 16px; }

    /* Banners */
    .info-banner { padding: 10px 14px; border-radius: 8px; font-size: 12px; margin-bottom: 12px; border-left: 4px solid; line-height: 1.5; }
    .info-banner.bad    { background: #fee2e2; border-color: #dc2626; color: #991b1b; }
    .info-banner.warn   { background: #fef3c7; border-color: #d97706; color: #92400e; }
    .info-banner.info   { background: #dbeafe; border-color: #2563eb; color: #1e3a8a; }
    .info-banner strong { font-weight: 700; }
    .info-banner a { color: inherit; font-weight: 700; text-decoration: underline; }

    /* Profit hero */
    .profit-card { background: linear-gradient(135deg, #064e3b 0%, #065f46 100%); color: #fff; border-radius: 12px; padding: 20px 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 18px; margin-bottom: 16px; }
    .profit-card.loss { background: linear-gradient(135deg, #7f1d1d 0%, #991b1b 100%); }
    .profit-card .label { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.2px; color: rgba(255,255,255,0.75); margin-bottom: 6px; }
    .profit-card .value { font-size: 28px; font-weight: 800; font-family: 'SF Mono', monospace; font-variant-numeric: tabular-nums; letter-spacing: -0.8px; line-height: 1; }
    .profit-card .right { display: flex; gap: 24px; flex-wrap: wrap; }
    .profit-card .right .stat .lbl { font-size: 9.5px; text-transform: uppercase; letter-spacing: 1px; color: rgba(255,255,255,0.7); font-weight: 700; margin-bottom: 3px; }
    .profit-card .right .stat .val { font-size: 14px; font-weight: 700; font-family: 'SF Mono', monospace; color: #fff; }
    .profit-card .right .stat .val.muted { opacity: 0.75; }

    /* Section titles */
    .section-title { font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 1.5px; margin: 18px 0 10px; display: flex; align-items: center; gap: 6px; }
    .section-title:first-child { margin-top: 0; }
    .section-title i { color: #2563eb; font-size: 10px; }

    /* Metric grid */
    .metric-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; }
    .metric-card { background: #fff; border-radius: 9px; padding: 12px 14px; border-left: 3px solid; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: relative; }
    .metric-card.green  { border-color: #059669; }
    .metric-card.blue   { border-color: #2563eb; }
    .metric-card.orange { border-color: #ea580c; }
    .metric-card.red    { border-color: #dc2626; }
    .metric-card.purple { border-color: #7c3aed; }
    .metric-card.teal   { border-color: #0891b2; }
    .metric-card.amber  { border-color: #f59e0b; }

    .metric-card .label { font-size: 9.5px; color: #64748b; text-transform: uppercase; letter-spacing: 0.8px; font-weight: 700; margin-bottom: 4px; }
    .metric-card .value { font-size: 16px; font-weight: 700; font-family: 'SF Mono', monospace; font-variant-numeric: tabular-nums; color: #0f172a; letter-spacing: -0.3px; line-height: 1.1; }
    .metric-card .value.pos { color: #047857; }
    .metric-card .value.neg { color: #b91c1c; }
    .metric-card .sub { font-size: 10.5px; color: #94a3b8; margin-top: 4px; }
    .metric-card .sub a { color: #2563eb; text-decoration: none; font-weight: 600; }
    .metric-card .sub a:hover { text-decoration: underline; }

    .metric-card .warn-badge { position: absolute; top: 10px; right: 10px; background: #fee2e2; color: #991b1b; font-size: 9px; font-weight: 800; padding: 2px 6px; border-radius: 8px; text-transform: uppercase; letter-spacing: 0.3px; }
    .metric-card .ok-badge { position: absolute; top: 10px; right: 10px; background: #d1fae5; color: #065f46; font-size: 9px; font-weight: 800; padding: 2px 6px; border-radius: 8px; text-transform: uppercase; letter-spacing: 0.3px; }

    /* ───── Type cards (replaces boring table) ───── */
    .type-cards { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; }
    @media (max-width: 1000px) { .type-cards { grid-template-columns: repeat(3, 1fr); } }
    @media (max-width: 700px)  { .type-cards { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 480px)  { .type-cards { grid-template-columns: 1fr; } }

    .type-card {
        background: #fff;
        border-radius: 10px;
        padding: 14px 14px 12px;
        border: 1px solid #e2e8f0;
        border-left: 3px solid;
        text-decoration: none;
        color: inherit;
        display: block;
        position: relative;
        transition: all 0.15s;
    }
    .type-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        transform: translateY(-2px);
        text-decoration: none;
        color: inherit;
    }
    .type-card .tc-head { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
    .type-card .tc-icon {
        width: 26px; height: 26px; border-radius: 6px;
        display: flex; align-items: center; justify-content: center;
        font-size: 11px; color: #fff; flex-shrink: 0;
    }
    .type-card .tc-label {
        font-size: 9.5px; color: #64748b;
        text-transform: uppercase; letter-spacing: 0.8px; font-weight: 700;
    }
    .type-card .tc-value {
        font-size: 16px; font-weight: 700;
        font-family: 'SF Mono', monospace; font-variant-numeric: tabular-nums;
        color: #0f172a; letter-spacing: -0.3px; line-height: 1.1;
    }
    .type-card .tc-value.neg { color: #b91c1c; }
    .type-card .tc-value.zero { color: #cbd5e1; font-weight: 500; }
    .type-card .tc-sub {
        font-size: 10px; color: #94a3b8; margin-top: 4px;
    }

    /* Top accounts strip */
    .top-list { background: #fff; border-radius: 9px; border: 1px solid #e2e8f0; overflow: hidden; }
    .top-item {
        display: flex; align-items: center; gap: 10px;
        padding: 9px 14px;
        border-bottom: 1px solid #f1f5f9;
        font-size: 12px;
        text-decoration: none;
        color: #1e293b;
        transition: background 0.15s;
    }
    .top-item:hover { background: #fafbfc; text-decoration: none; color: #1e293b; }
    .top-item:last-child { border-bottom: none; }
    .top-item .type-letter {
        width: 18px; height: 18px; border-radius: 4px;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 8.5px; font-weight: 800; flex-shrink: 0;
    }
    .top-item .top-code {
        font-family: 'SF Mono', monospace; font-size: 10.5px;
        color: #64748b; font-weight: 600; width: 50px; flex-shrink: 0;
    }
    .top-item .top-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 500; }
    .top-item .top-bal {
        font-family: 'SF Mono', monospace; font-weight: 700;
        font-size: 12px; text-align: right; width: 110px; flex-shrink: 0;
        color: #0f172a;
    }
    .top-item .top-bal.neg { color: #b91c1c; }

    /* Quick links */
    .quick-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 8px; }
    .quick { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; text-decoration: none; color: #1e293b; display: flex; align-items: center; gap: 9px; font-weight: 600; font-size: 12px; transition: all 0.15s; }
    .quick:hover { border-color: #2563eb; background: #f0f5ff; color: #2563eb; text-decoration: none; }
    .quick .ico { width: 28px; height: 28px; border-radius: 7px; background: #e0e7ff; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 12px; flex-shrink: 0; }
    .quick .ico.pink   { background: #fce7f3; color: #9d174d; }
    .quick .ico.amber  { background: #fef3c7; color: #b45309; }
    .quick .ico.teal   { background: #cffafe; color: #0e7490; }
    .quick .ico.slate  { background: #f1f5f9; color: #475569; }
    .quick .ico.indigo { background: #e0e7ff; color: #4338ca; }

    /* Recent list */
    .recent-list { background: #fff; border-radius: 9px; border: 1px solid #e2e8f0; overflow: hidden; }
    .recent-item { display: flex; align-items: center; justify-content: space-between; padding: 9px 14px; border-bottom: 1px solid #f1f5f9; font-size: 12px; text-decoration: none; color: #1e293b; transition: background 0.15s; gap: 10px; }
    .recent-item:hover { background: #fafbfc; text-decoration: none; color: #1e293b; }
    .recent-item:last-child { border-bottom: none; }
    .recent-item .date { font-family: 'SF Mono', monospace; font-size: 11px; color: #64748b; width: 54px; flex-shrink: 0; }
    .recent-item .desc { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .recent-item .ref { font-size: 9.5px; padding: 1px 6px; background: #e0e7ff; color: #3730a3; border-radius: 4px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; flex-shrink: 0; }
    .recent-item .amt { font-family: 'SF Mono', monospace; font-weight: 700; width: 95px; text-align: right; color: #0f172a; flex-shrink: 0; font-size: 12px; }
    .recent-item.reversed { opacity: 0.55; }
    .recent-item.reversed .amt { text-decoration: line-through; color: #b91c1c; }
    .empty-state { padding: 24px; text-align: center; color: #94a3b8; font-style: italic; font-size: 12.5px; }

    @media (max-width: 700px) {
        .head { padding: 16px 18px; }
        .profit-card .value { font-size: 22px; }
        .profit-card .right { gap: 14px; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <h1><i class="fas fa-chart-line"></i> Accounting Dashboard</h1>
            <div class="sub">
                <?= htmlspecialchars($sessionTitle) ?> &middot; <?= date('l, F j, Y') ?>
            </div>
        </div>
        <?php if (!empty($perms['can_add'])): ?>
            <a href="gl_journal_add.php" class="btn-new"><i class="fas fa-plus"></i> Post Manual Entry</a>
        <?php endif; ?>
    </div>

    <div class="content">

        <!-- AP reconciliation -->
        <?php if (!$apRecon['ok']): ?>
            <div class="info-banner bad">
                <strong><i class="fas fa-exclamation-triangle"></i> Accounts Payable sub-ledger is broken.</strong>
                GL 2010 = PKR <?= number_format($apRecon['gl'], 2) ?> vs supplier balances = PKR <?= number_format($apRecon['sub'], 2) ?> (difference PKR <?= number_format($apRecon['diff'], 2) ?>).
                <a href="gl_parties.php">Open Suppliers</a>
            </div>
        <?php endif; ?>

        <!-- Session snapshot -->
        <?php if ($closedCurrentSession): ?>
            <div class="info-banner info">
                <strong><i class="fas fa-lock"></i> Session snapshotted.</strong>
                Closure #<?= (int)$currentSessionClosure['id'] ?> on <?= htmlspecialchars($currentSessionClosure['closed_at']) ?>.
                Revenue <?= number_format((float)$currentSessionClosure['total_revenue'], 2) ?>,
                Expenses <?= number_format((float)$currentSessionClosure['total_expenses'], 2) ?>,
                Net <?= number_format((float)$currentSessionClosure['net_profit'], 2) ?>.
                <a href="gl_session_close.php">View all closures</a>
            </div>
        <?php endif; ?>

        <!-- Bank recon drafts -->
        <?php if ($brTableExists && $brDraftCount > 0): ?>
            <div class="info-banner warn">
                <strong><i class="fas fa-exchange-alt"></i> <?= $brDraftCount ?> bank reconciliation<?= $brDraftCount === 1 ? '' : 's' ?> in progress.</strong>
                <a href="gl_bank_recon.php">Open Bank Reconciliation</a>
            </div>
        <?php endif; ?>

        <!-- Net Profit Hero -->
        <div class="profit-card <?= $netProfit < 0 ? 'loss' : '' ?>">
            <div>
                <div class="label"><?= $netProfit >= 0 ? 'Net Profit This Session' : 'Net Loss This Session' ?></div>
                <div class="value"><?= number_format(abs($netProfit), 2) ?></div>
            </div>
            <div class="right">
                <div class="stat"><div class="lbl">Revenue</div><div class="val"><?= number_format($revenue, 2) ?></div></div>
                <div class="stat"><div class="lbl">Expenses</div><div class="val muted"><?= number_format($expenses, 2) ?></div></div>
                <?php if ($revenue > 0): ?>
                    <div class="stat"><div class="lbl">Margin</div><div class="val"><?= number_format($profitMargin, 1) ?>%</div></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Cash & Receivables -->
        <div class="section-title"><i class="fas fa-wallet"></i> Cash &amp; Receivables</div>
        <div class="metric-grid">
            <div class="metric-card green">
                <div class="label">Cash in Hand</div>
                <div class="value <?= $cashInHand < 0 ? 'neg' : '' ?>"><?= number_format($cashInHand, 2) ?></div>
                <div class="sub">Physical cash</div>
            </div>
            <div class="metric-card blue">
                <div class="label">Cash at Bank</div>
                <div class="value <?= $cashAtBank < 0 ? 'neg' : '' ?>"><?= number_format($cashAtBank, 2) ?></div>
                <div class="sub">
                    In bank accounts
                    <?php if ($brTableExists): ?>&middot; <a href="gl_bank_recon.php">Reconcile</a><?php endif; ?>
                </div>
            </div>
            <div class="metric-card orange">
                <div class="label">Owed by Students</div>
                <div class="value"><?= number_format($receivable, 2) ?></div>
                <div class="sub">Fee receivable</div>
            </div>
            <div class="metric-card teal">
                <div class="label">Total Liquidity</div>
                <div class="value"><?= number_format($cashInHand + $cashAtBank, 2) ?></div>
                <div class="sub">Cash + Bank</div>
            </div>
        </div>

        <!-- Obligations -->
        <div class="section-title"><i class="fas fa-file-invoice-dollar"></i> Obligations</div>
        <div class="metric-grid">
            <div class="metric-card red">
                <div class="label">Advance Held</div>
                <div class="value"><?= number_format($advanceLiab, 2) ?></div>
                <div class="sub">Owed to students</div>
            </div>
            <div class="metric-card red">
                <?php if (!$apRecon['ok']): ?><span class="warn-badge"><i class="fas fa-exclamation-triangle"></i> Broken</span><?php endif; ?>
                <div class="label">Accounts Payable</div>
                <div class="value"><?= number_format($payable, 2) ?></div>
                <div class="sub">
                    Owed to <?= (int)$suppliersWithBalance ?> supplier<?= $suppliersWithBalance === 1 ? '' : 's' ?>
                    &middot; <a href="gl_parties.php">Open</a>
                </div>
            </div>
            <div class="metric-card purple">
                <div class="label">Total Obligations</div>
                <div class="value"><?= number_format($advanceLiab + $payable, 2) ?></div>
                <div class="sub">Advance + Payable</div>
            </div>
        </div>

        <!-- Bank Reconciliation -->
        <div class="section-title"><i class="fas fa-exchange-alt"></i> Bank Reconciliation</div>
        <div class="metric-grid">
            <div class="metric-card amber">
                <div class="label">In Progress (Draft)</div>
                <div class="value"><?= (int)$brDraftCount ?></div>
                <div class="sub"><?php if ($brDraftCount > 0): ?><a href="gl_bank_recon.php">Continue</a><?php else: ?>No drafts open<?php endif; ?></div>
            </div>
            <div class="metric-card blue">
                <div class="label">Completed</div>
                <div class="value"><?= (int)$brCompletedCount ?></div>
                <div class="sub">Reconciliations locked</div>
            </div>
            <div class="metric-card <?= $brLatestBalanced ? 'green' : ($brLatestDate ? 'red' : 'amber') ?>">
                <?php if ($brLatestDate && $brLatestBalanced): ?><span class="ok-badge"><i class="fas fa-check"></i> Balanced</span>
                <?php elseif ($brLatestDate): ?><span class="warn-badge"><i class="fas fa-exclamation-triangle"></i> Diff</span><?php endif; ?>
                <div class="label">Last Completed</div>
                <div class="value" style="font-size:13px;"><?= $brLatestDate ? htmlspecialchars($brLatestDate) : '—' ?></div>
                <div class="sub"><?= $brLatestDate ? ($brLatestBalanced ? 'Balanced' : 'Difference') : 'Never reconciled' ?></div>
            </div>
            <div class="metric-card teal">
                <div class="label">Cash at Bank (Ledger)</div>
                <div class="value"><?= number_format($cashAtBank, 2) ?></div>
                <div class="sub"><?php if (!empty($perms['can_add'])): ?><a href="gl_bank_recon_new.php">Start new</a><?php endif; ?></div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════
             ACCOUNTS OVERVIEW — type cards + top accounts strip
             ═══════════════════════════════════════════════════════════ -->
        <div class="section-title" style="margin-top: 22px;">
            <i class="fas fa-sitemap"></i> Accounts Overview
        </div>

        <div class="type-cards">
            <?php foreach ($typeMeta as $tk => $tm):
                $val = $totalByType[$tk];
                $isZero = abs($val) < 0.005;
                $cnt = $countByType[$tk];
            ?>
                <a href="gl_accounts.php?type=<?= $tk ?>" class="type-card" style="border-left-color: <?= $tm['border'] ?>;">
                    <div class="tc-head">
                        <div class="tc-icon" style="background: <?= $tm['color'] ?>;"><i class="fas <?= $tm['icon'] ?>"></i></div>
                        <div class="tc-label"><?= $tm['label'] ?></div>
                    </div>
                    <div class="tc-value <?= $isZero ? 'zero' : ($val < 0 ? 'neg' : '') ?>">
                        <?= $isZero ? '—' : number_format($val, 2) ?>
                    </div>
                    <div class="tc-sub">
                        <?= $cnt ?> account<?= $cnt === 1 ? '' : 's' ?> with balance
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Top accounts by balance -->
        <div class="section-title" style="margin-top: 18px;">
            <i class="fas fa-trophy"></i> Top Accounts by Balance
        </div>

        <div class="top-list">
            <?php if (empty($topAccounts)): ?>
                <div class="empty-state">No accounts with balance yet</div>
            <?php else: ?>
                <?php foreach ($topAccounts as $ta):
                    $meta = $typeMeta[$ta['type']];
                    $isZero = abs($ta['balance']) < 0.005;
                ?>
                    <a href="gl_account_ledger.php?id=<?= (int)$ta['id'] ?>" class="top-item">
                        <span class="type-letter" style="background: <?= $meta['color'] ?>; color:#fff;"><?= $meta['short'] ?></span>
                        <span class="top-code"><?= htmlspecialchars($ta['code']) ?></span>
                        <span class="top-name"><?= htmlspecialchars($ta['name']) ?></span>
                        <span class="top-bal <?= $ta['balance'] < 0 ? 'neg' : '' ?>"><?= $isZero ? '—' : number_format($ta['balance'], 2) ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Quick Links -->
        <div class="section-title" style="margin-top: 22px;"><i class="fas fa-bolt"></i> Reports &amp; Actions</div>
        <div class="quick-grid">
            <a href="gl_parties.php" class="quick"><span class="ico pink"><i class="fas fa-users"></i></span> Suppliers</a>
            <a href="gl_ap_aging.php" class="quick"><span class="ico pink"><i class="fas fa-hourglass-half"></i></span> AP Aging</a>
            <a href="gl_bank_recon.php" class="quick"><span class="ico indigo"><i class="fas fa-exchange-alt"></i></span> Bank Recon</a>
            <?php if (!empty($perms['can_add'])): ?>
                <a href="gl_purchase_add.php" class="quick"><span class="ico amber"><i class="fas fa-shopping-cart"></i></span> Credit Purchase</a>
            <?php endif; ?>
            <?php if (!empty($perms['can_add'])): ?>
                <a href="gl_voucher_add.php" class="quick"><span class="ico amber"><i class="fas fa-receipt"></i></span> New Voucher</a>
            <?php endif; ?>
            <a href="gl_trial_balance.php" class="quick"><span class="ico"><i class="fas fa-list-alt"></i></span> Trial Balance</a>
            <a href="gl_balance_sheet.php" class="quick"><span class="ico"><i class="fas fa-briefcase"></i></span> Balance Sheet</a>
            <a href="gl_pl.php" class="quick"><span class="ico"><i class="fas fa-chart-bar"></i></span> Profit &amp; Loss</a>
            <a href="gl_transactions.php" class="quick"><span class="ico"><i class="fas fa-book"></i></span> Journal Entries</a>
            <a href="gl_quick_ledger.php" class="quick"><span class="ico"><i class="fas fa-bolt"></i></span> Quick Ledger</a>
            <a href="gl_accounts.php" class="quick"><span class="ico"><i class="fas fa-tasks"></i></span> Chart of Accounts</a>
            <a href="gl_opening_balance.php" class="quick"><span class="ico slate"><i class="fas fa-flag-checkered"></i></span> Opening Balance</a>
            <a href="gl_user_activity.php" class="quick"><span class="ico slate"><i class="fas fa-eye"></i></span> User Activity</a>
            <a href="gl_session_close.php" class="quick"><span class="ico teal"><i class="fas fa-lock"></i></span> Session Close</a>
            <a href="gl_fee_defaulters.php" class="quick"><span class="ico pink"><i class="fas fa-exclamation-triangle"></i></span> Fee Defaulters</a>
        </div>

        <!-- Recent Activity -->
        <div class="section-title" style="margin-top: 22px;"><i class="fas fa-history"></i> Recent Activity</div>
        <div class="recent-list">
            <?php if (empty($recent)): ?>
                <div class="empty-state">No recent transactions</div>
            <?php else: ?>
                <?php foreach ($recent as $r): ?>
                    <a href="gl_transaction_view.php?id=<?= (int)$r['id'] ?>" class="recent-item <?= $r['status'] === 'REVERSED' ? 'reversed' : '' ?>">
                        <span class="date"><?= date('M j', strtotime($r['entry_date'])) ?></span>
                        <span class="desc"><?= htmlspecialchars($r['description']) ?></span>
                        <span class="ref"><?= htmlspecialchars($r['reference_type'] ?: 'MANUAL') ?></span>
                        <span class="amt"><?= number_format((float)$r['amt'], 2) ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
<?php $conn->close(); ?>