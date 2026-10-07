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

// Get today's date
$today = date('Y-m-d');
$today_day_name = date('l');

// Get today's attendance record
$today_attendance_query = "
    SELECT 
        date, 
        status,
        remarks
    FROM attendance 
    WHERE student_id = ? 
    AND date = ?
";
$stmt = $conn->prepare($today_attendance_query);
$stmt->bind_param("is", $student_id, $today);
$stmt->execute();
$today_result = $stmt->get_result();
$today_attendance = $today_result->fetch_assoc();
$stmt->close();

// Set default values if no record found
if (!$today_attendance) {
    $today_attendance = [
        'date' => $today,
        'status' => 'Not Recorded',
        'remarks' => ''
    ];
}

// Get date range for filtering (default to current month)
$current_month = date('Y-m');
$selected_month = isset($_GET['month']) ? $_GET['month'] : $current_month;

// Calculate start and end dates for the selected month
$start_date = date('Y-m-01', strtotime($selected_month));
$end_date = min(date('Y-m-t', strtotime($selected_month)), $today); // Don't go beyond today

// Get all class days (Monday to Saturday)
$class_days = [1, 2, 3, 4, 5, 6]; // Monday to Saturday (1=Monday, 7=Sunday)

// Get attendance records for the selected month
$attendance_query = "
    SELECT 
        date, 
        status,
        remarks
    FROM attendance 
    WHERE student_id = ? 
    AND date BETWEEN ? AND ?
    ORDER BY date DESC
";
$stmt = $conn->prepare($attendance_query);
$stmt->bind_param("iss", $student_id, $start_date, $end_date);
$stmt->execute();
$attendance_result = $stmt->get_result();

// Create an array with all dates in the month up to today and default to "Absent"
$attendance_by_date = [];
$current = strtotime($start_date);
$end = strtotime($end_date);

while ($current <= $end) {
    $date = date('Y-m-d', $current);
    $day_of_week = date('N', $current); // 1 (Monday) to 7 (Sunday)
    
    // Only count class days (Monday to Saturday)
    if (in_array($day_of_week, $class_days)) {
        $attendance_by_date[$date] = [
            'status' => 'Absent', // Default to absent
            'remarks' => '',
            'is_recorded' => false
        ];
    }
    
    $current = strtotime('+1 day', $current);
}

// Update with actual attendance records
while ($row = $attendance_result->fetch_assoc()) {
    $date = $row['date'];
    if (isset($attendance_by_date[$date])) {
        $attendance_by_date[$date] = [
            'status' => $row['status'],
            'remarks' => $row['remarks'],
            'is_recorded' => true
        ];
    }
}
$stmt->close();

// Count attendance status based on the detailed records
$present_count = 0;
$absent_count = 0;
$leave_count = 0;

foreach ($attendance_by_date as $record) {
    if ($record['status'] == 'P') {
        $present_count++;
    } elseif ($record['status'] == 'Absent') {
        $absent_count++;
    } elseif ($record['status'] == 'L') {
        $leave_count++;
    }
}

// Get all available months with attendance records for dropdown
$months_query = "
    SELECT DISTINCT DATE_FORMAT(date, '%Y-%m') as month 
    FROM attendance 
    WHERE student_id = ? 
    ORDER BY month DESC
";
$stmt = $conn->prepare($months_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$months_result = $stmt->get_result();
$available_months = [];
while ($row = $months_result->fetch_assoc()) {
    $available_months[] = $row['month'];
}
$stmt->close();

// If no months found, add current month
if (empty($available_months)) {
    $available_months[] = $current_month;
}

// Calculate attendance percentage based on detailed records
$total_class_days = count($attendance_by_date);
$attendance_percentage = $total_class_days > 0 ? ($present_count / $total_class_days) * 100 : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Attendance</title>
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
      border: none;
      position: relative;
      overflow: hidden;
      margin-bottom: 25px;
    }
    
    .dashboard-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 5px;
      height: 100%;
    }
    
    .card-attendance::before { background: var(--info); }
    
    .card-icon {
      font-size: 2.5rem;
      margin-bottom: 15px;
      display: inline-block;
      padding: 15px;
      border-radius: 12px;
    }
    
    .card-attendance .card-icon { background: rgba(13, 202, 240, 0.1); color: var(--info); }
    
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
      text-align: center;
    }
    
    .summary-value {
      font-size: 1.5rem;
      font-weight: 600;
    }
    
    .attendance-present { background-color: #d4edda; color: #155724; }
    .attendance-absent { background-color: #f8d7da; color: #721c24; }
    .attendance-leave { background-color: #fff3cd; color: #856404; }
    
    .attendance-badge {
      padding: 5px 10px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 500;
    }
    
    .badge-present { background-color: #d4edda; color: #155724; }
    .badge-absent { background-color: #f8d7da; color: #721c24; }
    .badge-leave { background-color: #fff3cd; color: #856404; }
    .badge-not-recorded { background-color: #6c757d; color: white; }
    
    .progress {
      height: 10px;
      margin-bottom: 10px;
    }
    
    .month-selector {
      padding: 10px 15px;
      border-radius: 8px;
      border: 1px solid #dee2e6;
      background-color: white;
      font-weight: 500;
    }
    
    .today-card {
      border: 2px solid var(--primary);
      background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    }
    
    .today-status {
      font-size: 1.2rem;
      font-weight: 600;
      padding: 8px 16px;
      border-radius: 20px;
      display: inline-block;
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
    <li><a href="student_fee_detail.php"><i class="fas fa-money-bill"></i> <span>Fee</span></a></li>
    <li><a href="student_view_results.php"><i class="fas fa-graduation-cap"></i> <span>Results</span></a></li>
    <li><a href="student_attendance_view.php" class="active"><i class="fas fa-calendar-check"></i> <span>Attendance</span></a></li>
    <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
  </ul>
</div>

<!-- Main Content -->
<div class="main-content">
  <!-- Header -->
  <div class="header">
    <div class="d-flex justify-content-between align-items-center">
      <div>
        <h3 class="mb-1">Attendance Records</h3>
        <p class="mb-0 text-muted">View your attendance history and statistics</p>
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
  
  <!-- Today's Attendance -->
  <div class="dashboard-card today-card">
    <h4 class="mb-4"><i class="fas fa-calendar-day me-2 text-primary"></i>Today's Attendance - <?php echo date('F j, Y'); ?></h4>
    
    <div class="row">
      <div class="col-md-8">
        <div class="d-flex align-items-center">
          <div class="me-4">
            <i class="fas fa-calendar-check fa-3x text-primary"></i>
          </div>
          <div>
            <h5 class="mb-1"><?php echo $today_day_name; ?>, <?php echo date('F j, Y'); ?></h5>
            <p class="mb-1">Class: <?php echo isset($current_class['class_title']) ? htmlspecialchars($current_class['class_title']) : 'N/A'; ?></p>
            <p class="mb-0">Section: <?php echo isset($current_class['section_title']) ? htmlspecialchars($current_class['section_title']) : 'N/A'; ?></p>
          </div>
        </div>
      </div>
      <div class="col-md-4 text-end">
        <h5 class="mb-2">Status</h5>
        <?php
        $status_class = '';
        if ($today_attendance['status'] == 'Present') {
            $status_class = 'badge-present';
        } elseif ($today_attendance['status'] == 'Absent') {
            $status_class = 'badge-absent';
        } elseif ($today_attendance['status'] == 'Leave') {
            $status_class = 'badge-leave';
        } else {
            $status_class = 'badge-not-recorded';
        }
        ?>
        <span class="attendance-badge <?php echo $status_class; ?> today-status">
          <?php echo $today_attendance['status']; ?>
        </span>
        <?php if (!empty($today_attendance['remarks'])): ?>
          <p class="mt-2 mb-0"><small>Remarks: <?php echo htmlspecialchars($today_attendance['remarks']); ?></small></p>
        <?php endif; ?>
      </div>
    </div>
  </div>
  
  <!-- Month Selector -->
  <div class="dashboard-card">
    <div class="row align-items-center">
      <div class="col-md-6">
        <h5 class="mb-0"><i class="fas fa-calendar me-2 text-primary"></i>Select Month for History</h5>
      </div>
      <div class="col-md-6">
        <form method="GET" action="student_attendance_view.php" class="d-flex">
          <select name="month" class="form-select month-selector" onchange="this.form.submit()">
            <?php foreach ($available_months as $month): 
              $month_name = date('F Y', strtotime($month . '-01'));
            ?>
            <option value="<?php echo $month; ?>" <?php echo $month == $selected_month ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($month_name); ?>
            </option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
    </div>
  </div>
  
  <!-- Attendance Summary -->
  <div class="row mb-4">
    <div class="col-md-3">
      <div class="summary-card">
        <div class="text-success">
          <i class="fas fa-check-circle fa-2x mb-2"></i>
          <h6>Present Days</h6>
        </div>
        <div class="summary-value text-success"><?php echo $present_count; ?></div>
        <small class="text-muted">out of <?php echo $total_class_days; ?> class days</small>
      </div>
    </div>
    <div class="col-md-3">
      <div class="summary-card">
        <div class="text-danger">
          <i class="fas fa-times-circle fa-2x mb-2"></i>
          <h6>Absent Days</h6>
        </div>
        <div class="summary-value text-danger"><?php echo $absent_count; ?></div>
        <small class="text-muted">out of <?php echo $total_class_days; ?> class days</small>
      </div>
    </div>
    <div class="col-md-3">
      <div class="summary-card">
        <div class="text-warning">
          <i class="fas fa-calendar-minus fa-2x mb-2"></i>
          <h6>Leave Days</h6>
        </div>
        <div class="summary-value text-warning"><?php echo $leave_count; ?></div>
        <small class="text-muted">out of <?php echo $total_class_days; ?> class days</small>
      </div>
    </div>
    <div class="col-md-3">
      <div class="summary-card">
        <div class="text-info">
          <i class="fas fa-percent fa-2x mb-2"></i>
          <h6>Attendance %</h6>
        </div>
        <div class="summary-value text-info">
          <?php echo number_format($attendance_percentage, 2); ?>%
        </div>
        <small class="text-muted">based on <?php echo $total_class_days; ?> class days</small>
      </div>
    </div>
  </div>
  
  <!-- Attendance Progress Bar -->
  <div class="dashboard-card">
    <h5 class="mb-3"><i class="fas fa-chart-line me-2 text-primary"></i>Attendance Rate</h5>
    <div class="progress">
      <div class="progress-bar bg-success" role="progressbar" 
           style="width: <?php echo $attendance_percentage; ?>%" 
           aria-valuenow="<?php echo $attendance_percentage; ?>" 
           aria-valuemin="0" aria-valuemax="100">
      </div>
    </div>
    <div class="d-flex justify-content-between mt-1">
      <small class="text-muted"><?php echo number_format($attendance_percentage, 2); ?>% Attendance Rate</small>
      <small class="text-muted"><?php echo $total_class_days; ?> Class Days</small>
    </div>
  </div>
  
  <!-- Detailed Attendance Records -->
  <div class="dashboard-card">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h5 class="mb-0"><i class="fas fa-list me-2 text-primary"></i>Attendance History - <?php echo date('F Y', strtotime($selected_month . '-01')); ?></h5>
      <span class="text-muted"><?php echo $total_class_days; ?> class days</span>
    </div>
    
    <?php if (!empty($attendance_by_date)): ?>
      <div class="table-responsive">
        <table class="table table-hover">
          <thead class="table-light">
            <tr>
              <th>Date</th>
              <th>Day</th>
              <th>Status</th>
              <th>Remarks</th>
              <th>Recorded</th>
            </tr>
          </thead>
          <tbody>
            <?php 
            // Sort by date descending (newest first)
            krsort($attendance_by_date);
            
            foreach ($attendance_by_date as $date => $record): 
              $day_name = date('l', strtotime($date));
              $status_class = '';
              if ($record['status'] == 'Present') $status_class = 'attendance-present';
              elseif ($record['status'] == 'Absent') $status_class = 'attendance-absent';
              elseif ($record['status'] == 'Leave') $status_class = 'attendance-leave';
              
              // Highlight today's date
              $is_today = ($date == $today);
            ?>
            <tr <?php echo $is_today ? 'class="table-primary"' : ''; ?>>
              <td>
                <?php echo date('d M, Y', strtotime($date)); ?>
                <?php if ($is_today): ?>
                  <span class="badge bg-primary ms-1">Today</span>
                <?php endif; ?>
              </td>
              <td><?php echo $day_name; ?></td>
              <td>
                <span class="attendance-badge badge-<?php echo strtolower($record['status']); ?>">
                  <?php echo $record['status']; ?>
                </span>
              </td>
              <td><?php echo !empty($record['remarks']) ? htmlspecialchars($record['remarks']) : '-'; ?></td>
              <td>
                <?php if ($record['is_recorded']): ?>
                  <span class="badge bg-success">Yes</span>
                <?php else: ?>
                  <span class="badge bg-secondary">No</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="text-center py-4">
        <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
        <h5 class="text-muted">No attendance records found</h5>
        <p class="text-muted">Your attendance records will appear here once they are available.</p>
      </div>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>