<?php 
require_once('security.php');

// Language is fixed to English
$lang = 'en';

// Language strings
$translations = [
    'title' => 'Items Management',
    'add_title' => 'Add New Item',
    'title_label' => 'Item Title:',
    'title_placeholder' => 'Enter item title',
    'date_label' => 'Date:',
    'status_label' => 'Status:',
    'status_active' => 'Active',
    'status_inactive' => 'Inactive',
    'submit' => 'Submit',
    'reset' => 'Reset',
    'success' => 'Item added successfully!',
    'update_success' => 'Item updated successfully!',
    'error' => 'Error: ',
    'empty_error' => 'Title cannot be empty!',
    'list_title' => 'Items List',
    'no_records' => 'No items found.',
    'sr_no' => '#',
    'actions' => 'Actions',
    'edit' => 'Edit',
    'save' => 'Save',
    'cancel' => 'Cancel',
    'delete' => 'Delete',
    'delete_confirm' => 'Are you sure you want to delete this item?'
];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    require_once('conn_inc.php');
    
    $title = trim($_POST['title']);
    $dated = $_POST['dated'];
    $status = isset($_POST['status']) ? 1 : 0;
    
    if (!empty($title)) {
        if (isset($_POST['item_id']) && !empty($_POST['item_id'])) {
            // Update existing item
            $item_id = intval($_POST['item_id']);
            $stmt = $conn->prepare("UPDATE account_heads SET title = ?, dated = ?, status = ? WHERE id = ?");
            $stmt->bind_param("ssii", $title, $dated, $status, $item_id);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = $translations['update_success'];
                $_SESSION['message_type'] = "success";
            }
        } else {
            // Insert new item
            $stmt = $conn->prepare("INSERT INTO account_heads (title, dated, status) VALUES (?, ?, ?)");
            $stmt->bind_param("ssi", $title, $dated, $status);
            
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

// Handle item deletion
if (isset($_GET['delete'])) {
    require_once('conn_inc.php');
    $id = intval($_GET['delete']);
    
    $stmt = $conn->prepare("DELETE FROM account_heads WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = 'Item deleted successfully!';
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = $translations['error'].$stmt->error;
        $_SESSION['message_type'] = "danger";
    }
    
    $stmt->close();
    header("Location: ".$_SERVER['PHP_SELF']);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <title>account head - <?php echo $translations['title']; ?></title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap CSS & JS -->
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
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
    <div class="col-md-10 col-md-offset-1">

      <!-- Panel for Add/Edit Item -->
      <div class="panel panel-primary">
        <div class="panel-heading">
          <h3 class="panel-title">
            <?php 
            echo isset($_GET['edit']) ? 'Edit Item' : $translations['add_title']; 
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
            $item_data = ['id' => '', 'title' => '', 'dated' => date('Y-m-d'), 'status' => 1];
            
            if (isset($_GET['edit'])) {
                require_once('conn_inc.php');
                $id = intval($_GET['edit']);
                $result = $conn->query("SELECT * FROM account_heads WHERE id = $id");
                
                if ($result->num_rows > 0) {
                    $item_data = $result->fetch_assoc();
                    $edit_mode = true;
                }
            }
            ?>
            
            <input type="hidden" name="item_id" value="<?php echo $item_data['id']; ?>">
            
            <div class="form-group">
              <label for="title"><?php echo $translations['title_label']; ?></label>
              <input type="text" class="form-control" id="title" name="title" required 
                     placeholder="<?php echo $translations['title_placeholder']; ?>"
                     value="<?php echo htmlspecialchars($item_data['title']); ?>">
            </div>
            
            <div class="form-group">
              <label for="dated"><?php echo $translations['date_label']; ?></label>
              <input type="date" class="form-control" id="dated" name="dated" required
                     value="<?php echo $item_data['dated']; ?>">
            </div>
            
            <div class="form-group">
              <label>
                <input type="checkbox" name="status" value="1" <?php echo $item_data['status'] ? 'checked' : ''; ?>>
                <?php echo $translations['status_label']; ?>
                <span class="<?php echo $item_data['status'] ? 'status-active' : 'status-inactive'; ?>">
                  (<?php echo $item_data['status'] ? $translations['status_active'] : $translations['status_inactive']; ?>)
                </span>
              </label>
            </div>

            <div class="text-right">
              <button type="submit" class="btn btn-success">
                <span class="glyphicon glyphicon-<?php echo $edit_mode ? 'refresh' : 'plus'; ?>"></span> 
                <?php echo $translations['submit']; ?>
              </button>
              
              <?php if ($edit_mode): ?>
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-default">
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

      <!-- Panel for Items List -->
      <div class="panel panel-info">
        <div class="panel-heading">
          <h3 class="panel-title"><?php echo $translations['list_title']; ?></h3>
        </div>
        <div class="panel-body">

          <?php
          require_once('conn_inc.php');
          $result = $conn->query("SELECT * FROM account_heads ORDER BY id DESC");
          ?>

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
                      <td><?php echo $row['dated']; ?></td>
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
                          <?php echo $translations['delete']; ?>
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
<?php $conn->close(); ?>
</html>
