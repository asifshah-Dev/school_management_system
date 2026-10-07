<?php
require_once('security.php');

// Initialize session if not started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Generate CSRF token if not set
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Language handling (only English now)
$lang = 'en';

// Language strings (only English)
$translations = [
    'title' => 'Class Fee Types Management',
    'add_title' => 'Add Class Fee Type',
    'edit_title' => 'Edit Class Fee Type',
    'class_label' => 'Class',
    'section_label' => 'Section',
    'fee_type_label' => 'Fee Type',
    'session_label' => 'Session',
    'amount_label' => 'Amount',
    'submit' => 'Submit',
    'save' => 'Save',
    'reset' => 'Reset',
    'success' => 'Fee type assigned successfully!',
    'update_success' => 'Fee type updated successfully!',
    'error' => 'Error: ',
    'delete_success' => 'Fee type deleted successfully!',
    'no_records' => 'No fee types found.',
    'sr_no' => '#',
    'actions' => 'Actions',
    'edit' => 'Edit',
    'delete' => 'Delete',
    'cancel' => 'Cancel',
    'empty_error' => 'Required fields cannot be empty!',
    'no_fee_types_selected' => 'At least one fee type must be selected!',
    'invalid_amount' => 'Amount must be a positive number!',
    'invalid_csrf' => 'Invalid request!',
    'delete_confirm' => 'Are you sure you want to delete this fee type?',
    'select_option' => '-- Select --'
];

// Create database connection
require_once('conn_inc.php');

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['message'] = $translations['invalid_csrf'];
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $class_id = intval($_POST['class_id']);
    $session_id = intval($_POST['session_id']);
    
    $fee_type_data = [];
    if (isset($_POST['fee_type_ids']) && is_array($_POST['fee_type_ids'])) {
        foreach ($_POST['fee_type_ids'] as $fee_type_id) {
            $fee_type_id = intval($fee_type_id);
            $amount = isset($_POST['amounts'][$fee_type_id]) ? floatval($_POST['amounts'][$fee_type_id]) : 0;
            if ($amount > 0) {
                $fee_type_data[$fee_type_id] = $amount;
            }
        }
    }
    
    $errors = [];
    if (empty($class_id)) $errors[] = 'class_id';
    if (empty($session_id)) $errors[] = 'session_id';
    if (empty($fee_type_data)) {
        $errors[] = 'fee_type_ids';
        $_SESSION['message'] = $translations['no_fee_types_selected'];
        $_SESSION['message_type'] = 'danger';
    }

    if (empty($errors)) {
        if (isset($_POST['edit_session_id']) && isset($_POST['edit_class_id'])) {
            $edit_session_id = intval($_POST['edit_session_id']);
            $edit_class_id = intval($_POST['edit_class_id']);
            $stmt = $conn->prepare("DELETE FROM class_fee_types WHERE class_id = ? AND session_id = ?");
            $stmt->bind_param("ii", $edit_class_id, $edit_session_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("INSERT INTO class_fee_types (class_id, fee_type_id, session_id, amount) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iiid", $class_id, $fee_type_id, $session_id, $amount);
            $success = true;

            foreach ($fee_type_data as $fee_type_id => $amount) {
                if (!$stmt->execute()) {
                    $success = false;
                    error_log("Database error: " . $stmt->error, 3, 'errors.log');
                    break;
                }
            }

            $_SESSION['message'] = $success ? $translations['update_success'] : $translations['error'] . 'An unexpected error occurred.';
            $_SESSION['message_type'] = $success ? 'success' : 'danger';
            $stmt->close();
        } else {
            $stmt = $conn->prepare("INSERT INTO class_fee_types (class_id, fee_type_id, session_id, amount) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iiid", $class_id, $fee_type_id, $session_id, $amount);
            $success = true;

            foreach ($fee_type_data as $fee_type_id => $amount) {
                if (!$stmt->execute()) {
                    $success = false;
                    error_log("Database error: " . $stmt->error, 3, 'errors.log');
                    break;
                }
            }

            $_SESSION['message'] = $success ? $translations['success'] : $translations['error'] . 'An unexpected error occurred.';
            $_SESSION['message_type'] = $success ? 'success' : 'danger';
            $stmt->close();
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } else {
        if (!isset($_SESSION['message'])) {
            $_SESSION['message'] = $translations['empty_error'];
            $_SESSION['message_type'] = 'danger';
        }
    }
}

// Handle deletion
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM class_fee_types WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $_SESSION['message'] = $translations['delete_success'];
        $_SESSION['message_type'] = "success";
    } else {
        error_log("Database error: " . $stmt->error, 3, 'errors.log');
        $_SESSION['message'] = $translations['error'] . 'An unexpected error occurred.';
        $_SESSION['message_type'] = "danger";
    }
    $stmt->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Get data for edit form
$edit_mode = false;
$fee_data = [
    'class_id' => '',
    'session_id' => '',
    'fee_types' => []
];

if (isset($_GET['edit_class_id']) && isset($_GET['edit_session_id'])) {
    $edit_class_id = intval($_GET['edit_class_id']);
    $edit_session_id = intval($_GET['edit_session_id']);
    $result = $conn->query("SELECT class_id, fee_type_id, session_id, amount FROM class_fee_types WHERE class_id = $edit_class_id AND session_id = $edit_session_id");
    
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $fee_data['fee_types'][$row['fee_type_id']] = $row['amount'];
            if (empty($fee_data['class_id'])) {
                $fee_data['class_id'] = $row['class_id'];
                $fee_data['session_id'] = $row['session_id'];
            }
        }
        $edit_mode = true;
    }
    $result->close();
}

// Get dropdown options
$classes = [];
$sessions = [];
$fee_types = [];

$session_result = $conn->query("SELECT id, title FROM sessions ORDER BY title");
while ($row = $session_result->fetch_assoc()) {
    $sessions[$row['id']] = $row['title'];
}
$session_result->close();

$class_result = $conn->query("SELECT id AS class_id, title AS class_title FROM classes ORDER BY title");
while ($row = $class_result->fetch_assoc()) {
    $classes[$row['class_id']] = $row['class_title'];
}
$class_result->close();

$fee_type_result = $conn->query("SELECT id, title FROM fee_types ORDER BY title");
while ($row = $fee_type_result->fetch_assoc()) {
    $fee_types[$row['id']] = $row['title'];
}
$fee_type_result->close();

// Get fee list with combined sections
$fee_list = [];
$result = $conn->query("
    SELECT cft.id, cft.class_id, cft.fee_type_id, cft.session_id, cft.amount,
           c.title AS class_title, ft.title AS fee_type_title, s.title AS session_title,
           GROUP_CONCAT(sec.title ORDER BY sec.title SEPARATOR ', ') AS section_titles
    FROM class_fee_types cft
    JOIN classes c ON cft.class_id = c.id
    JOIN fee_types ft ON cft.fee_type_id = ft.id
    LEFT JOIN sessions s ON cft.session_id = s.id
    LEFT JOIN class_sections cs ON cft.class_id = cs.class_id
    LEFT JOIN sections sec ON cs.section_id = sec.id
    GROUP BY cft.id, cft.class_id, cft.fee_type_id, cft.session_id, cft.amount, c.title, ft.title, s.title
    ORDER BY c.title, ft.title, s.title
");
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $class_id = $row['class_id'];
        $fee_list[$class_id]['class_title'] = $row['class_title'];
        $fee_list[$class_id]['fees'][] = $row;
    }
}
$result->close();

$class_colors = [
    '#e6f3ff', '#e6ffe6', '#fff5e6', '#ffe6f3', '#f0e6ff', '#e6fffa'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo $translations['title']; ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
    <style>
        .table th, .table td { text-align: left; }
        .checkbox label { padding-left: 20px; }
        .fee-types-container { max-height: 200px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; margin-bottom: 10px; }
        .fee-type-item { margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #eee; }
        .fee-type-item:last-child { border-bottom: none; }
        .amount-input { width: 120px; display: inline-block; margin-left: 10px; }
        .class-header { margin-top: 20px; margin-bottom: 10px; font-size: 18px; font-weight: bold; }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<!-- Dashboard -->
<div class="container">
    <div class="row">
        <div class="col-md-10 col-md-offset-1">

            <!-- Panel for Add/Edit Fee Type -->
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
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <?php if ($edit_mode): ?>
                            <input type="hidden" name="edit_session_id" value="<?php echo $fee_data['session_id']; ?>">
                            <input type="hidden" name="edit_class_id" value="<?php echo $fee_data['class_id']; ?>">
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label><?php echo $translations['session_label']; ?></label>
                            <select name="session_id" id="session_id" class="form-control" required>
                                <option value=""><?php echo $translations['select_option']; ?></option>
                                <?php foreach ($sessions as $id => $title): ?>
                                    <option value="<?php echo $id; ?>" <?php echo ($id == $fee_data['session_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label><?php echo $translations['class_label']; ?></label>
                            <select name="class_id" id="class_id" class="form-control" required disabled>
                                <option value=""><?php echo $translations['select_option']; ?></option>
                                <?php foreach ($classes as $id => $title): ?>
                                    <option value="<?php echo $id; ?>" <?php echo ($id == $fee_data['class_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label><?php echo $translations['fee_type_label']; ?></label>
                            <div class="fee-types-container">
                                <?php foreach ($fee_types as $id => $title): ?>
                                    <div class="fee-type-item">
                                        <div class="checkbox" style="display: inline-block;">
                                            <label>
                                                <input type="checkbox" name="fee_type_ids[]" value="<?php echo $id; ?>" 
                                                       class="fee_type_checkbox" 
                                                       <?php echo isset($fee_data['fee_types'][$id]) ? 'checked' : ''; ?> 
                                                       disabled>
                                                <?php echo htmlspecialchars($title); ?>
                                            </label>
                                        </div>
                                        <div class="amount-input">
                                            <input type="number" class="form-control" name="amounts[<?php echo $id; ?>]" 
                                                   step="0.01" min="0.01" placeholder="0.00"
                                                   value="<?php echo isset($fee_data['fee_types'][$id]) ? htmlspecialchars($fee_data['fee_types'][$id]) : ''; ?>"
                                                   disabled>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="text-right">
                            <button type="submit" class="btn btn-primary">
                                <span class="glyphicon glyphicon-<?php echo $edit_mode ? 'refresh' : 'plus'; ?>"></span> 
                                <?php echo $edit_mode ? $translations['save'] : $translations['submit']; ?>
                            </button>
                            
                            <?php if ($edit_mode): ?>
                                <a href="class_fee_types.php" class="btn btn-default">
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

            <!-- Panel for Fee Types List -->
            <div class="panel panel-info">
                <div class="panel-heading">
                    <h3 class="panel-title"><?php echo $translations['title']; ?></h3>
                </div>
                <div class="panel-body">
                    <?php if (!empty($fee_list)): ?>
                        <?php foreach ($fee_list as $class_id => $class_data): ?>
                            <div class="class-header">
                                <?php echo $translations['class_label']; ?>: <?php echo htmlspecialchars($class_data['class_title']); ?>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead>
                                        <tr>
                                            <th><?php echo $translations['sr_no']; ?></th>
                                            <th><?php echo $translations['class_label']; ?></th>
                                            <th><?php echo $translations['section_label']; ?></th>
                                            <th><?php echo $translations['fee_type_label']; ?></th>
                                            <th><?php echo $translations['session_label']; ?></th>
                                            <th><?php echo $translations['amount_label']; ?></th>
                                            <th><?php echo $translations['actions']; ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $counter = 1; foreach ($class_data['fees'] as $row): ?>
                                            <tr style="background-color: <?php echo $class_colors[$class_id % count($class_colors)]; ?>;">
                                                <td><?php echo $counter++; ?></td>
                                                <td><?php echo htmlspecialchars($row['class_title']); ?></td>
                                                <td><?php echo htmlspecialchars($row['section_titles'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($row['fee_type_title']); ?></td>
                                                <td><?php echo htmlspecialchars($row['session_title'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($row['amount']); ?></td>
                                                <td>
                                                    <a href="?edit_class_id=<?php echo $row['class_id']; ?>&edit_session_id=<?php echo $row['session_id']; ?>" class="btn btn-xs btn-warning">
                                                        <span class="glyphicon glyphicon-edit"></span> 
                                                        <?php echo $translations['edit']; ?>
                                                    </a>
                                                    <a href="?delete=<?php echo $row['id']; ?>" class="btn btn-xs btn-danger" onclick="return confirm('<?php echo $translations['delete_confirm']; ?>');">
                                                        <span class="glyphicon glyphicon-trash"></span> 
                                                        <?php echo $translations['delete']; ?>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
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

<script>
$(document).ready(function() {
    setTimeout(function() { $('.alert').fadeOut('slow'); }, 5000);

    $('#session_id').change(function() {
        if ($(this).val() !== '') {
            $('#class_id').prop('disabled', false);
        } else {
            $('#class_id').prop('disabled', true).val('');
            $('.fee_type_checkbox').prop('disabled', true).prop('checked', false);
            $('.fee_type_checkbox').closest('.fee-type-item').find('input[type="number"]').prop('disabled', true);
        }
    });

    $('#class_id').change(function() {
        if ($(this).val() !== '') {
            $('.fee_type_checkbox').prop('disabled', false);
            $('.fee_type_checkbox').each(function() {
                var amountInput = $(this).closest('.fee-type-item').find('input[type="number"]');
                amountInput.prop('disabled', !$(this).is(':checked'));
            });
        } else {
            $('.fee_type_checkbox').prop('disabled', true).prop('checked', false);
            $('.fee_type_container').closest('.fee-type-item').find('input[type="number"]').prop('disabled', true);
        }
    });

    $(document).on('change', '.fee_type_checkbox', function() {
        var amountInput = $(this).closest('.fee-type-item').find('input[type="number"]');
        if ($(this).is(':checked')) {
            amountInput.prop('disabled', false);
        } else {
            amountInput.prop('disabled', true).val('');
        }
    });

    if ($('#session_id').val() !== '') {
        $('#class_id').prop('disabled', false);
        if ($('#class_id').val() !== '') {
            $('#class_id').trigger('change');
        }
    }
});
</script>

</body>
</html>
<?php
$conn->close();
?>