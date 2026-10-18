<?php
ob_start();
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once('conn_inc.php');

function handleFormSubmission($conn, $post) {
    if (!isset($post['csrf_token']) || $post['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['message'] = "Security error: Invalid CSRF token.";
        $_SESSION['message_type'] = 'danger';
        return false;
    }

    $class_id = isset($post['class_id']) ? intval($post['class_id']) : 0;
    $selected_subjects = isset($post['selected_subjects']) ? array_map('intval', $post['selected_subjects']) : [];
    $edit_class_id = isset($post['edit_class_id']) ? intval($post['edit_class_id']) : 0;

    if ($class_id <= 0) {
        $_SESSION['message'] = "Please select a class.";
        $_SESSION['message_type'] = 'danger';
        return false;
    }

    $success = true;

    if ($edit_class_id > 0) {
        $existing = [];
        $stmt = $conn->prepare("SELECT id, subject_id FROM subject_class WHERE class_id = ? AND status = 0");
        $stmt->bind_param("i", $edit_class_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $existing[(int)$row['subject_id']] = (int)$row['id'];
        }
        $stmt->close();

        /* Add missing — reactivate if soft-deleted */
        foreach ($selected_subjects as $sid) {
            if ($sid <= 0) continue;
            if (!isset($existing[$sid])) {
                $chk = $conn->prepare("SELECT id FROM subject_class WHERE class_id = ? AND subject_id = ? AND status = 1 LIMIT 1");
                $chk->bind_param("ii", $edit_class_id, $sid);
                $chk->execute();
                $chk->store_result();

                if ($chk->num_rows > 0) {
                    $chk->bind_result($existing_id);
                    $chk->fetch();
                    $chk->close();
                    $react = $conn->prepare("UPDATE subject_class SET status = 0 WHERE id = ?");
                    $react->bind_param("i", $existing_id);
                    if (!$react->execute()) $success = false;
                    $react->close();
                } else {
                    $chk->close();
                    $ins = $conn->prepare("INSERT INTO subject_class (class_id, subject_id, marks, status) VALUES (?, ?, 0, 0)");
                    $ins->bind_param("ii", $edit_class_id, $sid);
                    if (!$ins->execute()) $success = false;
                    $ins->close();
                }
            }
        }

        /* Remove unselected — soft delete */
        foreach ($existing as $sid => $row_id) {
            if (!in_array($sid, $selected_subjects)) {
                $del = $conn->prepare("UPDATE subject_class_sub SET status = 1 WHERE subject_class_id = ? AND status = 0");
                $del->bind_param("i", $row_id);
                $del->execute();
                $del->close();

                $del2 = $conn->prepare("UPDATE subject_class SET status = 1 WHERE id = ?");
                $del2->bind_param("i", $row_id);
                if (!$del2->execute()) $success = false;
                $del2->close();
            }
        }
    } else {
        $existing = [];
        $stmt = $conn->prepare("SELECT subject_id FROM subject_class WHERE class_id = ? AND status = 0");
        $stmt->bind_param("i", $class_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $existing[] = (int)$row['subject_id'];
        }
        $stmt->close();

        foreach ($selected_subjects as $sid) {
            if ($sid <= 0) continue;
            if (in_array($sid, $existing)) continue;

            $chk = $conn->prepare("SELECT id FROM subject_class WHERE class_id = ? AND subject_id = ? AND status = 1 LIMIT 1");
            $chk->bind_param("ii", $class_id, $sid);
            $chk->execute();
            $chk->store_result();

            if ($chk->num_rows > 0) {
                $chk->bind_result($existing_id);
                $chk->fetch();
                $chk->close();
                $react = $conn->prepare("UPDATE subject_class SET status = 0 WHERE id = ?");
                $react->bind_param("i", $existing_id);
                if (!$react->execute()) $success = false;
                $react->close();
            } else {
                $chk->close();
                $ins = $conn->prepare("INSERT INTO subject_class (class_id, subject_id, marks, status) VALUES (?, ?, 0, 0)");
                $ins->bind_param("ii", $class_id, $sid);
                if (!$ins->execute()) $success = false;
                $ins->close();
            }
        }
    }

    return $success;
}

function handleDeletion($conn, $id) {
    $stmt = $conn->prepare("UPDATE subject_class_sub SET status = 1 WHERE subject_class_id = ? AND status = 0");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("UPDATE subject_class SET status = 1 WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        $_SESSION['message'] = 'Subject removed from class.';
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = 'Error: Could not delete.';
        $_SESSION['message_type'] = 'danger';
    }
    $stmt->close();
}

function loadEditData($conn, $class_id) {
    $data = ['edit_class_data' => [], 'existing_subject_ids' => []];

    $stmt = $conn->prepare("SELECT id, title FROM classes WHERE id = ?");
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $data['edit_class_data'] = $res->fetch_assoc();
    $stmt->close();

    if (!$data['edit_class_data']) {
        $_SESSION['message'] = "Class not found.";
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    $stmt = $conn->prepare("SELECT subject_id FROM subject_class WHERE class_id = ? AND status = 0");
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $data['existing_subject_ids'][] = (int)$row['subject_id'];
    }
    $stmt->close();

    return $data;
}

function getClasses($conn) {
    $classes = [];
    $q = $conn->query("SELECT id, title FROM classes ORDER BY title");
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $classes[(int)$row['id']] = $row['title'];
        }
        $q->close();
    }
    return $classes;
}

function getSubjects($conn) {
    $subjects = [];
    $q = $conn->query("SELECT id, title, type FROM subjects WHERE status = 0 ORDER BY title");
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $subjects[(int)$row['id']] = [
                'title' => $row['title'],
                'type'  => $row['type']
            ];
        }
        $q->close();
    }
    return $subjects;
}

function getSubjectClasses($conn) {
    $grouped = [];
    $sql = "SELECT sc.id, sc.class_id, sc.subject_id, c.title AS class_title, s.title AS subject_title, s.type FROM subject_class sc JOIN classes c ON sc.class_id = c.id JOIN subjects s ON sc.subject_id = s.id WHERE sc.status = 0 AND s.status = 0 ORDER BY c.title, s.title";
    $q = $conn->query($sql);
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $cid = (int)$row['class_id'];
            if (!isset($grouped[$cid])) {
                $grouped[$cid] = [
                    'class_title' => $row['class_title'],
                    'subjects' => []
                ];
            }
            $grouped[$cid]['subjects'][] = $row;
        }
        $q->close();
    }
    return $grouped;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $is_edit = isset($_POST['edit_class_id']) && intval($_POST['edit_class_id']) > 0;
    if (handleFormSubmission($conn, $_POST)) {
        $_SESSION['message'] = $is_edit ? "Assignments updated successfully." : "Subjects assigned successfully.";
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = $_SESSION['message'] ?? "Error: Could not process assignment.";
        $_SESSION['message_type'] = 'danger';
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

if (isset($_GET['delete'])) {
    handleDeletion($conn, intval($_GET['delete']));
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

$edit_mode = false;
$edit_class_data = [];
$existing_subject_ids = [];

if (isset($_GET['edit_class'])) {
    $edit_mode = true;
    $edit_data = loadEditData($conn, intval($_GET['edit_class']));
    $edit_class_data = $edit_data['edit_class_data'];
    $existing_subject_ids = $edit_data['existing_subject_ids'];
}

$classes = getClasses($conn);
$subjects = getSubjects($conn);
$subject_classes_by_class = getSubjectClasses($conn);

$total_assignments = 0;
foreach ($subject_classes_by_class as $cd) $total_assignments += count($cd['subjects']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Subject-Class Assignments</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Inter', sans-serif; background: #f5f7fa; padding-bottom: 60px; }

    .page-header-card {
        background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        border-radius: 14px; padding: 20px 24px; color: #fff;
        margin-bottom: 18px; display: flex; align-items: center; gap: 14px;
        box-shadow: 0 8px 28px rgba(37, 99, 235, 0.28);
    }
    .page-header-card .header-icon {
        width: 48px; height: 48px; background: rgba(255,255,255,0.18);
        border-radius: 12px; display: flex; align-items: center;
        justify-content: center; font-size: 22px; flex-shrink: 0;
    }
    .page-header-card h1 { font-size: 19px; font-weight: 700; margin: 0; }
    .page-header-card .subtitle { font-size: 12px; opacity: 0.88; margin-top: 3px; }

    .stats-strip { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 16px; }
    .stat-card {
        background: #fff; border-radius: 10px; padding: 12px 14px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        display: flex; align-items: center; gap: 12px;
        border-left: 3px solid #2563eb;
    }
    .stat-card .stat-icon {
        width: 36px; height: 36px; border-radius: 9px;
        display: flex; align-items: center; justify-content: center; font-size: 15px;
        flex-shrink: 0;
    }
    .stat-card .stat-body .stat-value { font-size: 18px; font-weight: 800; color: #1e293b; line-height: 1; }
    .stat-card .stat-body .stat-label { font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 3px; }
    .stat-card.blue { border-left-color: #2563eb; }
    .stat-card.blue .stat-icon { background: #eff6ff; color: #2563eb; }
    .stat-card.green { border-left-color: #059669; }
    .stat-card.green .stat-icon { background: #d1fae5; color: #059669; }
    .stat-card.purple { border-left-color: #7c3aed; }
    .stat-card.purple .stat-icon { background: #ede9fe; color: #7c3aed; }

    .form-card {
        background: #fff; border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.06);
        padding: 20px 22px; margin-bottom: 20px;
        border-top: 4px solid #2563eb;
    }
    .form-card.editing { border-top-color: #f59e0b; background: #fffbeb; }
    .form-card .form-head {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 16px; flex-wrap: wrap; gap: 10px;
    }
    .form-card .form-head h4 {
        font-size: 15px; font-weight: 700; color: #1e293b; margin: 0;
        display: flex; align-items: center; gap: 8px;
    }
    .form-card.editing .form-head h4 { color: #b45309; }
    .form-card .form-head h4 i { color: #2563eb; }
    .form-card.editing .form-head h4 i { color: #f59e0b; }

    .form-card label.field-label {
        font-size: 11px; font-weight: 700; color: #64748b;
        text-transform: uppercase; letter-spacing: 0.5px;
        margin-bottom: 5px; display: block;
    }
    .form-card .form-control {
        border-radius: 7px; border: 1px solid #cbd5e1;
        font-size: 13px; padding: 6px 10px; height: 36px;
        line-height: 1.4; color: #1e293b;
        transition: border-color 0.15s, box-shadow 0.15s;
    }
    .form-card select.form-control { height: 36px; padding: 5px 10px; }
    .form-card .form-control:focus {
        border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
    }
    .form-card .form-group { margin-bottom: 14px; }
    .form-card .row > [class*="col-"] { padding-left: 8px; padding-right: 8px; }
    .form-card .row { margin-left: -8px; margin-right: -8px; }

    .subject-pick-toolbar {
        display: flex; gap: 6px; margin-bottom: 10px; flex-wrap: wrap;
    }
    .subject-pick-toolbar button {
        background: #f1f5f9; border: 1px solid #e2e8f0;
        color: #475569; padding: 5px 10px; border-radius: 7px;
        font-size: 11.5px; font-weight: 700; cursor: pointer;
        font-family: inherit; transition: all 0.15s;
    }
    .subject-pick-toolbar button:hover { background: #e2e8f0; color: #1e293b; }

    .subject-pick-grid {
        display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px;
        border: 1px solid #e2e8f0; border-radius: 9px;
        background: #f8fafc; padding: 12px;
        max-height: 420px; overflow-y: auto;
    }
    .subject-pick-item {
        display: flex; align-items: center; gap: 9px;
        padding: 9px 12px; background: #fff;
        border: 1.5px solid #e2e8f0; border-radius: 8px;
        cursor: pointer; transition: all 0.15s; user-select: none;
    }
    .subject-pick-item:hover { border-color: #93c5fd; background: #f8fafc; }
    .subject-pick-item input[type="checkbox"] {
        width: 16px; height: 16px; accent-color: #2563eb;
        cursor: pointer; flex-shrink: 0; margin: 0;
    }
    .subject-pick-item .subj-info { flex: 1; min-width: 0; }
    .subject-pick-item .subj-name { font-size: 12.5px; font-weight: 600; color: #1e293b; }
    .subject-pick-item .subj-type-badge {
        display: inline-block; margin-top: 2px;
        font-size: 9.5px; padding: 1px 6px; border-radius: 7px;
        font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px;
    }
    .subject-pick-item .subj-type-badge.standalone { background: #dbeafe; color: #1e40af; }
    .subject-pick-item .subj-type-badge.composite { background: #ede9fe; color: #6d28d9; }
    .subject-pick-item:has(input:checked) {
        border-color: #2563eb; background: #eff6ff;
    }
    .subject-pick-item:has(input:checked) .subj-name { color: #1d4ed8; }

    .btn-brand {
        background: #2563eb; border: 1px solid #2563eb; color: #fff;
        font-weight: 600; font-size: 12.5px; padding: 8px 16px;
        border-radius: 7px; display: inline-flex; align-items: center; gap: 6px;
        text-decoration: none; height: 36px;
    }
    .btn-brand:hover, .btn-brand:focus { background: #1d4ed8; border-color: #1d4ed8; color: #fff; text-decoration: none; }
    .btn-success-brand {
        background: #059669; border: 1px solid #059669; color: #fff;
        font-weight: 600; font-size: 12.5px; padding: 8px 16px;
        border-radius: 7px; display: inline-flex; align-items: center; gap: 6px;
        text-decoration: none; height: 36px;
    }
    .btn-success-brand:hover, .btn-success-brand:focus { background: #047857; border-color: #047857; color: #fff; text-decoration: none; }
    .btn-ghost {
        background: #f1f5f9; border: 1px solid #e2e8f0; color: #475569;
        font-weight: 600; font-size: 12.5px; padding: 8px 14px;
        border-radius: 7px; display: inline-flex; align-items: center; gap: 6px;
        text-decoration: none; height: 36px;
    }
    .btn-ghost:hover, .btn-ghost:focus { background: #e2e8f0; color: #1e293b; text-decoration: none; }

    .list-card {
        background: #fff; border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.06);
        overflow: hidden; margin-bottom: 20px;
    }
    .list-card-head {
        padding: 14px 20px; border-bottom: 1px solid #e2e8f0;
        display: flex; align-items: center; justify-content: space-between;
        flex-wrap: wrap; gap: 10px;
    }
    .list-card-head h3 {
        font-size: 14px; font-weight: 700; color: #1e293b; margin: 0;
        display: flex; align-items: center; gap: 8px;
    }
    .list-card-head h3 i { color: #2563eb; }
    .list-card-head .count-chip {
        background: #eff6ff; color: #2563eb;
        padding: 4px 11px; border-radius: 20px;
        font-size: 11.5px; font-weight: 700;
    }

    .class-group { border-bottom: 1px solid #e2e8f0; }
    .class-group:last-child { border-bottom: none; }

    .class-group-head {
        padding: 12px 20px;
        background: #eff6ff; border-bottom: 1px solid #e2e8f0;
        display: flex; align-items: center; justify-content: space-between;
        flex-wrap: wrap; gap: 10px;
    }
    .class-group-head h4 {
        font-size: 13.5px; font-weight: 700; color: #1e40af;
        margin: 0; display: flex; align-items: center; gap: 8px;
    }
    .class-group-head h4 .class-icon {
        width: 30px; height: 30px; background: #dbeafe;
        border-radius: 7px; display: flex; align-items: center;
        justify-content: center; font-size: 13px; color: #2563eb;
    }
    .class-group-head .count-label {
        font-size: 11px; color: #64748b;
        background: #fff; padding: 3px 10px;
        border-radius: 20px; font-weight: 700;
        border: 1px solid #e2e8f0;
    }
    .btn-edit-class {
        background: #f59e0b; border: 1px solid #f59e0b; color: #fff;
        font-weight: 600; font-size: 11.5px; padding: 5px 11px;
        border-radius: 7px; display: inline-flex; align-items: center; gap: 5px;
        text-decoration: none;
    }
    .btn-edit-class:hover, .btn-edit-class:focus { background: #d97706; border-color: #d97706; color: #fff; text-decoration: none; }

    .chip-wrap { padding: 12px 20px; display: flex; flex-wrap: wrap; gap: 7px; }
    .subject-chip {
        display: inline-flex; align-items: center; gap: 7px;
        padding: 5px 10px 5px 12px;
        background: #fff; border: 1px solid #e2e8f0;
        border-radius: 18px;
        font-size: 11.5px; font-weight: 600; color: #1e293b;
        transition: all 0.15s;
    }
    .subject-chip:hover { border-color: #93c5fd; background: #f8fafc; }
    .subject-chip .chip-type {
        font-size: 8.5px; padding: 1px 5px; border-radius: 5px;
        font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px;
    }
    .subject-chip .chip-type.standalone { background: #dbeafe; color: #1e40af; }
    .subject-chip .chip-type.composite { background: #ede9fe; color: #6d28d9; }
    .subject-chip .chip-remove {
        width: 18px; height: 18px; border-radius: 50%;
        border: none; background: #fef2f2; color: #dc2626;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 9px; cursor: pointer; padding: 0;
        text-decoration: none;
    }
    .subject-chip .chip-remove:hover { background: #fee2e2; color: #b91c1c; }

    .empty-state { padding: 45px 20px; text-align: center; }
    .empty-state .empty-icon {
        width: 60px; height: 60px; background: #eff6ff;
        border-radius: 50%; display: inline-flex;
        align-items: center; justify-content: center;
        font-size: 24px; color: #2563eb; margin-bottom: 12px;
    }
    .empty-state h5 { font-size: 14px; font-weight: 700; color: #334155; margin: 0 0 5px 0; }
    .empty-state p { font-size: 12px; color: #64748b; margin: 0; }

    @media (max-width: 768px) {
        .stats-strip { grid-template-columns: 1fr; }
        .form-card { padding: 16px; }
        .subject-pick-grid { grid-template-columns: 1fr; max-height: none; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="container" style="padding: 20px; max-width: 1200px; margin: 0 auto;">

    <div class="page-header-card">
        <div class="header-icon"><i class="fas fa-list-check"></i></div>
        <div>
            <h1>Subject-Class Assignments</h1>
            <div class="subtitle">Assign subjects to classes &mdash; exam marks are configured in the Exam module</div>
        </div>
    </div>

    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert alert-<?= htmlspecialchars($_SESSION['message_type']) ?> alert-dismissible fade show">
            <?= htmlspecialchars($_SESSION['message']) ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
        <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
    <?php endif; ?>

    <div class="stats-strip">
        <div class="stat-card blue">
            <div class="stat-icon"><i class="fas fa-chalkboard"></i></div>
            <div class="stat-body">
                <div class="stat-value"><?= count($subject_classes_by_class) ?></div>
                <div class="stat-label">Classes with Subjects</div>
            </div>
        </div>
        <div class="stat-card green">
            <div class="stat-icon"><i class="fas fa-link"></i></div>
            <div class="stat-body">
                <div class="stat-value"><?= $total_assignments ?></div>
                <div class="stat-label">Total Assignments</div>
            </div>
        </div>
        <div class="stat-card purple">
            <div class="stat-icon"><i class="fas fa-book"></i></div>
            <div class="stat-body">
                <div class="stat-value"><?= count($subjects) ?></div>
                <div class="stat-label">Available Subjects</div>
            </div>
        </div>
    </div>

    <div class="form-card <?= $edit_mode ? 'editing' : '' ?>">
        <div class="form-head">
            <h4>
                <i class="fas fa-<?= $edit_mode ? 'pen' : 'plus-circle' ?>"></i>
                <?= $edit_mode ? 'Edit Assignments for ' . htmlspecialchars($edit_class_data['title']) : 'Assign Subjects to a Class' ?>
            </h4>
            <?php if ($edit_mode): ?>
                <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn-ghost">
                    <i class="fas fa-times"></i> Cancel Edit
                </a>
            <?php endif; ?>
        </div>

        <form method="post" action="" id="assignForm">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <?php if ($edit_mode): ?>
                <input type="hidden" name="edit_class_id" value="<?= (int)$edit_class_data['id'] ?>">
            <?php endif; ?>

            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <label class="field-label" for="class_id">Select Class <span style="color:#dc2626;">*</span></label>
                        <select name="class_id" id="class_id" class="form-control" required <?= $edit_mode ? 'disabled' : '' ?>>
                            <option value="">-- Select Class --</option>
                            <?php foreach ($classes as $id => $title): ?>
                                <option value="<?= (int)$id ?>" <?= ($edit_mode && (int)$edit_class_data['id'] === (int)$id) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($title) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($edit_mode): ?>
                            <input type="hidden" name="class_id" value="<?= (int)$edit_class_data['id'] ?>">
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="form-group" id="subjects_group" style="<?= $edit_mode ? '' : 'display: none;' ?>">
                <label class="field-label">Pick Subjects for this Class</label>
                <div class="subject-pick-toolbar">
                    <button type="button" id="pick_select_all"><i class="fas fa-check-double"></i> Select All</button>
                    <button type="button" id="pick_clear_all"><i class="fas fa-times"></i> Clear All</button>
                </div>
                <div class="subject-pick-grid" id="subjects_list">
                    <?php if ($edit_mode): ?>
                        <?php foreach ($subjects as $subject_id => $subject): ?>
                            <label class="subject-pick-item">
                                <input type="checkbox" class="subject-checkbox" name="selected_subjects[]"
                                       value="<?= (int)$subject_id ?>"
                                       <?= in_array((int)$subject_id, $existing_subject_ids) ? 'checked' : '' ?>>
                                <div class="subj-info">
                                    <div class="subj-name"><?= htmlspecialchars($subject['title']) ?></div>
                                    <span class="subj-type-badge <?= $subject['type'] ?>"><?= htmlspecialchars($subject['type']) ?></span>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div id="subjects_empty" style="display:none; padding: 24px; text-align: center; color:#94a3b8; font-size: 12px;">
                    No subjects available. Add subjects first from Subject Management.
                </div>
            </div>

            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:14px;">
                <?php if ($edit_mode ? !empty($perms['can_edit']) : !empty($perms['can_add'])): ?>
                    <button type="submit" class="btn-success-brand">
                        <i class="fas fa-<?= $edit_mode ? 'save' : 'check' ?>"></i>
                        <?= $edit_mode ? 'Save Changes' : 'Assign Subjects' ?>
                    </button>
                <?php endif; ?>
                <?php if ($edit_mode): ?>
                    <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn-ghost">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                <?php else: ?>
                    <button type="reset" class="btn-ghost">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="list-card">
        <div class="list-card-head">
            <h3><i class="fas fa-list-ul"></i> Existing Assignments</h3>
            <span class="count-chip"><?= count($subject_classes_by_class) ?> class(es)</span>
        </div>

        <?php if (!empty($subject_classes_by_class)): ?>
            <?php foreach ($subject_classes_by_class as $class_id => $class_data): ?>
                <div class="class-group">
                    <div class="class-group-head">
                        <h4>
                            <span class="class-icon"><i class="fas fa-chalkboard"></i></span>
                            <?= htmlspecialchars($class_data['class_title']) ?>
                        </h4>
                        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                            <span class="count-label"><?= count($class_data['subjects']) ?> subject(s)</span>
                            <?php if (!empty($perms['can_edit'])): ?>
                                <a href="?edit_class=<?= (int)$class_id ?>" class="btn-edit-class">
                                    <i class="fas fa-pen"></i> Edit
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="chip-wrap">
                        <?php foreach ($class_data['subjects'] as $sc): ?>
                            <span class="subject-chip">
                                <span><?= htmlspecialchars($sc['subject_title']) ?></span>
                                <span class="chip-type <?= htmlspecialchars($sc['type']) ?>"><?= htmlspecialchars($sc['type']) ?></span>
                                <?php if (!empty($perms['can_delete'])): ?>
                                    <a href="?delete=<?= (int)$sc['id'] ?>" class="chip-remove" title="Remove"
                                       onclick="return confirm('Remove this subject from class?');">
                                        <i class="fas fa-times"></i>
                                    </a>
                                <?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-book"></i></div>
                <h5>No assignments yet</h5>
                <p>Assign subjects to a class using the form above.</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
$(document).ready(function() {

    setTimeout(function() { $('.alert').fadeOut('slow'); }, 5000);

    var subjectsData = <?= json_encode($subjects) ?>;
    var existingIds  = <?= json_encode($existing_subject_ids) ?>;

    function renderSubjects(preChecked) {
        var html = '';
        var count = 0;
        $.each(subjectsData, function(sid, subj) {
            count++;
            var checked = (preChecked && preChecked.indexOf(parseInt(sid, 10)) !== -1) ? 'checked' : '';
            html +=
                '<label class="subject-pick-item">' +
                    '<input type="checkbox" class="subject-checkbox" name="selected_subjects[]" value="' + sid + '" ' + checked + '>' +
                    '<div class="subj-info">' +
                        '<div class="subj-name">' + subj.title + '</div>' +
                        '<span class="subj-type-badge ' + subj.type + '">' + subj.type + '</span>' +
                    '</div>' +
                '</label>';
        });
        $('#subjects_list').html(html);
        $('#subjects_empty').toggle(count === 0);
        $('#subjects_group').show();
    }

    <?php if ($edit_mode): ?>
        renderSubjects(existingIds);
    <?php endif; ?>

    $('#class_id').on('change', function() {
        var cid = $(this).val();
        if (cid) {
            renderSubjects([]);
        } else {
            $('#subjects_group').hide();
            $('#subjects_list').html('');
        }
    });

    $('#pick_select_all').on('click', function() {
        $('#subjects_list .subject-checkbox').prop('checked', true);
    });
    $('#pick_clear_all').on('click', function() {
        $('#subjects_list .subject-checkbox').prop('checked', false);
    });
});
</script>

</body>
</html>
<?php $conn->close(); ?>