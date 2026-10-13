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
   DELETE
   ═══════════════════════════════════════════════════════════ */
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && !empty($perms['can_delete'])) {
    $id = (int)$_GET['delete'];
    $conn->query("DELETE aes FROM arrange_exam_sub_subjects aes INNER JOIN arrange_exam_subjects a ON a.id = aes.arrange_exam_subject_id WHERE a.arrange_exam_id = " . $id);
    $conn->query("DELETE FROM arrange_exam_subjects WHERE arrange_exam_id = " . $id);

    $stmt = $conn->prepare("DELETE FROM arrange_exam WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $_SESSION['message'] = 'Arrangement and all its subjects deleted.';
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = 'Delete failed: ' . $stmt->error;
        $_SESSION['message_type'] = 'danger';
    }
    $stmt->close();
    header("Location: " . $_SERVER['PHP_SELF']); exit;
}

/* ═══════════════════════════════════════════════════════════
   FILTERS + PAGINATION
   ═══════════════════════════════════════════════════════════ */
$f_session = (int)($_GET['f_session'] ?? 0);
$f_class   = (int)($_GET['f_class']   ?? 0);
$f_type    = (int)($_GET['f_type']    ?? 0);
$f_search  = trim($_GET['f_q'] ?? '');
$filter_kind = in_array($_GET['kind'] ?? '', array('test','term'), true) ? $_GET['kind'] : '';
$show_previous = isset($_GET['show_previous']) && $_GET['show_previous'] == '1';

$PAGE   = max(1, (int)($_GET['page'] ?? 1));
$LIMIT  = 20;
$OFFSET = ($PAGE - 1) * $LIMIT;

$threshold_date = date('Y-m-d', strtotime('-15 days'));

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

/* WHERE */
$where = array("1=1");
if ($filter_kind !== '') $where[] = "ae.exam_kind = '" . $conn->real_escape_string($filter_kind) . "'";
if ($f_session > 0) $where[] = "ae.session_id = " . (int)$f_session;
if ($f_class   > 0) $where[] = "ae.class_id = "     . (int)$f_class;
if ($f_type    > 0) $where[] = "ae.exam_type_id = " . (int)$f_type;
if (!$show_previous) $where[] = "ae.start_date >= '" . $conn->real_escape_string($threshold_date) . "'";
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

/* Fetch */
$arrangements = array();
$sql = "SELECT ae.id, ae.class_id, ae.exam_type_id, ae.exam_kind, ae.start_date, ae.session_id, c.title AS class_title, et.title AS exam_type_title, (SELECT COUNT(*) FROM arrange_exam_subjects a WHERE a.arrange_exam_id = ae.id) AS subject_count, (SELECT COUNT(*) FROM exam_datesheet ed WHERE ed.arrange_exam_id = ae.id) AS datesheet_count FROM arrange_exam ae INNER JOIN classes c ON c.id = ae.class_id INNER JOIN exam_types et ON et.id = ae.exam_type_id WHERE $where_sql ORDER BY ae.start_date DESC, ae.id DESC LIMIT $LIMIT OFFSET $OFFSET";
$q = $conn->query($sql);
if ($q) while ($r = $q->fetch_assoc()) $arrangements[] = $r;

/* Prev count */
$prev_where = array();
if ($filter_kind !== '') $prev_where[] = "exam_kind = '" . $conn->real_escape_string($filter_kind) . "'";
if ($f_session > 0) $prev_where[] = "session_id = " . (int)$f_session;
if ($f_class   > 0) $prev_where[] = "class_id = "     . (int)$f_class;
if ($f_type    > 0) $prev_where[] = "exam_type_id = " . (int)$f_type;
$prev_where[] = "start_date < '" . $conn->real_escape_string($threshold_date) . "'";
$prev_count = 0;
$q = $conn->query("SELECT COUNT(*) AS c FROM arrange_exam WHERE " . implode(' AND ', $prev_where));
if ($q) $prev_count = (int)$q->fetch_assoc()['c'];

/* Query string helper */
$qstr = function($overrides = array()) {
    $base = array(
        'kind'          => $_GET['kind']          ?? '',
        'f_session'     => $_GET['f_session']     ?? '',
        'f_class'       => $_GET['f_class']       ?? '',
        'f_type'        => $_GET['f_type']        ?? '',
        'f_q'           => $_GET['f_q']           ?? '',
        'show_previous' => $_GET['show_previous'] ?? '',
        'page'          => $_GET['page']          ?? '',
    );
    foreach ($overrides as $k => $v) $base[$k] = $v;
    return http_build_query(array_filter($base, function($v){ return $v !== '' && $v !== null; }));
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>All Arrangements</title>
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

    .kind-tabs { display: flex; gap: 8px; margin-bottom: 18px; }
    .kind-tab { padding: 10px 20px; border-radius: 10px; font-size: 13px; font-weight: 700; text-decoration: none; border: 1px solid #cbd5e1; background: #fff; color: #334155; }
    .kind-tab.active { background: #1e40af; color: #fff; border-color: #1e40af; }
    .kind-tab:hover { text-decoration: none; color: #1e40af; border-color: #1e40af; }
    .kind-tab.active:hover { color: #fff; }

    .filter-panel { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px 18px; margin-bottom: 18px; }
    .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
    .field label { display: block; font-size: 11px; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px; }
    .field select, .field input { width: 100%; height: 40px; padding: 0 12px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 13px; font-family: inherit; background: #fff; }
    .field select:focus, .field input:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 4px rgba(30,64,175,0.1); }
    .filter-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 12px; padding-top: 12px; border-top: 1px solid #e2e8f0; flex-wrap: wrap; }

    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; height: 40px; padding: 0 16px; border-radius: 10px; font-size: 13px; font-weight: 700; font-family: inherit; cursor: pointer; border: 1px solid; text-decoration: none; }
    .btn-primary { background: #1e40af; color: #fff; border-color: #1e40af; }
    .btn-primary:hover { background: #1e3a8a; color: #fff; text-decoration: none; }
    .btn-success { background: #059669; color: #fff; border-color: #059669; }
    .btn-success:hover { background: #047857; color: #fff; text-decoration: none; }
    .btn-ghost { background: #fff; color: #334155; border-color: #cbd5e1; }
    .btn-ghost:hover { background: #f1f5f9; color: #1e293b; text-decoration: none; }
    .btn-danger { background: #dc2626; color: #fff; border-color: #dc2626; }
    .btn-danger:hover { background: #b91c1c; color: #fff; text-decoration: none; }
    .btn-sm { height: 34px; padding: 0 12px; font-size: 12px; }

    .list-head { font-size: 12px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 2px; margin: 0 0 12px 0; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
    .list-head .count { font-size: 11px; color: #94a3b8; font-weight: 600; letter-spacing: 0; text-transform: none; }

    table.g { width: 100%; border-collapse: collapse; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
    table.g thead th { background: #f8fafc; font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 12px; text-align: left; border-bottom: 2px solid #e2e8f0; }
    table.g tbody td { padding: 12px; font-size: 13px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    table.g tbody tr:last-child td { border-bottom: none; }
    table.g tbody tr:hover td { background: #f8fafc; }

    .pill { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; }
    .pill.test { background: #fef3c7; color: #92400e; }
    .pill.term { background: #dbeafe; color: #1e40af; }
    .pill.muted { background: #f1f5f9; color: #64748b; }

    .pagination { display: flex; justify-content: center; align-items: center; gap: 6px; margin-top: 20px; padding-top: 18px; border-top: 1px solid #e2e8f0; flex-wrap: wrap; }
    .pagination a, .pagination span { display: inline-flex; align-items: center; justify-content: center; min-width: 36px; height: 36px; padding: 0 10px; border-radius: 8px; font-size: 13px; font-weight: 700; text-decoration: none; border: 1px solid #cbd5e1; background: #fff; color: #334155; }
    .pagination a:hover { background: #eff6ff; border-color: #1e40af; color: #1e40af; text-decoration: none; }
    .pagination .current { background: #1e40af; color: #fff; border-color: #1e40af; }
    .pagination .disabled { opacity: 0.4; pointer-events: none; }
    .pagination .dots { border: none; background: transparent; color: #94a3b8; }

    .empty { padding: 60px 20px; text-align: center; color: #94a3b8; }
    .empty .big { font-size: 44px; opacity: 0.4; margin-bottom: 10px; }
    .empty h3 { font-size: 17px; color: #475569; font-weight: 600; margin-bottom: 8px; }
    .empty a { color: #1e40af; font-weight: 700; }

    .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; border-left: 4px solid; }
    .alert.success { background: #d1fae5; border-color: #059669; color: #065f46; }
    .alert.danger  { background: #fee2e2; border-color: #dc2626; color: #991b1b; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">
    <div class="head">
        <div>
            <h1><i class="fas fa-list"></i> All Arrangements</h1>
            <div class="sub">Filter and manage tests and term exams</div>
        </div>
        <div>
            <a href="arrange_exam.php" class="hb"><i class="fas fa-plus"></i> New Arrangement</a>
        </div>
    </div>

    <div class="card">

        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert <?= htmlspecialchars($_SESSION['message_type']) ?>"><?= htmlspecialchars($_SESSION['message']) ?></div>
            <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
        <?php endif; ?>

        <div class="kind-tabs">
            <a href="?kind=" class="kind-tab <?= $filter_kind === '' ? 'active' : '' ?>">
                <i class="fas fa-layer-group"></i> All
            </a>
            <a href="?kind=term" class="kind-tab <?= $filter_kind === 'term' ? 'active' : '' ?>">
                <i class="fas fa-graduation-cap"></i> Term Exams
            </a>
            <a href="?kind=test" class="kind-tab <?= $filter_kind === 'test' ? 'active' : '' ?>">
                <i class="fas fa-pen"></i> Tests
            </a>
        </div>

        <form method="get" class="filter-panel">
            <input type="hidden" name="kind" value="<?= htmlspecialchars($filter_kind) ?>">
            <div class="filter-grid">
                <div class="field">
                    <label>Session</label>
                    <select name="f_session" onchange="this.form.submit()">
                        <option value="0">All Sessions</option>
                        <?php foreach ($sessions as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $f_session ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Class</label>
                    <select name="f_class" onchange="this.form.submit()">
                        <option value="0">All Classes</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $f_class ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Exam Type</label>
                    <select name="f_type" onchange="this.form.submit()">
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
                    <label>Search</label>
                    <input type="text" name="f_q" value="<?= htmlspecialchars($f_search) ?>" placeholder="Class or exam...">
                </div>
            </div>
            <div class="filter-actions">
                <a href="arrange_exam_list.php" class="btn btn-sm btn-ghost"><i class="fas fa-times"></i> Clear</a>
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-search"></i> Apply</button>
                <?php if ($prev_count > 0): ?>
                    <?php if ($show_previous): ?>
                        <a href="?<?= $qstr(['show_previous' => '']) ?>" class="btn btn-sm btn-ghost"><i class="fas fa-eye-slash"></i> Hide Previous</a>
                    <?php else: ?>
                        <a href="?<?= $qstr(['show_previous' => '1']) ?>" class="btn btn-sm btn-ghost"><i class="fas fa-eye"></i> Show Previous (<?= $prev_count ?>)</a>
                    <?php endif; ?>
                <?php endif; ?>
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
                <div class="big">&#128197;</div>
                <h3>No arrangements match your filters</h3>
                <p style="margin-top:10px;">
                    <a href="arrange_exam.php"><i class="fas fa-plus"></i> Create a new arrangement</a>
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
                        <th style="text-align:center;">Datesheet</th>
                        <th>Status</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php $i = $OFFSET + 1; foreach ($arrangements as $a):
                    $isPrev = strtotime($a['start_date']) < strtotime($threshold_date);
                ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><strong><?= htmlspecialchars($a['class_title']) ?></strong></td>
                        <td><span class="pill <?= $a['exam_kind'] ?>"><?= htmlspecialchars($a['exam_type_title']) ?></span></td>
                        <td><?= date('d M Y', strtotime($a['start_date'])) ?></td>
                        <td style="text-align:center;">
                            <?php if ((int)$a['subject_count'] > 0): ?>
                                <span class="pill term"><?= (int)$a['subject_count'] ?></span>
                            <?php else: ?>
                                <span class="pill muted">none</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <?php if ((int)$a['datesheet_count'] > 0): ?>
                                <span class="pill term"><?= (int)$a['datesheet_count'] ?></span>
                            <?php else: ?>
                                <span class="pill muted">empty</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isPrev): ?>
                                <span class="pill muted">Previous</span>
                            <?php else: ?>
                                <span class="pill term">Upcoming</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right; white-space:nowrap;">
                            <a href="arrange_exam.php?edit=<?= (int)$a['id'] ?>" class="btn btn-sm btn-primary" title="Assign subjects">
                                <i class="fas fa-list-check"></i> Subjects
                            </a>
                            <a href="datesheet.php?exam_id=<?= (int)$a['id'] ?>" class="btn btn-sm btn-ghost" style="margin-left:4px;" title="Edit datesheet">
                                <i class="fas fa-calendar-days"></i> Datesheet
                            </a>
                            <a href="result_entry.php?exam_id=<?= (int)$a['id'] ?>" class="btn btn-sm btn-success" style="margin-left:4px;" title="Enter marks">
                                <i class="fas fa-pen-to-square"></i> Marks
                            </a>
                            <?php if (!empty($perms['can_delete'])): ?>
                                <a href="?delete=<?= (int)$a['id'] ?>" class="btn btn-sm btn-danger" style="margin-left:4px;"
                                   onclick="return confirm('Delete this arrangement and all its subject rows?');"
                                   title="Delete">
                                    <i class="fas fa-trash"></i>
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