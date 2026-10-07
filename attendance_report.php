<?php
require_once('conn_inc.php');

// Get filters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date   = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$class_section_id   = isset($_GET['class_section_id']) ? intval($_GET['class_section_id']) : 0;

// Fetch all class-section options
$classSections = $conn->query("
    SELECT cs.id, c.title AS class_name, s.title AS section_name
    FROM class_sections cs
    JOIN classes c ON cs.class_id = c.id
    JOIN sections s ON cs.section_id = s.id
    ORDER BY c.title, s.title
");

// Fetch attendance summary
$report = null;
if ($class_section_id > 0) {
    $sql = "
        SELECT 
            sr.reg_no, 
            sr.name, 
            CONCAT(c.title, ' - ', s.title) AS class_section_name,
            COUNT(DISTINCT CASE WHEN a.status='P' THEN a.date END) AS present_days,
            COUNT(DISTINCT CASE WHEN a.status='L' THEN a.date END) AS leave_days,
            (wd.total_days 
                - COUNT(DISTINCT CASE WHEN a.status='P' THEN a.date END) 
                - COUNT(DISTINCT CASE WHEN a.status='L' THEN a.date END)
            ) AS absent_days,
            wd.total_days
        FROM student_registration sr
        JOIN student_class sc ON sc.student_registration_id = sr.id
        JOIN class_sections cs ON cs.id = sc.class_id
        JOIN classes c ON c.id = cs.class_id
        JOIN sections s ON s.id = cs.section_id
        CROSS JOIN (
            SELECT COUNT(DISTINCT date) AS total_days
            FROM attendance
            WHERE date BETWEEN '$start_date' AND '$end_date'
        ) wd
        LEFT JOIN attendance a 
            ON a.student_id = sr.id 
           AND a.date BETWEEN '$start_date' AND '$end_date'
        WHERE cs.id = $class_section_id
        GROUP BY sr.id, sr.reg_no, sr.name, c.title, s.title, wd.total_days
        ORDER BY c.title, s.title, sr.reg_no
    ";
    $report = $conn->query($sql);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Attendance Report</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap 3.4.1 CSS -->
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
    <link rel="stylesheet" href="css/mystyle.css" />
    <style>
        .table th, .table td {
            text-align: left;
        }
        .table thead {
            background-color: #333;
            color: #fff;
        }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<!-- Dashboard -->
<div class="container">
    <div class="row">
        <div class="col-md-10 col-md-offset-1">
            <!-- Panel for Filter Form -->
            <div class="panel panel-primary">
                <div class="panel-heading">
                    <h3 class="panel-title">Attendance Report</h3>
                </div>
                <div class="panel-body">
                    <form method="get" class="form-horizontal">
                        <div class="form-group">
                            <label class="col-sm-2 control-label">Start Date</label>
                            <div class="col-sm-4">
                                <input type="date" name="start_date" value="<?=$start_date?>" class="form-control">
                            </div>
                            <label class="col-sm-2 control-label">End Date</label>
                            <div class="col-sm-4">
                                <input type="date" name="end_date" value="<?=$end_date?>" class="form-control">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-2 control-label">Class - Section</label>
                            <div class="col-sm-6">
                                <select name="class_section_id" class="form-control" required>
                                    <option value="">Select Class - Section</option>
                                    <?php while($row = $classSections->fetch_assoc()): ?>
                                        <option value="<?=$row['id']?>" <?=($row['id']==$class_section_id?'selected':'')?>>
                                            <?=$row['class_name']?> - <?=$row['section_name']?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="col-sm-4">
                                <button type="submit" class="btn btn-primary">
                                    <span class="glyphicon glyphicon-search"></span> Generate Report
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Panel for Report Table -->
            <?php if ($report && $report->num_rows > 0): ?>
            <div class="panel panel-info">
                <div class="panel-heading">
                    <h3 class="panel-title">Attendance Summary</h3>
                </div>
                <div class="panel-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th>Reg No</th>
                                    <th>Name</th>
                                    <th>Class - Section</th>
                                    <th>Present</th>
                                    <th>Leave</th>
                                    <th>Absent</th>
                                    <th>Total Days</th>
                                    <th>Attendance %</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($row = $report->fetch_assoc()): 
                                    $percentage = ($row['total_days'] > 0) 
                                                  ? round(($row['present_days'] / $row['total_days']) * 100, 2) 
                                                  : 0;
                                ?>
                                <tr>
                                    <td><?=$row['reg_no']?></td>
                                    <td><?=$row['name']?></td>
                                    <td><?=$row['class_section_name']?></td>
                                    <td><?=$row['present_days']?></td>
                                    <td><?=$row['leave_days']?></td>
                                    <td><?=$row['absent_days']?></td>
                                    <td><?=$row['total_days']?></td>
                                    <td><?=$percentage?>%</td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php elseif ($class_section_id): ?>
            <div class="panel panel-warning">
                <div class="panel-body">
                    <div class="alert alert-warning">No records found for selected Class-Section and date range.</div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="js/mobile_menu.js"></script>
</body>
</html>
<?php $conn->close(); ?>