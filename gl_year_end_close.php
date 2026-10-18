<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
$msg = ''; $msg_type = '';

/* AJAX: preview_close */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'preview_close') {
    header('Content-Type: application/json');
    $sid = (int)($_POST['session_id'] ?? 0);
    if ($sid <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid session']); exit; }

    $stmt = $conn->prepare("SELECT id, title, from_dated, to_dated FROM sessions WHERE id = ?");
    $stmt->bind_param("i", $sid); $stmt->execute();
    $s = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$s) { echo json_encode(['ok' => false, 'error' => 'Session not found']); exit; }

    $chk = $conn->prepare("SELECT id, closed_at FROM gl_year_end_closures WHERE session_id = ? LIMIT 1");
    $chk->bind_param("i", $sid); $chk->execute();
    $already = $chk->get_result()->fetch_assoc(); $chk->close();

    $fromD = $s['from_dated']; $toD = $s['to_dated'];

    $stmt = $conn->prepare("SELECT a.id, a.code, a.name, a.account_type, a.is_contra, COALESCE(SUM(l.debit), 0) AS total_debit, COALESCE(SUM(l.credit), 0) AS total_credit FROM gl_accounts a LEFT JOIN gl_journal_lines l ON l.account_id = a.id LEFT JOIN gl_transactions t ON t.id = l.transaction_id AND t.entry_date BETWEEN ? AND ? WHERE a.is_postable = 1 AND a.status = 1 AND a.account_type IN ('REVENUE','EXPENSE') GROUP BY a.id, a.code, a.name, a.account_type, a.is_contra ORDER BY a.account_type DESC, a.code");
    $stmt->bind_param("ss", $fromD, $toD); $stmt->execute();
    $accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

    $closeEntries = []; $totalRevenue = 0.0; $totalExpenses = 0.0;
    foreach ($accounts as $a) {
        $net = (float)$a['total_debit'] - (float)$a['total_credit'];
        $isContra = (int)$a['is_contra'] === 1;
        if ($a['account_type'] === 'REVENUE') {
            $totalRevenue += $isContra ? -$net : $net;
            if (abs($net) < 0.005) continue;
            if (!$isContra && $net < 0) $closeEntries[] = ['account_id'=>(int)$a['id'],'code'=>$a['code'],'name'=>$a['name'],'type'=>'REVENUE','debit'=>abs($net),'credit'=>0,'side'=>'Dr'];
            elseif ($isContra && $net > 0) $closeEntries[] = ['account_id'=>(int)$a['id'],'code'=>$a['code'],'name'=>$a['name'],'type'=>'REVENUE (contra)','debit'=>0,'credit'=>abs($net),'side'=>'Cr'];
        } else {
            $totalExpenses += $isContra ? -$net : $net;
            if (abs($net) < 0.005) continue;
            if (!$isContra && $net > 0) $closeEntries[] = ['account_id'=>(int)$a['id'],'code'=>$a['code'],'name'=>$a['name'],'type'=>'EXPENSE','debit'=>0,'credit'=>abs($net),'side'=>'Cr'];
            elseif ($isContra && $net < 0) $closeEntries[] = ['account_id'=>(int)$a['id'],'code'=>$a['code'],'name'=>$a['name'],'type'=>'EXPENSE (contra)','debit'=>abs($net),'credit'=>0,'side'=>'Dr'];
        }
    }
    $netProfit = $totalRevenue - $totalExpenses;

    $totalDr = 0.0; $totalCr = 0.0;
    foreach ($closeEntries as $e) { $totalDr += $e['debit']; $totalCr += $e['credit']; }
    $reAdjustment = round($totalDr - $totalCr, 2);

    echo json_encode([
        'ok' => true, 'session_id' => (int)$s['id'], 'session_title' => $s['title'],
        'from_date' => $fromD, 'to_date' => $toD,
        'total_revenue' => $totalRevenue, 'total_expenses' => $totalExpenses, 'net_profit' => $netProfit,
        'entries' => $closeEntries, 'total_debit' => $totalDr, 'total_credit' => $totalCr, 're_adjustment' => $reAdjustment,
        'already_closed' => $already ? true : false, 'closed_at' => $already['closed_at'] ?? null,
    ]);
    exit;
}

/* POST: close_session */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close_session') {
    $sid = (int)($_POST['session_id'] ?? 0);
    $entryDate = $_POST['entry_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');

    if ($sid <= 0) { $msg = 'Invalid session.'; $msg_type = 'danger'; }
    else {
        $stmt = $conn->prepare("SELECT id, title, from_dated, to_dated FROM sessions WHERE id = ?");
        $stmt->bind_param("i", $sid); $stmt->execute();
        $s = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$s) { $msg = 'Session not found.'; $msg_type = 'danger'; }
        else {
            $chk = $conn->prepare("SELECT id FROM gl_year_end_closures WHERE session_id = ? LIMIT 1");
            $chk->bind_param("i", $sid); $chk->execute();
            $already = $chk->get_result()->fetch_assoc(); $chk->close();

            if ($already) { $msg = 'This session has already been closed.'; $msg_type = 'danger'; }
            else {
                try {
                    $conn->begin_transaction();
                    $fromD = $s['from_dated']; $toD = $s['to_dated'];

                    $stmt = $conn->prepare("SELECT a.id, a.code, a.name, a.account_type, a.is_contra, COALESCE(SUM(l.debit), 0) AS total_debit, COALESCE(SUM(l.credit), 0) AS total_credit FROM gl_accounts a LEFT JOIN gl_journal_lines l ON l.account_id = a.id LEFT JOIN gl_transactions t ON t.id = l.transaction_id AND t.entry_date BETWEEN ? AND ? WHERE a.is_postable = 1 AND a.status = 1 AND a.account_type IN ('REVENUE','EXPENSE') GROUP BY a.id, a.code, a.name, a.account_type, a.is_contra");
                    $stmt->bind_param("ss", $fromD, $toD); $stmt->execute();
                    $accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

                    $lines = []; $totalRevenue = 0.0; $totalExpenses = 0.0; $revenueCount = 0; $expenseCount = 0;
                    foreach ($accounts as $a) {
                        $net = (float)$a['total_debit'] - (float)$a['total_credit'];
                        $isContra = (int)$a['is_contra'] === 1;
                        if ($a['account_type'] === 'REVENUE') {
                            $totalRevenue += $isContra ? -$net : $net;
                            if (abs($net) < 0.005) continue;
                            if (!$isContra && $net < 0) { $lines[] = ['account_id'=>(int)$a['id'],'debit'=>abs($net),'credit'=>0,'memo'=>'Year-end close — '.$a['code']]; $revenueCount++; }
                            elseif ($isContra && $net > 0) { $lines[] = ['account_id'=>(int)$a['id'],'debit'=>0,'credit'=>abs($net),'memo'=>'Year-end close — '.$a['code']]; $revenueCount++; }
                        } else {
                            $totalExpenses += $isContra ? -$net : $net;
                            if (abs($net) < 0.005) continue;
                            if (!$isContra && $net > 0) { $lines[] = ['account_id'=>(int)$a['id'],'debit'=>0,'credit'=>abs($net),'memo'=>'Year-end close — '.$a['code']]; $expenseCount++; }
                            elseif ($isContra && $net < 0) { $lines[] = ['account_id'=>(int)$a['id'],'debit'=>abs($net),'credit'=>0,'memo'=>'Year-end close — '.$a['code']]; $expenseCount++; }
                        }
                    }
                    $netProfit = $totalRevenue - $totalExpenses;

                    $reAccount = gl_get_account_by_code($conn, '3100');
                    if (!$reAccount) throw new Exception('Retained Earnings account (3100) not found.');

                    $totalDr = 0.0; $totalCr = 0.0;
                    foreach ($lines as $l) { $totalDr += $l['debit']; $totalCr += $l['credit']; }
                    $reAdjust = round($totalDr - $totalCr, 2);
                    if (abs($reAdjust) > 0.005) {
                        if ($reAdjust > 0) $lines[] = ['account_id'=>(int)$reAccount['id'],'debit'=>0,'credit'=>$reAdjust,'memo'=>'Year-end close — net result'];
                        else $lines[] = ['account_id'=>(int)$reAccount['id'],'debit'=>abs($reAdjust),'credit'=>0,'memo'=>'Year-end close — net result'];
                    }

                    if (empty($lines)) throw new Exception('Nothing to close — all revenue and expense accounts are already zero.');

                    $sessionId = gl_current_session_id($conn) ?: $sid;
                    $glErr = null;
                    $txnId = post_journal_entry($conn, [
                        'entry_date' => $entryDate, 'session_id' => $sessionId,
                        'description' => 'YEAR-END CLOSE — ' . $s['title'],
                        'ref_type' => 'year_end_close', 'ref_id' => $sid, 'posted_by' => $userId,
                        'idempotency' => 'year-end-close-' . $sid, 'lines' => $lines,
                    ], $glErr);
                    if ($txnId <= 0) throw new Exception('Close posting failed: ' . ($glErr ?: 'unknown'));

                    $ins = $conn->prepare("INSERT INTO gl_year_end_closures (session_id, session_title, from_date, to_date, total_revenue, total_expenses, net_profit, close_txn_id, close_entry_date, revenue_accounts, expense_accounts, closed_by, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins->bind_param("isssddddisiiis", $sid, $s['title'], $fromD, $toD, $totalRevenue, $totalExpenses, $netProfit, $txnId, $entryDate, $revenueCount, $expenseCount, $userId, $notes);
                    if (!$ins->execute()) throw new Exception("Closure log insert failed: " . $ins->error);
                    $ins->close();

                    $upd = $conn->prepare("UPDATE sessions SET status = 1 WHERE id = ?");
                    $upd->bind_param("i", $sid); $upd->execute(); $upd->close();

                    $conn->commit();
                    $msg = "Year-end close posted. TXN #$txnId — Revenue: " . number_format($totalRevenue, 2) . ", Expenses: " . number_format($totalExpenses, 2) . ", Net: " . number_format($netProfit, 2);
                    $msg_type = 'success';
                } catch (Exception $e) {
                    $conn->rollback();
                    $msg = 'Year-end close failed: ' . $e->getMessage();
                    $msg_type = 'danger';
                }
            }
        }
    }
}

$sessions = $conn->query("SELECT s.id, s.title, s.from_dated, s.to_dated, s.status, c.id AS closure_id, c.net_profit AS closed_profit, c.closed_at FROM sessions s LEFT JOIN gl_year_end_closures c ON c.session_id = s.id ORDER BY s.status ASC, s.id DESC")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Year-End Close</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    body { background: #f5f7fa; font-family: 'Inter', sans-serif; color: #1e293b; padding-bottom: 40px; }
    .wrap { max-width: 1200px; margin: 24px auto; padding: 0 20px; }

    .head { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: #fff; padding: 20px 24px; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; }
    .head h1 { margin: 0; font-size: 19px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .head .sub { font-size: 12px; opacity: 0.88; margin-top: 3px; }
    .btn-back { padding: 8px 14px; border-radius: 7px; font-size: 12px; font-weight: 700; background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.3); text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
    .btn-back:hover { background: rgba(255,255,255,0.25); color: #fff; text-decoration: none; }

    .card { background: #fff; padding: 20px 22px 22px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

    .info-banner { padding: 10px 14px; border-radius: 8px; font-size: 12.5px; margin-bottom: 14px; border-left: 4px solid; }
    .info-banner.info { background: #dbeafe; border-color: #2563eb; color: #1e3a8a; }
    .info-banner.warn { background: #fef3c7; border-color: #d97706; color: #92400e; }
    .info-banner.ok { background: #d1fae5; border-color: #059669; color: #065f46; }
    .info-banner.danger { background: #fee2e2; border-color: #dc2626; color: #991b1b; }
    .info-banner strong { display: block; margin-bottom: 3px; }

    .section-title { font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 1.5px; margin: 18px 0 10px; padding-bottom: 5px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; gap: 6px; }
    .section-title:first-child { margin-top: 0; }
    .section-title i { color: #2563eb; font-size: 10px; }
    .section-title .step-badge { margin-left: auto; font-size: 10px; font-weight: 700; padding: 2px 8px; background: #eff6ff; color: #2563eb; border-radius: 10px; }

    table.tbl { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; }
    table.tbl thead th { background: #f8fafc; font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; padding: 9px 12px; text-align: left; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
    table.tbl tbody td { padding: 9px 12px; font-size: 12.5px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    table.tbl tbody tr:last-child td { border-bottom: none; }
    table.tbl tbody tr:hover td { background: #fafbfc; }
    .num { font-family: 'SF Mono', monospace; text-align: right; font-variant-numeric: tabular-nums; }

    .pill { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; }
    .pill.active { background: #d1fae5; color: #065f46; }
    .pill.closed { background: #e0e7ff; color: #3730a3; }

    .btn-brand { height: 34px; padding: 0 14px; border-radius: 7px; font-size: 12px; font-weight: 600; background: #2563eb; color: #fff; border: none; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; gap: 6px; }
    .btn-brand:hover { background: #1d4ed8; color: #fff; text-decoration: none; }
    .btn-brand:disabled { background: #94a3b8; cursor: not-allowed; }
    .btn-warn { height: 32px; padding: 0 12px; border-radius: 6px; font-size: 11.5px; font-weight: 600; background: #dc2626; color: #fff; border: 1px solid #dc2626; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; gap: 5px; }
    .btn-warn:hover:not(:disabled) { background: #b91c1c; border-color: #b91c1c; color: #fff; }
    .btn-warn:disabled { opacity: 0.5; cursor: not-allowed; }
    .btn-soft { height: 32px; padding: 0 12px; border-radius: 6px; font-size: 11.5px; font-weight: 600; background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; gap: 5px; }
    .btn-soft:hover { background: #e2e8f0; color: #1e293b; text-decoration: none; }

    .close-form { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 20px; margin-bottom: 20px; }
    .field { margin-bottom: 12px; }
    .field label { display: block; font-size: 10.5px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 4px; }
    .field input, .field textarea { width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 12.5px; font-family: inherit; background: #fff; color: #0f172a; }
    .field input { height: 34px; }
    .field textarea { min-height: 56px; resize: vertical; }
    .field input:focus, .field textarea:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.12); }

    .preview-box { background: #fffbeb; border: 1px solid #fde68a; border-left: 4px solid #f59e0b; padding: 12px 16px; border-radius: 8px; margin: 14px 0; }
    .preview-box .lbl { font-size: 9.5px; color: #78350f; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
    .preview-box .row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 12.5px; }
    .preview-box .row.total { border-top: 1px solid #fbbf24; padding-top: 6px; margin-top: 4px; font-weight: 700; }
    .preview-box .big { font-family: 'SF Mono', monospace; font-size: 14px; font-weight: 700; }

    .entries-table { max-height: 350px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px; margin: 14px 0; }
    .entries-table table.tbl { border: none; border-radius: 0; }
    .entries-table table.tbl thead th { position: sticky; top: 0; z-index: 1; }

    @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    .spin { display: inline-block; animation: spin 1s linear infinite; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">
    <div class="head">
        <div>
            <h1><i class="fas fa-lock"></i> Year-End Close</h1>
            <div class="sub">Posts the closing entry — zeroes out Revenue &amp; Expenses into Retained Earnings, then locks the session</div>
        </div>
        <a href="gl_dashboard.php" class="btn-back"><i class="fas fa-arrow-left"></i> Dashboard</a>
    </div>

    <div class="card">

        <?php if ($msg): ?>
            <div class="info-banner <?= $msg_type === 'success' ? 'ok' : 'danger' ?>"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <div class="info-banner info">
            <strong><i class="fas fa-info-circle"></i> Correct order of operations</strong>
            1) <strong>Close</strong> the previous session here → 2) <strong>Create/activate</strong> the new session → 3) <strong>Import opening balance</strong> from <a href="gl_opening_balance.php" style="font-weight:700;color:#1e3a8a;">Opening Balance</a>.
        </div>

        <div class="section-title"><i class="fas fa-list"></i> Sessions</div>

        <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
            <table class="tbl" style="border:none;border-radius:0;">
                <thead>
                    <tr>
                        <th style="width:60px;">ID</th>
                        <th>Title</th>
                        <th style="width:180px;">Period</th>
                        <th style="width:90px;">Status</th>
                        <th style="width:150px;">Closed?</th>
                        <?php if (!empty($perms['can_edit'])): ?>
                            <th style="width:140px;">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sessions as $s):
                        $isActive = (int)$s['status'] === 0;
                        $isClosed = !empty($s['closure_id']);
                    ?>
                    <tr>
                        <td>#<?= (int)$s['id'] ?></td>
                        <td><strong><?= htmlspecialchars($s['title']) ?></strong></td>
                        <td><?= htmlspecialchars($s['from_dated']) ?> → <?= htmlspecialchars($s['to_dated']) ?></td>
                        <td>
                            <?php if ($isActive): ?>
                                <span class="pill active">Active</span>
                            <?php else: ?>
                                <span style="color:#94a3b8;font-size:11.5px;">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isClosed): ?>
                                <span class="pill closed"><i class="fas fa-check"></i> <?= number_format((float)$s['closed_profit'], 2) ?></span>
                            <?php else: ?>
                                <span style="color:#cbd5e1;">—</span>
                            <?php endif; ?>
                        </td>
                        <?php if (!empty($perms['can_edit'])): ?>
                            <td>
                                <?php if ($isClosed): ?>
                                    <span style="font-size:11px; color:#64748b;"><?= htmlspecialchars($s['closed_at']) ?></span>
                                <?php else: ?>
                                    <button type="button" class="btn-warn" onclick="loadPreview(<?= (int)$s['id'] ?>)">
                                        <i class="fas fa-lock"></i> Close
                                    </button>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="closeFormWrap" style="display:none;">
            <div class="section-title">
                <i class="fas fa-lock"></i> <span id="cf_header">Close Session</span>
                <span class="step-badge" id="stepBadge">Step 1 of 2 — Preview</span>
            </div>

            <form method="post" id="closeForm">
                <input type="hidden" name="action" value="close_session">
                <input type="hidden" name="session_id" id="cf_session_id" value="">

                <div id="stepPreview" class="close-form">
                    <div id="pv_session" style="font-size:13.5px; font-weight:700; margin-bottom:12px;"></div>

                    <div id="previewLoading" style="padding:16px; text-align:center; color:#64748b; font-size:12.5px;">
                        <i class="fas fa-circle-notch spin"></i> Computing…
                    </div>
                    <div id="previewError" class="info-banner danger" style="display:none;"></div>

                    <div id="previewContent" style="display:none;">
                        <div class="preview-box">
                            <div class="lbl">Summary</div>
                            <div class="row"><span>Total Revenue</span><span class="big" id="pv_revenue">—</span></div>
                            <div class="row"><span>Total Expenses</span><span class="big" id="pv_expenses">—</span></div>
                            <div class="row total"><span>Net Profit</span><span class="big" id="pv_profit">—</span></div>
                        </div>

                        <div style="font-size:10px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:1.2px; margin: 14px 0 8px;">
                            Closing Entries
                        </div>
                        <div class="entries-table">
                            <table class="tbl">
                                <thead>
                                    <tr>
                                        <th style="width:80px;">Code</th>
                                        <th>Account</th>
                                        <th style="width:110px;">Type</th>
                                        <th class="num" style="width:100px;">Dr</th>
                                        <th class="num" style="width:100px;">Cr</th>
                                    </tr>
                                </thead>
                                <tbody id="pv_entries"></tbody>
                            </table>
                        </div>

                        <div id="pv_already_closed" class="info-banner warn" style="display:none;">
                            <strong>Already closed.</strong> This session has been closed.
                        </div>

                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <button type="button" class="btn-brand" id="btnGoConfirm" onclick="goToConfirm()">
                                <i class="fas fa-arrow-right"></i> Continue to Confirm
                            </button>
                            <button type="button" class="btn-soft" onclick="cancelClose()">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                        </div>
                    </div>
                </div>

                <div id="stepConfirm" class="close-form" style="display:none;">
                    <div class="info-banner warn" style="margin-bottom:12px;">
                        <strong><i class="fas fa-exclamation-triangle"></i> Confirm Year-End Close</strong>
                        This will: post a closing journal entry zeroing every Revenue/Expense account; transfer net result to Retained Earnings (3100); lock the session; log the closure. Cannot be undone from the UI.
                    </div>

                    <div class="field">
                        <label>Closing Entry Date</label>
                        <input type="date" name="entry_date" id="cf_entry_date" required>
                        <div style="font-size:10.5px; color:#64748b; margin-top:3px;">Usually the last day of the session period.</div>
                    </div>

                    <div class="field">
                        <label>Notes (optional)</label>
                        <textarea name="notes" placeholder="e.g. FY 2026-2027 close, audited"></textarea>
                    </div>

                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <button type="submit" class="btn-warn">
                            <i class="fas fa-lock"></i> Confirm Close
                        </button>
                        <button type="button" class="btn-soft" onclick="backToPreview()">
                            <i class="fas fa-arrow-left"></i> Back
                        </button>
                        <button type="button" class="btn-soft" onclick="cancelClose()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </div>
            </form>
        </div>

    </div>
</div>

<script>
function loadPreview(sessionId) {
    document.getElementById('cf_session_id').value = sessionId;
    document.getElementById('stepPreview').style.display = '';
    document.getElementById('stepConfirm').style.display = 'none';
    document.getElementById('previewLoading').style.display = '';
    document.getElementById('previewContent').style.display = 'none';
    document.getElementById('previewError').style.display = 'none';
    document.getElementById('closeFormWrap').style.display = '';
    document.getElementById('stepBadge').textContent = 'Step 1 of 2 — Preview';

    setTimeout(function(){ document.getElementById('closeFormWrap').scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 50);

    var fd = new FormData();
    fd.append('ajax_action', 'preview_close');
    fd.append('session_id', sessionId);

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(resp){
            document.getElementById('previewLoading').style.display = 'none';
            if (!resp.ok) {
                document.getElementById('previewError').textContent = resp.error || 'Preview failed.';
                document.getElementById('previewError').style.display = '';
                return;
            }
            document.getElementById('pv_session').textContent = '#' + resp.session_id + ' — ' + resp.session_title + ' (' + resp.from_date + ' → ' + resp.to_date + ')';
            document.getElementById('pv_revenue').textContent = Number(resp.total_revenue).toLocaleString('en-PK', {minimumFractionDigits:2, maximumFractionDigits:2});
            document.getElementById('pv_expenses').textContent = Number(resp.total_expenses).toLocaleString('en-PK', {minimumFractionDigits:2, maximumFractionDigits:2});
            document.getElementById('pv_profit').textContent = Number(resp.net_profit).toLocaleString('en-PK', {minimumFractionDigits:2, maximumFractionDigits:2});
            document.getElementById('pv_profit').style.color = resp.net_profit < 0 ? '#b91c1c' : '#047857';

            var tbody = document.getElementById('pv_entries');
            tbody.innerHTML = '';
            resp.entries.forEach(function(e){
                var tr = document.createElement('tr');
                tr.innerHTML =
                    '<td><code style="font-family:\'SF Mono\',monospace;font-size:11px;">' + e.code + '</code></td>' +
                    '<td>' + e.name + '</td>' +
                    '<td><span style="font-size:9px;padding:2px 6px;border-radius:5px;background:' +
                        (e.type.indexOf('REVENUE') === 0 ? '#dbeafe;color:#1e40af' : '#fee2e2;color:#991b1b') + '">' +
                        e.type + '</span></td>' +
                    '<td class="num">' + (e.debit > 0 ? Number(e.debit).toLocaleString('en-PK', {minimumFractionDigits:2}) : '') + '</td>' +
                    '<td class="num">' + (e.credit > 0 ? Number(e.credit).toLocaleString('en-PK', {minimumFractionDigits:2}) : '') + '</td>';
                tbody.appendChild(tr);
            });

            var tr = document.createElement('tr');
            tr.style.background = '#f0f5ff';
            tr.style.fontWeight = '700';
            var reDr = resp.re_adjustment < 0 ? Math.abs(resp.re_adjustment) : 0;
            var reCr = resp.re_adjustment > 0 ? resp.re_adjustment : 0;
            tr.innerHTML =
                '<td><code style="font-family:\'SF Mono\',monospace;font-size:11px;">3100</code></td>' +
                '<td>Retained Earnings <em style="color:#64748b;font-size:11px;">(balancing)</em></td>' +
                '<td><span style="font-size:9px;padding:2px 6px;border-radius:5px;background:#e0e7ff;color:#3730a3;">EQUITY</span></td>' +
                '<td class="num">' + (reDr > 0 ? Number(reDr).toLocaleString('en-PK', {minimumFractionDigits:2}) : '') + '</td>' +
                '<td class="num">' + (reCr > 0 ? Number(reCr).toLocaleString('en-PK', {minimumFractionDigits:2}) : '') + '</td>';
            tbody.appendChild(tr);

            document.getElementById('cf_entry_date').value = resp.to_date;

            var btnConfirm = document.getElementById('btnGoConfirm');
            var alreadyBox = document.getElementById('pv_already_closed');
            if (resp.already_closed) { alreadyBox.style.display = ''; btnConfirm.disabled = true; }
            else { alreadyBox.style.display = 'none'; btnConfirm.disabled = false; }
            document.getElementById('previewContent').style.display = '';
        })
        .catch(function(err){
            document.getElementById('previewLoading').style.display = 'none';
            document.getElementById('previewError').textContent = 'Network error: ' + err;
            document.getElementById('previewError').style.display = '';
        });
}

function goToConfirm() {
    document.getElementById('stepPreview').style.display = 'none';
    document.getElementById('stepConfirm').style.display = '';
    document.getElementById('stepBadge').textContent = 'Step 2 of 2 — Confirm';
}
function backToPreview() {
    document.getElementById('stepConfirm').style.display = 'none';
    document.getElementById('stepPreview').style.display = '';
    document.getElementById('stepBadge').textContent = 'Step 1 of 2 — Preview';
}
function cancelClose() {
    if (!confirm('Cancel? Nothing will be saved.')) return;
    document.getElementById('closeFormWrap').style.display = 'none';
    document.getElementById('cf_session_id').value = '';
}
</script>

</body>
</html>
<?php $conn->close(); ?>