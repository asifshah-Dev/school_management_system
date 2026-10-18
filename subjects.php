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

/* Detect is_compulsory column */
$has_compulsory_col = false;
$chk = $conn->query("SHOW COLUMNS FROM subjects LIKE 'is_compulsory'");
if ($chk && $chk->num_rows > 0) $has_compulsory_col = true;

$edit_mode = false;
$subject_data = [];
$sub_subjects = [];

/* ============================================================
   ASSIGN-TO-CLASSES AJAX ENDPOINT
   ============================================================ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'assign_classes') {
    header('Content-Type: application/json');
    ob_clean();

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $subject_id = isset($_POST['subject_id']) ? intval($_POST['subject_id']) : 0;
    if ($subject_id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid subject']);
        exit;
    }

    $class_ids = isset($_POST['class_ids']) && is_array($_POST['class_ids']) ? array_map('intval', $_POST['class_ids']) : [];

    /* Current ACTIVE assignments only */
    $current = [];
    $q = $conn->prepare("SELECT class_id FROM subject_class WHERE subject_id = ? AND status = 0");
    $q->bind_param("i", $subject_id);
    $q->execute();
    $r = $q->get_result();
    while ($row = $r->fetch_assoc()) $current[] = (int)$row['class_id'];
    $q->close();

    $to_add    = array_diff($class_ids, $current);
    $to_remove = array_diff($current, $class_ids);

    $ok = true;

    /* Soft-delete unassigned */
    if (!empty($to_remove)) {
        $upd = $conn->prepare("UPDATE subject_class SET status = 1 WHERE class_id = ? AND subject_id = ? AND status = 0");
        foreach ($to_remove as $cid) {
            $cid = (int)$cid;
            $upd->bind_param("ii", $cid, $subject_id);
            if (!$upd->execute()) $ok = false;
        }
        $upd->close();
    }

    /* Reactivate existing soft-deleted, or insert new */
    if (!empty($to_add)) {
        foreach ($to_add as $cid) {
            $cid = (int)$cid;

            /* Is there a soft-deleted row? */
            $chk2 = $conn->prepare("SELECT id FROM subject_class WHERE class_id = ? AND subject_id = ? AND status = 1 LIMIT 1");
            $chk2->bind_param("ii", $cid, $subject_id);
            $chk2->execute();
            $chk2->store_result();

            if ($chk2->num_rows > 0) {
                $chk2->bind_result($existing_id);
                $chk2->fetch();
                $chk2->close();

                $react = $conn->prepare("UPDATE subject_class SET status = 0 WHERE id = ?");
                $react->bind_param("i", $existing_id);
                if (!$react->execute()) $ok = false;
                $react->close();
            } else {
                $chk2->close();
                $ins = $conn->prepare("INSERT INTO subject_class (class_id, subject_id, marks, status) VALUES (?, ?, 0, 0)");
                $ins->bind_param("ii", $cid, $subject_id);
                if (!$ins->execute()) $ok = false;
                $ins->close();
            }
        }
    }

    echo json_encode([
        'ok'     => $ok,
        'added'  => count($to_add),
        'removed'=> count($to_remove)
    ]);
    exit;
}

/* ============================================================
   POST — add / update
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['ajax'])) {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid CSRF token");
    }

    $subject_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $title      = trim($_POST['title'] ?? '');
    $type       = in_array($_POST['type'] ?? '', ['standalone', 'composite']) ? $_POST['type'] : 'standalone';
    $is_compulsory = isset($_POST['is_compulsory']) && $_POST['is_compulsory'] == '1' ? 1 : 0;

    $sub_subject_titles = (isset($_POST['sub_subject_titles']) && is_array($_POST['sub_subject_titles'])) ? $_POST['sub_subject_titles'] : [];
    $sub_subject_marks  = (isset($_POST['sub_subject_marks']) && is_array($_POST['sub_subject_marks'])) ? $_POST['sub_subject_marks'] : [];

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
        if ($has_compulsory_col) {
            $stmt = $conn->prepare("UPDATE subjects SET title = ?, type = ?, is_compulsory = ? WHERE id = ?");
            $stmt->bind_param("ssii", $title, $type, $is_compulsory, $subject_id);
        } else {
            $stmt = $conn->prepare("UPDATE subjects SET title = ?, type = ? WHERE id = ?");
            $stmt->bind_param("ssi", $title, $type, $subject_id);
        }

        if ($stmt->execute()) {
            /* Soft-delete old sub-subjects, then insert fresh */
            $del = $conn->prepare("UPDATE sub_subjects SET status = 1 WHERE subject_id = ? AND status = 0");
            $del->bind_param("i", $subject_id);
            $del->execute();
            $del->close();

            if ($type === 'composite') {
                foreach ($sub_subject_titles as $index => $sub_title) {
                    $sub_title = trim($sub_title);
                    if ($sub_title === '') continue;
                    $sub_marks = isset($sub_subject_marks[$index]) ? floatval($sub_subject_marks[$index]) : 0;
                    $sub_stmt = $conn->prepare("INSERT INTO sub_subjects (subject_id, title, marks, status) VALUES (?, ?, ?, 0)");
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
        if ($has_compulsory_col) {
            $stmt = $conn->prepare("INSERT INTO subjects (title, type, total_marks, is_compulsory, status) VALUES (?, ?, 0, ?, 0)");
            $stmt->bind_param("ssi", $title, $type, $is_compulsory);
        } else {
            $stmt = $conn->prepare("INSERT INTO subjects (title, type, total_marks, status) VALUES (?, ?, 0, 0)");
            $stmt->bind_param("ss", $title, $type);
        }

        if ($stmt->execute()) {
            $subject_id = $conn->insert_id;
            if ($type === 'composite') {
                foreach ($sub_subject_titles as $index => $sub_title) {
                    $sub_title = trim($sub_title);
                    if ($sub_title === '') continue;
                    $sub_marks = isset($sub_subject_marks[$index]) ? floatval($sub_subject_marks[$index]) : 0;
                    $sub_stmt = $conn->prepare("INSERT INTO sub_subjects (subject_id, title, marks, status) VALUES (?, ?, ?, 0)");
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
   DELETE = SOFT DELETE (status = 1)
   ============================================================ */
if (isset($_GET['delete']) && !empty($perms['can_delete'])) {
    $id = intval($_GET['delete']);

    $ref_q = $conn->prepare("SELECT COUNT(*) AS c FROM subject_class WHERE subject_id = ? AND status = 0");
    $ref_q->bind_param("i", $id);
    $ref_q->execute();
    $ref_r = $ref_q->get_result()->fetch_assoc();
    $ref_q->close();

    if ((int)$ref_r['c'] > 0) {
        $_SESSION['message'] = 'Cannot delete: this subject is assigned to ' . (int)$ref_r['c'] . ' class(es). Unassign it first.';
        $_SESSION['message_type'] = 'danger';
    } else {
        /* Soft-delete sub-subjects */
        $stmt = $conn->prepare("UPDATE sub_subjects SET status = 1 WHERE subject_id = ? AND status = 0");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        /* Soft-delete subject */
        $stmt = $conn->prepare("UPDATE subjects SET status = 1 WHERE id = ?");
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
        $stmt = $conn->prepare("SELECT id, title, type, is_compulsory FROM subjects WHERE id = ? AND status = 0");
    } else {
        $stmt = $conn->prepare("SELECT id, title, type FROM subjects WHERE id = ? AND status = 0");
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
        $stmt = $conn->prepare("SELECT id, title, marks FROM sub_subjects WHERE subject_id = ? AND status = 0 ORDER BY id");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $sub_subjects[] = $row;
        $stmt->close();
    }
}

/* ============================================================
   LOAD SUBJECT LIST (active only) + class count (active only)
   ============================================================ */
$subjects = [];
$select_compulsory = $has_compulsory_col ? ", s.is_compulsory" : ", 1 AS is_compulsory";
$sql = "SELECT s.id, s.title, s.type" . $select_compulsory . ", GROUP_CONCAT(CONCAT(ss.title, ' (', ss.marks, ')') ORDER BY ss.id SEPARATOR ', ') AS sub_subjects, (SELECT COUNT(*) FROM subject_class sc WHERE sc.subject_id = s.id AND sc.status = 0) AS class_count FROM subjects s LEFT JOIN sub_subjects ss ON s.id = ss.subject_id AND ss.status = 0 WHERE s.status = 0 GROUP BY s.id ORDER BY s.title";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) $subjects[] = $row;
    $result->close();
}

/* Load all active classes for assign modal */
$all_classes = [];
$cq = $conn->query("SELECT id, title FROM classes ORDER BY title");
if ($cq) {
    while ($row = $cq->fetch_assoc()) $all_classes[] = $row;
    $cq->close();
}

/* Load ACTIVE class assignments for modal pre-check */
$assignments_map = [];
$aq = $conn->query("SELECT subject_id, class_id FROM subject_class WHERE status = 0");
if ($aq) {
    while ($row = $aq->fetch_assoc()) {
        $sid = (int)$row['subject_id'];
        if (!isset($assignments_map[$sid])) $assignments_map[$sid] = [];
        $assignments_map[$sid][] = (int)$row['class_id'];
    }
    $aq->close();
}

/* Stats */
$total_subjects   = count($subjects);
$compulsory_count = 0;
$optional_count   = 0;
$composite_count  = 0;
$assigned_count   = 0;
foreach ($subjects as $s) {
    if ((int)($s['is_compulsory'] ?? 1) === 1) $compulsory_count++; else $optional_count++;
    if ($s['type'] === 'composite') $composite_count++;
    if ((int)$s['class_count'] > 0) $assigned_count++;
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
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Inter', sans-serif; background: #f5f7fa; padding-bottom: 40px; }

    .page-header-card {
        background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        border-radius: 14px; padding: 20px 24px; color: #fff;
        margin-bottom: 18px; display: flex; align-items: center; gap: 14px;
        box-shadow: 0 8px 28px rgba(37, 99, 235, 0.28);
    }
    .page-header-card .header-icon {
        width: 48px; height: 48px; background: rgba(255,255,255,0.18);
        border-radius: 12px; display: flex; align-items: center; justify-content: center;
        font-size: 22px; flex-shrink: 0;
    }
    .page-header-card h1 { font-size: 19px; font-weight: 700; margin: 0; }
    .page-header-card .subtitle { font-size: 12px; opacity: 0.88; margin-top: 3px; }

    .stats-strip {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 10px;
        margin-bottom: 16px;
    }
    .stat-card {
        background: #fff; border-radius: 10px; padding: 12px 14px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        display: flex; align-items: center; gap: 12px;
        border-left: 3px solid #2563eb;
    }
    .stat-card .stat-icon {
        width: 36px; height: 36px; border-radius: 9px;
        display: flex; align-items: center; justify-content: center;
        font-size: 15px; flex-shrink: 0;
    }
    .stat-card .stat-body .stat-value { font-size: 18px; font-weight: 800; color: #1e293b; line-height: 1; }
    .stat-card .stat-body .stat-label { font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 3px; }
    .stat-card.blue { border-left-color: #2563eb; }
    .stat-card.blue .stat-icon { background: #eff6ff; color: #2563eb; }
    .stat-card.green { border-left-color: #059669; }
    .stat-card.green .stat-icon { background: #d1fae5; color: #059669; }
    .stat-card.gray { border-left-color: #64748b; }
    .stat-card.gray .stat-icon { background: #f1f5f9; color: #64748b; }
    .stat-card.purple { border-left-color: #7c3aed; }
    .stat-card.purple .stat-icon { background: #ede9fe; color: #7c3aed; }

    .form-card {
        background: #fff; border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.06);
        padding: 20px 22px; margin-bottom: 18px;
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
    .form-card .form-control,
    .form-card .form-control-sm {
        border-radius: 7px; border: 1px solid #cbd5e1;
        font-size: 13px; padding: 6px 10px; height: 36px;
        line-height: 1.4; color: #1e293b;
        transition: border-color 0.15s, box-shadow 0.15s;
    }
    .form-card select.form-control {
        height: 36px; padding: 5px 10px;
        background-position: right 8px center;
    }
    .form-card .form-control:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
    }
    .form-card .form-group { margin-bottom: 14px; }
    .form-card .row > [class*="col-"] { padding-left: 8px; padding-right: 8px; }
    .form-card .row { margin-left: -8px; margin-right: -8px; }

    .radio-pill-group {
        display: inline-flex; background: #f1f5f9;
        border: 1px solid #e2e8f0; border-radius: 8px;
        padding: 3px; gap: 3px;
    }
    .radio-pill-group label {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 6px 14px; border-radius: 6px;
        font-size: 12px; font-weight: 600; color: #64748b;
        cursor: pointer; margin: 0;
        text-transform: none; letter-spacing: 0;
        transition: all 0.15s; user-select: none; min-height: 30px;
    }
    .radio-pill-group label:hover { color: #334155; }
    .radio-pill-group input { display: none; }
    .radio-pill-group label:has(input:checked) {
        background: #2563eb; color: #fff;
        box-shadow: 0 1px 4px rgba(37,99,235,0.3);
    }
    .radio-pill-group label:has(input[value="0"]:checked) {
        background: #64748b;
        box-shadow: 0 1px 4px rgba(100,116,139,0.3);
    }
    .radio-pill-group label i { font-size: 11px; }

    .btn-brand {
        background: #2563eb; border: 1px solid #2563eb; color: #fff;
        font-weight: 600; font-size: 12.5px; padding: 8px 16px;
        border-radius: 7px; display: inline-flex; align-items: center; gap: 6px;
        text-decoration: none; height: 36px;
    }
    .btn-brand:hover, .btn-brand:focus { background: #1d4ed8; border-color: #1d4ed8; color: #fff; text-decoration: none; }
    .btn-ghost {
        background: #f1f5f9; border: 1px solid #e2e8f0; color: #475569;
        font-weight: 600; font-size: 12.5px; padding: 8px 14px;
        border-radius: 7px; display: inline-flex; align-items: center; gap: 6px;
        text-decoration: none; height: 36px;
    }
    .btn-ghost:hover, .btn-ghost:focus { background: #e2e8f0; color: #1e293b; text-decoration: none; }

    .sub-subj-box {
        border: 1px solid #e2e8f0; border-radius: 9px;
        background: #f8fafc; padding: 12px;
    }
    .sub-subj-head {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 8px;
    }
    .sub-subj-head .label-text {
        font-size: 10.5px; font-weight: 700; color: #64748b;
        text-transform: uppercase; letter-spacing: 0.6px;
    }
    .sub-subj-head .hint { font-size: 10.5px; color: #94a3b8; }
    .sub-subj-rows { display: flex; flex-direction: column; gap: 6px; }
    .sub-subj-row {
        display: grid; grid-template-columns: 1fr 100px 32px;
        gap: 6px; align-items: center;
    }
    .sub-subj-row input {
        height: 32px; padding: 0 10px;
        border: 1px solid #cbd5e1; border-radius: 7px;
        font-size: 12.5px; font-family: inherit; background: #fff; width: 100%;
        color: #1e293b;
    }
    .sub-subj-row input:focus {
        outline: none; border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
    }
    .sub-subj-row .remove-row {
        width: 32px; height: 32px; background: #fff;
        border: 1px solid #e2e8f0; color: #94a3b8;
        border-radius: 7px; cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 11px; transition: all 0.15s;
    }
    .sub-subj-row .remove-row:hover {
        background: #fef2f2; border-color: #fecaca; color: #dc2626;
    }
    .add-row-btn {
        display: inline-flex; align-items: center; gap: 5px;
        margin-top: 8px; height: 30px; padding: 0 12px;
        background: #fff; border: 1px dashed #cbd5e1; color: #2563eb;
        border-radius: 7px; font-size: 11.5px; font-weight: 700;
        cursor: pointer; font-family: inherit;
    }
    .add-row-btn:hover { background: #eff6ff; border-color: #93c5fd; }
    .sub-subj-empty {
        padding: 16px; text-align: center; color: #94a3b8;
        font-size: 12px; background: #fff;
        border: 1px dashed #cbd5e1; border-radius: 7px;
    }

    .list-card {
        background: #fff; border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.06); overflow: hidden;
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

    .toolbar {
        padding: 12px 20px; border-bottom: 1px solid #e2e8f0;
        display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        background: #fafbfc;
    }
    .toolbar .search-box {
        position: relative; flex: 1; min-width: 200px; max-width: 300px;
    }
    .toolbar .search-box i {
        position: absolute; left: 12px; top: 50%;
        transform: translateY(-50%); color: #94a3b8; font-size: 12px;
    }
    .toolbar .search-box input {
        width: 100%; height: 34px; padding: 0 12px 0 32px;
        border: 1px solid #cbd5e1; border-radius: 7px;
        font-size: 12.5px; font-family: inherit;
    }
    .toolbar .search-box input:focus {
        outline: none; border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
    }
    .filter-tabs {
        display: inline-flex; background: #fff;
        border: 1px solid #e2e8f0; border-radius: 8px;
        padding: 2px; gap: 2px;
    }
    .filter-tabs button {
        border: none; background: transparent;
        padding: 6px 12px; border-radius: 6px;
        font-size: 11.5px; font-weight: 700; color: #64748b;
        cursor: pointer; font-family: inherit;
        transition: all 0.15s;
    }
    .filter-tabs button:hover { color: #1e293b; }
    .filter-tabs button.active {
        background: #2563eb; color: #fff;
        box-shadow: 0 1px 4px rgba(37,99,235,0.3);
    }
    .toolbar .count-inline {
        margin-left: auto; font-size: 11.5px; color: #64748b; font-weight: 600;
    }

    table.sub-table { width: 100%; margin: 0; border-collapse: separate; border-spacing: 0; }
    table.sub-table thead th {
        background: #f8fafc; color: #475569;
        font-size: 10.5px; font-weight: 700; text-transform: uppercase;
        letter-spacing: 0.4px; padding: 10px 14px;
        border-bottom: 1px solid #e2e8f0; white-space: nowrap;
    }
    table.sub-table tbody td {
        padding: 12px 14px; border-bottom: 1px solid #f1f5f9;
        vertical-align: middle; font-size: 12.5px; color: #1e293b;
    }
    table.sub-table tbody tr:hover { background: #f8fafc; }
    table.sub-table tbody tr:last-child td { border-bottom: none; }

    .subject-title { font-weight: 700; color: #1e293b; font-size: 13px; }
    .type-pill {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 3px 9px; border-radius: 12px;
        font-size: 10.5px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.4px;
    }
    .type-pill.standalone { background: #dbeafe; color: #1e40af; }
    .type-pill.composite { background: #ede9fe; color: #6d28d9; }

    .comp-pill {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 3px 9px; border-radius: 12px;
        font-size: 10.5px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.4px;
    }
    .comp-pill.yes { background: #d1fae5; color: #065f46; }
    .comp-pill.no  { background: #f1f5f9; color: #64748b; }

    .class-count-pill {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 3px 10px; border-radius: 20px;
        font-size: 11.5px; font-weight: 700;
        background: #eff6ff; color: #2563eb;
    }
    .class-count-pill.zero { background: #f1f5f9; color: #94a3b8; }

    .sub-subj-list { font-size: 11.5px; color: #475569; line-height: 1.6; max-width: 320px; }
    .sub-subj-chip {
        display: inline-block; padding: 2px 7px;
        background: #f1f5f9; border-radius: 6px;
        font-size: 10.5px; color: #334155;
        margin: 2px 3px 2px 0;
    }
    .muted { color: #94a3b8; font-style: italic; font-size: 11.5px; }

    .icon-btn {
        width: 30px; height: 30px; border-radius: 7px;
        border: 1px solid #e2e8f0; background: #fff; color: #64748b;
        display: inline-flex; align-items: center; justify-content: center;
        transition: all 0.15s; padding: 0; font-size: 12px;
        text-decoration: none;
    }
    .icon-btn:hover { transform: translateY(-1px); text-decoration: none; }
    .icon-btn.edit:hover { background: #eff6ff; border-color: #93c5fd; color: #2563eb; }
    .icon-btn.delete:hover { background: #fef2f2; border-color: #fecaca; color: #dc2626; }
    .icon-btn.assign:hover { background: #f0fdf4; border-color: #86efac; color: #059669; }
    .icon-btn + .icon-btn { margin-left: 5px; }

    .empty-state { padding: 45px 20px; text-align: center; color: #94a3b8; }
    .empty-state .big { font-size: 38px; opacity: 0.4; margin-bottom: 8px; }
    .empty-state h5 { font-size: 14px; font-weight: 700; color: #334155; margin: 0 0 5px 0; }

    #assignModal .modal-content { border: none; border-radius: 12px; overflow: hidden; }
    #assignModal .modal-header {
        background: linear-gradient(135deg, #059669 0%, #047857 100%);
        color: #fff; border: none; padding: 16px 20px;
    }
    #assignModal .modal-header h5 { font-weight: 700; font-size: 15px; margin: 0; display: flex; align-items: center; gap: 8px; }
    #assignModal .modal-header .subj-label { font-size: 11.5px; opacity: 0.9; margin-top: 3px; }
    #assignModal .modal-header .close { color: #fff; opacity: 0.8; text-shadow: none; font-size: 22px; padding: 0; margin: 0; }
    #assignModal .modal-header .close:hover { opacity: 1; }
    #assignModal .modal-body { padding: 16px 20px; max-height: 60vh; overflow-y: auto; }
    #assignModal .modal-footer { background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 12px 20px; }

    .class-pick-grid {
        display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px;
    }
    .class-pick-item {
        display: flex; align-items: center; gap: 9px;
        padding: 9px 12px; background: #fff;
        border: 1.5px solid #e2e8f0; border-radius: 8px;
        cursor: pointer; transition: all 0.15s; user-select: none;
    }
    .class-pick-item:hover { border-color: #93c5fd; background: #f8fafc; }
    .class-pick-item input[type="checkbox"] {
        width: 16px; height: 16px; accent-color: #059669;
        cursor: pointer; flex-shrink: 0; margin: 0;
    }
    .class-pick-item span { font-size: 12.5px; font-weight: 600; color: #1e293b; }
    .class-pick-item:has(input:checked) {
        border-color: #059669; background: #f0fdf4;
    }
    .class-pick-item:has(input:checked) span { color: #065f46; }

    .assign-toolbar {
        display: flex; gap: 6px; margin-bottom: 12px; flex-wrap: wrap;
    }
    .assign-toolbar button {
        background: #f1f5f9; border: 1px solid #e2e8f0;
        color: #475569; padding: 5px 10px; border-radius: 7px;
        font-size: 11.5px; font-weight: 700; cursor: pointer;
        font-family: inherit; transition: all 0.15s;
    }
    .assign-toolbar button:hover { background: #e2e8f0; color: #1e293b; }

    @media (max-width: 768px) {
        .stats-strip { grid-template-columns: repeat(2, 1fr); }
        .form-card { padding: 16px; }
        .sub-subj-row { grid-template-columns: 1fr 80px 32px; }
        .class-pick-grid { grid-template-columns: 1fr; }
        table.sub-table thead { display: none; }
        table.sub-table, table.sub-table tbody, table.sub-table tr, table.sub-table td { display: block; width: 100%; }
        table.sub-table tr { margin-bottom: 14px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; padding: 12px; }
        table.sub-table td { border: none; padding: 6px 0; display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        table.sub-table td::before { content: attr(data-label); font-weight: 700; font-size: 10.5px; text-transform: uppercase; color: #64748b; letter-spacing: 0.4px; flex-shrink: 0; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="container" style="padding: 20px; max-width: 1280px; margin: 0 auto;">

    <div class="page-header-card">
        <div class="header-icon"><i class="fas fa-book-open"></i></div>
        <div>
            <h1>Subject Management</h1>
            <div class="subtitle">Manage subjects, sub-subjects, and assign to classes &mdash; exam marks are configured separately</div>
        </div>
    </div>

    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert alert-<?php echo htmlspecialchars($_SESSION['message_type']); ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($_SESSION['message']); ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
        <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
    <?php endif; ?>

    <div class="stats-strip">
        <div class="stat-card blue">
            <div class="stat-icon"><i class="fas fa-book"></i></div>
            <div class="stat-body">
                <div class="stat-value"><?= $total_subjects ?></div>
                <div class="stat-label">Total Subjects</div>
            </div>
        </div>
        <div class="stat-card green">
            <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-body">
                <div class="stat-value"><?= $compulsory_count ?></div>
                <div class="stat-label">Compulsory</div>
            </div>
        </div>
        <div class="stat-card gray">
            <div class="stat-icon"><i class="fas fa-circle"></i></div>
            <div class="stat-body">
                <div class="stat-value"><?= $optional_count ?></div>
                <div class="stat-label">Optional</div>
            </div>
        </div>
        <div class="stat-card purple">
            <div class="stat-icon"><i class="fas fa-chalkboard-teacher"></i></div>
            <div class="stat-body">
                <div class="stat-value"><?= $assigned_count ?></div>
                <div class="stat-label">Assigned to Classes</div>
            </div>
        </div>
    </div>

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
                <div class="col-md-5">
                    <div class="form-group">
                        <label class="field-label">Subject Title <span style="color:#dc2626;">*</span></label>
                        <input type="text" class="form-control" name="title" required
                               value="<?php echo $edit_mode ? htmlspecialchars($subject_data['title']) : ''; ?>"
                               placeholder="e.g. Mathematics">
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label class="field-label">Type <span style="color:#dc2626;">*</span></label>
                        <select name="type" id="subject_type" class="form-control" required>
                            <option value="standalone" <?php echo ($edit_mode && $subject_data['type'] === 'standalone') ? 'selected' : ''; ?>>Standalone</option>
                            <option value="composite"  <?php echo ($edit_mode && $subject_data['type'] === 'composite') ? 'selected' : ''; ?>>Composite</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="field-label">Compulsory / Optional</label>
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
                <label class="field-label">Sub-Subjects</label>
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

            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top: 14px;">
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

    <div class="list-card">
        <div class="list-card-head">
            <h3><i class="fas fa-list-ul"></i> All Subjects</h3>
            <span class="count-chip"><?php echo count($subjects); ?> subject(s)</span>
        </div>

        <div class="toolbar">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="subject_search" placeholder="Search subjects...">
            </div>
            <div class="filter-tabs" id="filter_tabs">
                <button type="button" class="active" data-filter="all">All</button>
                <button type="button" data-filter="compulsory">Compulsory</button>
                <button type="button" data-filter="optional">Optional</button>
                <button type="button" data-filter="composite">Composite</button>
                <button type="button" data-filter="unassigned">Unassigned</button>
            </div>
            <div class="count-inline" id="visible_count"><?= count($subjects) ?> shown</div>
        </div>

        <?php if (!empty($subjects)): ?>
            <div style="overflow-x: auto;">
                <table class="sub-table" id="subjects_table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>Subject Title</th>
                            <th style="width: 120px;">Type</th>
                            <th style="width: 120px;">Status</th>
                            <th style="width: 130px;">Classes</th>
                            <th>Sub-Subjects</th>
                            <th style="width: 130px; text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $counter = 1; foreach ($subjects as $subject):
                            $is_comp = (int)($subject['is_compulsory'] ?? 1) === 1;
                            $class_count = (int)$subject['class_count'];
                        ?>
                        <tr data-subject-id="<?= (int)$subject['id'] ?>"
                            data-compulsory="<?= $is_comp ? '1' : '0' ?>"
                            data-type="<?= htmlspecialchars($subject['type']) ?>"
                            data-assigned="<?= $class_count > 0 ? '1' : '0' ?>"
                            data-title="<?= htmlspecialchars(mb_strtolower($subject['title'])) ?>">
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
                            <td data-label="Classes">
                                <?php if ($class_count > 0): ?>
                                    <span class="class-count-pill"><i class="fas fa-chalkboard"></i> <?= $class_count ?> class<?= $class_count > 1 ? 'es' : '' ?></span>
                                <?php else: ?>
                                    <span class="class-count-pill zero"><i class="fas fa-minus"></i> None</span>
                                <?php endif; ?>
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
                            <td data-label="Actions" style="text-align: right; white-space: nowrap;">
                                <button type="button" class="icon-btn assign btn-assign"
                                        title="Assign to Classes"
                                        data-subject-id="<?= (int)$subject['id'] ?>"
                                        data-subject-title="<?= htmlspecialchars($subject['title']) ?>">
                                    <i class="fas fa-chalkboard-teacher"></i>
                                </button>
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
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div id="no_match_msg" style="display:none; padding: 32px 20px; text-align:center; color:#94a3b8;">
                <i class="fas fa-search" style="font-size: 26px; opacity: 0.4; display: block; margin-bottom: 8px;"></i>
                No subjects match your search or filter.
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

<div class="modal fade" id="assignModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5><i class="fas fa-chalkboard-teacher"></i> Assign Subject to Classes</h5>
                    <div class="subj-label" id="assign_subj_label"></div>
                </div>
                <button type="button" class="close" id="assign_close_x">&times;</button>
            </div>
            <div class="modal-body">
                <div class="assign-toolbar">
                    <button type="button" id="assign_select_all"><i class="fas fa-check-double"></i> Select All</button>
                    <button type="button" id="assign_clear_all"><i class="fas fa-times"></i> Clear All</button>
                </div>
                <div class="class-pick-grid" id="class_pick_grid">
                    <?php foreach ($all_classes as $cls): ?>
                        <label class="class-pick-item">
                            <input type="checkbox" class="assign-class-cb" value="<?= (int)$cls['id'] ?>">
                            <span><?= htmlspecialchars($cls['title']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php if (empty($all_classes)): ?>
                    <p style="text-align:center; color:#94a3b8; padding:20px;">No classes found.</p>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-ghost" id="assign_cancel">Cancel</button>
                <button type="button" class="btn-brand" id="assign_save" style="background:#059669; border-color:#059669;">
                    <i class="fas fa-save"></i> Save Assignments
                </button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {

    var CSRF = '<?= $_SESSION['csrf_token'] ?>';
    var ASSIGNMENTS = <?= json_encode($assignments_map) ?>;

    setTimeout(function() { $('.alert').fadeOut('slow'); }, 5000);

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

    function applyTypeRules() {
        var type = $('#subject_type').val();
        if (type === 'composite') {
            $('#sub_subjects_group').slideDown(150);
            if ($('#sub_subj_rows .sub-subj-row').length === 0) {
                $('#add_row_btn').trigger('click');
            }
            $('#sub_subj_rows .sub-subj-row input').prop('required', true);
        } else {
            $('#sub_subjects_group').slideUp(150);
            $('#sub_subj_rows .sub-subj-row input').prop('required', false);
        }
    }
    $('#subject_type').on('change', applyTypeRules);
    applyTypeRules();

    $('#subjectForm').on('submit', function() {
        if (!$('input[name="is_compulsory"]:checked').length) {
            $('input[name="is_compulsory"][value="1"]').prop('checked', true);
        }
    });

    function applyFilter() {
        var q = $('#subject_search').val().toLowerCase().trim();
        var filter = $('#filter_tabs button.active').data('filter');
        var visible = 0;

        $('#subjects_table tbody tr').each(function() {
            var $tr = $(this);
            var title = $tr.data('title');
            var comp = String($tr.data('compulsory'));
            var type = String($tr.data('type'));
            var assigned = String($tr.data('assigned'));

            var matchSearch = (q === '' || title.indexOf(q) !== -1);
            var matchFilter = true;
            if (filter === 'compulsory') matchFilter = comp === '1';
            else if (filter === 'optional') matchFilter = comp === '0';
            else if (filter === 'composite') matchFilter = type === 'composite';
            else if (filter === 'unassigned') matchFilter = assigned === '0';

            if (matchSearch && matchFilter) {
                $tr.show();
                visible++;
            } else {
                $tr.hide();
            }
        });

        $('#visible_count').text(visible + ' shown');
        $('#no_match_msg').toggle(visible === 0 && $('#subjects_table tbody tr').length > 0);
    }

    $('#subject_search').on('input', applyFilter);
    $('#filter_tabs button').on('click', function() {
        $('#filter_tabs button').removeClass('active');
        $(this).addClass('active');
        applyFilter();
    });

    var currentSubjectId = 0;

    $(document).on('click', '.btn-assign', function() {
        currentSubjectId = $(this).data('subject-id');
        var title = $(this).data('subject-title');
        $('#assign_subj_label').text('Subject: ' + title);

        var assigned = ASSIGNMENTS[currentSubjectId] || [];
        $('#class_pick_grid .assign-class-cb').each(function() {
            var cid = parseInt($(this).val(), 10);
            $(this).prop('checked', assigned.indexOf(cid) !== -1);
        });

        $('#assignModal').modal('show');
    });

    $('#assign_close_x, #assign_cancel').on('click', function() {
        $('#assignModal').modal('hide');
    });

    $('#assign_select_all').on('click', function() {
        $('#class_pick_grid .assign-class-cb').prop('checked', true);
    });
    $('#assign_clear_all').on('click', function() {
        $('#class_pick_grid .assign-class-cb').prop('checked', false);
    });

    $('#assign_save').on('click', function() {
        var $btn = $(this);
        var classIds = [];
        $('#class_pick_grid .assign-class-cb:checked').each(function() {
            classIds.push(parseInt($(this).val(), 10));
        });

        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');

        $.ajax({
            url: '?ajax=assign_classes',
            method: 'POST',
            dataType: 'json',
            data: {
                csrf_token: CSRF,
                subject_id: currentSubjectId,
                class_ids: classIds
            }
        }).done(function(res) {
            if (res && res.ok) {
                ASSIGNMENTS[currentSubjectId] = classIds;

                var $row = $('tr[data-subject-id="' + currentSubjectId + '"]');
                var newCount = classIds.length;
                var pill = newCount > 0
                    ? '<span class="class-count-pill"><i class="fas fa-chalkboard"></i> ' + newCount + ' class' + (newCount > 1 ? 'es' : '') + '</span>'
                    : '<span class="class-count-pill zero"><i class="fas fa-minus"></i> None</span>';
                $row.find('td[data-label="Classes"]').html(pill);
                $row.attr('data-assigned', newCount > 0 ? '1' : '0');

                $('#assignModal').modal('hide');
                applyFilter();

                var msg = 'Saved: ' + (res.added || 0) + ' added, ' + (res.removed || 0) + ' removed.';
                var $alert = $('<div class="alert alert-success alert-dismissible fade show" style="position:fixed; top:20px; right:20px; z-index:9999; min-width:260px;"></div>')
                    .html(msg + '<button type="button" class="close" data-dismiss="alert">&times;</button>');
                $('body').append($alert);
                setTimeout(function() { $alert.fadeOut(400, function() { $(this).remove(); }); }, 3000);
            } else {
                alert('Save failed: ' + (res && res.error ? res.error : 'Unknown error'));
            }
        }).fail(function(xhr, status, err) {
            alert('Network error: ' + status + ' ' + err);
        }).always(function() {
            $btn.prop('disabled', false).html('<i class="fas fa-save"></i> Save Assignments');
        });
    });

});
</script>
</body>
</html>
<?php $conn->close(); ?>