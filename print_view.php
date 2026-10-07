<?php
// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if print data exists in session
if (!isset($_SESSION['print_data'])) {
    header("Location: student_feecards.php");
    exit();
}

$print_data = $_SESSION['print_data'];

// Language strings
$translations = [
    'en' => [
        'select_option' => 'Select an option',
        'title' => 'Print Fee Cards',
        'select_session' => 'Select Session',
        'select_class' => 'Select Class',
        'print_defaulter' => 'Print Defaulter List',
        'print_feecards' => 'Print Fee Cards',
        'student_name' => 'Student Name',
        'father_name' => 'Father Name',
        'total_due' => 'Total Due',
        'fee_type' => 'Fee Type',
        'month' => 'Month',
        'no_students' => 'No students found for this session and class.',
        'defaulter_list' => 'Defaulter List',
        'fee_card' => 'Fee Card',
        'class' => 'Class',
        'session' => 'Session',
        'grand_total' => 'Grand Total',
        'student_copy' => 'Student Copy',
        'office_copy' => 'Office Copy',
        'invalid_csrf' => 'Invalid request!',
        'student_id' => 'Student ID',
        'select_values' => 'Select Session and Class',
        'old_dues' => 'Old Dues',
        'total_fees' => 'Total Fees',
        'current_fees' => 'Current Total',
        'previous_dues' => 'Previous Dues',
        'print' => 'Print',
        'generated_date' => 'Generated Date',
        'mobile' => 'Mobile',
        'issue_date' => 'Issue Date',
        'new_search' => 'New Search',
        'total_monthly' => 'Total Monthly',
        'old_session' => 'Previous Session Dues',
        'current_session' => 'Current Session',
        'all_fees_summary' => 'All Fees Summary',
        'session_fees' => 'Session Fees',
        'thermal_print' => 'Thermal Print',
        'thermal_print_view' => 'Thermal Print View'
    ]
];

$lang = 'en';
$print_type = $print_data['print_type'];
$session_title = $print_data['session_title'];
$class_title = $print_data['class_title'];
$months = isset($print_data['months']) ? $print_data['months'] : [];
$students = $print_data['students'];
$total_remaining = $print_data['total_remaining'];
$total_old_dues = $print_data['total_old_dues'];

// Get selected session ID from session data
$selected_session_id = isset($_SESSION['form_data']['session_id']) ? $_SESSION['form_data']['session_id'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo $translations[$lang]['title']; ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: 'Arial Narrow', Arial, sans-serif;
            font-size: 17px;
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
            font-size: 19px;
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
            font-size: 19px;
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
            font-size: 19px;
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
            font-size: 21px;
            position: relative;
            font-family: 'Arial Narrow', Arial, sans-serif;
        }
        .rep_header .title {
            font-size: 22px;
            margin-bottom: 2px;
            font-weight: bold;
        }
        .rep_header .contact-info {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 2px;
            font-size: 17px;
        }
        .rep_header .registration-info {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 8px;
            font-size: 17px;
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
            font-size: 17px;
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
            font-size: 17px;
            display: block;
            margin-bottom: 1px;
        }
        .partial-info {
            color: #ffc107;
            font-size: 15px;
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
            font-size: 17px;
        }
        .assigned-amount {
            color: #000;
            font-weight: bold;
            font-size: 15px;
        }
        .old-dues-amount {
            color: #ff6b00;
            font-weight: bold;
            font-size: 17px;
        }
        .receipt-section {
            margin-top: 10px;
            border-top: 1px solid #000;
            padding-top: 5px;
            font-size: 17px;
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
        .paid-message {
            background-color: #e8f5e9;
            color: #28a745;
            padding: 5px 10px;
            border-radius: 5px;
            margin-bottom: 10px;
            font-weight: bold;
            font-size: 16px;
            border-left: 4px solid #28a745;
            text-align: center;
        }
        
        /* For defaulter list */
        .defaulter-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 15px;
        }
        
        .defaulter-table th,
        .defaulter-table td {
            padding: 0.5mm;
            border: 0.3mm solid #000;
            text-align: center;
        }
        
        .defaulter-table th {
            background-color: #f5f5f5;
            font-weight: 600;
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
                font-size: 17px;
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
                font-size: 19px;
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
                font-size: 17px;
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
                        window.location.href = 'student_feecards.php';
                    }, 100);
                });
                
                setTimeout(function() {
                    if (!window.matchMedia('print').matches) {
                        window.location.href = 'student_feecards.php';
                    }
                }, 2000);
            }, 500);
        };
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                window.location.href = 'student_feecards.php';
            }
        });
    </script>
</head>
<body>
    <div class="container">
        <?php if ($print_type == 'defaulter'): ?>
        <!-- Defaulter List Print View -->
        <div style="width: 100%; padding: 10px;">
            <div class="rep_header">
                <img src="logo2.png" />
                <div class="title">DAR-E-ARQAM SCHOOLS</div>
                <div class="contact-info">
                     <span>Matta Swat</span>
                    <span>Ph: 0946-791555</span>
                   
                </div>
                <div class="registration-info">
                    <span><?php echo $translations[$lang]['defaulter_list']; ?></span>
                </div>
            </div>

            <div style="margin-bottom: 2mm; font-size: 9px;">
                <p><strong><?php echo $translations[$lang]['session']; ?>:</strong> <?php echo $session_title; ?></p>
                <p><strong><?php echo $translations[$lang]['class']; ?>:</strong> <?php echo $class_title; ?></p>
                <p><strong><?php echo $translations[$lang]['generated_date']; ?>:</strong> <?php echo date('d-m-Y'); ?></p>
                <p><strong><?php echo $translations[$lang]['previous_dues']; ?>:</strong> <?php echo number_format($total_old_dues); ?> PKR</p>
                <p><strong><?php echo $translations[$lang]['grand_total']; ?>:</strong> <?php echo number_format($total_remaining); ?> PKR</p>
            </div>

            <table class="defaulter-table">
                <thead>
                    <tr>
                        <th style="width: 8mm;">Sr#</th>
                        <th style="width: 15mm;"><?php echo $translations[$lang]['student_id']; ?></th>
                        <th style="width: 40mm;"><?php echo $translations[$lang]['student_name']; ?><br><?php echo $translations[$lang]['father_name']; ?></th>
                        <th style="width: 25mm;"><?php echo $translations[$lang]['fee_type']; ?></th>
                        <?php foreach ($months as $month_key => $month_name): ?>
                        <th style="width: 10mm;"><?php echo $month_name; ?></th>
                        <?php endforeach; ?>
                        <th style="width: 15mm;"><?php echo $translations[$lang]['old_dues']; ?></th>
                        <th style="width: 15mm;"><?php echo $translations[$lang]['total_due']; ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $counter = 1;
                    foreach ($students as $student): 
                        if ($student['total_due'] > 0): 
                    ?>
                    <tr>
                        <td><?php echo $counter++; ?></td>
                        <td><?php echo $student['id']; ?></td>
                        <td style="text-align: left; padding-left: 1mm;">
                            <?php echo $student['name']; ?><br>
                            <?php echo $student['father_name']; ?>
                        </td>
                        <td style="text-align: left; padding-left: 1mm;">
                            <?php 
                            if (isset($student['pending_fees']) && !empty($student['pending_fees'])) {
                                // New structure - show pending fees grouped by month
                                $grouped_fees = [];
                                foreach ($student['pending_fees'] as $fee) {
                                    $grouped_fees[$fee['month']][] = $fee;
                                }
                                foreach ($grouped_fees as $month_name => $fees) {
                                    echo '<div style="margin-bottom: 0.5mm; padding-left: 2mm; border-left: 2px solid #dc3545;">';
                                    echo '<strong>' . $month_name . '</strong><br>';
                                    foreach ($fees as $fee) {
                                        echo $fee['fee_type'] . ': ' . number_format($fee['amount']) . '<br>';
                                    }
                                    echo '</div>';
                                }
                            } else {
                                // Old structure
                                foreach ($student['all_fees'] as $session_fees):
                                    foreach ($session_fees['fee_types'] as $fee_type):
                                        if ($fee_type['total'] > 0):
                                            $session_label = ($session_fees['session_id'] == $selected_session_id) ? $translations[$lang]['current_session'] : $translations[$lang]['old_session'] . ' (' . $session_fees['session_title'] . ')';
                                            echo '<div style="margin-bottom: 0.5mm; padding-left: 2mm; border-left: 2px solid ' . ($session_fees['session_id'] == $selected_session_id ? '#007bff' : '#ffc107') . ';">';
                                            echo $fee_type['title'] . '<br>';
                                            echo '<small style="font-size: 6px;">' . $session_label . '</small>';
                                            echo '</div>';
                                        endif;
                                    endforeach;
                                endforeach;
                            }
                            ?>
                        </td>
                        <?php foreach ($months as $month_key => $month_name): ?>
                        <td>
                            <?php 
                            if (isset($student['pending_fees']) && !empty($student['pending_fees'])) {
                                // New structure - check if this month has pending fees
                                $month_total = 0;
                                foreach ($student['pending_fees'] as $fee) {
                                    if ($fee['month'] == $month_name) {
                                        echo number_format($fee['amount']) . '<br>';
                                        $month_total += $fee['amount'];
                                    }
                                }
                                if ($month_total > 0) {
                                    echo '<small style="font-size: 7px;">Total: ' . number_format($month_total) . '</small>';
                                } else {
                                    echo '-';
                                }
                            } else {
                                // Old structure
                                $month_total = 0;
                                foreach ($student['all_fees'] as $session_fees):
                                    if (isset($session_fees['months'][$month_key])):
                                        $fee = $session_fees['months'][$month_key];
                                        if ($fee['status'] == 'paid'):
                                            echo '<span class="paid-tick">✓</span><br>';
                                        elseif ($fee['amount'] > 0):
                                            echo number_format($fee['amount']) . '<br>';
                                            $month_total += $fee['amount'];
                                        endif;
                                    endif;
                                endforeach;
                                if ($month_total > 0):
                                    echo '<small style="font-size: 7px;">Total: ' . number_format($month_total) . '</small>';
                                endif;
                            }
                            ?>
                        </td>
                        <?php endforeach; ?>
                        <td class="old-dues-amount">
                            <?php echo ($student['old_dues_amount'] > 0) ? number_format($student['old_dues_amount']) : '-'; ?>
                        </td>
                        <td style="font-weight: bold;"><?php echo number_format($student['total_due']); ?></td>
                    </tr>
                    <?php 
                        endif;
                    endforeach; 
                    ?>
                    <tr style="font-weight: bold; background-color: #f0f0f0;">
                        <td colspan="4"><?php echo $translations[$lang]['grand_total']; ?></td>
                        <td colspan="<?php echo count($months); ?>">Total</td>
                        <td class="old-dues-amount"><?php echo number_format($total_old_dues); ?></td>
                        <td><?php echo number_format($total_remaining); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <?php elseif ($print_type == 'feecards'): ?>
        <!-- Fee Cards Print View - Updated for new structure -->
        <?php foreach ($students as $student): ?>
        <div class="fee-card-group">
            <!-- Student Copy -->
            <div class="fee-card student-copy">
                <div class="rep_header">
                    <img src="logo2.png" />
                    <div class="title">DAR-E-ARQAM SCHOOLS</div>
                    <div class="contact-info">
                         <span>Matta Swat</span>
                        <span>Ph: 0946-791555</span>
                       
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
                            <span><strong><?php echo $translations[$lang]['student_name']; ?>:</strong> <?php echo $student['name']; ?></span>
                            <span><strong><?php echo $translations[$lang]['father_name']; ?>:</strong> <?php echo $student['father_name']; ?></span>
                            <span><strong><?php echo $translations[$lang]['class']; ?>:</strong> <?php echo $class_title; ?></span>
                            <span><strong><?php echo $translations[$lang]['mobile']; ?>:</strong> <?php echo isset($student['mobile']) ? $student['mobile'] : 'N/A'; ?></span>
                        </div>
                        <div class="student-info-right">
                            <span><strong><?php echo $translations[$lang]['session']; ?>:</strong> <?php echo $session_title; ?></span>
                            <span><strong>Student Class ID:</strong> <?php echo isset($student['student_class_id']) ? $student['student_class_id'] : ''; ?></span>
                            <span><strong><?php echo $translations[$lang]['issue_date']; ?>:</strong> <?php echo date('d-m-Y'); ?></span>
                            <?php if (isset($student['old_dues_amount']) && $student['old_dues_amount'] > 0): ?>
                            <span><strong style="color: #ff6b00;"><?php echo $translations[$lang]['old_dues']; ?>:</strong> <span class="old-dues-amount"><?php echo number_format($student['old_dues_amount']); ?> PKR</span></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <?php 
                $has_any_fees = false;
                
                // Check if we have pending fees in new structure
                if (isset($student['pending_fees']) && !empty($student['pending_fees'])) {
                    $has_any_fees = true;
                    
                    // Show paid until message if available
                    if (isset($student['paid_until_display']) && $student['paid_until_display']) {
                        echo '<div class="paid-message">✓ Fees paid until ' . $student['paid_until_display'] . '</div>';
                    }
                    
                    // Group pending fees by month
                    $grouped_fees = [];
                    foreach ($student['pending_fees'] as $fee) {
                        $grouped_fees[$fee['month']][] = $fee;
                    }
                    
                    foreach ($grouped_fees as $month_name => $fees):
                        $month_total = 0;
                        foreach ($fees as $fee) {
                            $month_total += $fee['amount'];
                        }
                ?>
                
                <div class="session-header current-session-header">
                    <?php echo $month_name; ?> - Pending Fees
                </div>
                
                <div class="fee-details">
                    <table>
                        <thead>
                            <tr>
                                <th><?php echo $translations[$lang]['fee_type']; ?></th>
                                <th class="month">Amount (PKR)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fees as $fee): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($fee['fee_type']); ?></td>
                                <td class="month pending-amount"><?php echo number_format($fee['amount']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <tr class="total-monthly-row">
                                <td><strong><?php echo $translations[$lang]['total_monthly']; ?></strong></td>
                                <td class="month"><strong><?php echo number_format($month_total); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <?php 
                    endforeach;
                    
                    // Show old dues if any
                    if (isset($student['old_dues_amount']) && $student['old_dues_amount'] > 0) {
                        echo '<div class="old-dues-row" style="padding: 5px; margin: 5px 0; text-align: right;">';
                        echo '<strong>Previous Dues: </strong>' . number_format($student['old_dues_amount']) . ' PKR';
                        echo '</div>';
                    }
                    
                    // Show total due
                    echo '<div style="background-color: #007bff; color: white; padding: 8px; margin-top: 10px; border-radius: 5px; text-align: right; font-weight: bold; font-size: 18px;">';
                    echo 'TOTAL DUE: ' . number_format($student['total_due']) . ' PKR';
                    echo '</div>';
                    
                } else if (isset($student['all_fees']) && !empty($student['all_fees'])) {
                    // Old structure with all_fees
                    
                    // Display each session separately
                    foreach ($student['all_fees'] as $session_fees): 
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
                        
                        $is_current_session = (isset($session_fees['session_id']) && $session_fees['session_id'] == $selected_session_id);
                        $session_label = $is_current_session ? 
                            $translations[$lang]['current_session'] . ' (' . $session_fees['session_title'] . ')' : 
                            $translations[$lang]['old_session'] . ' (' . $session_fees['session_title'] . ')';
                    ?>
                    
                    <div class="session-header <?php echo $is_current_session ? 'current-session-header' : 'old-session-header'; ?>">
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
                                <tr class="<?php echo $is_current_session ? 'current-session-row' : 'old-session-row'; ?>">
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
                                
                                <!-- Session Total Row -->
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
                    
                    <?php endforeach; // End sessions loop ?>
                    
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
                                        <td colspan="<?php echo (isset($session_fees['months']) ? count($session_fees['months']) : 12) - 1; ?>" style="text-align: center;">
                                            Total of all sessions
                                        </td>
                                        <td class="total"><strong><?php echo number_format($student['current_due']); ?></strong></td>
                                    </tr>
                                    
                                    <?php if (isset($student['old_dues_amount']) && $student['old_dues_amount'] > 0): ?>
                                    <tr class="old-dues-row">
                                        <td colspan="<?php echo (isset($session_fees['months']) ? count($session_fees['months']) : 12) + 1; ?>">
                                            <?php echo $translations[$lang]['previous_dues']; ?>
                                        </td>
                                        <td class="total"><?php echo number_format($student['old_dues_amount']); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    
                                    <tr class="total-row">
                                        <td colspan="<?php echo (isset($session_fees['months']) ? count($session_fees['months']) : 12) + 1; ?>">
                                            <?php echo $translations[$lang]['total_fees']; ?>
                                        </td>
                                        <td class="total"><strong><?php echo number_format($student['total_due']); ?></strong></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                <?php 
                } else {
                    echo '<div style="text-align: center; padding: 20px; color: #999;">No fee data available for this student.</div>';
                }
                ?>
                
                <div style="margin-top: 8px; text-align: center; font-size: 9px;">
                    <p>Note: Please pay fees before due date to avoid late fee charges.</p>
                    <p>For any query, contact college office during office hours.</p>
                    <?php if (isset($student['old_dues_amount']) && $student['old_dues_amount'] > 0): ?>
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
                            <span><strong><?php echo $translations[$lang]['student_name']; ?>:</strong> <?php echo $student['name']; ?></span>
                            <span><strong><?php echo $translations[$lang]['father_name']; ?>:</strong> <?php echo $student['father_name']; ?></span>
                            <span><strong><?php echo $translations[$lang]['class']; ?>:</strong> <?php echo $class_title; ?></span>
                            <span><strong><?php echo $translations[$lang]['mobile']; ?>:</strong> <?php echo isset($student['mobile']) ? $student['mobile'] : 'N/A'; ?></span>
                        </div>
                        <div class="student-info-right">
                            <span><strong><?php echo $translations[$lang]['session']; ?>:</strong> <?php echo $session_title; ?></span>
                            <span><strong>Student Class ID:</strong> <?php echo isset($student['student_class_id']) ? $student['student_class_id'] : ''; ?></span>
                            <span><strong><?php echo $translations[$lang]['issue_date']; ?>:</strong> <?php echo date('d-m-Y'); ?></span>
                            <?php if (isset($student['old_dues_amount']) && $student['old_dues_amount'] > 0): ?>
                            <span><strong style="color: #ff6b00;"><?php echo $translations[$lang]['old_dues']; ?>:</strong> <span class="old-dues-amount"><?php echo number_format($student['old_dues_amount']); ?> PKR</span></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <?php 
                if (isset($student['pending_fees']) && !empty($student['pending_fees'])) {
                    // Office copy with same pending fees display
                    if (isset($student['paid_until_display']) && $student['paid_until_display']) {
                        echo '<div class="paid-message">✓ Fees paid until ' . $student['paid_until_display'] . '</div>';
                    }
                    
                    $grouped_fees = [];
                    foreach ($student['pending_fees'] as $fee) {
                        $grouped_fees[$fee['month']][] = $fee;
                    }
                    
                    foreach ($grouped_fees as $month_name => $fees):
                        $month_total = 0;
                        foreach ($fees as $fee) {
                            $month_total += $fee['amount'];
                        }
                ?>
                
                <div class="session-header current-session-header">
                    <?php echo $month_name; ?> - Pending Fees
                </div>
                
                <div class="fee-details">
                    <table>
                        <thead>
                            <tr>
                                <th><?php echo $translations[$lang]['fee_type']; ?></th>
                                <th class="month">Amount (PKR)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fees as $fee): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($fee['fee_type']); ?></td>
                                <td class="month pending-amount"><?php echo number_format($fee['amount']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <tr class="total-monthly-row">
                                <td><strong><?php echo $translations[$lang]['total_monthly']; ?></strong></td>
                                <td class="month"><strong><?php echo number_format($month_total); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <?php 
                    endforeach;
                    
                    if (isset($student['old_dues_amount']) && $student['old_dues_amount'] > 0) {
                        echo '<div class="old-dues-row" style="padding: 5px; margin: 5px 0; text-align: right;">';
                        echo '<strong>Previous Dues: </strong>' . number_format($student['old_dues_amount']) . ' PKR';
                        echo '</div>';
                    }
                    
                    echo '<div style="background-color: #007bff; color: white; padding: 8px; margin-top: 10px; border-radius: 5px; text-align: right; font-weight: bold; font-size: 18px;">';
                    echo 'TOTAL DUE: ' . number_format($student['total_due']) . ' PKR';
                    echo '</div>';
                    
                } else if (isset($student['all_fees']) && !empty($student['all_fees'])) {
                    // Old structure display (same as student copy)
                    foreach ($student['all_fees'] as $session_fees): 
                        $session_total = 0;
                        $session_has_fees = false;
                        
                        foreach ($session_fees['fee_types'] as $fee_type) {
                            if ($fee_type['total'] > 0) {
                                $session_has_fees = true;
                                break;
                            }
                        }
                        
                        if (!$session_has_fees) continue;
                        
                        $is_current_session = (isset($session_fees['session_id']) && $session_fees['session_id'] == $selected_session_id);
                        $session_label = $is_current_session ? 
                            $translations[$lang]['current_session'] . ' (' . $session_fees['session_title'] . ')' : 
                            $translations[$lang]['old_session'] . ' (' . $session_fees['session_title'] . ')';
                    ?>
                    
                    <div class="session-header <?php echo $is_current_session ? 'current-session-header' : 'old-session-header'; ?>">
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
                                <tr>
                                    <td><?php echo htmlspecialchars($fee_type['title']); ?></td>
                                    <?php foreach ($session_fees['months'] as $month_key => $month_name): ?>
                                    <td class="month">
                                        <?php 
                                        if (isset($fee_type['months'][$month_key]) && $fee_type['months'][$month_key] !== null) {
                                            $card = $fee_type['months'][$month_key];
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
                                
                                <!-- Session Total Row -->
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
                                                echo '<span class="paid-tick">✓</span>';
                                                echo '<span class="partial-details">' . number_format($month_total) . '</span>';
                                            elseif ($month_paid > 0 && $month_remaining > 0):
                                                echo '<div class="partial-wrapper">';
                                                echo '<span class="assigned-amount">' . number_format($month_total) . '</span>';
                                                echo '<span class="partial-details">(' . number_format($month_paid) . '/' . number_format($month_total) . ')</span>';
                                                echo '</div>';
                                            else:
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
                    
                    <!-- Summary Section -->
                    <div class="summary-section">
                        <div class="fee-details">
                            <table>
                                <tbody>
                                    <tr class="total-monthly-row">
                                        <td colspan="2" style="text-align: left;">
                                            <strong><?php echo $translations[$lang]['all_fees_summary']; ?></strong>
                                        </td>
                                        <td colspan="<?php echo (isset($session_fees['months']) ? count($session_fees['months']) : 12) - 1; ?>" style="text-align: center;">
                                            Total of all sessions
                                        </td>
                                        <td class="total"><strong><?php echo number_format($student['current_due']); ?></strong></td>
                                    </tr>
                                    
                                    <?php if (isset($student['old_dues_amount']) && $student['old_dues_amount'] > 0): ?>
                                    <tr class="old-dues-row">
                                        <td colspan="<?php echo (isset($session_fees['months']) ? count($session_fees['months']) : 12) + 1; ?>">
                                            <?php echo $translations[$lang]['previous_dues']; ?>
                                        </td>
                                        <td class="total"><?php echo number_format($student['old_dues_amount']); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    
                                    <tr class="total-row">
                                        <td colspan="<?php echo (isset($session_fees['months']) ? count($session_fees['months']) : 12) + 1; ?>">
                                            <?php echo $translations[$lang]['total_fees']; ?>
                                        </td>
                                        <td class="total"><strong><?php echo number_format($student['total_due']); ?></strong></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php } ?>
                
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
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>
<?php
// Clear session data after display
unset($_SESSION['print_data']);
?>