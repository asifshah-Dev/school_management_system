<?php
ob_start();
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
if (session_status() === PHP_SESSION_NONE) session_start();

require_once('conn_inc.php');

$teacher_id = (int)($_GET['teacher_id'] ?? 0);

/* Teachers dropdown */
$teachers = [];
$q = $conn->query("
    SELECT id, username FROM users
    WHERE role_id = 3 AND (status = 0 OR status IS NULL)
    ORDER BY username
");
if ($q) { $teachers = $q->fetch_all(MYSQLI_ASSOC); }

/* Time slots */
$time_slots = [];
$q = $conn->query("SELECT id, title, start_time, end_time FROM time_slots ORDER BY start_time");
if ($q) { $time_slots = $q->fetch_all(MYSQLI_ASSOC); }

$days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

/* Grid: [day_id][slot_id] = row */
$grid = [];
$teacher_name = '';
$row_count = 0;

if ($teacher_id > 0) {
    $stmt = $conn->prepare("
        SELECT t.day_id, t.time_slot_id, t.room_no,
               c.title AS class_title,
               cs.id AS csid,
               s.title AS section_title,
               sub.title AS subject_title
        FROM timetable t
        INNER JOIN classes c ON c.id = t.class_id
        LEFT JOIN class_sections cs ON cs.id = t.class_section_id
        LEFT JOIN sections s ON s.id = cs.section_id
        LEFT JOIN subjects sub ON sub.id = t.subject_id
        WHERE t.teacher_id = ?
    ");
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $row['class_label'] = $row['class_title']
            . ($row['section_title'] ? ' - ' . $row['section_title'] : '');
        $grid[(int)$row['day_id']][(int)$row['time_slot_id']] = $row;
        $row_count++;
    }
    $stmt->close();

    foreach ($teachers as $t) {
        if ((int)$t['id'] === $teacher_id) { $teacher_name = $t['username']; break; }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Teacher Timetable — Dar-e-Arqm</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #eef1f5; font-family: 'Inter', sans-serif; color: #1e293b; }
    .wrap { max-width: 1400px; margin: 30px auto; padding: 0 20px; }

    .head {
        background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
        color: #fff; padding: 26px 32px;
        border-radius: 12px 12px 0 0;
        display: flex; justify-content: space-between; align-items: flex-end;
        gap: 20px; flex-wrap: wrap;
    }
    .head h1 { font-size: 26px; font-weight: 700; }
    .head .sub { font-size: 14px; opacity: 0.9; margin-top: 6px; }
    .head .hb {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 9px 16px; border-radius: 8px; font-size: 13px; font-weight: 700;
        text-decoration: none; cursor: pointer; font-family: inherit;
        border: 1px solid rgba(255,255,255,0.3);
        background: rgba(255,255,255,0.15); color: #fff;
    }
    .head .hb:hover { background: #fff; color: #1e40af; border-color: #fff; text-decoration: none; }

    .card {
        background: #fff; padding: 24px 28px 30px 28px;
        border-radius: 0 0 12px 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    }

    .selector {
        display: flex; gap: 14px; align-items: flex-end; flex-wrap: wrap;
        padding-bottom: 20px; margin-bottom: 22px;
        border-bottom: 1px solid #e2e8f0;
    }
    .selector .field { min-width: 300px; }
    .selector label {
        display: block; font-size: 11px; font-weight: 700; color: #64748b;
        text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px;
    }
    .selector select {
        width: 100%; height: 44px; padding: 0 14px;
        border: 1px solid #cbd5e1; border-radius: 10px;
        font-size: 14px; font-family: inherit; background: #fff;
    }
    .selector select:focus {
        outline: none; border-color: #1e40af;
        box-shadow: 0 0 0 4px rgba(30,64,175,0.1);
    }

    .print-header { text-align: center; margin-bottom: 14px; padding-bottom: 12px; border-bottom: 2px solid #1e3a8a; }
    .print-header img { height: 70px; margin-bottom: 6px; }
    .print-header h2 { font-size: 22px; color: #1e3a8a; margin: 6px 0; }
    .print-header p { font-size: 13px; color: #475569; }

    .tbl-wrap { overflow-x: auto; border-radius: 10px; border: 1px solid #e2e8f0; }
    table.tt { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 1000px; }
    table.tt thead th {
        background: #1e3a8a; color: #fff;
        font-size: 12px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px;
        padding: 14px 10px; text-align: center;
        border-right: 1px solid rgba(255,255,255,0.15);
    }
    table.tt thead th:last-child { border-right: none; }
    table.tt tbody td {
        padding: 12px 10px; font-size: 12px;
        border-right: 1px solid #f1f5f9; border-bottom: 1px solid #f1f5f9;
        vertical-align: top; background: #fff;
    }
    table.tt tbody td.period-cell {
        background: #f8fafc; font-weight: 700; color: #0f172a; text-align: left;
        border-right: 1px solid #e2e8f0; min-width: 130px;
    }
    .period-time { font-size: 10px; color: #94a3b8; margin-top: 3px; font-family: 'SF Mono', monospace; font-weight: 400; }
    .cls     { font-weight: 700; color: #0f172a; }
    .subject { color: #1e40af; font-size: 11px; margin-top: 2px; font-weight: 600; }
    .room    { color: #94a3b8; font-size: 11px; margin-top: 2px; }
    .empty-cell { color: #cbd5e1; font-style: italic; font-size: 11px; }

    .empty { padding: 60px 20px; text-align: center; color: #94a3b8; }
    .empty .big { font-size: 44px; opacity: 0.4; margin-bottom: 10px; }
    .empty h3 { font-size: 17px; color: #475569; font-weight: 600; }

    @media print {
        @page { margin: 10mm; size: A4 landscape; }
        body { background: #fff; }
        .wrap { margin: 0; padding: 0; max-width: none; }
        .head, .selector, .no-print { display: none !important; }
        .card { box-shadow: none; padding: 0; }
        .tbl-wrap { border: 1px solid #333; overflow: visible; }
        table.tt { min-width: 0; }
        table.tt thead th { background: #1e3a8a !important; color: #fff !important; padding: 8px 5px; font-size: 10px; }
        table.tt tbody td { padding: 8px 5px; font-size: 10px; }
        table.tt tbody td.period-cell { background: #f1f5f9 !important; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">
    <div class="head no-print">
        <div>
            <h1><i class="fas fa-chalkboard-teacher" style="opacity:0.8;"></i> Teacher Timetable</h1>
            <div class="sub">
                <?= $teacher_name ? htmlspecialchars($teacher_name) . ' · ' . $row_count . ' slot(s)' : 'Pick a teacher to view their weekly schedule' ?>
            </div>
        </div>
        <?php if ($teacher_id > 0): ?>
        <div>
            <button type="button" class="hb" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
        </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <form method="get" class="selector no-print">
            <div class="field">
                <label>Teacher</label>
                <select name="teacher_id" onchange="this.form.submit()">
                    <option value="">— Select a teacher —</option>
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= $teacher_id === (int)$t['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <?php if ($teacher_id === 0): ?>
            <div class="empty">
                <div class="big">👨‍🏫</div>
                <h3>Pick a teacher to view their timetable</h3>
            </div>
        <?php elseif (empty($time_slots)): ?>
            <div class="empty">
                <div class="big">⏰</div>
                <h3>No time slots configured yet</h3>
            </div>
        <?php else: ?>
            <div class="print-header">
                
                <h2>Teacher Timetable</h2>
                <p>
                    <strong>Teacher:</strong> <?= htmlspecialchars($teacher_name) ?>
                    &nbsp;·&nbsp;
                    <strong>Date:</strong> <?= date('d M Y') ?>
                </p>
            </div>

            <div class="tbl-wrap">
                <table class="tt">
                    <thead>
                        <tr>
                            <th>Period</th>
                            <?php foreach ($days as $day): ?>
                                <th><?= htmlspecialchars($day) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($time_slots as $slot):
                        $slot_id = (int)$slot['id'];
                    ?>
                        <tr>
                            <td class="period-cell">
                                <?= htmlspecialchars($slot['title'] ?: ('Period ' . $slot_id)) ?>
                                <div class="period-time"><?= date('H:i', strtotime($slot['start_time'])) ?>–<?= date('H:i', strtotime($slot['end_time'])) ?></div>
                            </td>
                            <?php foreach ($days as $di => $day):
                                $day_id = $di + 1;
                                $row = $grid[$day_id][$slot_id] ?? null;
                            ?>
                                <td>
                                    <?php if ($row && ($row['class_title'] || $row['subject_title'] || $row['room_no'])): ?>
                                        <?php if ($row['class_label']): ?>
                                            <div class="cls"><?= htmlspecialchars($row['class_label']) ?></div>
                                        <?php endif; ?>
                                        <?php if ($row['subject_title']): ?>
                                            <div class="subject"><?= htmlspecialchars($row['subject_title']) ?></div>
                                        <?php endif; ?>
                                        <?php if ($row['room_no']): ?>
                                            <div class="room">Rm <?= htmlspecialchars($row['room_no']) ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="empty-cell">—</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
<?php $conn->close(); ob_end_flush(); ?>