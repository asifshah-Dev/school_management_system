<?php
ob_start();
header('Content-Type: text/html; charset=utf-8');
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
if (session_status() === PHP_SESSION_NONE) session_start();

require_once('conn_inc.php');

function numberToOrdinal($number) {
    if ($number === 'Not Ranked') return $number;
    $ends = array('th','st','nd','rd','th','th','th','th','th','th');
    if (($number % 100) >= 11 && ($number % 100) <= 13) return $number . 'th';
    return $number . $ends[$number % 10];
}

/* ═══════════════════════════════════════════════════════════
   FILTERS
   ═══════════════════════════════════════════════════════════ */
$f_session  = (int)($_GET['session_id'] ?? 0);
$f_type     = (int)($_GET['exam_type_id'] ?? 0);
$f_class    = (int)($_GET['class_id'] ?? 0);
$f_search   = trim($_GET['q'] ?? '');
$f_kind     = in_array($_GET['kind'] ?? '', ['test','term'], true) ? $_GET['kind'] : '';

$PAGE  = max(1, (int)($_GET['page'] ?? 1));
$LIMIT = 20;
$OFFSET = ($PAGE - 1) * $LIMIT;

/* Auto-pick most recent arrangement if no filters and no page given */
$selected_arrange_exam_id = (int)($_GET['arrange_exam_id'] ?? 0);

/* ═══════════════════════════════════════════════════════════
   DROPDOWN DATA
   ═══════════════════════════════════════════════════════════ */
$sessions = array();
$q = $conn->query("SELECT id, title FROM sessions WHERE status = 0 ORDER BY id DESC");
if ($q) while ($r = $q->fetch_assoc()) $sessions[] = $r;

$exam_types = array('test' => array(), 'term' => array());
$q = $conn->query("SELECT id, title, exam_kind FROM exam_types WHERE status = 0 ORDER BY exam_kind, title");
if ($q) while ($r = $q->fetch_assoc()) {
    $k = $r['exam_kind'] === 'test' ? 'test' : 'term';
    $exam_types[$k][] = $r;
}

$classes = array();
$q = $conn->query("SELECT id, title FROM classes WHERE status = 0 OR status IS NULL ORDER BY title");
if ($q) while ($r = $q->fetch_assoc()) $classes[] = $r;

/* ═══════════════════════════════════════════════════════════
   BUILD ARRANGEMENT LIST QUERY (filtered + paginated)
   ═══════════════════════════════════════════════════════════ */
$where = array("1=1");
if ($f_session > 0) $where[] = "ae.session_id = " . (int)$f_session;
if ($f_type > 0)    $where[] = "ae.exam_type_id = " . (int)$f_type;
if ($f_class > 0)   $where[] = "ae.class_id = " . (int)$f_class;
if ($f_kind !== '') $where[] = "ae.exam_kind = '" . $conn->real_escape_string($f_kind) . "'";
if ($f_search !== '') {
    $s = $conn->real_escape_string($f_search);
    $where[] = "(c.title LIKE '%$s%' OR et.title LIKE '%$s%')";
}

$where_sql = implode(' AND ', $where);

/* Count total for pagination */
$total_rows = 0;
$q = $conn->query("SELECT COUNT(*) AS c FROM arrange_exam ae INNER JOIN classes c ON c.id = ae.class_id INNER JOIN exam_types et ON et.id = ae.exam_type_id WHERE $where_sql");
if ($q) $total_rows = (int)$q->fetch_assoc()['c'];

$total_pages = max(1, (int)ceil($total_rows / $LIMIT));

/* Auto-pick first arrangement on first load */
if ($selected_arrange_exam_id <= 0 && $PAGE === 1 && empty($f_search) && $f_session === 0 && $f_type === 0 && $f_class === 0 && $f_kind === '') {
    $q = $conn->query("SELECT id FROM arrange_exam ORDER BY start_date DESC, id DESC LIMIT 1");
    if ($q && $r = $q->fetch_assoc()) $selected_arrange_exam_id = (int)$r['id'];
}

/* Fetch the page */
$arrangements = array();
$sql = "SELECT ae.id AS arrange_exam_id, ae.start_date, ae.class_id, ae.exam_kind, ae.session_id, c.title AS class_name, et.title AS exam_type, (SELECT COUNT(*) FROM arrange_exam_subjects WHERE arrange_exam_id = ae.id) AS subject_count, (SELECT COUNT(*) FROM results WHERE arrange_exam_id = ae.id) AS result_count FROM arrange_exam ae INNER JOIN classes c ON c.id = ae.class_id INNER JOIN exam_types et ON et.id = ae.exam_type_id WHERE $where_sql ORDER BY ae.start_date DESC, ae.id DESC LIMIT $LIMIT OFFSET $OFFSET";
$q = $conn->query($sql);
if ($q) while ($r = $q->fetch_assoc()) $arrangements[] = $r;

/* ═══════════════════════════════════════════════════════════
   LOAD SELECTED ARRANGEMENT
   ═══════════════════════════════════════════════════════════ */
$arr_header = null;
$class_id = 0;
if ($selected_arrange_exam_id > 0) {
    $sql = "SELECT ae.*, c.title AS class_title, et.title AS exam_type_title, et.exam_kind, s.title AS session_title FROM arrange_exam ae INNER JOIN classes c ON c.id = ae.class_id INNER JOIN exam_types et ON et.id = ae.exam_type_id LEFT JOIN sessions s ON s.id = ae.session_id WHERE ae.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $selected_arrange_exam_id);
    $stmt->execute();
    $arr_header = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($arr_header) $class_id = (int)$arr_header['class_id'];
}

/* ═══════════════════════════════════════════════════════════
   LOAD STUDENTS + RESULTS FOR SELECTED ARRANGEMENT
   ═══════════════════════════════════════════════════════════ */
$students = array();
if ($class_id > 0 && $arr_header) {
    $session_id = (int)$arr_header['session_id'];
    $sql = "SELECT sr.id, sr.name, sr.father_name, sr.reg_no, (SELECT COUNT(*) FROM results r WHERE r.student_id = sr.id AND r.arrange_exam_id = ?) AS result_count, (SELECT COALESCE(SUM(r.marks),0) FROM results r WHERE r.student_id = sr.id AND r.arrange_exam_id = ?) AS total_obtained FROM student_class sc INNER JOIN student_registration sr ON sr.id = sc.student_registration_id WHERE sc.class_id = ? AND sc.session_id = ? AND sc.status = 0 AND sr.status = 0 ORDER BY sr.name";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiii", $selected_arrange_exam_id, $selected_arrange_exam_id, $class_id, $session_id);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    usort($students, function($a, $b){
        $av = (float)$a['total_obtained'];
        $bv = (float)$b['total_obtained'];
        if ($av === $bv) return strcmp($a['name'], $b['name']);
        return ($av > $bv) ? -1 : 1;
    });
    $rank = 1; $prev = null;
    foreach ($students as &$st) {
        $t = (float)$st['total_obtained'];
        if ($t <= 0 || (int)$st['result_count'] === 0) {
            $st['position'] = 'Not Ranked';
        } else {
            if ($prev !== null && $t < $prev) $rank++;
            $st['position'] = $rank;
        }
        $prev = $t;
    }
    unset($st);
}

/* Helper to build query string preserving filters */
function qstr($overrides = array()) {
    $base = array(
        'session_id'   => $_GET['session_id']   ?? '',
        'exam_type_id' => $_GET['exam_type_id'] ?? '',
        'class_id'     => $_GET['class_id']     ?? '',
        'kind'         => $_GET['kind']         ?? '',
        'q'            => $_GET['q']            ?? '',
        'page'         => $_GET['page']         ?? '',
        'arrange_exam_id' => $_GET['arrange_exam_id'] ?? '',
    );
    foreach ($overrides as $k => $v) $base[$k] = $v;
    return http_build_query(array_filter($base, function($v){ return $v !== '' && $v !== null; }));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>DMC List</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #eef1f5; font-family: 'Inter', sans-serif; color: #1e293b; }
    .wrap { max-width: 1400px; margin: 30px auto; padding: 0 20px; }
    .head { background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); color: #fff; padding: 26px 32px; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: flex-end; gap: 20px; flex-wrap: wrap; }
    .head h1 { font-size: 24px; font-weight: 700; }
    .head .sub { font-size: 13px; opacity: 0.9; margin-top: 6px; }
    .head .hb { display: inline-flex; align-items: center; gap: 6px; padding: 9px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; text-decoration: none; cursor: pointer; border: 1px solid rgba(255,255,255,0.3); background: rgba(255,255,255,0.15); color: #fff; font-family: inherit; }
    .head .hb:hover { background: #fff; color: #1e40af; border-color: #fff; text-decoration: none; }
    .card { background: #fff; padding: 24px 28px 30px 28px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

    /* Filters */
    .filter-panel { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px 18px; margin-bottom: 20px; }
    .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
    .field label { display: block; font-size: 11px; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px; }
    .field select, .field input { width: 100%; height: 40px; padding: 0 12px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 13px; font-family: inherit; background: #fff; }
    .field select:focus, .field input:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 4px rgba(30,64,175,0.1); }
    .filter-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 14px; padding-top: 14px; border-top: 1px solid #e2e8f0; }

    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; height: 40px; padding: 0 16px; border-radius: 10px; font-size: 13px; font-weight: 700; font-family: inherit; text-decoration: none; cursor: pointer; border: 1px solid; }
    .btn-primary { background: #1e40af; color: #fff; border-color: #1e40af; }
    .btn-primary:hover { background: #1e3a8a; color: #fff; text-decoration: none; }
    .btn-ghost { background: #fff; color: #334155; border-color: #cbd5e1; }
    .btn-ghost:hover { background: #f1f5f9; color: #1e293b; text-decoration: none; }
    .btn-success { background: #059669; color: #fff; border-color: #059669; }
    .btn-success:hover { background: #047857; color: #fff; text-decoration: none; }
    .btn-warning { background: #d97706; color: #fff; border-color: #d97706; }
    .btn-warning:hover { background: #b45309; color: #fff; text-decoration: none; }
    .btn-sm { height: 32px; padding: 0 12px; font-size: 12px; }

    /* List table */
    .list-head { font-size: 12px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 2px; margin: 0 0 12px 0; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
    .list-head .count { font-size: 11px; color: #94a3b8; font-weight: 600; letter-spacing: 0; text-transform: none; }
    table.g { width: 100%; border-collapse: collapse; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
    table.g thead th { background: #1e3a8a; color: #fff; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 12px 14px; text-align: left; }
    table.g tbody td { padding: 12px 14px; font-size: 13px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    table.g tbody tr:last-child td { border-bottom: none; }
    table.g tbody tr:hover td { background: #f8fafc; }
    table.g tr.active td { background: #eff6ff !important; }
    table.g tr.active td:first-child { border-left: 4px solid #1e40af; }

    .pos-badge { display: inline-block; min-width: 34px; padding: 4px 10px; border-radius: 8px; font-size: 12px; font-weight: 800; background: #dbeafe; color: #1e40af; text-align: center; }
    .pos-badge.na { background: #f1f5f9; color: #94a3b8; }
    .pill { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; }
    .pill.term { background: #dbeafe; color: #1e40af; }
    .pill.test { background: #fef3c7; color: #92400e; }
    .pill.muted { background: #f1f5f9; color: #64748b; }

    /* Pagination */
    .pagination { display: flex; justify-content: center; align-items: center; gap: 6px; margin-top: 20px; padding-top: 18px; border-top: 1px solid #e2e8f0; flex-wrap: wrap; }
    .pagination a, .pagination span { display: inline-flex; align-items: center; justify-content: center; min-width: 36px; height: 36px; padding: 0 10px; border-radius: 8px; font-size: 13px; font-weight: 700; text-decoration: none; border: 1px solid #cbd5e1; background: #fff; color: #334155; }
    .pagination a:hover { background: #eff6ff; border-color: #1e40af; color: #1e40af; text-decoration: none; }
    .pagination .current { background: #1e40af; color: #fff; border-color: #1e40af; }
    .pagination .disabled { opacity: 0.4; pointer-events: none; }
    .pagination .dots { border: none; background: transparent; color: #94a3b8; }

    .empty { padding: 50px 20px; text-align: center; color: #94a3b8; }
    .empty .big { font-size: 44px; opacity: 0.4; margin-bottom: 10px; }
    .empty h3 { font-size: 17px; color: #475569; font-weight: 600; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">
    <div class="head">
        <div>
            <h1><i class="fas fa-file-lines"></i> DMC List</h1>
            <div class="sub">
                <?php if ($arr_header): ?>
                    <?= htmlspecialchars($arr_header['class_title']) ?>
                    &middot; <?= htmlspecialchars($arr_header['exam_type_title']) ?>
                    <?php if (!empty($arr_header['session_title'])): ?>
                        &middot; <?= htmlspecialchars($arr_header['session_title']) ?>
                    <?php endif; ?>
                    &middot; <?= count($students) ?> student(s)
                <?php else: ?>
                    Filter and pick an arrangement to view its students
                <?php endif; ?>
            </div>
        </div>
        <?php if ($selected_arrange_exam_id > 0 && $arr_header): ?>
            <div>
                <a href="dmc_print_class.php?arrange_exam_id=<?= (int)$selected_arrange_exam_id ?>" target="_blank" class="hb">
                    <i class="fas fa-print"></i> Print Class DMCs
                </a>
                <a href="result_entry.php?exam_id=<?= (int)$selected_arrange_exam_id ?>" class="hb" style="margin-left:6px;">
                    <i class="fas fa-pen-to-square"></i> Result Entry
                </a>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">

        <form method="get" class="filter-panel">
            <div class="filter-grid">
                <div class="field">
                    <label>Session</label>
                    <select name="session_id" onchange="this.form.submit()">
                        <option value="0">All Sessions</option>
                        <?php foreach ($sessions as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $f_session ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Kind</label>
                    <select name="kind" onchange="this.form.submit()">
                        <option value="">All</option>
                        <option value="term" <?= $f_kind === 'term' ? 'selected' : '' ?>>Term Exams</option>
                        <option value="test" <?= $f_kind === 'test' ? 'selected' : '' ?>>Tests</option>
                    </select>
                </div>
                <div class="field">
                    <label>Exam Type</label>
                    <select name="exam_type_id" onchange="this.form.submit()">
                        <option value="0">All Exam Types</option>
                        <?php foreach (array('test','term') as $k): ?>
                            <?php if (empty($exam_types[$k])) continue; ?>
                            <optgroup label="<?= $k === 'test' ? 'Tests' : 'Term Exams' ?>">
                                <?php foreach ($exam_types[$k] as $et): ?>
                                    <option value="<?= (int)$et['id'] ?>" <?= (int)$et['id'] === $f_type ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($et['title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Class</label>
                    <select name="class_id" onchange="this.form.submit()">
                        <option value="0">All Classes</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $f_class ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Search</label>
                    <input type="text" name="q" value="<?= htmlspecialchars($f_search) ?>" placeholder="Class or exam name...">
                </div>
            </div>
            <div class="filter-actions">
                <a href="dmc_list.php" class="btn btn-ghost"><i class="fas fa-times"></i> Clear</a>
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Apply</button>
            </div>
        </form>

        <div class="list-head">
            <span>Arrangements</span>
            <span class="count">
                <?= number_format($total_rows) ?> total
                <?php if ($total_pages > 1): ?>
                    &middot; page <?= $PAGE ?> of <?= $total_pages ?>
                <?php endif; ?>
            </span>
        </div>

        <?php if (empty($arrangements)): ?>
            <div class="empty">
                <div class="big">&#128196;</div>
                <h3>No arrangements match your filters</h3>
                <p style="margin-top:8px;font-size:13px;">Try clearing filters or arrange a new exam.</p>
            </div>
        <?php else: ?>
            <table class="g">
                <thead>
                    <tr>
                        <th style="width:5%;">#</th>
                        <th>Class</th>
                        <th>Exam Type</th>
                        <th>Start Date</th>
                        <th style="text-align:center;">Subjects</th>
                        <th style="text-align:center;">Results</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php $i = $OFFSET + 1; foreach ($arrangements as $a):
                    $is_active = ((int)$a['arrange_exam_id'] === $selected_arrange_exam_id);
                ?>
                    <tr class="<?= $is_active ? 'active' : '' ?>">
                        <td><?= $i++ ?></td>
                        <td><strong><?= htmlspecialchars($a['class_name']) ?></strong></td>
                        <td><span class="pill <?= htmlspecialchars($a['exam_kind']) ?>"><?= htmlspecialchars($a['exam_type']) ?></span></td>
                        <td><?= date('d M Y', strtotime($a['start_date'])) ?></td>
                        <td style="text-align:center;">
                            <?php if ((int)$a['subject_count'] > 0): ?>
                                <span class="pill term"><?= (int)$a['subject_count'] ?></span>
                            <?php else: ?>
                                <span class="pill muted">none</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <?php if ((int)$a['result_count'] > 0): ?>
                                <span class="pill term"><?= (int)$a['result_count'] ?></span>
                            <?php else: ?>
                                <span class="pill muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right; white-space:nowrap;">
                            <a href="?<?= qstr(['arrange_exam_id' => $a['arrange_exam_id']]) ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-list"></i> View Students
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <a href="?<?= qstr(['page' => 1]) ?>" class="<?= $PAGE <= 1 ? 'disabled' : '' ?>"><i class="fas fa-angles-left"></i></a>
                    <a href="?<?= qstr(['page' => max(1, $PAGE - 1)]) ?>" class="<?= $PAGE <= 1 ? 'disabled' : '' ?>"><i class="fas fa-angle-left"></i></a>

                    <?php
                    $start = max(1, $PAGE - 2);
                    $end   = min($total_pages, $PAGE + 2);
                    if ($start > 1) {
                        echo '<a href="?' . qstr(['page' => 1]) . '">1</a>';
                        if ($start > 2) echo '<span class="dots">...</span>';
                    }
                    for ($p = $start; $p <= $end; $p++) {
                        if ($p === $PAGE) echo '<span class="current">' . $p . '</span>';
                        else echo '<a href="?' . qstr(['page' => $p]) . '">' . $p . '</a>';
                    }
                    if ($end < $total_pages) {
                        if ($end < $total_pages - 1) echo '<span class="dots">...</span>';
                        echo '<a href="?' . qstr(['page' => $total_pages]) . '">' . $total_pages . '</a>';
                    }
                    ?>

                    <a href="?<?= qstr(['page' => min($total_pages, $PAGE + 1)]) ?>" class="<?= $PAGE >= $total_pages ? 'disabled' : '' ?>"><i class="fas fa-angle-right"></i></a>
                    <a href="?<?= qstr(['page' => $total_pages]) ?>" class="<?= $PAGE >= $total_pages ? 'disabled' : '' ?>"><i class="fas fa-angles-right"></i></a>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($arr_header): ?>
            <div class="list-head" style="margin-top:28px;">
                <span>Students — <?= htmlspecialchars($arr_header['class_title']) ?> · <?= htmlspecialchars($arr_header['exam_type_title']) ?></span>
                <span class="count"><?= count($students) ?> student(s)</span>
            </div>

            <?php if (empty($students)): ?>
                <div class="empty">
                    <div class="big">&#128101;</div>
                    <h3>No students in this class for this session</h3>
                </div>
            <?php else: ?>
                <table class="g">
                    <thead>
                        <tr>
                            <th style="width:5%;">#</th>
                            <th>Student</th>
                            <th>Father</th>
                            <th style="width:120px; text-align:center;">Position</th>
                            <th style="width:110px; text-align:center;">Marks</th>
                            <th style="width:230px; text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $j = 1; foreach ($students as $st): ?>
                        <tr>
                            <td><?= $j++ ?></td>
                            <td><strong><?= htmlspecialchars($st['name']) ?></strong>
                                <?php if (!empty($st['reg_no'])): ?>
                                    <div style="font-size:11px;color:#64748b;font-weight:400;margin-top:2px;">Reg <?= htmlspecialchars($st['reg_no']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($st['father_name'] ?: '-') ?></td>
                            <td style="text-align:center;">
                                <?php if ($st['position'] === 'Not Ranked'): ?>
                                    <span class="pos-badge na">—</span>
                                <?php else: ?>
                                    <span class="pos-badge"><?= numberToOrdinal($st['position']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <?php if ((int)$st['result_count'] > 0): ?>
                                    <span class="pill term"><?= (float)$st['total_obtained'] ?></span>
                                <?php else: ?>
                                    <span class="pill muted">none</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right; white-space:nowrap;">
                                <?php if ((int)$st['result_count'] > 0): ?>
                                    <a href="dmc_print.php?student_id=<?= (int)$st['id'] ?>&arrange_exam_id=<?= (int)$selected_arrange_exam_id ?>"
                                       target="_blank" class="btn btn-sm btn-success">
                                        <i class="fas fa-print"></i> Print DMC
                                    </a>
                                    <?php if (!empty($perms['can_edit'])): ?>
                                        <a href="edit_dmc.php?student_id=<?= (int)$st['id'] ?>&arrange_exam_id=<?= (int)$selected_arrange_exam_id ?>"
                                           class="btn btn-sm btn-warning">
                                            <i class="fas fa-pencil"></i> Edit
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <a href="result_entry.php?exam_id=<?= (int)$selected_arrange_exam_id ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-pen-to-square"></i> Enter Marks
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>

    </div>
</div>

</body>
</html>
<?php $conn->close(); ob_end_flush(); ?>