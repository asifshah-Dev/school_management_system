<?php
ob_start();
header('Content-Type: text/html; charset=utf-8');
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
if (session_status() === PHP_SESSION_NONE) session_start();

require_once('conn_inc.php');
require_once('notice_helper.php');

$is_parent = notice_user_is_parent($conn);
$audience_sql = notice_audience_sql_for_user($conn);
$active_sql = notice_active_sql();

/* Counts */
$stats = array('total' => 0, 'unread' => 0, 'urgent' => 0, 'parents' => 0);
$q = $conn->query("SELECT COUNT(*) AS c FROM notices n WHERE $active_sql AND $audience_sql");
if ($q) $stats['total'] = (int)$q->fetch_assoc()['c'];
$stats['unread'] = notice_unread_count($conn);
$q = $conn->query("SELECT COUNT(*) AS c FROM notices n WHERE $active_sql AND $audience_sql AND n.priority = 'urgent'");
if ($q) $stats['urgent'] = (int)$q->fetch_assoc()['c'];
if (!$is_parent) {
    $q = $conn->query("SELECT COUNT(*) AS c FROM notices WHERE audience = 'parents' AND status = 0");
    if ($q) $stats['parents'] = (int)$q->fetch_assoc()['c'];
}

/* Recent */
$recent = array();
$sql = "SELECT n.id, n.title, n.audience, n.priority, n.publish_from, n.class_id, n.student_id, u.username AS author
        FROM notices n LEFT JOIN users u ON u.id = n.created_by
        WHERE $active_sql AND $audience_sql
        ORDER BY n.publish_from DESC, n.id DESC LIMIT 6";
$q = $conn->query($sql);
if ($q) while ($r = $q->fetch_assoc()) $recent[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Notices</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #eef1f5; font-family: 'Inter', sans-serif; color: #1e293b; }
    .wrap { max-width: 1400px; margin: 30px auto; padding: 0 20px; }
    .head { background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); color: #fff; padding: 30px 36px; border-radius: 12px 12px 0 0; }
    .head h1 { font-size: 28px; font-weight: 700; }
    .head .sub { font-size: 14px; opacity: 0.9; margin-top: 8px; }
    .card { background: #fff; padding: 30px 36px 36px 36px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
    .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 30px; }
    .stat { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px 20px; display: flex; align-items: center; gap: 14px; }
    .stat .icon { width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; }
    .stat .icon.blue   { background: #dbeafe; color: #1e40af; }
    .stat .icon.red    { background: #fee2e2; color: #dc2626; }
    .stat .icon.amber  { background: #fef3c7; color: #d97706; }
    .stat .icon.purple { background: #ede9fe; color: #7c3aed; }
    .stat .num { font-size: 26px; font-weight: 800; color: #0f172a; line-height: 1; }
    .stat .label { font-size: 12px; color: #64748b; margin-top: 4px; font-weight: 600; }
    .sec { font-size: 12px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 2px; margin: 24px 0 16px 0; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0; }
    .sec:first-child { margin-top: 0; }
    .tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
    .tile { display: flex; align-items: center; gap: 14px; padding: 18px 20px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; text-decoration: none; color: inherit; transition: all 0.15s; }
    .tile:hover { border-color: #1e40af; box-shadow: 0 4px 12px rgba(30,64,175,0.12); transform: translateY(-2px); text-decoration: none; color: inherit; }
    .tile .tile-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
    .tile .tile-body { min-width: 0; flex: 1; }
    .tile .tile-title { font-size: 14px; font-weight: 700; color: #0f172a; }
    .tile .tile-desc { font-size: 11px; color: #94a3b8; margin-top: 3px; }
    .tile .chev { color: #cbd5e1; font-size: 12px; flex-shrink: 0; }
    .tile:hover .chev { color: #1e40af; }
    table.recent { width: 100%; border-collapse: collapse; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; }
    table.recent thead th { background: #1e3a8a; color: #fff; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 12px; text-align: left; }
    table.recent tbody td { padding: 12px; font-size: 13px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    table.recent tbody tr:last-child td { border-bottom: none; }
    table.recent tbody tr:hover td { background: #f8fafc; }
    table.recent a { color: #1e40af; font-weight: 600; text-decoration: none; }
    table.recent a:hover { text-decoration: underline; }
    .np-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; }
    .np-urgent { background: #fee2e2; color: #991b1b; }
    .np-high { background: #fef3c7; color: #92400e; }
    .np-normal { background: #dbeafe; color: #1e40af; }
    .aud { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 700; background: #f1f5f9; color: #475569; text-transform: capitalize; }
    .empty { padding: 40px 20px; text-align: center; color: #94a3b8; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">
    <div class="head">
        <h1><i class="fas fa-bullhorn"></i> Notices &amp; Announcements</h1>
        <div class="sub"><?= $is_parent ? 'Read the latest notices from the school' : 'Broadcast announcements to parents, staff, or specific classes' ?></div>
    </div>

    <div class="card">

        <div class="stats">
            <div class="stat">
                <div class="icon blue"><i class="fas fa-envelope"></i></div>
                <div><div class="num"><?= (int)$stats['total'] ?></div><div class="label">Active Notices</div></div>
            </div>
            <div class="stat">
                <div class="icon red"><i class="fas fa-bell"></i></div>
                <div><div class="num"><?= (int)$stats['unread'] ?></div><div class="label">Unread</div></div>
            </div>
            <div class="stat">
                <div class="icon amber"><i class="fas fa-circle-exclamation"></i></div>
                <div><div class="num"><?= (int)$stats['urgent'] ?></div><div class="label">Urgent</div></div>
            </div>
            <?php if (!$is_parent): ?>
                <div class="stat">
                    <div class="icon purple"><i class="fas fa-users"></i></div>
                    <div><div class="num"><?= (int)$stats['parents'] ?></div><div class="label">To Parents</div></div>
                </div>
            <?php endif; ?>
        </div>

        <div class="sec">Quick Actions</div>
        <div class="tiles">
            <?php if (!$is_parent && (!empty($perms['can_add']) || !empty($perms['can_edit']))): ?>
                <a href="notice_add.php" class="tile">
                    <div class="tile-icon" style="background:#dbeafe;color:#1e40af;"><i class="fas fa-plus"></i></div>
                    <div class="tile-body"><div class="tile-title">New Notice</div><div class="tile-desc">Create and publish</div></div>
                    <i class="fas fa-chevron-right chev"></i>
                </a>
            <?php endif; ?>
            <a href="notice_list.php" class="tile">
                <div class="tile-icon" style="background:#d1fae5;color:#059669;"><i class="fas fa-list"></i></div>
                <div class="tile-body"><div class="tile-title">All Notices</div><div class="tile-desc">Filter, search, and manage</div></div>
                <i class="fas fa-chevron-right chev"></i>
            </a>
        </div>

        <div class="sec">Recent</div>
        <?php if (empty($recent)): ?>
            <div class="empty"><i class="fas fa-inbox" style="font-size:38px;opacity:0.4;display:block;margin-bottom:10px;"></i>No notices yet</div>
        <?php else: ?>
            <table class="recent">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th style="width:130px;">Audience</th>
                        <th style="width:110px;">Priority</th>
                        <th style="width:170px;">Published</th>
                        <th style="width:140px;">By</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recent as $r):
                    $href = 'notice_view.php?id=';
                ?>
                    <tr>
                        <td><a href="<?= $href . (int)$r['id'] ?>"><?= htmlspecialchars($r['title']) ?></a></td>
                        <td><span class="aud"><?= htmlspecialchars(notice_audience_label($r)) ?></span></td>
                        <td><?= notice_priority_badge($r['priority']) ?></td>
                        <td><?= date('d M Y · h:i A', strtotime($r['publish_from'])) ?></td>
                        <td><?= htmlspecialchars($r['author'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    </div>
</div>

</body>
</html>
<?php $conn->close(); ob_end_flush(); ?>