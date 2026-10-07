<?php 
require_once('security.php');
require_once('conn_inc.php');

// Enable error reporting for debugging
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Get current date for dashboard
$current_date = date('Y-m-d');
$current_month = date('m');
$current_year = date('Y');

// Get total students - ACTIVE (status = 1)
$active_student_count_result = $conn->query("SELECT COUNT(*) as total FROM student_registration WHERE status = 1");
$active_student_count = $active_student_count_result->fetch_assoc();

// Get total students - INACTIVE (status = 0)
$inactive_student_count_result = $conn->query("SELECT COUNT(*) as total FROM student_registration WHERE status = 0");
$inactive_student_count = $inactive_student_count_result->fetch_assoc();

// Get total students - ALL
$total_student_count_result = $conn->query("SELECT COUNT(*) as total FROM student_registration");
$total_student_count = $total_student_count_result->fetch_assoc();

// Get today's collections
$today_collection_result = $conn->query("
    SELECT COALESCE(SUM(paid_amount), 0) as total 
    FROM student_fee_payments 
    WHERE DATE(payment_date) = '$current_date'
");
$today_collection = $today_collection_result->fetch_assoc();

// Get yesterday's collection for comparison
$yesterday_collection_result = $conn->query("
    SELECT COALESCE(SUM(paid_amount), 0) as total 
    FROM student_fee_payments 
    WHERE DATE(payment_date) = DATE_SUB('$current_date', INTERVAL 1 DAY)
");
$yesterday_collection = $yesterday_collection_result->fetch_assoc();

// Calculate trend percentage
$trend_percentage = 0;
if ($yesterday_collection['total'] > 0) {
    $trend_percentage = round((($today_collection['total'] - $yesterday_collection['total']) / $yesterday_collection['total']) * 100, 1);
}

// Get this month's collections
$month_collection_result = $conn->query("
    SELECT COALESCE(SUM(paid_amount), 0) as total 
    FROM student_fee_payments 
    WHERE MONTH(payment_date) = '$current_month' 
    AND YEAR(payment_date) = '$current_year'
");
$month_collection = $month_collection_result->fetch_assoc();

// Get last month's collection for comparison
$last_month = $current_month - 1;
$last_month_year = $current_year;
if ($last_month == 0) {
    $last_month = 12;
    $last_month_year = $current_year - 1;
}
$last_month_collection_result = $conn->query("
    SELECT COALESCE(SUM(paid_amount), 0) as total 
    FROM student_fee_payments 
    WHERE MONTH(payment_date) = '$last_month' 
    AND YEAR(payment_date) = '$last_month_year'
");
$last_month_collection = $last_month_collection_result->fetch_assoc();

// Calculate monthly trend
$monthly_trend = 0;
if ($last_month_collection['total'] > 0) {
    $monthly_trend = round((($month_collection['total'] - $last_month_collection['total']) / $last_month_collection['total']) * 100, 1);
}

// Get student defaulter amount (total pending fees)
$defaulter_amount_result = $conn->query("
    SELECT COALESCE(SUM(total_amount), 0) as total 
    FROM student_fee_card 
    WHERE status = 'pending'
");
$defaulter_amount = $defaulter_amount_result->fetch_assoc();

// Get student defaulter count (number of students with pending fees)
$defaulter_count_result = $conn->query("
    SELECT COUNT(DISTINCT student_class_id) as total 
    FROM student_fee_card 
    WHERE status = 'pending'
");
$defaulter_count = $defaulter_count_result->fetch_assoc();

// Get total pending fees amount (same as defaulter amount, kept for compatibility)
$pending_fees = $defaulter_amount;

// Get today's attendance
$today_attendance_result = $conn->query("
    SELECT 
        COUNT(CASE WHEN status = 'P' THEN 1 END) as present,
        COUNT(CASE WHEN status = 'L' THEN 1 END) as late,
        COUNT(*) as total
    FROM attendance 
    WHERE date = '$current_date'
");
$today_attendance = $today_attendance_result->fetch_assoc();

if (!$today_attendance || !$today_attendance['total']) {
    $today_attendance = ['present' => 0, 'late' => 0, 'total' => 0];
}

// Get today's transactions count
$today_transactions_result = $conn->query("
    SELECT COUNT(*) as total 
    FROM student_fee_payments 
    WHERE DATE(payment_date) = '$current_date'
");
$today_transactions = $today_transactions_result->fetch_assoc();

// FIXED: Changed payment_date to invoice_date in expenses queries
$today_expenses_result = $conn->query("
    SELECT COALESCE(SUM(total_amount), 0) as total 
    FROM expenses 
    WHERE DATE(invoice_date) = '$current_date'
");
$today_expenses = $today_expenses_result->fetch_assoc();

// FIXED: Changed payment_date to invoice_date in expenses queries
$yesterday_expenses_result = $conn->query("
    SELECT COALESCE(SUM(total_amount), 0) as total 
    FROM expenses 
    WHERE DATE(invoice_date) = DATE_SUB('$current_date', INTERVAL 1 DAY)
");
$yesterday_expenses = $yesterday_expenses_result->fetch_assoc();

// Calculate expense trend
$expense_trend = 0;
if ($yesterday_expenses['total'] > 0) {
    $expense_trend = round((($today_expenses['total'] - $yesterday_expenses['total']) / $yesterday_expenses['total']) * 100, 1);
}

// FIXED: Changed payment_date to invoice_date in expenses queries
$month_expenses_result = $conn->query("
    SELECT COALESCE(SUM(total_amount), 0) as total 
    FROM expenses 
    WHERE MONTH(invoice_date) = '$current_month' 
    AND YEAR(invoice_date) = '$current_year'
");
$month_expenses = $month_expenses_result->fetch_assoc();

// Get hourly collection data for today
$hourly_data = [];
for ($hour = 8; $hour <= 19; $hour++) {
    $time_start = sprintf("%02d:00:00", $hour);
    $time_end = sprintf("%02d:59:59", $hour);
    
    $hourly_result = $conn->query("
        SELECT COALESCE(SUM(paid_amount), 0) as total 
        FROM student_fee_payments 
        WHERE DATE(payment_date) = '$current_date'
        AND TIME(payment_date) BETWEEN '$time_start' AND '$time_end'
    ");
    $hourly = $hourly_result->fetch_assoc();
    
    $hourly_data[] = floatval($hourly['total']);
}

// Get payment methods distribution for today
$payment_methods_result = $conn->query("
    SELECT 
        COALESCE(payment_method, 'cash') as payment_method,
        COUNT(*) as count,
        COALESCE(SUM(paid_amount), 0) as total
    FROM student_fee_payments 
    WHERE DATE(payment_date) = '$current_date'
    GROUP BY payment_method
");

$payment_methods_data = [];
$payment_methods_labels = [];
$payment_methods_totals = [];

while ($method = $payment_methods_result->fetch_assoc()) {
    $payment_methods_labels[] = ucfirst($method['payment_method'] ?: 'Cash');
    $payment_methods_data[] = intval($method['count']);
    $payment_methods_totals[] = floatval($method['total']);
}

// If no data for today, use empty data
if (empty($payment_methods_labels)) {
    $payment_methods_labels = ['Cash', 'Bank Transfer', 'Cheque', 'Online'];
    $payment_methods_data = [0, 0, 0, 0];
    $payment_methods_totals = [0, 0, 0, 0];
}

// Get monthly collection data for current year
$monthly_collection = [];
$monthly_labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

for ($m = 1; $m <= 12; $m++) {
    $month_data_result = $conn->query("
        SELECT COALESCE(SUM(paid_amount), 0) as total 
        FROM student_fee_payments 
        WHERE MONTH(payment_date) = '$m' 
        AND YEAR(payment_date) = '$current_year'
    ");
    $month_data = $month_data_result->fetch_assoc();
    $monthly_collection[] = floatval($month_data['total']);
}

// FIXED: Changed payment_date to invoice_date in expenses queries
$monthly_expenses = [];
for ($m = 1; $m <= 12; $m++) {
    $month_expense_result = $conn->query("
        SELECT COALESCE(SUM(total_amount), 0) as total 
        FROM expenses 
        WHERE MONTH(invoice_date) = '$m' 
        AND YEAR(invoice_date) = '$current_year'
    ");
    $month_expense = $month_expense_result->fetch_assoc();
    $monthly_expenses[] = floatval($month_expense['total']);
}

// Get fee status distribution
$fee_status_result = $conn->query("
    SELECT 
        COALESCE(status, 'pending') as status,
        COUNT(*) as count
    FROM student_fee_card
    GROUP BY status
");

$fee_status_labels = [];
$fee_status_data = [];

while ($status = $fee_status_result->fetch_assoc()) {
    $fee_status_labels[] = ucfirst($status['status']);
    $fee_status_data[] = intval($status['count']);
}

// Get class-wise enrollment
$enrollment_data_result = $conn->query("
    SELECT 
        COALESCE(c.title, 'Unknown Class') as class_name,
        COUNT(sc.id) AS student_count
    FROM classes c
    LEFT JOIN student_class sc ON c.id = sc.class_id
    WHERE c.status = 0 OR c.status IS NULL
    GROUP BY c.id
    ORDER BY student_count DESC
    LIMIT 5
");

$class_labels = [];
$class_counts = [];

while ($row = $enrollment_data_result->fetch_assoc()) {
    $class_labels[] = $row['class_name'];
    $class_counts[] = intval($row['student_count']);
}

// Get recent activities
$recent_activities_result = $conn->query("
    (SELECT 
        'fee' as type, 
        CONCAT('Fee collected from ', COALESCE(s.name, 'Student'), ' - Rs. ', COALESCE(sfp.paid_amount, 0)) as title, 
        sfp.payment_date as date,
        COALESCE(sfp.paid_amount, 0) as amount
     FROM student_fee_payments sfp
     LEFT JOIN student_fee_card sfc ON sfc.id = sfp.fee_card_id
     LEFT JOIN student_class sc ON sc.id = sfc.student_class_id
     LEFT JOIN student_registration s ON s.id = sc.student_registration_id
     WHERE sfp.payment_date IS NOT NULL
     ORDER BY sfp.payment_date DESC LIMIT 3)
    UNION ALL
    (SELECT 
        'student' as type, 
        CONCAT('New student registered: ', COALESCE(name, 'Unknown')) as title, 
        registration_date as date,
        0 as amount
     FROM student_registration
     WHERE registration_date IS NOT NULL
     ORDER BY registration_date DESC LIMIT 3)
    UNION ALL
    (SELECT 
        'attendance' as type, 
        CONCAT('Attendance marked for ', COUNT(*), ' students') as title, 
        COALESCE(date, CURDATE()) as date,
        0 as amount
     FROM attendance
     WHERE date IS NOT NULL
     GROUP BY date
     ORDER BY date DESC LIMIT 2)
    ORDER BY date DESC LIMIT 5
");

// Get upcoming events (fee due dates)
$upcoming_dues_result = $conn->query("
    SELECT 
        sfc.due_date,
        COUNT(DISTINCT sfc.student_class_id) as student_count,
        COALESCE(SUM(sfc.total_amount), 0) as total_amount
    FROM student_fee_card sfc
    WHERE sfc.status = 'pending'
    AND sfc.due_date >= '$current_date'
    GROUP BY sfc.due_date
    ORDER BY sfc.due_date ASC
    LIMIT 5
");

// Get village council statistics
$village_stats_result = $conn->query("
    SELECT 
        COALESCE(vc.title, 'Not Specified') as village_name,
        COUNT(sr.id) as student_count
    FROM village_councils vc
    LEFT JOIN student_registration sr ON vc.id = sr.village_council_id
    WHERE vc.status = 0
    GROUP BY vc.id
    ORDER BY student_count DESC
    LIMIT 5
");

// Get today's exam schedule
$today_schedule_result = $conn->query("
    SELECT 
        ed.start_time,
        ed.end_time,
        COALESCE(s.title, 'Exam') as subject,
        COALESCE(c.title, 'Class') as class
    FROM exam_datesheet ed
    LEFT JOIN subjects s ON s.id = ed.subject_id
    LEFT JOIN classes c ON c.id = ed.class_id
    WHERE ed.start_date = '$current_date'
    ORDER BY ed.start_time
    LIMIT 5
");

// Get calendar events for FullCalendar
$calendar_events = [];
$calendar_events[] = [
    'title' => 'Fee Collection',
    'start' => $current_date,
    'color' => '#4361ee'
];

// Add fee due dates as events
$due_dates_result = $conn->query("
    SELECT DISTINCT due_date 
    FROM student_fee_card 
    WHERE due_date >= '$current_date' 
    AND status = 'pending'
    LIMIT 5
");
while($due = $due_dates_result->fetch_assoc()) {
    $calendar_events[] = [
        'title' => 'Fee Due',
        'start' => $due['due_date'],
        'color' => '#f72585'
    ];
}

// Add staff meeting
$calendar_events[] = [
    'title' => 'Staff Meeting',
    'start' => date('Y-m-d', strtotime('next friday')),
    'color' => '#4cc9f0'
];

// Format current date for display
$formatted_date = date('l, F j, Y');
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <title>campus Management System | Dashboard</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  
  <!-- ApexCharts -->
  <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
  
  <!-- FullCalendar -->
  <link href='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.0/main.min.css' rel='stylesheet' />
  <script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.0/main.min.js'></script>

  <style>
    :root {
      --primary: #4361ee;
      --secondary: #3f37c9;
      --success: #4cc9f0;
      --info: #4895ef;
      --warning: #f72585;
      --danger: #e63946;
      --light: #f8f9fa;
      --dark: #212529;
      --soft-blue: #eef2ff;
      --soft-green: #e8f5e9;
      --soft-yellow: #fff3e0;
      --soft-red: #ffebee;
      --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
      --hover-shadow: 0 15px 40px rgba(0, 0, 0, 0.01);
    }

    * {
      font-family: 'Inter', sans-serif;
    }

    body {
      background-color: #f5f7fc;
      position: relative;
    }

    body::before {
      content: '';
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      pointer-events: none;
      z-index: 0;
    }

    .navbar-modern {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(10px);
      padding: 1rem 2rem;
      box-shadow: 0 2px 20px rgba(0,0,0,0.03);
      margin-bottom: 2rem;
      position: relative;
      z-index: 10;
    }

    .navbar-brand {
      font-weight: 700;
      color: var(--primary);
      font-size: 1.5rem;
    }

    .navbar-brand i {
      margin-right: 10px;
      color: var(--warning);
    }

    .date-display {
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      color: white;
      padding: 0.5rem 1.5rem;
      border-radius: 50px;
      font-weight: 500;
      font-size: 1rem;
      box-shadow: 0 5px 15px rgba(67, 97, 238, 0.2);
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .date-display i {
      font-size: 1.1rem;
    }

    .stat-card {
      border-radius: 20px;
      padding: 1.5rem;
      box-shadow: var(--card-shadow);
      transition: all 0.3s ease;
      height: 100%;
      position: relative;
      z-index: 5;
      overflow: hidden;
    }

    .stat-card:hover {
      transform: translateY(-5px);
      box-shadow: var(--hover-shadow);
    }

    /* All cards in first row - White background with full black text */
    .stat-card.white-bg {
      background: #ffffff;
      color: #000000;
    }

    .stat-header {
      display: flex;
      align-items: center;
      margin-bottom: 1rem;
      gap: 15px;
    }

    .stat-icon {
      width: 50px;
      height: 50px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
    }

    /* Icon and text colors for white background cards */
    .white-bg .stat-icon.blue-icon {
      color: #02488f;
    }

    .white-bg .stat-label.blue-text {
      color: #02488f;
      font-weight: 600;
      font-size: 1rem;
      margin: 0;
    }

    .white-bg .stat-icon.red-icon {
      color: #cb1515;
    }

    .white-bg .stat-label.red-text {
      color: #cb1515;
      font-weight: 600;
      font-size: 1rem;
      margin: 0;
    }

    .white-bg .stat-icon.orange-icon {
      color: #6b21a8;
    }

    .white-bg .stat-label.orange-text {
      color: #6b21a8;
      font-weight: 600;
      font-size: 1rem;
      margin: 0;
    }

    .white-bg .stat-icon.green-icon {
      color: #225c24;
    }

    .white-bg .stat-label.green-text {
      color: #225c24;
      font-weight: 600;
      font-size: 1rem;
      margin: 0;
    }

    .stat-number {
      font-size: 1.8rem;
      font-weight: 700;
      color: #4f4f4f;
      margin: 0.5rem 0;
      position: relative;
      z-index: 6;
    }

    .stat-trend {
      font-size: 0.85rem;
      display: flex;
      align-items: center;
      gap: 5px;
      position: relative;
      z-index: 6;
      color: #37474f;
    }

    .trend-up { color: #2e7d32; }
    .trend-down { color: #c62828; }

    /* Updated search container with reduced padding */
    .search-container {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(10px);
      border-radius: 50px;
      padding: 0.3rem;
      box-shadow: 0 5px 20px rgba(0,0,0,0.03);
      border: 2px solid transparent;
      transition: all 0.3s ease;
      max-width: 600px;
      margin: 1.5rem auto;
      position: relative;
      z-index: 10;
    }

    .search-input {
      border: none;
      background: transparent;
      padding: 0.5rem 1rem;
      font-size: 1rem;
      width: 100%;
      outline: none;
    }

    .search-button {
      background: #3b3a85;
      color: white;
      border: none;
      padding: 0.5rem 1.5rem;
      border-radius: 50px;
      font-weight: 500;
      font-size: 0.9rem;
      transition: all 0.3s ease;
    }

    .search-button:hover {
      background: var(--secondary);
      transform: translateY(-2px);
    }

    /* Updated view toggle with reduced padding */
    .view-toggle {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(10px);
      border-radius: 50px;
      padding: 0.2rem;
      display: inline-flex;
      box-shadow: 0 2px 10px rgba(0,0,0,0.03);
      border: 1px solid rgba(233, 236, 239, 0.5);
      position: relative;
      z-index: 10;
    }

    .view-btn {
      padding: 0.4rem 1rem;
      border: none;
      background: transparent;
      border-radius: 50px;
      color: #6c757d;
      font-weight: 500;
      font-size: 0.85rem;
      transition: all 0.3s ease;
    }

    .view-btn.active {
      background: #3b3a85;
      color: white;
    }

    .view-btn i {
      margin-right: 5px;
      font-size: 0.8rem;
    }

    .calendar-card {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(10px);
      border-radius: 20px;
      padding: 1.5rem;
      box-shadow: var(--card-shadow);
      border: 1px solid rgba(255, 255, 255, 0.5);
      position: relative;
      z-index: 5;
      overflow: hidden;
    }

    .calendar-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.5rem;
    }

    .calendar-month {
      font-size: 1.3rem;
      font-weight: 600;
      color: #2b2d42;
    }

    .calendar-nav-btn {
      width: 35px;
      height: 35px;
      border-radius: 10px;
      border: 1px solid #e9ecef;
      background: white;
      color: #6c757d;
      transition: all 0.3s ease;
    }

    .calendar-nav-btn:hover {
      background: var(--primary);
      color: white;
      border-color: var(--primary);
    }

    .modern-panel {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(10px);
      border-radius: 20px;
      padding: 1.5rem;
      box-shadow: var(--card-shadow);
      margin-bottom: 1.5rem;
      border: 1px solid rgba(255, 255, 255, 0.5);
      position: relative;
      z-index: 5;
      overflow: hidden;
    }

    .modern-panel::after {
      content: '📚';
      position: absolute;
      bottom: -10px;
      right: -10px;
      font-size: 60px;
      opacity: 0.03;
      transform: rotate(15deg);
      pointer-events: none;
    }

    .panel-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.5rem;
      position: relative;
      z-index: 6;
    }

    .panel-title {
      font-size: 1.2rem;
      font-weight: 600;
      color: #2b2d42;
      margin: 0;
    }

    .panel-title i {
      margin-right: 10px;
      color: var(--primary);
    }

    .quick-actions-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 1rem;
    }

    .quick-action-item {
      background: rgba(238, 242, 255, 0.7);
      backdrop-filter: blur(5px);
      border-radius: 15px;
      padding: 1rem;
      text-align: center;
      text-decoration: none;
      transition: all 0.3s ease;
      border: 1px solid rgba(255, 255, 255, 0.5);
    }

    .quick-action-item:hover {
      transform: translateY(-3px);
      box-shadow: 0 10px 20px rgba(67, 97, 238, 0.1);
      background: var(--soft-blue);
    }

    .quick-action-item i {
      font-size: 1.5rem;
      color: var(--primary);
      margin-bottom: 0.5rem;
    }

    .quick-action-item span {
      display: block;
      color: #2b2d42;
      font-weight: 500;
      font-size: 0.9rem;
    }

    .activity-list {
      list-style: none;
      padding: 0;
      margin: 0;
    }

    .activity-item {
      display: flex;
      align-items: center;
      padding: 1rem 0;
      border-bottom: 1px solid #f1f3f5;
    }

    .activity-item:last-child {
      border-bottom: none;
    }

    .activity-icon {
      width: 40px;
      height: 40px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-right: 1rem;
    }

    .activity-icon.fee { background: var(--soft-green); color: #4cc9f0; }
    .activity-icon.student { background: var(--soft-blue); color: #4361ee; }
    .activity-icon.attendance { background: var(--soft-yellow); color: #f8961e; }

    .activity-content {
      flex: 1;
    }

    .activity-title {
      font-weight: 600;
      color: #2b2d42;
      margin-bottom: 0.2rem;
      font-size: 0.95rem;
    }

    .activity-time {
      font-size: 0.8rem;
      color: #adb5bd;
    }

    .badge-paid {
      background: #4cc9f0;
      color: white;
      padding: 0.3rem 0.8rem;
      border-radius: 50px;
      font-size: 0.75rem;
    }
    
    .badge-pending {
      background: #f72585;
      color: white;
      padding: 0.3rem 0.8rem;
      border-radius: 50px;
      font-size: 0.75rem;
    }
    
    .badge-partial {
      background: #f8961e;
      color: white;
      padding: 0.3rem 0.8rem;
      border-radius: 50px;
      font-size: 0.75rem;
    }

    .container-fluid {
      position: relative;
      z-index: 10;
    }

    /* Remove border radius from Monthly Financial Trend chart */
    .no-radius-chart .apexcharts-canvas {
      border-radius: 0 !important;
    }
    
    .no-radius-chart .apexcharts-svg {
      border-radius: 0 !important;
    }

    @media (max-width: 768px) {
      .quick-actions-grid {
        grid-template-columns: repeat(2, 1fr);
      }
      
      .stat-number {
        font-size: 1.5rem;
      }
      
      .date-display {
        font-size: 0.85rem;
        padding: 0.4rem 1rem;
      }
    }
  </style>
</head>
<body>
<?php require_once('navbar.php'); ?>


<div class="container-fluid px-4">
  <!-- Search Section -->
  <div class="row justify-content-center">
    <div class="col-md-10">
      <div class="search-container d-flex align-items-center">
       
        <input type="text" class="search-input" id="searchInput" placeholder="Reg ID / Name" autofocus>
        <button class="search-button" id="searchButton"><i class="fa fa-search"></i></button>
        
<nav class=" d-flex justify-content-between align-items-center">
  <div class="d-flex align-items-center gap-3">
    <div class="view-toggle">
      <button class="view-btn" id="dailyViewBtn">
         Daily
      </button>
      <button class="view-btn active" id="monthlyViewBtn">
       Monthly
      </button>
    </div>
  </div>
  
  
</nav>
      </div>
    </div>
  </div>




  <!-- Search Results Container -->
  <div class="search-results-container" id="searchResults" style="display: none;">
    <div id="searchResultsContent"></div>
  </div>

  <!-- Dashboard Content -->
  <div id="dashboardContent"></div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
// Pass PHP data to JavaScript
const phpData = {
    todayCollection: <?php echo floatval($today_collection['total']); ?>,
    yesterdayCollection: <?php echo floatval($yesterday_collection['total']); ?>,
    trendPercentage: <?php echo floatval($trend_percentage); ?>,
    monthCollection: <?php echo floatval($month_collection['total']); ?>,
    monthlyTrend: <?php echo floatval($monthly_trend); ?>,
    defaulterAmount: <?php echo floatval($defaulter_amount['total']); ?>,
    defaulterCount: <?php echo intval($defaulter_count['total']); ?>,
    pendingFees: <?php echo floatval($pending_fees['total']); ?>,
    todayAttendance: <?php echo json_encode($today_attendance); ?>,
    todayTransactions: <?php echo intval($today_transactions['total']); ?>,
    todayExpenses: <?php echo floatval($today_expenses['total']); ?>,
    expenseTrend: <?php echo floatval($expense_trend); ?>,
    monthExpenses: <?php echo floatval($month_expenses['total']); ?>,
    hourlyData: <?php echo json_encode($hourly_data); ?>,
    paymentMethodsLabels: <?php echo json_encode($payment_methods_labels); ?>,
    paymentMethodsData: <?php echo json_encode($payment_methods_data); ?>,
    paymentMethodsTotals: <?php echo json_encode($payment_methods_totals); ?>,
    monthlyCollection: <?php echo json_encode($monthly_collection); ?>,
    monthlyExpenses: <?php echo json_encode($monthly_expenses); ?>,
    monthlyLabels: <?php echo json_encode($monthly_labels); ?>,
    feeStatusLabels: <?php echo json_encode($fee_status_labels); ?>,
    feeStatusData: <?php echo json_encode($fee_status_data); ?>,
    classLabels: <?php echo json_encode($class_labels); ?>,
    classCounts: <?php echo json_encode($class_counts); ?>,
    totalStudents: <?php echo intval($total_student_count['total']); ?>,
    activeStudents: <?php echo intval($active_student_count['total']); ?>,
    inactiveStudents: <?php echo intval($inactive_student_count['total']); ?>,
    calendarEvents: <?php echo json_encode($calendar_events); ?>
};

// View Toggle
let currentView = 'monthly';

document.getElementById('dailyViewBtn').addEventListener('click', function() {
    this.classList.add('active');
    document.getElementById('monthlyViewBtn').classList.remove('active');
    currentView = 'daily';
    loadDashboardContent('daily');
});

document.getElementById('monthlyViewBtn').addEventListener('click', function() {
    this.classList.add('active');
    document.getElementById('dailyViewBtn').classList.remove('active');
    currentView = 'monthly';
    loadDashboardContent('monthly');
});

// Load Dashboard Content
function loadDashboardContent(view) {
    const contentDiv = document.getElementById('dashboardContent');
    
    if (view === 'daily') {
        contentDiv.innerHTML = getDailyDashboardHTML();
        setTimeout(() => {
            initializeDailyCharts();
            initializeCalendar();
        }, 100);
    } else {
        contentDiv.innerHTML = getMonthlyDashboardHTML();
        setTimeout(() => {
            initializeMonthlyCharts();
        }, 100);
    }
}

// Daily Dashboard HTML - Updated with white background cards and names parallel to icons
function getDailyDashboardHTML() {
    const trendIcon = phpData.trendPercentage >= 0 ? 'fa-arrow-up trend-up' : 'fa-arrow-down trend-down';
    const trendClass = phpData.trendPercentage >= 0 ? 'trend-up' : 'trend-down';
    
    const expenseTrendIcon = phpData.expenseTrend >= 0 ? 'fa-arrow-up trend-up' : 'fa-arrow-down trend-down';
    const expenseTrendClass = phpData.expenseTrend >= 0 ? 'trend-up' : 'trend-down';
    
    return `
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card white-bg">
                    <div class="stat-header">
                        <div class="stat-icon blue-icon">
                            <i class="fas fa-wallet"></i>
                        </div>
                        <div class="stat-label blue-text">Today's Collection</div>
                    </div>
                    <div class="stat-number">Rs. ${formatNumber(phpData.todayCollection)}</div>
                    <div class="stat-trend">
                        <span class="${trendClass}"><i class="fas ${trendIcon}"></i> ${Math.abs(phpData.trendPercentage)}%</span>
                        <span>vs yesterday</span>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="stat-card white-bg">
                    <div class="stat-header">
                        <div class="stat-icon red-icon">
                            <i class="fas fa-receipt"></i>
                        </div>
                        <div class="stat-label red-text">Today's Expenses</div>
                    </div>
                    <div class="stat-number">Rs. ${formatNumber(phpData.todayExpenses)}</div>
                    <div class="stat-trend">
                        <span class="${expenseTrendClass}"><i class="fas ${expenseTrendIcon}"></i> ${Math.abs(phpData.expenseTrend)}%</span>
                        <span>vs yesterday</span>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="stat-card white-bg">
                    <div class="stat-header">
                        <div class="stat-icon orange-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="stat-label orange-text">Defaulters Amount</div>
                    </div>
                    <div class="stat-number">Rs. ${formatNumber(phpData.defaulterAmount)}</div>
                    <div class="stat-trend">
                        <span>${phpData.defaulterCount} students</span>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="stat-card white-bg">
                    <div class="stat-header">
                        <div class="stat-icon green-icon">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <div class="stat-label green-text">Present Today</div>
                    </div>
                    <div class="stat-number">${phpData.todayAttendance.present}</div>
                    <div class="stat-trend">
                        <span>${phpData.todayAttendance.total > 0 ? Math.round((phpData.todayAttendance.present / phpData.todayAttendance.total) * 100) : 0}% attendance</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-8">
                <div class="modern-panel">
                    <div class="panel-header">
                        <h5 class="panel-title"><i class="fas fa-chart-line"></i> Hourly Collection Today</h5>
                    </div>
                    <div id="hourlyChart" style="height: 300px;"></div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="modern-panel">
                    <div class="panel-header">
                        <h5 class="panel-title"><i class="fas fa-chart-pie"></i> Payment Methods</h5>
                    </div>
                    <div id="paymentMethodsChart" style="height: 300px;"></div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-md-7">
                <div class="calendar-card">
                    <div class="calendar-header">
                        <h5 class="calendar-month" id="currentMonth"><?php echo date('F Y'); ?></h5>
                        <div>
                            <button class="calendar-nav-btn" onclick="changeMonth(-1)"><i class="fas fa-chevron-left"></i></button>
                            <button class="calendar-nav-btn" onclick="changeMonth(1)"><i class="fas fa-chevron-right"></i></button>
                        </div>
                    </div>
                    <div id="calendar"></div>
                </div>
            </div>
            
            <div class="col-md-5">
                <div class="modern-panel">
                    <div class="panel-header">
                        <h5 class="panel-title"><i class="fas fa-clock"></i> Today's Schedule</h5>
                    </div>
                    <div id="todaySchedule">
                        <?php
                        if ($today_schedule_result && $today_schedule_result->num_rows > 0) {
                            echo '<ul class="list-unstyled">';
                            while($row = $today_schedule_result->fetch_assoc()) {
                                echo '<li class="mb-2 pb-2 border-bottom">';
                                echo '<div class="d-flex justify-content-between">';
                                echo '<span class="fw-bold">' . htmlspecialchars($row['subject']) . '</span>';
                                echo '<span class="badge bg-primary">' . htmlspecialchars($row['class']) . '</span>';
                                echo '</div>';
                                echo '<small class="text-muted">';
                                echo date('h:i A', strtotime($row['start_time'])) . ' - ' . date('h:i A', strtotime($row['end_time']));
                                echo '</small>';
                                echo '</li>';
                            }
                            echo '</ul>';
                        } else {
                            echo '<p class="text-muted text-center py-3">No exams scheduled for today</p>';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
    `;
}

// Monthly Dashboard HTML - Updated with white background cards, names parallel to icons, and no-radius chart container
function getMonthlyDashboardHTML() {
    const netIncome = phpData.monthCollection - phpData.monthExpenses;
    const netIncomeClass = netIncome >= 0 ? 'trend-up' : 'trend-down';
    
    return `
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card white-bg">
                    <div class="stat-header">
                        <div class="stat-icon blue-icon">
                            <i class="fas fa-wallet"></i>
                        </div>
                        <div class="stat-label blue-text">Month's Collection</div>
                    </div>
                    <div class="stat-number">Rs. ${formatNumber(phpData.monthCollection)}</div>
                    <div class="stat-trend">
                        <span class="${phpData.monthlyTrend >= 0 ? 'trend-up' : 'trend-down'}">
                            <i class="fas ${phpData.monthlyTrend >= 0 ? 'fa-arrow-up' : 'fa-arrow-down'}"></i>
                            ${Math.abs(phpData.monthlyTrend)}%
                        </span>
                        <span>vs last month</span>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="stat-card white-bg">
                    <div class="stat-header">
                        <div class="stat-icon red-icon">
                            <i class="fas fa-receipt"></i>
                        </div>
                        <div class="stat-label red-text">Month's Expenses</div>
                    </div>
                    <div class="stat-number">Rs. ${formatNumber(phpData.monthExpenses)}</div>
                    <div class="stat-trend">
                        <span>${((phpData.monthExpenses/phpData.monthCollection)*100 || 0).toFixed(1)}% of collection</span>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="stat-card white-bg">
                    <div class="stat-header">
                        <div class="stat-icon orange-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="stat-label orange-text">Defaulters Amount</div>
                    </div>
                    <div class="stat-number">Rs. ${formatNumber(phpData.defaulterAmount)}</div>
                    <div class="stat-trend">
                        <span>${phpData.defaulterCount} students</span>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="stat-card white-bg">
                    <div class="stat-header">
                        <div class="stat-icon green-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-label green-text">Total Students</div>
                    </div>
                    <div class="stat-number">${phpData.totalStudents}</div>
                    <div class="stat-trend">
                        <span class="trend-up"><i class="fas fa-circle" style="color: #2e7d32; font-size: 8px;"></i> Active: ${phpData.inactiveStudents}</span>
                        <span class="trend-down" style="margin-left: 10px;"><i class="fas fa-circle" style="color: #c62828; font-size: 8px;"></i> Inactive: ${phpData.activeStudents}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-8">
                <div class="modern-panel no-radius-chart">
                    <div class="panel-header">
                        <h5 class="panel-title"><i class="fas fa-chart-bar"></i> Monthly Financial Trend - <?php echo $current_year; ?></h5>
                    </div>
                    <div id="monthlyTrendChart" style="height: 300px;"></div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="modern-panel">
                    <div class="panel-header">
                        <h5 class="panel-title"><i class="fas fa-chart-pie"></i> Fee Status</h5>
                    </div>
                    <div id="feeStatusChart" style="height: 250px;"></div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-md-5">
                <div class="modern-panel">
                    <div class="panel-header">
                        <h5 class="panel-title"><i class="fas fa-history"></i> Recent Activity</h5>
                    </div>
                    <ul class="activity-list" id="recentActivityList">
                        <?php
                        if ($recent_activities_result) {
                            $recent_activities_result->data_seek(0);
                            while($activity = $recent_activities_result->fetch_assoc()):
                        ?>
                        <li class="activity-item">
                            <div class="activity-icon <?php echo $activity['type']; ?>">
                                <i class="fas fa-<?php echo $activity['type'] == 'fee' ? 'coins' : ($activity['type'] == 'student' ? 'user-plus' : 'calendar-check'); ?>"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">
                                    <?php echo htmlspecialchars($activity['title']); ?>
                                </div>
                                <div class="activity-time">
                                    <?php echo date('M j, Y g:i A', strtotime($activity['date'])); ?>
                                </div>
                            </div>
                        </li>
                        <?php 
                            endwhile;
                        } 
                        ?>
                    </ul>
                </div>
            </div>
            
            <div class="col-md-7">
                <div class="modern-panel">
                    <div class="panel-header">
                        <h5 class="panel-title"><i class="fas fa-chart-bar"></i> Class Enrollment</h5>
                    </div>
                    <div id="classEnrollmentChart" style="height: 250px;"></div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-md-12">
                <div class="modern-panel">
                    <div class="panel-header">
                        <h5 class="panel-title"><i class="fas fa-bolt"></i> Quick Actions</h5>
                    </div>
                    <div class="quick-actions-grid">
                        <a href="student_registration.php" class="quick-action-item">
                            <i class="fas fa-user-plus"></i>
                            <span>New Student</span>
                        </a>
                        <a href="fee_collection.php" class="quick-action-item">
                            <i class="fas fa-money-bill-wave"></i>
                            <span>Collect Fee</span>
                        </a>
                        <a href="student_list.php" class="quick-action-item">
                            <i class="fas fa-list"></i>
                            <span>Student List</span>
                        </a>
                        <a href="attendance.php" class="quick-action-item">
                            <i class="fas fa-calendar-check"></i>
                            <span>Attendance</span>
                        </a>
                        <a href="expenses.php" class="quick-action-item">
                            <i class="fas fa-receipt"></i>
                            <span>Expenses</span>
                        </a>
                        <a href="salary.php" class="quick-action-item">
                            <i class="fas fa-money-check-alt"></i>
                            <span>Salary</span>
                        </a>
                        <a href="exams.php" class="quick-action-item">
                            <i class="fas fa-pencil-alt"></i>
                            <span>Exams</span>
                        </a>
                        <a href="reports.php" class="quick-action-item">
                            <i class="fas fa-file-invoice"></i>
                            <span>Reports</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    `;
}

// Initialize Daily Charts
function initializeDailyCharts() {
    // Hourly Collection Chart
    var hourlyOptions = {
        chart: {
            type: 'area',
            height: 300,
            toolbar: { show: false }
        },
        series: [{
            name: 'Collection',
            data: phpData.hourlyData
        }],
        xaxis: {
            categories: ['8AM', '9AM', '10AM', '11AM', '12PM', '1PM', '2PM', '3PM', '4PM', '5PM', '6PM', '7PM']
        },
        yaxis: {
            labels: {
                formatter: function(value) {
                    return 'Rs. ' + value.toFixed(0);
                }
            }
        },
        stroke: {
            curve: 'smooth',
            width: 2
        },
        colors: ['#4361ee'],
        fill: {
            type: 'gradient',
            gradient: {
                shadeIntensity: 1,
                opacityFrom: 0.7,
                opacityTo: 0.3
            }
        },
        tooltip: {
            y: {
                formatter: function(value) {
                    return 'Rs. ' + value.toFixed(2);
                }
            }
        }
    };
    
    if (document.querySelector("#hourlyChart")) {
        var hourlyChart = new ApexCharts(document.querySelector("#hourlyChart"), hourlyOptions);
        hourlyChart.render();
    }
    
    // Payment Methods Pie Chart
    var paymentOptions = {
        chart: {
            type: 'donut',
            height: 300
        },
        series: phpData.paymentMethodsData,
        labels: phpData.paymentMethodsLabels,
        colors: ['#4cc9f0', '#4361ee', '#f72585', '#f8961e'],
        legend: {
            position: 'bottom'
        },
        plotOptions: {
            pie: {
                donut: {
                    size: '60%',
                    labels: {
                        show: true,
                        total: {
                            show: true,
                            label: 'Total',
                            formatter: function(w) {
                                return phpData.todayTransactions + ' transactions';
                            }
                        }
                    }
                }
            }
        }
    };
    
    if (document.querySelector("#paymentMethodsChart")) {
        var paymentChart = new ApexCharts(document.querySelector("#paymentMethodsChart"), paymentOptions);
        paymentChart.render();
    }
}

// Initialize Monthly Charts
function initializeMonthlyCharts() {
    // Monthly Trend Chart (Collection vs Expenses) - No border radius
    var trendOptions = {
        chart: {
            type: 'bar',
            height: 300,
            toolbar: { show: false },
            background: 'transparent'
        },
        series: [
            {
                name: 'Collection',
                data: phpData.monthlyCollection
            },
            {
                name: 'Expenses',
                data: phpData.monthlyExpenses
            }
        ],
        xaxis: {
            categories: phpData.monthlyLabels
        },
        yaxis: {
            labels: {
                formatter: function(value) {
                    return 'Rs. ' + (value/1000).toFixed(0) + 'k';
                }
            }
        },
        colors: ['#4361ee', '#f72585'],
        plotOptions: {
            bar: {
                borderRadius: 0, // Set to 0 to remove border radius
                columnWidth: '60%',
                grouped: true
            }
        },
        tooltip: {
            y: {
                formatter: function(value) {
                    return 'Rs. ' + value.toFixed(2);
                }
            }
        }
    };
    
    if (document.querySelector("#monthlyTrendChart")) {
        var trendChart = new ApexCharts(document.querySelector("#monthlyTrendChart"), trendOptions);
        trendChart.render();
    }
    
    // Fee Status Pie Chart
    if (phpData.feeStatusLabels.length > 0 && document.querySelector("#feeStatusChart")) {
        var feeOptions = {
            chart: {
                type: 'pie',
                height: 250
            },
            series: phpData.feeStatusData,
            labels: phpData.feeStatusLabels,
            colors: ['#4cc9f0', '#f72585', '#f8961e'],
            legend: {
                position: 'bottom'
            }
        };
        
        var feeChart = new ApexCharts(document.querySelector("#feeStatusChart"), feeOptions);
        feeChart.render();
    }
    
    // Class Enrollment Chart
    if (phpData.classLabels.length > 0 && document.querySelector("#classEnrollmentChart")) {
        var classOptions = {
            chart: {
                type: 'bar',
                height: 250,
                toolbar: { show: false }
            },
            series: [{
                name: 'Students',
                data: phpData.classCounts
            }],
            xaxis: {
                categories: phpData.classLabels
            },
            colors: ['#4cc9f0'],
            plotOptions: {
                bar: {
                    borderRadius: 6,
                    horizontal: true
                }
            },
            dataLabels: {
                enabled: true,
                formatter: function(val) {
                    return val + ' students';
                }
            }
        };
        
        var classChart = new ApexCharts(document.querySelector("#classEnrollmentChart"), classOptions);
        classChart.render();
    }
}

// Initialize Calendar
function initializeCalendar() {
    var calendarEl = document.getElementById('calendar');
    if (calendarEl) {
        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            headerToolbar: false,
            height: 350,
            events: phpData.calendarEvents,
            dateClick: function(info) {
                alert('Selected date: ' + info.dateStr);
            }
        });
        calendar.render();
        window.calendar = calendar;
    }
}

// Change Month in Calendar
function changeMonth(direction) {
    if (window.calendar) {
        window.calendar.next();
    }
}

// Format number with commas
function formatNumber(num) {
    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}

// Search Functionality
$(document).ready(function() {
    // Load monthly view by default
    loadDashboardContent('monthly');
    
    // Search functionality
    $('#searchButton').on('click', function() {
        var query = $('#searchInput').val().trim();
        if (query === '') {
            alert('Please enter a Registration Number or Name.');
            return;
        }
        
        if (!isNaN(query) && query !== '') {
            window.location.href = 'fee_collection.php?id=' + encodeURIComponent(query);
        } else {
            $.ajax({
                url: 'search_student.php',
                method: 'POST',
                data: { search_query: query },
                dataType: 'json',
                success: function(response) {
                    if (response.error) {
                        alert('Error: ' + response.error);
                    } else if (!Array.isArray(response) || response.length === 0) {
                        alert('No students found with that name.');
                    } else if (response.length === 1) {
                        window.location.href = 'fee_collection.php?id=' + encodeURIComponent(response[0].id);
                    } else {
                        displaySearchResults(response);
                    }
                },
                error: function() {
                    alert('Connection error. Please try again.');
                }
            });
        }
    });
    
    $('#searchInput').on('keypress', function(e) {
        if (e.which === 13) {
            $('#searchButton').click();
        }
    });
});

// Display search results
function displaySearchResults(students) {
    var resultsHtml = '<div class="row">';
    
    $.each(students, function(index, student) {
        resultsHtml += `
            <div class="col-md-6">
                <div class="student-card">
                    <div class="student-header">
                        <div class="student-id-badge">ID: ${student.id}</div>
                        <div class="student-name">${student.name}</div>
                        <div class="student-father">S/O: ${student.father_name}</div>
                        <div class="student-class">${student.current_class || 'Not enrolled'}</div>
                    </div>
                    <div class="student-body">
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Mobile</span>
                                <span class="info-value">
                                    <i class="fas fa-phone"></i> ${student.mobile || 'N/A'}
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">CNIC</span>
                                <span class="info-value">
                                    <i class="fas fa-id-card"></i> ${student.cnic || 'N/A'}
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Village</span>
                                <span class="info-value">
                                    <i class="fas fa-map-marker-alt"></i> ${student.village_council || 'N/A'}
                                </span>
                            </div>
                        </div>
                        
                        <div class="action-buttons">
                            <a href="fee_collection.php?id=${student.id}" class="action-btn action-btn-fee">
                                <i class="fas fa-money-bill-wave"></i> Fee
                            </a>
                            <a href="student_profile.php?student_id=${student.id}" class="action-btn action-btn-profile">
                                <i class="fas fa-user-circle"></i> Profile
                            </a>
                            <a href="edit_student.php?student_id=${student.id}" class="action-btn action-btn-edit">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <a href="mark_leave.php?student_id=${student.id}" class="action-btn action-btn-leave">
                                <i class="fas fa-sign-out-alt"></i> Leave
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        `;
    });
    
    resultsHtml += '</div>';
    $('#searchResultsContent').html(resultsHtml);
    $('#searchResults').show();
    $('#dashboardContent').hide();
}

// Add missing CSS for student cards
const style = document.createElement('style');
style.textContent = `
    .student-card {
        background: white;
        border-radius: 20px;
        overflow: hidden;
        box-shadow: var(--card-shadow);
        margin-bottom: 1.5rem;
    }
    .student-header {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        padding: 1.5rem;
        color: white;
        position: relative;
    }
    .student-id-badge {
        position: absolute;
        top: 1rem;
        right: 1rem;
        background: rgba(255,255,255,0.2);
        padding: 0.3rem 1rem;
        border-radius: 50px;
        font-size: 0.8rem;
    }
    .student-name {
        font-size: 1.4rem;
        font-weight: 600;
        margin-bottom: 0.3rem;
    }
    .student-father {
        font-size: 1rem;
        opacity: 0.9;
        margin-bottom: 0.5rem;
    }
    .student-class {
        display: inline-block;
        background: rgba(255,255,255,0.2);
        padding: 0.3rem 1rem;
        border-radius: 50px;
        font-size: 0.85rem;
    }
    .student-body {
        padding: 1.5rem;
    }
    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    .info-item {
        display: flex;
        flex-direction: column;
    }
    .info-label {
        font-size: 0.75rem;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 0.3rem;
    }
    .info-value {
        font-size: 0.95rem;
        color: #2b2d42;
        font-weight: 500;
    }
    .info-value i {
        width: 20px;
        color: var(--primary);
    }
    .action-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 0.8rem;
        margin-top: 1rem;
    }
    .action-btn {
        flex: 1;
        min-width: 120px;
        padding: 0.8rem;
        border: none;
        border-radius: 12px;
        font-weight: 500;
        font-size: 0.9rem;
        text-align: center;
        text-decoration: none;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        color: white !important;
    }
    .action-btn-fee {
        background: linear-gradient(135deg, #4cc9f0, #4895ef);
    }
    .action-btn-profile {
        background: linear-gradient(135deg, #4361ee, #3f37c9);
    }
    .action-btn-edit {
        background: linear-gradient(135deg, #f8961e, #f3722c);
    }
    .action-btn-leave {
        background: linear-gradient(135deg, #f72585, #b5179e);
    }
    .action-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
`;
document.head.appendChild(style);
</script>

</body>
</html>