<?php
ob_start();
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
if (session_status() == PHP_SESSION_NONE) session_start();
require_once('conn_inc.php');

if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$lang = 'en';

$translations = array(
    'en' => array(
        'heading' => 'Classes & Sections',
        'subtitle' => 'Assign classes and sections per session, with coordinator and capacity',
        'add_title' => 'Add Class + Section',
        'edit_title' => 'Edit Assignment',
        'class_label' => 'Class',
        'section_label' => 'Section',
        'session_label' => 'Session',
        'coordinator_label' => 'Coordinator',
        'max_students_label' => 'Max Students',
        'submit' => 'Add Assignment',
        'save' => 'Save Changes',
        'cancel' => 'Cancel',
        'reset' => 'Reset',
        'success' => 'Assignment added successfully!',
        'update_success' => 'Assignment updated successfully!',
        'delete_success' => 'Assignment deleted successfully!',
        'error' => 'Error: ',
        'empty_error' => 'Required fields cannot be empty!',
        'no_records' => 'No assignments found.',
        'actions' => 'Actions',
        'invalid_csrf' => 'Invalid request!',
        'coordinator_none' => 'Unassigned',
    )
);

function h($v) { return htmlspecialchars((string)(isset($v) ? $v : ''), ENT_QUOTES, 'UTF-8'); }

/* ─── INIT plain variables (NOT an array) ─── */
$edit_mode   = false;
$form_id             = 0;
$form_class_id       = 0;
$form_section_id     = 0;
$form_session_id     = 0;
$form_coordinator_id = 0;
$form_max_students   = '';

/* ─── EDIT LOAD ─── */
if (isset($_GET['edit'])) {
    $eid = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM class_sections WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $eid);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $fetched = $res->fetch_assoc();
            $form_id             = isset($fetched['id']) ? (int)$fetched['id'] : 0;
            $form_class_id       = isset($fetched['class_id']) ? (int)$fetched['class_id'] : 0;
            $form_section_id     = isset($fetched['section_id']) ? (int)$fetched['section_id'] : 0;
            $form_session_id     = isset($fetched['session_id']) ? (int)$fetched['session_id'] : 0;
            $form_coordinator_id = isset($fetched['coordinator_id']) ? (int)$fetched['coordinator_id'] : 0;
            $form_max_students   = isset($fetched['max_students']) ? $fetched['max_students'] : '';
            $edit_mode = true;
        }
        $stmt->close();
    }
}

/* ─── POST (add / update) ─── */
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['message'] = $translations[$lang]['invalid_csrf'];
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $post_class_id       = isset($_POST['class_id']) ? intval($_POST['class_id']) : 0;
    $post_section_id     = isset($_POST['section_id']) ? intval($_POST['section_id']) : 0;
    $post_session_id     = isset($_POST['session_id']) ? intval($_POST['session_id']) : 0;
    $post_coordinator_id = isset($_POST['coordinator_id']) ? intval($_POST['coordinator_id']) : 0;
    $post_max_students   = isset($_POST['max_students']) ? intval($_POST['max_students']) : 0;
    $post_editing_id     = isset($_POST['id']) ? intval($_POST['id']) : 0;

    if ($post_class_id <= 0 || $post_section_id <= 0 || $post_session_id <= 0) {
        $_SESSION['message'] = $translations[$lang]['empty_error'];
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    if ($post_editing_id > 0) {
        $stmt = $conn->prepare("UPDATE class_sections SET class_id=?, section_id=?, session_id=?, coordinator_id=?, max_students=? WHERE id=?");
        if ($stmt) {
            $stmt->bind_param("iiiiii", $post_class_id, $post_section_id, $post_session_id, $post_coordinator_id, $post_max_students, $post_editing_id);
            if ($stmt->execute()) {
                $_SESSION['message'] = $translations[$lang]['update_success'];
                $_SESSION['message_type'] = 'success';
            } else {
                $_SESSION['message'] = $translations[$lang]['error'] . ' ' . $stmt->error;
                $_SESSION['message_type'] = 'danger';
            }
            $stmt->close();
        }
    } else {
        $stmt = $conn->prepare("INSERT INTO class_sections (class_id, section_id, session_id, coordinator_id, max_students, status) VALUES (?,?,?,?,?,0)");
        if ($stmt) {
            $stmt->bind_param("iiiii", $post_class_id, $post_section_id, $post_session_id, $post_coordinator_id, $post_max_students);
            if ($stmt->execute()) {
                $_SESSION['message'] = $translations[$lang]['success'];
                $_SESSION['message_type'] = 'success';
            } else {
                $_SESSION['message'] = $translations[$lang]['error'] . ' ' . $stmt->error;
                $_SESSION['message_type'] = 'danger';
            }
            $stmt->close();
        }
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

/* ─── DELETE ─── */
if (isset($_GET['delete']) && !empty($perms['can_delete'])) {
    $did = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM class_sections WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $did);
        if ($stmt->execute()) {
            $_SESSION['message'] = $translations[$lang]['delete_success'];
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = $translations[$lang]['error'] . ' ' . $stmt->error;
            $_SESSION['message_type'] = 'danger';
        }
        $stmt->close();
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

/* ─── DROPDOWNS ─── */
$classes_list = array();
$r = $conn->query("SELECT id, title FROM classes ORDER BY title ASC");
if ($r) { while ($c = $r->fetch_assoc()) $classes_list[] = $c; }

$sections_list = array();
$r = $conn->query("SELECT id, title FROM sections ORDER BY title ASC");
if ($r) { while ($c = $r->fetch_assoc()) $sections_list[] = $c; }

$sessions_list = array();
$r = $conn->query("SELECT id, title FROM sessions ORDER BY from_dated DESC");
if ($r) { while ($c = $r->fetch_assoc()) $sessions_list[] = $c; }

$coordinators_list = array();
$r = $conn->query("SELECT id, username FROM users WHERE status = 0 ORDER BY username ASC");
if ($r) { while ($c = $r->fetch_assoc()) $coordinators_list[] = $c; }

/* ─── FILTERS / PAGINATION ─── */
$q        = isset($_GET['q']) ? trim($_GET['q']) : '';
$session_filter = isset($_GET['session_filter']) ? intval($_GET['session_filter']) : 0;
$page     = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset   = ($page - 1) * $per_page;

$where = array();
$params = array();
$types = '';

if ($q !== '') {
    $where[] = "(c.title LIKE ? OR s.title LIKE ? OR ses.title LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}
if ($session_filter > 0) {
    $where[] = "cs.session_id = ?";
    $params[] = $session_filter;
    $types .= 'i';
}
$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$total_rows = 0;
$count_sql = "SELECT COUNT(*) AS c FROM class_sections cs
              LEFT JOIN classes c ON c.id = cs.class_id
              LEFT JOIN sections s ON s.id = cs.section_id
              LEFT JOIN sessions ses ON ses.id = cs.session_id
              $where_sql";
$stmt = $conn->prepare($count_sql);
if ($stmt) {
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $r = $stmt->get_result();
    if ($r && ($row2 = $r->fetch_assoc())) $total_rows = (int)$row2['c'];
    $stmt->close();
}
$total_pages = max(1, (int)ceil($total_rows / $per_page));
if ($page > $total_pages) { $page = $total_pages; $offset = ($page - 1) * $per_page; }

$rows = array();
$sql = "SELECT cs.id, cs.class_id, cs.section_id, cs.session_id, cs.coordinator_id, cs.max_students,
               c.title  AS class_title,
               s.title  AS section_title,
               ses.title AS session_title,
               u.username AS coordinator_name
        FROM class_sections cs
        LEFT JOIN classes  c   ON c.id   = cs.class_id
        LEFT JOIN sections s   ON s.id   = cs.section_id
        LEFT JOIN sessions ses ON ses.id = cs.session_id
        LEFT JOIN users    u   ON u.id   = cs.coordinator_id
        $where_sql
        ORDER BY cs.id DESC
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $bind_types = $types . 'ii';
    $bind_params = $params;
    $bind_params[] = $per_page;
    $bind_params[] = $offset;
    $stmt->bind_param($bind_types, ...$bind_params);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($r && ($rr = $r->fetch_assoc())) {
        $rr['enrolled_count'] = 0;
        $rows[] = $rr;
    }
    $stmt->close();
}

foreach ($rows as $idx => $rr) {
    $cnt = 0;
    $stmt2 = $conn->prepare("SELECT COUNT(DISTINCT student_registration_id) AS c FROM student_class WHERE class_id = ?");
    if ($stmt2) {
        $cid = (int)$rr['class_id'];
        $stmt2->bind_param("i", $cid);
        $stmt2->execute();
        $r2 = $stmt2->get_result();
        if ($r2 && ($c2 = $r2->fetch_assoc())) $cnt = (int)$c2['c'];
        $stmt2->close();
    }
    $rows[$idx]['enrolled_count'] = $cnt;
}

$grand_total = 0;
$r = $conn->query("SELECT COUNT(*) AS c FROM class_sections");
if ($r && ($rr = $r->fetch_assoc())) $grand_total = (int)$rr['c'];

function qs_with($overrides = array()) {
    $base = array(
        'q' => isset($_GET['q']) ? $_GET['q'] : '',
        'session_filter' => isset($_GET['session_filter']) ? $_GET['session_filter'] : 0,
        'page' => isset($_GET['page']) ? $_GET['page'] : 1,
    );
    foreach ($overrides as $k => $v) $base[$k] = $v;
    $clean = array();
    foreach ($base as $k => $v) {
        if ($v === '' || $v === null || $v === 0) continue;
        $clean[$k] = $v;
    }
    return '?' . http_build_query($clean);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($translations[$lang]['heading']); ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<style>
    * { box-sizing: border-box; }
    body { background: #eef1f5; font-family: 'Inter', sans-serif; color: #1e293b; margin: 0; padding: 0; }
    .wrap { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
    .page-head { background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); color: #fff; padding: 26px 32px; border-radius: 12px 12px 0 0; display: flex; align-items: center; gap: 16px; }
    .page-head .head-icon { width: 52px; height: 52px; background: rgba(255,255,255,0.15); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; }
    .page-head h1 { font-size: 24px; font-weight: 700; margin: 0; }
    .page-head .sub { font-size: 13px; opacity: 0.9; margin-top: 5px; }
    .card { background: #fff; padding: 28px 32px 32px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); margin-bottom: 30px; }
    .form-label { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 6px; display: block; }
    .form-control { border-radius: 8px; border: 1.5px solid #e2e8f0; padding: 10px 14px; font-size: 14px; font-family: inherit; transition: all .15s; height: 44px; }
    .form-control:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 3px rgba(30,64,175,0.10); }
    .btn-primary { background: #1e40af; border: 1px solid #1e40af; color: #fff; font-weight: 700; font-size: 13.5px; height: 44px; padding: 0 24px; border-radius: 8px; display: inline-flex; align-items: center; gap: 8px; transition: all .15s; }
    .btn-primary:hover { background: #1e3a8a; border-color: #1e3a8a; color: #fff; }
    .btn-secondary { background: #fff; border: 1.5px solid #e2e8f0; color: #475569; font-weight: 700; font-size: 13.5px; height: 44px; padding: 0 20px; border-radius: 8px; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; transition: all .15s; }
    .btn-secondary:hover { background: #f1f5f9; color: #1e293b; text-decoration: none; }
    .section-header { display: flex; align-items: center; gap: 10px; margin: 30px 0 16px; }
    .section-header .icon { width: 38px; height: 38px; border-radius: 10px; background: #dbeafe; color: #1e40af; display: flex; align-items: center; justify-content: center; font-size: 16px; }
    .section-header h2 { font-size: 16px; font-weight: 700; color: #0f172a; margin: 0; }
    .section-header .count { margin-left: auto; background: #f1f5f9; color: #475569; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; }
    .toolbar { display: grid; grid-template-columns: 1fr 200px; gap: 12px; margin-bottom: 16px; }
    @media (max-width: 600px) { .toolbar { grid-template-columns: 1fr; } }
    .search-box { position: relative; }
    .search-box i { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 14px; pointer-events: none; }
    .search-box input { width: 100%; height: 44px; border: 1.5px solid #e2e8f0; border-radius: 8px; padding: 0 16px 0 44px; font-size: 13.5px; font-family: inherit; background: #f9fafb; transition: all .15s; }
    .search-box input:focus { outline: none; background: #fff; border-color: #1e40af; box-shadow: 0 0 0 3px rgba(30,64,175,0.10); }
    .toolbar select { height: 44px; border: 1.5px solid #e2e8f0; border-radius: 8px; padding: 0 14px; font-size: 13.5px; font-family: inherit; background: #f9fafb; cursor: pointer; transition: all .15s; font-weight: 600; color: #334155; }
    .table-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; }
    table.assign-table { width: 100%; margin: 0; border-collapse: collapse; }
    table.assign-table thead th { background: #f8fafc; color: #94a3b8; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.7px; padding: 12px 16px; text-align: left; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
    table.assign-table tbody td { padding: 14px 16px; font-size: 13.5px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    table.assign-table tbody tr:hover td { background: #f8fafc; }
    .class-chip { display: inline-block; font-weight: 700; color: #0f172a; background: #eef2ff; padding: 4px 12px; border-radius: 8px; font-size: 13px; }
    .section-chip { display: inline-block; font-weight: 700; background: #dbeafe; color: #1e40af; padding: 4px 12px; border-radius: 8px; font-size: 12.5px; }
    .session-chip { display: inline-block; font-weight: 600; color: #475569; background: #f1f5f9; padding: 4px 12px; border-radius: 8px; font-size: 12.5px; }
    .coordinator { font-weight: 600; color: #334155; font-size: 13px; }
    .coordinator.unassigned { color: #cbd5e1; font-weight: 500; font-style: italic; }
    .capacity { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 700; color: #0f172a; }
    .capacity .num { background: #d1fae5; color: #065f46; padding: 3px 10px; border-radius: 10px; font-size: 12px; }
    .capacity .sep { color: #cbd5e1; }
    .capacity .max { background: #fee2e2; color: #991b1b; padding: 3px 10px; border-radius: 10px; font-size: 12px; }
    .btn-icon { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 8px; font-size: 13px; text-decoration: none; border: 0; cursor: pointer; transition: all .15s; margin-left: 4px; }
    .btn-icon.edit { background: #fef3c7; color: #d97706; }
    .btn-icon.edit:hover { background: #d97706; color: #fff; text-decoration: none; }
    .btn-icon.danger { background: #fee2e2; color: #dc2626; }
    .btn-icon.danger:hover { background: #dc2626; color: #fff; text-decoration: none; }
    .alert { border-radius: 8px; font-size: 13.5px; padding: 12px 16px; margin-bottom: 20px; border: 0; font-weight: 500; }
    .alert-success { background: #d1fae5; color: #065f46; }
    .alert-danger { background: #fee2e2; color: #991b1b; }
    .empty-state { padding: 60px 20px; text-align: center; color: #94a3b8; }
    .empty-state .big { font-size: 42px; opacity: 0.4; margin-bottom: 12px; }
    .empty-state .msg { font-weight: 600; color: #475569; font-size: 15px; }
    .pagination-bar { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-top: 18px; flex-wrap: wrap; }
    .pagination-info { font-size: 12.5px; color: #64748b; font-weight: 500; }
    .pagination { display: inline-flex; gap: 4px; margin: 0; }
    .pagination a, .pagination span { display: inline-flex; align-items: center; justify-content: center; min-width: 36px; height: 36px; padding: 0 10px; border-radius: 8px; border: 1.5px solid #e2e8f0; background: #fff; color: #475569; text-decoration: none; font-size: 13px; font-weight: 700; }
    .pagination .current { background: #1e40af; border-color: #1e40af; color: #fff; }
    .pagination .dots { background: transparent; border-color: transparent; cursor: default; color: #94a3b8; }
</style>
</head>
<body>
<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="page-head">
        <div class="head-icon"><i class="fas fa-school"></i></div>
        <div>
            <h1><?php echo h($translations[$lang]['heading']); ?></h1>
            <div class="sub"><?php echo h($translations[$lang]['subtitle']); ?></div>
        </div>
    </div>

    <div class="card">

        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?php echo h($_SESSION['message_type']); ?>">
                <?php echo h($_SESSION['message']); ?>
            </div>
            <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
        <?php endif; ?>

        <form method="post" action="">
            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
            <?php if ($edit_mode): ?>
                <input type="hidden" name="id" value="<?php echo (int)$form_id; ?>">
            <?php endif; ?>

            <h2 style="font-size:15px;font-weight:700;margin:0 0 18px 0;color:#0f172a;">
                <i class="fas fa-plus-circle" style="color:#1e40af;margin-right:6px;"></i>
                <?php echo $edit_mode ? h($translations[$lang]['edit_title']) : h($translations[$lang]['add_title']); ?>
            </h2>

            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label class="form-label">Session <span class="text-danger">*</span></label>
                        <select class="form-control" name="session_id" required>
                            <option value="">— Select Session —</option>
                            <?php foreach ($sessions_list as $sess):
                                $is_sel = ($form_session_id === (int)$sess['id']) ? 'selected' : '';
                            ?>
                                <option value="<?php echo (int)$sess['id']; ?>" <?php echo $is_sel; ?>>
                                    <?php echo h($sess['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label class="form-label">Class <span class="text-danger">*</span></label>
                        <select class="form-control" name="class_id" required>
                            <option value="">— Select Class —</option>
                            <?php foreach ($classes_list as $cls):
                                $is_sel = ($form_class_id === (int)$cls['id']) ? 'selected' : '';
                            ?>
                                <option value="<?php echo (int)$cls['id']; ?>" <?php echo $is_sel; ?>>
                                    <?php echo h($cls['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label class="form-label">Section <span class="text-danger">*</span></label>
                        <select class="form-control" name="section_id" required>
                            <option value="">— Select Section —</option>
                            <?php foreach ($sections_list as $sec):
                                $is_sel = ($form_section_id === (int)$sec['id']) ? 'selected' : '';
                            ?>
                                <option value="<?php echo (int)$sec['id']; ?>" <?php echo $is_sel; ?>>
                                    <?php echo h($sec['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label class="form-label">Max Students</label>
                        <input type="number" min="0" class="form-control" name="max_students"
                               value="<?php echo h($form_max_students); ?>"
                               placeholder="e.g. 40">
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label">Coordinator</label>
                        <select class="form-control" name="coordinator_id">
                            <option value="0">— Unassigned —</option>
                            <?php foreach ($coordinators_list as $co):
                                $is_sel = ($form_coordinator_id === (int)$co['id']) ? 'selected' : '';
                            ?>
                                <option value="<?php echo (int)$co['id']; ?>" <?php echo $is_sel; ?>>
                                    <?php echo h($co['username']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="text-right" style="margin-top:6px;">
                <?php if ($edit_mode ? !empty($perms['can_edit']) : !empty($perms['can_add'])): ?>
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-<?php echo $edit_mode ? 'save' : 'plus'; ?>"></i>
                        <?php echo $edit_mode ? h($translations[$lang]['save']) : h($translations[$lang]['submit']); ?>
                    </button>
                <?php endif; ?>
                <?php if ($edit_mode): ?>
                    <a href="classes.php" class="btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                <?php else: ?>
                    <button type="reset" class="btn-secondary">
                        <i class="fas fa-redo"></i> Reset
                    </button>
                <?php endif; ?>
            </div>
        </form>

        <div class="section-header">
            <div class="icon"><i class="fas fa-list"></i></div>
            <h2>Assignments</h2>
            <div class="count"><?php echo number_format($grand_total); ?> total</div>
        </div>

        <form method="get" action="" class="toolbar" id="filterForm">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" name="q" id="searchInput"
                       placeholder="Search class, section, or session..."
                       value="<?php echo h($q); ?>" autocomplete="off">
            </div>
            <select name="session_filter" id="sessionFilter">
                <option value="0">All Sessions</option>
                <?php foreach ($sessions_list as $sess): ?>
                    <option value="<?php echo (int)$sess['id']; ?>" <?php echo ($session_filter === (int)$sess['id']) ? 'selected' : ''; ?>>
                        <?php echo h($sess['title']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if (!empty($rows)): ?>
            <div class="table-wrap">
                <table class="assign-table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">#</th>
                            <th style="width: 200px;">Session</th>
                            <th style="width: 140px;">Class</th>
                            <th style="width: 120px;">Section</th>
                            <th>Coordinator</th>
                            <th style="width: 180px;">Enrolled / Capacity</th>
                            <?php if (!empty($perms['can_edit']) || !empty($perms['can_delete'])): ?>
                                <th style="width: 130px; text-align:right;">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $counter = $offset + 1; foreach ($rows as $r):
                            $max      = (int)$r['max_students'];
                            $enrolled = (int)$r['enrolled_count'];
                        ?>
                        <tr>
                            <td style="font-family:ui-monospace,monospace;font-weight:700;color:#94a3b8;"><?php echo $counter++; ?></td>
                            <td><span class="session-chip"><?php echo h(isset($r['session_title']) ? $r['session_title'] : '—'); ?></span></td>
                            <td><span class="class-chip"><?php echo h(isset($r['class_title']) ? $r['class_title'] : '—'); ?></span></td>
                            <td><span class="section-chip"><?php echo h(isset($r['section_title']) ? $r['section_title'] : '—'); ?></span></td>
                            <td>
                                <?php if (!empty($r['coordinator_name'])): ?>
                                    <span class="coordinator"><?php echo h($r['coordinator_name']); ?></span>
                                <?php else: ?>
                                    <span class="coordinator unassigned">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="capacity">
                                    <span class="num"><?php echo $enrolled; ?></span>
                                    <span class="sep">/</span>
                                    <span class="max"><?php echo $max > 0 ? $max : '∞'; ?></span>
                                </div>
                            </td>
                            <?php if (!empty($perms['can_edit']) || !empty($perms['can_delete'])): ?>
                            <td style="text-align:right; white-space:nowrap;">
                                <?php if (!empty($perms['can_edit'])): ?>
                                    <a href="?edit=<?php echo (int)$r['id']; ?>" class="btn-icon edit" title="Edit">
                                        <i class="fas fa-pen"></i>
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($perms['can_delete'])): ?>
                                    <a href="<?php echo qs_with(array('delete' => (int)$r['id'])); ?>" class="btn-icon danger" title="Delete"
                                       onclick="return confirm('Delete this assignment?');">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="pagination-bar">
                <div class="pagination-info">
                    Showing <b><?php echo number_format($offset + 1); ?></b>–<b><?php echo number_format(min($offset + $per_page, $total_rows)); ?></b> of <b><?php echo number_format($total_rows); ?></b>
                </div>
                <div class="pagination">
                    <?php
                    if ($page > 1) echo '<a href="' . qs_with(array('page' => $page - 1)) . '"><i class="fas fa-chevron-left"></i></a>';
                    $range = 2;
                    $start = max(1, $page - $range);
                    $end   = min($total_pages, $page + $range);
                    if ($start > 1) {
                        echo '<a href="' . qs_with(array('page' => 1)) . '">1</a>';
                        if ($start > 2) echo '<span class="dots">...</span>';
                    }
                    for ($i = $start; $i <= $end; $i++) {
                        if ($i === $page) echo '<span class="current">' . $i . '</span>';
                        else echo '<a href="' . qs_with(array('page' => $i)) . '">' . $i . '</a>';
                    }
                    if ($end < $total_pages) {
                        if ($end < $total_pages - 1) echo '<span class="dots">...</span>';
                        echo '<a href="' . qs_with(array('page' => $total_pages)) . '">' . $total_pages . '</a>';
                    }
                    if ($page < $total_pages) echo '<a href="' . qs_with(array('page' => $page + 1)) . '"><i class="fas fa-chevron-right"></i></a>';
                    ?>
                </div>
            </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="empty-state">
                <div class="big">&#127979;</div>
                <div class="msg">No assignments found.</div>
                <div class="hint">Add your first class + section assignment above.</div>
            </div>
        <?php endif; ?>

    </div>
</div>

<script>
$(document).ready(function () {
    var searchTimer = null;
    var $filterForm = $('#filterForm');

    $('#searchInput').on('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { $filterForm.submit(); }, 400);
    });

    $('#sessionFilter').on('change', function () { $filterForm.submit(); });
});
</script>

</body>
</html>
<?php if (isset($conn)) $conn->close(); ob_end_flush(); ?>