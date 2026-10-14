<?php
ob_start();
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
if (session_status() === PHP_SESSION_NONE) session_start();

require_once('conn_inc.php');

if (empty($perms['can_delete'])) {
    $_SESSION['message'] = 'You do not have permission to archive notices.';
    $_SESSION['message_type'] = 'danger';
    header('Location: notice_list.php'); exit;
}
if (($_GET['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
    $_SESSION['message'] = 'Invalid request token.';
    $_SESSION['message_type'] = 'danger';
    header('Location: notice_list.php'); exit;
}

$notice_id = (int)($_GET['id'] ?? 0);
if ($notice_id <= 0) { header('Location: notice_list.php'); exit; }

$stmt = $conn->prepare("UPDATE notices SET status = 1 WHERE id = ?");
$stmt->bind_param("i", $notice_id);
$stmt->execute();
$stmt->close();

$_SESSION['message'] = 'Notice archived.';
$_SESSION['message_type'] = 'success';
header('Location: notice_list.php');
exit;