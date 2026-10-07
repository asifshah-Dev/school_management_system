<?php
// 1. Connect to the database
$conn = new mysqli("localhost", "root", "", "online_shop");

// 2. Check for connection errors
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// 3. Get user input from form
 $username = $_POST['username']; 
 //echo $username; die();
 $password = $_POST['password']; 



// 4. Check if username already exists
 $check_sql = "SELECT * FROM users WHERE username = '$username'"; 
 $check_result = $conn->query($check_sql); 
 
 //var_dump($check_result);

 //echo $check_result->num_rows; die();

if ($check_result->num_rows > 0) {
    echo "❌ Username already exists. Try another one.";
} else {
    // 5. Insert new user into database
   $insert_sql = "INSERT INTO users (username, password) VALUES ('$username', '$password')"; 

    if ($conn->query($insert_sql) === TRUE) {
        echo "✅ Registration successful! <a href='login.html'>Click here to login</a>.";
    } else {
        echo "❌ Error: " . $conn->error;
    }
}

// 6. Close the database connection
$conn->close();
?>
