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

// Language handling - Keep existing session language, no URL switching
if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = 'en';
}
$lang = $_SESSION['lang'];

// Language strings - Enhanced with more labels (Urdu array kept intact as requested)
$translations = [
    'en' => [
        'dashboard' => 'Accounting Dashboard',
        'master_balance' => 'Main Account Balance',
        'total_cash_in' => 'Total Cash In',
        'total_cash_out' => 'Total Cash Out',
        'today_activity' => "Today's Activity",
        'search' => 'Search by reference ID or type...',
        'date_range' => 'Date Range',
        'all' => 'All Types',
        'cash_in' => 'Cash In',
        'cash_out' => 'Cash Out',
        'export' => 'Export',
        'print' => 'Print',
        'id' => 'ID',
        'type' => 'Type',
        'amount' => 'Amount',
        'running_balance' => 'Running Balance',
        'category' => 'Category',
        'description' => 'Description',
        'reference_id' => 'Reference ID',
        'date_time' => 'Date & Time',
        'actions' => 'Actions',
        'edit' => 'Edit',
        'reverse' => 'Reverse',
        'view' => 'View',
        'transactions' => 'Transactions',
        'no_records' => 'No transactions found matching your filters',
        'positive_balance' => 'Positive Balance',
        'last_updated' => 'Last Updated',
        'today' => 'Today',
        'fee_collected' => 'Fee Collected',
        'fee_correction' => 'Fee Correction',
        'advance_payment' => 'Advance Payment',
        'expense_paid' => 'Expense Paid',
        'salary_paid' => 'Salary Paid',
        'updated_salary' => 'Updated Salary',
        'fee_restore' => 'Fee Restored',
        'advance_restore' => 'Advance Restored',
        'expense_deletion' => 'Expense Restored',
        'expense_edit' => 'Expense Adjusted',
        'edited' => 'Edited',
        'reversed' => 'Reversed',
        'final_entry' => 'Final Entry',
        'advance_fee' => 'Advance Fee',
        'salary_new' => 'New Salary',
        'fee_edit_reversal' => 'Fee Reversal',
        'advance_edit_reversal' => 'Advance Reversal',
        'advance_restore_reversal' => 'Advance Restore Reversal',
        'salary_edit_reversal' => 'Salary Reversal',
        'salary_edit_new' => 'Salary Adjustment',
        'expense_deletion_restore' => 'Expense Restoration',
        'invalid_csrf' => 'Invalid security token',
        'reverse_success' => 'Transaction reversed successfully!',
        'reverse_error' => 'Error reversing transaction',
        'filter' => 'Filter',
        'clear' => 'Clear',
        'apply' => 'Apply',
        'from' => 'From',
        'to' => 'To',
        'all_categories' => 'All Categories',
        'cancel' => 'Cancel',
        'close' => 'Close'
    ],
    'ur' => [
        'dashboard' => 'اکاؤنٹنگ ڈیش بورڈ',
        'master_balance' => 'مین اکاؤنٹ بیلنس',
        'total_cash_in' => 'کل نقدی اندر',
        'total_cash_out' => 'کل نقدی باہر',
        'today_activity' => 'آج کی سرگرمی',
        'search' => 'ریفرنس آئی ڈی یا قسم سے تلاش کریں...',
        'date_range' => 'تاریخ کی حد',
        'all' => 'تمام اقسام',
        'cash_in' => 'نقدی اندر',
        'cash_out' => 'نقدی باہر',
        'export' => 'ایکسپورٹ',
        'print' => 'پرنٹ',
        'id' => 'آئی ڈی',
        'type' => 'قسم',
        'amount' => 'رقم',
        'running_balance' => 'چلنے والا بیلنس',
        'category' => 'کیٹیگری',
        'description' => 'تفصیل',
        'reference_id' => 'حوالہ آئی ڈی',
        'date_time' => 'تاریخ اور وقت',
        'actions' => 'اعمال',
        'edit' => 'ترمیم',
        'reverse' => 'منسوخ',
        'view' => 'دیکھیں',
        'transactions' => 'ٹرانزیکشنز',
        'no_records' => 'آپ کے فلٹرز سے ملتی جلتی کوئی ٹرانزیکشن نہیں ملی',
        'positive_balance' => 'مثبت بیلنس',
        'last_updated' => 'آخری اپ ڈیٹ',
        'today' => 'آج',
        'fee_collected' => 'فیس جمع',
        'fee_correction' => 'فیس درستگی',
        'advance_payment' => 'پیشگی ادائیگی',
        'expense_paid' => 'خرچہ ادا کیا',
        'salary_paid' => 'تنخواہ ادا کی',
        'updated_salary' => 'اپڈیٹ شدہ تنخواہ',
        'fee_restore' => 'فیس بحال',
        'advance_restore' => 'پیشگی بحال',
        'expense_deletion' => 'خرچہ بحال',
        'expense_edit' => 'خرچہ ایڈجسٹ',
        'edited' => 'ترمیم شدہ',
        'reversed' => 'منسوخ شدہ',
        'final_entry' => 'حتمی اندراج',
        'advance_fee' => 'پیشگی فیس',
        'salary_new' => 'نئی تنخواہ',
        'fee_edit_reversal' => 'فیس منسوخی',
        'advance_edit_reversal' => 'پیشگی منسوخی',
        'advance_restore_reversal' => 'پیشگی بحالی منسوخی',
        'salary_edit_reversal' => 'تنخواہ منسوخی',
        'salary_edit_new' => 'تنخواہ ایڈجسٹمنٹ',
        'expense_deletion_restore' => 'خرچہ بحالی',
        'invalid_csrf' => 'غلط سیکیورٹی ٹوکن',
        'reverse_success' => 'ٹرانزیکشن کامیابی سے منسوخ ہوگئی!',
        'reverse_error' => 'ٹرانزیکشن منسوخ کرنے میں خرابی',
        'filter' => 'فلٹر',
        'clear' => 'صاف کریں',
        'apply' => 'لاگو کریں',
        'from' => 'سے',
        'to' => 'تک',
        'all_categories' => 'تمام کیٹیگریز',
        'cancel' => 'منسوخ',
        'close' => 'بند کریں'
    ]
];

// Create database connection
require_once('conn_inc.php');

// Helper function to clean type value for CSS classes
function getTypeClass($type) {
    $clean_type = str_replace(' ', '_', strtolower(trim($type)));
    return $clean_type;
}

// Helper function to get standardized display type
function getStandardType($type) {
    if (empty($type)) return '';
    $type_lower = strtolower(trim($type));
    if (in_array($type_lower, ['cash_in', 'cash in'])) return 'cash_in';
    if (in_array($type_lower, ['cash_out', 'cash out'])) return 'cash_out';
    return '';
}

// Helper function to get readable category label
function getCategoryLabel($ref_type, $lang) {
    global $translations;
    $labels = [
        'fee' => 'fee_collected',
        'fee_edit_reversal' => 'fee_correction',
        'fee_restore_reversal' => 'fee_restore',
        'advance_fee' => 'advance_fee',
        'advance_edit_reversal' => 'advance_edit_reversal',
        'advance_restore_reversal' => 'advance_restore',
        'expense' => 'expense_paid',
        'expense_deletion' => 'expense_deletion',
        'expense_edit' => 'expense_edit',
        'salary_new' => 'salary_new',
        'salary_edit_reversal' => 'salary_edit_reversal',
        'salary_edit_new' => 'salary_edit_new'
    ];
    $key = $labels[$ref_type] ?? $ref_type;
    return $translations[$lang][$key] ?? ucfirst(str_replace('_', ' ', $ref_type));
}

// Get transaction status badge
function getTransactionStatus($ref_type, $lang) {
    if (strpos($ref_type, 'reversal') !== false) {
        return '<span class="status-badge reversal"><i class="fas fa-undo"></i> ' . 
               ($lang == 'ur' ? 'منسوخ' : 'Reversed') . '</span>';
    }
    if (strpos($ref_type, 'edit') !== false || strpos($ref_type, 'correction') !== false) {
        return '<span class="status-badge edited"><i class="fas fa-pen"></i> ' . 
               ($lang == 'ur' ? 'ترمیم' : 'Edited') . '</span>';
    }
    return '<span class="status-badge final"><i class="fas fa-check"></i> ' . 
           ($lang == 'ur' ? 'حتمی' : 'Final') . '</span>';
}

// Get master account balance
$master_result = $conn->query("SELECT id, title, balance, created_at FROM master_account ORDER BY id DESC LIMIT 1");
$master_balance = 0;
$master_title = '';
$last_updated = '';
if ($master_result && $master_result->num_rows > 0) {
    $row = $master_result->fetch_assoc();
    $master_balance = floatval($row['balance']);
    $master_title = htmlspecialchars($row['title']);
    $last_updated = $row['created_at'];
}
$master_result->close();

// Calculate totals efficiently
$totals_query = $conn->query("SELECT 
    SUM(CASE WHEN LOWER(TRIM(type)) IN ('cash_in', 'cash in') THEN amount ELSE 0 END) as cash_in,
    SUM(CASE WHEN LOWER(TRIM(type)) IN ('cash_out', 'cash out') THEN amount ELSE 0 END) as cash_out,
    COUNT(CASE WHEN DATE(transaction_date) = CURDATE() THEN 1 END) as today_count
    FROM detail_account");
    
if ($totals_query && $totals_query->num_rows > 0) {
    $totals = $totals_query->fetch_assoc();
    $total_cash_in = floatval($totals['cash_in'] ?? 0);
    $total_cash_out = floatval($totals['cash_out'] ?? 0);
    $today_count = intval($totals['today_count'] ?? 0);
}
$totals_query->close();

// Handle reversal
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reverse_id'])) {
    $reverse_id = intval($_POST['reverse_id']);
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if ($csrf_token !== $_SESSION['csrf_token']) {
        $_SESSION['message'] = $translations[$lang]['invalid_csrf'];
        $_SESSION['message_type'] = 'danger';
    } else {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $conn->begin_transaction();
        
        try {
            $orig_query = $conn->prepare("SELECT amount, type, ref_id, ref_type, master_account_id FROM detail_account WHERE id = ?");
            $orig_query->bind_param("i", $reverse_id);
            $orig_query->execute();
            $orig_result = $orig_query->get_result();
            
            if ($orig_result->num_rows > 0) {
                $orig = $orig_result->fetch_assoc();
                $orig_type_std = getStandardType($orig['type']);
                $reverse_type = ($orig_type_std == 'cash_in') ? 'cash_out' : 'cash_in';
                $reverse_ref_type = $orig['ref_type'] . '_reversal';
                
                $master_query = $conn->query("SELECT balance FROM master_account WHERE id = 1");
                $current_balance = floatval($master_query->fetch_assoc()['balance']);
                $master_query->close();
                
                $new_balance = ($reverse_type == 'cash_in') ? $current_balance + $orig['amount'] : $current_balance - $orig['amount'];
                
                $insert = $conn->prepare("INSERT INTO detail_account (master_account_id, type, amount, balance, ref_id, ref_type, transaction_date, created_at) 
                                          VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $insert->bind_param("isddss", $orig['master_account_id'], $reverse_type, $orig['amount'], $new_balance, $orig['ref_id'], $reverse_ref_type);
                
                if ($insert->execute()) {
                    $update_master = $conn->prepare("UPDATE master_account SET balance = ?, created_at = NOW() WHERE id = 1");
                    $update_master->bind_param("d", $new_balance);
                    $update_master->execute();
                    $update_master->close();
                    
                    $conn->commit();
                    $_SESSION['message'] = $translations[$lang]['reverse_success'];
                    $_SESSION['message_type'] = 'success';
                } else {
                    throw new Exception("Failed to insert reversal");
                }
            } else {
                throw new Exception("Original transaction not found");
            }
            $orig_query->close();
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Reversal error: " . $e->getMessage());
            $_SESSION['message'] = $translations[$lang]['reverse_error'];
            $_SESSION['message_type'] = 'danger';
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Build filtered transactions query - IMPROVED FILTERING LOGIC
$where_conditions = [];
$params = [];
$types = "";

// Search filter
if (!empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $where_conditions[] = "(ref_id LIKE ? OR ref_type LIKE ? OR type LIKE ?)";
    $params[] = $search; $params[] = $search; $params[] = $search;
    $types .= "sss";
}

// Type filter (cash_in/cash_out)
if (!empty($_GET['type']) && $_GET['type'] != 'all') {
    $where_conditions[] = "LOWER(TRIM(type)) = ?";
    $params[] = strtolower(trim($_GET['type']));
    $types .= "s";
}

// Category filter (ref_type)
if (!empty($_GET['category_filter']) && $_GET['category_filter'] != 'all') {
    $where_conditions[] = "ref_type = ?";
    $params[] = $_GET['category_filter'];
    $types .= "s";
}

// Date range filters
if (!empty($_GET['date_from'])) {
    $where_conditions[] = "DATE(transaction_date) >= ?";
    $params[] = $_GET['date_from'];
    $types .= "s";
}

if (!empty($_GET['date_to'])) {
    $where_conditions[] = "DATE(transaction_date) <= ?";
    $params[] = $_GET['date_to'];
    $types .= "s";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
$query = "SELECT id, master_account_id, type, amount, balance, ref_id, ref_type, transaction_date, created_at 
          FROM detail_account $where_clause ORDER BY created_at DESC LIMIT 500";

$stmt = $conn->prepare($query);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$transactions = $stmt->get_result();

// Get unique ref_types for filter dropdown
$ref_types_result = $conn->query("SELECT DISTINCT ref_type FROM detail_account ORDER BY ref_type");
$ref_types = [];
while ($row = $ref_types_result->fetch_assoc()) $ref_types[] = $row['ref_type'];
$ref_types_result->close();
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" dir="<?php echo $lang == 'ur' ? 'rtl' : 'ltr'; ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $translations[$lang]['dashboard']; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --success: #10b981;
            --success-light: #d1fae5;
            --danger: #ef4444;
            --danger-light: #fee2e2;
            --warning: #f59e0b;
            --warning-light: #fef3c7;
            --info: #3b82f6;
            --info-light: #dbeafe;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            --shadow-sm: 0 1px 2px 0 rgba(0,0,0,0.05);
            --shadow: 0 1px 3px 0 rgba(0,0,0,0.1), 0 1px 2px 0 rgba(0,0,0,0.06);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);
            --radius: 12px;
            --radius-lg: 16px;
            --transition: all 0.2s ease;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #f0f4ff 0%, #f9fafb 100%);
            color: var(--gray-800);
            line-height: 1.5;
            min-height: 100vh;
            padding: 16px;
        }
        
        @media (min-width: 768px) { body { padding: 24px; } }
        
        .dashboard { max-width: 1400px; margin: 0 auto; }
        
        /* Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
        }
        
        /* Print Button - Replaces language switcher */
        .print-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: white;
            border: 1px solid var(--gray-300);
            border-radius: 10px;
            font-weight: 500;
            font-size: 14px;
            color: var(--gray-700);
            cursor: pointer;
            transition: var(--transition);
            box-shadow: var(--shadow);
        }
        .print-btn:hover {
            background: var(--gray-50);
            border-color: var(--gray-400);
            transform: translateY(-1px);
        }
        .print-btn i { color: var(--primary); }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 20px;
            box-shadow: var(--shadow-md);
            transition: var(--transition);
            border: 1px solid var(--gray-100);
        }
        
        .stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); }
        
        .stat-card.main {
            grid-column: span 2;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }
        
        .stat-card.main .stat-label { color: rgba(255,255,255,0.9); }
        .stat-card.main .stat-value { color: white; }
        .stat-card.main .stat-meta { color: rgba(255,255,255,0.8); }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }
        
        .stat-label {
            font-size: 13px;
            font-weight: 500;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }
        
        .stat-card.main .stat-icon { background: rgba(255,255,255,0.2); }
        .stat-card:nth-child(2) .stat-icon { background: var(--success-light); color: var(--success); }
        .stat-card:nth-child(3) .stat-icon { background: var(--danger-light); color: var(--danger); }
        .stat-card:nth-child(4) .stat-icon { background: var(--info-light); color: var(--info); }
        
        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .stat-value.positive { color: var(--success); }
        .stat-value.negative { color: var(--danger); }
        
        .stat-meta {
            font-size: 12px;
            color: var(--gray-500);
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        /* Filters Toolbar */
        .toolbar {
            background: white;
            border-radius: var(--radius-lg);
            padding: 16px 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-100);
        }
        
        .filters {
            display: flex;
            flex-wrap: wrap;
            gap: 12px 16px;
            align-items: flex-end;
        }
        
        .filter-field {
            flex: 1;
            min-width: 140px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        
        .filter-field label {
            font-size: 12px;
            font-weight: 500;
            color: var(--gray-600);
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .filter-field input,
        .filter-field select {
            padding: 10px 14px;
            border: 1px solid var(--gray-200);
            border-radius: 10px;
            font-size: 14px;
            transition: var(--transition);
            background: var(--gray-50);
        }
        
        .filter-field input:focus,
        .filter-field select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
            background: white;
        }
        
        .filter-actions {
            display: flex;
            gap: 8px;
            margin-left: auto;
        }
        
        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: 10px;
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: var(--transition);
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        .btn-primary:hover { background: var(--primary-dark); }
        
        .btn-outline {
            background: white;
            color: var(--gray-700);
            border: 1px solid var(--gray-300);
        }
        .btn-outline:hover {
            background: var(--gray-50);
            border-color: var(--gray-400);
        }
        
        .btn-export {
            background: linear-gradient(135deg, var(--success), #059669);
            color: white;
        }
        .btn-export:hover { filter: brightness(1.05); }
        
        /* Table Container */
        .table-container {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-100);
            overflow: hidden;
        }
        
        .table-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .table-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--gray-800);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .table-count {
            background: var(--gray-100);
            color: var(--gray-600);
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        thead {
            background: var(--gray-50);
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        th {
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray-500);
            border-bottom: 1px solid var(--gray-200);
            white-space: nowrap;
        }
        
        th:first-child { border-top-left-radius: 8px; }
        th:last-child { border-top-right-radius: 8px; }
        
        td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--gray-100);
            vertical-align: middle;
        }
        
        tbody tr {
            transition: background 0.15s ease;
        }
        
        tbody tr:hover {
            background: var(--gray-50);
        }
        
        tbody tr:last-child td { border-bottom: none; }
        
        /* Row States */
        .row-reversal { background: var(--warning-light) !important; }
        .row-edited { background: var(--info-light) !important; }
        
        /* Badges */
        .type-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .type-badge.cash-in {
            background: var(--success-light);
            color: var(--success);
        }
        
        .type-badge.cash-out {
            background: var(--danger-light);
            color: var(--danger);
        }
        
        .amount {
            font-weight: 600;
            font-family: 'SF Mono', 'Monaco', monospace;
        }
        
        .amount.positive { color: var(--success); }
        .amount.negative { color: var(--danger); }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .status-badge.final {
            background: var(--success-light);
            color: var(--success);
        }
        
        .status-badge.edited {
            background: var(--info-light);
            color: var(--info);
        }
        
        .status-badge.reversal {
            background: var(--warning-light);
            color: var(--warning);
        }
        
        /* Actions */
        .actions {
            display: flex;
            gap: 6px;
        }
        
        .action-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
            font-size: 13px;
        }
        
        .action-btn.edit {
            background: var(--info-light);
            color: var(--info);
        }
        .action-btn.edit:hover { background: var(--info); color: white; }
        
        .action-btn.reverse {
            background: var(--warning-light);
            color: var(--warning);
        }
        .action-btn.reverse:hover { background: var(--warning); color: white; }
        
        .action-btn.view {
            background: var(--gray-100);
            color: var(--gray-700);
        }
        .action-btn.view:hover { background: var(--gray-200); }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: var(--gray-500);
        }
        
        .empty-state i {
            font-size: 48px;
            color: var(--gray-300);
            margin-bottom: 16px;
        }
        
        .empty-state p {
            font-size: 15px;
            margin-top: 8px;
        }
        
        /* Alerts */
        .alert {
            padding: 14px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideDown 0.3s ease;
        }
        
        .alert-success {
            background: var(--success-light);
            color: #065f46;
            border-left: 4px solid var(--success);
        }
        
        .alert-danger {
            background: var(--danger-light);
            color: #991b1b;
            border-left: 4px solid var(--danger);
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        
        .modal.active { display: flex; }
        
        .modal-content {
            background: white;
            border-radius: var(--radius-lg);
            width: 100%;
            max-width: 480px;
            box-shadow: var(--shadow-lg);
            animation: modalIn 0.2s ease;
        }
        
        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
        
        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--gray-800);
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 20px;
            color: var(--gray-400);
            cursor: pointer;
            padding: 4px;
            border-radius: 6px;
            transition: var(--transition);
        }
        .modal-close:hover { background: var(--gray-100); color: var(--gray-600); }
        
        .modal-body { padding: 20px; }
        
        .modal-footer {
            padding: 16px 20px 20px;
            border-top: 1px solid var(--gray-100);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        /* RTL Support */
        [dir="rtl"] {
            text-align: right;
        }
        
        [dir="rtl"] th,
        [dir="rtl"] td {
            text-align: right;
        }
        
        [dir="rtl"] .filter-actions {
            margin-left: 0;
            margin-right: auto;
        }
        
        [dir="rtl"] .actions {
            flex-direction: row-reverse;
        }
        
        [dir="rtl"] .stat-header {
            flex-direction: row-reverse;
        }
        
        /* Print Styles - Hide elements not needed in print */
        @media print {
            body {
                background: white;
                padding: 0;
                color: #000;
            }
            
            .page-header .print-btn,
            .toolbar,
            .actions,
            .stat-card:not(.main),
            .modal,
            .alert {
                display: none !important;
            }
            
            .dashboard {
                max-width: 100%;
                margin: 0;
            }
            
            .stat-card.main {
                grid-column: span 1;
                background: white;
                color: #000;
                border: 1px solid #ddd;
                box-shadow: none;
            }
            
            .stat-card.main .stat-label,
            .stat-card.main .stat-value,
            .stat-card.main .stat-meta {
                color: #000;
            }
            
            .stat-card.main .stat-icon {
                background: #f3f4f6;
            }
            
            table {
                font-size: 11px;
            }
            
            th, td {
                padding: 8px 10px;
            }
            
            .table-container {
                box-shadow: none;
                border: 1px solid #ddd;
            }
            
            .type-badge, .status-badge {
                border: 1px solid #ccc;
                background: #f9fafb !important;
                color: #000 !important;
            }
            
            .amount.positive { color: #059669 !important; }
            .amount.negative { color: #dc2626 !important; }
            
            .page-title {
                font-size: 1.25rem;
                margin-bottom: 16px;
            }
            
            /* Show print header */
            .print-header {
                display: block !important;
                text-align: center;
                margin-bottom: 20px;
                padding-bottom: 10px;
                border-bottom: 2px solid #000;
            }
        }
        
        .print-header {
            display: none;
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--gray-300);
        }
        
        .print-header h2 {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 4px;
        }
        
        .print-header p {
            font-size: 13px;
            color: var(--gray-500);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr; }
            .stat-card.main { grid-column: span 1; }
            
            .filters { flex-direction: column; align-items: stretch; }
            .filter-field { min-width: 100%; }
            .filter-actions { width: 100%; justify-content: space-between; }
            
            th, td { padding: 12px 14px; font-size: 13px; }
            .action-btn { width: 30px; height: 30px; font-size: 12px; }
            
            .page-header { flex-direction: column; align-items: flex-start; }
            .print-btn { width: 100%; justify-content: center; }
        }
        
        @media (max-width: 480px) {
            body { padding: 12px; }
            .stat-value { font-size: 1.5rem; }
            .btn { padding: 9px 14px; font-size: 13px; }
            .filter-field input, .filter-field select { padding: 9px 12px; }
        }
    </style>
</head>
<body>
<div class="dashboard">
    <!-- Header with Print Button (replaces language switcher) -->
    <div class="page-header">
        <h1 class="page-title"><?php echo $translations[$lang]['dashboard']; ?></h1>
        <!-- Print button links to separate thermal print page -->
        <a href="print_transactions.php?<?php echo $filter_query; ?>&lang=<?php echo $lang; ?>" 
           class="print-btn" target="_blank">
            <i class="fas fa-print"></i>
            <?php echo $translations[$lang]['print']; ?>
        </a>
    </div>
    
    <!-- Print-only Header -->
    <div class="print-header">
        <h2><?php echo $translations[$lang]['dashboard']; ?></h2>
        <p><?php echo $translations[$lang]['last_updated']; ?>: <?php echo date('d M Y, h:i A', strtotime($last_updated)); ?></p>
    </div>
    
    <!-- Alerts -->
    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert alert-<?php echo $_SESSION['message_type']; ?>">
            <i class="fas fa-<?php echo $_SESSION['message_type'] == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <?php 
            echo htmlspecialchars($_SESSION['message']);
            unset($_SESSION['message'], $_SESSION['message_type']);
            ?>
        </div>
    <?php endif; ?>
    
    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card main">
            <div class="stat-header">
                <span class="stat-label"><?php echo $translations[$lang]['master_balance']; ?></span>
                <div class="stat-icon"><i class="fas fa-wallet"></i></div>
            </div>
            <div class="stat-value <?php echo $master_balance >= 0 ? 'positive' : 'negative'; ?>">
                Rs. <?php echo number_format($master_balance, 2); ?>
            </div>
            <div class="stat-meta">
                <i class="fas fa-clock"></i>
                <?php echo $translations[$lang]['last_updated']; ?>: 
                <?php echo date('d M Y, h:i A', strtotime($last_updated)); ?>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label"><?php echo $translations[$lang]['total_cash_in']; ?></span>
                <div class="stat-icon"><i class="fas fa-arrow-up"></i></div>
            </div>
            <div class="stat-value positive">Rs. <?php echo number_format($total_cash_in, 2); ?></div>
            <div class="stat-meta">All time incoming</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label"><?php echo $translations[$lang]['total_cash_out']; ?></span>
                <div class="stat-icon"><i class="fas fa-arrow-down"></i></div>
            </div>
            <div class="stat-value negative">Rs. <?php echo number_format($total_cash_out, 2); ?></div>
            <div class="stat-meta">All time outgoing</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label"><?php echo $translations[$lang]['today_activity']; ?></span>
                <div class="stat-icon"><i class="fas fa-calendar-day"></i></div>
            </div>
            <div class="stat-value"><?php echo $today_count; ?></div>
            <div class="stat-meta">Transactions today</div>
        </div>
    </div>
    
    <!-- Filters Toolbar -->
    <div class="toolbar">
        <form method="GET" class="filters" id="filterForm">
            <div class="filter-field">
                <label><i class="fas fa-search"></i> <?php echo $translations[$lang]['search']; ?></label>
                <input type="text" name="search" placeholder="Ref ID or type..." 
                       value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
            </div>
            
            <div class="filter-field">
                <label><i class="fas fa-calendar"></i> <?php echo $translations[$lang]['from']; ?></label>
                <input type="date" name="date_from" value="<?php echo $_GET['date_from'] ?? ''; ?>">
            </div>
            
            <div class="filter-field">
                <label><i class="fas fa-calendar"></i> <?php echo $translations[$lang]['to']; ?></label>
                <input type="date" name="date_to" value="<?php echo $_GET['date_to'] ?? ''; ?>">
            </div>
            
            <div class="filter-field">
                <label><i class="fas fa-filter"></i> <?php echo $translations[$lang]['type']; ?></label>
                <select name="type">
                    <option value="all"><?php echo $translations[$lang]['all']; ?></option>
                    <option value="cash_in" <?php echo (($_GET['type'] ?? '') == 'cash_in') ? 'selected' : ''; ?>>
                        <?php echo $translations[$lang]['cash_in']; ?>
                    </option>
                    <option value="cash_out" <?php echo (($_GET['type'] ?? '') == 'cash_out') ? 'selected' : ''; ?>>
                        <?php echo $translations[$lang]['cash_out']; ?>
                    </option>
                </select>
            </div>
            
            <div class="filter-field">
                <label><i class="fas fa-tags"></i> <?php echo $translations[$lang]['category']; ?></label>
                <select name="category_filter">
                    <option value="all"><?php echo $translations[$lang]['all_categories']; ?></option>
                    <?php foreach ($ref_types as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat); ?>" 
                            <?php echo (($_GET['category_filter'] ?? '') == $cat) ? 'selected' : ''; ?>>
                            <?php echo getCategoryLabel($cat, $lang); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i> <?php echo $translations[$lang]['apply']; ?>
                </button>
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-outline">
                    <i class="fas fa-times"></i> <?php echo $translations[$lang]['clear']; ?>
                </a>
                <button type="button" class="btn btn-export" onclick="exportToExcel()">
                    <i class="fas fa-file-excel"></i> <?php echo $translations[$lang]['export']; ?>
                </button>
            </div>
        </form>
    </div>
    
    <!-- Transactions Table -->
    <div class="table-container">
        <div class="table-header">
            <div class="table-title">
                <i class="fas fa-list"></i>
                <?php echo $translations[$lang]['transactions']; ?>
                <span class="table-count"><?php echo $transactions->num_rows; ?></span>
            </div>
        </div>
        
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th><?php echo $translations[$lang]['id']; ?></th>
                        <th><?php echo $translations[$lang]['type']; ?></th>
                        <th><?php echo $translations[$lang]['amount']; ?></th>
                        <th><?php echo $translations[$lang]['running_balance']; ?></th>
                        <th><?php echo $translations[$lang]['category']; ?></th>
                        <th><?php echo $translations[$lang]['reference_id']; ?></th>
                        <th><?php echo $translations[$lang]['date_time']; ?></th>
                        <th><?php echo $translations[$lang]['actions']; ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($transactions->num_rows > 0): ?>
                    <?php while ($row = $transactions->fetch_assoc()): 
                        $amount = floatval($row['amount']);
                        $type_std = getStandardType($row['type']) ?: ($amount >= 0 ? 'cash_in' : 'cash_out');
                        $row_class = '';
                        if (strpos($row['ref_type'], 'reversal') !== false) $row_class = 'row-reversal';
                        elseif (strpos($row['ref_type'], 'edit') !== false) $row_class = 'row-edited';
                    ?>
                    <tr class="<?php echo $row_class; ?>">
                        <td><strong>#<?php echo $row['id']; ?></strong></td>
                        <td>
                            <span class="type-badge <?php echo $type_std; ?>">
                                <i class="fas fa-<?php echo $type_std == 'cash_in' ? 'arrow-up' : 'arrow-down'; ?>"></i>
                                <?php echo $translations[$lang][$type_std] ?? ucfirst(str_replace('_', ' ', $type_std)); ?>
                            </span>
                        </td>
                        <td class="amount <?php echo $type_std == 'cash_in' ? 'positive' : 'negative'; ?>">
                            <?php echo ($type_std == 'cash_in' ? '+' : '-'); ?>Rs. <?php echo number_format(abs($amount), 2); ?>
                        </td>
                        <td class="amount">Rs. <?php echo number_format($row['balance'], 2); ?></td>
                        <td><?php echo getCategoryLabel($row['ref_type'], $lang); ?></td>
                        <td><?php echo htmlspecialchars($row['ref_id']); ?></td>
                        <td><?php echo date('d M Y, h:i A', strtotime($row['transaction_date'])); ?></td>
                        <td>
                            <div class="actions">
                                <button class="action-btn view" onclick="viewTransaction(<?php echo $row['id']; ?>)" title="<?php echo $translations[$lang]['view']; ?>">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <a href="edit_transaction.php?id=<?php echo $row['id']; ?>" class="action-btn edit" title="<?php echo $translations[$lang]['edit']; ?>">
                                    <i class="fas fa-pen"></i>
                                </a>
                                <button class="action-btn reverse" onclick="showReverseModal(<?php echo $row['id']; ?>)" title="<?php echo $translations[$lang]['reverse']; ?>">
                                    <i class="fas fa-undo"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <p><?php echo $translations[$lang]['no_records']; ?></p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Reverse Confirmation Modal -->
<div id="reverseModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-undo"></i> <?php echo $translations[$lang]['reverse']; ?></h3>
            <button class="modal-close" onclick="closeModal('reverseModal')">&times;</button>
        </div>
        <div class="modal-body">
            <p style="margin-bottom: 16px;">
                <?php echo $lang == 'ur' ? 
                    'کیا آپ واقعی اس ٹرانزیکشن کو منسوخ کرنا چاہتے ہیں؟ یہ ایک نیا الٹ اندراج بنائے گا اور ماسٹر بیلنس اپ ڈیٹ کرے گا۔' : 
                    'Are you sure you want to reverse this transaction? This will create a reversal entry and update the master balance.'; ?>
            </p>
            <div style="background: var(--warning-light); padding: 12px; border-radius: 8px; border-left: 3px solid var(--warning);">
                <small style="color: var(--warning);">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo $lang == 'ur' ? 'یہ عمل ناقابل واپسی ہے' : 'This action cannot be undone'; ?>
                </small>
            </div>
        </div>
        <div class="modal-footer">
            <form method="POST" id="reverseForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="reverse_id" id="reverse_id" value="">
                <button type="button" class="btn btn-outline" onclick="closeModal('reverseModal')">
                    <?php echo $translations[$lang]['cancel'] ?? 'Cancel'; ?>
                </button>
                <button type="submit" class="btn" style="background: var(--warning); color: white;">
                    <i class="fas fa-undo"></i> <?php echo $translations[$lang]['reverse']; ?>
                </button>
            </form>
        </div>
    </div>
</div>

<!-- View Transaction Modal -->
<div id="viewModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-info-circle"></i> <?php echo $translations[$lang]['view']; ?></h3>
            <button class="modal-close" onclick="closeModal('viewModal')">&times;</button>
        </div>
        <div class="modal-body" id="viewModalBody" style="min-height: 150px;">
            <div style="text-align: center; padding: 24px; color: var(--gray-500);">
                <i class="fas fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 12px;"></i>
                <p>Loading transaction details...</p>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('viewModal')">
                <?php echo $translations[$lang]['close'] ?? 'Close'; ?>
            </button>
        </div>
    </div>
</div>

<script>
// Auto-dismiss alerts after 5 seconds
document.querySelectorAll('.alert').forEach(alert => {
    setTimeout(() => {
        alert.style.opacity = '0';
        alert.style.transform = 'translateY(-10px)';
        setTimeout(() => alert.remove(), 300);
    }, 5000);
});

// Modal functions
function showModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

function showReverseModal(transactionId) {
    document.getElementById('reverse_id').value = transactionId;
    showModal('reverseModal');
}

// View transaction details via AJAX
function viewTransaction(id) {
    showModal('viewModal');
    const modalBody = document.getElementById('viewModalBody');
    
    fetch(`get_transaction.php?id=${id}`)
        .then(response => response.text())
        .then(html => { modalBody.innerHTML = html; })
        .catch(() => {
            modalBody.innerHTML = `<p style="color: var(--danger); text-align: center;">
                <i class="fas fa-exclamation-triangle"></i> Error loading details
            </p>`;
        });
}

// Export to Excel
function exportToExcel() {
    const params = new URLSearchParams(new FormData(document.getElementById('filterForm')));
    window.location.href = `export_transactions.php?${params.toString()}`;
}

// Close modals when clicking outside
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', (e) => {
        if (e.target === modal) modal.classList.remove('active');
    });
});

// Close modals with Escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal.active').forEach(modal => {
            modal.classList.remove('active');
        });
    }
});

// Enhanced filter form submission with validation
document.getElementById('filterForm').addEventListener('submit', function(e) {
    const dateFrom = this.querySelector('input[name="date_from"]').value;
    const dateTo = this.querySelector('input[name="date_to"]').value;
    
    if (dateFrom && dateTo && new Date(dateFrom) > new Date(dateTo)) {
        e.preventDefault();
        alert('<?php echo $lang == "ur" ? "تاریخ شروع، تاریخ اختتام سے بعد نہیں ہو سکتی" : "From date cannot be after To date"; ?>');
        return false;
    }
});
</script>
</body>
</html>
<?php $conn->close(); ?>