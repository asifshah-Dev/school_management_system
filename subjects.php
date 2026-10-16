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

/* Detect if is_compulsory column exists */
$has_compulsory_col = false;
$chk = $conn->query("SHOW COLUMNS FROM subjects LIKE 'is_compulsory'");
if ($chk && $chk->num_rows > 0) $has_compulsory_col = true;

$edit_mode = false;
$subject_data = [];
$sub_subjects = [];

/* ============================================================
   POST — add / update
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid CSRF token");
    }

    $subject_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $title      = trim($_POST['title'] ?? '');
    $type       = in_array($_POST['type'] ?? '', ['standalone', 'composite']) ? $_POST['type'] : 'standalone';
    $marks      = ($type === 'standalone' && isset($_POST['marks'])) ? floatval($_POST['marks']) : 0;
    $is_compulsory = isset($_POST['is_compulsory']) && $_POST['is_compulsory'] == '1' ? 1 : 0;

    $sub_subject_titles = (isset($_POST['sub_subject_titles']) && is_array($_POST['sub_subject_titles'])) ? $_POST['sub_subject_titles'] : [];
    $sub_subject_marks  = (isset($_POST['sub_subject_marks']) && is_array($_POST['sub_subject_marks'])) ? $_POST['sub_subject_marks'] : [];

    /* Server-side guard: composite must have at least one fully-filled sub-subject */
    if ($type === 'composite') {
        $has_valid_sub = false;
        foreach ($sub_subject_titles as $i => $t) {
            $t = trim($t);
            $m = isset($sub_subject_marks[$i]) ? trim((string)$sub_subject_marks[$i]) : '';
            if ($t !== '' && $m !== '' && is_numeric($m)) {
                $has_valid_sub = true;
                break;
            }
        }
        if (!$has_valid_sub) {
            $_SESSION['message'] = 'Composite subjects must have at least one sub-subject with a title and marks.';
            $_SESSION['message_type'] = 'danger';
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }

    if ($subject_id > 0) {
        /* ---------- UPDATE ---------- */
        if ($has_compulsory_col) {
            $stmt = $conn->prepare("UPDATE subjects SET title = ?, type = ?, total_marks = ?, is_compulsory = ? WHERE id = ?");
            $stmt->bind_param("ssdii", $title, $type, $marks, $is_compulsory, $subject_id);
        } else {
            $stmt = $conn->prepare("UPDATE subjects SET title = ?, type = ?, total_marks = ? WHERE id = ?");
            $stmt->bind_param("ssdi", $title, $type, $marks, $subject_id);
        }

        if ($stmt->execute()) {
            /* Refresh sub-subjects (delete + reinsert only if composite) */
            $del = $conn->prepare("DELETE FROM sub_subjects WHERE subject_id = ?");
            $del->bind_param("i", $subject_id);
            $del->execute();
            $del->close();

            if ($type === 'composite') {
                foreach ($sub_subject_titles as $index => $sub_title) {
                    $sub_title = trim($sub_title);
                    if ($sub_title === '') continue;
                    $sub_marks = isset($sub_subject_marks[$index]) ? floatval($sub_subject_marks[$index]) : 0;
                    $sub_stmt = $conn->prepare("INSERT INTO sub_subjects (subject_id, title, marks) VALUES (?, ?, ?)");
                    $sub_stmt->bind_param("isd", $subject_id, $sub_title, $sub_marks);
                    $sub_stmt->execute();
                    $sub_stmt->close();
                }
            }
            $_SESSION['message'] = "Subject updated successfully.";
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = "Error updating subject: " . $stmt->error;
            $_SESSION['message_type'] = 'danger';
        }
        $stmt->close();
    } else {
        /* ---------- INSERT ---------- */
        if ($has_compulsory_col) {
            $stmt = $conn->prepare("INSERT INTO subjects (title, type, total_marks, is_compulsory) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssdi", $title, $type, $marks, $is_compulsory);
        } else {
            $stmt = $conn->prepare("INSERT INTO subjects (title, type, total_marks) VALUES (?, ?, ?)");
            $stmt->bind_param("ssd", $title, $type, $marks);
        }

        if ($stmt->execute()) {
            $subject_id = $conn->insert_id;
            if ($type === 'composite') {
                foreach ($sub_subject_titles as $index => $sub_title) {
                    $sub_title = trim($sub_title);
                    if ($sub_title === '') continue;
                    $sub_marks = isset($sub_subject_marks[$index]) ? floatval($sub_subject_marks[$index]) : 0;
                    $sub_stmt = $conn->prepare("INSERT INTO sub_subjects (subject_id, title, marks) VALUES (?, ?, ?)");
                    $sub_stmt->bind_param("isd", $subject_id, $sub_title, $sub_marks);
                    $sub_stmt->execute();
                    $sub_stmt->close();
                }
            }
            $_SESSION['message'] = "Subject added successfully.";
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = "Error adding subject: " . $stmt->error;
            $_SESSION['message_type'] = 'danger';
        }
        $stmt->close();
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

/* ============================================================
   DELETE
   ============================================================ */
if (isset($_GET['delete']) && !empty($perms['can_delete'])) {
    $id = intval($_GET['delete']);

    $ref_q = $conn->prepare("SELECT COUNT(*) AS c FROM subject_class WHERE subject_id = ?");
    $ref_q->bind_param("i", $id);
    $ref_q->execute();
    $ref_r = $ref_q->get_result()->fetch_assoc();
    $ref_q->close();

    if ((int)$ref_r['c'] > 0) {
        $_SESSION['message'] = 'Cannot delete: this subject is assigned to ' . (int)$ref_r['c'] . ' class(es). Remove those first.';
        $_SESSION['message_type'] = 'danger';
    } else {
        $stmt = $conn->prepare("DELETE FROM sub_subjects WHERE subject_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM subjects WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $_SESSION['message'] = 'Subject deleted successfully.';
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = 'Error deleting subject: ' . $stmt->error;
            $_SESSION['message_type'] = 'danger';
        }
        $stmt->close();
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

/* ============================================================
   EDIT MODE
   ============================================================ */
if (isset($_GET['edit'])) {
    $edit_mode = true;
    $id = intval($_GET['edit']);

    if ($has_compulsory_col) {
        $stmt = $conn->prepare("SELECT id, title, type, total_marks, is_compulsory FROM subjects WHERE id = ?");
    } else {
        $stmt = $conn->prepare("SELECT id, title, type, total_marks FROM subjects WHERE id = ?");
    }
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $subject_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$subject_data) {
        $_SESSION['message'] = "Subject not found.";
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    if (!isset($subject_data['is_compulsory'])) $subject_data['is_compulsory'] = 1;

    if ($subject_data['type'] === 'composite') {
        $stmt = $conn->prepare("SELECT id, title, marks FROM sub_subjects WHERE subject_id = ? ORDER BY id");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $sub_subjects[] = $row;
        $stmt->close();
    }
}

/* ============================================================
   LOAD SUBJECT LIST
   ============================================================ */
$subjects = [];
$select_compulsory = $has_compulsory_col ? ", s.is_compulsory" : ", 1 AS is_compulsory";
$sql = "SELECT s.id, s.title, s.type, s.total_marks" . $select_compulsory . ", GROUP_CONCAT(CONCAT(ss.title, ' (', ss.marks, ')') ORDER BY ss.id SEPARATOR ', ') AS sub_subjects FROM subjects s LEFT JOIN sub_subjects ss ON s.id = ss.subject_id GROUP BY s.id ORDER BY s.title";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) $subjects[] = $row;
    $result->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Subject Management</title>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Inter', sans-serif; background: #f5f7fa; padding-bottom: 40px; }

    .page-header-card {
        background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        border-radius: 12px; padding: 22px 26px; color: #fff;
        margin-bottom: 20px; display: flex; align-items: center; gap: 16px;
        box-shadow: 0 6px 22px rgba(37, 99, 235, 0.25);
    }
    .page-header-card .header-icon {
        width: 52px; height: 52px; background: rgba(255,255,255,0.18);
        border-radius: 12px; display: flex; align-items: center; justify-content: center;
        font-size: 24px;
    }
    .page-header-card h1 { font-size: 21px; font-weight: 700; margin: 0; }
    .page-header-card .subtitle { font-size: 13px; opacity: 0.85; margin-top: 4px; }

    .form-card {
        background: #fff; border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.06);
        padding: 24px; margin-bottom: 20px;
        border-top: 4px solid #2563eb;
    }
    .form-card.editing { border-top-color: #f59e0b; background: #fffbeb; }
    .form-card .form-head {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 18px; flex-wrap: wrap; gap: 10px;
    }
    .form-card .form-head h4 {
        font-size: 16px; font-weight: 700; color: #1e293b; margin: 0;
        display: flex; align-items: center; gap: 10px;
    }
    .form-card.editing .form-head h4 { color: #b45309; }
    .form-card .form-head h4 i { color: #2563eb; }
    .form-card.editing .form-head h4 i { color: #f59e0b; }

    .form-card label {
        font-size: 12px; font-weight: 700; color: #475569;
        text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px;
    }
    .form-card .form-control {
        border-radius: 8px; border: 1px solid #cbd5e1;
        font-size: 14px; padding: 9px 12px; height: auto;
    }
    .form-card .form-control:focus {
        border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
    }

    /* ---------- RADIO PILL GROUP ---------- */
    .radio-pill-group {
        display: inline-flex;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 4px;
        gap: 4px;
    }
    .radio-pill-group label {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 8px 18px;
        border-radius: 8px;
        font-size: 13px; font-weight: 600;
        color: #64748b;
        cursor: pointer;
        margin: 0;
        text-transform: none;
        letter-spacing: 0;
        transition: all 0.15s;
        user-select: none;
        min-height: 36px;
    }
    .radio-pill-group label:hover { color: #334155; }
    .radio-pill-group input { display: none; }
    .radio-pill-group label:has(input:checked) {
        background: #2563eb;
        color: #fff;
        box-shadow: 0 2px 6px rgba(37,99,235,0.3);
    }
    .radio-pill-group label:has(input[value="0"]:checked) {
        background: #64748b;
        box-shadow: 0 2px 6px rgba(100,116,139,0.3);
    }
    .radio-pill-group label i { font-size: 12px; }

    .btn-brand {
        background: #2563eb; border: 1px solid #2563eb; color: #fff;
        font-weight: 600; font-size: 13px; padding: 9px 18px;
        border-radius: 8px; display: inline-flex; align-items: center; gap: 7px;
    }
    .btn-brand:hover, .btn-brand:focus { background: #1d4ed8; border-color: #1d4ed8; color: #fff; }
    .btn-ghost {
        background: #f1f5f9; border: 1px solid #e2e8f0; color: #475569;
        font-weight: 600; font-size: 13px; padding: 9px 16px;
        border-radius: 8px; display: inline-flex; align-items: center; gap: 7px;
    }
    .btn-ghost:hover, .btn-ghost:focus { background: #e2e8f0; color: #1e293b; }

    /* ---------- SUB-SUBJECTS ---------- */
    .sub-subj-box {
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        background: #f8fafc;
        padding: 14px;
    }
    .sub-subj-head {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 10px;
    }
    .sub-subj-head .label-text {
        font-size: 11px; font-weight: 700; color: #64748b;
        text-transform: uppercase; letter-spacing: 0.6px;
    }
    .sub-subj-head .hint {
        font-size: 11px; color: #94a3b8;
    }
    .sub-subj-rows { display: flex; flex-direction: column; gap: 8px; }
    .sub-subj-row {
        display: grid;
        grid-template-columns: 1fr 130px 40px;
        gap: 8px;
        align-items: center;
    }
    .sub-subj-row input {
        height: 38px; padding: 0 12px;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 13px; font-family: inherit; background: #fff;
        width: 100%;
    }
    .sub-subj-row input:focus {
        outline: none; border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
    }
    .sub-subj-row .remove-row {
        width: 38px; height: 38px;
        background: #fff; border: 1px solid #e2e8f0; color: #94a3b8;
        border-radius: 8px; cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 13px; transition: all 0.15s;
    }
    .sub-subj-row .remove-row:hover {
        background: #fef2f2; border-color: #fecaca; color: #dc2626;
    }
    .add-row-btn {
        display: inline-flex; align-items: center; gap: 6px;
        margin-top: 10px;
        height: 34px; padding: 0 14px;
        background: #fff; border: 1px dashed #cbd5e1; color: #2563eb;
        border-radius: 8px; font-size: 12px; font-weight: 700;
        cursor: pointer; font-family: inherit;
    }
    .add-row-btn:hover { background: #eff6ff; border-color: #93c5fd; }
    .sub-subj-empty {
        padding: 20px;
        text-align: center;
        color: #94a3b8;
        font-size: 13px;
        background: #fff;
        border: 1px dashed #cbd5e1;
        border-radius: 8px;
    }

    /* ---------- LIST ---------- */
    .list-card {
        background: #fff; border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.06); overflow: hidden;
    }
    .list-card-head {
        padding: 16px 20px; border-bottom: 1px solid #e2e8f0;
        display: flex; align-items: center; justify-content: space-between;
        flex-wrap: wrap; gap: 10px;
    }
    .list-card-head h3 {
        font-size: 15px; font-weight: 700; color: #1e293b; margin: 0;
        display: flex; align-items: center; gap: 8px;
    }
    .list-card-head h3 i { color: #2563eb; }
    .list-card-head .count-chip {
        background: #eff6ff; color: #2563eb;
        padding: 4px 12px; border-radius: 20px;
        font-size: 12px; font-weight: 700;
    }

    table.sub-table { width: 100%; margin: 0; border-collapse: separate; border-spacing: 0; }
    table.sub-table thead th {
        background: #f8fafc; color: #475569;
        font-size: 11px; font-weight: 700; text-transform: uppercase;
        letter-spacing: 0.4px; padding: 11px 14px;
        border-bottom: 1px solid #e2e8f0; white-space: nowrap;
    }
    table.sub-table tbody td {
        padding: 12px 14px; border-bottom: 1px solid #f1f5f9;
        vertical-align: middle; font-size: 13px; color: #1e293b;
    }
    table.sub-table tbody tr:hover { background: #f8fafc; }
    table.sub-table tbody tr:last-child td { border-bottom: none; }

    .subject-title { font-weight: 700; color: #1e293b; font-size: 14px; }
    .type-pill {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 3px 10px; border-radius: 12px;
        font-size: 11px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.4px;
    }
    .type-pill.standalone { background: #dbeafe; color: #1e40af; }
    .type-pill.composite { background: #ede9fe; color: #6d28d9; }

    .comp-pill {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 3px 10px; border-radius: 12px;
        font-size: 11px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.4px;
    }
    .comp-pill.yes { background: #d1fae5; color: #065f46; }
    .comp-pill.no  { background: #f1f5f9; color: #64748b; }

    .marks-badge {
        display: inline-flex; align-items: center;
        padding: 3px 10px; border-radius: 6px;
        background: #f1f5f9; color: #334155;
        font-size: 12px; font-weight: 700;
        font-family: 'SF Mono', monospace;
    }

    .sub-subj-list { font-size: 12px; color: #475569; line-height: 1.6; max-width: 320px; }
    .sub-subj-chip {
        display: inline-block; padding: 2px 8px;
        background: #f1f5f9; border-radius: 6px;
        font-size: 11px; color: #334155;
        margin: 2px 3px 2px 0;
    }
    .muted { color: #94a3b8; font-style: italic; font-size: 12px; }

    .icon-btn {
        width: 34px; height: 34px; border-radius: 8px;
        border: 1px solid #e2e8f0; background: #fff; color: #64748b;
        display: inline-flex; align-items: center; justify-content: center;
        transition: all 0.15s; padding: 0; font-size: 13px;
    }
    .icon-btn:hover { transform: translateY(-1px); }
    .icon-btn.edit:hover { background: #eff6ff; border-color: #93c5fd; color: #2563eb; }
    .icon-btn.delete:hover { background: #fef2f2; border-color: #fecaca; color: #dc2626; }
    .icon-btn + .icon-btn { margin-left: 6px; }

    .empty-state { padding: 55px 20px; text-align: center; color: #94a3b8; }
    .empty-state .big { font-size: 44px; opacity: 0.4; margin-bottom: 10px; }
    .empty-state h5 { font-size: 15px; font-weight: 700; color: #334155; margin: 0 0 6px 0; }

    @media (max-width: 768px) {
        .form-card { padding: 18px; }
        .sub-subj-row { grid-template-columns: 1fr 90px 40px; }
        table.sub-table thead { display: none; }
        table.sub-table, table.sub-table tbody, table.sub-table tr, table.sub-table td { display: block; width: 100%; }
        table.sub-table tr { margin-bottom: 14px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; padding: 12px; }
        table.sub-table td { border: none; padding: 6px 0; display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        table.sub-table td::before { content: attr(data-label); font-weight: 700; font-size: 11px; text-transform: uppercase; color: #64748b; letter-spacing: 0.4px; flex-shrink: 0; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="container" style="padding: 20px; max-width: 1200px; margin: 0 auto;">

    <div class="page-header-card">
        <div class="header-icon"><i class="fas fa-book-open"></i></div>
        <div>
            <h1>Subject Management</h1>
            <div class="subtitle">Manage subjects, sub-subjects, and compulsory / optional status</div>
        </div>
    </div>

    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert alert-<?php echo htmlspecialchars($_SESSION['message_type']); ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($_SESSION['message']); ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
        <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
    <?php endif; ?>

    <!-- Form -->
    <div class="form-card <?php echo $edit_mode ? 'editing' : ''; ?>">
        <div class="form-head">
            <h4>
                <i class="fas fa-<?php echo $edit_mode ? 'pen' : 'plus-circle'; ?>"></i>
                <?php echo $edit_mode ? 'Edit Subject' : 'Add New Subject'; ?>
            </h4>
            <?php if ($edit_mode): ?>
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn-ghost">
                    <i class="fas fa-times"></i> Cancel Edit
                </a>
            <?php endif; ?>
        </div>

        <form method="post" action="" id="subjectForm">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <?php if ($edit_mode): ?>
                <input type="hidden" name="id" value="<?php echo (int)$subject_data['id']; ?>">
            <?php endif; ?>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Subject Title <span style="color:#dc2626;">*</span></label>
                        <input type="text" class="form-control" name="title" required
                               value="<?php echo $edit_mode ? htmlspecialchars($subject_data['title']) : ''; ?>"
                               placeholder="e.g. Mathematics">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Subject Type <span style="color:#dc2626;">*</span></label>
                        <select name="type" id="subject_type" class="form-control" required>
                            <option value="standalone" <?php echo ($edit_mode && $subject_data['type'] === 'standalone') ? 'selected' : ''; ?>>Standalone</option>
                            <option value="composite"  <?php echo ($edit_mode && $subject_data['type'] === 'composite') ? 'selected' : ''; ?>>Composite (has sub-subjects)</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group" id="marks_group" style="<?php echo ($edit_mode && $subject_data['type'] !== 'standalone') ? 'display: none;' : ''; ?>">
                        <label>Total Marks <span style="color:#dc2626;">*</span></label>
                        <input type="number" class="form-control" name="marks" step="0.1" min="0"
                               value="<?php echo $edit_mode && $subject_data['type'] === 'standalone' ? htmlspecialchars($subject_data['total_marks']) : ''; ?>"
                               placeholder="e.g. 100"
                               <?php echo ($edit_mode && $subject_data['type'] !== 'standalone') ? '' : 'required'; ?>>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Compulsory / Optional</label>
                        <div class="radio-pill-group">
                            <label>
                                <input type="radio" name="is_compulsory" value="1" <?php echo (!$edit_mode || (int)($subject_data['is_compulsory'] ?? 1) === 1) ? 'checked' : ''; ?>>
                                <i class="fas fa-check-circle"></i>
                                <span>Compulsory</span>
                            </label>
                            <label>
                                <input type="radio" name="is_compulsory" value="0" <?php echo ($edit_mode && (int)($subject_data['is_compulsory'] ?? 1) === 0) ? 'checked' : ''; ?>>
                                <i class="fas fa-circle"></i>
                                <span>Optional</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-group" id="sub_subjects_group" style="<?php echo ($edit_mode && $subject_data['type'] !== 'composite') ? 'display: none;' : ''; ?>">
                <label>Sub-Subjects <span style="color:#dc2626;" id="sub_req_star" style="display:none;">*</span></label>
                <div class="sub-subj-box">
                    <div class="sub-subj-head">
                        <span class="label-text">Break this subject into parts</span>
                        <span class="hint">Title + Marks per row</span>
                    </div>
                    <div class="sub-subj-rows" id="sub_subj_rows">
                        <?php if ($edit_mode && !empty($sub_subjects)): ?>
                            <?php foreach ($sub_subjects as $idx => $sub): ?>
                                <div class="sub-subj-row">
                                    <input type="text" name="sub_subject_titles[]" value="<?php echo htmlspecialchars($sub['title']); ?>" placeholder="Sub-subject title" required>
                                    <input type="number" name="sub_subject_marks[]" step="0.1" min="0" value="<?php echo htmlspecialchars($sub['marks']); ?>" placeholder="Marks" required>
                                    <button type="button" class="remove-row" title="Remove"><i class="fas fa-times"></i></button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div id="sub_subj_empty" class="sub-subj-empty" style="<?php echo ($edit_mode && !empty($sub_subjects)) ? 'display:none;' : ''; ?>">
                        No sub-subjects yet. Click the button below to add the first one.
                    </div>
                    <button type="button" class="add-row-btn" id="add_row_btn">
                        <i class="fas fa-plus"></i> Add Sub-Subject
                    </button>
                </div>
            </div>

            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top: 18px;">
                <?php if ($edit_mode ? !empty($perms['can_edit']) : !empty($perms['can_add'])): ?>
                    <button type="submit" class="btn-brand">
                        <i class="fas fa-<?php echo $edit_mode ? 'save' : 'check'; ?>"></i>
                        <?php echo $edit_mode ? 'Save Changes' : 'Add Subject'; ?>
                    </button>
                <?php endif; ?>
                <?php if ($edit_mode): ?>
                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn-ghost">
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

    <!-- List -->
    <div class="list-card">
        <div class="list-card-head">
            <h3><i class="fas fa-list-ul"></i> All Subjects</h3>
            <span class="count-chip"><?php echo count($subjects); ?> subject(s)</span>
        </div>

        <?php if (!empty($subjects)): ?>
            <div style="overflow-x: auto;">
                <table class="sub-table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>Subject Title</th>
                            <th style="width: 130px;">Type</th>
                            <th style="width: 130px;">Status</th>
                            <th style="width: 110px;">Marks</th>
                            <th>Sub-Subjects</th>
                            <?php if (!empty($perms['can_edit']) || !empty($perms['can_delete'])): ?>
                                <th style="width: 110px; text-align: right;">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $counter = 1; foreach ($subjects as $subject):
                            $is_comp = (int)($subject['is_compulsory'] ?? 1) === 1;
                        ?>
                        <tr>
                            <td data-label="#"><?php echo $counter++; ?></td>
                            <td data-label="Subject Title">
                                <div class="subject-title"><?php echo htmlspecialchars($subject['title']); ?></div>
                            </td>
                            <td data-label="Type">
                                <?php if ($subject['type'] === 'composite'): ?>
                                    <span class="type-pill composite"><i class="fas fa-layer-group"></i> Composite</span>
                                <?php else: ?>
                                    <span class="type-pill standalone"><i class="fas fa-book"></i> Standalone</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Status">
                                <?php if ($is_comp): ?>
                                    <span class="comp-pill yes"><i class="fas fa-check-circle"></i> Compulsory</span>
                                <?php else: ?>
                                    <span class="comp-pill no"><i class="fas fa-circle"></i> Optional</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Marks">
                                <?php
                                if ($subject['type'] === 'standalone') {
                                    echo '<span class="marks-badge">' . htmlspecialchars($subject['total_marks']) . '</span>';
                                } else {
                                    $total = 0;
                                    if (!empty($subject['sub_subjects'])) {
                                        $parts = explode(', ', $subject['sub_subjects']);
                                        foreach ($parts as $p) {
                                            if (preg_match('/\((\d+\.?\d*)\)/', $p, $m)) {
                                                $total += floatval($m[1]);
                                            }
                                        }
                                    }
                                    echo $total > 0 ? '<span class="marks-badge">' . htmlspecialchars($total) . '</span>' : '<span class="muted">N/A</span>';
                                }
                                ?>
                            </td>
                            <td data-label="Sub-Subjects">
                                <?php if ($subject['type'] === 'composite' && !empty($subject['sub_subjects'])): ?>
                                    <div class="sub-subj-list">
                                        <?php
                                        $items = explode(', ', $subject['sub_subjects']);
                                        foreach ($items as $item) {
                                            echo '<span class="sub-subj-chip">' . htmlspecialchars($item) . '</span>';
                                        }
                                        ?>
                                    </div>
                                <?php elseif ($subject['type'] === 'composite'): ?>
                                    <span class="muted">No sub-subjects</span>
                                <?php else: ?>
                                    <span class="muted">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <?php if (!empty($perms['can_edit']) || !empty($perms['can_delete'])): ?>
                            <td data-label="Actions" style="text-align: right;">
                                <?php if (!empty($perms['can_edit'])): ?>
                                    <a href="?edit=<?php echo (int)$subject['id']; ?>" class="icon-btn edit" title="Edit">
                                        <i class="fas fa-pen"></i>
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($perms['can_delete'])): ?>
                                    <a href="?delete=<?php echo (int)$subject['id']; ?>" class="icon-btn delete" title="Delete"
                                       onclick="return confirm('Delete this subject? This cannot be undone.');">
                                        <i class="fas fa-trash-alt"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <div class="big"><i class="fas fa-book-open"></i></div>
                <h5>No subjects yet</h5>
                <p>Add your first subject using the form above.</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
$(document).ready(function() {

    /* Auto-dismiss alerts */
    setTimeout(function() { $('.alert').fadeOut('slow'); }, 5000);

    /* ---------- Sub-subject row management ---------- */
    function toggleEmpty() {
        var count = $('#sub_subj_rows .sub-subj-row').length;
        $('#sub_subj_empty').toggle(count === 0);
    }

    $('#add_row_btn').on('click', function() {
        var isComposite = $('#subject_type').val() === 'composite';
        var req = isComposite ? 'required' : '';

        var html =
            '<div class="sub-subj-row">' +
                '<input type="text" name="sub_subject_titles[]" placeholder="Sub-subject title" ' + req + '>' +
                '<input type="number" name="sub_subject_marks[]" step="0.1" min="0" placeholder="Marks" ' + req + '>' +
                '<button type="button" class="remove-row" title="Remove"><i class="fas fa-times"></i></button>' +
            '</div>';
        $('#sub_subj_rows').append(html);
        toggleEmpty();
        $('#sub_subj_rows .sub-subj-row:last-child input:first').focus();
    });

    $(document).on('click', '.sub-subj-row .remove-row', function() {
        $(this).closest('.sub-subj-row').remove();
        toggleEmpty();
    });

    toggleEmpty();

    /* ---------- Toggle between standalone and composite ---------- */
    function applyTypeRules() {
        var type = $('#subject_type').val();

        if (type === 'composite') {
            $('#sub_subjects_group').slideDown(150);
            $('#marks_group').slideUp(150).find('input').removeAttr('required');

            /* If no rows exist yet, add one automatically */
            if ($('#sub_subj_rows .sub-subj-row').length === 0) {
                $('#add_row_btn').trigger('click');
            }

            /* Require every sub-subject row */
            $('#sub_subj_rows .sub-subj-row input').prop('required', true);
            $('#sub_req_star').show();
        } else {
            $('#sub_subjects_group').slideUp(150);
            $('#marks_group').slideDown(150).find('input').attr('required', 'required');

            /* Remove required from sub-subject inputs so they can't block submit */
            $('#sub_subj_rows .sub-subj-row input').prop('required', false);
            $('#sub_req_star').hide();
        }
    }

    $('#subject_type').on('change', applyTypeRules);
    applyTypeRules();

    /* ---------- Ensure unchecked radio still submits a value ---------- */
    $('#subjectForm').on('submit', function() {
        if (!$('input[name="is_compulsory"]:checked').length) {
            $('input[name="is_compulsory"][value="1"]').prop('checked', true);
        }
    });
});
</script>
</body>
</html>
<?php $conn->close(); ?>