<?php
/* ---------- Bootstrap: session + DB first, then security ---------- */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'conn_inc.php';
require_once 'security.php';
require_once 'permissions.php';
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);

/* ---------- School settings (edit once, or load from DB) ---------- */
$school = [
    'name'    => 'Your School Name',
    'address' => '123 School Road, City, Country',
    'logo'    => 'logo2.png',
    'back'    => 'student_list.php',
];

/* ---------- Language (en / ur) ---------- */
$allowed = ['en', 'ur'];
$lang = $_GET['lang'] ?? ($_SESSION['lang'] ?? 'en');
$lang = in_array($lang, $allowed, true) ? $lang : 'en';
$_SESSION['lang'] = $lang;
$dir = $lang === 'ur' ? 'rtl' : 'ltr';

$T = [
    'en' => [
        'title' => 'Student Registration Form', 'form_title' => 'Student Registration Form',
        'print_btn' => 'Print', 'back_btn' => 'Back',
        'basic_info' => 'Basic Information', 'address_info' => 'Address Information',
        'guardian_info' => 'Guardian Information', 'academic_info' => 'Academic Information',
        'transport_info' => 'Transport Information', 'other_info' => 'Other Information',
        'office_use' => 'For Office Use Only', 'registration_no' => 'Registration No.',
        'registration_date' => 'Registration Date',
        'branch' => 'Branch', 'village_council' => 'Town', 'session' => 'Session', 'class' => 'Class',
        'name' => 'Student Name', 'father_name' => 'Father Name', 'mobile' => 'Mobile', 'cnic' => 'CNIC',
        'current_address' => 'Current Address', 'permanent_address' => 'Permanent Address',
        'guardian_name' => 'Guardian Name', 'guardian_mobile' => 'Guardian Mobile',
        'guardian_cnic' => 'Guardian CNIC', 'guardian_address' => 'Guardian Address',
        'previous_schools' => 'Previous Schools', 'other_details' => 'Other Details',
        'student_image' => 'Photo', 'using_transport' => 'Using Transport', 'transport_fee' => 'Transport Fee',
        'yes' => 'Yes', 'no' => 'No', 'signature' => 'Signature', 'date' => 'Date', 'place' => 'Place',
        'footer' => 'I hereby declare that the information provided is correct to the best of my knowledge.',
        'ready' => 'registration form(s) ready to print',
        'none_title' => 'No Students Selected',
        'none_text' => 'Go back to the student list and select at least one student.',
    ],
    'ur' => [
        'title' => 'داخلہ فارم', 'form_title' => 'طالب علم داخلہ فارم',
        'print_btn' => 'پرنٹ', 'back_btn' => 'واپس',
        'basic_info' => 'بنیادی معلومات', 'address_info' => 'پتے کی معلومات',
        'guardian_info' => 'سرپرست کی معلومات', 'academic_info' => 'تعلیمی معلومات',
        'transport_info' => 'ٹرانسپورٹ', 'other_info' => 'دیگر معلومات',
        'office_use' => 'صرف دفتری استعمال کے لیے', 'registration_no' => 'رجسٹریشن نمبر',
        'registration_date' => 'تاریخ داخلہ',
        'branch' => 'برانچ', 'village_council' => 'ویلج کونسل', 'session' => 'سیشن', 'class' => 'کلاس',
        'name' => 'نام طالب علم', 'father_name' => 'والد کا نام', 'mobile' => 'موبائل', 'cnic' => 'شناختی کارڈ',
        'current_address' => 'موجودہ پتہ', 'permanent_address' => 'مستقل پتہ',
        'guardian_name' => 'سرپرست کا نام', 'guardian_mobile' => 'سرپرست کا موبائل',
        'guardian_cnic' => 'سرپرست کا شناختی کارڈ', 'guardian_address' => 'سرپرست کا پتہ',
        'previous_schools' => 'سابقہ اسکول', 'other_details' => 'دیگر تفصیلات',
        'student_image' => 'تصویر', 'using_transport' => 'ٹرانسپورٹ استعمال', 'transport_fee' => 'ٹرانسپورٹ فیس',
        'yes' => 'ہاں', 'no' => 'نہیں', 'signature' => 'دستخط', 'date' => 'تاریخ', 'place' => 'مقام',
        'footer' => 'میں تصدیق کرتا ہوں کہ فراہم کردہ معلومات میرے علم کے مطابق درست ہیں۔',
        'ready' => 'فارم پرنٹ کے لیے تیار ہیں',
        'none_title' => 'کوئی طالب علم منتخب نہیں',
        'none_text' => 'براہ کرم فہرست میں واپس جا کر کم از کم ایک طالب علم منتخب کریں۔',
    ],
][$lang];

/* ---------- Helpers ---------- */
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function hn($v): string { return nl2br(h($v)); }

function field(string $label, $value, bool $multiline = false, bool $full = false): void {
    $v = $multiline ? hn($value) : h($value);
    echo '<div class="field' . ($full ? ' full' : '') . '">'
       . '<span class="label">' . h($label) . '</span>'
       . '<span class="value">' . $v . '</span></div>';
}

function safe_image(?string $path): ?string {
    // Only allow plain relative paths to image files; block traversal and URLs
    if (!$path || preg_match('#(^[a-z]+:|\.\.|^/)#i', $path)) return null;
    if (!preg_match('/\.(jpe?g|png|gif|webp)$/i', $path)) return null;
    return is_file($path) ? $path : null;
}

/* ---------- Parse IDs (GET or POST) ---------- */
$raw = $_POST['ids'] ?? $_GET['ids'] ?? '';
$raw = is_array($raw) ? implode(',', $raw) : (string)$raw;
$ids = array_values(array_unique(array_filter(
    array_map('intval', explode(',', $raw)),
    fn($n) => $n > 0
)));
$ids = array_slice($ids, 0, 500); // sane upper limit

/* ---------- Load students ---------- */
$students = [];
if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $sql = "
        SELECT sr.*,
               vc.title AS village_title, b.title AS branch_title,
               s.title AS session_title,  c.title AS class_title,
               co.title AS course_title
        FROM student_registration sr
        LEFT JOIN village_councils vc ON sr.village_council_id = vc.id
        LEFT JOIN branches b ON sr.branch_id = b.id
        LEFT JOIN (
            SELECT sc1.*
            FROM student_class sc1
            INNER JOIN (
                SELECT student_registration_id, MAX(id) AS max_id
                FROM student_class GROUP BY student_registration_id
            ) sc2 ON sc1.id = sc2.max_id
        ) sc ON sr.id = sc.student_registration_id
        LEFT JOIN sessions s ON sc.session_id = s.id
        LEFT JOIN classes c ON sc.class_id = c.id
        LEFT JOIN courses co ON c.course_id = co.id
        WHERE sr.id IN ($ph)
        ORDER BY sr.name ASC";

    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) $students[] = $row;
        $stmt->close();
    } else {
        error_log('student print query failed: ' . $conn->error);
    }
}
?>
<!DOCTYPE html>
<html lang="<?= h($lang) ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($T['title']) ?></title>
<style>
    :root { --ink:#111; --line:#000; --muted:#555; --bg:#eef1f5; }
    * { box-sizing: border-box; }
    body {
        margin: 0; padding: 12px; background: var(--bg); color: var(--ink);
        font: 12px/1.4 Helvetica, Arial, "Noto Nastaliq Urdu", "Jameel Noori Nastaleeq", sans-serif;
    }

    /* Toolbar & empty state (screen only) */
    .toolbar, .empty {
        max-width: 800px; margin: 0 auto 12px; background: #fff; border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,.08);
    }
    .toolbar { display: flex; justify-content: space-between; align-items: center;
               gap: 10px; flex-wrap: wrap; padding: 10px 14px; }
    .empty { max-width: 460px; margin-top: 60px; padding: 36px 28px; text-align: center; }
    .btn { display: inline-block; padding: 6px 14px; border: 1px solid #bbb; border-radius: 4px;
           background: #f7f7f7; color: #222; font-size: 13px; text-decoration: none; cursor: pointer; }
    .btn.primary { background: #1f6feb; border-color: #1f6feb; color: #fff; }

    /* Form page */
    .form-page {
        max-width: 800px; margin: 0 auto 12px; padding: 16px 18px;
        background: #fff; border: 1px solid #999;
    }
    .head { display: flex; align-items: center; gap: 12px; padding-bottom: 8px;
            border-bottom: 2px solid var(--line); margin-bottom: 10px; }
    .head .logo { width: 56px; height: 56px; object-fit: contain; }
    .head .titles { flex: 1; text-align: center; }
    .head h1 { margin: 0 0 2px; font-size: 18px; text-transform: uppercase; }
    .head .sub { font-size: 11px; color: var(--muted); }
    .photo { width: 80px; height: 100px; border: 1px solid var(--line); display: flex;
             align-items: center; justify-content: center; font-size: 10px; color: var(--muted);
             overflow: hidden; flex-shrink: 0; }
    .photo img { width: 100%; height: 100%; object-fit: cover; }

    .office { border: 1.5px solid var(--line); border-radius: 2px; padding: 6px 10px; margin-bottom: 10px; }
    section { margin-bottom: 10px; }
    h2 { margin: 0 0 6px; padding-bottom: 2px; border-bottom: 1px solid var(--line);
         font-size: 13px; text-transform: uppercase; }
    .office h2 { border: 0; margin-bottom: 4px; }

    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 14px; }
    .field.full { grid-column: 1 / -1; }
    .label { display: block; font-size: 10.5px; font-weight: 700; text-transform: uppercase; }
    .value { display: block; min-height: 17px; padding-bottom: 1px; font-size: 12.5px;
             border-bottom: 1px dotted #888; word-wrap: break-word; }

    table.info { width: 100%; border-collapse: collapse; }
    table.info th, table.info td { border: 1px solid var(--line); padding: 4px 8px; text-align: start; }
    table.info th { width: 32%; background: #f0f0f0; }

    .sign { display: flex; gap: 20px; margin-top: 26px; }
    .sign div { flex: 1; text-align: center; font-size: 11px; font-weight: 700; text-transform: uppercase; }
    .sign i { display: block; border-top: 1px solid var(--line); margin-bottom: 4px; }
    .foot { margin-top: 12px; text-align: center; font-size: 10.5px; font-style: italic; color: #333; }

    /* Print: one A4 page per student */
    @media print {
        @page { size: A4 portrait; margin: 10mm 12mm; }
        html, body { background: #fff !important; padding: 0; margin: 0;
                     -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .no-print { display: none !important; }
        .form-page { max-width: none; margin: 0; padding: 0; border: 0;
                     break-after: page; page-break-after: always; }
        .form-page:last-of-type { break-after: auto; page-break-after: auto; }
        section, .office, .head, .sign, table.info tr { break-inside: avoid; page-break-inside: avoid; }
    }
</style>
</head>
<body>

<?php if (!$students): ?>

    <div class="empty no-print">
        <h3><?= h($T['none_title']) ?></h3>
        <p><?= h($T['none_text']) ?></p>
        <a class="btn primary" href="<?= h($school['back']) ?>"><?= h($T['back_btn']) ?></a>
    </div>

<?php else: ?>

    <div class="toolbar no-print">
        <div><strong><?= count($students) ?></strong> <?= h($T['ready']) ?></div>
        <div>
            <a class="btn" href="<?= h($school['back']) ?>"><?= h($T['back_btn']) ?></a>
            <button class="btn primary" onclick="window.print()"><?= h($T['print_btn']) ?></button>
        </div>
    </div>

    <?php foreach ($students as $s):
        $img = safe_image($s['image_path'] ?? null);
        $classLabel = trim(implode(' - ', array_filter([$s['course_title'] ?? '', $s['class_title'] ?? ''])));
        // NOTE: original code treated is_transport == 0 as "Yes". Kept as-is; flip if your schema uses 1 = yes.
        $usesTransport = isset($s['is_transport']) && (int)$s['is_transport'] === 0;
    ?>
    <div class="form-page">

        <div class="head">
            <img class="logo" src="<?= h($school['logo']) ?>" alt="" onerror="this.style.display='none'">
            <div class="titles">
                <h1><?= h($T['form_title']) ?></h1>
                <div class="sub"><?= h($school['name']) ?></div>
                <div class="sub"><?= h($school['address']) ?></div>
            </div>
            <div class="photo">
                <?php if ($img): ?><img src="<?= h($img) ?>" alt=""><?php else: ?><?= h($T['student_image']) ?><?php endif; ?>
            </div>
        </div>

        <div class="office">
            <h2><?= h($T['office_use']) ?></h2>
            <div class="grid">
                <?php field($T['registration_no'], $s['reg_no'] ?? ''); ?>
                <?php field($T['registration_date'], $s['registration_date'] ?? ''); ?>
            </div>
        </div>

        <section>
            <h2><?= h($T['basic_info']) ?></h2>
            <div class="grid">
                <?php field($T['name'], $s['name'] ?? ''); ?>
                <?php field($T['father_name'], $s['father_name'] ?? ''); ?>
                <?php field($T['mobile'], $s['mobile'] ?? ''); ?>
                <?php field($T['cnic'], $s['cnic'] ?? ''); ?>
            </div>
        </section>

        <section>
            <h2><?= h($T['address_info']) ?></h2>
            <div class="grid">
                <?php field($T['current_address'], $s['current_address'] ?? '', true, true); ?>
                <?php field($T['permanent_address'], $s['permanent_address'] ?? '', true, true); ?>
            </div>
        </section>

        <section>
            <h2><?= h($T['guardian_info']) ?></h2>
            <div class="grid">
                <?php field($T['guardian_name'], $s['guardian_name'] ?? ''); ?>
                <?php field($T['guardian_mobile'], $s['guardian_mobile'] ?? ''); ?>
                <?php field($T['guardian_cnic'], $s['guardian_cnic'] ?? ''); ?>
                <?php field($T['guardian_address'], $s['guardian_address'] ?? '', true); ?>
            </div>
        </section>

        <section>
            <h2><?= h($T['academic_info']) ?></h2>
            <table class="info">
                <tr><th><?= h($T['branch']) ?></th><td><?= h($s['branch_title'] ?? '') ?></td></tr>
                <tr><th><?= h($T['village_council']) ?></th><td><?= h($s['village_title'] ?? '') ?></td></tr>
                <tr><th><?= h($T['session']) ?></th><td><?= h($s['session_title'] ?? '') ?></td></tr>
                <tr><th><?= h($T['class']) ?></th><td><?= h($classLabel) ?></td></tr>
            </table>
        </section>

        <section>
            <h2><?= h($T['transport_info']) ?></h2>
            <table class="info">
                <tr><th><?= h($T['using_transport']) ?></th><td><?= h($usesTransport ? $T['yes'] : $T['no']) ?></td></tr>
                <tr><th><?= h($T['transport_fee']) ?></th><td><?= h($s['transport_fee'] ?? '') ?></td></tr>
            </table>
        </section>

        <section>
            <h2><?= h($T['other_info']) ?></h2>
            <div class="grid">
                <?php field($T['previous_schools'], $s['previous_schools_description'] ?? '', true, true); ?>
                <?php field($T['other_details'], $s['student_other_description'] ?? '', true, true); ?>
            </div>
        </section>

        <div class="sign">
            <div><i></i><?= h($T['signature']) ?></div>
            <div><i></i><?= h($T['date']) ?></div>
            <div><i></i><?= h($T['place']) ?></div>
        </div>

        <div class="foot"><?= h($T['footer']) ?></div>
    </div>
    <?php endforeach; ?>

<?php endif; ?>

</body>
</html>
<?php $conn->close(); ?>