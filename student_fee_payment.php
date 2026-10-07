<?php
require_once('security.php');
require_once('conn_inc.php');

// Language handling
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'];
} elseif (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = 'en'; // Default to English
}
$lang = $_SESSION['lang'];

// Include translations (similar to your existing code)
$translations = [
    'en' => [
        'title' => 'Fee Payment System',
        'select_student' => 'Select Student',
        'student_info' => 'Student Information',
        'fee_details' => 'Fee Details',
        'payment_info' => 'Payment Information',
        'payment_method' => 'Payment Method',
        'cash' => 'Cash',
        'bank_transfer' => 'Bank Transfer',
        'jazzcash' => 'JazzCash',
        'easypaisa' => 'EasyPaisa',
        'other' => 'Other',
        'amount' => 'Amount',
        'paid_amount' => 'Paid Amount',
        'remaining_amount' => 'Remaining Amount',
        'payment_date' => 'Payment Date',
        'remarks' => 'Remarks',
        'submit_payment' => 'Submit Payment',
        'print_receipt' => 'Print Receipt',
        'receipt' => 'Receipt',
        'transaction_ref' => 'Transaction Reference',
        'payment_history' => 'Payment History',
        'date' => 'Date',
        'method' => 'Method',
        'status' => 'Status',
        'action' => 'Action',
        'view' => 'View',
        'no_payments' => 'No payments found',
        'full_payment' => 'Full Payment',
        'partial_payment' => 'Partial Payment',
        'advance_payment' => 'Advance Payment',
        'select_option' => 'Select Option',
        'due_date' => 'Due Date',
        'total_due' => 'Total Due',
        'payment_type' => 'Payment Type',
        'class' => 'Class',
        'session' => 'Session',
        'student_name' => 'Student Name',
        'load_details' => 'Load Details'
    ],
    'ur' => [
        // Urdu translations would go here
         'class' => 'کلاس',
        'session' => 'سیشن',
        'student_name' => 'طالب علم کا نام',
          'load_details' => 'تفصیلات لوڈ کریں'
    ]
];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Initialize variables with default values or empty strings
    $student_class_id = isset($_POST['student_class_id']) ? intval($_POST['student_class_id']) : 0; 
    $fee_card_id = isset($_POST['fee_card_id']) ? intval($_POST['fee_card_id']) : 0;
    $payment_amount = isset($_POST['payment_amount']) ? floatval($_POST['payment_amount']) : 0.00;
    $payment_method = isset($_POST['payment_method']) ? $_POST['payment_method'] : '';
    $transaction_ref = isset($_POST['transaction_ref']) ? $_POST['transaction_ref'] : null;
    $remarks = isset($_POST['remarks']) ? $_POST['remarks'] : null;
    $payment_date = isset($_POST['payment_date']) ? $_POST['payment_date'] : date('Y-m-d');
    $payment_type = isset($_POST['payment_type']) ? $_POST['payment_type'] : ''; // full, partial, advance
    
    // Validate required fields
    if ($student_class_id <= 0 || $fee_card_id <= 0 || $payment_amount <= 0 || empty($payment_method) || empty($payment_type)) {
        $error = "Please fill all required fields with valid values.";
    } else {
        try {
            // Begin transaction
            $conn->begin_transaction();
            
            // Insert payment record
            $stmt = $conn->prepare("INSERT INTO student_fee_payments 
                                   (fee_card_id, paid_amount, payment_date, payment_method, transaction_ref, remarks, status) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?)");
            $status = ($payment_type == 'full') ? 'paid' : ($payment_type == 'advance' ? 'advance' : 'partial');
            $stmt->bind_param("idsssss", $fee_card_id, $payment_amount, $payment_date, $payment_method, $transaction_ref, $remarks, $status);
            $stmt->execute();
            $payment_id = $stmt->insert_id;
            
            // Generate receipt number
            $receipt_number = 'RCPT-' . date('Ymd') . '-' . str_pad($payment_id, 5, '0', STR_PAD_LEFT);
            
            // Insert receipt record
            $stmt = $conn->prepare("INSERT INTO payment_receipts (payment_id, receipt_number) VALUES (?, ?)");
            $stmt->bind_param("is", $payment_id, $receipt_number);
            $stmt->execute();
            
            // Update fee card status if full payment
            if ($payment_type == 'full') {
                $stmt = $conn->prepare("UPDATE student_fee_card SET status = 'paid' WHERE id = ?");
                $stmt->bind_param("i", $fee_card_id);
                $stmt->execute();
            }
            
            // Commit transaction
            $conn->commit();
            
            $_SESSION['success'] = "Payment recorded successfully. Receipt #: $receipt_number";
            $_SESSION['last_payment_id'] = $payment_id;
            header("Location: payment.php?receipt=$payment_id");
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Payment failed: " . $e->getMessage();
        }
    }
}

// Get classes for dropdown
$classes = [];
try {
    $query = "SELECT c.id, c.title
              FROM classes c
              
             
              ORDER BY c.title";
    $result = $conn->query($query);
    while ($row = $result->fetch_assoc()) {
        $classes[] = $row;
    }
} catch (Exception $e) {
    $error = "Database error: " . $e->getMessage();
}

// Get sessions for dropdown
$sessions = [];
try {
    $query = "SELECT id, title FROM sessions WHERE status = 0 ORDER BY from_dated DESC";
    $result = $conn->query($query);
    while ($row = $result->fetch_assoc()) {
        $sessions[] = $row;
    }
} catch (Exception $e) {
    $error = "Database error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" dir="<?php echo ($lang == 'ur') ? 'rtl' : 'ltr'; ?>">
<head>
    <title><?php echo $translations[$lang]['title']; ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Naskh+Arabic:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/css/bootstrap-datepicker.min.css">
    <style>
        body {
            font-family: <?php echo ($lang == 'ur') ? "'Noto Naskh Arabic', sans-serif" : "Arial, sans-serif"; ?>;
        }
        .payment-card {
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .payment-header {
            background-color: #4a6fdc;
            color: white;
            padding: 15px;
            border-radius: 10px 10px 0 0;
        }
        .payment-body {
            padding: 20px;
        }
        .fee-item {
            border-bottom: 1px solid #eee;
            padding: 10px 0;
        }
        .receipt {
            border: 2px dashed #ccc;
            padding: 20px;
            margin-top: 20px;
        }
        @media print {
            .no-print {
                display: none;
            }
            .receipt {
                border: none;
            }
        }
    </style>
</head>
<body>
<?php require_once('navbar.php'); ?>

<div class="container">
    <h2 class="text-center"><?php echo $translations[$lang]['title']; ?></h2>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-4">
            <div class="payment-card">
                <div class="payment-header">
                    <h4><?php echo $translations[$lang]['select_student']; ?></h4>
                </div>
                <div class="payment-body">
                    <form id="studentSelectForm">
                        <div class="form-group">
                            <label><?php echo $translations[$lang]['class']; ?></label>
                            <select class="form-control" id="classSelect" required>
                                <option value=""><?php echo $translations[$lang]['select_option']; ?></option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>"><?php echo $class['title']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><?php echo $translations[$lang]['session']; ?></label>
                            <select class="form-control" id="sessionSelect" required>
                                <option value=""><?php echo $translations[$lang]['select_option']; ?></option>
                                <?php foreach ($sessions as $session): ?>
                                    <option value="<?php echo $session['id']; ?>"><?php echo $session['title']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><?php echo $translations[$lang]['student_name']; ?></label>
                            <select class="form-control" id="studentSelect" required disabled>
                                <option value=""><?php echo $translations[$lang]['select_option']; ?></option>
                            </select>
                        </div>
                        <button type="button" id="loadStudentBtn" class="btn btn-primary btn-block" disabled>
                            <?php echo $translations[$lang]['load_details']; ?>
                        </button>
                    </form>
                </div>
            </div>
            
            <div class="payment-card" id="studentInfoCard" style="display:none;">
                <div class="payment-header">
                    <h4><?php echo $translations[$lang]['student_info']; ?></h4>
                </div>
                <div class="payment-body">
                    <div id="studentDetails">
                        <!-- Student details will be loaded here -->
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-8">
            <div class="payment-card" id="feeDetailsCard" style="display:none;">
                <div class="payment-header">
                    <h4><?php echo $translations[$lang]['fee_details']; ?></h4>
                </div>
                <div class="payment-body">
                    <div id="feeDetails">
                        <!-- Fee details will be loaded here -->
                    </div>
                </div>
            </div>
            
            <div class="payment-card" id="paymentFormCard" style="display:none;">
                <div class="payment-header">
                    <h4><?php echo $translations[$lang]['payment_info']; ?></h4>
                </div>
                <div class="payment-body">
                    <form id="paymentForm" method="post">
                        <input type="hidden" id="student_class_id" name="student_class_id">
                        <input type="hidden" id="fee_card_id" name="fee_card_id">
                        
                        <div class="form-group">
                            <label><?php echo $translations[$lang]['payment_type']; ?></label>
                            <select class="form-control" id="paymentType" name="payment_type" required>
                                <option value=""><?php echo $translations[$lang]['select_option']; ?></option>
                                <option value="full"><?php echo $translations[$lang]['full_payment']; ?></option>
                                <option value="partial"><?php echo $translations[$lang]['partial_payment']; ?></option>
                                <option value="advance"><?php echo $translations[$lang]['advance_payment']; ?></option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label><?php echo $translations[$lang]['payment_method']; ?></label>
                            <select class="form-control" name="payment_method" required>
                                <option value=""><?php echo $translations[$lang]['select_option']; ?></option>
                                <option value="cash"><?php echo $translations[$lang]['cash']; ?></option>
                                <option value="bank_transfer"><?php echo $translations[$lang]['bank_transfer']; ?></option>
                                <option value="jazzcash"><?php echo $translations[$lang]['jazzcash']; ?></option>
                                <option value="easypaisa"><?php echo $translations[$lang]['easypaisa']; ?></option>
                                <option value="other"><?php echo $translations[$lang]['other']; ?></option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label><?php echo $translations[$lang]['amount']; ?></label>
                            <input type="number" class="form-control" id="paymentAmount" name="payment_amount" required step="0.01">
                        </div>
                        
                        <div class="form-group">
                            <label><?php echo $translations[$lang]['payment_date']; ?></label>
                            <input type="text" class="form-control datepicker" name="payment_date" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label><?php echo $translations[$lang]['transaction_ref']; ?></label>
                            <input type="text" class="form-control" name="transaction_ref">
                        </div>
                        
                        <div class="form-group">
                            <label><?php echo $translations[$lang]['remarks']; ?></label>
                            <textarea class="form-control" name="remarks" rows="2"></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-success btn-block">
                            <?php echo $translations[$lang]['submit_payment']; ?>
                        </button>
                    </form>
                </div>
            </div>
            
            <div class="payment-card" id="paymentHistoryCard" style="display:none;">
                <div class="payment-header">
                    <h4><?php echo $translations[$lang]['payment_history']; ?></h4>
                </div>
                <div class="payment-body">
                    <div id="paymentHistory">
                        <!-- Payment history will be loaded here -->
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Receipt Modal -->
    <div class="modal fade" id="receiptModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                    <h4 class="modal-title"><?php echo $translations[$lang]['receipt']; ?></h4>
                </div>
                <div class="modal-body" id="receiptContent">
                    <!-- Receipt content will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="window.print()">Print</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/js/bootstrap-datepicker.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize datepicker
    $('.datepicker').datepicker({
        format: 'yyyy-mm-dd',
        autoclose: true
    });
    
    // Load students when class/session is selected
    $('#classSelect, #sessionSelect').change(function() {
        var classId = $('#classSelect').val();
        var sessionId = $('#sessionSelect').val();
        
        if (classId && sessionId) {
            $('#loadStudentBtn').prop('disabled', false);
        } else {
            $('#loadStudentBtn').prop('disabled', true);
        }
    });
    
    // Load student details
    $('#loadStudentBtn').click(function() {
        var classId = $('#classSelect').val();
        var sessionId = $('#sessionSelect').val();
        var studentId = $('#studentSelect').val();
        alert(classId)
        alert(sessionId)
        alert(studentId)
        
        if (!classId || !sessionId || !studentId) return;
        
        $.ajax({
            url: 'ajax_get_student_details.php',
            type: 'GET',
            data: {
                class_id: classId,
                session_id: sessionId,
                student_id: studentId
            },
            success: function(response) {
               
                $('#studentDetails').html(response.student_html);
                $('#feeDetails').html(response.fee_html);
                $('#paymentHistory').html(response.history_html);
                
                // Set hidden fields
                $('#student_class_id').val(response.student_class_id);
                $('#fee_card_id').val(response.fee_card_id);
                
                // Show cards
                $('#studentInfoCard, #feeDetailsCard, #paymentFormCard, #paymentHistoryCard').show();
                
                // Set max payment amount
                $('#paymentAmount').attr('max', response.total_due);
            },
            error: function(xhr) {
                alert('Error loading student details');
            }
        });
    });
    
    // Load students for selected class/session
    $('#classSelect, #sessionSelect').change(function() {
        var classId = $('#classSelect').val();
        var sessionId = $('#sessionSelect').val();
        
        if (classId && sessionId) {
            $.ajax({
                url: 'ajax_get_students.php',
                type: 'GET',
                data: {
                    class_id: classId,
                    session_id: sessionId
                },
                success: function(data) {
                    $('#studentSelect').empty().append('<option value="">Select Student</option>');
                    $.each(data, function(index, student) {
                        $('#studentSelect').append('<option value="'+student.id+'">'+student.name+' ('+student.father_name+')</option>');
                    });
                    $('#studentSelect').prop('disabled', false);
                },
                error: function(xhr) {
                    alert('Error loading students');
                }
            });
        }
    });
    
    // Show receipt if in URL
    <?php if (isset($_GET['receipt'])): ?>
        $.ajax({
            url: 'ajax_get_receipt.php',
            type: 'GET',
            data: { payment_id: <?php echo intval($_GET['receipt']); ?> },
            success: function(data) {
                $('#receiptContent').html(data);
                $('#receiptModal').modal('show');
            }
        });
    <?php endif; ?>
    
    // Payment type change handler
    $('#paymentType').change(function() {
    
        var paymentType = $(this).val();
        var maxAmount = parseFloat($('#paymentAmount').attr('max'));
        
        if (paymentType === 'full') {
            $('#paymentAmount').val(maxAmount.toFixed(2));
        } else if (paymentType === 'partial') {
            $('#paymentAmount').val('');
        } else if (paymentType === 'advance') {
            $('#paymentAmount').val('');
            $('#paymentAmount').removeAttr('max');
        }
    });
});
</script>
</body>
</html>