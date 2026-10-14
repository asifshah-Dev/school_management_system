<?php
header('Content-Type: text/html; charset=utf-8');
require_once('security.php');
require_once('conn_inc.php');
require_once('permissions.php');

require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);

$_SESSION['lang'] = 'en';
$lang = $_SESSION['lang'];

$translations = [
    'en' => [
        'title' => 'Student ID Cards',
        'heading' => 'Student ID Card Generator',
        'select_class' => 'Select Class',
        'select_session' => 'Select Session',
        'load_students' => 'Load Students',
        'print_cards' => 'Print ID Cards',
        'no_students' => 'No students found for this class.',
        'students_found' => 'student(s) found.',
    ]
];

// ---------- Dropdowns ----------
$sessions = [];
$session_result = $conn->query("SELECT id, title FROM sessions ORDER BY id DESC");
if ($session_result) {
    while ($row = $session_result->fetch_assoc()) $sessions[$row['id']] = $row['title'];
}

$class_result = $conn->query("
    SELECT COALESCE(cs.id, 0) AS class_section_id, c.id AS class_id, c.title AS class_title, s.title AS section_title,
    CASE WHEN cs.id IS NULL THEN c.title ELSE CONCAT(c.title, ' - ', s.title) END AS display_label
    FROM classes c
    LEFT JOIN class_sections cs ON cs.class_id = c.id AND cs.status = 0
    LEFT JOIN sections s ON s.id = cs.section_id
    WHERE c.status = 0 ORDER BY c.title, s.title
");
$class_options = [];
if ($class_result) {
    while ($row = $class_result->fetch_assoc()) $class_options[] = $row;
}

// ---------- Fixed branding ----------
$school_name = 'SCHOOL MANAGEMENT SYSTEM';
$school_logo = '';

$chk = $conn->query("SHOW TABLES LIKE 'settings'");
if ($chk && $chk->num_rows > 0) {
    $s = $conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('school_logo')");
    if ($s) {
        while ($r = $s->fetch_assoc()) {
            if ($r['setting_key'] === 'school_logo' && !empty($r['setting_value'])) {
                $school_logo = $r['setting_value'];
            }
        }
    }
}

// ---------- Load Students ----------
$students = [];
$loaded = false;
$class_id = 0;
$class_section_id = 0;
$session_id = 0;
$loaded_class_label = '';

if (isset($_GET['load']) && $_GET['load'] === '1') {
    $combo = isset($_GET['class_combo']) ? trim($_GET['class_combo']) : '';
    if (strpos($combo, '|') !== false) {
        $parts = array_map('intval', explode('|', $combo, 2));
        $class_id = $parts[0];
        $class_section_id = $parts[1];
    } else {
        $class_id = intval($combo);
    }
    $session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;

    $where = [];
    $where[] = "sc.class_id = " . intval($class_id);
    if ($class_section_id > 0) $where[] = "sc.class_section_id = " . intval($class_section_id);
    if ($session_id > 0) $where[] = "sc.session_id = " . intval($session_id);

    $where_sql = 'WHERE ' . implode(' AND ', $where);

    $sql = "
        SELECT
            sr.*,
            c.title  AS class_title,
            s.title  AS section_title,
            ses.title AS session_title
        FROM student_registration sr
        INNER JOIN student_class sc ON sr.id = sc.student_registration_id
        LEFT JOIN classes c ON sc.class_id = c.id
        LEFT JOIN class_sections cs ON sc.class_section_id = cs.id
        LEFT JOIN sections s ON cs.section_id = s.id
        LEFT JOIN sessions ses ON sc.session_id = ses.id
        $where_sql
        ORDER BY sr.name ASC
    ";

    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) $students[] = $row;
    }

    foreach ($class_options as $opt) {
        if ((int)$opt['class_id'] === $class_id && (int)$opt['class_section_id'] === $class_section_id) {
            $loaded_class_label = $opt['display_label'];
            break;
        }
    }

    $loaded = true;
}

function fmt_date($d) {
    if (empty($d) || $d === '0000-00-00') return '';
    try {
        $dt = new DateTime($d);
        return $dt->format('d-M-Y');
    } catch (Exception $e) {
        return $d;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo htmlspecialchars($translations[$lang]['title']); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:wght@700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background: #eef1f6; padding-bottom: 30px; }

        /* ───── THEME COLORS ───── */
        :root {
            --primary: #1e3a8a;        /* Navy blue */
            --primary-dark: #172e6f;
            --primary-light: #2a4aa8;
            --accent: #d4af37;         /* Gold */
            --accent-soft: #f4d35e;
        }

        .page-title-bar {
            display: flex; align-items: center; justify-content: space-between;
            margin: 0 0 18px 0; flex-wrap: wrap; gap: 10px;
        }
        .page-title-bar h1 {
            font-size: 22px; font-weight: 700; color: #1e293b;
            margin: 0; display: flex; align-items: center; gap: 10px;
        }
        .page-title-bar h1 i { color: var(--primary); }

        .panel { background: #fff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 24px; }
        .panel-body { padding: 18px; }

        .form-row .field label {
            display: block; font-size: 11px; font-weight: 700;
            color: #64748b; text-transform: uppercase;
            letter-spacing: 0.8px; margin-bottom: 5px;
        }
        .form-row select {
            width: 100%; height: 42px; padding: 0 12px;
            border: 1px solid #cbd5e1; border-radius: 8px;
            font-size: 13px; background: #fff; font-family: inherit;
        }
        .form-row select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(30,58,138,0.12); }

        .btn-load {
            height: 42px; padding: 0 24px;
            background: var(--primary); color: #fff;
            border: 1px solid var(--primary); border-radius: 8px;
            font-weight: 600; font-size: 13px;
        }
        .btn-load:hover { background: var(--primary-dark); border-color: var(--primary-dark); color: #fff; }

        .btn-print {
            height: 42px; padding: 0 24px;
            background: #b8860b; color: #fff;
            border: 1px solid #b8860b; border-radius: 8px;
            font-weight: 600; font-size: 13px;
        }
        .btn-print:hover { background: #9a6f08; border-color: #9a6f08; color: #fff; }

        .info-bar {
            padding: 10px 16px; margin-bottom: 16px;
            background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 8px;
            font-size: 13px; color: #1e3a8a;
        }

        /* ───────── CARDS GRID ───────── */
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 18px;
        }

        /* ───────── ID CARD ───────── */
        .id-card {
            width: 340px;
            background: #ffffff;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 6px 20px rgba(0,0,0,0.14);
            position: relative;
            page-break-inside: avoid;
            break-inside: avoid;
            font-family: 'Inter', sans-serif;
            border: 1px solid #d4d4d4;
        }

        /* Navy header with title */
        .id-card .card-top {
            background: var(--primary);
            background: linear-gradient(180deg, var(--primary-light) 0%, var(--primary) 100%);
            padding: 14px 14px 10px 14px;
            text-align: center;
            position: relative;
        }
        .id-card .school-title {
            font-family: 'Playfair Display', serif;
            color: #ffffff;
            font-weight: 800;
            font-size: 15px;
            letter-spacing: 1.5px;
            line-height: 1.15;
            text-transform: uppercase;
            margin: 0;
            text-shadow: 0 1px 2px rgba(0,0,0,0.3);
        }

        /* Photo + Logo row */
        .id-card .photo-logo-row {
            padding: 14px 16px 4px 16px;
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }
        .id-card .photo-frame {
            width: 110px;
            height: 130px;
            border: 3px solid var(--accent);
            border-radius: 4px;
            background: #f5f5f5;
            overflow: hidden;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #bbb;
            font-size: 48px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }
        .id-card .photo-frame img {
            width: 100%; height: 100%; object-fit: cover;
        }

        .id-card .logo-info-col {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding-top: 4px;
        }
        .id-card .school-logo-circle {
            width: 66px;
            height: 66px;
            border-radius: 50%;
            background: #fdfdfd;
            border: 3px solid var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            color: var(--primary);
            overflow: hidden;
            box-shadow: 0 2px 6px rgba(0,0,0,0.12);
        }
        .id-card .school-logo-circle img {
            width: 100%; height: 100%; object-fit: cover;
        }

        .id-card .reg-block {
            text-align: center;
            width: 100%;
        }
        .id-card .reg-label {
            font-size: 8.5px;
            font-weight: 800;
            color: #555;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        .id-card .reg-value {
            font-size: 11px;
            font-weight: 700;
            color: #1a1a1a;
            font-family: 'SF Mono', 'Monaco', monospace;
            letter-spacing: 0.3px;
            margin-top: 1px;
        }

        /* Name box */
        .id-card .name-box {
            margin: 12px 16px 6px 16px;
            padding: 6px 10px;
            background: #ffffff;
            border: 1.5px solid #333;
            border-radius: 2px;
            text-align: center;
        }
        .id-card .name-box .name-text {
            font-size: 12.5px;
            font-weight: 800;
            color: #111;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            line-height: 1.15;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Class box */
        .id-card .class-box {
            margin: 0 16px 10px 16px;
            padding: 5px 10px;
            background: #ffffff;
            border: 1.5px solid #333;
            border-radius: 2px;
            text-align: center;
        }
        .id-card .class-box .class-text {
            font-size: 11.5px;
            font-weight: 700;
            color: #111;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Signature row */
        .id-card .signature-row {
            padding: 0 20px 4px 20px;
            text-align: center;
        }
        .id-card .signature-line {
            display: inline-block;
            font-family: 'Playfair Display', serif;
            font-style: italic;
            font-weight: 700;
            color: #111;
            font-size: 15px;
            padding: 2px 4px;
            border-bottom: 1px solid #555;
            min-width: 130px;
            line-height: 1.1;
        }
        .id-card .signature-title {
            font-size: 9px;
            font-weight: 700;
            color: #333;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-top: 3px;
        }
        .id-card .signature-sub {
            font-size: 8px;
            color: #666;
            letter-spacing: 0.5px;
            margin-top: 1px;
        }

        /* BARCODE strip */
        .id-card .barcode-strip {
            padding: 6px 16px 4px 16px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
        }
        .id-card .barcode-strip svg {
            display: block;
            max-width: 100%;
            height: auto;
        }
        .id-card .barcode-label {
            font-size: 8.5px;
            font-weight: 700;
            color: #333;
            letter-spacing: 1.2px;
            font-family: 'SF Mono', 'Monaco', monospace;
        }

        /* Footer strip */
        .id-card .card-footer-strip {
            background: var(--primary);
            background: linear-gradient(180deg, var(--primary) 0%, var(--primary-light) 100%);
            padding: 7px 14px;
            text-align: center;
        }
        .id-card .card-footer-strip .footer-text {
            font-family: 'Playfair Display', serif;
            font-weight: 800;
            font-size: 13px;
            color: var(--accent-soft);
            letter-spacing: 3px;
            text-transform: uppercase;
        }

        .empty-state { padding: 50px 20px; text-align: center; color: #94a3b8; }
        .empty-state .big { font-size: 44px; opacity: 0.4; margin-bottom: 10px; }

        /* ───────── PRINT ───────── */
        @media print {
            @page { size: A4; margin: 8mm; }
            body { background: #fff !important; padding: 0 !important; margin: 0 !important; }
            .no-print { display: none !important; }
            .navbar, nav { display: none !important; }
            .page-title-bar,
            .panel:first-of-type,
            .info-bar { display: none !important; }

            .cards-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 6mm;
                justify-items: center;
            }
            .id-card {
                box-shadow: none;
                border: 1px solid #999;
                width: 88mm;
                page-break-inside: avoid;
                break-inside: avoid;
            }
            .id-card .card-top,
            .id-card .card-footer-strip {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
<?php require_once('navbar.php'); ?>

<div class="container-fluid" style="padding: 20px; max-width: 1400px; margin: 0 auto;">

    <div class="page-title-bar no-print">
        <h1>
            <i class="fas fa-id-card"></i>
            <?php echo htmlspecialchars($translations[$lang]['heading']); ?>
        </h1>
        <a href="student_list.php" class="btn btn-outline-secondary btn-sm" style="padding: 8px 18px;">
            <i class="fas fa-list"></i> View Students
        </a>
    </div>

    <div class="panel no-print">
        <div class="panel-body">
            <form method="get" action="">
                <input type="hidden" name="load" value="1">
                <div class="row form-row">
                    <div class="col-md-4">
                        <div class="field">
                            <label><?php echo htmlspecialchars($translations[$lang]['select_session']); ?></label>
                            <select name="session_id" id="session_id">
                                <option value="0">-- All Sessions --</option>
                                <?php foreach ($sessions as $id => $name): ?>
                                    <option value="<?php echo $id; ?>" <?php echo ($session_id == $id) ? 'selected' : ''; ?>><?php echo htmlspecialchars($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="field">
                            <label><?php echo htmlspecialchars($translations[$lang]['select_class']); ?></label>
                            <select name="class_combo" id="class_combo" required>
                                <option value="">-- Select Class --</option>
                                <?php foreach ($class_options as $opt):
                                    $val = $opt['class_id'] . '|' . $opt['class_section_id'];
                                    $sel = ((int)$class_id === (int)$opt['class_id'] && (int)$class_section_id === (int)$opt['class_section_id']);
                                ?>
                                    <option value="<?php echo $val; ?>" <?php echo $sel ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($opt['display_label']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3" style="display:flex; align-items:flex-end; gap:8px;">
                        <button type="submit" class="btn-load">
                            <i class="fas fa-users"></i> <?php echo htmlspecialchars($translations[$lang]['load_students']); ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if ($loaded): ?>
        <div class="info-bar no-print">
            <i class="fas fa-info-circle"></i>
            <strong><?php echo count($students); ?></strong> <?php echo htmlspecialchars($translations[$lang]['students_found']); ?>
            <?php if ($loaded_class_label): ?> &middot; Class: <strong><?php echo htmlspecialchars($loaded_class_label); ?></strong><?php endif; ?>

            <?php if (!empty($students)): ?>
                <button type="button" class="btn-print float-right" onclick="window.print();" style="margin-top:-8px;">
                    <i class="fas fa-print"></i> <?php echo htmlspecialchars($translations[$lang]['print_cards']); ?>
                </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($loaded && !empty($students)): ?>
        <div class="cards-grid" id="cardsGrid">
            <?php foreach ($students as $s):
                $full_name = strtoupper($s['name']);
                $reg_no = $s['reg_no'];
                $class_label = trim(($s['class_title'] ?? '') . (($s['section_title'] ?? '') ? ' - ' . $s['section_title'] : ''));
                $class_label = strtoupper($class_label);
                $photo = $s['image_path'] ?? '';

                // Barcode value: reg_no only (Code128 handles it cleanly)
                $barcode_value = $reg_no;
            ?>
            <div class="id-card" data-barcode="<?php echo htmlspecialchars($barcode_value, ENT_QUOTES, 'UTF-8'); ?>">

                <!-- TOP HEADER -->
                <div class="card-top">
                    <h2 class="school-title"><?php echo htmlspecialchars($school_name); ?></h2>
                </div>

                <!-- PHOTO + LOGO ROW -->
                <div class="photo-logo-row">
                    <div class="photo-frame">
                        <?php if (!empty($photo) && file_exists($photo)): ?>
                            <img src="<?php echo htmlspecialchars($photo); ?>" alt="<?php echo htmlspecialchars($full_name); ?>">
                        <?php else: ?>
                            <i class="fas fa-user"></i>
                        <?php endif; ?>
                    </div>
                    <div class="logo-info-col">
                        <div class="school-logo-circle">
                            <?php if (!empty($school_logo) && file_exists($school_logo)): ?>
                                <img src="<?php echo htmlspecialchars($school_logo); ?>" alt="Logo">
                            <?php else: ?>
                                <i class="fas fa-graduation-cap"></i>
                            <?php endif; ?>
                        </div>
                        <div class="reg-block">
                            <div class="reg-label">Student No.</div>
                            <div class="reg-value"><?php echo htmlspecialchars($reg_no); ?></div>
                        </div>
                    </div>
                </div>

                <!-- NAME BOX -->
                <div class="name-box">
                    <div class="name-text"><?php echo htmlspecialchars($full_name); ?></div>
                </div>

                <!-- CLASS BOX -->
                <div class="class-box">
                    <div class="class-text"><?php echo htmlspecialchars($class_label ?: '—'); ?></div>
                </div>

                <!-- SIGNATURE -->
                <div class="signature-row">
                    <div class="signature-line">Principal</div>
                    <div class="signature-title">School Principal</div>
                    <div class="signature-sub">Authorized Signature</div>
                </div>

                <!-- BARCODE STRIP -->
                <div class="barcode-strip">
                    <svg class="barcode" data-value="<?php echo htmlspecialchars($barcode_value, ENT_QUOTES, 'UTF-8'); ?>"></svg>
                    <div class="barcode-label"><?php echo htmlspecialchars($barcode_value); ?></div>
                </div>

                <!-- FOOTER -->
                <div class="card-footer-strip">
                    <div class="footer-text">Student ID</div>
                </div>

            </div>
            <?php endforeach; ?>
        </div>
    <?php elseif ($loaded): ?>
        <div class="empty-state">
            <div class="big">📋</div>
            <h4><?php echo htmlspecialchars($translations[$lang]['no_students']); ?></h4>
        </div>
    <?php endif; ?>

</div>

<script>
$(document).ready(function() {
    // Generate barcodes with JsBarcode
    $('.id-card').each(function() {
        var $card = $(this);
        var $barcodeSvg = $card.find('svg.barcode');
        if (!$barcodeSvg.length) return;

        var value = $barcodeSvg.attr('data-value');
        if (!value) return;

        try {
            JsBarcode($barcodeSvg[0], value, {
                format: 'CODE128',
                width: 1.6,
                height: 44,
                displayValue: false,
                margin: 0,
                lineColor: '#111111',
                background: '#ffffff'
            });
        } catch (e) {
            console.error('Barcode generation failed for', value, e);
        }
    });
});
</script>
</body>
</html>
<?php $conn->close(); ?>