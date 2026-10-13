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

$student_id = (int)($_GET['student_id'] ?? 0);
if ($student_id <= 0) { header("Location: parent_dashboard.php"); exit(); }

if ($isParent && !$isAdmin) {
    $stmt = $conn->prepare("SELECT 1 FROM parent_students WHERE parent_user_id = ? AND student_id = ? LIMIT 1");
    $stmt->bind_param("ii", $userId, $student_id);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) { header("Location: 403.php"); exit(); }
    $stmt->close();
}

// Month filter
$month = isset($_GET['month']) ? preg_replace('/[^0-9\-]/', '', $_GET['month']) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');

$monthStart = $month . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));

// Student
$stmt = $conn->prepare("
    SELECT sr.id, sr.name, sr.father_name, sr.reg_no, sr.image_path,
           c.title AS class_title, s.title AS section_title
    FROM student_registration sr
    LEFT JOIN (
        SELECT sc1.student_registration_id, sc1.class_id, sc1.class_section_id
        FROM student_class sc1
        INNER JOIN (SELECT student_registration_id, MAX(id) AS max_id FROM student_class GROUP BY student_registration_id) sc2
            ON sc1.id = sc2.max_id
    ) sc ON sr.id = sc.student_registration_id
    LEFT JOIN classes c ON sc.class_id = c.id
    LEFT JOIN class_sections cs ON sc.class_section_id = cs.id
    LEFT JOIN sections s ON cs.section_id = s.id
    WHERE sr.id = ?
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) { header("Location: parent_dashboard.php"); exit(); }

// Attendance rows for the month
$stmt = $conn->prepare("
    SELECT date, status, remarks
    FROM attendance
    WHERE student_id = ? AND date BETWEEN ? AND ?
    ORDER BY date ASC
");
$stmt->bind_param("iss", $student_id, $monthStart, $monthEnd);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$by_date = [];
foreach ($rows as $r) $by_date[$r['date']] = $r;

$present = 0; $leave = 0;
foreach ($rows as $r) {
    if ($r['status'] === 'P') $present++;
    elseif ($r['status'] === 'L') $leave++;
}
$total = $present + $leave;
$pct = $total > 0 ? round(($present / $total) * 100, 1) : null;

$initials = strtoupper(substr(preg_replace('/\s+/', '', $student['name'] ?: 'S'), 0, 2));
$monthLabel = date('F Y', strtotime($monthStart));
$daysInMonth = (int)date('t', strtotime($monthStart));
$firstDayOfWeek = (int)date('w', strtotime($monthStart)); // 0=Sun
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Attendance — <?= htmlspecialchars($student['name']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Inter', sans-serif; background: #f5f7fa; }
    .pf-header {
        background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
        color: #fff; padding: 26px 30px; border-radius: 12px;
        margin-bottom: 22px; display: flex; align-items: center; gap: 20px;
    }
    .pf-photo {
        width: 64px; height: 64px; border-radius: 50%;
        background: rgba(255,255,255,0.2); border: 2px solid rgba(255,255,255,0.4);
        display: flex; align-items: center; justify-content: center;
        font-size: 22px; font-weight: 700; flex-shrink: 0;
        object-fit: cover;
    }
    .pf-header h1 { font-size: 22px; font-weight: 700; margin: 0; }
    .pf-header .sub { font-size: 13px; opacity: 0.9; margin-top: 4px; }

    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; margin-bottom: 22px; }
    .stat { background: #fff; border-radius: 10px; padding: 16px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border-left: 4px solid #1e40af; }
    .stat .lbl { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
    .stat .val { font-size: 22px; font-weight: 700; font-family: 'SF Mono', monospace; }
    .stat.present { border-left-color: #059669; } .stat.present .val { color: #059669; }
    .stat.leave   { border-left-color: #d97706; } .stat.leave .val   { color: #d97706; }
    .stat.pct     { border-left-color: #1e40af; } .stat.pct .val     { color: #1e40af; }

    .cal-wrap { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .cal-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; gap: 12px; flex-wrap: wrap; }
    .cal-title { font-size: 18px; font-weight: 700; color: #0f172a; }
    .month-nav { display: flex; gap: 8px; }
    .month-nav a, .month-nav form button {
        display: inline-flex; align-items: center; gap: 6px;
        height: 38px; padding: 0 14px;
        background: #fff; border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 13px; font-weight: 600; color: #334155;
        text-decoration: none; cursor: pointer;
    }
    .month-nav a:hover, .month-nav form button:hover { background: #f1f5f9; text-decoration: none; color: #1e293b; }
    .month-nav input[type=month] { height: 38px; padding: 0 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-family: inherit; }

    .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 8px; }
    .cal-grid .dow { text-align: center; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 1px; padding: 6px 0; }
    .cal-cell {
        aspect-ratio: 1 / 1; border-radius: 8px; padding: 8px;
        display: flex; flex-direction: column; justify-content: space-between;
        background: #f8fafc; border: 1px solid #e2e8f0;
        font-size: 13px;
    }
    .cal-cell .day-num { font-weight: 700; color: #334155; }
    .cal-cell .status {
        font-size: 10px; font-weight: 700; text-transform: uppercase;
        padding: 2px 6px; border-radius: 6px; align-self: flex-start;
    }
    .cal-cell.present .status { background: #d1fae5; color: #065f46; }
    .cal-cell.leave   .status { background: #fef3c7; color: #92400e; }
    .cal-cell.empty   .status { background: #f1f5f9; color: #94a3b8; }
    .cal-cell.empty   { background: #fdfdfd; }
    .cal-cell.blank   { background: transparent; border: none; }

    .empty-state { background: #fff; border-radius: 12px; padding: 50px 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-top: 20px; }
    .empty-state .big { font-size: 44px; opacity: 0.4; margin-bottom: 12px; }
    .empty-state h3 { font-size: 17px; color: #475569; font-weight: 600; }

    .back-btn {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 8px 16px; background: #fff; color: #334155;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 13px; font-weight: 600; text-decoration: none;
        margin-bottom: 14px;
    }
    .back-btn:hover { background: #f1f5f9; text-decoration: none; color: #1e293b; }
</style>
</head>
<body>
<?php require_once('navbar.php'); ?>

<div class="container" style="padding: 20px; max-width: 1000px; margin: 0 auto;">

    <a href="parent_dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to dashboard</a>

    <div class="pf-header">
        <?php if (!empty($student['image_path']) && file_exists($student['image_path'])): ?>
            <img src="<?= htmlspecialchars($student['image_path']) ?>" class="pf-photo" alt="">
        <?php else: ?>
            <div class="pf-photo"><?= htmlspecialchars($initials) ?></div>
        <?php endif; ?>
        <div style="flex:1;">
            <h1><?= htmlspecialchars($student['name']) ?></h1>
            <div class="sub">
                s/o <?= htmlspecialchars($student['father_name'] ?: '—') ?>
                · <?= htmlspecialchars($student['class_title'] ?: 'No class') ?><?= $student['section_title'] ? ' - ' . htmlspecialchars($student['section_title']) : '' ?>
                · Reg# <?= htmlspecialchars($student['reg_no']) ?>
            </div>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat present">
            <div class="lbl">Present Days</div>
            <div class="val"><?= $present ?></div>
        </div>
        <div class="stat leave">
            <div class="lbl">Leave Days</div>
            <div class="val"><?= $leave ?></div>
        </div>
        <div class="stat">
            <div class="lbl">Total Recorded</div>
            <div class="val"><?= $total ?></div>
        </div>
        <div class="stat pct">
            <div class="lbl">Attendance %</div>
            <div class="val"><?= $pct === null ? '—' : $pct . '%' ?></div>
        </div>
    </div>

    <div class="cal-wrap">
        <div class="cal-head">
            <div class="cal-title"><?= htmlspecialchars($monthLabel) ?></div>
            <div class="month-nav">
                <a href="?student_id=<?= $student_id ?>&month=<?= date('Y-m', strtotime($monthStart . ' -1 month')) ?>">
                    <i class="fas fa-chevron-left"></i> Prev
                </a>
                <form method="get" style="display:flex;gap:6px;">
                    <input type="hidden" name="student_id" value="<?= $student_id ?>">
                    <input type="month" name="month" value="<?= htmlspecialchars($month) ?>">
                    <button type="submit"><i class="fas fa-search"></i> Go</button>
                </form>
                <a href="?student_id=<?= $student_id ?>&month=<?= date('Y-m', strtotime($monthStart . ' +1 month')) ?>">
                    Next <i class="fas fa-chevron-right"></i>
                </a>
            </div>
        </div>

        <?php if (empty($rows)): ?>
            <div class="empty-state">
                <div class="big">📅</div>
                <h3>No attendance records for <?= htmlspecialchars($monthLabel) ?></h3>
            </div>
        <?php else: ?>
            <div class="cal-grid">
                <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
                    <div class="dow"><?= $d ?></div>
                <?php endforeach; ?>

                <?php for ($i = 0; $i < $firstDayOfWeek; $i++): ?>
                    <div class="cal-cell blank"></div>
                <?php endfor; ?>

                <?php for ($day = 1; $day <= $daysInMonth; $day++):
                    $dateStr = sprintf('%s-%02d', $month, $day);
                    $row = $by_date[$dateStr] ?? null;
                    $cellClass = 'empty';
                    $statusLabel = '—';
                    if ($row) {
                        if ($row['status'] === 'P') { $cellClass = 'present'; $statusLabel = 'Present'; }
                        elseif ($row['status'] === 'L') { $cellClass = 'leave'; $statusLabel = 'Leave'; }
                    }
                ?>
                    <div class="cal-cell <?= $cellClass ?>">
                        <div class="day-num"><?= $day ?></div>
                        <div class="status"><?= $statusLabel ?></div>
                    </div>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

</div>
</body>
</html>
<?php $conn->close(); ?>