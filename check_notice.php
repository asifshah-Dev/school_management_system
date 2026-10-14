<?php
require_once('security.php');
require_once('conn_inc.php');
require_once('notice_helper.php');

$uid = (int)$_SESSION['user_id'];
$role = (int)$conn->query("SELECT role_id FROM users WHERE id = $uid")->fetch_assoc()['role_id'];

echo "<pre>";
echo "User ID: $uid\n";
echo "Role:    $role\n\n";

echo "Total notices:      " . $conn->query("SELECT COUNT(*) c FROM notices")->fetch_assoc()['c'] . "\n";
echo "Active (by NOW()):  " . $conn->query("SELECT COUNT(*) c FROM notices n WHERE " . notice_active_sql())->fetch_assoc()['c'] . "\n";

$aud = notice_audience_sql_for_user($conn);
echo "Audience SQL:       $aud\n\n";

$visible = $conn->query("SELECT COUNT(*) c FROM notices n WHERE " . notice_active_sql() . " AND $aud")->fetch_assoc()['c'];
echo "Visible to you:     $visible\n";
echo "Unread:             " . notice_unread_count($conn) . "\n\n";

echo "Read rows for you:  " . $conn->query("SELECT COUNT(*) c FROM notice_reads WHERE user_id = $uid")->fetch_assoc()['c'] . "\n";

echo "</pre>";