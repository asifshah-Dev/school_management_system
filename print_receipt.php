<?php
require_once('security.php');
require_once('conn_inc.php');

// Get transaction ID(s) from URL - support both single ID and multiple IDs
$transaction_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$transaction_ids = isset($_GET['ids']) ? $_GET['ids'] : '';

$transactions = [];
$is_multiple = false;

if (!empty($transaction_ids) && $transaction_id == 0) {
    // Multiple transactions
    $ids_array = array_map('intval', explode(',', $transaction_ids));
    $ids_string = implode(',', $ids_array);
    $is_multiple = true;
    
    $query = $conn->query("
        SELECT 
            sfp.*,
            sfc.due_date,
            sfc.total_amount as fee_card_total,
            ft.title as fee_type_title,
            sc.id as student_class_id,
            sc.class_id,
            c.title as class_name,
            s.title as session_title,
            sr.id as student_id,
            sr.name as student_name,
            sr.father_name,
            sr.reg_no,
            sr.cnic,
            sr.mobile,
            sr.guardian_name,
            sr.guardian_mobile,
            sr.current_address,
            vc.title as village_name
        FROM student_fee_payments sfp
        INNER JOIN student_fee_card sfc ON sfp.fee_card_id = sfc.id
        INNER JOIN fee_types ft ON sfc.fee_type_id = ft.id
        INNER JOIN student_class sc ON sfc.student_class_id = sc.id
        INNER JOIN classes c ON sc.class_id = c.id
        INNER JOIN sessions s ON sc.session_id = s.id
        INNER JOIN student_registration sr ON sc.student_registration_id = sr.id
        LEFT JOIN village_councils vc ON sr.village_council_id = vc.id
        WHERE sfp.id IN ($ids_string)
        ORDER BY sfp.payment_date DESC
    ");
    
    while ($row = $query->fetch_assoc()) {
        $transactions[] = $row;
    }
    
    if (empty($transactions)) {
        die('Transactions not found');
    }
    
    // Use first transaction for student info (all should be same student)
    $trans = $transactions[0];
    
} else if ($transaction_id > 0) {
    // Single transaction
    $query = $conn->query("
        SELECT 
            sfp.*,
            sfc.due_date,
            sfc.total_amount as fee_card_total,
            ft.title as fee_type_title,
            sc.id as student_class_id,
            sc.class_id,
            c.title as class_name,
            s.title as session_title,
            sr.id as student_id,
            sr.name as student_name,
            sr.father_name,
            sr.reg_no,
            sr.cnic,
            sr.mobile,
            sr.guardian_name,
            sr.guardian_mobile,
            sr.current_address,
            vc.title as village_name
        FROM student_fee_payments sfp
        INNER JOIN student_fee_card sfc ON sfp.fee_card_id = sfc.id
        INNER JOIN fee_types ft ON sfc.fee_type_id = ft.id
        INNER JOIN student_class sc ON sfc.student_class_id = sc.id
        INNER JOIN classes c ON sc.class_id = c.id
        INNER JOIN sessions s ON sc.session_id = s.id
        INNER JOIN student_registration sr ON sc.student_registration_id = sr.id
        LEFT JOIN village_councils vc ON sr.village_council_id = vc.id
        WHERE sfp.id = $transaction_id
    ");
    
    if ($query->num_rows == 0) {
        die('Transaction not found');
    }
    
    $trans = $query->fetch_assoc();
    $transactions[] = $trans;
} else {
    die('Invalid transaction ID');
}

// Generate receipt number for single or first transaction
$receipt_no = "REC-" . date('Ymd', strtotime($trans['payment_date'])) . "-" . $trans['id'];
if ($is_multiple) {
    $receipt_no = "REC-MULTI-" . date('Ymd') . "-" . count($transactions);
}

// Determine if this is an advance payment (check if any is advance)
$is_advance = false;
foreach ($transactions as $t) {
    if ($t['is_advance'] == 1) {
        $is_advance = true;
        break;
    }
}

// Get month-year from due date function
function getMonthYearFromDueDate($due_date) {
    $timestamp = strtotime($due_date);
    return date('M-y', $timestamp);
}

// NEW FUNCTION: Get formatted month range from transactions
function getPaymentMonthRange($transactions) {
    if (empty($transactions)) {
        return '';
    }
    
    // Extract due dates from all transactions
    $months = [];
    foreach ($transactions as $t) {
        if (!empty($t['due_date'])) {
            $months[] = strtotime($t['due_date']);
        }
    }
    
    if (empty($months)) {
        return '';
    }
    
    // Get min and max timestamps
    $min_timestamp = min($months);
    $max_timestamp = max($months);
    
    // Format months
    $start_month = date('M Y', $min_timestamp);
    $end_month = date('M Y', $max_timestamp);
    
    // If same month, show single month
    if ($start_month == $end_month) {
        return $start_month;
    }
    
    // Otherwise show range
    return $start_month . ' to ' . $end_month;
}

// NEW FUNCTION: Get fee types with amounts (without decimals)
function getFeeTypesWithAmounts($transactions) {
    if (empty($transactions)) {
        return [];
    }
    
    $fee_types_with_amounts = [];
    foreach ($transactions as $t) {
        if (!empty($t['fee_type_title'])) {
            $fee_type = $t['fee_type_title'];
            $amount = $t['paid_amount'] ?? 0;
            
            if (!isset($fee_types_with_amounts[$fee_type])) {
                $fee_types_with_amounts[$fee_type] = 0;
            }
            $fee_types_with_amounts[$fee_type] += $amount;
        }
    }
    
    return $fee_types_with_amounts;
}

// School information
$org_name = "Dar-e-Arqam Schools";
$org_address = "Matta Campus";
$org_phone = "Ph: 0946-791555";
$org_email = "";

// Format payment method for display (use first transaction's method)
$payment_method_display = '';
switch($trans['payment_method']) {
    case 'cash':
        $payment_method_display = 'Cash';
        break;
    case 'bank_transfer':
        $payment_method_display = 'Bank Transfer';
        break;
    case 'cheque':
        $payment_method_display = 'Cheque';
        break;
    case 'online':
        $payment_method_display = 'Online Payment';
        break;
    default:
        $payment_method_display = ucfirst(str_replace('_', ' ', $trans['payment_method']));
}

// Fixed number to words function
function numberToWords($number) {
    if ($number == 0) return 'Zero';
    
    $words = array(
        0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 
        5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 
        14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 
        18 => 'Eighteen', 19 => 'Nineteen', 20 => 'Twenty', 30 => 'Thirty', 
        40 => 'Forty', 50 => 'Fifty', 60 => 'Sixty', 70 => 'Seventy', 
        80 => 'Eighty', 90 => 'Ninety', 100 => 'Hundred', 1000 => 'Thousand', 
        100000 => 'Lakh', 10000000 => 'Crore'
    );
    
    // For numbers less than 100
    if ($number < 100) {
        if ($number < 21) {
            return $words[$number];
        } else {
            $tens = $words[10 * floor($number/10)];
            $units = $number % 10;
            return $tens . ($units ? ' ' . $words[$units] : '');
        }
    }
    
    // For numbers less than 1000 (Hundreds)
    if ($number < 1000) {
        $hundreds = $words[floor($number/100)] . ' Hundred';
        $remainder = $number % 100;
        return $hundreds . ($remainder ? ' and ' . numberToWords($remainder) : '');
    }
    
    // For numbers less than 100000 (Thousands)
    if ($number < 100000) {
        $thousands = numberToWords(floor($number/1000)) . ' Thousand';
        $remainder = $number % 1000;
        return $thousands . ($remainder ? ' ' . numberToWords($remainder) : '');
    }
    
    // For numbers less than 10000000 (Lakhs)
    if ($number < 10000000) {
        $lakhs = numberToWords(floor($number/100000)) . ' Lakh';
        $remainder = $number % 100000;
        return $lakhs . ($remainder ? ' ' . numberToWords($remainder) : '');
    }
    
    // For numbers in Crores
    $crores = numberToWords(floor($number/10000000)) . ' Crore';
    $remainder = $number % 10000000;
    return $crores . ($remainder ? ' ' . numberToWords($remainder) : '');
}

// Calculate total amounts for multiple transactions
$total_fee_amount = 0;
$total_discount_amount = 0;
$total_net_amount = 0;
$total_paid_amount = 0;

foreach ($transactions as $index => $t) {
    $fee = $t['fee_card_total'] ?? $t['paid_amount'];
    $discount = $t['discount_amount'];
    $net = $fee - $discount;
    $paid = $t['paid_amount'];
    
    $total_fee_amount += $fee;
    $total_discount_amount += $discount;
    $total_net_amount += $net;
    $total_paid_amount += $paid;
}

// Convert amount to words (use total paid for multiple)
$amount_parts = explode('.', number_format($total_paid_amount, 2, '.', ''));
$rupees = intval($amount_parts[0]);
$paise = intval($amount_parts[1]);

$amount_in_words = numberToWords($rupees) . ' Rupees';
if ($paise > 0) {
    $amount_in_words .= ' and ' . numberToWords($paise) . ' Paise';
}
$amount_in_words .= ' Only';

// Get payment month range for display
$payment_month_range = getPaymentMonthRange($transactions);

// Get fee types with amounts for display
$fee_types_with_amounts = getFeeTypesWithAmounts($transactions);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fee Receipt <?php echo $is_multiple ? '(Multiple)' : '#' . $receipt_no; ?></title>
    
    <!-- Bootstrap CSS for print layout -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Include JsBarcode library -->
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    
    <style>
        /* Thermal Printer Specific Styles - COPIED FROM THERMAL PRINT VIEW */
        body {
            font-family: 'Courier New', monospace;
            font-size: 14px; /* Increased base font size */
            line-height: 1.2;
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
            padding: 3px;
            box-sizing: border-box;
        }
        
        .thermal-header {
            text-align: center;
            margin-bottom: 3px;
            padding-bottom: 2px;
            border-bottom: 1.5px solid #000;
        }
        
        .thermal-title {
            font-size: 18px; /* Increased from 16px */
            font-weight: 800; /* Extra bold */
            margin-bottom: 2px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .thermal-subtitle {
            font-size: 16px; /* Increased from 14px */
            margin-bottom: 2px;
            font-weight: 700; /* Bold */
        }
        
        .thermal-contact {
            font-size: 14px; /* Increased from 12px */
            margin-bottom: 2px;
            font-weight: 700; /* Bold */
        }
        
        .thermal-divider {
            border-bottom: 2px dashed #000;
            margin: 3px 0;
        }
        
        .thermal-student-info {
            margin: 3px 0;
            font-size: 14px; /* Increased from 13px */
        }
        
        .thermal-student-info div {
            margin-bottom: 2px;
        }
        
        .thermal-fee-table {
            width: 100%;
            border-collapse: collapse;
            margin: 3px 0;
            font-size: 15px; /* Increased from 14px */
            border: 2px solid #000;
        }
        
        .thermal-fee-table th,
        .thermal-fee-table td {
            padding: 5px 2mm; /* Increased padding */
            border: 1px solid #000;
            text-align: left;
            font-size: 15px; /* Increased from 14px */
            font-weight: 700; /* Bold for all table text */
            color: #000;
        }
        
        .thermal-fee-table th {
            font-weight: 800; /* Extra bold for headers */
            border-bottom: 2px solid #000;
            font-size: 15px; /* Increased from 14px */
            background-color: #f0f0f0;
            text-align: center;
        }
        
        .thermal-fee-table td {
            border: 1px solid #000;
            font-size: 15px; /* Increased from 14px */
            font-weight: 700; /* Bold */
            color: #000;
        }
        
        /* Make description column extra bold and larger */
        .thermal-fee-table td.fee-type-col {
            font-weight: 800; /* Extra bold */
            font-size: 15px; /* Larger */
            color: #000;
        }
        
        /* Make amount column extra bold */
        .thermal-fee-table td.amount-col {
            font-weight: 800; /* Extra bold */
            font-size: 15px; /* Larger */
            color: #000;
            text-align: right;
        }
        
        .thermal-fee-table .fee-type-col {
            width: 30mm;
            font-weight: 800; /* Extra bold */
        }
        
        .thermal-fee-table .amount-col {
            text-align: right;
            width: 15mm;
            font-weight: 800; /* Extra bold */
            color: #000;
        }
        
        .thermal-month-header {
            font-weight: 800; /* Extra bold */
            background-color: #f0f0f0;
            padding: 4px 4px; /* Increased padding */
            margin: 5px 0 3px 0;
            font-size: 15px; /* Increased from 14px */
            text-align: center;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
        }
        
        .thermal-total-row {
            font-weight: 800; /* Extra bold */
            border-top: 2px solid #000 !important;
            border-bottom: 2px solid #000 !important;
            font-size: 15px; /* Increased from 14px */
            background-color: #e8e8e8;
        }
        
        .thermal-total-row td {
            font-weight: 800 !important; /* Extra bold */
            font-size: 15px !important; /* Larger */
        }
        
        /* Discount row styling - very bold and readable */
        .discount-row td {
            font-weight: 800 !important; /* Extra bold */
            font-size: 15px !important; /* Larger */
            color: #b91c1c !important; /* Dark red for visibility */
        }
        
        .discount-row td.fee-type-col {
            font-weight: 800 !important;
        }
        
        .discount-row td.amount-col {
            font-weight: 800 !important;
            color: #b91c1c !important;
        }
        
        .thermal-old-dues {
            background-color: #f0f0f0;
            font-weight: 800; /* Extra bold */
            border: 2px solid #000 !important;
            font-size: 15px; /* Increased from 14px */
        }
        
        .thermal-grand-total {
            font-weight: 800; /* Extra bold */
            text-align: center;
            border: 3px solid #000 !important;
            padding: 5px !important; /* Increased padding */
            font-size: 17px; /* Increased from 15px */
            background-color: #e0e0e0;
        }
        
        .thermal-footer {
            margin-top: 4px;
            padding-top: 3px;
            border-top: 2px solid #000;
            font-size: 13px; /* Increased from 12px */
            text-align: center;
            font-weight: 700; /* Bold */
        }
        
        .thermal-session-header {
            font-weight: 800; /* Extra bold */
            background-color: #e0e0e0;
            padding: 4px 4px; /* Increased padding */
            margin: 5px 0 3px 0;
            font-size: 15px; /* Increased from 14px */
            text-align: center;
            border-left: 3px solid #007bff;
            border-right: 3px solid #007bff;
            border-radius: 3px;
        }
        
        .thermal-receipt-section {
            margin-top: 10px;
            padding-top: 5px;
            border-top: 2px solid #000;
            font-size: 13px; /* Increased from 12px */
            font-weight: 700; /* Bold */
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
            height: 30px !important; /* Increased height */
            max-width: 70mm;
            display: block;
            margin: 0 auto;
        }
        
        .barcode-id {
            font-family: monospace;
            font-size: 14px; /* Increased from 12px */
            font-weight: 800; /* Extra bold */
            margin-top: 5px;
            letter-spacing: 1px;
            text-align: center;
        }
        
        .office-signature {
            text-align: center;
            margin-top: 10px;
            padding-top: 5px;
            border-top: 1px solid #000;
            font-weight: 800; /* Extra bold */
            font-size: 14px; /* Increased from 12px */
        }
        
        .thermal-clear {
            clear: both;
        }
        
        .thermal-month-total {
            font-weight: 800; /* Extra bold */
            background-color: #f8f9fa;
            padding: 4px 4px; /* Increased padding */
            margin: 3px 0;
            font-size: 14px; /* Increased from 13px */
            border-top: 2px solid #000;
            border-bottom: 1px solid #000;
            text-align: center;
        }
        
        .thermal-month-paid {
            color: #000;
            font-weight: 800; /* Extra bold */
        }
        
        .thermal-month-balance {
            color: #000;
            font-weight: 800; /* Extra bold */
        }
        
        .thermal-month-amount {
            text-align: right;
            font-weight: 800; /* Extra bold */
            font-size: 15px; /* Increased from 14px */
            color: #000;
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
                font-size: 14px;
            }
            .thermal-container {
                width: 80mm;
                max-width: 80mm;
                margin: 0;
                padding: 2mm;
            }
            .thermal-fee-table {
                font-size: 15px;
                border: 2px solid #000 !important;
            }
            .thermal-fee-table th,
            .thermal-fee-table td {
                border: 1px solid #000 !important;
                font-size: 15px !important;
                font-weight: 700 !important;
                color: #000 !important;
            }
            .thermal-fee-table td.fee-type-col,
            .thermal-fee-table td.amount-col {
                font-weight: 800 !important;
            }
            .thermal-title {
                font-size: 18px;
            }
            .no-print {
                display: none !important;
            }
            .thermal-month-header {
                page-break-inside: avoid;
            }
            .page-break {
                page-break-after: always;
            }
            .barcode-svg {
                width: 70mm !important;
                height: 30px !important;
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
            margin-bottom: 3px; /* Increased margin */
        }
        
        .thermal-info-label {
            font-weight: 800; /* Extra bold */
            font-size: 14px; /* Increased from 13px */
            color: #000;
        }
        
        .thermal-info-value {
            text-align: right;
            font-weight: 800; /* Extra bold */
            font-size: 14px; /* Increased from 13px */
            color: #000;
        }
        
        /* Receipt specific adjustments */
        .receipt-info-line {
            display: flex;
            justify-content: space-between;
            margin-bottom: 4px; /* Increased margin */
            font-size: 15px; /* Increased from 14px */
            font-weight: 700; /* Bold */
        }
        
        .receipt-info-label {
            font-weight: 800; /* Extra bold */
            color: #000;
            font-size: 15px; /* Increased */
        }
        
        .receipt-info-value {
            font-weight: 800; /* Extra bold */
            color: #000;
            font-size: 15px; /* Increased */
        }
        
        .receipt-title-line {
            font-size: 20px; /* Increased from 18px */
            font-weight: 800; /* Extra bold */
            text-align: center;
            margin: 5px 0;
        }
        
        .receipt-status-badge {
            display: inline-block;
            padding: 5px 10px; /* Increased padding */
            font-weight: 800; /* Extra bold */
            font-size: 14px; /* Increased from 12px */
            border: 1px solid #000;
            background-color: #f0f0f0;
        }
        
        .receipt-amount-word {
            font-weight: 800; /* Extra bold */
            font-size: 14px; /* Increased from 12px */
            margin: 8px 0; /* Increased margin */
            padding: 5px; /* Increased padding */
            border-top: 1px dashed #000;
            border-bottom: 1px dashed #000;
        }
        
        /* Grand total section - increased size for total amount paid */
        .grand-total-section {
            background: #f1f5f9;
            margin: 10px 0; /* Increased margin */
            padding: 8px; /* Increased padding */
            border: 2px solid #000;
        }
        
        .grand-total-row {
            border-top: 2px solid #000;
        }
        
        .grand-total-label {
            font-size: 16px; /* Larger */
            font-weight: 800; /* Extra bold */
        }
        
        .grand-total-amount {
            font-size: 20px !important; /* Much larger for total paid amount */
            font-weight: 900 !important; /* Ultra bold */
            text-align: right;
            color: #000;
        }
        
        /* Discount label styling */
        .discount-label {
            font-weight: 800; /* Extra bold */
            font-size: 16px; /* Larger */
            color: #b91c1c;
        }
        
        .discount-amount {
            font-weight: 800; /* Extra bold */
            font-size: 16px; /* Larger */
            color: #b91c1c !important;
            text-align: right;
        }
        
        /* NEW: Payment Information Section with Month Range and Fee Types */
        .payment-info-section {
            background: #e6f3ff;
            padding: 8px;
            margin: 8px 0;
            border: 2px solid #000;
        }
        
        .payment-info-label {
            font-weight: 800;
            font-size: 14px;
            margin-bottom: 3px;
        }
        
        .payment-info-value {
            font-weight: 900;
            font-size: 16px;
            color: #000;
            margin-bottom: 5px;
        }
        
        .fee-type-breakdown {
            margin-top: 5px;
            border-top: 1px dashed #000;
            padding-top: 5px;
        }
        
        .fee-type-item {
            display: flex;
            justify-content: space-between;
            font-weight: 700;
            font-size: 14px;
            margin-bottom: 2px;
        }
        
        .fee-type-name {
            font-weight: 800;
        }
        
        .fee-type-amount {
            font-weight: 800;
            text-align: right;
        }
    </style>
</head>
<body>
    <div class="print-btn-container text-center no-print">
        <div style="width: 80mm; margin: 0 auto; padding: 10px 0;">
            <button onclick="window.print()" class="print-btn" style="display: block; width: 100%; padding: 12px; background: #2563eb; color: white; border: none; border-radius: 6px; font-size: 16px; font-weight: 800; cursor: pointer;">
                <i class="fas fa-print"></i> Print Receipt
            </button>
            <div style="margin-top: 5px;">
                <a href="javascript:window.close();" class="back-btn" style="display: inline-block; margin: 5px 3px; padding: 10px 16px; background: #64748b; color: white; text-decoration: none; border-radius: 4px; font-size: 14px; font-weight: 700;">
                    <i class="fas fa-times"></i> Close
                </a>
                <a href="fee_collection.php?id=<?php echo $trans['student_id']; ?>" class="back-btn" style="display: inline-block; margin: 5px 3px; padding: 10px 16px; background: #64748b; color: white; text-decoration: none; border-radius: 4px; font-size: 14px; font-weight: 700;">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>
    </div>
    
    <!-- Receipt Content - Using Thermal Print Styles -->
    <div class="thermal-container">
        
        <!-- Header -->
        <div class="thermal-header">
            <div class="thermal-title">Dar-e-Arqam Schools</div>
            <div class="thermal-subtitle">Matta Campus</div>
            <div class="thermal-contact">Ph: 0946-791555</div>
            <hr style="border: 1px solid #000; margin: 3px 0;">
            <div class="thermal-title" style="font-size: 18px;">
                <i class="fas fa-receipt"></i> FEE RECEIPT
                <?php if ($is_multiple): ?>
                <span style="background: #8b5cf6; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 800;">MULTIPLE</span>
                <?php endif; ?>
            </div>
            <div style="margin: 3px 0;">
               <span class="receipt-status-badge" style="color: #000; font-weight: bold;">
    <i class="fas <?php echo $is_advance ? 'fa-forward' : 'fa-check-circle'; ?>"></i> 
    <?php echo $is_advance ? 'ADVANCE PAYMENT' : 'PAID'; ?>
</span>

            </div>
        </div>
        
        <!-- Receipt Number and Date -->
        <div style="background: #f8fafc; padding: 6px; margin: 5px 0; border: 1px solid #000;">
            <div class="receipt-info-line">
                <span class="receipt-info-label">Receipt No:</span>
                <span class="receipt-info-value"><?php echo $receipt_no; ?></span>
            </div>
            <div class="receipt-info-line">
                <span class="receipt-info-label">Date:</span>
                <span class="receipt-info-value"><?php echo date('d-m-Y', strtotime($trans['payment_date'])); ?></span>
            </div>
            <div class="receipt-info-line">
                <span class="receipt-info-label">Payment Mode:</span>
                <span class="receipt-info-value"><?php echo $payment_method_display; ?></span>
            </div>
            <?php if (!empty($trans['transaction_ref'])): ?>
            <div class="receipt-info-line">
                <span class="receipt-info-label">Transaction Ref:</span>
                <span class="receipt-info-value"><?php echo htmlspecialchars($trans['transaction_ref']); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($is_multiple): ?>
            <div class="receipt-info-line">
                <span class="receipt-info-label">Total Transactions:</span>
                <span class="receipt-info-value"><?php echo count($transactions); ?></span>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Student Information -->
        <div class="thermal-student-info" style="border: 1px solid #000; padding: 6px; margin: 5px 0;">
            <div class="thermal-info-line">
                <span class="thermal-info-label">Student Name:</span>
                <span class="thermal-info-value"><?php echo htmlspecialchars($trans['student_name']); ?></span>
            </div>
            <div class="thermal-info-line">
                <span class="thermal-info-label">Father's Name:</span>
                <span class="thermal-info-value"><?php echo htmlspecialchars($trans['father_name']); ?></span>
            </div>
            <div class="thermal-info-line">
                <span class="thermal-info-label">Reg No:</span>
                <span class="thermal-info-value"><?php echo htmlspecialchars($trans['reg_no']); ?></span>
            </div>
            <div class="thermal-info-line">
                <span class="thermal-info-label">Class:</span>
                <span class="thermal-info-value"><?php echo htmlspecialchars($trans['class_name']); ?></span>
            </div>
            <div class="thermal-info-line">
                <span class="thermal-info-label">Session:</span>
                <span class="thermal-info-value"><?php echo htmlspecialchars($trans['session_title']); ?></span>
            </div>
            <?php if (!empty($trans['mobile'])): ?>
            <div class="thermal-info-line">
                <span class="thermal-info-label">Mobile:</span>
                <span class="thermal-info-value"><?php echo htmlspecialchars($trans['mobile']); ?></span>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($trans['current_address'])): ?>
        <div style="font-size: 14px; font-weight: 700; margin: 3px 0; padding: 3px; border: 1px solid #000;">
            <strong>Address:</strong> <?php echo htmlspecialchars($trans['current_address']); ?>
        </div>
        <?php endif; ?>
        
        <div class="thermal-divider"></div>
        
        <!-- NEW: Payment Information Section with Month Range and Fee Types Breakdown -->
        <div class="payment-info-section">
            <?php if (!empty($payment_month_range)): ?>
            <div style="margin-bottom: 5px;">
                <span class="payment-info-label">Payment For Month(s):</span>
                <div class="payment-info-value"><?php echo $payment_month_range; ?></div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($fee_types_with_amounts)): ?>
            <div>
                <span class="payment-info-label">Fee Type(s) Breakdown:</span>
                <div class="fee-type-breakdown">
                    <?php foreach ($fee_types_with_amounts as $fee_type => $amount): ?>
                    <div class="fee-type-item">
                        <span class="fee-type-name"><?php echo $fee_type; ?>:</span>
                        <span class="fee-type-amount">Rs. <?php echo number_format($amount); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Grand Totals - Enhanced with larger amount -->
        <div class="grand-total-section">
            <table style="width: 100%;">
                <tr>
                    <td style="font-size: 15px; font-weight: 800;">Total Fee:</td>
                    <td style="font-size: 16px; font-weight: 800; text-align: right;"> <?php echo number_format($total_fee_amount); ?></td>
                </tr>
                <?php if ($total_discount_amount > 0): ?>
                <tr>
                    <td style="font-size: 16px; font-weight: 800; color:black !important; "> Discount:</td>
                    <td style="font-size: 17px; font-weight: 800; text-align: right; color:black !important; " >
                        - <?php echo number_format($total_discount_amount); ?>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td style="font-size: 15px; font-weight: 800;">Total After Discount:</td>
                    <td style="font-size: 16px; font-weight: 800; text-align: right;"> <?php echo number_format($total_net_amount); ?></td>
                </tr>
                <tr class="grand-total-row">
                    <td style="font-size: 18px; font-weight: 900;" class="grand-total-label">PAID:</td>
                    <td style="font-size: 22px !important; font-weight: 900 !important; text-align: right; color: #000;" class="grand-total-amount">
                         <?php echo number_format($total_paid_amount); ?>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Remarks if any -->
        <?php if (!empty($trans['remarks'])): ?>
        <div style="font-size: 14px; margin: 8px 0; padding: 6px; background: #fef3c7; border: 1px solid #000; font-weight: 800;">
            <i class="fas fa-sticky-note"></i> <strong>Remarks:</strong> <?php echo htmlspecialchars($trans['remarks']); ?>
        </div>
        <?php endif; ?>
        
        <!-- Amount in Words -->
       
        
        <!-- Footer -->
        <div class="thermal-footer">
            <div style="font-size: 13px; font-weight: 800;">*** Thank you for your payment ***</div>
            <div style="font-size: 12px; font-weight: 700;">This is a computer generated receipt</div>
        </div>
    </div>
    
    <script>
        // Function to generate barcode
        function generateBarcodes() {
            const barcodeContainers = document.querySelectorAll('.barcode-container');
            
            barcodeContainers.forEach((container, index) => {
                const studentId = container.getAttribute('data-student-id');
                const svgId = `barcode-${index}`;
                
                const svgElement = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                svgElement.id = svgId;
                svgElement.className = 'barcode-svg';
                
                container.innerHTML = '';
                container.appendChild(svgElement);
                
                JsBarcode(`#${svgId}`, studentId, {
                    format: "CODE128",
                    width: 1.5,
                    height: 30,
                    displayValue: false,
                    background: "transparent",
                    lineColor: "#000000",
                    margin: 0,
                    fontSize: 0
                });
            });
        }
        
        window.onload = function() {
            generateBarcodes();
            
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('auto_print') === '1') {
                setTimeout(function() {
                    window.print();
                }, 500);
            }
        };
    </script>
</body>
</html>
<?php $conn->close(); ?>