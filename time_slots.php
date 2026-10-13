<?php
ob_start();
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

require_once('conn_inc.php');

/* ─── Save / update ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['message'] = 'Invalid request.';
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $id         = (int)($_POST['id'] ?? 0);
    $title      = trim($_POST['title'] ?? '');
    $start_time = trim($_POST['start_time'] ?? '');
    $end_time   = trim($_POST['end_time'] ?? '');

    $errors = [];
    if ($title === '') $errors[] = 'Title is required.';
    if (!preg_match('/^\d{2}:\d{2}$/', $start_time)) $errors[] = 'Start time must be HH:MM.';
    if (!preg_match('/^\d{2}:\d{2}$/', $end_time))   $errors[] = 'End time must be HH:MM.';
    if ($start_time >= $end_time) $errors[] = 'End time must be after start time.';

    if (!empty($errors)) {
        $_SESSION['message'] = implode(' ', $errors);
        $_SESSION['message_type'] = 'danger';
    } elseif ($id > 0) {
        $stmt = $conn->prepare("UPDATE time_slots SET title = ?, start_time = ?, end_time = ? WHERE id = ?");
        $stmt->bind_param("sssi", $title, $start_time, $end_time, $id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['message'] = 'Time slot updated.';
        $_SESSION['message_type'] = 'success';
    } else {
        $stmt = $conn->prepare("INSERT INTO time_slots (title, start_time, end_time, status) VALUES (?, ?, ?, 0)");
        $stmt->bind_param("sss", $title, $start_time, $end_time);
        $stmt->execute();
        $stmt->close();
        $_SESSION['message'] = 'Time slot added.';
        $_SESSION['message_type'] = 'success';
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

/* ─── Delete ─── */
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $used = (int)$conn->query("SELECT COUNT(*) c FROM timetable WHERE time_slot_id = $id")->fetch_assoc()['c'];
    if ($used > 0) {
        $_SESSION['message'] = "Cannot delete — this slot is used in $used timetable entries.";
        $_SESSION['message_type'] = 'danger';
    } else {
        $stmt = $conn->prepare("DELETE FROM time_slots WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['message'] = 'Time slot deleted.';
        $_SESSION['message_type'] = 'success';
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

/* ─── Edit mode ─── */
$edit = false;
$edit_data = ['id' => '', 'title' => '', 'start_time' => '08:00', 'end_time' => '08:50'];
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT id, title, start_time, end_time FROM time_slots WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($r) { $edit_data = $r; $edit = true; }
}

$slots = $conn->query("
    SELECT id, title, start_time, end_time,
           (SELECT COUNT(*) FROM timetable WHERE time_slot_id = time_slots.id) AS usage_count
    FROM time_slots
    ORDER BY start_time
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Time Slots — Dar-e-Arqm</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #eef1f5; font-family: 'Inter', sans-serif; color: #1e293b; }
    .wrap { max-width: 900px; margin: 30px auto; padding: 0 20px; }
    .head {
        background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
        color: #fff; padding: 30px 40px 26px 40px;
        border-radius: 12px 12px 0 0;
        box-shadow: 0 4px 16px rgba(30,64,175,0.15);
    }
    .head h1 { font-size: 28px; font-weight: 700; }
    .head .sub { font-size: 14px; opacity: 0.9; margin-top: 6px; }
    .card { background: #fff; padding: 28px 40px 40px 40px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
    .section-title {
        font-size: 12px; font-weight: 800; color: #64748b;
        text-transform: uppercase; letter-spacing: 2px;
        margin: 0 0 16px 0; padding-bottom: 8px;
        border-bottom: 1px solid #e2e8f0;
    }
    .alert { padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; border-left: 4px solid; }
    .alert.success { background: #d1fae5; border-color: #059669; color: #065f46; }
    .alert.danger  { background: #fee2e2; border-color: #dc2626; color: #991b1b; }

    .grid2 { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 16px; margin-bottom: 20px; }
    .field label {
        display: block; font-size: 11px; font-weight: 700; color: #334155;
        text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px;
    }
    .field input {
        width: 100%; height: 46px; padding: 0 14px;
        border: 1px solid #cbd5e1; border-radius: 10px;
        font-size: 15px; font-family: inherit; background: #fff;
    }
    .field input:focus {
        outline: none; border-color: #1e40af;
        box-shadow: 0 0 0 4px rgba(30,64,175,0.1);
    }

    .actions { display: flex; justify-content: flex-end; gap: 10px; padding-top: 18px; border-top: 1px solid #e2e8f0; }
    .btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        height: 44px; padding: 0 22px; border-radius: 10px;
        font-size: 14px; font-weight: 700; font-family: inherit;
        cursor: pointer; line-height: 1; border: 1px solid; text-decoration: none;
    }
    .btn-primary { background: #1e40af; color: #fff; border-color: #1e40af; }
    .btn-primary:hover { background: #1e3a8a; border-color: #1e3a8a; text-decoration: none; color: #fff; }
    .btn-ghost { background: #fff; color: #334155; border-color: #cbd5e1; }
    .btn-ghost:hover { background: #f1f5f9; text-decoration: none; color: #1e293b; }

    table.slots { width: 100%; border-collapse: collapse; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
    table.slots thead th {
        background: #f8fafc; font-size: 11px; color: #64748b; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px;
        padding: 12px 14px; text-align: left; border-bottom: 2px solid #e2e8f0;
    }
    table.slots tbody td { padding: 12px 14px; font-size: 13px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    table.slots tbody tr:last-child td { border-bottom: none; }
    table.slots tbody tr:hover td { background: #f8fafc; }
    table.slots .num { font-family: 'SF Mono', monospace; }
    .pill { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 10px; font-weight: 700; }
    .pill.used { background: #dbeafe; color: #1e40af; }
    .pill.free { background: #f1f5f9; color: #64748b; }

    .rb { display: inline-flex; align-items: center; gap: 5px; padding: 6px 12px; border-radius: 6px;
          font-size: 12px; font-weight: 600; text-decoration: none; cursor: pointer; border: 1px solid; font-family: inherit; margin-right: 4px; }
    .rb-edit { background: #fef3c7; color: #92400e; border-color: #fde68a; }
    .rb-edit:hover { background: #fde68a; text-decoration: none; color: #92400e; }
    .rb-del  { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
    .rb-del:hover { background: #fee2e2; text-decoration: none; color: #991b1b; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">
    <div class="head">
        <h1><i class="fas fa-clock" style="opacity:0.8;"></i> Time Slots</h1>
        <div class="sub">Manage the periods that make up the school day</div>
    </div>

    <div class="card">
        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert <?= htmlspecialchars($_SESSION['message_type']) ?>">
                <?= htmlspecialchars($_SESSION['message']) ?>
            </div>
            <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
        <?php endif; ?>

        <div class="section-title"><?= $edit ? 'Edit Time Slot' : 'Add Time Slot' ?></div>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="id" value="<?= (int)$edit_data['id'] ?>">
            <div class="grid2">
                <div class="field">
                    <label>Title <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="title" required maxlength="50"
                           value="<?= htmlspecialchars($edit_data['title']) ?>"
                           placeholder="e.g. Period 1, Break, Assembly">
                </div>
                <div class="field">
                    <label>Start Time <span style="color:#dc2626;">*</span></label>
                    <input type="time" name="start_time" required
                           value="<?= htmlspecialchars(substr($edit_data['start_time'], 0, 5)) ?>">
                </div>
                <div class="field">
                    <label>End Time <span style="color:#dc2626;">*</span></label>
                    <input type="time" name="end_time" required
                           value="<?= htmlspecialchars(substr($edit_data['end_time'], 0, 5)) ?>">
                </div>
            </div>
            <div class="actions">
                <?php if ($edit ? !empty($perms['can_edit']) : !empty($perms['can_add'])): ?>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-<?= $edit ? 'check' : 'plus' ?>"></i>
                        <?= $edit ? 'Save Changes' : 'Add Slot' ?>
                    </button>
                <?php endif; ?>
                <?php if ($edit): ?>
                    <a href="time_slots.php" class="btn btn-ghost">Cancel</a>
                <?php endif; ?>
            </div>
        </form>

        <div class="section-title" style="margin-top: 30px;">All Time Slots (<?= count($slots) ?>)</div>

        <?php if (empty($slots)): ?>
            <div style="padding: 40px; text-align: center; color: #94a3b8;">
                No time slots yet. Add the first period above.
            </div>
        <?php else: ?>
            <table class="slots">
                <thead>
                    <tr>
                        <th style="width:60px;">#</th>
                        <th>Title</th>
                        <th style="width:130px;">Start</th>
                        <th style="width:130px;">End</th>
                        <th style="width:130px;">Duration</th>
                        <th style="width:150px;">Used</th>
                        <th style="width:160px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i=1; foreach ($slots as $s):
                        $dur = (strtotime($s['end_time']) - strtotime($s['start_time'])) / 60;
                    ?>
                    <tr>
                        <td style="color:#64748b;"><?= $i++ ?></td>
                        <td><strong><?= htmlspecialchars($s['title']) ?></strong></td>
                        <td class="num"><?= date('h:i A', strtotime($s['start_time'])) ?></td>
                        <td class="num"><?= date('h:i A', strtotime($s['end_time'])) ?></td>
                        <td><?= (int)$dur ?> min</td>
                        <td>
                            <?php if ((int)$s['usage_count'] > 0): ?>
                                <span class="pill used"><?= (int)$s['usage_count'] ?> entries</span>
                            <?php else: ?>
                                <span class="pill free">unused</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($perms['can_edit'])): ?>
                                <a href="?edit=<?= (int)$s['id'] ?>" class="rb rb-edit">
                                    <i class="fas fa-pencil"></i> Edit
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($perms['can_delete'])): ?>
                                <a href="?delete=<?= (int)$s['id'] ?>" class="rb rb-del"
                                   onclick="return confirm('Delete this time slot? Only possible if unused.');">
                                    <i class="fas fa-trash"></i>
                                </a>
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