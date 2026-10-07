<?php
ob_start();
require_once 'conn_inc.php';

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'error_log.txt');

// Check database connection
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Get search query
$search_query = isset($_POST['search_query']) ? trim($_POST['search_query']) : '';
error_log("Received POST data: " . json_encode($_POST));

if (empty($search_query)) {
    error_log("Empty search query received");
    echo json_encode(['error' => 'Empty search query']);
    exit;
}

error_log("Processing search query: " . $search_query);

// Prepare SQL query to get student details with their current class
$search_query = $conn->real_escape_string($search_query);
$sql = "
    SELECT 
        sr.id,
        sr.name,
        sr.father_name,
        sr.mobile,
        sr.cnic,
        COALESCE(vc.title, 'N/A') AS village_council,
        CASE 
            WHEN c.title IS NOT NULL THEN c.title
            ELSE 'Not enrolled'
        END AS current_class
    FROM student_registration sr
    LEFT JOIN village_councils vc ON sr.village_council_id = vc.id
    LEFT JOIN (
        SELECT sc1.student_registration_id, sc1.class_id
        FROM student_class sc1
        INNER JOIN (
            SELECT student_registration_id, MAX(session_id) AS max_session, MAX(id) AS max_id
            FROM student_class
            WHERE status = 0
            GROUP BY student_registration_id
        ) sc2 ON sc1.student_registration_id = sc2.student_registration_id 
               AND sc1.session_id = sc2.max_session
               AND sc1.id = sc2.max_id
    ) current_enrollment ON sr.id = current_enrollment.student_registration_id
    LEFT JOIN classes c ON current_enrollment.class_id = c.id
    WHERE sr.id = ? OR sr.name LIKE ? 
       OR sr.father_name LIKE ? 
       OR sr.mobile LIKE ? 
       OR sr.cnic LIKE ?
    ORDER BY sr.id
";

if (!$stmt = $conn->prepare($sql)) {
    error_log("SQL prepare error: " . $conn->error);
    echo json_encode(['error' => 'SQL prepare failed']);
    exit;
}

$like_query = "%$search_query%";
// Bind parameters (id as string for exact match, and like queries for text fields)
$stmt->bind_param("sssss", $search_query, $like_query, $like_query, $like_query, $like_query);

if (!$stmt->execute()) {
    error_log("SQL execute error: " . $stmt->error);
    echo json_encode(['error' => 'SQL execution failed']);
    exit;
}

$result = $stmt->get_result();
$students = [];

while ($row = $result->fetch_assoc()) {
    // If class is NULL or empty, set to 'Not enrolled'
    if (empty($row['current_class']) || $row['current_class'] === null) {
        $row['current_class'] = 'Not enrolled';
    }
    $students[] = $row;
}

error_log("Search results count: " . count($students) . " for query: " . $search_query);

// Return JSON response
header('Content-Type: application/json');
echo json_encode($students);

$stmt->close();
$conn->close();
?>