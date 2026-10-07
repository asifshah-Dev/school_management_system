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
if ($voucherId <= 0) { exit('Invalid voucher'); }

$v = $service->getVoucher($voucherId);
if (!$v) { exit('Voucher not found'); }

$typeMeta = [
    'CPV' => ['label' => 'Cash Payment Voucher',  'cls' => 'cpv', 'icon' => 'glyphicon-arrow-up'],
    'CRV' => ['label' => 'Cash Receipt Voucher',  'cls' => 'crv', 'icon' => 'glyphicon-arrow-down'],
    'BPV' => ['label' => 'Bank Payment Voucher',  'cls' => 'bpv', 'icon' => 'glyphicon-upload'],
    'BRV' => ['label' => 'Bank Receipt Voucher',  'cls' => 'brv', 'icon' => 'glyphicon-download'],
];
$meta = $typeMeta[$v['voucher_type']];
$isPayment = in_array($v['voucher_type'], ['CPV', 'BPV'], true);
$cashAccountCode = in_array($v['voucher_type'], ['CPV','CRV'], true) ? '1010' : '1020';
$cashAccountName = ($cashAccountCode === '1010') ? 'Cash in Hand' : 'Cash at Bank';

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
        else { $t = $tens[intdiv($n, 10)]; $o = $n % 10; $words[] = $o ? "$t-$ones[$o]" : $t; }
    }
    return implode(' ', $words) . ' Rupees Only';
}

// Signature blocks
$signatures = [
    'CPV' => ['Prepared By', 'Approved By', 'Received By (Payee)'],
    'CRV' => ['Prepared By', 'Received By (Cashier)', 'Approved By'],
    'BPV' => ['Prepared By', 'Approved By', 'Cheque Signatory'],
    'BRV' => ['Prepared By', 'Verified By', 'Approved By'],
][$v['voucher_type']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Print <?php echo htmlspecialchars($v['voucher_number']); ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        html, body {
            background: #e5e7eb;
            font-family: 'Segoe UI', Calibri, Arial, sans-serif;
            color: #000;
            font-size: 12px;
        }

        .page {
            width: 210mm;
            height: 148mm;
            background: #fff;
            margin: 20px auto;
            padding: 12mm 14mm;
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
            display: flex;
            flex-direction: column;
            position: relative;
        }

        /* Header */
        .v-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            border-bottom: 2.5px solid #000;
            padding-bottom: 8px;
            margin-bottom: 12px;
            gap: 16px;
        }
        .v-head .school {
            flex: 1;
        }
        .v-head .school .name {
            font-size: 22px;
            font-weight: 800;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            line-height: 1.1;
        }
        .v-head .school .tagline {
            font-size: 11px;
            color: #333;
            margin-top: 3px;
            letter-spacing: 0.5px;
        }
        .v-head .voucher-type-box {
            text-align: right;
            border-left: 2px dashed #999;
            padding-left: 14px;
            min-width: 200px;
        }
        .v-head .voucher-type-box .title {
            font-size: 15px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 4px 10px;
            border: 2px solid #000;
            display: inline-block;
            margin-bottom: 6px;
        }
        .v-head .voucher-type-box .title.payment { background: #000; color: #fff; }
        .v-head .voucher-type-box .number {
            font-family: 'Courier New', monospace;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 1px;
        }

        /* Body */
        .v-body {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .row { display: flex; gap: 20px; }
        .row > .col { flex: 1; }
        .row > .col-half { flex: 0 0 50%; }

        .field {
            display: flex;
            align-items: baseline;
            gap: 8px;
            padding: 4px 0;
            border-bottom: 1px dotted #999;
            font-size: 13px;
        }
        .field .label {
            font-weight: 700;
            text-transform: uppercase;
            font-size: 10px;
            letter-spacing: 0.8px;
            color: #333;
            min-width: 90px;
        }
        .field .value {
            flex: 1;
            font-size: 13px;
            font-weight: 600;
        }
        .field .value.mono { font-family: 'Courier New', monospace; }

        /* Amount box */
        .amount-block {
            display: flex;
            align-items: stretch;
            border: 2px solid #000;
            margin-top: 4px;
        }
        .amount-block .words-box {
            flex: 1;
            padding: 8px 12px;
            border-right: 2px solid #000;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .amount-block .words-box .lbl {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #555;
            font-weight: 700;
            margin-bottom: 2px;
        }
        .amount-block .words-box .words {
            font-size: 12px;
            font-weight: 600;
            font-style: italic;
            line-height: 1.3;
        }
        .amount-block .figure-box {
            flex: 0 0 180px;
            padding: 8px 12px;
            background: #f3f3f3;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: flex-end;
            text-align: right;
        }
        .amount-block .figure-box .lbl {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #555;
            font-weight: 700;
            margin-bottom: 2px;
        }
        .amount-block .figure-box .figure {
            font-family: 'Courier New', monospace;
            font-size: 22px;
            font-weight: 800;
            letter-spacing: -0.5px;
            line-height: 1;
        }

        /* Narration */
        .narration-block {
            border: 1px solid #000;
            padding: 6px 10px;
            background: #fafafa;
        }
        .narration-block .lbl {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #555;
            font-weight: 700;
            margin-bottom: 3px;
        }
        .narration-block .text {
            font-size: 12px;
            line-height: 1.4;
            min-height: 24px;
        }

        /* Accounting entry */
        .entry-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            margin-top: 4px;
        }
        .entry-table th {
            background: #000;
            color: #fff;
            padding: 5px 8px;
            text-align: left;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            font-weight: 700;
        }
        .entry-table th.amount-col { text-align: right; }
        .entry-table td {
            padding: 5px 8px;
            border-bottom: 1px solid #ccc;
            font-size: 11px;
        }
        .entry-table td.amount-col {
            text-align: right;
            font-family: 'Courier New', monospace;
            font-weight: 700;
        }
        .entry-table tr.total-row td {
            background: #f0f0f0;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            font-weight: 800;
        }
        .entry-table .acc-code {
            font-family: 'Courier New', monospace;
            color: #666;
            font-size: 10px;
        }

        /* Signature area */
        .signatures {
            margin-top: auto;
            display: flex;
            justify-content: space-between;
            gap: 20px;
            padding-top: 30px;
        }
        .signature-box {
            flex: 1;
            text-align: center;
        }
        .signature-box .line {
            border-top: 1.5px solid #000;
            margin-bottom: 3px;
        }
        .signature-box .label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        /* Footer */
        .v-footer {
            margin-top: 10px;
            padding-top: 6px;
            border-top: 1px solid #999;
            display: flex;
            justify-content: space-between;
            font-size: 9px;
            color: #555;
        }

        /* Print */
        @page { size: A5 landscape; margin: 0; }
        @media print {
            html, body { background: #fff; }
            .page {
                margin: 0;
                box-shadow: none;
                width: 100%;
                height: 100vh;
                padding: 8mm 10mm;
            }
            .no-print { display: none !important; }
        }

        /* Floating print button */
        .no-print-bar {
            position: fixed;
            top: 20px;
            right: 20px;
            display: flex;
            gap: 8px;
            z-index: 100;
        }
        .no-print-bar button, .no-print-bar a {
            padding: 10px 18px;
            border-radius: 8px;
            border: none;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-print { background: #1e40af; color: #fff; }
        .btn-print:hover { background: #1e3a8a; }
        .btn-close { background: #fff; color: #333; border: 1px solid #ccc; }
        .btn-close:hover { background: #f3f4f6; }
    </style>
</head>
<body>

<!-- Floating controls (hidden in print) -->
<div class="no-print-bar">
    <button class="btn-print" onclick="window.print();">
        <span class="glyphicon glyphicon-print"></span> Print
    </button>
    <a href="gl_voucher_view.php?id=<?php echo (int)$v['id']; ?>" class="btn-close">
        <span class="glyphicon glyphicon-arrow-left"></span> Back
    </a>
</div>

<div class="page">

    <!-- Header -->
    <div class="v-head">
        <div class="school">
            <div class="name">School Management System</div>
            <div class="tagline">Official Accounting Voucher</div>
        </div>
        <div class="voucher-type-box">
            <div class="title <?php echo $isPayment ? 'payment' : ''; ?>">
                <?php echo htmlspecialchars($meta['label']); ?>
            </div>
            <div class="number"><?php echo htmlspecialchars($v['voucher_number']); ?></div>
        </div>
    </div>

    <!-- Body -->
    <div class="v-body">

        <div class="row">
            <div class="col-half">
                <div class="field">
                    <span class="label">Date</span>
                    <span class="value mono"><?php echo date('d-M-Y', strtotime($v['entry_date'])); ?></span>
                </div>
            </div>
            <div class="col-half">
                <div class="field">
                    <span class="label">Session</span>
                    <span class="value"><?php echo htmlspecialchars($v['session_title'] ?? ''); ?></span>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-half">
                <div class="field">
                    <span class="label"><?php echo $isPayment ? 'Paid To' : 'Received From'; ?></span>
                    <span class="value"><?php echo htmlspecialchars($v['party_name']); ?></span>
                </div>
            </div>
            <div class="col-half">
                <div class="field">
                    <span class="label">Reference</span>
                    <span class="value mono">
                        <?php echo !empty($v['reference_number']) ? htmlspecialchars($v['reference_number']) : '—'; ?>
                    </span>
                </div>
            </div>
        </div>

        <?php if ($v['voucher_type'] === 'BPV' || $v['voucher_type'] === 'BRV'): ?>
        <div class="row">
            <div class="col-half">
                <div class="field">
                    <span class="label">Method</span>
                    <span class="value"><?php echo htmlspecialchars(ucfirst($v['payment_method'] ?? '')); ?></span>
                </div>
            </div>
            <div class="col-half">
                <div class="field">
                    <span class="label">Bank</span>
                    <span class="value"><?php echo htmlspecialchars($v['bank_name'] ?? '—'); ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Amount block -->
        <div class="amount-block">
            <div class="words-box">
                <div class="lbl">Amount in Words</div>
                <div class="words"><?php echo amountInWords((float)$v['amount']); ?></div>
            </div>
            <div class="figure-box">
                <div class="lbl">Amount (PKR)</div>
                <div class="figure">
                    <?php echo number_format((float)$v['amount'], 2); ?>
                </div>
            </div>
        </div>

        <!-- Narration -->
        <div class="narration-block">
            <div class="lbl">Narration / Purpose</div>
            <div class="text">
                <?php echo nl2br(htmlspecialchars($v['narration'] ?: '—')); ?>
            </div>
        </div>

        <!-- Accounting entry -->
        <table class="entry-table">
            <thead>
                <tr>
                    <th>Account</th>
                    <th class="amount-col" style="width: 130px;">Debit</th>
                    <th class="amount-col" style="width: 130px;">Credit</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($isPayment): ?>
                    <tr>
                        <td>
                            <?php echo htmlspecialchars($v['account_name']); ?>
                            <span class="acc-code">(<?php echo htmlspecialchars($v['account_code']); ?>)</span>
                        </td>
                        <td class="amount-col"><?php echo number_format((float)$v['amount'], 2); ?></td>
                        <td class="amount-col">—</td>
                    </tr>
                    <tr>
                        <td>
                            <?php echo htmlspecialchars($cashAccountName); ?>
                            <span class="acc-code">(<?php echo htmlspecialchars($cashAccountCode); ?>)</span>
                        </td>
                        <td class="amount-col">—</td>
                        <td class="amount-col"><?php echo number_format((float)$v['amount'], 2); ?></td>
                    </tr>
                <?php else: ?>
                    <tr>
                        <td>
                            <?php echo htmlspecialchars($cashAccountName); ?>
                            <span class="acc-code">(<?php echo htmlspecialchars($cashAccountCode); ?>)</span>
                        </td>
                        <td class="amount-col"><?php echo number_format((float)$v['amount'], 2); ?></td>
                        <td class="amount-col">—</td>
                    </tr>
                    <tr>
                        <td>
                            <?php echo htmlspecialchars($v['account_name']); ?>
                            <span class="acc-code">(<?php echo htmlspecialchars($v['account_code']); ?>)</span>
                        </td>
                        <td class="amount-col">—</td>
                        <td class="amount-col"><?php echo number_format((float)$v['amount'], 2); ?></td>
                    </tr>
                <?php endif; ?>
                <tr class="total-row">
                    <td style="text-align: right;">TOTAL</td>
                    <td class="amount-col"><?php echo number_format((float)$v['amount'], 2); ?></td>
                    <td class="amount-col"><?php echo number_format((float)$v['amount'], 2); ?></td>
                </tr>
            </tbody>
        </table>

        <!-- Signatures -->
        <div class="signatures">
            <?php foreach ($signatures as $sig): ?>
                <div class="signature-box">
                    <div class="line"></div>
                    <div class="label"><?php echo htmlspecialchars($sig); ?></div>
                </div>
            <?php endforeach; ?>
        </div>

    </div>

    <!-- Footer -->
    <div class="v-footer">
        <div>
            Voucher: <?php echo htmlspecialchars($v['voucher_number']); ?>
            &nbsp;·&nbsp;
            Created by: <?php echo htmlspecialchars($v['created_by_name'] ?? 'System'); ?>
        </div>
        <div>
            <?php if ($v['status'] === 'POSTED'): ?>
                Status: <strong>POSTED</strong> on <?php echo date('d-M-Y H:i', strtotime($v['posted_at'])); ?>
            <?php else: ?>
                Status: <strong><?php echo htmlspecialchars($v['status']); ?></strong>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
// Auto-trigger print dialog when opened with ?auto_print=1
if (window.location.search.indexOf('auto_print=1') !== -1) {
    window.addEventListener('load', function() { window.print(); });
}
</script>

</body>
</html>
<?php $conn->close(); ?>