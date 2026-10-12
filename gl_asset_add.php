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

$editId = (int)($_GET['id'] ?? 0);
$isEdit = $editId > 0;

$errors = [];

/* ---------- Form state ---------- */
$form = [
    'id'                    => 0,
    'asset_code'            => '',
    'name'                  => '',
    'description'           => '',
    'category'              => '',
    'purchase_date'         => date('Y-m-d'),
    'cost'                  => '',
    'salvage_value'         => '0.00',
    'useful_life_years'     => '5',
    'depreciation_method'   => 'straight_line',
    'reducing_rate_pct'     => '20.00',
    'notes'                 => '',
    'accumulated_depreciation' => 0,
];

/* ---------- Load existing for edit ---------- */
if ($isEdit) {
    $existing = gl_get_asset($conn, $editId);
    if (!$existing) {
        http_response_code(404);
        die('Asset not found.');
    }
    if ($existing['status'] !== 'active') {
        die('Only active assets can be edited. This asset is ' . htmlspecialchars($existing['status']) . '.');
    }
    $form = array_merge($form, $existing);
    $form['reducing_rate_pct'] = $existing['reducing_rate_pct'] !== null ? $existing['reducing_rate_pct'] : '20.00';
}

/* ---------- Handle POST ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['asset_code']          = trim($_POST['asset_code'] ?? '');
    $form['name']                = trim($_POST['name'] ?? '');
    $form['description']         = trim($_POST['description'] ?? '');
    $form['category']            = trim($_POST['category'] ?? '');
    $form['purchase_date']       = $_POST['purchase_date'] ?? date('Y-m-d');
    $form['cost']                = $_POST['cost'] ?? '';
    $form['salvage_value']       = $_POST['salvage_value'] ?? '0.00';
    $form['useful_life_years']   = $_POST['useful_life_years'] ?? '5';
    $form['depreciation_method'] = $_POST['depreciation_method'] ?? 'straight_line';
    $form['reducing_rate_pct']   = $_POST['reducing_rate_pct'] ?? '20.00';
    $form['notes']               = trim($_POST['notes'] ?? '');
    $form['id']                  = (int)($_POST['id'] ?? 0);

    $cost           = (float)$form['cost'];
    $salvage        = (float)$form['salvage_value'];
    $life           = (int)$form['useful_life_years'];
    $reducingRate   = (float)$form['reducing_rate_pct'];
    $method         = $form['depreciation_method'];

    if ($form['asset_code'] === '')  $errors[] = 'Asset code is required.';
    if ($form['name'] === '')        $errors[] = 'Asset name is required.';
    if (!strtotime($form['purchase_date'])) $errors[] = 'Valid purchase date required.';
    if ($cost <= 0)                  $errors[] = 'Cost must be greater than 0.';
    if ($salvage < 0)                $errors[] = 'Salvage value cannot be negative.';
    if ($salvage >= $cost)           $errors[] = 'Salvage value must be less than cost.';
    if ($life <= 0 || $life > 100)   $errors[] = 'Useful life must be between 1 and 100 years.';
    if (!in_array($method, ['straight_line','reducing_balance'], true)) $errors[] = 'Invalid depreciation method.';
    if ($method === 'reducing_balance' && ($reducingRate <= 0 || $reducingRate > 100)) {
        $errors[] = 'Reducing balance rate must be between 0 and 100.';
    }

    if (empty($errors)) {
        $reducingRateOrNull = ($method === 'reducing_balance') ? $reducingRate : null;
        $purchaseDate       = $form['purchase_date'];
        $code               = $form['asset_code'];
        $name               = $form['name'];
        $desc               = $form['description'];
        $category           = $form['category'];
        $notes              = $form['notes'];

        if ($isEdit) {
            $stmt = $conn->prepare("
                UPDATE gl_assets SET
                    asset_code = ?, name = ?, description = ?, category = ?,
                    purchase_date = ?, cost = ?, salvage_value = ?,
                    useful_life_years = ?, depreciation_method = ?, reducing_rate_pct = ?,
                    notes = ?
                WHERE id = ?
            ");
            // 12 params: s s s s s d d i s s i i
            $stmt->bind_param(
                "sssssddissii",
                $code, $name, $desc, $category, $purchaseDate,
                $cost, $salvage, $life, $method, $reducingRateOrNull, $notes, $editId
            );
            if ($stmt->execute()) {
                $stmt->close();
                $_SESSION['asset_msg'] = 'Asset updated successfully.';
                $_SESSION['asset_msg_type'] = 'success';
                header("Location: gl_assets.php");
                exit;
            } else {
                $errors[] = 'Update failed: ' . $stmt->error;
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare("
                INSERT INTO gl_assets
                  (asset_code, name, description, category, purchase_date, cost, salvage_value,
                   useful_life_years, depreciation_method, reducing_rate_pct, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            // 12 params: s s s s s d d i s s i i
            $stmt->bind_param(
                "sssssddissii",
                $code, $name, $desc, $category, $purchaseDate,
                $cost, $salvage, $life, $method, $reducingRateOrNull, $notes, $userId
            );
            if ($stmt->execute()) {
                $newId = $conn->insert_id;
                $stmt->close();
                $_SESSION['asset_msg'] = 'Asset added successfully (ID ' . $newId . ').';
                $_SESSION['asset_msg_type'] = 'success';
                header("Location: gl_assets.php");
                exit;
            } else {
                $errors[] = 'Insert failed: ' . $stmt->error;
                $stmt->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= $isEdit ? 'Edit Asset' : 'Add Asset' ?> — Dar-e-Arqm School</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    * { box-sizing: border-box; }
    body { background: #eef1f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 0; color: #1e293b; }
    .wrap { max-width: 1000px; margin: 30px auto; padding: 0 20px; }

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
        letter-spacing: 2px; margin: 28px 0 14px 0; padding-bottom: 8px;
        border-bottom: 1px solid #e2e8f0;
    }
    .section-title:first-child { margin-top: 0; }

    .error-box {
        background: #fee2e2; border-left: 4px solid #dc2626;
        padding: 14px 18px; border-radius: 8px; margin-bottom: 20px;
        color: #991b1b; font-size: 14px;
    }
    .error-box ul { margin: 6px 0 0 20px; padding: 0; }

    .field { margin-bottom: 18px; }
    .field label {
        display: block; font-size: 12px; font-weight: 700; color: #334155;
        text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px;
    }
    .field .required { color: #dc2626; }
    .field input, .field select, .field textarea {
        width: 100%; height: 46px; padding: 10px 14px;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 15px; font-family: inherit; background: #fff; color: #0f172a;
    }
    .field textarea { height: auto; min-height: 80px; resize: vertical; }
    .field input:focus, .field select:focus, .field textarea:focus {
        outline: none; border-color: #5b21b6; box-shadow: 0 0 0 3px rgba(91,33,182,0.1);
    }
    .field .help { font-size: 12px; color: #64748b; margin-top: 5px; line-height: 1.5; }

    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
    @media (max-width: 800px) { .grid-2, .grid-3 { grid-template-columns: 1fr; } }

    .info-box {
        background: #eff6ff; border: 1px solid #bfdbfe; border-left: 4px solid #1e40af;
        padding: 14px 18px; border-radius: 10px; font-size: 13px;
        color: #1e3a8a; line-height: 1.6; margin-bottom: 24px;
    }
    .info-box code { background: #dbeafe; padding: 2px 6px; border-radius: 4px; font-size: 12px; }

    .actions {
        display: flex; justify-content: flex-end; gap: 12px;
        margin-top: 30px; padding-top: 24px; border-top: 1px solid #e2e8f0;
        flex-wrap: wrap;
    }
    .action-btn-primary {
        display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        height: 48px; min-width: 180px; padding: 0 26px;
        background: #5b21b6; color: #ffffff;
        border: 1px solid #5b21b6; border-radius: 8px;
        font-size: 15px; font-weight: 600; font-family: inherit;
        cursor: pointer; line-height: 1; white-space: nowrap;
        text-decoration: none;
    }
    .action-btn-primary:hover { background: #4c1d95; border-color: #4c1d95; color: #fff; text-decoration: none; }
    .action-btn-cancel {
        display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        height: 48px; min-width: 140px; padding: 0 22px;
        background: #f1f5f9; color: #334155;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 15px; font-weight: 600; font-family: inherit;
        cursor: pointer; line-height: 1;
        text-decoration: none;
    }
    .action-btn-cancel:hover { background: #e2e8f0; color: #1e293b; text-decoration: none; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <h1><?= $isEdit ? 'Edit Asset' : 'Add Asset' ?></h1>
            <div class="sub">
                <?= $isEdit
                    ? 'Update asset details. Accumulated depreciation is not editable here.'
                    : 'Register a fixed asset and its depreciation settings' ?>
            </div>
        </div>
        <a href="gl_assets.php" class="btn-back">
            <span class="glyphicon glyphicon-arrow-left"></span> Back to Register
        </a>
    </div>

    <div class="card">

        <?php if (!empty($errors)): ?>
            <div class="error-box">
                <strong>✗ Please fix the following:</strong>
                <ul>
                    <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!$isEdit): ?>
            <div class="info-box">
                <strong>What happens when you save:</strong> The asset is registered with cost, useful life, and depreciation method.
                No journal entry is posted here. Depreciation is charged later via the <code>Run Depreciation</code> page.
                <br><br>
                If you haven't already recorded the purchase in the ledger, do so separately via a voucher or manual journal entry
                (typically <code>Dr 1500 Fixed Assets, Cr Cash/Bank</code>).
            </div>
        <?php else: ?>
            <div class="info-box">
                <strong>Accumulated depreciation</strong> is currently
                <code><?= number_format((float)$form['accumulated_depreciation'], 2) ?></code>
                and cannot be edited directly. To correct it, reverse the corresponding depreciation run and post a new one.
            </div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">

            <div class="section-title">Identification</div>

            <div class="grid-2">
                <div class="field">
                    <label>Asset Code <span class="required">*</span></label>
                    <input type="text" name="asset_code" required maxlength="30"
                           value="<?= htmlspecialchars($form['asset_code']) ?>"
                           placeholder="e.g. BUS-001, COMP-014">
                    <div class="help">Short, unique identifier. Cannot be duplicated.</div>
                </div>
                <div class="field">
                    <label>Category</label>
                    <input type="text" name="category" maxlength="50"
                           value="<?= htmlspecialchars($form['category']) ?>"
                           placeholder="Vehicle / Furniture / Computer / Building">
                </div>
            </div>

            <div class="field">
                <label>Name <span class="required">*</span></label>
                <input type="text" name="name" required maxlength="150"
                       value="<?= htmlspecialchars($form['name']) ?>"
                       placeholder="e.g. School Bus (Suzuki Bolan)">
            </div>

            <div class="field">
                <label>Description</label>
                <textarea name="description" placeholder="Make / model / serial number / any other identifying detail"><?= htmlspecialchars($form['description']) ?></textarea>
            </div>

            <div class="section-title">Acquisition</div>

            <div class="grid-2">
                <div class="field">
                    <label>Purchase Date <span class="required">*</span></label>
                    <input type="date" name="purchase_date" required
                           value="<?= htmlspecialchars($form['purchase_date']) ?>">
                    <div class="help">Depreciation will be charged from this date onward.</div>
                </div>
                <div class="field">
                    <label>Cost (PKR) <span class="required">*</span></label>
                    <input type="number" name="cost" step="0.01" min="0.01" required
                           value="<?= htmlspecialchars($form['cost']) ?>"
                           placeholder="0.00">
                </div>
            </div>

            <div class="section-title">Depreciation Settings</div>

            <div class="grid-3">
                <div class="field">
                    <label>Salvage Value</label>
                    <input type="number" name="salvage_value" step="0.01" min="0"
                           value="<?= htmlspecialchars($form['salvage_value']) ?>">
                    <div class="help">Value at end of useful life (usually 0)</div>
                </div>
                <div class="field">
                    <label>Useful Life (years) <span class="required">*</span></label>
                    <input type="number" name="useful_life_years" min="1" max="100" required
                           value="<?= htmlspecialchars($form['useful_life_years']) ?>">
                </div>
                <div class="field">
                    <label>Method</label>
                    <select name="depreciation_method" id="f_method" onchange="toggleRate()">
                        <option value="straight_line"   <?= $form['depreciation_method'] === 'straight_line'   ? 'selected' : '' ?>>Straight Line</option>
                        <option value="reducing_balance" <?= $form['depreciation_method'] === 'reducing_balance' ? 'selected' : '' ?>>Reducing Balance</option>
                    </select>
                </div>
            </div>

            <div class="field" id="rate_field" style="<?= $form['depreciation_method'] === 'reducing_balance' ? '' : 'display:none;' ?>">
                <label>Reducing Balance Rate (%)</label>
                <input type="number" name="reducing_rate_pct" step="0.01" min="0" max="100"
                       value="<?= htmlspecialchars($form['reducing_rate_pct']) ?>">
                <div class="help">Annual percentage applied to book value. Common rates: 15–25%.</div>
            </div>

            <div class="section-title">Notes</div>

            <div class="field">
                <textarea name="notes" placeholder="Optional notes — warranty terms, location, custodian, etc."><?= htmlspecialchars($form['notes']) ?></textarea>
            </div>

            <div class="actions">
                <a href="gl_assets.php" class="action-btn-cancel">Cancel</a>
                <?php if ($isEdit ? !empty($perms['can_edit']) : !empty($perms['can_add'])): ?>
                <button type="submit" class="action-btn-primary">
                    <span class="glyphicon glyphicon-ok"></span> <?= $isEdit ? 'Save Changes' : 'Save Asset' ?>
                </button>
                <?php endif; ?>
            </div>
        </form>

    </div>
</div>

<script>
function toggleRate() {
    var method = document.getElementById('f_method').value;
    document.getElementById('rate_field').style.display = (method === 'reducing_balance') ? '' : 'none';
}
document.addEventListener('DOMContentLoaded', toggleRate);
</script>

</body>
</html>