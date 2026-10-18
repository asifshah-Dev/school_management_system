<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");

$errors = [];

$codeMap = [
    'capital_introduced' => '3100',
    'cash_in_hand'       => '1010',
    'cash_at_bank'       => '1020',
    'fee_receivable'     => '1030',
    'furniture'          => '1510',
    'computers'          => '1520',
    'vehicles'           => '1530',
    'accounts_payable'   => '2010',
    'advance_fee'        => '2020',
    'salary_payable'     => '2030',
    'bank_loans'         => '2510',
];

$accountIds  = [];
$accountInfo = [];
$missingAccounts = [];
foreach ($codeMap as $key => $code) {
    $acc = gl_get_account_by_code($conn, $code);
    if (!$acc) {
        $missingAccounts[] = $code;
    } else {
        $accountIds[$key]  = (int)$acc['id'];
        $accountInfo[$key] = $acc;
    }
}

$activeSessionId = 0;
$r = $conn->query("SELECT id, title FROM sessions WHERE status = 0 ORDER BY id DESC LIMIT 1");
if ($r && ($row = $r->fetch_assoc())) $activeSessionId = (int)$row['id'];

$ledgerCount = 0;
$r = $conn->query("SELECT COUNT(*) AS c FROM gl_transactions WHERE status = 'POSTED'");
if ($r && ($row = $r->fetch_assoc())) $ledgerCount = (int)$row['c'];

$form = [
    'entry_date'         => $_POST['entry_date']         ?? date('Y-m-d'),
    'description'        => $_POST['description']        ?? 'Opening Balance — Manual',
    'capital_introduced' => $_POST['capital_introduced'] ?? '',
    'cash_in_hand'       => $_POST['cash_in_hand']       ?? '',
    'cash_at_bank'       => $_POST['cash_at_bank']       ?? '',
    'fee_receivable'     => $_POST['fee_receivable']     ?? '',
    'furniture'          => $_POST['furniture']          ?? '',
    'computers'          => $_POST['computers']          ?? '',
    'vehicles'           => $_POST['vehicles']           ?? '',
    'accounts_payable'   => $_POST['accounts_payable']   ?? '',
    'advance_fee'        => $_POST['advance_fee']        ?? '',
    'salary_payable'     => $_POST['salary_payable']     ?? '',
    'bank_loans'         => $_POST['bank_loans']         ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_manual_opening'])) {

    $force = isset($_POST['force_post']) && $_POST['force_post'] === '1';

    if (!empty($missingAccounts)) {
        $errors[] = 'Missing chart-of-accounts: codes ' . implode(', ', $missingAccounts) . '.';
    }
    if (empty($form['entry_date']) || !strtotime($form['entry_date'])) {
        $errors[] = 'Valid entry date required.';
    }
    if (trim($form['description']) === '') {
        $errors[] = 'Description required.';
    }

    $assetFields  = ['cash_in_hand','cash_at_bank','fee_receivable','furniture','computers','vehicles'];
    $liabFields   = ['accounts_payable','advance_fee','salary_payable','bank_loans'];
    $equityFields = ['capital_introduced'];

    $totalDr = 0.0;
    $totalCr = 0.0;
    $amt = [];
    foreach (array_merge($assetFields, $liabFields, $equityFields) as $f) {
        $val = ($form[$f] === '' || !is_numeric($form[$f])) ? 0.0 : (float)$form[$f];
        if ($val < 0) $val = 0.0;
        $amt[$f] = round($val, 2);
        if (in_array($f, $assetFields, true)) $totalDr += $amt[$f];
        else                                  $totalCr += $amt[$f];
    }

    if ($totalDr + $totalCr <= 0) {
        $errors[] = 'At least one amount must be greater than zero.';
    }
    if ($ledgerCount > 0 && !$force) {
        $errors[] = "Ledger has $ledgerCount posted transaction(s). Tick the override to proceed.";
    }

    if (empty($errors)) {
        $lines = [];

        foreach ($assetFields as $f) {
            if ($amt[$f] > 0 && !empty($accountIds[$f])) {
                $lines[] = ['account_id' => $accountIds[$f], 'debit' => $amt[$f], 'credit' => 0.00, 'memo' => 'Opening — ' . $accountInfo[$f]['name']];
            }
        }
        foreach ($liabFields as $f) {
            if ($amt[$f] > 0 && !empty($accountIds[$f])) {
                $lines[] = ['account_id' => $accountIds[$f], 'debit' => 0.00, 'credit' => $amt[$f], 'memo' => 'Opening — ' . $accountInfo[$f]['name']];
            }
        }

        $plugNeeded = round($totalDr - $totalCr, 2);
        if (!empty($accountIds['capital_introduced'])) {
            $equityManual = $amt['capital_introduced'];
            if ($equityManual > 0) {
                $lines[] = ['account_id' => $accountIds['capital_introduced'], 'debit' => 0.00, 'credit' => $equityManual, 'memo' => 'Opening — ' . $accountInfo['capital_introduced']['name']];
            }
            $plugNeeded -= $equityManual;
        }

        $plugNeeded = round($plugNeeded, 2);
        if (abs($plugNeeded) > 0.005) {
            if ($plugNeeded > 0) {
                $lines[] = ['account_id' => $accountIds['capital_introduced'], 'debit' => 0.00, 'credit' => $plugNeeded, 'memo' => 'Opening — Retained Earnings plug'];
            } else {
                $lines[] = ['account_id' => $accountIds['capital_introduced'], 'debit' => abs($plugNeeded), 'credit' => 0.00, 'memo' => 'Opening — Retained Earnings plug'];
            }
        }

        if (count($lines) < 2) {
            $errors[] = 'Nothing to post — enter at least one non-zero amount.';
        }
        if (empty($errors) && $activeSessionId <= 0) {
            $errors[] = 'No active session found. Create one first.';
        }

        if (empty($errors)) {
            $postedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
            $glError = null;
            $txnId = post_journal_entry($conn, [
                'entry_date'  => $form['entry_date'],
                'session_id'  => $activeSessionId,
                'description' => $form['description'],
                'ref_type'    => 'opening_balance',
                'ref_id'      => null,
                'posted_by'   => $postedBy,
                'idempotency' => 'opening-manual-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)),
                'lines'       => $lines,
            ], $glError);

            if ($txnId > 0) {
                $_SESSION['message'] = "Opening balance posted — Transaction #$txnId";
                $_SESSION['message_type'] = 'success';
                header("Location: gl_transaction_view.php?id=" . $txnId);
                exit();
            } else {
                $errors[] = 'GL post failed: ' . htmlspecialchars($glError ?: 'unknown');
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
<title>Manual Opening Balance</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    body { background: #f5f7fa; font-family: 'Inter', sans-serif; color: #1e293b; padding-bottom: 40px; }
    .wrap { max-width: 1040px; margin: 24px auto; padding: 0 20px; }

    .head { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: #fff; padding: 20px 24px; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; }
    .head h1 { margin: 0; font-size: 19px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .head .sub { font-size: 12px; opacity: 0.88; margin-top: 4px; }
    .btn-back { padding: 8px 14px; border-radius: 7px; font-size: 12px; font-weight: 700; background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.3); text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
    .btn-back:hover { background: rgba(255,255,255,0.25); color: #fff; text-decoration: none; }

    .card { background: #fff; padding: 22px 24px 24px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

    .error-box { background: #fee2e2; border-left: 4px solid #dc2626; padding: 11px 14px; border-radius: 8px; margin-bottom: 16px; color: #991b1b; font-size: 12.5px; }
    .error-box strong { display: block; margin-bottom: 5px; }
    .error-box ul { margin: 0; padding-left: 18px; }

    .section-title { font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 1.5px; margin: 18px 0 10px; padding-bottom: 5px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; gap: 6px; }
    .section-title:first-child { margin-top: 0; }
    .section-title i { color: #2563eb; font-size: 10px; }

    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
    @media (max-width: 700px) { .grid-2, .grid-3 { grid-template-columns: 1fr; } }

    .field label { display: block; font-size: 10.5px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 4px; }
    .field input[type=text], .field input[type=date], .field input[type=number] { width: 100%; height: 34px; padding: 5px 10px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 12.5px; font-family: inherit; background: #fff; color: #0f172a; }
    .field input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.12); }
    .field .help { font-size: 10.5px; color: #64748b; margin-top: 3px; line-height: 1.4; }
    .amount-input { font-family: 'SF Mono', monospace; text-align: right; font-weight: 600; }

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
            <h1><i class="fas fa-pencil-alt"></i> Manual Opening Balance</h1>
            <div class="sub">Enter the starting financial position</div>
        </div>
        <a href="gl_opening_balance.php" class="btn-back"><i class="fas fa-arrow-left"></i> Auto Import</a>
    </div>

    <div class="card">

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

        <?php if (empty($perms['can_add'])): ?>
            <div class="error-box">
                <strong>No permission</strong> You do not have permission to post opening balances.
            </div>
        <?php else: ?>

        <form method="post" action="" id="manualForm">

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

            <div class="section-title"><i class="fas fa-coins"></i> Capital / Equity</div>
            <div class="grid-3">
                <div class="field">
                    <label for="capital_introduced">Capital Introduced</label>
                    <input type="number" step="0.01" min="0" class="amount-input" id="capital_introduced" name="capital_introduced"
                           value="<?= htmlspecialchars($form['capital_introduced']) ?>" placeholder="0.00" oninput="recalc()">
                </div>
            </div>

            <div class="section-title"><i class="fas fa-wallet"></i> Assets</div>
            <div class="grid-3">
                <div class="field">
                    <label for="cash_in_hand">Cash in Hand</label>
                    <input type="number" step="0.01" min="0" class="amount-input" id="cash_in_hand" name="cash_in_hand"
                           value="<?= htmlspecialchars($form['cash_in_hand']) ?>" placeholder="0.00" oninput="recalc()">
                </div>
                <div class="field">
                    <label for="cash_at_bank">Cash at Bank</label>
                    <input type="number" step="0.01" min="0" class="amount-input" id="cash_at_bank" name="cash_at_bank"
                           value="<?= htmlspecialchars($form['cash_at_bank']) ?>" placeholder="0.00" oninput="recalc()">
                </div>
                <div class="field">
                    <label for="fee_receivable">Student Fee Receivable</label>
                    <input type="number" step="0.01" min="0" class="amount-input" id="fee_receivable" name="fee_receivable"
                           value="<?= htmlspecialchars($form['fee_receivable']) ?>" placeholder="0.00" oninput="recalc()">
                </div>
            </div>
            <div class="grid-3">
                <div class="field">
                    <label for="furniture">Furniture &amp; Fixtures</label>
                    <input type="number" step="0.01" min="0" class="amount-input" id="furniture" name="furniture"
                           value="<?= htmlspecialchars($form['furniture']) ?>" placeholder="0.00" oninput="recalc()">
                </div>
                <div class="field">
                    <label for="computers">Computers &amp; Equipment</label>
                    <input type="number" step="0.01" min="0" class="amount-input" id="computers" name="computers"
                           value="<?= htmlspecialchars($form['computers']) ?>" placeholder="0.00" oninput="recalc()">
                </div>
                <div class="field">
                    <label for="vehicles">Vehicles</label>
                    <input type="number" step="0.01" min="0" class="amount-input" id="vehicles" name="vehicles"
                           value="<?= htmlspecialchars($form['vehicles']) ?>" placeholder="0.00" oninput="recalc()">
                </div>
            </div>

            <div class="section-title"><i class="fas fa-file-invoice-dollar"></i> Liabilities</div>
            <div class="grid-3">
                <div class="field">
                    <label for="accounts_payable">Accounts Payable</label>
                    <input type="number" step="0.01" min="0" class="amount-input" id="accounts_payable" name="accounts_payable"
                           value="<?= htmlspecialchars($form['accounts_payable']) ?>" placeholder="0.00" oninput="recalc()">
                </div>
                <div class="field">
                    <label for="advance_fee">Advance Fee Received</label>
                    <input type="number" step="0.01" min="0" class="amount-input" id="advance_fee" name="advance_fee"
                           value="<?= htmlspecialchars($form['advance_fee']) ?>" placeholder="0.00" oninput="recalc()">
                </div>
                <div class="field">
                    <label for="salary_payable">Salary Payable</label>
                    <input type="number" step="0.01" min="0" class="amount-input" id="salary_payable" name="salary_payable"
                           value="<?= htmlspecialchars($form['salary_payable']) ?>" placeholder="0.00" oninput="recalc()">
                </div>
            </div>
            <div class="grid-3">
                <div class="field">
                    <label for="bank_loans">Bank Loans</label>
                    <input type="number" step="0.01" min="0" class="amount-input" id="bank_loans" name="bank_loans"
                           value="<?= htmlspecialchars($form['bank_loans']) ?>" placeholder="0.00" oninput="recalc()">
                </div>
            </div>

            <div class="section-title"><i class="fas fa-book"></i> Journal Entry Preview</div>

            <div class="preview-wrap">
                <div class="pv-head">
                    <span><i class="fas fa-book"></i> Manual Opening Entry</span>
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
                            <strong>Post to non-empty ledger (override)</strong> &mdash; <?= number_format($ledgerCount) ?> posted transaction(s) exist.
                        </span>
                    </label>
                </div>
            <?php endif; ?>

            <div class="actions">
                <a href="gl_dashboard.php" class="btn btn-ghost">Cancel</a>
                <button type="submit" name="submit_manual_opening" value="1" class="btn btn-post" id="submitBtn" disabled>
                    <i class="fas fa-save"></i> Post Manual Opening
                </button>
            </div>

        </form>
        <?php endif; ?>
    </div>
</div>

<script>
var ACCOUNTS = {
    capital_introduced: { code: '3100', name: 'Retained Earnings' },
    cash_in_hand:       { code: '1010', name: 'Cash in Hand' },
    cash_at_bank:       { code: '1020', name: 'Cash at Bank' },
    fee_receivable:     { code: '1030', name: 'Student Fee Receivable' },
    furniture:          { code: '1510', name: 'Furniture & Fixtures' },
    computers:          { code: '1520', name: 'Computers & Equipment' },
    vehicles:           { code: '1530', name: 'Vehicles' },
    accounts_payable:   { code: '2010', name: 'Accounts Payable' },
    advance_fee:        { code: '2020', name: 'Advance Fee Received' },
    salary_payable:     { code: '2030', name: 'Salary Payable' },
    bank_loans:         { code: '2510', name: 'Bank Loans' }
};

var ASSETS = ['cash_in_hand','cash_at_bank','fee_receivable','furniture','computers','vehicles'];
var LIABS  = ['accounts_payable','advance_fee','salary_payable','bank_loans'];

function fmt(n) {
    if (!isFinite(n)) n = 0;
    return n.toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function recalc() {
    var tbody = document.getElementById('previewBody');
    var rows = [];
    var totalDr = 0;
    var totalCr = 0;

    ASSETS.forEach(function(f) {
        var v = parseFloat(document.getElementById(f).value) || 0;
        if (v < 0) v = 0;
        if (v < 0.005) return;
        rows.push({ code: ACCOUNTS[f].code, name: ACCOUNTS[f].name, debit: v, credit: 0 });
        totalDr += v;
    });

    LIABS.forEach(function(f) {
        var v = parseFloat(document.getElementById(f).value) || 0;
        if (v < 0) v = 0;
        if (v < 0.005) return;
        rows.push({ code: ACCOUNTS[f].code, name: ACCOUNTS[f].name, debit: 0, credit: v });
        totalCr += v;
    });

    var manualEquity = parseFloat(document.getElementById('capital_introduced').value) || 0;
    if (manualEquity < 0) manualEquity = 0;
    if (manualEquity > 0) {
        rows.push({ code: ACCOUNTS.capital_introduced.code, name: ACCOUNTS.capital_introduced.name + ' (Capital)', debit: 0, credit: manualEquity });
        totalCr += manualEquity;
    }

    var plug = Math.round((totalDr - totalCr) * 100) / 100;
    if (Math.abs(plug) > 0.005) {
        if (plug > 0) {
            rows.push({ code: ACCOUNTS.capital_introduced.code, name: ACCOUNTS.capital_introduced.name + ' (plug)', debit: 0, credit: plug });
            totalCr += plug;
        } else {
            rows.push({ code: ACCOUNTS.capital_introduced.code, name: ACCOUNTS.capital_introduced.name + ' (loss)', debit: Math.abs(plug), credit: 0 });
            totalDr += Math.abs(plug);
        }
    }

    var html = '';
    if (rows.length === 0) {
        html = '<tr><td colspan="4" style="text-align:center; padding:20px; color:#94a3b8; font-style:italic;">Enter amounts above</td></tr>';
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