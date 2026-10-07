<?php
require_once('security.php');
require_once('conn_inc.php');

// Log POST data for debugging
error_log("POST data: " . print_r($_POST, true));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['students']) && isset($_POST['status']) && !empty($_POST['class_id'])) {
    $student_ids = $_POST['students'];
    $status = (int)$_POST['status'];
    $class_id = $_POST['class_id'];
    $session_id = 5; // Match session_id from student_status.php

    // Validate status
    if (!in_array($status, [0, 1, 2])) {
        error_log("Invalid status: $status");
        header("Location: student_status.php?class_id=" . urlencode($class_id) . "&error=Invalid status selected");
        $conn->close();
        exit();
    }

    // Prepare update query
    $stmt = $conn->prepare("UPDATE student_registration SET status = ? WHERE id = ?");

    $success = true;
    foreach ($student_ids as $student_id) {
        $student_id = (int)$student_id; // sanitize
        $stmt->bind_param("ii", $status, $student_id);
        if (!$stmt->execute()) {
            $success = false;
            error_log("Failed to update status for student ID $student_id: " . $conn->error);
            break;
        }
    }

    $stmt->close();

    // Redirect back with result
    if ($success) {
        header("Location: student_status.php?class_id=" . urlencode($class_id) . "&success=Student status updated successfully");
    } else {
        header("Location: student_status.php?class_id=" . urlencode($class_id) . "&error=Failed to update student status");
    }
} else {
    // Log missing fields
    $missing = [];
    if (empty($_POST['students'])) $missing[] = 'students';
    if (!isset($_POST['status'])) $missing[] = 'status';
    if (empty($_POST['class_id'])) $missing[] = 'class_id';
    error_log("Status update failed - Missing: " . implode(', ', $missing));

    // Fallback for class_id
    $class_id = $_POST['class_id'] ?? ($_GET['class_id'] ?? '');
    header("Location: student_status.php?class_id=" . urlencode($class_id) . "&error=Please select at least one student and a status");
}

$conn->close();
exit();
?>
