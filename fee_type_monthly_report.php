<?php 
require_once('security.php'); 
require_once('conn_inc.php');

// Get month range from request (based on payment_date)
$from_month = isset($_GET['from_month']) ? $_GET['from_month'] : date('Y-m');
$to_month = isset($_GET['to_month']) ? $_GET['to_month'] : date('Y-m');
$session_id = isset($_GET['session_id']) ? $_GET['session_id'] : '';
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'summary'; // summary or detail

// Get sessions for dropdown
$sessions = $conn->query("SELECT id, title FROM sessions WHERE status = 0 ORDER BY from_dated DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <title>Fee Type Monthly Report - Madrasa Management System</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap CSS & JS -->
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
  
  <!-- Font Awesome for icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  
  <link rel="stylesheet" href="css/mystyle.css" />

  <style>
    .filter-section {
      background: #f8f9fa;
      padding: 20px;
      border-radius: 5px;
      margin-bottom: 30px;
      border: 1px solid #000;
    }
    
    .report-table {
      background: #fff;
      padding: 20px;
      border-radius: 5px;
      border: 2px solid #000;
      margin-bottom: 20px;
    }
    
    .month-group {
      background: #f8f9fa;
      font-weight: bold;
    }
    
    .month-group td {
      border-bottom: 2px solid #000 !important;
    }
    
    .btn-details {
      padding: 5px 15px;
      font-size: 12px;
    }
    
    .grand-total-row {
      background: #343a40 !important;
      color: white !important;
      font-weight: bold;
    }
    
    @media print {
      .no-print, .navbar, .filter-section, .btn-details {
        display: none !important;
      }
    }
    
    .text-right {
      text-align: right;
    }
    
    /* Solid black borders for tables */
    table, th, td {
      border: 2px solid #000 !important;
      border-collapse: collapse;
    }
    
    th {
      background-color: #f2f2f2;
      font-weight: bold;
    }
    
    .table-bordered > thead > tr > th,
    .table-bordered > tbody > tr > th,
    .table-bordered > tfoot > tr > th,
    .table-bordered > thead > tr > td,
    .table-bordered > tbody > tr > td,
    .table-bordered > tfoot > tr > td {
      border: 2px solid #000 !important;
    }
  </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="container-fluid">
  <!-- Page Header -->
  <div class="row">
    <div class="col-md-12">
      <h3><i class="fas fa-file-invoice"></i> Fee Collection Report</h3>
      <p class="text-muted">Select payment date range to view fee collection summary</p>
    </div>
  </div>

  <!-- Filter Section with Month Picker -->
  <div class="filter-section no-print">
    <form method="GET" action="" class="form-inline">
      <div class="row">
        <div class="col-md-2">
          <div class="form-group">
            <label><i class="fas fa-calendar-alt"></i> From Month:</label>
            <input type="month" name="from_month" class="form-control" value="<?php echo $from_month; ?>" required>
          </div>
        </div>
        <div class="col-md-2">
          <div class="form-group">
            <label><i class="fas fa-calendar-alt"></i> To Month:</label>
            <input type="month" name="to_month" class="form-control" value="<?php echo $to_month; ?>" required>
          </div>
        </div>
        <div class="col-md-2">
          <div class="form-group">
            <label><i class="fas fa-calendar"></i> Session:</label>
            <select name="session_id" class="form-control">
              <option value="">All Sessions</option>
              <?php while($session = $sessions->fetch_assoc()): ?>
                <option value="<?php echo $session['id']; ?>" <?php echo $session_id == $session['id'] ? 'selected' : ''; ?>>
                  <?php echo $session['title']; ?>
                </option>
              <?php endwhile; ?>
            </select>
          </div>
        </div>
        <div class="col-md-3">
          <div class="form-group">
            <label><i class="fas fa-chart-bar"></i> Report Type:</label>
            <select name="report_type" class="form-control" onchange="this.form.submit()">
              <option value="summary" <?php echo $report_type == 'summary' ? 'selected' : ''; ?>>Summary (Monthly Totals)</option>
              <option value="detail" <?php echo $report_type == 'detail' ? 'selected' : ''; ?>>Detail (All Transactions)</option>
            </select>
          </div>
        </div>
        <div class="col-md-3">
          <button type="submit" class="btn btn-primary" style="margin-top: 24px;">
            <i class="fas fa-search"></i> Generate Report
          </button>
          <button type="button" class="btn btn-default" style="margin-top: 24px; margin-left: 10px;" onclick="window.print()">
            <i class="fas fa-print"></i> Print
          </button>
        </div>
      </div>
    </form>
  </div>

  <?php
  // Convert month format to date range for payment_date
  $from_date = $from_month . '-01';
  $to_date = date('Y-m-t', strtotime($to_month . '-01'));
  
  if ($report_type == 'summary') {
      // SUMMARY REPORT: Monthly totals based on payment_date
      $monthly_summary_query = "
        SELECT 
          DATE_FORMAT(sfp.payment_date, '%Y-%m') as month_key,
          DATE_FORMAT(sfp.payment_date, '%M %Y') as month_name,
          COUNT(DISTINCT sfp.id) as total_transactions,
          COUNT(DISTINCT sfc.student_class_id) as total_students,
          SUM(sfp.paid_amount) as total_paid
        FROM student_fee_payments sfp
        INNER JOIN student_fee_card sfc ON sfp.fee_card_id = sfc.id
        WHERE DATE_FORMAT(sfp.payment_date, '%Y-%m') BETWEEN ? AND ?
      ";
      
      if (!empty($session_id)) {
        $monthly_summary_query .= " AND sfc.session_id = ?";
        $stmt = $conn->prepare($monthly_summary_query . " GROUP BY month_key, month_name ORDER BY month_key DESC");
        $stmt->bind_param("ssi", $from_month, $to_month, $session_id);
      } else {
        $stmt = $conn->prepare($monthly_summary_query . " GROUP BY month_key, month_name ORDER BY month_key DESC");
        $stmt->bind_param("ss", $from_month, $to_month);
      }
      
      $stmt->execute();
      $monthly_result = $stmt->get_result();
      
      // Calculate grand totals
      $grand_total_transactions = 0;
      $grand_total_students = 0;
      $grand_total_paid = 0;
  ?>

  <!-- SUMMARY REPORT VIEW -->
  <div class="report-table">
    <h4>Monthly Fee Collection Summary (by Payment Date)
      <small class="pull-right">
        Period: <?php echo date('F Y', strtotime($from_date)); ?> to <?php echo date('F Y', strtotime($to_date)); ?>
      </small>
    </h4>
    <hr>
    
    <div class="table-responsive">
      <table class="table table-bordered" id="monthlyReportTable">
        <thead>
          <tr>
            <th>#</th>
            <th>Payment Month</th>
            <th class="text-right">Students</th>
            <th class="text-right">Transactions</th>
            <th class="text-right">Paid Amount</th>
           
          </tr>
        </thead>
        <tbody>
          <?php 
          $counter = 1;
          if ($monthly_result->num_rows > 0) {
            while($month = $monthly_result->fetch_assoc()): 
              $grand_total_transactions += $month['total_transactions'];
              $grand_total_students += $month['total_students'];
              $grand_total_paid += $month['total_paid'];
          ?>
            <!-- Month Summary Row -->
            <tr class="month-group">
              <td><?php echo $counter++; ?></td>
              <td>
                <strong><?php echo $month['month_name']; ?></strong>
              </td>
              <td class="text-right"><?php echo number_format($month['total_students']); ?></td>
              <td class="text-right"><?php echo number_format($month['total_transactions']); ?></td>
              <td class="text-right"><strong>Rs. <?php echo number_format($month['total_paid'], 2); ?></strong></td>
              
            </tr>
          <?php 
            endwhile; 
          } else {
          ?>
            <tr>
              <td colspan="5" class="text-center">No records found for the selected period</td>
            </tr>
          <?php } ?>
        </tbody>
        <tfoot>
          <tr class="grand-total-row">
            <th colspan="2" class="text-right">GRAND TOTAL:</th>
            <th class="text-right"><?php echo number_format($grand_total_students); ?></th>
            <th class="text-right"><?php echo number_format($grand_total_transactions); ?></th>
            <th class="text-right">Rs. <?php echo number_format($grand_total_paid, 2); ?></th>
            <th></th>
          </tr>
        </tfoot>
      </table>
    </div>

  <?php 
  } else {
      // DETAIL REPORT: Show all individual transactions with both payment date and due month
      $detail_query = "
        SELECT 
          sfp.id as payment_id,
          sfp.paid_amount,
          sfp.payment_date,
          DATE_FORMAT(sfp.payment_date, '%d-%m-%Y') as payment_date_formatted,
          sfp.payment_method,
          sfp.remarks as payment_remarks,
          sfc.due_date as fee_month,
          DATE_FORMAT(sfc.due_date, '%M %Y') as fee_month_display,
          ft.title as fee_type_title,
          sr.name as student_name,
          sr.father_name,
          sr.reg_no,
          c.title as class_title
        FROM student_fee_payments sfp
        INNER JOIN student_fee_card sfc ON sfp.fee_card_id = sfc.id
        INNER JOIN fee_types ft ON sfc.fee_type_id = ft.id
        INNER JOIN student_class sc ON sfc.student_class_id = sc.id
        INNER JOIN student_registration sr ON sc.student_registration_id = sr.id
        INNER JOIN classes c ON sc.class_id = c.id
        WHERE DATE_FORMAT(sfp.payment_date, '%Y-%m') BETWEEN ? AND ?
      ";
      
      $params = [$from_month, $to_month];
      $types = "ss";
      
      if (!empty($session_id)) {
        $detail_query .= " AND sfc.session_id = ?";
        $params[] = $session_id;
        $types .= "i";
      }
      
      $detail_query .= " ORDER BY sfp.payment_date DESC, sr.name";
      
      $stmt = $conn->prepare($detail_query);
      $stmt->bind_param($types, ...$params);
      $stmt->execute();
      $detail_result = $stmt->get_result();
      
      $total_payments = 0;
      $total_amount = 0;
  ?>

  <!-- DETAIL REPORT VIEW -->
  <div class="report-table">
    <h4>Detailed Fee Collection Report
      <small class="pull-right">
        Payment Period: <?php echo date('F Y', strtotime($from_date)); ?> to <?php echo date('F Y', strtotime($to_date)); ?>
      </small>
    </h4>
    <hr>
    
    <div class="table-responsive">
      <table class="table table-bordered" id="detailReportTable">
        <thead>
          <tr>
            <th>#</th>
            <th>Payment Date</th>
            <th>Student Name</th>
            <th>Father Name</th>
           
            <th>Class</th>
            <th>Fee Type</th>
            <th>Fee Month (Due Date)</th>
            <th class="text-right">Amount</th>
         
            <th>Remarks</th>
          </tr>
        </thead>
        <tbody>
          <?php 
          $counter = 1;
          if ($detail_result->num_rows > 0) {
            while($row = $detail_result->fetch_assoc()): 
              $total_payments++;
              $total_amount += $row['paid_amount'];
          ?>
            <tr>
              <td><?php echo $counter++; ?></td>
              <td><?php echo $row['payment_date_formatted']; ?></td>
              <td><?php echo htmlspecialchars($row['student_name']); ?></td>
              <td><?php echo htmlspecialchars($row['father_name']); ?></td>
             
              <td><?php echo htmlspecialchars($row['class_title']); ?></td>
              <td><?php echo htmlspecialchars($row['fee_type_title']); ?></td>
              <td>
                <?php 
                // Display fee month from due_date
                if (!empty($row['fee_month'])) {
                    echo $row['fee_month_display'];
                } else {
                    echo 'N/A';
                }
                ?>
              </td>
              <td class="text-right">Rs. <?php echo number_format($row['paid_amount'], 0); ?></td>
              
              <td><small><?php echo htmlspecialchars(substr($row['payment_remarks'] ?? '', 0, 15)); ?></small></td>
            </tr>
          <?php 
            endwhile; 
          } else {
          ?>
            <tr>
              <td colspan="11" class="text-center">No records found for the selected period</td>
            </tr>
          <?php } ?>
        </tbody>
        <tfoot>
          <tr class="grand-total-row">
            <td colspan="8" class="text-right">TOTAL (<?php echo $total_payments; ?> Payments):</td>
            <td class="text-right">Rs. <?php echo number_format($total_amount, 0); ?></td>
            
          </tr>
        </tfoot>
      </table>
    </div>
  <?php } ?>
    
    <div class="row">
      <div class="col-md-12">
        <p class="text-muted">
          <i class="fas fa-info-circle"></i> 
          Report filtered by <strong>Payment Date</strong>. 
          <?php if($report_type == 'summary'): ?>
            Summary view shows monthly totals based on when payments were received.
          <?php else: ?>
            Detail view shows each transaction with Payment Date and the Fee Month (from due_date).
          <?php endif; ?>
          Generated on <?php echo date('d-m-Y h:i A'); ?>
        </p>
      </div>
    </div>
  </div>

</div>

<script>
$(document).ready(function() {
    // Auto-submit when report type changes
    $('select[name="report_type"]').change(function() {
        $(this).closest('form').submit();
    });
});
</script>

</body>
</html>