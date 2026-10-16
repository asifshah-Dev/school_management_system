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
$current_user_id = intval($_SESSION['user_id'] ?? 0);

/* ============================================================
   POST — save attendance for one slot
   Stores P, L, and A explicitly. Absent is now written, not deleted.
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $class_id     = intval($_POST['class_id'] ?? 0);
    $session_id   = intval($_POST['session_id'] ?? 0);
    $subject_id   = intval($_POST['subject_id'] ?? 0);
    $time_slot_id = intval($_POST['time_slot_id'] ?? 0);
    $date         = trim($_POST['date'] ?? date('Y-m-d'));
    $statuses     = $_POST['status'] ?? [];
    $remarks      = $_POST['remarks'] ?? [];
    $remarks_other = $_POST['remarks_other'] ?? [];
    $all_student_ids = $_POST['all_student_ids'] ?? [];

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

    if ($class_id > 0 && $subject_id > 0 && is_array($all_student_ids) && !empty($all_student_ids)) {

        $day_id = intval(date('N', strtotime($date)));
        $class_section_id = 0;

        /* Single upsert for all three statuses */
        $ins = $conn->prepare("INSERT INTO lecture_attendance (student_id, class_id, class_section_id, session_id, subject_id, time_slot_id, attendance_date, day_id, status, remarks, marked_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status), remarks = VALUES(remarks), marked_by = VALUES(marked_by), marked_at = NOW()");

        $saved = 0;

        foreach ($all_student_ids as $sid_raw) {
            $sid = intval($sid_raw);
            if ($sid <= 0) continue;

            /* Default to A when no status posted for the student */
            $st = isset($statuses[$sid]) ? trim($statuses[$sid]) : 'A';
            if ($st === '' || !in_array($st, ['P', 'L', 'A'], true)) $st = 'A';

            if ($st === 'P') {
                $remark = '';
                if ($ins) {
                    $ins->bind_param("iiiiiisissi", $sid, $class_id, $class_section_id, $session_id, $subject_id, $time_slot_id, $date, $day_id, $st, $remark, $current_user_id);
                    if ($ins->execute()) $saved++;
                }
            } elseif ($st === 'L') {
                $remark = trim($remarks[$sid] ?? '');
                if ($remark === 'Other') {
                    $other = trim($remarks_other[$sid] ?? '');
                    $remark = $other !== '' ? $other : 'Other';
                }
                if ($remark === '') $remark = 'Leave';
                if ($ins) {
                    $ins->bind_param("iiiiiisissi", $sid, $class_id, $class_section_id, $session_id, $subject_id, $time_slot_id, $date, $day_id, $st, $remark, $current_user_id);
                    if ($ins->execute()) $saved++;
                }
            } else {
                /* Absent — stored explicitly, no remark */
                $remark = '';
                if ($ins) {
                    $ins->bind_param("iiiiiisissi", $sid, $class_id, $class_section_id, $session_id, $subject_id, $time_slot_id, $date, $day_id, $st, $remark, $current_user_id);
                    if ($ins->execute()) $saved++;
                }
            }
        }

        if ($ins) $ins->close();

        $_SESSION['message'] = 'Lecture attendance saved. ' . $saved . ' record(s) written (Present / Leave / Absent).';
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = 'Missing required fields.';
        $_SESSION['message_type'] = 'danger';
    }

    $redirect = 'lecture_attendance.php?date=' . urlencode($date) . '&class_id=' . $class_id . '&session_id=' . $session_id . '&subject_id=' . $subject_id . '&time_slot_id=' . $time_slot_id;
    header('Location: ' . $redirect);
    exit;
}

/* ============================================================
   LOAD DROPDOWNS
   ============================================================ */
$sessions = [];
$s_res = $conn->query("SELECT id, title FROM sessions ORDER BY from_dated DESC, id DESC");
if ($s_res) while ($r = $s_res->fetch_assoc()) $sessions[$r['id']] = $r['title'];

$classes = [];
$c_res = $conn->query("SELECT id, title FROM classes WHERE status = 0 OR status IS NULL ORDER BY title");
if ($c_res) while ($r = $c_res->fetch_assoc()) $classes[$r['id']] = $r['title'];

$time_slots = [];
$ts_res = $conn->query("SELECT id, title, start_time, end_time FROM time_slots WHERE (title NOT LIKE '%break%') AND status = 0 ORDER BY start_time");
if ($ts_res) while ($r = $ts_res->fetch_assoc()) $time_slots[$r['id']] = $r;

/* ============================================================
   READ FILTERS
   ============================================================ */
$date = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
$day_id = intval(date('N', strtotime($date)));

$selected_session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
if ($selected_session_id <= 0 && !empty($sessions)) {
    $fk = array_key_first($sessions);
    $selected_session_id = intval($fk);
}

$selected_class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;

/* ============================================================
   SUBJECT LIST — timetable first, fallback to subject_class
   ============================================================ */
$lectures = [];
$lecture_source = '';

if ($selected_class_id > 0 && $selected_session_id > 0) {
    $tt_sql = "SELECT t.time_slot_id, t.subject_id, ts.title AS slot_title, ts.start_time, ts.end_time, s.title AS subject_title FROM timetable t LEFT JOIN time_slots ts ON ts.id = t.time_slot_id LEFT JOIN subjects s ON s.id = t.subject_id WHERE t.class_id = ? AND t.day_id = ? AND t.status = 0 GROUP BY t.subject_id, t.time_slot_id, ts.title, ts.start_time, ts.end_time, s.title ORDER BY ts.start_time";
    $tt_stmt = $conn->prepare($tt_sql);
    if ($tt_stmt) {
        $tt_stmt->bind_param("ii", $selected_class_id, $day_id);
        $tt_stmt->execute();
        $tt_res = $tt_stmt->get_result();
        if ($tt_res) {
            while ($r = $tt_res->fetch_assoc()) {
                $lectures[] = [
                    'subject_id' => intval($r['subject_id']),
                    'subject_title' => $r['subject_title'] ?? ('Subject #' . $r['subject_id']),
                    'time_slot_id' => intval($r['time_slot_id']),
                    'slot_title' => $r['slot_title'] ?? ('Period #' . $r['time_slot_id']),
                    'source' => 'timetable'
                ];
            }
        }
        $tt_stmt->close();
    }

    if (!empty($lectures)) {
        $lecture_source = 'timetable';
    } else {
        $sc_sql = "SELECT sc.subject_id, s.title AS subject_title FROM subject_class sc JOIN subjects s ON s.id = sc.subject_id WHERE sc.class_id = ? AND sc.status = 0 ORDER BY s.title";
        $sc_stmt = $conn->prepare($sc_sql);
        if ($sc_stmt) {
            $sc_stmt->bind_param("i", $selected_class_id);
            $sc_stmt->execute();
            $sc_res = $sc_stmt->get_result();
            if ($sc_res) {
                while ($r = $sc_res->fetch_assoc()) {
                    $lectures[] = [
                        'subject_id' => intval($r['subject_id']),
                        'subject_title' => $r['subject_title'] ?? ('Subject #' . $r['subject_id']),
                        'time_slot_id' => 0,
                        'slot_title' => 'Full Session',
                        'source' => 'subject_class'
                    ];
                }
            }
            $sc_stmt->close();
        }
        if (!empty($lectures)) $lecture_source = 'subject_class';
    }
}

$selected_subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;
$selected_slot_id    = isset($_GET['time_slot_id']) ? intval($_GET['time_slot_id']) : -1;

if (!empty($lectures)) {
    $found = false;
    if ($selected_subject_id > 0) {
        foreach ($lectures as $lec) {
            if (intval($lec['subject_id']) === $selected_subject_id && intval($lec['time_slot_id']) === $selected_slot_id) {
                $found = true;
                break;
            }
        }
    }
    if (!$found) {
        $first = $lectures[0];
        $selected_subject_id = intval($first['subject_id']);
        $selected_slot_id = intval($first['time_slot_id']);
    }
} else {
    $selected_subject_id = 0;
    $selected_slot_id = -1;
}

/* ============================================================
   LOAD STUDENTS + EXISTING ATTENDANCE
   ============================================================ */
$students = [];
$existing_attendance = [];
$attendance_taken = false;

if ($selected_class_id > 0 && $selected_session_id > 0 && $selected_subject_id > 0 && $selected_slot_id >= 0) {

    $stu_sql = "SELECT sr.id, sr.reg_no, sr.name FROM student_class sc JOIN student_registration sr ON sr.id = sc.student_registration_id WHERE sc.class_id = ? AND sc.session_id = ? AND sr.status = 0 ORDER BY sr.reg_no, sr.name";
    $stu_stmt = $conn->prepare($stu_sql);
    if ($stu_stmt) {
        $stu_stmt->bind_param("ii", $selected_class_id, $selected_session_id);
        $stu_stmt->execute();
        $stu_res = $stu_stmt->get_result();
        if ($stu_res) {
            while ($r = $stu_res->fetch_assoc()) $students[] = $r;
        }
        $stu_stmt->close();
    }

    if (!empty($students)) {
        $att_sql = "SELECT student_id, status, remarks FROM lecture_attendance WHERE attendance_date = ? AND time_slot_id = ? AND class_id = ? AND subject_id = ?";
        $att_stmt = $conn->prepare($att_sql);
        if ($att_stmt) {
            $att_stmt->bind_param("siii", $date, $selected_slot_id, $selected_class_id, $selected_subject_id);
            $att_stmt->execute();
            $att_res = $att_stmt->get_result();
            if ($att_res) {
                while ($r = $att_res->fetch_assoc()) {
                    $existing_attendance[intval($r['student_id'])] = [
                        'status' => $r['status'],
                        'remarks' => $r['remarks']
                    ];
                }
            }
            $att_stmt->close();
        }
        if (!empty($existing_attendance)) $attendance_taken = true;
    }
}

$leave_reasons = ['Sick', 'Leave', 'Urgent Work', 'Other'];

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
    <title>Lecture Attendance</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f5f7fa; padding-bottom: 80px; }

        .page-header-card { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); border-radius: 12px; padding: 22px 26px; color: #fff; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; box-shadow: 0 6px 22px rgba(37, 99, 235, 0.25); }
        .page-header-card .header-left { display: flex; align-items: center; gap: 16px; }
        .page-header-card .header-icon { width: 52px; height: 52px; background: rgba(255,255,255,0.18); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; }
        .page-header-card h1 { font-size: 21px; font-weight: 700; margin: 0; }
        .page-header-card .subtitle { font-size: 13px; opacity: 0.85; margin-top: 4px; }
        .page-header-card .date-chip { background: rgba(255,255,255,0.18); padding: 8px 14px; border-radius: 20px; font-size: 13px; font-weight: 600; }

        .filter-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.06); padding: 18px 22px; margin-bottom: 20px; border-top: 4px solid #2563eb; }
        .filter-card label { font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px; }
        .filter-card .form-control { border-radius: 8px; border: 1px solid #cbd5e1; font-size: 14px; padding: 9px 12px; height: auto; }
        .filter-card .form-control:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.12); }

        .counter-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 20px; }
        .counter-box { background: #fff; border-radius: 10px; padding: 16px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border-left: 4px solid; }
        .counter-box.total { border-left-color: #2563eb; }
        .counter-box.present { border-left-color: #059669; }
        .counter-box.absent { border-left-color: #dc2626; }
        .counter-box.leave { border-left-color: #f59e0b; }
        .counter-box.unmarked { border-left-color: #94a3b8; }
        .counter-box .counter-number { font-size: 24px; font-weight: 700; line-height: 1; }
        .counter-box .counter-label { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 700; margin-top: 6px; }
        .counter-box.total .counter-number { color: #2563eb; }
        .counter-box.present .counter-number { color: #059669; }
        .counter-box.absent .counter-number { color: #dc2626; }
        .counter-box.leave .counter-number { color: #d97706; }
        .counter-box.unmarked .counter-number { color: #64748b; }

        .list-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.06); overflow: hidden; }
        .list-card-head { padding: 16px 20px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
        .list-card-head h3 { font-size: 15px; font-weight: 700; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 8px; }
        .list-card-head h3 i { color: #2563eb; }

        .quick-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn-mini { font-size: 12px; font-weight: 600; padding: 6px 12px; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff; color: #475569; display: inline-flex; align-items: center; gap: 5px; }
        .btn-mini:hover { background: #f1f5f9; color: #1e293b; }
        .btn-mini.all-present:hover { background: #d1fae5; border-color: #6ee7b7; color: #065f46; }
        .btn-mini.all-absent:hover { background: #fee2e2; border-color: #fca5a5; color: #991b1b; }
        .btn-mini.clear-all:hover { background: #e2e8f0; border-color: #94a3b8; color: #1e293b; }

        table.att-table { width: 100%; margin: 0; border-collapse: separate; border-spacing: 0; }
        table.att-table thead th { background: #f8fafc; color: #475569; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; padding: 11px 12px; border-bottom: 1px solid #e2e8f0; white-space: nowrap; text-align: center; }
        table.att-table thead th.left { text-align: left; }
        table.att-table tbody td { padding: 12px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; font-size: 14px; color: #1e293b; text-align: center; }
        table.att-table tbody td.left { text-align: left; }
        table.att-table tbody tr:hover { background: #f8fafc; }
        table.att-table tbody tr.row-present { background: #f0fdf4; }
        table.att-table tbody tr.row-leave { background: #fffbeb; }
        table.att-table tbody tr.row-absent { background: #fef2f2; }
        table.att-table tbody tr.row-present:hover { background: #dcfce7; }
        table.att-table tbody tr.row-leave:hover { background: #fef3c7; }
        table.att-table tbody tr.row-absent:hover { background: #fee2e2; }

        .sr-badge { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; background: #dbeafe; color: #2563eb; font-weight: 700; font-size: 12px; border-radius: 6px; }
        .student-name { font-weight: 600; color: #1e293b; }
        .student-reg { color: #94a3b8; font-size: 12px; }

        .radio-pill { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; user-select: none; padding: 6px 12px; border-radius: 20px; border: 1.5px solid #e2e8f0; background: #fff; font-size: 12px; font-weight: 600; color: #64748b; transition: all 0.15s; }
        .radio-pill input { display: none; }
        .radio-pill:hover { border-color: #cbd5e1; background: #f8fafc; }
        .radio-pill.present.checked { background: #d1fae5; border-color: #059669; color: #065f46; }
        .radio-pill.absent.checked { background: #fee2e2; border-color: #dc2626; color: #991b1b; }
        .radio-pill.leave.checked { background: #fef3c7; border-color: #d97706; color: #92400e; }

        .remarks-wrap { display: flex; gap: 6px; align-items: center; justify-content: center; }
        .remarks-wrap select.remark-select { font-size: 12px; padding: 5px 8px; height: auto; border-radius: 6px; border: 1px solid #cbd5e1; min-width: 120px; }
        .remarks-wrap input.remark-other { font-size: 12px; padding: 5px 8px; height: auto; border-radius: 6px; border: 1px solid #cbd5e1; width: 130px; }

        .action-bar { padding: 16px 20px; border-top: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; background: #fafbfc; }
        .btn-success-brand { background: #059669; border: 1px solid #059669; color: #fff; font-weight: 600; font-size: 14px; padding: 10px 22px; border-radius: 8px; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; }
        .btn-success-brand:hover:not(:disabled), .btn-success-brand:focus:not(:disabled) { background: #047857; border-color: #047857; color: #fff; box-shadow: 0 4px 12px rgba(5,150,105,0.3); }
        .btn-success-brand:disabled { background: #94a3b8; border-color: #94a3b8; cursor: not-allowed; }

        .btn-brand { background: #2563eb; border: 1px solid #2563eb; color: #fff; font-weight: 600; font-size: 13px; padding: 9px 18px; border-radius: 8px; display: inline-flex; align-items: center; gap: 7px; }
        .btn-brand:hover, .btn-brand:focus { background: #1d4ed8; border-color: #1d4ed8; color: #fff; }

        .empty-state { padding: 55px 20px; text-align: center; }
        .empty-state .empty-icon { width: 72px; height: 72px; background: #dbeafe; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 30px; color: #2563eb; margin-bottom: 14px; }
        .empty-state h5 { font-size: 15px; font-weight: 700; color: #334155; margin: 0 0 6px 0; }
        .empty-state p { font-size: 13px; color: #64748b; margin: 0; }

        .info-note { border-radius: 8px; padding: 10px 14px; font-size: 12px; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .info-note.amber { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        .info-note.green { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }

        .lecture-chip-grid { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
        .lecture-chip { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 20px; border: 1.5px solid #e2e8f0; background: #fff; color: #475569; font-size: 12px; font-weight: 600; text-decoration: none; }
        .lecture-chip:hover { border-color: #2563eb; color: #2563eb; text-decoration: none; }
        .lecture-chip.active { background: #2563eb; border-color: #2563eb; color: #fff; }
        .lecture-chip small { opacity: 0.7; font-weight: 500; }
    </style>
</head>
<body>
    <?php require_once('navbar.php'); ?>

    <div class="container" style="padding: 20px; max-width: 1200px; margin: 0 auto;">

        <div class="page-header-card">
            <div class="header-left">
                <div class="header-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                <div>
                    <h1>Lecture Attendance</h1>
                    <div class="subtitle">Mark attendance per period / subject</div>
                </div>
            </div>
            <div class="date-chip">
                <i class="fas fa-calendar-day"></i>
                <?php echo date('d M Y', strtotime($date)); ?>
                <?php
                $day_names = [1=>'Mon', 2=>'Tue', 3=>'Wed', 4=>'Thu', 5=>'Fri', 6=>'Sat', 7=>'Sun'];
                $dname = $day_names[$day_id] ?? '';
                if ($dname) echo ' &middot; ' . $dname;
                ?>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-<?php echo htmlspecialchars($message_type); ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
        <?php endif; ?>

        <div class="filter-card">
            <form method="get" action="" id="filterForm">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Date</label>
                            <input type="date" class="form-control" name="date" id="dateInput" value="<?php echo htmlspecialchars($date); ?>" required>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Session</label>
                            <select class="form-control" name="session_id" id="sessionSelect">
                                <?php if (empty($sessions)): ?>
                                    <option value="">No sessions</option>
                                <?php else: ?>
                                    <?php foreach ($sessions as $sid => $sname): ?>
                                        <option value="<?php echo (int)$sid; ?>" <?php echo ((int)$sid === $selected_session_id) ? 'selected' : ''; ?>><?php echo htmlspecialchars($sname); ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Class</label>
                            <select class="form-control" name="class_id" id="classSelect">
                                <option value="">-- Select Class --</option>
                                <?php foreach ($classes as $cid => $cname): ?>
                                    <option value="<?php echo (int)$cid; ?>" <?php echo ((int)$cid === $selected_class_id) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cname); ?></option>
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

                <?php if (!empty($lectures)): ?>
                    <div style="margin-top: 16px; padding-top: 16px; border-top: 1px dashed #e2e8f0;">
                        <label style="display:block; margin-bottom: 8px;">Pick a Slot / Period</label>
                        <div class="lecture-chip-grid">
                            <?php foreach ($lectures as $lec):
                                $lid = intval($lec['time_slot_id']);
                                $subid = intval($lec['subject_id']);
                                $is_active = ($selected_subject_id === $subid && $selected_slot_id === $lid);
                                $chip_url = '?date=' . urlencode($date) . '&session_id=' . $selected_session_id . '&class_id=' . $selected_class_id . '&subject_id=' . $subid . '&time_slot_id=' . $lid;
                            ?>
                                <a href="<?php echo $chip_url; ?>" class="lecture-chip <?php echo $is_active ? 'active' : ''; ?>">
                                    <i class="fas fa-book"></i>
                                    <?php echo htmlspecialchars($lec['subject_title']); ?>
                                    <small>&middot; <?php echo htmlspecialchars($lec['slot_title']); ?></small>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <div style="margin-top: 8px; font-size: 12px; color: #94a3b8;">
                            <?php if ($lecture_source === 'timetable'): ?>
                                Source: Timetable for this weekday
                            <?php elseif ($lecture_source === 'subject_class'): ?>
                                Source: Subject list for this class (no timetable found for this weekday)
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <?php if ($selected_class_id > 0 && $selected_session_id > 0 && $selected_subject_id > 0 && !empty($students)): ?>

            <?php if ($attendance_taken): ?>
                <div class="info-note green">
                    <i class="fas fa-check-circle"></i>
                    <span>Attendance has been <strong>taken</strong> for this lecture. Unmarked students are <strong>Absent</strong>.</span>
                </div>
            <?php else: ?>
                <div class="info-note amber">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Attendance has <strong>not been taken yet</strong> for this lecture. Unmarked students default to <strong>Absent</strong> when you save.</span>
                </div>
            <?php endif; ?>

            <div class="counter-row">
                <div class="counter-box total">
                    <div class="counter-number" id="counterTotal"><?php echo count($students); ?></div>
                    <div class="counter-label">Total Students</div>
                </div>
                <div class="counter-box present">
                    <div class="counter-number" id="counterPresent">0</div>
                    <div class="counter-label">Present</div>
                </div>
                <div class="counter-box absent">
                    <div class="counter-number" id="counterAbsent">0</div>
                    <div class="counter-label">Absent</div>
                </div>
                <div class="counter-box leave">
                    <div class="counter-number" id="counterLeave">0</div>
                    <div class="counter-label">On Leave</div>
                </div>
                <div class="counter-box unmarked">
                    <div class="counter-number" id="counterUnmarked"><?php echo count($students); ?></div>
                    <div class="counter-label">Not Marked</div>
                </div>
            </div>

            <form method="post" action="" id="attendanceForm">
                <input type="hidden" name="class_id" value="<?php echo (int)$selected_class_id; ?>">
                <input type="hidden" name="session_id" value="<?php echo (int)$selected_session_id; ?>">
                <input type="hidden" name="subject_id" value="<?php echo (int)$selected_subject_id; ?>">
                <input type="hidden" name="time_slot_id" value="<?php echo (int)$selected_slot_id; ?>">
                <input type="hidden" name="date" value="<?php echo htmlspecialchars($date); ?>">
                <?php foreach ($students as $s): ?>
                    <input type="hidden" name="all_student_ids[]" value="<?php echo intval($s['id']); ?>">
                <?php endforeach; ?>

                <div class="list-card">
                    <div class="list-card-head">
                        <h3><i class="fas fa-users"></i> Mark Attendance</h3>
                        <?php if ($can_edit): ?>
                            <div class="quick-actions">
                                <button type="button" class="btn-mini all-present" id="btnAllPresent"><i class="fas fa-check"></i> All Present</button>
                                <button type="button" class="btn-mini all-absent" id="btnAllAbsent"><i class="fas fa-times"></i> All Absent</button>
                                <button type="button" class="btn-mini clear-all" id="btnClearAll"><i class="fas fa-undo"></i> Clear</button>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div style="overflow-x: auto;">
                        <table class="att-table">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">#</th>
                                    <th class="left">Student</th>
                                    <th style="width: 110px;">Present</th>
                                    <th style="width: 110px;">Absent</th>
                                    <th style="width: 110px;">Leave</th>
                                    <th style="width: 260px;">Leave Reason</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $sr = 1;
                                foreach ($students as $s):
                                    $sid = intval($s['id']);
                                    $cur_status = '';
                                    $cur_remark = '';
                                    if (isset($existing_attendance[$sid])) {
                                        $cur_status = $existing_attendance[$sid]['status'];
                                        $cur_remark = $existing_attendance[$sid]['remarks'];
                                    }
                                    $row_class = '';
                                    if ($cur_status === 'P') $row_class = 'row-present';
                                    elseif ($cur_status === 'L') $row_class = 'row-leave';
                                    elseif ($cur_status === 'A') $row_class = 'row-absent';

                                    $remark_is_other = ($cur_status === 'L' && $cur_remark !== '' && !in_array($cur_remark, $leave_reasons));
                                    $remark_select_val = $remark_is_other ? 'Other' : $cur_remark;
                                    $remark_other_val = $remark_is_other ? $cur_remark : '';
                                ?>
                                <tr class="<?php echo $row_class; ?>" data-student-id="<?php echo $sid; ?>">
                                    <td><span class="sr-badge"><?php echo $sr++; ?></span></td>
                                    <td class="left">
                                        <div class="student-name"><?php echo htmlspecialchars($s['name']); ?></div>
                                        <?php if (!empty($s['reg_no'])): ?>
                                            <div class="student-reg"><?php echo htmlspecialchars($s['reg_no']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <label class="radio-pill present <?php echo $cur_status === 'P' ? 'checked' : ''; ?>">
                                            <input type="radio" name="status[<?php echo $sid; ?>]" value="P" <?php echo $cur_status === 'P' ? 'checked' : ''; ?>>
                                            <i class="fas fa-check"></i> P
                                        </label>
                                    </td>
                                    <td>
                                        <label class="radio-pill absent <?php echo $cur_status === 'A' ? 'checked' : ''; ?>">
                                            <input type="radio" name="status[<?php echo $sid; ?>]" value="A" <?php echo $cur_status === 'A' ? 'checked' : ''; ?>>
                                            <i class="fas fa-times"></i> A
                                        </label>
                                    </td>
                                    <td>
                                        <label class="radio-pill leave <?php echo $cur_status === 'L' ? 'checked' : ''; ?>">
                                            <input type="radio" name="status[<?php echo $sid; ?>]" value="L" <?php echo $cur_status === 'L' ? 'checked' : ''; ?>>
                                            <i class="fas fa-clock"></i> L
                                        </label>
                                    </td>
                                    <td>
                                        <div class="remarks-wrap">
                                            <select name="remarks[<?php echo $sid; ?>]" class="remark-select" <?php echo $cur_status === 'L' ? '' : 'disabled'; ?>>
                                                <option value="">-- Reason --</option>
                                                <?php foreach ($leave_reasons as $lr): ?>
                                                    <option value="<?php echo htmlspecialchars($lr); ?>" <?php echo ($remark_select_val === $lr) ? 'selected' : ''; ?>><?php echo htmlspecialchars($lr); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="text" name="remarks_other[<?php echo $sid; ?>]" class="remark-other" placeholder="Specify..." value="<?php echo htmlspecialchars($remark_other_val); ?>" style="<?php echo ($cur_status === 'L' && $remark_select_val === 'Other') ? '' : 'display:none;'; ?>">
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($can_edit): ?>
                        <div class="action-bar">
                            <div style="font-size: 13px; color: #64748b;">
                                <i class="fas fa-info-circle"></i> Every student is saved: <strong>P</strong>, <strong>L</strong>, or <strong>A</strong>.
                            </div>
                            <div>
                                <button type="submit" class="btn-success-brand" id="btnSave"><i class="fas fa-save"></i> Save Attendance</button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </form>

        <?php elseif ($selected_class_id > 0 && $selected_session_id > 0 && empty($lectures)): ?>
            <div class="list-card">
                <div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-book"></i></div>
                    <h5>No lectures defined</h5>
                    <p>No timetable rows exist for this class on this weekday, and no subjects are assigned to this class. Add a timetable or assign subjects first.</p>
                </div>
            </div>
        <?php elseif ($selected_class_id > 0 && $selected_session_id > 0 && empty($students)): ?>
            <div class="list-card">
                <div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-user-slash"></i></div>
                    <h5>No students found</h5>
                    <p>No active students are enrolled in this class for the selected session.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="list-card">
                <div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-filter"></i></div>
                    <h5>Select filters</h5>
                    <p>Pick a date, session, and class above to load the lecture list.</p>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <script>
    $(document).ready(function() {
        $('#dateInput, #sessionSelect, #classSelect').on('change', function() {
            $('#filterForm').submit();
        });

        function updateRowStyling($row, status) {
            $row.removeClass('row-present row-leave row-absent');
            if (status === 'P') $row.addClass('row-present');
            else if (status === 'L') $row.addClass('row-leave');
            else if (status === 'A') $row.addClass('row-absent');

            $row.find('.radio-pill').removeClass('checked');
            if (status === 'P') $row.find('.radio-pill.present').addClass('checked');
            else if (status === 'A') $row.find('.radio-pill.absent').addClass('checked');
            else if (status === 'L') $row.find('.radio-pill.leave').addClass('checked');

            var $rs = $row.find('.remark-select');
            var $ro = $row.find('.remark-other');
            if (status === 'L') $rs.prop('disabled', false);
            else { $rs.prop('disabled', true).val(''); $ro.val('').hide(); }
        }

        function updateCounters() {
            var present = 0, absent = 0, leave = 0, unmarked = 0, total = 0;
            $('tr[data-student-id]').each(function() {
                total++;
                var val = $(this).find('input[type="radio"]:checked').val();
                if (val === 'P') present++;
                else if (val === 'A') absent++;
                else if (val === 'L') leave++;
                else unmarked++;
            });
            $('#counterTotal').text(total);
            $('#counterPresent').text(present);
            $('#counterAbsent').text(absent);
            $('#counterLeave').text(leave);
            $('#counterUnmarked').text(unmarked);
        }

        $(document).on('change', 'input[type="radio"][name^="status"]', function() {
            var $row = $(this).closest('tr');
            updateRowStyling($row, $(this).val());
            updateCounters();
        });

        $(document).on('change', '.remark-select', function() {
            var $row = $(this).closest('tr');
            var $other = $row.find('.remark-other');
            if ($(this).val() === 'Other') $other.show().focus();
            else $other.val('').hide();
        });

        $('#btnAllPresent').on('click', function() {
            $('tr[data-student-id]').each(function() {
                $(this).find('input[type="radio"][value="P"]').prop('checked', true);
                updateRowStyling($(this), 'P');
            });
            updateCounters();
        });

        $('#btnAllAbsent').on('click', function() {
            $('tr[data-student-id]').each(function() {
                $(this).find('input[type="radio"][value="A"]').prop('checked', true);
                updateRowStyling($(this), 'A');
            });
            updateCounters();
        });

        $('#btnClearAll').on('click', function() {
            $('tr[data-student-id]').each(function() {
                $(this).find('input[type="radio"]').prop('checked', false);
                updateRowStyling($(this), '');
            });
            updateCounters();
        });

        $('tr[data-student-id]').each(function() {
            var val = $(this).find('input[type="radio"]:checked').val() || '';
            updateRowStyling($(this), val);
        });

        updateCounters();
    });
    </script>
</body>
</html>
<?php $conn->close(); ?>