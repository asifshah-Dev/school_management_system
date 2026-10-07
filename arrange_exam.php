<?php
ob_start();
require_once('security.php');
$edit_mode = false;

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

// Check if user wants to show previous exams (default to hidden)
$show_previous = isset($_GET['show_previous']) && $_GET['show_previous'] == '1';

// Check if we're in edit mode
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $edit_mode = true;
    
    // Fetch the exam data to edit
    $stmt = $conn->prepare("SELECT id, class_id, exam_type_id, start_date FROM arrange_exam WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $edit_result = $stmt->get_result();
    
    if ($edit_result->num_rows > 0) {
        $edit_data = $edit_result->fetch_assoc();
    } else {
        $edit_mode = false;
        $_SESSION['message'] = "Exam not found.";
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid CSRF token: " . ($_POST['csrf_token'] ?? 'not set') . " vs " . $_SESSION['csrf_token']);
    }

    // Sanitize and validate input
    $exam_type_id = intval($_POST['exam_type_id']);
    $dated = $_POST['dated'];
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    
    // Handle multiple class selection
    $class_ids = isset($_POST['class_id']) ? $_POST['class_id'] : [];
    
    // Validate inputs
    if (empty($class_ids) || $exam_type_id <= 0 || empty($dated)) {
        $_SESSION['message'] = "Invalid input: Please fill all fields correctly.";
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    
    // Ensure date format
    $dated = date('Y-m-d', strtotime($dated));
    
    if ($id > 0) {
        // Update existing record (for single edit mode)
        $class_id = intval($class_ids[0]); // In edit mode, only one class is expected
        $stmt = $conn->prepare("UPDATE arrange_exam SET class_id = ?, exam_type_id = ?, start_date = ? WHERE id = ?");
        $stmt->bind_param("iisi", $class_id, $exam_type_id, $dated, $id);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "Record updated successfully.";
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = "Error: " . $stmt->error;
            $_SESSION['message_type'] = 'danger';
        }
        $stmt->close();
    } else {
        // Insert new records for each selected class
        $success_count = 0;
        $error_count = 0;
        
        foreach ($class_ids as $class_id) {
            $class_id = intval($class_id);
            
            if ($class_id <= 0) {
                continue; // Skip invalid class IDs
            }
            
            // Check if exam already exists for this class, exam type and date
            $check_stmt = $conn->prepare("SELECT id FROM arrange_exam WHERE class_id = ? AND exam_type_id = ? AND start_date = ?");
            $check_stmt->bind_param("iis", $class_id, $exam_type_id, $dated);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                // Exam already exists for this class
                $error_count++;
                $check_stmt->close();
                continue;
            }
            $check_stmt->close();
            
            // Insert new record
            $stmt = $conn->prepare("INSERT INTO arrange_exam (exam_type_id, class_id, start_date) VALUES (?, ?, ?)");
            $stmt->bind_param("iis", $exam_type_id, $class_id, $dated);
            
            if ($stmt->execute()) {
                $success_count++;
            } else {
                $error_count++;
                error_log("Error inserting exam for class $class_id: " . $stmt->error, 3, 'errors.log');
            }
            
            $stmt->close();
        }
        
        // Set appropriate message
        if ($success_count > 0 && $error_count == 0) {
            $_SESSION['message'] = "$success_count exam(s) created successfully.";
            $_SESSION['message_type'] = 'success';
        } elseif ($success_count > 0 && $error_count > 0) {
            $_SESSION['message'] = "$success_count exam(s) created successfully. $error_count exam(s) were not created (may already exist).";
            $_SESSION['message_type'] = 'warning';
        } else {
            $_SESSION['message'] = "No exams were created. They may already exist for the selected classes.";
            $_SESSION['message_type'] = 'warning';
        }
    }
    
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

// Get dropdown options with error handling
$classes = [];
$course_result = $conn->query("SELECT id, title FROM classes ORDER BY title");
if ($course_result) {
    while ($row = $course_result->fetch_assoc()) {
        $classes[$row['id']] = $row['title'];
    }
    $course_result->close();
} else {
    $_SESSION['message'] = "Error loading classes: " . $conn->error;
    $_SESSION['message_type'] = 'danger';
}

$exam_types = [];
$types_result = $conn->query("SELECT id, title FROM exam_types WHERE status = 0 ORDER BY title");
if ($types_result) {
    while ($row2 = $types_result->fetch_assoc()) {
        $exam_types[$row2['id']] = $row2['title'];
    }
    $types_result->close();
} else {
    $_SESSION['message'] = "Error loading exam types: " . $conn->error;
    $_SESSION['message_type'] = 'danger';
}

// Define threshold for previous exams (15 days)
$previous_days_threshold = 15;
$threshold_date = date('Y-m-d', strtotime("-$previous_days_threshold days"));

// Get data for listing - show only recent and future exams by default
if ($show_previous) {
    // Show all exams including previous ones
    $result = $conn->query("
        SELECT es.id, es.class_id, es.exam_type_id, es.start_date, 
               c.title AS class_title, et.title AS exam_type_title
        FROM arrange_exam es
        JOIN classes c ON es.class_id = c.id
        JOIN exam_types et ON es.exam_type_id = et.id
        ORDER BY es.start_date ASC, c.title ASC
    ");
} else {
    // Show only exams from the last 15 days and future exams
    $result = $conn->query("
        SELECT es.id, es.class_id, es.exam_type_id, es.start_date, 
               c.title AS class_title, et.title AS exam_type_title
        FROM arrange_exam es
        JOIN classes c ON es.class_id = c.id
        JOIN exam_types et ON es.exam_type_id = et.id
        WHERE es.start_date >= '$threshold_date'
        ORDER BY es.start_date ASC, c.title ASC
    ");
}

$exam_list = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $exam_list[] = $row;
    }
    $result->close();
} else {
    $_SESSION['message'] = "Error loading exams: " . $conn->error;
    $_SESSION['message_type'] = 'danger';
}

// Count previous exams (exams with start_date more than 15 days before today)
$previous_count_result = $conn->query("
    SELECT COUNT(*) as count FROM arrange_exam 
    WHERE start_date < '$threshold_date'
");
$previous_exam_count = 0;
if ($previous_count_result) {
    $previous_count_row = $previous_count_result->fetch_assoc();
    $previous_exam_count = $previous_count_row['count'];
    $previous_count_result->close();
}

// Define color palette
$colors = [
    '#e6f3ff', '#e6ffe6', '#fff3e6', '#ffe6e6', 
    '#f3e6ff', '#e6fff3', '#ffffe6', '#f0f0f0'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Exam Management</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
        /* General container styling */
        .container {
            padding: 15px;
            max-width: 100%;
            margin: 0 auto;
        }

        /* Form styling */
        .form-group {
            margin-bottom: 20px;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            font-size: 16px;
            border-radius: 4px;
        }

        /* Button styling */
        .btn {
            padding: 12px 20px;
            font-size: 14px;
            margin: 5px 0;
            width: 100%;
            box-sizing: border-box;
        }

        .btn-xs {
            padding: 6px 12px;
            font-size: 12px;
            width: auto;
            display: inline-block;
        }

        /* Table styling for desktop */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table {
            width: 100%;
            margin-bottom: 0;
        }

        .table th, .table td {
            padding: 12px;
            font-size: 14px;
            vertical-align: middle;
        }

        /* Select2 customization for multiple selection */
        .select2-container--default .select2-selection--multiple {
            min-height: 46px;
            border: 1px solid #ccc;
            border-radius: 4px;
            padding: 6px 12px;
        }
        
        .select2-container--default.select2-container--focus .select2-selection--multiple {
            border-color: #66afe9;
            outline: 0;
            box-shadow: inset 0 1px 1px rgba(0,0,0,.075), 0 0 8px rgba(102,175,233,.6);
        }
        
        .select2-container--default .select2-selection--multiple .select2-selection__rendered {
            padding: 0;
            list-style: none;
            margin: 0;
        }
        
        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            background-color: #337ab7;
            border: 1px solid #2e6da4;
            color: white;
            border-radius: 4px;
            padding: 4px 8px;
            margin: 4px 4px 0 0;
            font-size: 14px;
            line-height: 1.4;
            cursor: default;
            display: inline-block;
        }
        
        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
            color: white;
            margin-right: 5px;
            cursor: pointer;
        }
        
        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover {
            color: #ffcccc;
        }
        
        .select2-container .select2-selection--single .select2-selection__clear {
            margin-right: 5px;
        }
        
        /* Mobile adjustments for Select2 */
        @media (max-width: 768px) {
            .select2-container--default .select2-selection--multiple {
                min-height: 42px;
                padding: 5px 10px;
            }
            
            .select2-container--default .select2-selection--multiple .select2-selection__rendered {
                font-size: 14px;
            }
        }
        
        @media (max-width: 480px) {
            .select2-container--default .select2-selection--multiple {
                min-height: 38px;
                padding: 4px 8px;
            }
            
            .select2-container--default .select2-selection--multiple .select2-selection__rendered {
                font-size: 13px;
            }
            
            .select2-container--default .select2-selection--multiple .select2-selection__choice {
                font-size: 12px;
                padding: 3px 6px;
                margin: 2px 2px 0 0;
            }
        }

        /* Previous exam indicator */
        .previous-exam {
            background-color: #f8f9fa !important;
            opacity: 0.8;
        }
        
        .previous-exam td {
            color: #6c757d;
            font-style: italic;
        }
        
        .show-previous-btn {
            margin-bottom: 15px;
        }

        /* Card-based layout for mobile */
        @media (max-width: 768px) {
            .table-responsive {
                overflow-x: hidden;
            }

            .table {
                width: 100%;
                border: none;
            }

            .table thead {
                display: none;
            }

            .table tbody tr {
                display: block;
                margin: 15px 0;
                padding: 15px;
                background-color: #fff;
                border: 1px solid #ddd;
                border-radius: 8px;
                box-shadow: 0 2px 6px rgba(0,0,0,0.1);
            }

            .table tbody td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 10px 0;
                border-bottom: 1px solid #eee;
                font-size: 14px;
            }

            .table tbody td:last-child {
                border-bottom: none;
                display: block;
                text-align: center;
                padding: 15px 0;
            }

            .table tbody td:before {
                content: attr(data-label);
                font-weight: 600;
                flex: 0 0 40%;
                color: #333;
                text-align: left;
            }

            .table tbody td:last-child:before {
                content: none;
            }

            .table tbody td .btn-xs {
                margin: 5px;
                padding: 8px 12px;
                font-size: 12px;
            }
        }

        /* Form and panel adjustments for mobile */
        @media (max-width: 768px) {
            .panel {
                margin-bottom: 20px;
                border-radius: 8px;
            }

            .panel-heading {
                padding: 12px;
                border-radius: 8px 8px 0 0;
            }

            .panel-title {
                font-size: 16px;
            }

            .panel-body {
                padding: 15px;
            }

            .form-group label {
                font-size: 14px;
                margin-bottom: 8px;
            }

            .form-control {
                font-size: 14px;
                padding: 10px;
            }

            .btn {
                font-size: 14px;
                padding: 10px;
            }

            .alert {
                font-size: 14px;
                padding: 12px;
                margin-bottom: 15px;
            }

            .text-right {
                text-align: center;
            }

            .text-right .btn {
                display: block;
                margin: 10px auto;
            }
        }

        /* Extra small screens */
        @media (max-width: 480px) {
            .container {
                padding: 10px;
            }

            .panel-title {
                font-size: 14px;
            }

            .form-control {
                font-size: 13px;
                padding: 8px;
            }

            .btn {
                font-size: 13px;
                padding: 8px;
            }

            .btn-xs {
                font-size: 11px;
                padding: 6px 10px;
            }

            .table tbody td {
                font-size: 13px;
            }

            .table tbody td:before {
                flex: 0 0 45%;
            }
        }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<!-- Dashboard -->
<div class="container">
    <div class="row">
        <div class="col-md-12">

            <!-- Panel for Add/Edit Exam -->
            <div class="panel shadow-sm">
                <div class="panel-heading bg-dark">
                    <h3 class="panel-title text-white fs-3"><?php echo $edit_mode ? 'Edit Exam' : 'Arrange Exam'; ?></h3>
                </div>
                <div class="panel-body">

                    <?php if (isset($_SESSION['message'])): ?>
                        <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade in">
                            <a href="#" class="close" data-dismiss="alert" aria-label="close">&times;</a>
                            <?php 
                            echo $_SESSION['message']; 
                            unset($_SESSION['message'], $_SESSION['message_type']);
                            ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <?php if ($edit_mode && isset($edit_data)): ?>
                            <input type="hidden" name="id" value="<?php echo $edit_data['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label class="fs-6">Select Class(es):</label>
                            <select name="class_id[]" id="class_id" class="form-control" multiple="multiple" required>
                                <?php foreach ($classes as $id => $title): ?>
                                    <option value="<?php echo $id; ?>" 
                                        <?php if ($edit_mode && isset($edit_data) && $edit_data['class_id'] == $id) echo 'selected'; ?>>
                                        <?php echo htmlspecialchars($title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Select one or more classes. Use the search box to find classes quickly.</small>
                        </div>
                        
                        <div class="form-group">
                            <label class="fs-6">Select Exam Type:</label>
                            <select name="exam_type_id" id="exam_type_id" class="form-control" required>
                                <option value="">-- Select Exam Type --</option>
                                <?php foreach ($exam_types as $ids => $titles): ?>
                                    <option value="<?php echo $ids; ?>" 
                                        <?php if ($edit_mode && isset($edit_data) && $edit_data['exam_type_id'] == $ids) echo 'selected'; ?>>
                                        <?php echo htmlspecialchars($titles); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="fs-6">Start Date:</label>
                            <input type="date" class="form-control" name="dated" required 
                                   value="<?php if ($edit_mode && isset($edit_data)) echo $edit_data['start_date']; ?>">
                        </div>

                        <div class="text-right">
                            <button type="submit" class="btn btn-danger">
                                <span class="glyphicon glyphicon-<?php echo $edit_mode ? 'refresh' : 'plus'; ?>"></span> 
                                <?php echo $edit_mode ? 'Update Exam' : 'Create Exams'; ?>
                            </button>
                            <?php if ($edit_mode): ?>
                                <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-default">
                                    <span class="glyphicon glyphicon-remove"></span> Cancel Edit
                                </a>
                            <?php else: ?>
                                <button type="reset" class="btn btn-default fs-5" id="resetBtn">
                                    <span class="glyphicon glyphicon-refresh"></span> Reset Form
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Panel for Exam List -->
            <div class="panel panel-danger">
                <div class="panel-heading">
                    <div class="row">
                        <div class="col-sm-6">
                            <h3 class="panel-title fs-4">Exam List</h3>
                        </div>
                        <div class="col-sm-6 text-right">
                            <?php if ($previous_exam_count > 0): ?>
                                <?php if ($show_previous): ?>
                                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-xs btn-default show-previous-btn">
                                        <span class="glyphicon glyphicon-eye-close"></span> Hide Previous Exams
                                    </a>
                                <?php else: ?>
                                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>?show_previous=1" class="btn btn-xs btn-info show-previous-btn">
                                        <span class="glyphicon glyphicon-eye-open"></span> Show Previous Exams (<?php echo $previous_exam_count; ?>)
                                    </a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
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
                                        <th class="fs-6">Status</th>
                                        <th class="fs-6">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $counter = 1; 
                                    // Calculate threshold date (15 days ago) for determining previous exams in display
                                    $display_threshold_date = date('Y-m-d', strtotime("-15 days"));
                                    ?>
                                    <?php foreach ($exam_list as $row): 
                                        $is_previous = strtotime($row['start_date']) < strtotime($display_threshold_date);
                                        $row_class = $is_previous ? 'previous-exam' : 'course-' . ($counter % count($colors));
                                    ?>
                                        <tr class="<?php echo $row_class; ?>">
                                            <td data-label="#"><?php echo $counter++; ?></td>
                                            <td data-label="Class Title" class="fs-5"><?php echo htmlspecialchars($row['class_title']); ?></td>
                                            <td data-label="Exam Type" class="fs-5"><?php echo htmlspecialchars($row['exam_type_title']); ?></td>
                                            <td data-label="Start Date" class="fs-5"><?php echo date('d M Y', strtotime($row['start_date'])); ?></td>
                                            <td data-label="Status" class="fs-5">
                                                <?php if ($is_previous): ?>
                                                    <span class="label label-default">Previous</span>
                                                <?php else: ?>
                                                    <span class="label label-success">Upcoming/Current</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Actions">
                                                <a href="datesheet.php?exam_id=<?php echo $row['id']; ?>&class_id=<?php echo $row['class_id']; ?>&exam_type=<?php echo urlencode($row['exam_type_title']); ?>" class="btn btn-xs btn-warning">
                                                    <span class="glyphicon glyphicon-edit"></span> Create Datesheet
                                                </a>
                                                <a href="?edit=<?php echo $row['id']; ?>" class="btn btn-xs btn-warning">
                                                    <span class="glyphicon glyphicon-edit"></span> Edit
                                                </a>
                                                <a href="?delete=<?php echo $row['id']; ?>" class="btn btn-xs btn-danger" 
                                                   onclick="return confirm('Are you sure you want to delete this exam?')">
                                                    <span class="glyphicon glyphicon-trash"></span> Delete
                                                </a>
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
                            No Exams found. <?php if (!$show_previous && $previous_exam_count > 0): ?>
                                <br>
                                <a href="<?php echo $_SERVER['PHP_SELF']; ?>?show_previous=1" class="btn btn-xs btn-info" style="margin-top: 10px;">
                                    Show Previous Exams (<?php echo $previous_exam_count; ?>)
                                </a>
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
    // Auto-dismiss alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);
    
    // Initialize Select2 for multiple class selection
    $('#class_id').select2({
        placeholder: "Select one or more classes",
        allowClear: true,
        width: '100%'
    });
    
    // Initialize Select2 for exam type dropdown (single selection)
    $('#exam_type_id').select2({
        placeholder: "-- Select Exam Type --",
        allowClear: true,
        width: '100%'
    });
    
    // Reset button functionality
    $('#resetBtn').click(function() {
        $('#class_id').val(null).trigger('change');
        $('#exam_type_id').val(null).trigger('change');
        $('input[name="dated"]').val('');
    });
    
    // Form validation for at least one class selected
    $('form').on('submit', function() {
        var selectedClasses = $('#class_id').val();
        if (!selectedClasses || selectedClasses.length === 0) {
            alert('Please select at least one class.');
            $('#class_id').select2('open');
            return false;
        }
        
        var examType = $('#exam_type_id').val();
        if (!examType) {
            alert('Please select an exam type.');
            $('#exam_type_id').select2('open');
            return false;
        }
        
        var startDate = $('input[name="dated"]').val();
        if (!startDate) {
            alert('Please select a start date.');
            $('input[name="dated"]').focus();
            return false;
        }
        
        return true;
    });
});
</script>

</body>
</html>
<?php
// Close database connection
if (isset($conn)) {
    $conn->close();
}
?>