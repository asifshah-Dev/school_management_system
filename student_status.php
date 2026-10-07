<?php
require_once('security.php');
require_once('conn_inc.php');

// Language fixed to English
$lang = 'en';

// Language strings
$translations = [
    'en' => [
        'title' => 'Manage Student Status',
        'select_class_label' => 'Select Class:',
        'status_label' => 'Set Status:',
        'submit' => 'Update Status',
        'no_records' => 'No students found for this class.',
        'sr_no' => '#',
        'student_name' => 'Student Name',
        'father_name' => 'Father\'s Name',
        'status' => 'Current Status',
        'actions' => 'Select',
        'success' => 'Student status updated successfully!',
        'error' => 'Please select at least one student and a status.',
        'status_active' => 'Active',
        'status_leave' => 'On Leave',
        'status_struckoff' => 'Struck Off'
    ]
];

// Map status IDs to labels
$status_map = [
    0 => $translations[$lang]['status_active'],
    1 => $translations[$lang]['status_leave'],
    2 => $translations[$lang]['status_struckoff']
];
?>

<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <title><?php echo $translations[$lang]['title']; ?></title>
    <!-- Bootstrap 3.3.7 CSS -->
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" rel="stylesheet">
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Bootstrap 3.3.7 JS -->
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
</head>
<body>
    <?php require_once('meta_inc.php'); ?>
    <?php require_once('navbar.php'); ?>

    <!-- Dashboard -->
    <div class="container">
        <div class="row">
            <div class="col-md-12">

                <!-- Panel for Class Selection -->
                <div class="panel panel-primary">
                    <div class="panel-heading">
                        <h3 class="panel-title"><?php echo $translations[$lang]['title']; ?></h3>
                    </div>
                    <div class="panel-body">
                        <!-- Display success or error messages -->
                        <?php if (isset($_GET['success'])): ?>
                            <div class="alert alert-success">
                                <?php echo htmlspecialchars($_GET['success']); ?>
                            </div>
                        <?php elseif (isset($_GET['error'])): ?>
                            <div class="alert alert-danger">
                                <?php echo htmlspecialchars($_GET['error']); ?>
                            </div>
                        <?php endif; ?>

                        <!-- Class Selection Form -->
                        <form method="GET" action="">
                            <div class="form-group">
                                <label for="class_id"><?php echo $translations[$lang]['select_class_label']; ?></label>
                                <select name="class_id" id="class_id" class="form-control" onchange="this.form.submit()">
                                    <option value=""><?php echo $translations[$lang]['select_class_label']; ?></option>
                                    <?php
                                    // Fetch classes
                                    $class_query = "SELECT id, title FROM classes";
                                    $class_result = $conn->query($class_query);
                                    if (!$class_result) {
                                        error_log("Class query failed: " . $conn->error);
                                        echo "<option value=''>Error loading classes</option>";
                                    } else {
                                        while ($class = $class_result->fetch_assoc()) {
                                            $selected = (isset($_GET['class_id']) && $_GET['class_id'] == $class['id']) ? 'selected' : '';
                                            echo "<option value='{$class['id']}' $selected>" . htmlspecialchars($class['title']) . "</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if (isset($_GET['class_id']) && !empty($_GET['class_id'])): ?>
                    <?php $class_id = $_GET['class_id']; ?>
                    <!-- Panel for Student List and Status Form -->
                    <div class="panel panel-info">
                        <div class="panel-heading">
                            <h3 class="panel-title">Students in <?php 
                                $class_query = "SELECT title FROM classes WHERE id = ?";
                                $stmt = $conn->prepare($class_query);
                                $stmt->bind_param("i", $class_id);
                                $stmt->execute();
                                $result = $stmt->get_result();
                                $class = $result->fetch_assoc();
                                echo htmlspecialchars($class['title'] ?? 'Unknown Class');
                                $stmt->close();
                            ?></h3>
                        </div>
                        <div class="panel-body">
                            <form method="POST" action="process_status.php" id="statusForm">
                                <input type="hidden" name="class_id" value="<?php echo htmlspecialchars($class_id); ?>">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover">
                                        <thead>
                                            <tr>
                                                <th><?php echo $translations[$lang]['actions']; ?></th>
                                                <th><?php echo $translations[$lang]['student_name']; ?></th>
                                                <th><?php echo $translations[$lang]['father_name']; ?></th>
                                                <th><?php echo $translations[$lang]['status']; ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            // Fetch students in selected class
                                            $query = "SELECT sr.id, sr.name, sr.father_name, sr.status 
                                                    FROM student_registration sr
                                                    JOIN student_class sc ON sr.id = sc.student_registration_id
                                                    WHERE sc.class_id = ? AND session_id = ?";
                                            $stmt = $conn->prepare($query);
                                            $session_id = 5; // Match previous code
                                            $stmt->bind_param("ii", $class_id, $session_id);
                                            $stmt->execute();
                                            $result = $stmt->get_result();
                                            if ($result->num_rows === 0) {
                                                echo "<tr><td colspan='4'>" . $translations[$lang]['no_records'] . "</td></tr>";
                                                error_log("No students found for class_id: $class_id, session_id: $session_id");
                                            }
                                            while ($student = $result->fetch_assoc()) {
                                                $status_label = isset($status_map[$student['status']]) ? $status_map[$student['status']] : 'Unknown';
                                                echo "<tr>";
                                                echo "<td><input type='checkbox' name='students[]' value='{$student['id']}'></td>";
                                                echo "<td>" . htmlspecialchars($student['name']) . "</td>";
                                                echo "<td>" . htmlspecialchars($student['father_name']) . "</td>";
                                               if($status_label == "Active"){
                                                    echo "<td><label class='label label-success'>Active</label></td>";
                                                }elseif($status_label == "On Leave"){
                                                    echo "<td><label class='label label-warning'>Leave</label></td>";
                                                }elseif($status_label == "Struck Off"){
                                                    echo "<td><label class='label label-danger'>Sturck Off</label></td>";
                                                }
                                                echo "</tr>";
                                            }
                                            $stmt->close();
                                            ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Status Selection -->
                                <div class="form-group">
                                    <label for="status"><?php echo $translations[$lang]['status_label']; ?></label>
                                    <select name="status" id="status" class="form-control" required>
                                        <option value=""><?php echo $translations[$lang]['status_label']; ?></option>
                                        <option value="0"><?php echo $translations[$lang]['status_active']; ?></option>
                                        <option value="1"><?php echo $translations[$lang]['status_leave']; ?></option>
                                        <option value="2"><?php echo $translations[$lang]['status_struckoff']; ?></option>
                                    </select>
                                </div>

                                <div class="text-right">
                                    <button type="submit" class="btn btn-success">
                                        <span class="glyphicon glyphicon-plus"></span> <?php echo $translations[$lang]['submit']; ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
                <?php
                // Close connection after all queries are done
                $conn->close();
                ?>
            </div>
        </div>
    </div>

    <!-- JavaScript for form validation -->
    <script>
        $(document).ready(function() {
            $('#statusForm').on('submit', function(event) {
                var students = $('input[name="students[]"]:checked').length;
                var status = $('#status').val();
                if (students === 0 || !status) {
                    event.preventDefault();
                    alert('<?php echo $translations[$lang]['error']; ?>');
                }
            });
        });
    </script>

    <script src="js/mobile_menu.js"></script>
</body>
</html>