<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

$reconId = (int)($_GET['id'] ?? 0);
if ($reconId <= 0) { http_response_code(400); die('Missing id.'); }

$stmt = $conn->prepare("
    SELECT r.*, a.code AS account_code, a.name AS account_name,
           u.username AS created_by_name, cu.username AS completed_by_name
    FROM bank_reconciliations r
    LEFT JOIN gl_accounts a ON a.id = r.gl_account_id
    LEFT JOIN users u ON u.id = r.created_by
    LEFT JOIN users cu ON cu.id = r.completed_by
    WHERE r.id = ?
");
$stmt->bind_param("i", $reconId);
$stmt->execute();
$recon = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$recon) { http_response_code(404); die('Reconciliation not found.'); }

$stmtLines = $conn->query("
    SELECT s.*, l.debit AS l_debit, l.credit AS l_credit,
           t.entry_date AS l_date, t.description AS l_desc, t.id AS l_txn_id
    FROM bank_statement_lines s
    LEFT JOIN gl_journal_lines l ON l.id = s.matched_line_id
    LEFT JOIN gl_transactions t ON t.id = l.transaction_id
    WHERE s.reconciliation_id = $reconId
    ORDER BY s.line_date ASC, s.id ASC
")->fetch_all(MYSQLI_ASSOC);

$outstanding = $conn->query("
    SELECT l.id, l.debit, l.credit, t.entry_date, t.description, t.id AS txn_id
    FROM bank_recon_ledger_marks m
    JOIN gl_journal_lines l ON l.id = m.ledger_line_id
    JOIN gl_transactions t ON t.id = l.transaction_id
    WHERE m.reconciliation_id = $reconId AND m.mark_type = 'OUTSTANDING'
    ORDER BY t.entry_date ASC
")->fetch_all(MYSQLI_ASSOC);

$sumStmtDebit = 0.0; $sumStmtCredit = 0.0;
foreach ($stmtLines as $s) { $sumStmtDebit += (float)$s['debit']; $sumStmtCredit += (float)$s['credit']; }

$sumOutDebit = 0.0; $sumOutCredit = 0.0;
foreach ($outstanding as $o) { $sumOutDebit += (float)$o['debit']; $sumOutCredit += (float)$o['credit']; }

$adjustedBank = (float)$recon['closing_balance'] + $sumOutCredit - $sumOutDebit;
$adjustedBook = (float)$recon['book_balance'];
$difference   = $adjustedBank - $adjustedBook;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Bank Reconciliation #<?= (int)$recon['id'] ?></title>
<style>
    * { box-sizing: border-box; }
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        margin: 0; padding: 20px;
        color: #1e293b;
        background: #eef1f5;
    }

    /* On-screen toolbar — hidden when printing */
    .screen-actions {
        max-width: 900px;
        margin: 0 auto 16px auto;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }
    .screen-actions button {
        padding: 10px 22px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        font-family: inherit;
        border: none;
        transition: all 0.15s;
    }
    .screen-actions .btn-print {
        background: #1e40af; color: #fff;
    }
    .screen-actions .btn-print:hover { background: #1e3a8a; }
    .screen-actions .btn-close {
        background: #f1f5f9; color: #334155;
        border: 1px solid #e2e8f0;
    }
    .screen-actions .btn-close:hover { background: #e2e8f0; }

    .sheet {
        max-width: 900px;
        margin: 0 auto;
        background: #fff;
        padding: 40px 50px;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }

    .head {
        border-bottom: 2px solid #1e40af;
        padding-bottom: 14px;
        margin-bottom: 22px;
        display: flex; justify-content: space-between; align-items: flex-start;
        gap: 20px; flex-wrap: wrap;
    }
    .head h1 { margin: 0 0 4px 0; font-size: 22px; color: #1e3a8a; }
    .head .doc { font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 2px; font-weight: 700; }
    .head .meta { text-align: right; font-size: 13px; color: #475569; }
    .head .meta strong { color: #0f172a; }

    .supplier-block {
        background: #f8fafc; border: 1px solid #e2e8f0;
        border-radius: 8px; padding: 14px 18px;
        margin-bottom: 22px;
        display: flex; justify-content: space-between; gap: 20px;
        flex-wrap: wrap;
    }
    .supplier-block .name { font-size: 16px; font-weight: 700; }
    .supplier-block .sub { font-size: 12px; color: #64748b; margin-top: 3px; }

    .sec-title {
        font-size: 12px; font-weight: 800; color: #1e3a8a;
        text-transform: uppercase; letter-spacing: 2px;
        margin: 24px 0 10px 0;
    }

    .stmt {
        width: 100%; border-collapse: collapse;
        border: 1px solid #cbd5e1;
        margin-bottom: 20px;
    }
    .stmt thead th {
        background: #1e40af; color: #fff;
        font-size: 11px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px;
        padding: 8px 10px; text-align: left;
    }
    .stmt tbody td {
        padding: 8px 10px;
        font-size: 12px;
        border-bottom: 1px solid #e2e8f0;
    }
    .stmt tbody tr:nth-child(even) td { background: #f8fafc; }
    .stmt .num {
        font-family: 'SF Mono','Monaco',monospace;
        text-align: right;
        font-variant-numeric: tabular-nums;
    }
    .stmt tfoot td {
        padding: 10px;
        font-weight: 700;
        background: #f1f5f9;
        border-top: 2px solid #cbd5e1;
        font-size: 12px;
    }

    .summary {
        width: 100%; border-collapse: collapse;
        margin-top: 8px;
    }
    .summary td {
        padding: 7px 12px;
        font-size: 13px;
        border-bottom: 1px solid #e2e8f0;
    }
    .summary td.num {
        text-align: right;
        font-family: 'SF Mono','Monaco',monospace;
        font-variant-numeric: tabular-nums;
    }
    .summary tr.total td {
        font-weight: 800;
        font-size: 14px;
        border-top: 2px solid #1e40af;
        border-bottom: none;
        padding-top: 12px;
    }
    .summary tr.balanced td { color: #047857; }
    .summary tr.unbalanced td { color: #b91c1c; }

    .sign {
        margin-top: 40px;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 60px;
    }
    .sign .line {
        border-top: 1px solid #0f172a;
        margin-top: 40px;
        padding-top: 6px;
        font-size: 12px;
        text-align: center;
        color: #475569;
    }

    .note {
        margin-top: 20px;
        padding: 12px 16px;
        background: #f8fafc;
        border-left: 3px solid #cbd5e1;
        font-size: 11px;
        color: #64748b;
        border-radius: 4px;
    }

    @media print {
        @page { size: A4 portrait; margin: 12mm 10mm; }
        body { background: #fff; padding: 0; }
        .screen-actions { display: none !important; }
        .sheet { box-shadow: none; padding: 0; max-width: none; border-radius: 0; }
        .stmt thead th { background: #333 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .stmt tbody tr:nth-child(even) td { background: #f5f5f5 !important; -webkit-print-color-adjust: exact; }
    }
</style>
</head>
<body>

<!-- Screen-only action bar (hidden when printing) -->
<div class="screen-actions">
    <button type="button" class="btn-print" onclick="window.print()">
        🖨️ Print this page
    </button>
    <button type="button" class="btn-close" onclick="window.close()">
        Close
    </button>
</div>

<div class="sheet">

    <div class="head">
        <div>
            <h1>School Management System</h1>
            <div class="doc">Bank Reconciliation Statement</div>
        </div>
        <div class="meta">
            <div>Reconciliation #<strong><?= (int)$recon['id'] ?></strong></div>
            <div>Statement Date: <strong><?= htmlspecialchars($recon['statement_date']) ?></strong></div>
            <?php if ($recon['statement_start']): ?>
                <div>Period: <?= htmlspecialchars($recon['statement_start']) ?> → <?= htmlspecialchars($recon['statement_date']) ?></div>
            <?php endif; ?>
            <div style="font-size:11px; color:#94a3b8; margin-top:6px;">
                <?= htmlspecialchars($recon['status']) ?> · <?= date('d M Y, g:i A') ?>
            </div>
        </div>
    </div>

    <div class="supplier-block">
        <div>
            <div class="name"><?= htmlspecialchars($recon['account_name']) ?></div>
            <div class="sub">Account <?= htmlspecialchars($recon['account_code']) ?></div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:11px; color:#94a3b8; font-weight:700; text-transform:uppercase; letter-spacing:1px;">Status</div>
            <div style="font-size:16px; font-weight:800; color:<?= abs($difference) < 0.01 ? '#047857' : '#b91c1c' ?>;">
                <?= abs($difference) < 0.01 ? 'BALANCED' : 'DIFFERENCE: ' . number_format($difference, 2) ?>
            </div>
        </div>
    </div>

    <div class="sec-title">Bank Statement Lines</div>
    <table class="stmt">
        <thead>
            <tr>
                <th style="width:90px;">Date</th>
                <th>Description</th>
                <th style="width:80px;">Ref</th>
                <th class="num" style="width:100px;">Debit</th>
                <th class="num" style="width:100px;">Credit</th>
                <th style="width:140px;">Matched?</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($stmtLines)): ?>
                <tr><td colspan="6" style="text-align:center; padding:20px; color:#94a3b8;">No lines</td></tr>
            <?php else: foreach ($stmtLines as $s): ?>
                <tr>
                    <td><?= htmlspecialchars($s['line_date']) ?></td>
                    <td><?= htmlspecialchars($s['description']) ?></td>
                    <td><?= htmlspecialchars($s['reference']) ?></td>
                    <td class="num"><?= $s['debit'] > 0 ? number_format((float)$s['debit'], 2) : '' ?></td>
                    <td class="num"><?= $s['credit'] > 0 ? number_format((float)$s['credit'], 2) : '' ?></td>
                    <td>
                        <?php if ($s['matched_line_id']): ?>
                            ✓ TXN #<?= (int)$s['l_txn_id'] ?> · <?= htmlspecialchars($s['l_date']) ?>
                        <?php elseif ($s['adjustment_txn_id']): ?>
                            ✓ Adjusted TXN #<?= (int)$s['adjustment_txn_id'] ?>
                        <?php else: ?>
                            <span style="color:#b91c1c;">Unmatched</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3">Totals</td>
                <td class="num"><?= number_format($sumStmtDebit, 2) ?></td>
                <td class="num"><?= number_format($sumStmtCredit, 2) ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <div class="sec-title">Outstanding Items (on our books, not yet on bank)</div>
    <table class="stmt">
        <thead>
            <tr>
                <th style="width:90px;">Date</th>
                <th>Description</th>
                <th class="num" style="width:100px;">Debit</th>
                <th class="num" style="width:100px;">Credit</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($outstanding)): ?>
                <tr><td colspan="4" style="text-align:center; padding:20px; color:#94a3b8;">None</td></tr>
            <?php else: foreach ($outstanding as $o): ?>
                <tr>
                    <td><?= htmlspecialchars($o['entry_date']) ?></td>
                    <td><?= htmlspecialchars($o['description']) ?> (TXN #<?= (int)$o['txn_id'] ?>)</td>
                    <td class="num"><?= $o['debit'] > 0 ? number_format((float)$o['debit'], 2) : '' ?></td>
                    <td class="num"><?= $o['credit'] > 0 ? number_format((float)$o['credit'], 2) : '' ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">Totals</td>
                <td class="num"><?= number_format($sumOutDebit, 2) ?></td>
                <td class="num"><?= number_format($sumOutCredit, 2) ?></td>
            </tr>
        </tfoot>
    </table>

    <div class="sec-title">Reconciliation</div>
    <table class="summary">
        <tr>
            <td>Balance per bank statement</td>
            <td class="num"><?= number_format((float)$recon['closing_balance'], 2) ?></td>
        </tr>
        <tr>
            <td>Add: Deposits in transit (outstanding credits)</td>
            <td class="num"><?= number_format($sumOutCredit, 2) ?></td>
        </tr>
        <tr>
            <td>Less: Outstanding cheques (outstanding debits)</td>
            <td class="num">(<?= number_format($sumOutDebit, 2) ?>)</td>
        </tr>
        <tr class="total">
            <td>Adjusted Bank Balance</td>
            <td class="num"><?= number_format($adjustedBank, 2) ?></td>
        </tr>
        <tr><td colspan="2" style="border:none; padding:6px 0;"></td></tr>
        <tr>
            <td>Balance per our books (account <?= htmlspecialchars($recon['account_code']) ?>)</td>
            <td class="num"><?= number_format($adjustedBook, 2) ?></td>
        </tr>
        <tr class="total <?= abs($difference) < 0.01 ? 'balanced' : 'unbalanced' ?>">
            <td>Difference (Adjusted Bank − Book)</td>
            <td class="num"><?= number_format($difference, 2) ?></td>
        </tr>
    </table>

    <div class="sign">
        <div>
            <div class="line">Prepared by — <?= htmlspecialchars($recon['created_by_name'] ?: '') ?></div>
        </div>
        <div>
            <div class="line">Approved by</div>
        </div>
    </div>

    <div class="note">
        <strong>Notes:</strong>
        <?php if (!empty($recon['notes'])): ?>
            <?= nl2br(htmlspecialchars($recon['notes'])) ?>
        <?php else: ?>
            A balanced reconciliation (Difference = 0) confirms the ledger agrees with the bank.
        <?php endif; ?>
    </div>

</div>

</body>
</html>