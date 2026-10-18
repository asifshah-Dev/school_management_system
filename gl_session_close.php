<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");

$msg = ''; $msg_type = '';

/* AJAX: preview_closure */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'preview_closure') {
    header('Content-Type: application/json');
    $sid = (int)($_POST['session_id'] ?? 0);
    if ($sid <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid session']); exit; }

    $stmt = $conn->prepare("SELECT id, title, from_dated, to_dated FROM sessions WHERE id = ?");
    $stmt->bind_param("i", $sid); $stmt->execute();
    $s = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$s) { echo json_encode(['ok' => false, 'error' => 'Session not found']); exit; }

    $chk = $conn->prepare("SELECT id, closed_at FROM gl_session_closures WHERE session_id = ? LIMIT 1");
    $chk->bind_param("i", $sid); $chk->execute();
    $already = $chk->get_result()->fetch_assoc(); $chk->close();

    $fromD = $s['from_dated']; $toD = $s['to_dated'];

    $stmt = $conn->prepare("SELECT COALESCE(SUM(CASE WHEN a.is_contra = 0 THEN l.credit - l.debit ELSE l.debit - l.credit END), 0) AS net FROM gl_journal_lines l JOIN gl_transactions t ON t.id = l.transaction_id JOIN gl_accounts a ON a.id = l.account_id WHERE a.account_type = 'REVENUE' AND a.is_postable = 1 AND t.entry_date BETWEEN ? AND ?");
    $stmt->bind_param("ss", $fromD, $toD); $stmt->execute();
    $totalRevenue = (float)$stmt->get_result()->fetch_assoc()['net']; $stmt->close();

    $stmt = $conn->prepare("SELECT COALESCE(SUM(l.debit - l.credit), 0) AS net FROM gl_journal_lines l JOIN gl_transactions t ON t.id = l.transaction_id JOIN gl_accounts a ON a.id = l.account_id WHERE a.account_type = 'EXPENSE' AND a.is_postable = 1 AND t.entry_date BETWEEN ? AND ?");
    $stmt->bind_param("ss", $fromD, $toD); $stmt->execute();
    $totalExpenses = (float)$stmt->get_result()->fetch_assoc()['net']; $stmt->close();

    $netProfit = $totalRevenue - $totalExpenses;

    $stmt = $conn->prepare("SELECT COALESCE(SUM(l.debit - l.credit), 0) AS net FROM gl_journal_lines l JOIN gl_transactions t ON t.id = l.transaction_id JOIN gl_accounts a ON a.id = l.account_id WHERE a.account_type = 'ASSET' AND a.is_postable = 1 AND t.entry_date <= ?");
    $stmt->bind_param("s", $toD); $stmt->execute();
    $totalAssets = (float)$stmt->get_result()->fetch_assoc()['net']; $stmt->close();

    $stmt = $conn->prepare("SELECT COALESCE(SUM(l.credit - l.debit), 0) AS net FROM gl_journal_lines l JOIN gl_transactions t ON t.id = l.transaction_id JOIN gl_accounts a ON a.id = l.account_id WHERE a.account_type = 'LIABILITY' AND a.is_postable = 1 AND t.entry_date <= ?");
    $stmt->bind_param("s", $toD); $stmt->execute();
    $totalLiabilities = (float)$stmt->get_result()->fetch_assoc()['net']; $stmt->close();

    $totalEquity = $totalAssets - $totalLiabilities;

    function _pv_bal($conn, $code, $asOf) {
        $a = gl_get_account_by_code($conn, $code);
        return $a ? gl_account_balance_signed($conn, (int)$a['id'], $asOf) : 0.0;
    }
    $cashInHand = _pv_bal($conn, '1010', $toD);
    $cashAtBank = _pv_bal($conn, '1020', $toD);
    $receivable = _pv_bal($conn, '1030', $toD);
    $payable    = -_pv_bal($conn, '2010', $toD);

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM gl_transactions WHERE entry_date BETWEEN ? AND ?");
    $stmt->bind_param("ss", $fromD, $toD); $stmt->execute();
    $txnCount = (int)$stmt->get_result()->fetch_assoc()['c']; $stmt->close();

    echo json_encode([
        'ok' => true, 'session_id' => (int)$s['id'], 'session_title' => $s['title'],
        'from_date' => $fromD, 'to_date' => $toD,
        'total_revenue' => $totalRevenue, 'total_expenses' => $totalExpenses, 'net_profit' => $netProfit,
        'total_assets' => $totalAssets, 'total_liabilities' => $totalLiabilities, 'total_equity' => $totalEquity,
        'cash_in_hand' => $cashInHand, 'cash_at_bank' => $cashAtBank, 'receivable' => $receivable, 'payable' => $payable,
        'txn_count' => $txnCount, 'already_closed' => $already ? true : false, 'closed_at' => $already['closed_at'] ?? null,
    ]);
    exit;
}

/* POST: close_session */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_session'])) {
    $sessionId = (int)($_POST['session_id'] ?? 0);
    $notes     = trim($_POST['notes'] ?? '');

    if ($sessionId <= 0) { $msg = 'Invalid session.'; $msg_type = 'danger'; }
    else {
        $stmt = $conn->prepare("SELECT id, title, from_dated, to_dated FROM sessions WHERE id = ?");
        $stmt->bind_param("i", $sessionId); $stmt->execute();
        $sess = $stmt->get_result()->fetch_assoc(); $stmt->close();

        if (!$sess) { $msg = 'Session not found.'; $msg_type = 'danger'; }
        else {
            $check = $conn->prepare("SELECT id FROM gl_session_closures WHERE session_id = ? LIMIT 1");
            $check->bind_param("i", $sessionId); $check->execute();
            $already = $check->get_result()->fetch_assoc(); $check->close();

            if ($already) {
                $msg = 'This session has already been closed (closure #' . (int)$already['id'] . ').';
                $msg_type = 'danger';
            } else {
                try {
                    $conn->begin_transaction();
                    $fromD = $sess['from_dated']; $toD = $sess['to_dated'];

                    $stmt = $conn->prepare("SELECT COALESCE(SUM(CASE WHEN a.is_contra = 0 THEN l.credit - l.debit ELSE l.debit - l.credit END), 0) AS net FROM gl_journal_lines l JOIN gl_transactions t ON t.id = l.transaction_id JOIN gl_accounts a ON a.id = l.account_id WHERE a.account_type = 'REVENUE' AND a.is_postable = 1 AND t.entry_date BETWEEN ? AND ?");
                    $stmt->bind_param("ss", $fromD, $toD); $stmt->execute();
                    $totalRevenue = (float)$stmt->get_result()->fetch_assoc()['net']; $stmt->close();

                    $stmt = $conn->prepare("SELECT COALESCE(SUM(l.debit - l.credit), 0) AS net FROM gl_journal_lines l JOIN gl_transactions t ON t.id = l.transaction_id JOIN gl_accounts a ON a.id = l.account_id WHERE a.account_type = 'EXPENSE' AND a.is_postable = 1 AND t.entry_date BETWEEN ? AND ?");
                    $stmt->bind_param("ss", $fromD, $toD); $stmt->execute();
                    $totalExpenses = (float)$stmt->get_result()->fetch_assoc()['net']; $stmt->close();

                    $netProfit = $totalRevenue - $totalExpenses;

                    $stmt = $conn->prepare("SELECT COALESCE(SUM(l.debit - l.credit), 0) AS net FROM gl_journal_lines l JOIN gl_transactions t ON t.id = l.transaction_id JOIN gl_accounts a ON a.id = l.account_id WHERE a.account_type = 'ASSET' AND a.is_postable = 1 AND t.entry_date <= ?");
                    $stmt->bind_param("s", $toD); $stmt->execute();
                    $totalAssets = (float)$stmt->get_result()->fetch_assoc()['net']; $stmt->close();

                    $stmt = $conn->prepare("SELECT COALESCE(SUM(l.credit - l.debit), 0) AS net FROM gl_journal_lines l JOIN gl_transactions t ON t.id = l.transaction_id JOIN gl_accounts a ON a.id = l.account_id WHERE a.account_type = 'LIABILITY' AND a.is_postable = 1 AND t.entry_date <= ?");
                    $stmt->bind_param("s", $toD); $stmt->execute();
                    $totalLiabilities = (float)$stmt->get_result()->fetch_assoc()['net']; $stmt->close();

                    $totalEquity = $totalAssets - $totalLiabilities;

                    function _fin_bal($conn, $code, $asOf) {
                        $a = gl_get_account_by_code($conn, $code);
                        return $a ? gl_account_balance_signed($conn, (int)$a['id'], $asOf) : 0.0;
                    }
                    $cashInHand = _fin_bal($conn, '1010', $toD);
                    $cashAtBank = _fin_bal($conn, '1020', $toD);
                    $receivable = _fin_bal($conn, '1030', $toD);
                    $payable    = -_fin_bal($conn, '2010', $toD);

                    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM gl_transactions WHERE entry_date BETWEEN ? AND ?");
                    $stmt->bind_param("ss", $fromD, $toD); $stmt->execute();
                    $txnCount = (int)$stmt->get_result()->fetch_assoc()['c']; $stmt->close();

                    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
                    $stmt = $conn->prepare("INSERT INTO gl_session_closures (session_id, session_title, from_date, to_date, total_revenue, total_expenses, net_profit, total_assets, total_liabilities, total_equity, cash_in_hand, cash_at_bank, receivable, payable, txn_count, closed_by, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("isssdddddddddiis", $sessionId, $sess['title'], $fromD, $toD, $totalRevenue, $totalExpenses, $netProfit, $totalAssets, $totalLiabilities, $totalEquity, $cashInHand, $cashAtBank, $receivable, $payable, $txnCount, $userId, $notes);

                    if (!$stmt->execute()) throw new Exception("Insert failed: " . $stmt->error);
                    $closureId = $conn->insert_id;
                    $stmt->close();

                    $conn->commit();
                    $msg = "Session '{$sess['title']}' closed. Snapshot #$closureId created.";
                    $msg_type = 'success';
                } catch (Exception $e) {
                    $conn->rollback();
                    $msg = 'Close failed: ' . $e->getMessage();
                    $msg_type = 'danger';
                }
            }
        }
    }
}

$sessions = $conn->query("SELECT s.id, s.title, s.from_dated, s.to_dated, s.status, (SELECT COUNT(*) FROM gl_session_closures c WHERE c.session_id = s.id) AS closure_count FROM sessions s ORDER BY s.status ASC, s.id DESC")->fetch_all(MYSQLI_ASSOC);
$closures = $conn->query("SELECT c.*, u.username AS closed_by_name FROM gl_session_closures c LEFT JOIN users u ON u.id = c.closed_by ORDER BY c.closed_at DESC")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Session Snapshot</title>
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
    .info-banner strong { display: block; margin-bottom: 3px; }

    .section-title { font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 1.5px; margin: 18px 0 10px; padding-bottom: 5px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; gap: 6px; }
    .section-title:first-child { margin-top: 0; }
    .section-title i { color: #2563eb; font-size: 10px; }
    .section-title .step-badge { margin-left: auto; font-size: 10px; font-weight: 700; padding: 2px 8px; background: #eff6ff; color: #2563eb; border-radius: 10px; letter-spacing: 0.3px; }

    table.tbl { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; }
    table.tbl thead th { background: #f8fafc; font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; padding: 9px 12px; text-align: left; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
    table.tbl tbody td { padding: 9px 12px; font-size: 12.5px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    table.tbl tbody tr:last-child td { border-bottom: none; }
    table.tbl tbody tr:hover td { background: #fafbfc; }
    .num { font-family: 'SF Mono', monospace; font-variant-numeric: tabular-nums; text-align: right; }

    .pill { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; }
    .pill.active { background: #d1fae5; color: #065f46; }
    .pill.closed { background: #f1f5f9; color: #64748b; }
    .pill.snapshotted { background: #dbeafe; color: #1e40af; }

    .btn-primary-custom, .btn-soft { height: 32px; padding: 0 12px; border-radius: 6px; font-size: 11.5px; font-weight: 600; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; gap: 5px; text-decoration: none; border: 1px solid; transition: all 0.15s; }
    .btn-primary-custom { background: #2563eb; color: #fff; border-color: #2563eb; }
    .btn-primary-custom:hover:not(:disabled) { background: #1d4ed8; color: #fff; text-decoration: none; border-color: #1d4ed8; }
    .btn-primary-custom:disabled { opacity: 0.5; cursor: not-allowed; }
    .btn-soft { background: #f1f5f9; color: #475569; border-color: #e2e8f0; }
    .btn-soft:hover { background: #e2e8f0; color: #1e293b; text-decoration: none; }
    .btn-soft.danger { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
    .btn-soft.danger:hover { background: #fecaca; color: #991b1b; text-decoration: none; }
    .btn-brand { height: 34px; padding: 0 14px; border-radius: 7px; font-size: 12px; font-weight: 600; background: #2563eb; color: #fff; border: none; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; gap: 6px; }
    .btn-brand:hover { background: #1d4ed8; color: #fff; text-decoration: none; }
    .btn-brand:disabled { background: #94a3b8; cursor: not-allowed; }

    .close-form { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 20px; margin-bottom: 20px; }
    .close-form label { display: block; font-size: 10.5px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 4px; }
    .close-form textarea { width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 12.5px; font-family: inherit; background: #fff; color: #0f172a; margin-bottom: 12px; min-height: 56px; resize: vertical; }
    .close-form textarea:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.12); }

    .snapshot-detail { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; }
    .snapshot-detail .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; }
    .snapshot-detail .item .lbl { font-size: 9.5px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 2px; }
    .snapshot-detail .item .val { font-family: 'SF Mono', monospace; font-size: 13.5px; font-weight: 700; }

    .empty-state { padding: 24px; text-align: center; color: #94a3b8; font-size: 12.5px; font-style: italic; }

    @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    .spin { display: inline-block; animation: spin 1s linear infinite; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">
    <div class="head">
        <div>
            <h1><i class="fas fa-camera"></i> Session Snapshot</h1>
            <div class="sub">Freeze a session's financial state as a read-only snapshot</div>
        </div>
        <a href="gl_dashboard.php" class="btn-back"><i class="fas fa-arrow-left"></i> Dashboard</a>
    </div>

    <div class="card">

        <?php if ($msg): ?>
            <div class="info-banner <?= $msg_type === 'success' ? 'ok' : 'warn' ?>"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <div class="info-banner info">
            <strong><i class="fas fa-info-circle"></i> What this page does</strong>
            Records a read-only snapshot of the current financial state. It does <em>not</em> post any journal entry and does <em>not</em> lock the session. Use <a href="gl_year_end_close.php" style="font-weight:700;color:#1e3a8a;">Year-End Close</a> to actually close revenue and expense accounts.
        </div>

        <div class="section-title"><i class="fas fa-list"></i> Sessions</div>

        <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
            <table class="tbl" style="border:none;border-radius:0;">
                <thead>
                    <tr>
                        <th style="width:60px;">ID</th>
                        <th>Title</th>
                        <th style="width:110px;">From</th>
                        <th style="width:110px;">To</th>
                        <th style="width:90px;">Status</th>
                        <th style="width:130px;">Snapshot</th>
                        <?php if (!empty($perms['can_edit'])): ?>
                            <th style="width:180px;" class="no-print">Action</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sessions as $s):
                        $isActive = (int)$s['status'] === 0;
                        $hasClosure = (int)$s['closure_count'] > 0;
                    ?>
                    <tr>
                        <td>#<?= (int)$s['id'] ?></td>
                        <td><strong><?= htmlspecialchars($s['title']) ?></strong></td>
                        <td><?= htmlspecialchars($s['from_dated']) ?></td>
                        <td><?= htmlspecialchars($s['to_dated']) ?></td>
                        <td>
                            <?php if ($isActive): ?>
                                <span class="pill active">Active</span>
                            <?php else: ?>
                                <span class="pill closed">Closed</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($hasClosure): ?>
                                <span class="pill snapshotted"><i class="fas fa-check"></i> Snapshotted</span>
                            <?php else: ?>
                                <span style="color:#cbd5e1;">—</span>
                            <?php endif; ?>
                        </td>
                        <?php if (!empty($perms['can_edit'])): ?>
                            <td class="no-print">
                                <button type="button" class="btn-soft" onclick="openCloseForm(<?= (int)$s['id'] ?>)">
                                    <i class="fas fa-camera"></i> Take Snapshot
                                </button>
                            </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Close panel -->
        <div id="closeFormWrap" style="display:none;">
            <div class="section-title">
                <i class="fas fa-camera"></i> <span id="cf_header">Snapshot</span>
                <span class="step-badge" id="stepBadge">Step 1 of 2 — Preview</span>
            </div>

            <form method="post" id="closeForm">
                <input type="hidden" name="close_session" value="1">
                <input type="hidden" name="session_id" id="cf_session_id" value="">

                <!-- STEP 1 -->
                <div id="stepPreview" class="close-form">
                    <div id="pv_session" style="font-size:13.5px; font-weight:700; margin-bottom: 12px;"></div>

                    <div id="previewLoading" style="padding:16px; text-align:center; color:#64748b; font-size:12.5px;">
                        <i class="fas fa-circle-notch spin"></i> Computing snapshot…
                    </div>
                    <div id="previewError" class="info-banner warn" style="display:none;"></div>

                    <div id="previewContent" style="display:none;">
                        <div class="snapshot-detail" style="margin-bottom: 14px;">
                            <div class="grid">
                                <div class="item"><div class="lbl">Revenue</div><div class="val" id="pv_total_revenue">—</div></div>
                                <div class="item"><div class="lbl">Expenses</div><div class="val" id="pv_total_expenses">—</div></div>
                                <div class="item"><div class="lbl">Net Profit</div><div class="val" id="pv_net_profit">—</div></div>
                                <div class="item"><div class="lbl">Assets</div><div class="val" id="pv_total_assets">—</div></div>
                                <div class="item"><div class="lbl">Liabilities</div><div class="val" id="pv_total_liabilities">—</div></div>
                                <div class="item"><div class="lbl">Equity</div><div class="val" id="pv_total_equity">—</div></div>
                                <div class="item"><div class="lbl">Cash in Hand</div><div class="val" id="pv_cash_in_hand">—</div></div>
                                <div class="item"><div class="lbl">Cash at Bank</div><div class="val" id="pv_cash_at_bank">—</div></div>
                                <div class="item"><div class="lbl">Receivable</div><div class="val" id="pv_receivable">—</div></div>
                                <div class="item"><div class="lbl">Payable</div><div class="val" id="pv_payable">—</div></div>
                                <div class="item"><div class="lbl">Transactions</div><div class="val" id="pv_txn_count">—</div></div>
                            </div>
                        </div>

                        <div id="pv_already_closed" class="info-banner warn" style="display:none;">
                            <strong>Already snapshotted.</strong> A snapshot already exists for this session.
                        </div>

                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <button type="button" class="btn-brand" id="btnGoConfirm" onclick="goToConfirm()">
                                <i class="fas fa-arrow-right"></i> Continue to Confirm
                            </button>
                            <button type="button" class="btn-soft danger" onclick="cancelClose()">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                        </div>
                    </div>
                </div>

                <!-- STEP 2 -->
                <div id="stepConfirm" class="close-form" style="display:none;">
                    <div class="info-banner warn" style="margin-bottom:12px;">
                        <strong><i class="fas fa-exclamation-triangle"></i> Confirm</strong>
                        This writes a permanent snapshot record. It cannot be undone from the UI.
                    </div>

                    <label>Notes (optional)</label>
                    <textarea name="notes" id="cf_notes" placeholder="e.g. Audited snapshot at end of FY 2026-2027"></textarea>

                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <button type="submit" class="btn-brand" style="background:#d97706;">
                            <i class="fas fa-camera"></i> Save Snapshot
                        </button>
                        <button type="button" class="btn-soft" onclick="backToPreview()">
                            <i class="fas fa-arrow-left"></i> Back
                        </button>
                        <button type="button" class="btn-soft danger" onclick="cancelClose()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div class="section-title"><i class="fas fa-history"></i> Snapshot History</div>

        <?php if (empty($closures)): ?>
            <div class="empty-state">No snapshots recorded yet.</div>
        <?php else: ?>
            <?php foreach ($closures as $c): ?>
                <div class="snapshot-detail" style="margin-bottom:10px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; flex-wrap:wrap; gap:6px;">
                        <div>
                            <strong style="font-size:13px;"><?= htmlspecialchars($c['session_title']) ?></strong>
                            <span style="color:#64748b; font-size:11px; margin-left:6px;">(<?= htmlspecialchars($c['from_date']) ?> → <?= htmlspecialchars($c['to_date']) ?>)</span>
                        </div>
                        <div style="font-size:11px; color:#94a3b8;">
                            <?= htmlspecialchars($c['closed_at']) ?><?php if ($c['closed_by_name']): ?> by <?= htmlspecialchars($c['closed_by_name']) ?><?php endif; ?>
                        </div>
                    </div>
                    <div class="grid">
                        <div class="item"><div class="lbl">Revenue</div><div class="val"><?= number_format((float)$c['total_revenue'], 2) ?></div></div>
                        <div class="item"><div class="lbl">Expenses</div><div class="val"><?= number_format((float)$c['total_expenses'], 2) ?></div></div>
                        <div class="item"><div class="lbl">Net Profit</div><div class="val" style="color:<?= (float)$c['net_profit'] < 0 ? '#b91c1c' : '#047857' ?>;"><?= number_format((float)$c['net_profit'], 2) ?></div></div>
                        <div class="item"><div class="lbl">Assets</div><div class="val"><?= number_format((float)$c['total_assets'], 2) ?></div></div>
                        <div class="item"><div class="lbl">Liabilities</div><div class="val"><?= number_format((float)$c['total_liabilities'], 2) ?></div></div>
                        <div class="item"><div class="lbl">Equity</div><div class="val"><?= number_format((float)$c['total_equity'], 2) ?></div></div>
                    </div>
                    <?php if (!empty($c['notes'])): ?>
                        <div style="margin-top:10px; font-size:11.5px; color:#475569; background:#fff; padding:6px 10px; border-radius:5px;">
                            <strong>Notes:</strong> <?= htmlspecialchars($c['notes']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>
</div>

<script>
function openCloseForm(sessionId) {
    document.getElementById('cf_session_id').value = sessionId;
    document.getElementById('stepPreview').style.display = '';
    document.getElementById('stepConfirm').style.display = 'none';
    document.getElementById('previewLoading').style.display = '';
    document.getElementById('previewContent').style.display = 'none';
    document.getElementById('previewError').style.display = 'none';
    document.getElementById('cf_notes').value = '';
    document.getElementById('closeFormWrap').style.display = '';
    document.getElementById('stepBadge').textContent = 'Step 1 of 2 — Preview';

    setTimeout(function(){ document.getElementById('closeFormWrap').scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 50);

    var fd = new FormData();
    fd.append('ajax_action', 'preview_closure');
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
            var fields = ['total_revenue','total_expenses','net_profit','total_assets','total_liabilities','total_equity','cash_in_hand','cash_at_bank','receivable','payable'];
            fields.forEach(function(k){
                var el = document.getElementById('pv_' + k);
                if (el) el.textContent = Number(resp[k] || 0).toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            });
            document.getElementById('pv_txn_count').textContent = resp.txn_count;

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
    document.getElementById('cf_notes').value = '';
}
</script>

</body>
</html>
<?php $conn->close(); ?>