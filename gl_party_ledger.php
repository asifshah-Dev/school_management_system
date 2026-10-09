<?php
require_once 'security.php';
require_once 'conn_inc.php';
require_once 'gl.php';

$conn->set_charset('utf8mb4');
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

$party_id = (int)($_GET['id'] ?? 0);
if ($party_id <= 0) {
    header('Location: gl_parties.php');
    exit;
}

$supplier = gl_get_supplier($conn, $party_id);
if (!$supplier) {
    http_response_code(404);
    die('Supplier not found.');
}

$from = $_GET['from'] ?? '';
$to   = $_GET['to']   ?? '';

$opening_ap = $from ? gl_supplier_opening_ap($conn, $party_id, $from) : 0.0;
$lines      = gl_supplier_ledger($conn, $party_id, $from ?: null, $to ?: null);

$running = $opening_ap;
$total_debit = 0.0;
$total_credit = 0.0;
foreach ($lines as &$ln) {
    $isAP = ($ln['account_code'] === '2010');
    if ($isAP) {
        $running += ((float)$ln['credit'] - (float)$ln['debit']);
        $ln['running'] = $running;
    } else {
        $ln['running'] = null;
    }
    $total_debit  += (float)$ln['debit'];
    $total_credit += (float)$ln['credit'];
}
unset($ln);
$closing_ap = $running;

$phone = $supplier['phone'] ?: $supplier['mobile'];
$email = $supplier['email'] ?: $supplier['contact'];

$page_title = 'Supplier Ledger — ' . $supplier['name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($page_title) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
<style>
    * { box-sizing: border-box; }
    body {
        background: #eef1f5;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        margin: 0; padding: 0; color: #1e293b;
    }
    .wrap { max-width: 1200px; margin: 30px auto; padding: 0 20px; }

    .head {
        background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
        color: #fff;
        padding: 30px 40px 26px 40px;
        border-radius: 12px 12px 0 0;
        box-shadow: 0 4px 16px rgba(30, 64, 175, 0.15);
        display: flex; justify-content: space-between;
        align-items: flex-end; gap: 20px; flex-wrap: wrap;
    }
    .head h1 { margin: 0; font-size: 30px; font-weight: 700; letter-spacing: -0.5px; }
    .head .sub { font-size: 14px; opacity: 0.9; margin-top: 6px; }
    .head .back {
        display: inline-flex; align-items: center; gap: 6px;
        color: #fff; text-decoration: underline; font-size: 13px;
        margin-bottom: 8px; opacity: 0.85;
    }
    .head .back:hover { opacity: 1; }

    .btn-new {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 10px 18px;
        background: rgba(255, 255, 255, 0.15);
        color: #fff;
        border: 1px solid rgba(255, 255, 255, 0.3);
        border-radius: 8px; text-decoration: none;
        font-size: 14px; font-weight: 600;
        transition: all 0.15s;
    }
    .btn-new:hover { background: #fff; color: #1e40af; text-decoration: none; border-color: #fff; }

    .card {
        background: #fff;
        padding: 30px 40px 40px 40px;
        border-radius: 0 0 12px 12px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    }

    .section-title {
        font-size: 12px; font-weight: 800; color: #64748b;
        text-transform: uppercase; letter-spacing: 2px;
        margin: 28px 0 14px 0;
        padding-bottom: 8px;
        border-bottom: 1px solid #e2e8f0;
    }
    .section-title:first-child { margin-top: 0; }

    /* Stat grid */
    .stat-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 14px;
        margin-bottom: 8px;
    }
    @media (max-width: 900px) { .stat-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 500px) { .stat-grid { grid-template-columns: 1fr; } }
    .stat-box {
        background: #f8fafc; border: 1px solid #e2e8f0;
        border-radius: 10px; padding: 16px 18px;
    }
    .stat-box .lbl {
        font-size: 11px; color: #64748b; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px;
        margin-bottom: 6px;
    }
    .stat-box .val {
        font-family: 'SF Mono', 'Monaco', monospace;
        font-size: 20px; font-weight: 700; color: #1e293b;
        font-variant-numeric: tabular-nums;
    }
    .stat-box .val.pos { color: #c62828; }

    /* Toolbar */
    .toolbar {
        display: flex; gap: 12px; flex-wrap: wrap;
        align-items: center; margin-bottom: 16px;
    }
    .toolbar label { font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
    .toolbar input[type=date] {
        height: 40px; padding: 8px 12px;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 14px; background: #fff; color: #0f172a;
        font-family: inherit;
    }
    .toolbar input[type=date]:focus {
        outline: none; border-color: #1e40af;
        box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
    }
    .btn-primary-custom {
        height: 40px; padding: 0 18px;
        background: #1e40af; color: #fff;
        border: none; border-radius: 8px;
        font-size: 13px; font-weight: 600;
        cursor: pointer; font-family: inherit;
        display: inline-flex; align-items: center; gap: 6px;
        transition: all 0.15s;
    }
    .btn-primary-custom:hover { background: #1e3a8a; }
    .btn-soft {
        height: 40px; padding: 0 16px;
        background: #f1f5f9; color: #334155;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: 13px; font-weight: 600;
        cursor: pointer; font-family: inherit;
        display: inline-flex; align-items: center; gap: 6px;
        text-decoration: none;
        transition: all 0.15s;
    }
    .btn-soft:hover { background: #e2e8f0; color: #1e293b; text-decoration: none; }

    /* Table */
    table.tbl {
        width: 100%; border-collapse: collapse;
        background: #fff; border-radius: 8px; overflow: hidden;
        border: 1px solid #e2e8f0;
    }
    table.tbl thead th {
        background: #f8fafc;
        font-size: 11px; color: #64748b; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px;
        padding: 12px 14px; text-align: left;
        border-bottom: 2px solid #e2e8f0;
    }
    table.tbl tbody td {
        padding: 11px 14px;
        font-size: 13px; color: #1e293b;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }
    table.tbl .num {
        font-family: 'SF Mono', 'Monaco', monospace;
        font-variant-numeric: tabular-nums;
        text-align: right;
    }
    tr.row-opening td { background: #f1f8e9; font-weight: 600; }
    tr.row-closing td { background: #e3f2fd; font-weight: 700; }
    tr.row-ap    td { background: #fff; }
    tr.row-other td { background: #fafafa; color: #64748b; }

    .rev-badge {
        display: inline-block; padding: 2px 6px;
        background: #fee2e2; color: #991b1b;
        font-size: 10px; font-weight: 700;
        border-radius: 4px; margin-left: 4px;
        letter-spacing: 0.5px;
    }

    .voucher-link {
        color: #1e40af; text-decoration: none; font-weight: 600;
    }
    .voucher-link:hover { text-decoration: underline; }

    .hint {
        margin-top: 16px; padding: 12px 16px;
        background: #f8fafc; border-left: 3px solid #cbd5e1;
        font-size: 12px; color: #64748b;
        border-radius: 4px;
    }

    @media print {
        .no-print { display: none !important; }
        body { background: #fff; }
        .head { background: #fff; color: #000; border-bottom: 2px solid #000; box-shadow: none; }
        .head .back, .head .sub { color: #000; }
        .card { box-shadow: none; padding: 0; }
        table.tbl thead th { background: #eee; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <a href="gl_parties.php" class="back no-print">
                <span class="glyphicon glyphicon-arrow-left"></span> Back to Suppliers
            </a>
            <h1><?= htmlspecialchars($supplier['name']) ?></h1>
            <div class="sub">
                Supplier #<?= (int)$supplier['id'] ?>
                <?php if ($phone): ?> &nbsp;·&nbsp; <span class="glyphicon glyphicon-phone"></span> <?= htmlspecialchars($phone) ?><?php endif; ?>
                <?php if ($email): ?> &nbsp;·&nbsp; <span class="glyphicon glyphicon-envelope"></span> <?= htmlspecialchars($email) ?><?php endif; ?>
                <?php if ((int)$supplier['status'] !== 1): ?> &nbsp;·&nbsp; <em>Inactive</em><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card">

        <div class="section-title">Account Summary</div>

        <div class="stat-grid">
            <div class="stat-box">
                <div class="lbl">Opening AP</div>
                <div class="val"><?= number_format($opening_ap, 2) ?></div>
            </div>
            <div class="stat-box">
                <div class="lbl">Total Debit</div>
                <div class="val"><?= number_format($total_debit, 2) ?></div>
            </div>
            <div class="stat-box">
                <div class="lbl">Total Credit</div>
                <div class="val"><?= number_format($total_credit, 2) ?></div>
            </div>
            <div class="stat-box">
                <div class="lbl">Closing AP</div>
                <div class="val <?= $closing_ap > 0.01 ? 'pos' : '' ?>"><?= number_format($closing_ap, 2) ?></div>
            </div>
        </div>

        <div class="section-title">Ledger Activity</div>

        <form class="toolbar no-print" method="get">
            <input type="hidden" name="id" value="<?= (int)$party_id ?>">
            <label>From</label>
            <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
            <label>To</label>
            <input type="date" name="to" value="<?= htmlspecialchars($to) ?>">
            <button type="submit" class="btn-primary-custom">
                <span class="glyphicon glyphicon-filter"></span> Apply
            </button>
            <a href="gl_party_ledger.php?id=<?= (int)$party_id ?>" class="btn-soft">
                <span class="glyphicon glyphicon-refresh"></span> Reset
            </a>
            <button type="button" class="btn-soft" onclick="window.print()">
                <span class="glyphicon glyphicon-print"></span> Print
            </button>
            <button type="button" class="btn-soft" onclick="exportCSV()">
                <span class="glyphicon glyphicon-download-alt"></span> CSV
            </button>
        </form>

        <table class="tbl" id="ledgerTable">
            <thead>
                <tr>
                    <th style="width:100px;">Date</th>
                    <th style="width:150px;">Voucher</th>
                    <th>Account</th>
                    <th>Description</th>
                    <th class="num" style="width:120px;">Debit</th>
                    <th class="num" style="width:120px;">Credit</th>
                    <th class="num" style="width:130px;">AP Running</th>
                </tr>
            </thead>
            <tbody>
                <tr class="row-opening">
                    <td colspan="6">Opening Balance as of <?= $from ? htmlspecialchars($from) : 'beginning' ?></td>
                    <td class="num"><?= number_format($opening_ap, 2) ?></td>
                </tr>

                <?php if (empty($lines)): ?>
                    <tr><td colspan="7" style="text-align:center; padding:24px; color:#94a3b8;">
                        No ledger activity in this period.
                    </td></tr>
                <?php else: foreach ($lines as $ln):
                    $isAP = ($ln['account_code'] === '2010');
                    $voucherLabel = $ln['voucher_no'] ?: ('TXN-' . $ln['txn_id']);
                ?>
                    <tr class="<?= $isAP ? 'row-ap' : 'row-other' ?>">
                        <td><?= htmlspecialchars($ln['txn_date']) ?></td>
                        <td>
                            <a href="gl_transaction_view.php?id=<?= (int)$ln['txn_id'] ?>" class="voucher-link">
                                <?= htmlspecialchars($voucherLabel) ?>
                            </a>
                            <?php if ($ln['txn_status'] === 'REVERSED'): ?>
                                <span class="rev-badge">REV</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <small style="color:#64748b; font-family:'SF Mono',monospace;"><?= htmlspecialchars($ln['account_code']) ?></small>
                            <?= htmlspecialchars($ln['account_name']) ?>
                        </td>
                        <td><?= htmlspecialchars($ln['narration'] ?: $ln['memo']) ?></td>
                        <td class="num"><?= (float)$ln['debit']  ? number_format((float)$ln['debit'], 2)  : '' ?></td>
                        <td class="num"><?= (float)$ln['credit'] ? number_format((float)$ln['credit'], 2) : '' ?></td>
                        <td class="num"><?= $ln['running'] !== null ? number_format($ln['running'], 2) : '—' ?></td>
                    </tr>
                <?php endforeach; endif; ?>

                <tr class="row-closing">
                    <td colspan="4">Period Totals</td>
                    <td class="num"><?= number_format($total_debit, 2) ?></td>
                    <td class="num"><?= number_format($total_credit, 2) ?></td>
                    <td class="num"><?= number_format($closing_ap, 2) ?></td>
                </tr>
            </tbody>
        </table>

        <div class="hint">
            <strong>AP Running</strong> only updates on lines hitting account <code>2010</code> (Accounts Payable).
            Greyed rows are non-AP lines tagged to this supplier for context.
            Positive closing = school owes supplier.
        </div>

    </div>
</div>

<script>
function exportCSV() {
    var rows = [];
    document.querySelectorAll('#ledgerTable tr').forEach(function(tr){
        var cells = tr.querySelectorAll('th,td');
        var row = [];
        cells.forEach(function(c){ row.push(c.innerText.trim()); });
        if (row.length) rows.push(row);
    });
    var csv = rows.map(r => r.map(c => '"' + c.replace(/"/g,'""') + '"').join(',')).join('\n');
    var blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'supplier_<?= (int)$party_id ?>_ledger.csv';
    a.click();
}
</script>
</body>
</html>