<?php
ob_start();
header('Content-Type: text/html; charset=utf-8');
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
if (session_status() === PHP_SESSION_NONE) session_start();

require_once('conn_inc.php');

/* ═══════════ HELPERS ═══════════ */
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function fmt_date($d): string {
    if (empty($d) || $d === '0000-00-00') return '';
    try { return (new DateTime($d))->format('d M Y'); } catch (Exception $e) { return (string)$d; }
}
function relative_time($ts): string {
    if (empty($ts)) return '';
    try { $d = new DateTime($ts); } catch (Exception $e) { return ''; }
    $now = new DateTime();
    $diff = $now->diff($d);
    if ($diff->y > 0) return $diff->y . 'y ago';
    if ($diff->m > 0) return $diff->m . 'mo ago';
    if ($diff->d > 0) return $diff->d . 'd ago';
    if ($diff->h > 0) return $diff->h . 'h ago';
    if ($diff->i > 0) return $diff->i . 'm ago';
    return 'Just now';
}

/* ═══════════ STUDENT STATS ═══════════ */
$total_students = 0;
$active_students = 0;
$left_students = 0;
$inactive_students = 0;

$q = $conn->query("SELECT status, COUNT(*) AS c FROM student_registration GROUP BY status");
if ($q) while ($row = $q->fetch_assoc()) {
    $s = (int)$row['status'];
    $c = (int)$row['c'];
    $total_students += $c;
    if ($s === 0) $active_students = $c;
    elseif ($s === 3) $left_students = $c;
    else $inactive_students += $c;
}

$new_this_month = 0;
$q = $conn->query("SELECT COUNT(*) AS c FROM student_registration WHERE MONTH(registration_date) = MONTH(CURDATE()) AND YEAR(registration_date) = YEAR(CURDATE())");
if ($q) $new_this_month = (int)$q->fetch_assoc()['c'];

/* ═══════════ CERTIFICATE STATS ═══════════ */
$total_certs = 0;
$q = $conn->query("SELECT COUNT(*) AS c FROM student_certificates WHERE status = 0");
if ($q) $total_certs = (int)$q->fetch_assoc()['c'];

$certs_this_month = 0;
$q = $conn->query("SELECT COUNT(*) AS c FROM student_certificates WHERE status = 0 AND MONTH(cert_date) = MONTH(CURDATE()) AND YEAR(cert_date) = YEAR(CURDATE())");
if ($q) $certs_this_month = (int)$q->fetch_assoc()['c'];

/* ═══════════ ACADEMIC STATS ═══════════ */
$total_classes = 0;
$q = $conn->query("SELECT COUNT(*) AS c FROM classes WHERE status = 0 OR status IS NULL");
if ($q) $total_classes = (int)$q->fetch_assoc()['c'];

$total_branches = 0;
$q = $conn->query("SELECT COUNT(*) AS c FROM branches WHERE status = 0 OR status IS NULL");
if ($q) $total_branches = (int)$q->fetch_assoc()['c'];

/* ═══════════ EXAM STATS ═══════════ */
$stat_arrangements = 0;
$q = $conn->query("SELECT COUNT(*) AS c FROM arrange_exam WHERE exam_kind = 'term'");
if ($q) $stat_arrangements = (int)$q->fetch_assoc()['c'];

$stat_tests = 0;
$q = $conn->query("SELECT COUNT(*) AS c FROM arrange_exam WHERE exam_kind = 'test'");
if ($q) $stat_tests = (int)$q->fetch_assoc()['c'];

/* ═══════════ RECENT STUDENTS ═══════════ */
$recent_students = [];
$q = $conn->query("SELECT id, name, reg_no, registration_date, image_path, status FROM student_registration ORDER BY id DESC LIMIT 6");
if ($q) while ($r = $q->fetch_assoc()) $recent_students[] = $r;

/* ═══════════ RECENT CERTIFICATES ═══════════ */
$recent_certs = [];
$q = $conn->query("SELECT sc.id, sc.cert_no, sc.cert_date, sc.student_id, sr.name AS student_name, sr.reg_no FROM student_certificates sc LEFT JOIN student_registration sr ON sr.id = sc.student_id WHERE sc.status = 0 ORDER BY sc.id DESC LIMIT 6");
if ($q) while ($r = $q->fetch_assoc()) $recent_certs[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Dashboard</title>
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

    /* Stats */
    .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 30px; }
    .stat { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px 20px; display: flex; align-items: center; gap: 14px; }
    .stat .icon { width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; }
    .stat .icon.blue { background: #dbeafe; color: #1e40af; }
    .stat .icon.green { background: #d1fae5; color: #059669; }
    .stat .icon.amber { background: #fef3c7; color: #d97706; }
    .stat .icon.purple { background: #ede9fe; color: #7c3aed; }
    .stat .icon.red { background: #fee2e2; color: #dc2626; }
    .stat .icon.teal { background: #cffafe; color: #0891b2; }
    .stat .num { font-size: 26px; font-weight: 800; color: #0f172a; line-height: 1; }
    .stat .label { font-size: 12px; color: #64748b; margin-top: 4px; font-weight: 600; }

    /* Sections */
    .sec { font-size: 12px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 2px; margin: 24px 0 16px 0; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0; }
    .sec:first-child { margin-top: 0; }

    /* Tiles */
    .tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
    .tile { display: flex; align-items: center; gap: 14px; padding: 18px 20px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; text-decoration: none; color: inherit; transition: all 0.15s; }
    .tile:hover { border-color: #1e40af; box-shadow: 0 4px 12px rgba(30,64,175,0.12); transform: translateY(-2px); text-decoration: none; color: inherit; }
    .tile .tile-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
    .tile .tile-body { min-width: 0; flex: 1; }
    .tile .tile-title { font-size: 14px; font-weight: 700; color: #0f172a; }
    .tile .tile-desc { font-size: 11px; color: #94a3b8; margin-top: 3px; }
    .tile .chev { color: #cbd5e1; font-size: 12px; flex-shrink: 0; }
    .tile:hover .chev { color: #1e40af; }

    /* Recent table */
    table.recent { width: 100%; border-collapse: collapse; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; }
    table.recent thead th { background: #1e3a8a; color: #fff; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 12px; text-align: left; }
    table.recent tbody td { padding: 12px; font-size: 13px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    table.recent tbody tr:last-child td { border-bottom: none; }
    table.recent tbody tr:hover td { background: #f8fafc; }
    .pill { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; }
    .pill.blue { background: #dbeafe; color: #1e40af; }
    .pill.green { background: #d1fae5; color: #065f46; }
    .pill.amber { background: #fef3c7; color: #92400e; }
    .pill.red { background: #fee2e2; color: #991b1b; }
    .pill.muted { background: #f1f5f9; color: #64748b; }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; height: 32px; padding: 0 14px; border-radius: 8px; font-size: 12px; font-weight: 700; text-decoration: none; cursor: pointer; border: 1px solid; font-family: inherit; }
    .btn-primary { background: #1e40af; color: #fff; border-color: #1e40af; }
    .btn-primary:hover { background: #1e3a8a; color: #fff; text-decoration: none; }

    /* Two-column split for recent lists */
    .split { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    @media (max-width: 900px) { .split { grid-template-columns: 1fr; } }

    /* Class bars */
    .class-bars { margin-top: 10px; }
    .class-bar-row { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
    .class-bar-name { flex: 0 0 120px; font-size: 13px; font-weight: 600; color: #334155; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .class-bar-track { flex: 1; height: 8px; background: #f1f5f9; border-radius: 4px; overflow: hidden; }
    .class-bar-fill { height: 100%; background: linear-gradient(90deg, #1e40af, #3b82f6); border-radius: 4px; }
    .class-bar-count { flex: 0 0 40px; text-align: right; font-size: 13px; font-weight: 700; color: #0f172a; }

    /* Empty state */
    .empty { padding: 40px 20px; text-align: center; color: #94a3b8; }
    .empty .big { font-size: 40px; opacity: 0.4; margin-bottom: 10px; }
    .empty .msg { font-weight: 600; color: #475569; }
    .empty .link { font-size: 13px; margin-top: 8px; }
    .empty .link a { color: #1e40af; font-weight: 700; text-decoration: none; }
    .empty .link a:hover { text-decoration: underline; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">
    <div class="head">
        <h1><i class="fas fa-th-large"></i> Dashboard</h1>
        <div class="sub">Overview of <?= h(date('l, d M Y')) ?> &mdash; everything in one place</div>
    </div>

    <div class="card">

        <!-- ═══════════ TOP STATS ═══════════ -->
        <div class="stats">
            <div class="stat">
                <div class="icon blue"><i class="fas fa-users"></i></div>
                <div>
                    <div class="num"><?= number_format($total_students) ?></div>
                    <div class="label">Total Students</div>
                </div>
            </div>
            <div class="stat">
                <div class="icon green"><i class="fas fa-user-check"></i></div>
                <div>
                    <div class="num"><?= number_format($active_students) ?></div>
                    <div class="label">Active Students</div>
                </div>
            </div>
            <div class="stat">
                <div class="icon amber"><i class="fas fa-user-minus"></i></div>
                <div>
                    <div class="num"><?= number_format($left_students) ?></div>
                    <div class="label">Left School</div>
                </div>
            </div>
            <div class="stat">
                <div class="icon purple"><i class="fas fa-user-plus"></i></div>
                <div>
                    <div class="num"><?= number_format($new_this_month) ?></div>
                    <div class="label">New This Month</div>
                </div>
            </div>
        </div>

        <!-- ═══════════ STUDENTS ═══════════ -->
        <div class="sec">Students</div>
        <div class="tiles">
            <a href="student_registration.php" class="tile">
                <div class="tile-icon" style="background:#dbeafe;color:#1e40af;"><i class="fas fa-user-plus"></i></div>
                <div class="tile-body">
                    <div class="tile-title">Register New Student</div>
                    <div class="tile-desc">Add a new admission</div>
                </div>
                <i class="fas fa-chevron-right chev"></i>
            </a>
            <a href="student_list.php" class="tile">
                <div class="tile-icon" style="background:#d1fae5;color:#059669;"><i class="fas fa-users"></i></div>
                <div class="tile-body">
                    <div class="tile-title">Student List</div>
                    <div class="tile-desc">Browse and filter all students</div>
                </div>
                <i class="fas fa-chevron-right chev"></i>
            </a>
            <a href="student_id_cards.php" class="tile">
                <div class="tile-icon" style="background:#ede9fe;color:#7c3aed;"><i class="fas fa-id-card"></i></div>
                <div class="tile-body">
                    <div class="tile-title">ID Cards</div>
                    <div class="tile-desc">Generate & print student ID cards</div>
                </div>
                <i class="fas fa-chevron-right chev"></i>
            </a>
        </div>

        <!-- ═══════════ CERTIFICATES ═══════════ -->
        <div class="sec">Certificates</div>
        <div class="tiles">
            <a href="student_leaving_certificate.php" class="tile">
                <div class="tile-icon" style="background:#dbeafe;color:#1e40af;"><i class="fas fa-scroll"></i></div>
                <div class="tile-body">
                    <div class="tile-title">Issue Certificate</div>
                    <div class="tile-desc">Generate a new leaving certificate</div>
                </div>
                <i class="fas fa-chevron-right chev"></i>
            </a>
            <a href="student_leaving_certificate_list.php" class="tile">
                <div class="tile-icon" style="background:#d1fae5;color:#059669;"><i class="fas fa-file-alt"></i></div>
                <div class="tile-body">
                    <div class="tile-title">Certificate List</div>
                    <div class="tile-desc">All issued certificates</div>
                </div>
                <i class="fas fa-chevron-right chev"></i>
            </a>
        </div>

        <!-- ═══════════ ACADEMIC ═══════════ -->
        

        <!-- ═══════════ EXAMS ═══════════ -->
       

        <!-- ═══════════ RECENT ACTIVITY ═══════════ -->
        <div class="sec">Recent Activity</div>

        <div class="split">

            <!-- RECENT STUDENTS -->
            <div>
                <div style="font-size:13px;font-weight:700;color:#0f172a;margin-bottom:10px;">
                    <i class="fas fa-user-graduate" style="color:#1e40af;"></i> Recently Registered Students
                </div>
                <?php if ($recent_students): ?>
                    <table class="recent">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Reg No</th>
                                <th>Status</th>
                                <th style="text-align:right;">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_students as $s):
                                $status_pill = 'green';
                                $status_text = 'Active';
                                if ((int)$s['status'] === 3) { $status_pill = 'amber'; $status_text = 'Left'; }
                                elseif ((int)$s['status'] === 2) { $status_pill = 'red'; $status_text = 'Struck Off'; }
                                elseif ((int)$s['status'] === 1) { $status_pill = 'muted'; $status_text = 'Inactive'; }
                            ?>
                            <tr>
                                <td><strong><?= h($s['name']) ?></strong></td>
                                <td style="font-family:ui-monospace,monospace;font-size:12px;color:#1e40af;font-weight:600;"><?= h($s['reg_no']) ?></td>
                                <td><span class="pill <?= $status_pill ?>"><?= h($status_text) ?></span></td>
                                <td style="text-align:right;color:#64748b;font-size:12px;"><?= h(relative_time($s['registration_date'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty">
                        <div class="big">&#128100;</div>
                        <div class="msg">No students registered yet</div>
                        <div class="link"><a href="student_registration.php">Register the first student &rarr;</a></div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- RECENT CERTIFICATES -->
            <div>
                <div style="font-size:13px;font-weight:700;color:#0f172a;margin-bottom:10px;">
                    <i class="fas fa-scroll" style="color:#059669;"></i> Recently Issued Certificates
                </div>
                <?php if ($recent_certs): ?>
                    <table class="recent">
                        <thead>
                            <tr>
                                <th>Certificate No</th>
                                <th>Student</th>
                                <th style="text-align:right;">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_certs as $c): ?>
                            <tr>
                                <td style="font-family:ui-monospace,monospace;font-size:12px;color:#7c3aed;font-weight:700;"><?= h($c['cert_no']) ?></td>
                                <td><strong><?= h($c['student_name'] ?: '—') ?></strong></td>
                                <td style="text-align:right;color:#64748b;font-size:12px;"><?= h(fmt_date($c['cert_date'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty">
                        <div class="big">&#128196;</div>
                        <div class="msg">No certificates issued yet</div>
                        <div class="link"><a href="student_leaving_certificate.php">Issue the first certificate &rarr;</a></div>
                    </div>
                <?php endif; ?>
            </div>

        </div>

    </div>
</div>

</body>
</html>
<?php $conn->close(); ob_end_flush(); ?>