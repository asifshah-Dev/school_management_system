<?php
require_once('conn_inc.php');

$role_id = intval($_GET['role_id']);
$stmt = $conn->prepare("SELECT can_view, can_add, can_edit, can_delete FROM roles WHERE id=?");
$stmt->bind_param("i", $role_id);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stmt->close();

echo json_encode($result);
?>