<?php
ob_start();
header('Content-Type: text/html; charset=utf-8');
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

require_once('conn_inc.php');

$csrf = $_SESSION['csrf_token'];

/* ═══════════════════════════════════════════════════════════
   POST — save arrangement(s)
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_arrangement') {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf) {
        $_SESSION['message'] = 'Invalid request token.';
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF']); exit;
    }

    $exam_type_id = (int)($_POST['exam_type_id'] ?? 0);
    $exam_kind    = in_array($_POST['exam_kind'] ?? '', array('test','term'), true) ? $_POST['exam_kind'] : 'term';
    $start_date   = trim($_POST['start_date'] ?? '');
    $class_ids    = isset($_POST['class_ids']) && is_array($_POST['class_ids'])
                    ? array_map('intval', $_POST['class_ids']) : array();
    $session_id   = (int)($_POST['session_id'] ?? 0);

    $errors = array();
    if ($exam_type_id <= 0) $errors[] = 'Exam type is required.';
    if ($start_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) $errors[] = 'Valid start date is required.';
    if (empty($class_ids)) $errors[] = 'Pick at least one class.';

    if (!empty($errors)) {
        $_SESSION['message'] = implode(' ', $errors);
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF']); exit;
    }

    if ($session_id === 0) {
        $r = $conn->query("SELECT id FROM sessions WHERE status = 0 ORDER BY id DESC LIMIT 1")->fetch_assoc();
        if ($r) $session_id = (int)$r['id'];
    }

    $created = 0; $skipped = 0; $failed = 0;
    $last_id = 0;

    foreach ($class_ids as $cid) {
        if ($cid <= 0) continue;

        $chk = $conn->prepare("SELECT id FROM arrange_exam WHERE class_id = ? AND exam_type_id = ? AND start_date = ? LIMIT 1");
        $chk->bind_param("iis", $cid, $exam_type_id, $start_date);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) { $skipped++; $chk->close(); continue; }
        $chk->close();

        $ins = $conn->prepare("INSERT INTO arrange_exam (exam_type_id, exam_kind, session_id, class_id, start_date) VALUES (?, ?, ?, ?, ?)");
        $ins->bind_param("isiss", $exam_type_id, $exam_kind, $session_id, $cid, $start_date);
        if ($ins->execute()) { $created++; $last_id = $ins->insert_id; }
        else { $failed++; error_log("arrange_exam insert failed: " . $ins->error); }
        $ins->close();
    }

    if ($created > 0 && $failed === 0) {
        $_SESSION['message'] = "$created arrangement(s) created. Assign subjects below." . ($skipped ? " $skipped skipped (duplicate)." : "");
        $_SESSION['message_type'] = 'success';
        header("Location: arrange_exam.php?edit=" . $last_id); exit;
    } elseif ($created > 0) {
        $_SESSION['message'] = "$created created, $failed failed, $skipped skipped. Check error log.";
        $_SESSION['message_type'] = 'warning';
        header("Location: arrange_exam_list.php"); exit;
    } else {
        $_SESSION['message'] = "Nothing created. $skipped duplicate(s), $failed failed.";
        $_SESSION['message_type'] = 'warning';
    }
    header("Location: " . $_SERVER['PHP_SELF']); exit;
}

/* ═══════════════════════════════════════════════════════════
   EDIT MODE
   ═══════════════════════════════════════════════════════════ */
$edit_id = (int)($_GET['edit'] ?? 0);
$edit = null;

if ($edit_id > 0) {
    $stmt = $conn->prepare("SELECT ae.*, et.title AS exam_type_title, c.title AS class_title FROM arrange_exam ae INNER JOIN exam_types et ON et.id = ae.exam_type_id INNER JOIN classes c ON c.id = ae.class_id WHERE ae.id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $edit = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$edit) { header("Location: arrange_exam_list.php"); exit; }
}

/* ═══════════════════════════════════════════════════════════
   COMMON DROPDOWNS
   ═══════════════════════════════════════════════════════════ */
$sessions = array();
$q = $conn->query("SELECT id, title FROM sessions WHERE status = 0 ORDER BY id DESC");
if ($q) while ($r = $q->fetch_assoc()) $sessions[] = $r;

$exam_types = array('test' => array(), 'term' => array());
$q = $conn->query("SELECT id, title, exam_kind FROM exam_types WHERE status = 0 ORDER BY exam_kind, title");
if ($q) while ($r = $q->fetch_assoc()) $exam_types[$r['exam_kind'] ?: 'term'][] = $r;

$classes = array();
$q = $conn->query("SELECT id, title FROM classes WHERE status = 0 OR status IS NULL ORDER BY title");
if ($q) while ($r = $q->fetch_assoc()) $classes[] = $r;

/* ═══════════════════════════════════════════════════════════
   EDIT MODE — load assigned subjects + catalog
   ═══════════════════════════════════════════════════════════ */
$arr_subjects = array();
$catalog_subjects = array();
$arr_sub_subjects = array();

if ($edit) {
    $stmt = $conn->prepare("SELECT a.id AS arr_sub_id, a.subject_id, a.max_marks, a.display_order, s.title AS subject_title, s.type AS subject_type FROM arrange_exam_subjects a INNER JOIN subjects s ON s.id = a.subject_id WHERE a.arrange_exam_id = ? ORDER BY a.display_order, s.title");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $arr_subjects[(int)$row['subject_id']] = $row;
    $stmt->close();

    if (!empty($arr_subjects)) {
        $ids = array_column($arr_subjects, 'arr_sub_id');
        $in  = implode(',', array_map('intval', $ids));
        $q = $conn->query("SELECT aes.arrange_exam_subject_id, aes.sub_subject_id, aes.max_marks, ss.title AS sub_subject_title FROM arrange_exam_sub_subjects aes INNER JOIN sub_subjects ss ON ss.id = aes.sub_subject_id WHERE aes.arrange_exam_subject_id IN ($in)");
        if ($q) while ($row = $q->fetch_assoc()) {
            $arr_sub_subjects[(int)$row['arrange_exam_subject_id']][(int)$row['sub_subject_id']] = $row;
        }
    }

    $q = $conn->query("SELECT id, title, type FROM subjects WHERE status = 0 OR status IS NULL ORDER BY title");
    if ($q) while ($row = $q->fetch_assoc()) {
        $row['sub_subjects'] = array();
        if ($row['type'] === 'composite') {
            $q2 = $conn->query("SELECT id, title FROM sub_subjects WHERE subject_id = " . (int)$row['id'] . " AND (status = 0 OR status IS NULL) ORDER BY title");
            if ($q2) while ($s = $q2->fetch_assoc()) $row['sub_subjects'][] = $s;
        }
        $catalog_subjects[(int)$row['id']] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= $edit ? 'Assign Subjects' : 'Arrange Test / Exam' ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #eef1f5; font-family: 'Inter', sans-serif; color: #1e293b; }
    .wrap { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
    .head { background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); color: #fff; padding: 26px 32px; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: flex-end; gap: 20px; flex-wrap: wrap; }
    .head h1 { font-size: 24px; font-weight: 700; }
    .head .sub { font-size: 13px; opacity: 0.9; margin-top: 6px; }
    .head .hb { display: inline-flex; align-items: center; gap: 6px; padding: 9px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; text-decoration: none; cursor: pointer; border: 1px solid rgba(255,255,255,0.3); background: rgba(255,255,255,0.15); color: #fff; font-family: inherit; }
    .head .hb:hover { background: #fff; color: #1e40af; border-color: #fff; text-decoration: none; }
    .card { background: #fff; padding: 26px 32px 32px 32px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
    .sec { font-size: 12px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 2px; margin: 0 0 16px 0; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin-bottom: 18px; }
    .field label { display: block; font-size: 11px; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px; }
    .field select, .field input { width: 100%; height: 44px; padding: 0 12px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 14px; font-family: inherit; background: #fff; }
    .field select:focus, .field input:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 4px rgba(30,64,175,0.1); }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 44px; padding: 0 22px; border-radius: 10px; font-size: 14px; font-weight: 700; font-family: inherit; cursor: pointer; border: 1px solid; text-decoration: none; white-space: nowrap; }
    .btn-primary { background: #1e40af; color: #fff; border-color: #1e40af; }
    .btn-primary:hover { background: #1e3a8a; color: #fff; text-decoration: none; }
    .btn-success { background: #059669; color: #fff; border-color: #059669; }
    .btn-success:hover { background: #047857; color: #fff; text-decoration: none; }
    .btn-ghost { background: #fff; color: #334155; border-color: #cbd5e1; }
    .btn-ghost:hover { background: #f1f5f9; color: #1e293b; text-decoration: none; }
    .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; border-left: 4px solid; }
    .alert.success { background: #d1fae5; border-color: #059669; color: #065f46; }
    .alert.danger  { background: #fee2e2; border-color: #dc2626; color: #991b1b; }
    .alert.warning { background: #fef3c7; border-color: #d97706; color: #92400e; }
    .pill { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; }
    .pill.test { background: #fef3c7; color: #92400e; }
    .pill.term { background: #dbeafe; color: #1e40af; }
    .pill.muted { background: #f1f5f9; color: #64748b; }
    .subj-row { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-bottom: 1px solid #f1f5f9; }
    .subj-row:last-child { border-bottom: none; }
    .subj-row label { font-size: 13px; font-weight: 600; color: #0f172a; flex: 1; cursor: pointer; margin: 0; }
    .subj-row input[type=checkbox] { width: 18px; height: 18px; accent-color: #1e40af; cursor: pointer; }
    .subj-row input[type=number] { width: 90px; height: 36px; padding: 0 8px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; text-align: right; }
    .subj-children { padding-left: 32px; background: #f8fafc; }
    .subj-child-row { display: flex; align-items: center; gap: 10px; padding: 6px 10px; font-size: 12px; border-bottom: 1px dashed #e2e8f0; }
    .subj-child-row:last-child { border-bottom: none; }
    .subj-child-row label { flex: 1; font-weight: 500; color: #475569; cursor: pointer; margin: 0; }
    .subj-child-row input[type=number] { width: 80px; height: 32px; padding: 0 8px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 12px; text-align: right; }
    .empty { padding: 40px 20px; text-align: center; color: #94a3b8; }
    .empty .big { font-size: 40px; opacity: 0.4; margin-bottom: 10px; }
    .sticky-save { position: sticky; bottom: 0; background: #fff; border-top: 2px solid #e2e8f0; padding: 14px 0 4px 0; margin-top: 18px; display: flex; justify-content: flex-end; gap: 10px; }
    .actions { display: flex; justify-content: flex-end; gap: 10px; padding-top: 16px; border-top: 1px solid #e2e8f0; margin-top: 16px; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">
    <div class="head">
        <div>
            <h1><i class="fas fa-calendar-plus"></i> <?= $edit ? 'Assign Subjects' : 'Arrange Test / Exam' ?></h1>
            <div class="sub">
                <?php if ($edit): ?>
                    <span class="pill <?= htmlspecialchars($edit['exam_kind']) ?>"><?= htmlspecialchars($edit['exam_type_title']) ?></span>
                    &nbsp; <strong><?= htmlspecialchars($edit['class_title']) ?></strong>
                    &nbsp;&middot;&nbsp; <?= date('d M Y', strtotime($edit['start_date'])) ?>
                <?php else: ?>
                    Create a test or term exam, then assign its subjects
                <?php endif; ?>
            </div>
        </div>
        <div>
            <a href="arrange_exam_list.php" class="hb"><i class="fas fa-list"></i> All Arrangements</a>
        </div>
    </div>

    <div class="card">

        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert <?= htmlspecialchars($_SESSION['message_type']) ?>"><?= htmlspecialchars($_SESSION['message']) ?></div>
            <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
        <?php endif; ?>

        <?php if ($edit): ?>
            <!-- ══════════════════ EDIT MODE ══════════════════ -->
            <div class="sec">Arrangement Details</div>
            <form method="post" style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="save_arrangement">
                <input type="hidden" name="edit_id" value="<?= (int)$edit['id'] ?>">
                <input type="hidden" name="class_ids[]" value="<?= (int)$edit['class_id'] ?>">

                <div class="field" style="min-width:220px;">
                    <label>Exam Type</label>
                    <select name="exam_type_id" required>
                        <?php foreach (array('test','term') as $k): ?>
                            <optgroup label="<?= $k === 'test' ? 'Tests' : 'Term Exams' ?>">
                            <?php foreach ($exam_types[$k] as $et): ?>
                                <option value="<?= (int)$et['id'] ?>" <?= (int)$et['id'] === (int)$edit['exam_type_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($et['title']) ?>
                                </option>
                            <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field" style="min-width:180px;">
                    <label>Kind</label>
                    <select name="exam_kind" required>
                        <option value="test" <?= $edit['exam_kind'] === 'test' ? 'selected' : '' ?>>Test</option>
                        <option value="term" <?= $edit['exam_kind'] === 'term' ? 'selected' : '' ?>>Term</option>
                    </select>
                </div>
                <div class="field" style="min-width:180px;">
                    <label>Start Date</label>
                    <input type="date" name="start_date" required value="<?= htmlspecialchars($edit['start_date']) ?>">
                </div>
                <div class="field" style="min-width:180px;">
                    <label>Session</label>
                    <select name="session_id" required>
                        <?php foreach ($sessions as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === (int)$edit['session_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (!empty($perms['can_edit'])): ?>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Update</button>
                <?php endif; ?>
            </form>

            <div class="sec" style="margin-top:30px;">
                Subjects in this Arrangement
                <span id="subjCountBadge" class="pill term"><?= count($arr_subjects) ?> assigned</span>
            </div>

            <div id="subjectPicker">
                <?php if (empty($catalog_subjects)): ?>
                    <div class="empty">
                        <div class="big">&#128218;</div>
                        <h3>No subjects exist in the catalog</h3>
                        <p>Add subjects at <a href="subjects.php">Subjects</a> first.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($catalog_subjects as $sid => $subj): ?>
                        <?php
                            $isIn     = isset($arr_subjects[$sid]);
                            $arrId    = $isIn ? (int)$arr_subjects[$sid]['arr_sub_id'] : 0;
                            $maxMarks = $isIn ? (float)$arr_subjects[$sid]['max_marks'] : 100;
                            $isComp   = $subj['type'] === 'composite';
                        ?>
                        <div class="subj-row" data-subject-id="<?= (int)$sid ?>" data-arr-sub-id="<?= $arrId ?>">
                            <input type="checkbox" class="subj-check" <?= $isIn ? 'checked' : '' ?>>
                            <label><?= htmlspecialchars($subj['title']) ?>
                                <?php if ($isComp): ?><span class="pill muted" style="margin-left:6px;">composite</span><?php endif; ?>
                            </label>
                            <span style="font-size:11px;color:#64748b;">Max</span>
                            <input type="number" class="subj-max" min="0" max="9999" step="0.5" value="<?= $maxMarks ?>" <?= $isIn ? '' : 'disabled' ?>>
                        </div>

                        <?php if ($isComp && !empty($subj['sub_subjects'])): ?>
                            <div class="subj-children" data-parent-subject="<?= (int)$sid ?>" <?= $isIn ? '' : 'style="display:none;"' ?>>
                                <?php foreach ($subj['sub_subjects'] as $ss):
                                    $ssIn = $isIn && isset($arr_sub_subjects[$arrId][(int)$ss['id']]);
                                    $ssMax = $ssIn ? (float)$arr_sub_subjects[$arrId][(int)$ss['id']]['max_marks'] : 0;
                                ?>
                                    <div class="subj-child-row" data-sub-subject-id="<?= (int)$ss['id'] ?>">
                                        <input type="checkbox" class="ss-check" <?= $ssIn ? 'checked' : '' ?> <?= $isIn ? '' : 'disabled' ?>>
                                        <label><?= htmlspecialchars($ss['title']) ?></label>
                                        <span style="font-size:11px;color:#64748b;">Max</span>
                                        <input type="number" class="ss-max" min="0" max="9999" step="0.5" value="<?= $ssMax ?>" <?= $ssIn ? '' : 'disabled' ?>>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <div class="sticky-save">
                        <span id="saveStatus" style="font-size:12px;color:#64748b;align-self:center;margin-right:auto;"></span>
                        <?php if (!empty($perms['can_edit']) || !empty($perms['can_add'])): ?>
                            <button type="button" class="btn btn-success" id="btnSaveSubjects">
                                <i class="fas fa-save"></i> Save Subjects
                            </button>
                        <?php endif; ?>
                        <a href="arrange_exam_list.php" class="btn btn-ghost">Done</a>
                    </div>
                <?php endif; ?>
            </div>

            <script>
            var ARRANGE_ID = <?= (int)$edit['id'] ?>;
            var CSRF       = <?= json_encode($csrf) ?>;

            document.querySelectorAll('.subj-row').forEach(function(row){
                var chk = row.querySelector('.subj-check');
                var max = row.querySelector('.subj-max');
                var sid = row.dataset.subjectId;
                var child = document.querySelector('.subj-children[data-parent-subject="'+sid+'"]');

                function apply(){
                    max.disabled = !chk.checked;
                    if (child) {
                        child.style.display = chk.checked ? '' : 'none';
                        child.querySelectorAll('.ss-check').forEach(function(sc){ sc.disabled = !chk.checked; });
                        child.querySelectorAll('.ss-max').forEach(function(sm){ sm.disabled = !sc_checked(sm); });
                    }
                    updateBadge();
                }
                function sc_checked(sm){
                    var r = sm.closest('.subj-child-row');
                    return r.querySelector('.ss-check').checked;
                }
                chk.addEventListener('change', apply);
            });

            document.querySelectorAll('.subj-child-row').forEach(function(r){
                var sc = r.querySelector('.ss-check');
                var sm = r.querySelector('.ss-max');
                sc.addEventListener('change', function(){ sm.disabled = !sc.checked; });
            });

            function updateBadge(){
                var n = 0;
                document.querySelectorAll('.subj-row').forEach(function(r){
                    if (r.querySelector('.subj-check').checked) n++;
                });
                var b = document.getElementById('subjCountBadge');
                if (b) b.textContent = n + ' assigned';
            }
            updateBadge();

            document.getElementById('btnSaveSubjects').addEventListener('click', function(){
                var btn = this;
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                document.getElementById('saveStatus').textContent = '';

                var payload = { csrf_token: CSRF, arrange_exam_id: ARRANGE_ID, subjects: [] };
                document.querySelectorAll('.subj-row').forEach(function(row){
                    if (!row.querySelector('.subj-check').checked) return;
                    var sid = parseInt(row.dataset.subjectId);
                    var max = parseFloat(row.querySelector('.subj-max').value || 0);
                    var subSubs = [];
                    var child = document.querySelector('.subj-children[data-parent-subject="'+sid+'"]');
                    if (child) {
                        child.querySelectorAll('.subj-child-row').forEach(function(cr){
                            if (cr.querySelector('.ss-check').checked) {
                                subSubs.push({
                                    sub_subject_id: parseInt(cr.dataset.subSubjectId),
                                    max_marks: parseFloat(cr.querySelector('.ss-max').value || 0)
                                });
                            }
                        });
                    }
                    payload.subjects.push({ subject_id: sid, max_marks: max, sub_subjects: subSubs });
                });

                jQuery.ajax({
                    url: 'arrange_subjects_save.php',
                    type: 'POST',
                    dataType: 'json',
                    data: JSON.stringify(payload),
                    contentType: 'application/json',
                    success: function(r){
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-save"></i> Save Subjects';
                        if (r.success) {
                            document.getElementById('saveStatus').innerHTML =
                                '<span style="color:#059669;font-weight:600;"><i class="fas fa-check"></i> ' +
                                (r.saved_count || 0) + ' subject(s) saved at ' + new Date().toLocaleTimeString() + '</span>';
                            updateBadge();
                        } else {
                            document.getElementById('saveStatus').innerHTML =
                                '<span style="color:#dc2626;font-weight:600;"><i class="fas fa-times"></i> ' +
                                (r.message || 'Save failed.') + '</span>';
                        }
                    },
                    error: function(x, s, e){
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-save"></i> Save Subjects';
                        document.getElementById('saveStatus').innerHTML =
                            '<span style="color:#dc2626;font-weight:600;">Network error: ' + (e || 'unknown') + '</span>';
                    }
                });
            });
            </script>

        <?php else: ?>
            <!-- ══════════════════ CREATE MODE ══════════════════ -->
            <div class="sec">Create New Arrangement</div>

            <form method="post" id="createForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="save_arrangement">
                <div class="grid">
                    <div class="field">
                        <label>Kind <span style="color:#dc2626;">*</span></label>
                        <select name="exam_kind" id="kindSelect" required>
                            <option value="term">Term Exam</option>
                            <option value="test">Test</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Exam Type <span style="color:#dc2626;">*</span></label>
                        <select name="exam_type_id" id="examTypeSelect" required>
                            <option value="">— Select —</option>
                            <?php foreach (array('test','term') as $k): ?>
                                <optgroup label="<?= $k === 'test' ? 'Tests' : 'Term Exams' ?>" data-kind="<?= $k ?>">
                                    <?php foreach ($exam_types[$k] as $et): ?>
                                        <option value="<?= (int)$et['id'] ?>" data-kind="<?= $k ?>"><?= htmlspecialchars($et['title']) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Start Date <span style="color:#dc2626;">*</span></label>
                        <input type="date" name="start_date" required value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="field">
                        <label>Session</label>
                        <select name="session_id">
                            <?php foreach ($sessions as $s): ?>
                                <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="field" style="margin-bottom:22px;">
                    <label>Classes <span style="color:#dc2626;">*</span> (one arrangement per class — pick one or many)</label>
                    <select name="class_ids[]" id="classSelect" multiple required style="width:100%;">
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="actions">
                    <a href="arrange_exam_list.php" class="btn btn-ghost">Cancel</a>
                    <?php if (!empty($perms['can_add'])): ?>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Create & Assign Subjects</button>
                    <?php endif; ?>
                </div>
            </form>
        <?php endif; ?>

    </div>
</div>

<script>
$(document).ready(function(){
    if ($('#classSelect').length) {
        $('#classSelect').select2({ placeholder: 'Select one or more classes', width: '100%' });
    }

    function filterExamTypes(){
        var kind = $('#kindSelect').val();
        $('#examTypeSelect option').each(function(){
            var k = $(this).data('kind');
            if (!k) return;
            if (k === kind) $(this).show().prop('disabled', false);
            else $(this).hide().prop('disabled', true);
        });
        var sel = $('#examTypeSelect').find('option:selected');
        if (sel.data('kind') && sel.data('kind') !== kind) {
            $('#examTypeSelect').val('');
        }
    }
    if ($('#kindSelect').length) {
        $('#kindSelect').on('change', filterExamTypes);
        filterExamTypes();
    }

    setTimeout(function(){ $('.alert').fadeOut('slow'); }, 6000);
});
</script>

</body>
</html>
<?php $conn->close(); ob_end_flush(); ?>