<?php
require_once('security.php');

// Initialize session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Generate CSRF token if not set
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Create database connection
require_once('conn_inc.php');

/**
 * Validates if a sub_subject_id exists in the sub_subjects table and is linked to class_id via subject_class_sub
 * @param mysqli $conn Database connection
 * @param int $sub_subject_id Sub-subject ID to validate
 * @param int $subject_id Subject ID
 * @param int $class_id Class ID
 * @return bool True if valid, false otherwise
 */
function isValidSubSubject($conn, $sub_subject_id, $subject_id, $class_id) {
    $stmt = $conn->prepare("
        SELECT scs.id 
        FROM subject_class_sub scs
        JOIN subject_class sc ON scs.subject_class_id = sc.id
        JOIN sub_subjects ss ON scs.sub_subject_id = ss.id
        WHERE scs.sub_subject_id = ? AND sc.subject_id = ? AND sc.class_id = ?
    ");
    $stmt->bind_param("iii", $sub_subject_id, $subject_id, $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();
    return $exists;
}

/**
 * Validates if an entry_id exists in exam_datesheet for the given arrange_exam_id
 * @param mysqli $conn Database connection
 * @param int $entry_id Entry ID to validate
 * @param int $arrange_exam_id Arrange Exam ID
 * @return bool True if valid, false otherwise
 */
function isValidEntryId($conn, $entry_id, $arrange_exam_id) {
    $stmt = $conn->prepare("SELECT id FROM exam_datesheet WHERE id = ? AND arrange_exam_id = ?");
    $stmt->bind_param("ii", $entry_id, $arrange_exam_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();
    return $exists;
}

/**
 * Handles form submission for adding or updating datesheet entries
 * @param mysqli $conn Database connection
 * @param array $post $_POST data
 * @param int $arrange_exam_id Arrange Exam ID
 * @param int $class_id Class ID
 * @return bool Success status
 */
function handleFormSubmission($conn, $post, $arrange_exam_id, $class_id) {
    if (!isset($post['csrf_token']) || $post['csrf_token'] !== $_SESSION['csrf_token']) {
        error_log("CSRF token validation failed", 3, 'errors.log');
        $_SESSION['message'] = "Security error: Invalid CSRF token.";
        $_SESSION['message_type'] = 'danger';
        return false;
    }

    // Log entire POST data for debugging
    error_log("POST data: " . json_encode($post), 3, 'errors.log');

    $subject_ids = isset($post['subject_ids']) ? array_map('intval', $post['subject_ids']) : [];
    $start_dates = isset($post['start_dates']) ? $post['start_dates'] : [];
    $start_times = isset($post['start_times']) ? $post['start_times'] : [];
    $end_times = isset($post['end_times']) ? $post['end_times'] : [];
    $entry_ids = isset($post['entry_ids']) ? $post['entry_ids'] : [];

    // Skip processing if no subjects are submitted
    if (empty($subject_ids)) {
        error_log("No subject_ids submitted, skipping form processing", 3, 'errors.log');
        $_SESSION['message'] = "No subjects provided for update.";
        $_SESSION['message_type'] = 'warning';
        return false;
    }

    // Fetch valid subjects for the class
    $valid_subjects = getSubjectsForClass($conn, $class_id);
    if (empty($valid_subjects)) {
        error_log("No valid subjects found for class_id $class_id", 3, 'errors.log');
        $_SESSION['message'] = "No subjects found for the selected class.";
        $_SESSION['message_type'] = 'danger';
        return false;
    }

    $valid_sub_subject_ids = [];
    foreach ($valid_subjects as $subject_id => $subject) {
        if ($subject['type'] === 'composite') {
            foreach ($subject['sub_subjects'] as $sub_subject) {
                $valid_sub_subject_ids[$subject_id][] = $sub_subject['id'];
            }
        }
    }
    error_log("Valid sub_subject_ids: " . json_encode($valid_sub_subject_ids), 3, 'errors.log');

    // Fetch existing datesheet entries
    $existing_entries = [];
    $stmt = $conn->prepare("
        SELECT id, subject_id, sub_subject_id
        FROM exam_datesheet
        WHERE arrange_exam_id = ?
    ");
    $stmt->bind_param("i", $arrange_exam_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $key = $row['subject_id'] . '-' . ($row['sub_subject_id'] ?? 'NULL');
        $existing_entries[$key] = [
            'id' => $row['id'],
            'subject_id' => $row['subject_id'],
            'sub_subject_id' => $row['sub_subject_id']
        ];
    }
    $stmt->close();
    error_log("Existing entries: " . json_encode($existing_entries), 3, 'errors.log');

    $success = true;
    $processed_entry_ids = [];

    foreach ($subject_ids as $subject_id) {
        if ($subject_id <= 0 || !isset($valid_subjects[$subject_id])) {
            error_log("Invalid or unauthorized subject_id $subject_id for class_id $class_id", 3, 'errors.log');
            $_SESSION['message'] = "Subject ID $subject_id is invalid or not associated with the selected class.";
            $_SESSION['message_type'] = 'danger';
            $success = false;
            continue;
        }

        $subject = $valid_subjects[$subject_id];

        if ($subject['type'] === 'composite') {
            if (!isset($start_dates[$subject_id]) || !is_array($start_dates[$subject_id])) {
                error_log("No sub-subjects provided for composite subject_id $subject_id", 3, 'errors.log');
                $_SESSION['message'] = "No sub-subjects provided for composite subject ID $subject_id.";
                $_SESSION['message_type'] = 'danger';
                $success = false;
                continue;
            }

            foreach ($valid_sub_subject_ids[$subject_id] as $sub_subject_id) {
                if (!isset($start_dates[$subject_id][$sub_subject_id])) {
                    error_log("No data for sub_subject_id $sub_subject_id under subject_id $subject_id", 3, 'errors.log');
                    continue;
                }

                if (!isValidSubSubject($conn, $sub_subject_id, $subject_id, $class_id)) {
                    error_log("Invalid sub_subject_id $sub_subject_id for subject_id $subject_id and class_id $class_id", 3, 'errors.log');
                    $_SESSION['message'] = "Sub-subject ID $sub_subject_id is invalid for subject ID $subject_id in this class.";
                    $_SESSION['message_type'] = 'danger';
                    $success = false;
                    continue;
                }

                $start_date = $start_dates[$subject_id][$sub_subject_id] ?? '';
                $start_time = $start_times[$subject_id][$sub_subject_id] ?? '';
                $end_time = $end_times[$subject_id][$sub_subject_id] ?? '';
                $entry_id = isset($entry_ids[$subject_id][$sub_subject_id]) ? intval($entry_ids[$subject_id][$sub_subject_id]) : 0;

                if (empty($start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) ||
                    empty($start_time) || !preg_match('/^\d{2}:\d{2}$/', $start_time) ||
                    empty($end_time) || !preg_match('/^\d{2}:\d{2}$/', $end_time)) {
                    error_log("Invalid date/time format for sub_subject_id $sub_subject_id: date=$start_date, start=$start_time, end=$end_time", 3, 'errors.log');
                    $_SESSION['message'] = "Invalid date or time format for sub-subject ID $sub_subject_id.";
                    $_SESSION['message_type'] = 'danger';
                    $success = false;
                    continue;
                }

                $key = $subject_id . '-' . $sub_subject_id;
                $existing_entry_id = isset($existing_entries[$key]) ? $existing_entries[$key]['id'] : null;

                if ($existing_entry_id && $entry_id > 0 && $entry_id == $existing_entry_id && isValidEntryId($conn, $entry_id, $arrange_exam_id)) {
                    $stmt = $conn->prepare("
                        UPDATE exam_datesheet 
                        SET start_date = ?, start_time = ?, end_time = ?
                        WHERE id = ? AND arrange_exam_id = ? AND subject_id = ? AND sub_subject_id = ?
                    ");
                    $stmt->bind_param("sssiiis", $start_date, $start_time, $end_time, $existing_entry_id, $arrange_exam_id, $subject_id, $sub_subject_id);
                    if ($stmt->execute()) {
                        error_log("Updated entry_id $existing_entry_id for subject_id $subject_id, sub_subject_id $sub_subject_id", 3, 'errors.log');
                        $processed_entry_ids[] = $existing_entry_id;
                    } else {
                        error_log("Error updating entry_id $existing_entry_id: " . $stmt->error, 3, 'errors.log');
                        $_SESSION['message'] = "Error updating datesheet for sub-subject ID $sub_subject_id: " . $stmt->error;
                        $_SESSION['message_type'] = 'danger';
                        $success = false;
                    }
                    $stmt->close();
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO exam_datesheet (arrange_exam_id, class_id, subject_id, sub_subject_id, start_date, start_time, end_time) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->bind_param("iiiisss", $arrange_exam_id, $class_id, $subject_id, $sub_subject_id, $start_date, $start_time, $end_time);
                    if ($stmt->execute()) {
                        error_log("Inserted new entry for subject_id $subject_id, sub_subject_id $sub_subject_id", 3, 'errors.log');
                        $processed_entry_ids[] = $conn->insert_id;
                    } else {
                        error_log("Error inserting entry for sub_subject_id $sub_subject_id: " . $stmt->error, 3, 'errors.log');
                        $_SESSION['message'] = "Error inserting datesheet for sub-subject ID $sub_subject_id: " . $stmt->error;
                        $_SESSION['message_type'] = 'danger';
                        $success = false;
                    }
                    $stmt->close();
                }
            }
        } else {
            $start_date = $start_dates[$subject_id] ?? '';
            $start_time = $start_times[$subject_id] ?? '';
            $end_time = $end_times[$subject_id] ?? '';
            $entry_id = isset($entry_ids[$subject_id]) ? intval($entry_ids[$subject_id]) : 0;

            if (empty($start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) ||
                empty($start_time) || !preg_match('/^\d{2}:\d{2}$/', $start_time) ||
                empty($end_time) || !preg_match('/^\d{2}:\d{2}$/', $end_time)) {
                error_log("Invalid date/time format for subject_id $subject_id: date=$start_date, start=$start_time, end=$end_time", 3, 'errors.log');
                $_SESSION['message'] = "Invalid date or time format for subject ID $subject_id.";
                $_SESSION['message_type'] = 'danger';
                $success = false;
                continue;
            }

            $key = $subject_id . '-NULL';
            $existing_entry_id = isset($existing_entries[$key]) ? $existing_entries[$key]['id'] : null;

            if ($existing_entry_id && $entry_id > 0 && $entry_id == $existing_entry_id && isValidEntryId($conn, $entry_id, $arrange_exam_id)) {
                $stmt = $conn->prepare("
                    UPDATE exam_datesheet 
                    SET start_date = ?, start_time = ?, end_time = ?
                    WHERE id = ? AND arrange_exam_id = ? AND subject_id = ? AND sub_subject_id IS NULL
                ");
                $stmt->bind_param("sssiii", $start_date, $start_time, $end_time, $existing_entry_id, $arrange_exam_id, $subject_id);
                if ($stmt->execute()) {
                    error_log("Updated entry_id $existing_entry_id for subject_id $subject_id", 3, 'errors.log');
                    $processed_entry_ids[] = $existing_entry_id;
                } else {
                    error_log("Error updating entry_id $existing_entry_id: " . $stmt->error, 3, 'errors.log');
                    $_SESSION['message'] = "Error updating datesheet for subject ID $subject_id: " . $stmt->error;
                    $_SESSION['message_type'] = 'danger';
                    $success = false;
                }
                $stmt->close();
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO exam_datesheet (arrange_exam_id, class_id, subject_id, start_date, start_time, end_time) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("iiisss", $arrange_exam_id, $class_id, $subject_id, $start_date, $start_time, $end_time);
                if ($stmt->execute()) {
                    error_log("Inserted new entry for subject_id $subject_id", 3, 'errors.log');
                    $processed_entry_ids[] = $conn->insert_id;
                } else {
                    error_log("Error inserting entry for subject_id $subject_id: " . $stmt->error, 3, 'errors.log');
                    $_SESSION['message'] = "Error inserting datesheet for subject ID $subject_id: " . $stmt->error;
                    $_SESSION['message_type'] = 'danger';
                    $success = false;
                }
                $stmt->close();
            }
        }
    }

    if ($success && !empty($processed_entry_ids)) {
        foreach ($existing_entries as $key => $entry) {
            if (!in_array($entry['id'], $processed_entry_ids)) {
                error_log("Deleting unprocessed entry_id {$entry['id']}: subject_id={$entry['subject_id']}, sub_subject_id=" . ($entry['sub_subject_id'] ?? 'NULL'), 3, 'errors.log');
                $stmt = $conn->prepare("DELETE FROM exam_datesheet WHERE id = ? AND arrange_exam_id = ?");
                $stmt->bind_param("ii", $entry['id'], $arrange_exam_id);
                if (!$stmt->execute()) {
                    error_log("Error deleting unprocessed entry_id {$entry['id']}: " . $stmt->error, 3, 'errors.log');
                    $_SESSION['message'] = "Error deleting unprocessed datesheet entry ID {$entry['id']}.";
                    $_SESSION['message_type'] = 'danger';
                    $success = false;
                }
                $stmt->close();
            }
        }
    } else {
        error_log("No valid entries processed or update failed, skipping deletion", 3, 'errors.log');
    }

    if ($success) {
        $_SESSION['message'] = "Datesheet updated successfully.";
        $_SESSION['message_type'] = 'success';
    }
    return $success;
}

/**
 * Handles deletion of a datesheet entry
 * @param mysqli $conn Database connection
 * @param int $id Datesheet ID to delete
 */
function handleDeletion($conn, $id) {
    error_log("Attempting to delete datesheet entry ID $id", 3, 'errors.log');
    $stmt = $conn->prepare("DELETE FROM exam_datesheet WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        error_log("Successfully deleted datesheet entry ID $id", 3, 'errors.log');
        $_SESSION['message'] = 'Datesheet entry deleted successfully!';
        $_SESSION['message_type'] = 'success';
    } else {
        error_log("Error deleting datesheet ID $id: " . $stmt->error, 3, 'errors.log');
        $_SESSION['message'] = 'Error: Could not delete the entry.';
        $_SESSION['message_type'] = 'danger';
    }
    $stmt->close();
}

/**
 * Fetches exam and class details
 * @param mysqli $conn Database connection
 * @param int $arrange_exam_id Arrange Exam ID
 * @param int $class_id Class ID
 * @return array Exam and class details
 */
function getExamDetails($conn, $arrange_exam_id, $class_id) {
    $data = ['exam' => [], 'class' => []];
    
    $stmt = $conn->prepare("
        SELECT ae.id, ae.exam_type_id, ae.start_date, et.title AS exam_type_title
        FROM arrange_exam ae
        JOIN exam_types et ON ae.exam_type_id = et.id
        WHERE ae.id = ? AND ae.class_id = ?
    ");
    $stmt->bind_param("ii", $arrange_exam_id, $class_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $data['exam'] = $result->fetch_assoc();
        if (!$data['exam']) {
            error_log("No exam found for arrange_exam_id: $arrange_exam_id, class_id: $class_id", 3, 'errors.log');
            $_SESSION['message'] = "Exam not found.";
            $_SESSION['message_type'] = 'danger';
            header("Location: arrange_exam.php");
            exit();
        }
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT id, title FROM classes WHERE id = ?");
    $stmt->bind_param("i", $class_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $data['class'] = $result->fetch_assoc();
        if (!$data['class']) {
            error_log("No class found for ID: $class_id", 3, 'errors.log');
            $_SESSION['message'] = "Class not found.";
            $_SESSION['message_type'] = 'danger';
            header("Location: arrange_exam.php");
            exit();
        }
    }
    $stmt->close();

    return $data;
}

/**
 * Fetches subjects and sub-subjects for a class using subject_class and subject_class_sub
 * @param mysqli $conn Database connection
 * @param int $class_id Class ID
 * @return array Subject ID => [title, type, sub_subjects]
 */
function getSubjectsForClass($conn, $class_id) {
    $subjects = [];
    $stmt = $conn->prepare("
        SELECT sc.id AS subject_class_id, s.id AS subject_id, s.title, s.type 
        FROM subjects s 
        JOIN subject_class sc ON s.id = sc.subject_id 
        WHERE sc.class_id = ? 
        ORDER BY s.title
    ");
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        error_log("No subjects found for class_id: $class_id", 3, 'errors.log');
        return [];
    }
    while ($row = $result->fetch_assoc()) {
        $subjects[$row['subject_id']] = [
            'title' => $row['title'] ?? 'Untitled Subject',
            'type' => $row['type'],
            'subject_class_id' => $row['subject_class_id'],
            'sub_subjects' => []
        ];
    }
    $stmt->close();

    foreach ($subjects as $subject_id => &$subject) {
        if ($subject['type'] === 'composite') {
            $stmt = $conn->prepare("
                SELECT ss.id, ss.title 
                FROM sub_subjects ss
                JOIN subject_class_sub scs ON ss.id = scs.sub_subject_id
                JOIN subject_class sc ON scs.subject_class_id = sc.id
                WHERE sc.subject_id = ? AND sc.class_id = ?
                ORDER BY ss.title
            ");
            $stmt->bind_param("ii", $subject_id, $class_id);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $subject['sub_subjects'][] = [
                    'id' => $row['id'],
                    'title' => $row['title'] ?? 'Untitled Sub-Subject'
                ];
            }
            $stmt->close();
        }
    }

    return $subjects;
}

/**
 * Fetches existing datesheet entries with sub-subjects
 * @param mysqli $conn Database connection
 * @param int $arrange_exam_id Arrange Exam ID
 * @return array Datesheet entries
 */
function getDatesheet($conn, $arrange_exam_id) {
    $datesheet = [];
    $stmt = $conn->prepare("
        SELECT ed.id, ed.subject_id, ed.sub_subject_id, ed.start_date, ed.start_time, ed.end_time, 
               s.title AS subject_title, s.type, ss.title AS sub_subject_title
        FROM exam_datesheet ed
        JOIN subjects s ON ed.subject_id = s.id
        LEFT JOIN sub_subjects ss ON ed.sub_subject_id = ss.id
        WHERE ed.arrange_exam_id = ?
        ORDER BY ed.start_date, s.title, ss.title
    ");
    $stmt->bind_param("i", $arrange_exam_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $subject_id = $row['subject_id'];
        if (!isset($datesheet[$subject_id])) {
            $datesheet[$subject_id] = [
                'subject_id' => $row['subject_id'],
                'subject_title' => $row['subject_title'] ?? 'Untitled Subject',
                'type' => $row['type'],
                'sub_subjects' => []
            ];
            if ($row['type'] !== 'composite') {
                $datesheet[$subject_id]['id'] = $row['id'];
                $datesheet[$subject_id]['start_date'] = $row['start_date'];
                $datesheet[$subject_id]['start_time'] = $row['start_time'];
                $datesheet[$subject_id]['end_time'] = $row['end_time'];
            }
        }
        if ($row['sub_subject_id']) {
            $datesheet[$subject_id]['sub_subjects'][] = [
                'id' => $row['id'],
                'sub_subject_id' => $row['sub_subject_id'],
                'sub_subject_title' => $row['sub_subject_title'] ?? 'Untitled Sub-Subject',
                'start_date' => $row['start_date'],
                'start_time' => $row['start_time'],
                'end_time' => $row['end_time']
            ];
        }
    }
    $stmt->close();
    return $datesheet;
}

/**
 * Formats subject title for display
 * @param array $subject Subject data
 * @return string Formatted title
 */
function formatSubjectTitle($subject) {
    return isset($subject['subject_title']) ? htmlspecialchars($subject['subject_title']) : 'Untitled Subject';
}

// Validate URL parameters
$arrange_exam_id = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : 0;
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$exam_type_title = isset($_GET['exam_type']) ? htmlspecialchars($_GET['exam_type']) : '';

if ($arrange_exam_id <= 0 || $class_id <= 0) {
    error_log("Invalid URL parameters: arrange_exam_id=$arrange_exam_id, class_id=$class_id", 3, 'errors.log');
    $_SESSION['message'] = "Invalid exam or class ID.";
    $_SESSION['message_type'] = 'danger';
    header("Location: arrange_exam.php");
    exit();
}

// Log page access
error_log("Accessing datesheet.php with exam_id=$arrange_exam_id, class_id=$class_id, exam_type=$exam_type_title", 3, 'errors.log');

// Load exam and class details
$details = getExamDetails($conn, $arrange_exam_id, $class_id);
$exam = $details['exam'];
$class = $details['class'];

// Handle form submission (add/update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    error_log("Processing form submission for arrange_exam_id=$arrange_exam_id", 3, 'errors.log');
    if (handleFormSubmission($conn, $_POST, $arrange_exam_id, $class_id)) {
        header("Location: datesheet_list.php?message=" . urlencode("Datesheet updated successfully.") . "&message_type=success");
        exit();
    } else {
        $_SESSION['message'] = $_SESSION['message'] ?? "Error: Could not process datesheet.";
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF'] . "?exam_id=$arrange_exam_id&class_id=$class_id&exam_type=" . urlencode($exam_type_title));
        exit();
    }
}

// Handle deletion (POST request)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['delete_id']) && intval($_POST['delete_id']) > 0) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        error_log("CSRF token validation failed for deletion", 3, 'errors.log');
        $_SESSION['message'] = "Security error: Invalid CSRF token.";
        $_SESSION['message_type'] = 'danger';
    } else {
        handleDeletion($conn, intval($_POST['delete_id']));
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "?exam_id=$arrange_exam_id&class_id=$class_id&exam_type=" . urlencode($exam_type_title));
    exit();
}

// Load data
$subjects = getSubjectsForClass($conn, $class_id);
$datesheet = getDatesheet($conn, $arrange_exam_id);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Datesheet for <?php echo htmlspecialchars($class['title'] . ' - ' . $exam_type_title); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
    <style>
        .table th, .table td {
            text-align: left;
            vertical-align: middle;
            padding: 12px;
        }
        .subject-row {
            background-color: #f8fbff;
            transition: background-color 0.2s;
        }
        .subject-row:hover {
            background-color: #e6f3ff;
        }
        .sub-subject-row {
            background-color: #f0f8ff;
            padding-left: 30px;
        }
        .sub-subject-row:hover {
            background-color: #e0f0ff;
        }
        .subject-title {
            font-weight: bold;
        }
        .action-btn {
            margin-right: 5px;
            padding: 6px 12px;
            border-radius: 4px;
        }
        .form-table-container {
            margin-bottom: 15px;
        }
        .date-input, .time-input {
            width: 140px;
            display: inline-block;
            margin-right: 10px;
        }
        .form-group label {
            font-weight: 600;
            margin-bottom: 8px;
        }
        .alert {
            margin-bottom: 15px;
        }
        .exam-title {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #337ab7;
        }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="container">
    <div class="row">
        <div class="col-md-12">
            <h3 class="exam-title">
                Exam Datesheet for <?php echo htmlspecialchars($class['title']); ?> - <?php echo htmlspecialchars($exam_type_title); ?>
            </h3>

            <div class="panel panel-primary">
                <div class="panel-heading">
                    <h3 class="panel-title">Manage Datesheet</h3>
                </div>
                <div class="panel-body">
                    <?php if (isset($_SESSION['message'])): ?>
                        <div class="alert alert-<?php echo $_SESSION['message_type']; ?>">
                            <?php 
                            echo $_SESSION['message']; 
                            unset($_SESSION['message'], $_SESSION['message_type']);
                            ?>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($subjects)): ?>
                        <div class="alert alert-warning">
                            No subjects found for the selected class. Please assign subjects to this class first.
                            <a href="assign_subjects.php?class_id=<?php echo $class_id; ?>" class="alert-link">Assign Subjects</a>
                        </div>
                    <?php else: ?>
                        <form method="post" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <div class="form-group form-table-container">
                                <label>Assign Subjects, Dates, and Times for <?php echo htmlspecialchars($class['title']); ?>:</label>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped">
                                        <thead>
                                            <tr>
                                                <th style="width: 5%;">S.No</th>
                                                <th style="width: 30%;">Subjects</th>
                                                <th style="width: 20%;">Start Date</th>
                                                <th style="width: 20%;">Start Time</th>
                                                <th style="width: 20%;">End Time</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $counter = 1; ?>
                                            <?php foreach ($subjects as $subject_id => $subject): ?>
                                                <tr class="subject-row" data-subject-id="<?php echo $subject_id; ?>">
                                                    <td class="subject-sno"><?php echo $counter++; ?></td>
                                                    <td class="subject-title">
                                                        <?php echo htmlspecialchars($subject['title'] ?? 'Untitled Subject') . ' (' . $subject['type'] . ')'; ?>
                                                        <input type="hidden" name="subject_ids[]" value="<?php echo $subject_id; ?>">
                                                    </td>
                                                    <?php if ($subject['type'] !== 'composite'): ?>
                                                        <td>
                                                            <input type="date" class="form-control date-input start-date" 
                                                                   name="start_dates[<?php echo $subject_id; ?>]" 
                                                                   value="<?php echo isset($datesheet[$subject_id]['start_date']) ? htmlspecialchars($datesheet[$subject_id]['start_date']) : ''; ?>" 
                                                                   placeholder="Select Start Date">
                                                            <input type="hidden" name="entry_ids[<?php echo $subject_id; ?>]" 
                                                                   value="<?php echo isset($datesheet[$subject_id]['id']) ? htmlspecialchars($datesheet[$subject_id]['id']) : '0'; ?>">
                                                        </td>
                                                        <td>
                                                            <input type="time" class="form-control time-input start-time" 
                                                                   name="start_times[<?php echo $subject_id; ?>]" 
                                                                   value="<?php echo isset($datesheet[$subject_id]['start_time']) ? htmlspecialchars($datesheet[$subject_id]['start_time']) : ''; ?>" 
                                                                   placeholder="Start Time">
                                                        </td>
                                                        <td>
                                                            <input type="time" class="form-control time-input end-time" 
                                                                   name="end_times[<?php echo $subject_id; ?>]" 
                                                                   value="<?php echo isset($datesheet[$subject_id]['end_time']) ? htmlspecialchars($datesheet[$subject_id]['end_time']) : ''; ?>" 
                                                                   placeholder="End Time">
                                                        </td>
                                                    <?php else: ?>
                                                        <td></td><td></td><td></td>
                                                    <?php endif; ?>
                                                </tr>
                                                <?php if ($subject['type'] === 'composite'): ?>
                                                    <?php foreach ($subject['sub_subjects'] as $sub_subject): ?>
                                                        <tr class="sub-subject-row" data-subject-id="<?php echo $subject_id; ?>">
                                                            <td></td>
                                                            <td>
                                                                <?php echo htmlspecialchars($sub_subject['title'] ?? 'Untitled Sub-Subject'); ?>
                                                                <input type="hidden" name="sub_subject_ids[<?php echo $subject_id; ?>][]" 
                                                                       value="<?php echo $sub_subject['id']; ?>">
                                                            </td>
                                                            <td>
                                                                <input type="date" class="form-control date-input start-date" 
                                                                       name="start_dates[<?php echo $subject_id; ?>][<?php echo $sub_subject['id']; ?>]" 
                                                                       value="<?php 
                                                                           if (isset($datesheet[$subject_id]['sub_subjects'])) {
                                                                               foreach ($datesheet[$subject_id]['sub_subjects'] as $ds_sub) {
                                                                                   if ($ds_sub['sub_subject_id'] == $sub_subject['id']) {
                                                                                       echo htmlspecialchars($ds_sub['start_date']);
                                                                                       break;
                                                                                   }
                                                                               }
                                                                           }
                                                                       ?>" 
                                                                       placeholder="Select Start Date">
                                                                <input type="hidden" name="entry_ids[<?php echo $subject_id; ?>][<?php echo $sub_subject['id']; ?>]" 
                                                                       value="<?php 
                                                                           if (isset($datesheet[$subject_id]['sub_subjects'])) {
                                                                               foreach ($datesheet[$subject_id]['sub_subjects'] as $ds_sub) {
                                                                                   if ($ds_sub['sub_subject_id'] == $sub_subject['id']) {
                                                                                       echo htmlspecialchars($ds_sub['id']);
                                                                                       break;
                                                                                   }
                                                                               }
                                                                           } else {
                                                                               echo '0';
                                                                           }
                                                                       ?>">
                                                            </td>
                                                            <td>
                                                                <input type="time" class="form-control time-input start-time" 
                                                                       name="start_times[<?php echo $subject_id; ?>][<?php echo $sub_subject['id']; ?>]" 
                                                                       value="<?php 
                                                                           if (isset($datesheet[$subject_id]['sub_subjects'])) {
                                                                               foreach ($datesheet[$subject_id]['sub_subjects'] as $ds_sub) {
                                                                                   if ($ds_sub['sub_subject_id'] == $sub_subject['id']) {
                                                                                       echo htmlspecialchars($ds_sub['start_time']);
                                                                                       break;
                                                                                   }
                                                                               }
                                                                           }
                                                                       ?>" 
                                                                       placeholder="Start Time">
                                                            </td>
                                                            <td>
                                                                <input type="time" class="form-control time-input end-time" 
                                                                       name="end_times[<?php echo $subject_id; ?>][<?php echo $sub_subject['id']; ?>]" 
                                                                       value="<?php 
                                                                           if (isset($datesheet[$subject_id]['sub_subjects'])) {
                                                                               foreach ($datesheet[$subject_id]['sub_subjects'] as $ds_sub) {
                                                                                   if ($ds_sub['sub_subject_id'] == $sub_subject['id']) {
                                                                                       echo htmlspecialchars($ds_sub['end_time']);
                                                                                       break;
                                                                                   }
                                                                               }
                                                                           }
                                                                       ?>" 
                                                                       placeholder="End Time">
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="text-right">
                                <button type="submit" class="btn btn-success">
                                    <span class="glyphicon glyphicon-refresh"></span> Save Datesheet
                                </button>
                                <a href="arrange_exam.php" class="btn btn-default">
                                    <span class="glyphicon glyphicon-remove"></span> Back
                                </a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="panel panel-info">
                <div class="panel-heading">
                    <h3 class="panel-title">Datesheet Details</h3>
                </div>
                <div class="panel-body">
                    <?php if (!empty($datesheet)): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th style="width: 5%;">S.No</th>
                                        <th style="width: 30%;">Subjects</th>
                                        <th style="width: 20%;">Start Date</th>
                                        <th style="width: 20%;">Start Time</th>
                                        <th style="width: 20%;">End Time</th>
                                        <th style="width: 5%;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $counter = 1; ?>
                                    <?php foreach ($datesheet as $ds): ?>
                                        <tr class="subject-row">
                                            <td class="subject-sno"><?php echo $counter++; ?></td>
                                            <td class="subject-title"><?php echo formatSubjectTitle($ds); ?></td>
                                            <?php if ($ds['type'] !== 'composite'): ?>
                                                <td><?php echo htmlspecialchars($ds['start_date'] ?? ''); ?></td>
                                                <td><?php echo htmlspecialchars($ds['start_time'] ?? ''); ?></td>
                                                <td><?php echo htmlspecialchars($ds['end_time'] ?? ''); ?></td>
                                                <td>
                                                    <form method="post" action="" style="display: inline;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="delete_id" value="<?php echo $ds['id']; ?>">
                                                        <button type="submit" class="btn btn-xs btn-danger action-btn" 
                                                                onclick="return confirm('Are you sure you want to delete this datesheet entry?')">
                                                            <span class="glyphicon glyphicon-trash"></span>
                                                        </button>
                                                    </form>
                                                </td>
                                            <?php else: ?>
                                                <td></td><td></td><td></td><td></td>
                                            <?php endif; ?>
                                        </tr>
                                        <?php if ($ds['type'] === 'composite'): ?>
                                            <?php foreach ($ds['sub_subjects'] as $sub_ds): ?>
                                                <tr class="sub-subject-row">
                                                    <td></td>
                                                    <td><?php echo htmlspecialchars($sub_ds['sub_subject_title'] ?? 'Untitled Sub-Subject'); ?></td>
                                                    <td><?php echo htmlspecialchars($sub_ds['start_date'] ?? ''); ?></td>
                                                    <td><?php echo htmlspecialchars($sub_ds['start_time'] ?? ''); ?></td>
                                                    <td><?php echo htmlspecialchars($sub_ds['end_time'] ?? ''); ?></td>
                                                    <td>
                                                        <form method="post" action="" style="display: inline;">
                                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="delete_id" value="<?php echo $sub_ds['id']; ?>">
                                                            <button type="submit" class="btn btn-xs btn-danger action-btn" 
                                                                    onclick="return confirm('Are you sure you want to delete this sub-subject datesheet entry?')">
                                                                <span class="glyphicon glyphicon-trash"></span>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info text-center">
                            No datesheet entries found. Add entries above.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Auto-dismiss alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);

    // Sync all start times when the first row's start time is changed
    $('.start-time').first().change(function() {
        var selectedTime = $(this).val();
        if (selectedTime && selectedTime.match(/^\d{2}:\d{2}$/)) {
            $('.start-time').not(this).val(selectedTime);
        }
    });

    // Sync all end times when the first row's end time is changed
    $('.end-time').first().change(function() {
        var selectedTime = $(this).val();
        if (selectedTime && selectedTime.match(/^\d{2}:\d{2}$/)) {
            $('.end-time').not(this).val(selectedTime);
        }
    });
});
</script>

</body>
</html>
<?php
$conn->close();
?>