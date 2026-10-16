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
   HELPERS
   ============================================================ */
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

function valid_date(string $s): bool {
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s;
}

function calc_pct(int $attended, int $slots): ?float {
    return $slots > 0 ? round($attended / $slots * 100, 1) : null;
}

function fmt_pct(?float $p): string {
    if ($p === null) return '—';
    return (floor($p) == $p ? (string)(int)$p : number_format($p, 1)) . '%';
}

function pct_class(?float $p, int $threshold): string {
    if ($p === null) return 'na';
    if ($p >= $threshold) return 'good';
    if ($p >= $threshold - 10) return 'warn';
    return 'bad';
}

function csv_safe($v): string {
    $v = (string)($v ?? '');
    return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;
}

/* ============================================================
   DAILY RANGE REPORT
   ============================================================ */
function build_daily_range(mysqli $conn, string $from, string $to, int $class_id, array $subject_ids, array $subject_titles): array
{
    $empty = ['students' => [], 'lectures' => [], 'marks' => [], 'date_groups' => []];

    if ($class_id <= 0 || !valid_date($from) || !valid_date($to) || $from > $to) return $empty;

    /* ---------- students in class ---------- */
    $sql = "SELECT sr.id AS student_id, sr.reg_no, sr.name, sr.father_name
            FROM student_class sc JOIN student_registration sr ON sr.id = sc.student_registration_id
            WHERE sc.class_id = ? AND sr.status = 0 ORDER BY sr.reg_no, sr.name";
    $students = [];
    if ($st = $conn->prepare($sql)) {
        $st->bind_param("i", $class_id);
        $st->execute();
        $res = $st->get_result();
        while ($res && ($r = $res->fetch_assoc())) $students[] = $r;
        $st->close();
    }
    if (!$students) return $empty;

    /* ---------- filter for lecture_attendance ---------- */
    $where = "la.attendance_date BETWEEN ? AND ? AND la.class_id = ?";
    $types = 'ssi';
    $params = [$from, $to, $class_id];

    $clean_subs = array_values(array_filter(array_map('intval', $subject_ids)));
    if ($clean_subs) {
        $where .= " AND la.subject_id IN (" . implode(',', $clean_subs) . ")";
    }

    /* ---------- lectures held — sorted date, then subject A-Z ---------- */
    $sql = "SELECT DISTINCT la.attendance_date, la.time_slot_id, la.subject_id, s.title AS subject_title
            FROM lecture_attendance la
            LEFT JOIN subjects s ON s.id = la.subject_id
            WHERE $where
            ORDER BY la.attendance_date ASC, s.title ASC, la.time_slot_id ASC";
    $lectures = [];
    if ($st = $conn->prepare($sql)) {
        $st->bind_param($types, ...$params);
        $st->execute();
        $res = $st->get_result();
        while ($res && ($r = $res->fetch_assoc())) {
            $lectures[] = [
                'date'          => $r['attendance_date'],
                'slot_id'       => (int)$r['time_slot_id'],
                'subject_id'    => (int)$r['subject_id'],
                'subject_title' => $r['subject_title'] ?? ($subject_titles[(int)$r['subject_id']] ?? ('Subject #' . (int)$r['subject_id'])),
                'key'           => $r['attendance_date'] . '|' . (int)$r['time_slot_id'] . '|' . (int)$r['subject_id'],
            ];
        }
        $st->close();
    }
    if (!$lectures) return ['students' => $students, 'lectures' => [], 'marks' => [], 'date_groups' => []];

    /* ---------- marks ---------- */
    $marks = [];
    $sql = "SELECT la.student_id, la.attendance_date, la.time_slot_id, la.subject_id, la.status, la.remarks
            FROM lecture_attendance la
            WHERE $where";
    if ($st = $conn->prepare($sql)) {
        $st->bind_param($types, ...$params);
        $st->execute();
        $res = $st->get_result();
        while ($res && ($r = $res->fetch_assoc())) {
            $key = $r['attendance_date'] . '|' . (int)$r['time_slot_id'] . '|' . (int)$r['subject_id'];
            $marks[(int)$r['student_id']][$key] = [
                'status'  => $r['status'],
                'remarks' => $r['remarks'],
            ];
        }
        $st->close();
    }

    /* ---------- per-student totals ---------- */
    foreach ($students as &$s) {
        $sid = (int)$s['student_id'];
        $p = 0; $l = 0; $a = 0;
        foreach ($lectures as $lec) {
            $m = $marks[$sid][$lec['key']] ?? null;
            if ($m) {
                if ($m['status'] === 'P') $p++;
                elseif ($m['status'] === 'L') $l++;
                elseif ($m['status'] === 'A') $a++;
            }
        }
        $s['present'] = $p;
        $s['leave'] = $l;
        $s['absent'] = $a;
        $s['total'] = count($lectures);
        $s['pct'] = calc_pct($p + $l, count($lectures));
    }
    unset($s);

    /* ---------- group lectures by date for the header ---------- */
    $date_groups = [];
    foreach ($lectures as $idx => $lec) {
        if (!isset($date_groups[$lec['date']])) {
            $date_groups[$lec['date']] = ['count' => 0, 'indices' => []];
        }
        $date_groups[$lec['date']]['count']++;
        $date_groups[$lec['date']]['indices'][] = $idx;
    }

    return [
        'students'    => $students,
        'lectures'    => $lectures,
        'marks'       => $marks,
        'date_groups' => $date_groups,
    ];
}

/* ============================================================
   READ FILTERS
   ============================================================ */
$submitted = (($_GET['generate'] ?? '') === '1');

$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to'] ?? '');
if (!$submitted) {
    $from = date('Y-m-01');
    $to   = date('Y-m-d');
}
$selected_class_id = intval($_GET['class_id'] ?? 0);

$selected_subject_ids = [];
if (isset($_GET['subject_ids']) && is_array($_GET['subject_ids'])) {
    $selected_subject_ids = array_values(array_unique(array_filter(array_map('intval', $_GET['subject_ids']))));
}

$threshold = intval($_GET['threshold'] ?? 75);
if ($threshold < 1 || $threshold > 100) $threshold = 75;

$auto_print = (($_GET['print'] ?? '') === 'detail');

$date_ok = true;
$date_msg = '';
if ($submitted) {
    if (!valid_date($from) || !valid_date($to)) { $date_ok = false; $date_msg = 'Please pick valid From and To dates.'; }
    elseif ($from > $to) { $date_ok = false; $date_msg = 'The From date must be on or before the To date.'; }
}

/* ============================================================
   DROPDOWN DATA
   ============================================================ */
$classes = [];
if ($r = $conn->query("SELECT id, title FROM classes WHERE status = 0 OR status IS NULL ORDER BY title")) {
    while ($row = $r->fetch_assoc()) $classes[(int)$row['id']] = $row['title'];
}

$subject_titles = [];
if ($r = $conn->query("SELECT id, title FROM subjects")) {
    while ($row = $r->fetch_assoc()) $subject_titles[(int)$row['id']] = $row['title'];
}

$subjects = [];
if ($selected_class_id > 0) {
    $sql = "SELECT s.id, s.title FROM subjects s INNER JOIN subject_class sc ON sc.subject_id = s.id WHERE sc.class_id = ? AND (sc.status = 0 OR sc.status IS NULL) AND (s.status = 0 OR s.status IS NULL) GROUP BY s.id, s.title ORDER BY s.title";
    if ($st = $conn->prepare($sql)) {
        $st->bind_param("i", $selected_class_id);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) $subjects[(int)$row['id']] = $row['title'];
        $st->close();
    }
}

/* ============================================================
   AJAX — subjects for a class
   ============================================================ */
if (($_GET['ajax'] ?? '') === 'subjects') {
    $cid = intval($_GET['class_id'] ?? 0);
    $list = [];
    if ($cid > 0) {
        $sql = "SELECT s.id, s.title FROM subjects s INNER JOIN subject_class sc ON sc.subject_id = s.id WHERE sc.class_id = ? AND (sc.status = 0 OR sc.status IS NULL) AND (s.status = 0 OR s.status IS NULL) GROUP BY s.id, s.title ORDER BY s.title";
        if ($st = $conn->prepare($sql)) {
            $st->bind_param("i", $cid);
            $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) $list[] = ['id' => (int)$row['id'], 'title' => $row['title']];
            $st->close();
        }
    }
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($list);
    exit;
}

/* ============================================================
   EXPORTS — with title header like the print layout
   ============================================================ */
$export = $_GET['export'] ?? '';
if ($submitted && $date_ok && in_array($export, ['csv', 'excel'], true)) {
    $data = build_daily_range($conn, $from, $to, $selected_class_id, $selected_subject_ids, $subject_titles);
    $base = 'daily_attendance_' . $from . '_to_' . $to;

    $class_label_exp = $selected_class_id > 0 ? ($classes[$selected_class_id] ?? '') : 'All classes';
    $subject_label_exp = $selected_subject_ids
        ? implode(', ', array_map(fn($i) => $subject_titles[$i] ?? ('#' . $i), $selected_subject_ids))
        : 'All subjects';
    $effective_to_exp = ($to > date('Y-m-d')) ? date('Y-m-d') : $to;

    $title_line = 'Daily Attendance Report';
    $meta_line1 = 'From ' . date('d M Y', strtotime($from)) . ' to ' . date('d M Y', strtotime($effective_to_exp))
                . ' | Class: ' . $class_label_exp
                . ' | Subjects: ' . $subject_label_exp;
    $meta_line2 = count($data['lectures']) . ' lecture(s) held | ' . count($data['students']) . ' student(s) | Required: ' . $threshold . '%'
                . ' | Printed: ' . date('d M Y, h:i A');

    /* ---------- CSV ---------- */
    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $base . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");

        /* Title block */
        fputcsv($out, [$title_line]);
        fputcsv($out, [$meta_line1]);
        fputcsv($out, [$meta_line2]);
        fputcsv($out, []);  // blank spacer

        /* Date header row: date name in first column of each date group */
        $row1 = ['', '', ''];
        foreach ($data['date_groups'] as $date => $grp) {
            $row1[] = date('d M Y', strtotime($date));
            for ($i = 1; $i < $grp['count']; $i++) $row1[] = '';
        }
        $row1[] = 'Totals'; $row1[] = ''; $row1[] = ''; $row1[] = '';
        fputcsv($out, $row1);

        /* Subject header row */
        $row2 = ['Reg No', 'Student', 'Father'];
        foreach ($data['lectures'] as $lec) {
            $row2[] = $lec['subject_title'];
        }
        $row2[] = 'Present'; $row2[] = 'Leave'; $row2[] = 'Absent'; $row2[] = 'Attendance %';
        fputcsv($out, $row2);

        /* Data rows */
        foreach ($data['students'] as $s) {
            $line = [csv_safe($s['reg_no']), csv_safe($s['name']), csv_safe($s['father_name'])];
            foreach ($data['lectures'] as $lec) {
                $m = $data['marks'][(int)$s['student_id']][$lec['key']] ?? null;
                $line[] = $m ? $m['status'] : '';
            }
            $line[] = $s['present'];
            $line[] = $s['leave'];
            $line[] = $s['absent'];
            $line[] = $s['pct'] === null ? '' : $s['pct'] . '%';
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }

    /* ---------- Excel (HTML table with merged date row) ---------- */
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $base . '.xls"');
    echo "\xEF\xBB\xBF";
    echo '<html><head><meta charset="utf-8">';
    echo '<style>';
    echo 'table { border-collapse:collapse; font-family:Arial,sans-serif; font-size:10pt; }';
    echo 'td, th { border:1px solid #333; padding:3px 6px; }';
    echo 'th { background:#f1f5f9; text-align:center; font-weight:bold; }';
    echo 'th.date-hdr { background:#dbeafe; color:#1e40af; }';
    echo 'th.total-hdr { background:#f8fafc; }';
    echo 'td.mark-P { background:#d1fae5; color:#065f46; text-align:center; font-weight:bold; }';
    echo 'td.mark-L { background:#fef3c7; color:#92400e; text-align:center; font-weight:bold; }';
    echo 'td.mark-A { background:#fee2e2; color:#991b1b; text-align:center; font-weight:bold; }';
    echo 'td.mark-empty { background:#f1f5f9; color:#cbd5e1; text-align:center; }';
    echo 'td.center { text-align:center; }';
    echo '.title { font-family:Arial,sans-serif; font-size:16pt; font-weight:bold; text-align:center; padding:6px 0; }';
    echo '.meta { font-family:Arial,sans-serif; font-size:9pt; color:#333; text-align:center; }';
    echo '</style>';
    echo '</head><body>';

    /* Title block */
    echo '<table style="border:none;border-collapse:collapse;width:100%;margin-bottom:8px;">';
    echo '<tr><td class="title" style="border:none;">' . h($title_line) . '</td></tr>';
    echo '<tr><td class="meta" style="border:none;">' . h($meta_line1) . '</td></tr>';
    echo '<tr><td class="meta" style="border:none;">' . h($meta_line2) . '</td></tr>';
    echo '</table>';

    /* Main data table */
    echo '<table>';

    /* Row 1: date header (merged across each date group) */
    echo '<tr>';
    echo '<th rowspan="2">Reg No</th>';
    echo '<th rowspan="2">Student</th>';
    echo '<th rowspan="2">Father</th>';
    foreach ($data['date_groups'] as $date => $grp) {
        echo '<th class="date-hdr" colspan="' . (int)$grp['count'] . '">' . h(date('d M Y', strtotime($date))) . '</th>';
    }
    echo '<th class="total-hdr" colspan="4">Totals</th>';
    echo '</tr>';

    /* Row 2: subject names */
    echo '<tr>';
    foreach ($data['lectures'] as $lec) {
        echo '<th class="date-hdr">' . h($lec['subject_title']) . '</th>';
    }
    echo '<th class="total-hdr">Present</th>';
    echo '<th class="total-hdr">Leave</th>';
    echo '<th class="total-hdr">Absent</th>';
    echo '<th class="total-hdr">Attendance %</th>';
    echo '</tr>';

    /* Student rows */
    foreach ($data['students'] as $s) {
        echo '<tr>';
        echo '<td>' . h($s['reg_no']) . '</td>';
        echo '<td>' . h($s['name']) . '</td>';
        echo '<td>' . h($s['father_name']) . '</td>';
        foreach ($data['lectures'] as $lec) {
            $m = $data['marks'][(int)$s['student_id']][$lec['key']] ?? null;
            $st = $m ? $m['status'] : '';
            if ($st === 'P')      echo '<td class="mark-P">P</td>';
            elseif ($st === 'L')  echo '<td class="mark-L">L</td>';
            elseif ($st === 'A')  echo '<td class="mark-A">A</td>';
            else                  echo '<td class="mark-empty">&middot;</td>';
        }
        echo '<td class="center">' . $s['present'] . '</td>';
        echo '<td class="center">' . $s['leave'] . '</td>';
        echo '<td class="center">' . $s['absent'] . '</td>';
        echo '<td class="center">' . h(fmt_pct($s['pct'])) . '</td>';
        echo '</tr>';
    }

    echo '</table>';
    echo '</body></html>';
    exit;
}

/* ============================================================
   RUN REPORT
   ============================================================ */
$data = ['students' => [], 'lectures' => [], 'marks' => [], 'date_groups' => []];
$sum = ['p' => 0, 'l' => 0, 'a' => 0, 'lec' => 0];
$overall_pct = null;
$short_rows = [];
$effective_to = $to;

if ($submitted && $date_ok && $selected_class_id > 0) {
    $data = build_daily_range($conn, $from, $to, $selected_class_id, $selected_subject_ids, $subject_titles);
    if ($to > date('Y-m-d')) $effective_to = date('Y-m-d');

    /* Lectures held = DISTINCT lectures (columns), not students × lectures */
    $sum['lec'] = count($data['lectures']);

    foreach ($data['students'] as $s) {
        $sum['p'] += $s['present'];
        $sum['l'] += $s['leave'];
        $sum['a'] += $s['absent'];
        if ($s['pct'] !== null && $s['pct'] < $threshold) $short_rows[] = $s;
    }

    /* Overall % = all P+L marks ÷ (students × lectures) */
    $total_slots = count($data['students']) * count($data['lectures']);
    $overall_pct = calc_pct($sum['p'] + $sum['l'], $total_slots);

    usort($short_rows, fn($a, $b) => $a['pct'] <=> $b['pct']);
}

$class_label = $selected_class_id > 0 ? ($classes[$selected_class_id] ?? '') : 'No class';
$subject_label = $selected_subject_ids
    ? implode(', ', array_map(fn($i) => $subject_titles[$i] ?? ('#' . $i), $selected_subject_ids))
    : 'All subjects';

$qs = ['generate' => 1, 'from' => $from, 'to' => $to, 'class_id' => $selected_class_id, 'threshold' => $threshold];
if ($selected_subject_ids) $qs['subject_ids'] = $selected_subject_ids;
$export_base = '?' . http_build_query($qs);

function student_cell(array $r): string {
    $s = '<div class="stu-name">' . h($r['name']) . '</div>';
    if (!empty($r['father_name'])) $s .= '<div class="stu-sub">S/O ' . h($r['father_name']) . '</div>';
    return $s;
}
function pct_cell(?float $p, int $threshold): string {
    $cls = pct_class($p, $threshold);
    $w = $p === null ? 0 : min(100, max(0, $p));
    return '<div class="pbar"><span class="pfill ' . $cls . '" style="width:' . $w . '%"></span></div><span class="ptxt ' . $cls . '">' . h(fmt_pct($p)) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Daily Attendance Report</title>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<style>
    :root {
        --brand:#2563eb; --brand-d:#1d4ed8; --ink:#0f172a; --muted:#64748b; --line:#e2e8f0; --bg:#f4f6fb;
        --good:#059669; --good-bg:#d1fae5; --warn:#d97706; --warn-bg:#fef3c7; --bad:#dc2626; --bad-bg:#fee2e2;
    }
    body { font-family:'Inter',sans-serif; background:var(--bg); color:var(--ink); padding-bottom:60px; }
    .wrap { max-width:100%; margin:0 auto; padding:20px; }

    .hero { background:linear-gradient(135deg,#2563eb,#1e40af); color:#fff; border-radius:14px; padding:20px 24px; margin-bottom:18px;
            display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; box-shadow:0 8px 24px rgba(37,99,235,.25); }
    .hero h1 { margin:0; font-size:21px; font-weight:700; }
    .hero .sub { font-size:13px; opacity:.9; margin-top:4px; }
    .hero-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .btn-w { background:#fff; color:var(--brand-d); border:0; border-radius:8px; padding:8px 14px; font-weight:600; font-size:13px; cursor:pointer; }
    .btn-g { background:rgba(255,255,255,.16); color:#fff; border:1px solid rgba(255,255,255,.35); border-radius:8px; padding:8px 14px; font-weight:600; font-size:13px; cursor:pointer; text-decoration:none; }
    .btn-g:hover { background:rgba(255,255,255,.26); color:#fff; text-decoration:none; }
    .dropdown-menu { border-radius:10px; border:1px solid var(--line); box-shadow:0 8px 24px rgba(0,0,0,.12); font-size:13px; }

    .card-x { background:#fff; border-radius:14px; box-shadow:0 2px 10px rgba(15,23,42,.06); }
    .filters { padding:18px 22px; margin-bottom:18px; }
    .filters label.lb { font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.5px; margin-bottom:5px; display:block; }
    .filters .form-control { border-radius:8px; border:1px solid #cbd5e1; font-size:14px; height:40px; }
    .filters .form-control:focus { border-color:var(--brand); box-shadow:0 0 0 3px rgba(37,99,235,.12); }
    .pills { display:flex; flex-wrap:wrap; gap:7px; min-height:34px; }
    .pill { display:inline-flex; align-items:center; gap:7px; margin:0; padding:6px 13px; border:1.5px solid var(--line); border-radius:20px; font-size:12.5px; font-weight:600; color:#475569; cursor:pointer; user-select:none; background:#fff; }
    .pill input { display:none; }
    .pill .dot { width:14px; height:14px; border-radius:50%; border:2px solid #cbd5e1; flex-shrink:0; }
    .pill.on { background:#eff6ff; border-color:var(--brand); color:#1e40af; }
    .pill.on .dot { background:var(--brand); border-color:var(--brand); box-shadow:inset 0 0 0 2px #fff; }
    .pill-tools { font-size:12px; color:var(--muted); margin-top:7px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .pill-tools a { color:var(--brand); font-weight:700; cursor:pointer; }
    .btn-go { background:var(--brand); color:#fff; border:0; border-radius:8px; padding:9px 22px; font-weight:600; font-size:14px; margin-left:auto; cursor:pointer; }
    .btn-go:hover { background:var(--brand-d); }
    .subject-placeholder { color:#94a3b8; font-size:12.5px; padding:6px 0; }

    .stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:18px; }
    .stat { background:#fff; border-radius:12px; padding:14px 16px; box-shadow:0 2px 8px rgba(15,23,42,.06); border-left:4px solid var(--brand); }
    .stat .n { font-size:23px; font-weight:700; line-height:1; }
    .stat .t { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.4px; font-weight:700; margin-top:6px; }
    .stat.s-p { border-color:var(--good); } .stat.s-p .n { color:var(--good); }
    .stat.s-l { border-color:#f59e0b; } .stat.s-l .n { color:var(--warn); }
    .stat.s-a { border-color:var(--bad); } .stat.s-a .n { color:var(--bad); }
    .stat.s-o { border-color:#0891b2; } .stat.s-o .n { color:#0891b2; }
    .stat.s-s { border-color:#7c3aed; } .stat.s-s .n { color:#7c3aed; }

    .results { overflow:hidden; }
    .toolbar { padding:14px 18px; border-bottom:1px solid var(--line); display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
    .search { position:relative; }
    .search i { position:absolute; left:11px; top:11px; color:#94a3b8; font-size:12px; }
    .search input { height:36px; border:1px solid #cbd5e1; border-radius:8px; padding:0 12px 0 31px; font-size:13px; width:210px; }
    .search input:focus { outline:none; border-color:var(--brand); box-shadow:0 0 0 3px rgba(37,99,235,.12); }
    .chk { font-size:12.5px; font-weight:600; color:#475569; margin:0; cursor:pointer; display:flex; align-items:center; gap:6px; }
    .vis-count { font-size:12px; font-weight:700; color:var(--brand-d); background:#eff6ff; padding:4px 11px; border-radius:14px; }

    .scroll-x { overflow-x:auto; max-height:75vh; }
    .scroll-x table.rep { min-width:max-content; }

    table.rep { width:100%; border-collapse:separate; border-spacing:0; }
    table.rep thead th { background:#f8fafc; color:#475569; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; padding:10px 12px; border-bottom:1px solid var(--line); white-space:nowrap; }
    table.rep thead tr.date-row th { position:sticky; top:0; z-index:3; padding:7px 12px; }
    table.rep thead tr:not(.date-row) th { position:sticky; top:34px; z-index:2; }
    table.rep thead th.c, table.rep td.c { text-align:center; }
    table.rep thead th.sortable { cursor:pointer; }
    table.rep thead th.sortable:hover { color:var(--brand); }
    table.rep thead th.sortable::after { content:' ↕'; opacity:.35; }
    table.rep thead th[data-dir="asc"]::after { content:' ↑'; opacity:1; color:var(--brand); }
    table.rep thead th[data-dir="desc"]::after { content:' ↓'; opacity:1; color:var(--brand); }
    table.rep tbody td { padding:9px 12px; border-bottom:1px solid #f1f5f9; vertical-align:middle; font-size:13px; }
    table.rep tbody tr:hover td { background:#f8fafc; }
    .reg { display:inline-block; padding:2px 9px; background:#eff6ff; color:var(--brand); font-weight:700; font-size:12px; border-radius:6px; font-family:monospace; }
    .stu-name { font-weight:600; white-space:nowrap; }
    .stu-sub { color:#94a3b8; font-size:11.5px; }

    .num { display:inline-block; min-width:34px; padding:2px 9px; border-radius:12px; font-size:12px; font-weight:700; text-align:center; }
    .num.p { background:var(--good-bg); color:#065f46; } .num.l { background:var(--warn-bg); color:#92400e; } .num.a { background:var(--bad-bg); color:#991b1b; }
    .pbar { width:90px; height:8px; background:#e2e8f0; border-radius:4px; overflow:hidden; display:inline-block; vertical-align:middle; }
    .pfill { display:block; height:100%; } .pfill.good { background:var(--good); } .pfill.warn { background:#f59e0b; } .pfill.bad { background:var(--bad); } .pfill.na { background:#cbd5e1; }
    .ptxt { font-weight:700; font-size:12.5px; margin-left:8px; } .ptxt.good { color:var(--good); } .ptxt.warn { color:var(--warn); } .ptxt.bad { color:var(--bad); } .ptxt.na { color:#94a3b8; }

    .mark { display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; border-radius:8px; font-weight:700; font-size:13px; }
    .mark.P { background:var(--good-bg); color:#065f46; }
    .mark.L { background:var(--warn-bg); color:#92400e; }
    .mark.A { background:var(--bad-bg); color:#991b1b; }
    .mark.empty { background:#f1f5f9; color:#cbd5e1; }

    table.rep thead tr.date-row th.date-alt { background:#eef2f8 !important; }
    table.rep thead tr:not(.date-row) th.date-alt { background:#f1f5f9; }
    table.rep tbody td.date-alt { background:#fafbfc; }
    table.rep tbody tr:hover td.date-alt { background:#f1f5f9; }

    td.stu { position:sticky; left:0; background:#fff; z-index:1; min-width:200px; box-shadow:2px 0 6px rgba(0,0,0,.04); }
    th.stu { position:sticky; left:0; z-index:4; min-width:200px; box-shadow:2px 0 6px rgba(0,0,0,.04); background:#f8fafc; }
    tr:hover td.stu { background:#f8fafc; }

    .lec-head small { display:block; font-weight:700; color:#334155; text-transform:none; letter-spacing:0; font-size:11.5px; }

    .legend { padding:12px 18px; font-size:12px; color:var(--muted); display:flex; gap:16px; flex-wrap:wrap; align-items:center; border-top:1px solid var(--line); }
    .legend i { display:inline-block; width:10px; height:10px; border-radius:3px; margin-right:5px; }
    .empty { padding:56px 24px; text-align:center; }
    .empty .ic { width:76px; height:76px; border-radius:50%; background:#eff6ff; color:var(--brand); display:inline-flex; align-items:center; justify-content:center; font-size:32px; margin-bottom:14px; }
    .empty h4 { font-size:18px; font-weight:700; } .empty p { color:var(--muted); font-size:14px; margin:0; }

    .print-head { display:none; }

    @page { size: A4 landscape; margin: 6mm; }
    @media print {
        html, body {
            width: 100%;
            background: #fff !important;
            padding: 0 !important;
            margin: 0 !important;
            font-family: Arial, Helvetica, sans-serif;
            color: #000;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .no-print, .hero, .filters, .stats, .toolbar, .legend,
        .navbar, nav, header, footer, #navbar, #header, .navbar-fixed-top, .navbar-default { display: none !important; }
        .wrap { max-width: none; padding: 0; margin: 0; }
        .card-x { box-shadow: none; border-radius: 0; }
        .print-head { display: block; text-align: center; margin-bottom: 6px; }
        .print-head h2 { font-size: 14px; margin: 0 0 2px; }
        .print-head .meta { font-size: 9.5px; color: #333; line-height: 1.35; }

        .scroll-x { overflow: visible !important; max-height: none !important; width: 100% !important; }
        table.rep {
            width: 100% !important;
            font-size: 9px;
            table-layout: auto;
        }
        table.rep thead th {
            position: static !important;
            background: #eee !important;
            color: #000 !important;
            border: 1px solid #333 !important;
            padding: 2px 4px !important;
            font-size: 9px !important;
            white-space: normal !important;
        }
        table.rep thead tr.date-row th {
            background: #e0e7ff !important;
            color: #000 !important;
            font-weight: 700;
            font-size: 9px !important;
        }
        table.rep thead tr.date-row th.date-alt { background: #d0d9f0 !important; }

        table.rep tbody td {
            border: 1px solid #333 !important;
            padding: 1px 3px !important;
            font-size: 9px !important;
            background: #fff !important;
        }
        table.rep tbody td.date-alt { background: #f5f5f5 !important; }
        table.rep tr { break-inside: avoid; page-break-inside: avoid; }
        thead { display: table-header-group; }

        td.stu, th.stu { position: static !important; min-width: 0 !important; box-shadow: none !important; }
        .stu-name { font-size: 9px !important; }

        .mark {
            border: none;
            color: #000 !important;
            background: transparent !important;
            font-size: 10px !important;
            width: auto !important; height: auto !important;
            padding: 0 !important;
            font-weight: 700;
        }
        .mark.P { color: #000 !important; }
        .mark.L { color: #000 !important; }
        .mark.A { color: #000 !important; }
        .mark.empty { color: #999 !important; }

        .num {
            background: transparent !important;
            color: #000 !important;
            padding: 0 !important;
            min-width: 0 !important;
            font-weight: 700;
            font-size: 10px !important;
        }
        .reg { background: transparent !important; color: #000 !important; padding: 0 !important; font-size: 9px !important; font-family: inherit !important; }
        .pbar { display: none !important; }
        .ptxt { color: #000 !important; font-weight: 700; font-size: 9.5px !important; margin: 0 !important; }
    }
</style>
</head>
<body>
<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="hero no-print">
        <div>
            <h1><i class="fas fa-calendar-check mr-2"></i>Daily Attendance Report</h1>
            <div class="sub">
                <?php if ($submitted && $date_ok): ?>
                    <?= h(date('d M Y', strtotime($from))) ?> – <?= h(date('d M Y', strtotime($effective_to))) ?>
                    &middot; <?= h($class_label) ?> &middot; <?= h($subject_label) ?>
                <?php else: ?>
                    Pick a date range, class and subjects — then generate
                <?php endif; ?>
            </div>
        </div>
        <?php if ($submitted && $date_ok && !empty($data['lectures'])): ?>
        <div class="hero-actions">
            <div class="dropdown">
                <button class="btn-g dropdown-toggle" data-toggle="dropdown"><i class="fas fa-download"></i> Export</button>
                <div class="dropdown-menu dropdown-menu-right">
                    <a class="dropdown-item" href="<?= h($export_base) ?>&export=csv"><i class="fas fa-file-csv text-success mr-2"></i>CSV (Daily Grid)</a>
                    <a class="dropdown-item" href="<?= h($export_base) ?>&export=excel"><i class="fas fa-file-excel text-success mr-2"></i>Excel (Daily Grid)</a>
                </div>
            </div>
            <button class="btn-w" id="printCurrent"><i class="fas fa-print"></i> Print</button>
        </div>
        <?php endif; ?>
    </div>

    <div class="card-x filters no-print">
        <form method="get" action="" id="filterForm">
            <input type="hidden" name="generate" value="1">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="lb">From date</label>
                    <input type="date" class="form-control" name="from" id="from" value="<?= h($from) ?>" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="lb">To date</label>
                    <input type="date" class="form-control" name="to" id="to" value="<?= h($to) ?>" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="lb">Class</label>
                    <select class="form-control" name="class_id" id="classPick">
                        <option value="0">— Select a class —</option>
                        <?php foreach ($classes as $cid => $cname): ?>
                            <option value="<?= $cid ?>" <?= $cid === $selected_class_id ? 'selected' : '' ?>><?= h($cname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="lb">Required attendance</label>
                    <div class="d-flex" style="gap:8px; align-items:center;">
                        <input type="number" class="form-control" name="threshold" min="1" max="100" value="<?= (int)$threshold ?>" style="width:80px;">
                        <span style="font-weight:600; font-size:13px; color:#64748b;">%</span>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-12 mb-3">
                    <label class="lb">Subjects <span style="text-transform:none;font-weight:500;color:#94a3b8">(none selected = all)</span></label>
                    <div class="pills" id="pills">
                        <?php if ($selected_class_id > 0 && !empty($subjects)): ?>
                            <?php foreach ($subjects as $sid => $sname): $on = in_array($sid, $selected_subject_ids, true); ?>
                                <label class="pill <?= $on ? 'on' : '' ?>">
                                    <input type="checkbox" name="subject_ids[]" value="<?= $sid ?>" <?= $on ? 'checked' : '' ?>>
                                    <span class="dot"></span><?= h($sname) ?>
                                </label>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="subject-placeholder">Select a class above to see its subjects.</div>
                        <?php endif; ?>
                    </div>
                    <div class="pill-tools" id="pillTools" style="<?= ($selected_class_id > 0 && !empty($subjects)) ? '' : 'display:none;' ?>">
                        <span id="pillCount">All subjects</span> &middot;
                        <a id="pillAll">Select all</a> <a id="pillNone">Clear</a>
                    </div>
                </div>
            </div>

            <div style="text-align:right; padding-top:14px; border-top:1px dashed var(--line);">
                <button type="submit" class="btn-go"><i class="fas fa-search mr-1"></i> Generate report</button>
            </div>
        </form>
    </div>

<?php if (!$submitted): ?>
    <div class="card-x empty no-print">
        <div class="ic"><i class="fas fa-filter"></i></div>
        <h4>Ready when you are</h4>
        <p>Pick a date range, class and subjects, then click <strong>Generate report</strong>.</p>
    </div>

<?php elseif (!$date_ok): ?>
    <div class="card-x empty no-print">
        <div class="ic" style="background:#fef2f2;color:#dc2626"><i class="fas fa-exclamation-triangle"></i></div>
        <h4>Check the dates</h4>
        <p><?= h($date_msg) ?></p>
    </div>

<?php elseif ($selected_class_id <= 0): ?>
    <div class="card-x empty no-print">
        <div class="ic" style="background:#fef3c7;color:#b45309"><i class="fas fa-graduation-cap"></i></div>
        <h4>Select a class</h4>
        <p>This report is per-class. Pick a class in the filters above.</p>
    </div>

<?php elseif (empty($data['lectures'])): ?>
    <div class="card-x empty no-print">
        <div class="ic" style="background:#f1f5f9;color:#64748b"><i class="fas fa-inbox"></i></div>
        <h4>No lectures found</h4>
        <p>No lecture attendance was recorded for these filters<?= $to > date('Y-m-d') ? ' (the end date is limited to today)' : '' ?>.</p>
    </div>

<?php else: ?>

    <div class="stats">
        <div class="stat"><div class="n"><?= (int)$sum['lec'] ?></div><div class="t">Lectures held</div></div>
        <div class="stat s-s"><div class="n"><?= count($data['students']) ?></div><div class="t">Students</div></div>
        <div class="stat s-p"><div class="n"><?= (int)$sum['p'] ?></div><div class="t">Present</div></div>
        <div class="stat s-l"><div class="n"><?= (int)$sum['l'] ?></div><div class="t">Leave</div></div>
        <div class="stat s-a"><div class="n"><?= (int)$sum['a'] ?></div><div class="t">Absent</div></div>
        <div class="stat s-o"><div class="n"><?= h(fmt_pct($overall_pct)) ?></div><div class="t">Overall attendance</div></div>
        <div class="stat s-a"><div class="n"><?= count($short_rows) ?></div><div class="t">Below <?= (int)$threshold ?>%</div></div>
    </div>

    <div class="card-x results">

        <div class="print-head">
            <h2>Lecture Attendance Report</h2>
            <div class="meta">
                <?= h(date('d M Y', strtotime($from))) ?> to <?= h(date('d M Y', strtotime($effective_to))) ?> &middot;
                Class: <?= h($class_label) ?> &middot; Subjects: <?= h($subject_label) ?><br>
                <?= (int)$sum['lec'] ?> lecture(s) held &middot; <?= count($data['students']) ?> student(s) &middot; Required: <?= (int)$threshold ?>%<br>
                Printed on <?= h(date('d M Y, h:i A')) ?>
            </div>
        </div>

        <div class="toolbar">
            <div class="search" style="flex:0 0 auto;"><i class="fas fa-search"></i><input type="text" id="search" placeholder="Search student…"></div>
            <label class="chk"><input type="checkbox" id="shortOnly"> Only below <?= (int)$threshold ?>%</label>
            <span class="vis-count" id="visCount"></span>
        </div>

        <div class="scroll-x">
        <?php
        $date_alternate = [];
        $group_num = 0;
        foreach ($data['date_groups'] as $date => $grp) {
            $is_alt = ($group_num % 2 === 1);
            foreach ($grp['indices'] as $idx) {
                if ($is_alt) $date_alternate[$idx] = true;
            }
            $group_num++;
        }
        ?>
        <table class="rep" id="dailyTable">
            <thead>
                <tr class="date-row">
                    <th style="width:44px; background:#fff;"></th>
                    <th class="stu" style="background:#fff;"></th>
                    <?php
                    $group_num = 0;
                    foreach ($data['date_groups'] as $date => $grp):
                        $is_alt = ($group_num % 2 === 1);
                    ?>
                        <th class="c <?php echo $is_alt ? 'date-alt' : ''; ?>" colspan="<?= (int)$grp['count'] ?>" style="color:#1e40af; font-weight:800; font-size:12px; letter-spacing:.5px;">
                            <i class="far fa-calendar-alt"></i> <?= h(date('d M Y', strtotime($date))) ?>
                        </th>
                    <?php $group_num++; endforeach; ?>
                    <th class="c" colspan="4" style="background:#fff;"></th>
                </tr>

                <tr>
                    <th style="width:44px">#</th>
                    <th class="stu sortable">Student</th>
                    <?php foreach ($data['lectures'] as $i => $lec): ?>
                        <th class="c lec-head <?php echo isset($date_alternate[$i]) ? 'date-alt' : ''; ?>" title="<?= h(date('d M Y', strtotime($lec['date'])) . ' · ' . $lec['subject_title']) ?>">
                            <small><?= h($lec['subject_title']) ?></small>
                        </th>
                    <?php endforeach; ?>
                    <th class="c sortable">P</th>
                    <th class="c sortable">L</th>
                    <th class="c sortable">A</th>
                    <th class="c sortable" style="min-width:150px">Attendance</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($data['students'] as $s):
                $sid = (int)$s['student_id'];
                $short = ($s['pct'] !== null && $s['pct'] < $threshold);
            ?>
                <tr data-search="<?= h(mb_strtolower($s['name'] . ' ' . $s['reg_no'] . ' ' . $s['father_name'])) ?>" data-short="<?= $short ? 1 : 0 ?>">
                    <td class="rn" style="color:#94a3b8; font-weight:600;"></td>
                    <td class="stu">
                        <?= student_cell($s) ?>
                        <span class="reg" style="margin-top:3px;"><?= h($s['reg_no']) ?></span>
                    </td>
                    <?php foreach ($data['lectures'] as $i => $lec):
                        $m = $data['marks'][$sid][$lec['key']] ?? null;
                        $st = $m ? $m['status'] : '';
                        $rem = $m ? ($m['remarks'] ?? '') : '';
                    ?>
                        <td class="c <?php echo isset($date_alternate[$i]) ? 'date-alt' : ''; ?>">
                            <?php if ($st): ?>
                                <span class="mark <?= h($st) ?>" title="<?= h(date('d M Y', strtotime($lec['date'])) . ' · ' . $lec['subject_title'] . ': ' . ($st === 'P' ? 'Present' : ($st === 'L' ? 'Leave' : 'Absent')) . ($rem ? ' — ' . $rem : '')) ?>">
                                    <?= h($st) ?>
                                </span>
                            <?php else: ?>
                                <span class="mark empty" title="No record for this lecture">·</span>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <td class="c" data-sort="<?= (int)$s['present'] ?>"><span class="num p"><?= (int)$s['present'] ?></span></td>
                    <td class="c" data-sort="<?= (int)$s['leave'] ?>"><span class="num l"><?= (int)$s['leave'] ?></span></td>
                    <td class="c" data-sort="<?= (int)$s['absent'] ?>"><span class="num a"><?= (int)$s['absent'] ?></span></td>
                    <td data-sort="<?= $s['pct'] === null ? -1 : $s['pct'] ?>"><?= pct_cell($s['pct'], $threshold) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <div class="legend">
            <span><span class="mark P" style="width:22px;height:22px;font-size:11px;">P</span> Present</span>
            <span><span class="mark L" style="width:22px;height:22px;font-size:11px;">L</span> Leave</span>
            <span><span class="mark A" style="width:22px;height:22px;font-size:11px;">A</span> Absent</span>
            <span><span class="mark empty" style="width:22px;height:22px;font-size:11px;">·</span> No record</span>
            <span style="margin-left:auto">Alternating tint marks each date group. Hover a cell for full details.</span>
        </div>
    </div>

<?php endif; ?>
</div>

<script>
(function () {
    var $ = function (s, c) { return (c || document).querySelector(s); };
    var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };

    function syncPills() {
        var n = 0;
        $$('#pills .pill').forEach(function (p) {
            var on = $('input', p) && $('input', p).checked;
            p.classList.toggle('on', on);
            if (on) n++;
        });
        var cnt = $('#pillCount');
        if (cnt) cnt.textContent = n === 0 ? 'All subjects' : n + ' selected';
    }
    function bindPillEvents() {
        $$('#pills input').forEach(function (i) { i.addEventListener('change', syncPills); });
    }
    function renderPills(list) {
        var wrap = $('#pills');
        if (!wrap) return;
        wrap.innerHTML = '';
        var tools = $('#pillTools');
        if (!list || list.length === 0) {
            wrap.innerHTML = '<div class="subject-placeholder">No subjects assigned to this class.</div>';
            if (tools) tools.style.display = 'none';
            syncPills();
            return;
        }
        list.forEach(function (s) {
            var lbl = document.createElement('label');
            lbl.className = 'pill';
            var inp = document.createElement('input');
            inp.type = 'checkbox';
            inp.name = 'subject_ids[]';
            inp.value = s.id;
            var dot = document.createElement('span');
            dot.className = 'dot';
            lbl.appendChild(inp);
            lbl.appendChild(dot);
            lbl.appendChild(document.createTextNode(' ' + s.title));
            wrap.appendChild(lbl);
        });
        if (tools) tools.style.display = '';
        bindPillEvents();
        syncPills();
    }
    bindPillEvents();
    if ($('#pillAll')) $('#pillAll').addEventListener('click', function () { $$('#pills input').forEach(function (i) { i.checked = true; }); syncPills(); });
    if ($('#pillNone')) $('#pillNone').addEventListener('click', function () { $$('#pills input').forEach(function (i) { i.checked = false; }); syncPills(); });
    syncPills();

    var classPick = $('#classPick');
    if (classPick) {
        classPick.addEventListener('change', function () {
            var cid = this.value;
            var wrap = $('#pills');
            var tools = $('#pillTools');
            if (cid === '0') {
                if (wrap) wrap.innerHTML = '<div class="subject-placeholder">Select a class above to see its subjects.</div>';
                if (tools) tools.style.display = 'none';
                syncPills();
                return;
            }
            if (wrap) wrap.innerHTML = '<div class="subject-placeholder">Loading subjects…</div>';
            if (tools) tools.style.display = 'none';

            fetch('?ajax=subjects&class_id=' + encodeURIComponent(cid), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (data) { renderPills(data); })
                .catch(function () {
                    if (wrap) wrap.innerHTML = '<div class="subject-placeholder" style="color:#dc2626;">Could not load subjects.</div>';
                });
        });
    }

    function numberRows() {
        var rows = $$('#dailyTable tbody tr');
        var n = 0;
        rows.forEach(function (tr) {
            if (tr.style.display === 'none') return;
            n++;
            var c = tr.querySelector('td.rn');
            if (c) c.textContent = n;
        });
        var vc = $('#visCount');
        if (vc) vc.textContent = n + ' student' + (n === 1 ? '' : 's');
    }

    function applyFilter() {
        var q = ($('#search').value || '').trim().toLowerCase();
        var only = $('#shortOnly') && $('#shortOnly').checked;
        $$('#dailyTable tbody tr').forEach(function (tr) {
            var ok = (!q || tr.getAttribute('data-search').indexOf(q) > -1) && (!only || tr.getAttribute('data-short') === '1');
            tr.style.display = ok ? '' : 'none';
        });
        numberRows();
    }
    if ($('#search')) $('#search').addEventListener('input', applyFilter);
    if ($('#shortOnly')) $('#shortOnly').addEventListener('change', applyFilter);

    $$('th.sortable').forEach(function (th) {
        th.addEventListener('click', function () {
            var table = th.closest('table');
            var idx = Array.prototype.indexOf.call(th.parentNode.children, th);
            var dir = th.getAttribute('data-dir') === 'asc' ? 'desc' : 'asc';
            $$('th', table).forEach(function (x) { x.removeAttribute('data-dir'); });
            th.setAttribute('data-dir', dir);
            var tb = table.tBodies[0];
            var rows = Array.prototype.slice.call(tb.rows);
            function val(tr) {
                var c = tr.cells[idx];
                var d = c.getAttribute('data-sort');
                return d !== null ? d : c.textContent.trim();
            }
            rows.sort(function (a, b) {
                var x = val(a), y = val(b);
                var nx = parseFloat(x), ny = parseFloat(y);
                var r = (!isNaN(nx) && !isNaN(ny) && String(nx) === String(x).trim() && String(ny) === String(y).trim())
                    ? nx - ny
                    : String(x).localeCompare(String(y), undefined, { numeric: true, sensitivity: 'base' });
                return dir === 'asc' ? r : -r;
            });
            rows.forEach(function (r) { tb.appendChild(r); });
            numberRows();
        });
    });

    var pc = $('#printCurrent');
    if (pc) pc.addEventListener('click', function (e) { e.preventDefault(); window.print(); });

    <?php if ($auto_print && !empty($data['lectures'])): ?>
    window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });
    <?php endif; ?>

    numberRows();
})();
</script>
</body>
</html>
<?php $conn->close(); ?>