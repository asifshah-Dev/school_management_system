<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

$msg = ''; $msg_type = '';

/* ============================================================
   AJAX: preview a session's closure numbers (read-only, no write)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'preview_closure') {
    header('Content-Type: application/json');

    $sid = (int)($_POST['session_id'] ?? 0);
    if ($sid <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid session']); exit; }

    $stmt = $conn->prepare("SELECT id, title, from_dated, to_dated FROM sessions WHERE id = ?");
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    $s = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$s) { echo json_encode(['ok' => false, 'error' => 'Session not found']); exit; }

    // Already closed?
    $chk = $conn->prepare("SELECT id, closed_at FROM gl_session_closures WHERE session_id = ? LIMIT 1");
    $chk->bind_param("i", $sid);
    $chk->execute();
    $already = $chk->get_result()->fetch_assoc();
    $chk->close();

    $fromD = $s['from_dated'];
    $toD   = $s['to_dated'];

    // ---- Revenue ----
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(CASE WHEN a.is_contra = 0 THEN l.credit - l.debit ELSE l.debit - l.credit END), 0) AS net
        FROM gl_journal_lines l
        JOIN gl_transactions t ON t.id = l.transaction_id
        JOIN gl_accounts a ON a.id = l.account_id
        WHERE a.account_type = 'REVENUE' AND a.is_postable = 1
          AND t.entry_date BETWEEN ? AND ?
    ");
    $stmt->bind_param("ss", $fromD, $toD);
    $stmt->execute();
    $totalRevenue = (float)$stmt->get_result()->fetch_assoc()['net'];
    $stmt->close();

    // ---- Expenses ----
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(l.debit - l.credit), 0) AS net
        FROM gl_journal_lines l
        JOIN gl_transactions t ON t.id = l.transaction_id
        JOIN gl_accounts a ON a.id = l.account_id
        WHERE a.account_type = 'EXPENSE' AND a.is_postable = 1
          AND t.entry_date BETWEEN ? AND ?
    ");
    $stmt->bind_param("ss", $fromD, $toD);
    $stmt->execute();
    $totalExpenses = (float)$stmt->get_result()->fetch_assoc()['net'];
    $stmt->close();

    $netProfit = $totalRevenue - $totalExpenses;

    // ---- Assets / Liabilities / Equity as of end date ----
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(l.debit - l.credit), 0) AS net
        FROM gl_journal_lines l
        JOIN gl_transactions t ON t.id = l.transaction_id
        JOIN gl_accounts a ON a.id = l.account_id
        WHERE a.account_type = 'ASSET' AND a.is_postable = 1 AND t.entry_date <= ?
    ");
    $stmt->bind_param("s", $toD);
    $stmt->execute();
    $totalAssets = (float)$stmt->get_result()->fetch_assoc()['net'];
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(l.credit - l.debit), 0) AS net
        FROM gl_journal_lines l
        JOIN gl_transactions t ON t.id = l.transaction_id
        JOIN gl_accounts a ON a.id = l.account_id
        WHERE a.account_type = 'LIABILITY' AND a.is_postable = 1 AND t.entry_date <= ?
    ");
    $stmt->bind_param("s", $toD);
    $stmt->execute();
    $totalLiabilities = (float)$stmt->get_result()->fetch_assoc()['net'];
    $stmt->close();

    $totalEquity = $totalAssets - $totalLiabilities;

    function _preview_bal($conn, $code, $asOf) {
        $a = gl_get_account_by_code($conn, $code);
        if (!$a) return 0.0;
        return gl_account_balance_signed($conn, (int)$a['id'], $asOf);
    }
    $cashInHand = _preview_bal($conn, '1010', $toD);
    $cashAtBank = _preview_bal($conn, '1020', $toD);
    $receivable = _preview_bal($conn, '1030', $toD);
    $payable    = -_preview_bal($conn, '2010', $toD);

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM gl_transactions WHERE entry_date BETWEEN ? AND ?");
    $stmt->bind_param("ss", $fromD, $toD);
    $stmt->execute();
    $txnCount = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    echo json_encode([
        'ok'                => true,
        'session_id'        => (int)$s['id'],
        'session_title'     => $s['title'],
        'from_date'         => $fromD,
        'to_date'           => $toD,
        'total_revenue'     => $totalRevenue,
        'total_expenses'    => $totalExpenses,
        'net_profit'        => $netProfit,
        'total_assets'      => $totalAssets,
        'total_liabilities' => $totalLiabilities,
        'total_equity'      => $totalEquity,
        'cash_in_hand'      => $cashInHand,
        'cash_at_bank'      => $cashAtBank,
        'receivable'        => $receivable,
        'payable'           => $payable,
        'txn_count'         => $txnCount,
        'already_closed'    => $already ? true : false,
        'closed_at'         => $already['closed_at'] ?? null,
    ]);
    exit;
}

/* ============================================================
   HANDLE POST: close a session (only fires after explicit confirm)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_session'])) {
    $sessionId = (int)($_POST['session_id'] ?? 0);
    $notes     = trim($_POST['notes'] ?? '');

    if ($sessionId <= 0) {
        $msg = 'Invalid session.'; $msg_type = 'danger';
    } else {
        $stmt = $conn->prepare("SELECT id, title, from_dated, to_dated, status FROM sessions WHERE id = ?");
        $stmt->bind_param("i", $sessionId);
        $stmt->execute();
        $sess = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$sess) {
            $msg = 'Session not found.'; $msg_type = 'danger';
        } else {
            $check = $conn->prepare("SELECT id FROM gl_session_closures WHERE session_id = ? LIMIT 1");
            $check->bind_param("i", $sessionId);
            $check->execute();
            $already = $check->get_result()->fetch_assoc();
            $check->close();

            if ($already) {
                $msg = 'This session has already been closed (closure #' . (int)$already['id'] . ').';
                $msg_type = 'danger';
            } else {
                try {
                    $conn->begin_transaction();

                    $fromD = $sess['from_dated'];
                    $toD   = $sess['to_dated'];

                    // Revenue
                    $stmt = $conn->prepare("
                        SELECT COALESCE(SUM(CASE WHEN a.is_contra = 0 THEN l.credit - l.debit ELSE l.debit - l.credit END), 0) AS net
                        FROM gl_journal_lines l
                        JOIN gl_transactions t ON t.id = l.transaction_id
                        JOIN gl_accounts a ON a.id = l.account_id
                        WHERE a.account_type = 'REVENUE' AND a.is_postable = 1
                          AND t.entry_date BETWEEN ? AND ?
                    ");
                    $stmt->bind_param("ss", $fromD, $toD);
                    $stmt->execute();
                    $totalRevenue = (float)$stmt->get_result()->fetch_assoc()['net'];
                    $stmt->close();

                    // Expenses
                    $stmt = $conn->prepare("
                        SELECT COALESCE(SUM(l.debit - l.credit), 0) AS net
                        FROM gl_journal_lines l
                        JOIN gl_transactions t ON t.id = l.transaction_id
                        JOIN gl_accounts a ON a.id = l.account_id
                        WHERE a.account_type = 'EXPENSE' AND a.is_postable = 1
                          AND t.entry_date BETWEEN ? AND ?
                    ");
                    $stmt->bind_param("ss", $fromD, $toD);
                    $stmt->execute();
                    $totalExpenses = (float)$stmt->get_result()->fetch_assoc()['net'];
                    $stmt->close();

                    $netProfit = $totalRevenue - $totalExpenses;

                    // Assets / Liabilities / Equity as of end date
                    $stmt = $conn->prepare("
                        SELECT COALESCE(SUM(l.debit - l.credit), 0) AS net
                        FROM gl_journal_lines l
                        JOIN gl_transactions t ON t.id = l.transaction_id
                        JOIN gl_accounts a ON a.id = l.account_id
                        WHERE a.account_type = 'ASSET' AND a.is_postable = 1 AND t.entry_date <= ?
                    ");
                    $stmt->bind_param("s", $toD);
                    $stmt->execute();
                    $totalAssets = (float)$stmt->get_result()->fetch_assoc()['net'];
                    $stmt->close();

                    $stmt = $conn->prepare("
                        SELECT COALESCE(SUM(l.credit - l.debit), 0) AS net
                        FROM gl_journal_lines l
                        JOIN gl_transactions t ON t.id = l.transaction_id
                        JOIN gl_accounts a ON a.id = l.account_id
                        WHERE a.account_type = 'LIABILITY' AND a.is_postable = 1 AND t.entry_date <= ?
                    ");
                    $stmt->bind_param("s", $toD);
                    $stmt->execute();
                    $totalLiabilities = (float)$stmt->get_result()->fetch_assoc()['net'];
                    $stmt->close();

                    $totalEquity = $totalAssets - $totalLiabilities;

                    // Cash / receivable / payable positions
                    function _final_bal($conn, $code, $asOf) {
                        $a = gl_get_account_by_code($conn, $code);
                        if (!$a) return 0.0;
                        return gl_account_balance_signed($conn, (int)$a['id'], $asOf);
                    }
                    $cashInHand = _final_bal($conn, '1010', $toD);
                    $cashAtBank = _final_bal($conn, '1020', $toD);
                    $receivable = _final_bal($conn, '1030', $toD);
                    $payable    = -_final_bal($conn, '2010', $toD);

                    // Transaction count in period
                    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM gl_transactions WHERE entry_date BETWEEN ? AND ?");
                    $stmt->bind_param("ss", $fromD, $toD);
                    $stmt->execute();
                    $txnCount = (int)$stmt->get_result()->fetch_assoc()['c'];
                    $stmt->close();

                    // Insert closure record
                    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
                    $stmt = $conn->prepare("
                        INSERT INTO gl_session_closures
                          (session_id, session_title, from_date, to_date,
                           total_revenue, total_expenses, net_profit,
                           total_assets, total_liabilities, total_equity,
                           cash_in_hand, cash_at_bank, receivable, payable,
                           txn_count, closed_by, notes)
                        VALUES (?, ?, ?, ?,
                                ?, ?, ?,
                                ?, ?, ?,
                                ?, ?, ?, ?,
                                ?, ?, ?)
                    ");
                    $stmt->bind_param(
                        "isss" . "ddd" . "ddd" . "dddd" . "iis",
                        $sessionId, $sess['title'], $fromD, $toD,
                        $totalRevenue, $totalExpenses, $netProfit,
                        $totalAssets, $totalLiabilities, $totalEquity,
                        $cashInHand, $cashAtBank, $receivable, $payable,
                        $txnCount, $userId, $notes
                    );

                    if (!$stmt->execute()) {
                        throw new Exception("Insert closure failed: " . $stmt->error);
                    }
                    $closureId = $conn->insert_id;
                    $stmt->close();

                    $conn->commit();
                    $msg = "Session '{$sess['title']}' closed successfully. Snapshot #$closureId created.";
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

/* ============================================================
   LOAD DATA
   ============================================================ */
$sessions = $conn->query("
    SELECT s.id, s.title, s.from_dated, s.to_dated, s.status,
           (SELECT COUNT(*) FROM gl_session_closures c WHERE c.session_id = s.id) AS closure_count
    FROM sessions s
    ORDER BY s.status ASC, s.id DESC
")->fetch_all(MYSQLI_ASSOC);

$closures = $conn->query("
    SELECT c.*, u.username AS closed_by_name
    FROM gl_session_closures c
    LEFT JOIN users u ON u.id = c.closed_by
    ORDER BY c.closed_at DESC
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Session Close — Dar-e-Arqm School</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
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

    .alert-custom {
        padding: 14px 18px; border-radius: 8px; margin-bottom: 20px;
        font-size: 14px; border-left: 4px solid;
    }
    .alert-custom.success { background: #d1fae5; border-color: #059669; color: #065f46; }
    .alert-custom.danger  { background: #fee2e2; border-color: #dc2626; color: #991b1b; }

    table.tbl {
        width: 100%; border-collapse: collapse;
        background: #fff; border-radius: 8px; overflow: hidden;
        border: 1px solid #e2e8f0;
        margin-bottom: 20px;
    }
    table.tbl thead th {
        background: #f8fafc;
        font-size: 11px; color: #64748b; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px;
        padding: 12px 14px; text-align: left;
        border-bottom: 2px solid #e2e8f0;
    }
    table.tbl tbody td {
        padding: 12px 14px;
        font-size: 13px; color: #1e293b;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }
    .num {
        font-family: 'SF Mono', 'Monaco', monospace;
        font-variant-numeric: tabular-nums;
        text-align: right;
    }
    .pill {
        display: inline-block; padding: 3px 10px;
        border-radius: 10px; font-size: 10px;
        font-weight: 700; letter-spacing: 0.5px;
        text-transform: uppercase;
    }
    .pill.active      { background: #d1fae5; color: #065f46; }
    .pill.closed      { background: #f1f5f9; color: #64748b; }
    .pill.snapshotted { background: #dbeafe; color: #1e40af; }

    .btn-primary-custom {
        height: 40px; padding: 0 18px;
        background: #1e40af; color: #fff;
        border: none; border-radius: 8px;
        font-size: 13px; font-weight: 600;
        cursor: pointer; font-family: inherit;
        display: inline-flex; align-items: center; gap: 6px;
        text-decoration: none;
        transition: all 0.15s;
    }
    .btn-primary-custom:hover:not(:disabled) { background: #1e3a8a; color: #fff; text-decoration: none; }
    .btn-primary-custom:disabled { opacity: 0.5; cursor: not-allowed; }
    .btn-soft {
        height: 40px; padding: 0 16px;
        background: #f1f5f9; color: #334155;
        border: 1px solid #e2e8f0; border-radius: 8px;
        font-size: 13px; font-weight: 600;
        cursor: pointer; font-family: inherit;
        display: inline-flex; align-items: center; gap: 6px;
        text-decoration: none;
        transition: all 0.15s;
    }
    .btn-soft:hover { background: #e2e8f0; text-decoration: none; color: #1e293b; }
    .btn-soft.danger { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
    .btn-soft.danger:hover { background: #fecaca; }

    .close-form {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 22px 26px;
        margin-bottom: 28px;
    }
    .close-form label {
        display: block;
        font-size: 11px; font-weight: 700; color: #475569;
        text-transform: uppercase; letter-spacing: 1px;
        margin-bottom: 6px;
    }
    .close-form textarea {
        width: 100%; padding: 10px 14px;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 14px; font-family: inherit;
        background: #fff; color: #0f172a;
        margin-bottom: 14px;
        min-height: 70px; resize: vertical;
    }
    .close-form textarea:focus {
        outline: none; border-color: #1e40af;
        box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
    }

    .snapshot-detail {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 16px 20px;
    }
    .snapshot-detail .grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 14px;
    }
    .snapshot-detail .item .lbl {
        font-size: 10px; color: #64748b; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px;
        margin-bottom: 4px;
    }
    .snapshot-detail .item .val {
        font-family: 'SF Mono', 'Monaco', monospace;
        font-size: 16px; font-weight: 700;
    }

    .empty-state {
        padding: 30px; text-align: center;
        color: #94a3b8; font-style: italic;
    }

    .step-header {
        font-size: 11px; font-weight: 800; color: #1e40af;
        text-transform: uppercase; letter-spacing: 1.5px;
        margin-bottom: 12px;
        display: flex; align-items: center; gap: 8px;
    }
    .step-header .num {
        display: inline-flex; align-items: center; justify-content: center;
        width: 22px; height: 22px;
        background: #1e40af; color: #fff;
        border-radius: 50%;
        font-size: 11px; font-weight: 800;
    }

    @keyframes spin {
        from { transform: rotate(0deg); }
        to   { transform: rotate(360deg); }
    }
    .spin { display: inline-block; animation: spin 1s linear infinite; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <h1>Session Close</h1>
            <div class="sub">Freeze a session and record a permanent financial snapshot</div>
        </div>
        <a href="gl_test_hub.php" class="btn-new no-print">
            <span class="glyphicon glyphicon-home"></span> Test Hub
        </a>
    </div>

    <div class="card">

        <?php if ($msg): ?>
            <div class="alert-custom <?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <div class="section-title">Sessions</div>

        <table class="tbl">
            <thead>
                <tr>
                    <th style="width:60px;">ID</th>
                    <th>Title</th>
                    <th style="width:120px;">From</th>
                    <th style="width:120px;">To</th>
                    <th style="width:100px;">Status</th>
                    <th style="width:140px;">Snapshot</th>
                    <th class="no-print" style="width:200px;">Action</th>
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
                            <span class="pill snapshotted">✓ Snapshotted</span>
                        <?php else: ?>
                            <span style="color:#94a3b8; font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="no-print">
                        <button type="button" class="btn-soft" onclick="openCloseForm(<?= (int)$s['id'] ?>)">
                            <span class="glyphicon glyphicon-lock"></span> Close &amp; Snapshot
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Close panel — 2-step flow -->
        <div id="closeFormWrap" style="display:none;">
            <div class="section-title" style="display:flex; align-items:center; justify-content:space-between;">
                <span>Close Session</span>
                <span style="font-size:11px; color:#94a3b8; font-weight:600; letter-spacing:0; text-transform:none;">
                    Step 1 of 2 — Preview
                </span>
            </div>

            <form method="post" id="closeForm">
                <input type="hidden" name="close_session" value="1">
                <input type="hidden" name="session_id" id="cf_session_id" value="">

                <!-- STEP 1: PREVIEW -->
                <div id="stepPreview" class="close-form">
                    <div class="step-header"><span class="num">1</span> Preview — nothing will be saved yet</div>

                    <label>Session</label>
                    <div id="pv_session" style="font-size:15px; font-weight:700; margin-bottom:16px;"></div>

                    <div id="previewLoading" style="padding:20px; text-align:center; color:#64748b;">
                        <span class="glyphicon glyphicon-refresh spin"></span>
                        Computing snapshot…
                    </div>

                    <div id="previewError" class="alert-custom danger" style="display:none; margin-bottom:14px;"></div>

                    <div id="previewContent" style="display:none;">
                        <p style="font-size:12px; color:#475569; margin:0 0 12px 0;">
                            These are the numbers that will be frozen. Nothing is written until you confirm in Step 2.
                        </p>

                        <div class="snapshot-detail" style="margin-bottom:16px;">
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

                        <div id="pv_already_closed" class="alert-custom danger" style="display:none;">
                            <strong>Already closed.</strong> This session already has a snapshot and cannot be closed again.
                        </div>

                        <div style="display:flex; gap:10px; flex-wrap:wrap;">
                            <button type="button" class="btn-primary-custom" id="btnGoConfirm" onclick="goToConfirm()">
                                <span class="glyphicon glyphicon-arrow-right"></span> Continue to Confirm
                            </button>
                            <button type="button" class="btn-soft danger" onclick="cancelClose()">
                                <span class="glyphicon glyphicon-remove"></span> Cancel / Not now
                            </button>
                        </div>
                    </div>
                </div>

                <!-- STEP 2: CONFIRM -->
                <div id="stepConfirm" class="close-form" style="display:none;">
                    <div class="step-header" style="color:#991b1b;"><span class="num" style="background:#dc2626;">2</span> Confirm — this will write a permanent snapshot</div>

                    <div class="alert-custom danger" style="margin-bottom:16px;">
                        <strong>⚠ Final step.</strong>
                        Clicking <em>Confirm Close</em> writes an immutable record to <code>gl_session_closures</code>.
                        It cannot be undone from the UI.
                    </div>

                    <label>Notes (optional)</label>
                    <textarea name="notes" id="cf_notes" placeholder="e.g. Closed at end of academic year, audited by Mr. Khan"></textarea>

                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <button type="submit" class="btn-primary-custom">
                            <span class="glyphicon glyphicon-lock"></span> Confirm Close &amp; Snapshot
                        </button>
                        <button type="button" class="btn-soft" onclick="backToPreview()">
                            <span class="glyphicon glyphicon-arrow-left"></span> Back to Preview
                        </button>
                        <button type="button" class="btn-soft danger" onclick="cancelClose()">
                            <span class="glyphicon glyphicon-remove"></span> Cancel / Not now
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div class="section-title">Closure History</div>

        <?php if (empty($closures)): ?>
            <div class="empty-state">No sessions have been closed yet.</div>
        <?php else: ?>
            <?php foreach ($closures as $c): ?>
                <div class="snapshot-detail" style="margin-bottom:14px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
                        <div>
                            <strong style="font-size:15px;"><?= htmlspecialchars($c['session_title']) ?></strong>
                            <span style="color:#64748b; font-size:12px; margin-left:8px;">
                                (<?= htmlspecialchars($c['from_date']) ?> → <?= htmlspecialchars($c['to_date']) ?>)
                            </span>
                        </div>
                        <div style="font-size:12px; color:#94a3b8;">
                            Closed <?= htmlspecialchars($c['closed_at']) ?>
                            <?php if ($c['closed_by_name']): ?> by <?= htmlspecialchars($c['closed_by_name']) ?><?php endif; ?>
                        </div>
                    </div>
                    <div class="grid">
                        <div class="item"><div class="lbl">Revenue</div><div class="val"><?= number_format((float)$c['total_revenue'], 2) ?></div></div>
                        <div class="item"><div class="lbl">Expenses</div><div class="val"><?= number_format((float)$c['total_expenses'], 2) ?></div></div>
                        <div class="item"><div class="lbl">Net Profit</div><div class="val" style="color:<?= (float)$c['net_profit'] < 0 ? '#b91c1c' : '#047857' ?>;"><?= number_format((float)$c['net_profit'], 2) ?></div></div>
                        <div class="item"><div class="lbl">Assets</div><div class="val"><?= number_format((float)$c['total_assets'], 2) ?></div></div>
                        <div class="item"><div class="lbl">Liabilities</div><div class="val"><?= number_format((float)$c['total_liabilities'], 2) ?></div></div>
                        <div class="item"><div class="lbl">Equity</div><div class="val"><?= number_format((float)$c['total_equity'], 2) ?></div></div>
                        <div class="item"><div class="lbl">Cash in Hand</div><div class="val"><?= number_format((float)$c['cash_in_hand'], 2) ?></div></div>
                        <div class="item"><div class="lbl">Cash at Bank</div><div class="val"><?= number_format((float)$c['cash_at_bank'], 2) ?></div></div>
                        <div class="item"><div class="lbl">Receivable</div><div class="val"><?= number_format((float)$c['receivable'], 2) ?></div></div>
                        <div class="item"><div class="lbl">Payable</div><div class="val"><?= number_format((float)$c['payable'], 2) ?></div></div>
                        <div class="item"><div class="lbl">Transactions</div><div class="val"><?= (int)$c['txn_count'] ?></div></div>
                    </div>
                    <?php if (!empty($c['notes'])): ?>
                        <div style="margin-top:12px; font-size:12px; color:#475569; background:#fff; padding:8px 12px; border-radius:6px;">
                            <strong>Notes:</strong> <?= htmlspecialchars($c['notes']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>
</div>

<script>
/* ============================================================
   2-step Close workflow.
   Step 1: click "Close & Snapshot" → AJAX preview (no write)
   Step 2: user clicks Continue → notes + confirm button
   Cancel is available at every step and writes nothing.
   ============================================================ */

function openCloseForm(sessionId) {
    // Reset UI
    document.getElementById('cf_session_id').value = sessionId;
    document.getElementById('stepPreview').style.display = '';
    document.getElementById('stepConfirm').style.display = 'none';
    document.getElementById('previewLoading').style.display = '';
    document.getElementById('previewContent').style.display = 'none';
    document.getElementById('previewError').style.display = 'none';
    document.getElementById('cf_notes').value = '';
    document.getElementById('closeFormWrap').style.display = '';

    // Scroll into view
    setTimeout(function(){
        document.getElementById('closeFormWrap').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 50);

    // Fetch preview (read-only, no DB writes)
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

            document.getElementById('pv_session').textContent =
                '#' + resp.session_id + ' — ' + resp.session_title +
                ' (' + resp.from_date + ' → ' + resp.to_date + ')';

            var fields = [
                'total_revenue', 'total_expenses', 'net_profit',
                'total_assets', 'total_liabilities', 'total_equity',
                'cash_in_hand', 'cash_at_bank', 'receivable', 'payable'
            ];
            fields.forEach(function(k){
                var el = document.getElementById('pv_' + k);
                if (el) {
                    el.textContent = Number(resp[k] || 0).toLocaleString('en-PK', {
                        minimumFractionDigits: 2, maximumFractionDigits: 2
                    });
                }
            });
            document.getElementById('pv_txn_count').textContent = resp.txn_count;

            var btnConfirm = document.getElementById('btnGoConfirm');
            var alreadyBox = document.getElementById('pv_already_closed');

            if (resp.already_closed) {
                alreadyBox.style.display = '';
                btnConfirm.disabled = true;
            } else {
                alreadyBox.style.display = 'none';
                btnConfirm.disabled = false;
            }

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
    document.querySelector('#closeFormWrap .section-title span:last-child').textContent = 'Step 2 of 2 — Confirm';
}

function backToPreview() {
    document.getElementById('stepConfirm').style.display = 'none';
    document.getElementById('stepPreview').style.display = '';
    document.querySelector('#closeFormWrap .section-title span:last-child').textContent = 'Step 1 of 2 — Preview';
}

function cancelClose() {
    if (!confirm('Cancel this close operation? Nothing will be saved.')) return;
    document.getElementById('closeFormWrap').style.display = 'none';
    document.getElementById('cf_session_id').value = '';
    document.getElementById('cf_notes').value = '';
    document.querySelector('#closeFormWrap .section-title span:last-child').textContent = 'Step 1 of 2 — Preview';
}
</script>

</body>
</html>