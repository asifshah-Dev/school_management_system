<?php
ob_start();
header('Content-Type: text/html; charset=utf-8');
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

require_once('conn_inc.php');
require_once('grade_helper.php');

/* ─────────────────────────────────────────────────────────────
   SAVE / UPDATE
   ───────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['message'] = 'Invalid request.';
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF']); exit;
    }

    $id         = (int)($_POST['id'] ?? 0);
    $grade      = strtoupper(trim($_POST['grade'] ?? ''));
    $remark     = trim($_POST['remark'] ?? '');
    $min_pct    = (float)($_POST['min_pct'] ?? 0);
    $max_pct    = (float)($_POST['max_pct'] ?? 0);
    $order      = (int)($_POST['display_order'] ?? 0);

    $errors = [];
    if ($grade === '' || !preg_match('/^[A-Z0-9+\-]{1,10}$/', $grade)) $errors[] = 'Grade must be 1–10 chars (A–Z, 0–9, +, -).';
    if ($remark === '') $errors[] = 'Remark is required.';
    if ($min_pct < 0 || $min_pct > 100) $errors[] = 'Min % must be 0–100.';
    if ($max_pct < 0 || $max_pct > 100) $errors[] = 'Max % must be 0–100.';
    if ($min_pct >= $max_pct) $errors[] = 'Min % must be less than Max %.';

    // Overlap check against other active bands
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT id, grade, min_pct, max_pct FROM grade_scale
                                WHERE status = 0 AND id <> ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($b = $rs->fetch_assoc()) {
            $bMin = (float)$b['min_pct']; $bMax = (float)$b['max_pct'];
            if ($min_pct <= $bMax && $max_pct >= $bMin) {
                $errors[] = "Range {$min_pct}–{$max_pct} overlaps existing grade {$b['grade']} ({$bMin}–{$bMax}).";
                break;
            }
        }
        $stmt->close();
    }

    // Duplicate grade letter check
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT id FROM grade_scale WHERE grade = ? AND id <> ?");
        $stmt->bind_param("si", $grade, $id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) $errors[] = "Grade '{$grade}' already exists.";
        $stmt->close();
    }

    if (!empty($errors)) {
        $_SESSION['message'] = implode(' ', $errors);
        $_SESSION['message_type'] = 'danger';
    } else {
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE grade_scale SET grade=?, remark=?, min_pct=?, max_pct=?, display_order=? WHERE id=?");
            $stmt->bind_param("ssddii", $grade, $remark, $min_pct, $max_pct, $order, $id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['message'] = "Grade {$grade} updated.";
        } else {
            $stmt = $conn->prepare("INSERT INTO grade_scale (grade, remark, min_pct, max_pct, display_order, status) VALUES (?, ?, ?, ?, ?, 0)");
            $stmt->bind_param("ssddi", $grade, $remark, $min_pct, $max_pct, $order);
            $stmt->execute();
            $stmt->close();
            $_SESSION['message'] = "Grade {$grade} added.";
        }
        $_SESSION['message_type'] = 'success';
    }
    header("Location: " . $_SERVER['PHP_SELF']); exit;
}

/* ─────────────────────────────────────────────────────────────
   DELETE (soft — sets status=1)
   ───────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['message'] = 'Invalid request.';
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF']); exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE grade_scale SET status = 1 WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['message'] = 'Grade deactivated.';
        $_SESSION['message_type'] = 'success';
    }
    header("Location: " . $_SERVER['PHP_SELF']); exit;
}

/* ─────────────────────────────────────────────────────────────
   RE-SEED DEFAULTS (wipes all + inserts the school's 4 bands)
   ───────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reseed') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['message'] = 'Invalid request.';
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF']); exit;
    }
    $conn->query("TRUNCATE TABLE grade_scale");
    $conn->query("INSERT INTO grade_scale (grade, remark, min_pct, max_pct, display_order, status) VALUES
        ('E', 'Exceeding Expectations',        90.00, 100.00, 1, 0),
        ('M', 'Meeting Expectations',          71.00,  89.99, 2, 0),
        ('A', 'Approaching Expectations',      61.00,  70.99, 3, 0),
        ('N', 'Not Yet Meeting Expectations',   0.00,  60.99, 4, 0)");
    $_SESSION['message'] = 'Grade scale reset to school defaults.';
    $_SESSION['message_type'] = 'success';
    header("Location: " . $_SERVER['PHP_SELF']); exit;
}

/* ─────────────────────────────────────────────────────────────
   EDIT MODE
   ───────────────────────────────────────────────────────────── */
$edit = false;
$edit_data = ['id'=>0,'grade'=>'','remark'=>'','min_pct'=>'','max_pct'=>'','display_order'=>0,'status'=>0];
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM grade_scale WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($r) { $edit_data = $r; $edit = true; }
}

/* ─────────────────────────────────────────────────────────────
   LOAD LIST (active + inactive)
   ───────────────────────────────────────────────────────────── */
$bands = [];
$q = $conn->query("SELECT * FROM grade_scale ORDER BY status ASC, min_pct DESC");
if ($q) while ($r = $q->fetch_assoc()) $bands[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Grade Scale — Dar-e-Arqm</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #eef1f5; font-family: 'Inter', sans-serif; color: #1e293b; }
    .wrap { max-width: 1000px; margin: 30px auto; padding: 0 20px; }
    .head { background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); color: #fff; padding: 26px 32px; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; }
    .head h1 { font-size: 26px; font-weight: 700; }
    .head .sub { font-size: 13px; opacity: 0.9; margin-top: 6px; }
    .head .hb { display: inline-flex; align-items: center; gap: 6px; padding: 9px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; text-decoration: none; cursor: pointer; border: 1px solid rgba(255,255,255,0.3); background: rgba(255,255,255,0.15); color: #fff; font-family: inherit; }
    .head .hb:hover { background: #fff; color: #1e40af; border-color: #fff; text-decoration: none; }
    .card { background: #fff; padding: 24px 28px 30px 28px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
    .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; border-left: 4px solid; }
    .alert.success { background: #d1fae5; border-color: #059669; color: #065f46; }
    .alert.danger  { background: #fee2e2; border-color: #dc2626; color: #991b1b; }
    .sec { font-size: 12px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 2px; margin: 0 0 16px 0; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
    .sec .sec-actions { display: flex; gap: 8px; }
    .grid { display: grid; grid-template-columns: 100px 1fr 120px 120px 90px; gap: 12px; margin-bottom: 16px; }
    .field label { display: block; font-size: 11px; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px; }
    .field input { width: 100%; height: 44px; padding: 0 12px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 14px; font-family: inherit; background: #fff; }
    .field input:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 4px rgba(30,64,175,0.1); }
    .actions { display: flex; justify-content: flex-end; gap: 10px; padding-top: 16px; border-top: 1px solid #e2e8f0; }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 42px; padding: 0 20px; border-radius: 10px; font-size: 13px; font-weight: 700; font-family: inherit; cursor: pointer; line-height: 1; border: 1px solid; text-decoration: none; }
    .btn-primary { background: #1e40af; color: #fff; border-color: #1e40af; }
    .btn-primary:hover { background: #1e3a8a; border-color: #1e3a8a; text-decoration: none; color: #fff; }
    .btn-ghost { background: #fff; color: #334155; border-color: #cbd5e1; }
    .btn-ghost:hover { background: #f1f5f9; text-decoration: none; color: #1e293b; }
    .btn-danger { background: #dc2626; color: #fff; border-color: #dc2626; }
    .btn-danger:hover { background: #b91c1c; border-color: #b91c1c; text-decoration: none; color: #fff; }
    .btn-sm { height: 34px; padding: 0 12px; font-size: 12px; }
    table.gs { width: 100%; border-collapse: collapse; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
    table.gs thead th { background: #f8fafc; font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 12px 14px; text-align: left; border-bottom: 2px solid #e2e8f0; }
    table.gs tbody td { padding: 12px 14px; font-size: 13px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    table.gs tbody tr:last-child td { border-bottom: none; }
    table.gs tbody tr:hover td { background: #f8fafc; }
    .pill { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; background: #dbeafe; color: #1e40af; }
    .pill.off { background: #f1f5f9; color: #64748b; }
    .grade-badge { display: inline-block; min-width: 32px; padding: 4px 10px; border-radius: 8px; font-size: 14px; font-weight: 800; background: #1e40af; color: #fff; text-align: center; }
    .range-cell { font-family: 'SF Mono', monospace; font-size: 12px; color: #475569; }
    .rb { display: inline-flex; align-items: center; gap: 5px; padding: 6px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; text-decoration: none; cursor: pointer; border: 1px solid; font-family: inherit; margin-right: 4px; }
    .rb-edit { background: #fef3c7; color: #92400e; border-color: #fde68a; }
    .rb-edit:hover { background: #fde68a; text-decoration: none; color: #92400e; }
    .rb-del  { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
    .rb-del:hover { background: #fee2e2; text-decoration: none; color: #991b1b; }
    .empty { padding: 40px 20px; text-align: center; color: #94a3b8; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">
    <div class="head">
        <div>
            <h1><i class="fas fa-award" style="opacity:0.8;"></i> Grade Scale</h1>
            <div class="sub">Configure percentage bands used by result entry and DMC printing</div>
        </div>
        <div>
            <?php if (!empty($perms['can_add']) || !empty($perms['can_edit'])): ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('Reset grade scale to school defaults (E/M/A/N)? This wipes all custom bands.');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="reseed">
                    <button type="submit" class="hb"><i class="fas fa-rotate"></i> Reset to Defaults</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert <?= htmlspecialchars($_SESSION['message_type']) ?>">
                <?= htmlspecialchars($_SESSION['message']) ?>
            </div>
            <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
        <?php endif; ?>

        <div class="sec"><?= $edit ? 'Edit Grade' : 'Add Grade' ?></div>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)$edit_data['id'] ?>">
            <div class="grid">
                <div class="field">
                    <label>Grade <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="grade" required maxlength="10" style="text-transform:uppercase;text-align:center;font-weight:800;"
                           value="<?= htmlspecialchars($edit_data['grade']) ?>" placeholder="A+">
                </div>
                <div class="field">
                    <label>Remark <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="remark" required maxlength="150"
                           value="<?= htmlspecialchars($edit_data['remark']) ?>" placeholder="e.g. Meeting Expectations">
                </div>
                <div class="field">
                    <label>Min % <span style="color:#dc2626;">*</span></label>
                    <input type="number" name="min_pct" required min="0" max="100" step="0.01"
                           value="<?= htmlspecialchars((string)$edit_data['min_pct']) ?>" placeholder="0">
                </div>
                <div class="field">
                    <label>Max % <span style="color:#dc2626;">*</span></label>
                    <input type="number" name="max_pct" required min="0" max="100" step="0.01"
                           value="<?= htmlspecialchars((string)$edit_data['max_pct']) ?>" placeholder="100">
                </div>
                <div class="field">
                    <label>Order</label>
                    <input type="number" name="display_order" min="0" max="999"
                           value="<?= (int)$edit_data['display_order'] ?>" placeholder="0">
                </div>
            </div>
            <div class="actions">
                <?php if ($edit ? !empty($perms['can_edit']) : !empty($perms['can_add'])): ?>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-<?= $edit ? 'check' : 'plus' ?>"></i>
                        <?= $edit ? 'Save Changes' : 'Add Grade' ?>
                    </button>
                <?php endif; ?>
                <?php if ($edit): ?>
                    <a href="grade_scale.php" class="btn btn-ghost">Cancel</a>
                <?php endif; ?>
            </div>
        </form>

        <div class="sec" style="margin-top:30px;">Active Grades (<?= count(array_filter($bands, fn($b) => $b['status'] == 0)) ?>)</div>

        <?php if (empty($bands)): ?>
            <div class="empty">
                <div style="font-size:38px;opacity:0.4;">🏅</div>
                <h3 style="color:#475569;font-weight:600;">No grades configured</h3>
                <p style="margin-top:8px;">Click <strong>Reset to Defaults</strong> above to seed the school's 4-band scale.</p>
            </div>
        <?php else: ?>
            <table class="gs">
                <thead>
                    <tr>
                        <th style="width:80px;">Grade</th>
                        <th>Remark</th>
                        <th style="width:180px;">Range</th>
                        <th style="width:80px;">Order</th>
                        <th style="width:100px;">Status</th>
                        <th style="width:150px; text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($bands as $b): ?>
                    <tr>
                        <td><span class="grade-badge"><?= htmlspecialchars($b['grade']) ?></span></td>
                        <td><?= htmlspecialchars($b['remark']) ?></td>
                        <td class="range-cell">
                            <?= number_format((float)$b['min_pct'], 2) ?>% – <?= number_format((float)$b['max_pct'], 2) ?>%
                        </td>
                        <td><?= (int)$b['display_order'] ?></td>
                        <td>
                            <?php if ((int)$b['status'] === 0): ?>
                                <span class="pill">Active</span>
                            <?php else: ?>
                                <span class="pill off">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <?php if ((int)$b['status'] === 0): ?>
                                <?php if (!empty($perms['can_edit'])): ?>
                                    <a href="?edit=<?= (int)$b['id'] ?>" class="rb rb-edit"><i class="fas fa-pencil"></i></a>
                                <?php endif; ?>
                                <?php if (!empty($perms['can_delete'])): ?>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Deactivate grade <?= htmlspecialchars($b['grade']) ?>?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                        <button type="submit" class="rb rb-del"><i class="fas fa-ban"></i></button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
<?php $conn->close(); ob_end_flush(); ?>