<?php
ob_start();
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
if (session_status() === PHP_SESSION_NONE) session_start();

require_once('conn_inc.php');
require_once('grade_helper.php');

function numberToWords($number) {
    $number = (int)$number;
    if ($number < 0) return 'Zero';
    $ones = array(0=>'Zero',1=>'One',2=>'Two',3=>'Three',4=>'Four',5=>'Five',6=>'Six',7=>'Seven',8=>'Eight',9=>'Nine',
        10=>'Ten',11=>'Eleven',12=>'Twelve',13=>'Thirteen',14=>'Fourteen',15=>'Fifteen',16=>'Sixteen',17=>'Seventeen',18=>'Eighteen',19=>'Nineteen');
    $tens = array(2=>'Twenty',3=>'Thirty',4=>'Forty',5=>'Fifty',6=>'Sixty',7=>'Seventy',8=>'Eighty',9=>'Ninety');
    if ($number < 20) return $ones[$number];
    if ($number < 100) {
        $t = (int)($number / 10);
        $o = $number % 10;
        return $tens[$t] . ($o > 0 ? '-' . $ones[$o] : '');
    }
    $h = (int)($number / 100);
    $r = $number % 100;
    $out = $ones[$h] . ' Hundred';
    if ($r > 0) $out .= ' and ' . numberToWords($r);
    return $out;
}

function numberToOrdinal($number) {
    if ($number === 'Not Ranked') return $number;
    $ends = array('th','st','nd','rd','th','th','th','th','th','th');
    if (($number % 100) >= 11 && ($number % 100) <= 13) return $number . 'th';
    return $number . $ends[$number % 10];
}

$student_id      = (int)($_GET['student_id'] ?? 0);
$arrange_exam_id = (int)($_GET['arrange_exam_id'] ?? 0);

if ($student_id <= 0) { echo '<p>Invalid student ID.</p>'; exit; }

$sql = "SELECT sr.*, sc.class_id, c.title AS class_title, s.title AS section_title FROM student_registration sr JOIN student_class sc ON sr.id = sc.student_registration_id LEFT JOIN classes c ON c.id = sc.class_id LEFT JOIN class_sections cs ON cs.class_id = c.id LEFT JOIN sections s ON s.id = cs.section_id WHERE sr.id = ? AND sr.status = 0 AND sc.status = 0 LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) { echo '<p>Student not found.</p>'; exit; }

$class_id = (int)$student['class_id'];

if ($arrange_exam_id <= 0) {
    $q = $conn->query("SELECT id FROM arrange_exam WHERE class_id = " . (int)$class_id . " ORDER BY start_date DESC, id DESC LIMIT 1");
    if ($q && $r = $q->fetch_assoc()) $arrange_exam_id = (int)$r['id'];
}

if ($arrange_exam_id <= 0) { echo '<p>No exam arrangement found for this class.</p>'; exit; }

$sql = "SELECT ae.*, et.title AS exam_type_title, et.exam_kind, s.title AS session_title FROM arrange_exam ae INNER JOIN exam_types et ON et.id = ae.exam_type_id LEFT JOIN sessions s ON s.id = ae.session_id WHERE ae.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $arrange_exam_id);
$stmt->execute();
$arr = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$arr) { echo '<p>Arrangement not found.</p>'; exit; }

/* Load arrangement subjects (new pipeline) */
$arrSubjects = array();
$sql = "SELECT aes.id AS arr_sub_id, aes.subject_id, aes.max_marks, s.title AS subject_title, s.type AS subject_type FROM arrange_exam_subjects aes INNER JOIN subjects s ON s.id = aes.subject_id WHERE aes.arrange_exam_id = " . (int)$arrange_exam_id . " ORDER BY aes.display_order, s.title";
$q = $conn->query($sql);
if ($q) while ($r = $q->fetch_assoc()) {
    $r['sub_subjects'] = array();
    $arrSubjects[(int)$r['arr_sub_id']] = $r;
}

if (!empty($arrSubjects)) {
    $ids = implode(',', array_map('intval', array_keys($arrSubjects)));
    $sql = "SELECT aes.arrange_exam_subject_id, aes.sub_subject_id, aes.max_marks, ss.title AS sub_subject_title FROM arrange_exam_sub_subjects aes INNER JOIN sub_subjects ss ON ss.id = aes.sub_subject_id WHERE aes.arrange_exam_subject_id IN ($ids) ORDER BY aes.display_order, ss.title";
    $q = $conn->query($sql);
    if ($q) while ($r = $q->fetch_assoc()) {
        $arrSubjects[(int)$r['arrange_exam_subject_id']]['sub_subjects'][(int)$r['sub_subject_id']] = $r;
    }
}

/* Load results from BOTH pipelines */
$newResults = array();
$oldResults = array();

$sql = "SELECT r.id, r.arrange_exam_subject_id, r.arrange_exam_sub_subject_id, r.subject_class_id, r.subject_class_sub_id, r.marks, r.max_marks, r.status, r.remarks FROM results r WHERE r.student_id = ? AND r.arrange_exam_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $student_id, $arrange_exam_id);
$stmt->execute();
$rres = $stmt->get_result();
while ($row = $rres->fetch_assoc()) {
    if (!empty($row['arrange_exam_subject_id'])) {
        $asid = (int)$row['arrange_exam_subject_id'];
        $ss   = $row['arrange_exam_sub_subject_id'] !== null ? (int)$row['arrange_exam_sub_subject_id'] : 0;
        $newResults[$asid][$ss] = $row;
    } else {
        $scid = (int)$row['subject_class_id'];
        $scs  = $row['subject_class_sub_id'] !== null ? (int)$row['subject_class_sub_id'] : 0;
        $oldResults[$scid][$scs] = $row;
    }
}
$stmt->close();

/* Build display subjects */
$display_subjects = array();
$total_max = 0.0;
$obtained_total = 0.0;

if (!empty($arrSubjects)) {
    foreach ($arrSubjects as $asid => $as) {
        $subj_row = array(
            'title' => $as['subject_title'],
            'is_composite' => !empty($as['sub_subjects']),
            'total_marks' => 0.0,
            'theory_marks' => 0.0,
            'practical_marks' => 0.0,
            'subject_total' => 0.0,
            'status' => 0,
            'child_subjects' => array(),
        );

        if ($subj_row['is_composite']) {
            $comp_total_max = 0.0;
            $comp_theory = 0.0;
            $comp_practical = 0.0;
            $comp_sum = 0.0;
            $any_absent = false;
            foreach ($as['sub_subjects'] as $ssid => $ss) {
                $row = isset($newResults[$asid][(int)$ssid]) ? $newResults[$asid][(int)$ssid] : null;
                $marks = $row ? (float)$row['marks'] : 0.0;
                $ss_max = (float)$ss['max_marks'];
                $absent = $row && (int)$row['status'] === 1;
                if ($absent) $any_absent = true;
                $is_practical = (stripos($ss['sub_subject_title'], 'practical') !== false);
                $comp_total_max += $ss_max;
                if ($is_practical) $comp_practical += $marks; else $comp_theory += $marks;
                $comp_sum += $marks;
                $subj_row['child_subjects'][] = array(
                    'title' => $ss['sub_subject_title'],
                    'total_marks' => $ss_max,
                    'marks' => $marks,
                    'is_practical' => $is_practical,
                    'status' => $absent ? 1 : 0,
                );
            }
            $subj_row['total_marks'] = $comp_total_max;
            $subj_row['theory_marks'] = $comp_theory;
            $subj_row['practical_marks'] = $comp_practical;
            $subj_row['subject_total'] = $comp_sum;
            $subj_row['status'] = $any_absent ? 1 : 0;
        } else {
            $row = isset($newResults[$asid][0]) ? $newResults[$asid][0] : null;
            $marks = $row ? (float)$row['marks'] : 0.0;
            $absent = $row && (int)$row['status'] === 1;
            $subj_row['total_marks'] = (float)$as['max_marks'];
            $subj_row['subject_total'] = $marks;
            $subj_row['status'] = $absent ? 1 : 0;
        }

        $total_max += (float)$subj_row['total_marks'];
        $obtained_total += (float)$subj_row['subject_total'];
        $display_subjects[] = $subj_row;
    }
} else {
    /* Old pipeline fallback */
    $subj_query = "SELECT s.id AS subject_id, s.title, s.type, sc.id AS subject_class_id, sc.marks AS total_marks FROM subjects s JOIN subject_class sc ON s.id = sc.subject_id WHERE sc.class_id = ? ORDER BY s.id";
    $stmt = $conn->prepare($subj_query);
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $subj_res = $stmt->get_result();
    $old_subjects = array();
    while ($row = $subj_res->fetch_assoc()) {
        $old_subjects[(int)$row['subject_class_id']] = array(
            'title' => $row['title'],
            'type' => $row['type'],
            'total_marks' => (float)$row['total_marks'],
            'child_subjects' => array(),
        );
    }
    $stmt->close();

    if (!empty($old_subjects)) {
        $ids = implode(',', array_map('intval', array_keys($old_subjects)));
        $q = $conn->query("SELECT scs.subject_class_id, scs.sub_subject_id, scs.marks AS max_marks, ss.title FROM subject_class_sub scs INNER JOIN sub_subjects ss ON ss.id = scs.sub_subject_id WHERE scs.subject_class_id IN ($ids)");
        if ($q) while ($r = $q->fetch_assoc()) {
            $scid = (int)$r['subject_class_id'];
            if (isset($old_subjects[$scid])) {
                $old_subjects[$scid]['child_subjects'][(int)$r['sub_subject_id']] = $r;
            }
        }
    }

    foreach ($old_subjects as $scid => $os) {
        $subj_row = array(
            'title' => $os['title'],
            'is_composite' => !empty($os['child_subjects']),
            'total_marks' => 0.0,
            'theory_marks' => 0.0,
            'practical_marks' => 0.0,
            'subject_total' => 0.0,
            'status' => 0,
            'child_subjects' => array(),
        );

        if ($subj_row['is_composite']) {
            $comp_total_max = 0.0;
            $comp_theory = 0.0;
            $comp_practical = 0.0;
            $comp_sum = 0.0;
            $any_absent = false;
            foreach ($os['child_subjects'] as $ssid => $ss) {
                $row = isset($oldResults[$scid][(int)$ssid]) ? $oldResults[$scid][(int)$ssid] : null;
                $marks = $row ? (float)$row['marks'] : 0.0;
                $ss_max = (float)$ss['max_marks'];
                $absent = $row && (int)$row['status'] === 1;
                if ($absent) $any_absent = true;
                $is_practical = (stripos($ss['title'], 'practical') !== false);
                $comp_total_max += $ss_max;
                if ($is_practical) $comp_practical += $marks; else $comp_theory += $marks;
                $comp_sum += $marks;
                $subj_row['child_subjects'][] = array(
                    'title' => $ss['title'],
                    'total_marks' => $ss_max,
                    'marks' => $marks,
                    'is_practical' => $is_practical,
                    'status' => $absent ? 1 : 0,
                );
            }
            $subj_row['total_marks'] = $comp_total_max;
            $subj_row['theory_marks'] = $comp_theory;
            $subj_row['practical_marks'] = $comp_practical;
            $subj_row['subject_total'] = $comp_sum;
            $subj_row['status'] = $any_absent ? 1 : 0;
        } else {
            $row = isset($oldResults[$scid][0]) ? $oldResults[$scid][0] : null;
            $marks = $row ? (float)$row['marks'] : 0.0;
            $absent = $row && (int)$row['status'] === 1;
            $subj_row['total_marks'] = (float)$os['total_marks'];
            $subj_row['subject_total'] = $marks;
            $subj_row['status'] = $absent ? 1 : 0;
        }

        $total_max += (float)$subj_row['total_marks'];
        $obtained_total += (float)$subj_row['subject_total'];
        $display_subjects[] = $subj_row;
    }
}

$percentage = $total_max > 0 ? round(($obtained_total / $total_max) * 100, 2) : 0.0;
$grade_info = grade_for_pct($conn, $percentage);
$grade = $grade_info['grade'];
$remark = $grade_info['remark'];

/* Position */
$student_position = 'Not Ranked';
$sql = "SELECT r.student_id, SUM(r.marks) AS total_obtained FROM results r INNER JOIN student_class sc ON r.student_id = sc.student_registration_id WHERE sc.class_id = ? AND r.arrange_exam_id = ? GROUP BY r.student_id ORDER BY total_obtained DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $class_id, $arrange_exam_id);
$stmt->execute();
$pos_res = $stmt->get_result();
$rank = 1; $prev = null;
while ($row = $pos_res->fetch_assoc()) {
    if ($prev !== null && (float)$row['total_obtained'] < (float)$prev) $rank++;
    if ((int)$row['student_id'] === $student_id) {
        $student_position = ((float)$row['total_obtained'] > 0) ? $rank : 'Not Ranked';
        break;
    }
    $prev = $row['total_obtained'];
}
$stmt->close();

$exam_year = date('Y', strtotime($arr['start_date']));
$result_declaration_date = numberToOrdinal((int)date('j')) . '-' . date('F') . '-' . date('Y');

$classTitle = trim((string)($student['class_title'] ?? ''));
$classKey = strtolower(preg_replace('/\s+/', ' ', $classTitle));
$showParentSet = array('play group','nursery','grade prep');
$hideParentSet = array('grade 1','grade 2','grade 3','grade 4','grade 4 hifz','grade 5','grade 5 hifz','grade 6','grade 6 hifz','grade 7','grade 7 hifz','grade 8','grade 9');
if (in_array($classKey, $showParentSet, true)) $showCompositeParentTitle = true;
elseif (in_array($classKey, $hideParentSet, true)) $showCompositeParentTitle = false;
else $showCompositeParentTitle = true;
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>DMC - <?= htmlspecialchars($student['name']) ?></title>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<style>
    @media print {
        body { position: relative; }
        body::before {
            content: '';
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background-image: url('logo2.png');
            background-repeat: repeat; background-size: 70px auto;
            opacity: 0.04; z-index: -1;
        }
        .no-print { display: none !important; }
    }
    body { font-family: Arial, sans-serif; font-size: 18px; line-height: 1.15; margin: 6px; padding: 0; color: #000; background: #fff; }
    .dmc-container { max-width: 900px; margin: 0 auto; page-break-after: always; page-break-inside: avoid; }
    .header { text-align: center; margin-bottom: 6px; }
    .header .logo-text-qr { display: flex; align-items: center; justify-content: center; }
    .header img.logo { width: 60px; margin-right: 8px; }
    .header .board-text { font-size: 18px; font-weight: bold; color: #000; text-align: left; line-height: 1.1; flex-grow: 1; }
    canvas.qr { width: 150px; height: 60px; }
    .certificate-title { font-weight: bold; text-decoration: underline; margin: 6px 0; font-size: 20px; text-align: center; }
    table.info { width: 100%; margin-bottom: 6px; border-collapse: collapse; }
    table.info td { padding: 2px 0; vertical-align: top; }
    table.info td.info-cell { width: 45%; }
    table.info td.photo-cell { width: 50px; padding-left: 6px; }
    table.info td.photo-cell img { width: 50px; height: 60px; border: 1px solid #000; }
    table.marks { width: 100%; border-collapse: collapse; margin-top: 4px; font-size: 15px; }
    table.marks th, table.marks td { border: 1px solid #000; text-align: center; padding: 3px 4px; }
    table.marks th { font-weight: bold; font-size: 13px; background: #f5f5f5; }
    .sub-subject-row td { padding-left: 15px; }
    .composite-header td { font-weight: bold; background: #f5f5f5; text-align: left; padding-left: 6px; }
    .absent-cell { color: #b91c1c; font-weight: bold; }
    .result-summary { border: 1px solid #000; padding: 6px 10px; box-sizing: border-box; float: right; margin-top: 10px; text-align: left; background: #fff; font-size: 15px; }
    .footer { margin-top: 45px; font-size: 14px; clear: both; }
    .signature { text-align: right; font-weight: bold; font-size: 14px; margin-top: 12px; }
    .chart-container { margin: 8px 0; text-align: center; page-break-inside: avoid; }
    .compact-row { margin: 4px 0; }
    .btn { display: inline-block; padding: 8px 16px; font-size: 14px; border-radius: 4px; text-decoration: none; border: 1px solid; }
    .btn-primary { background: #1e40af; color: #fff; border-color: #1e40af; }
    .btn-success { background: #059669; color: #fff; border-color: #059669; }
</style>
</head>
<body>

<div class="no-print" style="max-width:900px;margin:12px auto;padding:0 12px;">
    <a href="dmc_list.php?arrange_exam_id=<?= (int)$arrange_exam_id ?>" class="btn btn-primary">Back to DMC List</a>
    <button onclick="window.print();" class="btn btn-success" style="float:right;">Print DMC</button>
</div>

<div class="dmc-container">

    <div class="header">
        <div class="logo-text-qr">
            <img src="logo2.png" alt="Logo" class="logo">
            <div class="board-text">
                SCHOOL MANAGEMENT SYSTEM<br>DAR-E-ARQAM, MATTA SWAT
            </div>
        </div>
    </div>

    <div class="compact-row"><b>S. No. SB.</b> ___________</div>

    <div style="text-align:center;margin:6px 0;">
        <div class="certificate-title">DETAILED MARKS CERTIFICATE</div>
        <div><?= strtoupper(htmlspecialchars($arr['exam_type_title'])) ?> EXAMINATION, <?= htmlspecialchars($exam_year) ?></div>
    </div>

    <table class="info">
        <tr>
            <td class="info-cell"><b>Name:</b> <?= htmlspecialchars($student['name']) ?></td>
            <td class="info-cell"><b>Roll No:</b> <?= (int)$student['id'] ?></td>
            <td class="photo-cell" rowspan="4"><img src="Uploads/students/students.jpeg" alt="Photo"></td>
        </tr>
        <tr><td class="info-cell" colspan="2"><b>Father's Name:</b> <?= htmlspecialchars($student['father_name'] ?: '-') ?></td></tr>
        <tr><td class="info-cell" colspan="2"><b>Candidate of:</b> School Management System</td></tr>
        <tr><td class="info-cell" colspan="2"><b>Class:</b> <?= htmlspecialchars($student['class_title'] ?? '-') ?></td></tr>
        <tr><td class="info-cell" colspan="2"><b>Position in Class:</b> <span style="font-weight:bolder;"><?= numberToOrdinal($student_position) ?></span></td></tr>
    </table>

    <?php if (empty($display_subjects)): ?>
        <p style="text-align:center;color:#991b1b;font-weight:bold;margin-top:20px;">No marks recorded for this student in this exam.</p>
    <?php else: ?>

    <table class="marks">
        <thead>
            <tr>
                <th rowspan="2" width="30%">Subjects</th>
                <th rowspan="2" width="10%">Max Marks</th>
                <th colspan="3">Marks Obtained</th>
                <th rowspan="2" width="22%">Marks in Words</th>
            </tr>
            <tr>
                <th width="10%">Theory</th>
                <th width="10%">Practical</th>
                <th width="10%">Total</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $childPadding = $showCompositeParentTitle ? '15px' : '5px';
        foreach ($display_subjects as $sub):
            if ($sub['is_composite'] && !empty($sub['child_subjects'])):
                if ($showCompositeParentTitle):
                    echo "<tr class='composite-header'><td colspan='6'>" . htmlspecialchars($sub['title']) . "</td></tr>";
                endif;
                foreach ($sub['child_subjects'] as $child):
                    $is_absent = ((int)$child['status'] === 1);
                    $m = $child['marks'];
                    $mw = $is_absent ? 'Absent' : ucfirst(numberToWords($m));
                    echo "<tr class='sub-subject-row'>";
                    echo "<td style='text-align:left;padding-left:" . $childPadding . ";'>" . htmlspecialchars($child['title']) . "</td>";
                    echo "<td>" . (int)$child['total_marks'] . "</td>";
                    echo "<td>" . ($child['is_practical'] ? '--' : ($is_absent ? "<span class='absent-cell'>Absent</span>" : (($m != 0) ? $m : '--'))) . "</td>";
                    echo "<td>" . ($child['is_practical'] ? ($is_absent ? "<span class='absent-cell'>Absent</span>" : (($m != 0) ? $m : '--')) : '--') . "</td>";
                    echo "<td>" . ($is_absent ? "<span class='absent-cell'>Absent</span>" : $m) . "</td>";
                    echo "<td style='text-align:left;padding-left:" . $childPadding . ";'>" . $mw . "</td>";
                    echo "</tr>";
                endforeach;
            else:
                $is_absent = ((int)$sub['status'] === 1);
                $m = $sub['subject_total'];
                $mw = $is_absent ? 'Absent' : ucfirst(numberToWords($m));
                echo "<tr>";
                echo "<td style='text-align:left;padding-left:5px;'>" . htmlspecialchars($sub['title']) . "</td>";
                echo "<td>" . (int)$sub['total_marks'] . "</td>";
                echo "<td>" . ($is_absent ? "<span class='absent-cell'>Absent</span>" : (($sub['theory_marks'] != 0) ? $sub['theory_marks'] : '--')) . "</td>";
                echo "<td>" . ($is_absent ? "<span class='absent-cell'>Absent</span>" : (($sub['practical_marks'] != 0) ? $sub['practical_marks'] : '--')) . "</td>";
                echo "<td>" . ($is_absent ? "<span class='absent-cell'>Absent</span>" : $m) . "</td>";
                echo "<td style='text-align:left;padding-left:5px;'>" . $mw . "</td>";
                echo "</tr>";
            endif;
        endforeach;
        ?>
        <tr>
            <td style="text-align:right;padding-right:6px;font-weight:bold;">Total :</td>
            <td style="font-weight:bold;"><?= (int)$total_max ?></td>
            <td></td>
            <td></td>
            <td style="font-weight:bold;"><?= $obtained_total ?></td>
            <td style="text-align:left;padding-left:6px;font-weight:bold;"><?= ucfirst(numberToWords($obtained_total)) ?> Only</td>
        </tr>
        </tbody>
    </table>

    <div class="result-summary">
        <b>Percentage:</b> <?= number_format($percentage, 2) ?>%<br>
        <b>Grade:</b> <?= htmlspecialchars($grade) ?><br>
        <b>Remarks:</b> <?= htmlspecialchars($remark) ?>
    </div>

    <?php
    $bar_width = 15; $spacing = 6;
    $group_width = (2 * $bar_width) + $spacing;
    $chart_width = 420;
    $num_subjects = count($display_subjects);
    $available_width = $chart_width - ($num_subjects * $group_width);
    $group_spacing = $num_subjects ? $available_width / ($num_subjects + 1) : 0;
    $x_pos = 40 + $group_spacing;
    $all_max = array();
    foreach ($display_subjects as $ds) { $all_max[] = (float)$ds['total_marks']; }
    $max_marks_for_chart = max(array_merge($all_max, array(100)));
    $scale_factor = $max_marks_for_chart > 0 ? (90 / $max_marks_for_chart) : 0;
    ?>
    <div class="chart-container">
        <svg width="500" height="150" style="display:block;margin:0 auto;">
            <line x1="40" y1="120" x2="460" y2="120" stroke="black" stroke-width="1" />
            <line x1="40" y1="120" x2="40" y2="30" stroke="black" stroke-width="1" />
            <?php
            foreach ($display_subjects as $ds):
                $label = substr($ds['title'], 0, 10) . (strlen($ds['title']) > 10 ? '...' : '');
                $obt_h = ((int)$ds['status'] === 1 ? 0 : (float)$ds['subject_total']) * $scale_factor;
                $tot_h = (float)$ds['total_marks'] * $scale_factor;
                echo "<rect x='$x_pos' y='" . (120 - $obt_h) . "' width='$bar_width' height='$obt_h' fill='#1e40af' />";
                echo "<rect x='" . ($x_pos + $bar_width + $spacing) . "' y='" . (120 - $tot_h) . "' width='$bar_width' height='$tot_h' fill='#d1d3e2' />";
                $text_x = $x_pos + $bar_width / 2;
                echo "<text x='$text_x' y='132' text-anchor='middle' font-size='8'>" . htmlspecialchars($label) . "</text>";
                $x_pos += $group_width + $group_spacing;
            endforeach;
            ?>
            <rect x="400" y="20" width="10" height="10" fill="#1e40af" />
            <text x="415" y="29" font-size="9">Obtained</text>
            <rect x="400" y="40" width="10" height="10" fill="#d1d3e2" />
            <text x="415" y="49" font-size="9">Total</text>
        </svg>
    </div>

    <div class="footer">
        Prepared and Checked by Computer Cell<br>
        School Management System<br>
        Result Declaration Date: <?= htmlspecialchars($result_declaration_date) ?><br>
        <i>Note: Errors / Omissions are subject to subsequent rectification.</i>
    </div>
    <div class="signature">
        Controller of Examinations,<br>
        School Management System
    </div>

    <canvas id="barcode-<?= (int)$student['id'] ?>" class="qr"></canvas>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        try {
            JsBarcode("#barcode-<?= (int)$student['id'] ?>", "<?= (int)$student['id'] ?>", {
                format: "CODE39", width: 1, height: 50, displayValue: false,
                background: "#ffffff", lineColor: "#000000"
            });
        } catch (e) { console.error(e); }
    });
    </script>

    <?php endif; ?>

</div>

</body>
</html>
<?php
$conn->close();
ob_end_flush();
?>