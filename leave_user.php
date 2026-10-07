<?php
ob_start();
require_once('conn_inc.php');

header('Content-Type: application/json');

// Suppress any stray output
ob_start();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid request method']);
        ob_end_flush();
        exit;
    }

    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $date = isset($_POST['date']) ? $_POST['date'] : date('Y-m-d');
    $remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';

    if (!$user_id || $remarks === '') {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Missing user_id or remarks']);
        ob_end_flush();
        exit;
    }

    // Validate user exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE id=?");
    if (!$stmt) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Statement preparation failed: ' . $conn->error]);
        ob_end_flush();
        exit;
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'User not found']);
        ob_end_flush();
        exit;
    }
    $stmt->close();

    // UPDATE first (turn any existing row into Leave), then INSERT if none existed
    $stmtUpd = $conn->prepare("UPDATE user_attendance SET status='L', remarks=? WHERE user_id=? AND dated=?");
    if (!$stmtUpd) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Statement preparation failed: ' . $conn->error]);
        ob_end_flush();
        exit;
    }
    $stmtUpd->bind_param("sis", $remarks, $user_id, $date);
    $stmtUpd->execute();

    if ($stmtUpd->affected_rows === 0) {
        // No row existed -> INSERT
        $stmtIns = $conn->prepare("INSERT INTO user_attendance (user_id, dated, status, remarks) VALUES (?, ?, 'L', ?)");
        if (!$stmtIns) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Statement preparation failed: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmtIns->bind_param("iss", $user_id, $date, $remarks);
        $stmtIns->execute();
        $stmtIns->close();
    }

    $stmtUpd->close();

    ob_clean();
    echo json_encode(['success' => true, 'message' => 'Marked as Leave']);
} catch (Throwable $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
} finally {
    ob_end_flush();
    if (isset($conn)) { $conn->close(); }
}