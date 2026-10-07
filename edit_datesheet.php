<?php
/**
 * File: edit_datesheet.php
 * Purpose: Edits existing datesheet entries for a specific class and exam, staying on the same page after submission.
 * Features:
 * - Displays a form pre-populated with existing datesheet entries, sorted by subject title for consistent order.
 * - Includes subject_ids and valid entry_ids in the form.
 * - Validates entry_ids against exam_datesheet table.
 * - Handles both HH:MM and HH:MM:SS time formats.
 * - Normalizes sub_subject_id=0 to NULL for validation.
 * - Updates exam_datesheet table with CSRF protection.
 * - Stays on the same page after submission, showing success or error messages.
 * - Uses Bootstrap 3.4.1 for styling.
 * Dependencies: security.php, conn_inc.php, navbar.php
 */

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
 * Validates if an entry_id exists in exam_datesheet for the given arrange_exam_id
 * @param mysqli $conn Database connection
 * @param int $entry_id Entry ID to validate
 * @param int $arrange_exam_id Arrange Exam ID
 * @return bool True if valid, false otherwise
 */
function isValidEntryId($conn, $entry_id, $arrange_exam_id) {
    if ($entry_id <= 0) {
        error_log("Invalid entry_id: $entry_id (zero or negative)", 3, 'errors.log');
        return false;
    }
    $stmt = $conn->prepare("SELECT id FROM exam_datesheet WHERE id = ? AND arrange_exam_id = ?");
    $stmt->bind_param("ii", $entry_id, $arrange_exam_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();
    if (!$exists) {
        error_log("No record found for entry_id: $entry_id, arrange_exam_id: $arrange_exam_id", 3, 'errors.log');
    }
    return $exists;
}

/**
 * Normalizes time format to HH:MM:SS
 * @param string $time Time in HH:MM or HH:MM:SS
 * @return string Normalized time
 */
function normalizeTime($time) {
    if (preg_match('/^\d{2}:\d{2}$/', $time)) {
        return $time . ':00';
    }
    return $time;
}

/**
 * Handles form submission for updating datesheet entries
 * @param mysqli $conn Database connection
 * @param array $post $_POST data
 * @param int $arrange_exam_id Arrange Exam ID
 * @return bool Success status
 */
function handleFormSubmission($conn, $post, $arrange_exam_id) {
    if (!isset($post['csrf_token']) || $post['csrf_token'] !== $_SESSION['csrf_token']) {
        error_log("CSRF token validation failed", 3, 'errors.log');
        $_SESSION['message'] = "Security error: Invalid CSRF token.";
        $_SESSION['message_type'] = 'danger';
        return false;
    }

    // Log POST data
    error_log("POST data: " . json_encode($post), 3, 'errors.log');

    $subject_ids = isset($post['subject_ids']) ? array_map('intval', $post['subject_ids']) : [];
    $entry_ids = isset($post['entry_ids']) ? $post['entry_ids'] : [];
    $start_dates = isset($post['start_dates']) ? $post['start_dates'] : [];
    $start_times = isset($post['start_times']) ? $post['start_times'] : [];
    $end_times = isset($post['end_times']) ? $post['end_times'] : [];

    if (empty($subject_ids)) {
        error_log("No subject_ids submitted", 3, 'errors.log');
        $_SESSION['message'] = "No subjects provided for update.";
        $_SESSION['message_type'] = 'warning';
        return false;
    }

    // Fetch existing entries
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
        $sub_subject_id = $row['sub_subject_id'] === null || $row['sub_subject_id'] == 0 ? 'NULL' : $row['sub_subject_id'];
        $key = $row['subject_id'] . '-' . $sub_subject_id;
        $existing_entries[$key] = [
            'id' => $row['id'],
            'subject_id' => $row['subject_id'],
            'sub_subject_id' => $row['sub_subject_id']
        ];
    }
    $stmt->close();
    error_log("Existing entries: " . json_encode($existing_entries), 3, 'errors.log');

    $success = true;
    $errors = [];

    foreach ($subject_ids as $subject_id) {
        if ($subject_id <= 0) {
            $errors[] = "Invalid subject ID: $subject_id";
            error_log("Invalid subject_id: $subject_id", 3, 'errors.log');
            $success = false;
            continue;
        }

        $is_composite = isset($entry_ids[$subject_id]) && is_array($entry_ids[$subject_id]);

        if ($is_composite) {
            // Composite subjects
            foreach ($entry_ids[$subject_id] as $sub_subject_id => $entry_id) {
                $entry_id = intval($entry_id);
                $key = $subject_id . '-' . $sub_subject_id;
                if (!$entry_id || !isset($existing_entries[$key]) || !isValidEntryId($conn, $entry_id, $arrange_exam_id)) {
                    $errors[] = "Invalid or non-existent entry ID $entry_id for subject ID $subject_id, sub-subject ID $sub_subject_id";
                    error_log("Invalid entry_id $entry_id for subject_id $subject_id, sub_subject_id $sub_subject_id, key=$key", 3, 'errors.log');
                    $success = false;
                    continue;
                }

                $start_date = $start_dates[$subject_id][$sub_subject_id] ?? '';
                $start_time = normalizeTime($start_times[$subject_id][$sub_subject_id] ?? '');
                $end_time = normalizeTime($end_times[$subject_id][$sub_subject_id] ?? '');

                if (empty($start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) ||
                    empty($start_time) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start_time) ||
                    empty($end_time) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $end_time)) {
                    $errors[] = "Invalid date/time format for subject ID $subject_id, sub-subject ID $sub_subject_id (date=$start_date, start=$start_time, end=$end_time)";
                    error_log("Invalid date/time for entry_id $entry_id: date=$start_date, start=$start_time, end=$end_time", 3, 'errors.log');
                    $success = false;
                    continue;
                }

                // Update entry
                $stmt = $conn->prepare("
                    UPDATE exam_datesheet 
                    SET start_date = ?, start_time = ?, end_time = ?
                    WHERE id = ? AND arrange_exam_id = ? AND subject_id = ? AND sub_subject_id = ?
                ");
                $stmt->bind_param("sssiiis", $start_date, $start_time, $end_time, $entry_id, $arrange_exam_id, $subject_id, $sub_subject_id);
                if ($stmt->execute()) {
                    error_log("Updated entry_id $entry_id for subject_id $subject_id, sub_subject_id $sub_subject_id", 3, 'errors.log');
                } else {
                    $errors[] = "Error updating entry ID $entry_id: " . $stmt->error;
                    error_log("Error updating entry_id $entry_id: " . $stmt->error, 3, 'errors.log');
                    $success = false;
                }
                $stmt->close();
            }
        } else {
            // Standalone subjects
            $entry_id = isset($entry_ids[$subject_id]) ? intval($entry_ids[$subject_id]) : 0;
            $key = $subject_id . '-NULL';
            if (!$entry_id || !isset($existing_entries[$key]) || !isValidEntryId($conn, $entry_id, $arrange_exam_id)) {
                $errors[] = "Invalid or non-existent entry ID $entry_id for subject ID $subject_id";
                error_log("Invalid entry_id $entry_id for subject_id $subject_id, key=$key", 3, 'errors.log');
                $success = false;
                continue;
            }

            $start_date = $start_dates[$subject_id] ?? '';
            $start_time = normalizeTime($start_times[$subject_id] ?? '');
            $end_time = normalizeTime($end_times[$subject_id] ?? '');

            if (empty($start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) ||
                empty($start_time) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start_time) ||
                empty($end_time) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $end_time)) {
                $errors[] = "Invalid date/time format for subject ID $subject_id (date=$start_date, start=$start_time, end=$end_time)";
                error_log("Invalid date/time for entry_id $entry_id: date=$start_date, start=$start_time, end=$end_time", 3, 'errors.log');
                $success = false;
                continue;
            }

            // Update entry
            $stmt = $conn->prepare("
                UPDATE exam_datesheet 
                SET start_date = ?, start_time = ?, end_time = ?
                WHERE id = ? AND arrange_exam_id = ? AND subject_id = ? AND sub_subject_id IS NULL
            ");
            $stmt->bind_param("sssiii", $start_date, $start_time, $end_time, $entry_id, $arrange_exam_id, $subject_id);
            if ($stmt->execute()) {
                error_log("Updated entry_id $entry_id for subject_id $subject_id", 3, 'errors.log');
            } else {
                $errors[] = "Error updating entry ID $entry_id: " . $stmt->error;
                error_log("Error updating entry_id $entry_id: " . $stmt->error, 3, 'errors.log');
                $success = false;
            }
            $stmt->close();
        }
    }

    if (!$success && !empty($errors)) {
        $_SESSION['message'] = implode("<br>", $errors);
        $_SESSION['message_type'] = 'danger';
    } elseif ($success) {
        $_SESSION['message'] = "Datesheet updated successfully.";
        $_SESSION['message_type'] = 'success';
    }
    return $success;
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
            return $data;
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
            return $data;
        }
    }
    $stmt->close();

    return $data;
}

/**
 * Fetches subjects and sub-subjects for a class
 * @param mysqli $conn Database connection
 * @param int $class_id Class ID
 * @return array Subject ID => [title, type, sub_subjects]
 */
function getSubjectsForClass($conn, $class_id) {
    $subjects = [];
    $stmt = $conn->prepare("
        SELECT s.id, s.title, s.type 
        FROM subjects s 
        JOIN subject_class sc ON s.id = sc.subject_id 
        WHERE sc.class_id = ? 
        ORDER BY s.title
    ");
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $subjects[$row['id']] = [
            'title' => $row['title'] ?? 'Untitled Subject',
            'type' => $row['type'],
            'sub_subjects' => []
        ];
    }
    $stmt->close();

    foreach ($subjects as $subject_id => &$subject) {
        if ($subject['type'] === 'composite') {
            $stmt = $conn->prepare("
                SELECT id, title 
                FROM sub_subjects 
                WHERE subject_id = ? 
                ORDER BY title
            ");
            $stmt->bind_param("i", $subject_id);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $subjects[$subject_id]['sub_subjects'][] = [
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
        ORDER BY s.title, ss.title
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
            if ($row['type'] !== 'composite' && $row['id']) {
                $datesheet[$subject_id]['id'] = $row['id'];
                $datesheet[$subject_id]['start_date'] = $row['start_date'];
                $datesheet[$subject_id]['start_time'] = $row['start_time'];
                $datesheet[$subject_id]['end_time'] = $row['end_time'];
            }
        }
        if ($row['sub_subject_id'] && $row['id']) {
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
    error_log("Datesheet data: " . json_encode($datesheet), 3, 'errors.log');
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
}

// Log page access
error_log("Accessing edit_datesheet.php with exam_id=$arrange_exam_id, class_id=$class_id, exam_type=$exam_type_title", 3, 'errors.log');

// Load exam and class details
$details = getExamDetails($conn, $arrange_exam_id, $class_id);
$exam = $details['exam'];
$class = $details['class'];

// Load datesheet
$datesheet = getDatesheet($conn, $arrange_exam_id);
$subjects = getSubjectsForClass($conn, $class_id);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("Processing form submission for arrange_exam_id=$arrange_exam_id", 3, 'errors.log');
    handleFormSubmission($conn, $_POST, $arrange_exam_id);
    // Reload datesheet to reflect updates
    $datesheet = getDatesheet($conn, $arrange_exam_id);
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Edit Datesheet - <?php echo htmlspecialchars($class['title'] . ' - ' . $exam_type_title); ?></title>
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
                Edit Datesheet for <?php echo htmlspecialchars($class['title'] ?? 'Unknown Class'); ?> - <?php echo htmlspecialchars($exam_type_title); ?>
            </h3>

            <?php if (isset($_SESSION['message'])): ?>
                <div class="alert alert-<?php echo $_SESSION['message_type']; ?>">
                    <?php 
                    echo $_SESSION['message']; 
                    unset($_SESSION['message'], $_SESSION['message_type']);
                    ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($datesheet)): ?>
                <div class="panel panel-primary">
                    <div class="panel-heading">
                        <h3 class="panel-title">Edit Datesheet</h3>
                    </div>
                    <div class="panel-body">
                        <form method="post" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            
                            <div class="form-group form-table-container">
                                <label>Update Dates and Times:</label>
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
                                            <?php foreach ($datesheet as $subject_id => $ds): ?>
                                                <?php if (!isset($ds['id']) && empty($ds['sub_subjects'])) continue; ?>
                                                <tr class="subject-row" data-subject-id="<?php echo $subject_id; ?>">
                                                    <td class="subject-sno"><?php echo $counter++; ?></td>
                                                    <td class="subject-title">
                                                        <?php echo formatSubjectTitle($ds); ?>
                                                        <input type="hidden" name="subject_ids[]" value="<?php echo $subject_id; ?>">
                                                    </td>
                                                    <?php if ($ds['type'] !== 'composite' && isset($ds['id'])): ?>
                                                        <td>
                                                            <input type="date" class="form-control date-input start-date" 
                                                                   name="start_dates[<?php echo $subject_id; ?>]" 
                                                                   value="<?php echo htmlspecialchars($ds['start_date'] ?? ''); ?>" 
                                                                   placeholder="Select Start Date" required>
                                                            <input type="hidden" name="entry_ids[<?php echo $subject_id; ?>]" 
                                                                   value="<?php echo htmlspecialchars($ds['id'] ?? '0'); ?>">
                                                        </td>
                                                        <td>
                                                            <input type="time" class="form-control time-input start-time" 
                                                                   name="start_times[<?php echo $subject_id; ?>]" 
                                                                   value="<?php echo htmlspecialchars(substr($ds['start_time'] ?? '', 0, 5)); ?>" 
                                                                   placeholder="Start Time" required>
                                                        </td>
                                                        <td>
                                                            <input type="time" class="form-control time-input end-time" 
                                                                   name="end_times[<?php echo $subject_id; ?>]" 
                                                                   value="<?php echo htmlspecialchars(substr($ds['end_time'] ?? '', 0, 5)); ?>" 
                                                                   placeholder="End Time" required>
                                                        </td>
                                                    <?php else: ?>
                                                        <td></td><td></td><td></td>
                                                    <?php endif; ?>
                                                </tr>
                                                <?php if ($ds['type'] === 'composite'): ?>
                                                    <?php foreach ($ds['sub_subjects'] as $sub_ds): ?>
                                                        <?php if (!isset($sub_ds['id'])) continue; ?>
                                                        <tr class="sub-subject-row" data-subject-id="<?php echo $subject_id; ?>">
                                                            <td></td>
                                                            <td><?php echo htmlspecialchars($sub_ds['sub_subject_title'] ?? 'Untitled Sub-Subject'); ?></td>
                                                            <td>
                                                                <input type="date" class="form-control date-input start-date" 
                                                                       name="start_dates[<?php echo $subject_id; ?>][<?php echo $sub_ds['sub_subject_id']; ?>]" 
                                                                       value="<?php echo htmlspecialchars($sub_ds['start_date'] ?? ''); ?>" 
                                                                       placeholder="Select Start Date" required>
                                                                <input type="hidden" name="entry_ids[<?php echo $subject_id; ?>][<?php echo $sub_ds['sub_subject_id']; ?>]" 
                                                                       value="<?php echo htmlspecialchars($sub_ds['id'] ?? '0'); ?>">
                                                            </td>
                                                            <td>
                                                                <input type="time" class="form-control time-input start-time" 
                                                                       name="start_times[<?php echo $subject_id; ?>][<?php echo $sub_ds['sub_subject_id']; ?>]" 
                                                                       value="<?php echo htmlspecialchars(substr($sub_ds['start_time'] ?? '', 0, 5)); ?>" 
                                                                       placeholder="Start Time" required>
                                                            </td>
                                                            <td>
                                                                <input type="time" class="form-control time-input end-time" 
                                                                       name="end_times[<?php echo $subject_id; ?>][<?php echo $sub_ds['sub_subject_id']; ?>]" 
                                                                       value="<?php echo htmlspecialchars(substr($sub_ds['end_time'] ?? '', 0, 5)); ?>" 
                                                                       placeholder="End Time" required>
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
                                <button type="submit" class="btn btn-success" onclick="return confirm('Are you sure you want to update the datesheet?')">
                                    <span class="glyphicon glyphicon-refresh"></span> Update Datesheet
                                </button>
                                <a href="datesheet_list.php" class="btn btn-default">
                                    <span class="glyphicon glyphicon-remove"></span> Back
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-info text-center">
                    No datesheet entries found to edit.
                </div>
                <div class="text-right">
                    <a href="datesheet_list.php" class="btn btn-default">
                        <span class="glyphicon glyphicon-remove"></span> Back
                    </a>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Auto-dismiss alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);

    // Sync all start times
    $('.start-time').first().change(function() {
        var selectedTime = $(this).val();
        if (selectedTime && selectedTime.match(/^\d{2}:\d{2}$/)) {
            $('.start-time').not(this).val(selectedTime);
        }
    });

    // Sync all end times
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