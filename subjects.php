<?php
ob_start();

require_once('security.php');

$edit_mode = false;



// Initialize session if not started

if (session_status() == PHP_SESSION_NONE) {

    session_start();

}



// Generate CSRF token if not set

if (!isset($_SESSION['csrf_token'])) {

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

}



// Create database connection

require_once('conn_inc.php');



if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {

        die("Invalid CSRF token");

    }



    // Sanitize input

    $subject_id = isset($_POST['id']) ? intval($_POST['id']) : 0;

    $title = trim($_POST['title']);

    $type = in_array($_POST['type'], ['standalone', 'composite']) ? $_POST['type'] : 'standalone';

    $marks = ($type === 'standalone' && isset($_POST['marks'])) ? floatval($_POST['marks']) : 0;

    $sub_subject_titles = isset($_POST['sub_subject_titles']) && $type === 'composite' ? $_POST['sub_subject_titles'] : [];

    $sub_subject_marks = isset($_POST['sub_subject_marks']) && $type === 'composite' ? $_POST['sub_subject_marks'] : [];



    if ($subject_id > 0) {

        // Update existing subject

        $stmt = $conn->prepare("UPDATE subjects SET title = ?, type = ?, total_marks = ? WHERE id = ?");

        $stmt->bind_param("ssdi", $title, $type, $marks, $subject_id);



        if ($stmt->execute()) {

            // Update sub-subjects only for composite subjects

            $stmt_delete = $conn->prepare("DELETE FROM sub_subjects WHERE subject_id = ?");

            $stmt_delete->bind_param("i", $subject_id);

            $stmt_delete->execute();

            $stmt_delete->close();



            if ($type === 'composite') {

                foreach ($sub_subject_titles as $index => $sub_title) {

                    if (!empty(trim($sub_title))) {

                        $sub_marks = isset($sub_subject_marks[$index]) ? floatval($sub_subject_marks[$index]) : 0;

                        $stmt_sub = $conn->prepare("INSERT INTO sub_subjects (subject_id, title, marks) VALUES (?, ?, ?)");

                        $stmt_sub->bind_param("isd", $subject_id, $sub_title, $sub_marks);

                        if (!$stmt_sub->execute()) {

                            error_log("Error inserting sub-subject: " . $stmt_sub->error, 3, 'errors.log');

                        }

                        $stmt_sub->close();

                    }

                }

            }

            $_SESSION['message'] = "Subject updated successfully.";

            $_SESSION['message_type'] = 'success';

        } else {

            error_log("Error updating subject: " . $stmt->error, 3, 'errors.log');

            $_SESSION['message'] = "Error updating subject: " . $stmt->error;

            $_SESSION['message_type'] = 'danger';

        }

        $stmt->close();

    } else {

        // Insert new subject

        $stmt = $conn->prepare("INSERT INTO subjects (title, type, total_marks) VALUES (?, ?, ?)");

        $stmt->bind_param("ssd", $title, $type, $marks);



        if ($stmt->execute()) {

            $subject_id = $conn->insert_id;

            if ($type === 'composite') {

                foreach ($sub_subject_titles as $index => $sub_title) {

                    if (!empty(trim($sub_title))) {

                        $sub_marks = isset($sub_subject_marks[$index]) ? floatval($sub_subject_marks[$index]) : 0;

                        $stmt_sub = $conn->prepare("INSERT INTO sub_subjects (subject_id, title, marks) VALUES (?, ?, ?)");

                        $stmt_sub->bind_param("isd", $subject_id, $sub_title, $sub_marks);

                        if (!$stmt_sub->execute()) {

                            error_log("Error inserting sub-subject: " . $stmt_sub->error, 3, 'errors.log');

                        }

                        $stmt_sub->close();

                    }

                }

            }

            $_SESSION['message'] = "Subject added successfully.";

            $_SESSION['message_type'] = 'success';

        } else {

            error_log("Error adding subject: " . $stmt->error, 3, 'errors.log');

            $_SESSION['message'] = "Error adding subject: " . $stmt->error;

            $_SESSION['message_type'] = 'danger';

        }

        $stmt->close();

    }

    header("Location: " . $_SERVER['PHP_SELF']);

    exit();

}



// Handle subject deletion

if (isset($_GET['delete'])) {

    $id = intval($_GET['delete']);

    

    $stmt = $conn->prepare("DELETE FROM sub_subjects WHERE subject_id = ?");

    $stmt->bind_param("i", $id);

    $stmt->execute();

    $stmt->close();



    $stmt = $conn->prepare("DELETE FROM subjects WHERE id = ?");

    $stmt->bind_param("i", $id);

    

    if ($stmt->execute()) {

        $_SESSION['message'] = 'Subject deleted successfully!';

        $_SESSION['message_type'] = 'success';

    } else {

        error_log("Database error: " . $stmt->error, 3, 'errors.log');

        $_SESSION['message'] = 'Error: An unexpected error occurred.';

        $_SESSION['message_type'] = 'danger';

    }

    

    $stmt->close();

    header("Location: " . $_SERVER['PHP_SELF']);

    exit();

}



// Handle edit mode

$subject_data = [];

$sub_subjects = [];

if (isset($_GET['edit'])) {

    $edit_mode = true;

    $id = intval($_GET['edit']);

    $stmt = $conn->prepare("SELECT id, title, type, total_marks FROM subjects WHERE id = ?");

    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {

        $result = $stmt->get_result();

        $subject_data = $result->fetch_assoc();

        if (!$subject_data) {

            error_log("No subject found for ID: $id", 3, 'errors.log');

            $_SESSION['message'] = "Subject not found.";

            $_SESSION['message_type'] = 'danger';

            header("Location: " . $_SERVER['PHP_SELF']);

            exit();

        }

    } else {

        error_log("Error fetching subject: " . $stmt->error, 3, 'errors.log');

    }

    $stmt->close();



    // Fetch sub-subjects for composite subjects

    if ($subject_data['type'] === 'composite') {

        $stmt = $conn->prepare("SELECT id, title, marks FROM sub_subjects WHERE subject_id = ? ORDER BY title");

        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {

            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {

                $sub_subjects[] = $row;

            }

            if (empty($sub_subjects)) {

                error_log("No sub-subjects found for composite subject ID: $id", 3, 'errors.log');

            }

        } else {

            error_log("Error fetching sub-subjects: " . $stmt->error, 3, 'errors.log');

        }

        $stmt->close();

    }

}



// Get data for listing

$result = $conn->query("

    SELECT s.id, s.title, s.type, s.total_marks,

           GROUP_CONCAT(CONCAT(ss.title, ' (', ss.marks, ')') ORDER BY ss.title SEPARATOR ', ') as sub_subjects

    FROM subjects s

    LEFT JOIN sub_subjects ss ON s.id = ss.subject_id

    GROUP BY s.id

    ORDER BY s.title

");

$subjects = [];

if ($result) {

    while ($row = $result->fetch_assoc()) {

        $subjects[] = $row;

    }

    if (empty($subjects)) {

        error_log("No subjects found in database", 3, 'errors.log');

    }

    $result->close();

} else {

    error_log("Error fetching subjects: " . $conn->error, 3, 'errors.log');

}



?>



<!DOCTYPE html>

<html lang="en">

<head>

    <title>Subject Management</title>

    <meta charset="utf-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>

    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>

    <style>

        .table th, .table td {

            text-align: left;

        }

        .checkbox label {

            padding-left: 20px;

        }

        .sub-subjects-container {

            max-height: 300px;

            overflow-y: auto;

            border: 1px solid #ccc;

            padding: 10px;

            margin-bottom: 10px;

        }

        .sub-subject-item {

            margin-bottom: 10px;

            padding-bottom: 10px;

            border-bottom: 1px solid #eee;

        }

        .sub-subject-item:last-child {

            border-bottom: none;

        }

        .subject-header {

            font-weight: bold;

            font-size: 16px;

        }

        .subject-row {

            background-color: #e6f3ff;

        }

        .marks-input {

            width: 100px;

        }

        /* Mobile-specific styles for screens smaller than 768px */
@media (max-width: 768px) {
    .table-responsive {
        overflow-x: hidden;
        -webkit-overflow-scrolling: touch;
        margin-bottom: 20px;
    }

    .table {
        width: 100%;
        background: transparent;
    }

    thead {
        display: none;
    }

    tbody {
        display: block;
    }

    tr {
        display: block;
        margin-bottom: 20px;
        background: #ffffff;
        border: 1px solid #dfe4ea;
        border-radius: 10px;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
        padding: 15px;
    }

    td {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 10px 15px;
        border-bottom: 1px solid #eceff1;
        font-size: 0.95rem;
        color: #2c3e50;
    }

    td:last-child {
        border-bottom: none;
    }

    td::before {
        content: attr(data-label);
        font-weight: 600;
        font-size: 1rem;
        color: #1a252f;
        flex: 0 0 45%;
        text-align: left;
    }

    td > *:not(:first-child) {
        margin-left: 0.4rem !important;
    }

    .btn-xs {
        display: inline-block;
        width: 100%;
        padding: 10px;
        font-size: 1rem;
        border-radius: 6px;
        margin: 5px 0;
        text-align: center;
        transition: background-color 0.2s ease-in-out;
    }

    /* Extra small screens (mobiles < 576px) */
    @media (max-width: 576px) {
        tr {
            margin-bottom: 15px;
            padding: 12px;
        }

        td {
            font-size: 0.9rem;
            padding: 8px 12px;
        }

        td::before {
            font-size: 0.95rem;
            flex: 0 0 50%;
        }

        .btn-xs {
            font-size: 0.95rem;
            padding: 8px;
        }
    }
}
    </style>

</head>

<body>



<?php require_once('navbar.php'); ?>



<!-- Dashboard -->

<div class="container">

    <div class="row">

        <div class="col-md-12">



            <!-- Panel for Add/Edit Subjects -->

            <div class="panel panel-primary">

                <div class="panel-heading">

                    <h3 class="panel-title"><?php echo $edit_mode ? 'Edit Subject' : 'Add Subject'; ?></h3>

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

                            <input type="hidden" name="id" value="<?php echo $subject_data['id']; ?>">

                        <?php endif; ?>

                        

                        <div class="form-group">

                            <label>Subject Title:</label>

                            <input type="text" class="form-control" name="title" required 

                                   value="<?php echo $edit_mode ? htmlspecialchars($subject_data['title']) : ''; ?>">

                        </div>

                        

                        <div class="form-group">

                            <label>Subject Type:</label>

                            <select name="type" id="subject_type" class="form-control" required>

                                <option value="standalone" <?php echo ($edit_mode && $subject_data['type'] === 'standalone') ? 'selected' : ''; ?>>Standalone</option>

                                <option value="composite" <?php echo ($edit_mode && $subject_data['type'] === 'composite') ? 'selected' : ''; ?>>Composite</option>

                            </select>

                        </div>

                        

                        <div class="form-group" id="marks_group" style="<?php echo ($edit_mode && $subject_data['type'] !== 'standalone') ? 'display: none;' : ''; ?>">

                            <label>Marks:</label>

                            <input type="number" class="form-control marks-input" name="marks" step="0.1" min="0" 

                                   value="<?php echo $edit_mode && $subject_data['type'] === 'standalone' ? htmlspecialchars($subject_data['total_marks']) : ''; ?>" 

                                   <?php echo ($edit_mode && $subject_data['type'] !== 'standalone') ? '' : 'required'; ?>>

                        </div>

                        

                        <div class="form-group" id="sub_subjects_group" style="<?php echo ($edit_mode && $subject_data['type'] !== 'composite') ? 'display: none;' : ''; ?>">

                            <label>Sub-Subjects:</label>

                            <div class="sub-subjects-container" id="sub_subjects_list">

                                <?php if ($edit_mode && !empty($sub_subjects) && $subject_data['type'] === 'composite'): ?>

                                    <?php foreach ($sub_subjects as $index => $sub_subject): ?>

                                        <div class="sub-subject-item" id="sub_subject_item_<?php echo $index; ?>">

                                            <div class="input-group">

                                                <input type="text" class="form-control" name="sub_subject_titles[]" 

                                                       value="<?php echo htmlspecialchars($sub_subject['title']); ?>" 

                                                       placeholder="Enter sub-subject title" required>

                                                <input type="number" class="form-control marks-input" name="sub_subject_marks[]" 

                                                       value="<?php echo htmlspecialchars($sub_subject['marks']); ?>" 

                                                       placeholder="Marks" step="0.1" min="0" required>

                                                <span class="input-group-btn">

                                                    <button type="button" class="btn btn-danger remove-sub-subject" 

                                                            data-id="<?php echo $index; ?>">

                                                        <span class="glyphicon glyphicon-remove"></span>

                                                    </button>

                                                </span>

                                            </div>

                                        </div>

                                    <?php endforeach; ?>

                                <?php elseif ($edit_mode && $subject_data['type'] === 'composite'): ?>

                                    <p class="text-muted">No sub-subjects found for this composite subject.</p>

                                <?php endif; ?>

                            </div>

                            <button type="button" id="add_sub_subject_btn" class="btn btn-primary">

                                <span class="glyphicon glyphicon-plus"></span> Add Sub-Subject

                            </button>

                        </div>



                        <div class="text-right">

                            <button type="submit" class="btn btn-success">

                                <span class="glyphicon glyphicon-<?php echo $edit_mode ? 'refresh' : 'plus'; ?>"></span> 

                                <?php echo $edit_mode ? 'Save' : 'Submit'; ?>

                            </button>

                            <?php if ($edit_mode): ?>

                                <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-default">

                                    <span class="glyphicon glyphicon-remove"></span> Cancel

                                </a>

                            <?php else: ?>

                                <button type="reset" class="btn btn-default">

                                    <span class="glyphicon glyphicon-refresh"></span> Reset

                                </button>

                            <?php endif; ?>

                        </div>

                    </form>

                </div>

            </div>



            <!-- Panel for Subjects List -->

            <div class="panel panel-info">

                <div class="panel-heading">

                    <h3 class="panel-title">Subjects List</h3>

                </div>

                <div class="panel-body">

                    <?php if (!empty($subjects)): ?>

                        <div class="table-responsive">

                            <table class="table table-bordered table-hover">

                                <thead>

                                    <tr>

                                        <th>#</th>

                                        <th>Subject Title</th>

                                        <th>Type</th>

                                        <th>Marks</th>

                                        <th>Sub-Subjects</th>

                                        <th>Actions</th>

                                    </tr>

                                </thead>

                                <tbody>

                                    <?php $counter = 1; ?>

                                    <?php foreach ($subjects as $subject): ?>

                                        <tr class="subject-row">

                                            <td><?php echo $counter++; ?></td>

                                            <td><?php echo htmlspecialchars($subject['title']); ?></td>

                                            <td><?php echo htmlspecialchars($subject['type']); ?></td>

                                            <td>

                                                <?php 

                                                if ($subject['type'] === 'standalone') {

                                                    echo htmlspecialchars($subject['total_marks']);

                                                } else {

                                                    // Calculate total marks for composite subjects

                                                    $total_marks = 0;

                                                    if ($subject['sub_subjects']) {

                                                        $sub_subjects_list = explode(', ', $subject['sub_subjects']);

                                                        foreach ($sub_subjects_list as $sub) {

                                                            preg_match('/\((\d+\.?\d*)\)/', $sub, $matches);

                                                            $total_marks += isset($matches[1]) ? floatval($matches[1]) : 0;

                                                        }

                                                    }

                                                    echo $total_marks > 0 ? htmlspecialchars($total_marks) : 'N/A';

                                                }

                                                ?>

                                            </td>

                                            <td>

                                                <?php 

                                                if ($subject['type'] === 'composite') {

                                                    echo $subject['sub_subjects'] ? htmlspecialchars($subject['sub_subjects']) : 'No sub-subjects';

                                                } else {

                                                    echo 'None';

                                                }

                                                ?>

                                            </td>

                                            <td>

                                                <a href="?edit=<?php echo $subject['id']; ?>" class="btn btn-xs btn-warning">

                                                    <span class="glyphicon glyphicon-edit"></span> Edit

                                                </a>

                                                <a href="?delete=<?php echo $subject['id']; ?>" class="btn btn-xs btn-danger" 

                                                   onclick="return confirm('Are you sure you want to delete this subject?')">

                                                    <span class="glyphicon glyphicon-trash"></span> Delete

                                                </a>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php else: ?>

                        <div class="alert alert-info text-center">

                            No subjects found.

                        </div>

                    <?php endif; ?>

                </div>

            </div>



        </div>

    </div>

</div>



<script>

$(document).ready(function() {

    // Auto-dismiss alerts after 5 seconds

    setTimeout(function() {

        $('.alert').fadeOut('slow');

    }, 5000);



    // Add new sub-subject input

    var subSubjectCounter = <?php echo $edit_mode && $subject_data['type'] === 'composite' ? count($sub_subjects) : 0; ?>;

    $('#add_sub_subject_btn').click(function() {

        var newSubSubjectHtml = '<div class="sub-subject-item" id="sub_subject_item_' + subSubjectCounter + '">' +

            '<div class="input-group">' +

            '<input type="text" class="form-control" name="sub_subject_titles[]" placeholder="Enter sub-subject title" required>' +

            '<input type="number" class="form-control marks-input" name="sub_subject_marks[]" placeholder="Marks" step="0.1" min="0" required>' +

            '<span class="input-group-btn">' +

            '<button type="button" class="btn btn-danger remove-sub-subject" data-id="' + subSubjectCounter + '">' +

            '<span class="glyphicon glyphicon-remove"></span>' +

            '</button>' +

            '</span>' +

            '</div>' +

            '</div>';

        

        $('#sub_subjects_list').append(newSubSubjectHtml);

        subSubjectCounter++;

        console.log('Added sub-subject input #' + subSubjectCounter);

    });



    // Remove sub-subject input

    $(document).on('click', '.remove-sub-subject', function() {

        var id = $(this).data('id');

        $('#sub_subject_item_' + id).remove();

        console.log('Removed sub-subject input #' + id);

    });



    // Toggle sub-subjects and marks sections based on subject type

    $('#subject_type').change(function() {

        var type = $(this).val();

        console.log('Subject type changed to: ' + type);

        if (type === 'composite') {

            $('#sub_subjects_group').show();

            $('#marks_group').hide();

            $('#marks_group input').removeAttr('required');

        } else {

            $('#sub_subjects_group').hide();

            $('#sub_subjects_list').html('');

            subSubjectCounter = 0;

            $('#marks_group').show();

            $('#marks_group input').attr('required', 'required');

        }

    });



    // Initialize sections visibility

    <?php if ($edit_mode && $subject_data['type'] === 'composite'): ?>

        console.log('Edit mode: composite subject with <?php echo count($sub_subjects); ?> sub-subjects');

        $('#marks_group').hide();

        $('#sub_subjects_group').show();

    <?php elseif ($edit_mode && $subject_data['type'] === 'standalone'): ?>

        console.log('Edit mode: standalone subject');

        $('#sub_subjects_group').hide();

        $('#marks_group').show();

    <?php else: ?>

        console.log('Add mode: default to standalone');

        $('#marks_group').show();

        $('#sub_subjects_group').hide();

    <?php endif; ?>

});


// Add data-label attributes for mobile card view
$('.table tbody tr').each(function() {
    var cells = $(this).find('td');
    cells.eq(0).attr('data-label', '#');
    cells.eq(1).attr('data-label', 'Subject Title');
    cells.eq(2).attr('data-label', 'Type');
    cells.eq(3).attr('data-label', 'Marks');
    cells.eq(4).attr('data-label', 'Sub-Subjects');
    cells.eq(5).attr('data-label', 'Actions');
});
</script>



</body>

</html>

<?php

// Close database connection

$conn->close();

?>