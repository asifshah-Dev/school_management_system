<?php
$conn = mysqli_connect("localhost", "root", "", "online_shop");

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

$id = intval($_POST['id']);
$username = $_POST['username'];
$password = $_POST['password'];

$sql = "UPDATE users SET username='$username', password='$password' WHERE id=$id";

if (mysqli_query($conn, $sql)) {
    echo "<p style='color:green'>User updated successfully.</p>";
    header("Location: registration.php"); // Replace with your main file
    exit();
} else {
    echo "Error updating user: " . mysqli_error($conn);
}
?>
