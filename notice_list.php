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
require_once('notice_helper.php');

$csrf = $_SESSION['csrf_token'];
$is_parent = notice_user_is_parent($conn);
$uid = (int)$_SESSION['user_id'];

/* Mark all as read */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_read') {
    if (($_POST['csrf_token'] ?? '') === $csrf) {
        $audience_sql = notice_audience_sql_for_user($conn);
        $active_sql = notice_active_sql();
        $sql = "INSERT IGNORE INTO notice_reads (notice_id, user_id)
                SELECT n.id, $uid FROM notices n
                WHERE $active_sql AND $audience_sql
                  AND NOT EXISTS (SELECT 1 FROM notice_reads r WHERE r.notice_id = n.id AND r.user_id = $uid)";
        $conn->query($sql);
        $_SESSION['message'] = 'All notices marked as read.';
        $_SESSION['message_type'] = 'success';
    }
    header('Location: notice_list.php'); exit;
}

/* Filters */
$f_search   = trim($_GET['q'] ?? '');
$f_audience = in_array($_GET['audience'] ?? '', array('all','parents','staff','class','student'), true) ? $_GET['audience'] : '';
$f_priority = in_array($_GET['priority'] ?? '', array('normal','high','urgent'), true) ? $_GET['priority'] : '';
$f_from     = trim($_GET['date_from'] ?? '');
$f_to       = trim($_GET['date_to'] ?? '');
$PAGE  = max(1, (int)($_GET['page'] ?? 1));
$LIMIT = 20;
$OFFSET = ($PAGE - 1) * $LIMIT;

/* Visible scope */
$where = array(notice_active_sql());
$where[] = notice_audience_sql_for_user($conn);

/* Show archived to admins only */
$show_archived = (!$is_parent && isset($_GET['show_archived']) && $_GET['show_archived'] == '1');
if ($show_archived && !empty($perms['can_edit'])) {
    $where[0] = "n.status IN (0,1)";
}

if ($f_search !== '') {
    $s = $conn->real_escape_string($f_search);
    $where[] = "(n.title LIKE '%$s%' OR n.body LIKE '%$s%')";
}
if ($f_audience !== '') $where[] = "n.audience = '" . $conn->real_escape_string($f_audience) . "'";
if ($f_priority !== '') $where[] = "n.priority = '" . $conn->real_escape_string($f_priority) . "'";
if ($f_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_from)) $where[] = "n.publish_from >= '" . $conn->real_escape_string($f_from) . " 00:00:00'";
if ($f_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_to))     $where[] = "n.publish_from <= '" . $conn->real_escape_string($f_to) . " 23:59:59'";

$where_sql = implode(' AND ', $where);

/* Count */
$total = 0;
$q = $conn->query("SELECT COUNT(*) AS c FROM notices n WHERE $where_sql");
if ($q) $total = (int)$q->fetch_assoc()['c'];
$total_pages = max(1, (int)ceil($total / $LIMIT));

/* Fetch */
$rows = array();
$sql = "SELECT n.*, u.username AS author,
        (SELECT COUNT(*) FROM notice_reads r WHERE r.notice_id = n.id AND r.user_id = $uid) AS is_read
        FROM notices n LEFT JOIN users u ON u.id = n.created_by
        WHERE $where_sql
        ORDER BY n.publish_from DESC, n.id DESC
        LIMIT $LIMIT OFFSET $OFFSET";
$q = $conn->query($sql);
if ($q) while ($r = $q->fetch_assoc()) $rows[] = $r;

$qstr = function($overrides = array()) {
    $base = array(
        'q'             => $_GET['q']             ?? '',
        'audience'      => $_GET['audience']      ?? '',
        'priority'      => $_GET['priority']      ?? '',
        'date_from'     => $_GET['date_from']     ?? '',
        'date_to'       => $_GET['date_to']       ?? '',
        'show_archived' => $_GET['show_archived'] ?? '',
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
<title>All Notices</title>
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
    .head .hb { display: inline-flex; align-items: center; gap: 6px; padding: 9px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; text-decoration: none; cursor: pointer; border: 1px solid rgba(255,255,255,0.3); background: rgba(255,255,255,0.15); color: #fff; font-family: inherit; margin-left: 6px; }
    .head .hb:hover { background: #fff; color: #1e40af; border-color: #fff; text-decoration: none; }
    .card { background: #fff; padding: 24px 28px 30px 28px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
    .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; border-left: 4px solid; }
    .alert.success { background: #d1fae5; border-color: #059669; color: #065f46; }
    .alert.danger  { background: #fee2e2; border-color: #dc2626; color: #991b1b; }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; height: 40px; padding: 0 16px; border-radius: 10px; font-size: 13px; font-weight: 700; font-family: inherit; cursor: pointer; border: 1px solid; text-decoration: none; }
    .btn-primary { background: #1e40af; color: #fff; border-color: #1e40af; }
    .btn-primary:hover { background: #1e3a8a; color: #fff; text-decoration: none; }
    .btn-ghost { background: #fff; color: #334155; border-color: #cbd5e1; }
    .btn-ghost:hover { background: #f1f5f9; color: #1e293b; text-decoration: none; }
    .btn-success { background: #059669; color: #fff; border-color: #059669; }
    .btn-success:hover { background: #047857; color: #fff; text-decoration: none; }
    .btn-danger { background: #dc2626; color: #fff; border-color: #dc2626; }
    .btn-danger:hover { background: #b91c1c; color: #fff; text-decoration: none; }
    .btn-warning { background: #d97706; color: #fff; border-color: #d97706; }
    .btn-warning:hover { background: #b45309; color: #fff; text-decoration: none; }
    .btn-sm { height: 32px; padding: 0 12px; font-size: 12px; }
    .filter-panel { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px 18px; margin-bottom: 18px; }
    .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
    .field label { display: block; font-size: 11px; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px; }
    .field select, .field input { width: 100%; height: 40px; padding: 0 12px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 13px; font-family: inherit; background: #fff; }
    .field select:focus, .field input:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 4px rgba(30,64,175,0.1); }
    .filter-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 12px; padding-top: 12px; border-top: 1px solid #e2e8f0; flex-wrap: wrap; }
    .list-head { font-size: 12px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 2px; margin: 0 0 12px 0; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
    .list-head .count { font-size: 11px; color: #94a3b8; font-weight: 600; letter-spacing: 0; text-transform: none; }
    table.g { width: 100%; border-collapse: collapse; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
    table.g thead th { background: #1e3a8a; color: #fff; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 12px; text-align: left; }
    table.g tbody td { padding: 12px; font-size: 13px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    table.g tbody tr:last-child td { border-bottom: none; }
    table.g tbody tr:hover td { background: #f8fafc; }
    table.g tbody tr.unread td { background: #eff6ff; }
    table.g tbody tr.unread td:first-child { border-left: 4px solid #1e40af; }
    table.g tbody tr.archived td { opacity: 0.55; }
    .np-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; }
    .np-urgent { background: #fee2e2; color: #991b1b; }
    .np-high { background: #fef3c7; color: #92400e; }
    .np-normal { background: #dbeafe; color: #1e40af; }
    .aud { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; background: #f1f5f9; color: #475569; }
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
        <div>
            <h1><i class="fas fa-bullhorn"></i> All Notices</h1>
            <div class="sub">
                <?= number_format($total) ?> notice(s)
                <?php if ($total_pages > 1): ?> · page <?= $PAGE ?> of <?= $total_pages ?><?php endif; ?>
            </div>
        </div>
        <div>
            <?php if (!$is_parent && (!empty($perms['can_add']) || !empty($perms['can_edit']))): ?>
                <a href="notice_add.php" class="hb"><i class="fas fa-plus"></i> New Notice</a>
            <?php endif; ?>
            <a href="notice_hub.php" class="hb"><i class="fas fa-arrow-left"></i> Hub</a>
        </div>
    </div>

    <div class="card">

        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert <?= htmlspecialchars($_SESSION['message_type']) ?>"><?= htmlspecialchars($_SESSION['message']) ?></div>
            <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
        <?php endif; ?>

        <form method="post" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="mark_all_read">
            <button type="submit" class="btn btn-sm btn-ghost" style="margin-bottom:14px;">
                <i class="fas fa-check-double"></i> Mark all as read
            </button>
        </form>

        <form method="get" class="filter-panel">
            <div class="filter-grid">
                <div class="field">
                    <label>Search</label>
                    <input type="text" name="q" value="<?= htmlspecialchars($f_search) ?>" placeholder="Title or body...">
                </div>
                <?php if (!$is_parent): ?>
                    <div class="field">
                        <label>Audience</label>
                        <select name="audience" onchange="this.form.submit()">
                            <option value="">All Audiences</option>
                            <option value="all"     <?= $f_audience === 'all'     ? 'selected' : '' ?>>Everyone</option>
                            <option value="parents" <?= $f_audience === 'parents' ? 'selected' : '' ?>>Parents</option>
                            <option value="staff"   <?= $f_audience === 'staff'   ? 'selected' : '' ?>>Staff</option>
                            <option value="class"   <?= $f_audience === 'class'   ? 'selected' : '' ?>>Specific Class</option>
                            <option value="student" <?= $f_audience === 'student' ? 'selected' : '' ?>>Specific Student</option>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="field">
                    <label>Priority</label>
                    <select name="priority" onchange="this.form.submit()">
                        <option value="">Any Priority</option>
                        <option value="urgent" <?= $f_priority === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                        <option value="high"   <?= $f_priority === 'high'   ? 'selected' : '' ?>>High</option>
                        <option value="normal" <?= $f_priority === 'normal' ? 'selected' : '' ?>>Normal</option>
                    </select>
                </div>
                <div class="field">
                    <label>From Date</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($f_from) ?>">
                </div>
                <div class="field">
                    <label>To Date</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($f_to) ?>">
                </div>
            </div>
            <div class="filter-actions">
                <a href="notice_list.php" class="btn btn-sm btn-ghost"><i class="fas fa-times"></i> Clear</a>
                <?php if (!$is_parent && !empty($perms['can_edit'])): ?>
                    <?php if ($show_archived): ?>
                        <a href="?<?= $qstr(['show_archived' => '']) ?>" class="btn btn-sm btn-ghost"><i class="fas fa-eye-slash"></i> Hide Archived</a>
                    <?php else: ?>
                        <a href="?<?= $qstr(['show_archived' => '1']) ?>" class="btn btn-sm btn-ghost"><i class="fas fa-archive"></i> Show Archived</a>
                    <?php endif; ?>
                <?php endif; ?>
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-search"></i> Apply</button>
            </div>
        </form>

        <?php if (empty($rows)): ?>
            <div class="empty">
                <div class="big">&#128226;</div>
                <h3>No notices match your filters</h3>
            </div>
        <?php else: ?>
            <table class="g">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th style="width:130px;">Audience</th>
                        <th style="width:110px;">Priority</th>
                        <th style="width:170px;">Published</th>
                        <th style="width:140px;">By</th>
                        <th style="width:230px; text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $unread = ((int)$r['is_read'] === 0);
                    $archived = ((int)$r['status'] === 1);
                    $view_href = 'notice_view.php?id=' . (int)$r['id'];
                ?>
                    <tr class="<?= $unread ? 'unread' : '' ?> <?= $archived ? 'archived' : '' ?>">
                        <td>
                            <a href="<?= $view_href ?>" style="color:#0f172a;font-weight:<?= $unread ? '800' : '600' ?>;text-decoration:none;">
                                <?= htmlspecialchars($r['title']) ?>
                            </a>
                            <?php if ($unread): ?><span style="color:#1e40af;font-size:11px;font-weight:800;margin-left:6px;">• NEW</span><?php endif; ?>
                            <?php if ($archived): ?><span style="color:#94a3b8;font-size:11px;font-weight:800;margin-left:6px;">• ARCHIVED</span><?php endif; ?>
                        </td>
                        <td><span class="aud"><?= htmlspecialchars(notice_audience_label($r)) ?></span></td>
                        <td><?= notice_priority_badge($r['priority']) ?></td>
                        <td><?= date('d M Y · h:i A', strtotime($r['publish_from'])) ?></td>
                        <td><?= htmlspecialchars($r['author'] ?? '—') ?></td>
                        <td style="text-align:right; white-space:nowrap;">
                            <a href="<?= $view_href ?>" class="btn btn-sm btn-ghost"><i class="fas fa-eye"></i></a>
                            <?php if (!$is_parent && !empty($perms['can_edit'])): ?>
                                <a href="notice_edit.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-warning" style="margin-left:4px;"><i class="fas fa-pencil"></i></a>
                            <?php endif; ?>
                            <?php if (!$is_parent && !empty($perms['can_delete'])): ?>
                                <a href="notice_delete.php?id=<?= (int)$r['id'] ?>&csrf_token=<?= urlencode($csrf) ?>"
                                   class="btn btn-sm btn-danger" style="margin-left:4px;"
                                   onclick="return confirm('Archive this notice?');">
                                    <i class="fas fa-archive"></i>
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
                    if ($start > 1) { echo '<a href="?' . $qstr(['page' => 1]) . '">1</a>'; if ($start > 2) echo '<span class="dots">...</span>'; }
                    for ($p = $start; $p <= $end; $p++) {
                        if ($p === $PAGE) echo '<span class="current">' . $p . '</span>';
                        else echo '<a href="?' . $qstr(['page' => $p]) . '">' . $p . '</a>';
                    }
                    if ($end < $total_pages) { if ($end < $total_pages - 1) echo '<span class="dots">...</span>'; echo '<a href="?' . $qstr(['page' => $total_pages]) . '">' . $total_pages . '</a>'; }
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