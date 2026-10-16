<?php
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once('conn_inc.php');

/* ============================================================
   POST — promote students
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['promote_students'])) {

    $current_session_id = intval($_POST['current_session_id'] ?? 0);
    $current_class_id   = intval($_POST['current_class_id'] ?? 0);
    $new_session_id     = intval($_POST['new_session_id'] ?? 0);
    $new_class_id       = intval($_POST['new_class_id'] ?? 0);
    $promotion_date     = trim($_POST['promotion_date'] ?? date('Y-m-d'));
    $promotion_mode     = ($_POST['promotion_mode'] ?? 'all') === 'selective' ? 'selective' : 'all';

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $promotion_date)) $promotion_date = date('Y-m-d');

    $success_count = 0;
    $error_count = 0;
    $skipped_count = 0;

    /* Determine student_class IDs to promote */
    if ($promotion_mode === 'selective') {
        $students_to_promote = (isset($_POST['student_ids']) && is_array($_POST['student_ids']))
            ? array_values(array_filter(array_map('intval', $_POST['student_ids'])))
            : [];
    } else {
        /* All mode: fetch every active student in the current class+session */
        $students_to_promote = [];
        $all_q = $conn->prepare("SELECT id FROM student_class WHERE session_id = ? AND class_id = ? AND status = 0");
        $all_q->bind_param("ii", $current_session_id, $current_class_id);
        $all_q->execute();
        $all_r = $all_q->get_result();
        while ($r = $all_r->fetch_assoc()) $students_to_promote[] = (int)$r['id'];
        $all_q->close();
    }

    if (empty($students_to_promote)) {
        $_SESSION['message'] = 'No students selected for promotion.';
        $_SESSION['message_type'] = 'warning';
        header("Location: student_promotion.php");
        exit();
    }

    $conn->begin_transaction();

    try {
        foreach ($students_to_promote as $student_class_id) {

            /* Get student_registration_id from student_class */
            $stmt = $conn->prepare("SELECT student_registration_id FROM student_class WHERE id = ?");
            $stmt->bind_param("i", $student_class_id);
            $stmt->execute();
            $student = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$student) {
                $error_count++;
                continue;
            }

            $student_registration_id = (int)$student['student_registration_id'];

            /* Skip if already promoted to the same new session + class */
            $chk = $conn->prepare("SELECT id FROM student_class WHERE student_registration_id = ? AND session_id = ? AND class_id = ? AND status = 0");
            $chk->bind_param("iii", $student_registration_id, $new_session_id, $new_class_id);
            $chk->execute();
            $exists = $chk->get_result()->num_rows > 0;
            $chk->close();

            if ($exists) {
                $skipped_count++;
                continue;
            }

            /* Insert new record */
            $ins = $conn->prepare("INSERT INTO student_class (student_registration_id, session_id, class_id, promotion_date, dated, status) VALUES (?, ?, ?, ?, CURDATE(), 0)");
            $ins->bind_param("iiis", $student_registration_id, $new_session_id, $new_class_id, $promotion_date);
            if (!$ins->execute()) {
                $ins->close();
                throw new Exception("Insert failed for student_class ID: $student_class_id");
            }
            $ins->close();

            /* Mark old record as inactive */
            $upd = $conn->prepare("UPDATE student_class SET status = 1 WHERE id = ?");
            $upd->bind_param("i", $student_class_id);
            if (!$upd->execute()) {
                $upd->close();
                throw new Exception("Failed to update old record for student_class ID: $student_class_id");
            }
            $upd->close();

            $success_count++;
        }

        $conn->commit();

        $msg = "Promotion completed. Promoted: $success_count.";
        if ($skipped_count > 0) $msg .= " Skipped (already in new class): $skipped_count.";
        if ($error_count > 0)   $msg .= " Failed: $error_count.";

        $_SESSION['message'] = $msg;
        $_SESSION['message_type'] = ($error_count > 0) ? 'warning' : 'success';

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['message'] = "Promotion failed: " . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }

    header("Location: student_promotion.php");
    exit();
}

/* ============================================================
   LOAD DROPDOWN DATA
   ============================================================ */
$sessions = [];
$s_res = $conn->query("SELECT id, title FROM sessions WHERE status = 0 ORDER BY id DESC");
if ($s_res) while ($r = $s_res->fetch_assoc()) $sessions[] = $r;

/* Classes — grouped by id, with section label for display */
$classes = [];
$c_sql = "SELECT c.id, c.title FROM classes c WHERE c.status IS NULL OR c.status = 0 ORDER BY c.id";
$c_res = $conn->query($c_sql);
if ($c_res) {
    while ($r = $c_res->fetch_assoc()) {
        $classes[$r['id']] = $r['title'];
    }
}

/* ============================================================
   STUDENT LOADING FOR THE CURRENT CLASS
   ============================================================ */
$students = [];
$current_session_id = isset($_GET['current_session_id']) ? intval($_GET['current_session_id']) : 0;
$current_class_id   = isset($_GET['current_class_id']) ? intval($_GET['current_class_id']) : 0;

if ($current_session_id > 0 && $current_class_id > 0) {
    $sql = "SELECT sc.id AS student_class_id, sr.id AS student_id, sr.reg_no, sr.name, sr.father_name, sr.mobile, c.title AS class_name FROM student_class sc INNER JOIN student_registration sr ON sc.student_registration_id = sr.id INNER JOIN classes c ON sc.class_id = c.id WHERE sc.session_id = ? AND sc.class_id = ? AND sc.status = 0 ORDER BY sr.id ASC";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ii", $current_session_id, $current_class_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $students[] = $r;
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Student Promotion</title>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Inter', sans-serif; background: #f5f7fa; padding-bottom: 60px; }

    .page-header-card {
        background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        border-radius: 12px; padding: 22px 26px; color: #fff;
        margin-bottom: 20px; display: flex; align-items: center; gap: 16px;
        box-shadow: 0 6px 22px rgba(37, 99, 235, 0.25);
    }
    .page-header-card .header-icon {
        width: 52px; height: 52px; background: rgba(255,255,255,0.18);
        border-radius: 12px; display: flex; align-items: center; justify-content: center;
        font-size: 24px; flex-shrink: 0;
    }
    .page-header-card h1 { font-size: 21px; font-weight: 700; margin: 0; }
    .page-header-card .subtitle { font-size: 13px; opacity: 0.85; margin-top: 4px; }

    .card-block {
        background: #fff; border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.06);
        padding: 22px 24px;
        margin-bottom: 20px;
    }
    .card-block h3 {
        font-size: 15px; font-weight: 700; color: #1e293b; margin: 0 0 16px 0;
        display: flex; align-items: center; gap: 10px;
    }
    .card-block h3 i { color: #2563eb; }

    .info-banner {
        background: #fffbeb; border: 1px solid #fde68a; border-left: 4px solid #f59e0b;
        border-radius: 10px; padding: 14px 18px; margin-bottom: 20px;
        font-size: 13px; color: #92400e; line-height: 1.6;
    }
    .info-banner strong { color: #78350f; }
    .info-banner ul { margin: 8px 0 0 20px; padding: 0; }
    .info-banner ul li { margin-bottom: 4px; }

    .form-control {
        border-radius: 8px; border: 1px solid #cbd5e1;
        font-size: 14px; padding: 9px 12px; height: auto;
    }
    .form-control:focus {
        border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
    }
    label {
        font-size: 12px; font-weight: 700; color: #475569;
        text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px;
    }

    .btn-brand {
        background: #2563eb; border: 1px solid #2563eb; color: #fff;
        font-weight: 600; font-size: 14px; padding: 10px 20px;
        border-radius: 8px; display: inline-flex; align-items: center; gap: 8px;
    }
    .btn-brand:hover, .btn-brand:focus { background: #1d4ed8; border-color: #1d4ed8; color: #fff; }
    .btn-success-brand {
        background: #059669; border: 1px solid #059669; color: #fff;
        font-weight: 600; font-size: 14px; padding: 12px 24px;
        border-radius: 8px; display: inline-flex; align-items: center; gap: 8px;
    }
    .btn-success-brand:hover, .btn-success-brand:focus { background: #047857; border-color: #047857; color: #fff; }
    .btn-ghost {
        background: #f1f5f9; border: 1px solid #e2e8f0; color: #475569;
        font-weight: 600; font-size: 13px; padding: 9px 16px;
        border-radius: 8px; display: inline-flex; align-items: center; gap: 7px;
    }
    .btn-ghost:hover, .btn-ghost:focus { background: #e2e8f0; color: #1e293b; }
    .btn-info-brand {
        background: #0891b2; border: 1px solid #0891b2; color: #fff;
        font-weight: 600; font-size: 13px; padding: 10px 18px;
        border-radius: 8px; display: inline-flex; align-items: center; gap: 7px;
    }
    .btn-info-brand:hover, .btn-info-brand:focus { background: #0e7490; border-color: #0e7490; color: #fff; }
    .btn-warn-brand {
        background: #f59e0b; border: 1px solid #f59e0b; color: #fff;
        font-weight: 600; font-size: 13px; padding: 10px 18px;
        border-radius: 8px; display: inline-flex; align-items: center; gap: 7px;
    }
    .btn-warn-brand:hover, .btn-warn-brand:focus { background: #d97706; border-color: #d97706; color: #fff; }

    /* Radio pills for promotion mode */
    .radio-pill-group {
        display: inline-flex; background: #f1f5f9;
        border: 1px solid #e2e8f0; border-radius: 10px; padding: 4px; gap: 4px;
    }
    .radio-pill-group label {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 8px 18px; border-radius: 8px;
        font-size: 13px; font-weight: 600; color: #64748b;
        cursor: pointer; margin: 0; text-transform: none; letter-spacing: 0;
        transition: all 0.15s; user-select: none;
    }
    .radio-pill-group label:hover { color: #334155; }
    .radio-pill-group input { display: none; }
    .radio-pill-group label:has(input:checked) { background: #2563eb; color: #fff; box-shadow: 0 2px 6px rgba(37,99,235,0.3); }
    .radio-pill-group label:has(input[value="selective"]:checked) { background: #7c3aed; box-shadow: 0 2px 6px rgba(124,58,237,0.3); }

    /* Student list */
    .student-list-box {
        max-height: 480px; overflow-y: auto;
        border: 1px solid #e2e8f0; border-radius: 10px;
        background: #f8fafc;
    }
    .student-row {
        display: grid; grid-template-columns: 44px 1fr auto;
        gap: 12px; align-items: center;
        padding: 12px 16px;
        border-bottom: 1px solid #e2e8f0;
        background: #fff; transition: background 0.15s;
    }
    .student-row:last-child { border-bottom: none; }
    .student-row:hover { background: #f8fafc; }
    .student-row.unchecked { opacity: 0.5; background: #f8fafc; }

    .student-checkbox-wrap { display: flex; align-items: center; justify-content: center; }
    .student-checkbox { width: 20px; height: 20px; accent-color: #2563eb; cursor: pointer; }
    .student-checkbox:disabled { cursor: not-allowed; }

    .student-meta { min-width: 0; }
    .student-name { font-weight: 700; font-size: 14px; color: #1e293b; }
    .student-sub {
        font-size: 12px; color: #64748b; margin-top: 2px;
        display: flex; gap: 14px; flex-wrap: wrap;
    }
    .student-sub span { display: inline-flex; align-items: center; gap: 4px; }

    .student-idx {
        font-size: 12px; font-weight: 700; color: #94a3b8;
        display: inline-flex; align-items: center; justify-content: center;
        width: 28px; height: 28px;
        background: #f1f5f9; border-radius: 6px;
    }

    .select-all-bar {
        display: flex; align-items: center; gap: 10px;
        padding: 12px 16px; background: #eff6ff;
        border: 1px solid #bfdbfe; border-radius: 10px;
        margin-bottom: 12px; font-size: 13px; font-weight: 600; color: #1e40af;
    }
    .select-all-bar input { width: 18px; height: 18px; accent-color: #2563eb; cursor: pointer; }

    .counter-pill {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 4px 12px; border-radius: 20px;
        font-size: 12px; font-weight: 700;
    }
    .counter-pill.blue { background: #eff6ff; color: #2563eb; }
    .counter-pill.green { background: #d1fae5; color: #065f46; }
    .counter-pill.amber { background: #fef3c7; color: #92400e; }

    .empty-state {
        padding: 50px 20px; text-align: center; color: #94a3b8;
    }
    .empty-state .big { font-size: 44px; opacity: 0.4; margin-bottom: 10px; }
    .empty-state h5 { font-size: 15px; font-weight: 700; color: #334155; margin: 0 0 6px 0; }

    .section-title {
        font-size: 12px; font-weight: 800; color: #64748b;
        text-transform: uppercase; letter-spacing: 1.6px;
        margin: 8px 0 12px 0; padding-bottom: 8px;
        border-bottom: 2px solid #e2e8f0;
        display: flex; align-items: center; gap: 8px;
    }

    .promotion-target {
        background: #f0fdf4; border: 1px solid #bbf7d0;
        border-radius: 10px; padding: 16px 18px; margin-top: 12px;
    }
    .promotion-target .label-line {
        font-size: 12px; font-weight: 700; color: #166534;
        text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 10px;
    }

    @media (max-width: 768px) {
        .student-row { grid-template-columns: 40px 1fr; }
        .student-row .student-idx { display: none; }
        .radio-pill-group { width: 100%; }
        .radio-pill-group label { flex: 1; justify-content: center; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="container" style="padding: 20px; max-width: 1200px; margin: 0 auto;">

    <div class="page-header-card">
        <div class="header-icon"><i class="fas fa-arrow-up"></i></div>
        <div>
            <h1>Student Promotion</h1>
            <div class="subtitle">Move students from one class to the next at year-end</div>
        </div>
    </div>

    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert alert-<?php echo htmlspecialchars($_SESSION['message_type']); ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($_SESSION['message']); ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
        <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
    <?php endif; ?>

    <div class="info-banner">
        <strong><i class="fas fa-info-circle"></i> How it works:</strong>
        <ul>
            <li>Students are added to the new session + class.</li>
            <li>Their old class record is marked <strong>inactive</strong> (status = 1).</li>
            <li>Each student can have only one active class record per session.</li>
            <li>Promotion cannot be undone from this page &mdash; use the reverse flow if you make a mistake.</li>
        </ul>
    </div>

    <!-- Step 1: Pick current class -->
    <div class="card-block">
        <h3><i class="fas fa-search"></i> Step 1 — Pick the current class to promote from</h3>

        <form method="GET" action="">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <label>Current Session <span style="color:#dc2626;">*</span></label>
                        <select class="form-control" name="current_session_id" required>
                            <option value="">— Select Session —</option>
                            <?php foreach ($sessions as $s): ?>
                                <option value="<?php echo (int)$s['id']; ?>" <?php echo ($current_session_id === (int)$s['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($s['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-5">
                    <div class="form-group">
                        <label>Current Class <span style="color:#dc2626;">*</span></label>
                        <select class="form-control" name="current_class_id" required>
                            <option value="">— Select Class —</option>
                            <?php foreach ($classes as $cid => $ctitle): ?>
                                <option value="<?php echo (int)$cid; ?>" <?php echo ($current_class_id === (int)$cid) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ctitle); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group" style="padding-top: 24px;">
                        <button type="submit" class="btn-brand" style="width: 100%; justify-content: center;">
                            <i class="fas fa-users"></i> Load
                        </button>
                    </div>
                </div>
            </div>
            <?php if ($current_session_id > 0 && $current_class_id > 0): ?>
                <a href="student_promotion.php" class="btn-ghost">
                    <i class="fas fa-times"></i> Reset
                </a>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($current_session_id > 0 && $current_class_id > 0 && !empty($students)): ?>

    <form method="POST" action="" id="promotionForm">
        <input type="hidden" name="current_session_id" value="<?php echo (int)$current_session_id; ?>">
        <input type="hidden" name="current_class_id" value="<?php echo (int)$current_class_id; ?>">
        <input type="hidden" name="promote_students" value="1">

        <!-- Step 2: Choose students -->
        <div class="card-block">
            <h3>
                <i class="fas fa-users"></i> Step 2 — Choose students to promote
                <span class="counter-pill blue" style="margin-left:auto;">
                    <i class="fas fa-graduation-cap"></i> <?php echo count($students); ?> in class
                </span>
            </h3>

            <!-- Promotion mode -->
            <div style="margin-bottom: 16px;">
                <label style="display:block; margin-bottom: 8px;">Promotion Mode</label>
                <div class="radio-pill-group">
                    <label>
                        <input type="radio" name="promotion_mode" value="all" checked>
                        <i class="fas fa-check-circle"></i>
                        <span>Promote All</span>
                    </label>
                    <label>
                        <input type="radio" name="promotion_mode" value="selective">
                        <i class="fas fa-list-check"></i>
                        <span>Select Specific</span>
                    </label>
                </div>
            </div>

            <!-- Select-all bar (only shown in selective mode) -->
            <div class="select-all-bar" id="selectAllBar" style="display:none;">
                <input type="checkbox" id="selectAllCheckbox" checked>
                <label for="selectAllCheckbox" style="margin: 0; text-transform: none; letter-spacing: 0; font-weight: 600; color: inherit; font-size: 13px;">
                    Select / deselect all students
                </label>
                <span class="counter-pill green" style="margin-left: auto;" id="selectedCounter">
                    <i class="fas fa-check"></i> <span id="selectedCountText"><?php echo count($students); ?></span> selected
                </span>
            </div>

            <!-- Student list -->
            <div class="student-list-box">
                <?php $i = 1; foreach ($students as $s): ?>
                    <div class="student-row" data-row-id="<?php echo (int)$s['student_class_id']; ?>">
                        <div class="student-checkbox-wrap">
                            <input type="checkbox" class="student-checkbox" name="student_ids[]"
                                   value="<?php echo (int)$s['student_class_id']; ?>"
                                   data-student-name="<?php echo htmlspecialchars($s['name'], ENT_QUOTES); ?>"
                                   checked>
                        </div>
                        <div class="student-meta">
                            <div class="student-name"><?php echo htmlspecialchars($s['name']); ?></div>
                            <div class="student-sub">
                                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($s['father_name']); ?></span>
                                <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($s['mobile'] ?: '—'); ?></span>
                                <span><i class="fas fa-tag"></i> <?php echo htmlspecialchars($s['reg_no']); ?></span>
                            </div>
                        </div>
                        <span class="student-idx"><?php echo $i++; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Step 3: Target class -->
        <div class="card-block">
            <h3><i class="fas fa-arrow-right"></i> Step 3 — Where are they going?</h3>

            <div class="promotion-target">
                <div class="label-line"><i class="fas fa-calendar-day"></i> Promotion Date</div>
                <div class="form-group" style="margin: 0;">
                    <input type="date" class="form-control" name="promotion_date" id="promotion_date"
                           value="<?php echo date('Y-m-d'); ?>" required>
                </div>
            </div>

            <div class="row" style="margin-top: 14px;">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>New Session <span style="color:#dc2626;">*</span></label>
                        <select class="form-control" name="new_session_id" id="new_session_id" required>
                            <option value="">— Select Session —</option>
                            <?php foreach ($sessions as $s): ?>
                                <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>New Class <span style="color:#dc2626;">*</span></label>
                        <select class="form-control" name="new_class_id" id="new_class_id" required>
                            <option value="">— Select Class —</option>
                            <?php foreach ($classes as $cid => $ctitle): ?>
                                <option value="<?php echo (int)$cid; ?>"><?php echo htmlspecialchars($ctitle); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div style="display:flex; gap: 10px; flex-wrap: wrap; margin-top: 20px; padding-top: 16px; border-top: 1px solid #e2e8f0;">
                <button type="button" class="btn-warn-brand" id="btnValidate">
                    <i class="fas fa-clipboard-check"></i> Validate
                </button>
                <button type="button" class="btn-info-brand" id="btnSummary">
                    <i class="fas fa-list-alt"></i> Show Summary
                </button>
                <button type="submit" class="btn-success-brand" id="promoteBtn" style="margin-left: auto;">
                    <i class="fas fa-arrow-up"></i> Promote Selected
                </button>
            </div>
        </div>
    </form>

    <?php elseif ($current_session_id > 0 && $current_class_id > 0): ?>
        <div class="card-block">
            <div class="empty-state">
                <div class="big"><i class="fas fa-user-slash"></i></div>
                <h5>No active students in this class</h5>
                <p>Nobody is currently enrolled in the selected session and class.</p>
            </div>
        </div>
    <?php endif; ?>

</div>

<?php if ($current_session_id > 0 && $current_class_id > 0 && !empty($students)): ?>
<script>
(function() {
    var TOTAL_STUDENTS = <?php echo count($students); ?>;
    var CURRENT_SESSION = <?php echo (int)$current_session_id; ?>;
    var CURRENT_CLASS = <?php echo (int)$current_class_id; ?>;

    var $checkboxes = $('.student-checkbox');
    var $rows = $('.student-row');

    function currentMode() {
        return $('input[name="promotion_mode"]:checked').val();
    }

    function applyMode() {
        var mode = currentMode();
        if (mode === 'all') {
            $('#selectAllBar').slideUp(120);
            $checkboxes.prop('checked', true).prop('disabled', true);
            $rows.removeClass('unchecked');
        } else {
            $('#selectAllBar').slideDown(120);
            $checkboxes.prop('disabled', false);
            updateRowStyle();
        }
        updateCounter();
    }

    function updateRowStyle() {
        $rows.each(function() {
            var $cb = $(this).find('.student-checkbox');
            $(this).toggleClass('unchecked', !$cb.is(':checked'));
        });
    }

    function updateCounter() {
        var n = $checkboxes.filter(':checked').length;
        $('#selectedCountText').text(n);
        $('#selectAllCheckbox').prop('checked', n === TOTAL_STUDENTS);
        $('#selectAllCheckbox').prop('indeterminate', n > 0 && n < TOTAL_STUDENTS);
    }

    $('input[name="promotion_mode"]').on('change', applyMode);
    $checkboxes.on('change', function() { updateRowStyle(); updateCounter(); });

    $('#selectAllCheckbox').on('change', function() {
        var checked = $(this).is(':checked');
        $checkboxes.prop('checked', checked);
        updateRowStyle();
        updateCounter();
    });

    /* Initial state */
    applyMode();

    /* Validate button */
    $('#btnValidate').on('click', function() {
        var newSession = $('#new_session_id').val();
        var newClass = $('#new_class_id').val();
        var promoDate = $('#promotion_date').val();
        var selected = $checkboxes.filter(':checked').length;

        if (!newSession || !newClass || !promoDate) {
            alert('Please fill New Session, New Class, and Promotion Date.');
            return;
        }
        if (selected === 0) {
            alert('No students selected for promotion.');
            return;
        }

        var msg = 'PROMOTION VALIDATION\n\n';
        msg += 'Total students in class: ' + TOTAL_STUDENTS + '\n';
        msg += 'Students to promote: ' + selected + '\n';
        msg += 'Students staying: ' + (TOTAL_STUDENTS - selected) + '\n\n';
        msg += 'From: Session ' + CURRENT_SESSION + ', Class ' + CURRENT_CLASS + '\n';
        msg += 'To:   Session ' + newSession + ', Class ' + newClass + '\n';
        msg += 'Date: ' + promoDate + '\n';
        alert(msg);
    });

    /* Summary button */
    $('#btnSummary').on('click', function() {
        var selected = $checkboxes.filter(':checked').length;
        var leftOut = [];
        $checkboxes.not(':checked').each(function() {
            leftOut.push($(this).data('student-name'));
        });

        var msg = 'PROMOTION SUMMARY\n\n';
        msg += 'Total: ' + TOTAL_STUDENTS + '\n';
        msg += 'Promoting: ' + selected + '\n';
        msg += 'Staying in current class: ' + (TOTAL_STUDENTS - selected) + '\n\n';

        if (leftOut.length > 0) {
            msg += 'Students NOT being promoted:\n';
            for (var i = 0; i < leftOut.length; i++) {
                msg += '  ' + (i + 1) + '. ' + leftOut[i] + '\n';
            }
        } else {
            msg += 'All students will be promoted.';
        }
        alert(msg);
    });

    /* Submit confirmation */
    $('#promotionForm').on('submit', function(e) {
        var newSession = $('#new_session_id').val();
        var newClass = $('#new_class_id').val();
        var promoDate = $('#promotion_date').val();
        var selected = $checkboxes.filter(':checked').length;

        if (!newSession || !newClass || !promoDate) {
            e.preventDefault();
            alert('Please fill New Session, New Class, and Promotion Date.');
            return;
        }
        if (selected === 0) {
            e.preventDefault();
            alert('No students selected for promotion.');
            return;
        }

        var msg = 'CONFIRM PROMOTION\n\n';
        msg += 'Students to promote: ' + selected + '\n';
        msg += 'From: Session ' + CURRENT_SESSION + ', Class ' + CURRENT_CLASS + '\n';
        msg += 'To:   Session ' + newSession + ', Class ' + newClass + '\n';
        msg += 'Date: ' + promoDate + '\n\n';
        msg += 'This action cannot be undone.\n\nContinue?';

        if (!confirm(msg)) e.preventDefault();
    });
})();
</script>
<?php endif; ?>

</body>
</html>
<?php $conn->close(); ?>