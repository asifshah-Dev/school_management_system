<?php 
ob_start();
header('Content-Type: text/html; charset=utf-8');
require_once('security.php');

// Language fixed to English only
$lang = 'en';

// Language strings
$translations = [
    'en' => [
        'title' => 'Add Exam Type',
        'title_label' => 'Exam Title:',
        'title_placeholder' => '',
        'submit' => 'Submit',
        'reset' => 'Reset',
        'success' => 'Exam type added successfully!',
        'update_success' => 'Exam type updated successfully!',
        'error' => 'Error: ',
        'empty_error' => 'Title cannot be empty!',
        'list_title' => 'Exam Types List',
        'no_records' => 'No exam types found.',
        'sr_no' => '#',
        'actions' => 'Actions',
        'edit' => 'Edit',
        'save' => 'Save',
        'cancel' => 'Cancel',
        'delete' => 'Delete'
    ]
];

// Handle AJAX edit request
if (isset($_POST['ajax']) && $_POST['ajax'] == 'edit_exam_type') {
    require_once('conn_inc.php');
    
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    
    $response = ['status' => 'error', 'message' => 'Unknown error'];
    
    if ($id <= 0) {
        $response['message'] = 'Invalid ID';
    } elseif (empty($title)) {
        $response['message'] = $translations[$lang]['empty_error'];
    } else {
        $stmt = $conn->prepare("UPDATE exam_types SET title = ? WHERE id = ?");
        $stmt->bind_param("si", $title, $id);
        
        if ($stmt->execute()) {
            $response['status'] = 'success';
            $response['message'] = $translations[$lang]['update_success'];
        } else {
            $response['message'] = $translations[$lang]['error'] . $stmt->error;
        }
        
        $stmt->close();
    }
    
    $conn->close();
    echo json_encode($response);
    exit();
}

// Process form submission for adding new exam type
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['ajax'])) {
    require_once('conn_inc.php');
    
    $title = trim($_POST['title']);
    
    if (!empty($title)) {
        $stmt = $conn->prepare("INSERT INTO exam_types (title) VALUES (?)");
        $stmt->bind_param("s", $title);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = $translations[$lang]['success'];
            $_SESSION['message_type'] = "success";
            header("Location: ".$_SERVER['PHP_SELF']);
            exit();
        } else {
            $_SESSION['message'] = $translations[$lang]['error'].$stmt->error;
            $_SESSION['message_type'] = "danger";
        }
        
        $stmt->close();
    } else {
        $_SESSION['message'] = $translations[$lang]['empty_error'];
        $_SESSION['message_type'] = "danger";
    }
    
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <!-- Add jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Add Bootstrap CSS if not already included -->
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <style>
        .d-none {
            display: none;
        }
        .panel {
            margin-top: 20px;
        }
        .alert {
            margin-top: 10px;
        }
    </style>
</head>
<body>
<?php require_once('meta_inc.php'); ?>
<?php require_once('navbar.php'); ?>

<!-- Dashboard -->
<div class="container">
  <div class="row">
    <div class="col-md-12">

      <!-- Panel for Add New Exam Type -->
      <div class="panel panel-primary">
        <div class="panel-heading">
          <h3 class="panel-title"><?php echo $translations[$lang]['title']; ?></h3>
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

          <form method="post" action="" id="examTypeForm">
            <div class="form-group">
              <label for="title"><?php echo $translations[$lang]['title_label']; ?></label>
              <input type="text" class="form-control" id="title" name="title" required 
                     placeholder="<?php echo $translations[$lang]['title_placeholder']; ?>">
            </div>

            <div class="text-right">
              <button type="submit" class="btn btn-success">
                <span class="glyphicon glyphicon-plus"></span> <?php echo $translations[$lang]['submit']; ?>
              </button>
              <button type="reset" class="btn btn-default">
                <span class="glyphicon glyphicon-refresh"></span> <?php echo $translations[$lang]['reset']; ?>
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- Panel for Exam Types List -->
      <div class="panel panel-info">
        <div class="panel-heading">
          <h3 class="panel-title"><?php echo $translations[$lang]['list_title']; ?></h3>
        </div>
        <div class="panel-body">

        <?php
        require_once('conn_inc.php');
        $result = $conn->query("SELECT * FROM exam_types ORDER BY id DESC");
        ?>

          <?php if ($result->num_rows > 0): ?>
            <div class="table-responsive">
              <table class="table table-bordered table-hover">
                <thead>
                  <tr>
                    <th><?php echo $translations[$lang]['sr_no']; ?></th>
                    <th><?php echo $translations[$lang]['title_label']; ?></th>
                    <th><?php echo $translations[$lang]['actions']; ?></th>
                  </tr>
                </thead>
                <tbody id="examTypesTable">
                  <?php $counter = 1; while ($row = $result->fetch_assoc()): ?>
                    <tr id="row-<?php echo $row['id']; ?>" data-id="<?php echo $row['id']; ?>">
                      <td><?php echo $counter++; ?></td>
                      <td class="title-cell" data-original="<?php echo htmlspecialchars($row['title']); ?>">
                        <?php echo htmlspecialchars($row['title']); ?>
                      </td>
                      <td>
                        <button class="btn btn-xs btn-warning edit-btn"><?php echo $translations[$lang]['edit']; ?></button>
                        <button class="btn btn-xs btn-success save-btn d-none"><?php echo $translations[$lang]['save']; ?></button>
                        <button class="btn btn-xs btn-default cancel-btn d-none"><?php echo $translations[$lang]['cancel']; ?></button>
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

<script>
$(document).ready(function() {
  // Handle edit button click
  $(document).on('click', '.edit-btn', function() {
    var $row = $(this).closest('tr');
    var titleCell = $row.find('.title-cell');
    var originalTitle = titleCell.data('original');
    
    // Store original title if not already stored
    if (!titleCell.data('original')) {
      titleCell.data('original', titleCell.text().trim());
    }
    
    // Replace cell with input
    titleCell.html('<input type="text" class="form-control input-sm edit-input" value="' + originalTitle + '">');
    
    // Toggle button visibility
    $row.find('.edit-btn').addClass('d-none');
    $row.find('.save-btn, .cancel-btn').removeClass('d-none');
  });

  // Handle cancel button click
  $(document).on('click', '.cancel-btn', function() {
    var $row = $(this).closest('tr');
    var titleCell = $row.find('.title-cell');
    var originalTitle = titleCell.data('original');
    
    // Restore original title
    titleCell.html(originalTitle);
    
    // Toggle button visibility
    $row.find('.edit-btn').removeClass('d-none');
    $row.find('.save-btn, .cancel-btn').addClass('d-none');
  });

  // Handle save button click
  $(document).on('click', '.save-btn', function() {
    var $row = $(this).closest('tr');
    var id = $row.data('id');
    var newTitle = $row.find('.edit-input').val().trim();
    
    if (!newTitle) {
      alert('Title cannot be empty!');
      return;
    }
    
    // Show loading state
    $row.find('.save-btn').prop('disabled', true).html('<i class="glyphicon glyphicon-refresh glyphicon-spin"></i> Saving...');
    
    // Send AJAX request
    $.ajax({
      url: '<?php echo $_SERVER['PHP_SELF']; ?>',
      type: 'POST',
      dataType: 'json',
      data: {
        ajax: 'edit_exam_type',
        id: id,
        title: newTitle
      },
      success: function(response) {
        if (response.status === 'success') {
          // Update the cell with new title
          $row.find('.title-cell').html(newTitle);
          $row.find('.title-cell').data('original', newTitle);
          
          // Show success message
          showAlert(response.message, 'success');
        } else {
          // Show error message
          showAlert(response.message, 'danger');
          
          // Revert to original title
          var originalTitle = $row.find('.title-cell').data('original');
          $row.find('.title-cell').html(originalTitle);
        }
      },
      error: function(xhr, status, error) {
        showAlert('AJAX error: ' + error, 'danger');
        console.error('AJAX Error:', status, error);
      },
      complete: function() {
        // Restore button state
        $row.find('.edit-btn').removeClass('d-none');
        $row.find('.save-btn, .cancel-btn').addClass('d-none');
        $row.find('.save-btn').prop('disabled', false).html('<?php echo $translations[$lang]['save']; ?>');
      }
    });
  });

  // Function to show alert message
  function showAlert(message, type) {
    // Remove existing alerts
    $('.alert-dismissible').remove();
    
    // Create new alert
    var alertHtml = '<div class="alert alert-' + type + ' alert-dismissible" style="margin-top: 10px;">' +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    message +
                    '</div>';
    
    // Add alert to the top of the page
    $('.container').prepend(alertHtml);
    
    // Auto-remove alert after 5 seconds
    setTimeout(function() {
      $('.alert-dismissible').fadeOut('slow', function() {
        $(this).remove();
      });
    }, 5000);
  }

  // Handle form submission for adding new exam type
  $('#examTypeForm').submit(function(e) {
    var title = $('#title').val().trim();
    if (!title) {
      e.preventDefault();
      showAlert('<?php echo $translations[$lang]['empty_error']; ?>', 'danger');
      $('#title').focus();
    }
  });
});
</script>

<!-- Include Bootstrap JS -->
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>

</body>
</html>