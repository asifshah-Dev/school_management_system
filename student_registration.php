<?php
header('Content-Type: text/html; charset=utf-8');
require_once('security.php');
require_once('conn_inc.php');
require_once('permissions.php');

/* ==== AUTHORIZATION GATE ==== */
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
/* ==== END AUTHORIZATION GATE ==== */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION['lang'] = 'en';
$lang = $_SESSION['lang'];

$translations = [
    'en' => [
        'title' => 'Student Registration',
        'add_title' => 'Register New Student',
        'edit_title' => 'Edit Student Registration',
        'submit' => 'Save Registration',
        'save' => 'Save',
        'save_next' => 'Save & Next',
        'next' => 'Next',
        'back' => 'Back',
        'reset' => 'Reset Form',
        'cancel' => 'Cancel',
        'success' => 'Saved successfully.',
        'update_success' => 'Student information saved.',
        'error' => 'Error: ',
        'select_option' => '-- Select --',
        'view_students' => 'View Student List',
        'tab_basic' => 'Basic Info',
        'tab_parents' => 'Parents & Guardian',
        'tab_address' => 'Address & Contact',
        'tab_academic' => 'Academic & Fees',
        'tab_parent_login' => 'Parent Login',
    ]
];

// ---------- Dropdowns ----------
$branches = []; $village_councils = []; $sessions = []; $class_options = []; $fee_types = [];

$branch_result = $conn->query("SELECT id, title FROM branches ORDER BY title");
if ($branch_result) {
    while ($row = $branch_result->fetch_assoc()) $branches[$row['id']] = $row['title'];
}

$vc_result = $conn->query("SELECT id, title, transport_fee FROM village_councils ORDER BY title");
if ($vc_result) {
    while ($row = $vc_result->fetch_assoc()) {
        $village_councils[$row['id']] = ['title' => $row['title'], 'transport_fee' => $row['transport_fee']];
    }
}

$session_result = $conn->query("SELECT id, title FROM sessions ORDER BY id DESC");
if ($session_result) {
    while ($row = $session_result->fetch_assoc()) $sessions[$row['id']] = $row['title'];
}

$class_result = $conn->query("
    SELECT COALESCE(cs.id, 0) AS class_section_id, c.id AS class_id, c.title AS class_title, s.title AS section_title,
    CASE WHEN cs.id IS NULL THEN c.title ELSE CONCAT(c.title, ' - ', s.title) END AS display_label
    FROM classes c
    LEFT JOIN class_sections cs ON cs.class_id = c.id AND cs.status = 0
    LEFT JOIN sections s ON s.id = cs.section_id
    WHERE c.status = 0 ORDER BY c.title, s.title
");
if ($class_result) {
    while ($row = $class_result->fetch_assoc()) $class_options[] = $row;
}

$ft_result = $conn->query("SELECT id, title, type FROM fee_types ORDER BY title");
if ($ft_result) {
    while ($row = $ft_result->fetch_assoc()) $fee_types[$row['id']] = $row;
}

$month_map = [
    'jan'=>'01','january'=>'01','feb'=>'02','february'=>'02','mar'=>'03','march'=>'03',
    'apr'=>'04','april'=>'04','may'=>'05','jun'=>'06','june'=>'06','jul'=>'07','july'=>'07',
    'aug'=>'08','august'=>'08','sep'=>'09','sept'=>'09','september'=>'09',
    'oct'=>'10','october'=>'10','nov'=>'11','november'=>'11','dec'=>'12','december'=>'12'
];

// ---------- AJAX fee handlers ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => ''];

    if ($action === 'add_fee') {
        $student_id = intval($_POST['student_id']);
        $fee_type_id = intval($_POST['fee_type_id']);
        $amount = floatval($_POST['amount']);
        $assigned_date = trim($_POST['assigned_date']);

        if ($student_id && $fee_type_id && $amount > 0 && $assigned_date) {
            $stmt = $conn->prepare("INSERT INTO student_fee_assignments (student_id, fee_type_id, amount, assigned_date, status) VALUES (?, ?, ?, ?, 1)");
            if ($stmt) {
                $stmt->bind_param("iids", $student_id, $fee_type_id, $amount, $assigned_date);
                if ($stmt->execute()) {
                    $response['success'] = true;
                    $response['message'] = 'Fee added successfully';
                    $response['id'] = $conn->insert_id;
                    $ft = $conn->prepare("SELECT title FROM fee_types WHERE id = ?");
                    if ($ft) {
                        $ft->bind_param("i", $fee_type_id);
                        $ft->execute();
                        $ft_res = $ft->get_result();
                        if ($ft_res) {
                            $ft_row = $ft_res->fetch_assoc();
                            $response['fee_type_title'] = $ft_row['title'];
                        }
                        $ft->close();
                    }
                    $response['amount'] = number_format($amount, 2);
                    $response['assigned_date'] = $assigned_date;
                } else {
                    $response['message'] = 'Insert failed: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $response['message'] = 'Insert prepare failed: ' . $conn->error;
            }
        } else {
            $response['message'] = 'Invalid data provided.';
        }
    } elseif ($action === 'delete_fee') {
        $fee_id = intval($_POST['fee_id']);
        $stmt = $conn->prepare("DELETE FROM student_fee_assignments WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $fee_id);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Fee removed successfully';
            }
            $stmt->close();
        }
    }
    echo json_encode($response);
    exit;
}

// ---------- Auto-generate Reg No ----------
function generate_reg_no($conn) {
    $year = date('Y');
    $prefix = 'REG-' . $year . '-';
    $sql = "SELECT reg_no FROM student_registration WHERE reg_no LIKE ? ORDER BY id DESC LIMIT 1";
    $like = $prefix . '%';
    $stmt = $conn->prepare($sql);
    $next = 1;
    if ($stmt) {
        $stmt->bind_param("s", $like);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
            $last = $row['reg_no'];
            $parts = explode('-', $last);
            $num = intval(end($parts));
            if ($num > 0) $next = $num + 1;
        }
        $stmt->close();
    }
    return $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
}

// ---------- Edit mode Data Loading ----------
$student_data = [
    'id' => '', 'branch_id' => '1', 'village_council_id' => '', 'reg_no' => '',
    'admission_no' => '', 'admission_date' => '', 'roll_no' => '',
    'session_id' => '', 'class_id' => '', 'class_section_id' => '',
    'name' => '', 'dob_day' => '', 'dob_month' => '', 'dob_year' => '',
    'gender' => '', 'gender_other' => '', 'b_form_no' => '', 'student_mobile' => '',
    'religion' => '', 'house' => '',
    'father_name' => '', 'father_occupation' => '', 'father_qualification' => '',
    'father_income' => '', 'father_phone' => '',
    'mobile' => '', 'cnic' => '',
    'current_address' => '', 'permanent_address' => '',
    'guardian_name' => '', 'guardian_mobile' => '', 'guardian_address' => '',
    'guardian_cnic' => '', 'guardian_relation' => '',
    'emergency_name' => '', 'emergency_phone' => '', 'medical_history' => '',
    'previous_schools_description' => '', 'student_other_description' => '',
    'image_path' => '', 'transport_fee' => '0', 'is_transport' => '1',
    'registration_date' => date('Y-m-d'), 'is_old_dues' => '0', 'old_dues_amount' => '0',
    'is_admission' => '1'
];

$edit_mode = false;
$existing_parent_links = [];

if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM student_registration WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $edit_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $student_data = $result->fetch_assoc();

            if (!empty($student_data['dob']) && $student_data['dob'] !== '0000-00-00') {
                $d = new DateTime($student_data['dob']);
                $student_data['dob_year'] = $d->format('Y');
                $student_data['dob_month'] = $d->format('m');
                $student_data['dob_day'] = $d->format('d');
            }
            if (!isset($student_data['gender_other'])) $student_data['gender_other'] = '';
            if (!isset($student_data['guardian_relation'])) $student_data['guardian_relation'] = '';

            $c_stmt = $conn->prepare("SELECT class_id, class_section_id, session_id FROM student_class WHERE student_registration_id = ? ORDER BY id DESC LIMIT 1");
            if ($c_stmt) {
                $c_stmt->bind_param("i", $edit_id);
                $c_stmt->execute();
                $c_res = $c_stmt->get_result();
                if ($c_res && $c_row = $c_res->fetch_assoc()) {
                    $student_data['class_id'] = $c_row['class_id'];
                    $student_data['class_section_id'] = $c_row['class_section_id'];
                    $student_data['session_id'] = $c_row['session_id'];
                }
                $c_stmt->close();
            }

            $p_stmt = $conn->prepare("
                SELECT u.id, u.username, u.email, u.mobile_no, ps.relationship, ps.is_primary
                FROM parent_students ps
                JOIN users u ON u.id = ps.parent_user_id
                WHERE ps.student_id = ?
                ORDER BY ps.is_primary DESC
            ");
            if ($p_stmt) {
                $p_stmt->bind_param("i", $edit_id);
                $p_stmt->execute();
                $p_res = $p_stmt->get_result();
                if ($p_res) {
                    while ($p_row = $p_res->fetch_assoc()) {
                        $existing_parent_links[] = $p_row;
                    }
                }
                $p_stmt->close();
            }

            $edit_mode = true;
        }
        $stmt->close();
    }
}

if (!$edit_mode) {
    $student_data['reg_no'] = generate_reg_no($conn);
}

$existing_fees = [];
if ($edit_mode && !empty($student_data['id'])) {
    $stmt = $conn->prepare("SELECT sfa.id, ft.title AS fee_type_title, sfa.amount, sfa.assigned_date FROM student_fee_assignments sfa JOIN fee_types ft ON sfa.fee_type_id = ft.id WHERE sfa.student_id = ? ORDER BY sfa.id DESC");
    if ($stmt) {
        $stmt->bind_param("i", $student_data['id']);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) $existing_fees[] = $row;
        }
        $stmt->close();
    }
}

// ---------- Form Submission (Save / Save & Next) ----------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['student_id']) && isset($_POST['save_action'])) {

    function clean($conn, $key, $default = '') {
        return mysqli_real_escape_string($conn, trim($_POST[$key] ?? $default));
    }

    $student_id = intval($_POST['student_id']);
    $branch_id = 1;
    $village_council_id = intval($_POST['village_council_id'] ?? 0);
    $reg_no = clean($conn, 'reg_no');
    $session_id = intval($_POST['session_id'] ?? 0);

    $gender = clean($conn, 'gender');
    $gender_other = clean($conn, 'gender_other');
    if ($gender === 'Other' && $gender_other !== '') {
        $gender = $gender_other;
    }

    $combo = trim($_POST['class_combo'] ?? '');
    if (strpos($combo, '|') !== false) {
        $parts = array_map('intval', explode('|', $combo, 2));
        $class_id = $parts[0];
        $class_section_id = $parts[1];
    } else {
        $class_id = intval($combo);
        $class_section_id = 0;
    }
    if ($class_section_id === 0) $class_section_id = null;

    /* ═══════════════════════════════════════════════════
       SAME AS CURRENT ADDRESS: mirror on server side too
       ═══════════════════════════════════════════════════ */
    $current_address = clean($conn, 'current_address');
    $permanent_address = clean($conn, 'permanent_address');
    if (!empty($_POST['permanent_same_as_current'])) {
        $permanent_address = $current_address;
    }

    $fields = [
        'admission_no' => clean($conn, 'admission_no'),
        'admission_date' => clean($conn, 'admission_date') ?: null,
        'roll_no' => clean($conn, 'roll_no'),
        'name' => clean($conn, 'name'),
        'gender' => $gender,
        'b_form_no' => clean($conn, 'b_form_no'),
        'student_mobile' => clean($conn, 'student_mobile'),
        'religion' => clean($conn, 'religion'),
        'house' => clean($conn, 'house'),
        'father_name' => clean($conn, 'father_name'),
        'father_occupation' => clean($conn, 'father_occupation'),
        'father_qualification' => clean($conn, 'father_qualification'),
        'father_income' => (isset($_POST['father_income']) && $_POST['father_income'] !== '') ? floatval($_POST['father_income']) : null,
        'father_phone' => clean($conn, 'father_phone'),
        'cnic' => clean($conn, 'cnic') ?: null,
        'current_address' => $current_address,
        'permanent_address' => $permanent_address,
        'guardian_name' => clean($conn, 'guardian_name'),
        'guardian_mobile' => clean($conn, 'guardian_mobile'),
        'guardian_address' => clean($conn, 'guardian_address'),
        'guardian_cnic' => clean($conn, 'guardian_cnic') ?: null,
        'emergency_name' => clean($conn, 'emergency_name'),
        'emergency_phone' => clean($conn, 'emergency_phone'),
        'medical_history' => clean($conn, 'medical_history'),
        'previous_schools_description' => clean($conn, 'previous_schools_description'),
        'student_other_description' => clean($conn, 'student_other_description'),
        'transport_fee' => floatval($_POST['transport_fee'] ?? 0),
        'is_transport' => isset($_POST['is_transport']) ? 0 : 1,
        'registration_date' => clean($conn, 'registration_date'),
        'is_old_dues' => isset($_POST['is_old_dues']) ? 1 : 0,
        'old_dues_amount' => floatval($_POST['old_dues_amount'] ?? 0),
    ];

    $chk_col = $conn->query("SHOW COLUMNS FROM student_registration LIKE 'guardian_relation'");
    if ($chk_col && $chk_col->num_rows > 0) {
        $fields['guardian_relation'] = clean($conn, 'guardian_relation');
    }

    $dob = null;
    $dob_day = trim($_POST['dob_day'] ?? '');
    $dob_month = strtolower(trim($_POST['dob_month'] ?? ''));
    $dob_year = trim($_POST['dob_year'] ?? '');

    if ($dob_day && $dob_month && $dob_year) {
        $month_num = isset($month_map[$dob_month]) ? $month_map[$dob_month] : (is_numeric($dob_month) ? str_pad(intval($dob_month), 2, '0', STR_PAD_LEFT) : '');
        if ($month_num && checkdate(intval($month_num), intval($dob_day), intval($dob_year))) {
            $dob = sprintf('%04d-%02d-%02d', intval($dob_year), intval($month_num), intval($dob_day));
        }
    }
    $fields['dob'] = $dob;

    $image_path = isset($student_data['image_path']) ? $student_data['image_path'] : '';

    if (!empty($_POST['remove_image']) && $image_path !== '') {
        if (file_exists($image_path)) @unlink($image_path);
        $image_path = '';
    }

    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK && !empty($_FILES['image']['name'])) {
        $target_dir = "Uploads/students/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (in_array($ext, $allowed) && $_FILES['image']['size'] <= 5 * 1024 * 1024) {
            $safe_name = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($_FILES['image']['name']));
            $target_file = $target_dir . $safe_name;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
                if ($image_path !== '' && $image_path !== $target_file && file_exists($image_path)) {
                    @unlink($image_path);
                }
                $image_path = $target_file;
            }
        }
    }
    $fields['image_path'] = $image_path;

    $set_parts = [];
    foreach ($fields as $col => $val) {
        if ($val === null) {
            $set_parts[] = "$col = NULL";
        } elseif (is_int($val) || is_float($val)) {
            $set_parts[] = "$col = $val";
        } else {
            $set_parts[] = "$col = '$val'";
        }
    }
    $set_clause = implode(", ", $set_parts);

    $final_student_id = $student_id;

    if ($student_id > 0) {
        $sql = "UPDATE student_registration SET $set_clause WHERE id = $student_id";
        if ($conn->query($sql)) {
            $check_stmt = $conn->prepare("SELECT id FROM student_class WHERE student_registration_id = ? ORDER BY id DESC LIMIT 1");
            if ($check_stmt) {
                $check_stmt->bind_param("i", $student_id);
                $check_stmt->execute();
                $cr = $check_stmt->get_result();

                if ($cr && $cr->num_rows > 0) {
                    $row = $cr->fetch_assoc();
                    $upd = $conn->prepare("UPDATE student_class SET session_id=?, class_id=?, class_section_id=? WHERE id=?");
                    if ($upd) {
                        $upd->bind_param("iiii", $session_id, $class_id, $class_section_id, $row['id']);
                        $upd->execute();
                        $upd->close();
                    }
                } else {
                    $ins = $conn->prepare("INSERT INTO student_class (student_registration_id, session_id, class_id, class_section_id) VALUES (?, ?, ?, ?)");
                    if ($ins) {
                        $ins->bind_param("iiii", $student_id, $session_id, $class_id, $class_section_id);
                        $ins->execute();
                        $ins->close();
                    }
                }
                $check_stmt->close();
            }
            $_SESSION['message'] = $translations[$lang]['update_success'];
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = $translations[$lang]['error'] . $conn->error;
            $_SESSION['message_type'] = 'danger';
        }
    } else {
        $fields['reg_no'] = $reg_no;

        $cols = implode(", ", array_keys($fields));
        $vals = implode(", ", array_map(function($v) {
            if ($v === null) return "NULL";
            if (is_int($v) || is_float($v)) return $v;
            return "'$v'";
        }, array_values($fields)));

        $sql = "INSERT INTO student_registration ($cols) VALUES ($vals)";
        if ($conn->query($sql)) {
            $final_student_id = $conn->insert_id;
            $ins = $conn->prepare("INSERT INTO student_class (student_registration_id, session_id, class_id, class_section_id) VALUES (?, ?, ?, ?)");
            if ($ins) {
                $ins->bind_param("iiii", $final_student_id, $session_id, $class_id, $class_section_id);
                $ins->execute();
                $ins->close();
            }
            $_SESSION['message'] = $translations[$lang]['success'];
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = $translations[$lang]['error'] . $conn->error;
            $_SESSION['message_type'] = 'danger';
        }
    }

    if (!empty($_POST['create_parent_account'])) {
        $p_username   = trim($_POST['parent_username'] ?? '');
        $p_password   = trim($_POST['parent_password'] ?? '');
        $p_email      = trim($_POST['parent_email'] ?? '');
        $p_mobile     = trim($_POST['parent_mobile'] ?? '');
        $p_rel        = $_POST['parent_relationship'] ?? 'father';
        $p_is_primary = !empty($_POST['parent_is_primary']) ? 1 : 0;

        if ($final_student_id > 0 && !empty($p_username)) {
            $chk = $conn->prepare("SELECT id FROM users WHERE username = ?");
            if ($chk) {
                $chk->bind_param("s", $p_username);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    $_SESSION['message'] .= " <br><span class='text-warning'>Warning: Parent username '$p_username' already exists.</span>";
                } else {
                    if (empty($p_password)) $p_password = bin2hex(random_bytes(4));
                    $u_stmt = $conn->prepare("INSERT INTO users (role_id, username, password, mobile_no, email, status) VALUES (6, ?, ?, ?, ?, 0)");
                    if ($u_stmt) {
                        $u_stmt->bind_param("ssss", $p_username, $p_password, $p_mobile, $p_email);
                        if ($u_stmt->execute()) {
                            $parent_user_id = $conn->insert_id;
                            if ($p_is_primary) {
                                $conn->query("UPDATE parent_students SET is_primary = 0 WHERE student_id = $final_student_id");
                            }
                            $l_stmt = $conn->prepare("INSERT INTO parent_students (parent_user_id, student_id, relationship, is_primary) VALUES (?, ?, ?, ?)");
                            if ($l_stmt) {
                                $l_stmt->bind_param("iisi", $parent_user_id, $final_student_id, $p_rel, $p_is_primary);
                                if ($l_stmt->execute()) {
                                    $_SESSION['message'] .= " <br><span class='text-success'>Parent account '$p_username' created.</span>";
                                }
                                $l_stmt->close();
                            }
                        }
                        $u_stmt->close();
                    }
                }
                $chk->close();
            }
        }
    }

    $save_action = $_POST['save_action'];
    $current_step = intval($_POST['current_step'] ?? 0);

    if ($save_action === 'save_next') {
        $next_step_after_save = $current_step + 1;
        if ($next_step_after_save > 4) $next_step_after_save = 4;
        header("Location: student_registration.php?edit=" . $final_student_id . "&step=" . $next_step_after_save);
        exit();
    } else {
        header("Location: student_registration.php?edit=" . $final_student_id . "&step=4");
        exit();
    }
}

$initial_step = 0;
if (isset($_GET['step']) && is_numeric($_GET['step'])) {
    $initial_step = intval($_GET['step']);
    if ($initial_step < 0) $initial_step = 0;
    if ($initial_step > 4) $initial_step = 4;
}

/* Determine if permanent == current on load (for auto-tick) */
$permanent_same_as_current_default = false;
if ($edit_mode && !empty($student_data['current_address']) && $student_data['current_address'] === $student_data['permanent_address']) {
    $permanent_same_as_current_default = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo htmlspecialchars($translations[$lang]['title']); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f5f7fa; padding-bottom: 100px; }

        .page-title-bar {
            display: flex; align-items: center; justify-content: space-between;
            margin: 0 0 18px 0; flex-wrap: wrap; gap: 10px;
        }
        .page-title-bar h1 {
            font-size: 22px; font-weight: 700; color: #1e293b;
            margin: 0; display: flex; align-items: center; gap: 10px;
        }
        .page-title-bar h1 i { color: #2563eb; }
        .page-title-bar .subtitle {
            font-size: 13px; color: #64748b; margin-top: 4px;
        }

        .panel { background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 30px; overflow: hidden; }

        .nav-tabs { border-bottom: 2px solid #dee2e6; margin-bottom: 20px; }
        .nav-tabs .nav-link {
            border: none; color: #6c757d; font-weight: 600; padding: 12px 20px;
            border-bottom: 3px solid transparent; transition: all 0.3s;
            position: relative; cursor: pointer;
        }
        .nav-tabs .nav-link:hover { color: #2563eb; border-color: transparent; background: #f8f9fa; }
        .nav-tabs .nav-link.active {
            color: #2563eb; border-bottom: 3px solid #2563eb; background: transparent;
        }
        .nav-tabs .nav-link .step-num {
            display: inline-block; width: 22px; height: 22px; line-height: 22px;
            text-align: center; border-radius: 50%; background: #e2e8f0; color: #64748b;
            font-size: 11px; font-weight: 700; margin-right: 6px;
        }
        .nav-tabs .nav-link.active .step-num { background: #2563eb; color: #fff; }
        .nav-tabs .nav-link.completed .step-num { background: #10b981; color: #fff; }

        .tab-content { padding: 20px 0; }
        .form-section { margin-bottom: 20px; }
        .form-section h5 { font-size: 16px; font-weight: 700; color: #333; margin-bottom: 15px; border-left: 4px solid #2563eb; padding-left: 10px; }

        .form-control { border-radius: 6px; border: 1px solid #ddd; padding: 10px; }
        .form-control:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); }
        label { font-weight: 500; color: #555; font-size: 0.9rem; }

        .btn-primary { background-color: #2563eb; border-color: #2563eb; color: #fff; }
        .btn-primary:hover, .btn-primary:focus, .btn-primary:active { background-color: #1d4ed8; border-color: #1d4ed8; color: #fff; }
        .btn-success { background-color: #059669; border-color: #059669; color: #fff; }
        .btn-success:hover, .btn-success:focus, .btn-success:active { background-color: #047857; border-color: #047857; color: #fff; }
        .btn-secondary { background-color: #64748b; border-color: #64748b; color: #fff; }
        .btn-secondary:hover, .btn-secondary:focus, .btn-secondary:active { background-color: #475569; border-color: #475569; color: #fff; }
        .btn-outline-secondary { background-color: #fff; border-color: #cbd5e1; color: #475569; }
        .btn-outline-secondary:hover, .btn-outline-secondary:focus, .btn-outline-secondary:active { background-color: #f1f5f9; border-color: #94a3b8; color: #1e293b; }

        .parent-card { background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 15px; }

        .dob-group .form-control { display: inline-block; width: auto; }
        .dob-day { width: 70px !important; }
        .dob-month { width: 100px !important; }
        .dob-year { width: 90px !important; }

        .image-upload-card {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            padding: 16px;
            text-align: center;
        }
        .image-upload-card .preview-wrap img {
            max-width: 140px; max-height: 140px;
            border-radius: 8px; border: 3px solid #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.12);
            object-fit: cover;
        }
        .image-upload-card .placeholder {
            width: 140px; height: 140px;
            border-radius: 8px; background: #e2e8f0;
            display: inline-flex; align-items: center; justify-content: center;
            color: #94a3b8; font-size: 40px;
            margin: 0 auto 12px auto;
        }
        .image-upload-card input[type="file"] { display: block; margin: 8px auto 0 auto; font-size: 12px; }
        .image-upload-card .remove-wrap { margin-top: 10px; font-size: 12px; color: #64748b; }

        /* SAME AS CURRENT ADDRESS CHECKBOX */
        .same-address-toggle {
            display: flex; align-items: center; gap: 8px;
            margin: 8px 0 0 0;
            padding: 8px 12px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 6px;
            font-size: 13px;
            color: #1e40af;
            font-weight: 500;
            cursor: pointer;
            user-select: none;
        }
        .same-address-toggle input {
            width: 16px; height: 16px;
            accent-color: #2563eb;
            cursor: pointer;
            margin: 0;
        }
        .same-address-toggle:hover { background: #dbeafe; }
        .form-control[readonly] { background-color: #f1f5f9; cursor: not-allowed; }

        .wizard-bar {
            position: fixed;
            bottom: 0; left: 0; right: 0;
            background: #fff;
            border-top: 1px solid #e2e8f0;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.06);
            padding: 12px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 100;
        }
        .wizard-bar .progress-text { color: #64748b; font-size: 13px; font-weight: 500; }
        .wizard-bar .progress-text strong { color: #2563eb; }
        .wizard-bar .progress-track {
            flex: 1; max-width: 300px; height: 6px; background: #e2e8f0;
            border-radius: 3px; margin: 0 20px; overflow: hidden;
        }
        .wizard-bar .progress-track .fill {
            height: 100%; background-color: #2563eb;
            transition: width 0.3s ease;
        }
        .wizard-bar .btn { font-weight: 600; padding: 10px 24px; font-size: 14px; }
        .wizard-bar .btn + .btn { margin-left: 8px; }

        @media (max-width: 768px) {
            .page-title-bar h1 { font-size: 18px; }
            .wizard-bar { padding: 10px 12px; }
            .wizard-bar .btn { padding: 8px 14px; font-size: 12px; }
            .wizard-bar .progress-text { font-size: 11px; }
            .wizard-bar .progress-track { display: none; }
        }
    </style>
</head>
<body>
    <?php require_once('navbar.php'); ?>

    <div class="container" style="padding: 20px; max-width: 1200px; margin: 0 auto;">

        <div class="page-title-bar">
            <div>
                <h1>
                    <i class="fas fa-user-graduate"></i>
                    <?php echo $edit_mode ? 'Edit Student Registration' : 'Student Registration'; ?>
                </h1>
                <div class="subtitle">
                    <?php if ($edit_mode): ?>
                        Editing <strong><?php echo htmlspecialchars($student_data['name'] ?: 'Student'); ?></strong>
                        <?php if (!empty($student_data['reg_no'])): ?> &middot; <?php echo htmlspecialchars($student_data['reg_no']); ?><?php endif; ?>
                    <?php else: ?>
                        Register a new student &middot; Auto Reg No: <strong><?php echo htmlspecialchars($student_data['reg_no']); ?></strong>
                    <?php endif; ?>
                </div>
            </div>
            <a href="student_list.php" class="btn btn-outline-secondary btn-sm" style="padding: 8px 18px;">
                <i class="fas fa-list"></i> View Students
            </a>
        </div>

        <?php if (isset($_SESSION['message'])): ?>
        <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show">
            <?php echo $_SESSION['message']; unset($_SESSION['message'], $_SESSION['message_type']); ?>
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
        <?php endif; ?>

        <div class="panel">
            <div class="panel-body">
                <form id="studentForm" method="post" action="" enctype="multipart/form-data">
                    <input type="hidden" name="student_id" value="<?php echo intval($student_data['id']); ?>">
                    <input type="hidden" name="save_action" id="saveAction" value="">
                    <input type="hidden" name="current_step" id="currentStepInput" value="<?php echo intval($initial_step); ?>">

                    <ul class="nav nav-tabs" id="wizardTabs" role="tablist">
                        <li class="nav-item"><a class="nav-link" id="tab-basic" data-toggle="tab" data-step="0" href="#basic" role="tab"><span class="step-num">1</span> <?php echo $translations[$lang]['tab_basic']; ?></a></li>
                        <li class="nav-item"><a class="nav-link" id="tab-parents" data-toggle="tab" data-step="1" href="#parents" role="tab"><span class="step-num">2</span> <?php echo $translations[$lang]['tab_parents']; ?></a></li>
                        <li class="nav-item"><a class="nav-link" id="tab-address" data-toggle="tab" data-step="2" href="#address" role="tab"><span class="step-num">3</span> <?php echo $translations[$lang]['tab_address']; ?></a></li>
                        <li class="nav-item"><a class="nav-link" id="tab-academic" data-toggle="tab" data-step="3" href="#academic" role="tab"><span class="step-num">4</span> <?php echo $translations[$lang]['tab_academic']; ?></a></li>
                        <li class="nav-item"><a class="nav-link" id="tab-parent-login" data-toggle="tab" data-step="4" href="#parent-login" role="tab"><span class="step-num">5</span> <?php echo $translations[$lang]['tab_parent_login']; ?></a></li>
                    </ul>

                    <div class="tab-content" id="myTabContent">

                        <!-- TAB 1: BASIC INFO -->
                        <div class="tab-pane fade" id="basic" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-section">
                                        <h5>Personal Details</h5>
                                        <div class="form-group">
                                            <label>Student Name <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($student_data['name']); ?>" required>
                                        </div>
                                        <div class="form-row">
                                            <div class="col-6">
                                                <div class="form-group">
                                                    <label>Gender <span class="text-danger">*</span></label>
                                                    <select class="form-control" name="gender" id="gender_select" required>
                                                        <option value="">Select</option>
                                                        <option value="Male" <?php echo ($student_data['gender']=='Male')?'selected':''; ?>>Male</option>
                                                        <option value="Female" <?php echo ($student_data['gender']=='Female')?'selected':''; ?>>Female</option>
                                                        <option value="Other" <?php echo ($student_data['gender']=='Other')?'selected':''; ?>>Other</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="form-group">
                                                    <label>Date of Birth</label>
                                                    <div class="dob-group">
                                                        <select class="form-control dob-day" name="dob_day">
                                                            <option value="">Day</option>
                                                            <?php for ($d = 1; $d <= 31; $d++):
                                                                $dVal = str_pad($d, 2, '0', STR_PAD_LEFT);
                                                            ?>
                                                                <option value="<?php echo $dVal; ?>" <?php echo ((string)$student_data['dob_day'] === $dVal) ? 'selected' : ''; ?>><?php echo $d; ?></option>
                                                            <?php endfor; ?>
                                                        </select>
                                                        <select class="form-control dob-month" name="dob_month">
                                                            <option value="">Month</option>
                                                            <?php foreach(['01'=>'Jan','02'=>'Feb','03'=>'Mar','04'=>'Apr','05'=>'May','06'=>'Jun','07'=>'Jul','08'=>'Aug','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Dec'] as $k=>$v): ?>
                                                                <option value="<?=$k?>" <?=($student_data['dob_month']==$k)?'selected':''?>><?=$v?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <select class="form-control dob-year" name="dob_year">
                                                            <option value="">Year</option>
                                                            <?php
                                                            $current_year = intval(date('Y'));
                                                            for ($y = $current_year; $y >= $current_year - 40; $y--):
                                                            ?>
                                                                <option value="<?php echo $y; ?>" <?php echo ((string)$student_data['dob_year'] === (string)$y) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                                            <?php endfor; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="form-group" id="gender_other_wrap" style="display: <?php echo ($student_data['gender']=='Other')?'block':'none'; ?>;">
                                            <label>Specify Gender <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="gender_other" id="gender_other_input" value="<?php echo htmlspecialchars($student_data['gender_other']); ?>" placeholder="Enter gender">
                                        </div>
                                        <div class="form-group">
                                            <label>CNIC</label>
                                            <input type="text" class="form-control" name="cnic" value="<?php echo htmlspecialchars($student_data['cnic']); ?>" pattern="[0-9]{13}" maxlength="13">
                                        </div>
                                        <div class="form-group">
                                            <label>B-Form No</label>
                                            <input type="text" class="form-control" name="b_form_no" value="<?php echo htmlspecialchars($student_data['b_form_no']); ?>">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-section">
                                        <h5>Admission Details</h5>
                                        <div class="form-row">
                                            <div class="col-6">
                                                <div class="form-group">
                                                    <label>Reg No <span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" name="reg_no" value="<?php echo htmlspecialchars($student_data['reg_no']); ?>" readonly required style="background:#f1f5f9;">
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="form-group">
                                                    <label>Admission No</label>
                                                    <input type="text" class="form-control" name="admission_no" value="<?php echo htmlspecialchars($student_data['admission_no']); ?>">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <div class="col-6">
                                                <div class="form-group">
                                                    <label>Roll No</label>
                                                    <input type="text" class="form-control" name="roll_no" value="<?php echo htmlspecialchars($student_data['roll_no']); ?>">
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="form-group">
                                                    <label>Admission Date</label>
                                                    <input type="date" class="form-control" name="admission_date" value="<?php echo htmlspecialchars($student_data['admission_date']); ?>">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <label>Student Mobile</label>
                                            <input type="text" class="form-control" name="student_mobile" value="<?php echo htmlspecialchars($student_data['student_mobile']); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-section">
                                        <h5>Additional Info</h5>
                                        <div class="form-group">
                                            <label>Religion</label>
                                            <input type="text" class="form-control" name="religion" value="<?php echo htmlspecialchars($student_data['religion']); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label>House</label>
                                            <input type="text" class="form-control" name="house" value="<?php echo htmlspecialchars($student_data['house']); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label>Medical History</label>
                                            <textarea class="form-control" name="medical_history" rows="2"><?php echo htmlspecialchars($student_data['medical_history']); ?></textarea>
                                        </div>
                                        <div class="form-group">
                                            <label>Previous Schools</label>
                                            <textarea class="form-control" name="previous_schools_description" rows="2"><?php echo htmlspecialchars($student_data['previous_schools_description']); ?></textarea>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-section">
                                        <h5>Student Image</h5>
                                        <div class="image-upload-card">
                                            <div class="preview-wrap" id="imagePreviewWrap">
                                                <?php if (!empty($student_data['image_path']) && file_exists($student_data['image_path'])): ?>
                                                    <img src="<?php echo htmlspecialchars($student_data['image_path']); ?>" id="imagePreview" alt="Student">
                                                <?php else: ?>
                                                    <div class="placeholder" id="imagePlaceholder"><i class="fas fa-user"></i></div>
                                                <?php endif; ?>
                                            </div>
                                            <input type="file" name="image" id="imageInput" accept="image/png, image/jpeg, image/jpg, image/gif, image/webp">
                                            <div class="text-muted" style="font-size: 11px; margin-top: 6px;">JPG, PNG, GIF, WEBP &mdash; max 5 MB</div>
                                            <?php if (!empty($student_data['image_path']) && file_exists($student_data['image_path'])): ?>
                                                <div class="remove-wrap">
                                                    <label style="font-weight: normal;">
                                                        <input type="checkbox" name="remove_image" value="1"> Remove current image
                                                    </label>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="form-group" style="margin-top: 15px;">
                                            <label>Other Description</label>
                                            <textarea class="form-control" name="student_other_description" rows="3"><?php echo htmlspecialchars($student_data['student_other_description']); ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 2: PARENTS -->
                        <div class="tab-pane fade" id="parents" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-section">
                                        <h5>Father's Information</h5>
                                        <div class="form-group">
                                            <label>Father Name</label>
                                            <input type="text" class="form-control" name="father_name" value="<?php echo htmlspecialchars($student_data['father_name']); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label>Father Occupation</label>
                                            <input type="text" class="form-control" name="father_occupation" value="<?php echo htmlspecialchars($student_data['father_occupation']); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label>Father Qualification</label>
                                            <input type="text" class="form-control" name="father_qualification" value="<?php echo htmlspecialchars($student_data['father_qualification']); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label>Father Income</label>
                                            <input type="number" class="form-control" name="father_income" value="<?php echo htmlspecialchars($student_data['father_income']); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label>Father Phone No</label>
                                            <input type="text" class="form-control" name="father_phone" value="<?php echo htmlspecialchars($student_data['father_phone']); ?>">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-section">
                                        <h5>Guardian Information</h5>
                                        <div class="form-group">
                                            <label>Relationship with Guardian</label>
                                            <select class="form-control" name="guardian_relation">
                                                <option value="">-- Select --</option>
                                                <option value="Father"    <?php echo ($student_data['guardian_relation']=='Father')?'selected':''; ?>>Father</option>
                                                <option value="Mother"    <?php echo ($student_data['guardian_relation']=='Mother')?'selected':''; ?>>Mother</option>
                                                <option value="Brother"   <?php echo ($student_data['guardian_relation']=='Brother')?'selected':''; ?>>Brother</option>
                                                <option value="Sister"    <?php echo ($student_data['guardian_relation']=='Sister')?'selected':''; ?>>Sister</option>
                                                <option value="Uncle"     <?php echo ($student_data['guardian_relation']=='Uncle')?'selected':''; ?>>Uncle</option>
                                                <option value="Aunt"      <?php echo ($student_data['guardian_relation']=='Aunt')?'selected':''; ?>>Aunt</option>
                                                <option value="Grandfather" <?php echo ($student_data['guardian_relation']=='Grandfather')?'selected':''; ?>>Grandfather</option>
                                                <option value="Grandmother" <?php echo ($student_data['guardian_relation']=='Grandmother')?'selected':''; ?>>Grandmother</option>
                                                <option value="Other"     <?php echo ($student_data['guardian_relation']=='Other')?'selected':''; ?>>Other</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label>Guardian Name</label>
                                            <input type="text" class="form-control" name="guardian_name" value="<?php echo htmlspecialchars($student_data['guardian_name']); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label>Guardian CNIC</label>
                                            <input type="text" class="form-control" name="guardian_cnic" value="<?php echo htmlspecialchars($student_data['guardian_cnic']); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label>Guardian Phone No</label>
                                            <input type="text" class="form-control" name="guardian_mobile" value="<?php echo htmlspecialchars($student_data['guardian_mobile']); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label>Guardian Address</label>
                                            <textarea class="form-control" name="guardian_address" rows="2"><?php echo htmlspecialchars($student_data['guardian_address']); ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 3: ADDRESS (with Same as Current) -->
                        <div class="tab-pane fade" id="address" role="tabpanel">
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-section">
                                        <h5>Address Details</h5>

                                        <div class="form-group">
                                            <label>Current Address <span class="text-danger">*</span></label>
                                            <textarea class="form-control" name="current_address" id="current_address" rows="3" required><?php echo htmlspecialchars($student_data['current_address']); ?></textarea>
                                        </div>

                                        <div class="form-group">
                                            <label>Permanent Address <span class="text-danger">*</span></label>
                                            <textarea class="form-control" name="permanent_address" id="permanent_address" rows="3" required><?php echo htmlspecialchars($student_data['permanent_address']); ?></textarea>

                                            <label class="same-address-toggle" for="permanent_same_as_current">
                                                <input type="checkbox" name="permanent_same_as_current" id="permanent_same_as_current" value="1" <?php echo $permanent_same_as_current_default ? 'checked' : ''; ?>>
                                                <i class="fas fa-copy"></i> Same as Current Address
                                            </label>
                                        </div>

                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Emergency Contact Name</label>
                                                    <input type="text" class="form-control" name="emergency_name" value="<?php echo htmlspecialchars($student_data['emergency_name']); ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Emergency Phone</label>
                                                    <input type="text" class="form-control" name="emergency_phone" value="<?php echo htmlspecialchars($student_data['emergency_phone']); ?>">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 4: ACADEMIC -->
                        <div class="tab-pane fade" id="academic" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-section">
                                        <h5>Class & Session</h5>
                                        <div class="form-group">
                                            <label>Session</label>
                                            <select class="form-control" name="session_id">
                                                <option value="">Select</option>
                                                <?php foreach ($sessions as $id => $name): ?>
                                                    <option value="<?php echo $id; ?>" <?php echo ($id==$student_data['session_id'])?'selected':''; ?>><?php echo $name; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label>Class/Section</label>
                                            <select class="form-control" name="class_combo">
                                                <option value="">Select</option>
                                                <?php foreach ($class_options as $opt):
                                                    $optVal = $opt['class_id'] . '|' . $opt['class_section_id'];
                                                    $isSel = ((int)$student_data['class_id'] === (int)$opt['class_id'] && (int)$student_data['class_section_id'] === (int)$opt['class_section_id']);
                                                ?>
                                                    <option value="<?php echo $optVal; ?>" <?php echo $isSel ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt['display_label']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-section">
                                        <h5>Village & Transport</h5>
                                        <div class="form-group">
                                            <label>Village Council</label>
                                            <select class="form-control" id="village_council_id" name="village_council_id">
                                                <option value="">Select</option>
                                                <?php foreach ($village_councils as $id => $data): ?>
                                                    <option value="<?php echo $id; ?>" <?php echo ($id==$student_data['village_council_id'])?'selected':''; ?> data-fee="<?php echo $data['transport_fee']; ?>">
                                                        <?php echo htmlspecialchars($data['title']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label>Transport Fee</label>
                                            <input type="number" class="form-control" id="transport_fee" name="transport_fee" value="<?php echo htmlspecialchars($student_data['transport_fee']); ?>">
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="is_transport" id="is_transport" value="0" <?php echo ($student_data['is_transport']==0)?'checked':''; ?>>
                                            <label class="form-check-label" for="is_transport">Use Transport?</label>
                                        </div>
                                    </div>

                                    <div class="form-section mt-3">
                                        <h5>Dues</h5>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="is_old_dues" id="is_old_dues" value="1" <?php echo ($student_data['is_old_dues']==1)?'checked':''; ?>>
                                            <label class="form-check-label" for="is_old_dues">Has Old Dues?</label>
                                        </div>
                                        <div id="old_dues_container" style="display: <?php echo ($student_data['is_old_dues']==1)?'block':'none'; ?>; margin-top: 10px;">
                                            <label>Old Dues Amount</label>
                                            <input type="number" class="form-control" name="old_dues_amount" value="<?php echo htmlspecialchars($student_data['old_dues_amount']); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <?php if ($edit_mode && (!empty($perms['can_edit']) || !empty($perms['can_add']))): ?>
                            <div class="row mt-3">
                                <div class="col-12">
                                    <div class="card bg-light">
                                        <div class="card-body">
                                            <h5>Custom Fees</h5>
                                            <div class="row mb-2">
                                                <div class="col-md-4">
                                                    <select class="form-control" id="fee_type_id">
                                                        <option value="">Select Fee Type</option>
                                                        <?php foreach ($fee_types as $ft_id => $ft): ?>
                                                            <option value="<?php echo $ft_id; ?>"><?php echo htmlspecialchars($ft['title']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <input type="number" class="form-control" id="fee_amount" placeholder="Amount">
                                                </div>
                                                <div class="col-md-3">
                                                    <input type="date" class="form-control" id="fee_assigned_date" value="<?php echo date('Y-m-d'); ?>">
                                                </div>
                                                <div class="col-md-2">
                                                    <button type="button" class="btn btn-success btn-block" id="btnAddFee">Add</button>
                                                </div>
                                            </div>
                                            <table class="table table-sm table-bordered bg-white">
                                                <thead><tr><th>Type</th><th>Amount</th><th>Date</th><th>Action</th></tr></thead>
                                                <tbody id="feeTableBody">
                                                    <?php foreach ($existing_fees as $fee): ?>
                                                    <tr data-fee-id="<?php echo $fee['id']; ?>">
                                                        <td><?php echo htmlspecialchars($fee['fee_type_title']); ?></td>
                                                        <td><?php echo number_format($fee['amount'], 2); ?></td>
                                                        <td><?php echo $fee['assigned_date']; ?></td>
                                                        <td><button type="button" class="btn btn-sm btn-danger delete-fee-btn" data-fee-id="<?php echo $fee['id']; ?>">X</button></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- TAB 5: PARENT LOGIN -->
                        <div class="tab-pane fade" id="parent-login" role="tabpanel">
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="parent-card">
                                        <h5><i class="fas fa-user-lock"></i> Create Parent Login Account</h5>
                                        <p class="text-muted small">Check the box below to create a login account for the parent/guardian.</p>

                                        <div class="form-group">
                                            <label class="checkbox-label">
                                                <input type="checkbox" id="create_parent_account" name="create_parent_account" value="1">
                                                <strong>Create Parent Account</strong>
                                            </label>
                                        </div>

                                        <div id="parentAccountFields" style="display: none;">
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label>Username</label>
                                                        <input type="text" class="form-control" name="parent_username" id="parent_username" placeholder="e.g. parent_ahmed">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label>Password</label>
                                                        <input type="text" class="form-control" name="parent_password" id="parent_password" placeholder="Leave blank to auto-generate">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label>Relationship</label>
                                                        <select class="form-control" name="parent_relationship">
                                                            <option value="father">Father</option>
                                                            <option value="mother">Mother</option>
                                                            <option value="guardian">Guardian</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>Email</label>
                                                        <input type="email" class="form-control" name="parent_email" placeholder="parent@example.com">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>Mobile</label>
                                                        <input type="text" class="form-control" name="parent_mobile" pattern="[0-9]{11}" maxlength="11" placeholder="03XXXXXXXXX">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="parent_is_primary" value="1" checked>
                                                <label class="form-check-label">Set as Primary Contact</label>
                                            </div>
                                        </div>

                                        <?php if ($edit_mode && !empty($existing_parent_links)): ?>
                                            <hr>
                                            <h6>Existing Linked Accounts:</h6>
                                            <ul class="list-group">
                                                <?php foreach ($existing_parent_links as $pl): ?>
                                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                                        <div>
                                                            <strong><?= htmlspecialchars($pl['username']) ?></strong>
                                                            <span class="badge badge-secondary"><?= htmlspecialchars($pl['relationship']) ?></span>
                                                            <?php if($pl['is_primary']): ?><span class="badge badge-primary">Primary</span><?php endif; ?>
                                                            <br><small class="text-muted"><?= htmlspecialchars($pl['email'] ?: 'No Email') ?> | <?= htmlspecialchars($pl['mobile_no'] ?: 'No Mobile') ?></small>
                                                        </div>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="wizard-bar">
        <div class="progress-text">
            Step <strong id="stepNum">1</strong> of <strong>5</strong> &middot; <span id="stepName">Basic Info</span>
        </div>
        <div class="progress-track">
            <div class="fill" id="progressFill" style="width: 20%;"></div>
        </div>
        <div>
            <a href="student_list.php" class="btn btn-secondary btn-sm" style="padding: 10px 22px;">Cancel</a>
            <button type="button" id="btnBack" class="btn btn-outline-secondary btn-sm" style="display:none; padding: 10px 22px;">
                <i class="fas fa-arrow-left"></i> <?php echo $translations[$lang]['back']; ?>
            </button>
            <button type="button" id="btnSaveNext" class="btn btn-primary btn-sm" style="padding: 10px 26px;">
                <i class="fas fa-save"></i> <?php echo $translations[$lang]['save_next']; ?> <i class="fas fa-arrow-right"></i>
            </button>
            <button type="button" id="btnSaveFinal" class="btn btn-success btn-sm" style="display:none; padding: 10px 26px;">
                <i class="fas fa-check"></i> <?php echo $translations[$lang]['save']; ?>
            </button>
        </div>
    </div>

    <script>
    $(document).ready(function() {

        var TOTAL_STEPS = 5;
        var currentStep = <?php echo intval($initial_step); ?>;
        var stepNames = ['Basic Info', 'Parents & Guardian', 'Address & Contact', 'Academic & Fees', 'Parent Login'];
        var tabIds = ['#basic', '#parents', '#address', '#academic', '#parent-login'];

        function updateWizardUI() {
            $('#stepNum').text(currentStep + 1);
            $('#stepName').text(stepNames[currentStep]);
            $('#currentStepInput').val(currentStep);

            var pct = ((currentStep + 1) / TOTAL_STEPS) * 100;
            $('#progressFill').css('width', pct + '%');

            if (currentStep === 0) $('#btnBack').hide(); else $('#btnBack').show();

            if (currentStep === TOTAL_STEPS - 1) {
                $('#btnSaveNext').hide();
                $('#btnSaveFinal').show();
            } else {
                $('#btnSaveNext').show();
                $('#btnSaveFinal').hide();
            }

            $('#wizardTabs .nav-link').each(function() {
                var s = parseInt($(this).data('step'));
                $(this).toggleClass('completed', s < currentStep);
                $(this).toggleClass('active', s === currentStep);
            });

            $('.tab-pane').removeClass('show active');
            $(tabIds[currentStep]).addClass('show active');
        }

        function validateCurrentStep() {
            var $pane = $(tabIds[currentStep]);
            var valid = true;
            $pane.find('input[required], select[required], textarea[required]').each(function() {
                if (!this.checkValidity()) {
                    valid = false;
                    this.reportValidity();
                    return false;
                }
            });
            return valid;
        }

        function goToStep(n, skipValidate) {
            if (n < 0 || n >= TOTAL_STEPS) return;
            if (!skipValidate && !validateCurrentStep()) return;
            currentStep = n;
            updateWizardUI();
            $('html, body').animate({ scrollTop: 0 }, 200);
        }

        $('#wizardTabs .nav-link').on('click', function(e) {
            e.preventDefault();
            var target = parseInt($(this).data('step'));
            if (target <= currentStep) goToStep(target, true);
        });

        $('#btnBack').on('click', function() {
            goToStep(currentStep - 1, true);
        });

        $('#btnSaveNext').on('click', function() {
            if (!validateCurrentStep()) return;
            $('#saveAction').val('save_next');
            $('#currentStepInput').val(currentStep);
            $('#studentForm').submit();
        });

        $('#btnSaveFinal').on('click', function() {
            if (!validateCurrentStep()) return;
            $('#saveAction').val('save');
            $('#currentStepInput').val(currentStep);
            $('#studentForm').submit();
        });

        /* ═══════════════════════════════════════════════════
           SAME AS CURRENT ADDRESS LOGIC
           ═══════════════════════════════════════════════════ */
        function syncPermanentAddress() {
            if ($('#permanent_same_as_current').is(':checked')) {
                $('#permanent_address')
                    .val($('#current_address').val())
                    .prop('readonly', true)
                    .css('background-color', '#f1f5f9');
            } else {
                $('#permanent_address')
                    .prop('readonly', false)
                    .css('background-color', '');
            }
        }

        // On page load: if checkbox is already checked, sync
        if ($('#permanent_same_as_current').is(':checked')) {
            syncPermanentAddress();
        }

        // When checkbox changes
        $('#permanent_same_as_current').on('change', function() {
            syncPermanentAddress();
        });

        // Live sync while typing in current address
        $('#current_address').on('input keyup', function() {
            if ($('#permanent_same_as_current').is(':checked')) {
                $('#permanent_address').val($(this).val());
            }
        });

        /* Image preview */
        $('#imageInput').on('change', function(e) {
            var file = this.files && this.files[0];
            if (!file) return;
            if (file.size > 5 * 1024 * 1024) {
                alert('File too large. Maximum size is 5 MB.');
                $(this).val('');
                return;
            }
            var reader = new FileReader();
            reader.onload = function(ev) {
                $('#imagePreviewWrap').html('<img id="imagePreview" src="' + ev.target.result + '" alt="Preview">');
            };
            reader.readAsDataURL(file);
        });

        /* Transport fee */
        $('#village_council_id').change(function() {
            var fee = $(this).find('option:selected').data('fee') || 0;
            $('#transport_fee').val(fee);
        });
        <?php if ($edit_mode): ?>$('#village_council_id').trigger('change');<?php endif; ?>

        /* Old dues */
        $('#is_old_dues').change(function() {
            if ($(this).is(':checked')) $('#old_dues_container').slideDown();
            else $('#old_dues_container').slideUp();
        });

        /* Gender Other */
        $('#gender_select').change(function() {
            if ($(this).val() === 'Other') {
                $('#gender_other_wrap').slideDown();
                $('#gender_other_input').prop('required', true);
            } else {
                $('#gender_other_wrap').slideUp();
                $('#gender_other_input').prop('required', false);
            }
        });
        $('#gender_select').trigger('change');

        /* Parent account */
        $('#create_parent_account').change(function() {
            if ($(this).is(':checked')) {
                $('#parentAccountFields').slideDown();
                if (!$('#parent_username').val()) {
                    var regNo = $('input[name="reg_no"]').val();
                    if (regNo) $('#parent_username').val('parent_' + regNo.replace(/[^a-zA-Z0-9]/g, ''));
                }
            } else {
                $('#parentAccountFields').slideUp();
            }
        });

        /* AJAX Fee */
        <?php if ($edit_mode): ?>
        var studentId = <?php echo intval($student_data['id']); ?>;

        $('#btnAddFee').click(function() {
            var typeId = $('#fee_type_id').val();
            var amount = $('#fee_amount').val();
            var date = $('#fee_assigned_date').val();
            if(!typeId || !amount || !date) { alert('Please fill all fee fields'); return; }

            $.post('', { action:'add_fee', student_id:studentId, fee_type_id:typeId, amount:amount, assigned_date:date }, function(res) {
                if(res.success) {
                    $('#feeTableBody').append('<tr data-fee-id="'+res.id+'"><td>'+res.fee_type_title+'</td><td>'+res.amount+'</td><td>'+res.assigned_date+'</td><td><button type="button" class="btn btn-sm btn-danger delete-fee-btn" data-fee-id="'+res.id+'">X</button></td></tr>');
                    $('#fee_amount').val('');
                } else {
                    alert(res.message);
                }
            }, 'json');
        });

        $(document).on('click', '.delete-fee-btn', function() {
            if(!confirm('Delete this fee?')) return;
            var feeId = $(this).data('fee-id');
            var row = $(this).closest('tr');
            $.post('', { action:'delete_fee', fee_id:feeId }, function(res) {
                if(res.success) row.remove();
                else alert(res.message);
            }, 'json');
        });
        <?php endif; ?>

        updateWizardUI();
    });
    </script>
</body>
</html>
<?php $conn->close(); ?>