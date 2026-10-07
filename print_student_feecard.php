<?php
// Check if session is already started


if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
require_once('security.php');
require_once('conn_inc.php');

// Get student ID from URL
$student_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Language handling
$lang = 'en';

// Language strings
$translations = [
    'en' => [
        'title' => 'Print Fee Card',
        'student_name' => 'Student Name',
        'father_name' => 'Father Name',
        'total_due' => 'Total Due',
        'fee_type' => 'Fee Type',
        'month' => 'Month',
        'fee_card' => 'Fee Card',
        'class' => 'Class',
        'session' => 'Session',
        'grand_total' => 'Grand Total',
        'student_copy' => 'Student Copy',
        'office_copy' => 'Office Copy',
        'student_id' => 'Student ID',
        'old_dues' => 'Old Dues',
        'total_fees' => 'Total Fees',
        'current_fees' => 'Current Total',
        'previous_dues' => 'Previous Dues',
        'generated_date' => 'Generated Date',
        'mobile' => 'Mobile',
        'issue_date' => 'Issue Date',
        'total_monthly' => 'Total Monthly',
        'old_session' => 'Previous Session Dues',
        'current_session' => 'Current Session',
        'all_fees_summary' => 'All Fees Summary',
        'session_fees' => 'Session Fees',
        'print' => 'Print'
    ]
];

// Initialize variables
$student = [];
$current_class = [];
$all_sessions_fees = [];
$old_sessions_fees = [];
$current_session_fees = [];
$old_dues_amount = 0;
$error = '';

// Get student information
if ($student_id > 0) {
    try {
        // Get basic student info including old dues
        $stmt = $conn->prepare("SELECT id, name, father_name, mobile, is_old_dues, old_dues_amount FROM student_registration WHERE id = ?");
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $student = $result->fetch_assoc();
            $old_dues_amount = $student['is_old_dues'] == 1 ? floatval($student['old_dues_amount']) : 0;
            
            // Get ALL sessions for this student
            $stmt = $conn->prepare("SELECT DISTINCT sc.session_id, sc.class_id, sc.id AS student_class_id,
                                   s.title AS session_title, s.from_dated, s.to_dated,
                                   c.title AS class_title
                                   FROM student_class sc
                                   JOIN sessions s ON sc.session_id = s.id
                                   JOIN classes c ON sc.class_id = c.id
                                   WHERE sc.student_registration_id = ?
                                   ORDER BY s.from_dated DESC");
            $stmt->bind_param("i", $student_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $sessions = [];
            $current_session_id = 0;
            $current_class_id = 0;
            $session_counter = 0;
            
            while ($session_row = $result->fetch_assoc()) {
                $sessions[] = $session_row;
                // Set the first (latest) session as current session
                if ($current_session_id == 0) {
                    $current_session_id = $session_row['session_id'];
                    $current_class_id = $session_row['class_id'];
                }
                $session_counter++;
            }
            
            if (!empty($sessions)) {
                // Process each session
                foreach ($sessions as $session_data) {
                    $session_id = $session_data['session_id'];
                    $class_id = $session_data['class_id'];
                    $student_class_id = $session_data['student_class_id'];
                    
                    // Generate months array for this specific session
                    $session_months = [];
                    $session_from_date = new DateTime($session_data['from_dated']);
                    $session_to_date = new DateTime($session_data['to_dated']);
                    $session_to_date_modified = (clone $session_to_date)->modify('+1 month');
                    $session_interval = new DateInterval('P1M');
                    $session_period = new DatePeriod($session_from_date, $session_interval, $session_to_date_modified);

                    foreach ($session_period as $date) {
                        $month_key = $date->format('m');
                        $month_name = $date->format('M');
                        $session_months[$month_key] = $month_name;
                    }
                    ksort($session_months);
                    
                    // Get fee types for this student in this session
                    $session_fee_types_query = "SELECT DISTINCT ft.id, ft.title, ft.type, cft.amount
                                                FROM fee_types ft
                                                JOIN class_fee_types cft ON ft.id = cft.fee_type_id
                                                WHERE cft.session_id = ? 
                                                AND cft.class_id = ?
                                                AND cft.status = 0
                                                ORDER BY ft.title";
                    $session_fee_types_stmt = $conn->prepare($session_fee_types_query);
                    $session_fee_types_stmt->bind_param("ii", $session_id, $class_id);
                    $session_fee_types_stmt->execute();
                    $session_fee_types_result = $session_fee_types_stmt->get_result();
                    
                    $session_fee_types = [];
                    
                    while ($fee_type_row = $session_fee_types_result->fetch_assoc()) {
                        $fee_type_id = $fee_type_row['id'];
                        
                        $session_fee_types[$fee_type_id] = [
                            'title' => $fee_type_row['title'],
                            'type' => $fee_type_row['type'],
                            'amount' => $fee_type_row['amount'],
                            'months' => array_fill_keys(array_keys($session_months), null),
                            'total' => 0,
                            'paid_amount' => 0
                        ];
                    }
                    
                    // Get all fee cards for this student in this session
                    $session_fee_cards_query = "SELECT sfc.id, sfc.fee_type_id, sfc.total_amount, 
                                                       sfc.due_date, sfc.status, sfc.month
                                                FROM student_fee_card sfc
                                                WHERE sfc.student_class_id = ?
                                                ORDER BY sfc.due_date";
                    $session_fee_cards_stmt = $conn->prepare($session_fee_cards_query);
                    $session_fee_cards_stmt->bind_param("i", $student_class_id);
                    $session_fee_cards_stmt->execute();
                    $session_fee_cards_result = $session_fee_cards_stmt->get_result();
                    
                    $session_total = 0;
                    
                    while ($fee_card_row = $session_fee_cards_result->fetch_assoc()) {
                        $fee_type_id = $fee_card_row['fee_type_id'];
                        
                        // Check if this fee type exists in our structure
                        if (isset($session_fee_types[$fee_type_id])) {
                            
                            // Calculate total payments
                            $paid_query = "SELECT COALESCE(SUM(paid_amount), 0) AS total_paid 
                                           FROM student_fee_payments 
                                           WHERE fee_card_id = ?";
                            $paid_stmt = $conn->prepare($paid_query);
                            $paid_stmt->bind_param("i", $fee_card_row['id']);
                            $paid_stmt->execute();
                            $paid_result = $paid_stmt->get_result();
                            $paid_row = $paid_result->fetch_assoc();
                            
                            $total_paid = $paid_row['total_paid'];
                            $remaining = $fee_card_row['total_amount'] - $total_paid;
                            
                            // Generate month key from due date
                            $due_date = new DateTime($fee_card_row['due_date']);
                            $month_key = $due_date->format('m');
                            
                            // Determine status
                            $status = $fee_card_row['status'];
                            if ($remaining <= 0) {
                                $status = 'paid';
                            } elseif ($total_paid > 0 && $remaining < $fee_card_row['total_amount']) {
                                $status = 'partial';
                            } elseif ($remaining > 0) {
                                $status = 'pending';
                            }
                            
                            // Store fee information
                            $session_fee_types[$fee_type_id]['months'][$month_key] = [
                                'amount' => $remaining,
                                'status' => $status,
                                'total_amount' => $fee_card_row['total_amount'],
                                'paid_amount' => $total_paid,
                                'due_date' => $fee_card_row['due_date']
                            ];
                            
                            // Add to fee type total
                            $session_fee_types[$fee_type_id]['total'] += $remaining;
                            $session_fee_types[$fee_type_id]['paid_amount'] += $total_paid;
                            $session_total += $remaining;
                        }
                    }
                    
                    // Clean up: remove fee types with no data
                    foreach ($session_fee_types as $fee_type_id => $fee_data) {
                        $has_data = false;
                        foreach ($fee_data['months'] as $month_data) {
                            if ($month_data !== null) {
                                $has_data = true;
                                break;
                            }
                        }
                        
                        if (!$has_data) {
                            unset($session_fee_types[$fee_type_id]);
                        }
                    }
                    
                    // Only add session if it has fees
                    if (!empty($session_fee_types)) {
                        $session_info = [
                            'session_id' => $session_id,
                            'session_title' => $session_data['session_title'],
                            'from_dated' => $session_data['from_dated'],
                            'to_dated' => $session_data['to_dated'],
                            'class_title' => $session_data['class_title'],
                            'student_class_id' => $student_class_id,
                            'months' => $session_months,
                            'fee_types' => $session_fee_types,
                            'session_total' => $session_total,
                            'is_current' => ($session_id == $current_session_id)
                        ];
                        
                        if ($session_id == $current_session_id) {
                            $current_session_fees = $session_info;
                        } else {
                            $old_sessions_fees[] = $session_info;
                        }
                        
                        $all_sessions_fees[] = $session_info;
                    }
                }
                
                // Set current class info
                $current_class = [
                    'class_title' => $sessions[0]['class_title'],
                    'session_title' => $sessions[0]['session_title'],
                    'from_dated' => $sessions[0]['from_dated'],
                    'to_dated' => $sessions[0]['to_dated']
                ];
                
            } else {
                $error = "Student is not enrolled in any session.";
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

// Calculate total from all sessions
$current_total = 0;
foreach ($all_sessions_fees as $session_fees) {
    $current_total += $session_fees['session_total'];
}

// Calculate total due including old dues
$total_due = $current_total + $old_dues_amount;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo $translations[$lang]['title']; ?> - <?php echo htmlspecialchars($student['name'] ?? ''); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: 'Arial Narrow', Arial, sans-serif;
            font-size: 11px;
            line-height: 1.2;
            margin: 0;
            padding: 0;
        }
        .container {
            width: 100%;
            padding: 0;
            margin: 0;
        }
        .print-section {
            width: 100%;
        }
        .fee-card {
            border: none;
            box-shadow: none;
            padding: 3px;
            margin: 0;
            background-color: #fff;
            width: 100%;
            height: auto;
            page-break-inside: avoid;
            font-size: 11px;
            font-family: 'Arial Narrow', Arial, sans-serif;
        }
        .fee-card-group {
            display: block;
            page-break-after: auto;
            margin-bottom: 10px;
            padding: 2px;
            min-height: 45%;
        }
        .fee-card-header {
            border-bottom: 1px solid #ccc;
            padding-bottom: 2px;
            margin-bottom: 4px;
        }
        .fee-details table {
            margin: 0;
            font-size: 10px;
            width: 100%;
            font-family: 'Arial Narrow', Arial, sans-serif;
            border-collapse: collapse;
        }
        .fee-details th,
        .fee-details td {
            padding: 1px 2px;
            border: 1px solid #ddd;
            white-space: nowrap;
        }
        .fee-details th {
            background-color: #f5f5f5;
            font-weight: 600;
        }
        .fee-details th.month,
        .fee-details td.month {
            min-width: 25px;
            text-align: center;
        }
        .fee-details td.total {
            font-weight: 600;
        }
        .total-row {
            font-weight: 600;
            background-color: #e8ecef;
        }
        .old-dues-row {
            font-weight: 600;
            background-color: #fff3cd;
            color: #856404;
        }
        .total-monthly-row {
            font-weight: 600;
            background-color: #e3f2fd;
            color: #1565c0;
        }
        .session-header {
            font-weight: bold;
            background-color: #f0f0f0;
            padding: 3px 5px;
            border-left: 4px solid #007bff;
            margin: 8px 0 4px 0;
            font-size: 11px;
        }
        .old-session-header {
            background-color: #fff3cd;
            border-left-color: #ffc107;
        }
        .current-session-header {
            background-color: #e3f2fd;
            border-left-color: #007bff;
        }
        .rep_header {
            text-align: center;
            margin-bottom: 5px;
            padding: 2px;
            border-bottom: 2px solid #000;
            font-weight: bold;
            font-size: 14px;
            position: relative;
            font-family: 'Arial Narrow', Arial, sans-serif;
        }
        .rep_header .title {
            font-size: 16px;
            margin-bottom: 2px;
            font-weight: bold;
        }
        .rep_header .contact-info {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 2px;
            font-size: 10px;
        }
        .rep_header .registration-info {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 8px;
            font-size: 10px;
        }
        .rep_header img {
            height: 50px;
            position: absolute;
            left: 10px;
            top: 5px;
        }
        .copy-label {
            text-align: center;
            font-weight: bold;
            margin: 3px 0;
            border-top: 1px dashed #000;
            border-bottom: 1px dashed #000;
            padding: 2px 0;
            font-size: 11px;
        }
        .student-info {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            padding: 3px 0;
            margin-bottom: 4px;
        }
        .student-info-left, .student-info-right {
            flex: 1;
            min-width: 45%;
        }
        .student-info span {
            display: block;
            margin-bottom: 1px;
        }
        .paid-tick {
            color: #28a745;
            font-weight: bold;
            font-size: 10px;
            display: block;
            margin-bottom: 1px;
        }
        .partial-info {
            color: #ffc107;
            font-size: 9px;
            display: block;
            margin-bottom: 1px;
            font-weight: bold;
        }
        .partial-details {
            color: #6c757d;
            font-size: 8px;
            display: block;
            margin-top: 1px;
        }
        .pending-amount {
            color: #dc3545;
            font-weight: bold;
            font-size: 10px;
        }
        .assigned-amount {
            color: #000;
            font-weight: bold;
            font-size: 10px;
        }
        .old-dues-amount {
            color: #ff6b00;
            font-weight: bold;
            font-size: 10px;
        }
        .receipt-section {
            margin-top: 10px;
            border-top: 1px solid #000;
            padding-top: 5px;
            font-size: 10px;
        }
        .receipt-left {
            float: left;
            width: 50%;
        }
        .receipt-right {
            float: right;
            width: 50%;
            text-align: right;
        }
        .clear {
            clear: both;
        }
        .no-fee {
            color: #999;
            font-size: 8px;
        }
        .partial-wrapper {
            text-align: center;
        }
        .summary-section {
            margin-top: 10px;
            border-top: 1px solid #000;
            padding-top: 5px;
        }
        
        /* Print styles for fitting 2 fee cards per page */
        @media print {
            @page {
                size: A4 portrait;
                margin: 5mm;
            }
            body {
                margin: 5mm;
                padding: 0;
                font-size: 10px;
            }
            .fee-card {
                width: 100%;
                margin: 0;
                padding: 2px;
                height: auto;
                page-break-inside: avoid;
            }
            .fee-card-group {
                page-break-inside: avoid;
                page-break-after: auto;
                margin: 0;
                padding: 2px;
                min-height: 45%;
            }
            .rep_header {
                margin-bottom: 3px;
                padding: 1px;
            }
            .rep_header .title {
                font-size: 15px;
            }
            .rep_header img {
                height: 45px;
                left: 5px;
            }
            .student-info {
                padding: 2px 0;
                margin-bottom: 3px;
            }
            .fee-details table {
                font-size: 9px;
            }
            .fee-details th,
            .fee-details td {
                padding: 0px 1px;
            }
            /* Force 2 fee cards per page */
            .fee-card-group:nth-child(2n) {
                page-break-after: always;
            }
            .fee-card-group:last-child {
                page-break-after: auto;
            }
        }
    </style>
    <script>
        window.onload = function() {
            setTimeout(function() {
                window.print();
                
                window.addEventListener('afterprint', function() {
                    setTimeout(function() {
                        window.location.href = 'fee_collection.php?id=<?php echo $student_id; ?>';
                    }, 100);
                });
                
                setTimeout(function() {
                    if (!window.matchMedia('print').matches) {
                        window.location.href = 'fee_collection.php?id=<?php echo $student_id; ?>';
                    }
                }, 2000);
            }, 500);
        };
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                window.location.href = 'fee_collection.php?id=<?php echo $student_id; ?>';
            }
        });
    </script>
</head>
<body>
    <div class="container">
        <?php if ($error): ?>
            <div style="text-align: center; padding: 50px; color: red;">
                <h3>Error</h3>
                <p><?php echo htmlspecialchars($error); ?></p>
                <button onclick="window.location.href='fee_collection.php?id=<?php echo $student_id; ?>'">Back to Fee Collection</button>
            </div>
        <?php else: ?>
            <!-- Student and Office Copies -->
            <div class="fee-card-group">
                <!-- Student Copy -->
                <div class="fee-card student-copy">
                    <div class="rep_header">
                        <img src="logo2.png" />
                        <div class="title">QIMS COLLEGE KHWAZA KHELA SWAT</div>
                        <div class="contact-info">
                            <span>Phone: 0346-9401982</span>
                            <span>Email: info@qimscollege.edu.pk</span>
                        </div>
                        <div class="registration-info">
                            <span><?php echo $translations[$lang]['fee_card']; ?></span>
                        </div>
                    </div>
                    
                    <div class="copy-label"><?php echo $translations[$lang]['student_copy']; ?></div>
                    
                    <div class="fee-card-header">
                        <div style="font-size: 14px; font-weight: bold; text-align: center;">
                            Fee Card
                        </div>
                        <div class="student-info">
                            <div class="student-info-left">
                                <span><strong><?php echo $translations[$lang]['student_id']; ?>:</strong> <?php echo $student['id']; ?></span>
                                <span><strong><?php echo $translations[$lang]['student_name']; ?>:</strong> <?php echo htmlspecialchars($student['name']); ?></span>
                                <span><strong><?php echo $translations[$lang]['father_name']; ?>:</strong> <?php echo htmlspecialchars($student['father_name']); ?></span>
                                <span><strong><?php echo $translations[$lang]['class']; ?>:</strong> <?php echo htmlspecialchars($current_class['class_title'] ?? ''); ?></span>
                                <span><strong><?php echo $translations[$lang]['mobile']; ?>:</strong> <?php echo $student['mobile'] ?: 'N/A'; ?></span>
                            </div>
                            <div class="student-info-right">
                                <span><strong><?php echo $translations[$lang]['session']; ?>:</strong> <?php echo htmlspecialchars($current_class['session_title'] ?? ''); ?></span>
                                <span><strong>Student Class ID:</strong> <?php echo $all_sessions_fees[0]['student_class_id'] ?? ''; ?></span>
                                <span><strong><?php echo $translations[$lang]['issue_date']; ?>:</strong> <?php echo date('d-m-Y'); ?></span>
                                <?php if ($old_dues_amount > 0): ?>
                                <span><strong style="color: #ff6b00;"><?php echo $translations[$lang]['old_dues']; ?>:</strong> <span class="old-dues-amount"><?php echo number_format($old_dues_amount); ?> PKR</span></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <?php 
                    $total_all_sessions = 0;
                    $has_any_fees = false;
                    
                    // Display OLD sessions first
                    foreach ($old_sessions_fees as $session_fees): 
                        $session_total = 0;
                        $session_has_fees = false;
                        
                        // Check if this session has any fees
                        foreach ($session_fees['fee_types'] as $fee_type) {
                            if ($fee_type['total'] > 0) {
                                $session_has_fees = true;
                                $has_any_fees = true;
                                break;
                            }
                        }
                        
                        if (!$session_has_fees) continue;
                        
                        $session_label = $translations[$lang]['old_session'] . ' (' . $session_fees['session_title'] . ')';
                    ?>
                    
                    <div class="session-header old-session-header">
                        <?php echo $session_label; ?>
                    </div>
                    
                    <div class="fee-details">
                        <table>
                            <thead>
                                <tr>
                                    <th><?php echo $translations[$lang]['fee_type']; ?></th>
                                    <?php foreach ($session_fees['months'] as $month_key => $month_name): ?>
                                    <th class="month"><?php echo $month_name; ?></th>
                                    <?php endforeach; ?>
                                    <th><?php echo $translations[$lang]['current_fees']; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($session_fees['fee_types'] as $fee_type): ?>
                                <?php if ($fee_type['total'] > 0): ?>
                                <?php 
                                $fee_type_total = 0;
                                $fee_type_paid = 0;
                                ?>
                                <tr class="old-session-row">
                                    <td><?php echo htmlspecialchars($fee_type['title']); ?></td>
                                    <?php foreach ($session_fees['months'] as $month_key => $month_name): ?>
                                    <td class="month">
                                        <?php 
                                        if (isset($fee_type['months'][$month_key]) && $fee_type['months'][$month_key] !== null) {
                                            $card = $fee_type['months'][$month_key];
                                            $fee_type_total += $card['amount'];
                                            $fee_type_paid += $card['paid_amount'];
                                            
                                            if ($card['status'] == 'paid'):
                                                echo '<span class="paid-tick">✓</span>';
                                            elseif ($card['amount'] > 0):
                                                echo '<span class="assigned-amount">' . number_format($card['total_amount']) . '</span>';
                                            endif;
                                        }
                                        ?>
                                    </td>
                                    <?php endforeach; ?>
                                    <td class="total"><?php echo number_format($fee_type['total']); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php endforeach; ?>
                                
                                <!-- Session Total Monthly Row -->
                                <?php 
                                $session_monthly_totals = [];
                                $session_monthly_paid = [];
                                $session_monthly_remaining = [];
                                
                                foreach ($session_fees['months'] as $month_key => $month_name) {
                                    $month_total = 0;
                                    $month_paid = 0;
                                    $month_remaining = 0;
                                    
                                    foreach ($session_fees['fee_types'] as $fee_type) {
                                        if (isset($fee_type['months'][$month_key]) && $fee_type['months'][$month_key] !== null) {
                                            $card = $fee_type['months'][$month_key];
                                            $month_total += $card['total_amount'];
                                            $month_paid += $card['paid_amount'];
                                            $month_remaining += $card['amount'];
                                        }
                                    }
                                    
                                    $session_monthly_totals[$month_key] = $month_total;
                                    $session_monthly_paid[$month_key] = $month_paid;
                                    $session_monthly_remaining[$month_key] = $month_remaining;
                                    $session_total += $month_remaining;
                                }
                                $total_all_sessions += $session_total;
                                ?>
                                
                                <tr class="total-monthly-row">
                                    <td><strong><?php echo $translations[$lang]['total_monthly']; ?></strong></td>
                                    <?php foreach ($session_fees['months'] as $month_key => $month_name): ?>
                                    <td class="month">
                                        <?php 
                                        $month_total = $session_monthly_totals[$month_key] ?? 0;
                                        $month_paid = $session_monthly_paid[$month_key] ?? 0;
                                        $month_remaining = $session_monthly_remaining[$month_key] ?? 0;
                                        
                                        if ($month_total > 0):
                                            if ($month_remaining <= 0):
                                                // Fully paid - show checkmark and full amount
                                                echo '<span class="paid-tick">✓</span>';
                                                echo '<span class="partial-details">' . number_format($month_total) . '</span>';
                                            elseif ($month_paid > 0 && $month_remaining > 0):
                                                // Partial payment - show full amount with paid details
                                                echo '<div class="partial-wrapper">';
                                                echo '<span class="assigned-amount">' . number_format($month_total) . '</span>';
                                                echo '<span class="partial-details">(' . number_format($month_paid) . '/' . number_format($month_total) . ')</span>';
                                                echo '</div>';
                                            else:
                                                // Unpaid - show full amount
                                                echo '<span class="assigned-amount">' . number_format($month_total) . '</span>';
                                            endif;
                                        endif;
                                        ?>
                                    </td>
                                    <?php endforeach; ?>
                                    <td class="total"><strong><?php echo number_format($session_total); ?></strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php endforeach; ?>
                    
                    <!-- Display CURRENT session -->
                    <?php if (!empty($current_session_fees)): 
                        $session_total = 0;
                        $session_has_fees = false;
                        
                        // Check if current session has any fees
                        foreach ($current_session_fees['fee_types'] as $fee_type) {
                            if ($fee_type['total'] > 0) {
                                $session_has_fees = true;
                                $has_any_fees = true;
                                break;
                            }
                        }
                        
                        if ($session_has_fees):
                            $session_label = $translations[$lang]['current_session'] . ' (' . $current_session_fees['session_title'] . ')';
                    ?>
                    
                    <div class="session-header current-session-header">
                        <?php echo $session_label; ?>
                    </div>
                    
                    <div class="fee-details">
                        <table>
                            <thead>
                                <tr>
                                    <th><?php echo $translations[$lang]['fee_type']; ?></th>
                                    <?php foreach ($current_session_fees['months'] as $month_key => $month_name): ?>
                                    <th class="month"><?php echo $month_name; ?></th>
                                    <?php endforeach; ?>
                                    <th><?php echo $translations[$lang]['current_fees']; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($current_session_fees['fee_types'] as $fee_type): ?>
                                <?php if ($fee_type['total'] > 0): ?>
                                <?php 
                                $fee_type_total = 0;
                                $fee_type_paid = 0;
                                ?>
                                <tr class="current-session-row">
                                    <td><?php echo htmlspecialchars($fee_type['title']); ?></td>
                                    <?php foreach ($current_session_fees['months'] as $month_key => $month_name): ?>
                                    <td class="month">
                                        <?php 
                                        if (isset($fee_type['months'][$month_key]) && $fee_type['months'][$month_key] !== null) {
                                            $card = $fee_type['months'][$month_key];
                                            $fee_type_total += $card['amount'];
                                            $fee_type_paid += $card['paid_amount'];
                                            
                                            if ($card['status'] == 'paid'):
                                                echo '<span class="paid-tick">✓</span>';
                                            elseif ($card['amount'] > 0):
                                                echo '<span class="assigned-amount">' . number_format($card['total_amount']) . '</span>';
                                            endif;
                                        }
                                        ?>
                                    </td>
                                    <?php endforeach; ?>
                                    <td class="total"><?php echo number_format($fee_type['total']); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php endforeach; ?>
                                
                                <!-- Session Total Monthly Row -->
                                <?php 
                                $session_monthly_totals = [];
                                $session_monthly_paid = [];
                                $session_monthly_remaining = [];
                                
                                foreach ($current_session_fees['months'] as $month_key => $month_name) {
                                    $month_total = 0;
                                    $month_paid = 0;
                                    $month_remaining = 0;
                                    
                                    foreach ($current_session_fees['fee_types'] as $fee_type) {
                                        if (isset($fee_type['months'][$month_key]) && $fee_type['months'][$month_key] !== null) {
                                            $card = $fee_type['months'][$month_key];
                                            $month_total += $card['total_amount'];
                                            $month_paid += $card['paid_amount'];
                                            $month_remaining += $card['amount'];
                                        }
                                    }
                                    
                                    $session_monthly_totals[$month_key] = $month_total;
                                    $session_monthly_paid[$month_key] = $month_paid;
                                    $session_monthly_remaining[$month_key] = $month_remaining;
                                    $session_total += $month_remaining;
                                }
                                $total_all_sessions += $session_total;
                                ?>
                                
                                <tr class="total-monthly-row">
                                    <td><strong><?php echo $translations[$lang]['total_monthly']; ?></strong></td>
                                    <?php foreach ($current_session_fees['months'] as $month_key => $month_name): ?>
                                    <td class="month">
                                        <?php 
                                        $month_total = $session_monthly_totals[$month_key] ?? 0;
                                        $month_paid = $session_monthly_paid[$month_key] ?? 0;
                                        $month_remaining = $session_monthly_remaining[$month_key] ?? 0;
                                        
                                        if ($month_total > 0):
                                            if ($month_remaining <= 0):
                                                // Fully paid - show checkmark and full amount
                                                echo '<span class="paid-tick">✓</span>';
                                                echo '<span class="partial-details">' . number_format($month_total) . '</span>';
                                            elseif ($month_paid > 0 && $month_remaining > 0):
                                                // Partial payment - show full amount with paid details
                                                echo '<div class="partial-wrapper">';
                                                echo '<span class="assigned-amount">' . number_format($month_total) . '</span>';
                                                echo '<span class="partial-details">(' . number_format($month_paid) . '/' . number_format($month_total) . ')</span>';
                                                echo '</div>';
                                            else:
                                                // Unpaid - show full amount
                                                echo '<span class="assigned-amount">' . number_format($month_total) . '</span>';
                                            endif;
                                        endif;
                                        ?>
                                    </td>
                                    <?php endforeach; ?>
                                    <td class="total"><strong><?php echo number_format($session_total); ?></strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php endif; endif; ?>
                    
                    <?php if (!$has_any_fees): ?>
                    <div style="text-align: center; padding: 20px; color: #999;">
                        No fee data available for this student.
                    </div>
                    <?php endif; ?>
                    
                    <!-- Summary Section -->
                    <?php if ($has_any_fees): ?>
                    <div class="summary-section">
                        <div class="fee-details">
                            <table>
                                <tbody>
                                    <tr class="total-monthly-row">
                                        <td colspan="2" style="text-align: left;">
                                            <strong><?php echo $translations[$lang]['all_fees_summary']; ?></strong>
                                        </td>
                                        <td colspan="<?php echo (isset($current_session_fees['months']) ? count($current_session_fees['months']) : 12) - 1; ?>" style="text-align: center;">
                                            Total of all sessions
                                        </td>
                                        <td class="total"><strong><?php echo number_format($current_total); ?></strong></td>
                                    </tr>
                                    
                                    <?php if ($old_dues_amount > 0): ?>
                                    <tr class="old-dues-row">
                                        <td colspan="<?php echo (isset($current_session_fees['months']) ? count($current_session_fees['months']) : 12) + 1; ?>">
                                            <?php echo $translations[$lang]['previous_dues']; ?>
                                        </td>
                                        <td class="total"><?php echo number_format($old_dues_amount); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    
                                    <tr class="total-row">
                                        <td colspan="<?php echo (isset($current_session_fees['months']) ? count($current_session_fees['months']) : 12) + 1; ?>">
                                            <?php echo $translations[$lang]['total_fees']; ?>
                                        </td>
                                        <td class="total"><strong><?php echo number_format($total_due); ?></strong></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div style="margin-top: 8px; text-align: center; font-size: 9px;">
                        <p>Note: Please pay fees before due date to avoid late fee charges.</p>
                        <p>For any query, contact college office during office hours.</p>
                        <?php if ($old_dues_amount > 0): ?>
                        <p style="color: #ff6b00; font-weight: bold;">* Previous dues must be cleared along with current fees.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Office Copy -->
                <div class="fee-card office-copy">
                    <div class="copy-label"><?php echo $translations[$lang]['office_copy']; ?></div>
                    
                    <div class="fee-card-header">
                        <div style="font-size: 14px; font-weight: bold; text-align: center;">
                            Fee Card
                        </div>
                        <div class="student-info">
                            <div class="student-info-left">
                                <span><strong><?php echo $translations[$lang]['student_id']; ?>:</strong> <?php echo $student['id']; ?></span>
                                <span><strong><?php echo $translations[$lang]['student_name']; ?>:</strong> <?php echo htmlspecialchars($student['name']); ?></span>
                                <span><strong><?php echo $translations[$lang]['father_name']; ?>:</strong> <?php echo htmlspecialchars($student['father_name']); ?></span>
                                <span><strong><?php echo $translations[$lang]['class']; ?>:</strong> <?php echo htmlspecialchars($current_class['class_title'] ?? ''); ?></span>
                                <span><strong><?php echo $translations[$lang]['mobile']; ?>:</strong> <?php echo $student['mobile'] ?: 'N/A'; ?></span>
                            </div>
                            <div class="student-info-right">
                                <span><strong><?php echo $translations[$lang]['session']; ?>:</strong> <?php echo htmlspecialchars($current_class['session_title'] ?? ''); ?></span>
                                <span><strong>Student Class ID:</strong> <?php echo $all_sessions_fees[0]['student_class_id'] ?? ''; ?></span>
                                <span><strong><?php echo $translations[$lang]['issue_date']; ?>:</strong> <?php echo date('d-m-Y'); ?></span>
                                <?php if ($old_dues_amount > 0): ?>
                                <span><strong style="color: #ff6b00;"><?php echo $translations[$lang]['old_dues']; ?>:</strong> <span class="old-dues-amount"><?php echo number_format($old_dues_amount); ?> PKR</span></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <?php 
                    $total_all_sessions = 0;
                    $has_any_fees = false;
                    
                    // Display OLD sessions first - Office Copy
                    foreach ($old_sessions_fees as $session_fees): 
                        $session_total = 0;
                        $session_has_fees = false;
                        
                        // Check if this session has any fees
                        foreach ($session_fees['fee_types'] as $fee_type) {
                            if ($fee_type['total'] > 0) {
                                $session_has_fees = true;
                                $has_any_fees = true;
                                break;
                            }
                        }
                        
                        if (!$session_has_fees) continue;
                        
                        $session_label = $translations[$lang]['old_session'] . ' (' . $session_fees['session_title'] . ')';
                    ?>
                    
                    <div class="session-header old-session-header">
                        <?php echo $session_label; ?>
                    </div>
                    
                    <div class="fee-details">
                        <table>
                            <thead>
                                <tr>
                                    <th><?php echo $translations[$lang]['fee_type']; ?></th>
                                    <?php foreach ($session_fees['months'] as $month_key => $month_name): ?>
                                    <th class="month"><?php echo $month_name; ?></th>
                                    <?php endforeach; ?>
                                    <th><?php echo $translations[$lang]['current_fees']; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($session_fees['fee_types'] as $fee_type): ?>
                                <?php if ($fee_type['total'] > 0): ?>
                                <?php 
                                $fee_type_total = 0;
                                $fee_type_paid = 0;
                                ?>
                                <tr class="old-session-row">
                                    <td><?php echo htmlspecialchars($fee_type['title']); ?></td>
                                    <?php foreach ($session_fees['months'] as $month_key => $month_name): ?>
                                    <td class="month">
                                        <?php 
                                        if (isset($fee_type['months'][$month_key]) && $fee_type['months'][$month_key] !== null) {
                                            $card = $fee_type['months'][$month_key];
                                            $fee_type_total += $card['amount'];
                                            $fee_type_paid += $card['paid_amount'];
                                            
                                            if ($card['status'] == 'paid'):
                                                echo '<span class="paid-tick">✓</span>';
                                            elseif ($card['amount'] > 0):
                                                echo '<span class="assigned-amount">' . number_format($card['total_amount']) . '</span>';
                                            endif;
                                        }
                                        ?>
                                    </td>
                                    <?php endforeach; ?>
                                    <td class="total"><?php echo number_format($fee_type['total']); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php endforeach; ?>
                                
                                <!-- Session Total Monthly Row - Office Copy -->
                                <?php 
                                $session_monthly_totals = [];
                                $session_monthly_paid = [];
                                $session_monthly_remaining = [];
                                
                                foreach ($session_fees['months'] as $month_key => $month_name) {
                                    $month_total = 0;
                                    $month_paid = 0;
                                    $month_remaining = 0;
                                    
                                    foreach ($session_fees['fee_types'] as $fee_type) {
                                        if (isset($fee_type['months'][$month_key]) && $fee_type['months'][$month_key] !== null) {
                                            $card = $fee_type['months'][$month_key];
                                            $month_total += $card['total_amount'];
                                            $month_paid += $card['paid_amount'];
                                            $month_remaining += $card['amount'];
                                        }
                                    }
                                    
                                    $session_monthly_totals[$month_key] = $month_total;
                                    $session_monthly_paid[$month_key] = $month_paid;
                                    $session_monthly_remaining[$month_key] = $month_remaining;
                                    $session_total += $month_remaining;
                                }
                                $total_all_sessions += $session_total;
                                ?>
                                
                                <tr class="total-monthly-row">
                                    <td><strong><?php echo $translations[$lang]['total_monthly']; ?></strong></td>
                                    <?php foreach ($session_fees['months'] as $month_key => $month_name): ?>
                                    <td class="month">
                                        <?php 
                                        $month_total = $session_monthly_totals[$month_key] ?? 0;
                                        $month_paid = $session_monthly_paid[$month_key] ?? 0;
                                        $month_remaining = $session_monthly_remaining[$month_key] ?? 0;
                                        
                                        if ($month_total > 0):
                                            if ($month_remaining <= 0):
                                                // Fully paid - show checkmark and full amount
                                                echo '<span class="paid-tick">✓</span>';
                                                echo '<span class="partial-details">' . number_format($month_total) . '</span>';
                                            elseif ($month_paid > 0 && $month_remaining > 0):
                                                // Partial payment - show full amount with paid details
                                                echo '<div class="partial-wrapper">';
                                                echo '<span class="assigned-amount">' . number_format($month_total) . '</span>';
                                                echo '<span class="partial-details">(' . number_format($month_paid) . '/' . number_format($month_total) . ')</span>';
                                                echo '</div>';
                                            else:
                                                // Unpaid - show full amount
                                                echo '<span class="assigned-amount">' . number_format($month_total) . '</span>';
                                            endif;
                                        endif;
                                        ?>
                                    </td>
                                    <?php endforeach; ?>
                                    <td class="total"><strong><?php echo number_format($session_total); ?></strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php endforeach; ?>
                    
                    <!-- Display CURRENT session - Office Copy -->
                    <?php if (!empty($current_session_fees)): 
                        $session_total = 0;
                        $session_has_fees = false;
                        
                        // Check if current session has any fees
                        foreach ($current_session_fees['fee_types'] as $fee_type) {
                            if ($fee_type['total'] > 0) {
                                $session_has_fees = true;
                                $has_any_fees = true;
                                break;
                            }
                        }
                        
                        if ($session_has_fees):
                            $session_label = $translations[$lang]['current_session'] . ' (' . $current_session_fees['session_title'] . ')';
                    ?>
                    
                    <div class="session-header current-session-header">
                        <?php echo $session_label; ?>
                    </div>
                    
                    <div class="fee-details">
                        <table>
                            <thead>
                                <tr>
                                    <th><?php echo $translations[$lang]['fee_type']; ?></th>
                                    <?php foreach ($current_session_fees['months'] as $month_key => $month_name): ?>
                                    <th class="month"><?php echo $month_name; ?></th>
                                    <?php endforeach; ?>
                                    <th><?php echo $translations[$lang]['current_fees']; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($current_session_fees['fee_types'] as $fee_type): ?>
                                <?php if ($fee_type['total'] > 0): ?>
                                <?php 
                                $fee_type_total = 0;
                                $fee_type_paid = 0;
                                ?>
                                <tr class="current-session-row">
                                    <td><?php echo htmlspecialchars($fee_type['title']); ?></td>
                                    <?php foreach ($current_session_fees['months'] as $month_key => $month_name): ?>
                                    <td class="month">
                                        <?php 
                                        if (isset($fee_type['months'][$month_key]) && $fee_type['months'][$month_key] !== null) {
                                            $card = $fee_type['months'][$month_key];
                                            $fee_type_total += $card['amount'];
                                            $fee_type_paid += $card['paid_amount'];
                                            
                                            if ($card['status'] == 'paid'):
                                                echo '<span class="paid-tick">✓</span>';
                                            elseif ($card['amount'] > 0):
                                                echo '<span class="assigned-amount">' . number_format($card['total_amount']) . '</span>';
                                            endif;
                                        }
                                        ?>
                                    </td>
                                    <?php endforeach; ?>
                                    <td class="total"><?php echo number_format($fee_type['total']); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php endforeach; ?>
                                
                                <!-- Session Total Monthly Row - Office Copy -->
                                <?php 
                                $session_monthly_totals = [];
                                $session_monthly_paid = [];
                                $session_monthly_remaining = [];
                                
                                foreach ($current_session_fees['months'] as $month_key => $month_name) {
                                    $month_total = 0;
                                    $month_paid = 0;
                                    $month_remaining = 0;
                                    
                                    foreach ($current_session_fees['fee_types'] as $fee_type) {
                                        if (isset($fee_type['months'][$month_key]) && $fee_type['months'][$month_key] !== null) {
                                            $card = $fee_type['months'][$month_key];
                                            $month_total += $card['total_amount'];
                                            $month_paid += $card['paid_amount'];
                                            $month_remaining += $card['amount'];
                                        }
                                    }
                                    
                                    $session_monthly_totals[$month_key] = $month_total;
                                    $session_monthly_paid[$month_key] = $month_paid;
                                    $session_monthly_remaining[$month_key] = $month_remaining;
                                    $session_total += $month_remaining;
                                }
                                $total_all_sessions += $session_total;
                                ?>
                                
                                <tr class="total-monthly-row">
                                    <td><strong><?php echo $translations[$lang]['total_monthly']; ?></strong></td>
                                    <?php foreach ($current_session_fees['months'] as $month_key => $month_name): ?>
                                    <td class="month">
                                        <?php 
                                        $month_total = $session_monthly_totals[$month_key] ?? 0;
                                        $month_paid = $session_monthly_paid[$month_key] ?? 0;
                                        $month_remaining = $session_monthly_remaining[$month_key] ?? 0;
                                        
                                        if ($month_total > 0):
                                            if ($month_remaining <= 0):
                                                // Fully paid - show checkmark and full amount
                                                echo '<span class="paid-tick">✓</span>';
                                                echo '<span class="partial-details">' . number_format($month_total) . '</span>';
                                            elseif ($month_paid > 0 && $month_remaining > 0):
                                                // Partial payment - show full amount with paid details
                                                echo '<div class="partial-wrapper">';
                                                echo '<span class="assigned-amount">' . number_format($month_total) . '</span>';
                                                echo '<span class="partial-details">(' . number_format($month_paid) . '/' . number_format($month_total) . ')</span>';
                                                echo '</div>';
                                            else:
                                                // Unpaid - show full amount
                                                echo '<span class="assigned-amount">' . number_format($month_total) . '</span>';
                                            endif;
                                        endif;
                                        ?>
                                    </td>
                                    <?php endforeach; ?>
                                    <td class="total"><strong><?php echo number_format($session_total); ?></strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php endif; endif; ?>
                    
                    <?php if (!$has_any_fees): ?>
                    <div style="text-align: center; padding: 20px; color: #999;">
                        No fee data available for this student.
                    </div>
                    <?php endif; ?>
                    
                    <!-- Summary Section - Office Copy -->
                    <?php if ($has_any_fees): ?>
                    <div class="summary-section">
                        <div class="fee-details">
                            <table>
                                <tbody>
                                    <tr class="total-monthly-row">
                                        <td colspan="2" style="text-align: left;">
                                            <strong><?php echo $translations[$lang]['all_fees_summary']; ?></strong>
                                        </td>
                                        <td colspan="<?php echo (isset($current_session_fees['months']) ? count($current_session_fees['months']) : 12) - 1; ?>" style="text-align: center;">
                                            Total of all sessions
                                        </td>
                                        <td class="total"><strong><?php echo number_format($current_total); ?></strong></td>
                                    </tr>
                                    
                                    <?php if ($old_dues_amount > 0): ?>
                                    <tr class="old-dues-row">
                                        <td colspan="<?php echo (isset($current_session_fees['months']) ? count($current_session_fees['months']) : 12) + 1; ?>">
                                            <?php echo $translations[$lang]['previous_dues']; ?>
                                        </td>
                                        <td class="total"><?php echo number_format($old_dues_amount); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    
                                    <tr class="total-row">
                                        <td colspan="<?php echo (isset($current_session_fees['months']) ? count($current_session_fees['months']) : 12) + 1; ?>">
                                            <?php echo $translations[$lang]['total_fees']; ?>
                                        </td>
                                        <td class="total"><strong><?php echo number_format($total_due); ?></strong></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="receipt-section">
                        <div class="receipt-left">
                            <p><strong>Received by:</strong> _________________</p>
                            <p><strong>Signature:</strong> _________________</p>
                        </div>
                        <div class="receipt-right">
                            <p><strong>Date:</strong> _________________</p>
                            <p><strong>Amount:</strong> _________________</p>
                        </div>
                        <div class="clear"></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
<?php
$conn->close();
?>