<?php
session_start();
require_once('conn_inc.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['class_id']) && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $class_id = intval($_POST['class_id']);
    $sections = [];

    $stmt = $conn->prepare("SELECT s.id, s.title FROM sections s JOIN class_sections cs ON s.id = cs.section_id WHERE cs.class_id = ? ORDER BY s.title");
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $sections[$row['id']] = $row['title'];
    }
    $stmt->close();

    echo json_encode(['success' => true, 'sections' => $sections]);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request or CSRF token.']);
}
$conn->close();
?>