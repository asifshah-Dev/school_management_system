<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

/* ---------- Date range ---------- */
$sessRow = $conn->query("
    SELECT from_dated, to_dated FROM sessions
    WHERE status = 0 ORDER BY id DESC LIMIT 1
")->fetch_assoc();

$defaultFrom = $sessRow ? $sessRow['from_dated'] : date('Y-m-01');
$defaultTo   = $sessRow ? $sessRow['to_dated']   : date('Y-m-d');

$from = $_GET['from'] ?? $defaultFrom;
$to   = $_GET['to']   ?? $defaultTo;

/* ---------- Load settings ---------- */
$cashCodes    = gl_get_setting($conn, 'cash_flow_cash_accounts')    ?: '1010,1020';
$investPrefix = gl_get_setting($conn, 'cash_flow_investing_codes')  ?: '15';
$finPrefix    = gl_get_setting($conn, 'cash_flow_financing_codes')  ?: '25,30';

$cashCodesArr    = array_map('trim', explode(',', $cashCodes));
$investPrefixArr = array_filter(array_map('trim', explode(',', $investPrefix)));
$finPrefixArr    = array_filter(array_map('trim', explode(',', $finPrefix)));

/* ---------- Resolve cash account IDs ---------- */
$cashAccountIds = [];
foreach ($cashCodesArr as $code) {
    $a = gl_get_account_by_code($conn, $code);
    if ($a) $cashAccountIds[] = (int)$a['id'];
}
$cashIdsSql = implode(',', $cashAccountIds);
if (empty($cashAccountIds)) {
    die('No cash accounts configured. Set cash_flow_cash_accounts in gl_settings.');
}

/* ---------- Helper: classify an account ---------- */
function classify_account(array $acc, array $investPrefixes, array $finPrefixes): string {
    $code = $acc['account_code'] ?? '';
    $type = $acc['account_type'] ?? '';
    if ($code === '') return 'operating';

    foreach ($investPrefixes as $p) {
        if ($p !== '' && strpos($code, $p) === 0) return 'investing';
    }
    if ($type === 'EQUITY') return 'financing';
    foreach ($finPrefixes as $p) {
        if ($p !== '' && strpos($code, $p) === 0) return 'financing';
    }
    return 'operating';
}

/* ---------- Opening cash balance (before $from) ---------- */
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(l.debit - l.credit), 0) AS bal
    FROM gl_journal_lines l
    JOIN gl_transactions t ON t.id = l.transaction_id
    WHERE l.account_id IN ($cashIdsSql)
      AND t.entry_date < ?
");
$stmt->bind_param("s", $from);
$stmt->execute();
$openingCash = (float)$stmt->get_result()->fetch_assoc()['bal'];
$stmt->close();

/* ---------- Fetch all transactions in range ---------- */
$stmt = $conn->prepare("
    SELECT
        t.id            AS txn_id,
        t.entry_date,
        t.description,
        t.reference_type,
        t.status
    FROM gl_transactions t
    WHERE t.entry_date BETWEEN ? AND ?
    ORDER BY t.entry_date ASC, t.id ASC
");
$stmt->bind_param("ss", $from, $to);
$stmt->execute();
$transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ---------- Build cash flow entries ---------- */
$operating = [];
$investing = [];
$financing = [];

$totalOperating = 0.0;
$totalInvesting = 0.0;
$totalFinancing = 0.0;

$lineStmt = $conn->prepare("
    SELECT
        l.id             AS line_id,
        l.transaction_id,
        l.account_id,
        l.debit,
        l.credit,
        a.code           AS account_code,
        a.name           AS account_name,
        a.account_type
    FROM gl_journal_lines l
    JOIN gl_accounts a ON a.id = l.account_id
    WHERE l.transaction_id = ?
    ORDER BY l.id
");

foreach ($transactions as $txn) {
    $lineStmt->bind_param("i", $txn['txn_id']);
    $lineStmt->execute();
    $lines = $lineStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    // NOTE: do NOT close $lineStmt here — it's reused in the next iteration.

    $cashNet = 0.0;
    $counterLines = [];

    foreach ($lines as $line) {
        if (in_array((int)$line['account_id'], $cashAccountIds, true)) {
            $cashNet += ((float)$line['debit'] - (float)$line['credit']);
        } else {
            $counterLines[] = $line;
        }
    }

    if (abs($cashNet) < 0.005) continue;

    $catOperating = 0.0;
    $catInvesting = 0.0;
    $catFinancing = 0.0;

    foreach ($counterLines as $line) {
        $lineCashEffect = (float)$line['credit'] - (float)$line['debit'];
        $category = classify_account($line, $investPrefixArr, $finPrefixArr);

        if ($category === 'investing') {
            $catInvesting += $lineCashEffect;
        } elseif ($category === 'financing') {
            $catFinancing += $lineCashEffect;
        } else {
            $catOperating += $lineCashEffect;
        }
    }

    $desc = $txn['description'] ?: ('Transaction #' . $txn['txn_id']);
    $refLabel = $txn['reference_type'] ? strtoupper($txn['reference_type']) : '';

    if (abs($catOperating) > 0.005) {
        $operating[] = [
            'date'        => $txn['entry_date'],
            'description' => $desc,
            'ref'         => $refLabel,
            'amount'      => $catOperating,
        ];
        $totalOperating += $catOperating;
    }
    if (abs($catInvesting) > 0.005) {
        $investing[] = [
            'date'        => $txn['entry_date'],
            'description' => $desc,
            'ref'         => $refLabel,
            'amount'      => $catInvesting,
        ];
        $totalInvesting += $catInvesting;
    }
    if (abs($catFinancing) > 0.005) {
        $financing[] = [
            'date'        => $txn['entry_date'],
            'description' => $desc,
            'ref'         => $refLabel,
            'amount'      => $catFinancing,
        ];
        $totalFinancing += $catFinancing;
    }
}

// Close the reused prepared statement ONCE, after the loop
$lineStmt->close();

$netChange = $totalOperating + $totalInvesting + $totalFinancing;
$closingCash = $openingCash + $netChange;

/* ---------- Verify ---------- */
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(l.debit - l.credit), 0) AS bal
    FROM gl_journal_lines l
    JOIN gl_transactions t ON t.id = l.transaction_id
    WHERE l.account_id IN ($cashIdsSql)
      AND t.entry_date <= ?
");
$stmt->bind_param("s", $to);
$stmt->execute();
$ledgerClosingCash = (float)$stmt->get_result()->fetch_assoc()['bal'];
$stmt->close();

$reconDiff = round($closingCash - $ledgerClosingCash, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Cash Flow Statement — Dar-e-Arqm School</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    * { box-sizing: border-box; }
    body { background: #eef1f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 0; color: #1e293b; }
    .wrap { max-width: 1200px; margin: 30px auto; padding: 0 20px; }

    .head {
        background: linear-gradient(135deg, #0891b2 0%, #0e7490 100%);
        color: #fff; padding: 30px 40px 26px 40px;
        border-radius: 12px 12px 0 0; box-shadow: 0 4px 16px rgba(8,145,178,0.15);
        display: flex; justify-content: space-between; align-items: flex-end;
        gap: 20px; flex-wrap: wrap;
    }
    .head h1 { margin: 0; font-size: 30px; font-weight: 700; }
    .head .sub { font-size: 14px; opacity: 0.9; margin-top: 6px; }

    .btn-back {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 10px 20px; background: #ffffff; color: #0891b2;
        border: 1px solid #ffffff; border-radius: 8px;
        text-decoration: none; font-size: 14px; font-weight: 600;
        line-height: 1; font-family: inherit;
    }
    .btn-back:hover { background: #cffafe; border-color: #cffafe; text-decoration: none; color: #0891b2; }

    .card { background: #fff; padding: 30px 40px 40px 40px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

    .toolbar { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 24px; padding-bottom: 20px; border-bottom: 1px solid #e2e8f0; }
    .field-inline { display: flex; flex-direction: column; gap: 5px; }
    .field-inline label { font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 1px; }
    .field-inline input {
        height: 40px; padding: 8px 12px;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 14px; font-family: inherit; background: #fff; color: #0f172a;
    }
    .field-inline input:focus { outline: none; border-color: #0891b2; box-shadow: 0 0 0 3px rgba(8,145,178,0.1); }

    .action-btn-primary {
        display: inline-flex; align-items: center; gap: 6px;
        height: 40px; padding: 0 18px;
        background: #0891b2; color: #fff;
        border: 1px solid #0891b2; border-radius: 8px;
        font-size: 13px; font-weight: 600; font-family: inherit;
        text-decoration: none; cursor: pointer; white-space: nowrap;
    }
    .action-btn-primary:hover { background: #0e7490; border-color: #0e7490; text-decoration: none; color: #fff; }
    .btn-soft {
        display: inline-flex; align-items: center; gap: 6px;
        height: 40px; padding: 0 16px;
        background: #f1f5f9; color: #334155;
        border: 1px solid #e2e8f0; border-radius: 8px;
        font-size: 13px; font-weight: 600; font-family: inherit;
        text-decoration: none; cursor: pointer;
    }
    .btn-soft:hover { background: #e2e8f0; text-decoration: none; color: #1e293b; }

    .hero-bar {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px,1fr));
        gap: 14px;
        margin-bottom: 28px;
    }
    .hero-box {
        background: #f8fafc; border: 1px solid #e2e8f0;
        border-radius: 10px; padding: 18px 20px; border-left: 5px solid;
    }
    .hero-box .lbl { font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
    .hero-box .val { font-family: 'SF Mono','Monaco',monospace; font-size: 22px; font-weight: 800; color: #0f172a; }
    .hero-box.s-open    { border-color: #64748b; }
    .hero-box.s-net     { border-color: #0891b2; }
    .hero-box.s-close   { border-color: #047857; background: #ecfdf5; }
    .hero-box.s-verify  { border-color: #059669; }
    .hero-box.s-broken  { border-color: #dc2626; background: #fef2f2; }

    .cf-section {
        margin-bottom: 24px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        overflow: hidden;
    }
    .cf-section-head {
        padding: 14px 20px;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        display: flex; justify-content: space-between; align-items: center;
    }
    .cf-section-head h3 {
        margin: 0;
        font-size: 15px; font-weight: 800; color: #0f172a;
        text-transform: uppercase; letter-spacing: 1.5px;
    }
    .cf-section-head .total {
        font-family: 'SF Mono','Monaco',monospace;
        font-size: 18px; font-weight: 800;
    }
    .cf-section-head .total.pos { color: #047857; }
    .cf-section-head .total.neg { color: #b91c1c; }
    .cf-section.op .cf-section-head { border-left: 5px solid #0891b2; }
    .cf-section.inv .cf-section-head { border-left: 5px solid #b45309; }
    .cf-section.fin .cf-section-head { border-left: 5px solid #7c3aed; }

    table.cf-tbl { width: 100%; border-collapse: collapse; }
    table.cf-tbl thead th {
        background: #f1f5f9; font-size: 10px; color: #64748b;
        font-weight: 700; text-transform: uppercase; letter-spacing: 1px;
        padding: 9px 20px; text-align: left;
        border-bottom: 1px solid #e2e8f0;
    }
    table.cf-tbl tbody td {
        padding: 9px 20px; font-size: 13px; color: #1e293b;
        border-bottom: 1px solid #f1f5f9;
    }
    table.cf-tbl tbody tr:last-child td { border-bottom: none; }
    table.cf-tbl tbody tr:hover td { background: #f8fafc; }
    table.cf-tbl .num { font-family: 'SF Mono','Monaco',monospace; text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    table.cf-tbl .num.pos { color: #047857; font-weight: 600; }
    table.cf-tbl .num.neg { color: #b91c1c; font-weight: 600; }

    .cf-empty {
        padding: 24px 20px; text-align: center;
        color: #94a3b8; font-style: italic; font-size: 13px;
    }

    .cf-summary {
        background: #f8fafc; border: 2px solid #0891b2;
        border-radius: 12px; padding: 22px 28px; margin-top: 24px;
    }
    .cf-summary table { width: 100%; border-collapse: collapse; }
    .cf-summary td { padding: 8px 0; font-size: 14px; }
    .cf-summary td:last-child {
        text-align: right;
        font-family: 'SF Mono','Monaco',monospace;
        font-variant-numeric: tabular-nums;
        font-weight: 600;
    }
    .cf-summary tr.total-row td {
        border-top: 2px solid #0891b2;
        border-bottom: 2px solid #0891b2;
        padding: 12px 0; font-size: 16px; font-weight: 800;
    }
    .cf-summary tr.closing td { font-size: 16px; font-weight: 800; }

    .verify-box {
        padding: 16px 20px; border-radius: 10px; margin-top: 16px;
        font-size: 13px; line-height: 1.6; border-left: 4px solid;
    }
    .verify-box.ok  { background: #d1fae5; border-color: #059669; color: #065f46; }
    .verify-box.bad { background: #fee2e2; border-color: #dc2626; color: #991b1b; }

    @media print {
        @page { size: A4 portrait; margin: 12mm 10mm; }
        .no-print { display: none !important; }
        body { background: #fff; }
        .head { background: #fff !important; color: #000 !important; border-bottom: 2px solid #000; box-shadow: none; }
        .head h1 { color: #000; font-size: 18pt; }
        .head .sub { color: #333; }
        .card { box-shadow: none; padding: 0; }
        table.cf-tbl thead th { background: #eee; }
        .hero-box { border: 1px solid #999; }
        .cf-section { border: 1px solid #999; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <h1>Cash Flow Statement</h1>
            <div class="sub">Where did cash come from and go? &middot; <?= htmlspecialchars($from) ?> → <?= htmlspecialchars($to) ?></div>
        </div>
        <a href="gl_test_hub.php" class="btn-back no-print">
            <span class="glyphicon glyphicon-arrow-left"></span> Test Hub
        </a>
    </div>

    <div class="card">

        <form method="get" class="toolbar no-print">
            <div class="field-inline">
                <label>From</label>
                <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
            </div>
            <div class="field-inline">
                <label>To</label>
                <input type="date" name="to" value="<?= htmlspecialchars($to) ?>">
            </div>
            <button type="submit" class="action-btn-primary">
                <span class="glyphicon glyphicon-filter"></span> Apply
            </button>
            <a href="gl_cash_flow.php" class="btn-soft">
                <span class="glyphicon glyphicon-refresh"></span> Reset
            </a>
            <button type="button" class="btn-soft" onclick="window.print()">
                <span class="glyphicon glyphicon-print"></span> Print
            </button>
        </form>

        <div class="hero-bar">
            <div class="hero-box s-open">
                <div class="lbl">Opening Cash</div>
                <div class="val"><?= number_format($openingCash, 2) ?></div>
            </div>
            <div class="hero-box s-net">
                <div class="lbl">Net Change</div>
                <div class="val" style="color:<?= $netChange >= 0 ? '#0891b2' : '#b91c1c' ?>;">
                    <?= number_format($netChange, 2) ?>
                </div>
            </div>
            <div class="hero-box s-close">
                <div class="lbl">Closing Cash</div>
                <div class="val"><?= number_format($closingCash, 2) ?></div>
            </div>
            <div class="hero-box <?= abs($reconDiff) < 0.01 ? 's-verify' : 's-broken' ?>">
                <div class="lbl">Reconciliation</div>
                <div class="val" style="color:<?= abs($reconDiff) < 0.01 ? '#047857' : '#b91c1c' ?>;">
                    <?= abs($reconDiff) < 0.01 ? '✓ Balanced' : 'Diff ' . number_format($reconDiff, 2) ?>
                </div>
            </div>
        </div>

        <!-- OPERATING -->
        <div class="cf-section op">
            <div class="cf-section-head">
                <h3>Operating Activities</h3>
                <div class="total <?= $totalOperating >= 0 ? 'pos' : 'neg' ?>">
                    <?= ($totalOperating >= 0 ? '+' : '') . number_format($totalOperating, 2) ?>
                </div>
            </div>
            <?php if (empty($operating)): ?>
                <div class="cf-empty">No operating cash flows in this period.</div>
            <?php else: ?>
                <table class="cf-tbl">
                    <thead>
                        <tr>
                            <th style="width:90px;">Date</th>
                            <th style="width:80px;">Ref</th>
                            <th>Description</th>
                            <th class="num" style="width:130px;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($operating as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['date']) ?></td>
                                <td><small style="font-family:'SF Mono',monospace; color:#64748b;"><?= htmlspecialchars($row['ref']) ?></small></td>
                                <td><?= htmlspecialchars($row['description']) ?></td>
                                <td class="num <?= $row['amount'] >= 0 ? 'pos' : 'neg' ?>">
                                    <?= number_format($row['amount'], 2) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- INVESTING -->
        <div class="cf-section inv">
            <div class="cf-section-head">
                <h3>Investing Activities</h3>
                <div class="total <?= $totalInvesting >= 0 ? 'pos' : 'neg' ?>">
                    <?= ($totalInvesting >= 0 ? '+' : '') . number_format($totalInvesting, 2) ?>
                </div>
            </div>
            <?php if (empty($investing)): ?>
                <div class="cf-empty">No investing cash flows in this period.</div>
            <?php else: ?>
                <table class="cf-tbl">
                    <thead>
                        <tr>
                            <th style="width:90px;">Date</th>
                            <th style="width:80px;">Ref</th>
                            <th>Description</th>
                            <th class="num" style="width:130px;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($investing as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['date']) ?></td>
                                <td><small style="font-family:'SF Mono',monospace; color:#64748b;"><?= htmlspecialchars($row['ref']) ?></small></td>
                                <td><?= htmlspecialchars($row['description']) ?></td>
                                <td class="num <?= $row['amount'] >= 0 ? 'pos' : 'neg' ?>">
                                    <?= number_format($row['amount'], 2) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- FINANCING -->
        <div class="cf-section fin">
            <div class="cf-section-head">
                <h3>Financing Activities</h3>
                <div class="total <?= $totalFinancing >= 0 ? 'pos' : 'neg' ?>">
                    <?= ($totalFinancing >= 0 ? '+' : '') . number_format($totalFinancing, 2) ?>
                </div>
            </div>
            <?php if (empty($financing)): ?>
                <div class="cf-empty">No financing cash flows in this period.</div>
            <?php else: ?>
                <table class="cf-tbl">
                    <thead>
                        <tr>
                            <th style="width:90px;">Date</th>
                            <th style="width:80px;">Ref</th>
                            <th>Description</th>
                            <th class="num" style="width:130px;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($financing as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['date']) ?></td>
                                <td><small style="font-family:'SF Mono',monospace; color:#64748b;"><?= htmlspecialchars($row['ref']) ?></small></td>
                                <td><?= htmlspecialchars($row['description']) ?></td>
                                <td class="num <?= $row['amount'] >= 0 ? 'pos' : 'neg' ?>">
                                    <?= number_format($row['amount'], 2) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Summary -->
        <div class="cf-summary">
            <table>
                <tr>
                    <td>Operating Activities</td>
                    <td style="color:<?= $totalOperating >= 0 ? '#047857' : '#b91c1c' ?>;">
                        <?= number_format($totalOperating, 2) ?>
                    </td>
                </tr>
                <tr>
                    <td>Investing Activities</td>
                    <td style="color:<?= $totalInvesting >= 0 ? '#047857' : '#b91c1c' ?>;">
                        <?= number_format($totalInvesting, 2) ?>
                    </td>
                </tr>
                <tr>
                    <td>Financing Activities</td>
                    <td style="color:<?= $totalFinancing >= 0 ? '#047857' : '#b91c1c' ?>;">
                        <?= number_format($totalFinancing, 2) ?>
                    </td>
                </tr>
                <tr class="total-row">
                    <td>Net Change in Cash</td>
                    <td style="color:<?= $netChange >= 0 ? '#047857' : '#b91c1c' ?>;">
                        <?= number_format($netChange, 2) ?>
                    </td>
                </tr>
                <tr>
                    <td>Cash at start of period</td>
                    <td><?= number_format($openingCash, 2) ?></td>
                </tr>
                <tr class="closing">
                    <td>Cash at end of period</td>
                    <td style="color:#0891b2;"><?= number_format($closingCash, 2) ?></td>
                </tr>
            </table>
        </div>

        <?php if (abs($reconDiff) < 0.01): ?>
            <div class="verify-box ok">
                <strong>✓ Verified.</strong> Cash flow statement matches the ledger's cash accounts (<code><?= htmlspecialchars($cashCodes) ?></code>) exactly. Closing cash per this report = closing cash per GL.
            </div>
        <?php else: ?>
            <div class="verify-box bad">
                <strong>✗ Discrepancy of PKR <?= number_format($reconDiff, 2) ?>.</strong>
                The cash flow statement shows <?= number_format($closingCash, 2) ?> but the ledger's cash accounts total <?= number_format($ledgerClosingCash, 2) ?>.
                This usually means a transaction was recorded that debits and credits the same cash accounts (net zero), or a transaction has no counter-account.
            </div>
        <?php endif; ?>

    </div>
</div>

</body>
</html>