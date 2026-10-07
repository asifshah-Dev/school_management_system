<?php 
require_once('security.php');
require_once('conn_inc.php');

// Default language (English only now)
$lang = 'en';

// Language strings
$translations = [
    'en' => [
        'title' => 'Time Slots Management',
        'add_title' => 'Add New Time Slot',
        'start_time_label' => 'Start Time:',
        'end_time_label' => 'End Time:',
        'submit' => 'Submit',
        'reset' => 'Reset',
        'success' => 'Time slot added successfully!',
        'update_success' => 'Time slot updated successfully!',
        'error' => 'Error: ',
        'empty_error' => 'Start Time and End Time cannot be empty!',
        'time_error' => 'End Time must be after Start Time!',
        'list_title' => 'Time Slots List',
        'no_records' => 'No time slots found.',
        'sr_no' => '#',
        'actions' => 'Actions',
        'edit' => 'Edit',
        'save' => 'Save',
        'cancel' => 'Cancel',
        'duration' => 'Duration',
        'delete_confirm' => 'Are you sure you want to delete this time slot?'
    ]
];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    
    $time_error = '';
    if (strtotime($end_time) <= strtotime($start_time)) {
        $time_error = $translations[$lang]['time_error'];
    }
    
    if (!empty($start_time) && !empty($end_time) && empty($time_error)) {
        if (isset($_POST['slot_id']) && !empty($_POST['slot_id'])) {
            // Update existing time slot
            $slot_id = intval($_POST['slot_id']);
            $stmt = $conn->prepare("UPDATE time_slots SET start_time = ?, end_time = ? WHERE id = ?");
            $stmt->bind_param("ssi", $start_time, $end_time, $slot_id);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = $translations[$lang]['update_success'];
                $_SESSION['message_type'] = "success";
            }
        } else {
            // Insert new time slot
            $stmt = $conn->prepare("INSERT INTO time_slots (start_time, end_time) VALUES (?, ?)");
            $stmt->bind_param("ss", $start_time, $end_time);
            
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
        $_SESSION['message'] = empty($start_time) || empty($end_time) ? 
            $translations[$lang]['empty_error'] : $time_error;
        $_SESSION['message_type'] = "danger";
    }
}

// Handle deletion
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    
    $stmt = $conn->prepare("DELETE FROM time_slots WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = 'Time slot deleted successfully!';
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = $translations[$lang]['error'] . $stmt->error;
        $_SESSION['message_type'] = "danger";
    }
    
    $stmt->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once('meta_inc.php'); ?>
    <style>
        .time-picker {
            font-family: monospace;
            font-size: 16px;
        }
        .duration-badge {
            background-color: #f0f0f0;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 12px;
            display: inline-block;
        }
        .time-slot-card {
            transition: transform 0.2s;
        }
        .time-slot-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<!-- Dashboard -->
<div class="container">
    <div class="row">
        <div class="col-md-12">

            <!-- Panel for Add/Edit Time Slot -->
            <div class="panel panel-primary">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <?php 
                        echo isset($_GET['edit']) ? 'Edit Time Slot' : $translations[$lang]['add_title']; 
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
                        $slot_data = [
                            'id' => '', 
                            'start_time' => '09:00:00', 
                            'end_time' => '17:00:00'
                        ];
                        
                        if (isset($_GET['edit'])) {
                            $id = intval($_GET['edit']);
                            $result = $conn->query("SELECT id, start_time, end_time FROM time_slots WHERE id = $id");
                            if ($result->num_rows > 0) {
                                $slot_data = $result->fetch_assoc();
                                $edit_mode = true;
                            }
                        }
                        ?>
                        
                        <input type="hidden" name="slot_id" value="<?php echo $slot_data['id']; ?>">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="start_time"><?php echo $translations[$lang]['start_time_label']; ?></label>
                                    <input type="time" class="form-control time-picker" id="start_time" name="start_time" required
                                           value="<?php echo substr($slot_data['start_time'], 0, 5); ?>">
                                    <small class="help-block">Select the start time for this slot</small>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="end_time"><?php echo $translations[$lang]['end_time_label']; ?></label>
                                    <input type="time" class="form-control time-picker" id="end_time" name="end_time" required
                                           value="<?php echo substr($slot_data['end_time'], 0, 5); ?>">
                                    <small class="help-block">Select the end time for this slot</small>
                                </div>
                            </div>
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

            <!-- Panel for Time Slots List -->
            <div class="panel panel-info">
                <div class="panel-heading">
                    <h3 class="panel-title"><?php echo $translations[$lang]['list_title']; ?></h3>
                </div>
                <div class="panel-body">

                    <?php
                    $result = $conn->query("SELECT id, start_time, end_time FROM time_slots ORDER BY start_time ASC");
                    ?>

                    <?php if ($result->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th><?php echo $translations[$lang]['sr_no']; ?></th>
                                        <th><?php echo $translations[$lang]['start_time_label']; ?></th>
                                        <th><?php echo $translations[$lang]['end_time_label']; ?></th>
                                        <th><?php echo $translations[$lang]['duration']; ?></th>
                                        <th><?php echo $translations[$lang]['actions']; ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $counter = 1; 
                                    while ($row = $result->fetch_assoc()): 
                                        // Calculate duration in hours and minutes
                                        $start = new DateTime($row['start_time']);
                                        $end = new DateTime($row['end_time']);
                                        $interval = $start->diff($end);
                                        
                                        $duration = '';
                                        if ($interval->h > 0) {
                                            $duration .= $interval->h . ' hour' . ($interval->h > 1 ? 's' : '');
                                        }
                                        if ($interval->i > 0) {
                                            if ($duration) $duration .= ' ';
                                            $duration .= $interval->i . ' minute' . ($interval->i > 1 ? 's' : '');
                                        }
                                        if (empty($duration)) {
                                            $duration = '0 minutes';
                                        }
                                        
                                        $start_time_formatted = date('h:i A', strtotime($row['start_time']));
                                        $end_time_formatted = date('h:i A', strtotime($row['end_time']));
                                    ?>
                                        <tr>
                                            <td><?php echo $counter++; ?></td>
                                            <td>
                                                <strong><?php echo $start_time_formatted; ?></strong>
                                                <br>
                                                <small class="text-muted"><?php echo $row['start_time']; ?></small>
                                            </td>
                                            <td>
                                                <strong><?php echo $end_time_formatted; ?></strong>
                                                <br>
                                                <small class="text-muted"><?php echo $row['end_time']; ?></small>
                                            </td>
                                            <td>
                                                <span class="duration-badge">
                                                    <span class="glyphicon glyphicon-time"></span> 
                                                    <?php echo $duration; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="?edit=<?php echo $row['id']; ?>" class="btn btn-xs btn-warning">
                                                    <span class="glyphicon glyphicon-edit"></span> 
                                                    <?php echo $translations[$lang]['edit']; ?>
                                                </a>
                                                <a href="?delete=<?php echo $row['id']; ?>" class="btn btn-xs btn-danger" 
                                                   onclick="return confirm('<?php echo $translations[$lang]['delete_confirm']; ?>')">
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
                            <?php echo $translations[$lang]['no_records']; ?>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

            <!-- Optional: Quick Stats Panel -->
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title">Quick Statistics</h3>
                </div>
                <div class="panel-body">
                    <?php
                    $total_slots = $conn->query("SELECT COUNT(*) as total FROM time_slots")->fetch_assoc()['total'];
                    
                    // Get earliest and latest time slots
                    $earliest = $conn->query("SELECT MIN(start_time) as earliest FROM time_slots")->fetch_assoc()['earliest'];
                    $latest = $conn->query("SELECT MAX(end_time) as latest FROM time_slots")->fetch_assoc()['latest'];
                    ?>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="well well-sm text-center">
                                <h4><?php echo $total_slots; ?></h4>
                                <p class="text-muted">Total Time Slots</p>
                            </div>
                        </div>
                        <?php if ($earliest): ?>
                        <div class="col-md-4">
                            <div class="well well-sm text-center">
                                <h4><?php echo date('h:i A', strtotime($earliest)); ?></h4>
                                <p class="text-muted">Earliest Start Time</p>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($latest): ?>
                        <div class="col-md-4">
                            <div class="well well-sm text-center">
                                <h4><?php echo date('h:i A', strtotime($latest)); ?></h4>
                                <p class="text-muted">Latest End Time</p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="js/mobile_menu.js"></script>

<script>
$(document).ready(function(){
    // Add validation for time slots
    $('#start_time, #end_time').on('change', function() {
        var startTime = $('#start_time').val();
        var endTime = $('#end_time').val();
        
        if (startTime && endTime) {
            if (endTime <= startTime) {
                $('#end_time').addClass('is-invalid');
                $('.btn-success').prop('disabled', true);
                if (!$('#time-error-message').length) {
                    $('#end_time').after('<small id="time-error-message" class="text-danger">End time must be after start time</small>');
                }
            } else {
                $('#end_time').removeClass('is-invalid');
                $('.btn-success').prop('disabled', false);
                $('#time-error-message').remove();
            }
        }
    });
    
    // Initialize time inputs with default values if empty
    if (!$('#start_time').val()) {
        $('#start_time').val('09:00');
    }
    if (!$('#end_time').val()) {
        $('#end_time').val('17:00');
    }
});
</script>

</body>
<?php if (isset($conn)) $conn->close(); ?>
</html>