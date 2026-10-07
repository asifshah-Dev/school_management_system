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
        'title' => 'Account Search and Transactions',
        'search_label' => 'Search Account (Title or Mobile)',
        'amount' => 'Amount',
        'description' => 'Description',
        'transaction_date' => 'Transaction Date',
        'payment_button' => 'Make Payment',
        'receive_button' => 'Receive Payment',
        'submit' => 'Submit',
        'reset' => 'Reset',
        'success' => 'Transaction recorded successfully!',
        'error' => 'Error: ',
        'empty_error' => 'Required fields cannot be empty!',
        'insufficient_balance' => 'Payment amount exceeds account balance!',
        'no_account_found' => 'No account found or multiple accounts match. Please be more specific.',
        'account_found' => 'Account found: ',
        'account_details' => 'Account Details',
        'account_id' => 'ID',
        'account_title' => 'Title',
        'account_type' => 'Type',
        'account_balance' => 'Balance',
        'account_mobile' => 'Mobile'
    ],
    'ur' => [
        'title' => 'اکاؤنٹ تلاش اور ٹرانزیکشنز',
        'search_label' => 'اکاؤنٹ تلاش کریں (عنوان یا موبائل)',
        'amount' => 'رقم',
        'description' => 'تفصیل',
        'transaction_date' => 'ٹرانزیکشن کی تاریخ',
        'payment_button' => 'ادائیگی کریں',
        'receive_button' => 'وصولی کریں',
        'submit' => 'جمع کرائیں',
        'reset' => 'دوبارہ ترتیب دیں',
        'success' => 'ٹرانزیکشن کامیابی سے ریکارڈ ہو گئی!',
        'error' => 'خرابی: ',
        'empty_error' => 'ضروری فیلڈز خالی نہیں ہو سکتے!',
        'insufficient_balance' => 'ادائیگی کی رقم اکاؤنٹ بیلنس سے زیادہ ہے!',
        'no_account_found' => 'کوئی اکاؤنٹ نہیں ملا یا متعدد اکاؤنٹس مماثل ہیں۔ براہ کرم مزید مخصوص ہوں۔',
        'account_found' => 'اکاؤنٹ ملا: ',
        'account_details' => 'اکاؤنٹ کی تفصیلات',
        'account_id' => 'آئی ڈی',
        'account_title' => 'عنوان',
        'account_type' => 'قسم',
        'account_balance' => 'بیلنس',
        'account_mobile' => 'موبائل'
    ]
];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    require_once('conn_inc.php');
    
    // Get form data
    $account_id = intval($_POST['account_id']);
    $transaction_type = trim($_POST['transaction_type']);
    $amount = floatval($_POST['amount']);
    $description = trim($_POST['description']);
    $transaction_date = trim($_POST['transaction_date']);
    $status = 0; // Default status to 0 (active)
    
    // Validate required fields
    if (!empty($account_id) && !empty($transaction_type) && !empty($amount) && !empty($transaction_date)) {
        // Validate payment amount against account balance
        if ($transaction_type == 'Payment') {
            $stmt = $conn->prepare("SELECT balance FROM accounts WHERE id = ?");
            $stmt->bind_param("i", $account_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $current_balance = $result->fetch_assoc()['balance'];
            $stmt->close();
            
            if ($amount > $current_balance) {
                $_SESSION['message'] = $translations[$lang]['insufficient_balance'];
                $_SESSION['message_type'] = "danger";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }
        }
        
        // Start transaction
        $conn->autocommit(FALSE);
        $success = true;
        
        try {
            // Insert into transactions table
            $stmt = $conn->prepare("INSERT INTO transactions (account_id, type, amount, description, transaction_date, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isdsis", $account_id, $transaction_type, $amount, $description, $transaction_date, $status);
            if (!$stmt->execute()) {
                throw new Exception($translations[$lang]['error'] . $stmt->error);
            }
            $transaction_id = $stmt->insert_id;
            $stmt->close();
            
            // Update accounts table balance
            $amount_to_update = ($transaction_type == 'Payment') ? -$amount : $amount;
            $stmt = $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
            $stmt->bind_param("di", $amount_to_update, $account_id);
            if (!$stmt->execute()) {
                throw new Exception($translations[$lang]['error'] . $stmt->error);
            }
            $stmt->close();
            
            // Get updated balance from accounts table
            $stmt = $conn->prepare("SELECT balance FROM accounts WHERE id = ?");
            $stmt->bind_param("i", $account_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $new_balance = $result->fetch_assoc()['balance'];
            $stmt->close();
            
            // Insert into accounts_details
            $ref_type = strtolower($transaction_type);
            $detail_amount = ($transaction_type == 'Payment') ? -$amount : $amount;
            $stmt = $conn->prepare("INSERT INTO accounts_details (account_id, ref_id, ref_type, amount, balance, description) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iisdds", $account_id, $transaction_id, $ref_type, $detail_amount, $new_balance, $description);
            if (!$stmt->execute()) {
                throw new Exception($translations[$lang]['error'] . $stmt->error);
            }
            $stmt->close();
            
            // Commit transaction
            $conn->commit();
            
            $_SESSION['message'] = $translations[$lang]['success'];
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
?>

<!DOCTYPE html>
<html lang="en" dir="<?php echo ($lang == 'ur') ? 'rtl' : 'ltr'; ?>">
<head>
    <?php require_once('meta_inc.php'); ?>
    <style>
        .amount-input { text-align: right; }
        .table-responsive { overflow-x: auto; }
        .search-section { max-width: 600px; margin: 20px auto; }
        .transaction-section { background-color: #f9f9f9; padding: 15px; border-radius: 5px; display: none; }
        .inline-amounts .form-group { display: inline-block; width: 22%; margin-right: 2%; }
        .inline-amounts .form-control { width: 100%; }
        .inline-amounts .btn { margin-top: 24px; }
        .search-feedback { margin-top: 5px; font-size: 0.9em; }
        .account-details { margin-top: 20px; }
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

            <!-- Panel for Account Search -->
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

                    <div class="search-section">
                        <div class="form-group">
                            <input type="text" class="form-control" id="account_search" 
                                   placeholder="<?php echo $translations[$lang]['search_label']; ?>" required>
                            <div class="search-feedback" id="search_feedback"></div>
                        </div>
                    </div>

                    <div class="account-details" id="account_details" style="display:none;">
                        <h4><?php echo $translations[$lang]['account_details']; ?></h4>
                        <table class="table table-bordered">
                            <tr><th><?php echo $translations[$lang]['account_id']; ?></th><td id="account_id_display"></td></tr>
                            <tr><th><?php echo $translations[$lang]['account_title']; ?></th><td id="account_title"></td></tr>
                            <tr><th><?php echo $translations[$lang]['account_type']; ?></th><td id="account_type"></td></tr>
                            <tr><th><?php echo $translations[$lang]['account_balance']; ?></th><td id="account_balance"></td></tr>
                            <tr><th><?php echo $translations[$lang]['account_mobile']; ?></th><td id="account_mobile"></td></tr>
                        </table>
                    </div>

                    <div class="transaction-section" id="transaction_section">
                        <form method="post" action="" id="transactionForm">
                            <input type="hidden" id="account_id" name="account_id" value="">
                            <input type="hidden" id="transaction_type" name="transaction_type" value="">
                            <div class="row inline-amounts">
                                <div class="form-group">
                                    <label for="amount"><?php echo $translations[$lang]['amount']; ?></label>
                                    <input type="number" class="form-control amount-input" id="amount" 
                                           name="amount" step="0.01" min="0" required value="0">
                                </div>
                                <div class="form-group">
                                    <label for="transaction_date"><?php echo $translations[$lang]['transaction_date']; ?></label>
                                    <input type="date" class="form-control" id="transaction_date" name="transaction_date" required 
                                           value="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="form-group">
                                    <label for="description"><?php echo $translations[$lang]['description']; ?></label>
                                    <textarea class="form-control" id="description" name="description" rows="2"></textarea>
                                </div>
                                <div class="form-group">
                                    <button type="submit" class="btn btn-success btn-lg" id="submit_button">
                                        <span class="glyphicon glyphicon-plus"></span> 
                                        <span id="submit_button_label"><?php echo $translations[$lang]['submit']; ?></span>
                                    </button>
                                    <button type="reset" class="btn btn-default btn-lg">
                                        <span class="glyphicon glyphicon-refresh"></span> 
                                        <?php echo $translations[$lang]['reset']; ?>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="js/mobile_menu.js"></script>
<script>
$(document).ready(function() {
    let debounceTimeout;

    // Handle account search with debounce
    $('#account_search').on('input', function() {
        clearTimeout(debounceTimeout);
        debounceTimeout = setTimeout(function() {
            var searchTerm = $('#account_search').val().trim();
            if (searchTerm.length < 2) {
                resetUI();
                return;
            }

            $.ajax({
                url: 'fetch_account.php',
                method: 'POST',
                data: { search_term: searchTerm },
                dataType: 'json',
                success: function(data) {
                    if (data.status === 'success' && data.account) {
                        $('#search_feedback').html('<span class="text-success"><?php echo $translations[$lang]['account_found']; ?>' + data.account.title + ' (' + data.account.type + ')</span>');
                        $('#account_id').val(data.account.id);
                        $('#account_id_display').text(data.account.id);
                        $('#account_title').text(data.account.title);
                        $('#account_type').text(data.account.type);
                        $('#account_balance').text(parseFloat(data.account.balance).toFixed(2));
                        $('#account_mobile').text(data.account.mobile || 'N/A');
                        $('#account_details').show();

                        if (data.account.balance > 0) {
                            $('#transaction_type').val('Payment');
                            $('#submit_button_label').text('<?php echo $translations[$lang]['payment_button']; ?>');
                            $('#transaction_section').show();
                            $('#submit_button').prop('disabled', false);
                        } else if (data.account.balance < 0) {
                            $('#transaction_type').val('Receipt');
                            $('#submit_button_label').text('<?php echo $translations[$lang]['receive_button']; ?>');
                            $('#transaction_section').show();
                            $('#submit_button').prop('disabled', false);
                        } else {
                            $('#transaction_section').hide();
                            $('#submit_button').prop('disabled', true);
                        }
                    } else {
                        $('#search_feedback').html('<span class="text-danger"><?php echo $translations[$lang]['no_account_found']; ?></span>');
                        resetUI();
                    }
                },
                error: function() {
                    $('#search_feedback').html('<span class="text-danger"><?php echo $translations[$lang]['error']; ?> AJAX request failed.</span>');
                    resetUI();
                }
            });
        }, 500); // Debounce delay of 500ms
    });

    // Validate amount on form submission
    $('#transactionForm').submit(function(e) {
        var transactionType = $('#transaction_type').val();
        var amount = parseFloat($('#amount').val()) || 0;
        var balance = parseFloat($('#account_balance').text()) || 0;
        
        if (transactionType == 'Payment' && amount > balance) {
            e.preventDefault();
            alert('<?php echo $translations[$lang]['insufficient_balance']; ?>');
        }
        if (!$('#account_id').val()) {
            e.preventDefault();
            alert('<?php echo $translations[$lang]['no_account_found']; ?>');
        }
    });

    // Reset form
    $('#transactionForm').on('reset', function() {
        resetUI();
        $('#account_search').val('');
        $('#search_feedback').text('');
    });

    // Reset UI function
    function resetUI() {
        $('#account_id').val('');
        $('#account_id_display').text('');
        $('#account_title').text('');
        $('#account_type').text('');
        $('#account_balance').text('');
        $('#account_mobile').text('');
        $('#account_details').hide();
        $('#transaction_section').hide();
        $('#submit_button').prop('disabled', true);
    }
});
</script>

</body>
<?php if (isset($conn)) $conn->close(); ?>
</html>