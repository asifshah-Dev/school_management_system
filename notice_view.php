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

$uid = (int)$_SESSION['user_id'];
$notice_id = (int)($_GET['id'] ?? 0);
if ($notice_id <= 0) { header('Location: notice_list.php'); exit; }

/* Access check — respects audience scoping */
$audience_sql = notice_audience_sql_for_user($conn);
$stmt = $conn->prepare("SELECT n.*, u.username AS author FROM notices n LEFT JOIN users u ON u.id = n.created_by WHERE n.id = ? AND ($audience_sql)");
$stmt->bind_param("i", $notice_id);
$stmt->execute();
$notice = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$notice) { header('Location: notice_list.php'); exit; }

/* Mark as read — but skip if the viewer is the author */
if ((int)$notice['created_by'] !== $uid) {
    $conn->query("INSERT IGNORE INTO notice_reads (notice_id, user_id) VALUES ($notice_id, $uid)");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($notice['title']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #eef1f5; font-family: 'Inter', sans-serif; color: #1e293b; }
    .wrap { max-width: 800px; margin: 30px auto; padding: 0 20px; }
    .head { background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); color: #fff; padding: 26px 32px; border-radius: 12px 12px 0 0; }
    .head .priority { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 800; text-transform: uppercase; }
    .head .pr-urgent { background: #fee2e2; color: #991b1b; }
    .head .pr-high { background: #fef3c7; color: #92400e; }
    .head .pr-normal { background: #dbeafe; color: #1e40af; }
    .head h1 { font-size: 24px; font-weight: 700; margin-top: 12px; }
    .head .meta { font-size: 13px; opacity: 0.9; margin-top: 8px; }
    .card { background: #fff; padding: 30px 32px 34px 32px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
    .body { font-size: 15px; line-height: 1.75; color: #1e293b; white-space: pre-wrap; }
    .actions { display: flex; justify-content: space-between; gap: 10px; padding-top: 20px; border-top: 1px solid #e2e8f0; margin-top: 26px; }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 42px; padding: 0 20px; border-radius: 10px; font-size: 13px; font-weight: 700; font-family: inherit; cursor: pointer; border: 1px solid; text-decoration: none; }
    .btn-ghost { background: #fff; color: #334155; border-color: #cbd5e1; }
    .btn-ghost:hover { background: #f1f5f9; color: #1e293b; text-decoration: none; }
    .btn-warning { background: #d97706; color: #fff; border-color: #d97706; }
    .btn-warning:hover { background: #b45309; color: #fff; text-decoration: none; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">
    <div class="head">
        <?php
        $pr = $notice['priority'];
        $pr_class = ($pr === 'urgent') ? 'pr-urgent' : (($pr === 'high') ? 'pr-high' : 'pr-normal');
        ?>
        <span class="priority <?= $pr_class ?>"><?= htmlspecialchars(strtoupper($pr)) ?></span>
        <h1><?= htmlspecialchars($notice['title']) ?></h1>
        <div class="meta">
            <i class="fas fa-user"></i> <?= htmlspecialchars($notice['author'] ?? 'System') ?>
            &nbsp;·&nbsp;
            <i class="fas fa-clock"></i> <?= date('d M Y · h:i A', strtotime($notice['publish_from'])) ?>
            <?php if (!empty($notice['publish_to'])): ?>
                &nbsp;·&nbsp; Expires <?= date('d M Y', strtotime($notice['publish_to'])) ?>
            <?php endif; ?>
            &nbsp;·&nbsp;
            <i class="fas fa-users"></i> <?= htmlspecialchars(notice_audience_label($notice)) ?>
        </div>
    </div>

    <div class="card">
        <div class="body"><?= nl2br(htmlspecialchars($notice['body'])) ?></div>

        <div class="actions">
            <a href="notice_list.php" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back to Notices</a>
            <?php if (!notice_user_is_parent($conn) && !empty($perms['can_edit'])): ?>
                <a href="notice_edit.php?id=<?= (int)$notice['id'] ?>" class="btn btn-warning"><i class="fas fa-pencil"></i> Edit</a>
            <?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>
<?php $conn->close(); ob_end_flush(); ?>