<?php
/**
 * File: datesheet_list.php
 * Purpose: Displays a list of all datesheets for different classes and exams with View, Edit, and Print options.
 * Features:
 * - Shows a table with S.No, Class, Exam Type, and Actions (View, Edit, Print).
 * - View: Links to view_datesheet.php to display read-only datesheet details.
 * - Edit: Links to datesheet.php for editing the datesheet.
 * - Print: Links to print_datesheet.php for a printable version.
 * - Uses Bootstrap 3.4.1 for styling consistency.
 * - Includes CSRF token initialization for potential future forms.
 * Dependencies: security.php, conn_inc.php, navbar.php
 */

require_once('security.php');

// Initialize session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Generate CSRF token if not set
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Create database connection
require_once('conn_inc.php');

/**
 * Fetches all datesheets with class and exam details
 * @param mysqli $conn Database connection
 * @return array Datesheet data
 */
function getDatesheetList($conn) {
    $datesheets = [];
    $stmt = $conn->prepare("
        SELECT ae.id AS arrange_exam_id, c.id AS class_id, c.title AS class_title, et.title AS exam_type_title
        FROM arrange_exam ae
        JOIN classes c ON ae.class_id = c.id
        JOIN exam_types et ON ae.exam_type_id = et.id
        WHERE EXISTS (
            SELECT 1 FROM exam_datesheet ed WHERE ed.arrange_exam_id = ae.id
        )
        ORDER BY c.title, et.title
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $datesheets[] = [
            'arrange_exam_id' => $row['arrange_exam_id'],
            'class_id' => $row['class_id'],
            'class_title' => $row['class_title'],
            'exam_type_title' => $row['exam_type_title']
        ];
    }
    $stmt->close();
    return $datesheets;
}

// Log page access
error_log("Accessing datesheet_list.php", 3, 'errors.log');

// Load datesheet list
$datesheets = getDatesheetList($conn);

// Get message from URL if present
$message = isset($_GET['message']) ? htmlspecialchars($_GET['message']) : '';
$message_type = isset($_GET['message_type']) ? htmlspecialchars($_GET['message_type']) : '';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Datesheet List</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
    <style>
        .table th, .table td {
            text-align: left;
            vertical-align: middle;
            padding: 12px;
        }
        .action-btn {
            margin-right: 5px;
            padding: 6px 12px;
            border-radius: 4px;
        }
        .alert {
            margin-bottom: 15px;
        }
        .page-title {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #337ab7;
        }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="container">
    <div class="row">
        <div class="col-md-12 ">

            <h3 class="page-title">Datesheet List</h3>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <div class="panel panel-primary">
                <div class="panel-heading">
                    <h3 class="panel-title">All Datesheets</h3>
                </div>
                <div class="panel-body">
                    <?php if (!empty($datesheets)): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th style="width: 5%;">S.No</th>
                                        <th style="width: 35%;">Class</th>
                                        <th style="width: 35%;">Exam Type</th>
                                        <th style="width: 25%;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $counter = 1; ?>
                                    <?php foreach ($datesheets as $ds): ?>
                                        <tr>
                                            <td><?php echo $counter++; ?></td>
                                            <td><?php echo htmlspecialchars($ds['class_title']); ?></td>
                                            <td><?php echo htmlspecialchars($ds['exam_type_title']); ?></td>
                                            <td>
                                                <a href="view_datesheet.php?exam_id=<?php echo $ds['arrange_exam_id']; ?>&class_id=<?php echo $ds['class_id']; ?>&exam_type=<?php echo urlencode($ds['exam_type_title']); ?>" 
                                                   class="btn btn-xs btn-info action-btn">
                                                    <span class="glyphicon glyphicon-eye-open"></span> View
                                                </a>
                                                <a href="edit_datesheet.php?exam_id=<?php echo $ds['arrange_exam_id']; ?>&class_id=<?php echo $ds['class_id']; ?>&exam_type=<?php echo urlencode($ds['exam_type_title']); ?>" 
   class="btn btn-xs btn-primary action-btn">
    <span class="glyphicon glyphicon-edit"></span> Edit
</a>
                                                <a href="print_datesheet.php?exam_id=<?php echo $ds['arrange_exam_id']; ?>&class_id=<?php echo $ds['class_id']; ?>&exam_type=<?php echo urlencode($ds['exam_type_title']); ?>" 
                                                   class="btn btn-xs btn-success action-btn" target="_blank">
                                                    <span class="glyphicon glyphicon-print"></span> Print
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info text-center">
                            No datesheets found. Create a datesheet from the exam management page.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="text-right">
                <a href="arrange_exam.php" class="btn btn-default">
                    <span class="glyphicon glyphicon-remove"></span> Back to Exam Management
                </a>
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
});
</script>

</body>
</html>
<?php
$conn->close();
?>