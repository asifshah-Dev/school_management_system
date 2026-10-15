<?php
header('Content-Type: text/html; charset=utf-8');
require_once('security.php');
require_once('conn_inc.php');
require_once('permissions.php');

/* ==== AUTHORIZATION GATE ==== */
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
/* ==== END AUTHORIZATION GATE ==== */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$can_edit   = !empty($perms['can_edit']);
$can_delete = !empty($perms['can_delete']);

/* ============================================================
   POST HANDLERS (plain form POST, then redirect — no AJAX)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    /* ---------- SAVE (Add or Update) ---------- */
    if ($action === 'save') {
        $id     = intval($_POST['id'] ?? 0);
        $title  = isset($_POST['title']) ? trim($_POST['title']) : '';
        $status = isset($_POST['status']) ? intval($_POST['status']) : 0;

        if ($title === '') {
            $_SESSION['message'] = 'Class title is required.';
            $_SESSION['message_type'] = 'danger';
            header('Location: class_add.php');
            exit;
        }

        if ($id > 0) {
            if (!$can_edit) {
                $_SESSION['message'] = 'You do not have permission to edit classes.';
                $_SESSION['message_type'] = 'danger';
                header('Location: class_add.php');
                exit;
            }
            $stmt = $conn->prepare("UPDATE classes SET title = ?, status = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("sii", $title, $status, $id);
                if ($stmt->execute()) {
                    $_SESSION['message'] = 'Class updated successfully.';
                    $_SESSION['message_type'] = 'success';
                } else {
                    $_SESSION['message'] = 'Update failed: ' . $stmt->error;
                    $_SESSION['message_type'] = 'danger';
                }
                $stmt->close();
            } else {
                $_SESSION['message'] = 'Prepare failed: ' . $conn->error;
                $_SESSION['message_type'] = 'danger';
            }
        } else {
            $today = date('Y-m-d');
            $stmt = $conn->prepare("INSERT INTO classes (title, dated, status) VALUES (?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("ssi", $title, $today, $status);
                if ($stmt->execute()) {
                    $_SESSION['message'] = 'Class added successfully.';
                    $_SESSION['message_type'] = 'success';
                } else {
                    $_SESSION['message'] = 'Insert failed: ' . $stmt->error;
                    $_SESSION['message_type'] = 'danger';
                }
                $stmt->close();
            } else {
                $_SESSION['message'] = 'Prepare failed: ' . $conn->error;
                $_SESSION['message_type'] = 'danger';
            }
        }
        header('Location: class_add.php');
        exit;
    }

    /* ---------- DELETE ---------- */
    if ($action === 'delete') {
        if (!$can_delete) {
            $_SESSION['message'] = 'You do not have permission to delete classes.';
            $_SESSION['message_type'] = 'danger';
            header('Location: class_add.php');
            exit;
        }
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['message'] = 'Invalid class id.';
            $_SESSION['message_type'] = 'danger';
            header('Location: class_add.php');
            exit;
        }

        /* Block delete if referenced in class_sections */
        $ref_count = 0;
        $chk = $conn->prepare("SELECT COUNT(*) AS c FROM class_sections WHERE class_id = ?");
        if ($chk) {
            $chk->bind_param("i", $id);
            $chk->execute();
            $chk_res = $chk->get_result();
            if ($chk_res && ($chk_row = $chk_res->fetch_assoc())) {
                $ref_count = intval($chk_row['c']);
            }
            $chk->close();
        }

        if ($ref_count > 0) {
            $_SESSION['message'] = 'Cannot delete: this class is linked to ' . $ref_count . ' class-section(s). Remove those first.';
            $_SESSION['message_type'] = 'danger';
            header('Location: class_add.php');
            exit;
        }

        $del = $conn->prepare("DELETE FROM classes WHERE id = ?");
        if ($del) {
            $del->bind_param("i", $id);
            if ($del->execute()) {
                $_SESSION['message'] = 'Class deleted successfully.';
                $_SESSION['message_type'] = 'success';
            } else {
                $_SESSION['message'] = 'Delete failed: ' . $del->error;
                $_SESSION['message_type'] = 'danger';
            }
            $del->close();
        } else {
            $_SESSION['message'] = 'Prepare failed: ' . $conn->error;
            $_SESSION['message_type'] = 'danger';
        }
        header('Location: class_add.php');
        exit;
    }
}

/* ============================================================
   LOAD LIST (natural sort)
   ============================================================ */
$rows = [];
$sql = "SELECT c.id, c.title, c.dated, c.status, (SELECT COUNT(*) FROM class_sections cs WHERE cs.class_id = c.id) AS section_count FROM classes c ORDER BY CAST(REGEXP_REPLACE(c.title, '[^0-9]', '') AS UNSIGNED) ASC, c.title ASC";
$res = $conn->query($sql);
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
}

/* Stats */
$stat_total = 0;
$stat_active = 0;
$stat_inactive = 0;
$stat_res = $conn->query("SELECT SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) AS active_c, SUM(CASE WHEN status IS NULL OR status <> 0 THEN 1 ELSE 0 END) AS inactive_c, COUNT(*) AS total_c FROM classes");
if ($stat_res && ($sr = $stat_res->fetch_assoc())) {
    $stat_total = intval($sr['total_c']);
    $stat_active = intval($sr['active_c']);
    $stat_inactive = intval($sr['inactive_c']);
}

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
    <title>Classes</title>
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
        .page-header-card h1 {
            font-size: 21px;
            font-weight: 700;
            margin: 0;
            letter-spacing: -0.3px;
        }
        .page-header-card .subtitle {
            font-size: 13px;
            opacity: 0.85;
            margin-top: 4px;
        }

        /* ---------- STATS ---------- */
        .stat-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: #fff;
            border-radius: 10px;
            padding: 14px 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .stat-card .stat-icon {
            width: 42px; height: 42px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .stat-card .stat-icon.blue { background: #dbeafe; color: #2563eb; }
        .stat-card .stat-icon.green { background: #d1fae5; color: #059669; }
        .stat-card .stat-icon.grey { background: #e2e8f0; color: #64748b; }
        .stat-card .stat-value { font-size: 20px; font-weight: 700; color: #1e293b; line-height: 1; }
        .stat-card .stat-label { font-size: 11px; color: #64748b; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 600; }

        /* ---------- FORM CARD ---------- */
        .form-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            padding: 22px;
            margin-bottom: 20px;
            border-top: 4px solid #2563eb;
        }
        .form-card.editing { border-top-color: #f59e0b; background: #fffbeb; }
        .form-card .form-head {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 14px; flex-wrap: wrap; gap: 10px;
        }
        .form-card .form-head h4 {
            font-size: 15px; font-weight: 700; color: #1e293b; margin: 0;
            display: flex; align-items: center; gap: 8px;
        }
        .form-card.editing .form-head h4 { color: #b45309; }
        .form-card .form-head h4 i { color: #2563eb; }
        .form-card.editing .form-head h4 i { color: #f59e0b; }

        .form-card label {
            font-size: 12px; font-weight: 700; color: #475569;
            text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px;
        }
        .form-card .form-control {
            border-radius: 8px; border: 1px solid #cbd5e1;
            font-size: 14px; padding: 9px 12px; height: auto;
        }
        .form-card .form-control:focus {
            border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
        }

        .btn-brand {
            background: #2563eb; border: 1px solid #2563eb; color: #fff;
            font-weight: 600; font-size: 13px; padding: 9px 18px;
            border-radius: 8px; display: inline-flex; align-items: center; gap: 7px;
            transition: all 0.2s;
        }
        .btn-brand:hover, .btn-brand:focus {
            background: #1d4ed8; border-color: #1d4ed8; color: #fff;
            box-shadow: 0 4px 12px rgba(37,99,235,0.3);
        }
        .btn-ghost {
            background: #f1f5f9; border: 1px solid #e2e8f0; color: #475569;
            font-weight: 600; font-size: 13px; padding: 9px 16px;
            border-radius: 8px; display: inline-flex; align-items: center; gap: 7px;
        }
        .btn-ghost:hover, .btn-ghost:focus { background: #e2e8f0; color: #1e293b; }

        /* ---------- LIST CARD ---------- */
        .list-card {
            background: #fff; border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06); overflow: hidden;
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

        table.cls-table { width: 100%; margin: 0; border-collapse: separate; border-spacing: 0; }
        table.cls-table thead th {
            background: #f8fafc; color: #475569; font-size: 12px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.4px;
            padding: 11px 16px; border-bottom: 1px solid #e2e8f0; white-space: nowrap;
        }
        table.cls-table tbody td {
            padding: 13px 16px; border-bottom: 1px solid #f1f5f9;
            vertical-align: middle; font-size: 14px; color: #1e293b;
        }
        table.cls-table tbody tr:hover { background: #f8fafc; }
        table.cls-table tbody tr:last-child td { border-bottom: none; }

        .id-badge {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 34px; height: 26px; padding: 0 8px;
            background: #eff6ff; color: #2563eb;
            font-weight: 700; font-size: 12px; border-radius: 6px;
        }
        .class-title { font-weight: 600; font-size: 14px; color: #1e293b; }

        .status-pill {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 10px; border-radius: 20px;
            font-size: 12px; font-weight: 600; line-height: 1;
        }
        .status-pill.active { background: #d1fae5; color: #065f46; }
        .status-pill.inactive { background: #e2e8f0; color: #475569; }
        .status-pill::before {
            content: ''; width: 6px; height: 6px;
            border-radius: 50%; background: currentColor;
        }

        .section-pill {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 10px; border-radius: 14px;
            font-size: 12px; font-weight: 600;
            background: #f1f5f9; color: #475569;
        }
        .section-pill.has { background: #e0e7ff; color: #3730a3; }

        .icon-btn {
            width: 34px; height: 34px; border-radius: 8px;
            border: 1px solid #e2e8f0; background: #fff; color: #64748b;
            display: inline-flex; align-items: center; justify-content: center;
            transition: all 0.15s; padding: 0; font-size: 13px;
        }
        .icon-btn:hover { transform: translateY(-1px); }
        .icon-btn.edit:hover { background: #eff6ff; border-color: #93c5fd; color: #2563eb; }
        .icon-btn.delete:hover { background: #fef2f2; border-color: #fecaca; color: #dc2626; }
        .icon-btn + .icon-btn { margin-left: 6px; }

        .empty-state { padding: 50px 20px; text-align: center; }
        .empty-state .empty-icon {
            width: 72px; height: 72px; background: #f1f5f9;
            border-radius: 50%; display: inline-flex;
            align-items: center; justify-content: center;
            font-size: 30px; color: #94a3b8; margin-bottom: 14px;
        }
        .empty-state h5 { font-size: 15px; font-weight: 700; color: #334155; margin: 0 0 6px 0; }
        .empty-state p { font-size: 13px; color: #64748b; margin: 0; }
    </style>
</head>
<body>
    <?php require_once('navbar.php'); ?>

    <div class="container" style="padding: 20px; max-width: 1200px; margin: 0 auto;">

        <!-- Page Header -->
        <div class="page-header-card">
            <div class="header-icon"><i class="fas fa-school"></i></div>
            <div>
                <h1>Classes</h1>
                <div class="subtitle">Add, edit and manage classes</div>
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

        <!-- Stats -->
        <div class="stat-row">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-layer-group"></i></div>
                <div>
                    <div class="stat-value"><?php echo $stat_total; ?></div>
                    <div class="stat-label">Total Classes</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                <div>
                    <div class="stat-value"><?php echo $stat_active; ?></div>
                    <div class="stat-label">Active</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon grey"><i class="fas fa-pause-circle"></i></div>
                <div>
                    <div class="stat-value"><?php echo $stat_inactive; ?></div>
                    <div class="stat-label">Inactive</div>
                </div>
            </div>
        </div>

        <!-- Add / Edit Form -->
        <div class="form-card" id="formCard">
            <div class="form-head">
                <h4 id="formTitle"><i class="fas fa-plus-circle"></i> Add New Class</h4>
                <button type="button" class="btn-ghost" id="btnCancelEdit" style="display:none;">
                    <i class="fas fa-times"></i> Cancel Edit
                </button>
            </div>
            <form method="post" action="" id="classForm" autocomplete="off">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="classId" value="0">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group" style="margin-bottom: 14px;">
                            <label for="classTitle">Class Title <span style="color:#dc2626;">*</span></label>
                            <input type="text" class="form-control" id="classTitle" name="title" placeholder="e.g. Class 1" required>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group" style="margin-bottom: 14px;">
                            <label for="classStatus">Status</label>
                            <select class="form-control" id="classStatus" name="status">
                                <option value="0">Active</option>
                                <option value="1">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3" style="display: flex; align-items: flex-end;">
                        <button type="submit" class="btn-brand" style="width: 100%; justify-content: center; margin-bottom: 14px;">
                            <i class="fas fa-save"></i> <span id="saveBtnText">Add Class</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- List -->
        <div class="list-card">
            <div class="list-card-head">
                <h3><i class="fas fa-list-ul"></i> All Classes (<?php echo count($rows); ?>)</h3>
            </div>

            <?php if (empty($rows)): ?>
                <div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-school"></i></div>
                    <h5>No classes yet</h5>
                    <p>Add your first class using the form above.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="cls-table">
                        <thead>
                            <tr>
                                <th style="width: 70px;">#</th>
                                <th>Class Title</th>
                                <th style="width: 120px;">Sections</th>
                                <th style="width: 120px;">Status</th>
                                <th style="width: 140px;">Added</th>
                                <th style="width: 120px; text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r):
                                $row_id = intval($r['id']);
                                $row_title = $r['title'];
                                $row_status = $r['status'];
                                $row_dated = $r['dated'];
                                $row_section_count = intval($r['section_count']);
                                $is_active = ($row_status === 0 || $row_status === '0');
                                $dated_display = '&mdash;';
                                if (!empty($row_dated) && $row_dated !== '0000-00-00') {
                                    $ts = strtotime($row_dated);
                                    if ($ts) $dated_display = date('d M Y', $ts);
                                }
                            ?>
                            <tr>
                                <td><span class="id-badge">#<?php echo $row_id; ?></span></td>
                                <td><div class="class-title"><?php echo htmlspecialchars($row_title); ?></div></td>
                                <td>
                                    <span class="section-pill <?php echo $row_section_count > 0 ? 'has' : ''; ?>">
                                        <i class="fas fa-layer-group"></i> <?php echo $row_section_count; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-pill <?php echo $is_active ? 'active' : 'inactive'; ?>">
                                        <?php echo $is_active ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td><?php echo $dated_display; ?></td>
                                <td style="text-align: right;">
                                    <?php if ($can_edit): ?>
                                        <button type="button" class="icon-btn edit btn-edit"
                                            data-id="<?php echo $row_id; ?>"
                                            data-title="<?php echo htmlspecialchars($row_title, ENT_QUOTES); ?>"
                                            data-status="<?php echo $is_active ? '0' : '1'; ?>"
                                            title="Edit">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($can_delete): ?>
                                        <form method="post" action="" style="display:inline;" class="delete-form" data-title="<?php echo htmlspecialchars($row_title, ENT_QUOTES); ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $row_id; ?>">
                                            <button type="submit" class="icon-btn delete" title="Delete">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    $(document).ready(function() {

        /* Edit button — fills the form and scrolls to it */
        $(document).on('click', '.btn-edit', function() {
            var id = $(this).data('id');
            var title = $(this).data('title');
            var status = $(this).data('status');

            $('#classId').val(id);
            $('#classTitle').val(title);
            $('#classStatus').val(String(status));

            $('#formTitle').html('<i class="fas fa-pen"></i> Edit Class');
            $('#saveBtnText').text('Update Class');
            $('#formCard').addClass('editing');
            $('#btnCancelEdit').show();

            $('html, body').animate({ scrollTop: $('#formCard').offset().top - 80 }, 250);
            $('#classTitle').focus();
        });

        /* Cancel edit — reset form */
        $('#btnCancelEdit').on('click', function() {
            resetForm();
        });

        function resetForm() {
            $('#classId').val('0');
            $('#classTitle').val('');
            $('#classStatus').val('0');
            $('#formTitle').html('<i class="fas fa-plus-circle"></i> Add New Class');
            $('#saveBtnText').text('Add Class');
            $('#formCard').removeClass('editing');
            $('#btnCancelEdit').hide();
        }

        /* Delete confirm */
        $(document).on('submit', '.delete-form', function(e) {
            var title = $(this).data('title');
            if (!confirm('Delete class "' + title + '"?\n\nThis cannot be undone.')) {
                e.preventDefault();
                return false;
            }
        });
    });
    </script>
</body>
</html>
<?php $conn->close(); ?>