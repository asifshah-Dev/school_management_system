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

$mode = isset($_GET['mode']) ? $_GET['mode'] : 'single';
if (!in_array($mode, ['single', 'multi', 'teacher'], true)) $mode = 'single';

/* ─────────── Session ─────────── */
$session_id = (int)($_GET['session_id'] ?? 0);
if ($session_id === 0) {
    $r = $conn->query("SELECT id FROM sessions WHERE status = 0 ORDER BY id DESC LIMIT 1");
    if ($r && ($row = $r->fetch_assoc())) $session_id = (int)$row['id'];
}

$sessions = [];
$s_res = $conn->query("SELECT id, title FROM sessions ORDER BY id DESC");
if ($s_res) while ($r = $s_res->fetch_assoc()) $sessions[$r['id']] = $r['title'];

$session_title = $sessions[$session_id] ?? '';

/* ─────────── Class options (hybrid) ─────────── */
$class_options = [];
$q = $conn->query("SELECT c.id AS class_id, c.title AS class_title, COALESCE(cs.id, 0) AS class_section_id, s.title AS section_title, CASE WHEN cs.id IS NULL THEN c.title ELSE CONCAT(c.title, ' - ', s.title) END AS display_label FROM classes c LEFT JOIN class_sections cs ON cs.class_id = c.id AND (cs.status = 0 OR cs.status IS NULL) LEFT JOIN sections s ON s.id = cs.section_id WHERE c.status = 0 OR c.status IS NULL ORDER BY c.title, s.title");
if ($q) while ($r = $q->fetch_assoc()) $class_options[] = $r;

/* ─────────── Time slots & days ─────────── */
$time_slots = [];
$q = $conn->query("SELECT id, title, start_time, end_time FROM time_slots ORDER BY start_time");
if ($q) while ($r = $q->fetch_assoc()) $time_slots[] = $r;

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

/* ─────────── Teachers ─────────── */
$teachers_list = [];
$q = $conn->query("SELECT id, username FROM users WHERE role_id = 3 AND (status = 0 OR status IS NULL) ORDER BY username COLLATE utf8mb4_general_ci");
if ($q) while ($r = $q->fetch_assoc()) $teachers_list[] = $r;

/* ─────────── Selected classes / teachers ─────────── */
function parse_class_picks($raw) {
    /* each entry "class_id|class_section_id" */
    $out = [];
    if (!is_array($raw)) return $out;
    foreach ($raw as $v) {
        $parts = explode('|', (string)$v);
        $cid = isset($parts[0]) ? (int)$parts[0] : 0;
        $csid = isset($parts[1]) ? (int)$parts[1] : 0;
        if ($cid > 0) $out[] = ['class_id' => $cid, 'class_section_id' => $csid];
    }
    return $out;
}

$selected_classes = [];
if ($mode === 'single') {
    $cid  = (int)($_GET['class_id'] ?? 0);
    $csid = (int)($_GET['class_section_id'] ?? 0);
    if ($cid > 0) $selected_classes[] = ['class_id' => $cid, 'class_section_id' => $csid];
} elseif ($mode === 'multi') {
    if (!empty($_GET['picks'])) {
        $selected_classes = parse_class_picks($_GET['picks']);
    } elseif (isset($_GET['class_id']) && (int)$_GET['class_id'] > 0) {
        /* fallback from Edit button */
        $selected_classes[] = [
            'class_id'         => (int)$_GET['class_id'],
            'class_section_id' => (int)($_GET['class_section_id'] ?? 0)
        ];
    }
}

$selected_teachers = [];
if ($mode === 'teacher') {
    if (!empty($_GET['teacher_ids']) && is_array($_GET['teacher_ids'])) {
        foreach ($_GET['teacher_ids'] as $tid) {
            $tid = (int)$tid;
            if ($tid > 0) $selected_teachers[] = $tid;
        }
    }
}

/* ─────────── Label helpers ─────────── */
function class_label_lookup($class_options) {
    $m = [];
    foreach ($class_options as $co) {
        $m[$co['class_id'] . '|' . $co['class_section_id']] = $co['display_label'];
    }
    return $m;
}
$class_labels = class_label_lookup($class_options);

function teacher_name_lookup($teachers_list) {
    $m = [];
    foreach ($teachers_list as $t) $m[(int)$t['id']] = $t['username'];
    return $m;
}
$teacher_names = teacher_name_lookup($teachers_list);

/* ─────────── Load timetable data ─────────── */
function load_timetable_for_class($conn, $class_id, $class_section_id) {
    $rows = [];
    if ($class_section_id > 0) {
        $stmt = $conn->prepare("SELECT t.day_id, t.time_slot_id, t.room_no, t.subject_id, t.teacher_id, s.title AS subject_title, u.username AS teacher_name FROM timetable t LEFT JOIN subjects s ON s.id = t.subject_id LEFT JOIN users u ON u.id = t.teacher_id WHERE t.class_id = ? AND t.class_section_id = ?");
        $stmt->bind_param("ii", $class_id, $class_section_id);
    } else {
        $stmt = $conn->prepare("SELECT t.day_id, t.time_slot_id, t.room_no, t.subject_id, t.teacher_id, s.title AS subject_title, u.username AS teacher_name FROM timetable t LEFT JOIN subjects s ON s.id = t.subject_id LEFT JOIN users u ON u.id = t.teacher_id WHERE t.class_id = ? AND t.class_section_id IS NULL");
        $stmt->bind_param("i", $class_id);
    }
    if (!$stmt) return $rows;
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[(int)$row['day_id']][(int)$row['time_slot_id']] = $row;
        }
    }
    $stmt->close();
    return $rows;
}

/* Per-class data keyed by "cid|csid" */
$class_timetables = [];
foreach ($selected_classes as $sc) {
    $key = $sc['class_id'] . '|' . $sc['class_section_id'];
    $class_timetables[$key] = [
        'class_id'         => $sc['class_id'],
        'class_section_id' => $sc['class_section_id'],
        'label'            => $class_labels[$key] ?? ('Class ' . $sc['class_id']),
        'data'             => load_timetable_for_class($conn, $sc['class_id'], $sc['class_section_id']),
    ];
}

/* Teacher data: fetch all timetable rows where teacher_id = ? */
function load_timetable_for_teacher($conn, $teacher_id) {
    $rows = [];
    $stmt = $conn->prepare("SELECT t.day_id, t.time_slot_id, t.room_no, t.class_id, t.class_section_id, s.title AS subject_title, c.title AS class_title, sec.title AS section_title FROM timetable t LEFT JOIN subjects s ON s.id = t.subject_id LEFT JOIN classes c ON c.id = t.class_id LEFT JOIN sections sec ON sec.id = (SELECT section_id FROM class_sections WHERE id = t.class_section_id) WHERE t.teacher_id = ? ORDER BY t.day_id, t.time_slot_id");
    if (!$stmt) return $rows;
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[(int)$row['day_id']][(int)$row['time_slot_id']] = $row;
        }
    }
    $stmt->close();
    return $rows;
}

$teacher_timetables = [];
foreach ($selected_teachers as $tid) {
    $teacher_timetables[$tid] = [
        'teacher_id' => $tid,
        'label'      => $teacher_names[$tid] ?? ('Teacher ' . $tid),
        'data'       => load_timetable_for_teacher($conn, $tid),
    ];
}

/* ─────────── Export grid for single-class mode (unchanged behavior) ─────────── */
$grid_rows = [];
$export_title = 'School Management System - Class Timetable';
$export_meta = '';
$export_filename = 'timetable_' . date('Y-m-d');

if ($mode === 'single' && !empty($class_timetables)) {
    $key = array_key_first($class_timetables);
    $single = $class_timetables[$key];
    $timetable_data = $single['data'];
    $sel_label = $single['label'];

    foreach ($time_slots as $slot) {
        $slot_id = (int)$slot['id'];
        $period_label = ($slot['title'] ?: ('Period ' . $slot_id)) . ' (' . date('H:i', strtotime($slot['start_time'])) . '-' . date('H:i', strtotime($slot['end_time'])) . ')';
        $row = ['period' => $period_label, 'cells' => []];
        foreach ($days as $di => $day) {
            $day_id = $di + 1;
            $cell = $timetable_data[$day_id][$slot_id] ?? null;
            $val = '';
            if ($cell) {
                $parts = [];
                if (!empty($cell['subject_title'])) $parts[] = $cell['subject_title'];
                if (!empty($cell['teacher_name'])) $parts[] = $cell['teacher_name'];
                if (!empty($cell['room_no'])) $parts[] = 'Room ' . $cell['room_no'];
                $val = implode(' / ', $parts);
            }
            $row['cells'][] = $val;
        }
        $grid_rows[] = $row;
    }

    $export_meta = 'Class: ' . $sel_label . '   |   Session: ' . ($session_title ?: '-') . '   |   Printed: ' . date('d M Y');
    $export_filename = 'timetable_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $sel_label ?: 'class') . '_' . date('Y-m-d');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Print Timetable</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #eef1f5; font-family: 'Inter', sans-serif; color: #1e293b; padding-bottom: 40px; }
    .wrap { max-width: 1400px; margin: 30px auto; padding: 0 20px; }

    .head {
        background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
        color: #fff; padding: 26px 32px;
        border-radius: 12px 12px 0 0;
        display: flex; justify-content: space-between; align-items: flex-end;
        gap: 20px; flex-wrap: wrap;
    }
    .head h1 { font-size: 24px; font-weight: 700; }
    .head .sub { font-size: 14px; opacity: 0.9; margin-top: 6px; }
    .head .actions { display: flex; gap: 10px; flex-wrap: wrap; }
    .btn-white { background: #fff; color: #1e40af; border: 1px solid #fff; font-weight: 700; font-size: 13px; padding: 10px 20px; border-radius: 8px; display: inline-flex; align-items: center; gap: 8px; cursor: pointer; text-decoration: none; font-family: inherit; }
    .btn-white:hover, .btn-white:focus { background: #f1f5f9; color: #1e3a8a; text-decoration: none; }
    .btn-white-ghost { background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.3); font-weight: 700; font-size: 13px; padding: 10px 20px; border-radius: 8px; display: inline-flex; align-items: center; gap: 8px; cursor: pointer; text-decoration: none; font-family: inherit; }
    .btn-white-ghost:hover, .btn-white-ghost:focus { background: rgba(255,255,255,0.25); color: #fff; text-decoration: none; }

    .card { background: #fff; padding: 26px 32px 34px 32px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

    .selector { display: flex; gap: 14px; align-items: flex-end; flex-wrap: wrap; padding-bottom: 20px; margin-bottom: 22px; border-bottom: 1px solid #e2e8f0; }
    .selector .field { min-width: 240px; }
    .selector label { display: block; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
    .selector select { width: 100%; height: 42px; padding: 0 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; font-family: inherit; background: #fff; }
    .selector select:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 4px rgba(30,64,175,0.1); }

    /* Multi-select checkbox grid */
    .pick-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px; margin-bottom: 22px; }
    .pick-card {
        display: flex; align-items: center; gap: 10px;
        padding: 10px 14px; background: #fff;
        border: 1.5px solid #e2e8f0; border-radius: 10px;
        cursor: pointer; transition: all 0.15s; user-select: none;
        font-size: 13px; font-weight: 600; color: #1e293b;
    }
    .pick-card:hover { border-color: #93c5fd; background: #f8fafc; }
    .pick-card input[type="checkbox"] { width: 16px; height: 16px; accent-color: #1e40af; cursor: pointer; margin: 0; }
    .pick-card:has(input:checked) { border-color: #1e40af; background: #eff6ff; }
    .pick-card:has(input:checked) { color: #1e3a8a; }

    .pick-toolbar { display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; }
    .pick-toolbar button {
        background: #f1f5f9; border: 1px solid #e2e8f0; color: #475569;
        padding: 7px 14px; border-radius: 8px; font-size: 12px; font-weight: 700;
        cursor: pointer; font-family: inherit;
    }
    .pick-toolbar button:hover { background: #e2e8f0; color: #1e293b; }

    .empty { padding: 60px 20px; text-align: center; color: #94a3b8; }
    .empty .big { font-size: 44px; opacity: 0.4; margin-bottom: 10px; }
    .empty h3 { font-size: 17px; color: #475569; font-weight: 600; margin-bottom: 6px; }

    /* Each class/teacher block */
    .print-block {
        margin-bottom: 30px;
        padding-bottom: 22px;
        border-bottom: 2px dashed #cbd5e1;
        position: relative;
    }
    .print-block:last-child { border-bottom: none; margin-bottom: 0; }
    .print-block .block-tools {
        display: flex; justify-content: flex-end; gap: 8px; margin-bottom: 10px;
    }
    .print-block .block-tools button {
        background: #1e40af; border: 1px solid #1e40af; color: #fff;
        padding: 6px 12px; border-radius: 7px; font-size: 11.5px; font-weight: 700;
        cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; gap: 5px;
    }
    .print-block .block-tools button:hover { background: #1e3a8a; }

    .print-head { text-align: center; padding-bottom: 14px; border-bottom: 2px solid #000; margin-bottom: 14px; }
    .print-head .school { font-size: 20px; font-weight: 800; letter-spacing: 0.5px; color: #000; text-transform: uppercase; }
    .print-head .doctype { font-size: 13px; font-weight: 600; color: #000; margin-top: 4px; }
    .print-head .meta { font-size: 11px; color: #333; margin-top: 8px; display: flex; justify-content: center; gap: 26px; flex-wrap: wrap; }
    .print-head .meta strong { color: #000; }

    .tbl-wrap { overflow-x: auto; }
    table.tt { width: 100%; border-collapse: collapse; background: #fff; }
    table.tt th, table.tt td { border: 1px solid #333; padding: 8px 10px; font-size: 12px; color: #000; vertical-align: top; text-align: left; }
    table.tt thead th { background: #e5e7eb; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; text-align: center; padding: 10px 8px; }
    table.tt thead th.period-head { width: 130px; text-align: left; background: #d1d5db; }
    table.tt tbody td.period-cell { background: #f3f4f6; font-weight: 700; width: 130px; }
    table.tt tbody td.period-cell .pt { font-size: 12px; color: #000; }
    table.tt tbody td.period-cell .ptime { font-size: 10px; color: #4b5563; margin-top: 2px; font-family: 'SF Mono', monospace; }
    table.tt tbody td.period-cell.is-break { background: #e5e7eb; font-style: italic; }

    .cell-subj { font-size: 12px; font-weight: 700; color: #000; line-height: 1.3; }
    .cell-tch  { font-size: 11px; color: #333; margin-top: 3px; }
    .cell-room { font-size: 10px; color: #666; margin-top: 2px; }
    .cell-empty { font-size: 12px; color: #cbd5e1; text-align: center; }

    /* ---------- PRINT ---------- */
    @media print {
        @page { size: A4 landscape; margin: 10mm; }
        body { background: #fff !important; padding: 0 !important; margin: 0 !important; }
        body * { visibility: hidden !important; }
        .print-area, .print-area * { visibility: visible !important; }
        .print-area { position: absolute !important; left: 0 !important; top: 0 !important; width: 100% !important; padding: 0 !important; margin: 0 !important; background: #fff !important; }
        .no-print, .head, .selector, .pick-grid, .pick-toolbar, nav, .navbar, header, footer,
        #navbar, #header, .navbar-fixed-top, .navbar-default { display: none !important; }
        .card { box-shadow: none !important; padding: 0 !important; border-radius: 0 !important; }
        .print-block { margin: 0 0 8mm 0 !important; padding: 0 !important; border-bottom: none !important; page-break-after: always !important; break-after: page !important; }
        .print-block:last-child { page-break-after: auto !important; break-after: auto !important; }
        .print-block .block-tools { display: none !important; }
        .print-head { border-bottom: 2px solid #000 !important; margin-bottom: 10px !important; }
        .print-head .school { font-size: 16px !important; }
        .print-head .doctype { font-size: 12px !important; }
        .print-head .meta { font-size: 10px !important; gap: 20px !important; }
        table.tt { font-size: 11px !important; }
        table.tt th, table.tt td { border: 1px solid #000 !important; padding: 5px 6px !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        table.tt thead th { background: #e5e7eb !important; font-size: 10px !important; }
        table.tt thead th.period-head { background: #d1d5db !important; }
        table.tt tbody td.period-cell { background: #f3f4f6 !important; }
        table.tt tbody td.period-cell.is-break { background: #e5e7eb !important; }
        .cell-subj { font-size: 11px !important; }
        .cell-tch  { font-size: 10px !important; }
        .cell-room { font-size: 9px !important; }

        /* Hide everything except the block being individually printed */
        body.print-one .print-block { display: none !important; visibility: hidden !important; }
        body.print-one .print-block.print-target { display: block !important; visibility: visible !important; page-break-after: auto !important; break-after: auto !important; }
        body.print-one .print-block.print-target,
        body.print-one .print-block.print-target * { visibility: visible !important; }
    }
</style>
</head>
<body>
<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head no-print">
        <div>
            <h1>
                <i class="fas fa-print"></i>
                <?php if ($mode === 'multi'): ?>Print Multiple Classes
                <?php elseif ($mode === 'teacher'): ?>Print Teacher Timetables
                <?php else: ?>Print Timetable<?php endif; ?>
            </h1>
            <div class="sub">
                <?php if ($session_title): ?>Session: <?= htmlspecialchars($session_title); ?><?php endif; ?>
            </div>
        </div>
        <div class="actions">
            <a href="create_timetable.php?session_id=<?= (int)$session_id; ?><?php if ($mode === 'single' && !empty($selected_classes)): ?>&class_id=<?= (int)$selected_classes[0]['class_id']; ?>&class_section_id=<?= (int)$selected_classes[0]['class_section_id']; ?><?php endif; ?>" class="btn-white-ghost">
                <i class="fas fa-pen"></i> Edit
            </a>
            <a href="print_timetable.php?mode=multi&session_id=<?= (int)$session_id; ?>" class="btn-white-ghost">
                <i class="fas fa-layer-group"></i> Multiple Classes
            </a>
            <a href="print_timetable.php?mode=teacher&session_id=<?= (int)$session_id; ?>" class="btn-white-ghost">
                <i class="fas fa-user-tie"></i> By Teacher
            </a>
            <?php if (($mode === 'multi' && !empty($class_timetables)) || ($mode === 'teacher' && !empty($teacher_timetables)) || ($mode === 'single' && !empty($class_timetables))): ?>
                <button type="button" class="btn-white" onclick="window.print();">
                    <i class="fas fa-print"></i> Print All
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">

        <!-- Session selector -->
        <form method="get" class="selector no-print" id="filterForm">
            <input type="hidden" name="mode" value="<?= htmlspecialchars($mode); ?>">
            <div class="field">
                <label>Session</label>
                <select name="session_id" onchange="this.form.submit()">
                    <?php foreach ($sessions as $sid => $sname): ?>
                        <option value="<?= (int)$sid; ?>" <?= ((int)$sid === $session_id) ? 'selected' : ''; ?>><?= htmlspecialchars($sname); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <?php if (empty($time_slots)): ?>
            <div class="empty no-print">
                <div class="big"><i class="fas fa-clock"></i></div>
                <h3>No time slots defined</h3>
                <p>Add periods in Time Slots first.</p>
            </div>

        <?php elseif ($mode === 'single'): ?>
            <!-- SINGLE MODE — unchanged selector -->
            <form method="get" class="selector no-print">
                <input type="hidden" name="mode" value="single">
                <div class="field">
                    <label>Session</label>
                    <select name="session_id" onchange="this.form.submit()">
                        <?php foreach ($sessions as $sid => $sname): ?>
                            <option value="<?= (int)$sid; ?>" <?= ((int)$sid === $session_id) ? 'selected' : ''; ?>><?= htmlspecialchars($sname); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Class / Class-Section</label>
                    <select name="pick" onchange="onPick(this)">
                        <option value="">— Select —</option>
                        <?php foreach ($class_options as $co):
                            $val = $co['class_id'] . '|' . $co['class_section_id'];
                            $sel = !empty($selected_classes)
                                && (int)$co['class_id'] === (int)$selected_classes[0]['class_id']
                                && (int)$co['class_section_id'] === (int)$selected_classes[0]['class_section_id'];
                        ?>
                            <option value="<?= htmlspecialchars($val); ?>" <?= $sel ? 'selected' : ''; ?>><?= htmlspecialchars($co['display_label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
            <form method="get" id="pickForm" style="display:none;">
                <input type="hidden" name="mode" value="single">
                <input type="hidden" name="session_id" value="<?= (int)$session_id; ?>">
                <input type="hidden" name="class_id" id="p_class_id">
                <input type="hidden" name="class_section_id" id="p_class_section_id">
            </form>
            <script>
            function onPick(sel) {
                var v = sel.value || '';
                if (!v) { location.href = 'print_timetable.php?mode=single&session_id=' + <?= (int)$session_id; ?>; return; }
                var p = v.split('|');
                document.getElementById('p_class_id').value = p[0] || 0;
                document.getElementById('p_class_section_id').value = p[1] || 0;
                document.getElementById('pickForm').submit();
            }
            </script>

            <?php if (empty($class_timetables)): ?>
                <div class="empty no-print">
                    <div class="big"><i class="fas fa-graduation-cap"></i></div>
                    <h3>Pick a class to preview</h3>
                </div>
            <?php endif; ?>

        <?php elseif ($mode === 'multi'): ?>
            <!-- MULTI MODE — pick classes -->
            <div class="no-print" style="margin-bottom: 20px;">
                <h3 style="font-size:15px; font-weight:700; color:#1e293b; margin-bottom:10px;">
                    <i class="fas fa-check-square" style="color:#1e40af;"></i> Select Classes to Print
                </h3>
                <div class="pick-toolbar">
                    <button type="button" onclick="$('.pick-grid input[type=checkbox]').prop('checked', true);">Select All</button>
                    <button type="button" onclick="$('.pick-grid input[type=checkbox]').prop('checked', false);">Clear</button>
                </div>
                <form method="get" id="multiForm">
                    <input type="hidden" name="mode" value="multi">
                    <input type="hidden" name="session_id" value="<?= (int)$session_id; ?>">
                    <div class="pick-grid">
                        <?php
                        $picked_keys = [];
                        foreach ($selected_classes as $sc) $picked_keys[] = $sc['class_id'] . '|' . $sc['class_section_id'];
                        foreach ($class_options as $co):
                            $val = $co['class_id'] . '|' . $co['class_section_id'];
                            $checked = in_array($val, $picked_keys, true);
                        ?>
                            <label class="pick-card">
                                <input type="checkbox" name="picks[]" value="<?= htmlspecialchars($val); ?>" <?= $checked ? 'checked' : ''; ?>>
                                <span><?= htmlspecialchars($co['display_label']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" style="background:#1e40af; color:#fff; border:none; padding:10px 22px; border-radius:8px; font-weight:700; font-size:13px; cursor:pointer;">
                        <i class="fas fa-print"></i> Load Selected
                    </button>
                </form>
            </div>
        <?php elseif ($mode === 'teacher'): ?>
            <!-- TEACHER MODE — pick teachers -->
            <div class="no-print" style="margin-bottom: 20px;">
                <h3 style="font-size:15px; font-weight:700; color:#1e293b; margin-bottom:10px;">
                    <i class="fas fa-user-tie" style="color:#1e40af;"></i> Select Teachers to Print
                </h3>
                <div class="pick-toolbar">
                    <button type="button" onclick="$('.pick-grid input[type=checkbox]').prop('checked', true);">Select All</button>
                    <button type="button" onclick="$('.pick-grid input[type=checkbox]').prop('checked', false);">Clear</button>
                </div>
                <form method="get" id="teacherForm">
                    <input type="hidden" name="mode" value="teacher">
                    <input type="hidden" name="session_id" value="<?= (int)$session_id; ?>">
                    <div class="pick-grid">
                        <?php foreach ($teachers_list as $t):
                            $checked = in_array((int)$t['id'], $selected_teachers, true);
                        ?>
                            <label class="pick-card">
                                <input type="checkbox" name="teacher_ids[]" value="<?= (int)$t['id']; ?>" <?= $checked ? 'checked' : ''; ?>>
                                <span><?= htmlspecialchars($t['username']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" style="background:#1e40af; color:#fff; border:none; padding:10px 22px; border-radius:8px; font-weight:700; font-size:13px; cursor:pointer;">
                        <i class="fas fa-print"></i> Load Selected
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <!-- ═══════════════════════════════════════════════════════════
             RENDER — one print-block per selected class or teacher
             ═══════════════════════════════════════════════════════════ -->

        <?php if ($mode === 'single' && !empty($class_timetables)): ?>
            <?php $single = reset($class_timetables); $timetable_data = $single['data']; $sel_label = $single['label']; ?>
            <div class="print-area" id="printArea">
                <div class="print-block print-target" data-label="<?= htmlspecialchars($single['label']); ?>">
                    <div class="print-head">
                        <div class="school">School Management System</div>
                        <div class="doctype">Class Timetable</div>
                        <div class="meta">
                            <span><strong>Class:</strong> <?= htmlspecialchars($single['label']); ?></span>
                            <span><strong>Session:</strong> <?= htmlspecialchars($session_title ?: '—'); ?></span>
                            <span><strong>Printed:</strong> <?= date('d M Y'); ?></span>
                        </div>
                    </div>
                    <div class="tbl-wrap">
                        <table class="tt">
                            <thead><tr><th class="period-head">Period</th><?php foreach ($days as $day): ?><th><?= htmlspecialchars($day); ?></th><?php endforeach; ?></tr></thead>
                            <tbody>
                                <?php foreach ($time_slots as $slot): $slot_id = (int)$slot['id']; $is_break = stripos($slot['title'] ?? '', 'break') !== false; ?>
                                    <tr>
                                        <td class="period-cell <?= $is_break ? 'is-break' : ''; ?>">
                                            <div class="pt"><?= htmlspecialchars($slot['title'] ?: ('Period ' . $slot_id)); ?></div>
                                            <div class="ptime"><?= date('H:i', strtotime($slot['start_time'])); ?>&ndash;<?= date('H:i', strtotime($slot['end_time'])); ?></div>
                                        </td>
                                        <?php foreach ($days as $di => $day): $day_id = $di + 1; $cell = $timetable_data[$day_id][$slot_id] ?? null; ?>
                                            <td>
                                                <?php if ($cell): ?>
                                                    <div class="cell-subj"><?= htmlspecialchars($cell['subject_title'] ?? '—'); ?></div>
                                                    <?php if (!empty($cell['teacher_name'])): ?><div class="cell-tch"><?= htmlspecialchars($cell['teacher_name']); ?></div><?php endif; ?>
                                                    <?php if (!empty($cell['room_no'])): ?><div class="cell-room">Room: <?= htmlspecialchars($cell['room_no']); ?></div><?php endif; ?>
                                                <?php else: ?>
                                                    <div class="cell-empty">&mdash;</div>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php elseif ($mode === 'multi' && !empty($class_timetables)): ?>
            <div class="print-area" id="printArea">
                <?php foreach ($class_timetables as $key => $ct): ?>
                    <div class="print-block print-target" data-label="<?= htmlspecialchars($ct['label']); ?>">
                        <div class="block-tools no-print">
                            <button type="button" onclick="printOne(this);"><i class="fas fa-print"></i> Print this class</button>
                        </div>
                        <div class="print-head">
                            <div class="school">School Management System</div>
                            <div class="doctype">Class Timetable</div>
                            <div class="meta">
                                <span><strong>Class:</strong> <?= htmlspecialchars($ct['label']); ?></span>
                                <span><strong>Session:</strong> <?= htmlspecialchars($session_title ?: '—'); ?></span>
                                <span><strong>Printed:</strong> <?= date('d M Y'); ?></span>
                            </div>
                        </div>
                        <div class="tbl-wrap">
                            <table class="tt">
                                <thead><tr><th class="period-head">Period</th><?php foreach ($days as $day): ?><th><?= htmlspecialchars($day); ?></th><?php endforeach; ?></tr></thead>
                                <tbody>
                                    <?php foreach ($time_slots as $slot): $slot_id = (int)$slot['id']; $is_break = stripos($slot['title'] ?? '', 'break') !== false; ?>
                                        <tr>
                                            <td class="period-cell <?= $is_break ? 'is-break' : ''; ?>">
                                                <div class="pt"><?= htmlspecialchars($slot['title'] ?: ('Period ' . $slot_id)); ?></div>
                                                <div class="ptime"><?= date('H:i', strtotime($slot['start_time'])); ?>&ndash;<?= date('H:i', strtotime($slot['end_time'])); ?></div>
                                            </td>
                                            <?php foreach ($days as $di => $day): $day_id = $di + 1; $cell = $ct['data'][$day_id][$slot_id] ?? null; ?>
                                                <td>
                                                    <?php if ($cell): ?>
                                                        <div class="cell-subj"><?= htmlspecialchars($cell['subject_title'] ?? '—'); ?></div>
                                                        <?php if (!empty($cell['teacher_name'])): ?><div class="cell-tch"><?= htmlspecialchars($cell['teacher_name']); ?></div><?php endif; ?>
                                                        <?php if (!empty($cell['room_no'])): ?><div class="cell-room">Room: <?= htmlspecialchars($cell['room_no']); ?></div><?php endif; ?>
                                                    <?php else: ?>
                                                        <div class="cell-empty">&mdash;</div>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php elseif ($mode === 'teacher' && !empty($teacher_timetables)): ?>
            <div class="print-area" id="printArea">
                <?php foreach ($teacher_timetables as $tid => $tt): ?>
                    <div class="print-block print-target" data-label="<?= htmlspecialchars($tt['label']); ?>">
                        <div class="block-tools no-print">
                            <button type="button" onclick="printOne(this);"><i class="fas fa-print"></i> Print this teacher</button>
                        </div>
                        <div class="print-head">
                            <div class="school">School Management System</div>
                            <div class="doctype">Teacher Timetable</div>
                            <div class="meta">
                                <span><strong>Teacher:</strong> <?= htmlspecialchars($tt['label']); ?></span>
                                <span><strong>Session:</strong> <?= htmlspecialchars($session_title ?: '—'); ?></span>
                                <span><strong>Printed:</strong> <?= date('d M Y'); ?></span>
                            </div>
                        </div>
                        <div class="tbl-wrap">
                            <table class="tt">
                                <thead><tr><th class="period-head">Period</th><?php foreach ($days as $day): ?><th><?= htmlspecialchars($day); ?></th><?php endforeach; ?></tr></thead>
                                <tbody>
                                    <?php foreach ($time_slots as $slot): $slot_id = (int)$slot['id']; $is_break = stripos($slot['title'] ?? '', 'break') !== false; ?>
                                        <tr>
                                            <td class="period-cell <?= $is_break ? 'is-break' : ''; ?>">
                                                <div class="pt"><?= htmlspecialchars($slot['title'] ?: ('Period ' . $slot_id)); ?></div>
                                                <div class="ptime"><?= date('H:i', strtotime($slot['start_time'])); ?>&ndash;<?= date('H:i', strtotime($slot['end_time'])); ?></div>
                                            </td>
                                            <?php foreach ($days as $di => $day): $day_id = $di + 1; $cell = $tt['data'][$day_id][$slot_id] ?? null; ?>
                                                <td>
                                                    <?php if ($cell): ?>
                                                        <div class="cell-subj"><?= htmlspecialchars($cell['class_title'] ?? '—'); ?><?= !empty($cell['section_title']) ? ' - ' . htmlspecialchars($cell['section_title']) : ''; ?></div>
                                                        <?php if (!empty($cell['subject_title'])): ?><div class="cell-tch"><?= htmlspecialchars($cell['subject_title']); ?></div><?php endif; ?>
                                                        <?php if (!empty($cell['room_no'])): ?><div class="cell-room">Room: <?= htmlspecialchars($cell['room_no']); ?></div><?php endif; ?>
                                                    <?php else: ?>
                                                        <div class="cell-empty">&mdash;</div>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php elseif ($mode !== 'single'): ?>
            <div class="empty no-print">
                <div class="big"><i class="fas fa-print"></i></div>
                <h3>Select <?= $mode === 'teacher' ? 'teachers' : 'classes' ?> above and click Load Selected</h3>
            </div>
        <?php endif; ?>

    </div>
</div>

<script>
/* Print a single .print-block */
function printOne(btn) {
    var block = btn.closest('.print-block');
    document.body.classList.add('print-one');
    document.querySelectorAll('.print-block').forEach(function(b){ b.classList.remove('print-target'); });
    block.classList.add('print-target');
    setTimeout(function(){
        window.print();
        setTimeout(function(){
            document.body.classList.remove('print-one');
            document.querySelectorAll('.print-block').forEach(function(b){ b.classList.add('print-target'); });
        }, 200);
    }, 50);
}

/* Existing single-mode export buttons (unchanged) */
<?php if ($mode === 'single' && !empty($class_timetables)): ?>
var TT_EXPORT = {
    title:    <?= json_encode($export_title, JSON_UNESCAPED_UNICODE); ?>,
    meta:     <?= json_encode($export_meta, JSON_UNESCAPED_UNICODE); ?>,
    days:     <?= json_encode($days, JSON_UNESCAPED_UNICODE); ?>,
    rows:     <?= json_encode($grid_rows, JSON_UNESCAPED_UNICODE); ?>,
    filename: <?= json_encode($export_filename, JSON_UNESCAPED_UNICODE); ?>
};
function buildMatrix() {
    var m = [];
    var header = ['Period'].concat(TT_EXPORT.days);
    var blank = new Array(header.length).fill('');
    m.push([TT_EXPORT.title].concat(new Array(header.length - 1).fill('')));
    m.push([TT_EXPORT.meta].concat(new Array(header.length - 1).fill('')));
    m.push(blank.slice());
    m.push(header);
    TT_EXPORT.rows.forEach(function(r) { m.push([r.period].concat(r.cells)); });
    return m;
}
<?php endif; ?>
</script>

</body>
</html>
<?php $conn->close(); ?>