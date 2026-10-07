<?php
require_once('conn_inc.php');

// Default cut-off (24h format)
$default_gross_time = "08:30:00";
$current_time = date('H:i:s');

$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// --- Handle Present ---
if (isset($_POST['mark_present'])) {
    $student_id = intval($_POST['student_id']);

    // Check if late
    $status = (strtotime($current_time) > strtotime($default_gross_time)) ? 'L' : 'P';

    $check = $conn->query("SELECT id FROM attendance WHERE student_id=$student_id AND date='$date'");
    if ($check->num_rows > 0) {
        $conn->query("UPDATE attendance SET status='$status' WHERE student_id=$student_id AND date='$date'");
    } else {
        $conn->query("INSERT INTO attendance (student_id, date, status) VALUES ($student_id, '$date', '$status')");
    }
    exit("ok");
}

// --- Handle Leave ---
if (isset($_POST['mark_leave'])) {
    $student_id = intval($_POST['student_id']);

    $check = $conn->query("SELECT id FROM attendance WHERE student_id=$student_id AND date='$date'");
    if ($check->num_rows > 0) {
        $conn->query("UPDATE attendance SET status='LV' WHERE student_id=$student_id AND date='$date'");
    } else {
        $conn->query("INSERT INTO attendance (student_id, date, status) VALUES ($student_id, '$date', 'LV')");
    }
    exit("ok");
}

// --- Show students ---
$students = $conn->query("SELECT id, name FROM students ORDER BY name");

// --- Show today's attendance ---
$attendance = $conn->query("
    SELECT a.id, s.name, a.status 
    FROM attendance a 
    JOIN students s ON a.student_id=s.id 
    WHERE a.date='$date'
");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Attendance</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>

<h2>Attendance <?php echo $date; ?></h2>
<p>Server time: <?php echo $current_time; ?> | Cut-off: <?php echo $default_gross_time; ?></p>

<table border="1" cellpadding="5">
<tr><th>Student</th><th>Actions</th></tr>
<?php while($row = $students->fetch_assoc()) { ?>
<tr>
    <td><?php echo $row['name']; ?></td>
    <td>
        <button onclick="markPresent(<?php echo $row['id']; ?>)">Present</button>
        <button onclick="markLeave(<?php echo $row['id']; ?>)">Leave</button>
    </td>
</tr>
<?php } ?>
</table>

<h3>Today’s Attendance</h3>
<table border="1" cellpadding="5">
<tr><th>Name</th><th>Status</th></tr>
<?php while($row = $attendance->fetch_assoc()) { ?>
<tr>
    <td><?php echo $row['name']; ?></td>
    <td><?php echo $row['status']; ?></td>
</tr>
<?php } ?>
</table>

<script>
function markPresent(student_id) {
    $.post("", {mark_present:1, student_id:student_id}, function(res){
        location.reload();
    });
}
function markLeave(student_id) {
    $.post("", {mark_leave:1, student_id:student_id}, function(res){
        location.reload();
    });
}
</script>

</body>
</html>
