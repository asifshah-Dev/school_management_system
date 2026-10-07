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

// Diagnostic flags
$ledgerEmpty = ($ledgerCount === 0 && $lineCount === 0);
$coaSeeded   = ($accountCount >= 40);
$mapsSeeded  = ($feeMapCount > 0 && $expenseMapCount > 0 && $roleMapCount > 0);
$engineReady = ($triggerCount === 4 && $procCount === 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    
    <title>GL Test Hub — All Links</title>
    <style>
        body { background: #eef1f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }

        .hub {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .hub-head {
            margin-bottom: 30px;
        }
        .hub-head h1 {
            margin: 0;
            font-size: 30px;
            font-weight: 700;
            color: #1e293b;
        }
        .hub-head .sub {
            color: #64748b;
            font-size: 14px;
            margin-top: 4px;
        }

        /* -- Status board -- */
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
        .status-tile .sub {
            font-size: 12px;
            color: #94a3b8;
        }
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

        /* -- Section -- */
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

        /* -- Link grid -- */
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

        .link-card .body {
            flex: 1;
            min-width: 0;
        }
        .link-card .title {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .link-card .desc {
            font-size: 12px;
            color: #64748b;
            line-height: 1.5;
        }
        .link-card .url {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 11px;
            color: #94a3b8;
            margin-top: 6px;
            word-break: break-all;
        }

        .link-card .badge {
            position: absolute;
            top: 14px;
            right: 14px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 2px 8px;
            border-radius: 10px;
        }
        .link-card .badge.new      { background: #d1fae5; color: #065f46; }
        .link-card .badge.testing  { background: #fef3c7; color: #92400e; }
        .link-card .badge.warn     { background: #fee2e2; color: #991b1b; }

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

        @media (max-width: 600px) {
            .hub-head h1 { font-size: 22px; }
            .link-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="hub">

    <div class="hub-head">
        <h1>GL Test Hub</h1>
        <div class="sub">
            Every page in one place &middot; Session: <strong><?php echo htmlspecialchars($currentSession); ?></strong> &middot; <?php echo date('l, F j, Y'); ?>
        </div>
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
    </div>

    <!-- ===== Reports ===== -->
    <div class="section-title">Reports</div>
    <div class="link-grid">

        <a href="gl_dashboard.php" class="link-card report">
            <div class="icon"><span class="glyphicon glyphicon-dashboard"></span></div>
            <div class="body">
                <div class="title">Accounting Dashboard</div>
                <div class="desc">One-page overview: cash, receivables, obligations, net profit, recent activity.</div>
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
            <div class="icon"><span class="glyphicon glyphicon-tasks"></span></div>
            <div class="body">
                <div class="title">Quick Ledger</div>
                <div class="desc">Summary of account activity with key financial metrics.</div>
                <div class="url">gl_quick_ledger.php</div>
            </div>
        </a>
        <a href="gl_voucher_add.php" class="link-card report">
            <div class="icon"><span class="glyphicon glyphicon-tasks"></span></div>
            <div class="body">
                <div class="title">Vouchers</div>
                <div class="desc">Create and manage accounting vouchers.</div>
                <div class="url">gl_voucher_add.php</div>
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

        <a href="gl_transactions.php?ref_type=fee_payment" class="link-card admin">
            <div class="icon"><span class="glyphicon glyphicon-usd"></span></div>
            <div class="body">
                <div class="title">Fee Payment Entries</div>
                <div class="desc">Filtered view of fee payments only.</div>
                <div class="url">gl_transactions.php?ref_type=fee_payment</div>
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
                <div class="desc">Record expenses. Each posts to a category-mapped expense account.</div>
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

    <!-- ===== Danger Zone ===== -->
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
            <li>The status board at the top shows the health of the entire engine at a glance.</li>
            <li>Reports read the ledger. Admin pages define the Chart of Accounts. Mappings connect operational data to GL accounts.</li>
            <li>Operational pages write to the ledger automatically via <code>sp_post_transaction</code>.</li>
            <li>If a status tile shows red, click through to the relevant page to investigate.</li>
        </ul>
    </div>

</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

</body>
</html>
<?php $conn->close(); ?>