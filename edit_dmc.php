<?php
ob_start();
header('Content-Type: text/html; charset=utf-8');
require_once('security.php');
require_once('conn_inc.php');

// Initialize variables
$student_id      = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$arrange_exam_id = isset($_GET['arrange_exam_id']) ? (int)$_GET['arrange_exam_id'] : 0;

$student_info    = null;
$marks_list      = [];
$success_message = '';
$error_message   = '';

// Require arrange_exam_id
if ($arrange_exam_id <= 0) {
    $error_message = "Exam arrangement ID is required.";
}

// Fetch student info
if ($student_id > 0) {
    $stmt = $conn->prepare("
        SELECT sr.name, sr.father_name, c.title AS class_name, s.title AS section_name, c.id AS class_section_id
        FROM student_registration sr
        JOIN student_class sc ON sr.id = sc.student_registration_id
        LEFT JOIN classes c ON sc.class_id = c.id
        LEFT JOIN class_sections cs ON cs.class_id = c.id
        LEFT JOIN sections s ON cs.section_id = s.id
        WHERE sr.id = ? AND sc.status = 0 AND sr.status = 0
    ");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $student_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Debugging
    error_log("Student info for student_id $student_id: " . print_r($student_info, true));
}

// Handle edit marks — now only for this specific exam
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $result_id = (int)$_POST['result_id'];
    $marks     = isset($_POST['marks']) ? trim($_POST['marks']) : '';

    if (is_numeric($marks) && $marks >= 0 && $marks <= 100) {
        $stmt = $conn->prepare("
            UPDATE results
            SET marks = ?
            WHERE id = ? 
              AND student_id = ? 
              AND arrange_exam_id = ?
        ");
        $stmt->bind_param("diii", $marks, $result_id, $student_id, $arrange_exam_id);
        if ($stmt->execute()) {
            $success_message = "Marks updated successfully for this exam.";
        } else {
            $error_message = "Failed to update marks.";
        }
        $stmt->close();
    } else {
        $error_message = "Invalid marks. Must be a number between 0 and 100.";
    }
}

// Handle delete marks — only for this exam
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $result_id = (int)$_POST['result_id'];
    $stmt = $conn->prepare("
        DELETE FROM results
        WHERE id = ? 
          AND student_id = ? 
          AND arrange_exam_id = ?
    ");
    $stmt->bind_param("iii", $result_id, $student_id, $arrange_exam_id);
    if ($stmt->execute()) {
        $success_message = "Marks deleted successfully for this exam.";
    } else {
        $error_message = "Failed to delete marks.";
    }
    $stmt->close();
}

// Fetch marks list — only for this specific exam
if ($student_id > 0 && $arrange_exam_id > 0) {
    $stmt = $conn->prepare("
        SELECT 
            r.id, 
            r.subject_class_id, 
            r.subject_class_sub_id, 
            r.marks,
            scs.title AS subject_name,
            COALESCE(subs.title, '') AS sub_subject_name
        FROM results r
        JOIN subject_class sc ON r.subject_class_id = sc.id
        JOIN subjects scs ON sc.subject_id = scs.id
        LEFT JOIN subject_class_sub scsub ON r.subject_class_sub_id = scsub.id
        LEFT JOIN sub_subjects subs ON scsub.sub_subject_id = subs.id
        WHERE r.student_id = ? 
          AND r.arrange_exam_id = ?
        ORDER BY r.subject_class_id, r.subject_class_sub_id
    ");
    $stmt->bind_param("ii", $student_id, $arrange_exam_id);
    $stmt->execute();
    $marks_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Debugging
    error_log("Marks for student_id $student_id (exam $arrange_exam_id): " . print_r($marks_list, true));
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <?php require_once('meta_inc.php'); ?>
</head>

<body>
    <?php require_once('navbar.php'); ?>

    <div class="container">
        <div class="row">
            <div class="col-md-12">

                <!-- Student Info Panel -->
                <div class="panel panel-primary">
                    <div class="panel-heading">
                        <h3 class="panel-title">Student Information</h3>
                    </div>
                    <div class="panel-body">
                        <?php if ($student_info): ?>
                        <p><strong>Name:</strong> <?php echo htmlspecialchars($student_info['name']); ?></p>
                        <p><strong>F.Name:</strong> <?php echo htmlspecialchars($student_info['father_name']); ?></p>
                        <p><strong>Class:</strong> <?php echo htmlspecialchars($student_info['class_name']); ?></p>
                        
                        <a href="dmc_list.php?arrange_exam_id=<?php echo $arrange_exam_id; ?>"
                           class="btn btn-default btn-sm">Back to List</a>
                        <?php else: ?>
                        <div class="alert alert-danger">Student not found.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Messages -->
                <?php if ($success_message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
                <?php endif; ?>
                <?php if ($error_message): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>

                <!-- Marks List Panel -->
                <div class="panel panel-info">
                    <div class="panel-heading">
                        <h3 class="panel-title">Edit Marks</h3>
                    </div>
                    <div class="panel-body">
                        <?php if ($student_id > 0 && $student_info && $arrange_exam_id > 0): ?>
                        <?php if (count($marks_list) > 0): ?>
                        <table class="table table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Subject</th>
                                    <th>Sub-Subject/Marks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $i = 1;
                                $current_subject_id = null;
                                foreach ($marks_list as $mark) {
                                    $display_subject = ($mark['subject_class_id'] !== $current_subject_id);
                                    $current_subject_id = $mark['subject_class_id'];
                                ?>
                                <tr>
                                    <td><?php echo $i++; ?></td>
                                    <td><?php echo $display_subject ? htmlspecialchars($mark['subject_name']) : ''; ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($mark['sub_subject_name'] ?: '(Main Subject)'); ?>
                                        <form method="post"
                                              action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?student_id=' . $student_id . '&arrange_exam_id=' . $arrange_exam_id; ?>">
                                            <input type="hidden" name="action" value="edit">
                                            <input type="hidden" name="result_id" value="<?php echo $mark['id']; ?>">
                                            <input type="number" name="marks"
                                                   value="<?php echo htmlspecialchars($mark['marks']); ?>"
                                                   class="form-control input-sm" style="width: 100px;" min="0" max="100"
                                                   required>
                                            <button type="submit" class="btn btn-primary btn-sm"
                                                    style="margin-top: 5px;">Save</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <div class="alert alert-info">No marks entered yet for this student in the selected exam.</div>
                        <?php endif; ?>
                        <?php else: ?>
                        <div class="alert alert-info">Please select a valid student and exam arrangement.</div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
    </div>
</body>

</html>