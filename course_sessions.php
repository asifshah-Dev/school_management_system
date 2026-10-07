<?php 
require_once('security.php');

// Language strings (English only now)
$translations = [
    'title' => 'Assign Courses to Sessions',
    'add_title' => 'Add Session-Course Mapping',
    'edit_title' => 'Edit Session-Course Mapping',
    'session_label' => 'Session:',
    'course_label' => 'Course:',
    'submit' => 'Submit',
    'save' => 'Save',
    'reset' => 'Reset',
    'success' => 'Mapping added successfully!',
    'update_success' => 'Mapping updated successfully!',
    'error' => 'Error: ',
    'delete_success' => 'Mapping deleted successfully!',
    'no_records' => 'No mappings found.',
    'sr_no' => '#',
    'actions' => 'Actions',
    'edit' => 'Edit',
    'delete' => 'Delete',
    'cancel' => 'Cancel',
    'empty_error' => 'Session and Course are required!',
    'delete_confirm' => 'Are you sure you want to delete this mapping?',
    'select_option' => '-- Select --',
    'session_period' => 'Session Period',
    'active_sessions' => 'Active Sessions Only'
];

// Create database connection
require_once('conn_inc.php');

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $session_id = intval($_POST['session_id']);
    $course_id = intval($_POST['course_id']);
    
    if (!empty($session_id) && !empty($course_id)) {
        if (isset($_POST['mapping_id']) && !empty($_POST['mapping_id'])) {
            // Update existing mapping
            $mapping_id = intval($_POST['mapping_id']);
            $stmt = $conn->prepare("UPDATE session_courses SET session_id = ?, course_id = ? WHERE id = ?");
            $stmt->bind_param("iii", $session_id, $course_id, $mapping_id);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = $translations['update_success'];
                $_SESSION['message_type'] = "success";
            }
        } else {
            // Check if this mapping already exists
            $check_stmt = $conn->prepare("SELECT id FROM session_courses WHERE session_id = ? AND course_id = ?");
            $check_stmt->bind_param("ii", $session_id, $course_id);
            $check_stmt->execute();
            $check_stmt->store_result();
            
            if ($check_stmt->num_rows > 0) {
                $_SESSION['message'] = "This course is already assigned to this session!";
                $_SESSION['message_type'] = "danger";
            } else {
                // Insert new mapping
                $stmt = $conn->prepare("INSERT INTO session_courses (session_id, course_id) VALUES (?, ?)");
                $stmt->bind_param("ii", $session_id, $course_id);
                
                if ($stmt->execute()) {
                    $_SESSION['message'] = $translations['success'];
                    $_SESSION['message_type'] = "success";
                }
            }
            $check_stmt->close();
        }
        
        if (isset($stmt) && $stmt->error) {
            $_SESSION['message'] = $translations['error'].$stmt->error;
            $_SESSION['message_type'] = "danger";
        }
        
        if (isset($stmt)) $stmt->close();
        header("Location: ".$_SERVER['PHP_SELF']);
        exit();
    } else {
        $_SESSION['message'] = $translations['empty_error'];
        $_SESSION['message_type'] = "danger";
    }
}

// Handle mapping deletion
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    
    $stmt = $conn->prepare("DELETE FROM session_courses WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = $translations['delete_success'];
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = $translations['error'].$stmt->error;
        $_SESSION['message_type'] = "danger";
    }
    
    $stmt->close();
    header("Location: ".$_SERVER['PHP_SELF']);
    exit();
}

// Get data for edit form
$edit_mode = false;
$mapping_data = ['id' => '', 'session_id' => '', 'course_id' => ''];

if (isset($_GET['edit'])) {
    $id = intval($_GET['edit']);
    $result = $conn->query("SELECT * FROM session_courses WHERE id = $id");
    
    if ($result->num_rows > 0) {
        $mapping_data = $result->fetch_assoc();
        $edit_mode = true;
    }
    $result->close();
}

// Get dropdown options
$sessions = [];
$courses = [];

// Fetch active sessions (status = 0)
$session_result = $conn->query("
    SELECT id, title, from_dated, to_dated 
    FROM sessions 
    WHERE status = 0
    ORDER BY from_dated DESC
");
while ($row = $session_result->fetch_assoc()) {
    $sessions[$row['id']] = [
        'title' => $row['title'],
        'period' => date('d M Y', strtotime($row['from_dated'])) . ' - ' . date('d M Y', strtotime($row['to_dated']))
    ];
}

// Fetch active courses (status = 0)
$course_result = $conn->query("SELECT id, title FROM courses WHERE status = 0 ORDER BY title");
while ($row = $course_result->fetch_assoc()) {
    $courses[$row['id']] = $row['title'];
}

// Get data for listing
$result = $conn->query("
    SELECT sc.id, s.title AS session_title, s.from_dated, s.to_dated, c.title AS course_title 
    FROM session_courses sc
    JOIN sessions s ON sc.session_id = s.id
    JOIN courses c ON sc.course_id = c.id
    ORDER BY s.from_dated DESC, c.title ASC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once('meta_inc.php'); ?>
    <style>
        .table th, .table td {
            text-align: left;
        }
        .session-period {
            font-size: 0.9em;
            color: #666;
        }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<!-- Dashboard -->
<div class="container">
    <div class="row">
        <div class="col-md-10 col-md-offset-1">

            <!-- Panel for Add/Edit Mapping -->
            <div class="panel panel-primary">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <?php echo $edit_mode ? $translations['edit_title'] : $translations['add_title']; ?>
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

                    <form method="post" action="">
                        <input type="hidden" name="mapping_id" value="<?php echo $mapping_data['id']; ?>">
                        
                        <div class="form-group">
                            <label for="session_id"><?php echo $translations['session_label']; ?></label>
                            <select class="form-control" id="session_id" name="session_id" required>
                                <option value=""><?php echo $translations['select_option']; ?></option>
                                <?php foreach ($sessions as $id => $session): ?>
                                    <option value="<?php echo $id; ?>" <?php echo ($id == $mapping_data['session_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($session['title']); ?> (<?php echo $session['period']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="course_id"><?php echo $translations['course_label']; ?></label>
                            <select class="form-control" id="course_id" name="course_id" required>
                                <option value=""><?php echo $translations['select_option']; ?></option>
                                <?php foreach ($courses as $id => $title): ?>
                                    <option value="<?php echo $id; ?>" <?php echo ($id == $mapping_data['course_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="text-right">
                            <button type="submit" class="btn btn-success">
                                <span class="glyphicon glyphicon-<?php echo $edit_mode ? 'refresh' : 'plus'; ?>"></span> 
                                <?php echo $edit_mode ? $translations['save'] : $translations['submit']; ?>
                            </button>
                            
                            <?php if ($edit_mode): ?>
                                <a href="session_course_mapping.php" class="btn btn-default">
                                    <span class="glyphicon glyphicon-remove"></span> 
                                    <?php echo $translations['cancel']; ?>
                                </a>
                            <?php else: ?>
                                <button type="reset" class="btn btn-default">
                                    <span class="glyphicon glyphicon-refresh"></span> 
                                    <?php echo $translations['reset']; ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Panel for Mappings List -->
            <div class="panel panel-info">
                <div class="panel-heading">
                    <h3 class="panel-title"><?php echo $translations['title']; ?></h3>
                </div>
                <div class="panel-body">

                    <?php if ($result->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th><?php echo $translations['sr_no']; ?></th>
                                        <th><?php echo $translations['session_label']; ?></th>
                                        <th><?php echo $translations['course_label']; ?></th>
                                        <th><?php echo $translations['session_period']; ?></th>
                                        <th><?php echo $translations['actions']; ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $counter = 1; while ($row = $result->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo $counter++; ?></td>
                                            <td><?php echo htmlspecialchars($row['session_title']); ?></td>
                                            <td><?php echo htmlspecialchars($row['course_title']); ?></td>
                                            <td>
                                                <?php echo date('d M Y', strtotime($row['from_dated'])) . ' - ' . date('d M Y', strtotime($row['to_dated'])); ?>
                                            </td>
                                            <td>
                                                <a href="?edit=<?php echo $row['id']; ?>" class="btn btn-xs btn-warning">
                                                    <span class="glyphicon glyphicon-edit"></span> 
                                                    <?php echo $translations['edit']; ?>
                                                </a>
                                                <a href="?delete=<?php echo $row['id']; ?>" class="btn btn-xs btn-danger" 
                                                   onclick="return confirm('<?php echo $translations['delete_confirm']; ?>')">
                                                    <span class="glyphicon glyphicon-trash"></span> 
                                                    <?php echo $translations['delete']; ?>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info text-center">
                            <?php echo $translations['no_records']; ?>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

        </div>
    </div>
</div>

<script src="js/mobile_menu.js"></script>

</body>
</html>
<?php
// Close database connection at the very end
$conn->close();
?>
