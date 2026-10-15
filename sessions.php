<?php
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
        'title' => 'Academic Sessions',
        'add_title' => 'Add Academic Session',
        'edit_title' => 'Edit Academic Session',
        'title_label' => 'Session Title',
        'title_placeholder' => 'e.g. 2026___2027',
        'from_date_label' => 'From Date',
        'to_date_label' => 'To Date',
        'duration_label' => 'Duration',
        'submit' => 'Save Session',
        'update' => 'Update Session',
        'reset' => 'Reset',
        'success' => 'Session added successfully!',
        'update_success' => 'Session updated successfully!',
        'delete_success' => 'Session deleted successfully!',
        'error' => 'Error: ',
        'empty_error' => 'Title, From Date, and To Date cannot be empty!',
        'date_error' => 'To Date must be after From Date!',
        'list_title' => 'Sessions List',
        'no_records' => 'No sessions found.',
        'sr_no' => '#',
        'actions' => 'Actions',
        'edit' => 'Edit',
        'save' => 'Save',
        'cancel' => 'Cancel',
        'delete_confirm' => 'Are you sure you want to delete this session?',
    ]
];

/* ─────────── Helper: month map (same as student_registration) ─────────── */
$month_map = [
    'jan'=>'01','january'=>'01','feb'=>'02','february'=>'02','mar'=>'03','march'=>'03',
    'apr'=>'04','april'=>'04','may'=>'05','jun'=>'06','june'=>'06','jul'=>'07','july'=>'07',
    'aug'=>'08','august'=>'08','sep'=>'09','sept'=>'09','september'=>'09',
    'oct'=>'10','october'=>'10','nov'=>'11','november'=>'11','dec'=>'12','december'=>'12'
];

$month_names = [
    '01'=>'Jan','02'=>'Feb','03'=>'Mar','04'=>'Apr','05'=>'May','06'=>'Jun',
    '07'=>'Jul','08'=>'Aug','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Dec'
];

$current_year = intval(date('Y'));

/* ─────────── Process form submission ─────────── */
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = trim($_POST['title'] ?? '');

    // Build FROM date from day/month/year dropdowns
    $from_day   = str_pad(trim($_POST['from_day'] ?? ''), 2, '0', STR_PAD_LEFT);
    $from_month = strtolower(trim($_POST['from_month'] ?? ''));
    $from_year  = trim($_POST['from_year'] ?? '');

    // Build TO date from day/month/year dropdowns
    $to_day   = str_pad(trim($_POST['to_day'] ?? ''), 2, '0', STR_PAD_LEFT);
    $to_month = strtolower(trim($_POST['to_month'] ?? ''));
    $to_year  = trim($_POST['to_year'] ?? '');

    // Resolve month name → number
    $from_month_num = isset($month_map[$from_month]) ? $month_map[$from_month] : (is_numeric($from_month) ? str_pad(intval($from_month), 2, '0', STR_PAD_LEFT) : '');
    $to_month_num   = isset($month_map[$to_month])   ? $month_map[$to_month]   : (is_numeric($to_month)   ? str_pad(intval($to_month), 2, '0', STR_PAD_LEFT)   : '');

    $from_dated = '';
    $to_dated   = '';

    if ($from_day && $from_month_num && $from_year && checkdate(intval($from_month_num), intval($from_day), intval($from_year))) {
        $from_dated = sprintf('%04d-%02d-%02d', intval($from_year), intval($from_month_num), intval($from_day));
    }
    if ($to_day && $to_month_num && $to_year && checkdate(intval($to_month_num), intval($to_day), intval($to_year))) {
        $to_dated = sprintf('%04d-%02d-%02d', intval($to_year), intval($to_month_num), intval($to_day));
    }

    $date_error = '';
    if ($from_dated && $to_dated && strtotime($to_dated) <= strtotime($from_dated)) {
        $date_error = $translations[$lang]['date_error'];
    }

    if (!empty($title) && !empty($from_dated) && !empty($to_dated) && empty($date_error)) {
        if (isset($_POST['session_id']) && !empty($_POST['session_id'])) {
            $session_id = intval($_POST['session_id']);
            $stmt = $conn->prepare("UPDATE sessions SET title = ?, from_dated = ?, to_dated = ? WHERE id = ?");
            $stmt->bind_param("sssi", $title, $from_dated, $to_dated, $session_id);
            if ($stmt->execute()) {
                $_SESSION['message'] = $translations[$lang]['update_success'];
                $_SESSION['message_type'] = "success";
            }
        } else {
            $stmt = $conn->prepare("INSERT INTO sessions (title, from_dated, to_dated) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $title, $from_dated, $to_dated);
            if ($stmt->execute()) {
                $_SESSION['message'] = $translations[$lang]['success'];
                $_SESSION['message_type'] = "success";
            }
        }
        if (!empty($stmt->error)) {
            $_SESSION['message'] = $translations[$lang]['error'] . $stmt->error;
            $_SESSION['message_type'] = "danger";
        }
        $stmt->close();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } else {
        if (empty($title) || empty($from_dated) || empty($to_dated)) {
            $_SESSION['message'] = $translations[$lang]['empty_error'];
        } else {
            $_SESSION['message'] = $date_error;
        }
        $_SESSION['message_type'] = "danger";
    }
}

/* ─────────── Handle deletion ─────────── */
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM sessions WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $_SESSION['message'] = $translations[$lang]['delete_success'];
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = $translations[$lang]['error'] . $stmt->error;
        $_SESSION['message_type'] = "danger";
    }
    $stmt->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

/* ─────────── Load edit data ─────────── */
$edit_mode = false;
$session_data = [
    'id' => '',
    'title' => '',
    'from_dated' => '',
    'to_dated' => ''
];

if (isset($_GET['edit'])) {
    $id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT id, title, from_dated, to_dated FROM sessions WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $session_data = $res->fetch_assoc();
            $edit_mode = true;
        }
        $stmt->close();
    }
}

/* ─────────── Pre-parse from/to dates for dropdowns ─────────── */
function parse_date_parts($date_str) {
    if (empty($date_str) || $date_str === '0000-00-00') return ['', '', ''];
    try {
        $d = new DateTime($date_str);
        return [$d->format('d'), $d->format('m'), $d->format('Y')];
    } catch (Exception $e) {
        return ['', '', ''];
    }
}

list($from_dd, $from_mm, $from_yyyy) = parse_date_parts($session_data['from_dated']);
list($to_dd, $to_mm, $to_yyyy) = parse_date_parts($session_data['to_dated']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($translations[$lang]['title']) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<style>
    * { box-sizing: border-box; }
    body { background: #eef1f5; font-family: 'Inter', sans-serif; color: #1e293b; margin: 0; padding: 0; }
    .wrap { max-width: 1100px; margin: 30px auto; padding: 0 20px; }

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

    /* DOB-style group — day / month / year dropdowns */
    .dob-group { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .dob-group select {
        height: 44px; border: 1.5px solid #e2e8f0; border-radius: 8px;
        padding: 0 12px; font-size: 14px; font-family: inherit;
        background: #fff; transition: all .15s;
    }
    .dob-group select:focus {
        outline: none; border-color: #1e40af;
        box-shadow: 0 0 0 3px rgba(30,64,175,0.10);
    }
    .dob-day   { width: 90px; }
    .dob-month { width: 130px; }
    .dob-year  { width: 110px; }

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

    /* Section header (between form and list) */
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

    /* Table */
    .table-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; }
    table.sessions-table { width: 100%; margin: 0; border-collapse: collapse; }
    table.sessions-table thead th {
        background: #f8fafc; color: #94a3b8;
        font-size: 11px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.7px;
        padding: 12px 16px; text-align: left;
        border-bottom: 1px solid #e2e8f0;
        white-space: nowrap;
    }
    table.sessions-table tbody td {
        padding: 14px 16px;
        font-size: 13.5px;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }
    table.sessions-table tbody tr:last-child td { border-bottom: 0; }
    table.sessions-table tbody tr:hover td { background: #f8fafc; }

    .session-title {
        font-weight: 700; color: #0f172a;
    }
    .date-cell { color: #475569; font-weight: 500; font-size: 13px; white-space: nowrap; }
    .duration-pill {
        display: inline-block;
        background: #dbeafe; color: #1e40af;
        padding: 4px 12px; border-radius: 12px;
        font-size: 11.5px; font-weight: 700;
    }
    .sr-num {
        font-family: ui-monospace, monospace;
        font-weight: 700; color: #94a3b8;
    }

    .btn-icon {
        display: inline-flex; align-items: center; justify-content: center;
        width: 34px; height: 34px;
        border-radius: 8px;
        font-size: 13px;
        text-decoration: none;
        border: 0;
        cursor: pointer;
        transition: all .15s;
    }
    .btn-icon.edit { background: #fef3c7; color: #d97706; }
    .btn-icon.edit:hover { background: #d97706; color: #fff; text-decoration: none; }
    .btn-icon.danger { background: #fee2e2; color: #dc2626; }
    .btn-icon.danger:hover { background: #dc2626; color: #fff; text-decoration: none; }

    .alert {
        border-radius: 8px; font-size: 13.5px;
        padding: 12px 16px; margin-bottom: 20px;
        border: 0; font-weight: 500;
    }
    .alert-success { background: #d1fae5; color: #065f46; }
    .alert-danger { background: #fee2e2; color: #991b1b; }
    .alert-info { background: #dbeafe; color: #1e40af; }

    .empty-state {
        padding: 60px 20px;
        text-align: center;
        color: #94a3b8;
    }
    .empty-state .big { font-size: 42px; opacity: 0.4; margin-bottom: 12px; }
    .empty-state .msg { font-weight: 600; color: #475569; font-size: 15px; }
    .empty-state .hint { font-size: 13px; margin-top: 6px; }

    @media (max-width: 768px) {
        .wrap { padding: 0 12px; margin: 16px auto; }
        .page-head { padding: 20px 22px; }
        .page-head h1 { font-size: 19px; }
        .card { padding: 22px 20px 26px; }
        .dob-group select { font-size: 13px; padding: 0 10px; }
        .dob-day   { width: 78px; }
        .dob-month { width: 110px; }
        .dob-year  { width: 92px; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="page-head">
        <div class="head-icon"><i class="fas fa-calendar-alt"></i></div>
        <div>
            <h1><?= htmlspecialchars($translations[$lang]['title']) ?></h1>
            <div class="sub">Manage academic year start and end dates</div>
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
            <input type="hidden" name="session_id" value="<?= (int)$session_data['id'] ?>">

            <h2 style="font-size:15px;font-weight:700;margin:0 0 18px 0;color:#0f172a;">
                <i class="fas fa-<?= $edit_mode ? 'edit' : 'plus-circle' ?>" style="color:#1e40af;margin-right:6px;"></i>
                <?= $edit_mode ? $translations[$lang]['edit_title'] : $translations[$lang]['add_title'] ?>
            </h2>

            <div class="row">
                <div class="col-md-12">
                    <div class="form-group">
                        <label class="form-label" for="title"><?= htmlspecialchars($translations[$lang]['title_label']) ?></label>
                        <input type="text" class="form-control" id="title" name="title" required
                               placeholder="<?= htmlspecialchars($translations[$lang]['title_placeholder']) ?>"
                               value="<?= htmlspecialchars($session_data['title']) ?>">
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars($translations[$lang]['from_date_label']) ?></label>
                        <div class="dob-group">
                            <select class="dob-day" name="from_day" required>
                                <option value="">Day</option>
                                <?php for ($d = 1; $d <= 31; $d++):
                                    $dVal = str_pad($d, 2, '0', STR_PAD_LEFT);
                                ?>
                                    <option value="<?= $dVal ?>" <?= ((string)$from_dd === $dVal) ? 'selected' : '' ?>><?= $d ?></option>
                                <?php endfor; ?>
                            </select>
                            <select class="dob-month" name="from_month" required>
                                <option value="">Month</option>
                                <?php foreach ($month_names as $mk => $mv): ?>
                                    <option value="<?= $mk ?>" <?= ((string)$from_mm === (string)$mk) ? 'selected' : '' ?>><?= $mv ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select class="dob-year" name="from_year" required>
                                <option value="">Year</option>
                                <?php for ($y = $current_year + 2; $y >= $current_year - 10; $y--): ?>
                                    <option value="<?= $y ?>" <?= ((string)$from_yyyy === (string)$y) ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars($translations[$lang]['to_date_label']) ?></label>
                        <div class="dob-group">
                            <select class="dob-day" name="to_day" required>
                                <option value="">Day</option>
                                <?php for ($d = 1; $d <= 31; $d++):
                                    $dVal = str_pad($d, 2, '0', STR_PAD_LEFT);
                                ?>
                                    <option value="<?= $dVal ?>" <?= ((string)$to_dd === $dVal) ? 'selected' : '' ?>><?= $d ?></option>
                                <?php endfor; ?>
                            </select>
                            <select class="dob-month" name="to_month" required>
                                <option value="">Month</option>
                                <?php foreach ($month_names as $mk => $mv): ?>
                                    <option value="<?= $mk ?>" <?= ((string)$to_mm === (string)$mk) ? 'selected' : '' ?>><?= $mv ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select class="dob-year" name="to_year" required>
                                <option value="">Year</option>
                                <?php for ($y = $current_year + 3; $y >= $current_year - 10; $y--): ?>
                                    <option value="<?= $y ?>" <?= ((string)$to_yyyy === (string)$y) ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="text-right" style="margin-top:14px;">
                <?php if ($edit_mode ? !empty($perms['can_edit']) : !empty($perms['can_add'])): ?>
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-<?= $edit_mode ? 'save' : 'plus' ?>"></i>
                        <?= $edit_mode ? $translations[$lang]['update'] : $translations[$lang]['submit'] ?>
                    </button>
                <?php endif; ?>

                <?php if ($edit_mode): ?>
                    <a href="sessions.php" class="btn-secondary">
                        <i class="fas fa-times"></i> <?= $translations[$lang]['cancel'] ?>
                    </a>
                <?php else: ?>
                    <button type="reset" class="btn-secondary">
                        <i class="fas fa-redo"></i> <?= $translations[$lang]['reset'] ?>
                    </button>
                <?php endif; ?>
            </div>
        </form>

        <!-- ═══════════ LIST ═══════════ -->
        <?php
        $result = $conn->query("SELECT id, title, from_dated, to_dated FROM sessions ORDER BY from_dated DESC");
        $session_rows = [];
        if ($result) while ($row = $result->fetch_assoc()) $session_rows[] = $row;
        ?>

        <div class="section-header">
            <div class="icon"><i class="fas fa-list"></i></div>
            <h2><?= htmlspecialchars($translations[$lang]['list_title']) ?></h2>
            <div class="count"><?= count($session_rows) ?> total</div>
        </div>

        <?php if (!empty($session_rows)): ?>
            <div class="table-wrap">
                <table class="sessions-table">
                    <thead>
                        <tr>
                            <th style="width: 60px;"><?= $translations[$lang]['sr_no'] ?></th>
                            <th><?= $translations[$lang]['title_label'] ?></th>
                            <th><?= $translations[$lang]['from_date_label'] ?></th>
                            <th><?= $translations[$lang]['to_date_label'] ?></th>
                            <th><?= $translations[$lang]['duration_label'] ?></th>
                            <?php if (!empty($perms['can_edit']) || !empty($perms['can_delete'])): ?>
                                <th style="width: 130px; text-align:right;"><?= $translations[$lang]['actions'] ?></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $counter = 1; foreach ($session_rows as $row):
                            $start_date = new DateTime($row['from_dated']);
                            $end_date = new DateTime($row['to_dated']);
                            $interval = $start_date->diff($end_date);
                            $duration_parts = [];
                            if ($interval->y > 0) $duration_parts[] = $interval->y . ' ' . ($interval->y == 1 ? 'yr' : 'yrs');
                            if ($interval->m > 0) $duration_parts[] = $interval->m . ' ' . ($interval->m == 1 ? 'mo' : 'mos');
                            if ($interval->d > 0) $duration_parts[] = $interval->d . ' ' . ($interval->d == 1 ? 'day' : 'days');
                            if (empty($duration_parts)) $duration_parts[] = '0 days';
                            $duration = implode(' ', $duration_parts);
                        ?>
                        <tr>
                            <td class="sr-num"><?= $counter++ ?></td>
                            <td><span class="session-title"><?= htmlspecialchars($row['title']) ?></span></td>
                            <td class="date-cell"><?= date('d M Y', strtotime($row['from_dated'])) ?></td>
                            <td class="date-cell"><?= date('d M Y', strtotime($row['to_dated'])) ?></td>
                            <td><span class="duration-pill"><?= htmlspecialchars($duration) ?></span></td>
                            <?php if (!empty($perms['can_edit']) || !empty($perms['can_delete'])): ?>
                            <td style="text-align:right; white-space:nowrap;">
                                <?php if (!empty($perms['can_edit'])): ?>
                                    <a href="?edit=<?= (int)$row['id'] ?>" class="btn-icon edit" title="Edit">
                                        <i class="fas fa-pen"></i>
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($perms['can_delete'])): ?>
                                    <a href="?delete=<?= (int)$row['id'] ?>" class="btn-icon danger" title="Delete"
                                       onclick="return confirm('<?= htmlspecialchars($translations[$lang]['delete_confirm']) ?>');">
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
        <?php else: ?>
            <div class="empty-state">
                <div class="big">&#128197;</div>
                <div class="msg"><?= htmlspecialchars($translations[$lang]['no_records']) ?></div>
                <div class="hint">Add your first academic session above.</div>
            </div>
        <?php endif; ?>

    </div>
</div>

<script>
$(document).ready(function () {
    // Auto-fill logic: no datepicker needed — dropdowns handle it
    // Nothing extra to initialize
});
</script>

</body>
<?php if (isset($conn)) $conn->close(); ?>
</html>