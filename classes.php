<?php
ob_start();
require_once('security.php');

if (session_status() == PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$lang = 'en';

$translations = [
    'en' => [
        'title' => 'Classes Management',
        'add_title' => 'Add New Class',
        'edit_title' => 'Edit Class',
        'class_label' => 'Class Title:',
        'date_label' => 'Date:',
        'sections_label' => 'Sections (select or type new):',
        'submit' => 'Submit',
        'reset' => 'Reset',
        'save' => 'Save',
        'cancel' => 'Cancel',
        'success' => 'Class added successfully!',
        'update_success' => 'Class updated successfully!',
        'delete_success' => 'Class deleted successfully!',
        'error' => 'Error:',
        'empty_error' => 'Required fields cannot be empty!',
        'no_records' => 'No classes found.',
        'sr_no' => '#',
        'actions' => 'Actions',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'invalid_csrf' => 'Invalid request!'
    ]
];

require_once('conn_inc.php');

$edit_mode = false;
$class_data = ['id' => '', 'title' => '', 'dated' => date('Y-m-d'), 'sections' => []];

// Edit mode
if (isset($_GET['edit'])) {
    $id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT id, title, dated FROM classes WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows > 0) {
        $class_data = $res->fetch_assoc();
        $edit_mode = true;
        $sec_res = $conn->query("SELECT section_id FROM class_sections WHERE class_id=" . $class_data['id']);
        while ($row = $sec_res->fetch_assoc()) $class_data['sections'][] = $row['section_id'];
    }
    $stmt->close();
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['message'] = $translations[$lang]['invalid_csrf'];
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $title = trim($_POST['title']);
    $dated = $_POST['dated'];
    
    $sections = isset($_POST['sections']) ? $_POST['sections'] : [];
    if (empty($sections)) {
        $sections = 0;
    } else {
        $sections = isset($_POST['sections']) ? $_POST['sections'] : [];
    }
    if (empty($title)) {
        $_SESSION['message'] = $translations[$lang]['empty_error'];
        $_SESSION['message_type'] = 'danger';
    } else {
        // Start transaction
        $conn->begin_transaction();
        try {
            // Insert or update class
            if (isset($_POST['id']) && !empty($_POST['id'])) {
                $id = intval($_POST['id']);
                $stmt = $conn->prepare("UPDATE classes SET title=?, dated=? WHERE id=?");
                $stmt->bind_param("ssi", $title, $dated, $id);
                $stmt->execute();
                $stmt->close();
            } else {
                // Check if class already exists
                $stmt = $conn->prepare("SELECT id FROM classes WHERE title=? LIMIT 1");
                $stmt->bind_param("s", $title);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res->num_rows > 0) {
                    $row = $res->fetch_assoc();
                    $id = $row['id'];
                } else {
                    $stmt_insert = $conn->prepare("INSERT INTO classes(title, dated) VALUES(?,?)");
                    $stmt_insert->bind_param("ss", $title, $dated);
                    $stmt_insert->execute();
                    $id = $stmt_insert->insert_id;
                    $stmt_insert->close();
                }
                $stmt->close();
            }

            // Handle sections
            $section_ids = [];
            foreach ($sections as $sec_title) {
                $sec_title = trim($sec_title);
                if (empty($sec_title)) continue;
                // Check if section exists
                $stmt = $conn->prepare("SELECT id FROM sections WHERE title=? LIMIT 1");
                $stmt->bind_param("s", $sec_title);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res->num_rows > 0) {
                    $row = $res->fetch_assoc();
                    $sec_id = $row['id'];
                } else {
                    $stmt_insert = $conn->prepare("INSERT INTO sections(title) VALUES(?)");
                    $stmt_insert->bind_param("s", $sec_title);
                    $stmt_insert->execute();
                    $sec_id = $stmt_insert->insert_id;
                    $stmt_insert->close();
                }
                $stmt->close();
                $section_ids[] = $sec_id;
            }

            // Delete old links and insert new links
            $conn->query("DELETE FROM class_sections WHERE class_id=$id");
            $stmt = $conn->prepare("INSERT INTO class_sections(class_id, section_id) VALUES(?,?)");
            foreach ($section_ids as $sec_id) {
                $stmt->bind_param("ii", $id, $sec_id);
                $stmt->execute();
            }
            $stmt->close();

            $conn->commit();
            $_SESSION['message'] = $edit_mode ? $translations[$lang]['update_success'] : $translations[$lang]['success'];
            $_SESSION['message_type'] = 'success';
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['message'] = $translations[$lang]['error'] . " " . $e->getMessage();
            $_SESSION['message_type'] = 'danger';
        }
    }
}

// Handle delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $conn->begin_transaction();
    try {
        $conn->query("DELETE FROM class_sections WHERE class_id=$id");
        $stmt = $conn->prepare("DELETE FROM classes WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
        $_SESSION['message'] = $translations[$lang]['delete_success'];
        $_SESSION['message_type'] = 'success';
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['message'] = $translations[$lang]['error'] . " " . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Fetch all sections
$sections_arr = [];
$res = $conn->query("SELECT id, title FROM sections ORDER BY title");
while ($row = $res->fetch_assoc()) $sections_arr[$row['id']] = $row['title'];

// Fetch all class titles
$class_titles = [];
$res = $conn->query("SELECT title FROM classes ORDER BY title");
while ($row = $res->fetch_assoc()) $class_titles[] = $row['title'];

// Fetch all classes with sections
$classes = [];
$res = $conn->query("
    SELECT c.id, c.title, c.dated, GROUP_CONCAT(s.title SEPARATOR ', ') as sections
    FROM classes c
    LEFT JOIN class_sections cs ON c.id=cs.class_id
    LEFT JOIN sections s ON cs.section_id=s.id
    GROUP BY c.id
    ORDER BY c.title
");
while ($row = $res->fetch_assoc()) $classes[] = $row;
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<title><?php echo $translations[$lang]['title']; ?></title>



<style>
body {
    font-size: 16px;
    line-height: 1.5;
    padding-top: 20px;
}

.container {
    padding: 0 15px;
}

/* Form controls */
.form-control {
    min-height: 44px;
    font-size: 16px;
    border-radius: 4px;
}

.form-group {
    margin-bottom: 20px;
}

/* Select2 adjustments */
.select2-container {
    width: 100% !important;
}

.select2-container .select2-selection--single,
.select2-container .select2-selection--multiple {
    min-height: 44px;
    font-size: 16px;
    border-radius: 4px;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 44px;
}

.select2-container--default .select2-selection--multiple .select2-selection__choice {
    font-size: 14px;
    padding: 5px 10px;
    margin: 5px;
}

.select2-container--default .select2-selection__clear {
    margin-top: 10px;
}

/* Buttons */
.btn {
    font-size: 16px;
    padding: 10px 16px;
    min-width: 100px;
    margin: 5px;
    border-radius: 4px;
}

.text-right {
    text-align: right;
}

/* Table */
.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    position: relative;
}

.table {
    width: 100%;
    margin-bottom: 20px;
    table-layout: auto;
}

.table th, .table td {
    vertical-align: middle;
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}

.table th {
    background-color: #f5f5f5;
    font-weight: 600;
    white-space: nowrap;
}

.table td {
    white-space: normal; /* Allow wrapping for long content */
}

.table .class-info {
    min-width: 200px; /* Ensure enough space for combined info */
}

/* Actions column */
.table th.actions,
.table td.actions {
    min-width: 120px; /* Ensure enough space for buttons */
    white-space: nowrap; /* Prevent buttons from wrapping */
    position: -webkit-sticky; /* For Safari */
    position: sticky;
    right: 0;
    background-color: #fff; /* Ensure buttons are visible */
    z-index: 5; /* Keep above other cells */
}

.table th.actions {
    background-color: #f5f5f5;
    z-index: 10; /* Higher z-index for header */
}

/* Style for combined class info */
.class-info div {
    margin-bottom: 5px;
}

.class-info .title {
    font-weight: bold;
}

.class-info .sections,
.class-info .date {
    color: #555;
    font-size: 0.9em;
}

/* Mobile styles */
@media (max-width: 767px) {
    .container {
        padding: 0 10px;
    }

    .panel {
        margin: 10px 0;
    }

    .panel-body {
        padding: 15px;
    }

    .form-group {
        margin-bottom: 15px;
    }

    .btn {
        width: 100%;
        margin: 5px 0;
    }

    .text-right {
        text-align: center;
    }

    /* Table adjustments for mobile */
    .table-responsive {
        border: none;
        margin-bottom: 15px;
        -webkit-overflow-scrolling: touch;
    }

    .table {
        min-width: 400px; /* Reduced for two columns */
        font-size: 14px; /* Smaller font for mobile */
    }

    .table th, .table td {
        padding: 8px; /* Reduced padding for mobile */
    }

    .table th {
        font-size: 13px;
        position: sticky;
        top: 0;
        z-index: 15;
        background-color: #f5f5f5;
    }

    .table td {
        font-size: 13px;
    }

    .table .class-info {
        min-width: 150px; /* Adjusted for mobile */
    }

    .table th.actions,
    .table td.actions {
        min-width: 100px; /* Smaller min-width for mobile */
        background-color: #fff;
        box-shadow: -5px 0 5px -5px rgba(0, 0, 0, 0.2); /* Subtle shadow */
    }

    .table .btn {
        display: inline-block;
        width: auto;
        min-width: 50px; /* Smaller buttons for mobile */
        font-size: 12px;
        padding: 5px 8px;
        margin: 0 3px;
        line-height: 1.2;
        border-radius: 3px;
    }

    .class-info .sections,
    .class-info .date {
        font-size: 0.85em;
    }
}

/* Medium screens */
@media (min-width: 768px) and (max-width: 991px) {
    .btn {
        font-size: 14px;
        padding: 8px 12px;
    }

    .table th, .table td {
        font-size: 15px;
        padding: 10px;
    }

    .table th.actions,
    .table td.actions {
        min-width: 110px;
    }

    .table .class-info {
        min-width: 180px;
    }
}
</style>
</head>

<body>
<?php require_once('navbar.php'); ?>

<div class="container">
    <div class="row">
        <div class="col-xs-12">

            <!-- Add/Edit Class -->
            <div class="panel panel-primary">
                <div class="panel-heading">
                    <h3 class="panel-title"><?php echo $edit_mode ? $translations[$lang]['edit_title'] : $translations[$lang]['add_title']; ?></h3>
                </div>
                <div class="panel-body">
                    <?php if (isset($_SESSION['message'])): ?>
                        <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible">
                            <button type="button" class="close" data-dismiss="alert">&times;</button>
                            <?php echo $_SESSION['message']; unset($_SESSION['message'], $_SESSION['message_type']); ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <?php if ($edit_mode): ?>
                            <input type="hidden" name="id" value="<?php echo $class_data['id']; ?>">
                        <?php endif; ?>

                        <div class="form-group">
                            <label><?php echo $translations[$lang]['class_label']; ?></label>
                            <select class="form-control" id="class_title" name="title" required>
                                <option value="" disabled <?php echo $edit_mode ? '' : 'selected'; ?>>Select or type a class title</option>
                                <?php foreach ($class_titles as $title): ?>
                                    <option value="<?php echo htmlspecialchars($title); ?>" <?php echo ($edit_mode && $class_data['title'] === $title) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="help-block">Type a new class title and press Enter to add.</p>
                        </div>

                        <div class="form-group">
                            <label><?php echo $translations[$lang]['sections_label']; ?></label>
                            <select class="form-control" id="sections" name="sections[]" multiple="multiple">
                                <?php foreach ($sections_arr as $id => $title): ?>
                                    <option value="<?php echo htmlspecialchars($title); ?>" <?php echo in_array($id, $class_data['sections']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="help-block">Type new sections and press Enter to add.</p>
                        </div>

                        <div class="form-group">
                            <label><?php echo $translations[$lang]['date_label']; ?></label>
                            <input type="date" class="form-control" name="dated" value="<?php echo $class_data['dated']; ?>" required>
                        </div>

                        <div class="text-right">
                            <button type="submit" class="btn btn-success"><?php echo $edit_mode ? $translations[$lang]['save'] : $translations[$lang]['submit']; ?></button>
                            <?php if ($edit_mode): ?>
                                <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-default"><?php echo $translations[$lang]['cancel']; ?></a>
                            <?php else: ?>
                                <button type="reset" class="btn btn-default"><?php echo $translations[$lang]['reset']; ?></button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Classes List -->
            <div class="panel panel-info">
                <div class="panel-heading">
                    <h3 class="panel-title"><?php echo $translations[$lang]['title']; ?></h3>
                </div>
                <div class="panel-body">
                    <?php if (!empty($classes)): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th>Class Information</th>
                                        <th class="actions"><?php echo $translations[$lang]['actions']; ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $counter = 1; foreach ($classes as $row): ?>
                                        <tr>
                                            <td class="class-info">
                                                <div class="title"><?php echo htmlspecialchars($row['title']); ?></div>
                                                <div class="sections"><?php echo htmlspecialchars($row['sections'] ?: 'No Sections'); ?></div>
                                                <div class="date"><?php echo $row['dated'] ? date('d M Y', strtotime($row['dated'])) : 'No Date'; ?></div>
                                            </td>
                                            <td class="actions">
                                                <a href="?edit=<?php echo $row['id']; ?>" class="btn btn-warning btn-xs">Edit</a>
                                                <a href="?delete=<?php echo $row['id']; ?>" class="btn btn-danger btn-xs" onclick="return confirm('Are you sure?')">Delete</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info"><?php echo $translations[$lang]['no_records']; ?></div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#class_title').select2({
        tags: true,
        placeholder: "Select or type a class title",
        allowClear: true,
        width: '100%'
    });
    $('#sections').select2({
        tags: true,
        tokenSeparators: [','],
        placeholder: "Select or type sections",
        width: '100%'
    });
});
</script>

</body>
</html>

<?php $conn->close(); ?>