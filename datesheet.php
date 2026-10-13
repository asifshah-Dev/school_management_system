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

function day_name($date) {
    if (!$date) return '';
    $d = (int)date('N', strtotime($date));
    $days = array('', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday');
    return $days[$d];
}

$arrange_exam_id = (int)($_GET['exam_id'] ?? 0);

/* ── No exam_id given → show a picker of arrangements ── */
if ($arrange_exam_id <= 0) {
    $picker_kind = isset($_GET['kind']) && $_GET['kind'] === 'test' ? 'test' : 'term';

    $picker_rows = [];
    $q = $conn->query("
        SELECT ae.id, ae.start_date, ae.exam_kind,
               c.title AS class_title,
               et.title AS exam_type_title,
               (SELECT COUNT(*) FROM arrange_exam_subjects WHERE arrange_exam_id = ae.id) AS subject_count,
               (SELECT COUNT(*) FROM exam_datesheet WHERE arrange_exam_id = ae.id) AS datesheet_count
        FROM arrange_exam ae
        INNER JOIN classes c ON c.id = ae.class_id
        INNER JOIN exam_types et ON et.id = ae.exam_type_id
        WHERE ae.exam_kind = '" . $conn->real_escape_string($picker_kind) . "'
        ORDER BY ae.start_date DESC, c.title ASC
    ");
    if ($q) while ($r = $q->fetch_assoc()) $picker_rows[] = $r;
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="utf-8">
    <title>Datesheet — Pick an Arrangement</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #eef1f5; font-family: 'Inter', sans-serif; color: #1e293b; }
        .wrap { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .head { background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); color: #fff; padding: 26px 32px; border-radius: 12px 12px 0 0; }
        .head h1 { font-size: 24px; font-weight: 700; }
        .head .sub { font-size: 13px; opacity: 0.9; margin-top: 6px; }
        .card { background: #fff; padding: 26px 32px 32px 32px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .tabs { display: flex; gap: 6px; margin-bottom: 20px; }
        .tab { padding: 9px 18px; border-radius: 10px; font-size: 12px; font-weight: 700; border: 1px solid #cbd5e1; background: #fff; color: #334155; text-decoration: none; }
        .tab.active { background: #1e40af; color: #fff; border-color: #1e40af; }
        .tab:hover { text-decoration: none; color: #1e40af; }
        .tab.active:hover { color: #fff; }
        table.g { width: 100%; border-collapse: collapse; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
        table.g thead th { background: #1e3a8a; color: #fff; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 12px; text-align: left; }
        table.g tbody td { padding: 12px; font-size: 13px; border-bottom: 1px solid #f1f5f9; }
        table.g tbody tr:hover td { background: #f8fafc; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 36px; padding: 0 16px; border-radius: 8px; font-size: 12px; font-weight: 700; font-family: inherit; cursor: pointer; border: 1px solid; text-decoration: none; }
        .btn-primary { background: #1e40af; color: #fff; border-color: #1e40af; }
        .btn-primary:hover { background: #1e3a8a; color: #fff; text-decoration: none; }
        .btn-warning { background: #d97706; color: #fff; border-color: #d97706; }
        .btn-warning:hover { background: #b45309; color: #fff; text-decoration: none; }
        .pill { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; }
        .pill.term { background: #dbeafe; color: #1e40af; }
        .pill.test { background: #fef3c7; color: #92400e; }
        .pill.muted { background: #f1f5f9; color: #64748b; }
        .empty { padding: 60px 20px; text-align: center; color: #94a3b8; }
        .empty .big { font-size: 44px; opacity: 0.4; margin-bottom: 10px; }
        .empty h3 { font-size: 17px; color: #475569; font-weight: 600; }
    </style>
    </head>
    <body>
    <?php require_once('navbar.php'); ?>
    <div class="wrap">
        <div class="head">
            <h1><i class="fas fa-calendar-days"></i> Datesheet</h1>
            <div class="sub">Pick an arrangement to edit its datesheet</div>
        </div>
        <div class="card">
            <div class="tabs">
                <a href="?kind=term" class="tab <?= $picker_kind === 'term' ? 'active' : '' ?>"><i class="fas fa-graduation-cap"></i> Term Exams</a>
                <a href="?kind=test" class="tab <?= $picker_kind === 'test' ? 'active' : '' ?>"><i class="fas fa-pen"></i> Tests</a>
            </div>
            <?php if (empty($picker_rows)): ?>
                <div class="empty">
                    <div class="big">&#128197;</div>
                    <h3>No <?= $picker_kind === 'test' ? 'tests' : 'term exams' ?> arranged yet</h3>
                    <p style="margin-top:12px;">
                        <a href="arrange_exam.php" style="color:#1e40af;font-weight:700;">Arrange one first →</a>
                    </p>
                </div>
            <?php else: ?>
                <table class="g">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Class</th>
                            <th>Exam Type</th>
                            <th>Start Date</th>
                            <th style="text-align:center;">Subjects</th>
                            <th style="text-align:center;">Datesheet Entries</th>
                            <th style="text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $i = 1; foreach ($picker_rows as $r): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><strong><?= htmlspecialchars($r['class_title']) ?></strong></td>
                            <td><span class="pill <?= htmlspecialchars($r['exam_kind']) ?>"><?= htmlspecialchars($r['exam_type_title']) ?></span></td>
                            <td><?= date('d M Y', strtotime($r['start_date'])) ?></td>
                            <td style="text-align:center;">
                                <?php if ((int)$r['subject_count'] > 0): ?>
                                    <span class="pill term"><?= (int)$r['subject_count'] ?> assigned</span>
                                <?php else: ?>
                                    <span class="pill muted">none</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <?php if ((int)$r['datesheet_count'] > 0): ?>
                                    <span class="pill term"><?= (int)$r['datesheet_count'] ?> set</span>
                                <?php else: ?>
                                    <span class="pill muted">empty</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;">
                                <a href="datesheet.php?exam_id=<?= (int)$r['id'] ?>" class="btn btn-primary">
                                    <i class="fas fa-arrow-right"></i> Open
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    </body>
    </html>
    <?php
    $conn->close();
    ob_end_flush();
    exit;
}

/* ── exam_id was provided → continue with normal flow ── */
$class_id = 0;

$stmt = $conn->prepare("SELECT ae.*, et.title AS exam_type_title, et.exam_kind, c.title AS class_title, s.title AS session_title FROM arrange_exam ae INNER JOIN exam_types et ON et.id = ae.exam_type_id INNER JOIN classes c ON c.id = ae.class_id LEFT JOIN sessions s ON s.id = ae.session_id WHERE ae.id = ?");
$stmt->bind_param("i", $arrange_exam_id);
$stmt->execute();
$arr = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$arr) {
    $_SESSION['message'] = 'Arrangement not found.';
    $_SESSION['message_type'] = 'danger';
    header('Location: arrange_exam.php');
    exit;
}

$class_id = (int)$arr['class_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'auto_assign') {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf) {
        $_SESSION['message'] = 'Invalid request token.';
        $_SESSION['message_type'] = 'danger';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?exam_id=' . $arrange_exam_id);
        exit;
    }

    $conn->begin_transaction();
    try {
        $sql1 = "INSERT INTO arrange_exam_subjects (arrange_exam_id, subject_id, max_marks, display_order, status)
                 SELECT ?, sc.subject_id, COALESCE(sc.marks, 100), 0, 0
                 FROM subject_class sc
                 WHERE sc.class_id = ?
                   AND NOT EXISTS (
                       SELECT 1 FROM arrange_exam_subjects aes
                       WHERE aes.arrange_exam_id = ? AND aes.subject_id = sc.subject_id
                   )";
        $ins1 = $conn->prepare($sql1);
        $ins1->bind_param("iii", $arrange_exam_id, $class_id, $arrange_exam_id);
        $ins1->execute();
        $added_subjects = $ins1->affected_rows;
        $ins1->close();

        $sql2 = "INSERT INTO arrange_exam_sub_subjects (arrange_exam_subject_id, sub_subject_id, max_marks, display_order)
                 SELECT aes.id, scs.sub_subject_id, COALESCE(scs.marks, 0), 0
                 FROM arrange_exam_subjects aes
                 INNER JOIN subject_class sc ON sc.subject_id = aes.subject_id AND sc.class_id = ?
                 INNER JOIN subject_class_sub scs ON scs.subject_class_id = sc.id
                 WHERE aes.arrange_exam_id = ?
                   AND NOT EXISTS (
                       SELECT 1 FROM arrange_exam_sub_subjects x
                       WHERE x.arrange_exam_subject_id = aes.id
                         AND x.sub_subject_id = scs.sub_subject_id
                   )";
        $ins2 = $conn->prepare($sql2);
        $ins2->bind_param("ii", $class_id, $arrange_exam_id);
        $ins2->execute();
        $added_subs = $ins2->affected_rows;
        $ins2->close();

        $conn->commit();
        $_SESSION['message'] = 'Auto-assigned ' . $added_subjects . ' subject(s) and ' . $added_subs . ' sub-subject(s).';
        $_SESSION['message_type'] = 'success';
    } catch (Throwable $e) {
        $conn->rollback();
        $_SESSION['message'] = 'Auto-assign failed: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?exam_id=' . $arrange_exam_id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_rules') {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf) {
        $_SESSION['message'] = 'Invalid request token.';
        $_SESSION['message_type'] = 'danger';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?exam_id=' . $arrange_exam_id);
        exit;
    }

    $rules = isset($_POST['rules_text']) ? trim($_POST['rules_text']) : '';
    $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

    $stmt = $conn->prepare("INSERT INTO exam_datesheet_rules (arrange_exam_id, rules_text, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE rules_text = VALUES(rules_text), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP()");
    $stmt->bind_param("isi", $arrange_exam_id, $rules, $uid);
    $stmt->execute();
    $stmt->close();

    $_SESSION['message'] = 'Rules saved.';
    $_SESSION['message_type'] = 'success';
    header('Location: ' . $_SERVER['PHP_SELF'] . '?exam_id=' . $arrange_exam_id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_datesheet') {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf) {
        $_SESSION['message'] = 'Invalid request token.';
        $_SESSION['message_type'] = 'danger';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?exam_id=' . $arrange_exam_id);
        exit;
    }

    $dates = isset($_POST['dates']) ? $_POST['dates'] : array();

    $conn->begin_transaction();
    try {
        $ins = $conn->prepare("INSERT INTO exam_datesheet (arrange_exam_id, class_id, subject_id, sub_subject_id, start_date, start_time, end_time, status) VALUES (?, ?, ?, ?, ?, ?, ?, 0)");
        $delExisting = $conn->prepare("DELETE FROM exam_datesheet WHERE arrange_exam_id = ? AND subject_id = ? AND ((sub_subject_id IS NULL AND ? = 0) OR sub_subject_id = ?)");

        $catalog = array();
        $q = $conn->query("SELECT id AS arr_sub_id, subject_id FROM arrange_exam_subjects WHERE arrange_exam_id = " . (int)$arrange_exam_id);
        if ($q) {
            while ($r = $q->fetch_assoc()) {
                $catalog[(int)$r['arr_sub_id']] = (int)$r['subject_id'];
            }
        }

        $subCatalog = array();
        $q = $conn->query("SELECT aes.arrange_exam_subject_id, aes.sub_subject_id FROM arrange_exam_sub_subjects aes INNER JOIN arrange_exam_subjects a ON a.id = aes.arrange_exam_subject_id WHERE a.arrange_exam_id = " . (int)$arrange_exam_id);
        if ($q) {
            while ($r = $q->fetch_assoc()) {
                $subCatalog[(int)$r['arrange_exam_subject_id']][(int)$r['sub_subject_id']] = true;
            }
        }

        $saved = 0;
        foreach ($dates as $arrSubId => $rows) {
            $arrSubId = (int)$arrSubId;
            if ($arrSubId <= 0) continue;
            if (!isset($catalog[$arrSubId])) continue;
            $subjectId = $catalog[$arrSubId];

            foreach ($rows as $ssKey => $d) {
                $ssId = (int)$ssKey;
                $date = isset($d['date'])  ? trim($d['date'])  : '';
                $st   = isset($d['start']) ? trim($d['start']) : '';
                $en   = isset($d['end'])   ? trim($d['end'])   : '';

                if ($date === '' && $st === '' && $en === '') {
                    $delExisting->bind_param("iiii", $arrange_exam_id, $subjectId, $ssId, $ssId);
                    $delExisting->execute();
                    continue;
                }
                if ($date === '' || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date)) continue;
                if ($st === '' || !preg_match('/^[0-9]{2}:[0-9]{2}$/', $st)) continue;
                if ($en === '' || !preg_match('/^[0-9]{2}:[0-9]{2}$/', $en)) continue;
                if ($st >= $en) continue;
                if ($ssId > 0) {
                    if (!isset($subCatalog[$arrSubId])) continue;
                    if (!isset($subCatalog[$arrSubId][$ssId])) continue;
                }

                $delExisting->bind_param("iiii", $arrange_exam_id, $subjectId, $ssId, $ssId);
                $delExisting->execute();

                $ssInsert = $ssId > 0 ? $ssId : null;
                $ins->bind_param("iiiisss", $arrange_exam_id, $class_id, $subjectId, $ssInsert, $date, $st, $en);
                if (!$ins->execute()) {
                    throw new Exception('Insert failed: ' . $ins->error);
                }
                $saved++;
            }
        }

        $ins->close();
        $delExisting->close();
        $conn->commit();

        $_SESSION['message'] = $saved . ' datesheet entry(ies) saved.';
        $_SESSION['message_type'] = 'success';
    } catch (Throwable $e) {
        $conn->rollback();
        $_SESSION['message'] = 'Save failed: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?exam_id=' . $arrange_exam_id);
    exit;
}

$arrSubjects = array();
$q = $conn->query("SELECT aes.id AS arr_sub_id, aes.subject_id, aes.max_marks, s.title AS subject_title, s.type AS subject_type FROM arrange_exam_subjects aes INNER JOIN subjects s ON s.id = aes.subject_id WHERE aes.arrange_exam_id = " . (int)$arrange_exam_id . " ORDER BY aes.display_order, s.title");
if ($q) {
    while ($r = $q->fetch_assoc()) {
        $r['sub_subjects'] = array();
        $arrSubjects[(int)$r['arr_sub_id']] = $r;
    }
}

if (!empty($arrSubjects)) {
    $ids = implode(',', array_map('intval', array_keys($arrSubjects)));
    $q = $conn->query("SELECT aes.arrange_exam_subject_id, aes.sub_subject_id, aes.max_marks, ss.title AS sub_subject_title FROM arrange_exam_sub_subjects aes INNER JOIN sub_subjects ss ON ss.id = aes.sub_subject_id WHERE aes.arrange_exam_subject_id IN ($ids) ORDER BY aes.display_order, ss.title");
    if ($q) {
        while ($r = $q->fetch_assoc()) {
            $arrSubjects[(int)$r['arrange_exam_subject_id']]['sub_subjects'][(int)$r['sub_subject_id']] = $r;
        }
    }
}

$catalog_count = 0;
$q = $conn->query("SELECT COUNT(*) AS c FROM subject_class WHERE class_id = " . (int)$class_id);
if ($q) {
    $row = $q->fetch_assoc();
    $catalog_count = (int)$row['c'];
}

$existing = array();
$q = $conn->query("SELECT ed.id, ed.subject_id, ed.sub_subject_id, ed.start_date, ed.start_time, ed.end_time FROM exam_datesheet ed WHERE ed.arrange_exam_id = " . (int)$arrange_exam_id);
if ($q) {
    while ($r = $q->fetch_assoc()) {
        foreach ($arrSubjects as $asid => $as) {
            if ((int)$as['subject_id'] !== (int)$r['subject_id']) continue;
            $ssKey = $r['sub_subject_id'] !== null ? (int)$r['sub_subject_id'] : 0;
            if ($ssKey > 0) {
                if (!isset($as['sub_subjects'][$ssKey])) continue;
            }
            $existing[$asid][$ssKey] = $r;
            break;
        }
    }
}

$rules = '';
$q = $conn->query("SELECT rules_text FROM exam_datesheet_rules WHERE arrange_exam_id = " . (int)$arrange_exam_id);
if ($q) {
    $row = $q->fetch_assoc();
    if ($row) $rules = $row['rules_text'];
}

$view = isset($_GET['view']) ? $_GET['view'] : 'edit';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Datesheet</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #eef1f5; font-family: 'Inter', sans-serif; color: #1e293b; }
    .wrap { max-width: 1300px; margin: 30px auto; padding: 0 20px; }
    .head { background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); color: #fff; padding: 26px 32px; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: flex-end; gap: 20px; flex-wrap: wrap; }
    .head h1 { font-size: 24px; font-weight: 700; }
    .head .sub { font-size: 13px; opacity: 0.9; margin-top: 6px; }
    .head .hb { display: inline-flex; align-items: center; gap: 6px; padding: 9px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; text-decoration: none; cursor: pointer; border: 1px solid rgba(255,255,255,0.3); background: rgba(255,255,255,0.15); color: #fff; font-family: inherit; margin-left: 6px; }
    .head .hb:hover { background: #fff; color: #1e40af; border-color: #fff; text-decoration: none; }
    .card { background: #fff; padding: 26px 32px 32px 32px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
    .sec { font-size: 12px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 2px; margin: 24px 0 14px 0; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0; }
    .sec:first-child { margin-top: 0; }
    .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; border-left: 4px solid; }
    .alert.success { background: #d1fae5; border-color: #059669; color: #065f46; }
    .alert.danger  { background: #fee2e2; border-color: #dc2626; color: #991b1b; }
    .alert.warning { background: #fef3c7; border-color: #d97706; color: #92400e; }
    .alert.info    { background: #dbeafe; border-color: #1e40af; color: #1e3a8a; }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 42px; padding: 0 20px; border-radius: 10px; font-size: 13px; font-weight: 700; font-family: inherit; cursor: pointer; border: 1px solid; text-decoration: none; white-space: nowrap; }
    .btn-primary { background: #1e40af; color: #fff; border-color: #1e40af; }
    .btn-primary:hover { background: #1e3a8a; color: #fff; text-decoration: none; }
    .btn-success { background: #059669; color: #fff; border-color: #059669; }
    .btn-success:hover { background: #047857; color: #fff; text-decoration: none; }
    .btn-warning { background: #d97706; color: #fff; border-color: #d97706; }
    .btn-warning:hover { background: #b45309; color: #fff; text-decoration: none; }
    .btn-ghost { background: #fff; color: #334155; border-color: #cbd5e1; }
    .btn-ghost:hover { background: #f1f5f9; color: #1e293b; text-decoration: none; }
    table.g { width: 100%; border-collapse: collapse; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
    table.g thead th { background: #1e3a8a; color: #fff; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 12px; text-align: left; }
    table.g tbody td { padding: 10px 12px; font-size: 13px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    table.g tbody tr:last-child td { border-bottom: none; }
    table.g tr.subj-row td { background: #f8fafc; font-weight: 700; }
    table.g tr.ss-row td:first-child { padding-left: 32px; font-weight: 500; color: #475569; background: #fff; }
    .field-date, .field-time { width: 100%; height: 38px; padding: 0 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; font-family: inherit; background: #fff; }
    .field-date:focus, .field-time:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 3px rgba(30,64,175,0.1); }
    .pill { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; }
    .pill.test { background: #fef3c7; color: #92400e; }
    .pill.term { background: #dbeafe; color: #1e40af; }
    .pill.muted { background: #f1f5f9; color: #64748b; }
    .actions { display: flex; justify-content: flex-end; gap: 10px; padding-top: 18px; border-top: 1px solid #e2e8f0; margin-top: 16px; }
    textarea.rules { width: 100%; min-height: 140px; padding: 12px 14px; border: 1px solid #cbd5e1; border-radius: 10px; font-family: inherit; font-size: 13px; line-height: 1.6; resize: vertical; }
    textarea.rules:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 4px rgba(30,64,175,0.1); }
    .empty { padding: 40px 20px; text-align: center; color: #94a3b8; }
    .empty .big { font-size: 40px; opacity: 0.4; margin-bottom: 10px; }
    .day-group { margin-bottom: 20px; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; }
    .day-group .dg-head { background: #eff6ff; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #dbeafe; }
    .day-group .dg-head strong { color: #1e40af; font-size: 14px; }
    .day-group .dg-head .date-label { font-size: 12px; color: #475569; font-weight: 600; }
    .day-group table.dt { width: 100%; border-collapse: collapse; }
    .day-group table.dt th { background: #f8fafc; font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase; padding: 10px 14px; text-align: left; border-bottom: 1px solid #e2e8f0; letter-spacing: 1px; }
    .day-group table.dt td { padding: 10px 14px; font-size: 13px; border-bottom: 1px solid #f1f5f9; }
    .day-group table.dt tr:last-child td { border-bottom: none; }
    .rules-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 20px; white-space: pre-wrap; font-size: 13px; line-height: 1.7; color: #334155; }
    .tabs { display: flex; gap: 6px; margin-bottom: 16px; flex-wrap: wrap; }
    .tab { padding: 9px 18px; border-radius: 10px; font-size: 12px; font-weight: 700; border: 1px solid #cbd5e1; background: #fff; cursor: pointer; font-family: inherit; color: #334155; text-decoration: none; }
    .tab.active { background: #1e40af; color: #fff; border-color: #1e40af; }
    .tab:hover { text-decoration: none; color: #1e40af; }
    .tab.active:hover { color: #fff; }
    .empty-state { padding: 40px 20px; text-align: center; }
    .empty-state .big { font-size: 52px; opacity: 0.35; margin-bottom: 14px; }
    .empty-state h3 { font-size: 18px; color: #334155; font-weight: 700; margin-bottom: 8px; }
    .empty-state p { font-size: 14px; color: #64748b; margin-bottom: 18px; line-height: 1.6; }
    .empty-state .btn-row { display: flex; justify-content: center; gap: 10px; flex-wrap: wrap; }
    @media print {
        @page { margin: 12mm; size: A4 landscape; }
        body { background: #fff; }
        .wrap { margin: 0; padding: 0; max-width: none; }
        .head, .tabs, .no-print, form { display: none !important; }
        .card { box-shadow: none; padding: 0; }
        .day-group { page-break-inside: avoid; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">
    <div class="head">
        <div>
            <h1><i class="fas fa-calendar-days"></i> Datesheet</h1>
            <div class="sub">
                <span class="pill <?= htmlspecialchars($arr['exam_kind']) ?>"><?= htmlspecialchars($arr['exam_type_title']) ?></span>
                &nbsp; <strong><?= htmlspecialchars($arr['class_title']) ?></strong>
                &nbsp;&middot;&nbsp; <?= date('d M Y', strtotime($arr['start_date'])) ?>
                <?php if (!empty($arr['session_title'])): ?>
                    &nbsp;&middot;&nbsp; <?= htmlspecialchars($arr['session_title']) ?>
                <?php endif; ?>
            </div>
        </div>
        <div>
            <a href="arrange_exam.php?edit=<?= (int)$arrange_exam_id ?>" class="hb"><i class="fas fa-list-check"></i> Edit Subjects</a>
            <a href="exam_master_datesheet.php?exam_type_id=<?= (int)$arr['exam_type_id'] ?>&session_id=<?= (int)$arr['session_id'] ?>" class="hb"><i class="fas fa-layer-group"></i> Master</a>
            <a href="arrange_exam.php" class="hb"><i class="fas fa-arrow-left"></i> Back</a>
        </div>
    </div>

    <div class="card">

        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert <?= htmlspecialchars($_SESSION['message_type']) ?>"><?= htmlspecialchars($_SESSION['message']) ?></div>
            <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
        <?php endif; ?>

        <?php if (empty($arrSubjects)): ?>

            <div class="empty-state">
                <div class="big">&#128218;</div>
                <h3>No subjects assigned to this arrangement</h3>
                <p>
                    This arrangement was created before the new pipeline, or the subject picker was never saved.<br>
                    Class catalog has <strong><?= $catalog_count ?></strong> subject(s) in subject_class.
                </p>
                <div class="btn-row">
                    <?php if ($catalog_count > 0 && (!empty($perms['can_add']) || !empty($perms['can_edit']))): ?>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="action" value="auto_assign">
                            <button type="submit" class="btn btn-warning"
                                    onclick="return confirm('Copy all <?= $catalog_count ?> subject(s) from the class catalog into this arrangement?');">
                                <i class="fas fa-wand-magic-sparkles"></i> Auto-Assign from Class Catalog
                            </button>
                        </form>
                    <?php endif; ?>
                    <a href="arrange_exam.php?edit=<?= (int)$arrange_exam_id ?>" class="btn btn-primary">
                        <i class="fas fa-list-check"></i> Pick Subjects Manually
                    </a>
                </div>

                <?php if ($catalog_count === 0): ?>
                    <p style="margin-top:24px;font-size:13px;color:#94a3b8;">
                        The class <strong><?= htmlspecialchars($arr['class_title']) ?></strong> has no subjects in the
                        <a href="subject_class.php" style="color:#1e40af;font-weight:700;">Class Subjects</a> catalog either.
                        Add subjects there first.
                    </p>
                <?php endif; ?>
            </div>

        <?php else: ?>

            <div class="tabs no-print">
                <a href="?exam_id=<?= (int)$arrange_exam_id ?>&view=edit" class="tab <?= ($view === 'edit') ? 'active' : '' ?>"><i class="fas fa-pen"></i> Edit</a>
                <a href="?exam_id=<?= (int)$arrange_exam_id ?>&view=day" class="tab <?= ($view === 'day') ? 'active' : '' ?>"><i class="fas fa-list"></i> Day-wise</a>
                <a href="?exam_id=<?= (int)$arrange_exam_id ?>&view=print" target="_blank" class="tab"><i class="fas fa-print"></i> Print</a>
            </div>

            <?php if ($view === 'edit'): ?>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="save_datesheet">

                <div class="sec">Set Dates and Times</div>

                <table class="g">
                    <thead>
                        <tr>
                            <th style="width:30%;">Subject / Sub-subject</th>
                            <th style="width:10%;">Marks</th>
                            <th style="width:20%;">Date</th>
                            <th style="width:12%;">Day</th>
                            <th style="width:14%;">Start Time</th>
                            <th style="width:14%;">End Time</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($arrSubjects as $arrSubId => $as):
                        $sid = (int)$as['arr_sub_id'];
                        $hasChildren = !empty($as['sub_subjects']);
                    ?>
                        <tr class="subj-row">
                            <td>
                                <i class="fas fa-book" style="color:#1e40af;margin-right:6px;"></i>
                                <?= htmlspecialchars($as['subject_title']) ?>
                                <?php if ($hasChildren): ?><span class="pill muted" style="margin-left:6px;">composite</span><?php endif; ?>
                            </td>
                            <td><?= number_format((float)$as['max_marks'], 0) ?></td>
                            <?php
                                $ex = isset($existing[$sid][0]) ? $existing[$sid][0] : null;
                                $d  = $ex ? htmlspecialchars($ex['start_date']) : '';
                                $st = $ex ? substr($ex['start_time'], 0, 5) : '';
                                $en = $ex ? substr($ex['end_time'], 0, 5) : '';
                            ?>
                            <td><input type="date" class="field-date date-input" name="dates[<?= $sid ?>][0][date]" value="<?= $d ?>"></td>
                            <td class="day-cell"><?= $d ? htmlspecialchars(day_name($d)) : '&mdash;' ?></td>
                            <td><input type="time" class="field-time" name="dates[<?= $sid ?>][0][start]" value="<?= $st ?>"></td>
                            <td><input type="time" class="field-time" name="dates[<?= $sid ?>][0][end]"   value="<?= $en ?>"></td>
                        </tr>

                        <?php foreach ($as['sub_subjects'] as $ssId => $ss):
                            $ssEx = isset($existing[$sid][(int)$ssId]) ? $existing[$sid][(int)$ssId] : null;
                            $ssD  = $ssEx ? htmlspecialchars($ssEx['start_date']) : '';
                            $ssSt = $ssEx ? substr($ssEx['start_time'], 0, 5) : '';
                            $ssEn = $ssEx ? substr($ssEx['end_time'], 0, 5) : '';
                        ?>
                            <tr class="ss-row">
                                <td><?= htmlspecialchars($ss['sub_subject_title']) ?></td>
                                <td><?= number_format((float)$ss['max_marks'], 0) ?></td>
                                <td><input type="date" class="field-date date-input" name="dates[<?= $sid ?>][<?= (int)$ssId ?>][date]" value="<?= $ssD ?>"></td>
                                <td class="day-cell"><?= $ssD ? htmlspecialchars(day_name($ssD)) : '&mdash;' ?></td>
                                <td><input type="time" class="field-time" name="dates[<?= $sid ?>][<?= (int)$ssId ?>][start]" value="<?= $ssSt ?>"></td>
                                <td><input type="time" class="field-time" name="dates[<?= $sid ?>][<?= (int)$ssId ?>][end]"   value="<?= $ssEn ?>"></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="actions">
                    <?php if (!empty($perms['can_add']) || !empty($perms['can_edit'])): ?>
                        <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Save Datesheet</button>
                    <?php endif; ?>
                    <button type="reset" class="btn btn-ghost">Reset</button>
                </div>
            </form>

            <div class="sec" style="margin-top:30px;">Rules and Regulations</div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="save_rules">
                <textarea class="rules" name="rules_text" placeholder="Enter exam rules, instructions, and guidelines for students."><?= htmlspecialchars($rules) ?></textarea>
                <div class="actions">
                    <?php if (!empty($perms['can_edit'])): ?>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Rules</button>
                    <?php endif; ?>
                </div>
            </form>

            <?php elseif ($view === 'day' || $view === 'print'): ?>

            <?php
            $byDate = array();
            foreach ($arrSubjects as $arrSubId => $as):
                $sid = (int)$as['arr_sub_id'];
                $rows = array();
                if (isset($existing[$sid][0])) $rows[0] = $existing[$sid][0];
                foreach ($as['sub_subjects'] as $ssId => $ss) {
                    if (isset($existing[$sid][(int)$ssId])) $rows[(int)$ssId] = $existing[$sid][(int)$ssId];
                }
                foreach ($rows as $ssKey => $r) {
                    $date = $r['start_date'];
                    if ($ssKey === 0) {
                        $label = $as['subject_title'];
                        $max   = $as['max_marks'];
                    } else {
                        $ssTitle = isset($as['sub_subjects'][$ssKey]['sub_subject_title']) ? $as['sub_subjects'][$ssKey]['sub_subject_title'] : '';
                        $label = $as['subject_title'] . ' - ' . $ssTitle;
                        $max   = isset($as['sub_subjects'][$ssKey]['max_marks']) ? $as['sub_subjects'][$ssKey]['max_marks'] : 0;
                    }
                    if (!isset($byDate[$date])) $byDate[$date] = array();
                    $byDate[$date][] = array(
                        'subject' => $label,
                        'marks'   => $max,
                        'start'   => substr($r['start_time'], 0, 5),
                        'end'     => substr($r['end_time'], 0, 5),
                    );
                }
            endforeach;
            ksort($byDate);
            ?>

            <?php if (empty($byDate)): ?>
                <div class="empty">
                    <div class="big">&#128197;</div>
                    <h3>No datesheet entries yet</h3>
                    <p>Switch to <a href="?exam_id=<?= (int)$arrange_exam_id ?>&view=edit">Edit</a> to add dates and times.</p>
                </div>
            <?php else: ?>
                <?php if ($view === 'day'): ?>
                    <div class="sec">Day-wise Schedule</div>
                <?php endif; ?>

                <?php foreach ($byDate as $date => $items): ?>
                    <div class="day-group">
                        <div class="dg-head">
                            <strong><?= htmlspecialchars(day_name($date)) ?></strong>
                            <span class="date-label"><?= date('d M Y', strtotime($date)) ?> &middot; <?= count($items) ?> paper(s)</span>
                        </div>
                        <table class="dt">
                            <thead>
                                <tr>
                                    <th style="width:5%;">#</th>
                                    <th>Subject</th>
                                    <th style="width:10%;">Max Marks</th>
                                    <th style="width:15%;">Start</th>
                                    <th style="width:15%;">End</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php $i = 1; foreach ($items as $it): ?>
                                <tr>
                                    <td><?= $i++ ?></td>
                                    <td><?= htmlspecialchars($it['subject']) ?></td>
                                    <td><?= number_format((float)$it['marks'], 0) ?></td>
                                    <td><?= htmlspecialchars($it['start']) ?></td>
                                    <td><?= htmlspecialchars($it['end']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>

                <?php if (!empty($rules)): ?>
                    <div class="sec" style="margin-top:30px;">Rules and Regulations</div>
                    <div class="rules-box"><?= nl2br(htmlspecialchars($rules)) ?></div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($view === 'print'): ?>
                <script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 400); });</script>
            <?php endif; ?>

            <?php endif; ?>

        <?php endif; ?>

    </div>
</div>

<script>
document.querySelectorAll('.date-input').forEach(function(input){
    input.addEventListener('change', function(){
        var d = this.value;
        var cell = this.closest('tr').querySelector('.day-cell');
        if (!cell) return;
        if (!d) { cell.textContent = '\u2014'; return; }
        var dt = new Date(d + 'T00:00:00');
        var names = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        cell.textContent = names[dt.getDay()];
    });
});
</script>

</body>
</html>
<?php $conn->close(); ob_end_flush(); ?>