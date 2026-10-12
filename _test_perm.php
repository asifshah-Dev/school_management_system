<?php
require_once('security.php');
/* ==== AUTHORIZATION GATE ==== */
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
/* ==== END AUTHORIZATION GATE ==== */require_once('conn_inc.php');
require_once('permissions.php');

header('Content-Type: text/plain');

$userId = (int)$_SESSION['user_id'];
echo "Session user_id: $userId\n";
echo "Script: " . basename($_SERVER['SCRIPT_NAME']) . "\n\n";

// What module_id does permissions.php find for user_registration.php?
$basename = 'user_registration.php';
$stmt = $conn->prepare("
    SELECT id, url, status FROM modules
    WHERE LOWER(SUBSTRING_INDEX(url, '/', -1)) = ?
      AND status = 1
    ORDER BY id ASC
");
$stmt->bind_param("s", $basename);
$stmt->execute();
$mods = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo "Modules matching user_registration.php:\n";
print_r($mods);

$perms = current_user_can($conn, $basename, 'add');
echo "\ncurrent_user_can(..., 'add') => " . ($perms ? 'TRUE' : 'FALSE') . "\n";

$perms_all = gl_user_can($conn, $userId, $basename);
echo "\nFull perms array:\n";
print_r($perms_all);

// What's in user_modules for this user?
$stmt = $conn->prepare("SELECT module_id, can_view, can_add, can_edit, can_delete FROM user_modules WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo "\nuser_modules rows for this user:\n";
print_r($rows);