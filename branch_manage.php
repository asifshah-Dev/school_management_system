<?php
header('Content-Type: text/html; charset=utf-8');
require_once('security.php');
require_once('conn_inc.php');
require_once('permissions.php');

require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION['lang'] = 'en';
$lang = $_SESSION['lang'];

/* ──────────────────────────────
   Handle POST actions
   ────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['action'])) {

        // ADD
        if ($_POST['action'] === 'add' && !empty($perms['can_add'])) {
            $title = mysqli_real_escape_string($conn, trim($_POST['title'] ?? ''));
            $status = isset($_POST['status']) ? intval($_POST['status']) : 0;

            if ($title === '') {
                $_SESSION['message'] = 'Title is required.';
                $_SESSION['message_type'] = 'danger';
            } else {
                $stmt = $conn->prepare("INSERT INTO branches (title, dated, status) VALUES (?, CURDATE(), ?)");
                if ($stmt) {
                    $stmt->bind_param("si", $title, $status);
                    if ($stmt->execute()) {
                        $_SESSION['message'] = 'Branch added successfully.';
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
        }

        // EDIT
        elseif ($_POST['action'] === 'edit' && !empty($perms['can_edit'])) {
            $id = intval($_POST['id'] ?? 0);
            $title = mysqli_real_escape_string($conn, trim($_POST['title'] ?? ''));
            $status = isset($_POST['status']) ? intval($_POST['status']) : 0;

            if ($id > 0 && $title !== '') {
                $stmt = $conn->prepare("UPDATE branches SET title = ?, status = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("sii", $title, $status, $id);
                    if ($stmt->execute()) {
                        $_SESSION['message'] = 'Branch updated successfully.';
                        $_SESSION['message_type'] = 'success';
                    } else {
                        $_SESSION['message'] = 'Update failed: ' . $stmt->error;
                        $_SESSION['message_type'] = 'danger';
                    }
                    $stmt->close();
                }
            } else {
                $_SESSION['message'] = 'Invalid data.';
                $_SESSION['message_type'] = 'danger';
            }
        }
    }
    header('Location: branch_manage.php');
    exit;
}

/* ──────────────────────────────
   DELETE
   ────────────────────────────── */
if (isset($_GET['delete']) && !empty($perms['can_delete'])) {
    $id = intval($_GET['delete']);

    $chk = $conn->prepare("SELECT COUNT(*) AS c FROM student_registration WHERE branch_id = ?");
    $count = 0;
    if ($chk) {
        $chk->bind_param("i", $id);
        $chk->execute();
        $r = $chk->get_result()->fetch_assoc();
        $count = (int)$r['c'];
        $chk->close();
    }

    if ($count > 0) {
        $_SESSION['message'] = "Cannot delete: $count student(s) are assigned to this branch.";
        $_SESSION['message_type'] = 'warning';
    } else {
        $stmt = $conn->prepare("DELETE FROM branches WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $_SESSION['message'] = 'Branch deleted.';
                $_SESSION['message_type'] = 'success';
            } else {
                $_SESSION['message'] = 'Delete failed: ' . $stmt->error;
                $_SESSION['message_type'] = 'danger';
            }
            $stmt->close();
        }
    }
    header('Location: branch_manage.php');
    exit;
}

/* ──────────────────────────────
   TOGGLE STATUS
   ────────────────────────────── */
if (isset($_GET['toggle']) && !empty($perms['can_edit'])) {
    $id = intval($_GET['toggle']);
    $stmt = $conn->prepare("UPDATE branches SET status = CASE WHEN status = 0 THEN 1 ELSE 0 END WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['message'] = 'Status toggled.';
        $_SESSION['message_type'] = 'success';
    }
    header('Location: branch_manage.php');
    exit;
}

/* ──────────────────────────────
   Load list + student counts
   ────────────────────────────── */
$branches = [];
$sql = "
    SELECT
        b.id,
        b.title,
        b.dated,
        b.status,
        (SELECT COUNT(*) FROM student_registration sr WHERE sr.branch_id = b.id) AS student_count
    FROM branches b
    ORDER BY b.title ASC
";
$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) $branches[] = $row;
}

/* Load branch being edited */
$edit_row = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $eid = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT id, title, status FROM branches WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $eid);
        $stmt->execute();
        $r = $stmt->get_result();
        if ($r && $r->num_rows > 0) $edit_row = $r->fetch_assoc();
        $stmt->close();
    }
}

/* ──────────────────────────────
   Helper: relative date
   ────────────────────────────── */
function relative_date($date_str) {
    if (empty($date_str)) return '';
    try {
        $d = new DateTime($date_str);
    } catch (Exception $e) {
        return $date_str;
    }
    $now = new DateTime();
    $diff = $now->diff($d);

    if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
    return 'Just now';
}

$total = count($branches);
$active_count = 0;
$inactive_count = 0;
foreach ($branches as $b) {
    if ((int)$b['status'] === 0) $active_count++;
    else $inactive_count++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Branch Management &mdash; dsc_online</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f7fa; }

        /* ────────── PAGE HEADER ────────── */
        .page-header {
            display: flex; align-items: flex-start; justify-content: space-between;
            gap: 16px; flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .page-header .title-block h1 {
            font-size: 24px; font-weight: 700; color: #1e293b;
            margin: 0 0 4px 0;
            display: flex; align-items: center; gap: 10px;
        }
        .page-header .title-block h1 i {
            color: #2563eb;
            background: #dbeafe;
            width: 42px; height: 42px;
            border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 20px;
        }
        .page-header .title-block .subtitle {
            font-size: 13px; color: #64748b;
            margin-left: 52px;
        }
        .page-header .actions { display: flex; gap: 8px; flex-wrap: wrap; }

        /* ────────── STAT CARDS ────────── */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 14px;
            margin-bottom: 22px;
        }
        .stat-card {
            background: #fff;
            border-radius: 10px;
            padding: 16px 18px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
            display: flex; align-items: center; gap: 14px;
            transition: all 0.15s;
        }
        .stat-card:hover { box-shadow: 0 4px 14px rgba(0,0,0,0.09); transform: translateY(-1px); }
        .stat-card .icon-box {
            width: 46px; height: 46px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        .stat-card.blue   .icon-box { background: #dbeafe; color: #2563eb; }
        .stat-card.green  .icon-box { background: #d1fae5; color: #059669; }
        .stat-card.red    .icon-box { background: #fee2e2; color: #dc2626; }
        .stat-card .num {
            font-size: 22px; font-weight: 700; color: #0f172a;
            line-height: 1.1;
        }
        .stat-card .lbl {
            font-size: 12px; color: #64748b; font-weight: 500;
            text-transform: uppercase; letter-spacing: 0.4px;
        }

        /* ────────── PANEL / CARD ────────── */
        .panel {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            margin-bottom: 24px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }
        .panel-header {
            padding: 16px 20px;
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; flex-wrap: wrap;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
        }
        .panel-header h5 {
            margin: 0;
            font-size: 15px; font-weight: 700; color: #1e293b;
            display: flex; align-items: center; gap: 8px;
        }
        .panel-header h5 i { color: #2563eb; }
        .panel-body-inner { padding: 20px; }

        /* ────────── FORM ────────── */
        .form-label {
            display: block; font-size: 11px; font-weight: 700;
            color: #64748b; text-transform: uppercase;
            letter-spacing: 0.6px; margin-bottom: 6px;
        }
        .form-control {
            border-radius: 8px; border: 1px solid #cbd5e1;
            padding: 10px 14px; font-size: 14px;
            transition: all 0.15s;
            font-family: inherit;
        }
        .form-control:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
        }

        /* ────────── BUTTONS ────────── */
        .btn {
            font-weight: 600;
            border-radius: 8px;
            padding: 9px 18px;
            font-size: 13px;
            transition: all 0.15s;
            display: inline-flex; align-items: center; gap: 6px;
            border: 1px solid transparent;
        }
        .btn i { font-size: 13px; }
        .btn-primary {
            background-color: #2563eb; border-color: #2563eb; color: #fff;
        }
        .btn-primary:hover, .btn-primary:focus, .btn-primary:active {
            background-color: #1d4ed8; border-color: #1d4ed8; color: #fff;
        }
        .btn-success {
            background-color: #059669; border-color: #059669; color: #fff;
        }
        .btn-success:hover, .btn-success:focus, .btn-success:active {
            background-color: #047857; border-color: #047857; color: #fff;
        }
        .btn-secondary {
            background-color: #fff; border-color: #cbd5e1; color: #475569;
        }
        .btn-secondary:hover, .btn-secondary:focus, .btn-secondary:active {
            background-color: #f1f5f9; border-color: #94a3b8; color: #1e293b;
        }

        /* Small icon-only action buttons */
        .action-btn {
            width: 34px; height: 34px;
            border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 13px;
            border: 1px solid transparent;
            transition: all 0.15s;
            cursor: pointer;
            padding: 0;
            text-decoration: none !important;
            margin-right: 4px;
        }
        .action-btn:hover { transform: translateY(-1px); }
        .action-btn.edit {
            background: #eff6ff; color: #2563eb; border-color: #bfdbfe;
        }
        .action-btn.edit:hover { background: #2563eb; color: #fff; border-color: #2563eb; }

        .action-btn.toggle {
            background: #fef3c7; color: #92400e; border-color: #fde68a;
        }
        .action-btn.toggle:hover { background: #d97706; color: #fff; border-color: #d97706; }

        .action-btn.danger {
            background: #fee2e2; color: #dc2626; border-color: #fecaca;
        }
        .action-btn.danger:hover { background: #dc2626; color: #fff; border-color: #dc2626; }

        /* ────────── TABLE ────────── */
        table.branch-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin: 0;
        }
        table.branch-table thead th {
            background: #f8fafc;
            color: #64748b;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            padding: 12px 16px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }
        table.branch-table tbody td {
            padding: 14px 16px;
            font-size: 13.5px;
            color: #1e293b;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        table.branch-table tbody tr { transition: background 0.15s; }
        table.branch-table tbody tr:hover td { background: #f8fafc; }
        table.branch-table tbody tr:last-child td { border-bottom: none; }

        .id-badge {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 32px; height: 26px; padding: 0 8px;
            background: #eff6ff;
            color: #2563eb;
            border-radius: 6px;
            font-size: 12px; font-weight: 700;
            font-family: 'SF Mono', 'Monaco', monospace;
        }

        .branch-title {
            display: flex; align-items: center; gap: 10px;
            font-weight: 600; color: #0f172a; font-size: 14px;
        }
        .branch-title .icon {
            width: 30px; height: 30px;
            border-radius: 8px;
            background: #eef2ff;
            color: #4f46e5;
            display: flex; align-items: center; justify-content: center;
            font-size: 13px;
            flex-shrink: 0;
        }

        .dated-text {
            font-size: 12.5px;
            color: #64748b;
            display: flex; flex-direction: column; gap: 2px;
        }
        .dated-text .relative { color: #475569; font-weight: 500; }
        .dated-text .exact { color: #94a3b8; font-size: 11px; }

        .student-count {
            display: inline-flex; align-items: center; gap: 6px;
            background: #f1f5f9;
            color: #334155;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px; font-weight: 600;
        }
        .student-count i { color: #64748b; font-size: 11px; }
        .student-count.has-students { background: #dbeafe; color: #1e40af; }
        .student-count.has-students i { color: #2563eb; }
        .student-count a { color: inherit; text-decoration: none; }
        .student-count a:hover { text-decoration: underline; }

        /* ────────── STATUS PILL ────────── */
        .pill {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.4px;
        }
        .pill::before {
            content: '';
            width: 6px; height: 6px; border-radius: 50%;
            display: inline-block;
        }
        .pill.active { background: #d1fae5; color: #065f46; }
        .pill.active::before { background: #10b981; }
        .pill.inactive { background: #fee2e2; color: #991b1b; }
        .pill.inactive::before { background: #dc2626; }

        /* ────────── EMPTY STATE ────────── */
        .empty-state {
            padding: 60px 20px;
            text-align: center;
        }
        .empty-state .icon-box {
            width: 72px; height: 72px;
            margin: 0 auto 16px auto;
            background: #f1f5f9;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: #94a3b8; font-size: 28px;
        }
        .empty-state h5 { color: #334155; font-weight: 700; margin: 0 0 6px 0; }
        .empty-state p { color: #94a3b8; font-size: 13px; margin: 0 0 16px 0; }

        /* ────────── ALERT ────────── */
        .alert {
            border-radius: 10px;
            border: none;
            padding: 12px 18px;
            font-size: 13.5px;
            font-weight: 500;
            box-shadow: 0 2px 6px rgba(0,0,0,0.04);
        }
        .alert-success { background: #d1fae5; color: #065f46; }
        .alert-danger  { background: #fee2e2; color: #991b1b; }
        .alert-warning { background: #fef3c7; color: #92400e; }
        .alert-info    { background: #dbeafe; color: #1e40af; }
        .alert .close { color: inherit; opacity: 0.5; }

        /* ────────── EDIT MODE HIGHLIGHT ────────── */
        .panel.edit-mode {
            border: 2px solid #f59e0b;
            box-shadow: 0 4px 14px rgba(245, 158, 11, 0.15);
        }
        .panel.edit-mode .panel-header {
            background: #fffbeb;
            border-bottom-color: #fde68a;
        }
        .panel.edit-mode .panel-header h5 { color: #92400e; }
        .panel.edit-mode .panel-header h5 i { color: #d97706; }

        @media (max-width: 768px) {
            .page-header .title-block h1 { font-size: 20px; }
            .page-header .title-block h1 i { width: 36px; height: 36px; font-size: 16px; }
            .page-header .title-block .subtitle { margin-left: 46px; font-size: 12px; }
            table.branch-table thead th,
            table.branch-table tbody td { padding: 10px 12px; }
            .stat-card .num { font-size: 18px; }
        }
    </style>
</head>
<body>
<?php require_once('navbar.php'); ?>

<div class="container" style="padding: 24px 20px; max-width: 1150px; margin: 0 auto;">

    <!-- PAGE HEADER -->
    <div class="page-header">
        <div class="title-block">
            <h1>
                <i class="fas fa-building"></i>
                Branch Management
            </h1>
            <div class="subtitle">Manage all branches of your school</div>
        </div>
      
    </div>

    <!-- ALERT MESSAGES -->
    <?php if (isset($_SESSION['message'])): ?>
    <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show">
        <i class="fas fa-<?php
            echo $_SESSION['message_type'] === 'success' ? 'check-circle' :
                ($_SESSION['message_type'] === 'warning' ? 'exclamation-triangle' : 'exclamation-circle');
        ?> mr-2"></i>
        <?php echo $_SESSION['message']; unset($_SESSION['message'], $_SESSION['message_type']); ?>
        <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
    </div>
    <?php endif; ?>

    <!-- STAT CARDS -->
    <div class="stats-row">
        <div class="stat-card blue">
            <div class="icon-box"><i class="fas fa-building"></i></div>
            <div>
                <div class="num"><?php echo $total; ?></div>
                <div class="lbl">Total Branches</div>
            </div>
        </div>
        <div class="stat-card green">
            <div class="icon-box"><i class="fas fa-check-circle"></i></div>
            <div>
                <div class="num"><?php echo $active_count; ?></div>
                <div class="lbl">Active</div>
            </div>
        </div>
        <div class="stat-card red">
            <div class="icon-box"><i class="fas fa-times-circle"></i></div>
            <div>
                <div class="num"><?php echo $inactive_count; ?></div>
                <div class="lbl">Inactive</div>
            </div>
        </div>
    </div>

    <!-- FORM PANEL (Add / Edit) -->
    <?php if (!empty($perms['can_add']) || !empty($perms['can_edit'])): ?>
    <div class="panel <?php echo $edit_row ? 'edit-mode' : ''; ?>">
        <div class="panel-header">
            <h5>
                <i class="fas fa-<?php echo $edit_row ? 'edit' : 'plus-circle'; ?>"></i>
                <?php echo $edit_row ? 'Edit Branch' : 'Add New Branch'; ?>
            </h5>
            <?php if ($edit_row): ?>
                <a href="branch_manage.php" class="btn btn-secondary" style="padding: 6px 14px; font-size:12px;">
                    <i class="fas fa-times"></i> Cancel Edit
                </a>
            <?php endif; ?>
        </div>
        <div class="panel-body-inner">
            <form method="post" action="branch_manage.php">
                <input type="hidden" name="action" value="<?php echo $edit_row ? 'edit' : 'add'; ?>">
                <?php if ($edit_row): ?>
                    <input type="hidden" name="id" value="<?php echo (int)$edit_row['id']; ?>">
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group mb-md-0">
                            <label class="form-label">Branch Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="title" required
                                   value="<?php echo $edit_row ? htmlspecialchars($edit_row['title']) : ''; ?>"
                                   placeholder="e.g. Main Campus, Matta, Girls Campus"
                                   autofocus>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group mb-md-0">
                            <label class="form-label">Status</label>
                            <select class="form-control" name="status">
                                <option value="0" <?php echo (!$edit_row || $edit_row['status'] == 0) ? 'selected' : ''; ?>>Active</option>
                                <option value="1" <?php echo ($edit_row && $edit_row['status'] == 1) ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-<?php echo $edit_row ? 'primary' : 'success'; ?> btn-block" style="height: 42px; justify-content:center;">
                            <i class="fas fa-<?php echo $edit_row ? 'save' : 'plus'; ?>"></i>
                            <?php echo $edit_row ? 'Update Branch' : 'Add Branch'; ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- LIST PANEL -->
    <div class="panel">
        <div class="panel-header">
            <h5><i class="fas fa-list"></i> All Branches</h5>
            <span style="font-size:12px; color:#64748b; font-weight:600;">
                <?php echo $total; ?> total
            </span>
        </div>

        <?php if (!empty($branches)): ?>
        <div class="table-responsive">
            <table class="branch-table">
                <thead>
                    <tr>
                        <th style="width:70px;">ID</th>
                        <th>Branch</th>
                        <th style="width:150px;">Students</th>
                        <th style="width:150px;">Created</th>
                        <th style="width:120px;">Status</th>
                        <th style="width:150px; text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($branches as $b):
                        $is_active = (int)$b['status'] === 0;
                        $s_count = (int)$b['student_count'];
                    ?>
                    <tr>
                        <td>
                            <span class="id-badge"><?php echo (int)$b['id']; ?></span>
                        </td>
                        <td>
                            <div class="branch-title">
                                <div class="icon"><i class="fas fa-building"></i></div>
                                <div><?php echo htmlspecialchars($b['title']); ?></div>
                            </div>
                        </td>
                        <td>
                            <?php if ($s_count > 0): ?>
                                <a href="student_list.php?branch_filter=<?php echo (int)$b['id']; ?>" class="student-count has-students" title="View students in this branch">
                                    <i class="fas fa-users"></i> <?php echo $s_count; ?> student<?php echo $s_count > 1 ? 's' : ''; ?>
                                </a>
                            <?php else: ?>
                                <span class="student-count">
                                    <i class="fas fa-users"></i> None
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="dated-text">
                                <span class="relative"><?php echo htmlspecialchars(relative_date($b['dated'])); ?></span>
                                <span class="exact" title="<?php echo htmlspecialchars($b['dated']); ?>"><?php echo htmlspecialchars($b['dated']); ?></span>
                            </div>
                        </td>
                        <td>
                            <?php if ($is_active): ?>
                                <span class="pill active">Active</span>
                            <?php else: ?>
                                <span class="pill inactive">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <div style="display:inline-flex; align-items:center;">
                                <?php if (!empty($perms['can_edit'])): ?>
                                    <a href="branch_manage.php?edit=<?php echo (int)$b['id']; ?>"
                                       class="action-btn edit" title="Edit branch">
                                        <i class="fas fa-pen"></i>
                                    </a>
                                    <a href="branch_manage.php?toggle=<?php echo (int)$b['id']; ?>"
                                       class="action-btn toggle"
                                       title="<?php echo $is_active ? 'Deactivate' : 'Activate'; ?>"
                                       onclick="return confirm('<?php echo $is_active ? 'Deactivate' : 'Activate'; ?> this branch?');">
                                        <i class="fas fa-<?php echo $is_active ? 'toggle-on' : 'toggle-off'; ?>"></i>
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($perms['can_delete'])): ?>
                                    <a href="branch_manage.php?delete=<?php echo (int)$b['id']; ?>"
                                       class="action-btn danger"
                                       title="Delete branch"
                                       onclick="return confirm('Delete this branch? This cannot be undone.');">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div class="empty-state">
                <div class="icon-box"><i class="fas fa-building"></i></div>
                <h5>No branches added yet</h5>
                <p>Add your first branch to get started</p>
                <?php if (!empty($perms['can_add'])): ?>
                    <button type="button" class="btn btn-success" onclick="$('input[name=title]').focus();$('html,body').animate({scrollTop:0},300);">
                        <i class="fas fa-plus"></i> Add First Branch
                    </button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

</body>
</html>
<?php $conn->close(); ?>