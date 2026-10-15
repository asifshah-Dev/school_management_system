<?php
header('Content-Type: text/html; charset=utf-8');
require_once 'security.php';
require_once 'conn_inc.php';
require_once 'permissions.php';

require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ═══════════ HELPERS ═══════════ */
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function safe_image(?string $path): ?string {
    if (!$path || preg_match('#(^[a-z]+:|\.\.)#i', $path)) return null;
    if (!preg_match('/\.(jpe?g|png|gif|webp|svg)$/i', $path)) return null;
    return is_file($path) ? $path : null;
}
function fmt_date($d): string {
    if (empty($d) || $d === '0000-00-00') return '';
    try { return (new DateTime($d))->format('d-M-Y'); } catch (Exception $e) { return (string)$d; }
}
function next_cert_no($conn): string {
    $year = date('Y'); $prefix = 'LC-' . $year . '-';
    $stmt = $conn->prepare("SELECT cert_no FROM student_certificates WHERE cert_no LIKE ? ORDER BY id DESC LIMIT 1");
    $next = 1;
    if ($stmt) {
        $like = $prefix . '%';
        $stmt->bind_param("s", $like);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
            $parts = explode('-', $row['cert_no']);
            $n = intval(end($parts));
            if ($n > 0) $next = $n + 1;
        }
        $stmt->close();
    }
    return $prefix . str_pad($next, 5, '0', STR_PAD_LEFT);
}
function build_body_text($student, $school_name, $leaving_date, $conduct, $reason, $to_school) {
    $g = strtolower(trim($student['gender'] ?? ''));
    if ($g === 'male') {
        $child = 'son'; $they = 'He'; $their = 'His'; $them = 'him'; $possessive = 'his';
    } elseif ($g === 'female') {
        $child = 'daughter'; $they = 'She'; $their = 'Her'; $them = 'her'; $possessive = 'her';
    } else {
        $child = 'child'; $they = 'They'; $their = 'Their'; $them = 'them'; $possessive = 'their';
    }
    $class_label = trim(($student['class_title'] ?? '') . (!empty($student['section_title']) ? ' - ' . $student['section_title'] : ''));
    $name = $student['name'];
    $father = $student['father_name'] ?: '—';
    $adm_dt = fmt_date($student['admission_date']);
    $lv_dt  = fmt_date($leaving_date);

    $text  = "This is to certify that {$name}, {$child} of {$father}, ";
    $text .= "was a bonafide student of {$school_name} from {$adm_dt} to {$lv_dt}. ";
    $text .= "{$they} was studying in class " . ($class_label ?: '—') . " at the time of leaving the school. ";
    $text .= "{$their} conduct and character during the stay in the school remained " . strtolower($conduct ?: 'good') . ". ";
    $text .= "{$they} bears a good moral character and has no dues outstanding against {$them} at the time of leaving the school.";
    if ($reason !== '') $text .= " Reason for leaving: " . $reason . ".";
    if ($to_school !== '') $text .= " {$they} intends to continue {$possessive} studies at " . $to_school . ".";
    $text .= " We wish {$them} success in all future endeavours.";
    return $text;
}

/* ═══════════ BRANDING ═══════════ */
$school = ['name'=>'SCHOOL MANAGEMENT SYSTEM','logo'=>'','address'=>'','phone'=>'','email'=>''];
$chk = $conn->query("SHOW TABLES LIKE 'settings'");
if ($chk && $chk->num_rows > 0) {
    $q = $conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('school_logo','school_name','school_address','school_phone','school_email')");
    if ($q) while ($row = $q->fetch_assoc()) {
        if ($row['setting_value'] === '' || $row['setting_value'] === null) continue;
        $map = ['school_logo'=>'logo','school_name'=>'name','school_address'=>'address','school_phone'=>'phone','school_email'=>'email'];
        if (isset($map[$row['setting_key']])) $school[$map[$row['setting_key']]] = $row['setting_value'];
    }
}
$school_logo = safe_image($school['logo']);

/* ═══════════ CLASS DROPDOWN ═══════════ */
$class_options = [];
$r = $conn->query("
    SELECT COALESCE(cs.id, 0) AS class_section_id, c.id AS class_id, c.title AS class_title,
           s.title AS section_title,
           CASE WHEN cs.id IS NULL THEN c.title ELSE CONCAT(c.title, ' - ', s.title) END AS display_label
    FROM classes c
    LEFT JOIN class_sections cs ON cs.class_id = c.id AND cs.status = 0
    LEFT JOIN sections s ON s.id = cs.section_id
    WHERE c.status = 0 ORDER BY c.title, s.title
");
if ($r) while ($row = $r->fetch_assoc()) $class_options[] = $row;

/* ═══════════ INPUTS ═══════════ */
$step         = isset($_REQUEST['step']) ? intval($_REQUEST['step']) : 1;
if ($step < 1 || $step > 3) $step = 1;

$class_filter = isset($_REQUEST['class_filter']) ? trim($_REQUEST['class_filter']) : '';
$search       = isset($_REQUEST['search']) ? trim($_REQUEST['search']) : '';
$student_id   = isset($_REQUEST['student_id']) ? intval($_REQUEST['student_id']) : 0;
$cert_no      = isset($_REQUEST['cert_no']) ? trim($_REQUEST['cert_no']) : '';
$cert_date    = isset($_REQUEST['cert_date']) ? trim($_REQUEST['cert_date']) : date('Y-m-d');
$leaving_date = isset($_REQUEST['leaving_date']) ? trim($_REQUEST['leaving_date']) : '';
$to_school    = isset($_REQUEST['to_school']) ? trim($_REQUEST['to_school']) : '';
$reason       = isset($_REQUEST['reason']) ? trim($_REQUEST['reason']) : '';
$conduct      = isset($_REQUEST['conduct']) ? trim($_REQUEST['conduct']) : 'Good';
$issued_by    = isset($_REQUEST['issued_by']) ? trim($_REQUEST['issued_by']) : 'Principal';
$body_text    = isset($_REQUEST['body_text']) ? trim($_REQUEST['body_text']) : '';
$remarks      = isset($_REQUEST['remarks']) ? trim($_REQUEST['remarks']) : '';

$do_next      = isset($_POST['go_next']) || isset($_GET['go_next']);
$do_final     = isset($_POST['final_generate']);
$do_autofill  = isset($_POST['autofill_body']);

/* ═══════════ SEARCH ═══════════ */
$search_results = [];
$has_filter = ($class_filter !== '' || $search !== '');

if ($has_filter) {
    $where = []; $params = []; $types = '';
    if ($class_filter !== '') {
        if (strpos($class_filter, '|') !== false) {
            [$cid, $csid] = array_map('intval', explode('|', $class_filter, 2));
            $where[] = "sc.class_id = ?"; $types .= 'i'; $params[] = $cid;
            if ($csid > 0) { $where[] = "sc.class_section_id = ?"; $types .= 'i'; $params[] = $csid; }
        }
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = "(sr.name LIKE ? OR sr.reg_no LIKE ? OR sr.father_name LIKE ?)";
        $types .= 'sss'; $params[] = $like; $params[] = $like; $params[] = $like;
    }
    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "
        SELECT sr.id, sr.name, sr.reg_no, sr.father_name, sr.gender, sr.status,
               c.title AS class_title, s.title AS section_title
        FROM student_registration sr
        LEFT JOIN (
            SELECT sc1.student_registration_id, sc1.class_id, sc1.class_section_id
            FROM student_class sc1
            INNER JOIN (
                SELECT student_registration_id, MAX(id) AS max_id
                FROM student_class GROUP BY student_registration_id
            ) sc2 ON sc1.id = sc2.max_id
        ) sc ON sr.id = sc.student_registration_id
        LEFT JOIN classes c ON sc.class_id = c.id
        LEFT JOIN class_sections cs ON sc.class_section_id = cs.id
        LEFT JOIN sections s ON cs.section_id = s.id
        $where_sql ORDER BY sr.name ASC LIMIT 100
    ";
    if ($stmt = $conn->prepare($sql)) {
        if (!empty($params)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) $search_results[] = $row;
        $stmt->close();
    }
}

/* ═══════════ LOAD STUDENT ═══════════ */
$sel = null;
$existing_cert = null;

if ($student_id > 0) {
    $stmt = $conn->prepare("
        SELECT sr.id, sr.reg_no, sr.name, sr.father_name, sr.dob, sr.gender,
               sr.admission_no, sr.admission_date, sr.status,
               vc.title AS village_title, b.title AS branch_title,
               c.title AS class_title, s.title AS section_title, ses.title AS session_title
        FROM student_registration sr
        LEFT JOIN (
            SELECT sc1.student_registration_id, sc1.class_id, sc1.class_section_id, sc1.session_id
            FROM student_class sc1
            INNER JOIN (
                SELECT student_registration_id, MAX(id) AS max_id
                FROM student_class GROUP BY student_registration_id
            ) sc2 ON sc1.id = sc2.max_id
        ) sc ON sr.id = sc.student_registration_id
        LEFT JOIN classes c ON sc.class_id = c.id
        LEFT JOIN class_sections cs ON sc.class_section_id = cs.id
        LEFT JOIN sections s ON cs.section_id = s.id
        LEFT JOIN sessions ses ON sc.session_id = ses.id
        LEFT JOIN village_councils vc ON sr.village_council_id = vc.id
        LEFT JOIN branches b ON sr.branch_id = b.id
        WHERE sr.id = ? LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) $sel = $res->fetch_assoc();
        $stmt->close();
    }

    if ($sel) {
        $chk = $conn->prepare("SELECT * FROM student_certificates WHERE student_id = ? AND status = 0 ORDER BY id DESC LIMIT 1");
        if ($chk) {
            $chk->bind_param("i", $student_id);
            $chk->execute();
            $r = $chk->get_result();
            if ($r && ($row = $r->fetch_assoc())) {
                $existing_cert = $row;
                if ($step === 2 && !$do_next && !$do_autofill && !$do_final) {
                    $cert_no      = $existing_cert['cert_no'];
                    $cert_date    = $existing_cert['cert_date'];
                    $leaving_date = $existing_cert['leaving_date'];
                    $to_school    = $existing_cert['to_school'];
                    $reason       = $existing_cert['reason'];
                    $conduct      = $existing_cert['conduct'] ?: 'Good';
                    $issued_by    = $existing_cert['issued_by'];
                    $body_text    = $existing_cert['body_text'];
                    $remarks      = $existing_cert['remarks'];
                }
            }
            $chk->close();
        }
    }
}

/* ═══════════ AUTO-FILL ═══════════ */
if ($do_autofill && $sel) {
    $body_text = build_body_text($sel, $school['name'], $leaving_date, $conduct, $reason, $to_school);
    $step = 2;
}

/* ═══════════ NEXT ═══════════ */
if ($do_next && $student_id > 0 && $sel) {
    $step = 2;
    if ($existing_cert) {
        $cert_no      = $existing_cert['cert_no'];
        $cert_date    = $existing_cert['cert_date'];
        $leaving_date = $existing_cert['leaving_date'];
        $to_school    = $existing_cert['to_school'];
        $reason       = $existing_cert['reason'];
        $conduct      = $existing_cert['conduct'] ?: 'Good';
        $issued_by    = $existing_cert['issued_by'];
        $body_text    = $existing_cert['body_text'];
        $remarks      = $existing_cert['remarks'];
    } elseif ($body_text === '') {
        $body_text = build_body_text($sel, $school['name'], $leaving_date, $conduct, $reason, $to_school);
    }
}

/* ═══════════ GENERATE ═══════════ */
$save_msg = ''; $save_err = ''; $final_done = false;

if ($do_final && $sel) {
    if ($cert_no === '') $cert_no = next_cert_no($conn);
    if ($body_text === '') {
        $body_text = build_body_text($sel, $school['name'], $leaving_date, $conduct, $reason, $to_school);
    }
    $issued_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;

    if ($existing_cert) {
        $stmt = $conn->prepare("UPDATE student_certificates SET cert_no=?, cert_date=?, leaving_date=?, to_school=?, reason=?, conduct=?, issued_by=?, body_text=?, remarks=?, issued_user_id=? WHERE id=?");
        if ($stmt) {
            $stmt->bind_param("sssssssssii", $cert_no, $cert_date, $leaving_date, $to_school, $reason, $conduct, $issued_by, $body_text, $remarks, $issued_user_id, $existing_cert['id']);
            if ($stmt->execute()) $save_msg = 'Certificate updated successfully.';
            else $save_err = 'Update failed: ' . $stmt->error;
            $stmt->close();
        }
    } else {
        $stmt = $conn->prepare("INSERT INTO student_certificates (student_id, cert_no, cert_date, leaving_date, to_school, reason, conduct, issued_by, body_text, remarks, issued_user_id, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,0)");
        if ($stmt) {
            $stmt->bind_param("isssssssssi", $sel['id'], $cert_no, $cert_date, $leaving_date, $to_school, $reason, $conduct, $issued_by, $body_text, $remarks, $issued_user_id);
            if ($stmt->execute()) $save_msg = 'Certificate generated successfully.';
            else $save_err = 'Save failed: ' . $stmt->error;
            $stmt->close();
        }
    }

    if (empty($save_err)) {
        $upd = $conn->prepare("UPDATE student_registration SET status = 3 WHERE id = ?");
        if ($upd) {
            $upd->bind_param("i", $sel['id']);
            $upd->execute();
            $upd->close();
            $sel['status'] = 3;
        }
        $final_done = true;
        $step = 3;
    }
}

$show_certificate = ($final_done && $sel);

/* If we came from the list's "Print Now" button, show the cert directly */
$auto_print = isset($_GET['autoprint']);
if ($auto_print && $sel && $existing_cert && !$show_certificate) {
    $cert_no      = $existing_cert['cert_no'];
    $cert_date    = $existing_cert['cert_date'];
    $leaving_date = $existing_cert['leaving_date'];
    $to_school    = $existing_cert['to_school'];
    $reason       = $existing_cert['reason'];
    $conduct      = $existing_cert['conduct'] ?: 'Good';
    $issued_by    = $existing_cert['issued_by'];
    $body_text    = $existing_cert['body_text'];
    $remarks      = $existing_cert['remarks'];
    $show_certificate = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>School Leaving Certificate</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Cinzel:wght@600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f5f7fa; padding-bottom: 40px; }

        .page-title-bar { display: flex; align-items: center; justify-content: space-between; margin: 0 0 18px 0; flex-wrap: wrap; gap: 10px; }
        .page-title-bar h1 { font-size: 22px; font-weight: 700; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 10px; }
        .page-title-bar h1 i { color: #2563eb; }
        .page-title-bar .subtitle { font-size: 13px; color: #64748b; margin-top: 4px; }

        .panel { background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 30px; overflow: hidden; }
        .nav-tabs { border-bottom: 2px solid #dee2e6; margin-bottom: 20px; }
        .nav-tabs .nav-link { border: none; color: #6c757d; font-weight: 600; padding: 12px 20px; border-bottom: 3px solid transparent; cursor: pointer; }
        .nav-tabs .nav-link.active { color: #2563eb; border-bottom: 3px solid #2563eb; }
        .nav-tabs .nav-link .step-num { display: inline-block; width: 22px; height: 22px; line-height: 22px; text-align: center; border-radius: 50%; background: #e2e8f0; color: #64748b; font-size: 11px; font-weight: 700; margin-right: 6px; }
        .nav-tabs .nav-link.active .step-num { background: #2563eb; color: #fff; }
        .nav-tabs .nav-link.completed .step-num { background: #10b981; color: #fff; }

        .tab-content { padding: 20px 0; }
        .form-section { margin-bottom: 20px; }
        .form-section h5 { font-size: 16px; font-weight: 700; color: #333; margin-bottom: 15px; border-left: 4px solid #2563eb; padding-left: 10px; }
        .form-control { border-radius: 6px; border: 1px solid #ddd; padding: 10px; }
        .form-control:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
        label { font-weight: 500; color: #555; font-size: 0.9rem; }

        .btn-primary { background-color: #2563eb; border-color: #2563eb; color: #fff; }
        .btn-primary:hover { background-color: #1d4ed8; border-color: #1d4ed8; color: #fff; }
        .btn-success { background-color: #059669; border-color: #059669; color: #fff; }
        .btn-success:hover { background-color: #047857; border-color: #047857; color: #fff; }
        .btn-warning { background-color: #d97706; border-color: #d97706; color: #fff; }
        .btn-warning:hover { background-color: #b45309; border-color: #b45309; color: #fff; }
        .btn-secondary { background-color: #64748b; border-color: #64748b; color: #fff; }
        .btn-secondary:hover { background-color: #475569; border-color: #475569; color: #fff; }
        .btn-info { background-color: #0891b2; border-color: #0891b2; color: #fff; }
        .btn-info:hover { background-color: #0e7490; border-color: #0e7490; color: #fff; }

        .search-filter-row { display: grid; grid-template-columns: 320px 1fr; gap: 12px; margin-bottom: 14px; }
        @media (max-width: 700px) { .search-filter-row { grid-template-columns: 1fr; } }

        .search-wrap { position: relative; }
        .search-input { width: 100%; height: 48px; font-size: 14px; border: 2px solid #cbd5e1; border-radius: 8px; padding: 0 16px 0 44px; background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='18' height='18' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z'/%3E%3C/svg%3E") 16px center no-repeat; }
        .search-input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.12); }

        .class-select { width: 100%; height: 48px; font-size: 14px; border: 2px solid #cbd5e1; border-radius: 8px; padding: 0 12px; background: #fff; }
        .class-select:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.12); }

        .search-results { margin-top: 12px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; max-height: 500px; overflow-y: auto; }
        .search-result { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 16px; border-bottom: 1px solid #f1f5f9; cursor: pointer; transition: background .12s; }
        .search-result:last-child { border-bottom: 0; }
        .search-result:hover { background: #eff6ff; }
        .search-result .left { flex: 1; min-width: 0; }
        .search-result .name { font-weight: 700; color: #0f172a; font-size: 14px; }
        .search-result .meta { font-size: 12px; color: #64748b; margin-top: 2px; }
        .search-result .meta .badge-soft { display: inline-block; background: #f1f5f9; color: #334155; padding: 1px 8px; border-radius: 10px; font-weight: 600; margin-right: 6px; font-size: 11px; }
        .search-result .right { flex-shrink: 0; }
        .search-result .go-btn { background: #2563eb; color: #fff; border: none; padding: 6px 14px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer; }
        .search-result .go-btn:hover { background: #1d4ed8; }
        .search-result.has-cert .go-btn { background: #d97706; }
        .search-result.has-cert .go-btn:hover { background: #b45309; }

        .no-results { padding: 30px; text-align: center; color: #94a3b8; font-size: 13px; border: 1px dashed #cbd5e1; border-radius: 8px; margin-top: 12px; }
        .no-results i { font-size: 26px; opacity: .4; display: block; margin-bottom: 8px; }

        .student-card { background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 14px 18px; margin-bottom: 18px; display: flex; gap: 20px; flex-wrap: wrap; align-items: center; }
        .student-card .field { font-size: 13px; }
        .student-card .field b { color: #0f172a; font-weight: 700; }
        .student-card .field span.k { color: #64748b; font-weight: 600; margin-right: 4px; }

        .alert-edit-mode { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; padding: 12px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 18px; }
        .alert-edit-mode b { color: #78350f; }

        .cert-section { margin-top: 20px; }
        .cert-actions { text-align: center; margin-bottom: 16px; }

        .cert { background: #fff; width: 100%; max-width: 100%; margin: 0; padding: 40px 50px 45px; color: #111; font-size: 14px; line-height: 1.75; box-shadow: 0 4px 14px rgba(15,23,42,.08); }
        .cert-header { display: flex; align-items: center; gap: 20px; padding-bottom: 16px; border-bottom: 2px solid #1e3a8a; }
        .cert-logo { width: 80px; height: 80px; flex-shrink: 0; border-radius: 50%; background: #f1f5f9; border: 2px solid #1e3a8a; display: flex; align-items: center; justify-content: center; color: #1e3a8a; font-size: 30px; overflow: hidden; }
        .cert-logo img { width: 100%; height: 100%; object-fit: cover; }
        .cert-head-txt { flex: 1; text-align: center; }
        .cert-school { font-family: 'Cinzel', serif; font-weight: 700; font-size: 26px; letter-spacing: 2px; text-transform: uppercase; color: #1e3a8a; margin: 0; }
        .cert-sub { font-size: 12px; color: #475569; margin-top: 6px; }
        .cert-sub span { margin: 0 8px; }
        .cert-title-block { text-align: center; margin: 30px 0 26px; }
        .cert-title { display: inline-block; font-family: 'Cinzel', serif; font-weight: 700; font-size: 22px; letter-spacing: 6px; color: #1e3a8a; padding: 8px 30px; border-top: 2px solid #1e3a8a; border-bottom: 2px solid #1e3a8a; text-transform: uppercase; }
        .cert-meta { display: flex; justify-content: space-between; font-size: 13.5px; margin-bottom: 24px; color: #334155; }
        .cert-meta b { color: #0f172a; font-family: monospace; font-size: 14px; }
        .cert-details { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 40px; margin: 24px 0 28px; padding: 18px 22px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; }
        .cert-details .row-i { display: flex; font-size: 13.5px; padding: 7px 0; border-bottom: 1px solid #e2e8f0; }
        .cert-details .row-i:nth-last-child(-n+2) { border-bottom: 0; }
        .cert-details .lbl { flex: 0 0 140px; font-weight: 700; color: #64748b; text-transform: uppercase; font-size: 11px; letter-spacing: .3px; padding-top: 3px; }
        .cert-details .val { flex: 1; font-weight: 600; color: #0f172a; }
        .cert-body { font-size: 15px; line-height: 2; text-align: justify; margin-bottom: 28px; white-space: pre-wrap; }
        .cert-signatures { display: flex; justify-content: space-between; gap: 40px; margin-top: 70px; }
        .sign-box { flex: 1; text-align: center; }
        .sign-box .line { border-top: 1.5px solid #1e293b; margin-bottom: 8px; }
        .sign-box .role { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: #1e293b; }
        .sign-box .sub { font-size: 11px; color: #94a3b8; margin-top: 3px; }
        .cert-footer { text-align: center; font-size: 11px; color: #94a3b8; margin-top: 45px; padding-top: 14px; border-top: 1px solid #e2e8f0; }

        @media print {
            @page { size: A4 portrait; margin: 0; }
            html, body {
                background: #fff !important; color: #000 !important;
                margin: 0 !important; padding: 0 !important;
                width: 100% !important; height: 100% !important;
                font-family: 'Inter', Arial, sans-serif !important;
                font-size: 12pt !important;
            }
            body > * { display: none !important; }
            body > .cert-section { display: block !important; }
            .cert-actions { display: none !important; }
            .cert-section, .cert-section * { visibility: visible !important; }
            .cert-section {
                position: absolute !important; top: 0 !important; left: 0 !important;
                width: 100% !important; height: 100% !important;
                margin: 0 !important; padding: 0 !important; background: #fff !important;
            }
            .cert {
                display: block !important;
                width: 100% !important; max-width: 100% !important; height: 100% !important;
                margin: 0 !important; padding: 20mm 18mm !important;
                border: none !important; box-shadow: none !important;
                background: #fff !important;
                font-size: 12pt !important; line-height: 1.75 !important; color: #000 !important;
                box-sizing: border-box !important;
                page-break-inside: avoid !important; break-inside: avoid !important;
            }
            .cert * { color: #000 !important; background: transparent !important; box-shadow: none !important; visibility: visible !important; }
            .cert-header { border-bottom-color: #000 !important; }
            .cert-logo { border-color: #000 !important; background: #fff !important; }
            .cert-title { border-color: #000 !important; }
            .cert-details { border-color: #666 !important; background: #fff !important; }
            .cert-details .row-i { border-bottom-color: #ccc !important; }
            .cert-details .lbl { color: #333 !important; }
            .cert-details .val { color: #000 !important; }
            .sign-box .line { border-top-color: #000 !important; }
            .sign-box .role { color: #000 !important; }
            .cert-footer { border-top-color: #ccc !important; color: #555 !important; }
            .cert-school { font-size: 24pt !important; }
            .cert-sub { font-size: 11pt !important; }
            .cert-title { font-size: 18pt !important; letter-spacing: 6px !important; }
            .cert-meta { font-size: 12pt !important; }
            .cert-body { font-size: 13pt !important; line-height: 1.9 !important; }
            .cert-details .row-i { font-size: 12pt !important; }
            .sign-box .role { font-size: 12pt !important; }
            .cert-footer { font-size: 10pt !important; }
        }
    </style>
</head>
<body>
<?php require_once 'navbar.php'; ?>

<div class="container" style="padding: 20px; max-width: 1100px; margin: 0 auto;">

    <div class="page-title-bar">
        <div>
            <h1><i class="fas fa-scroll"></i> School Leaving Certificate</h1>
            <div class="subtitle">Generate or update a leaving certificate for a student</div>
        </div>
        <a href="student_leaving_certificate_list.php" class="btn btn-secondary btn-sm" style="padding: 8px 18px;">
            <i class="fas fa-list"></i> All Certificates
        </a>
    </div>

    <?php if ($save_msg): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= h($save_msg) ?></div>
    <?php endif; ?>
    <?php if ($save_err): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= h($save_err) ?></div>
    <?php endif; ?>

    <div class="panel">
        <div class="panel-body">

            <ul class="nav nav-tabs" id="wizardTabs" role="tablist">
                <li class="nav-item"><a class="nav-link <?= $step === 1 ? 'active' : ($step > 1 ? 'completed' : '') ?>" data-step="1" href="#step1"><span class="step-num">1</span> Select Student</a></li>
                <li class="nav-item"><a class="nav-link <?= $step === 2 ? 'active' : ($step > 2 ? 'completed' : '') ?>" data-step="2" href="#step2"><span class="step-num">2</span> Certificate Details</a></li>
            </ul>

            <div class="tab-content">

                <div class="tab-pane <?= $step === 1 ? 'show active' : '' ?>" id="step1">
                    <div class="form-section">
                        <h5>Find Student</h5>

                        <form method="post" action="" id="searchForm">
                            <input type="hidden" name="step" value="1">
                            <div class="search-filter-row">
                                <select name="class_filter" id="classFilter" class="class-select">
                                    <option value="">All Classes</option>
                                    <?php foreach ($class_options as $opt):
                                        $val = $opt['class_id'] . '|' . $opt['class_section_id'];
                                    ?>
                                        <option value="<?= h($val) ?>" <?= $class_filter === $val ? 'selected' : '' ?>>
                                            <?= h($opt['display_label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="search-wrap">
                                    <input type="text" name="search" id="searchInput" class="search-input"
                                           placeholder="Type student name, reg no, or father name..."
                                           value="<?= h($search) ?>" autocomplete="off">
                                </div>
                            </div>
                        </form>

                        <?php if ($has_filter): ?>
                            <div class="search-results">
                                <?php if ($search_results): ?>
                                    <?php foreach ($search_results as $s):
                                        $cl = trim(($s['class_title'] ?? '') . (!empty($s['section_title']) ? ' - ' . $s['section_title'] : ''));
                                        $student_has_cert = false;
                                        $cert_check = $conn->prepare("SELECT cert_no FROM student_certificates WHERE student_id = ? AND status = 0 LIMIT 1");
                                        if ($cert_check) {
                                            $cert_check->bind_param("i", $s['id']);
                                            $cert_check->execute();
                                            $rr = $cert_check->get_result();
                                            if ($rr && $rr->num_rows > 0) $student_has_cert = true;
                                            $cert_check->close();
                                        }
                                    ?>
                                        <form method="post" action="" style="margin:0;">
                                            <input type="hidden" name="step" value="2">
                                            <input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
                                            <input type="hidden" name="search" value="<?= h($search) ?>">
                                            <input type="hidden" name="class_filter" value="<?= h($class_filter) ?>">
                                            <button type="submit" name="go_next" value="1" style="border:0; background:none; width:100%; padding:0; text-align:left; cursor:pointer;">
                                                <div class="search-result <?= $student_has_cert ? 'has-cert' : '' ?>">
                                                    <div class="left">
                                                        <div class="name"><?= h($s['name']) ?></div>
                                                        <div class="meta">
                                                            <span class="badge-soft"><?= h($s['reg_no']) ?></span>
                                                            <?= h($cl ?: '—') ?>
                                                            &middot; Father: <?= h($s['father_name'] ?: '—') ?>
                                                        </div>
                                                    </div>
                                                    <div class="right">
                                                        <?php if ($student_has_cert): ?>
                                                            <span class="go-btn"><i class="fas fa-edit"></i> Edit</span>
                                                        <?php else: ?>
                                                            <span class="go-btn">Select &rarr;</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </button>
                                        </form>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="no-results">
                                        <i class="fas fa-search"></i>
                                        No students matched your filters
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="no-results">
                                <i class="fas fa-info-circle"></i>
                                Select a class or start typing to search for a student
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="tab-pane <?= $step === 2 ? 'show active' : '' ?>" id="step2">
                    <?php if ($sel): ?>

                        <?php if ($existing_cert): ?>
                            <div class="alert-edit-mode">
                                <i class="fas fa-info-circle"></i>
                                This student already has a certificate (<b><?= h($existing_cert['cert_no']) ?></b>, issued <?= h(fmt_date($existing_cert['cert_date'])) ?>).
                                Any changes below will <b>update the existing certificate</b>.
                            </div>
                        <?php endif; ?>

                        <div class="student-card">
                            <div class="field"><span class="k">Name:</span> <b><?= h($sel['name']) ?></b></div>
                            <div class="field"><span class="k">Reg No:</span> <b><?= h($sel['reg_no']) ?></b></div>
                            <div class="field"><span class="k">Father:</span> <b><?= h($sel['father_name'] ?: '—') ?></b></div>
                            <div class="field"><span class="k">Gender:</span> <b><?= h($sel['gender'] ?: '—') ?></b></div>
                            <div class="field"><span class="k">Class:</span> <b><?php
                                $cl = trim(($sel['class_title'] ?? '') . (!empty($sel['section_title']) ? ' - ' . $sel['section_title'] : ''));
                                echo h($cl ?: '—');
                            ?></b></div>
                            <div class="field"><span class="k">Session:</span> <b><?= h($sel['session_title'] ?: '—') ?></b></div>
                        </div>

                        <form method="post" action="">
                            <input type="hidden" name="step" value="2">
                            <input type="hidden" name="student_id" value="<?= (int)$student_id ?>">
                            <input type="hidden" name="search" value="<?= h($search) ?>">
                            <input type="hidden" name="class_filter" value="<?= h($class_filter) ?>">

                            <div class="form-section">
                                <h5>Certificate Details</h5>
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label>Certificate No.</label>
                                            <input type="text" name="cert_no" class="form-control" value="<?= h($cert_no) ?>" placeholder="Auto-generate" <?= $existing_cert ? 'readonly' : '' ?>>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label>Certificate Date</label>
                                            <input type="date" name="cert_date" class="form-control" value="<?= h($cert_date) ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label>Date of Leaving <span class="text-danger">*</span></label>
                                            <input type="date" name="leaving_date" class="form-control" value="<?= h($leaving_date) ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label>Conduct</label>
                                            <select name="conduct" class="form-control">
                                                <?php foreach (['Excellent','Very Good','Good','Satisfactory','Fair'] as $c): ?>
                                                    <option value="<?= h($c) ?>" <?= $conduct === $c ? 'selected' : '' ?>><?= h($c) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>Reason for Leaving</label>
                                            <input type="text" name="reason" class="form-control" value="<?= h($reason) ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>To School (where going next)</label>
                                            <input type="text" name="to_school" class="form-control" value="<?= h($to_school) ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>Issued By (Designation)</label>
                                            <input type="text" name="issued_by" class="form-control" value="<?= h($issued_by) ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>Remarks (Optional)</label>
                                            <input type="text" name="remarks" class="form-control" value="<?= h($remarks) ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>
                                        Certificate Body Text
                                        <button type="submit" name="autofill_body" value="1" class="btn btn-info btn-sm ml-2" style="padding:3px 12px; font-size:12px;">
                                            <i class="fas fa-magic"></i> Auto-Generate Body
                                        </button>
                                    </label>
                                    <textarea name="body_text" class="form-control" rows="9"><?= h($body_text) ?></textarea>
                                    <small class="text-muted">Click <b>Auto-Generate Body</b> to fill this based on the student's gender. Review and edit before saving.</small>
                                </div>

                                <div class="mt-3">
                                    <?php if ($existing_cert): ?>
                                        <button type="submit" name="final_generate" value="1" class="btn btn-warning"
                                                onclick="return confirm('Update the existing certificate?');">
                                            <i class="fas fa-save"></i> Update Certificate
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" name="final_generate" value="1" class="btn btn-success"
                                                onclick="return confirm('Generate certificate and mark this student as LEFT?');">
                                            <i class="fas fa-check-circle"></i> Generate Certificate
                                        </button>
                                    <?php endif; ?>
                                    <a href="?step=1" class="btn btn-secondary">
                                        <i class="fas fa-times"></i> Cancel
                                    </a>
                                </div>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-warning">No student selected. <a href="?step=1">Go back to Step 1</a>.</div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

</div>

<?php if ($show_certificate): ?>
<div class="cert-section">
    <div class="container" style="max-width: 1100px; margin: 0 auto;">
        <div class="cert-actions">
            <button type="button" class="btn btn-primary" onclick="window.print();">
                <i class="fas fa-print"></i> Print Certificate
            </button>
            <a href="?step=1" class="btn btn-secondary">
                <i class="fas fa-plus"></i> Issue Another
            </a>
            <a href="student_leaving_certificate_list.php" class="btn btn-secondary">
                <i class="fas fa-list"></i> All Certificates
            </a>
        </div>

        <div class="cert">
            <div class="cert-header">
                <div class="cert-logo">
                    <?php if ($school_logo): ?><img src="<?= h($school_logo) ?>" alt=""><?php else: ?><i class="fas fa-graduation-cap"></i><?php endif; ?>
                </div>
                <div class="cert-head-txt">
                    <h1 class="cert-school"><?= h($school['name']) ?></h1>
                    <div class="cert-sub">
                        <?php if ($school['address']): ?><span><?= h($school['address']) ?></span><?php endif; ?>
                        <?php if ($school['phone']): ?><span>· <?= h($school['phone']) ?></span><?php endif; ?>
                        <?php if ($school['email']): ?><span>· <?= h($school['email']) ?></span><?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="cert-title-block">
                <span class="cert-title">School Leaving Certificate</span>
            </div>

            <div class="cert-meta">
                <div>Certificate No: <b><?= h($cert_no ?: '—') ?></b></div>
                <div>Date: <b><?= h(fmt_date($cert_date) ?: '—') ?></b></div>
            </div>

            <div class="cert-details">
                <div class="row-i"><div class="lbl">Student Name</div><div class="val"><?= h($sel['name']) ?></div></div>
                <div class="row-i"><div class="lbl">Registration No</div><div class="val"><?= h($sel['reg_no']) ?></div></div>
                <div class="row-i"><div class="lbl">Father Name</div><div class="val"><?= h($sel['father_name'] ?: '—') ?></div></div>
                <div class="row-i"><div class="lbl">Date of Birth</div><div class="val"><?= h(fmt_date($sel['dob']) ?: '—') ?></div></div>
                <div class="row-i"><div class="lbl">Admission No</div><div class="val"><?= h($sel['admission_no'] ?: '—') ?></div></div>
                <div class="row-i"><div class="lbl">Admission Date</div><div class="val"><?= h(fmt_date($sel['admission_date']) ?: '—') ?></div></div>
                <div class="row-i"><div class="lbl">Class</div><div class="val"><?php
                    $cl = trim(($sel['class_title'] ?? '') . (!empty($sel['section_title']) ? ' - ' . $sel['section_title'] : ''));
                    echo h($cl ?: '—');
                ?></div></div>
                <div class="row-i"><div class="lbl">Session</div><div class="val"><?= h($sel['session_title'] ?: '—') ?></div></div>
                <div class="row-i"><div class="lbl">Branch</div><div class="val"><?= h($sel['branch_title'] ?: '—') ?></div></div>
                <div class="row-i"><div class="lbl">Village Council</div><div class="val"><?= h($sel['village_title'] ?: '—') ?></div></div>
                <div class="row-i"><div class="lbl">Date of Leaving</div><div class="val"><?= h(fmt_date($leaving_date) ?: '—') ?></div></div>
                <div class="row-i"><div class="lbl">Conduct</div><div class="val"><?= h($conduct) ?></div></div>
            </div>

            <div class="cert-body"><?= h($body_text) ?></div>

            <?php if ($to_school !== ''): ?>
                <div style="font-size:14px; line-height:1.9; margin-bottom:22px;">
                    <b>To:</b> <?= h($to_school) ?>
                </div>
            <?php endif; ?>

            <div class="cert-signatures">
                <div class="sign-box">
                    <div class="line"></div>
                    <div class="role">Class Teacher</div>
                    <div class="sub">Signature</div>
                </div>
                <div class="sign-box">
                    <div class="line"></div>
                    <div class="role">Accountant</div>
                    <div class="sub">No Dues Verified</div>
                </div>
                <div class="sign-box">
                    <div class="line"></div>
                    <div class="role"><?= h($issued_by) ?></div>
                    <div class="sub">School Seal</div>
                </div>
            </div>

            <?php if ($remarks !== ''): ?>
                <div style="text-align:center; font-size:13px; margin-top:24px; font-style:italic; color:#334155;">
                    <?= h($remarks) ?>
                </div>
            <?php endif; ?>

            <div class="cert-footer">
                This certificate is issued on the basis of the school records. Any alteration renders it void.
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
$(document).ready(function () {
    var searchInput = document.getElementById('searchInput');
    var classFilter = document.getElementById('classFilter');
    var searchForm = document.getElementById('searchForm');
    var timer = null;

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () { searchForm.submit(); }, 400);
        });
    }
    if (classFilter) {
        classFilter.addEventListener('change', function () { searchForm.submit(); });
    }
});
</script>

<?php if ($auto_print && $show_certificate): ?>
<script>
window.addEventListener('load', function () {
    setTimeout(function () { window.print(); }, 500);
});
</script>
<?php endif; ?>
</body>
</html>
<?php $conn->close(); ?>