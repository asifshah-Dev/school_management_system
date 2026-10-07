<?php
require_once('conn_inc.php');

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = isset($_POST['student_id']) ? intval($_POST['student_id']) : 0;
    $date = isset($_POST['date']) ? trim($_POST['date']) : '';
    $remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';

    // Validate inputs
    if ($student_id <= 0 || empty($date)) {
        $response['message'] = 'Invalid student ID or date';
        echo json_encode($response);
        exit;
    }

    // Validate date format (YYYY-MM-DD)
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $response['message'] = 'Invalid date format';
        echo json_encode($response);
        exit;
    }

    try {
        // Prepare and execute the query to insert or update attendance
        $stmt = $conn->prepare("
            INSERT INTO attendance (student_id, date, status, remarks) 
            VALUES (?, ?, 'L', ?) 
            ON DUPLICATE KEY UPDATE status = 'L', remarks = ?
        ");
        $stmt->bind_param("isss", $student_id, $date, $remarks, $remarks);
        $stmt->execute();

        $response['success'] = true;
        $response['message'] = "Marked Leave for student ID $student_id";
    } catch (Exception $e) {
        $response['message'] = "Database error: " . $e->getMessage();
    }
} else {
    $response['message'] = 'Invalid request method';
}

echo json_encode($response);
exit;
?>