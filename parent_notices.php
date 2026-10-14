<?php
ob_start();
header('Content-Type: text/html; charset=utf-8');
require_once('security.php');
require_once('permissions.php');
require_once('conn_inc.php');
require_once('notice_helper.php');

$roleId = (int)($_SESSION['role_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($roleId !== 6 && $roleId !== 1) { header('Location: 403.php'); exit(); }

$csrf = $_SESSION['csrf_token'] ?? '';

/* Filters */
$filter_status = in_array($_GET['status'] ?? '', ['all','unread','read'], true) ? $_GET['status'] : 'all';
$filter_priority = in_array($_GET['priority'] ?? '', ['normal','high','urgent'], true) ? $_GET['priority'] : '';
$filter_from = trim($_GET['date_from'] ?? '');
$filter_to   = trim($_GET['date_to'] ?? '');
$search      = trim($_GET['q'] ?? '');

$PAGE = max(1, (int)($_GET['page'] ?? 1));
$LIMIT = 20;
$OFFSET = ($PAGE - 1) * $LIMIT;

$audience_sql = notice_audience_sql_for_user($conn);
$active_sql = notice_active_sql();

$where = [$active_sql, $audience_sql];
if ($filter_priority !== '') $where[] = "n.priority = '" . $conn->real_escape_string($filter_priority) . "'";
if ($filter_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_from)) $where[] = "n.publish_from >= '" . $conn->real_escape_string($filter_from) . " 00:00:00'";
if ($filter_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_to)) $where[] = "n.publish_from <= '" . $conn->real_escape_string($filter_to) . " 23:59:59'";
if ($search !== '') {
    $s = $conn->real_escape_string($search);
    $where[] = "(n.title LIKE '%$s%' OR n.body LIKE '%$s%')";
}
if ($filter_status === 'unread') {
    $where[] = "NOT EXISTS (SELECT 1 FROM notice_reads r WHERE r.notice_id = n.id AND r.user_id = $userId)";
} elseif ($filter_status === 'read') {
    $where[] = "EXISTS (SELECT 1 FROM notice_reads r WHERE r.notice_id = n.id AND r.user_id = $userId)";
}
$where_sql = implode(' AND ', $where);

$total = 0;
$q = $conn->query("SELECT COUNT(*) c FROM notices n WHERE $where_sql");
if ($q) $total = (int)$q->fetch_assoc()['c'];
$total_pages = max(1, (int)ceil($total / $LIMIT));

$rows = [];
$sql = "SELECT n.id, n.title, n.body, n.priority, n.publish_from, n.publish_to, n.audience,
               u.username AS author,
               (SELECT COUNT(*) FROM notice_reads r WHERE r.notice_id = n.id AND r.user_id = $userId) AS is_read
        FROM notices n
        LEFT JOIN users u ON u.id = n.created_by
        WHERE $where_sql
        ORDER BY n.publish_from DESC, n.id DESC
        LIMIT $LIMIT OFFSET $OFFSET";
$q = $conn->query($sql);
if ($q) while ($r = $q->fetch_assoc()) $rows[] = $r;

/* Mark-all-read */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_read') {
    if (($_POST['csrf_token'] ?? '') === $csrf) {
        $conn->query("
            INSERT IGNORE INTO notice_reads (notice_id, user_id)
            SELECT n.id, $userId FROM notices n
            WHERE $active_sql AND $audience_sql
        ");
        $_SESSION['message'] = 'All notices marked as read.';
        $_SESSION['message_type'] = 'success';
    }
    header('Location: parent_notices.php'); exit;
}

$unread_total = notice_unread_count($conn);

$qstr = function($overrides = []) {
    $base = [
        'status'   => $_GET['status']   ?? '',
        'priority' => $_GET['priority'] ?? '',
        'date_from'=> $_GET['date_from']?? '',
        'date_to'  => $_GET['date_to']  ?? '',
        'q'        => $_GET['q']        ?? '',
        'page'     => $_GET['page']     ?? '',
    ];
    foreach ($overrides as $k => $v) $base[$k] = $v;
    return http_build_query(array_filter($base, fn($v) => $v !== '' && $v !== null));
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Notices — Dar-e-Arqm</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #eef1f5; font-family: 'Inter', sans-serif; color: #1e293b; }
    .wrap { max-width: 900px; margin: 24px auto 40px; padding: 0 20px; }
    .head { background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); color: #fff; padding: 24px 28px 22px 28px; border-radius: 14px; margin-bottom: 20px; }
    .head h1 { font-size: 24px; font-weight: 700; }
    .head .sub { font-size: 13px; opacity: 0.9; margin-top: 6px; }
    .head .count { display: inline-block; padding: 3px 10px; border-radius: 20px; background: rgba(255,255,255,0.2); font-size: 12px; font-weight: 700; margin-left: 8px; }

    .toolbar { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); padding: 16px 18px; margin-bottom: 18px; }
    .tabs { display: flex; gap: 6px; margin-bottom: 14px; flex-wrap: wrap; }
    .tab { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 10px; font-size: 13px; font-weight: 700; border: 1px solid #cbd5e1; background: #fff; color: #334155; text-decoration: none; }
    .tab:hover { text-decoration: none; color: #1e40af; border-color: #1e40af; }
    .tab.active { background: #1e40af; color: #fff; border-color: #1e40af; }
    .tab.active:hover { color: #fff; }
    .tab .badge { display: inline-block; min-width: 20px; padding: 1px 6px; border-radius: 20px; background: #dc2626; color: #fff; font-size: 10px; font-weight: 800; text-align: center; }
    .tab.active .badge { background: rgba(255,255,255,0.3); }

    .filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; }
    .filters label { display: block; font-size: 11px; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 5px; }
    .filters select, .filters input { width: 100%; height: 38px; padding: 0 10px; border: 1px solid #cbd5e1; border-radius: 9px; font-size: 13px; font-family: inherit; background: #fff; }
    .filters select:focus, .filters input:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 3px rgba(30,64,175,0.1); }
    .actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 12px; padding-top: 12px; border-top: 1px solid #e2e8f0; flex-wrap: wrap; }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; height: 38px; padding: 0 16px; border-radius: 10px; font-size: 13px; font-weight: 700; font-family: inherit; cursor: pointer; border: 1px solid; text-decoration: none; }
    .btn-primary { background: #1e40af; color: #fff; border-color: #1e40af; }
    .btn-primary:hover { background: #1e3a8a; color: #fff; text-decoration: none; }
    .btn-ghost { background: #fff; color: #334155; border-color: #cbd5e1; }
    .btn-ghost:hover { background: #f1f5f9; color: #1e293b; text-decoration: none; }

    .list { display: flex; flex-direction: column; gap: 12px; }
    .notice-card { display: flex; align-items: flex-start; gap: 14px; background: #fff; border-radius: 12px; padding: 18px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); text-decoration: none; color: inherit; border-left: 5px solid #e2e8f0; transition: transform 0.15s, box-shadow 0.15s; }
    .notice-card:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0,0,0,0.08); text-decoration: none; color: inherit; }
    .notice-card.unread { border-left-color: #1e40af; background: #f8fbff; }
    .notice-card.priority-high { border-left-color: #d97706; }
    .notice-card.priority-urgent { border-left-color: #dc2626; }
    .notice-icon { flex-shrink: 0; width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; }
    .notice-icon.normal { background: #dbeafe; color: #1e40af; }
    .notice-icon.high   { background: #fef3c7; color: #92400e; }
    .notice-icon.urgent { background: #fee2e2; color: #991b1b; }
    .notice-body { flex: 1; min-width: 0; }
    .notice-title { font-size: 16px; font-weight: 700; color: #0f172a; line-height: 1.35; margin-bottom: 4px; }
    .notice-card.unread .notice-title { font-weight: 800; }
    .notice-snippet { font-size: 13px; color: #64748b; line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; margin-bottom: 8px; }
    .notice-meta { display: flex; align-items: center; gap: 10px; font-size: 12px; color: #94a3b8; flex-wrap: wrap; }
    .notice-meta i { color: #cbd5e1; }
    .priority-tag { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 8px; font-size: 10px; font-weight: 800; text-transform: uppercase; }
    .priority-tag.high   { background: #fef3c7; color: #92400e; }
    .priority-tag.urgent { background: #fee2e2; color: #991b1b; }
    .priority-tag.normal { background: #dbeafe; color: #1e40af; }
    .unread-dot { width: 8px; height: 8px; border-radius: 50%; background: #3b6fe0; margin-top: 6px; flex-shrink: 0; }

    .pagination { display: flex; justify-content: center; gap: 6px; margin-top: 22px; flex-wrap: wrap; }
    .pagination a, .pagination span { display: inline-flex; align-items: center; justify-content: center; min-width: 36px; height: 36px; padding: 0 10px; border-radius: 8px; font-size: 13px; font-weight: 700; text-decoration: none; border: 1px solid #cbd5e1; background: #fff; color: #334155; }
    .pagination a:hover { background: #eff6ff; border-color: #1e40af; color: #1e40af; text-decoration: none; }
    .pagination .current { background: #1e40af; color: #fff; border-color: #1e40af; }
    .pagination .disabled { opacity: 0.4; pointer-events: none; }
    .pagination .dots { border: none; background: transparent; color: #94a3b8; }

    .empty { background: #fff; border-radius: 12px; padding: 60px 20px; text-align: center; color: #94a3b8; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .empty i { font-size: 44px; opacity: 0.4; margin-bottom: 12px; display: block; }
    .empty h3 { font-size: 17px; color: #475569; font-weight: 600; }

    .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; border-left: 4px solid; }
    .alert.success { background: #d1fae5; border-color: #059669; color: #065f46; }

    @media (max-width: 640px) {
        .wrap { padding: 0 12px; margin-top: 12px; }
        .head { padding: 20px 20px 18px; }
        .head h1 { font-size: 20px; }
        .notice-card { padding: 14px 16px; }
        .notice-title { font-size: 15px; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">
    <div class="head">
        <h1>📢 Notices &amp; Announcements</h1>
        <div class="sub">
            Updates from the school
            <?php if ($unread_total > 0): ?>
                <span class="count"><?= (int)$unread_total ?> unread</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert <?= htmlspecialchars($_SESSION['message_type']) ?>"><?= htmlspecialchars($_SESSION['message']) ?></div>
        <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
    <?php endif; ?>

    <div class="toolbar">
        <div class="tabs">
            <a href="?<?= $qstr(['status' => 'all',    'page' => 1]) ?>" class="tab <?= $filter_status === 'all' ? 'active' : '' ?>"><i class="fas fa-layer-group"></i> All</a>
            <a href="?<?= $qstr(['status' => 'unread', 'page' => 1]) ?>" class="tab <?= $filter_status === 'unread' ? 'active' : '' ?>">
                <i class="fas fa-circle"></i> Unread
                <?php if ($unread_total > 0): ?><span class="badge"><?= (int)$unread_total ?></span><?php endif; ?>
            </a>
            <a href="?<?= $qstr(['status' => 'read',   'page' => 1]) ?>" class="tab <?= $filter_status === 'read' ? 'active' : '' ?>"><i class="fas fa-check"></i> Read</a>
        </div>

        <form method="get">
            <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
            <div class="filters">
                <div>
                    <label>Priority</label>
                    <select name="priority" onchange="this.form.submit()">
                        <option value="">Any</option>
                        <option value="urgent" <?= $filter_priority === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                        <option value="high"   <?= $filter_priority === 'high'   ? 'selected' : '' ?>>High</option>
                        <option value="normal" <?= $filter_priority === 'normal' ? 'selected' : '' ?>>Normal</option>
                    </select>
                </div>
                <div>
                    <label>From</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($filter_from) ?>">
                </div>
                <div>
                    <label>To</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($filter_to) ?>">
                </div>
                <div>
                    <label>Search</label>
                    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Title or body…">
                </div>
            </div>
            <div class="actions">
                <a href="parent_notices.php" class="btn btn-ghost"><i class="fas fa-times"></i> Clear</a>
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Apply</button>
            </div>
        </form>
    </div>

    <?php if (empty($rows)): ?>
        <div class="empty">
            <i class="fa-regular fa-bell-slash"></i>
            <h3>No notices match your filters</h3>
            <p style="margin-top:8px;font-size:13px;">Try changing the filters above.</p>
        </div>
    <?php else: ?>
        <div class="list">
            <?php foreach ($rows as $r):
                $unread = ((int)$r['is_read'] === 0);
                $p = $r['priority'];
                $snippet = mb_substr(preg_replace('/\s+/', ' ', $r['body']), 0, 160);
            ?>
                <a class="notice-card <?= $unread ? 'unread' : '' ?> <?= $p !== 'normal' ? 'priority-' . $p : '' ?>"
                   href="parent_notice_view.php?id=<?= (int)$r['id'] ?>">
                    <span class="notice-icon <?= htmlspecialchars($p) ?>">
                        <i class="fas fa-bullhorn"></i>
                    </span>
                    <span class="notice-body">
                        <div class="notice-title"><?= htmlspecialchars($r['title']) ?></div>
                        <div class="notice-snippet"><?= htmlspecialchars($snippet) ?>…</div>
                        <div class="notice-meta">
                            <span><i class="fas fa-user-tie"></i> <?= htmlspecialchars($r['author'] ?? 'School Office') ?></span>
                            <span><i class="fas fa-clock"></i> <?= date('d M · h:i A', strtotime($r['publish_from'])) ?></span>
                            <?php if ($p !== 'normal'): ?>
                                <span class="priority-tag <?= htmlspecialchars($p) ?>">
                                    <?php if ($p === 'urgent'): ?><i class="fas fa-circle-exclamation"></i><?php endif; ?>
                                    <?= htmlspecialchars(ucfirst($p)) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </span>
                    <?php if ($unread): ?><span class="unread-dot"></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <a href="?<?= $qstr(['page' => 1]) ?>" class="<?= $PAGE <= 1 ? 'disabled' : '' ?>"><i class="fas fa-angles-left"></i></a>
                <a href="?<?= $qstr(['page' => max(1, $PAGE - 1)]) ?>" class="<?= $PAGE <= 1 ? 'disabled' : '' ?>"><i class="fas fa-angle-left"></i></a>
                <?php
                $start = max(1, $PAGE - 2);
                $end   = min($total_pages, $PAGE + 2);
                if ($start > 1) { echo '<a href="?' . $qstr(['page' => 1]) . '">1</a>'; if ($start > 2) echo '<span class="dots">…</span>'; }
                for ($p = $start; $p <= $end; $p++) {
                    if ($p === $PAGE) echo '<span class="current">' . $p . '</span>';
                    else echo '<a href="?' . $qstr(['page' => $p]) . '">' . $p . '</a>';
                }
                if ($end < $total_pages) { if ($end < $total_pages - 1) echo '<span class="dots">…</span>'; echo '<a href="?' . $qstr(['page' => $total_pages]) . '">' . $total_pages . '</a>'; }
                ?>
                <a href="?<?= $qstr(['page' => min($total_pages, $PAGE + 1)]) ?>" class="<?= $PAGE >= $total_pages ? 'disabled' : '' ?>"><i class="fas fa-angle-right"></i></a>
                <a href="?<?= $qstr(['page' => $total_pages]) ?>" class="<?= $PAGE >= $total_pages ? 'disabled' : '' ?>"><i class="fas fa-angles-right"></i></a>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

</body>
</html>
<?php $conn->close(); ob_end_flush(); ?>