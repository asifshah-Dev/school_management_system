<?php
/**
 * File: view_datesheet.php
 * Purpose: Displays a read-only view of the datesheet for a specific class and exam.
 * Features:
 * - Shows exam title with class and exam type.
 * - Displays a table with S.No, Subjects, Start Date, Start Time, End Time.
 * - Subjects are displayed in bold; composite subjects have sub-subject rows.
 * - Includes a Back button to return to datesheet_list.php.
 * - Uses Bootstrap 3.4.1 for styling consistency.
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
            header("Location: datesheet_list.php");
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
            header("Location: datesheet_list.php");
            exit();
        }
    }
    $stmt->close();

    return $data;
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
    $title = isset($subject['subject_title']) ? htmlspecialchars($subject['subject_title']) : 'Untitled Subject';
    $title .= ' (' . $subject['type'];
    if ($subject['type'] === 'composite' && !empty($subject['sub_subjects'])) {
        $sub_titles = array_map(function($sub) {
            return htmlspecialchars($sub['sub_subject_title'] ?? 'Untitled Sub-Subject');
        }, $subject['sub_subjects']);
        $title .= ': ' . implode(', ', $sub_titles);
    }
    $title .= ')';
    return $title;
}

// Validate URL parameters
$arrange_exam_id = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : 0;
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$exam_type_title = isset($_GET['exam_type']) ? htmlspecialchars($_GET['exam_type']) : '';

if ($arrange_exam_id <= 0 || $class_id <= 0) {
    error_log("Invalid URL parameters: arrange_exam_id=$arrange_exam_id, class_id=$class_id", 3, 'errors.log');
    $_SESSION['message'] = "Invalid exam or class ID.";
    $_SESSION['message_type'] = 'danger';
    header("Location: datesheet_list.php");
    exit();
}

// Log page access
error_log("Accessing view_datesheet.php with exam_id=$arrange_exam_id, class_id=$class_id, exam_type=$exam_type_title", 3, 'errors.log');

// Load exam and class details
$details = getExamDetails($conn, $arrange_exam_id, $class_id);
$exam = $details['exam'];
$class = $details['class'];

// Load datesheet
$datesheet = getDatesheet($conn, $arrange_exam_id);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>View Datesheet - <?php echo htmlspecialchars($class['title'] . ' - ' . $exam_type_title); ?></title>
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
                Datesheet for <?php echo htmlspecialchars($class['title']); ?> - <?php echo htmlspecialchars($exam_type_title); ?>
            </h3>

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
                                            <?php else: ?>
                                                <td></td><td></td><td></td>
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
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info text-center">
                            No datesheet entries found.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="text-right">
                <a href="datesheet_list.php" class="btn btn-default">
                    <span class="glyphicon glyphicon-remove"></span> Back to Datesheet List
                </a>
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
});
</script>

</body>
</html>
<?php
$conn->close();
?>