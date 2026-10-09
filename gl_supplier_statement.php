<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

$party_id = (int)($_GET['id'] ?? 0);
if ($party_id <= 0) { header('Location: gl_parties.php'); exit; }

$supplier = gl_get_supplier($conn, $party_id);
if (!$supplier) { http_response_code(404); die('Supplier not found.'); }

// Default range: current session
$sess = $conn->query("SELECT from_dated, to_dated FROM sessions WHERE status = 0 ORDER BY id DESC LIMIT 1")->fetch_assoc();
$defaultFrom = $sess['from_dated'] ?? date('Y-m-01');
$defaultTo   = $sess['to_dated']   ?? date('Y-m-d');

$from = $_GET['from'] ?? $defaultFrom;
$to   = $_GET['to']   ?? $defaultTo;

$opening = gl_supplier_opening_ap($conn, $party_id, $from);
$lines   = gl_supplier_ledger($conn, $party_id, $from, $to);

$running = $opening;
$totalDr = 0.0;
$totalCr = 0.0;
foreach ($lines as &$ln) {
    $isAP = ($ln['account_code'] === '2010');
    if ($isAP) {
        $running += ((float)$ln['credit'] - (float)$ln['debit']);
        $ln['running'] = $running;
    } else {
        $ln['running'] = null;
    }
    $totalDr += (float)$ln['debit'];
    $totalCr += (float)$ln['credit'];
}
unset($ln);
$closing = $running;

$schoolName = 'Dar-e-Arqm School';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Statement — <?= htmlspecialchars($supplier['name']) ?></title>
<style>
    * { box-sizing: border-box; }
    body {
        background: #eef1f5;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        margin: 0; padding: 0; color: #1e293b;
    }
    .wrap { max-width: 900px; margin: 30px auto; padding: 0 20px; }

    .toolbar {
        background: #fff;
        padding: 14px 20px;
        border-radius: 10px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        display: flex; gap: 12px; align-items: center;
        flex-wrap: wrap; margin-bottom: 20px;
    }
    .toolbar label { font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 1px; }
    .toolbar input[type=date] {
        height: 38px; padding: 6px 12px;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 14px; font-family: inherit;
    }
    .btn-primary-custom {
        height: 38px; padding: 0 18px;
        background: #1e40af; color: #fff;
        border: none; border-radius: 8px;
        font-size: 13px; font-weight: 600;
        cursor: pointer; font-family: inherit;
        display: inline-flex; align-items: center; gap: 6px;
    }
    .btn-primary-custom:hover { background: #1e3a8a; }
    .btn-soft {
        height: 38px; padding: 0 16px;
        background: #f1f5f9; color: #334155;
        border: 1px solid #e2e8f0; border-radius: 8px;
        font-size: 13px; font-weight: 600;
        cursor: pointer; font-family: inherit;
        display: inline-flex; align-items: center; gap: 6px;
        text-decoration: none;
    }
    .btn-soft:hover { background: #e2e8f0; text-decoration: none; color: #1e293b; }

    .sheet {
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        padding: 40px 44px;
    }

    .sheet-head {
        border-bottom: 2px solid #1e40af;
        padding-bottom: 14px; margin-bottom: 22px;
        display: flex; justify-content: space-between; align-items: flex-start; gap: 20px;
        flex-wrap: wrap;
    }
    .sheet-head .school { font-size: 22px; font-weight: 800; color: #1e3a8a; margin: 0 0 4px 0; }
    .sheet-head .doc   { font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 2px; font-weight: 700; }
    .sheet-head .range { text-align: right; font-size: 13px; color: #475569; }
    .sheet-head .range strong { color: #0f172a; }

    .supplier-block {
        background: #f8fafc; border: 1px solid #e2e8f0;
        border-radius: 8px; padding: 14px 18px;
        margin-bottom: 22px;
        display: flex; justify-content: space-between; gap: 20px;
        flex-wrap: wrap;
    }
    .supplier-block .name { font-size: 17px; font-weight: 700; color: #0f172a; }
    .supplier-block .meta { font-size: 12px; color: #64748b; margin-top: 4px; }
    .supplier-block .meta span { display: inline-block; margin-right: 12px; }

    .summary {
        display: grid; grid-template-columns: repeat(3, 1fr);
        gap: 12px; margin-bottom: 22px;
    }
    .summary .box {
        border: 1px solid #e2e8f0; border-radius: 8px;
        padding: 12px 16px;
    }
    .summary .box .lbl { font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; }
    .summary .box .val { font-family: 'SF Mono', 'Monaco', monospace; font-size: 18px; font-weight: 700; margin-top: 4px; }
    .summary .box.opening .val { color: #475569; }
    .summary .box.dr .val      { color: #1e40af; }
    .summary .box.cr .val      { color: #047857; }
    .summary .box.closing .val { color: <?= $closing > 0.01 ? '#b91c1c' : '#047857' ?>; }

    table.stmt {
        width: 100%; border-collapse: collapse;
        border: 1px solid #cbd5e1;
    }
    table.stmt thead th {
        background: #1e40af; color: #fff;
        font-size: 11px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px;
        padding: 10px 12px; text-align: left;
    }
    table.stmt tbody td {
        padding: 9px 12px;
        font-size: 13px;
        border-bottom: 1px solid #e2e8f0;
    }
    table.stmt tbody tr:nth-child(even) td { background: #f8fafc; }
    table.stmt .num {
        font-family: 'SF Mono', 'Monaco', monospace;
        font-variant-numeric: tabular-nums;
        text-align: right;
    }
    table.stmt tbody tr.row-opening td { background: #f1f8e9 !important; font-weight: 700; }
    table.stmt tbody tr.row-closing td { background: #e3f2fd !important; font-weight: 700; border-top: 2px solid #1e40af; }
    table.stmt .desc { color: #334155; }
    table.stmt .muted { color: #94a3b8; font-size: 11px; }

    .footer-note {
        margin-top: 24px; padding-top: 14px;
        border-top: 1px dashed #cbd5e1;
        font-size: 11px; color: #64748b; text-align: center;
    }
    .empty-state { text-align: center; padding: 40px; color: #94a3b8; font-style: italic; }

    @media print {
        @page { size: A4 portrait; margin: 12mm 10mm; }
        .no-print { display: none !important; }
        body { background: #fff; }
        .wrap { max-width: none; margin: 0; padding: 0; }
        .sheet { box-shadow: none; border-radius: 0; padding: 0; }
        table.stmt thead th { background: #333 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        table.stmt tbody tr:nth-child(even) td { background: #f5f5f5 !important; -webkit-print-color-adjust: exact; }
        table.stmt tbody tr.row-opening td,
        table.stmt tbody tr.row-closing td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="toolbar no-print">
        <label>From</label>
        <input type="date" id="date_from" value="<?= htmlspecialchars($from) ?>">
        <label>To</label>
        <input type="date" id="date_to" value="<?= htmlspecialchars($to) ?>">
        <button type="button" class="btn-primary-custom" onclick="applyRange()">
            <span class="glyphicon glyphicon-filter"></span> Apply
        </button>
        <a href="gl_supplier_statement.php?id=<?= (int)$party_id ?>" class="btn-soft">Reset</a>
        <a href="gl_party_ledger.php?id=<?= (int)$party_id ?>" class="btn-soft">
            <span class="glyphicon glyphicon-list-alt"></span> Ledger
        </a>
        <button type="button" class="btn-soft" onclick="window.print()">
            <span class="glyphicon glyphicon-print"></span> Print
        </button>
    </div>

    <div class="sheet">

        <div class="sheet-head">
            <div>
                <h1 class="school"><?= htmlspecialchars($schoolName) ?></h1>
                <div class="doc">Supplier Statement of Account</div>
            </div>
            <div class="range">
                Statement Period<br>
                <strong><?= htmlspecialchars($from) ?> &nbsp;→&nbsp; <?= htmlspecialchars($to) ?></strong><br>
                <span style="font-size:11px; color:#94a3b8;">Generated: <?= date('d M Y, g:i A') ?></span>
            </div>
        </div>

        <div class="supplier-block">
            <div>
                <div class="name"><?= htmlspecialchars($supplier['name']) ?></div>
                <div class="meta">
                    <?php
                    $phone = $supplier['phone'] ?: $supplier['mobile'];
                    $email = $supplier['email'] ?: $supplier['contact'];
                    ?>
                    <?php if ($phone): ?><span>📞 <?= htmlspecialchars($phone) ?></span><?php endif; ?>
                    <?php if ($email): ?><span>✉ <?= htmlspecialchars($email) ?></span><?php endif; ?>
                    <?php if (!empty($supplier['tax_id'])): ?><span>NTN: <?= htmlspecialchars($supplier['tax_id']) ?></span><?php endif; ?>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:11px; color:#94a3b8; font-weight:700; text-transform:uppercase; letter-spacing:1px;">Supplier ID</div>
                <div style="font-family:'SF Mono',monospace; font-size:16px; font-weight:700;">#<?= (int)$supplier['id'] ?></div>
            </div>
        </div>

        <div class="summary">
            <div class="box opening">
                <div class="lbl">Opening Balance</div>
                <div class="val"><?= number_format($opening, 2) ?></div>
            </div>
            <div class="box dr">
                <div class="lbl">Total Debits (payments)</div>
                <div class="val"><?= number_format($totalDr, 2) ?></div>
            </div>
            <div class="box cr">
                <div class="lbl">Total Credits (bills)</div>
                <div class="val"><?= number_format($totalCr, 2) ?></div>
            </div>
        </div>

        <table class="stmt">
            <thead>
                <tr>
                    <th style="width:85px;">Date</th>
                    <th style="width:120px;">Reference</th>
                    <th>Description</th>
                    <th class="num" style="width:100px;">Debit</th>
                    <th class="num" style="width:100px;">Credit</th>
                    <th class="num" style="width:110px;">Balance</th>
                </tr>
            </thead>
            <tbody>
                <tr class="row-opening">
                    <td><?= htmlspecialchars($from) ?></td>
                    <td>—</td>
                    <td><strong>Opening Balance</strong></td>
                    <td class="num">—</td>
                    <td class="num">—</td>
                    <td class="num"><?= number_format($opening, 2) ?></td>
                </tr>

                <?php if (empty($lines)): ?>
                    <tr><td colspan="6" class="empty-state">No transactions in this period.</td></tr>
                <?php else: foreach ($lines as $ln):
                    $isAP = ($ln['account_code'] === '2010');
                    $voucherLabel = $ln['voucher_no'] ?: ('TXN-' . $ln['txn_id']);
                ?>
                    <tr>
                        <td><?= htmlspecialchars($ln['txn_date']) ?></td>
                        <td>
                            <?= htmlspecialchars($voucherLabel) ?>
                            <?php if ($ln['txn_status'] === 'REVERSED'): ?>
                                <span class="muted">(rev)</span>
                            <?php endif; ?>
                            <?php if (!$isAP): ?>
                                <div class="muted"><?= htmlspecialchars($ln['account_code']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="desc"><?= htmlspecialchars($ln['narration'] ?: $ln['memo']) ?></td>
                        <td class="num"><?= (float)$ln['debit']  ? number_format((float)$ln['debit'], 2)  : '' ?></td>
                        <td class="num"><?= (float)$ln['credit'] ? number_format((float)$ln['credit'], 2) : '' ?></td>
                        <td class="num"><?= $ln['running'] !== null ? number_format($ln['running'], 2) : '' ?></td>
                    </tr>
                <?php endforeach; endif; ?>

                <tr class="row-closing">
                    <td colspan="3"><strong>Closing Balance as of <?= htmlspecialchars($to) ?></strong></td>
                    <td class="num"><?= number_format($totalDr, 2) ?></td>
                    <td class="num"><?= number_format($totalCr, 2) ?></td>
                    <td class="num"><?= number_format($closing, 2) ?></td>
                </tr>
            </tbody>
        </table>

        <div class="footer-note">
            Positive closing balance = amount owed <strong>by the school</strong> to the supplier.
            Negative closing balance = amount owed <strong>by the supplier</strong> to the school (credit balance).
        </div>

    </div>
</div>

<script>
function applyRange() {
    var from = document.getElementById('date_from').value;
    var to   = document.getElementById('date_to').value;
    window.location.href = 'gl_supplier_statement.php?id=<?= (int)$party_id ?>&from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to);
}
</script>

</body>
</html>