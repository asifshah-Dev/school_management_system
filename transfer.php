<?php
ob_start();
require_once('security.php');
require_once('conn_inc.php');

// Log all POST data for debugging
error_log("POST data: " . print_r($_POST, true));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['students']) && !empty($_POST['transfer_class_id']) && !empty($_POST['class_id'])) {
    $student_ids = $_POST['students'];
    $transfer_class_id = $_POST['transfer_class_id'];
    $class_id = $_POST['class_id'];
    $session_id = 5; // Match session_id from transfer_stu.php

    // Update class for selected students
    $stmt = $conn->prepare("UPDATE student_class SET class_id = ? WHERE student_registration_id = ? AND session_id = ?");
    $stmt->bind_param("iii", $transfer_class_id, $student_id, $session_id);

    $success = true;
    foreach ($student_ids as $student_id) {
        if (!$stmt->execute()) {
            $success = false;
            error_log("Failed to transfer student ID $student_id: " . $conn->error);
            break;
        }
    }

    // Close the statement
    $stmt->close();

    // Redirect to transfer_stu.php with appropriate message
    if ($success) {
        header("Location: transfer_stu.php?class_id=" . urlencode($class_id) . "&success=Students transferred successfully");
    } else {
        header("Location: transfer_stu.php?class_id=" . urlencode($class_id) . "&error=Failed to transfer students");
    }
} else {
    // Log specific missing fields
    $missing = [];
    if (empty($_POST['students'])) $missing[] = 'students';
    if (empty($_POST['transfer_class_id'])) $missing[] = 'transfer_class_id';
    if (empty($_POST['class_id'])) $missing[] = 'class_id';
    error_log("Transfer failed - Missing: " . implode(', ', $missing));

    // Fallback for class_id
    $class_id = isset($_POST['class_id']) ? $_POST['class_id'] : (isset($_GET['class_id']) ? $_GET['class_id'] : '');
    header("Location: transfer_stu.php?class_id=" . urlencode($class_id) . "&error=Please select at least one student and target class");
}

$conn->close();
exit();
?>