<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('permissions.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

$msg = ''; $msg_type = '';

// --- Save ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId <= 0) {
        $msg = 'Pick a user first.'; $msg_type = 'danger';
    } else {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("DELETE FROM user_modules WHERE user_id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute(); $stmt->close();

            $ins = $conn->prepare("
                INSERT INTO user_modules (user_id, module_id, can_view, can_add, can_edit, can_delete)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $modulesPost = $_POST['modules'] ?? [];
            foreach ($modulesPost as $mid => $flags) {
                $mid = (int)$mid;
                if ($mid <= 0) continue;
                $v = !empty($flags['view'])   ? 1 : 0;
                $a = !empty($flags['add'])    ? 1 : 0;
                $e = !empty($flags['edit'])   ? 1 : 0;
                $d = !empty($flags['delete']) ? 1 : 0;
                $ins->bind_param("iiiiii", $userId, $mid, $v, $a, $e, $d);
                $ins->execute();
            }
            $ins->close();

            $conn->commit();
            $msg = 'Permissions saved.'; $msg_type = 'success';
        } catch (Exception $e) {
            $conn->rollback();
            $msg = 'Save failed: ' . $e->getMessage(); $msg_type = 'danger';
        }
    }
}

// --- Clear overrides ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear') {
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId > 0) {
        $stmt = $conn->prepare("DELETE FROM user_modules WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute(); $stmt->close();
        $msg = 'All overrides cleared. User now inherits from role.'; $msg_type = 'success';
    }
}

$users = $conn->query("
    SELECT u.id, u.username, u.role_id, r.title AS role_title
    FROM users u
    LEFT JOIN roles r ON r.id = u.role_id
    WHERE u.status = 0
    ORDER BY u.id ASC
")->fetch_all(MYSQLI_ASSOC);

$selectedUserId = (int)($_GET['user_id'] ?? 0);

$modules = $conn->query("
    SELECT m.id, m.parent_id, m.title, m.url, m.type, m.status,
           p.title AS parent_title
    FROM modules m
    LEFT JOIN modules p ON p.id = m.parent_id
    WHERE m.status = 1
    ORDER BY COALESCE(p.title, m.title), m.parent_id IS NULL DESC, m.id
")->fetch_all(MYSQLI_ASSOC);

$tree = [];
foreach ($modules as $m) {
    if ($m['type'] === 'Parent') $tree[$m['id']] = ['parent'=>$m, 'children'=>[]];
}
foreach ($modules as $m) {
    if ($m['type'] === 'Child' && $m['parent_id'] && isset($tree[$m['parent_id']])) {
        $tree[$m['parent_id']]['children'][] = $m;
    }
}

$overrides = [];
$roleDefaults = [];
$selectedUser = null;

if ($selectedUserId > 0) {
    foreach ($users as $u) if ((int)$u['id'] === $selectedUserId) $selectedUser = $u;

    if ($selectedUser) {
        $stmt = $conn->prepare("SELECT module_id, can_view, can_add, can_edit, can_delete FROM user_modules WHERE user_id = ?");
        $stmt->bind_param("i", $selectedUserId);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) $overrides[(int)$row['module_id']] = $row;
        $stmt->close();

        $stmt = $conn->prepare("SELECT module_id, can_view, can_add, can_edit, can_delete FROM role_modules WHERE role_id = ?");
        $stmt->bind_param("i", $selectedUser['role_id']);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) $roleDefaults[(int)$row['module_id']] = $row;
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>User Permissions — Dar-e-Arqm</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #eef1f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #1e293b; }
    .wrap { max-width: 1400px; margin: 30px auto; padding: 0 20px; }

    .head {
        background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
        color: #fff; padding: 30px 40px 26px 40px; border-radius: 12px 12px 0 0;
        display: flex; justify-content: space-between; align-items: flex-end;
        gap: 20px; flex-wrap: wrap;
    }
    .head h1 { font-size: 28px; font-weight: 700; }
    .head .sub { font-size: 14px; opacity: 0.85; margin-top: 6px; }

    .card { background: #fff; padding: 28px 40px 40px 40px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

    .section-title {
        font-size: 12px; font-weight: 800; color: #64748b; text-transform: uppercase;
        letter-spacing: 2px; margin: 0 0 16px 0; padding-bottom: 8px;
        border-bottom: 1px solid #e2e8f0;
    }

    .alert { padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; border-left: 4px solid; }
    .alert.success { background: #d1fae5; border-color: #059669; color: #065f46; }
    .alert.danger  { background: #fee2e2; border-color: #dc2626; color: #991b1b; }

    .picker { display: flex; gap: 12px; align-items: flex-end; margin-bottom: 24px; flex-wrap: wrap; }
    .picker .field { flex: 1; min-width: 260px; }
    .picker label {
        display: block; font-size: 11px; font-weight: 700; color: #334155;
        text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px;
    }
    .picker select {
        width: 100%; height: 46px; padding: 0 14px;
        border: 1px solid #cbd5e1; border-radius: 10px;
        font-size: 14px; font-family: inherit; background: #fff;
        -webkit-appearance: none; appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L2 4h8z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 14px center;
        padding-right: 36px;
    }
    .picker select:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 4px rgba(30,64,175,0.1); }
    .picker .info-box {
        padding: 11px 16px; border-radius: 10px; font-size: 12px;
        background: #eff6ff; border: 1px solid #bfdbfe; color: #1e3a8a;
        height: 46px; display: flex; align-items: center; gap: 8px;
    }

    table.perm { width: 100%; border-collapse: collapse; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
    table.perm thead th {
        background: #f8fafc; font-size: 11px; color: #64748b; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px;
        padding: 12px; text-align: left; border-bottom: 2px solid #e2e8f0;
    }
    table.perm thead th.center { text-align: center; width: 90px; }
    table.perm tbody td { padding: 10px 12px; font-size: 13px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    table.perm tbody tr.parent-row td {
        background: #f1f5f9; font-weight: 700; font-size: 12px;
        text-transform: uppercase; letter-spacing: 0.8px; color: #334155;
    }
    table.perm tbody tr:hover:not(.parent-row) td { background: #f8fafc; }
    table.perm td.center { text-align: center; }
    table.perm .mod-url { font-family: 'SF Mono','Monaco',monospace; font-size: 11px; color: #94a3b8; }

    table.perm input[type=checkbox] {
        width: 18px; height: 18px; cursor: pointer;
        accent-color: #1e40af;
    }
    .overridden {
        background: #fef3c7 !important;
    }
    .role-default {
        font-size: 11px; color: #94a3b8; margin-left: 4px;
    }

    .actions {
        display: flex; gap: 10px; margin-top: 24px;
        padding-top: 20px; border-top: 1px solid #e2e8f0;
        align-items: center; flex-wrap: wrap;
    }
    .btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        height: 46px; padding: 0 26px; border-radius: 10px; border: 1px solid;
        font-size: 14px; font-weight: 700; font-family: inherit;
        cursor: pointer; line-height: 1; transition: all 0.15s;
        text-decoration: none;
    }
    .btn-primary { background: #1e40af; color: #fff; border-color: #1e40af; }
    .btn-primary:hover { background: #1e3a8a; border-color: #1e3a8a; box-shadow: 0 4px 12px rgba(30,64,175,0.25); }
    .btn-ghost { background: #fff; color: #334155; border-color: #cbd5e1; }
    .btn-ghost:hover { background: #f1f5f9; border-color: #94a3b8; }
    .btn-danger { background: #fff; color: #991b1b; border-color: #fecaca; }
    .btn-danger:hover { background: #fee2e2; border-color: #fca5a5; }

    .empty { padding: 60px 20px; text-align: center; color: #94a3b8; }
    .empty .big { font-size: 48px; opacity: 0.35; margin-bottom: 10px; }
    .empty .title { font-size: 17px; color: #475569; font-weight: 600; }

    .legend { display: flex; gap: 16px; font-size: 12px; color: #64748b; margin-bottom: 14px; flex-wrap: wrap; }
    .legend span { display: inline-flex; align-items: center; gap: 6px; }
    .legend .swatch { width: 14px; height: 14px; border-radius: 3px; border: 1px solid #cbd5e1; }
    .legend .swatch.override { background: #fef3c7; border-color: #fde68a; }

    .bulk-bar {
        display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap;
        padding: 10px 14px; background: #f8fafc;
        border: 1px solid #e2e8f0; border-radius: 10px;
    }
    .bulk-btn {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 6px 12px; border-radius: 6px;
        font-size: 12px; font-weight: 600;
        border: 1px solid #cbd5e1; background: #fff; color: #334155;
        cursor: pointer; font-family: inherit;
        transition: all 0.15s; line-height: 1;
    }
    .bulk-btn:hover { background: #f1f5f9; border-color: #94a3b8; }
    .bulk-btn.danger { background: #fff; color: #991b1b; border-color: #fecaca; }
    .bulk-btn.danger:hover { background: #fee2e2; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">
    <div class="head">
        <div>
            <h1>User Permissions</h1>
            <div class="sub">Per-user module overrides. No row = inherit (allow by default). Explicit row = your settings win.</div>
        </div>
    </div>

    <div class="card">

        <?php if ($msg): ?>
            <div class="alert <?= htmlspecialchars($msg_type) ?>"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <div class="section-title">1. Pick a user</div>

        <form method="get" class="picker">
            <div class="field">
                <label>User</label>
                <select name="user_id" onchange="this.form.submit()">
                    <option value="">— Select a user —</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === $selectedUserId ? 'selected' : '' ?>>
                            #<?= (int)$u['id'] ?> · <?= htmlspecialchars($u['username']) ?>
                            · <?= htmlspecialchars($u['role_title'] ?: 'no role') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($selectedUser): ?>
                <div class="info-box">
                    <strong>Role:</strong> <?= htmlspecialchars($selectedUser['role_title'] ?: '—') ?>
                    · <?= count($overrides) ?> explicit row<?= count($overrides) === 1 ? '' : 's' ?>
                </div>
            <?php endif; ?>
        </form>

        <?php if (!$selectedUser): ?>
            <div class="empty">
                <div class="big">👤</div>
                <div class="title">Select a user above to edit their permissions</div>
            </div>
        <?php else: ?>

            <div class="section-title" style="margin-top: 24px;">2. Set permissions</div>

            <div class="legend">
                <span><span class="swatch override"></span> Yellow = an explicit row exists for this user+module</span>
                <span>Blank row = no explicit rule → user inherits (allowed by default)</span>
                <span>Uncheck a box to <strong>deny</strong> that action for this user</span>
            </div>

            <form method="post" id="permForm">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="user_id" value="<?= (int)$selectedUser['id'] ?>">

                <div class="bulk-bar">
                    <strong style="font-size:12px;color:#475569;margin-right:8px;">Bulk:</strong>
                    <button type="button" class="bulk-btn" onclick="setAll('view', true)">All View ✓</button>
                    <button type="button" class="bulk-btn" onclick="setAll('view', false)">All View ✗</button>
                    <button type="button" class="bulk-btn" onclick="setAll('add', true)">All Add ✓</button>
                    <button type="button" class="bulk-btn" onclick="setAll('add', false)">All Add ✗</button>
                    <button type="button" class="bulk-btn" onclick="setAll('edit', true)">All Edit ✓</button>
                    <button type="button" class="bulk-btn" onclick="setAll('edit', false)">All Edit ✗</button>
                    <button type="button" class="bulk-btn" onclick="setAll('delete', true)">All Delete ✓</button>
                    <button type="button" class="bulk-btn danger" onclick="setAll('delete', false)">All Delete ✗</button>
                    <button type="button" class="bulk-btn danger" onclick="uncheckAll()">Uncheck All</button>
                    <button type="button" class="bulk-btn" onclick="checkAll()">Check All</button>
                </div>

                <table class="perm">
                    <thead>
                        <tr>
                            <th>Module</th>
                            <th class="center">View</th>
                            <th class="center">Add</th>
                            <th class="center">Edit</th>
                            <th class="center">Delete</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tree as $node):
                            $p = $node['parent'];
                        ?>
                            <tr class="parent-row">
                                <td colspan="5"><?= htmlspecialchars($p['title']) ?></td>
                            </tr>

                            <?php foreach ($node['children'] as $c):
                                $mid = (int)$c['id'];
                                $ov = $overrides[$mid] ?? null;
                                $rd = $roleDefaults[$mid] ?? null;

                                $isOverridden = $ov !== null;
                                $rowClass = $isOverridden ? 'overridden' : '';

                                // Display defaults:
                                //  - explicit override → its value
                                //  - role row exists → role's value
                                //  - neither → default to ALLOW (inherit)
                                if ($isOverridden) {
                                    $curV = (int)$ov['can_view'];
                                    $curA = (int)$ov['can_add'];
                                    $curE = (int)$ov['can_edit'];
                                    $curD = (int)$ov['can_delete'];
                                } elseif ($rd !== null) {
                                    $curV = (int)$rd['can_view'];
                                    $curA = (int)$rd['can_add'];
                                    $curE = (int)$rd['can_edit'];
                                    $curD = (int)$rd['can_delete'];
                                } else {
                                    $curV = 1; $curA = 1; $curE = 1; $curD = 1;
                                }

                                $roleHint = function($k) use ($rd) {
                                    if ($rd === null) return '';
                                    $v = (int)$rd[$k];
                                    return '<span class="role-default">role: ' . ($v ? '✓' : '✗') . '</span>';
                                };
                            ?>
                                <tr class="<?= $rowClass ?>" data-module-id="<?= $mid ?>">
                                    <td>
                                        <strong><?= htmlspecialchars($c['title']) ?></strong>
                                        <div class="mod-url"><?= htmlspecialchars($c['url']) ?></div>
                                        <input type="hidden" name="modules[<?= $mid ?>][present]" value="1">
                                    </td>
                                    <td class="center">
                                        <input type="checkbox" data-action="view" name="modules[<?= $mid ?>][view]" value="1" <?= $curV ? 'checked' : '' ?>>
                                        <?php if (!$isOverridden) echo $roleHint('can_view'); ?>
                                    </td>
                                    <td class="center">
                                        <input type="checkbox" data-action="add" name="modules[<?= $mid ?>][add]" value="1" <?= $curA ? 'checked' : '' ?>>
                                        <?php if (!$isOverridden) echo $roleHint('can_add'); ?>
                                    </td>
                                    <td class="center">
                                        <input type="checkbox" data-action="edit" name="modules[<?= $mid ?>][edit]" value="1" <?= $curE ? 'checked' : '' ?>>
                                        <?php if (!$isOverridden) echo $roleHint('can_edit'); ?>
                                    </td>
                                    <td class="center">
                                        <input type="checkbox" data-action="delete" name="modules[<?= $mid ?>][delete]" value="1" <?= $curD ? 'checked' : '' ?>>
                                        <?php if (!$isOverridden) echo $roleHint('can_delete'); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="actions">
                    <button type="submit" class="btn btn-primary">
                        Save Permissions for <?= htmlspecialchars($selectedUser['username']) ?>
                    </button>
                    <span style="flex:1;"></span>
                    <button type="submit" form="clearForm" class="btn btn-danger"
                            onclick="return confirm('Remove all overrides for this user? They will revert to pure inherit (allow).');">
                        Clear Overrides (revert to inherit)
                    </button>
                </div>
            </form>

            <form method="post" id="clearForm" style="display:none;">
                <input type="hidden" name="action" value="clear">
                <input type="hidden" name="user_id" value="<?= (int)$selectedUser['id'] ?>">
            </form>

        <?php endif; ?>

    </div>
</div>

<script>
function setAll(action, value) {
    document.querySelectorAll('#permForm input[type=checkbox][data-action="' + action + '"]').forEach(function(cb) {
        cb.checked = value;
    });
}
function checkAll() {
    document.querySelectorAll('#permForm input[type=checkbox]').forEach(function(cb) { cb.checked = true; });
}
function uncheckAll() {
    document.querySelectorAll('#permForm input[type=checkbox]').forEach(function(cb) { cb.checked = false; });
}
</script>

</body>
</html>