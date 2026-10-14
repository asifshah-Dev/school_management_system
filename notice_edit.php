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
require_once('notice_helper.php');

$csrf = $_SESSION['csrf_token'];
$uid = (int)$_SESSION['user_id'];
$errors = array();

/* ─────────── Load notice ─────────── */
$notice_id = (int)($_GET['id'] ?? 0);
if ($notice_id <= 0) {
    $_SESSION['message'] = 'Invalid notice.';
    $_SESSION['message_type'] = 'danger';
    header('Location: notice_list.php');
    exit;
}

$stmt = $conn->prepare("SELECT * FROM notices WHERE id = ?");
$stmt->bind_param("i", $notice_id);
$stmt->execute();
$notice = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$notice) {
    $_SESSION['message'] = 'Notice not found.';
    $_SESSION['message_type'] = 'danger';
    header('Location: notice_list.php');
    exit;
}

/* ─────────── Dropdowns ─────────── */
$classes = array();
$q = $conn->query("SELECT id, title FROM classes WHERE status = 0 OR status IS NULL ORDER BY title");
if ($q) while ($r = $q->fetch_assoc()) $classes[] = $r;

$students = array();
$q = $conn->query("SELECT sr.id, sr.name, sr.reg_no FROM student_registration sr WHERE sr.status = 0 ORDER BY sr.name LIMIT 500");
if ($q) while ($r = $q->fetch_assoc()) $students[] = $r;

/* ─────────── Form values (POST takes priority, else current row) ─────────── */
$form = array(
    'title'        => $notice['title'],
    'body'         => $notice['body'],
    'audience'     => $notice['audience'],
    'priority'     => $notice['priority'],
    'class_id'     => (int)$notice['class_id'],
    'student_id'   => (int)$notice['student_id'],
    'publish_from' => $notice['publish_from'],
    'publish_to'   => $notice['publish_to'],
);

/* ─────────── Handle POST ─────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (($_POST['csrf_token'] ?? '') !== $csrf) {
        $errors[] = 'Invalid request token.';
    } else {
        $form['title']        = trim($_POST['title'] ?? '');
        $form['body']         = trim($_POST['body'] ?? '');
        $form['audience']     = in_array($_POST['audience'] ?? '', array('all','parents','staff','class','student'), true) ? $_POST['audience'] : 'all';
        $form['priority']     = in_array($_POST['priority'] ?? '', array('normal','high','urgent'), true) ? $_POST['priority'] : 'normal';
        $form['class_id']     = (int)($_POST['class_id'] ?? 0);
        $form['student_id']   = (int)($_POST['student_id'] ?? 0);
        $form['publish_from'] = trim($_POST['publish_from'] ?? '');
        $form['publish_to']   = trim($_POST['publish_to'] ?? '');

        /* Normalize datetime-local input to MySQL datetime */
        if ($form['publish_from'] !== '' && strpos($form['publish_from'], 'T') !== false) {
            $form['publish_from'] = str_replace('T', ' ', $form['publish_from']);
            if (strlen($form['publish_from']) === 16) $form['publish_from'] .= ':00';
        }
        if ($form['publish_to'] !== '' && strpos($form['publish_to'], 'T') !== false) {
            $form['publish_to'] = str_replace('T', ' ', $form['publish_to']);
            if (strlen($form['publish_to']) === 16) $form['publish_to'] .= ':00';
        }

        if ($form['title'] === '') $errors[] = 'Title is required.';
        if ($form['body'] === '')  $errors[] = 'Body is required.';
        if ($form['audience'] === 'class' && $form['class_id'] <= 0) $errors[] = 'Pick a class for class audience.';
        if ($form['audience'] === 'student' && $form['student_id'] <= 0) $errors[] = 'Pick a student for student audience.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $form['publish_from'])) $errors[] = 'Invalid publish-from date.';
        if ($form['publish_to'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $form['publish_to'])) $errors[] = 'Invalid publish-to date.';

        if (empty($errors)) {
            $cid = ($form['audience'] === 'class')   ? $form['class_id']   : null;
            $sid = ($form['audience'] === 'student') ? $form['student_id'] : null;
            $pto = ($form['publish_to'] === '')      ? null               : $form['publish_to'];

            $stmt = $conn->prepare("UPDATE notices SET title = ?, body = ?, audience = ?, class_id = ?, student_id = ?, priority = ?, publish_from = ?, publish_to = ? WHERE id = ?");
            $stmt->bind_param("sssiisssi",
                $form['title'],
                $form['body'],
                $form['audience'],
                $cid,
                $sid,
                $form['priority'],
                $form['publish_from'],
                $pto,
                $notice_id
            );

            if ($stmt->execute()) {
                $stmt->close();
                $_SESSION['message'] = 'Notice updated successfully.';
                $_SESSION['message_type'] = 'success';
                header('Location: notice_view.php?id=' . $notice_id);
                exit;
            } else {
                $errors[] = 'Update failed: ' . $stmt->error;
                $stmt->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Edit Notice</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #eef1f5; font-family: 'Inter', sans-serif; color: #1e293b; }
    .wrap { max-width: 900px; margin: 30px auto; padding: 0 20px; }
    .head { background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); color: #fff; padding: 26px 32px; border-radius: 12px 12px 0 0; }
    .head h1 { font-size: 24px; font-weight: 700; }
    .head .sub { font-size: 13px; opacity: 0.9; margin-top: 6px; }
    .card { background: #fff; padding: 26px 32px 32px 32px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
    .field { margin-bottom: 18px; }
    .field label { display: block; font-size: 11px; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px; }
    .field input, .field select, .field textarea { width: 100%; padding: 12px 14px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 14px; font-family: inherit; background: #fff; }
    .field textarea { min-height: 180px; resize: vertical; line-height: 1.6; }
    .field input:focus, .field select:focus, .field textarea:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 4px rgba(30,64,175,0.1); }
    .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 46px; padding: 0 22px; border-radius: 10px; font-size: 14px; font-weight: 700; font-family: inherit; cursor: pointer; border: 1px solid; text-decoration: none; }
    .btn-primary { background: #1e40af; color: #fff; border-color: #1e40af; }
    .btn-primary:hover { background: #1e3a8a; color: #fff; text-decoration: none; }
    .btn-ghost { background: #fff; color: #334155; border-color: #cbd5e1; }
    .btn-ghost:hover { background: #f1f5f9; color: #1e293b; text-decoration: none; }
    .btn-warning { background: #d97706; color: #fff; border-color: #d97706; }
    .btn-warning:hover { background: #b45309; color: #fff; text-decoration: none; }
    .actions { display: flex; justify-content: flex-end; gap: 10px; padding-top: 18px; border-top: 1px solid #e2e8f0; margin-top: 24px; }
    .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; border-left: 4px solid; }
    .alert.danger  { background: #fee2e2; border-color: #dc2626; color: #991b1b; }
    .alert.success { background: #d1fae5; border-color: #059669; color: #065f46; }
    .hidden-aud { display: none; }
    .topbar { background: #fff; padding: 14px 32px; border-radius: 12px 12px 0 0; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
    .topbar a { color: #1e40af; text-decoration: none; font-size: 13px; font-weight: 700; }
    .topbar a:hover { text-decoration: underline; }
    .topbar .meta { font-size: 12px; color: #64748b; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="topbar">
        <a href="notice_list.php"><i class="fas fa-arrow-left"></i> Back to Notices</a>
        <span class="meta">
            Created <?= date('d M Y · h:i A', strtotime($notice['created_at'])) ?>
            · Last updated <?= date('d M Y · h:i A', strtotime($notice['updated_at'])) ?>
        </span>
    </div>

    <div class="head">
        <h1><i class="fas fa-pencil"></i> Edit Notice</h1>
        <div class="sub">Update the notice — changes take effect immediately</div>
    </div>

    <div class="card">
        <?php if (!empty($errors)): ?>
            <div class="alert danger"><?= implode(' ', array_map('htmlspecialchars', $errors)) ?></div>
        <?php endif; ?>

        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert <?= htmlspecialchars($_SESSION['message_type']) ?>"><?= htmlspecialchars($_SESSION['message']) ?></div>
            <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

            <div class="field">
                <label>Title <span style="color:#dc2626;">*</span></label>
                <input type="text" name="title" required maxlength="255"
                       value="<?= htmlspecialchars($form['title']) ?>"
                       placeholder="e.g. Parent-Teacher Meeting">
            </div>

            <div class="field">
                <label>Body <span style="color:#dc2626;">*</span></label>
                <textarea name="body" required placeholder="Write the full notice text here..."><?= htmlspecialchars($form['body']) ?></textarea>
            </div>

            <div class="row2">
                <div class="field">
                    <label>Audience <span style="color:#dc2626;">*</span></label>
                    <select name="audience" id="audienceSelect" required onchange="toggleAudience()">
                        <option value="all"     <?= $form['audience'] === 'all'     ? 'selected' : '' ?>>Everyone</option>
                        <option value="parents" <?= $form['audience'] === 'parents' ? 'selected' : '' ?>>Parents Only</option>
                        <option value="staff"   <?= $form['audience'] === 'staff'   ? 'selected' : '' ?>>Staff Only</option>
                        <option value="class"   <?= $form['audience'] === 'class'   ? 'selected' : '' ?>>Specific Class</option>
                        <option value="student" <?= $form['audience'] === 'student' ? 'selected' : '' ?>>Specific Student</option>
                    </select>
                </div>

                <div class="field">
                    <label>Priority <span style="color:#dc2626;">*</span></label>
                    <select name="priority" required>
                        <option value="normal" <?= $form['priority'] === 'normal' ? 'selected' : '' ?>>Normal</option>
                        <option value="high"   <?= $form['priority'] === 'high'   ? 'selected' : '' ?>>High</option>
                        <option value="urgent" <?= $form['priority'] === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                    </select>
                </div>
            </div>

            <div class="row2">
                <div class="field <?= $form['audience'] === 'class' ? '' : 'hidden-aud' ?>" id="classField">
                    <label>Class</label>
                    <select name="class_id">
                        <option value="">— Select class —</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= ((int)$form['class_id'] === (int)$c['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field <?= $form['audience'] === 'student' ? '' : 'hidden-aud' ?>" id="studentField">
                    <label>Student</label>
                    <select name="student_id">
                        <option value="">— Select student —</option>
                        <?php foreach ($students as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= ((int)$form['student_id'] === (int)$s['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['name']) ?><?= !empty($s['reg_no']) ? ' (' . htmlspecialchars($s['reg_no']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row2">
                <div class="field">
                    <label>Publish From</label>
                    <input type="datetime-local" name="publish_from"
                           value="<?= htmlspecialchars(date('Y-m-d\TH:i', strtotime($form['publish_from']))) ?>">
                </div>
                <div class="field">
                    <label>Publish To <span style="color:#94a3b8;font-weight:500;text-transform:none;font-size:11px;">(optional expiry)</span></label>
                    <input type="datetime-local" name="publish_to"
                           value="<?= htmlspecialchars(!empty($form['publish_to']) ? date('Y-m-d\TH:i', strtotime($form['publish_to'])) : '') ?>">
                </div>
            </div>

            <div class="actions">
                <a href="notice_view.php?id=<?= (int)$notice_id ?>" class="btn btn-ghost"><i class="fas fa-times"></i> Cancel</a>
                <?php if (!empty($perms['can_edit']) || !empty($perms['can_add'])): ?>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Save Changes</button>
                <?php endif; ?>
            </div>
        </form>

        <script>
        function toggleAudience(){
            var v = document.getElementById('audienceSelect').value;
            document.getElementById('classField').classList.toggle('hidden-aud', v !== 'class');
            document.getElementById('studentField').classList.toggle('hidden-aud', v !== 'student');
        }
        toggleAudience();
        </script>
    </div>
</div>

</body>
</html>
<?php $conn->close(); ob_end_flush(); ?>