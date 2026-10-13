<?php
ob_start();
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

require_once('conn_inc.php');

/* ─── Save assignments ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['message'] = 'Invalid request.';
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $class_id         = (int)($_POST['class_id'] ?? 0);
    $class_section_id = (int)($_POST['class_section_id'] ?? 0);
    if ($class_section_id === 0) $class_section_id = null;

    $teacher_ids = isset($_POST['teacher_ids']) && is_array($_POST['teacher_ids'])
        ? array_map('intval', $_POST['teacher_ids']) : [];

    if ($class_id <= 0) {
        $_SESSION['message'] = 'Pick a class first.';
        $_SESSION['message_type'] = 'danger';
    } else {
        $conn->begin_transaction();
        try {
            // Wipe existing rows for this exact class (+ optional section)
            if ($class_section_id !== null) {
                $del = $conn->prepare("DELETE FROM teacher_classes WHERE class_section_id = ?");
                $del->bind_param("i", $class_section_id);
            } else {
                $del = $conn->prepare("DELETE FROM teacher_classes WHERE class_id = ? AND class_section_id IS NULL");
                $del->bind_param("i", $class_id);
            }
            $del->execute();
            $del->close();

            // Insert new
            if (!empty($teacher_ids)) {
                $ins = $conn->prepare("INSERT INTO teacher_classes (teacher_id, class_id, class_section_id) VALUES (?, ?, ?)");
                foreach ($teacher_ids as $tid) {
                    if ($tid <= 0) continue;
                    $ins->bind_param("iii", $tid, $class_id, $class_section_id);
                    $ins->execute();
                }
                $ins->close();
            }

            $conn->commit();
            $_SESSION['message'] = count($teacher_ids) . ' teacher(s) assigned.';
            $_SESSION['message_type'] = 'success';
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['message'] = 'Save failed: ' . $e->getMessage();
            $_SESSION['message_type'] = 'danger';
        }
    }
    // Redirect with the same class+section still selected
    $q = "?class_id=" . $class_id;
    if ($class_section_id !== null) $q .= "&class_section_id=" . $class_section_id;
    header("Location: " . $_SERVER['PHP_SELF'] . $q);
    exit();
}

/* ─── Hybrid class list ─── */
$class_options = $conn->query("
    SELECT
        c.id                AS class_id,
        c.title             AS class_title,
        COALESCE(cs.id, 0)  AS class_section_id,
        s.title             AS section_title,
        CASE
            WHEN cs.id IS NULL THEN c.title
            ELSE CONCAT(c.title, ' - ', s.title)
        END                 AS display_label
    FROM classes c
    LEFT JOIN class_sections cs ON cs.class_id = c.id AND cs.status = 0
    LEFT JOIN sections s ON s.id = cs.section_id
    WHERE c.status = 0 OR c.status IS NULL
    ORDER BY c.title, s.title
")->fetch_all(MYSQLI_ASSOC);

$sel_class_id     = (int)($_GET['class_id'] ?? 0);
$sel_class_section_id = (int)($_GET['class_section_id'] ?? 0);

/* ─── Teachers (role_id = 3) ─── */
$teachers = $conn->query("
    SELECT id, username, email
    FROM users
    WHERE role_id = 3 AND status = 0
    ORDER BY username COLLATE utf8mb4_general_ci
")->fetch_all(MYSQLI_ASSOC);

/* ─── Existing assignments for selected class(+section) ─── */
$assigned_ids = [];
if ($sel_class_id > 0) {
    if ($sel_class_section_id > 0) {
        $stmt = $conn->prepare("SELECT teacher_id FROM teacher_classes WHERE class_section_id = ?");
        $stmt->bind_param("i", $sel_class_section_id);
    } else {
        $stmt = $conn->prepare("SELECT teacher_id FROM teacher_classes WHERE class_id = ? AND class_section_id IS NULL");
        $stmt->bind_param("i", $sel_class_id);
    }
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $assigned_ids[] = (int)$row['teacher_id'];
    $stmt->close();
}

/* ─── Teacher load (how many classes each teacher already teaches) ─── */
$teacher_load = [];
$r = $conn->query("
    SELECT teacher_id,
           COUNT(DISTINCT COALESCE(class_section_id, class_id * -1)) AS cnt
    FROM teacher_classes
    GROUP BY teacher_id
");
while ($row = $r->fetch_assoc()) $teacher_load[(int)$row['teacher_id']] = (int)$row['cnt'];

/* ─── Current label ─── */
$selected_label = '';
foreach ($class_options as $co) {
    if ((int)$co['class_id'] === $sel_class_id
        && (int)$co['class_section_id'] === $sel_class_section_id) {
        $selected_label = $co['display_label'];
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Assign Teachers — Dar-e-Arqm</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #eef1f5; font-family: 'Inter', sans-serif; color: #1e293b; }
    .wrap { max-width: 1100px; margin: 30px auto; padding: 0 20px; }

    .head {
        background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
        color: #fff; padding: 30px 40px 26px 40px;
        border-radius: 12px 12px 0 0;
        box-shadow: 0 4px 16px rgba(30,64,175,0.15);
    }
    .head h1 { font-size: 28px; font-weight: 700; }
    .head .sub { font-size: 14px; opacity: 0.9; margin-top: 6px; }

    .card { background: #fff; padding: 28px 40px 40px 40px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

    .alert { padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; border-left: 4px solid; }
    .alert.success { background: #d1fae5; border-color: #059669; color: #065f46; }
    .alert.danger  { background: #fee2e2; border-color: #dc2626; color: #991b1b; }

    .section-title {
        font-size: 12px; font-weight: 800; color: #64748b;
        text-transform: uppercase; letter-spacing: 2px;
        margin: 8px 0 16px 0; padding-bottom: 8px;
        border-bottom: 1px solid #e2e8f0;
        display: flex; justify-content: space-between; align-items: center;
    }
    .section-title .ctx {
        font-size: 12px; font-weight: 700; color: #1e40af;
        background: #eff6ff; padding: 3px 12px; border-radius: 20px;
        letter-spacing: 0.5px; text-transform: none;
    }

    .field { margin-bottom: 20px; max-width: 560px; }
    .field label {
        display: block; font-size: 12px; font-weight: 700; color: #334155;
        text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px;
    }
    .field select {
        width: 100%; height: 46px; padding: 0 14px;
        border: 1px solid #cbd5e1; border-radius: 10px;
        font-size: 15px; font-family: inherit; background: #fff;
        -webkit-appearance: none; appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L2 4h8z'/%3E%3C/svg%3E");
        background-repeat: no-repeat; background-position: right 14px center;
        padding-right: 36px;
    }
    .field select:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 4px rgba(30,64,175,0.1); }

    .teacher-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 12px; margin-bottom: 24px;
    }
    .teacher-card {
        display: flex; align-items: center; gap: 12px;
        padding: 14px 16px; border: 1px solid #e2e8f0; border-radius: 10px;
        background: #f8fafc; cursor: pointer;
        transition: all 0.15s;
    }
    .teacher-card:hover { border-color: #bfdbfe; background: #eff6ff; }
    .teacher-card.checked {
        border-color: #1e40af; background: #eff6ff;
        box-shadow: 0 0 0 3px rgba(30,64,175,0.1);
    }
    .teacher-card input[type=checkbox] {
        width: 18px; height: 18px; cursor: pointer;
        accent-color: #1e40af; flex-shrink: 0;
    }
    .teacher-card .info { flex: 1; min-width: 0; }
    .teacher-card .name { font-size: 14px; font-weight: 700; color: #0f172a; }
    .teacher-card .meta { font-size: 11px; color: #64748b; margin-top: 3px; }
    .teacher-card .load {
        display: inline-block; padding: 2px 8px; border-radius: 10px;
        font-size: 10px; font-weight: 700;
        background: #dbeafe; color: #1e40af;
    }

    .actions {
        display: flex; justify-content: flex-end; gap: 10px;
        padding-top: 20px; border-top: 1px solid #e2e8f0;
    }
    .btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        height: 46px; padding: 0 24px; border-radius: 10px;
        font-size: 14px; font-weight: 700; font-family: inherit;
        cursor: pointer; line-height: 1; border: 1px solid; text-decoration: none;
    }
    .btn-primary { background: #1e40af; color: #fff; border-color: #1e40af; }
    .btn-primary:hover { background: #1e3a8a; border-color: #1e3a8a; text-decoration: none; color: #fff; }
    .btn-ghost { background: #fff; color: #334155; border-color: #cbd5e1; }
    .btn-ghost:hover { background: #f1f5f9; text-decoration: none; color: #1e293b; }

    .empty { padding: 40px 20px; text-align: center; color: #94a3b8; }
    .empty .icon { font-size: 40px; opacity: 0.4; margin-bottom: 10px; }
    .empty .title { font-size: 16px; color: #475569; font-weight: 600; }
    .empty .sub { margin-top: 6px; font-size: 13px; }
    .empty .sub a { color: #1e40af; font-weight: 600; text-decoration: none; }
    .empty .sub a:hover { text-decoration: underline; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">
    <div class="head">
        <h1><i class="fas fa-chalkboard-user" style="opacity:0.8;"></i> Assign Teachers</h1>
        <div class="sub">Pick a class (or a specific class-section) and tick the teachers who teach it</div>
    </div>

    <div class="card">

        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert <?= htmlspecialchars($_SESSION['message_type']) ?>">
                <?= htmlspecialchars($_SESSION['message']) ?>
            </div>
            <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
        <?php endif; ?>

        <?php if (empty($class_options)): ?>
            <div class="empty">
                <div class="icon">🏫</div>
                <div class="title">No classes set up yet</div>
                <div class="sub">Go to <a href="classes.php">Classes</a> and add at least one class first.</div>
            </div>
        <?php else: ?>

            <div class="section-title">1. Pick a Class or Class-Section</div>

            <form method="get" class="field">
                <label>Class / Class-Section</label>
                <select name="pick" onchange="onPickChange(this)">
                    <option value="">— Select —</option>
                    <?php foreach ($class_options as $co):
                        $val = $co['class_id'] . '|' . $co['class_section_id'];
                        $sel = ((int)$co['class_id'] === $sel_class_id
                             && (int)$co['class_section_id'] === $sel_class_section_id);
                    ?>
                        <option value="<?= htmlspecialchars($val) ?>" <?= $sel ? 'selected' : '' ?>>
                            <?= htmlspecialchars($co['display_label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <!-- Hidden auto-submitted form to carry both fields -->
            <form method="get" id="pickForm" style="display:none;">
                <input type="hidden" name="class_id" id="pick_class_id">
                <input type="hidden" name="class_section_id" id="pick_class_section_id">
            </form>

            <?php if ($sel_class_id > 0): ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="class_id" value="<?= $sel_class_id ?>">
                    <input type="hidden" name="class_section_id" value="<?= $sel_class_section_id ?>">

                    <div class="section-title">
                        2. Tick Teachers
                        <span class="ctx"><?= htmlspecialchars($selected_label) ?></span>
                    </div>

                    <?php if (empty($teachers)): ?>
                        <div class="empty">
                            <div class="icon">👩‍🏫</div>
                            <div class="title">No teachers found</div>
                            <div class="sub">Add users with the <strong>Teacher</strong> role first at <a href="user_registration.php">User Registration</a>.</div>
                        </div>
                    <?php else: ?>
                        <div class="teacher-grid">
                            <?php foreach ($teachers as $t):
                                $tid = (int)$t['id'];
                                $checked = in_array($tid, $assigned_ids, true);
                                $load = $teacher_load[$tid] ?? 0;
                            ?>
                                <label class="teacher-card <?= $checked ? 'checked' : '' ?>">
                                    <input type="checkbox" name="teacher_ids[]" value="<?= $tid ?>" <?= $checked ? 'checked' : '' ?>>
                                    <div class="info">
                                        <div class="name"><?= htmlspecialchars($t['username']) ?></div>
                                        <div class="meta">
                                            <?= htmlspecialchars($t['email'] ?: 'no email') ?>
                                            &nbsp;·&nbsp;
                                            <span class="load"><?= $load ?> class<?= $load === 1 ? '' : 'es' ?></span>
                                        </div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div class="actions">
                            <?php if (!empty($perms['can_edit']) || !empty($perms['can_add'])): ?>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-check"></i> Save Assignments
                                </button>
                            <?php endif; ?>
                            <a href="assign_teacher.php" class="btn btn-ghost">Clear</a>
                        </div>
                    <?php endif; ?>
                </form>
            <?php else: ?>
                <div class="empty">
                    <div class="icon">🎯</div>
                    <div class="title">Select a class above</div>
                    <div class="sub">Then tick the teachers who teach this class or class-section.</div>
                </div>
            <?php endif; ?>

        <?php endif; ?>

    </div>
</div>

<script>
function onPickChange(sel) {
    var v = sel.value || '';
    if (!v) { location.href = 'assign_teacher.php'; return; }
    var parts = v.split('|');
    document.getElementById('pick_class_id').value = parts[0] || 0;
    document.getElementById('pick_class_section_id').value = parts[1] || 0;
    document.getElementById('pickForm').submit();
}

document.querySelectorAll('.teacher-card').forEach(function(card) {
    var cb = card.querySelector('input[type=checkbox]');
    card.addEventListener('click', function(e) {
        if (e.target === cb) return;
        e.preventDefault();
        cb.checked = !cb.checked;
        card.classList.toggle('checked', cb.checked);
    });
    cb.addEventListener('change', function() {
        card.classList.toggle('checked', cb.checked);
    });
});
</script>

</body>
</html>
<?php $conn->close(); ob_end_flush(); ?>