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
        'sr_no' => '#',
        'actions' => 'Actions',
        'edit' => 'Edit',
        'print_registration_form' => 'Print',
        'print_list' => 'Print List',
        'collect_fee' => 'Collect Fee',
        'father_name_label' => 'Father',
        'mobile_label' => 'Mobile',
        'reg_no_label' => 'Reg No',
        'admission_no_label' => 'Adm No',
        'roll_no_label' => 'Roll No',
        'class_label' => 'Class',
        'section_label' => 'Sec',
        'session_label' => 'Session',
        'village_label' => 'VC',
        'search_placeholder' => 'Search by name, father, mobile, reg no, ID...',
        'add_new_student' => 'Add New Student',
        'delete_confirm' => 'Are you sure you want to delete this student record?',
        'delete_success' => 'Student deleted successfully!',
        'error' => 'Error: ',
        'filter_by_class' => 'Class',
        'filter_by_village' => 'Village Council',
        'filter_by_status' => 'Status',
        'all_classes' => 'All Classes',
        'all_villages' => 'All Village Councils',
        'all_status' => 'All Students',
        'status_active' => 'Active',
        'status_leave' => 'Leave',
        'status_struck_off' => 'Struck Off',
        'active_students' => 'Active',
        'status' => 'Status',
        'update_status' => 'Update Status',
        'select_new_status' => 'Select New Status',
        'status_updated' => 'Student status updated successfully!',
        'status_update_confirm' => 'Change this student\'s status?',
        'status_update_error' => 'Error updating status: ',
        'change_status' => 'Change Status',
        'current_status' => 'Current Status',
        'reset_button' => 'Reset',
        'search_button' => 'Search',
    ]
];

// Handle status update
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

    $params = [];
    foreach (['search','class_filter','village_filter','status_filter'] as $k) {
        if (!empty($_GET[$k])) $params[$k] = $_GET[$k];
    }
    header("Location: student_list.php" . (!empty($params) ? '?' . http_build_query($params) : ''));
    exit();
}

// Handle delete
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

// Filters
$classes = [];
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

$search            = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$class_filter      = isset($_GET['class_filter']) ? intval($_GET['class_filter']) : 0;
$class_section_filter = isset($_GET['class_section_filter']) ? intval($_GET['class_section_filter']) : 0;
$village_filter    = isset($_GET['village_filter']) ? intval($_GET['village_filter']) : 0;
$status_filter     = isset($_GET['status_filter']) ? $_GET['status_filter'] : '0';

$where = [];
if (!empty($search)) {
    $where[] = "(
        sr.name LIKE '%$search%' OR
        sr.father_name LIKE '%$search%' OR
        sr.mobile LIKE '%$search%' OR
        sr.reg_no LIKE '%$search%' OR
        sr.admission_no LIKE '%$search%' OR
        sr.roll_no LIKE '%$search%' OR
        sr.guardian_name LIKE '%$search%' OR
        sr.guardian_mobile LIKE '%$search%' OR
        sr.id LIKE '%$search%'
    )";
}
if ($class_filter > 0)         $where[] = "sc.class_id = $class_filter";
if ($class_section_filter > 0) $where[] = "sc.class_section_id = $class_section_filter";
if ($village_filter > 0)       $where[] = "sr.village_council_id = $village_filter";
if ($status_filter !== 'all')  $where[] = "sr.status = '$status_filter'";
$where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$query = "
    SELECT
        sr.id, sr.name, sr.father_name, sr.mobile, sr.reg_no,
        sr.admission_no, sr.roll_no,
        sr.guardian_name, sr.guardian_mobile, sr.guardian_cnic,
        sr.village_council_id, sr.status,
        c.title  AS class_title,
        s.title  AS section_title,
        ses.title AS session_title,
        vc.title AS village_council_title
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
    $where_clause
    ORDER BY sr.id DESC
";
$result = $conn->query($query);
$total_students = $result ? $result->num_rows : 0;

$js_translations = [
    'delete_confirm' => $translations[$lang]['delete_confirm'],
    'status_update_confirm' => $translations[$lang]['status_update_confirm'],
    'status_active' => $translations[$lang]['status_active'],
    'status_leave' => $translations[$lang]['status_leave'],
    'status_struck_off' => $translations[$lang]['status_struck_off']
];
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
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script>
var lang = <?php echo json_encode($js_translations, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
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

    /* ── Search icon + slide-down search bar ── */
    .search-toggle-btn {
        display: inline-flex; align-items: center; gap: 8px;
        background: rgba(255,255,255,0.18); color: #fff;
        border: 1px solid rgba(255,255,255,0.3);
        padding: 7px 14px; border-radius: 8px;
        font-size: 13px; font-weight: 600; font-family: inherit;
        cursor: pointer; line-height: 1;
        transition: all 0.15s;
    }
    .search-toggle-btn:hover { background: #fff; color: #1e40af; border-color: #fff; }
    .header-btn {
        display: inline-flex; align-items: center; gap: 8px;
        background: #ffffff; color: #1e40af;
        border: 1px solid #ffffff;
        padding: 7px 14px; border-radius: 8px;
        font-size: 13px; font-weight: 600;
        cursor: pointer; line-height: 1;
        text-decoration: none;
        transition: all 0.15s;
    }
    .header-btn:hover { background: #dbeafe; border-color: #dbeafe; color: #1e3a8a; text-decoration: none; }

    .search-drawer {
        max-height: 0; overflow: hidden;
        transition: max-height 0.25s ease;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
    }
    .search-drawer.open { max-height: 120px; }
    .search-drawer .inner { padding: 14px 18px; display: flex; gap: 10px; flex-wrap: wrap; }
    .search-drawer input {
        flex: 1; min-width: 220px;
        height: 42px; padding: 0 14px;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 14px; font-family: inherit;
    }
    .search-drawer input:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 3px rgba(30,64,175,0.12); }
    .search-drawer button {
        height: 42px; padding: 0 20px;
        background: #1e40af; color: #fff;
        border: 1px solid #1e40af; border-radius: 8px;
        font-size: 13px; font-weight: 700;
        cursor: pointer; line-height: 1;
    }
    .search-drawer button:hover { background: #1e3a8a; border-color: #1e3a8a; }

    /* ── Filter row ── */
    .filter-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 12px; padding: 14px 18px;
        background: #fff; border-bottom: 1px solid #e2e8f0;
    }
    .filter-row .field label {
        display: block; font-size: 11px; font-weight: 700;
        color: #64748b; text-transform: uppercase;
        letter-spacing: 0.8px; margin-bottom: 5px;
    }
    .filter-row select {
        width: 100%; height: 40px; padding: 0 12px;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 13px; background: #fff;
        -webkit-appearance: none; appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L2 4h8z'/%3E%3C/svg%3E");
        background-repeat: no-repeat; background-position: right 12px center;
        padding-right: 32px;
    }
    .filter-row select:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 3px rgba(30,64,175,0.12); }

    /* ── Results bar ── */
    .results-bar {
        display: flex; justify-content: space-between; align-items: center;
        padding: 10px 18px; background: #eff6ff;
        border-bottom: 1px solid #bfdbfe;
        font-size: 13px; color: #1e3a8a;
    }
    .results-bar .badge-filter {
        background: #1e40af; color: #fff;
        padding: 3px 10px; border-radius: 12px;
        font-size: 11px; font-weight: 600; margin-left: 6px;
    }

    /* ── Excel table ── */
    .excel-wrap {
        overflow: auto;
        max-height: 72vh;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        background: #fff;
    }
    table.excel {
        width: 100%; border-collapse: separate; border-spacing: 0;
        min-width: 1200px;
    }
    table.excel thead th {
        position: sticky; top: 0; z-index: 3;
        background: #f1f5f9;
        color: #334155;
        font-size: 11px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.7px;
        padding: 10px 12px;
        text-align: left;
        border-bottom: 2px solid #cbd5e1;
        border-right: 1px solid #e2e8f0;
        white-space: nowrap;
    }
    table.excel thead th:last-child { border-right: none; }
    table.excel tbody td {
        padding: 8px 12px;
        font-size: 12px; color: #1e293b;
        border-bottom: 1px solid #f1f5f9;
        border-right: 1px solid #f1f5f9;
        white-space: nowrap;
        vertical-align: middle;
    }
    table.excel tbody td:last-child { border-right: none; }
    table.excel tbody tr:nth-child(even) td { background: #f8fafc; }
    table.excel tbody tr:hover td { background: #eff6ff; }

    /* Sticky first column (#) and second column (ID) */
    table.excel thead th.sticky-col,
    table.excel tbody td.sticky-col {
        position: sticky; left: 0; z-index: 2;
        background: #fff;
        box-shadow: 2px 0 4px rgba(0,0,0,0.04);
    }
    table.excel thead th.sticky-col { z-index: 4; background: #f1f5f9; }
    table.excel tbody tr:nth-child(even) td.sticky-col { background: #f8fafc; }
    table.excel tbody tr:hover td.sticky-col { background: #eff6ff; }

    .id-cell { font-weight: 700; color: #1e40af; font-family: 'SF Mono', 'Monaco', monospace; }
    .name-cell { font-weight: 600; color: #0f172a; }
    .num-cell { text-align: right; font-variant-numeric: tabular-nums; }
    .center-cell { text-align: center; }

    /* Status pills */
    .status-pill {
        display: inline-block; padding: 3px 10px;
        border-radius: 12px; font-size: 10px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.4px;
    }
    .status-pill.active { background: #d1fae5; color: #065f46; }
    .status-pill.leave  { background: #fef3c7; color: #92400e; }
    .status-pill.struck { background: #fee2e2; color: #991b1b; }

    /* Row action buttons */
    .row-btn {
        display: inline-flex; align-items: center; justify-content: center;
        width: 30px; height: 30px;
        border-radius: 6px; border: 1px solid;
        font-size: 12px; cursor: pointer;
        text-decoration: none; font-family: inherit;
        transition: all 0.12s;
        margin: 0 2px;
    }
    .row-btn-edit    { background: #eff6ff; color: #1e40af; border-color: #bfdbfe; }
    .row-btn-edit:hover    { background: #dbeafe; color: #1e40af; text-decoration: none; }
    .row-btn-print   { background: #f0f9ff; color: #0369a1; border-color: #bae6fd; }
    .row-btn-print:hover   { background: #e0f2fe; color: #0369a1; text-decoration: none; }
    .row-btn-fee     { background: #d1fae5; color: #065f46; border-color: #a7f3d0; }
    .row-btn-fee:hover     { background: #a7f3d0; color: #065f46; text-decoration: none; }
    .row-btn-status  { background: #fef3c7; color: #92400e; border-color: #fde68a; }
    .row-btn-status:hover  { background: #fde68a; color: #92400e; }
    .row-btn-delete  { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
    .row-btn-delete:hover  { background: #fee2e2; color: #991b1b; text-decoration: none; }

    /* Status modal */
    .modal-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; border-bottom: none; }
    .modal-header .close { color: #fff; opacity: 0.8; }
    .status-option { padding: 10px 14px; margin-bottom: 8px; border: 1px solid #e2e8f0; border-radius: 8px; cursor: pointer; transition: all 0.15s; }
    .status-option:hover { background: #f8fafc; border-color: #667eea; }
    .status-option input[type="radio"] { margin-right: 10px; }
    .current-status-info { background: #f1f5f9; padding: 10px 14px; border-radius: 6px; margin-bottom: 14px; font-size: 13px; }

    /* Empty state */
    .empty-state { padding: 50px 20px; text-align: center; color: #94a3b8; }
    .empty-state .big { font-size: 44px; opacity: 0.4; margin-bottom: 10px; }
</style>
</head>
<body>
<?php require_once('navbar.php'); ?>

<div class="container-fluid" style="padding: 20px; max-width: 1600px; margin: 0 auto;">

    <div class="text-right" style="margin-bottom: 14px;">
        <?php if (!empty($perms['can_add'])): ?>
        <a href="student_registration.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> <?php echo $translations[$lang]['add_new_student']; ?>
        </a>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="panel-heading">
            <h3 class="panel-title"><?php echo $translations[$lang]['list_title']; ?> (<?php echo $total_students; ?>)</h3>
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <button type="button" class="search-toggle-btn" id="searchToggle">
                    <i class="fas fa-search"></i> Search
                </button>
                <a href="print_student_list.php?<?php echo http_build_query($_GET); ?>" class="header-btn" target="_blank">
                    <i class="fas fa-print"></i> Print
                </a>
            </div>
        </div>

        <!-- Search drawer -->
        <div class="search-drawer" id="searchDrawer">
            <form method="GET" action="" class="inner">
                <?php foreach (['class_filter','class_section_filter','village_filter','status_filter'] as $k): ?>
                    <?php if (!empty($_GET[$k])): ?>
                        <input type="hidden" name="<?php echo $k; ?>" value="<?php echo htmlspecialchars($_GET[$k]); ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <input type="text" name="search" id="searchInput"
                    placeholder="<?php echo $translations[$lang]['search_placeholder']; ?>"
                    value="<?php echo htmlspecialchars($search); ?>"
                    autocomplete="off">
                <button type="submit"><i class="fas fa-search"></i> Search</button>
                <?php if ($search): ?>
                    <a href="?<?php echo http_build_query(array_filter(['class_filter'=>$_GET['class_filter'] ?? null,'village_filter'=>$_GET['village_filter'] ?? null,'status_filter'=>$_GET['status_filter'] ?? null])); ?>"
                       class="btn btn-outline-secondary" style="height:42px; padding:0 18px; line-height:42px; border:1px solid #cbd5e1; border-radius:8px; color:#334155; text-decoration:none;">
                        Clear
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Filters -->
        <form method="GET" action="" id="filterForm">
            <?php if (!empty($search)): ?>
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
            <?php endif; ?>
            <div class="filter-row">
                <div class="field">
                    <label><i class="fas fa-graduation-cap"></i> <?php echo $translations[$lang]['filter_by_class']; ?></label>
                    <select name="class_combo_filter" id="class_combo_filter">
                        <option value="0|0"><?php echo $translations[$lang]['all_classes']; ?></option>
                        <?php foreach ($class_options as $opt):
                            $val = $opt['class_id'] . '|' . $opt['class_section_id'];
                            $sel = ((int)$class_filter === (int)$opt['class_id']
                                 && (int)$class_section_filter === (int)$opt['class_section_id']);
                        ?>
                            <option value="<?php echo $val; ?>" <?php echo $sel ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($opt['display_label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="class_filter" id="class_filter_hidden" value="<?php echo (int)$class_filter; ?>">
                    <input type="hidden" name="class_section_filter" id="class_section_filter_hidden" value="<?php echo (int)$class_section_filter; ?>">
                </div>
                <div class="field">
                    <label><i class="fas fa-map-marker-alt"></i> <?php echo $translations[$lang]['filter_by_village']; ?></label>
                    <select name="village_filter" id="village_filter">
                        <option value="0"><?php echo $translations[$lang]['all_villages']; ?></option>
                        <?php foreach ($village_councils as $id => $name): ?>
                            <option value="<?php echo $id; ?>" <?php echo ($village_filter == $id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label><i class="fas fa-flag"></i> <?php echo $translations[$lang]['filter_by_status']; ?></label>
                    <select name="status_filter" id="status_filter">
                        <option value="0"   <?php echo ($status_filter == '0')   ? 'selected' : ''; ?>><?php echo $translations[$lang]['active_students']; ?></option>
                        <option value="1"   <?php echo ($status_filter == '1')   ? 'selected' : ''; ?>><?php echo $translations[$lang]['status_leave']; ?></option>
                        <option value="2"   <?php echo ($status_filter == '2')   ? 'selected' : ''; ?>><?php echo $translations[$lang]['status_struck_off']; ?></option>
                        <option value="all" <?php echo ($status_filter == 'all') ? 'selected' : ''; ?>><?php echo $translations[$lang]['all_status']; ?></option>
                    </select>
                </div>
            </div>
        </form>

        <!-- Results bar -->
        <?php if ($search || $class_filter > 0 || $class_section_filter > 0 || $village_filter > 0 || $status_filter != '0'): ?>
        <div class="results-bar">
            <div>
                <i class="fas fa-filter"></i> Showing <strong><?php echo $total_students; ?></strong> result(s)
                <?php if ($search): ?><span class="badge-filter">Search: "<?php echo htmlspecialchars($search); ?>"</span><?php endif; ?>
                <?php if ($village_filter > 0 && isset($village_councils[$village_filter])): ?>
                    <span class="badge-filter">VC: <?php echo htmlspecialchars($village_councils[$village_filter]); ?></span>
                <?php endif; ?>
            </div>
            <a href="student_list.php" class="btn btn-sm btn-warning">Reset</a>
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

            <?php if ($result && $result->num_rows > 0): ?>
            <div class="excel-wrap">
                <table class="excel">
                    <thead>
                        <tr>
                            <th class="sticky-col" style="width:50px;">#</th>
                            <th style="width:70px;">ID</th>
                            <th style="width:110px;">Adm No</th>
                            <th style="width:90px;">Roll No</th>
                            <th style="width:200px;">Student Name</th>
                            <th style="width:180px;">Father</th>
                            <th style="width:130px;">Mobile</th>
                            <th style="width:160px;">Class</th>
                            <th style="width:70px;">Sec</th>
                            <th style="width:150px;">Session</th>
                            <th style="width:160px;">Village Council</th>
                            <th style="width:90px;">Reg No</th>
                            <th style="width:100px;" class="center-cell">Status</th>
                            <?php if (!empty($perms['can_edit']) || !empty($perms['can_delete']) || !empty($perms['can_add'])): ?>
                            <th style="width:210px;" class="center-cell">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $count = 1; while ($row = $result->fetch_assoc()):
                            $status_class = 'active';
                            $status_text  = $translations[$lang]['status_active'];
                            if ($row['status'] == 1) { $status_class = 'leave';  $status_text = $translations[$lang]['status_leave']; }
                            elseif ($row['status'] == 2) { $status_class = 'struck'; $status_text = $translations[$lang]['status_struck_off']; }
                        ?>
                        <tr>
                            <td class="sticky-col center-cell" style="color:#64748b;"><?php echo $count++; ?></td>
                            <td class="id-cell"><?php echo (int)$row['id']; ?></td>
                            <td><?php echo htmlspecialchars($row['admission_no'] ?: '—'); ?></td>
                            <td><?php echo htmlspecialchars($row['roll_no'] ?: '—'); ?></td>
                            <td class="name-cell"><?php echo htmlspecialchars($row['name']); ?></td>
                            <td><?php echo htmlspecialchars($row['father_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['mobile']); ?></td>
                            <td><?php echo htmlspecialchars($row['class_title'] ?? '—'); ?></td>
                            <td class="center-cell"><?php echo htmlspecialchars($row['section_title'] ?: '—'); ?></td>
                            <td><?php echo htmlspecialchars($row['session_title'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($row['village_council_title'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($row['reg_no']); ?></td>
                            <td class="center-cell">
                                <span class="status-pill <?php echo $status_class; ?>" id="status-badge-<?php echo $row['id']; ?>">
                                    <?php echo $status_text; ?>
                                </span>
                            </td>
                            <?php if (!empty($perms['can_edit']) || !empty($perms['can_delete']) || !empty($perms['can_add'])): ?>
                            <td class="center-cell">
                                <?php if (!empty($perms['can_edit'])): ?>
                                <a href="student_registration.php?edit=<?php echo $row['id']; ?>" class="row-btn row-btn-edit" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                                <a href="student_registration_print.php?id=<?php echo $row['id']; ?>" class="row-btn row-btn-print" target="_blank" title="Print Registration">
                                    <i class="fas fa-print"></i>
                                </a>
                                <a href="fee_collection.php?id=<?php echo $row['id']; ?>" class="row-btn row-btn-fee" title="Collect Fee">
                                    <i class="fas fa-money-bill-wave"></i>
                                </a>
                                <button type="button"
                                    class="row-btn row-btn-status status-update-btn"
                                    data-student-id="<?php echo $row['id']; ?>"
                                    data-student-name="<?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-student-status="<?php echo $row['status']; ?>"
                                    title="Change Status">
                                    <i class="fas fa-exchange-alt"></i>
                                </button>
                                <?php if (!empty($perms['can_delete'])): ?>
                                <a href="?delete=<?php echo $row['id']; ?>" class="row-btn row-btn-delete delete-btn" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </a>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="big">📋</div>
                    <h4><?php echo $translations[$lang]['no_records']; ?></h4>
                    <?php if ($search || $class_filter > 0 || $village_filter > 0 || $status_filter != '0'): ?>
                        <p style="margin-top: 10px;"><a href="student_list.php" class="btn btn-info">Clear Filters</a></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Status Update Modal -->
<div class="modal fade" id="statusModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-exchange-alt"></i> <?php echo $translations[$lang]['update_status']; ?></h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form method="POST" action="" id="statusForm">
                <div class="modal-body">
                    <input type="hidden" name="update_status" value="1">
                    <input type="hidden" name="student_id" id="modal_student_id" value="">
                    <div class="current-status-info">
                        <strong><?php echo $translations[$lang]['current_status']; ?>:</strong>
                        <span id="current_status_text"></span>
                    </div>
                    <div class="form-group">
                        <label><strong>Student:</strong></label>
                        <p class="form-control-static" id="modal_student_name" style="margin:0;"></p>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label><strong><?php echo $translations[$lang]['select_new_status']; ?>:</strong></label>
                        <div class="status-option" onclick="selectStatus(0)">
                            <label style="margin:0; cursor:pointer;">
                                <input type="radio" name="new_status" value="0">
                                <span class="status-pill active"><?php echo $translations[$lang]['status_active']; ?></span>
                            </label>
                        </div>
                        <div class="status-option" onclick="selectStatus(1)">
                            <label style="margin:0; cursor:pointer;">
                                <input type="radio" name="new_status" value="1">
                                <span class="status-pill leave"><?php echo $translations[$lang]['status_leave']; ?></span>
                            </label>
                        </div>
                        <div class="status-option" onclick="selectStatus(2)">
                            <label style="margin:0; cursor:pointer;">
                                <input type="radio" name="new_status" value="2">
                                <span class="status-pill struck"><?php echo $translations[$lang]['status_struck_off']; ?></span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="updateStatusBtn">Update Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('[data-toggle="tooltip"]').tooltip();

    // Search drawer toggle
    $('#searchToggle').on('click', function() {
        $('#searchDrawer').toggleClass('open');
        if ($('#searchDrawer').hasClass('open')) {
            setTimeout(function() { $('#searchInput').focus(); }, 200);
        }
    });
    <?php if (!empty($search)): ?>
    $('#searchDrawer').addClass('open');
    <?php endif; ?>

    // Combine class combo into hidden fields on submit
    $('#filterForm').on('submit', function() {
        var val = $('#class_combo_filter').val() || '0|0';
        var parts = val.split('|');
        $('#class_filter_hidden').val(parts[0] || 0);
        $('#class_section_filter_hidden').val(parts[1] || 0);
    });

    // Auto-submit on filter change
    $('#class_combo_filter, #village_filter, #status_filter').change(function() {
        $('#filterForm').submit();
    });

    // Status update modal
    $(document).on('click', '.status-update-btn', function(e) {
        e.preventDefault();
        var studentId = $(this).data('student-id');
        var studentName = $(this).data('student-name');
        var studentStatus = $(this).data('student-status');

        $('#modal_student_id').val(studentId);
        $('#modal_student_name').text(studentName);

        var statusText = '';
        switch (parseInt(studentStatus)) {
            case 0: statusText = lang.status_active; break;
            case 1: statusText = lang.status_leave; break;
            case 2: statusText = lang.status_struck_off; break;
            default: statusText = 'Unknown';
        }
        $('#current_status_text').text(statusText);
        $('input[name="new_status"]').prop('checked', false);
        $('input[name="new_status"][value="' + studentStatus + '"]').prop('checked', true);
        $('#statusModal').modal('show');
    });

    // Delete confirmation
    $(document).on('click', '.delete-btn', function(e) {
        if (!confirm(lang.delete_confirm)) { e.preventDefault(); return false; }
    });

    // Status update confirmation
    $('#updateStatusBtn').on('click', function(e) {
        var selectedStatus = $('input[name="new_status"]:checked').val();
        if (selectedStatus === undefined) {
            e.preventDefault();
            alert('Please select a status');
            return false;
        }
        if (!confirm(lang.status_update_confirm)) {
            e.preventDefault();
            return false;
        }
    });
});

function selectStatus(value) {
    $('input[name="new_status"]').prop('checked', false);
    $('input[name="new_status"][value="' + value + '"]').prop('checked', true);
}
</script>
</body>
</html>
<?php $conn->close(); ?>