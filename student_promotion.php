<?php
require_once 'conn_inc.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['promote_students'])) {
    $current_session_id = $_POST['current_session_id'];
    $current_class_id = $_POST['current_class_id'];
    $new_session_id = $_POST['new_session_id'];
    $new_class_id = $_POST['new_class_id'];
    $promotion_date = $_POST['promotion_date'] ?: date('Y-m-d');
    
    $success_count = 0;
    $error_count = 0;
    $skipped_count = 0;
    $updated_old_records = 0;
    
    // If no students are explicitly selected, get all students from the current class
    if (isset($_POST['student_ids']) && is_array($_POST['student_ids'])) {
        $students_to_promote = $_POST['student_ids'];
    } else {
        // Get all students from current class
        $stmt = $conn->prepare("SELECT id FROM student_class WHERE session_id = ? AND class_id = ? AND status = 0");
        $stmt->bind_param("ii", $current_session_id, $current_class_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $students_to_promote = [];
        while ($row = $result->fetch_assoc()) {
            $students_to_promote[] = $row['id'];
        }
        $stmt->close();
    }
    
    // Start transaction for data consistency
    $conn->begin_transaction();
    
    try {
        foreach ($students_to_promote as $student_class_id) {
            // Get student registration ID from student_class table
            $stmt = $conn->prepare("SELECT student_registration_id FROM student_class WHERE id = ?");
            $stmt->bind_param("i", $student_class_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $student = $result->fetch_assoc();
            
            if ($student) {
                $student_registration_id = $student['student_registration_id'];
                
                // Check if student already exists in new class for the new session
                $checkStmt = $conn->prepare("SELECT id FROM student_class WHERE student_registration_id = ? AND session_id = ? AND class_id = ? AND status = 0");
                $checkStmt->bind_param("iii", $student_registration_id, $new_session_id, $new_class_id);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                
                if ($checkResult->num_rows == 0) {
                    // Insert new record in student_class table for the new session/class
                    $insertStmt = $conn->prepare("INSERT INTO student_class (student_registration_id, session_id, class_id, promotion_date, dated, status) VALUES (?, ?, ?, ?, CURDATE(), 0)");
                    $insertStmt->bind_param("iiis", $student_registration_id, $new_session_id, $new_class_id, $promotion_date);
                    
                    if ($insertStmt->execute()) {
                        // FIX: Update the old record to mark it as promoted (inactive)
                        // You can either set status to 1 (inactive) or add a 'promoted' flag
                        // Here I'm setting status = 1 to mark the old record as inactive
                        $updateStmt = $conn->prepare("UPDATE student_class SET status = 1 WHERE id = ?");
                        $updateStmt->bind_param("i", $student_class_id);
                        
                        if ($updateStmt->execute()) {
                            $success_count++;
                            $updated_old_records++;
                        } else {
                            // If update fails, rollback the insert
                            $insertStmt->close();
                            throw new Exception("Failed to update old record for student class ID: $student_class_id");
                        }
                        $updateStmt->close();
                    } else {
                        $error_count++;
                    }
                    $insertStmt->close();
                } else {
                    $skipped_count++; // Student already in this class
                }
                $checkStmt->close();
            }
            $stmt->close();
        }
        
        // Commit transaction
        $conn->commit();
        
        $message = "Promotion completed successfully! ";
        $message .= "Promoted: $success_count students. ";
        if ($updated_old_records > 0) {
            $message .= "Updated old records: $updated_old_records. ";
        }
        if ($error_count > 0) {
            $message .= "Failed: $error_count. ";
        }
        if ($skipped_count > 0) {
            $message .= "Skipped (already in new class): $skipped_count. ";
        }
        
        $_SESSION['message'] = $message;
        $_SESSION['message_type'] = ($error_count > 0) ? 'warning' : 'success';
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        
        $_SESSION['message'] = "Promotion failed: " . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
    
    // Redirect to avoid form resubmission
    header("Location: student_promotion.php");
    exit();
}

// Get sessions for dropdowns
$sessions_result = $conn->query("SELECT * FROM sessions WHERE status = 0 ORDER BY id DESC");
$sessions = [];
while ($row = $sessions_result->fetch_assoc()) {
    $sessions[] = $row;
}

// Get classes for dropdowns
$classes_query = "SELECT c.*, cs.section_id, s.title as section_title FROM classes c 
                  LEFT JOIN class_sections cs ON c.id = cs.class_id AND cs.status = 0
                  LEFT JOIN sections s ON cs.section_id = s.id
                  WHERE c.status IS NULL ORDER BY c.id";
$classes_result = $conn->query($classes_query);
$classes = [];
while ($row = $classes_result->fetch_assoc()) {
    $classes[] = $row;
}

// Group classes by section for display
$grouped_classes = [];
foreach ($classes as $class) {
    $section_title = $class['section_title'] ? ' - ' . $class['section_title'] : '';
    $grouped_classes[$class['id']] = $class['title'] . $section_title;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <title>Student Promotion - School Management System</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap CSS & JS -->
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
  
  <link rel="stylesheet" href="css/mystyle.css" />
  
  <style>
    .panel-section {
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 15px;
        margin-bottom: 20px;
    }
    .panel-section h4 {
        margin-top: 0;
        color: #337ab7;
        border-bottom: 1px solid #eee;
        padding-bottom: 10px;
    }
    .form-section-box {
        background: #f9f9f9;
        border-left: 4px solid #337ab7;
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 4px;
    }
    .form-section-box h5 {
        margin-top: 0;
        color: #337ab7;
    }
    .student-list-container {
        max-height: 400px;
        overflow-y: auto;
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 10px;
        margin-top: 15px;
    }
    .student-item {
        padding: 10px;
        border-bottom: 1px solid #eee;
        display: flex;
        align-items: center;
        background: #fff;
    }
    .student-item:hover {
        background-color: #f5f5f5;
    }
    .student-item:last-child {
        border-bottom: none;
    }
    .student-info {
        flex-grow: 1;
        margin-left: 10px;
    }
    .promote-btn-container {
        background: #f8f9fa;
        padding: 15px;
        border-top: 1px solid #ddd;
        margin-top: 20px;
        border-radius: 0 0 4px 4px;
    }
    .checkbox-all {
        background: #f0f7ff;
        padding: 10px;
        border-radius: 4px;
        margin-bottom: 10px;
        border: 1px solid #cce5ff;
    }
    .selected-count-badge {
        background: #d4edda;
        color: #155724;
        padding: 3px 8px;
        border-radius: 10px;
        font-weight: bold;
        margin-left: 5px;
    }
    .form-control:focus {
        border-color: #337ab7;
        box-shadow: 0 0 0 0.2rem rgba(51, 122, 183, 0.25);
    }
    .btn-success {
        background-color: #5cb85c;
        border-color: #4cae4c;
    }
    .btn-success:hover {
        background-color: #449d44;
        border-color: #398439;
    }
    .btn-primary {
        background-color: #337ab7;
        border-color: #2e6da4;
    }
    .btn-primary:hover {
        background-color: #286090;
        border-color: #204d74;
    }
    .btn-warning {
        background-color: #f0ad4e;
        border-color: #eea236;
    }
    .btn-warning:hover {
        background-color: #ec971f;
        border-color: #d58512;
    }
    .alert {
        border-radius: 4px;
        margin-bottom: 20px;
    }
    .student-count {
        font-size: 14px;
        color: #666;
        margin-left: 10px;
    }
    .promotion-mode {
        background: #e8f4fd;
        padding: 15px;
        border-radius: 4px;
        margin-bottom: 15px;
        border: 1px solid #b6d9f8;
    }
    .promotion-mode label {
        font-weight: bold;
        color: #337ab7;
        margin-right: 15px;
    }
    .unchecked-student {
        opacity: 0.7;
        background-color: #f9f9f9;
    }
    .date-input-group {
        background: #f0f8ff;
        padding: 10px;
        border-radius: 4px;
        margin: 10px 0;
    }
    .btn-info {
        background-color: #5bc0de;
        border-color: #46b8da;
    }
    .btn-info:hover {
        background-color: #31b0d5;
        border-color: #269abc;
    }
    .promotion-info {
        background: #e7f3e7;
        padding: 10px;
        border-radius: 4px;
        margin: 10px 0;
        border-left: 4px solid #5cb85c;
    }
  </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<!-- Dashboard -->
<div class="container">
  <div class="row">
    <div class="col-md-12">

      <!-- Panel for Student Promotion -->
      <div class="panel panel-primary">
        <div class="panel-heading">
          <h3 class="panel-title">
            <span class="glyphicon glyphicon-arrow-up"></span> Student Promotion
          </h3>
        </div>
        <div class="panel-body">

          <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['message_type']; ?>">
              <?php 
              echo $_SESSION['message']; 
              unset($_SESSION['message'], $_SESSION['message_type']);
              ?>
            </div>
          <?php endif; ?>

          <!-- Promotion Information Box -->
          <div class="promotion-info">
            <h5><span class="glyphicon glyphicon-info-sign"></span> How Promotion Works:</h5>
            <ul style="margin-bottom: 0;">
              <li>Students will be added to the new session/class</li>
              <li>Their old records will be marked as inactive (status = 1)</li>
              <li>This prevents duplicate active records for the same student</li>
              <li>Students can only have one active class record per session</li>
            </ul>
          </div>

          <!-- Main Promotion Form -->
          <form method="GET" action="" id="filterForm">
            <div class="panel-section">
              <h4><span class="glyphicon glyphicon-search"></span> Select Current Class</h4>
              
              <div class="row">
                <div class="col-md-5">
                  <div class="form-group">
                    <label for="current_session_id">Current Session *</label>
                    <select class="form-control" id="current_session_id" name="current_session_id" required>
                      <option value="">Select Session</option>
                      <?php foreach ($sessions as $session): ?>
                        <option value="<?php echo $session['id']; ?>" 
                          <?php echo isset($_GET['current_session_id']) && $_GET['current_session_id'] == $session['id'] ? 'selected' : ''; ?>>
                          <?php echo htmlspecialchars($session['title']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                
                <div class="col-md-5">
                  <div class="form-group">
                    <label for="current_class_id">Current Class *</label>
                    <select class="form-control" id="current_class_id" name="current_class_id" required>
                      <option value="">Select Class</option>
                      <?php foreach ($grouped_classes as $id => $title): ?>
                        <option value="<?php echo $id; ?>"
                          <?php echo isset($_GET['current_class_id']) && $_GET['current_class_id'] == $id ? 'selected' : ''; ?>>
                          <?php echo htmlspecialchars($title); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                
                <div class="col-md-2">
                  <div class="form-group" style="padding-top: 25px;">
                    <button type="submit" class="btn btn-primary btn-block">
                      <span class="glyphicon glyphicon-search"></span> Load Students
                    </button>
                  </div>
                </div>
              </div>
              
              <div class="text-center" style="margin-top: 10px;">
                <button type="button" class="btn btn-default" onclick="resetForm()">
                  <span class="glyphicon glyphicon-refresh"></span> Reset
                </button>
              </div>
            </div>
          </form>

          <?php if (isset($_GET['current_session_id']) && isset($_GET['current_class_id'])): ?>
            <?php
            $current_session_id = $_GET['current_session_id'];
            $current_class_id = $_GET['current_class_id'];
            
            // Fetch students for the selected class and session
            $query = "SELECT sc.id as student_class_id, sr.id as student_id, sr.reg_no, sr.name, 
                      sr.father_name, sr.mobile, c.title as class_name
                      FROM student_class sc
                      INNER JOIN student_registration sr ON sc.student_registration_id = sr.id
                      INNER JOIN classes c ON sc.class_id = c.id
                      WHERE sc.session_id = ? AND sc.class_id = ? AND sc.status = 0
                      ORDER BY sr.id ASC";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param("ii", $current_session_id, $current_class_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $students = [];
            while ($row = $result->fetch_assoc()) {
                $students[] = $row;
            }
            $stmt->close();
            ?>
            
            <!-- Promotion Form -->
            <form method="POST" action="" id="promotionForm">
              <input type="hidden" name="current_session_id" value="<?php echo $current_session_id; ?>">
              <input type="hidden" name="current_class_id" value="<?php echo $current_class_id; ?>">
              
              <div class="panel-section">
                <h4>
                  <span class="glyphicon glyphicon-education"></span> Students in Current Class
                  <span class="badge"><?php echo count($students); ?> students</span>
                </h4>
                
                <?php if (count($students) > 0): ?>
                  <!-- Promotion Mode Selection -->
                  <div class="promotion-mode">
                    <label>Promotion Mode:</label>
                    <div class="form-check form-check-inline">
                      <input class="form-check-input" type="radio" name="promotion_mode" id="mode_all" value="all" checked>
                      <label class="form-check-label" for="mode_all">
                        <span class="glyphicon glyphicon-ok-circle"></span> Promote All Students
                      </label>
                    </div>
                    <div class="form-check form-check-inline">
                      <input class="form-check-input" type="radio" name="promotion_mode" id="mode_selective" value="selective">
                      <label class="form-check-label" for="mode_selective">
                        <span class="glyphicon glyphicon-check"></span> Select Specific Students
                      </label>
                    </div>
                  </div>
                  
                  <div class="checkbox-all" id="selectAllContainer" style="display: none;">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" id="selectAll">
                      <label class="form-check-label" for="selectAll" style="font-weight: bold;">
                        <span class="glyphicon glyphicon-check"></span> Select/Deselect All Students
                      </label>
                    </div>
                  </div>
                  
                  <div class="student-list-container">
                    <?php $counter = 1; foreach ($students as $student): ?>
                      <div class="student-item" id="student_item_<?php echo $student['student_class_id']; ?>">
                        <div class="form-check">
                          <input class="form-check-input student-checkbox" type="checkbox" 
                                 name="student_ids[]" value="<?php echo $student['student_class_id']; ?>"
                                 id="student_<?php echo $student['student_class_id']; ?>" checked>
                        </div>
                        <div class="student-info">
                          <label class="form-check-label" for="student_<?php echo $student['student_class_id']; ?>">
                            <strong><?php echo $counter++; ?>. <?php echo htmlspecialchars($student['name']); ?></strong>
                            <br>
                            <small class="text-muted">
                              <span class="glyphicon glyphicon-user"></span> Father: <?php echo htmlspecialchars($student['father_name']); ?> | 
                              <span class="glyphicon glyphicon-phone"></span> Mobile: <?php echo htmlspecialchars($student['mobile']); ?> | 
                              <span class="glyphicon glyphicon-tag"></span> Reg No: <?php echo htmlspecialchars($student['reg_no']); ?>
                            </small>
                          </label>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                  
                  <!-- Promotion Options -->
                  <div class="form-section-box">
                    <h5><span class="glyphicon glyphicon-arrow-up"></span> Promotion Options</h5>
                    
                    <!-- Promotion Date -->
                    <div class="date-input-group">
                      <div class="form-group">
                        <label for="promotion_date">Promotion Date *</label>
                        <input type="date" class="form-control" id="promotion_date" name="promotion_date" 
                               value="<?php echo date('Y-m-d'); ?>" required>
                        <small class="text-muted">Date when students will be promoted to new class</small>
                      </div>
                    </div>
                    
                    <div class="row">
                      <div class="col-md-6">
                        <div class="form-group">
                          <label for="new_session_id">New Session *</label>
                          <select class="form-control" id="new_session_id" name="new_session_id" required>
                            <option value="">Select Session</option>
                            <?php foreach ($sessions as $session): ?>
                              <option value="<?php echo $session['id']; ?>">
                                <?php echo htmlspecialchars($session['title']); ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                      </div>
                      
                      <div class="col-md-6">
                        <div class="form-group">
                          <label for="new_class_id">New Class *</label>
                          <select class="form-control" id="new_class_id" name="new_class_id" required>
                            <option value="">Select Class</option>
                            <?php foreach ($grouped_classes as $id => $title): ?>
                              <option value="<?php echo $id; ?>">
                                <?php echo htmlspecialchars($title); ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                      </div>
                    </div>
                    
                    <div class="promote-btn-container">
                      <div class="row">
                        <div class="col-md-4">
                          <div class="form-group">
                            <button type="button" class="btn btn-warning btn-block" onclick="validatePromotion()">
                              <span class="glyphicon glyphicon-check"></span> Validate Promotion
                            </button>
                          </div>
                        </div>
                        
                        <div class="col-md-4">
                          <div class="form-group">
                            <button type="button" class="btn btn-info btn-block" onclick="showSummary()">
                              <span class="glyphicon glyphicon-list-alt"></span> Show Summary
                            </button>
                          </div>
                        </div>
                        
                        <div class="col-md-4">
                          <div class="form-group">
                            <button type="submit" name="promote_students" class="btn btn-success btn-block" id="promoteBtn">
                              <span class="glyphicon glyphicon-arrow-up"></span> Promote Students
                              <span id="selectedCount" class="selected-count-badge"><?php echo count($students); ?></span>
                            </button>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                  
                <?php else: ?>
                  <div class="alert alert-warning text-center">
                    <span class="glyphicon glyphicon-warning-sign"></span> No students found in the selected class for this session.
                  </div>
                <?php endif; ?>
              </div>
            </form>
          <?php endif; ?>

        </div>
        
        <?php if (isset($_GET['current_session_id']) && isset($_GET['current_class_id']) && isset($students) && count($students) > 0): ?>
        <div class="panel-footer">
          <div class="text-muted">
            <small>
              <span class="glyphicon glyphicon-info-sign"></span> 
              <strong>Note:</strong> When students are promoted, their old records will be marked as inactive (status = 1) to prevent duplicates.<br>
              <strong>Promote All:</strong> All students will be promoted by default.<br>
              <strong>Selective Mode:</strong> Uncheck students you want to leave in the current class.<br>
              Students left in old class will not be promoted to the new session/class.
            </small>
          </div>
        </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>

<script src="js/mobile_menu.js"></script>

<script>
// Promotion mode handling
document.addEventListener('DOMContentLoaded', function() {
    const modeAll = document.getElementById('mode_all');
    const modeSelective = document.getElementById('mode_selective');
    const selectAllContainer = document.getElementById('selectAllContainer');
    const studentCheckboxes = document.querySelectorAll('.student-checkbox');
    const studentItems = document.querySelectorAll('.student-item');
    
    // Initialize - all checkboxes checked by default
    updateSelectedCount();
    
    function updatePromotionMode() {
        if (modeAll.checked) {
            // Promote all mode
            selectAllContainer.style.display = 'none';
            studentCheckboxes.forEach(checkbox => {
                checkbox.checked = true;
                checkbox.disabled = true;
            });
            studentItems.forEach(item => {
                item.classList.remove('unchecked-student');
            });
        } else {
            // Selective mode
            selectAllContainer.style.display = 'block';
            studentCheckboxes.forEach(checkbox => {
                checkbox.disabled = false;
            });
            updateCheckboxStyles();
        }
        updateSelectedCount();
    }
    
    function updateCheckboxStyles() {
        studentCheckboxes.forEach((checkbox, index) => {
            const studentItem = studentItems[index];
            if (checkbox.checked) {
                studentItem.classList.remove('unchecked-student');
            } else {
                studentItem.classList.add('unchecked-student');
            }
        });
    }
    
    // Add event listeners for promotion mode
    modeAll.addEventListener('change', updatePromotionMode);
    modeSelective.addEventListener('change', updatePromotionMode);
    
    // Add event listeners to checkboxes
    studentCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updateCheckboxStyles();
            updateSelectedCount();
        });
    });
    
    // Initialize
    updatePromotionMode();
});

// Select all functionality
document.getElementById('selectAll')?.addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.student-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = this.checked;
    });
    updateCheckboxStyles();
    updateSelectedCount();
});

// Update selected count
function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.student-checkbox');
    let selectedCount = 0;
    
    checkboxes.forEach(checkbox => {
        if (checkbox.checked) selectedCount++;
    });
    
    const badge = document.getElementById('selectedCount');
    if (badge) {
        badge.textContent = selectedCount;
    }
}

// Validate promotion
function validatePromotion() {
    const newSessionId = document.getElementById('new_session_id').value;
    const newClassId = document.getElementById('new_class_id').value;
    const promotionDate = document.getElementById('promotion_date').value;
    
    if (!newSessionId || !newClassId || !promotionDate) {
        alert('Please fill all required fields: New Session, New Class, and Promotion Date.');
        return;
    }
    
    const selectedCount = parseInt(document.getElementById('selectedCount').textContent);
    const totalStudents = <?php echo isset($students) ? count($students) : 0; ?>;
    const currentSessionId = <?php echo isset($current_session_id) ? $current_session_id : 'null'; ?>;
    const currentClassId = <?php echo isset($current_class_id) ? $current_class_id : 'null'; ?>;
    
    if (selectedCount === 0) {
        alert('No students selected for promotion. Please select at least one student.');
        return;
    }
    
    let message = `Promotion Validation:\n\n`;
    message += `Total Students in Current Class: ${totalStudents}\n`;
    message += `Students to Promote: ${selectedCount}\n`;
    message += `Students to Leave: ${totalStudents - selectedCount}\n\n`;
    message += `From: Session ${currentSessionId}, Class ${currentClassId}\n`;
    message += `To: Session ${newSessionId}, Class ${newClassId}\n`;
    message += `Promotion Date: ${promotionDate}\n\n`;
    
    message += `Action:\n`;
    message += `✅ ${selectedCount} new records will be created in the new class\n`;
    message += `✅ ${selectedCount} old records will be marked as inactive\n`;
    
    if (selectedCount < totalStudents) {
        message += `⚠️ ${totalStudents - selectedCount} students will remain in the current class\n`;
    }
    
    if (newSessionId == currentSessionId && newClassId == currentClassId) {
        message += '\n⚠️ WARNING: Same session and class selected!';
    }
    
    alert(message);
}

// Show summary
function showSummary() {
    const checkboxes = document.querySelectorAll('.student-checkbox');
    const totalStudents = <?php echo isset($students) ? count($students) : 0; ?>;
    let selectedCount = 0;
    let notSelectedNames = [];
    
    checkboxes.forEach((checkbox, index) => {
        const studentName = document.querySelector(`label[for="${checkbox.id}"] strong`).textContent.split('. ')[1];
        if (checkbox.checked) {
            selectedCount++;
        } else {
            notSelectedNames.push(studentName);
        }
    });
    
    let message = `Promotion Summary:\n\n`;
    message += `Total Students: ${totalStudents}\n`;
    message += `Students to Promote: ${selectedCount}\n`;
    message += `Students to Leave: ${totalStudents - selectedCount}\n\n`;
    
    if (notSelectedNames.length > 0) {
        message += `Students NOT being promoted (will stay in current class):\n`;
        notSelectedNames.forEach((name, index) => {
            message += `${index + 1}. ${name}\n`;
        });
    } else {
        message += `All students will be promoted.`;
    }
    
    alert(message);
}

// Form submission confirmation
document.getElementById('promotionForm')?.addEventListener('submit', function(e) {
    const selectedCount = parseInt(document.getElementById('selectedCount').textContent);
    const totalStudents = <?php echo isset($students) ? count($students) : 0; ?>;
    const newSessionId = document.getElementById('new_session_id').value;
    const newClassId = document.getElementById('new_class_id').value;
    const promotionDate = document.getElementById('promotion_date').value;
    
    if (selectedCount === 0) {
        e.preventDefault();
        alert('No students selected for promotion. Please select at least one student.');
        return;
    }
    
    if (!newSessionId || !newClassId || !promotionDate) {
        e.preventDefault();
        alert('Please fill all required fields: New Session, New Class, and Promotion Date.');
        return;
    }
    
    const currentSessionId = <?php echo isset($current_session_id) ? $current_session_id : 'null'; ?>;
    const currentClassId = <?php echo isset($current_class_id) ? $current_class_id : 'null'; ?>;
    
    let message = `CONFIRM STUDENT PROMOTION\n\n`;
    message += `Total Students: ${totalStudents}\n`;
    message += `Students to Promote: ${selectedCount}\n`;
    
    if (selectedCount < totalStudents) {
        message += `Students to Leave: ${totalStudents - selectedCount}\n\n`;
    }
    
    message += `From: Session ${currentSessionId}, Class ${currentClassId}\n`;
    message += `To: Session ${newSessionId}, Class ${newClassId}\n`;
    message += `Promotion Date: ${promotionDate}\n\n`;
    
    message += `ACTIONS THAT WILL BE PERFORMED:\n`;
    message += `1. ${selectedCount} new records will be created in the new class\n`;
    message += `2. ${selectedCount} old records will be marked as inactive\n`;
    
    if (newSessionId == currentSessionId && newClassId == currentClassId) {
        message += '\n⚠️ WARNING: Same session and class selected!\n';
    }
    
    message += '\nThis action cannot be undone. Continue?';
    
    if (!confirm(message)) {
        e.preventDefault();
    }
});

// Reset form
function resetForm() {
    window.location.href = 'student_promotion.php';
}

// Initialize date picker
document.addEventListener('DOMContentLoaded', function() {
    // Set min date to today
    const today = new Date().toISOString().split('T')[0];
    const promotionDateField = document.getElementById('promotion_date');
    if (promotionDateField) {
        promotionDateField.min = today;
    }
});
</script>

</body>
</html>
<?php
// Close database connection
if (isset($conn)) {
    $conn->close();
}