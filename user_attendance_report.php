<?php
ob_start();
require_once('conn_inc.php');
require_once('security.php'); // Include security check

// --------------------
// Get Parameters
// --------------------
$type = isset($_GET['type']) ? $_GET['type'] : 'daily';
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// --------------------
// Calculate Date Range
// --------------------
if ($type === 'daily') {
    $start_date = $end_date = $date;
    $period_title = "Daily Report - " . date('F j, Y', strtotime($date));
} elseif ($type === 'weekly') {
    $ts = strtotime($date);
    $start_date = date('Y-m-d', strtotime('monday this week', $ts));
    $end_date   = date('Y-m-d', strtotime('sunday this week', $ts));
    $period_title = "Weekly Report - " . date('M j', strtotime($start_date)) . " to " . date('M j, Y', strtotime($end_date));
} elseif ($type === 'monthly') {
    $ts = strtotime($date);
    $start_date = date('Y-m-01', $ts);
    $end_date   = date('Y-m-t', $ts);
    $period_title = "Monthly Report - " . date('F Y', strtotime($date));
} else {
    die("Invalid report type");
}

// --------------------
// SQL Query - FIXED for monthly report
// --------------------
$sql = "
SELECT 
    u.id AS user_id,
    u.username,
    c.dated,
    COALESCE(ua.status, 'A') AS status
FROM users u
CROSS JOIN (
    SELECT DATE(?) + INTERVAL (a.a + (10 * b.a)) DAY AS dated
    FROM 
        (SELECT 0 AS a UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 
         UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL 
         SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS a
    CROSS JOIN 
        (SELECT 0 AS a UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 
         UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL 
         SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS b
) c
LEFT JOIN user_attendance ua ON ua.user_id = u.id AND ua.dated = c.dated
WHERE c.dated BETWEEN ? AND ?
ORDER BY u.username, c.dated
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("sss", $start_date, $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();

// --------------------
// Organize Data
// --------------------
$attendance = [];
$dates = [];
$total_teachers = 0;

// Calculate overall totals
$total_present = 0;
$total_leave = 0;
$total_absent = 0;

while ($row = $result->fetch_assoc()) {
    // Remove extra spaces from teacher names
    $teacher_name = trim(preg_replace('/\s+/', ' ', $row['username']));
    $attendance[$row['user_id']]['name'] = $teacher_name;
    $attendance[$row['user_id']]['records'][$row['dated']] = $row['status'];
    $dates[$row['dated']] = true;
}

$dates = array_keys($dates);
sort($dates);
$total_teachers = count($attendance);
$total_days = count($dates);

// Calculate totals for each teacher and overall
foreach ($attendance as $user_id => $data) {
    $teacher_present = 0;
    $teacher_leave = 0;
    $teacher_absent = 0;
    
    foreach ($dates as $d) {
        $status = $data['records'][$d] ?? 'A';
        switch ($status) {
            case 'P': 
                $teacher_present++;
                $total_present++;
                break;
            case 'L': 
                $teacher_leave++;
                $total_leave++;
                break;
            case 'A': 
                $teacher_absent++;
                $total_absent++;
                break;
        }
    }
    
    $attendance[$user_id]['present'] = $teacher_present;
    $attendance[$user_id]['leave'] = $teacher_leave;
    $attendance[$user_id]['absent'] = $teacher_absent;
}

$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Teacher Attendance Report</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="css/mystyle.css" />
    <style>
       
        
        
        
        /* Improved Table Styles */
        .attendance-table { 
            width: 100%; 
            border-collapse: collapse;
            font-size: 12px;
            background: white;
        }
        
        .attendance-table th { 
            background: #2c3e50; 
            color: white; 
            padding: 10px 5px;
            text-align: center;
            border: 1px solid #34495e;
            font-weight: bold;
            font-size: 11px;
        }
        
        .attendance-table td {
            padding: 8px 4px;
            text-align: center;
            border: 1px solid #ddd;
            font-weight: bold;
        }
        
        /* Status Colors */
        .present { background: #27ae60; color: white; }
        .leave { background: #f39c12; color: white; }
        .absent { background: #e74c3c; color: white; }
        
        /* Teacher name column */
        .teacher-col { 
            background: #34495e; 
            color: white;
            font-weight: bold; 
            text-align: left !important;
            padding: 10px 12px !important;
            position: sticky;
            left: 0;
            z-index: 2;
            min-width: 160px;
            font-size: 12px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Date headers - FIXED: No rotation, no italic */
        .date-header { 
            height: 60px;
            white-space: normal;
            padding: 5px 3px !important;
            font-size: 10px;
            font-weight: bold;
            font-style: normal;
            line-height: 1.2;
            vertical-align: bottom;
        }
        
        .date-header .day {
            display: block;
            font-size: 11px;
            font-weight: bold;
        }
        
        .date-header .month {
            display: block;
            font-size: 9px;
            opacity: 0.8;
        }
        
        /* Summary boxes */
        .summary-box { 
            padding: 15px; 
            border-radius: 5px; 
            color: white;
            text-align: center;
            margin: 5px;
        }
        
        .present-summary { background: #27ae60; }
        .leave-summary { background: #f39c12; }
        .absent-summary { background: #e74c3c; }
        .total-summary { background: #3498db; }
        
        .summary-number { 
            font-size: 22px; 
            font-weight: bold; 
            display: block;
            margin-bottom: 5px;
        }
        
        .summary-label { 
            font-size: 12px; 
        }
        
        /* Scrollable table container */
        .table-container {
            overflow-x: auto;
            margin-bottom: 20px;
            border: 1px solid #ddd;
        }
        
        .btn {
            font-weight: 500;
        }
        
        .well {
            background: #f8f9fa;
            border: 1px solid #e1e5e9;
            border-radius: 4px;
            padding: 12px;
        }

        /* Print-specific styling */
        .no-print { }
        .print-only { display: none; }
        .print-header {
            text-align: center; 
            margin-bottom: 15px;
        }
        .print-meta {
            text-align: center; 
            margin-bottom: 15px; 
            font-size: 12px;
        }
        .page-break { page-break-before: always; }

        @media print {
            body { padding-top: 0; background: #fff; color: black !important; }
              .navbar,
    .navbar * {
        display: none !important;
      
    }
.attendance-table th { 
        color: black !important;
        }
        
        .attendance-table td {
           color: black;
        }

 .summary-number { 
           color: black !important;
        }
        .attendance-table { 
           color: black !important;
        }
        
         .present-summary { color: black !important; }
        .leave-summary { color: black !important; }
        .absent-summary { color: black !important;}
        .total-summary { color: black !important;}
        .summary-label { 
           color: black !important; 
        }
    .date-header { 
            color: black !important;
        }

    /* Extra safety for common logo hooks */
    .navbar-brand,
    .navbar-brand img,
    .logo,
    img.logo {
        display: none !important;
       
    }
    .col-md-3{display: none !important;}
            .no-print { display: none !important; color: black !important;}
            .print-only { display: block !important; color: black !important;}
            .panel { border: none; color: black !important;}
            .panel-heading {color: black !important;  }
            .table-container {  border: none; color: black !important;}
            .teacher-col { position: static; color: black !important;}
            .container { width: 100% !important; }
            a[href]:after { content: ""; color: black !important;}
        }
         .panel { 
            color: black  !important;
        }
        
        .panel-heading { 
           color: black !important;
        }
    </style>
</head>
<body>

    <?php require_once('navbar.php'); ?>
  

    <div class="container">
        <div class="row no-print">
            <div class="col-md-12">
                <!-- Controls -->
                <div class="panel panel-primary">
                    <div class="panel-heading">
                        <h4 class="panel-title text-white">Attendance Report Controls</h4>
                    </div>
                    <div class="panel-body">
                        <form method="get" class="form-inline">
                            <div class="form-group">
                                <label>Report Type:</label>
                                <select id="type" name="type" class="form-control" style="margin: 0 10px;">
                                    <option value="daily" <?= $type === 'daily' ? 'selected' : '' ?>>Daily Report</option>
                                    <option value="weekly" <?= $type === 'weekly' ? 'selected' : '' ?>>Weekly Report</option>
                                    <option value="monthly" <?= $type === 'monthly' ? 'selected' : '' ?>>Monthly Report</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Date:</label>
                                <input type="date" name="date" value="<?= $date ?>" class="form-control" style="margin: 0 10px;">
                            </div>
                            <button type="submit" class="btn btn-success">Generate Report</button>
                            <button type="button" id="btnPrint" class="btn btn-primary" style="margin-left:10px;">
                                <span class="glyphicon glyphicon-print"></span> Print
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Print header (only visible on print) -->
        <div class="print-only">
            <div class="print-header">
                <h3 style="margin:0;">Teacher Attendance Report</h3>
                <div style="font-size:13px; margin-top:4px;"><?= htmlspecialchars($period_title) ?></div>
            </div>
            <div class="print-meta">
                From: <?= htmlspecialchars($start_date) ?> &nbsp; | &nbsp;
                To: <?= htmlspecialchars($end_date) ?> &nbsp; | &nbsp;
                Generated: <?= date('Y-m-d H:i') ?>
            </div>
        </div>

        <!-- Summary Statistics -->
        <div id="printArea">
           

            <!-- Report Table -->
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h4 class="panel-title ">Attendance Register - <?= $period_title ?></h4>
                </div>
                <div class="panel-body" style="padding: 0;">
                    <?php if (empty($attendance)): ?>
                        <div class="alert alert-warning text-center" style="margin: 20px;">
                            <h4>No Data Available</h4>
                            <p>No attendance records found for the selected period.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="attendance-table">
                                <thead>
                                    <tr>
                                        <th class="teacher-col">Teacher Name</th>
                                        <?php foreach ($dates as $d): ?>
                                            <th class="date-header">
                                                <span class="day"><?= date('d', strtotime($d)) ?></span>
                                                <span class="month"><?= date('M', strtotime($d)) ?></span>
                                            </th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($attendance as $user_id => $data): ?>
                                        <tr>
                                            <td class="teacher-col" title="<?= htmlspecialchars($data['name']) ?>">
                                                <?= htmlspecialchars($data['name']) ?>
                                            </td>
                                            <?php foreach ($dates as $d): 
                                                $status = $data['records'][$d] ?? 'A';
                                                $status_class = '';
                                                switch ($status) {
                                                    case 'P': $status_class = 'present'; break;
                                                    case 'L': $status_class = 'leave'; break;
                                                    case 'A': $status_class = 'absent'; break;
                                                }
                                            ?>
                                                <td class="<?= $status_class ?>"><?= $status ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Monthly Summary -->
                        <div class="row" style="margin: 20px 10px 10px 10px;">
                            <div class="col-md-12">
                                <div class="panel panel-info">
                                    <div class="panel-heading">
                                        <h4 class="panel-title ">Monthly Attendance Summary - <?= date('F Y', strtotime($date)) ?></h4>
                                    </div>
                                    <div class="panel-body">
                                        <div class="row text-center">
                                            <div class="col-md-4">
                                                <div class="summary-box present-summary">
                                                    <span class="summary-number"><?= $total_present ?></span>
                                                    <span class="summary-label">TOTAL PRESENT</span>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="summary-box leave-summary">
                                                    <span class="summary-number"><?= $total_leave ?></span>
                                                    <span class="summary-label">TOTAL LEAVE</span>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="summary-box absent-summary">
                                                    <span class="summary-number"><?= $total_absent ?></span>
                                                    <span class="summary-label">TOTAL ABSENT</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row text-center" style="margin-top: 15px;">
                                            <div class="col-md-6">
                                                <div class="well">
                                                    <h5 style="font-weight: bold; margin: 0;">
                                                        Total Working Days: <?= $total_days ?>
                                                    </h5>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="well">
                                                    <h5 style="font-weight: bold; margin: 0;">
                                                        Total Teachers: <?= $total_teachers ?>
                                                    </h5>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div><!-- /#printArea -->
    </div>

    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
    <script>
    $(document).ready(function() {
        // Auto-submit form when type changes
        $('#type').change(function() {
            $(this).closest('form').submit();
        });

        // Print only the register + summaries (CSS hides controls/navbar)
        $('#btnPrint').on('click', function() {
            window.print();
        });
    });
    </script>

</body>
</html>

<?php
$conn->close();
?>