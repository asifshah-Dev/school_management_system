<?php
header('Content-Type: text/html; charset=utf-8');
require_once('security.php');
require_once('conn_inc.php');
require_once('permissions.php');

require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Accounts — Dar-e-Arqm</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #eef1f5; font-family: 'Inter', -apple-system, sans-serif; color: #1e293b; }
    .wrap { max-width: 1300px; margin: 30px auto; padding: 0 20px; }

    .head {
        background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
        color: #fff;
        padding: 34px 42px 30px 42px;
        border-radius: 12px 12px 0 0;
        box-shadow: 0 4px 16px rgba(30,64,175,0.15);
    }
    .head h1 { font-size: 30px; font-weight: 800; letter-spacing: -0.5px; }
    .head .sub { font-size: 14px; opacity: 0.9; margin-top: 8px; }

    .card {
        background: #fff;
        padding: 34px 42px 46px 42px;
        border-radius: 0 0 12px 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    }

    .section-title {
        font-size: 12px; font-weight: 800; color: #64748b;
        text-transform: uppercase; letter-spacing: 2px;
        margin: 8px 0 18px 0;
        padding-bottom: 10px;
        border-bottom: 2px solid #e2e8f0;
        display: flex; align-items: center; gap: 10px;
    }
    .section-title .icon {
        display: inline-flex; align-items: center; justify-content: center;
        width: 26px; height: 26px;
        background: #eff6ff; color: #1e40af;
        border-radius: 6px; font-size: 13px;
    }
    .section-title + .section-title,
    .grid + .section-title { margin-top: 34px; }

    .grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 16px;
    }

    .tile {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 22px 22px 20px 22px;
        text-decoration: none;
        color: #1e293b;
        display: flex; flex-direction: column;
        gap: 14px;
        transition: all 0.15s ease;
        position: relative;
        overflow: hidden;
    }
    .tile:hover {
        border-color: #1e40af;
        background: #f0f5ff;
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(30,64,175,0.12);
        text-decoration: none; color: #1e293b;
    }
    .tile::after {
        content: '';
        position: absolute;
        bottom: 0; left: 0;
        height: 3px; width: 100%;
        background: transparent;
        transition: background 0.15s;
    }
    .tile:hover::after { background: linear-gradient(90deg, #1e40af 0%, #7c3aed 100%); }

    .tile .head-row { display: flex; align-items: flex-start; gap: 14px; }
    .tile .ico {
        width: 46px; height: 46px;
        display: flex; align-items: center; justify-content: center;
        border-radius: 11px; font-size: 20px;
        flex-shrink: 0;
    }
    .tile .body { flex: 1; min-width: 0; }
    .tile .t { font-size: 15px; font-weight: 700; color: #0f172a; letter-spacing: -0.2px; }
    .tile .d { font-size: 12.5px; color: #64748b; line-height: 1.5; margin-top: 4px; }
    .tile .arrow { font-size: 13px; color: #94a3b8; margin-left: auto; transition: transform 0.15s; }
    .tile:hover .arrow { transform: translateX(3px); color: #1e40af; }

    /* Icon palettes */
    .ico-hub      { background: #dbeafe; color: #1e40af; }
    .ico-coa      { background: #e0e7ff; color: #4338ca; }
    .ico-journal  { background: #ede9fe; color: #6d28d9; }
    .ico-add      { background: #f0fdf4; color: #15803d; }
    .ico-open     { background: #fef3c7; color: #b45309; }
    .ico-voucher  { background: #fce7f3; color: #9d174d; }

    .ico-ledger   { background: #d1fae5; color: #047857; }
    .ico-quick    { background: #cffafe; color: #0e7490; }
    .ico-tb       { background: #dbeafe; color: #1e40af; }
    .ico-pl       { background: #dcfce7; color: #15803d; }
    .ico-bs       { background: #e0e7ff; color: #4338ca; }
    .ico-cf       { background: #fef3c7; color: #b45309; }

    .ico-session  { background: #ede9fe; color: #5b21b6; }
    .ico-yec      { background: #f3e8ff; color: #7e22ce; }
    .ico-budget   { background: #fef3c7; color: #a16207; }

    .ico-audit    { background: #f1f5f9; color: #334155; }
    .ico-settings { background: #f1f5f9; color: #475569; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <h1><i class="fas fa-book" style="opacity:0.8;"></i> Accounts</h1>
        <div class="sub">Chart of accounts, journal entries, financial reports, and period closing</div>
    </div>

    <div class="card">

        <!-- CORE -->
        <div class="section-title">
            <span class="icon"><i class="fas fa-gauge-high"></i></span>
            Core
        </div>
        <div class="grid">

            <a href="gl_dashboard.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-hub"><i class="fas fa-gauge-high"></i></div>
                    <div class="body">
                        <div class="t">Accounting Dashboard</div>
                        <div class="d">One-page overview: cash, receivables, payables, net profit</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="gl_accounts.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-coa"><i class="fas fa-sitemap"></i></div>
                    <div class="body">
                        <div class="t">Chart of Accounts</div>
                        <div class="d">All GL accounts with current balances, tree view by type</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="gl_transactions.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-journal"><i class="fas fa-book"></i></div>
                    <div class="body">
                        <div class="t">Journal Entries</div>
                        <div class="d">Every posted transaction. Filter by date, session, reference type</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="gl_journal_add.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-add"><i class="fas fa-plus-circle"></i></div>
                    <div class="body">
                        <div class="t">Post Manual Journal</div>
                        <div class="d">Post a balanced journal entry — openings, corrections, adjustments</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="gl_opening_balance.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-open"><i class="fas fa-flag"></i></div>
                    <div class="body">
                        <div class="t">Opening Balance</div>
                        <div class="d">Create and manage opening balance entries</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="gl_vouchers.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-voucher"><i class="fas fa-file-invoice"></i></div>
                    <div class="body">
                        <div class="t">All Vouchers</div>
                        <div class="d">List every CPV, CRV, BPV, BRV. Filter by type, status, date</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

        </div>

        <!-- REPORTS -->
        <div class="section-title">
            <span class="icon"><i class="fas fa-chart-line"></i></span>
            Reports
        </div>
        <div class="grid">

            <a href="gl_account_ledger.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-ledger"><i class="fas fa-list-alt"></i></div>
                    <div class="body">
                        <div class="t">Account Ledger</div>
                        <div class="d">Detailed transaction history for a single account</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="gl_quick_ledger.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-quick"><i class="fas fa-bolt"></i></div>
                    <div class="body">
                        <div class="t">Quick Ledger</div>
                        <div class="d">Summary of account activity with key financial metrics</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="gl_trial_balance.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-tb"><i class="fas fa-balance-scale"></i></div>
                    <div class="body">
                        <div class="t">Trial Balance</div>
                        <div class="d">Opening, movement, closing balances per account</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="gl_pl.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-pl"><i class="fas fa-signal"></i></div>
                    <div class="body">
                        <div class="t">Profit &amp; Loss</div>
                        <div class="d">Revenue minus expenses for a date range</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="gl_balance_sheet.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-bs"><i class="fas fa-briefcase"></i></div>
                    <div class="body">
                        <div class="t">Balance Sheet</div>
                        <div class="d">Assets = Liabilities + Equity with change column</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="gl_cash_flow.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-cf"><i class="fas fa-exchange-alt"></i></div>
                    <div class="body">
                        <div class="t">Cash Flow</div>
                        <div class="d">Operating / Investing / Financing breakdown of cash movement</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

        </div>

        <!-- PERIOD & CLOSE -->
        <div class="section-title">
            <span class="icon"><i class="fas fa-lock"></i></span>
            Period &amp; Close
        </div>
        <div class="grid">

            <a href="gl_session_close.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-session"><i class="fas fa-calendar-check"></i></div>
                    <div class="body">
                        <div class="t">Session Close</div>
                        <div class="d">Freeze a session and record a permanent snapshot</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="gl_year_end_close.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-yec"><i class="fas fa-calendar-times"></i></div>
                    <div class="body">
                        <div class="t">Year-End Close</div>
                        <div class="d">Zero revenue and expense accounts, transfer to Retained Earnings</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="gl_budget.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-budget"><i class="fas fa-chart-pie"></i></div>
                    <div class="body">
                        <div class="t">Budgets</div>
                        <div class="d">Fiscal-year budgets with monthly breakdown, approve and lock</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

        </div>

        <!-- AUDIT -->
        <div class="section-title">
            <span class="icon"><i class="fas fa-shield-halved"></i></span>
            Audit &amp; Configuration
        </div>
        <div class="grid">

            <a href="gl_user_activity.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-audit"><i class="fas fa-user-clock"></i></div>
                    <div class="body">
                        <div class="t">User Activity</div>
                        <div class="d">Every journal entry, voucher, and reversal performed by each user</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="gl_settings.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-settings"><i class="fas fa-cog"></i></div>
                    <div class="body">
                        <div class="t">Settings</div>
                        <div class="d">Period lock date and other global accounting settings</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

        </div>

    </div>
</div>

</body>
</html>