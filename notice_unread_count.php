<?php
header('Content-Type: application/json; charset=utf-8');
require_once('security.php');
require_once('conn_inc.php');
require_once('notice_helper.php');

echo json_encode(['count' => notice_unread_count($conn)]);
exit;