<?php
ob_start();
require_once('security.php');

// Initialize session if not started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Generate CSRF token if not set
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Language strings
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
    'invalid_amount' => 'Amount must be a positive number or zero!',
    'invalid_csrf' => 'Invalid request!',
    'delete_confirm' => 'Are you sure you want to delete all fee types for this class and session?',
    'select_option' => '-- Select --',
    'duplicate_error' => 'Fee type already assigned for this class and session!',
    'select_fee_types' => 'Select Fee Types'
];

// Create database connection
require_once('conn_inc.php');

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['message'] = $translations['invalid_csrf'];
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $class_id = intval($_POST['class_id']);
    $session_id = intval($_POST['session_id']);
    
    // Collect fee type data - process checked fee types
    $fee_type_data = [];
    $has_valid_fee_types = false;
    
    if (isset($_POST['fee_type_ids']) && is_array($_POST['fee_type_ids'])) {
        foreach ($_POST['fee_type_ids'] as $fee_type_id) {
            $fee_type_id = intval($fee_type_id);
            $amount = isset($_POST['amounts'][$fee_type_id]) ? floatval($_POST['amounts'][$fee_type_id]) : 0;
            
            // Allow amount >= 0 (changed from > 0 to >= 0)
            if ($amount >= 0) {
                $fee_type_data[$fee_type_id] = $amount;
                $has_valid_fee_types = true;
            }
        }
    }
    
    // Validation
    $errors = [];
    if (empty($class_id)) $errors[] = 'class_id';
    if (empty($session_id)) $errors[] = 'session_id';
    if (!$has_valid_fee_types) {
        $errors[] = 'fee_type_ids';
        $_SESSION['message'] = $translations['no_fee_types_selected'];
        $_SESSION['message_type'] = 'danger';
    }

    if (empty($errors)) {
        // Check for duplicates (only for new entries, not edits)
        if (!isset($_POST['edit_session_id']) || !isset($_POST['edit_class_id'])) {
            // Check each fee type individually
            foreach ($fee_type_data as $fee_type_id => $amount) {
                $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM class_fee_types WHERE class_id = ? AND session_id = ? AND fee_type_id = ?");
                $check_stmt->bind_param("iii", $class_id, $session_id, $fee_type_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result()->fetch_assoc();
                $check_stmt->close();
                
                if ($check_result['count'] > 0) {
                    // Get fee type name for error message
                    $fee_type_stmt = $conn->prepare("SELECT title FROM fee_types WHERE id = ?");
                    $fee_type_stmt->bind_param("i", $fee_type_id);
                    $fee_type_stmt->execute();
                    $fee_type_result = $fee_type_stmt->get_result()->fetch_assoc();
                    $fee_type_stmt->close();
                    
                    $_SESSION['message'] = $translations['duplicate_error'] . ' (' . htmlspecialchars($fee_type_result['title']) . ')';
                    $_SESSION['message_type'] = 'danger';
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit();
                }
            }
        }
        
        // If editing, delete old records first
        if (isset($_POST['edit_session_id']) && isset($_POST['edit_class_id'])) {
            $edit_session_id = intval($_POST['edit_session_id']);
            $edit_class_id = intval($_POST['edit_class_id']);
            
            $delete_stmt = $conn->prepare("DELETE FROM class_fee_types WHERE class_id = ? AND session_id = ?");
            $delete_stmt->bind_param("ii", $edit_class_id, $edit_session_id);
            $delete_stmt->execute();
            $delete_stmt->close();
        }
        
        // Insert new records - ONLY for checked fee types
        $insert_stmt = $conn->prepare("INSERT INTO class_fee_types (class_id, fee_type_id, session_id, amount) VALUES (?, ?, ?, ?)");
        $success = true;
        
        foreach ($fee_type_data as $fee_type_id => $amount) {
            // Double-check for duplicates before inserting (for safety)
            $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM class_fee_types WHERE class_id = ? AND session_id = ? AND fee_type_id = ?");
            $check_stmt->bind_param("iii", $class_id, $session_id, $fee_type_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();
            
            if ($check_result['count'] == 0) {
                $insert_stmt->bind_param("iiid", $class_id, $fee_type_id, $session_id, $amount);
                if (!$insert_stmt->execute()) {
                    $success = false;
                    error_log("Database error: " . $insert_stmt->error);
                    break;
                }
            }
        }
        
        $_SESSION['message'] = $success ? 
            (isset($_POST['edit_session_id']) ? $translations['update_success'] : $translations['success']) : 
            $translations['error'] . 'An unexpected error occurred.';
        $_SESSION['message_type'] = $success ? 'success' : 'danger';
        $insert_stmt->close();
        
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } else {
        if (!isset($_SESSION['message'])) {
            $_SESSION['message'] = $translations['empty_error'];
            $_SESSION['message_type'] = 'danger';
        }
    }
}

// Handle deletion (delete all records for that class/session)
if (isset($_GET['delete_class_id']) && isset($_GET['delete_session_id'])) {
    $class_id = intval($_GET['delete_class_id']);
    $session_id = intval($_GET['delete_session_id']);
    
    $stmt = $conn->prepare("DELETE FROM class_fee_types WHERE class_id = ? AND session_id = ?");
    $stmt->bind_param("ii", $class_id, $session_id);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = $translations['delete_success'];
        $_SESSION['message_type'] = "success";
    } else {
        error_log("Database error: " . $stmt->error);
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
    
    $stmt = $conn->prepare("SELECT class_id, fee_type_id, session_id, amount FROM class_fee_types WHERE class_id = ? AND session_id = ?");
    $stmt->bind_param("ii", $edit_class_id, $edit_session_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
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
    $stmt->close();
}

// Get dropdown options
$classes = [];
$sessions = [];
$fee_types = [];

// Get sessions
$session_result = $conn->query("SELECT id, title FROM sessions ORDER BY title");
while ($row = $session_result->fetch_assoc()) {
    $sessions[$row['id']] = $row['title'];
}
$session_result->close();

// Get classes
$class_result = $conn->query("SELECT id, title FROM classes ORDER BY title");
while ($row = $class_result->fetch_assoc()) {
    $classes[$row['id']] = $row['title'];
}
$class_result->close();

// Get fee types
$fee_type_result = $conn->query("SELECT id, title FROM fee_types ORDER BY title");
while ($row = $fee_type_result->fetch_assoc()) {
    $fee_types[$row['id']] = $row['title'];
}
$fee_type_result->close();

// Get fee list
$fee_list = [];
$result = $conn->query("
    SELECT 
        cft.class_id,
        cft.session_id,
        c.title AS class_title,
        s.title AS session_title,
        ft.title AS fee_type_title,
        cft.amount,
        GROUP_CONCAT(DISTINCT sec.title ORDER BY sec.title SEPARATOR ', ') AS section_titles
    FROM class_fee_types cft
    JOIN classes c ON cft.class_id = c.id
    JOIN fee_types ft ON cft.fee_type_id = ft.id
    JOIN sessions s ON cft.session_id = s.id
    LEFT JOIN class_sections cs ON cft.class_id = cs.class_id
    LEFT JOIN sections sec ON cs.section_id = sec.id
    GROUP BY cft.id, cft.class_id, cft.session_id, c.title, s.title, ft.title, cft.amount
    ORDER BY c.title, s.title, ft.title
");

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $fee_list[] = $row;
    }
}
$result->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo $translations['title']; ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Using local Bootstrap files - adjust paths as needed -->
    <style>
        .table th, .table td { 
            text-align: left; 
            vertical-align: middle;
            border: 1px solid #ddd !important;
        }
        .fee-types-container { 
            max-height: 300px; 
            overflow-y: auto; 
            border: 1px solid #ddd; 
            padding: 15px; 
            margin-bottom: 15px; 
            border-radius: 4px;
            background-color: #fff;
        }
        .fee-type-item { 
            padding: 8px 0; 
            border-bottom: 1px solid #eee; 
            display: flex; 
            align-items: center;
            gap: 10px;
        }
        .fee-type-item:last-child { border-bottom: none; }
        .amount-input { 
            width: 150px; 
            flex-shrink: 0;
        }
        .checkbox { 
            margin: 0;
            display: flex;
            align-items: center;
            flex: 1;
        }
        .checkbox label { 
            margin: 0;
            display: flex;
            align-items: center;
            width: 100%;
            cursor: pointer;
            padding: 5px 0;
        }
        .checkbox input[type="checkbox"] {
            margin-right: 10px;
            flex-shrink: 0;
            cursor: pointer;
            width: 18px;
            height: 18px;
        }
        .fee-type-title {
            flex: 1;
            margin-right: 15px;
            cursor: pointer;
            padding: 5px;
            border-radius: 3px;
            transition: background-color 0.2s;
        }
        .fee-type-title:hover {
            background-color: #f5f5f5;
        }
        .actions-column { white-space: nowrap; }
        table {
            border-collapse: collapse !important;
        }
        thead th {
            border-bottom: 2px solid #dee2e6 !important;
            background-color: #f8f9fa;
        }
        tbody tr {
            border-bottom: 1px solid #dee2e6;
        }
        tbody tr:last-child {
            border-bottom: none;
        }
        .panel {
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .panel-heading {
            background-color: #f8f9fa;
            border-bottom: 1px solid #ddd;
            padding: 10px 15px;
        }
        .panel-body {
            padding: 15px;
        }
        .class-session-group {
            background-color: #f9f9f9;
        }
        .class-session-group td {
            border-top: 2px solid #ddd !important;
        }
        .fee-type-details td {
            border-top: none !important;
        }
        .alert {
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .text-right {
            text-align: right;
        }
        .btn {
            margin-left: 5px;
        }
        .required-field::after {
            content: " *";
            color: #dc3545;
        }
        .amount-input-field:not(:checked) {
            background-color: #f8f9fa;
        }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="container">
    <div class="row">
        <div class="col-md-12">
            <!-- Add/Edit Panel -->
            <div class="panel panel-primary">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <?php echo $edit_mode ? $translations['edit_title'] : $translations['add_title']; ?>
                    </h3>
                </div>
                <div class="panel-body">
                    <?php if (isset($_SESSION['message'])): ?>
                        <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible">
                            <button type="button" class="close" data-dismiss="alert">&times;</button>
                            <?php 
                            echo $_SESSION['message']; 
                            unset($_SESSION['message'], $_SESSION['message_type']);
                            ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="" id="feeTypeForm">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <?php if ($edit_mode): ?>
                            <input type="hidden" name="edit_session_id" value="<?php echo $fee_data['session_id']; ?>">
                            <input type="hidden" name="edit_class_id" value="<?php echo $fee_data['class_id']; ?>">
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label for="session_id" class="required-field"><?php echo $translations['session_label']; ?></label>
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
                            <label for="class_id" class="required-field"><?php echo $translations['class_label']; ?></label>
                            <select name="class_id" id="class_id" class="form-control" required>
                                <option value=""><?php echo $translations['select_option']; ?></option>
                                <?php foreach ($classes as $id => $title): ?>
                                    <option value="<?php echo $id; ?>" <?php echo ($id == $fee_data['class_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="required-field"><?php echo $translations['select_fee_types']; ?></label>
                            <div class="fee-types-container">
                                <?php foreach ($fee_types as $id => $title): ?>
                                    <div class="fee-type-item">
                                        <div class="checkbox">
                                            <label>
                                                <input type="checkbox" name="fee_type_ids[]" value="<?php echo $id; ?>" 
                                                       class="fee_type_checkbox" 
                                                       <?php echo isset($fee_data['fee_types'][$id]) ? 'checked' : ''; ?>>
                                                <span class="fee-type-title">
                                                    <?php echo htmlspecialchars($title); ?>
                                                </span>
                                            </label>
                                        </div>
                                        <div class="amount-input">
                                            <input type="number" class="form-control amount-input-field" 
                                                   name="amounts[<?php echo $id; ?>]" 
                                                   data-fee-id="<?php echo $id; ?>"
                                                   step="0.01" min="0" placeholder="0.00" 
                                                   value="<?php echo isset($fee_data['fee_types'][$id]) ? htmlspecialchars($fee_data['fee_types'][$id]) : ''; ?>"
                                                   <?php echo isset($fee_data['fee_types'][$id]) ? '' : ''; ?>>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <small class="text-muted">Select fee types and enter amounts for each. Only checked fee types will be saved (amount can be 0 or more).</small>
                        </div>

                        <div class="text-right">
                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                <span class="glyphicon glyphicon-<?php echo $edit_mode ? 'refresh' : 'plus'; ?>"></span> 
                                <?php echo $edit_mode ? $translations['save'] : $translations['submit']; ?>
                            </button>
                            
                            <?php if ($edit_mode): ?>
                                <a href="class_fee_types.php" class="btn btn-default">
                                    <span class="glyphicon glyphicon-remove"></span> 
                                    <?php echo $translations['cancel']; ?>
                                </a>
                            <?php else: ?>
                                <button type="reset" class="btn btn-default" id="resetBtn">
                                    <span class="glyphicon glyphicon-refresh"></span> 
                                    <?php echo $translations['reset']; ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- List Panel -->
            <div class="panel panel-info">
                <div class="panel-heading">
                    <h3 class="panel-title"><?php echo $translations['title']; ?> List</h3>
                </div>
                <div class="panel-body">
                    <?php if (!empty($fee_list)): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th><?php echo $translations['sr_no']; ?></th>
                                        <th><?php echo $translations['class_label']; ?></th>
                                        <th><?php echo $translations['section_label']; ?></th>
                                        <th><?php echo $translations['fee_type_label']; ?></th>
                                        <th><?php echo $translations['amount_label']; ?></th>
                                        <th><?php echo $translations['session_label']; ?></th>
                                        <th><?php echo $translations['actions']; ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $current_counter = 1;
                                    $class_session_fees = [];
                                    
                                    // Group fees by class and session
                                    foreach ($fee_list as $row) {
                                        $key = $row['class_id'] . '_' . $row['session_id'];
                                        if (!isset($class_session_fees[$key])) {
                                            $class_session_fees[$key] = [
                                                'class_id' => $row['class_id'],
                                                'session_id' => $row['session_id'],
                                                'class_title' => $row['class_title'],
                                                'session_title' => $row['session_title'],
                                                'section_titles' => $row['section_titles'],
                                                'fees' => []
                                            ];
                                        }
                                        $class_session_fees[$key]['fees'][] = [
                                            'fee_type_title' => $row['fee_type_title'],
                                            'amount' => number_format($row['amount'], 2)
                                        ];
                                    }
                                    
                                    // Display grouped data
                                    foreach ($class_session_fees as $key => $data):
                                        $fee_count = count($data['fees']);
                                        $first_fee = true;
                                        
                                        foreach ($data['fees'] as $index => $fee):
                                    ?>
                                        <tr <?php echo $first_fee ? 'class="class-session-group"' : 'class="fee-type-details"'; ?>>
                                            <?php if ($first_fee): ?>
                                                <td rowspan="<?php echo $fee_count; ?>"><?php echo $current_counter; ?></td>
                                                <td rowspan="<?php echo $fee_count; ?>"><?php echo htmlspecialchars($data['class_title']); ?></td>
                                                <td rowspan="<?php echo $fee_count; ?>"><?php echo htmlspecialchars($data['section_titles'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($fee['fee_type_title']); ?></td>
                                                <td>₹<?php echo $fee['amount']; ?></td>
                                                <td rowspan="<?php echo $fee_count; ?>"><?php echo htmlspecialchars($data['session_title']); ?></td>
                                                <td rowspan="<?php echo $fee_count; ?>" class="actions-column">
                                                    <a href="?edit_class_id=<?php echo $data['class_id']; ?>&edit_session_id=<?php echo $data['session_id']; ?>" class="btn btn-xs btn-warning">
                                                        <span class="glyphicon glyphicon-edit"></span> 
                                                        <?php echo $translations['edit']; ?>
                                                    </a>
                                                    <a href="?delete_class_id=<?php echo $data['class_id']; ?>&delete_session_id=<?php echo $data['session_id']; ?>" 
                                                       class="btn btn-xs btn-danger" 
                                                       onclick="return confirm('<?php echo $translations['delete_confirm']; ?>');">
                                                        <span class="glyphicon glyphicon-trash"></span> 
                                                        <?php echo $translations['delete']; ?>
                                                    </a>
                                                </td>
                                            <?php else: ?>
                                                <td><?php echo htmlspecialchars($fee['fee_type_title']); ?></td>
                                                <td>₹<?php echo $fee['amount']; ?></td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php 
                                            $first_fee = false;
                                        endforeach;
                                        $current_counter++;
                                    endforeach; 
                                    ?>
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

<script>
$(document).ready(function() {
    // Auto-hide alerts after 5 seconds
    setTimeout(function() { 
        $('.alert').fadeOut('slow'); 
    }, 5000);

    // Enable amount input when checkbox is checked, disable when unchecked
    $(document).on('change', '.fee_type_checkbox', function() {
        var amountInput = $(this).closest('.fee-type-item').find('input[type="number"]');
        if ($(this).is(':checked')) {
            amountInput.prop('required', true);
            if (amountInput.val() === '') {
                amountInput.val('0.00');
            }
        } else {
            amountInput.prop('required', false);
            amountInput.val('');
        }
    });

    // Click on fee type title to toggle checkbox
    $(document).on('click', '.fee-type-title', function(e) {
        e.stopPropagation();
        var checkbox = $(this).closest('.checkbox').find('.fee_type_checkbox');
        checkbox.prop('checked', !checkbox.prop('checked'));
        checkbox.trigger('change');
    });

    // Custom reset function
    $('#resetBtn').click(function(e) {
        e.preventDefault();
        $('form')[0].reset();
        // Reset all amount inputs
        $('.amount-input-field').val('').prop('required', false);
    });

    // Form validation before submission
    $('#feeTypeForm').submit(function(e) {
        var hasCheckedFees = false;
        var hasValidAmounts = false;
        var errorMessages = [];
        
        // Check if at least one fee type is checked
        $('.fee_type_checkbox:checked').each(function() {
            hasCheckedFees = true;
            var feeId = $(this).val();
            var amountInput = $('input[name="amounts[' + feeId + ']"]');
            var amount = parseFloat(amountInput.val());
            
            // Check if amount is valid (can be 0 or more)
            if (!isNaN(amount) && amount >= 0) {
                hasValidAmounts = true;
            } else {
                var feeTitle = $(this).closest('.checkbox').find('.fee-type-title').text();
                errorMessages.push('Invalid amount for "' + feeTitle.trim() + '". Amount must be 0 or more.');
            }
        });
        
        if (!hasCheckedFees) {
            alert('<?php echo $translations['no_fee_types_selected']; ?>');
            e.preventDefault();
            return false;
        }
        
        if (!hasValidAmounts) {
            if (errorMessages.length > 0) {
                alert(errorMessages.join('\n'));
            } else {
                alert('<?php echo $translations['invalid_amount']; ?>');
            }
            e.preventDefault();
            return false;
        }
        
        return true;
    });

    // Initialize form state on page load
    initializeFormState();
    
    function initializeFormState() {
        // Set required property for amount inputs based on checkbox state
        $('.fee_type_checkbox').each(function() {
            var amountInput = $(this).closest('.fee-type-item').find('input[type="number"]');
            if ($(this).is(':checked')) {
                amountInput.prop('required', true);
            } else {
                amountInput.prop('required', false);
            }
        });
    }
});
</script>

</body>
</html>
<?php
$conn->close();
ob_end_flush();
?>