<?php 
header('Content-Type: text/html; charset=utf-8');
require_once('security.php');

// Language fixed to English only
$lang = 'en';

// Language strings
$translations = [
    'en' => [
        'title' => 'Add New Village',
        'title_label' => 'Village Title:',
        'title_placeholder' => 'Enter village title',
        'submit' => 'Submit',
        'reset' => 'Reset',
        'success' => 'Village added successfully!',
        'error' => 'Error: ',
        'empty_error' => 'Title cannot be empty!',
        'list_title' => 'Village List',
        'no_records' => 'No records found.',
        'sr_no' => '#',
        'actions' => 'Actions',
        'edit' => 'Edit',
        'save' => 'Save',
        'cancel' => 'Cancel'
    ]
];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    require_once('conn_inc.php');
    
    $title = trim($_POST['title']);
    
    if (!empty($title)) {
        $stmt = $conn->prepare("INSERT INTO village_councils (title) VALUES (?)");
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
  <?php require_once('meta_inc.php'); ?>
</head>
<body>

<?php require_once('navbar.php'); ?>

<!-- Dashboard -->
<div class="container">
  <div class="row">
    <div class="col-md-12">

      <!-- Panel for Add New Village -->
      <div class="panel " style="border-color: black;">
        <div class="panel-heading bg-dark ">
          <h3 class="panel-title text-white"><?php echo $translations[$lang]['title']; ?></h3>
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
            <div class="form-group">
              <label for="title "><?php echo $translations[$lang]['title_label']; ?></label>
              <input type="text" class="form-control" id="title" name="title" required 
                     placeholder="<?php echo $translations[$lang]['title_placeholder']; ?>">
            </div>

            <div class="text-right">
              <button type="submit" class="btn btn-dark">
                <span class="glyphicon glyphicon-plus"></span> <?php echo $translations[$lang]['submit']; ?>
              </button>
              <button type="reset" class="btn text-white" style="background-color: #f14d4d;">
                <span class="glyphicon glyphicon-refresh"></span> <?php echo $translations[$lang]['reset']; ?>
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- Panel for Village List -->
      <div class="panel  " style="border-color: #0d6efd;">
        <div class="panel-heading bg-primary" >
          <h3 class="panel-title"><?php echo $translations[$lang]['list_title']; ?></h3>
        </div>
        <div class="panel-body">

        <?php
        require_once('conn_inc.php');
        $result = $conn->query("SELECT * FROM village_councils ORDER BY id DESC");
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
                <tbody>
                  <?php $counter = 1; while ($row = $result->fetch_assoc()): ?>
                    <tr data-id="<?php echo $row['id']; ?>">
                      <td><?php echo $counter++; ?></td>
                      <td class="title-cell"><?php echo htmlspecialchars($row['title']); ?></td>
                      <td>
                        <button class="btn btn-xs btn-primary edit-btn"><?php echo $translations[$lang]['edit']; ?></button>
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
  $('.edit-btn').click(function() {
    var $row = $(this).closest('tr');
    var titleCell = $row.find('.title-cell');
    var originalTitle = titleCell.text().trim();

    // Replace cell with input
    titleCell.html('<input type="text" class="form-control input-sm edit-input" value="' + originalTitle + '">');

    $row.find('.edit-btn').addClass('d-none');
    $row.find('.save-btn, .cancel-btn').removeClass('d-none');
  });

  $('.cancel-btn').click(function() {
    var $row = $(this).closest('tr');
    var titleCell = $row.find('.title-cell');
    var originalTitle = titleCell.data('original') || titleCell.find('input').val();

    // Restore original title
    titleCell.html(originalTitle);
    $row.find('.edit-btn').removeClass('d-none');
    $row.find('.save-btn, .cancel-btn').addClass('d-none');
  });

  $('.save-btn').click(function() {
    var $row = $(this).closest('tr');
    var id = $row.data('id');
    var newTitle = $row.find('.edit-input').val();

    $.ajax({
      url: 'village_council_edit.php',
      type: 'POST',
      data: { id: id, title: newTitle },
      success: function(response) {
        if (response.trim() === 'success') {
          $row.find('.title-cell').html(newTitle);
        } else {
          alert('Update failed: ' + response);
        }
        $row.find('.edit-btn').removeClass('d-none');
        $row.find('.save-btn, .cancel-btn').addClass('d-none');
      },
      error: function() {
        alert('AJAX error. Try again.');
      }
    });
  });
});
</script>

<script src="js/mobile_menu.js"></script>

</body>
</html>
