<?php 
require_once('security.php'); 
require_once('conn_inc.php');

// Get date range from request
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-t');
$session_id = isset($_GET['session_id']) ? $_GET['session_id'] : '';

// Get sessions for dropdown
$sessions = $conn->query("SELECT id, title FROM sessions WHERE status = 0 ORDER BY from_dated DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <title>Fee Type Group Report - Madrasa Management System</title>
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
      border: 1px solid #dee2e6;
    }
    
    .report-table {
      background: #fff;
      padding: 20px;
      border-radius: 5px;
      border: 1px solid #dee2e6;
      margin-bottom: 20px;
    }
    
    .month-group {
      background: #f8f9fa;
      font-weight: bold;
      cursor: pointer;
    }
    
    .month-group:hover {
      background: #e9ecef;
    }
    
    .month-group td {
      border-bottom: 2px solid #dee2e6 !important;
    }
    
    .details-row {
      display: none;
      background: #fff;
    }
    
    .details-row td {
      padding: 0 !important;
    }
    
    .details-table {
      margin: 0;
      background: #f5f5f5;
    }
    
    .details-table td {
      border-top: none !important;
    }
    
    .details-table tr:last-child td {
      border-bottom: 1px solid #dee2e6;
    }
    
    .btn-details {
      padding: 2px 8px;
      font-size: 12px;
    }
    
    .month-total {
      background: #e3f2fd;
    }
    
    .grand-total-row {
      background: #343a40 !important;
      color: white !important;
      font-weight: bold;
    }
    
    .badge-monthly { background: #e3f2fd; color: #1976d2; }
    .badge-yearly { background: #e8f5e8; color: #2e7d32; }
    .badge-one-time { background: #fff3e0; color: #f57c00; }
    
    @media print {
      .no-print, .navbar, .filter-section, .btn-details {
        display: none !important;
      }
    }
    
    .text-right {
      text-align: right;
    }
    
    .expand-icon {
      font-size: 16px;
      margin-right: 5px;
    }
  </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="container-fluid">
  <!-- Page Header -->
  <div class="row">
    <div class="col-md-12">
      <h3><i class="fas fa-file-invoice"></i> Fee Type Group Report - Monthly Summary</h3>
      <p class="text-muted">Click on any month to view detailed fee type breakdown</p>
    </div>
  </div>

  <!-- Filter Section -->
  <div class="filter-section no-print">
    <form method="GET" action="" class="form-inline">
      <div class="row">
        <div class="col-md-3">
          <div class="form-group">
            <label><i class="fas fa-calendar-alt"></i> From Date:</label>
            <input type="date" name="from_date" class="form-control" value="<?php echo $from_date; ?>" required>
          </div>
        </div>
        <div class="col-md-3">
          <div class="form-group">
            <label><i class="fas fa-calendar-alt"></i> To Date:</label>
            <input type="date" name="to_date" class="form-control" value="<?php echo $to_date; ?>" required>
          </div>
        </div>
        <div class="col-md-3">
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
  // Query to get monthly summary
  $monthly_summary_query = "
    SELECT 
      DATE_FORMAT(sfp.payment_date, '%Y-%m') as month_key,
      DATE_FORMAT(sfp.payment_date, '%M %Y') as month_name,
      COUNT(DISTINCT sfp.id) as total_transactions,
      COUNT(DISTINCT sfc.student_class_id) as total_students,
      SUM(sfp.paid_amount) as total_collected,
      SUM(sfp.discount) as total_discount
    FROM student_fee_payments sfp
    INNER JOIN student_fee_card sfc ON sfp.fee_card_id = sfc.id
    WHERE sfp.payment_date BETWEEN ? AND ?
  ";
  
  if (!empty($session_id)) {
    $monthly_summary_query .= " AND sfc.session_id = ?";
    $stmt = $conn->prepare($monthly_summary_query . " GROUP BY month_key, month_name ORDER BY month_key DESC");
    $stmt->bind_param("ssi", $from_date, $to_date, $session_id);
  } else {
    $stmt = $conn->prepare($monthly_summary_query . " GROUP BY month_key, month_name ORDER BY month_key DESC");
    $stmt->bind_param("ss", $from_date, $to_date);
  }
  
  $stmt->execute();
  $monthly_result = $stmt->get_result();
  
  // Query to get fee type details by month
  $details_query = "
    SELECT 
      DATE_FORMAT(sfp.payment_date, '%Y-%m') as month_key,
      ft.title as fee_type,
      ft.type as fee_category,
      COUNT(DISTINCT sfp.id) as transactions,
      COUNT(DISTINCT sfc.student_class_id) as students,
      SUM(sfp.paid_amount) as collected,
      SUM(sfp.discount) as discount
    FROM student_fee_payments sfp
    INNER JOIN student_fee_card sfc ON sfp.fee_card_id = sfc.id
    INNER JOIN fee_types ft ON sfc.fee_type_id = ft.id
    WHERE sfp.payment_date BETWEEN ? AND ?
  ";
  
  if (!empty($session_id)) {
    $details_query .= " AND sfc.session_id = ?";
    $stmt_details = $conn->prepare($details_query . " GROUP BY month_key, ft.id, ft.title, ft.type ORDER BY month_key DESC, ft.title");
    $stmt_details->bind_param("ssi", $from_date, $to_date, $session_id);
  } else {
    $stmt_details = $conn->prepare($details_query . " GROUP BY month_key, ft.id, ft.title, ft.type ORDER BY month_key DESC, ft.title");
    $stmt_details->bind_param("ss", $from_date, $to_date);
  }
  
  $stmt_details->execute();
  $details_result = $stmt_details->get_result();
  
  // Organize details by month
  $details_by_month = [];
  while($detail = $details_result->fetch_assoc()) {
    $details_by_month[$detail['month_key']][] = $detail;
  }
  
  // Calculate grand totals
  $grand_total_transactions = 0;
  $grand_total_students = 0;
  $grand_total_collected = 0;
  $grand_total_discount = 0;
  ?>

  <!-- Report Table -->
  <div class="report-table">
    <h4>Monthly Fee Collection Summary
      <small class="pull-right">
        Period: <?php echo date('d-m-Y', strtotime($from_date)); ?> to <?php echo date('d-m-Y', strtotime($to_date)); ?>
      </small>
    </h4>
    <hr>
    
    <div class="table-responsive">
      <table class="table table-bordered" id="monthlyReportTable">
        <thead>
          <tr>
            <th style="width: 5%">#</th>
            <th style="width: 20%">Month</th>
            <th style="width: 10%" class="text-right">Students</th>
            <th style="width: 10%" class="text-right">Transactions</th>
            <th style="width: 15%" class="text-right">Collected Amount</th>
            <th style="width: 15%" class="text-right">Discount Given</th>
            <th style="width: 15%" class="text-right">Net Collection</th>
            <th style="width: 10%" class="text-center">Details</th>
          </tr>
        </thead>
        <tbody>
          <?php 
          $counter = 1;
          while($month = $monthly_result->fetch_assoc()): 
            $net_collection = $month['total_collected'] - $month['total_discount'];
            $grand_total_transactions += $month['total_transactions'];
            $grand_total_students += $month['total_students'];
            $grand_total_collected += $month['total_collected'];
            $grand_total_discount += $month['total_discount'];
          ?>
            <!-- Month Summary Row -->
            <tr class="month-group" data-month="<?php echo $month['month_key']; ?>">
              <td><?php echo $counter++; ?></td>
              <td>
                <i class="fas fa-plus-circle expand-icon text-primary"></i>
                <strong><?php echo $month['month_name']; ?></strong>
              </td>
              <td class="text-right"><?php echo number_format($month['total_students']); ?></td>
              <td class="text-right"><?php echo number_format($month['total_transactions']); ?></td>
              <td class="text-right">Rs. <?php echo number_format($month['total_collected'], 2); ?></td>
              <td class="text-right">Rs. <?php echo number_format($month['total_discount'], 2); ?></td>
              <td class="text-right"><strong>Rs. <?php echo number_format($net_collection, 2); ?></strong></td>
              <td class="text-center">
                <button class="btn btn-primary btn-xs btn-details" onclick="toggleDetails('<?php echo $month['month_key']; ?>')">
                  <i class="fas fa-eye"></i> View
                </button>
              </td>
            </tr>
            
            <!-- Details Row (Hidden by default) -->
            <tr class="details-row" id="details-<?php echo $month['month_key']; ?>">
              <td colspan="8" style="padding: 0 !important;">
                <div class="details-table">
                  <table class="table table-condensed table-striped" style="margin: 0; background: #f9f9f9;">
                    <thead>
                      <tr style="background: #e9ecef;">
                        <th style="width: 5%"></th>
                        <th style="width: 25%">Fee Type</th>
                        <th style="width: 10%">Category</th>
                        <th style="width: 10%" class="text-right">Students</th>
                        <th style="width: 10%" class="text-right">Transactions</th>
                        <th style="width: 15%" class="text-right">Collected</th>
                        <th style="width: 15%" class="text-right">Discount</th>
                        <th style="width: 10%" class="text-right">Net</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php 
                      $month_details = $details_by_month[$month['month_key']] ?? [];
                      $month_total_collected = 0;
                      $month_total_discount = 0;
                      
                      foreach($month_details as $detail):
                        $badge_class = '';
                        switch($detail['fee_category']) {
                          case 'monthly':
                            $badge_class = 'badge-monthly';
                            $category_display = 'Monthly';
                            break;
                          case 'yearly':
                            $badge_class = 'badge-yearly';
                            $category_display = 'Yearly';
                            break;
                          case 'one_time':
                            $badge_class = 'badge-one-time';
                            $category_display = 'One Time';
                            break;
                          default:
                            $category_display = ucfirst($detail['fee_category']);
                        }
                        
                        $net = $detail['collected'] - $detail['discount'];
                        $month_total_collected += $detail['collected'];
                        $month_total_discount += $detail['discount'];
                      ?>
                        <tr>
                          <td></td>
                          <td><strong><?php echo htmlspecialchars($detail['fee_type']); ?></strong></td>
                          <td><span class="fee-type-badge <?php echo $badge_class; ?>"><?php echo $category_display; ?></span></td>
                          <td class="text-right"><?php echo number_format($detail['students']); ?></td>
                          <td class="text-right"><?php echo number_format($detail['transactions']); ?></td>
                          <td class="text-right">Rs. <?php echo number_format($detail['collected'], 2); ?></td>
                          <td class="text-right">Rs. <?php echo number_format($detail['discount'], 2); ?></td>
                          <td class="text-right"><strong>Rs. <?php echo number_format($net, 2); ?></strong></td>
                        </tr>
                      <?php endforeach; ?>
                      
                      <!-- Month Total Row -->
                      <?php if(!empty($month_details)): ?>
                      <tr style="background: #e3f2fd; font-weight: bold;">
                        <td colspan="3" class="text-right"><strong>Month Total:</strong></td>
                        <td class="text-right"><?php echo number_format($month['total_students']); ?></td>
                        <td class="text-right"><?php echo number_format($month['total_transactions']); ?></td>
                        <td class="text-right">Rs. <?php echo number_format($month_total_collected, 2); ?></td>
                        <td class="text-right">Rs. <?php echo number_format($month_total_discount, 2); ?></td>
                        <td class="text-right">Rs. <?php echo number_format($month_total_collected - $month_total_discount, 2); ?></td>
                      </tr>
                      <?php else: ?>
                      <tr>
                        <td colspan="8" class="text-center text-muted">No fee type details available for this month</td>
                      </tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </td>
            </tr>
          <?php endwhile; ?>
        </tbody>
        <tfoot>
          <tr class="grand-total-row">
            <th colspan="2" class="text-right">GRAND TOTAL:</th>
            <th class="text-right"><?php echo number_format($grand_total_students); ?></th>
            <th class="text-right"><?php echo number_format($grand_total_transactions); ?></th>
            <th class="text-right">Rs. <?php echo number_format($grand_total_collected, 2); ?></th>
            <th class="text-right">Rs. <?php echo number_format($grand_total_discount, 2); ?></th>
            <th class="text-right">Rs. <?php echo number_format($grand_total_collected - $grand_total_discount, 2); ?></th>
            <th></th>
          </tr>
        </tfoot>
      </table>
    </div>
    
    <div class="row">
      <div class="col-md-12">
        <p class="text-muted">
          <i class="fas fa-info-circle"></i> 
          Click on "View" button or the month row to expand/collapse details. 
          Report generated on <?php echo date('d-m-Y h:i A'); ?>
        </p>
      </div>
    </div>
  </div>

</div>

<script>
// Function to toggle details visibility
function toggleDetails(monthKey) {
  var detailsRow = document.getElementById('details-' + monthKey);
  var monthRow = document.querySelector('tr[data-month="' + monthKey + '"]');
  var icon = monthRow.querySelector('.expand-icon');
  
  if (detailsRow.style.display === 'none' || detailsRow.style.display === '') {
    detailsRow.style.display = 'table-row';
    icon.classList.remove('fa-plus-circle');
    icon.classList.add('fa-minus-circle');
  } else {
    detailsRow.style.display = 'none';
    icon.classList.remove('fa-minus-circle');
    icon.classList.add('fa-plus-circle');
  }
}

// Make month row clickable
$(document).ready(function() {
  $('.month-group').on('click', function(e) {
    // Don't toggle if clicking on button
    if ($(e.target).hasClass('btn-details') || $(e.target).closest('.btn-details').length) {
      return;
    }
    var monthKey = $(this).data('month');
    toggleDetails(monthKey);
  });
  
  // Expand all button (optional)
  $('#expandAll').on('click', function() {
    $('.details-row').each(function() {
      $(this).show();
    });
    $('.expand-icon').removeClass('fa-plus-circle').addClass('fa-minus-circle');
  });
  
  // Collapse all button (optional)
  $('#collapseAll').on('click', function() {
    $('.details-row').each(function() {
      $(this).hide();
    });
    $('.expand-icon').removeClass('fa-minus-circle').addClass('fa-plus-circle');
  });
});
</script>

<!-- Optional: Add Expand/Collapse All buttons -->
<div class="row no-print" style="margin-bottom: 20px;">
  <div class="col-md-12">
    <button class="btn btn-sm btn-default" id="expandAll">
      <i class="fas fa-expand"></i> Expand All
    </button>
    <button class="btn btn-sm btn-default" id="collapseAll">
      <i class="fas fa-compress"></i> Collapse All
    </button>
  </div>
</div>

</body>
</html>