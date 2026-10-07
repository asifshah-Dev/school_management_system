<?php

ob_start();
require_once('security.php');

// Initialize session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Generate CSRF token if not set
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Create database connection
require_once('conn_inc.php');

/**
 * Converts 24-hour time format to 12-hour AM/PM format
 * @param string $time Time in 24-hour format (HH:MM:SS or HH:MM)
 * @return string Time in 12-hour AM/PM format
 */
function formatTime12Hour($time) {
    if (empty($time) || $time == '00:00:00' || $time == '00:00') {
        return '12:00 AM';
    }
    
    // Handle both HH:MM:SS and HH:MM formats
    $time = trim($time);
    
    // Convert to 12-hour format
    $formatted = date("h:i A", strtotime($time));
    
    // Special case for 00:00 which should be 12:00 AM
    if ($formatted == '12:00 AM') {
        return $formatted;
    }
    
    return $formatted;
}

/**
 * Formats date from YYYY-MM-DD to DD-MM-YYYY format
 * @param string $date Date in YYYY-MM-DD format
 * @return string Date in DD-MM-YYYY format
 */
function formatDateDMY($date) {
    if (empty($date) || $date == '0000-00-00') {
        return '';
    }
    
    $date = trim($date);
    
    // Check if date is already in correct format or invalid
    if (strpos($date, '-') === false) {
        return $date;
    }
    
    // Try to convert from YYYY-MM-DD to DD-MM-YYYY
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date; // Return original if conversion fails
    }
    
    return date("d-m-Y", $timestamp);
}

/**
 * Fetches exam and class details
 * @param mysqli $conn Database connection
 * @param int $arrange_exam_id Arrange Exam ID
 * @param int $class_id Class ID
 * @return array Exam and class details
 */
function getExamDetails($conn, $arrange_exam_id, $class_id) {
    $data = ['exam' => [], 'class' => []];
    
    $stmt = $conn->prepare("
        SELECT ae.id, ae.exam_type_id, ae.start_date, et.title AS exam_type_title
        FROM arrange_exam ae
        JOIN exam_types et ON ae.exam_type_id = et.id
        WHERE ae.id = ? AND ae.class_id = ?
    ");
    $stmt->bind_param("ii", $arrange_exam_id, $class_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $data['exam'] = $result->fetch_assoc();
        if (!$data['exam']) {
            error_log("No exam found for arrange_exam_id: $arrange_exam_id, class_id: $class_id", 3, 'errors.log');
            $_SESSION['message'] = "Exam not found.";
            $_SESSION['message_type'] = 'danger';
            header("Location: datesheet_list.php");
            exit();
        }
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT id, title FROM classes WHERE id = ?");
    $stmt->bind_param("i", $class_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $data['class'] = $result->fetch_assoc();
        if (!$data['class']) {
            error_log("No class found for ID: $class_id", 3, 'errors.log');
            $_SESSION['message'] = "Class not found.";
            $_SESSION['message_type'] = 'danger';
            header("Location: datesheet_list.php");
            exit();
        }
    }
    $stmt->close();

    return $data;
}

/**
 * Fetches existing datesheet entries with sub-subjects
 * @param mysqli $conn Database connection
 * @param int $arrange_exam_id Arrange Exam ID
 * @return array Datesheet entries
 */
function getDatesheet($conn, $arrange_exam_id) {
    $datesheet = [];
    $stmt = $conn->prepare("
        SELECT ed.id, ed.subject_id, ed.sub_subject_id, ed.start_date, ed.start_time, ed.end_time, 
               s.title AS subject_title, s.type, ss.title AS sub_subject_title
        FROM exam_datesheet ed
        JOIN subjects s ON ed.subject_id = s.id
        LEFT JOIN sub_subjects ss ON ed.sub_subject_id = ss.id
        WHERE ed.arrange_exam_id = ?
        ORDER BY ed.start_date, s.title, ss.title
    ");
    $stmt->bind_param("i", $arrange_exam_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $subject_id = $row['subject_id'];
        
        // If this subject hasn't been added yet, add it
        if (!isset($datesheet[$subject_id])) {
            $datesheet[$subject_id] = [
                'subject_id' => $row['subject_id'],
                'subject_title' => $row['subject_title'] ?? 'Untitled Subject',
                'type' => $row['type'],
                'sub_subjects' => [],
                'has_sub_subjects' => false
            ];
        }
        
        // If this row has a sub-subject, add it to the parent subject
        if ($row['sub_subject_id']) {
            $datesheet[$subject_id]['has_sub_subjects'] = true;
            $datesheet[$subject_id]['sub_subjects'][] = [
                'id' => $row['id'],
                'sub_subject_id' => $row['sub_subject_id'],
                'sub_subject_title' => $row['sub_subject_title'] ?? 'Untitled Sub-Subject',
                'start_date' => $row['start_date'],
                'start_time' => $row['start_time'],
                'end_time' => $row['end_time']
            ];
        } else {
            // This is the main subject entry (no sub-subject)
            $datesheet[$subject_id]['id'] = $row['id'];
            $datesheet[$subject_id]['start_date'] = $row['start_date'];
            $datesheet[$subject_id]['start_time'] = $row['start_time'];
            $datesheet[$subject_id]['end_time'] = $row['end_time'];
        }
    }
    $stmt->close();
    return $datesheet;
}

/**
 * Formats subject title for display
 * @param array $subject Subject data
 * @return string Formatted title
 */
function formatSubjectTitle($subject) {
    return isset($subject['subject_title']) ? htmlspecialchars($subject['subject_title']) : 'Untitled Subject';
}

// Validate URL parameters
$arrange_exam_id = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : 0;
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$exam_type_title = isset($_GET['exam_type']) ? htmlspecialchars($_GET['exam_type']) : '';

if ($arrange_exam_id <= 0 || $class_id <= 0) {
    error_log("Invalid URL parameters: arrange_exam_id=$arrange_exam_id, class_id=$class_id", 3, 'errors.log');
    $_SESSION['message'] = "Invalid exam or class ID.";
    $_SESSION['message_type'] = 'danger';
    header("Location: datesheet_list.php");
    exit();
}

// Log page access
error_log("Accessing print_datesheet.php with exam_id=$arrange_exam_id, class_id=$class_id, exam_type=$exam_type_title", 3, 'errors.log');

// Load exam and class details
$details = getExamDetails($conn, $arrange_exam_id, $class_id);
$exam = $details['exam'];
$class = $details['class'];

// Load datesheet
$datesheet = getDatesheet($conn, $arrange_exam_id);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Print Datesheet - <?php echo htmlspecialchars($class['title'] . ' - ' . $exam_type_title); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <style>
        .header {
            text-align: center;
            position: relative;
            margin-bottom: 10px;
        }
        .header .logo-text-qr {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .header img.logo {
            width: 70px;
            margin-right: 8px;
        }
        .header .board-text {
            font-size: 18px;
            font-weight: bold;
            color: #000;
            text-align: left;
            line-height: 1.1;
            flex-grow: 1;
        }
        @media print {
            body {
                margin: 0;
                font-family: Arial, sans-serif;
                font-size: 12pt;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .container {
                width: 100%;
                max-width: 210mm; /* A4 width */
                margin: 0 auto;
                padding: 10mm;
            }
            .exam-title {
                font-size: 16pt;
                font-weight: bold;
                text-align: center;
                margin-bottom: 20mm;
                color: #000;
            }
            .table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 0;
            }
            .table th, .table td {
                border: 1px solid #000 !important;
                padding: 8px !important;
                text-align: left;
                vertical-align: middle;
            }
            .subject-row {
                background-color: #fff;
            }
            .sub-subject-row {
                background-color: #f5f5f5;
            }
           
            .no-print {
                display: none !important;
            }
            /* Ensure all cells are visible */
            td, th {
                visibility: visible !important;
                display: table-cell !important;
            }
            /* Force visibility of all table content */
            .table td:nth-child(1),
            .table td:nth-child(2),
            .table td:nth-child(3),
            .table td:nth-child(4),
            .table td:nth-child(5) {
                display: table-cell !important;
                visibility: visible !important;
                opacity: 1 !important;
            }
            /* Exam rules styling */
            .exam-rules {
                margin-top: 20mm;
                font-size: 11pt;
                line-height: 1.4;
            }
            .exam-rules h4 {
                font-size: 12pt;
                font-weight: bold;
                margin-bottom: 5mm;
                text-align: center;
                text-decoration: underline;
            }
            .exam-rules ul {
                margin-left: 5mm;
                padding-left: 5mm;
            }
            .exam-rules li {
                margin-bottom: 2mm;
            }
            .signature-section {
                margin-top: 15mm;
                border-top: 1px solid #000;
                padding-top: 5mm;
            }
            .signature-line {
                width: 200px;
                border-bottom: 1px solid #000;
                margin: 0 auto;
                margin-bottom: 2mm;
            }
        }
        @media screen {
            .table th, .table td {
                text-align: left;
                vertical-align: middle;
                padding: 12px;
            }
            .subject-row {
                background-color: #f8fbff;
                transition: background-color 0.2s;
            }
            .subject-row:hover {
                background-color: #e6f3ff;
            }
            .sub-subject-row {
                background-color: #f0f8ff;
            }
            .sub-subject-row:hover {
                background-color: #e0f0ff;
            }
            .subject-title {
                font-weight: bold;
            }
            .exam-title {
                font-size: 24px;
                font-weight: 600;
                margin-bottom: 20px;
                color: #337ab7;
            }
            .alert {
                margin-bottom: 15px;
            }
            /* Exam rules styling for screen */
            .exam-rules {
                margin-top: 30px;
                padding: 20px;
                background-color: #f9f9f9;
                border: 1px solid #ddd;
                border-radius: 5px;
            }
            .exam-rules h4 {
                color: #337ab7;
                margin-bottom: 15px;
            }
            .exam-rules ul {
                margin-left: 20px;
            }
            .exam-rules li {
                margin-bottom: 8px;
            }
            .signature-section {
                margin-top: 30px;
                border-top: 2px solid #337ab7;
                padding-top: 20px;
            }
        }
        /* Fix for table layout */
        .table {
            table-layout: fixed;
        }
        .table td, .table th {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        /* Ensure text is visible */
        td, th {
            color: #000 !important;
        }
        /* Exam rules container */
        .exam-rules-container {
            margin-top: 40px;
        }
    </style>
</head>
<body onload="window.print()">

<div class="container">
    <div class="row">
        <div class="col-md-12">
            <div class="header">
                <div class="logo-text-qr">
                    <img src="logo2.png" alt="BISE Logo" class="logo">
                    <div class="board-text">
                        DAR-E-ARQAM SCHOOL AND COLLEGE<br>MATTA, SWAT<br>Khyber Pakhtunkhwa (Pakistan)
                    </div>
                </div>
            </div>
            <h3 class="exam-title">
                Datesheet for <?php echo htmlspecialchars($class['title']); ?> - <?php echo htmlspecialchars($exam_type_title); ?>
            </h3>

            <?php if (!empty($datesheet)): ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover">
                        <thead>
                            <tr>
                                <th style="width: 10%;">S.No</th>
                                <th style="width: 30%;">Subjects</th>
                                <th style="width: 20%;">Start Date</th>
                                <th style="width: 20%;">Start Time</th>
                                <th style="width: 20%;">End Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $counter = 1; 
                            foreach ($datesheet as $subject_id => $ds): 
                                $hasSubSubjects = !empty($ds['sub_subjects']) && $ds['has_sub_subjects'];
                            ?>
                                <?php if ($hasSubSubjects): ?>
                                    <!-- Don't show parent subject row - only show sub-subjects -->
                                    <?php foreach ($ds['sub_subjects'] as $sub_ds): ?>
                                        <tr class="sub-subject-row">
                                            <td><?php echo $counter++; ?></td>
                                            <td><?php echo htmlspecialchars($sub_ds['sub_subject_title'] ?? 'Untitled Sub-Subject'); ?></td>
                                            <td><?php echo htmlspecialchars(formatDateDMY($sub_ds['start_date'] ?? '')); ?></td>
                                            <td><?php echo htmlspecialchars(formatTime12Hour($sub_ds['start_time'] ?? '')); ?></td>
                                            <td><?php echo htmlspecialchars(formatTime12Hour($sub_ds['end_time'] ?? '')); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <!-- For subjects without sub-subjects, show the subject with its date/time -->
                                    <tr class="subject-row">
                                        <td><?php echo $counter++; ?></td>
                                        <td class="subject-title"><?php echo formatSubjectTitle($ds); ?></td>
                                        <td><?php echo htmlspecialchars(formatDateDMY($ds['start_date'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars(formatTime12Hour($ds['start_time'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars(formatTime12Hour($ds['end_time'] ?? '')); ?></td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-info text-center">
                    No datesheet entries found.
                </div>
            <?php endif; ?>

            <!-- Exam Rules Section - Added at the bottom -->
            <div class="exam-rules-container">
                <div class="exam-rules">
                    <h4>EXAMINATION RULES & INSTRUCTIONS</h4>
                    <ul>
                        <li>Students must arrive at the examination hall 15 minutes before the scheduled start time.</li>
                        <li>No student will be allowed to enter the examination hall 30 minutes after the exam has started.</li>
                        <li>Students must bring their school ID card and admit card to the examination hall.</li>
                        <li>All electronic devices (mobile phones, smart watches, calculators etc.) are strictly prohibited.</li>
                        <li>Use of unfair means will result in strict disciplinary action including cancellation of paper.</li>
                        <li>Students must write their roll numbers clearly on the answer sheet.</li>
                        <li>No extra time will be given under any circumstances.</li>
                        <li>Students must leave the examination hall immediately after submitting their answer sheets.</li>
                        <li>Rough work must be done on the answer sheet only, no separate sheets will be provided.</li>
                        <li>Maintain silence in the examination hall at all times.</li>
                    </ul>
                    
                    <div class="signature-section">
                        <div style="text-align: center; margin-bottom: 10px;">
                            <div class="signature-line"></div>
                            <div>Principal/Exam Controller</div>
                            <div>Dar-e-Arqam School & College, Matta Swat</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="text-right no-print">
                <a href="datesheet_list.php" class="btn btn-default">
                    <span class="glyphicon glyphicon-remove"></span> Back to Datesheet List
                </a>
            </div>

        </div>
    </div>
</div>

<script>
    // Auto-dismiss alerts after 5 seconds on screen
    window.onload = function() {
        window.print();
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(function(alert) {
                alert.style.display = 'none';
            });
        }, 5000);
    };
    
    // Ensure print displays all columns properly
    window.onafterprint = function() {
        // Any cleanup if needed
    };
</script>

</body>
</html>
<?php
$conn->close();
?>