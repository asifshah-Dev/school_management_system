<?php
header('Content-Type: text/html; charset=utf-8');
require_once('security.php');
require_once('conn_inc.php');
require_once('permissions.php');

require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ============================================================
   REPORT BUILDER
   ============================================================ */
function build_lecture_report($conn, $from, $to, $class_id, $session_id, $subject_id = 0) {
    $rows = [];

    $dt_from = new DateTime($from);
    $dt_to = new DateTime($to);
    $dt_today = new DateTime(date('Y-m-d'));
    if ($dt_to > $dt_today) $dt_to = clone $dt_today;
    if ($dt_to < $dt_from) return ['rows' => [], 'lectures' => 0];

    $sql = "SELECT sr.id AS student_id, sr.reg_no, sr.name, sr.father_name FROM student_class sc JOIN student_registration sr ON sr.id = sc.student_registration_id WHERE sr.status = 0";
    $params = [];
    $types = '';

    if ($class_id > 0) { $sql .= " AND sc.class_id = ?"; $params[] = $class_id; $types .= 'i'; }
    if ($session_id > 0) { $sql .= " AND sc.session_id = ?"; $params[] = $session_id; $types .= 'i'; }
    $sql .= " ORDER BY sr.reg_no, sr.name";

    $stmt = $conn->prepare($sql);
    $students = [];
    if ($stmt) {
        if (!empty($params)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) while ($r = $res->fetch_assoc()) $students[] = $r;
        $stmt->close();
    }
    if (empty($students)) return ['rows' => [], 'lectures' => 0];

    $student_ids = [];
    foreach ($students as $s) $student_ids[] = intval($s['student_id']);
    $id_list = implode(',', array_map('intval', $student_ids));

    $subject_where = '';
    if ($subject_id > 0) $subject_where = ' AND la.subject_id = ' . intval($subject_id);

    $class_where = '';
    if ($class_id > 0) $class_where = ' AND la.class_id = ' . intval($class_id);

    $session_where = '';
    if ($session_id > 0) $session_where = ' AND la.session_id = ' . intval($session_id);

    $sql_att = "SELECT la.student_id, la.subject_id, sub.title AS subject_title, la.status FROM lecture_attendance la LEFT JOIN subjects sub ON sub.id = la.subject_id WHERE la.attendance_date >= ? AND la.attendance_date <= ? AND la.student_id IN (" . $id_list . ")" . $subject_where . $class_where . $session_where . " ORDER BY sub.title";
    $att_stmt = $conn->prepare($sql_att);
    $records = [];

    if ($att_stmt) {
        $att_stmt->bind_param("ss", $from, $to);
        $att_stmt->execute();
        $att_res = $att_stmt->get_result();
        if ($att_res) {
            while ($r = $att_res->fetch_assoc()) {
                $sid = intval($r['student_id']);
                $sub = intval($r['subject_id']);
                if (!isset($records[$sid])) $records[$sid] = [];
                if (!isset($records[$sid][$sub])) {
                    $records[$sid][$sub] = ['subject_title' => $r['subject_title'] ?? ('Subject #' . $sub), 'present' => 0, 'leave' => 0];
                }
                if ($r['status'] === 'P') $records[$sid][$sub]['present']++;
                elseif ($r['status'] === 'L') $records[$sid][$sub]['leave']++;
            }
        }
        $att_stmt->close();
    }

    $sql_lec = "SELECT la.subject_id, sub.title AS subject_title, COUNT(DISTINCT CONCAT(la.attendance_date, '-', la.time_slot_id)) AS lecture_count FROM lecture_attendance la LEFT JOIN subjects sub ON sub.id = la.subject_id WHERE la.attendance_date >= ? AND la.attendance_date <= ? AND la.student_id IN (" . $id_list . ")" . $subject_where . $class_where . $session_where . " GROUP BY la.subject_id, sub.title";
    $lec_stmt = $conn->prepare($sql_lec);
    $lecture_counts = [];
    $subject_titles = [];
    $total_lectures = 0;
    if ($lec_stmt) {
        $lec_stmt->bind_param("ss", $from, $to);
        $lec_stmt->execute();
        $lec_res = $lec_stmt->get_result();
        if ($lec_res) {
            while ($r = $lec_res->fetch_assoc()) {
                $sub = intval($r['subject_id']);
                $lecture_counts[$sub] = intval($r['lecture_count']);
                $subject_titles[$sub] = $r['subject_title'] ?? ('Subject #' . $sub);
                $total_lectures += intval($r['lecture_count']);
            }
        }
        $lec_stmt->close();
    }

    $class_lecture_total = 0;
    foreach ($lecture_counts as $cnt) $class_lecture_total += $cnt;

    foreach ($students as $s) {
        $sid = intval($s['student_id']);
        $student_present = 0;
        $student_leave = 0;
        $student_lectures = $class_lecture_total;

        if (isset($records[$sid])) {
            foreach ($records[$sid] as $sub => $data) {
                $student_present += $data['present'];
                $student_leave += $data['leave'];
            }
        }

        $absent = $student_lectures - $student_present - $student_leave;
        if ($absent < 0) $absent = 0;
        $pct = 0;
        if ($student_lectures > 0) {
            $pct = round((($student_present + $student_leave) / $student_lectures) * 100, 1);
        }

        $subjects_detail = [];
        foreach ($lecture_counts as $sub => $sub_lectures) {
            $p = isset($records[$sid][$sub]) ? $records[$sid][$sub]['present'] : 0;
            $l = isset($records[$sid][$sub]) ? $records[$sid][$sub]['leave'] : 0;
            $a = $sub_lectures - $p - $l;
            if ($a < 0) $a = 0;
            $sp = 0;
            if ($sub_lectures > 0) $sp = round((($p + $l) / $sub_lectures) * 100, 1);

            $subjects_detail[] = [
                'subject_title' => $subject_titles[$sub] ?? ('Subject #' . $sub),
                'lectures' => $sub_lectures,
                'present' => $p,
                'leave' => $l,
                'absent' => $a,
                'pct' => $sp
            ];
        }

        $rows[] = [
            'reg_no' => $s['reg_no'],
            'name' => $s['name'],
            'father_name' => $s['father_name'],
            'lectures' => $student_lectures,
            'present' => $student_present,
            'leave' => $student_leave,
            'absent' => $absent,
            'percentage' => $pct,
            'subjects' => $subjects_detail
        ];
    }

    return ['rows' => $rows, 'lectures' => $total_lectures];
}

/* ============================================================
   READ FILTERS
   ============================================================ */
$from = isset($_GET['from']) ? trim($_GET['from']) : '';
$to   = isset($_GET['to']) ? trim($_GET['to']) : '';
$selected_class_id   = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$selected_session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
$selected_subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;

$submitted = isset($_GET['generate']) && $_GET['generate'] === '1';
$print_mode = isset($_GET['print']) ? trim($_GET['print']) : '';  // 'summary' | 'detail' | ''

$date_ok = true;
if ($submitted) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $date_ok = false;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $date_ok = false;
}

/* ============================================================
   EXPORT
   ============================================================ */
if ($submitted && $date_ok && isset($_GET['export']) && in_array($_GET['export'], ['csv', 'excel'])) {
    $data = build_lecture_report($conn, $from, $to, $selected_class_id, $selected_session_id, $selected_subject_id);
    $filename_base = 'lecture_attendance_report_' . $from . '_to_' . $to;

    if ($_GET['export'] === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename_base . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Reg No', 'Student Name', 'Father Name', 'Subject', 'Lectures', 'Present', 'Leave', 'Absent', 'Attendance %']);
        foreach ($data['rows'] as $r) {
            if (!empty($r['subjects'])) {
                foreach ($r['subjects'] as $sub) {
                    fputcsv($out, [$r['reg_no'], $r['name'], $r['father_name'], $sub['subject_title'], $sub['lectures'], $sub['present'], $sub['leave'], $sub['absent'], $sub['pct'] . '%']);
                }
            } else {
                fputcsv($out, [$r['reg_no'], $r['name'], $r['father_name'], '(no records)', 0, 0, 0, 0, '0%']);
            }
        }
        fclose($out);
        exit;
    }

    if ($_GET['export'] === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename_base . '.xls"');
        echo "\xEF\xBB\xBF";
        echo '<html><head><meta charset="utf-8"></head><body>';
        echo '<table border="1" cellpadding="4" cellspacing="0">';
        echo '<tr style="background:#f1f5f9; font-weight:bold;">';
        echo '<th>Reg No</th><th>Student Name</th><th>Father Name</th><th>Subject</th><th>Lectures</th><th>Present</th><th>Leave</th><th>Absent</th><th>Attendance %</th>';
        echo '</tr>';
        foreach ($data['rows'] as $r) {
            if (!empty($r['subjects'])) {
                foreach ($r['subjects'] as $sub) {
                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($r['reg_no']) . '</td>';
                    echo '<td>' . htmlspecialchars($r['name']) . '</td>';
                    echo '<td>' . htmlspecialchars($r['father_name']) . '</td>';
                    echo '<td>' . htmlspecialchars($sub['subject_title']) . '</td>';
                    echo '<td align="center">' . $sub['lectures'] . '</td>';
                    echo '<td align="center">' . $sub['present'] . '</td>';
                    echo '<td align="center">' . $sub['leave'] . '</td>';
                    echo '<td align="center">' . $sub['absent'] . '</td>';
                    echo '<td align="center">' . $sub['pct'] . '%</td>';
                    echo '</tr>';
                }
            }
        }
        echo '</table></body></html>';
        exit;
    }
}

/* ============================================================
   LOAD DROPDOWNS
   ============================================================ */
$classes = [];
$c_res = $conn->query("SELECT id, title FROM classes WHERE status = 0 OR status IS NULL ORDER BY title");
if ($c_res) while ($r = $c_res->fetch_assoc()) $classes[$r['id']] = $r['title'];

$sessions = [];
$s_res = $conn->query("SELECT id, title FROM sessions ORDER BY from_dated DESC, id DESC");
if ($s_res) while ($r = $s_res->fetch_assoc()) $sessions[$r['id']] = $r['title'];

$subjects = [];
$sub_res = $conn->query("SELECT id, title FROM subjects WHERE status = 0 ORDER BY title");
if ($sub_res) while ($r = $sub_res->fetch_assoc()) $subjects[$r['id']] = $r['title'];

/* ============================================================
   RUN REPORT
   ============================================================ */
$rows = [];
$total_lectures = 0;
$sum_present = 0;
$sum_leave = 0;
$sum_absent = 0;
$overall_pct = 0;
$students_count = 0;

if ($submitted && $date_ok) {
    $report = build_lecture_report($conn, $from, $to, $selected_class_id, $selected_session_id, $selected_subject_id);
    $rows = $report['rows'];
    $students_count = count($rows);
    foreach ($rows as $r) {
        $sum_present += $r['present'];
        $sum_leave += $r['leave'];
        $sum_absent += $r['absent'];
    }
    if ($sum_present + $sum_leave + $sum_absent > 0) {
        $overall_pct = round((($sum_present + $sum_leave) / ($sum_present + $sum_leave + $sum_absent)) * 100, 1);
    }
    $total_lectures = $report['lectures'];
}

$export_url = '?from=' . urlencode($from) . '&to=' . urlencode($to) . '&class_id=' . $selected_class_id . '&session_id=' . $selected_session_id . '&subject_id=' . $selected_subject_id . '&generate=1';

/* Detail mode active when explicitly print=detail OR when the user has expanded (we default to summary in HTML) */
$show_detail = ($print_mode === 'detail');
$auto_print = ($print_mode === 'summary' || $print_mode === 'detail');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Lecture Attendance Report</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f5f7fa; padding-bottom: 60px; }

        .page-header-card { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); border-radius: 12px; padding: 22px 26px; color: #fff; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; box-shadow: 0 6px 22px rgba(37, 99, 235, 0.25); }
        .page-header-card .header-left { display: flex; align-items: center; gap: 16px; }
        .page-header-card .header-icon { width: 52px; height: 52px; background: rgba(255,255,255,0.18); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; }
        .page-header-card h1 { font-size: 21px; font-weight: 700; margin: 0; }
        .page-header-card .subtitle { font-size: 13px; opacity: 0.85; margin-top: 4px; }
        .page-header-card .header-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn-white { background: #fff; color: #2563eb; border: 1px solid #fff; font-weight: 600; font-size: 13px; padding: 9px 16px; border-radius: 8px; display: inline-flex; align-items: center; gap: 7px; }
        .btn-white:hover, .btn-white:focus { background: #f1f5f9; color: #1d4ed8; }
        .btn-white-ghost { background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.3); font-weight: 600; font-size: 13px; padding: 9px 16px; border-radius: 8px; display: inline-flex; align-items: center; gap: 7px; }
        .btn-white-ghost:hover, .btn-white-ghost:focus { background: rgba(255,255,255,0.25); color: #fff; }

        .filter-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.06); padding: 18px 22px; margin-bottom: 20px; border-top: 4px solid #2563eb; }
        .filter-card label { font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px; }
        .filter-card .form-control { border-radius: 8px; border: 1px solid #cbd5e1; font-size: 14px; padding: 9px 12px; height: auto; }
        .filter-card .form-control:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.12); }

        .counter-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 20px; }
        .counter-box { background: #fff; border-radius: 10px; padding: 16px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border-left: 4px solid; }
        .counter-box.lectures { border-left-color: #2563eb; }
        .counter-box.students { border-left-color: #7c3aed; }
        .counter-box.present { border-left-color: #059669; }
        .counter-box.leave { border-left-color: #f59e0b; }
        .counter-box.absent { border-left-color: #dc2626; }
        .counter-box.pct { border-left-color: #0891b2; }
        .counter-box .counter-number { font-size: 22px; font-weight: 700; line-height: 1; }
        .counter-box .counter-label { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 700; margin-top: 6px; }
        .counter-box.lectures .counter-number { color: #2563eb; }
        .counter-box.students .counter-number { color: #7c3aed; }
        .counter-box.present .counter-number { color: #059669; }
        .counter-box.leave .counter-number { color: #d97706; }
        .counter-box.absent .counter-number { color: #dc2626; }
        .counter-box.pct .counter-number { color: #0891b2; }

        .list-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.06); overflow: hidden; }
        .list-card-head { padding: 16px 20px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
        .list-card-head h3 { font-size: 15px; font-weight: 700; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 8px; }
        .list-card-head h3 i { color: #2563eb; }
        .list-card-head .count-chip { background: #eff6ff; color: #2563eb; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; }

        table.rep-table { width: 100%; margin: 0; border-collapse: separate; border-spacing: 0; }
        table.rep-table thead th { background: #f8fafc; color: #475569; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; padding: 11px 14px; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
        table.rep-table thead th.center { text-align: center; }
        table.rep-table tbody td { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; font-size: 14px; color: #1e293b; }
        table.rep-table tbody td.center { text-align: center; }
        table.rep-table tbody tr:hover { background: #f8fafc; }
        table.rep-table tbody tr:last-child td { border-bottom: none; }
        table.rep-table tbody tr.subject-row { background: #fafbfc; }
        table.rep-table tbody tr.subject-row td { font-size: 13px; color: #475569; }

        .reg-badge { display: inline-flex; align-items: center; justify-content: center; padding: 3px 10px; background: #eff6ff; color: #2563eb; font-weight: 700; font-size: 12px; border-radius: 6px; font-family: monospace; }
        .student-name { font-weight: 600; color: #1e293b; }
        .student-sub { color: #94a3b8; font-size: 12px; }
        .num-pill { display: inline-flex; align-items: center; justify-content: center; min-width: 40px; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 700; }
        .num-pill.p { background: #d1fae5; color: #065f46; }
        .num-pill.l { background: #fef3c7; color: #92400e; }
        .num-pill.a { background: #fee2e2; color: #991b1b; }
        .pct-bar-wrap { background: #f1f5f9; border-radius: 4px; height: 8px; width: 100px; overflow: hidden; display: inline-block; vertical-align: middle; }
        .pct-bar { height: 100%; background: #059669; }
        .pct-bar.mid { background: #f59e0b; }
        .pct-bar.low { background: #dc2626; }
        .pct-text { font-size: 12px; font-weight: 700; color: #1e293b; margin-left: 8px; }

        .expand-btn { background: transparent; border: none; color: #2563eb; cursor: pointer; padding: 4px 8px; font-size: 12px; font-weight: 600; }
        .expand-btn:hover { background: #eff6ff; border-radius: 6px; }

        .prompt-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.06); padding: 60px 30px; text-align: center; border-top: 4px solid #2563eb; }
        .prompt-card .prompt-icon { width: 80px; height: 80px; background: #eff6ff; color: #2563eb; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 34px; margin-bottom: 18px; }
        .prompt-card h4 { font-size: 18px; font-weight: 700; color: #1e293b; margin: 0 0 8px 0; }
        .prompt-card p { font-size: 14px; color: #64748b; margin: 0; }

        .btn-brand { background: #2563eb; border: 1px solid #2563eb; color: #fff; font-weight: 600; font-size: 13px; padding: 9px 18px; border-radius: 8px; display: inline-flex; align-items: center; gap: 7px; }
        .btn-brand:hover, .btn-brand:focus { background: #1d4ed8; border-color: #1d4ed8; color: #fff; }

        .print-header { display: none; }

        @media print {
            @page { margin: 12mm; }
            body * { visibility: hidden !important; }
            .print-area, .print-area * { visibility: visible !important; }
            .print-area { position: absolute !important; left: 0 !important; top: 0 !important; width: 100% !important; margin: 0 !important; padding: 0 !important; }
            .no-print, .page-header-card, .filter-card, .counter-row, .prompt-card, nav, .navbar, header, footer, #navbar, #header, .navbar-fixed-top, .navbar-default { display: none !important; }
            body { background: #fff !important; padding: 0 !important; margin: 0 !important; font-family: Arial, Helvetica, sans-serif; color: #000; }
            .list-card { box-shadow: none !important; border-radius: 0 !important; background: #fff !important; border: none !important; padding: 0 !important; margin: 0 !important; overflow: visible !important; }
            .list-card-head { display: none !important; }
            .print-header { display: block !important; text-align: center; margin-bottom: 12px; visibility: visible !important; }
            .print-header h2 { font-size: 16px; font-weight: bold; margin: 0 0 4px 0; color: #000; }
            .print-header .meta { font-size: 11px; color: #333; }
            table.rep-table { width: 100% !important; border-collapse: collapse !important; font-size: 11px !important; color: #000 !important; }
            table.rep-table thead th { background: #fff !important; color: #000 !important; border: 1px solid #333 !important; padding: 6px 8px !important; font-size: 11px !important; font-weight: bold !important; text-align: left !important; text-transform: none !important; letter-spacing: normal !important; }
            table.rep-table thead th.center { text-align: center !important; }
            table.rep-table tbody td { border: 1px solid #333 !important; padding: 5px 8px !important; font-size: 11px !important; color: #000 !important; background: #fff !important; }
            table.rep-table tbody td.center { text-align: center !important; }
            table.rep-table tbody tr:hover { background: #fff !important; }
            .reg-badge, .num-pill, .student-name, .student-sub { background: transparent !important; color: #000 !important; padding: 0 !important; border: none !important; font-weight: normal !important; font-family: inherit !important; }
            .pct-bar-wrap, .pct-bar { display: none !important; }
            .pct-text { font-weight: normal !important; color: #000 !important; margin: 0 !important; }
            .expand-btn { display: none !important; }

            /* Force subject rows visible ONLY when body has print-detail */
            body.print-detail .subject-row { display: table-row !important; }
            body.print-summary .subject-row { display: none !important; }
        }
    </style>
</head>
<body class="<?php echo $show_detail ? 'print-detail' : 'print-summary'; ?>">
    <?php require_once('navbar.php'); ?>

    <div class="container no-print" style="padding: 20px; max-width: 1300px; margin: 0 auto;">

        <div class="page-header-card">
            <div class="header-left">
                <div class="header-icon"><i class="fas fa-chart-bar"></i></div>
                <div>
                    <h1>Lecture Attendance Report</h1>
                    <div class="subtitle">
                        <?php if ($submitted && $date_ok): ?>
                            <?php echo date('d M Y', strtotime($from)); ?> &mdash; <?php echo date('d M Y', strtotime($to)); ?>
                        <?php else: ?>
                            Select filters and generate
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php if ($submitted && $date_ok && !empty($rows)): ?>
            <div class="header-actions">
                <a href="<?php echo $export_url; ?>&export=csv" class="btn-white-ghost"><i class="fas fa-file-csv"></i> CSV</a>
                <a href="<?php echo $export_url; ?>&export=excel" class="btn-white-ghost"><i class="fas fa-file-excel"></i> Excel</a>
                <a href="<?php echo $export_url; ?>&print=summary" class="btn-white-ghost"><i class="fas fa-print"></i> Print Summary</a>
                <a href="<?php echo $export_url; ?>&print=detail" class="btn-white"><i class="fas fa-print"></i> Print Detailed</a>
            </div>
            <?php endif; ?>
        </div>

        <div class="filter-card">
            <form method="get" action="">
                <input type="hidden" name="generate" value="1">
                <div class="row">
                    <div class="col-md-2">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>From</label>
                            <input type="date" class="form-control" name="from" value="<?php echo htmlspecialchars($from); ?>" required>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>To</label>
                            <input type="date" class="form-control" name="to" value="<?php echo htmlspecialchars($to); ?>" required>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Session</label>
                            <select class="form-control" name="session_id">
                                <option value="0">All</option>
                                <?php foreach ($sessions as $sid => $sname): ?>
                                    <option value="<?php echo (int)$sid; ?>" <?php echo ((int)$sid === $selected_session_id) ? 'selected' : ''; ?>><?php echo htmlspecialchars($sname); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Class</label>
                            <select class="form-control" name="class_id">
                                <option value="0">All</option>
                                <?php foreach ($classes as $cid => $cname): ?>
                                    <option value="<?php echo (int)$cid; ?>" <?php echo ((int)$cid === $selected_class_id) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cname); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Subject</label>
                            <select class="form-control" name="subject_id">
                                <option value="0">All</option>
                                <?php foreach ($subjects as $subid => $subname): ?>
                                    <option value="<?php echo (int)$subid; ?>" <?php echo ((int)$subid === $selected_subject_id) ? 'selected' : ''; ?>><?php echo htmlspecialchars($subname); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2" style="display: flex; align-items: flex-end;">
                        <button type="submit" class="btn-brand" style="width: 100%; justify-content: center;"><i class="fas fa-search"></i> Generate</button>
                    </div>
                </div>
            </form>
        </div>

        <?php if ($submitted && $date_ok && !empty($rows)): ?>
        <div class="counter-row">
            <div class="counter-box lectures">
                <div class="counter-number"><?php echo $total_lectures; ?></div>
                <div class="counter-label">Lectures Taken</div>
            </div>
            <div class="counter-box students">
                <div class="counter-number"><?php echo $students_count; ?></div>
                <div class="counter-label">Students</div>
            </div>
            <div class="counter-box present">
                <div class="counter-number"><?php echo $sum_present; ?></div>
                <div class="counter-label">Total Present</div>
            </div>
            <div class="counter-box leave">
                <div class="counter-number"><?php echo $sum_leave; ?></div>
                <div class="counter-label">Total Leave</div>
            </div>
            <div class="counter-box absent">
                <div class="counter-number"><?php echo $sum_absent; ?></div>
                <div class="counter-label">Total Absent</div>
            </div>
            <div class="counter-box pct">
                <div class="counter-number"><?php echo $overall_pct; ?>%</div>
                <div class="counter-label">Overall Attendance</div>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <div class="container" style="max-width: 1300px; margin: 0 auto;">
        <?php if (!$submitted): ?>
            <div class="prompt-card no-print">
                <div class="prompt-icon"><i class="fas fa-filter"></i></div>
                <h4>Select Filters to Generate Report</h4>
                <p>Pick a date range above, then click <strong>Generate</strong>.</p>
            </div>
        <?php elseif (!$date_ok): ?>
            <div class="prompt-card no-print">
                <div class="prompt-icon" style="background:#fef2f2; color:#dc2626;"><i class="fas fa-exclamation-triangle"></i></div>
                <h4>Invalid Dates</h4>
                <p>Please pick valid From and To dates.</p>
            </div>
        <?php elseif (empty($rows)): ?>
            <div class="prompt-card no-print">
                <div class="prompt-icon" style="background:#f1f5f9; color:#64748b;"><i class="fas fa-inbox"></i></div>
                <h4>No Data</h4>
                <p>No lecture attendance records matched the selected filters.</p>
            </div>
        <?php else: ?>

        <div class="list-card print-area">

            <div class="print-header">
                <h2>Lecture Attendance Report<?php echo $show_detail ? ' — Detailed' : ''; ?></h2>
                <div class="meta">
                    From <?php echo date('d M Y', strtotime($from)); ?> to <?php echo date('d M Y', strtotime($to)); ?>
                    &mdash; <?php echo $total_lectures; ?> lecture(s)
                </div>
            </div>

            <div class="list-card-head">
                <h3><i class="fas fa-list-ul"></i> Per-Student Summary (click to expand subjects)</h3>
                <span class="count-chip"><?php echo count($rows); ?> student(s)</span>
            </div>

            <div style="overflow-x: auto;">
                <table class="rep-table">
                    <thead>
                        <tr>
                            <th style="width: 40px;"></th>
                            <th style="width: 50px;">#</th>
                            <th>Reg No</th>
                            <th>Student</th>
                            <th class="center">Lectures</th>
                            <th class="center">Present</th>
                            <th class="center">Leave</th>
                            <th class="center">Absent</th>
                            <th style="width: 160px;">Attendance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sr = 1; foreach ($rows as $r):
                            $pct = $r['percentage'];
                            $bar_class = '';
                            if ($pct < 60) $bar_class = 'low';
                            elseif ($pct < 85) $bar_class = 'mid';
                            $row_id = 'stu_' . $sr;
                        ?>
                        <tr>
                            <td>
                                <?php if (!empty($r['subjects'])): ?>
                                    <button type="button" class="expand-btn no-print" data-target="<?php echo $row_id; ?>">
                                        <i class="fas fa-chevron-down"></i>
                                    </button>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $sr; ?></td>
                            <td><span class="reg-badge"><?php echo htmlspecialchars($r['reg_no']); ?></span></td>
                            <td>
                                <div class="student-name"><?php echo htmlspecialchars($r['name']); ?></div>
                                <?php if (!empty($r['father_name'])): ?>
                                    <div class="student-sub">S/O <?php echo htmlspecialchars($r['father_name']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="center"><?php echo $r['lectures']; ?></td>
                            <td class="center"><span class="num-pill p"><?php echo $r['present']; ?></span></td>
                            <td class="center"><span class="num-pill l"><?php echo $r['leave']; ?></span></td>
                            <td class="center"><span class="num-pill a"><?php echo $r['absent']; ?></span></td>
                            <td>
                                <span class="pct-bar-wrap"><span class="pct-bar <?php echo $bar_class; ?>" style="width: <?php echo $pct; ?>%;"></span></span>
                                <span class="pct-text"><?php echo $pct; ?>%</span>
                            </td>
                        </tr>
                        <?php if (!empty($r['subjects'])): ?>
                            <?php foreach ($r['subjects'] as $sub):
                                $sub_pct = $sub['pct'];
                                $sub_bar = '';
                                if ($sub_pct < 60) $sub_bar = 'low';
                                elseif ($sub_pct < 85) $sub_bar = 'mid';
                            ?>
                            <tr class="subject-row" data-parent="<?php echo $row_id; ?>" style="display: <?php echo $show_detail ? 'table-row' : 'none'; ?>;">
                                <td></td>
                                <td colspan="3" style="padding-left: 32px;">
                                    <i class="fas fa-book" style="color:#2563eb; font-size: 11px;"></i>
                                    <strong><?php echo htmlspecialchars($sub['subject_title']); ?></strong>
                                </td>
                                <td class="center"><?php echo $sub['lectures']; ?></td>
                                <td class="center"><?php echo $sub['present']; ?></td>
                                <td class="center"><?php echo $sub['leave']; ?></td>
                                <td class="center"><?php echo $sub['absent']; ?></td>
                                <td>
                                    <span class="pct-bar-wrap"><span class="pct-bar <?php echo $sub_bar; ?>" style="width: <?php echo $sub_pct; ?>%;"></span></span>
                                    <span class="pct-text"><?php echo $sub_pct; ?>%</span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php $sr++; endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <script>
    $(document).ready(function() {
        $(document).on('click', '.expand-btn', function() {
            var target = $(this).data('target');
            var $rows = $('tr.subject-row[data-parent="' + target + '"]');
            var $icon = $(this).find('i');
            if ($rows.first().is(':visible')) {
                $rows.hide();
                $icon.removeClass('fa-chevron-up').addClass('fa-chevron-down');
            } else {
                $rows.show();
                $icon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
            }
        });
    });
    </script>

    <?php if ($auto_print): ?>
    <script>
    /* Print mode requested — trigger print once page has settled */
    window.addEventListener('load', function() {
        setTimeout(function() { window.print(); }, 400);
    });
    </script>
    <?php endif; ?>
</body>
</html>
<?php $conn->close(); ?>