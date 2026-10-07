<?php
require_once('gl.php');

//echo "<pre>";



// Detect environment

if ($_SERVER['SERVER_NAME'] == 'localhost') {

    // Localhost configuration

    $servername = "localhost";

    $username = "root";

    $password = "";

    $dbname = "dsc_online";

    

} else {

    // Live server configuration

    $servername = "localhost";

    $username = "softray1_dm"; // <-- Confirm this is correct

    $password = "dm123!@#$%^&*()";

    $dbname = "softray1_dm";

   // echo "Environment: Live Server\n";

}



// echo "Server: $servername\n";

// echo "Username: $username\n";

// echo "Database: $dbname\n";



// Create connection

$conn = new mysqli($servername, $username, $password, $dbname);



// Check connection

if ($conn->connect_error) {

    echo "Connection failed:\n";

    // echo "Error Number: " . $conn->connect_errno . "\n";

    // echo "Error Message: " . $conn->connect_error . "\n";

    die("Database connection failed.");

} else {

    // echo "Connection successful!\n";

}



//echo "</pre>";

?>

