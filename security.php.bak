<?php
session_start();

// Optional: Prevent this file from running on login.php
if (basename($_SERVER['PHP_SELF']) === 'login.php') {
    return; // or exit;
}

// Canary setup
if (!isset($_SESSION['canary'])) {
    session_regenerate_id(true);
    $_SESSION['canary'] = time();
}

// Regenerate session ID every 5 minutes for security
if (isset($_SESSION['canary']) && $_SESSION['canary'] < time() - 300) {
    session_regenerate_id(true);
    $_SESSION['canary'] = time();
}

// Define required session variables
$required_vars = ['user_id', 'username', 'role_id'];

// Validate session
$session_valid = true;
foreach ($required_vars as $var) {
    if (!isset($_SESSION[$var]) || empty($_SESSION[$var])) {
        $session_valid = false;
        break;
    }
}

// If session is invalid, destroy it and redirect to login
if (!$session_valid) {
    // Save error message temporarily using session
    $_SESSION['login_error'] = 'Session expired or invalid. Please login again.';

    // Clear session
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();

    // Redirect to login
    header("Location: login.php");
    exit();
}

// If everything is fine, assign session values
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role_id = $_SESSION['role_id'];
?>
<?php

// Optional: Prevent this file from running on login.php
if (basename($_SERVER['PHP_SELF']) === 'login.php') {
    return; // or exit;
}

// Canary setup
if (!isset($_SESSION['canary'])) {
    session_regenerate_id(true);
    $_SESSION['canary'] = time();
}

// Regenerate session ID every 5 minutes for security
if (isset($_SESSION['canary']) && $_SESSION['canary'] < time() - 300) {
    session_regenerate_id(true);
    $_SESSION['canary'] = time();
}

// Define required session variables
$required_vars = ['user_id', 'username', 'role_id'];

// Validate session
$session_valid = true;
foreach ($required_vars as $var) {
    if (!isset($_SESSION[$var]) || empty($_SESSION[$var])) {
        $session_valid = false;
        break;
    }
}

// If session is invalid, destroy it and redirect to login
if (!$session_valid) {
    // Save error message temporarily using session
    $_SESSION['login_error'] = 'Session expired or invalid. Please login again.';

    // Clear session
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();

    // Redirect to login
    header("Location: login.php");
    exit();
}

// If everything is fine, assign session values
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role_id = $_SESSION['role_id'];
?>
