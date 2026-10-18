<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");

/* ───────── Accounts grouped by type ───────── */
$accounts = [];
$r = $conn->query("SELECT id, code, name, account_type FROM gl_accounts WHERE is_postable = 1 AND status = 1 ORDER BY account_type, code");
if ($r) { while ($row = $r->fetch_assoc()) $accounts[] = $row; }

$accountsByType = [
    'ASSET'     => [],
    'LIABILITY' => [],
    'EQUITY'    => [],
    'REVENUE'   => [],
    'EXPENSE'   => [],
];
foreach ($accounts as $a) $accountsByType[$a['account_type']][] = $a;

/* ───────── Sessions ───────── */
$sessions = [];
$r = $conn->query("SELECT id, title, from_dated, to_dated, status FROM sessions ORDER BY status ASC, id DESC");
if ($r) { while ($row = $r->fetch_assoc()) $sessions[] = $row; }

$currentSessionId = null;
foreach ($sessions as $s) {
    if ((int)$s['status'] === 0) { $currentSessionId = (int)$s['id']; break; }
}
if ($currentSessionId === null && !empty($sessions)) $currentSessionId = (int)$sessions[0]['id'];

/* ───────── POST ───────── */
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_journal'])) {
    $entryDate   = trim($_POST['entry_date'] ?? '');
    $sessionId   = (int)($_POST['session_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $refType     = trim($_POST['ref_type']   ?? '');
    $refId       = trim($_POST['ref_id']     ?? '');
    $idempotency = trim($_POST['idempotency_key'] ?? '');

    if ($idempotency === '') $idempotency = 'jrnl-' . bin2hex(random_bytes(16));

    $lines = [];
    if (isset($_POST['account_id']) && is_array($_POST['account_id'])) {
        $count = count($_POST['account_id']);
        for ($i = 0; $i < $count; $i++) {
            $accId  = (int)($_POST['account_id'][$i] ?? 0);
            $debit  = trim($_POST['debit'][$i]  ?? '');
            $credit = trim($_POST['credit'][$i] ?? '');
            $memo   = trim($_POST['memo'][$i]   ?? '');

            $debitF  = ($debit  === '') ? 0.0 : (float)$debit;
            $creditF = ($credit === '') ? 0.0 : (float)$credit;

            if ($accId === 0 && $debitF === 0.0 && $creditF === 0.0 && $memo === '') continue;

            $lines[] = [
                'account_id' => $accId,
                'debit'      => $debitF,
                'credit'     => $creditF,
                'memo'       => $memo === '' ? null : $memo,
            ];
        }
    }

    if ($description === '') $errors[] = 'Description is required.';
    if ($entryDate === '' || !strtotime($entryDate)) $errors[] = 'A valid entry date is required.';
    if ($sessionId <= 0) $errors[] = 'Session is required.';

    if (count($lines) < 2) {
        $errors[] = 'At least 2 lines are required.';
    } else {
        $totalD = 0.0; $totalC = 0.0;
        foreach ($lines as $i => $l) {
            $n = $i + 1;
            if ($l['account_id'] <= 0) $errors[] = "Line $n: account is required.";
            if ($l['debit'] < 0 || $l['credit'] < 0) $errors[] = "Line $n: amounts cannot be negative.";
            $d = round($l['debit'], 2);
            $c = round($l['credit'], 2);
            if ($d === 0.0 && $c === 0.0) $errors[] = "Line $n: amount cannot be zero.";
            if (($d > 0 && $c > 0) || ($d === 0.0 && $c === 0.0)) $errors[] = "Line $n: must be either a debit OR a credit.";
            $totalD += $d; $totalC += $c;
        }
        if (round($totalD, 2) !== round($totalC, 2)) {
            $errors[] = 'Total debits must equal total credits (D=' . number_format($totalD, 2) . ' C=' . number_format($totalC, 2) . ').';
        }
    }

    if (empty($errors)) {
        $postedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;

        $dbErr = null;
        $txnId = post_journal_entry($conn, [
            'entry_date'  => $entryDate,
            'session_id'  => $sessionId,
            'description' => $description,
            'ref_type'    => $refType === '' ? null : $refType,
            'ref_id'      => $refId   === '' ? null : (int)$refId,
            'posted_by'   => $postedBy,
            'idempotency' => $idempotency,
            'lines'       => $lines,
        ], $dbErr);

        if ($txnId > 0) {
            $_SESSION['message'] = "Journal entry posted successfully — Transaction #$txnId";
            $_SESSION['message_type'] = 'success';
            header("Location: gl_transaction_view.php?id=" . $txnId);
            exit();
        } else {
            $errors[] = 'Database error: ' . htmlspecialchars($dbErr ?? 'unknown');
        }
    }
}

/* ───────── Form state ───────── */
$form = [
    'entry_date'  => $_POST['entry_date']  ?? date('Y-m-d'),
    'session_id'  => $_POST['session_id']  ?? $currentSessionId,
    'description' => $_POST['description'] ?? '',
    'ref_type'    => $_POST['ref_type']    ?? '',
    'ref_id'      => $_POST['ref_id']      ?? '',
    'idempotency' => $_POST['idempotency_key'] ?? ('jrnl-' . bin2hex(random_bytes(16))),
];

$postedLines = [];
if (isset($_POST['account_id']) && is_array($_POST['account_id'])) {
    for ($i = 0; $i < count($_POST['account_id']); $i++) {
        $postedLines[] = [
            'account_id' => (int)($_POST['account_id'][$i] ?? 0),
            'debit'      => $_POST['debit'][$i]  ?? '',
            'credit'     => $_POST['credit'][$i] ?? '',
            'memo'       => $_POST['memo'][$i]   ?? '',
        ];
    }
}
if (count($postedLines) < 2) {
    $postedLines = [
        ['account_id' => 0, 'debit' => '', 'credit' => '', 'memo' => ''],
        ['account_id' => 0, 'debit' => '', 'credit' => '', 'memo' => ''],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>New Journal Entry</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    body { background: #f5f7fa; font-family: 'Inter', sans-serif; color: #1e293b; padding-bottom: 40px; }
    .wrap { max-width: 1200px; margin: 24px auto; padding: 0 20px; }

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

    .field label { display: block; font-size: 10.5px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 4px; }
    .field .form-control { height: 34px; padding: 5px 10px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 12.5px; font-family: inherit; color: #0f172a; background: #fff; }
    .field select.form-control { height: 34px; }
    .field .form-control:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.12); }

    .grid-4 { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 10px; }
    .grid-1 { display: grid; grid-template-columns: 1fr; gap: 10px; }
    @media (max-width: 900px) { .grid-4 { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 600px) { .grid-4 { grid-template-columns: 1fr; } }

    /* Lines table */
    .lines-wrap { border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; margin-top: 4px; }
    .lines-table { width: 100%; margin: 0; border-collapse: collapse; font-size: 12.5px; }
    .lines-table thead th { background: #f8fafc; font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.6px; padding: 8px 10px; border-bottom: 1px solid #e2e8f0; text-align: left; white-space: nowrap; }
    .lines-table thead th.num { text-align: right; }
    .lines-table thead th.act { width: 36px; }
    .lines-table tbody td { padding: 6px 8px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .lines-table tbody tr:last-child td { border-bottom: none; }
    .lines-table tbody tr:hover td { background: #fafbfc; }
    .lines-table .line-no { width: 36px; text-align: center; font-size: 11px; color: #94a3b8; font-weight: 700; font-family: 'SF Mono', monospace; }
    .lines-table .account-cell { min-width: 260px; }
    .lines-table .amount-cell { width: 120px; }
    .lines-table .memo-cell { min-width: 180px; }
    .lines-table .action-cell { width: 36px; text-align: center; }

    .lines-table select,
    .lines-table input { height: 30px; padding: 4px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; font-family: inherit; background: #fff; width: 100%; color: #0f172a; }
    .lines-table select:focus, .lines-table input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.12); }
    .amount-input { font-family: 'SF Mono', monospace; text-align: right; font-weight: 600; }

    .remove-line-btn { width: 26px; height: 26px; border-radius: 5px; background: #fff; border: 1px solid #e2e8f0; color: #94a3b8; display: inline-flex; align-items: center; justify-content: center; font-size: 11px; cursor: pointer; transition: all 0.15s; padding: 0; }
    .remove-line-btn:hover { background: #fef2f2; border-color: #fecaca; color: #dc2626; }

    /* Add row button */
    .add-row-btn { display: inline-flex; align-items: center; gap: 5px; margin-top: 8px; height: 30px; padding: 0 12px; background: #fff; border: 1px dashed #cbd5e1; color: #2563eb; border-radius: 7px; font-size: 11.5px; font-weight: 700; cursor: pointer; font-family: inherit; }
    .add-row-btn:hover { background: #eff6ff; border-color: #93c5fd; }

    /* Totals bar */
    .totals-bar { display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 10px; background: #f0f5ff; border: 1px solid #c9d7ef; padding: 10px 14px; border-radius: 8px; margin-top: 14px; align-items: center; }
    .totals-bar.balanced { background: #d1fae5; border-color: #86efac; }
    .totals-bar.unbalanced { background: #fee2e2; border-color: #fecaca; }
    .totals-bar .t-lbl { font-size: 9.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
    .totals-bar .t-val { font-family: 'SF Mono', monospace; font-size: 14px; font-weight: 700; font-variant-numeric: tabular-nums; color: #0f172a; margin-top: 2px; }
    .balance-indicator { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 20px; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; }
    .balance-indicator.ok { background: #059669; color: #fff; }
    .balance-indicator.bad { background: #dc2626; color: #fff; }

    .actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; padding-top: 14px; border-top: 1px solid #e2e8f0; }
    .btn { height: 36px; padding: 0 16px; border-radius: 7px; font-size: 12.5px; font-weight: 600; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; font-family: inherit; }
    .btn-ghost { background: #fff; border: 1px solid #cbd5e1; color: #475569; }
    .btn-ghost:hover { background: #f8fafc; color: #1e293b; text-decoration: none; }
    .btn-post { background: #2563eb; color: #fff; }
    .btn-post:hover { background: #1d4ed8; color: #fff; text-decoration: none; }
    .btn-post:disabled { background: #94a3b8; cursor: not-allowed; }
    .help-text { font-size: 10.5px; color: #64748b; margin-top: 3px; line-height: 1.4; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <h1><i class="fas fa-book-open"></i> New Journal Entry</h1>
            <div class="sub">Post a balanced double-entry journal entry</div>
        </div>
        <a href="gl_transactions.php" class="btn-back"><i class="fas fa-arrow-left"></i> Transactions</a>
    </div>

    <div class="card">

        <?php if (!empty($errors)): ?>
            <div class="error-box">
                <strong><i class="fas fa-exclamation-circle"></i> Please fix:</strong>
                <ul>
                    <?php foreach ($errors as $e): ?>
                        <li><?= $e ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($perms['can_add'])): ?>

        <form method="post" action="" id="journalForm">
            <input type="hidden" name="idempotency_key" value="<?= htmlspecialchars($form['idempotency']) ?>">

            <div class="section-title"><i class="fas fa-calendar-alt"></i> Entry Header</div>

            <div class="grid-4">
                <div class="field">
                    <label for="entry_date">Entry Date <span style="color: #dc2626;">*</span></label>
                    <input type="date" class="form-control" id="entry_date" name="entry_date" required value="<?= htmlspecialchars($form['entry_date']) ?>">
                </div>
                <div class="field">
                    <label for="session_id">Session <span style="color: #dc2626;">*</span></label>
                    <select class="form-control" id="session_id" name="session_id" required>
                        <?php foreach ($sessions as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= ((int)$form['session_id'] === (int)$s['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['title']) ?>
                                <?= ((int)$s['status'] === 0) ? ' (active)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="ref_type">Reference Type</label>
                    <input type="text" class="form-control" id="ref_type" name="ref_type" maxlength="50" value="<?= htmlspecialchars($form['ref_type']) ?>" placeholder="e.g. manual, adjustment">
                </div>
                <div class="field">
                    <label for="ref_id">Reference ID</label>
                    <input type="text" class="form-control" id="ref_id" name="ref_id" value="<?= htmlspecialchars($form['ref_id']) ?>" placeholder="Optional source doc id">
                </div>
            </div>

            <div class="grid-1" style="margin-top: 10px;">
                <div class="field">
                    <label for="description">Description <span style="color: #dc2626;">*</span></label>
                    <input type="text" class="form-control" id="description" name="description" maxlength="500" required value="<?= htmlspecialchars($form['description']) ?>" placeholder="e.g. Cash fee receipt from Student #42">
                </div>
            </div>

            <div class="section-title">
                <i class="fas fa-list"></i> Journal Lines
            </div>

            <div class="lines-wrap">
                <table class="lines-table" id="linesTable">
                    <thead>
                        <tr>
                            <th class="line-no">#</th>
                            <th>Account</th>
                            <th class="num">Debit</th>
                            <th class="num">Credit</th>
                            <th>Memo</th>
                            <th class="act"></th>
                        </tr>
                    </thead>
                    <tbody id="linesBody">
                        <?php foreach ($postedLines as $i => $line): ?>
                            <tr class="line-row">
                                <td class="line-no"><?= $i + 1 ?></td>
                                <td class="account-cell">
                                    <select class="account-select" name="account_id[]">
                                        <option value="">— Select Account —</option>
                                        <?php foreach ($accountsByType as $type => $list): ?>
                                            <?php if (empty($list)) continue; ?>
                                            <optgroup label="<?= gl_type_label($type) ?>">
                                                <?php foreach ($list as $a): ?>
                                                    <option value="<?= (int)$a['id'] ?>" <?= ((int)$line['account_id'] === (int)$a['id']) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($a['code'] . ' — ' . $a['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="amount-cell">
                                    <input type="number" step="0.01" min="0" class="amount-input debit-input" name="debit[]" value="<?= htmlspecialchars($line['debit']) ?>">
                                </td>
                                <td class="amount-cell">
                                    <input type="number" step="0.01" min="0" class="amount-input credit-input" name="credit[]" value="<?= htmlspecialchars($line['credit']) ?>">
                                </td>
                                <td class="memo-cell">
                                    <input type="text" name="memo[]" maxlength="255" placeholder="Optional note" value="<?= htmlspecialchars($line['memo']) ?>">
                                </td>
                                <td class="action-cell">
                                    <button type="button" class="remove-line-btn" title="Remove"><i class="fas fa-times"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <button type="button" id="addRowBtn" class="add-row-btn"><i class="fas fa-plus"></i> Add Row</button>

            <div class="totals-bar" id="totalsBar">
                <div>
                    <div class="t-lbl">Total Debit</div>
                    <div class="t-val" id="totalDebit">0.00</div>
                </div>
                <div>
                    <div class="t-lbl">Total Credit</div>
                    <div class="t-val" id="totalCredit">0.00</div>
                </div>
                <div>
                    <div class="t-lbl">Difference</div>
                    <div class="t-val" id="totalDiff">0.00</div>
                </div>
                <div style="text-align: right;">
                    <span class="balance-indicator bad" id="balanceStatus">Not Balanced</span>
                </div>
            </div>

            <div class="actions">
                <a href="gl_transactions.php" class="btn btn-ghost">Cancel</a>
                <button type="submit" name="submit_journal" value="1" class="btn btn-post" id="submitBtn" disabled>
                    <i class="fas fa-save"></i> Post Entry
                </button>
            </div>

        </form>

        <?php endif; ?>
    </div>
</div>

<!-- Row template -->
<template id="lineRowTemplate">
    <tr class="line-row">
        <td class="line-no"></td>
        <td class="account-cell">
            <select class="account-select" name="account_id[]">
                <option value="">— Select Account —</option>
                <?php foreach ($accountsByType as $type => $list): ?>
                    <?php if (empty($list)) continue; ?>
                    <optgroup label="<?= gl_type_label($type) ?>">
                        <?php foreach ($list as $a): ?>
                            <option value="<?= (int)$a['id'] ?>"><?= htmlspecialchars($a['code'] . ' — ' . $a['name']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </td>
        <td class="amount-cell">
            <input type="number" step="0.01" min="0" class="amount-input debit-input" name="debit[]">
        </td>
        <td class="amount-cell">
            <input type="number" step="0.01" min="0" class="amount-input credit-input" name="credit[]">
        </td>
        <td class="memo-cell">
            <input type="text" name="memo[]" maxlength="255" placeholder="Optional note">
        </td>
        <td class="action-cell">
            <button type="button" class="remove-line-btn" title="Remove"><i class="fas fa-times"></i></button>
        </td>
    </tr>
</template>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {

    function renumber() {
        $('#linesBody .line-row').each(function(i) {
            $(this).find('.line-no').text(i + 1);
        });
    }

    function recalc() {
        var totalD = 0, totalC = 0;
        $('#linesBody .line-row').each(function() {
            var d = parseFloat($(this).find('.debit-input').val())  || 0;
            var c = parseFloat($(this).find('.credit-input').val()) || 0;
            if (d < 0) d = 0;
            if (c < 0) c = 0;
            totalD += d;
            totalC += c;
        });
        totalD = Math.round(totalD * 100) / 100;
        totalC = Math.round(totalC * 100) / 100;
        var diff = Math.round((totalD - totalC) * 100) / 100;

        $('#totalDebit').text(totalD.toFixed(2));
        $('#totalCredit').text(totalC.toFixed(2));
        $('#totalDiff').text(diff.toFixed(2));

        var bar = $('#totalsBar');
        var status = $('#balanceStatus');
        var btn = $('#submitBtn');

        if (totalD > 0 && totalC > 0 && diff === 0) {
            bar.removeClass('unbalanced').addClass('balanced');
            status.removeClass('bad').addClass('ok').html('<i class="fas fa-check"></i> Balanced');
            btn.prop('disabled', false);
        } else {
            bar.removeClass('balanced').addClass('unbalanced');
            status.removeClass('ok').addClass('bad').text('Not Balanced');
            btn.prop('disabled', true);
        }
    }

    $('#addRowBtn').on('click', function() {
        var tpl = document.getElementById('lineRowTemplate').innerHTML;
        $('#linesBody').append(tpl);
        renumber();
        recalc();
    });

    $(document).on('click', '.remove-line-btn', function() {
        if ($('#linesBody .line-row').length <= 2) {
            alert('At least 2 lines are required.');
            return;
        }
        $(this).closest('tr').remove();
        renumber();
        recalc();
    });

    $(document).on('input change', '.debit-input, .credit-input', recalc);

    renumber();
    recalc();
});
</script>

</body>
</html>
<?php $conn->close(); ?>