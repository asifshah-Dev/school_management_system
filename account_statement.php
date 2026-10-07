<?php
require_once('session_data.php');
require_once('my_connection.php');
$warning = '';
$success = '';
?>

<!DOCTYPE html>
<html>
<title>Shop Management System</title>
    
<head>
    <link href="assets/plugins/bootstrap-datepicker/css/bootstrap-datepicker.min.css" rel="stylesheet">
    <?php require_once('my_meta.php'); ?>
    <script src="dist/jquery.js"></script>
    
    <style>
        .datepicker{
            z-index: 2000 !important;
        }
        
        @media print {    
            .no-print, .no-print * {
                display: none !important;
            }
        }
        
        .balance-positive {
            color: #28a745 !important;
            font-weight: bold;
        }
        
        .balance-negative {
            color: #dc3545 !important;
            font-weight: bold;
        }
        
        .badge-success {
            background-color: #28a745 !important;
        }
        
        .badge-danger {
            background-color: #dc3545 !important;
        }
        
        .simple-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .simple-table th {
            background-color: #f5f5f5;
            padding: 12px 8px;
            text-align: left;
            border-bottom: 2px solid #dee2e6;
            font-weight: 600;
            color: #000000 !important;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .simple-table td {
            padding: 10px 8px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .simple-table tr:hover {
            background-color: #f8f9fa;
        }
        
        .simple-table .text-right {
            text-align: right;
        }
        
        .simple-table .text-center {
            text-align: center;
        }
        
        /* Fixed table header styling */
        .simple-table thead {
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .simple-table thead th {
            background-color: #f5f5f5 !important;
            color: #000000 !important;
            border-bottom: 2px solid #dee2e6 !important;
            box-shadow: 0 2px 2px -1px rgba(0,0,0,0.1);
        }
        
        /* Fix for print */
        @media print {
            .simple-table thead th {
                position: static !important;
                background-color: #f5f5f5 !important;
                color: #000000 !important;
            }
        }
        
        .input-sm {
            height: 25px !important;
            padding: 3px 6px !important;
            font-size: 12px !important;
        }
        
        .btn-row-print {
            padding: 2px 8px !important;
            font-size: 12px !important;
        }
        
        .summary-row {
            background-color: #f8f9fa !important;
            font-weight: bold;
        }
        
        .summary-total {
            background-color: #e8f5e8 !important;
            font-weight: bold;
        }
    </style>
    
    <script>
        function printme(){
            window.print();
        }
    </script>
</head>

<body>
    <!-- Navigation Bar-->
    <?php require_once('my_header.php'); ?>
    <!-- End Navigation Bar-->

    <div class="wrapper">
        <div class="container">
            <!-- Page-Title -->
            <div class="row no-print">
                <div class="col-sm-12">
                    <h4 class="page-title">Account Statement</h4>
                    <ol class="breadcrumb"></ol>
                </div>
            </div>

            <div class="row no-print">
                <div class="col-sm-12">
                    <div class="card-box">
                        <div class="row">
                            <?php echo $success; ?>
                            <?php echo $warning; ?>
                            
                            <?php
                            if(isset($_GET['msg']) == 'updated'){
                                echo '<div class="alert alert-info alert-block alert-dismissible fade in iconic-alert" role="alert">
                                    <div class="alert-icon">
                                        <span class="gcon gcon-hand centered-xy"></span>
                                    </div>
                                    <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true"><span class="mcon mcon-close"></span></span></button>
                                    <strong>Info </strong> Record updated
                                </div>';
                            }
                            ?>
                            
                            <div class="col-md-6">
                                <form class="form-horizontal" role="form" method="post">
                                    <div class="form-group">
                                        <label class="control-label col-sm-4">Select Account</label>
                                        <div class="col-sm-8">
                                            <select name="supplier_id" id="ss" class="form-control" style="width: 100%;">
                                                <option selected="selected" value="">Select Account</option>
                                                <?php
                                                $sql1 = mysqli_query($my_connection, "SELECT id, title FROM customers WHERE status = 0 and id != 1 ORDER BY id DESC") or die(mysqli_error($my_connection));
                                                while ($row1 = mysqli_fetch_array($sql1)) {
                                                    $id = $row1['id'];
                                                    $title = $row1['title'];
                                                    $selected = isset($_POST['supplier_id']) && $_POST['supplier_id'] == $id ? 'selected' : '';
                                                    echo '<option value="'.$id.'" '.$selected.'>'.$title.'</option>';
                                                }
                                                ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="control-label col-sm-4">From Date</label>
                                        <div class="col-sm-8">
                                            <div class="input-group">
                                                <input type="text" class="form-control" name="from_dated" placeholder="mm/dd/yyyy" id="datepicker-autoclose" 
                                                       value="<?php echo isset($_POST['from_dated']) ? $_POST['from_dated'] : ''; ?>">
                                                <span class="input-group-addon bg-custom b-0 text-white"><i class="icon-calender"></i></span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="col-md-4 control-label">To Date</label>
                                        <div class="col-md-8">
                                            <div class="input-group">
                                                <input type="text" class="form-control" name="to_dated" placeholder="mm/dd/yyyy" id="datepicker-autoclose2"
                                                       value="<?php echo isset($_POST['to_dated']) ? $_POST['to_dated'] : ''; ?>">
                                                <span class="input-group-addon bg-custom b-0 text-white"><i class="icon-calender"></i></span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <label class="col-md-4 control-label"></label>
                                    <div class="form-group">
                                        <button type="submit" class="btn btn-success waves-effect waves-light m-l-10 btn-md" name="btncreate">Submit</button>
                                        <button type="button" class="btn btn-default waves-effect waves-light m-l-10 btn-md" onclick="clearDates()">Clear</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Report Section -->
            <?php
            if(isset($_POST['btncreate']) && !empty($_POST['supplier_id'])){
                $account_id = $_POST['supplier_id'];
                $from_dated = date('d-M-Y', strtotime($_POST['from_dated']));
                $to_dated = date('d-M-Y', strtotime($_POST['to_dated']));
                $from_dated_f = date('Y-m-d', strtotime($from_dated));
                $to_dated_f = date('Y-m-d', strtotime($to_dated));
                
                // Get customer title
                $select_qry1 = mysqli_query($my_connection, "SELECT title FROM customers WHERE id = '$account_id'") or die(mysqli_error($my_connection));
                $row1 = mysqli_fetch_array($select_qry1);
                $customer_title = $row1['title'];
                
                // Get opening balance (balance before the selected date range)
                $opening_balance_qry = mysqli_query($my_connection, "
                    SELECT SUM(amount) as opening_balance 
                    FROM customer_account 
                    WHERE customer_id = '$account_id' 
                    AND DATE(dated) < '$from_dated_f'
                ") or die(mysqli_error($my_connection));
                
                $opening_row = mysqli_fetch_assoc($opening_balance_qry);
                $opening_balance = $opening_row['opening_balance'] ?? 0;
                $running_balance = $opening_balance;
                
                echo '<h2>Account Statement
                    <button style="" type="button" onclick="printme()" name="btn_print" class="btn btn-default waves-effect waves-light no-print"> 
                        <i class="fa fa-print"></i> <span>Print</span> 
                    </button>
                </h2>';
                
                echo '<h3>Account: ' . $customer_title . '</h3>';
                echo '<h4>Statement Period: ' . $from_dated . ' to ' . $to_dated . '</h4>';
                echo '<p><strong>Opening Balance:</strong> ' . number_format($opening_balance, 2) . '</p>';
            ?>
            
            <div class="row">
                <div class="col-sm-12">
                    <div class="card-box table-responsive">
                        <table class="simple-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Amount</th>
                                    <th>Balance</th>
                                    <th>Payment Type</th>
                                    <th>Description</th>
                                    <th>Date</th>
                                    <th>Invoice</th>
                                    
                                </tr>
                            </thead>
                            
                            <tbody>
                                <?php
                                $select_qry = mysqli_query($my_connection, "
                                    SELECT ca.id, ca.customer_id, ca.purchase_invoice_id, ca.sale_invoice_id, 
                                           ca.expense_invoice_id, ca.user_salary_id, ca.amount, ca.payment_type, 
                                           ca.description, ca.dated, ca.type
                                    FROM customer_account AS ca
                                    WHERE ca.customer_id = '$account_id'
                                    AND DATE(ca.dated) BETWEEN '$from_dated_f' AND '$to_dated_f'
                                    ORDER BY ca.dated ASC, ca.id ASC
                                ") or die(mysqli_error($my_connection));
                                
                                $i = 1;
                                $total_paid = 0;
                                $total_received = 0;
                                
                                if(mysqli_num_rows($select_qry) > 0) {
                                    while($row = mysqli_fetch_array($select_qry)){
                                        echo '<tr>';
                                        echo '<td>' . $i++ . '</td>';
                                        
                                        // Amount column with Paid/Received badge
                                        $amount = $row["amount"];
                                        $formatted_amount = number_format($amount, 2);
                                        
                                        echo '<td>';
                                        if ($amount >= 0) {
                                            echo '<span class="badge badge-danger" style="font-size:10px;">Received</span> ';
                                        } else {
                                            echo '<span class="badge badge-success" style="font-size:10px;">Paid</span> ';
                                        }
                                        echo $formatted_amount;
                                        echo '</td>';
                                        
                                        // Update running balance
                                        $running_balance += $amount;
                                        
                                        // Balance column
                                        echo '<td>';
                                        echo '<span class="' . ($running_balance >= 0 ? 'balance-positive' : 'balance-negative') . '">';
                                        echo number_format($running_balance, 2);
                                        echo '</span>';
                                        echo '</td>';
                                        
                                        // Payment Type column
                                        echo '<td>';
                                        echo $row['payment_type'] ?? 'Cash';
                                        echo '</td>';
                                        
                                        // Description column
                                        echo '<td>' . $row["description"] . '</td>';
                                        
                                        // Date column
                                        echo '<td>' . date('d-M-y', strtotime($row["dated"])) . '</td>';
                                        
                                        // Invoice Number column
                                        echo '<td>';
                                        if (!empty($row["purchase_invoice_id"])) {
                                            echo "<a href='purchase_invoice_edit3.php?id=" . $row["purchase_invoice_id"] . 
                                            "&supplier_id=" . $account_id . 
                                            "&title=" . urlencode($customer_title) . 
                                            "' class='btn btn-warning btn-sm'>Purchase " . $row['purchase_invoice_id'] . "</a>";
                                        } elseif (!empty($row["sale_invoice_id"])) {
                                            echo "<a href='sale_invoice_edit_f.php?id=" . $row["sale_invoice_id"] . 
                                            "&customer_id=" . $account_id . 
                                            "&title=" . urlencode($customer_title) . 
                                            "' class='btn btn-success btn-sm'>Sale " . $row['sale_invoice_id'] . "</a>";
                                        } elseif (!empty($row["expense_invoice_id"])) {
                                            echo "<a href='expense_invoice_edit.php?id=" . $row["expense_invoice_id"] . 
                                            "&customer_id=" . $account_id . 
                                            "&title=" . urlencode($customer_title) . 
                                            "' class='btn btn-danger btn-sm'>Expense " . $row['expense_invoice_id'] . "</a>";
                                        } elseif (!empty($row["user_salary_id"])) {
                                            echo "<a href='salary_details.php?id=" . $row["user_salary_id"] . "' class='btn btn-info btn-sm'>Salary</a>";
                                        }
                                        echo '</td>';
                                        
                                        // Print button column
                                       
                                        
                                        echo '</tr>';
                                        
                                        // Update totals
                                        if ($amount >= 0) {
                                            $total_received += $amount;
                                        } else {
                                            $total_paid += abs($amount);
                                        }
                                    }
                                } else {
                                    echo '<tr><td colspan="8" class="text-center">No transactions found for the selected date range.</td></tr>';
                                }
                                ?>
                                
                                <?php if(mysqli_num_rows($select_qry) > 0): ?>
                                <!-- Summary Row -->
                               
                                
                                <tr class="summary-total">
                                    <td colspan="2" class="text-right"><strong>Closing Balance:</strong></td>
                                    <td><strong>
                                        <span class="<?php echo $running_balance >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                                            <?php echo number_format($running_balance, 2); ?>
                                        </span>
                                    </strong></td>
                                    <td colspan="5"></td>
                                </tr>
                                <tr class="summary-total">
                                    <td colspan="8" class="text-center">
                                        <?php
                                        if ($running_balance < 0) {
                                            echo $customer_title . ' will pay Rs: ' . number_format(abs($running_balance), 2);
                                        } else {
                                            echo $customer_title . ' will receive Rs: ' . number_format($running_balance, 2);
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <?php } elseif(isset($_POST['btncreate']) && empty($_POST['supplier_id'])) { ?>
            <!-- Show message when no account selected -->
            <div class="row">
                <div class="col-sm-12">
                    <div class="alert alert-warning">
                        <i class="fa fa-warning"></i> Please select an account to generate statement.
                    </div>
                </div>
            </div>
            <?php } else { ?>
            <!-- Show message when no report generated -->
            <div class="row">
                <div class="col-sm-12">
                    <div class="alert alert-info">
                        <i class="fa fa-info-circle"></i> Please select account and date range to generate statement.
                    </div>
                </div>
            </div>
            <?php } ?>

            <!-- Footer -->
            <span class="no-print">
                <?php require_once('my_footer.php'); ?>
            </span>
            <!-- End Footer -->
        </div>
    </div>

    <!-- jQuery and plugins -->
    <script src="assets/js/jquery.min.js"></script>
    <script src="assets/js/bootstrap.min.js"></script>
    <script src="assets/js/detect.js"></script>
    <script src="assets/js/fastclick.js"></script>
    <script src="assets/js/jquery.slimscroll.js"></script>
    <script src="assets/js/jquery.blockUI.js"></script>
    <script src="assets/js/waves.js"></script>
    <script src="assets/js/wow.min.js"></script>
    <script src="assets/js/jquery.nicescroll.js"></script>
    <script src="assets/js/jquery.scrollTo.min.js"></script>

    <!-- plugins js -->
    <script src="assets/plugins/moment/moment.js"></script>
    <script src="assets/plugins/timepicker/bootstrap-timepicker.js"></script>
    <script src="assets/plugins/bootstrap-colorpicker/js/bootstrap-colorpicker.min.js"></script>
    <script src="assets/plugins/bootstrap-datepicker/js/bootstrap-datepicker.min.js"></script>
    <script src="assets/plugins/clockpicker/js/bootstrap-clockpicker.min.js"></script>
    <script src="assets/plugins/bootstrap-daterangepicker/daterangepicker.js"></script>

    <!-- App core js -->
    <script src="assets/js/jquery.core.js"></script>
    <script src="assets/js/jquery.app.js"></script>
    <script src="assets/pages/jquery.form-pickers.init.js"></script>

    <script type="text/javascript">
        $(document).ready(function () {
            // Initialize datepickers
            $('#datepicker-autoclose').datepicker({
                format: 'yyyy-mm-dd',
                autoclose: true,
                todayHighlight: true
            });
            
            $('#datepicker-autoclose2').datepicker({
                format: 'yyyy-mm-dd',
                autoclose: true,
                todayHighlight: true
            });
            
            // Clear dates function
            window.clearDates = function() {
                $('#datepicker-autoclose').val('');
                $('#datepicker-autoclose2').val('');
                $('#ss').val('');
            };
            
            // Print transaction receipt function
            window.printTransactionReceipt = function(transactionId) {
                // You can implement print functionality here
                alert('Print receipt for transaction ID: ' + transactionId);
                // Example: window.open('print_receipt.php?id=' + transactionId, '_blank');
            };
            
            // Ensure header text stays black
            $('.simple-table thead th').css('color', '#000000');
        });
    </script>
</body>
</html>