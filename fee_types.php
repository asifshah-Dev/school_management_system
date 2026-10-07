<?php 
require_once('security.php');

// Language strings (English only)
$translations = [
    'title' => 'Fee Types Management',
    'add_title' => 'Add New Fee Type',
    'title_label' => 'Fee Type Title:',
    'title_placeholder' => 'Enter fee type title',
    'date_label' => 'Date:',
    'status_label' => 'Status:',
    'status_active' => 'Active',
    'status_inactive' => 'Inactive',
    'submit' => 'Submit',
    'reset' => 'Reset',
    'success' => 'Fee type added successfully!',
    'update_success' => 'Fee type updated successfully!',
    'error' => 'Error: ',
    'empty_error' => 'Title cannot be empty!',
    'list_title' => 'Fee Types List',
    'no_records' => 'No fee types found.',
    'sr_no' => '#',
    'actions' => 'Actions',
    'edit' => 'Edit',
    'save' => 'Save',
    'cancel' => 'Cancel',
    'delete_confirm' => 'Are you sure you want to delete this fee type?',
    'delete_success' => 'Fee type deleted successfully!'
];

// Create database connection
require_once('conn_inc.php');

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = trim($_POST['title']);
    $status = isset($_POST['status']) ? 1 : 0;
    
    if (!empty($title)) {
        if (isset($_POST['fee_type_id']) && !empty($_POST['fee_type_id'])) {
            // Update existing fee type
            $fee_type_id = intval($_POST['fee_type_id']);
            $stmt = $conn->prepare("UPDATE fee_types SET title = ?, status = ? WHERE id = ?");
            $stmt->bind_param("sii", $title, $status, $fee_type_id);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = $translations['update_success'];
                $_SESSION['message_type'] = "success";
            }
        } else {
            // Insert new fee type
            $current_date = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("INSERT INTO fee_types (title, dated, status) VALUES (?, ?, ?)");
            $stmt->bind_param("ssi", $title, $current_date, $status);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = $translations['success'];
                $_SESSION['message_type'] = "success";
            }
        }
        
        if ($stmt->error) {
            $_SESSION['message'] = $translations['error'].$stmt->error;
            $_SESSION['message_type'] = "danger";
        }
        
        $stmt->close();
        header("Location: ".$_SERVER['PHP_SELF']);
        exit();
    } else {
        $_SESSION['message'] = $translations['empty_error'];
        $_SESSION['message_type'] = "danger";
    }
}

// Handle fee type deletion
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    
    $stmt = $conn->prepare("DELETE FROM fee_types WHERE id = ?");
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
$fee_type_data = ['id' => '', 'title' => '', 'dated' => '', 'status' => 1];

if (isset($_GET['edit'])) {
    $id = intval($_GET['edit']);
    $result = $conn->query("SELECT * FROM fee_types WHERE id = $id");
    
    if ($result->num_rows > 0) {
        $fee_type_data = $result->fetch_assoc();
        $edit_mode = true;
    }
    $result->close();
}

// Get data for listing
$result = $conn->query("SELECT * FROM fee_types ORDER BY id DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <title>Fee types - <?php echo $translations['title']; ?></title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap CSS & JS -->
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
  
  <link rel="stylesheet" href="css/mystyle.css" />
  
  <style>
    .table th, .table td {
      text-align: left;
    }
    .status-active {
      color: #3c763d;
      font-weight: bold;
    }
    .status-inactive {
      color: #a94442;
      font-weight: bold;
    }
  </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<!-- Dashboard -->
<div class="container">
  <div class="row">
    <div class="col-md-12 ">

      <!-- Panel for Add/Edit Fee Type -->
      <div class="panel panel-primary">
        <div class="panel-heading">
          <h3 class="panel-title">
            <?php echo $edit_mode ? 'Edit Fee Type' : $translations['add_title']; ?>
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
            <input type="hidden" name="fee_type_id" value="<?php echo $fee_type_data['id']; ?>">
            
            <div class="form-group">
              <label for="title"><?php echo $translations['title_label']; ?></label>
              <input type="text" class="form-control" id="title" name="title" required 
                     placeholder="<?php echo $translations['title_placeholder']; ?>"
                     value="<?php echo htmlspecialchars($fee_type_data['title']); ?>">
            </div>
            
            <?php if ($edit_mode): ?>
            <div class="form-group">
              <label for="dated"><?php echo $translations['date_label']; ?></label>
              <input type="text" class="form-control" id="dated" name="dated" readonly
                     value="<?php echo htmlspecialchars($fee_type_data['dated']); ?>">
            </div>
            <?php endif; ?>
            
            <div class="form-group">
              <label>
                <input type="checkbox" name="status" value="1" <?php echo $fee_type_data['status'] ? 'checked' : ''; ?>>
                <?php echo $translations['status_active']; ?>
              </label>
            </div>

            <div class="text-right">
              <button type="submit" class="btn btn-success">
                <span class="glyphicon glyphicon-<?php echo $edit_mode ? 'refresh' : 'plus'; ?>"></span> 
                <?php echo $edit_mode ? $translations['save'] : $translations['submit']; ?>
              </button>
              
              <?php if ($edit_mode): ?>
                <a href="fee_types.php" class="btn btn-default">
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
          <h3 class="panel-title"><?php echo $translations['list_title']; ?></h3>
        </div>
        <div class="panel-body">

          <?php if ($result->num_rows > 0): ?>
            <div class="table-responsive">
              <table class="table table-bordered table-hover">
                <thead>
                  <tr>
                    <th><?php echo $translations['sr_no']; ?></th>
                    <th><?php echo $translations['title_label']; ?></th>
                    <th><?php echo $translations['date_label']; ?></th>
                    <th><?php echo $translations['status_label']; ?></th>
                    <th><?php echo $translations['actions']; ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php $counter = 1; while ($row = $result->fetch_assoc()): ?>
                    <tr>
                      <td><?php echo $counter++; ?></td>
                      <td><?php echo htmlspecialchars($row['title']); ?></td>
                      <td><?php echo htmlspecialchars($row['dated']); ?></td>
                      <td class="<?php echo $row['status'] ? 'status-active' : 'status-inactive'; ?>">
                        <?php echo $row['status'] ? $translations['status_active'] : $translations['status_inactive']; ?>
                      </td>
                      <td>
                        <a href="?edit=<?php echo $row['id']; ?>" class="btn btn-xs btn-warning">
                          <span class="glyphicon glyphicon-edit"></span> 
                          <?php echo $translations['edit']; ?>
                        </a>
                        <a href="?delete=<?php echo $row['id']; ?>" class="btn btn-xs btn-danger" 
                           onclick="return confirm('<?php echo $translations['delete_confirm']; ?>')">
                          <span class="glyphicon glyphicon-trash"></span> 
                          Delete
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
