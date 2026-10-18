<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");

$errors  = [];
$success = null;

function tbl_exists(mysqli $conn, string $table): bool {
    $t = $conn->real_escape_string($table);
    $r = $conn->query("SHOW TABLES LIKE '$t'");
    return $r && $r->num_rows > 0;
}

$hasOpeningTable = tbl_exists($conn, 'gl_opening_balances');

$ledgerCount = 0;
$r = $conn->query("SELECT COUNT(*) AS c FROM gl_transactions WHERE status = 'POSTED'");
if ($r && ($row = $r->fetch_assoc())) $ledgerCount = (int)$row['c'];

$existingOpening = null;
$r = $conn->query("SELECT id, entry_date, description, status FROM gl_transactions WHERE reference_type = 'opening_balance' ORDER BY id DESC LIMIT 1");
if ($r && ($row = $r->fetch_assoc())) $existingOpening = $row;

$openingItems = [];
$sourceLabel = '';

if ($hasOpeningTable) {
    $sql = "SELECT ob.account_id, ob.amount, a.code, a.name, a.account_type
            FROM gl_opening_balances ob
            JOIN gl_accounts a ON a.id = ob.account_id
            WHERE a.status = 1
            ORDER BY a.code";
    $r = $conn->query($sql);
    if ($r && $r->num_rows > 0) {
        while ($row = $r->fetch_assoc()) {
            $amt = (float)$row['amount'];
            if (abs($amt) < 0.005) continue;
            $openingItems[] = [
                'field' => 'acc_' . (int)$row['account_id'],
                'account_id' => (int)$row['account_id'],
                'code'  => $row['code'],
                'name'  => $row['name'],
                'type'  => $row['account_type'],
                'amount'=> $amt,
            ];
        }
        $sourceLabel = 'Imported opening balances (previous session)';
    }
}

if (empty($openingItems)) {
    $sql = "SELECT l.account_id, a.code, a.name, a.account_type,
                   SUM(l.debit)  AS total_debit,
                   SUM(l.credit) AS total_credit
            FROM gl_journal_lines l
            JOIN gl_transactions t ON t.id = l.transaction_id
            JOIN gl_accounts a ON a.id = l.account_id
            WHERE t.reference_type = 'opening_balance'
              AND t.status = 'POSTED'
              AND a.status = 1
            GROUP BY l.account_id, a.code, a.name, a.account_type
            ORDER BY a.code";
    $r = $conn->query($sql);
    if ($r && $r->num_rows > 0) {
        while ($row = $r->fetch_assoc()) {
            $isDebitNormal = in_array($row['account_type'], ['ASSET','EXPENSE'], true);
            $net = $isDebitNormal
                ? ((float)$row['total_debit'] - (float)$row['total_credit'])
                : ((float)$row['total_credit'] - (float)$row['total_debit']);
            if (abs($net) < 0.005) continue;
            $openingItems[] = [
                'field' => 'acc_' . (int)$row['account_id'],
                'account_id' => (int)$row['account_id'],
                'code'  => $row['code'],
                'name'  => $row['name'],
                'type'  => $row['account_type'],
                'amount'=> $net,
            ];
        }
        $sourceLabel = 'Posted opening balances (from ledger)';
    }
}

if (empty($openingItems)) {
    $prefills = [];
    $q = $conn->query("SELECT COALESCE(SUM(total_amount - paid_amount - COALESCE(discount_amount, 0)), 0) AS total FROM student_fee_card WHERE status IN ('pending', 'partial')");
    if ($q && ($row = $q->fetch_assoc())) $prefills['1030'] = (float)$row['total'];

    $q = $conn->query("SELECT COALESCE(SUM(remaining_amount), 0) AS total FROM advance_payments WHERE status = 'active'");
    if ($q && ($row = $q->fetch_assoc())) $prefills['2020'] = (float)$row['total'];

    foreach ($prefills as $code => $amount) {
        if (abs($amount) < 0.005) continue;
        $acc = gl_get_account_by_code($conn, $code);
        if (!$acc) continue;
        $openingItems[] = [
            'field' => 'acc_' . (int)$acc['id'],
            'account_id' => (int)$acc['id'],
            'code'  => $acc['code'],
            'name'  => $acc['name'],
            'type'  => $acc['account_type'],
            'amount'=> $amount,
        ];
    }
    if (!empty($openingItems)) $sourceLabel = 'Auto-filled from operational data';
}

$retainedAcc = gl_get_account_by_code($conn, '3100');

$form = [
    'entry_date'  => $_POST['entry_date']  ?? date('Y-m-d'),
    'description' => $_POST['description'] ?? 'Opening Balance',
];

$postedAmounts = [];
foreach ($openingItems as $it) {
    $key = 'amt_' . $it['account_id'];
    if (isset($_POST[$key])) {
        $v = (float)$_POST[$key];
        if (abs($v) >= 0.005) $postedAmounts[$it['account_id']] = round($v, 2);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_opening'])) {

    $force = isset($_POST['force_post']) && $_POST['force_post'] === '1';

    if ($ledgerCount > 0 && !$force) {
        $errors[] = "The ledger already contains $ledgerCount posted transaction(s). Tick the override checkbox to proceed anyway.";
    }
    if (empty($form['entry_date']) || !strtotime($form['entry_date'])) {
        $errors[] = "Valid entry date required.";
    }
    if (empty($form['description'])) {
        $errors[] = "Description required.";
    }
    if (empty($postedAmounts)) {
        $errors[] = "No account has a non-zero amount. Nothing to post.";
    }
    if (!$retainedAcc) {
        $errors[] = "Retained Earnings account (3100) missing.";
    }

    if (empty($errors)) {
        $lines = [];
        $totalDr = 0.0;
        $totalCr = 0.0;

        foreach ($postedAmounts as $accountId => $amount) {
            $acc = gl_get_account($conn, (int)$accountId);
            if (!$acc) continue;

            if (in_array($acc['account_type'], ['ASSET','EXPENSE'], true)) {
                $lines[] = ['account_id' => (int)$accountId, 'debit' => $amount, 'credit' => 0.00, 'memo' => 'Opening balance — ' . $acc['name']];
                $totalDr += $amount;
            } else {
                $lines[] = ['account_id' => (int)$accountId, 'debit' => 0.00, 'credit' => $amount, 'memo' => 'Opening balance — ' . $acc['name']];
                $totalCr += $amount;
            }
        }

        $plug = round($totalDr - $totalCr, 2);
        if (abs($plug) > 0.005) {
            if ($plug > 0) {
                $lines[] = ['account_id' => (int)$retainedAcc['id'], 'debit' => 0.00, 'credit' => $plug, 'memo' => 'Opening balance — Retained Earnings (plug)'];
            } else {
                $lines[] = ['account_id' => (int)$retainedAcc['id'], 'debit' => abs($plug), 'credit' => 0.00, 'memo' => 'Opening balance — Retained Earnings (accumulated loss)'];
            }
        }

        if (count($lines) < 2) {
            $errors[] = "At least 2 non-zero amounts are needed.";
        }

        if (empty($errors)) {
            $sessionRow = $conn->query("SELECT id FROM sessions WHERE status = 0 ORDER BY id DESC LIMIT 1")->fetch_assoc();
            $sessionId = $sessionRow ? (int)$sessionRow['id'] : 1;
            $postedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;

            $glError = null;
            $txnId = post_journal_entry($conn, [
                'entry_date'  => $form['entry_date'],
                'session_id'  => $sessionId,
                'description' => $form['description'],
                'ref_type'    => 'opening_balance',
                'ref_id'      => null,
                'posted_by'   => $postedBy,
                'idempotency' => 'opening_balance_' . date('YmdHis'),
                'lines'       => $lines,
            ], $glError);

            if ($txnId > 0) {
                $_SESSION['message'] = "Opening balance posted — Transaction #$txnId";
                $_SESSION['message_type'] = 'success';
                header("Location: gl_transaction_view.php?id=" . $txnId);
                exit();
            } else {
                $errors[] = "GL post failed: " . htmlspecialchars($glError ?: 'unknown');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Opening Balance</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    body { background: #f5f7fa; font-family: 'Inter', sans-serif; color: #1e293b; padding-bottom: 40px; }
    .wrap { max-width: 1040px; margin: 24px auto; padding: 0 20px; }

    .head { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: #fff; padding: 20px 24px; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; }
    .head h1 { margin: 0; font-size: 19px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .head .sub { font-size: 12px; opacity: 0.88; margin-top: 4px; }
    .head .actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .btn-back, .btn-head { padding: 8px 14px; border-radius: 7px; font-size: 12px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; border: 1px solid; }
    .btn-back { background: rgba(255,255,255,0.15); color: #fff; border-color: rgba(255,255,255,0.3); }
    .btn-back:hover { background: rgba(255,255,255,0.25); color: #fff; text-decoration: none; }
    .btn-head { background: #fff; color: #1d4ed8; border-color: #fff; }
    .btn-head:hover { background: #f1f5f9; color: #1d4ed8; text-decoration: none; }

    .card { background: #fff; padding: 22px 24px 24px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

    .error-box { background: #fee2e2; border-left: 4px solid #dc2626; padding: 11px 14px; border-radius: 8px; margin-bottom: 16px; color: #991b1b; font-size: 12.5px; }
    .error-box strong { display: block; margin-bottom: 5px; }
    .error-box ul { margin: 0; padding-left: 18px; }

    .section-title { font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 1.5px; margin: 18px 0 10px; padding-bottom: 5px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; gap: 6px; }
    .section-title:first-child { margin-top: 0; }
    .section-title i { color: #2563eb; font-size: 10px; }
    .section-title .badge { margin-left: auto; font-size: 9.5px; font-weight: 700; padding: 2px 8px; background: #eff6ff; color: #2563eb; border-radius: 10px; letter-spacing: 0.3px; text-transform: none; }

    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    @media (max-width: 700px) { .grid-2 { grid-template-columns: 1fr; } }

    .field label { display: block; font-size: 10.5px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 4px; }
    .field input[type=text], .field input[type=date], .field input[type=number] { width: 100%; height: 34px; padding: 5px 10px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 12.5px; font-family: inherit; background: #fff; color: #0f172a; }
    .field input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.12); }
    .field .help { font-size: 10.5px; color: #64748b; margin-top: 3px; line-height: 1.4; }
    .amount-input { font-family: 'SF Mono', monospace; text-align: right; font-weight: 600; }

    .open-table-wrap { border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; margin-top: 4px; }
    .open-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
    .open-table thead th { background: #f8fafc; padding: 8px 12px; font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.6px; text-align: left; border-bottom: 1px solid #e2e8f0; }
    .open-table thead th.num { text-align: right; }
    .open-table tbody td { padding: 8px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .open-table tbody tr:last-child td { border-bottom: none; }
    .open-table tbody tr:hover { background: #fafbfc; }
    .open-table .acc-code { font-family: 'SF Mono', monospace; font-size: 11.5px; color: #64748b; font-weight: 600; width: 60px; }
    .open-table .acc-name { font-weight: 500; color: #0f172a; }
    .open-table .acc-name small { display: block; color: #94a3b8; font-size: 10px; font-weight: 500; margin-top: 1px; }
    .open-table .type-pill { display: inline-block; padding: 2px 7px; border-radius: 10px; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; }
    .open-table .type-pill.asset { background: #dbeafe; color: #1e40af; }
    .open-table .type-pill.liability { background: #ffedd5; color: #c2410c; }
    .open-table .type-pill.equity { background: #ede9fe; color: #6b21a8; }
    .open-table .type-pill.revenue { background: #d1fae5; color: #047857; }
    .open-table .type-pill.expense { background: #fee2e2; color: #b91c1c; }
    .open-table .amount-input { width: 130px; height: 30px; font-size: 12.5px; }

    .preview-wrap { margin-top: 12px; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
    .preview-wrap .pv-head { background: #1e293b; color: #fff; padding: 8px 14px; font-size: 11px; font-weight: 700; letter-spacing: 0.5px; display: flex; justify-content: space-between; align-items: center; }
    .preview-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
    .preview-table th { padding: 6px 12px; text-align: left; font-size: 9.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.6px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
    .preview-table th.num { text-align: right; }
    .preview-table td { padding: 6px 12px; border-bottom: 1px solid #f1f5f9; }
    .preview-table td.num { text-align: right; font-family: 'SF Mono', monospace; font-weight: 600; font-variant-numeric: tabular-nums; }
    .preview-table td.num.debit { color: #047857; }
    .preview-table td.num.credit { color: #b91c1c; }
    .preview-table tr.total-row td { background: #f0f5ff; font-weight: 800; border-top: 2px solid #c9d7ef; border-bottom: 2px solid #c9d7ef; }

    .balance-indicator { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 12px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; }
    .balance-indicator.ok { background: #d1fae5; color: #065f46; }
    .balance-indicator.bad { background: #fee2e2; color: #991b1b; }

    .force-box { background: #fef3c7; border-left: 4px solid #d97706; padding: 10px 14px; border-radius: 8px; margin-top: 14px; font-size: 12px; color: #92400e; }
    .force-box label { display: flex; align-items: flex-start; gap: 8px; cursor: pointer; margin: 0; }
    .force-box input[type=checkbox] { margin-top: 2px; }

    .actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; padding-top: 14px; border-top: 1px solid #e2e8f0; }
    .btn { height: 36px; padding: 0 16px; border-radius: 7px; font-size: 12.5px; font-weight: 600; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; font-family: inherit; }
    .btn-ghost { background: #fff; border: 1px solid #cbd5e1; color: #475569; }
    .btn-ghost:hover { background: #f8fafc; color: #1e293b; text-decoration: none; }
    .btn-post { background: #2563eb; color: #fff; }
    .btn-post:hover { background: #1d4ed8; color: #fff; text-decoration: none; }
    .btn-post:disabled { background: #94a3b8; cursor: not-allowed; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <h1><i class="fas fa-flag-checkered"></i> Opening Balance</h1>
            <div class="sub">
                <?= $sourceLabel ? htmlspecialchars($sourceLabel) : 'Record opening balances for the current session' ?>
            </div>
        </div>
        <div class="actions">
            <a href="gl_opening_balance_manual.php" class="btn-head" title="Enter opening balances manually">
                <i class="fas fa-pencil-alt"></i> Enter Manually
            </a>
            <a href="gl_dashboard.php" class="btn-back"><i class="fas fa-arrow-left"></i> Dashboard</a>
        </div>
    </div>

    <div class="card">

        <?php if (isset($_SESSION['message'])): ?>
            <div style="margin-bottom: 14px; font-size: 12.5px;"><?php echo $_SESSION['message']; unset($_SESSION['message'], $_SESSION['message_type']); ?></div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="error-box">
                <strong><i class="fas fa-exclamation-circle"></i> Cannot post:</strong>
                <ul>
                    <?php foreach ($errors as $e): ?>
                        <li><?= htmlspecialchars($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (empty($openingItems)): ?>
            <div style="padding: 40px 20px; text-align: center; color: #94a3b8;">
                <div style="font-size: 40px; opacity: 0.4; margin-bottom: 8px;"><i class="fas fa-inbox"></i></div>
                <div style="font-size: 13px;">No opening balances to import.</div>
            </div>

        <?php else: ?>
        <form method="post" action="" id="openingForm">

            <div class="section-title"><i class="fas fa-calendar-alt"></i> Entry Details</div>
            <div class="grid-2">
                <div class="field">
                    <label for="entry_date">Entry Date <span style="color: #dc2626;">*</span></label>
                    <input type="date" id="entry_date" name="entry_date" required value="<?= htmlspecialchars($form['entry_date']) ?>">
                </div>
                <div class="field">
                    <label for="description">Description <span style="color: #dc2626;">*</span></label>
                    <input type="text" id="description" name="description" maxlength="500" required value="<?= htmlspecialchars($form['description']) ?>">
                </div>
            </div>

            <div class="section-title">
                <i class="fas fa-wallet"></i> Accounts With Opening Balance
                <span class="badge"><?= count($openingItems) ?> account<?= count($openingItems) === 1 ? '' : 's' ?></span>
            </div>

            <div class="open-table-wrap">
                <table class="open-table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">Code</th>
                            <th>Account</th>
                            <th style="width: 110px;">Type</th>
                            <th class="num" style="width: 150px;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($openingItems as $it):
                            $key = 'amt_' . (int)$it['account_id'];
                            $val = isset($_POST[$key]) ? $_POST[$key] : $it['amount'];
                            $typeClass = strtolower($it['type']);
                        ?>
                            <tr>
                                <td class="acc-code"><?= htmlspecialchars($it['code']) ?></td>
                                <td class="acc-name">
                                    <?= htmlspecialchars($it['name']) ?>
                                    <small><?= ($it['type'] === 'ASSET' || $it['type'] === 'EXPENSE') ? 'Debit balance' : 'Credit balance' ?></small>
                                </td>
                                <td><span class="type-pill <?= $typeClass ?>"><?= htmlspecialchars($it['type']) ?></span></td>
                                <td style="text-align: right;">
                                    <input type="number" step="0.01" min="0"
                                           class="amount-input"
                                           name="<?= htmlspecialchars($key) ?>"
                                           value="<?= htmlspecialchars($val) ?>"
                                           oninput="recalc()">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="section-title"><i class="fas fa-book"></i> Journal Entry Preview</div>

            <div class="preview-wrap">
                <div class="pv-head">
                    <span><i class="fas fa-book"></i> Opening Balance Entry</span>
                    <span id="previewStatus" class="balance-indicator ok">Balanced</span>
                </div>
                <table class="preview-table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">Code</th>
                            <th>Account</th>
                            <th class="num" style="width: 130px;">Debit</th>
                            <th class="num" style="width: 130px;">Credit</th>
                        </tr>
                    </thead>
                    <tbody id="previewBody"></tbody>
                    <tfoot>
                        <tr class="total-row">
                            <td colspan="2" style="text-align: right;">TOTAL</td>
                            <td class="num debit" id="totalDebit">0.00</td>
                            <td class="num credit" id="totalCredit">0.00</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <?php if ($ledgerCount > 0): ?>
                <div class="force-box">
                    <label>
                        <input type="checkbox" name="force_post" value="1" <?= (isset($_POST['force_post']) && $_POST['force_post'] === '1') ? 'checked' : '' ?>>
                        <span>
                            <strong>I understand this will post an opening balance to a non-empty ledger.</strong>
                        </span>
                    </label>
                </div>
            <?php endif; ?>

            <div class="actions">
                <a href="gl_dashboard.php" class="btn btn-ghost">Cancel</a>
                <button type="submit" name="submit_opening" value="1" class="btn btn-post" id="submitBtn">
                    <i class="fas fa-save"></i> Post Opening Balance
                </button>
            </div>

        </form>
        <?php endif; ?>
    </div>
</div>

<script>
var OPENING_ITEMS = <?= json_encode(array_map(function($it){
    return [
        'account_id' => $it['account_id'],
        'code'       => $it['code'],
        'name'       => $it['name'],
        'type'       => $it['type'],
    ];
}, $openingItems), JSON_UNESCAPED_UNICODE) ?>;

var RETAINED = { code: '3100', name: 'Retained Earnings' };

function fmt(n) {
    if (!isFinite(n)) n = 0;
    return n.toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function recalc() {
    var tbody = document.getElementById('previewBody');
    var rows = [];
    var totalDr = 0;
    var totalCr = 0;

    OPENING_ITEMS.forEach(function(it) {
        var input = document.querySelector('input[name="amt_' + it.account_id + '"]');
        var val = input ? (parseFloat(input.value) || 0) : 0;
        if (val < 0) val = 0;
        if (val < 0.005) return;

        if (it.type === 'ASSET' || it.type === 'EXPENSE') {
            rows.push({ code: it.code, name: it.name, debit: val, credit: 0 });
            totalDr += val;
        } else {
            rows.push({ code: it.code, name: it.name, debit: 0, credit: val });
            totalCr += val;
        }
    });

    var plug = Math.round((totalDr - totalCr) * 100) / 100;
    if (Math.abs(plug) > 0.005) {
        if (plug > 0) {
            rows.push({ code: RETAINED.code, name: RETAINED.name + ' (plug)', debit: 0, credit: plug });
            totalCr += plug;
        } else {
            rows.push({ code: RETAINED.code, name: RETAINED.name + ' (loss)', debit: Math.abs(plug), credit: 0 });
            totalDr += Math.abs(plug);
        }
    }

    var html = '';
    if (rows.length === 0) {
        html = '<tr><td colspan="4" style="text-align:center; padding: 20px; color:#94a3b8; font-style:italic;">Enter amounts above</td></tr>';
    } else {
        rows.forEach(function(r) {
            html += '<tr>';
            html += '<td style="font-family:\'SF Mono\',monospace; font-size:11.5px; color:#64748b;">' + r.code + '</td>';
            html += '<td>' + r.name + '</td>';
            html += '<td class="num debit">' + (r.debit > 0 ? fmt(r.debit) : '—') + '</td>';
            html += '<td class="num credit">' + (r.credit > 0 ? fmt(r.credit) : '—') + '</td>';
            html += '</tr>';
        });
    }
    tbody.innerHTML = html;

    document.getElementById('totalDebit').textContent  = fmt(totalDr);
    document.getElementById('totalCredit').textContent = fmt(totalCr);

    var status = document.getElementById('previewStatus');
    var balanced = Math.abs(totalDr - totalCr) < 0.01 && (totalDr > 0 || totalCr > 0);
    if (balanced) {
        status.className = 'balance-indicator ok';
        status.textContent = 'Balanced';
    } else if (totalDr === 0 && totalCr === 0) {
        status.className = 'balance-indicator ok';
        status.textContent = 'Empty';
    } else {
        status.className = 'balance-indicator bad';
        status.textContent = 'Not Balanced';
    }

    var btn = document.getElementById('submitBtn');
    if (btn) btn.disabled = !balanced;
}

document.addEventListener('DOMContentLoaded', recalc);
</script>

</body>
</html>
<?php $conn->close(); ?>