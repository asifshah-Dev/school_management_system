<?php
require_once('../security.php');
require_once('../conn_inc.php');

header('Content-Type: text/html; charset=utf-8');

$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
$arrange_exam_id = isset($_GET['arrange_exam_id']) ? intval($_GET['arrange_exam_id']) : 0;
$student_class_id = isset($_GET['student_class_id']) ? intval($_GET['student_class_id']) : 0;

if ($student_id > 0 && $arrange_exam_id > 0 && $student_class_id > 0) {
    // Get student basic info
    $stmt = $conn->prepare("
        SELECT sr.name, sr.father_name, sr.reg_no, 
               c.title AS class_name, s.title AS session_name,
               et.title AS exam_type_name
        FROM student_registration sr
        INNER JOIN student_class sc ON sr.id = sc.student_registration_id
        INNER JOIN classes c ON sc.class_id = c.id
        INNER JOIN sessions s ON sc.session_id = s.id
        INNER JOIN arrange_exam ae ON ae.class_id = sc.class_id
        INNER JOIN exam_types et ON ae.exam_type_id = et.id
        WHERE sr.id = ? AND sc.id = ? AND ae.id = ?
        LIMIT 1
    ");
    
    if ($stmt) {
        $stmt->bind_param("iii", $student_id, $student_class_id, $arrange_exam_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $student_info = $result->fetch_assoc();
            $stmt->close();
            
            // Get subject-wise marks
            $query = "
                SELECT 
                    sub.title AS subject_name,
                    COALESCE(SUM(r.marks), 0) AS marks_obtained,
                    sc.marks AS total_marks,
                    CASE 
                        WHEN sc.marks > 0 THEN ROUND((COALESCE(SUM(r.marks), 0) / sc.marks * 100), 2)
                        ELSE 0 
                    END AS percentage,
                    GROUP_CONCAT(DISTINCT ss.title SEPARATOR ', ') AS sub_subjects
                FROM subject_class sc
                LEFT JOIN subjects sub ON sc.subject_id = sub.id
                LEFT JOIN results r ON sc.id = r.subject_class_id 
                    AND r.student_id = ? 
                    AND r.arrange_exam_id = ?
                    AND r.status = 0
                LEFT JOIN subject_class_sub scs ON sc.id = scs.subject_class_id
                LEFT JOIN sub_subjects ss ON scs.sub_subject_id = ss.id
                WHERE sc.class_id = (
                    SELECT class_id FROM student_class WHERE id = ?
                )
                AND sc.status = 0
                GROUP BY sc.id, sub.title, sc.marks
                ORDER BY sub.title
            ";
            
            $stmt = $conn->prepare($query);
            if ($stmt) {
                $stmt->bind_param("iii", $student_id, $arrange_exam_id, $student_class_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                $subjects = [];
                $total_obtained = 0;
                $total_possible = 0;
                
                while ($row = $result->fetch_assoc()) {
                    $subjects[] = $row;
                    $total_obtained += $row['marks_obtained'];
                    $total_possible += $row['total_marks'];
                }
                $stmt->close();
                
                $overall_percentage = $total_possible > 0 ? round(($total_obtained / $total_possible) * 100, 2) : 0;
                ?>
                
                <div class="student-details">
                    <div class="row">
                        <div class="col-md-12">
                            <h4><?php echo htmlspecialchars($student_info['name']); ?></h4>
                            <p>
                                <strong>Father:</strong> <?php echo htmlspecialchars($student_info['father_name']); ?><br>
                                <strong>Reg No:</strong> <?php echo htmlspecialchars($student_info['reg_no']); ?><br>
                                <strong>Class:</strong> <?php echo htmlspecialchars($student_info['class_name']); ?><br>
                                <strong>Session:</strong> <?php echo htmlspecialchars($student_info['session_name']); ?><br>
                                <strong>Exam:</strong> <?php echo htmlspecialchars($student_info['exam_type_name']); ?>
                            </p>
                            
                            <div class="alert alert-<?php echo $overall_percentage < 50 ? 'warning' : 'success'; ?>">
                                <h5>Overall Performance:</h5>
                                <div class="progress">
                                    <div class="progress-bar progress-bar-<?php echo $overall_percentage < 50 ? 'warning' : 'success'; ?>" 
                                         role="progressbar" 
                                         style="width: <?php echo min($overall_percentage, 100); ?>%">
                                        <?php echo $overall_percentage; ?>%
                                    </div>
                                </div>
                                <p>
                                    <strong>Obtained Marks:</strong> <?php echo $total_obtained; ?> /
                                    <strong>Total Marks:</strong> <?php echo $total_possible; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (count($subjects) > 0): ?>
                        <div class="row">
                            <div class="col-md-12">
                                <h5>Subject-wise Marks:</h5>
                                <table class="table table-bordered table-hover">
                                    <thead>
                                        <tr>
                                            <th>Subject</th>
                                            <th>Obtained Marks</th>
                                            <th>Total Marks</th>
                                            <th>Percentage</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($subjects as $subject): ?>
                                            <?php
                                            $subject_status = '';
                                            $subject_class = '';
                                            if ($subject['total_marks'] > 0) {
                                                $pass_percentage = ($subject['marks_obtained'] / $subject['total_marks']) * 100;
                                                if ($pass_percentage < 33) {
                                                    $subject_status = 'Fail';
                                                    $subject_class = 'danger';
                                                } elseif ($pass_percentage < 50) {
                                                    $subject_status = 'Below Avg';
                                                    $subject_class = 'warning';
                                                } else {
                                                    $subject_status = 'Pass';
                                                    $subject_class = 'success';
                                                }
                                            }
                                            ?>
                                            <tr class="<?php echo $subject_class; ?>">
                                                <td>
                                                    <strong><?php echo htmlspecialchars($subject['subject_name']); ?></strong>
                                                    <?php if (!empty($subject['sub_subjects'])): ?>
                                                        <br><small><?php echo htmlspecialchars($subject['sub_subjects']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo $subject['marks_obtained']; ?></td>
                                                <td><?php echo $subject['total_marks']; ?></td>
                                                <td>
                                                    <div class="progress" style="height: 15px; margin-bottom: 0;">
                                                        <div class="progress-bar progress-bar-<?php echo $subject_class; ?>" 
                                                             style="width: <?php echo min($subject['percentage'], 100); ?>%">
                                                            <?php if ($subject['percentage'] > 10): ?>
                                                                <?php echo $subject['percentage']; ?>%
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="label label-<?php echo $subject_class; ?>">
                                                        <?php echo $subject_status; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            No subject marks found for this student.
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php
            } else {
                echo '<div class="alert alert-danger">Failed to prepare marks query.</div>';
            }
        } else {
            echo '<div class="alert alert-danger">Student information not found.</div>';
        }
    } else {
        echo '<div class="alert alert-danger">Failed to prepare student query.</div>';
    }
} else {
    echo '<div class="alert alert-danger">Invalid parameters provided.</div>';
}

$conn->close();
?>