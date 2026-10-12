<?php
require_once('security.php');
/* ==== AUTHORIZATION GATE ==== */
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
/* ==== END AUTHORIZATION GATE ==== */
require_once('conn_inc.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id']);
    $title = trim($_POST['title']);

    if (!empty($title) && $id > 0) {
        $stmt = $conn->prepare("UPDATE village_councils SET title = ? WHERE id = ?");
        $stmt->bind_param("si", $title, $id);

        if ($stmt->execute()) {
            echo 'success';
        } else {
            echo 'error: '.$stmt->error;
        }

        $stmt->close();
    } else {
        echo 'Invalid data';
    }
}

$conn->close();
?>