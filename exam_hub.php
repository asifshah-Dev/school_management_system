<?php
ob_start();
header('Content-Type: text/html; charset=utf-8');
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
if (session_status() === PHP_SESSION_NONE) session_start();

require_once('conn_inc.php');

/* ─────────── Quick stats ─────────── */
$stat_arrangements = 0;
$q = $conn->query("SELECT COUNT(*) AS c FROM arrange_exam WHERE exam_kind = 'term'");
if ($q) $stat_arrangements = (int)$q->fetch_assoc()['c'];

$stat_tests = 0;
$q = $conn->query("SELECT COUNT(*) AS c FROM arrange_exam WHERE exam_kind = 'test'");
if ($q) $stat_tests = (int)$q->fetch_assoc()['c'];

$stat_dmc_ready = 0;
$q = $conn->query("SELECT COUNT(DISTINCT arrange_exam_id) AS c FROM results");
if ($q) $stat_dmc_ready = (int)$q->fetch_assoc()['c'];

$stat_grades = 0;
$q = $conn->query("SELECT COUNT(*) AS c FROM grade_scale WHERE status = 0");
if ($q) $stat_grades = (int)$q->fetch_assoc()['c'];

/* ─────────── Recent arrangements ─────────── */
$recent = array();
$q = $conn->query("SELECT ae.id, ae.start_date, ae.exam_kind, c.title AS class_title, et.title AS exam_type_title, (SELECT COUNT(*) FROM arrange_exam_subjects WHERE arrange_exam_id = ae.id) AS subject_count, (SELECT COUNT(*) FROM results WHERE arrange_exam_id = ae.id) AS result_count FROM arrange_exam ae INNER JOIN classes c ON c.id = ae.class_id INNER JOIN exam_types et ON et.id = ae.exam_type_id ORDER BY ae.start_date DESC, ae.id DESC LIMIT 8");
if ($q) while ($r = $q->fetch_assoc()) $recent[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Exam Management</title>
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
    .pill.term { background: #dbeafe; color: #1e40af; }
    .pill.test { background: #fef3c7; color: #92400e; }
    .pill.muted { background: #f1f5f9; color: #64748b; }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; height: 32px; padding: 0 14px; border-radius: 8px; font-size: 12px; font-weight: 700; text-decoration: none; cursor: pointer; border: 1px solid; font-family: inherit; }
    .btn-primary { background: #1e40af; color: #fff; border-color: #1e40af; }
    .btn-primary:hover { background: #1e3a8a; color: #fff; text-decoration: none; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">
    <div class="head">
        <h1><i class="fas fa-clipboard-list"></i> Exam Management</h1>
        <div class="sub">Arrange tests and exams, print datesheets, enter marks, and generate DMCs</div>
    </div>

    <div class="card">

        <div class="stats">
            <div class="stat">
                <div class="icon blue"><i class="fas fa-graduation-cap"></i></div>
                <div>
                    <div class="num"><?= $stat_arrangements ?></div>
                    <div class="label">Term Exams</div>
                </div>
            </div>
            <div class="stat">
                <div class="icon amber"><i class="fas fa-pen"></i></div>
                <div>
                    <div class="num"><?= $stat_tests ?></div>
                    <div class="label">Tests</div>
                </div>
            </div>
            <div class="stat">
                <div class="icon green"><i class="fas fa-file-lines"></i></div>
                <div>
                    <div class="num"><?= $stat_dmc_ready ?></div>
                    <div class="label">DMC-Ready</div>
                </div>
            </div>
            <div class="stat">
                <div class="icon purple"><i class="fas fa-award"></i></div>
                <div>
                    <div class="num"><?= $stat_grades ?></div>
                    <div class="label">Grade Bands</div>
                </div>
            </div>
        </div>

        <div class="sec">Arrange</div>
        <div class="tiles">
            <a href="arrange_exam.php" class="tile">
                <div class="tile-icon" style="background:#dbeafe;color:#1e40af;"><i class="fas fa-calendar-plus"></i></div>
                <div class="tile-body">
                    <div class="tile-title">Arrange Test / Exam</div>
                    <div class="tile-desc">Create arrangements and assign subjects</div>
                </div>
                <i class="fas fa-chevron-right chev"></i>
            </a>
            <a href="exam_types.php" class="tile">
                <div class="tile-icon" style="background:#fef3c7;color:#d97706;"><i class="fas fa-list"></i></div>
                <div class="tile-body">
                    <div class="tile-title">Exam Types</div>
                    <div class="tile-desc">Manage test and term types</div>
                </div>
                <i class="fas fa-chevron-right chev"></i>
            </a>
            <a href="subject_class.php" class="tile">
                <div class="tile-icon" style="background:#d1fae5;color:#059669;"><i class="fas fa-book"></i></div>
                <div class="tile-body">
                    <div class="tile-title">Exam Subjects</div>
                    <div class="tile-desc">Class-wise subject catalog</div>
                </div>
                <i class="fas fa-chevron-right chev"></i>
            </a>
        </div>

        <div class="sec">Datesheet</div>
        <div class="tiles">
            <a href="datesheet.php" class="tile">
                <div class="tile-icon" style="background:#dbeafe;color:#1e40af;"><i class="fas fa-calendar-days"></i></div>
                <div class="tile-body">
                    <div class="tile-title">Datesheet</div>
                    <div class="tile-desc">Set dates, times, and rules per arrangement</div>
                </div>
                <i class="fas fa-chevron-right chev"></i>
            </a>
            <a href="exam_master_datesheet.php" class="tile">
                <div class="tile-icon" style="background:#ede9fe;color:#7c3aed;"><i class="fas fa-layer-group"></i></div>
                <div class="tile-body">
                    <div class="tile-title">Master Datesheet</div>
                    <div class="tile-desc">All classes, by subject / day / class</div>
                </div>
                <i class="fas fa-chevron-right chev"></i>
            </a>
        </div>

        <div class="sec">Marks &amp; DMC</div>
        <div class="tiles">
            <a href="exam_attendance_sheet.php" class="tile">
                <div class="tile-icon" style="background:#d1fae5;color:#059669;"><i class="fas fa-clipboard-check"></i></div>
                <div class="tile-body">
                    <div class="tile-title">Exam Attendance</div>
                    <div class="tile-desc">Per-subject attendance</div>
                </div>
                <i class="fas fa-chevron-right chev"></i>
            </a>
            <a href="exam_marks_sheet.php" class="tile">
                <div class="tile-icon" style="background:#fef3c7;color:#d97706;"><i class="fas fa-file-pen"></i></div>
                <div class="tile-body">
                    <div class="tile-title">Marks Sheet</div>
                    <div class="tile-desc">Draft entry per subject</div>
                </div>
                <i class="fas fa-chevron-right chev"></i>
            </a>
            <a href="result_entry.php" class="tile">
                <div class="tile-icon" style="background:#dbeafe;color:#1e40af;"><i class="fas fa-pen-to-square"></i></div>
                <div class="tile-body">
                    <div class="tile-title">Result Entry</div>
                    <div class="tile-desc">Full marks entry with absent flags</div>
                </div>
                <i class="fas fa-chevron-right chev"></i>
            </a>
            <a href="dmc_list.php" class="tile">
                <div class="tile-icon" style="background:#d1fae5;color:#059669;"><i class="fas fa-file-lines"></i></div>
                <div class="tile-body">
                    <div class="tile-title">DMC List</div>
                    <div class="tile-desc">Students, positions, print links</div>
                </div>
                <i class="fas fa-chevron-right chev"></i>
            </a>
            <a href="create_dmcs.php" class="tile">
                <div class="tile-icon" style="background:#ede9fe;color:#7c3aed;"><i class="fas fa-award"></i></div>
                <div class="tile-body">
                    <div class="tile-title">Create DMCs</div>
                    <div class="tile-desc">Bulk DMC generation</div>
                </div>
                <i class="fas fa-chevron-right chev"></i>
            </a>
        </div>

        <div class="sec">Configuration</div>
        <div class="tiles">
            <a href="grade_scale.php" class="tile">
                <div class="tile-icon" style="background:#dbeafe;color:#1e40af;"><i class="fas fa-award"></i></div>
                <div class="tile-body">
                    <div class="tile-title">Grade Scale</div>
                    <div class="tile-desc">Configure percentage bands</div>
                </div>
                <i class="fas fa-chevron-right chev"></i>
            </a>
            <a href="sessions.php" class="tile">
                <div class="tile-icon" style="background:#f1f5f9;color:#475569;"><i class="fas fa-calendar"></i></div>
                <div class="tile-body">
                    <div class="tile-title">Sessions</div>
                    <div class="tile-desc">Academic years</div>
                </div>
                <i class="fas fa-chevron-right chev"></i>
            </a>
        </div>

        <div class="sec">Reports</div>
        <div class="tiles">
            <a href="student_result_analysis.php" class="tile">
                <div class="tile-icon" style="background:#fef3c7;color:#d97706;"><i class="fas fa-chart-line"></i></div>
                <div class="tile-body">
                    <div class="tile-title">Result Analysis</div>
                    <div class="tile-desc">Students below 50%</div>
                </div>
                <i class="fas fa-chevron-right chev"></i>
            </a>
        </div>

        <div class="sec">Recent Arrangements</div>
        <?php if (empty($recent)): ?>
            <div style="padding:40px 20px;text-align:center;color:#94a3b8;">
                <div style="font-size:40px;opacity:0.4;margin-bottom:10px;">&#128197;</div>
                <div style="font-weight:600;color:#475569;">No arrangements yet</div>
                <div style="font-size:13px;margin-top:8px;">
                    <a href="arrange_exam.php" style="color:#1e40af;font-weight:700;">Arrange the first test or exam →</a>
                </div>
            </div>
        <?php else: ?>
            <table class="recent">
                <thead>
                    <tr>
                        <th>Class</th>
                        <th>Exam Type</th>
                        <th>Start Date</th>
                        <th style="text-align:center;">Subjects</th>
                        <th style="text-align:center;">Results</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recent as $r): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($r['class_title']) ?></strong></td>
                        <td><span class="pill <?= htmlspecialchars($r['exam_kind']) ?>"><?= htmlspecialchars($r['exam_type_title']) ?></span></td>
                        <td><?= date('d M Y', strtotime($r['start_date'])) ?></td>
                        <td style="text-align:center;">
                            <?php if ((int)$r['subject_count'] > 0): ?>
                                <span class="pill term"><?= (int)$r['subject_count'] ?></span>
                            <?php else: ?>
                                <span class="pill muted">none</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <?php if ((int)$r['result_count'] > 0): ?>
                                <span class="pill term"><?= (int)$r['result_count'] ?></span>
                            <?php else: ?>
                                <span class="pill muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right; white-space:nowrap;">
                            <a href="datesheet.php?exam_id=<?= (int)$r['id'] ?>" class="btn btn-primary"><i class="fas fa-calendar-days"></i></a>
                            <a href="result_entry.php?exam_id=<?= (int)$r['id'] ?>" class="btn btn-primary" style="background:#059669;border-color:#059669;"><i class="fas fa-pen"></i></a>
                            <a href="dmc_list.php?arrange_exam_id=<?= (int)$r['id'] ?>" class="btn btn-primary" style="background:#7c3aed;border-color:#7c3aed;"><i class="fas fa-file-lines"></i></a>
                        </td>
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