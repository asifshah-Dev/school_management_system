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

// Group results by arrange_exam
$stmt = $conn->prepare("
    SELECT ae.id AS arrange_exam_id,
           ae.start_date,
           ae.exam_type_id,
           et.title AS exam_title,
           r.subject_name,
           r.marks,
           r.max_marks
    FROM results r
    INNER JOIN arrange_exam ae ON ae.id = r.arrange_exam_id
    LEFT JOIN exam_types et ON et.id = ae.exam_type_id
    WHERE r.student_id = ?
    ORDER BY ae.start_date DESC, ae.id DESC, r.subject_name ASC
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$raw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$exams = [];
foreach ($raw as $r) {
    $eid = (int)$r['arrange_exam_id'];
    if (!isset($exams[$eid])) {
        $exams[$eid] = [
            'arrange_exam_id' => $eid,
            'exam_title' => $r['exam_title'] ?: ('Exam #' . $eid),
            'start_date' => $r['start_date'],
            'subjects' => [],
            'total_scored' => 0,
            'total_max' => 0,
        ];
    }
    $exams[$eid]['subjects'][] = [
        'subject_name' => $r['subject_name'],
        'marks' => (float)$r['marks'],
        'max_marks' => (float)$r['max_marks'],
    ];
    $exams[$eid]['total_scored'] += (float)$r['marks'];
    $exams[$eid]['total_max']    += (float)$r['max_marks'];
}

$initials = strtoupper(substr(preg_replace('/\s+/', '', $student['name'] ?: 'S'), 0, 2));

function grade_for($pct) {
    if ($pct >= 90) return 'A+';
    if ($pct >= 80) return 'A';
    if ($pct >= 70) return 'B';
    if ($pct >= 60) return 'C';
    if ($pct >= 50) return 'D';
    return 'F';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Results — <?= htmlspecialchars($student['name']) ?></title>
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

    .exam-block { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 18px; overflow: hidden; }
    .exam-head {
        padding: 18px 22px; background: #fafbfc; border-bottom: 1px solid #f1f5f9;
        display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;
    }
    .exam-head .title { font-size: 16px; font-weight: 700; color: #0f172a; }
    .exam-head .date  { font-size: 12px; color: #64748b; margin-top: 3px; }
    .exam-summary { display: flex; gap: 20px; align-items: center; }
    .exam-summary .big { font-size: 22px; font-weight: 700; font-family: 'SF Mono', monospace; color: #1e40af; }
    .exam-summary .pct { font-size: 12px; color: #64748b; text-align: right; }

    .subj-table { width: 100%; border-collapse: collapse; }
    .subj-table th {
        background: #fff; padding: 12px 22px; text-align: left;
        font-size: 10px; font-weight: 700; color: #64748b;
        text-transform: uppercase; letter-spacing: 1px;
        border-bottom: 1px solid #f1f5f9;
    }
    .subj-table td {
        padding: 12px 22px; font-size: 13px; color: #1e293b;
        border-bottom: 1px solid #f8fafc;
    }
    .subj-table tr:last-child td { border-bottom: none; }
    .subj-table .num { text-align: right; font-family: 'SF Mono', monospace; font-weight: 600; }
    .subj-table .grade-cell { text-align: center; }

    .grade-pill {
        display: inline-block; min-width: 34px; padding: 3px 8px; border-radius: 8px;
        font-size: 12px; font-weight: 700; text-align: center;
    }
    .grade-pill.aplus { background: #d1fae5; color: #065f46; }
    .grade-pill.a     { background: #d1fae5; color: #065f46; }
    .grade-pill.b     { background: #dbeafe; color: #1e40af; }
    .grade-pill.c     { background: #fef3c7; color: #92400e; }
    .grade-pill.d     { background: #ffedd5; color: #9a3412; }
    .grade-pill.f     { background: #fee2e2; color: #991b1b; }

    .empty-state { background: #fff; border-radius: 12px; padding: 50px 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
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

<div class="container" style="padding: 20px; max-width: 900px; margin: 0 auto;">

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

    <?php if (empty($exams)): ?>
        <div class="empty-state">
            <div class="big">📊</div>
            <h3>No exam results yet</h3>
        </div>
    <?php else: ?>
        <?php foreach ($exams as $ex): ?>
            <?php
                $pct = $ex['total_max'] > 0 ? round(($ex['total_scored'] / $ex['total_max']) * 100, 1) : 0;
                $overallGrade = grade_for($pct);
                $overallClass = strtolower($overallGrade) === 'a+' ? 'aplus' : strtolower($overallGrade);
            ?>
            <div class="exam-block">
                <div class="exam-head">
                    <div>
                        <div class="title"><?= htmlspecialchars($ex['exam_title']) ?></div>
                        <div class="date"><i class="fas fa-calendar"></i> <?= htmlspecialchars($ex['start_date']) ?></div>
                    </div>
                    <div class="exam-summary">
                        <div style="text-align:right;">
                            <div class="big"><?= number_format($ex['total_scored'], 0) ?> / <?= number_format($ex['total_max'], 0) ?></div>
                            <div class="pct"><?= $pct ?>%</div>
                        </div>
                        <span class="grade-pill <?= $overallClass ?>" style="font-size: 16px; padding: 6px 14px;"><?= $overallGrade ?></span>
                    </div>
                </div>
                <table class="subj-table">
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th class="num" style="text-align:right;">Marks</th>
                            <th class="num" style="text-align:right;">Out of</th>
                            <th class="num" style="text-align:right;">%</th>
                            <th class="grade-cell">Grade</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ex['subjects'] as $sub): ?>
                            <?php
                                $subMax = $sub['max_marks'] > 0 ? $sub['max_marks'] : 100;
                                $subPct = round(($sub['marks'] / $subMax) * 100, 1);
                                $g = grade_for($subPct);
                                $gc = strtolower($g) === 'a+' ? 'aplus' : strtolower($g);
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($sub['subject_name']) ?></td>
                                <td class="num"><?= number_format($sub['marks'], 1) ?></td>
                                <td class="num"><?= number_format($sub['max_marks'], 1) ?></td>
                                <td class="num"><?= $subPct ?>%</td>
                                <td class="grade-cell"><span class="grade-pill <?= $gc ?>"><?= $g ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>
</body>
</html>
<?php $conn->close(); ?>