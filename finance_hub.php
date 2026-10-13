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
<title>Fees &amp; Finance — Dar-e-Arqm</title>
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

    .tile .ico {
        width: 46px; height: 46px;
        display: flex; align-items: center; justify-content: center;
        border-radius: 11px; font-size: 20px;
        flex-shrink: 0;
    }
    .tile .t { font-size: 15px; font-weight: 700; color: #0f172a; letter-spacing: -0.2px; }
    .tile .d { font-size: 12.5px; color: #64748b; line-height: 1.5; }
    .tile .arrow { font-size: 13px; color: #94a3b8; margin-left: auto; transition: transform 0.15s; }
    .tile:hover .arrow { transform: translateX(3px); color: #1e40af; }

    /* Tile icon colors */
    .ico-fee      { background: #d1fae5; color: #047857; }
    .ico-collect  { background: #dbeafe; color: #1e40af; }
    .ico-challan  { background: #fef3c7; color: #b45309; }
    .ico-assign   { background: #ede9fe; color: #6d28d9; }
    .ico-advance  { background: #fce7f3; color: #9d174d; }
    .ico-report   { background: #e0e7ff; color: #4338ca; }
    .ico-defaulter{ background: #fee2e2; color: #b91c1c; }

    .ico-cpv { background: #fee2e2; color: #b91c1c; }
    .ico-crv { background: #d1fae5; color: #047857; }
    .ico-bpv { background: #ffedd5; color: #c2410c; }
    .ico-brv { background: #dbeafe; color: #1e40af; }
    .ico-jv  { background: #ede9fe; color: #6d28d9; }
    .ico-all { background: #f1f5f9; color: #334155; }

    .tile .head-row {
        display: flex; align-items: flex-start; gap: 14px;
    }
    .tile .head-row .ico { flex-shrink: 0; }
    .tile .head-row .body { flex: 1; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <h1><i class="fas fa-coins" style="opacity:0.8;"></i> Fees &amp; Finance</h1>
        <div class="sub">Fee collection, challans, reports, and all financial vouchers in one place</div>
    </div>

    <div class="card">

        <div class="section-title">
            <span class="icon"><i class="fas fa-file-invoice-dollar"></i></span>
            Fees Management
        </div>

        <div class="grid">
            <a href="fee_card_create_monthly.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-fee"><i class="fas fa-calendar-plus"></i></div>
                    <div class="body">
                        <div class="t">Monthly Fee Cards</div>
                        <div class="d">Generate fee cards for a class and month in bulk</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="student_feecards.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-challan"><i class="fas fa-file-invoice"></i></div>
                    <div class="body">
                        <div class="t">Fee Challans</div>
                        <div class="d">Print fee reminders and defaulter lists per class</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="fee_pick_student.php?mode=assign" class="tile">
                <div class="head-row">
                    <div class="ico ico-assign"><i class="fas fa-tasks"></i></div>
                    <div class="body">
                        <div class="t">Fee Assignments</div>
                        <div class="d">Assign or override a fee structure for a single student</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="fee_pick_student.php?mode=collect" class="tile">
                <div class="head-row">
                    <div class="ico ico-collect"><i class="fas fa-hand-holding-usd"></i></div>
                    <div class="body">
                        <div class="t">Collect Fees</div>
                        <div class="d">Open a student from the list and record payments</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="advance_fee_payment.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-advance"><i class="fas fa-forward"></i></div>
                    <div class="body">
                        <div class="t">Advance Fee Payment</div>
                        <div class="d">Record advance payments against future months</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="fee_type_monthly_report.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-report"><i class="fas fa-chart-line"></i></div>
                    <div class="body">
                        <div class="t">Fee Collection Report</div>
                        <div class="d">Monthly totals of collected fees, with detail view</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="gl_fee_defaulters.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-defaulter"><i class="fas fa-user-clock"></i></div>
                    <div class="body">
                        <div class="t">Fee Defaulters</div>
                        <div class="d">Students with outstanding dues, aged by how long overdue</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>
        </div>

        <div class="section-title">
            <span class="icon"><i class="fas fa-receipt"></i></span>
            Vouchers
        </div>

        <div class="grid">
            <a href="gl_voucher_add.php?type=CPV" class="tile">
                <div class="head-row">
                    <div class="ico ico-cpv"><i class="fas fa-arrow-up"></i></div>
                    <div class="body">
                        <div class="t">Cash Payment Voucher (CPV)</div>
                        <div class="d">Record cash paid out — expenses, salary, suppliers</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="gl_voucher_add.php?type=CRV" class="tile">
                <div class="head-row">
                    <div class="ico ico-crv"><i class="fas fa-arrow-down"></i></div>
                    <div class="body">
                        <div class="t">Cash Received Voucher (CRV)</div>
                        <div class="d">Record cash received — donations, misc income</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="gl_voucher_add.php?type=BPV" class="tile">
                <div class="head-row">
                    <div class="ico ico-bpv"><i class="fas fa-upload"></i></div>
                    <div class="body">
                        <div class="t">Bank Payment Voucher (BPV)</div>
                        <div class="d">Record bank payments — cheques, transfers, online</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="gl_voucher_add.php?type=BRV" class="tile">
                <div class="head-row">
                    <div class="ico ico-brv"><i class="fas fa-download"></i></div>
                    <div class="body">
                        <div class="t">Bank Received Voucher (BRV)</div>
                        <div class="d">Record bank deposits and incoming transfers</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="gl_journal_add.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-jv"><i class="fas fa-balance-scale"></i></div>
                    <div class="body">
                        <div class="t">Journal Entry Voucher</div>
                        <div class="d">Post a manual double-entry journal (openings, corrections)</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="gl_vouchers.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-all"><i class="fas fa-list-ul"></i></div>
                    <div class="body">
                        <div class="t">All Vouchers</div>
                        <div class="d">Browse every CPV, CRV, BPV, BRV — filter by type and status</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>
        </div>

    </div>
</div>

</body>
</html>