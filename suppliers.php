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
        'title' => 'Suppliers Management',
        'add_title' => 'Add New Supplier',
        'title_label' => 'Supplier Name:',
        'mobile_label' => 'Mobile Number:',
        'info_label' => 'Other Information:',
        'balance_label' => 'Balance:',
        'title_placeholder' => 'Enter supplier name',
        'mobile_placeholder' => 'Enter mobile number',
        'info_placeholder' => 'Enter additional information',
        'balance_placeholder' => 'Enter initial balance',
        'submit' => 'Submit',
        'reset' => 'Reset',
        'success' => 'Supplier added successfully!',
        'update_success' => 'Supplier updated successfully!',
        'delete_success' => 'Supplier deleted successfully!',
        'error' => 'Error: ',
        'empty_error' => 'Name and mobile cannot be empty!',
        'list_title' => 'Suppliers List',
        'no_records' => 'No suppliers found.',
        'sr_no' => '#',
        'actions' => 'Actions',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'save' => 'Save',
        'cancel' => 'Cancel',
        'delete_confirm' => 'Are you sure you want to delete this supplier?'
    ],
    'ur' => [
        'title' => 'سپلائرز کا انتظام',
        'add_title' => 'نیا سپلائر شامل کریں',
        'title_label' => 'سپلائر کا نام:',
        'mobile_label' => 'موبائل نمبر:',
        'info_label' => 'دیگر معلومات:',
        'balance_label' => 'بقیہ رقم:',
        'title_placeholder' => 'سپلائر کا نام درج کریں',
        'mobile_placeholder' => 'موبائل نمبر درج کریں',
        'info_placeholder' => 'اضافی معلومات درج کریں',
        'balance_placeholder' => 'ابتدائی بقیہ رقم درج کریں',
        'submit' => 'جمع کرائیں',
        'reset' => 'دوبارہ ترتیب دیں',
        'success' => 'سپلائر کامیابی سے شامل ہو گیا!',
        'update_success' => 'سپلائر کامیابی سے اپ ڈیٹ ہو گیا!',
        'delete_success' => 'سپلائر کامیابی سے حذف ہو گیا!',
        'error' => 'خرابی: ',
        'empty_error' => 'نام اور موبائل نمبر خالی نہیں ہو سکتے!',
        'list_title' => 'سپلائرز کی فہرست',
        'no_records' => 'کوئی سپلائر موجود نہیں۔',
        'sr_no' => 'نمبر',
        'actions' => 'اعمال',
        'edit' => 'ترمیم کریں',
        'delete' => 'حذف کریں',
        'save' => 'محفوظ کریں',
        'cancel' => 'منسوخ کریں',
        'delete_confirm' => 'کیا آپ واقعی اس سپلائر کو حذف کرنا چاہتے ہیں؟'
    ]
];

// Handle delete action
if (isset($_GET['delete'])) {
    require_once('conn_inc.php');
    $id = intval($_GET['delete']);
    
    $stmt = $conn->prepare("DELETE FROM suppliers WHERE title = ?");
    $stmt->bind_param("s", $id);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = $translations[$lang]['delete_success'];
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = $translations[$lang]['error'] . $stmt->error;
        $_SESSION['message_type'] = "danger";
    }
    
    $stmt->close();
    header("Location: " . str_replace('&delete='.$id, '', $_SERVER['REQUEST_URI']));
    exit();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    require_once('conn_inc.php');
    
    $title = trim($_POST['title']);
    $mobile_no = trim($_POST['mobile_no']);
    $other_information = trim($_POST['other_information']);
    $balance = intval($_POST['balance']);
    
    if (!empty($title) && !empty($mobile_no)) {
        if (isset($_POST['supplier_id']) && !empty($_POST['supplier_id'])) {
            // Update existing supplier
            $supplier_id = $_POST['supplier_id'];
            $stmt = $conn->prepare("UPDATE suppliers SET title = ?, mobile_no = ?, other_information = ?, balance = ? WHERE title = ?");
            $stmt->bind_param("sssis", $title, $mobile_no, $other_information, $balance, $supplier_id);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = $translations[$lang]['update_success'];
                $_SESSION['message_type'] = "success";
            }
        } else {
            // Insert new supplier
            $stmt = $conn->prepare("INSERT INTO suppliers (title, mobile_no, other_information, balance, dated) VALUES (?, ?, ?, ?, CURRENT_DATE())");
            $stmt->bind_param("sssi", $title, $mobile_no, $other_information, $balance);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = $translations[$lang]['success'];
                $_SESSION['message_type'] = "success";
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
    }
}
?>

<!DOCTYPE html>
<html lang="en" dir="<?php echo ($lang == 'ur') ? 'rtl' : 'ltr'; ?>">
<head>
    <?php require_once('meta_inc.php'); ?>
    <title><?php echo $translations[$lang]['title']; ?></title>
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

            <!-- Panel for Add/Edit Supplier -->
            <div class="panel panel-primary">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <?php 
                        echo isset($_GET['edit']) ? 
                            ($lang == 'ur' ? 'سپلائر کی ترمیم کریں' : 'Edit Supplier') : 
                            $translations[$lang]['add_title']; 
                        ?>
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
                        <?php
                        $edit_mode = false;
                        $supplier_data = [
                            'title' => '', 
                            'mobile_no' => '', 
                            'other_information' => '', 
                            'balance' => 0
                        ];
                        
                        if (isset($_GET['edit'])) {
                            require_once('conn_inc.php');
                            $id = $_GET['edit'];
                            $result = $conn->query("SELECT title, mobile_no, other_information, balance FROM suppliers WHERE title = '$id'");
                            
                            if ($result->num_rows > 0) {
                                $supplier_data = $result->fetch_assoc();
                                $edit_mode = true;
                            }
                        }
                        ?>
                        
                        <input type="hidden" name="supplier_id" value="<?php echo isset($_GET['edit']) ? htmlspecialchars($_GET['edit']) : ''; ?>">
                        
                        <div class="form-group">
                            <label for="title"><?php echo $translations[$lang]['title_label']; ?></label>
                            <input type="text" class="form-control" id="title" name="title" required 
                                   placeholder="<?php echo $translations[$lang]['title_placeholder']; ?>"
                                   value="<?php echo htmlspecialchars($supplier_data['title']); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="mobile_no"><?php echo $translations[$lang]['mobile_label']; ?></label>
                            <input type="text" class="form-control" id="mobile_no" name="mobile_no" required 
                                   placeholder="<?php echo $translations[$lang]['mobile_placeholder']; ?>"
                                   value="<?php echo htmlspecialchars($supplier_data['mobile_no']); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="other_information"><?php echo $translations[$lang]['info_label']; ?></label>
                            <textarea class="form-control" id="other_information" name="other_information" 
                                      placeholder="<?php echo $translations[$lang]['info_placeholder']; ?>"
                                      rows="3"><?php echo htmlspecialchars($supplier_data['other_information']); ?></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="balance"><?php echo $translations[$lang]['balance_label']; ?></label>
                            <input type="number" class="form-control" id="balance" name="balance" 
                                   placeholder="<?php echo $translations[$lang]['balance_placeholder']; ?>"
                                   value="<?php echo htmlspecialchars($supplier_data['balance']); ?>">
                        </div>

                        <div class="text-right">
                            <button type="submit" class="btn btn-success">
                                <span class="glyphicon glyphicon-<?php echo $edit_mode ? 'refresh' : 'plus'; ?>"></span> 
                                <?php echo $translations[$lang]['submit']; ?>
                            </button>
                            
                            <?php if ($edit_mode): ?>
                                <a href="suppliers.php" class="btn btn-default">
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

            <!-- Panel for Suppliers List -->
            <div class="panel panel-info">
                <div class="panel-heading">
                    <h3 class="panel-title"><?php echo $translations[$lang]['list_title']; ?></h3>
                </div>
                <div class="panel-body">

                    <?php
                    require_once('conn_inc.php');
                    $result = $conn->query("SELECT title, mobile_no, other_information, balance, dated FROM suppliers ORDER BY dated DESC");
                    ?>

                    <?php if ($result->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th><?php echo $translations[$lang]['sr_no']; ?></th>
                                        <th><?php echo $translations[$lang]['title_label']; ?></th>
                                        <th><?php echo $translations[$lang]['mobile_label']; ?></th>
                                        <th><?php echo $translations[$lang]['balance_label']; ?></th>
                                        <th>Date Added</th>
                                        <th><?php echo $translations[$lang]['actions']; ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $counter = 1; while ($row = $result->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo $counter++; ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($row['title']); ?>
                                                <?php if (!empty($row['other_information'])): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($row['other_information']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($row['mobile_no']); ?></td>
                                            <td><?php echo number_format($row['balance']); ?></td>
                                            <td><?php echo date('d M Y', strtotime($row['dated'])); ?></td>
                                            <td>
                                                <a href="?edit=<?php echo urlencode($row['title']); ?>" class="btn btn-xs btn-warning">
                                                    <span class="glyphicon glyphicon-edit"></span> 
                                                    <?php echo $translations[$lang]['edit']; ?>
                                                </a>
                                                <!-- <a href="?delete=<?php echo urlencode($row['title']); ?>" class="btn btn-xs btn-danger" 
                                                   onclick="return confirm('<?php echo $translations[$lang]['delete_confirm']; ?>')">
                                                    <span class="glyphicon glyphicon-trash"></span> 
                                                    <?php echo $translations[$lang]['delete']; ?>
                                                </a> -->
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
<?php if (isset($conn)) $conn->close(); ?>
</html>