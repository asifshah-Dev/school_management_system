<?php
// Remove the duplicate session_start() since security.php already starts it
header('Content-Type: text/html; charset=utf-8');
require_once('conn_inc.php');
require_once('security.php');

$lang = 'en';
$success_message = '';
$error_message = '';

// Get current session
$current_session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
if ($current_session_id == 0) {
    $session_query = "SELECT id FROM sessions WHERE status = 1 ORDER BY id DESC LIMIT 1";
    $session_result = $conn->query($session_query);
    if ($session_result && $session_result->num_rows > 0) {
        $current_session = $session_result->fetch_assoc();
        $current_session_id = $current_session['id'];
    }
}

// Get classes - FIXED: Handle NULL status values
$classes = [];
$class_query = "SELECT id, title FROM classes WHERE status = 0 ORDER BY title";
$class_result = $conn->query($class_query);

if ($class_result->num_rows == 0) {
    $class_query = "SELECT id, title FROM classes WHERE status IS NULL ORDER BY title";
    $class_result = $conn->query($class_query);
}

if ($class_result->num_rows == 0) {
    $class_query = "SELECT id, title FROM classes ORDER BY title";
    $class_result = $conn->query($class_query);
}

while ($row = $class_result->fetch_assoc()) {
    $classes[] = $row;
}

// Get selected class
$selected_class = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;

// Get time slots - ENSURE 10 PERIODS
$time_slots = [];
$time_slot_query = "SELECT id, start_time, end_time FROM time_slots ORDER BY start_time ASC";
$time_slot_result = $conn->query($time_slot_query);
while ($row = $time_slot_result->fetch_assoc()) {
    $time_slots[] = $row;
}

// If we don't have 10 time slots, create default ones
$default_time_slots = [];
if (count($time_slots) < 10) {
    $default_times = [
        ['08:00:00', '08:50:00'],
        ['08:50:00', '09:40:00'],
        ['09:40:00', '10:30:00'],
        ['10:30:00', '11:20:00'],
        ['11:20:00', '12:10:00'],
        ['12:10:00', '13:00:00'],
        ['13:00:00', '13:50:00'],
        ['13:50:00', '14:40:00'],
        ['14:40:00', '15:30:00'],
        ['15:30:00', '16:20:00']
    ];
    
    for ($i = 0; $i < 10; $i++) {
        if ($i < count($time_slots)) {
            $default_time_slots[] = $time_slots[$i];
        } else {
            $default_time_slots[] = [
                'id' => 1000 + $i,
                'start_time' => $default_times[$i][0],
                'end_time' => $default_times[$i][1]
            ];
        }
    }
    $time_slots = $default_time_slots;
}

// Get subjects
$subjects = [];
$subject_query = "SELECT id, title FROM subjects WHERE status = 0 ORDER BY title";
$subject_result = $conn->query($subject_query);
while ($row = $subject_result->fetch_assoc()) {
    $subjects[] = $row;
}

// Get teachers
$teachers = [];
$teacher_query = "SELECT id, username FROM users WHERE role_id = '3' AND status = 0 ORDER BY username";
$teacher_result = $conn->query($teacher_query);
while ($row = $teacher_result->fetch_assoc()) {
    $teachers[] = $row;
}

// Define days (excluding Sunday)
$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

// Get existing timetable data
$timetable_data = [];
if ($selected_class > 0) {
    $query = "SELECT t.*, ts.start_time, ts.end_time, s.title as subject_name, u.username as teacher_name 
              FROM timetable t 
              LEFT JOIN time_slots ts ON t.time_slot_id = ts.id
              LEFT JOIN subjects s ON t.subject_id = s.id 
              LEFT JOIN users u ON t.teacher_id = u.id 
              WHERE t.class_id = $selected_class";
    
    $result = $conn->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $timetable_data[$row['day_id']][$row['time_slot_id']] = $row;
        }
    }
}

// Handle AJAX update request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_update'])) {
    header('Content-Type: application/json');
    
    $class_id = intval($_POST['class_id']);
    $day_id = intval($_POST['day_id']);
    $time_slot_id = intval($_POST['time_slot_id']);
    $subject_id = !empty($_POST['subject_id']) ? intval($_POST['subject_id']) : null;
    $teacher_id = !empty($_POST['teacher_id']) ? intval($_POST['teacher_id']) : null;
    $room_no = !empty($_POST['room_no']) ? trim($_POST['room_no']) : null;
    $updated_by = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 1;
    
    // Check if record exists (to get ID for updates)
    $existing_id = null;
    $check_query = "SELECT id FROM timetable WHERE class_id = ? AND day_id = ? AND time_slot_id = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("iii", $class_id, $day_id, $time_slot_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $existing = $check_result->fetch_assoc();
        $existing_id = $existing['id'];
    }
    $check_stmt->close();
    
    // =====================================================
    // NEW: STRICT TEACHER AVAILABILITY CONSTRAINT CHECK
    // =====================================================
    if ($teacher_id) {
        if ($existing_id) {
            // Updating - exclude current record from conflict check
            $conflict_query = "SELECT t.id, c.title as class_title 
                              FROM timetable t 
                              LEFT JOIN classes c ON t.class_id = c.id 
                              WHERE t.teacher_id = ? 
                              AND t.day_id = ? 
                              AND t.time_slot_id = ? 
                              AND t.id != ? 
                              LIMIT 1";
            $conflict_stmt = $conn->prepare($conflict_query);
            $conflict_stmt->bind_param("iiii", $teacher_id, $day_id, $time_slot_id, $existing_id);
        } else {
            // Inserting - check all records for conflicts
            $conflict_query = "SELECT t.id, c.title as class_title 
                              FROM timetable t 
                              LEFT JOIN classes c ON t.class_id = c.id 
                              WHERE t.teacher_id = ? 
                              AND t.day_id = ? 
                              AND t.time_slot_id = ? 
                              LIMIT 1";
            $conflict_stmt = $conn->prepare($conflict_query);
            $conflict_stmt->bind_param("iii", $teacher_id, $day_id, $time_slot_id);
        }
        
        $conflict_stmt->execute();
        $conflict_result = $conflict_stmt->get_result();
        
        if ($conflict_result->num_rows > 0) {
            $conflict = $conflict_result->fetch_assoc();
            $conflict_class = $conflict['class_title'] ?? 'another class';
            echo json_encode([
                'success' => false,
                'message' => 'This teacher is already booked for another class during this time.',
                'conflict_class' => $conflict_class,
                'error_type' => 'teacher_conflict'
            ]);
            $conflict_stmt->close();
            exit;
        }
        $conflict_stmt->close();
    }
    // =====================================================
    // END TEACHER AVAILABILITY CHECK
    // =====================================================
    
    // Check if record exists for update/insert decision
    if ($existing_id) {
        // Update existing record
        $update_query = "UPDATE timetable SET subject_id = ?, teacher_id = ?, room_no = ?, updated_by = ? WHERE id = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("iisii", $subject_id, $teacher_id, $room_no, $updated_by, $existing_id);
        $success = $update_stmt->execute();
        $update_stmt->close();
    } else {
        // Insert new record
        $insert_query = "INSERT INTO timetable (class_id, day_id, time_slot_id, subject_id, teacher_id, room_no, updated_by) 
                         VALUES (?, ?, ?, ?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("iiiiisi", $class_id, $day_id, $time_slot_id, $subject_id, $teacher_id, $room_no, $updated_by);
        $success = $insert_stmt->execute();
        $insert_stmt->close();
    }
    
    // Get updated data for response
    $subject_name = '';
    $teacher_name = '';
    
    if ($subject_id) {
        foreach ($subjects as $s) {
            if ($s['id'] == $subject_id) {
                $subject_name = $s['title'];
                break;
            }
        }
    }
    
    if ($teacher_id) {
        foreach ($teachers as $t) {
            if ($t['id'] == $teacher_id) {
                $teacher_name = $t['username'];
                break;
            }
        }
    }
    
    echo json_encode([
        'success' => $success,
        'message' => $success ? 'Updated successfully!' : 'Error updating record',
        'subject_name' => $subject_name,
        'teacher_name' => $teacher_name,
        'room_no' => $room_no
    ]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Create Timetable - Timetable Management</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f0f2f5;
        }
        
        .container {
            max-width: 100%;
            padding: 20px;
        }
        
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .card-header {
            background: white;
            border-bottom: 2px solid #f0f2f5;
            border-radius: 15px 15px 0 0 !important;
            padding: 15px 20px;
            font-weight: 600;
        }
        
        .timetable-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 30px;
            overflow: visible;
        }
        
        .timetable-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            table-layout: fixed;
        }
        
        .timetable-table th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 8px 4px;
            text-align: center;
            border: 1px solid #ddd;
            font-size: 11px;
        }
        
        .timetable-table td {
            border: 1px solid #ddd;
            padding: 6px 4px;
            vertical-align: top;
            background-color: white;
        }
        
        .day-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-weight: bold;
            font-size: 11px;
            text-align: center;
        }
        
        .period-time {
            font-size: 9px;
            color: #fff;
            display: block;
            margin-top: 2px;
            opacity: 0.9;
        }
        
        .form-group-sm {
            margin-bottom: 4px;
        }
        
        .form-control-sm-custom {
            height: 28px;
            padding: 2px 4px;
            font-size: 11px;
            border-radius: 4px;
            border: 1px solid #ddd;
            width: 100%;
        }
        
        .form-control-sm-custom:focus {
            border-color: #667eea;
            outline: none;
            box-shadow: 0 0 0 2px rgba(102,126,234,0.1);
        }
        
        .room-input {
            margin-top: 3px;
        }
        
        .btn-update {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border: none;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            width: 100%;
            margin-top: 4px;
            cursor: pointer;
        }
        
        .btn-update:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 5px rgba(40,167,69,0.4);
            color: white;
        }
        
        .btn-update:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .btn-print {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
            border: none;
            padding: 10px 30px;
            border-radius: 25px;
            font-weight: 600;
            margin-left: 10px;
        }
        
        .btn-print:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(23,162,184,0.4);
            color: white;
        }
        
        .alert-custom {
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            display: none;
            justify-content: center;
            align-items: center;
        }
        
        .loading-spinner {
            background: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }
        
        .badge-time {
            background-color: rgba(255,255,255,0.2);
            color: #fff;
            font-size: 9px;
            padding: 1px 3px;
            border-radius: 3px;
            margin-top: 2px;
            display: inline-block;
        }
        
        .success-indicator {
            color: #28a745;
            font-size: 12px;
            display: none;
            text-align: center;
            margin-top: 2px;
        }
        
        .error-indicator {
            color: #dc3545;
            font-size: 12px;
            display: none;
            text-align: center;
            margin-top: 2px;
        }
        
        /* Remove all scrolling */
        .table-responsive, .table-responsive-fixed {
            overflow: visible !important;
        }
        
        select.form-control-sm-custom {
            cursor: pointer;
        }
        
        /* Compact layout for all 10 periods and 6 days */
        .timetable-table th:first-child,
        .timetable-table td:first-child {
            width: 80px;
        }
        
        .timetable-table th:not(:first-child),
        .timetable-table td:not(:first-child) {
            width: calc((100% - 80px) / 6);
        }
        
        /* Print Styles - Hide browser headers/footers */
        @media print {
            @page {
                margin: 0;
                size: auto;
            }
            
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            
            body {
                background-color: white;
                padding: 0;
                margin: 0;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .no-print {
                display: none !important;
            }
            
            .container {
                max-width: 100%;
                padding: 10px;
                margin: 0;
            }
            
            .timetable-container {
                box-shadow: none;
                padding: 0;
                margin: 0;
            }
            
            /* Show print header */
            .print-header {
                display: block !important;
                text-align: center;
                margin-bottom: 15px;
                padding-bottom: 15px;
                border-bottom: 2px solid #333;
            }
            
            .print-logo {
                max-height: 80px;
                margin-bottom: 10px;
            }
            
            .print-title {
                font-size: 24px;
                font-weight: bold;
                margin: 10px 0;
            }
            
            .print-info {
                font-size: 14px;
                color: #666;
                margin: 5px 0;
            }
            
            /* Hide form elements */
            .form-control-sm-custom,
            .btn-update,
            .success-indicator,
            .error-indicator {
                display: none !important;
            }
            
            /* Show print values */
            .print-value {
                display: block !important;
                font-size: 11px;
                padding: 2px 0;
            }
            
            .print-label {
                font-weight: bold;
                color: #333;
                font-size: 10px;
                display: block;
            }
            
            /* Table styles for print */
            .timetable-table {
                font-size: 10px;
                border: 2px solid #333;
                width: 100%;
                margin-top: 10px;
            }
            
            .timetable-table th {
                background: #333 !important;
                color: white !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                padding: 6px 4px;
                font-size: 10px;
            }
            
            .timetable-table td {
                border: 1px solid #666;
                padding: 4px;
                vertical-align: top;
            }
            
            .day-header {
                background: #333 !important;
                color: white !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                font-size: 10px;
            }
            
            .period-time {
                color: #fff !important;
                font-size: 8px;
            }
            
            .badge-time {
                color: #fff !important;
                background-color: rgba(255,255,255,0.3);
            }
            
            /* Ensure page breaks work */
            tr {
                page-break-inside: avoid;
            }
            
            thead {
                display: table-header-group;
            }
            
            /* Hide empty cells in print */
            .timetable-table td:empty,
            .timetable-table td .print-value:empty,
            .timetable-table td .print-value > div:empty {
                display: none !important;
            }
            
            /* Only show rows with data in print */
            .timetable-table tbody tr {
                display: none;
            }
            
            .timetable-table tbody tr.has-data {
                display: table-row !important;
            }
            
            /* Show all header rows */
            .timetable-table thead tr {
                display: table-row !important;
            }
            
            /* Alternative: hide cells without data */
            .timetable-table tbody tr td {
                display: none;
            }
            
            .timetable-table tbody tr td:first-child,
            .timetable-table tbody tr td.has-data {
                display: table-cell !important;
            }
            
            .timetable-table tbody tr td:first-child {
                background: #333 !important;
                color: white !important;
            }
        }
        
        /* Hide print elements on screen */
        .print-header,
        .print-value {
            display: none;
        }
    </style>
</head>

<body>
    <?php require_once('navbar.php'); ?>
    
    <div class="container">
        <!-- Selection Panel -->
        <div class="card no-print">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4 class="mb-0"><i class="fas fa-edit mr-2"></i>Create/Edit Timetable (10 Periods)</h4>
                <?php if ($selected_class > 0 && count($time_slots) > 0): ?>
                <button type="button" class="btn btn-print" onclick="printTimetable()">
                    <i class="fas fa-print mr-2"></i>Print Timetable
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (count($classes) == 0): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    No classes found. Please add classes to the system first.
                </div>
                <?php endif; ?>
                
                <?php if ($success_message): ?>
                <div class="alert alert-success alert-custom">
                    <i class="fas fa-check-circle mr-2"></i><?php echo $success_message; ?>
                </div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                <div class="alert alert-danger alert-custom">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo $error_message; ?>
                </div>
                <?php endif; ?>
                
                <form method="GET" action="" class="row" id="classSelectForm">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-alt mr-1"></i>Session</label>
                            <select name="session_id" class="form-control" id="session_id">
                                <?php
                                $sessions = $conn->query("SELECT id, title FROM sessions ORDER BY id DESC");
                                while ($session = $sessions->fetch_assoc()):
                                ?>
                                <option value="<?php echo $session['id']; ?>" <?php echo ($session['id'] == $current_session_id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($session['title']); ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label><i class="fas fa-graduation-cap mr-1"></i>Class</label>
                            <select name="class_id" class="form-control" required id="class_id">
                                <option value="">-- Select Class --</option>
                                <?php foreach($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo ($class['id'] == $selected_class) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['title']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-primary btn-block" <?php echo count($classes) == 0 ? 'disabled' : ''; ?>>
                                <i class="fas fa-table mr-2"></i>Load Timetable
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if ($selected_class > 0 && count($time_slots) > 0): ?>
        <!-- Timetable Editor -->
        <div class="timetable-container" id="timetablePrintArea">
            <!-- Print Header (visible only when printing) -->
            <div class="print-header">
                <img src="logo2.png" alt="School Logo" class="print-logo" onerror="this.style.display='none'">
                <div class="print-title">Class Timetable</div>
                <div class="print-info">
                    <strong>Class:</strong> <?php 
                    foreach($classes as $class) {
                        if($class['id'] == $selected_class) {
                            echo htmlspecialchars($class['title']);
                            break;
                        }
                    }
                    ?> | 
                    <strong>Session:</strong> <?php 
                        $session_query = "SELECT title FROM sessions WHERE id = $current_session_id";
                        $session_res = $conn->query($session_query);
                        if($session_res && $session_res->num_rows > 0) {
                            $session_row = $session_res->fetch_assoc();
                            echo htmlspecialchars($session_row['title']);
                        }
                    ?>
                </div>
                <div class="print-info">
                    <strong>Generated on:</strong> <?php echo date('F d, Y'); ?>
                </div>
            </div>
            
            <div class="text-center mb-4 no-print">
                <h4>
                    Timetable Editor - <?php 
                    foreach($classes as $class) {
                        if($class['id'] == $selected_class) {
                            echo htmlspecialchars($class['title']);
                            break;
                        }
                    }
                    ?>
                    <br>
                    <small class="text-muted">Session: <?php 
                        $session_query = "SELECT title FROM sessions WHERE id = $current_session_id";
                        $session_res = $conn->query($session_query);
                        if($session_res && $session_res->num_rows > 0) {
                            $session_row = $session_res->fetch_assoc();
                            echo htmlspecialchars($session_row['title']);
                        }
                    ?></small>
                </h4>
            </div>
            
            <div>
                <table class="timetable-table">
                    <thead>
                        <tr>
                            <th>Periods / Days</th>
                            <?php foreach($days as $index => $day): ?>
                            <th>
                                <?php echo substr($day, 0, 3); ?>
                                <small class="period-time"><?php echo $day; ?></small>
                            </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($time_slots as $slot_index => $slot): 
                            $period_num = $slot_index + 1;
                            $start_time_formatted = date('h:i A', strtotime($slot['start_time']));
                            $end_time_formatted = date('h:i A', strtotime($slot['end_time']));
                            
                            // Check if this period has any data across all days
                            $period_has_data = false;
                            foreach($days as $day_index => $day) {
                                $day_key = $day_index + 1;
                                if (isset($timetable_data[$day_key][$slot['id']])) {
                                    $existing = $timetable_data[$day_key][$slot['id']];
                                    if ($existing && ($existing['subject_id'] || $existing['teacher_id'] || $existing['room_no'])) {
                                        $period_has_data = true;
                                        break;
                                    }
                                }
                            }
                        ?>
                        <tr class="<?php echo $period_has_data ? 'has-data' : ''; ?>">
                            <td class="day-header">
                                <strong>Period <?php echo $period_num; ?></strong>
                                <small class="period-time"><?php echo $start_time_formatted; ?> - <?php echo $end_time_formatted; ?></small>
                            </td>
                            
                            <?php foreach($days as $day_index => $day):
                                $day_key = $day_index + 1;
                                $existing = isset($timetable_data[$day_key][$slot['id']]) ? $timetable_data[$day_key][$slot['id']] : null;
                                $cell_id = "cell_{$day_key}_{$slot['id']}";
                                
                                // Check if this cell has data
                                $cell_has_data = ($existing && ($existing['subject_id'] || $existing['teacher_id'] || $existing['room_no']));
                                
                                // Get selected values for print display
                                $selected_subject = '';
                                $selected_teacher = '';
                                $room_value = '';
                                
                                if ($existing) {
                                    if ($existing['subject_id']) {
                                        foreach ($subjects as $subject) {
                                            if ($subject['id'] == $existing['subject_id']) {
                                                $selected_subject = $subject['title'];
                                                break;
                                            }
                                        }
                                    }
                                    if ($existing['teacher_id']) {
                                        foreach ($teachers as $teacher) {
                                            if ($teacher['id'] == $existing['teacher_id']) {
                                                $selected_teacher = $teacher['username'];
                                                break;
                                            }
                                        }
                                    }
                                    $room_value = $existing['room_no'] ?: '';
                                }
                            ?>
                            <td id="<?php echo $cell_id; ?>" class="<?php echo $cell_has_data ? 'has-data' : ''; ?>">
                                <!-- Screen Form Elements -->
                                <div class="form-group-sm no-print">
                                    <select class="form-control-sm-custom subject-select" 
                                            data-day="<?php echo $day_key; ?>"
                                            data-slot="<?php echo $slot['id']; ?>"
                                            id="subject_<?php echo $day_key; ?>_<?php echo $slot['id']; ?>">
                                        <option value="">-- Subject --</option>
                                        <?php foreach($subjects as $subject): ?>
                                        <option value="<?php echo $subject['id']; ?>" 
                                            <?php echo ($existing && $existing['subject_id'] == $subject['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($subject['title']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group-sm no-print">
                                    <select class="form-control-sm-custom teacher-select" 
                                            data-day="<?php echo $day_key; ?>"
                                            data-slot="<?php echo $slot['id']; ?>"
                                            id="teacher_<?php echo $day_key; ?>_<?php echo $slot['id']; ?>">
                                        <option value="">-- Teacher --</option>
                                        <?php foreach($teachers as $teacher): ?>
                                        <option value="<?php echo $teacher['id']; ?>" 
                                            <?php echo ($existing && $existing['teacher_id'] == $teacher['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($teacher['username']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group-sm room-input no-print">
                                    <input type="text" 
                                           class="form-control-sm-custom room-input-field" 
                                           placeholder="Room"
                                           value="<?php echo $existing ? htmlspecialchars($existing['room_no']) : ''; ?>"
                                           id="room_<?php echo $day_key; ?>_<?php echo $slot['id']; ?>">
                                </div>
                                
                                <button type="button" class="btn-update no-print" 
                                        onclick="updatePeriod(<?php echo $day_key; ?>, <?php echo $slot['id']; ?>)">
                                    <i class="fas fa-save mr-1"></i>Update
                                </button>
                                
                                <div class="success-indicator no-print" id="success_<?php echo $day_key; ?>_<?php echo $slot['id']; ?>">
                                    <i class="fas fa-check-circle"></i> Saved
                                </div>
                                <div class="error-indicator no-print" id="error_<?php echo $day_key; ?>_<?php echo $slot['id']; ?>">
                                    <i class="fas fa-times-circle"></i> Error
                                </div>
                                
                                <!-- Print Values (visible only when printing) -->
                                <div class="print-value">
                                    <?php if ($selected_subject): ?>
                                    <div><strong><?php echo htmlspecialchars($selected_subject); ?></strong></div>
                                    <?php endif; ?>
                                    <?php if ($selected_teacher): ?>
                                    <div style="font-size: 9px; color: #666;"><?php echo htmlspecialchars($selected_teacher); ?></div>
                                    <?php endif; ?>
                                    <?php if ($room_value): ?>
                                    <div style="font-size: 9px; color: #888;">Room: <?php echo htmlspecialchars($room_value); ?></div>
                                    <?php endif; ?>
                                    <?php if (!$selected_subject && !$selected_teacher && !$room_value): ?>
                                    <div style="color: #999; font-style: italic; font-size: 9px;">-</div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="legend no-print mt-3">
                <strong>Instructions:</strong>
                <div class="legend-item">
                    <i class="fas fa-book text-primary"></i> Select subject
                </div>
                <div class="legend-item">
                    <i class="fas fa-chalkboard-teacher text-success"></i> Select teacher
                </div>
                <div class="legend-item">
                    <i class="fas fa-door-open text-info"></i> Enter room
                </div>
                <div class="legend-item">
                    <i class="fas fa-save text-danger"></i> Click Update to save
                </div>
            </div>
        </div>
        
        <?php elseif($selected_class > 0 && count($time_slots) == 0): ?>
        <div class="alert alert-warning text-center">
            <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
            <h4>No time slots found!</h4>
            <p>Please add time slots first before creating timetable.</p>
            <a href="time_slots.php" class="btn btn-primary">Manage Time Slots</a>
        </div>
        <?php elseif($selected_class == 0 && count($classes) > 0): ?>
        <div class="alert alert-info text-center">
            <i class="fas fa-info-circle fa-3x mb-3"></i>
            <h4>Please select a class to create timetable</h4>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="loading-spinner">
            <i class="fas fa-spinner fa-spin fa-3x"></i>
            <p class="mt-2">Updating...</p>
        </div>
    </div>
    
    <script>
        function updatePeriod(dayId, slotId) {
            var subjectId = $('#subject_' + dayId + '_' + slotId).val();
            var teacherId = $('#teacher_' + dayId + '_' + slotId).val();
            var roomNo = $('#room_' + dayId + '_' + slotId).val();
            var classId = <?php echo $selected_class; ?>;
            
            // Hide previous indicators
            $('#success_' + dayId + '_' + slotId).hide();
            $('#error_' + dayId + '_' + slotId).hide();
            
            // Show loading
            $('#loadingOverlay').css('display', 'flex');
            
            $.ajax({
                url: '',
                type: 'POST',
                dataType: 'json',
                data: {
                    ajax_update: 1,
                    class_id: classId,
                    day_id: dayId,
                    time_slot_id: slotId,
                    subject_id: subjectId,
                    teacher_id: teacherId,
                    room_no: roomNo
                },
                success: function(response) {
                    $('#loadingOverlay').hide();
                    
                    if (response.success) {
                        $('#success_' + dayId + '_' + slotId).fadeIn().delay(2000).fadeOut();
                        // Reload page after successful update to refresh print data
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        // Handle teacher conflict error with specific message
                        if (response.error_type === 'teacher_conflict') {
                            var conflictMsg = response.message;
                            if (response.conflict_class) {
                                conflictMsg += ' (Already assigned to: ' + response.conflict_class + ')';
                            }
                            alert(conflictMsg);
                        } else {
                            alert('Error: ' + response.message);
                        }
                        $('#error_' + dayId + '_' + slotId).fadeIn().delay(3000).fadeOut();
                    }
                },
                error: function(xhr, status, error) {
                    $('#loadingOverlay').hide();
                    $('#error_' + dayId + '_' + slotId).fadeIn().delay(3000).fadeOut();
                    alert('Error updating record. Please try again.');
                    console.log('Error:', error);
                }
            });
        }
        
        function printTimetable() {
            window.print();
        }
        
        $(document).ready(function() {
            // Auto-load timetable when class is selected
            $('#class_id').change(function() {
                if ($(this).val()) {
                    $('#classSelectForm').submit();
                }
            });
            
            $('#session_id').change(function() {
                if ($('#class_id').val()) {
                    $('#classSelectForm').submit();
                }
            });
            
            // Client-side warning for potential teacher conflicts (same day/time in current view)
            $('.teacher-select').change(function() {
                var teacherId = $(this).val();
                var day = $(this).data('day');
                var slot = $(this).data('slot');
                
                if (teacherId) {
                    var conflict = false;
                    var conflictCell = '';
                    $('.teacher-select').each(function() {
                        if ($(this).data('day') == day && 
                            $(this).data('slot') != slot && 
                            $(this).val() == teacherId) {
                            conflict = true;
                            conflictCell = $(this).closest('td').attr('id');
                            return false;
                        }
                    });
                    
                    if (conflict) {
                        alert('Warning: This teacher is already assigned to another period on the same day in this class!\n\nThe server will also check for conflicts across ALL classes before saving.');
                    }
                }
            });
        });
    </script>
</body>

</html>
<?php $conn->close(); ?>