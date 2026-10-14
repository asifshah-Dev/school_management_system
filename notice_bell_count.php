<?php
/**
 * notice_bell_count.php
 * Outputs the unread-notice badge span for the topbar bell.
 * Requires: $conn (mysqli), session started.
 */
if (!isset($conn) || !($conn instanceof mysqli)) return;
if (!isset($_SESSION['user_id'])) return;

require_once(__DIR__ . '/notice_helper.php');

$__nbc = notice_unread_count($conn);
if ($__nbc > 0):
?>
<span class="dashboard-notification-count"><?= $__nbc > 99 ? '99+' : (int)$__nbc ?></span>
<?php endif; ?>