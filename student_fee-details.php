<?php
session_start();
if (!isset($_SESSION['student_user_id']) || $_SESSION['role_id'] != 4) {
    header("Location: student_login.php");
    exit();
}
require_once('conn_inc.php');

// Get student details
$student_id = $_SESSION['student_user_id'];
$student_query = "
    SELECT sr.*, vc.title as village_council_name 
    FROM student_registration sr 
    LEFT JOIN village_councils vc ON sr.village_council_id = vc.id 
    WHERE sr.id = ?
";
$stmt = $conn->prepare($student_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student_result = $stmt->get_result();
$student = $student_result->fetch_assoc();
$stmt->close();

// Get current class information
$current_class_query = "
    SELECT sc.*, c.title as class_title, s.title as section_title, cs.section_id
    FROM student_class sc 
    JOIN class_sections cs ON sc.class_id = cs.id 
    JOIN classes c ON cs.class_id = c.id
    JOIN sections s ON cs.section_id = s.id
    WHERE sc.student_registration_id = ? AND sc.status = 'Active'
    ORDER BY sc.id DESC LIMIT 1
";
$stmt = $conn->prepare($current_class_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$class_result = $stmt->get_result();
$current_class = $class_result->fetch_assoc();
$stmt->close();

// Get fee details
$fee_query = "
    SELECT 
        sfc.id as fee_card_id,
        sfc.fee_type_id,
        ft.title as fee_type,
        ft.type as fee_category,
        sfc.total_amount,
        sfc.due_date,
        sfc.remarks,
        COALESCE(SUM(sfp.paid_amount), 0) as paid_amount,
        (sfc.total_amount - COALESCE(SUM(sfp.paid_amount), 0)) as due_amount,
        CASE 
            WHEN COALESCE(SUM(sfp.paid_amount), 0) >= sfc.total_amount THEN 'Paid'
            WHEN COALESCE(SUM(sfp.paid_amount), 0) > 0 THEN 'Partial'
            ELSE 'Unpaid'
        END as payment_status
    FROM student_fee_card sfc
    JOIN fee_types ft ON sfc.fee_type_id = ft.id
    LEFT JOIN student_fee_payments sfp ON sfc.id = sfp.fee_card_id
    WHERE sfc.student_class_id IN (
        SELECT id FROM student_class 
        WHERE student_registration_id = ?
    )
    GROUP BY sfc.id
    ORDER BY sfc.due_date DESC
";
$stmt = $conn->prepare($fee_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$fee_result = $stmt->get_result();
$fee_details = [];
$total_fee = 0;
$total_paid = 0;
$total_due = 0;

while ($row = $fee_result->fetch_assoc()) {
    $fee_details[] = $row;
    $total_fee += $row['total_amount'];
    $total_paid += $row['paid_amount'];
    $total_due += $row['due_amount'];
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Fee Details</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary: #4361ee;
      --secondary: #6c757d;
      --success: #198754;
      --info: #0dcaf0;
      --warning: #ffc107;
      --danger: #dc3545;
      --light: #f8f9fa;
      --dark: #212529;
      --sidebar-bg: #0a3d62;
      --sidebar-hover: #3e92cc;
      --card-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
    }
    
    body {
      background-color: #f5f7fb;
      font-family: 'Poppins', sans-serif;
      color: #495057;
    }
    
    .sidebar {
      background: linear-gradient(180deg, var(--sidebar-bg) 0%, #1a5d9c 100%);
      color: white;
      height: 100vh;
      position: fixed;
      top: 0;
      left: 0;
      width: 250px;
      padding-top: 20px;
      box-shadow: 3px 0 10px rgba(0, 0, 0, 0.1);
      z-index: 1000;
    }
    
    .sidebar-brand {
      padding: 0 20px 20px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      margin-bottom: 20px;
    }
    
    .sidebar-nav {
      list-style: none;
      padding: 0;
      margin: 0;
    }
    
    .sidebar-nav li {
      margin-bottom: 5px;
    }
    
    .sidebar-nav a {
      color: rgba(255, 255, 255, 0.8);
      text-decoration: none;
      display: flex;
      align-items: center;
      padding: 12px 20px;
      transition: all 0.3s;
      border-left: 3px solid transparent;
    }
    
    .sidebar-nav a:hover, .sidebar-nav a.active {
      background: rgba(255, 255, 255, 0.1);
      color: white;
      border-left: 3px solid white;
    }
    
    .sidebar-nav i {
      margin-right: 10px;
      width: 20px;
      text-align: center;
    }
    
    .main-content {
      margin-left: 250px;
      padding: 20px;
    }
    
    .header {
      background: white;
      border-radius: 12px;
      padding: 15px 25px;
      box-shadow: var(--card-shadow);
      margin-bottom: 25px;
    }
    
    .dashboard-card {
      background: white;
      border-radius: 12px;
      padding: 25px;
      box-shadow: var(--card-shadow);
      transition: all 0.3s ease;
      height: 100%;
      border: none;
      position: relative;
      overflow: hidden;
    }
    
    .dashboard-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 5px;
      height: 100%;
    }
    
    .card-fee::before { background: var(--success); }
    
    .card-icon {
      font-size: 2.5rem;
      margin-bottom: 15px;
      display: inline-block;
      padding: 15px;
      border-radius: 12px;
    }
    
    .card-fee .card-icon { background: rgba(25, 135, 84, 0.1); color: var(--success); }
    
    .status-badge {
      padding: 5px 10px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 500;
    }
    
    .status-paid { background: #e7f4ef; color: #0f6848; }
    .status-partial { background: #fef7ec; color: #f6aa2c; }
    .status-unpaid { background: #fce8e6; color: #d92525; }
    
    .table th {
      background-color: #f8f9fa;
      font-weight: 600;
    }
    
    .summary-card {
      background: white;
      border-radius: 12px;
      padding: 20px;
      box-shadow: var(--card-shadow);
      margin-bottom: 25px;
    }
    
    .summary-value {
      font-size: 1.5rem;
      font-weight: 600;
    }
    
    @media (max-width: 992px) {
      .sidebar {
        width: 70px;
      }
      
      .sidebar-brand h4, .sidebar-nav span {
        display: none;
      }
      
      .sidebar-nav i {
        margin-right: 0;
        font-size: 1.2rem;
      }
      
      .main-content {
        margin-left: 70px;
      }
    }
  </style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
  <div class="sidebar-brand">
    <h4><i class="fas fa-graduation-cap me-2"></i> <span>Student Portal</span></h4>
  </div>
  
  <ul class="sidebar-nav">
    <li><a href="student_dashboard.php"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
    <li><a href="#"><i class="fas fa-user"></i> <span>Profile</span></a></li>
    <li><a href="student_fee-detail.php" class="active"><i class="fas fa-money-bill"></i> <span>Fee</span></a></li>
    <li><a href="student_view_result.php"><i class="fas fa-graduation-cap"></i> <span>Results</span></a></li>
    <li><a href="student_attendance_details.php"><i class="fas fa-calendar-check"></i> <span>Attendance</span></a></li>
    <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
  </ul>
</div>

<!-- Main Content -->
<div class="main-content">
  <!-- Header -->
  <div class="header">
    <div class="d-flex justify-content-between align-items-center">
      <div>
        <h3 class="mb-1">Fee Details</h3>
        <p class="mb-0 text-muted">View your fee information and payment history</p>
      </div>
      <div class="text-end">
        <h5 class="mb-1"><?php echo htmlspecialchars($student['name']); ?></h5>
        <p class="mb-0 text-muted small">
          <?php 
          echo isset($current_class['class_title']) ? 
          htmlspecialchars($current_class['class_title']) . ' - ' . htmlspecialchars($current_class['section_title']) : 
          'Class information not available';
          ?>
        </p>
      </div>
    </div>
  </div>
  
  <!-- Fee Summary -->
  <div class="row mb-4">
    <div class="col-md-4">
      <div class="summary-card text-center">
        <div class="text-primary">
          <i class="fas fa-receipt fa-2x mb-2"></i>
          <h6>Total Fee</h6>
        </div>
        <div class="summary-value text-primary">Rs. <?php echo number_format($total_fee, 2); ?></div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="summary-card text-center">
        <div class="text-success">
          <i class="fas fa-check-circle fa-2x mb-2"></i>
          <h6>Paid Amount</h6>
        </div>
        <div class="summary-value text-success">Rs. <?php echo number_format($total_paid, 2); ?></div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="summary-card text-center">
        <div class="text-danger">
          <i class="fas fa-exclamation-circle fa-2x mb-2"></i>
          <h6>Due Amount</h6>
        </div>
        <div class="summary-value text-danger">Rs. <?php echo number_format($total_due, 2); ?></div>
      </div>
    </div>
  </div>
  
  <!-- Fee Details Table -->
  <div class="dashboard-card">
    <h4 class="mb-4"><i class="fas fa-list me-2 text-primary"></i>Fee Details</h4>
    
    <?php if (count($fee_details) > 0): ?>
      <div class="table-responsive">
        <table class="table table-hover">
          <thead>
            <tr>
              <th>Fee Type</th>
              <th>Total Amount</th>
              <th>Paid Amount</th>
              <th>Due Amount</th>
              <th>Due Date</th>
              <th>Status</th>
              <th>Remarks</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($fee_details as $fee): ?>
              <tr>
                <td><?php echo htmlspecialchars($fee['fee_type']); ?></td>
                <td>Rs. <?php echo number_format($fee['total_amount'], 2); ?></td>
                <td>Rs. <?php echo number_format($fee['paid_amount'], 2); ?></td>
                <td>Rs. <?php echo number_format($fee['due_amount'], 2); ?></td>
                <td><?php echo date('d M, Y', strtotime($fee['due_date'])); ?></td>
                <td>
                  <span class="status-badge status-<?php echo strtolower($fee['payment_status']); ?>">
                    <?php echo $fee['payment_status']; ?>
                  </span>
                </td>
                <td><?php echo !empty($fee['remarks']) ? htmlspecialchars($fee['remarks']) : '-'; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="text-center py-4">
        <i class="fas fa-receipt fa-3x text-muted mb-3"></i>
        <h5 class="text-muted">No fee records found</h5>
        <p class="text-muted">Your fee details will appear here once available.</p>
      </div>
    <?php endif; ?>
  </div>
  
  <!-- Payment History -->
  <?php if (count($fee_details) > 0): ?>
    <div class="dashboard-card mt-4">
      <h4 class="mb-4"><i class="fas fa-history me-2 text-primary"></i>Payment History</h4>
      
      <?php
      // Get payment history
      $payment_query = "
          SELECT 
            sfp.*,
            sfc.fee_type_id,
            ft.title as fee_type,
            DATE(sfp.payment_date) as payment_date_formatted
          FROM student_fee_payments sfp
          JOIN student_fee_card sfc ON sfp.fee_card_id = sfc.id
          JOIN fee_types ft ON sfc.fee_type_id = ft.id
          WHERE sfc.student_class_id IN (
              SELECT id FROM student_class 
              WHERE student_registration_id = ?
          )
          ORDER BY sfp.payment_date DESC
      ";
      $stmt = $conn->prepare($payment_query);
      $stmt->bind_param("i", $student_id);
      $stmt->execute();
      $payment_result = $stmt->get_result();
      $payments = [];
      
      while ($row = $payment_result->fetch_assoc()) {
          $payments[] = $row;
      }
      $stmt->close();
      ?>
      
      <?php if (count($payments) > 0): ?>
        <div class="table-responsive">
          <table class="table table-hover">
            <thead>
              <tr>
                <th>Payment Date</th>
                <th>Fee Type</th>
                <th>Amount</th>
                <th>Payment Method</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($payments as $payment): ?>
                <tr>
                  <td><?php echo date('d M, Y', strtotime($payment['payment_date_formatted'])); ?></td>
                  <td><?php echo htmlspecialchars($payment['fee_type']); ?></td>
                  <td>Rs. <?php echo number_format($payment['paid_amount'], 2); ?></td>
                  <td><?php echo !empty($payment['payment_method']) ? htmlspecialchars($payment['payment_method']) : 'Not specified'; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="text-center py-4">
          <i class="fas fa-history fa-3x text-muted mb-3"></i>
          <h5 class="text-muted">No payment history found</h5>
          <p class="text-muted">Your payment records will appear here once available.</p>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>