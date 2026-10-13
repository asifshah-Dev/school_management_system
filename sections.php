<?php
ob_start();
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

require_once('conn_inc.php');

$msg = ''; $msg_type = '';
$edit_mode = false;
$section_data = ['id' => '', 'title' => ''];

// ─── Edit mode load ───
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT id, title FROM sections WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($r) { $section_data = $r; $edit_mode = true; }
}

// ─── POST handling ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['message'] = 'Invalid request.';
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $id    = (int)($_POST['id'] ?? 0);
    $title = trim($_POST['title'] ?? '');

    if ($title === '') {
        $_SESSION['message'] = 'Section title cannot be empty.';
        $_SESSION['message_type'] = 'danger';
    } else {
        // Duplicate check (case-insensitive)
        $check = $conn->prepare("SELECT id FROM sections WHERE LOWER(title) = LOWER(?) AND id <> ? LIMIT 1");
        $check->bind_param("si", $title, $id);
        $check->execute();
        $dupe = $check->get_result()->fetch_assoc();
        $check->close();

        if ($dupe) {
            $_SESSION['message'] = 'A section with that title already exists.';
            $_SESSION['message_type'] = 'warning';
        } elseif ($id > 0) {
            $stmt = $conn->prepare("UPDATE sections SET title = ? WHERE id = ?");
            $stmt->bind_param("si", $title, $id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['message'] = 'Section updated.';
            $_SESSION['message_type'] = 'success';
        } else {
            $stmt = $conn->prepare("INSERT INTO sections (title) VALUES (?)");
            $stmt->bind_param("s", $title);
            $stmt->execute();
            $stmt->close();
            $_SESSION['message'] = 'Section added.';
            $_SESSION['message_type'] = 'success';
        }
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// ─── Delete ───
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    // Block if referenced anywhere
    $used = 0;
    $r = $conn->query("SELECT COUNT(*) c FROM class_sections WHERE section_id = $id")->fetch_assoc();
    $used += (int)$r['c'];
    if ($used > 0) {
        $_SESSION['message'] = "Cannot delete — this section is assigned to $used class(es). Remove those first.";
        $_SESSION['message_type'] = 'danger';
    } else {
        $stmt = $conn->prepare("DELETE FROM sections WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['message'] = 'Section deleted.';
        $_SESSION['message_type'] = 'success';
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// ─── List with usage count ───
$sections = $conn->query("
    SELECT s.id, s.title,
           (SELECT COUNT(*) FROM class_sections cs WHERE cs.section_id = s.id) AS class_count
    FROM sections s
    ORDER BY s.title
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Sections — Dar-e-Arqm</title>
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
    .section-title + form + .section-title { margin-top: 30px; }

    .alert { padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; border-left: 4px solid; }
    .alert.success { background: #d1fae5; border-color: #059669; color: #065f46; }
    .alert.danger  { background: #fee2e2; border-color: #dc2626; color: #991b1b; }
    .alert.warning { background: #fef3c7; border-color: #d97706; color: #92400e; }

    .field { margin-bottom: 18px; }
    .field label {
        display: block; font-size: 11px; font-weight: 700; color: #334155;
        text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px;
    }
    .field input[type=text] {
        width: 100%; height: 46px; padding: 0 16px;
        border: 1px solid #cbd5e1; border-radius: 10px;
        font-size: 15px; font-family: inherit; background: #fff;
    }
    .field input:focus {
        outline: none; border-color: #1e40af;
        box-shadow: 0 0 0 4px rgba(30,64,175,0.1);
    }

    .btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        height: 44px; padding: 0 22px; border-radius: 10px;
        font-size: 14px; font-weight: 700; font-family: inherit;
        cursor: pointer; line-height: 1; border: 1px solid;
        text-decoration: none;
    }
    .btn-primary { background: #1e40af; color: #fff; border-color: #1e40af; }
    .btn-primary:hover { background: #1e3a8a; border-color: #1e3a8a; text-decoration: none; color: #fff; }
    .btn-ghost { background: #fff; color: #334155; border-color: #cbd5e1; }
    .btn-ghost:hover { background: #f1f5f9; border-color: #94a3b8; text-decoration: none; color: #1e293b; }

    table.sections { width: 100%; border-collapse: collapse; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
    table.sections thead th {
        background: #f8fafc; font-size: 11px; color: #64748b; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px;
        padding: 12px 14px; text-align: left; border-bottom: 2px solid #e2e8f0;
    }
    table.sections tbody td { padding: 12px 14px; font-size: 13px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    table.sections tbody tr:last-child td { border-bottom: none; }
    table.sections tbody tr:hover td { background: #f8fafc; }
    table.sections .title { font-weight: 700; color: #0f172a; font-size: 14px; }
    table.sections .count {
        display: inline-block; padding: 2px 8px; border-radius: 10px;
        font-size: 11px; font-weight: 700;
    }
    table.sections .count.used  { background: #dbeafe; color: #1e40af; }
    table.sections .count.free  { background: #f1f5f9; color: #64748b; }

    .rb {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 6px 12px; border-radius: 6px;
        font-size: 12px; font-weight: 600;
        text-decoration: none; cursor: pointer;
        border: 1px solid; font-family: inherit;
        margin-right: 4px;
    }
    .rb-edit { background: #fef3c7; color: #92400e; border-color: #fde68a; }
    .rb-edit:hover { background: #fde68a; text-decoration: none; color: #92400e; }
    .rb-del  { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
    .rb-del:hover { background: #fee2e2; text-decoration: none; color: #991b1b; }

    .actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; padding-top: 20px; border-top: 1px solid #e2e8f0; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">
    <div class="head">
        <h1><i class="fas fa-layer-group" style="opacity:0.8;"></i> Sections</h1>
        <div class="sub">Master list of sections (A, B, C, ...) used across all classes</div>
    </div>

    <div class="card">

        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert <?= htmlspecialchars($_SESSION['message_type']) ?>">
                <?= htmlspecialchars($_SESSION['message']) ?>
            </div>
            <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
        <?php endif; ?>

        <div class="section-title"><?= $edit_mode ? 'Edit Section' : 'Add New Section' ?></div>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="id" value="<?= (int)$section_data['id'] ?>">
            <div class="field">
                <label for="title">Section Title <span style="color:#dc2626;">*</span></label>
                <input type="text" id="title" name="title" required maxlength="10"
                       value="<?= htmlspecialchars($section_data['title']) ?>"
                       placeholder="e.g. A, B, C, Red, Blue">
            </div>
            <div class="actions">
                <?php if ($edit_mode ? !empty($perms['can_edit']) : !empty($perms['can_add'])): ?>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-<?= $edit_mode ? 'check' : 'plus' ?>"></i>
                        <?= $edit_mode ? 'Save Changes' : 'Add Section' ?>
                    </button>
                <?php endif; ?>
                <?php if ($edit_mode): ?>
                    <a href="sections.php" class="btn btn-ghost"><i class="fas fa-times"></i> Cancel</a>
                <?php endif; ?>
            </div>
        </form>

        <div class="section-title" style="margin-top: 30px;">All Sections (<?= count($sections) ?>)</div>

        <?php if (empty($sections)): ?>
            <div style="padding: 40px 20px; text-align: center; color: #94a3b8;">
                <div style="font-size: 40px; opacity: 0.4; margin-bottom: 10px;">📋</div>
                <div style="font-size: 16px; color: #475569; font-weight: 600;">No sections yet</div>
                <div style="margin-top: 6px; font-size: 13px;">Add sections A, B, C above, then assign them to classes.</div>
            </div>
        <?php else: ?>
            <table class="sections">
                <thead>
                    <tr>
                        <th style="width:60px;">#</th>
                        <th>Title</th>
                        <th style="width:180px;">Used in Classes</th>
                        <th style="width:180px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($sections as $s): ?>
                    <tr>
                        <td style="color:#64748b;"><?= $i++ ?></td>
                        <td><span class="title"><?= htmlspecialchars($s['title']) ?></span></td>
                        <td>
                            <?php if ((int)$s['class_count'] > 0): ?>
                                <span class="count used"><i class="fas fa-check"></i> <?= (int)$s['class_count'] ?> class(es)</span>
                            <?php else: ?>
                                <span class="count free">unused</span>
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
                                   onclick="return confirm('Delete this section? Only possible if no class uses it.');">
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