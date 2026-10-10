<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
$msg = ''; $msg_type = '';

/* ---------- Configurable account codes ---------- */
$CASH_CODE   = '1010';   // Cash in Hand
$SHORT_CODE  = '5940';   // Cash Short / Over

/* ---------- Look up accounts once ---------- */
$cashAccount  = gl_get_account_by_code($conn, $CASH_CODE);
$shortAccount = gl_get_account_by_code($conn, $SHORT_CODE);

/* ---------- Post a new count ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'post_count') {

    $countDate  = $_POST['count_date'] ?? date('Y-m-d');
    $shift      = $_POST['shift'] ?? 'full_day';
    $counted    = (float)($_POST['counted_amount'] ?? 0);
    $notes      = trim($_POST['notes'] ?? '');

    $validShifts = ['full_day', 'morning', 'evening'];
    if (!in_array($shift, $validShifts, true)) $shift = 'full_day';

    if (!$cashAccount) {
        $msg = 'Cash in Hand account (1010) not found.'; $msg_type = 'danger';
    } elseif (!$shortAccount) {
        $msg = 'Cash Short/Over account (' . $SHORT_CODE . ') not found. Please create it in the Chart of Accounts.'; $msg_type = 'danger';
    } elseif (!strtotime($countDate)) {
        $msg = 'Valid date required.'; $msg_type = 'danger';
    } else {
        // Ledger balance for 1010 as of the count date
        $ledgerBalance = gl_account_balance_signed($conn, (int)$cashAccount['id'], $countDate);
        $difference    = round($counted - $ledgerBalance, 2);

        $conn->begin_transaction();
        try {
            $txnId = null;

            // If difference is non-zero, post an adjustment
            if (abs($difference) >= 0.01) {
                $sessionId = gl_current_session_id($conn) ?: 1;

                // Case 1: counted > ledger → cash is OVER. We have more cash than we recorded.
                //   Dr Cash (1010)   +difference
                //   Cr Short/Over    +difference
                // Case 2: counted < ledger → cash is SHORT. We have less than we recorded.
                //   Dr Short/Over    -difference (positive)
                //   Cr Cash (1010)   -difference
                if ($difference > 0) {
                    $lines = [
                        ['account_id' => (int)$cashAccount['id'],  'debit' => $difference, 'credit' => 0,
                         'memo' => 'Cash count OVER — ' . $countDate],
                        ['account_id' => (int)$shortAccount['id'], 'debit' => 0, 'credit' => $difference,
                         'memo' => 'Cash count over — ' . $countDate],
                    ];
                } else {
                    $shortAmt = abs($difference);
                    $lines = [
                        ['account_id' => (int)$shortAccount['id'], 'debit' => $shortAmt, 'credit' => 0,
                         'memo' => 'Cash count SHORT — ' . $countDate],
                        ['account_id' => (int)$cashAccount['id'],  'debit' => 0, 'credit' => $shortAmt,
                         'memo' => 'Cash count short — ' . $countDate],
                    ];
                }

                $glErr = null;
                $txnId = post_journal_entry($conn, [
                    'entry_date'  => $countDate,
                    'session_id'  => $sessionId,
                    'description' => 'Petty cash count adjustment — ' . ($difference > 0 ? 'OVER' : 'SHORT')
                                   . ' ' . number_format(abs($difference), 2),
                    'ref_type'    => 'petty_cash',
                    'ref_id'      => null,
                    'posted_by'   => $userId,
                    'idempotency' => 'petty-cash-' . $countDate . '-' . $shift . '-' . time(),
                    'lines'       => $lines,
                ], $glErr);

                if ($txnId <= 0) {
                    throw new Exception('GL post failed: ' . ($glErr ?: 'unknown'));
                }
            }

            // Save the count record
            $ins = $conn->prepare("
                INSERT INTO petty_cash_counts
                  (count_date, shift, ledger_balance, counted_amount, difference, adjustment_txn_id, notes, counted_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->bind_param(
                "ssdddisi",
                $countDate, $shift, $ledgerBalance, $counted, $difference, $txnId, $notes, $userId
            );
            if (!$ins->execute()) {
                throw new Exception('Insert failed: ' . $ins->error);
            }
            $ins->close();

            $conn->commit();

            $diffText = number_format(abs($difference), 2);
            if (abs($difference) < 0.01) {
                $msg = "✓ Count recorded on $countDate. Cash matches the ledger exactly.";
            } else {
                $msg = "✓ Count recorded on $countDate. Difference of PKR $diffText ("
                     . ($difference > 0 ? 'OVER' : 'SHORT')
                     . ') was posted as a journal entry' . ($txnId ? " (TXN #$txnId)" : '') . '.';
            }
            $msg_type = 'success';

        } catch (Exception $e) {
            $conn->rollback();
            $msg = 'Failed: ' . $e->getMessage();
            $msg_type = 'danger';
        }
    }
}

/* ---------- Load past counts ---------- */
$filterFrom = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$filterTo   = $_GET['to']   ?? date('Y-m-d');

$stmt = $conn->prepare("
    SELECT c.*, u.username AS counted_by_name
    FROM petty_cash_counts c
    LEFT JOIN users u ON u.id = c.counted_by
    WHERE c.count_date BETWEEN ? AND ?
    ORDER BY c.count_date DESC, c.id DESC
");
$stmt->bind_param("ss", $filterFrom, $filterTo);
$stmt->execute();
$counts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ---------- Current live ledger balance for 1010 ---------- */
$currentCash = 0.0;
if ($cashAccount) {
    $currentCash = gl_account_balance_signed($conn, (int)$cashAccount['id'], date('Y-m-d'));
}

/* ---------- Summary ---------- */
$totalShort = 0.0;
$totalOver  = 0.0;
$unreconciled = 0;
foreach ($counts as $c) {
    $d = (float)$c['difference'];
    if ($d > 0.005) $totalOver += $d;
    elseif ($d < -0.005) $totalShort += abs($d);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Petty Cash / Daily Count — Dar-e-Arqm School</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    * { box-sizing: border-box; }
    body { background: #eef1f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 0; color: #1e293b; }
    .wrap { max-width: 1200px; margin: 30px auto; padding: 0 20px; }

    .head {
        background: linear-gradient(135deg, #047857 0%, #059669 100%);
        color: #fff; padding: 30px 40px 26px 40px;
        border-radius: 12px 12px 0 0; box-shadow: 0 4px 16px rgba(4,120,87,0.15);
        display: flex; justify-content: space-between; align-items: flex-end;
        gap: 20px; flex-wrap: wrap;
    }
    .head h1 { margin: 0; font-size: 30px; font-weight: 700; }
    .head .sub { font-size: 14px; opacity: 0.9; margin-top: 6px; }

    .btn-back {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 10px 20px; background: #ffffff; color: #047857;
        border: 1px solid #ffffff; border-radius: 8px;
        text-decoration: none; font-size: 14px; font-weight: 600;
        line-height: 1; font-family: inherit;
    }
    .btn-back:hover { background: #d1fae5; border-color: #d1fae5; text-decoration: none; color: #047857; }

    .card { background: #fff; padding: 30px 40px 40px 40px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

    .section-title {
        font-size: 12px; font-weight: 800; color: #64748b; text-transform: uppercase;
        letter-spacing: 2px; margin: 24px 0 14px 0; padding-bottom: 8px;
        border-bottom: 1px solid #e2e8f0;
    }
    .section-title:first-child { margin-top: 0; }

    .alert-custom { padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; border-left: 4px solid; }
    .alert-custom.success { background: #d1fae5; border-color: #059669; color: #065f46; }
    .alert-custom.danger  { background: #fee2e2; border-color: #dc2626; color: #991b1b; }

    .current-balance {
        background: #ecfdf5; border: 1px solid #a7f3d0; border-left: 6px solid #059669;
        padding: 18px 24px; border-radius: 10px; margin-bottom: 24px;
        display: flex; justify-content: space-between; align-items: center;
        flex-wrap: wrap; gap: 12px;
    }
    .current-balance .lbl { font-size: 11px; color: #065f46; font-weight: 800; text-transform: uppercase; letter-spacing: 1.2px; }
    .current-balance .val { font-family: 'SF Mono','Monaco',monospace; font-size: 26px; font-weight: 800; color: #065f46; }

    .summary-bar {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr));
        gap: 12px; margin-bottom: 24px;
    }
    .summary-box {
        background: #f8fafc; border: 1px solid #e2e8f0;
        border-radius: 10px; padding: 14px 16px; border-left: 5px solid;
    }
    .summary-box .lbl { font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
    .summary-box .val { font-family: 'SF Mono','Monaco',monospace; font-size: 18px; font-weight: 700; color: #0f172a; }
    .summary-box.s-short { border-color: #dc2626; }
    .summary-box.s-over  { border-color: #059669; }
    .summary-box.s-count { border-color: #1e40af; }

    .form-box {
        background: #f8fafc; border: 1px solid #e2e8f0;
        border-radius: 10px; padding: 24px 28px; margin-bottom: 24px;
    }
    .field { margin-bottom: 18px; }
    .field label {
        display: block; font-size: 12px; font-weight: 700; color: #334155;
        text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px;
    }
    .field input, .field select, .field textarea {
        width: 100%; height: 46px; padding: 10px 14px;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 15px; font-family: inherit; background: #fff; color: #0f172a;
    }
    .field textarea { height: auto; min-height: 80px; resize: vertical; }
    .field input:focus, .field select:focus, .field textarea:focus {
        outline: none; border-color: #047857; box-shadow: 0 0 0 3px rgba(4,120,87,0.1);
    }
    .field .help { font-size: 12px; color: #64748b; margin-top: 5px; }

    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    @media (max-width: 700px) { .grid-2 { grid-template-columns: 1fr; } }

    .diff-preview {
        background: #eff6ff; border: 1px solid #bfdbfe; border-left: 4px solid #1e40af;
        padding: 14px 20px; border-radius: 10px; margin: 20px 0;
        font-size: 14px; color: #1e3a8a; line-height: 1.6;
    }
    .diff-preview .big {
        font-family: 'SF Mono','Monaco',monospace; font-size: 22px; font-weight: 800;
        color: #1e40af; display: block; margin-top: 4px;
    }

    .btn {
        height: 48px; min-width: 200px; padding: 0 26px;
        background: #047857; color: #ffffff;
        border: 1px solid #047857; border-radius: 8px;
        font-size: 15px; font-weight: 600; font-family: inherit;
        cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        line-height: 1;
    }
    .btn:hover { background: #065f46; border-color: #065f46; }

    table.tbl { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; }
    table.tbl thead th {
        background: #f8fafc; font-size: 11px; color: #64748b; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px;
        padding: 12px 12px; text-align: left;
        border-bottom: 2px solid #e2e8f0; white-space: nowrap;
    }
    table.tbl tbody td { padding: 12px 12px; font-size: 13px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    table.tbl tbody tr:hover td { background: #f8fafc; }
    .num { font-family: 'SF Mono','Monaco',monospace; text-align: right; font-variant-numeric: tabular-nums; }

    .diff-short { color: #dc2626; font-weight: 700; }
    .diff-over  { color: #047857; font-weight: 700; }
    .diff-zero  { color: #64748b; }

    .badge { display: inline-block; padding: 3px 9px; border-radius: 8px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
    .badge-short { background: #fee2e2; color: #991b1b; }
    .badge-over  { background: #d1fae5; color: #065f46; }
    .badge-ok    { background: #dbeafe; color: #1e40af; }

    .empty-state { padding: 40px; text-align: center; color: #94a3b8; font-style: italic; font-size: 14px; }

    .toolbar { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 16px; }
    .field-inline { display: flex; flex-direction: column; gap: 5px; }
    .field-inline label { font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 1px; }
    .field-inline input {
        height: 38px; padding: 8px 12px;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 14px; font-family: inherit; background: #fff; color: #0f172a;
    }
    .btn-soft {
        display: inline-flex; align-items: center; gap: 6px;
        height: 38px; padding: 0 16px;
        background: #f1f5f9; color: #334155;
        border: 1px solid #e2e8f0; border-radius: 8px;
        font-size: 13px; font-weight: 600; font-family: inherit;
        text-decoration: none; cursor: pointer;
    }
    .btn-soft:hover { background: #e2e8f0; text-decoration: none; color: #1e293b; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <h1>Petty Cash / Daily Count</h1>
            <div class="sub">Record the physical cash count and post any difference to the ledger</div>
        </div>
        <a href="gl_test_hub.php" class="btn-back">
            <span class="glyphicon glyphicon-arrow-left"></span> Test Hub
        </a>
    </div>

    <div class="card">

        <?php if ($msg): ?>
            <div class="alert-custom <?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <?php if (!$shortAccount): ?>
            <div class="alert-custom danger">
                <strong>Setup required.</strong>
                The Cash Short/Over account (<code><?= htmlspecialchars($SHORT_CODE) ?></code>) doesn't exist.
                Create it in the Chart of Accounts (Expense type) before recording counts.
            </div>
        <?php endif; ?>

        <div class="current-balance">
            <div>
                <div class="lbl">Current Ledger Balance — Cash in Hand (1010)</div>
                <div class="val"><?= number_format($currentCash, 2) ?></div>
            </div>
            <small style="color:#065f46;">As of <?= date('Y-m-d') ?></small>
        </div>

        <div class="section-title">Record New Count</div>

        <form method="post" class="form-box">
            <input type="hidden" name="action" value="post_count">

            <div class="grid-2">
                <div class="field">
                    <label>Count Date <span style="color:#dc2626;">*</span></label>
                    <input type="date" name="count_date" required value="<?= date('Y-m-d') ?>">
                </div>
                <div class="field">
                    <label>Shift</label>
                    <select name="shift">
                        <option value="full_day">Full Day</option>
                        <option value="morning">Morning</option>
                        <option value="evening">Evening</option>
                    </select>
                </div>
            </div>

            <div class="field">
                <label>Counted Cash Amount (PKR) <span style="color:#dc2626;">*</span></label>
                <input type="number" name="counted_amount" id="counted_amount" step="0.01" min="0" required
                       placeholder="0.00" oninput="updatePreview()">
                <div class="help">Enter the physical cash you counted in the drawer / cash box.</div>
            </div>

            <div class="diff-preview" id="diff_preview">
                <strong>Difference preview:</strong>
                <span class="big" id="diff_value">0.00</span>
                <span id="diff_label" style="color:#64748b; font-style:italic;">Enter the counted amount above to see the difference.</span>
            </div>

            <div class="field">
                <label>Notes (optional)</label>
                <textarea name="notes" placeholder="e.g. Counted by cashier in presence of accountant"></textarea>
            </div>

            <button type="submit" class="btn" <?= $shortAccount ? '' : 'disabled' ?>>
                <span class="glyphicon glyphicon-ok"></span> Save Count &amp; Post Difference
            </button>
        </form>

        <div class="section-title">Past Counts (<?= htmlspecialchars($filterFrom) ?> → <?= htmlspecialchars($filterTo) ?>)</div>

        <form method="get" class="toolbar">
            <div class="field-inline">
                <label>From</label>
                <input type="date" name="from" value="<?= htmlspecialchars($filterFrom) ?>">
            </div>
            <div class="field-inline">
                <label>To</label>
                <input type="date" name="to" value="<?= htmlspecialchars($filterTo) ?>">
            </div>
            <button type="submit" class="btn-soft">
                <span class="glyphicon glyphicon-filter"></span> Apply
            </button>
            <a href="gl_petty_cash.php" class="btn-soft">
                <span class="glyphicon glyphicon-refresh"></span> Reset
            </a>
        </form>

        <div class="summary-bar">
            <div class="summary-box s-count">
                <div class="lbl">Counts Recorded</div>
                <div class="val"><?= count($counts) ?></div>
            </div>
            <div class="summary-box s-short">
                <div class="lbl">Total Cash Short</div>
                <div class="val" style="color:#dc2626;"><?= number_format($totalShort, 2) ?></div>
            </div>
            <div class="summary-box s-over">
                <div class="lbl">Total Cash Over</div>
                <div class="val" style="color:#047857;"><?= number_format($totalOver, 2) ?></div>
            </div>
        </div>

        <?php if (empty($counts)): ?>
            <div class="empty-state">No counts recorded in this period.</div>
        <?php else: ?>
            <table class="tbl">
                <thead>
                    <tr>
                        <th style="width:60px;">#</th>
                        <th style="width:110px;">Date</th>
                        <th style="width:90px;">Shift</th>
                        <th class="num" style="width:120px;">Ledger Balance</th>
                        <th class="num" style="width:120px;">Counted</th>
                        <th class="num" style="width:120px;">Difference</th>
                        <th style="width:90px;">Type</th>
                        <th>Notes</th>
                        <th style="width:110px;">By</th>
                        <th class="no-print" style="width:90px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($counts as $c):
                        $d = (float)$c['difference'];
                        if (abs($d) < 0.01) {
                            $cls = 'diff-zero'; $type = 'OK'; $badge = 'badge-ok';
                        } elseif ($d > 0) {
                            $cls = 'diff-over'; $type = 'OVER'; $badge = 'badge-over';
                        } else {
                            $cls = 'diff-short'; $type = 'SHORT'; $badge = 'badge-short';
                        }
                    ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><?= htmlspecialchars($c['count_date']) ?></td>
                            <td><?= htmlspecialchars(str_replace('_', ' ', $c['shift'])) ?></td>
                            <td class="num"><?= number_format((float)$c['ledger_balance'], 2) ?></td>
                            <td class="num"><?= number_format((float)$c['counted_amount'], 2) ?></td>
                            <td class="num <?= $cls ?>"><?= number_format(abs($d), 2) ?></td>
                            <td><span class="badge <?= $badge ?>"><?= $type ?></span></td>
                            <td><small style="color:#64748b;"><?= htmlspecialchars($c['notes'] ?: '—') ?></small></td>
                            <td><small><?= htmlspecialchars($c['counted_by_name'] ?: '—') ?></small></td>
                            <td class="no-print">
                                <?php if (!empty($c['adjustment_txn_id'])): ?>
                                    <a href="gl_transaction_view.php?id=<?= (int)$c['adjustment_txn_id'] ?>" class="btn-soft" style="height:32px; padding:0 10px; font-size:12px;">
                                        TXN #<?= (int)$c['adjustment_txn_id'] ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color:#94a3b8; font-size:12px;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    </div>
</div>

<script>
var ledgerBalance = <?= json_encode((float)$currentCash) ?>;

function updatePreview() {
    var counted = parseFloat(document.getElementById('counted_amount').value) || 0;
    var diff = counted - ledgerBalance;
    var el = document.getElementById('diff_value');
    var lbl = document.getElementById('diff_label');

    var fmt = Math.abs(diff).toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    el.textContent = fmt;

    if (Math.abs(diff) < 0.01) {
        el.style.color = '#64748b';
        lbl.innerHTML = '✓ Count matches the ledger exactly. No journal entry will be posted.';
    } else if (diff > 0) {
        el.style.color = '#047857';
        lbl.innerHTML = '🟢 Cash is <strong>OVER</strong> by this amount. Will post: Dr Cash / Cr Cash Short-Over.';
    } else {
        el.style.color = '#dc2626';
        lbl.innerHTML = '🔴 Cash is <strong>SHORT</strong> by this amount. Will post: Dr Cash Short-Over / Cr Cash.';
    }
}
</script>

</body>
</html>