<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");

$_SESSION['lang'] = 'en';
$lang = $_SESSION['lang'];

$translations['en'] = [
    'title'         => 'New Journal Entry',
    'form_title'    => 'New Journal Entry',
    'entry_date'    => 'Entry Date',
    'session'       => 'Session',
    'description'   => 'Description',
    'description_ph'=> 'e.g. Cash fee receipt from Student #42',
    'ref_type'      => 'Reference Type',
    'ref_type_ph'   => 'e.g. manual, adjustment, opening_balance',
    'ref_id'        => 'Reference ID',
    'ref_id_ph'     => 'Optional — source document id',
    'lines_title'   => 'Journal Lines',
    'account'       => 'Account',
    'debit'         => 'Debit',
    'credit'        => 'Credit',
    'memo'          => 'Memo',
    'memo_ph'       => 'Optional per-line note',
    'add_row'       => 'Add Row',
    'remove_row'    => 'Remove',
    'total_debit'   => 'Total Debit',
    'total_credit'  => 'Total Credit',
    'difference'    => 'Difference',
    'balanced'      => 'Balanced',
    'not_balanced'  => 'Not Balanced',
    'submit'        => 'Post Entry',
    'cancel'        => 'Cancel',
    'select_account'=> '-- Select Account --',
    'errors_title'  => 'Please fix the following:',
    'err_min_lines' => 'At least 2 lines are required.',
    'err_account'   => 'Every line must have an account.',
    'err_debit_xor_credit' => 'Each line must be either a debit OR a credit, not both, not neither.',
    'err_negative'  => 'Amounts cannot be negative.',
    'err_unbalanced'=> 'Total debits must equal total credits.',
    'err_zero'      => 'Amounts cannot be zero.',
    'err_description' => 'Description is required.',
    'err_date'      => 'A valid entry date is required.',
    'err_session'   => 'Session is required.',
    'err_db'        => 'Database error: ',
    'success'       => 'Journal entry posted successfully.',
];

// ---------------------------------------------------------------------------
// Load accounts (postable, active)
// ---------------------------------------------------------------------------
$accounts = $conn->query("
    SELECT id, code, name, account_type
    FROM gl_accounts
    WHERE is_postable = 1 AND status = 1
    ORDER BY account_type, code
")->fetch_all(MYSQLI_ASSOC);

// Group by type for optgroups
$accountsByType = [
    'ASSET'     => [],
    'LIABILITY' => [],
    'EQUITY'    => [],
    'REVENUE'   => [],
    'EXPENSE'   => [],
];
foreach ($accounts as $a) {
    $accountsByType[$a['account_type']][] = $a;
}

// Load sessions
$sessions = $conn->query("
    SELECT id, title, from_dated, to_dated, status
    FROM sessions
    ORDER BY status ASC, id DESC
")->fetch_all(MYSQLI_ASSOC);

$currentSessionId = null;
foreach ($sessions as $s) {
    if ((int)$s['status'] === 0) {  // 0 = active in your schema
        $currentSessionId = (int)$s['id'];
        break;
    }
}
if ($currentSessionId === null && !empty($sessions)) {
    $currentSessionId = (int)$sessions[0]['id'];
}

// ---------------------------------------------------------------------------
// Handle POST
// ---------------------------------------------------------------------------
$errors    = [];
$postedTxn = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_journal'])) {
    // --- Collect header
    $entryDate   = trim($_POST['entry_date'] ?? '');
    $sessionId   = (int)($_POST['session_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $refType     = trim($_POST['ref_type']   ?? '');
    $refId       = trim($_POST['ref_id']     ?? '');
    $idempotency = trim($_POST['idempotency_key'] ?? '');

    if ($idempotency === '') {
        $idempotency = 'jrnl-' . bin2hex(random_bytes(16));
    }

    // --- Collect lines
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

            // Skip completely empty rows
            if ($accId === 0 && $debitF === 0.0 && $creditF === 0.0 && $memo === '') {
                continue;
            }

            $lines[] = [
                'account_id' => $accId,
                'debit'      => $debitF,
                'credit'     => $creditF,
                'memo'       => $memo === '' ? null : $memo,
            ];
        }
    }

    // --- Validate header
    if ($description === '') {
        $errors[] = $translations[$lang]['err_description'];
    }
    if ($entryDate === '' || !strtotime($entryDate)) {
        $errors[] = $translations[$lang]['err_date'];
    }
    if ($sessionId <= 0) {
        $errors[] = $translations[$lang]['err_session'];
    }

    // --- Validate lines
    if (count($lines) < 2) {
        $errors[] = $translations[$lang]['err_min_lines'];
    } else {
        $totalD = 0.0;
        $totalC = 0.0;
        foreach ($lines as $i => $l) {
            $n = $i + 1;
            if ($l['account_id'] <= 0) {
                $errors[] = "Line $n: " . $translations[$lang]['err_account'];
            }
            if ($l['debit'] < 0 || $l['credit'] < 0) {
                $errors[] = "Line $n: " . $translations[$lang]['err_negative'];
            }
            $d = round($l['debit'], 2);
            $c = round($l['credit'], 2);
            if ($d === 0.0 && $c === 0.0) {
                $errors[] = "Line $n: " . $translations[$lang]['err_zero'];
            }
            if (($d > 0 && $c > 0) || ($d === 0.0 && $c === 0.0)) {
                $errors[] = "Line $n: " . $translations[$lang]['err_debit_xor_credit'];
            }
            $totalD += $d;
            $totalC += $c;
        }
        if (round($totalD, 2) !== round($totalC, 2)) {
            $errors[] = $translations[$lang]['err_unbalanced']
                      . ' (D=' . number_format($totalD, 2)
                      . ' C=' . number_format($totalC, 2) . ')';
        }
    }

    // --- Post
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
            $_SESSION['message'] = "<div style='color: #3c763d; background-color: #dff0d8; border: 1px solid #d6e9c6; padding: 15px; border-radius: 4px;'>"
                . "<strong>✓ " . $translations[$lang]['success'] . "</strong>"
                . "<br>Transaction #" . $txnId
                . "</div>";
            $_SESSION['message_type'] = 'success';
            header("Location: gl_transaction_view.php?id=" . $txnId);
            exit();
        } else {
            $errors[] = $translations[$lang]['err_db'] . htmlspecialchars($dbErr ?? 'unknown');
        }
    }
}

// Form state for redisplay on error
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
    // Default blank rows
    $postedLines = [
        ['account_id' => 0, 'debit' => '', 'credit' => '', 'memo' => ''],
        ['account_id' => 0, 'debit' => '', 'credit' => '', 'memo' => ''],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once('meta_inc.php'); ?>
    <style>
        .line-table th { font-size: 12px; color: #555; background: #f5f5f5; }
        .line-table td { vertical-align: middle !important; }
        .line-table .account-cell { min-width: 280px; }
        .line-table .amount-cell { width: 140px; }
        .line-table .memo-cell { min-width: 200px; }
        .line-table .action-cell { width: 60px; }
        .amount-input { text-align: right; font-family: monospace; }
        .totals-bar {
            background: #eef4ff;
            border: 1px solid #b8c9e8;
            padding: 12px 15px;
            border-radius: 4px;
            margin-top: 15px;
        }
        .totals-bar .lbl { font-size: 12px; color: #555; }
        .totals-bar .val { font-size: 18px; font-family: monospace; font-weight: bold; }
        .totals-bar.balanced { background: #e5f5e5; border-color: #a8d5a8; }
        .totals-bar.unbalanced { background: #fbe9e9; border-color: #e0b0b0; }
        .balance-status { font-size: 14px; font-weight: bold; padding: 3px 10px; border-radius: 3px; }
        .balance-status.ok   { background: #5cb85c; color: #fff; }
        .balance-status.bad  { background: #d9534f; color: #fff; }
        .remove-line-btn { color: #d9534f; cursor: pointer; }
        .remove-line-btn:hover { color: #a94442; }
        .required-star { color: #d9534f; }
        .input-sm-mono { font-family: monospace; font-size: 13px; }
        .form-section-title { font-weight: bold; color: #337ab7; border-bottom: 2px solid #337ab7; padding-bottom: 5px; margin: 20px 0 15px 0; }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="container">
    <div class="row">
        <div class="col-md-12">

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger" style="margin-top: 15px;">
                    <strong>✗ <?php echo $translations[$lang]['errors_title']; ?></strong>
                    <ul style="margin-bottom: 0;">
                        <?php foreach ($errors as $e): ?>
                            <li><?php echo $e; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="panel panel-success" style="margin-top: 15px;">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <span class="glyphicon glyphicon-plus-sign"></span>
                        <?php echo $translations[$lang]['form_title']; ?>
                    </h3>
                </div>
                <div class="panel-body">

                    <form method="post" action="" id="journalForm">
                        <input type="hidden" name="idempotency_key"
                               value="<?php echo htmlspecialchars($form['idempotency']); ?>">

                        <div class="form-section-title">Entry Header</div>

                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="entry_date">
                                        <?php echo $translations[$lang]['entry_date']; ?>
                                        <span class="required-star">*</span>
                                    </label>
                                    <input type="date" class="form-control" id="entry_date"
                                           name="entry_date" required
                                           value="<?php echo htmlspecialchars($form['entry_date']); ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="session_id">
                                        <?php echo $translations[$lang]['session']; ?>
                                        <span class="required-star">*</span>
                                    </label>
                                    <select class="form-control" id="session_id" name="session_id" required>
                                        <?php foreach ($sessions as $s): ?>
                                            <option value="<?php echo (int)$s['id']; ?>"
                                                <?php echo ((int)$form['session_id'] === (int)$s['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($s['title']); ?>
                                                <?php if ((int)$s['status'] === 0): ?>
                                                    (active)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="ref_type"><?php echo $translations[$lang]['ref_type']; ?></label>
                                    <input type="text" class="form-control" id="ref_type" name="ref_type"
                                           maxlength="50"
                                           placeholder="<?php echo $translations[$lang]['ref_type_ph']; ?>"
                                           value="<?php echo htmlspecialchars($form['ref_type']); ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="ref_id"><?php echo $translations[$lang]['ref_id']; ?></label>
                                    <input type="text" class="form-control" id="ref_id" name="ref_id"
                                           placeholder="<?php echo $translations[$lang]['ref_id_ph']; ?>"
                                           value="<?php echo htmlspecialchars($form['ref_id']); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label for="description">
                                        <?php echo $translations[$lang]['description']; ?>
                                        <span class="required-star">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="description" name="description"
                                           maxlength="500" required
                                           placeholder="<?php echo $translations[$lang]['description_ph']; ?>"
                                           value="<?php echo htmlspecialchars($form['description']); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="form-section-title">Journal Lines</div>

                        <div class="table-responsive">
                            <table class="table table-bordered line-table" id="linesTable">
                                <thead>
                                    <tr>
                                        <th style="width: 40px;">#</th>
                                        <th><?php echo $translations[$lang]['account']; ?> <span class="required-star">*</span></th>
                                        <th class="amount-cell text-right"><?php echo $translations[$lang]['debit']; ?></th>
                                        <th class="amount-cell text-right"><?php echo $translations[$lang]['credit']; ?></th>
                                        <th class="memo-cell"><?php echo $translations[$lang]['memo']; ?></th>
                                        <th class="action-cell"></th>
                                    </tr>
                                </thead>
                                <tbody id="linesBody">
                                    <?php foreach ($postedLines as $i => $line): ?>
                                        <tr class="line-row">
                                            <td class="line-no text-center"><?php echo $i + 1; ?></td>
                                            <td class="account-cell">
                                                <select class="form-control input-sm account-select" name="account_id[]">
                                                    <option value=""><?php echo $translations[$lang]['select_account']; ?></option>
                                                    <?php foreach ($accountsByType as $type => $list): ?>
                                                        <?php if (empty($list)) continue; ?>
                                                        <optgroup label="<?php echo gl_type_label($type); ?>">
                                                            <?php foreach ($list as $a): ?>
                                                                <option value="<?php echo (int)$a['id']; ?>"
                                                                    <?php echo ((int)$line['account_id'] === (int)$a['id']) ? 'selected' : ''; ?>>
                                                                    <?php echo htmlspecialchars($a['code'] . ' — ' . $a['name']); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </optgroup>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td class="amount-cell">
                                                <input type="number" step="0.01" min="0"
                                                       class="form-control input-sm amount-input debit-input"
                                                       name="debit[]"
                                                       value="<?php echo htmlspecialchars($line['debit']); ?>">
                                            </td>
                                            <td class="amount-cell">
                                                <input type="number" step="0.01" min="0"
                                                       class="form-control input-sm amount-input credit-input"
                                                       name="credit[]"
                                                       value="<?php echo htmlspecialchars($line['credit']); ?>">
                                            </td>
                                            <td class="memo-cell">
                                                <input type="text" class="form-control input-sm"
                                                       name="memo[]" maxlength="255"
                                                       placeholder="<?php echo $translations[$lang]['memo_ph']; ?>"
                                                       value="<?php echo htmlspecialchars($line['memo']); ?>">
                                            </td>
                                            <td class="action-cell text-center">
                                                <span class="glyphicon glyphicon-trash remove-line-btn" title="Remove"></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="row">
                            <div class="col-md-12">
                                <button type="button" id="addRowBtn" class="btn btn-sm btn-default">
                                    <span class="glyphicon glyphicon-plus"></span>
                                    <?php echo $translations[$lang]['add_row']; ?>
                                </button>
                            </div>
                        </div>

                        <div class="totals-bar" id="totalsBar">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="lbl"><?php echo $translations[$lang]['total_debit']; ?></div>
                                    <div class="val" id="totalDebit">0.00</div>
                                </div>
                                <div class="col-md-3">
                                    <div class="lbl"><?php echo $translations[$lang]['total_credit']; ?></div>
                                    <div class="val" id="totalCredit">0.00</div>
                                </div>
                                <div class="col-md-3">
                                    <div class="lbl"><?php echo $translations[$lang]['difference']; ?></div>
                                    <div class="val" id="totalDiff">0.00</div>
                                </div>
                                <div class="col-md-3 text-right">
                                    <span class="balance-status bad" id="balanceStatus">
                                        <?php echo $translations[$lang]['not_balanced']; ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="row" style="margin-top: 20px;">
                            <div class="col-md-12 text-right">
                                <a href="gl_transactions.php" class="btn btn-default btn-lg">
                                    <?php echo $translations[$lang]['cancel']; ?>
                                </a>
                                <button type="submit" name="submit_journal" value="1"
                                        class="btn btn-success btn-lg" id="submitBtn" disabled>
                                    <span class="glyphicon glyphicon-floppy-disk"></span>
                                    <?php echo $translations[$lang]['submit']; ?>
                                </button>
                            </div>
                        </div>

                    </form>

                </div>
            </div>

        </div>
    </div>
</div>

<!-- Row template (cloned by JS) -->
<script type="text/template" id="lineRowTemplate">
    <tr class="line-row">
        <td class="line-no text-center"></td>
        <td class="account-cell">
            <select class="form-control input-sm account-select" name="account_id[]">
                <option value=""><?php echo $translations[$lang]['select_account']; ?></option>
                <?php foreach ($accountsByType as $type => $list): ?>
                    <?php if (empty($list)) continue; ?>
                    <optgroup label="<?php echo gl_type_label($type); ?>">
                        <?php foreach ($list as $a): ?>
                            <option value="<?php echo (int)$a['id']; ?>">
                                <?php echo htmlspecialchars($a['code'] . ' — ' . $a['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </td>
        <td class="amount-cell">
            <input type="number" step="0.01" min="0"
                   class="form-control input-sm amount-input debit-input" name="debit[]">
        </td>
        <td class="amount-cell">
            <input type="number" step="0.01" min="0"
                   class="form-control input-sm amount-input credit-input" name="credit[]">
        </td>
        <td class="memo-cell">
            <input type="text" class="form-control input-sm" name="memo[]" maxlength="255"
                   placeholder="<?php echo $translations[$lang]['memo_ph']; ?>">
        </td>
        <td class="action-cell text-center">
            <span class="glyphicon glyphicon-trash remove-line-btn" title="Remove"></span>
        </td>
    </tr>
</script>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
<script>
(function($) {
    'use strict';

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
            status.removeClass('bad').addClass('ok')
                  .text('<?php echo addslashes($translations[$lang]['balanced']); ?> ✓');
            btn.prop('disabled', false);
        } else {
            bar.removeClass('balanced').addClass('unbalanced');
            status.removeClass('ok').addClass('bad')
                  .text('<?php echo addslashes($translations[$lang]['not_balanced']); ?>');
            btn.prop('disabled', true);
        }
    }

    function enforceDebitCreditExclusive(row) {
        // Not strictly required — user may enter both if they want to fix later.
        // But UX hint: styling. We'll just leave both editable.
    }

    // Add row
    $('#addRowBtn').on('click', function() {
        var tpl = $('#lineRowTemplate').html();
        var $row = $(tpl);
        $('#linesBody').append($row);
        renumber();
        recalc();
    });

    // Remove row (delegate)
    $(document).on('click', '.remove-line-btn', function() {
        var $rows = $('#linesBody .line-row');
        if ($rows.length <= 2) {
            alert('At least 2 lines are required.');
            return;
        }
        $(this).closest('tr').remove();
        renumber();
        recalc();
    });

    // Recalc on input
    $(document).on('input change', '.debit-input, .credit-input', recalc);

    // Initial
    renumber();
    recalc();
})(jQuery);
</script>

</body>
<?php if (isset($conn)) $conn->close(); ?>
</html>