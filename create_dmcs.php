<?php
ob_start();
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

require_once('conn_inc.php');

$csrf = $_SESSION['csrf_token'];
$teacher_id = (int)($_SESSION['user_id'] ?? 0);

/* ═══════════════════════════════════════════════════════════
   ROLE
   ═══════════════════════════════════════════════════════════ */
$is_admin = false;
$stmt = $conn->prepare("SELECT r.title FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$rres = $stmt->get_result();
if ($rres && $r = $rres->fetch_assoc()) {
    $is_admin = (strcasecmp($r['title'], 'admin') === 0);
}
$stmt->close();

/* ═══════════════════════════════════════════════════════════
   FILTERS
   ═══════════════════════════════════════════════════════════ */
$f_session = (int)($_GET['session_id'] ?? 0);
$f_type    = (int)($_GET['exam_type_id'] ?? 0);
$f_class   = (int)($_GET['class_id'] ?? 0);
$f_kind    = in_array($_GET['kind'] ?? '', array('test','term'), true) ? $_GET['kind'] : '';
$f_search  = trim($_GET['q'] ?? '');
$f_sort    = in_array($_GET['sort'] ?? '', array('newest','oldest','class','type'), true) ? $_GET['sort'] : 'newest';

$PAGE   = max(1, (int)($_GET['page'] ?? 1));
$LIMIT  = 20;
$OFFSET = ($PAGE - 1) * $LIMIT;

/* ═══════════════════════════════════════════════════════════
   DROPDOWNS
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

/* Classes — scoped by teacher if not admin */
$classes = array();
if ($is_admin) {
    $q = $conn->query("SELECT id, title FROM classes WHERE status = 0 OR status IS NULL ORDER BY title");
    if ($q) while ($r = $q->fetch_assoc()) $classes[] = $r;
} else {
    $stmt = $conn->prepare("
        SELECT DISTINCT c.id, c.title
        FROM classes c
        INNER JOIN teacher_classes tc ON tc.class_id = c.id
        WHERE tc.teacher_id = ?
        ORDER BY c.title
    ");
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) $classes[] = $r;
    $stmt->close();
}

/* ═══════════════════════════════════════════════════════════
   WHERE + SORT
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
/* Non-admin scope */
if (!$is_admin) {
    $allowed_ids = array();
    foreach ($classes as $c) $allowed_ids[] = (int)$c['id'];
    if (empty($allowed_ids)) {
        $where[] = "0=1";
    } else {
        $where[] = "ae.class_id IN (" . implode(',', $allowed_ids) . ")";
    }
}
$where_sql = implode(' AND ', $where);

$order_sql = "ae.start_date DESC, ae.id DESC";
if ($f_sort === 'oldest') $order_sql = "ae.start_date ASC, ae.id ASC";
elseif ($f_sort === 'class') $order_sql = "c.title ASC, ae.start_date DESC";
elseif ($f_sort === 'type') $order_sql = "et.title ASC, ae.start_date DESC";

/* Count */
$total_rows = 0;
$q = $conn->query("SELECT COUNT(*) AS c FROM arrange_exam ae INNER JOIN classes c ON c.id = ae.class_id INNER JOIN exam_types et ON et.id = ae.exam_type_id WHERE $where_sql");
if ($q) $total_rows = (int)$q->fetch_assoc()['c'];
$total_pages = max(1, (int)ceil($total_rows / $LIMIT));

/* Fetch */
$exam_list = array();
$sql = "SELECT ae.id, ae.class_id, ae.exam_type_id, ae.start_date, ae.exam_kind, ae.session_id,
               c.title AS class_title, et.title AS exam_type_title,
               (SELECT COUNT(*) FROM arrange_exam_subjects WHERE arrange_exam_id = ae.id) AS subject_count,
               (SELECT COUNT(*) FROM results WHERE arrange_exam_id = ae.id) AS result_count
        FROM arrange_exam ae
        INNER JOIN classes c ON c.id = ae.class_id
        INNER JOIN exam_types et ON et.id = ae.exam_type_id
        WHERE $where_sql
        ORDER BY $order_sql
        LIMIT $LIMIT OFFSET $OFFSET";
$q = $conn->query($sql);
if ($q) while ($r = $q->fetch_assoc()) $exam_list[] = $r;

/* Query string helper */
$qstr = function($overrides = array()) {
    $base = array(
        'session_id'   => $_GET['session_id']   ?? '',
        'exam_type_id' => $_GET['exam_type_id'] ?? '',
        'class_id'     => $_GET['class_id']     ?? '',
        'kind'         => $_GET['kind']         ?? '',
        'q'            => $_GET['q']            ?? '',
        'sort'         => $_GET['sort']         ?? '',
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
<title>Create DMCs</title>
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
    .list-head { font-size: 12px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 2px; margin: 0 0 12px 0; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
    .list-head .count { font-size: 11px; color: #94a3b8; font-weight: 600; letter-spacing: 0; text-transform: none; }
    table.g { width: 100%; border-collapse: collapse; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
    table.g thead th { background: #1e3a8a; color: #fff; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 12px 14px; text-align: left; }
    table.g tbody td { padding: 12px 14px; font-size: 13px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    table.g tbody tr:last-child td { border-bottom: none; }
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
        <h1><i class="fas fa-award"></i> Create DMCs</h1>
        <div class="sub">
            <?php if ($is_admin): ?>
                All classes — pick an arrangement to start entering marks
            <?php else: ?>
                Your assigned classes only
            <?php endif; ?>
        </div>
    </div>

    <div class="card">

        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?= htmlspecialchars($_SESSION['message_type']) ?>" style="padding:12px 16px;border-radius:8px;margin-bottom:18px;border-left:4px solid;">
                <?= htmlspecialchars($_SESSION['message']) ?>
            </div>
            <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
        <?php endif; ?>

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
                    <label>Sort</label>
                    <select name="sort" onchange="this.form.submit()">
                        <option value="newest" <?= $f_sort === 'newest' ? 'selected' : '' ?>>Newest first</option>
                        <option value="oldest" <?= $f_sort === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
                        <option value="class"  <?= $f_sort === 'class'  ? 'selected' : '' ?>>By class</option>
                        <option value="type"   <?= $f_sort === 'type'   ? 'selected' : '' ?>>By exam type</option>
                    </select>
                </div>
                <div class="field">
                    <label>Search</label>
                    <input type="text" name="q" value="<?= htmlspecialchars($f_search) ?>" placeholder="Class or exam name...">
                </div>
            </div>
            <div class="filter-actions">
                <a href="create_dmcs.php" class="btn btn-ghost"><i class="fas fa-times"></i> Clear</a>
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

        <?php if (empty($exam_list)): ?>
            <div class="empty">
                <div class="big">&#128203;</div>
                <h3>No arrangements match your filters</h3>
                <p style="margin-top:8px;font-size:13px;">
                    <?php if (!$is_admin): ?>
                        Only exams for your assigned classes appear here.
                    <?php else: ?>
                        Try clearing filters or arrange a new exam.
                    <?php endif; ?>
                </p>
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
                <?php $i = $OFFSET + 1; foreach ($exam_list as $row): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><strong><?= htmlspecialchars($row['class_title']) ?></strong></td>
                        <td><span class="pill <?= htmlspecialchars($row['exam_kind']) ?>"><?= htmlspecialchars($row['exam_type_title']) ?></span></td>
                        <td><?= date('d M Y', strtotime($row['start_date'])) ?></td>
                        <td style="text-align:center;">
                            <?php if ((int)$row['subject_count'] > 0): ?>
                                <span class="pill term"><?= (int)$row['subject_count'] ?></span>
                            <?php else: ?>
                                <span class="pill muted">none</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <?php if ((int)$row['result_count'] > 0): ?>
                                <span class="pill term"><?= (int)$row['result_count'] ?></span>
                            <?php else: ?>
                                <span class="pill muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right; white-space:nowrap;">
                            <?php if (!empty($perms['can_add']) || !empty($perms['can_edit'])): ?>
                                <a href="result_entry.php?exam_id=<?= (int)$row['id'] ?>"
                                   class="btn btn-sm btn-primary"
                                   title="Enter or edit marks">
                                    <i class="fas fa-pen-to-square"></i> Enter Marks
                                </a>
                                <a href="dmc_list.php?arrange_exam_id=<?= (int)$row['id'] ?>"
                                   class="btn btn-sm btn-ghost"
                                   style="margin-left:4px;"
                                   title="View students and print DMCs">
                                    <i class="fas fa-file-lines"></i> DMC List
                                </a>
                            <?php endif; ?>
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
<?php $conn->close(); ob_end_flush(); ?>