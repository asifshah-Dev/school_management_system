<?php
ob_start();
header('Content-Type: text/html; charset=utf-8');
require_once('security.php');
require_once('conn_inc.php');

// Function to convert number to ordinal (1st, 2nd, 3rd, etc.)
function numberToOrdinal($number) {
    if ($number == 'Not Ranked') {
        return $number;
    }
    $ends = ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'];
    if (($number % 100) >= 11 && ($number % 100) <= 13) {
        return $number . 'th';
    }
    return $number . $ends[$number % 10];
}

// Fetch class-sections for dropdown — sorted by most recent exam first
$class_sections = $conn->query("
    SELECT
        c.id AS class_id,
        c.title AS class_name,
        s.title AS section_name,
        et.title AS exam_type,
        ae.id AS arrange_exam_id,
        ae.created_at
    FROM classes c
    cross JOIN class_sections cs ON cs.class_id = c.id
    LEFT JOIN sections s ON cs.section_id = s.id
    JOIN arrange_exam ae ON ae.class_id = c.id
    JOIN exam_types et ON et.id = ae.exam_type_id
    ORDER BY ae.created_at DESC, c.title, s.title
");

// Fetch students with results count and total marks
$students = [];
$selected_arrange_exam_id = isset($_GET['arrange_exam_id']) ? (int)$_GET['arrange_exam_id'] : 0;
$class_id = 0;

if ($selected_arrange_exam_id > 0) {
    $class_stmt = $conn->prepare("SELECT class_id FROM arrange_exam WHERE id = ?");
    $class_stmt->bind_param("i", $selected_arrange_exam_id);
    $class_stmt->execute();
    $result = $class_stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $class_id = $row['class_id'];
    }
    $class_stmt->close();
}

// If no exam is selected yet, auto-select the most recent one
if ($selected_arrange_exam_id === 0 && $class_sections->num_rows > 0) {
    $class_sections->data_seek(0); // reset pointer
    $first_row = $class_sections->fetch_assoc();
    $selected_arrange_exam_id = $first_row['arrange_exam_id'];
    $class_id = $first_row['class_id'];
    // Redirect to load with selected value (optional, clean URL)
    // header("Location: " . $_SERVER['PHP_SELF'] . "?arrange_exam_id=" . $selected_arrange_exam_id);
    // exit;
}

if ($class_id > 0) {
    $stmt = $conn->prepare("
        SELECT sr.id, sr.name, sr.current_address, sr.father_name,
               COUNT(r.id) AS result_count,
               COALESCE(SUM(r.marks), 0) AS total_obtained
        FROM student_class sc
        JOIN student_registration sr ON sc.student_registration_id = sr.id
        JOIN sessions s ON s.id = sc.session_id
        LEFT JOIN results r ON sr.id = r.student_id AND r.arrange_exam_id = ?
        WHERE sc.class_id = ?
          AND sr.status = 0
          AND sc.status = 0
        GROUP BY sr.id, sr.name, sr.current_address, sr.father_name
        ORDER BY total_obtained DESC, sr.name
    ");
    $stmt->bind_param("ii", $selected_arrange_exam_id, $class_id);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Calculate positions (dense ranking)
    $rank = 1;
    $prev_marks = null;
    foreach ($students as &$student) {
        if ($student['total_obtained'] == 0) {
            $student['position'] = 'Not Ranked';
        } else {
            if ($prev_marks !== null && $student['total_obtained'] < $prev_marks) {
                $rank++;
            }
            $student['position'] = ($student['result_count'] > 0) ? $rank : 'Not Ranked';
        }
        $prev_marks = $student['total_obtained'];
    }
    unset($student);

    // Debugging
    error_log("Students for arrange_exam_id $selected_arrange_exam_id: " . print_r($students, true));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once('meta_inc.php'); ?>
    <style>
        /* Your existing styles remain unchanged */
        .container { padding: 20px; max-width: 100%; margin: 0 auto; }
        .form-group { margin-bottom: 25px; }
        .form-control { width: 100%; padding: 14px; font-size: 18px; border-radius: 4px; }
        .btn { padding: 14px 24px; font-size: 16px; margin: 8px 0; width: 100%; box-sizing: border-box; }
        .btn-sm { padding: 10px 16px; font-size: 14px; width: auto; display: inline-block; }
        .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .table { width: 100%; margin-bottom: 0; }
        .table th, .table td { padding: 14px; font-size: 16px; vertical-align: middle; }
        /* ... rest of your media queries and styles unchanged ... */
    </style>
</head>
<body>
    <?php require_once('navbar.php'); ?>

    <div class="container">
        <div class="row">
            <div class="col-md-12">

                <!-- Panel Select Class-Section -->
                <div class="panel panel-primary">
                    <div class="panel-heading"><h3 class="panel-title">Select Class and Section</h3></div>
                    <div class="panel-body">
                        <?php if ($class_sections->num_rows > 0): ?>
                            <form method="get" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                                <div class="form-group">
                                    <label>Select Class-Section (Most recent exam shown first)</label>
                                    <select name="arrange_exam_id" class="form-control" onchange="this.form.submit()" required>
                                        <option value="">-- Select Class-Section --</option>
                                        <?php 
                                        $class_sections->data_seek(0); // reset pointer
                                        while ($cs = $class_sections->fetch_assoc()): ?>
                                            <option value="<?php echo $cs['arrange_exam_id']; ?>" 
                                                    <?php echo $selected_arrange_exam_id == $cs['arrange_exam_id'] ? 'selected' : ''; ?>>
                                                <?php 
                                                echo htmlspecialchars($cs['class_name']) . 
                                                     ($cs['section_name'] ? ' - ' . htmlspecialchars($cs['section_name']) : '') . 
                                                     ' - ' . htmlspecialchars($cs['exam_type']); 
                                                ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="alert alert-warning">No class-sections or exams available.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Panel Student List -->
                <div class="panel panel-info">
                    <div class="panel-heading">
                        <h3 class="panel-title" style="display: inline-block;">Students List</h3>
                        <?php if ($selected_arrange_exam_id > 0): ?>
                            <a href="dmc_print_class.php?arrange_exam_id=<?php echo $selected_arrange_exam_id; ?>" 
                               class="btn btn-primary btn-sm pull-right" style="margin-top: -5px;">Print DMC Class Wise</a>
                        <?php endif; ?>
                    </div>
                    <div class="panel-body">
                        <?php if ($selected_arrange_exam_id > 0): ?>
                            <?php if (count($students) > 0): ?>
                                <table class="table table-bordered table-hover">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Father's Name</th>
                                            <th>Position</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($students as $student): ?>
                                            <tr>
                                                <td data-label="Name"><?php echo htmlspecialchars($student['name']); ?></td>
                                                <td data-label="Father's Name"><?php echo htmlspecialchars($student['father_name']); ?></td>
                                                <td data-label="Position">
                                                    <span style="font-size:20px; font-weight: bolder;">
                                                        <?php echo numberToOrdinal($student['position']); ?>
                                                    </span>
                                                </td>
                                                <td data-label="Actions">
                                                    <?php if ($student['result_count'] > 0): ?>
                                                        <a href="dmc_print.php?student_id=<?php echo $student['id']; ?>&arrange_exam_id=<?php echo $selected_arrange_exam_id;?>" 
                                                           class="btn btn-success btn-sm">Print DMC</a>
                                                        <a href="edit_dmc.php?student_id=<?php echo $student['id']; ?>&arrange_exam_id=<?php echo $selected_arrange_exam_id; ?>" 
                                                           class="btn btn-warning btn-sm">Edit DMC</a>
                                                    <?php else: ?>
                                                        <span class="label label-warning">Marks are not entered</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="alert alert-info">No students found for this exam arrangement. Check if students are assigned.</div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="alert alert-info">Please select a class-section/exam to view students.</div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
    </div>
</body>
</html>