<?php
ob_start();
try {
    require_once('security.php');
    require_once('conn_inc.php');

    function getGrade($percentage) {
        if ($percentage >= 90) {
            return 'E';
        } elseif ($percentage >= 71 && $percentage <= 89) {
            return 'M';
        } elseif ($percentage >= 61 && $percentage <= 70) {
            return 'A';
        } else {
            return 'N';
        }
    }

    function getGradeRemark($percentage) {
        if ($percentage >= 90) {
            return 'Exceeding Expectations';
        } elseif ($percentage >= 71 && $percentage <= 89) {
            return 'Meeting Expectations';
        } elseif ($percentage >= 61 && $percentage <= 70) {
            return 'Approaching Expectations';
        } else {
            return 'Not Yet Meeting Expectations';
        }
    }

    function numberToWords($number) {
        try {
            $number = intval($number);
            if ($number === null || $number < 0) {
                return "Zero";
            }
            $ones = array(
                0 => "Zero", 1 => "One", 2 => "Two", 3 => "Three", 4 => "Four",
                5 => "Five", 6 => "Six", 7 => "Seven", 8 => "Eight", 9 => "Nine",
                10 => "Ten", 11 => "Eleven", 12 => "Twelve", 13 => "Thirteen", 14 => "Fourteen",
                15 => "Fifteen", 16 => "Sixteen", 17 => "Seventeen", 18 => "Eighteen", 19 => "Nineteen"
            );
            $tens = array(
                2 => "Twenty", 3 => "Thirty", 4 => "Forty", 5 => "Fifty",
                6 => "Sixty", 7 => "Seventy", 8 => "Eighty", 9 => "Ninety"
            );
            if ($number < 20) {
                return $ones[$number];
            } elseif ($number < 100) {
                $tens_digit = floor($number / 10);
                $ones_digit = $number % 10;
                return $tens[$tens_digit] . ($ones_digit > 0 ? "-" . $ones[$ones_digit] : "");
            } else {
                $hundreds = floor($number / 100);
                $remainder = $number % 100;
                $result = $ones[$hundreds] . " Hundred";
                if ($remainder > 0) {
                    $result .= " and " . numberToWords($remainder);
                }
                return $result;
            }
        } catch (Exception $e) {
            error_log("Error in numberToWords for input $number: " . $e->getMessage());
            return "Unknown";
        }
    }

    // Function to convert number to ordinal (1st, 2nd, 3rd, etc.)
    function numberToOrdinal($number) {
        if ($number == 'Not Ranked') {
            return $number;
        }
        $ends = ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'];
        if (($number % 100) >= 11 && ($number % 100) <= 13) {
            return $number . 'th';
        }
        return $number . $ends[$number % 10];
    }

    $arrange_exam_id = isset($_GET['arrange_exam_id']) ? intval($_GET['arrange_exam_id']) : 0;
    if (!$arrange_exam_id) {
        throw new Exception("Invalid arrange_exam_id");
    }

    // Get class_id from arrange_exam
    $class_stmt = $conn->prepare("SELECT class_id FROM arrange_exam WHERE id = ?");
    $class_stmt->bind_param("i", $arrange_exam_id);
    $class_stmt->execute();
    $class_res = $class_stmt->get_result();
    if (!$class_row = $class_res->fetch_assoc()) {
        throw new Exception("No class found for this arrange_exam_id");
    }
    $class_id = $class_row['class_id'];
    $class_stmt->close();

    // Class details
    $class_query = "
        SELECT c.title as class_title, s.title as section_title
        FROM classes c
        LEFT JOIN class_sections cs ON cs.class_id = c.id
        LEFT JOIN sections s ON s.id = cs.section_id
        WHERE c.id = ?
    ";
    $stmt = $conn->prepare($class_query);
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $class_result = $stmt->get_result();
    $class_details = $class_result->fetch_assoc();
    $stmt->close();

    if (!$class_details) {
        throw new Exception("No class found for this ID");
    }

    // Class-based toggle for composite parent title visibility
    $classTitle = trim((string)($class_details['class_title'] ?? ''));
    $classKey = strtolower(preg_replace('/\s+/', ' ', $classTitle));

    $showParentSet = [
        'play group',
        'nursery',
        'grade prep',
    ];

    $hideParentSet = [
        'grade 1','grade 2','grade 3',
        'grade 4','grade 4 hifz',
        'grade 5','grade 5 hifz',
        'grade 6','grade 6 hifz',
        'grade 7','grade 7 hifz',
        'grade 8','grade 9',
    ];

    if (in_array($classKey, $showParentSet, true)) {
        $showCompositeParentTitle = true;
    } elseif (in_array($classKey, $hideParentSet, true)) {
        $showCompositeParentTitle = false;
    } else {
        $showCompositeParentTitle = true;
    }

    // Students
    $students_query = "
        SELECT sr.id, sr.name, sr.father_name, sr.reg_no, sc.class_id
        FROM student_registration sr
        JOIN student_class sc ON sr.id = sc.student_registration_id
        join sessions s on s.id = sc.session_id
        WHERE sc.class_id = ? and sr.status = 0 and sc.status = 0
    ";
    $stmt = $conn->prepare($students_query);
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $students_result = $stmt->get_result();
    $students = $students_result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($students)) {
        throw new Exception("No students found for this class-section");
    }

    // Date
    $current_date = new DateTime();
    $day = $current_date->format('j');
    $month = $current_date->format('F');
    $year = $current_date->format('Y');
    $result_declaration_date = numberToOrdinal($day) . '-' . $month . '-' . $year;

    // Compute per-student
    $computed_students = [];
    foreach ($students as $student) {
        $student_id = $student['id'];

        // ─── IMPORTANT FIX ─── reset totals to numbers for each student
        $total_marks_sum_for_class = 0;
        $obtained_marks = 0;

        $subjects = [];
        $display_subjects = [];

        // All subjects for class
        $subjects_query = "
            SELECT 
                s.id as subject_id,
                s.title, 
                s.type, 
                sc.id as subject_class_id,
                sc.marks as total_marks
            FROM subjects s
            JOIN subject_class sc ON s.id = sc.subject_id
            WHERE sc.class_id = ?
            ORDER BY s.id
        ";
        $stmt = $conn->prepare($subjects_query);
        $stmt->bind_param("i", $class_id);
        $stmt->execute();
        $subjects_result = $stmt->get_result();

        while ($row = $subjects_result->fetch_assoc()) {
            $subject_class_id = $row['subject_class_id'];
            $subjects[$subject_class_id] = [
                'title' => $row['title'],
                'type' => $row['type'],
                'total_marks' => $row['total_marks'] ?? 0,
                'theory_marks' => 0,
                'practical_marks' => 0,
                'child_subjects' => [],
                'subject_total' => 0,
                'status' => 0
            ];
        }
        $stmt->close();

        // Standalone marks
        $standalone_marks_query = "
            SELECT 
                sc.id as subject_class_id,
                r.marks as obtained_marks,
                r.arrange_exam_id as exam_id,
                r.status as result_status
            FROM subject_class sc
            JOIN subjects s ON s.id = sc.subject_id
            LEFT JOIN results r 
                ON r.subject_class_id = sc.id 
                AND r.student_id = ? 
                AND (r.subject_class_sub_id IS NULL OR r.subject_class_sub_id = 0)
                AND r.arrange_exam_id = ?
            WHERE sc.class_id = ? AND s.type = 'standalone'
        ";
        $stmt = $conn->prepare($standalone_marks_query);
        $stmt->bind_param("iii", $student_id, $arrange_exam_id, $class_id);
        $stmt->execute();
        $standalone_result = $stmt->get_result();
        while ($row = $standalone_result->fetch_assoc()) {
            $subject_class_id = $row['subject_class_id'];
            if (isset($subjects[$subject_class_id])) {
                $subjects[$subject_class_id]['theory_marks'] = $row['obtained_marks'] ?? 0;
                $subjects[$subject_class_id]['subject_total'] = $row['obtained_marks'] ?? 0;
                $subjects[$subject_class_id]['status'] = $row['result_status'] ?? 0;
            }
        }
        $stmt->close();

        // Composite marks
        $composite_marks_query = "
            SELECT 
                sc.id as subject_class_id,
                r.marks as obtained_marks,
                r.subject_class_sub_id,
                r.arrange_exam_id as exam_id,
                r.status as result_status,
                ss.title as sub_subject_title,
                scs.marks as sub_subject_total_marks
            FROM subject_class sc
            JOIN subjects s ON s.id = sc.subject_id
            JOIN subject_class_sub scs ON sc.id = scs.subject_class_id
            JOIN sub_subjects ss ON scs.sub_subject_id = ss.id
            LEFT JOIN results r ON r.subject_class_sub_id = scs.id 
                AND r.student_id = ? 
                AND r.arrange_exam_id = ?
            WHERE sc.class_id = ? AND s.type = 'composite'
        ";
        $stmt = $conn->prepare($composite_marks_query);
        $stmt->bind_param("iii", $student_id, $arrange_exam_id, $class_id);
        $stmt->execute();
        $composite_result = $stmt->get_result();
        while ($row = $composite_result->fetch_assoc()) {
            $subject_class_id = $row['subject_class_id'];
            if (isset($subjects[$subject_class_id])) {
                $is_practical = stripos($row['sub_subject_title'] ?? '', 'practical') !== false;
                $subjects[$subject_class_id]['child_subjects'][] = [
                    'title' => $row['sub_subject_title'],
                    'marks' => $row['obtained_marks'] ?? 0,
                    'total_marks' => $row['sub_subject_total_marks'] ?? 0,
                    'is_practical' => $is_practical,
                    'status' => $row['result_status'] ?? 0
                ];
                if ($is_practical) {
                    $subjects[$subject_class_id]['practical_marks'] += $row['obtained_marks'] ?? 0;
                } else {
                    $subjects[$subject_class_id]['theory_marks'] += $row['obtained_marks'] ?? 0;
                }
                $subjects[$subject_class_id]['subject_total'] += $row['obtained_marks'] ?? 0;
                if (($row['result_status'] ?? 0) == 1) {
                    $subjects[$subject_class_id]['status'] = 1;
                }
            }
        }
        $stmt->close();

        // Build display and compute totals
        foreach ($subjects as $subject_class_id => $subject) {
            if ($subject['type'] === 'composite' && !empty($subject['child_subjects'])) {
                $composite_total_marks = 0;
                foreach ($subject['child_subjects'] as $child) {
                    $composite_total_marks += ($child['total_marks'] ?? 0);
                }
                $subjects[$subject_class_id]['total_marks'] = $composite_total_marks;

                $display_subjects[] = [
                    'title' => $subject['title'],
                    'total_marks' => $composite_total_marks,
                    'theory_marks' => $subject['theory_marks'],
                    'practical_marks' => $subject['practical_marks'],
                    'subject_total' => $subject['subject_total'],
                    'is_composite' => true,
                    'child_subjects' => $subject['child_subjects'],
                    'status' => $subject['status']
                ];
            } else {
                $display_subjects[] = [
                    'title' => $subject['title'],
                    'total_marks' => $subject['total_marks'],
                    'theory_marks' => $subject['theory_marks'],
                    'practical_marks' => $subject['practical_marks'],
                    'subject_total' => $subject['subject_total'],
                    'is_composite' => false,
                    'child_subjects' => [],
                    'status' => $subject['status']
                ];
            }

            // Only add numbers — this is the critical fix
            $total_marks_sum_for_class += (int)($subjects[$subject_class_id]['total_marks'] ?? 0);
            $obtained_marks += (int)($subject['subject_total'] ?? 0);
        }

        // Exam type and year
        $exam_type_title = 'Unknown';
        $exam_year = 'Unknown';
        if ($arrange_exam_id) {
            $exam_query = "
                SELECT et.title AS exam_title, YEAR(ae.created_at) AS year 
                FROM exam_types et 
                INNER JOIN arrange_exam ae ON ae.exam_type_id = et.id 
                WHERE ae.id = ?
            ";
            $stmt = $conn->prepare($exam_query);
            $stmt->bind_param("i", $arrange_exam_id);
            $stmt->execute();
            $exam_result = $stmt->get_result();
            if ($exam_result->num_rows > 0) {
                $row = $exam_result->fetch_assoc();
                $exam_type_title = $row['exam_title'];
                $exam_year = $row['year'];
            }
            $stmt->close();
        }

        // Safe percentage calculation
        $percentage = ($total_marks_sum_for_class > 0 && is_numeric($total_marks_sum_for_class))
            ? round(($obtained_marks / $total_marks_sum_for_class) * 100, 2)
            : 0;

        $grade = getGrade($percentage);
        $remark = getGradeRemark($percentage);

        $computed_students[] = [
            'student' => $student,
            'subjects' => $subjects,
            'display_subjects' => $display_subjects,
            'total_marks' => $total_marks_sum_for_class,
            'obtained_marks' => $obtained_marks,
            'percentage' => $percentage,
            'grade' => $grade,
            'remark' => $remark,
            'exam_type_title' => $exam_type_title,
            'exam_year' => $exam_year
        ];
    }

    // Dense rank positions
    $totals_for_ranking = [];
    foreach ($computed_students as $idx => $cs) {
        $totals_for_ranking[] = ['idx' => $idx, 'obtained' => $cs['obtained_marks']];
    }
    usort($totals_for_ranking, function($a, $b) {
        if ($a['obtained'] == $b['obtained']) return 0;
        return ($a['obtained'] < $b['obtained']) ? 1 : -1;
    });
    $rank = 0;
    $prev = null;
    $positions = array_fill(0, count($computed_students), 'Not Ranked');
    foreach ($totals_for_ranking as $entry) {
        $idx = $entry['idx'];
        $obt = $entry['obtained'];
        if ($obt <= 0) {
            $positions[$idx] = 'Not Ranked';
            continue;
        }
        if ($prev === null || $obt < $prev) {
            $rank++;
            $prev = $obt;
        }
        $positions[$idx] = $rank;
    }
    // Render order by obtained desc
    $render_order = $totals_for_ranking;
    if (empty($render_order)) {
        foreach ($computed_students as $idx => $_) {
            $render_order[] = ['idx' => $idx, 'obtained' => 0];
        }
    }

} catch (Exception $e) {
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    error_log("Top-level error in print_dmc_classwise.php: " . $e->getMessage());
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>DMC Print - Class Wise</title>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <style>
       @media print {
    body {
        background-repeat: repeat;
        background-size: 100px auto;
        opacity: 1;
        position: relative;
    }
    body::before {
        content: '';
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-image: url('logo2.png');
        background-repeat: repeat;
      background-size: 70px auto;
        opacity: 0.04;
        z-index: -1;
    }
    .dmc-container {
        page-break-after: always;
        page-break-inside: avoid;
        break-after: page;
        break-inside: avoid;
        margin-bottom: 0;
    }
    .no-print { display: none; }
}
        body {
            font-family: Arial, sans-serif;
            font-size: 20px;
            line-height: 1.1;
            margin: 5px;
            padding: 0;
        }
        .header {
            text-align: center;
            position: relative;
            margin-bottom: 5px;
        }
        .header .logo-text-qr {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .header img.logo {
            width: 60px;
            margin-right: 5px;
        }
        .header .board-text {
            font-size: 20px;
            font-weight: bold,
            color: #000;
            text-align: left;
            line-height: 1.1;
            flex-grow: 1;
        }
        canvas.qr {
            width: 150px;
            height: 60px;
        }
        .certificate-title {
            font-weight: bold;
            text-decoration: underline;
            margin: 5px 0;
            font-size: 20px;
        }
        table.info {
            width: 100%;
            margin-bottom: 5px;
            border-collapse: collapse.
        }
        table.info td {
            padding: 1px 0;
            vertical-align: top;
        }
        table.info td.info-cell {
            width: 45%;
        }
        table.info td.photo-cell {
            width: 50px;
            padding-left: 5px;
        }
        table.info td.photo-cell img {
            width: 50px;
            height: 60px;
            border: 1px solid #000;
        }
        table.marks {
            width: 100%;
            border-collapse: collapse;
            margin-top: 3px;
            font-size: 23px;
        }
        table.marks th, table.marks td {
            border: 1px solid #000;
            text-align: center;
            padding: 2px;
        }
        table.marks th {
            font-weight: bold;
            font-size: 15px;
        }
        .sub-subject-row td {
            padding-left: 15px;
        }
        .composite-header td,
        .composite-header th {
            font-weight: bold;
            background-color: #f5f5f5;
            text-align: left;
            padding-left: 5px;
        }
        .result-summary {
            border: 1px solid #000;
            padding: 5px;
            box-sizing: border-box;
            float: right;
            margin-top: 8px;
            text-align: left;
            background-color: #fff;
            font-size: 16px;
        }
        .footer {
            margin-top: 50px;
            font-size: 15px;
            clear: both;
        }
        .signature {
            text-align: right;
            font-weight: bold;
            font-size: 15px;
        }
        .chart-container {
            margin: 5px 0;
            text-align: center;
            page-break-inside: avoid.
        }
        .compact-row {
            margin: 3px 0;
        }
    </style>
</head>
<body>
    <div class="container no-print">
        <a href="dmc_list.php?arrange_exam_id=<?php echo $arrange_exam_id; ?>" class="btn btn-primary">Back to DMC List</a>
        <button onclick="window.print();" class="btn btn-success pull-right">Print DMC</button>
    </div>

    <?php
    foreach ($render_order as $order) {
        $i = $order['idx'];
        $data = $computed_students[$i];

        $student = $data['student'];
        $subjects = $data['subjects'];
        $display_subjects = $data['display_subjects'];
        $total_marks = $data['total_marks'];
        $obtained_marks = $data['obtained_marks'];
        $percentage = $data['percentage'];
        $grade = $data['grade'];
        $remark = $data['remark'];
        $exam_type_title = $data['exam_type_title'];
        $exam_year = $data['exam_year'];
        $position = $positions[$i];

        // Chart data
        $subject_labels = [];
        $obtained_data = [];
        $max_data = [];
        foreach ($subjects as $subject_item) {
            $subject_labels[] = $subject_item['title'];
            $obtained_data[] = ($subject_item['status'] == 1) ? 0 : ($subject_item['subject_total'] ?? 0);
            $max_data[] = $subject_item['total_marks'] ?? 0; // composite overridden
        }

        $svg_width = 500;
        $svg_height = 150;
        $bar_width = 15;
        $spacing = 6;
        $group_width = (2 * $bar_width) + $spacing;
        $chart_width = 420;
        $num_subjects = count($subject_labels);
        $available_width = $chart_width - ($num_subjects * $group_width);
        $group_spacing = $num_subjects ? $available_width / ($num_subjects + 1) : 0;
        $x_pos = 40 + $group_spacing;
        $max_marks = max(array_merge($max_data, [100]));
        $scale_factor = $max_marks > 0 ? (90 / $max_marks) : 0;
        ?>
        <div class="dmc-container">
            <div class="header">
                <div class="logo-text-qr">
                    <img src="logo2.png" alt="BISE Logo" class="logo">
                    <div class="board-text">
                        DAR-E-ARQAM SCHOOL AND COLLEGE<br>MATTA, SWAT<br>Khyber Pakhtunkhwa (Pakistan)
                    </div>
                </div>
            </div>

            <div class="compact-row">
                <p style="margin: 3px 0;"><b>S. No. SB.</b> ___________</p>
            </div>

            <div style="text-align:center; margin:5px 0;">
                <div class="certificate-title">DETAILED MARKS CERTIFICATE</div>
                <p style="margin: 3px 0;"><?php echo strtoupper($exam_type_title);?> EXAMINATION, <?php echo strtoupper($exam_year);?><br>
                </p>
            </div>

            <table class="info">
                <tr>
                    <td class="info-cell"><b>Name :</b> <?php echo htmlspecialchars($student['name']); ?></td>
                    <td class="info-cell"><b>Roll No:</b> <?php echo htmlspecialchars($student['id']); ?></td>
                    <td class="photo-cell" rowspan="4"><img src="Uploads/students/students.jpeg" alt="Student Photo"></td>
                </tr>
                <tr>
                    <td class="info-cell"><b>Father's Name :</b> <?php echo htmlspecialchars($student['father_name']); ?></td>
                </tr>
                <tr>
                    <td class="info-cell" colspan="2"><b>Candidate of :</b> <?php echo "Dar-e-Arqam School"; ?></td>
                </tr>
                <tr>
                    <td class="info-cell" colspan="2"><b>Class:</b> <?php echo htmlspecialchars($class_details['class_title']); ?></td>
                </tr>
                <tr>
                    <td class="info-cell" colspan="2"><b>Position in Class:</b> <span style="font-size: 15px; font-weight:bolder;"><?php echo numberToOrdinal($position); ?></span></td>
                </tr>
            </table>

            <table class="marks">
                <thead>
                    <tr>
                        <th rowspan="2" width="25%">Subjects</th>
                        <th rowspan="2" width="8%">Marks</th>
                        <th colspan="3">Marks Obtained</th>
                        <th rowspan="2" width="25%">Marks in Words</th>
                    </tr>
                    <tr>
                        <th width="8%">Theory</th>
                        <th width="8%">Practical</th>
                        <th width="8%">Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                // Decide child-left padding: indent for lower grades (when parent title is shown), none for upper grades (when hidden)
                $childPadding = $showCompositeParentTitle ? '15px' : '5px';

                foreach ($display_subjects as $sub) {
                    if ($sub['is_composite'] && !empty($sub['child_subjects'])) {
                        // Show/hide parent header by class rule
                        if ($showCompositeParentTitle) {
                            echo "<tr class='composite-header'><td colspan='6' style='text-align:left;padding-left:5px;font-weight:bold;background-color:#f5f5f5;'>"
                                . htmlspecialchars($sub['title']) . "</td></tr>";
                        }
                        foreach ($sub['child_subjects'] as $child) {
                            $is_absent = $child['status'] == 1;
                            echo "<tr class='sub-subject-row'>";
                            echo "<td style='text-align:left; padding-left:" . $childPadding . ";'>" . htmlspecialchars($child['title']) . "</td>";
                            echo "<td>" . ($child['total_marks'] ?? 0) . "</td>";
                            echo "<td>" . ($child['is_practical'] ? '--' : ($is_absent ? 'Absent' : (($child['marks'] ?? 0) != 0 ? ($child['marks'] ?? 0) : '--'))) . "</td>";
                            echo "<td>" . ($child['is_practical'] ? ($is_absent ? 'Absent' : (($child['marks'] ?? 0) != 0 ? ($child['marks'] ?? 0) : '--')) : '--') . "</td>";
                            echo "<td>" . ($is_absent ? 'Absent' : ($child['marks'] ?? 0)) . "</td>";
                            echo "<td style='text-align:left; padding-left:" . $childPadding . ";'>" . ($is_absent ? 'Absent' : ucfirst(numberToWords($child['marks'] ?? 0))) . "</td>";
                            echo "</tr>";
                        }
                    } else {
                        $is_absent = isset($sub['status']) && $sub['status'] == 1;
                        echo "<tr>";
                        echo "<td style='text-align:left; padding-left:5px;'>" . htmlspecialchars($sub['title']) . "</td>";
                        echo "<td>" . ($sub['total_marks'] ?? 0) . "</td>";
                        echo "<td>" . ($is_absent ? 'Absent' : (($sub['theory_marks'] ?? 0) != 0 ? ($sub['theory_marks'] ?? 0) : '--')) . "</td>";
                        echo "<td>" . ($is_absent ? 'Absent' : (($sub['practical_marks'] ?? 0) != 0 ? ($sub['practical_marks'] ?? 0) : '--')) . "</td>";
                        echo "<td>" . ($is_absent ? 'Absent' : $sub['subject_total']) . "</td>";
                        echo "<td style='text-align:left; padding-left:5px;'>" . ($is_absent ? 'Absent' : ucfirst(numberToWords($sub['subject_total']))) . "</td>";
                        echo "</tr>";
                    }
                }
                ?>
                <tr>
                    <td style="text-align:right; padding-left:5px;">Total :</td>
                    <td><?php echo $total_marks; ?></td>
                    <td></td>
                    <td></td>
                    <td style="font-weight:bold;"><?php echo $obtained_marks; ?></td>
                    <td style="text-align:left; padding-left:5px; font-weight:bold;">
                        <?php echo ucfirst(numberToWords($obtained_marks)); ?> Only
                    </td>
                </tr>
                </tbody>
            </table>

            <div class="result-summary">
                <b>Percentage:</b> <?php echo number_format($percentage, 2); ?>%<br>
                <b>Grade:</b> <?php echo $grade; ?> <br>
                <b>Remarks:</b> <?php echo $remark; ?>
            </div>

            <div class="chart-container">
                <svg width="500" height="150" style="display: block; margin: 0 auto;">
                    <line x1="40" y1="120" x2="460" y2="120" stroke="black" stroke-width="1" />
                    <line x1="40" y1="120" x2="40" y2="30" stroke="black" stroke-width="1" />
                    <?php
                    foreach ($subjects as $index => $subject):
                        $subject_name = substr($subject['title'], 0, 10) . (strlen($subject['title']) > 10 ? '...' : '');
                        $obtained_height = ($subject['status'] == 1 ? 0 : ($subject['subject_total'] ?? 0)) * $scale_factor;
                        $total_height = ($subject['total_marks'] ?? 0) * $scale_factor;
                        echo "<rect x='$x_pos' y='" . (120 - $obtained_height) . "' width='$bar_width' height='$obtained_height' fill='#4e73df' />";
                        echo "<rect x='" . ($x_pos + $bar_width + $spacing) . "' y='" . (120 - $total_height) . "' width='$bar_width' height='$total_height' fill='#d1d3e2' />";
                        $text_x = $x_pos + $bar_width/2;
                        echo "<text x='$text_x' y='125' text-anchor='middle' font-size='6' transform='rotate(45 $text_x,125)'>" . htmlspecialchars($subject_name) . "</text>";
                        $x_pos += $group_width + $group_spacing;
                    endforeach;
                    ?>
                    <rect x="400" y="20" width="10" height="10" fill="#4e73df" />
                    <text x="410" y="30" font-size="8">Obtained</text>
                    <rect x="400" y="40" width="10" height="10" fill="#d1d3e2" />
                    <text x="410" y="50" font-size="8">Total</text>
                </svg>
            </div>

            <div class="footer">
                Prepared and Checked by Computer Cell<br>
                Dar-e-Arqam, Swat<br>
                Result Declaration Date: <?php echo $result_declaration_date; ?><br>
                <i>Note: Errors / Omissions are subject to subsequent rectification.</i>
            </div>

            <div class="signature">
                Controller of Examinations,<br>
                DAR-E-ARQAM, Swat.
            </div>

            <canvas id="barcode-<?php echo $student['id']; ?>" class="qr"></canvas>

            <script>
                document.addEventListener("DOMContentLoaded", function() {
                    try {
                        JsBarcode("#barcode-<?php echo $student['id']; ?>", "<?php echo htmlspecialchars($student['id']); ?>", {
                            format: "CODE39",
                            width: 1,
                            height: 50,
                            displayValue: false,
                            background: "#ffffff",
                            lineColor: "#000000"
                        });
                    } catch (e) {
                        console.error("Barcode generation failed for student <?php echo $student['id']; ?>: ", e);
                    }
                });
            </script>
        </div>
    <?php
    }
    ?>
</body>
</html>