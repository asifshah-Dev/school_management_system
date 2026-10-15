<?php
header('Content-Type: text/html; charset=utf-8');
require_once 'security.php';
require_once 'conn_inc.php';
require_once 'permissions.php';

require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);

/* ---------- Helpers ---------- */
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

function safe_image(?string $path): ?string {
    if (!$path || preg_match('#(^[a-z]+:|\.\.)#i', $path)) return null;
    if (!preg_match('/\.(jpe?g|png|gif|webp|svg)$/i', $path)) return null;
    return is_file($path) ? $path : null;
}

$T = [
    'title' => 'Student ID Cards',
    'heading' => 'Student ID Card Generator',
    'select_class' => 'Class',
    'select_session' => 'Session',
    'load_students' => 'Load Students',
    'print_cards' => 'Print Cards',
    'no_students' => 'No students found for this class.',
    'students_found' => 'student(s) found.',
];

/* ---------- Dropdowns ---------- */
$sessions = [];
if ($r = $conn->query("SELECT id, title FROM sessions ORDER BY id DESC")) {
    while ($row = $r->fetch_assoc()) $sessions[$row['id']] = $row['title'];
}

$class_options = [];
$r = $conn->query("
    SELECT COALESCE(cs.id, 0) AS class_section_id, c.id AS class_id, c.title AS class_title,
           s.title AS section_title,
           CASE WHEN cs.id IS NULL THEN c.title ELSE CONCAT(c.title, ' - ', s.title) END AS display_label
    FROM classes c
    LEFT JOIN class_sections cs ON cs.class_id = c.id AND cs.status = 0
    LEFT JOIN sections s ON s.id = cs.section_id
    WHERE c.status = 0
    ORDER BY c.title, s.title
");
if ($r) while ($row = $r->fetch_assoc()) $class_options[] = $row;

/* ---------- Branding ---------- */
$school = [
    'name'    => 'SCHOOL MANAGEMENT SYSTEM',
    'logo'    => '',
    'address' => '',
    'phone'   => '',
];
$chk = $conn->query("SHOW TABLES LIKE 'settings'");
if ($chk && $chk->num_rows > 0) {
    $q = $conn->query("SELECT setting_key, setting_value FROM settings
                       WHERE setting_key IN ('school_logo','school_name','school_address','school_phone')");
    if ($q) while ($row = $q->fetch_assoc()) {
        if ($row['setting_value'] === '' || $row['setting_value'] === null) continue;
        $map = ['school_logo' => 'logo', 'school_name' => 'name', 'school_address' => 'address', 'school_phone' => 'phone'];
        if (isset($map[$row['setting_key']])) $school[$map[$row['setting_key']]] = $row['setting_value'];
    }
}
$school_logo = safe_image($school['logo']);

/* ---------- Themes ---------- */
$themes = [
    'navy'   => 'Navy Blue',
    'blue'   => 'Classic Blue',
    'green'  => 'Forest Green',
    'red'    => 'Deep Red',
    'purple' => 'Indigo',
    'teal'   => 'Teal',
    'gray'   => 'Charcoal',
];
$theme = $_GET['theme'] ?? 'navy';
if (!isset($themes[$theme])) $theme = 'navy';

$sides = $_GET['sides'] ?? 'front';
if (!in_array($sides, ['front', 'both', 'back'], true)) $sides = 'front';

/* ---------- Load students ---------- */
$students = [];
$loaded = false;
$class_id = 0; $class_section_id = 0; $session_id = 0;
$loaded_class_label = '';

if (($_GET['load'] ?? '') === '1') {
    $combo = trim($_GET['class_combo'] ?? '');
    if (strpos($combo, '|') !== false) {
        [$class_id, $class_section_id] = array_map('intval', explode('|', $combo, 2));
    } else {
        $class_id = intval($combo);
    }
    $session_id = intval($_GET['session_id'] ?? 0);

    $where = ['sc.class_id = ?'];
    $types = 'i';
    $params = [$class_id];
    if ($class_section_id > 0) { $where[] = 'sc.class_section_id = ?'; $types .= 'i'; $params[] = $class_section_id; }
    if ($session_id > 0)       { $where[] = 'sc.session_id = ?';       $types .= 'i'; $params[] = $session_id; }

    $sql = "
        SELECT sr.*, c.title AS class_title, s.title AS section_title, ses.title AS session_title
        FROM student_registration sr
        INNER JOIN student_class sc ON sr.id = sc.student_registration_id
        LEFT JOIN classes c ON sc.class_id = c.id
        LEFT JOIN class_sections cs ON sc.class_section_id = cs.id
        LEFT JOIN sections s ON cs.section_id = s.id
        LEFT JOIN sessions ses ON sc.session_id = ses.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY sr.name ASC";

    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) $students[] = $row;
        $stmt->close();
    } else {
        error_log('id card query failed: ' . $conn->error);
    }

    foreach ($class_options as $opt) {
        if ((int)$opt['class_id'] === $class_id && (int)$opt['class_section_id'] === $class_section_id) {
            $loaded_class_label = $opt['display_label'];
            break;
        }
    }
    $loaded = true;
}

$pages = array_chunk($students, 8); // 2 columns x 4 rows = 8 cards per A4 page
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title><?= h($T['title']) ?></title>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<style>
    :root { --fs: 14px; }
    body { font-family: 'Inter', sans-serif; background: #eef1f6; padding-bottom: 30px; }

    /* Page chrome */
    .page-title-bar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:18px; }
    .page-title-bar h1 { font-size:22px; font-weight:700; color:#1e293b; margin:0; display:flex; align-items:center; gap:10px; }
    .page-title-bar h1 i { color:#1e3a8a; }
    .panel { background:#fff; border-radius:10px; box-shadow:0 1px 4px rgba(0,0,0,.06); margin-bottom:20px; border:1px solid #e5e9ef; }
    .panel-body { padding:18px; }
    .fld label { display:block; font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.8px; margin-bottom:5px; }
    .fld select { width:100%; height:42px; padding:0 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; background:#fff; font-family:inherit; }
    .fld select:focus { outline:none; border-color:#1e3a8a; box-shadow:0 0 0 3px rgba(30,58,138,.12); }
    .btn-load, .btn-print { height:42px; padding:0 22px; border-radius:8px; font-weight:600; font-size:13px; color:#fff; border:0; cursor:pointer; transition:background .15s; }
    .btn-load { background:#1e3a8a; } .btn-load:hover { background:#172e6f; }
    .btn-print { background:#334155; } .btn-print:hover { background:#1e293b; }
    .seg { display:inline-flex; border:1px solid #cbd5e1; border-radius:8px; overflow:hidden; height:42px; }
    .seg label { margin:0; padding:0 14px; display:flex; align-items:center; font-size:12.5px; font-weight:600; color:#475569; cursor:pointer; background:#fff; text-transform:none; letter-spacing:0; }
    .seg input { display:none; }
    .seg label:has(input:checked) { background:#1e3a8a; color:#fff; }
    .info-bar { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; padding:10px 16px; margin-bottom:16px; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:8px; font-size:13px; color:#334155; }
    .empty-state { padding:50px 20px; text-align:center; color:#94a3b8; }
    .empty-state .big { font-size:44px; opacity:.4; margin-bottom:10px; }
    .sheet-label { font-size:11px; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:#94a3b8; margin:18px 0 8px; }

    /* ───── Themes — flat solid colours ───── */
    .sheets { --main:#1e3a8a; --dark:#0f2557; --soft:#eff3fb; --ink:#0f1f4d; }
    .sheets[data-theme="blue"]   { --main:#1d4ed8; --dark:#1e3a8a; --soft:#eff6ff; --ink:#1e3a8a; }
    .sheets[data-theme="green"]  { --main:#15803d; --dark:#0f5132; --soft:#f0fdf4; --ink:#0f5132; }
    .sheets[data-theme="red"]    { --main:#b91c1c; --dark:#7f1d1d; --soft:#fef2f2; --ink:#7f1d1d; }
    .sheets[data-theme="purple"] { --main:#5b21b6; --dark:#3f1d7f; --soft:#f5f3ff; --ink:#3f1d7f; }
    .sheets[data-theme="teal"]   { --main:#0f766e; --dark:#0d5e57; --soft:#f0fdfa; --ink:#0d5e57; }
    .sheets[data-theme="gray"]   { --main:#334155; --dark:#1e293b; --soft:#f1f5f9; --ink:#0f172a; }

    /* ───── Sheet ───── */
    .sheet { display:flex; flex-wrap:wrap; gap:1.4em; font-size:var(--fs); }

    /* ═══════════════════════════════════════════════════════
       ID CARD DESIGN
       ═══════════════════════════════════════════════════════ */
    .id-card {
        position:relative;
        width:20.4em;
        height:32.35em;
        background:#ffffff;
        border-radius:0.8em;
        overflow:hidden;
        display:flex;
        box-shadow:0 0.35em 1.1em rgba(15,31,77,.12);
        border:1px solid #d9dde6;
        font-family:'Inter',sans-serif;
        color:#1a1a1a;
        break-inside:avoid;
        page-break-inside:avoid;
        -webkit-print-color-adjust:exact;
        print-color-adjust:exact;
    }

    /* Left vertical stripe */
    .id-card .stripe {
        width:0.9em;
        background:var(--main);
        flex-shrink:0;
        display:flex;
        flex-direction:column;
        align-items:center;
        justify-content:space-between;
        padding:0.6em 0;
        color:#ffffff;
        position:relative;
    }
    .id-card .stripe .stripe-text {
        writing-mode:vertical-rl;
        transform:rotate(180deg);
        font-size:0.6em;
        font-weight:800;
        letter-spacing:0.35em;
        text-transform:uppercase;
        opacity:0.9;
    }
    .id-card .stripe .stripe-dot {
        width:0.4em; height:0.4em;
        border-radius:50%;
        background:rgba(255,255,255,0.5);
    }

    /* Right main content */
    .id-card .content {
        flex:1;
        display:flex;
        flex-direction:column;
        min-width:0;
        position:relative;
    }

    /* Header */
    .id-card .head {
        display:flex;
        align-items:center;
        gap:0.6em;
        padding:0.85em 1em 0.7em;
        border-bottom:2px solid var(--main);
        background:#ffffff;
    }
    .id-card .head .logo {
        width:2.6em; height:2.6em;
        border-radius:50%;
        overflow:hidden;
        flex-shrink:0;
        display:flex;
        align-items:center;
        justify-content:center;
        color:var(--main);
        border:0.12em solid var(--main);
        background:#fff;
    }
    .id-card .head .logo img { width:100%; height:100%; object-fit:cover; }
    .id-card .head .logo i { font-size:1.1em; }
    .id-card .head .school-name {
        flex:1;
        min-width:0;
        font-family:'Poppins',sans-serif;
        font-weight:800;
        font-size:0.82em;
        line-height:1.15;
        letter-spacing:0.05em;
        text-transform:uppercase;
        color:var(--dark);
        display:-webkit-box;
        -webkit-line-clamp:2;
        -webkit-box-orient:vertical;
        overflow:hidden;
    }

    /* Photo */
    .id-card .photo-block {
        display:flex;
        justify-content:center;
        padding:1em 0 0.4em;
    }
    .id-card .photo {
        width:7.8em; height:7.8em;
        border-radius:0.6em;
        overflow:hidden;
        flex-shrink:0;
        background:#f3f4f7;
        border:2px solid var(--main);
        display:flex;
        align-items:center;
        justify-content:center;
        color:#c3c8d3;
    }
    .id-card .photo img { width:100%; height:100%; object-fit:cover; }
    .id-card .photo i { font-size:3em; }

    /* Name + class */
    .id-card .name-block {
        text-align:center;
        padding:0 1em 0.7em;
        border-bottom:1px solid #eef1f6;
    }
    .id-card .name {
        font-family:'Poppins',sans-serif;
        font-weight:700;
        font-size:1.15em;
        line-height:1.15;
        color:var(--ink);
        text-transform:uppercase;
        letter-spacing:0.02em;
        display:-webkit-box;
        -webkit-line-clamp:2;
        -webkit-box-orient:vertical;
        overflow:hidden;
    }
    .id-card .class-line {
        margin-top:0.35em;
        font-size:0.76em;
        font-weight:700;
        color:var(--main);
        text-transform:uppercase;
        letter-spacing:0.12em;
    }

    /* Info block */
    .id-card .info-block {
        padding:0.75em 1em 0.3em;
        display:flex;
        flex-direction:column;
        gap:0.35em;
        flex:1;
        min-height:0;
    }
    .id-card .info-row {
        display:flex;
        align-items:baseline;
        gap:0.5em;
        font-size:0.76em;
        padding-bottom:0.32em;
        border-bottom:1px solid #f1f4f8;
    }
    .id-card .info-row:last-child { border-bottom:0; }
    .id-card .info-row .k {
        flex:0 0 5.4em;
        font-size:0.82em;
        font-weight:700;
        color:#8892a6;
        text-transform:uppercase;
        letter-spacing:0.05em;
    }
    .id-card .info-row .v {
        flex:1;
        min-width:0;
        font-weight:700;
        color:#111;
        white-space:nowrap;
        overflow:hidden;
        text-overflow:ellipsis;
    }
    .id-card .info-row .v.mono {
        font-family:'SF Mono','Menlo','Consolas',monospace;
        letter-spacing:0.03em;
        color:var(--main);
    }

    /* Barcode */
    .id-card .barcode-block {
        padding:0 1em 0.4em;
        margin-top:auto;
    }
    .id-card .barcode-box {
        padding:0.35em 0.4em 0.15em;
        border-radius:0.35em;
        background:#fafbfc;
        border:1px solid #eef1f6;
        text-align:center;
    }
    .id-card .barcode-box svg {
        display:block;
        width:100%;
        height:2.2em;
    }

    /* Footer */
    .id-card .foot {
        display:flex;
        align-items:center;
        justify-content:space-between;
        padding:0.5em 1em;
        background:var(--main);
        color:#ffffff;
        font-size:0.68em;
        font-weight:700;
        letter-spacing:0.15em;
        text-transform:uppercase;
    }
    .id-card .foot .foot-id { opacity:0.9; }
    .id-card .foot .foot-label { font-weight:800; }

    /* Back side */
    .id-card.back .content { padding:0; }
    .id-card.back .head {
        border-bottom:2px solid var(--main);
        justify-content:center;
    }
    .id-card.back .head .school-name {
        text-align:center;
        font-size:0.78em;
    }
    .id-card.back .back-body {
        flex:1;
        display:flex;
        flex-direction:column;
        padding:1.1em 1.2em 0.5em;
    }
    .id-card.back h4 {
        margin:0 0 0.5em;
        font-family:'Poppins',sans-serif;
        font-size:0.82em;
        font-weight:800;
        letter-spacing:0.1em;
        text-transform:uppercase;
        color:var(--main);
        text-align:center;
        padding-bottom:0.4em;
        border-bottom:2px solid var(--main);
    }
    .id-card.back ul {
        margin:0;
        padding:0;
        list-style:none;
        font-size:0.72em;
        line-height:1.5;
        color:#333;
    }
    .id-card.back li {
        position:relative;
        padding-left:1.05em;
        margin-bottom:0.5em;
    }
    .id-card.back li::before {
        content:"";
        position:absolute;
        left:0;
        top:0.6em;
        width:0.35em;
        height:0.35em;
        border-radius:50%;
        background:var(--main);
    }
    .id-card.back .return-box {
        margin-top:0.7em;
        padding:0.65em 0.75em;
        border:1px dashed var(--main);
        border-radius:0.5em;
        background:var(--soft);
        text-align:center;
        font-size:0.72em;
        line-height:1.5;
    }
    .id-card.back .return-box b {
        display:block;
        font-size:0.9em;
        letter-spacing:0.1em;
        text-transform:uppercase;
        color:var(--main);
        margin-bottom:0.25em;
    }
    .id-card.back .sign-block {
        margin-top:auto;
        text-align:center;
        padding-top:1em;
    }
    .id-card.back .sign-block .ln {
        width:60%;
        margin:0 auto;
        border-top:1px solid #555;
    }
    .id-card.back .sign-block .title {
        font-size:0.62em;
        font-weight:700;
        letter-spacing:0.12em;
        text-transform:uppercase;
        color:#444;
        margin-top:0.35em;
    }
    .id-card.back .issue-line {
        text-align:center;
        font-size:0.62em;
        color:#7b8294;
        margin:0.65em 0 0.4em;
        letter-spacing:0.05em;
    }

    /* ═══════════════════════════════════════════════════════
       PRINT — hide EVERYTHING except the cards
       ═══════════════════════════════════════════════════════ */
    @media print {
        :root { --fs: 10px; }

        @page { size: A4 portrait; margin: 8mm; }

        html, body {
            background:#ffffff !important;
            margin:0 !important;
            padding:0 !important;
            width:100% !important;
            height:auto !important;
            overflow:visible !important;
        }

        /* Hide everything by default */
        body * {
            visibility:hidden !important;
        }

        /* Reveal only the card sheets */
        .sheets,
        .sheets * {
            visibility:visible !important;
        }

        /* Position the sheets at the top of the page */
        .sheets {
            position:absolute !important;
            left:0 !important;
            top:0 !important;
            width:100% !important;
            margin:0 !important;
            padding:0 !important;
        }

        /* Hard-hide structural chrome */
        nav,
        .navbar,
        .navbar *,
        .no-print,
        .no-print *,
        .page-title-bar,
        .panel,
        .info-bar,
        .sheet-label {
            display:none !important;
            visibility:hidden !important;
        }

        /* Reset container / column padding */
        .container,
        .container-fluid,
        .container-fluid > *,
        .row,
        [class*="col-"] {
            padding:0 !important;
            margin:0 !important;
            background:transparent !important;
        }

        /* Sheet pagination */
        .sheet {
            display:grid !important;
            grid-template-columns:repeat(2, 74mm) !important;
            gap:5mm !important;
            justify-content:center !important;
            align-content:start !important;
            page-break-after:always !important;
            break-after:page !important;
        }
        .sheet:last-child {
            page-break-after:auto !important;
            break-after:auto !important;
        }
        .sheet.is-back { direction:rtl !important; }
        .sheet.is-back .id-card { direction:ltr !important; }

        /* Card in print */
        .id-card {
            box-shadow:none !important;
            border:0.2mm solid #b5b9c4 !important;
            border-radius:3mm !important;
            width:74mm !important;
            height:118mm !important;
            -webkit-print-color-adjust:exact !important;
            print-color-adjust:exact !important;
        }

        .id-card .stripe,
        .id-card .head,
        .id-card .photo,
        .id-card .foot,
        .id-card .back-body,
        .id-card.back .head {
            -webkit-print-color-adjust:exact !important;
            print-color-adjust:exact !important;
        }
    }
</style>
</head>
<body>
<?php require_once 'navbar.php'; ?>

<div class="container-fluid" style="padding:20px; max-width:1400px; margin:0 auto;">

    <div class="page-title-bar no-print">
        <h1><i class="fas fa-id-card"></i> <?= h($T['heading']) ?></h1>
       
    </div>

    <div class="panel no-print">
        <div class="panel-body">
            <form method="get" action="">
                <input type="hidden" name="load" value="1">
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <div class="fld">
                            <label><?= h($T['select_session']) ?></label>
                            <select name="session_id">
                                <option value="0">-- All Sessions --</option>
                                <?php foreach ($sessions as $sid => $sname): ?>
                                    <option value="<?= (int)$sid ?>" <?= $session_id == $sid ? 'selected' : '' ?>><?= h($sname) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="fld">
                            <label><?= h($T['select_class']) ?></label>
                            <select name="class_combo" required>
                                <option value="">-- Select Class --</option>
                                <?php foreach ($class_options as $opt):
                                    $val = $opt['class_id'] . '|' . $opt['class_section_id'];
                                    $sel = ((int)$class_id === (int)$opt['class_id'] && (int)$class_section_id === (int)$opt['class_section_id']);
                                ?>
                                    <option value="<?= h($val) ?>" <?= $sel ? 'selected' : '' ?>><?= h($opt['display_label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2 mb-3">
                        <div class="fld">
                            <label>Theme</label>
                            <select name="theme" id="themeSel">
                                <?php foreach ($themes as $k => $label): ?>
                                    <option value="<?= h($k) ?>" <?= $theme === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="fld">
                            <label>Print Sides</label>
                            <div class="seg">
                                <label><input type="radio" name="sides" value="front" <?= $sides === 'front' ? 'checked' : '' ?>><span>Front</span></label>
                                <label><input type="radio" name="sides" value="both"  <?= $sides === 'both'  ? 'checked' : '' ?>><span>Both</span></label>
                                <label><input type="radio" name="sides" value="back"  <?= $sides === 'back'  ? 'checked' : '' ?>><span>Back</span></label>
                            </div>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn-load"><i class="fas fa-users"></i> <?= h($T['load_students']) ?></button>
            </form>
        </div>
    </div>

    <?php if ($loaded): ?>
        <div class="info-bar no-print">
            <div>
                <i class="fas fa-info-circle"></i>
                <strong><?= count($students) ?></strong> <?= h($T['students_found']) ?>
                <?php if ($loaded_class_label): ?> &middot; Class: <strong><?= h($loaded_class_label) ?></strong><?php endif; ?>
                <?php if ($students): ?> &middot; <?= count($pages) ?> page<?= count($pages) > 1 ? 's' : '' ?> (8 cards per page)<?php endif; ?>
            </div>
            <?php if ($students): ?>
                <button type="button" class="btn-print" onclick="window.print();">
                    <i class="fas fa-print"></i> <?= h($T['print_cards']) ?>
                </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($loaded && $students): ?>
    <div class="sheets" id="sheets" data-theme="<?= h($theme) ?>">
        <?php foreach ($pages as $pi => $chunk): ?>

            <?php if ($sides !== 'back'): ?>
            <div class="sheet-label no-print">Page <?= $pi + 1 ?> &middot; Front</div>
            <div class="sheet">
                <?php foreach ($chunk as $s):
                    $photo = safe_image($s['image_path'] ?? null);
                    $class_label = trim(($s['class_title'] ?? '') . (!empty($s['section_title']) ? ' - ' . $s['section_title'] : ''));
                    $reg_no = (string)($s['reg_no'] ?? '');
                    $contact = $s['guardian_mobile'] ?? '';
                    if ($contact === '' || $contact === null) $contact = $s['mobile'] ?? '';
                ?>
                <div class="id-card front">
                    <!-- Left vertical stripe -->
                    <div class="stripe">
                        <div class="stripe-dot"></div>
                        <div class="stripe-text">Student ID</div>
                        <div class="stripe-dot"></div>
                    </div>

                    <!-- Right content -->
                    <div class="content">
                        <!-- Header with logo + school name -->
                        <div class="head">
                            <div class="logo">
                                <?php if ($school_logo): ?>
                                    <img src="<?= h($school_logo) ?>" alt="">
                                <?php else: ?>
                                    <i class="fas fa-graduation-cap"></i>
                                <?php endif; ?>
                            </div>
                            <div class="school-name"><?= h($school['name']) ?></div>
                        </div>

                        <!-- Photo -->
                        <div class="photo-block">
                            <div class="photo">
                                <?php if ($photo): ?>
                                    <img src="<?= h($photo) ?>" alt="">
                                <?php else: ?>
                                    <i class="fas fa-user"></i>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Name + class -->
                        <div class="name-block">
                            <div class="name" dir="auto"><?= h($s['name'] ?? '') ?></div>
                            <div class="class-line"><?= h($class_label !== '' ? $class_label : '—') ?></div>
                        </div>

                        <!-- Info rows -->
                        <div class="info-block">
                            <div class="info-row">
                                <span class="k">Student No.</span>
                                <span class="v mono"><?= h($reg_no) ?></span>
                            </div>
                            <div class="info-row">
                                <span class="k">Father</span>
                                <span class="v" dir="auto"><?= h($s['father_name'] ?? '') ?></span>
                            </div>
                            <div class="info-row">
                                <span class="k">Session</span>
                                <span class="v"><?= h($s['session_title'] ?? '') ?></span>
                            </div>
                            <?php if ($contact !== ''): ?>
                            <div class="info-row">
                                <span class="k">Contact</span>
                                <span class="v mono"><?= h($contact) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Barcode -->
                        <?php if ($reg_no !== ''): ?>
                        <div class="barcode-block">
                            <div class="barcode-box">
                                <svg class="barcode" data-value="<?= h($reg_no) ?>"></svg>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Footer -->
                        <div class="foot">
                            <span class="foot-id">#<?= h($reg_no) ?></span>
                            <span class="foot-label">Student ID</span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($sides !== 'front'): ?>
            <div class="sheet-label no-print">Page <?= $pi + 1 ?> &middot; Back</div>
            <div class="sheet is-back">
                <?php foreach ($chunk as $s):
                    $gm = $s['guardian_mobile'] ?? '';
                ?>
                <div class="id-card back">
                    <!-- Left stripe -->
                    <div class="stripe">
                        <div class="stripe-dot"></div>
                        <div class="stripe-text">Back</div>
                        <div class="stripe-dot"></div>
                    </div>

                    <div class="content">
                        <!-- Header -->
                        <div class="head">
                            <div class="school-name"><?= h($school['name']) ?></div>
                        </div>

                        <div class="back-body">
                            <h4>Terms &amp; Conditions</h4>
                            <ul>
                                <li>This card is the property of the school and must be carried at all times.</li>
                                <li>It is non-transferable and must be shown on request.</li>
                                <li>Report loss immediately to the school office. A fee applies for replacement.</li>
                            </ul>

                            <div class="return-box">
                                <b>If found, please return to</b>
                                <?= h($school['name']) ?><br>
                                <?php if ($school['address'] !== ''): ?><?= h($school['address']) ?><br><?php endif; ?>
                                <?php if ($school['phone'] !== ''): ?>Tel: <?= h($school['phone']) ?><?php endif; ?>
                            </div>

                            <?php if (!empty($gm)): ?>
                                <div class="issue-line" style="margin-top:.9em;">Guardian Contact: <?= h($gm) ?></div>
                            <?php endif; ?>

                            <div class="sign-block">
                                <div class="ln"></div>
                                <div class="title">Principal &middot; Authorized Signature</div>
                            </div>

                            <div class="issue-line">Issued: <?= h(date('d-M-Y')) ?></div>
                        </div>

                        <div class="foot">
                            <span class="foot-id">School Property</span>
                            <span class="foot-label">Back</span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

        <?php endforeach; ?>
    </div>
    <?php elseif ($loaded): ?>
        <div class="empty-state">
            <div class="big">📋</div>
            <h4><?= h($T['no_students']) ?></h4>
        </div>
    <?php endif; ?>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('svg.barcode').forEach(function (svg) {
        var value = svg.getAttribute('data-value');
        if (!value) return;
        try {
            JsBarcode(svg, value, {
                format: 'CODE128',
                width: 2,
                height: 50,
                displayValue: false,
                margin: 0,
                lineColor: '#111111',
                background: 'transparent'
            });
        } catch (e) {
            svg.parentNode.style.display = 'none';
            console.error('Barcode failed for', value, e);
        }
    });

    var sel = document.getElementById('themeSel'), sheets = document.getElementById('sheets');
    if (sel && sheets) sel.addEventListener('change', function () {
        sheets.setAttribute('data-theme', sel.value);
    });
});
</script>
</body>
</html>
<?php $conn->close(); ?>