<?php
header('Content-Type: text/html; charset=utf-8');
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
require_once('conn_inc.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$can_edit = !empty($perms['can_edit']);

/* ============================================================
   POST HANDLER — update status for selected students
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['students']) && is_array($_POST['students'])) {

    $new_status = intval($_POST['status'] ?? -1);
    $return_class = intval($_POST['class_id'] ?? 0);
    $return_session = intval($_POST['session_id'] ?? 0);

    if ($new_status < 0 || $new_status > 3) {
        $_SESSION['message'] = 'Invalid status selected.';
        $_SESSION['message_type'] = 'danger';
    } elseif (!$can_edit) {
        $_SESSION['message'] = 'You do not have permission to update student status.';
        $_SESSION['message_type'] = 'danger';
    } else {
        $updated = 0;
        $failed = 0;

        $upd = $conn->prepare("UPDATE student_registration SET status = ? WHERE id = ?");
        if ($upd) {
            foreach ($_POST['students'] as $sid_raw) {
                $sid = intval($sid_raw);
                if ($sid <= 0) continue;
                $upd->bind_param("ii", $new_status, $sid);
                if ($upd->execute()) {
                    $updated++;
                } else {
                    $failed++;
                }
            }
            $upd->close();
        }

        if ($updated > 0) {
            $_SESSION['message'] = 'Student status updated successfully. ' . $updated . ' record(s) affected.';
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = 'No records updated.' . ($failed > 0 ? ' (' . $failed . ' failed)' : '');
            $_SESSION['message_type'] = 'warning';
        }
    }

    $redirect = 'student_status.php?class_id=' . $return_class . '&session_id=' . $return_session;
    header('Location: ' . $redirect);
    exit;
}

/* ============================================================
   LOAD FILTER DROPDOWNS
   ============================================================ */
$sessions = [];
$sess_res = $conn->query("SELECT id, title FROM sessions ORDER BY from_dated DESC, id DESC");
if ($sess_res) {
    while ($r = $sess_res->fetch_assoc()) $sessions[$r['id']] = $r['title'];
}

$classes = [];
$class_res = $conn->query("SELECT id, title FROM classes WHERE status = 0 OR status IS NULL ORDER BY title");
if ($class_res) {
    while ($r = $class_res->fetch_assoc()) $classes[$r['id']] = $r['title'];
}

/* Selected filters */
$selected_session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
if ($selected_session_id <= 0 && !empty($sessions)) {
    $first_key = array_key_first($sessions);
    $selected_session_id = intval($first_key);
}

$selected_class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;

/* Selected class title */
$selected_class_title = '';
if ($selected_class_id > 0 && isset($classes[$selected_class_id])) {
    $selected_class_title = $classes[$selected_class_id];
}

/* ============================================================
   LOAD STUDENTS
   ============================================================ */
$students = [];
if ($selected_class_id > 0 && $selected_session_id > 0) {
    $sql = "SELECT sr.id, sr.name, sr.father_name, sr.status FROM student_registration sr JOIN student_class sc ON sr.id = sc.student_registration_id WHERE sc.class_id = ? AND sc.session_id = ? ORDER BY sr.name ASC";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ii", $selected_class_id, $selected_session_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($r = $res->fetch_assoc()) $students[] = $r;
        }
        $stmt->close();
    }
}

$status_map = [
    0 => 'Active',
    1 => 'Inactive',
    2 => 'Struck Off',
    3 => 'Left'
];

$message = '';
$message_type = '';
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'] ?? 'info';
    unset($_SESSION['message'], $_SESSION['message_type']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Manage Student Status</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f5f7fa; padding-bottom: 60px; }

        .page-header-card {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            border-radius: 12px;
            padding: 22px 26px;
            color: #fff;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 6px 22px rgba(37, 99, 235, 0.25);
        }
        .page-header-card .header-icon {
            width: 52px; height: 52px;
            background: rgba(255,255,255,0.18);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px;
        }
        .page-header-card h1 { font-size: 21px; font-weight: 700; margin: 0; letter-spacing: -0.3px; }
        .page-header-card .subtitle { font-size: 13px; opacity: 0.85; margin-top: 4px; }

        .filter-card {
            background: #fff; border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            padding: 18px 22px; margin-bottom: 20px;
            border-top: 4px solid #2563eb;
        }
        .filter-card label {
            font-size: 12px; font-weight: 700; color: #475569;
            text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px;
        }
        .filter-card .form-control {
            border-radius: 8px; border: 1px solid #cbd5e1;
            font-size: 14px; padding: 9px 12px; height: auto;
        }
        .filter-card .form-control:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
        }

        .list-card {
            background: #fff; border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            overflow: hidden;
        }
        .list-card-head {
            padding: 16px 20px; border-bottom: 1px solid #e2e8f0;
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 10px;
        }
        .list-card-head h3 {
            font-size: 15px; font-weight: 700; color: #1e293b; margin: 0;
            display: flex; align-items: center; gap: 8px;
        }
        .list-card-head h3 i { color: #2563eb; }
        .list-card-head .count-chip {
            background: #eff6ff; color: #2563eb;
            padding: 4px 12px; border-radius: 20px;
            font-size: 12px; font-weight: 700;
        }

        table.status-table { width: 100%; margin: 0; border-collapse: separate; border-spacing: 0; }
        table.status-table thead th {
            background: #f8fafc; color: #475569;
            font-size: 12px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.4px;
            padding: 11px 16px; border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
        }
        table.status-table tbody td {
            padding: 13px 16px; border-bottom: 1px solid #f1f5f9;
            vertical-align: middle; font-size: 14px; color: #1e293b;
        }
        table.status-table tbody tr:hover { background: #f8fafc; }
        table.status-table tbody tr:last-child td { border-bottom: none; }

        .student-name { font-weight: 600; color: #1e293b; }
        .father-name { color: #64748b; font-size: 13px; }

        .status-pill {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 10px; border-radius: 20px;
            font-size: 12px; font-weight: 600; line-height: 1;
        }
        .status-pill.active { background: #d1fae5; color: #065f46; }
        .status-pill.inactive { background: #e2e8f0; color: #475569; }
        .status-pill.struck { background: #fee2e2; color: #991b1b; }
        .status-pill.left { background: #fef3c7; color: #92400e; }
        .status-pill::before {
            content: ''; width: 6px; height: 6px;
            border-radius: 50%; background: currentColor;
        }

        .custom-check {
            width: 18px; height: 18px; cursor: pointer;
            accent-color: #2563eb;
        }

        .btn-brand {
            background: #2563eb; border: 1px solid #2563eb; color: #fff;
            font-weight: 600; font-size: 13px; padding: 9px 20px;
            border-radius: 8px; display: inline-flex; align-items: center; gap: 7px;
            transition: all 0.2s;
        }
        .btn-brand:hover, .btn-brand:focus {
            background: #1d4ed8; border-color: #1d4ed8; color: #fff;
            box-shadow: 0 4px 12px rgba(37,99,235,0.3);
        }

        .empty-state { padding: 55px 20px; text-align: center; }
        .empty-state .empty-icon {
            width: 72px; height: 72px; background: #f1f5f9;
            border-radius: 50%; display: inline-flex;
            align-items: center; justify-content: center;
            font-size: 30px; color: #94a3b8; margin-bottom: 14px;
        }
        .empty-state h5 { font-size: 15px; font-weight: 700; color: #334155; margin: 0 0 6px 0; }
        .empty-state p { font-size: 13px; color: #64748b; margin: 0; }

        .action-bar {
            padding: 16px 20px; border-top: 1px solid #e2e8f0;
            display: flex; align-items: flex-end; gap: 16px;
            flex-wrap: wrap; background: #fafbfc;
        }
        .action-bar label {
            font-size: 12px; font-weight: 700; color: #475569;
            text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px;
        }
        .action-bar select.form-control {
            border-radius: 8px; border: 1px solid #cbd5e1;
            font-size: 14px; padding: 9px 12px; height: auto;
            min-width: 180px;
        }
        .action-bar select.form-control:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
        }
    </style>
</head>
<body>
    <?php require_once('navbar.php'); ?>

    <div class="container" style="padding: 20px; max-width: 1200px; margin: 0 auto;">

        <!-- Header -->
        <div class="page-header-card">
            <div class="header-icon"><i class="fas fa-user-cog"></i></div>
            <div>
                <h1>Manage Student Status</h1>
                <div class="subtitle">Update active, inactive, struck off, or left status for students</div>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-<?php echo htmlspecialchars($message_type); ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="filter-card">
            <form method="get" action="" id="filterForm">
                <div class="row">
                    <div class="col-md-5">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="session_id">Session</label>
                            <select name="session_id" id="session_id" class="form-control" onchange="document.getElementById('filterForm').submit();">
                                <?php if (empty($sessions)): ?>
                                    <option value="">No sessions available</option>
                                <?php else: ?>
                                    <?php foreach ($sessions as $sid => $sname): ?>
                                        <option value="<?php echo (int)$sid; ?>" <?php echo ((int)$sid === $selected_session_id) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($sname); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="class_id">Class</label>
                            <select name="class_id" id="class_id" class="form-control" onchange="document.getElementById('filterForm').submit();">
                                <option value="">-- Select Class --</option>
                                <?php foreach ($classes as $cid => $cname): ?>
                                    <option value="<?php echo (int)$cid; ?>" <?php echo ((int)$cid === $selected_class_id) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cname); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2" style="display: flex; align-items: flex-end;">
                        <button type="submit" class="btn-brand" style="width: 100%; justify-content: center;">
                            <i class="fas fa-search"></i> Load
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <?php if ($selected_class_id > 0 && $selected_session_id > 0): ?>
            <!-- Student List -->
            <form method="post" action="" id="statusForm">
                <input type="hidden" name="class_id" value="<?php echo (int)$selected_class_id; ?>">
                <input type="hidden" name="session_id" value="<?php echo (int)$selected_session_id; ?>">

                <div class="list-card">
                    <div class="list-card-head">
                        <h3>
                            <i class="fas fa-users"></i>
                            Students in <?php echo htmlspecialchars($selected_class_title); ?>
                        </h3>
                        <span class="count-chip"><?php echo count($students); ?> student(s)</span>
                    </div>

                    <?php if (empty($students)): ?>
                        <div class="empty-state">
                            <div class="empty-icon"><i class="fas fa-user-slash"></i></div>
                            <h5>No students found</h5>
                            <p>No students are enrolled in this class for the selected session.</p>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="status-table">
                                <thead>
                                    <tr>
                                        <?php if ($can_edit): ?>
                                            <th style="width: 50px; text-align: center;">
                                                <input type="checkbox" class="custom-check" id="checkAll">
                                            </th>
                                        <?php endif; ?>
                                        <th style="width: 60px;">#</th>
                                        <th>Student Name</th>
                                        <th>Father Name</th>
                                        <th style="width: 140px;">Current Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $sr = 1; foreach ($students as $s):
                                        $sid = intval($s['id']);
                                        $sname = $s['name'];
                                        $fname = $s['father_name'];
                                        $st = intval($s['status']);
                                        $st_label = isset($status_map[$st]) ? $status_map[$st] : 'Unknown';
                                        $st_class = 'inactive';
                                        if ($st === 0) $st_class = 'active';
                                        elseif ($st === 2) $st_class = 'struck';
                                        elseif ($st === 3) $st_class = 'left';
                                    ?>
                                    <tr>
                                        <?php if ($can_edit): ?>
                                            <td style="text-align: center;">
                                                <input type="checkbox" name="students[]" value="<?php echo $sid; ?>" class="custom-check student-check">
                                            </td>
                                        <?php endif; ?>
                                        <td><?php echo $sr++; ?></td>
                                        <td><span class="student-name"><?php echo htmlspecialchars($sname); ?></span></td>
                                        <td><span class="father-name"><?php echo htmlspecialchars($fname); ?></span></td>
                                        <td><span class="status-pill <?php echo $st_class; ?>"><?php echo htmlspecialchars($st_label); ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($can_edit): ?>
                            <div class="action-bar">
                                <div>
                                    <label for="status">Set New Status</label>
                                    <select name="status" id="status" class="form-control" required>
                                        <option value="">-- Select Status --</option>
                                        <option value="0">Active</option>
                                        <option value="1">Inactive</option>
                                        <option value="2">Struck Off</option>
                                        <option value="3">Left</option>
                                    </select>
                                </div>
                                <div>
                                    <button type="submit" class="btn-brand">
                                        <i class="fas fa-check"></i> Update Status
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </form>
        <?php else: ?>
            <div class="list-card">
                <div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-filter"></i></div>
                    <h5>Select a session and class</h5>
                    <p>Choose a session and class above to load the student list.</p>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <script>
    $(document).ready(function() {

        /* Select all checkbox */
        $('#checkAll').on('change', function() {
            $('.student-check').prop('checked', $(this).is(':checked'));
        });

        /* Update "select all" state when individual checkboxes change */
        $(document).on('change', '.student-check', function() {
            var total = $('.student-check').length;
            var checked = $('.student-check:checked').length;
            $('#checkAll').prop('checked', total > 0 && total === checked);
            $('#checkAll').prop('indeterminate', checked > 0 && checked < total);
        });

        /* Validate before submit */
        $('#statusForm').on('submit', function(e) {
            var checked = $('.student-check:checked').length;
            var status = $('#status').val();
            if (checked === 0) {
                e.preventDefault();
                alert('Please select at least one student.');
                return false;
            }
            if (!status && status !== '0') {
                e.preventDefault();
                alert('Please select a status.');
                return false;
            }
            return true;
        });
    });
    </script>
</body>
</html>
<?php $conn->close(); ?>