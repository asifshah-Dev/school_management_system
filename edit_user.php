<?php
// 1. Connect to database
$conn = mysqli_connect("localhost", "root", "", "online_shop");

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// 2. Get user ID from URL
$id = intval($_GET['id']);

// 3. Get current user data
$sql = "SELECT * FROM users WHERE id = $id";
$result = mysqli_query($conn, $sql);
$user = mysqli_fetch_assoc($result);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit User</title>
    <link rel="stylesheet" href="bootstrap/bootstrap.min.css">
</head>
<body class="p-5">
<div class="container">
    <h2>Edit User</h2>
    <form action="update_users.php" method="post">
        <input type="hidden" name="id" value="<?php echo $user['id']; ?>">

        <div class="mb-3">
            <label class="form-label">Username:</label>
            <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Password:</label>
            <input type="text" name="password" class="form-control" value="<?php echo htmlspecialchars($user['password']); ?>" required>
        </div>

        <input type="submit" value="Update" class="btn btn-success">
        <a href="registration.php" class="btn btn-secondary">Cancel</a>
    </form>
</div>
</body>
</html>
