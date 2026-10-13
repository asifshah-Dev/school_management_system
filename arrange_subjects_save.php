<?php
/**
 * arrange_subjects_save.php
 * AJAX endpoint — saves the subject + sub-subject picker from arrange_exam.php
 *
 * Receives (JSON body):
 *   csrf_token       string
 *   arrange_exam_id  int
 *   subjects         [ { subject_id, max_marks, sub_subjects: [ {sub_subject_id, max_marks} ] } ]
 *
 * Returns (JSON):
 *   { success: bool, saved_count: int, message: string }
 */

/* Never emit HTML on this endpoint, even if includes try to */
while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');

/* Capture fatals as JSON */
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo json_encode([
            'success' => false,
            'message' => 'PHP FATAL: ' . $e['message'] . ' in ' . basename($e['file']) . ':' . $e['line'],
        ]);
    }
});

set_exception_handler(function ($ex) {
    echo json_encode(['success' => false, 'message' => 'EXCEPTION: ' . $ex->getMessage()]);
    exit;
});

require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
if (session_status() === PHP_SESSION_NONE) session_start();

require_once('conn_inc.php');

try {
    /* ── Only POST ── */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'POST required.']);
        exit;
    }

    /* ── Permission ── */
    if (empty($perms['can_add']) && empty($perms['can_edit'])) {
        echo json_encode(['success' => false, 'message' => 'You do not have permission to modify exams.']);
        exit;
    }

    /* ── Decode JSON body ── */
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON payload.']);
        exit;
    }

    /* ── CSRF ── */
    $csrf = $data['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || $csrf !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit;
    }

    /* ── Validate arrange_exam_id ── */
    $arrange_exam_id = (int)($data['arrange_exam_id'] ?? 0);
    if ($arrange_exam_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Missing arrange_exam_id.']);
        exit;
    }

    /* ── Confirm arrangement exists ── */
    $chk = $conn->prepare("SELECT id FROM arrange_exam WHERE id = ?");
    $chk->bind_param("i", $arrange_exam_id);
    $chk->execute();
    if ($chk->get_result()->num_rows === 0) {
        $chk->close();
        echo json_encode(['success' => false, 'message' => 'Arrangement not found.']);
        exit;
    }
    $chk->close();

    $subjects = $data['subjects'] ?? [];
    if (!is_array($subjects)) $subjects = [];

    /* ── Begin transaction ── */
    $conn->begin_transaction();

    /* ── Wipe existing rows for this arrangement ──
       Sub-subjects first (child), then subjects (parent). */
    $delChild = $conn->prepare("
        DELETE aes FROM arrange_exam_sub_subjects aes
        INNER JOIN arrange_exam_subjects a ON a.id = aes.arrange_exam_subject_id
        WHERE a.arrange_exam_id = ?
    ");
    $delChild->bind_param("i", $arrange_exam_id);
    $delChild->execute();
    $delChild->close();

    $delParent = $conn->prepare("DELETE FROM arrange_exam_subjects WHERE arrange_exam_id = ?");
    $delParent->bind_param("i", $arrange_exam_id);
    $delParent->execute();
    $delParent->close();

    /* ── Insert new subjects ── */
    $saved_count = 0;
    $order = 0;

    $insSubject = $conn->prepare("
        INSERT INTO arrange_exam_subjects
            (arrange_exam_id, subject_id, max_marks, display_order, status)
        VALUES (?, ?, ?, ?, 0)
    ");

    $insSubSub = $conn->prepare("
        INSERT INTO arrange_exam_sub_subjects
            (arrange_exam_subject_id, sub_subject_id, max_marks, display_order)
        VALUES (?, ?, ?, ?)
    ");

    /* Cache of valid sub_subject_ids per subject_id — guards against
       a tampered payload sending a foreign sub_subject_id */
    $validSubSubjects = [];
    $q = $conn->query("
        SELECT subject_id, id
        FROM sub_subjects
        WHERE status = 0 OR status IS NULL
    ");
    if ($q) {
        while ($r = $q->fetch_assoc()) {
            $validSubSubjects[(int)$r['subject_id']][] = (int)$r['id'];
        }
    }

    foreach ($subjects as $entry) {
        $sid = (int)($entry['subject_id'] ?? 0);
        $mx  = (float)($entry['max_marks'] ?? 0);
        if ($sid <= 0) continue;
        if ($mx < 0) $mx = 0;
        if ($mx > 9999) $mx = 9999;

        $order++;
        $insSubject->bind_param("iidi", $arrange_exam_id, $sid, $mx, $order);
        if (!$insSubject->execute()) {
            throw new Exception('Insert subject failed: ' . $insSubject->error);
        }
        $arr_sub_id = $conn->insert_id;
        $saved_count++;

        /* Sub-subjects */
        $subSubs = $entry['sub_subjects'] ?? [];
        if (is_array($subSubs) && !empty($validSubSubjects[$sid])) {
            $ssOrder = 0;
            foreach ($subSubs as $ss) {
                $ssid = (int)($ss['sub_subject_id'] ?? 0);
                $ssmax = (float)($ss['max_marks'] ?? 0);
                if ($ssid <= 0) continue;
                /* Security: only allow sub-subjects belonging to this subject_id */
                if (!in_array($ssid, $validSubSubjects[$sid], true)) continue;
                if ($ssmax < 0) $ssmax = 0;
                if ($ssmax > 9999) $ssmax = 9999;

                $ssOrder++;
                $insSubSub->bind_param("iidi", $arr_sub_id, $ssid, $ssmax, $ssOrder);
                if (!$insSubSub->execute()) {
                    throw new Exception('Insert sub-subject failed: ' . $insSubSub->error);
                }
            }
        }
    }

    $insSubject->close();
    $insSubSub->close();

    $conn->commit();

    echo json_encode([
        'success'     => true,
        'saved_count' => $saved_count,
        'message'     => $saved_count . ' subject(s) saved.',
    ]);

} catch (Throwable $e) {
    if ($conn && $conn->errno === 0) { @$conn->rollback(); }
    error_log('arrange_subjects_save.php: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Save failed: ' . $e->getMessage(),
    ]);
}