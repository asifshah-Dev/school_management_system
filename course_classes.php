<?php 
require_once('security.php');

// Language handling
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'];
} elseif (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = 'en'; // Default to English
}

$lang = $_SESSION['lang'];

// Language strings
$translations = [
    'en' => [
        'title' => 'Assign Classes to Courses',
        'add_title' => 'Add Course-Class Mapping',
        'edit_title' => 'Edit Course-Class Mapping',
        'course_label' => 'Course:',
        'class_label' => 'Class:',
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
        'empty_error' => 'Course and Class are required!',
        'delete_confirm' => 'Are you sure you want to delete this mapping?',
        'select_option' => '-- Select --'
    ],
    'ur' => [
        'title' => 'کلاسز کو کورسز سے منسلک کریں',
        'add_title' => 'کورس-کلاس ربط شامل کریں',
        'edit_title' => 'کورس-کلاس ربط میں ترمیم کریں',
        'course_label' => 'کورس:',
        'class_label' => 'کلاس:',
        'submit' => 'جمع کریں',
        'save' => 'محفوظ کریں',
        'reset' => 'دوبارہ ترتیب دیں',
        'success' => 'ربط کامیابی سے شامل ہو گیا!',
        'update_success' => 'ربط کامیابی سے اپ ڈیٹ ہو گیا!',
        'error' => 'خرابی: ',
        'delete_success' => 'ربط کامیابی سے حذف ہو گیا!',
        'no_records' => 'کوئی ربط موجود نہیں۔',
        'sr_no' => 'نمبر',
        'actions' => 'اعمال',
        'edit' => 'ترمیم',
        'delete' => 'حذف کریں',
        'cancel' => 'منسوخ کریں',
        'empty_error' => 'کورس اور کلاس ضروری ہیں!',
        'delete_confirm' => 'کیا آپ واقعی یہ ربط حذف کرنا چاہتے ہیں؟',
        'select_option' => '-- منتخب کریں --'
    ]
];

// Create database connection
require_once('conn_inc.php');

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $course_id = intval($_POST['course_id']);
    $class_id = intval($_POST['class_id']);
    
    if (!empty($course_id) && !empty($class_id)) {
        if (isset($_POST['mapping_id']) && !empty($_POST['mapping_id'])) {
            // Update existing mapping
            $mapping_id = intval($_POST['mapping_id']);
            $stmt = $conn->prepare("UPDATE course_classes SET course_id = ?, class_id = ? WHERE id = ?");
            $stmt->bind_param("iii", $course_id, $class_id, $mapping_id);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = $translations[$lang]['update_success'];
                $_SESSION['message_type'] = "success";
            }
        } else {
            // Insert new mapping
            $stmt = $conn->prepare("INSERT INTO course_classes (course_id, class_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $course_id, $class_id);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = $translations[$lang]['success'];
                $_SESSION['message_type'] = "success";
            }
        }
        
        if ($stmt->error) {
            $_SESSION['message'] = $translations[$lang]['error'].$stmt->error;
            $_SESSION['message_type'] = "danger";
        }
        
        $stmt->close();
        header("Location: ".$_SERVER['PHP_SELF']);
        exit();
    } else {
        $_SESSION['message'] = $translations[$lang]['empty_error'];
        $_SESSION['message_type'] = "danger";
    }
}

// Handle mapping deletion
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    
    $stmt = $conn->prepare("DELETE FROM course_classes WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = $translations[$lang]['delete_success'];
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = $translations[$lang]['error'].$stmt->error;
        $_SESSION['message_type'] = "danger";
    }
    
    $stmt->close();
    header("Location: ".$_SERVER['PHP_SELF']);
    exit();
}

// Get data for edit form
$edit_mode = false;
$mapping_data = ['id' => '', 'course_id' => '', 'class_id' => ''];

if (isset($_GET['edit'])) {
    $id = intval($_GET['edit']);
    $result = $conn->query("SELECT * FROM course_classes WHERE id = $id");
    
    if ($result->num_rows > 0) {
        $mapping_data = $result->fetch_assoc();
        $edit_mode = true;
    }
    $result->close();
}

// Get dropdown options
$courses = [];
$classes = [];

// Fetch courses
$course_result = $conn->query("SELECT id, title FROM courses ORDER BY title");
while ($row = $course_result->fetch_assoc()) {
    $courses[$row['id']] = $row['title'];
}

// Fetch classes
$class_result = $conn->query("SELECT id, title FROM classes ORDER BY title");
while ($row = $class_result->fetch_assoc()) {
    $classes[$row['id']] = $row['title'];
}

// Get data for listing
$result = $conn->query("
    SELECT cc.id, c.title AS course_title, cl.title AS class_title 
    FROM course_classes cc
    JOIN courses c ON cc.course_id = c.id
    JOIN classes cl ON cc.class_id = cl.id
    ORDER BY cc.id DESC
");
?>

<!DOCTYPE html>
<html lang="en" dir="<?php echo ($lang == 'ur') ? 'rtl' : 'ltr'; ?>">
<head>
    <?php require_once('meta_inc.php'); ?>
    <style>
        <?php if ($lang == 'ur'): ?>
        body, .form-control, .btn, .alert, .navbar-nav {
            text-align: right;
            direction: rtl;
        }
        .dropdown-menu {
            text-align: right;
            left: auto;
            right: 0;
        }
        .table th, .table td {
            text-align: right;
        }
        <?php else: ?>
        .table th, .table td {
            text-align: left;
        }
        <?php endif; ?>
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<!-- Language switcher -->
<div class="container text-right">
    <a href="?lang=en" class="btn btn-xs btn-default <?php echo ($lang == 'en') ? 'active' : ''; ?>">English</a>
    <a href="?lang=ur" class="btn btn-xs btn-default <?php echo ($lang == 'ur') ? 'active' : ''; ?>">اردو</a>
</div>

<!-- Dashboard -->
<div class="container">
    <div class="row">
        <div class="col-md-10 col-md-offset-1">

            <!-- Panel for Add/Edit Mapping -->
            <div class="panel panel-primary">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <?php echo $edit_mode ? $translations[$lang]['edit_title'] : $translations[$lang]['add_title']; ?>
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
                            <label for="course_id"><?php echo $translations[$lang]['course_label']; ?></label>
                            <select class="form-control" id="course_id" name="course_id" required>
                                <option value=""><?php echo $translations[$lang]['select_option']; ?></option>
                                <?php foreach ($courses as $id => $title): ?>
                                    <option value="<?php echo $id; ?>" <?php echo ($id == $mapping_data['course_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="class_id"><?php echo $translations[$lang]['class_label']; ?></label>
                            <select class="form-control" id="class_id" name="class_id" required>
                                <option value=""><?php echo $translations[$lang]['select_option']; ?></option>
                                <?php foreach ($classes as $id => $title): ?>
                                    <option value="<?php echo $id; ?>" <?php echo ($id == $mapping_data['class_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="text-right">
                            <button type="submit" class="btn btn-success">
                                <span class="glyphicon glyphicon-<?php echo $edit_mode ? 'refresh' : 'plus'; ?>"></span> 
                                <?php echo $edit_mode ? $translations[$lang]['save'] : $translations[$lang]['submit']; ?>
                            </button>
                            
                            <?php if ($edit_mode): ?>
                                <a href="course_class_mapping.php" class="btn btn-default">
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
                    </form>
                </div>
            </div>

            <!-- Panel for Mappings List -->
            <div class="panel panel-info">
                <div class="panel-heading">
                    <h3 class="panel-title"><?php echo $translations[$lang]['title']; ?></h3>
                </div>
                <div class="panel-body">

                    <?php if ($result->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th><?php echo $translations[$lang]['sr_no']; ?></th>
                                        <th><?php echo $translations[$lang]['course_label']; ?></th>
                                        <th><?php echo $translations[$lang]['class_label']; ?></th>
                                        <th><?php echo $translations[$lang]['actions']; ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $counter = 1; while ($row = $result->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo $counter++; ?></td>
                                            <td><?php echo htmlspecialchars($row['course_title']); ?></td>
                                            <td><?php echo htmlspecialchars($row['class_title']); ?></td>
                                            <td>
                                                <a href="?edit=<?php echo $row['id']; ?>" class="btn btn-xs btn-warning">
                                                    <span class="glyphicon glyphicon-edit"></span> 
                                                    <?php echo $translations[$lang]['edit']; ?>
                                                </a>
                                                <a href="?delete=<?php echo $row['id']; ?>" class="btn btn-xs btn-danger" 
                                                   onclick="return confirm('<?php echo $translations[$lang]['delete_confirm']; ?>')">
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
                        <div class="alert alert-info text-center">
                            <?php echo $translations[$lang]['no_records']; ?>
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