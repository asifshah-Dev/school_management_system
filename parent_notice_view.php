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

$notice_id = (int)($_GET['id'] ?? 0);
if ($notice_id <= 0) { header('Location: parent_dashboard.php'); exit; }

$audience_sql = notice_audience_sql_for_user($conn);
$active_sql   = notice_active_sql('n');

$stmt = $conn->prepare("
    SELECT n.*, u.username AS author
    FROM notices n
    LEFT JOIN users u ON u.id = n.created_by
    WHERE n.id = ? AND $active_sql AND $audience_sql
");
$stmt->bind_param("i", $notice_id);
$stmt->execute();
$notice = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$notice) {
    $_SESSION['message'] = 'Notice not available.';
    $_SESSION['message_type'] = 'danger';
    header('Location: parent_notices.php');
    exit;
}

/* Mark read — parents are never the author, so no skip needed */
$conn->query("INSERT IGNORE INTO notice_reads (notice_id, user_id) VALUES ($notice_id, $userId)");

$p = $notice['priority'];
$pr_class = ($p === 'urgent') ? 'pr-urgent' : (($p === 'high') ? 'pr-high' : 'pr-normal');
$pr_label = strtoupper($p);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($notice['title']) ?> — Notice</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #eef1f5; font-family: 'Inter', sans-serif; color: #1e293b; }
    .wrap { max-width: 720px; margin: 24px auto 40px; padding: 0 20px; }
    .card { background: #fff; border-radius: 16px; box-shadow: 0 2px 10px rgba(0,0,0,0.06); overflow: hidden; }
    .head { padding: 24px 26px 20px 26px; border-bottom: 1px solid #eef1f5; }
    .head .badges { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
    .badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 11px; border-radius: 20px; font-size: 11px; font-weight: 800; letter-spacing: 0.4px; text-transform: uppercase; }
    .pr-normal { background: #dbeafe; color: #1e40af; }
    .pr-high   { background: #fef3c7; color: #92400e; }
    .pr-urgent { background: #fee2e2; color: #991b1b; }
    .badge-date { background: #f1f5f9; color: #475569; }
    .head h1 { font-size: 22px; font-weight: 800; color: #0f172a; line-height: 1.3; letter-spacing: -0.02em; }
    .head .sub { font-size: 13px; color: #64748b; margin-top: 10px; display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
    .head .sub i { color: #94a3b8; margin-right: 3px; }
    .body { padding: 26px 26px 30px 26px; }
    .body-text { font-size: 15px; line-height: 1.8; color: #1e293b; white-space: pre-wrap; }
    .footer { padding: 16px 26px 22px 26px; border-top: 1px solid #eef1f5; background: #fafbfc; }
    .footer .meta { font-size: 12px; color: #94a3b8; }
    .back-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; gap: 10px; flex-wrap: wrap; }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 42px; padding: 0 20px; border-radius: 10px; font-size: 13px; font-weight: 700; font-family: inherit; cursor: pointer; border: 1px solid; text-decoration: none; }
    .btn-ghost { background: #fff; color: #334155; border-color: #cbd5e1; }
    .btn-ghost:hover { background: #f1f5f9; color: #1e293b; text-decoration: none; }
    .read-indicator { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 8px; background: #d1fae5; color: #065f46; font-size: 12px; font-weight: 700; }
    @media (max-width: 480px) {
        .wrap { padding: 0 12px; margin-top: 12px; }
        .head { padding: 20px 18px 16px; }
        .head h1 { font-size: 19px; }
        .body { padding: 20px 18px 24px; }
        .body-text { font-size: 14.5px; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">
    <div class="back-bar">
        <a href="parent_notices.php" class="btn btn-ghost">
            <i class="fas fa-arrow-left"></i> All Notices
        </a>
        <span class="read-indicator">
            <i class="fas fa-check-circle"></i> Marked as read
        </span>
    </div>

    <div class="card">
        <div class="head">
            <div class="badges">
                <span class="badge <?= $pr_class ?>">
                    <?php if ($p === 'urgent'): ?><i class="fas fa-circle-exclamation"></i><?php endif; ?>
                    <?php if ($p === 'high'): ?><i class="fas fa-arrow-up"></i><?php endif; ?>
                    <?= $pr_label ?>
                </span>
                <span class="badge badge-date">
                    <i class="fas fa-calendar"></i>
                    <?= date('d M Y', strtotime($notice['publish_from'])) ?>
                </span>
            </div>
            <h1><?= htmlspecialchars($notice['title']) ?></h1>
            <div class="sub">
                <span><i class="fas fa-user-tie"></i> <?= htmlspecialchars($notice['author'] ?? 'School Office') ?></span>
                <span><i class="fas fa-clock"></i> <?= date('h:i A', strtotime($notice['publish_from'])) ?></span>
            </div>
        </div>

        <div class="body">
            <div class="body-text"><?= nl2br(htmlspecialchars($notice['body'])) ?></div>
        </div>

        <?php if (!empty($notice['publish_to'])): ?>
        <div class="footer">
            <div class="meta">
                <i class="fas fa-hourglass-end"></i>
                This notice is valid until <?= date('d M Y', strtotime($notice['publish_to'])) ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
<?php $conn->close(); ob_end_flush(); ?>