<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
/* ==== AUTHORIZATION GATE ==== */
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
/* ==== END AUTHORIZATION GATE ==== */
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;

$budgetId = (int)($_GET['id'] ?? $_POST['budget_id'] ?? 0);
if ($budgetId <= 0) { header('Location: gl_budget.php'); exit; }

$budget = $conn->query("
    SELECT b.*, s.title AS session_title, s.from_dated, s.to_dated
    FROM gl_budgets b
    LEFT JOIN sessions s ON s.id = b.session_id
    WHERE b.id = $budgetId
    LIMIT 1
")->fetch_assoc();
if (!$budget) { http_response_code(404); die('Budget not found.'); }

$isDraft = ($budget['status'] === 'DRAFT');

/* ---------- SAVE (bulk) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save' && $isDraft) {
    $cells = $_POST['cells'] ?? [];    // [account_id][month_index] = amount

    $conn->begin_transaction();
    try {
        // Delete all existing lines for this budget, then re-insert non-zero ones.
        // Simpler than diffing. Wrapped in transaction, so safe.
        $conn->query("DELETE FROM gl_budget_lines WHERE budget_id = $budgetId");

        $ins = $conn->prepare("
            INSERT INTO gl_budget_lines (budget_id, account_id, month_index, amount)
            VALUES (?, ?, ?, ?)
        ");
        $saved = 0;
        foreach ($cells as $accountId => $months) {
            $accountId = (int)$accountId;
            if ($accountId <= 0) continue;
            foreach ($months as $mIdx => $amt) {
                $mIdx = (int)$mIdx;
                $amt  = round((float)$amt, 2);
                if ($mIdx < 1 || $mIdx > 12) continue;
                if (abs($amt) < 0.005) continue;
                $ins->bind_param("iiid", $budgetId, $accountId, $mIdx, $amt);
                if ($ins->execute()) $saved++;
            }
        }
        $ins->close();

        // Update title/notes
        $title = trim($_POST['title'] ?? $budget['title']);
        $notes = trim($_POST['notes'] ?? '');
        $stmt = $conn->prepare("UPDATE gl_budgets SET title = ?, notes = ? WHERE id = ?");
        $stmt->bind_param("ssi", $title, $notes, $budgetId);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        $_SESSION['budget_msg'] = "Saved — $saved budget line(s).";
        $_SESSION['budget_msg_type'] = 'success';
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['budget_msg'] = 'Save failed: ' . $e->getMessage();
        $_SESSION['budget_msg_type'] = 'danger';
    }
    header("Location: gl_budget_edit.php?id=$budgetId");
    exit;
}

/* ---------- APPROVE ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve') {
    if ($budget['status'] === 'DRAFT') {
        $stmt = $conn->prepare("
            UPDATE gl_budgets
            SET status = 'APPROVED', approved_by = ?, approved_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param("ii", $userId, $budgetId);
        $stmt->execute();
        $stmt->close();
        $_SESSION['budget_msg'] = 'Budget approved.';
        $_SESSION['budget_msg_type'] = 'success';
    }
    header("Location: gl_budget_edit.php?id=$budgetId");
    exit;
}

/* ---------- LOCK ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'lock') {
    if ($budget['status'] === 'APPROVED') {
        $stmt = $conn->prepare("
            UPDATE gl_budgets
            SET status = 'LOCKED', locked_by = ?, locked_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param("ii", $userId, $budgetId);
        $stmt->execute();
        $stmt->close();
        $_SESSION['budget_msg'] = 'Budget locked. It can no longer be edited.';
        $_SESSION['budget_msg_type'] = 'success';
    }
    header("Location: gl_budget_edit.php?id=$budgetId");
    exit;
}

/* ---------- UNLOCK (admin only) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unlock') {
    if ($budget['status'] === 'LOCKED' && $userId === 1) {
        $stmt = $conn->prepare("UPDATE gl_budgets SET status = 'APPROVED' WHERE id = ?");
        $stmt->bind_param("i", $budgetId);
        $stmt->execute();
        $stmt->close();
        $_SESSION['budget_msg'] = 'Budget unlocked (back to APPROVED).';
        $_SESSION['budget_msg_type'] = 'success';
    } else {
        $_SESSION['budget_msg'] = 'Only admin (user #1) can unlock a locked budget.';
        $_SESSION['budget_msg_type'] = 'danger';
    }
    header("Location: gl_budget_edit.php?id=$budgetId");
    exit;
}

/* ---------- Reload after actions ---------- */
$budget = $conn->query("
    SELECT b.*, s.title AS session_title, s.from_dated, s.to_dated
    FROM gl_budgets b
    LEFT JOIN sessions s ON s.id = b.session_id
    WHERE b.id = $budgetId
    LIMIT 1
")->fetch_assoc();
$isDraft = ($budget['status'] === 'DRAFT');

/* ---------- Data ---------- */
$months = gl_budget_month_boundaries($budget);
$existingLines = gl_budget_lines_map($conn, $budgetId);
$accounts = gl_list_accounts($conn, null, true);
// Filter to Revenue + Expense only
$accounts = array_values(array_filter($accounts, fn($a) => in_array($a['account_type'], ['REVENUE','EXPENSE'], true)));

// Grand totals for display
$grandTotal = 0.0;
foreach ($existingLines as $monthsArr) foreach ($monthsArr as $amt) $grandTotal += $amt;

$msg = $_SESSION['budget_msg'] ?? ''; $msg_type = $_SESSION['budget_msg_type'] ?? '';
unset($_SESSION['budget_msg'], $_SESSION['budget_msg_type']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Edit Budget — <?= htmlspecialchars($budget['title']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    * { box-sizing: border-box; }
    body { background: #eef1f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 0; color: #1e293b; }
    .wrap { max-width: 1700px; margin: 30px auto; padding: 0 20px; }

    .head {
        background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
        color: #fff; padding: 26px 34px 22px 34px;
        border-radius: 12px 12px 0 0; box-shadow: 0 4px 16px rgba(30,64,175,0.15);
        display: flex; justify-content: space-between; align-items: flex-end;
        gap: 20px; flex-wrap: wrap;
    }
    .head h1 { margin: 0; font-size: 24px; font-weight: 700; }
    .head .sub { font-size: 13px; opacity: 0.9; margin-top: 4px; }

    .btn-white {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 9px 16px; background: #ffffff; color: #1e40af;
        border: 1px solid #ffffff; border-radius: 8px;
        text-decoration: none; font-size: 13px; font-weight: 600;
        line-height: 1; font-family: inherit; cursor: pointer;
    }
    .btn-white:hover { background: #dbeafe; border-color: #dbeafe; color: #1e40af; text-decoration: none; }

    .card { background: #fff; padding: 24px 30px 30px 30px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

    .alert-custom { padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; border-left: 4px solid; }
    .alert-custom.success { background: #d1fae5; border-color: #059669; color: #065f46; }
    .alert-custom.danger  { background: #fee2e2; border-color: #dc2626; color: #991b1b; }

    .info-strip {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr));
        gap: 12px; margin-bottom: 20px;
    }
    .info-box {
        background: #f8fafc; border: 1px solid #e2e8f0;
        border-left: 5px solid #1e40af;
        border-radius: 10px; padding: 14px 16px;
    }
    .info-box .lbl { font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
    .info-box .val { font-family: 'SF Mono','Monaco',monospace; font-size: 18px; font-weight: 700; }
    .info-box.status-DRAFT    { border-color: #f59e0b; }
    .info-box.status-APPROVED { border-color: #1e40af; }
    .info-box.status-LOCKED   { border-color: #059669; }

    .toolbar {
        display: flex; gap: 10px; flex-wrap: wrap; align-items: center;
        margin-bottom: 16px;
    }

    .btn {
        display: inline-flex; align-items: center; gap: 8px;
        height: 42px; padding: 0 20px;
        border-radius: 8px;
        font-size: 14px; font-weight: 600; font-family: inherit;
        cursor: pointer; line-height: 1;
        text-decoration: none; border: 1px solid;
        transition: all 0.15s;
    }
    .btn-primary { background: #1e40af; color: #fff; border-color: #1e40af; }
    .btn-primary:hover { background: #1e3a8a; border-color: #1e3a8a; color: #fff; text-decoration: none; }
    .btn-success { background: #059669; color: #fff; border-color: #059669; }
    .btn-success:hover { background: #047857; border-color: #047857; color: #fff; text-decoration: none; }
    .btn-warn { background: #f59e0b; color: #fff; border-color: #f59e0b; }
    .btn-warn:hover { background: #d97706; border-color: #d97706; color: #fff; text-decoration: none; }
    .btn-ghost { background: #fff; color: #334155; border-color: #cbd5e1; }
    .btn-ghost:hover { background: #f1f5f9; text-decoration: none; color: #1e293b; }

    /* The grid */
    .grid-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; }
    table.grid {
        width: 100%; border-collapse: collapse;
        font-size: 12px;
    }
    table.grid thead th {
        background: #f8fafc; color: #475569; font-weight: 700;
        text-transform: uppercase; font-size: 10px;
        letter-spacing: 0.5px;
        padding: 10px 8px; text-align: center;
        border-bottom: 2px solid #e2e8f0;
        border-right: 1px solid #e2e8f0;
        position: sticky; top: 0; z-index: 2;
    }
    table.grid thead th.first-col { text-align: left; }
    table.grid thead th .sub { display: block; font-size: 9px; color: #94a3b8; font-weight: 500; margin-top: 2px; }

    table.grid tbody td {
        padding: 6px 8px;
        border-bottom: 1px solid #f1f5f9;
        border-right: 1px solid #f1f5f9;
        vertical-align: middle;
    }
    table.grid tbody td.first-col {
        white-space: nowrap; text-align: left;
        background: #f8fafc; position: sticky; left: 0; z-index: 1;
        font-weight: 600; color: #334155;
        min-width: 260px;
    }
    table.grid .num {
        text-align: right;
        font-family: 'SF Mono','Monaco',monospace;
        font-variant-numeric: tabular-nums;
    }
    table.grid input.cell {
        width: 90px; height: 32px;
        padding: 4px 8px;
        border: 1px solid #e2e8f0; border-radius: 6px;
        font-size: 12px; text-align: right;
        font-family: 'SF Mono','Monaco',monospace;
        background: #fff; color: #0f172a;
    }
    table.grid input.cell:focus {
        outline: none; border-color: #1e40af;
        box-shadow: 0 0 0 2px rgba(30,64,175,0.15);
    }
    table.grid input.cell[readonly] {
        background: #f1f5f9; color: #64748b; cursor: not-allowed;
    }

    table.grid tbody tr:hover td.first-col { background: #eff6ff; }
    table.grid tbody tr.subtotal td { background: #f1f5f9; font-weight: 700; }
    table.grid tbody tr.subtotal td.first-col { background: #e0e7ff; color: #1e40af; }

    .row-total {
        font-family: 'SF Mono','Monaco',monospace;
        font-size: 12px; font-weight: 700;
        color: #0f172a; padding: 6px 8px;
        text-align: right;
    }
    .row-total.pos { color: #047857; }
    .row-total.neg { color: #b91c1c; }

    .section-band {
        background: #e0e7ff; color: #1e40af;
        font-weight: 700; font-size: 11px;
        text-transform: uppercase; letter-spacing: 1.5px;
        padding: 8px 12px;
    }
    .section-band td { padding: 8px 12px !important; background: #e0e7ff !important; }

    .legend {
        margin-top: 16px;
        padding: 14px 18px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        font-size: 12px;
        color: #64748b;
        line-height: 1.7;
    }
    .legend code { background: #e2e8f0; padding: 1px 5px; border-radius: 3px; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <h1><?= htmlspecialchars($budget['title']) ?></h1>
            <div class="sub">
                <?= htmlspecialchars($budget['session_title']) ?>
                &middot; <?= htmlspecialchars($budget['from_dated']) ?> → <?= htmlspecialchars($budget['to_dated']) ?>
                &middot; Status: <strong><?= htmlspecialchars($budget['status']) ?></strong>
            </div>
        </div>
        <a href="gl_budget.php" class="btn-white">
            <span class="glyphicon glyphicon-arrow-left"></span> All Budgets
        </a>
    </div>

    <div class="card">

        <?php if ($msg): ?>
            <div class="alert-custom <?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <div class="info-strip">
            <div class="info-box status-<?= htmlspecialchars($budget['status']) ?>">
                <div class="lbl">Status</div>
                <div class="val"><?= htmlspecialchars($budget['status']) ?></div>
            </div>
            <div class="info-box">
                <div class="lbl">Accounts Budgeted</div>
                <div class="val"><?= count(array_filter($existingLines)) ?></div>
            </div>
            <div class="info-box">
                <div class="lbl">Grand Total</div>
                <div class="val"><?= number_format($grandTotal, 2) ?></div>
            </div>
            <div class="info-box">
                <div class="lbl">Months</div>
                <div class="val"><?= count($months) ?></div>
            </div>
        </div>

        <form method="post" id="budgetForm">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="budget_id" value="<?= (int)$budgetId ?>">

            <?php if ($isDraft): ?>
                <div class="toolbar">
                    <input type="text" name="title" value="<?= htmlspecialchars($budget['title']) ?>"
                           maxlength="150" required
                           style="height:42px; padding:0 14px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; font-family:inherit; min-width:340px;">
                    <?php if (!empty($perms['can_edit'])): ?>
                    <button type="submit" class="btn btn-primary">
                        <span class="glyphicon glyphicon-floppy-disk"></span> Save Budget
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-ghost" onclick="window.print()">
                        <span class="glyphicon glyphicon-print"></span> Print
                    </button>
                </div>
            <?php else: ?>
                <div class="toolbar">
                    <button type="button" class="btn btn-ghost" onclick="window.print()">
                        <span class="glyphicon glyphicon-print"></span> Print
                    </button>
                    <?php if ($budget['status'] === 'APPROVED'): ?>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Lock this budget? It becomes read-only.');">
                            <input type="hidden" name="action" value="lock">
                            <button type="submit" class="btn btn-success">
                                <span class="glyphicon glyphicon-lock"></span> Lock Budget
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if ($budget['status'] === 'LOCKED' && $userId === 1): ?>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Unlock this budget? It returns to APPROVED.');">
                            <input type="hidden" name="action" value="unlock">
                            <button type="submit" class="btn btn-warn">
                                <span class="glyphicon glyphicon-refresh"></span> Unlock (admin)
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Grid -->
            <div class="grid-wrap">
                <table class="grid">
                    <thead>
                        <tr>
                            <th class="first-col" style="min-width:260px;">Account</th>
                            <?php foreach ($months as $m): ?>
                                <th style="min-width:100px;">
                                    <?= htmlspecialchars($m['label']) ?>
                                    <span class="sub"><?= htmlspecialchars(substr($m['start'], 5)) ?> – <?= htmlspecialchars(substr($m['end'], 5)) ?></span>
                                </th>
                            <?php endforeach; ?>
                            <th style="min-width:120px; background:#e0e7ff; color:#1e40af;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $types = [
                            'REVENUE' => 'Revenue',
                            'EXPENSE' => 'Expenses',
                        ];
                        foreach ($types as $typeKey => $typeLabel):
                            $typedAccounts = array_filter($accounts, fn($a) => $a['account_type'] === $typeKey);
                            if (empty($typedAccounts)) continue;
                        ?>
                            <tr class="section-band">
                                <td colspan="<?= count($months) + 2 ?>"><?= htmlspecialchars($typeLabel) ?></td>
                            </tr>
                            <?php
                            $colTotals = array_fill(1, 12, 0.0);
                            foreach ($typedAccounts as $acc):
                                $accId = (int)$acc['id'];
                                $rowTotal = 0.0;
                            ?>
                                <tr>
                                    <td class="first-col">
                                        <code style="color:#94a3b8;"><?= htmlspecialchars($acc['code']) ?></code>
                                        <?= htmlspecialchars($acc['name']) ?>
                                    </td>
                                    <?php foreach ($months as $m):
                                        $mIdx = (int)$m['index'];
                                        $val  = $existingLines[$accId][$mIdx] ?? 0;
                                        $rowTotal += $val;
                                        $colTotals[$mIdx] += $val;
                                    ?>
                                        <td class="num">
                                            <input type="number" step="0.01" min="0"
                                                   class="cell"
                                                   name="cells[<?= $accId ?>][<?= $mIdx ?>]"
                                                   value="<?= $val > 0 ? number_format($val, 2, '.', '') : '' ?>"
                                                   placeholder="—"
                                                   <?= $isDraft ? '' : 'readonly' ?>
                                                   oninput="recalcRow(this); recalcTotals();">
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="row-total" id="rowtotal-<?= $accId ?>" data-row-total="<?= number_format($rowTotal, 2, '.', '') ?>">
                                        <?= number_format($rowTotal, 2) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="subtotal">
                                <td class="first-col"><?= htmlspecialchars($typeLabel) ?> — Subtotal</td>
                                <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <td class="row-total" id="coltotal-<?= $typeKey ?>-<?= $i ?>" data-col-key="<?= $typeKey ?>-<?= $i ?>">
                                        <?= number_format($colTotals[$i] ?? 0, 2) ?>
                                    </td>
                                <?php endfor; ?>
                                <td class="row-total" id="type-total-<?= $typeKey ?>" data-type-total="<?= $typeKey ?>">
                                    <?= number_format(array_sum($colTotals), 2) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:#e3f2fd;">
                            <td class="first-col" style="font-size:12px; color:#1e40af; text-transform:uppercase; letter-spacing:1px;">Grand Total</td>
                            <?php for ($i = 1; $i <= 12; $i++): ?>
                                <td class="row-total" id="grandcol-<?= $i ?>"><?= number_format(0, 2) ?></td>
                            <?php endfor; ?>
                            <td class="row-total" id="grandtotal"><?= number_format(0, 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="legend">
                <strong>Notes:</strong>
                <ul style="margin: 8px 0 0 20px; padding: 0;">
                    <li>Months are labeled by the calendar month falling inside the session period (<?= htmlspecialchars($budget['from_dated']) ?> → <?= htmlspecialchars($budget['to_dated']) ?>).</li>
                    <li>Enter <strong>Revenue</strong> as a positive number = target to hit. Enter <strong>Expense</strong> as a positive number = limit to stay under.</li>
                    <li>Leave a cell empty to mean <code>0.00</code>.</li>
                    <li>Grand Total is a simple sum, not a net profit.</li>
                    <?php if ($budget['status'] === 'APPROVED'): ?>
                        <li style="color: #1e40af;"><strong>This budget is APPROVED.</strong> Editing is disabled. Click <strong>Lock</strong> to freeze it permanently.</li>
                    <?php elseif ($budget['status'] === 'LOCKED'): ?>
                        <li style="color: #065f46;"><strong>This budget is LOCKED.</strong> It cannot be edited. Only admin can unlock it.</li>
                    <?php endif; ?>
                </ul>
            </div>

            <?php if ($isDraft): ?>
                <div style="margin-top: 20px; display: flex; gap: 12px; flex-wrap: wrap;">
                    <?php if (!empty($perms['can_edit'])): ?>
                    <button type="submit" class="btn btn-primary">
                        <span class="glyphicon glyphicon-floppy-disk"></span> Save Budget
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-success" onclick="if(confirm('Approve this budget? After approval, the numbers cannot be changed except by admin.')){ document.getElementById('approveForm').submit(); }">
                        <span class="glyphicon glyphicon-ok"></span> Save &amp; Approve
                    </button>
                </div>
            <?php endif; ?>
        </form>

        <!-- Separate form for approve action (outside the main save form) -->
        <form method="post" id="approveForm" style="display:none;">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="budget_id" value="<?= (int)$budgetId ?>">
        </form>

    </div>
</div>

<script>
function num(v) {
    var n = parseFloat(v);
    return isNaN(n) ? 0 : n;
}

function fmt(n) {
    return n.toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function recalcRow(input) {
    var tr = input.closest('tr');
    var total = 0;
    tr.querySelectorAll('input.cell').forEach(function(inp){
        total += num(inp.value);
    });
    var rowId = tr.querySelector('.row-total').id;
    if (rowId) {
        var td = document.getElementById(rowId);
        if (td) {
            td.textContent = fmt(total);
            td.dataset.rowTotal = total.toFixed(2);
        }
    }
}

function recalcTotals() {
    // For each type, sum every row's cell values per column
    var typeKeys = ['REVENUE','EXPENSE'];
    var grandCol = {};
    for (var i = 1; i <= 12; i++) grandCol[i] = 0;
    var grandSum = 0;

    typeKeys.forEach(function(tk){
        var colTotal = {};
        for (var i = 1; i <= 12; i++) colTotal[i] = 0;

        // Find all rows under this type's section (until next section-band)
        var bands = document.querySelectorAll('tr.section-band');
        var currentType = null;
        var subtotalFound = false;

        document.querySelectorAll('table.grid tbody tr').forEach(function(tr){
            if (tr.classList.contains('section-band')) {
                var label = tr.textContent.trim().toUpperCase();
                if (label.indexOf('REVENUE') === 0) currentType = 'REVENUE';
                else if (label.indexOf('EXPENSE') === 0) currentType = 'EXPENSE';
                return;
            }
            if (currentType !== tk) return;
            if (tr.classList.contains('subtotal')) return;

            tr.querySelectorAll('input.cell').forEach(function(inp, idx){
                var mIdx = idx + 1;
                colTotal[mIdx] += num(inp.value);
            });
        });

        // Update subtotal cells
        for (var i = 1; i <= 12; i++) {
            var el = document.getElementById('coltotal-' + tk + '-' + i);
            if (el) el.textContent = fmt(colTotal[i]);
            grandCol[i] += colTotal[i];
        }
        var typeTotalEl = document.getElementById('type-total-' + tk);
        if (typeTotalEl) {
            var typeTotal = Object.values(colTotal).reduce((a,b)=>a+b, 0);
            typeTotalEl.textContent = fmt(typeTotal);
            grandSum += typeTotal;
        }
    });

    // Update grand total row
    for (var i = 1; i <= 12; i++) {
        var el = document.getElementById('grandcol-' + i);
        if (el) el.textContent = fmt(grandCol[i]);
    }
    var gt = document.getElementById('grandtotal');
    if (gt) gt.textContent = fmt(grandSum);
}

document.addEventListener('DOMContentLoaded', recalcTotals);
</script>

</body>
</html>