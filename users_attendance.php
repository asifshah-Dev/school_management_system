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

$can_edit = !empty($perms['can_edit']);

/* ============================================================
   AJAX HANDLER
   Only P and L are stored. Reset = delete = implicit absent.
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    if (!$can_edit) {
        $response['message'] = 'No permission to edit attendance.';
        echo json_encode($response);
        exit;
    }

    $date = isset($_POST['date']) ? trim($_POST['date']) : date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

    $action = isset($_POST['action']) ? trim($_POST['action']) : '';

    try {
        /* -------- Mark Present -------- */
        if ($action === 'mark_present') {
            $user_id = intval($_POST['user_id'] ?? 0);
            if ($user_id <= 0) throw new Exception('Invalid user.');

            $stmt = $conn->prepare("INSERT INTO user_attendance (user_id, dated, status, remarks) VALUES (?, ?, 'P', NULL) ON DUPLICATE KEY UPDATE status = 'P', remarks = NULL");
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
            $stmt->bind_param("is", $user_id, $date);
            $stmt->execute();
            $stmt->close();

            $response['success'] = true;
            $response['message'] = 'Marked Present.';
        }

        /* -------- Mark Leave (dropdown reason) -------- */
        elseif ($action === 'mark_leave') {
            $user_id = intval($_POST['user_id'] ?? 0);
            $reason  = trim($_POST['reason'] ?? '');
            $reason_other = trim($_POST['reason_other'] ?? '');
            $allowed_reasons = ['Sick', 'Leave', 'Urgent Work', 'Other'];

            if ($user_id <= 0) throw new Exception('Invalid user.');
            if ($reason === '') throw new Exception('Please select a leave reason.');
            if (!in_array($reason, $allowed_reasons)) throw new Exception('Invalid leave reason.');

            $final_reason = $reason;
            if ($reason === 'Other') {
                if ($reason_other === '') throw new Exception('Please specify the leave reason.');
                $final_reason = $reason_other;
            }

            $stmt = $conn->prepare("INSERT INTO user_attendance (user_id, dated, status, remarks) VALUES (?, ?, 'L', ?) ON DUPLICATE KEY UPDATE status = 'L', remarks = VALUES(remarks)");
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
            $stmt->bind_param("iss", $user_id, $date, $final_reason);
            $stmt->execute();
            $stmt->close();

            $response['success'] = true;
            $response['message'] = 'Marked Leave — ' . $final_reason;
        }

        /* -------- Reset (Absent) -------- */
        elseif ($action === 'reset') {
            $user_id = intval($_POST['user_id'] ?? 0);
            if ($user_id <= 0) throw new Exception('Invalid user.');

            $stmt = $conn->prepare("DELETE FROM user_attendance WHERE user_id = ? AND dated = ?");
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
            $stmt->bind_param("is", $user_id, $date);
            $stmt->execute();
            $stmt->close();

            $response['success'] = true;
            $response['message'] = 'Reset to Absent.';
        }

        /* -------- Scan username → mark Present -------- */
        elseif ($action === 'scan') {
            $username = trim($_POST['username'] ?? '');
            if ($username === '') throw new Exception('Username required.');

            $sel = $conn->prepare("SELECT id FROM users WHERE username = ?");
            if (!$sel) throw new Exception('Prepare failed: ' . $conn->error);
            $sel->bind_param("s", $username);
            $sel->execute();
            $sel_res = $sel->get_result();
            $urow = $sel_res ? $sel_res->fetch_assoc() : null;
            $sel->close();

            if (!$urow) {
                $response['message'] = 'No user found with username ' . $username;
                echo json_encode($response);
                exit;
            }

            $user_id = intval($urow['id']);

            $chk = $conn->prepare("SELECT status FROM user_attendance WHERE user_id = ? AND dated = ?");
            $chk->bind_param("is", $user_id, $date);
            $chk->execute();
            $chk_res = $chk->get_result();
            $existing = $chk_res ? $chk_res->fetch_assoc() : null;
            $chk->close();

            if ($existing && $existing['status'] === 'P') {
                $response['success'] = true;
                $response['message'] = 'Already marked Present for ' . $username . '.';
                echo json_encode($response);
                exit;
            }

            $ins = $conn->prepare("INSERT INTO user_attendance (user_id, dated, status, remarks) VALUES (?, ?, 'P', NULL) ON DUPLICATE KEY UPDATE status = 'P', remarks = NULL");
            $ins->bind_param("is", $user_id, $date);
            $ins->execute();
            $ins->close();

            $response['success'] = true;
            $response['message'] = 'Marked Present for ' . $username . '.';
        }

        else {
            $response['message'] = 'Unknown action.';
        }
    } catch (Exception $e) {
        $response['success'] = false;
        $response['message'] = 'Server error: ' . $e->getMessage();
    }

    echo json_encode($response);
    exit;
}

/* ============================================================
   PAGE LOAD
   ============================================================ */
$date = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

$status_filter = isset($_GET['status']) ? strtoupper(trim($_GET['status'])) : 'P';
if (!in_array($status_filter, ['P', 'L', 'A'])) $status_filter = 'P';

$total_users = 0;
$tot_res = $conn->query("SELECT COUNT(*) AS c FROM users");
if ($tot_res && ($tr = $tot_res->fetch_assoc())) $total_users = intval($tr['c']);

$present_count = 0;
$leave_count = 0;

$cnt_stmt = $conn->prepare("SELECT SUM(CASE WHEN status = 'P' THEN 1 ELSE 0 END) AS present_c, SUM(CASE WHEN status = 'L' THEN 1 ELSE 0 END) AS leave_c FROM user_attendance WHERE dated = ?");
if ($cnt_stmt) {
    $cnt_stmt->bind_param("s", $date);
    $cnt_stmt->execute();
    $cnt_res = $cnt_stmt->get_result();
    if ($cnt_res && ($cr = $cnt_res->fetch_assoc())) {
        $present_count = intval($cr['present_c']);
        $leave_count = intval($cr['leave_c']);
    }
    $cnt_stmt->close();
}

$absent_count = $total_users - $present_count - $leave_count;
if ($absent_count < 0) $absent_count = 0;

$attendance_taken = ($present_count + $leave_count) > 0;

$users = [];
if ($status_filter === 'P') {
    $sql = "SELECT u.id AS user_id, u.username, ua.status, ua.remarks FROM users u JOIN user_attendance ua ON ua.user_id = u.id AND ua.dated = ? AND ua.status = 'P' ORDER BY u.username ASC";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $date);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) while ($r = $res->fetch_assoc()) $users[] = $r;
        $stmt->close();
    }
} elseif ($status_filter === 'L') {
    $sql = "SELECT u.id AS user_id, u.username, ua.status, ua.remarks FROM users u JOIN user_attendance ua ON ua.user_id = u.id AND ua.dated = ? AND ua.status = 'L' ORDER BY u.username ASC";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $date);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) while ($r = $res->fetch_assoc()) $users[] = $r;
        $stmt->close();
    }
} else {
    $sql = "SELECT u.id AS user_id, u.username, 'A' AS status, NULL AS remarks FROM users u LEFT JOIN user_attendance ua ON ua.user_id = u.id AND ua.dated = ? WHERE ua.id IS NULL OR ua.status NOT IN ('P','L') ORDER BY u.username ASC";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $date);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) while ($r = $res->fetch_assoc()) $users[] = $r;
        $stmt->close();
    }
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
    <title>Teacher Attendance</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f5f7fa; padding-bottom: 80px; }

        .page-header-card {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            border-radius: 12px;
            padding: 22px 26px;
            color: #fff;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            box-shadow: 0 6px 22px rgba(37, 99, 235, 0.25);
        }
        .page-header-card .header-left { display: flex; align-items: center; gap: 16px; }
        .page-header-card .header-icon {
            width: 52px; height: 52px;
            background: rgba(255,255,255,0.18);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px;
        }
        .page-header-card h1 { font-size: 21px; font-weight: 700; margin: 0; letter-spacing: -0.3px; }
        .page-header-card .subtitle { font-size: 13px; opacity: 0.85; margin-top: 4px; }
        .page-header-card .date-chip {
            background: rgba(255,255,255,0.18);
            padding: 8px 14px; border-radius: 20px;
            font-size: 13px; font-weight: 600;
        }

        .scan-card {
            background: #fff; border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            padding: 18px 22px; margin-bottom: 20px;
            border-top: 4px solid #2563eb;
        }
        .scan-card label {
            font-size: 12px; font-weight: 700; color: #475569;
            text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px;
        }
        .scan-card .form-control {
            border-radius: 8px; border: 1px solid #cbd5e1;
            font-size: 14px; padding: 9px 12px; height: auto;
        }
        .scan-card .form-control:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
        }
        .scan-card .input-lg {
            font-size: 16px; padding: 12px 14px;
            font-weight: 600;
        }
        .scan-feedback {
            margin-top: 8px;
            font-size: 13px;
            min-height: 20px;
            font-weight: 600;
        }
        .scan-feedback.ok { color: #059669; }
        .scan-feedback.err { color: #dc2626; }

        .counter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }
        .counter-box {
            background: #fff; border-radius: 10px;
            padding: 16px 18px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border-left: 4px solid;
            cursor: pointer;
            transition: all 0.15s;
        }
        .counter-box:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .counter-box.total { border-left-color: #2563eb; cursor: default; }
        .counter-box.present { border-left-color: #059669; }
        .counter-box.leave { border-left-color: #f59e0b; }
        .counter-box.absent { border-left-color: #dc2626; }
        .counter-box.active { background: #f8fafc; box-shadow: 0 0 0 2px rgba(37,99,235,0.25), 0 4px 12px rgba(0,0,0,0.08); }
        .counter-box .counter-number { font-size: 24px; font-weight: 700; color: #1e293b; line-height: 1; }
        .counter-box .counter-label { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 700; margin-top: 6px; }
        .counter-box.total .counter-number { color: #2563eb; }
        .counter-box.present .counter-number { color: #059669; }
        .counter-box.leave .counter-number { color: #d97706; }
        .counter-box.absent .counter-number { color: #dc2626; }

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

        .user-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 14px;
            padding: 18px 20px;
        }
        .user-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px;
            transition: all 0.15s;
        }
        .user-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.08);
        }
        .user-card.status-present { background: #f0fdf4; border-color: #bbf7d0; }
        .user-card.status-leave { background: #fffbeb; border-color: #fde68a; }
        .user-card.status-absent { background: #fef2f2; border-color: #fecaca; }

        .user-card .user-name {
            font-size: 15px; font-weight: 700; color: #1e293b;
            display: flex; align-items: center; gap: 8px;
            margin-bottom: 6px;
        }
        .user-card .user-name i { color: #64748b; font-size: 13px; }
        .user-card .status-line {
            font-size: 12px; color: #64748b;
            display: flex; align-items: center; gap: 6px;
            margin-bottom: 10px;
        }
        .status-pill {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 10px; border-radius: 12px;
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.4px;
        }
        .status-pill.present { background: #d1fae5; color: #065f46; }
        .status-pill.leave { background: #fef3c7; color: #92400e; }
        .status-pill.absent { background: #fee2e2; color: #991b1b; }

        .user-card .remarks-box {
            font-size: 12px; color: #64748b;
            background: rgba(0,0,0,0.03);
            padding: 6px 10px; border-radius: 6px;
            margin-bottom: 10px;
        }

        .card-actions { display: flex; flex-wrap: wrap; gap: 6px; }
        .btn-mini {
            font-size: 12px; font-weight: 600;
            padding: 6px 12px; border-radius: 6px;
            border: 1px solid #cbd5e1; background: #fff; color: #475569;
            display: inline-flex; align-items: center; gap: 5px;
            transition: all 0.15s;
        }
        .btn-mini:hover { transform: translateY(-1px); }
        .btn-mini.present { border-color: #059669; color: #059669; }
        .btn-mini.present:hover { background: #d1fae5; }
        .btn-mini.leave { border-color: #d97706; color: #d97706; }
        .btn-mini.leave:hover { background: #fef3c7; }
        .btn-mini.reset { border-color: #dc2626; color: #dc2626; }
        .btn-mini.reset:hover { background: #fee2e2; }

        .empty-state { padding: 55px 20px; text-align: center; }
        .empty-state .empty-icon {
            width: 72px; height: 72px; background: #f1f5f9;
            border-radius: 50%; display: inline-flex;
            align-items: center; justify-content: center;
            font-size: 30px; color: #94a3b8; margin-bottom: 14px;
        }
        .empty-state h5 { font-size: 15px; font-weight: 700; color: #334155; margin: 0 0 6px 0; }
        .empty-state p { font-size: 13px; color: #64748b; margin: 0; }

        .info-note {
            border-radius: 8px; padding: 10px 14px;
            font-size: 12px; margin-bottom: 16px;
            display: flex; align-items: center; gap: 8px;
        }
        .info-note.blue { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
        .info-note.amber { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        .info-note.green { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }

        .modal-content { border-radius: 12px; border: none; }
        .modal-header { border-bottom: 1px solid #e2e8f0; padding: 16px 20px; }
        .modal-title { font-size: 15px; font-weight: 700; color: #1e293b; }
        .modal-body { padding: 18px 20px; }
        .modal-body label {
            font-size: 12px; font-weight: 700; color: #475569;
            text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px;
        }
        .modal-body .form-control {
            border-radius: 8px; border: 1px solid #cbd5e1;
            font-size: 14px; padding: 10px 12px; height: auto;
        }
        .modal-body .form-control:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
        }
        .modal-footer { border-top: 1px solid #e2e8f0; padding: 14px 20px; }

        .btn-brand {
            background: #2563eb; border: 1px solid #2563eb; color: #fff;
            font-weight: 600; font-size: 13px; padding: 9px 18px;
            border-radius: 8px; display: inline-flex; align-items: center; gap: 7px;
        }
        .btn-brand:hover:not(:disabled), .btn-brand:focus:not(:disabled) { background: #1d4ed8; border-color: #1d4ed8; color: #fff; }
        .btn-brand:disabled { background: #94a3b8; border-color: #94a3b8; cursor: not-allowed; }
        .btn-ghost {
            background: #f1f5f9; border: 1px solid #e2e8f0; color: #475569;
            font-weight: 600; font-size: 13px; padding: 9px 16px;
            border-radius: 8px; display: inline-flex; align-items: center; gap: 7px;
        }
        .btn-ghost:hover, .btn-ghost:focus { background: #e2e8f0; color: #1e293b; }
    </style>
</head>
<body>
    <?php require_once('navbar.php'); ?>

    <div class="container" style="padding: 20px; max-width: 1200px; margin: 0 auto;">

        <div class="page-header-card">
            <div class="header-left">
                <div class="header-icon"><i class="fas fa-user-clock"></i></div>
                <div>
                    <h1>Teacher Attendance</h1>
                    <div class="subtitle">Scan or click to mark teacher attendance</div>
                </div>
            </div>
            <div class="date-chip">
                <i class="fas fa-calendar-day"></i>
                <?php echo date('d M Y', strtotime($date)); ?>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-<?php echo htmlspecialchars($message_type); ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
        <?php endif; ?>

        <?php if ($attendance_taken): ?>
            <div class="info-note green">
                <i class="fas fa-check-circle"></i>
                <span>Attendance has been <strong>taken</strong> for this date. Teachers not marked Present or Leave are <strong>Absent</strong>.</span>
            </div>
        <?php else: ?>
            <div class="info-note amber">
                <i class="fas fa-exclamation-triangle"></i>
                <span>Attendance has <strong>not been taken yet</strong> for this date. Nobody is marked absent until you mark someone.</span>
            </div>
        <?php endif; ?>

        <div class="scan-card">
            <div class="row">
                <div class="col-md-4">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="attendance_date">Date</label>
                        <input type="date" id="attendance_date" class="form-control" value="<?php echo htmlspecialchars($date); ?>">
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="username_search">Scan or Type Username</label>
                        <input type="text" id="username_search" class="form-control input-lg" placeholder="Scan barcode or type username then wait..." autocomplete="off">
                        <div id="search_feedback" class="scan-feedback"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="counter-row">
            <div class="counter-box total">
                <div class="counter-number"><?php echo $total_users; ?></div>
                <div class="counter-label">Total Users</div>
            </div>
            <a href="?date=<?php echo urlencode($date); ?>&status=P" style="text-decoration:none; color:inherit;">
                <div class="counter-box present <?php echo ($status_filter === 'P') ? 'active' : ''; ?>">
                    <div class="counter-number"><?php echo $present_count; ?></div>
                    <div class="counter-label">Present</div>
                </div>
            </a>
            <a href="?date=<?php echo urlencode($date); ?>&status=L" style="text-decoration:none; color:inherit;">
                <div class="counter-box leave <?php echo ($status_filter === 'L') ? 'active' : ''; ?>">
                    <div class="counter-number"><?php echo $leave_count; ?></div>
                    <div class="counter-label">On Leave</div>
                </div>
            </a>
            <a href="?date=<?php echo urlencode($date); ?>&status=A" style="text-decoration:none; color:inherit;">
                <div class="counter-box absent <?php echo ($status_filter === 'A') ? 'active' : ''; ?>">
                    <div class="counter-number"><?php echo $absent_count; ?></div>
                    <div class="counter-label">Absent (implicit)</div>
                </div>
            </a>
        </div>

        <div class="list-card">
            <div class="list-card-head">
                <h3>
                    <i class="fas fa-users"></i>
                    <?php
                    if ($status_filter === 'P') echo 'Present Teachers';
                    elseif ($status_filter === 'L') echo 'Teachers on Leave';
                    else echo 'Absent Teachers';
                    ?>
                </h3>
                <span class="count-chip"><?php echo count($users); ?> user(s)</span>
            </div>

            <?php if (empty($users)): ?>
                <div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-user-slash"></i></div>
                    <h5>No teachers in this list</h5>
                    <p>No one matches the selected status for <?php echo date('d M Y', strtotime($date)); ?>.</p>
                </div>
            <?php else: ?>
                <div class="user-grid">
                    <?php foreach ($users as $u):
                        $uid = intval($u['user_id']);
                        $uname = $u['username'];
                        $ustatus = $u['status'];
                        $uremarks = $u['remarks'];
                        $st_class = 'status-absent';
                        $st_pill = 'absent';
                        $st_label = 'Absent';
                        if ($ustatus === 'P') { $st_class = 'status-present'; $st_pill = 'present'; $st_label = 'Present'; }
                        elseif ($ustatus === 'L') { $st_class = 'status-leave'; $st_pill = 'leave'; $st_label = 'Leave'; }
                    ?>
                    <div class="user-card <?php echo $st_class; ?>" data-user-id="<?php echo $uid; ?>" data-username="<?php echo htmlspecialchars($uname, ENT_QUOTES); ?>">
                        <div class="user-name">
                            <i class="fas fa-user-circle"></i>
                            <?php echo htmlspecialchars($uname); ?>
                        </div>
                        <div class="status-line">
                            Status: <span class="status-pill <?php echo $st_pill; ?>"><?php echo $st_label; ?></span>
                        </div>
                        <?php if ($ustatus === 'L' && !empty($uremarks)): ?>
                            <div class="remarks-box">
                                <i class="fas fa-comment-alt"></i> <?php echo htmlspecialchars($uremarks); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($can_edit): ?>
                        <div class="card-actions">
                            <?php if ($ustatus !== 'P'): ?>
                                <button type="button" class="btn-mini present mark-present">
                                    <i class="fas fa-check"></i> Present
                                </button>
                            <?php endif; ?>
                            <?php if ($ustatus !== 'L'): ?>
                                <button type="button" class="btn-mini leave mark-leave">
                                    <i class="fas fa-clock"></i> Leave
                                </button>
                            <?php endif; ?>
                            <?php if ($ustatus !== 'A'): ?>
                                <button type="button" class="btn-mini reset mark-absent">
                                    <i class="fas fa-undo"></i> Reset
                                </button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- Leave Modal -->
    <div class="modal fade" id="leaveModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Leave Reason</h5>
                    <button type="button" class="close" id="leaveModalCloseBtn" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="leave_user_id">

                    <div class="form-group" style="margin-bottom: 14px;">
                        <label for="leave_reason">Reason</label>
                        <select id="leave_reason" class="form-control">
                            <option value="">-- Select Reason --</option>
                            <option value="Sick">Sick</option>
                            <option value="Leave">Leave</option>
                            <option value="Urgent Work">Urgent Work</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="form-group" id="leave_other_wrap" style="display: none; margin-bottom: 0;">
                        <label for="leave_reason_other">Specify Reason</label>
                        <input type="text" id="leave_reason_other" class="form-control" placeholder="Enter reason...">
                    </div>

                    <div id="leave_feedback" style="font-size: 12px; color: #dc2626; margin-top: 10px; min-height: 16px;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-ghost" id="leaveModalCancelBtn">Cancel</button>
                    <button type="button" class="btn-brand" id="saveLeave">
                        <i class="fas fa-save"></i> Save Leave
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
    var CURRENT_DATE = <?php echo json_encode($date); ?>;

    $(document).ready(function() {

        $('#attendance_date').on('change', function() {
            var newDate = $(this).val();
            window.location.href = '?date=' + newDate + '&status=<?php echo $status_filter; ?>';
        });

        function focusSearchBox() {
            if (!$('#leaveModal').hasClass('show')) {
                $('#username_search').focus();
            }
        }
        focusSearchBox();

        function showFeedback(message, ok) {
            var $fb = $('#search_feedback');
            $fb.text(message).removeClass('ok err').addClass(ok ? 'ok' : 'err');
            if (ok) {
                setTimeout(function() { $fb.text('').removeClass('ok err'); }, 3000);
            }
        }

        function postAction(data) {
            $.ajax({
                url: '',
                type: 'POST',
                dataType: 'json',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                data: data
            }).done(function(res) {
                if (res && res.success) {
                    showFeedback(res.message || 'Done.', true);
                    setTimeout(function() { location.reload(); }, 300);
                } else {
                    showFeedback((res && res.message) ? res.message : 'Action failed.', false);
                }
            }).fail(function() {
                showFeedback('Request failed.', false);
            });
        }

        $(document).on('click', '.mark-present', function() {
            var uid = $(this).closest('.user-card').data('user-id');
            postAction({ action: 'mark_present', user_id: uid, date: CURRENT_DATE });
        });

        $(document).on('click', '.mark-absent', function() {
            var uid = $(this).closest('.user-card').data('user-id');
            if (!confirm('Reset this teacher to Absent for today?')) return;
            postAction({ action: 'reset', user_id: uid, date: CURRENT_DATE });
        });

        /* Open leave modal */
        $(document).on('click', '.mark-leave', function() {
            var uid = $(this).closest('.user-card').data('user-id');
            $('#leave_user_id').val(uid);
            $('#leave_reason').val('');
            $('#leave_reason_other').val('');
            $('#leave_other_wrap').hide();
            $('#leave_feedback').text('');
            $('#leaveModal').modal('show');
        });

        /* Show/hide the "Other" input based on dropdown */
        $('#leave_reason').on('change', function() {
            if ($(this).val() === 'Other') {
                $('#leave_other_wrap').show();
                $('#leave_reason_other').focus();
            } else {
                $('#leave_other_wrap').hide();
                $('#leave_reason_other').val('');
            }
        });

        /* Modal close buttons (explicit, no data-dismiss) */
        $('#leaveModalCloseBtn, #leaveModalCancelBtn').on('click', function() {
            $('#leaveModal').modal('hide');
        });

        $('#leaveModal').on('shown.bs.modal', function() {
            $('#leave_reason').focus();
        });

        $('#leaveModal').on('hidden.bs.modal', function() {
            focusSearchBox();
        });

        /* Save leave */
        $('#saveLeave').on('click', function() {
            var uid = $('#leave_user_id').val();
            var reason = $('#leave_reason').val();
            var reason_other = $.trim($('#leave_reason_other').val());

            if (!reason) {
                $('#leave_feedback').text('Please select a reason.');
                $('#leave_reason').focus();
                return;
            }
            if (reason === 'Other' && !reason_other) {
                $('#leave_feedback').text('Please specify the reason.');
                $('#leave_reason_other').focus();
                return;
            }

            $('#leave_feedback').text('');
            var $btn = $(this);
            $btn.prop('disabled', true);

            $.ajax({
                url: '',
                type: 'POST',
                dataType: 'json',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                data: {
                    action: 'mark_leave',
                    user_id: uid,
                    date: CURRENT_DATE,
                    reason: reason,
                    reason_other: reason_other
                }
            }).done(function(res) {
                if (res && res.success) {
                    showFeedback(res.message, true);
                    $('#leaveModal').modal('hide');
                    setTimeout(function() { location.reload(); }, 300);
                } else {
                    $('#leave_feedback').text((res && res.message) ? res.message : 'Failed to save leave.');
                }
            }).fail(function() {
                $('#leave_feedback').text('Request failed. Please try again.');
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });

        /* Scan username */
        var scanTimer = null;
        $('#username_search').on('input', function() {
            clearTimeout(scanTimer);
            var username = $.trim($(this).val());
            if (!username) return;

            scanTimer = setTimeout(function() {
                $('#username_search').val('');
                showFeedback('Processing...', true);

                $.ajax({
                    url: '',
                    type: 'POST',
                    dataType: 'json',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    data: { action: 'scan', username: username, date: CURRENT_DATE }
                }).done(function(res) {
                    if (res && res.success) {
                        showFeedback(res.message, true);
                        setTimeout(function() { location.reload(); }, 300);
                    } else {
                        showFeedback((res && res.message) ? res.message : 'Scan failed.', false);
                    }
                }).fail(function() {
                    showFeedback('Request failed.', false);
                });
            }, 700);
        });
    });
    </script>
</body>
</html>
<?php $conn->close(); ?>