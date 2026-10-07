<?php
// Make sure no output is sent before headers
ob_start();
session_start();
require_once('conn_inc.php');

// ===========================
// 2. Receive Form Data
// ===========================
$username = $_POST['username']; 
$password = $_POST['password'];

// ===========================
// 3. Query the Database for User
// ===========================
// Changed status to 1 for active users
$sql = "SELECT id, role_id, username, password FROM users WHERE username = ? AND status = 0";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

// ===========================
// 4. Check If User Exists
// ===========================
if ($result->num_rows === 1) {
    $row = $result->fetch_assoc();

    // ===========================
    // 5. Check the Password
    // ===========================
    if ($row['password'] === $password) {
        // Store user data in session
        $_SESSION['user_id'] = $row['id'];
        $_SESSION['username'] = $row['username'];
        $_SESSION['role_id'] = $row['role_id'];
        
        // Clear output buffer before redirect
        ob_end_clean();
       
        // Redirect to index.php
        echo "<script>window.location.href = 'index.php';</script>";
        exit(); // optional but recommended
    } else {

        $_SESSION['error'] = "❌ Incorrect password.";
        ob_end_clean();
        header("Location: login.php");
        exit();
    }
} else {
    echo 'sory'; die();
    $_SESSION['error'] = "❌ User not found or inactive.";
    ob_end_clean();
    header("Location: login.php");
    exit();
}

// ===========================
// 6. Close the Connection
// ===========================
$stmt->close();
$conn->close();
?>