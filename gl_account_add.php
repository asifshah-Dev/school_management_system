<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");

$errors = [];
$form = [
    'code'        => '',
    'name'        => '',
    'account_type'=> '',
    'parent_id'   => '',
    'description' => '',
    'is_postable' => '1',
];

$addingUnder = null;

if (isset($_GET['parent_id']) && ctype_digit((string)$_GET['parent_id'])) {
    $form['parent_id'] = (string)$_GET['parent_id'];
    $parentAcc = gl_get_account($conn, (int)$_GET['parent_id']);
    if ($parentAcc) {
        $form['account_type'] = $parentAcc['account_type'];
        $addingUnder = $parentAcc;
        $suggested = gl_suggest_next_code($conn, $parentAcc['code']);
        if ($suggested !== null) $form['code'] = $suggested;
    }
} elseif (isset($_GET['type']) && in_array($_GET['type'], ['ASSET','LIABILITY','EQUITY','REVENUE','EXPENSE'], true)) {
    $form['account_type'] = $_GET['type'];
    $suggested = gl_suggest_next_code_top_level($conn, $form['account_type']);
    if ($suggested !== null) $form['code'] = $suggested;
}

$parentsByType = [
    'ASSET'     => gl_list_accounts($conn, 'ASSET', true),
    'LIABILITY' => gl_list_accounts($conn, 'LIABILITY', true),
    'EQUITY'    => gl_list_accounts($conn, 'EQUITY', true),
    'REVENUE'   => gl_list_accounts($conn, 'REVENUE', true),
    'EXPENSE'   => gl_list_accounts($conn, 'EXPENSE', true),
];

$parentOptions = [];
foreach ($parentsByType as $type => $list) {
    $parentOptions[$type] = [];
    foreach ($list as $p) {
        if ((int)$p['is_postable'] === 1) continue;
        $suggest = gl_suggest_next_code($conn, $p['code']);
        $parentOptions[$type][] = [
            'id'      => (int)$p['id'],
            'code'    => $p['code'],
            'name'    => $p['name'],
            'suggest' => $suggest ?: '',
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_account'])) {
    $form['code']         = trim($_POST['code'] ?? '');
    $form['name']         = trim($_POST['name'] ?? '');
    $form['account_type'] = trim($_POST['account_type'] ?? '');
    $form['parent_id']    = trim($_POST['parent_id'] ?? '');
    $form['description']  = trim($_POST['description'] ?? '');
    $form['is_postable']  = isset($_POST['is_postable']) && $_POST['is_postable'] === '0' ? '0' : '1';

    if ($form['code'] === '') $errors[] = 'Account code is required.';
    elseif (!ctype_digit($form['code'])) $errors[] = 'Code must be numeric (digits only).';
    if ($form['name'] === '') $errors[] = 'Account name is required.';
    if ($form['account_type'] === '') $errors[] = 'Account type is required.';
    elseif (!in_array($form['account_type'], ['ASSET','LIABILITY','EQUITY','REVENUE','EXPENSE'], true)) $errors[] = 'Invalid account type.';

    if (empty($errors)) {
        $check = gl_get_account_by_code($conn, $form['code']);
        if ($check !== null) $errors[] = 'This account code already exists. Choose a different one.';
    }

    $parentId = null;
    if ($form['parent_id'] !== '' && empty($errors)) {
        if (!ctype_digit($form['parent_id'])) {
            $errors[] = 'Invalid parent account.';
        } else {
            $parentId = (int)$form['parent_id'];
            $parent = gl_get_account($conn, $parentId);
            if (!$parent) $errors[] = 'Parent account not found.';
            elseif ($parent['account_type'] !== $form['account_type']) $errors[] = 'Parent type does not match the selected account type.';
            elseif ((int)$parent['is_postable'] === 1) $errors[] = 'Parent must be a grouping account (Postable = No).';
        }
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("INSERT INTO gl_accounts (code, name, account_type, parent_id, is_postable, is_system, description, status) VALUES (?, ?, ?, ?, ?, 0, ?, 1)");
        $isPostable = (int)$form['is_postable'];
        $stmt->bind_param("sssiss", $form['code'], $form['name'], $form['account_type'], $parentId, $isPostable, $form['description']);
        if ($stmt->execute()) {
            $_SESSION['message'] = "Account created: " . htmlspecialchars($form['code']) . " &mdash; " . htmlspecialchars($form['name']);
            $_SESSION['message_type'] = 'success';
            header("Location: gl_accounts.php");
            exit();
        } else {
            $errors[] = 'Insert failed: ' . $stmt->error;
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Add New Account</title>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    body { background: #f5f7fa; font-family: 'Inter', sans-serif; color: #1e293b; padding-bottom: 40px; }

    .form-container { max-width: 680px; margin: 24px auto; background: #fff; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.06); overflow: hidden; }

    .form-header { padding: 18px 22px; background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: #fff; }
    .form-header h1 { margin: 0; font-size: 16px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .form-header .sub { font-size: 11.5px; opacity: 0.88; margin-top: 3px; }
    .parent-banner { margin-top: 10px; padding: 7px 12px; background: rgba(255,255,255,0.15); border-radius: 6px; font-size: 11.5px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .parent-banner .label { opacity: 0.85; }
    .parent-banner strong { font-weight: 700; }

    .form-body { padding: 20px 22px 22px; }

    .form-section { font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 1.2px; margin: 16px 0 8px; padding-bottom: 5px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; gap: 6px; }
    .form-section:first-child { margin-top: 0; }
    .form-section i { color: #2563eb; font-size: 10px; }

    .field-label { font-size: 10.5px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 4px; display: block; }
    .required { color: #dc2626; }

    .form-input, .form-select { width: 100%; height: 34px; padding: 5px 10px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 12.5px; color: #0f172a; background: #fff; font-family: inherit; transition: border-color 0.15s, box-shadow 0.15s; }
    .form-input:focus, .form-select:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.12); }
    textarea.form-input { height: auto; min-height: 56px; resize: vertical; font-family: inherit; padding: 7px 10px; }

    .help-text { font-size: 10.5px; color: #64748b; margin-top: 3px; line-height: 1.4; }
    .help-text strong { color: #2563eb; font-weight: 700; }

    .error-box { padding: 10px 14px; background: #fee2e2; border-left: 3px solid #dc2626; border-radius: 6px; margin-bottom: 14px; color: #991b1b; font-size: 12px; }
    .error-box strong { display: block; margin-bottom: 4px; font-size: 12px; }
    .error-box ul { margin: 0; padding-left: 18px; }

    .auto-hint { display: inline-block; padding: 1px 7px; background: #dbeafe; color: #1e40af; border-radius: 8px; font-size: 9px; font-weight: 700; margin-left: 6px; opacity: 0; transition: opacity 0.3s; }
    .auto-hint.show { opacity: 1; }

    .form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; padding-top: 14px; border-top: 1px solid #e2e8f0; }
    .btn { height: 34px; padding: 0 16px; border-radius: 7px; font-size: 12.5px; font-weight: 600; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; font-family: inherit; }
    .btn-primary { background: #2563eb; color: #fff; }
    .btn-primary:hover { background: #1d4ed8; color: #fff; text-decoration: none; }
    .btn-ghost { background: #fff; border: 1px solid #cbd5e1; color: #475569; }
    .btn-ghost:hover { background: #f8fafc; color: #1e293b; text-decoration: none; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="form-container">

    <div class="form-header">
        <h1><i class="fas fa-plus-circle"></i> Add New Account</h1>
        <div class="sub">Create a new ledger account with code, name, type, and optional parent.</div>
        <?php if ($addingUnder): ?>
            <div class="parent-banner">
                <i class="fas fa-level-down-alt"></i>
                <span class="label">Under:</span>
                <strong><?= htmlspecialchars($addingUnder['code'] . ' — ' . $addingUnder['name']) ?></strong>
                <span style="opacity: 0.75;">(<?= gl_type_label($addingUnder['account_type']) ?>)</span>
            </div>
        <?php endif; ?>
    </div>

    <div class="form-body">

        <?php if (!empty($errors)): ?>
            <div class="error-box">
                <strong><i class="fas fa-exclamation-circle"></i> Please fix:</strong>
                <ul>
                    <?php foreach ($errors as $e): ?>
                        <li><?= htmlspecialchars($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($perms['can_add'])): ?>
        <form method="post" action="" id="accountForm">

            <div class="form-section"><i class="fas fa-tag"></i> Identification</div>

            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 10px;">
                <div>
                    <label class="field-label" for="code">
                        Code <span class="required">*</span>
                        <span class="auto-hint" id="codeAutoHint">Auto-filled</span>
                    </label>
                    <input type="text" class="form-input" id="code" name="code" maxlength="20" required pattern="\d+" value="<?= htmlspecialchars($form['code']) ?>" placeholder="e.g. 1050" autocomplete="off">
                    <div class="help-text">1xxx=Asset, 2xxx=Liability, 3xxx=Equity, 4xxx=Revenue, 5xxx=Expense</div>
                </div>
                <div>
                    <label class="field-label" for="name">Name <span class="required">*</span></label>
                    <input type="text" class="form-input" id="name" name="name" maxlength="150" required value="<?= htmlspecialchars($form['name']) ?>" placeholder="e.g. Cash in Hand">
                </div>
            </div>

            <div class="form-section"><i class="fas fa-list-alt"></i> Classification</div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                <div>
                    <label class="field-label" for="account_type">Type <span class="required">*</span></label>
                    <select class="form-select" id="account_type" name="account_type" required onchange="onTypeChange()">
                        <option value="">— Select Type —</option>
                        <?php foreach (['ASSET','LIABILITY','EQUITY','REVENUE','EXPENSE'] as $t): ?>
                            <option value="<?= $t ?>" <?= $form['account_type'] === $t ? 'selected' : '' ?>><?= gl_type_label($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="field-label" for="is_postable">Postable?</label>
                    <select class="form-select" id="is_postable" name="is_postable">
                        <option value="1" <?= $form['is_postable'] === '1' ? 'selected' : '' ?>>Yes — can receive entries</option>
                        <option value="0" <?= $form['is_postable'] === '0' ? 'selected' : '' ?>>No — grouping only</option>
                    </select>
                </div>
            </div>

            <div style="margin-top: 10px;">
                <label class="field-label" for="parent_id">Parent Account</label>
                <select class="form-select" id="parent_id" name="parent_id" onchange="onParentChange()">
                    <option value="">— No Parent (top-level) —</option>
                </select>
                <div class="help-text">Leave blank for top-level. Picking a parent auto-suggests the next code.</div>
            </div>

            <div class="form-section"><i class="fas fa-sticky-note"></i> Notes</div>

            <div>
                <label class="field-label" for="description">Description</label>
                <textarea class="form-input" id="description" name="description" rows="2" placeholder="Optional notes..."><?= htmlspecialchars($form['description']) ?></textarea>
            </div>

            <div class="form-actions">
                <a href="gl_accounts.php" class="btn btn-ghost"><i class="fas fa-times"></i> Cancel</a>
                <button type="submit" name="submit_account" class="btn btn-primary"><i class="fas fa-save"></i> Save Account</button>
            </div>

        </form>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
var parentOptions = <?= json_encode($parentOptions, JSON_UNESCAPED_UNICODE) ?>;
var currentParentId = '<?= htmlspecialchars($form['parent_id']) ?>';

function renderParentOptions(type) {
    var sel = document.getElementById('parent_id');
    sel.innerHTML = '';
    var noOpt = document.createElement('option');
    noOpt.value = '';
    noOpt.textContent = '— No Parent (top-level) —';
    sel.appendChild(noOpt);

    if (type && parentOptions[type]) {
        parentOptions[type].forEach(function(p) {
            var opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = p.code + ' — ' + p.name;
            opt.setAttribute('data-suggest', p.suggest || '');
            if (String(p.id) === String(currentParentId)) opt.selected = true;
            sel.appendChild(opt);
        });
    }
}

function onTypeChange() {
    var type = document.getElementById('account_type').value;
    currentParentId = '';
    renderParentOptions(type);
}

function onParentChange() {
    var sel = document.getElementById('parent_id');
    var opt = sel.options[sel.selectedIndex];
    var suggested = opt ? opt.getAttribute('data-suggest') : '';
    if (suggested) {
        document.getElementById('code').value = suggested;
        var hint = document.getElementById('codeAutoHint');
        hint.classList.add('show');
        clearTimeout(window.__hintTimer);
        window.__hintTimer = setTimeout(function() { hint.classList.remove('hint'); hint.classList.remove('show'); }, 2500);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var type = document.getElementById('account_type').value;
    renderParentOptions(type);
});
</script>

</body>
</html>
<?php $conn->close(); ?>