<?php
ob_start();
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
require_once('security.php');
require_once('conn_inc.php');

// Get student ID from URL
$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;

// Initialize variables
$student = [];
$current_class = [];
$fee_cards = [];
$advance_payments = [];
$total_advance_available = 0;
$error = '';
$success = '';

// Get student information
if ($student_id > 0) {
    try {
        // Get basic student info
        $stmt = $conn->prepare("SELECT id, name, father_name, mobile FROM student_registration WHERE id = ?");
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
                
                // Get available advance payments
                $stmt = $conn->prepare("SELECT ap.id, ap.amount, ap.remaining_amount, ap.payment_date, ap.payment_method, ap.remarks, ap.status,
                                       (SELECT COALESCE(SUM(used_amount), 0) 
                                        FROM advance_payment_usage 
                                        WHERE advance_payment_id = ap.id) AS used_amount
                                       FROM advance_payments ap
                                       WHERE ap.student_class_id = ? AND ap.status IN ('active') 
                                       AND ap.remaining_amount > 0
                                       ORDER BY ap.payment_date ASC");
                $stmt->bind_param("i", $current_class['student_class_id']);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $advance_payments[] = $row;
                    $total_advance_available += $row['remaining_amount'];
                }
                
                // Get fee cards for the current class
                $stmt = $conn->prepare("SELECT sfc.id, sfc.fee_type_id, sfc.total_amount, sfc.due_date, sfc.status,
                                       ft.title AS fee_type_title,
                                       (SELECT COALESCE(SUM(paid_amount), 0) 
                                        FROM student_fee_payments 
                                        WHERE fee_card_id = sfc.id) AS paid_amount
                                       FROM student_fee_card sfc
                                       JOIN fee_types ft ON sfc.fee_type_id = ft.id
                                       WHERE sfc.student_class_id = ? AND sfc.status IN ('pending', 'partial')
                                       ORDER BY sfc.due_date");
                $stmt->bind_param("i", $current_class['student_class_id']);
                $stmt->execute();
                $result = $stmt->get_result();
                
                while ($row = $result->fetch_assoc()) {
                    $remaining = $row['total_amount'] - $row['paid_amount'];
                    $month_name = date('M Y', strtotime($row['due_date']));
                    
                    $fee_cards[] = [
                        'id' => $row['id'],
                        'fee_type_title' => $row['fee_type_title'],
                        'month_name' => $month_name,
                        'total_amount' => $row['total_amount'],
                        'paid_amount' => $row['paid_amount'],
                        'remaining_amount' => $remaining,
                        'due_date' => $row['due_date'],
                        'status' => $row['status']
                    ];
                }
                
            } else {
                $error = "Student is not currently enrolled in any class. Please enroll the student in a class first.";
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

// Process form submission for advance payments
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_advance'])) {
    error_log("Advance payment form submitted");
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Invalid CSRF token';
    } else {
        try {
            $conn->begin_transaction();
            
            $payment_date = $_POST['payment_date'];
            $payment_method = $_POST['payment_method'];
            $remarks = $_POST['remarks'];
            $advance_amount = floatval($_POST['advance_amount']);
            
            // Check if student_class_id exists
            if (!empty($current_class['student_class_id']) && $advance_amount > 0) {
                // Insert advance payment record
                $stmt = $conn->prepare("INSERT INTO advance_payments 
                    (student_class_id, amount, remaining_amount, payment_date, payment_method, remarks, status) 
                    VALUES (?, ?, ?, ?, ?, ?, 'active')");
                
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                
                $stmt->bind_param("iddsss", $current_class['student_class_id'], $advance_amount, $advance_amount, $payment_date, $payment_method, $remarks);
                
                if (!$stmt->execute()) {
                    throw new Exception("Execute failed: " . $stmt->error);
                }
                
                $advance_payment_id = $conn->insert_id;
                
                // Insert into accounts_details for accounting
                $desc = "Advance payment for student ID: " . $student_id . " - " . $remarks;
                $stmt = $conn->prepare("INSERT INTO accounts_details (account_id, amount, description, dated) VALUES (?, ?, ?, ?)");
                
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                
                $stmt->bind_param("idss", $student_id, $advance_amount, $desc, $payment_date);
                
                if (!$stmt->execute()) {
                    throw new Exception("Execute failed: " . $stmt->error);
                }
                
                $conn->commit();
                $success = 'Advance payment submitted successfully';
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                header("Location: advance_payment.php?student_id=" . $student_id);
                exit;
            } else {
                throw new Exception("Invalid student class or advance amount. Student Class ID: " . (!empty($current_class['student_class_id']) ? $current_class['student_class_id'] : 'empty') . ", Amount: " . $advance_amount);
            }
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Error submitting advance payment: ' . $e->getMessage();
            error_log("Advance payment error: " . $e->getMessage());
        }
    }
}

// Process form submission for applying advance payments to specific fee cards
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['apply_advance'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Invalid CSRF token';
    } else {
        try {
            $conn->begin_transaction();
            
            $fee_card_id = intval($_POST['fee_card_id']);
            $advance_payment_id = intval($_POST['advance_payment_id']);
            $apply_amount = floatval($_POST['apply_amount']);
            
            // Get fee card details
            $stmt = $conn->prepare("SELECT sfc.id, sfc.total_amount, sfc.student_class_id,
                                   (SELECT COALESCE(SUM(paid_amount), 0) 
                                    FROM student_fee_payments 
                                    WHERE fee_card_id = sfc.id) AS paid_amount
                                   FROM student_fee_card sfc 
                                   WHERE sfc.id = ?");
            $stmt->bind_param("i", $fee_card_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $fee_card = $result->fetch_assoc();
            
            // Get advance payment details
            $stmt = $conn->prepare("SELECT id, remaining_amount FROM advance_payments WHERE id = ? AND status = 'active'");
            $stmt->bind_param("i", $advance_payment_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $advance_payment = $result->fetch_assoc();
            
            if ($fee_card && $advance_payment) {
                $remaining_fee = $fee_card['total_amount'] - $fee_card['paid_amount'];
                $available_advance = $advance_payment['remaining_amount'];
                
                // Calculate amount to apply (minimum of remaining fee and available advance)
                $amount_to_apply = min($apply_amount, $remaining_fee, $available_advance);
                
                if ($amount_to_apply > 0) {
                    // Create a special payment record for advance payment
                    $payment_remarks = "Advance payment applied (Advance ID: " . $advance_payment_id . ")";
                    $stmt = $conn->prepare("INSERT INTO student_fee_payments 
                                          (fee_card_id, paid_amount, payment_date, payment_method, remarks, is_advance, advance_payment_id) 
                                          VALUES (?, ?, CURDATE(), 'advance', ?, 1, ?)");
                    $stmt->bind_param("idsi", $fee_card_id, $amount_to_apply, $payment_remarks, $advance_payment_id);
                    $stmt->execute();
                    
                    // Record advance payment usage
                    $stmt = $conn->prepare("INSERT INTO advance_payment_usage 
                                          (advance_payment_id, fee_card_id, used_amount, usage_date) 
                                          VALUES (?, ?, ?, CURDATE())");
                    $stmt->bind_param("iid", $advance_payment_id, $fee_card_id, $amount_to_apply);
                    $stmt->execute();
                    
                    // Update advance payment remaining amount
                    $new_remaining = $available_advance - $amount_to_apply;
                    $new_status = $new_remaining <= 0 ? 'used' : 'active';
                    $stmt = $conn->prepare("UPDATE advance_payments 
                                          SET remaining_amount = ?, status = ?
                                          WHERE id = ?");
                    $stmt->bind_param("dsi", $new_remaining, $new_status, $advance_payment_id);
                    $stmt->execute();
                    
                    // Update fee card status
                    $new_total_paid = $fee_card['paid_amount'] + $amount_to_apply;
                    $new_status = ($new_total_paid >= $fee_card['total_amount']) ? 'paid' : 
                                  ($new_total_paid > 0 ? 'partial' : 'pending');
                    $stmt = $conn->prepare("UPDATE student_fee_card SET status = ? WHERE id = ?");
                    $stmt->bind_param("si", $new_status, $fee_card_id);
                    $stmt->execute();
                }
            }
            
            $conn->commit();
            $success = 'Advance payment applied successfully';
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            header("Location: advance_payment.php?student_id=" . $student_id);
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Error applying advance payment: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Advance Payment</title>
    <script src="js/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
    <link rel="stylesheet" href="css/mystyle.css" />
    <style>
        body {
            font-family: 'Arial Narrow', Arial, sans-serif;
        }
        .table th, .table td {
            text-align: left;
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
        .advance-available {
            color: #198754;
            font-weight: bold;
        }
        .advance-used {
            color: #6c757d;
        }
        .advance-row {
            cursor: pointer;
        }
        .advance-row:hover {
            background-color: #f8f9fa;
        }
        .form-section-title {
            background-color: #f8f9fa;
            padding: 10px 15px;
            border-left: 4px solid #007bff;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .no-class-error {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .back-btn {
            margin-bottom: 20px;
        }
        .fee-card-item {
            padding: 10px;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            margin-bottom: 10px;
            background-color: #f8f9fa;
        }
        .fee-card-item:hover {
            background-color: #e9ecef;
        }
        .apply-advance-btn {
            margin-top: 10px;
        }
        .due-amount {
            color: #e74c3c;
            font-weight: bold;
        }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="container">
    <div class="row">
        <div class="col-md-12">
            <div class="back-btn">
                <a href="fee_collection.php?id=<?php echo $student_id; ?>" class="btn btn-default">
                    <span class="glyphicon glyphicon-arrow-left"></span> Back to Fee Collection
                </a>
                <a href="student_details.php?id=<?php echo $student_id; ?>" class="btn btn-primary">
                    <span class="glyphicon glyphicon-user"></span> Student Details
                </a>
            </div>
            
            <h2>Advance Payment Management</h2>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php elseif ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <?php if (!empty($student)): ?>
                <?php if (!empty($current_class)): ?>
                <!-- Student Basic Info -->
                <div class="student-info">
                    <h3>Student Information</h3>
                    <p><strong>Student Name:</strong> <?php echo htmlspecialchars($student['name']); ?></p>
                    <p><strong>Father Name:</strong> <?php echo htmlspecialchars($student['father_name']); ?></p>
                    <p><strong>Current Class:</strong> 
                        <?php echo htmlspecialchars($current_class['class_title']); ?>
                    </p>
                    <p><strong>Current Session:</strong> 
                        <?php echo htmlspecialchars($current_class['session_title']); ?>
                    </p>
                </div>

                <!-- Advance Payments Summary -->
                <div class="panel panel-warning">
                    <div class="panel-heading">
                        <h3 class="panel-title">Available Advance Payments</h3>
                    </div>
                    <div class="panel-body">
                        <?php if (!empty($advance_payments)): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>Advance ID</th>
                                        <th>Payment Date</th>
                                        <th>Original Amount</th>
                                        <th>Remaining Amount</th>
                                        <th>Used Amount</th>
                                        <th>Payment Method</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_advance = 0;
                                    $total_remaining = 0;
                                    foreach ($advance_payments as $advance): 
                                        $used_amount = $advance['used_amount'] ?: ($advance['amount'] - $advance['remaining_amount']);
                                        $total_advance += $advance['amount'];
                                        $total_remaining += $advance['remaining_amount'];
                                    ?>
                                        <tr class="advance-row">
                                            <td>#<?php echo $advance['id']; ?></td>
                                            <td><?php echo $advance['payment_date']; ?></td>
                                            <td><?php echo number_format($advance['amount'], 2); ?> PKR</td>
                                            <td class="advance-available">
                                                <strong><?php echo number_format($advance['remaining_amount'], 2); ?> PKR</strong>
                                            </td>
                                            <td class="advance-used">
                                                <?php echo number_format($used_amount, 2); ?> PKR
                                            </td>
                                            <td><?php echo $advance['payment_method']; ?></td>
                                            <td>
                                                <span class="label label-success">
                                                    Available
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr style="font-weight: bold; background-color: #f5f5f5;">
                                        <td colspan="2"><strong>Totals:</strong></td>
                                        <td><?php echo number_format($total_advance, 2); ?> PKR</td>
                                        <td class="advance-available"><?php echo number_format($total_remaining, 2); ?> PKR</td>
                                        <td><?php echo number_format($total_advance - $total_remaining, 2); ?> PKR</td>
                                        <td colspan="2"></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                        <div class="alert alert-info">
                            <strong>Total Advance Available:</strong> <span class="advance-available"><?php echo number_format($total_advance_available, 2); ?> PKR</span><br>
                            <small>Advance payments will NOT be automatically deducted. Use the form below to manually apply advance payments to fee cards.</small>
                        </div>
                    </div>
                </div>

                <!-- New Advance Payment Form -->
                <div class="panel panel-success">
                    <div class="panel-heading">
                        <h3 class="panel-title">Add New Advance Payment</h3>
                    </div>
                    <div class="panel-body">
                        <div class="form-section-title">
                            <h4>Enter Advance Payment Details</h4>
                        </div>
                        
                        <form method="post" class="form-horizontal" id="advanceForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="submit_advance" value="1">
                            
                            <div class="form-group">
                                <label class="col-sm-3 control-label">Advance Amount (PKR)</label>
                                <div class="col-sm-9">
                                    <input type="number" name="advance_amount" class="form-control" 
                                           min="1" step="0.01" required 
                                           placeholder="Enter advance payment amount">
                                    <small class="text-muted">Minimum amount: 1 PKR</small>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="col-sm-3 control-label">Payment Date</label>
                                <div class="col-sm-9">
                                    <input type="date" name="payment_date" class="form-control" 
                                           value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="col-sm-3 control-label">Payment Method</label>
                                <div class="col-sm-9">
                                    <select name="payment_method" class="form-control" required>
                                        <option value="cash">Cash</option>
                                        <option value="bank_transfer">Bank Transfer</option>
                                        <option value="cheque">Cheque</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="col-sm-3 control-label">Remarks</label>
                                <div class="col-sm-9">
                                    <textarea name="remarks" class="form-control" rows="2" 
                                              placeholder="Advance payment remarks (optional)"></textarea>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <div class="col-sm-offset-3 col-sm-9">
                                    <button type="submit" name="submit_advance" class="btn btn-success btn-lg">
                                        <span class="glyphicon glyphicon-forward"></span> Submit Advance Payment
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Apply Advance to Specific Fee Cards -->
                <?php if (!empty($fee_cards) && !empty($advance_payments)): ?>
                <div class="panel panel-info">
                    <div class="panel-heading">
                        <h3 class="panel-title">Apply Advance to Specific Fee Cards</h3>
                    </div>
                    <div class="panel-body">
                        <div class="form-section-title">
                            <h4>Select Advance Payment and Fee Card</h4>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <h5>Available Fee Cards</h5>
                                <?php foreach ($fee_cards as $card): ?>
                                    <div class="fee-card-item">
                                        <p><strong><?php echo $card['month_name']; ?> - <?php echo $card['fee_type_title']; ?></strong></p>
                                        <p>Total Amount: <?php echo number_format($card['total_amount'], 2); ?> PKR</p>
                                        <p>Paid Amount: <?php echo number_format($card['paid_amount'], 2); ?> PKR</p>
                                        <p class="due-amount">Due Amount: <?php echo number_format($card['remaining_amount'], 2); ?> PKR</p>
                                        <p>Due Date: <?php echo $card['due_date']; ?></p>
                                        <p>Status: 
                                            <span class="label label-<?php echo $card['status'] == 'paid' ? 'success' : ($card['status'] == 'partial' ? 'warning' : 'danger'); ?>">
                                                <?php echo ucfirst($card['status']); ?>
                                            </span>
                                        </p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="col-md-6">
                                <form method="post" class="form-horizontal" id="applyAdvanceForm">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="apply_advance" value="1">
                                    
                                    <div class="form-group">
                                        <label class="control-label">Select Advance Payment</label>
                                        <select name="advance_payment_id" class="form-control" required>
                                            <option value="">-- Select Advance Payment --</option>
                                            <?php foreach ($advance_payments as $advance): ?>
                                                <option value="<?php echo $advance['id']; ?>">
                                                    #<?php echo $advance['id']; ?> - 
                                                    Available: <?php echo number_format($advance['remaining_amount'], 2); ?> PKR
                                                    (Paid: <?php echo $advance['payment_date']; ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="control-label">Select Fee Card to Apply</label>
                                        <select name="fee_card_id" class="form-control" required>
                                            <option value="">-- Select Fee Card --</option>
                                            <?php foreach ($fee_cards as $card): 
                                                if ($card['remaining_amount'] > 0): ?>
                                                <option value="<?php echo $card['id']; ?>" 
                                                        data-remaining="<?php echo $card['remaining_amount']; ?>">
                                                    <?php echo $card['month_name']; ?> - 
                                                    <?php echo $card['fee_type_title']; ?> - 
                                                    Due: <?php echo number_format($card['remaining_amount'], 2); ?> PKR
                                                </option>
                                                <?php endif; 
                                            endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="control-label">Amount to Apply (PKR)</label>
                                        <input type="number" name="apply_amount" class="form-control apply-amount" 
                                               min="1" step="0.01" required 
                                               placeholder="Enter amount to apply">
                                        <small class="text-muted">Maximum: <span id="maxApplyAmount">0.00</span> PKR</small>
                                    </div>
                                    
                                    <div class="form-group">
                                        <button type="submit" name="apply_advance" class="btn btn-warning btn-lg apply-advance-btn">
                                            Apply Advance to Selected Fee Card
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php elseif (empty($fee_cards) && !empty($advance_payments)): ?>
                    <div class="alert alert-info">
                        <h4><span class="glyphicon glyphicon-info-sign"></span> No Pending Fee Cards</h4>
                        <p>All fee cards are already paid or there are no fee cards created for this student.</p>
                    </div>
                <?php endif; ?>
                <?php else: ?>
                <!-- Display error if student is not enrolled in any class -->
                <div class="no-class-error">
                    <h3><span class="glyphicon glyphicon-exclamation-sign"></span> Student Not Enrolled</h3>
                    <p>The student <strong><?php echo htmlspecialchars($student['name']); ?></strong> is not currently enrolled in any class.</p>
                    <p>Please enroll the student in a class before processing advance payments.</p>
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
    // Handle apply advance amount limits
    $('select[name="fee_card_id"]').change(function() {
        var selected = $(this).find('option:selected');
        var remaining = selected.data('remaining') || 0;
        $('#maxApplyAmount').text(remaining.toFixed(2));
        $('.apply-amount').attr('max', remaining);
        $('.apply-amount').attr('placeholder', 'Max: ' + remaining.toFixed(2) + ' PKR');
    });
    
    // Validate advance amount doesn't exceed available amount
    $('#advanceForm').submit(function(e) {
        var advanceAmount = parseFloat($('input[name="advance_amount"]').val()) || 0;
        if (advanceAmount <= 0) {
            alert('Please enter a valid advance amount greater than 0.');
            e.preventDefault();
            return false;
        }
    });
    
    // Validate apply advance amount
    $('#applyAdvanceForm').submit(function(e) {
        var applyAmount = parseFloat($('input[name="apply_amount"]').val()) || 0;
        var maxAmount = parseFloat($('.apply-amount').attr('max')) || 0;
        
        if (applyAmount <= 0) {
            alert('Please enter a valid amount to apply.');
            e.preventDefault();
            return false;
        }
        
        if (applyAmount > maxAmount) {
            alert('Amount to apply cannot exceed ' + maxAmount.toFixed(2) + ' PKR');
            e.preventDefault();
            return false;
        }
    });
});
</script>

</body>
</html>