<?php 
require_once('security.php');

$lang = 'en'; // English only

// Language strings
$translations = [
    'title' => 'Accounts Management',
    'add_title' => 'Add New Account',
    'title_label' => 'Account Title:',
    'mobile_label' => 'Mobile Number:',
    'info_label' => 'Other Information:',
    'balance_label' => 'Balance:',
    'title_placeholder' => 'Enter account title',
    'mobile_placeholder' => 'Enter mobile number',
    'info_placeholder' => 'Enter additional information',
    'balance_placeholder' => 'Enter initial balance',
    'submit' => 'Submit',
    'reset' => 'Reset',
    'success' => 'Account added successfully!',
    'update_success' => 'Account updated successfully!',
    'delete_success' => 'Account deleted successfully!',
    'error' => 'Error: ',
    'empty_error' => 'Name and mobile cannot be empty!',
    'list_title' => 'Accounts List',
    'no_records' => 'No accounts found.',
    'sr_no' => '#',
    'actions' => 'Actions',
    'edit' => 'Edit',
    'delete' => 'Delete',
    'save' => 'Save',
    'cancel' => 'Cancel',
    'delete_confirm' => 'Are you sure you want to delete this account?'
];

// Handle deletion
if (isset($_GET['delete'])) {
    require_once('conn_inc.php');
    $id = intval($_GET['delete']);
    
    $stmt = $conn->prepare("DELETE FROM accounts WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = $translations['delete_success'];
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = $translations['error'] . $stmt->error;
        $_SESSION['message_type'] = "danger";
    }
    
    $stmt->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    require_once('conn_inc.php');
    
    $title = trim($_POST['title']);
    $mobile_no = trim($_POST['mobile_no']);
    $other_information = trim($_POST['other_information']);
    $balance = intval($_POST['balance']);
    
    if (!empty($title) && !empty($mobile_no)) {
        if (isset($_POST['account_id']) && !empty($_POST['account_id'])) {
            // Update existing account
            $account_id = intval($_POST['account_id']);
            $stmt = $conn->prepare("UPDATE accounts SET title = ?, mobile_no = ?, other_information = ?, balance = ? WHERE id = ?");
            $stmt->bind_param("sssii", $title, $mobile_no, $other_information, $balance, $account_id);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = $translations['update_success'];
                $_SESSION['message_type'] = "success";
            }
        } else {
            // Insert new account
            $stmt = $conn->prepare("INSERT INTO accounts (title, mobile_no, other_information, balance, dated) VALUES (?, ?, ?, ?, CURRENT_DATE())");
            $stmt->bind_param("sssi", $title, $mobile_no, $other_information, $balance);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = $translations['success'];
                $_SESSION['message_type'] = "success";
            }
        }
        
        if ($stmt->error) {
            $_SESSION['message'] = $translations['error'] . $stmt->error;
            $_SESSION['message_type'] = "danger";
        }
        
        $stmt->close();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } else {
        $_SESSION['message'] = $translations['empty_error'];
        $_SESSION['message_type'] = "danger";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once('meta_inc.php'); ?>
    <title><?php echo $translations['title']; ?></title>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="container">
    <div class="row">
        <div class="col-md-10 col-md-offset-1">

            <!-- Panel for Add/Edit Account -->
            <div class="panel panel-primary">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <?php echo isset($_GET['edit']) ? 'Edit Account' : $translations['add_title']; ?>
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
                        $account_data = [
                            'id' => '', 
                            'title' => '', 
                            'mobile_no' => '', 
                            'other_information' => '', 
                            'balance' => 0
                        ];
                        
                        if (isset($_GET['edit'])) {
                            require_once('conn_inc.php');
                            $id = intval($_GET['edit']);
                            $result = $conn->query("SELECT * FROM accounts WHERE id = $id");
                            if ($result->num_rows > 0) {
                                $account_data = $result->fetch_assoc();
                                $edit_mode = true;
                            }
                        }
                        ?>
                        
                        <input type="hidden" name="account_id" value="<?php echo $account_data['id']; ?>">
                        
                        <div class="form-group">
                            <label for="title"><?php echo $translations['title_label']; ?></label>
                            <input type="text" class="form-control" id="title" name="title" required 
                                   placeholder="<?php echo $translations['title_placeholder']; ?>"
                                   value="<?php echo htmlspecialchars($account_data['title']); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="mobile_no"><?php echo $translations['mobile_label']; ?></label>
                            <input type="text" class="form-control" id="mobile_no" name="mobile_no" required 
                                   placeholder="<?php echo $translations['mobile_placeholder']; ?>"
                                   value="<?php echo htmlspecialchars($account_data['mobile_no']); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="other_information"><?php echo $translations['info_label']; ?></label>
                            <textarea class="form-control" id="other_information" name="other_information" 
                                      placeholder="<?php echo $translations['info_placeholder']; ?>"
                                      rows="3"><?php echo htmlspecialchars($account_data['other_information']); ?></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="balance"><?php echo $translations['balance_label']; ?></label>
                            <input type="number" class="form-control" id="balance" name="balance" 
                                   placeholder="<?php echo $translations['balance_placeholder']; ?>"
                                   value="<?php echo htmlspecialchars($account_data['balance']); ?>">
                        </div>

                        <div class="text-right">
                            <button type="submit" class="btn btn-success">
                                <span class="glyphicon glyphicon-<?php echo $edit_mode ? 'refresh' : 'plus'; ?>"></span> 
                                <?php echo $translations['submit']; ?>
                            </button>
                            
                            <?php if ($edit_mode): ?>
                                <a href="accounts.php" class="btn btn-default">
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

            <!-- Panel for Accounts List -->
            <div class="panel panel-info">
                <div class="panel-heading">
                    <h3 class="panel-title"><?php echo $translations['list_title']; ?></h3>
                </div>
                <div class="panel-body">
                    <?php
                    require_once('conn_inc.php');
                    $result = $conn->query("SELECT * FROM accounts ORDER BY dated DESC");
                    ?>

                    <?php if ($result->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th><?php echo $translations['sr_no']; ?></th>
                                        <th><?php echo $translations['title_label']; ?></th>
                                        <th><?php echo $translations['mobile_label']; ?></th>
                                        <th><?php echo $translations['balance_label']; ?></th>
                                        <th>Date Added</th>
                                        <th><?php echo $translations['actions']; ?></th>
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
                                                <a href="?edit=<?php echo $row['id']; ?>" class="btn btn-xs btn-warning">
                                                    <span class="glyphicon glyphicon-edit"></span> 
                                                    <?php echo $translations['edit']; ?>
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
<?php if (isset($conn)) $conn->close(); ?>
</html>
