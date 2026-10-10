<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
$msg = ''; $msg_type = '';

/* ============================================================
   AJAX: preview closing numbers (read-only)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'preview_close') {
    header('Content-Type: application/json');

    $sid = (int)($_POST['session_id'] ?? 0);
    if ($sid <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid session']); exit; }

    $stmt = $conn->prepare("SELECT id, title, from_dated, to_dated FROM sessions WHERE id = ?");
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    $s = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$s) { echo json_encode(['ok' => false, 'error' => 'Session not found']); exit; }

    $chk = $conn->prepare("SELECT id, closed_at FROM gl_year_end_closures WHERE session_id = ? LIMIT 1");
    $chk->bind_param("i", $sid);
    $chk->execute();
    $already = $chk->get_result()->fetch_assoc();
    $chk->close();

    $fromD = $s['from_dated'];
    $toD   = $s['to_dated'];

    // Get every postable Revenue and Expense account with its net balance for the period
    $stmt = $conn->prepare("
        SELECT
            a.id, a.code, a.name, a.account_type, a.is_contra,
            COALESCE(SUM(l.debit),  0) AS total_debit,
            COALESCE(SUM(l.credit), 0) AS total_credit
        FROM gl_accounts a
        LEFT JOIN gl_journal_lines l ON l.account_id = a.id
        LEFT JOIN gl_transactions t ON t.id = l.transaction_id
                                   AND t.entry_date BETWEEN ? AND ?
        WHERE a.is_postable = 1
          AND a.status = 1
          AND a.account_type IN ('REVENUE','EXPENSE')
        GROUP BY a.id, a.code, a.name, a.account_type, a.is_contra
        ORDER BY a.account_type DESC, a.code
    ");
    $stmt->bind_param("ss", $fromD, $toD);
    $stmt->execute();
    $accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Build the closing entries
    $closeEntries = [];  // accounts to zero + their Dr/Cr
    $totalRevenue = 0.0;
    $totalExpenses = 0.0;

    foreach ($accounts as $a) {
        $net = (float)$a['total_debit'] - (float)$a['total_credit'];

        if ($a['account_type'] === 'REVENUE') {
            // Revenue normally has credit balance; contra-revenue (is_contra) has debit
            $isContra = (int)$a['is_contra'] === 1;
            $revenue_amount = $isContra ? -$net : $net;  // positive = revenue earned
            $totalRevenue += $revenue_amount;

            // To zero out a revenue account with credit balance, DEBIT it
            // For contra-revenue with debit balance, CREDIT it
            if (abs($net) < 0.005) continue;

            if (!$isContra) {
                // Normal revenue: credit balance; closing = Dr
                if ($net < 0) {
                    $closeEntries[] = [
                        'account_id' => (int)$a['id'],
                        'code' => $a['code'],
                        'name' => $a['name'],
                        'type' => 'REVENUE',
                        'debit'  => abs($net),
                        'credit' => 0,
                        'side'   => 'Dr',
                    ];
                }
            } else {
                // Contra-revenue: debit balance; closing = Cr
                if ($net > 0) {
                    $closeEntries[] = [
                        'account_id' => (int)$a['id'],
                        'code' => $a['code'],
                        'name' => $a['name'],
                        'type' => 'REVENUE (contra)',
                        'debit'  => 0,
                        'credit' => abs($net),
                        'side'   => 'Cr',
                    ];
                }
            }
        } else { // EXPENSE
            $isContra = (int)$a['is_contra'] === 1;
            $expense_amount = $isContra ? -$net : $net;
            $totalExpenses += $expense_amount;

            // Expense normally has debit balance; closing = Cr
            if (abs($net) < 0.005) continue;

            if (!$isContra) {
                if ($net > 0) {
                    $closeEntries[] = [
                        'account_id' => (int)$a['id'],
                        'code' => $a['code'],
                        'name' => $a['name'],
                        'type' => 'EXPENSE',
                        'debit'  => 0,
                        'credit' => abs($net),
                        'side'   => 'Cr',
                    ];
                }
            } else {
                if ($net < 0) {
                    $closeEntries[] = [
                        'account_id' => (int)$a['id'],
                        'code' => $a['code'],
                        'name' => $a['name'],
                        'type' => 'EXPENSE (contra)',
                        'debit'  => abs($net),
                        'credit' => 0,
                        'side'   => 'Dr',
                    ];
                }
            }
        }
    }

    $netProfit = $totalRevenue - $totalExpenses;

    // Compute the balancing entry to Retained Earnings (3100)
    $totalDr = 0.0; $totalCr = 0.0;
    foreach ($closeEntries as $e) {
        $totalDr += $e['debit'];
        $totalCr += $e['credit'];
    }
    // Net profit is the difference; if profit, credit RE; if loss, debit RE
    $reAdjustment = round($totalDr - $totalCr, 2);

    echo json_encode([
        'ok'                 => true,
        'session_id'         => (int)$s['id'],
        'session_title'      => $s['title'],
        'from_date'          => $fromD,
        'to_date'            => $toD,
        'total_revenue'      => $totalRevenue,
        'total_expenses'     => $totalExpenses,
        'net_profit'         => $netProfit,
        'entries'            => $closeEntries,
        'total_debit'        => $totalDr,
        'total_credit'       => $totalCr,
        're_adjustment'      => $reAdjustment,
        'already_closed'     => $already ? true : false,
        'closed_at'          => $already['closed_at'] ?? null,
    ]);
    exit;
}

/* ============================================================
   POST: execute the close
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close_session') {
    $sid = (int)($_POST['session_id'] ?? 0);
    $entryDate = $_POST['entry_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');

    if ($sid <= 0) {
        $msg = 'Invalid session.'; $msg_type = 'danger';
    } else {
        $stmt = $conn->prepare("SELECT id, title, from_dated, to_dated FROM sessions WHERE id = ?");
        $stmt->bind_param("i", $sid);
        $stmt->execute();
        $s = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$s) {
            $msg = 'Session not found.'; $msg_type = 'danger';
        } else {
            $chk = $conn->prepare("SELECT id FROM gl_year_end_closures WHERE session_id = ? LIMIT 1");
            $chk->bind_param("i", $sid);
            $chk->execute();
            $already = $chk->get_result()->fetch_assoc();
            $chk->close();

            if ($already) {
                $msg = 'This session has already been closed.'; $msg_type = 'danger';
            } else {
                try {
                    $conn->begin_transaction();

                    $fromD = $s['from_dated'];
                    $toD   = $s['to_dated'];

                    // Build closing entries (same logic as preview)
                    $stmt = $conn->prepare("
                        SELECT a.id, a.code, a.name, a.account_type, a.is_contra,
                               COALESCE(SUM(l.debit), 0) AS total_debit,
                               COALESCE(SUM(l.credit), 0) AS total_credit
                        FROM gl_accounts a
                        LEFT JOIN gl_journal_lines l ON l.account_id = a.id
                        LEFT JOIN gl_transactions t ON t.id = l.transaction_id
                                                   AND t.entry_date BETWEEN ? AND ?
                        WHERE a.is_postable = 1 AND a.status = 1
                          AND a.account_type IN ('REVENUE','EXPENSE')
                        GROUP BY a.id, a.code, a.name, a.account_type, a.is_contra
                    ");
                    $stmt->bind_param("ss", $fromD, $toD);
                    $stmt->execute();
                    $accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();

                    $lines = [];
                    $totalRevenue = 0.0;
                    $totalExpenses = 0.0;
                    $revenueCount = 0;
                    $expenseCount = 0;

                    foreach ($accounts as $a) {
                        $net = (float)$a['total_debit'] - (float)$a['total_credit'];
                        $isContra = (int)$a['is_contra'] === 1;

                        if ($a['account_type'] === 'REVENUE') {
                            $totalRevenue += $isContra ? -$net : $net;
                            if (abs($net) < 0.005) continue;
                            if (!$isContra && $net < 0) {
                                $lines[] = ['account_id' => (int)$a['id'], 'debit' => abs($net), 'credit' => 0,
                                            'memo' => 'Year-end close — ' . $a['code']];
                                $revenueCount++;
                            } elseif ($isContra && $net > 0) {
                                $lines[] = ['account_id' => (int)$a['id'], 'debit' => 0, 'credit' => abs($net),
                                            'memo' => 'Year-end close — ' . $a['code']];
                                $revenueCount++;
                            }
                        } else {
                            $totalExpenses += $isContra ? -$net : $net;
                            if (abs($net) < 0.005) continue;
                            if (!$isContra && $net > 0) {
                                $lines[] = ['account_id' => (int)$a['id'], 'debit' => 0, 'credit' => abs($net),
                                            'memo' => 'Year-end close — ' . $a['code']];
                                $expenseCount++;
                            } elseif ($isContra && $net < 0) {
                                $lines[] = ['account_id' => (int)$a['id'], 'debit' => abs($net), 'credit' => 0,
                                            'memo' => 'Year-end close — ' . $a['code']];
                                $expenseCount++;
                            }
                        }
                    }

                    $netProfit = $totalRevenue - $totalExpenses;

                    // Retained Earnings balancing entry
                    $reAccount = gl_get_account_by_code($conn, '3100');
                    if (!$reAccount) {
                        throw new Exception('Retained Earnings account (3100) not found.');
                    }

                    $totalDr = 0.0; $totalCr = 0.0;
                    foreach ($lines as $l) {
                        $totalDr += $l['debit'];
                        $totalCr += $l['credit'];
                    }
                    $reAdjust = round($totalDr - $totalCr, 2);

                    if (abs($reAdjust) > 0.005) {
                        if ($reAdjust > 0) {
                            // Need credit to RE
                            $lines[] = ['account_id' => (int)$reAccount['id'], 'debit' => 0, 'credit' => $reAdjust,
                                        'memo' => 'Year-end close — net result'];
                        } else {
                            // Need debit to RE (loss)
                            $lines[] = ['account_id' => (int)$reAccount['id'], 'debit' => abs($reAdjust), 'credit' => 0,
                                        'memo' => 'Year-end close — net result'];
                        }
                    }

                    if (empty($lines)) {
                        throw new Exception('Nothing to close — all revenue and expense accounts are already zero.');
                    }

                    // Post the closing journal entry
                    $sessionId = gl_current_session_id($conn) ?: $sid;
                    $glErr = null;
                    $txnId = post_journal_entry($conn, [
                        'entry_date'  => $entryDate,
                        'session_id'  => $sessionId,
                        'description' => 'YEAR-END CLOSE — ' . $s['title'],
                        'ref_type'    => 'year_end_close',
                        'ref_id'      => $sid,
                        'posted_by'   => $userId,
                        'idempotency' => 'year-end-close-' . $sid,
                        'lines'       => $lines,
                    ], $glErr);

                    if ($txnId <= 0) {
                        throw new Exception('Close posting failed: ' . ($glErr ?: 'unknown'));
                    }

                    // Record the closure
                    $ins = $conn->prepare("
                        INSERT INTO gl_year_end_closures
                          (session_id, session_title, from_date, to_date,
                           total_revenue, total_expenses, net_profit,
                           close_txn_id, close_entry_date,
                           revenue_accounts, expense_accounts,
                           closed_by, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $ins->bind_param(
                        "isssddddisiiis",
                        $sid, $s['title'], $fromD, $toD,
                        $totalRevenue, $totalExpenses, $netProfit,
                        $txnId, $entryDate,
                        $revenueCount, $expenseCount,
                        $userId, $notes
                    );
                    if (!$ins->execute()) {
                        throw new Exception("Closure log insert failed: " . $ins->error);
                    }
                    $ins->close();

                    // Lock the session
                    $upd = $conn->prepare("UPDATE sessions SET status = 1 WHERE id = ?");
                    $upd->bind_param("i", $sid);
                    $upd->execute();
                    $upd->close();

                    $conn->commit();

                    $msg = "Year-end close posted successfully. "
                         . "TXN #$txnId — Revenue: " . number_format($totalRevenue, 2)
                         . ", Expenses: " . number_format($totalExpenses, 2)
                         . ", Net: " . number_format($netProfit, 2);
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

/* ============================================================
   LOAD DATA
   ============================================================ */
$sessions = $conn->query("
    SELECT s.id, s.title, s.from_dated, s.to_dated, s.status,
           c.id AS closure_id, c.net_profit AS closed_profit, c.closed_at
    FROM sessions s
    LEFT JOIN gl_year_end_closures c ON c.session_id = s.id
    ORDER BY s.status ASC, s.id DESC
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Year-End Close</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    * { box-sizing: border-box; }
    body { background: #eef1f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 0; color: #1e293b; }
    .wrap { max-width: 1200px; margin: 30px auto; padding: 0 20px; }

    .head {
        background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
        color: #fff; padding: 30px 40px 26px 40px; border-radius: 12px 12px 0 0;
        box-shadow: 0 4px 16px rgba(30,64,175,0.15);
        display: flex; justify-content: space-between; align-items: flex-end;
        gap: 20px; flex-wrap: wrap;
    }
    .head h1 { margin: 0; font-size: 30px; font-weight: 700; }
    .head .sub { font-size: 14px; opacity: 0.9; margin-top: 6px; }

    .btn-back {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 10px 20px; background: #ffffff; color: #1e40af;
        border: 1px solid #ffffff; border-radius: 8px;
        text-decoration: none; font-size: 14px; font-weight: 600;
        line-height: 1; font-family: inherit;
    }
    .btn-back:hover { background: #e0e7ff; border-color: #e0e7ff; text-decoration: none; color: #1e40af; }

    .card { background: #fff; padding: 30px 40px 40px 40px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

    .section-title {
        font-size: 12px; font-weight: 800; color: #64748b; text-transform: uppercase;
        letter-spacing: 2px; margin: 28px 0 14px 0; padding-bottom: 8px;
        border-bottom: 1px solid #e2e8f0;
    }
    .section-title:first-child { margin-top: 0; }

    .alert-custom { padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; border-left: 4px solid; }
    .alert-custom.success { background: #d1fae5; border-color: #059669; color: #065f46; }
    .alert-custom.danger  { background: #fee2e2; border-color: #dc2626; color: #991b1b; }

    table.tbl { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; margin-bottom: 20px; }
    table.tbl thead th {
        background: #f8fafc; font-size: 11px; color: #64748b; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px; padding: 12px 14px;
        text-align: left; border-bottom: 2px solid #e2e8f0;
    }
    table.tbl tbody td { padding: 12px 14px; font-size: 13px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    table.tbl tbody tr:hover td { background: #f8fafc; }
    .num { font-family: 'SF Mono','Monaco',monospace; text-align: right; font-variant-numeric: tabular-nums; }

    .pill { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
    .pill.active { background: #d1fae5; color: #065f46; }
    .pill.closed { background: #e0e7ff; color: #3730a3; }

    .action-btn-primary,
    .action-btn-cancel,
    .action-btn-warn {
        display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        height: 46px; min-width: 170px; padding: 0 24px;
        border-radius: 8px; font-family: inherit; font-size: 15px; font-weight: 600;
        line-height: 1; text-decoration: none; cursor: pointer;
        border: 1px solid transparent; white-space: nowrap;
        transition: all 0.15s;
    }
    .action-btn-primary { background: #1e40af; color: #ffffff; border-color: #1e40af; }
    .action-btn-primary:hover { background: #1e3a8a; border-color: #1e3a8a; color: #ffffff; text-decoration: none; }
    .action-btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
    .action-btn-cancel { background: #f1f5f9; color: #334155; border-color: #cbd5e1; }
    .action-btn-cancel:hover { background: #e2e8f0; color: #1e293b; text-decoration: none; }
    .action-btn-warn { background: #b91c1c; color: #ffffff; border-color: #b91c1c; }
    .action-btn-warn:hover:not(:disabled) { background: #7f1d1d; border-color: #7f1d1d; color: #ffffff; text-decoration: none; }
    .action-btn-warn:disabled { opacity: 0.5; cursor: not-allowed; }

    .close-form { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 24px 28px; margin-bottom: 24px; }
    .field { margin-bottom: 16px; }
    .field label { display: block; font-size: 12px; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px; }
    .field input, .field textarea {
        width: 100%; height: 44px; padding: 10px 14px;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 15px; font-family: inherit; background: #fff; color: #0f172a;
    }
    .field textarea { height: auto; min-height: 80px; resize: vertical; }
    .field input:focus, .field textarea:focus {
        outline: none; border-color: #1e40af; box-shadow: 0 0 0 3px rgba(30,64,175,0.1);
    }

    .preview-box {
        background: #fffbeb; border: 1px solid #fde68a; border-left: 4px solid #f59e0b;
        padding: 16px 20px; border-radius: 10px; margin: 20px 0;
    }
    .preview-box .lbl {
        font-size: 11px; color: #78350f; font-weight: 800;
        text-transform: uppercase; letter-spacing: 1.2px; margin-bottom: 8px;
    }
    .preview-box .big {
        font-family: 'SF Mono','Monaco',monospace; font-size: 20px; font-weight: 700;
    }
    .preview-box .row { display: flex; justify-content: space-between; padding: 6px 0; font-size: 14px; }
    .preview-box .row.total { border-top: 2px solid #fbbf24; padding-top: 10px; margin-top: 6px; font-weight: 700; }

    .entries-table {
        max-height: 400px; overflow-y: auto;
        border: 1px solid #e2e8f0; border-radius: 8px; margin: 20px 0;
    }

    .warn-box {
        background: #fef2f2; border: 1px solid #fecaca; border-left: 4px solid #dc2626;
        padding: 14px 18px; border-radius: 10px; font-size: 13px;
        color: #991b1b; line-height: 1.6; margin-top: 16px;
    }

    .readonly-note { padding: 20px 24px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; color: #64748b; text-align: center; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <h1>Year-End Close</h1>
            <div class="sub">Post the closing journal entry that zeroes out Revenue and Expenses into Retained Earnings</div>
        </div>
        <a href="gl_test_hub.php" class="btn-back">
            <span class="glyphicon glyphicon-arrow-left"></span> Test Hub
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
                    <th style="width:130px;">Period</th>
                    <th style="width:130px;">Status</th>
                    <th style="width:150px;">Closed?</th>
                    <th style="width:200px;">Actions</th>
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
                            <span style="color:#94a3b8;">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($isClosed): ?>
                            <span class="pill closed">✓ <?= number_format((float)$s['closed_profit'], 2) ?></span>
                        <?php else: ?>
                            <span style="color:#94a3b8;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($isClosed): ?>
                            <span style="font-size:12px; color:#64748b;">Closed on <?= htmlspecialchars($s['closed_at']) ?></span>
                        <?php else: ?>
                            <button type="button" class="action-btn-warn" style="height:36px; min-width:0; padding:0 14px; font-size:13px;"
                                    onclick="loadPreview(<?= (int)$s['id'] ?>)">
                                <span class="glyphicon glyphicon-lock"></span> Close
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Close panel -->
        <div id="closeFormWrap" style="display:none;">
            <div class="section-title">
                <span id="cf_header">Close Session</span>
                <span style="font-size:11px; color:#94a3b8; font-weight:600; letter-spacing:0; text-transform:none; margin-left:auto;">
                    Step 1 of 2 — Preview
                </span>
            </div>

            <form method="post" id="closeForm">
                <input type="hidden" name="action" value="close_session">
                <input type="hidden" name="session_id" id="cf_session_id" value="">

                <!-- STEP 1: PREVIEW -->
                <div id="stepPreview" class="close-form">
                    <div style="display:flex; align-items:center; gap:12px; margin-bottom:16px;">
                        <span style="display:inline-flex; align-items:center; justify-content:center; width:26px; height:26px; background:#1e40af; color:#fff; border-radius:50%; font-weight:800; font-size:12px;">1</span>
                        <span style="font-weight:700; color:#1e40af;">Preview — nothing written yet</span>
                    </div>

                    <div id="pv_session" style="font-size:16px; font-weight:700; margin-bottom:16px;"></div>

                    <div id="previewLoading" style="padding:24px; text-align:center; color:#64748b;">
                        <span class="glyphicon glyphicon-refresh"></span> Computing…
                    </div>

                    <div id="previewError" class="alert-custom danger" style="display:none;"></div>

                    <div id="previewContent" style="display:none;">

                        <div class="preview-box">
                            <div class="lbl">Summary</div>
                            <div class="row"><span>Total Revenue</span><span class="big" id="pv_revenue">—</span></div>
                            <div class="row"><span>Total Expenses</span><span class="big" id="pv_expenses">—</span></div>
                            <div class="row total"><span>Net Profit</span><span class="big" id="pv_profit">—</span></div>
                        </div>

                        <div style="font-size:12px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:1.5px; margin:20px 0 10px 0;">
                            Closing Entries to be Posted
                        </div>
                        <div class="entries-table">
                            <table class="tbl" style="margin:0; border:none; border-radius:0;">
                                <thead>
                                    <tr>
                                        <th style="width:100px;">Code</th>
                                        <th>Account</th>
                                        <th style="width:120px;">Type</th>
                                        <th class="num" style="width:120px;">Dr</th>
                                        <th class="num" style="width:120px;">Cr</th>
                                    </tr>
                                </thead>
                                <tbody id="pv_entries"></tbody>
                            </table>
                        </div>

                        <div id="pv_already_closed" class="alert-custom danger" style="display:none;">
                            <strong>Already closed.</strong> This session has already been closed.
                        </div>

                        <div style="display:flex; gap:12px; flex-wrap:wrap; margin-top:20px;">
                            <button type="button" class="action-btn-primary" id="btnGoConfirm" onclick="goToConfirm()">
                                <span class="glyphicon glyphicon-arrow-right"></span> Continue to Confirm
                            </button>
                            <button type="button" class="action-btn-cancel" onclick="cancelClose()">
                                <span class="glyphicon glyphicon-remove"></span> Cancel
                            </button>
                        </div>
                    </div>
                </div>

                <!-- STEP 2: CONFIRM -->
                <div id="stepConfirm" class="close-form" style="display:none;">
                    <div style="display:flex; align-items:center; gap:12px; margin-bottom:16px;">
                        <span style="display:inline-flex; align-items:center; justify-content:center; width:26px; height:26px; background:#dc2626; color:#fff; border-radius:50%; font-weight:800; font-size:12px;">2</span>
                        <span style="font-weight:700; color:#991b1b;">Confirm — this writes a journal entry</span>
                    </div>

                    <div class="warn-box">
                        <strong>⚠ Final step.</strong> Clicking <em>Confirm Year-End Close</em> will:
                        <ul style="margin:8px 0 0 20px; padding:0;">
                            <li>Post a journal entry that zeroes out every Revenue and Expense account for this period</li>
                            <li>Transfer the net profit / loss to Retained Earnings (account 3100)</li>
                            <li>Mark the session as inactive (status = 1)</li>
                            <li>Record the closure in <code>gl_year_end_closures</code></li>
                        </ul>
                        This cannot be undone from the UI.
                    </div>

                    <div class="field" style="margin-top:20px;">
                        <label>Closing Entry Date</label>
                        <input type="date" name="entry_date" id="cf_entry_date" required>
                        <div style="font-size:12px; color:#64748b; margin-top:5px;">
                            Usually the last day of the session period.
                        </div>
                    </div>

                    <div class="field">
                        <label>Notes (optional)</label>
                        <textarea name="notes" placeholder="e.g. FY 2026-2027 close, audited by Mr. Khan"></textarea>
                    </div>

                    <div style="display:flex; gap:12px; flex-wrap:wrap;">
                        <button type="submit" class="action-btn-warn">
                            <span class="glyphicon glyphicon-lock"></span> Confirm Year-End Close
                        </button>
                        <button type="button" class="action-btn-cancel" onclick="backToPreview()">
                            <span class="glyphicon glyphicon-arrow-left"></span> Back to Preview
                        </button>
                        <button type="button" class="action-btn-cancel" onclick="cancelClose()">
                            <span class="glyphicon glyphicon-remove"></span> Cancel
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

    document.querySelector('#closeFormWrap .section-title span:last-child').textContent = 'Step 1 of 2 — Preview';

    setTimeout(function(){
        document.getElementById('closeFormWrap').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 50);

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

            document.getElementById('pv_session').textContent =
                '#' + resp.session_id + ' — ' + resp.session_title +
                ' (' + resp.from_date + ' → ' + resp.to_date + ')';

            document.getElementById('pv_revenue').textContent = Number(resp.total_revenue).toLocaleString('en-PK', {minimumFractionDigits:2, maximumFractionDigits:2});
            document.getElementById('pv_expenses').textContent = Number(resp.total_expenses).toLocaleString('en-PK', {minimumFractionDigits:2, maximumFractionDigits:2});
            document.getElementById('pv_profit').textContent = Number(resp.net_profit).toLocaleString('en-PK', {minimumFractionDigits:2, maximumFractionDigits:2});
            document.getElementById('pv_profit').style.color = resp.net_profit < 0 ? '#b91c1c' : '#047857';

            var tbody = document.getElementById('pv_entries');
            tbody.innerHTML = '';
            resp.entries.forEach(function(e){
                var tr = document.createElement('tr');
                tr.innerHTML =
                    '<td><code>' + e.code + '</code></td>' +
                    '<td>' + e.name + '</td>' +
                    '<td><span style="font-size:10px; padding:2px 6px; border-radius:6px; background:' +
                        (e.type.indexOf('REVENUE') === 0 ? '#dbeafe; color:#1e40af' : '#fee2e2; color:#991b1b') + '">' +
                        e.type + '</span></td>' +
                    '<td class="num">' + (e.debit > 0 ? Number(e.debit).toLocaleString('en-PK', {minimumFractionDigits:2}) : '') + '</td>' +
                    '<td class="num">' + (e.credit > 0 ? Number(e.credit).toLocaleString('en-PK', {minimumFractionDigits:2}) : '') + '</td>';
                tbody.appendChild(tr);
            });

            // Retained earnings balancing row
            var tr = document.createElement('tr');
            tr.style.background = '#f0f5ff';
            tr.style.fontWeight = '700';
            var reDr = resp.re_adjustment < 0 ? Math.abs(resp.re_adjustment) : 0;
            var reCr = resp.re_adjustment > 0 ? resp.re_adjustment : 0;
            tr.innerHTML =
                '<td><code>3100</code></td>' +
                '<td>Retained Earnings</td>' +
                '<td><span style="font-size:10px; padding:2px 6px; border-radius:6px; background:#e0e7ff; color:#3730a3;">EQUITY</span></td>' +
                '<td class="num">' + (reDr > 0 ? Number(reDr).toLocaleString('en-PK', {minimumFractionDigits:2}) : '') + '</td>' +
                '<td class="num">' + (reCr > 0 ? Number(reCr).toLocaleString('en-PK', {minimumFractionDigits:2}) : '') + '</td>';
            tbody.appendChild(tr);

            document.getElementById('cf_entry_date').value = resp.to_date;

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
    if (!confirm('Cancel this year-end close? Nothing will be saved.')) return;
    document.getElementById('closeFormWrap').style.display = 'none';
    document.getElementById('cf_session_id').value = '';
    document.querySelector('#closeFormWrap .section-title span:last-child').textContent = 'Step 1 of 2 — Preview';
}
</script>

</body>
</html>