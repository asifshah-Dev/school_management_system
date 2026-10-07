<?php
require_once('security.php');
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once('conn_inc.php');

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Initialize variables
$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$section_id = isset($_GET['section_id']) ? intval($_GET['section_id']) : 0; // class_section_id
$arrange_exam_id = isset($_GET['arrange_exam_id']) ? intval($_GET['arrange_exam_id']) : 0;
$messages = [];

// Validate inputs
if ($student_id <= 0 || $class_id <= 0 || $section_id <= 0 || $arrange_exam_id <= 0) {
    $messages[] = ['text' => 'Invalid parameters.', 'type' => 'danger'];
}

// Fetch student, class, and section details
$student_name = '';
$class_title = '';
$section_title = '';
$exam_title = '';

if (empty($messages)) {
    $stmt = $conn->prepare("
        SELECT sr.name, c.title AS class_title, s.title AS section_title
        FROM student_registration sr
        JOIN student_class sc ON sr.id = sc.student_registration_id
        JOIN class_sections cs ON sc.class_id = cs.id
        JOIN classes c ON cs.class_id = c.id
        JOIN sections s ON cs.section_id = s.id
        WHERE sr.id = ? AND sc.class_id = ?
    ");
    $stmt->bind_param('ii', $student_id, $section_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $student_name = $row['name'];
        $class_title = $row['class_title'];
        $section_title = $row['section_title'];
    } else {
        $messages[] = ['text' => 'Invalid student, class, or section.', 'type' => 'danger'];
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT e.title FROM arrange_exam ae JOIN exam_types e ON ae.exam_type_id = e.id WHERE ae.id = ?");
    $stmt->bind_param("i", $arrange_exam_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $exam_title = $row['title'];
    } else {
        $messages[] = ['text' => 'Invalid exam.', 'type' => 'danger'];
    }
    $stmt->close();
}

// Fetch subjects and sub-subjects along with existing marks
$subjects = [];
if (empty($messages)) {
    
    $stmt = $conn->prepare("
       SELECT 
    sc.id AS subject_class_id,
    s.id AS subject_id,
    s.title AS subject_title,
    s.type,
    sc.marks AS subject_total_marks,
    ss.id AS sub_subject_id,
    ss.title AS sub_subject_title,
    scs.marks AS sub_subject_total_marks
FROM subject_class sc
JOIN subjects s ON sc.subject_id = s.id
JOIN class_sections cs ON sc.class_id = cs.class_id
LEFT JOIN subject_class_sub scs ON sc.id = scs.subject_class_id
LEFT JOIN sub_subjects ss ON scs.sub_subject_id = ss.id
WHERE cs.id = ?
ORDER BY s.title, ss.title;

    ");

    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $temp_subjects = [];
    while ($row = $result->fetch_assoc()) {
        $subject_id = $row['subject_id'];
        if (!isset($temp_subjects[$subject_id])) {
            $temp_subjects[$subject_id] = [
                'id' => $subject_id,
                'title' => $row['subject_title'],
                'type' => $row['type'],
                'total_marks' => $row['subject_total_marks'],
                'marks' => null,
                'sub_subjects' => []
            ];
        }

        // If main subject (no sub-subject)
        if (empty($row['sub_subject_id'])) {
            $temp_subjects[$subject_id]['marks'] = $row['obtained_marks'] ?? '';
        } else { // sub-subject
            $temp_subjects[$subject_id]['sub_subjects'][] = [
                'id' => $row['sub_subject_id'],
                'title' => $row['sub_subject_title'],
                'total_marks' => $row['sub_subject_total_marks'],
                'marks' => $row['obtained_marks'] ?? ''
            ];
        }
    }
    $subjects = array_values($temp_subjects);
    $stmt->close();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_results'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $messages[] = ['text' => 'Invalid CSRF token.', 'type' => 'danger'];
    } else {
        $marks = $_POST['marks'] ?? [];
        $conn->begin_transaction();
        try {
            foreach ($marks as $subject_id => $sub_marks) {
                foreach ($sub_marks as $sub_subject_id => $obtained_marks) {
                    $obtained_marks = floatval($obtained_marks);
                    if ($obtained_marks < 0) continue;

                    $stmt = $conn->prepare("SELECT id FROM results WHERE student_id = ? AND arrange_exam_id = ? AND subject_id = ? AND sub_subject_id = ?");
                    $stmt->bind_param("iiii", $student_id, $arrange_exam_id, $subject_id, $sub_subject_id);
                    $stmt->execute();
                    $res = $stmt->get_result();

                    if ($res->num_rows > 0) {
                        $stmt = $conn->prepare("UPDATE results SET marks = ? WHERE student_id = ? AND arrange_exam_id = ? AND subject_id = ? AND sub_subject_id = ?");
                        $stmt->bind_param("diiii", $obtained_marks, $student_id, $arrange_exam_id, $subject_id, $sub_subject_id);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO results (student_id, arrange_exam_id, subject_id, sub_subject_id, marks) VALUES (?, ?, ?, ?, ?)");
                        $stmt->bind_param("iiiid", $student_id, $arrange_exam_id, $subject_id, $sub_subject_id, $obtained_marks);
                    }
                    $stmt->execute();
                }
            }
            $conn->commit();
            $select = mysqli_query($conn, "SELECT class_id FROM `class_sections` WHERE id = '$class_id'");
            $row = mysqli_fetch_assoc($select);
            $new_class_id = $row['class_id'];
            // ✅ Redirect back to result_entry.php
            header("Location: result_entry.php?exam_id={$arrange_exam_id}&class_id={$new_class_id}&exam_type_id={$_GET['exam_type_id']}&section_id={$section_id}");
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            $messages[] = ['text' => 'Error: ' . $e->getMessage(), 'type' => 'danger'];
            error_log("SQL Error: " . $e->getMessage(), 3, 'errors.log');
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Enter Results</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
    <style>
    .table th,
    .table td {
        text-align: left;
    }

    .alert {
        margin-bottom: 20px;
    }
    </style>
</head>

<body>

    <?php require_once('navbar.php'); ?>

    <div class="container">
        <div class="row">
            <div class="col-md-10 col-md-offset-1">
                <h2>Enter Results for <?php echo htmlspecialchars($student_name); ?></h2>

                <!-- Messages -->
                <?php if (!empty($messages)): ?>
                <?php foreach ($messages as $msg): ?>
                <div class="alert alert-<?php echo $msg['type']; ?>">
                    <?php echo $msg['text']; ?>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <!-- Student Info -->
                <?php if (empty($messages) || !in_array('danger', array_column($messages, 'type'))): ?>
                <p><strong>Class:</strong> <?php echo htmlspecialchars($class_title . ' - ' . $section_title); ?></p>
                <p><strong>Exam:</strong> <?php echo htmlspecialchars($exam_title); ?></p>

                <!-- Result Entry Form -->
                <form method="post" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                    <input type="hidden" name="arrange_exam_id" value="<?php echo $arrange_exam_id; ?>">
                    <div class="table-responsive">
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>Subject / Sub-Subject</th>
                <th>Total Marks</th>
                <th>Obtained Marks</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($subjects as $subject): ?>
                <!-- Main Subject Row -->
                <tr>
                    <td><strong><?php echo htmlspecialchars($subject['title']); ?></strong></td>
                    <td>
                        <?php if (count($subject['sub_subjects']) > 0): ?>
                            <!-- Parent with children: leave total marks empty -->
                            <?php echo ''; ?>
                        <?php else: ?>
                            <!-- Show only if > 0 -->
                            <?php echo ($subject['total_marks'] > 0) 
                                ? htmlspecialchars($subject['total_marks']) 
                                : ''; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (count($subject['sub_subjects']) == 0): ?>
                            <!-- Only show input if no sub-subjects -->
                            <input type="number" 
                                   name="marks[<?php echo $subject['id']; ?>][0]" 
                                   value="<?php echo htmlspecialchars($subject['marks']); ?>" 
                                   min="0" 
                                   max="<?php echo $subject['total_marks']; ?>" 
                                   step="0.01" 
                                   class="form-control obtained-marks" required>
                        <?php endif; ?>
                    </td>
                </tr>

                <!-- Sub-Subjects -->
                <?php foreach ($subject['sub_subjects'] as $sub_subject): ?>
                    <tr>
                        <td>&nbsp;&nbsp;— <?php echo htmlspecialchars($sub_subject['title']); ?></td>
                        <td>
                            <?php echo ($sub_subject['total_marks'] > 0) 
                                ? htmlspecialchars($sub_subject['total_marks']) 
                                : ''; ?>
                        </td>
                        <td>
                            <input type="number" 
                                   name="marks[<?php echo $subject['id']; ?>][<?php echo $sub_subject['id']; ?>]" 
                                   value="<?php echo htmlspecialchars($sub_subject['marks']); ?>" 
                                   min="0" 
                                   max="<?php echo $sub_subject['total_marks']; ?>" 
                                   step="0.01" 
                                   class="form-control obtained-marks" required>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>




                    <div class="text-right">
                        <button type="submit" name="save_results" class="btn btn-success">Save Results</button>
                        <a href="result_entry.php?exam_id=<?php echo $arrange_exam_id; ?>&class_id=<?php echo $class_id; ?>&exam_type_id=<?php echo $_GET['exam_type_id'] ?? 0; ?>"
                            class="btn btn-default">Back</a>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

</body>

</html>
<script>
$(document).ready(function() {
    $('.obtained-marks').on('input', function() {
        var max = parseFloat($(this).attr('max'));
        var val = parseFloat($(this).val());

        if (!isNaN(max) && !isNaN(val) && val > max) {
            $(this).val(max); // automatically set to max
        } else if (!isNaN(val) && val < 0) {
            $(this).val(0); // prevent negative
        }
    });
});
</script>

<?php
$conn->close();
?>