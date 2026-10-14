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
        'title' => 'Student List',
        'list_title' => 'Registered Students',
        'no_records' => 'No students found.',
        'delete_confirm' => 'Are you sure you want to delete this student record?',
        'delete_success' => 'Student deleted successfully!',
        'error' => 'Error: ',
        'filter_by_class' => 'Class',
        'filter_by_village' => 'Village Council',
        'filter_by_status' => 'Status',
        'filter_by_cnic' => 'CNIC',
        'filter_by_mobile' => 'Mobile',
        'filter_by_father' => 'Father Name',
        'all_classes' => 'All Classes',
        'all_villages' => 'All Village Councils',
        'all_status' => 'All Students',
        'status_active' => 'Active',
        'status_leave' => 'Leave',
        'status_struck_off' => 'Struck Off',
        'status' => 'Status',
        'status_updated' => 'Student status updated successfully!',
        'status_update_confirm' => 'Change this student\'s status?',
        'status_update_error' => 'Error updating status: ',
        'search_placeholder' => 'Search ALL columns (name, father, mobile, reg no, ID, address, etc.)...',
    ]
];

/* ═══════════════════════════════════════════════════════════
   ALL COLUMNS — full student_registration schema
   ═══════════════════════════════════════════════════════════ */
$all_columns = [
    'id'                          => 'ID',
    'branch_id'                   => 'Branch ID',
    'village_council_id'          => 'Village Council ID',
    'village_council_title'       => 'Village Council',
    'reg_no'                      => 'Reg No',
    'admission_no'                => 'Adm No',
    'admission_date'              => 'Adm Date',
    'roll_no'                     => 'Roll No',
    'name'                        => 'Name',
    'dob'                         => 'DOB',
    'gender'                      => 'Gender',
    'father_name'                 => 'Father Name',
    'father_occupation'           => 'Father Occupation',
    'father_qualification'        => 'Father Qualification',
    'father_income'               => 'Father Income',
    'father_phone'                => 'Father Phone',
    'mobile'                      => 'Mobile',
    'cnic'                        => 'CNIC',
    'b_form_no'                   => 'B-Form No',
    'student_mobile'              => 'Student Mobile',
    'religion'                    => 'Religion',
    'house'                       => 'House',
    'mname'                       => 'Mother Name',
    'mcnic'                       => 'Mother CNIC',
    'current_address'             => 'Current Address',
    'permanent_address'           => 'Permanent Address',
    'guardian_name'               => 'Guardian Name',
    'guardian_mobile'             => 'Guardian Mobile',
    'guardian_address'            => 'Guardian Address',
    'guardian_cnic'               => 'Guardian CNIC',
    'emergency_name'              => 'Emergency Name',
    'emergency_phone'             => 'Emergency Phone',
    'medical_history'             => 'Medical History',
    'previous_schools_description'=> 'Previous Schools',
    'student_other_description'   => 'Other Description',
    'image_path'                  => 'Image Path',
    'registration_date'           => 'Registration Date',
    'is_transport'                => 'Is Transport',
    'transport_fee'               => 'Transport Fee',
    'is_old_dues'                 => 'Is Old Dues',
    'old_dues_amount'             => 'Old Dues Amount',
    'is_admission'                => 'Is Admission',
    'dated'                       => 'Dated',
    'status'                      => 'Status',
    'class_title'                 => 'Class',
    'section_title'               => 'Section',
    'session_title'               => 'Session',
    'branch_title'                => 'Branch',
];

/* Sort columns ascending by display label for the config drawer */
$all_columns_sorted = $all_columns;
asort($all_columns_sorted, SORT_NATURAL | SORT_FLAG_CASE);

/* ═══════════════════════════════════════════════════════════
   ALL FILTERS — dynamically generated from ALL columns
   ═══════════════════════════════════════════════════════════ */
$all_filters = [
    'class'   => 'Class Filter',
    'village' => 'Village Council Filter',
    'status'  => 'Status Filter',
];
foreach ($all_columns as $key => $label) {
    if (in_array($key, ['class_title', 'village_council_title', 'status', 'id'])) {
        continue;
    }
    $all_filters[$key] = $label . ' Filter';
}

/* Sort filters ascending by display label for the config drawer */
$all_filters_sorted = $all_filters;
asort($all_filters_sorted, SORT_NATURAL | SORT_FLAG_CASE);

/* By default, every column is visible in the table */
$default_columns = array_keys($all_columns);
$default_filters = [];

if (!isset($_SESSION['student_columns_visible']) || !is_array($_SESSION['student_columns_visible'])) {
    $_SESSION['student_columns_visible'] = $default_columns;
}
if (!isset($_SESSION['student_filters_visible']) || !is_array($_SESSION['student_filters_visible'])) {
    $_SESSION['student_filters_visible'] = $default_filters;
}

if (isset($_GET['reset_layout'])) {
    $_SESSION['student_columns_visible'] = $default_columns;
    $_SESSION['student_filters_visible'] = $default_filters;
    header('Location: student_list.php');
    exit;
}

if (isset($_GET['save_filters'])) {
    $vis_cols = isset($_GET['visible_columns']) && is_array($_GET['visible_columns'])
        ? array_values(array_intersect(array_keys($all_columns), $_GET['visible_columns']))
        : $_SESSION['student_columns_visible'];

    $vis_filters = isset($_GET['visible_filters']) && is_array($_GET['visible_filters'])
        ? array_values(array_intersect(array_keys($all_filters), $_GET['visible_filters']))
        : $_SESSION['student_filters_visible'];

    $_SESSION['student_columns_visible'] = $vis_cols;
    $_SESSION['student_filters_visible'] = $vis_filters;

    $back = $_GET;
    unset($back['save_filters'], $back['visible_columns'], $back['visible_filters']);
    header('Location: student_list.php' . (!empty($back) ? '?' . http_build_query($back) : ''));
    exit;
}
$columns_visible = $_SESSION['student_columns_visible'];
$filters_visible = $_SESSION['student_filters_visible'];

/* Bulk actions */
if (isset($_POST['bulk_action']) && isset($_POST['student_ids']) && is_array($_POST['student_ids'])) {
    $ids = array_values(array_filter(array_map('intval', $_POST['student_ids'])));
    $bulk = $_POST['bulk_action'];
    $ok = 0; $fail = 0;

    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        if ($bulk === 'delete' && !empty($perms['can_delete'])) {
            $stmt = $conn->prepare("DELETE FROM student_registration WHERE id IN ($placeholders)");
            $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
            if ($stmt->execute()) $ok = $stmt->affected_rows; else $fail = count($ids);
            $stmt->close();
            $_SESSION['message'] = "$ok student(s) deleted." . ($fail ? " $fail failed." : '');
            $_SESSION['message_type'] = $fail ? 'warning' : 'success';
        } elseif ($bulk === 'status' && !empty($perms['can_edit'])) {
            $new_status = (int)($_POST['bulk_status'] ?? 0);
            $stmt = $conn->prepare("UPDATE student_registration SET status = ? WHERE id IN ($placeholders)");
            $types = 'i' . str_repeat('i', count($ids));
            $params = array_merge([$new_status], $ids);
            $stmt->bind_param($types, ...$params);
            if ($stmt->execute()) $ok = $stmt->affected_rows; else $fail = count($ids);
            $stmt->close();
            $_SESSION['message'] = "Status updated for $ok student(s)." . ($fail ? " $fail failed." : '');
            $_SESSION['message_type'] = $fail ? 'warning' : 'success';
        } elseif ($bulk === 'print_batch') {
            header('Location: print_student_list.php?ids=' . implode(',', $ids));
            exit;
        } elseif ($bulk === 'print_registration') {
            header('Location: print_student_registration_batch.php?ids=' . implode(',', $ids));
            exit;
        }
    } else {
        $_SESSION['message'] = 'No students selected.';
        $_SESSION['message_type'] = 'warning';
    }
    header('Location: student_list.php');
    exit;
}

if (isset($_POST['update_status'])) {
    $student_id = intval($_POST['student_id']);
    $new_status = intval($_POST['new_status']);
    $stmt = $conn->prepare("UPDATE student_registration SET status = ? WHERE id = ?");
    $stmt->bind_param("ii", $new_status, $student_id);
    if ($stmt->execute()) {
        $_SESSION['message'] = $translations[$lang]['status_updated'];
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = $translations[$lang]['status_update_error'] . $stmt->error;
        $_SESSION['message_type'] = 'danger';
    }
    $stmt->close();
    header("Location: student_list.php");
    exit();
}

if (isset($_GET['delete']) && !empty($perms['can_delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM student_registration WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $_SESSION['message'] = $translations[$lang]['delete_success'];
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = $translations[$lang]['error'] . $stmt->error;
        $_SESSION['message_type'] = 'danger';
    }
    $stmt->close();
    header("Location: student_list.php");
    exit();
}

/* Dropdown data */
$class_filter_query = $conn->query("
    SELECT
        COALESCE(cs.id, 0) AS class_section_id,
        c.id               AS class_id,
        c.title            AS class_title,
        s.title            AS section_title,
        CASE WHEN cs.id IS NULL THEN c.title
             ELSE CONCAT(c.title, ' - ', s.title) END AS display_label
    FROM classes c
    LEFT JOIN class_sections cs ON cs.class_id = c.id AND cs.status = 0
    LEFT JOIN sections s ON s.id = cs.section_id
    WHERE c.status = 0
    ORDER BY c.title, s.title
");
$class_options = [];
while ($row = $class_filter_query->fetch_assoc()) $class_options[] = $row;

$village_councils = [];
$vc_q = $conn->query("SELECT id, title FROM village_councils ORDER BY title");
while ($row = $vc_q->fetch_assoc()) $village_councils[$row['id']] = $row['title'];

/* ═══════════════════════════════════════════════════════════
   DYNAMIC FILTER PROCESSING
   ═══════════════════════════════════════════════════════════ */
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$class_filter = isset($_GET['class_filter']) ? intval($_GET['class_filter']) : 0;
$class_section_filter = isset($_GET['class_section_filter']) ? intval($_GET['class_section_filter']) : 0;
$village_filter = isset($_GET['village_filter']) ? intval($_GET['village_filter']) : 0;
$status_filter = isset($_GET['status_filter']) ? $_GET['status_filter'] : '';

$active_filters = [];
foreach ($filters_visible as $fkey) {
    if (in_array($fkey, ['class', 'village', 'status'])) {
        continue;
    }
    $get_key = $fkey . '_filter';
    if (isset($_GET[$get_key]) && trim($_GET[$get_key]) !== '') {
        $active_filters[$fkey] = trim($_GET[$get_key]);
    }
}

$where = [];

/* 1. GLOBAL SEARCH */
if (!empty($search)) {
    $search_safe = $conn->real_escape_string($search);
    $search_columns = [
        'id', 'branch_id', 'village_council_id', 'reg_no', 'admission_no', 'admission_date', 'roll_no', 'name',
        'dob', 'gender', 'father_name', 'father_occupation', 'father_qualification', 'father_income', 'father_phone',
        'mobile', 'cnic', 'b_form_no', 'student_mobile', 'religion', 'house', 'mname', 'mcnic', 'current_address',
        'permanent_address', 'guardian_name', 'guardian_mobile', 'guardian_address', 'guardian_cnic', 'emergency_name',
        'emergency_phone', 'medical_history', 'previous_schools_description', 'student_other_description', 'image_path',
        'registration_date', 'is_transport', 'transport_fee', 'is_old_dues', 'old_dues_amount', 'is_admission', 'dated', 'status'
    ];
    $search_conditions = [];
    foreach ($search_columns as $col) {
        $search_conditions[] = "sr.$col LIKE '%$search_safe%'";
    }
    $search_conditions[] = "vc.title LIKE '%$search_safe%'";
    $search_conditions[] = "c.title LIKE '%$search_safe%'";
    $search_conditions[] = "s.title LIKE '%$search_safe%'";
    $search_conditions[] = "b.title LIKE '%$search_safe%'";
    $search_conditions[] = "ses.title LIKE '%$search_safe%'";

    $where[] = "(" . implode(" OR ", $search_conditions) . ")";
}

/* 2. SPECIAL DROPDOWN FILTERS */
if (in_array('class', $filters_visible, true) && $class_filter > 0) {
    $where[] = "sc.class_id = $class_filter";
}
if (in_array('class', $filters_visible, true) && $class_section_filter > 0) {
    $where[] = "sc.class_section_id = $class_section_filter";
}
if (in_array('village', $filters_visible, true) && $village_filter > 0) {
    $where[] = "sr.village_council_id = $village_filter";
}
if (in_array('status', $filters_visible, true) && $status_filter !== '' && $status_filter !== 'all') {
    $where[] = "sr.status = '" . $conn->real_escape_string($status_filter) . "'";
}

/* 3. DYNAMIC COLUMN FILTERS */
foreach ($active_filters as $fkey => $fval) {
    $safe_val = $conn->real_escape_string($fval);

    if ($fkey === 'cnic') {
        $where[] = "(sr.cnic LIKE '%$safe_val%' OR sr.mcnic LIKE '%$safe_val%' OR sr.guardian_cnic LIKE '%$safe_val%')";
        continue;
    }
    if ($fkey === 'mobile') {
        $where[] = "(sr.mobile LIKE '%$safe_val%' OR sr.father_phone LIKE '%$safe_val%' OR sr.guardian_mobile LIKE '%$safe_val%' OR sr.student_mobile LIKE '%$safe_val%' OR sr.emergency_phone LIKE '%$safe_val%')";
        continue;
    }
    if ($fkey === 'father_name') {
        $where[] = "sr.father_name LIKE '%$safe_val%'";
        continue;
    }

    $table_prefix = 'sr.';
    if ($fkey === 'class_title') $table_prefix = 'c.';
    elseif ($fkey === 'section_title') $table_prefix = 's.';
    elseif ($fkey === 'session_title') $table_prefix = 'ses.';
    elseif ($fkey === 'branch_title') $table_prefix = 'b.';
    elseif ($fkey === 'village_council_title') $table_prefix = 'vc.';

    /* GENDER: exact match instead of LIKE — the dropdown sends the exact value */
    if ($fkey === 'gender') {
        $where[] = "sr.gender = '$safe_val'";
        continue;
    }

    $where[] = "$table_prefix$fkey LIKE '%$safe_val%'";
}

$where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$query = "
    SELECT
        sr.*,
        c.title  AS class_title,
        s.title  AS section_title,
        ses.title AS session_title,
        vc.title AS village_council_title,
        b.title  AS branch_title
    FROM student_registration sr
    LEFT JOIN (
        SELECT sc1.student_registration_id, sc1.class_id, sc1.class_section_id, sc1.session_id
        FROM student_class sc1
        INNER JOIN (
            SELECT student_registration_id, MAX(id) AS max_id
            FROM student_class
            GROUP BY student_registration_id
        ) sc2 ON sc1.id = sc2.max_id
    ) sc ON sr.id = sc.student_registration_id
    LEFT JOIN classes c ON sc.class_id = c.id
    LEFT JOIN class_sections cs ON sc.class_section_id = cs.id
    LEFT JOIN sections s ON cs.section_id = s.id
    LEFT JOIN sessions ses ON sc.session_id = ses.id
    LEFT JOIN village_councils vc ON sr.village_council_id = vc.id
    LEFT JOIN branches b ON sr.branch_id = b.id
    $where_clause
    ORDER BY sr.id DESC
";
$result = $conn->query($query);
$total_students = $result ? $result->num_rows : 0;

$students = [];
if ($result) while ($r = $result->fetch_assoc()) $students[] = $r;

/* Format any cell value for display */
function student_cell_value($row, $key) {
    switch ($key) {
        case 'is_transport':
        case 'is_old_dues':
        case 'is_admission':
            return ((int)($row[$key] ?? 0) === 1) ? 'Yes' : 'No';
        case 'status':
            if ((int)$row['status'] === 1) return 'Leave';
            if ((int)$row['status'] === 2) return 'Struck Off';
            return 'Active';
        default:
            if (!array_key_exists($key, $row)) return '—';
            $v = $row[$key];
            if ($v === null || $v === '') return '—';
            return $v;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($translations[$lang]['title']); ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script>
var visibleColumns = <?php echo json_encode($columns_visible, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
var allColumnLabels = <?php echo json_encode($all_columns, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
var studentsData = <?php echo json_encode($students, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
</script>
<style>
    body { font-family: 'Inter', sans-serif; background: #f5f7fa; }
    .panel { background: #fff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 24px; overflow: hidden; }
    .panel-heading {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: #fff; padding: 14px 20px;
        display: flex; justify-content: space-between; align-items: center;
        gap: 12px; flex-wrap: wrap;
    }
    .panel-title { margin: 0; font-weight: 600; font-size: 1.05rem; }
    .panel-body { padding: 18px; }
    .toolbar-btn {
        display: inline-flex; align-items: center; gap: 8px;
        background: rgba(255,255,255,0.18); color: #fff;
        border: 1px solid rgba(255,255,255,0.3);
        padding: 7px 14px; border-radius: 8px;
        font-size: 13px; font-weight: 600; font-family: inherit;
        cursor: pointer; line-height: 1; text-decoration: none;
        transition: all 0.15s;
    }
    .toolbar-btn:hover { background: #fff; color: #1e40af; border-color: #fff; text-decoration: none; }
    .toolbar-btn.primary { background: #ffffff; color: #1e40af; border-color: #ffffff; }
    .toolbar-btn.primary:hover { background: #dbeafe; border-color: #dbeafe; }
    .export-wrap { position: relative; display: inline-block; }
    .export-menu {
        display: none; position: absolute; right: 0; top: calc(100% + 6px);
        background: #fff; min-width: 220px; border-radius: 10px;
        box-shadow: 0 8px 24px rgba(0,0,0,0.15);
        border: 1px solid #e2e8f0; padding: 6px; z-index: 50;
    }
    .export-menu.open { display: block; }
    .export-menu .export-item {
        display: flex; align-items: center; gap: 10px;
        padding: 9px 12px; border-radius: 6px;
        font-size: 13px; font-weight: 600; color: #334155;
        cursor: pointer; text-decoration: none;
    }
    .export-menu .export-item:hover { background: #eff6ff; color: #1e40af; text-decoration: none; }
    .export-menu .export-item i { width: 16px; color: #1e40af; }
    .export-menu .export-item i.excel { color: #107c41; }
    .export-menu .export-item i.csv { color: #0f9d58; }
    .export-menu .export-item i.print { color: #64748b; }
    .export-menu .export-item i.copy { color: #d97706; }
    .search-drawer {
        max-height: 0; overflow: hidden; transition: max-height 0.25s ease;
        background: #f8fafc; border-bottom: 1px solid #e2e8f0;
    }
    .search-drawer.open { max-height: 140px; }
    .search-drawer .inner { padding: 14px 18px; display: flex; gap: 10px; flex-wrap: wrap; }
    .search-drawer input {
        flex: 1; min-width: 220px; height: 42px; padding: 0 14px;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 14px; font-family: inherit;
    }
    .search-drawer input:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 3px rgba(30,64,175,0.12); }
    .search-drawer .search-submit {
        height: 42px; padding: 0 20px;
        background: #1e40af; color: #fff;
        border: 1px solid #1e40af; border-radius: 8px;
        font-size: 13px; font-weight: 700; cursor: pointer;
    }
    .search-drawer .search-submit:hover { background: #1e3a8a; border-color: #1e3a8a; }
    .search-drawer .search-clear, .search-drawer .search-close {
        height: 42px; padding: 0 16px; background: #fff; color: #334155;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 13px; font-weight: 600; cursor: pointer;
        display: inline-flex; align-items: center; gap: 6px;
        text-decoration: none;
    }
    .search-drawer .search-clear:hover, .search-drawer .search-close:hover { background: #f1f5f9; text-decoration: none; color: #334155; }
    .filter-row {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 12px; padding: 14px 18px;
        background: #fff; border-bottom: 1px solid #e2e8f0;
    }
    .filter-row .field label {
        display: block; font-size: 11px; font-weight: 700;
        color: #64748b; text-transform: uppercase;
        letter-spacing: 0.8px; margin-bottom: 5px;
    }
    .filter-row select,
    .filter-row input[type="text"] {
        width: 100%; height: 40px; padding: 0 12px;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 13px; background: #fff;
        font-family: inherit;
        box-sizing: border-box;
    }
    .filter-row select {
        padding-right: 32px;
        -webkit-appearance: none; appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L2 4h8z'/%3E%3C/svg%3E");
        background-repeat: no-repeat; background-position: right 12px center;
    }
    .filter-row select:focus,
    .filter-row input[type="text"]:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 3px rgba(30,64,175,0.12); }

    .config-drawer { max-height: 0; overflow: hidden; transition: max-height 0.3s ease; background: #fffbeb; border-bottom: 1px solid #fde68a; }
    .config-drawer.open { max-height: 2200px; }
    .config-drawer .inner { padding: 16px 18px; }
    .config-drawer h6 { font-size: 12px; font-weight: 800; text-transform: uppercase; color: #92400e; letter-spacing: 1px; margin: 0 0 10px 0; }
    .config-drawer .col-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 8px 20px; margin-bottom: 14px; max-height: 400px; overflow-y: auto; padding-right: 6px; }
    .config-drawer .opt { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #334155; cursor: pointer; }
    .config-drawer .opt input { width: 16px; height: 16px; accent-color: #1e40af; cursor: pointer; }
    .config-drawer .actions { margin-top: 14px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; padding-top: 14px; border-top: 1px dashed #fde68a; }
    .config-drawer .quick-links { margin-left: auto; display: flex; gap: 6px; font-size: 12px; }
    .config-drawer .quick-links a { color: #1e40af; text-decoration: none; font-weight: 700; cursor: pointer; }
    .config-drawer .quick-links a:hover { text-decoration: underline; }

    .bulk-bar {
        display: none; align-items: center; gap: 10px; flex-wrap: wrap;
        padding: 12px 18px;
        background: linear-gradient(135deg, #1e40af 0%, #1e3a8a 100%);
        color: #fff; border-bottom: 1px solid #1e3a8a;
    }
    .bulk-bar.open { display: flex; }
    .bulk-bar .count { font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .bulk-bar .count .num { display: inline-flex; align-items: center; justify-content: center; min-width: 26px; height: 26px; padding: 0 8px; border-radius: 20px; background: rgba(255,255,255,0.2); font-weight: 800; }
    .bulk-bar .bulk-btn {
        display: inline-flex; align-items: center; gap: 6px;
        height: 36px; padding: 0 14px; border-radius: 8px;
        font-size: 12px; font-weight: 700; font-family: inherit;
        cursor: pointer; border: 1px solid rgba(255,255,255,0.3);
        background: rgba(255,255,255,0.15); color: #fff;
        transition: all 0.15s; text-decoration: none;
    }
    .bulk-bar .bulk-btn:hover { background: #fff; color: #1e40af; text-decoration: none; }
    .bulk-bar .bulk-btn.danger { background: #dc2626; border-color: #dc2626; }
    .bulk-bar .bulk-btn.danger:hover { background: #b91c1c; border-color: #b91c1c; color: #fff; }
    .bulk-bar select {
        height: 36px; padding: 0 12px; border-radius: 8px;
        border: 1px solid rgba(255,255,255,0.3);
        background: rgba(255,255,255,0.15); color: #fff;
        font-size: 12px; font-family: inherit; font-weight: 600;
    }
    .bulk-bar select option { color: #1e293b; background: #fff; }
    .bulk-bar .bulk-close { margin-left: auto; background: transparent; border: none; color: #fff; font-size: 20px; cursor: pointer; line-height: 1; padding: 0 6px; }
    .results-bar {
        display: flex; justify-content: space-between; align-items: center;
        padding: 10px 18px; background: #eff6ff;
        border-bottom: 1px solid #bfdbfe;
        font-size: 13px; color: #1e3a8a;
    }
    .excel-wrap {
        overflow: auto;
        max-height: 74vh;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        background: #fff;
    }
    table.excel {
        border-collapse: separate; border-spacing: 0;
        min-width: 100%;
    }
    table.excel thead th {
        position: sticky; top: 0; z-index: 3;
        background: #f1f5f9; color: #334155;
        font-size: 10.5px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.5px;
        padding: 9px 10px; text-align: left;
        border-bottom: 2px solid #cbd5e1;
        border-right: 1px solid #e2e8f0;
        white-space: nowrap;
    }
    table.excel thead th:last-child { border-right: none; }
    table.excel tbody td {
        padding: 7px 10px;
        font-size: 11.5px; color: #1e293b;
        border-bottom: 1px solid #f1f5f9;
        border-right: 1px solid #f1f5f9;
        white-space: nowrap;
        vertical-align: middle;
        max-width: 260px;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    table.excel tbody td:last-child { border-right: none; }
    table.excel tbody tr:nth-child(even) td { background: #f8fafc; }
    table.excel tbody tr:hover td { background: #eff6ff; }

    table.excel thead th.check-col,
    table.excel tbody td.check-col {
        position: sticky;
        left: 0;
        z-index: 5;
        background: #fff;
        width: 40px;
        min-width: 40px;
        text-align: center;
        box-shadow: 2px 0 4px rgba(0,0,0,0.04);
    }
    table.excel thead th.check-col { z-index: 6; background: #f1f5f9; }
    table.excel tbody tr:nth-child(even) td.check-col { background: #f8fafc; }
    table.excel tbody tr:hover td.check-col { background: #eff6ff; }

    table.excel thead th.sticky-col,
    table.excel tbody td.sticky-col {
        position: sticky;
        z-index: 4;
        background: #fff;
        box-shadow: 2px 0 4px rgba(0,0,0,0.04);
        width: 50px;
        min-width: 50px;
    }
    table.excel thead th.sticky-col { z-index: 6; background: #f1f5f9; }
    table.excel tbody tr:nth-child(even) td.sticky-col { background: #f8fafc; }
    table.excel tbody tr:hover td.sticky-col { background: #eff6ff; }

    table.excel tbody tr.selected td,
    table.excel tbody tr.selected td.sticky-col,
    table.excel tbody tr.selected td.check-col { background: #dbeafe !important; }

    .bulk-checkbox { width: 16px; height: 16px; cursor: pointer; accent-color: #1e40af; }
    .id-cell { font-weight: 700; color: #1e40af; font-family: 'SF Mono', 'Monaco', monospace; }
    .name-cell { font-weight: 600; color: #0f172a; }
    .center-cell { text-align: center; }
    .status-pill {
        display: inline-block; padding: 3px 10px;
        border-radius: 12px; font-size: 10px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.4px;
    }
    .status-pill.active { background: #d1fae5; color: #065f46; }
    .status-pill.leave  { background: #fef3c7; color: #92400e; }
    .status-pill.struck { background: #fee2e2; color: #991b1b; }
    .yes-yes { color: #059669; font-weight: 700; }
    .yes-no  { color: #94a3b8; font-weight: 600; }
    .empty-state { padding: 50px 20px; text-align: center; color: #94a3b8; }
    .empty-state .big { font-size: 44px; opacity: 0.4; margin-bottom: 10px; }
</style>
</head>
<body>
<?php require_once('navbar.php'); ?>

<div class="container-fluid" style="padding: 20px; max-width: 100%; margin: 0 auto;">

    <div class="panel">
        <div class="panel-heading">
            <h3 class="panel-title"><?php echo $translations[$lang]['list_title']; ?> (<?php echo $total_students; ?>) — <?php echo count($columns_visible); ?> columns</h3>
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <button type="button" class="toolbar-btn" id="searchToggle">
                    <i class="fas fa-search"></i> Search
                </button>

                <div class="export-wrap" id="settingsWrap">
                    <button type="button" class="toolbar-btn" id="settingsToggle">
                        <i class="fas fa-cog"></i> Settings <i class="fas fa-caret-down" style="font-size:10px;"></i>
                    </button>
                    <div class="export-menu" id="settingsMenu">
                        <a class="export-item" href="#" id="openFiltersBtn">
                            <i class="fas fa-filter"></i> Filters
                        </a>
                        <a class="export-item" href="#" id="openColumnsBtn">
                            <i class="fas fa-columns"></i> Columns
                        </a>
                    </div>
                </div>

                <div class="export-wrap">
                    <button type="button" class="toolbar-btn" id="exportToggle">
                        <i class="fas fa-download"></i> Export <i class="fas fa-caret-down" style="font-size:10px;"></i>
                    </button>
                    <div class="export-menu" id="exportMenu">
                        <a class="export-item" href="#" id="exportExcel"><i class="fas fa-file-excel excel"></i> Export as Excel (.xlsx)</a>
                        <a class="export-item" href="#" id="exportCsv"><i class="fas fa-file-csv csv"></i> Export as CSV</a>
                        <a class="export-item" href="#" id="exportPrint"><i class="fas fa-print print"></i> Print List</a>
                        <a class="export-item" href="#" id="exportCopy"><i class="fas fa-copy copy"></i> Copy to Clipboard</a>
                    </div>
                </div>

                <a href="print_student_list.php?<?php echo http_build_query($_GET); ?>" class="toolbar-btn primary" target="_blank">
                    <i class="fas fa-print"></i> Print
                </a>
            </div>
        </div>

        <!-- Search drawer -->
        <div class="search-drawer" id="searchDrawer">
            <form method="GET" action="" class="inner">
                <?php
                foreach ($_GET as $k => $v) {
                    if ($k === 'search' || strpos($k, '_filter') !== false || $k === 'class_combo_filter') {
                        echo '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '">';
                    }
                }
                ?>
                <input type="text" name="search" id="searchInput"
                    placeholder="<?php echo $translations[$lang]['search_placeholder']; ?>"
                    value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                <button type="submit" class="search-submit"><i class="fas fa-search"></i> Search</button>
                <?php if ($search): ?><a href="?" class="search-clear"><i class="fas fa-times"></i> Clear</a><?php endif; ?>
                <button type="button" class="search-close" id="closeSearchDrawer"><i class="fas fa-chevron-up"></i> Close</button>
            </form>
        </div>

        <!-- FILTERS DRAWER -->
        <div class="config-drawer" id="filtersDrawer">
            <form method="GET" action="" class="inner">
                <?php
                foreach ($_GET as $k => $v) {
                    if ($k === 'search' || strpos($k, '_filter') !== false || $k === 'class_combo_filter') {
                        echo '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '">';
                    }
                }
                ?>
                <input type="hidden" name="save_filters" value="1">

                <h6><i class="fas fa-filter"></i> Filter dropdowns to show above the table (ALL COLUMNS)</h6>
                <div class="col-grid">
                    <?php foreach ($all_filters_sorted as $fk => $flabel): ?>
                        <label class="opt">
                            <input type="checkbox" name="visible_filters[]" value="<?php echo htmlspecialchars($fk); ?>"
                                <?php echo in_array($fk, $filters_visible, true) ? 'checked' : ''; ?>>
                            <?php echo htmlspecialchars($flabel); ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="actions">
                    <button type="submit" class="btn btn-warning btn-sm"><i class="fas fa-save"></i> Save Filters</button>
                    <button type="button" class="btn btn-light btn-sm" id="closeFiltersDrawer"><i class="fas fa-times"></i> Cancel</button>
                    <div class="quick-links">
                        <a href="#" id="selectAllFilterBoxes">Select all</a>
                        <span style="color:#cbd5e1;">·</span>
                        <a href="#" id="clearAllFilterBoxes">Clear all</a>
                    </div>
                </div>
            </form>
        </div>

        <!-- COLUMNS DRAWER -->
        <div class="config-drawer" id="columnsDrawer">
            <form method="GET" action="" class="inner">
                <?php
                foreach ($_GET as $k => $v) {
                    if ($k === 'search' || strpos($k, '_filter') !== false || $k === 'class_combo_filter') {
                        echo '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '">';
                    }
                }
                ?>
                <input type="hidden" name="save_filters" value="1">

                <h6><i class="fas fa-columns"></i> Table columns — <?= count($all_columns) ?> available</h6>
                <div class="col-grid">
                    <?php foreach ($all_columns_sorted as $ck => $clabel): ?>
                        <label class="opt">
                            <input type="checkbox" name="visible_columns[]" value="<?php echo htmlspecialchars($ck); ?>"
                                <?php echo in_array($ck, $columns_visible, true) ? 'checked' : ''; ?>>
                            <?php echo htmlspecialchars($clabel); ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="actions">
                    <button type="submit" class="btn btn-warning btn-sm"><i class="fas fa-save"></i> Save Columns</button>
                    <a href="?reset_layout=1" class="btn btn-info btn-sm" onclick="return confirm('Reset to all columns visible?');">
                        <i class="fas fa-rotate"></i> Show All Columns
                    </a>
                    <button type="button" class="btn btn-light btn-sm" id="closeColumnsDrawer"><i class="fas fa-times"></i> Cancel</button>
                    <div class="quick-links">
                        <a href="#" id="selectAllColBoxes">Select all</a>
                        <span style="color:#cbd5e1;">·</span>
                        <a href="#" id="clearAllColBoxes">Clear all</a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Bulk bar -->
        <div class="bulk-bar" id="bulkBar">
            <span class="count"><span class="num" id="bulkCount">0</span> selected</span>

            <?php if (!empty($perms['can_edit'])): ?>
            <form method="POST" action="" id="bulkStatusForm" style="display:flex;gap:6px;align-items:center;">
                <input type="hidden" name="bulk_action" value="status">
                <select name="bulk_status" id="bulkStatusSelect" required>
                    <option value="">— Set status —</option>
                    <option value="0">Active</option>
                    <option value="1">Leave</option>
                    <option value="2">Struck Off</option>
                </select>
                <button type="submit" class="bulk-btn"><i class="fas fa-exchange-alt"></i> Apply</button>
            </form>
            <?php endif; ?>

            <button type="button" class="bulk-btn" id="bulkEditBtn"><i class="fas fa-edit"></i> Edit</button>
            <button type="button" class="bulk-btn" id="bulkFeeBtn"><i class="fas fa-money-bill-wave"></i> Collect Fee</button>
            <button type="button" class="bulk-btn" id="bulkPrintRegBtn"><i class="fas fa-file-alt"></i> Print Registration</button>
            <button type="button" class="bulk-btn" id="bulkExportBtn"><i class="fas fa-file-excel"></i> Export Selected</button>

            <?php if (!empty($perms['can_delete'])): ?>
            <form method="POST" action="" id="bulkDeleteForm" style="display:inline;">
                <input type="hidden" name="bulk_action" value="delete">
                <button type="submit" class="bulk-btn danger" onclick="return confirm('Delete selected students? This cannot be undone.');">
                    <i class="fas fa-trash"></i> Delete
                </button>
            </form>
            <?php endif; ?>

            <button type="button" class="bulk-close" id="bulkClear" title="Clear selection">&times;</button>
        </div>

        <!-- Filters -->
        <form method="GET" action="" id="filterForm">
            <?php if (!empty($search)): ?>
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
            <?php endif; ?>

            <?php if (!empty($filters_visible)): ?>
            <div class="filter-row">
                <?php foreach ($filters_visible as $fkey):
                    if (!isset($all_columns[$fkey]) && !in_array($fkey, ['class', 'village', 'status'])) continue;

                    $flabel = in_array($fkey, ['class', 'village', 'status']) ? $all_filters[$fkey] : $all_columns[$fkey];
                    $get_key = $fkey . '_filter';
                    $fval = isset($_GET[$get_key]) ? htmlspecialchars($_GET[$get_key]) : '';
                ?>
                <div class="field">
                    <label><?php echo htmlspecialchars($flabel); ?></label>
                    <?php if ($fkey === 'class'): ?>
                        <select name="class_combo_filter" id="class_combo_filter">
                            <option value="0|0"><?php echo $translations[$lang]['all_classes']; ?></option>
                            <?php foreach ($class_options as $opt):
                                $val = $opt['class_id'] . '|' . $opt['class_section_id'];
                                $sel = ((int)$class_filter === (int)$opt['class_id'] && (int)$class_section_filter === (int)$opt['class_section_id']);
                            ?>
                                <option value="<?php echo $val; ?>" <?php echo $sel ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($opt['display_label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="class_filter" id="class_filter_hidden" value="<?php echo (int)$class_filter; ?>">
                        <input type="hidden" name="class_section_filter" id="class_section_filter_hidden" value="<?php echo (int)$class_section_filter; ?>">
                    <?php elseif ($fkey === 'village'): ?>
                        <select name="village_filter" id="village_filter">
                            <option value="0"><?php echo $translations[$lang]['all_villages']; ?></option>
                            <?php foreach ($village_councils as $id => $name): ?>
                                <option value="<?php echo $id; ?>" <?php echo ($village_filter == $id) ? 'selected' : ''; ?>><?php echo htmlspecialchars($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($fkey === 'status'): ?>
                        <select name="status_filter" id="status_filter">
                            <option value="all" <?php echo ($status_filter === '' || $status_filter === 'all') ? 'selected' : ''; ?>><?php echo $translations[$lang]['all_status']; ?></option>
                            <option value="0"   <?php echo ($status_filter === '0')   ? 'selected' : ''; ?>><?php echo $translations[$lang]['status_active']; ?></option>
                            <option value="1"   <?php echo ($status_filter === '1')   ? 'selected' : ''; ?>><?php echo $translations[$lang]['status_leave']; ?></option>
                            <option value="2"   <?php echo ($status_filter === '2')   ? 'selected' : ''; ?>><?php echo $translations[$lang]['status_struck_off']; ?></option>
                        </select>
                    <?php elseif ($fkey === 'gender'): ?>
                        <!-- GENDER: Render as selectbox -->
                        <select name="gender_filter" id="gender_filter">
                            <option value="">-- All --</option>
                            <option value="Male"   <?php echo ($fval === 'Male')   ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo ($fval === 'Female') ? 'selected' : ''; ?>>Female</option>
                            <option value="Other"  <?php echo ($fval === 'Other')  ? 'selected' : ''; ?>>Other</option>
                        </select>
                    <?php else: ?>
                        <input type="text" name="<?php echo $get_key; ?>" id="<?php echo $get_key; ?>"
                            placeholder="Search <?php echo htmlspecialchars($flabel); ?>..."
                            value="<?php echo $fval; ?>">
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </form>

        <?php
        $has_active_filters = !empty($search) || $class_filter > 0 || $village_filter > 0 || ($status_filter !== '' && $status_filter !== 'all') || !empty($active_filters);
        if ($has_active_filters):
        ?>
        <div class="results-bar">
            <div><i class="fas fa-filter"></i> Showing <strong><?php echo $total_students; ?></strong> result(s)</div>
            <a href="student_list.php" class="btn btn-sm btn-warning">Reset All Filters</a>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['message'])): ?>
            <div style="padding: 12px 18px;">
                <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show" style="margin:0;">
                    <?php echo $_SESSION['message']; unset($_SESSION['message'], $_SESSION['message_type']); ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            </div>
        <?php endif; ?>

        <div class="panel-body" style="padding: 14px;">

            <?php if (!empty($students)): ?>
            <div class="excel-wrap">
                <table class="excel" id="studentTable">
                    <thead>
                        <tr>
                            <th class="check-col">
                                <input type="checkbox" class="bulk-checkbox" id="selectAll">
                            </th>
                            <th class="sticky-col">#</th>

                            <?php foreach ($columns_visible as $colKey):
                                if (!isset($all_columns[$colKey])) continue;
                            ?>
                                <th <?php echo $colKey === 'status' ? 'class="center-cell"' : ''; ?>>
                                    <?php echo htmlspecialchars($all_columns[$colKey]); ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $count = 1; foreach ($students as $row):
                            $status_class = 'active';
                            $status_text  = 'Active';
                            if ($row['status'] == 1) { $status_class = 'leave';  $status_text = 'Leave'; }
                            elseif ($row['status'] == 2) { $status_class = 'struck'; $status_text = 'Struck Off'; }
                        ?>
                        <tr data-row-id="<?php echo (int)$row['id']; ?>">
                            <td class="check-col">
                                <input type="checkbox" class="bulk-checkbox row-checkbox"
                                       name="student_ids[]" value="<?php echo (int)$row['id']; ?>">
                            </td>
                            <td class="sticky-col center-cell" style="color:#64748b;"><?php echo $count++; ?></td>

                            <?php foreach ($columns_visible as $colKey):
                                if (!isset($all_columns[$colKey])) continue;
                                $val = student_cell_value($row, $colKey);
                                $cellClass = '';
                                if ($colKey === 'id') $cellClass = 'id-cell';
                                elseif ($colKey === 'name') $cellClass = 'name-cell';
                                elseif ($colKey === 'status') $cellClass = 'center-cell';
                                elseif (in_array($colKey, ['is_transport','is_old_dues','is_admission'], true)) {
                                    $cellClass = ($val === 'Yes') ? 'center-cell yes-yes' : 'center-cell yes-no';
                                }
                                if ($colKey === 'status'): ?>
                                    <td class="center-cell">
                                        <span class="status-pill <?php echo $status_class; ?>">
                                            <?php echo $status_text; ?>
                                        </span>
                                    </td>
                                <?php else: ?>
                                    <td class="<?php echo $cellClass; ?>" title="<?php echo htmlspecialchars((string)$val); ?>">
                                        <?php echo htmlspecialchars((string)$val); ?>
                                    </td>
                                <?php endif;
                            endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="big">📋</div>
                    <h4><?php echo $translations[$lang]['no_records']; ?></h4>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {

    function updateStickyPositions() {
        var idVisible = visibleColumns.includes('id');
        $('.check-col').css('left', '0px');
        if (idVisible) {
            $('.sticky-col').css('left', '40px');
        } else {
            $('.sticky-col').css('left', '-100px');
        }
    }
    updateStickyPositions();

    $('#searchToggle').on('click', function() {
        $('#searchDrawer').toggleClass('open');
        if ($('#searchDrawer').hasClass('open')) setTimeout(function(){ $('#searchInput').focus(); }, 200);
    });
    $('#closeSearchDrawer').on('click', function() { $('#searchDrawer').removeClass('open'); });
    <?php if (!empty($search)): ?>$('#searchDrawer').addClass('open');<?php endif; ?>

    $('#settingsToggle').on('click', function(e) {
        e.stopPropagation();
        $('#settingsMenu').toggleClass('open');
    });

    $('#openFiltersBtn').on('click', function(e) {
        e.preventDefault();
        $('#settingsMenu').removeClass('open');
        $('#columnsDrawer').removeClass('open');
        $('#filtersDrawer').toggleClass('open');
    });

    $('#openColumnsBtn').on('click', function(e) {
        e.preventDefault();
        $('#settingsMenu').removeClass('open');
        $('#filtersDrawer').removeClass('open');
        $('#columnsDrawer').toggleClass('open');
    });

    $('#closeFiltersDrawer').on('click', function() { $('#filtersDrawer').removeClass('open'); });
    $('#closeColumnsDrawer').on('click', function() { $('#columnsDrawer').removeClass('open'); });

    $('#selectAllFilterBoxes').on('click', function(e) { e.preventDefault(); $('#filtersDrawer input[name="visible_filters[]"]').prop('checked', true); });
    $('#clearAllFilterBoxes').on('click', function(e) { e.preventDefault(); $('#filtersDrawer input[name="visible_filters[]"]').prop('checked', false); });

    $('#selectAllColBoxes').on('click', function(e) { e.preventDefault(); $('#columnsDrawer input[name="visible_columns[]"]').prop('checked', true); });
    $('#clearAllColBoxes').on('click', function(e) { e.preventDefault(); $('#columnsDrawer input[name="visible_columns[]"]').prop('checked', false); });

    $('#exportToggle').on('click', function(e) { e.stopPropagation(); $('#exportMenu').toggleClass('open'); });

    $(document).on('click', function(e) {
        if (!$(e.target).closest('#settingsWrap').length) $('#settingsMenu').removeClass('open');
        if (!$(e.target).closest('.export-wrap').length) $('#exportMenu').removeClass('open');
    });

    function exportRows(onlySelected) {
        return onlySelected
            ? studentsData.filter(function(s) { return $('.row-checkbox[value="' + s.id + '"]').is(':checked'); })
            : studentsData;
    }

    function getCellExportValue(r, c) {
        switch (c) {
            case 'is_transport':
            case 'is_old_dues':
            case 'is_admission':
                return (parseInt(r[c]) === 1) ? 'Yes' : 'No';
            case 'status':
                return (parseInt(r.status) === 1) ? 'Leave' : (parseInt(r.status) === 2 ? 'Struck Off' : 'Active');
            default:
                var v = r[c];
                return (v === null || v === undefined) ? '' : v;
        }
    }

    function buildExportMatrix(rows) {
        var headers = [];
        visibleColumns.forEach(function(c) { headers.push(allColumnLabels[c] || c); });
        var data = [headers];
        rows.forEach(function(r) {
            var line = [];
            visibleColumns.forEach(function(c) { line.push(getCellExportValue(r, c)); });
            data.push(line);
        });
        return data;
    }

    $('#exportExcel').on('click', function(e) {
        e.preventDefault();
        var ws = XLSX.utils.aoa_to_sheet(buildExportMatrix(exportRows(false)));
        var wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Students');
        XLSX.writeFile(wb, 'student_list_' + new Date().toISOString().slice(0,10) + '.xlsx');
        $('#exportMenu').removeClass('open');
    });

    $('#exportCsv').on('click', function(e) {
        e.preventDefault();
        var csv = buildExportMatrix(exportRows(false)).map(function(r) {
            return r.map(function(c) {
                var v = String(c == null ? '' : c);
                if (v.indexOf(',') !== -1 || v.indexOf('"') !== -1 || v.indexOf('\n') !== -1) v = '"' + v.replace(/"/g, '""') + '"';
                return v;
            }).join(',');
        }).join('\n');
        var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'student_list_' + new Date().toISOString().slice(0,10) + '.csv';
        a.click();
        URL.revokeObjectURL(url);
        $('#exportMenu').removeClass('open');
    });

    $('#exportPrint').on('click', function(e) {
        e.preventDefault();
        window.open('print_student_list.php?' + new URLSearchParams(window.location.search).toString(), '_blank');
        $('#exportMenu').removeClass('open');
    });

    $('#exportCopy').on('click', function(e) {
        e.preventDefault();
        var tsv = buildExportMatrix(exportRows(false)).map(function(r) { return r.join('\t'); }).join('\n');
        navigator.clipboard.writeText(tsv).then(function() { alert('Table copied to clipboard.'); });
        $('#exportMenu').removeClass('open');
    });

    function refreshBulkBar() {
        var n = $('.row-checkbox:checked').length;
        $('#bulkCount').text(n);
        $('#bulkBar').toggleClass('open', n > 0);
    }

    $('#selectAll').on('change', function() {
        var checked = $(this).is(':checked');
        $('.row-checkbox').prop('checked', checked).each(function() {
            $(this).closest('tr').toggleClass('selected', checked);
        });
        refreshBulkBar();
    });

    $(document).on('change', '.row-checkbox', function() {
        $(this).closest('tr').toggleClass('selected', $(this).is(':checked'));
        var total = $('.row-checkbox').length;
        var checked = $('.row-checkbox:checked').length;
        $('#selectAll').prop('checked', total > 0 && total === checked);
        $('#selectAll').prop('indeterminate', checked > 0 && checked < total);
        refreshBulkBar();
    });

    $('#bulkClear').on('click', function() {
        $('#selectAll').prop('checked', false).prop('indeterminate', false);
        $('.row-checkbox').prop('checked', false).each(function() { $(this).closest('tr').removeClass('selected'); });
        refreshBulkBar();
    });

    $('#bulkStatusForm').on('submit', function(e) {
        if (!$('#bulkStatusSelect').val()) { e.preventDefault(); alert('Pick a status first.'); return; }
        $('.row-checkbox:checked').each(function() {
            $('<input>').attr({ type: 'hidden', name: 'student_ids[]', value: this.value }).appendTo('#bulkStatusForm');
        });
    });

    $('#bulkDeleteForm').on('submit', function() {
        $('.row-checkbox:checked').each(function() {
            $('<input>').attr({ type: 'hidden', name: 'student_ids[]', value: this.value }).appendTo('#bulkDeleteForm');
        });
    });

    $('#bulkEditBtn').on('click', function() {
        var ids = $('.row-checkbox:checked').map(function(){ return this.value; }).get();
        if (ids.length === 0) { alert('Select a student first.'); return; }
        if (ids.length > 1) { alert('Edit works on a single student.'); return; }
        window.location.href = 'student_registration.php?edit=' + ids[0];
    });

    $('#bulkFeeBtn').on('click', function() {
        var ids = $('.row-checkbox:checked').map(function(){ return this.value; }).get();
        if (ids.length === 0) { alert('Select a student first.'); return; }
        if (ids.length > 1) { alert('Collect Fee works on a single student.'); return; }
        window.location.href = 'fee_collection.php?id=' + ids[0];
    });

    $('#bulkPrintRegBtn').on('click', function() {
        var ids = $('.row-checkbox:checked').map(function(){ return this.value; }).get();
        if (ids.length === 0) { alert('Select at least one student.'); return; }
        window.open('print_student_registration_batch.php?ids=' + ids.join(','), '_blank');
    });

    $('#bulkExportBtn').on('click', function() {
        var ids = $('.row-checkbox:checked').map(function(){ return this.value; }).get();
        if (ids.length === 0) { alert('Select at least one student.'); return; }
        var rows = studentsData.filter(function(s) { return ids.indexOf(String(s.id)) !== -1; });
        var ws = XLSX.utils.aoa_to_sheet(buildExportMatrix(rows));
        var wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Selected');
        XLSX.writeFile(wb, 'students_selected_' + new Date().toISOString().slice(0,10) + '.xlsx');
    });

    $('#filterForm').on('submit', function() {
        var combo = $('#class_combo_filter').val();
        if (combo) {
            var parts = combo.split('|');
            $('#class_filter_hidden').val(parts[0] || 0);
            $('#class_section_filter_hidden').val(parts[1] || 0);
        }
    });

    $('#class_combo_filter, #village_filter, #status_filter').change(function() { $('#filterForm').submit(); });

    // GENDER selectbox auto-submit
    $(document).on('change', '#gender_filter', function() { $('#filterForm').submit(); });

    // DYNAMIC DEBOUNCE for text filters only
    var filterDebounce = null;
    $(document).on('keyup', '.filter-row input[type="text"]', function(e) {
        if (e.key === 'Enter') { $('#filterForm').submit(); return; }
        clearTimeout(filterDebounce);
        filterDebounce = setTimeout(function() { $('#filterForm').submit(); }, 600);
    });
});
</script>
</body>
</html>
<?php $conn->close(); ?>