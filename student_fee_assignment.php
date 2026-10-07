<?php 
header('Content-Type: text/html; charset=utf-8');
require_once('security.php');
require_once('conn_inc.php');

// Get student ID from URL
$student_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$current_mode = isset($_GET['type']) ? $_GET['type'] : 'school';

// Fetch student details - removed student_class_id dependency
$student_query = "SELECT sr.*, c.title as class_name, c.id as class_id, s.title as session_name
                  FROM student_registration sr
                  LEFT JOIN student_class sc ON sr.id = sc.student_registration_id AND sc.status = 0
                  LEFT JOIN classes c ON sc.class_id = c.id
                  LEFT JOIN sessions s ON sc.session_id = s.id
                  WHERE sr.id = ?";

$stmt = $conn->prepare($student_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student_result = $stmt->get_result();
$student = $student_result->fetch_assoc();

if (!$student) {
    $_SESSION['message'] = 'Student not found!';
    $_SESSION['message_type'] = 'danger';
    header("Location: student_list.php?type=" . $current_mode);
    exit();
}

// Handle form submission for adding/updating fee
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_fee'])) {
    $fee_type_id = intval($_POST['fee_type_id']);
    $amount = floatval($_POST['amount']);
    $fee_id = isset($_POST['fee_id']) ? intval($_POST['fee_id']) : 0;
    
    // Check for duplicate fee type (only for new entries)
    if ($fee_id == 0) {
        $check_query = "SELECT id FROM student_fee_assignments 
                        WHERE student_id = ? AND fee_type_id = ? AND status = 1";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("ii", $student_id, $fee_type_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $_SESSION['message'] = 'This fee type is already assigned to the student!';
            $_SESSION['message_type'] = 'warning';
            header("Location: student_fee_assignment.php?student_id=" . $student_id . "&type=" . $current_mode);
            exit();
        }
    } else {
        // Check for duplicate when editing (excluding current fee)
        $check_query = "SELECT id FROM student_fee_assignments 
                        WHERE student_id = ? AND fee_type_id = ? AND status = 1 AND id != ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("iii", $student_id, $fee_type_id, $fee_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $_SESSION['message'] = 'This fee type is already assigned to the student!';
            $_SESSION['message_type'] = 'warning';
            header("Location: student_fee_assignment.php?student_id=" . $student_id . "&type=" . $current_mode);
            exit();
        }
    }
    
    if ($fee_id > 0) {
        // Update existing fee - allow zero amount
        $query = "UPDATE student_fee_assignments SET fee_type_id = ?, amount = ? WHERE id = ? AND student_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("idii", $fee_type_id, $amount, $fee_id, $student_id);
    } else {
        // Insert new fee - allow zero amount
        $query = "INSERT INTO student_fee_assignments (student_id, fee_type_id, amount, assigned_date, status) 
                  VALUES (?, ?, ?, NOW(), 1)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("iid", $student_id, $fee_type_id, $amount);
    }
    
    if ($stmt->execute()) {
        $_SESSION['message'] = 'Fee saved successfully!';
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = 'Error saving fee: ' . $conn->error;
        $_SESSION['message_type'] = 'danger';
    }
    
    header("Location: student_fee_assignment.php?student_id=" . $student_id . "&type=" . $current_mode);
    exit();
}

// Handle fee deletion - permanent delete
if (isset($_GET['delete_fee'])) {
    $fee_id = intval($_GET['delete_fee']);
    // Permanent delete instead of status update
    $delete_query = "DELETE FROM student_fee_assignments WHERE id = ? AND student_id = ?";
    $stmt = $conn->prepare($delete_query);
    $stmt->bind_param("ii", $fee_id, $student_id);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = 'Fee removed successfully!';
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = 'Error removing fee!';
        $_SESSION['message_type'] = 'danger';
    }
    
    header("Location: student_fee_assignment.php?student_id=" . $student_id . "&type=" . $current_mode);
    exit();
}

// Fetch active fee types with their type (one_time, monthly, yearly, promotion)
// Only show fee types that are NOT already assigned to this student
$fee_types_query = "SELECT id, title, type FROM fee_types WHERE status = 1 
                    AND id NOT IN (
                        SELECT fee_type_id FROM student_fee_assignments 
                        WHERE student_id = ? AND status = 1
                    )
                    ORDER BY 
                    CASE type 
                        WHEN 'one_time' THEN 1
                        WHEN 'monthly' THEN 2
                        WHEN 'yearly' THEN 3
                        WHEN 'promotion' THEN 4
                    END, title";

$stmt = $conn->prepare($fee_types_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$fee_types_result = $stmt->get_result();

// Fetch existing fee assignments for this student with fee type details
$existing_fees_query = "SELECT sfa.*, ft.title as fee_type_title, ft.type as fee_type_category
                        FROM student_fee_assignments sfa
                        JOIN fee_types ft ON sfa.fee_type_id = ft.id
                        WHERE sfa.student_id = ? 
                        AND sfa.status = 1
                        ORDER BY 
                        CASE ft.type 
                            WHEN 'one_time' THEN 1
                            WHEN 'monthly' THEN 2
                            WHEN 'yearly' THEN 3
                            WHEN 'promotion' THEN 4
                        END, ft.title";

$stmt = $conn->prepare($existing_fees_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$existing_fees = $stmt->get_result();

// Create array of assigned fee type IDs for JavaScript validation
$assigned_fee_ids = [];
$fees = [];
while ($fee = $existing_fees->fetch_assoc()) {
    $fees[] = $fee;
    $assigned_fee_ids[] = $fee['fee_type_id'];
}

// Calculate total fee by category for display
$total_query = "SELECT 
                    SUM(CASE WHEN ft.type = 'one_time' THEN sfa.amount ELSE 0 END) as one_time_total,
                    SUM(CASE WHEN ft.type = 'monthly' THEN sfa.amount ELSE 0 END) as monthly_total,
                    SUM(CASE WHEN ft.type = 'yearly' THEN sfa.amount ELSE 0 END) as yearly_total,
                    SUM(CASE WHEN ft.type = 'promotion' THEN sfa.amount ELSE 0 END) as promotion_total,
                    SUM(sfa.amount) as total_fee
                FROM student_fee_assignments sfa
                JOIN fee_types ft ON sfa.fee_type_id = ft.id
                WHERE sfa.student_id = ? AND sfa.status = 1";

$stmt = $conn->prepare($total_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$total_result = $stmt->get_result();
$totals = $total_result->fetch_assoc();
$total_fee = $totals['total_fee'] ?? 0;

// Get fee data for editing
$edit_fee = null;
if (isset($_GET['edit_fee'])) {
    $edit_id = intval($_GET['edit_fee']);
    $edit_query = "SELECT sfa.*, ft.type as fee_type_category 
                   FROM student_fee_assignments sfa
                   JOIN fee_types ft ON sfa.fee_type_id = ft.id
                   WHERE sfa.id = ? AND sfa.student_id = ? AND sfa.status = 1";
    $stmt = $conn->prepare($edit_query);
    $stmt->bind_param("ii", $edit_id, $student_id);
    $stmt->execute();
    $edit_result = $stmt->get_result();
    $edit_fee = $edit_result->fetch_assoc();
}

// Function to get badge class based on fee type
function getFeeTypeBadge($type) {
    switch($type) {
        case 'one_time':
            return 'badge-one-time';
        case 'monthly':
            return 'badge-monthly';
        case 'yearly':
            return 'badge-yearly';
        case 'promotion':
            return 'badge-promotion';
        default:
            return 'badge-secondary';
    }
}

// Function to get display text for fee type
function getFeeTypeDisplay($type) {
    switch($type) {
        case 'one_time':
            return 'One Time';
        case 'monthly':
            return 'Monthly';
        case 'yearly':
            return 'Yearly';
        case 'promotion':
            return 'Promotion';
        default:
            return ucfirst($type);
    }
}

// Check if student has class assignment
$has_class = isset($student['class_name']) && !empty($student['class_name']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Assign Fee Structure - <?php echo htmlspecialchars($student['name']); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
        }
        .container {
            max-width: 1400px;
        }
        .panel {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            padding: 20px;
        }
        .panel-heading {
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 15px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .panel-title {
            color: #2c3e50;
            font-weight: 600;
            margin: 0;
        }
        .total-fee-counter {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 10px 20px;
            border-radius: 50px;
            font-size: 18px;
            font-weight: bold;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        .total-fee-counter i {
            margin-right: 10px;
        }
        .student-info-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .student-info-card h3 {
            margin-bottom: 10px;
            font-weight: 600;
        }
        .student-info-card p {
            margin-bottom: 5px;
            opacity: 0.9;
        }
        .table th {
            background-color: #f8f9fa;
            border-top: none;
        }
        .btn-action {
            padding: 5px 10px;
            margin: 0 2px;
        }
        .total-amount {
            font-size: 18px;
            font-weight: bold;
            color: #28a745;
        }
        .fee-form {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .realtime-total {
            font-size: 16px;
            color: #666;
            margin-top: 5px;
        }
        .counter-badge {
            background-color: #dc3545;
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 12px;
            margin-left: 5px;
        }
        .fee-type-warning {
            color: #dc3545;
            font-size: 14px;
            margin-top: 5px;
            display: none;
        }
        .is-duplicate {
            border-color: #dc3545 !important;
        }
        
        /* Badge styles for fee types */
        .badge-type {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-left: 8px;
        }
        .badge-one-time {
            background-color: #e3f2fd;
            color: #0d47a1;
            border: 1px solid #bbdefb;
        }
        .badge-monthly {
            background-color: #e8f5e8;
            color: #1e7e34;
            border: 1px solid #c3e6cb;
        }
        .badge-yearly {
            background-color: #fff3e0;
            color: #b45f06;
            border: 1px solid #ffe0b2;
        }
        .badge-promotion {
            background-color: #fce4e4;
            color: #b71c1c;
            border: 1px solid #f5c6cb;
        }
        
        /* Summary cards */
        .summary-card {
            background: white;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            border-left: 4px solid;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .summary-one-time { border-left-color: #0d47a1; }
        .summary-monthly { border-left-color: #1e7e34; }
        .summary-yearly { border-left-color: #b45f06; }
        .summary-promotion { border-left-color: #b71c1c; }
        
        .summary-title {
            font-size: 14px;
            color: #6c757d;
            margin-bottom: 5px;
        }
        .summary-amount {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 0;
        }
        
        .fee-type-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
        }
        .indicator-one-time { background-color: #0d47a1; }
        .indicator-monthly { background-color: #1e7e34; }
        .indicator-yearly { background-color: #b45f06; }
        .indicator-promotion { background-color: #b71c1c; }
        
        /* Fee type filter buttons */
        .filter-badge {
            cursor: pointer;
            transition: all 0.2s;
            margin-right: 5px;
        }
        .filter-badge:hover {
            transform: translateY(-2px);
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .filter-badge.active {
            border: 2px solid #007bff;
        }
        .class-warning {
            background-color: #fff3cd;
            border: 1px solid #ffeeba;
            color: #856404;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .zero-amount {
            color: #dc3545;
            font-style: italic;
        }
    </style>
</head>
<body>
    <?php require_once('navbar.php'); ?>

    <div class="container mt-4">
        <!-- Student Information Card -->
        <div class="student-info-card">
            <div class="row">
                <div class="col-md-8">
                    <h3><i class="fas fa-user-graduate mr-2"></i><?php echo htmlspecialchars($student['name']); ?></h3>
                    <p><strong>Father Name:</strong> <?php echo htmlspecialchars($student['father_name']); ?></p>
                    <p><strong>Class:</strong> <?php echo htmlspecialchars($student['class_name'] ?? 'Not Assigned'); ?></p>
                    <?php if ($has_class): ?>
                    <p><strong>Session:</strong> <?php echo htmlspecialchars($student['session_name'] ?? 'N/A'); ?></p>
                    <?php endif; ?>
                </div>
                <div class="col-md-4 text-right">
                    <p><strong>Student ID:</strong> #<?php echo $student['id']; ?></p>
                    <p><strong>Mobile:</strong> <?php echo htmlspecialchars($student['mobile'] ?? 'N/A'); ?></p>
                    <div class="mt-3">
                        <a href="student_list.php?type=<?php echo $current_mode; ?>" class="btn btn-light btn-sm">
                            <i class="fas fa-arrow-left mr-2"></i>Back to List
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <?php if (isset($_SESSION['message'])): ?>
        <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show">
            <?php echo $_SESSION['message']; ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
        <?php 
        unset($_SESSION['message'], $_SESSION['message_type']);
        endif; 
        ?>

        <!-- Class Warning if not assigned -->
        <?php if (!$has_class): ?>
        <div class="class-warning">
            <i class="fas fa-exclamation-triangle mr-2"></i>
            <strong>Warning:</strong> This student is not assigned to any class. 
            <a href="student_promotion.php?student_id=<?php echo $student_id; ?>" class="btn btn-warning btn-sm ml-2">
                <i class="fas fa-arrow-right mr-2"></i>Assign Class Now
            </a>
        </div>
        <?php endif; ?>

        <!-- Add/Edit Fee Form -->
        <div class="panel">
            <div class="panel-heading">
                <h4 class="panel-title">
                    <i class="fas fa-<?php echo $edit_fee ? 'edit' : 'plus-circle'; ?> mr-2"></i>
                    <?php echo $edit_fee ? 'Edit Fee' : 'Add New Fee'; ?>
                </h4>
                <div class="total-fee-counter" id="totalCounter">
                    <i class="fas fa-rupee-sign"></i>
                    Total: Rs. <span id="totalAmount"><?php echo number_format($total_fee, 2); ?></span>
                </div>
            </div>
            <div class="panel-body">
                <form method="POST" action="" class="fee-form" id="feeForm">
                    <?php if ($edit_fee): ?>
                    <input type="hidden" name="fee_id" value="<?php echo $edit_fee['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-5">
                            <div class="form-group">
                                <label>Fee Type <span class="text-danger">*</span></label>
                                <select name="fee_type_id" id="feeType" class="form-control" required>
                                    <option value="">Select Fee Type</option>
                                    <?php 
                                    if ($edit_fee) {
                                        // For edit mode, also include the currently selected fee type
                                        $current_fee_type_query = "SELECT id, title, type FROM fee_types WHERE id = ? AND status = 1";
                                        $current_stmt = $conn->prepare($current_fee_type_query);
                                        $current_stmt->bind_param("i", $edit_fee['fee_type_id']);
                                        $current_stmt->execute();
                                        $current_result = $current_stmt->get_result();
                                        $current_fee_type = $current_result->fetch_assoc();
                                        
                                        if ($current_fee_type) {
                                            $selected = 'selected';
                                            $type_display = getFeeTypeDisplay($current_fee_type['type']);
                                            echo '<option value="' . $current_fee_type['id'] . '" selected data-type="' . $current_fee_type['type'] . '">';
                                            echo htmlspecialchars($current_fee_type['title']) . ' (' . $type_display . ')';
                                            echo '</option>';
                                        }
                                    }
                                    
                                    // Show all available fee types (not assigned to this student)
                                    $fee_types_result->data_seek(0);
                                    while ($fee_type = $fee_types_result->fetch_assoc()): 
                                        $type_display = getFeeTypeDisplay($fee_type['type']);
                                    ?>
                                    <option value="<?php echo $fee_type['id']; ?>" 
                                            data-type="<?php echo $fee_type['type']; ?>">
                                        <?php echo htmlspecialchars($fee_type['title']); ?> 
                                        (<?php echo $type_display; ?>)
                                    </option>
                                    <?php endwhile; ?>
                                </select>
                                
                                <div class="fee-type-warning" id="duplicateWarning">
                                    <i class="fas fa-exclamation-triangle mr-1"></i>
                                    This fee type is already assigned!
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Amount (Rs.) <span class="text-danger">*</span></label>
                                <input type="number" name="amount" id="amount" class="form-control" step="0.01" min="0" 
                                       value="<?php echo $edit_fee ? $edit_fee['amount'] : ''; ?>" 
                                       required placeholder="Enter amount (0.00 allowed)">
                                <small class="form-text text-muted">You can enter 0.00 for free fees</small>
                            </div>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <div class="form-group mb-0">
                                <button type="submit" name="submit_fee" class="btn btn-primary" id="submitBtn">
                                    <i class="fas fa-save mr-2"></i><?php echo $edit_fee ? 'Update Fee' : 'Add Fee'; ?>
                                </button>
                                <?php if ($edit_fee): ?>
                                <a href="student_fee_assignment.php?student_id=<?php echo $student_id; ?>&type=<?php echo $current_mode; ?>" 
                                   class="btn btn-secondary">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Real-time calculation display -->
                    <div class="row mt-3">
                        <div class="col-12">
                            <div class="alert alert-info realtime-total" id="realtimeDisplay" style="display: none;">
                                <i class="fas fa-calculator mr-2"></i>
                                New Total Will Be: Rs. <span id="newTotal">0.00</span>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Existing Fee Assignments Table -->
        <div class="panel">
            <div class="panel-heading">
                <h4 class="panel-title">
                    <i class="fas fa-list mr-2"></i>
                    Assigned Fee Structure
                    <span class="counter-badge" id="feeCount"><?php echo count($fees); ?></span>
                </h4>
                <?php if (!empty($fees)): ?>
                <div>
                    <span class="filter-badge badge badge-primary" data-filter="all">All</span>
                    <span class="filter-badge badge badge-info" data-filter="one_time">One Time</span>
                    <span class="filter-badge badge badge-success" data-filter="monthly">Monthly</span>
                    <span class="filter-badge badge badge-warning" data-filter="yearly">Yearly</span>
                    <span class="filter-badge badge badge-danger" data-filter="promotion">Promotion</span>
                </div>
                <?php endif; ?>
            </div>
            <div class="panel-body">
                <?php if (count($fees) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover" id="feeTable">
                            <thead>
                                <tr>
                                    <th width="5%">#</th>
                                    <th>Fee Type</th>
                                    <th>Type</th>
                                    <th class="text-right">Amount (Rs.)</th>
                                    <th width="15%" class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($fees as $index => $fee): ?>
                                <tr data-amount="<?php echo $fee['amount']; ?>" 
                                    data-fee-type="<?php echo $fee['fee_type_id']; ?>"
                                    data-type="<?php echo $fee['fee_type_category']; ?>">
                                    <td><?php echo $index + 1; ?></td>
                                    <td><?php echo htmlspecialchars($fee['fee_type_title']); ?></td>
                                    <td>
                                        <span class="badge-type <?php echo getFeeTypeBadge($fee['fee_type_category']); ?>">
                                            <?php echo getFeeTypeDisplay($fee['fee_type_category']); ?>
                                        </span>
                                        <?php if ($fee['fee_type_category'] == 'one_time'): ?>
                                            <i class="fas fa-info-circle text-info ml-1" 
                                               title="One time fee - will be charged only once on the fee card"></i>
                                        <?php elseif ($fee['fee_type_category'] == 'monthly'): ?>
                                            <i class="fas fa-info-circle text-info ml-1" 
                                               title="Monthly fee - will be charged every month on the fee card"></i>
                                        <?php elseif ($fee['fee_type_category'] == 'yearly'): ?>
                                            <i class="fas fa-info-circle text-info ml-1" 
                                               title="Yearly fee - will be charged annually on the fee card"></i>
                                        <?php elseif ($fee['fee_type_category'] == 'promotion'): ?>
                                            <i class="fas fa-info-circle text-info ml-1" 
                                               title="Promotion fee - special promotion fee"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right font-weight-bold <?php echo $fee['amount'] == 0 ? 'zero-amount' : ''; ?>">
                                        <?php if ($fee['amount'] == 0): ?>
                                            <i class="fas fa-gift mr-1"></i>Free
                                        <?php else: ?>
                                            Rs. <?php echo number_format($fee['amount'], 2); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="?student_id=<?php echo $student_id; ?>&type=<?php echo $current_mode; ?>&edit_fee=<?php echo $fee['id']; ?>" 
                                           class="btn btn-sm btn-warning btn-action" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="?student_id=<?php echo $student_id; ?>&type=<?php echo $current_mode; ?>&delete_fee=<?php echo $fee['id']; ?>" 
                                           class="btn btn-sm btn-danger btn-action" 
                                           onclick="return confirm('Are you sure you want to permanently remove this fee?');"
                                           title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="bg-light">
                                    <th colspan="3" class="text-right">Total Fee:</th>
                                    <th class="text-right total-amount" id="tableTotal">
                                        Rs. <?php echo number_format($total_fee, 2); ?>
                                    </th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle mr-2"></i>
                        No fee structure assigned yet. Please add fees using the form above.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    $(document).ready(function() {
        // Get current total
        var currentTotal = <?php echo $total_fee; ?>;
        var assignedFeeTypes = <?php echo json_encode($assigned_fee_ids); ?>;
        var editMode = <?php echo $edit_fee ? 'true' : 'false'; ?>;
        var editFeeId = <?php echo $edit_fee ? $edit_fee['fee_type_id'] : 'null'; ?>;
        
        // Real-time calculation on amount input
        $('#amount, #feeType').on('input change', function() {
            var newAmount = parseFloat($('#amount').val()) || 0;
            var feeType = $('#feeType').val();
            
            if (feeType) {
                var newTotal = currentTotal;
                
                // If editing existing fee
                <?php if ($edit_fee): ?>
                var oldAmount = <?php echo $edit_fee['amount']; ?>;
                newTotal = currentTotal - oldAmount + newAmount;
                <?php else: ?>
                newTotal = currentTotal + newAmount;
                <?php endif; ?>
                
                $('#newTotal').text(newTotal.toFixed(2));
                $('#realtimeDisplay').show();
            } else {
                $('#realtimeDisplay').hide();
            }
        });
        
        // Check for duplicate fee type on selection
        $('#feeType').on('change', function() {
            var selectedValue = parseInt($(this).val());
            var isDuplicate = false;
            
            if (!selectedValue) {
                $('#duplicateWarning').hide();
                $(this).removeClass('is-duplicate');
                $('#submitBtn').prop('disabled', false);
                return;
            }
            
            // For edit mode, check if selected value is different from current and already assigned
            if (editMode) {
                if (selectedValue !== editFeeId && assignedFeeTypes.includes(selectedValue)) {
                    isDuplicate = true;
                }
            } else {
                // For new entries, check if already assigned
                if (assignedFeeTypes.includes(selectedValue)) {
                    isDuplicate = true;
                }
            }
            
            if (isDuplicate) {
                $(this).addClass('is-duplicate');
                $('#duplicateWarning').show();
                $('#submitBtn').prop('disabled', true);
            } else {
                $(this).removeClass('is-duplicate');
                $('#duplicateWarning').hide();
                $('#submitBtn').prop('disabled', false);
            }
        });
        
        // Form validation with duplicate check - removed zero amount validation
        $('#feeForm').submit(function(e) {
            var feeType = $('#feeType').val();
            var amount = $('#amount').val();
            var isDuplicate = false;
            
            // Check for duplicate
            if (!editMode) {
                if (assignedFeeTypes.includes(parseInt(feeType))) {
                    isDuplicate = true;
                }
            } else {
                if (parseInt(feeType) !== editFeeId && assignedFeeTypes.includes(parseInt(feeType))) {
                    isDuplicate = true;
                }
            }
            
            if (!feeType) {
                alert('Please select a fee type');
                e.preventDefault();
                return false;
            }
            
            if (isDuplicate) {
                alert('This fee type is already assigned to the student!');
                e.preventDefault();
                return false;
            }
            
            // Allow zero amount - removed validation that prevents zero
            
            return true;
        });
        
        // Filter table by fee type
        $('.filter-badge').click(function() {
            var filter = $(this).data('filter');
            
            // Update active state
            $('.filter-badge').removeClass('active');
            $(this).addClass('active');
            
            if (filter === 'all') {
                $('#feeTable tbody tr').show();
            } else {
                $('#feeTable tbody tr').hide();
                $('#feeTable tbody tr[data-type="' + filter + '"]').show();
            }
            
            // Update fee count
            $('#feeCount').text($('#feeTable tbody tr:visible').length);
        });
        
        // Trigger change on page load if editing
        <?php if ($edit_fee): ?>
        $('#amount').trigger('input');
        <?php endif; ?>
        
        // Highlight zero amount rows
        $('.zero-amount').each(function() {
            $(this).css('color', '#dc3545');
        });
    });
    </script>
</body>
</html>
<?php
$conn->close();
?>