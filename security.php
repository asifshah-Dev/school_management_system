<?php
/**
 * security.php — session bootstrap + automatic per-user module gate
 * for the VIEW action only. Page-level add/edit/delete gating is
 * handled by require_action() called explicitly on write pages.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- login gate ---
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: login.php');
    exit;
}

// --- load connection (idempotent) ---
if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once(__DIR__ . '/conn_inc.php');
}

// --- load permission resolver (idempotent) ---
if (!function_exists('gl_user_can')) {
    require_once(__DIR__ . '/permissions.php');
}

// --- pages that bypass the automatic view gate ---
$currentScript = strtolower(basename($_SERVER['SCRIPT_NAME'] ?? ''));
$skipList = [
    'login.php', 'logout.php', '403.php',
    'user_permissions.php', 'permissions.php',
    'security.php', 'conn_inc.php',
    'gl_test_hub.php', 'gl_test_hub_reset.php',
];
if (in_array($currentScript, $skipList, true)) {
    return;
}

// --- module lookup (only enforce can_view here) ---
$userId = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("
    SELECT id FROM modules
    WHERE LOWER(SUBSTRING_INDEX(url, '/', -1)) = ?
      AND status = 1
    ORDER BY id ASC
    LIMIT 1
");
$stmt->bind_param("s", $currentScript);
$stmt->execute();
$mod = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Page not registered as a module -> allow (fail-open for unlisted pages)
if (!$mod) {
    return;
}

// Registered -> enforce can_view
if (!current_user_can($conn, $currentScript, 'view')) {
    header('Location: 403.php');
    exit;
}