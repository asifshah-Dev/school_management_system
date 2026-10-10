<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

$studentId = (int)($_GET['id'] ?? 0);
if ($studentId <= 0) { header('Location: gl_fee_defaulters.php'); exit; }

/* ---------- Load student ---------- */
$stmt = $conn->prepare("
    SELECT sr.*
    FROM student_registration sr
    WHERE sr.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $studentId);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) { http_response_code(404); die('Student not found.'); }

/* ---------- Date range ---------- */
// Default the range to the FULL current session so no fee card is missed.
$sessRow = $conn->query("
    SELECT from_dated, to_dated FROM sessions
    WHERE status = 0 ORDER BY id DESC LIMIT 1
")->fetch_assoc();

$defaultFrom = $sessRow ? $sessRow['from_dated'] : date('Y-m-d', strtotime('-2 years'));
$defaultTo   = $sessRow ? $sessRow['to_dated']   : date('Y-m-d');

$from = $_GET['from'] ?? $defaultFrom;
$to   = $_GET['to']   ?? $defaultTo;

/* ---------- Opening balance: everything before $from ---------- */
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(c.total_amount - COALESCE(c.discount_amount,0)), 0) AS total_cards,
           COALESCE((SELECT SUM(p.paid_amount + COALESCE(p.discount_amount,0))
                     FROM student_fee_payments p
                     JOIN student_fee_card cc ON cc.id = p.fee_card_id
                     JOIN student_class scp ON scp.id = cc.student_class_id
                     WHERE scp.student_registration_id = ?
                       AND p.status = 1
                       AND p.payment_date < ?), 0) AS total_paid
    FROM student_fee_card c
    JOIN student_class sc ON sc.id = c.student_class_id
    WHERE sc.student_registration_id = ?
      AND DATE(c.dated) < ?
");
$stmt->bind_param("isis", $studentId, $from, $studentId, $from);
$stmt->execute();
$openingRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

$openingBalance = (float)$openingRow['total_cards'] - (float)$openingRow['total_paid'];

/* ---------- Fee cards in range ---------- */
$stmt = $conn->prepare("
    SELECT
        c.id              AS card_id,
        DATE(c.dated)     AS entry_date,
        c.total_amount,
        c.discount_amount AS card_discount,
        c.due_date,
        c.month,
        c.status          AS card_status,
        c.remarks,
        ft.title          AS fee_type_title,
        cl.title          AS class_title,
        s.title           AS session_title
    FROM student_fee_card c
    JOIN student_class sc ON sc.id = c.student_class_id
    LEFT JOIN fee_types ft ON ft.id = c.fee_type_id
    LEFT JOIN classes cl ON cl.id = sc.class_id
    LEFT JOIN sessions s ON s.id = c.session_id
    WHERE sc.student_registration_id = ?
      AND DATE(c.dated) BETWEEN ? AND ?
    ORDER BY c.dated ASC, c.id ASC
");
$stmt->bind_param("iss", $studentId, $from, $to);
$stmt->execute();
$cards = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ---------- Payments in range ---------- */
$stmt = $conn->prepare("
    SELECT
        p.id              AS payment_id,
        p.payment_date    AS entry_date,
        p.paid_amount,
        p.discount_amount AS payment_discount,
        p.payment_method,
        p.transaction_ref,
        p.remarks,
        p.fee_card_id,
        c.due_date,
        ft.title          AS fee_type_title
    FROM student_fee_payments p
    JOIN student_fee_card c ON c.id = p.fee_card_id
    JOIN student_class sc ON sc.id = c.student_class_id
    LEFT JOIN fee_types ft ON ft.id = c.fee_type_id
    WHERE sc.student_registration_id = ?
      AND p.status = 1
      AND p.payment_date BETWEEN ? AND ?
    ORDER BY p.payment_date ASC, p.id ASC
");
$stmt->bind_param("iss", $studentId, $from, $to);
$stmt->execute();
$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ---------- Advances in range ---------- */
$stmt = $conn->prepare("
    SELECT
        a.id,
        a.payment_date    AS entry_date,
        a.amount,
        a.remaining_amount,
        a.payment_method,
        a.remarks,
        a.status,
        (SELECT cl.title FROM student_class sc
         LEFT JOIN classes cl ON cl.id = sc.class_id
         WHERE sc.id = a.student_class_id LIMIT 1) AS class_title
    FROM advance_payments a
    JOIN student_class sc ON sc.id = a.student_class_id
    WHERE sc.student_registration_id = ?
      AND a.payment_date BETWEEN ? AND ?
      AND a.status IN ('active','used')
    ORDER BY a.payment_date ASC, a.id ASC
");
$stmt->bind_param("iss", $studentId, $from, $to);
$stmt->execute();
$advances = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ---------- Merge into one chronological ledger ---------- */
$ledger = [];

foreach ($cards as $c) {
    $netCharge = (float)$c['total_amount'] - (float)$c['card_discount'];
    $monthLabel = '';
    if ($c['month'] > 0 && $c['due_date']) {
        $monthLabel = ' (' . date('M Y', mktime(0,0,0,(int)$c['month'],1,(int)date('Y', strtotime($c['due_date'])))) . ')';
    }
    $ledger[] = [
        'date'         => $c['entry_date'],
        'type'         => 'CHARGE',
        'ref'          => 'FC-' . $c['card_id'],
        'description'  => ($c['fee_type_title'] ?: 'Fee') . ' — Card #' . $c['card_id'] . $monthLabel,
        'debit'        => $netCharge,
        'credit'       => 0,
        'extra'        => 'Due ' . ($c['due_date'] ?: '—'),
        'tie_id'       => (int)$c['card_id'],
    ];
}

foreach ($payments as $p) {
    $totalCredit = (float)$p['paid_amount'] + (float)$p['payment_discount'];
    $ledger[] = [
        'date'         => $p['entry_date'],
        'type'         => 'PAYMENT',
        'ref'          => 'PM-' . $p['payment_id'],
        'description'  => 'Payment for ' . ($p['fee_type_title'] ?: 'Fee')
                         . ($p['payment_method'] ? ' via ' . $p['payment_method'] : ''),
        'debit'        => 0,
        'credit'       => $totalCredit,
        'extra'        => $p['transaction_ref'] ?: '',
        'tie_id'       => (int)$p['payment_id'],
    ];
}

foreach ($advances as $a) {
    $ledger[] = [
        'date'         => $a['entry_date'],
        'type'         => 'ADVANCE',
        'ref'          => 'AD-' . $a['id'],
        'description'  => 'Advance fee received'
                         . ($a['payment_method'] ? ' via ' . $a['payment_method'] : ''),
        'debit'        => 0,
        'credit'       => (float)$a['amount'],
        'extra'        => 'Remaining: ' . number_format((float)$a['remaining_amount'], 2),
        'tie_id'       => (int)$a['id'],
    ];
}

usort($ledger, function($a, $b) {
    if ($a['date'] !== $b['date']) return strcmp($a['date'], $b['date']);
    $prio = ['CHARGE' => 1, 'PAYMENT' => 2, 'ADVANCE' => 3];
    return ($prio[$a['type']] ?? 9) <=> ($prio[$b['type']] ?? 9);
});

$running = $openingBalance;
$totalCharges = 0.0;
$totalPayments = 0.0;
$totalAdvances = 0.0;
foreach ($ledger as &$row) {
    $running += $row['debit'] - $row['credit'];
    $row['running'] = $running;
    if ($row['type'] === 'CHARGE')  $totalCharges += $row['debit'];
    if ($row['type'] === 'PAYMENT') $totalPayments += $row['credit'];
    if ($row['type'] === 'ADVANCE') $totalAdvances += $row['credit'];
}
unset($row);

$closingBalance = $running;

/* ---------- Student's current class ---------- */
$classRow = $conn->query("
    SELECT cl.title, s.title AS session_title
    FROM student_class sc
    LEFT JOIN classes cl ON cl.id = sc.class_id
    LEFT JOIN sessions s ON s.id = sc.session_id
    WHERE sc.student_registration_id = $studentId
    ORDER BY sc.id DESC LIMIT 1
")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Student Ledger — <?= htmlspecialchars($student['name']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    * { box-sizing: border-box; }
    body { background: #eef1f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 0; color: #1e293b; }
    .wrap { max-width: 1200px; margin: 30px auto; padding: 0 20px; }

    .head {
        background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
        color: #fff; padding: 30px 40px 26px 40px;
        border-radius: 12px 12px 0 0; box-shadow: 0 4px 16px rgba(30,64,175,0.15);
        display: flex; justify-content: space-between; align-items: flex-end;
        gap: 20px; flex-wrap: wrap;
    }
    .head h1 { margin: 0; font-size: 28px; font-weight: 700; }
    .head .sub { font-size: 14px; opacity: 0.9; margin-top: 6px; }
    .head .sub strong { color: #fff; }
    .head .meta { font-size: 13px; opacity: 0.85; margin-top: 4px; }

    .btn-back {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 10px 20px; background: #ffffff; color: #1e40af;
        border: 1px solid #ffffff; border-radius: 8px;
        text-decoration: none; font-size: 14px; font-weight: 600;
        line-height: 1; font-family: inherit;
    }
    .btn-back:hover { background: #e0e7ff; border-color: #e0e7ff; text-decoration: none; color: #1e40af; }

    .card { background: #fff; padding: 30px 40px 40px 40px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

    .toolbar { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 24px; padding-bottom: 20px; border-bottom: 1px solid #e2e8f0; }
    .field-inline { display: flex; flex-direction: column; gap: 5px; }
    .field-inline label { font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 1px; }
    .field-inline input {
        height: 40px; padding: 8px 12px;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 14px; font-family: inherit; background: #fff; color: #0f172a;
    }
    .field-inline input:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 3px rgba(30,64,175,0.1); }

    .action-btn-primary {
        display: inline-flex; align-items: center; gap: 6px;
        height: 40px; padding: 0 18px;
        background: #1e40af; color: #fff;
        border: 1px solid #1e40af; border-radius: 8px;
        font-size: 13px; font-weight: 600; font-family: inherit;
        text-decoration: none; cursor: pointer; white-space: nowrap;
    }
    .action-btn-primary:hover { background: #1e3a8a; border-color: #1e3a8a; text-decoration: none; color: #fff; }
    .btn-soft {
        display: inline-flex; align-items: center; gap: 6px;
        height: 40px; padding: 0 16px;
        background: #f1f5f9; color: #334155;
        border: 1px solid #e2e8f0; border-radius: 8px;
        font-size: 13px; font-weight: 600; font-family: inherit;
        text-decoration: none; cursor: pointer;
    }
    .btn-soft:hover { background: #e2e8f0; text-decoration: none; color: #1e293b; }

    .summary-bar {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(160px,1fr));
        gap: 12px; margin-bottom: 24px;
    }
    .summary-box {
        background: #f8fafc; border: 1px solid #e2e8f0;
        border-radius: 10px; padding: 14px 16px; border-left: 5px solid;
    }
    .summary-box .lbl { font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
    .summary-box .val { font-family: 'SF Mono','Monaco',monospace; font-size: 17px; font-weight: 700; color: #0f172a; }
    .summary-box.s-opening { border-color: #64748b; }
    .summary-box.s-charges { border-color: #1e40af; }
    .summary-box.s-payments { border-color: #059669; }
    .summary-box.s-advances { border-color: #0891b2; }
    .summary-box.s-closing { border-color: #991b1b; background: #fef2f2; }

    table.tbl { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; }
    table.tbl thead th {
        background: #f8fafc; font-size: 11px; color: #64748b; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px;
        padding: 12px 12px; text-align: left;
        border-bottom: 2px solid #e2e8f0; white-space: nowrap;
    }
    table.tbl tbody td { padding: 11px 12px; font-size: 13px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .num { font-family: 'SF Mono','Monaco',monospace; text-align: right; font-variant-numeric: tabular-nums; }

    .row-opening td { background: #f1f5f9; font-weight: 600; }
    .row-closing td { background: #e3f2fd; font-weight: 700; border-top: 2px solid #1e40af; }
    .row-charge td { background: #fff; }
    .row-payment td { background: #f0fdf4; }
    .row-advance td { background: #ecfeff; }

    .tag {
        display: inline-block; padding: 3px 9px; border-radius: 8px;
        font-size: 10px; font-weight: 700; text-transform: uppercase;
    }
    .tag-charge  { background: #dbeafe; color: #1e40af; }
    .tag-payment { background: #d1fae5; color: #065f46; }
    .tag-advance { background: #cffafe; color: #0e7490; }

    .empty-state { padding: 40px; text-align: center; color: #94a3b8; font-style: italic; font-size: 14px; }

    @media print {
        @page { size: A4 portrait; margin: 12mm 10mm; }
        .no-print { display: none !important; }
        body { background: #fff; }
        .head { background: #fff !important; color: #000 !important; border-bottom: 2px solid #000; box-shadow: none; }
        .head h1 { color: #000; font-size: 16pt; }
        .head .sub, .head .meta { color: #333; }
        .head .sub strong { color: #000; }
        .card { box-shadow: none; padding: 0; }
        table.tbl thead th { background: #eee; }
        .summary-box { border: 1px solid #999; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <h1><?= htmlspecialchars($student['name']) ?></h1>
            <div class="sub">
                Reg #<strong><?= htmlspecialchars($student['reg_no']) ?></strong>
                <?php if (!empty($student['father_name'])): ?>
                    &nbsp;·&nbsp; s/o <strong><?= htmlspecialchars($student['father_name']) ?></strong>
                <?php endif; ?>
                <?php if ($classRow && $classRow['title']): ?>
                    &nbsp;·&nbsp; Class <strong><?= htmlspecialchars($classRow['title']) ?></strong>
                <?php endif; ?>
            </div>
            <div class="meta">
                <?php if (!empty($student['mobile'])): ?>
                    📞 <?= htmlspecialchars($student['mobile']) ?>
                    &nbsp;·&nbsp;
                <?php endif; ?>
                Ledger period: <?= htmlspecialchars($from) ?> → <?= htmlspecialchars($to) ?>
            </div>
        </div>
        <a href="gl_fee_defaulters.php" class="btn-back no-print">
            <span class="glyphicon glyphicon-arrow-left"></span> Defaulters
        </a>
    </div>

    <div class="card">

        <form method="get" class="toolbar no-print">
            <input type="hidden" name="id" value="<?= (int)$studentId ?>">
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
            <a href="gl_student_ledger.php?id=<?= (int)$studentId ?>" class="btn-soft">
                <span class="glyphicon glyphicon-refresh"></span> Reset
            </a>
            <button type="button" class="btn-soft" onclick="window.print()">
                <span class="glyphicon glyphicon-print"></span> Print
            </button>
        </form>

        <div class="summary-bar">
            <div class="summary-box s-opening">
                <div class="lbl">Opening Balance</div>
                <div class="val"><?= number_format($openingBalance, 2) ?></div>
            </div>
            <div class="summary-box s-charges">
                <div class="lbl">Total Charges</div>
                <div class="val"><?= number_format($totalCharges, 2) ?></div>
            </div>
            <div class="summary-box s-payments">
                <div class="lbl">Total Payments</div>
                <div class="val"><?= number_format($totalPayments, 2) ?></div>
            </div>
            <div class="summary-box s-advances">
                <div class="lbl">Total Advances</div>
                <div class="val"><?= number_format($totalAdvances, 2) ?></div>
            </div>
            <div class="summary-box s-closing">
                <div class="lbl">Closing Balance</div>
                <div class="val" style="color:<?= $closingBalance > 0.01 ? '#991b1b' : '#047857' ?>;">
                    <?= number_format($closingBalance, 2) ?>
                </div>
            </div>
        </div>

        <table class="tbl">
            <thead>
                <tr>
                    <th style="width:100px;">Date</th>
                    <th style="width:80px;">Type</th>
                    <th style="width:80px;">Ref</th>
                    <th>Description</th>
                    <th class="num" style="width:120px;">Debit (Charge)</th>
                    <th class="num" style="width:120px;">Credit (Paid)</th>
                    <th class="num" style="width:130px;">Balance</th>
                </tr>
            </thead>
            <tbody>
                <tr class="row-opening">
                    <td><?= htmlspecialchars($from) ?></td>
                    <td colspan="4"><strong>Opening Balance</strong></td>
                    <td class="num">—</td>
                    <td class="num"><?= number_format($openingBalance, 2) ?></td>
                </tr>

                <?php if (empty($ledger)): ?>
                    <tr><td colspan="7" class="empty-state">No activity in this period.</td></tr>
                <?php else: foreach ($ledger as $row):
                    $rowCls = $row['type'] === 'CHARGE' ? 'row-charge' : ($row['type'] === 'PAYMENT' ? 'row-payment' : 'row-advance');
                    $tagCls = $row['type'] === 'CHARGE' ? 'tag-charge' : ($row['type'] === 'PAYMENT' ? 'tag-payment' : 'tag-advance');
                ?>
                    <tr class="<?= $rowCls ?>">
                        <td><?= htmlspecialchars($row['date']) ?></td>
                        <td><span class="tag <?= $tagCls ?>"><?= htmlspecialchars($row['type']) ?></span></td>
                        <td><small style="font-family:'SF Mono',monospace;"><?= htmlspecialchars($row['ref']) ?></small></td>
                        <td>
                            <?= htmlspecialchars($row['description']) ?>
                            <?php if (!empty($row['extra'])): ?>
                                <br><small style="color:#94a3b8;"><?= htmlspecialchars($row['extra']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= $row['debit']  > 0 ? number_format($row['debit'],  2) : '' ?></td>
                        <td class="num"><?= $row['credit'] > 0 ? number_format($row['credit'], 2) : '' ?></td>
                        <td class="num"><?= number_format($row['running'], 2) ?></td>
                    </tr>
                <?php endforeach; endif; ?>

                <tr class="row-closing">
                    <td colspan="4"><strong>Closing Balance as of <?= htmlspecialchars($to) ?></strong></td>
                    <td class="num"><?= number_format($totalCharges, 2) ?></td>
                    <td class="num"><?= number_format($totalPayments + $totalAdvances, 2) ?></td>
                    <td class="num"><?= number_format($closingBalance, 2) ?></td>
                </tr>
            </tbody>
        </table>

        <p style="font-size:12px; color:#64748b; margin-top:16px;">
            <strong>Note:</strong>
            <em>Debit</em> = fee charged to the student. <em>Credit</em> = fee paid or advance received.
            Positive closing balance = amount owed by the student.
        </p>

    </div>
</div>

</body>
</html>