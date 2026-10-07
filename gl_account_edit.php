<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");

$_SESSION['lang'] = 'en';
$lang = $_SESSION['lang'];

$translations['en'] = [
    'title'        => 'Edit Account',
    'form_title'   => 'Edit Account',
    'code'         => 'Account Code',
    'code_locked'  => 'Account code cannot be changed after creation.',
    'name'         => 'Account Name',
    'type'         => 'Account Type',
    'type_locked'  => 'Account type cannot be changed after creation.',
    'parent'       => 'Parent Account',
    'parent_help'  => 'Leave blank for a top-level account.',
    'parent_leaf'  => 'Parent must be a grouping account.',
    'description'  => 'Description',
    'is_postable'  => 'Postable?',
    'is_postable_help' => 'Yes = can receive journal entries (leaf). No = grouping only (parent).',
    'status'       => 'Status',
    'status_help'  => 'Inactive accounts do not appear in entry forms but historical entries are preserved.',
    'active'       => 'Active',
    'inactive'     => 'Inactive',
    'submit'       => 'Update Account',
    'cancel'       => 'Cancel',
    'yes'          => 'Yes',
    'no'           => 'No',
    'no_parent'    => '-- No Parent (top-level) --',
    'success'      => 'Account updated successfully.',
    'not_found'    => 'Account not found.',
    'required'     => 'Required fields cannot be empty!',
    'bad_parent'   => 'Selected parent account is invalid or does not match the type.',
    'bad_parent_postable' => 'Selected parent is a postable account.',
    'cannot_deactivate_children' => 'Cannot deactivate this account while it has active children.',
    'cannot_change_parent_when_children' => 'Cannot change parent while this account has children.',
    'cannot_deactivate_parent_of_children' => 'Deactivate children first.',
];

$errors = [];
$accountId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($accountId <= 0) {
    header("Location: gl_accounts.php");
    exit();
}

$account = gl_get_account($conn, $accountId);
if (!$account) {
    $_SESSION['message'] = "<div style='color: #a94442; background-color: #f2dede; border: 1px solid #ebccd1; padding: 15px; border-radius: 4px;'><strong>✗ "
        . $translations[$lang]['not_found'] . "</strong></div>";
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

// Do any children exist?
$childCheck = $conn->prepare("SELECT COUNT(*) AS cnt FROM gl_accounts WHERE parent_id = ? AND status = 1");
$childCheck->bind_param("i", $accountId);
$childCheck->execute();
$childCount = (int)$childCheck->get_result()->fetch_assoc()['cnt'];
$childCheck->close();

// Parent options for this type
$parentsByType = gl_list_accounts($conn, $account['account_type'], true);
// Remove self and descendants? For simplicity, remove self only. Full cycle detection later.
$parentsByType = array_filter($parentsByType, function($p) use ($accountId) {
    return (int)$p['id'] !== $accountId;
});

// ---------------------------------------------------------------------------
// Handle POST
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_account'])) {
    $form['name']        = trim($_POST['name'] ?? '');
    $form['parent_id']   = trim($_POST['parent_id'] ?? '');
    $form['description'] = trim($_POST['description'] ?? '');
    $form['is_postable'] = isset($_POST['is_postable']) && $_POST['is_postable'] === '0' ? '0' : '1';
    $form['status']      = isset($_POST['status']) && $_POST['status'] === '0' ? '0' : '1';

    if ($form['name'] === '') {
        $errors[] = 'Account name is required.';
    }

    // If has children and trying to change parent → reject
    if ($childCount > 0 && (string)($account['parent_id'] ?? '') !== $form['parent_id']) {
        // Normalize null to '' for compare
        $origParent = $account['parent_id'] !== null ? (string)$account['parent_id'] : '';
        if ($origParent !== $form['parent_id']) {
            $errors[] = $translations[$lang]['cannot_change_parent_when_children'];
        }
    }

    // If has children and trying to deactivate → reject
    if ($childCount > 0 && $form['status'] === '0') {
        $errors[] = $translations[$lang]['cannot_deactivate_children'];
    }

    // If changing is_postable to 0 (grouping) but has lines already? Could check here.
    // We'll allow for now, but note: leaving as-is.

    // Validate parent
    $parentId = null;
    if ($form['parent_id'] !== '' && empty($errors)) {
        if (!ctype_digit($form['parent_id'])) {
            $errors[] = $translations[$lang]['bad_parent'];
        } else {
            $parentId = (int)$form['parent_id'];
            $parent = gl_get_account($conn, $parentId);
            if (!$parent) {
                $errors[] = $translations[$lang]['bad_parent'];
            } elseif ($parent['account_type'] !== $account['account_type']) {
                $errors[] = $translations[$lang]['bad_parent'];
            } elseif ((int)$parent['is_postable'] === 1) {
                $errors[] = $translations[$lang]['bad_parent_postable'];
            }
        }
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("
            UPDATE gl_accounts
               SET name = ?, parent_id = ?, description = ?, is_postable = ?, status = ?
             WHERE id = ?
        ");
        $isPostable = (int)$form['is_postable'];
        $status     = (int)$form['status'];
        $stmt->bind_param(
            "sissii",
            $form['name'],
            $parentId,
            $form['description'],
            $isPostable,
            $status,
            $accountId
        );
        if ($stmt->execute()) {
            $_SESSION['message'] = "<div style='color: #3c763d; background-color: #dff0d8; border: 1px solid #d6e9c6; padding: 15px; border-radius: 4px;'>"
                . "<strong>✓ " . $translations[$lang]['success'] . "</strong>"
                . "<br>Code: " . htmlspecialchars($account['code'])
                . " &mdash; " . htmlspecialchars($form['name'])
                . "</div>";
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
    <?php require_once('meta_inc.php'); ?>
    <style>
        .form-section-title {
            font-weight: bold;
            color: #337ab7;
            border-bottom: 2px solid #337ab7;
            padding-bottom: 5px;
            margin: 20px 0 15px 0;
        }
        .help-block { font-size: 12px; }
        .required-star { color: #d9534f; }
        .locked-field {
            background-color: #eee;
            cursor: not-allowed;
        }
        .locked-note { color: #888; font-size: 12px; }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="container">
    <div class="row">
        <div class="col-md-8 col-md-offset-2">

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger" style="margin-top: 15px;">
                    <strong>✗ Please fix the following:</strong>
                    <ul style="margin-bottom: 0;">
                        <?php foreach ($errors as $e): ?>
                            <li><?php echo htmlspecialchars($e); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="panel panel-warning" style="margin-top: 15px;">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <span class="glyphicon glyphicon-edit"></span>
                        <?php echo $translations[$lang]['form_title']; ?>
                        <small>— <?php echo htmlspecialchars($account['code'] . ' ' . $account['name']); ?></small>
                    </h3>
                </div>
                <div class="panel-body">

                    <form method="post" action="">

                        <div class="form-section-title">Identification (locked)</div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label><?php echo $translations[$lang]['code']; ?></label>
                                    <input type="text" class="form-control locked-field" disabled
                                           value="<?php echo htmlspecialchars($account['code']); ?>">
                                    <span class="help-block locked-note"><?php echo $translations[$lang]['code_locked']; ?></span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label><?php echo $translations[$lang]['type']; ?></label>
                                    <input type="text" class="form-control locked-field" disabled
                                           value="<?php echo gl_type_label($account['account_type']); ?>">
                                    <span class="help-block locked-note"><?php echo $translations[$lang]['type_locked']; ?></span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="name">
                                        <?php echo $translations[$lang]['name']; ?>
                                        <span class="required-star">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="name" name="name"
                                           maxlength="150" required
                                           value="<?php echo htmlspecialchars($form['name']); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="form-section-title">Classification</div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="parent_id"><?php echo $translations[$lang]['parent']; ?></label>
                                    <select class="form-control" id="parent_id" name="parent_id"
                                            <?php echo $childCount > 0 ? 'disabled' : ''; ?>>
                                        <option value=""><?php echo $translations[$lang]['no_parent']; ?></option>
                                        <?php foreach ($parentsByType as $p): ?>
                                            <?php if ((int)$p['is_postable'] === 1) continue; ?>
                                            <option value="<?php echo (int)$p['id']; ?>"
                                                <?php echo ((string)$form['parent_id'] === (string)$p['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($p['code'] . ' — ' . $p['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($childCount > 0): ?>
                                        <input type="hidden" name="parent_id" value="<?php echo (int)$account['parent_id']; ?>">
                                        <span class="help-block locked-note">
                                            Parent locked because this account has <?php echo $childCount; ?> child(ren).
                                        </span>
                                    <?php else: ?>
                                        <span class="help-block"><?php echo $translations[$lang]['parent_help']; ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="is_postable"><?php echo $translations[$lang]['is_postable']; ?></label>
                                    <select class="form-control" id="is_postable" name="is_postable">
                                        <option value="1" <?php echo $form['is_postable'] === '1' ? 'selected' : ''; ?>>
                                            <?php echo $translations[$lang]['yes']; ?>
                                        </option>
                                        <option value="0" <?php echo $form['is_postable'] === '0' ? 'selected' : ''; ?>>
                                            <?php echo $translations[$lang]['no']; ?>
                                        </option>
                                    </select>
                                    <span class="help-block"><?php echo $translations[$lang]['is_postable_help']; ?></span>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="status"><?php echo $translations[$lang]['status']; ?></label>
                                    <select class="form-control" id="status" name="status"
                                            <?php echo $childCount > 0 ? 'disabled' : ''; ?>>
                                        <option value="1" <?php echo $form['status'] === '1' ? 'selected' : ''; ?>>
                                            <?php echo $translations[$lang]['active']; ?>
                                        </option>
                                        <option value="0" <?php echo $form['status'] === '0' ? 'selected' : ''; ?>>
                                            <?php echo $translations[$lang]['inactive']; ?>
                                        </option>
                                    </select>
                                    <?php if ($childCount > 0): ?>
                                        <input type="hidden" name="status" value="<?php echo (int)$account['status']; ?>">
                                    <?php endif; ?>
                                    <span class="help-block"><?php echo $translations[$lang]['status_help']; ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="form-section-title">Notes</div>

                        <div class="row">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label for="description"><?php echo $translations[$lang]['description']; ?></label>
                                    <textarea class="form-control" id="description" name="description" rows="3"><?php
                                        echo htmlspecialchars($form['description']);
                                    ?></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="row" style="margin-top: 20px;">
                            <div class="col-md-12 text-right">
                                <button type="submit" name="submit_account" class="btn btn-warning btn-lg">
                                    <span class="glyphicon glyphicon-floppy-disk"></span>
                                    <?php echo $translations[$lang]['submit']; ?>
                                </button>
                                <a href="gl_accounts.php" class="btn btn-default btn-lg">
                                    <?php echo $translations[$lang]['cancel']; ?>
                                </a>
                            </div>
                        </div>

                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

</body>
<?php if (isset($conn)) $conn->close(); ?>
</html>