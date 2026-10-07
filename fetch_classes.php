<?php
session_start();
require_once('conn_inc.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['exam_type_id']) && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $exam_type_id = intval($_POST['exam_type_id']);
    $classes = [];

    $stmt = $conn->prepare("SELECT DISTINCT c.id, c.title FROM classes c JOIN arrange_exam ae ON c.id = ae.class_id WHERE ae.exam_type_id = ? ORDER BY c.title");
    $stmt->bind_param("i", $exam_type_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $classes[$row['id']] = $row['title'];
    }
    $stmt->close();

    echo json_encode(['success' => true, 'classes' => $classes]);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request or CSRF token.']);
}
$conn->close();
?>