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
        'thermal_print_view' => 'Thermal Print View',
        'total_amount' => 'Total',
        'paid' => 'Paid',
        'balance' => 'Balance',
        'monthly_summary' => 'Monthly Summary',
        'student_details' => 'Student Details',
        'total' => 'Total'
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

// Only show thermal print for fee cards, not for defaulter list
if ($print_type != 'feecards') {
    // If trying to thermal print defaulter list, redirect to regular print
    header("Location: print_view.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo $translations[$lang]['thermal_print_view']; ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Include JsBarcode library -->
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <style>
    /* Thermal Printer Specific Styles - UPDATED WITH BOLD BLACK TEXT AND BORDERS */
    body {
        font-family: 'Courier New', monospace;
        font-size: 13px;
        line-height: 1.1;
        margin: 0;
        padding: 0;
        background-color: #fff;
        color: #000;
        width: 80mm;
        margin: 0 auto;
    }
    
    .thermal-container {
        width: 80mm;
        max-width: 80mm;
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    /* Individual slip wrapper with proper spacing */
    .thermal-slip {
        margin-bottom: 15px;
        padding: 3px;
        page-break-after: avoid;
        break-inside: avoid;
    }
    
    /* Last slip doesn't need bottom margin */
    .thermal-slip:last-child {
        margin-bottom: 0;
    }
    
    .thermal-header {
        text-align: center;
        margin-bottom: 3px;
        padding-bottom: 2px;
        border-bottom: 1.5px solid #000;
    }
    
    .thermal-title {
        font-size: 16px;
        font-weight: bold;
        margin-bottom: 2px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .thermal-subtitle {
        font-size: 14px;
        margin-bottom: 2px;
        font-weight: bold;
    }
    
    .thermal-contact {
        font-size: 12px;
        margin-bottom: 2px;
        font-weight: bold;
    }
    
    .thermal-divider {
        border-bottom: 2px dashed #000;
        margin: 3px 0;
    }
    
    .thermal-student-info {
        margin: 3px 0;
        font-size: 13px;
    }
    
    .thermal-student-info div {
        margin-bottom: 2px;
    }
    
    .thermal-fee-table {
        width: 100%;
        border-collapse: collapse;
        margin: 3px 0;
        font-size: 14px; /* Increased table font size */
        border: 2px solid #000; /* Added table border */
    }
    
    .thermal-fee-table th,
    .thermal-fee-table td {
        padding: 4px 2mm; /* Increased padding */
        border: 1px solid #000; /* Added cell borders */
        text-align: left;
        font-size: 14px; /* Increased font size */
        font-weight: bold; /* Added bold weight */
        color: #000; /* Pure black color */
    }
    
    .thermal-fee-table th {
        font-weight: bold;
        border-bottom: 2px solid #000;
        font-size: 14px; /* Increased header font size */
        background-color: #f0f0f0; /* Light background for headers */
        text-align: center; /* Center align headers */
    }
    
    .thermal-fee-table td {
        border: 1px solid #000;
        font-size: 14px;
        font-weight: bold; /* Bold TD text */
        color: #000; /* Pure black color */
    }
    
    .thermal-fee-table .fee-type-col {
        width: 30mm;
        font-weight: bold; /* Bold fee type text */
    }
    
    .thermal-fee-table .amount-col {
        text-align: right;
        width: 15mm;
        font-weight: bold;
        color: #000; /* Pure black color */
    }
    
    .thermal-month-header {
        font-weight: bold;
        background-color: #f0f0f0;
        padding: 3px 4px; /* Increased padding */
        margin: 5px 0 3px 0; /* Increased margin */
        font-size: 14px; /* Increased font size */
        text-align: center;
        border-top: 2px solid #000;
        border-bottom: 2px solid #000;
    }
    
    .thermal-total-row {
        font-weight: bold;
        border-top: 2px solid #000 !important;
        border-bottom: 2px solid #000 !important;
        font-size: 14px; /* Increased font size */
        background-color: #e8e8e8; /* Light background for total row */
    }
    
    .thermal-old-dues {
        background-color: #f0f0f0;
        font-weight: bold;
        border: 2px solid #000 !important;
        font-size: 14px; /* Increased font size */
    }
    
    .thermal-grand-total {
        font-weight: bold;
        text-align: center;
        border: 3px solid #000 !important;
        padding: 4px !important; /* Increased padding */
        font-size: 15px; /* Increased font size */
        background-color: #e0e0e0; /* Light background */
    }
    
    .thermal-footer {
        margin-top: 4px;
        padding-top: 3px;
        border-top: 2px solid #000;
        font-size: 12px;
        text-align: center;
        font-weight: bold;
    }
    
    .thermal-session-header {
        font-weight: bold;
        background-color: #e0e0e0;
        padding: 3px 4px; /* Increased padding */
        margin: 5px 0 3px 0; /* Increased margin */
        font-size: 14px; /* Increased font size */
        text-align: center;
        border-left: 3px solid #007bff;
        border-right: 3px solid #007bff;
        border-radius: 3px; /* Rounded corners */
    }
    
    .thermal-receipt-section {
        margin-top: 10px;
        padding-top: 5px;
        border-top: 2px solid #000;
        font-size: 12px;
        font-weight: bold;
        text-align: center;
    }
    
    /* Barcode Styles */
    .barcode-container {
        text-align: center;
        margin: 10px auto;
        padding: 5px;
    }
    
    .barcode-svg {
        width: 70mm !important;
        height: 25px !important;
        max-width: 70mm;
        display: block;
        margin: 0 auto;
    }
    
    .barcode-id {
        font-family: monospace;
        font-size: 12px;
        font-weight: bold;
        margin-top: 5px;
        letter-spacing: 1px;
        text-align: center;
    }
    
    .office-signature {
        text-align: center;
        margin-top: 10px;
        padding-top: 5px;
        border-top: 1px solid #000;
        font-weight: bold;
        font-size: 12px;
    }
    
    .thermal-clear {
        clear: both;
    }
    
    .thermal-month-total {
        font-weight: bold;
        background-color: #f8f9fa;
        padding: 3px 4px; /* Increased padding */
        margin: 3px 0; /* Increased margin */
        font-size: 13px; /* Increased font size */
        border-top: 2px solid #000;
        border-bottom: 1px solid #000;
        text-align: center;
    }
    
    .thermal-month-paid {
        color: #000; /* Pure black */
        font-weight: bold;
    }
    
    .thermal-month-balance {
        color: #000; /* Pure black */
        font-weight: bold;
    }
    
    .thermal-month-amount {
        text-align: right;
        font-weight: bold;
        font-size: 14px; /* Increased font size */
        color: #000; /* Pure black */
    }
    
    /* Print specific styles for thermal printers */
    @media print {
        @page {
            size: 80mm auto;
            margin: 0;
        }
        body {
            width: 80mm;
            margin: 0;
            padding: 0;
            font-size: 13px;
        }
        .thermal-container {
            width: 80mm;
            max-width: 80mm;
            margin: 0;
            padding: 0;
        }
        
        /* Slip spacing for print */
        .thermal-slip {
            margin-bottom: 12mm;
            padding: 0;
            page-break-after: avoid;
            break-inside: avoid;
            position: relative;
        }
        
        /* Add a visual separator for multiple slips */
        .thermal-slip:after {
            content: '';
            display: block;
            height: 5mm;
            background: transparent;
        }
        
        .thermal-slip:last-child:after {
            display: none;
        }
        
        .thermal-fee-table {
            font-size: 14px; /* Increased for print */
            border: 2px solid #000 !important;
        }
        .thermal-fee-table th,
        .thermal-fee-table td {
            border: 1px solid #000 !important;
            font-size: 14px !important;
            font-weight: bold !important;
            color: #000 !important;
        }
        .thermal-title {
            font-size: 15px;
        }
        .no-print {
            display: none !important;
        }
        .thermal-month-header {
            page-break-inside: avoid;
        }
        
        /* Page break control - ensure proper spacing between slips */
        .page-break {
            page-break-after: always;
            margin: 0;
            padding: 0;
            height: 2mm;
            display: block;
        }
        
        .barcode-svg {
            width: 70mm !important;
            height: 25px !important;
        }
        /* Ensure barcode prints in black and white for thermal printers */
        .barcode-svg path, .barcode-svg rect {
            fill: #000 !important;
            stroke: #000 !important;
        }
    }
    
    .no-print {
        text-align: center;
        margin: 10px 0;
        padding: 10px;
        background-color: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 4px;
    }
    
    .no-print button {
        margin: 5px;
        padding: 5px 10px;
        font-size: 12px;
    }
    
    .thermal-info-line {
        display: flex;
        justify-content: space-between;
        margin-bottom: 2px;
    }
    
    .thermal-info-label {
        font-weight: bold;
        font-size: 13px;
        color: #000; /* Pure black */
    }
    
    .thermal-info-value {
        text-align: right;
        font-weight: bold;
        font-size: 13px;
        color: #000; /* Pure black */
    }
    
    /* Style for table cell text */
    .thermal-fee-table td.fee-type-col {
        font-weight: bold;
        color: #000;
    }
    
    .thermal-fee-table td.amount-col {
        font-weight: bold;
        color: #000;
    }
    
    /* Additional spacing for multiple slips */
    .slip-spacer {
        display: block;
        height: 5mm;
        width: 100%;
        background: transparent;
    }
</style>
    <script>
        // Function to generate barcode for each student
        function generateBarcodes() {
            // Find all barcode containers
            const barcodeContainers = document.querySelectorAll('.barcode-container');
            
            barcodeContainers.forEach((container, index) => {
                const studentId = container.getAttribute('data-student-id');
                const svgId = `barcode-${index}`;
                
                // Create SVG element for barcode
                const svgElement = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                svgElement.id = svgId;
                svgElement.className = 'barcode-svg';
                
                // Clear container and add SVG
                container.innerHTML = '';
                container.appendChild(svgElement);
                
                // Generate barcode using JsBarcode
                JsBarcode(`#${svgId}`, studentId, {
                    format: "CODE128", // Using CODE128 which is more compact
                    width: 1.5, // Thinner bars for thermal printer
                    height: 25, // Height of barcode
                    displayValue: false, // Don't show text below barcode
                    background: "transparent",
                    lineColor: "#000000",
                    margin: 0,
                    fontSize: 0
                });
            });
        }
        
        window.onload = function() {
            // Generate barcodes first
            generateBarcodes();
            
            // Then initiate print after a short delay
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
    <div class="no-print">
        <h3><?php echo $translations[$lang]['thermal_print_view']; ?></h3>
        <p>Preparing for thermal printer (80mm width)...</p>
        <button onclick="window.print()">Print Now</button>
        <button onclick="window.location.href='student_feecards.php'">Cancel</button>
    </div>
    
    <div class="thermal-container">
        <?php foreach ($students as $student_index => $student): ?>
        
        <!-- Each student slip in its own wrapper with spacing -->
        <div class="thermal-slip">
            <div class="thermal-header">
                <div class="thermal-title">Dar-e-Arqam Schools</div>
                <div class="thermal-subtitle">Matta Campus</div>
                <div class="thermal-contact">Ph: 0946-791555</div>
                <div class="thermal-subtitle"><?php echo $translations[$lang]['fee_card']; ?></div>
            </div>
            
            <!-- Student Information -->
            <div class="thermal-student-info">
                <div class="thermal-info-line">
                    <span class="thermal-info-label"><?php echo $translations[$lang]['student_id']; ?>:</span>
                    <span class="thermal-info-value"><?php echo $student['id']; ?></span>
                </div>
                <div class="thermal-info-line">
                    <span class="thermal-info-label"><?php echo $translations[$lang]['student_name']; ?>:</span>
                    <span class="thermal-info-value"><?php echo $student['name']; ?></span>
                </div>
                <div class="thermal-info-line">
                    <span class="thermal-info-label"><?php echo $translations[$lang]['father_name']; ?>:</span>
                    <span class="thermal-info-value"><?php echo $student['father_name']; ?></span>
                </div>
                <div class="thermal-info-line">
                    <span class="thermal-info-label"><?php echo $translations[$lang]['class']; ?>:</span>
                    <span class="thermal-info-value"><?php echo $class_title; ?></span>
                </div>
                <div class="thermal-info-line">
                    <span class="thermal-info-label"><?php echo $translations[$lang]['session']; ?>:</span>
                    <span class="thermal-info-value"><?php echo $session_title; ?></span>
                </div>
                <div class="thermal-info-line">
                    <span class="thermal-info-label"><?php echo $translations[$lang]['issue_date']; ?>:</span>
                    <span class="thermal-info-value"><?php echo date('d-m-Y'); ?></span>
                </div>
                
                <!-- Show paid until message if available -->
                <?php if (isset($student['paid_until_display']) && $student['paid_until_display']): ?>
                <div class="thermal-info-line">
                    <span class="thermal-info-label" style="color:black;">Paid Until:</span>
                    <span class="thermal-info-value" style="color:black; font-weight: bold;"><?php echo $student['paid_until_display']; ?></span>
                </div>
                <?php endif; ?>
                
                <?php if (isset($student['old_dues_amount']) && $student['old_dues_amount'] > 0): ?>
                <div class="thermal-info-line">
                    <span class="thermal-info-label" style="color: #ff6b00;"><?php echo $translations[$lang]['old_dues']; ?>:</span>
                    <span class="thermal-info-value" style="color: #ff6b00;"><?php echo number_format($student['old_dues_amount']); ?> PKR</span>
                </div>
                <?php endif; ?>
                
                <?php if (isset($student['total_due']) && $student['total_due'] > 0): ?>
                <div class="thermal-info-line">
                    <span class="thermal-info-label" style="color:black;">Total Due:</span>
                    <span class="thermal-info-value" style="color:black; font-weight: bold;"><?php echo number_format($student['total_due']); ?> PKR</span>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="thermal-divider"></div>
            
            <?php 
            $has_any_fees = false;
            
            // Check if we have pending fees in the new structure
            if (isset($student['pending_fees']) && !empty($student['pending_fees'])) {
                $has_any_fees = true;
                ?>
                
                <!-- Pending Fees Table -->
                <table class="thermal-fee-table">
                    <thead>
                        <tr>
                            <th class="fee-type-col">Fee Type</th>
                            <th class="amount-col">Month</th>
                            <th class="amount-col">Amount (PKR)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($student['pending_fees'] as $fee): ?>
                        <tr>
                            <td class="fee-type-col" style="font-weight: bold; color: #000;"><?php echo htmlspecialchars($fee['fee_type']); ?></td>
                            <td class="amount-col" style="font-weight: bold; color: #000;"><?php echo $fee['month']; ?></td>
                            <td class="amount-col" style="font-weight: bold; color: #000;"><?php echo number_format($fee['amount']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if (isset($student['old_dues_amount']) && $student['old_dues_amount'] > 0): ?>
                        <tr>
                            <td class="fee-type-col" style="font-weight: bold; color: #ff6b00;">Previous Dues</td>
                            <td class="amount-col" style="font-weight: bold; color: #ff6b00;">-</td>
                            <td class="amount-col" style="font-weight: bold; color: #ff6b00;"><?php echo number_format($student['old_dues_amount']); ?></td>
                        </tr>
                        <?php endif; ?>
                        
                        <!-- Grand Total Row -->
                        <tr class="thermal-total-row">
                            <td colspan="2" class="fee-type-col" style="font-weight: bold; color: #000; text-align: right;">TOTAL DUE:</td>
                            <td class="amount-col" style="font-weight: bold; color: #dc3545;"><?php echo number_format($student['total_due']); ?> PKR</td>
                        </tr>
                    </tbody>
                </table>
                
                <?php
            } 
            // If no pending fees but we have old structure with all_fees
            else if (isset($student['all_fees']) && !empty($student['all_fees'])) {
                
                // Get all months from all sessions
                $all_months = [];
                foreach ($student['all_fees'] as $session_fees) {
                    if (isset($session_fees['months']) && is_array($session_fees['months'])) {
                        foreach ($session_fees['months'] as $month_key => $month_name) {
                            if (!isset($all_months[$month_key])) {
                                $all_months[$month_key] = $month_name;
                            }
                        }
                    }
                }
                ksort($all_months);
                
                // Display each session separately
                foreach ($student['all_fees'] as $session_fees): 
                    $session_total = 0;
                    $session_has_fees = false;
                    
                    // Check if this session has any fees
                    if (isset($session_fees['fee_types']) && is_array($session_fees['fee_types'])) {
                        foreach ($session_fees['fee_types'] as $fee_type) {
                            if (isset($fee_type['total']) && $fee_type['total'] > 0) {
                                $session_has_fees = true;
                                $has_any_fees = true;
                                break;
                            }
                        }
                    }
                    
                    if (!$session_has_fees) continue;
                    
                    $is_current_session = (isset($session_fees['session_id']) && $session_fees['session_id'] == $selected_session_id);
                    $session_label = $is_current_session ? 
                        $translations[$lang]['current_session'] . ' (' . (isset($session_fees['session_title']) ? $session_fees['session_title'] : '') . ')' : 
                        $translations[$lang]['old_session'] . ' (' . (isset($session_fees['session_title']) ? $session_fees['session_title'] : '') . ')';
                ?>
                
                <div class="thermal-session-header">
                    <?php echo $session_label; ?>
                </div>
                
                <?php 
                // Display each month vertically
                foreach ($all_months as $month_key => $month_name): 
                    $month_has_fees = false;
                    $month_total = 0;
                    $month_paid = 0;
                    $month_balance = 0;
                    
                    // Check if this month has any fees in this session
                    if (isset($session_fees['fee_types']) && is_array($session_fees['fee_types'])) {
                        foreach ($session_fees['fee_types'] as $fee_type) {
                            if (isset($fee_type['months'][$month_key]) && $fee_type['months'][$month_key] !== null) {
                                $card = $fee_type['months'][$month_key];
                                if (isset($card['amount']) || isset($card['total_amount'])) {
                                    $month_has_fees = true;
                                    $month_total += isset($card['total_amount']) ? $card['total_amount'] : 0;
                                    $month_paid += isset($card['paid_amount']) ? $card['paid_amount'] : 0;
                                    $month_balance += isset($card['amount']) ? $card['amount'] : 0;
                                }
                            }
                        }
                    }
                    
                    if (!$month_has_fees) continue;
                    
                    $session_total += $month_balance;
                ?>
                
                <!-- Month Header -->
                <div class="thermal-month-header">
                    <?php echo $month_name; ?> - <?php echo $translations[$lang]['monthly_summary']; ?>
                </div>
                
                <!-- Month Fee Details -->
                <table class="thermal-fee-table">
                    <thead>
                        <tr>
                            <th class="fee-type-col"><?php echo $translations[$lang]['fee_type']; ?></th>
                            <th class="amount-col"><?php echo $translations[$lang]['total_amount']; ?></th>
                            <th class="amount-col"><?php echo $translations[$lang]['paid']; ?></th>
                            <th class="amount-col"><?php echo $translations[$lang]['balance']; ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if (isset($session_fees['fee_types']) && is_array($session_fees['fee_types'])) {
                            foreach ($session_fees['fee_types'] as $fee_type): 
                                if (isset($fee_type['months'][$month_key]) && $fee_type['months'][$month_key] !== null):
                                    $card = $fee_type['months'][$month_key];
                                    if (isset($card['total_amount']) && $card['total_amount'] > 0):
                        ?>
                        <tr>
                            <td class="fee-type-col" style="font-weight: bold; color: #000;"><?php echo substr(htmlspecialchars($fee_type['title']), 0, 15); ?></td>
                            <td class="amount-col" style="font-weight: bold; color: #000;"><?php echo number_format($card['total_amount']); ?></td>
                            <td class="amount-col" style="font-weight: bold; color: #000;"><?php echo number_format($card['paid_amount']); ?></td>
                            <td class="amount-col" style="font-weight: bold; color: #000;"><?php echo number_format($card['amount']); ?></td>
                        </tr>
                        <?php 
                                    endif;
                                endif;
                            endforeach;
                        }
                        ?>
                        
                        <!-- Month Total Row -->
                        <tr class="thermal-total-row">
                            <td class="fee-type-col" style="font-weight: bold; color: #000;"><?php echo $translations[$lang]['total']; ?></td>
                            <td class="amount-col" style="font-weight: bold; color: #000;"><?php echo number_format($month_total); ?></td>
                            <td class="amount-col" style="font-weight: bold; color: #000;"><?php echo number_format($month_paid); ?></td>
                            <td class="amount-col" style="font-weight: bold; color: #000;"><?php echo number_format($month_balance); ?></td>
                        </tr>
                    </tbody>
                </table>
                
                <?php endforeach; // End months loop ?>
                
                <!-- Session Total -->
                <div class="thermal-month-total" style="text-align: center;">
                    <?php echo $session_label; ?> <?php echo $translations[$lang]['total_fees']; ?>: 
                    <strong style="color: #000;"><?php echo number_format($session_total); ?> PKR</strong>
                </div>
                
                <?php endforeach; // End sessions loop ?>
                
                <?php 
            }
            
            if (!$has_any_fees): 
            ?>
            <div style="text-align: center; padding: 5px; color: #999;">
                No fee data available.
            </div>
            <?php endif; ?>
            
            <!-- Office Signature Section -->
            <div class="office-signature">
                Office Signature: ________________
            </div>
            
            <!-- Barcode Section -->
            <div class="barcode-container" data-student-id="<?php echo $student['id']; ?>">
                <!-- Barcode will be generated here by JavaScript -->
            </div>
            <div class="barcode-id">
                Student ID: <?php echo $student['id']; ?>
            </div>
        </div> <!-- End thermal-slip -->
        
        <?php 
        // Add a spacer div between slips (visible only in print)
        if ($student_index < count($students) - 1): 
        ?>
        <div class="slip-spacer"></div>
        <?php endif; ?>
        
        <?php endforeach; // End students loop ?>
    </div>
</body>
</html>
<?php
// Clear session data after display
unset($_SESSION['print_data']);
?>