<?php
ob_start();
session_start();
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
require_once('security.php');
require_once('conn_inc.php');

// Get student ID from URL
$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
$edit_fee_card_id = isset($_GET['edit_fee_card']) ? intval($_GET['edit_fee_card']) : 0;

// Initialize variables
$student = [];
$current_class = [];
$fee_cards = [];
$fee_types = [];
$class_fee_types = [];
$fee_card_to_edit = null;
$error = '';
$success = '';

// Get student information
if ($student_id > 0) {
    try {
        // Get basic student info
        $stmt = $conn->prepare("SELECT id, name, father_name FROM student_registration WHERE id = ?");
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $student = $result->fetch_assoc();
            
            // Get current class and session
            $stmt = $conn->prepare("SELECT sc.id as student_class_id, sc.class_id, sc.session_id, 
                                   c.title AS class_title,
                                   s.title AS session_title
                                   FROM student_class sc
                                   JOIN classes c ON sc.class_id = c.id
                                   JOIN sessions s ON sc.session_id = s.id
                                   WHERE sc.student_registration_id = ?
                                   ORDER BY sc.id DESC LIMIT 1");
            $stmt->bind_param("i", $student_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $current_class = $result->fetch_assoc();
                
                // Get ALL fee types
                $stmt = $conn->prepare("SELECT id, title, type FROM fee_types ORDER BY title");
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $fee_types[] = $row;
                }
                
                // Get class fee types for current class
                $stmt = $conn->prepare("SELECT cft.id, cft.fee_type_id, cft.amount, ft.title, ft.type
                                       FROM class_fee_types cft
                                       JOIN fee_types ft ON cft.fee_type_id = ft.id
                                       WHERE cft.class_id = ? AND cft.session_id = ? AND cft.status = 'active'
                                       ORDER BY ft.title");
                $stmt->bind_param("ii", $current_class['class_id'], $current_class['session_id']);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $class_fee_types[] = $row;
                }
                
                // Get fee card to edit if requested
                if ($edit_fee_card_id > 0) {
                    $stmt = $conn->prepare("SELECT sfc.*, ft.title as fee_type_title, ft.type as fee_type
                                           FROM student_fee_card sfc
                                           JOIN fee_types ft ON sfc.fee_type_id = ft.id
                                           WHERE sfc.id = ? AND sfc.student_class_id = ?");
                    $stmt->bind_param("ii", $edit_fee_card_id, $current_class['student_class_id']);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result->num_rows > 0) {
                        $fee_card_to_edit = $result->fetch_assoc();
                    } else {
                        $error = "Fee card not found.";
                    }
                }
                
                // First, fix any corrupted month data by extracting month from due_date
                $fix_stmt = $conn->prepare("UPDATE student_fee_card SET month = MONTH(due_date) 
                                           WHERE student_class_id = ? AND (month < 1 OR month > 12 OR month IS NULL)");
                $fix_stmt->bind_param("i", $current_class['student_class_id']);
                $fix_stmt->execute();
                
                // Get ALL fee cards for the current class
                $stmt = $conn->prepare("SELECT sfc.id, sfc.fee_type_id, sfc.total_amount, sfc.paid_amount, 
                                               sfc.due_date, sfc.status, sfc.month, sfc.remarks,
                                               ft.title AS fee_type_title, ft.type AS fee_type
                                       FROM student_fee_card sfc
                                       JOIN fee_types ft ON sfc.fee_type_id = ft.id
                                       WHERE sfc.student_class_id = ?
                                       ORDER BY sfc.due_date DESC");
                $stmt->bind_param("i", $current_class['student_class_id']);
                $stmt->execute();
                $result = $stmt->get_result();
                
                while ($row = $result->fetch_assoc()) {
                    $paid_amount = floatval($row['paid_amount']);
                    $total_amount = floatval($row['total_amount']);
                    $remaining = $total_amount - $paid_amount;
                    
                    // Get month name and key from due_date (more reliable)
                    $due_date_timestamp = strtotime($row['due_date']);
                    $month_name = date('M Y', $due_date_timestamp);
                    $month_key = date('Y-m', $due_date_timestamp);
                    $month_number = intval($row['month']); // This will be 1-12
                    
                    $fee_card_data = [
                        'id' => $row['id'],
                        'fee_type_id' => $row['fee_type_id'],
                        'fee_type_title' => $row['fee_type_title'],
                        'fee_type' => $row['fee_type'],
                        'month_key' => $month_key,
                        'month_name' => $month_name,
                        'month_number' => $month_number,
                        'total_amount' => $total_amount,
                        'paid_amount' => $paid_amount,
                        'remaining_amount' => $remaining,
                        'due_date' => $row['due_date'],
                        'status' => $row['status'],
                        'remarks' => $row['remarks']
                    ];
                    
                    $fee_cards[] = $fee_card_data;
                }
                
            } else {
                $error = "Student is not currently enrolled in any class.";
            }
        } else {
            $error = "Student not found.";
        }
    } catch (Exception $e) {
        $error = "Database error: " . $e->getMessage();
    }
} else {
    $error = "Invalid student ID.";
}

// Process edit fee card form
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_fee_card'])) {
    // CSRF Validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Invalid CSRF token. Please refresh the page and try again.';
    } else {
        try {
            $conn->begin_transaction();
            
            $fee_card_id = intval($_POST['fee_card_id']);
            $fee_type_id = intval($_POST['fee_type_id']);
            $total_amount = floatval($_POST['total_amount']);
            $due_date = $_POST['due_date'];
            $remarks = $_POST['remarks'];
            $status = $_POST['status'];
            
            // Get current fee card to check if amount changed
            $stmt = $conn->prepare("SELECT total_amount, paid_amount, student_class_id FROM student_fee_card WHERE id = ?");
            $stmt->bind_param("i", $fee_card_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $old_fee_card = $result->fetch_assoc();
            
            if ($old_fee_card) {
                $paid_amount = floatval($old_fee_card['paid_amount']);
                
                // Check if new total is less than paid amount
                if ($total_amount < $paid_amount) {
                    $error = "Cannot reduce total amount below already paid amount (Paid: " . number_format($paid_amount, 2) . " PKR)";
                } else {
                    // Extract month number from due_date
                    $month_number = intval(date('n', strtotime($due_date)));
                    
                    // Update fee card
                    $stmt = $conn->prepare("UPDATE student_fee_card SET fee_type_id = ?, total_amount = ?, 
                                           due_date = ?, remarks = ?, status = ?, month = ? WHERE id = ?");
                    $stmt->bind_param("idsssii", $fee_type_id, $total_amount, $due_date, $remarks, $status, $month_number, $fee_card_id);
                    $stmt->execute();
                    
                    // Recalculate status based on paid amount
                    $new_status = 'pending';
                    if ($paid_amount >= $total_amount) {
                        $new_status = 'paid';
                    } elseif ($paid_amount > 0) {
                        $new_status = 'partial';
                    }
                    
                    $stmt = $conn->prepare("UPDATE student_fee_card SET status = ? WHERE id = ?");
                    $stmt->bind_param("si", $new_status, $fee_card_id);
                    $stmt->execute();
                    
                    $conn->commit();
                    
                    // Generate new token after successful operation
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    
                    $success = 'Fee card updated successfully';
                    // Redirect to clear POST data
                    header("Location: edit_fee_card.php?student_id=" . $student_id . "&success=1");
                    exit;
                }
            } else {
                $error = 'Fee card not found';
            }
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Error updating fee card: ' . $e->getMessage();
        }
    }
}

// Process create new fee card form
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_fee_card'])) {
    // CSRF Validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Invalid CSRF token. Please refresh the page and try again.';
    } else {
        try {
            $conn->begin_transaction();
            
            $fee_type_id = intval($_POST['new_fee_type_id']);
            $total_amount = floatval($_POST['new_total_amount']);
            $month_year = $_POST['new_month_year'];
            $remarks = $_POST['new_remarks'];
            $student_class_id = $current_class['student_class_id'];
            
            // Parse month and year from selection
            list($year, $month) = explode('-', $month_year);
            
            // Store ONLY the month number (1-12) as integer
            $month_number = intval($month); // This will be 1 for Jan, 2 for Feb, etc.
            
            // Set due date to the 10th of the selected month
            $due_date = $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-10';
            
            // Check if this fee type already exists for this month and year
            // Using both month number and year from due_date
            $check_stmt = $conn->prepare("SELECT id FROM student_fee_card 
                                         WHERE student_class_id = ? 
                                         AND fee_type_id = ? 
                                         AND MONTH(due_date) = ? 
                                         AND YEAR(due_date) = ?");
            $check_stmt->bind_param("iiii", $student_class_id, $fee_type_id, $month_number, $year);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $error = 'This fee type already exists for the selected month. Please edit the existing fee card instead.';
            } else {
                // Insert new fee card - store only month number (1-12)
                $stmt = $conn->prepare("INSERT INTO student_fee_card 
                                       (student_class_id, fee_type_id, total_amount, due_date, month, session_id, status, remarks, dated, paid_amount) 
                                       VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), 0)");
                $stmt->bind_param("iidssis", $student_class_id, $fee_type_id, $total_amount, $due_date, $month_number, $current_class['session_id'], $remarks);
                $stmt->execute();
                
                $conn->commit();
                
                // Generate new token after successful operation
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                
                $success = 'New fee card created successfully for ' . date('F Y', strtotime($due_date));
                // Redirect to clear POST data
                header("Location: edit_fee_card.php?student_id=" . $student_id . "&success=2");
                exit;
            }
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Error creating fee card: ' . $e->getMessage();
        }
    }
}

// Process delete fee card
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_fee_card'])) {
    // CSRF Validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Invalid CSRF token. Please refresh the page and try again.';
    } else {
        try {
            $conn->begin_transaction();
            
            $fee_card_id = intval($_POST['delete_fee_card']);
            
            // Check if fee card has any payments
            $stmt = $conn->prepare("SELECT COUNT(*) as payment_count FROM student_fee_payments WHERE fee_card_id = ?");
            $stmt->bind_param("i", $fee_card_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            if ($row['payment_count'] > 0) {
                $error = 'Cannot delete fee card that has payments. Please delete payments first or edit the fee card instead.';
            } else {
                // Delete fee card
                $stmt = $conn->prepare("DELETE FROM student_fee_card WHERE id = ?");
                $stmt->bind_param("i", $fee_card_id);
                $stmt->execute();
                
                $conn->commit();
                
                // Generate new token after successful operation
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                
                $success = 'Fee card deleted successfully';
                // Redirect to clear POST data
                header("Location: edit_fee_card.php?student_id=" . $student_id . "&success=3");
                exit;
            }
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Error deleting fee card: ' . $e->getMessage();
        }
    }
}

// Check for success message from redirect
if (isset($_GET['success'])) {
    if ($_GET['success'] == 1) {
        $success = 'Fee card updated successfully';
    } elseif ($_GET['success'] == 2) {
        $success = 'New fee card created successfully';
    } elseif ($_GET['success'] == 3) {
        $success = 'Fee card deleted successfully';
    }
}

// Get last amount for each fee type from existing fee cards
$fee_type_last_amount = [];
if (!empty($current_class)) {
    $stmt = $conn->prepare("SELECT sfc.fee_type_id, sfc.total_amount
                            FROM student_fee_card sfc
                            WHERE sfc.student_class_id = ?
                            ORDER BY sfc.fee_type_id, sfc.due_date DESC");
    $stmt->bind_param("i", $current_class['student_class_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if (!isset($fee_type_last_amount[$row['fee_type_id']])) {
            $fee_type_last_amount[$row['fee_type_id']] = $row['total_amount'];
        }
    }
}

// Generate month options for the next 12 months
$month_options = [];
$current_date = date('Y-m-d');
for ($i = 0; $i < 12; $i++) {
    $month = date('Y-m', strtotime("+$i months", strtotime($current_date)));
    $month_display = date('F Y', strtotime($month . '-01'));
    $month_options[$month] = $month_display;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Fee Card</title>
    <script src="js/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
    <link rel="stylesheet" href="css/mystyle.css" />
    <style>
        body {
            font-family: 'Arial Narrow', Arial, sans-serif;
        }
        .container {
            max-width: 1200px;
        }
        .student-info {
            margin-bottom: 20px;
            padding: 15px;
            background-color: #f9f9f9;
            border-radius: 5px;
        }
        .edit-fee-card-panel {
            background-color: #f8f9fa;
            border: 2px solid #007bff;
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .edit-fee-card-header {
            background-color: #007bff;
            color: white;
            padding: 15px;
            border-radius: 8px 8px 0 0;
            margin: -20px -20px 20px -20px;
        }
        .edit-fee-card-title {
            margin: 0;
            font-size: 20px;
        }
        .fee-type-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            margin-left: 10px;
        }
        .fee-type-monthly {
            background-color: #d4edda;
            color: #155724;
        }
        .fee-type-once {
            background-color: #cce5ff;
            color: #004085;
        }
        .fee-type-optional {
            background-color: #fff3cd;
            color: #856404;
        }
        .existing-fee-cards {
            margin-top: 20px;
        }
        .fee-card-item {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-bottom: 10px;
            background-color: white;
        }
        .fee-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .fee-card-actions {
            display: flex;
            gap: 10px;
        }
        .fee-card-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            font-size: 14px;
        }
        .fee-card-detail {
            padding: 5px;
            background-color: #f8f9fa;
            border-radius: 3px;
        }
        .status-badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }
        .status-pending {
            background-color: #fff3cd;
            color: #856404;
        }
        .status-partial {
            background-color: #cce5ff;
            color: #004085;
        }
        .status-paid {
            background-color: #d4edda;
            color: #155724;
        }
        .create-new-fee-card {
            margin-top: 30px;
            padding: 20px;
            background-color: #e7f3fe;
            border-radius: 8px;
            border: 1px solid #b6d4fe;
        }
        .month-year-select {
            margin-bottom: 15px;
        }
        .back-button {
            margin-bottom: 20px;
        }
        @media (max-width: 768px) {
            .fee-card-details {
                grid-template-columns: 1fr;
            }
            .fee-card-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .fee-card-actions {
                margin-top: 10px;
                width: 100%;
                justify-content: flex-end;
            }
        }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="container">
    <div class="row">
        <div class="col-md-12">
            <!-- Back Button -->
            <div class="back-button">
                <a href="fee_collection.php?id=<?php echo $student_id; ?>" class="btn btn-default">
                    <span class="glyphicon glyphicon-arrow-left"></span> Back to Fee Collection
                </a>
            </div>
            
            <h2>Manage Fee Cards</h2>
            
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <span class="glyphicon glyphicon-exclamation-sign"></span> 
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <span class="glyphicon glyphicon-ok-sign"></span> 
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($student)): ?>
                <?php if (!empty($current_class)): ?>
                <!-- Student Information -->
                <div class="student-info">
                    <h3>Student Information</h3>
                    <p><strong>Student Name:</strong> <?php echo htmlspecialchars($student['name']); ?></p>
                    <p><strong>Father Name:</strong> <?php echo htmlspecialchars($student['father_name']); ?></p>
                    <p><strong>Current Class:</strong> <?php echo htmlspecialchars($current_class['class_title']); ?></p>
                    <p><strong>Current Session:</strong> <?php echo htmlspecialchars($current_class['session_title']); ?></p>
                </div>

                <div class="edit-fee-card-panel">
                    <div class="edit-fee-card-header">
                        <h3 class="edit-fee-card-title">
                            <span class="glyphicon glyphicon-edit"></span> 
                            <?php echo $edit_fee_card_id > 0 ? 'Edit Fee Card' : 'Manage Fee Cards'; ?>
                        </h3>
                    </div>
                    
                    <?php if ($edit_fee_card_id > 0 && $fee_card_to_edit): ?>
                    <!-- Edit Existing Fee Card Form -->
                    <form method="post" class="form-horizontal">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="fee_card_id" value="<?php echo $fee_card_to_edit['id']; ?>">
                        
                        <div class="form-group">
                            <label class="col-sm-3 control-label">Fee Type</label>
                            <div class="col-sm-9">
                                <select name="fee_type_id" class="form-control" required>
                                    <option value="">Select Fee Type</option>
                                    <?php foreach ($fee_types as $type): ?>
                                        <option value="<?php echo $type['id']; ?>" 
                                            <?php echo $type['id'] == $fee_card_to_edit['fee_type_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($type['title']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="col-sm-3 control-label">Total Amount (PKR)</label>
                            <div class="col-sm-9">
                                <input type="number" name="total_amount" class="form-control" 
                                       step="0.01" min="<?php echo $fee_card_to_edit['paid_amount']; ?>" 
                                       value="<?php echo number_format($fee_card_to_edit['total_amount'], 2, '.', ''); ?>" required>
                                <small class="text-muted">Minimum: <?php echo number_format($fee_card_to_edit['paid_amount'], 2); ?> PKR (already paid)</small>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="col-sm-3 control-label">Due Date</label>
                            <div class="col-sm-9">
                                <input type="date" name="due_date" class="form-control" 
                                       value="<?php echo $fee_card_to_edit['due_date']; ?>" required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="col-sm-3 control-label">Status</label>
                            <div class="col-sm-9">
                                <select name="status" class="form-control" required>
                                    <option value="pending" <?php echo $fee_card_to_edit['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="partial" <?php echo $fee_card_to_edit['status'] == 'partial' ? 'selected' : ''; ?>>Partial</option>
                                    <option value="paid" <?php echo $fee_card_to_edit['status'] == 'paid' ? 'selected' : ''; ?>>Paid</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="col-sm-3 control-label">Remarks</label>
                            <div class="col-sm-9">
                                <textarea name="remarks" class="form-control" rows="2"><?php echo htmlspecialchars($fee_card_to_edit['remarks']); ?></textarea>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div class="col-sm-offset-3 col-sm-9">
                                <button type="submit" name="edit_fee_card" class="btn btn-primary">
                                    <span class="glyphicon glyphicon-save"></span> Update Fee Card
                                </button>
                                <a href="edit_fee_card.php?student_id=<?php echo $student_id; ?>" class="btn btn-default">Cancel</a>
                            </div>
                        </div>
                    </form>
                    <?php else: ?>
                    
                    <!-- Create New Fee Card Form -->
                    <div class="create-new-fee-card">
                        <h4><span class="glyphicon glyphicon-plus"></span> Create New Fee Card</h4>
                        <form method="post" class="form-horizontal">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            
                            <div class="form-group">
                                <label class="col-sm-3 control-label">Fee Type</label>
                                <div class="col-sm-9">
                                    <select name="new_fee_type_id" class="form-control" id="new_fee_type_id" required>
                                        <option value="">Select Fee Type</option>
                                        <?php foreach ($fee_types as $type): ?>
                                            <option value="<?php echo $type['id']; ?>">
                                                <?php echo htmlspecialchars($type['title']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Class-specific fee types: 
                                        <?php 
                                        if (!empty($class_fee_types)) {
                                            foreach ($class_fee_types as $cft) {
                                                echo '<span class="label label-info">' . $cft['title'] . ' (' . number_format($cft['amount'], 2) . ' PKR)</span> ';
                                            }
                                        } else {
                                            echo 'No class-specific fee types found';
                                        }
                                        ?>
                                    </small>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="col-sm-3 control-label">Month</label>
                                <div class="col-sm-9">
                                    <select name="new_month_year" class="form-control" required>
                                        <option value="">Select Month</option>
                                        <?php foreach ($month_options as $value => $label): ?>
                                            <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Select the month for this fee card</small>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="col-sm-3 control-label">Total Amount (PKR)</label>
                                <div class="col-sm-9">
                                    <input type="number" name="new_total_amount" id="new_total_amount" class="form-control" 
                                           step="0.01" min="0" value="" required>
                                    <small class="text-muted">Enter the fee amount in PKR</small>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="col-sm-3 control-label">Remarks</label>
                                <div class="col-sm-9">
                                    <textarea name="new_remarks" class="form-control" rows="2" placeholder="Optional remarks"></textarea>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <div class="col-sm-offset-3 col-sm-9">
                                    <button type="submit" name="create_fee_card" class="btn btn-success">
                                        <span class="glyphicon glyphicon-plus"></span> Create Fee Card
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Existing Fee Cards List -->
                    <div class="existing-fee-cards">
                        <h4><span class="glyphicon glyphicon-list"></span> Existing Fee Cards</h4>
                        <?php if (!empty($fee_cards)): ?>
                            <?php 
                            // Group fee cards by month
                            $fee_cards_by_month = [];
                            foreach ($fee_cards as $card) {
                                $month_key = $card['month_key'];
                                if (!isset($fee_cards_by_month[$month_key])) {
                                    $fee_cards_by_month[$month_key] = [];
                                }
                                $fee_cards_by_month[$month_key][] = $card;
                            }
                            krsort($fee_cards_by_month); // Show newest first
                            ?>
                            
                            <?php foreach ($fee_cards_by_month as $month_key => $month_cards): ?>
                                <div class="month-year-select">
                                    <h5><?php echo date('F Y', strtotime($month_key . '-01')); ?></h5>
                                    <?php foreach ($month_cards as $card): ?>
                                        <div class="fee-card-item">
                                            <div class="fee-card-header">
                                                <strong><?php echo htmlspecialchars($card['fee_type_title']); ?></strong>
                                                <div class="fee-card-actions">
                                                    <span class="status-badge status-<?php echo $card['status']; ?>">
                                                        <?php echo strtoupper($card['status']); ?>
                                                    </span>
                                                    <a href="edit_fee_card.php?student_id=<?php echo $student_id; ?>&edit_fee_card=<?php echo $card['id']; ?>" 
                                                       class="btn btn-xs btn-warning">
                                                        <span class="glyphicon glyphicon-edit"></span> Edit
                                                    </a>
                                                    <?php if ($card['paid_amount'] <= 0): ?>
                                                        <form method="post" style="display: inline;">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                            <input type="hidden" name="delete_fee_card" value="<?php echo $card['id']; ?>">
                                                            <button type="submit" class="btn btn-xs btn-danger" onclick="return confirm('Are you sure you want to delete this fee card?')">
                                                                <span class="glyphicon glyphicon-trash"></span> Delete
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="fee-card-details">
                                                <div class="fee-card-detail">
                                                    <strong>Total Amount:</strong> <?php echo number_format($card['total_amount'], 2); ?> PKR
                                                </div>
                                                <div class="fee-card-detail">
                                                    <strong>Paid Amount:</strong> <?php echo number_format($card['paid_amount'], 2); ?> PKR
                                                </div>
                                                <div class="fee-card-detail">
                                                    <strong>Due Amount:</strong> <span class="due-amount"><?php echo number_format($card['remaining_amount'], 2); ?> PKR</span>
                                                </div>
                                                <div class="fee-card-detail">
                                                    <strong>Due Date:</strong> <?php echo date('d-M-Y', strtotime($card['due_date'])); ?>
                                                </div>
                                                <div class="fee-card-detail">
                                                    <strong>Month:</strong> <?php echo date('F', strtotime($card['due_date'])); ?> (<?php echo $card['month_number']; ?>)
                                                </div>
                                                <div class="fee-card-detail">
                                                    <strong>Type:</strong> 
                                                    <span class="fee-type-badge fee-type-<?php echo strtolower($card['fee_type']); ?>">
                                                        <?php echo strtoupper($card['fee_type']); ?>
                                                    </span>
                                                </div>
                                                <?php if (!empty($card['remarks'])): ?>
                                                <div class="fee-card-detail">
                                                    <strong>Remarks:</strong> <?php echo htmlspecialchars($card['remarks']); ?>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <p>No fee cards found for this student.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <!-- Display error if student is not enrolled in any class -->
                <div class="alert alert-danger">
                    <h4><span class="glyphicon glyphicon-exclamation-sign"></span> Student Not Enrolled</h4>
                    <p>The student is not currently enrolled in any class. Please enroll the student in a class first.</p>
                    <a href="student_details.php?id=<?php echo $student_id; ?>" class="btn btn-primary">
                        <span class="glyphicon glyphicon-user"></span> Go to Student Details
                    </a>
                    <a href="student_class_enrollment.php?student_id=<?php echo $student_id; ?>" class="btn btn-success">
                        <span class="glyphicon glyphicon-plus"></span> Enroll in Class
                    </a>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Auto-fill class fee type amount
    $('#new_fee_type_id').change(function() {
        var feeTypeId = $(this).val();
        var classFeeTypes = <?php echo json_encode($class_fee_types); ?>;
        
        // Find matching class fee type
        var matchingFeeType = classFeeTypes.find(function(feeType) {
            return feeType.fee_type_id == feeTypeId;
        });
        
        if (matchingFeeType) {
            $('#new_total_amount').val(matchingFeeType.amount);
        }
    });
    
    // Get last amount for fee type
    var feeTypeLastAmount = <?php echo json_encode($fee_type_last_amount); ?>;
    
    $('#new_fee_type_id').on('change', function() {
        var feeTypeId = $(this).val();
        var lastAmount = feeTypeLastAmount[feeTypeId];
        
        // If we have a last amount for this fee type and no amount is entered yet
        if (lastAmount && $('#new_total_amount').val() === '') {
            $('#new_total_amount').val(lastAmount);
        }
    });
});
</script>

</body>
</html>