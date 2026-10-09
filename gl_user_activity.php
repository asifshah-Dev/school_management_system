<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

/* ---------- Filters ---------- */
$from   = $_GET['from']   ?? date('Y-m-01');
$to     = $_GET['to']     ?? date('Y-m-d');
$userId = (int)($_GET['user_id'] ?? 0);

/* ---------- Users list ---------- */
$users = $conn->query("
    SELECT id, username FROM users ORDER BY username COLLATE utf8mb4_general_ci
")->fetch_all(MYSQLI_ASSOC);

/* ---------- Transactions posted by this user ---------- */
$txns = [];
if ($userId > 0) {
    $stmt = $conn->prepare("
        SELECT t.id, t.entry_date, t.description, t.reference_type, t.reference_id,
               t.status, t.posted_at, t.reversal_of,
               (SELECT COALESCE(SUM(l.debit), 0) FROM gl_journal_lines l WHERE l.transaction_id = t.id) AS amt,
               (SELECT COUNT(*) FROM gl_journal_lines l WHERE l.transaction_id = t.id) AS line_count
        FROM gl_transactions t
        WHERE t.posted_by = ?
          AND t.entry_date BETWEEN ? AND ?
        ORDER BY t.entry_date DESC, t.id DESC
    ");
    $stmt->bind_param("iss", $userId, $from, $to);
    $stmt->execute();
    $txns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

/* ---------- Vouchers created by this user ---------- */
$vouchers = [];
if ($userId > 0) {
    $stmt = $conn->prepare("
        SELECT id, voucher_type, voucher_number, entry_date, party_name, amount,
               status, created_at, posted_at, gl_transaction_id
        FROM vouchers
        WHERE created_by = ?
          AND entry_date BETWEEN ? AND ?
        ORDER BY entry_date DESC, id DESC
    ");
    $stmt->bind_param("iss", $userId, $from, $to);
    $stmt->execute();
    $vouchers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

/* ---------- Reversals performed by this user ---------- */
$reversals = [];
if ($userId > 0) {
    $stmt = $conn->prepare("
        SELECT t.id, t.entry_date, t.description, t.reversal_of, t.posted_at,
               rt.description AS original_desc,
               rt.entry_date  AS original_date,
               (SELECT COALESCE(SUM(l.debit), 0) FROM gl_journal_lines l WHERE l.transaction_id = t.id) AS amt
        FROM gl_transactions t
        LEFT JOIN gl_transactions rt ON rt.id = t.reversal_of
        WHERE t.posted_by = ?
          AND t.reversal_of IS NOT NULL
          AND t.entry_date BETWEEN ? AND ?
        ORDER BY t.entry_date DESC, t.id DESC
    ");
    $stmt->bind_param("iss", $userId, $from, $to);
    $stmt->execute();
    $reversals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

/* ---------- Summary ---------- */
$totalTxns = count($txns);
$totalAmount = 0.0;
foreach ($txns as $t) {
    if ($t['reversal_of'] === null) $totalAmount += (float)$t['amt'];
}

$selectedUser = null;
if ($userId > 0) {
    foreach ($users as $u) if ((int)$u['id'] === $userId) { $selectedUser = $u; break; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>User Activity — Dar-e-Arqm School</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    * { box-sizing: border-box; }
    body {
        background: #eef1f5;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        margin: 0; padding: 0; color: #1e293b;
    }
    .wrap { max-width: 1300px; margin: 30px auto; padding: 0 20px; }

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

    .btn-new {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 10px 18px;
        background: rgba(255, 255, 255, 0.15);
        color: #fff; border: 1px solid rgba(255, 255, 255, 0.3);
        border-radius: 8px; text-decoration: none;
        font-size: 14px; font-weight: 600;
        transition: all 0.15s;
    }
    .btn-new:hover { background: #fff; color: #1e40af; border-color: #fff; }

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

    .toolbar {
        display: flex; gap: 12px; flex-wrap: wrap;
        align-items: center; margin-bottom: 20px;
        padding-bottom: 20px; border-bottom: 1px solid #e2e8f0;
    }
    .toolbar label {
        font-size: 12px; font-weight: 700; color: #475569;
        text-transform: uppercase; letter-spacing: 1px;
    }
    .toolbar input[type=date],
    .toolbar select {
        height: 40px; padding: 8px 12px;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 14px; font-family: inherit;
        background: #fff;
    }
    .toolbar select { min-width: 200px; }
    .toolbar input:focus, .toolbar select:focus {
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
    }
    .btn-primary-custom:hover { background: #1e3a8a; }
    .btn-soft {
        height: 40px; padding: 0 16px;
        background: #f1f5f9; color: #334155;
        border: 1px solid #e2e8f0; border-radius: 8px;
        font-size: 13px; font-weight: 600;
        cursor: pointer; font-family: inherit;
        display: inline-flex; align-items: center; gap: 6px;
        text-decoration: none;
    }
    .btn-soft:hover { background: #e2e8f0; text-decoration: none; color: #1e293b; }

    /* Summary cards */
    .stats {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 14px; margin-bottom: 24px;
    }
    .stat-box {
        background: #f8fafc; border: 1px solid #e2e8f0;
        border-left: 5px solid #1e40af;
        border-radius: 10px; padding: 14px 18px;
    }
    .stat-box.red    { border-color: #dc2626; }
    .stat-box.green  { border-color: #059669; }
    .stat-box .lbl {
        font-size: 11px; color: #64748b; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px;
    }
    .stat-box .val {
        font-family: 'SF Mono', 'Monaco', monospace;
        font-size: 22px; font-weight: 700;
        font-variant-numeric: tabular-nums;
    }

    table.tbl {
        width: 100%; border-collapse: collapse;
        background: #fff; border-radius: 8px; overflow: hidden;
        border: 1px solid #e2e8f0; margin-bottom: 10px;
    }
    table.tbl thead th {
        background: #f8fafc;
        font-size: 11px; color: #64748b; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px;
        padding: 12px 14px; text-align: left;
        border-bottom: 2px solid #e2e8f0;
    }
    table.tbl tbody td {
        padding: 10px 14px;
        font-size: 13px; color: #1e293b;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }
    table.tbl tbody tr:hover td { background: #f8fafc; }
    table.tbl tbody tr.rev td { background: #fef2f2; }
    .num {
        font-family: 'SF Mono', 'Monaco', monospace;
        font-variant-numeric: tabular-nums;
        text-align: right;
    }
    .pill {
        display: inline-block; padding: 2px 8px;
        border-radius: 10px; font-size: 10px;
        font-weight: 700; letter-spacing: 0.5px;
        text-transform: uppercase;
    }
    .pill.ok  { background: #d1fae5; color: #065f46; }
    .pill.rev { background: #fee2e2; color: #991b1b; }
    .pill.dft { background: #fef3c7; color: #92400e; }
    .pill.can { background: #f1f5f9; color: #64748b; }

    .empty-state {
        padding: 40px; text-align: center;
        color: #94a3b8; font-style: italic;
    }

    @media print {
        .no-print { display: none !important; }
        body { background: #fff; }
        .head { background: #fff; color: #000; border-bottom: 2px solid #000; box-shadow: none; }
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
            <h1>User Activity</h1>
            <div class="sub">
                Every journal entry, voucher, and reversal by a specific user
                <?php if ($selectedUser): ?> — <strong><?= htmlspecialchars($selectedUser['username']) ?></strong><?php endif; ?>
            </div>
        </div>
        <a href="gl_test_hub.php" class="btn-new no-print">
            <span class="glyphicon glyphicon-home"></span> Test Hub
        </a>
    </div>

    <div class="card">

        <form class="toolbar no-print" method="get">
            <label>User</label>
            <select name="user_id" required>
                <option value="">— Select user —</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= $userId === (int)$u['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($u['username']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label>From</label>
            <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
            <label>To</label>
            <input type="date" name="to" value="<?= htmlspecialchars($to) ?>">
            <button type="submit" class="btn-primary-custom">
                <span class="glyphicon glyphicon-filter"></span> Apply
            </button>
            <a href="gl_user_activity.php" class="btn-soft">Reset</a>
            <button type="button" class="btn-soft" onclick="window.print()">
                <span class="glyphicon glyphicon-print"></span> Print
            </button>
        </form>

        <?php if ($userId <= 0): ?>
            <div class="empty-state">Select a user to see their activity.</div>
        <?php else: ?>

            <div class="stats">
                <div class="stat-box">
                    <div class="lbl">Journal Entries Posted</div>
                    <div class="val"><?= $totalTxns ?></div>
                </div>
                <div class="stat-box green">
                    <div class="lbl">Net Amount Posted</div>
                    <div class="val"><?= number_format($totalAmount, 2) ?></div>
                </div>
                <div class="stat-box red">
                    <div class="lbl">Reversals Performed</div>
                    <div class="val"><?= count($reversals) ?></div>
                </div>
                <div class="stat-box">
                    <div class="lbl">Vouchers Created</div>
                    <div class="val"><?= count($vouchers) ?></div>
                </div>
            </div>

            <div class="section-title">Journal Entries Posted</div>
            <?php if (empty($txns)): ?>
                <div class="empty-state" style="padding:24px;">No entries in this period.</div>
            <?php else: ?>
                <table class="tbl">
                    <thead>
                        <tr>
                            <th style="width:60px;">ID</th>
                            <th style="width:100px;">Date</th>
                            <th>Description</th>
                            <th style="width:110px;">Ref Type</th>
                            <th class="num" style="width:110px;">Amount</th>
                            <th style="width:90px;">Status</th>
                            <th class="no-print" style="width:70px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($txns as $t):
                        $isRev = $t['reversal_of'] !== null;
                    ?>
                        <tr class="<?= $isRev ? 'rev' : '' ?>">
                            <td>#<?= (int)$t['id'] ?></td>
                            <td><?= htmlspecialchars($t['entry_date']) ?></td>
                            <td>
                                <?= htmlspecialchars($t['description']) ?>
                                <?php if ($isRev): ?>
                                    <span class="pill rev">Reversal</span>
                                    <small style="color:#94a3b8;"> of #<?= (int)$t['reversal_of'] ?></small>
                                <?php endif; ?>
                            </td>
                            <td><small><?= htmlspecialchars($t['reference_type'] ?: '—') ?></small></td>
                            <td class="num"><?= number_format((float)$t['amt'], 2) ?></td>
                            <td>
                                <?php if ($t['status'] === 'POSTED'): ?>
                                    <span class="pill ok">Posted</span>
                                <?php else: ?>
                                    <span class="pill rev">Reversed</span>
                                <?php endif; ?>
                            </td>
                            <td class="no-print">
                                <a href="gl_transaction_view.php?id=<?= (int)$t['id'] ?>" class="btn-soft" style="height:30px; padding:0 10px; font-size:12px;">
                                    View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <div class="section-title">Vouchers Created</div>
            <?php if (empty($vouchers)): ?>
                <div class="empty-state" style="padding:24px;">No vouchers in this period.</div>
            <?php else: ?>
                <table class="tbl">
                    <thead>
                        <tr>
                            <th style="width:140px;">Voucher #</th>
                            <th style="width:60px;">Type</th>
                            <th style="width:100px;">Date</th>
                            <th>Party</th>
                            <th class="num" style="width:110px;">Amount</th>
                            <th style="width:100px;">Status</th>
                            <th class="no-print" style="width:70px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($vouchers as $v):
                        $cls = $v['status'] === 'POSTED' ? 'ok' : ($v['status'] === 'CANCELLED' ? 'can' : 'dft');
                    ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($v['voucher_number']) ?></strong></td>
                            <td><?= htmlspecialchars($v['voucher_type']) ?></td>
                            <td><?= htmlspecialchars($v['entry_date']) ?></td>
                            <td><?= htmlspecialchars($v['party_name']) ?></td>
                            <td class="num"><?= number_format((float)$v['amount'], 2) ?></td>
                            <td><span class="pill <?= $cls ?>"><?= htmlspecialchars($v['status']) ?></span></td>
                            <td class="no-print">
                                <a href="gl_voucher_view.php?id=<?= (int)$v['id'] ?>" class="btn-soft" style="height:30px; padding:0 10px; font-size:12px;">
                                    View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <div class="section-title">Reversals Performed</div>
            <?php if (empty($reversals)): ?>
                <div class="empty-state" style="padding:24px;">No reversals in this period.</div>
            <?php else: ?>
                <table class="tbl">
                    <thead>
                        <tr>
                            <th style="width:60px;">Rev #</th>
                            <th style="width:100px;">Date</th>
                            <th>Original Entry</th>
                            <th class="num" style="width:110px;">Amount</th>
                            <th class="no-print" style="width:70px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($reversals as $r): ?>
                        <tr class="rev">
                            <td>#<?= (int)$r['id'] ?></td>
                            <td><?= htmlspecialchars($r['entry_date']) ?></td>
                            <td>
                                <?= htmlspecialchars($r['original_desc'] ?: $r['description']) ?>
                                <br><small style="color:#94a3b8;">Original #<?= (int)$r['reversal_of'] ?> · <?= htmlspecialchars($r['original_date'] ?? '') ?></small>
                            </td>
                            <td class="num"><?= number_format((float)$r['amt'], 2) ?></td>
                            <td class="no-print">
                                <a href="gl_transaction_view.php?id=<?= (int)$r['id'] ?>" class="btn-soft" style="height:30px; padding:0 10px; font-size:12px;">
                                    View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        <?php endif; ?>

    </div>
</div>

</body>
</html>