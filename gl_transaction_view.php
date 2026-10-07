<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

$_SESSION['lang'] = 'en';
$lang = $_SESSION['lang'];

$translations['en'] = [
    'view_title'    => 'Journal Entry',
    'back'          => 'Back to List',
    'date'          => 'Date',
    'session'       => 'Session',
    'description'   => 'Description',
    'reference'     => 'Reference',
    'posted_by'     => 'Posted By',
    'posted_at'     => 'Posted At',
    'posted'        => 'Posted',
    'reversed'      => 'Reversed',
    'lines_card'    => 'Journal Lines',
    'account'       => 'Account',
    'debit'         => 'Debit',
    'credit'        => 'Credit',
    'memo'          => 'Memo',
    'party'         => 'Party',
    'total'         => 'Total',
    'balanced'      => 'Balanced',
    'not_balanced'  => 'NOT BALANCED',
    'reverse_btn'   => 'Reverse This Entry',
    'reverse_title' => 'Reverse Journal Entry',
    'reverse_reason'=> 'Reason for reversal',
    'reverse_reason_ph' => 'e.g. Wrong amount entered',
    'reverse_confirm' => 'Are you sure? A reversal entry will be created. The original entry will not be deleted.',
    'cancel'        => 'Cancel',
    'confirm_reverse' => 'Confirm Reversal',
    'reverse_success' => 'Reversal posted successfully.',
    'reverse_failed'  => 'Reversal failed',
    'not_found'     => 'Journal entry not found.',
    'reason_required' => 'Reason is required.',
    'already_reversed' => 'This entry has already been reversed.',
    'reverse_of_reversal' => 'A reversal entry cannot be reversed.',
    'reversal_badge' => 'This is a reversal entry',
    'reversed_badge' => 'This entry has been reversed',
];

// ---------------------------------------------------------------------------
// Fetch transaction
// ---------------------------------------------------------------------------
$txnId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($txnId <= 0) {
    header("Location: gl_transactions.php");
    exit();
}

$stmt = $conn->prepare("
    SELECT t.*, u.username AS posted_by_name, s.title AS session_title,
           rev.id AS reversal_txn_id
    FROM gl_transactions t
    LEFT JOIN users u ON u.id = t.posted_by
    LEFT JOIN sessions s ON s.id = t.session_id
    LEFT JOIN gl_transactions rev ON rev.reversal_of = t.id AND rev.status = 'POSTED'
    WHERE t.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $txnId);
$stmt->execute();
$txn = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$txn) {
    $_SESSION['message'] = "<div style='color: #a94442; background-color: #f2dede; border: 1px solid #ebccd1; padding: 15px; border-radius: 4px;'><strong>✗ "
        . $translations[$lang]['not_found'] . "</strong></div>";
    $_SESSION['message_type'] = 'danger';
    header("Location: gl_transactions.php");
    exit();
}

// ---------------------------------------------------------------------------
// Handle POST — Reverse
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reverse_confirm'])) {
    $reason = trim($_POST['reason'] ?? '');

    if ($reason === '') {
        $_SESSION['message'] = "<div style='color: #a94442; background-color: #f2dede; border: 1px solid #ebccd1; padding: 15px; border-radius: 4px;'><strong>✗ "
            . $translations[$lang]['reason_required'] . "</strong></div>";
        $_SESSION['message_type'] = 'danger';
        header("Location: gl_transaction_view.php?id=" . $txnId);
        exit();
    }

    if ($txn['status'] === 'REVERSED') {
        $_SESSION['message'] = "<div style='color: #a94442; background-color: #f2dede; border: 1px solid #ebccd1; padding: 15px; border-radius: 4px;'><strong>✗ "
            . $translations[$lang]['already_reversed'] . "</strong></div>";
        $_SESSION['message_type'] = 'danger';
        header("Location: gl_transaction_view.php?id=" . $txnId);
        exit();
    }

    if ($txn['reversal_of'] !== null) {
        $_SESSION['message'] = "<div style='color: #a94442; background-color: #f2dede; border: 1px solid #ebccd1; padding: 15px; border-radius: 4px;'><strong>✗ "
            . $translations[$lang]['reverse_of_reversal'] . "</strong></div>";
        $_SESSION['message_type'] = 'danger';
        header("Location: gl_transaction_view.php?id=" . $txnId);
        exit();
    }

    $postedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
    $error = null;
    $revId = reverse_journal_entry($conn, $txnId, $reason, $postedBy, $error);

    if ($revId > 0) {
        $_SESSION['message'] = "<div style='color: #3c763d; background-color: #dff0d8; border: 1px solid #d6e9c6; padding: 15px; border-radius: 4px;'>"
            . "<strong>✓ " . $translations[$lang]['reverse_success'] . "</strong>"
            . "<br>Reversal Txn #" . $revId
            . "</div>";
        $_SESSION['message_type'] = 'success';
        header("Location: gl_transaction_view.php?id=" . $revId);
        exit();
    } else {
        $_SESSION['message'] = "<div style='color: #a94442; background-color: #f2dede; border: 1px solid #ebccd1; padding: 15px; border-radius: 4px;'><strong>✗ "
            . $translations[$lang]['reverse_failed'] . ":</strong> " . htmlspecialchars($error ?? 'unknown') . "</div>";
        $_SESSION['message_type'] = 'danger';
        header("Location: gl_transaction_view.php?id=" . $txnId);
        exit();
    }
}

// ---------------------------------------------------------------------------
// Fetch lines
// ---------------------------------------------------------------------------
$stmt = $conn->prepare("
    SELECT l.id, l.line_no, l.debit, l.credit, l.memo,
           a.code AS account_code, a.name AS account_name, a.account_type,
           p.name AS party_name, p.party_type
    FROM gl_journal_lines l
    LEFT JOIN gl_accounts a ON a.id = l.account_id
    LEFT JOIN gl_parties  p ON p.id = l.party_id
    WHERE l.transaction_id = ?
    ORDER BY l.line_no
");
$stmt->bind_param("i", $txnId);
$stmt->execute();
$lines = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$sumD = 0.0; $sumC = 0.0;
foreach ($lines as $l) {
    $sumD += (float)$l['debit'];
    $sumC += (float)$l['credit'];
}
$balanced = (round($sumD, 2) === round($sumC, 2));

$canReverse = ($txn['status'] === 'POSTED' && $txn['reversal_of'] === null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once('meta_inc.php'); ?>
    <style>
        .detail-card { background: #f9f9f9; border: 1px solid #e3e3e3; border-radius: 4px; padding: 15px; }
        .detail-card .row { margin-bottom: 10px; }
        .detail-card .lbl { font-weight: bold; color: #555; font-size: 12px; }
        .detail-card .val { font-size: 14px; color: #222; }
        .amount-column { text-align: right; font-family: monospace; font-size: 14px; }
        .badge-reversed { background-color: #999; color: #fff; padding: 3px 8px; border-radius: 3px; }
        .badge-posted { background-color: #5cb85c; color: #fff; padding: 3px 8px; border-radius: 3px; }
        .badge-balanced { background-color: #5cb85c; color: #fff; padding: 3px 8px; border-radius: 3px; }
        .badge-unbalanced { background-color: #d9534f; color: #fff; padding: 3px 8px; border-radius: 3px; }
        .total-row { background: #eef4ff; font-weight: bold; }
        .memo-col { max-width: 300px; }
        .account-code { font-family: monospace; font-weight: bold; color: #337ab7; }

        /* ===== Custom Modal (no Bootstrap dependency) ===== */
        .custom-modal-backdrop {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9998;
        }
        .custom-modal-backdrop.active { display: block; }

        .custom-modal {
            display: none;
            position: fixed;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            background: #fff;
            border-radius: 6px;
            box-shadow: 0 5px 25px rgba(0, 0, 0, 0.3);
            z-index: 9999;
            width: 90%;
            max-width: 550px;
            max-height: 90vh;
            overflow: auto;
        }
        .custom-modal.active { display: block; }

        .custom-modal-header {
            padding: 15px 20px;
            border-bottom: 1px solid #e5e5e5;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .custom-modal-header h4 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: #333;
        }
        .custom-modal-close {
            background: none;
            border: none;
            font-size: 26px;
            line-height: 1;
            color: #888;
            cursor: pointer;
            padding: 0 5px;
        }
        .custom-modal-close:hover { color: #333; }

        .custom-modal-body {
            padding: 20px;
        }
        .custom-modal-footer {
            padding: 15px 20px;
            border-top: 1px solid #e5e5e5;
            text-align: right;
        }

        .custom-alert-warning {
            background: #fcf8e3;
            border: 1px solid #faebcc;
            color: #8a6d3b;
            padding: 12px 15px;
            border-radius: 4px;
            margin-bottom: 15px;
            font-size: 13px;
        }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="container" style="margin-top: 15px;">

    <?php if (isset($_SESSION['message'])): ?>
        <div>
            <?php
            echo $_SESSION['message'];
            unset($_SESSION['message'], $_SESSION['message_type']);
            ?>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-12">
            <div class="panel panel-primary">
                <div class="panel-heading">
                    <div class="row">
                        <div class="col-md-8">
                            <h3 class="panel-title">
                                <span class="glyphicon glyphicon-file"></span>
                                <?php echo $translations[$lang]['view_title']; ?>
                                #<?php echo (int)$txn['id']; ?>
                                <?php if ($txn['status'] === 'REVERSED'): ?>
                                    <span class="badge-reversed"><?php echo $translations[$lang]['reversed']; ?></span>
                                <?php else: ?>
                                    <span class="badge-posted"><?php echo $translations[$lang]['posted']; ?></span>
                                <?php endif; ?>
                            </h3>
                        </div>
                        <div class="col-md-4 text-right">
                            <a href="gl_transactions.php" class="btn btn-sm btn-default">
                                <span class="glyphicon glyphicon-arrow-left"></span>
                                <?php echo $translations[$lang]['back']; ?>
                            </a>
                            <?php if ($canReverse): ?>
                                <button type="button" id="openReverseModalBtn" class="btn btn-sm btn-danger">
                                    <span class="glyphicon glyphicon-refresh"></span>
                                    <?php echo $translations[$lang]['reverse_btn']; ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="panel-body">

                    <?php if ($txn['reversal_of']): ?>
                        <div class="alert alert-warning">
                            <strong><?php echo $translations[$lang]['reversal_badge']; ?>.</strong>
                            Reverses Txn
                            <a href="gl_transaction_view.php?id=<?php echo (int)$txn['reversal_of']; ?>">
                                #<?php echo (int)$txn['reversal_of']; ?>
                            </a>
                        </div>
                    <?php endif; ?>

                    <?php if ($txn['reversal_txn_id']): ?>
                        <div class="alert alert-info">
                            <strong><?php echo $translations[$lang]['reversed_badge']; ?>.</strong>
                            Reversed by
                            <a href="gl_transaction_view.php?id=<?php echo (int)$txn['reversal_txn_id']; ?>">
                                Txn #<?php echo (int)$txn['reversal_txn_id']; ?>
                            </a>
                        </div>
                    <?php endif; ?>

                    <div class="detail-card">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="lbl"><?php echo $translations[$lang]['date']; ?></div>
                                <div class="val"><?php echo htmlspecialchars($txn['entry_date']); ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="lbl"><?php echo $translations[$lang]['session']; ?></div>
                                <div class="val"><?php echo htmlspecialchars($txn['session_title'] ?? ('#' . $txn['session_id'])); ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="lbl"><?php echo $translations[$lang]['reference']; ?></div>
                                <div class="val">
                                    <?php if ($txn['reference_type']): ?>
                                        <span class="label label-primary"><?php echo htmlspecialchars($txn['reference_type']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($txn['reference_id']): ?>
                                        <span class="label label-default">#<?php echo (int)$txn['reference_id']; ?></span>
                                    <?php endif; ?>
                                    <?php if (!$txn['reference_type'] && !$txn['reference_id']): ?>
                                        <em class="text-muted">&mdash;</em>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="lbl"><?php echo $translations[$lang]['posted_by']; ?></div>
                                <div class="val">
                                    <?php echo htmlspecialchars($txn['posted_by_name'] ?? ('User #' . $txn['posted_by'])); ?>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-9">
                                <div class="lbl"><?php echo $translations[$lang]['description']; ?></div>
                                <div class="val"><?php echo htmlspecialchars($txn['description']); ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="lbl"><?php echo $translations[$lang]['posted_at']; ?></div>
                                <div class="val"><?php echo htmlspecialchars($txn['posted_at']); ?></div>
                            </div>
                        </div>
                    </div>

                    <h4 style="margin-top: 25px;">
                        <span class="glyphicon glyphicon-list"></span>
                        <?php echo $translations[$lang]['lines_card']; ?>
                        <?php if ($balanced): ?>
                            <span class="badge-balanced"><?php echo $translations[$lang]['balanced']; ?> &check;</span>
                        <?php else: ?>
                            <span class="badge-unbalanced"><?php echo $translations[$lang]['not_balanced']; ?></span>
                        <?php endif; ?>
                    </h4>

                    <div class="table-responsive">
                        <table class="table table-bordered table-condensed">
                            <thead>
                                <tr>
                                    <th style="width: 40px;">#</th>
                                    <th style="width: 90px;">Code</th>
                                    <th><?php echo $translations[$lang]['account']; ?></th>
                                    <th class="memo-col"><?php echo $translations[$lang]['memo']; ?></th>
                                    <th style="width: 120px;"><?php echo $translations[$lang]['party']; ?></th>
                                    <th style="width: 120px;" class="text-right"><?php echo $translations[$lang]['debit']; ?></th>
                                    <th style="width: 120px;" class="text-right"><?php echo $translations[$lang]['credit']; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($lines as $l): ?>
                                    <tr>
                                        <td><?php echo (int)$l['line_no']; ?></td>
                                        <td class="account-code"><?php echo htmlspecialchars($l['account_code']); ?></td>
                                        <td><?php echo htmlspecialchars($l['account_name']); ?></td>
                                        <td class="memo-col"><?php echo htmlspecialchars($l['memo'] ?? ''); ?></td>
                                        <td>
                                            <?php if (!empty($l['party_name'])): ?>
                                                <span class="label label-info"><?php echo htmlspecialchars($l['party_name']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="amount-column">
                                            <?php echo ((float)$l['debit'] > 0) ? number_format((float)$l['debit'], 2) : ''; ?>
                                        </td>
                                        <td class="amount-column">
                                            <?php echo ((float)$l['credit'] > 0) ? number_format((float)$l['credit'], 2) : ''; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="total-row">
                                    <th colspan="5" class="text-right"><?php echo $translations[$lang]['total']; ?>:</th>
                                    <th class="amount-column"><?php echo number_format($sumD, 2); ?></th>
                                    <th class="amount-column"><?php echo number_format($sumC, 2); ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($canReverse): ?>
<!-- Custom Modal (no Bootstrap dependency at all) -->
<div class="custom-modal-backdrop" id="reverseBackdrop"></div>
<div class="custom-modal" id="reverseModal">
    <form method="post" action="gl_transaction_view.php?id=<?php echo (int)$txnId; ?>" id="reverseForm">
        <div class="custom-modal-header">
            <h4>
                <span class="glyphicon glyphicon-refresh"></span>
                <?php echo $translations[$lang]['reverse_title']; ?>
            </h4>
            <button type="button" class="custom-modal-close" id="closeReverseModalBtn">&times;</button>
        </div>
        <div class="custom-modal-body">
            <div class="custom-alert-warning">
                <strong>Note:</strong> <?php echo $translations[$lang]['reverse_confirm']; ?>
            </div>
            <div class="form-group">
                <label for="reason">
                    <?php echo $translations[$lang]['reverse_reason']; ?> *
                </label>
                <textarea class="form-control" id="reason" name="reason" rows="3" required
                          placeholder="<?php echo $translations[$lang]['reverse_reason_ph']; ?>"></textarea>
            </div>
        </div>
        <div class="custom-modal-footer">
            <button type="button" class="btn btn-default" id="cancelReverseModalBtn">
                <?php echo $translations[$lang]['cancel']; ?>
            </button>
            <button type="button" id="confirmReverseBtn" class="btn btn-danger">
                <span class="glyphicon glyphicon-refresh"></span>
                <?php echo $translations[$lang]['confirm_reverse']; ?>
            </button>
        </div>
    </form>
</div>
<?php endif; ?>

<script>
// Vanilla JavaScript — no jQuery, no Bootstrap dependency
(function() {
    'use strict';

    var modal     = document.getElementById('reverseModal');
    var backdrop  = document.getElementById('reverseBackdrop');
    var openBtn   = document.getElementById('openReverseModalBtn');
    var closeBtn  = document.getElementById('closeReverseModalBtn');
    var cancelBtn = document.getElementById('cancelReverseModalBtn');
    var confirmBtn= document.getElementById('confirmReverseBtn');
    var reason    = document.getElementById('reason');

    if (!modal) return; // no modal on this page

    function showModal() {
        modal.classList.add('active');
        backdrop.classList.add('active');
        if (reason) reason.focus();
    }

    function hideModal() {
        modal.classList.remove('active');
        backdrop.classList.remove('active');
    }

    if (openBtn)  openBtn.addEventListener('click', showModal);
    if (closeBtn) closeBtn.addEventListener('click', hideModal);
    if (cancelBtn)cancelBtn.addEventListener('click', hideModal);
    if (backdrop) backdrop.addEventListener('click', hideModal);

    // Escape key closes
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.classList.contains('active')) {
            hideModal();
        }
    });

    // Confirm
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function() {
            var val = reason.value.trim();
            if (val === '') {
                alert('Please enter a reason for the reversal.');
                reason.focus();
                return;
            }

            if (!confirm('<?php echo addslashes($translations[$lang]["reverse_confirm"]); ?>')) {
                return;
            }

            var form = document.getElementById('reverseForm');
            var existing = form.querySelector('input[name="reverse_confirm"]');
            if (!existing) {
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'reverse_confirm';
                hidden.value = '1';
                form.appendChild(hidden);
            }
            form.submit();
        });
    }
})();
</script>

</body>
<?php if (isset($conn)) $conn->close(); ?>
</html>