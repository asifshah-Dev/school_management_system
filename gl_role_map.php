<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

// --- AJAX: save mapping ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_mapping') {
    header('Content-Type: application/json');
    $roleId = (int)($_POST['role_id'] ?? 0);
    $accountId = (int)($_POST['account_id'] ?? 0);

    if ($roleId <= 0 || $accountId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
        exit();
    }

    $acc = gl_get_account($conn, $accountId);
    if (!$acc || $acc['account_type'] !== 'EXPENSE' || (int)$acc['is_postable'] !== 1) {
        echo json_encode(['success' => false, 'message' => 'Selected account must be a postable Expense account.']);
        exit();
    }

    $ok = gl_save_mapping($conn, 'role_gl_map', 'role_id', $roleId, 'salary_account_id', $accountId);
    echo json_encode(['success' => $ok, 'message' => $ok ? 'Mapping saved.' : 'Failed to save.']);
    exit();
}

$showInactive = isset($_GET['inactive']) && $_GET['inactive'] === '1';

// --- Fetch roles ---
$where = $showInactive ? '' : 'WHERE status = 0';
$roles = $conn->query("
    SELECT id, title, status
    FROM roles
    $where
    ORDER BY id
")->fetch_all(MYSQLI_ASSOC);

// --- Fetch current mappings ---
$mappings = [];
$rows = $conn->query("
    SELECT m.role_id, m.salary_account_id, a.code, a.name
    FROM role_gl_map m
    LEFT JOIN gl_accounts a ON a.id = m.salary_account_id
")->fetch_all(MYSQLI_ASSOC);
foreach ($rows as $r) {
    $mappings[(int)$r['role_id']] = $r;
}

// --- Fetch expense accounts (salaries are expenses) ---
$expenseAccounts = $conn->query("
    SELECT id, code, name
    FROM gl_accounts
    WHERE account_type = 'EXPENSE' AND is_postable = 1 AND status = 1
    ORDER BY code
")->fetch_all(MYSQLI_ASSOC);

// --- Stats ---
$totalActive = 0; $mappedActive = 0;
foreach ($roles as $r) {
    if ((int)$r['status'] === 0) {
        $totalActive++;
        if (isset($mappings[(int)$r['id']])) $mappedActive++;
    }
}

// --- Get user count per role (informational) ---
$usersByRole = [];
$res = $conn->query("SELECT role_id, COUNT(*) AS cnt FROM users GROUP BY role_id");
while ($row = $res->fetch_assoc()) {
    $usersByRole[(int)$row['role_id']] = (int)$row['cnt'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once('meta_inc.php'); ?>
    <title>Role → Salary GL Mapping</title>
    <style>
        body { background: #eef1f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }

        .map-container {
            max-width: 1000px; margin: 30px auto;
            background: #fff; border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .map-header {
            padding: 30px 40px;
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
            color: #fff;
        }
        .map-header .company {
            font-size: 12px; letter-spacing: 2px; text-transform: uppercase;
            opacity: 0.75; margin-bottom: 8px; font-weight: 600;
        }
        .map-header h1 { margin: 0; font-size: 26px; font-weight: 700; letter-spacing: -0.4px; }
        .map-header .sub { font-size: 14px; opacity: 0.85; margin-top: 6px; }

        .map-controls {
            padding: 18px 40px; background: #f8fafc;
            border-bottom: 1px solid #e5e9ef;
            display: flex; justify-content: space-between;
            align-items: center; flex-wrap: wrap; gap: 12px;
        }
        .map-controls .link-btn {
            height: 36px; padding: 0 16px; border-radius: 6px;
            font-size: 13px; font-weight: 600; text-decoration: none;
            display: inline-flex; align-items: center; gap: 6px;
            background: #fff; border: 1px solid #cbd5e1; color: #475569;
            transition: all 0.15s;
        }
        .map-controls .link-btn:hover { background: #f8fafc; color: #1e293b; text-decoration: none; }
        .map-controls .toggle {
            display: inline-flex; align-items: center; gap: 8px;
            font-size: 13px; color: #475569;
        }
        .map-controls .toggle input { margin: 0; }
        .map-controls .toggle label { cursor: pointer; margin: 0; font-weight: 500; }

        .map-body { padding: 20px 40px 40px 40px; }

        .intro {
            padding: 16px 20px; background: #f0f5ff;
            border-left: 4px solid #1e40af;
            border-radius: 6px; margin-bottom: 24px;
            font-size: 13px; color: #334155; line-height: 1.6;
        }
        .intro strong { color: #1e40af; }

        .map-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .map-table thead th {
            padding: 14px 12px; text-align: left;
            font-size: 11px; font-weight: 700; color: #64748b;
            text-transform: uppercase; letter-spacing: 1px;
            border-bottom: 2px solid #334155;
            background: #f1f5f9;
        }
        .map-table tbody tr { border-bottom: 1px solid #f1f5f9; transition: background 0.3s; }
        .map-table tbody tr:hover { background: #f8fafc; }
        .map-table tbody tr.just-saved { background: #d1fae5 !important; }
        .map-table tbody tr.error-flash { background: #fee2e2 !important; }
        .map-table tbody td { padding: 14px 12px; vertical-align: middle; color: #1e293b; }

        .role-title { font-weight: 600; color: #0f172a; }
        .user-count {
            display: inline-block; padding: 2px 8px; border-radius: 10px;
            font-size: 11px; font-weight: 700;
            background: #e0e7ff; color: #3730a3;
            margin-left: 8px;
        }
        .role-inactive {
            display: inline-block; padding: 2px 8px; border-radius: 4px;
            font-size: 10px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.5px;
            background: #f1f5f9; color: #94a3b8;
            margin-left: 8px;
        }

        .map-select {
            width: 100%; max-width: 360px; height: 38px;
            padding: 6px 12px; border: 1px solid #cbd5e1;
            border-radius: 6px; font-size: 13px; background: #fff;
            cursor: pointer; transition: border-color 0.15s;
        }
        .map-select:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1); }
        .map-select.unmapped { border-color: #fca5a5; background: #fef2f2; }

        .status-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 4px 10px; border-radius: 20px;
            font-size: 11px; font-weight: 700;
            letter-spacing: 0.4px; text-transform: uppercase;
        }
        .status-badge.mapped    { background: #d1fae5; color: #065f46; }
        .status-badge.unmapped  { background: #fee2e2; color: #991b1b; }
        .status-badge.inactive  { background: #f1f5f9; color: #64748b; }
        .status-badge.student   { background: #fef3c7; color: #92400e; }

        .save-indicator {
            display: inline-block; margin-left: 10px;
            font-size: 11px; color: #64748b;
        }
        .save-indicator.saving { color: #1e40af; }
        .save-indicator.saved  { color: #047857; }
        .save-indicator.error  { color: #b91c1c; }

        .progress-summary {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 20px; background: #f8fafc;
            border-radius: 8px; margin-bottom: 20px;
            border: 1px solid #e2e8f0;
        }
        .progress-summary .stat { font-size: 13px; color: #475569; }
        .progress-summary .stat strong { color: #1e293b; font-size: 15px; }
        .progress-summary .progress-bar {
            flex: 1; max-width: 240px; height: 8px;
            background: #e2e8f0; border-radius: 4px;
            overflow: hidden; margin: 0 20px;
        }
        .progress-summary .progress-bar .fill {
            height: 100%; background: #059669;
            transition: width 0.3s;
        }
        .progress-summary .all-mapped { font-weight: 700; color: #065f46; }
        .progress-summary .not-all-mapped { font-weight: 700; color: #991b1b; }

        .empty-state { padding: 60px 40px; text-align: center; color: #94a3b8; font-size: 14px; }

        .footer-note {
            padding: 20px 40px; background: #f8fafc;
            border-top: 1px solid #e5e9ef;
            font-size: 12px; color: #64748b; line-height: 1.6;
        }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="map-container">

    <div class="map-header">
        <div class="company">Dar-e-Arqm School</div>
        <h1>Role → Salary GL Account Mapping</h1>
        <div class="sub">Controls which expense account salaries post to, based on the user's role.</div>
    </div>

    <div class="map-controls">
        <div>
            <a href="roles.php" class="link-btn">
                <span class="glyphicon glyphicon-arrow-left"></span> Manage Roles
            </a>
        </div>
        <div class="toggle">
            <input type="checkbox" id="showInactive" <?php echo $showInactive ? 'checked' : ''; ?>
                   onchange="window.location.href='gl_role_map.php' + (this.checked ? '?inactive=1' : '');">
            <label for="showInactive">Show inactive roles</label>
        </div>
    </div>

    <div class="map-body">

        <div class="intro">
            <strong>How this works:</strong> When a salary is saved for a user, the salary amount is posted as an expense.
            The expense account is determined by the user's role. Teaching roles typically go to "Teacher Salaries", admin roles to "Admin Salaries".
            <br><br>
            <strong>Unmapped roles:</strong> If a role is not mapped, salary entries for users with that role will be <strong>refused</strong> — this prevents accidental misclassification. You'll see a clear error at save time.
            <br><br>
            <strong>Students:</strong> Student roles should NOT be mapped (they don't receive salaries). Leaving them unmapped is intentional.
        </div>

        <?php if (empty($roles)): ?>
            <div class="empty-state">No roles found. <a href="roles.php">Add one first.</a></div>
        <?php else: ?>

            <div class="progress-summary">
                <div class="stat">
                    <strong><?php echo $mappedActive; ?></strong> of <strong><?php echo $totalActive; ?></strong> active roles mapped
                </div>
                <div class="progress-bar">
                    <div class="fill" style="width: <?php echo $totalActive > 0 ? round(($mappedActive / $totalActive) * 100) : 0; ?>%;"></div>
                </div>
                <div>
                    <?php if ($mappedActive === $totalActive && $totalActive > 0): ?>
                        <span class="all-mapped">✓ All mapped</span>
                    <?php else: ?>
                        <span class="not-all-mapped">⚠ <?php echo $totalActive - $mappedActive; ?> unmapped</span>
                    <?php endif; ?>
                </div>
            </div>

            <table class="map-table">
                <thead>
                    <tr>
                        <th style="width: 45%;">Role</th>
                        <th style="width: 40%;">Salary Account</th>
                        <th style="width: 15%;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roles as $r):
                        $roleId = (int)$r['id'];
                        $isActive = (int)$r['status'] === 0;
                        $hasMapping = isset($mappings[$roleId]);
                        $currentMapping = $hasMapping ? $mappings[$roleId] : null;
                        $userCount = $usersByRole[$roleId] ?? 0;
                        $isStudentRole = (stripos($r['title'], 'student') !== false);
                    ?>
                        <tr data-role-id="<?php echo $roleId; ?>">
                            <td>
                                <div class="role-title"><?php echo htmlspecialchars($r['title']); ?></div>
                                <div style="margin-top: 4px;">
                                    <span class="user-count"><?php echo $userCount; ?> user<?php echo $userCount === 1 ? '' : 's'; ?></span>
                                    <?php if (!$isActive): ?>
                                        <span class="role-inactive">Inactive</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <select class="map-select <?php echo $hasMapping ? '' : 'unmapped'; ?>"
                                        data-role-id="<?php echo $roleId; ?>"
                                        <?php echo $isActive ? '' : 'disabled'; ?>>
                                    <option value="">— Select Salary Account —</option>
                                    <?php foreach ($expenseAccounts as $acc): ?>
                                        <option value="<?php echo (int)$acc['id']; ?>"
                                            <?php echo $hasMapping && (int)$currentMapping['salary_account_id'] === (int)$acc['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($acc['code'] . ' — ' . $acc['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="save-indicator" data-for="<?php echo $roleId; ?>"></div>
                            </td>
                            <td>
                                <?php if (!$isActive): ?>
                                    <span class="status-badge inactive">Inactive</span>
                                <?php elseif ($isStudentRole && !$hasMapping): ?>
                                    <span class="status-badge student">Student (skip)</span>
                                <?php elseif ($hasMapping): ?>
                                    <span class="status-badge mapped">✓ Mapped</span>
                                <?php else: ?>
                                    <span class="status-badge unmapped">⚠ Not Mapped</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>
    </div>

    <div class="footer-note">
        <strong>Auto-save:</strong> Changing the dropdown saves immediately via AJAX.
        <br>
        <strong>For student roles:</strong> Leave unmapped. Salary processing for student-role users will be refused, which is the desired behavior.
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
<script>
$(document).ready(function() {
    $(document).on('change', '.map-select:not([disabled])', function() {
        var $select = $(this);
        var roleId = $select.data('role-id');
        var accountId = $select.val();
        var $row = $select.closest('tr');
        var $indicator = $('[data-for="' + roleId + '"]');
        var $statusBadge = $row.find('.status-badge');

        if (!accountId) {
            $indicator.attr('class', 'save-indicator error').text('Please select an account.');
            $select.addClass('unmapped');
            return;
        }

        $indicator.attr('class', 'save-indicator saving').text('Saving…');

        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { action: 'save_mapping', role_id: roleId, account_id: accountId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $indicator.attr('class', 'save-indicator saved').text('✓ Saved');
                    $select.removeClass('unmapped');
                    $statusBadge.attr('class', 'status-badge mapped').html('✓ Mapped');
                    $row.addClass('just-saved');
                    setTimeout(function() { $row.removeClass('just-saved'); }, 1500);
                    setTimeout(function() { $indicator.attr('class', 'save-indicator').text(''); }, 3000);
                    updateProgress();
                } else {
                    $indicator.attr('class', 'save-indicator error').text('✗ ' + (response.message || 'Failed'));
                    $row.addClass('error-flash');
                    setTimeout(function() { $row.removeClass('error-flash'); }, 1500);
                }
            },
            error: function() {
                $indicator.attr('class', 'save-indicator error').text('✗ Network error');
            }
        });
    });

    function updateProgress() {
        var total = $('.map-table tbody tr').length;
        var mapped = $('.map-table tbody tr').filter(function() {
            return $(this).find('.map-select').val() !== '';
        }).length;
        if (total > 0) {
            var pct = Math.round((mapped / total) * 100);
            $('.progress-summary .progress-bar .fill').css('width', pct + '%');
        }
    }
});
</script>

</body>
</html>
<?php $conn->close(); ?>