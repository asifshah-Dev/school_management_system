<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

// Quick health checks
$ledgerCount = (int)$conn->query("SELECT COUNT(*) AS c FROM gl_transactions")->fetch_assoc()['c'];
$lineCount   = (int)$conn->query("SELECT COUNT(*) AS c FROM gl_journal_lines")->fetch_assoc()['c'];
$accountCount= (int)$conn->query("SELECT COUNT(*) AS c FROM gl_accounts")->fetch_assoc()['c'];

$feeMapCount     = (int)$conn->query("SELECT COUNT(*) AS c FROM fee_type_gl_map")->fetch_assoc()['c'];
$expenseMapCount = (int)$conn->query("SELECT COUNT(*) AS c FROM expense_category_gl_map")->fetch_assoc()['c'];
$roleMapCount    = (int)$conn->query("SELECT COUNT(*) AS c FROM role_gl_map")->fetch_assoc()['c'];

$triggerCount = 0;
$res = $conn->query("SHOW TRIGGERS WHERE `Table` IN ('gl_transactions', 'gl_journal_lines')");
while ($res->fetch_assoc()) $triggerCount++;

$procCount = 0;
$res = $conn->query("SHOW PROCEDURE STATUS WHERE Db = DATABASE() AND Name LIKE 'sp_%'");
while ($res->fetch_assoc()) $procCount++;

// Trial balance check
$tbDr = (float)($conn->query("SELECT COALESCE(SUM(debit),0) AS d FROM gl_journal_lines")->fetch_assoc()['d'] ?? 0);
$tbCr = (float)($conn->query("SELECT COALESCE(SUM(credit),0) AS c FROM gl_journal_lines")->fetch_assoc()['c'] ?? 0);
$tbBalanced = abs($tbDr - $tbCr) < 0.01;

// Current session
$sessionRow = $conn->query("SELECT id, title FROM sessions WHERE status = 0 ORDER BY id DESC LIMIT 1")->fetch_assoc();
$currentSession = $sessionRow ? $sessionRow['title'] : 'N/A';

// ---------- SUPPLIER / AP HEALTH ----------
$supplierCount      = (int)$conn->query("SELECT COUNT(*) AS c FROM gl_parties WHERE party_type='SUPPLIER'")->fetch_assoc()['c'];
$activeSupplierCount= (int)$conn->query("SELECT COUNT(*) AS c FROM gl_parties WHERE party_type='SUPPLIER' AND status=1")->fetch_assoc()['c'];
$apRecon            = gl_ap_reconciliation($conn);

$suppliersWithBalance = 0;
foreach (gl_list_suppliers($conn, false) as $s) {
    if (abs((float)$s['ap_balance']) > 0.01) $suppliersWithBalance++;
}

// ---------- SESSION CLOSURE HEALTH ----------
$closuresCount = 0;
$closuresTableExists = false;
$tableCheck = $conn->query("SHOW TABLES LIKE 'gl_session_closures'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $closuresTableExists = true;
    $closuresCount = (int)$conn->query("SELECT COUNT(*) AS c FROM gl_session_closures")->fetch_assoc()['c'];
}

// ---------- BANK RECON HEALTH ----------
$brTableExists = false;
$chkBR = $conn->query("SHOW TABLES LIKE 'bank_reconciliations'");
if ($chkBR && $chkBR->num_rows > 0) $brTableExists = true;

$brTotal = 0;
$brDraft = 0;
$brCompleted = 0;
$brLatestBalanced = false;
if ($brTableExists) {
    $brTotal     = (int)$conn->query("SELECT COUNT(*) AS c FROM bank_reconciliations")->fetch_assoc()['c'];
    $brDraft     = (int)$conn->query("SELECT COUNT(*) AS c FROM bank_reconciliations WHERE status='DRAFT'")->fetch_assoc()['c'];
    $brCompleted = (int)$conn->query("SELECT COUNT(*) AS c FROM bank_reconciliations WHERE status='COMPLETED'")->fetch_assoc()['c'];
    $brLatest = $conn->query("
        SELECT difference FROM bank_reconciliations
        WHERE status='COMPLETED'
        ORDER BY statement_date DESC, id DESC LIMIT 1
    ")->fetch_assoc();
    if ($brLatest) $brLatestBalanced = abs((float)$brLatest['difference']) < 0.01;
}

// Diagnostic flags
$ledgerEmpty = ($ledgerCount === 0 && $lineCount === 0);
$coaSeeded   = ($accountCount >= 40);
$mapsSeeded  = ($feeMapCount > 0 && $expenseMapCount > 0 && $roleMapCount > 0);
$engineReady = ($triggerCount === 4 && $procCount === 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>GL Test Hub — All Links</title>
    <style>
        * { box-sizing: border-box; }
        body { background: #eef1f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; }

        .hub { max-width: 1200px; margin: 30px auto; padding: 0 20px; }

        .hub-head {
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 20px;
            flex-wrap: wrap;
        }
        .hub-head h1 { margin: 0; font-size: 30px; font-weight: 700; color: #1e293b; }
        .hub-head .sub { color: #64748b; font-size: 14px; margin-top: 4px; }

        .btn-danger-soft {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 18px;
            background: #fee2e2; color: #991b1b;
            border: 1px solid #fecaca;
            border-radius: 8px;
            font-size: 14px; font-weight: 600;
            cursor: pointer; font-family: inherit;
            transition: all 0.15s;
        }
        .btn-danger-soft:hover { background: #fecaca; }

        .status-board {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 14px;
            margin-bottom: 36px;
        }
        .status-tile {
            background: #fff;
            border-radius: 10px;
            padding: 16px 18px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            border-left: 4px solid #94a3b8;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .status-tile.ok      { border-left-color: #059669; }
        .status-tile.warn    { border-left-color: #f59e0b; }
        .status-tile.err     { border-left-color: #dc2626; }
        .status-tile .label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            color: #64748b;
            font-weight: 700;
        }
        .status-tile .value {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            font-family: 'SF Mono', 'Monaco', monospace;
        }
        .status-tile .sub { font-size: 12px; color: #94a3b8; }
        .status-pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-pill.ok  { background: #d1fae5; color: #065f46; }
        .status-pill.bad { background: #fee2e2; color: #991b1b; }
        .status-pill.warn { background: #fef3c7; color: #92400e; }

        .section-title {
            font-size: 12px;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin: 32px 0 14px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .section-title::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #cbd5e1;
        }

        .link-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 14px;
        }

        .link-card {
            background: #fff;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            padding: 18px 20px;
            text-decoration: none;
            color: #1e293b;
            display: flex;
            align-items: flex-start;
            gap: 14px;
            transition: all 0.15s;
            position: relative;
        }
        .link-card:hover {
            border-color: #1e40af;
            background: #f0f5ff;
            text-decoration: none;
            color: #1e293b;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(30, 64, 175, 0.1);
        }

        .link-card .icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: #e0e7ff;
            color: #1e40af;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            flex-shrink: 0;
        }
        .link-card.operational .icon { background: #fef3c7; color: #b45309; }
        .link-card.mapping .icon     { background: #d1fae5; color: #047857; }
        .link-card.admin .icon       { background: #f3e8ff; color: #6b21a8; }
        .link-card.report .icon      { background: #dbeafe; color: #1e40af; }
        .link-card.danger .icon      { background: #fee2e2; color: #b91c1c; }
        .link-card.supplier .icon    { background: #fce7f3; color: #9d174d; }
        .link-card.session .icon     { background: #cffafe; color: #0e7490; }
        .link-card.bank .icon        { background: #e0e7ff; color: #4338ca; }

        .link-card .body { flex: 1; min-width: 0; }
        .link-card .title { font-size: 15px; font-weight: 700; color: #0f172a; margin-bottom: 4px; }
        .link-card .desc { font-size: 12px; color: #64748b; line-height: 1.5; }
        .link-card .url {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 11px;
            color: #94a3b8;
            margin-top: 6px;
            word-break: break-all;
        }

        .footer-note {
            margin-top: 40px;
            padding: 20px 24px;
            background: #f8fafc;
            border-radius: 10px;
            font-size: 12px;
            color: #64748b;
            line-height: 1.7;
            border: 1px solid #e2e8f0;
        }
        .footer-note strong { color: #1e293b; }
        .footer-note code { background: #e2e8f0; padding: 1px 5px; border-radius: 3px; }

        /* ============ Reset Modal ============ */
        .modal-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(15, 23, 42, 0.6);
            z-index: 9999;
            align-items: flex-start; justify-content: center;
            padding: 40px 20px;
            overflow-y: auto;
        }
        .modal-overlay.open { display: flex; }

        .modal-box {
            background: #fff;
            width: 100%; max-width: 620px;
            border-radius: 12px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.3);
            overflow: hidden;
            animation: modalIn 0.15s ease-out;
        }
        @keyframes modalIn {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .modal-head {
            display: flex; justify-content: space-between; align-items: center;
            padding: 18px 24px;
            background: linear-gradient(135deg, #991b1b 0%, #b91c1c 100%);
            color: #fff;
        }
        .modal-head h3 { margin: 0; font-size: 17px; font-weight: 700; }
        .modal-head .modal-sub { font-size: 12px; opacity: 0.85; margin-top: 2px; }
        .modal-close {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.3);
            color: #fff;
            width: 32px; height: 32px;
            border-radius: 8px;
            font-size: 18px; line-height: 1;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
        }
        .modal-close:hover { background: #fff; color: #991b1b; }
        .modal-body { padding: 22px 24px 8px 24px; }
        .modal-foot {
            display: flex; justify-content: flex-end; gap: 10px;
            padding: 16px 24px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
        }

        .reset-option {
            display: flex; gap: 10px;
            padding: 14px 16px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            margin-bottom: 10px;
            cursor: pointer;
        }
        .reset-option:hover { border-color: #cbd5e1; background: #f1f5f9; }
        .reset-option input[type="radio"] { margin-top: 4px; cursor: pointer; }
        .reset-option label { cursor: pointer; flex: 1; }
        .reset-option label strong { display: block; font-size: 14px; color: #0f172a; margin-bottom: 4px; }
        .reset-option .scope-desc { display: block; font-size: 12px; color: #64748b; line-height: 1.5; }
        .reset-option .scope-desc code { background: #e2e8f0; padding: 1px 5px; border-radius: 3px; font-size: 11px; }

        .confirm-block {
            margin-top: 20px;
            padding: 16px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 10px;
        }
        .confirm-block label {
            display: block;
            font-size: 12px; font-weight: 700; color: #991b1b;
            text-transform: uppercase; letter-spacing: 0.8px;
            margin-bottom: 8px;
        }
        .confirm-text {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 15px; font-weight: 700;
            color: #991b1b;
            padding: 8px 12px;
            background: #fff;
            border: 1px solid #fecaca;
            border-radius: 6px;
            margin-bottom: 10px;
            transition: all 0.15s;
        }
        .confirm-block input[type="text"] {
            width: 100%; height: 40px;
            padding: 8px 12px;
            border: 1px solid #cbd5e1; border-radius: 8px;
            font-size: 14px; font-family: 'SF Mono', monospace;
        }
        .confirm-block input:focus {
            outline: none; border-color: #991b1b;
            box-shadow: 0 0 0 3px rgba(153, 27, 27, 0.1);
        }

        .feedback {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 13px;
            margin-top: 14px;
            line-height: 1.6;
        }
        .feedback.ok  { background: #d1fae5; border: 1px solid #a7f3d0; color: #065f46; }
        .feedback.err { background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; }

        .btn-ghost-custom {
            height: 40px; padding: 0 20px;
            background: #fff; color: #334155;
            border: 1px solid #cbd5e1; border-radius: 8px;
            font-size: 13px; font-weight: 600;
            cursor: pointer; font-family: inherit;
        }
        .btn-ghost-custom:hover { background: #f1f5f9; }

        .btn-danger {
            height: 40px; padding: 0 20px;
            background: #991b1b; color: #fff;
            border: none; border-radius: 8px;
            font-size: 13px; font-weight: 600;
            cursor: pointer; font-family: inherit;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-danger:hover:not(:disabled) { background: #7f1d1d; }
        .btn-danger:disabled { opacity: 0.45; cursor: not-allowed; }

        @media (max-width: 600px) {
            .hub-head h1 { font-size: 22px; }
            .link-grid { grid-template-columns: 1fr; }
            .hub-head { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="hub">

    <div class="hub-head">
        <div>
            <h1>GL Test Hub</h1>
            <div class="sub">
                Every page in one place &middot; Session: <strong><?php echo htmlspecialchars($currentSession); ?></strong> &middot; <?php echo date('l, F j, Y'); ?>
            </div>
        </div>
        <button type="button" class="btn-danger-soft" onclick="openResetPanel()">
            <span class="glyphicon glyphicon-refresh"></span> Reset Ledger
        </button>
    </div>

    <!-- ===== System Status ===== -->
    <div class="status-board">
        <div class="status-tile <?php echo $engineReady ? 'ok' : 'err'; ?>">
            <div class="label">Ledger Engine</div>
            <div class="value"><?php echo $triggerCount; ?> triggers, <?php echo $procCount; ?> procedures</div>
            <div class="sub">
                <?php if ($engineReady): ?>
                    <span class="status-pill ok">Ready</span>
                <?php else: ?>
                    <span class="status-pill bad">Incomplete</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="status-tile <?php echo $coaSeeded ? 'ok' : 'err'; ?>">
            <div class="label">Chart of Accounts</div>
            <div class="value"><?php echo $accountCount; ?> accounts</div>
            <div class="sub">
                <?php if ($coaSeeded): ?>
                    <span class="status-pill ok">Seeded</span>
                <?php else: ?>
                    <span class="status-pill bad">Empty</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="status-tile <?php echo $mapsSeeded ? 'ok' : 'warn'; ?>">
            <div class="label">Mappings</div>
            <div class="value"><?php echo $feeMapCount + $expenseMapCount + $roleMapCount; ?> rows</div>
            <div class="sub">
                Fee: <?php echo $feeMapCount; ?> &middot;
                Expense: <?php echo $expenseMapCount; ?> &middot;
                Role: <?php echo $roleMapCount; ?>
            </div>
        </div>

        <div class="status-tile <?php echo $tbBalanced ? 'ok' : 'err'; ?>">
            <div class="label">Trial Balance</div>
            <div class="value"><?php echo $tbBalanced ? '✓ Balanced' : '✗ Off'; ?></div>
            <div class="sub">
                <?php if ($tbBalanced): ?>
                    <span class="status-pill ok">Debits = Credits</span>
                <?php else: ?>
                    <span class="status-pill bad">Diff <?php echo number_format(abs($tbDr - $tbCr), 2); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="status-tile <?php echo $ledgerEmpty ? 'warn' : 'ok'; ?>">
            <div class="label">Ledger State</div>
            <div class="value"><?php echo $ledgerCount; ?> txns / <?php echo $lineCount; ?> lines</div>
            <div class="sub">
                <?php if ($ledgerEmpty): ?>
                    <span class="status-pill warn">Awaiting Opening Balance</span>
                <?php else: ?>
                    <span class="status-pill ok">In Use</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="status-tile <?php echo $apRecon['ok'] ? 'ok' : 'err'; ?>">
            <div class="label">Suppliers / AP</div>
            <div class="value"><?php echo $activeSupplierCount; ?> active</div>
            <div class="sub">
                <?php if ($apRecon['ok']): ?>
                    <span class="status-pill ok">Sub-ledger OK</span>
                <?php else: ?>
                    <span class="status-pill bad">Broken (diff <?php echo number_format($apRecon['diff'], 2); ?>)</span>
                <?php endif; ?>
                &middot; <?php echo $suppliersWithBalance; ?> with balance
            </div>
        </div>

        <div class="status-tile <?php
            if (!$closuresTableExists) echo 'err';
            elseif ($closuresCount > 0)  echo 'ok';
            else                          echo 'warn';
        ?>">
            <div class="label">Session Closures</div>
            <div class="value"><?php echo $closuresCount; ?> snapshot<?php echo $closuresCount === 1 ? '' : 's'; ?></div>
            <div class="sub">
                <?php if (!$closuresTableExists): ?>
                    <span class="status-pill bad">Table missing</span>
                <?php elseif ($closuresCount > 0): ?>
                    <span class="status-pill ok">Snapshots recorded</span>
                <?php else: ?>
                    <span class="status-pill warn">None yet</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Bank Reconciliation tile -->
        <div class="status-tile <?php
            if (!$brTableExists)          echo 'err';
            elseif ($brDraft > 0)         echo 'warn';
            elseif ($brCompleted > 0)     echo 'ok';
            else                          echo 'warn';
        ?>">
            <div class="label">Bank Reconciliation</div>
            <div class="value"><?php echo $brTotal; ?> total</div>
            <div class="sub">
                <?php if (!$brTableExists): ?>
                    <span class="status-pill bad">Table missing</span>
                <?php elseif ($brDraft > 0): ?>
                    <span class="status-pill warn"><?php echo $brDraft; ?> draft</span>
                <?php elseif ($brCompleted > 0): ?>
                    <span class="status-pill ok"><?php echo $brCompleted; ?> done</span>
                <?php else: ?>
                    <span class="status-pill warn">None yet</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ===== Bank Reconciliation ===== -->
    <div class="section-title">Bank Reconciliation</div>
    <div class="link-grid">

        <a href="gl_bank_recon.php" class="link-card bank">
            <div class="icon"><span class="glyphicon glyphicon-transfer"></span></div>
            <div class="body">
                <div class="title">Bank Reconciliation</div>
                <div class="desc">Match your ledger (1020) against the bank statement. Match lines, mark outstanding items, post adjustments for bank charges/interest. Complete and print.</div>
                <div class="url">gl_bank_recon.php &middot; <?php echo $brTotal; ?> total, <?php echo $brDraft; ?> draft</div>
            </div>
        </a>

        <a href="gl_bank_recon_new.php" class="link-card bank">
            <div class="icon"><span class="glyphicon glyphicon-plus"></span></div>
            <div class="body">
                <div class="title">New Bank Reconciliation</div>
                <div class="desc">Start a fresh reconciliation. Enter the statement date and closing balance from the bank, then paste or add statement lines.</div>
                <div class="url">gl_bank_recon_new.php</div>
            </div>
        </a>

    </div>

    <!-- ===== Suppliers & Payables ===== -->
    <div class="section-title">Suppliers &amp; Payables</div>
    <div class="link-grid">

        <a href="gl_parties.php" class="link-card supplier">
            <div class="icon"><span class="glyphicon glyphicon-user"></span></div>
            <div class="body">
                <div class="title">Suppliers</div>
                <div class="desc">Register suppliers, view live AP balance per supplier, toggle active status, drill into a ledger.</div>
                <div class="url">gl_parties.php &middot; <?php echo $supplierCount; ?> total</div>
            </div>
        </a>

        <a href="gl_purchase_add.php" class="link-card supplier">
            <div class="icon"><span class="glyphicon glyphicon-shopping-cart"></span></div>
            <div class="body">
                <div class="title">New Credit Purchase</div>
                <div class="desc">Record goods/services received on credit. Posts Dr Expense, Cr 2010 AP tagged to the supplier. <strong>Increases what the school owes.</strong></div>
                <div class="url">gl_purchase_add.php</div>
            </div>
        </a>

        <a href="gl_voucher_add.php" class="link-card supplier">
            <div class="icon"><span class="glyphicon glyphicon-duplicate"></span></div>
            <div class="body">
                <div class="title">Pay a Supplier</div>
                <div class="desc">Post a CPV/BPV against <code>2010 Accounts Payable</code>. A supplier dropdown appears. <strong>Decreases what the school owes.</strong></div>
                <div class="url">gl_voucher_add.php &rarr; account 2010</div>
            </div>
        </a>

        <a href="gl_ap_aging.php" class="link-card supplier">
            <div class="icon"><span class="glyphicon glyphicon-time"></span></div>
            <div class="body">
                <div class="title">AP Aging Report</div>
                <div class="desc">Outstanding supplier balances bucketed by 0–30 / 31–60 / 61–90 / 90+ days.</div>
                <div class="url">gl_ap_aging.php</div>
            </div>
        </a>

        <a href="gl_transactions.php?ref_type=purchase" class="link-card supplier">
            <div class="icon"><span class="glyphicon glyphicon-list"></span></div>
            <div class="body">
                <div class="title">Credit Purchase Entries</div>
                <div class="desc">Filtered view of all supplier bills posted via the credit-purchase page.</div>
                <div class="url">gl_transactions.php?ref_type=purchase</div>
            </div>
        </a>

    </div>

    <!-- ===== Reports ===== -->
    <div class="section-title">Reports</div>
    <div class="link-grid">

        <a href="gl_dashboard.php" class="link-card report">
            <div class="icon"><span class="glyphicon glyphicon-dashboard"></span></div>
            <div class="body">
                <div class="title">Accounting Dashboard</div>
                <div class="desc">One-page overview: cash, receivables, obligations, net profit, recent activity. Includes AP sub-ledger health banner.</div>
                <div class="url">gl_dashboard.php</div>
            </div>
        </a>

        <a href="gl_trial_balance.php" class="link-card report">
            <div class="icon"><span class="glyphicon glyphicon-list-alt"></span></div>
            <div class="body">
                <div class="title">Trial Balance</div>
                <div class="desc">Opening, movement, and closing balances per account. Click any account to drill into its ledger.</div>
                <div class="url">gl_trial_balance.php</div>
            </div>
        </a>

        <a href="gl_balance_sheet.php" class="link-card report">
            <div class="icon"><span class="glyphicon glyphicon-briefcase"></span></div>
            <div class="body">
                <div class="title">Balance Sheet</div>
                <div class="desc">Assets = Liabilities + Equity. Includes a "change" column showing movement since a prior date.</div>
                <div class="url">gl_balance_sheet.php</div>
            </div>
        </a>

        <a href="gl_pl.php" class="link-card report">
            <div class="icon"><span class="glyphicon glyphicon-signal"></span></div>
            <div class="body">
                <div class="title">Profit &amp; Loss</div>
                <div class="desc">Revenue minus expenses for a date range. Shows net profit or loss.</div>
                <div class="url">gl_pl.php</div>
            </div>
        </a>

        <a href="gl_accounts.php" class="link-card report">
            <div class="icon"><span class="glyphicon glyphicon-tasks"></span></div>
            <div class="body">
                <div class="title">Chart of Accounts</div>
                <div class="desc">All accounts with current balances. Tree view by type.</div>
                <div class="url">gl_accounts.php</div>
            </div>
        </a>

        <a href="gl_quick_ledger.php" class="link-card report">
            <div class="icon"><span class="glyphicon glyphicon-flash"></span></div>
            <div class="body">
                <div class="title">Quick Ledger</div>
                <div class="desc">Summary of account activity with key financial metrics.</div>
                <div class="url">gl_quick_ledger.php</div>
            </div>
        </a>

        <a href="gl_vouchers.php" class="link-card report">
            <div class="icon"><span class="glyphicon glyphicon-file"></span></div>
            <div class="body">
                <div class="title">All Vouchers</div>
                <div class="desc">List every CPV, CRV, BPV, BRV. Filter by type, status, date.</div>
                <div class="url">gl_vouchers.php</div>
            </div>
        </a>

        <a href="gl_opening_balance.php" class="link-card report">
            <div class="icon"><span class="glyphicon glyphicon-cog"></span></div>
            <div class="body">
                <div class="title">Opening Balance</div>
                <div class="desc">Create and manage opening balance entries.</div>
                <div class="url">gl_opening_balance.php</div>
            </div>
        </a>

    </div>

    <!-- ===== Ledger ===== -->
    <div class="section-title">Ledger</div>
    <div class="link-grid">

        <a href="gl_transactions.php" class="link-card admin">
            <div class="icon"><span class="glyphicon glyphicon-book"></span></div>
            <div class="body">
                <div class="title">Journal Entries</div>
                <div class="desc">Every posted transaction. Filter by date, session, reference type, status. Search descriptions and memos.</div>
                <div class="url">gl_transactions.php</div>
            </div>
        </a>

        <a href="gl_journal_add.php" class="link-card admin">
            <div class="icon"><span class="glyphicon glyphicon-plus"></span></div>
            <div class="body">
                <div class="title">Post Manual Entry</div>
                <div class="desc">Post a balanced journal entry directly. Use this for opening balances, corrections, and adjustments.</div>
                <div class="url">gl_journal_add.php</div>
            </div>
        </a>

        <a href="gl_transactions.php?ref_type=opening_balance" class="link-card admin">
            <div class="icon"><span class="glyphicon glyphicon-flag"></span></div>
            <div class="body">
                <div class="title">Opening Balance Entries</div>
                <div class="desc">Filtered view of opening-balance transactions only.</div>
                <div class="url">gl_transactions.php?ref_type=opening_balance</div>
            </div>
        </a>

        <a href="gl_transactions.php?ref_type=bank_recon" class="link-card admin">
            <div class="icon"><span class="glyphicon glyphicon-transfer"></span></div>
            <div class="body">
                <div class="title">Bank Recon Adjustments</div>
                <div class="desc">Filtered view of journal entries posted from bank reconciliations.</div>
                <div class="url">gl_transactions.php?ref_type=bank_recon</div>
            </div>
        </a>

        <a href="gl_transactions.php?ref_type=fee_payment" class="link-card admin">
            <div class="icon"><span class="glyphicon glyphicon-usd"></span></div>
            <div class="body">
                <div class="title">Fee Payment Entries</div>
                <div class="desc">Filtered view of fee payments only.</div>
                <div class="url">gl_transactions.php?ref_type=fee_payment</div>
            </div>
        </a>

    </div>

    <!-- ===== Session Management & Audit ===== -->
    <div class="section-title">Session Management &amp; Audit</div>
    <div class="link-grid">

        <a href="gl_session_close.php" class="link-card session">
            <div class="icon"><span class="glyphicon glyphicon-lock"></span></div>
            <div class="body">
                <div class="title">Session Close</div>
                <div class="desc">Freeze a session and record a permanent snapshot: revenue, expenses, net profit, assets, liabilities, equity. Two-step preview then confirm.</div>
                <div class="url">gl_session_close.php &middot; <?php echo $closuresCount; ?> snapshot<?php echo $closuresCount === 1 ? '' : 's'; ?></div>
            </div>
        </a>

        <a href="gl_user_activity.php" class="link-card session">
            <div class="icon"><span class="glyphicon glyphicon-eye-open"></span></div>
            <div class="body">
                <div class="title">User Activity</div>
                <div class="desc">Pick a user and a date range — see every journal entry they posted, every voucher they created, and every reversal they performed.</div>
                <div class="url">gl_user_activity.php</div>
            </div>
        </a>

    </div>

    <!-- ===== Chart of Accounts Admin ===== -->
    <div class="section-title">Chart of Accounts — Admin</div>
    <div class="link-grid">

        <a href="gl_account_add.php" class="link-card admin">
            <div class="icon"><span class="glyphicon glyphicon-plus-sign"></span></div>
            <div class="body">
                <div class="title">Add Account</div>
                <div class="desc">Create a new GL account. Choose type, code, parent, and postable flag.</div>
                <div class="url">gl_account_add.php</div>
            </div>
        </a>

        <a href="gl_accounts.php?type=ASSET" class="link-card admin">
            <div class="icon"><span class="glyphicon glyphicon-home"></span></div>
            <div class="body">
                <div class="title">Assets Only</div>
                <div class="desc">Filtered view of Asset accounts.</div>
                <div class="url">gl_accounts.php?type=ASSET</div>
            </div>
        </a>

        <a href="gl_accounts.php?type=LIABILITY" class="link-card admin">
            <div class="icon"><span class="glyphicon glyphicon-transfer"></span></div>
            <div class="body">
                <div class="title">Liabilities Only</div>
                <div class="desc">Filtered view of Liability accounts.</div>
                <div class="url">gl_accounts.php?type=LIABILITY</div>
            </div>
        </a>

        <a href="gl_accounts.php?type=REVENUE" class="link-card admin">
            <div class="icon"><span class="glyphicon glyphicon-usd"></span></div>
            <div class="body">
                <div class="title">Revenue Only</div>
                <div class="desc">Filtered view of Revenue accounts.</div>
                <div class="url">gl_accounts.php?type=REVENUE</div>
            </div>
        </a>

        <a href="gl_accounts.php?type=EXPENSE" class="link-card admin">
            <div class="icon"><span class="glyphicon glyphicon-shopping-cart"></span></div>
            <div class="body">
                <div class="title">Expenses Only</div>
                <div class="desc">Filtered view of Expense accounts.</div>
                <div class="url">gl_accounts.php?type=EXPENSE</div>
            </div>
        </a>

    </div>

    <!-- ===== Mappings ===== -->
    <div class="section-title">Mappings — What Posts Where</div>
    <div class="link-grid">

        <a href="gl_fee_type_map.php" class="link-card mapping">
            <div class="icon"><span class="glyphicon glyphicon-tags"></span></div>
            <div class="body">
                <div class="title">Fee Type → Revenue Account</div>
                <div class="desc">Which revenue account each fee type posts to. <strong>Must be complete before generating fee cards.</strong></div>
                <div class="url">gl_fee_type_map.php &middot; <?php echo $feeMapCount; ?> mapped</div>
            </div>
        </a>

        <a href="gl_expense_category_map.php" class="link-card mapping">
            <div class="icon"><span class="glyphicon glyphicon-list"></span></div>
            <div class="body">
                <div class="title">Expense Category → Expense Account</div>
                <div class="desc">Which expense account each category posts to. <strong>Required before saving any expense.</strong></div>
                <div class="url">gl_expense_category_map.php &middot; <?php echo $expenseMapCount; ?> mapped</div>
            </div>
        </a>

        <a href="gl_role_map.php" class="link-card mapping">
            <div class="icon"><span class="glyphicon glyphicon-user"></span></div>
            <div class="body">
                <div class="title">Role → Salary Account</div>
                <div class="desc">Which expense account each role's salary posts to. <strong>Required before saving any salary.</strong></div>
                <div class="url">gl_role_map.php &middot; <?php echo $roleMapCount; ?> mapped</div>
            </div>
        </a>

    </div>

    <!-- ===== Operational Pages ===== -->
    <div class="section-title">Operational Pages — Where Data Flows In</div>
    <div class="link-grid">

        <a href="fee_card_create_monthly.php" class="link-card operational">
            <div class="icon"><span class="glyphicon glyphicon-calendar"></span></div>
            <div class="body">
                <div class="title">Generate Monthly Fee Cards</div>
                <div class="desc">Creates fee cards for a class + month. Each card posts a receivable entry to the ledger.</div>
                <div class="url">fee_card_create_monthly.php</div>
            </div>
        </a>

        <a href="student_list.php" class="link-card operational">
            <div class="icon"><span class="glyphicon glyphicon-education"></span></div>
            <div class="body">
                <div class="title">Student List</div>
                <div class="desc">Pick a student to see their fee cards. Click into fee_collection.php from there.</div>
                <div class="url">student_list.php</div>
            </div>
        </a>

        <a href="advance_fee_payment.php" class="link-card operational">
            <div class="icon"><span class="glyphicon glyphicon-forward"></span></div>
            <div class="body">
                <div class="title">Advance Fee Payment</div>
                <div class="desc">Record advance fees. Posts to Advance Fee Received (liability).</div>
                <div class="url">advance_fee_payment.php (needs ?id=student_id)</div>
            </div>
        </a>

        <a href="salary_management.php" class="link-card operational">
            <div class="icon"><span class="glyphicon glyphicon-piggy-bank"></span></div>
            <div class="body">
                <div class="title">Salary Management</div>
                <div class="desc">Generate and pay salaries. Each salary posts a Dr to a role-mapped expense account.</div>
                <div class="url">salary_management.php</div>
            </div>
        </a>

        <a href="expenses.php" class="link-card operational">
            <div class="icon"><span class="glyphicon glyphicon-shopping-cart"></span></div>
            <div class="body">
                <div class="title">Expense Invoicing</div>
                <div class="desc">Record expenses. Optionally pick a supplier → posts to AP. Otherwise immediate cash/bank payment.</div>
                <div class="url">expenses.php</div>
            </div>
        </a>

    </div>

    <!-- ===== Master Tables ===== -->
    <div class="section-title">Master Tables — Manage Fee Types, Categories, Roles</div>
    <div class="link-grid">

        <a href="fee_types.php" class="link-card admin">
            <div class="icon"><span class="glyphicon glyphicon-tag"></span></div>
            <div class="body">
                <div class="title">Fee Types</div>
                <div class="desc">Add or edit the master list of fee types. Then map them to GL accounts.</div>
                <div class="url">fee_types.php</div>
            </div>
        </a>

        <a href="expense_heads.php" class="link-card admin">
            <div class="icon"><span class="glyphicon glyphicon-list-alt"></span></div>
            <div class="body">
                <div class="title">Expense Heads (Categories)</div>
                <div class="desc">Add or edit expense categories. Then map them to GL accounts.</div>
                <div class="url">expense_heads.php</div>
            </div>
        </a>

        <a href="roles.php" class="link-card admin">
            <div class="icon"><span class="glyphicon glyphicon-lock"></span></div>
            <div class="body">
                <div class="title">Roles</div>
                <div class="desc">Manage user roles. Then map them to salary GL accounts.</div>
                <div class="url">roles.php</div>
            </div>
        </a>

    </div>

    <!-- ===== Testing Utilities ===== -->
    <div class="section-title">Testing Utilities</div>
    <div class="link-grid">

        <a href="gl_transactions.php?status=REVERSED" class="link-card danger">
            <div class="icon"><span class="glyphicon glyphicon-refresh"></span></div>
            <div class="body">
                <div class="title">View Reversed Transactions</div>
                <div class="desc">All transactions that have been reversed. Useful for verifying corrections.</div>
                <div class="url">gl_transactions.php?status=REVERSED</div>
            </div>
        </a>

        <a href="gl_balance_sheet.php?from=<?php echo date('Y-01-01'); ?>&to=<?php echo date('Y-m-d'); ?>" class="link-card danger">
            <div class="icon"><span class="glyphicon glyphicon-stats"></span></div>
            <div class="body">
                <div class="title">Balance Sheet — Year to Date</div>
                <div class="desc">Balance Sheet with a full year's change column.</div>
                <div class="url">gl_balance_sheet.php?from=Y-01-01&amp;to=today</div>
            </div>
        </a>

        <a href="gl_pl.php?from=<?php echo date('Y-m-01'); ?>&to=<?php echo date('Y-m-d'); ?>" class="link-card danger">
            <div class="icon"><span class="glyphicon glyphicon-time"></span></div>
            <div class="body">
                <div class="title">P&amp;L — This Month</div>
                <div class="desc">Profit &amp; Loss restricted to the current month.</div>
                <div class="url">gl_pl.php?from=Y-m-01&amp;to=today</div>
            </div>
        </a>

    </div>

    <div class="footer-note">
        <strong>How to use this page:</strong>
        <ul style="margin: 8px 0 0 20px; padding: 0;">
            <li>Bookmark this page as your home for the ledger system.</li>
            <li>The status board at the top shows the health of the entire engine at a glance &mdash; including <strong>Suppliers/AP</strong>, <strong>Bank Reconciliation</strong>, and <strong>Session Closures</strong>.</li>
            <li>Reports read the ledger. Admin pages define the Chart of Accounts. Mappings connect operational data to GL accounts.</li>
            <li><strong>Suppliers &amp; Payables:</strong> use <code>gl_purchase_add.php</code> to record bills (AP up), and <code>gl_voucher_add.php</code> against account 2010 to record payments (AP down).</li>
            <li><strong>Bank Reconciliation:</strong> use <code>gl_bank_recon.php</code> to match your bank statement against the ledger, post adjustments, and lock a completed reconciliation.</li>
            <li><strong>Session Management:</strong> use <code>gl_session_close.php</code> to freeze a session at year end and record a permanent financial snapshot.</li>
            <li><strong>Audit:</strong> use <code>gl_user_activity.php</code> to review any user's postings, vouchers, and reversals.</li>
            <li><strong>Reset Ledger:</strong> top-right button wipes test data. <strong>Remove this page and <code>gl_test_hub_reset.php</code> before handing over to a real client.</strong></li>
        </ul>
    </div>

</div>

<!-- ============ Reset Ledger Modal ============ -->
<div class="modal-overlay" id="resetModal">
    <div class="modal-box">
        <div class="modal-head">
            <div>
                <h3>⚠ Reset Ledger Data</h3>
                <div class="modal-sub">This cannot be undone from the UI. Take a backup first.</div>
            </div>
            <button type="button" class="modal-close" onclick="closeResetPanel()">&times;</button>
        </div>
        <div class="modal-body">

            <p style="font-size:13px; color:#475569; margin-top:0;">
                Choose what to wipe. In both cases, <strong>the Chart of Accounts, Suppliers, and Mapping tables are preserved.</strong>
            </p>

            <div class="reset-option" data-scope="ledger_only">
                <input type="radio" name="reset_scope" value="ledger_only" id="rs_ledger">
                <label for="rs_ledger">
                    <strong>Ledger only</strong>
                    <span class="scope-desc">
                        Wipes <code>gl_transactions</code>, <code>gl_journal_lines</code>, <code>vouchers</code>,
                        <code>voucher_counters</code>, and <code>gl_session_closures</code>.
                        Leaves operational data untouched.
                    </span>
                </label>
            </div>

            <div class="reset-option" data-scope="ledger_and_operational">
                <input type="radio" name="reset_scope" value="ledger_and_operational" id="rs_all">
                <label for="rs_all">
                    <strong>Ledger + Operational (full clean slate)</strong>
                    <span class="scope-desc">
                        Everything above, <em>plus</em> <code>expenses</code>, <code>expanse_inventory_details</code>,
                        <code>student_fee_card</code>, <code>fee_payments</code>, <code>advance_fees</code>, and <code>user_salary</code>.
                        <strong style="color:#991b1b;">Use this only while testing — real fee/salary history will be destroyed.</strong>
                    </span>
                </label>
            </div>

            <div class="confirm-block">
                <label>Type this exact text to confirm:</label>
                <div class="confirm-text" id="confirm_expected">— select a scope —</div>
                <input type="text" id="confirm_input" placeholder="Type the text above" autocomplete="off">
            </div>

            <div id="resetFeedback" class="feedback" style="display:none;"></div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn-ghost-custom" onclick="closeResetPanel()">Cancel / Not now</button>
            <button type="button" class="btn-danger" id="btnConfirmReset" onclick="doReset()" disabled>
                <span class="glyphicon glyphicon-trash"></span> Confirm Reset
            </button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
<script>
var resetModal = document.getElementById('resetModal');
var confirmInput = document.getElementById('confirm_input');
var confirmExpected = document.getElementById('confirm_expected');
var btnConfirm = document.getElementById('btnConfirmReset');
var feedback = document.getElementById('resetFeedback');

function getScope() {
    var checked = document.querySelector('input[name="reset_scope"]:checked');
    return checked ? checked.value : '';
}

function updateExpectedText() {
    var scope = getScope();
    if (!scope) {
        confirmExpected.textContent = '— select a scope —';
        confirmExpected.style.background = '#fff';
        confirmExpected.style.color = '#991b1b';
        confirmExpected.style.borderColor = '#fecaca';
        confirmInput.value = '';
        btnConfirm.disabled = true;
        return;
    }

    var expected = 'RESET-' + scope.toUpperCase();
    confirmExpected.textContent = expected;
    confirmInput.value = '';
    btnConfirm.disabled = true;

    if (scope === 'ledger_and_operational') {
        confirmExpected.style.background = '#7f1d1d';
        confirmExpected.style.color = '#fff';
        confirmExpected.style.borderColor = '#7f1d1d';
    } else {
        confirmExpected.style.background = '#fff';
        confirmExpected.style.color = '#991b1b';
        confirmExpected.style.borderColor = '#fecaca';
    }
}

document.querySelectorAll('input[name="reset_scope"]').forEach(function(r){
    r.addEventListener('change', updateExpectedText);
});

confirmInput.addEventListener('input', function(){
    var scope = getScope();
    if (!scope) return;
    var expected = 'RESET-' + scope.toUpperCase();
    btnConfirm.disabled = (this.value.trim() !== expected);
});

function openResetPanel() {
    document.querySelectorAll('input[name="reset_scope"]').forEach(function(r){ r.checked = false; });
    updateExpectedText();
    feedback.style.display = 'none';
    resetModal.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeResetPanel() {
    resetModal.classList.remove('open');
    document.body.style.overflow = '';
}

resetModal.addEventListener('click', function(e){
    if (e.target === resetModal) closeResetPanel();
});
document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && resetModal.classList.contains('open')) closeResetPanel();
});

function doReset() {
    var scope = getScope();
    if (!scope) { alert('Select a scope.'); return; }

    var expected = 'RESET-' + scope.toUpperCase();
    if (confirmInput.value.trim() !== expected) {
        alert('Confirmation text does not match.');
        return;
    }

    if (!confirm(
        'FINAL WARNING\n\n' +
        'You are about to delete ledger data.\n' +
        'Scope: ' + scope + '\n\n' +
        'This CANNOT be undone. Continue?'
    )) return;

    btnConfirm.disabled = true;
    btnConfirm.innerHTML = '<span class="glyphicon glyphicon-refresh"></span> Resetting...';
    feedback.style.display = 'none';

    var fd = new FormData();
    fd.append('scope', scope);
    fd.append('confirm', expected);

    fetch('gl_test_hub_reset.php', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(resp){
            btnConfirm.disabled = false;
            btnConfirm.innerHTML = '<span class="glyphicon glyphicon-trash"></span> Confirm Reset';

            if (!resp.ok) {
                feedback.className = 'feedback err';
                feedback.innerHTML = '<strong>Reset failed:</strong> ' + (resp.error || 'unknown');
                feedback.style.display = 'block';
                return;
            }

            var html = '<strong>✓ Reset complete.</strong><br>';
            html += '<table style="width:100%; margin-top:8px; font-size:12px; border-collapse:collapse;">';
            html += '<tr style="border-bottom:1px solid rgba(0,0,0,0.1);"><th style="text-align:left; padding:4px 0;">Table</th><th style="text-align:right; padding:4px 0;">Rows Deleted</th></tr>';
            Object.keys(resp.report).forEach(function(k){
                html += '<tr><td style="padding:3px 0; font-family:monospace;">' + k + '</td><td style="text-align:right; font-family:monospace;">' + resp.report[k] + '</td></tr>';
            });
            html += '</table>';
            html += '<div style="margin-top:10px; font-size:12px;">Reloading in 3 seconds…</div>';

            feedback.className = 'feedback ok';
            feedback.innerHTML = html;
            feedback.style.display = 'block';

            setTimeout(function(){ window.location.reload(); }, 3000);
        })
        .catch(function(err){
            btnConfirm.disabled = false;
            btnConfirm.innerHTML = '<span class="glyphicon glyphicon-trash"></span> Confirm Reset';
            feedback.className = 'feedback err';
            feedback.innerHTML = '<strong>Network error:</strong> ' + err;
            feedback.style.display = 'block';
        });
}
</script>

</body>
</html>
<?php $conn->close(); ?>