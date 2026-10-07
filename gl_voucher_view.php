<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');
require_once('VoucherService.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

$service = new VoucherService($conn);

$voucherId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($voucherId <= 0) {
    header("Location: gl_vouchers.php");
    exit();
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'post') {
            $postedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
            $service->postVoucher($voucherId, $postedBy);
            $_SESSION['message'] = "<div class='alert alert-success'><strong>✓ Voucher posted to ledger</strong></div>";
        } elseif ($action === 'cancel') {
            $service->cancelDraft($voucherId);
            $_SESSION['message'] = "<div class='alert alert-warning'><strong>✓ Draft voucher cancelled</strong></div>";
        } elseif ($action === 'reverse') {
            $reason = trim($_POST['reason'] ?? '');
            if ($reason === '') {
                throw new Exception("Reversal reason required.");
            }
            $postedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
            $service->reverseVoucher($voucherId, $reason, $postedBy);
            $_SESSION['message'] = "<div class='alert alert-info'><strong>✓ Voucher reversed</strong><br>Reason: " . htmlspecialchars($reason) . "</div>";
        }
    } catch (Exception $e) {
        $_SESSION['message'] = "<div class='alert alert-danger'><strong>✗ Action failed</strong><br>" . htmlspecialchars($e->getMessage()) . "</div>";
    }

    header("Location: gl_voucher_view.php?id=" . $voucherId);
    exit();
}

$v = $service->getVoucher($voucherId);
if (!$v) {
    $_SESSION['message'] = "<div class='alert alert-danger'>Voucher #$voucherId not found.</div>";
    header("Location: gl_vouchers.php");
    exit();
}

// Type meta
$typeMeta = [
    'CPV' => ['label' => 'Cash Payment Voucher',  'short' => 'Cash Payment',  'cls' => 'cpv', 'icon' => 'glyphicon-arrow-up'],
    'CRV' => ['label' => 'Cash Receipt Voucher',  'short' => 'Cash Receipt',  'cls' => 'crv', 'icon' => 'glyphicon-arrow-down'],
    'BPV' => ['label' => 'Bank Payment Voucher',  'short' => 'Bank Payment',  'cls' => 'bpv', 'icon' => 'glyphicon-upload'],
    'BRV' => ['label' => 'Bank Receipt Voucher',  'short' => 'Bank Receipt',  'cls' => 'brv', 'icon' => 'glyphicon-download'],
];

$meta = $typeMeta[$v['voucher_type']];
$isPayment = in_array($v['voucher_type'], ['CPV', 'BPV'], true);
$cashAccountCode = $isPayment ? ($v['voucher_type'] === 'CPV' ? '1010' : '1020') : ($v['voucher_type'] === 'CRV' ? '1010' : '1020');
$cashAccountName = ($cashAccountCode === '1010') ? 'Cash in Hand' : 'Cash at Bank';

// Amount in words
function amountInWords($num) {
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
             'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
             'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    $n = (int)round($num);
    if ($n === 0) return 'Zero Rupees Only';

    $words = [];
    if ($n >= 10000000) { $words[] = amountInWords(intdiv($n, 10000000)) . ' Crore'; $n %= 10000000; }
    if ($n >= 100000)   { $words[] = amountInWords(intdiv($n, 100000)) . ' Lakh'; $n %= 100000; }
    if ($n >= 1000)     { $words[] = amountInWords(intdiv($n, 1000)) . ' Thousand'; $n %= 1000; }
    if ($n >= 100)      { $words[] = $ones[intdiv($n, 100)] . ' Hundred'; $n %= 100; }
    if ($n > 0) {
        if ($n < 20) $words[] = $ones[$n];
        else {
            $t = $tens[intdiv($n, 10)];
            $o = $n % 10;
            $words[] = $o ? "$t-$ones[$o]" : $t;
        }
    }
    return implode(' ', $words) . ' Rupees Only';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
   
    <title><?php echo htmlspecialchars($v['voucher_number']); ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            background: #eef1f5;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0; padding: 0; color: #1e293b;
        }
        .wrap { max-width: 900px; margin: 30px auto; padding: 0 20px; }

        .head {
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
            color: #fff;
            padding: 30px 40px 26px 40px;
            border-radius: 12px 12px 0 0;
            box-shadow: 0 4px 16px rgba(30, 64, 175, 0.15);
            display: flex; justify-content: space-between;
            align-items: flex-end; gap: 20px; flex-wrap: wrap;
        }
        .head h1 { margin: 0; font-size: 24px; font-weight: 700; letter-spacing: -0.4px; }
        .head .sub { font-size: 14px; opacity: 0.9; margin-top: 6px; }
        .head .number {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 22px; font-weight: 700;
            background: rgba(255, 255, 255, 0.15);
            padding: 8px 16px; border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        .btn-back {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 14px;
            background: rgba(255, 255, 255, 0.15);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 6px; text-decoration: none;
            font-size: 13px; font-weight: 600;
        }
        .btn-back:hover { background: rgba(255, 255, 255, 0.25); color: #fff; text-decoration: none; }

        .toolbar {
            background: #fff;
            padding: 16px 40px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            margin-bottom: 24px;
            display: flex; gap: 10px; flex-wrap: wrap; align-items: center;
        }
        .toolbar .spacer { flex: 1; }

        .btn-tool {
            height: 40px; padding: 0 18px; border-radius: 8px;
            font-size: 13px; font-weight: 600; border: 1px solid;
            cursor: pointer; display: inline-flex; align-items: center;
            gap: 7px; text-decoration: none; transition: all 0.15s;
            white-space: nowrap;
        }
        .btn-tool.primary { background: #1e40af; color: #fff; border-color: #1e40af; }
        .btn-tool.primary:hover { background: #1e3a8a; text-decoration: none; }
        .btn-tool.success { background: #047857; color: #fff; border-color: #047857; }
        .btn-tool.success:hover { background: #065f46; text-decoration: none; }
        .btn-tool.warning { background: #d97706; color: #fff; border-color: #d97706; }
        .btn-tool.warning:hover { background: #b45309; text-decoration: none; }
        .btn-tool.danger { background: #dc2626; color: #fff; border-color: #dc2626; }
        .btn-tool.danger:hover { background: #b91c1c; text-decoration: none; }
        .btn-tool.ghost { background: #fff; color: #475569; border-color: #cbd5e1; }
        .btn-tool.ghost:hover { background: #f8fafc; color: #1e293b; text-decoration: none; }

        .voucher-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            overflow: hidden;
            margin-bottom: 24px;
        }

        .voucher-body { padding: 30px 40px 40px 40px; }

        .status-banner {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 20px; border-radius: 10px;
            font-size: 14px; font-weight: 600;
            margin-bottom: 24px;
        }
        .status-banner.draft     { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
        .status-banner.posted    { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
        .status-banner.cancelled { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .status-banner .icon {
            width: 36px; height: 36px; border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 17px; background: rgba(0,0,0,0.1);
        }

        /* Type pill */
        .v-type-pill {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 6px 14px; border-radius: 20px;
            font-size: 12px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 1px; color: #fff;
        }
        .v-type-pill.cpv { background: #dc2626; }
        .v-type-pill.crv { background: #047857; }
        .v-type-pill.bpv { background: #c2410c; }
        .v-type-pill.brv { background: #1e40af; }

        /* Detail grid */
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px 40px;
            margin-bottom: 30px;
        }
        @media (max-width: 700px) { .detail-grid { grid-template-columns: 1fr; } }

        .field-block .lbl {
            font-size: 11px; color: #64748b;
            text-transform: uppercase; letter-spacing: 1px;
            font-weight: 700; margin-bottom: 6px;
        }
        .field-block .val {
            font-size: 15px; color: #0f172a; font-weight: 500;
        }
        .field-block .val.mono {
            font-family: 'SF Mono', 'Monaco', monospace;
        }

        /* Amount box */
        .amount-box {
            background: linear-gradient(135deg, #f0f5ff 0%, #e0e7ff 100%);
            border: 2px solid #c7d2fe;
            border-radius: 12px;
            padding: 24px 30px;
            margin-bottom: 30px;
            text-align: center;
        }
        .amount-box .lbl {
            font-size: 12px; color: #64748b;
            text-transform: uppercase; letter-spacing: 1.5px;
            font-weight: 700; margin-bottom: 8px;
        }
        .amount-box .amt {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 44px; font-weight: 800;
            color: #1e40af;
            letter-spacing: -1.5px;
            line-height: 1;
        }
        .amount-box .words {
            font-size: 14px; color: #475569; font-style: italic;
            margin-top: 10px;
        }
        .amount-box.payment {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            border-color: #fca5a5;
        }
        .amount-box.payment .amt { color: #b91c1c; }

        /* Accounting entry table */
        .entry-table-wrap {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 30px;
        }
        .entry-table-wrap .header {
            background: #1e293b; color: #fff;
            padding: 12px 20px;
            font-size: 12px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 1.2px;
        }
        .entry-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .entry-table th {
            padding: 12px 16px; text-align: left;
            font-size: 10px; font-weight: 700; color: #64748b;
            text-transform: uppercase; letter-spacing: 1px;
            background: #f8fafc; border-bottom: 1px solid #e2e8f0;
        }
        .entry-table th.amount-col { text-align: right; }
        .entry-table td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; }
        .entry-table td.amount-col {
            text-align: right;
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 15px; font-weight: 700;
            font-variant-numeric: tabular-nums;
        }
        .entry-table td.amount-col.debit { color: #047857; }
        .entry-table td.amount-col.credit { color: #b91c1c; }
        .entry-table .acc-code {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 12px; color: #64748b;
        }
        .entry-table .total-row {
            background: #f0f5ff; font-weight: 700;
            border-top: 2px solid #c9d7ef;
        }

        /* Attachment preview */
        .attachment-box {
            background: #f8fafc; border: 1px solid #e2e8f0;
            border-radius: 10px; padding: 18px 24px;
            margin-bottom: 24px;
            display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
        }
        .attachment-box .glyphicon { font-size: 32px; color: #1e40af; }
        .attachment-box .info { flex: 1; }
        .attachment-box .name { font-size: 14px; font-weight: 600; color: #0f172a; }
        .attachment-box .link {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 16px; background: #1e40af; color: #fff;
            border-radius: 6px; text-decoration: none;
            font-size: 13px; font-weight: 600;
        }
        .attachment-box .link:hover { background: #1e3a8a; color: #fff; text-decoration: none; }

        /* Reverse modal */
        .modal-content { border-radius: 12px; overflow: hidden; }
        .modal-header { background: #dc2626; color: #fff; border-bottom: none; padding: 18px 24px; }
        .modal-title { font-weight: 700; }
        .modal-body { padding: 24px; }
        .modal-footer { background: #f8fafc; padding: 16px 24px; border-top: 1px solid #e2e8f0; }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <h1><?php echo htmlspecialchars($meta['label']); ?></h1>
            <div class="sub"><?php echo htmlspecialchars($v['party_name']); ?></div>
        </div>
        <div class="number"><?php echo htmlspecialchars($v['voucher_number']); ?></div>
        <a href="gl_vouchers.php" class="btn-back">
            <span class="glyphicon glyphicon-arrow-left"></span> All Vouchers
        </a>
    </div>

    <div class="toolbar">
        <?php if ($v['status'] === 'DRAFT'): ?>
            <form method="post" style="display: inline;">
                <button type="submit" name="action" value="post" class="btn-tool success">
                    <span class="glyphicon glyphicon-ok"></span> Post to Ledger
                </button>
            </form>
            <form method="post" style="display: inline;" onsubmit="return confirm('Cancel this draft voucher?');">
                <button type="submit" name="action" value="cancel" class="btn-tool warning">
                    <span class="glyphicon glyphicon-remove"></span> Cancel Draft
                </button>
            </form>
        <?php elseif ($v['status'] === 'POSTED'): ?>
         <button type="button" class="btn-tool danger" id="openReverseBtn">
    <span class="glyphicon glyphicon-refresh"></span> Reverse Voucher
</button>
        <?php endif; ?>

        <span class="spacer"></span>

        <a href="gl_voucher_print.php?id=<?php echo (int)$v['id']; ?>" target="_blank" class="btn-tool primary">
            <span class="glyphicon glyphicon-print"></span> Print Voucher
        </a>
    </div>

    <?php if (isset($_SESSION['message'])): ?>
        <div style="margin-bottom: 20px;"><?php echo $_SESSION['message']; unset($_SESSION['message']); ?></div>
    <?php endif; ?>

    <div class="voucher-card">
        <div class="voucher-body">

            <!-- Status banner -->
            <?php if ($v['status'] === 'DRAFT'): ?>
                <div class="status-banner draft">
                    <span class="icon"><span class="glyphicon glyphicon-pencil"></span></span>
                    <div>
                        <strong>Draft</strong> — This voucher has not yet been posted to the ledger.
                        <br><small>Post it to move cash/bank and expense/revenue accounts.</small>
                    </div>
                </div>
            <?php elseif ($v['status'] === 'POSTED'): ?>
                <div class="status-banner posted">
                    <span class="icon"><span class="glyphicon glyphicon-ok"></span></span>
                    <div>
                        <strong>Posted</strong> — This voucher is recorded in the General Ledger.
                        <?php if (!empty($v['gl_transaction_id'])): ?>
                            <br><small>Linked to GL Transaction #<?php echo (int)$v['gl_transaction_id']; ?>
                            <a href="gl_transaction_view.php?id=<?php echo (int)$v['gl_transaction_id']; ?>"
                               style="color: #065f46; font-weight: 700; text-decoration: underline;">View GL entry →</a></small>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="status-banner cancelled">
                    <span class="icon"><span class="glyphicon glyphicon-remove"></span></span>
                    <div>
                        <strong>Cancelled</strong> — This voucher has been voided.
                        <?php if (!empty($v['gl_transaction_id'])): ?>
                            <br><small>The linked GL entry was reversed. Original GL #<?php echo (int)$v['gl_transaction_id']; ?>.</small>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Voucher type -->
            <div style="margin-bottom: 24px;">
                <span class="v-type-pill <?php echo $meta['cls']; ?>">
                    <span class="glyphicon <?php echo $meta['icon']; ?>"></span>
                    <?php echo htmlspecialchars($meta['label']); ?>
                </span>
            </div>

            <!-- Amount box -->
            <div class="amount-box <?php echo $isPayment ? 'payment' : ''; ?>">
                <div class="lbl"><?php echo $isPayment ? 'Amount Paid' : 'Amount Received'; ?></div>
                <div class="amt">
                    <?php echo ($isPayment ? '− ' : '+ ') . number_format((float)$v['amount'], 2); ?>
                </div>
                <div class="words"><?php echo amountInWords((float)$v['amount']); ?></div>
            </div>

            <!-- Detail grid -->
            <div class="detail-grid">
                <div class="field-block">
                    <div class="lbl">Entry Date</div>
                    <div class="val mono"><?php echo htmlspecialchars($v['entry_date']); ?></div>
                </div>
                <div class="field-block">
                    <div class="lbl">Session</div>
                    <div class="val"><?php echo htmlspecialchars($v['session_title'] ?? ('#' . $v['session_id'])); ?></div>
                </div>
                <div class="field-block">
                    <div class="lbl"><?php echo $isPayment ? 'Paid To' : 'Received From'; ?></div>
                    <div class="val"><?php echo htmlspecialchars($v['party_name']); ?></div>
                    <?php if (!empty($v['party_contact'])): ?>
                        <div style="font-size: 13px; color: #64748b; margin-top: 3px;">
                            <?php echo htmlspecialchars($v['party_contact']); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="field-block">
                    <div class="lbl">Reference Number</div>
                    <div class="val mono">
                        <?php echo !empty($v['reference_number']) ? htmlspecialchars($v['reference_number']) : '—'; ?>
                    </div>
                </div>
                <?php if ($v['voucher_type'] === 'BPV' || $v['voucher_type'] === 'BRV'): ?>
                    <div class="field-block">
                        <div class="lbl">Payment Method</div>
                        <div class="val"><?php echo htmlspecialchars(ucfirst($v['payment_method'] ?? '—')); ?></div>
                    </div>
                    <div class="field-block">
                        <div class="lbl">Bank Name</div>
                        <div class="val"><?php echo !empty($v['bank_name']) ? htmlspecialchars($v['bank_name']) : '—'; ?></div>
                    </div>
                <?php endif; ?>
                <div class="field-block">
                    <div class="lbl">Created By</div>
                    <div class="val">
                        <?php echo htmlspecialchars($v['created_by_name'] ?? ('User #' . $v['created_by'])); ?>
                        <span style="font-size: 12px; color: #64748b; margin-left: 8px;">
                            <?php echo htmlspecialchars(substr($v['created_at'], 0, 16)); ?>
                        </span>
                    </div>
                </div>
                <?php if ($v['status'] === 'POSTED'): ?>
                    <div class="field-block">
                        <div class="lbl">Posted By</div>
                        <div class="val">
                            <?php echo htmlspecialchars($v['posted_by_name'] ?? ('User #' . $v['posted_by'])); ?>
                            <span style="font-size: 12px; color: #64748b; margin-left: 8px;">
                                <?php echo htmlspecialchars(substr($v['posted_at'], 0, 16)); ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($v['narration'])): ?>
                <div style="margin-bottom: 30px;">
                    <div class="lbl" style="font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; margin-bottom: 6px;">
                        Narration
                    </div>
                    <div style="background: #f8fafc; padding: 14px 18px; border-radius: 8px; border-left: 4px solid #1e40af; font-size: 14px; line-height: 1.6;">
                        <?php echo nl2br(htmlspecialchars($v['narration'])); ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Accounting entry preview -->
            <div class="entry-table-wrap">
                <div class="header">
                    <span class="glyphicon glyphicon-book"></span> Accounting Entry
                </div>
                <table class="entry-table">
                    <thead>
                        <tr>
                            <th>Account</th>
                            <th class="amount-col">Debit</th>
                            <th class="amount-col">Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($isPayment): ?>
                            <tr>
                                <td>
                                    <div><?php echo htmlspecialchars($v['account_name']); ?></div>
                                    <div class="acc-code"><?php echo htmlspecialchars($v['account_code']); ?></div>
                                </td>
                                <td class="amount-col debit"><?php echo number_format((float)$v['amount'], 2); ?></td>
                                <td class="amount-col">—</td>
                            </tr>
                            <tr>
                                <td>
                                    <div><?php echo htmlspecialchars($cashAccountName); ?></div>
                                    <div class="acc-code"><?php echo htmlspecialchars($cashAccountCode); ?></div>
                                </td>
                                <td class="amount-col">—</td>
                                <td class="amount-col credit"><?php echo number_format((float)$v['amount'], 2); ?></td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td>
                                    <div><?php echo htmlspecialchars($cashAccountName); ?></div>
                                    <div class="acc-code"><?php echo htmlspecialchars($cashAccountCode); ?></div>
                                </td>
                                <td class="amount-col debit"><?php echo number_format((float)$v['amount'], 2); ?></td>
                                <td class="amount-col">—</td>
                            </tr>
                            <tr>
                                <td>
                                    <div><?php echo htmlspecialchars($v['account_name']); ?></div>
                                    <div class="acc-code"><?php echo htmlspecialchars($v['account_code']); ?></div>
                                </td>
                                <td class="amount-col">—</td>
                                <td class="amount-col credit"><?php echo number_format((float)$v['amount'], 2); ?></td>
                            </tr>
                        <?php endif; ?>
                        <tr class="total-row">
                            <td style="text-align: right;">Totals</td>
                            <td class="amount-col debit"><?php echo number_format((float)$v['amount'], 2); ?></td>
                            <td class="amount-col credit"><?php echo number_format((float)$v['amount'], 2); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Attachment -->
            <?php if (!empty($v['attachment_path'])): ?>
                <div class="attachment-box">
                    <span class="glyphicon glyphicon-paperclip"></span>
                    <div class="info">
                        <div class="name">Attached Document</div>
                        <div style="font-size: 12px; color: #64748b; margin-top: 2px;">
                            <?php echo htmlspecialchars(basename($v['attachment_path'])); ?>
                        </div>
                    </div>
                    <a href="<?php echo htmlspecialchars($v['attachment_path']); ?>"
                       target="_blank" class="link">
                        <span class="glyphicon glyphicon-new-window"></span> View
                    </a>
                </div>
            <?php endif; ?>

        </div>
    </div>

</div>

<!-- Custom Reverse Modal (no Bootstrap dependency) -->
<?php if ($v['status'] === 'POSTED'): ?>
<style>
    .rev-backdrop {
        display: none;
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0, 0, 0, 0.55);
        z-index: 9998;
    }
    .rev-backdrop.active { display: block; }

    .rev-modal {
        display: none;
        position: fixed;
        top: 50%; left: 50%;
        transform: translate(-50%, -50%);
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35);
        z-index: 9999;
        width: 90%;
        max-width: 520px;
        max-height: 90vh;
        overflow: auto;
    }
    .rev-modal.active { display: block; }

    .rev-header {
        background: #dc2626;
        color: #fff;
        padding: 18px 24px;
        border-radius: 12px 12px 0 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .rev-header h4 {
        margin: 0;
        font-size: 16px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .rev-close {
        background: none;
        border: none;
        color: #fff;
        font-size: 24px;
        line-height: 1;
        cursor: pointer;
        opacity: 0.85;
        padding: 0 6px;
    }
    .rev-close:hover { opacity: 1; }

    .rev-body {
        padding: 24px;
    }
    .rev-body .warn {
        background: #fef2f2;
        border-left: 4px solid #dc2626;
        padding: 14px 18px;
        border-radius: 6px;
        margin-bottom: 18px;
        font-size: 13px;
        color: #991b1b;
        line-height: 1.5;
    }
    .rev-body label {
        font-size: 13px;
        font-weight: 700;
        display: block;
        margin-bottom: 6px;
        color: #334155;
    }
    .rev-body textarea {
        width: 100%;
        padding: 10px 14px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        font-size: 14px;
        font-family: inherit;
        resize: vertical;
        min-height: 80px;
    }
    .rev-body textarea:focus {
        outline: none;
        border-color: #dc2626;
        box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
    }

    .rev-footer {
        background: #f8fafc;
        padding: 16px 24px;
        border-top: 1px solid #e2e8f0;
        border-radius: 0 0 12px 12px;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }
    .rev-footer .btn-cancel {
        padding: 10px 20px;
        background: #fff;
        border: 1px solid #cbd5e1;
        color: #475569;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
    }
    .rev-footer .btn-cancel:hover { background: #f8fafc; }
    .rev-footer .btn-confirm {
        padding: 10px 20px;
        background: #dc2626;
        border: 1px solid #dc2626;
        color: #fff;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .rev-footer .btn-confirm:hover { background: #b91c1c; }
</style>

<div class="rev-backdrop" id="revBackdrop"></div>
<div class="rev-modal" id="revModal">
    <form method="post" id="reverseForm">
        <input type="hidden" name="action" value="reverse">
        <div class="rev-header">
            <h4>
                <span class="glyphicon glyphicon-refresh"></span>
                Reverse Voucher
            </h4>
            <button type="button" class="rev-close" id="revCloseBtn">&times;</button>
        </div>
        <div class="rev-body">
            <div class="warn">
                <strong>Warning:</strong> This will create a reversing entry in the General Ledger.
                The original entry will be preserved but marked as REVERSED. This action cannot be undone.
            </div>
            <label for="reason">
                Reason for Reversal <span style="color: #dc2626;">*</span>
            </label>
            <textarea name="reason" id="reason" rows="3" required
                      placeholder="e.g. Wrong amount entered, duplicate entry, voucher cancelled by accountant..."></textarea>
        </div>
        <div class="rev-footer">
            <button type="button" class="btn-cancel" id="revCancelBtn">Cancel</button>
            <button type="button" class="btn-confirm" id="revConfirmBtn">
                <span class="glyphicon glyphicon-refresh"></span> Confirm Reversal
            </button>
        </div>
    </form>
</div>
<?php endif; ?>
<script>
(function() {
    'use strict';

    var modal     = document.getElementById('revModal');
    var backdrop  = document.getElementById('revBackdrop');
    var openBtn   = document.getElementById('openReverseBtn');
    var closeBtn  = document.getElementById('revCloseBtn');
    var cancelBtn = document.getElementById('revCancelBtn');
    var confirmBtn= document.getElementById('revConfirmBtn');
    var reason    = document.getElementById('reason');
    var form      = document.getElementById('reverseForm');

    if (!modal) return;  // no reverse modal on this page

    function show() {
        modal.classList.add('active');
        backdrop.classList.add('active');
        if (reason) reason.focus();
    }

    function hide() {
        modal.classList.remove('active');
        backdrop.classList.remove('active');
    }

    if (openBtn)   openBtn.addEventListener('click', show);
    if (closeBtn)  closeBtn.addEventListener('click', hide);
    if (cancelBtn) cancelBtn.addEventListener('click', hide);
    if (backdrop)  backdrop.addEventListener('click', hide);

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.classList.contains('active')) hide();
    });

    if (confirmBtn) {
        confirmBtn.addEventListener('click', function() {
            var val = reason.value.trim();
            if (val === '') {
                alert('Please enter a reason for the reversal.');
                reason.focus();
                return;
            }
            if (!confirm('Are you sure you want to reverse this voucher?\n\nThis action cannot be undone.')) {
                return;
            }
            form.submit();
        });
    }
})();
</script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
</body>
</html>
<?php $conn->close(); ?>