<?php
ob_start();
header('Content-Type: text/html; charset=utf-8');
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

require_once('conn_inc.php');

$csrf = $_SESSION['csrf_token'];

/* ═══════════════════════════════════════════════════════════
   PICKER
   ═══════════════════════════════════════════════════════════ */
$arrange_exam_id = (int)($_GET['exam_id'] ?? 0);

if ($arrange_exam_id <= 0) {

    $f_session = (int)($_GET['session_id'] ?? 0);
    $f_type    = (int)($_GET['exam_type_id'] ?? 0);
    $f_class   = (int)($_GET['class_id'] ?? 0);
    $f_kind    = in_array($_GET['kind'] ?? '', array('test','term'), true) ? $_GET['kind'] : '';
    $f_search  = trim($_GET['q'] ?? '');

    $PAGE  = max(1, (int)($_GET['page'] ?? 1));
    $LIMIT = 20;
    $OFFSET = ($PAGE - 1) * $LIMIT;

    /* Dropdowns */
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

    /* WHERE */
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

    $total_rows = 0;
    $q = $conn->query("SELECT COUNT(*) AS c FROM arrange_exam ae INNER JOIN classes c ON c.id = ae.class_id INNER JOIN exam_types et ON et.id = ae.exam_type_id WHERE $where_sql");
    if ($q) $total_rows = (int)$q->fetch_assoc()['c'];
    $total_pages = max(1, (int)ceil($total_rows / $LIMIT));

    $picker_rows = array();
    $sql = "SELECT ae.id, ae.start_date, ae.exam_kind, ae.session_id, ae.class_id, c.title AS class_title, et.title AS exam_type_title, (SELECT COUNT(*) FROM arrange_exam_subjects WHERE arrange_exam_id = ae.id) AS subject_count, (SELECT COUNT(*) FROM results WHERE arrange_exam_id = ae.id) AS result_count FROM arrange_exam ae INNER JOIN classes c ON c.id = ae.class_id INNER JOIN exam_types et ON et.id = ae.exam_type_id WHERE $where_sql ORDER BY ae.start_date DESC, ae.id DESC LIMIT $LIMIT OFFSET $OFFSET";
    $q = $conn->query($sql);
    if ($q) while ($r = $q->fetch_assoc()) $picker_rows[] = $r;

    $qstr = function($overrides = array()) {
        $base = array(
            'session_id'   => $_GET['session_id']   ?? '',
            'exam_type_id' => $_GET['exam_type_id'] ?? '',
            'class_id'     => $_GET['class_id']     ?? '',
            'kind'         => $_GET['kind']         ?? '',
            'q'            => $_GET['q']            ?? '',
            'page'         => $_GET['page']         ?? '',
        );
        foreach ($overrides as $k => $v) $base[$k] = $v;
        return http_build_query(array_filter($base, function($v){ return $v !== '' && $v !== null; }));
    };
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="utf-8">
    <title>Result Entry - Pick Arrangement</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #eef1f5; font-family: 'Inter', sans-serif; color: #1e293b; }
        .wrap { max-width: 1400px; margin: 30px auto; padding: 0 20px; }
        .head { background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); color: #fff; padding: 26px 32px; border-radius: 12px 12px 0 0; }
        .head h1 { font-size: 24px; font-weight: 700; }
        .head .sub { font-size: 13px; opacity: 0.9; margin-top: 6px; }
        .card { background: #fff; padding: 24px 28px 30px 28px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .filter-panel { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px 18px; margin-bottom: 20px; }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
        .field label { display: block; font-size: 11px; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px; }
        .field select, .field input { width: 100%; height: 40px; padding: 0 12px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 13px; font-family: inherit; background: #fff; }
        .field select:focus, .field input:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 4px rgba(30,64,175,0.1); }
        .filter-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 14px; padding-top: 14px; border-top: 1px solid #e2e8f0; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; height: 40px; padding: 0 16px; border-radius: 10px; font-size: 13px; font-weight: 700; font-family: inherit; cursor: pointer; border: 1px solid; text-decoration: none; }
        .btn-primary { background: #1e40af; color: #fff; border-color: #1e40af; }
        .btn-primary:hover { background: #1e3a8a; color: #fff; text-decoration: none; }
        .btn-ghost { background: #fff; color: #334155; border-color: #cbd5e1; }
        .btn-ghost:hover { background: #f1f5f9; color: #1e293b; text-decoration: none; }
        .btn-success { background: #059669; color: #fff; border-color: #059669; }
        .btn-success:hover { background: #047857; color: #fff; text-decoration: none; }
        .btn-sm { height: 32px; padding: 0 12px; font-size: 12px; }
        .list-head { font-size: 12px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 2px; margin: 0 0 12px 0; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
        .list-head .count { font-size: 11px; color: #94a3b8; font-weight: 600; letter-spacing: 0; text-transform: none; }
        table.g { width: 100%; border-collapse: collapse; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
        table.g thead th { background: #1e3a8a; color: #fff; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 12px; text-align: left; }
        table.g tbody td { padding: 12px; font-size: 13px; border-bottom: 1px solid #f1f5f9; }
        table.g tbody tr:hover td { background: #f8fafc; }
        .pill { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; }
        .pill.term { background: #dbeafe; color: #1e40af; }
        .pill.test { background: #fef3c7; color: #92400e; }
        .pill.muted { background: #f1f5f9; color: #64748b; }
        .pagination { display: flex; justify-content: center; align-items: center; gap: 6px; margin-top: 20px; padding-top: 18px; border-top: 1px solid #e2e8f0; flex-wrap: wrap; }
        .pagination a, .pagination span { display: inline-flex; align-items: center; justify-content: center; min-width: 36px; height: 36px; padding: 0 10px; border-radius: 8px; font-size: 13px; font-weight: 700; text-decoration: none; border: 1px solid #cbd5e1; background: #fff; color: #334155; }
        .pagination a:hover { background: #eff6ff; border-color: #1e40af; color: #1e40af; text-decoration: none; }
        .pagination .current { background: #1e40af; color: #fff; border-color: #1e40af; }
        .pagination .disabled { opacity: 0.4; pointer-events: none; }
        .pagination .dots { border: none; background: transparent; color: #94a3b8; }
        .empty { padding: 60px 20px; text-align: center; color: #94a3b8; }
        .empty .big { font-size: 44px; opacity: 0.4; margin-bottom: 10px; }
        .empty h3 { font-size: 17px; color: #475569; font-weight: 600; }
    </style>
    </head>
    <body>
    <?php require_once('navbar.php'); ?>
    <div class="wrap">
        <div class="head">
            <h1><i class="fas fa-pen-to-square"></i> Result Entry</h1>
            <div class="sub">Filter and pick an arrangement to enter or edit marks</div>
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
                    <a href="result_entry.php" class="btn btn-ghost"><i class="fas fa-times"></i> Clear</a>
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

            <?php if (empty($picker_rows)): ?>
                <div class="empty">
                    <div class="big">&#128221;</div>
                    <h3>No arrangements match your filters</h3>
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
                            <th style="text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $i = $OFFSET + 1; foreach ($picker_rows as $r): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><strong><?= htmlspecialchars($r['class_title']) ?></strong></td>
                            <td><span class="pill <?= htmlspecialchars($r['exam_kind']) ?>"><?= htmlspecialchars($r['exam_type_title']) ?></span></td>
                            <td><?= date('d M Y', strtotime($r['start_date'])) ?></td>
                            <td style="text-align:center;">
                                <?php if ((int)$r['subject_count'] > 0): ?>
                                    <span class="pill term"><?= (int)$r['subject_count'] ?></span>
                                <?php else: ?>
                                    <span class="pill muted">none</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <?php if ((int)$r['result_count'] > 0): ?>
                                    <span class="pill term"><?= (int)$r['result_count'] ?></span>
                                <?php else: ?>
                                    <span class="pill muted">0</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;">
                                <a href="result_entry.php?exam_id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-primary">
                                    <i class="fas fa-arrow-right"></i> Open
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <a href="?<?= $qstr(['page' => 1]) ?>" class="<?= $PAGE <= 1 ? 'disabled' : '' ?>"><i class="fas fa-angles-left"></i></a>
                        <a href="?<?= $qstr(['page' => max(1, $PAGE - 1)]) ?>" class="<?= $PAGE <= 1 ? 'disabled' : '' ?>"><i class="fas fa-angle-left"></i></a>
                        <?php
                        $start = max(1, $PAGE - 2);
                        $end   = min($total_pages, $PAGE + 2);
                        if ($start > 1) {
                            echo '<a href="?' . $qstr(['page' => 1]) . '">1</a>';
                            if ($start > 2) echo '<span class="dots">...</span>';
                        }
                        for ($p = $start; $p <= $end; $p++) {
                            if ($p === $PAGE) echo '<span class="current">' . $p . '</span>';
                            else echo '<a href="?' . $qstr(['page' => $p]) . '">' . $p . '</a>';
                        }
                        if ($end < $total_pages) {
                            if ($end < $total_pages - 1) echo '<span class="dots">...</span>';
                            echo '<a href="?' . $qstr(['page' => $total_pages]) . '">' . $total_pages . '</a>';
                        }
                        ?>
                        <a href="?<?= $qstr(['page' => min($total_pages, $PAGE + 1)]) ?>" class="<?= $PAGE >= $total_pages ? 'disabled' : '' ?>"><i class="fas fa-angle-right"></i></a>
                        <a href="?<?= $qstr(['page' => $total_pages]) ?>" class="<?= $PAGE >= $total_pages ? 'disabled' : '' ?>"><i class="fas fa-angles-right"></i></a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    </body>
    </html>
    <?php
    $conn->close();
    ob_end_flush();
    exit;
}

/* ═══════════════════════════════════════════════════════════
   LOAD ARRANGEMENT (unchanged)
   ═══════════════════════════════════════════════════════════ */
$sql = "SELECT ae.*, et.title AS exam_type_title, et.exam_kind, c.title AS class_title, s.title AS session_title FROM arrange_exam ae INNER JOIN exam_types et ON et.id = ae.exam_type_id INNER JOIN classes c ON c.id = ae.class_id LEFT JOIN sessions s ON s.id = ae.session_id WHERE ae.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $arrange_exam_id);
$stmt->execute();
$arr = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$arr) {
    $_SESSION['message'] = 'Arrangement not found.';
    $_SESSION['message_type'] = 'danger';
    header('Location: result_entry.php');
    exit;
}

$class_id = (int)$arr['class_id'];
$session_id = (int)$arr['session_id'];

/* ═══════════════════════════════════════════════════════════
   LOAD ARRANGEMENT SUBJECTS
   ═══════════════════════════════════════════════════════════ */
$arrSubjects = array();
$sqlAs = "SELECT aes.id AS arr_sub_id, aes.subject_id, aes.max_marks, s.title AS subject_title, s.type AS subject_type FROM arrange_exam_subjects aes INNER JOIN subjects s ON s.id = aes.subject_id WHERE aes.arrange_exam_id = " . (int)$arrange_exam_id . " ORDER BY aes.display_order, s.title";
$q = $conn->query($sqlAs);
if ($q) while ($r = $q->fetch_assoc()) {
    $r['sub_subjects'] = array();
    $arrSubjects[(int)$r['arr_sub_id']] = $r;
}

if (!empty($arrSubjects)) {
    $ids = implode(',', array_map('intval', array_keys($arrSubjects)));
    $sqlSs = "SELECT aes.arrange_exam_subject_id, aes.sub_subject_id, aes.max_marks, ss.title AS sub_subject_title FROM arrange_exam_sub_subjects aes INNER JOIN sub_subjects ss ON ss.id = aes.sub_subject_id WHERE aes.arrange_exam_subject_id IN ($ids) ORDER BY aes.display_order, ss.title";
    $q = $conn->query($sqlSs);
    if ($q) while ($r = $q->fetch_assoc()) {
        $arrSubjects[(int)$r['arrange_exam_subject_id']]['sub_subjects'][(int)$r['sub_subject_id']] = $r;
    }
}

$arr_sub_id = (int)($_GET['arr_sub_id'] ?? 0);
if ($arr_sub_id <= 0 && !empty($arrSubjects)) {
    $keys = array_keys($arrSubjects);
    $arr_sub_id = (int)$keys[0];
}
$selected = isset($arrSubjects[$arr_sub_id]) ? $arrSubjects[$arr_sub_id] : null;

/* ═══════════════════════════════════════════════════════════
   SAVE RESULTS
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_results') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf) {
        $_SESSION['message'] = 'Invalid request token.';
        $_SESSION['message_type'] = 'danger';
        header('Location: result_entry.php?exam_id=' . $arrange_exam_id . '&arr_sub_id=' . $arr_sub_id);
        exit;
    }

    $post_arr_sub_id = (int)($_POST['arr_sub_id'] ?? 0);
    if (!isset($arrSubjects[$post_arr_sub_id])) {
        $_SESSION['message'] = 'Subject is not part of this arrangement.';
        $_SESSION['message_type'] = 'danger';
        header('Location: result_entry.php?exam_id=' . $arrange_exam_id);
        exit;
    }

    $subj = $arrSubjects[$post_arr_sub_id];
    $subject_id = (int)$subj['subject_id'];
    $is_composite = !empty($subj['sub_subjects']);
    $marks = isset($_POST['marks']) ? $_POST['marks'] : array();
    $remarks = isset($_POST['remarks']) ? $_POST['remarks'] : array();
    $student_status = isset($_POST['student_status']) ? $_POST['student_status'] : array();
    $subject_name = $subj['subject_title'];

    /* Server-side validation */
    $invalid = array();
    foreach ($marks as $student_id => $rows) {
        $sid_int = (int)$student_id;
        if ($sid_int <= 0) continue;

        $status = isset($student_status[$student_id]) ? (int)$student_status[$student_id] : 0;
        if ($status === 1) continue;

        if ($is_composite) {
            foreach ($rows as $ss_id => $raw) {
                $ss_id_int = (int)$ss_id;
                if (!isset($subj['sub_subjects'][$ss_id_int])) continue;
                if ($raw === '' || $raw === null) continue;
                $v = (float)$raw;
                $max = (float)$subj['sub_subjects'][$ss_id_int]['max_marks'];
                if ($v < 0 || $v > $max) {
                    $invalid[] = 'Student ID ' . $sid_int . ', ' . $subj['sub_subjects'][$ss_id_int]['sub_subject_title'] . ': ' . $v . ' (max ' . $max . ')';
                }
            }
        } else {
            if (!isset($rows[0])) continue;
            $raw = $rows[0];
            if ($raw === '' || $raw === null) continue;
            $v = (float)$raw;
            $max = (float)$subj['max_marks'];
            if ($v < 0 || $v > $max) {
                $invalid[] = 'Student ID ' . $sid_int . ', ' . $subject_name . ': ' . $v . ' (max ' . $max . ')';
            }
        }
    }
    if (!empty($invalid)) {
        $_SESSION['message'] = 'Marks out of range: ' . implode('; ', array_slice($invalid, 0, 5)) . (count($invalid) > 5 ? ' and ' . (count($invalid) - 5) . ' more.' : '');
        $_SESSION['message_type'] = 'danger';
        header('Location: result_entry.php?exam_id=' . $arrange_exam_id . '&arr_sub_id=' . $arr_sub_id);
        exit;
    }

    $subject_class_id = 0;
    $q = $conn->query("SELECT id FROM subject_class WHERE class_id = " . (int)$class_id . " AND subject_id = " . (int)$subject_id . " LIMIT 1");
    if ($q && $r = $q->fetch_assoc()) $subject_class_id = (int)$r['id'];

    $conn->begin_transaction();
    try {
        $ins = $conn->prepare("INSERT INTO results (student_id, arrange_exam_id, subject_class_id, subject_name, subject_class_sub_id, sub_subject_name, marks, max_marks, status, remarks, arrange_exam_subject_id, arrange_exam_sub_subject_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $upd = $conn->prepare("UPDATE results SET marks = ?, max_marks = ?, status = ?, remarks = ?, arrange_exam_subject_id = ?, arrange_exam_sub_subject_id = ? WHERE student_id = ? AND arrange_exam_id = ? AND arrange_exam_subject_id = ? AND (arrange_exam_sub_subject_id IS NULL AND ? = 0 OR arrange_exam_sub_subject_id = ?)");

        $saved = 0;
        $zero_i = 0;

        foreach ($marks as $student_id => $rows) {
            $sid_int = (int)$student_id;
            if ($sid_int <= 0) continue;

            $student_remark = isset($remarks[$student_id]) ? trim($remarks[$student_id]) : '';
            $status = isset($student_status[$student_id]) ? (int)$student_status[$student_id] : 0;
            if ($status !== 0 && $status !== 1) $status = 0;

            if ($is_composite) {
                foreach ($rows as $ss_id => $raw_val) {
                    $ss_id_int = (int)$ss_id;
                    if (!isset($subj['sub_subjects'][$ss_id_int])) continue;

                    $ss_max = (float)$subj['sub_subjects'][$ss_id_int]['max_marks'];
                    $ss_title = $subj['sub_subjects'][$ss_id_int]['sub_subject_title'];
                    $val_num = ($status === 1 || $raw_val === '' || $raw_val === null) ? 0.0 : (float)$raw_val;

                    $arr_ss_id = 0;
                    $q = $conn->query("SELECT id FROM arrange_exam_sub_subjects WHERE arrange_exam_subject_id = " . (int)$post_arr_sub_id . " AND sub_subject_id = " . (int)$ss_id_int . " LIMIT 1");
                    if ($q && $r = $q->fetch_assoc()) $arr_ss_id = (int)$r['id'];

                    $sub_class_sub_id = 0;
                    $q = $conn->query("SELECT id FROM subject_class_sub WHERE subject_class_id = " . (int)$subject_class_id . " AND sub_subject_id = " . (int)$ss_id_int . " LIMIT 1");
                    if ($q && $r = $q->fetch_assoc()) $sub_class_sub_id = (int)$r['id'];

                    $upd->bind_param("ddisiiiiiii",
                        $val_num,
                        $ss_max,
                        $status,
                        $student_remark,
                        $post_arr_sub_id,
                        $arr_ss_id,
                        $sid_int,
                        $arrange_exam_id,
                        $post_arr_sub_id,
                        $ss_id_int,
                        $ss_id_int
                    );
                    $upd->execute();

                    if ($upd->affected_rows > 0) { $saved++; continue; }
                    if ($val_num == 0.0 && $student_remark === '' && $status === 0) continue;

                    $ins->bind_param("iiisisddisii",
                        $sid_int,
                        $arrange_exam_id,
                        $subject_class_id,
                        $subject_name,
                        $sub_class_sub_id,
                        $ss_title,
                        $val_num,
                        $ss_max,
                        $status,
                        $student_remark,
                        $post_arr_sub_id,
                        $arr_ss_id
                    );
                    $ins->execute();
                    $saved++;
                }
            } else {
                $raw_val = isset($rows[0]) ? $rows[0] : '';
                $max = (float)$subj['max_marks'];
                $val_num = ($status === 1 || $raw_val === '' || $raw_val === null) ? 0.0 : (float)$raw_val;

                $upd->bind_param("ddisiiiiiii",
                    $val_num,
                    $max,
                    $status,
                    $student_remark,
                    $post_arr_sub_id,
                    $zero_i,
                    $sid_int,
                    $arrange_exam_id,
                    $post_arr_sub_id,
                    $zero_i,
                    $zero_i
                );
                $upd->execute();

                if ($upd->affected_rows > 0) { $saved++; continue; }
                if ($val_num == 0.0 && $student_remark === '' && $status === 0) continue;

                $empty_ss_title = '';
                $ins->bind_param("iiisisddisii",
                    $sid_int,
                    $arrange_exam_id,
                    $subject_class_id,
                    $subject_name,
                    $zero_i,
                    $empty_ss_title,
                    $val_num,
                    $max,
                    $status,
                    $student_remark,
                    $post_arr_sub_id,
                    $zero_i
                );
                $ins->execute();
                $saved++;
            }
        }

        $ins->close();
        $upd->close();
        $conn->commit();
        $_SESSION['message'] = $saved . ' result row(s) saved.';
        $_SESSION['message_type'] = 'success';
    } catch (Throwable $e) {
        $conn->rollback();
        $_SESSION['message'] = 'Save failed: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
    header('Location: result_entry.php?exam_id=' . $arrange_exam_id . '&arr_sub_id=' . $arr_sub_id);
    exit;
}

/* ═══════════════════════════════════════════════════════════
   LOAD STUDENTS + EXISTING RESULTS
   ═══════════════════════════════════════════════════════════ */
$students = array();
$sqlStu = "SELECT sr.id, sr.name, sr.father_name, sr.reg_no FROM student_class sc INNER JOIN student_registration sr ON sr.id = sc.student_registration_id WHERE sc.class_id = " . (int)$class_id . " AND sc.session_id = " . (int)$session_id . " AND sc.status = 0 AND sr.status = 0 ORDER BY sr.name";
$q = $conn->query($sqlStu);
if ($q) while ($r = $q->fetch_assoc()) $students[] = $r;

$existing = array();
$student_exam_status = array();
if ($selected) {
    $sel_arr_sub_id = (int)$selected['arr_sub_id'];
    $sqlEx = "SELECT student_id, subject_class_sub_id, arrange_exam_sub_subject_id, marks, remarks, status FROM results WHERE arrange_exam_id = " . (int)$arrange_exam_id . " AND arrange_exam_subject_id = " . (int)$sel_arr_sub_id;
    $q = $conn->query($sqlEx);
    if ($q) {
        while ($r = $q->fetch_assoc()) {
            $st = (int)$r['student_id'];
            $ss = $r['arrange_exam_sub_subject_id'] !== null ? (int)$r['arrange_exam_sub_subject_id'] : 0;
            $existing[$st][$ss] = array(
                'marks' => $r['marks'],
                'remarks' => $r['remarks'],
            );
            if ((int)$r['status'] === 1) $student_exam_status[$st] = 1;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Result Entry</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #eef1f5; font-family: 'Inter', sans-serif; color: #1e293b; }
    .wrap { max-width: 1400px; margin: 30px auto; padding: 0 20px; }
    .head { background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); color: #fff; padding: 24px 30px; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: flex-end; gap: 20px; flex-wrap: wrap; }
    .head h1 { font-size: 22px; font-weight: 700; }
    .head .sub { font-size: 13px; opacity: 0.9; margin-top: 6px; }
    .head .hb { display: inline-flex; align-items: center; gap: 6px; padding: 9px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; text-decoration: none; cursor: pointer; border: 1px solid rgba(255,255,255,0.3); background: rgba(255,255,255,0.15); color: #fff; font-family: inherit; margin-left: 6px; }
    .head .hb:hover { background: #fff; color: #1e40af; border-color: #fff; text-decoration: none; }
    .card { background: #fff; padding: 20px 26px 26px 26px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
    .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; border-left: 4px solid; }
    .alert.success { background: #d1fae5; border-color: #059669; color: #065f46; }
    .alert.danger  { background: #fee2e2; border-color: #dc2626; color: #991b1b; }
    .alert.warning { background: #fef3c7; border-color: #d97706; color: #92400e; }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 42px; padding: 0 20px; border-radius: 10px; font-size: 13px; font-weight: 700; font-family: inherit; cursor: pointer; border: 1px solid; text-decoration: none; }
    .btn-primary { background: #1e40af; color: #fff; border-color: #1e40af; }
    .btn-primary:hover { background: #1e3a8a; color: #fff; text-decoration: none; }
    .btn-success { background: #059669; color: #fff; border-color: #059669; }
    .btn-success:hover { background: #047857; color: #fff; text-decoration: none; }
    .btn-ghost { background: #fff; color: #334155; border-color: #cbd5e1; }
    .btn-ghost:hover { background: #f1f5f9; color: #1e293b; text-decoration: none; }
    .subject-picker { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 18px; padding-bottom: 16px; border-bottom: 1px solid #e2e8f0; }
    .subj-tab { padding: 10px 16px; border-radius: 10px; font-size: 13px; font-weight: 700; border: 1px solid #cbd5e1; background: #fff; color: #334155; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
    .subj-tab:hover { text-decoration: none; color: #1e40af; border-color: #1e40af; }
    .subj-tab.active { background: #1e40af; color: #fff; border-color: #1e40af; }
    .subj-tab.active:hover { color: #fff; }
    .info-bar { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; display: flex; gap: 24px; flex-wrap: wrap; font-size: 13px; }
    .info-bar strong { color: #1e40af; }
    .tbl-wrap { overflow-x: auto; border-radius: 10px; border: 1px solid #e2e8f0; }
    table.res { width: 100%; border-collapse: collapse; }
    table.res thead th { background: #1e3a8a; color: #fff; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 10px 8px; text-align: center; border-right: 1px solid rgba(255,255,255,0.15); }
    table.res thead th.name-head { text-align: left; min-width: 220px; }
    table.res thead th.status-head { background: #7c3aed; }
    table.res thead th.total-head { background: #059669; }
    table.res tbody td { padding: 8px; font-size: 13px; border-right: 1px solid #f1f5f9; border-bottom: 1px solid #f1f5f9; vertical-align: middle; text-align: center; background: #fff; }
    table.res tbody td.student-cell { text-align: left; font-weight: 600; background: #f8fafc; }
    table.res tbody td.student-cell small { display: block; font-size: 11px; color: #64748b; font-weight: 400; margin-top: 2px; }
    table.res tbody td.total-cell { font-weight: 700; color: #065f46; background: #f0fdf4; font-size: 14px; }
    table.res tbody tr.absent-row td { background: #fef2f2; }
    table.res tbody tr:hover td { background: #f8fafc; }
    table.res tbody tr:hover td.student-cell { background: #f1f5f9; }
    .mark-input { width: 76px; height: 36px; padding: 0 8px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; font-family: inherit; font-weight: 600; text-align: center; background: #fff; }
    .mark-input:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 3px rgba(30,64,175,0.15); }
    .mark-input.over { border-color: #dc2626; background: #fef2f2; animation: shake 0.4s; }
    @keyframes shake {
        0%,100% { transform: translateX(0); }
        25% { transform: translateX(-3px); }
        75% { transform: translateX(3px); }
    }
    .mark-input:disabled { background: #f1f5f9; color: #94a3b8; }
    .status-select { height: 36px; padding: 0 8px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 12px; font-family: inherit; font-weight: 600; background: #fff; cursor: pointer; }
    .status-select.present { background: #d1fae5; color: #065f46; border-color: #6ee7b7; }
    .status-select.absent { background: #fee2e2; color: #991b1b; border-color: #fca5a5; }
    .remark-input { width: 180px; height: 36px; padding: 0 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 12px; font-family: inherit; text-align: left; background: #fff; }
    .remark-input:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 3px rgba(30,64,175,0.15); }
    .actions { display: flex; justify-content: flex-end; gap: 10px; padding-top: 18px; border-top: 1px solid #e2e8f0; margin-top: 18px; }
    .empty { padding: 60px 20px; text-align: center; color: #94a3b8; }
    .empty .big { font-size: 44px; opacity: 0.4; margin-bottom: 10px; }
    .empty h3 { font-size: 17px; color: #475569; font-weight: 600; }
    .pill { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; }
    .pill.term { background: #dbeafe; color: #1e40af; }
    .pill.test { background: #fef3c7; color: #92400e; }
    .bulk-row { display: flex; gap: 8px; align-items: center; padding: 0 0 14px 0; flex-wrap: wrap; }
    .bulk-row label { font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 1px; }
    @media print {
        @page { margin: 10mm; size: A4 landscape; }
        body * { visibility: hidden !important; }
        body { background: #fff; }
        .print-area, .print-area * { visibility: visible !important; }
        .print-area { position: absolute; left: 0; top: 0; width: 100%; }
        .head, .actions, .subject-picker, .no-print, .bulk-row { display: none !important; }
        .card { box-shadow: none; padding: 0; }
        .mark-input, .remark-input, .status-select { border: none; background: transparent; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">
    <div class="head no-print">
        <div>
            <h1><i class="fas fa-pen-to-square"></i> Result Entry</h1>
            <div class="sub">
                <span class="pill <?= htmlspecialchars($arr['exam_kind']) ?>"><?= htmlspecialchars($arr['exam_type_title']) ?></span>
                &nbsp; <strong><?= htmlspecialchars($arr['class_title']) ?></strong>
                &nbsp;&middot;&nbsp; <?= date('d M Y', strtotime($arr['start_date'])) ?>
                <?php if (!empty($arr['session_title'])): ?>
                    &nbsp;&middot;&nbsp; <?= htmlspecialchars($arr['session_title']) ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="no-print">
            <a href="result_entry.php" class="hb"><i class="fas fa-arrow-left"></i> Back to List</a>
        </div>
    </div>

    <div class="card">
        <div class="print-area">

        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert <?= htmlspecialchars($_SESSION['message_type']) ?> no-print"><?= htmlspecialchars($_SESSION['message']) ?></div>
            <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
        <?php endif; ?>

        <?php if (empty($arrSubjects)): ?>
            <div class="empty">
                <div class="big">&#128221;</div>
                <h3>No subjects assigned to this arrangement</h3>
                <p style="margin-top:12px;">
                    <a href="arrange_exam.php?edit=<?= (int)$arrange_exam_id ?>" style="color:#1e40af;font-weight:700;">Assign subjects first</a>
                </p>
            </div>
        <?php elseif (empty($students)): ?>
            <div class="empty">
                <div class="big">&#128101;</div>
                <h3>No students enrolled in this class for this session</h3>
            </div>
        <?php else: ?>

            <div class="subject-picker no-print">
                <?php foreach ($arrSubjects as $asid => $as): ?>
                    <a href="?exam_id=<?= (int)$arrange_exam_id ?>&arr_sub_id=<?= (int)$asid ?>"
                       class="subj-tab <?= (int)$asid === $arr_sub_id ? 'active' : '' ?>">
                        <i class="fas fa-book"></i>
                        <?= htmlspecialchars($as['subject_title']) ?>
                        <span style="font-size:11px;opacity:0.75;">(<?= (int)round($as['max_marks']) ?>)</span>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ($selected): ?>
                <?php
                $is_composite = !empty($selected['sub_subjects']);
                $total_max = 0;
                if ($is_composite) {
                    foreach ($selected['sub_subjects'] as $ss) $total_max += (float)$ss['max_marks'];
                } else {
                    $total_max = (float)$selected['max_marks'];
                }
                ?>

                <div class="info-bar no-print">
                    <div>Subject: <strong><?= htmlspecialchars($selected['subject_title']) ?></strong></div>
                    <div><?= $is_composite ? 'Composite' : 'Standalone' ?> &middot; Max: <strong><?= number_format($total_max, 0) ?></strong></div>
                    <div><strong><?= count($students) ?></strong> student(s)</div>
                </div>

                <form method="post" id="resultsForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="save_results">
                    <input type="hidden" name="arr_sub_id" value="<?= (int)$arr_sub_id ?>">

                    <div class="bulk-row no-print">
                        <label>Bulk:</label>
                        <button type="button" class="btn btn-ghost" style="height:32px;padding:0 12px;font-size:12px;" onclick="setAllStatus(0)"><i class="fas fa-check"></i> All Present</button>
                        <button type="button" class="btn btn-ghost" style="height:32px;padding:0 12px;font-size:12px;color:#dc2626;border-color:#fca5a5;" onclick="setAllStatus(1)"><i class="fas fa-times"></i> All Absent</button>
                    </div>

                    <div class="tbl-wrap">
                        <table class="res">
                            <thead>
                                <tr>
                                    <th class="name-head">Student</th>
                                    <th class="status-head" style="width:120px;">Status</th>
                                    <?php if ($is_composite): ?>
                                        <?php foreach ($selected['sub_subjects'] as $ssId => $ss): ?>
                                            <th>
                                                <?= htmlspecialchars($ss['sub_subject_title']) ?>
                                                <div style="font-size:9px;opacity:0.75;font-weight:500;">(Max <?= (int)round($ss['max_marks']) ?>)</div>
                                            </th>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <th>
                                            Obtained
                                            <div style="font-size:9px;opacity:0.75;font-weight:500;">(Max <?= (int)round($selected['max_marks']) ?>)</div>
                                        </th>
                                    <?php endif; ?>
                                    <th class="total-head">Total</th>
                                    <th style="min-width:200px;">Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($students as $st):
                                $stId = (int)$st['id'];
                                $row_remark = '';
                                if (isset($existing[$stId][0]['remarks'])) $row_remark = $existing[$stId][0]['remarks'];
                                if ($row_remark === '' && $is_composite) {
                                    foreach ($selected['sub_subjects'] as $ssId => $ss) {
                                        if (isset($existing[$stId][(int)$ssId]['remarks']) && $existing[$stId][(int)$ssId]['remarks'] !== '') {
                                            $row_remark = $existing[$stId][(int)$ssId]['remarks'];
                                            break;
                                        }
                                    }
                                }
                                $cur_status = isset($student_exam_status[$stId]) ? 1 : 0;
                            ?>
                                <tr data-student-id="<?= $stId ?>" class="<?= $cur_status === 1 ? 'absent-row' : '' ?>">
                                    <td class="student-cell">
                                        <?= htmlspecialchars($st['name']) ?>
                                        <small><?= htmlspecialchars($st['father_name'] ?: '') ?><?= !empty($st['reg_no']) ? ' &middot; ' . htmlspecialchars($st['reg_no']) : '' ?></small>
                                    </td>
                                    <td>
                                        <select name="student_status[<?= $stId ?>]" class="status-select <?= $cur_status === 1 ? 'absent' : 'present' ?>">
                                            <option value="0" <?= $cur_status === 0 ? 'selected' : '' ?>>Present</option>
                                            <option value="1" <?= $cur_status === 1 ? 'selected' : '' ?>>Absent</option>
                                        </select>
                                    </td>
                                    <?php if ($is_composite): ?>
                                        <?php foreach ($selected['sub_subjects'] as $ssId => $ss):
                                            $cur = isset($existing[$stId][(int)$ssId]['marks']) ? $existing[$stId][(int)$ssId]['marks'] : '';
                                        ?>
                                            <td>
                                                <input type="number"
                                                       name="marks[<?= $stId ?>][<?= (int)$ssId ?>]"
                                                       class="mark-input js-mark"
                                                       data-max="<?= (float)$ss['max_marks'] ?>"
                                                       min="0"
                                                       max="<?= (float)$ss['max_marks'] ?>"
                                                       step="0.5"
                                                       value="<?= htmlspecialchars((string)$cur) ?>"
                                                       <?= $cur_status === 1 ? 'disabled' : '' ?>>
                                            </td>
                                        <?php endforeach; ?>
                                    <?php else:
                                        $cur = isset($existing[$stId][0]['marks']) ? $existing[$stId][0]['marks'] : '';
                                    ?>
                                        <td>
                                            <input type="number"
                                                   name="marks[<?= $stId ?>][0]"
                                                   class="mark-input js-mark"
                                                   data-max="<?= (float)$selected['max_marks'] ?>"
                                                   min="0"
                                                   max="<?= (float)$selected['max_marks'] ?>"
                                                   step="0.5"
                                                   value="<?= htmlspecialchars((string)$cur) ?>"
                                                   <?= $cur_status === 1 ? 'disabled' : '' ?>>
                                        </td>
                                    <?php endif; ?>
                                    <td class="total-cell js-total">0</td>
                                    <td>
                                        <input type="text"
                                               name="remarks[<?= $stId ?>]"
                                               class="remark-input"
                                               maxlength="150"
                                               value="<?= htmlspecialchars($row_remark) ?>"
                                               placeholder="Optional remark">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="actions no-print">
                        <?php if (!empty($perms['can_add']) || !empty($perms['can_edit'])): ?>
                            <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Save Results</button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-ghost" onclick="window.print();"><i class="fas fa-print"></i> Print</button>
                    </div>
                </form>

                <script>
                function clampInput(inp){
                    if (inp.disabled) { inp.classList.remove('over'); return 0; }
                    var max = parseFloat(inp.getAttribute('data-max') || 0);
                    var v = parseFloat(inp.value);
                    if (isNaN(v)) { inp.classList.remove('over'); return 0; }
                    if (v < 0) { v = 0; inp.value = 0; inp.classList.add('over'); var t1 = inp; setTimeout(function(){ t1.classList.remove('over'); }, 700); }
                    if (v > max) {
                        v = max;
                        inp.value = max;
                        inp.classList.add('over');
                        var t2 = inp;
                        setTimeout(function(){ t2.classList.remove('over'); }, 700);
                    } else {
                        inp.classList.remove('over');
                    }
                    return v;
                }
                function updateRow(tr){
                    var inputs = tr.querySelectorAll('.js-mark');
                    var total = 0;
                    inputs.forEach(function(inp){ total += clampInput(inp); });
                    var cell = tr.querySelector('.js-total');
                    if (cell) cell.textContent = total ? total.toFixed(1).replace(/\.0$/, '') : '0';
                }
                function applyAbsent(tr){
                    var statusSel = tr.querySelector('.status-select');
                    var marks = tr.querySelectorAll('.js-mark');
                    if (statusSel.value === '1') {
                        tr.classList.add('absent-row');
                        marks.forEach(function(inp){
                            if (!inp.disabled) {
                                inp.dataset.prev = inp.value;
                                inp.value = '';
                                inp.disabled = true;
                            }
                        });
                    } else {
                        tr.classList.remove('absent-row');
                        marks.forEach(function(inp){
                            inp.disabled = false;
                            if (inp.dataset.prev !== undefined && inp.value === '') {
                                inp.value = inp.dataset.prev;
                            }
                        });
                    }
                    updateRow(tr);
                }
                document.querySelectorAll('table.res tbody tr').forEach(function(tr){
                    tr.querySelectorAll('.js-mark').forEach(function(inp){
                        inp.addEventListener('input', function(){
                            var max = parseFloat(this.getAttribute('data-max') || 0);
                            var v = parseFloat(this.value);
                            if (!isNaN(v)) {
                                if (v > max) {
                                    this.value = max;
                                    this.classList.add('over');
                                    var that = this;
                                    setTimeout(function(){ that.classList.remove('over'); }, 700);
                                } else if (v < 0) {
                                    this.value = 0;
                                    this.classList.add('over');
                                    var that2 = this;
                                    setTimeout(function(){ that2.classList.remove('over'); }, 700);
                                } else {
                                    this.classList.remove('over');
                                }
                            }
                            updateRow(tr);
                        });
                        inp.addEventListener('blur', function(){ updateRow(tr); });
                    });
                    var statusSel = tr.querySelector('.status-select');
                    if (statusSel) {
                        statusSel.addEventListener('change', function(){
                            this.classList.remove('present','absent');
                            this.classList.add(this.value === '1' ? 'absent' : 'present');
                            applyAbsent(tr);
                        });
                    }
                    updateRow(tr);
                });
                function setAllStatus(v){
                    document.querySelectorAll('table.res tbody tr').forEach(function(tr){
                        var statusSel = tr.querySelector('.status-select');
                        if (!statusSel) return;
                        statusSel.value = String(v);
                        statusSel.classList.remove('present','absent');
                        statusSel.classList.add(v === 1 ? 'absent' : 'present');
                        applyAbsent(tr);
                    });
                }
                document.getElementById('resultsForm').addEventListener('submit', function(e){
                    var bad = false;
                    document.querySelectorAll('.js-mark').forEach(function(inp){
                        if (inp.disabled) return;
                        var max = parseFloat(inp.getAttribute('data-max') || 0);
                        var v = parseFloat(inp.value);
                        if (isNaN(v)) return;
                        if (v < 0 || v > max) { bad = true; inp.classList.add('over'); }
                    });
                    if (bad) {
                        e.preventDefault();
                        alert('Some marks exceed the subject max. Fix the red fields and try again.');
                    }
                });
                </script>
            <?php endif; ?>

        <?php endif; ?>

        </div><!-- /.print-area -->
    </div>
</div>

</body>
</html>
<?php $conn->close(); ob_end_flush(); ?>