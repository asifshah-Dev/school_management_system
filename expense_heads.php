<?php 
require_once('security.php');
require_once('conn_inc.php');

// Error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
        'title' => 'Expense Categories Management',
        'add_title' => 'Add New Expense Category',
        'title_label' => 'Expense Category Title:',
        'title_placeholder' => 'Enter expense category title',
        'submit' => 'Submit',
        'reset' => 'Reset',
        'success' => 'Expense category added successfully!',
        'update_success' => 'Expense category updated successfully!',
        'error' => 'Error: ',
        'empty_error' => 'Title cannot be empty!',
        'list_title' => 'Expense Categories List',
        'no_records' => 'No expense categories found.',
        'sr_no' => '#',
        'actions' => 'Actions',
        'edit' => 'Edit',
        'save' => 'Save',
        'cancel' => 'Cancel',
        'delete_confirm' => 'Are you sure you want to delete this expense category?',
        'delete' => 'Delete'
    ],
    'ur' => [
        'title' => 'اخراجات کے زمرے کا انتظام',
        'add_title' => 'نیا خرچہ زمرہ شامل کریں',
        'title_label' => 'اخراجات کا زمرہ عنوان:',
        'title_placeholder' => 'اخراجات کا زمرہ عنوان درج کریں',
        'submit' => 'جمع کرائیں',
        'reset' => 'دوبارہ ترتیب دیں',
        'success' => 'اخراجات کا زمرہ کامیابی سے شامل ہو گیا!',
        'update_success' => 'اخراجات کا زمرہ کامیابی سے اپ ڈیٹ ہو گیا!',
        'error' => 'خرابی: ',
        'empty_error' => 'عنوان خالی نہیں ہو سکتا!',
        'list_title' => 'اخراجات کے زمرے کی فہرست',
        'no_records' => 'کوئی اخراجات کے زمرے موجود نہیں۔',
        'sr_no' => 'نمبر',
        'actions' => 'اعمال',
        'edit' => 'ترمیم کریں',
        'save' => 'محفوظ کریں',
        'cancel' => 'منسوخ کریں',
        'delete_confirm' => 'کیا آپ واقعی اس اخراجات کے زمرے کو حذف کرنا چاہتے ہیں؟',
        'delete' => 'حذف کریں'
    ]
];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = trim($_POST['title']);
    
    if (!empty($title)) {
        if (isset($_POST['expense_category_id']) && !empty($_POST['expense_category_id'])) {
            // Update existing expense category
            $expense_category_id = intval($_POST['expense_category_id']);
            $current_datetime = date('Y-m-d H:i:s');
            
            $stmt = $conn->prepare("UPDATE expense_categories SET title = ?, updated_at = ? WHERE id = ?");
            $stmt->bind_param("ssi", $title, $current_datetime, $expense_category_id);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = $translations[$lang]['update_success'];
                $_SESSION['message_type'] = "success";
            }
        } else {
            // Insert new expense category
            $current_datetime = date('Y-m-d H:i:s');
            $type = 'expense';
            $status = 1;
            
            // Fix: Correct the bind_param types - s=string, i=integer
            $stmt = $conn->prepare("INSERT INTO expense_categories (title, status, created_at, updated_at) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("siss", $title, $status, $current_datetime, $current_datetime);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = $translations[$lang]['success'];
                $_SESSION['message_type'] = "success";
            } else {
                $_SESSION['message'] = $translations[$lang]['error'] . $stmt->error;
                $_SESSION['message_type'] = "danger";
            }
        }
        
        if ($stmt->error) {
            $_SESSION['message'] = $translations[$lang]['error'] . $stmt->error;
            $_SESSION['message_type'] = "danger";
        }
        
        $stmt->close();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } else {
        $_SESSION['message'] = $translations[$lang]['empty_error'];
        $_SESSION['message_type'] = "danger";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Handle deletion
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    
    // First check if this category is being used in expenses table
    $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM expense WHERE category_id = ?");
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $row = $result->fetch_assoc();
    
    if ($row['count'] > 0) {
        $_SESSION['message'] = ($lang == 'ur') ? 'اس زمرے کو حذف نہیں کیا جا سکتا کیونکہ یہ اخراجات میں استعمال ہو رہا ہے!' : 'This category cannot be deleted because it is being used in expenses!';
        $_SESSION['message_type'] = "danger";
    } else {
        // Delete the category if not in use
        $stmt = $conn->prepare("DELETE FROM expense_categories WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = ($lang == 'ur') ? 'اخراجات کا زمرہ کامیابی سے حذف ہو گیا!' : 'Expense category deleted successfully!';
            $_SESSION['message_type'] = "success";
        } else {
            $_SESSION['message'] = $translations[$lang]['error'] . $stmt->error;
            $_SESSION['message_type'] = "danger";
        }
        $stmt->close();
    }
    
    $check_stmt->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Debug: Check if table exists and has data
$debug_mode = false; // Set to true to see debug info
if ($debug_mode) {
    $check_table = $conn->query("SHOW TABLES LIKE 'expense_categories'");
    if ($check_table->num_rows == 0) {
        echo "<div style='background:red;color:white;padding:10px;'>Table 'expense_categories' does not exist!</div>";
    } else {
        $count_query = $conn->query("SELECT COUNT(*) as total FROM expense_categories");
        $count = $count_query->fetch_assoc();
        echo "<div style='background:green;color:white;padding:10px;'>Table exists. Total records: " . $count['total'] . "</div>";
        
        // Show all records regardless of type for debugging
        $all_records = $conn->query("SELECT * FROM expense_categories");
        echo "<div style='background:blue;color:white;padding:10px;'>";
        echo "<h3>All Records in Database:</h3>";
        while($row = $all_records->fetch_assoc()) {
            echo "ID: " . $row['id'] . ", Title: " . $row['title'] . ", Type: " . $row['type'] . ", Status: " . $row['status'] . "<br>";
        }
        echo "</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="en" dir="<?php echo ($lang == 'ur') ? 'rtl' : 'ltr'; ?>">
<head>
    <?php require_once('meta_inc.php'); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <style>
        .table-actions {
            white-space: nowrap;
        }
        .btn-xs {
            margin: 2px;
        }
        .btn i {
            margin-right: 3px;
        }
        [dir="rtl"] .btn i {
            margin-left: 3px;
            margin-right: 0;
        }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<!-- Dashboard -->
<div class="container">
    <div class="row">
        <div class="col-md-10 col-md-offset-1">

            <!-- Panel for Add/Edit Expense Category -->
            <div class="panel panel-primary">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <?php 
                        echo isset($_GET['edit']) ? 
                            ($lang == 'ur' ? 'اخراجات کے زمرے کی ترمیم کریں' : 'Edit Expense Category') : 
                            $translations[$lang]['add_title']; 
                        ?>
                    </h3>
                </div>
                <div class="panel-body">

                    <?php if (isset($_SESSION['message'])): ?>
                        <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade in">
                            <a href="#" class="close" data-dismiss="alert" aria-label="close">&times;</a>
                            <?php 
                            echo $_SESSION['message']; 
                            unset($_SESSION['message'], $_SESSION['message_type']);
                            ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="">
                        <?php
                        $edit_mode = false;
                        $expense_category_data = [
                            'id' => '', 
                            'title' => ''
                        ];
                        
                        if (isset($_GET['edit'])) {
                            $id = intval($_GET['edit']);
                            $result = $conn->query("SELECT id, title FROM expense_categories WHERE id = $id");
                            if ($result && $result->num_rows > 0) {
                                $expense_category_data = $result->fetch_assoc();
                                $edit_mode = true;
                            }
                        }
                        ?>
                        
                        <input type="hidden" name="expense_category_id" value="<?php echo $expense_category_data['id']; ?>">
                        
                        <div class="form-group">
                            <label for="title"><?php echo $translations[$lang]['title_label']; ?></label>
                            <input type="text" class="form-control" id="title" name="title" required 
                                   placeholder="<?php echo $translations[$lang]['title_placeholder']; ?>"
                                   value="<?php echo htmlspecialchars($expense_category_data['title']); ?>">
                        </div>

                        <div class="text-right">
                            <button type="submit" class="btn btn-success">
                                <i class="fa fa-<?php echo $edit_mode ? 'refresh' : 'plus'; ?>"></i> 
                                <?php echo $edit_mode ? $translations[$lang]['save'] : $translations[$lang]['submit']; ?>
                            </button>
                            
                            <?php if ($edit_mode): ?>
                                <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-default">
                                    <i class="fa fa-times"></i> 
                                    <?php echo $translations[$lang]['cancel']; ?>
                                </a>
                            <?php else: ?>
                                <button type="reset" class="btn btn-default">
                                    <i class="fa fa-refresh"></i> 
                                    <?php echo $translations[$lang]['reset']; ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Panel for Expense Categories List -->
            <div class="panel panel-info">
                <div class="panel-heading">
                    <h3 class="panel-title"><?php echo $translations[$lang]['list_title']; ?></h3>
                </div>
                <div class="panel-body">

                    <?php
                    // First, let's check what's in the database
                    $debug_query = $conn->query("SELECT COUNT(*) as total FROM expense_categories");
                    $debug_result = $debug_query->fetch_assoc();
                    ?>
                    
                    <?php
                    // Query to get all expense categories - temporarily remove type filter to see all records
                    $result = $conn->query("SELECT id, title,  status, created_at FROM expense_categories ORDER BY id DESC");
                    
                    if (!$result) {
                        echo '<div class="alert alert-danger">Database error: ' . $conn->error . '</div>';
                    } else {
                    ?>

                    <?php if ($result->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover table-striped">
                                <thead>
                                    <tr>
                                        <th width="50"><?php echo $translations[$lang]['sr_no']; ?></th>
                                        <th><?php echo $translations[$lang]['title_label']; ?></th>
                                        <th width="200">Created At</th>
                                        <th width="150" class="text-center"><?php echo $translations[$lang]['actions']; ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $counter = 1; 
                                    while ($row = $result->fetch_assoc()): 
                                    ?>
                                        <tr>
                                            <td><?php echo $counter++; ?></td>
                                            <td><?php echo htmlspecialchars($row['title']); ?></td>
                                            <td><?php echo $row['created_at'] ? date('d-m-Y H:i', strtotime($row['created_at'])) : 'N/A'; ?></td>
                                            <td class="text-center table-actions">
                                                <a href="?edit=<?php echo $row['id']; ?>" class="btn btn-xs btn-warning" title="<?php echo $translations[$lang]['edit']; ?>">
                                                    <i class="fa fa-pencil"></i> <?php echo $translations[$lang]['edit']; ?>
                                                </a>
                                                <a href="?delete=<?php echo $row['id']; ?>" class="btn btn-xs btn-danger" title="<?php echo $translations[$lang]['delete']; ?>" 
                                                   onclick="return confirm('<?php echo $translations[$lang]['delete_confirm']; ?>');">
                                                    <i class="fa fa-trash"></i> <?php echo $translations[$lang]['delete']; ?>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Display total count -->
                        <div class="text-right">
                            <strong>Total Categories: <?php echo $result->num_rows; ?></strong>
                        </div>
                        
                    <?php else: ?>
                        <div class="alert alert-info text-center">
                            <span class="glyphicon glyphicon-info-sign"></span> 
                            <?php echo $translations[$lang]['no_records']; ?>
                        </div>
                        
                        <!-- Add a test record button for debugging -->
                        <div class="text-center">
                            <a href="?test_insert=1" class="btn btn-sm btn-primary"><i class="fa fa-plus"></i> Insert Test Record</a>
                        </div>
                        
                        <?php
                        // Handle test insert
                        if (isset($_GET['test_insert'])) {
                            $test_title = "Test Category " . date('His');
                            $test_type = "expense";
                            $test_status = 1;
                            $test_datetime = date('Y-m-d H:i:s');
                            
                            $test_stmt = $conn->prepare("INSERT INTO expense_categories (title, type, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)");
                            $test_stmt->bind_param("ssiss", $test_title, $test_type, $test_status, $test_datetime, $test_datetime);
                            
                            if ($test_stmt->execute()) {
                                echo '<div class="alert alert-success"><i class="fa fa-check-circle"></i> Test record inserted successfully! <a href="'.$_SERVER['PHP_SELF'].'">Refresh</a></div>';
                            } else {
                                echo '<div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> Test insert failed: ' . $test_stmt->error . '</div>';
                            }
                            $test_stmt->close();
                        }
                        ?>
                        
                    <?php endif; ?>
                    
                    <?php } // end of if(!$result) ?>

                </div>
            </div>

        </div>
    </div>
</div>

<!-- Include jQuery and Bootstrap JS -->
<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
<script src="js/mobile_menu.js"></script>

</body>
<?php if (isset($conn)) $conn->close(); ?>
</html>