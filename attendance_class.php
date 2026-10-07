<?php
ob_start();
require_once("conn_inc.php");
$date = $_GET['date'] ?? date('Y-m-d');

// Flags
$attendanceSaved = false;
$attendanceExists = false;

// Counters
$presentCount = 0;
$absentCount = 0;
$leaveCount = 0;
$totalCount = 0;

// Process form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $attendanceSaved = true;
    $class_id = intval($_POST['class_id']);
    $statuses = $_POST['status'] ?? [];
    $remarks = $_POST['remarks'] ?? [];

    foreach ($statuses as $student_id => $status) {
        $remark = trim($remarks[$student_id] ?? '');

        if ($status === 'P') {
            $stmt = $conn->prepare("
                INSERT INTO attendance (student_id, date, status, remarks)
                VALUES (?, ?, 'P', ?)
                ON DUPLICATE KEY UPDATE status='P', remarks=VALUES(remarks)
            ");
            $stmt->bind_param("iss", $student_id, $date, $remark);
            $stmt->execute();
        } elseif ($status === 'L') {
            if ($remark === '') $remark = 'Leave';
            $stmt = $conn->prepare("
                INSERT INTO attendance (student_id, date, status, remarks)
                VALUES (?, ?, 'L', ?)
                ON DUPLICATE KEY UPDATE status='L', remarks=VALUES(remarks)
            ");
            $stmt->bind_param("iss", $student_id, $date, $remark);
            $stmt->execute();
        } elseif ($status === 'A') {
            $stmt = $conn->prepare("DELETE FROM attendance WHERE student_id=? AND date=?");
            $stmt->bind_param("is", $student_id, $date);
            $stmt->execute();
        }
    }
}

// Load students
$students = [];
$class_id = $_GET['class_id'] ?? ($_POST['class_id'] ?? null);

if ($class_id) {
    // Check if attendance already exists for this class and date
    $stmtCheck = $conn->prepare("
        SELECT COUNT(*) as cnt
        FROM attendance a
        JOIN student_class sc ON sc.student_registration_id = a.student_id
        WHERE sc.class_id = ? AND a.date = ?
    ");
    $stmtCheck->bind_param("is", $class_id, $date);
    $stmtCheck->execute();
    $resultCheck = $stmtCheck->get_result()->fetch_assoc();
    $attendanceExists = ($resultCheck['cnt'] > 0);

    // Load student list - UPDATED: filter by class_id instead of class_section_id
    $stmt = $conn->prepare("
        SELECT sr.id, sr.reg_no, sr.name,
               COALESCE(a.status, 'P') AS status, a.remarks
        FROM student_class sc
        JOIN student_registration sr ON sr.id = sc.student_registration_id
        LEFT JOIN attendance a ON a.student_id = sr.id AND a.date = ?
        WHERE sc.class_id = ? and sc.status = 0 and sr.status = 0
        ORDER BY sr.reg_no
    ");
    $stmt->bind_param("si", $date, $class_id);
    $stmt->execute();
    $students = $stmt->get_result();
    
    // Calculate counters
    if ($students && $students->num_rows > 0) {
        $students->data_seek(0);
        while($s = $students->fetch_assoc()) {
            $totalCount++;
            if ($s['status'] == 'P') {
                $presentCount++;
            } elseif ($s['status'] == 'L') {
                $leaveCount++;
            } elseif ($s['status'] == 'A') {
                $absentCount++;
            }
        }
        $students->data_seek(0); // Reset pointer for later use
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Class-wise Attendance</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap 3.4.1 CSS & JS -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
    <!-- Bootstrap Datepicker CSS & JS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/css/bootstrap-datepicker.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/js/bootstrap-datepicker.min.js"></script>
    <link rel="stylesheet" href="css/mystyle.css" />
    <style>
        .table th, .table td { text-align: left; vertical-align: middle; }
        .table-success td { background-color: #dff0d8 !important; }
        .table-warning td { background-color: #fcf8e3 !important; }
        .table-danger td { background-color: #f2dede !important; }
        .remarks-select { width: 180px; font-size:20px; }
        input[type="radio"] { margin: 0 5px; font-size:20px; }
        .datepicker { z-index: 1151 !important; }
        .form-group .input-group .input-group-addon { cursor: pointer; }
        .counter-box { 
            border-radius: 5px; 
            padding: 15px; 
            margin-bottom: 20px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .present-counter { background-color: #dff0d8; border: 1px solid #d6e9c6; color: #3c763d; }
        .absent-counter { background-color: #f2dede; border: 1px solid #ebccd1; color: #a94442; }
        .leave-counter { background-color: #fcf8e3; border: 1px solid #faebcc; color: #8a6d3b; }
        .total-counter { background-color: #d9edf7; border: 1px solid #bce8f1; color: #31708f; }
        .counter-number { 
            font-size: 24px; 
            font-weight: bold; 
            display: block;
            margin-bottom: 5px;
        }
        .counter-label { 
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .counters-row { margin-bottom: 20px; }
    </style>
    <script>
        function toggleRemarks(studentId, status) {
            let remarksField = document.getElementById("remarks_" + studentId);
            if (status === 'L') {
                remarksField.disabled = false;
                remarksField.required = true;
            } else {
                remarksField.disabled = true;
                remarksField.required = false;
                remarksField.value = '';
            }
        }
        
        function updateRemarksSelect(studentId) {
            let remarksField = document.getElementById("remarks_" + studentId);
            let leaveRadio = document.querySelector('input[name="status[' + studentId + ']"][value="L"]');
            
            if (leaveRadio.checked) {
                remarksField.disabled = false;
                remarksField.required = true;
            } else {
                remarksField.disabled = true;
                remarksField.required = false;
                remarksField.value = '';
            }
        }
        
        function updateCounters() {
            let presentCount = 0;
            let absentCount = 0;
            let leaveCount = 0;
            let totalCount = 0;
            
            // Count all radio buttons that are checked
            document.querySelectorAll('input[type="radio"]:checked').forEach(function(radio) {
                totalCount++;
                if (radio.value === 'P') presentCount++;
                else if (radio.value === 'A') absentCount++;
                else if (radio.value === 'L') leaveCount++;
            });
            
            // Update counter displays
            document.getElementById('presentCount').textContent = presentCount;
            document.getElementById('absentCount').textContent = absentCount;
            document.getElementById('leaveCount').textContent = leaveCount;
            document.getElementById('totalCount').textContent = totalCount;
        }
        
        $(document).ready(function() {
            // Initialize datepicker - REMOVED endDate restriction
            $('.datepicker').datepicker({
                format: 'yyyy-mm-dd',
                autoclose: true,
                todayHighlight: true
                // Removed: endDate: '0d' - Now allows all dates including future
            });
            
            <?php if ($students && $students->num_rows > 0): ?>
                <?php $students->data_seek(0); ?>
                <?php while($s = $students->fetch_assoc()): ?>
                    updateRemarksSelect(<?=$s['id']?>);
                <?php endwhile; ?>
                <?php $students->data_seek(0); ?>
            <?php endif; ?>
            
            // Add change event listeners to all radio buttons to update counters
            document.querySelectorAll('input[type="radio"]').forEach(function(radio) {
                radio.addEventListener('change', updateCounters);
            });
            
            // Initial counter update
            updateCounters();
        });
    </script>
</head>
<body>

<?php require_once('navbar.php');

// Load all classes using the provided query
$qry = "
    SELECT c.id as class_id, c.title 
    FROM `classes` c 
    LEFT JOIN class_sections sc ON sc.class_id = c.id 
    LEFT JOIN sections s ON s.id = sc.section_id
    GROUP BY c.id, c.title
    ORDER BY c.title
";
$stmt = $conn->prepare($qry);
if ($stmt === false) {
    echo "<div class='alert alert-danger'>Query preparation failed: " . htmlspecialchars($conn->error) . "</div>";
} else {
    $stmt->execute();
    $classes = $stmt->get_result();
    if ($classes->num_rows == 0) {
        echo "<div class='alert alert-warning'>No classes found in the system.</div>";
    }
}
?>

<div class="container">
    <div class="row">
        <div class="col-md-12 ">

            <!-- Select Class Section -->
            <div class="panel panel-primary">
                <div class="panel-heading"><h3 class="panel-title">Class Attendance</h3></div>
                <div class="panel-body">
                    <form method="get" class="form-horizontal">
                        <div class="form-group">
                            <label class="col-sm-3 control-label">Select Date:</label>
                            <div class="col-sm-9">
                                <div class="input-group date">
                                    <input type="text" class="form-control datepicker" name="date" value="<?= htmlspecialchars($date) ?>" required>
                                    <span class="input-group-addon">
                                        <span class="glyphicon glyphicon-calendar"></span>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">Select Class:</label>
                            <div class="col-sm-9">
                            <select name="class_id" class="form-control" required>
                                <option value="">-- Select Class --</option>
                                <?php
                                if ($classes->num_rows > 0) {
                                    while ($fetch = $classes->fetch_assoc()) {
                                        $class_id_val = $fetch['class_id'];
                                        $class_title = htmlspecialchars($fetch['title']);
                                        $selected = ($class_id == $class_id_val) ? 'selected' : '';
                                        echo "<option value='$class_id_val' $selected>$class_title</option>";
                                    }
                                }
                                ?>
                            </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="col-sm-offset-3 col-sm-9">
                                <button type="submit" class="btn btn-primary">
                                    <span class="glyphicon glyphicon-search"></span> Load Students
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Attendance Counters -->
            <?php if ($students && $students->num_rows > 0): ?>
            <div class="row counters-row">
                <div class="col-md-3 col-sm-6">
                    <div class="counter-box total-counter">
                        <span class="counter-number" id="totalCount"><?= $totalCount ?></span>
                        <span class="counter-label">Total Students</span>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="counter-box present-counter">
                        <span class="counter-number" id="presentCount"><?= $presentCount ?></span>
                        <span class="counter-label">Present</span>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="counter-box absent-counter">
                        <span class="counter-number" id="absentCount"><?= $absentCount ?></span>
                        <span class="counter-label">Absent</span>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="counter-box leave-counter">
                        <span class="counter-number" id="leaveCount"><?= $leaveCount ?></span>
                        <span class="counter-label">On Leave</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Attendance Form -->
            <?php if ($students && $students->num_rows > 0 && !$attendanceSaved && !$attendanceExists): ?>
            <div class="panel panel-info">
                <div class="panel-heading">
                    <h3 class="panel-title">Mark Attendance for <?= date('d-M-Y', strtotime($date)) ?></h3>
                </div>
                <div class="panel-body">
                    <form method="post">
                        <input type="hidden" name="class_id" value="<?=$class_id?>">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th>Sr No</th>
                                        <th>Name</th>
                                        <th>Present</th>
                                        <th>Absent</th>
                                        <th>Leave</th>
                                        <th>Remarks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php 
                                $sr_no = 1;
                                while($s = $students->fetch_assoc()): 
                                ?>
                                    <tr class="<?= $s['status']=='P'?'table-success':($s['status']=='L'?'table-warning':'table-danger') ?>">
                                        <td><?=$sr_no?></td>
                                        <td><?=htmlspecialchars($s['name'])?></td>
                                        <td>
                                            <input type="radio" class="form-check-input" name="status[<?=$s['id']?>]" value="P" 
                                                <?=($s['status']=='P'?'checked':'')?> 
                                                onchange="updateRemarksSelect(<?=$s['id']?>); updateCounters();">
                                        </td>
                                        <td>
                                            <input type="radio" class="form-check-input" name="status[<?=$s['id']?>]" value="A" 
                                                <?=($s['status']=='A'?'checked':'')?> 
                                                onchange="updateRemarksSelect(<?=$s['id']?>); updateCounters();">
                                        </td>
                                        <td>
                                            <input type="radio" class="form-check-input" name="status[<?=$s['id']?>]" value="L" 
                                                <?=($s['status']=='L'?'checked':'')?> 
                                                onchange="updateRemarksSelect(<?=$s['id']?>); updateCounters();">
                                        </td>
                                        <td>
                                            <select id="remarks_<?=$s['id']?>" name="remarks[<?=$s['id']?>]" class="form-control remarks-select" 
                                                <?=($s['status']=='L'?'':'disabled')?>>
                                                <option value="">Select Reason</option>
                                                <option value="Sick" <?=($s['remarks']=='Sick'?'selected':'')?>>Sick</option>
                                                <option value="Leave" <?=($s['remarks']=='Leave'?'selected':'')?>>Leave</option>
                                                <option value="Urgent work" <?=($s['remarks']=='Urgent work'?'selected':'')?>>Urgent work</option>
                                                <option value="Other" <?=($s['remarks']!='' && !in_array($s['remarks'], ['Sick', 'Leave', 'Urgent work'])?'selected':'')?>>Other</option>
                                            </select>
                                        </td>
                                    </tr>
                                <?php 
                                $sr_no++;
                                endwhile; 
                                ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="text-right">
                            <button type="submit" class="btn btn-success m-3">
                                <span class="glyphicon glyphicon-floppy-disk"></span> Save Attendance
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Attendance Summary -->
            <?php if (($attendanceSaved || $attendanceExists) && $students && $students->num_rows > 0): ?>
            <div class="panel panel-info">
                <div class="panel-heading">
                    <h3 class="panel-title">Attendance Summary for <?= date('d-M-Y', strtotime($date)) ?> (Editable)</h3>
                </div>
                <div class="panel-body">
                    <form method="post">
                        <input type="hidden" name="class_id" value="<?=$class_id?>">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th>Sr No</th>
                                        <th>Name</th>
                                        <th>Present</th>
                                        <th>Absent</th>
                                        <th>Leave</th>
                                        <th>Remarks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php
                                $students->data_seek(0);
                                $sr_no = 1;
                                while($s = $students->fetch_assoc()):
                                    $stmt2 = $conn->prepare("SELECT status, remarks FROM attendance WHERE student_id=? AND date=?");
                                    $stmt2->bind_param("is", $s['id'], $date);
                                    $stmt2->execute();
                                    $res = $stmt2->get_result()->fetch_assoc();
                                    $status = $res['status'] ?? 'A';
                                    $remarks = $res['remarks'] ?? '';
                                ?>
                                    <tr class="<?= $status=='P'?'table-success':($status=='L'?'table-warning':'table-danger') ?>">
                                        <td><?=$sr_no?></td>
                                        <td><?=htmlspecialchars($s['name'])?></td>
                                        <td>
                                            <input type="radio" name="status[<?=$s['id']?>]" value="P" 
                                                <?=($status=='P'?'checked':'')?> 
                                                onchange="updateRemarksSelect(<?=$s['id']?>); updateCounters();">
                                        </td>
                                        <td>
                                            <input type="radio" name="status[<?=$s['id']?>]" value="A" 
                                                <?=($status=='A'?'checked':'')?> 
                                                onchange="updateRemarksSelect(<?=$s['id']?>); updateCounters();">
                                        </td>
                                        <td>
                                            <input type="radio" name="status[<?=$s['id']?>]" value="L" 
                                                <?=($status=='L'?'checked':'')?> 
                                                onchange="updateRemarksSelect(<?=$s['id']?>); updateCounters();">
                                        </td>
                                        <td>
                                            <select id="remarks_<?=$s['id']?>" name="remarks[<?=$s['id']?>]" class="form-control remarks-select" 
                                                <?=($status=='L'?'':'disabled')?>>
                                                <option value="">Select Reason</option>
                                                <option value="Sick" <?=($remarks=='Sick'?'selected':'')?>>Sick</option>
                                                <option value="Leave" <?=($remarks=='Leave'?'selected':'')?>>Leave</option>
                                                <option value="Urgent work" <?=($remarks=='Urgent work'?'selected':'')?>>Urgent work</option>
                                                <option value="Other" <?=($remarks!='' && !in_array($remarks, ['Sick', 'Leave', 'Urgent work'])?'selected':'')?>>Other</option>
                                            </select>
                                        </td>
                                    </tr>
                                <?php 
                                $sr_no++;
                                endwhile; 
                                ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="text-right">
                            <button type="submit" class="btn btn-primary">
                                <span class="glyphicon glyphicon-refresh"></span> Update Attendance
                            </button>
                        </div>
                    </form>
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