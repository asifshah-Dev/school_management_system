<?php
require_once('conn_inc.php');

$class_id = intval($_GET['class_id']);
$session_id = intval($_GET['session_id']);
$class_id = 13;
$query = "SELECT sr.id, sr.name, sr.father_name 
          FROM student_registration sr
          JOIN student_class sc ON sr.id = sc.student_registration_id
          WHERE sc.class_id = ? AND sc.session_id = ?
          ORDER BY sr.name";
$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $class_id, $session_id);
$stmt->execute();
$result = $stmt->get_result();

$students = [];
while ($row = $result->fetch_assoc()) {
    $students[] = $row;
}

header('Content-Type: application/json');
echo json_encode($students);
?>