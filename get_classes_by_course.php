<?php
require_once('conn_inc.php');

if (isset($_GET['course_id'])) {
    $course_id = intval($_GET['course_id']);

    $stmt = $conn->prepare("
        SELECT cl.id, cl.title 
        FROM course_classes cc
        JOIN classes cl ON cc.class_id = cl.id
        WHERE cc.course_id = ?
        ORDER BY cl.title
    ");
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $classes = [];
    while ($row = $result->fetch_assoc()) {
        $classes[] = $row;
    }

    echo json_encode($classes);
}
?>
