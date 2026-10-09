<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;

$reconId = (int)($_GET['id'] ?? 0);
if ($reconId <= 0) { header('Location: gl_bank_recon.php'); exit; }

$stmt = $conn->prepare("
    SELECT r.*, a.code AS account_code, a.name AS account_name
    FROM bank_reconciliations r
    LEFT JOIN gl_accounts a ON a.id = r.gl_account_id
    WHERE r.id = ?
");
$stmt->bind_param("i", $reconId);
$stmt->execute();
$recon = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$recon) { http_response_code(404); die('Reconciliation not found.'); }

$isDraft = $recon['status'] === 'DRAFT';

$msg = ''; $msg_type = '';

/* ---------- Action: add_line ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_line' && $isDraft) {
    $d = (float)($_POST['debit'] ?? 0);
    $c = (float)($_POST['credit'] ?? 0);
    $desc = trim($_POST['description'] ?? '');

    if ($desc === '') {
        $msg = 'Description is required.'; $msg_type = 'danger';
    } elseif ($d <= 0 && $c <= 0) {
        $msg = 'Enter either a debit OR a credit.'; $msg_type = 'danger';
    } elseif ($d > 0 && $c > 0) {
        $msg = 'Only one of debit or credit.'; $msg_type = 'danger';
    } else {
        $ins = $conn->prepare("INSERT INTO bank_statement_lines (reconciliation_id, line_date, description, reference, debit, credit) VALUES (?, ?, ?, ?, ?, ?)");
        $ins->bind_param("isssdd", $reconId, $_POST['line_date'], $desc, $_POST['reference'], $d, $c);
        if ($ins->execute()) {
            $msg = 'Statement line added.'; $msg_type = 'success';
        } else {
            $msg = 'Insert failed: ' . $ins->error; $msg_type = 'danger';
        }
        $ins->close();
    }
}

/* ---------- Action: bulk_paste ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_paste' && $isDraft) {
    $raw = trim($_POST['bulk_data'] ?? '');
    if ($raw === '') {
        $msg = 'Nothing to import.'; $msg_type = 'danger';
    } else {
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $inserted = 0; $skipped = 0;
        $ins = $conn->prepare("INSERT INTO bank_statement_lines (reconciliation_id, line_date, description, reference, debit, credit) VALUES (?, ?, ?, ?, ?, ?)");

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            if (strpos($line, "\t") !== false) {
                $parts = explode("\t", $line);
            } elseif (strpos($line, ',') !== false) {
                $parts = str_getcsv($line);
            } else {
                $parts = preg_split('/\s{2,}/', $line);
            }
            $parts = array_map('trim', $parts);
            if (count($parts) < 4) { $skipped++; continue; }

            $ts = strtotime($parts[0]);
            if (!$ts) { $skipped++; continue; }
            $lineDate = date('Y-m-d', $ts);
            $desc = $parts[1]; $ref = $parts[2];

            if (count($parts) >= 5 && is_numeric(str_replace(',', '', $parts[3])) && is_numeric(str_replace(',', '', $parts[4]))) {
                $d = (float)str_replace(',', '', $parts[3]);
                $c = (float)str_replace(',', '', $parts[4]);
            } elseif (count($parts) >= 4 && is_numeric(str_replace(',', '', $parts[3]))) {
                $amt = (float)str_replace(',', '', $parts[3]);
                if ($amt >= 0) { $d = 0; $c = $amt; } else { $d = -$amt; $c = 0; }
            } else { $skipped++; continue; }

            if ($d <= 0 && $c <= 0) { $skipped++; continue; }

            $ins->bind_param("isssdd", $reconId, $lineDate, $desc, $ref, $d, $c);
            if ($ins->execute()) $inserted++; else $skipped++;
        }
        $ins->close();
        $msg = "Imported $inserted line(s). Skipped $skipped malformed row(s).";
        $msg_type = $inserted > 0 ? 'success' : 'danger';
    }
}

/* ---------- Action: match ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'match' && $isDraft) {
    $sid = (int)($_POST['statement_id'] ?? 0);
    $lid = (int)($_POST['ledger_id']    ?? 0);

    if ($sid > 0 && $lid > 0) {
        $conn->begin_transaction();
        try {
            $conn->query("UPDATE bank_statement_lines SET matched_line_id = NULL WHERE id = $sid AND reconciliation_id = $reconId");
            $conn->query("DELETE FROM bank_recon_ledger_marks WHERE reconciliation_id = $reconId AND ledger_line_id = $lid");
            $conn->query("UPDATE bank_statement_lines SET matched_line_id = $lid WHERE id = $sid AND reconciliation_id = $reconId");

            $ins = $conn->prepare("INSERT INTO bank_recon_ledger_marks (reconciliation_id, ledger_line_id, mark_type, matched_statement_id) VALUES (?, ?, 'MATCHED', ?)");
            $ins->bind_param("iii", $reconId, $lid, $sid);
            $ins->execute();
            $ins->close();

            $conn->commit();
            $msg = 'Matched.'; $msg_type = 'success';
        } catch (Exception $e) {
            $conn->rollback();
            $msg = 'Match failed: ' . $e->getMessage(); $msg_type = 'danger';
        }
    }
}

/* ---------- Action: adjust ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'adjust' && $isDraft) {
    $sid = (int)($_POST['statement_id'] ?? 0);
    $otherAccId = (int)($_POST['other_account'] ?? 0);
    $amt = (float)($_POST['adjust_amount'] ?? 0);
    $adjType = $_POST['adjust_type'] ?? '';
    $memo = trim($_POST['adjust_memo'] ?? '');

    if ($sid <= 0 || $otherAccId <= 0 || $amt <= 0) {
        $msg = 'All fields required.'; $msg_type = 'danger';
    } else {
        $sl = $conn->query("SELECT * FROM bank_statement_lines WHERE id = $sid AND reconciliation_id = $reconId")->fetch_assoc();
        if (!$sl) {
            $msg = 'Statement line not found.'; $msg_type = 'danger';
        } else {
            $bankId = (int)$recon['gl_account_id'];
            $sessionId = gl_current_session_id($conn) ?: 1;

            if ($adjType === 'debit') {
                $lines = [
                    ['account_id' => $otherAccId, 'debit' => $amt, 'credit' => 0, 'memo' => $memo ?: $sl['description']],
                    ['account_id' => $bankId, 'debit' => 0, 'credit' => $amt, 'memo' => 'Bank adj for line #' . $sid],
                ];
            } else {
                $lines = [
                    ['account_id' => $bankId, 'debit' => $amt, 'credit' => 0, 'memo' => 'Bank adj for line #' . $sid],
                    ['account_id' => $otherAccId, 'debit' => 0, 'credit' => $amt, 'memo' => $memo ?: $sl['description']],
                ];
            }

            $glErr = null;
            $txnId = post_journal_entry($conn, [
                'entry_date' => $sl['line_date'],
                'session_id' => $sessionId,
                'description' => 'Bank Recon #' . $reconId . ' — ' . ($memo ?: $sl['description']),
                'ref_type' => 'bank_recon',
                'ref_id' => $reconId,
                'posted_by' => $userId,
                'idempotency' => 'bankrecon-' . $reconId . '-line-' . $sid,
                'lines' => $lines,
            ], $glErr);

            if ($txnId > 0) {
                $conn->query("UPDATE bank_statement_lines SET adjustment_txn_id = $txnId WHERE id = $sid");
                $msg = 'Adjustment posted (TXN #' . $txnId . ').'; $msg_type = 'success';
            } else {
                $msg = 'Adjustment failed: ' . $glErr; $msg_type = 'danger';
            }
        }
    }
}

/* ---------- Action: unmatch ---------- */
if (isset($_GET['unmatch']) && is_numeric($_GET['unmatch']) && $isDraft) {
    $sid = (int)$_GET['unmatch'];
    $conn->query("UPDATE bank_statement_lines SET matched_line_id = NULL WHERE id = $sid AND reconciliation_id = $reconId");
    $conn->query("DELETE FROM bank_recon_ledger_marks WHERE reconciliation_id = $reconId AND matched_statement_id = $sid");
    header("Location: gl_bank_recon_view.php?id=$reconId");
    exit;
}

/* ---------- Action: mark_outstanding ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_outstanding' && $isDraft) {
    $lid = (int)($_POST['ledger_id'] ?? 0);
    if ($lid > 0) {
        $conn->query("DELETE FROM bank_recon_ledger_marks WHERE reconciliation_id = $reconId AND ledger_line_id = $lid");
        $ins = $conn->prepare("INSERT INTO bank_recon_ledger_marks (reconciliation_id, ledger_line_id, mark_type) VALUES (?, ?, 'OUTSTANDING')");
        $ins->bind_param("ii", $reconId, $lid);
        $ins->execute();
        $ins->close();
        $msg = 'Marked outstanding.'; $msg_type = 'success';
    }
}

/* ---------- Action: unmark ---------- */
if (isset($_GET['unmark']) && is_numeric($_GET['unmark']) && $isDraft) {
    $lid = (int)$_GET['unmark'];
    $conn->query("DELETE FROM bank_recon_ledger_marks WHERE reconciliation_id = $reconId AND ledger_line_id = $lid");
    header("Location: gl_bank_recon_view.php?id=$reconId");
    exit;
}

/* ---------- Action: delete_line ---------- */
if (isset($_GET['del_line']) && is_numeric($_GET['del_line']) && $isDraft) {
    $lid = (int)$_GET['del_line'];
    $conn->query("DELETE FROM bank_statement_lines WHERE id = $lid AND reconciliation_id = $reconId");
    header("Location: gl_bank_recon_view.php?id=$reconId");
    exit;
}

/* ---------- Action: complete ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete' && $isDraft) {
    $bankId = (int)$recon['gl_account_id'];
    $bookBalance = gl_account_balance_signed($conn, $bankId, $recon['statement_date']);
    $adjustedBank = (float)$recon['closing_balance'];
    $adjustedBook = $bookBalance;
    $difference = $adjustedBank - $adjustedBook;

    $upd = $conn->prepare("UPDATE bank_reconciliations SET book_balance=?, adjusted_bank=?, adjusted_book=?, difference=?, status='COMPLETED', completed_at=NOW(), completed_by=? WHERE id=?");
    $upd->bind_param("ddddii", $bookBalance, $adjustedBank, $adjustedBook, $difference, $userId, $reconId);
    $upd->execute();
    $upd->close();
    header("Location: gl_bank_recon_view.php?id=$reconId");
    exit;
}

/* ---------- Reload ---------- */
$stmt = $conn->prepare("SELECT r.*, a.code AS account_code, a.name AS account_name FROM bank_reconciliations r LEFT JOIN gl_accounts a ON a.id=r.gl_account_id WHERE r.id=?");
$stmt->bind_param("i", $reconId);
$stmt->execute();
$recon = $stmt->get_result()->fetch_assoc();
$stmt->close();
$isDraft = $recon['status'] === 'DRAFT';

/* ---------- Load data ---------- */
$stmtLines = $conn->query("
    SELECT s.*, l.debit AS l_debit, l.credit AS l_credit, t.entry_date AS l_date
    FROM bank_statement_lines s
    LEFT JOIN gl_journal_lines l ON l.id = s.matched_line_id
    LEFT JOIN gl_transactions t ON t.id = l.transaction_id
    WHERE s.reconciliation_id = $reconId
    ORDER BY s.line_date ASC, s.id ASC
")->fetch_all(MYSQLI_ASSOC);

$bankId = (int)$recon['gl_account_id'];
$periodStart = $recon['statement_start'] ?: date('Y-m-d', strtotime($recon['statement_date'] . ' -60 days'));

$stmt = $conn->prepare("
    SELECT l.id, l.debit, l.credit, t.id AS txn_id, t.entry_date, t.description
    FROM gl_journal_lines l
    JOIN gl_transactions t ON t.id = l.transaction_id
    WHERE l.account_id = ? AND t.entry_date <= ? AND t.entry_date >= ?
    ORDER BY t.entry_date ASC, l.id ASC
");
$stmt->bind_param("iss", $bankId, $recon['statement_date'], $periodStart);
$stmt->execute();
$ledgerLines = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$marks = [];
$mkRes = $conn->query("SELECT ledger_line_id, mark_type, matched_statement_id FROM bank_recon_ledger_marks WHERE reconciliation_id = $reconId");
while ($m = $mkRes->fetch_assoc()) $marks[(int)$m['ledger_line_id']] = $m;

$unmatchedStatement = 0;
foreach ($stmtLines as $sl) if (empty($sl['matched_line_id']) && empty($sl['adjustment_txn_id'])) $unmatchedStatement++;

$unmatchedLedger = 0;
foreach ($ledgerLines as $ll) if (!isset($marks[(int)$ll['id']])) $unmatchedLedger++;

$adjustAccounts = gl_list_accounts($conn, null, true);

$tab = $_GET['tab'] ?? 'overview';
$preselectStmt = (int)($_GET['stmt'] ?? 0);
$preselectLedger = (int)($_GET['ledger'] ?? 0);

$pendingStmt = array_filter($stmtLines, function($s) {
    return empty($s['matched_line_id']) && empty($s['adjustment_txn_id']);
});
$availableLedger = array_filter($ledgerLines, function($l) use ($marks) {
    return !isset($marks[(int)$l['id']]);
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Reconcile #<?= (int)$recon['id'] ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    * { box-sizing: border-box; }
    body { background: #eef1f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 0; color: #1e293b; }
    .wrap { max-width: 1500px; margin: 30px auto; padding: 0 20px; }

    .head {
        background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
        color: #fff; padding: 26px 34px 22px 34px; border-radius: 12px 12px 0 0;
        box-shadow: 0 4px 16px rgba(30,64,175,0.15);
        display: flex; justify-content: space-between; align-items: flex-end;
        gap: 20px; flex-wrap: wrap;
    }
    .head h1 { margin: 0; font-size: 24px; font-weight: 700; }
    .head .sub { font-size: 13px; opacity: 0.9; margin-top: 4px; }
    .head .actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .btn-head {
        display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px;
        background: rgba(255,255,255,0.15); color: #fff;
        border: 1px solid rgba(255,255,255,0.3); border-radius: 8px;
        text-decoration: none; font-size: 13px; font-weight: 600;
        cursor: pointer; font-family: inherit;
        transition: all 0.15s;
    }
    .btn-head:hover { background: #fff; color: #1e40af; border-color: #fff; text-decoration: none; }
    .btn-head.primary { background: #fff; color: #1e40af; }

    .card { background: #fff; padding: 0; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

    /* ============ TABS ============ */
    .tabs {
        display: flex; border-bottom: 2px solid #e2e8f0;
        background: #f8fafc; overflow-x: auto;
    }
    .tab-btn {
        padding: 16px 26px; background: transparent; border: none;
        border-bottom: 3px solid transparent;
        font-size: 14px; font-weight: 600; color: #64748b;
        cursor: pointer; font-family: inherit; white-space: nowrap;
        transition: all 0.15s;
        display: inline-flex; align-items: center; gap: 8px; text-decoration: none;
    }
    .tab-btn:hover { color: #1e40af; background: #f1f5f9; text-decoration: none; }
    .tab-btn.active { color: #1e40af; border-bottom-color: #1e40af; background: #fff; }
    .tab-btn .count {
        background: #e2e8f0; color: #475569;
        padding: 2px 8px; border-radius: 10px;
        font-size: 11px; font-weight: 700;
    }
    .tab-btn.active .count { background: #dbeafe; color: #1e40af; }

    /* ============ TAB CONTENT ============ */
    .tab-content {
        padding: 30px 40px 40px 40px;
        display: none;
    }
    .tab-content.active { display: block; }

    /* ============ OVERVIEW ============ */
    .alert-custom {
        padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;
        font-size: 13px; border-left: 4px solid;
    }
    .alert-custom.success { background: #d1fae5; border-color: #059669; color: #065f46; }
    .alert-custom.danger  { background: #fee2e2; border-color: #dc2626; color: #991b1b; }

    .summary-bar {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr));
        gap: 12px; margin-bottom: 22px;
    }
    .summary-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 16px; }
    .summary-box .lbl {
        font-size: 10px; color: #64748b; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px;
    }
    .summary-box .val { font-family: 'SF Mono','Monaco',monospace; font-size: 18px; font-weight: 700; }
    .summary-box.blue { border-left: 4px solid #1e40af; }
    .summary-box.amber { border-left: 4px solid #f59e0b; }
    .summary-box.ok { background: #ecfdf5; border-left: 4px solid #059669; }
    .summary-box.bad { background: #fef2f2; border-left: 4px solid #dc2626; }

    .recon-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    @media (max-width: 1100px) { .recon-grid { grid-template-columns: 1fr; } }

    .panel { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; }
    .panel-head {
        padding: 14px 18px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;
        display: flex; justify-content: space-between; align-items: center;
    }
    .panel-head h3 {
        margin: 0; font-size: 14px; font-weight: 800; color: #0f172a;
        text-transform: uppercase; letter-spacing: 1px;
    }
    .panel-head .meta { font-size: 12px; color: #64748b; }

    table.mini { width: 100%; border-collapse: collapse; }
    table.mini thead th {
        background: #f1f5f9; font-size: 10px; color: #64748b; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px;
        padding: 8px 10px; text-align: left; border-bottom: 1px solid #e2e8f0;
    }
    table.mini tbody td {
        padding: 9px 10px; font-size: 12px;
        border-bottom: 1px solid #f1f5f9; vertical-align: middle;
    }
    table.mini tbody tr:hover td { background: #f8fafc; }
    .num { font-family: 'SF Mono','Monaco',monospace; text-align: right; }

    .tag { display: inline-block; padding: 2px 7px; border-radius: 8px; font-size: 9px; font-weight: 700; text-transform: uppercase; }
    .tag.matched { background: #d1fae5; color: #065f46; }
    .tag.outstanding { background: #fef3c7; color: #92400e; }
    .tag.adjusted { background: #dbeafe; color: #1e40af; }
    .tag.unmatched { background: #fee2e2; color: #991b1b; }

    .row-matched td { background: #f0fdf4 !important; }
    .row-adjusted td { background: #eff6ff !important; }
    .row-outstanding td { background: #fffbeb !important; }

    .mini-btn {
        display: inline-flex; align-items: center; gap: 3px;
        padding: 4px 9px; border-radius: 5px;
        font-size: 11px; font-weight: 700;
        text-decoration: none; cursor: pointer;
        border: 1px solid; font-family: inherit;
        text-transform: uppercase; margin-right: 3px;
    }
    .mini-btn-info { background: #eff6ff; color: #1e40af; border-color: #bfdbfe; }
    .mini-btn-info:hover { background: #dbeafe; text-decoration: none; color: #1e40af; }
    .mini-btn-warn { background: #fffbeb; color: #92400e; border-color: #fcd34d; }
    .mini-btn-warn:hover { background: #fef3c7; text-decoration: none; color: #92400e; }
    .mini-btn-danger { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
    .mini-btn-danger:hover { background: #fee2e2; text-decoration: none; color: #991b1b; }
    .mini-btn-ok { background: #d1fae5; color: #065f46; border-color: #a7f3d0; }
    .mini-btn-ok:hover { background: #a7f3d0; text-decoration: none; color: #065f46; }

    /* ============ FORM (used inside tabs) ============ */
    .form-section { margin-bottom: 24px; }
    .form-section-title {
        font-size: 12px; font-weight: 800; color: #64748b;
        text-transform: uppercase; letter-spacing: 2px;
        margin: 0 0 14px 0; padding-bottom: 8px;
        border-bottom: 1px solid #e2e8f0;
    }
    .field { margin-bottom: 16px; }
    .field label {
        display: block; font-size: 12px; font-weight: 700; color: #334155;
        text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px;
    }
    .field .required { color: #dc2626; }
    .field input, .field select, .field textarea {
        width: 100%; height: 44px; padding: 10px 14px;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 15px; font-family: inherit; background: #fff; color: #0f172a;
    }
    .field textarea {
        height: auto; min-height: 220px;
        resize: vertical; font-family: 'SF Mono', monospace; font-size: 13px;
    }
    .field input:focus, .field select:focus, .field textarea:focus {
        outline: none; border-color: #1e40af;
        box-shadow: 0 0 0 3px rgba(30,64,175,0.1);
    }
    .field .help { font-size: 12px; color: #64748b; margin-top: 5px; }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    @media (max-width: 700px) { .grid-2 { grid-template-columns: 1fr; } }

    .form-actions {
        display: flex;
        justify-content: flex-end;
        gap: 12px;
        margin-top: 30px;
        padding-top: 24px;
        border-top: 1px solid #e2e8f0;
        flex-wrap: wrap;
    }
    .action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        height: 48px;
        min-width: 160px;
        padding: 0 26px;
        border-radius: 8px;
        font-family: inherit;
        font-size: 15px;
        font-weight: 600;
        line-height: 1;
        text-decoration: none;
        cursor: pointer;
        border: 1px solid transparent;
        white-space: nowrap;
        transition: background 0.15s, box-shadow 0.15s, transform 0.1s;
    }
    .action-btn-cancel {
        background: #f1f5f9;
        color: #334155;
        border-color: #cbd5e1;
    }
    .action-btn-cancel:hover {
        background: #e2e8f0;
        color: #1e293b;
        text-decoration: none;
    }
    .action-btn-primary {
        background: #1e40af;
        color: #ffffff;
        border-color: #1e40af;
    }
    .action-btn-primary:hover {
        background: #1e3a8a;
        border-color: #1e3a8a;
        color: #ffffff;
        text-decoration: none;
        box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
    }
    .action-btn-primary:active { transform: translateY(1px); }

    .info-banner {
        background: #eff6ff; border: 1px solid #bfdbfe; border-left: 4px solid #1e40af;
        padding: 14px 18px; border-radius: 10px; font-size: 13px;
        color: #1e3a8a; line-height: 1.6; margin-bottom: 24px;
    }
    .info-banner code { background: #dbeafe; padding: 2px 6px; border-radius: 4px; font-size: 12px; }

    .line-preview {
        background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;
        padding: 14px 18px; margin-bottom: 20px;
        font-size: 14px; color: #0f172a;
    }
    .line-preview .label {
        font-size: 11px; color: #64748b; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px;
    }
    .line-preview .value { font-size: 15px; font-weight: 600; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <h1>Reconcile #<?= (int)$recon['id'] ?> — <?= htmlspecialchars($recon['account_code']) ?></h1>
            <div class="sub">
                <?= htmlspecialchars($recon['account_name']) ?>
                &middot; Statement date: <strong><?= htmlspecialchars($recon['statement_date']) ?></strong>
                &middot;
                <?php if ($recon['status'] === 'DRAFT'): ?>
                    <span style="background:#fef3c7; color:#92400e; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700;">DRAFT</span>
                <?php else: ?>
                    <span style="background:#d1fae5; color:#065f46; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700;">COMPLETED</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="actions">
            <a href="gl_bank_recon.php" class="btn-head">
                <span class="glyphicon glyphicon-arrow-left"></span> List
            </a>
            <button type="button" class="btn-head" onclick="printRecon()">
                <span class="glyphicon glyphicon-print"></span> Print
            </button>
            <?php if ($isDraft): ?>
            <form method="post" style="display:inline;" onsubmit="return confirm('Complete this reconciliation?');">
                <input type="hidden" name="action" value="complete">
                <button type="submit" class="btn-head primary">
                    <span class="glyphicon glyphicon-ok"></span> Complete
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">

        <div class="tabs">
            <a href="?id=<?= (int)$reconId ?>&tab=overview" class="tab-btn <?= $tab === 'overview' ? 'active' : '' ?>">
                <span class="glyphicon glyphicon-th-list"></span> Overview
            </a>
            <a href="?id=<?= (int)$reconId ?>&tab=add" class="tab-btn <?= $tab === 'add' ? 'active' : '' ?>">
                <span class="glyphicon glyphicon-plus"></span> Add Line
            </a>
            <a href="?id=<?= (int)$reconId ?>&tab=bulk" class="tab-btn <?= $tab === 'bulk' ? 'active' : '' ?>">
                <span class="glyphicon glyphicon-paste"></span> Bulk Paste
            </a>
            <a href="?id=<?= (int)$reconId ?>&tab=match" class="tab-btn <?= $tab === 'match' ? 'active' : '' ?>">
                <span class="glyphicon glyphicon-link"></span> Match
                <?php if ($unmatchedStatement > 0): ?>
                    <span class="count"><?= $unmatchedStatement ?></span>
                <?php endif; ?>
            </a>
            <a href="?id=<?= (int)$reconId ?>&tab=adjust" class="tab-btn <?= $tab === 'adjust' ? 'active' : '' ?>">
                <span class="glyphicon glyphicon-check"></span> Adjust
            </a>
        </div>

        <!-- ============================================================
             TAB: OVERVIEW
             ============================================================ -->
        <div class="tab-content <?= $tab === 'overview' ? 'active' : '' ?>">

            <?php if ($msg && $tab === 'overview'): ?>
                <div class="alert-custom <?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div>
            <?php endif; ?>

            <div class="summary-bar">
                <div class="summary-box blue">
                    <div class="lbl">Bank Closing Balance</div>
                    <div class="val"><?= number_format((float)$recon['closing_balance'], 2) ?></div>
                </div>
                <div class="summary-box blue">
                    <div class="lbl">Our Book Balance</div>
                    <div class="val"><?= number_format((float)$recon['book_balance'], 2) ?></div>
                </div>
                <div class="summary-box amber">
                    <div class="lbl">Unmatched Statement</div>
                    <div class="val"><?= $unmatchedStatement ?></div>
                </div>
                <div class="summary-box amber">
                    <div class="lbl">Unmatched Ledger</div>
                    <div class="val"><?= $unmatchedLedger ?></div>
                </div>
                <div class="summary-box <?= abs((float)$recon['difference']) < 0.01 ? 'ok' : 'bad' ?>">
                    <div class="lbl">Difference</div>
                    <div class="val" style="color:<?= abs((float)$recon['difference']) < 0.01 ? '#047857' : '#b91c1c' ?>;">
                        <?= number_format((float)$recon['difference'], 2) ?>
                    </div>
                </div>
            </div>

            <div class="recon-grid">

                <div class="panel">
                    <div class="panel-head">
                        <h3>Bank Statement Lines</h3>
                        <span class="meta"><?= count($stmtLines) ?> line(s)</span>
                    </div>
                    <?php if (empty($stmtLines)): ?>
                        <div style="padding:30px; text-align:center; color:#94a3b8; font-style:italic; font-size:13px;">
                            No statement lines yet. Use the <strong>Add Line</strong> or <strong>Bulk Paste</strong> tab.
                        </div>
                    <?php else: ?>
                        <div style="overflow:auto; max-height:600px;">
                            <table class="mini">
                                <thead>
                                    <tr>
                                        <th style="width:85px;">Date</th>
                                        <th>Description</th>
                                        <th class="num" style="width:80px;">Debit</th>
                                        <th class="num" style="width:80px;">Credit</th>
                                        <th style="width:100px;">Status</th>
                                        <th style="width:150px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($stmtLines as $s):
                                    $matched = !empty($s['matched_line_id']);
                                    $adjusted = !empty($s['adjustment_txn_id']);
                                    $rowCls = $matched ? 'row-matched' : ($adjusted ? 'row-adjusted' : '');
                                ?>
                                    <tr class="<?= $rowCls ?>">
                                        <td><?= htmlspecialchars($s['line_date']) ?></td>
                                        <td><?= htmlspecialchars($s['description']) ?></td>
                                        <td class="num"><?= $s['debit'] > 0 ? number_format((float)$s['debit'], 2) : '' ?></td>
                                        <td class="num"><?= $s['credit'] > 0 ? number_format((float)$s['credit'], 2) : '' ?></td>
                                        <td>
                                            <?php if ($matched): ?><span class="tag matched">Matched</span>
                                            <?php elseif ($adjusted): ?><span class="tag adjusted">Adjusted</span>
                                            <?php else: ?><span class="tag unmatched">Unmatched</span><?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($matched): ?>
                                                <a href="?id=<?= (int)$reconId ?>&unmatch=<?= (int)$s['id'] ?>" class="mini-btn mini-btn-warn">Unmatch</a>
                                            <?php elseif ($adjusted): ?>
                                                <a href="gl_transaction_view.php?id=<?= (int)$s['adjustment_txn_id'] ?>" target="_blank" class="mini-btn mini-btn-info">View TXN</a>
                                            <?php elseif ($isDraft): ?>
                                                <a href="?id=<?= (int)$reconId ?>&tab=match&stmt=<?= (int)$s['id'] ?>" class="mini-btn mini-btn-info">Match</a>
                                                <a href="?id=<?= (int)$reconId ?>&tab=adjust&stmt=<?= (int)$s['id'] ?>" class="mini-btn mini-btn-ok">Adjust</a>
                                            <?php endif; ?>
                                            <?php if ($isDraft): ?>
                                                <a href="?id=<?= (int)$reconId ?>&del_line=<?= (int)$s['id'] ?>" class="mini-btn mini-btn-danger"
                                                   onclick="return confirm('Delete this line?');">✕</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-head">
                        <h3>Ledger — <?= htmlspecialchars($recon['account_code']) ?></h3>
                        <span class="meta"><?= count($ledgerLines) ?> line(s)</span>
                    </div>
                    <?php if (empty($ledgerLines)): ?>
                        <div style="padding:30px; text-align:center; color:#94a3b8; font-style:italic; font-size:13px;">
                            No ledger activity in this period.
                        </div>
                    <?php else: ?>
                        <div style="overflow:auto; max-height:600px;">
                            <table class="mini">
                                <thead>
                                    <tr>
                                        <th style="width:85px;">Date</th>
                                        <th>Description</th>
                                        <th class="num" style="width:80px;">Debit</th>
                                        <th class="num" style="width:80px;">Credit</th>
                                        <th style="width:100px;">Status</th>
                                        <th style="width:110px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($ledgerLines as $l):
                                    $lid = (int)$l['id'];
                                    $mark = $marks[$lid] ?? null;
                                    $rowCls = '';
                                    if ($mark && $mark['mark_type'] === 'MATCHED') $rowCls = 'row-matched';
                                    elseif ($mark && $mark['mark_type'] === 'OUTSTANDING') $rowCls = 'row-outstanding';
                                ?>
                                    <tr class="<?= $rowCls ?>">
                                        <td><?= htmlspecialchars($l['entry_date']) ?></td>
                                        <td><?= htmlspecialchars($l['description']) ?></td>
                                        <td class="num"><?= $l['debit'] > 0 ? number_format((float)$l['debit'], 2) : '' ?></td>
                                        <td class="num"><?= $l['credit'] > 0 ? number_format((float)$l['credit'], 2) : '' ?></td>
                                        <td>
                                            <?php if (!$mark): ?><span class="tag unmatched">Unmatched</span>
                                            <?php elseif ($mark['mark_type'] === 'MATCHED'): ?><span class="tag matched">Matched</span>
                                            <?php else: ?><span class="tag outstanding">Outstanding</span><?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($mark && $mark['mark_type'] === 'MATCHED'): ?>
                                                <a href="?id=<?= (int)$reconId ?>&unmatch=<?= (int)$mark['matched_statement_id'] ?>" class="mini-btn mini-btn-warn">Unmatch</a>
                                            <?php elseif ($mark && $mark['mark_type'] === 'OUTSTANDING'): ?>
                                                <a href="?id=<?= (int)$reconId ?>&unmark=<?= (int)$lid ?>" class="mini-btn mini-btn-warn">Unmark</a>
                                            <?php elseif ($isDraft): ?>
                                                <form method="post" style="display:inline;">
                                                    <input type="hidden" name="action" value="mark_outstanding">
                                                    <input type="hidden" name="ledger_id" value="<?= (int)$lid ?>">
                                                    <button type="submit" class="mini-btn mini-btn-info">Outstanding</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>

        <!-- ============================================================
             TAB: ADD LINE
             ============================================================ -->
        <div class="tab-content <?= $tab === 'add' ? 'active' : '' ?>">

            <?php if ($msg && $tab === 'add'): ?>
                <div class="alert-custom <?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div>
            <?php endif; ?>

            <div class="info-banner">
                <strong>Add a single line</strong> from the bank statement. Every debit is money that left your account, every credit is money that entered.
            </div>

            <form method="post">
                <input type="hidden" name="action" value="add_line">

                <div class="form-section">
                    <div class="form-section-title">Line Details</div>

                    <div class="grid-2">
                        <div class="field">
                            <label>Date <span class="required">*</span></label>
                            <input type="date" name="line_date" required value="<?= htmlspecialchars($recon['statement_date']) ?>">
                        </div>
                        <div class="field">
                            <label>Reference</label>
                            <input type="text" name="reference" placeholder="Cheque # / ref">
                        </div>
                    </div>

                    <div class="field">
                        <label>Description <span class="required">*</span></label>
                        <input type="text" name="description" required placeholder="e.g. Fee deposit / Cheque payment">
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title">Amount</div>
                    <div class="grid-2">
                        <div class="field">
                            <label>Debit (bank withdrawal)</label>
                            <input type="number" name="debit" step="0.01" value="0.00">
                            <div class="help">Money LEFT your account</div>
                        </div>
                        <div class="field">
                            <label>Credit (bank deposit)</label>
                            <input type="number" name="credit" step="0.01" value="0.00">
                            <div class="help">Money ENTERED your account</div>
                        </div>
                    </div>
                    <div class="help" style="font-size:12px; color:#64748b;">
                        Fill in only one of these. Leave the other at 0.00.
                    </div>
                </div>

                <div class="form-actions">
                    <a href="?id=<?= (int)$reconId ?>&tab=overview" class="action-btn action-btn-cancel">Cancel</a>
                    <button type="submit" class="action-btn action-btn-primary">
                        <span class="glyphicon glyphicon-plus"></span> Add Line
                    </button>
                </div>
            </form>
        </div>

        <!-- ============================================================
             TAB: BULK PASTE
             ============================================================ -->
        <div class="tab-content <?= $tab === 'bulk' ? 'active' : '' ?>">

            <?php if ($msg && $tab === 'bulk'): ?>
                <div class="alert-custom <?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div>
            <?php endif; ?>

            <div class="info-banner">
                <strong>Paste multiple rows at once.</strong>
                Copy directly from Excel (tab-separated) or CSV. A header row is ignored automatically.
                <br><br>
                <strong>4 columns:</strong> date, description, ref, single amount (+ deposit / − withdrawal)<br>
                <strong>5 columns:</strong> date, description, ref, debit, credit
            </div>

            <form method="post">
                <input type="hidden" name="action" value="bulk_paste">

                <div class="form-section">
                    <div class="form-section-title">Paste Statement Rows</div>
                    <div class="field">
                        <textarea name="bulk_data" placeholder="2026-02-05&#9;Fee deposit&#9;&#9;0&#9;250000.00
2026-02-07&#9;Cheque payment&#9;001234&#9;45000.00&#9;0
2026-02-08&#9;Service charges&#9;&#9;500.00&#9;0"></textarea>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="?id=<?= (int)$reconId ?>&tab=overview" class="action-btn action-btn-cancel">Cancel</a>
                    <button type="submit" class="action-btn action-btn-primary">
                        <span class="glyphicon glyphicon-import"></span> Import Lines
                    </button>
                </div>
            </form>
        </div>

        <!-- ============================================================
             TAB: MATCH
             ============================================================ -->
        <div class="tab-content <?= $tab === 'match' ? 'active' : '' ?>">

            <?php if ($msg && $tab === 'match'): ?>
                <div class="alert-custom <?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div>
            <?php endif; ?>

            <div class="info-banner">
                <strong>Match a bank statement line to its ledger entry.</strong>
                Pick the bank line on the left, pick the ledger line on the right — they should have the same amount and date.
            </div>

            <?php if (empty($pendingStmt)): ?>
                <div style="padding:40px; text-align:center; color:#94a3b8; font-style:italic;">
                    No unmatched bank statement lines. Add some first via the <strong>Add Line</strong> or <strong>Bulk Paste</strong> tab.
                </div>
            <?php elseif (empty($availableLedger)): ?>
                <div style="padding:40px; text-align:center; color:#94a3b8; font-style:italic;">
                    All ledger lines in the period are already matched or marked outstanding.
                </div>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="action" value="match">

                    <div class="form-section">
                        <div class="form-section-title">Bank Statement Line</div>
                        <div class="field">
                            <label>Choose the bank line to match <span class="required">*</span></label>
                            <select name="statement_id" required>
                                <option value="">— Select bank line —</option>
                                <?php foreach ($pendingStmt as $s):
                                    $amt = $s['debit'] > 0
                                        ? '− ' . number_format((float)$s['debit'], 2)
                                        : '+ ' . number_format((float)$s['credit'], 2);
                                ?>
                                    <option value="<?= (int)$s['id'] ?>" <?= $preselectStmt === (int)$s['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['line_date']) ?> ·
                                        <?= htmlspecialchars(substr($s['description'], 0, 50)) ?> ·
                                        <?= $amt ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title">Ledger Entry</div>
                        <div class="field">
                            <label>Choose the ledger line <span class="required">*</span></label>
                            <select name="ledger_id" required>
                                <option value="">— Select ledger line —</option>
                                <?php foreach ($availableLedger as $l):
                                    $amt = $l['debit'] > 0
                                        ? '− ' . number_format((float)$l['debit'], 2)
                                        : '+ ' . number_format((float)$l['credit'], 2);
                                ?>
                                    <option value="<?= (int)$l['id'] ?>" <?= $preselectLedger === (int)$l['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($l['entry_date']) ?> ·
                                        <?= htmlspecialchars(substr($l['description'], 0, 50)) ?> ·
                                        <?= $amt ?> (TXN #<?= (int)$l['txn_id'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="?id=<?= (int)$reconId ?>&tab=overview" class="action-btn action-btn-cancel">Cancel</a>
                        <button type="submit" class="action-btn action-btn-primary">
                            <span class="glyphicon glyphicon-link"></span> Match
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <!-- ============================================================
             TAB: ADJUST
             ============================================================ -->
        <div class="tab-content <?= $tab === 'adjust' ? 'active' : '' ?>">

            <?php if ($msg && $tab === 'adjust'): ?>
                <div class="alert-custom <?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div>
            <?php endif; ?>

            <div class="info-banner">
                <strong>Post an adjustment.</strong>
                For items on the bank statement that don't exist in your ledger — bank charges, interest earned, WHT deducted, direct deposits.
                This creates a new journal entry so the ledger reflects the bank's reality.
            </div>

            <?php if (empty($pendingStmt)): ?>
                <div style="padding:40px; text-align:center; color:#94a3b8; font-style:italic;">
                    No unmatched statement lines to adjust. Add some first via the <strong>Add Line</strong> or <strong>Bulk Paste</strong> tab.
                </div>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="action" value="adjust">

                    <div class="form-section">
                        <div class="form-section-title">Statement Line to Adjust</div>
                        <div class="field">
                            <label>Which statement line? <span class="required">*</span></label>
                            <select name="statement_id" id="adj_statement_id" required onchange="updateAdjustHint()">
                                <option value="">— Select statement line —</option>
                                <?php foreach ($pendingStmt as $s):
                                    $amt = $s['debit'] > 0
                                        ? 'Debit ' . number_format((float)$s['debit'], 2)
                                        : 'Credit ' . number_format((float)$s['credit'], 2);
                                    $type = $s['debit'] > 0 ? 'debit' : 'credit';
                                ?>
                                    <option value="<?= (int)$s['id'] ?>"
                                            data-type="<?= $type ?>"
                                            data-amount="<?= $s['debit'] > 0 ? number_format((float)$s['debit'], 2, '.', '') : number_format((float)$s['credit'], 2, '.', '') ?>"
                                            <?= $preselectStmt === (int)$s['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['line_date']) ?> ·
                                        <?= htmlspecialchars(substr($s['description'], 0, 50)) ?> ·
                                        <?= $amt ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title">Journal Entry</div>

                        <div class="line-preview" id="adj_preview">
                            <div class="label">Effect</div>
                            <div class="value" id="adj_preview_text" style="color:#64748b; font-style:italic;">Select a statement line above.</div>
                        </div>

                        <input type="hidden" name="adjust_type" id="adj_type" value="debit">

                        <div class="field">
                            <label>Amount <span class="required">*</span></label>
                            <input type="number" name="adjust_amount" id="adj_amount" step="0.01" required placeholder="0.00">
                        </div>

                        <div class="field">
                            <label>Counter-account <span class="required">*</span></label>
                            <select name="other_account" required>
                                <option value="">— Select account —</option>
                                <?php foreach ($adjustAccounts as $a):
                                    if (!$a['is_postable']) continue;
                                ?>
                                    <option value="<?= (int)$a['id'] ?>" <?= (strpos($a['name'], 'Bank Charge') !== false) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($a['code'] . ' — ' . $a['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="help">
                                <strong>Bank charges</strong> → an Expense account (default).<br>
                                <strong>Interest earned</strong> → a Revenue account.<br>
                                <strong>WHT deducted</strong> → an Asset account.
                            </div>
                        </div>

                        <div class="field">
                            <label>Memo (optional)</label>
                            <input type="text" name="adjust_memo" placeholder="Description for the journal entry">
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="?id=<?= (int)$reconId ?>&tab=overview" class="action-btn action-btn-cancel">Cancel</a>
                        <button type="submit" class="action-btn action-btn-primary">
                            <span class="glyphicon glyphicon-check"></span> Post Adjustment
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
function printRecon() {
    window.open('gl_bank_recon_print.php?id=<?= (int)$recon['id'] ?>', '_blank');
}

function updateAdjustHint() {
    var sel = document.getElementById('adj_statement_id');
    if (!sel) return;
    var opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) {
        document.getElementById('adj_preview_text').innerHTML = 'Select a statement line above.';
        document.getElementById('adj_type').value = 'debit';
        document.getElementById('adj_amount').value = '';
        return;
    }
    var type = opt.getAttribute('data-type');
    var amt = opt.getAttribute('data-amount');

    document.getElementById('adj_type').value = type;
    document.getElementById('adj_amount').value = amt;

    if (type === 'debit') {
        document.getElementById('adj_preview_text').innerHTML =
            'Money <strong>left</strong> the bank → post <strong>Dr (counter-account) / Cr Bank</strong>';
    } else {
        document.getElementById('adj_preview_text').innerHTML =
            'Money <strong>entered</strong> the bank → post <strong>Dr Bank / Cr (counter-account)</strong>';
    }
}
document.addEventListener('DOMContentLoaded', function(){
    updateAdjustHint();
});
</script>

</body>
</html>