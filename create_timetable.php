<?php
ob_start();
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

require_once('conn_inc.php');

/* ═══════════════════════════════════════════════════════════
   AJAX HANDLER — runs FIRST, before any HTML output
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_update'])) {

    /* Wipe any stray output from includes */
    while (ob_get_level() > 0) { ob_end_clean(); }

    /* Suppress raw output; capture fatals as JSON */
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);

    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'PHP FATAL: ' . $e['message'] . ' in ' . basename($e['file']) . ':' . $e['line'],
                'error_type' => 'php_fatal'
            ]);
        }
    });

    set_exception_handler(function ($ex) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'EXCEPTION: ' . $ex->getMessage(),
            'error_type' => 'exception'
        ]);
        exit;
    });

    header('Content-Type: application/json; charset=utf-8');

    try {
        $class_id         = (int)($_POST['class_id'] ?? 0);
        $class_section_id = (int)($_POST['class_section_id'] ?? 0);
        if ($class_section_id === 0) $class_section_id = null;

        $day_id       = (int)($_POST['day_id'] ?? 0);
        $time_slot_id = (int)($_POST['time_slot_id'] ?? 0);
        $subject_id   = !empty($_POST['subject_id']) ? (int)$_POST['subject_id'] : null;
        $teacher_id   = !empty($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : null;
        $room_no      = isset($_POST['room_no']) && trim($_POST['room_no']) !== '' ? trim($_POST['room_no']) : null;
        $updated_by   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;

        if ($class_id <= 0 || $day_id <= 0 || $time_slot_id <= 0) {
            echo json_encode(['success'=>false, 'message'=>'Invalid request (missing class/day/slot).']);
            exit;
        }

        /* Validate subject belongs to this class */
        if ($subject_id) {
            $v = $conn->prepare("SELECT 1 FROM subject_class WHERE class_id = ? AND subject_id = ? AND (status = 0 OR status IS NULL) LIMIT 1");
            $v->bind_param("ii", $class_id, $subject_id);
            $v->execute();
            $ok_subj = (bool)$v->get_result()->fetch_row();
            $v->close();
            if (!$ok_subj) {
                echo json_encode(['success'=>false, 'message'=>'Subject not assigned to this class.']);
                exit;
            }
        }

        /* Validate teacher is assigned to this class / class-section */
        if ($teacher_id) {
            if ($class_section_id !== null) {
                $v = $conn->prepare("
                    SELECT 1 FROM teacher_classes
                    WHERE teacher_id = ?
                      AND ((class_id = ? AND class_section_id = ?)
                           OR (class_id = ? AND class_section_id IS NULL))
                    LIMIT 1
                ");
                $v->bind_param("iiii", $teacher_id, $class_id, $class_section_id, $class_id);
            } else {
                $v = $conn->prepare("
                    SELECT 1 FROM teacher_classes
                    WHERE teacher_id = ? AND class_id = ? AND class_section_id IS NULL
                    LIMIT 1
                ");
                $v->bind_param("ii", $teacher_id, $class_id);
            }
            $v->execute();
            $ok_tch = (bool)$v->get_result()->fetch_row();
            $v->close();
            if (!$ok_tch) {
                echo json_encode(['success'=>false, 'message'=>'Teacher not assigned to this class.']);
                exit;
            }
        }

        /* Find existing row */
        if ($class_section_id !== null) {
            $chk = $conn->prepare("SELECT id FROM timetable WHERE class_id=? AND class_section_id=? AND day_id=? AND time_slot_id=?");
            $chk->bind_param("iiii", $class_id, $class_section_id, $day_id, $time_slot_id);
        } else {
            $chk = $conn->prepare("SELECT id FROM timetable WHERE class_id=? AND class_section_id IS NULL AND day_id=? AND time_slot_id=?");
            $chk->bind_param("iii", $class_id, $day_id, $time_slot_id);
        }
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();
        $chk->close();
        $existing_id = $existing ? (int)$existing['id'] : null;

        /* Teacher conflict check */
        if ($teacher_id) {
            if ($existing_id) {
                $cf = $conn->prepare("SELECT t.id, c.title AS class_title
                                     FROM timetable t LEFT JOIN classes c ON c.id = t.class_id
                                     WHERE t.teacher_id=? AND t.day_id=? AND t.time_slot_id=? AND t.id != ? LIMIT 1");
                $cf->bind_param("iiii", $teacher_id, $day_id, $time_slot_id, $existing_id);
            } else {
                $cf = $conn->prepare("SELECT t.id, c.title AS class_title
                                     FROM timetable t LEFT JOIN classes c ON c.id = t.class_id
                                     WHERE t.teacher_id=? AND t.day_id=? AND t.time_slot_id=? LIMIT 1");
                $cf->bind_param("iii", $teacher_id, $day_id, $time_slot_id);
            }
            $cf->execute();
            $conflict = $cf->get_result()->fetch_assoc();
            $cf->close();
            if ($conflict) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Teacher already booked at this day/time.',
                    'conflict_class' => $conflict['class_title'] ?? 'another class',
                    'error_type' => 'teacher_conflict'
                ]);
                exit;
            }
        }

        /* Insert or update */
        if ($existing_id) {
            $u = $conn->prepare("UPDATE timetable SET subject_id=?, teacher_id=?, room_no=?, updated_by=? WHERE id=?");
            $u->bind_param("iisii", $subject_id, $teacher_id, $room_no, $updated_by, $existing_id);
            $ok = $u->execute();
            if (!$ok) throw new Exception('UPDATE failed: ' . $u->error);
            $u->close();
        } else {
            $i = $conn->prepare("INSERT INTO timetable (class_id, class_section_id, day_id, time_slot_id, subject_id, teacher_id, room_no, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $i->bind_param("iiiiiisi", $class_id, $class_section_id, $day_id, $time_slot_id, $subject_id, $teacher_id, $room_no, $updated_by);
            $ok = $i->execute();
            if (!$ok) throw new Exception('INSERT failed: ' . $i->error);
            $i->close();
        }

        echo json_encode([
            'success' => true,
            'message' => 'Saved.',
            'room_no' => $room_no,
        ]);
        exit;

    } catch (Throwable $e) {
        echo json_encode([
            'success' => false,
            'message' => 'SERVER ERROR: ' . $e->getMessage(),
            'error_type' => 'caught_exception'
        ]);
        exit;
    }
}

/* ═══════════════════════════════════════════════════════════
   PAGE LOAD (non-AJAX) — build the UI
   ═══════════════════════════════════════════════════════════ */

$session_id = (int)($_GET['session_id'] ?? 0);
if ($session_id === 0) {
    $r = $conn->query("SELECT id FROM sessions WHERE status = 0 ORDER BY id DESC LIMIT 1")->fetch_assoc();
    if ($r) $session_id = (int)$r['id'];
}

$sessions = $conn->query("SELECT id, title FROM sessions ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);

$class_options = [];
$q = $conn->query("
    SELECT c.id AS class_id, c.title AS class_title,
           COALESCE(cs.id, 0) AS class_section_id,
           s.title AS section_title,
           CASE WHEN cs.id IS NULL THEN c.title
                ELSE CONCAT(c.title, ' - ', s.title) END AS display_label
    FROM classes c
    LEFT JOIN class_sections cs ON cs.class_id = c.id AND (cs.status = 0 OR cs.status IS NULL)
    LEFT JOIN sections s ON s.id = cs.section_id
    WHERE c.status = 0 OR c.status IS NULL
    ORDER BY c.title, s.title
");
if ($q) { $class_options = $q->fetch_all(MYSQLI_ASSOC); }

$sel_class_id         = (int)($_GET['class_id'] ?? 0);
$sel_class_section_id = (int)($_GET['class_section_id'] ?? 0);

$time_slots = [];
$q = $conn->query("SELECT id, title, start_time, end_time FROM time_slots ORDER BY start_time");
if ($q) { $time_slots = $q->fetch_all(MYSQLI_ASSOC); }

/* Subjects for this class only */
$subjects = [];
if ($sel_class_id > 0) {
    $stmt = $conn->prepare("
        SELECT s.id, s.title
        FROM subjects s
        INNER JOIN subject_class sc ON sc.subject_id = s.id
        WHERE sc.class_id = ?
          AND (sc.status = 0 OR sc.status IS NULL)
          AND (s.status = 0 OR s.status IS NULL)
        ORDER BY s.title
    ");
    $stmt->bind_param("i", $sel_class_id);
    $stmt->execute();
    $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

/* Teachers for this class / class-section */
$teachers = [];
if ($sel_class_id > 0) {
    if ($sel_class_section_id > 0) {
        $stmt = $conn->prepare("
            SELECT DISTINCT u.id, u.username
            FROM users u
            INNER JOIN teacher_classes tc ON tc.teacher_id = u.id
            WHERE (u.role_id = 3)
              AND (u.status = 0 OR u.status IS NULL)
              AND (
                    (tc.class_id = ? AND tc.class_section_id = ?)
                 OR (tc.class_id = ? AND tc.class_section_id IS NULL)
              )
            ORDER BY u.username
        ");
        $stmt->bind_param("iii", $sel_class_id, $sel_class_section_id, $sel_class_id);
    } else {
        $stmt = $conn->prepare("
            SELECT DISTINCT u.id, u.username
            FROM users u
            INNER JOIN teacher_classes tc ON tc.teacher_id = u.id
            WHERE (u.role_id = 3)
              AND (u.status = 0 OR u.status IS NULL)
              AND tc.class_id = ?
              AND tc.class_section_id IS NULL
            ORDER BY u.username
        ");
        $stmt->bind_param("i", $sel_class_id);
    }
    $stmt->execute();
    $teachers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

$timetable_data = [];
if ($sel_class_id > 0) {
    if ($sel_class_section_id > 0) {
        $stmt = $conn->prepare("SELECT * FROM timetable WHERE class_id = ? AND class_section_id = ?");
        $stmt->bind_param("ii", $sel_class_id, $sel_class_section_id);
    } else {
        $stmt = $conn->prepare("SELECT * FROM timetable WHERE class_id = ? AND class_section_id IS NULL");
        $stmt->bind_param("i", $sel_class_id);
    }
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $timetable_data[(int)$row['day_id']][(int)$row['time_slot_id']] = $row;
    }
    $stmt->close();
}

$sel_label = '';
foreach ($class_options as $co) {
    if ((int)$co['class_id'] === $sel_class_id && (int)$co['class_section_id'] === $sel_class_section_id) {
        $sel_label = $co['display_label'];
        break;
    }
}
$session_title = '';
foreach ($sessions as $s) if ((int)$s['id'] === $session_id) $session_title = $s['title'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Timetable — Dar-e-Arqm</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #eef1f5; font-family: 'Inter', sans-serif; color: #1e293b; }
    .wrap { max-width: 1600px; margin: 30px auto; padding: 0 20px; }
    .head { background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); color: #fff; padding: 26px 32px; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: flex-end; gap: 20px; flex-wrap: wrap; }
    .head h1 { font-size: 26px; font-weight: 700; }
    .head .sub { font-size: 14px; opacity: 0.9; margin-top: 6px; }
    .head .hb { display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; border-radius: 8px; font-size: 13px; font-weight: 700; text-decoration: none; cursor: pointer; border: 1px solid rgba(255,255,255,0.3); background: rgba(255,255,255,0.15); color: #fff; font-family: inherit; }
    .head .hb:hover { background: #fff; color: #1e40af; border-color: #fff; text-decoration: none; }
    .card { background: #fff; padding: 24px 28px 30px 28px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
    .selector { display: flex; gap: 14px; align-items: flex-end; flex-wrap: wrap; padding-bottom: 20px; margin-bottom: 22px; border-bottom: 1px solid #e2e8f0; }
    .selector .field { min-width: 280px; }
    .selector label { display: block; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
    .selector select { width: 100%; height: 44px; padding: 0 14px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 14px; font-family: inherit; background: #fff; }
    .selector select:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 4px rgba(30,64,175,0.1); }
    .empty { padding: 60px 20px; text-align: center; color: #94a3b8; }
    .empty .big { font-size: 44px; opacity: 0.4; margin-bottom: 10px; }
    .empty h3 { font-size: 17px; color: #475569; font-weight: 600; margin-bottom: 6px; }
    .empty a { color: #1e40af; text-decoration: none; font-weight: 600; }
    .tbl-wrap { overflow-x: auto; border-radius: 10px; border: 1px solid #e2e8f0; }
    table.tt { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 1200px; }
    table.tt thead th { background: #1e3a8a; color: #fff; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 12px 8px; text-align: center; border-right: 1px solid rgba(255,255,255,0.15); position: sticky; top: 0; z-index: 3; white-space: nowrap; }
    table.tt tbody td { padding: 8px 6px; font-size: 12px; border-right: 1px solid #f1f5f9; border-bottom: 1px solid #f1f5f9; vertical-align: top; background: #fff; }
    table.tt tbody td.period-cell, table.tt thead th.period-cell { position: sticky; left: 0; z-index: 2; background: #f8fafc; text-align: left; border-right: 1px solid #e2e8f0; min-width: 120px; }
    table.tt thead th.period-cell { z-index: 4; background: #1e3a8a; }
    .period-title { font-weight: 700; color: #0f172a; font-size: 12px; }
    .period-time  { font-size: 10px; color: #94a3b8; margin-top: 2px; font-family: 'SF Mono', monospace; }
    .cell { display: flex; flex-direction: column; gap: 4px; min-width: 150px; }
    .cell select, .cell input { width: 100%; padding: 5px 7px; font-size: 11px; border: 1px solid #e2e8f0; border-radius: 5px; background: #fff; color: #1e293b; font-family: inherit; }
    .cell select:focus, .cell input:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 2px rgba(30,64,175,0.12); }
    .cell.filled select, .cell.filled input { border-color: #bfdbfe; background: #f0f5ff; }
    .cell .btn-save { font-size: 10px; font-weight: 700; padding: 4px 8px; border-radius: 5px; background: #1e40af; color: #fff; border: none; cursor: pointer; font-family: inherit; display: none; align-items: center; justify-content: center; gap: 4px; }
    .cell.dirty .btn-save { display: inline-flex; }
    .cell.saving .btn-save { background: #64748b; cursor: wait; }
    .cell.saved .btn-save { background: #10b981; display: inline-flex; }
    .cell-msg { font-size: 10px; color: #dc2626; font-weight: 600; display: none; word-break: break-word; }
    .cell.err .cell-msg { display: block; }
    .note { background: #dbeafe; border-left: 4px solid #1e40af; color: #1e3a8a; padding: 10px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; }
</style>
</head>
<body>
<?php require_once('navbar.php'); ?>
<div class="wrap">
    <div class="head no-print">
    <div>
        <h1><i class="fas fa-table"></i> Timetable Editor</h1>
        <div class="sub"><?= $sel_label ? htmlspecialchars($sel_label) : 'Pick a class' ?> &middot; <?= htmlspecialchars($session_title) ?></div>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <a href="print_timetable.php?mode=multi&amp;session_id=<?= (int)$session_id; ?>" class="hb">
            <i class="fas fa-print"></i> Print / Export
        </a>
        <a href="print_timetable.php?mode=teacher&amp;session_id=<?= (int)$session_id; ?>" class="hb">
            <i class="fas fa-user-tie"></i> By Teacher
        </a>
        <a href="time_slots.php" class="hb"><i class="fas fa-clock"></i> Time Slots</a>
    </div>
</div>
    <div class="card">

        <form method="get" class="selector">
            <div class="field">
                <label>Session</label>
                <select name="session_id" onchange="this.form.submit()">
                    <?php foreach ($sessions as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= $s['id'] == $session_id ? 'selected' : '' ?>><?= htmlspecialchars($s['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Class / Class-Section</label>
                <select name="pick" onchange="onPick(this)">
                    <option value="">— Select —</option>
                    <?php foreach ($class_options as $co):
                        $val = $co['class_id'] . '|' . $co['class_section_id'];
                        $sel = ((int)$co['class_id'] === $sel_class_id && (int)$co['class_section_id'] === $sel_class_section_id);
                    ?>
                        <option value="<?= htmlspecialchars($val) ?>" <?= $sel ? 'selected' : '' ?>><?= htmlspecialchars($co['display_label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
        <form method="get" id="pickForm" style="display:none;">
            <input type="hidden" name="session_id" value="<?= $session_id ?>">
            <input type="hidden" name="class_id" id="p_class_id">
            <input type="hidden" name="class_section_id" id="p_class_section_id">
        </form>

        <script>
        function onPick(sel) {
            var v = sel.value || '';
            if (!v) { location.href = 'create_timetable.php?session_id=' + <?= (int)$session_id ?>; return; }
            var p = v.split('|');
            document.getElementById('p_class_id').value = p[0] || 0;
            document.getElementById('p_class_section_id').value = p[1] || 0;
            document.getElementById('pickForm').submit();
        }
        </script>

        <?php if (empty($time_slots)): ?>
            <div class="empty"><div class="big">⏰</div><h3>No time slots defined</h3><p><a href="time_slots.php">Add them here</a>.</p></div>
        <?php elseif ($sel_class_id === 0): ?>
            <div class="empty"><div class="big">🎯</div><h3>Pick a class to start editing</h3></div>
        <?php elseif (empty($subjects)): ?>
            <div class="empty">
                <div class="big">📚</div>
                <h3>No subjects assigned to this class</h3>
                <p><a href="subject_class.php">Assign subjects to this class first</a>.</p>
            </div>
        <?php else: ?>

        <div class="note">
            <i class="fas fa-info-circle"></i>
            Showing <strong><?= count($subjects) ?></strong> subject(s) and <strong><?= count($teachers) ?></strong> teacher(s) assigned to this class.
        </div>

        <div class="tbl-wrap">
            <table class="tt">
                <thead><tr><th class="period-cell">Period</th><?php foreach ($days as $day): ?><th><?= htmlspecialchars($day) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach ($time_slots as $slot): $slot_id = (int)$slot['id']; ?>
                    <tr>
                        <td class="period-cell">
                            <div class="period-title"><?= htmlspecialchars($slot['title'] ?: ('Period '.$slot_id)) ?></div>
                            <div class="period-time"><?= date('H:i', strtotime($slot['start_time'])) ?>–<?= date('H:i', strtotime($slot['end_time'])) ?></div>
                        </td>
                        <?php foreach ($days as $di => $day):
                            $day_id = $di + 1;
                            $existing = $timetable_data[$day_id][$slot_id] ?? null;
                            $cur_subj = $existing ? (int)$existing['subject_id'] : 0;
                            $cur_tch  = $existing ? (int)$existing['teacher_id'] : 0;
                            $cur_room = $existing ? (string)$existing['room_no'] : '';
                            $filled = ($cur_subj || $cur_tch || $cur_room);
                        ?>
                        <td>
                            <div class="cell <?= $filled ? 'filled' : '' ?>" data-day="<?= $day_id ?>" data-slot="<?= $slot_id ?>">
                                <select class="subj"><option value="">— Subject —</option>
                                    <?php foreach ($subjects as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $cur_subj === (int)$s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['title']) ?></option><?php endforeach; ?>
                                </select>
                                <select class="tch"><option value="">— Teacher —</option>
                                    <?php foreach ($teachers as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $cur_tch === (int)$t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['username']) ?></option><?php endforeach; ?>
                                </select>
                                <input type="text" class="room" placeholder="Room" maxlength="20" value="<?= htmlspecialchars($cur_room) ?>">
                                <button type="button" class="btn-save"><i class="fas fa-save"></i> Save</button>
                                <div class="cell-msg"></div>
                            </div>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <script>
        var CLASS_ID = <?= (int)$sel_class_id ?>;
        var CS_ID    = <?= (int)$sel_class_section_id ?>;
        document.querySelectorAll('.cell').forEach(function(cell){
            var subj = cell.querySelector('.subj'), tch = cell.querySelector('.tch'), room = cell.querySelector('.room'), btn = cell.querySelector('.btn-save');
            function markDirty(){ cell.classList.add('dirty'); cell.classList.remove('saved'); }
            [subj,tch,room].forEach(function(el){ el.addEventListener('input', markDirty); el.addEventListener('change', markDirty); });
            btn.addEventListener('click', function(){
                cell.classList.remove('dirty'); cell.classList.add('saving');
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving';
                jQuery.ajax({
                    url: 'create_timetable.php', type: 'POST', dataType: 'json',
                    data: { ajax_update:1, class_id:CLASS_ID, class_section_id:CS_ID,
                            day_id:cell.dataset.day, time_slot_id:cell.dataset.slot,
                            subject_id:subj.value, teacher_id:tch.value, room_no:room.value },
                    success: function(r){
                        cell.classList.remove('saving');
                        btn.innerHTML = '<i class="fas fa-save"></i> Save';
                        if (r.success){
                            cell.classList.add('saved');
                            cell.classList.toggle('filled', !!(subj.value || tch.value || room.value));
                            cell.querySelector('.cell-msg').textContent = '';
                            cell.classList.remove('err');
                            setTimeout(function(){ cell.classList.remove('saved'); }, 1200);
                        } else {
                            cell.classList.add('err','dirty');
                            var m = r.message || 'Save failed.';
                            if (r.conflict_class) m += ' (' + r.conflict_class + ')';
                            cell.querySelector('.cell-msg').textContent = m;
                        }
                    },
                    error: function(xhr, status, err){
                        cell.classList.remove('saving');
                        cell.classList.add('dirty','err');
                        var raw = (xhr && xhr.responseText) ? xhr.responseText.substring(0, 500) : '';
                        cell.querySelector('.cell-msg').textContent = 'HTTP ' + (xhr.status || '?') + ' — ' + (err || status) + (raw ? ' — ' + raw : '');
                        btn.innerHTML = '<i class="fas fa-save"></i> Save';
                    }
                });
            });
        });
        </script>

        <?php endif; ?>
    </div>
</div>
</body>
</html>
<?php $conn->close(); ob_end_flush(); ?>