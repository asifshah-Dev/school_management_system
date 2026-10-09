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

// Load the active academic session for session-aware dashboard summaries.
$active_session_result = $conn->query("SELECT id, title FROM sessions WHERE status = 1 ORDER BY id DESC LIMIT 1");
$active_session = $active_session_result ? $active_session_result->fetch_assoc() : null;
$active_session_id = intval($active_session['id'] ?? 0);
$active_session_title = (string) ($active_session['title'] ?? '');

// Attendance summary by class for the active session.
$class_attendance_result = $conn->query("
    SELECT
        c.title AS class_name,
        COUNT(DISTINCT sc.student_registration_id) AS enrolled,
        COUNT(DISTINCT CASE WHEN a.status = 'P' THEN sc.student_registration_id END) AS present,
        COUNT(DISTINCT CASE WHEN a.status = 'A' THEN sc.student_registration_id END) AS absent
    FROM classes c
    LEFT JOIN class_sections cs ON cs.class_id = c.id
    LEFT JOIN student_class sc ON sc.class_id = cs.id AND sc.session_id = $active_session_id
    LEFT JOIN attendance a ON a.student_id = sc.student_registration_id AND a.date = '$current_date'
    WHERE c.status = 0 OR c.status IS NULL
    GROUP BY c.id, c.title
    ORDER BY enrolled DESC, c.title
");
$class_attendance = [];
if ($class_attendance_result) {
    while ($row = $class_attendance_result->fetch_assoc()) {
        $class_attendance[] = [
            'class' => $row['class_name'],
            'enrolled' => intval($row['enrolled']),
            'present' => intval($row['present']),
            'absent' => intval($row['absent'])
        ];
    }
}

// Top overdue fee cards for the active academic session.
$overdue_fees_result = $conn->query("
    SELECT
        sr.name AS student_name,
        c.title AS class_name,
        sec.title AS section_name,
        sfc.due_date,
        GREATEST(COALESCE(sfc.total_amount, 0) - COALESCE(sfc.paid_amount, 0), 0) AS outstanding_amount
    FROM student_fee_card sfc
    INNER JOIN student_class sc ON sc.id = sfc.student_class_id
    INNER JOIN student_registration sr ON sr.id = sc.student_registration_id
    LEFT JOIN class_sections cs ON cs.id = sc.class_id
    LEFT JOIN classes c ON c.id = cs.class_id
    LEFT JOIN sections sec ON sec.id = cs.section_id
    WHERE sfc.status = 'pending'
      AND sfc.due_date < '$current_date'
      AND sfc.session_id = $active_session_id
    ORDER BY sfc.due_date ASC, outstanding_amount DESC
    LIMIT 20
");
$overdue_fees = [];
if ($overdue_fees_result) {
    while ($row = $overdue_fees_result->fetch_assoc()) {
        $overdue_fees[] = [
            'student' => $row['student_name'],
            'class' => trim(($row['class_name'] ?? '') . ' ' . ($row['section_name'] ?? '')),
            'due_date' => $row['due_date'],
            'amount' => (float) $row['outstanding_amount']
        ];
    }
}

// Daily fee collections for the last 15 days.
$collection_start_date = date('Y-m-d', strtotime('-14 days', strtotime($current_date)));
$daily_collection_result = $conn->query("
    SELECT DATE(payment_date) AS collection_date, COALESCE(SUM(paid_amount), 0) AS total
    FROM student_fee_payments
    WHERE payment_date >= '$collection_start_date'
      AND payment_date < DATE_ADD('$current_date', INTERVAL 1 DAY)
    GROUP BY DATE(payment_date)
    ORDER BY collection_date
");
$daily_collection_by_date = [];
if ($daily_collection_result) {
    while ($row = $daily_collection_result->fetch_assoc()) {
        $daily_collection_by_date[$row['collection_date']] = (float) $row['total'];
    }
}
$daily_collection_labels = [];
$daily_collection_values = [];
for ($day_offset = 0; $day_offset < 15; $day_offset++) {
    $collection_date = date('Y-m-d', strtotime("+$day_offset days", strtotime($collection_start_date)));
    $daily_collection_labels[] = date('M d', strtotime($collection_date));
    $daily_collection_values[] = $daily_collection_by_date[$collection_date] ?? 0;
}

// Today's timetable uses the Monday=1 through Saturday=6 day IDs from create_timetable.php.
$weekday_id = (int) date('N');
$today_timetable = [];
$timetable_available = false;
$timetable_tables = [];
$timetable_table_result = $conn->query("SHOW TABLES");
if ($timetable_table_result) {
    while ($table_row = $timetable_table_result->fetch_row()) {
        $timetable_tables[$table_row[0]] = true;
    }
}

if (isset($timetable_tables['timetable'], $timetable_tables['time_slots'])) {
    $timetable_available = true;
    $today_timetable_result = $conn->query("
        SELECT
            c.title AS class_name,
            sub.title AS subject_name,
            u.username AS teacher_name,
            ts.start_time,
            ts.end_time
        FROM timetable t
        LEFT JOIN classes c ON c.id = t.class_id
        LEFT JOIN subjects sub ON sub.id = t.subject_id
        LEFT JOIN users u ON u.id = t.teacher_id
        LEFT JOIN time_slots ts ON ts.id = t.time_slot_id
        WHERE t.day_id = $weekday_id
        ORDER BY ts.start_time
        LIMIT 12
    ");
    while ($row = $today_timetable_result->fetch_assoc()) {
        $today_timetable[] = [
            'class' => $row['class_name'],
            'subject' => $row['subject_name'],
            'teacher' => $row['teacher_name'],
            'start' => $row['start_time'],
            'end' => $row['end_time']
        ];
    }
}

// Upcoming exams and fee due dates make up the events available in this installation.
$upcoming_exams_result = $conn->query("
    SELECT ed.start_date, ed.start_time, s.title AS subject_name, c.title AS class_name
    FROM exam_datesheet ed
    LEFT JOIN subjects s ON s.id = ed.subject_id
    LEFT JOIN classes c ON c.id = ed.class_id
    WHERE ed.start_date >= '$current_date'
    ORDER BY ed.start_date, ed.start_time
    LIMIT 5
");
$upcoming_exams = [];
if ($upcoming_exams_result) {
    while ($row = $upcoming_exams_result->fetch_assoc()) {
        $upcoming_exams[] = [
            'date' => $row['start_date'],
            'time' => $row['start_time'],
            'subject' => $row['subject_name'],
            'class' => $row['class_name']
        ];
    }
}

// Admissions only track confirmed/pending status in this school database.
$admissions_result = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN is_admission = 1 THEN 1 ELSE 0 END) AS admitted,
        SUM(CASE WHEN is_admission = 0 THEN 1 ELSE 0 END) AS pending
    FROM student_registration
    WHERE registration_date >= DATE_FORMAT('$current_date', '%Y-%m-01')
      AND registration_date <= '$current_date'
");
$admissions_summary = $admissions_result ? $admissions_result->fetch_assoc() : [];

// Transport is recorded as a student transport flag; there is no fleet table in this app.
$transport_summary_result = $conn->query("
    SELECT COUNT(*) AS students, COALESCE(SUM(transport_fee), 0) AS monthly_fees
    FROM student_registration
    WHERE is_transport = 1 AND status = 1
");
$transport_summary = $transport_summary_result ? $transport_summary_result->fetch_assoc() : ['students' => 0, 'monthly_fees' => 0];

// Staff attendance uses the dated user_attendance records.
$staff_attendance_result = $conn->query("
    SELECT
        COUNT(DISTINCT u.id) AS total_staff,
        COUNT(DISTINCT CASE WHEN ua.status = 'P' THEN u.id END) AS present_staff,
        COUNT(DISTINCT CASE WHEN ua.status = 'L' THEN u.id END) AS leave_staff
    FROM users u
    LEFT JOIN user_attendance ua ON ua.user_id = u.id AND DATE(ua.dated) = '$current_date'
    WHERE u.status = 0
");
$staff_attendance = $staff_attendance_result ? $staff_attendance_result->fetch_assoc() : ['total_staff' => 0, 'present_staff' => 0, 'leave_staff' => 0];
$staff_snapshot_result = $conn->query("
    SELECT u.username, ua.status
    FROM users u
    LEFT JOIN user_attendance ua ON ua.user_id = u.id AND DATE(ua.dated) = '$current_date'
    WHERE u.status = 0
    ORDER BY u.username
    LIMIT 8
");
$staff_snapshot = [];
if ($staff_snapshot_result) {
    while ($row = $staff_snapshot_result->fetch_assoc()) {
        $staff_snapshot[] = [
            'name' => $row['username'],
            'status' => $row['status'] ?: 'Not marked'
        ];
    }
}

// Student birthdays in the next seven days, using the current-session class assignment.
$student_birthdays_result = $conn->query("
    SELECT
        sr.name AS student_name,
        c.title AS class_name,
        sec.title AS section_name,
        sr.dob,
        CASE
            WHEN DATE_FORMAT(sr.dob, '%m-%d') >= DATE_FORMAT('$current_date', '%m-%d')
                THEN STR_TO_DATE(CONCAT(YEAR('$current_date'), DATE_FORMAT(sr.dob, '-%m-%d')), '%Y-%m-%d')
            ELSE STR_TO_DATE(CONCAT(YEAR('$current_date') + 1, DATE_FORMAT(sr.dob, '-%m-%d')), '%Y-%m-%d')
        END AS next_birthday
    FROM student_registration sr
    LEFT JOIN student_class sc ON sc.student_registration_id = sr.id AND sc.session_id = $active_session_id
    LEFT JOIN class_sections cs ON cs.id = sc.class_id
    LEFT JOIN classes c ON c.id = cs.class_id
    LEFT JOIN sections sec ON sec.id = cs.section_id
    WHERE sr.dob IS NOT NULL
    HAVING next_birthday BETWEEN '$current_date' AND DATE_ADD('$current_date', INTERVAL 6 DAY)
    ORDER BY next_birthday, sr.name
    LIMIT 8
");
$student_birthdays = [];
if ($student_birthdays_result) {
    while ($row = $student_birthdays_result->fetch_assoc()) {
        $student_birthdays[] = [
            'name' => $row['student_name'],
            'class' => trim(($row['class_name'] ?? '') . ' ' . ($row['section_name'] ?? '')),
            'dob' => $row['dob'],
            'date' => $row['next_birthday'],
            'age' => intval(date('Y', strtotime($row['next_birthday'])) - date('Y', strtotime($row['dob'])))
        ];
    }
}

// Setup status from the configuration records available in this installation.
$setup_status = [];
foreach ([
    'Academic Session' => 'sessions',
    'Classes & Sections' => 'class_sections',
    'Subjects' => 'subjects',
    'Fees Assigned' => 'class_fee_types'
] as $setup_label => $setup_table) {
    $setup_result = $conn->query("SELECT id FROM `$setup_table` LIMIT 1");
    $setup_status[$setup_label] = $setup_result && $setup_result->num_rows > 0;
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
$recent_activities = [];
if ($recent_activities_result) {
    $recent_activities_result->data_seek(0);
    while ($activity = $recent_activities_result->fetch_assoc()) {
        $recent_activities[] = [
            'type' => $activity['type'],
            'title' => $activity['title'],
            'date' => $activity['date'],
            'amount' => (float) $activity['amount']
        ];
    }
    $recent_activities_result->data_seek(0);
}

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
$current_hour = (int) date('G');
$dashboard_greeting = $current_hour < 12 ? 'Good morning' : ($current_hour < 17 ? 'Good afternoon' : 'Good evening');
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
<body class="dashboard-page">
<?php require_once('navbar.php'); ?>

<style>
  body.dashboard-page {
    background: #f3f4f8 !important;
    color: #302c29;
    padding-top: 64px;
  }

  .dashboard-topbar {
    position: fixed;
    inset: 0 0 auto;
    z-index: 1040;
    display: flex;
    align-items: center;
    height: 64px;
    padding: 0 1.35rem;
    background: #fff;
    border-bottom: 1px solid #ebe9e7;
    box-shadow: 0 2px 8px rgba(36, 31, 27, 0.035);
  }

  .dashboard-menu-toggle,
  .dashboard-topbar-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    flex: 0 0 40px;
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: #6f6c69;
    cursor: pointer;
    font-size: 1rem;
    transition: background 0.2s ease, color 0.2s ease;
  }

  .dashboard-menu-toggle:hover,
  .dashboard-topbar-icon:hover {
    background: #f2f3f8;
    color: #3546a5;
  }

  .dashboard-topbar-menu-wrap {
    position: relative;
  }

  .dashboard-topbar-menu {
    position: absolute;
    top: calc(100% + 0.55rem);
    right: 0;
    z-index: 1060;
    width: min(310px, calc(100vw - 1.5rem));
    padding: 0.55rem;
    border: 1px solid #e9e7e5;
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 12px 35px rgba(35, 32, 29, 0.16);
  }

  .dashboard-topbar-menu[hidden] {
    display: none;
  }

  .dashboard-menu-heading {
    padding: 0.55rem 0.65rem 0.7rem;
    color: #393641;
    font-size: 0.84rem;
    font-weight: 700;
  }

  .dashboard-topbar-menu > a:not(.dashboard-notifications-all) {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    padding: 0.65rem;
    border-radius: 9px;
    color: #54515b;
    font-size: 0.82rem;
    text-decoration: none;
  }

  .dashboard-topbar-menu > a:hover {
    background: #f5f5fa;
    color: #3546a5;
  }

  .dashboard-topbar-menu > a > i {
    width: 20px;
    color: #5363b7;
    text-align: center;
  }

  .dashboard-notification-button {
    position: relative;
  }

  .dashboard-notification-count {
    position: absolute;
    top: 2px;
    right: 2px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 16px;
    height: 16px;
    padding: 0 4px;
    border-radius: 20px;
    background: #e85b54;
    color: #fff;
    font-size: 0.6rem;
    font-weight: 700;
  }

  .dashboard-notification-item {
    align-items: flex-start !important;
  }

  .dashboard-notification-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 33px;
    height: 33px;
    flex: 0 0 33px;
    border-radius: 10px;
    background: #fff2e8;
    color: #e9914a;
  }

  .dashboard-notification-item strong,
  .dashboard-notification-item small {
    display: block;
  }

  .dashboard-notification-item strong {
    color: #3d3a40;
    font-size: 0.79rem;
  }

  .dashboard-notification-item small {
    margin-top: 0.2rem;
    color: #89858b;
    font-size: 0.71rem;
  }

  .dashboard-topbar-menu .dashboard-notifications-all {
    display: block;
    padding: 0.65rem;
    border-top: 1px solid #efeeed;
    color: #3546a5;
    font-size: 0.77rem;
    font-weight: 650;
    text-align: center;
    text-decoration: none;
  }

  .dashboard-notifications-empty {
    margin: 0;
    padding: 0.75rem 0.65rem;
    color: #7f7c81;
    font-size: 0.8rem;
  }

  .dashboard-brand {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    min-width: 0;
    margin-left: 1rem;
    color: #34333a;
    font-size: 0.95rem;
    font-weight: 750;
    letter-spacing: 0.015em;
    line-height: 1.05;
    text-decoration: none;
    white-space: nowrap;
  }

  .dashboard-brand img {
    width: 37px;
    height: 37px;
    border-radius: 9px;
    object-fit: contain;
  }

  .dashboard-brand small {
    display: block;
    margin-top: 0.22rem;
    color: #92908d;
    font-size: 0.57rem;
    font-weight: 600;
    letter-spacing: 0.08em;
  }

  .dashboard-topbar-actions {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    margin-left: auto;
  }

  .dashboard-account-name {
    max-width: 190px;
    overflow: hidden;
    color: #686562;
    font-size: 0.85rem;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .dashboard-user-avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 39px;
    height: 39px;
    padding: 0;
    border: 3px solid #fff;
    border-radius: 50%;
    background: #ed8b43;
    box-shadow: 0 1px 6px rgba(36, 31, 27, 0.2);
    color: #fff;
    cursor: pointer;
    font-size: 0.74rem;
    font-weight: 700;
    font-family: inherit;
  }

  .dashboard-sidebar {
    position: fixed;
    z-index: 1035;
    top: 64px;
    bottom: 0;
    left: 0;
    display: flex;
    flex-direction: column;
    width: 260px;
    overflow-x: hidden;
    overflow-y: auto;
    background: #292d39;
    color: #e6e7eb;
    transition: width 0.22s ease, transform 0.22s ease;
  }

  .dashboard-sidebar-nav {
    padding: 1.1rem 0.8rem 0.5rem;
  }

  .dashboard-sidebar-search {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    height: 40px;
    margin: 0 0.1rem 1rem;
    padding: 0 0.7rem;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 9px;
    background: rgba(255, 255, 255, 0.07);
    color: #aeb1bb;
  }

  .dashboard-sidebar-search i {
    font-size: 0.75rem;
  }

  .dashboard-sidebar-search input {
    width: 100%;
    min-width: 0;
    border: 0;
    outline: 0;
    background: transparent;
    color: #f4f4f6;
    font-size: 0.76rem;
  }

  .dashboard-sidebar-search input::placeholder {
    color: #aeb1bb;
  }

  .dashboard-nav-caption {
    padding: 0.35rem 0.8rem 0.55rem;
    color: #989ba5;
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 0.11em;
  }

  .dashboard-nav-caption-spaced {
    margin-top: 0.8rem;
  }

  .dashboard-sidebar-link {
    position: relative;
    display: flex;
    align-items: center;
    gap: 0.85rem;
    width: 100%;
    min-height: 43px;
    margin: 0.15rem 0;
    padding: 0.65rem 0.8rem;
    border: 0;
    border-radius: 9px;
    background: transparent;
    color: #d1d2d8;
    cursor: pointer;
    font: inherit;
    font-size: 0.83rem;
    text-align: left;
    text-decoration: none;
    transition: background 0.18s ease, color 0.18s ease;
  }

  .dashboard-sidebar-link > i:first-child {
    width: 19px;
    color: #a8abb5;
    font-size: 0.94rem;
    text-align: center;
  }

  .dashboard-sidebar-link:hover {
    background: #353a48;
    color: #fff;
  }

  .dashboard-sidebar-link.active {
    background: #f09a50;
    color: #fff;
    font-weight: 650;
  }

  .dashboard-sidebar-link.active > i:first-child {
    color: #fff;
  }

  .dashboard-sidebar-group-toggle {
    appearance: none;
  }

  .dashboard-sidebar-chevron {
    margin-left: auto;
    font-size: 0.65rem;
    transition: transform 0.18s ease;
  }

  .dashboard-sidebar-group-toggle[aria-expanded="true"] .dashboard-sidebar-chevron {
    transform: rotate(180deg);
  }

  .dashboard-sidebar-submenu {
    padding: 0.15rem 0 0.35rem 1.1rem;
  }

  .dashboard-sidebar-submenu[hidden] {
    display: none;
  }

  .dashboard-sidebar-sublink {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    min-height: 36px;
    padding: 0.45rem 0.7rem 0.45rem 1.45rem;
    border-radius: 8px;
    color: #bfc1c9;
    font-size: 0.78rem;
    text-decoration: none;
    transition: background 0.18s ease, color 0.18s ease;
  }

  .dashboard-sidebar-sublink i {
    width: 15px;
    color: #8e929e;
    font-size: 0.78rem;
    text-align: center;
  }

  .dashboard-sidebar-sublink:hover {
    background: #353a48;
    color: #fff;
  }

  .dashboard-sidebar-footer {
    margin-top: auto;
    padding: 0.6rem 0.8rem 0.9rem;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
  }

  .dashboard-sidebar-backdrop {
    display: none;
  }

  .dashboard-main {
    min-height: calc(100vh - 64px);
    margin-left: 260px;
    transition: margin-left 0.22s ease;
  }

  body.dashboard-sidebar-collapsed .dashboard-sidebar {
    width: 76px;
  }

  body.dashboard-sidebar-collapsed .dashboard-main {
    margin-left: 76px;
  }

  body.dashboard-sidebar-collapsed .dashboard-nav-caption,
  body.dashboard-sidebar-collapsed .dashboard-sidebar-search,
  body.dashboard-sidebar-collapsed .dashboard-sidebar-link span,
  body.dashboard-sidebar-collapsed .dashboard-sidebar-chevron,
  body.dashboard-sidebar-collapsed .dashboard-sidebar-submenu,
  body.dashboard-sidebar-collapsed .dashboard-sidebar-footer span {
    display: none;
  }

  body.dashboard-sidebar-collapsed .dashboard-sidebar-link {
    justify-content: center;
    padding-right: 0.5rem;
    padding-left: 0.5rem;
  }

  body.dashboard-sidebar-collapsed .dashboard-sidebar-link > i:first-child {
    width: auto;
  }

  @media (max-width: 767.98px) {
    .dashboard-sidebar {
      width: 260px;
      transform: translateX(-100%);
    }

    .dashboard-main,
    body.dashboard-sidebar-collapsed .dashboard-main {
      margin-left: 0;
    }

    body.dashboard-sidebar-open .dashboard-sidebar {
      transform: translateX(0);
    }

    .dashboard-sidebar-backdrop {
      position: fixed;
      z-index: 1030;
      inset: 64px 0 0;
      border: 0;
      background: rgba(24, 26, 33, 0.38);
    }

    body.dashboard-sidebar-open .dashboard-sidebar-backdrop {
      display: block;
    }

    .dashboard-topbar {
      padding: 0 0.75rem;
    }

    .dashboard-brand {
      margin-left: 0.35rem;
      font-size: 0.76rem;
    }

    .dashboard-brand img {
      width: 32px;
      height: 32px;
    }

    .dashboard-brand small {
      font-size: 0.48rem;
    }

    .dashboard-topbar-actions {
      gap: 0.25rem;
    }

    .dashboard-account-name {
      display: none;
    }
  }

  @media (min-width: 768px) {
    body.dashboard-sidebar-collapsed .dashboard-sidebar-group-toggle {
      justify-content: center;
    }
  }

  .dashboard-page .container-fluid {
    max-width: 1440px;
    margin: 0 auto;
    padding-top: 1.25rem;
    padding-bottom: 2rem;
  }

  .dashboard-welcome {
    margin: 0.25rem 0 1.25rem;
  }

  .dashboard-welcome h1 {
    margin: 0;
    color: #302c29;
    font-size: clamp(1.55rem, 2.5vw, 2rem);
    font-weight: 700;
    letter-spacing: -0.04em;
  }

  .dashboard-welcome p {
    margin: 0.35rem 0 0;
    color: #77736f;
    font-size: 0.98rem;
  }

  .dashboard-page .dashboard-update-strip {
    display: flex;
    align-items: center;
    gap: 0.8rem;
    margin: 0 0 1.25rem;
    padding: 0.9rem 1.1rem;
    border: 1px solid #d8e9ed;
    border-radius: 13px;
    background: #e8f6f8;
    color: #335b62;
    font-size: 0.86rem;
  }

  .dashboard-update-strip > i {
    color: #27a3b3;
    font-size: 1.1rem;
  }

  .dashboard-update-copy {
    flex: 1;
  }

  .dashboard-update-copy strong {
    margin-right: 0.25rem;
    color: #235864;
  }

  .dashboard-update-strip a {
    color: #267c8a;
    font-weight: 650;
    text-decoration: none;
  }

  .dashboard-update-dismiss {
    border: 0;
    background: transparent;
    color: #57828a;
    cursor: pointer;
    font-size: 1rem;
  }

  .dashboard-page .dashboard-toolbar {
    margin-bottom: 0.8rem;
  }

  .dashboard-page .search-container {
    width: 100%;
    max-width: none;
    min-height: 0;
    gap: 0.5rem;
    margin: 0;
    padding: 0;
    border: 0;
    border-radius: 0;
    background: transparent;
    box-shadow: none;
  }

  .dashboard-page .search-input {
    min-width: 120px;
    color: #302c29;
  }

  .dashboard-page .search-input,
  .dashboard-page .search-button {
    display: none;
  }

  body.dashboard-student-search-open .search-input,
  body.dashboard-student-search-open .search-button {
    display: block;
  }

  .dashboard-page .search-container nav {
    margin-left: auto;
  }

  .dashboard-page .search-button,
  .dashboard-page .view-btn.active {
    background: #3546a5;
  }

  .dashboard-page .search-button {
    min-height: 40px;
    border-radius: 10px;
  }

  .dashboard-page .search-button:hover,
  .dashboard-page .view-btn.active:hover {
    background: #293889;
  }

  .dashboard-page .view-toggle {
    flex-shrink: 0;
    border-color: #ebe9e7;
    border-radius: 10px;
    box-shadow: none;
  }

  .dashboard-page .view-btn,
  .dashboard-page .view-btn.active {
    border-radius: 8px;
  }

  .dashboard-page #dashboardContent > .row {
    --bs-gutter-x: 1rem;
    --bs-gutter-y: 1rem;
  }

  .dashboard-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
    margin: 0 0 1.25rem;
  }

  .dashboard-kpi-card {
    position: relative;
    display: flex;
    min-width: 0;
    min-height: 142px;
    align-items: flex-start;
    overflow: hidden;
    padding: 1rem 1.15rem;
    border: 1px solid #e9e7e5;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 3px 14px rgba(36, 31, 27, 0.045);
    color: inherit;
    text-decoration: none;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
  }

  .dashboard-kpi-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 24px rgba(36, 31, 27, 0.09);
    color: inherit;
  }

  .dashboard-kpi-copy {
    position: relative;
    z-index: 1;
    max-width: 68%;
    min-width: 0;
  }

  .dashboard-kpi-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 42px;
    height: 42px;
    margin-bottom: 0.55rem;
    border-radius: 13px;
    font-size: 1rem;
  }

  .dashboard-kpi-card.blue .dashboard-kpi-icon { background: #e5f5fb; color: #1596c8; }
  .dashboard-kpi-card.green .dashboard-kpi-icon { background: #e7f7ef; color: #20a66a; }
  .dashboard-kpi-card.purple .dashboard-kpi-icon { background: #f1edff; color: #8358d6; }
  .dashboard-kpi-card.orange .dashboard-kpi-icon { background: #fff0e5; color: #e58b3d; }

  .dashboard-kpi-value {
    overflow: hidden;
    color: #302c29;
    font-size: clamp(1.35rem, 2vw, 1.75rem);
    font-weight: 750;
    letter-spacing: -0.04em;
    line-height: 1.15;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .dashboard-kpi-label {
    margin-top: 0.2rem;
    color: #85817c;
    font-size: 0.8rem;
  }

  .dashboard-kpi-detail {
    margin-top: 0.28rem;
    color: #aaa6a1;
    font-size: 0.7rem;
  }

  .dashboard-kpi-sparkline {
    position: absolute;
    right: -3px;
    bottom: 1.05rem;
    width: 47%;
    height: 68px;
    opacity: 0.95;
  }

  .dashboard-kpi-sparkline .sparkline-fill {
    opacity: 0.12;
  }

  .dashboard-module-hub {
    margin-bottom: 0;
    padding: 1.35rem;
    border: 1px solid #e9e7e5;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 3px 14px rgba(36, 31, 27, 0.045);
    scroll-margin-top: 82px;
  }

  .dashboard-module-hub-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
  }

  .dashboard-module-hub-title {
    margin: 0;
    color: #302c29;
    font-size: 1.05rem;
    font-weight: 700;
  }

  .dashboard-module-hub-title i {
    margin-right: 0.45rem;
    color: #535eb0;
  }

  .dashboard-module-hub-subtitle {
    margin: 0.25rem 0 0;
    color: #918c87;
    font-size: 0.78rem;
  }

  .dashboard-module-toolbar {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1.05rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid #efeeed;
  }

  .dashboard-module-search {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    width: min(270px, 100%);
    min-height: 40px;
    padding: 0 0.8rem;
    border: 1px solid #e8e6e4;
    border-radius: 10px;
    background: #fafafa;
    color: #a29e99;
  }

  .dashboard-module-search input {
    width: 100%;
    min-width: 0;
    border: 0;
    outline: 0;
    background: transparent;
    color: #393641;
    font-size: 0.8rem;
  }

  .dashboard-module-filters {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    overflow-x: auto;
    scrollbar-width: thin;
  }

  .dashboard-module-filter {
    flex: 0 0 auto;
    padding: 0.48rem 0.8rem;
    border: 1px solid transparent;
    border-radius: 9px;
    background: transparent;
    color: #77736f;
    cursor: pointer;
    font-size: 0.76rem;
    transition: background 0.18s ease, color 0.18s ease;
  }

  .dashboard-module-filter:hover {
    background: #f5f5fa;
    color: #3546a5;
  }

  .dashboard-module-filter.active {
    border-color: #e5e7f3;
    background: #f0f1fa;
    color: #3546a5;
    font-weight: 650;
  }

  .dashboard-module-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.75rem;
  }

  .dashboard-module-card {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    min-width: 0;
    min-height: 74px;
    padding: 0.75rem;
    border: 1px solid #efeeed;
    border-radius: 13px;
    background: #fff;
    color: #393641;
    text-decoration: none;
    transition: border-color 0.18s ease, background 0.18s ease, transform 0.18s ease;
  }

  .dashboard-module-card:hover {
    transform: translateY(-2px);
    border-color: #d9dcef;
    background: #fbfbfe;
    color: #3546a5;
  }

  .dashboard-module-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    flex: 0 0 40px;
    border-radius: 12px;
    background: #edf0fb;
    color: #5968b9;
    font-size: 0.95rem;
  }

  .dashboard-module-card[data-category="Finance"] .dashboard-module-icon { background: #e7f7ef; color: #209d64; }
  .dashboard-module-card[data-category="Academics"] .dashboard-module-icon { background: #e5f5fb; color: #1596c8; }
  .dashboard-module-card[data-category="Students"] .dashboard-module-icon { background: #f1edff; color: #8358d6; }
  .dashboard-module-card[data-category="Operations"] .dashboard-module-icon { background: #fff0e5; color: #e58b3d; }
  .dashboard-module-card[data-category="Engagement"] .dashboard-module-icon { background: #fff0ed; color: #df5d52; }

  .dashboard-module-card span {
    overflow: hidden;
    font-size: 0.8rem;
    font-weight: 650;
    text-overflow: ellipsis;
  }

  .dashboard-module-card[hidden] {
    display: none;
  }

  .dashboard-sidebar-sublink[hidden],
  .dashboard-sidebar-group[hidden],
  .dashboard-sidebar-nav > .dashboard-sidebar-link[hidden] {
    display: none;
  }

  .dashboard-module-empty {
    grid-column: 1 / -1;
    padding: 1.5rem 1rem;
    color: #85817c;
    font-size: 0.84rem;
    text-align: center;
  }

  .dashboard-module-hub-footer {
    margin-top: 0.9rem;
    color: #96918d;
    font-size: 0.72rem;
  }

  .dashboard-hub-footer-button {
    margin-left: 0.35rem;
    padding: 0;
    border: 0;
    background: transparent;
    color: #3546a5;
    cursor: pointer;
    font: inherit;
    font-weight: 650;
  }

  .dashboard-panels-grid {
    display: grid;
    grid-template-columns: repeat(12, minmax(0, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
  }

  .dashboard-module-hub-panel {
    grid-column: span 8;
    align-self: start;
  }

  .dashboard-panel-half { grid-column: span 6; }
  .dashboard-panel-third { grid-column: span 4; }
  .dashboard-panel-two-thirds { grid-column: span 8; }
  .dashboard-panel-full { grid-column: 1 / -1; }
  .dashboard-panel-half,
  .dashboard-panel-third,
  .dashboard-panel-two-thirds,
  .dashboard-panel-full {
    align-self: start;
  }

  .dashboard-data-panel {
    min-width: 0;
    padding: 1.25rem;
    border: 1px solid #e9e7e5;
    border-radius: 17px;
    background: #fff;
    box-shadow: 0 3px 14px rgba(36, 31, 27, 0.045);
  }

  .dashboard-data-panel.span-two {
    grid-column: 1 / -1;
  }

  .dashboard-data-panel-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
    margin-bottom: 1rem;
  }

  .dashboard-data-panel-title {
    margin: 0;
    color: #302c29;
    font-size: 0.98rem;
    font-weight: 700;
  }

  .dashboard-data-panel-title i {
    width: 23px;
    margin-right: 0.3rem;
    color: #5665b2;
    text-align: center;
  }

  .dashboard-data-panel-subtitle {
    margin-top: 0.2rem;
    color: #96918d;
    font-size: 0.72rem;
  }

  .dashboard-data-panel-body {
    min-width: 0;
  }

  .dashboard-empty-state {
    padding: 1.5rem 0.75rem;
    color: #918c87;
    font-size: 0.8rem;
    text-align: center;
  }

  .dashboard-empty-state i {
    display: block;
    margin-bottom: 0.55rem;
    color: #c0bdba;
    font-size: 1.2rem;
  }

  .dashboard-attendance-summary {
    display: flex;
    align-items: center;
    gap: 1.1rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid #efeeed;
  }

  .dashboard-attendance-ring {
    display: grid;
    width: 88px;
    height: 88px;
    flex: 0 0 88px;
    place-items: center;
    border-radius: 50%;
    background: conic-gradient(#1ba3b8 var(--attendance-rate), #eef0f4 0);
  }

  .dashboard-attendance-ring > span {
    display: grid;
    width: 68px;
    height: 68px;
    place-items: center;
    border-radius: 50%;
    background: #fff;
    color: #302c29;
    font-size: 1.15rem;
    font-weight: 750;
  }

  .dashboard-attendance-counts {
    display: grid;
    flex: 1;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.5rem;
  }

  .dashboard-attendance-count {
    min-width: 0;
  }

  .dashboard-attendance-count strong {
    display: block;
    color: #302c29;
    font-size: 1.05rem;
  }

  .dashboard-attendance-count span {
    display: block;
    color: #918c87;
    font-size: 0.67rem;
  }

  .dashboard-breakdown-list {
    display: grid;
    gap: 0.65rem;
    max-height: 310px;
    overflow-y: auto;
    padding-top: 0.9rem;
  }

  .dashboard-breakdown-item {
    display: grid;
    grid-template-columns: minmax(70px, 1fr) minmax(80px, 1.5fr) auto;
    align-items: center;
    gap: 0.65rem;
    color: #5f5b57;
    font-size: 0.72rem;
  }

  .dashboard-breakdown-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .dashboard-breakdown-track {
    height: 7px;
    overflow: hidden;
    border-radius: 10px;
    background: #eef0f4;
  }

  .dashboard-breakdown-track span {
    display: block;
    height: 100%;
    border-radius: inherit;
    background: #1ba3b8;
  }

  .dashboard-breakdown-value {
    color: #77736f;
    white-space: nowrap;
  }

  .dashboard-data-table-wrap {
    overflow-x: auto;
  }

  .dashboard-data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.75rem;
  }

  .dashboard-data-table th {
    padding: 0.55rem 0.45rem;
    border-bottom: 1px solid #eeecea;
    color: #96918d;
    font-size: 0.65rem;
    font-weight: 650;
    text-align: left;
    white-space: nowrap;
  }

  .dashboard-data-table td {
    padding: 0.62rem 0.45rem;
    border-bottom: 1px solid #f2f1ef;
    color: #595550;
    vertical-align: middle;
  }

  .dashboard-data-table tr:last-child td {
    border-bottom: 0;
  }

  .dashboard-overdue-student {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    min-width: 145px;
  }

  .dashboard-initials {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 31px;
    height: 31px;
    flex: 0 0 31px;
    border-radius: 50%;
    background: #f0f1fa;
    color: #5968b9;
    font-size: 0.64rem;
    font-weight: 700;
  }

  .dashboard-overdue-student strong,
  .dashboard-overdue-student small {
    display: block;
  }

  .dashboard-overdue-student strong {
    overflow: hidden;
    color: #393641;
    font-size: 0.72rem;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .dashboard-overdue-student small {
    margin-top: 0.1rem;
    color: #99948f;
    font-size: 0.64rem;
  }

  .dashboard-status-pill {
    display: inline-flex;
    padding: 0.24rem 0.48rem;
    border-radius: 20px;
    background: #fff0ed;
    color: #d85b4e;
    font-size: 0.63rem;
    font-weight: 650;
    white-space: nowrap;
  }

  .dashboard-list {
    display: grid;
    gap: 0.15rem;
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .dashboard-activity-list {
    max-height: 470px;
    overflow-y: auto;
  }

  .dashboard-list-item {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 0.7rem 0.15rem;
    border-bottom: 1px solid #f0efed;
  }

  .dashboard-list-item:last-child {
    border-bottom: 0;
  }

  .dashboard-list-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    flex: 0 0 34px;
    border-radius: 10px;
    background: #edf0fb;
    color: #5968b9;
    font-size: 0.8rem;
  }

  .dashboard-list-copy {
    flex: 1;
    min-width: 0;
  }

  .dashboard-list-copy strong {
    display: block;
    overflow: hidden;
    color: #45413d;
    font-size: 0.75rem;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .dashboard-list-copy small {
    display: block;
    margin-top: 0.2rem;
    color: #96918d;
    font-size: 0.67rem;
  }

  .dashboard-list-meta {
    color: #77736f;
    font-size: 0.67rem;
    text-align: right;
    white-space: nowrap;
  }

  .dashboard-collection-chart {
    min-height: 270px;
  }

  .dashboard-transport-summary,
  .dashboard-admission-summary,
  .dashboard-staff-summary {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.7rem;
  }

  .dashboard-mini-stat {
    padding: 0.8rem 0.65rem;
    border: 1px solid #efeeed;
    border-radius: 12px;
    background: #fcfcfd;
  }

  .dashboard-mini-stat strong {
    display: block;
    color: #302c29;
    font-size: 1.15rem;
  }

  .dashboard-mini-stat span {
    display: block;
    margin-top: 0.15rem;
    color: #918c87;
    font-size: 0.67rem;
  }

  .dashboard-birthday-date {
    color: #dd8d45;
    font-size: 0.67rem;
    font-weight: 650;
    white-space: nowrap;
  }

  .dashboard-setup-list {
    display: grid;
    gap: 0.25rem;
  }

  .dashboard-setup-row {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    padding: 0.65rem 0.1rem;
    border-bottom: 1px solid #f0efed;
    color: #5d5954;
    font-size: 0.76rem;
  }

  .dashboard-setup-row:last-child {
    border-bottom: 0;
  }

  .dashboard-setup-row i {
    width: 18px;
    color: #25a26b;
  }

  .dashboard-setup-row.pending i {
    color: #e59746;
  }

  .dashboard-setup-row span:last-child {
    margin-left: auto;
    color: #898580;
    font-size: 0.67rem;
  }

  .dashboard-page .stat-card {
    min-height: 145px;
    padding: 1.25rem;
    border: 1px solid #eceae8;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 3px 14px rgba(36, 31, 27, 0.045);
  }

  .dashboard-page .stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 9px 24px rgba(36, 31, 27, 0.08);
  }

  .dashboard-page .stat-header {
    gap: 0.75rem;
    margin-bottom: 0.65rem;
  }

  .dashboard-page .stat-icon {
    width: 44px;
    height: 44px;
    flex: 0 0 44px;
    border-radius: 13px;
    background: #e8f5fb;
    font-size: 1.15rem;
  }

  .dashboard-page .white-bg .stat-icon.blue-icon { color: #1596c8; background: #e5f5fb; }
  .dashboard-page .white-bg .stat-icon.red-icon { color: #df5d52; background: #fff0ed; }
  .dashboard-page .white-bg .stat-icon.orange-icon { color: #8358d6; background: #f1edff; }
  .dashboard-page .white-bg .stat-icon.green-icon { color: #20a66a; background: #e7f7ef; }

  .dashboard-page .white-bg .stat-label {
    font-size: 0.9rem;
    font-weight: 600;
    line-height: 1.35;
  }

  .dashboard-page .white-bg .stat-label.blue-text { color: #1596c8; }
  .dashboard-page .white-bg .stat-label.red-text { color: #df5d52; }
  .dashboard-page .white-bg .stat-label.orange-text { color: #8358d6; }
  .dashboard-page .white-bg .stat-label.green-text { color: #20a66a; }

  .dashboard-page .stat-number {
    margin: 0.4rem 0 0.3rem;
    color: #302c29;
    font-size: 1.7rem;
    letter-spacing: -0.035em;
  }

  .dashboard-page .stat-trend {
    flex-wrap: wrap;
    color: #898581;
    font-size: 0.78rem;
  }

  .dashboard-page .modern-panel,
  .dashboard-page .calendar-card {
    padding: 1.35rem;
    border: 1px solid #eceae8;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 3px 14px rgba(36, 31, 27, 0.045);
  }

  .dashboard-page .modern-panel::after {
    content: none;
  }

  .dashboard-page .panel-header {
    margin-bottom: 1rem;
  }

  .dashboard-page .panel-title,
  .dashboard-page .calendar-month {
    color: #302c29;
    font-size: 1.05rem;
    font-weight: 650;
  }

  .dashboard-page .panel-title i {
    color: #3546a5;
  }

  .dashboard-page .quick-action-item {
    border: 1px solid #ececf5;
    border-radius: 13px;
    background: #f7f8fd;
    color: #454b75;
  }

  .dashboard-page .quick-action-item:hover {
    background: #eef0fb;
    transform: translateY(-2px);
  }

  .dashboard-page .activity-item {
    border-color: #f0efed;
  }

  .dashboard-activity-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
  }

  .dashboard-activity-filter {
    padding: 0.38rem 0.65rem;
    border: 1px solid transparent;
    border-radius: 8px;
    background: transparent;
    color: #85817c;
    cursor: pointer;
    font-size: 0.7rem;
  }

  .dashboard-activity-filter.active {
    border-color: #e5e7f3;
    background: #f0f1fa;
    color: #3546a5;
    font-weight: 650;
  }

  .activity-item[hidden] {
    display: none;
  }

  @media (max-width: 767.98px) {
    .dashboard-kpi-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .dashboard-panels-grid {
      grid-template-columns: 1fr;
    }

    .dashboard-panel-half,
    .dashboard-panel-third,
    .dashboard-panel-two-thirds,
    .dashboard-module-hub-panel,
    .dashboard-panel-full {
      grid-column: 1;
    }

    .dashboard-module-toolbar {
      align-items: stretch;
      flex-direction: column;
    }

    .dashboard-module-search {
      width: 100%;
    }

    .dashboard-module-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .dashboard-update-strip {
      align-items: flex-start;
      font-size: 0.78rem;
    }

    .dashboard-page .container-fluid {
      padding-right: 1rem;
      padding-left: 1rem;
      padding-top: 1rem;
    }

    .dashboard-page .search-container {
      flex-wrap: wrap;
    }

    .dashboard-page .search-input {
      flex: 1 1 calc(100% - 60px);
    }

    body.dashboard-student-search-open .search-input,
    body.dashboard-student-search-open .search-button {
      display: block;
    }

    .dashboard-page .search-container nav {
      width: 100%;
      justify-content: flex-end !important;
      margin-top: 0.25rem;
    }
  }

  @media (max-width: 420px) {
    .dashboard-kpi-grid {
      grid-template-columns: 1fr;
    }

    .dashboard-module-grid {
      grid-template-columns: 1fr;
    }

    .dashboard-module-hub {
      padding: 1rem;
    }
  }

  @media (min-width: 768px) and (max-width: 1100px) {
    .dashboard-kpi-grid,
    .dashboard-module-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .dashboard-panels-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .dashboard-panel-half,
    .dashboard-panel-third,
    .dashboard-panel-two-thirds,
    .dashboard-module-hub-panel {
      grid-column: span 1;
    }

    .dashboard-panel-full {
      grid-column: 1 / -1;
    }
  }
</style>

<main class="dashboard-main">
<div class="container-fluid px-4">
  <div class="dashboard-welcome">
    <h1><?php echo htmlspecialchars($dashboard_greeting, ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(ucfirst((string) ($username ?? 'there')), ENT_QUOTES, 'UTF-8'); ?> 👋</h1>
    <p>Here is what is happening at Dar-e-Arqam today — <?php echo htmlspecialchars($formatted_date, ENT_QUOTES, 'UTF-8'); ?>.</p>
  </div>

  <div class="dashboard-update-strip" id="dashboardUpdateStrip">
    <i class="fa-solid fa-calendar-check"></i>
    <div class="dashboard-update-copy">
      <strong>School overview.</strong>
      Your student, fee, attendance, and activity summaries are ready below.
      <?php if (!empty($dashboard_due_notifications)): ?>
        <a href="student_feecards.php"><?php echo count($dashboard_due_notifications); ?> upcoming fee due date<?php echo count($dashboard_due_notifications) === 1 ? '' : 's'; ?> — view fee cards</a>
      <?php endif; ?>
    </div>
    <button type="button" class="dashboard-update-dismiss" id="dashboardUpdateDismiss" aria-label="Dismiss overview message">&times;</button>
  </div>

  <!-- Search Section -->
  <div class="row justify-content-center dashboard-toolbar">
    <div class="col-12">
      <div class="search-container d-flex align-items-center">
       
        <input type="text" class="search-input" id="searchInput" placeholder="Search by registration ID or student name">
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
</main>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
// Pass PHP data to JavaScript
const phpData = {
    currentDate: <?php echo json_encode($current_date); ?>,
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
    activeSession: <?php echo json_encode($active_session_title, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
    classAttendance: <?php echo json_encode($class_attendance, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
    overdueFees: <?php echo json_encode($overdue_fees, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
    recentActivities: <?php echo json_encode($recent_activities, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
    upcomingFeeDues: <?php echo json_encode($dashboard_due_notifications ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
    dailyCollectionLabels: <?php echo json_encode($daily_collection_labels); ?>,
    dailyCollectionValues: <?php echo json_encode($daily_collection_values); ?>,
    timetableAvailable: <?php echo json_encode($timetable_available); ?>,
    todayTimetable: <?php echo json_encode($today_timetable, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
    upcomingExams: <?php echo json_encode($upcoming_exams, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
    admissionsSummary: <?php echo json_encode($admissions_summary); ?>,
    transportSummary: <?php echo json_encode($transport_summary); ?>,
    staffAttendance: <?php echo json_encode($staff_attendance); ?>,
    staffSnapshot: <?php echo json_encode($staff_snapshot, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
    studentBirthdays: <?php echo json_encode($student_birthdays, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
    setupStatus: <?php echo json_encode($setup_status, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
    calendarEvents: <?php echo json_encode($calendar_events); ?>
};

const dashboardModules = <?php
$dashboard_module_items = [];
foreach (($role_modules ?? []) as $dashboard_module) {
    $dashboard_parent_title = (string) ($dashboard_module['title'] ?? '');
    if (!empty($dashboard_module['children'])) {
        foreach ($dashboard_module['children'] as $dashboard_child) {
            $dashboard_child_url = (string) ($dashboard_child['url'] ?? '#');
            if ($dashboard_child_url !== '#') {
                $dashboard_module_items[] = [
                    'title' => (string) ($dashboard_child['title'] ?? 'Untitled'),
                    'url' => $dashboard_child_url,
                    'parent' => $dashboard_parent_title
                ];
            }
        }
    } else {
        $dashboard_module_url = (string) ($dashboard_module['url'] ?? '#');
        if ($dashboard_module_url !== '#') {
            $dashboard_module_items[] = [
                'title' => $dashboard_parent_title,
                'url' => $dashboard_module_url,
                'parent' => ''
            ];
        }
    }
}
echo json_encode($dashboard_module_items, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
?>;

function escapeDashboardHTML(value) {
    return String(value).replace(/[&<>"']/g, function(character) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        }[character];
    });
}

function getModuleCategory(module) {
    const searchableName = `${module.parent} ${module.title}`.toLowerCase();
    if (/fee|finance|account|payment|expense|salary|supplier|voucher|ledger/.test(searchableName)) return 'Finance';
    if (/student|admission|parent|guardian|registration/.test(searchableName)) return 'Students';
    if (/academic|class|exam|subject|course|result|attendance|timetable|dmc|lesson/.test(searchableName)) return 'Academics';
    if (/notice|message|whatsapp|communication|ptm|website|contact/.test(searchableName)) return 'Engagement';
    if (/role|user|setting|module|permission|system|backup/.test(searchableName)) return 'Admin';
    return 'Operations';
}

function getModuleIcon(module) {
    const searchableName = `${module.parent} ${module.title}`.toLowerCase();
    if (/fee|payment|collection/.test(searchableName)) return 'fa-money-bill-wave';
    if (/account|ledger|voucher|finance/.test(searchableName)) return 'fa-chart-pie';
    if (/expense|supplier|salary/.test(searchableName)) return 'fa-receipt';
    if (/student|admission|parent|guardian/.test(searchableName)) return 'fa-user-graduate';
    if (/attendance/.test(searchableName)) return 'fa-calendar-check';
    if (/exam|result|dmc/.test(searchableName)) return 'fa-file-lines';
    if (/class|course|subject|timetable|lesson/.test(searchableName)) return 'fa-book-open';
    if (/notice|message|whatsapp|ptm/.test(searchableName)) return 'fa-bullhorn';
    if (/role|user|setting|module|permission/.test(searchableName)) return 'fa-gear';
    if (/report/.test(searchableName)) return 'fa-chart-column';
    return 'fa-folder';
}

function getDashboardSummaryCards(view) {
    const attendance = phpData.todayAttendance || { present: 0, total: 0 };
    const attendanceRate = attendance.total > 0
        ? Math.round((attendance.present / attendance.total) * 100)
        : 0;
    const cards = [
        {
            label: 'Total Students',
            value: formatNumber(phpData.totalStudents),
            detail: `${formatNumber(phpData.activeStudents)} active students`,
            icon: 'fa-user-graduate',
            color: 'blue',
            url: 'student_list.php',
            data: phpData.classCounts.length ? phpData.classCounts : phpData.monthlyCollection
        },
        {
            label: view === 'daily' ? "Today's Collection" : "Collected This Month",
            value: `Rs. ${formatNumber(view === 'daily' ? phpData.todayCollection : phpData.monthCollection)}`,
            detail: view === 'daily'
                ? `${Math.abs(phpData.trendPercentage)}% vs yesterday`
                : `${Math.abs(phpData.monthlyTrend)}% vs last month`,
            icon: 'fa-money-bill-wave',
            color: 'green',
            url: 'fee_collection.php',
            data: view === 'daily' ? phpData.hourlyData : phpData.monthlyCollection
        },
        {
            label: 'Attendance Today',
            value: `${attendanceRate}%`,
            detail: `${formatNumber(attendance.present)} students present`,
            icon: 'fa-calendar-check',
            color: 'purple',
            url: 'attendance_report.php',
            data: phpData.hourlyData
        },
        {
            label: 'Pending Fee Dues',
            value: `Rs. ${formatNumber(phpData.defaulterAmount)}`,
            detail: `${formatNumber(phpData.defaulterCount)} students with pending fees`,
            icon: 'fa-file-invoice-dollar',
            color: 'orange',
            url: 'student_feecards.php',
            data: phpData.monthlyExpenses
        }
    ];

    return `<section class="dashboard-kpi-grid" aria-label="School summary">
        ${cards.map(function(card) {
            const values = (card.data || []).slice(-8).map(Number);
            const maximum = Math.max(1, ...values);
            const points = values.map(function(value, index) {
                const x = values.length > 1 ? (index / (values.length - 1)) * 100 : 100;
                const y = 43 - (Math.max(0, value) / maximum) * 35;
                return `${x},${y}`;
            });
            const line = points.length ? `M ${points.join(' L ')}` : 'M 0,38 L 100,18';
            const area = `${line} L 100,54 L 0,54 Z`;
            const chartColor = {
                blue: '#1596c8',
                green: '#20a66a',
                purple: '#8358d6',
                orange: '#e58b3d'
            }[card.color];

            return `<a class="dashboard-kpi-card ${card.color}" href="${escapeDashboardHTML(card.url)}">
                <div class="dashboard-kpi-copy">
                    <span class="dashboard-kpi-icon"><i class="fa-solid ${card.icon}"></i></span>
                    <div class="dashboard-kpi-value">${escapeDashboardHTML(card.value)}</div>
                    <div class="dashboard-kpi-label">${escapeDashboardHTML(card.label)}</div>
                    <div class="dashboard-kpi-detail">${escapeDashboardHTML(card.detail)}</div>
                </div>
                <svg class="dashboard-kpi-sparkline" viewBox="0 0 100 54" preserveAspectRatio="none" aria-hidden="true">
                    <path class="sparkline-fill" d="${area}" fill="${chartColor}"></path>
                    <path d="${line}" fill="none" stroke="${chartColor}" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"></path>
                </svg>
            </a>`;
        }).join('')}
    </section>`;
}

function getModuleHubHTML() {
    return `<section class="dashboard-module-hub dashboard-module-hub-panel" id="ecosystemHub" aria-labelledby="moduleHubTitle">
        <div class="dashboard-module-hub-header">
            <div>
                <h2 class="dashboard-module-hub-title" id="moduleHubTitle"><i class="fa-solid fa-table-cells-large"></i> Ecosystem Hub</h2>
                <p class="dashboard-module-hub-subtitle"><strong>${dashboardModules.length}</strong> role-enabled modules — your school tools in one place</p>
            </div>
        </div>
        <div class="dashboard-module-toolbar">
            <label class="dashboard-module-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" id="moduleSearch" placeholder="Search your modules..." autocomplete="off">
            </label>
            <div class="dashboard-module-filters" id="moduleFilters" role="group" aria-label="Filter modules"></div>
        </div>
        <div class="dashboard-module-grid" id="moduleGrid"></div>
        <div class="dashboard-module-hub-footer" id="moduleHubFooter"></div>
    </section>`;
}

function dashboardInitials(name) {
    return String(name).trim().split(/\s+/).slice(0, 2).map(function(part) {
        return part.charAt(0);
    }).join('').toUpperCase();
}

function formatDashboardDate(dateString, options) {
    if (!dateString) return '';
    const date = new Date(`${dateString}T00:00:00`);
    if (Number.isNaN(date.getTime())) return '';
    return date.toLocaleDateString(undefined, options || { month: 'short', day: 'numeric' });
}

function getDashboardActivityPanel() {
    const activities = phpData.recentActivities || [];
    const items = activities.map(function(activity) {
        const icon = activity.type === 'fee' ? 'fa-coins'
            : activity.type === 'student' ? 'fa-user-plus'
            : 'fa-calendar-check';
        return `<li class="dashboard-list-item activity-item" data-activity-type="${escapeDashboardHTML(activity.type)}">
            <span class="dashboard-list-icon"><i class="fa-solid ${icon}"></i></span>
            <span class="dashboard-list-copy">
                <strong>${escapeDashboardHTML(activity.title)}</strong>
                <small>${escapeDashboardHTML(formatDashboardDate(activity.date, { weekday: 'short', month: 'short', day: 'numeric' }))}</small>
            </span>
            <span class="dashboard-status-pill">${escapeDashboardHTML(activity.type === 'fee' ? 'Fee' : activity.type === 'student' ? 'Admission' : 'Attendance')}</span>
        </li>`;
    }).join('');

    return `<section class="dashboard-data-panel dashboard-panel-third" aria-labelledby="liveActivityTitle">
        <div class="dashboard-data-panel-header">
            <div>
                <h2 class="dashboard-data-panel-title" id="liveActivityTitle"><i class="fa-solid fa-bolt"></i> Live Activity</h2>
                <div class="dashboard-data-panel-subtitle">Recent updates from your school records</div>
            </div>
            <div class="dashboard-activity-filters" role="group" aria-label="Filter activity">
                <button type="button" class="dashboard-activity-filter active" data-activity-filter="all" aria-pressed="true">All</button>
                <button type="button" class="dashboard-activity-filter" data-activity-filter="fee" aria-pressed="false">Fees</button>
                <button type="button" class="dashboard-activity-filter" data-activity-filter="student" aria-pressed="false">Admissions</button>
                <button type="button" class="dashboard-activity-filter" data-activity-filter="attendance" aria-pressed="false">Attendance</button>
            </div>
        </div>
        <ul class="dashboard-list dashboard-activity-list" id="recentActivityList">${items || '<li class="dashboard-empty-state">No recent activity found.</li>'}</ul>
    </section>`;
}

function getAttendancePanel() {
    const classes = phpData.classAttendance || [];
    const enrolled = classes.reduce(function(total, item) { return total + item.enrolled; }, 0);
    const present = classes.reduce(function(total, item) { return total + item.present; }, 0);
    const absent = classes.reduce(function(total, item) { return total + item.absent; }, 0);
    const unmarked = Math.max(0, enrolled - present - absent);
    const rate = enrolled > 0 ? Math.round(present / enrolled * 100) : 0;
    const classRows = classes.filter(function(item) { return item.enrolled > 0; }).map(function(item) {
        const percent = item.enrolled > 0 ? Math.round(item.present / item.enrolled * 100) : 0;
        return `<div class="dashboard-breakdown-item">
            <span class="dashboard-breakdown-name" title="${escapeDashboardHTML(item.class)}">${escapeDashboardHTML(item.class)}</span>
            <span class="dashboard-breakdown-track"><span style="width:${percent}%"></span></span>
            <span class="dashboard-breakdown-value"><strong>${percent}%</strong> · ${item.enrolled}</span>
        </div>`;
    }).join('');

    return `<section class="dashboard-data-panel dashboard-panel-half" aria-labelledby="attendanceTitle">
        <div class="dashboard-data-panel-header">
            <div>
                <h2 class="dashboard-data-panel-title" id="attendanceTitle"><i class="fa-solid fa-calendar-check"></i> Today's Attendance</h2>
                <div class="dashboard-data-panel-subtitle">Students · ${escapeDashboardHTML(formatDashboardDate(phpData.currentDate, { day: '2-digit', month: 'short', year: 'numeric' }))}${phpData.activeSession ? ` · ${escapeDashboardHTML(phpData.activeSession)}` : ''}</div>
            </div>
        </div>
        <div class="dashboard-attendance-summary">
            <div class="dashboard-attendance-ring" style="--attendance-rate:${rate * 3.6}deg"><span>${rate}%</span></div>
            <div class="dashboard-attendance-counts">
                <div class="dashboard-attendance-count"><strong>${present}</strong><span>Present</span></div>
                <div class="dashboard-attendance-count"><strong>${absent}</strong><span>Absent</span></div>
                <div class="dashboard-attendance-count"><strong>${enrolled}</strong><span>Enrolled</span></div>
            </div>
        </div>
        ${classRows
            ? `<h3 class="dashboard-breakdown-heading">Class-wise Breakdown</h3><div class="dashboard-breakdown-list">${classRows}</div>`
            : '<div class="dashboard-empty-state">No active-session class enrollments are available.</div>'}
        ${unmarked ? `<div class="dashboard-data-panel-subtitle">${unmarked} student${unmarked === 1 ? '' : 's'} without an attendance record today.</div>` : ''}
    </section>`;
}

function getOverdueFeesPanel() {
    const fees = phpData.overdueFees || [];
    const rows = fees.map(function(fee) {
        return `<tr>
            <td><span class="dashboard-overdue-student">
                <span class="dashboard-initials">${escapeDashboardHTML(dashboardInitials(fee.student))}</span>
                <span><strong>${escapeDashboardHTML(fee.student)}</strong><small>${escapeDashboardHTML(fee.class || 'Class not assigned')}</small></span>
            </span></td>
            <td>Rs. ${formatNumber(fee.amount)}</td>
            <td>${escapeDashboardHTML(formatDashboardDate(fee.due_date))}</td>
            <td><span class="dashboard-status-pill">Overdue</span></td>
        </tr>`;
    }).join('');

    return `<section class="dashboard-data-panel dashboard-panel-half" aria-labelledby="overdueFeesTitle">
        <div class="dashboard-data-panel-header">
            <div>
                <h2 class="dashboard-data-panel-title" id="overdueFeesTitle"><i class="fa-solid fa-file-invoice-dollar"></i> Pending Fee Dues</h2>
                <div class="dashboard-data-panel-subtitle">Top overdue · ${phpData.activeSession ? escapeDashboardHTML(phpData.activeSession) : 'Active session'}</div>
            </div>
            <a class="dashboard-panel-link" href="student_feecards.php">View all</a>
        </div>
        <div class="dashboard-data-table-wrap">
            ${rows
                ? `<table class="dashboard-data-table"><thead><tr><th>Student</th><th>Amount</th><th>Due</th><th>Status</th></tr></thead><tbody>${rows}</tbody></table>`
                : '<div class="dashboard-empty-state"><i class="fa-solid fa-circle-check"></i>No overdue fees for the active session.</div>'}
        </div>
    </section>`;
}

function getTimetablePanel() {
    const lessons = phpData.todayTimetable || [];
    const items = lessons.map(function(lesson) {
        const time = lesson.start
            ? new Date(`1970-01-01T${lesson.start}`).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
            : '';
        return `<li class="dashboard-list-item">
            <span class="dashboard-list-icon"><i class="fa-solid fa-book-open"></i></span>
            <span class="dashboard-list-copy">
                <strong>${escapeDashboardHTML(lesson.subject || 'Lesson')}</strong>
                <small>${escapeDashboardHTML(lesson.class || 'Class')} · ${escapeDashboardHTML(lesson.teacher || 'Teacher not assigned')}</small>
            </span>
            <span class="dashboard-list-meta">${escapeDashboardHTML(time)}</span>
        </li>`;
    }).join('');
    return `<section class="dashboard-data-panel dashboard-panel-third" aria-labelledby="timetableTitle">
        <div class="dashboard-data-panel-header">
            <div><h2 class="dashboard-data-panel-title" id="timetableTitle"><i class="fa-solid fa-clock"></i> Today's Timetable</h2><div class="dashboard-data-panel-subtitle">Today's scheduled lessons</div></div>
            <a class="dashboard-panel-link" href="create_timetable.php">Timetable</a>
        </div>
        ${items ? `<ul class="dashboard-list">${items}</ul>` : `<div class="dashboard-empty-state"><i class="fa-regular fa-calendar"></i>${phpData.timetableAvailable ? 'No timetable entries scheduled for today.' : 'The timetable is not configured in this installation.'}</div>`}
    </section>`;
}

function getEventsExamsPanel() {
    const exams = (phpData.upcomingExams || []).map(function(exam) {
        return `<li class="dashboard-list-item">
            <span class="dashboard-list-icon"><i class="fa-solid fa-file-lines"></i></span>
            <span class="dashboard-list-copy"><strong>${escapeDashboardHTML(exam.subject || 'Exam')}</strong><small>${escapeDashboardHTML(exam.class || 'Class')} · ${escapeDashboardHTML(formatDashboardDate(exam.date))}</small></span>
            <span class="dashboard-list-meta">${escapeDashboardHTML(exam.time ? new Date(`1970-01-01T${exam.time}`).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '')}</span>
        </li>`;
    }).join('');
    const dues = (phpData.upcomingFeeDues || []).map(function(due) {
        return `<li class="dashboard-list-item">
            <span class="dashboard-list-icon"><i class="fa-solid fa-coins"></i></span>
            <span class="dashboard-list-copy"><strong>Fee due · ${due.student_count} students</strong><small>${escapeDashboardHTML(formatDashboardDate(due.due_date))}</small></span>
            <span class="dashboard-list-meta">Rs. ${formatNumber(due.total_amount)}</span>
        </li>`;
    }).join('');
    return `<section class="dashboard-data-panel dashboard-panel-third" aria-labelledby="eventsTitle">
        <div class="dashboard-data-panel-header">
            <div><h2 class="dashboard-data-panel-title" id="eventsTitle"><i class="fa-solid fa-calendar-days"></i> Upcoming Events & Exams</h2><div class="dashboard-data-panel-subtitle">Scheduled exams and fee due dates</div></div>
            <a class="dashboard-panel-link" href="datesheet_list.php">Exam datesheets</a>
        </div>
        ${exams || dues
            ? `<ul class="dashboard-list">${exams}${dues}</ul>`
            : '<div class="dashboard-empty-state"><i class="fa-regular fa-calendar"></i>No upcoming exams or fee due dates.</div>'}
    </section>`;
}

function getTransportPanel() {
    const transport = phpData.transportSummary || {};
    return `<section class="dashboard-data-panel dashboard-panel-third" aria-labelledby="transportTitle">
        <div class="dashboard-data-panel-header">
            <div><h2 class="dashboard-data-panel-title" id="transportTitle"><i class="fa-solid fa-bus"></i> Transport Status</h2><div class="dashboard-data-panel-subtitle">Student transport registrations</div></div>
        </div>
        <div class="dashboard-transport-summary">
            <div class="dashboard-mini-stat"><strong>${formatNumber(transport.students || 0)}</strong><span>Students using transport</span></div>
            <div class="dashboard-mini-stat"><strong>Rs. ${formatNumber(transport.monthly_fees || 0)}</strong><span>Transport fees recorded</span></div>
        </div>
        <div class="dashboard-data-panel-subtitle dashboard-source-note">Vehicle and route statuses are not tracked in this installation.</div>
    </section>`;
}

function getAdmissionsPanel() {
    const admissions = phpData.admissionsSummary || {};
    return `<section class="dashboard-data-panel dashboard-panel-third" aria-labelledby="admissionsTitle">
        <div class="dashboard-data-panel-header">
            <div><h2 class="dashboard-data-panel-title" id="admissionsTitle"><i class="fa-solid fa-user-plus"></i> Admissions</h2><div class="dashboard-data-panel-subtitle">Registration summary this month</div></div>
            <a class="dashboard-panel-link" href="student_registration.php">New registration</a>
        </div>
        <div class="dashboard-admission-summary">
            <div class="dashboard-mini-stat"><strong>${formatNumber(Number(admissions.admitted) || 0)}</strong><span>Admitted</span></div>
            <div class="dashboard-mini-stat"><strong>${formatNumber(Number(admissions.pending) || 0)}</strong><span>Pending</span></div>
            <div class="dashboard-mini-stat"><strong>${formatNumber(Number(admissions.total) || 0)}</strong><span>Registrations</span></div>
        </div>
        <div class="dashboard-data-panel-subtitle dashboard-source-note">Enquiry stages and seat capacity are not recorded here.</div>
    </section>`;
}

function getBirthdaysPanel() {
    const birthdays = (phpData.studentBirthdays || []).map(function(birthday) {
        return `<li class="dashboard-list-item">
            <span class="dashboard-list-icon dashboard-birthday-icon"><i class="fa-solid fa-cake-candles"></i></span>
            <span class="dashboard-list-copy"><strong>${escapeDashboardHTML(birthday.name)}</strong><small>${escapeDashboardHTML(birthday.class || 'Student')}</small></span>
            <span class="dashboard-birthday-date">${escapeDashboardHTML(formatDashboardDate(birthday.date))}${birthday.age ? ` · ${birthday.age}` : ''}</span>
        </li>`;
    }).join('');
    return `<section class="dashboard-data-panel dashboard-panel-third" aria-labelledby="birthdaysTitle">
        <div class="dashboard-data-panel-header">
            <div><h2 class="dashboard-data-panel-title" id="birthdaysTitle"><i class="fa-solid fa-cake-candles"></i> Upcoming Birthdays</h2><div class="dashboard-data-panel-subtitle">Students · next seven days</div></div>
        </div>
        ${birthdays ? `<ul class="dashboard-list">${birthdays}</ul>` : '<div class="dashboard-empty-state">No student birthdays in the next seven days.</div>'}
    </section>`;
}

function getStaffPanel() {
    const staff = phpData.staffAttendance || {};
    const staffRows = (phpData.staffSnapshot || []).map(function(person) {
        const status = person.status === 'P' ? 'Present' : person.status === 'L' ? 'Leave' : person.status;
        return `<li class="dashboard-list-item">
            <span class="dashboard-initials">${escapeDashboardHTML(dashboardInitials(person.name))}</span>
            <span class="dashboard-list-copy"><strong>${escapeDashboardHTML(person.name)}</strong><small>Staff</small></span>
            <span class="dashboard-list-meta">${escapeDashboardHTML(status)}</span>
        </li>`;
    }).join('');
    const present = Number(staff.present_staff) || 0;
    const total = Number(staff.total_staff) || 0;
    const away = Math.max(0, total - present);
    return `<section class="dashboard-data-panel dashboard-panel-two-thirds" aria-labelledby="staffTitle">
        <div class="dashboard-data-panel-header">
            <div><h2 class="dashboard-data-panel-title" id="staffTitle"><i class="fa-solid fa-people-group"></i> Staff Snapshot</h2><div class="dashboard-data-panel-subtitle">Attendance today</div></div>
            <a class="dashboard-panel-link" href="users_attendance.php">Mark attendance</a>
        </div>
        <div class="dashboard-staff-summary">
            <div class="dashboard-mini-stat"><strong>${formatNumber(total)}</strong><span>Total staff</span></div>
            <div class="dashboard-mini-stat"><strong>${formatNumber(present)}</strong><span>Present</span></div>
            <div class="dashboard-mini-stat"><strong>${formatNumber(away)}</strong><span>Away / unmarked</span></div>
        </div>
        ${staffRows ? `<ul class="dashboard-list dashboard-staff-list">${staffRows}</ul>` : '<div class="dashboard-empty-state">No active staff records found.</div>'}
    </section>`;
}

function getSetupStatusPanel() {
    const setup = phpData.setupStatus || {};
    const rows = Object.keys(setup).map(function(label) {
        const complete = Boolean(setup[label]);
        return `<div class="dashboard-setup-row${complete ? '' : ' pending'}">
            <i class="fa-solid ${complete ? 'fa-circle-check' : 'fa-circle-exclamation'}"></i>
            <strong>${escapeDashboardHTML(label)}</strong>
            <span>${complete ? 'Done' : 'Needs setup'}</span>
        </div>`;
    }).join('');
    return `<section class="dashboard-data-panel dashboard-panel-third" aria-labelledby="setupTitle">
        <div class="dashboard-data-panel-header">
            <div><h2 class="dashboard-data-panel-title" id="setupTitle"><i class="fa-solid fa-list-check"></i> Setup Status</h2><div class="dashboard-data-panel-subtitle">School configuration</div></div>
            <a class="dashboard-panel-link" href="sessions.php">Review</a>
        </div>
        <div class="dashboard-setup-list">${rows}</div>
    </section>`;
}

function getOperationalDashboardHTML() {
    return `<div class="dashboard-panels-grid">
        ${getModuleHubHTML()}
        ${getDashboardActivityPanel()}
        ${getAttendancePanel()}
        ${getOverdueFeesPanel()}
        ${getTimetablePanel()}
        ${getEventsExamsPanel()}
        ${getTransportPanel()}
        <section class="dashboard-data-panel dashboard-panel-two-thirds" aria-labelledby="collectionsTitle">
            <div class="dashboard-data-panel-header">
                <div><h2 class="dashboard-data-panel-title" id="collectionsTitle"><i class="fa-solid fa-chart-line"></i> Fee Collection Overview</h2><div class="dashboard-data-panel-subtitle">Daily collections · last 15 days</div></div>
                <a class="dashboard-panel-link" href="fee_collection.php">Collect fee</a>
            </div>
            <div id="dailyCollectionChart" class="dashboard-collection-chart"></div>
        </section>
        <section class="dashboard-data-panel dashboard-panel-third" aria-labelledby="announcementsTitle">
            <div class="dashboard-data-panel-header"><div><h2 class="dashboard-data-panel-title" id="announcementsTitle"><i class="fa-solid fa-bullhorn"></i> Announcements</h2><div class="dashboard-data-panel-subtitle">Latest school notices</div></div></div>
            <div class="dashboard-empty-state"><i class="fa-regular fa-bell"></i>Notices and announcement records are not configured in this installation.</div>
        </section>
        ${getAdmissionsPanel()}
        ${getBirthdaysPanel()}
        <section class="dashboard-data-panel dashboard-panel-third" aria-labelledby="libraryTitle">
            <div class="dashboard-data-panel-header"><div><h2 class="dashboard-data-panel-title" id="libraryTitle"><i class="fa-solid fa-book"></i> Library Today</h2><div class="dashboard-data-panel-subtitle">Library circulation</div></div></div>
            <div class="dashboard-empty-state"><i class="fa-solid fa-book-open"></i>Library circulation records are not configured in this installation.</div>
        </section>
        ${getStaffPanel()}
        ${getSetupStatusPanel()}
    </div>`;
}

function initializeModuleHub() {
    const grid = document.getElementById('moduleGrid');
    const filterContainer = document.getElementById('moduleFilters');
    const search = document.getElementById('moduleSearch');
    const footer = document.getElementById('moduleHubFooter');
    if (!grid || !filterContainer || !search || !footer) return;

    const categories = ['All', 'Finance', 'Academics', 'Students', 'Operations', 'Engagement', 'Admin'];
    let selectedCategory = 'All';

    filterContainer.innerHTML = categories.map(function(category, index) {
        return `<button type="button" class="dashboard-module-filter${index === 0 ? ' active' : ''}" data-module-category="${category}" aria-pressed="${index === 0}">
            ${category}
        </button>`;
    }).join('');

    grid.innerHTML = dashboardModules.length
        ? dashboardModules.map(function(module) {
            const category = getModuleCategory(module);
            const title = escapeDashboardHTML(module.title);
            const url = escapeDashboardHTML(module.url);
            const parent = escapeDashboardHTML(module.parent);
            return `<a class="dashboard-module-card" data-category="${category}" data-search="${escapeDashboardHTML(`${module.title} ${module.parent}`.toLowerCase())}" href="${url}" title="${parent ? `${parent} — ` : ''}${title}">
                <span class="dashboard-module-icon"><i class="fa-solid ${getModuleIcon(module)}"></i></span>
                <span>${title}</span>
            </a>`;
        }).join('')
        : '<div class="dashboard-module-empty">No modules are assigned to this account.</div>';

    function applyModuleFilters() {
        const query = search.value.trim().toLowerCase();
        let visibleCount = 0;
        grid.querySelectorAll('.dashboard-module-card').forEach(function(card) {
            const matchesCategory = selectedCategory === 'All' || card.dataset.category === selectedCategory;
            const matchesSearch = !query || card.dataset.search.includes(query);
            card.hidden = !(matchesCategory && matchesSearch);
            if (!card.hidden) visibleCount += 1;
        });

        const emptyMessage = grid.querySelector('.dashboard-module-empty');
        if (!emptyMessage) {
            const existingMessage = grid.querySelector('[data-module-empty-result]');
            if (visibleCount === 0) {
                if (!existingMessage) {
                    const message = document.createElement('div');
                    message.className = 'dashboard-module-empty';
                    message.dataset.moduleEmptyResult = 'true';
                    message.textContent = 'No modules match your search.';
                    grid.appendChild(message);
                }
            } else if (existingMessage) {
                existingMessage.remove();
            }
        }

        footer.textContent = `${visibleCount} ${visibleCount === 1 ? 'module' : 'modules'} available`;
    }

    filterContainer.querySelectorAll('[data-module-category]').forEach(function(button) {
        button.addEventListener('click', function() {
            selectedCategory = button.dataset.moduleCategory;
            filterContainer.querySelectorAll('[data-module-category]').forEach(function(filterButton) {
                const isSelected = filterButton === button;
                filterButton.classList.toggle('active', isSelected);
                filterButton.setAttribute('aria-pressed', String(isSelected));
            });
            applyModuleFilters();
        });
    });

    search.addEventListener('input', applyModuleFilters);
    applyModuleFilters();
}

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
        initializeModuleHub();
        initializeDailyCollectionChart();
        setTimeout(() => {
            initializeDailyCharts();
            initializeCalendar();
        }, 100);
    } else {
        contentDiv.innerHTML = getMonthlyDashboardHTML();
        initializeModuleHub();
        initializeDailyCollectionChart();
        setTimeout(() => {
            initializeMonthlyCharts();
        }, 100);
    }
}

// Daily Dashboard HTML - Updated with white background cards and names parallel to icons
function getDailyDashboardHTML() {
    return `
        ${getDashboardSummaryCards('daily')}
        ${getOperationalDashboardHTML()}
    `;
}

// Monthly dashboard uses the same module and operations layout as the daily view.
function getMonthlyDashboardHTML() {
    return `
        ${getDashboardSummaryCards('monthly')}
        ${getOperationalDashboardHTML()}
    `;
}

function initializeDailyCollectionChart() {
    var chartElement = document.querySelector('#dailyCollectionChart');
    if (!chartElement) return;

    var chart = new ApexCharts(chartElement, {
        chart: {
            type: 'area',
            height: 270,
            toolbar: { show: false },
            zoom: { enabled: false }
        },
        series: [{
            name: 'Collected',
            data: phpData.dailyCollectionValues
        }],
        xaxis: {
            categories: phpData.dailyCollectionLabels,
            labels: { rotate: -35, trim: true }
        },
        yaxis: {
            labels: {
                formatter: function(value) {
                    return 'Rs. ' + formatNumber(Math.round(value));
                }
            }
        },
        stroke: { curve: 'smooth', width: 3 },
        colors: ['#5865b2'],
        fill: {
            type: 'gradient',
            gradient: { opacityFrom: 0.35, opacityTo: 0.04 }
        },
        dataLabels: { enabled: false },
        tooltip: {
            y: {
                formatter: function(value) {
                    return 'Rs. ' + formatNumber(Number(value).toFixed(2));
                }
            }
        },
        noData: { text: 'No fee collection data for this period' }
    });
    chart.render();
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
        colors: ['#1ba3b8'],
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
        colors: ['#20ad70', '#1ba3b8', '#8c69db', '#ed934d'],
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
        colors: ['#1ba3b8', '#ed934d'],
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
            colors: ['#1ba3b8', '#ed934d', '#8c69db'],
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
            colors: ['#675ce1'],
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
        if (direction < 0) {
            window.calendar.prev();
        } else {
            window.calendar.next();
        }
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

    $('#dashboardUpdateDismiss').on('click', function() {
        $('#dashboardUpdateStrip').slideUp(150);
    });

    $(document).on('click', '[data-activity-filter]', function() {
        var selectedType = $(this).attr('data-activity-filter');
        $('[data-activity-filter]').removeClass('active').attr('aria-pressed', 'false');
        $(this).addClass('active').attr('aria-pressed', 'true');
        $('#recentActivityList .activity-item').each(function() {
            var isVisible = selectedType === 'all' || $(this).attr('data-activity-type') === selectedType;
            $(this).prop('hidden', !isVisible);
        });
    });
    
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