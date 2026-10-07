<?php
ob_start();
require_once('conn_inc.php');

// Language handling
$_SESSION['lang'] = 'en';
$lang = $_SESSION['lang'];

// Language strings
$translations = [
    'en' => [
        'title' => 'Users Management',
        'add_title' => 'Add New User',
        'username_label' => 'Username:',
        'username_placeholder' => 'Enter username',
        'password_label' => 'Password:',
        'password_placeholder' => 'Enter password',
        'mobile_no_label' => 'Mobile No:',
        'mobile_no_placeholder' => 'Enter mobile number',
        'email_label' => 'Email:',
        'email_placeholder' => 'Enter email address',
        'role_label' => 'Role',
        'village_council_label' => 'Village Council',
        'class_section_label' => 'Class',
        'submit' => 'Submit',
        'reset' => 'Reset',
        'success' => 'Operation successful!',
        'error' => 'Error: ',
        'empty_error' => 'Username, Role, and Village Council cannot be empty!',
        'list_title' => 'Users List',
        'no_records' => 'No users found.',
        'sr_no' => '#',
        'actions' => 'Actions',
        'edit' => 'Edit',
        'save' => 'Save',
        'cancel' => 'Cancel',
        'delete' => 'Delete',
        'delete_confirm' => 'Are you sure you want to delete this user?'
    ]
];

// Fetch roles, village councils, class sections
$roles = $conn->query("SELECT id, title FROM roles ORDER BY title");
$village_councils = $conn->query("SELECT id, title FROM village_councils ORDER BY title");
$class_sections = $conn->query("
    SELECT c.id, c.title AS class_name, s.title AS section_name
    FROM classes c
    LEFT JOIN class_sections cs ON cs.class_id = c.id
    LEFT JOIN sections s ON cs.section_id = s.id
    ORDER BY c.title, s.title
");

// Fetch Teacher role ID
$teacher_role_id = 0;
$stmt = $conn->prepare("SELECT id FROM roles WHERE UPPER(title) = 'TEACHER'");
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) $teacher_role_id = $result->fetch_assoc()['id'];
$stmt->close();

// Handle add/edit POST
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $mobile_no = trim($_POST['mobile_no']);
    $salary = trim($_POST['salary']);
    $email = trim($_POST['email']);
    $role_id = intval($_POST['role_id']);
    $village_council_id = intval($_POST['village_council_id']);
    $class_section_ids = $_POST['class_section_ids'] ?? [];

    if (!empty($username) && !empty($role_id) && !empty($village_council_id)) {
        if (!empty($_POST['user_id'])) {
            // Update user
            $user_id = intval($_POST['user_id']);
            if (!empty($password)) {
                $stmt = $conn->prepare("UPDATE users SET username=?, password=?, mobile_no=?, email=?, role_id=?,salary=?, village_council_id=? WHERE id=?");
                $stmt->bind_param("ssssiiii", $username, $password, $mobile_no, $email, $role_id,$salary, $village_council_id, $user_id);
            } else {
                $stmt = $conn->prepare("UPDATE users SET username=?, mobile_no=?, email=?, role_id=?,salary=?, village_council_id=? WHERE id=?");
                $stmt->bind_param("ssssiii", $username, $mobile_no, $email, $role_id,$salary, $village_council_id, $user_id);
            }
            $stmt->execute();
            $stmt->close();
        } else {
            // Insert new user
            $stmt = $conn->prepare("INSERT INTO users (username, password, mobile_no, email, role_id,salary, village_council_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssii", $username, $password, $mobile_no, $email, $role_id,$salary, $village_council_id);
            $stmt->execute();
            $user_id = $conn->insert_id;
            $stmt->close();
        }

        // Handle teacher classes - FIXED: Always delete existing assignments first
        $conn->query("DELETE FROM teacher_classes WHERE teacher_id=$user_id");
        $role_name = $conn->query("SELECT title FROM roles WHERE id=$role_id")->fetch_assoc()['title'];
        if (strcasecmp($role_name, 'Teacher') == 0 && !empty($class_section_ids)) {
            $stmt_cs = $conn->prepare("INSERT INTO teacher_classes (teacher_id, class_section_id) VALUES (?, ?)");
            foreach ($class_section_ids as $cs_id) {
                $cs_id = intval($cs_id);
                if ($cs_id > 0) {
                    $stmt_cs->bind_param("ii", $user_id, $cs_id);
                    $stmt_cs->execute();
                }
            }
            $stmt_cs->close();
        }

        $_SESSION['message'] = $translations[$lang]['success'];
        $_SESSION['message_type'] = "success";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } else {
        $_SESSION['message'] = $translations[$lang]['empty_error'];
        $_SESSION['message_type'] = "danger";
    }
}

// Handle delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    $_SESSION['message'] = 'User deleted successfully!';
    $_SESSION['message_type'] = 'success';
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Users - <?php echo $translations[$lang]['title']; ?></title>
    <!-- Bootstrap 3.4.1 CSS & JS -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
    <!-- Select2 CSS & JS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <link rel="stylesheet" href="css/mystyle.css" />
   
    <script>
        $(document).ready(function() {
            // Log to console to verify jQuery is loaded
            console.log('jQuery loaded, version:', $.fn.jquery);

            // Show/hide class+section for teacher
            function toggleClassSectionField() {
                var roleId = $('#role_id').val();
                var teacherRoleId = '<?php echo $teacher_role_id; ?>';
                console.log('Selected role ID:', roleId, 'Teacher role ID:', teacherRoleId);
                if (roleId == teacherRoleId && roleId !== '') {
                    $('#class_section_field').show();
                    console.log('Showing class+section dropdown');
                } else {
                    $('#class_section_field').hide();
                    console.log('Hiding class+section dropdown');
                }
            }
            toggleClassSectionField();
            $('#role_id').change(toggleClassSectionField);

            // Initialize Select2
            $('#role_id, select[name="village_council_id"], select[name="class_section_ids[]"]').select2({
                width: '100%',
                placeholder: 'Select an option',
                allowClear: true
            });
        });
    </script>
</head>
<body>

<?php require_once('navbar.php'); 
$edit_mode = '';
?>

<!-- Dashboard -->
<div class="container">
    <div class="row">
        <div class="col-md-12 ">
            <!-- Panel for Add/Edit User -->
            <div class="panel panel-primary">
                <div class="panel-heading">
                    <h3 class="panel-title"><?php echo $edit_mode ? 'Edit User' : $translations[$lang]['add_title']; ?></h3>
                </div>
                <div class="panel-body">
                    <?php if (isset($_SESSION['message'])): ?>
                        <div class="alert alert-<?php echo $_SESSION['message_type']; ?>">
                            <?php echo $_SESSION['message']; unset($_SESSION['message'], $_SESSION['message_type']); ?>
                        </div>
                    <?php endif; ?>

                    <?php
                    $edit_mode = false;
                    $user_data = ['id' => '', 'username' => '', 'password' => '', 'mobile_no' => '', 'email' => '', 'role_id' => '', 'village_council_id' => '', 'salary' => '', 'class_section_ids' => []];
                    if (isset($_GET['edit'])) {
                        $id = intval($_GET['edit']);
                        $stmt = $conn->prepare("SELECT * FROM users WHERE id=?");
                        $stmt->bind_param("i", $id);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        if ($result->num_rows > 0) {
                            $user_data = $result->fetch_assoc();
                            $edit_mode = true;
                            
                            // FIXED: Ensure class_section_ids is always an array
                            $user_data['class_section_ids'] = [];
                            $cs_result = $conn->query("SELECT class_section_id FROM teacher_classes WHERE teacher_id=$id");
                            if ($cs_result && $cs_result->num_rows > 0) {
                                while ($cs = $cs_result->fetch_assoc()) {
                                    $user_data['class_section_ids'][] = $cs['class_section_id'];
                                }
                            }
                        }
                        $stmt->close();
                    }
                    ?>

                    <form method="post" class="form-horizontal">
                        <input type="hidden" name="user_id" value="<?php echo $user_data['id']; ?>">
                        <input type="hidden" id="teacher_role_id" value="<?php echo $teacher_role_id; ?>">

                        <div class="form-group">
                            <label class="col-sm-3 control-label"><?php echo $translations[$lang]['username_label']; ?></label>
                            <div class="col-sm-9">
                                <input type="text" class="form-control" name="username" required placeholder="<?php echo $translations[$lang]['username_placeholder']; ?>" value="<?php echo htmlspecialchars($user_data['username']); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-3 control-label"><?php echo $translations[$lang]['password_label']; ?></label>
                            <div class="col-sm-9">
                                <input type="text" class="form-control" name="password" placeholder="<?php echo $translations[$lang]['password_placeholder']; ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-3 control-label"><?php echo $translations[$lang]['mobile_no_label']; ?></label>
                            <div class="col-sm-9">
                                <input type="text" class="form-control" name="mobile_no" placeholder="<?php echo $translations[$lang]['mobile_no_placeholder']; ?>" value="<?php echo htmlspecialchars($user_data['mobile_no']); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-3 control-label"><?php echo $translations[$lang]['email_label']; ?></label>
                            <div class="col-sm-9">
                                <input type="email" class="form-control" name="email" placeholder="<?php echo $translations[$lang]['email_placeholder']; ?>" value="<?php echo htmlspecialchars($user_data['email']); ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">Salary</label>
                            <div class="col-sm-9">
                                <input type="text" class="form-control" name="salary" placeholder="" value="<?php echo htmlspecialchars($user_data['salary']); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-3 control-label"><?php echo $translations[$lang]['role_label']; ?></label>
                            <div class="col-sm-9">
                                <select name="role_id" id="role_id" class="form-control" required>
                                    <option value="">Select Role</option>
                                    <?php while ($role = $roles->fetch_assoc()): ?>
                                        <option value="<?php echo $role['id']; ?>" <?php echo $role['id'] == $user_data['role_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($role['title']); ?></option>
                                    <?php endwhile; $roles->data_seek(0); ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-3 control-label"><?php echo $translations[$lang]['village_council_label']; ?></label>
                            <div class="col-sm-9">
                                <select name="village_council_id" class="form-control" required>
                                    <option value="">Select Village Council</option>
                                    <?php while ($vc = $village_councils->fetch_assoc()): ?>
                                        <option value="<?php echo $vc['id']; ?>" <?php echo $vc['id'] == $user_data['village_council_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($vc['title']); ?></option>
                                    <?php endwhile; $village_councils->data_seek(0); ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group" id="class_section_field" style="display:none;">
                            <label class="col-sm-3 control-label"><?php echo $translations[$lang]['class_section_label']; ?></label>
                            <div class="col-sm-9">
                                <select name="class_section_ids[]" class="form-control" multiple>
                                    <option value="">Select Class + Section</option>
                                    <?php while ($cs = $class_sections->fetch_assoc()): ?>
                                        <option value="<?php echo $cs['id']; ?>" <?php echo (is_array($user_data['class_section_ids']) && in_array($cs['id'], $user_data['class_section_ids'])) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cs['class_name']) . '  ' . $cs['section_name']; ?>
                                        </option>
                                    <?php endwhile; $class_sections->data_seek(0); ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <div class="col-sm-offset-3 col-sm-9">
                                <button type="submit" class="btn btn-success">
                                    <span class="glyphicon glyphicon-<?php echo $edit_mode ? 'refresh' : 'plus'; ?>"></span> 
                                    <?php echo $translations[$lang]['submit']; ?>
                                </button>
                                <?php if ($edit_mode): ?>
                                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-default">
                                        <span class="glyphicon glyphicon-remove"></span> 
                                        <?php echo $translations[$lang]['cancel']; ?>
                                    </a>
                                <?php else: ?>
                                    <button type="reset" class="btn btn-default">
                                        <span class="glyphicon glyphicon-refresh"></span> 
                                        <?php echo $translations[$lang]['reset']; ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Panel for Users List -->
            <div class="panel panel-info">
                <div class="panel-heading">
                    <h3 class="panel-title"><?php echo $translations[$lang]['list_title']; ?></h3>
                </div>
                <div class="panel-body">
                    <?php
                    $result = $conn->query("
                        SELECT u.id, u.username, u.mobile_no, u.email, r.title AS role_title, vc.title AS village_council_title,u.salary,
                               GROUP_CONCAT(DISTINCT CONCAT(c.title, '  ', COALESCE(s.title, '')) SEPARATOR ', ') AS class_sections
                        FROM users u
                        LEFT JOIN roles r ON u.role_id = r.id
                        LEFT JOIN village_councils vc ON u.village_council_id = vc.id
                        LEFT JOIN teacher_classes tc ON u.id = tc.teacher_id
                        LEFT JOIN classes c ON tc.class_section_id = c.id
                        LEFT JOIN class_sections cs ON cs.class_id = c.id
                        LEFT JOIN sections s ON cs.section_id = s.id
                        GROUP BY u.id
                        ORDER BY u.id DESC
                    ");
                    ?>

                    <?php if ($result->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th><?php echo $translations[$lang]['sr_no']; ?></th>
                                        <th><?php echo $translations[$lang]['username_label']; ?></th>
                                       
                                        <th><?php echo $translations[$lang]['role_label']; ?></th>
                                        <th>Salary</th>
                                        
                                        <th><?php echo $translations[$lang]['class_section_label']; ?></th>
                                        <th><?php echo $translations[$lang]['actions']; ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $counter = 1; while ($row = $result->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo $counter++; ?></td>
                                            <td><span style="font-size: 1rem;"><b><?php echo htmlspecialchars($row['username']); ?></b>  </span> <br>  <?php if($row['mobile_no'] != 0 && $row['mobile_no'] != ''){ echo "Mobile no: "; echo htmlspecialchars($row['mobile_no']);}else{ echo "Mobile no: N/A";}?> <br>  <?php if($row['email'] != ''){ echo "Email: "; echo htmlspecialchars($row['email']);}else{echo "Email: N/A";}?> <br> Address:  <?php echo htmlspecialchars($row['village_council_title']);?></td>
                                            
                                            <td><?php echo htmlspecialchars($row['role_title']); ?></td>
                                            <td><?php echo htmlspecialchars($row['salary']); ?></td>
                                           
                                            <td><?php echo htmlspecialchars($row['class_sections'] ?: '-'); ?></td>
                                            <td>
                                                <a href="?edit=<?php echo $row['id']; ?>" class="btn btn-xs btn-warning">
                                                    <span class="glyphicon glyphicon-edit"></span> 
                                                    <?php echo $translations[$lang]['edit']; ?>
                                                </a>
                                                <a href="?delete=<?php echo $row['id']; ?>" class="btn btn-xs btn-danger" onclick="return confirm('<?php echo $translations[$lang]['delete_confirm']; ?>')">
                                                    <span class="glyphicon glyphicon-trash"></span> 
                                                    <?php echo $translations[$lang]['delete']; ?>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info text-center"><?php echo $translations[$lang]['no_records']; ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="js/mobile_menu.js"></script>
</body>
</html>
<?php $conn->close(); ?>