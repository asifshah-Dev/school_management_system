<?php
ob_start();
require_once('security.php');
require_once('conn_inc.php');

// Language fixed to English (based on provided code)
$lang = 'en';

// Language strings (adapted for transfer functionality)
$translations = [
    'en' => [
        'title' => 'Student Class Transfer',
        'select_class_label' => 'Select Class:',
        'transfer_to_label' => 'Transfer to Class:',
        'submit' => 'Transfer Selected Students',
        'no_records' => 'No students found for this class.',
        'sr_no' => '#',
        'student_name' => 'Student Name',
        'father_name' => 'Father\'s Name',
        'actions' => 'Select',
        'success' => 'Students transferred successfully!',
        'error' => 'Please select at least one student and target class.'
    ]
];
?>

<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <title><?php echo $translations[$lang]['title']; ?></title>
   
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
                    <!-- Panel for Student List and Transfer Form -->
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
                            <form method="POST" action="transfer.php" id="transferForm">
                                <input type="hidden" name="class_id" value="<?php echo htmlspecialchars($class_id); ?>">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover">
                                        <thead>
                                            <tr>
                                                <th>#</th>

                                                <th><?php echo $translations[$lang]['actions']; ?></th>
                                                <th><?php echo $translations[$lang]['student_name']; ?></th>
                                                <th><?php echo $translations[$lang]['father_name']; ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            // Fetch students in selected class
                                            $query = "SELECT sr.id, sr.name, sr.father_name 
                                                    FROM student_registration sr
                                                    JOIN student_class sc ON sr.id = sc.student_registration_id
                                                    WHERE sc.class_id = ? AND session_id = ?";
                                            $stmt = $conn->prepare($query);
                                            $session_id = 5; // Match session_id from transfer.php
                                            $stmt->bind_param("ii", $class_id, $session_id);
                                            $stmt->execute();
                                            $result = $stmt->get_result();
                                            if ($result->num_rows === 0) {
                                                echo "<tr><td colspan='3'>" . $translations[$lang]['no_records'] . "</td></tr>";
                                                error_log("No students found for class_id: $class_id, session_id: $session_id");
                                            }
                                            $i=1;
                                            while ($student = $result->fetch_assoc()) {
                                                echo "<tr>";
                                                echo "<td>" . $i++ . "</td>";

                                                echo "<td><input type='checkbox' name='students[]' value='{$student['id']}'></td>";
                                                echo "<td>" . htmlspecialchars($student['name']) . "</td>";
                                                echo "<td>" . htmlspecialchars($student['father_name']) . "</td>";
                                                echo "</tr>";
                                            }
                                            $stmt->close();
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
<br>
                                <!-- Transfer to Class Selection -->
                                <div class="form-group">
                                    <label for="transfer_class_id"><?php echo $translations[$lang]['transfer_to_label']; ?></label>
                                    <select name="transfer_class_id" id="transfer_class_id" class="form-control" required>
                                        <option value=""><?php echo $translations[$lang]['transfer_to_label']; ?></option>
                                        <?php
                                        // Re-execute the class query for transfer class dropdown
                                        $class_query = "SELECT id, title FROM classes";
                                        $class_result = $conn->query($class_query);
                                        if (!$class_result) {
                                            error_log("Transfer class query failed: " . $conn->error);
                                            echo "<option value=''>Error loading classes</option>";
                                        } else {
                                            while ($class = $class_result->fetch_assoc()) {
                                                if ($class['id'] != $class_id) { // Exclude current class
                                                    echo "<option value='{$class['id']}'>" . htmlspecialchars($class['title']) . "</option>";
                                                }
                                            }
                                        }
                                        ?>
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
            $('#transferForm').on('submit', function(event) {
                var students = $('input[name="students[]"]:checked').length;
                var transferClass = $('#transfer_class_id').val();
                if (students === 0 || !transferClass) {
                    event.preventDefault();
                    alert('<?php echo $translations[$lang]['error']; ?>');
                }
            });
        });
    </script>

    <script src="js/mobile_menu.js"></script>
</body>
</html>