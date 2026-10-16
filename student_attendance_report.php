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

function pct_class(?float $p, int $threshold): string {
    if ($p === null) return 'na';
    if ($p >= $threshold) return 'good';
    if ($p >= $threshold - 10) return 'warn';
    return 'bad';
}

/* ============================================================
   STUDENT ATTENDANCE REPORT BUILDER
   ============================================================ */
function build_student_daily_report(mysqli $conn, string $from, string $to, int $class_id, int $session_id): array
{
    $empty = ['students' => [], 'days' => [], 'marks' => [], 'month_groups' => []];

    if (!valid_date($from) || !valid_date($to) || $from > $to) return $empty;

    $today = date('Y-m-d');
    if ($to > $today) $to = $today;
    if ($from > $to) return $empty;

    /* --- Students --- */
    $sql = "SELECT DISTINCT sr.id AS student_id, sr.reg_no, sr.name, sr.father_name, c.title AS class_title
            FROM student_class sc
            JOIN student_registration sr ON sr.id = sc.student_registration_id
            JOIN classes c ON c.id = sc.class_id
            WHERE sr.status = 0";
    $params = [];
    $types = '';
    if ($class_id > 0)  { $sql .= " AND sc.class_id = ?";   $params[] = $class_id;  $types .= 'i'; }
    if ($session_id > 0){ $sql .= " AND sc.session_id = ?"; $params[] = $session_id;$types .= 'i'; }
    $sql .= " ORDER BY c.title, sr.reg_no, sr.name";

    $students = [];
    if ($st = $conn->prepare($sql)) {
        if ($params) $st->bind_param($types, ...$params);
        $st->execute();
        $res = $st->get_result();
        while ($res && ($r = $res->fetch_assoc())) $students[] = $r;
        $st->close();
    }
    if (!$students) return $empty;

    /* --- Days in range --- */
    $days = [];
    $cur = new DateTime($from);
    $end = new DateTime($to);
    while ($cur <= $end) {
        $days[] = $cur->format('Y-m-d');
        $cur->modify('+1 day');
    }
    if (!$days) return $empty;

    /* --- Attendance marks --- */
    $ids = array_map(fn($s) => (int)$s['student_id'], $students);
    $id_list = implode(',', $ids);

    $marks = [];
    $a_sql = "SELECT student_id, date, status FROM attendance
              WHERE date >= ? AND date <= ? AND student_id IN ($id_list)";
    if ($a_st = $conn->prepare($a_sql)) {
        $a_st->bind_param("ss", $from, $to);
        $a_st->execute();
        $res = $a_st->get_result();
        while ($res && ($r = $res->fetch_assoc())) {
            $sid = (int)$r['student_id'];
            $d   = $r['date'];
            $marks[$sid][$d] = $r['status'];
        }
        $a_st->close();
    }

    /* --- Totals per student --- */
    $total_days = count($days);
    foreach ($students as &$s) {
        $sid = (int)$s['student_id'];
        $p = 0; $l = 0; $a = 0;
        foreach ($days as $d) {
            $mk = $marks[$sid][$d] ?? '';
            if ($mk === 'P') $p++;
            elseif ($mk === 'L') $l++;
            elseif ($mk === 'A') $a++;
        }
        $s['present'] = $p;
        $s['leave']   = $l;
        $s['absent']  = $a;
        $s['total']   = $total_days;
        $s['pct']     = $total_days > 0 ? round((($p + $l) / $total_days) * 100, 1) : null;
    }
    unset($s);

    /* --- Group days by month for the header --- */
    $month_groups = [];
    foreach ($days as $d) {
        $m = date('Y-m', strtotime($d));
        if (!isset($month_groups[$m])) $month_groups[$m] = ['count' => 0, 'indices' => []];
        $month_groups[$m]['count']++;
        $month_groups[$m]['indices'][] = $d;
    }

    return [
        'students'     => $students,
        'days'         => $days,
        'marks'        => $marks,
        'month_groups' => $month_groups,
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
$selected_class_id   = intval($_GET['class_id'] ?? 0);
$selected_session_id = intval($_GET['session_id'] ?? 0);

$threshold = intval($_GET['threshold'] ?? 75);
if ($threshold < 1 || $threshold > 100) $threshold = 75;

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

$sessions = [];
if ($r = $conn->query("SELECT id, title FROM sessions ORDER BY from_dated DESC, id DESC")) {
    while ($row = $r->fetch_assoc()) $sessions[(int)$row['id']] = $row['title'];
}

/* ============================================================
   EXPORTS (CSV + Excel)
   ============================================================ */
$export = $_GET['export'] ?? '';
if ($submitted && $date_ok && in_array($export, ['csv', 'excel'], true)) {
    $data = build_student_daily_report($conn, $from, $to, $selected_class_id, $selected_session_id);
    $base = 'student_attendance_' . $from . '_to_' . $to;

    $class_label_exp = $selected_class_id > 0 ? ($classes[$selected_class_id] ?? '') : 'All classes';
    $session_label_exp = $selected_session_id > 0 ? ($sessions[$selected_session_id] ?? '') : 'All sessions';
    $effective_to_exp = ($to > date('Y-m-d')) ? date('Y-m-d') : $to;

    $title_line = 'Student Attendance Report';
    $meta_line1 = 'From ' . date('d M Y', strtotime($from)) . ' to ' . date('d M Y', strtotime($effective_to_exp))
                . ' | Class: ' . $class_label_exp
                . ' | Session: ' . $session_label_exp;
    $meta_line2 = count($data['days']) . ' day(s) | ' . count($data['students']) . ' student(s) | Required: ' . $threshold . '%'
                . ' | Printed: ' . date('d M Y, h:i A');

    /* ---------- CSV ---------- */
    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $base . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");

        $total_cols = 3 + count($data['days']) + 4;
        $mid = (int)floor($total_cols / 2);

        foreach ([$title_line, $meta_line1, $meta_line2] as $txt) {
            $row = array_fill(0, $total_cols, '');
            $row[$mid] = $txt;
            fputcsv($out, $row);
        }
        fputcsv($out, array_fill(0, $total_cols, ''));

        $head1 = ['', '', 'Date:'];
        foreach ($data['days'] as $d) {
            $head1[] = date('D d M Y', strtotime($d));
        }
        array_push($head1, '', '', '', '');
        fputcsv($out, $head1);

        $head2 = ['Reg No', 'Student', 'Father'];
        foreach ($data['days'] as $d) {
            $head2[] = date('D', strtotime($d));
        }
        array_push($head2, 'Attendance %', 'Present', 'Leave', 'Absent');
        fputcsv($out, $head2);

        foreach ($data['students'] as $s) {
            $sid = (int)$s['student_id'];
            $line = [$s['reg_no'], $s['name'], $s['father_name']];
            foreach ($data['days'] as $d) {
                $line[] = $data['marks'][$sid][$d] ?? '';
            }
            array_push($line,
                $s['pct'] === null ? '' : $s['pct'] . '%',
                $s['present'], $s['leave'], $s['absent']);
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }

    /* ---------- Excel ---------- */
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $base . '.xls"');
    echo "\xEF\xBB\xBF";
    echo '<html><head><meta charset="utf-8"><style>';
    echo 'table { border-collapse:collapse; font-family:Arial,sans-serif; font-size:10pt; }';
    echo 'td, th { border:1px solid #333; padding:3px 6px; }';
    echo 'th { background:#f1f5f9; text-align:center; font-weight:bold; }';
    echo 'th.date-hdr { background:#dbeafe; color:#1e40af; }';
    echo 'th.subj-hdr { background:#e2e8f0; color:#334155; font-size:9pt; }';
    echo 'th.total-hdr { background:#f8fafc; }';
    echo 'td.mark-P { background:#d1fae5; color:#065f46; text-align:center; font-weight:bold; }';
    echo 'td.mark-L { background:#fef3c7; color:#92400e; text-align:center; font-weight:bold; }';
    echo 'td.mark-A { background:#fee2e2; color:#991b1b; text-align:center; font-weight:bold; }';
    echo 'td.mark-empty { background:#f1f5f9; color:#cbd5e1; text-align:center; }';
    echo 'td.center { text-align:center; }';
    echo '.title { font-size:16pt; font-weight:bold; text-align:center; padding:6px 0; }';
    echo '.meta { font-size:9pt; color:#333; text-align:center; }';
    echo '</style></head><body>';

    echo '<table style="border:none;width:100%;margin-bottom:8px;">';
    echo '<tr><td class="title" style="border:none;">' . h($title_line) . '</td></tr>';
    echo '<tr><td class="meta" style="border:none;">' . h($meta_line1) . '</td></tr>';
    echo '<tr><td class="meta" style="border:none;">' . h($meta_line2) . '</td></tr>';
    echo '</table>';

    echo '<table><tr>';
    echo '<th class="date-hdr" rowspan="2">Reg No</th><th class="date-hdr" rowspan="2">Student</th><th class="date-hdr" rowspan="2">Father</th>';
    foreach ($data['month_groups'] as $m => $grp) {
        echo '<th class="date-hdr" colspan="' . (int)$grp['count'] . '">' . h(date('F Y', strtotime($m . '-01'))) . '</th>';
    }
    echo '<th class="total-hdr" rowspan="2">%</th><th class="total-hdr" rowspan="2">Present</th><th class="total-hdr" rowspan="2">Leave</th><th class="total-hdr" rowspan="2">Absent</th>';
    echo '</tr><tr>';
    foreach ($data['days'] as $d) {
        echo '<th class="subj-hdr">' . h(date('D j', strtotime($d))) . '</th>';
    }
    echo '</tr>';

    foreach ($data['students'] as $s) {
        $sid = (int)$s['student_id'];
        echo '<tr><td>' . h($s['reg_no']) . '</td><td>' . h($s['name']) . '</td><td>' . h($s['father_name']) . '</td>';
        foreach ($data['days'] as $d) {
            $mk = $data['marks'][$sid][$d] ?? '';
            if ($mk === 'P')      echo '<td class="mark-P">P</td>';
            elseif ($mk === 'L')  echo '<td class="mark-L">L</td>';
            elseif ($mk === 'A')  echo '<td class="mark-A">A</td>';
            else                  echo '<td class="mark-empty">·</td>';
        }
        echo '<td class="center">' . h($s['pct'] === null ? '—' : $s['pct'] . '%') . '</td>'
           . '<td class="center">' . (int)$s['present'] . '</td>'
           . '<td class="center">' . (int)$s['leave'] . '</td>'
           . '<td class="center">' . (int)$s['absent'] . '</td></tr>';
    }
    echo '</table></body></html>';
    exit;
}

/* ============================================================
   RUN REPORT
   ============================================================ */
$data = ['students' => [], 'days' => [], 'marks' => [], 'month_groups' => []];
$sum = ['p' => 0, 'l' => 0, 'a' => 0, 'days' => 0];
$overall_pct = null;
$short_rows = [];
$effective_to = $to;

if ($submitted && $date_ok) {
    $data = build_student_daily_report($conn, $from, $to, $selected_class_id, $selected_session_id);
    if ($to > date('Y-m-d')) $effective_to = date('Y-m-d');

    $sum['days'] = count($data['days']);
    foreach ($data['students'] as $s) {
        $sum['p'] += $s['present'];
        $sum['l'] += $s['leave'];
        $sum['a'] += $s['absent'];
        if ($s['pct'] !== null && $s['pct'] < $threshold) $short_rows[] = $s;
    }

    $total_slots = count($data['students']) * count($data['days']);
    $overall_pct = $total_slots > 0 ? round((($sum['p'] + $sum['l']) / $total_slots) * 100, 1) : null;
}

$class_label = $selected_class_id > 0 ? ($classes[$selected_class_id] ?? '') : 'All classes';
$session_label = $selected_session_id > 0 ? ($sessions[$selected_session_id] ?? '') : 'All sessions';

$qs = ['generate' => 1, 'from' => $from, 'to' => $to, 'class_id' => $selected_class_id, 'session_id' => $selected_session_id, 'threshold' => $threshold];
$export_base = '?' . http_build_query($qs);

function student_cell(array $r): string {
    $s = '<div class="stu-name">' . h($r['name']) . '</div>';
    if (!empty($r['father_name'])) $s .= '<div class="stu-sub">S/O ' . h($r['father_name']) . '</div>';
    return $s;
}
function pct_cell(?float $p, int $threshold): string {
    $cls = pct_class($p, $threshold);
    $w = $p === null ? 0 : min(100, max(0, $p));
    return '<div class="pbar"><span class="pfill ' . $cls . '" style="width:' . $w . '%"></span></div><span class="ptxt ' . $cls . '">' . h($p === null ? '—' : $p . '%') . '</span>';
}

/* ============================================================
   PRINT CHUNKING — landscape, no rotation of the table
   Only the date labels run vertically inside their column headers.
   ============================================================ */
$PRINT_DAY_COL_MM   = 6.5;
$PRINT_PAGE_W_MM    = 285;   // A4 landscape printable width (297 - 2*6mm)
$PRINT_RESERVED_MM  = 200;   // # + Reg + Student + % + P + L + A + padding
$PRINT_AVAIL_MM     = $PRINT_PAGE_W_MM - $PRINT_RESERVED_MM;
$PRINT_MAX_COLS     = max(1, (int)floor($PRINT_AVAIL_MM / $PRINT_DAY_COL_MM));

$print_pages = [];
if (!empty($data['days'])) {
    $cur_days  = [];
    $cur_groups= [];
    $cur_count = 0;

    foreach ($data['month_groups'] as $m => $grp) {
        $grp_days = $grp['indices'];
        $grp_count = count($grp_days);

        if ($cur_count > 0 && ($cur_count + $grp_count) > $PRINT_MAX_COLS) {
            $print_pages[] = ['days' => $cur_days, 'groups' => $cur_groups];
            $cur_days = []; $cur_groups = []; $cur_count = 0;
        }

        if ($grp_count > $PRINT_MAX_COLS) {
            foreach (array_chunk($grp_days, $PRINT_MAX_COLS) as $chunk) {
                if ($cur_count > 0) {
                    $print_pages[] = ['days' => $cur_days, 'groups' => $cur_groups];
                    $cur_days = []; $cur_groups = []; $cur_count = 0;
                }
                $print_pages[] = [
                    'days'   => $chunk,
                    'groups' => [$m => count($chunk)],
                ];
            }
            continue;
        }

        foreach ($grp_days as $d) $cur_days[] = $d;
        $cur_groups[$m] = ($cur_groups[$m] ?? 0) + $grp_count;
        $cur_count += $grp_count;
    }
    if ($cur_days) $print_pages[] = ['days' => $cur_days, 'groups' => $cur_groups];
}
$total_parts = count($print_pages);
$multi_year = substr($from, 0, 4) !== substr($effective_to, 0, 4);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Student Attendance Report</title>
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
    .btn-go { background:var(--brand); color:#fff; border:0; border-radius:8px; padding:9px 22px; font-weight:600; font-size:14px; margin-left:auto; cursor:pointer; }
    .btn-go:hover { background:var(--brand-d); }

    .stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:18px; }
    .stat { background:#fff; border-radius:12px; padding:14px 16px; box-shadow:0 2px 8px rgba(15,23,42,.06); border-left:4px solid var(--brand); }
    .stat .n { font-size:23px; font-weight:700; line-height:1; }
    .stat .t { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.4px; font-weight:700; margin-top:6px; }
    .stat.s-d { border-color:var(--brand); } .stat.s-d .n { color:var(--brand); }
    .stat.s-s { border-color:#7c3aed; } .stat.s-s .n { color:#7c3aed; }
    .stat.s-p { border-color:var(--good); } .stat.s-p .n { color:var(--good); }
    .stat.s-l { border-color:#f59e0b; } .stat.s-l .n { color:var(--warn); }
    .stat.s-a { border-color:var(--bad); } .stat.s-a .n { color:var(--bad); }
    .stat.s-o { border-color:#0891b2; } .stat.s-o .n { color:#0891b2; }

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
    .day-head small { display:block; font-weight:700; color:#334155; text-transform:none; letter-spacing:0; font-size:11.5px; }

    .legend { padding:12px 18px; font-size:12px; color:var(--muted); display:flex; gap:16px; flex-wrap:wrap; align-items:center; border-top:1px solid var(--line); }
    .empty { padding:56px 24px; text-align:center; }
    .empty .ic { width:76px; height:76px; border-radius:50%; background:#eff6ff; color:var(--brand); display:inline-flex; align-items:center; justify-content:center; font-size:32px; margin-bottom:14px; }
    .empty h4 { font-size:18px; font-weight:700; } .empty p { color:var(--muted); font-size:14px; margin:0; }

    .print-only { display:none; }

    /* ======================================================================
       PRINT — A4 LANDSCAPE, NORMAL TABLE, DATES ROTATED VERTICALLY
       Table stays horizontal. Only the date labels run vertically.
       ====================================================================== */
    @page { size: A4 landscape; margin: 6mm; }

    @media print {
        html, body {
            background:#fff !important; margin:0 !important; padding:0 !important;
            font-family:Arial, Helvetica, sans-serif; color:#000;
            -webkit-print-color-adjust:exact; print-color-adjust:exact;
        }
        .no-print, .hero, .filters, .stats, .toolbar, .legend, .screen-only,
        .navbar, nav, header, footer, #navbar, #header, .navbar-fixed-top, .navbar-default { display:none !important; }
        .wrap { max-width:none; padding:0; margin:0; }
        .card-x { box-shadow:none; border-radius:0; background:#fff; }
        .results { overflow:visible; }

        .print-only { display:block !important; }

        .print-sheet {
            page-break-after: always;
            break-after: page;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .print-sheet:last-child { page-break-after: auto; break-after: auto; }

        .ph { text-align:center; margin:0 0 3mm; }
        .ph h2 { font-size:14px; font-weight:700; margin:0 0 2px; }
        .ph .meta { font-size:9.5px; color:#222; line-height:1.3; }

        table.ptable {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        table.ptable thead { display: table-header-group; }
        table.ptable tr { break-inside: avoid; page-break-inside: avoid; }

        table.ptable th, table.ptable td {
            border: 1px solid #333;
            padding: 2px 3px;
            font-size: 10px;
            line-height: 1.15;
            text-align: center;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        table.ptable thead th { background: #e9edf3 !important; font-weight: 700; font-size: 10px; }
        table.ptable thead th.pdate { background: #d6e2f7 !important; font-size: 10px; padding: 2px 0; font-weight: 700; }
        table.ptable thead th.pdate.alt { background: #c2d3f0 !important; }

        /* ---- DATE HEADER CELLS: text runs VERTICALLY ---- */
        table.ptable thead th.day {
            background: #e2e8f0 !important;
            font-size: 9.5px;
            font-weight: 600;
            padding: 2px 1px;
            height: 22mm;
            vertical-align: bottom;
            overflow: visible;
            white-space: normal;
            line-height: 1.05;
        }
        table.ptable thead th.day span {
            display: inline-block;
            writing-mode: vertical-rl;
            -webkit-writing-mode: vertical-rl;
            transform: rotate(180deg);
            white-space: nowrap;
            max-height: 21mm;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        table.ptable td.preg { font-weight: 700; font-size: 10px; }
        table.ptable td.pname {
            text-align: left;
            padding-left: 3px;
            font-weight: 600;
            font-size: 10px;
        }
        table.ptable td.pname .pf { font-weight: 400; color: #555; font-size: 8.5px; }
        table.ptable tbody tr { height: 6mm; }
        table.ptable tbody tr:nth-child(even) td { background: #f6f7f9; }
        table.ptable td.pm { font-weight: 700; font-size: 11px; }
        table.ptable td.pm.L { background: #fde9b8 !important; }
        table.ptable td.pm.A { background: #f4b9b9 !important; }
        table.ptable td.pm.e { color: #aaa; font-weight: 400; }
        table.ptable td.pt { font-weight: 700; font-size: 10px; }
        table.ptable td.ppct { font-weight: 700; font-size: 10px; }
        table.ptable td.ppct.bad { background: #f4b9b9 !important; }
        table.ptable td.ppct.warn { background: #fde9b8 !important; }
        table.ptable td.pn { font-size: 9px; color:#444; font-weight:600; }

        .plegend { margin-top: 3mm; font-size: 9.5px; color: #333; text-align: center; }
        .ppart   { text-align: center; font-size: 9.5px; color: #444; margin-top: 1mm; font-style: italic; }
    }
</style>
</head>
<body>
<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="hero no-print">
        <div>
            <h1><i class="fas fa-chart-bar mr-2"></i>Student Attendance Report</h1>
            <div class="sub">
                <?php if ($submitted && $date_ok): ?>
                    <?= h(date('d M Y', strtotime($from))) ?> – <?= h(date('d M Y', strtotime($effective_to))) ?>
                    &middot; <?= h($class_label) ?> &middot; <?= h($session_label) ?>
                <?php else: ?>
                    Pick a date range, class and session — then generate
                <?php endif; ?>
            </div>
        </div>
        <?php if ($submitted && $date_ok && !empty($data['students'])): ?>
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
                <div class="col-md-2 mb-3">
                    <label class="lb">From date</label>
                    <input type="date" class="form-control" name="from" id="from" value="<?= h($from) ?>" required>
                </div>
                <div class="col-md-2 mb-3">
                    <label class="lb">To date</label>
                    <input type="date" class="form-control" name="to" id="to" value="<?= h($to) ?>" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="lb">Session</label>
                    <select class="form-control" name="session_id" id="sessionPick">
                        <option value="0">— All sessions —</option>
                        <?php foreach ($sessions as $sid => $sname): ?>
                            <option value="<?= (int)$sid ?>" <?= ((int)$sid === $selected_session_id) ? 'selected' : '' ?>><?= h($sname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="lb">Class</label>
                    <select class="form-control" name="class_id" id="classPick">
                        <option value="0">— All classes —</option>
                        <?php foreach ($classes as $cid => $cname): ?>
                            <option value="<?= (int)$cid ?>" <?= ((int)$cid === $selected_class_id) ? 'selected' : '' ?>><?= h($cname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 mb-3">
                    <label class="lb">Required %</label>
                    <input type="number" class="form-control" name="threshold" min="1" max="100" value="<?= (int)$threshold ?>">
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
        <p>Pick a date range, session and class, then click <strong>Generate report</strong>.</p>
    </div>

<?php elseif (!$date_ok): ?>
    <div class="card-x empty no-print">
        <div class="ic" style="background:#fef2f2;color:#dc2626"><i class="fas fa-exclamation-triangle"></i></div>
        <h4>Check the dates</h4>
        <p><?= h($date_msg) ?></p>
    </div>

<?php elseif (empty($data['students'])): ?>
    <div class="card-x empty no-print">
        <div class="ic" style="background:#f1f5f9;color:#64748b"><i class="fas fa-inbox"></i></div>
        <h4>No students found</h4>
        <p>No students matched the selected filters.</p>
    </div>

<?php else: ?>

    <div class="stats no-print">
        <div class="stat s-d"><div class="n"><?= (int)$sum['days'] ?></div><div class="t">Days</div></div>
        <div class="stat s-s"><div class="n"><?= count($data['students']) ?></div><div class="t">Students</div></div>
        <div class="stat s-p"><div class="n"><?= (int)$sum['p'] ?></div><div class="t">Total Present</div></div>
        <div class="stat s-l"><div class="n"><?= (int)$sum['l'] ?></div><div class="t">Total Leave</div></div>
        <div class="stat s-a"><div class="n"><?= (int)$sum['a'] ?></div><div class="t">Total Absent</div></div>
        <div class="stat s-o"><div class="n"><?= h($overall_pct === null ? '—' : $overall_pct . '%') ?></div><div class="t">Overall Attendance</div></div>
        <div class="stat s-a"><div class="n"><?= count($short_rows) ?></div><div class="t">Below <?= (int)$threshold ?>%</div></div>
    </div>

    <div class="card-x results">

        <div class="toolbar no-print">
            <div class="search" style="flex:0 0 auto;"><i class="fas fa-search"></i><input type="text" id="search" placeholder="Search student…"></div>
            <label class="chk"><input type="checkbox" id="shortOnly"> Only below <?= (int)$threshold ?>%</label>
            <span class="vis-count" id="visCount"></span>
        </div>

        <!-- ================= SCREEN TABLE ================= -->
        <div class="scroll-x screen-only">
        <?php
        $day_alternate = [];
        $group_num = 0;
        foreach ($data['month_groups'] as $m => $grp) {
            $is_alt = ($group_num % 2 === 1);
            foreach ($grp['indices'] as $d) {
                if ($is_alt) $day_alternate[$d] = true;
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
                    foreach ($data['month_groups'] as $m => $grp):
                        $is_alt = ($group_num % 2 === 1);
                    ?>
                        <th class="c <?= $is_alt ? 'date-alt' : '' ?>" colspan="<?= (int)$grp['count'] ?>" style="color:#1e40af; font-weight:800; font-size:12px; letter-spacing:.5px;">
                            <i class="far fa-calendar-alt"></i> <?= h(date('F Y', strtotime($m . '-01'))) ?>
                        </th>
                    <?php $group_num++; endforeach; ?>
                    <th class="c" colspan="4" style="background:#fff;"></th>
                </tr>

                <tr>
                    <th style="width:44px">#</th>
                    <th class="stu sortable">Student</th>
                    <?php foreach ($data['days'] as $d): ?>
                        <th class="c day-head <?= isset($day_alternate[$d]) ? 'date-alt' : '' ?>" title="<?= h(date('l, d M Y', strtotime($d))) ?>">
                            <small><?= h(date('D j', strtotime($d))) ?></small>
                        </th>
                    <?php endforeach; ?>
                    <th class="c sortable" style="min-width:150px">Attendance</th>
                    <th class="c sortable">P</th>
                    <th class="c sortable">L</th>
                    <th class="c sortable">A</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($data['students'] as $s):
                $sid = (int)$s['student_id'];
                $short = ($s['pct'] !== null && $s['pct'] < $threshold);
            ?>
                <tr data-search="<?= h(mb_strtolower($s['name'] . ' ' . $s['reg_no'] . ' ' . $s['father_name'])) ?>" data-short="<?= $short ? 1 : 0 ?>">
                    <td class="rn" style="color:#94a3b8; font-weight:600;"></td>
                    <td class="stu" data-sort="<?= h(mb_strtolower($s['name'])) ?>">
                        <?= student_cell($s) ?>
                        <span class="reg" style="margin-top:3px;"><?= h($s['reg_no']) ?></span>
                    </td>
                    <?php foreach ($data['days'] as $d):
                        $stt = $data['marks'][$sid][$d] ?? '';
                    ?>
                        <td class="c <?= isset($day_alternate[$d]) ? 'date-alt' : '' ?>">
                            <?php if ($stt): ?>
                                <span class="mark <?= h($stt) ?>" title="<?= h(date('l, d M Y', strtotime($d)) . ': ' . ($stt === 'P' ? 'Present' : ($stt === 'L' ? 'Leave' : 'Absent'))) ?>"><?= h($stt) ?></span>
                            <?php else: ?>
                                <span class="mark empty" title="No record for <?= h(date('d M Y', strtotime($d))) ?>">·</span>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <td data-sort="<?= $s['pct'] === null ? -1 : $s['pct'] ?>"><?= pct_cell($s['pct'], $threshold) ?></td>
                    <td class="c" data-sort="<?= (int)$s['present'] ?>"><span class="num p"><?= (int)$s['present'] ?></span></td>
                    <td class="c" data-sort="<?= (int)$s['leave'] ?>"><span class="num l"><?= (int)$s['leave'] ?></span></td>
                    <td class="c" data-sort="<?= (int)$s['absent'] ?>"><span class="num a"><?= (int)$s['absent'] ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <div class="legend no-print">
            <span><span class="mark P" style="width:22px;height:22px;font-size:11px;">P</span> Present</span>
            <span><span class="mark L" style="width:22px;height:22px;font-size:11px;">L</span> Leave</span>
            <span><span class="mark A" style="width:22px;height:22px;font-size:11px;">A</span> Absent</span>
            <span><span class="mark empty" style="width:22px;height:22px;font-size:11px;">·</span> No record</span>
            <span style="margin-left:auto">Print: <?= (int)$total_parts ?> page(s). Dates run vertically in their column headers.</span>
        </div>

        <!-- ================= PRINT SHEETS — normal table, rotated date labels ================= -->
        <div class="print-only">
        <?php foreach ($print_pages as $pi => $page): ?>
            <div class="print-sheet">
                <div class="ph">
                    <h2>Student Attendance Report</h2>
                    <div class="meta">
                        Class: <b><?= h($class_label) ?></b> &middot; Session: <?= h($session_label) ?> &middot;
                        <?= h(date('d M Y', strtotime($from))) ?> to <?= h(date('d M Y', strtotime($effective_to))) ?><br>
                        <?= (int)$sum['days'] ?> day(s) &middot; <?= count($data['students']) ?> student(s) &middot; Required: <?= (int)$threshold ?>%
                        &middot; Printed <?= h(date('d M Y, h:i A')) ?>
                    </div>
                </div>

                <table class="ptable">
                    <colgroup>
                        <col style="width:30px">
                        <col style="width:70px">
                        <col style="width:140px">
                        <?php foreach ($page['days'] as $d): ?><col style="width:22px"><?php endforeach; ?>
                        <col style="width:52px">
                        <col style="width:26px">
                        <col style="width:26px">
                        <col style="width:26px">
                    </colgroup>
                    <thead>
                        <tr>
                            <th rowspan="2">#</th>
                            <th rowspan="2">Reg No</th>
                            <th rowspan="2">Student</th>
                            <?php $gi = 0; foreach ($page['groups'] as $m => $span):
                                if ($span <= 0) continue;
                                $ts = strtotime($m . '-01');
                            ?>
                                <th class="pdate <?= $gi % 2 ? 'alt' : '' ?>" colspan="<?= (int)$span ?>">
                                    <?= h(date('M Y', $ts)) ?>
                                </th>
                            <?php $gi++; endforeach; ?>
                            <th rowspan="2">%</th>
                            <th rowspan="2">P</th>
                            <th rowspan="2">L</th>
                            <th rowspan="2">A</th>
                        </tr>
                        <tr>
                            <?php foreach ($page['days'] as $d): ?>
                                <th class="day"><span><?= h(date('D j M', strtotime($d))) ?></span></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $rn = 0; foreach ($data['students'] as $s): $rn++; $sid = (int)$s['student_id']; ?>
                        <tr>
                            <td class="pn"><?= $rn ?></td>
                            <td class="preg"><?= h($s['reg_no']) ?></td>
                            <td class="pname"><?= h($s['name']) ?><?php if (!empty($s['father_name'])): ?> <span class="pf">S/O <?= h($s['father_name']) ?></span><?php endif; ?></td>
                            <?php foreach ($page['days'] as $d):
                                $mk = $data['marks'][$sid][$d] ?? '';
                                if ($mk === 'P' || $mk === 'L' || $mk === 'A'): ?>
                                    <td class="pm <?= $mk ?>"><?= $mk ?></td>
                                <?php else: ?>
                                    <td class="pm e">·</td>
                                <?php endif;
                            endforeach; ?>
                            <td class="ppct <?= $s['pct'] !== null && pct_class($s['pct'], $threshold) !== 'good' ? pct_class($s['pct'], $threshold) : '' ?>"><?= h($s['pct'] === null ? '—' : $s['pct'] . '%') ?></td>
                            <td class="pt"><?= (int)$s['present'] ?></td>
                            <td class="pt"><?= (int)$s['leave'] ?></td>
                            <td class="pt"><?= (int)$s['absent'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="plegend">
                    P = Present &nbsp;&middot;&nbsp; L = Leave &nbsp;&middot;&nbsp; A = Absent &nbsp;&middot;&nbsp; · = No record
                    &nbsp;&middot;&nbsp; Absent counts saved A rows only &nbsp;&middot;&nbsp; Shaded % = below <?= (int)$threshold ?>%
                </div>
                <?php if ($total_parts > 1): ?>
                    <div class="ppart">Part <?= ($pi + 1) ?> of <?= (int)$total_parts ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    </div>

<?php endif; ?>
</div>

<script>
(function () {
    var $ = function (s, c) { return (c || document).querySelector(s); };
    var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };

    function numberRows() {
        var n = 0;
        $$('#dailyTable tbody tr').forEach(function (tr) {
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

    $$('#dailyTable th.sortable').forEach(function (th) {
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
                var x = val(a), y = val(b), nx = parseFloat(x), ny = parseFloat(y);
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

    if ($('#dailyTable')) numberRows();
})();
</script>
</body>
</html>
<?php $conn->close(); ?>