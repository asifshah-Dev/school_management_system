<?php
ob_start();
require_once('security.php');
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Generate CSRF token if not set
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once('conn_inc.php');

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Initialize variables
$exam_id = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : 0;
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$exam_type_id = isset($_GET['exam_type_id']) ? intval($_GET['exam_type_id']) : 0;
$sel_section_id = isset($_GET['section_id']) ? intval($_GET['section_id']) : 0;
$section_id = isset($_POST['section_id']) ? intval($_POST['section_id']) : 0;
$subject_id = isset($_POST['subject_id']) ? intval($_POST['subject_id']) : (isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0);
$session_id = 0;
$students = [];
$show_students = false;
$messages = [];
$selection_text = '';
$subject_title = '';
$max_marks = 100; // Default for main subject
$sub_subjects = []; // Store sub-subjects for the selected subject
$has_any_results = false; // Track if any student has results

// Fetch current session
$stmt = $conn->query("SELECT id, title FROM sessions order by id desc  LIMIT 1");
if ($stmt->num_rows > 0) {
    $row = $stmt->fetch_assoc();
    $session_id = $row['id'];
    $session_title = $row['title'];
} else {
    $stmt = $conn->query("SELECT id, title FROM sessions ORDER BY title DESC LIMIT 1");
    if ($stmt->num_rows > 0) {
        $row = $stmt->fetch_assoc();
        $session_id = $row['id'];
        $session_title = $row['title'];
    } else {
        $messages[] = ['text' => 'No sessions available.', 'type' => 'warning'];
    }
}
$stmt->close();

// Validate and fetch exam type and class
$exam_type_title = '';
$class_title = '';
if ($exam_type_id > 0) {
    $stmt = $conn->prepare("SELECT title FROM exam_types WHERE id = ?");
    $stmt->bind_param("i", $exam_type_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $exam_type_title = $row['title'];
    } else {
        $messages[] = ['text' => 'Invalid or unavailable exam type.', 'type' => 'danger'];
    }
    $stmt->close();
}

if ($class_id > 0) {
    $stmt = $conn->prepare("SELECT c.title as class_title,s.title as section_title FROM classes c 
    left join class_sections cs on cs.class_id = c.id
    left join sections s on cs.section_id = s.id
    WHERE c.id = ?");
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $class_title = $row['class_title'] . ' ' . $row['section_title'];
    } else {
        $messages[] = ['text' => 'Invalid or unavailable class.', 'type' => 'danger'];
    }
    $stmt->close();
}

// Validate exam_id
if ($exam_id > 0) {
    $stmt = $conn->prepare("SELECT id FROM arrange_exam WHERE id = ? AND exam_type_id = ? AND class_id = ?");
    $stmt->bind_param("iii", $exam_id, $exam_type_id, $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows == 0) {
        $messages[] = ['text' => 'Invalid exam configuration.', 'type' => 'danger'];
    }
    $stmt->close();
}

// Set selection text
if ($session_id && $exam_type_title && $class_title) {
    $selection_text = "$session_title - $exam_type_title - $class_title";
} elseif (!$messages) {
    $messages[] = ['text' => 'Please ensure valid session, exam type, and class are provided.', 'type' => 'danger'];
}

// Fetch subjects for the selected class
$subjects = [];
if ($class_id > 0) {
    $stmt = $conn->prepare("
        SELECT sc.id as subject_class_id, sc.subject_id, s.title, sc.marks
        FROM subject_class sc
        JOIN subjects s ON sc.subject_id = s.id
        WHERE sc.class_id = ?
        ORDER BY s.title
    ");
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $subjects[$row['subject_id']] = [
            'subject_class_id' => $row['subject_class_id'],
            'title' => $row['title'],
            'marks' => $row['marks']
        ];
    }
    $stmt->close();
    if (empty($subjects)) {
        $messages[] = ['text' => 'No subjects available for this class.', 'type' => 'warning'];
    }
}

// Fetch subject title and max marks if subject_id is set
$subject_class_id = 0;
if ($subject_id > 0) {
    $stmt = $conn->prepare("
        SELECT s.title, sc.marks, sc.id as subject_class_id
        FROM subjects s
        JOIN subject_class sc ON s.id = sc.subject_id
        WHERE s.id = ? AND sc.class_id = ?
    ");
    $stmt->bind_param("ii", $subject_id, $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $subject_title = $row['title'];
        $max_marks = $row['marks'];
        $subject_class_id = $row['subject_class_id'];
    } else {
        $messages[] = ['text' => 'Invalid or unavailable subject.', 'type' => 'danger'];
    }
    $stmt->close();
}

// Fetch sub-subjects for the selected subject
if ($subject_class_id > 0) {
    $stmt = $conn->prepare("
        SELECT scs.id as subject_class_sub_id, scs.sub_subject_id, ss.title, scs.marks
        FROM subject_class_sub scs
        JOIN sub_subjects ss ON scs.sub_subject_id = ss.id
        WHERE scs.subject_class_id = ?
        ORDER BY ss.title
    ");
    $stmt->bind_param("i", $subject_class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $sub_subjects[$row['sub_subject_id']] = [
            'subject_class_sub_id' => $row['subject_class_sub_id'],
            'title' => $row['title'],
            'marks' => $row['marks']
        ];
    }
    $stmt->close();
}

// Handle form submission for saving results
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_results'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['message'] = "Invalid CSRF token.";
        $_SESSION['message_type'] = 'danger';
        $_SESSION['show_alert'] = true;
        header("Location: " . $_SERVER['PHP_SELF'] . "?exam_id=$exam_id&class_id=$class_id&exam_type_id=$exam_type_id&section_id=$section_id&subject_id=$subject_id");
        exit();
    }

    $marks = isset($_POST['marks']) ? $_POST['marks'] : [];
    $statuses = isset($_POST['student_status']) ? $_POST['student_status'] : []; // Single status per student
    $invalid_marks = [];
    $conn->begin_transaction();
    try {
        foreach ($marks as $student_id => $subjects_data) {
            $student_id = intval($student_id);
            $status = isset($statuses[$student_id]) ? intval($statuses[$student_id]) : 0; // Single status per student
            
            if ($status == 1) { // If student is absent, set all marks to 0
                if (empty($sub_subjects)) {
                    $subjects_data[0] = 0; // Set main subject marks to 0
                } else {
                    foreach ($sub_subjects as $sub_subject_id => $sub_data) {
                        $subjects_data[$sub_subject_id] = 0; // Set all sub-subject marks to 0
                    }
                }
            }

            if (empty($sub_subjects)) {
                // Validate and save main subject marks and status
                if (isset($subjects_data[0])) {
                    $obtained_marks = $status == 1 ? 0 : floatval($subjects_data[0]); // Force 0 if absent
                    if ($status == 0 && ($obtained_marks < 0 || $obtained_marks > $max_marks)) {
                        $invalid_marks[] = "Marks for student ID $student_id ($obtained_marks) exceed max ($max_marks) or are negative.";
                        continue;
                    }
                    
                    $stmt = $conn->prepare("SELECT id FROM results WHERE student_id = ? AND arrange_exam_id = ? AND subject_class_id = ? AND subject_class_sub_id = 0");
                    $stmt->bind_param("iii", $student_id, $exam_id, $subject_class_id);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    if ($result->num_rows > 0) {
                        $stmt = $conn->prepare("UPDATE results SET marks = ?, status = ? WHERE student_id = ? AND arrange_exam_id = ? AND subject_class_id = ? AND subject_class_sub_id = 0");
                        $stmt->bind_param("diiii", $obtained_marks, $status, $student_id, $exam_id, $subject_class_id);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO results (student_id, arrange_exam_id, subject_class_id, subject_class_sub_id, marks, status) VALUES (?, ?, ?, 0, ?, ?)");
                        $stmt->bind_param("iiidi", $student_id, $exam_id, $subject_class_id, $obtained_marks, $status);
                    }
                    $stmt->execute();
                }
            } else {
                // Validate and save sub-subject marks with single student status
                foreach ($sub_subjects as $sub_subject_id => $sub_data) {
                    if (isset($subjects_data[$sub_subject_id])) {
                        $obtained_marks = $status == 1 ? 0 : floatval($subjects_data[$sub_subject_id]); // Force 0 if absent
                        if ($status == 0 && ($obtained_marks < 0 || $obtained_marks > $sub_data['marks'])) {
                            $invalid_marks[] = "Marks for student ID $student_id, sub-subject {$sub_data['title']} ($obtained_marks) exceed max ({$sub_data['marks']}) or are negative.";
                            continue;
                        }
                        
                        $subject_class_sub_id = $sub_data['subject_class_sub_id'];
                        $stmt = $conn->prepare("SELECT id FROM results WHERE student_id = ? AND arrange_exam_id = ? AND subject_class_id = ? AND subject_class_sub_id = ?");
                        $stmt->bind_param("iiii", $student_id, $exam_id, $subject_class_id, $subject_class_sub_id);
                        $stmt->execute();
                        $result = $stmt->get_result();

                        if ($result->num_rows > 0) {
                            $stmt = $conn->prepare("UPDATE results SET marks = ?, status = ? WHERE student_id = ? AND arrange_exam_id = ? AND subject_class_id = ? AND subject_class_sub_id = ?");
                            $stmt->bind_param("diiiii", $obtained_marks, $status, $student_id, $exam_id, $subject_class_id, $subject_class_sub_id);
                        } else {
                            $stmt = $conn->prepare("INSERT INTO results (student_id, arrange_exam_id, subject_class_id, subject_class_sub_id, marks, status) VALUES (?, ?, ?, ?, ?, ?)");
                            $stmt->bind_param("iiiidi", $student_id, $exam_id, $subject_class_id, $subject_class_sub_id, $obtained_marks, $status);
                        }
                        $stmt->execute();
                    }
                }
            }
        }
        
        if (!empty($invalid_marks)) {
            $conn->rollback();
            $_SESSION['message'] = "Invalid marks detected: " . implode("; ", $invalid_marks);
            $_SESSION['message_type'] = 'danger';
            $_SESSION['show_alert'] = true;
            header("Location: " . $_SERVER['PHP_SELF'] . "?exam_id=$exam_id&class_id=$class_id&exam_type_id=$exam_type_id&subject_id=$subject_id");
            exit();
        }
        $conn->commit();
        $_SESSION['message'] = "Results saved successfully!";
        $_SESSION['message_type'] = 'success';
        $_SESSION['show_alert'] = true;
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['message'] = "Error: " . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
        $_SESSION['show_alert'] = true;
        error_log("SQL Error: " . $e->getMessage(), 3, 'errors.log');
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "?exam_id=$exam_id&class_id=$class_id&exam_type_id=$exam_type_id&section_id=$section_id&subject_id=$subject_id");
    exit();
}

// Handle delete marks request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_marks'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['message'] = "Invalid CSRF token.";
        $_SESSION['message_type'] = 'danger';
        $_SESSION['show_alert'] = true;
        header("Location: " . $_SERVER['PHP_SELF'] . "?exam_id=$exam_id&class_id=$class_id&exam_type_id=$exam_type_id&section_id=$section_id&subject_id=$subject_id");
        exit();
    }

    $student_id = intval($_POST['student_id']);

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("DELETE FROM results WHERE student_id = ? AND arrange_exam_id = ? AND subject_class_id = ?");
        $stmt->bind_param("iii", $student_id, $exam_id, $subject_class_id);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            $conn->commit();
            $_SESSION['message'] = "All marks deleted successfully for student ID $student_id";
            $_SESSION['message_type'] = 'success';
            $_SESSION['show_alert'] = true;
        } else {
            $conn->rollback();
            $_SESSION['message'] = "No marks found to delete for student ID $student_id";
            $_SESSION['message_type'] = 'warning';
            $_SESSION['show_alert'] = true;
        }
        $stmt->close();
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['message'] = "Error deleting marks for student ID $student_id: " . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
        $_SESSION['show_alert'] = true;
        error_log("SQL Error: student_id=$student_id, error=" . $e->getMessage(), 3, 'errors.log');
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "?exam_id=$exam_id&class_id=$class_id&exam_type_id=$exam_type_id&section_id=$section_id&subject_id=$subject_id");
    exit();
}

// Function to fetch students
function fetchStudents($conn, $class_id, $session_id, $exam_id, $subject_class_id, $sub_subjects) {
    global $has_any_results;
    $students = [];
    $stmt = $conn->prepare("
        SELECT sr.id, sr.name, c.title as class_title, s.title as section_title
        FROM student_registration sr
        JOIN student_class sc ON sr.id = sc.student_registration_id
        LEFT JOIN classes c ON sc.class_id = c.id
        LEFT JOIN class_sections cs on cs.class_id = c.id
        LEFT JOIN sections s on cs.section_id = s.id
        WHERE c.id = ? AND sc.session_id = ? AND sr.status = 0 AND sc.status = 0
        ORDER BY sr.name
    ");
    $stmt->bind_param("ii", $class_id, $session_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        // Get student status from any result record for this student/exam/subject
        $row['status'] = 0; // Default to Present
        $row['marks'] = null;
        $row['sub_marks'] = [];
        
        // Fetch main subject status and marks (if no sub-subjects)
        if (empty($sub_subjects)) {
            $stmt2 = $conn->prepare("SELECT marks, status FROM results WHERE student_id = ? AND arrange_exam_id = ? AND subject_class_id = ? AND subject_class_sub_id = 0 ORDER BY marks DESC");
            $stmt2->bind_param("iii", $row['id'], $exam_id, $subject_class_id);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            if ($res2->num_rows > 0) {
                $res_row = $res2->fetch_assoc();
                $row['marks'] = $res_row['marks'];
                $row['status'] = $res_row['status']; // Single status for student
                $has_any_results = true;
            }
            $stmt2->close();
        } else {
            // For sub-subjects, get status from any sub-subject record
            $stmt2 = $conn->prepare("SELECT status FROM results WHERE student_id = ? AND arrange_exam_id = ? AND subject_class_id = ? LIMIT 1");
            $stmt2->bind_param("iii", $row['id'], $exam_id, $subject_class_id);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            if ($res2->num_rows > 0) {
                $res_row = $res2->fetch_assoc();
                $row['status'] = $res_row['status']; // Single status for student
                $has_any_results = true;
            }
            $stmt2->close();
            
            // Fetch sub-subject marks
            foreach ($sub_subjects as $sub_subject_id => $sub_data) {
                $subject_class_sub_id = $sub_data['subject_class_sub_id'];
                $stmt2 = $conn->prepare("SELECT marks FROM results WHERE student_id = ? AND arrange_exam_id = ? AND subject_class_id = ? AND subject_class_sub_id = ? ORDER BY marks DESC");
                $stmt2->bind_param("iiii", $row['id'], $exam_id, $subject_class_id, $subject_class_sub_id);
                $stmt2->execute();
                $res2 = $stmt2->get_result();
                if ($res2->num_rows > 0) {
                    $res_row = $res2->fetch_assoc();
                    $row['sub_marks'][$sub_subject_id] = $res_row['marks'];
                    $has_any_results = true;
                } else {
                    $row['sub_marks'][$sub_subject_id] = null;
                }
                $stmt2->close();
            }
        }
        
        $row['has_results'] = !is_null($row['marks']) || !empty(array_filter($row['sub_marks']));
        $students[] = $row;
    }
    $stmt->close();
    return $students;
}

// Handle View button
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['view_students'])) {
    $subject_id = intval($_POST['subject_id']);
    $session_id = intval($_POST['session_id']);
    $exam_id = intval($_POST['exam_id']);
    $class_id = intval($_POST['class_id']); 
    $exam_type_id = intval($_POST['exam_type_id']);

    header("Location: result_entry.php?exam_id={$exam_id}&class_id={$class_id}&exam_type_id={$exam_type_id}&subject_id={$subject_id}");
}

// Auto-load students if $sel_section_id and $subject_id are present in GET
if ($class_id > 0 && $session_id > 0 && $subject_id > 0) {
    // Validate section belongs to class
    $stmt = $conn->prepare("SELECT id FROM classes WHERE id = ?");
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows == 0) {
        $messages[] = ['text' => 'Invalid section for the selected class.', 'type' => 'danger'];
    } else {
        $students = fetchStudents($conn, $class_id, $session_id, $exam_id, $subject_class_id, $sub_subjects);
        $show_students = !empty($students);
        if (!$show_students) {
            $messages[] = ['text' => 'No students found for the selected section and subject.', 'type' => 'warning'];
        }
        $section_id = $sel_section_id;
    }
    $stmt->close();
}
$sections = [];
if ($class_id > 0) {
    $stmt = $conn->prepare("
        SELECT s.id AS section_id, s.title AS section_title, c.id AS class_id, c.title AS class_title
        FROM classes c
        LEFT JOIN class_sections cs ON cs.class_id = c.id
        LEFT JOIN sections s ON s.id = cs.section_id
        WHERE c.id = ?
        ORDER BY c.title
    ");
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $sections[$row['class_id']][] = $row['class_title'] . ' ' . $row['section_title'];
    }
    $stmt->close();

    if (empty($sections)) {
        $messages[] = ['text' => 'No sections available for this class.', 'type' => 'warning'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Result Entry</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
    .table th,
    .table td {
        text-align: left;
        vertical-align: middle;
    }

    .alert {
        margin-bottom: 20px;
    }

    .select2-container {
        width: 100% !important;
    }

    .select2-container--disabled .select2-selection {
        background-color: #f5f5f5;
    }

    .marks-input {
        width: 80px;
    }

    .action-btn {
        margin: 0 5px;
    }

    .popup-alert {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 1050;
        min-width: 300px;
    }

    /* Mobile-specific styles for screens smaller than 768px */
    @media (max-width: 768px) {
        body {
            padding: 15px;
            font-size: 1.1rem;
            line-height: 1.6;
            background-color: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: #2c3e50;
        }

        .container {
            max-width: 100%;
            padding: 20px;
            margin: 0 auto;
            background: #ffffff;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            border-radius: 10px;
        }

        h1,
        .panel-title {
            font-size: 1.8rem;
            text-align: center;
            margin-bottom: 20px;
            color: #2c3e50;
        }

        h2 {
            font-size: 1.4rem;
            margin-bottom: 15px;
        }

        /* Card view for table */
        .table-responsive {
            overflow-x: hidden;
            -webkit-overflow-scrolling: touch;
            margin-bottom: 20px;
        }

        .table {
            width: 100%;
            background: transparent;
        }

        thead {
            display: none;
        }

        tbody {
            display: block;
        }

        tr {
            display: block;
            margin-bottom: 20px;
            background: #ffffff;
            border: 1px solid #dfe4ea;
            border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
            padding: 15px;
        }

        td {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 15px;
            border-bottom: 1px solid #eceff1;
            font-size: 0.95rem;
            color: #2c3e50;
        }

        td:last-child {
            border-bottom: none;
        }

        td::before {
            content: attr(data-label);
            font-weight: 600;
            font-size: 1rem;
            color: #1a252f;
            flex: 0 0 45%;
            text-align: left;
        }

        td>*:not(:first-child) {
            margin-left: 11px;
        }

        /* Marks display, inputs, and buttons */
        .marks-display {
            font-size: 0.95rem;
            color: #2c3e50;
        }

        .marks-input {
            width: 100%;
            padding: 10px;
            font-size: 1rem;
            border: 1px solid #ced4da;
            border-radius: 6px;
            background-color: #f9fbfc;
        }

        .action-btn {
            display: inline-block;
            width: 100%;
            padding: 10px;
            font-size: 1rem;
            border-radius: 6px;
            margin: 5px 0;
            text-align: center;
            transition: background-color 0.2s ease-in-out;
        }

        .btn-warning {
            background-color: #f39c12;
            color: #ffffff;
        }

        .btn-warning:hover {
            background-color: #e67e22;
        }

        .btn-danger {
            background-color: #e74c3c;
            color: #ffffff;
        }

        .btn-danger:hover {
            background-color: #c0392b;
        }

        /* Alert styling */
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            font-size: 1rem;
            border-radius: 8px;
            line-height: 1.5;
            background-color: #ffffff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-warning {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }

        .select2-container .select2-selection {
            font-size: 1rem;
            padding: 8px;
            border-radius: 6px;
            border: 1px solid #ced4da;
            background-color: #f9fbfc;
        }

        .popup-alert {
            min-width: 85%;
            right: 15px;
            top: 15px;
            padding: 15px;
            font-size: 1rem;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }
    }

    .status-label {
        display: inline-block;
        padding: 6px 12px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .status-present {
        background-color: #007bff94;
        color: white;
        box-shadow: 0 2px 4px rgba(40, 167, 69, 0.3);
    }

    .status-absent {
        background-color: #dc3545;
        color: white;
        box-shadow: 0 2px 4px rgba(220, 53, 69, 0.3);
    }

    .status-label:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        transition: all 0.3s ease;
    }

    /* Extra small screens (mobiles < 576px) */
    @media (max-width: 576px) {
        body {
            padding: 10px;
            font-size: 1rem;
        }

        .container {
            padding: 15px;
        }

        h1,
        .panel-title {
            font-size: 1.6rem;
        }

        h2 {
            font-size: 1.3rem;
        }

        tr {
            margin-bottom: 15px;
            padding: 12px;
        }

        td {
            font-size: 0.9rem;
            padding: 8px 12px;
        }

        td::before {
            font-size: 0.95rem;
            flex: 0 0 50%;
        }

        .marks-input {
            font-size: 0.95rem;
            padding: 8px;
        }

        .action-btn {
            font-size: 0.95rem;
            padding: 8px;
        }

        .alert {
            font-size: 0.95rem;
            padding: 12px;
        }

        .select2-container .select2-selection {
            font-size: 0.95rem;
            padding: 7px;
            height: 40px;
        }

        .popup-alert {
            min-width: 90%;
            right: 10px;
            top: 10px;
        }

    }
    </style>
</head>

<body>
    <?php require_once('navbar.php'); ?>
    <div class="container">
        <div class="row">
            <div class="col-md-12 ">
                <!-- Messages for Empty Data -->
                <?php if (!empty($messages)): ?>
                <?php foreach ($messages as $msg): ?>
                <div class="alert alert-<?php echo $msg['type']; ?>">
                    <?php echo $msg['text']; ?>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <!-- Session Message -->
                <?php if (isset($_SESSION['message'])): ?>
                <div class="alert alert-<?php echo $_SESSION['message_type']; ?>">
                    <?php echo $_SESSION['message']; ?>
                    <?php if (isset($_SESSION['show_alert']) && $_SESSION['show_alert']): ?>
                    <script>
                    $(document).ready(function() {
                        showPopupAlert('<?php echo addslashes($_SESSION['message']); ?>',
                            '<?php echo $_SESSION['message_type']; ?>');
                    });
                    </script>
                    <?php endif; ?>
                    <?php unset($_SESSION['message'], $_SESSION['message_type'], $_SESSION['show_alert']); ?>
                </div>
                <?php endif; ?>

                <!-- Selection Panel -->
                <div class="panel panel-primary">
                    <div class="panel-heading">
                        <h3 class="panel-title">Result Entry</h3>
                    </div>
                    <div class="panel-body">
                        <form method="post" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
                            <input type="hidden" name="exam_type_id" value="<?php echo $exam_type_id; ?>">
                            <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
                            <input type="hidden" name="exam_id" value="<?php echo $exam_id; ?>">

                            <div class="form-group">
                                <label>Session, Exam Type, and Class:</label>
                                <select class="form-control select2" disabled>
                                    <option><?php echo htmlspecialchars($selection_text); ?></option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Section:</label>
                                <select name="class_id" id="section_id" class="form-control select2" required
                                    <?php echo empty($sections) ? 'disabled' : ''; ?>>
                                    <option value="">-- Select Section --</option>
                                    <?php foreach ($sections as $classId => $sectionTitles): ?>
                                    <?php foreach ($sectionTitles as $title): ?>
                                    <option value="<?php echo $classId; ?>"
                                        <?php echo $classId == $classId ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($title); ?>
                                    </option>
                                    <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Subject:</label>
                                <select name="subject_id" id="subject_id" class="form-control select2" required
                                    <?php echo empty($subjects) ? 'disabled' : ''; ?>>
                                    <option value="">-- Select Subject --</option>
                                    <?php foreach ($subjects as $id => $data): ?>
                                    <option value="<?php echo $id; ?>"
                                        <?php echo $subject_id == $id ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($data['title']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="text-right">
                                <button type="submit" name="view_students" class="btn btn-primary"
                                    <?php echo empty($selection_text) || empty($sections) || empty($subjects) ? 'disabled' : ''; ?>>View</button>
                                <?php if ($show_students): ?>

                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Student List Panel -->
                <?php if ($show_students): ?>
                <div class="panel panel-info">
                    <div class="panel-heading clearfix">
                        <h3 class="panel-title pull-left" id="awardListHeading">
                            Students - <?php echo htmlspecialchars($selection_text  . ' - ' . $subject_title); ?>
                        </h3>
                        <button type="button" class="btn btn-info btn-sm pull-right float-end"
                            onclick="printAwardList()">Print Award List</button>
                    </div>

                    <div class="panel-body">
                        <form method="post" action="" id="resultsForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
                            <input type="hidden" name="exam_type_id" value="<?php echo $exam_type_id; ?>">
                            <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
                            <input type="hidden" name="exam_id" value="<?php echo $exam_id; ?>">
                            <input type="hidden" name="subject_id" value="<?php echo $subject_id; ?>">
                            <input type="hidden" name="section_id" value="<?php echo $section_id; ?>">
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover" id="resultsTable">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Student Name</th>
                                            <th>Class</th>
                                            <th>Present/Absent</th> <!-- Single column for status -->
                                            <?php if (empty($sub_subjects)): ?>
                                            <th><?php echo htmlspecialchars($subject_title); ?> (Max:
                                                <?php echo $max_marks; ?>)</th>
                                            <?php else: ?>
                                            <?php foreach ($sub_subjects as $sub_subject_id => $sub_data): ?>
                                            <th><?php echo htmlspecialchars($sub_data['title']); ?> (Max:
                                                <?php echo $sub_data['marks']; ?>)</th>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                            <?php if ($has_any_results): ?>
                                            <th>Actions</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $counter = 1; ?>
                                        <?php foreach ($students as $student): ?>
                                        <tr data-student-id="<?php echo $student['id']; ?>">
                                            <td data-label="#"><?php echo $counter++; ?></td>
                                            <td data-label="Student Name">
                                                <?php echo htmlspecialchars($student['name']); ?></td>
                                            <td data-label="Class">
                                                <?php echo htmlspecialchars($student['class_title']) . ' ' . $student['section_title']; ?>
                                            </td>

                                            <!-- Single Present/Absent dropdown per student -->
                                            <td data-label="Present/Absent">
                                                <?php if ($student['has_results']): ?>
                                                <span class="status-display">
                                                    <?php if ($student['status'] == 0): ?>
                                                    <span class="status-label status-present"
                                                        title="Student is Present">
                                                        ✓ Present
                                                    </span>
                                                    <?php else: ?>
                                                    <span class="status-label status-absent" title="Student is Absent">
                                                        ✗ Absent
                                                    </span>
                                                    <?php endif; ?>
                                                </span>
                                                <select name="student_status[<?php echo $student['id']; ?>]"
                                                    class="form-control edit-mode student-status"
                                                    style="display: none;">
                                                    <option value="0"
                                                        <?php echo $student['status'] == 0 ? 'selected' : ''; ?>>Present
                                                    </option>
                                                    <option value="1"
                                                        <?php echo $student['status'] == 1 ? 'selected' : ''; ?>>Absent
                                                    </option>
                                                </select>
                                                <?php else: ?>
                                                <select name="student_status[<?php echo $student['id']; ?>]"
                                                    class="form-control student-status">
                                                    <option value="0" selected>Present</option>
                                                    <option value="1">Absent</option>
                                                </select>
                                                <?php endif; ?>
                                            </td>

                                            <?php if (empty($sub_subjects)): ?>
                                            <td
                                                data-label="<?php echo htmlspecialchars($subject_title . ' (Max: ' . $max_marks . ')'); ?>">
                                                <?php if ($student['has_results'] && !is_null($student['marks'])): ?>
                                                <span
                                                    class="marks-display"><?php echo htmlspecialchars($student['marks']); ?></span>
                                                <input type="number" name="marks[<?php echo $student['id']; ?>][0]"
                                                    value="<?php echo htmlspecialchars($student['marks'] ?? ''); ?>"
                                                    min="0" max="<?php echo $max_marks; ?>" step="0.01"
                                                    class="form-control marks-input edit-mode" style="display: none;"
                                                    placeholder="Enter marks">
                                                <?php else: ?>
                                                <input type="number" name="marks[<?php echo $student['id']; ?>][0]"
                                                    value="<?php echo $student['status'] == 1 ? '0' : ''; ?>" min="0"
                                                    max="<?php echo $max_marks; ?>" step="0.01"
                                                    class="form-control marks-input" placeholder="Enter marks">
                                                <?php endif; ?>
                                            </td>
                                            <?php else: ?>
                                            <?php foreach ($sub_subjects as $sub_subject_id => $sub_data): ?>
                                            <td
                                                data-label="<?php echo htmlspecialchars($sub_data['title'] . ' (Max: ' . $sub_data['marks'] . ')'); ?>">
                                                <?php if ($student['has_results'] && !is_null($student['sub_marks'][$sub_subject_id])): ?>
                                                <span
                                                    class="marks-display"><?php echo htmlspecialchars($student['sub_marks'][$sub_subject_id]); ?></span>
                                                <input type="number"
                                                    name="marks[<?php echo $student['id']; ?>][<?php echo $sub_subject_id; ?>]"
                                                    value="<?php echo htmlspecialchars($student['sub_marks'][$sub_subject_id] ?? ''); ?>"
                                                    min="0" max="<?php echo $sub_data['marks']; ?>" step="0.01"
                                                    class="form-control marks-input edit-mode" style="display: none;"
                                                    placeholder="Enter marks">
                                                <?php else: ?>
                                                <input type="number"
                                                    name="marks[<?php echo $student['id']; ?>][<?php echo $sub_subject_id; ?>]"
                                                    value="<?php echo $student['status'] == 1 ? '0' : ''; ?>" min="0"
                                                    max="<?php echo $sub_data['marks']; ?>" step="0.01"
                                                    class="form-control marks-input" placeholder="Enter marks">
                                                <?php endif; ?>
                                            </td>
                                            <?php endforeach; ?>
                                            <?php endif; ?>

                                            <?php if ($has_any_results): ?>
                                            <td data-label="Actions">
                                                <?php if ($student['has_results']): ?>
                                                <button type="button"
                                                    class="btn btn-warning btn-sm action-btn edit-btn">Edit</button>
                                                <button type="button" class="btn btn-dark btn-sm action-btn delete-btn"
                                                    data-student-id="<?php echo $student['id']; ?>">Delete</button>
                                                <?php endif; ?>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-right mt-3">
                                <button type="submit" name="save_results" class="btn btn-success">Save Results</button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    $(document).ready(function() {
        // Initialize Select2
        $('.select2').select2({
            placeholder: "Select an option",
            allowClear: true
        });

        // Auto-dismiss alerts
        setTimeout(function() {
            $('.alert').fadeOut('slow');
        }, 5000);

        // Show pop-up alert
        function showPopupAlert(message, type) {
            const alertClass = type === 'success' ? 'alert-success' : (type === 'warning' ?
                'alert-warning' : 'alert-danger');
            const alertHtml = `
                    <div class="alert ${alertClass} popup-alert">
                        <button type="button" class="close" data-dismiss="alert">&times;</button>
                        ${message}
                    </div>
                `;
            $('body').append(alertHtml);
            setTimeout(function() {
                $('.popup-alert').fadeOut('slow', function() {
                    $(this).remove();
                });
            }, 5000);
        }

        // Handle student status change
        $(document).on('change', '.student-status', function() {
            const statusSelect = $(this);
            const statusValue = statusSelect.val(); // 0 = Present, 1 = Absent
            const studentRow = statusSelect.closest('tr');
            const marksInputs = studentRow.find('.marks-input');

            if (statusValue == '1') { // Absent
                // Set all marks to 0
                marksInputs.each(function() {
                    $(this).val('0');
                    $(this).prop("readonly", true);
                });

                // Update display spans if visible
                const marksDisplays = studentRow.find('.marks-display');
                marksDisplays.each(function() {
                    $(this).text('0');

                });
            } else { // Present
                // Clear marks
                marksInputs.each(function() {
                    if ($(this).val() == '0') {
                        $(this).val('');
                        $(this).prop("readonly", false);

                    }
                });
            }
        });

        // Initialize status on page load
        $('.student-status').each(function() {
            if ($(this).val() == '1') {
                const studentRow = $(this).closest('tr');
                const marksInputs = studentRow.find('.marks-input');
                marksInputs.val('0');
            }
        });

        // Edit button click - allow editing even when absent
        $('.edit-btn').on('click', function() {
            const studentRow = $(this).closest('tr');
            studentRow.find('.marks-display').hide();
            studentRow.find('.marks-input').show();
            studentRow.find('.status-display').hide();
            studentRow.find('.student-status.edit-mode').show();
            $(this).hide();
            studentRow.find('.delete-btn').hide();
        });

        // Delete button click
        $('.delete-btn').on('click', function() {
            if (!confirm('Are you sure you want to delete all marks for this student?')) return;
            const studentId = $(this).data('student-id');
            $.post('', {
                delete_marks: true,
                csrf_token: '<?php echo $_SESSION['csrf_token']; ?>',
                student_id: studentId
            }, function(response) {
                location.reload();
            });
        });
    });

    function printAwardList() {
    // Collect student data
    var students = [];
    var rows = document.querySelectorAll("#resultsTable tbody tr");
    
    rows.forEach(function(row, index) {
        var cols = row.querySelectorAll("td");
        if (cols.length > 0) {
            var student = {
                id: index + 1,
                name: cols[1].innerText.trim(),
                marks: []
            };
            
            <?php if (empty($sub_subjects)): ?>
                // Main subject only: columns are #(0), Name(1), Class(2), Status(3), Marks(4), [Actions(5)]
                if (cols[4]) {
                    var marksDisplay = cols[4].querySelector('.marks-display');
                    student.marks.push(marksDisplay ? marksDisplay.innerText.trim() : '');
                } else {
                    student.marks.push('');
                }
            <?php else: ?>
                // Sub-subjects: columns are #(0), Name(1), Class(2), Status(3), Sub1(4), Sub2(5), ..., [Actions]
                <?php 
                $colIndex = 4; // Start after Status column
                foreach ($sub_subjects as $sub_id => $sub): 
                ?>
                    if (cols[<?php echo $colIndex; ?>]) {
                        var marksDisplay = cols[<?php echo $colIndex; ?>].querySelector('.marks-display');
                        student.marks.push(marksDisplay ? marksDisplay.innerText.trim() : '');
                    } else {
                        student.marks.push('');
                    }
                <?php 
                    $colIndex++;
                endforeach; 
                ?>
            <?php endif; ?>
            
            students.push(student);
        }
    });

    // Dynamic data from PHP
    var awardClass = "<?php echo addslashes($class_title); ?>";
    var awardSession = "<?php echo addslashes($session_title); ?>";
    var awardExamType = "<?php echo addslashes($exam_type_title); ?>";
    var awardSubject = "<?php echo addslashes($subject_title); ?>";

    // Subjects/Sub-subjects columns for header
    var awardSubjects = [];
    <?php if (!empty($sub_subjects)): ?>
        <?php foreach ($sub_subjects as $sub_id => $sub): ?>
            awardSubjects.push("<?php echo addslashes($sub['title'] . ' (Max: ' . $sub['marks'] . ')'); ?>");
        <?php endforeach; ?>
    <?php else: ?>
        awardSubjects.push("<?php echo addslashes($subject_title . ' (Max: ' . $max_marks . ')'); ?>");
    <?php endif; ?>

    // Build HTML for print
    var html = `
        <html>
        <head>
            <title>Award List</title>
            <style>
                body { 
                    font-family: Arial, sans-serif; 
                    padding: 20px; 
                    margin: 0;
                }
                .header {
                    text-align: center;
                    margin-bottom: 30px;
                }
                h2 { 
                    text-align: center; 
                    margin-bottom: 5px; 
                    font-size: 18px;
                }
                h4 { 
                    text-align: center; 
                    margin-bottom: 20px; 
                    font-weight: normal; 
                    font-size: 14px;
                }
                table { 
                    width: 100%; 
                    border-collapse: collapse; 
                    font-size: 12px; 
                }
                th, td { 
                    border: 1px solid #444; 
                    padding: 8px; 
                    text-align: center; 
                }
                th { 
                    background-color: #f2f2f2; 
                    font-weight: bold;
                }
                td.text-left { 
                    text-align: left; 
                    padding-left: 10px; 
                }
                tbody tr:nth-child(even) { 
                    background-color: #f9f9f9; 
                }
                @media print {
                    body { 
                        padding: 0; 
                    }
                    table { 
                        page-break-inside: auto; 
                    }
                    tr { 
                        page-break-inside: avoid; 
                        page-break-after: auto; 
                    }
                }
            </style>
        </head>
        <body>
            <div class="header">
                <h2>Award List of Class ${awardClass}</h2>
                <h4>Session: ${awardSession} | Exam Type: ${awardExamType} | Subject: ${awardSubject}</h4>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student Name</th>`;
    
    awardSubjects.forEach(function(sub) {
        html += `<th>${sub}</th>`;
    });
    
    html += `<th>Present/Absent</th>
                    </tr>
                </thead>
                <tbody>`;
    
    students.forEach(function(stu) {
        html += `<tr>
            <td>${stu.id}</td>
            <td class="text-left">${stu.name}</td>`;
        
        stu.marks.forEach(function(mark) {
            html += `<td>${mark || ''}</td>`;
        });
        
        // Empty Present/Absent column
        html += `<td></td>`;
        
        html += `</tr>`;
    });
    
    html += `
                </tbody>
            </table>
        </body>
        </html>
    `;

    // Remove any existing print iframe
    var existingIframe = document.getElementById('printIframe');
    if (existingIframe) {
        existingIframe.parentNode.removeChild(existingIframe);
    }

    // Create an iframe for printing instead of a new window
    var iframe = document.createElement('iframe');
    iframe.id = 'printIframe';
    iframe.style.display = 'none';
    document.body.appendChild(iframe);
    
    var iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
    iframeDoc.write(html);
    iframeDoc.close();
    
    // Flag to prevent multiple print triggers
    var hasPrinted = false;
    
    // Function to handle printing
    function handlePrint() {
        if (!hasPrinted) {
            hasPrinted = true;
            try {
                iframe.contentWindow.focus();
                iframe.contentWindow.print();
                
                // Remove iframe after a delay
                setTimeout(function() {
                    try {
                        if (iframe && iframe.parentNode) {
                            iframe.parentNode.removeChild(iframe);
                        }
                    } catch (e) {
                        console.log('Iframe already removed');
                    }
                }, 2000);
            } catch (e) {
                console.log('Print error:', e);
            }
        }
    }
    
    // Handle iframe load
    iframe.onload = handlePrint;
    
    // Fallback: If iframe is already loaded
    if (iframeDoc.readyState === 'complete') {
        setTimeout(handlePrint, 500);
    }
}
    </script>

</body>

</html>
<?php
$conn->close();
?>