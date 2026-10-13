<?php
ob_start();
header('Content-Type: text/html; charset=utf-8');
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
if (session_status() === PHP_SESSION_NONE) session_start();

require_once('conn_inc.php');

function day_name($date) {
    if (!$date) return '';
    $d = (int)date('N', strtotime($date));
    $days = array('', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday');
    return $days[$d];
}

/* ═══════════════════════════════════════════════════════════
   FILTERS
   ═══════════════════════════════════════════════════════════ */
$filter_exam_type_id = (int)($_GET['exam_type_id'] ?? 0);
$filter_session_id   = (int)($_GET['session_id'] ?? 0);
$filter_class_id     = (int)($_GET['class_id'] ?? 0);
$filter_date_from    = trim($_GET['date_from'] ?? '');
$filter_date_to      = trim($_GET['date_to'] ?? '');
$filter_search       = trim($_GET['q'] ?? '');
$view                = isset($_GET['view']) && in_array($_GET['view'], array('subject','day','class'), true) ? $_GET['view'] : 'day';

/* ═══════════════════════════════════════════════════════════
   DROPDOWN DATA
   ═══════════════════════════════════════════════════════════ */
$exam_types = array('test' => array(), 'term' => array());
$q = $conn->query("SELECT id, title, exam_kind FROM exam_types WHERE status = 0 ORDER BY exam_kind, title");
if ($q) while ($r = $q->fetch_assoc()) {
    $k = $r['exam_kind'] === 'test' ? 'test' : 'term';
    $exam_types[$k][] = $r;
}

$sessions = array();
$q = $conn->query("SELECT id, title FROM sessions WHERE status = 0 ORDER BY id DESC");
if ($q) while ($r = $q->fetch_assoc()) $sessions[] = $r;

$classes = array();
$q = $conn->query("SELECT id, title FROM classes WHERE status = 0 OR status IS NULL ORDER BY title");
if ($q) while ($r = $q->fetch_assoc()) $classes[] = $r;

/* ═══════════════════════════════════════════════════════════
   LOAD DATESHEET ROWS
   ═══════════════════════════════════════════════════════════ */
$header = array('exam_type_title' => '', 'exam_kind' => '', 'session_title' => '');
$rows = array();
$classList = array();
$subjectList = array();
$byDay = array();
$byClass = array();
$bySubjectClass = array();
$dateRange = array('min' => '', 'max' => '');

if ($filter_exam_type_id > 0) {
    $stmt = $conn->prepare("SELECT id, title, exam_kind FROM exam_types WHERE id = ?");
    $stmt->bind_param("i", $filter_exam_type_id);
    $stmt->execute();
    $et = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($et) {
        $header['exam_type_title'] = $et['title'];
        $header['exam_kind'] = $et['exam_kind'];
    }

    if ($filter_session_id > 0) {
        $stmt = $conn->prepare("SELECT title FROM sessions WHERE id = ?");
        $stmt->bind_param("i", $filter_session_id);
        $stmt->execute();
        $sr = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($sr) $header['session_title'] = $sr['title'];
    }

    $where = array("ae.exam_type_id = " . (int)$filter_exam_type_id);
    if ($filter_session_id > 0) $where[] = "ae.session_id = " . (int)$filter_session_id;
    if ($filter_class_id > 0)   $where[] = "ae.class_id = "     . (int)$filter_class_id;
    if ($filter_date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_from)) {
        $where[] = "ed.start_date >= '" . $conn->real_escape_string($filter_date_from) . "'";
    }
    if ($filter_date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_to)) {
        $where[] = "ed.start_date <= '" . $conn->real_escape_string($filter_date_to) . "'";
    }
    if ($filter_search !== '') {
        $s = $conn->real_escape_string($filter_search);
        $where[] = "(c.title LIKE '%$s%' OR et.title LIKE '%$s%' OR s.title LIKE '%$s%' OR ss.title LIKE '%$s%')";
    }
    $where_sql = implode(' AND ', $where);

    $sql = "SELECT ed.id AS ds_id, ed.start_date, ed.start_time, ed.end_time, ae.id AS arrange_exam_id, ae.class_id, c.title AS class_title, ed.subject_id, s.title AS subject_title, s.type AS subject_type, ed.sub_subject_id, ss.title AS sub_subject_title FROM exam_datesheet ed INNER JOIN arrange_exam ae ON ae.id = ed.arrange_exam_id INNER JOIN classes c ON c.id = ae.class_id INNER JOIN exam_types et ON et.id = ae.exam_type_id INNER JOIN subjects s ON s.id = ed.subject_id LEFT JOIN sub_subjects ss ON ss.id = ed.sub_subject_id WHERE $where_sql ORDER BY ed.start_date ASC, ed.start_time ASC, c.title ASC, s.title ASC, ss.title ASC";
    $q = $conn->query($sql);
    if ($q) while ($r = $q->fetch_assoc()) $rows[] = $r;

    foreach ($rows as $r) {
        $classList[(int)$r['class_id']] = $r['class_title'];
        $sid = (int)$r['subject_id'];
        if (!isset($subjectList[$sid])) {
            $subjectList[$sid] = array('id' => $sid, 'title' => $r['subject_title'], 'type' => $r['subject_type']);
        }
        $d = $r['start_date'];
        if (!isset($byDay[$d])) $byDay[$d] = array();
        $byDay[$d][] = $r;

        $cid = (int)$r['class_id'];
        $ss = $r['sub_subject_id'] !== null ? (int)$r['sub_subject_id'] : 0;
        if (!isset($byClass[$cid])) $byClass[$cid] = array();
        if (!isset($byClass[$cid][$sid])) $byClass[$cid][$sid] = array();
        $byClass[$cid][$sid][$ss] = $r;

        if (!isset($bySubjectClass[$sid])) $bySubjectClass[$sid] = array();
        if (!isset($bySubjectClass[$sid][$cid])) $bySubjectClass[$sid][$cid] = array();
        $bySubjectClass[$sid][$cid][$ss] = $r;

        if ($dateRange['min'] === '' || $d < $dateRange['min']) $dateRange['min'] = $d;
        if ($dateRange['max'] === '' || $d > $dateRange['max']) $dateRange['max'] = $d;
    }
    ksort($byDay);
}

/* ═══════════════════════════════════════════════════════════
   BUILD QUERY STRING PRESERVING FILTERS
   ═══════════════════════════════════════════════════════════ */
$qstr = function($overrides = array()) {
    $base = array(
        'exam_type_id' => $_GET['exam_type_id'] ?? '',
        'session_id'   => $_GET['session_id']   ?? '',
        'class_id'     => $_GET['class_id']     ?? '',
        'date_from'    => $_GET['date_from']    ?? '',
        'date_to'      => $_GET['date_to']      ?? '',
        'q'            => $_GET['q']            ?? '',
        'view'         => $_GET['view']         ?? '',
    );
    foreach ($overrides as $k => $v) $base[$k] = $v;
    return http_build_query(array_filter($base, function($v){ return $v !== '' && $v !== null; }));
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Master Datesheet</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #eef1f5; font-family: 'Inter', sans-serif; color: #1e293b; }
    .wrap { max-width: 1500px; margin: 30px auto; padding: 0 20px; }
    .head { background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); color: #fff; padding: 26px 32px; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: flex-end; gap: 20px; flex-wrap: wrap; }
    .head h1 { font-size: 24px; font-weight: 700; }
    .head .sub { font-size: 13px; opacity: 0.9; margin-top: 6px; }
    .head .hb { display: inline-flex; align-items: center; gap: 6px; padding: 9px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; text-decoration: none; cursor: pointer; border: 1px solid rgba(255,255,255,0.3); background: rgba(255,255,255,0.15); color: #fff; font-family: inherit; margin-left: 6px; }
    .head .hb:hover { background: #fff; color: #1e40af; border-color: #fff; text-decoration: none; }
    .card { background: #fff; padding: 24px 28px 30px 28px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

    .filter-panel { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px 18px; margin-bottom: 20px; }
    .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
    .field label { display: block; font-size: 11px; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px; }
    .field select, .field input { width: 100%; height: 40px; padding: 0 12px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 13px; font-family: inherit; background: #fff; }
    .field select:focus, .field input:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 4px rgba(30,64,175,0.1); }
    .filter-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 12px; padding-top: 12px; border-top: 1px solid #e2e8f0; flex-wrap: wrap; }

    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; height: 40px; padding: 0 16px; border-radius: 10px; font-size: 13px; font-weight: 700; font-family: inherit; cursor: pointer; border: 1px solid; text-decoration: none; }
    .btn-primary { background: #1e40af; color: #fff; border-color: #1e40af; }
    .btn-primary:hover { background: #1e3a8a; color: #fff; text-decoration: none; }
    .btn-ghost { background: #fff; color: #334155; border-color: #cbd5e1; }
    .btn-ghost:hover { background: #f1f5f9; color: #1e293b; text-decoration: none; }
    .btn-sm { height: 34px; padding: 0 12px; font-size: 12px; }

    .tabs { display: flex; gap: 6px; margin-bottom: 20px; flex-wrap: wrap; }
    .tab { padding: 9px 18px; border-radius: 10px; font-size: 12px; font-weight: 700; border: 1px solid #cbd5e1; background: #fff; color: #334155; text-decoration: none; }
    .tab.active { background: #1e40af; color: #fff; border-color: #1e40af; }
    .tab:hover { text-decoration: none; color: #1e40af; border-color: #1e40af; }
    .tab.active:hover { color: #fff; border-color: #1e40af; }

    .list-head { font-size: 12px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 2px; margin: 0 0 14px 0; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
    .list-head .count { font-size: 11px; color: #94a3b8; font-weight: 600; letter-spacing: 0; text-transform: none; }

    .pill { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; }
    .pill.term { background: #dbeafe; color: #1e40af; }
    .pill.test { background: #fef3c7; color: #92400e; }
    .pill.muted { background: #f1f5f9; color: #64748b; }
    .pill.date { background: #f3e8ff; color: #6b21a8; }

    .empty { padding: 60px 20px; text-align: center; color: #94a3b8; }
    .empty .big { font-size: 44px; opacity: 0.4; margin-bottom: 10px; }
    .empty h3 { font-size: 17px; color: #475569; font-weight: 600; margin-bottom: 6px; }
    .empty a { color: #1e40af; font-weight: 700; }

    /* Day groups */
    .day-group { margin-bottom: 20px; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; }
    .day-group .dg-head { background: #eff6ff; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #dbeafe; }
    .day-group .dg-head strong { color: #1e40af; font-size: 14px; }
    .day-group .dg-head .date-label { font-size: 12px; color: #475569; font-weight: 600; }
    table.dt { width: 100%; border-collapse: collapse; }
    table.dt thead th { background: #f8fafc; font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase; padding: 10px 14px; text-align: left; border-bottom: 1px solid #e2e8f0; letter-spacing: 1px; }
    table.dt tbody td { padding: 10px 14px; font-size: 13px; border-bottom: 1px solid #f1f5f9; }
    table.dt tbody tr:last-child td { border-bottom: none; }

    /* Subject × Class matrix */
    .matrix-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; }
    table.matrix { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 800px; }
    table.matrix thead th { background: #1e3a8a; color: #fff; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 12px 10px; text-align: center; border-right: 1px solid rgba(255,255,255,0.15); }
    table.matrix thead th.subj-head { text-align: left; }
    table.matrix thead th:last-child { border-right: none; }
    table.matrix tbody td { padding: 10px 12px; font-size: 12px; border-right: 1px solid #f1f5f9; border-bottom: 1px solid #f1f5f9; background: #fff; vertical-align: top; }
    table.matrix tbody td.subj-cell { background: #f8fafc; font-weight: 700; color: #0f172a; min-width: 180px; }
    table.matrix tbody tr:last-child td { border-bottom: none; }
    table.matrix .cell-date { font-weight: 700; color: #1e40af; font-size: 11px; }
    table.matrix .cell-time { font-size: 11px; color: #475569; margin-top: 2px; }
    table.matrix .cell-empty { color: #cbd5e1; font-style: italic; font-size: 11px; }

    /* Class blocks */
    .class-block { margin-bottom: 26px; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; }
    .class-block .cb-head { background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); color: #fff; padding: 14px 18px; font-size: 15px; font-weight: 700; }
    table.ct { width: 100%; border-collapse: collapse; }
    table.ct thead th { background: #f8fafc; font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase; padding: 10px 14px; text-align: left; border-bottom: 1px solid #e2e8f0; letter-spacing: 1px; }
    table.ct tbody td { padding: 10px 14px; font-size: 13px; border-bottom: 1px solid #f1f5f9; }
    table.ct tbody tr.ss-row td:first-child { padding-left: 34px; color: #475569; }
    table.ct tbody tr:last-child td { border-bottom: none; }

    .print-header { display: none; }
    @media print {
        @page { margin: 12mm; size: A4 landscape; }
        body { background: #fff !important; }
        body * { visibility: hidden !important; }
        .print-area, .print-area * { visibility: visible !important; }
        .print-area { position: absolute !important; left: 0; top: 0; width: 100%; padding: 0; margin: 0; }
        .head, .filters, .filter-panel, .tabs, .no-print, .btn { display: none !important; }
        .card { box-shadow: none !important; padding: 0 !important; background: #fff !important; border-radius: 0 !important; }
        .print-header { display: block !important; text-align: center; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 2px solid #1e3a8a; }
        .print-header h2 { font-size: 20px; color: #1e3a8a; margin: 4px 0; }
        .print-header p { font-size: 12px; color: #475569; }
        .day-group, .class-block { page-break-inside: avoid; }
        table.matrix thead th { background: #1e3a8a !important; color: #fff !important; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">
    <div class="head no-print">
        <div>
            <h1><i class="fas fa-layer-group"></i> Master Datesheet</h1>
            <div class="sub">
                <?php if ($filter_exam_type_id > 0): ?>
                    <?= htmlspecialchars($header['exam_type_title']) ?>
                    <?php if ($header['exam_kind']): ?><span class="pill <?= htmlspecialchars($header['exam_kind']) ?>"><?= htmlspecialchars($header['exam_kind']) ?></span><?php endif; ?>
                    <?php if ($header['session_title']): ?> &middot; <?= htmlspecialchars($header['session_title']) ?><?php endif; ?>
                    &middot; <?= count($rows) ?> paper(s) &middot; <?= count($classList) ?> class(es)
                <?php else: ?>
                    Filter and view the datesheet across all classes
                <?php endif; ?>
            </div>
        </div>
        <div>
            <?php if ($filter_exam_type_id > 0 && !empty($rows)): ?>
                <a href="#" onclick="event.preventDefault(); window.print();" class="hb"><i class="fas fa-print"></i> Print</a>
            <?php endif; ?>
            <a href="exam_hub.php" class="hb"><i class="fas fa-arrow-left"></i> Hub</a>
        </div>
    </div>

    <div class="card">
        <div class="print-area">

        <form method="get" class="filter-panel no-print">
            <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
            <div class="filter-grid">
                <div class="field">
                    <label>Exam Type <span style="color:#dc2626;">*</span></label>
                    <select name="exam_type_id" onchange="this.form.submit()">
                        <option value="">— Select —</option>
                        <?php foreach (array('test','term') as $k): ?>
                            <?php if (empty($exam_types[$k])) continue; ?>
                            <optgroup label="<?= $k === 'test' ? 'Tests' : 'Term Exams' ?>">
                                <?php foreach ($exam_types[$k] as $et): ?>
                                    <option value="<?= (int)$et['id'] ?>" <?= (int)$et['id'] === $filter_exam_type_id ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($et['title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Session</label>
                    <select name="session_id" onchange="this.form.submit()">
                        <option value="0">All Sessions</option>
                        <?php foreach ($sessions as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $filter_session_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Class</label>
                    <select name="class_id" onchange="this.form.submit()">
                        <option value="0">All Classes</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $filter_class_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>From Date</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($filter_date_from) ?>">
                </div>
                <div class="field">
                    <label>To Date</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($filter_date_to) ?>">
                </div>
                <div class="field">
                    <label>Search</label>
                    <input type="text" name="q" value="<?= htmlspecialchars($filter_search) ?>" placeholder="Class, exam, or subject...">
                </div>
            </div>
            <div class="filter-actions">
                <a href="exam_master_datesheet.php" class="btn btn-sm btn-ghost"><i class="fas fa-times"></i> Clear</a>
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-search"></i> Apply</button>
            </div>
        </form>

        <?php if ($filter_exam_type_id === 0): ?>
            <div class="empty">
                <div class="big">&#128194;</div>
                <h3>Pick an exam type to view the master datesheet</h3>
                <p style="margin-top:8px;font-size:13px;">Filters by session, class, date range, or keyword.</p>
            </div>
        <?php elseif (empty($rows)): ?>
            <div class="empty">
                <div class="big">&#128229;</div>
                <h3>No datesheet entries match your filters</h3>
                <p style="margin-top:8px;font-size:13px;">
                    Try clearing filters or add dates in <a href="datesheet.php">Datesheet</a>.
                </p>
            </div>
        <?php else: ?>

            <div class="list-head no-print">
                <span>Results</span>
                <span class="count">
                    <?= number_format(count($rows)) ?> paper(s) across <?= count($classList) ?> class(es)
                    <?php if (!empty($dateRange['min'])): ?>
                        &middot; <?= date('d M', strtotime($dateRange['min'])) ?> – <?= date('d M Y', strtotime($dateRange['max'])) ?>
                    <?php endif; ?>
                </span>
            </div>

            <div class="tabs no-print">
                <a href="?<?= htmlspecialchars($qstr(['view' => 'day'])) ?>" class="tab <?= $view === 'day' ? 'active' : '' ?>">
                    <i class="fas fa-calendar-day"></i> By Day
                </a>
                <a href="?<?= htmlspecialchars($qstr(['view' => 'subject'])) ?>" class="tab <?= $view === 'subject' ? 'active' : '' ?>">
                    <i class="fas fa-table-cells"></i> By Subject &times; Class
                </a>
                <a href="?<?= htmlspecialchars($qstr(['view' => 'class'])) ?>" class="tab <?= $view === 'class' ? 'active' : '' ?>">
                    <i class="fas fa-chalkboard"></i> By Class
                </a>
            </div>

            <div class="print-header">
                <h2>SCHOOL MANAGEMENT SYSTEM</h2>
                <p>
                    <strong>Master Datesheet</strong> &mdash;
                    <?= htmlspecialchars($header['exam_type_title']) ?>
                    <?php if ($header['session_title']): ?> &middot; Session <?= htmlspecialchars($header['session_title']) ?><?php endif; ?>
                    <?php if (!empty($dateRange['min'])): ?>
                        &middot; <?= date('d M', strtotime($dateRange['min'])) ?> – <?= date('d M Y', strtotime($dateRange['max'])) ?>
                    <?php endif; ?>
                </p>
            </div>

            <?php if ($view === 'day'): ?>
                <?php foreach ($byDay as $date => $dayRows): ?>
                    <div class="day-group">
                        <div class="dg-head">
                            <strong><?= htmlspecialchars(day_name($date)) ?></strong>
                            <span class="date-label"><?= date('d M Y', strtotime($date)) ?> &middot; <?= count($dayRows) ?> paper(s)</span>
                        </div>
                        <table class="dt">
                            <thead>
                                <tr>
                                    <th style="width:5%;">#</th>
                                    <th style="width:20%;">Class</th>
                                    <th>Subject</th>
                                    <th style="width:15%;">Start</th>
                                    <th style="width:15%;">End</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php $i = 1; foreach ($dayRows as $r): ?>
                                <tr>
                                    <td><?= $i++ ?></td>
                                    <td><?= htmlspecialchars($r['class_title']) ?></td>
                                    <td>
                                        <?= htmlspecialchars($r['subject_title']) ?>
                                        <?php if (!empty($r['sub_subject_title'])): ?>
                                            <span style="color:#64748b;"> &mdash; <?= htmlspecialchars($r['sub_subject_title']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars(substr($r['start_time'], 0, 5)) ?></td>
                                    <td><?= htmlspecialchars(substr($r['end_time'], 0, 5)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>

            <?php elseif ($view === 'subject'): ?>
                <?php if (empty($subjectList) || empty($classList)): ?>
                    <div class="empty"><h3>No data to display</h3></div>
                <?php else: ?>
                    <div class="matrix-wrap">
                        <table class="matrix">
                            <thead>
                                <tr>
                                    <th class="subj-head">Subject</th>
                                    <?php foreach ($classList as $cid => $ctitle): ?>
                                        <th><?= htmlspecialchars($ctitle) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($subjectList as $sid => $subj): ?>
                                <tr>
                                    <td class="subj-cell">
                                        <?= htmlspecialchars($subj['title']) ?>
                                        <?php if ($subj['type'] === 'composite'): ?>
                                            <span class="pill muted" style="margin-left:6px;font-size:10px;">composite</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php foreach ($classList as $cid => $ctitle):
                                        $cells = isset($bySubjectClass[$sid][$cid]) ? $bySubjectClass[$sid][$cid] : array();
                                        ksort($cells);
                                    ?>
                                        <td>
                                            <?php if (empty($cells)): ?>
                                                <span class="cell-empty">&mdash;</span>
                                            <?php else: ?>
                                                <?php foreach ($cells as $ssId => $r): ?>
                                                    <div style="margin-bottom:6px;">
                                                        <?php if ($ssId > 0): ?>
                                                            <div style="font-size:10px;color:#64748b;font-weight:700;">
                                                                <?= htmlspecialchars($r['sub_subject_title']) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <div class="cell-date"><?= date('d M', strtotime($r['start_date'])) ?></div>
                                                        <div class="cell-time">
                                                            <?= htmlspecialchars(substr($r['start_time'], 0, 5)) ?>&ndash;<?= htmlspecialchars(substr($r['end_time'], 0, 5)) ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

            <?php elseif ($view === 'class'): ?>
                <?php foreach ($byClass as $cid => $subjects): ?>
                    <div class="class-block">
                        <div class="cb-head"><i class="fas fa-chalkboard"></i> <?= htmlspecialchars(isset($classList[$cid]) ? $classList[$cid] : 'Class') ?></div>
                        <table class="ct">
                            <thead>
                                <tr>
                                    <th style="width:30%;">Subject</th>
                                    <th style="width:15%;">Date</th>
                                    <th style="width:15%;">Day</th>
                                    <th style="width:15%;">Start</th>
                                    <th style="width:15%;">End</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($subjects as $sid => $entries):
                                ksort($entries);
                                $subjectTitle = '';
                                foreach ($entries as $e) { $subjectTitle = $e['subject_title']; break; }
                            ?>
                                <?php foreach ($entries as $ssId => $r): ?>
                                    <tr class="<?= $ssId > 0 ? 'ss-row' : '' ?>">
                                        <td>
                                            <?php if ($ssId > 0): ?>
                                                <?= htmlspecialchars($r['sub_subject_title']) ?>
                                            <?php else: ?>
                                                <?= htmlspecialchars($subjectTitle) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= date('d M Y', strtotime($r['start_date'])) ?></td>
                                        <td><?= htmlspecialchars(day_name($r['start_date'])) ?></td>
                                        <td><?= htmlspecialchars(substr($r['start_time'], 0, 5)) ?></td>
                                        <td><?= htmlspecialchars(substr($r['end_time'], 0, 5)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        <?php endif; ?>

        </div><!-- /.print-area -->
    </div>
</div>

</body>
</html>
<?php $conn->close(); ob_end_flush(); ?>