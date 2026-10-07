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
        'title' => 'Receive Payment',
        'amount' => 'Amount',
        'description' => 'Description',
        'transaction_date' => 'Transaction Date',
        'receive_button' => 'Receive Payment',
        'reset' => 'Reset',
        'success' => 'Payment received successfully!',
        'error' => 'Error: ',
        'empty_error' => 'Required fields cannot be empty!',
        'account_details' => 'Account Details',
        'account_id' => 'ID',
        'account_title' => 'Title',
        'account_type' => 'Type',
        'account_balance' => 'Balance',
        'account_mobile' => 'Mobile',
        'no_account' => 'No account found for the provided ID.'
    ],
    'ur' => [
        'title' => 'وصولی کریں',
        'amount' => 'رقم',
        'description' => 'تفصیل',
        'transaction_date' => 'ٹرانزیکشن کی تاریخ',
        'receive_button' => 'وصولی کریں',
        'reset' => 'دوبارہ ترتیب دیں',
        'success' => 'وصولی کامیابی سے ریکارڈ ہو گئی!',
        'error' => 'خرابی: ',
        'empty_error' => 'ضروری فیلڈز خالی نہیں ہو سکتے!',
        'account_details' => 'اکاؤنٹ کی تفصیلات',
        'account_id' => 'آئی ڈی',
        'account_title' => 'عنوان',
        'account_type' => 'قسم',
        'account_balance' => 'بیلنس',
        'account_mobile' => 'موبائل',
        'no_account' => 'دیے گئے آئی ڈی کے لیے کوئی اکاؤنٹ نہیں ملا۔'
    ]
];

// Fetch account details
require_once('conn_inc.php');
$account_id = isset($_GET['account_id']) ? intval($_GET['account_id']) : 0;
$account = null;

if ($account_id > 0) {
    $stmt = $conn->prepare("SELECT id, title, type, balance, mobile FROM accounts WHERE id = ?");
    $stmt->bind_param("i", $account_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 1) {
        $account = $result->fetch_assoc();
    }
    $stmt->close();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $account) {
    $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $transaction_date = isset($_POST['transaction_date']) ? trim($_POST['transaction_date']) : '';
    $transaction_type = 'Receipt';
    $status = 0;

    if ($amount > 0 && !empty($transaction_date)) {
        // Start transaction
        $conn->autocommit(FALSE);
        try {
            // Insert into transactions
            $stmt = $conn->prepare("INSERT INTO transactions (account_id, type, amount, description, transaction_date, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isdsis", $account_id, $transaction_type, $amount, $description, $transaction_date, $status);
            if (!$stmt->execute()) {
                throw new Exception($translations[$lang]['error'] . $stmt->error);
            }
            $transaction_id = $stmt->insert_id;
            $stmt->close();

            // Update accounts balance
            $amount_to_update = $amount;
            $stmt = $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
            $stmt->bind_param("di", $amount_to_update, $account_id);
            if (!$stmt->execute()) {
                throw new Exception($translations[$lang]['error'] . $stmt->error);
            }
            $stmt->close();

            // Get updated balance
            $stmt = $conn->prepare("SELECT balance FROM accounts WHERE id = ?");
            $stmt->bind_param("i", $account_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $new_balance = $result->fetch_assoc()['balance'];
            $stmt->close();

            // Insert into accounts_details
            $ref_type = strtolower($transaction_type);
            $detail_amount = $amount;
            $stmt = $conn->prepare("INSERT INTO accounts_details (account_id, ref_id, ref_type, amount, balance, description) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iisdds", $account_id, $transaction_id, $ref_type, $detail_amount, $new_balance, $description);
            if (!$stmt->execute()) {
                throw new Exception($translations[$lang]['error'] . $stmt->error);
            }
            $stmt->close();

            // Commit transaction
            $conn->commit();

            $_SESSION['message'] = $translations[$lang]['success'];
            $_SESSION['message_type'] = 'success';
            header("Location: account_search.php");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['message'] = $e->getMessage();
            $_SESSION['message_type'] = 'danger';
            header("Location: " . $_SERVER['PHP_SELF'] . "?account_id=$account_id");
            exit();
        }
    } else {
        $_SESSION['message'] = $translations[$lang]['empty_error'];
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF'] . "?account_id=$account_id");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en" dir="<?php echo ($lang == 'ur') ? 'rtl' : 'ltr'; ?>">
<head>
    <?php require_once('meta_inc.php'); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <style>
        body {
            font-size: 16px;
            background-color: #f5f5f5;
        }
        .navbar {
            position: fixed;
            top: 0;
            width: 100%;
            z-index: 1000;
            margin-bottom: 0;
        }
        .container {
            padding-top: 70px;
        }
        .account-details {
            margin-top: 20px;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .table {
            font-size: 0.9rem;
            margin-bottom: 0;
        }
        .table th, .table td {
            padding: 10px;
            vertical-align: middle;
        }
        .transaction-section {
            background-color: #fff;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-top: 20px;
        }
        .transaction-section .form-group {
            margin-bottom: 15px;
        }
        .transaction-section .form-control {
            width: 100%;
            font-size: 1rem;
            padding: 10px;
            border-radius: 6px;
        }
        .transaction-section .btn {
            width: 100%;
            padding: 12px;
            font-size: 1rem;
            border-radius: 6px;
            margin-bottom: 10px;
        }
        .amount-input {
            text-align: right;
        }
        .fade-in {
            animation: fadeIn 0.3s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @media (max-width: 767px) {
            .table th, .table td {
                font-size: 0.8rem;
                padding: 8px;
            }
            .navbar-brand {
                font-size: 1rem;
            }
        }
        .btn-lg {
            padding: 15px;
            font-size: 1.1rem;
        }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<!-- Language switcher -->
<div class="container text-right">
    <a href="?account_id=<?php echo $account_id; ?>&lang=en" class="btn btn-xs btn-default <?php echo ($lang == 'en') ? 'active' : ''; ?>">English</a>
    <a href="?account_id=<?php echo $account_id; ?>&lang=ur" class="btn btn-xs btn-default <?php echo ($lang == 'ur') ? 'active' : ''; ?>">اردو</a>
</div>

<!-- Dashboard -->
<div class="container">
    <div class="row">
        <div class="col-xs-12">
            <div class="panel panel-primary">
                <div class="panel-heading">
                    <h3 class="panel-title"><?php echo $translations[$lang]['title']; ?></h3>
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

                    <?php if (!$account): ?>
                        <div class="alert alert-danger">
                            <?php echo $translations[$lang]['no_account']; ?>
                        </div>
                    <?php else: ?>
                        <div class="account-details fade-in">
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead>
                                        <tr>
                                            <th><?php echo $translations[$lang]['account_id']; ?></th>
                                            <th><?php echo $translations[$lang]['account_title']; ?></th>
                                            <th><?php echo $translations[$lang]['account_type']; ?></th>
                                            <th><?php echo $translations[$lang]['account_balance']; ?></th>
                                            <th><?php echo $translations[$lang]['account_mobile']; ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><?php echo htmlspecialchars($account['id']); ?></td>
                                            <td><?php echo htmlspecialchars($account['title']); ?></td>
                                            <td><?php echo htmlspecialchars($account['type']); ?></td>
                                            <td><?php echo number_format($account['balance'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($account['mobile'] ?: 'N/A'); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="transaction-section fade-in">
                            <form method="post" action="" id="receiveForm">
                                <input type="hidden" name="account_id" value="<?php echo $account_id; ?>">
                                <div class="row">
                                    <div class="form-group col-xs-12 col-sm-4">
                                        <label for="amount"><?php echo $translations[$lang]['amount']; ?></label>
                                        <input type="number" class="form-control amount-input" id="amount" 
                                               name="amount" step="0.01" min="0.01" required>
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
                                        <button type="submit" class="btn btn-primary btn-lg">
                                            <span class="glyphicon glyphicon-plus"></span> 
                                            <?php echo $translations[$lang]['receive_button']; ?>
                                        </button>
                                        <button type="reset" class="btn btn-default btn-lg">
                                            <span class="glyphicon glyphicon-refresh"></span> 
                                            <?php echo $translations[$lang]['reset']; ?>
                                        </button>
                                    </div>
                                </div>
                            </form>
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
    $('#receiveForm').submit(function(e) {
        var amount = parseFloat($('#amount').val()) || 0;
        
        if (amount <= 0) {
            e.preventDefault();
            alert('<?php echo $translations[$lang]['empty_error']; ?>');
            return;
        }
    });
});
</script>

</body>
<?php if (isset($conn)) $conn->close(); ?>
</html>