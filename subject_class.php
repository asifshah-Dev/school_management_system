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

/**
 * Handles form submission for adding or updating subject-class assignments
 */
function handleFormSubmission($conn, $post) {
    if (!isset($post['csrf_token']) || $post['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['message'] = "Security error: Invalid CSRF token.";
        $_SESSION['message_type'] = 'danger';
        return false;
    }

    $class_id = intval($post['class_id']);
    $selected_subjects = isset($post['selected_subjects']) ? array_map('intval', $post['selected_subjects']) : [];
    $marks = isset($post['marks']) ? $post['marks'] : [];
    $sub_subject_ids = isset($post['sub_subject_ids']) ? $post['sub_subject_ids'] : [];
    $sub_subject_marks = isset($post['sub_subject_marks']) ? $post['sub_subject_marks'] : [];
    $edit_class_id = isset($post['edit_class_id']) ? intval($post['edit_class_id']) : 0;

    $success = true;

    if ($edit_class_id > 0) {
        $stmt = $conn->prepare("SELECT id, subject_id FROM subject_class WHERE class_id = ?");
        $stmt->bind_param("i", $edit_class_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $existing_subject_classes = [];
        while ($row = $result->fetch_assoc()) {
            $existing_subject_classes[$row['subject_id']] = $row['id'];
        }
        $stmt->close();

        foreach ($selected_subjects as $subject_id) {
            if ($subject_id <= 0) continue;

            $stmt = $conn->prepare("SELECT type FROM subjects WHERE id = ?");
            $stmt->bind_param("i", $subject_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $subject = $result->fetch_assoc();
            $type = $subject['type'] ?? 'standalone';
            $stmt->close();

            $subject_marks = ($type === 'standalone' && isset($marks[$subject_id])) ? floatval($marks[$subject_id]) : 0;
            if ($type === 'standalone' && $subject_marks <= 0) {
                $success = false;
                continue;
            }

            if (isset($existing_subject_classes[$subject_id])) {
                $subject_class_id = $existing_subject_classes[$subject_id];
                $stmt = $conn->prepare("UPDATE subject_class SET marks = ? WHERE id = ?");
                $stmt->bind_param("di", $subject_marks, $subject_class_id);
                if (!$stmt->execute()) $success = false;
                $stmt->close();
            } else {
                $stmt = $conn->prepare("INSERT INTO subject_class (class_id, subject_id, marks) VALUES (?, ?, ?)");
                $stmt->bind_param("iid", $class_id, $subject_id, $subject_marks);
                if ($stmt->execute()) {
                    $subject_class_id = $conn->insert_id;
                } else {
                    $success = false;
                    continue;
                }
                $stmt->close();
            }

            if ($type === 'composite' && isset($sub_subject_ids[$subject_id]) && isset($sub_subject_marks[$subject_id])) {
                $stmt = $conn->prepare("SELECT id, sub_subject_id FROM subject_class_sub WHERE subject_class_id = ?");
                $stmt->bind_param("i", $subject_class_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $existing_sub_subjects = [];
                while ($row = $result->fetch_assoc()) {
                    $existing_sub_subjects[$row['sub_subject_id']] = $row['id'];
                }
                $stmt->close();

                foreach ($sub_subject_ids[$subject_id] as $sub_index => $sub_subject_id) {
                    if ($sub_subject_id > 0) {
                        $sub_marks = isset($sub_subject_marks[$subject_id][$sub_index]) ? floatval($sub_subject_marks[$subject_id][$sub_index]) : 0;
                        if ($sub_marks <= 0) continue;

                        if (isset($existing_sub_subjects[$sub_subject_id])) {
                            $subject_class_sub_id = $existing_sub_subjects[$sub_subject_id];
                            $stmt = $conn->prepare("UPDATE subject_class_sub SET marks = ? WHERE id = ?");
                            $stmt->bind_param("di", $sub_marks, $subject_class_sub_id);
                            if (!$stmt->execute()) $success = false;
                            $stmt->close();
                        } else {
                            $stmt = $conn->prepare("INSERT INTO subject_class_sub (subject_class_id, sub_subject_id, marks) VALUES (?, ?, ?)");
                            $stmt->bind_param("iid", $subject_class_id, $sub_subject_id, $sub_marks);
                            if (!$stmt->execute()) $success = false;
                            $stmt->close();
                        }
                    }
                }

                $submitted_sub_subject_ids = array_filter($sub_subject_ids[$subject_id], function($id) { return $id > 0; });
                foreach ($existing_sub_subjects as $sub_subject_id => $subject_class_sub_id) {
                    if (!in_array($sub_subject_id, $submitted_sub_subject_ids)) {
                        $stmt = $conn->prepare("DELETE FROM subject_class_sub WHERE id = ?");
                        $stmt->bind_param("i", $subject_class_sub_id);
                        if (!$stmt->execute()) $success = false;
                        $stmt->close();
                    }
                }
            } elseif ($type === 'composite') {
                $stmt = $conn->prepare("DELETE FROM subject_class_sub WHERE subject_class_id = ?");
                $stmt->bind_param("i", $subject_class_id);
                if (!$stmt->execute()) $success = false;
                $stmt->close();
            }
        }

        foreach ($existing_subject_classes as $subject_id => $subject_class_id) {
            if (!in_array($subject_id, $selected_subjects)) {
                $stmt = $conn->prepare("DELETE FROM subject_class_sub WHERE subject_class_id = ?");
                $stmt->bind_param("i", $subject_class_id);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("DELETE FROM subject_class WHERE id = ?");
                $stmt->bind_param("i", $subject_class_id);
                if (!$stmt->execute()) $success = false;
                $stmt->close();
            }
        }
    } else {
        foreach ($selected_subjects as $subject_id) {
            if ($subject_id <= 0) continue;

            $stmt = $conn->prepare("SELECT type FROM subjects WHERE id = ?");
            $stmt->bind_param("i", $subject_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $subject = $result->fetch_assoc();
            $type = $subject['type'] ?? 'standalone';
            $stmt->close();

            $subject_marks = ($type === 'standalone' && isset($marks[$subject_id])) ? floatval($marks[$subject_id]) : 0;
            if ($type === 'standalone' && $subject_marks <= 0) {
                $success = false;
                continue;
            }

            $stmt = $conn->prepare("INSERT INTO subject_class (class_id, subject_id, marks) VALUES (?, ?, ?)");
            $stmt->bind_param("iid", $class_id, $subject_id, $subject_marks);
            if ($stmt->execute()) {
                $subject_class_id = $conn->insert_id;
                if ($type === 'composite' && isset($sub_subject_ids[$subject_id]) && isset($sub_subject_marks[$subject_id])) {
                    foreach ($sub_subject_ids[$subject_id] as $sub_index => $sub_subject_id) {
                        if ($sub_subject_id > 0) {
                            $sub_marks = isset($sub_subject_marks[$subject_id][$sub_index]) ? floatval($sub_subject_marks[$subject_id][$sub_index]) : 0;
                            if ($sub_marks <= 0) continue;
                            $stmt_sub = $conn->prepare("INSERT INTO subject_class_sub (subject_class_id, sub_subject_id, marks) VALUES (?, ?, ?)");
                            $stmt_sub->bind_param("iid", $subject_class_id, $sub_subject_id, $sub_marks);
                            if (!$stmt_sub->execute()) $success = false;
                            $stmt_sub->close();
                        }
                    }
                }
            } else {
                $success = false;
            }
            $stmt->close();
        }
    }

    return $success;
}

function handleDeletion($conn, $id) {
    $stmt = $conn->prepare("DELETE FROM subject_class_sub WHERE subject_class_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM subject_class WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        $_SESSION['message'] = 'Subject-Class assignment deleted successfully!';
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = 'Error: Could not delete the assignment.';
        $_SESSION['message_type'] = 'danger';
    }
    $stmt->close();
}

function loadEditData($conn, $class_id) {
    $data = ['edit_class_data' => [], 'existing_assignments' => []];

    $stmt = $conn->prepare("SELECT id, title FROM classes WHERE id = ?");
    $stmt->bind_param("i", $class_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $data['edit_class_data'] = $result->fetch_assoc();
        if (!$data['edit_class_data']) {
            $_SESSION['message'] = "Class not found.";
            $_SESSION['message_type'] = 'danger';
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT id, class_id, subject_id, marks FROM subject_class WHERE class_id = ?");
    $stmt->bind_param("i", $class_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $data['existing_assignments'][$row['subject_id']] = $row;
            $stmt_sub = $conn->prepare("SELECT scs.sub_subject_id, ss.title, scs.marks FROM subject_class_sub scs JOIN sub_subjects ss ON scs.sub_subject_id = ss.id WHERE scs.subject_class_id = ? ORDER BY ss.title");
            $stmt_sub->bind_param("i", $row['id']);
            if ($stmt_sub->execute()) {
                $sub_result = $stmt_sub->get_result();
                $data['existing_assignments'][$row['subject_id']]['sub_subjects'] = [];
                while ($sub_row = $sub_result->fetch_assoc()) {
                    $data['existing_assignments'][$row['subject_id']]['sub_subjects'][$sub_row['sub_subject_id']] = $sub_row;
                }
            }
            $stmt_sub->close();
        }
    }
    $stmt->close();

    return $data;
}

function getClasses($conn) {
    $classes = [];
    $result = $conn->query("SELECT id, title FROM classes ORDER BY title");
    while ($row = $result->fetch_assoc()) {
        $classes[$row['id']] = $row['title'];
    }
    $result->close();
    return $classes;
}

function getSubjects($conn) {
    $subjects = [];
    $result = $conn->query("SELECT id, title, type FROM subjects ORDER BY title");
    while ($row = $result->fetch_assoc()) {
        $subjects[$row['id']] = ['title' => $row['title'], 'type' => $row['type']];
    }
    $result->close();
    return $subjects;
}

function getSubSubjects($conn) {
    $sub_subjects = [];
    $result = $conn->query("SELECT id, subject_id, title FROM sub_subjects ORDER BY subject_id, title");
    while ($row = $result->fetch_assoc()) {
        $sub_subjects[$row['subject_id']][] = $row;
    }
    $result->close();
    return $sub_subjects;
}

function getSubjectClasses($conn) {
    $subject_classes_by_class = [];
    $class_ids = [];
    $result = $conn->query("SELECT sc.id, sc.class_id, sc.subject_id, sc.marks, c.title AS class_title, s.title AS subject_title, s.type FROM subject_class sc JOIN classes c ON sc.class_id = c.id JOIN subjects s ON sc.subject_id = s.id ORDER BY c.title, s.title");
    while ($row = $result->fetch_assoc()) {
        $subject_classes_by_class[$row['class_id']]['class_title'] = $row['class_title'];
        $subject_classes_by_class[$row['class_id']]['subjects'][$row['id']] = $row;
        $class_ids[] = $row['class_id'];
    }
    $result->close();

    foreach ($subject_classes_by_class as $class_id => &$class_data) {
        foreach ($class_data['subjects'] as &$sc) {
            if ($sc['type'] === 'composite') {
                $stmt = $conn->prepare("SELECT ss.title, scs.marks FROM subject_class_sub scs JOIN sub_subjects ss ON scs.sub_subject_id = ss.id WHERE scs.subject_class_id = ? ORDER BY ss.title");
                $stmt->bind_param("i", $sc['id']);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    $sc['sub_subjects'] = [];
                    while ($row = $result->fetch_assoc()) {
                        $sc['sub_subjects'][] = $row;
                    }
                }
                $stmt->close();
            } else {
                $sc['sub_subjects'] = [];
            }
        }
    }
    unset($class_data, $sc);
    return ['subject_classes' => $subject_classes_by_class, 'class_ids' => $class_ids];
}

/* ═══════════════ Handlers ═══════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (handleFormSubmission($conn, $_POST)) {
        $_SESSION['message'] = isset($_POST['edit_class_id']) && $_POST['edit_class_id'] > 0
            ? "Subject-Class assignments updated successfully."
            : "Subject-Class assignments added successfully.";
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = $_SESSION['message'] ?? "Error: Could not process assignments.";
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
$existing_assignments = [];
if (isset($_GET['edit_class'])) {
    $edit_mode = true;
    $edit_data = loadEditData($conn, intval($_GET['edit_class']));
    $edit_class_data = $edit_data['edit_class_data'];
    $existing_assignments = $edit_data['existing_assignments'];
}

$classes = getClasses($conn);
$subjects = getSubjects($conn);
$sub_subjects = getSubSubjects($conn);
$list_data = getSubjectClasses($conn);
$subject_classes_by_class = $list_data['subject_classes'];
$class_ids = $list_data['class_ids'];
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
        border-radius: 12px; padding: 22px 26px; color: #fff;
        margin-bottom: 20px; display: flex; align-items: center; gap: 16px;
        box-shadow: 0 6px 22px rgba(37, 99, 235, 0.25);
    }
    .page-header-card .header-icon {
        width: 52px; height: 52px; background: rgba(255,255,255,0.18);
        border-radius: 12px; display: flex; align-items: center;
        justify-content: center; font-size: 24px; flex-shrink: 0;
    }
    .page-header-card h1 { font-size: 21px; font-weight: 700; margin: 0; }
    .page-header-card .subtitle { font-size: 13px; opacity: 0.85; margin-top: 4px; }

    .form-card {
        background: #fff; border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.06);
        padding: 24px; margin-bottom: 22px;
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

    .form-card label.field-label {
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

    .subjects-container {
        border: 1px solid #e2e8f0; border-radius: 10px;
        background: #f8fafc; padding: 14px;
        max-height: 560px; overflow-y: auto;
    }
    .subject-item {
        background: #fff; border: 1px solid #e2e8f0;
        border-radius: 10px; padding: 14px 16px;
        margin-bottom: 10px;
    }
    .subject-item:last-child { margin-bottom: 0; }
    .subject-item .subject-head {
        display: flex; align-items: center; justify-content: space-between;
        gap: 12px; flex-wrap: wrap;
    }
    .subject-item .subject-check {
        display: flex; align-items: center; gap: 10px;
        font-size: 14px; font-weight: 600; color: #1e293b;
        cursor: pointer; margin: 0; flex: 1; min-width: 0;
    }
    .subject-item .subject-check input[type="checkbox"] {
        width: 18px; height: 18px; accent-color: #2563eb;
        cursor: pointer; flex-shrink: 0;
    }
    .subject-item .subject-check .subj-type {
        font-size: 11px; padding: 2px 8px; border-radius: 10px;
        text-transform: uppercase; letter-spacing: 0.4px;
        font-weight: 700; margin-left: 6px;
    }
    .subject-item .subject-check .subj-type.standalone { background: #dbeafe; color: #1e40af; }
    .subject-item .subject-check .subj-type.composite { background: #ede9fe; color: #6d28d9; }

    .marks-input {
        width: 140px !important; height: 38px !important;
        padding: 6px 12px !important; font-size: 13px !important;
        border-radius: 8px !important; border: 1px solid #cbd5e1 !important;
        font-family: inherit !important;
    }
    .marks-input:focus {
        border-color: #2563eb !important;
        box-shadow: 0 0 0 3px rgba(37,99,235,0.12) !important;
    }
    .marks-input:disabled {
        background: #f1f5f9 !important; cursor: not-allowed;
    }

    .sub-subjects-container {
        margin-top: 12px; padding-top: 12px;
        border-top: 1px dashed #e2e8f0;
    }
    .sub-subject-item { margin-bottom: 8px; }
    .sub-subject-item:last-child { margin-bottom: 0; }
    .sub-subject-item .row-inputs {
        display: flex; gap: 8px; align-items: center;
    }
    .sub-subject-item .subj-title {
        flex: 1; padding: 8px 12px;
        background: #f1f5f9; border-radius: 8px;
        font-size: 13px; color: #475569; font-weight: 600;
    }

    .btn-brand {
        background: #2563eb; border: 1px solid #2563eb; color: #fff;
        font-weight: 600; font-size: 13px; padding: 9px 18px;
        border-radius: 8px; display: inline-flex; align-items: center; gap: 7px;
        text-decoration: none;
    }
    .btn-brand:hover, .btn-brand:focus { background: #1d4ed8; border-color: #1d4ed8; color: #fff; text-decoration: none; }
    .btn-success-brand {
        background: #059669; border: 1px solid #059669; color: #fff;
        font-weight: 600; font-size: 13px; padding: 9px 18px;
        border-radius: 8px; display: inline-flex; align-items: center; gap: 7px;
    }
    .btn-success-brand:hover, .btn-success-brand:focus { background: #047857; border-color: #047857; color: #fff; }
    .btn-ghost {
        background: #f1f5f9; border: 1px solid #e2e8f0; color: #475569;
        font-weight: 600; font-size: 13px; padding: 9px 16px;
        border-radius: 8px; display: inline-flex; align-items: center; gap: 7px;
        text-decoration: none;
    }
    .btn-ghost:hover, .btn-ghost:focus { background: #e2e8f0; color: #1e293b; text-decoration: none; }

    .list-card {
        background: #fff; border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.06);
        overflow: hidden; margin-bottom: 22px;
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

    /* Class group card */
    .class-group {
        border-bottom: 1px solid #e2e8f0;
    }
    .class-group:last-child { border-bottom: none; }

    .class-group-head {
        padding: 14px 20px;
        background: #eff6ff; border-bottom: 1px solid #e2e8f0;
        display: flex; align-items: center; justify-content: space-between;
        flex-wrap: wrap; gap: 10px;
    }
    .class-group-head h4 {
        font-size: 15px; font-weight: 700; color: #1e40af;
        margin: 0; display: flex; align-items: center; gap: 10px;
    }
    .class-group-head h4 .class-icon {
        width: 34px; height: 34px; background: #dbeafe;
        border-radius: 8px; display: flex; align-items: center;
        justify-content: center; font-size: 15px;
        color: #2563eb;
    }
    .class-group-head .count-label {
        font-size: 12px; color: #64748b;
        background: #fff; padding: 4px 12px;
        border-radius: 20px; font-weight: 700;
        border: 1px solid #e2e8f0;
    }
    .btn-edit-class {
        background: #f59e0b; border: 1px solid #f59e0b; color: #fff;
        font-weight: 600; font-size: 12px; padding: 7px 14px;
        border-radius: 8px; display: inline-flex; align-items: center; gap: 6px;
        text-decoration: none;
    }
    .btn-edit-class:hover, .btn-edit-class:focus { background: #d97706; border-color: #d97706; color: #fff; text-decoration: none; }

    /* Table inside a class group */
    table.sc-table { width: 100%; margin: 0; border-collapse: separate; border-spacing: 0; }
    table.sc-table thead th {
        background: #f8fafc; color: #475569;
        font-size: 11px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.4px;
        padding: 10px 20px; border-bottom: 1px solid #e2e8f0;
        white-space: nowrap;
    }
    table.sc-table tbody td {
        padding: 12px 20px; border-bottom: 1px solid #f1f5f9;
        vertical-align: middle; font-size: 13px; color: #1e293b;
    }
    table.sc-table tbody tr:hover { background: #f8fafc; }
    table.sc-table tbody tr:last-child td { border-bottom: none; }

    .subject-name { font-weight: 700; color: #1e293b; font-size: 13.5px; }
    .type-pill {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 3px 10px; border-radius: 12px;
        font-size: 11px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.4px;
    }
    .type-pill.standalone { background: #dbeafe; color: #1e40af; }
    .type-pill.composite { background: #ede9fe; color: #6d28d9; }

    .marks-badge {
        display: inline-flex; align-items: center;
        padding: 3px 10px; border-radius: 6px;
        background: #f1f5f9; color: #334155;
        font-size: 12px; font-weight: 700;
        font-family: 'SF Mono', monospace;
    }

    .sub-chips { display: flex; flex-wrap: wrap; gap: 4px; }
    .sub-chip {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 3px 9px; border-radius: 12px;
        background: #f1f5f9; color: #475569;
        font-size: 11px; font-weight: 600;
    }
    .sub-chip .marks {
        background: #fff; color: #2563eb;
        padding: 1px 6px; border-radius: 8px;
        font-weight: 700;
    }

    .icon-btn {
        width: 34px; height: 34px; border-radius: 8px;
        border: 1px solid #e2e8f0; background: #fff; color: #64748b;
        display: inline-flex; align-items: center; justify-content: center;
        transition: all 0.15s; padding: 0; font-size: 13px;
        text-decoration: none;
    }
    .icon-btn:hover { transform: translateY(-1px); text-decoration: none; }
    .icon-btn.delete:hover { background: #fef2f2; border-color: #fecaca; color: #dc2626; }

    .empty-state { padding: 55px 20px; text-align: center; }
    .empty-state .empty-icon {
        width: 72px; height: 72px; background: #eff6ff;
        border-radius: 50%; display: inline-flex;
        align-items: center; justify-content: center;
        font-size: 30px; color: #2563eb; margin-bottom: 14px;
    }
    .empty-state h5 { font-size: 15px; font-weight: 700; color: #334155; margin: 0 0 6px 0; }
    .empty-state p { font-size: 13px; color: #64748b; margin: 0; }

    @media (max-width: 768px) {
        .form-card { padding: 18px; }
        .subjects-container { max-height: none; }
        .marks-input { width: 100% !important; }
        .subject-item .subject-head { flex-direction: column; align-items: stretch; }
        .sub-subject-item .row-inputs { flex-direction: column; align-items: stretch; }
        table.sc-table thead { display: none; }
        table.sc-table, table.sc-table tbody, table.sc-table tr, table.sc-table td { display: block; width: 100%; }
        table.sc-table tr { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; }
        table.sc-table td { border: none; padding: 4px 0; display: flex; justify-content: space-between; gap: 10px; }
        table.sc-table td::before { content: attr(data-label); font-weight: 700; font-size: 11px; text-transform: uppercase; color: #64748b; }
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
            <div class="subtitle">Assign subjects to classes with marks and sub-subject configuration</div>
        </div>
    </div>

    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert alert-<?= htmlspecialchars($_SESSION['message_type']) ?> alert-dismissible fade show">
            <?= htmlspecialchars($_SESSION['message']) ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
        <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
    <?php endif; ?>

    <!-- Add/Edit Form -->
    <div class="form-card <?= $edit_mode ? 'editing' : '' ?>">
        <div class="form-head">
            <h4>
                <i class="fas fa-<?= $edit_mode ? 'pen' : 'plus-circle' ?>"></i>
                <?= $edit_mode ? 'Edit Assignments for ' . htmlspecialchars($edit_class_data['title']) : 'Add Subject-Class Assignments' ?>
            </h4>
            <?php if ($edit_mode): ?>
                <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn-ghost">
                    <i class="fas fa-times"></i> Cancel Edit
                </a>
            <?php endif; ?>
        </div>

        <form method="post" action="">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <?php if ($edit_mode): ?>
                <input type="hidden" name="edit_class_id" value="<?= (int)$edit_class_data['id'] ?>">
            <?php endif; ?>

            <div class="row">
                <div class="col-md-6">
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
                <label class="field-label">Assign Subjects and Marks</label>
                <div class="subjects-container" id="subjects_list">
                    <?php if ($edit_mode): ?>
                        <?php foreach ($subjects as $subject_id => $subject): ?>
                            <div class="subject-item" id="subject_item_<?= (int)$subject_id ?>">
                                <div class="subject-head">
                                    <label class="subject-check">
                                        <input type="checkbox" class="subject-checkbox" name="selected_subjects[]"
                                               value="<?= (int)$subject_id ?>"
                                               <?= isset($existing_assignments[$subject_id]) ? 'checked' : '' ?>>
                                        <span>
                                            <?= htmlspecialchars($subject['title']) ?>
                                            <span class="subj-type <?= $subject['type'] ?>"><?= htmlspecialchars($subject['type']) ?></span>
                                        </span>
                                    </label>
                                    <?php if ($subject['type'] === 'standalone'): ?>
                                        <input type="number" class="marks-input form-control"
                                               name="marks[<?= (int)$subject_id ?>]"
                                               value="<?= isset($existing_assignments[$subject_id]) ? htmlspecialchars($existing_assignments[$subject_id]['marks']) : '' ?>"
                                               placeholder="Marks" step="0.1" min="0"
                                               <?= isset($existing_assignments[$subject_id]) ? '' : 'disabled' ?>>
                                    <?php endif; ?>
                                </div>
                                <?php if ($subject['type'] === 'composite'): ?>
                                    <div class="sub-subjects-container">
                                        <?php if (!empty($sub_subjects[$subject_id])): ?>
                                            <?php foreach ($sub_subjects[$subject_id] as $index => $sub_subject): ?>
                                                <div class="sub-subject-item" id="sub_subject_item_<?= (int)$subject_id ?>_<?= (int)$index ?>">
                                                    <div class="row-inputs">
                                                        <div class="subj-title"><?= htmlspecialchars($sub_subject['title']) ?></div>
                                                        <input type="hidden" name="sub_subject_ids[<?= (int)$subject_id ?>][]" value="<?= (int)$sub_subject['id'] ?>">
                                                        <input type="number" class="marks-input form-control"
                                                               name="sub_subject_marks[<?= (int)$subject_id ?>][]"
                                                               value="<?= isset($existing_assignments[$subject_id]['sub_subjects'][$sub_subject['id']]) ? htmlspecialchars($existing_assignments[$subject_id]['sub_subjects'][$sub_subject['id']]['marks']) : '' ?>"
                                                               placeholder="Marks" step="0.1" min="0"
                                                               <?= isset($existing_assignments[$subject_id]) ? '' : 'disabled' ?>>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <p style="font-size:12px; color:#94a3b8; margin:0;">No sub-subjects available.</p>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:18px;">
                <?php if ($edit_mode ? !empty($perms['can_edit']) : !empty($perms['can_add'])): ?>
                    <button type="submit" class="btn-success-brand">
                        <i class="fas fa-<?= $edit_mode ? 'save' : 'check' ?>"></i>
                        <?= $edit_mode ? 'Save Changes' : 'Add Assignments' ?>
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

    <!-- List -->
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
                        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                            <span class="count-label"><?= count($class_data['subjects']) ?> subject(s)</span>
                            <?php if (!empty($perms['can_edit'])): ?>
                                <a href="?edit_class=<?= (int)$class_id ?>" class="btn-edit-class">
                                    <i class="fas fa-pen"></i> Edit Class
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="overflow-x:auto;">
                        <table class="sc-table">
                            <thead>
                                <tr>
                                    <th style="width: 50px;">#</th>
                                    <th>Subject</th>
                                    <th style="width: 130px;">Type</th>
                                    <th style="width: 130px;">Total Marks</th>
                                    <th>Sub-Subjects</th>
                                    <?php if (!empty($perms['can_delete'])): ?>
                                        <th style="width: 90px; text-align:right;">Action</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $counter = 1; foreach ($class_data['subjects'] as $sc): ?>
                                    <tr>
                                        <td data-label="#"><?= $counter++ ?></td>
                                        <td data-label="Subject">
                                            <div class="subject-name"><?= htmlspecialchars($sc['subject_title']) ?></div>
                                        </td>
                                        <td data-label="Type">
                                            <span class="type-pill <?= $sc['type'] ?>">
                                                <?= htmlspecialchars($sc['type']) ?>
                                            </span>
                                        </td>
                                        <td data-label="Marks">
                                            <?php
                                            if ($sc['type'] === 'standalone') {
                                                echo '<span class="marks-badge">' . htmlspecialchars($sc['marks']) . '</span>';
                                            } else {
                                                $total_marks = 0;
                                                foreach ($sc['sub_subjects'] as $sub) $total_marks += floatval($sub['marks']);
                                                echo $total_marks > 0
                                                    ? '<span class="marks-badge">' . htmlspecialchars($total_marks) . '</span>'
                                                    : '<span style="color:#94a3b8; font-style:italic; font-size:12px;">N/A</span>';
                                            }
                                            ?>
                                        </td>
                                        <td data-label="Sub-Subjects">
                                            <?php if ($sc['type'] === 'composite' && !empty($sc['sub_subjects'])): ?>
                                                <div class="sub-chips">
                                                    <?php foreach ($sc['sub_subjects'] as $sub): ?>
                                                        <span class="sub-chip">
                                                            <?= htmlspecialchars($sub['title']) ?>
                                                            <span class="marks"><?= htmlspecialchars($sub['marks']) ?></span>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php elseif ($sc['type'] === 'composite'): ?>
                                                <span style="color:#94a3b8; font-style:italic; font-size:12px;">No sub-subjects</span>
                                            <?php else: ?>
                                                <span style="color:#cbd5e1;">&mdash;</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php if (!empty($perms['can_delete'])): ?>
                                            <td data-label="Action" style="text-align:right;">
                                                <a href="?delete=<?= (int)$sc['id'] ?>" class="icon-btn delete" title="Delete"
                                                   onclick="return confirm('Delete this assignment?');">
                                                    <i class="fas fa-trash-alt"></i>
                                                </a>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-book"></i></div>
                <h5>No assignments yet</h5>
                <p>Add your first subject-class assignment using the form above.</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
$(document).ready(function() {

    setTimeout(function() { $('.alert').fadeOut('slow'); }, 5000);

    function toggleMarksInputs(checkbox, subjectId) {
        var subjectItem = $('#subject_item_' + subjectId);
        var inputs = subjectItem.find('input[type="number"]');
        inputs.prop('disabled', !checkbox.checked);
        if (!checkbox.checked) inputs.val('');
    }

    function updateSubjects(classId) {
        var subjectsList = $('#subjects_list');
        subjectsList.html('');

        <?php if (!empty($subjects)): ?>
            var subjects = <?= json_encode($subjects) ?>;
            var subSubjects = <?= json_encode($sub_subjects) ?>;
            var existingAssignments = <?= $edit_mode ? json_encode($existing_assignments) : '{}' ?>;

            $.each(subjects, function(subjectId, subject) {
                var isChecked = existingAssignments[subjectId] ? 'checked' : '';
                var marksValue = existingAssignments[subjectId] ? existingAssignments[subjectId].marks : '';

                var html = '<div class="subject-item" id="subject_item_' + subjectId + '">' +
                    '<div class="subject-head">' +
                        '<label class="subject-check">' +
                            '<input type="checkbox" class="subject-checkbox" name="selected_subjects[]" value="' + subjectId + '" ' + isChecked + '>' +
                            '<span>' + subject.title + ' <span class="subj-type ' + subject.type + '">' + subject.type + '</span></span>' +
                        '</label>';

                if (subject.type === 'standalone') {
                    html += '<input type="number" class="marks-input form-control" name="marks[' + subjectId + ']" placeholder="Marks" step="0.1" min="0" value="' + marksValue + '" ' + (isChecked ? '' : 'disabled') + '>';
                }
                html += '</div>';

                if (subject.type === 'composite') {
                    html += '<div class="sub-subjects-container">';
                    if (subSubjects[subjectId] && subSubjects[subjectId].length > 0) {
                        $.each(subSubjects[subjectId], function(index, subSubject) {
                            var subMarksValue = (existingAssignments[subjectId] && existingAssignments[subjectId].sub_subjects && existingAssignments[subjectId].sub_subjects[subSubject.id])
                                ? existingAssignments[subjectId].sub_subjects[subSubject.id].marks : '';
                            html += '<div class="sub-subject-item" id="sub_subject_item_' + subjectId + '_' + index + '">' +
                                    '<div class="row-inputs">' +
                                        '<div class="subj-title">' + subSubject.title + '</div>' +
                                        '<input type="hidden" name="sub_subject_ids[' + subjectId + '][]" value="' + subSubject.id + '">' +
                                        '<input type="number" class="marks-input form-control" name="sub_subject_marks[' + subjectId + '][]" placeholder="Marks" step="0.1" min="0" value="' + subMarksValue + '" ' + (isChecked ? '' : 'disabled') + '>' +
                                    '</div></div>';
                        });
                    } else {
                        html += '<p style="font-size:12px; color:#94a3b8; margin:0;">No sub-subjects available.</p>';
                    }
                    html += '</div>';
                }

                html += '</div>';
                subjectsList.append(html);
            });

            subjectsList.find('.subject-checkbox').change(function() {
                toggleMarksInputs(this, $(this).val());
            });
        <?php else: ?>
            subjectsList.html('<p style="color:#94a3b8;">No subjects available.</p>');
        <?php endif; ?>
        $('#subjects_group').show();
    }

    <?php if ($edit_mode): ?>
        updateSubjects(<?= (int)$edit_class_data['id'] ?>);
    <?php else: ?>
        $('#subjects_group').hide();
    <?php endif; ?>

    $('#class_id').change(function() {
        var classId = $(this).val();
        if (classId) {
            updateSubjects(classId);
        } else {
            $('#subjects_group').hide();
            $('#subjects_list').html('');
        }
    });
});
</script>

</body>
</html>
<?php $conn->close(); ?>