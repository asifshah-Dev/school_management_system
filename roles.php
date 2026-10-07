<?php 
require_once('security.php');

// Language handling
$_SESSION['lang'] = 'en';
$lang = $_SESSION['lang'];

// Language strings
$translations = [
    'en' => [
        'title' => 'Roles Management',
        'add_title' => 'Add New Role',
        'title_label' => 'Role Title:',
        'title_placeholder' => 'Enter role title',
        'date_label' => 'Date:',
        'status_label' => 'Status:',
        'status_active' => 'Active',
        'status_inactive' => 'Inactive',
        'permissions' => 'Permissions',
        'can_view' => 'Can View',
        'can_add' => 'Can Add',
        'can_edit' => 'Can Edit',
        'can_delete' => 'Can Delete',
        'submit' => 'Submit',
        'reset' => 'Reset',
        'success' => 'Role added successfully!',
        'update_success' => 'Role updated successfully!',
        'error' => 'Error: ',
        'empty_error' => 'Title cannot be empty!',
        'list_title' => 'Roles List',
        'no_records' => 'No roles found.',
        'sr_no' => '#',
        'actions' => 'Actions',
        'edit' => 'Edit',
        'save' => 'Save',
        'cancel' => 'Cancel',
        'delete' => 'Delete',
        'delete_confirm' => 'Are you sure you want to delete this role?'
    ]
];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    require_once('conn_inc.php');
    
    $title = trim($_POST['title']);
    $dated = $_POST['dated'];
    $status = isset($_POST['status']) ? 1 : 0;

    // permissions
    $can_view   = isset($_POST['can_view']) ? 1 : 0;
    $can_add    = isset($_POST['can_add']) ? 1 : 0;
    $can_edit   = isset($_POST['can_edit']) ? 1 : 0;
    $can_delete = isset($_POST['can_delete']) ? 1 : 0;
    
    if (!empty($title)) {
        if (isset($_POST['role_id']) && !empty($_POST['role_id'])) {
            // Update existing role
            $role_id = intval($_POST['role_id']);
            $stmt = $conn->prepare("UPDATE roles 
                                    SET title = ?, dated = ?, status = ?, 
                                        can_view=?, can_add=?, can_edit=?, can_delete=? 
                                    WHERE id = ?");
            $stmt->bind_param("ssiiiiii", $title, $dated, $status, $can_view, $can_add, $can_edit, $can_delete, $role_id);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = $translations[$lang]['update_success'];
                $_SESSION['message_type'] = "success";
            }
        } else {
            // Insert new role
            $stmt = $conn->prepare("INSERT INTO roles (title, dated, status, can_view, can_add, can_edit, can_delete) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssiiiii", $title, $dated, $status, $can_view, $can_add, $can_edit, $can_delete);
            
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

// Handle role deletion
if (isset($_GET['delete'])) {
    require_once('conn_inc.php');
    $id = intval($_GET['delete']);
    
    $stmt = $conn->prepare("DELETE FROM roles WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = 'Role deleted successfully!';
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = $translations[$lang]['error'].$stmt->error;
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
  <title>Roles - <?php echo $translations[$lang]['title']; ?></title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap CSS & JS -->
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
  
  <link rel="stylesheet" href="css/mystyle.css" />
  
  <style>
    .table th, .table td { text-align: left; }
    .status-active { color: #3c763d; font-weight: bold; }
    .status-inactive { color: #a94442; font-weight: bold; }
  </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<!-- Dashboard -->
<div class="container">
  <div class="row">
    <div class="col-md-12">

      <!-- Panel for Add/Edit Role -->
      <div class="panel panel-primary">
        <div class="panel-heading">
          <h3 class="panel-title">
            <?php 
            echo isset($_GET['edit']) ? 'Edit Role' : $translations[$lang]['add_title']; 
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
            $role_data = ['id' => '', 'title' => '', 'dated' => date('Y-m-d'), 'status' => 1,
                          'can_view'=>0,'can_add'=>0,'can_edit'=>0,'can_delete'=>0];
            
            if (isset($_GET['edit'])) {
                require_once('conn_inc.php');
                $id = intval($_GET['edit']);
                $result = $conn->query("SELECT * FROM roles WHERE id = $id");
                
                if ($result->num_rows > 0) {
                    $role_data = $result->fetch_assoc();
                    $edit_mode = true;
                }
            }
            ?>
            
            <input type="hidden" name="role_id" value="<?php echo $role_data['id']; ?>">
            
            <div class="form-group">
              <label for="title"><?php echo $translations[$lang]['title_label']; ?></label>
              <input type="text" class="form-control" id="title" name="title" required 
                     placeholder="<?php echo $translations[$lang]['title_placeholder']; ?>"
                     value="<?php echo htmlspecialchars($role_data['title']); ?>">
            </div>
            
            <div class="form-group">
              <label for="dated"><?php echo $translations[$lang]['date_label']; ?></label>
              <input type="date" class="form-control" id="dated" name="dated" required
                     value="<?php echo $role_data['dated']; ?>">
            </div>
            
            <div class="form-group">
              <label>
                <input type="checkbox" name="status" value="1" <?php echo $role_data['status'] ? 'checked' : ''; ?>>
                <?php echo $translations[$lang]['status_label']; ?>
                <span class="<?php echo $role_data['status'] ? 'status-active' : 'status-inactive'; ?>">
                  (<?php echo $role_data['status'] ? $translations[$lang]['status_active'] : $translations[$lang]['status_inactive']; ?>)
                </span>
              </label>
            </div>

            <!-- Permissions Section -->
            <div class="form-group">
              <label><?php echo $translations[$lang]['permissions']; ?>:</label><br>
              <label><input type="checkbox" name="can_view" value="1" <?php echo $role_data['can_view'] ? 'checked' : ''; ?>> <?php echo $translations[$lang]['can_view']; ?></label><br>
              <label><input type="checkbox" name="can_add" value="1" <?php echo $role_data['can_add'] ? 'checked' : ''; ?>> <?php echo $translations[$lang]['can_add']; ?></label><br>
              <label><input type="checkbox" name="can_edit" value="1" <?php echo $role_data['can_edit'] ? 'checked' : ''; ?>> <?php echo $translations[$lang]['can_edit']; ?></label><br>
              <label><input type="checkbox" name="can_delete" value="1" <?php echo $role_data['can_delete'] ? 'checked' : ''; ?>> <?php echo $translations[$lang]['can_delete']; ?></label>
            </div>

            <div class="text-right">
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
          </form>
        </div>
      </div>

      <!-- Panel for Roles List -->
      <div class="panel panel-info">
        <div class="panel-heading">
          <h3 class="panel-title"><?php echo $translations[$lang]['list_title']; ?></h3>
        </div>
        <div class="panel-body">

          <?php
          require_once('conn_inc.php');
          $result = $conn->query("SELECT * FROM roles ORDER BY id DESC");
          ?>

          <?php if ($result->num_rows > 0): ?>
            <div class="table-responsive">
              <table class="table table-bordered table-hover">
                <thead>
                  <tr>
                    <th><?php echo $translations[$lang]['sr_no']; ?></th>
                    <th><?php echo $translations[$lang]['title_label']; ?></th>
                    <th><?php echo $translations[$lang]['date_label']; ?></th>
                    <th><?php echo $translations[$lang]['status_label']; ?></th>
                    <th><?php echo $translations[$lang]['permissions']; ?></th>
                    <th><?php echo $translations[$lang]['actions']; ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php $counter = 1; while ($row = $result->fetch_assoc()): ?>
                    <tr>
                      <td><?php echo $counter++; ?></td>
                      <td><?php echo htmlspecialchars($row['title']); ?></td>
                      <td><?php echo $row['dated']; ?></td>
                      <td class="<?php echo $row['status'] ? 'status-active' : 'status-inactive'; ?>">
                        <?php echo $row['status'] ? $translations[$lang]['status_active'] : $translations[$lang]['status_inactive']; ?>
                      </td>
                      <td>
                        <?php
                          $perms = [];
                          if ($row['can_view']) $perms[] = $translations[$lang]['can_view'];
                          if ($row['can_add']) $perms[] = $translations[$lang]['can_add'];
                          if ($row['can_edit']) $perms[] = $translations[$lang]['can_edit'];
                          if ($row['can_delete']) $perms[] = $translations[$lang]['can_delete'];
                          echo implode(", ", $perms) ?: "-";
                        ?>
                      </td>
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
<?php $conn->close(); ?>
</html>
