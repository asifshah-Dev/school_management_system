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
 * Handles form submission for adding or updating subject-class assignments
 * @param mysqli $conn Database connection
 * @param array $post $_POST data
 * @return bool Success status
 */
function handleFormSubmission($conn, $post) {
    if (!isset($post['csrf_token']) || $post['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['message'] = "Security error: Invalid CSRF token.";
        $_SESSION['message_type'] = 'danger';
        return false;
    }

    $class_id = intval($post['class_id']);
    $selected_subjects = isset($post['selected_subjects']) ? array_map('intval', $post['selected_subjects']) : [];
    $marks = isset($post['marks']) ? $post['marks'] : []; // Keyed by subject_id
    $sub_subject_ids = isset($post['sub_subject_ids']) ? $post['sub_subject_ids'] : [];
    $sub_subject_marks = isset($post['sub_subject_marks']) ? $post['sub_subject_marks'] : [];
    $edit_class_id = isset($post['edit_class_id']) ? intval($post['edit_class_id']) : 0;

    // Debug: Log POST data
    error_log("handleFormSubmission: class_id=$class_id, edit_class_id=$edit_class_id, selected_subjects=" . json_encode($selected_subjects) . ", marks=" . json_encode($marks), 3, 'errors.log');

    $success = true;

    if ($edit_class_id > 0) {
        // Fetch existing subject_class records for the class
        $stmt = $conn->prepare("SELECT id, subject_id FROM subject_class WHERE class_id = ?");
        $stmt->bind_param("i", $edit_class_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $existing_subject_classes = [];
        while ($row = $result->fetch_assoc()) {
            $existing_subject_classes[$row['subject_id']] = $row['id'];
        }
        $stmt->close();

        // Process each selected subject
        foreach ($selected_subjects as $subject_id) {
            if ($subject_id <= 0) continue;

            // Get subject type
            $stmt = $conn->prepare("SELECT type FROM subjects WHERE id = ?");
            $stmt->bind_param("i", $subject_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $subject = $result->fetch_assoc();
            $type = $subject['type'] ?? 'standalone';
            $stmt->close();

            $subject_marks = ($type === 'standalone' && isset($marks[$subject_id])) ? floatval($marks[$subject_id]) : 0;
            if ($type === 'standalone' && $subject_marks <= 0) {
                error_log("Skipping subject_id $subject_id: Invalid marks ($subject_marks)", 3, 'errors.log');
                $success = false;
                continue;
            }

            if (isset($existing_subject_classes[$subject_id])) {
                // Update existing subject_class record
                $subject_class_id = $existing_subject_classes[$subject_id];
                $stmt = $conn->prepare("UPDATE subject_class SET marks = ? WHERE id = ?");
                $stmt->bind_param("di", $subject_marks, $subject_class_id);
                if (!$stmt->execute()) {
                    error_log("Error updating subject_class for subject_id $subject_id: " . $stmt->error, 3, 'errors.log');
                    $success = false;
                }
                $stmt->close();
            } else {
                // Insert new subject_class record
                $stmt = $conn->prepare("INSERT INTO subject_class (class_id, subject_id, marks) VALUES (?, ?, ?)");
                $stmt->bind_param("iid", $class_id, $subject_id, $subject_marks);
                if ($stmt->execute()) {
                    $subject_class_id = $conn->insert_id;
                } else {
                    error_log("Error adding subject_class for subject_id $subject_id: " . $stmt->error, 3, 'errors.log');
                    $success = false;
                    continue;
                }
                $stmt->close();
            }

            if ($type === 'composite' && isset($sub_subject_ids[$subject_id]) && isset($sub_subject_marks[$subject_id])) {
                // Fetch existing sub-subjects for this subject_class
                $stmt = $conn->prepare("SELECT id, sub_subject_id FROM subject_class_sub WHERE subject_class_id = ?");
                $stmt->bind_param("i", $subject_class_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $existing_sub_subjects = [];
                while ($row = $result->fetch_assoc()) {
                    $existing_sub_subjects[$row['sub_subject_id']] = $row['id'];
                }
                $stmt->close();

                // Process submitted sub-subjects
                foreach ($sub_subject_ids[$subject_id] as $sub_index => $sub_subject_id) {
                    if ($sub_subject_id > 0) {
                        $sub_marks = isset($sub_subject_marks[$subject_id][$sub_index]) ? floatval($sub_subject_marks[$subject_id][$sub_index]) : 0;
                        if ($sub_marks <= 0) {
                            error_log("Skipping sub_subject_id $sub_subject_id: Invalid marks ($sub_marks)", 3, 'errors.log');
                            continue;
                        }

                        if (isset($existing_sub_subjects[$sub_subject_id])) {
                            // Update existing sub-subject
                            $subject_class_sub_id = $existing_sub_subjects[$sub_subject_id];
                            $stmt = $conn->prepare("UPDATE subject_class_sub SET marks = ? WHERE id = ?");
                            $stmt->bind_param("di", $sub_marks, $subject_class_sub_id);
                            if (!$stmt->execute()) {
                                error_log("Error updating sub-subject marks for subject_class_sub_id $subject_class_sub_id: " . $stmt->error, 3, 'errors.log');
                                $success = false;
                            }
                            $stmt->close();
                        } else {
                            // Insert new sub-subject
                            $stmt = $conn->prepare("INSERT INTO subject_class_sub (subject_class_id, sub_subject_id, marks) VALUES (?, ?, ?)");
                            $stmt->bind_param("iid", $subject_class_id, $sub_subject_id, $sub_marks);
                            if (!$stmt->execute()) {
                                error_log("Error inserting sub-subject marks for subject_class_id $subject_class_id: " . $stmt->error, 3, 'errors.log');
                                $success = false;
                            }
                            $stmt->close();
                        }
                    }
                }

                // Delete sub-subjects that are no longer selected
                $submitted_sub_subject_ids = array_filter($sub_subject_ids[$subject_id], function($id) { return $id > 0; });
                foreach ($existing_sub_subjects as $sub_subject_id => $subject_class_sub_id) {
                    if (!in_array($sub_subject_id, $submitted_sub_subject_ids)) {
                        $stmt = $conn->prepare("DELETE FROM subject_class_sub WHERE id = ?");
                        $stmt->bind_param("i", $subject_class_sub_id);
                        if (!$stmt->execute()) {
                            error_log("Error deleting sub-subject ID $subject_class_sub_id: " . $stmt->error, 3, 'errors.log');
                            $success = false;
                        }
                        $stmt->close();
                    }
                }
            } elseif ($type === 'composite') {
                // If composite but no sub-subjects submitted, delete all existing sub-subjects
                $stmt = $conn->prepare("DELETE FROM subject_class_sub WHERE subject_class_id = ?");
                $stmt->bind_param("i", $subject_class_id);
                if (!$stmt->execute()) {
                    error_log("Error deleting sub-subjects for subject_class_id $subject_class_id: " . $stmt->error, 3, 'errors.log');
                    $success = false;
                }
                $stmt->close();
            }
        }

        // Delete subjects that are no longer selected
        foreach ($existing_subject_classes as $subject_id => $subject_class_id) {
            if (!in_array($subject_id, $selected_subjects)) {
                $stmt = $conn->prepare("DELETE FROM subject_class_sub WHERE subject_class_id = ?");
                $stmt->bind_param("i", $subject_class_id);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("DELETE FROM subject_class WHERE id = ?");
                $stmt->bind_param("i", $subject_class_id);
                if (!$stmt->execute()) {
                    error_log("Error deleting subject_class ID $subject_class_id: " . $stmt->error, 3, 'errors.log');
                    $success = false;
                }
                $stmt->close();
            }
        }
    } else {
        // Add mode (no changes needed, as it already inserts new records)
        foreach ($selected_subjects as $subject_id) {
            if ($subject_id <= 0) continue;

            // Get subject type
            $stmt = $conn->prepare("SELECT type FROM subjects WHERE id = ?");
            $stmt->bind_param("i", $subject_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $subject = $result->fetch_assoc();
            $type = $subject['type'] ?? 'standalone';
            $stmt->close();

            // Insert subject_class record
            $subject_marks = ($type === 'standalone' && isset($marks[$subject_id])) ? floatval($marks[$subject_id]) : 0;
            if ($type === 'standalone' && $subject_marks <= 0) {
                error_log("Skipping subject_id $subject_id: Invalid marks ($subject_marks)", 3, 'errors.log');
                $success = false;
                continue;
            }

            $stmt = $conn->prepare("INSERT INTO subject_class (class_id, subject_id, marks) VALUES (?, ?, ?)");
            $stmt->bind_param("iid", $class_id, $subject_id, $subject_marks);
            if ($stmt->execute()) {
                $subject_class_id = $conn->insert_id;
                if ($type === 'composite' && isset($sub_subject_ids[$subject_id]) && isset($sub_subject_marks[$subject_id])) {
                    foreach ($sub_subject_ids[$subject_id] as $sub_index => $sub_subject_id) {
                        if ($sub_subject_id > 0) {
                            $sub_marks = isset($sub_subject_marks[$subject_id][$sub_index]) ? floatval($sub_subject_marks[$subject_id][$sub_index]) : 0;
                            if ($sub_marks <= 0) {
                                error_log("Skipping sub_subject_id $sub_subject_id: Invalid marks ($sub_marks)", 3, 'errors.log');
                                continue;
                            }
                            $stmt_sub = $conn->prepare("INSERT INTO subject_class_sub (subject_class_id, sub_subject_id, marks) VALUES (?, ?, ?)");
                            $stmt_sub->bind_param("iid", $subject_class_id, $sub_subject_id, $sub_marks);
                            if (!$stmt_sub->execute()) {
                                error_log("Error inserting sub-subject marks for subject_class_id $subject_class_id: " . $stmt_sub->error, 3, 'errors.log');
                                $success = false;
                            }
                            $stmt_sub->close();
                        }
                    }
                }
            } else {
                error_log("Error adding subject_class for subject_id $subject_id: " . $stmt->error, 3, 'errors.log');
                $success = false;
            }
            $stmt->close();
        }
    }

    return $success;
}

/**
 * Handles deletion of a subject-class assignment
 * @param mysqli $conn Database connection
 * @param int $id Subject-class ID to delete
 */
function handleDeletion($conn, $id) {
    $stmt = $conn->prepare("DELETE FROM subject_class_sub WHERE subject_class_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM subject_class WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = 'Subject-Class assignment deleted successfully!';
        $_SESSION['message_type'] = 'success';
    } else {
        error_log("Error deleting subject_class ID $id: " . $stmt->error, 3, 'errors.log');
        $_SESSION['message'] = 'Error: Could not delete the assignment.';
        $_SESSION['message_type'] = 'danger';
    }
    $stmt->close();
}

/**
 * Loads data for edit mode
 * @param mysqli $conn Database connection
 * @param int $class_id Class ID to edit
 * @return array Edit class data and existing assignments
 */
function loadEditData($conn, $class_id) {
    $data = ['edit_class_data' => [], 'existing_assignments' => []];
    
    // Fetch class details
    $stmt = $conn->prepare("SELECT id, title FROM classes WHERE id = ?");
    $stmt->bind_param("i", $class_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $data['edit_class_data'] = $result->fetch_assoc();
        if (!$data['edit_class_data']) {
            error_log("No class found for ID: $class_id", 3, 'errors.log');
            $_SESSION['message'] = "Class not found.";
            $_SESSION['message_type'] = 'danger';
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }
    $stmt->close();

    // Fetch existing assignments
    $stmt = $conn->prepare("SELECT id, class_id, subject_id, marks FROM subject_class WHERE class_id = ?");
    $stmt->bind_param("i", $class_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $data['existing_assignments'][$row['subject_id']] = $row;
            $stmt_sub = $conn->prepare("
                SELECT scs.sub_subject_id, ss.title, scs.marks
                FROM subject_class_sub scs
                JOIN sub_subjects ss ON scs.sub_subject_id = ss.id
                WHERE scs.subject_class_id = ?
                ORDER BY ss.title
            ");
            $stmt_sub->bind_param("i", $row['id']);
            if ($stmt_sub->execute()) {
                $sub_result = $stmt_sub->get_result();
                $data['existing_assignments'][$row['subject_id']]['sub_subjects'] = [];
                while ($sub_row = $sub_result->fetch_assoc()) {
                    $data['existing_assignments'][$row['subject_id']]['sub_subjects'][$sub_row['sub_subject_id']] = $sub_row;
                }
            } else {
                error_log("Error fetching sub-subject marks for subject_class_id {$row['id']}: " . $stmt_sub->error, 3, 'errors.log');
            }
            $stmt_sub->close();
        }
    }
    $stmt->close();
    
    return $data;
}

/**
 * Fetches all classes for dropdown
 * @param mysqli $conn Database connection
 * @return array Class ID => title
 */
function getClasses($conn) {
    $classes = [];
    $result = $conn->query("SELECT id, title FROM classes ORDER BY title");
    while ($row = $result->fetch_assoc()) {
        $classes[$row['id']] = $row['title'];
    }
    $result->close();
    return $classes;
}

/**
 * Fetches all subjects
 * @param mysqli $conn Database connection
 * @return array Subject ID => [title, type]
 */
function getSubjects($conn) {
    $subjects = [];
    $result = $conn->query("SELECT id, title, type FROM subjects ORDER BY title");
    while ($row = $result->fetch_assoc()) {
        $subjects[$row['id']] = ['title' => $row['title'], 'type' => $row['type']];
    }
    $result->close();
    return $subjects;
}

/**
 * Fetches all sub-subjects
 * @param mysqli $conn Database connection
 * @return array Subject ID => array of sub-subjects
 */
function getSubSubjects($conn) {
    $sub_subjects = [];
    $result = $conn->query("SELECT id, subject_id, title FROM sub_subjects ORDER BY subject_id, title");
    while ($row = $result->fetch_assoc()) {
        $sub_subjects[$row['subject_id']][] = $row;
    }
    $result->close();
    return $sub_subjects;
}

/**
 * Fetches subject-class assignments for listing
 * @param mysqli $conn Database connection
 * @return array Grouped by class_id
 */
function getSubjectClasses($conn) {
    $subject_classes_by_class = [];
    $class_ids = [];
    $result = $conn->query("
        SELECT sc.id, sc.class_id, sc.subject_id, sc.marks, c.title AS class_title, 
               s.title AS subject_title, s.type
        FROM subject_class sc
        JOIN classes c ON sc.class_id = c.id
        JOIN subjects s ON sc.subject_id = s.id
        ORDER BY c.title, s.title
    ");
    while ($row = $result->fetch_assoc()) {
        $subject_classes_by_class[$row['class_id']]['class_title'] = $row['class_title'];
        $subject_classes_by_class[$row['class_id']]['subjects'][$row['id']] = $row;
        $class_ids[] = $row['class_id'];
    }
    $result->close();

    // Fetch sub-subject marks
    foreach ($subject_classes_by_class as $class_id => &$class_data) {
        foreach ($class_data['subjects'] as &$sc) {
            if ($sc['type'] === 'composite') {
                $stmt = $conn->prepare("
                    SELECT ss.title, scs.marks
                    FROM subject_class_sub scs
                    JOIN sub_subjects ss ON scs.sub_subject_id = ss.id
                    WHERE scs.subject_class_id = ?
                    ORDER BY ss.title
                ");
                $stmt->bind_param("i", $sc['id']);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    $sc['sub_subjects'] = [];
                    while ($row = $result->fetch_assoc()) {
                        $sc['sub_subjects'][] = $row;
                    }
                } else {
                    error_log("Error fetching sub-subject marks for subject_class_id {$sc['id']}: " . $stmt->error, 3, 'errors.log');
                }
                $stmt->close();
            } else {
                $sc['sub_subjects'] = [];
            }
        }
    }
    unset($class_data, $sc);
    return ['subject_classes' => $subject_classes_by_class, 'class_ids' => $class_ids];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (handleFormSubmission($conn, $_POST)) {
        $_SESSION['message'] = isset($_POST['edit_class_id']) && $_POST['edit_class_id'] > 0 ? 
            "Subject-Class assignments updated successfully." : 
            "Subject-Class assignments added successfully.";
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = $_SESSION['message'] ?? "Error: Could not process assignments.";
        $_SESSION['message_type'] = 'danger';
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle deletion
if (isset($_GET['delete'])) {
    handleDeletion($conn, intval($_GET['delete']));
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle edit mode
$edit_mode = false;
$edit_class_data = [];
$existing_assignments = [];
if (isset($_GET['edit_class'])) {
    $edit_mode = true;
    $edit_data = loadEditData($conn, intval($_GET['edit_class']));
    $edit_class_data = $edit_data['edit_class_data'];
    $existing_assignments = $edit_data['existing_assignments'];
}

// Load data
$classes = getClasses($conn);
$subjects = getSubjects($conn);
$sub_subjects = getSubSubjects($conn);
$list_data = getSubjectClasses($conn);
$subject_classes_by_class = $list_data['subject_classes'];
$class_ids = $list_data['class_ids'];

// Define color palette for classes
$colors = [
    '#e6f3ff', // Light Blue
    '#e6ffe6', // Light Green
    '#fff3e6', // Light Orange
    '#ffe6e6', // Light Red
    '#f3e6ff', // Light Purple
    '#e6fff3', // Light Mint
    '#ffffe6', // Light Yellow
    '#f0f0f0'  // Light Gray
];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Subject-Class Management</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
</head>
<body>

<?php require_once('navbar.php'); ?>

<!-- Dashboard -->
<div class="container">
    <div class="row">
        <div class="col-md-12">

            <!-- Panel for Add/Edit Subject-Class -->
            <div class="panel panel-primary">
                <div class="panel-heading">
                    <h3 class="panel-title"><?php echo $edit_mode ? 'Edit Subject-Class Assignments' : 'Add Subject-Class Assignments'; ?></h3>
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

                    <form method="post" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <?php if ($edit_mode): ?>
                            <input type="hidden" name="edit_class_id" value="<?php echo $edit_class_data['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label for="class_id">Select Class:</label>
                            <select name="class_id" id="class_id" class="form-control" required>
                                <option value="">-- Select Class --</option>
                                <?php foreach ($classes as $id => $title): ?>
                                    <option value="<?php echo $id; ?>" <?php echo ($edit_mode && $edit_class_data['id'] == $id) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group" id="subjects_group" style="<?php echo $edit_mode ? '' : 'display: none;'; ?>">
                            <label>Assign Subjects and Marks:</label>
                            <div class="subjects-container" id="subjects_list">
                                <?php if ($edit_mode): ?>
                                    <?php foreach ($subjects as $subject_id => $subject): ?>
                                        <div class="subject-item" id="subject_item_<?php echo $subject_id; ?>">
                                            <div class="form-group">
                                                <label>
                                                    <input type="checkbox" class="subject-checkbox" name="selected_subjects[]" 
                                                           value="<?php echo $subject_id; ?>" 
                                                           <?php echo isset($existing_assignments[$subject_id]) ? 'checked' : ''; ?>>
                                                    <?php echo htmlspecialchars($subject['title']) . ' (' . $subject['type'] . ')'; ?>
                                                </label>
                                                <?php if ($subject['type'] === 'standalone'): ?>
                                                    <input type="number" class="form-control marks-input" name="marks[<?php echo $subject_id; ?>]" 
                                                           value="<?php echo isset($existing_assignments[$subject_id]) ? htmlspecialchars($existing_assignments[$subject_id]['marks']) : ''; ?>" 
                                                           placeholder="Enter Marks" step="0.1" min="0" 
                                                           <?php echo isset($existing_assignments[$subject_id]) ? '' : 'disabled'; ?>>
                                                <?php else: ?>
                                                    <div class="sub-subjects-container">
                                                        <?php if (isset($sub_subjects[$subject_id]) && !empty($sub_subjects[$subject_id])): ?>
                                                            <?php foreach ($sub_subjects[$subject_id] as $index => $sub_subject): ?>
                                                                <div class="sub-subject-item" id="sub_subject_item_<?php echo $subject_id . '_' . $index; ?>">
                                                                    <div class="input-group">
                                                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($sub_subject['title']); ?>" readonly>
                                                                        <input type="hidden" name="sub_subject_ids[<?php echo $subject_id; ?>][]" value="<?php echo $sub_subject['id']; ?>">
                                                                        <input type="number" class="form-control marks-input" name="sub_subject_marks[<?php echo $subject_id; ?>][]" 
                                                                               value="<?php echo isset($existing_assignments[$subject_id]['sub_subjects'][$sub_subject['id']]) ? htmlspecialchars($existing_assignments[$subject_id]['sub_subjects'][$sub_subject['id']]['marks']) : ''; ?>" 
                                                                               placeholder="Enter Marks" step="0.1" min="0" 
                                                                               <?php echo isset($existing_assignments[$subject_id]) ? '' : 'disabled'; ?>>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        <?php else: ?>
                                                            <p class="text-muted">No sub-subjects available for this subject.</p>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="text-right">
                            <button type="submit" class="btn btn-success">
                                <span class="glyphicon glyphicon-<?php echo $edit_mode ? 'refresh' : 'plus'; ?>"></span> 
                                <?php echo $edit_mode ? 'Save Changes' : 'Add Assignments'; ?>
                            </button>
                            <?php if ($edit_mode): ?>
                                <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-default">
                                    <span class="glyphicon glyphicon-remove"></span> Cancel
                                </a>
                            <?php else: ?>
                                <button type="reset" class="btn btn-default">
                                    <span class="glyphicon glyphicon-refresh"></span> Reset
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Panel for Subject-Class List -->
            <div class="panel panel-info">
                <div class="panel-heading">
                    <h3 class="panel-title">Subject-Class Assignments</h3>
                </div>
                <div class="panel-body">
                    <?php if (!empty($subject_classes_by_class)): ?>
                        <!-- Responsive table for listing assignments -->
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th style="width: 5%;">#</th>
                                        <th style="width: 30%;">Class/Subject</th>
                                        <th style="width: 15%;">Type</th>
                                        <th style="width: 15%;">Total Marks</th>
                                        <th style="width: 25%;">Sub-Subjects</th>
                                        <th style="width: 10%;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $counter = 1; ?>
                                    <?php foreach ($subject_classes_by_class as $class_id => $class_data): ?>
                                        <!-- Class header row -->
                                        <tr class="class-header class-<?php echo array_search($class_id, array_unique($class_ids)) % count($colors); ?>">
                                            <td colspan="5">
                                                <span style="font-size:25px;"><?php echo htmlspecialchars($class_data['class_title']); ?></span>
                                                <a href="?edit_class=<?php echo $class_id; ?>" class="btn btn-warning action-btn pull-right">
                                                    <span class="glyphicon glyphicon-edit"></span> Edit
                                                </a>
                                            </td>
                                            <td></td> <!-- Empty cell for actions column alignment -->
                                        </tr>
                                        <?php foreach ($class_data['subjects'] as $sc): ?>
                                            <!-- Subject row -->
                                            <tr class="subject-row">
                                                <td><?php echo $counter++; ?></td>
                                                <td><?php echo htmlspecialchars($sc['subject_title']); ?></td>
                                                <td><?php echo htmlspecialchars($sc['type']); ?></td>
                                                <td>
                                                    <?php 
                                                    if ($sc['type'] === 'standalone') {
                                                        echo htmlspecialchars($sc['marks']);
                                                    } else {
                                                        $total_marks = 0;
                                                        foreach ($sc['sub_subjects'] as $sub) {
                                                            $total_marks += floatval($sub['marks']);
                                                        }
                                                        echo $total_marks > 0 ? htmlspecialchars($total_marks) : 'N/A';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php if ($sc['type'] === 'composite' && !empty($sc['sub_subjects'])): ?>
                                                        <ul class="sub-subjects-list">
                                                            <?php foreach ($sc['sub_subjects'] as $sub): ?>
                                                                <li class="sub-subject-title"><?php echo htmlspecialchars($sub['title']); ?></li>
                                                                <li class="sub-subject-marks">Marks: <?php echo htmlspecialchars($sub['marks']); ?></li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php elseif ($sc['type'] === 'composite'): ?>
                                                        <span class="text-muted">No sub-subjects</span>
                                                    <?php else: ?>
                                                        <span class="text-muted">None</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <a href="?delete=<?php echo $sc['id']; ?>" class="btn btn-xs btn-danger action-btn" 
                                                       onclick="return confirm('Are you sure you want to delete this assignment?')">
                                                        <span class="glyphicon glyphicon-trash"></span> Delete
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info text-center">
                            No subject-class assignments found. Start by adding assignments above.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Auto-dismiss alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);

    // Enable/disable marks inputs based on checkbox
    function toggleMarksInputs(checkbox, subjectId) {
        var subjectItem = $('#subject_item_' + subjectId);
        var inputs = subjectItem.find('input[type="number"]');
        inputs.prop('disabled', !checkbox.checked);
        if (!checkbox.checked) {
            inputs.val('');
        }
    }

    // Populate subjects when class is selected
    function updateSubjects(classId) {
        var subjectsList = $('#subjects_list');
        subjectsList.html('');

        <?php if (!empty($subjects)): ?>
            var subjects = <?php echo json_encode($subjects); ?>;
            var subSubjects = <?php echo json_encode($sub_subjects); ?>;
            var existingAssignments = <?php echo $edit_mode ? json_encode($existing_assignments) : '{}'; ?>;

            $.each(subjects, function(subjectId, subject) {
                var isChecked = existingAssignments[subjectId] ? 'checked' : '';
                var marksValue = existingAssignments[subjectId] ? existingAssignments[subjectId].marks : '';
                var html = '<div class="subject-item" id="subject_item_' + subjectId + '">' +
                    '<div class="form-group">' +
                    '<label>' +
                    '<input type="checkbox" class="subject-checkbox" name="selected_subjects[]" value="' + subjectId + '" ' + isChecked + '>' +
                    ' ' + subject.title + ' (' + subject.type + ')' +
                    '</label>';

                if (subject.type === 'standalone') {
                    html += '<input type="number" class="form-control marks-input" name="marks[' + subjectId + ']" ' +
                            'placeholder="Enter Marks" step="0.1" min="0" value="' + marksValue + '" ' + (isChecked ? '' : 'disabled') + '>';
                } else {
                    html += '<div class="sub-subjects-container">';
                    if (subSubjects[subjectId] && subSubjects[subjectId].length > 0) {
                        $.each(subSubjects[subjectId], function(index, subSubject) {
                            var subMarksValue = existingAssignments[subjectId] && existingAssignments[subjectId].sub_subjects && 
                                                existingAssignments[subjectId].sub_subjects[subSubject.id] ? 
                                                existingAssignments[subjectId].sub_subjects[subSubject.id].marks : '';
                            html += '<div class="sub-subject-item" id="sub_subject_item_' + subjectId + '_' + index + '">' +
                                    '<div class="input-group">' +
                                    '<input type="text" class="form-control" value="' + subSubject.title + '" readonly>' +
                                    '<input type="hidden" name="sub_subject_ids[' + subjectId + '][]" value="' + subSubject.id + '">' +
                                    '<input type="number" class="form-control marks-input" name="sub_subject_marks[' + subjectId + '][]" ' +
                                    'placeholder="Enter Marks" step="0.1" min="0" value="' + subMarksValue + '" ' + (isChecked ? '' : 'disabled') + '>' +
                                    '</div></div>';
                        });
                    } else {
                        html += '<p class="text-muted">No sub-subjects available for this subject.</p>';
                    }
                    html += '</div>';
                }
                html += '</div></div>';
                subjectsList.append(html);
            });

            // Attach checkbox event handlers
            subjectsList.find('.subject-checkbox').change(function() {
                var subjectId = $(this).val();
                toggleMarksInputs(this, subjectId);
            });

            console.log('Loaded subjects for class ID: ' + classId);
        <?php else: ?>
            subjectsList.html('<p class="text-muted">No subjects available.</p>');
            console.log('No subjects available');
        <?php endif; ?>
        $('#subjects_group').show();
    }

    // Initialize form for edit mode
    <?php if ($edit_mode): ?>
        updateSubjects(<?php echo $edit_class_data['id']; ?>);
    <?php else: ?>
        $('#subjects_group').hide();
    <?php endif; ?>

    // Handle class change
    $('#class_id').change(function() {
        var classId = $(this).val();
        if (classId) {
            updateSubjects(classId);
        } else {
            $('#subjects_group').hide();
            $('#subjects_list').html('');
            console.log('No class selected');
        }
    });
});
</script>

</body>
</html>
<?php
// Close database connection
$conn->close();
?>