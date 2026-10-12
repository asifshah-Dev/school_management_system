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
$msg = $_SESSION['asset_msg'] ?? ''; $msg_type = $_SESSION['asset_msg_type'] ?? '';
unset($_SESSION['asset_msg'], $_SESSION['asset_msg_type']);

/* ---------- Dispose (inline — small action) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dispose') {
    $id = (int)($_POST['id'] ?? 0);
    $disposalDate = $_POST['disposal_date'] ?? date('Y-m-d');
    $proceeds = (float)($_POST['disposal_proceeds'] ?? 0);
    $stmt = $conn->prepare("
        UPDATE gl_assets
        SET status = 'disposed', disposal_date = ?, disposal_proceeds = ?
        WHERE id = ?
    ");
    $stmt->bind_param("sdi", $disposalDate, $proceeds, $id);
    if ($stmt->execute()) {
        $stmt->close();
        $_SESSION['asset_msg'] = 'Asset marked disposed.';
        $_SESSION['asset_msg_type'] = 'success';
    } else {
        $err = $stmt->error; $stmt->close();
        $_SESSION['asset_msg'] = 'Disposal failed: ' . $err;
        $_SESSION['asset_msg_type'] = 'danger';
    }
    header("Location: gl_assets.php");
    exit;
}

$assets = $conn->query("SELECT * FROM gl_assets ORDER BY status ASC, purchase_date DESC, id DESC")->fetch_all(MYSQLI_ASSOC);

$totalCost = 0.0; $totalAccum = 0.0; $totalNet = 0.0;
$activeCount = 0;
foreach ($assets as $a) {
    if ($a['status'] !== 'active') continue;
    $activeCount++;
    $totalCost  += (float)$a['cost'];
    $totalAccum += (float)$a['accumulated_depreciation'];
    $totalNet   += gl_asset_book_value($a);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Asset Register — Dar-e-Arqm School</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    * { box-sizing: border-box; }
    body { background: #eef1f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 0; color: #1e293b; }
    .wrap { max-width: 1500px; margin: 30px auto; padding: 0 20px; }

    .head {
        background: linear-gradient(135deg, #5b21b6 0%, #6d28d9 100%);
        color: #fff; padding: 30px 40px 26px 40px;
        border-radius: 12px 12px 0 0; box-shadow: 0 4px 16px rgba(91,33,182,0.15);
        display: flex; justify-content: space-between; align-items: flex-end;
        gap: 20px; flex-wrap: wrap;
    }
    .head h1 { margin: 0; font-size: 30px; font-weight: 700; }
    .head .sub { font-size: 14px; opacity: 0.9; margin-top: 6px; }

    .btn-back, .btn-primary-white {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 10px 20px; background: #ffffff; color: #5b21b6;
        border: 1px solid #ffffff; border-radius: 8px;
        text-decoration: none; font-size: 14px; font-weight: 600;
        line-height: 1; font-family: inherit; cursor: pointer;
    }
    .btn-back:hover, .btn-primary-white:hover { background: #ede9fe; border-color: #ede9fe; text-decoration: none; color: #5b21b6; }

    .card { background: #fff; padding: 30px 40px 40px 40px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

    .alert-custom { padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; border-left: 4px solid; }
    .alert-custom.success { background: #d1fae5; border-color: #059669; color: #065f46; }
    .alert-custom.danger  { background: #fee2e2; border-color: #dc2626; color: #991b1b; }

    .summary-bar {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(200px,1fr));
        gap: 12px; margin-bottom: 24px;
    }
    .summary-box {
        background: #f8fafc; border: 1px solid #e2e8f0;
        border-radius: 10px; padding: 16px 18px; border-left: 5px solid;
    }
    .summary-box .lbl { font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
    .summary-box .val { font-family: 'SF Mono','Monaco',monospace; font-size: 20px; font-weight: 700; color: #0f172a; }
    .summary-box.s-cost   { border-color: #5b21b6; }
    .summary-box.s-accum  { border-color: #b91c1c; }
    .summary-box.s-net    { border-color: #047857; background: #ecfdf5; }
    .summary-box.s-count  { border-color: #0891b2; }

    table.tbl { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; }
    table.tbl thead th {
        background: #f8fafc; font-size: 11px; color: #64748b; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px;
        padding: 12px 12px; text-align: left;
        border-bottom: 2px solid #e2e8f0; white-space: nowrap;
    }
    table.tbl tbody td { padding: 12px; font-size: 13px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    table.tbl tbody tr:hover td { background: #f8fafc; }
    .num { font-family: 'SF Mono','Monaco',monospace; text-align: right; font-variant-numeric: tabular-nums; }

    .badge { display: inline-block; padding: 3px 9px; border-radius: 8px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
    .badge.active    { background: #d1fae5; color: #065f46; }
    .badge.disposed  { background: #f1f5f9; color: #64748b; }
    .badge.written_off { background: #fee2e2; color: #991b1b; }

    .row-btn {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 7px 12px; border-radius: 6px;
        font-size: 12px; font-weight: 700;
        text-decoration: none; cursor: pointer;
        font-family: inherit; border: 1px solid;
        transition: all 0.15s; white-space: nowrap;
        margin-right: 4px; line-height: 1;
    }
    .row-btn-info { background: #eff6ff; color: #1e40af; border-color: #bfdbfe; }
    .row-btn-info:hover { background: #dbeafe; text-decoration: none; color: #1e40af; }
    .row-btn-danger { background: #dc2626; color: #ffffff; border-color: #dc2626; }
    .row-btn-danger:hover { background: #b91c1c; border-color: #b91c1c; text-decoration: none; color: #ffffff; }
    .row-btn .glyphicon { color: inherit; }

    .empty-state { padding: 60px 20px; text-align: center; color: #94a3b8; font-style: italic; }
    .empty-state .big { font-size: 48px; margin-bottom: 12px; opacity: 0.4; }
    .empty-state .title { font-size: 18px; color: #475569; font-style: normal; font-weight: 600; margin-bottom: 6px; }

    /* ============ Dispose Modal — compact centered ============ */
    .dispose-overlay {
        display: none;
        position: fixed; inset: 0;
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(3px);
        z-index: 9999;
        align-items: center;
        justify-content: center;
        padding: 30px 20px;
        overflow-y: auto;
    }
    .dispose-overlay.open { display: flex; }

    .dispose-box {
        background: #fff;
        width: 100%;
        max-width: 520px;
        border-radius: 14px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35);
        overflow: hidden;
        animation: disposeIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }
    @keyframes disposeIn {
        from { opacity: 0; transform: scale(0.96) translateY(-10px); }
        to   { opacity: 1; transform: scale(1) translateY(0); }
    }

    .dispose-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        padding: 22px 26px 18px 26px;
        background: linear-gradient(135deg, #991b1b 0%, #b91c1c 100%);
        color: #fff;
        gap: 14px;
    }
    .dispose-head h3 {
        margin: 0;
        font-size: 19px;
        font-weight: 700;
        line-height: 1.2;
    }
    .dispose-sub {
        font-size: 13px;
        opacity: 0.88;
        margin-top: 4px;
        font-weight: 500;
    }
    .dispose-close {
        background: rgba(255,255,255,0.15);
        border: 1px solid rgba(255,255,255,0.3);
        color: #fff;
        width: 34px;
        height: 34px;
        border-radius: 9px;
        font-size: 20px;
        line-height: 1;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        padding: 0;
        font-family: inherit;
        transition: all 0.15s;
    }
    .dispose-close:hover { background: #fff; color: #991b1b; border-color: #fff; }

    .dispose-body {
        padding: 24px 26px 4px 26px;
    }

    .dispose-field {
        margin-bottom: 18px;
    }
    .dispose-field label {
        display: block;
        font-size: 12px;
        font-weight: 700;
        color: #334155;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        margin-bottom: 7px;
    }
    .dispose-field .req { color: #dc2626; }
    .dispose-field input {
        width: 100%;
        height: 46px;
        padding: 10px 14px;
        border: 1px solid #cbd5e1;
        border-radius: 9px;
        font-size: 15px;
        font-family: inherit;
        background: #fff;
        color: #0f172a;
        transition: all 0.15s;
    }
    .dispose-field input:focus {
        outline: none;
        border-color: #991b1b;
        box-shadow: 0 0 0 4px rgba(153, 27, 27, 0.12);
    }
    .dispose-help {
        display: block;
        font-size: 12px;
        color: #64748b;
        margin-top: 6px;
        line-height: 1.5;
    }

    .dispose-note {
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-left: 4px solid #dc2626;
        padding: 12px 16px;
        border-radius: 9px;
        font-size: 13px;
        color: #991b1b;
        line-height: 1.6;
        margin-bottom: 8px;
    }

    .dispose-foot {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        padding: 16px 26px 20px 26px;
        background: #f8fafc;
        border-top: 1px solid #e2e8f0;
    }

    .dispose-btn-cancel {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        height: 44px;
        min-width: 110px;
        padding: 0 20px;
        background: #fff;
        color: #334155;
        border: 1px solid #cbd5e1;
        border-radius: 9px;
        font-size: 14px;
        font-weight: 600;
        font-family: inherit;
        cursor: pointer;
        transition: all 0.15s;
    }
    .dispose-btn-cancel:hover { background: #f1f5f9; border-color: #94a3b8; }

    .dispose-btn-primary {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        height: 44px;
        min-width: 180px;
        padding: 0 22px;
        background: #991b1b;
        color: #fff;
        border: 1px solid #991b1b;
        border-radius: 9px;
        font-size: 14px;
        font-weight: 600;
        font-family: inherit;
        cursor: pointer;
        transition: all 0.15s;
    }
    .dispose-btn-primary:hover {
        background: #7f1d1d;
        border-color: #7f1d1d;
        box-shadow: 0 6px 16px rgba(153, 27, 27, 0.3);
    }

    @media (max-width: 600px) {
        .dispose-box { max-width: 100%; border-radius: 12px; }
        .dispose-head { padding: 18px 20px; }
        .dispose-head h3 { font-size: 17px; }
        .dispose-body { padding: 20px 20px 4px 20px; }
        .dispose-foot { padding: 14px 20px 18px 20px; flex-direction: column-reverse; }
        .dispose-btn-cancel, .dispose-btn-primary { width: 100%; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <h1>Asset Register</h1>
            <div class="sub">Fixed assets, useful life, and depreciation settings</div>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="gl_depreciation_run.php" class="btn-back">
                <span class="glyphicon glyphicon-cog"></span> Run Depreciation
            </a>
            <?php if (!empty($perms['can_add'])): ?>
            <a href="gl_asset_add.php" class="btn-primary-white">
                <span class="glyphicon glyphicon-plus"></span> Add Asset
            </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">

        <?php if ($msg): ?>
            <div class="alert-custom <?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <div class="summary-bar">
            <div class="summary-box s-count">
                <div class="lbl">Active Assets</div>
                <div class="val"><?= $activeCount ?></div>
            </div>
            <div class="summary-box s-cost">
                <div class="lbl">Total Cost</div>
                <div class="val"><?= number_format($totalCost, 2) ?></div>
            </div>
            <div class="summary-box s-accum">
                <div class="lbl">Accumulated Depreciation</div>
                <div class="val"><?= number_format($totalAccum, 2) ?></div>
            </div>
            <div class="summary-box s-net">
                <div class="lbl">Net Book Value</div>
                <div class="val"><?= number_format($totalNet, 2) ?></div>
            </div>
        </div>

        <?php if (empty($assets)): ?>
            <div class="empty-state">
                <div class="big">&#127963;&#65039;</div>
                <div class="title">No assets registered</div>
                Click <strong>Add Asset</strong> to register your first fixed asset.
            </div>
        <?php else: ?>
            <table class="tbl">
                <thead>
                    <tr>
                        <th style="width:60px;">#</th>
                        <th style="width:100px;">Code</th>
                        <th>Name</th>
                        <th style="width:100px;">Category</th>
                        <th style="width:110px;">Purchased</th>
                        <th class="num" style="width:120px;">Cost</th>
                        <th class="num" style="width:130px;">Accum. Dep.</th>
                        <th class="num" style="width:130px;">Net Book Value</th>
                        <th style="width:90px;">Status</th>
                        <?php if (!empty($perms['can_edit']) || !empty($perms['can_delete'])): ?>
<th class="no-print" style="width:180px;">Actions</th>
<?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($assets as $a):
                        $bookValue = gl_asset_book_value($a);
                    ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><small style="font-family:'SF Mono',monospace; font-weight:700;"><?= htmlspecialchars($a['asset_code']) ?></small></td>
                            <td>
                                <strong><?= htmlspecialchars($a['name']) ?></strong>
                                <?php if ($a['description']): ?>
                                    <br><small style="color:#64748b;"><?= htmlspecialchars(substr($a['description'], 0, 80)) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($a['category'] ?: '—') ?></td>
                            <td><?= htmlspecialchars($a['purchase_date']) ?></td>
                            <td class="num"><?= number_format((float)$a['cost'], 2) ?></td>
                            <td class="num" style="color:#b91c1c;"><?= number_format((float)$a['accumulated_depreciation'], 2) ?></td>
                            <td class="num" style="color:#047857; font-weight:700;"><?= number_format($bookValue, 2) ?></td>
                            <td><span class="badge <?= $a['status'] ?>"><?= htmlspecialchars($a['status']) ?></span></td>
                            <?php if (!empty($perms['can_edit']) || !empty($perms['can_delete'])): ?>
<td class="no-print">
                                <?php if ($a['status'] === 'active'): ?>
                                    <?php if (!empty($perms['can_edit'])): ?>
                                    <a href="gl_asset_add.php?id=<?= (int)$a['id'] ?>" class="row-btn row-btn-info">
                                        <span class="glyphicon glyphicon-pencil"></span> Edit
                                    </a>
                                    <?php endif; ?>
                                    <?php if (!empty($perms['can_delete'])): ?>
                                    <button type="button" class="row-btn row-btn-danger" onclick='openDispose(<?= (int)$a['id'] ?>, <?= json_encode($a["name"], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                        <span class="glyphicon glyphicon-remove"></span> Dispose
                                    </button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#94a3b8; font-size:12px;">—</span>
                                <?php endif; ?>
                            </td>
<?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    </div>
</div>

<!-- Dispose Modal — compact centered -->
<div class="dispose-overlay" id="disposeModal">
    <div class="dispose-box" role="dialog">
        <div class="dispose-head">
            <div>
                <h3>Dispose Asset</h3>
                <div class="dispose-sub" id="disposeName"></div>
            </div>
            <button type="button" class="dispose-close" onclick="closeDispose()" aria-label="Close">&times;</button>
        </div>
        <form method="post">
            <div class="dispose-body">
                <input type="hidden" name="action" value="dispose">
                <input type="hidden" name="id" id="dispose_id">

                <div class="dispose-field">
                    <label>Disposal Date <span class="req">*</span></label>
                    <input type="date" name="disposal_date" required value="<?= date('Y-m-d') ?>">
                </div>

                <div class="dispose-field">
                    <label>Proceeds (PKR)</label>
                    <input type="number" name="disposal_proceeds" step="0.01" min="0" value="0.00">
                    <small class="dispose-help">Cash received, if sold. Enter 0 if scrapped.</small>
                </div>

                <div class="dispose-note">
                    <strong>Note:</strong> This marks the asset as disposed. You may need to post a manual journal entry to remove the cost and accumulated depreciation from the balance sheet and record any gain/loss.
                </div>
            </div>

            <div class="dispose-foot">
                <button type="button" class="dispose-btn-cancel" onclick="closeDispose()">Cancel</button>
                <button type="submit" class="dispose-btn-primary">
                    <span class="glyphicon glyphicon-remove"></span> Confirm Disposal
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openDispose(id, name) {
    document.getElementById('dispose_id').value = id;
    document.getElementById('disposeName').textContent = name;
    document.getElementById('disposeModal').classList.add('open');
    document.body.style.overflow = 'hidden';
    setTimeout(function() {
        var inp = document.querySelector('#disposeModal input[name="disposal_date"]');
        if (inp) inp.focus();
    }, 250);
}
function closeDispose() {
    document.getElementById('disposeModal').classList.remove('open');
    document.body.style.overflow = '';
}
document.getElementById('disposeModal').addEventListener('click', function(e){
    if (e.target === this) closeDispose();
});
document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') closeDispose();
});
</script>

</body>
</html>