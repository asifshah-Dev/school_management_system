<?php
/**
 * notification_bell.php
 * Standalone Notices bell. Outputs its own button + dropdown.
 * Requires: $conn (mysqli), session started, notice_helper.php available.
 */
if (!isset($conn) || !($conn instanceof mysqli)) return;
if (!isset($_SESSION['user_id'])) return;

require_once(__DIR__ . '/notice_helper.php');

$__nb_uid      = (int)$_SESSION['user_id'];
$__nb_unread   = notice_unread_count($conn);
$__nb_audience = notice_audience_sql_for_user($conn);
$__nb_active   = notice_active_sql();

$__nb_items = array();
$__nb_q = $conn->query("
    SELECT n.id, n.title, n.priority, n.publish_from, n.audience,
           u.username AS author,
           (SELECT COUNT(*) FROM notice_reads r WHERE r.notice_id = n.id AND r.user_id = $__nb_uid) AS is_read
    FROM notices n
    LEFT JOIN users u ON u.id = n.created_by
    WHERE $__nb_active AND $__nb_audience
    ORDER BY n.publish_from DESC, n.id DESC
    LIMIT 8
");
if ($__nb_q) while ($__nb_r = $__nb_q->fetch_assoc()) $__nb_items[] = $__nb_r;
?>
<div class="nb-wrap" id="nbNoticesWrap">
    <button type="button" class="nb-btn" id="nbNoticesBtn" aria-label="Notices" aria-expanded="false">
        <i class="fa-solid fa-bullhorn"></i>
        <?php if ($__nb_unread > 0): ?>
            <span class="nb-count"><?= $__nb_unread > 99 ? '99+' : (int)$__nb_unread ?></span>
        <?php endif; ?>
    </button>
    <div class="nb-dropdown" id="nbNoticesMenu" hidden>
        <div class="nb-head">
            <span>Notices<?= $__nb_unread > 0 ? ' · ' . (int)$__nb_unread . ' unread' : '' ?></span>
            <a href="notice_list.php">View all</a>
        </div>

        <?php if (empty($__nb_items)): ?>
            <div class="nb-empty">
                <i class="fa-regular fa-bell-slash"></i>
                <div>No notices yet</div>
            </div>
        <?php else: ?>
            <div class="nb-list">
                <?php foreach ($__nb_items as $it):
                    $unread = ((int)$it['is_read'] === 0);
                    $p = $it['priority'];
                ?>
                    <?php
$__nb_link_prefix = ((int)($_SESSION['role_id'] ?? 0) === 6)
    ? 'parent_notice_view.php?id='
    : 'notice_view.php?id=';
?>
<a class="nb-item <?= $unread ? 'nb-unread' : '' ?>"
   href="<?= $__nb_link_prefix . (int)$it['id'] ?>">
                        <span class="nb-icon nb-icon-<?= htmlspecialchars($p) ?>">
                            <i class="fa-solid fa-bullhorn"></i>
                        </span>
                        <span class="nb-body">
                            <span class="nb-title"><?= htmlspecialchars($it['title']) ?></span>
                            <span class="nb-meta">
                                <span><?= date('d M · H:i', strtotime($it['publish_from'])) ?></span>
                                <?php if ($p !== 'normal'): ?>
                                    <span class="nb-badge nb-badge-<?= htmlspecialchars($p) ?>"><?= htmlspecialchars(ucfirst($p)) ?></span>
                                <?php endif; ?>
                                <?php if ($unread): ?>
                                    <span class="nb-dot"></span>
                                <?php endif; ?>
                            </span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>