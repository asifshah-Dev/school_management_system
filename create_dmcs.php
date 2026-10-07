<?php
ob_start();
require_once('security.php');
$edit_mode = false;
$teacher_id = $_SESSION['user_id'];

// Initialize session if not started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Generate CSRF token if not set
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Create database connection
require_once('conn_inc.php');

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Determine user role
$is_admin = false;
$stmt = $conn->prepare("SELECT r.title FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $role = $result->fetch_assoc()['title'];
    $is_admin = strcasecmp($role, 'admin') === 0;
}
$stmt->close();

// Get filter parameters
$filter_class = isset($_GET['filter_class']) ? intval($_GET['filter_class']) : 0;
$filter_exam_type = isset($_GET['filter_exam_type']) ? intval($_GET['filter_exam_type']) : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid CSRF token: " . ($_POST['csrf_token'] ?? 'not set') . " vs " . $_SESSION['csrf_token']);
    }

    // Sanitize and validate input
    $class_id = intval($_POST['class_id']);
    $exam_type_id = intval($_POST['exam_type_id']);
    $dated = $_POST['dated'];
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

    // Validate inputs
    if ($class_id <= 0 || $exam_type_id <= 0 || empty($dated)) {
        $_SESSION['message'] = "Invalid input: Please fill all fields correctly.";
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Ensure date format
    $dated = date('Y-m-d', strtotime($dated));

    if ($id > 0) {
        // Update existing record
        $stmt = $conn->prepare("UPDATE arrange_exam SET class_id = ?, exam_type_id = ?, start_date = ? WHERE id = ?");
        $stmt->bind_param("iisi", $class_id, $exam_type_id, $dated, $id);
    } else {
        // Insert new record
        $stmt = $conn->prepare("INSERT INTO arrange_exam (exam_type_id, class_id, start_date) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $exam_type_id, $class_id, $dated);
    }

    if (!$stmt->execute()) {
        $_SESSION['message'] = "Error: " . $stmt->error;
        $_SESSION['message_type'] = 'danger';
        error_log("SQL Error: " . $stmt->error, 3, 'errors.log');
    } else {
        $_SESSION['message'] = $id > 0 ? "Record updated successfully." : "Record added successfully.";
        $_SESSION['message_type'] = 'success';
    }

    $stmt->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle exam deletion
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    
    $stmt = $conn->prepare("DELETE FROM arrange_exam WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = 'Exam deleted successfully!';
        $_SESSION['message_type'] = 'success';
    } else {
        error_log("Database error: " . $stmt->error, 3, 'errors.log');
        $_SESSION['message'] = 'Error: An unexpected error occurred.';
        $_SESSION['message_type'] = 'danger';
    }
    
    $stmt->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Get dropdown options for filters
$classes = [];
if ($is_admin) {
    // Admin can see all classes
    $course_result = $conn->query("SELECT id, title FROM classes ORDER BY title");
} else {
    // Non-admin can only see classes they teach
    $stmt = $conn->prepare("SELECT c.id, c.title 
                           FROM classes c 
                           JOIN teacher_classes tc ON c.id = tc.class_section_id 
                           WHERE tc.teacher_id = ? 
                           ORDER BY c.title");
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $course_result = $stmt->get_result();
}

while ($row = $course_result->fetch_assoc()) {
    $classes[$row['id']] = $row['title'];
}
if (!$is_admin) {
    $stmt->close();
} else {
    $course_result->close();
}

$exam_types = [];
$types_result = $conn->query("SELECT id, title FROM exam_types ORDER BY title");
while ($row2 = $types_result->fetch_assoc()) {
    $exam_types[$row2['id']] = $row2['title'];
}
$types_result->close();

// Build WHERE clause for filtering
$where_conditions = [];
$params = [];
$param_types = "";

// Add teacher filter if not admin (must be first for non-admin)
if (!$is_admin) {
    $where_conditions[] = "tc.teacher_id = ?";
    $params[] = $teacher_id;
    $param_types .= "i";
}

if ($filter_class > 0) {
    $where_conditions[] = "es.class_id = ?";
    $params[] = $filter_class;
    $param_types .= "i";
}

if ($filter_exam_type > 0) {
    $where_conditions[] = "es.exam_type_id = ?";
    $params[] = $filter_exam_type;
    $param_types .= "i";
}

// Build the query
if ($is_admin) {
    $sql = "
        SELECT es.id, es.class_id, es.exam_type_id, es.start_date, 
               c.title AS class_title, et.title AS exam_type_title
        FROM arrange_exam es
        JOIN classes c ON es.class_id = c.id
        JOIN exam_types et ON es.exam_type_id = et.id
    ";
} else {
    $sql = "
        SELECT es.id, es.class_id, es.exam_type_id, es.start_date, 
               c.title AS class_title, et.title AS exam_type_title
        FROM arrange_exam es
        JOIN classes c ON es.class_id = c.id
        JOIN exam_types et ON es.exam_type_id = et.id
        JOIN teacher_classes tc ON c.id = tc.class_section_id
    ";
}

// Add WHERE conditions if any
if (!empty($where_conditions)) {
    $sql .= " WHERE " . implode(' AND ', $where_conditions);
}

// Add ORDER BY
$sql .= " ORDER BY es.start_date ASC, c.title ASC";

// Prepare and execute query with parameters
$stmt = $conn->prepare($sql);

// Bind parameters if any
if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$exam_list = [];
while ($row = $result->fetch_assoc()) {
    $exam_list[] = $row;
}
$stmt->close();

// Define color palette
$colors = [
    '#e6f3ff', '#e6ffe6', '#fff3e6', '#ffe6e6', 
    '#f3e6ff', '#e6fff3', '#ffffe6', '#f0f0f0'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Exam Management - Create DMCs</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
</head>
<style>
    .table th,
    .table td {
        text-align: left;
        vertical-align: middle;
    }

    .alert {
        margin-bottom: 20px;
    }

    .select2-container {
        width: 100% !important;
    }

    .select2-container--disabled .select2-selection {
        background-color: #f5f5f5;
    }

    .marks-input {
        width: 80px;
    }

    .action-btn {
        margin: 0 5px;
    }

    .popup-alert {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 1050;
        min-width: 300px;
    }

    .filter-section {
        background-color: #f8f9fa;
        padding: 15px;
        border-radius: 5px;
        margin-bottom: 20px;
        border: 1px solid #dee2e6;
    }

    .filter-section h4 {
        margin-top: 0;
        margin-bottom: 15px;
        color: #495057;
    }

    .filter-group {
        margin-bottom: 10px;
    }

    .filter-group label {
        font-weight: 600;
        margin-bottom: 5px;
        display: block;
        color: #495057;
    }

    .filter-btn-group {
        margin-top: 15px;
        text-align: right;
    }

    .clear-filters {
        margin-right: 10px;
    }

    /* Mobile-specific styles for screens smaller than 768px */
    @media (max-width: 768px) {
        body {
            padding: 15px;
            font-size: 1.1rem;
            line-height: 1.6;
            background-color: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: #2c3e50;
        }

        .container {
            max-width: 100%;
            padding: 20px;
            margin: 0 auto;
            background: #ffffff;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            border-radius: 10px;
        }

        h1, .panel-title {
            font-size: 1.8rem;
            text-align: center;
            margin-bottom: 20px;
            color: white;
        }

        h2 {
            font-size: 1.4rem;
            margin-bottom: 15px;
        }

        /* Filter section mobile styles */
        .filter-section {
            padding: 12px;
            margin-bottom: 15px;
        }

        .filter-group {
            margin-bottom: 12px;
        }

        .filter-group label {
            font-size: 1rem;
            margin-bottom: 8px;
        }

        .filter-btn-group {
            text-align: center;
            margin-top: 20px;
        }

        .filter-btn-group .btn {
            width: 48%;
            margin: 1%;
            padding: 10px;
            font-size: 1rem;
        }

        .clear-filters {
            margin-right: 2%;
        }

        /* Card view for table */
        .table-responsive {
            overflow-x: hidden;
            -webkit-overflow-scrolling: touch;
            margin-bottom: 20px;
        }

        .table {
            width: 100%;
            background: transparent;
        }

        thead {
            display: none;
        }

        tbody {
            display: block;
        }

        tr {
            display: block;
            margin-bottom: 20px;
            background: #ffffff;
            border: 1px solid #dfe4ea;
            border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
            padding: 15px;
        }

        td {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 15px;
            border-bottom: 1px solid #eceff1;
            font-size: 0.95rem;
            color: #2c3e50;
        }

        td:last-child {
            border-bottom: none;
        }

        td::before {
            content: attr(data-label);
            font-weight: 600;
            font-size: 1rem;
            color: #1a252f;
            flex: 0 0 45%;
            text-align: left;
        }

        td > *:not(:first-child) {
            flex: 0 0 50%;
            text-align: right;
        }

        /* Marks display, inputs, and buttons */
        .marks-display {
            font-size: 0.95rem;
            color: #2c3e50;
        }

        .marks-input {
            width: 100%;
            padding: 10px;
            font-size: 1rem;
            border: 1px solid #ced4da;
            border-radius: 6px;
            background-color: #f9fbfc;
        }

        .action-btn {
            display: inline-block;
            width: 100%;
            padding: 10px;
            font-size: 1rem;
            border-radius: 6px;
            margin: 5px 0;
            text-align: center;
            transition: background-color 0.2s ease-in-out;
        }

        .btn-warning {
            background-color: #f39c12;
            color: #ffffff;
        }

        .btn-warning:hover {
            background-color: #e67e22;
        }

        .btn-danger {
            background-color: #e74c3c;
            color: #ffffff;
        }

        .btn-danger:hover {
            background-color: #c0392b;
        }

        /* Alert styling */
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            font-size: 1rem;
            border-radius: 8px;
            line-height: 1.5;
            background-color: #ffffff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-warning {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }

        .select2-container .select2-selection {
            font-size: 1rem;
            padding: 1px;
            border-radius: 6px;
            border: 1px solid #ced4da;
            background-color: #f9fbfc;
        }

        .popup-alert {
            min-width: 85%;
            right: 15px;
            top: 15px;
            padding: 15px;
            font-size: 1rem;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }
    }

    /* Extra small screens (mobiles < 576px) */
    @media (max-width: 576px) {
        body {
            padding: 10px;
            font-size: 1rem;
        }

        .container {
            padding: 15px;
        }

        h1, .panel-title {
            color: white;
            font-size: 1.6rem;
        }

        h2 {
            font-size: 1.3rem;
        }

        .filter-section {
            padding: 10px;
        }

        .filter-group label {
            font-size: 0.95rem;
        }

        .filter-btn-group .btn {
            width: 100%;
            margin: 5px 0;
            padding: 8px;
            font-size: 0.95rem;
        }

        tr {
            margin-bottom: 15px;
            padding: 12px;
        }

        td {
            font-size: 0.9rem;
            padding: 8px 12px;
        }

        td::before {
            font-size: 0.95rem;
            flex: 0 0 50%;
        }

        .marks-input {
            font-size: 0.95rem;
            padding: 8px;
        }

        .action-btn {
            font-size: 0.95rem;
            padding: 8px;
        }

        .alert {
            font-size: 0.95rem;
            padding: 12px;
        }

        .select2-container .select2-selection {
            font-size: 0.95rem;
            padding: 1px;
        }

        .popup-alert {
            min-width: 90%;
            right: 10px;
            top: 10px;
        }
    }
</style>
<body>

<?php require_once('navbar.php'); ?>

<!-- Dashboard -->
<div class="container">
    <div class="row">
        <div class="col-md-12">          
            
            <?php
            // Display session messages
            if (isset($_SESSION['message'])) {
                echo '<div class="alert alert-' . $_SESSION['message_type'] . ' alert-dismissible fade in">';
                echo '<button type="button" class="close" data-dismiss="alert">&times;</button>';
                echo $_SESSION['message'];
                echo '</div>';
                unset($_SESSION['message']);
                unset($_SESSION['message_type']);
            }
            ?>

            <!-- Filter Section -->
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h4 class="panel-title text-white">Filter Exams</h4>
                </div>
                <div class="panel-body">
                    <form method="GET" action="" id="filterForm">
                        <div class="row">
                            <div class="col-md-6 col-sm-12">
                                <div class="form-group filter-group">
                                    <label for="filter_class">Class:</label>
                                    <select class="form-control select2" id="filter_class" name="filter_class">
                                        <option value="0">All Classes</option>
                                        <?php foreach ($classes as $id => $title): ?>
                                            <option value="<?php echo $id; ?>" <?php echo $filter_class == $id ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6 col-sm-12">
                                <div class="form-group filter-group">
                                    <label for="filter_exam_type">Exam Type:</label>
                                    <select class="form-control select2" id="filter_exam_type" name="filter_exam_type">
                                        <option value="0">All Exam Types</option>
                                        <?php foreach ($exam_types as $id => $title): ?>
                                            <option value="<?php echo $id; ?>" <?php echo $filter_exam_type == $id ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="filter-btn-group">
                            <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-default clear-filters">
                                <span class="glyphicon glyphicon-refresh"></span> Clear Filters
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <span class="glyphicon glyphicon-filter"></span> Apply Filters
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Panel for Exam List -->
            <div class="panel panel-danger">
                <div class="panel-heading">
                    <h3 class="panel-title fs-4">Exam List 
                        <span class="badge" style="background-color: #d9534f; color: white;">
                            <?php echo count($exam_list); ?> exams found
                        </span>
                    </h3>
                </div>
                <div class="panel-body">
                    <?php if (!empty($exam_list)): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th class="fs-6">#</th>
                                        <th class="fs-6">Class Title</th>
                                        <th class="fs-6">Exam Type</th>
                                        <th class="fs-6">Start Date</th>
                                        <th class="fs-6">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $counter = 1; ?>
                                    <?php foreach ($exam_list as $row): ?>
                                        <tr class="course-<?php echo $counter % count($colors); ?>">
                                            <td><?php echo $counter++; ?></td>
                                            <td class="fs-5"><?php echo htmlspecialchars($row['class_title']); ?></td>
                                            <td class="fs-5"><?php echo htmlspecialchars($row['exam_type_title']); ?></td>
                                            <td class="fs-5"><?php echo date('d M Y', strtotime($row['start_date'])); ?></td>
                                            <td>
                                                <a href="result_entry.php?exam_id=<?php echo $row['id']; ?>&class_id=<?php echo $row['class_id']; ?>&exam_type_id=<?php echo $row['exam_type_id']; ?>" class="btn btn-xs btn-primary">
                                                    <span class="glyphicon glyphicon-list-alt"></span> Create DMC's
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info text-center">
                            No Exams found. 
                            <?php if ($filter_class > 0 || $filter_exam_type > 0): ?>
                                Try changing your filter criteria.
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2({
        placeholder: "Select option",
        allowClear: true
    });

    // Auto-dismiss alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);

    // Mobile responsive adjustments
    function adjustForMobile() {
        if ($(window).width() <= 768) {
            // Convert table to card view on mobile
            $('.table-responsive table tbody tr').each(function() {
                var $tr = $(this);
                $tr.find('td').each(function(index) {
                    var headerText = $tr.closest('table').find('th').eq(index).text();
                    $(this).attr('data-label', headerText);
                });
            });
        }
    }

    // Call on load and resize
    adjustForMobile();
    $(window).resize(adjustForMobile);
});
</script>

</body>
</html>
<?php
// Close database connection
$conn->close();
?>