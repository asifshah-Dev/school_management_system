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
    $catId = (int)($_POST['expense_category_id'] ?? 0);
    $accountId = (int)($_POST['account_id'] ?? 0);

    if ($catId <= 0 || $accountId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
        exit();
    }

    $acc = gl_get_account($conn, $accountId);
    if (!$acc || $acc['account_type'] !== 'EXPENSE' || (int)$acc['is_postable'] !== 1) {
        echo json_encode(['success' => false, 'message' => 'Selected account must be a postable Expense account.']);
        exit();
    }

    $ok = gl_save_mapping($conn, 'expense_category_gl_map', 'expense_category_id', $catId, 'expense_account_id', $accountId);
    echo json_encode(['success' => $ok, 'message' => $ok ? 'Mapping saved.' : 'Failed to save.']);
    exit();
}

$showInactive = isset($_GET['inactive']) && $_GET['inactive'] === '1';

// --- Fetch expense categories ---
$where = $showInactive ? '' : 'WHERE status = 1';
$categories = $conn->query("
    SELECT id, title, status
    FROM expense_categories
    $where
    ORDER BY title
")->fetch_all(MYSQLI_ASSOC);

// --- Fetch current mappings ---
$mappings = [];
$rows = $conn->query("
    SELECT m.expense_category_id, m.expense_account_id, a.code, a.name
    FROM expense_category_gl_map m
    LEFT JOIN gl_accounts a ON a.id = m.expense_account_id
")->fetch_all(MYSQLI_ASSOC);
foreach ($rows as $r) {
    $mappings[(int)$r['expense_category_id']] = $r;
}

// --- Fetch expense accounts ---
$expenseAccounts = $conn->query("
    SELECT id, code, name
    FROM gl_accounts
    WHERE account_type = 'EXPENSE' AND is_postable = 1 AND status = 1
    ORDER BY code
")->fetch_all(MYSQLI_ASSOC);

// --- Stats ---
$totalActive = 0; $mappedActive = 0;
foreach ($categories as $c) {
    if ((int)$c['status'] === 1) {
        $totalActive++;
        if (isset($mappings[(int)$c['id']])) $mappedActive++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once('meta_inc.php'); ?>
    <title>Expense Category → GL Mapping</title>
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
            padding: 16px 20px; background: #fff7ed;
            border-left: 4px solid #ea580c;
            border-radius: 6px; margin-bottom: 24px;
            font-size: 13px; color: #7c2d12; line-height: 1.6;
        }
        .intro strong { color: #c2410c; }

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

        .cat-title { font-weight: 600; color: #0f172a; }
        .cat-inactive {
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
        <h1>Expense Category → GL Account Mapping</h1>
        <div class="sub">Controls which expense account each category posts to.</div>
    </div>

    <div class="map-controls">
        <div>
            <a href="expense_heads.php" class="link-btn">
                <span class="glyphicon glyphicon-arrow-left"></span> Manage Expense Heads
            </a>
        </div>
        <div class="toggle">
            <input type="checkbox" id="showInactive" <?php echo $showInactive ? 'checked' : ''; ?>
                   onchange="window.location.href='gl_expense_category_map.php' + (this.checked ? '?inactive=1' : '');">
            <label for="showInactive">Show inactive categories</label>
        </div>
    </div>

    <div class="map-body">

        <div class="intro">
            <strong>How this works:</strong> Every expense category must be mapped to an Expense GL account.
            When an expense is recorded, the amount is posted to the mapped account.
            If a category is not mapped, you <strong>cannot save expenses</strong> with that category.
        </div>

        <?php if (empty($categories)): ?>
            <div class="empty-state">No expense categories found. <a href="expense_heads.php">Add one first.</a></div>
        <?php else: ?>

            <div class="progress-summary">
                <div class="stat">
                    <strong><?php echo $mappedActive; ?></strong> of <strong><?php echo $totalActive; ?></strong> active categories mapped
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
                        <th style="width: 45%;">Expense Category</th>
                        <th style="width: 40%;">Expense Account</th>
                        <th style="width: 15%;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $c):
                        $cId = (int)$c['id'];
                        $isActive = (int)$c['status'] === 1;
                        $hasMapping = isset($mappings[$cId]);
                        $currentMapping = $hasMapping ? $mappings[$cId] : null;
                    ?>
                        <tr data-category-id="<?php echo $cId; ?>">
                            <td>
                                <div class="cat-title"><?php echo htmlspecialchars($c['title']); ?></div>
                                <?php if (!$isActive): ?>
                                    <span class="cat-inactive">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <select class="map-select <?php echo $hasMapping ? '' : 'unmapped'; ?>"
                                        data-category-id="<?php echo $cId; ?>"
                                        <?php echo $isActive ? '' : 'disabled'; ?>>
                                    <option value="">— Select Expense Account —</option>
                                    <?php foreach ($expenseAccounts as $acc): ?>
                                        <option value="<?php echo (int)$acc['id']; ?>"
                                            <?php echo $hasMapping && (int)$currentMapping['expense_account_id'] === (int)$acc['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($acc['code'] . ' — ' . $acc['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="save-indicator" data-for="<?php echo $cId; ?>"></div>
                            </td>
                            <td>
                                <?php if (!$isActive): ?>
                                    <span class="status-badge inactive">Inactive</span>
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
        <strong>Categories with no expenses posted:</strong> You can change the mapping freely. Categories with historical expenses keep their old postings — only future expenses will use the new account.
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
<script>
$(document).ready(function() {
    $(document).on('change', '.map-select:not([disabled])', function() {
        var $select = $(this);
        var catId = $select.data('category-id');
        var accountId = $select.val();
        var $row = $select.closest('tr');
        var $indicator = $('[data-for="' + catId + '"]');
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
            data: { action: 'save_mapping', expense_category_id: catId, account_id: accountId },
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