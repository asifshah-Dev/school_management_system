<?php 
require_once('security.php');

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
require_once('whatsapp_helper.php');

// Initialize display variables
$display_data = null;
$selected_session_id = 0;
$selected_class_id = 0;
$print_type = '';
$all_students_data = [];

// Check if we have data in session from previous request
if (isset($_SESSION['form_data'])) {
    $selected_session_id = $_SESSION['form_data']['session_id'] ?? 0;
    $selected_class_id = $_SESSION['form_data']['class_id'] ?? 0;
    $print_type = $_SESSION['form_data']['print_type'] ?? '';
    unset($_SESSION['form_data']);
}

// Clear print data if coming back from print view
if (isset($_GET['clear']) && $_GET['clear'] == 1) {
    unset($_SESSION['print_data']);
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['message'] = 'Invalid request!';
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    
    $selected_session_id = isset($_POST['session_id']) ? intval($_POST['session_id']) : 0;
    $selected_class_id = isset($_POST['class_id']) ? intval($_POST['class_id']) : 0;
    $print_type = isset($_POST['print_type']) ? $_POST['print_type'] : '';
    $action = isset($_POST['action']) ? $_POST['action'] : 'view';
    
    // Check for selected students
    $selected_students = [];
    if (isset($_POST['selected_students'])) {
        if (is_array($_POST['selected_students'])) {
            $selected_students = array_map('intval', $_POST['selected_students']);
        } elseif (is_string($_POST['selected_students']) && !empty($_POST['selected_students'])) {
            $selected_students = array_map('intval', explode(',', $_POST['selected_students']));
        }
    }

    // ==========================================================
    // 🟢 WHATSAPP SENDING ACTION
    // ==========================================================
    if (in_array($action, ['whatsapp_single', 'whatsapp_bulk'])
        && $selected_session_id > 0
        && $selected_class_id > 0
        && !empty($selected_students)) {

        try {
            // Fetch session title
            $sStmt = $conn->prepare("SELECT title, from_dated, to_dated FROM sessions WHERE id = ?");
            $sStmt->bind_param("i", $selected_session_id);
            $sStmt->execute();
            $sRow = $sStmt->get_result()->fetch_assoc();
            $wa_session_title = $sRow
                ? $sRow['title'] . ' (' . date('M Y', strtotime($sRow['from_dated'])) . ' - ' . date('M Y', strtotime($sRow['to_dated'])) . ')'
                : '';

            // Fetch class title
            $cStmt = $conn->prepare("SELECT title FROM classes WHERE id = ?");
            $cStmt->bind_param("i", $selected_class_id);
            $cStmt->execute();
            $cRow = $cStmt->get_result()->fetch_assoc();
            $wa_class_title = $cRow['title'] ?? '';

            $sentCount    = 0;
            $failCount    = 0;
            $noPhoneCount = 0;
            $skipCount    = 0;
            $errors       = [];

            foreach ($selected_students as $studentId) {
                $stuStmt = $conn->prepare(
                    "SELECT id, name, father_name, mobile, is_old_dues, old_dues_amount
                     FROM student_registration
                     WHERE id = ? AND status = 0"
                );
                $stuStmt->bind_param("i", $studentId);
                $stuStmt->execute();
                $stu = $stuStmt->get_result()->fetch_assoc();

                if (!$stu) {
                    $skipCount++;
                    continue;
                }

                if (empty($stu['mobile'])) {
                    $noPhoneCount++;
                    $errors[] = "{$stu['name']}: No mobile number";
                    continue;
                }

                $dues = calculateStudentDues($conn, $studentId, $selected_session_id);
                $oldDues = ($stu['is_old_dues'] == 1) ? floatval($stu['old_dues_amount']) : 0;
                $totalDue = $dues['total_pending'] + $oldDues;

                if ($totalDue <= 0) {
                    $skipCount++;
                    continue;
                }

                $message = buildFeeReminderMessage(
                    $stu,
                    $dues['pending_fees'],
                    $dues['total_pending'],
                    $oldDues,
                    $totalDue,
                    $dues['paid_until_display'],
                    $wa_class_title,
                    $wa_session_title
                );

                $result = sendWhatsAppMessage($stu['mobile'], $message);

                if ($result['success']) {
                    $sentCount++;
                } else {
                    $failCount++;
                    $errors[] = "{$stu['name']}: " . $result['error'];
                }

                if (WHATSAPP_SEND_DELAY_US > 0) {
                    usleep(WHATSAPP_SEND_DELAY_US);
                }
            }

            $parts = [];
            if ($sentCount)    $parts[] = "{$sentCount} sent";
            if ($failCount)    $parts[] = "{$failCount} failed";
            if ($noPhoneCount) $parts[] = "{$noPhoneCount} no mobile";
            if ($skipCount)    $parts[] = "{$skipCount} skipped (no dues)";

            $_SESSION['message'] = '📱 WhatsApp: ' . implode(', ', $parts) . '.';
            if (!empty($errors)) {
                $_SESSION['message'] .= ' | ' . implode(' | ', array_slice($errors, 0, 3));
            }
            $_SESSION['message_type'] = $failCount > 0 ? 'warning' : 'success';

            $_SESSION['form_data'] = [
                'session_id' => $selected_session_id,
                'class_id'   => $selected_class_id,
                'print_type' => $print_type,
            ];

            header("Location: " . $_SERVER['PHP_SELF']);
            exit();

        } catch (Exception $e) {
            $_SESSION['message'] = "WhatsApp error: " . $e->getMessage();
            $_SESSION['message_type'] = 'danger';
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }
    
    if ($selected_session_id > 0 && $selected_class_id > 0 && $print_type != '') {
        try {
            // Fetch session and class details
            $session_query = "SELECT title, from_dated, to_dated FROM sessions WHERE id = ? AND status = 0";
            $session_stmt = $conn->prepare($session_query);
            $session_stmt->bind_param("i", $selected_session_id);
            $session_stmt->execute();
            $session_result = $session_stmt->get_result();
            
            if ($session_row = $session_result->fetch_assoc()) {
                $session_title = $session_row['title'] . ' (' . date('M Y', strtotime($session_row['from_dated'])) . ' - ' . date('M Y', strtotime($session_row['to_dated'])) . ')';
                
                // Fetch class details
                $class_query = "SELECT title FROM classes WHERE id = ?";
                $class_stmt = $conn->prepare($class_query);
                $class_stmt->bind_param("i", $selected_class_id);
                $class_stmt->execute();
                $class_result = $class_stmt->get_result();
                $class_row = $class_result->fetch_assoc();
                $class_title = $class_row['title'] ?? '';
                
                // Fetch students
                $query = "SELECT sr.id, sr.name, sr.father_name, sr.mobile, 
                                 sr.is_old_dues, sr.old_dues_amount,
                                 sc.id AS student_class_id, sc.promotion_date
                          FROM student_registration sr 
                          JOIN student_class sc ON sr.id = sc.student_registration_id 
                          WHERE sc.session_id = ? AND sc.class_id = ? AND sr.status = 0 AND sc.status = 0
                          ORDER BY sr.name";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ii", $selected_session_id, $selected_class_id);
                $stmt->execute();
                $result = $stmt->get_result();

                $students_data = [];
                $total_remaining = 0;
                $total_old_dues = 0;
                
                while ($student_row = $result->fetch_assoc()) {
                    $student_id = $student_row['id'];
                    
                    $fee_cards_query = "SELECT sfc.id, sfc.fee_type_id, sfc.total_amount, 
                                               sfc.due_date, ft.title as fee_type_title,
                                               DATE_FORMAT(sfc.due_date, '%M %Y') AS month_display,
                                               DATE_FORMAT(sfc.due_date, '%Y-%m') AS month_key
                                        FROM student_fee_card sfc
                                        JOIN student_class sc ON sfc.student_class_id = sc.id
                                        JOIN fee_types ft ON sfc.fee_type_id = ft.id
                                        WHERE sc.student_registration_id = ?
                                        AND sc.session_id = ?
                                        ORDER BY sfc.due_date";
                    $fee_cards_stmt = $conn->prepare($fee_cards_query);
                    $fee_cards_stmt->bind_param("ii", $student_id, $selected_session_id);
                    $fee_cards_stmt->execute();
                    $fee_cards_result = $fee_cards_stmt->get_result();
                    
                    $all_months = [];
                    $paid_until = null;
                    $paid_until_display = null;
                    $pending_fees = [];
                    $total_pending = 0;
                    
                    while ($fee_card = $fee_cards_result->fetch_assoc()) {
                        $month_key = $fee_card['month_key'];
                        
                        $payment_query = "SELECT COALESCE(SUM(paid_amount), 0) as total_paid, 
                                                 COALESCE(SUM(discount_amount), 0) as total_discount 
                                          FROM student_fee_payments 
                                          WHERE fee_card_id = ? AND status = 'completed'";
                        $payment_stmt = $conn->prepare($payment_query);
                        $payment_stmt->bind_param("i", $fee_card['id']);
                        $payment_stmt->execute();
                        $payment_result = $payment_stmt->get_result();
                        $payment_data = $payment_result->fetch_assoc();
                        
                        $total_paid = $payment_data['total_paid'] ?? 0;
                        $total_discount = $payment_data['total_discount'] ?? 0;
                        $total_cleared = $total_paid + $total_discount;
                        $due_amount = $fee_card['total_amount'] - $total_cleared;
                        
                        if (!isset($all_months[$month_key])) {
                            $all_months[$month_key] = [
                                'display' => $fee_card['month_display'],
                                'date' => $fee_card['due_date'],
                                'fees' => [],
                                'total_amount' => 0,
                                'total_cleared' => 0,
                                'is_fully_paid' => true
                            ];
                        }
                        
                        $all_months[$month_key]['fees'][] = [
                            'fee_type' => $fee_card['fee_type_title'],
                            'total_amount' => $fee_card['total_amount'],
                            'paid_amount' => $total_paid,
                            'discount_amount' => $total_discount,
                            'due_amount' => max(0, $due_amount),
                            'is_paid' => ($due_amount <= 0)
                        ];
                        
                        $all_months[$month_key]['total_amount'] += $fee_card['total_amount'];
                        $all_months[$month_key]['total_cleared'] += $total_cleared;
                        
                        if ($due_amount > 0) {
                            $all_months[$month_key]['is_fully_paid'] = false;
                        }
                    }
                    
                    ksort($all_months);
                    
                    foreach ($all_months as $month_key => $month_data) {
                        if ($month_data['is_fully_paid'] && $month_data['total_cleared'] >= $month_data['total_amount']) {
                            $paid_until = $month_key;
                            $paid_until_display = $month_data['display'];
                        } else {
                            break;
                        }
                    }
                    
                    $start_collecting = false;
                    foreach ($all_months as $month_key => $month_data) {
                        if ($paid_until === null) {
                            $start_collecting = true;
                        } elseif ($month_key > $paid_until) {
                            $start_collecting = true;
                        }
                        
                        if ($start_collecting) {
                            foreach ($month_data['fees'] as $fee) {
                                if ($fee['due_amount'] > 0) {
                                    $pending_fees[] = [
                                        'fee_type' => $fee['fee_type'],
                                        'month' => $month_data['display'],
                                        'amount' => $fee['due_amount'],
                                        'total_amount' => $fee['total_amount'],
                                        'paid_amount' => $fee['paid_amount'],
                                        'discount_amount' => $fee['discount_amount']
                                    ];
                                    $total_pending += $fee['due_amount'];
                                }
                            }
                        }
                    }
                    
                    $old_dues = 0;
                    if ($student_row['is_old_dues'] == 1 && $student_row['old_dues_amount'] > 0) {
                        $old_dues = $student_row['old_dues_amount'];
                    }
                    
                    $total_due = $total_pending + $old_dues;
                    
                    if ($print_type == 'feecards' || ($print_type == 'defaulter' && $total_due > 0)) {
                        $student_data = [
                            'id' => $student_row['id'],
                            'name' => $student_row['name'],
                            'father_name' => $student_row['father_name'],
                            'mobile' => $student_row['mobile'],
                            'student_class_id' => $student_row['student_class_id'],
                            'old_dues_amount' => $old_dues,
                            'total_pending' => $total_pending,
                            'total_due' => $total_due,
                            'paid_until_display' => $paid_until_display,
                            'pending_fees' => $pending_fees,
                            'all_months' => $all_months
                        ];
                        
                        $students_data[] = $student_data;
                        $all_students_data[] = $student_data;
                        
                        if ($total_due > 0) {
                            $total_remaining += $total_due;
                            $total_old_dues += $old_dues;
                        }
                    }
                }
                
                if (empty($students_data)) {
                    $_SESSION['message'] = 'No students found for this session and class.';
                    $_SESSION['message_type'] = 'warning';
                } else {
                    if (($action == 'print' || $action == 'thermal_print') && !empty($selected_students)) {
                        $filtered_students = [];
                        $filtered_total_remaining = 0;
                        $filtered_total_old_dues = 0;
                        
                        foreach ($students_data as $student) {
                            if (in_array($student['id'], $selected_students)) {
                                $filtered_students[] = $student;
                                $filtered_total_remaining += $student['total_due'];
                                $filtered_total_old_dues += $student['old_dues_amount'];
                            }
                        }
                        
                        if (empty($filtered_students)) {
                            $_SESSION['message'] = 'Please select at least one student to print.';
                            $_SESSION['message_type'] = 'warning';
                            header("Location: " . $_SERVER['PHP_SELF']);
                            exit();
                        }
                        
                        $students_data = $filtered_students;
                        $total_remaining = $filtered_total_remaining;
                        $total_old_dues = $filtered_total_old_dues;
                    }
                    
                    $display_data = [
                        'print_type' => $print_type,
                        'session_title' => $session_title,
                        'class_title' => $class_title,
                        'students' => $students_data,
                        'total_remaining' => $total_remaining,
                        'total_old_dues' => $total_old_dues,
                        'all_students' => $all_students_data,
                        'selected_students' => $selected_students
                    ];
                    
                    $_SESSION['form_data'] = [
                        'session_id' => $selected_session_id,
                        'class_id' => $selected_class_id,
                        'print_type' => $print_type,
                        'selected_students' => $selected_students
                    ];
                    
                    if ($action == 'print') {
                        $_SESSION['print_data'] = $display_data;
                        header("Location: print_view.php");
                        exit();
                    }
                    
                    if ($action == 'thermal_print') {
                        $_SESSION['print_data'] = $display_data;
                        header("Location: thermal_print_view.php");
                        exit();
                    }
                }
                
            } else {
                $_SESSION['message'] = "Invalid session selected.";
                $_SESSION['message_type'] = 'danger';
            }
            
        } catch (Exception $e) {
            $_SESSION['message'] = "Database error: " . $e->getMessage();
            $_SESSION['message_type'] = 'danger';
        }
    }
}

// Get sessions for dropdown
$sessions = [];
try {
    $query = "SELECT id, title, from_dated, to_dated 
              FROM sessions 
              WHERE status = 0 
              ORDER BY from_dated DESC";
    $result = $conn->query($query);
    while ($row = $result->fetch_assoc()) {
        $sessions[] = $row;
    }
} catch (Exception $e) {
    $error = "Database error: " . $e->getMessage();
}

// Get classes for dropdown
$classes = [];
try {
    $query = "SELECT id, title FROM classes WHERE status IS NULL OR status = 0 ORDER BY title";
    $result = $conn->query($query);
    while ($row = $result->fetch_assoc()) {
        $classes[] = $row;
    }
} catch (Exception $e) {
    $error = "Database error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Fee Reminder Slip</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        .container { max-width: 1200px; margin-top: 20px; }
        .panel { margin-top: 20px; }
        .form-group { margin-bottom: 15px; }
        .btn-group { margin-top: 20px; text-align: center; }
        .btn-group .btn { margin: 0 10px; min-width: 200px; }
        .logo-container { text-align: center; margin-bottom: 20px; }
        .logo-container img { max-height: 80px; }
        .alert { margin-top: 20px; }
        .form-control { height: 45px; font-size: 16px; }
        .btn-lg { padding: 12px 30px; font-size: 16px; font-weight: bold; }
        .btn-success { background-color: #28a745; border-color: #28a745; }
        .btn-primary { background-color: #007bff; border-color: #007bff; }
        .btn-warning { background-color: #ffc107; border-color: #ffc107; color: #212529; }
        .panel-heading { background-color: #007bff !important; color: white !important; }
        .results-section { margin-top: 30px; display: <?php echo $display_data ? 'block' : 'none'; ?>; }
        
        .reminder-slip {
            font-family: 'Arial', sans-serif;
            max-width: 800px;
            margin: 0 auto 30px auto;
            padding: 20px;
            border: 2px solid #007bff;
            border-radius: 10px;
            background-color: #fff;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .reminder-header { text-align: center; border-bottom: 2px solid #007bff; padding-bottom: 10px; margin-bottom: 15px; }
        .reminder-header h3 { color: #007bff; font-weight: bold; margin: 5px 0; }
        .reminder-header h4 { color: #28a745; font-weight: bold; }
        .student-info { background-color: #f8f9fa; padding: 10px; border-radius: 5px; margin-bottom: 15px; border-left: 4px solid #007bff; }
        .student-info p { margin: 5px 0; font-size: 14px; }
        .paid-message { background-color: #e8f5e9; color: #28a745; padding: 10px; border-radius: 5px; margin-bottom: 15px; font-weight: bold; font-size: 14px; border-left: 4px solid #28a745; }
        .pending-fees { margin-top: 15px; }
        .pending-fees table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .pending-fees th { background-color: #007bff; color: white; padding: 8px; text-align: left; }
        .pending-fees td { padding: 8px; border-bottom: 1px solid #ddd; }
        .pending-fees tr:last-child td { border-bottom: none; }
        .pending-fees .amount { text-align: right; font-weight: bold; color: #dc3545; }
        .total-row { background-color: #f8f9fa; font-weight: bold; }
        .total-row td { border-top: 2px solid #007bff; }
        .old-dues { background-color: #fff3cd; padding: 8px; border-radius: 5px; margin: 10px 0; border-left: 4px solid #ffc107; }
        .grand-total { background-color: #007bff; color: white; padding: 10px; border-radius: 5px; margin-top: 10px; font-weight: bold; font-size: 16px; text-align: right; }
        .footer-note { margin-top: 15px; text-align: center; font-size: 12px; color: #6c757d; border-top: 1px solid #ddd; padding-top: 10px; }
        
        .student-selection-container {
            background-color: #f8f9fa;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
            border: 1px solid #dee2e6;
            position: sticky;
            top: 70px;
            z-index: 1000;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .student-selection-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .student-selection-title { font-size: 18px; font-weight: bold; color: #007bff; display: flex; align-items: center; gap: 10px; }
        .selection-controls { display: flex; gap: 10px; align-items: center; }
        .print-selected-buttons { display: flex; gap: 10px; align-items: center; margin-left: auto; flex-wrap: wrap; }
        .print-selected-buttons .btn { min-width: 140px; font-size: 14px; padding: 8px 16px; margin: 0; }
        .student-checkbox { transform: scale(1.3); margin-right: 10px; }
        .selected-count { background-color: #28a745; color: white; padding: 5px 15px; border-radius: 20px; font-weight: bold; font-size: 14px; white-space: nowrap; }
        .reminder-checkbox { margin-bottom: 10px; padding: 10px; background-color: #f8f9fa; border-radius: 5px; }
        .bulk-print-buttons { text-align: center; margin: 20px 0; padding-top: 20px; border-top: 2px solid #dee2e6; }
        .bulk-print-buttons .btn { margin: 0 10px 10px 10px; min-width: 200px; }
        .thermal-print-btn { background-color: #17a2b8; border-color: #17a2b8; color: white; }
        .thermal-print-btn:hover { background-color: #138496; border-color: #117a8b; }
        .no-data { text-align: center; padding: 40px; color: #6c757d; font-size: 18px; }
        
        .defaulter-table-display { width: 100%; border-collapse: collapse; font-size: 8px; margin-top: 10px; }
        .defaulter-table-display th, .defaulter-table-display td { padding: 2px 3px; border: 1px solid #000; text-align: center; vertical-align: middle; }
        .defaulter-table-display th { background-color: #f5f5f5; font-weight: 600; }
        .display-header-info { margin-bottom: 10px; font-size: 9px; background-color: #f8f9fa; padding: 10px; border-radius: 4px; border-left: 4px solid #007bff; }
        .rep_header-display { text-align: center; margin-bottom: 5px; padding: 2px; border-bottom: 2px solid #000; font-weight: bold; font-size: 14px; position: relative; font-family: 'Arial Narrow', Arial, sans-serif; }
        .rep_header-display img { height: 50px; position: absolute; left: 10px; top: 5px; }
        .defaulter-checkbox-cell { width: 30px; text-align: center; }
        .defaulter-checkbox { transform: scale(1.2); }
        .defaulter-old-dues { font-weight: bold; color: #ff6b00; }
        .defaulter-total-amount { font-weight: bold; color: #dc3545; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn { display: inline-block; padding: 6px 12px; margin-bottom: 0; font-size: 14px; font-weight: 400; line-height: 1.42857143; text-align: center; white-space: nowrap; vertical-align: middle; cursor: pointer; border: 1px solid transparent; border-radius: 4px; }
        .btn-sm { padding: 5px 10px; font-size: 12px; line-height: 1.5; border-radius: 3px; }
        .btn-default { color: #333; background-color: #fff; border-color: #ccc; }
        .badge { display: inline-block; min-width: 10px; padding: 3px 7px; font-size: 12px; font-weight: 700; line-height: 1; color: #fff; text-align: center; white-space: nowrap; vertical-align: middle; background-color: #777; border-radius: 10px; }
        .whatsapp-btn { background-color: #25D366 !important; border-color: #25D366 !important; color: #fff !important; }
        .whatsapp-btn:hover { background-color: #1da851 !important; border-color: #1a8f47 !important; color: #fff !important; }
        
        @media (max-width: 768px) {
            .student-selection-header { flex-direction: column; align-items: stretch; }
            .print-selected-buttons { margin-left: 0; justify-content: center; }
            .selection-controls { justify-content: center; }
            .student-selection-container { top: 50px; }
        }
    </style>
</head>
<body>
    <?php require_once('navbar.php'); ?>
    
    <?php if (isset($_SESSION['message'])): ?>
    <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible">
        <button type="button" class="close" data-dismiss="alert">&times;</button>
        <?php 
        echo $_SESSION['message']; 
        unset($_SESSION['message'], $_SESSION['message_type']);
        ?>
    </div>
    <?php endif; ?>
    
    <div class="panel panel-primary" style="width:80% !important; margin:0 auto;margin-top:10px;">
        <div class="panel-heading">
            <h3 class="panel-title">Select Session and Class</h3>
        </div>
        <div class="panel-body">
            <form method="post" class="form-horizontal" id="mainForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="view">
                
                <div class="form-group">
                    <label class="control-label col-sm-3">Select Session</label>
                    <div class="col-sm-9">
                        <select name="session_id" class="form-control" required>
                            <option value="">Select an option</option>
                            <?php foreach ($sessions as $session): ?>
                            <option value="<?php echo $session['id']; ?>" 
                                <?php echo ($selected_session_id == $session['id']) ? 'selected' : ''; ?>>
                                <?php echo $session['title'] . ' (' . date('M Y', strtotime($session['from_dated'])) . ' - ' . date('M Y', strtotime($session['to_dated'])) . ')'; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="control-label col-sm-3">Select Class</label>
                    <div class="col-sm-9">
                        <select name="class_id" class="form-control" required>
                            <option value="">Select an option</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" 
                                <?php echo ($selected_class_id == $class['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['title']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <div class="col-sm-12 text-center">
                        <div class="btn-group">
                            <button type="submit" name="print_type" value="feecards" class="btn btn-success btn-lg">
                                <span class="glyphicon glyphicon-list"></span> 
                                Generate Fee Reminder
                            </button>
                            <button type="submit" name="print_type" value="defaulter" class="btn btn-primary btn-lg">
                                <span class="glyphicon glyphicon-list-alt"></span> 
                                Defaulter List
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <?php if ($display_data): ?>
    <div class="results-section">
        <div class="student-selection-container">
            <div class="student-selection-header" style="width:80% !important; margin:0 auto;">
                <div class="student-selection-title">
                    <span class="badge"><?php echo count($display_data['all_students']); ?> students found</span>
                </div>
                
                <div class="selection-controls">
                    <button type="button" class="btn btn-sm btn-success" id="selectAllBtn">
                        <span class="glyphicon glyphicon-check"></span> 
                        Select All
                    </button>
                    <button type="button" class="btn btn-sm btn-default" id="deselectAllBtn">
                        <span class="glyphicon glyphicon-unchecked"></span> 
                        Deselect All
                    </button>
                    <div class="selected-count" id="selectedCount">0 selected</div>
                </div>
                
                <div class="print-selected-buttons">
                    <?php if ($display_data['print_type'] == 'feecards'): ?>
                    <button type="button" class="btn btn-success" id="printSelectedBtn" disabled>
                        <span class="glyphicon glyphicon-print"></span> 
                        Print Selected
                    </button>
                    
                    <button type="button" class="btn btn-warning thermal-print-btn" id="thermalPrintSelectedBtn" disabled>
                        <span class="glyphicon glyphicon-print"></span> 
                        Thermal Print
                    </button>
                    
                    <?php elseif ($display_data['print_type'] == 'defaulter'): ?>
                    <button type="button" class="btn btn-success" id="printSelectedBtn" disabled>
                        <span class="glyphicon glyphicon-print"></span> 
                        Print Selected
                    </button>
                    <?php endif; ?>
                    
                    <button type="button" class="btn whatsapp-btn" id="whatsappSelectedBtn" disabled>
                        <span class="glyphicon glyphicon-earphone"></span> 
                        Send WhatsApp
                    </button>
                    
                    <button type="button" onclick="window.location.href='<?php echo $_SERVER['PHP_SELF']; ?>'" class="btn btn-default">
                        <span class="glyphicon glyphicon-refresh"></span> 
                        New Search
                    </button>
                </div>
            </div>
        </div>
        
        <?php if ($display_data['print_type'] == 'defaulter'): ?>
        <div class="display-container">
            <div class="rep_header-display">
                <img src="logo2.png" />
                <div class="title">Dar-e-Arqam School System</div>
                <div class="contact-info">
                    <span>Phone: 0346-9401982</span>
                    <span>Email: info@das.edu.pk</span>
                </div>
                <div class="registration-info">
                    <span>Defaulter List</span>
                </div>
            </div>

            <div class="display-header-info">
                <p><strong>Session:</strong> <?php echo $display_data['session_title']; ?></p>
                <p><strong>Class:</strong> <?php echo $display_data['class_title']; ?></p>
                <p><strong>Generated Date:</strong> <?php echo date('d-m-Y'); ?></p>
                <p><strong>Previous Dues:</strong> <?php echo number_format($display_data['total_old_dues']); ?> PKR</p>
                <p><strong>Grand Total:</strong> <?php echo number_format($display_data['total_remaining']); ?> PKR</p>
            </div>

            <table class="defaulter-table-display">
                <thead>
                    <tr>
                        <th style="width: 5%;" class="defaulter-checkbox-cell">
                            <input type="checkbox" id="selectAllTable" class="defaulter-checkbox">
                        </th>
                        <th style="width: 5%;">Sr#</th>
                        <th style="width: 8%;">Student ID</th>
                        <th style="width: 20%; text-align: left;">Student Name</th>
                        <th style="width: 15%; text-align: left;">Father Name</th>
                        <th style="width: 10%;">Mobile</th>
                        <th style="width: 10%;">Old Dues</th>
                        <th style="width: 10%;">Pending Fees</th>
                        <th style="width: 10%;">Total Due</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $counter = 1;
                    foreach ($display_data['all_students'] as $student): 
                        if ($student['total_due'] > 0): 
                    ?>
                    <tr>
                        <td class="defaulter-checkbox-cell">
                            <input type="checkbox" name="selected_students[]" value="<?php echo $student['id']; ?>" 
                                   class="student-checkbox defaulter-checkbox" 
                                   data-student-id="<?php echo $student['id']; ?>"
                                   <?php echo in_array($student['id'], $display_data['selected_students']) ? 'checked' : ''; ?>>
                        </td>
                        <td><?php echo $counter++; ?></td>
                        <td><?php echo $student['id']; ?></td>
                        <td style="text-align: left;"><?php echo $student['name']; ?></td>
                        <td style="text-align: left;"><?php echo $student['father_name']; ?></td>
                        <td><?php echo $student['mobile'] ?: '-'; ?></td>
                        <td class="defaulter-old-dues"><?php echo $student['old_dues_amount'] > 0 ? number_format($student['old_dues_amount']) : '-'; ?></td>
                        <td class="defaulter-total-amount"><?php echo number_format($student['total_pending']); ?></td>
                        <td class="defaulter-total-amount"><strong><?php echo number_format($student['total_due']); ?></strong></td>
                    </tr>
                    <?php 
                        endif;
                    endforeach; 
                    ?>
                </tbody>
            </table>
        </div>

        <?php elseif ($display_data['print_type'] == 'feecards'): ?>
        <div class="display-container">
            <?php foreach ($display_data['all_students'] as $student): ?>
            <div class="reminder-checkbox">
                <input type="checkbox" name="selected_students[]" value="<?php echo $student['id']; ?>" 
                       class="student-checkbox" 
                       data-student-id="<?php echo $student['id']; ?>"
                       style="transform: scale(1.3); margin-right: 10px;"
                       <?php echo in_array($student['id'], $display_data['selected_students']) ? 'checked' : ''; ?>>
                <strong>Select <?php echo $student['name']; ?></strong>
            </div>
            
            <div class="reminder-slip">
                <div class="reminder-header">
                    <img src="logo2.png" style="height: 60px; margin-bottom: 10px;" />
                    <h3>Dar-e-Arqam School System</h3>
                    <h4>FEE REMINDER SLIP</h4>
                </div>
                
                <div class="student-info">
                    <p><strong>Student Name:</strong> <?php echo $student['name']; ?></p>
                    <p><strong>Father Name:</strong> <?php echo $student['father_name']; ?></p>
                    <p><strong>Student ID:</strong> <?php echo $student['id']; ?></p>
                    <p><strong>Class:</strong> <?php echo $display_data['class_title']; ?></p>
                    <p><strong>Session:</strong> <?php echo $display_data['session_title']; ?></p>
                    <p><strong>Mobile:</strong> <?php echo $student['mobile'] ?: 'N/A'; ?></p>
                    <p><strong>Date:</strong> <?php echo date('d-m-Y'); ?></p>
                </div>
                
                <?php if ($student['paid_until_display']): ?>
                <div class="paid-message">
                    ✓ Fees paid until <?php echo $student['paid_until_display']; ?>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($student['pending_fees']) || $student['old_dues_amount'] > 0): ?>
                <div class="pending-fees">
                    <h4 style="color: #dc3545; margin-bottom: 10px;">Pending Fees:</h4>
                    
                    <?php if (!empty($student['pending_fees'])): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Fee Type</th>
                                <th>Month</th>
                                <th>Amount (PKR)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($student['pending_fees'] as $fee): ?>
                            <tr>
                                <td><?php echo $fee['fee_type']; ?></td>
                                <td><?php echo $fee['month']; ?></td>
                                <td class="amount"><?php echo number_format($fee['amount']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                    
                    <?php if ($student['old_dues_amount'] > 0): ?>
                    <div class="old-dues">
                        <strong>Previous Session Dues:</strong> <?php echo number_format($student['old_dues_amount']); ?> PKR
                    </div>
                    <?php endif; ?>
                    
                    <div class="grand-total">
                        TOTAL DUE: <?php echo number_format($student['total_due']); ?> PKR
                    </div>
                </div>
                <?php else: ?>
                <div class="paid-message" style="background-color: #d4edda; color: #155724;">
                    ✓ No pending fees. All dues are cleared.
                </div>
                <?php endif; ?>
                
                <div class="footer-note">
                    <p>Please clear the dues at the earliest to avoid late fee charges.</p>
                    <p>For any query, contact the college office.</p>
                    <p style="margin-top: 15px;">_________________________<br>Accounts Officer Signature</p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <div class="bulk-print-buttons">
            <form method="post" id="bulkPrintForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="session_id" value="<?php echo $selected_session_id; ?>">
                <input type="hidden" name="class_id" value="<?php echo $selected_class_id; ?>">
                <input type="hidden" name="print_type" value="<?php echo $display_data['print_type']; ?>">
                
                <?php if ($display_data['print_type'] == 'feecards'): ?>
                <input type="hidden" name="action" value="print">
                <button type="submit" class="btn btn-success btn-lg">
                    <span class="glyphicon glyphicon-print"></span> Print All Reminders
                </button>
                
                <button type="submit" name="action" value="thermal_print" class="btn btn-warning btn-lg thermal-print-btn">
                    <span class="glyphicon glyphicon-print"></span> Thermal Print All
                </button>
                
                <?php elseif ($display_data['print_type'] == 'defaulter'): ?>
                <input type="hidden" name="action" value="print">
                <button type="submit" class="btn btn-success btn-lg">
                    <span class="glyphicon glyphicon-print"></span> Print All
                </button>
                <?php endif; ?>
                
                <button type="submit" name="action" value="whatsapp_bulk"
                        class="btn btn-lg whatsapp-btn"
                        onclick="return prepareWhatsAppAll();">
                    <span class="glyphicon glyphicon-earphone"></span> Send WhatsApp to All
                </button>
                
                <button type="button" onclick="window.location.href='<?php echo $_SERVER['PHP_SELF']; ?>'" class="btn btn-default btn-lg">
                    <span class="glyphicon glyphicon-refresh"></span> New Search
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>
    
    </div>

    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.4/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
    
    <script>
    $(document).ready(function() {
        <?php if ($display_data): ?>
        $('html, body').animate({
            scrollTop: $(".results-section").offset().top
        }, 1000);
        
        setTimeout(function() {
            initializeCheckboxFunctionality();
        }, 500);
        <?php endif; ?>
    });
    
    function initializeCheckboxFunctionality() {
        const studentCheckboxes = document.querySelectorAll('.student-checkbox');
        const selectAllBtn = document.getElementById('selectAllBtn');
        const deselectAllBtn = document.getElementById('deselectAllBtn');
        const selectAllTable = document.getElementById('selectAllTable');
        const selectedCount = document.getElementById('selectedCount');
        const printSelectedBtn = document.getElementById('printSelectedBtn');
        const thermalPrintSelectedBtn = document.getElementById('thermalPrintSelectedBtn');
        const whatsappSelectedBtn = document.getElementById('whatsappSelectedBtn');
        
        if (studentCheckboxes.length === 0) return;
        
        function updateSelection() {
            const selected = document.querySelectorAll('.student-checkbox:checked');
            const selectedCountValue = selected.length;
            
            if (selectedCount) {
                selectedCount.textContent = selectedCountValue + ' selected';
            }
            
            const hasSelection = selectedCountValue > 0;
            if (printSelectedBtn) printSelectedBtn.disabled = !hasSelection;
            if (thermalPrintSelectedBtn) thermalPrintSelectedBtn.disabled = !hasSelection;
            if (whatsappSelectedBtn) whatsappSelectedBtn.disabled = !hasSelection;
            
            if (selectAllTable) {
                selectAllTable.checked = selectedCountValue === studentCheckboxes.length && studentCheckboxes.length > 0;
            }
        }
        
        studentCheckboxes.forEach(function(checkbox) {
            checkbox.addEventListener('change', updateSelection);
        });
        
        if (selectAllBtn) {
            selectAllBtn.addEventListener('click', function() {
                studentCheckboxes.forEach(function(checkbox) { checkbox.checked = true; });
                updateSelection();
            });
        }
        
        if (deselectAllBtn) {
            deselectAllBtn.addEventListener('click', function() {
                studentCheckboxes.forEach(function(checkbox) { checkbox.checked = false; });
                updateSelection();
            });
        }
        
        if (selectAllTable) {
            selectAllTable.addEventListener('change', function() {
                studentCheckboxes.forEach(function(checkbox) {
                    checkbox.checked = this.checked;
                }, this);
                updateSelection();
            });
        }
        
        updateSelection();
        
        if (printSelectedBtn) {
            printSelectedBtn.addEventListener('click', function() {
                const selected = document.querySelectorAll('.student-checkbox:checked');
                if (selected.length === 0) {
                    alert('Please select at least one student to print.');
                    return false;
                }
                submitAction('print', selected);
            });
        }
        
        if (thermalPrintSelectedBtn) {
            thermalPrintSelectedBtn.addEventListener('click', function() {
                const selected = document.querySelectorAll('.student-checkbox:checked');
                if (selected.length === 0) {
                    alert('Please select at least one student to print.');
                    return false;
                }
                submitAction('thermal_print', selected);
            });
        }
        
        if (whatsappSelectedBtn) {
            whatsappSelectedBtn.addEventListener('click', function() {
                const selected = document.querySelectorAll('.student-checkbox:checked');
                if (selected.length === 0) {
                    alert('Please select at least one student.');
                    return false;
                }
                if (!confirm('📱 Send WhatsApp fee reminders to ' + selected.length + ' student(s)?')) {
                    return false;
                }
                whatsappSelectedBtn.disabled = true;
                whatsappSelectedBtn.innerHTML = '<span class="glyphicon glyphicon-time"></span> Sending...';
                submitAction('whatsapp_bulk', selected);
            });
        }
        
        function submitAction(actionValue, selectedCheckboxes) {
            const form = document.createElement('form');
            form.method = 'post';
            form.action = '';
            
            const addField = (name, value) => {
                const i = document.createElement('input');
                i.type = 'hidden';
                i.name = name;
                i.value = value;
                form.appendChild(i);
            };
            
            addField('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
            addField('session_id', '<?php echo $selected_session_id; ?>');
            addField('class_id', '<?php echo $selected_class_id; ?>');
            addField('print_type', '<?php echo $display_data['print_type']; ?>');
            addField('action', actionValue);
            
            selectedCheckboxes.forEach(function(cb) {
                addField('selected_students[]', cb.value);
            });
            
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    function prepareWhatsAppAll() {
        const allCheckboxes = document.querySelectorAll('.student-checkbox');
        const total = allCheckboxes.length;
        
        if (total === 0) {
            alert('No students to send to.');
            return false;
        }
        
        if (!confirm('📱 Send WhatsApp fee reminders to ALL ' + total + ' student(s)?\n\nThis may take ' + Math.ceil(total * 0.5) + ' seconds.')) {
            return false;
        }
        
        document.querySelectorAll('.wa-bulk-input').forEach(el => el.remove());
        
        const form = document.getElementById('bulkPrintForm');
        allCheckboxes.forEach(cb => {
            const i = document.createElement('input');
            i.type = 'hidden';
            i.name = 'selected_students[]';
            i.value = cb.value;
            i.className = 'wa-bulk-input';
            form.appendChild(i);
        });
        
        return true;
    }
    </script>
</body>
</html>
<?php
$conn->close();
?>