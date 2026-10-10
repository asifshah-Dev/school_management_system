<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

/* ---------- Filters ---------- */
$asOf       = $_GET['as_of'] ?? date('Y-m-d');
$classId    = (int)($_GET['class_id'] ?? 0);
$bucket     = $_GET['bucket'] ?? '';

/* ---------- Classes for filter dropdown ---------- */
$classes = $conn->query("
    SELECT id, title FROM classes
   
    ORDER BY title COLLATE utf8mb4_general_ci
")->fetch_all(MYSQLI_ASSOC);

/* ---------- Defaulters query — one row per fee card ---------- */
$where = ["c.status IN ('pending','partial')"];
$params = [$asOf];
$types = "s";

if ($classId > 0) {
    $where[] = "sc.class_id = ?";
    $params[] = $classId;
    $types .= "i";
}

$whereSql = implode(' AND ', $where);

$sql = "
    SELECT
        c.id                          AS fee_card_id,
        c.total_amount,
        c.discount_amount             AS card_discount,
        c.paid_amount                 AS card_paid,
        c.due_date,
        c.month,
        c.status                      AS card_status,
        ft.title                      AS fee_type_title,
        ft.type                       AS fee_type_kind,
        cl.title                      AS class_title,
        cl.id                         AS class_id,
        sr.id                         AS student_id,
        sr.reg_no,
        sr.name                       AS student_name,
        sr.father_name,
        sr.mobile,
        s.id                          AS session_id,
        s.title                       AS session_title,
        DATEDIFF(?, c.due_date)       AS days_overdue,
        COALESCE((
            SELECT SUM(p.paid_amount)
            FROM student_fee_payments p
            WHERE p.fee_card_id = c.id AND p.status = 1
        ), 0)                         AS total_paid,
        COALESCE((
            SELECT SUM(p.discount_amount)
            FROM student_fee_payments p
            WHERE p.fee_card_id = c.id AND p.status = 1
        ), 0)                         AS total_payment_discount
    FROM student_fee_card c
    JOIN student_class sc ON sc.id = c.student_class_id
    JOIN student_registration sr ON sr.id = sc.student_registration_id
    LEFT JOIN classes cl ON cl.id = sc.class_id
    LEFT JOIN fee_types ft ON ft.id = c.fee_type_id
    LEFT JOIN sessions s ON s.id = c.session_id
    WHERE $whereSql
      AND c.due_date IS NOT NULL
      AND c.due_date <= ?
    ORDER BY sr.name ASC, c.due_date ASC
";

$params[] = $asOf;
$types .= "s";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rawRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ---------- Post-process: compute net owed, bucket, group by student ---------- */
$sum = ['current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'total' => 0];
$countByBucket = ['current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0];

$byStudent = [];

foreach ($rawRows as $r) {
    $cardTotal = (float)$r['total_amount'];
    $cardDisc  = (float)$r['card_discount'];
    $totalPaid = (float)$r['total_paid'];
    $payDisc   = (float)$r['total_payment_discount'];
    $netOwed   = round($cardTotal - $cardDisc - $totalPaid - $payDisc, 2);

    if ($netOwed <= 0.005) continue;

    $days = (int)$r['days_overdue'];
    if ($days < 0) $days = 0;

    if ($days <= 30)      $bkt = 'current';
    elseif ($days <= 60)  $bkt = 'd30';
    elseif ($days <= 90)  $bkt = 'd60';
    else                  $bkt = 'd90';

    if ($bucket !== '' && $bucket !== $bkt) continue;

    $sid = (int)$r['student_id'];
    if (!isset($byStudent[$sid])) {
        $byStudent[$sid] = [
            'student_id'       => $sid,
            'student_name'     => $r['student_name'],
            'reg_no'           => $r['reg_no'],
            'father_name'      => $r['father_name'],
            'mobile'           => $r['mobile'],
            'class_title'      => $r['class_title'],
            'class_id'         => (int)$r['class_id'],
            'total_owed'       => 0.0,
            'oldest_days'      => 0,
            'oldest_due_date'  => null,
            'card_count'       => 0,
            'cards'            => [],
        ];
    }

    $byStudent[$sid]['total_owed'] += $netOwed;
    $byStudent[$sid]['card_count']++;

    if ($days > $byStudent[$sid]['oldest_days']) {
        $byStudent[$sid]['oldest_days'] = $days;
        $byStudent[$sid]['oldest_due_date'] = $r['due_date'];
    }

    $byStudent[$sid]['cards'][] = [
        'fee_card_id'  => (int)$r['fee_card_id'],
        'fee_type'     => $r['fee_type_title'] ?: 'Fee',
        'month'        => (int)$r['month'],
        'due_date'     => $r['due_date'],
        'days_overdue' => $days,
        'card_total'   => $cardTotal,
        'card_discount'=> $cardDisc,
        'paid'         => $totalPaid + $payDisc,
        'net_owed'     => $netOwed,
        'bucket'       => $bkt,
        'status'       => $r['card_status'],
    ];

    $sum[$bkt]   += $netOwed;
    $sum['total'] += $netOwed;
    $countByBucket[$bkt]++;
}

usort($byStudent, function($a, $b) {
    return $b['total_owed'] <=> $a['total_owed'];
});

$totalStudents = count($byStudent);
$totalCards = 0;
foreach ($byStudent as $s) $totalCards += $s['card_count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Fee Defaulters — Dar-e-Arqm School</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    * { box-sizing: border-box; }
    body { background: #eef1f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 0; color: #1e293b; }
    .wrap { max-width: 1500px; margin: 30px auto; padding: 0 20px; }

    .head {
        background: linear-gradient(135deg, #991b1b 0%, #b91c1c 100%);
        color: #fff; padding: 30px 40px 26px 40px;
        border-radius: 12px 12px 0 0; box-shadow: 0 4px 16px rgba(153,27,27,0.15);
        display: flex; justify-content: space-between; align-items: flex-end;
        gap: 20px; flex-wrap: wrap;
    }
    .head h1 { margin: 0; font-size: 30px; font-weight: 700; }
    .head .sub { font-size: 14px; opacity: 0.9; margin-top: 6px; }

    .btn-back {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 10px 20px; background: #ffffff; color: #991b1b;
        border: 1px solid #ffffff; border-radius: 8px;
        text-decoration: none; font-size: 14px; font-weight: 600;
        line-height: 1; font-family: inherit;
    }
    .btn-back:hover { background: #fee2e2; border-color: #fee2e2; text-decoration: none; color: #991b1b; }

    .card { background: #fff; padding: 30px 40px 40px 40px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

    .toolbar {
        display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;
        margin-bottom: 24px; padding-bottom: 20px;
        border-bottom: 1px solid #e2e8f0;
    }
    .field-inline { display: flex; flex-direction: column; gap: 5px; }
    .field-inline label {
        font-size: 11px; font-weight: 700; color: #475569;
        text-transform: uppercase; letter-spacing: 1px;
    }
    .field-inline input, .field-inline select {
        height: 40px; padding: 8px 12px;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 14px; font-family: inherit; background: #fff; color: #0f172a;
    }
    .field-inline input:focus, .field-inline select:focus {
        outline: none; border-color: #991b1b;
        box-shadow: 0 0 0 3px rgba(153,27,27,0.1);
    }

    .action-btn-primary {
        display: inline-flex; align-items: center; gap: 6px;
        height: 40px; padding: 0 18px;
        background: #991b1b; color: #fff;
        border: 1px solid #991b1b; border-radius: 8px;
        font-size: 13px; font-weight: 600; font-family: inherit;
        text-decoration: none; cursor: pointer;
        white-space: nowrap;
    }
    .action-btn-primary:hover { background: #7f1d1d; border-color: #7f1d1d; text-decoration: none; color: #fff; }
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
        display: grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr));
        gap: 12px; margin-bottom: 24px;
    }
    .summary-box {
        background: #f8fafc; border: 1px solid #e2e8f0;
        border-radius: 10px; padding: 14px 16px;
        border-left: 5px solid;
    }
    .summary-box .lbl { font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
    .summary-box .val { font-family: 'SF Mono','Monaco',monospace; font-size: 18px; font-weight: 700; color: #0f172a; }
    .summary-box .cnt { font-size: 11px; color: #94a3b8; margin-top: 4px; }
    .summary-box.s-current { border-color: #059669; }
    .summary-box.s-d30     { border-color: #f59e0b; }
    .summary-box.s-d60     { border-color: #ea580c; }
    .summary-box.s-d90     { border-color: #dc2626; }
    .summary-box.s-total   { border-color: #991b1b; background: #fef2f2; }
    .summary-box.s-students { border-color: #1e40af; background: #eff6ff; }

    table.tbl { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; }
    table.tbl thead th {
        background: #f8fafc; font-size: 11px; color: #64748b; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px;
        padding: 12px 12px; text-align: left;
        border-bottom: 2px solid #e2e8f0; white-space: nowrap;
    }
    table.tbl tbody td { padding: 12px; font-size: 13px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    table.tbl tbody tr.student-row:hover td { background: #f8fafc; cursor: pointer; }
    table.tbl tbody tr.student-row.expanded td { background: #f0f5ff; border-bottom: none; }
    table.tbl tbody tr.detail-row td { padding: 0; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
    .num { font-family: 'SF Mono','Monaco',monospace; text-align: right; font-variant-numeric: tabular-nums; }

    .expand-toggle {
        display: inline-flex; align-items: center; justify-content: center;
        width: 24px; height: 24px;
        background: #eff6ff; color: #1e40af;
        border-radius: 6px; font-weight: 800; font-size: 14px;
        margin-right: 8px; transition: transform 0.15s;
    }
    .expand-toggle.open { transform: rotate(90deg); background: #dbeafe; }

    .badge {
        display: inline-block; padding: 3px 9px; border-radius: 8px;
        font-size: 10px; font-weight: 700; text-transform: uppercase;
    }
    .badge.current { background: #d1fae5; color: #065f46; }
    .badge.d30     { background: #fef3c7; color: #92400e; }
    .badge.d60     { background: #ffedd5; color: #9a3412; }
    .badge.d90     { background: #fee2e2; color: #991b1b; }

    .mini-btn {
        display: inline-flex; align-items: center; gap: 3px;
        padding: 6px 11px; border-radius: 6px;
        font-size: 11px; font-weight: 700;
        text-decoration: none; cursor: pointer;
        border: 1px solid; font-family: inherit; line-height: 1;
    }
    .mini-btn-info { background: #eff6ff; color: #1e40af; border-color: #bfdbfe; }
    .mini-btn-info:hover { background: #dbeafe; text-decoration: none; color: #1e40af; }

    .detail-table {
        width: 100%; border-collapse: collapse;
        padding: 0;
        background: #f8fafc;
    }
    .detail-table thead th {
        background: #e2e8f0; color: #475569;
        font-size: 10px; font-weight: 700; text-transform: uppercase;
        letter-spacing: 1px; padding: 8px 14px; text-align: left;
    }
    .detail-table tbody td { padding: 9px 14px; font-size: 12px; color: #334155; border-bottom: 1px solid #e2e8f0; }
    .detail-table tbody tr:last-child td { border-bottom: none; }
    .detail-table .num { text-align: right; font-family: 'SF Mono','Monaco',monospace; }
    .detail-pad { padding: 0 12px 12px 12px !important; }

    .empty-state { padding: 60px 20px; text-align: center; color: #94a3b8; font-style: italic; }
    .empty-state .big { font-size: 48px; margin-bottom: 12px; opacity: 0.4; }
    .empty-state .title { font-size: 18px; color: #475569; font-style: normal; font-weight: 600; margin-bottom: 6px; }

    @media print {
        .no-print { display: none !important; }
        body { background: #fff; }
        .head { background: #fff !important; color: #000 !important; border-bottom: 2px solid #000; box-shadow: none; }
        .head h1 { color: #000; font-size: 18pt; }
        .head .sub { color: #333; }
        .card { box-shadow: none; padding: 0; }
        table.tbl thead th { background: #eee; }
        .summary-box { border: 1px solid #999; }
        .detail-table { display: table !important; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <h1>Fee Defaulters</h1>
            <div class="sub">
                Unpaid and partially-paid fee cards as of <strong><?= htmlspecialchars($asOf) ?></strong>
                &middot; <?= number_format($totalCards) ?> card(s) across <?= $totalStudents ?> student(s)
                &middot; click a row to expand
            </div>
        </div>
        <a href="gl_test_hub.php" class="btn-back no-print">
            <span class="glyphicon glyphicon-arrow-left"></span> Test Hub
        </a>
    </div>

    <div class="card">

        <form method="get" class="toolbar no-print">
            <div class="field-inline">
                <label>As of Date</label>
                <input type="date" name="as_of" value="<?= htmlspecialchars($asOf) ?>">
            </div>

            <div class="field-inline">
                <label>Class</label>
                <select name="class_id">
                    <option value="0">All Classes</option>
                    <?php foreach ($classes as $cl): ?>
                        <option value="<?= (int)$cl['id'] ?>" <?= $classId === (int)$cl['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cl['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field-inline">
                <label>Bucket</label>
                <select name="bucket">
                    <option value=""          <?= $bucket === ''      ? 'selected' : '' ?>>All</option>
                    <option value="current"   <?= $bucket === 'current' ? 'selected' : '' ?>>0–30 days</option>
                    <option value="d30"       <?= $bucket === 'd30'     ? 'selected' : '' ?>>31–60 days</option>
                    <option value="d60"       <?= $bucket === 'd60'     ? 'selected' : '' ?>>61–90 days</option>
                    <option value="d90"       <?= $bucket === 'd90'     ? 'selected' : '' ?>>90+ days</option>
                </select>
            </div>

            <button type="submit" class="action-btn-primary">
                <span class="glyphicon glyphicon-filter"></span> Apply
            </button>
            <a href="gl_fee_defaulters.php" class="btn-soft">
                <span class="glyphicon glyphicon-refresh"></span> Reset
            </a>
            <button type="button" class="btn-soft" onclick="toggleAll()" id="toggleAllBtn">
                <span class="glyphicon glyphicon-resize-vertical"></span> Expand All
            </button>
            <button type="button" class="btn-soft" onclick="window.print()">
                <span class="glyphicon glyphicon-print"></span> Print
            </button>
        </form>

        <div class="summary-bar">
            <div class="summary-box s-current">
                <div class="lbl">0–30 days</div>
                <div class="val"><?= number_format($sum['current'], 2) ?></div>
                <div class="cnt"><?= $countByBucket['current'] ?> card<?= $countByBucket['current'] === 1 ? '' : 's' ?></div>
            </div>
            <div class="summary-box s-d30">
                <div class="lbl">31–60 days</div>
                <div class="val"><?= number_format($sum['d30'], 2) ?></div>
                <div class="cnt"><?= $countByBucket['d30'] ?> card<?= $countByBucket['d30'] === 1 ? '' : 's' ?></div>
            </div>
            <div class="summary-box s-d60">
                <div class="lbl">61–90 days</div>
                <div class="val"><?= number_format($sum['d60'], 2) ?></div>
                <div class="cnt"><?= $countByBucket['d60'] ?> card<?= $countByBucket['d60'] === 1 ? '' : 's' ?></div>
            </div>
            <div class="summary-box s-d90">
                <div class="lbl">90+ days</div>
                <div class="val"><?= number_format($sum['d90'], 2) ?></div>
                <div class="cnt"><?= $countByBucket['d90'] ?> card<?= $countByBucket['d90'] === 1 ? '' : 's' ?></div>
            </div>
            <div class="summary-box s-total">
                <div class="lbl">Total Outstanding</div>
                <div class="val"><?= number_format($sum['total'], 2) ?></div>
                <div class="cnt"><?= $totalCards ?> card<?= $totalCards === 1 ? '' : 's' ?></div>
            </div>
            <div class="summary-box s-students">
                <div class="lbl">Affected Students</div>
                <div class="val"><?= $totalStudents ?></div>
                <div class="cnt">unique students</div>
            </div>
        </div>

        <?php if (empty($byStudent)): ?>
            <div class="empty-state">
                <div class="big">&#10003;</div>
                <div class="title">No defaulters</div>
                No unpaid fee cards match the current filters as of <?= htmlspecialchars($asOf) ?>.
            </div>
        <?php else: ?>
            <table class="tbl" id="defaultersTable">
                <thead>
                    <tr>
                        <th style="width:50px;"></th>
                        <th>Student</th>
                        <th style="width:80px;">Reg #</th>
                        <th style="width:120px;">Class</th>
                        <th style="width:130px;">Oldest</th>
                        <th class="num" style="width:160px;">Total Owed</th>
                        <th class="no-print" style="width:110px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($byStudent as $s):
                        $oldestDays = $s['oldest_days'];
                        if ($oldestDays <= 30)      $oldestCls = 'current';
                        elseif ($oldestDays <= 60)  $oldestCls = 'd30';
                        elseif ($oldestDays <= 90)  $oldestCls = 'd60';
                        else                        $oldestCls = 'd90';
                    ?>
                        <tr class="student-row" data-student-id="<?= (int)$s['student_id'] ?>" onclick="toggleStudent(this)">
                            <td><span class="expand-toggle">▶</span></td>
                            <td>
                                <strong><?= htmlspecialchars($s['student_name']) ?></strong>
                                <?php if (!empty($s['father_name'])): ?>
                                    <br><small style="color:#64748b;">s/o <?= htmlspecialchars($s['father_name']) ?></small>
                                <?php endif; ?>
                                <?php if (!empty($s['mobile'])): ?>
                                    <br><small style="color:#94a3b8; font-family:'SF Mono',monospace;"><?= htmlspecialchars($s['mobile']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><small style="font-family:'SF Mono',monospace;"><?= htmlspecialchars($s['reg_no']) ?></small></td>
                            <td><?= htmlspecialchars($s['class_title'] ?: '—') ?></td>
                            <td>
                                <span class="badge <?= $oldestCls ?>"><?= $oldestDays ?> days</span>
                                <?php if ($s['oldest_due_date']): ?>
                                    <br><small style="color:#94a3b8;"><?= htmlspecialchars($s['oldest_due_date']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="num" style="font-weight:700; color:#991b1b; font-size:15px;">
                                <?= number_format($s['total_owed'], 2) ?>
                            </td>
                            <td class="no-print" onclick="event.stopPropagation();">
                                <a href="gl_student_ledger.php?id=<?= (int)$s['student_id'] ?>" class="mini-btn mini-btn-info">
                                    <span class="glyphicon glyphicon-list-alt"></span> Ledger
                                </a>
                            </td>
                        </tr>

                        <!-- Detail row (hidden by default) -->
                        <tr class="detail-row" id="detail-<?= (int)$s['student_id'] ?>" style="display:none;">
                            <td colspan="7" class="detail-pad">
                                <table class="detail-table">
                                    <thead>
                                        <tr>
                                            <th style="width:60px;">#</th>
                                            <th>Fee Type</th>
                                            <th style="width:110px;">Month</th>
                                            <th style="width:120px;">Due Date</th>
                                            <th class="num" style="width:80px;">Days</th>
                                            <th class="num" style="width:120px;">Card Total</th>
                                            <th class="num" style="width:120px;">Paid</th>
                                            <th class="num" style="width:130px;">Outstanding</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $cIdx = 1; foreach ($s['cards'] as $card):
                                            $monthLabel = '—';
                                            if ($card['month'] > 0 && $card['due_date']) {
                                                $monthLabel = date('M Y', mktime(0,0,0,(int)$card['month'],1,(int)date('Y', strtotime($card['due_date']))));
                                            }
                                            $bCls = $card['bucket'];
                                        ?>
                                            <tr>
                                                <td><?= $cIdx++ ?></td>
                                                <td><strong><?= htmlspecialchars($card['fee_type']) ?></strong></td>
                                                <td><?= htmlspecialchars($monthLabel) ?></td>
                                                <td><?= htmlspecialchars($card['due_date']) ?></td>
                                                <td class="num">
                                                    <span class="badge <?= $bCls ?>"><?= (int)$card['days_overdue'] ?></span>
                                                </td>
                                                <td class="num"><?= number_format($card['card_total'], 2) ?></td>
                                                <td class="num"><?= number_format($card['paid'], 2) ?></td>
                                                <td class="num" style="font-weight:700; color:#991b1b;">
                                                    <?= number_format($card['net_owed'], 2) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    </div>
</div>

<script>
function toggleStudent(row) {
    var sid = row.getAttribute('data-student-id');
    var detail = document.getElementById('detail-' + sid);
    var toggle = row.querySelector('.expand-toggle');

    if (detail.style.display === 'none') {
        detail.style.display = '';
        toggle.classList.add('open');
        toggle.textContent = '▼';
        row.classList.add('expanded');
    } else {
        detail.style.display = 'none';
        toggle.classList.remove('open');
        toggle.textContent = '▶';
        row.classList.remove('expanded');
    }
}

var allExpanded = false;
function toggleAll() {
    var rows = document.querySelectorAll('.student-row');
    var details = document.querySelectorAll('.detail-row');
    var toggles = document.querySelectorAll('.expand-toggle');
    var btn = document.getElementById('toggleAllBtn');

    if (!allExpanded) {
        details.forEach(function(d){ d.style.display = ''; });
        rows.forEach(function(r){ r.classList.add('expanded'); });
        toggles.forEach(function(t){ t.classList.add('open'); t.textContent = '▼'; });
        btn.innerHTML = '<span class="glyphicon glyphicon-resize-vertical"></span> Collapse All';
        allExpanded = true;
    } else {
        details.forEach(function(d){ d.style.display = 'none'; });
        rows.forEach(function(r){ r.classList.remove('expanded'); });
        toggles.forEach(function(t){ t.classList.remove('open'); t.textContent = '▶'; });
        btn.innerHTML = '<span class="glyphicon glyphicon-resize-vertical"></span> Expand All';
        allExpanded = false;
    }
}
</script>

</body>
</html>