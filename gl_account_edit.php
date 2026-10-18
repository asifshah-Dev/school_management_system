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
$accountId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($accountId <= 0) {
    header("Location: gl_accounts.php");
    exit();
}

$account = gl_get_account($conn, $accountId);
if (!$account) {
    $_SESSION['message'] = "Account not found.";
    $_SESSION['message_type'] = 'danger';
    header("Location: gl_accounts.php");
    exit();
}

$form = [
    'name'        => $account['name'],
    'parent_id'   => $account['parent_id'] !== null ? (string)$account['parent_id'] : '',
    'description' => $account['description'] ?? '',
    'is_postable' => (string)$account['is_postable'],
    'status'      => (string)$account['status'],
];

$childCheck = $conn->prepare("SELECT COUNT(*) AS cnt FROM gl_accounts WHERE parent_id = ? AND status = 1");
$childCheck->bind_param("i", $accountId);
$childCheck->execute();
$childCount = (int)$childCheck->get_result()->fetch_assoc()['cnt'];
$childCheck->close();

$parentsByType = gl_list_accounts($conn, $account['account_type'], true);
$parentsByType = array_filter($parentsByType, function($p) use ($accountId) {
    return (int)$p['id'] !== $accountId;
});

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_account'])) {
    $form['name']        = trim($_POST['name'] ?? '');
    $form['parent_id']   = trim($_POST['parent_id'] ?? '');
    $form['description'] = trim($_POST['description'] ?? '');
    $form['is_postable'] = isset($_POST['is_postable']) && $_POST['is_postable'] === '0' ? '0' : '1';
    $form['status']      = isset($_POST['status']) && $_POST['status'] === '0' ? '0' : '1';

    if ($form['name'] === '') {
        $errors[] = 'Account name is required.';
    }

    if ($childCount > 0) {
        $origParent = $account['parent_id'] !== null ? (string)$account['parent_id'] : '';
        if ($origParent !== $form['parent_id']) {
            $errors[] = 'Cannot change parent while this account has children.';
        }
        if ($form['status'] === '0') {
            $errors[] = 'Cannot deactivate this account while it has active children.';
        }
    }

    $parentId = null;
    if ($form['parent_id'] !== '' && empty($errors)) {
        if (!ctype_digit($form['parent_id'])) {
            $errors[] = 'Selected parent account is invalid.';
        } else {
            $parentId = (int)$form['parent_id'];
            $parent = gl_get_account($conn, $parentId);
            if (!$parent) {
                $errors[] = 'Selected parent account does not exist.';
            } elseif ($parent['account_type'] !== $account['account_type']) {
                $errors[] = 'Selected parent does not match this account type.';
            } elseif ((int)$parent['is_postable'] === 1) {
                $errors[] = 'Selected parent is a postable account (must be a grouping account).';
            }
        }
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("UPDATE gl_accounts SET name = ?, parent_id = ?, description = ?, is_postable = ?, status = ? WHERE id = ?");
        $isPostable = (int)$form['is_postable'];
        $status     = (int)$form['status'];
        $stmt->bind_param("sissii", $form['name'], $parentId, $form['description'], $isPostable, $status, $accountId);
        if ($stmt->execute()) {
            $_SESSION['message'] = "Account updated: " . htmlspecialchars($account['code']) . " &mdash; " . htmlspecialchars($form['name']);
            $_SESSION['message_type'] = 'success';
            header("Location: gl_accounts.php");
            exit();
        } else {
            $errors[] = 'Update failed: ' . $stmt->error;
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Edit Account</title>
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
    .context-banner { margin-top: 10px; padding: 7px 12px; background: rgba(255,255,255,0.15); border-radius: 6px; font-size: 11.5px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .context-banner .label { opacity: 0.85; }
    .context-banner strong { font-weight: 700; }
    .context-banner .type-tag { background: rgba(255,255,255,0.25); padding: 1px 8px; border-radius: 10px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }

    .form-body { padding: 20px 22px 22px; }

    .form-section { font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 1.2px; margin: 16px 0 8px; padding-bottom: 5px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; gap: 6px; }
    .form-section:first-child { margin-top: 0; }
    .form-section i { color: #2563eb; font-size: 10px; }
    .form-section .lock-badge { margin-left: auto; font-size: 9px; font-weight: 700; padding: 1px 7px; background: #f1f5f9; color: #64748b; border-radius: 8px; letter-spacing: 0.3px; text-transform: uppercase; }
    .form-section .lock-badge i { font-size: 8px; margin-right: 3px; color: #64748b; }

    .field-label { font-size: 10.5px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 4px; display: block; }
    .required { color: #dc2626; }

    .form-input, .form-select { width: 100%; height: 34px; padding: 5px 10px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 12.5px; color: #0f172a; background: #fff; font-family: inherit; transition: border-color 0.15s, box-shadow 0.15s; }
    .form-input:focus, .form-select:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.12); }
    textarea.form-input { height: auto; min-height: 56px; resize: vertical; font-family: inherit; padding: 7px 10px; }

    .form-input:disabled, .form-select:disabled { background: #f1f5f9; color: #94a3b8; cursor: not-allowed; font-weight: 600; }

    .help-text { font-size: 10.5px; color: #64748b; margin-top: 3px; line-height: 1.4; }
    .help-text.locked { color: #64748b; font-weight: 600; }
    .help-text strong { color: #2563eb; font-weight: 700; }

    .error-box { padding: 10px 14px; background: #fee2e2; border-left: 3px solid #dc2626; border-radius: 6px; margin-bottom: 14px; color: #991b1b; font-size: 12px; }
    .error-box strong { display: block; margin-bottom: 4px; font-size: 12px; }
    .error-box ul { margin: 0; padding-left: 18px; }

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
        <h1><i class="fas fa-pen"></i> Edit Account</h1>
        <div class="sub">Modify this ledger account. Code and type are locked after creation.</div>
        <div class="context-banner">
            <i class="fas fa-tag"></i>
            <span class="label">Editing:</span>
            <strong><?= htmlspecialchars($account['code'] . ' — ' . $account['name']) ?></strong>
            <span class="type-tag"><?= gl_type_label($account['account_type']) ?></span>
        </div>
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

        <?php if (!empty($perms['can_edit'])): ?>
        <form method="post" action="" id="accountForm">

            <div class="form-section">
                <i class="fas fa-lock"></i> Identification
                <span class="lock-badge"><i class="fas fa-lock"></i> Locked</span>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1.4fr; gap: 10px;">
                <div>
                    <label class="field-label">Code</label>
                    <input type="text" class="form-input" disabled value="<?= htmlspecialchars($account['code']) ?>">
                </div>
                <div>
                    <label class="field-label">Type</label>
                    <input type="text" class="form-input" disabled value="<?= gl_type_label($account['account_type']) ?>">
                </div>
                <div>
                    <label class="field-label" for="name">Name <span class="required">*</span></label>
                    <input type="text" class="form-input" id="name" name="name" maxlength="150" required value="<?= htmlspecialchars($form['name']) ?>">
                </div>
            </div>

            <div class="form-section"><i class="fas fa-list-alt"></i> Classification</div>

            <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 10px;">
                <div>
                    <label class="field-label" for="parent_id">Parent Account</label>
                    <select class="form-select" id="parent_id" name="parent_id" <?= $childCount > 0 ? 'disabled' : '' ?>>
                        <option value="">— No Parent (top-level) —</option>
                        <?php foreach ($parentsByType as $p): ?>
                            <?php if ((int)$p['is_postable'] === 1) continue; ?>
                            <option value="<?= (int)$p['id'] ?>" <?= ((string)$form['parent_id'] === (string)$p['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['code'] . ' — ' . $p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($childCount > 0): ?>
                        <input type="hidden" name="parent_id" value="<?= (int)$account['parent_id'] ?>">
                        <div class="help-text locked"><i class="fas fa-lock"></i> Locked — this account has <?= $childCount ?> child(ren).</div>
                    <?php else: ?>
                        <div class="help-text">Leave blank for top-level.</div>
                    <?php endif; ?>
                </div>
                <div>
                    <label class="field-label" for="is_postable">Postable?</label>
                    <select class="form-select" id="is_postable" name="is_postable">
                        <option value="1" <?= $form['is_postable'] === '1' ? 'selected' : '' ?>>Yes — leaf</option>
                        <option value="0" <?= $form['is_postable'] === '0' ? 'selected' : '' ?>>No — grouping</option>
                    </select>
                    <div class="help-text">Yes = can receive entries.</div>
                </div>
                <div>
                    <label class="field-label" for="status">Status</label>
                    <select class="form-select" id="status" name="status" <?= $childCount > 0 ? 'disabled' : '' ?>>
                        <option value="1" <?= $form['status'] === '1' ? 'selected' : '' ?>>Active</option>
                        <option value="0" <?= $form['status'] === '0' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                    <?php if ($childCount > 0): ?>
                        <input type="hidden" name="status" value="<?= (int)$account['status'] ?>">
                        <div class="help-text locked"><i class="fas fa-lock"></i> Locked — has children.</div>
                    <?php else: ?>
                        <div class="help-text">Inactive = hidden from entry forms.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-section"><i class="fas fa-sticky-note"></i> Notes</div>

            <div>
                <label class="field-label" for="description">Description</label>
                <textarea class="form-input" id="description" name="description" rows="2" placeholder="Optional notes..."><?= htmlspecialchars($form['description']) ?></textarea>
            </div>

            <div class="form-actions">
                <a href="gl_accounts.php" class="btn btn-ghost"><i class="fas fa-times"></i> Cancel</a>
                <button type="submit" name="submit_account" class="btn btn-primary"><i class="fas fa-save"></i> Update Account</button>
            </div>

        </form>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
<?php $conn->close(); ?>