<?php
header('Content-Type: text/html; charset=utf-8');
require_once('security.php');
/* ==== AUTHORIZATION GATE ==== */
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
/* ==== END AUTHORIZATION GATE ==== */
require_once('conn_inc.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$lang = 'en';

$translations = [
    'en' => [
        'title' => 'Add New Town',
        'heading' => 'Towns',
        'subtitle' => 'Manage towns / village councils',
        'title_label' => 'Town Title',
        'title_placeholder' => 'Enter town title',
        'submit' => 'Add Town',
        'reset' => 'Reset',
        'success' => 'Town added successfully!',
        'update_success' => 'Town updated successfully!',
        'error' => 'Error: ',
        'empty_error' => 'Title cannot be empty!',
        'list_title' => 'Towns List',
        'no_records' => 'No towns found.',
        'no_match' => 'No towns match your search.',
        'sr_no' => '#',
        'actions' => 'Actions',
        'edit' => 'Edit',
        'save' => 'Save',
        'cancel' => 'Cancel',
    ]
];

/* ═══════════ HANDLE ADD ═══════════ */
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['title'])) {
    $title = trim($_POST['title']);
    if (!empty($title)) {
        $stmt = $conn->prepare("INSERT INTO village_councils (title) VALUES (?)");
        $stmt->bind_param("s", $title);
        if ($stmt->execute()) {
            $_SESSION['message'] = $translations[$lang]['success'];
            $_SESSION['message_type'] = "success";
            // Keep the search/sort/page the user was on
            $back = $_SERVER['PHP_SELF'];
            $qs = [];
            if (!empty($_POST['return_q']))       $qs['q']    = $_POST['return_q'];
            if (!empty($_POST['return_sort']))    $qs['sort'] = $_POST['return_sort'];
            if (!empty($_POST['return_page']))    $qs['page'] = $_POST['return_page'];
            if (!empty($qs)) $back .= '?' . http_build_query($qs);
            header("Location: " . $back);
            exit();
        } else {
            $_SESSION['message'] = $translations[$lang]['error'] . $stmt->error;
            $_SESSION['message_type'] = "danger";
        }
        $stmt->close();
    } else {
        $_SESSION['message'] = $translations[$lang]['empty_error'];
        $_SESSION['message_type'] = "danger";
    }
}

/* ═══════════ HANDLE DELETE ═══════════ */
if (isset($_GET['delete']) && !empty($perms['can_delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM village_councils WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $_SESSION['message'] = 'Town deleted successfully!';
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = $translations[$lang]['error'] . $stmt->error;
        $_SESSION['message_type'] = "danger";
    }
    $stmt->close();

    $back = $_SERVER['PHP_SELF'];
    $qs = [];
    if (!empty($_GET['q']))    $qs['q']    = $_GET['q'];
    if (!empty($_GET['sort'])) $qs['sort'] = $_GET['sort'];
    if (!empty($_GET['page'])) $qs['page'] = $_GET['page'];
    if (!empty($qs)) $back .= '?' . http_build_query($qs);
    header("Location: " . $back);
    exit();
}

/* ═══════════ FILTERS / PAGINATION ═══════════ */
$q        = isset($_GET['q']) ? trim($_GET['q']) : '';
$sort     = isset($_GET['sort']) ? trim($_GET['sort']) : 'az';
$page     = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset   = ($page - 1) * $per_page;

/* Whitelist sort */
$order_sql = "ORDER BY title ASC";
if ($sort === 'za')     $order_sql = "ORDER BY title DESC";
if ($sort === 'recent') $order_sql = "ORDER BY id DESC";
if ($sort === 'oldest') $order_sql = "ORDER BY id ASC";

/* Build where */
$where_sql = '';
$params = [];
$types = '';
if ($q !== '') {
    $where_sql = "WHERE title LIKE ?";
    $like = '%' . $q . '%';
    $params[] = $like;
    $types .= 's';
}

/* Total count */
$total_towns = 0;
$count_sql = "SELECT COUNT(*) AS c FROM village_councils $where_sql";
if ($stmt = $conn->prepare($count_sql)) {
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $r = $stmt->get_result();
    if ($r && ($row = $r->fetch_assoc())) $total_towns = (int)$row['c'];
    $stmt->close();
}

$total_pages = max(1, (int)ceil($total_towns / $per_page));
if ($page > $total_pages) { $page = $total_pages; $offset = ($page - 1) * $per_page; }

/* Fetch page */
$towns = [];
$fetch_sql = "SELECT id, title FROM village_councils $where_sql $order_sql LIMIT ? OFFSET ?";
if ($stmt = $conn->prepare($fetch_sql)) {
    $bind_types = $types . 'ii';
    $bind_params = $params;
    $bind_params[] = $per_page;
    $bind_params[] = $offset;
    $stmt->bind_param($bind_types, ...$bind_params);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($r && ($row = $r->fetch_assoc())) $towns[] = $row;
    $stmt->close();
}

/* Total towns in DB (unfiltered) — for the header "X total" pill */
$grand_total = 0;
$r = $conn->query("SELECT COUNT(*) AS c FROM village_councils");
if ($r && ($row = $r->fetch_assoc())) $grand_total = (int)$row['c'];

/* Build a querystring helper (preserves current filters) */
function qs_with($overrides = []) {
    $base = [
        'q'    => isset($_GET['q']) ? $_GET['q'] : '',
        'sort' => isset($_GET['sort']) ? $_GET['sort'] : 'az',
        'page' => isset($_GET['page']) ? $_GET['page'] : 1,
    ];
    foreach ($overrides as $k => $v) $base[$k] = $v;
    $clean = [];
    foreach ($base as $k => $v) {
        if ($v === '' || $v === null) continue;
        $clean[$k] = $v;
    }
    return '?' . http_build_query($clean);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($translations[$lang]['heading']) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<style>
    * { box-sizing: border-box; }
    body { background: #eef1f5; font-family: 'Inter', sans-serif; color: #1e293b; margin: 0; padding: 0; }
    .wrap { max-width: 1000px; margin: 30px auto; padding: 0 20px; }

    /* Header */
    .page-head {
        background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
        color: #fff; padding: 26px 32px;
        border-radius: 12px 12px 0 0;
        display: flex; align-items: center; gap: 16px;
    }
    .page-head .head-icon {
        width: 52px; height: 52px;
        background: rgba(255,255,255,0.15);
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 22px;
    }
    .page-head h1 { font-size: 24px; font-weight: 700; margin: 0; }
    .page-head .sub { font-size: 13px; opacity: 0.9; margin-top: 5px; }

    /* Card */
    .card {
        background: #fff;
        padding: 28px 32px 32px;
        border-radius: 0 0 12px 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        margin-bottom: 30px;
    }

    /* Form */
    .form-label {
        font-size: 11px; font-weight: 700; color: #64748b;
        text-transform: uppercase; letter-spacing: 0.6px;
        margin-bottom: 6px; display: block;
    }
    .form-control {
        border-radius: 8px; border: 1.5px solid #e2e8f0;
        padding: 10px 14px; font-size: 14px;
        font-family: inherit;
        transition: all .15s;
        height: 44px;
    }
    .form-control:focus {
        outline: none; border-color: #1e40af;
        box-shadow: 0 0 0 3px rgba(30,64,175,0.10);
    }
    .btn-primary {
        background: #1e40af; border: 1px solid #1e40af; color: #fff;
        font-weight: 700; font-size: 13.5px;
        height: 44px; padding: 0 24px;
        border-radius: 8px;
        display: inline-flex; align-items: center; gap: 8px;
        transition: all .15s;
    }
    .btn-primary:hover { background: #1e3a8a; border-color: #1e3a8a; color: #fff; }

    .btn-secondary {
        background: #fff; border: 1.5px solid #e2e8f0; color: #475569;
        font-weight: 700; font-size: 13.5px;
        height: 44px; padding: 0 20px;
        border-radius: 8px;
        display: inline-flex; align-items: center; gap: 8px;
        text-decoration: none;
        transition: all .15s;
    }
    .btn-secondary:hover { background: #f1f5f9; color: #1e293b; text-decoration: none; }

    /* Section header */
    .section-header {
        display: flex; align-items: center; gap: 10px;
        margin: 30px 0 16px;
    }
    .section-header .icon {
        width: 38px; height: 38px; border-radius: 10px;
        background: #dbeafe; color: #1e40af;
        display: flex; align-items: center; justify-content: center;
        font-size: 16px;
    }
    .section-header h2 {
        font-size: 16px; font-weight: 700; color: #0f172a;
        margin: 0;
    }
    .section-header .count {
        margin-left: auto;
        background: #f1f5f9; color: #475569;
        padding: 5px 12px; border-radius: 20px;
        font-size: 12px; font-weight: 700;
    }

    /* Search + Sort bar */
    .toolbar {
        display: grid;
        grid-template-columns: 1fr 200px;
        gap: 12px;
        margin-bottom: 16px;
    }
    @media (max-width: 600px) {
        .toolbar { grid-template-columns: 1fr; }
    }
    .search-box { position: relative; }
    .search-box i {
        position: absolute; left: 16px; top: 50%; transform: translateY(-50%);
        color: #94a3b8; font-size: 14px; pointer-events: none;
    }
    .search-box input {
        width: 100%; height: 44px;
        border: 1.5px solid #e2e8f0; border-radius: 8px;
        padding: 0 16px 0 44px;
        font-size: 13.5px;
        font-family: inherit;
        background: #f9fafb;
        transition: all .15s;
    }
    .search-box input:focus {
        outline: none; background: #fff;
        border-color: #1e40af;
        box-shadow: 0 0 0 3px rgba(30,64,175,0.10);
    }
    .toolbar select {
        height: 44px;
        border: 1.5px solid #e2e8f0; border-radius: 8px;
        padding: 0 14px;
        font-size: 13.5px;
        font-family: inherit;
        background: #f9fafb;
        cursor: pointer;
        transition: all .15s;
        font-weight: 600;
        color: #334155;
    }
    .toolbar select:focus {
        outline: none; background: #fff;
        border-color: #1e40af;
        box-shadow: 0 0 0 3px rgba(30,64,175,0.10);
    }

    /* Table */
    .table-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; }
    table.town-table { width: 100%; margin: 0; border-collapse: collapse; }
    table.town-table thead th {
        background: #f8fafc; color: #94a3b8;
        font-size: 11px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.7px;
        padding: 12px 16px; text-align: left;
        border-bottom: 1px solid #e2e8f0;
        white-space: nowrap;
    }
    table.town-table tbody td {
        padding: 14px 16px;
        font-size: 13.5px;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }
    table.town-table tbody tr:last-child td { border-bottom: 0; }
    table.town-table tbody tr:hover td { background: #f8fafc; }

    .sr-num {
        font-family: ui-monospace, monospace;
        font-weight: 700; color: #94a3b8;
        width: 60px;
    }
    .title-cell { font-weight: 700; color: #0f172a; }

    /* Inline edit input */
    .edit-input {
        height: 36px;
        border: 1.5px solid #1e40af;
        border-radius: 6px;
        padding: 0 12px;
        font-size: 13.5px;
        font-family: inherit;
        width: 100%;
        max-width: 420px;
    }
    .edit-input:focus {
        outline: none;
        box-shadow: 0 0 0 3px rgba(30,64,175,0.12);
    }

    /* Icon buttons */
    .btn-icon {
        display: inline-flex; align-items: center; justify-content: center;
        width: 36px; height: 36px;
        border-radius: 8px;
        font-size: 13px;
        text-decoration: none;
        border: 0;
        cursor: pointer;
        transition: all .15s;
        margin-left: 4px;
    }
    .btn-icon.edit { background: #fef3c7; color: #d97706; }
    .btn-icon.edit:hover { background: #d97706; color: #fff; text-decoration: none; }

    .btn-icon.save { background: #d1fae5; color: #059669; }
    .btn-icon.save:hover { background: #059669; color: #fff; }

    .btn-icon.cancel { background: #f1f5f9; color: #64748b; }
    .btn-icon.cancel:hover { background: #64748b; color: #fff; }

    .btn-icon.danger { background: #fee2e2; color: #dc2626; }
    .btn-icon.danger:hover { background: #dc2626; color: #fff; text-decoration: none; }

    /* Alerts */
    .alert {
        border-radius: 8px; font-size: 13.5px;
        padding: 12px 16px; margin-bottom: 20px;
        border: 0; font-weight: 500;
    }
    .alert-success { background: #d1fae5; color: #065f46; }
    .alert-danger { background: #fee2e2; color: #991b1b; }
    .alert-info { background: #dbeafe; color: #1e40af; }

    /* Empty */
    .empty-state {
        padding: 60px 20px;
        text-align: center;
        color: #94a3b8;
    }
    .empty-state .big { font-size: 42px; opacity: 0.4; margin-bottom: 12px; }
    .empty-state .msg { font-weight: 600; color: #475569; font-size: 15px; }
    .empty-state .hint { font-size: 13px; margin-top: 6px; }

    /* Pagination */
    .pagination-bar {
        display: flex; align-items: center; justify-content: space-between;
        gap: 16px; margin-top: 18px; flex-wrap: wrap;
    }
    .pagination-info {
        font-size: 12.5px; color: #64748b; font-weight: 500;
    }
    .pagination-info b { color: #0f172a; font-weight: 700; }
    .pagination {
        display: inline-flex; gap: 4px;
        margin: 0;
    }
    .pagination a,
    .pagination span {
        display: inline-flex; align-items: center; justify-content: center;
        min-width: 36px; height: 36px;
        padding: 0 10px;
        border-radius: 8px;
        border: 1.5px solid #e2e8f0;
        background: #fff;
        color: #475569;
        text-decoration: none;
        font-size: 13px;
        font-weight: 700;
        transition: all .15s;
    }
    .pagination a:hover {
        background: #eff6ff;
        border-color: #bfdbfe;
        color: #1e40af;
    }
    .pagination .current {
        background: #1e40af;
        border-color: #1e40af;
        color: #fff;
    }
    .pagination .dots {
        background: transparent;
        border-color: transparent;
        cursor: default;
        color: #94a3b8;
    }

    @media (max-width: 768px) {
        .wrap { padding: 0 12px; margin: 16px auto; }
        .page-head { padding: 20px 22px; }
        .page-head h1 { font-size: 19px; }
        .card { padding: 22px 20px 26px; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="page-head">
        <div class="head-icon"><i class="fas fa-map-marked-alt"></i></div>
        <div>
            <h1><?= htmlspecialchars($translations[$lang]['heading']) ?></h1>
            <div class="sub"><?= htmlspecialchars($translations[$lang]['subtitle']) ?></div>
        </div>
    </div>

    <div class="card">

        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?= htmlspecialchars($_SESSION['message_type']) ?>">
                <i class="fas fa-<?= $_SESSION['message_type'] === 'success' ? 'check-circle' : 'exclamation-circle' ?> mr-2"></i>
                <?= htmlspecialchars($_SESSION['message']) ?>
            </div>
            <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
        <?php endif; ?>

        <!-- ═══════════ FORM ═══════════ -->
        <form method="post" action="">
            <input type="hidden" name="return_q" value="<?= htmlspecialchars($q) ?>">
            <input type="hidden" name="return_sort" value="<?= htmlspecialchars($sort) ?>">
            <input type="hidden" name="return_page" value="<?= (int)$page ?>">

            <h2 style="font-size:15px;font-weight:700;margin:0 0 18px 0;color:#0f172a;">
                <i class="fas fa-plus-circle" style="color:#1e40af;margin-right:6px;"></i>
                <?= htmlspecialchars($translations[$lang]['title']) ?>
            </h2>

            <div class="row">
                <div class="col-md-12">
                    <div class="form-group">
                        <label class="form-label" for="title"><?= htmlspecialchars($translations[$lang]['title_label']) ?></label>
                        <input type="text" class="form-control" id="title" name="title" required
                               placeholder="<?= htmlspecialchars($translations[$lang]['title_placeholder']) ?>">
                    </div>
                </div>
            </div>

            <div class="text-right" style="margin-top:6px;">
                <button type="submit" class="btn-primary">
                    <i class="fas fa-plus"></i> <?= htmlspecialchars($translations[$lang]['submit']) ?>
                </button>
                <button type="reset" class="btn-secondary">
                    <i class="fas fa-redo"></i> <?= htmlspecialchars($translations[$lang]['reset']) ?>
                </button>
            </div>
        </form>

        <!-- ═══════════ LIST ═══════════ -->
        <div class="section-header">
            <div class="icon"><i class="fas fa-list"></i></div>
            <h2><?= htmlspecialchars($translations[$lang]['list_title']) ?></h2>
            <div class="count"><?= number_format($grand_total) ?> total</div>
        </div>

        <!-- Search + Sort toolbar -->
        <form method="get" action="" class="toolbar" id="filterForm">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" name="q" id="searchInput"
                       placeholder="Search towns..."
                       value="<?= htmlspecialchars($q) ?>"
                       autocomplete="off">
            </div>
            <select name="sort" id="sortSelect">
                <option value="az"     <?= $sort === 'az'     ? 'selected' : '' ?>>A → Z</option>
                <option value="za"     <?= $sort === 'za'     ? 'selected' : '' ?>>Z → A</option>
                <option value="recent" <?= $sort === 'recent' ? 'selected' : '' ?>>Newest First</option>
                <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
            </select>
        </form>

        <?php if (!empty($towns)): ?>
            <div class="table-wrap">
                <table class="town-table">
                    <thead>
                        <tr>
                            <th class="sr-num"><?= $translations[$lang]['sr_no'] ?></th>
                            <th><?= htmlspecialchars($translations[$lang]['title_label']) ?></th>
                            <?php if (!empty($perms['can_edit']) || !empty($perms['can_delete'])): ?>
                                <th style="width: 160px; text-align:right;"><?= $translations[$lang]['actions'] ?></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $counter = $offset + 1; foreach ($towns as $row): ?>
                            <tr data-id="<?= (int)$row['id'] ?>">
                                <td class="sr-num"><?= $counter++ ?></td>
                                <td class="title-cell"><?= htmlspecialchars($row['title']) ?></td>
                                <?php if (!empty($perms['can_edit']) || !empty($perms['can_delete'])): ?>
                                <td style="text-align:right; white-space:nowrap;">
                                    <?php if (!empty($perms['can_edit'])): ?>
                                        <button class="btn-icon edit edit-btn" title="Edit"><i class="fas fa-pen"></i></button>
                                        <button class="btn-icon save save-btn d-none" title="Save"><i class="fas fa-check"></i></button>
                                        <button class="btn-icon cancel cancel-btn d-none" title="Cancel"><i class="fas fa-times"></i></button>
                                    <?php endif; ?>
                                    <?php if (!empty($perms['can_delete'])): ?>
                                        <a href="<?= qs_with(['delete' => (int)$row['id']]) ?>" class="btn-icon danger" title="Delete"
                                           onclick="return confirm('Delete this town?');">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination-bar">
                <div class="pagination-info">
                    Showing <b><?= number_format($offset + 1) ?></b>–<b><?= number_format(min($offset + $per_page, $total_towns)) ?></b>
                    of <b><?= number_format($total_towns) ?></b>
                    <?php if ($q !== ''): ?> for "<b><?= htmlspecialchars($q) ?></b>"<?php endif; ?>
                </div>
                <div class="pagination">
                    <?php
                    // Prev
                    if ($page > 1) {
                        echo '<a href="' . qs_with(['page' => $page - 1]) . '"><i class="fas fa-chevron-left"></i></a>';
                    }
                    // Pages with ellipsis
                    $range = 2;
                    $start = max(1, $page - $range);
                    $end   = min($total_pages, $page + $range);
                    if ($start > 1) {
                        echo '<a href="' . qs_with(['page' => 1]) . '">1</a>';
                        if ($start > 2) echo '<span class="dots">...</span>';
                    }
                    for ($i = $start; $i <= $end; $i++) {
                        if ($i === $page) {
                            echo '<span class="current">' . $i . '</span>';
                        } else {
                            echo '<a href="' . qs_with(['page' => $i]) . '">' . $i . '</a>';
                        }
                    }
                    if ($end < $total_pages) {
                        if ($end < $total_pages - 1) echo '<span class="dots">...</span>';
                        echo '<a href="' . qs_with(['page' => $total_pages]) . '">' . $total_pages . '</a>';
                    }
                    // Next
                    if ($page < $total_pages) {
                        echo '<a href="' . qs_with(['page' => $page + 1]) . '"><i class="fas fa-chevron-right"></i></a>';
                    }
                    ?>
                </div>
            </div>
            <?php elseif ($total_towns > 0): ?>
                <div class="pagination-info" style="margin-top:14px;">
                    Showing <b><?= number_format($total_towns) ?></b>
                    <?= $total_towns === 1 ? 'town' : 'towns' ?>
                    <?php if ($q !== ''): ?> for "<b><?= htmlspecialchars($q) ?></b>"<?php endif; ?>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="empty-state">
                <div class="big">&#127961;&#65039;</div>
                <div class="msg">
                    <?= $q !== '' ? htmlspecialchars($translations[$lang]['no_match']) : htmlspecialchars($translations[$lang]['no_records']) ?>
                </div>
                <div class="hint">
                    <?php if ($q !== ''): ?>
                        <a href="village_councils.php" style="color:#1e40af;font-weight:700;">Clear search</a>
                    <?php else: ?>
                        Add your first town above.
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<script>
$(document).ready(function () {

    /* ─────── Auto-submit on search typing (debounced) ─────── */
    var searchTimer = null;
    var $searchInput = $('#searchInput');
    var $filterForm = $('#filterForm');

    $searchInput.on('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () {
            // Reset to page 1 when searching
            $filterForm.find('input[name="page"]').remove();
            $filterForm.submit();
        }, 400);
    });

    /* ─────── Auto-submit on sort change ─────── */
    $('#sortSelect').on('change', function () {
        $filterForm.find('input[name="page"]').remove();
        $filterForm.submit();
    });

    /* ─────── Inline edit: START ─────── */
    $(document).on('click', '.edit-btn', function () {
        var $row = $(this).closest('tr');
        var $titleCell = $row.find('.title-cell');
        var original = $titleCell.text().trim();

        $titleCell.data('original', original);
        $titleCell.html('<input type="text" class="edit-input" value="' + $('<div>').text(original).html() + '">');

        $row.find('.edit-btn, .danger').addClass('d-none');
        $row.find('.save-btn, .cancel-btn').removeClass('d-none');
        $row.find('.edit-input').focus().select();
    });

    /* ─────── Inline edit: CANCEL ─────── */
    $(document).on('click', '.cancel-btn', function () {
        var $row = $(this).closest('tr');
        var $titleCell = $row.find('.title-cell');
        var original = $titleCell.data('original') || '';

        $titleCell.html($('<div>').text(original).html());
        $row.find('.save-btn, .cancel-btn').addClass('d-none');
        $row.find('.edit-btn, .danger').removeClass('d-none');
    });

    /* ─────── Inline edit: SAVE ─────── */
    $(document).on('click', '.save-btn', function () {
        var $row = $(this).closest('tr');
        var id = $row.data('id');
        var newTitle = $row.find('.edit-input').val().trim();

        if (!newTitle) {
            alert('Title cannot be empty.');
            return;
        }

        $.ajax({
            url: 'village_council_edit.php',
            type: 'POST',
            data: { id: id, title: newTitle },
            success: function (response) {
                response = (response || '').trim();
                if (response === 'success') {
                    $row.find('.title-cell').html($('<div>').text(newTitle).html());
                    $row.find('.save-btn, .cancel-btn').addClass('d-none');
                    $row.find('.edit-btn, .danger').removeClass('d-none');
                } else {
                    alert('Update failed: ' + response);
                }
            },
            error: function () {
                alert('AJAX error. Please try again.');
            }
        });
    });

    /* Enter → save */
    $(document).on('keypress', '.edit-input', function (e) {
        if (e.which === 13) {
            e.preventDefault();
            $(this).closest('tr').find('.save-btn').click();
        }
    });

    /* Escape → cancel */
    $(document).on('keyup', '.edit-input', function (e) {
        if (e.which === 27) {
            $(this).closest('tr').find('.cancel-btn').click();
        }
    });

    /* Focus search input with Ctrl+K / Cmd+K */
    $(document).on('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            $searchInput.focus().select();
        }
    });
});
</script>

</body>
</html>
<?php if (isset($conn)) $conn->close(); ?>