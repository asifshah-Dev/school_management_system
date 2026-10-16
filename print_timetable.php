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
   FILTERS
   ============================================================ */
$session_id = (int)($_GET['session_id'] ?? 0);
if ($session_id === 0) {
    $r = $conn->query("SELECT id FROM sessions WHERE status = 0 ORDER BY id DESC LIMIT 1");
    if ($r && ($row = $r->fetch_assoc())) $session_id = (int)$row['id'];
}

$sessions = [];
$s_res = $conn->query("SELECT id, title FROM sessions ORDER BY id DESC");
if ($s_res) while ($r = $s_res->fetch_assoc()) $sessions[$r['id']] = $r['title'];

$class_options = [];
$q = $conn->query("SELECT c.id AS class_id, c.title AS class_title, COALESCE(cs.id, 0) AS class_section_id, s.title AS section_title, CASE WHEN cs.id IS NULL THEN c.title ELSE CONCAT(c.title, ' - ', s.title) END AS display_label FROM classes c LEFT JOIN class_sections cs ON cs.class_id = c.id AND (cs.status = 0 OR cs.status IS NULL) LEFT JOIN sections s ON s.id = cs.section_id WHERE c.status = 0 OR c.status IS NULL ORDER BY c.title, s.title");
if ($q) while ($r = $q->fetch_assoc()) $class_options[] = $r;

$sel_class_id         = (int)($_GET['class_id'] ?? 0);
$sel_class_section_id = (int)($_GET['class_section_id'] ?? 0);

$time_slots = [];
$q = $conn->query("SELECT id, title, start_time, end_time FROM time_slots ORDER BY start_time");
if ($q) while ($r = $q->fetch_assoc()) $time_slots[] = $r;

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

$sel_label = '';
foreach ($class_options as $co) {
    if ((int)$co['class_id'] === $sel_class_id && (int)$co['class_section_id'] === $sel_class_section_id) {
        $sel_label = $co['display_label'];
        break;
    }
}
$session_title = $sessions[$session_id] ?? '';

/* ============================================================
   TIMETABLE DATA
   ============================================================ */
$timetable_data = [];
if ($sel_class_id > 0) {
    if ($sel_class_section_id > 0) {
        $stmt = $conn->prepare("SELECT t.day_id, t.time_slot_id, t.room_no, s.title AS subject_title, u.username AS teacher_name FROM timetable t LEFT JOIN subjects s ON s.id = t.subject_id LEFT JOIN users u ON u.id = t.teacher_id WHERE t.class_id = ? AND t.class_section_id = ?");
        $stmt->bind_param("ii", $sel_class_id, $sel_class_section_id);
    } else {
        $stmt = $conn->prepare("SELECT t.day_id, t.time_slot_id, t.room_no, s.title AS subject_title, u.username AS teacher_name FROM timetable t LEFT JOIN subjects s ON s.id = t.subject_id LEFT JOIN users u ON u.id = t.teacher_id WHERE t.class_id = ? AND t.class_section_id IS NULL");
        $stmt->bind_param("i", $sel_class_id);
    }
    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $timetable_data[(int)$row['day_id']][(int)$row['time_slot_id']] = $row;
            }
        }
        $stmt->close();
    }
}

/* Build export grid — plain array, reused by JS for CSV + XLSX */
$grid_rows = [];
foreach ($time_slots as $slot) {
    $slot_id = (int)$slot['id'];
    $period_label = ($slot['title'] ?: ('Period ' . $slot_id)) . ' (' . date('H:i', strtotime($slot['start_time'])) . '-' . date('H:i', strtotime($slot['end_time'])) . ')';

    $row = ['period' => $period_label, 'cells' => []];
    foreach ($days as $di => $day) {
        $day_id = $di + 1;
        $cell = $timetable_data[$day_id][$slot_id] ?? null;
        $val = '';
        if ($cell) {
            $parts = [];
            if (!empty($cell['subject_title'])) $parts[] = $cell['subject_title'];
            if (!empty($cell['teacher_name'])) $parts[] = $cell['teacher_name'];
            if (!empty($cell['room_no'])) $parts[] = 'Room ' . $cell['room_no'];
            $val = implode(' / ', $parts);
        }
        $row['cells'][] = $val;
    }
    $grid_rows[] = $row;
}

$export_title = 'School Management System - Class Timetable';
$export_meta  = 'Class: ' . ($sel_label ?: '-') . '   |   Session: ' . ($session_title ?: '-') . '   |   Printed: ' . date('d M Y');
$export_filename = 'timetable_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $sel_label ?: 'class') . '_' . date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Print Timetable<?php echo $sel_label ? ' — ' . htmlspecialchars($sel_label) : ''; ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #eef1f5; font-family: 'Inter', sans-serif; color: #1e293b; padding-bottom: 40px; }
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
    .head .actions { display: flex; gap: 10px; flex-wrap: wrap; }
    .btn-white { background: #fff; color: #1e40af; border: 1px solid #fff; font-weight: 700; font-size: 13px; padding: 10px 20px; border-radius: 8px; display: inline-flex; align-items: center; gap: 8px; cursor: pointer; text-decoration: none; font-family: inherit; }
    .btn-white:hover, .btn-white:focus { background: #f1f5f9; color: #1e3a8a; }
    .btn-white-ghost { background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.3); font-weight: 700; font-size: 13px; padding: 10px 20px; border-radius: 8px; display: inline-flex; align-items: center; gap: 8px; cursor: pointer; text-decoration: none; font-family: inherit; }
    .btn-white-ghost:hover, .btn-white-ghost:focus { background: rgba(255,255,255,0.25); color: #fff; }

    .card { background: #fff; padding: 26px 32px 34px 32px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

    .selector { display: flex; gap: 14px; align-items: flex-end; flex-wrap: wrap; padding-bottom: 20px; margin-bottom: 22px; border-bottom: 1px solid #e2e8f0; }
    .selector .field { min-width: 260px; }
    .selector label { display: block; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
    .selector select { width: 100%; height: 42px; padding: 0 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; font-family: inherit; background: #fff; }
    .selector select:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 4px rgba(30,64,175,0.1); }

    .empty { padding: 60px 20px; text-align: center; color: #94a3b8; }
    .empty .big { font-size: 44px; opacity: 0.4; margin-bottom: 10px; }
    .empty h3 { font-size: 17px; color: #475569; font-weight: 600; margin-bottom: 6px; }

    .print-area { background: #fff; }
    .print-head { text-align: center; padding-bottom: 14px; border-bottom: 2px solid #000; margin-bottom: 14px; }
    .print-head .school { font-size: 20px; font-weight: 800; letter-spacing: 0.5px; color: #000; text-transform: uppercase; }
    .print-head .doctype { font-size: 13px; font-weight: 600; color: #000; margin-top: 4px; }
    .print-head .meta { font-size: 11px; color: #333; margin-top: 8px; display: flex; justify-content: center; gap: 26px; flex-wrap: wrap; }
    .print-head .meta strong { color: #000; }

    .tbl-wrap { overflow-x: auto; }
    table.tt { width: 100%; border-collapse: collapse; background: #fff; }
    table.tt th, table.tt td { border: 1px solid #333; padding: 8px 10px; font-size: 12px; color: #000; vertical-align: top; text-align: left; }
    table.tt thead th { background: #e5e7eb; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; text-align: center; padding: 10px 8px; }
    table.tt thead th.period-head { width: 130px; text-align: left; background: #d1d5db; }
    table.tt tbody td.period-cell { background: #f3f4f6; font-weight: 700; width: 130px; }
    table.tt tbody td.period-cell .pt { font-size: 12px; color: #000; }
    table.tt tbody td.period-cell .ptime { font-size: 10px; color: #4b5563; margin-top: 2px; font-family: 'SF Mono', monospace; }
    table.tt tbody td.period-cell.is-break { background: #e5e7eb; font-style: italic; }

    .cell-subj { font-size: 12px; font-weight: 700; color: #000; line-height: 1.3; }
    .cell-tch  { font-size: 11px; color: #333; margin-top: 3px; }
    .cell-room { font-size: 10px; color: #666; margin-top: 2px; }
    .cell-empty { font-size: 12px; color: #cbd5e1; text-align: center; }

    @media print {
        @page { size: A4 landscape; margin: 10mm; }
        body { background: #fff !important; padding: 0 !important; margin: 0 !important; }
        body * { visibility: hidden !important; }
        .print-area, .print-area * { visibility: visible !important; }
        .print-area { position: absolute !important; left: 0 !important; top: 0 !important; width: 100% !important; padding: 0 !important; margin: 0 !important; background: #fff !important; }
        .no-print, .head, .selector, nav, .navbar, header, footer,
        #navbar, #header, .navbar-fixed-top, .navbar-default { display: none !important; }
        .card { box-shadow: none !important; padding: 0 !important; border-radius: 0 !important; }
        .print-head { border-bottom: 2px solid #000 !important; margin-bottom: 10px !important; }
        .print-head .school { font-size: 16px !important; }
        .print-head .doctype { font-size: 12px !important; }
        .print-head .meta { font-size: 10px !important; gap: 20px !important; }
        table.tt { font-size: 11px !important; }
        table.tt th, table.tt td { border: 1px solid #000 !important; padding: 5px 6px !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        table.tt thead th { background: #e5e7eb !important; font-size: 10px !important; }
        table.tt thead th.period-head { background: #d1d5db !important; }
        table.tt tbody td.period-cell { background: #f3f4f6 !important; }
        table.tt tbody td.period-cell.is-break { background: #e5e7eb !important; }
        .cell-subj { font-size: 11px !important; }
        .cell-tch  { font-size: 10px !important; }
        .cell-room { font-size: 9px !important; }
    }
</style>
</head>
<body>
<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head no-print">
        <div>
            <h1><i class="fas fa-print"></i> Print Timetable</h1>
            <div class="sub">
                <?php echo $sel_label ? htmlspecialchars($sel_label) : 'Pick a class'; ?>
                <?php if ($session_title): ?> &middot; <?php echo htmlspecialchars($session_title); ?><?php endif; ?>
            </div>
        </div>
        <div class="actions">
            <a href="create_timetable.php?session_id=<?php echo (int)$session_id; ?><?php echo $sel_class_id ? '&class_id=' . (int)$sel_class_id . '&class_section_id=' . (int)$sel_class_section_id : ''; ?>" class="btn-white-ghost">
                <i class="fas fa-pen"></i> Edit
            </a>
            <?php if ($sel_class_id > 0 && !empty($time_slots)): ?>
                <button type="button" class="btn-white-ghost" id="btnExportCsv">
                    <i class="fas fa-file-csv"></i> CSV
                </button>
                <button type="button" class="btn-white-ghost" id="btnExportXlsx">
                    <i class="fas fa-file-excel"></i> Excel
                </button>
                <button type="button" class="btn-white" onclick="window.print();">
                    <i class="fas fa-print"></i> Print
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">

        <form method="get" class="selector no-print">
            <div class="field">
                <label>Session</label>
                <select name="session_id" onchange="this.form.submit()">
                    <?php foreach ($sessions as $sid => $sname): ?>
                        <option value="<?php echo (int)$sid; ?>" <?php echo ((int)$sid === $session_id) ? 'selected' : ''; ?>><?php echo htmlspecialchars($sname); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Class / Class-Section</label>
                <select name="pick" onchange="onPick(this)">
                    <option value="">— Select —</option>
                    <?php foreach ($class_options as $co):
                        $val = $co['class_id'] . '|' . $co['class_section_id'];
                        $sel = ((int)$co['class_id'] === $sel_class_id && (int)$co['class_section_id'] === $sel_class_section_id);
                    ?>
                        <option value="<?php echo htmlspecialchars($val); ?>" <?php echo $sel ? 'selected' : ''; ?>><?php echo htmlspecialchars($co['display_label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
        <form method="get" id="pickForm" style="display:none;">
            <input type="hidden" name="session_id" value="<?php echo (int)$session_id; ?>">
            <input type="hidden" name="class_id" id="p_class_id">
            <input type="hidden" name="class_section_id" id="p_class_section_id">
        </form>

        <script>
        function onPick(sel) {
            var v = sel.value || '';
            if (!v) { location.href = 'print_timetable.php?session_id=' + <?php echo (int)$session_id; ?>; return; }
            var p = v.split('|');
            document.getElementById('p_class_id').value = p[0] || 0;
            document.getElementById('p_class_section_id').value = p[1] || 0;
            document.getElementById('pickForm').submit();
        }
        </script>

        <?php if (empty($time_slots)): ?>
            <div class="empty no-print">
                <div class="big"><i class="fas fa-clock"></i></div>
                <h3>No time slots defined</h3>
                <p>Add periods in Time Slots first.</p>
            </div>
        <?php elseif ($sel_class_id === 0): ?>
            <div class="empty no-print">
                <div class="big"><i class="fas fa-graduation-cap"></i></div>
                <h3>Pick a class to preview</h3>
            </div>
        <?php else: ?>

            <div class="print-area">

                <div class="print-head">
                    <div class="school">School Management System</div>
                    <div class="doctype">Class Timetable</div>
                    <div class="meta">
                        <span><strong>Class:</strong> <?php echo htmlspecialchars($sel_label ?: '—'); ?></span>
                        <span><strong>Session:</strong> <?php echo htmlspecialchars($session_title ?: '—'); ?></span>
                        <span><strong>Printed:</strong> <?php echo date('d M Y'); ?></span>
                    </div>
                </div>

                <div class="tbl-wrap">
                    <table class="tt">
                        <thead>
                            <tr>
                                <th class="period-head">Period</th>
                                <?php foreach ($days as $day): ?>
                                    <th><?php echo htmlspecialchars($day); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($time_slots as $slot):
                                $slot_id = (int)$slot['id'];
                                $is_break = (stripos($slot['title'] ?? '', 'break') !== false);
                            ?>
                            <tr>
                                <td class="period-cell <?php echo $is_break ? 'is-break' : ''; ?>">
                                    <div class="pt"><?php echo htmlspecialchars($slot['title'] ?: ('Period ' . $slot_id)); ?></div>
                                    <div class="ptime"><?php echo date('H:i', strtotime($slot['start_time'])); ?>&ndash;<?php echo date('H:i', strtotime($slot['end_time'])); ?></div>
                                </td>
                                <?php foreach ($days as $di => $day):
                                    $day_id = $di + 1;
                                    $cell = $timetable_data[$day_id][$slot_id] ?? null;
                                ?>
                                <td>
                                    <?php if ($cell): ?>
                                        <div class="cell-subj"><?php echo htmlspecialchars($cell['subject_title'] ?? '—'); ?></div>
                                        <?php if (!empty($cell['teacher_name'])): ?>
                                            <div class="cell-tch"><?php echo htmlspecialchars($cell['teacher_name']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($cell['room_no'])): ?>
                                            <div class="cell-room">Room: <?php echo htmlspecialchars($cell['room_no']); ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="cell-empty">&mdash;</div>
                                    <?php endif; ?>
                                </td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            </div>

        <?php endif; ?>

    </div>
</div>

<?php if ($sel_class_id > 0 && !empty($time_slots)): ?>
<script>
/* Export data (already computed server-side, embedded here for JS) */
var TT_EXPORT = {
    title:    <?php echo json_encode($export_title, JSON_UNESCAPED_UNICODE); ?>,
    meta:     <?php echo json_encode($export_meta, JSON_UNESCAPED_UNICODE); ?>,
    days:     <?php echo json_encode($days, JSON_UNESCAPED_UNICODE); ?>,
    rows:     <?php echo json_encode($grid_rows, JSON_UNESCAPED_UNICODE); ?>,
    filename: <?php echo json_encode($export_filename, JSON_UNESCAPED_UNICODE); ?>
};

/* Build 2D array: 
   row 0: title
   row 1: meta
   row 2: blank
   row 3: header (Period + days)
   row 4+: period + cells
*/
function buildMatrix() {
    var m = [];
    var header = ['Period'].concat(TT_EXPORT.days);
    var blank = new Array(header.length).fill('');

    m.push([TT_EXPORT.title].concat(new Array(header.length - 1).fill('')));
    m.push([TT_EXPORT.meta].concat(new Array(header.length - 1).fill('')));
    m.push(blank.slice());
    m.push(header);

    TT_EXPORT.rows.forEach(function(r) {
        m.push([r.period].concat(r.cells));
    });

    return m;
}

/* ---------- EXCEL (SheetJS) ---------- */
document.getElementById('btnExportXlsx').addEventListener('click', function() {
    var matrix = buildMatrix();
    var ws = XLSX.utils.aoa_to_sheet(matrix);

    /* Column widths */
    var cols = [{ wch: 28 }];
    TT_EXPORT.days.forEach(function() { cols.push({ wch: 24 }); });
    ws['!cols'] = cols;

    /* Merged title + meta rows */
    var total_cols = TT_EXPORT.days.length + 1;
    ws['!merges'] = [
        { s: { r: 0, c: 0 }, e: { r: 0, c: total_cols - 1 } },
        { s: { r: 1, c: 0 }, e: { r: 1, c: total_cols - 1 } }
    ];

    /* Row heights */
    ws['!rows'] = [];
    ws['!rows'][0] = { hpt: 26 };
    ws['!rows'][1] = { hpt: 18 };
    ws['!rows'][3] = { hpt: 22 };

    var wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Timetable');
    XLSX.writeFile(wb, TT_EXPORT.filename + '.xlsx');
});

/* ---------- CSV ---------- */
document.getElementById('btnExportCsv').addEventListener('click', function() {
    var matrix = buildMatrix();
    var csv = matrix.map(function(row) {
        return row.map(function(cell) {
            var v = (cell === null || cell === undefined) ? '' : String(cell);
            if (v.indexOf(',') !== -1 || v.indexOf('"') !== -1 || v.indexOf('\n') !== -1) {
                v = '"' + v.replace(/"/g, '""') + '"';
            }
            return v;
        }).join(',');
    }).join('\n');

    var blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = TT_EXPORT.filename + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
});
</script>
<?php endif; ?>

</body>
</html>
<?php $conn->close(); ?>