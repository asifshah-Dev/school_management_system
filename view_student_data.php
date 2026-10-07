<?php
require_once 'conn_inc.php';
require_once 'security.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 12;
$offset = ($page - 1) * $limit;
$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$search = isset($_GET['search']) ? $_GET['search'] : '';

define('CURRENT_SESSION_ID', 6);

// Build conditions
$conditions = ["sr.status IN (0, 1, 2)"];

// Status filter
if ($status !== 'all' && in_array($status, ['0', '1', '2'])) {
    $conditions[] = "sr.status = " . intval($status);
}

// Class filter - Show ONLY students whose CURRENT class (status = 0) matches the selected class
if ($class_id > 0) {
    $conditions[] = "EXISTS (
        SELECT 1 FROM student_class sc2 
        WHERE sc2.student_registration_id = sr.id 
        AND sc2.class_id = $class_id 
        AND sc2.status = 0
        AND sc2.session_id = " . CURRENT_SESSION_ID . "
    )";
}

// Search filter
if (!empty($search)) {
    $search = mysqli_real_escape_string($conn, $search);
    $conditions[] = "(sr.name LIKE '%$search%' 
                     OR sr.reg_no LIKE '%$search%' 
                     OR sr.father_name LIKE '%$search%' 
                     OR sr.mobile LIKE '%$search%'
                     OR sr.cnic LIKE '%$search%')";
}

$where_clause = "WHERE " . implode(" AND ", $conditions);

// Count total for pagination
$count_sql = "SELECT COUNT(DISTINCT sr.id) as total FROM student_registration sr $where_clause";
$count_result = $conn->query($count_sql);
$total = $count_result->fetch_assoc()['total'];
$has_more = ($offset + $limit) < $total;

// Main query
$sql = "SELECT 
            sr.id,
            sr.reg_no,
            sr.name,
            sr.dob,
            sr.gender,
            sr.father_name,
            sr.mobile,
            sr.cnic,
            sr.registration_date,
            sr.status as school_status,
            sr.is_admission,
            sr.is_transport,
            (SELECT c.title 
             FROM student_class sc 
             LEFT JOIN classes c ON sc.class_id = c.id 
             WHERE sc.student_registration_id = sr.id 
             AND sc.status = 0 
             AND sc.session_id = " . CURRENT_SESSION_ID . "
             LIMIT 1) as current_class,
            (SELECT s.title 
             FROM student_class sc 
             LEFT JOIN sessions s ON sc.session_id = s.id 
             WHERE sc.student_registration_id = sr.id 
             AND sc.status = 0 
             AND sc.session_id = " . CURRENT_SESSION_ID . "
             LIMIT 1) as current_session_title,
            GROUP_CONCAT(
                DISTINCT CONCAT(
                    COALESCE(c.title, 'Unknown'), '|', 
                    COALESCE(s.title, 'Unknown')
                )
                ORDER BY sc.id ASC 
                SEPARATOR ';;'
            ) as previous_classes
        FROM student_registration sr
        LEFT JOIN student_class sc ON sr.id = sc.student_registration_id AND sc.status = 1
        LEFT JOIN classes c ON sc.class_id = c.id AND c.status = 1
        LEFT JOIN sessions s ON sc.session_id = s.id AND s.status = 1
        $where_clause
        GROUP BY sr.id
        ORDER BY sr.id DESC
        LIMIT $offset, $limit";

$result = $conn->query($sql);
$students = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $previous_classes = [];
        if ($row['previous_classes']) {
            $parts = explode(';;', $row['previous_classes']);
            foreach ($parts as $part) {
                $details = explode('|', $part);
                if (count($details) >= 2) {
                    $previous_classes[] = [
                        'class' => $details[0],
                        'session' => $details[1]
                    ];
                }
            }
        }
        
        $students[] = [
            'id' => $row['id'],
            'reg_no' => $row['reg_no'],
            'name' => $row['name'],
            'dob' => $row['dob'],
            'gender' => $row['gender'],
            'father_name' => $row['father_name'],
            'mobile' => $row['mobile'],
            'cnic' => $row['cnic'],
            'registration_date' => $row['registration_date'],
            'school_status' => $row['school_status'],
            'is_admission' => $row['is_admission'],
            'is_transport' => $row['is_transport'],
            'current_class' => $row['current_class'],
            'current_session_title' => $row['current_session_title'],
            'previous_classes' => $previous_classes
        ];
    }
}

echo json_encode([
    'success' => true,
    'students' => $students,
    'has_more' => $has_more,
    'total' => $total,
    'page' => $page,
    'filters' => [
        'status' => $status,
        'class_id' => $class_id,
        'search' => $search
    ]
]);
?>