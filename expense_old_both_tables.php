<?php 
require_once('security.php');

// Language handling
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'];
} elseif (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = 'en'; // Default to English
}

$lang = $_SESSION['lang'];

// Language strings
$translations = [
    'en' => [
        'title' => 'Expense Invoicing',
        'add_title' => 'Add New Expense',
        'edit_title' => 'Edit Expense',
        'from_account' => 'From Account',
        'payment_type' => 'Payment Type',
        'invoice_date' => 'Invoice Date',
        'description' => 'Description',
        'expense_head' => 'Expense Head',
        'amount' => 'Amount',
        'total_amount' => 'Total Amount',
        'discount' => 'Discount',
        'net_total' => 'Net Total',
        'paid_amount' => 'Paid Amount',
        'balance_amount' => 'Balance',
        'add_row' => 'Add Row',
        'remove_row' => 'Remove',
        'submit' => 'Submit',
        'reset' => 'Reset',
        'success' => 'Expense saved successfully!',
        'update_success' => 'Expense updated successfully!',
        'error' => 'Error: ',
        'empty_error' => 'Required fields cannot be empty!',
        'list_title' => 'Expenses List',
        'no_records' => 'No expenses found.',
        'sr_no' => '#',
        'actions' => 'Actions',
        'edit' => 'Edit',
        'save' => 'Save',
        'cancel' => 'Cancel',
        'payment_history' => 'Payment History',
        'add_payment' => 'Add Payment',
        'payment_date' => 'Payment Date',
        'payment_amount' => 'Amount',
        'status' => 'Status',
        'status_active' => 'Active',
        'status_inactive' => 'Inactive'
    ],
    'ur' => [
        'title' => 'اخراجات کی انوائسنگ',
        'add_title' => 'نیا خرچہ شامل کریں',
        'edit_title' => 'خرچہ کی ترمیم کریں',
        'from_account' => 'اکاؤنٹ سے',
        'payment_type' => 'ادائیگی کی قسم',
        'invoice_date' => 'انوائس کی تاریخ',
        'description' => 'تفصیل',
        'expense_head' => 'خرچہ کا سر',
        'amount' => 'رقم',
        'total_amount' => 'کل رقم',
        'discount' => 'رعایت',
        'net_total' => 'خالص کل',
        'paid_amount' => 'ادا شدہ رقم',
        'balance_amount' => 'بقیہ',
        'add_row' => 'قطار شامل کریں',
        'remove_row' => 'حذف کریں',
        'submit' => 'جمع کرائیں',
        'reset' => 'دوبارہ ترتیب دیں',
        'success' => 'خرچہ کامیابی سے محفوظ ہو گیا!',
        'update_success' => 'خرچہ کامیابی سے اپ ڈیٹ ہو گیا!',
        'error' => 'خرابی: ',
        'empty_error' => 'ضروری فیلڈز خالی نہیں ہو سکتے!',
        'list_title' => 'خرچوں کی فہرست',
        'no_records' => 'کوئی خرچہ موجود نہیں۔',
        'sr_no' => 'نمبر',
        'actions' => 'اعمال',
        'edit' => 'ترمیم کریں',
        'save' => 'محفوظ کریں',
        'cancel' => 'منسوخ کریں',
        'payment_history' => 'ادائیگی کی تاریخ',
        'add_payment' => 'ادائیگی شامل کریں',
        'payment_date' => 'ادائیگی کی تاریخ',
        'payment_amount' => 'رقم',
        'status' => 'حالت',
        'status_active' => 'فعال',
        'status_inactive' => 'غیر فعال'
    ]
];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    require_once('conn_inc.php');
    
    // Get form data
    $from_account_id = intval($_POST['from_account_id']);
    $payment_type = trim($_POST['payment_type']);
    $invoice_date = trim($_POST['invoice_date']);
    $description = trim($_POST['description']);
    $total_amount = floatval($_POST['total_amount']);
    $discount = floatval($_POST['discount']);
    $net_total = floatval($_POST['net_total']);
    $paid_amount = floatval($_POST['paid_amount']);
    $balance_amount = floatval($_POST['balance_amount']);
    $status = intval($_POST['status']);
    
    // Get expense details
    $expense_details = [];
    if (isset($_POST['expense_head_id']) && is_array($_POST['expense_head_id'])) {
        foreach ($_POST['expense_head_id'] as $index => $expense_head_id) {
            $expense_details[] = [
                'expense_head_id' => intval($expense_head_id),
                'amount' => floatval($_POST['detail_amount'][$index]),
                'description' => trim($_POST['detail_description'][$index])
            ];
        }
    }
    
    // Validate required fields
    if (!empty($from_account_id) && !empty($payment_type) && !empty($invoice_date) && count($expense_details) > 0) {
        // Start transaction
        $conn->autocommit(FALSE);
        $success = true;
        
        try {
            if (isset($_POST['expense_id']) && !empty($_POST['expense_id'])) {
                // Update existing expense
                $expense_id = intval($_POST['expense_id']);
                $stmt = $conn->prepare("UPDATE expenses_master SET 
                    from_account_id = ?, payment_type = ?, total_amount = ?, discount = ?, 
                    net_total = ?, paid_amount = ?, balance_amount = ?, description = ?, 
                    invoice_date = ?, status = ? WHERE id = ?");
                $stmt->bind_param("isddddddssi", 
                    $from_account_id, $payment_type, $total_amount, $discount, 
                    $net_total, $paid_amount, $balance_amount, $description, 
                    $invoice_date, $status, $expense_id);
                
                if (!$stmt->execute()) {
                    throw new Exception($translations[$lang]['error'] . $stmt->error);
                }
                $stmt->close();
                
                // Delete existing details
                $stmt = $conn->prepare("DELETE FROM expenses_details WHERE expense_id = ?");
                $stmt->bind_param("i", $expense_id);
                if (!$stmt->execute()) {
                    throw new Exception($translations[$lang]['error'] . $stmt->error);
                }
                $stmt->close();
            } else {
                // Insert new expense
                $stmt = $conn->prepare("INSERT INTO expenses_master 
                    (from_account_id, payment_type, total_amount, discount, net_total, 
                    paid_amount, balance_amount, description, invoice_date, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isddddddss", 
                    $from_account_id, $payment_type, $total_amount, $discount, 
                    $net_total, $paid_amount, $balance_amount, $description, 
                    $invoice_date, $status);
                
                if (!$stmt->execute()) {
                    throw new Exception($translations[$lang]['error'] . $stmt->error);
                }
                $expense_id = $stmt->insert_id;
                $stmt->close();
            }
            
            // Insert expense details
            foreach ($expense_details as $detail) {
                $stmt = $conn->prepare("INSERT INTO expenses_details 
                    (expense_id, expense_head_id, amount, description) 
                    VALUES (?, ?, ?, ?)");
                $stmt->bind_param("iids", 
                    $expense_id, $detail['expense_head_id'], 
                    $detail['amount'], $detail['description']);
                
                if (!$stmt->execute()) {
                    throw new Exception($translations[$lang]['error'] . $stmt->error);
                }
                $stmt->close();
            }
            
            // Commit transaction
            $conn->commit();
            
            $_SESSION['message'] = isset($_POST['expense_id']) ? 
                $translations[$lang]['update_success'] : 
                $translations[$lang]['success'];
            $_SESSION['message_type'] = "success";
            
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['message'] = $e->getMessage();
            $_SESSION['message_type'] = "danger";
        }
    } else {
        $_SESSION['message'] = $translations[$lang]['empty_error'];
        $_SESSION['message_type'] = "danger";
    }
}

// Process payment submission
if (isset($_POST['add_payment'])) {
    require_once('conn_inc.php');
    
    $expense_id = intval($_POST['expense_id']);
    $payment_date = trim($_POST['payment_date']);
    $payment_amount = floatval($_POST['payment_amount']);
    $description = trim($_POST['payment_description']);
    
    if (!empty($expense_id) && !empty($payment_date) && $payment_amount > 0) {
        // Insert payment
        $stmt = $conn->prepare("INSERT INTO expenses_payments 
            (expense_id, payment_date, payment_amount, description) 
            VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isds", $expense_id, $payment_date, $payment_amount, $description);
        
        if ($stmt->execute()) {
            // Update master record
            $stmt = $conn->prepare("UPDATE expenses_master 
                SET paid_amount = paid_amount + ?, 
                    balance_amount = net_total - (paid_amount + ?) 
                WHERE id = ?");
            $stmt->bind_param("ddi", $payment_amount, $payment_amount, $expense_id);
            $stmt->execute();
            
            $_SESSION['message'] = "Payment added successfully!";
            $_SESSION['message_type'] = "success";
        } else {
            $_SESSION['message'] = $translations[$lang]['error'] . $stmt->error;
            $_SESSION['message_type'] = "danger";
        }
        
        $stmt->close();
        header("Location: " . $_SERVER['PHP_SELF'] . "?edit=" . $expense_id);
        exit();
    } else {
        $_SESSION['message'] = "Please fill all required fields with valid data!";
        $_SESSION['message_type'] = "danger";
    }
}
?>

<!DOCTYPE html>
<html lang="en" dir="<?php echo ($lang == 'ur') ? 'rtl' : 'ltr'; ?>">
<head>
    <?php require_once('meta_inc.php'); ?>
    <style>
        .amount-input { text-align: right; }
        .table-responsive { overflow-x: auto; }
        .detail-row { margin-bottom: 10px; }
        .payment-history { margin-top: 20px; }
        .totals-section { background-color: #f9f9f9; padding: 15px; border-radius: 5px; }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<!-- Language switcher -->
<div class="container text-right">
    <a href="?lang=en" class="btn btn-xs btn-default <?php echo ($lang == 'en') ? 'active' : ''; ?>">English</a>
    <a href="?lang=ur" class="btn btn-xs btn-default <?php echo ($lang == 'ur') ? 'active' : ''; ?>">اردو</a>
</div>

<!-- Dashboard -->
<div class="container">
    <div class="row">
        <div class="col-md-12">

            <!-- Panel for Add/Edit Expense -->
            <div class="panel panel-primary">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <?php 
                        echo isset($_GET['edit']) ? 
                            $translations[$lang]['edit_title'] : 
                            $translations[$lang]['add_title']; 
                        ?>
                    </h3>
                </div>
                <div class="panel-body">

                    <?php if (isset($_SESSION['message'])): ?>
                        <div class="alert alert-<?php echo $_SESSION['message_type']; ?>">
                            <?php 
                            echo $_SESSION['message']; 
                            unset($_SESSION['message'], $_SESSION['message_type']);
                            ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="" id="expenseForm">
                        <?php
                        $edit_mode = isset($_GET['edit']);
                        $expense_data = [
                            'id' => '',
                            'from_account_id' => '',
                            'payment_type' => 'Cash',
                            'total_amount' => 0,
                            'discount' => 0,
                            'net_total' => 0,
                            'paid_amount' => 0,
                            'balance_amount' => 0,
                            'description' => '',
                            'invoice_date' => date('Y-m-d'),
                            'status' => 1
                        ];
                        $expense_details = [];
                        $payments = [];
                        
                        if ($edit_mode) {
                            require_once('conn_inc.php');
                            $id = intval($_GET['edit']);
                            
                            // Get master record
                            $result = $conn->query("SELECT * FROM expenses_master WHERE id = $id");
                            if ($result->num_rows > 0) {
                                $expense_data = $result->fetch_assoc();
                            }
                            
                            // Get details
                            $result = $conn->query("SELECT * FROM expenses_details WHERE expense_id = $id");
                            if ($result->num_rows > 0) {
                                while ($row = $result->fetch_assoc()) {
                                    $expense_details[] = $row;
                                }
                            }
                            
                            // Get payments
                            $result = $conn->query("SELECT * FROM expenses_payments WHERE expense_id = $id ORDER BY payment_date DESC");
                            if ($result->num_rows > 0) {
                                while ($row = $result->fetch_assoc()) {
                                    $payments[] = $row;
                                }
                            }
                        }
                        
                        if (count($expense_details) == 0) {
                            // Add one empty row for new expense
                            $expense_details[] = [
                                'expense_head_id' => '',
                                'amount' => 0,
                                'description' => ''
                            ];
                        }
                        ?>
                        
                        <input type="hidden" name="expense_id" value="<?php echo $expense_data['id']; ?>">
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="from_account_id"><?php echo $translations[$lang]['from_account']; ?></label>
                                    <select class="form-control" id="from_account_id" name="from_account_id" required>
                                        <option value="">-- Select Account --</option>
                                        <?php
                                        require_once('conn_inc.php');
                                        $accounts_query = "SELECT id, title, type, balance FROM accounts 
                                                          WHERE type IN ('Supplier', 'Cash', 'Bank') 
                                                          ORDER BY title";
                                        $accounts_result = $conn->query($accounts_query);
                                        
                                        if ($accounts_result && $accounts_result->num_rows > 0) {
                                            while ($account = $accounts_result->fetch_assoc()) {
                                                $selected = ($account['id'] == $expense_data['from_account_id']) ? 'selected' : '';
                                                $display_text = "{$account['title']} ({$account['type']}) - Balance: " . number_format($account['balance'], 2);
                                                echo "<option value='{$account['id']}' $selected>{$display_text}</option>";
                                            }
                                        } else {
                                            echo "<option value='' disabled>No accounts found in database</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="payment_type"><?php echo $translations[$lang]['payment_type']; ?></label>
                                    <select class="form-control" id="payment_type" name="payment_type" required>
                                        <option value="Cash" <?php echo ($expense_data['payment_type'] == 'Cash') ? 'selected' : ''; ?>>Cash</option>
                                        <option value="Bank" <?php echo ($expense_data['payment_type'] == 'Bank') ? 'selected' : ''; ?>>Bank</option>
                                        <option value="Cheque" <?php echo ($expense_data['payment_type'] == 'Cheque') ? 'selected' : ''; ?>>Cheque</option>
                                        <option value="Credit" <?php echo ($expense_data['payment_type'] == 'Credit') ? 'selected' : ''; ?>>Credit</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="invoice_date"><?php echo $translations[$lang]['invoice_date']; ?></label>
                                    <input type="date" class="form-control" id="invoice_date" name="invoice_date" required 
                                           value="<?php echo htmlspecialchars($expense_data['invoice_date']); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="description"><?php echo $translations[$lang]['description']; ?></label>
                            <textarea class="form-control" id="description" name="description" rows="2"><?php 
                                echo htmlspecialchars($expense_data['description']); 
                            ?></textarea>
                        </div>
                        
                        <hr>
                        
                        <h4><?php echo $translations[$lang]['expense_head']; ?></h4>
                        
                        <div id="expenseDetails">
                            <?php foreach ($expense_details as $index => $detail): ?>
                                <div class="row detail-row">
                                    <div class="col-md-5">
                                        <select class="form-control account-head" name="expense_head_id[]" required>
                                            <option value="">-- Select Expense Head --</option>
                                            <?php
                                            if (isset($conn)) {
                                                $result = $conn->query("SELECT id, title FROM expense_heads ORDER BY title");
                                                while ($row = $result->fetch_assoc()) {
                                                    $selected = ($row['id'] == $detail['expense_head_id']) ? 'selected' : '';
                                                    echo "<option value='{$row['id']}' $selected>{$row['title']}</option>";
                                                }
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <input type="number" class="form-control amount-input detail-amount" 
                                               name="detail_amount[]" step="0.01" min="0" required
                                               value="<?php echo htmlspecialchars($detail['amount']); ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <input type="text" class="form-control" 
                                               name="detail_description[]" 
                                               value="<?php echo htmlspecialchars($detail['description']); ?>">
                                    </div>
                                    <div class="col-md-1">
                                        <?php if ($index > 0): ?>
                                            <button type="button" class="btn btn-danger btn-block remove-row">
                                                <span class="glyphicon glyphicon-remove"></span>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="text-right" style="margin-bottom: 20px;">
                            <button type="button" id="addRow" class="btn btn-info">
                                <span class="glyphicon glyphicon-plus"></span> 
                                <?php echo $translations[$lang]['add_row']; ?>
                            </button>
                        </div>
                        
                        <div class="row totals-section">
                            <div class="col-md-3 col-md-offset-6">
                                <div class="form-group">
                                    <label for="total_amount"><?php echo $translations[$lang]['total_amount']; ?></label>
                                    <input type="number" class="form-control amount-input" id="total_amount" 
                                           name="total_amount" readonly value="<?php echo $expense_data['total_amount']; ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="discount"><?php echo $translations[$lang]['discount']; ?></label>
                                    <input type="number" class="form-control amount-input" id="discount" 
                                           name="discount" step="0.01" min="0" 
                                           value="<?php echo $expense_data['discount']; ?>">
                                </div>
                            </div>
                            
                            <div class="col-md-3 col-md-offset-6">
                                <div class="form-group">
                                    <label for="net_total"><?php echo $translations[$lang]['net_total']; ?></label>
                                    <input type="number" class="form-control amount-input" id="net_total" 
                                           name="net_total" readonly value="<?php echo $expense_data['net_total']; ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="paid_amount"><?php echo $translations[$lang]['paid_amount']; ?></label>
                                    <input type="number" class="form-control amount-input" id="paid_amount" 
                                           name="paid_amount" step="0.01" min="0" 
                                           value="<?php echo $expense_data['paid_amount']; ?>">
                                </div>
                            </div>
                            
                            <div class="col-md-3 col-md-offset-6">
                                <div class="form-group">
                                    <label for="balance_amount"><?php echo $translations[$lang]['balance_amount']; ?></label>
                                    <input type="number" class="form-control amount-input" id="balance_amount" 
                                           name="balance_amount" readonly value="<?php echo $expense_data['balance_amount']; ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="status"><?php echo $translations[$lang]['status']; ?></label>
                                    <select class="form-control" id="status" name="status">
                                        <option value="1" <?php echo ($expense_data['status'] == 1) ? 'selected' : ''; ?>>
                                            <?php echo $translations[$lang]['status_active']; ?>
                                        </option>
                                        <option value="0" <?php echo ($expense_data['status'] == 0) ? 'selected' : ''; ?>>
                                            <?php echo $translations[$lang]['status_inactive']; ?>
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="text-right" style="margin-top: 20px;">
                            <button type="submit" class="btn btn-success">
                                <span class="glyphicon glyphicon-<?php echo $edit_mode ? 'refresh' : 'plus'; ?>"></span> 
                                <?php echo $translations[$lang]['submit']; ?>
                            </button>
                            
                            <?php if ($edit_mode): ?>
                                <a href="expenses.php" class="btn btn-default">
                                    <span class="glyphicon glyphicon-remove"></span> 
                                    <?php echo $translations[$lang]['cancel']; ?>
                                </a>
                            <?php else: ?>
                                <button type="reset" class="btn btn-default">
                                    <span class="glyphicon glyphicon-refresh"></span> 
                                    <?php echo $translations[$lang]['reset']; ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                    
                    <?php if ($edit_mode && count($payments) > 0): ?>
                        <div class="payment-history">
                            <h4><?php echo $translations[$lang]['payment_history']; ?></h4>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead>
                                        <tr>
                                            <th><?php echo $translations[$lang]['sr_no']; ?></th>
                                            <th><?php echo $translations[$lang]['payment_date']; ?></th>
                                            <th><?php echo $translations[$lang]['payment_amount']; ?></th>
                                            <th><?php echo $translations[$lang]['description']; ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $counter = 1; foreach ($payments as $payment): ?>
                                            <tr>
                                                <td><?php echo $counter++; ?></td>
                                                <td><?php echo htmlspecialchars($payment['payment_date']); ?></td>
                                                <td class="amount-input"><?php echo number_format($payment['payment_amount'], 2); ?></td>
                                                <td><?php echo htmlspecialchars($payment['description']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($edit_mode): ?>
                        <hr>
                        <h4><?php echo $translations[$lang]['add_payment']; ?></h4>
                        <form method="post" action="">
                            <input type="hidden" name="expense_id" value="<?php echo $expense_data['id']; ?>">
                            
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="payment_date"><?php echo $translations[$lang]['payment_date']; ?></label>
                                        <input type="date" class="form-control" id="payment_date" name="payment_date" required 
                                               value="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="payment_amount"><?php echo $translations[$lang]['payment_amount']; ?></label>
                                        <input type="number" class="form-control amount-input" id="payment_amount" 
                                               name="payment_amount" step="0.01" min="0.01" 
                                               max="<?php echo $expense_data['balance_amount']; ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-5">
                                    <div class="form-group">
                                        <label for="payment_description"><?php echo $translations[$lang]['description']; ?></label>
                                        <input type="text" class="form-control" id="payment_description" 
                                               name="payment_description">
                                    </div>
                                </div>
                                <div class="col-md-1">
                                    <div class="form-group">
                                        <label>&nbsp;</label>
                                        <button type="submit" name="add_payment" class="btn btn-primary btn-block">
                                            <span class="glyphicon glyphicon-plus"></span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Panel for Expenses List -->
            <div class="panel panel-info">
                <div class="panel-heading">
                    <h3 class="panel-title"><?php echo $translations[$lang]['list_title']; ?></h3>
                </div>
                <div class="panel-body">

                    <?php
                    require_once('conn_inc.php');
                    $result = $conn->query("SELECT em.*, a.title as from_account 
                                          FROM expenses_master em
                                          LEFT JOIN accounts a ON em.from_account_id = a.id
                                          ORDER BY em.invoice_date DESC, em.id DESC");
                    ?>

                    <?php if ($result->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th><?php echo $translations[$lang]['sr_no']; ?></th>
                                        <th><?php echo $translations[$lang]['invoice_date']; ?></th>
                                        <th><?php echo $translations[$lang]['from_account']; ?></th>
                                        <th><?php echo $translations[$lang]['payment_type']; ?></th>
                                        <th><?php echo $translations[$lang]['net_total']; ?></th>
                                        <th><?php echo $translations[$lang]['paid_amount']; ?></th>
                                        <th><?php echo $translations[$lang]['balance_amount']; ?></th>
                                        <th><?php echo $translations[$lang]['status']; ?></th>
                                        <th><?php echo $translations[$lang]['actions']; ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $counter = 1; while ($row = $result->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo $counter++; ?></td>
                                            <td><?php echo htmlspecialchars($row['invoice_date']); ?></td>
                                            <td><?php echo htmlspecialchars($row['from_account']); ?></td>
                                            <td><?php echo htmlspecialchars($row['payment_type']); ?></td>
                                            <td class="amount-input"><?php echo number_format($row['net_total'], 2); ?></td>
                                            <td class="amount-input"><?php echo number_format($row['paid_amount'], 2); ?></td>
                                            <td class="amount-input"><?php echo number_format($row['balance_amount'], 2); ?></td>
                                            <td>
                                                <?php if ($row['status'] == 1): ?>
                                                    <span class="label label-success"><?php echo $translations[$lang]['status_active']; ?></span>
                                                <?php else: ?>
                                                    <span class="label label-danger"><?php echo $translations[$lang]['status_inactive']; ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="?edit=<?php echo $row['id']; ?>" class="btn btn-xs btn-warning">
                                                    <span class="glyphicon glyphicon-edit"></span> 
                                                    <?php echo $translations[$lang]['edit']; ?>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info text-center">
                            <?php echo $translations[$lang]['no_records']; ?>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

        </div>
    </div>
</div>

<script src="js/mobile_menu.js"></script>
<script>
$(document).ready(function() {
    // Add new expense detail row
    $('#addRow').click(function() {
        var newRow = `
            <div class="row detail-row">
                <div class="col-md-5">
                    <select class="form-control account-head" name="expense_head_id[]" required>
                        <option value="">-- Select Expense Head --</option>
                        <?php
                        if (isset($conn)) {
                            $result = $conn->query("SELECT id, title FROM expense_heads ORDER BY title");
                            while ($row = $result->fetch_assoc()) {
                                echo "<option value='{$row['id']}'>{$row['title']}</option>";
                            }
                            $result->free();
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <input type="number" class="form-control amount-input detail-amount" 
                           name="detail_amount[]" step="0.01" min="0" required value="0">
                </div>
                <div class="col-md-3">
                    <input type="text" class="form-control" name="detail_description[]">
                </div>
                <div class="col-md-1">
                    <button type="button" class="btn btn-danger btn-block remove-row">
                        <span class="glyphicon glyphicon-remove"></span>
                    </button>
                </div>
            </div>
        `;
        $('#expenseDetails').append(newRow);
        calculateTotals();
    });
    
    // Remove expense detail row
    $(document).on('click', '.remove-row', function() {
        $(this).closest('.detail-row').remove();
        calculateTotals();
    });
    
    // Calculate totals when amount or discount changes
    $(document).on('input', '.detail-amount, #discount, #paid_amount', function() {
        calculateTotals();
    });
    
    // Function to calculate totals
    function calculateTotals() {
        var totalAmount = 0;
        
        // Sum all detail amounts
        $('.detail-amount').each(function() {
            var amount = parseFloat($(this).val()) || 0;
            totalAmount += amount;
        });
        
        var discount = parseFloat($('#discount').val()) || 0;
        var netTotal = totalAmount - discount;
        var paidAmount = parseFloat($('#paid_amount').val()) || 0;
        var balanceAmount = netTotal - paidAmount;
        
        // Update fields
        $('#total_amount').val(totalAmount.toFixed(2));
        $('#net_total').val(netTotal.toFixed(2));
        $('#balance_amount').val(balanceAmount.toFixed(2));
    }
    
    // Initialize totals on page load
    calculateTotals();
});
</script>

</body>
<?php if (isset($conn)) $conn->close(); ?>
</html>