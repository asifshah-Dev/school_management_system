<?php
// Include security file to enforce authentication and authorization
require_once('security.php');

// Start session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Handle language selection from GET parameter or set default
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'ur'])) {
    $_SESSION['lang'] = $_GET['lang']; // Set session language
} elseif (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = 'en'; // Default to English if no language set
}

// Store selected language
$lang = $_SESSION['lang'];

// Define translations for English and Urdu
$translations = [
    'en' => [
        'title' => 'Make Payment',
        'amount' => 'Amount',
        'description' => 'Description',
        'transaction_date' => 'Transaction Date',
        'payment_button' => 'Make Payment',
        'reset' => 'Reset',
        'success' => 'Payment recorded successfully!',
        'error' => 'Error: ',
        'empty_error' => 'Required fields cannot be empty or invalid!',
        'insufficient_balance' => 'Payment amount exceeds invoice balance!',
        'account_details' => 'Account Details',
        'account_id' => 'ID',
        'account_title' => 'Title',
        'account_type' => 'Type',
        'account_balance' => 'Balance',
        'account_mobile' => 'Mobile No',
        'no_account' => 'No account found for the provided ID.',
        'pending_invoices' => 'Pending Expense Invoices',
        'invoice_id' => 'Invoice ID',
        'total' => 'Total Amount',
        'paid' => 'Paid Amount',
        'remaining' => 'Balance Amount',
        'total_payment' => 'Total Payment'
    ],
    'ur' => [
        'title' => 'ادائیگی کریں',
        'amount' => 'رقم',
        'description' => 'تفصیل',
        'transaction_date' => 'ٹرانزیکشن کی تاریخ',
        'payment_button' => 'ادائیگی کریں',
        'reset' => 'دوبارہ ترتیب دیں',
        'success' => 'ادائیگی کامیابی سے ریکارڈ ہو گئی!',
        'error' => 'خرابی: ',
        'empty_error' => 'ضروری فیلڈز خالی یا غلط نہیں ہو سکتے!',
        'insufficient_balance' => 'ادائیگی کی رقم انوائس کے بیلنس سے زیادہ ہے!',
        'account_details' => 'اکاؤنٹ کی تفصیلات',
        'account_id' => 'آئی ڈی',
        'account_title' => 'عنوان',
        'account_type' => 'قسم',
        'account_balance' => 'بیلنس',
        'account_mobile' => 'موبائل نمبر',
        'no_account' => 'دیے گئے آئی ڈی کے لیے کوئی اکاؤنٹ نہیں ملا۔',
        'pending_invoices' => 'زیر التواء اخراجات کے انوائسز',
        'invoice_id' => 'انوائس آئی ڈی',
        'total' => 'کل رقم',
        'paid' => 'ادائیگی شدہ رقم',
        'remaining' => 'باقی رقم',
        'total_payment' => 'کل ادائیگی'
    ]
];

// Include database connection
require_once('conn_inc.php');

// Check for database connection errors
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error, 3, 'error_log.txt');
    die("Connection failed: " . $conn->connect_error);
}

// Validate and sanitize account_id from GET parameter
$account_id = isset($_GET['account_id']) ? intval($_GET['account_id']) : 0;

// Redirect if account_id is invalid
if ($account_id <= 0) {
    error_log("Invalid account ID: $account_id", 3, 'error_log.txt');
    $_SESSION['message'] = $translations[$lang]['no_account'];
    $_SESSION['message_type'] = 'danger';
    header("Location: account_search.php");
    exit();
}

// Fetch account details from database
$account = null;
$stmt = $conn->prepare("SELECT id, title, type, balance, mobile_no FROM accounts WHERE id = ?");
if (!$stmt) {
    error_log("Prepare failed for account fetch: " . $conn->error, 3, 'error_log.txt');
    $_SESSION['message'] = $translations[$lang]['error'] . "Database error";
    $_SESSION['message_type'] = 'danger';
    header("Location: account_search.php");
    exit();
}
$stmt->bind_param("i", $account_id);
if (!$stmt->execute()) {
    error_log("Error fetching account ID $account_id: " . $stmt->error, 3, 'error_log.txt');
    $_SESSION['message'] = $translations[$lang]['error'] . "Database error";
    $_SESSION['message_type'] = 'danger';
    header("Location: account_search.php");
    exit();
}
$result = $stmt->get_result();
if ($result->num_rows === 1) {
    $account = $result->fetch_assoc(); // Store account details
} else {
    error_log("No account found for ID $account_id", 3, 'error_log.txt');
    $_SESSION['message'] = $translations[$lang]['no_account'];
    $_SESSION['message_type'] = 'danger';
    header("Location: account_search.php");
    exit();
}
$stmt->close();

// Fetch pending invoices for the account
$invoices = [];
$stmt = $conn->prepare("SELECT id, invoice_date, total_amount, paid_amount, balance_amount 
                        FROM expenses_master 
                        WHERE from_account_id = ? AND balance_amount > 0 
                        ORDER BY invoice_date ASC");
if (!$stmt) {
    error_log("Prepare failed for invoices fetch: " . $conn->error, 3, 'error_log.txt');
    $_SESSION['message'] = $translations[$lang]['error'] . "Database error";
    $_SESSION['message_type'] = 'danger';
    header("Location: account_search.php?account_id=$account_id");
    exit();
}
$stmt->bind_param("i", $account_id);
if (!$stmt->execute()) {
    error_log("Error fetching invoices for account ID $account_id: " . $stmt->error, 3, 'error_log.txt');
    $_SESSION['message'] = $translations[$lang]['error'] . "Database error";
    $_SESSION['message_type'] = 'danger';
    header("Location: account_search.php?account_id=$account_id");
    exit();
}
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $invoices[] = $row; // Store each invoice
}
$stmt->close();

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Ensure account exists
    if (!$account) {
        error_log("No account found for processing", 3, 'error_log.txt');
        $_SESSION['message'] = $translations[$lang]['no_account'];
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF'] . "?account_id=$account_id");
        exit();
    }

    // Validate invoices data
    if (empty($_POST['invoices']) || !is_array($_POST['invoices'])) {
        error_log("No invoices data in POST: " . json_encode($_POST), 3, 'error_log.txt');
        $_SESSION['message'] = $translations[$lang]['empty_error'];
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF'] . "?account_id=$account_id");
        exit();
    }

    // Sanitize form inputs
    $invoices_data = $_POST['invoices'];
    $description = isset($_POST['description']) ? trim($conn->real_escape_string($_POST['description'])) : '';
    $transaction_date = isset($_POST['transaction_date']) ? trim($conn->real_escape_string($_POST['transaction_date'])) : '';

    // Validate transaction date format
    if (empty($transaction_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $transaction_date)) {
        error_log("Invalid or missing transaction date: $transaction_date", 3, 'error_log.txt');
        $_SESSION['message'] = $translations[$lang]['empty_error'];
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF'] . "?account_id=$account_id");
        exit();
    }

    // Validate payment amounts
    $valid = true;
    $has_valid_payment = false;
    $total_payment = 0;
    foreach ($invoices_data as $invoice_id => $payment_amount) {
        $invoice_id = intval($invoice_id); // Sanitize invoice ID
        $payment_amount = floatval($payment_amount); // Sanitize payment amount

        // Verify invoice exists
        $invoice_exists = false;
        foreach ($invoices as $invoice) {
            if ($invoice['id'] == $invoice_id) {
                $invoice_exists = true;
                if ($payment_amount > $invoice['balance_amount']) {
                    error_log("Invalid payment amount for invoice $invoice_id: $payment_amount exceeds balance {$invoice['balance_amount']}", 3, 'error_log.txt');
                    $valid = false;
                    $_SESSION['message'] = $translations[$lang]['insufficient_balance'];
                    $_SESSION['message_type'] = 'danger';
                    break;
                }
                break;
            }
        }

        // Check for invalid invoice ID
        if (!$invoice_exists) {
            error_log("Invalid invoice ID $invoice_id in POST data", 3, 'error_log.txt');
            $valid = false;
            $_SESSION['message'] = $translations[$lang]['error'] . "Invalid invoice ID";
            $_SESSION['message_type'] = 'danger';
            break;
        }

        // Check for negative payment amounts
        if ($payment_amount < 0) {
            error_log("Negative payment amount for invoice $invoice_id: $payment_amount", 3, 'error_log.txt');
            $valid = false;
            $_SESSION['message'] = $translations[$lang]['empty_error'];
            $_SESSION['message_type'] = 'danger';
            break;
        }

        // Track valid payments
        if ($payment_amount > 0) {
            $has_valid_payment = true;
            $total_payment += $payment_amount;
        }
    }

    // Check if validation failed or no valid payments
    if (!$valid || !$has_valid_payment) {
        error_log("Validation failed: valid=$valid, has_valid_payment=$has_valid_payment, total_payment=$total_payment", 3, 'error_log.txt');
        $_SESSION['message'] = $translations[$lang]['empty_error'];
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF'] . "?account_id=$account_id");
        exit();
    }

    // Start database transaction
    $conn->autocommit(FALSE);
    try {
        // Update account balance
        $amount_to_update = -$total_payment; // Negative for payment
        $stmt = $conn->prepare("UPDATE accounts SET balance = balance + ?, dated = ? WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Prepare failed for account update: " . $conn->error);
        }
        $stmt->bind_param("dsi", $amount_to_update, $transaction_date, $account_id);
        if (!$stmt->execute()) {
            throw new Exception("Error updating account balance: " . $stmt->error);
        }
        $stmt->close();

        // Fetch updated account balance
        $stmt = $conn->prepare("SELECT balance FROM accounts WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Prepare failed for balance fetch: " . $conn->error);
        }
        $stmt->bind_param("i", $account_id);
        if (!$stmt->execute()) {
            throw new Exception("Error fetching account balance: " . $stmt->error);
        }
        $result = $stmt->get_result();
        $current_balance = $result->fetch_assoc()['balance'];
        $stmt->close();

        // Process each invoice payment
        foreach ($invoices_data as $invoice_id => $payment_amount) {
            $invoice_id = intval($invoice_id);
            $payment_amount = floatval($payment_amount);
            if ($payment_amount <= 0) {
                continue; // Skip zero or negative payments
            }

            // Fetch invoice details
            $stmt = $conn->prepare("SELECT total_amount, paid_amount, balance_amount 
                                    FROM expenses_master 
                                    WHERE id = ? AND from_account_id = ?");
            if (!$stmt) {
                throw new Exception("Prepare failed for invoice fetch: " . $conn->error);
            }
            $stmt->bind_param("ii", $invoice_id, $account_id);
            if (!$stmt->execute()) {
                throw new Exception("Error fetching invoice $invoice_id: " . $stmt->error);
            }
            $result = $stmt->get_result();
            if ($result->num_rows !== 1) {
                throw new Exception($translations[$lang]['error'] . "Invalid invoice ID: $invoice_id");
            }
            $invoice = $result->fetch_assoc();
            $stmt->close();

            // Validate payment amount
            if ($payment_amount > $invoice['balance_amount']) {
                throw new Exception($translations[$lang]['insufficient_balance']);
            }

            // Calculate new invoice values
            $new_paid_amount = $invoice['paid_amount'] + $payment_amount;
            $new_balance_amount = $invoice['balance_amount'] - $payment_amount;
            if (abs($new_balance_amount) < 0.01) { // Handle floating-point precision
                $new_balance_amount = 0;
                $new_paid_amount = $invoice['total_amount'];
            }

            // Update expenses_master
            $stmt = $conn->prepare("UPDATE expenses_master 
                                    SET paid_amount = ?, balance_amount = ? 
                                    WHERE id = ? AND from_account_id = ?");
            if (!$stmt) {
                throw new Exception("Prepare failed for invoice update: " . $conn->error);
            }
            $stmt->bind_param("ddii", $new_paid_amount, $new_balance_amount, $invoice_id, $account_id);
            if (!$stmt->execute()) {
                throw new Exception("Error updating expenses_master for invoice $invoice_id: " . $stmt->error);
            }
            $stmt->close();

            // Insert payment record into accounts_details
            $ref_type = 'payment';
            $detail_amount = -$payment_amount; // Negative for payments
            $stmt = $conn->prepare("INSERT INTO accounts_details 
                                    (account_id, ref_id, ref_type, amount, balance, description, dated) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception("Prepare failed for accounts_details insert: " . $conn->error);
            }
            $stmt->bind_param("iisddss", $account_id, $invoice_id, $ref_type, $detail_amount, 
                             $current_balance, $description, $transaction_date);
            if (!$stmt->execute()) {
                throw new Exception("Error inserting into accounts_details for invoice $invoice_id: " . $stmt->error);
            }
            $current_balance += $detail_amount; // Update running balance
            $stmt->close();
        }

        // Commit transaction
        $conn->commit();
        error_log("Payment processed successfully for account ID $account_id, total: $total_payment", 3, 'error_log.txt');
        $_SESSION['message'] = $translations[$lang]['success'];
        $_SESSION['message_type'] = 'success';
        header("Location: " . $_SERVER['PHP_SELF'] . "?account_id=$account_id");
        exit();

    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        error_log("Transaction failed: " . $e->getMessage(), 3, 'error_log.txt');
        $_SESSION['message'] = $translations[$lang]['error'] . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF'] . "?account_id=$account_id");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en" dir="<?php echo ($lang == 'ur') ? 'rtl' : 'ltr'; ?>">
<head>
    <!-- Include meta tags -->
    <?php require_once('meta_inc.php'); ?>
    <!-- Set viewport for responsive design -->
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <!-- Set page title -->
    <title><?php echo $translations[$lang]['title']; ?></title>
    <!-- Include Tailwind CSS via CDN for button styling -->
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box; /* Prevent padding/margin overflow */
        }
        body {
            font-size: 14px; /* Compact base font */
            background-color: #f8fafc; /* Slate-50 for clean look */
        }
        html[dir="rtl"] body {
            font-size: 16px; /* Larger for Urdu */
        }
        .navbar {
            position: fixed;
            top: 0;
            width: 100%;
            z-index: 1000;
            background-color: #1e40af; /* Blue-800 for navbar */
            color: #ffffff;
        }
        .container {
            padding-top: 50px; /* Compact */
            padding-left: 8px;
            padding-right: 8px;
        }
        .panel-heading {
            background-color: #1e40af; /* Blue-800 for professional look */
            color: #ffffff; /* White text */
            padding: 8px; /* Compact */
            border-radius: 6px 6px 0 0;
        }
        .panel-body {
            padding: 8px; /* Compact */
        }
        .language-switcher {
            display: flex !important; /* Ensure visibility */
            visibility: visible !important; /* Force visibility */
            gap: 4px; /* Tight spacing */
            justify-content: flex-end;
            margin-bottom: 6px; /* Reduced */
            z-index: 1001; /* Above other elements */
        }
        html[dir="rtl"] .language-switcher {
            justify-content: flex-start; /* Align left for RTL */
        }
        .language-switcher .btn {
            display: inline-block !important; /* Ensure buttons show */
            padding: 4px 8px; /* Compact */
            font-size: 0.8rem; /* Smaller */
            border-radius: 4px;
            text-decoration: none; /* Prevent underline */
            color: #4b5563; /* Gray-600 */
            background-color: #e5e7eb; /* Gray-200 */
        }
        .language-switcher .btn.active {
            background-color: #1e40af; /* Blue-800 */
            color: #ffffff;
        }
        .account-details, .invoices-section {
            margin-top: 8px; /* Reduced */
        }
        .account-card {
            background-color: #fff;
            padding: 8px; /* Compact */
            border-radius: 6px;
            border: 1px solid #e2e8f0; /* Slate-200 border */
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            margin-bottom: 8px; /* Reduced */
        }
        .account-card p {
            margin: 4px 0; /* Compact */
            font-size: 0.9rem; /* Default */
        }
        html[dir="rtl"] .account-card p {
            font-size: 1rem; /* Larger for Urdu */
        }
        .account-card .balance-field {
            background-color: #fef2f2; /* Light red for Balance */
            padding: 6px 4px; /* Slightly more padding */
            border-radius: 4px;
        }
        .account-card .balance-value {
            font-size: 1.2rem; /* Larger for prominence */
            font-weight: 800;
            color: #dc2626; /* Vibrant red */
        }
        html[dir="rtl"] .account-card .balance-value {
            font-size: 1.3rem; /* Larger for Urdu */
        }
        .account-card strong {
            display: inline-block;
            width: 100px; /* Compact */
            font-size: 0.9rem;
            color: #4b5563; /* Gray-600 */
        }
        html[dir="rtl"] .account-card strong {
            font-size: 1rem; /* Larger for Urdu */
        }
        .table-responsive {
            overflow-x: auto;
            margin-bottom: 8px; /* Compact */
        }
        .table {
            font-size: 0.85rem; /* Compact */
            margin-bottom: 0;
            background-color: #ffffff;
        }
        html[dir="rtl"] .table {
            font-size: 0.95rem; /* Larger for Urdu */
        }
        .table th, .table td {
            padding: 6px; /* Compact */
            vertical-align: middle;
        }
        .table tr:nth-child(even) {
            background-color: #f8fafc; /* Slate-50 for alternate rows */
        }
        .transaction-section {
            background-color: #f1f5f9; /* Slate-100 for modern look */
            padding: 8px; /* Compact */
            border-radius: 6px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            margin-top: 8px; /* Reduced */
        }
        .transaction-section .form-group {
            margin-bottom: 8px; /* Compact */
        }
        .transaction-section .form-control {
            width: 100%;
            font-size: 0.9rem; /* Compact */
            padding: 6px; /* Reduced */
            border-radius: 4px;
            border: 1px solid #d1d5db; /* Gray-300 */
        }
        html[dir="rtl"] .transaction-section .form-control {
            font-size: 1rem; /* Larger for Urdu */
        }
        .transaction-section .btn {
            width: 100%;
            padding: 8px; /* Compact */
            font-size: 0.9rem; /* Compact */
            border-radius: 4px;
            min-height: 36px; /* Touch-friendly */
        }
        html[dir="rtl"] .transaction-section .btn {
            font-size: 1rem; /* Larger for Urdu */
        }
        .btn-success {
            background-color: #15803d; /* Green-700 */
            color: #ffffff;
        }
        .btn-default {
            background-color: #6b7280; /* Gray-500 */
            color: #ffffff;
        }
        .btn-lg {
            padding: 10px; /* Compact */
            font-size: 1rem; /* Slightly smaller */
        }
        html[dir="rtl"] .btn-lg {
            font-size: 1.1rem; /* Larger for Urdu */
        }
        .amount-input {
            text-align: right;
        }
        .total-payment {
            font-weight: bold;
            background-color: #e5e7eb; /* Gray-200 for distinction */
        }
        .alert-success {
            background-color: #10b981; /* Green-500 */
            color: #ffffff;
        }
        .alert-danger, .alert-info {
            background-color: #ef4444; /* Red-500 */
            color: #ffffff;
        }
        .fade-in {
            animation: fadeIn 0.3s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @media (max-width: 767px) {
            .container {
                padding-top: 40px; /* More compact */
            }
            .panel-heading {
                padding: 6px;
            }
            .panel-body {
                padding: 6px;
            }
            .language-switcher .btn {
                font-size: 0.75rem; /* Smaller for mobile */
                padding: 3px 6px;
            }
            .account-details, .invoices-section, .transaction-section {
                margin-top: 6px;
            }
            .account-card {
                padding: 6px;
                margin-bottom: 6px;
            }
            .account-card p {
                font-size: 0.85rem;
            }
            html[dir="rtl"] .account-card p {
                font-size: 0.95rem;
            }
            .account-card .balance-value {
                font-size: 1.1rem;
            }
            html[dir="rtl"] .account-card .balance-value {
                font-size: 1.15rem;
            }
            .account-card strong {
                width: 80px;
                font-size: 0.85rem;
            }
            html[dir="rtl"] .account-card strong {
                font-size: 0.95rem;
            }
            .table th, .table td {
                font-size: 0.8rem;
                padding: 4px;
            }
            html[dir="rtl"] .table th, html[dir="rtl"] .table td {
                font-size: 0.9rem;
            }
            .transaction-section .form-group {
                margin-bottom: 6px;
            }
            .transaction-section .form-control {
                font-size: 0.85rem;
                padding: 4px;
            }
            html[dir="rtl"] .transaction-section .form-control {
                font-size: 0.95rem;
            }
            .transaction-section .btn {
                font-size: 0.85rem;
                padding: 6px;
                min-height: 32px;
            }
            html[dir="rtl"] .transaction-section .btn {
                font-size: 0.95rem;
            }
            .btn-lg {
                padding: 8px;
                font-size: 0.9rem;
            }
            html[dir="rtl"] .btn-lg {
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>

<!-- Include navigation bar -->
<?php require_once('navbar.php'); ?>

<!-- Main dashboard content -->
<div class="container">
    <div class="row">
        <div class="col-xs-12">
            <div class="panel panel-primary">
                <div class="panel-heading">
                    <h3 class="panel-title"><?php echo $translations[$lang]['title']; ?></h3>
                </div>
                <div class="panel-body">
                    <!-- Language switcher buttons -->
                    <div class="language-switcher">
                        <a href="?account_id=<?php echo $account_id; ?>&lang=en" class="btn btn-sm btn-default <?php echo ($lang == 'en') ? 'active' : ''; ?>">English</a>
                        <a href="?account_id=<?php echo $account_id; ?>&lang=ur" class="btn btn-sm btn-default <?php echo ($lang == 'ur') ? 'active' : ''; ?>">اردو</a>
                    </div>

                    <!-- Display session messages if any -->
                    <?php if (isset($_SESSION['message'])): ?>
                        <div class="alert alert-<?php echo $_SESSION['message_type']; ?>">
                            <?php 
                            echo $_SESSION['message']; 
                            unset($_SESSION['message'], $_SESSION['message_type']);
                            ?>
                        </div>
                    <?php endif; ?>

                    <!-- Display error if no account found -->
                    <?php if (!$account): ?>
                        <div class="alert alert-danger">
                            <?php echo $translations[$lang]['no_account']; ?>
                        </div>
                    <?php else: ?>
                        <!-- Display account details -->
                        <div class="account-details fade-in">
                            <div class="account-card">
                                <p><strong><?php echo $translations[$lang]['account_id']; ?>:</strong> <?php echo htmlspecialchars($account['id']); ?></p>
                                <p><strong><?php echo $translations[$lang]['account_title']; ?>:</strong> <?php echo htmlspecialchars($account['title']); ?></p>
                                <p><strong><?php echo $translations[$lang]['account_type']; ?>:</strong> <?php echo htmlspecialchars($account['type']); ?></p>
                                <p class="balance-field"><strong><?php echo $translations[$lang]['account_balance']; ?>:</strong> <span class="balance-value"><?php echo number_format($account['balance'], 2); ?></span></p>
                                <p><strong><?php echo $translations[$lang]['account_mobile']; ?>:</strong> <?php echo htmlspecialchars($account['mobile_no'] ?: 'N/A'); ?></p>
                            </div>
                        </div>

                        <!-- Display pending invoices -->
                        <div class="invoices-section fade-in">
                            <h4><?php echo $translations[$lang]['pending_invoices']; ?></h4>
                            <?php if (empty($invoices)): ?>
                                <div class="alert alert-info">
                                    <?php echo $lang == 'en' ? 'No pending invoices found.' : 'کوئی زیر التواء انوائسز نہیں ملے۔'; ?>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . '?account_id=' . $account_id); ?>" id="paymentForm">
                                        <table class="table table-bordered">
                                            <thead>
                                                <tr>
                                                    <th><?php echo $translations[$lang]['invoice_id']; ?></th>
                                                    <th><?php echo $translations[$lang]['transaction_date']; ?></th>
                                                    <th><?php echo $translations[$lang]['total']; ?></th>
                                                    <th><?php echo $translations[$lang]['paid']; ?></th>
                                                    <th><?php echo $translations[$lang]['remaining']; ?></th>
                                                    <th><?php echo $translations[$lang]['amount']; ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <!-- Loop through invoices -->
                                                <?php foreach ($invoices as $invoice): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($invoice['id']); ?></td>
                                                        <td><?php echo htmlspecialchars($invoice['invoice_date']); ?></td>
                                                        <td><?php echo number_format($invoice['total_amount'], 2); ?></td>
                                                        <td><?php echo number_format($invoice['paid_amount'], 2); ?></td>
                                                        <td><?php echo number_format($invoice['balance_amount'], 2); ?></td>
                                                        <td>
                                                            <input type="number" class="form-control amount-input payment-amount" 
                                                                   name="invoices[<?php echo $invoice['id']; ?>]" 
                                                                   step="0.01" min="0" max="<?php echo $invoice['balance_amount']; ?>" 
                                                                   placeholder="0.00" data-invoice-id="<?php echo $invoice['id']; ?>">
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                        <div class="transaction-section fade-in">
                                            <input type="hidden" name="account_id" value="<?php echo $account_id; ?>">
                                            <div class="row flex gap-2">
                                                <div class="form-group col-xs-12 col-sm-4">
                                                    <label for="total_payment"><?php echo $translations[$lang]['total_payment']; ?></label>
                                                    <input type="number" class="form-control total-payment" id="total_payment" 
                                                           readonly value="0.00">
                                                </div>
                                                <div class="form-group col-xs-12 col-sm-4">
                                                    <label for="transaction_date"><?php echo $translations[$lang]['transaction_date']; ?></label>
                                                    <input type="date" class="form-control" id="transaction_date" name="transaction_date" 
                                                           required value="<?php echo date('Y-m-d'); ?>">
                                                </div>
                                                <div class="form-group col-xs-12 col-sm-4">
                                                    <label for="description"><?php echo $translations[$lang]['description']; ?></label>
                                                    <textarea class="form-control" id="description" name="description" rows="2"></textarea>
                                                </div>
                                                <div class="form-group col-xs-12">
                                                    <button type="submit" class="btn btn-success btn-lg">
                                                        <span class="glyphicon glyphicon-minus"></span> 
                                                        <?php echo $translations[$lang]['payment_button']; ?>
                                                    </button>
                                                    <button type="reset" class="btn btn-default btn-lg">
                                                        <span class="glyphicon glyphicon-refresh"></span> 
                                                        <?php echo $translations[$lang]['reset']; ?>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="js/mobile_menu.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    // Update total payment amount
    function updateTotalPayment() {
        var total = 0;
        $('.payment-amount').each(function() {
            var amount = parseFloat($(this).val()) || 0;
            if (amount > 0) {
                total += amount;
            }
        });
        $('#total_payment').val(total.toFixed(2));
    }

    // Handle changes to payment amount inputs
    $('.payment-amount').on('input', function() {
        var max = parseFloat($(this).attr('max')) || 0;
        var value = parseFloat($(this).val()) || 0;
        if (value < 0) {
            $(this).val('0.00');
        } else if (value > max) {
            $(this).val(max.toFixed(2));
            alert('<?php echo $translations[$lang]['insufficient_balance']; ?>');
        }
        updateTotalPayment();
    });

    // Validate form on submission
    $('#paymentForm').submit(function(e) {
        var total = parseFloat($('#total_payment').val()) || 0;
        if (total <= 0) {
            e.preventDefault();
            alert('<?php echo $translations[$lang]['empty_error']; ?>');
            return false;
        }
        $('.payment-amount').each(function() {
            var max = parseFloat($(this).attr('max')) || 0;
            var value = parseFloat($(this).val()) || 0;
            if (value > max) {
                e.preventDefault();
                alert('<?php echo $translations[$lang]['insufficient_balance']; ?>');
                return false;
            }
        });
        var transactionDate = $('#transaction_date').val();
        if (!transactionDate.match(/^\d{4}-\d{2}-\d{2}$/)) {
            e.preventDefault();
            alert('<?php echo $translations[$lang]['empty_error']; ?>');
            return false;
        }
        return true;
    });
});
</script>

</body>
<?php
// Close database connection if open
if (isset($conn)) {
    $conn->close();
}
?>
</html>
