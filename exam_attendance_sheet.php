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
   PICKER — filtered + paginated when no exam_id
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

    /* Dropdown data */
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

    /* Build where */
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

    /* Count */
    $total_rows = 0;
    $q = $conn->query("SELECT COUNT(*) AS c FROM arrange_exam ae INNER JOIN classes c ON c.id = ae.class_id INNER JOIN exam_types et ON et.id = ae.exam_type_id WHERE $where_sql");
    if ($q) $total_rows = (int)$q->fetch_assoc()['c'];
    $total_pages = max(1, (int)ceil($total_rows / $LIMIT));

    /* Fetch page */
    $picker_rows = array();
    $sql = "SELECT ae.id, ae.start_date, ae.exam_kind, ae.session_id, ae.class_id, c.title AS class_title, et.title AS exam_type_title, (SELECT COUNT(*) FROM arrange_exam_subjects WHERE arrange_exam_id = ae.id) AS subject_count, (SELECT COUNT(*) FROM exam_datesheet WHERE arrange_exam_id = ae.id) AS datesheet_count FROM arrange_exam ae INNER JOIN classes c ON c.id = ae.class_id INNER JOIN exam_types et ON et.id = ae.exam_type_id WHERE $where_sql ORDER BY ae.start_date DESC, ae.id DESC LIMIT $LIMIT OFFSET $OFFSET";
    $q = $conn->query($sql);
    if ($q) while ($r = $q->fetch_assoc()) $picker_rows[] = $r;

    /* Query string helper */
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
    <title>Exam Attendance - Pick an Arrangement</title>
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
            <h1><i class="fas fa-clipboard-check"></i> Exam Attendance</h1>
            <div class="sub">Filter and pick an arrangement to mark per-subject attendance</div>
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
                    <a href="exam_attendance_sheet.php" class="btn btn-ghost"><i class="fas fa-times"></i> Clear</a>
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
                    <div class="big">&#128203;</div>
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
                            <th style="text-align:center;">Datesheet</th>
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
                                <?php if ((int)$r['datesheet_count'] > 0): ?>
                                    <span class="pill term"><?= (int)$r['datesheet_count'] ?></span>
                                <?php else: ?>
                                    <span class="pill muted">empty</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;">
                                <a href="exam_attendance_sheet.php?exam_id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-primary">
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
    header('Location: exam_attendance_sheet.php');
    exit;
}

$class_id = (int)$arr['class_id'];
$session_id = (int)$arr['session_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_attendance') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf) {
        $_SESSION['message'] = 'Invalid request token.';
        $_SESSION['message_type'] = 'danger';
        header('Location: exam_attendance_sheet.php?exam_id=' . $arrange_exam_id);
        exit;
    }

    $attendance = isset($_POST['att']) ? $_POST['att'] : array();
    $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

    $conn->begin_transaction();
    try {
        $sqlIns = "INSERT INTO exam_attendance (arrange_exam_id, arrange_exam_subject_id, arrange_exam_sub_subject_id, student_id, exam_date, status, marked_by) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status), marked_by = VALUES(marked_by), marked_at = CURRENT_TIMESTAMP()";
        $ins = $conn->prepare($sqlIns);

        $catalog = array();
        $sqlCat = "SELECT aes.id AS arr_sub_id, aes.subject_id, ed.start_date FROM arrange_exam_subjects aes INNER JOIN exam_datesheet ed ON ed.subject_id = aes.subject_id AND ed.arrange_exam_id = aes.arrange_exam_id AND (ed.sub_subject_id IS NULL OR ed.sub_subject_id = 0) WHERE aes.arrange_exam_id = " . (int)$arrange_exam_id;
        $q = $conn->query($sqlCat);
        if ($q) while ($r = $q->fetch_assoc()) $catalog[(int)$r['arr_sub_id']] = $r;

        $subCatalog = array();
        $sqlSub = "SELECT aes.arrange_exam_subject_id, aes.sub_subject_id, ed.start_date FROM arrange_exam_sub_subjects aes INNER JOIN arrange_exam_subjects a ON a.id = aes.arrange_exam_subject_id INNER JOIN exam_datesheet ed ON ed.subject_id = a.subject_id AND ed.sub_subject_id = aes.sub_subject_id AND ed.arrange_exam_id = a.arrange_exam_id WHERE a.arrange_exam_id = " . (int)$arrange_exam_id;
        $q = $conn->query($sqlSub);
        if ($q) while ($r = $q->fetch_assoc()) {
            $subCatalog[(int)$r['arrange_exam_subject_id']][(int)$r['sub_subject_id']] = $r['start_date'];
        }

        $saved = 0;
        foreach ($attendance as $key => $status) {
            $parts = explode(':', $key);
            if (count($parts) !== 3) continue;
            $arrSubId = (int)$parts[0];
            $ssId = (int)$parts[1];
            $studentId = (int)$parts[2];

            if ($arrSubId <= 0 || $studentId <= 0) continue;
            if (!isset($catalog[$arrSubId])) continue;

            $status = in_array($status, array('P', 'A', 'L'), true) ? $status : 'P';

            if ($ssId > 0) {
                if (!isset($subCatalog[$arrSubId][$ssId])) continue;
                $examDate = $subCatalog[$arrSubId][$ssId];
                $ssInsert = $ssId;
            } else {
                $examDate = $catalog[$arrSubId]['start_date'];
                $ssInsert = null;
            }

            if (empty($examDate)) $examDate = $arr['start_date'];

            $ins->bind_param("iiiissi", $arrange_exam_id, $arrSubId, $ssInsert, $studentId, $examDate, $status, $uid);
            if (!$ins->execute()) throw new Exception('Insert failed: ' . $ins->error);
            $saved++;
        }

        $ins->close();
        $conn->commit();
        $_SESSION['message'] = $saved . ' attendance record(s) saved.';
        $_SESSION['message_type'] = 'success';
    } catch (Throwable $e) {
        $conn->rollback();
        $_SESSION['message'] = 'Save failed: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
    header('Location: exam_attendance_sheet.php?exam_id=' . $arrange_exam_id);
    exit;
}

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

$students = array();
$sqlStu = "SELECT sr.id, sr.name, sr.father_name, sr.reg_no FROM student_class sc INNER JOIN student_registration sr ON sr.id = sc.student_registration_id WHERE sc.class_id = " . (int)$class_id . " AND sc.session_id = " . (int)$session_id . " AND sc.status = 0 AND sr.status = 0 ORDER BY sr.name";
$q = $conn->query($sqlStu);
if ($q) while ($r = $q->fetch_assoc()) $students[] = $r;

$existing = array();
$sqlEx = "SELECT arrange_exam_subject_id, arrange_exam_sub_subject_id, student_id, status FROM exam_attendance WHERE arrange_exam_id = " . (int)$arrange_exam_id;
$q = $conn->query($sqlEx);
if ($q) {
    while ($r = $q->fetch_assoc()) {
        $asid = (int)$r['arrange_exam_subject_id'];
        $ss = $r['arrange_exam_sub_subject_id'] !== null ? (int)$r['arrange_exam_sub_subject_id'] : 0;
        $st = (int)$r['student_id'];
        $existing[$asid][$ss][$st] = $r['status'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Exam Attendance</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #eef1f5; font-family: 'Inter', sans-serif; color: #1e293b; }
    .wrap { max-width: 100%; margin: 20px auto; padding: 0 20px; }
    .head { background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); color: #fff; padding: 24px 30px; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: flex-end; gap: 20px; flex-wrap: wrap; }
    .head h1 { font-size: 22px; font-weight: 700; }
    .head .sub { font-size: 13px; opacity: 0.9; margin-top: 6px; }
    .head .hb { display: inline-flex; align-items: center; gap: 6px; padding: 9px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; text-decoration: none; cursor: pointer; border: 1px solid rgba(255,255,255,0.3); background: rgba(255,255,255,0.15); color: #fff; font-family: inherit; margin-left: 6px; }
    .head .hb:hover { background: #fff; color: #1e40af; border-color: #fff; text-decoration: none; }
    .card { background: #fff; padding: 20px 26px 26px 26px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
    .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; border-left: 4px solid; }
    .alert.success { background: #d1fae5; border-color: #059669; color: #065f46; }
    .alert.danger  { background: #fee2e2; border-color: #dc2626; color: #991b1b; }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 42px; padding: 0 20px; border-radius: 10px; font-size: 13px; font-weight: 700; font-family: inherit; cursor: pointer; border: 1px solid; text-decoration: none; }
    .btn-success { background: #059669; color: #fff; border-color: #059669; }
    .btn-success:hover { background: #047857; color: #fff; text-decoration: none; }
    .btn-ghost { background: #fff; color: #334155; border-color: #cbd5e1; }
    .btn-ghost:hover { background: #f1f5f9; color: #1e293b; text-decoration: none; }
    .btn-sm { height: 32px; padding: 0 12px; font-size: 11px; }
    .tbl-wrap { overflow-x: auto; border-radius: 10px; border: 1px solid #e2e8f0; }
    table.att { border-collapse: separate; border-spacing: 0; min-width: 100%; }
    table.att thead th { background: #1e3a8a; color: #fff; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 10px 8px; text-align: center; border-right: 1px solid rgba(255,255,255,0.15); position: sticky; top: 0; z-index: 3; white-space: nowrap; }
    table.att thead th.student-head { text-align: left; background: #1e3a8a; position: sticky; left: 0; z-index: 4; min-width: 220px; }
    table.att thead th.subj-head { background: #059669; }
    table.att thead th.ss-head { background: #0891b2; font-size: 9px; }
    table.att tbody td { padding: 8px 8px; font-size: 12px; border-right: 1px solid #f1f5f9; border-bottom: 1px solid #f1f5f9; text-align: center; background: #fff; }
    table.att tbody td.student-cell { text-align: left; background: #f8fafc; font-weight: 600; position: sticky; left: 0; z-index: 2; }
    table.att tbody tr:hover td { background: #f8fafc; }
    table.att tbody tr:hover td.student-cell { background: #f1f5f9; }
    .att-select { width: 54px; height: 32px; padding: 0 4px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; font-family: inherit; font-weight: 700; text-align: center; background: #fff; cursor: pointer; }
    .att-select.p { background: #d1fae5; color: #065f46; border-color: #6ee7b7; }
    .att-select.a { background: #fee2e2; color: #991b1b; border-color: #fca5a5; }
    .att-select.l { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
    .att-select:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 3px rgba(30,64,175,0.15); }
    .bulk-row { display: flex; gap: 8px; align-items: center; padding: 12px 0 18px 0; flex-wrap: wrap; border-bottom: 1px solid #e2e8f0; margin-bottom: 14px; }
    .bulk-row label { font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 1px; }
    .empty { padding: 60px 20px; text-align: center; color: #94a3b8; }
    .empty .big { font-size: 44px; opacity: 0.4; margin-bottom: 10px; }
    .empty h3 { font-size: 17px; color: #475569; font-weight: 600; }
    .actions { display: flex; justify-content: flex-end; gap: 10px; padding-top: 18px; border-top: 1px solid #e2e8f0; margin-top: 18px; }
    .info-bar { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; display: flex; gap: 24px; flex-wrap: wrap; font-size: 13px; }
    .info-bar strong { color: #1e40af; }
    .pill { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; }
    .pill.term { background: #dbeafe; color: #1e40af; }
    .pill.test { background: #fef3c7; color: #92400e; }
    @media print {
        @page { margin: 10mm; size: A4 landscape; }
        body * { visibility: hidden !important; }
        body { background: #fff; }
        .print-area, .print-area * { visibility: visible !important; }
        .print-area { position: absolute; left: 0; top: 0; width: 100%; }
        .head, .actions, .bulk-row, .no-print { display: none !important; }
        .card { box-shadow: none; padding: 0; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">
    <div class="head no-print">
        <div>
            <h1><i class="fas fa-clipboard-check"></i> Exam Attendance</h1>
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
            <a href="exam_attendance_sheet.php" class="hb"><i class="fas fa-arrow-left"></i> Back to List</a>
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
                <div class="big">&#128203;</div>
                <h3>No subjects assigned to this arrangement</h3>
                <p style="margin-top:12px;">
                    <a href="arrange_exam.php?edit=<?= (int)$arrange_exam_id ?>" style="color:#1e40af;font-weight:700;">Assign subjects first</a>
                </p>
            </div>
        <?php elseif (empty($students)): ?>
            <div class="empty">
                <div class="big">&#128101;</div>
                <h3>No students enrolled in this class for this session</h3>
                <p style="margin-top:12px;font-size:13px;">
                    Class: <strong><?= htmlspecialchars($arr['class_title']) ?></strong>,
                    Session: <strong><?= htmlspecialchars($arr['session_title'] ?? '') ?></strong>
                </p>
            </div>
        <?php else: ?>

            <div class="info-bar no-print">
                <div><strong><?= count($students) ?></strong> student(s)</div>
                <div><strong><?= count($arrSubjects) ?></strong> subject(s)</div>
                <div>Legend: <span style="background:#d1fae5;padding:2px 8px;border-radius:6px;color:#065f46;font-weight:700;">P</span> Present &middot; <span style="background:#fee2e2;padding:2px 8px;border-radius:6px;color:#991b1b;font-weight:700;">A</span> Absent &middot; <span style="background:#fef3c7;padding:2px 8px;border-radius:6px;color:#92400e;font-weight:700;">L</span> Leave</div>
            </div>

            <form method="post" id="attForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="save_attendance">

                <div class="bulk-row no-print">
                    <label>Mark all:</label>
                    <button type="button" class="btn btn-sm btn-success" onclick="markAll('P')"><i class="fas fa-check"></i> Present</button>
                    <button type="button" class="btn btn-sm btn-ghost" onclick="markAll('A')" style="color:#dc2626;border-color:#fca5a5;"><i class="fas fa-times"></i> Absent</button>
                    <button type="button" class="btn btn-sm btn-ghost" onclick="markAll('L')" style="color:#d97706;border-color:#fcd34d;"><i class="fas fa-clock"></i> Leave</button>
                </div>

                <div class="tbl-wrap">
                    <table class="att">
                        <thead>
                            <tr>
                                <th class="student-head">Student</th>
                                <?php foreach ($arrSubjects as $arrSubId => $as): ?>
                                    <?php if (empty($as['sub_subjects'])): ?>
                                        <th class="subj-head" title="<?= htmlspecialchars($as['subject_title']) ?>">
                                            <?= htmlspecialchars(substr($as['subject_title'], 0, 14)) ?>
                                        </th>
                                    <?php else: ?>
                                        <?php foreach ($as['sub_subjects'] as $ssId => $ss): ?>
                                            <th class="ss-head" title="<?= htmlspecialchars($as['subject_title'] . ' - ' . $ss['sub_subject_title']) ?>">
                                                <?= htmlspecialchars(substr($ss['sub_subject_title'], 0, 12)) ?>
                                            </th>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($students as $st):
                            $stId = (int)$st['id'];
                        ?>
                            <tr>
                                <td class="student-cell">
                                    <?= htmlspecialchars($st['name']) ?>
                                    <div style="font-size:11px;color:#64748b;font-weight:400;margin-top:2px;">
                                        <?= htmlspecialchars($st['father_name'] ?: '') ?>
                                        <?php if (!empty($st['reg_no'])): ?> &middot; <?= htmlspecialchars($st['reg_no']) ?><?php endif; ?>
                                    </div>
                                </td>
                                <?php foreach ($arrSubjects as $arrSubId => $as):
                                    $sid = (int)$as['arr_sub_id'];
                                ?>
                                    <?php if (empty($as['sub_subjects'])):
                                        $key = $sid . ':0:' . $stId;
                                        $cur = isset($existing[$sid][0][$stId]) ? $existing[$sid][0][$stId] : 'P';
                                    ?>
                                        <td>
                                            <select name="att[<?= htmlspecialchars($key) ?>]" class="att-select <?= strtolower($cur) ?>">
                                                <option value="P" <?= $cur === 'P' ? 'selected' : '' ?>>P</option>
                                                <option value="A" <?= $cur === 'A' ? 'selected' : '' ?>>A</option>
                                                <option value="L" <?= $cur === 'L' ? 'selected' : '' ?>>L</option>
                                            </select>
                                        </td>
                                    <?php else: ?>
                                        <?php foreach ($as['sub_subjects'] as $ssId => $ss):
                                            $key = $sid . ':' . (int)$ssId . ':' . $stId;
                                            $cur = isset($existing[$sid][(int)$ssId][$stId]) ? $existing[$sid][(int)$ssId][$stId] : 'P';
                                        ?>
                                            <td>
                                                <select name="att[<?= htmlspecialchars($key) ?>]" class="att-select <?= strtolower($cur) ?>">
                                                    <option value="P" <?= $cur === 'P' ? 'selected' : '' ?>>P</option>
                                                    <option value="A" <?= $cur === 'A' ? 'selected' : '' ?>>A</option>
                                                    <option value="L" <?= $cur === 'L' ? 'selected' : '' ?>>L</option>
                                                </select>
                                            </td>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="actions no-print">
                    <?php if (!empty($perms['can_add']) || !empty($perms['can_edit'])): ?>
                        <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Save Attendance</button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-ghost" onclick="window.print();"><i class="fas fa-print"></i> Print</button>
                </div>
            </form>

            <script>
            document.querySelectorAll('.att-select').forEach(function(sel){
                sel.addEventListener('change', function(){
                    this.classList.remove('p','a','l');
                    this.classList.add(this.value.toLowerCase());
                });
            });
            function markAll(v){
                document.querySelectorAll('.att-select').forEach(function(sel){
                    sel.value = v;
                    sel.classList.remove('p','a','l');
                    sel.classList.add(v.toLowerCase());
                });
            }
            </script>

        <?php endif; ?>

        </div><!-- /.print-area -->
    </div>
</div>

</body>
</html>
<?php $conn->close(); ob_end_flush(); ?>