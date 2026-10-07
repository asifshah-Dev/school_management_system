<?php
require_once('security.php');
require_once('conn_inc.php');

// Check user permissions
$user_id = $_SESSION['user_id'];
$role_id = $_SESSION['role_id'];

// Default language
$lang = 'en';

// Language strings
$translations = [
    'en' => [
        'title' => 'Student Result Analysis',
        'subtitle' => 'Students with Below 50% Performance',
        'search_placeholder' => 'Search by ID, Name, or Roll No...',
        'search_btn' => 'Search',
        'reset_btn' => 'Reset',
        'filter_class' => 'Filter by Class',
        'filter_session' => 'Filter by Session',
        'filter_exam' => 'Filter by Exam Type',
        'filter_all' => 'All',
        'student_id' => 'Student ID',
        'student_name' => 'Student Name',
        'father_name' => 'Father Name',
        'class' => 'Class',
        'session' => 'Session',
        'exam_type' => 'Exam Type',
        'total_marks' => 'Total Marks',
        'obtained_marks' => 'Obtained Marks',
        'percentage' => 'Percentage',
        'status' => 'Status',
        'actions' => 'Actions',
        'no_records' => 'No students found with below 50% performance.',
        'below_50' => 'Below 50%',
        'view_details' => 'View Details',
        'export_csv' => 'Export to CSV',
        'export_pdf' => 'Export to PDF',
        'print' => 'Print',
        'sr_no' => '#',
        'back' => 'Back',
        'marks_details' => 'Marks Details',
        'subject' => 'Subject',
        'marks_obtained' => 'Marks Obtained',
        'total_subject_marks' => 'Total Subject Marks',
        'subject_percentage' => 'Subject %',
        'close' => 'Close',
        'loading' => 'Loading...',
        'error' => 'Error',
        'confirm_delete' => 'Are you sure you want to delete this record?'
    ]
];

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
$exam_type_id = isset($_GET['exam_type_id']) ? intval($_GET['exam_type_id']) : 0;

// Build WHERE conditions for main query
$where_conditions = ["sr.status = 0", "sc.status = 0"];
$params = [];
$param_types = '';

// Search condition
if (!empty($search)) {
    $search_term = "%{$search}%";
    $where_conditions[] = "(sr.reg_no LIKE ? OR sr.name LIKE ? OR sr.father_name LIKE ? OR sr.id LIKE ?)";
    $params = array_merge($params, [$search_term, $search_term, $search_term, "%{$search}%"]);
    $param_types .= 'ssss';
}

// Class filter
if ($class_id > 0) {
    $where_conditions[] = "sc.class_id = ?";
    $params[] = $class_id;
    $param_types .= 'i';
}

// Session filter
if ($session_id > 0) {
    $where_conditions[] = "sc.session_id = ?";
    $params[] = $session_id;
    $param_types .= 'i';
}

// Exam type filter
if ($exam_type_id > 0) {
    $where_conditions[] = "ae.exam_type_id = ?";
    $params[] = $exam_type_id;
    $param_types .= 'i';
}

// FIXED: Get all classes for filter dropdown - CORRECT QUERY
$classes_result = $conn->query("SELECT id, title FROM classes WHERE status IS NULL OR status = 0 ORDER BY 
    CASE 
        WHEN title LIKE 'Play Group%' THEN 1
        WHEN title LIKE 'Nursery%' THEN 2
        WHEN title LIKE 'Grade Prep%' THEN 3
        WHEN title LIKE 'Class%' THEN 4
        ELSE 5
    END,
    CAST(SUBSTRING_INDEX(title, ' ', -1) AS UNSIGNED),
    title");
$classes = [];
while ($row = $classes_result->fetch_assoc()) {
    $classes[$row['id']] = $row['title'];
}

// Get all sessions for filter dropdown
$sessions_result = $conn->query("SELECT id, title FROM sessions WHERE status = 0 ORDER BY from_dated DESC");
$sessions = [];
while ($row = $sessions_result->fetch_assoc()) {
    $sessions[$row['id']] = $row['title'];
}

// Get all exam types for filter dropdown
$exam_types_result = $conn->query("SELECT id, title FROM exam_types WHERE status = 0 ORDER BY title");
$exam_types = [];
while ($row = $exam_types_result->fetch_assoc()) {
    $exam_types[$row['id']] = $row['title'];
}

// Query to get students with below 50% performance - FIXED JOIN CONDITIONS
$query = "
    SELECT 
        sr.id AS student_id,
        sr.reg_no,
        sr.name,
        sr.father_name,
        sr.mobile,
        c.title AS class_name,
        s.title AS session_name,
        et.title AS exam_type_name,
        ae.id AS arrange_exam_id,
        sc.id AS student_class_id,
        sc.class_id,
        sc.session_id,
        COALESCE(SUM(r.marks), 0) AS total_obtained_marks,
        (
            SELECT COALESCE(SUM(scs.marks), 0)
            FROM subject_class scs 
            WHERE scs.class_id = sc.class_id 
            AND scs.status = 0
        ) AS total_possible_marks,
        (
            SELECT COUNT(DISTINCT scs.subject_id)
            FROM subject_class scs 
            WHERE scs.class_id = sc.class_id 
            AND scs.status = 0
        ) AS total_subjects
    FROM student_registration sr
    INNER JOIN student_class sc ON sr.id = sc.student_registration_id
    INNER JOIN classes c ON sc.class_id = c.id
    INNER JOIN sessions s ON sc.session_id = s.id
    LEFT JOIN arrange_exam ae ON ae.class_id = sc.class_id AND ae.status = 0
    LEFT JOIN exam_types et ON ae.exam_type_id = et.id
    LEFT JOIN results r ON r.student_id = sr.id AND r.arrange_exam_id = ae.id AND r.status = 0
    WHERE " . implode(' AND ', $where_conditions) . "
    GROUP BY sr.id, ae.id, sc.id, c.id, s.id, et.id
    HAVING total_possible_marks > 0 
        AND (COALESCE(SUM(r.marks), 0) / total_possible_marks * 100) < 50
    ORDER BY (COALESCE(SUM(r.marks), 0) / total_possible_marks * 100) ASC, sr.name
";

// Prepare and execute query
$stmt = $conn->prepare($query);

if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}

$students = [];
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        if ($row['total_possible_marks'] > 0) {
            $percentage = ($row['total_obtained_marks'] / $row['total_possible_marks']) * 100;
            $row['percentage'] = round($percentage, 2);
            $students[] = $row;
        }
    }
    $stmt->close();
} else {
    echo "<div class='alert alert-danger'>Query preparation failed: " . $conn->error . "</div>";
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=below_50_percent_students_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fwrite($output, "\xEF\xBB\xBF");
    
    // Header row
    fputcsv($output, [
        'Student ID',
        'Registration No',
        'Student Name',
        'Father Name',
        'Class',
        'Session',
        'Exam Type',
        'Total Obtained Marks',
        'Total Possible Marks',
        'Percentage',
        'Status'
    ]);
    
    // Data rows
    foreach ($students as $student) {
        fputcsv($output, [
            $student['student_id'],
            $student['reg_no'],
            $student['name'],
            $student['father_name'],
            $student['class_name'],
            $student['session_name'],
            $student['exam_type_name'],
            $student['total_obtained_marks'],
            $student['total_possible_marks'],
            $student['percentage'] . '%',
            'Below 50%'
        ]);
    }
    
    fclose($output);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once('meta_inc.php'); ?>
    <title><?php echo $translations[$lang]['title']; ?> - QIMS</title>
    <style>
        .panel-custom {
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-bottom: 20px;
            box-shadow: 0 1px 1px rgba(0,0,0,.05);
        }
        .panel-custom .panel-heading {
            background-color: #f5f5f5;
            border-bottom: 1px solid #ddd;
            padding: 10px 15px;
            border-top-left-radius: 3px;
            border-top-right-radius: 3px;
        }
        .panel-custom .panel-body {
            padding: 15px;
        }
        .percentage-badge {
            font-size: 12px;
            padding: 3px 8px;
            border-radius: 12px;
            font-weight: bold;
        }
        .percentage-below {
            background-color: #f2dede;
            color: #a94442;
        }
        .percentage-above {
            background-color: #dff0d8;
            color: #3c763d;
        }
        .filter-section {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            border: 1px solid #dee2e6;
        }
        .export-buttons {
            margin-bottom: 15px;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .student-details-modal .modal-body {
            max-height: 500px;
            overflow-y: auto;
        }
        .subject-row {
            border-bottom: 1px solid #eee;
            padding: 8px 0;
        }
        .subject-row:last-child {
            border-bottom: none;
        }
        .progress {
            height: 20px;
            margin-bottom: 5px;
        }
        .progress-bar-danger {
            background-color: #d9534f;
        }
        .progress-bar-warning {
            background-color: #f0ad4e;
        }
        .progress-bar-success {
            background-color: #5cb85c;
        }
        .filter-row {
            margin-bottom: 10px;
        }
        .filter-row .form-group {
            margin-bottom: 10px;
        }
        @media (max-width: 768px) {
            .filter-row .col-md-3,
            .filter-row .col-md-2 {
                margin-bottom: 10px;
            }
        }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<!-- Dashboard -->
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            
            <!-- Page Header -->
            <div class="page-header">
                <h1>
                    <i class="glyphicon glyphicon-stats"></i>
                    <?php echo $translations[$lang]['title']; ?>
                    <small><?php echo $translations[$lang]['subtitle']; ?></small>
                </h1>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="get" action="" class="form-horizontal">
                    <div class="row filter-row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <input type="text" class="form-control" name="search" 
                                       placeholder="<?php echo $translations[$lang]['search_placeholder']; ?>"
                                       value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="form-group">
                                <select class="form-control" name="class_id">
                                    <option value="0"><?php echo $translations[$lang]['filter_class']; ?> (All)</option>
                                    <?php foreach ($classes as $id => $title): ?>
                                        <option value="<?php echo $id; ?>" 
                                            <?php echo $class_id == $id ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="form-group">
                                <select class="form-control" name="session_id">
                                    <option value="0"><?php echo $translations[$lang]['filter_session']; ?> (All)</option>
                                    <?php foreach ($sessions as $id => $title): ?>
                                        <option value="<?php echo $id; ?>" 
                                            <?php echo $session_id == $id ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="form-group">
                                <select class="form-control" name="exam_type_id">
                                    <option value="0"><?php echo $translations[$lang]['filter_exam']; ?> (All)</option>
                                    <?php foreach ($exam_types as $id => $title): ?>
                                        <option value="<?php echo $id; ?>" 
                                            <?php echo $exam_type_id == $id ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12 text-right">
                            <button type="submit" class="btn btn-primary">
                                <i class="glyphicon glyphicon-search"></i>
                                <?php echo $translations[$lang]['search_btn']; ?>
                            </button>
                            <a href="student_result_analysis.php" class="btn btn-default">
                                <i class="glyphicon glyphicon-refresh"></i>
                                <?php echo $translations[$lang]['reset_btn']; ?>
                            </a>
                        </div>
                    </div>
                </form>
            </div>

           

            <!-- Export Buttons -->
            <div class="export-buttons text-right">
                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" 
                   class="btn btn-success">
                    <i class="glyphicon glyphicon-download-alt"></i>
                    <?php echo $translations[$lang]['export_csv']; ?>
                </a>
                <button onclick="window.print()" class="btn btn-info">
                    <i class="glyphicon glyphicon-print"></i>
                    <?php echo $translations[$lang]['print']; ?>
                </button>
           
            </div>

            <!-- Results Panel -->
            <div class="panel panel-custom panel-danger">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <i class="glyphicon glyphicon-exclamation-sign"></i>
                        <?php echo $translations[$lang]['subtitle']; ?>
                        <span class="badge"><?php echo count($students); ?> Students</span>
                    </h3>
                </div>
                <div class="panel-body">
                    
                    <?php if (count($students) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover table-striped">
                                <thead>
                                    <tr>
                                        <th width="50"><?php echo $translations[$lang]['sr_no']; ?></th>
                                        <th><?php echo $translations[$lang]['student_id']; ?></th>
                                        <th><?php echo $translations[$lang]['student_name']; ?></th>
                                        <th><?php echo $translations[$lang]['father_name']; ?></th>
                                        <th><?php echo $translations[$lang]['class']; ?></th>
                                        <th><?php echo $translations[$lang]['session']; ?></th>
                                        <th><?php echo $translations[$lang]['exam_type']; ?></th>
                                        <th><?php echo $translations[$lang]['obtained_marks']; ?></th>
                                        <th><?php echo $translations[$lang]['total_marks']; ?></th>
                                        <th><?php echo $translations[$lang]['percentage']; ?></th>
                                        <th><?php echo $translations[$lang]['status']; ?></th>
                                        <th><?php echo $translations[$lang]['actions']; ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($students as $index => $student): ?>
                                        <?php
                                        $percentage_class = $student['percentage'] < 50 ? 'percentage-below' : 'percentage-above';
                                        $progress_class = '';
                                        if ($student['percentage'] < 33) {
                                            $progress_class = 'progress-bar-danger';
                                            $status_text = 'Fail';
                                        } elseif ($student['percentage'] < 50) {
                                            $progress_class = 'progress-bar-warning';
                                            $status_text = 'Below Average';
                                        } else {
                                            $progress_class = 'progress-bar-success';
                                            $status_text = 'Pass';
                                        }
                                        ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($student['reg_no']); ?></strong>
                                                <br><small>ID: <?php echo $student['student_id']; ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($student['name']); ?></td>
                                            <td><?php echo htmlspecialchars($student['father_name']); ?></td>
                                            <td><?php echo htmlspecialchars($student['class_name']); ?></td>
                                            <td><?php echo htmlspecialchars($student['session_name']); ?></td>
                                            <td><?php echo htmlspecialchars($student['exam_type_name']); ?></td>
                                            <td>
                                                <strong><?php echo $student['total_obtained_marks']; ?></strong>
                                            </td>
                                            <td><?php echo $student['total_possible_marks']; ?></td>
                                            <td>
                                                <div class="progress">
                                                    <div class="progress-bar <?php echo $progress_class; ?>" 
                                                         role="progressbar" 
                                                         style="width: <?php echo min($student['percentage'], 100); ?>%">
                                                        <?php echo $student['percentage']; ?>%
                                                    </div>
                                                </div>
                                                <span class="percentage-badge <?php echo $percentage_class; ?>">
                                                    <?php echo $student['percentage']; ?>%
                                                </span>
                                            </td>
                                            <td>
                                                <span class="label label-<?php echo $student['percentage'] < 33 ? 'danger' : 'warning'; ?>">
                                                    <?php echo $status_text; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-xs btn-info view-details" 
                                                        data-student-id="<?php echo $student['student_id']; ?>"
                                                        data-arrange-exam-id="<?php echo $student['arrange_exam_id']; ?>"
                                                        data-student-class-id="<?php echo $student['student_class_id']; ?>">
                                                    <i class="glyphicon glyphicon-eye-open"></i>
                                                    <?php echo $translations[$lang]['view_details']; ?>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Summary Statistics -->
                        <div class="well">
                            <div class="row">
                                <div class="col-md-3">
                                    <h5>Summary:</h5>
                                    <ul class="list-unstyled">
                                        <li>Total Students: <strong><?php echo count($students); ?></strong></li>
                                        <li>Average Percentage: 
                                            <strong>
                                                <?php 
                                                $total_percentage = 0;
                                                foreach ($students as $s) $total_percentage += $s['percentage'];
                                                echo count($students) > 0 ? round($total_percentage / count($students), 2) : 0; 
                                                ?>%
                                            </strong>
                                        </li>
                                    </ul>
                                </div>
                                <div class="col-md-9">
                                    <h5>Performance Distribution:</h5>
                                    <div class="progress">
                                        <?php
                                        $below_33 = 0;
                                        $below_50 = 0;
                                        foreach ($students as $s) {
                                            if ($s['percentage'] < 33) $below_33++;
                                            elseif ($s['percentage'] < 50) $below_50++;
                                        }
                                        $total = count($students);
                                        ?>
                                        <div class="progress-bar progress-bar-danger" 
                                             style="width: <?php echo $total > 0 ? ($below_33 / $total * 100) : 0; ?>%">
                                            Below 33%: <?php echo $below_33; ?>
                                        </div>
                                        <div class="progress-bar progress-bar-warning" 
                                             style="width: <?php echo $total > 0 ? ($below_50 / $total * 100) : 0; ?>%">
                                            33-49%: <?php echo $below_50; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                    <?php else: ?>
                        <div class="alert alert-info text-center">
                            <i class="glyphicon glyphicon-info-sign"></i>
                            <?php echo $translations[$lang]['no_records']; ?>
                        </div>
                    <?php endif; ?>
                    
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Student Details Modal -->
<div class="modal fade student-details-modal" id="studentDetailsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">
                    <i class="glyphicon glyphicon-user"></i>
                    <?php echo $translations[$lang]['marks_details']; ?>
                </h4>
            </div>
            <div class="modal-body">
                <div id="studentDetailsContent">
                    <div class="text-center">
                        <i class="glyphicon glyphicon-refresh glyphicon-spin"></i>
                        <?php echo $translations[$lang]['loading']; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">
                    <?php echo $translations[$lang]['close']; ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script src="js/mobile_menu.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

<script>
$(document).ready(function() {
    // View student details
    $('.view-details').click(function() {
        var studentId = $(this).data('student-id');
        var arrangeExamId = $(this).data('arrange-exam-id');
        var studentClassId = $(this).data('student-class-id');
        
        $('#studentDetailsContent').html(
            '<div class="text-center">' +
            '<i class="glyphicon glyphicon-refresh glyphicon-spin"></i> ' +
            '<?php echo $translations[$lang]["loading"]; ?>' +
            '</div>'
        );
        
        $('#studentDetailsModal').modal('show');
        
        $.ajax({
            url: 'ajax/get_student_marks_details.php',
            type: 'GET',
            data: {
                student_id: studentId,
                arrange_exam_id: arrangeExamId,
                student_class_id: studentClassId
            },
            success: function(response) {
                $('#studentDetailsContent').html(response);
            },
            error: function() {
                $('#studentDetailsContent').html(
                    '<div class="alert alert-danger">' +
                    '<i class="glyphicon glyphicon-exclamation-sign"></i> ' +
                    '<?php echo $translations[$lang]["error"]; ?>' +
                    '</div>'
                );
            }
        });
    });
});
</script>

</body>
</html>