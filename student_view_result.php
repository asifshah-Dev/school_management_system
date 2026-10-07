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

// Get all exams taken by the student
$exams_query = "
    SELECT DISTINCT ae.id, ae.exam_type_id, et.title as exam_type, ae.start_date, ae.class_id
    FROM arrange_exam ae
    JOIN exam_types et ON ae.exam_type_id = et.id
    JOIN results r ON ae.id = r.arrange_exam_id
    WHERE r.student_id = ?
    ORDER BY ae.start_date DESC
";
$stmt = $conn->prepare($exams_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$exams_result = $stmt->get_result();
$exams = [];
while ($row = $exams_result->fetch_assoc()) {
    $exams[] = $row;
}
$stmt->close();

// Get results for a specific exam if selected
$selected_exam_id = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : (count($exams) > 0 ? $exams[0]['id'] : 0);
$results = [];
$exam_details = [];
$total_obtained = 0;
$total_max = 0;

if ($selected_exam_id > 0) {
    // Get exam details
    $exam_details_query = "
        SELECT ae.*, et.title as exam_type, c.title as class_title
        FROM arrange_exam ae
        JOIN exam_types et ON ae.exam_type_id = et.id
        JOIN classes c ON ae.class_id = c.id
        WHERE ae.id = ?
    ";
    $stmt = $conn->prepare($exam_details_query);
    $stmt->bind_param("i", $selected_exam_id);
    $stmt->execute();
    $exam_details_result = $stmt->get_result();
    $exam_details = $exam_details_result->fetch_assoc();
    $stmt->close();

    // Get results for the selected exam
    $results_query = "
        SELECT 
            r.*,
            s.title as subject_title,
            s.type as subject_type,
            s.total_marks as subject_total_marks,
            ss.title as sub_subject_title,
            ss.marks as sub_subject_max_marks,
            scs.marks as class_subject_max_marks
        FROM results r
        LEFT JOIN subjects s ON r.subject_id = s.id
        LEFT JOIN sub_subjects ss ON r.sub_subject_id = ss.id
        LEFT JOIN subject_class sc ON s.id = sc.subject_id AND sc.class_id = ?
        LEFT JOIN subject_class_sub scs ON sc.id = scs.subject_class_id AND ss.id = scs.sub_subject_id
        WHERE r.student_id = ? AND r.arrange_exam_id = ?
        ORDER BY s.title, ss.title
    ";
    $stmt = $conn->prepare($results_query);
    $stmt->bind_param("iii", $exam_details['class_id'], $student_id, $selected_exam_id);
    $stmt->execute();
    $results_result = $stmt->get_result();
    
    // Organize results by subject
    $results_by_subject = [];
    while ($row = $results_result->fetch_assoc()) {
        $subject_id = $row['subject_id'];
        
        if (!isset($results_by_subject[$subject_id])) {
            $results_by_subject[$subject_id] = [
                'subject_title' => $row['subject_title'],
                'subject_type' => $row['subject_type'],
                'subject_total_marks' => $row['subject_total_marks'],
                'subjects' => [],
                'total_obtained' => 0,
                'total_max' => 0
            ];
        }
        
        if ($row['sub_subject_id']) {
            $results_by_subject[$subject_id]['subjects'][] = [
                'sub_subject_title' => $row['sub_subject_title'],
                'obtained_marks' => $row['marks'],
                'max_marks' => $row['sub_subject_max_marks'] ?: $row['class_subject_max_marks']
            ];
            $results_by_subject[$subject_id]['total_obtained'] += $row['marks'];
            $results_by_subject[$subject_id]['total_max'] += $row['sub_subject_max_marks'] ?: $row['class_subject_max_marks'];
        } else {
            // This is for subjects without sub-subjects
            $results_by_subject[$subject_id]['total_obtained'] = $row['marks'];
            $results_by_subject[$subject_id]['total_max'] = $row['subject_total_marks'];
        }
        
        $total_obtained += $row['marks'];
        if ($row['sub_subject_id']) {
            $total_max += $row['sub_subject_max_marks'] ?: $row['class_subject_max_marks'];
        } else {
            $total_max += $row['subject_total_marks'];
        }
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Results</title>
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
    
    .card-results::before { background: var(--warning); }
    
    .card-icon {
      font-size: 2.5rem;
      margin-bottom: 15px;
      display: inline-block;
      padding: 15px;
      border-radius: 12px;
    }
    
    .card-results .card-icon { background: rgba(255, 193, 7, 0.1); color: var(--warning); }
    
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
    
    .exam-selector {
      padding: 10px 15px;
      border-radius: 8px;
      border: 1px solid #dee2e6;
      background-color: white;
      font-weight: 500;
    }
    
    .subject-header {
      background-color: #e9ecef;
      font-weight: 600;
    }
    
    .sub-subject-row {
      background-color: #f8f9fa;
    }
    
    .total-row {
      background-color: #e7f4ef;
      font-weight: 600;
    }
    
    .percentage-cell {
      font-weight: 500;
    }
    
    .grade-cell {
      font-weight: 600;
      padding: 3px 8px;
      border-radius: 4px;
      display: inline-block;
    }
    
    .grade-A { background-color: #d4edda; color: #155724; }
    .grade-B { background-color: #cce5ff; color: #004085; }
    .grade-C { background-color: #fff3cd; color: #856404; }
    .grade-D { background-color: #f8d7da; color: #721c24; }
    .grade-F { background-color: #dc3545; color: white; }
    
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
    <li><a href="student_view_results.php" class="active"><i class="fas fa-graduation-cap"></i> <span>Results</span></a></li>
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
        <h3 class="mb-1">Exam Results</h3>
        <p class="mb-0 text-muted">View your academic performance and results</p>
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
  
  <!-- Exam Selector -->
  <?php if (count($exams) > 0): ?>
  <div class="dashboard-card">
    <div class="row align-items-center">
      <div class="col-md-6">
        <h5 class="mb-0"><i class="fas fa-calendar-alt me-2 text-primary"></i>Select Exam</h5>
      </div>
      <div class="col-md-6">
        <form method="GET" action="student_view_results.php" class="d-flex">
          <select name="exam_id" class="form-select exam-selector" onchange="this.form.submit()">
            <?php foreach ($exams as $exam): ?>
            <option value="<?php echo $exam['id']; ?>" <?php echo $exam['id'] == $selected_exam_id ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($exam['exam_type'] . ' - ' . date('M Y', strtotime($exam['start_date']))); ?>
            </option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>
  
  <?php if ($selected_exam_id > 0 && isset($exam_details)): ?>
  <!-- Exam Summary -->
  <div class="row mb-4">
    <div class="col-md-3">
      <div class="summary-card">
        <div class="text-primary">
          <i class="fas fa-check-circle fa-2x mb-2"></i>
          <h6>Obtained Marks</h6>
        </div>
        <div class="summary-value text-primary"><?php echo number_format($total_obtained, 2); ?></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="summary-card">
        <div class="text-info">
          <i class="fas fa-chart-bar fa-2x mb-2"></i>
          <h6>Total Marks</h6>
        </div>
        <div class="summary-value text-info"><?php echo number_format($total_max, 2); ?></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="summary-card">
        <div class="text-success">
          <i class="fas fa-percent fa-2x mb-2"></i>
          <h6>Percentage</h6>
        </div>
        <div class="summary-value text-success">
          <?php echo $total_max > 0 ? number_format(($total_obtained / $total_max) * 100, 2) . '%' : 'N/A'; ?>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="summary-card">
        <div class="text-warning">
          <i class="fas fa-star fa-2x mb-2"></i>
          <h6>Grade</h6>
        </div>
        <div class="summary-value text-warning">
          <?php
          $percentage = $total_max > 0 ? ($total_obtained / $total_max) * 100 : 0;
          if ($percentage >= 90) echo 'A+';
          elseif ($percentage >= 80) echo 'A';
          elseif ($percentage >= 70) echo 'B';
          elseif ($percentage >= 60) echo 'C';
          elseif ($percentage >= 50) echo 'D';
          else echo 'F';
          ?>
        </div>
      </div>
    </div>
  </div>
  
  <!-- Results Table -->
  <div class="dashboard-card">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h4 class="mb-0"><i class="fas fa-file-alt me-2 text-primary"></i><?php echo htmlspecialchars($exam_details['exam_type']); ?> Results</h4>
      <div class="text-muted">
        Date: <?php echo date('F j, Y', strtotime($exam_details['start_date'])); ?>
      </div>
    </div>
    
    <?php if (!empty($results_by_subject)): ?>
      <div class="table-responsive">
        <table class="table table-bordered table-hover">
          <thead class="table-light">
            <tr>
              <th>Subject</th>
              <th>Component</th>
              <th>Obtained Marks</th>
              <th>Max Marks</th>
              <th>Percentage</th>
              <th>Grade</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($results_by_subject as $subject_id => $subject_data): 
              $subject_percentage = $subject_data['total_max'] > 0 ? 
                ($subject_data['total_obtained'] / $subject_data['total_max']) * 100 : 0;
              $subject_grade = '';
              if ($subject_percentage >= 90) $subject_grade = 'A+';
              elseif ($subject_percentage >= 80) $subject_grade = 'A';
              elseif ($subject_percentage >= 70) $subject_grade = 'B';
              elseif ($subject_percentage >= 60) $subject_grade = 'C';
              elseif ($subject_percentage >= 50) $subject_grade = 'D';
              else $subject_grade = 'F';
            ?>
            <tr class="subject-header">
              <td><?php echo htmlspecialchars($subject_data['subject_title']); ?></td>
              <td>Overall</td>
              <td><?php echo number_format($subject_data['total_obtained'], 2); ?></td>
              <td><?php echo number_format($subject_data['total_max'], 2); ?></td>
              <td class="percentage-cell"><?php echo number_format($subject_percentage, 2); ?>%</td>
              <td>
                <span class="grade-cell grade-<?php echo $subject_grade[0]; ?>">
                  <?php echo $subject_grade; ?>
                </span>
              </td>
            </tr>
            
            <?php if (!empty($subject_data['subjects'])): ?>
              <?php foreach ($subject_data['subjects'] as $sub): 
                $sub_percentage = $sub['max_marks'] > 0 ? ($sub['obtained_marks'] / $sub['max_marks']) * 100 : 0;
                $sub_grade = '';
                if ($sub_percentage >= 90) $sub_grade = 'A+';
                elseif ($sub_percentage >= 80) $sub_grade = 'A';
                elseif ($sub_percentage >= 70) $sub_grade = 'B';
                elseif ($sub_percentage >= 60) $sub_grade = 'C';
                elseif ($sub_percentage >= 50) $sub_grade = 'D';
                else $sub_grade = 'F';
              ?>
              <tr class="sub-subject-row">
                <td></td>
                <td><?php echo htmlspecialchars($sub['sub_subject_title']); ?></td>
                <td><?php echo number_format($sub['obtained_marks'], 2); ?></td>
                <td><?php echo number_format($sub['max_marks'], 2); ?></td>
                <td class="percentage-cell"><?php echo number_format($sub_percentage, 2); ?>%</td>
                <td>
                  <span class="grade-cell grade-<?php echo $sub_grade[0]; ?>">
                    <?php echo $sub_grade; ?>
                  </span>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            <?php endforeach; ?>
            
            <!-- Grand Total Row -->
            <tr class="total-row">
              <td colspan="2" class="text-end"><strong>Grand Total</strong></td>
              <td><strong><?php echo number_format($total_obtained, 2); ?></strong></td>
              <td><strong><?php echo number_format($total_max, 2); ?></strong></td>
              <td class="percentage-cell">
                <strong>
                  <?php echo $total_max > 0 ? number_format(($total_obtained / $total_max) * 100, 2) . '%' : 'N/A'; ?>
                </strong>
              </td>
              <td>
                <?php
                $overall_percentage = $total_max > 0 ? ($total_obtained / $total_max) * 100 : 0;
                $overall_grade = '';
                if ($overall_percentage >= 90) $overall_grade = 'A+';
                elseif ($overall_percentage >= 80) $overall_grade = 'A';
                elseif ($overall_percentage >= 70) $overall_grade = 'B';
                elseif ($overall_percentage >= 60) $overall_grade = 'C';
                elseif ($overall_percentage >= 50) $overall_grade = 'D';
                else $overall_grade = 'F';
                ?>
                <span class="grade-cell grade-<?php echo $overall_grade[0]; ?>">
                  <strong><?php echo $overall_grade; ?></strong>
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="text-center py-4">
        <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
        <h5 class="text-muted">No results available for this exam</h5>
        <p class="text-muted">Your results will appear here once they are published.</p>
      </div>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <!-- No Exams Available -->
  <div class="dashboard-card text-center py-5">
    <i class="fas fa-clipboard-list fa-4x text-muted mb-3"></i>
    <h4 class="text-muted">No Exam Results Available</h4>
    <p class="text-muted">Your exam results will appear here once they are published.</p>
  </div>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>