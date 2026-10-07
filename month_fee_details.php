<?php 
require_once('security.php'); 
require_once('conn_inc.php');

$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$session_id = isset($_GET['session_id']) ? $_GET['session_id'] : '';

// Debug - Check if month is received
if(empty($month)) {
    die("Error: Month parameter is missing");
}

// Parse month for display
if (strlen($month) == 7) { // Format: YYYY-MM
    $year = substr($month, 0, 4);
    $month_num = substr($month, 5, 2);
    $month_name = date('F Y', mktime(0, 0, 0, $month_num, 1, $year));
} else {
    $month_name = date('F Y', strtotime($month . '-01'));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <title>Monthly Fee Details - <?php echo $month_name; ?></title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="css/mystyle.css" />
  
  <style>
    .page-header {
      background: #337ab7;
      color: white;
      padding: 15px 20px;
      border-radius: 5px 5px 0 0;
      margin-bottom: 20px;
      border: 2px solid #000;
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
    
    .panel {
      border: 2px solid #000;
    }
    
    .panel-heading {
      border-bottom: 2px solid #000;
      font-weight: bold;
    }
    
    .grand-total {
      background: #343a40;
      color: white;
      font-weight: bold;
      font-size: 18px;
    }
    
    @media print {
      .no-print { display: none; }
    }
  </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="container-fluid">
  <div class="row">
    <div class="col-md-12">
      <div class="page-header">
        <h3><i class="fas fa-calendar-alt"></i> Monthly Fee Collection Details</h3>
        <h4>Payment Month: <?php echo $month_name; ?></h4>
      </div>
    </div>
  </div>

  <div class="row no-print" style="margin: 20px 0;">
    <div class="col-md-12">
      <a href="fee_type_monthly_report.php?from_month=<?php echo $month; ?>&to_month=<?php echo $month; ?>&session_id=<?php echo $session_id; ?>&report_type=summary" class="btn btn-primary">
        <i class="fas fa-arrow-left"></i> Back to Report
      </a>
      <button onclick="window.print()" class="btn btn-default">
        <i class="fas fa-print"></i> Print
      </button>
    </div>
  </div>

  <?php
  // First, let's check if there are any records for this month
  $check_query = "SELECT COUNT(*) as total FROM student_fee_payments WHERE DATE_FORMAT(payment_date, '%Y-%m') = ?";
  $check_stmt = $conn->prepare($check_query);
  $check_stmt->bind_param("s", $month);
  $check_stmt->execute();
  $check_result = $check_stmt->get_result();
  $check_row = $check_result->fetch_assoc();
  
  if($check_row['total'] == 0) {
      echo '<div class="alert alert-warning">No payment records found for ' . $month_name . '</div>';
  }
  
  // Get summary by fee type for this payment month
  $summary_query = "
    SELECT 
      ft.id as fee_type_id,
      ft.title as fee_type_title,
      ft.frequency,
      COUNT(DISTINCT sfp.id) as transaction_count,
      COUNT(DISTINCT sfc.student_class_id) as student_count,
      COALESCE(SUM(sfp.paid_amount), 0) as total_paid
    FROM student_fee_payments sfp
    INNER JOIN student_fee_card sfc ON sfp.fee_card_id = sfc.id
    INNER JOIN fee_types ft ON sfc.fee_type_id = ft.id
    WHERE DATE_FORMAT(sfp.payment_date, '%Y-%m') = ?
  ";
  
  $params = [$month];
  $types = "s";
  
  if (!empty($session_id)) {
    $summary_query .= " AND sfc.session_id = ?";
    $params[] = $session_id;
    $types .= "i";
  }
  
  $summary_query .= " GROUP BY ft.id, ft.title, ft.frequency ORDER BY ft.title";
  
  $stmt = $conn->prepare($summary_query);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $summary_result = $stmt->get_result();
  
  // Get all transactions for this payment month with both payment date and due month
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
      ft.frequency,
      sr.name as student_name,
      sr.father_name,
      sr.reg_no,
      c.title as class_title,
      s.title as section_title
    FROM student_fee_payments sfp
    INNER JOIN student_fee_card sfc ON sfp.fee_card_id = sfc.id
    INNER JOIN fee_types ft ON sfc.fee_type_id = ft.id
    INNER JOIN student_class sc ON sfc.student_class_id = sc.id
    INNER JOIN student_registration sr ON sc.student_registration_id = sr.id
    INNER JOIN classes c ON sc.class_id = c.id
    LEFT JOIN class_sections cs ON sc.class_id = cs.class_id
    LEFT JOIN sections s ON cs.section_id = s.id
    WHERE DATE_FORMAT(sfp.payment_date, '%Y-%m') = ?
  ";
  
  $detail_params = [$month];
  $detail_types = "s";
  
  if (!empty($session_id)) {
    $detail_query .= " AND sfc.session_id = ?";
    $detail_params[] = $session_id;
    $detail_types .= "i";
  }
  
  $detail_query .= " ORDER BY sfp.payment_date DESC, sr.name";
  
  $detail_stmt = $conn->prepare($detail_query);
  $detail_stmt->bind_param($detail_types, ...$detail_params);
  $detail_stmt->execute();
  $detail_result = $detail_stmt->get_result();
  
  $grand_total = 0;
  ?>

  <!-- Summary Cards -->
  <div class="row">
    <?php
    $total_students = 0;
    $total_transactions = 0;
    
    if($summary_result->num_rows > 0) {
        while($sum = $summary_result->fetch_assoc()) {
          $total_students += $sum['student_count'];
          $total_transactions += $sum['transaction_count'];
          $grand_total += $sum['total_paid'];
        }
        // Reset summary result pointer
        $summary_result->data_seek(0);
    }
    ?>
    
    <div class="col-md-3">
      <div class="panel panel-info">
        <div class="panel-heading">Total Students</div>
        <div class="panel-body text-center" style="font-size: 24px; font-weight: bold;">
          <?php echo $total_students; ?>
        </div>
      </div>
    </div>
    
    <div class="col-md-3">
      <div class="panel panel-success">
        <div class="panel-heading">Total Transactions</div>
        <div class="panel-body text-center" style="font-size: 24px; font-weight: bold;">
          <?php echo $total_transactions; ?>
        </div>
      </div>
    </div>
    
    <div class="col-md-3">
      <div class="panel panel-warning">
        <div class="panel-heading">Fee Types</div>
        <div class="panel-body text-center" style="font-size: 24px; font-weight: bold;">
          <?php echo $summary_result->num_rows; ?>
        </div>
      </div>
    </div>
    
    <div class="col-md-3">
      <div class="panel panel-danger">
        <div class="panel-heading">Total Collection</div>
        <div class="panel-body text-center" style="font-size: 24px; font-weight: bold;">
          Rs. <?php echo number_format($grand_total, 0); ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Fee Type Summary Table -->
  <div class="panel panel-default">
    <div class="panel-heading">
      <h4>Collection by Fee Type</h4>
    </div>
    <div class="table-responsive">
      <table class="table table-bordered">
        <thead>
          <tr>
            <th>Fee Type</th>
            <th>Frequency</th>
            <th class="text-right">Students</th>
            <th class="text-right">Transactions</th>
            <th class="text-right">Amount</th>
          </tr>
        </thead>
        <tbody>
          <?php 
          if($summary_result->num_rows > 0) {
              while($sum = $summary_result->fetch_assoc()): 
          ?>
          <tr>
            <td><strong><?php echo htmlspecialchars($sum['fee_type_title']); ?></strong></td>
            <td>
              <?php if($sum['frequency'] == 'monthly'): ?>
                <span class="label label-info">Monthly</span>
              <?php else: ?>
                <span class="label label-warning">One Time</span>
              <?php endif; ?>
            </td>
            <td class="text-right"><?php echo $sum['student_count']; ?></td>
            <td class="text-right"><?php echo $sum['transaction_count']; ?></td>
            <td class="text-right">Rs. <?php echo number_format($sum['total_paid'], 0); ?></td>
          </tr>
          <?php 
              endwhile; 
          } else {
          ?>
          <tr>
              <td colspan="5" class="text-center">No summary data found</td>
          </tr>
          <?php } ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Detailed Transactions Table -->
  <div class="panel panel-default">
    <div class="panel-heading">
      <h4>All Transactions (Payment Date: <?php echo $month_name; ?>)</h4>
    </div>
    <div class="table-responsive">
      <table class="table table-bordered table-striped">
        <thead>
          <tr>
            <th>#</th>
            <th>Payment Date</th>
            <th>Student Name</th>
            <th>Father Name</th>
            <th>Reg No</th>
            <th>Class</th>
            <th>Fee Type</th>
            <th>Fee Month (Due Date)</th>
            <th class="text-right">Amount</th>
            <th>Method</th>
            <th>Remarks</th>
          </tr>
        </thead>
        <tbody>
          <?php 
          $counter = 1;
          if ($detail_result->num_rows > 0) {
            while($row = $detail_result->fetch_assoc()): 
          ?>
          <tr>
            <td><?php echo $counter++; ?></td>
            <td><?php echo $row['payment_date_formatted']; ?></td>
            <td><?php echo htmlspecialchars($row['student_name']); ?></td>
            <td><?php echo htmlspecialchars($row['father_name']); ?></td>
            <td><?php echo htmlspecialchars($row['reg_no']); ?></td>
            <td>
              <?php echo htmlspecialchars($row['class_title']); ?>
              <?php if(!empty($row['section_title'])): ?>
                (<?php echo $row['section_title']; ?>)
              <?php endif; ?>
            </td>
            <td>
              <?php echo htmlspecialchars($row['fee_type_title']); ?>
              <?php if($row['frequency'] == 'monthly'): ?>
                <small>(Monthly)</small>
              <?php endif; ?>
            </td>
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
            <td><?php echo ucfirst($row['payment_method']); ?></td>
            <td><small><?php echo htmlspecialchars(substr($row['payment_remarks'] ?? '', 0, 20)); ?></small></td>
          </tr>
          <?php 
            endwhile; 
          } else {
          ?>
            <tr>
              <td colspan="11" class="text-center">No transactions found for this payment month</td>
            </tr>
          <?php } ?>
        </tbody>
        <tfoot>
          <tr class="grand-total">
            <td colspan="8" class="text-right">GRAND TOTAL:</td>
            <td class="text-right">Rs. <?php echo number_format($grand_total, 0); ?></td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <div class="row">
    <div class="col-md-12 text-muted">
      <small>Report generated on <?php echo date('d-m-Y h:i A'); ?></small>
    </div>
  </div>
</div>

<!-- Debug Information (Remove in production) -->
<div class="row no-print">
    <div class="col-md-12">
        <div class="panel panel-default">
            <div class="panel-heading">Debug Info</div>
            <div class="panel-body">
                <p><strong>Month Parameter:</strong> <?php echo $month; ?></p>
                <p><strong>Session ID:</strong> <?php echo $session_id ?: 'None'; ?></p>
                <p><strong>Total Records in Detail:</strong> <?php echo $detail_result->num_rows; ?></p>
                <p><strong>Total Records in Summary:</strong> <?php echo $summary_result->num_rows; ?></p>
            </div>
        </div>
    </div>
</div>

</body>
</html>
<?php $conn->close(); ?>