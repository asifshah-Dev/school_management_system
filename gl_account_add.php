<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

$errors = [];
$form = [
    'code'        => '',
    'name'        => '',
    'account_type'=> '',
    'parent_id'   => '',
    'description' => '',
    'is_postable' => '1',
];

$addingUnder = null;  // banner context

// Case 1: ?parent_id=X — user clicked "Add" under a specific parent
if (isset($_GET['parent_id']) && ctype_digit((string)$_GET['parent_id'])) {
    $form['parent_id'] = (string)$_GET['parent_id'];
    $parentAcc = gl_get_account($conn, (int)$_GET['parent_id']);
    if ($parentAcc) {
        $form['account_type'] = $parentAcc['account_type'];
        $addingUnder = $parentAcc;

        $suggested = gl_suggest_next_code($conn, $parentAcc['code']);
        if ($suggested !== null) {
            $form['code'] = $suggested;
        }
    }
}
// Case 2: ?type=ASSET — user clicked "Add New Account" from the toolbar
elseif (isset($_GET['type']) && in_array($_GET['type'], ['ASSET','LIABILITY','EQUITY','REVENUE','EXPENSE'], true)) {
    $form['account_type'] = $_GET['type'];
    $suggested = gl_suggest_next_code_top_level($conn, $form['account_type']);
    if ($suggested !== null) {
        $form['code'] = $suggested;
    }
}

// Load parent options per type
$parentsByType = [
    'ASSET'     => gl_list_accounts($conn, 'ASSET', true),
    'LIABILITY' => gl_list_accounts($conn, 'LIABILITY', true),
    'EQUITY'    => gl_list_accounts($conn, 'EQUITY', true),
    'REVENUE'   => gl_list_accounts($conn, 'REVENUE', true),
    'EXPENSE'   => gl_list_accounts($conn, 'EXPENSE', true),
];

// Build parent options with auto-suggested codes
$parentOptions = [];
foreach ($parentsByType as $type => $list) {
    $parentOptions[$type] = [];
    foreach ($list as $p) {
        if ((int)$p['is_postable'] === 1) continue; // only grouping accounts
        $suggest = gl_suggest_next_code($conn, $p['code']);
        $parentOptions[$type][] = [
            'id'      => (int)$p['id'],
            'code'    => $p['code'],
            'name'    => $p['name'],
            'suggest' => $suggest ?: '',
        ];
    }
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_account'])) {
    $form['code']         = trim($_POST['code'] ?? '');
    $form['name']         = trim($_POST['name'] ?? '');
    $form['account_type'] = trim($_POST['account_type'] ?? '');
    $form['parent_id']    = trim($_POST['parent_id'] ?? '');
    $form['description']  = trim($_POST['description'] ?? '');
    $form['is_postable']  = isset($_POST['is_postable']) && $_POST['is_postable'] === '0' ? '0' : '1';

    if ($form['code'] === '') {
        $errors[] = 'Account code is required.';
    } elseif (!ctype_digit($form['code'])) {
        $errors[] = 'Code must be numeric (digits only).';
    }
    if ($form['name'] === '') {
        $errors[] = 'Account name is required.';
    }
    if ($form['account_type'] === '') {
        $errors[] = 'Account type is required.';
    } elseif (!in_array($form['account_type'], ['ASSET','LIABILITY','EQUITY','REVENUE','EXPENSE'], true)) {
        $errors[] = 'Invalid account type.';
    }

    if (empty($errors)) {
        $check = gl_get_account_by_code($conn, $form['code']);
        if ($check !== null) {
            $errors[] = 'This account code already exists. Choose a different one.';
        }
    }

    $parentId = null;
    if ($form['parent_id'] !== '' && empty($errors)) {
        if (!ctype_digit($form['parent_id'])) {
            $errors[] = 'Invalid parent account.';
        } else {
            $parentId = (int)$form['parent_id'];
            $parent = gl_get_account($conn, $parentId);
            if (!$parent) {
                $errors[] = 'Parent account not found.';
            } elseif ($parent['account_type'] !== $form['account_type']) {
                $errors[] = 'Parent type does not match the selected account type.';
            } elseif ((int)$parent['is_postable'] === 1) {
                $errors[] = 'Parent must be a grouping account (Postable = No).';
            }
        }
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("
            INSERT INTO gl_accounts
              (code, name, account_type, parent_id, is_postable, is_system, description, status)
            VALUES (?, ?, ?, ?, ?, 0, ?, 1)
        ");
        $isPostable = (int)$form['is_postable'];
        $stmt->bind_param(
            "sssiss",
            $form['code'],
            $form['name'],
            $form['account_type'],
            $parentId,
            $isPostable,
            $form['description']
        );
        if ($stmt->execute()) {
            $_SESSION['message'] = "<div style='color: #3c763d; background-color: #dff0d8; border: 1px solid #d6e9c6; padding: 15px; border-radius: 4px;'>"
                . "<strong>✓ Account created successfully.</strong>"
                . "<br>Code: " . htmlspecialchars($form['code'])
                . " &mdash; " . htmlspecialchars($form['name'])
                . "</div>";
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
    <style>
        body { background: #eef1f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }

        .form-container {
            max-width: 800px;
            margin: 30px auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .form-header {
            padding: 28px 36px;
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
            color: #fff;
        }
        .form-header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
            letter-spacing: -0.3px;
        }
        .form-header .sub {
            font-size: 13px;
            opacity: 0.8;
            margin-top: 4px;
        }
        .parent-banner {
            margin-top: 16px;
            padding: 12px 16px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 6px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .parent-banner .label {
            opacity: 0.85;
        }
        .parent-banner strong {
            font-weight: 700;
        }

        .form-body { padding: 32px 36px 40px 36px; }

        .form-section {
            font-size: 11px;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin: 24px 0 14px 0;
            padding-bottom: 8px;
            border-bottom: 1px solid #e2e8f0;
        }
        .form-section:first-child { margin-top: 0; }

        .field-label {
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 6px;
            display: block;
        }
        .required { color: #dc2626; }

        .form-input,
        .form-select {
            width: 100%;
            height: 42px;
            padding: 8px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 14px;
            color: #0f172a;
            background: #fff;
            transition: border-color 0.15s;
        }
        .form-input:focus,
        .form-select:focus {
            outline: none;
            border-color: #1e40af;
            box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
        }
        textarea.form-input {
            height: auto;
            min-height: 80px;
            resize: vertical;
            font-family: inherit;
        }

        .help-text {
            font-size: 12px;
            color: #64748b;
            margin-top: 5px;
            line-height: 1.5;
        }
        .help-text strong { color: #1e40af; }

        .error-box {
            padding: 16px 20px;
            background: #fee2e2;
            border-left: 4px solid #dc2626;
            border-radius: 6px;
            margin-bottom: 24px;
            color: #991b1b;
            font-size: 13px;
        }
        .error-box strong { display: block; margin-bottom: 8px; font-size: 14px; }
        .error-box ul { margin: 0; padding-left: 20px; }

        .auto-hint {
            display: inline-block;
            padding: 3px 10px;
            background: #dbeafe;
            color: #1e40af;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 700;
            margin-left: 8px;
            opacity: 0;
            transition: opacity 0.3s;
        }
        .auto-hint.show { opacity: 1; }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid #e2e8f0;
        }
        .btn {
            height: 44px;
            padding: 0 24px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.15s;
        }
        .btn-primary { background: #1e40af; color: #fff; }
        .btn-primary:hover { background: #1e3a8a; color: #fff; text-decoration: none; }
        .btn-ghost { background: #fff; border: 1px solid #cbd5e1; color: #475569; }
        .btn-ghost:hover { background: #f8fafc; color: #1e293b; text-decoration: none; }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="form-container">

    <div class="form-header">
        <h1>Add New Account</h1>
        <div class="sub">Create a new ledger account. Assign it a code, name, type, and optional parent.</div>
        <?php if ($addingUnder): ?>
            <div class="parent-banner">
                <span class="glyphicon glyphicon-plus-sign"></span>
                <span class="label">Adding under:</span>
                <strong><?php echo htmlspecialchars($addingUnder['code'] . ' — ' . $addingUnder['name']); ?></strong>
                <span style="opacity: 0.75;">(<?php echo gl_type_label($addingUnder['account_type']); ?>)</span>
            </div>
        <?php endif; ?>
    </div>

    <div class="form-body">

        <?php if (!empty($errors)): ?>
            <div class="error-box">
                <strong>✗ Please fix the following:</strong>
                <ul>
                    <?php foreach ($errors as $e): ?>
                        <li><?php echo htmlspecialchars($e); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="" id="accountForm">

            <div class="form-section">Identification</div>

            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 16px;">
                <div>
                    <label class="field-label" for="code">
                        Account Code <span class="required">*</span>
                        <span class="auto-hint" id="codeAutoHint">Auto-filled</span>
                    </label>
                    <input type="text" class="form-input" id="code" name="code"
                           maxlength="20" required pattern="\d+"
                           value="<?php echo htmlspecialchars($form['code']); ?>"
                           placeholder="e.g. 1050"
                           autocomplete="off">
                    <div class="help-text">
                        Numeric. Convention: 1xxx=Asset, 2xxx=Liability, 3xxx=Equity, 4xxx=Revenue, 5xxx=Expense.
                    </div>
                </div>
                <div>
                    <label class="field-label" for="name">
                        Account Name <span class="required">*</span>
                    </label>
                    <input type="text" class="form-input" id="name" name="name"
                           maxlength="150" required
                           value="<?php echo htmlspecialchars($form['name']); ?>"
                           placeholder="e.g. Cash in Hand">
                </div>
            </div>

            <div class="form-section">Classification</div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div>
                    <label class="field-label" for="account_type">
                        Account Type <span class="required">*</span>
                    </label>
                    <select class="form-select" id="account_type" name="account_type" required onchange="onTypeChange()">
                        <option value="">— Select Type —</option>
                        <?php foreach (['ASSET','LIABILITY','EQUITY','REVENUE','EXPENSE'] as $t): ?>
                            <option value="<?php echo $t; ?>" <?php echo $form['account_type'] === $t ? 'selected' : ''; ?>>
                                <?php echo gl_type_label($t); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="field-label" for="is_postable">Postable?</label>
                    <select class="form-select" id="is_postable" name="is_postable">
                        <option value="1" <?php echo $form['is_postable'] === '1' ? 'selected' : ''; ?>>Yes — can receive entries</option>
                        <option value="0" <?php echo $form['is_postable'] === '0' ? 'selected' : ''; ?>>No — grouping only</option>
                    </select>
                    <div class="help-text">
                        Yes = leaf account for journal entries. No = parent/grouping account.
                    </div>
                </div>
            </div>

            <div style="margin-top: 16px;">
                <label class="field-label" for="parent_id">Parent Account</label>
                <select class="form-select" id="parent_id" name="parent_id" onchange="onParentChange()">
                    <option value="">— No Parent (top-level) —</option>
                </select>
                <div class="help-text">
                    Leave blank for a top-level account. Choosing a parent will <strong>auto-suggest the next available code</strong>.
                </div>
            </div>

            <div class="form-section">Notes</div>

            <div>
                <label class="field-label" for="description">Description</label>
                <textarea class="form-input" id="description" name="description" rows="3" placeholder="Optional notes about this account..."><?php echo htmlspecialchars($form['description']); ?></textarea>
            </div>

            <div class="form-actions">
                <a href="gl_accounts.php" class="btn btn-ghost">
                    <span class="glyphicon glyphicon-remove"></span> Cancel
                </a>
                <button type="submit" name="submit_account" class="btn btn-primary">
                    <span class="glyphicon glyphicon-floppy-disk"></span> Save Account
                </button>
            </div>

        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
<script>
var parentOptions = <?php echo json_encode($parentOptions, JSON_UNESCAPED_UNICODE); ?>;
var currentParentId = '<?php echo htmlspecialchars($form['parent_id']); ?>';

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
        var codeInput = document.getElementById('code');
        codeInput.value = suggested;

        var hint = document.getElementById('codeAutoHint');
        hint.classList.add('show');
        clearTimeout(window.__hintTimer);
        window.__hintTimer = setTimeout(function() {
            hint.classList.remove('show');
        }, 2500);
    }
}

// On page load, if type is pre-selected, render its parent options
document.addEventListener('DOMContentLoaded', function() {
    var type = document.getElementById('account_type').value;
    renderParentOptions(type);
});
</script>

</body>
</html>
<?php $conn->close(); ?>