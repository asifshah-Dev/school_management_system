<?php
require_once('security.php');
require_once('conn_inc.php');

// Error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Get filter values
$from_date = isset($_POST['from_date']) ? $_POST['from_date'] : date('Y-m-01');
$to_date = isset($_POST['to_date']) ? $_POST['to_date'] : date('Y-m-d');
$report_type = isset($_POST['report_type']) ? $_POST['report_type'] : 'summary';

// Keep date values in session for print
if(isset($_POST['from_date']) || isset($_POST['to_date'])) {
    $_SESSION['report_from_date'] = $from_date;
    $_SESSION['report_to_date'] = $to_date;
    $_SESSION['report_type'] = $report_type;
}

// Use session values if available
if(isset($_SESSION['report_from_date']) && !isset($_POST['from_date'])) {
    $from_date = $_SESSION['report_from_date'];
    $to_date = $_SESSION['report_to_date'];
    $report_type = $_SESSION['report_type'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Report</title>
    <style>
        /* Screen Styles */
        .report-header {
            background-color: #f8f9fa;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 5px;
            border: 1px solid #ddd;
        }
        .btn-group-custom {
            margin: 15px 0;
        }
        .btn-group-custom .btn {
            margin-right: 5px;
        }
        .table thead {
            background-color: #337ab7;
            color: white;
        }
        .grand-total {
            font-weight: bold;
            background-color: #dff0d8 !important;
            font-size: 16px;
        }
        .summary-row {
            background-color: #f9f9f9;
        }
        .zero-amount {
            color: #999;
            font-style: italic;
        }
        .report-title {
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #337ab7;
        }
        .badge-info {
            background-color: #5bc0de;
        }
        
        /* Print Styles - A4 Format with Proper Table Rows */
        @media print {
            /* Hide all screen elements */
            .no-print, .panel-heading, .panel-footer, .btn, 
            .report-header, .btn-group-custom, .navbar, 
            .container > .row > .col-md-12 > .panel > .panel-heading,
            .panel-footer, .report-title, .info {
                display: none !important;
            }
            
            /* A4 Page Settings */
            @page {
                size: A4;
                margin: 2cm;
            }
            
            body {
                margin: 0;
                padding: 0;
                background: #fff;
                font-family: Arial, sans-serif;
                line-height: 1.3;
            }
            
            .container {
                width: 100%;
                margin: 0;
                padding: 0;
            }
            
            .panel {
                border: none !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            
            .panel-body {
                padding: 0 !important;
            }
            
            /* Print-only elements */
            .print-only {
                display: block !important;
            }
            
            /* Print Header with School Name */
            .print-header {
                text-align: center;
                margin-bottom: 25px;
                font-family: Arial, sans-serif;
            }
            
            .table-bordered{
                border: 1px solid black !important;
            }
            
            .table th,
.table td {
    border: 1px solid #adb5bd !important;
}
            
            .print-header .school-name {
                font-size: 24px;
                font-weight: bold;
                text-transform: uppercase;
                letter-spacing: 1px;
                margin: 0 0 5px 0;
                padding: 0;
                color: #000;
            }
            
            .print-header .report-title-print {
                font-size: 18px;
                font-weight: bold;
                margin: 5px 0;
                padding: 0;
                color: #000;
                text-decoration: underline;
            }
            
            .print-header .report-period {
                font-size: 14px;
                margin: 5px 0;
                color: #000;
                font-weight: normal;
            }
            
            /* Table styles - Perfect for A4 */
            .table {
                width: 100%;
                border-collapse: collapse;
                margin: 20px 0;
                font-size: 12px;
                font-family: Arial, sans-serif;
                border: 2px solid #000;
            }
            
            .table th {
                background-color: #fff !important;
                color: #000 !important;
                font-weight: bold;
                border: 1px solid #000;
                padding: 10px 8px;
                text-align: left;
                font-size: 12px;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .table td {
                border: 1px solid #000;
                padding: 8px;
                vertical-align: top;
            }
            
            /* Each row gets proper borders */
            .table tr {
                border-bottom: 1px solid #000;
                page-break-inside: avoid;
            }
            
            /* Ensure last row of table has bottom border */
            .table tbody tr:last-child td {
                border-bottom: 2px solid #000;
            }
            
            /* Grand total row styling */
            .grand-total td {
                border-top: 2px solid #000 !important;
                border-bottom: 2px solid #000 !important;
                font-weight: bold;
                background-color: #fff !important;
            }
            
            /* Text alignment */
            .text-right {
                text-align: right;
            }
            
            .text-center {
                text-align: center;
            }
            
            /* Remove any background colors */
            * {
                background: transparent !important;
                color: #000 !important;
                box-shadow: none !important;
                text-shadow: none !important;
            }
            
            /* Ensure table headers repeat on each page */
            thead {
                display: table-header-group;
            }
            
            /* Table borders */
            table {
                border-top: 2px solid #000;
                border-bottom: 2px solid #000;
            }
            
            /* Hide badges and labels in print - replace with text */
            .badge, .label {
                border: none;
                background: transparent !important;
                color: #000 !important;
                padding: 0;
                font-weight: normal;
            }
            
            /* Remove any special formatting */
            .summary-row, .info, .zero-amount {
                background: transparent !important;
            }
            
            /* Ensure proper spacing for A4 */
            .table th, .table td {
                page-break-inside: avoid;
            }
        }
        
        /* Print-only elements */
        .print-only {
            display: none;
        }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="container">
    <div class="row">
        <div class="col-md-12">
            
            <!-- Main Panel -->
            <div class="panel panel-primary">
                <div class="panel-heading no-print">
                    <h3 class="panel-title">
                        <i class="fa fa-file-text-o"></i> Expense Report
                    </h3>
                </div>
                <div class="panel-body">

                    <!-- Date Filter Form (Screen Only) -->
                    <div class="report-header no-print">
                        <form method="post" action="" class="form-inline">
                            <div class="form-group">
                                <label for="from_date">From Date:</label>
                                <input type="date" class="form-control" id="from_date" 
                                       name="from_date" value="<?php echo $from_date; ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="to_date">To Date:</label>
                                <input type="date" class="form-control" id="to_date" 
                                       name="to_date" value="<?php echo $to_date; ?>" required>
                            </div>
                            <button type="submit" name="report_type" value="summary" class="btn btn-primary">
                                <i class="fa fa-search"></i> Generate Report
                            </button>
                        </form>
                    </div>

                    <!-- Report Buttons (Screen Only) -->
                    <div class="btn-group-custom no-print">
                        <form method="post" action="" style="display: inline;">
                            <input type="hidden" name="from_date" value="<?php echo $from_date; ?>">
                            <input type="hidden" name="to_date" value="<?php echo $to_date; ?>">
                            <button type="submit" name="report_type" value="summary" 
                                    class="btn btn-<?php echo ($report_type == 'summary') ? 'success' : 'default'; ?>">
                                <i class="fa fa-pie-chart"></i> Summary Report
                            </button>
                            <button type="submit" name="report_type" value="detail" 
                                    class="btn btn-<?php echo ($report_type == 'detail') ? 'success' : 'default'; ?>">
                                <i class="fa fa-list"></i> Detail Report
                            </button>
                        </form>
                        <button onclick="window.print()" class="btn btn-info">
                            <i class="fa fa-print"></i> Print
                        </button>
                    </div>

                    <!-- Print Header with School Name (Print Only) - A4 Format -->
                    <div class="print-only print-header">
                        <div class="school-name">DARA-E-ARQAM SCHOOL AND COLLEGE</div>
                        <div class="report-title-print">
                            <?php echo ($report_type == 'summary') ? 'EXPENSE SUMMARY REPORT' : 'EXPENSE DETAIL REPORT'; ?>
                        </div>
                        <div class="report-period">
                            Period: <?php echo date('d-m-Y', strtotime($from_date)); ?> to <?php echo date('d-m-Y', strtotime($to_date)); ?>
                        </div>
                    </div>

                    <!-- Report Title and Period (Screen Only) -->
                    <div class="report-title no-print">
                        <h4>
                            <?php echo ($report_type == 'summary') ? 'Summary Report' : 'Detail Report'; ?>
                            <small> (From: <?php echo date('d-m-Y', strtotime($from_date)); ?> 
                            To: <?php echo date('d-m-Y', strtotime($to_date)); ?>)</small>
                        </h4>
                    </div>

                    <?php if($report_type == 'summary'): ?>
                        <!-- Summary Report - Expense Head Wise -->
                        <?php
                        $query = "SELECT 
                                    ec.id,
                                    ec.title as expense_head,
                                    COUNT(e.id) as transaction_count,
                                    COALESCE(SUM(e.total_amount), 0) as total_amount
                                  FROM expense_categories ec
                                  LEFT JOIN expenses e ON ec.id = e.expense_categories_id 
                                      AND e.invoice_date BETWEEN ? AND ?
                                      AND e.status = 1
                                  WHERE ec.status = 1
                                  GROUP BY ec.id, ec.title
                                  ORDER BY ec.title";
                        
                        $stmt = $conn->prepare($query);
                        
                        if(!$stmt) {
                            echo '<div class="alert alert-danger">Query Preparation Failed: ' . $conn->error . '</div>';
                        } else {
                            $stmt->bind_param("ss", $from_date, $to_date);
                            $stmt->execute();
                            $result = $stmt->get_result();
                        ?>
                        
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th width="50">#</th>
                                        <th>Expense Head</th>
                                        <th width="150" class="text-center">No. of Transactions</th>
                                        <th width="200" class="text-right">Total Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    if($result->num_rows > 0):
                                        $counter = 1;
                                        $grand_total = 0;
                                        $categories_with_expenses = 0;
                                        
                                        while($row = $result->fetch_assoc()): 
                                            $grand_total += $row['total_amount'];
                                            if($row['total_amount'] > 0) {
                                                $categories_with_expenses++;
                                            }
                                    ?>
                                        <tr class="<?php echo ($row['total_amount'] > 0) ? 'summary-row' : ''; ?>">
                                            <td><?php echo $counter++; ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($row['expense_head']); ?>
                                                <?php if($row['total_amount'] == 0): ?>
                                                    <small class="text-muted">(No expenses)</small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if($row['transaction_count'] > 0): ?>
                                                    <span class="badge" style="background-color: #5bc0de;"><?php echo $row['transaction_count']; ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-right <?php echo ($row['total_amount'] == 0) ? 'zero-amount' : ''; ?>">
                                                <?php echo number_format($row['total_amount'], 2); ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                        <tr class="grand-total">
                                            <td colspan="3" class="text-right">
                                                <strong>Grand Total:</strong>
                                            </td>
                                            <td class="text-right"><strong><?php echo number_format($grand_total, 2); ?></strong></td>
                                        </tr>
                                        
                                        <!-- Summary row (Screen Only) -->
                                        <tr class="info no-print">
                                            <td colspan="4">
                                                <i class="fa fa-info-circle"></i> 
                                                <strong>Summary:</strong> 
                                                Total Active Categories: <?php echo $result->num_rows; ?> | 
                                                Categories with Expenses: <?php echo $categories_with_expenses; ?> | 
                                                Categories without Expenses: <?php echo ($result->num_rows - $categories_with_expenses); ?>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-danger">
                                                <i class="fa fa-exclamation-triangle"></i> 
                                                No active expense categories found. Please add expense categories first.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <?php
                            $stmt->close();
                        }
                        ?>

                    <?php else: ?>
                        <!-- Detail Report - All Expense Records -->
                        <?php
                        // First check if there are any expenses
                        $check_query = "SELECT COUNT(*) as total FROM expenses WHERE invoice_date BETWEEN ? AND ? AND status = 1";
                        $check_stmt = $conn->prepare($check_query);
                        $check_stmt->bind_param("ss", $from_date, $to_date);
                        $check_stmt->execute();
                        $check_result = $check_stmt->get_result();
                        $total_expenses = $check_result->fetch_assoc()['total'];
                        $check_stmt->close();
                        
                        if($total_expenses > 0) {
                            $query = "SELECT 
                                        e.invoice_date,
                                        ec.title as expense_head,
                                        e.description,
                                        e.total_amount,
                                        e.payment_type
                                      FROM expenses e
                                      INNER JOIN expense_categories ec ON e.expense_categories_id = ec.id
                                      WHERE e.invoice_date BETWEEN ? AND ? 
                                        AND e.status = 1
                                      ORDER BY e.invoice_date DESC, ec.title";
                            
                            $stmt = $conn->prepare($query);
                            $stmt->bind_param("ss", $from_date, $to_date);
                            $stmt->execute();
                            $result = $stmt->get_result();
                        }
                        ?>
                        
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th width="50">#</th>
                                        <th>Date</th>
                                        <th>Expense Head</th>
                                        <th>Description</th>
                                        <th> Type</th>
                                        <th width="150" class="text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    if(isset($total_expenses) && $total_expenses > 0 && isset($result) && $result->num_rows > 0):
                                        $counter = 1;
                                        $grand_total = 0;
                                        while($row = $result->fetch_assoc()): 
                                            $grand_total += $row['total_amount'];
                                    ?>
                                        <tr>
                                            <td><?php echo $counter++; ?></td>
                                            <td><?php echo date('d-m-Y', strtotime($row['invoice_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($row['expense_head']); ?></td>
                                            <td><?php echo htmlspecialchars($row['description']); ?></td>
                                            <td>
                                                <?php 
                                                $payment_type = ucfirst($row['payment_type']);
                                                $label_class = ($row['payment_type'] == 'cash') ? 'success' : 'info';
                                                echo "<span class='label label-$label_class'>$payment_type</span>";
                                                ?>
                                            </td>
                                            <td class="text-right"><?php echo number_format($row['total_amount'], 2); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                        <tr class="grand-total">
                                            <td colspan="5" class="text-right"><strong>Grand Total:</strong></td>
                                            <td class="text-right"><strong><?php echo number_format($grand_total, 2); ?></strong></td>
                                        </tr>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-danger">
                                                <i class="fa fa-info-circle"></i> 
                                                No expense records found for the selected date range.
                                                <?php if(isset($total_expenses) && $total_expenses == 0): ?>
                                                    <br><small>No expenses have been added yet.</small>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if(isset($stmt)) $stmt->close(); ?>
                    <?php endif; ?>

                </div>
                
              
            </div>
        </div>
    </div>
</div>


<script>
$(document).ready(function() {
    // Add tooltips
    $('[data-toggle="tooltip"]').tooltip();
    
    // Highlight rows on hover
    $('.summary-row').hover(
        function() { $(this).css('background-color', '#e7f3ff'); },
        function() { $(this).css('background-color', '#f9f9f9'); }
    );
});
</script>

</body>
</html>
<?php $conn->close(); ?>