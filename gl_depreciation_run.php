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
$msg = ''; $msg_type = '';

$ACCUM_CODE = '1540';
$EXP_CODE   = '6000';

$accumAccount = gl_get_account_by_code($conn, $ACCUM_CODE);
$expAccount   = gl_get_account_by_code($conn, $EXP_CODE);

/* ============================================================
   AJAX: preview
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'preview') {
    header('Content-Type: application/json');
    $periodStart = $_POST['period_start'] ?? '';
    $periodEnd   = $_POST['period_end'] ?? '';

    if (!strtotime($periodStart) || !strtotime($periodEnd)) {
        echo json_encode(['ok' => false, 'error' => 'Both dates required.']);
        exit;
    }
    if (strtotime($periodEnd) < strtotime($periodStart)) {
        echo json_encode(['ok' => false, 'error' => 'End date must be on or after start date.']);
        exit;
    }

    $assets = gl_list_assets($conn, 'active');
    $rows = [];
    $total = 0.0;

    foreach ($assets as $a) {
        $charge = gl_calc_depreciation($a, $periodStart, $periodEnd);
        if ($charge <= 0) continue;

        $bookBefore = gl_asset_book_value($a);
        $bookAfter = round($bookBefore - $charge, 2);

        $rows[] = [
            'id' => (int)$a['id'],
            'code' => $a['asset_code'],
            'name' => $a['name'],
            'cost' => (float)$a['cost'],
            'accumulated' => (float)$a['accumulated_depreciation'],
            'book_before' => $bookBefore,
            'charge' => $charge,
            'book_after' => $bookAfter,
        ];
        $total += $charge;
    }

    echo json_encode([
        'ok' => true,
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'rows' => $rows,
        'total' => round($total, 2),
        'asset_count' => count($rows),
    ]);
    exit;
}

/* ============================================================
   POST: run depreciation
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    $periodStart = $_POST['period_start'] ?? '';
    $periodEnd   = $_POST['period_end'] ?? '';
    $entryDate   = $_POST['entry_date'] ?? $periodEnd;
    $notes       = trim($_POST['notes'] ?? '');

    if (!$accumAccount || !$expAccount) {
        $msg = "Setup required: create accounts $ACCUM_CODE (Accumulated Depreciation) and $EXP_CODE (Depreciation Expense).";
        $msg_type = 'danger';
    } elseif (!strtotime($periodStart) || !strtotime($periodEnd)) {
        $msg = 'Both period dates required.'; $msg_type = 'danger';
    } elseif (strtotime($periodEnd) < strtotime($periodStart)) {
        $msg = 'End date must be on or after start date.'; $msg_type = 'danger';
    } else {
        $assets = gl_list_assets($conn, 'active');
        $rows = [];
        $total = 0.0;

        foreach ($assets as $a) {
            $charge = gl_calc_depreciation($a, $periodStart, $periodEnd);
            if ($charge <= 0) continue;
            $bookBefore = gl_asset_book_value($a);
            $bookAfter = round($bookBefore - $charge, 2);
            $rows[] = [
                'asset' => $a,
                'charge' => $charge,
                'book_before' => $bookBefore,
                'book_after' => $bookAfter,
            ];
            $total += $charge;
        }

        if (empty($rows)) {
            $msg = 'No depreciation to charge for this period. (Either all assets are already depreciated or purchase dates are after period end.)';
            $msg_type = 'danger';
        } else {
            $conn->begin_transaction();
            try {
                // Aggregate journal entry: Dr Depreciation Expense / Cr Accumulated Depreciation
                $lines = [
                    [
                        'account_id' => (int)$expAccount['id'],
                        'debit' => round($total, 2),
                        'credit' => 0,
                        'memo' => 'Depreciation ' . $periodStart . ' to ' . $periodEnd,
                    ],
                    [
                        'account_id' => (int)$accumAccount['id'],
                        'debit' => 0,
                        'credit' => round($total, 2),
                        'memo' => 'Accumulated depreciation ' . $periodStart . ' to ' . $periodEnd,
                    ],
                ];

                $sessionId = gl_current_session_id($conn) ?: 1;
                $glErr = null;
                $txnId = post_journal_entry($conn, [
                    'entry_date' => $entryDate,
                    'session_id' => $sessionId,
                    'description' => 'Depreciation — ' . $periodStart . ' to ' . $periodEnd . ' (' . count($rows) . ' assets)',
                    'ref_type' => 'depreciation',
                    'ref_id' => null,
                    'posted_by' => $userId,
                    'idempotency' => 'depr-' . $periodStart . '-' . $periodEnd . '-' . time(),
                    'lines' => $lines,
                ], $glErr);

                if ($txnId <= 0) {
                    throw new Exception('GL post failed: ' . ($glErr ?: 'unknown'));
                }

                // Insert the run record
                $insRun = $conn->prepare("
                    INSERT INTO gl_depreciation_runs
                      (period_start, period_end, entry_date, asset_count, total_amount, gl_txn_id, notes, posted_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $assetCount = count($rows);
                $totalAmt = round($total, 2);
                $insRun->bind_param("sssidisi",
                    $periodStart, $periodEnd, $entryDate, $assetCount, $totalAmt, $txnId, $notes, $userId
                );
                if (!$insRun->execute()) {
                    throw new Exception('Run insert failed: ' . $insRun->error);
                }
                $runId = $conn->insert_id;
                $insRun->close();

                // Per-asset entries + update each asset's accumulated + last_depreciated_through
                $insEntry = $conn->prepare("
                    INSERT INTO gl_depreciation_entries
                      (run_id, asset_id, period_start, period_end, amount, book_value_before, book_value_after)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $updAsset = $conn->prepare("
                    UPDATE gl_assets
                    SET accumulated_depreciation = accumulated_depreciation + ?,
                        last_depreciated_through = ?
                    WHERE id = ?
                ");

                foreach ($rows as $r) {
                    $assetId = (int)$r['asset']['id'];
                    $insEntry->bind_param("iissddd",
                        $runId, $assetId, $periodStart, $periodEnd,
                        $r['charge'], $r['book_before'], $r['book_after']
                    );
                    if (!$insEntry->execute()) {
                        throw new Exception('Entry insert failed: ' . $insEntry->error);
                    }

                    $updAsset->bind_param("dsi", $r['charge'], $periodEnd, $assetId);
                    if (!$updAsset->execute()) {
                        throw new Exception('Asset update failed: ' . $updAsset->error);
                    }
                }
                $insEntry->close();
                $updAsset->close();

                $conn->commit();
                $msg = "✓ Depreciation posted. " . count($rows) . " assets charged, total PKR " . number_format($total, 2) . " (TXN #$txnId).";
                $msg_type = 'success';
            } catch (Exception $e) {
                $conn->rollback();
                $msg = 'Failed: ' . $e->getMessage();
                $msg_type = 'danger';
            }
        }
    }
}

/* ---------- Recent runs ---------- */
$runs = $conn->query("
    SELECT r.*, u.username AS posted_by_name
    FROM gl_depreciation_runs r
    LEFT JOIN users u ON u.id = r.posted_by
    ORDER BY r.id DESC
    LIMIT 30
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Run Depreciation — Dar-e-Arqm School</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    * { box-sizing: border-box; }
    body { background: #eef1f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 0; color: #1e293b; }
    .wrap { max-width: 1200px; margin: 30px auto; padding: 0 20px; }

    .head {
        background: linear-gradient(135deg, #5b21b6 0%, #6d28d9 100%);
        color: #fff; padding: 30px 40px 26px 40px;
        border-radius: 12px 12px 0 0; box-shadow: 0 4px 16px rgba(91,33,182,0.15);
        display: flex; justify-content: space-between; align-items: flex-end;
        gap: 20px; flex-wrap: wrap;
    }
    .head h1 { margin: 0; font-size: 30px; font-weight: 700; }
    .head .sub { font-size: 14px; opacity: 0.9; margin-top: 6px; }

    .btn-back {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 10px 20px; background: #ffffff; color: #5b21b6;
        border: 1px solid #ffffff; border-radius: 8px;
        text-decoration: none; font-size: 14px; font-weight: 600;
        line-height: 1; font-family: inherit;
    }
    .btn-back:hover { background: #ede9fe; border-color: #ede9fe; text-decoration: none; color: #5b21b6; }

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
    .alert-custom.info    { background: #eff6ff; border-color: #1e40af; color: #1e3a8a; }

    .form-box {
        background: #f8fafc; border: 1px solid #e2e8f0;
        border-radius: 10px; padding: 24px 28px; margin-bottom: 24px;
    }
    .field { margin-bottom: 18px; }
    .field label {
        display: block; font-size: 12px; font-weight: 700; color: #334155;
        text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px;
    }
    .field input, .field textarea {
        width: 100%; height: 46px; padding: 10px 14px;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 15px; font-family: inherit; background: #fff; color: #0f172a;
    }
    .field textarea { height: auto; min-height: 70px; resize: vertical; }
    .field input:focus, .field textarea:focus {
        outline: none; border-color: #5b21b6; box-shadow: 0 0 0 3px rgba(91,33,182,0.1);
    }
    .field .help { font-size: 12px; color: #64748b; margin-top: 5px; }
    .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
    @media (max-width: 700px) { .grid-3 { grid-template-columns: 1fr; } }

    .action-btn-primary {
        display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        height: 48px; min-width: 200px; padding: 0 26px;
        background: #5b21b6; color: #ffffff;
        border: 1px solid #5b21b6; border-radius: 8px;
        font-size: 15px; font-weight: 600; font-family: inherit;
        cursor: pointer; line-height: 1;
    }
    .action-btn-primary:hover:not(:disabled) { background: #4c1d95; border-color: #4c1d95; }
    .action-btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }

    .preview-box {
        background: #fffbeb; border: 1px solid #fde68a; border-left: 4px solid #f59e0b;
        padding: 20px 24px; border-radius: 10px; margin: 20px 0;
    }
    .preview-box .lbl {
        font-size: 11px; color: #78350f; font-weight: 800;
        text-transform: uppercase; letter-spacing: 1.2px; margin-bottom: 10px;
    }
    .preview-box .total {
        font-family: 'SF Mono','Monaco',monospace; font-size: 24px; font-weight: 800;
        color: #92400e;
    }

    table.tbl { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; }
    table.tbl thead th {
        background: #f8fafc; font-size: 11px; color: #64748b; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px;
        padding: 10px 12px; text-align: left;
        border-bottom: 2px solid #e2e8f0; white-space: nowrap;
    }
    table.tbl tbody td { padding: 10px 12px; font-size: 13px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .num { font-family: 'SF Mono','Monaco',monospace; text-align: right; font-variant-numeric: tabular-nums; }

    .btn-soft {
        display: inline-flex; align-items: center; gap: 6px;
        height: 32px; padding: 0 12px;
        background: #eff6ff; color: #1e40af;
        border: 1px solid #bfdbfe; border-radius: 6px;
        font-size: 12px; font-weight: 600; font-family: inherit;
        text-decoration: none; cursor: pointer;
    }
    .btn-soft:hover { background: #dbeafe; text-decoration: none; color: #1e40af; }

    .empty-state { padding: 40px; text-align: center; color: #94a3b8; font-style: italic; font-size: 14px; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <h1>Run Depreciation</h1>
            <div class="sub">Charge monthly or annual depreciation on all active assets</div>
        </div>
        <a href="gl_assets.php" class="btn-back">
            <span class="glyphicon glyphicon-arrow-left"></span> Asset Register
        </a>
    </div>

    <div class="card">

        <?php if ($msg): ?>
            <div class="alert-custom <?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <?php if (!$accumAccount || !$expAccount): ?>
            <div class="alert-custom danger">
                <strong>Setup required.</strong> The following accounts must exist before you can run depreciation:
                <br>
                &bull; <code><?= htmlspecialchars($ACCUM_CODE) ?></code> — Accumulated Depreciation (ASSET, is_contra = 1)
                <?= $accumAccount ? ' ✓' : ' ✗' ?>
                <br>
                &bull; <code><?= htmlspecialchars($EXP_CODE) ?></code> — Depreciation Expense (EXPENSE)
                <?= $expAccount ? ' ✓' : ' ✗' ?>
            </div>
        <?php endif; ?>

        <div class="alert-custom info">
            <strong>How it works:</strong>
            <br>
            Pick a period (e.g. one month). The system computes depreciation for every active asset that hasn't been depreciated past this period's end date.
            <br>
            One aggregate journal entry is posted: <em>Dr Depreciation Expense, Cr Accumulated Depreciation</em>.
            <br>
            Each asset's <code>accumulated_depreciation</code> and <code>last_depreciated_through</code> are updated so it isn't charged twice for the same period.
        </div>

        <div class="section-title">New Depreciation Run</div>

        <form method="post" class="form-box" id="runForm">
            <input type="hidden" name="action" value="run">

            <div class="grid-3">
                <div class="field">
                    <label>Period Start <span style="color:#dc2626;">*</span></label>
                    <input type="date" name="period_start" id="f_ps" required value="<?= date('Y-m-01') ?>" oninput="clearPreview()">
                </div>
                <div class="field">
                    <label>Period End <span style="color:#dc2626;">*</span></label>
                    <input type="date" name="period_end" id="f_pe" required value="<?= date('Y-m-t') ?>" oninput="clearPreview()">
                </div>
                <div class="field">
                    <label>Entry Date</label>
                    <input type="date" name="entry_date" id="f_ed" value="<?= date('Y-m-d') ?>">
                    <div class="help">Date the journal entry hits the ledger</div>
                </div>
            </div>

            <div class="field">
                <label>Notes (optional)</label>
                <textarea name="notes" placeholder="e.g. Monthly depreciation for October 2026"></textarea>
            </div>

            <div style="display:flex; gap:12px; flex-wrap:wrap;">
                <button type="button" class="action-btn-primary" style="background:#f1f5f9; color:#334155; border-color:#cbd5e1;" onclick="doPreview()">
                    <span class="glyphicon glyphicon-search"></span> Preview
                </button>
                <?php if (!empty($perms['can_add'])): ?>
                <button type="submit" class="action-btn-primary" id="btnRun" <?= ($accumAccount && $expAccount) ? '' : 'disabled' ?>>
                    <span class="glyphicon glyphicon-ok"></span> Post Depreciation
                </button>
                <?php endif; ?>
            </div>
        </form>

        <!-- Preview panel -->
        <div id="previewPanel" style="display:none;">
            <div class="preview-box">
                <div class="lbl">Preview</div>
                <div>Assets to be charged: <strong id="pv_count">0</strong></div>
                <div>Period: <strong id="pv_period">—</strong></div>
                <div style="margin-top:12px;">
                    <div class="lbl">Total Charge</div>
                    <div class="total" id="pv_total">0.00</div>
                </div>
            </div>

            <table class="tbl" style="margin-bottom:24px;">
                <thead>
                    <tr>
                        <th style="width:100px;">Code</th>
                        <th>Asset</th>
                        <th class="num" style="width:120px;">Cost</th>
                        <th class="num" style="width:120px;">Accum. Before</th>
                        <th class="num" style="width:120px;">Book Before</th>
                        <th class="num" style="width:120px;">Charge</th>
                        <th class="num" style="width:120px;">Book After</th>
                    </tr>
                </thead>
                <tbody id="pv_tbody"></tbody>
            </table>
        </div>

        <div class="section-title">Recent Depreciation Runs</div>

        <?php if (empty($runs)): ?>
            <div class="empty-state">No depreciation runs yet.</div>
        <?php else: ?>
            <table class="tbl">
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th style="width:130px;">Period</th>
                        <th style="width:110px;">Entry Date</th>
                        <th class="num" style="width:90px;">Assets</th>
                        <th class="num" style="width:140px;">Total</th>
                        <th style="width:120px;">Status</th>
                        <th>Posted By</th>
                        <th class="no-print" style="width:110px;">Journal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($runs as $r): ?>
                        <tr>
                            <td>#<?= (int)$r['id'] ?></td>
                            <td><?= htmlspecialchars($r['period_start']) ?> → <?= htmlspecialchars($r['period_end']) ?></td>
                            <td><?= htmlspecialchars($r['entry_date']) ?></td>
                            <td class="num"><?= (int)$r['asset_count'] ?></td>
                            <td class="num" style="font-weight:700;"><?= number_format((float)$r['total_amount'], 2) ?></td>
                            <td><?= htmlspecialchars($r['status']) ?></td>
                            <td><?= htmlspecialchars($r['posted_by_name'] ?: '—') ?></td>
                            <td class="no-print">
                                <?php if ($r['gl_txn_id']): ?>
                                    <a href="gl_transaction_view.php?id=<?= (int)$r['gl_txn_id'] ?>" class="btn-soft">
                                        TXN #<?= (int)$r['gl_txn_id'] ?>
                                    </a>
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
function clearPreview() {
    document.getElementById('previewPanel').style.display = 'none';
}

function doPreview() {
    var ps = document.getElementById('f_ps').value;
    var pe = document.getElementById('f_pe').value;
    if (!ps || !pe) { alert('Both period dates required.'); return; }

    var fd = new FormData();
    fd.append('ajax_action', 'preview');
    fd.append('period_start', ps);
    fd.append('period_end', pe);

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(resp){
            if (!resp.ok) { alert('Preview failed: ' + (resp.error || 'unknown')); return; }

            document.getElementById('pv_count').textContent = resp.asset_count;
            document.getElementById('pv_period').textContent = resp.period_start + ' → ' + resp.period_end;
            document.getElementById('pv_total').textContent =
                Number(resp.total).toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            var tbody = document.getElementById('pv_tbody');
            tbody.innerHTML = '';

            if (resp.rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:20px; color:#94a3b8;">No assets to depreciate in this period.</td></tr>';
            } else {
                resp.rows.forEach(function(r){
                    var tr = document.createElement('tr');
                    tr.innerHTML =
                        '<td><code>' + r.code + '</code></td>' +
                        '<td>' + r.name + '</td>' +
                        '<td class="num">' + Number(r.cost).toLocaleString('en-PK', {minimumFractionDigits:2}) + '</td>' +
                        '<td class="num">' + Number(r.accumulated).toLocaleString('en-PK', {minimumFractionDigits:2}) + '</td>' +
                        '<td class="num">' + Number(r.book_before).toLocaleString('en-PK', {minimumFractionDigits:2}) + '</td>' +
                        '<td class="num" style="font-weight:700; color:#5b21b6;">' + Number(r.charge).toLocaleString('en-PK', {minimumFractionDigits:2}) + '</td>' +
                        '<td class="num" style="font-weight:700; color:#047857;">' + Number(r.book_after).toLocaleString('en-PK', {minimumFractionDigits:2}) + '</td>';
                    tbody.appendChild(tr);
                });
            }

            document.getElementById('previewPanel').style.display = '';
            document.getElementById('previewPanel').scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
}
</script>

</body>
</html>