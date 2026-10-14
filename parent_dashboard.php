<?php
header('Content-Type: text/html; charset=utf-8');
require_once('security.php');
require_once('conn_inc.php');
require_once('permissions.php');

$roleId = (int)($_SESSION['role_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = ($roleId === 1);
$isParent = ($roleId === 6);

if (!$isParent && !$isAdmin) { header("Location: 403.php"); exit(); }

// Fetch linked children
$children = [];
if ($isParent) {
    $stmt = $conn->prepare("
        SELECT sr.id, sr.name, sr.image_path, sr.reg_no, sr.admission_no, sr.roll_no,
               sr.father_name, sr.status,
               ps.relationship, ps.is_primary,
               c.title  AS class_title,
               s.title  AS section_title,
               ses.title AS session_title
        FROM parent_students ps
        JOIN student_registration sr ON sr.id = ps.student_id
        LEFT JOIN (
            SELECT sc1.student_registration_id, sc1.class_id, sc1.class_section_id, sc1.session_id
            FROM student_class sc1
            INNER JOIN (
                SELECT student_registration_id, MAX(id) AS max_id
                FROM student_class GROUP BY student_registration_id
            ) sc2 ON sc1.id = sc2.max_id
        ) sc ON sr.id = sc.student_registration_id
        LEFT JOIN classes  c   ON sc.class_id = c.id
        LEFT JOIN class_sections cs ON sc.class_section_id = cs.id
        LEFT JOIN sections s   ON cs.section_id = s.id
        LEFT JOIN sessions ses ON sc.session_id = ses.id
        WHERE ps.parent_user_id = ?
        ORDER BY sr.name
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $children = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Per child: fee + attendance + latest result summary
foreach ($children as &$child) {
    $cid = (int)$child['id'];

    $feeStmt = $conn->prepare("
        SELECT
            COALESCE(SUM(sfc.total_amount), 0) AS total_billed,
            COALESCE(SUM(sfc.paid_amount), 0)  AS total_paid,
            COALESCE(SUM(sfc.total_amount - sfc.paid_amount), 0) AS total_outstanding,
            MIN(CASE WHEN sfc.status IN ('pending','partial') AND sfc.due_date >= CURDATE() THEN sfc.due_date END) AS next_due
        FROM student_fee_card sfc
        INNER JOIN student_class sc ON sfc.student_class_id = sc.id
        WHERE sc.student_registration_id = ?
    ");
    $feeStmt->bind_param("i", $cid);
    $feeStmt->execute();
    $child['fee'] = $feeStmt->get_result()->fetch_assoc();
    $feeStmt->close();

    $attStmt = $conn->prepare("
        SELECT
            SUM(CASE WHEN status='P' THEN 1 ELSE 0 END) AS present_days,
            SUM(CASE WHEN status='L' THEN 1 ELSE 0 END) AS leave_days,
            COUNT(*) AS total_days
        FROM attendance
        WHERE student_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");
    $attStmt->bind_param("i", $cid);
    $attStmt->execute();
    $child['attendance'] = $attStmt->get_result()->fetch_assoc();
    $attStmt->close();

    $resStmt = $conn->prepare("
        SELECT ae.id AS arrange_exam_id,
               et.title AS exam_title,
               ae.start_date,
               COALESCE(SUM(r.marks), 0) AS total_scored,
               COALESCE(SUM(r.max_marks), 0) AS total_max
        FROM results r
        INNER JOIN arrange_exam ae ON ae.id = r.arrange_exam_id
        LEFT JOIN exam_types et ON et.id = ae.exam_type_id
        WHERE r.student_id = ?
        GROUP BY ae.id
        ORDER BY ae.start_date DESC
        LIMIT 1
    ");
    $resStmt->bind_param("i", $cid);
    $resStmt->execute();
    $child['latest_result'] = $resStmt->get_result()->fetch_assoc();
    $resStmt->close();
}
unset($child);

function att_pct($present, $total) {
    if ($total <= 0) return null;
    return round(($present / $total) * 100, 1);
}

/* ─────────── Notices for the parent ─────────── */
require_once('notice_helper.php');
$parent_notices = [];
$parent_notice_unread = 0;

if ($isParent || $isAdmin) {
    $audience_sql = notice_audience_sql_for_user($conn);
    $active_sql   = notice_active_sql();
    $parent_notice_unread = notice_unread_count($conn);

    $q = $conn->query("
        SELECT n.id, n.title, n.body, n.priority, n.publish_from,
               u.username AS author,
               (SELECT COUNT(*) FROM notice_reads r WHERE r.notice_id = n.id AND r.user_id = $userId) AS is_read
        FROM notices n
        LEFT JOIN users u ON u.id = n.created_by
        WHERE $active_sql AND $audience_sql
        ORDER BY n.publish_from DESC, n.id DESC
        LIMIT 5
    ");
    if ($q) while ($r = $q->fetch_assoc()) $parent_notices[] = $r;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Parent Dashboard — Dar-e-Arqm</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Inter', sans-serif; background: #f5f7fa; }
    .pd-header {
        background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
        color: #fff; padding: 30px 40px 26px 40px; border-radius: 12px;
        margin-bottom: 24px;
    }
    .pd-header h1 { font-size: 26px; font-weight: 700; margin: 0; }
    .pd-header .sub { font-size: 14px; opacity: 0.9; margin-top: 4px; }

    .child-card {
        background: #fff; border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.06);
        margin-bottom: 24px; overflow: hidden;
        border-left: 5px solid #1e40af;
    }
    .child-head {
        padding: 20px 24px;
        display: flex; align-items: center; gap: 18px;
        border-bottom: 1px solid #e2e8f0;
    }
    .child-photo {
        width: 72px; height: 72px; border-radius: 50%;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: #fff; display: flex; align-items: center; justify-content: center;
        font-weight: 700; font-size: 26px; flex-shrink: 0;
        object-fit: cover; border: 3px solid #e0e7ff;
    }
    .child-info h3 { margin: 0; font-size: 20px; font-weight: 700; color: #0f172a; }
    .child-info .meta { font-size: 13px; color: #64748b; margin-top: 3px; }
    .child-info .tags { margin-top: 8px; }
    .tag {
        display: inline-block; padding: 3px 10px; border-radius: 10px;
        font-size: 11px; font-weight: 600; margin-right: 6px;
        background: #f1f5f9; color: #475569;
    }
    .tag.primary { background: #d1fae5; color: #065f46; }
    .tag.inactive { background: #fee2e2; color: #991b1b; }

    .child-body { padding: 22px 24px; display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; }

    .summary-box {
        padding: 16px 18px; border-radius: 10px; border: 1px solid #e2e8f0;
        background: #f8fafc;
    }
    .summary-box .lbl {
        font-size: 11px; font-weight: 700; color: #64748b;
        text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px;
    }
    .summary-box .big {
        font-size: 22px; font-weight: 700; color: #0f172a;
        font-family: 'SF Mono', 'Monaco', monospace;
    }
    .summary-box .sub { font-size: 12px; color: #64748b; margin-top: 4px; }
    .summary-box.fee .big.pending { color: #dc2626; }
    .summary-box.fee .big.paid    { color: #059669; }
    .summary-box.att .big.ok      { color: #059669; }
    .summary-box.att .big.warn    { color: #d97706; }
    .summary-box.att .big.bad     { color: #dc2626; }

    .child-actions {
        padding: 14px 24px 20px;
        display: flex; gap: 10px; flex-wrap: wrap;
        border-top: 1px solid #f1f5f9;
    }
    .child-actions .btn {
        display: inline-flex; align-items: center; gap: 6px;
        height: 40px; padding: 0 18px;
        border-radius: 8px; font-size: 13px; font-weight: 600;
        text-decoration: none; cursor: pointer; line-height: 1;
        transition: all 0.15s;
    }
    .btn-fee    { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .btn-fee:hover    { background: #a7f3d0; color: #065f46; text-decoration: none; }
    .btn-att    { background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; }
    .btn-att:hover    { background: #bfdbfe; color: #1e40af; text-decoration: none; }
    .btn-res    { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
    .btn-res:hover    { background: #fde68a; color: #92400e; text-decoration: none; }

    .empty-state {
        background: #fff; border-radius: 12px; padding: 60px 20px;
        text-align: center; box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }
    .empty-state .big { font-size: 48px; opacity: 0.4; margin-bottom: 12px; }
    .empty-state h3 { font-size: 18px; color: #475569; font-weight: 600; }

        /* Notices panel */
    .notices-panel { background: #fff; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.06); margin-bottom: 24px; overflow: hidden; }
    .notices-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 18px 22px; border-bottom: 1px solid #e2e8f0; flex-wrap: wrap; }
    .notices-head h2 { font-size: 17px; font-weight: 700; color: #0f172a; margin: 0; }
    .notices-head h2 i { color: #1e40af; margin-right: 6px; }
    .notices-sub { font-size: 13px; color: #64748b; margin-top: 3px; }
    .notices-unread-badge { display: inline-block; padding: 2px 9px; border-radius: 20px; background: #dc2626; color: #fff; font-size: 11px; font-weight: 800; margin-left: 6px; }
    .notices-view-all { font-size: 13px; font-weight: 700; color: #1e40af; text-decoration: none; }
    .notices-view-all:hover { text-decoration: underline; }
    .notices-list { display: flex; flex-direction: column; }
    .notice-row { display: flex; align-items: flex-start; gap: 12px; padding: 14px 22px; border-bottom: 1px solid #f1f5f9; text-decoration: none; color: inherit; transition: background 0.12s; }
    .notice-row:last-child { border-bottom: none; }
    .notice-row:hover { background: #f8fafc; text-decoration: none; color: inherit; }
    .notice-row.unread { background: #f0f5ff; }
    .notice-row.unread:hover { background: #e5edfd; }
    .notice-row.priority-high   { border-left: 4px solid #d97706; }
    .notice-row.priority-urgent { border-left: 4px solid #dc2626; }
    .notice-row-icon { width: 38px; height: 38px; flex-shrink: 0; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 15px; }
    .notice-row-icon.normal { background: #dbeafe; color: #1e40af; }
    .notice-row-icon.high   { background: #fef3c7; color: #92400e; }
    .notice-row-icon.urgent { background: #fee2e2; color: #991b1b; }
    .notice-row-body { flex: 1; min-width: 0; }
    .notice-row-title { font-size: 14px; font-weight: 700; color: #0f172a; margin-bottom: 3px; }
    .notice-row.unread .notice-row-title { font-weight: 800; }
    .notice-row-snippet { font-size: 12px; color: #64748b; line-height: 1.45; display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden; margin-bottom: 4px; }
    .notice-row-meta { display: flex; align-items: center; gap: 10px; font-size: 11px; color: #94a3b8; flex-wrap: wrap; }
    .notice-row-meta i { color: #cbd5e1; }
    .priority-tag { display: inline-flex; align-items: center; padding: 1px 7px; border-radius: 8px; font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.3px; }
    .priority-tag.high   { background: #fef3c7; color: #92400e; }
    .priority-tag.urgent { background: #fee2e2; color: #991b1b; }
    .priority-tag.normal { background: #dbeafe; color: #1e40af; }
    .notice-row-dot { width: 8px; height: 8px; border-radius: 50%; background: #3b6fe0; margin-top: 8px; flex-shrink: 0; }
</style>
</head>
<body>
<?php require_once('navbar.php'); ?>

<div class="container" style="padding: 20px; max-width: 1100px; margin: 0 auto;">

    <!-- NOTICES PANEL -->
    <?php if (!empty($parent_notices)): ?>
    <div class="notices-panel">
        <div class="notices-head">
            <div>
                <h2><i class="fas fa-bullhorn"></i> Notices &amp; Announcements</h2>
                <div class="notices-sub">
                    Latest updates from the school
                    <?php if ($parent_notice_unread > 0): ?>
                        <span class="notices-unread-badge"><?= (int)$parent_notice_unread ?> unread</span>
                    <?php endif; ?>
                </div>
            </div>
            <a href="parent_notices.php" class="notices-view-all">View all →</a>
        </div>
        <div class="notices-list">
            <?php foreach ($parent_notices as $pn):
                $unread = ((int)$pn['is_read'] === 0);
                $p = $pn['priority'];
                $snippet = mb_substr(preg_replace('/\s+/', ' ', $pn['body']), 0, 120);
                $priorityClass = $p !== 'normal' ? 'priority-' . $p : '';
                $iconClass = $p;
            ?>
                <a class="notice-row <?= $unread ? 'unread' : '' ?> <?= $priorityClass ?>"
                   href="parent_notice_view.php?id=<?= (int)$pn['id'] ?>">
                    <span class="notice-row-icon <?= htmlspecialchars($iconClass) ?>">
                        <i class="fas fa-bullhorn"></i>
                    </span>
                    <span class="notice-row-body">
                        <div class="notice-row-title"><?= htmlspecialchars($pn['title']) ?></div>
                        <div class="notice-row-snippet"><?= htmlspecialchars($snippet) ?>…</div>
                        <div class="notice-row-meta">
                            <span><i class="fas fa-clock"></i> <?= date('d M · h:i A', strtotime($pn['publish_from'])) ?></span>
                            <?php if ($p !== 'normal'): ?>
                                <span class="priority-tag <?= htmlspecialchars($p) ?>"><?= htmlspecialchars(ucfirst($p)) ?></span>
                            <?php endif; ?>
                        </div>
                    </span>
                    <?php if ($unread): ?><span class="notice-row-dot"></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (empty($children)): ?>
        <div class="empty-state">
            <div class="big">👶</div>
            <h3>No students linked to your account</h3>
            <p style="color: #94a3b8; margin-top: 8px;">Contact the school administration.</p>
        </div>
    <?php else: ?>
        <?php foreach ($children as $c):
            $cid = (int)$c['id'];
            $initials = strtoupper(substr(preg_replace('/\s+/', '', $c['name'] ?: 'S'), 0, 2));
            $fee = $c['fee'] ?? ['total_billed'=>0,'total_paid'=>0,'total_outstanding'=>0,'next_due'=>null];
            $att = $c['attendance'] ?? ['present_days'=>0,'leave_days'=>0,'total_days'=>0];
            $lr  = $c['latest_result'] ?? null;
            $attPct = att_pct((int)$att['present_days'], (int)$att['total_days']);
            $attClass = 'ok';
            if ($attPct === null) $attClass = '';
            elseif ($attPct < 70) $attClass = 'bad';
            elseif ($attPct < 85) $attClass = 'warn';
        ?>
        <div class="child-card">
            <div class="child-head">
                <?php if (!empty($c['image_path']) && file_exists($c['image_path'])): ?>
                    <img src="<?= htmlspecialchars($c['image_path']) ?>" class="child-photo" alt="">
                <?php else: ?>
                    <div class="child-photo"><?= htmlspecialchars($initials) ?></div>
                <?php endif; ?>
                <div class="child-info" style="flex: 1;">
                    <h3><?= htmlspecialchars($c['name']) ?></h3>
                    <div class="meta">
                        <i class="fas fa-user"></i> s/o <?= htmlspecialchars($c['father_name'] ?: '—') ?>
                        &nbsp;·&nbsp;
                        <i class="fas fa-graduation-cap"></i> <?= htmlspecialchars($c['class_title'] ?: 'No class') ?>
                        <?= $c['section_title'] ? ' - ' . htmlspecialchars($c['section_title']) : '' ?>
                        &nbsp;·&nbsp;
                        Reg# <?= htmlspecialchars($c['reg_no']) ?>
                    </div>
                    <div class="tags">
                        <span class="tag">#<?= $cid ?></span>
                        <?php if ($c['roll_no']): ?><span class="tag">Roll <?= htmlspecialchars($c['roll_no']) ?></span><?php endif; ?>
                        <span class="tag <?= $c['is_primary'] ? 'primary' : '' ?>"><?= ucfirst(htmlspecialchars($c['relationship'])) ?><?= $c['is_primary'] ? ' (primary)' : '' ?></span>
                        <?php if ((int)$c['status'] !== 0): ?><span class="tag inactive">Inactive</span><?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="child-body">
                <div class="summary-box fee">
                    <div class="lbl">Fees</div>
                    <?php if ((float)$fee['total_outstanding'] > 0): ?>
                        <div class="big pending">Rs. <?= number_format((float)$fee['total_outstanding'], 0) ?></div>
                        <div class="sub">Outstanding · Paid Rs. <?= number_format((float)$fee['total_paid'], 0) ?></div>
                    <?php else: ?>
                        <div class="big paid">All paid</div>
                        <div class="sub">Total billed Rs. <?= number_format((float)$fee['total_billed'], 0) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($fee['next_due'])): ?>
                        <div class="sub" style="margin-top: 6px; color: #d97706;">
                            <i class="fas fa-calendar"></i> Next due <?= htmlspecialchars($fee['next_due']) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="summary-box att">
                    <div class="lbl">Attendance (30 days)</div>
                    <?php if ($attPct === null): ?>
                        <div class="big" style="color: #94a3b8;">—</div>
                        <div class="sub">No attendance data</div>
                    <?php else: ?>
                        <div class="big <?= $attClass ?>"><?= $attPct ?>%</div>
                        <div class="sub"><?= (int)$att['present_days'] ?> present · <?= (int)$att['leave_days'] ?> absent of <?= (int)$att['total_days'] ?></div>
                    <?php endif; ?>
                </div>

                <div class="summary-box">
                    <div class="lbl">Latest Result</div>
                    <?php if ($lr && (float)$lr['total_max'] > 0): ?>
                        <?php
                            $pct = round(((float)$lr['total_scored'] / (float)$lr['total_max']) * 100, 1);
                            $grade = $pct >= 90 ? 'A+' : ($pct >= 80 ? 'A' : ($pct >= 70 ? 'B' : ($pct >= 60 ? 'C' : ($pct >= 50 ? 'D' : 'F'))));
                        ?>
                        <div class="big"><?= $grade ?> (<?= $pct ?>%)</div>
                        <div class="sub"><?= htmlspecialchars($lr['exam_title'] ?: 'Exam') ?> · <?= number_format((float)$lr['total_scored'],0) ?>/<?= number_format((float)$lr['total_max'],0) ?></div>
                    <?php else: ?>
                        <div class="big" style="color: #94a3b8;">—</div>
                        <div class="sub">No results yet</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="child-actions">
                <a href="parent_fee_view.php?student_id=<?= $cid ?>" class="btn btn-fee">
                    <i class="fas fa-money-bill-wave"></i> View Fees
                </a>
                <a href="parent_attendance.php?student_id=<?= $cid ?>" class="btn btn-att">
                    <i class="fas fa-calendar-check"></i> Attendance
                </a>
                <a href="parent_result.php?student_id=<?= $cid ?>" class="btn btn-res">
                    <i class="fas fa-poll"></i> Results
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>
</body>
</html>
<?php $conn->close(); ?>