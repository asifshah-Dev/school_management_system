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
        'dob_label' => 'Date of Birth:',
        'title' => 'Student Registration',
        'add_title' => 'Register New Student',
        'edit_title' => 'Edit Student Registration',
        'branch_label' => 'Branch:',
        'village_council_label' => 'Village Council:',
        'reg_no_label' => 'Registration Number:',
        'admission_no_label' => 'Admission No:',
        'admission_date_label' => 'Admission Date:',
        'roll_no_label' => 'Roll No:',
        'b_form_no_label' => 'B-Form No:',
        'student_mobile_label' => 'Student Mobile:',
        'religion_label' => 'Religion:',
        'house_label' => 'House:',
        'father_occupation_label' => 'Father Occupation:',
        'father_qualification_label' => 'Father Qualification:',
        'father_income_label' => 'Father Monthly Income:',
        'father_phone_label' => 'Father Phone:',
        'emergency_name_label' => 'Emergency Contact Name:',
        'emergency_phone_label' => 'Emergency Contact Phone:',
        'medical_history_label' => 'Medical History:',
        'transport_fee_label' => 'Transport Fee:',
        'registration_date_label' => 'Registration Date:',
        'transport_label' => 'Use Transport:',
        'session_label' => 'Session:',
        'class_label' => 'Class:',
        'course_label' => 'Course:',
        'name_label' => 'Student Name:',
        'father_name_label' => 'Father Name:',
        'mobile_label' => 'Mobile:',
        'cnic_label' => 'CNIC:',
        'current_address_label' => 'Current Address:',
        'permanent_address_label' => 'Permanent Address:',
        'guardian_name_label' => 'Guardian Name:',
        'guardian_mobile_label' => 'Guardian Mobile:',
        'guardian_address_label' => 'Guardian Address:',
        'guardian_cnic_label' => 'Guardian CNIC:',
        'previous_schools_label' => 'Previous Schools:',
        'other_info_label' => 'Other Information:',
        'image_label' => 'Student Image:',
        'gender_label' => 'Gender:',
        'submit' => 'Submit',
        'save' => 'Save Changes',
        'reset' => 'Reset',
        'cancel' => 'Cancel',
        'success' => 'Student registered successfully!',
        'update_success' => 'Student information updated successfully!',
        'error' => 'Error: ',
        'required_error' => 'Required fields cannot be empty!',
        'select_option' => '-- Select --',
        'class_or_course' => 'Class/Course:',
        'old_dues_label' => 'Old Dues:',
        'old_dues_amount_label' => 'Old Dues Amount:',
        'new_admission_label' => 'New Admission',
        'old_admission_label' => 'Old Admission',
        'invalid_dob' => 'Invalid Date of Birth or Year before 1950.',
        'view_students' => 'Student List',
        'male' => 'Male',
        'female' => 'Female',
        'other' => 'Other',
        'custom_fee_title'       => 'Custom Fee Assignment',
        'fee_type_label'         => 'Fee Type:',
        'fee_amount_label'       => 'Amount:',
        'fee_assigned_date_label'=> 'Assigned Date:',
        'add_fee_btn'            => 'Add Fee',
        'assigned_fees_title'    => 'Assigned Fees',
        'no_fees'                => 'No custom fees assigned yet.',
        'fee_save_success'       => 'Custom fee assigned successfully!',
        'fee_delete_success'     => 'Custom fee removed successfully!',
        'fee_error'              => 'Error saving fee. Please try again.',
        'remove'                 => 'Remove',
        'actions'                => 'Actions',
    ]
];

// ---------- Dropdowns ----------
$branches = [];
$village_councils = [];
$sessions = [];
$class_options = [];
$courses = [];
$fee_types = [];

$branch_result = $conn->query("SELECT id, title FROM branches ORDER BY title");
while ($row = $branch_result->fetch_assoc()) $branches[$row['id']] = $row['title'];

$vc_result = $conn->query("SELECT id, title, transport_fee FROM village_councils ORDER BY title");
while ($row = $vc_result->fetch_assoc()) {
    $village_councils[$row['id']] = ['title' => $row['title'], 'transport_fee' => $row['transport_fee']];
}

$session_result = $conn->query("SELECT id, title FROM sessions ORDER BY id DESC");
while ($row = $session_result->fetch_assoc()) $sessions[$row['id']] = $row['title'];

$class_result = $conn->query("
    SELECT
        COALESCE(cs.id, 0) AS class_section_id,
        c.id               AS class_id,
        c.title            AS class_title,
        s.title            AS section_title,
        CASE
            WHEN cs.id IS NULL THEN c.title
            ELSE CONCAT(c.title, ' - ', s.title)
        END                AS display_label
    FROM classes c
    LEFT JOIN class_sections cs
           ON cs.class_id = c.id AND cs.status = 0
    LEFT JOIN sections s
           ON s.id = cs.section_id
    WHERE c.status = 0
    ORDER BY c.title, s.title
");
while ($row = $class_result->fetch_assoc()) $class_options[] = $row;

$course_result = $conn->query("SELECT id, title FROM courses ORDER BY title");
while ($row = $course_result->fetch_assoc()) $courses[$row['id']] = $row['title'];

$ft_result = $conn->query("SELECT id, title, type FROM fee_types ORDER BY title");
while ($row = $ft_result->fetch_assoc()) $fee_types[$row['id']] = $row;

$month_map = [
    'jan'=>'01','january'=>'01','feb'=>'02','february'=>'02','mar'=>'03','march'=>'03',
    'apr'=>'04','april'=>'04','may'=>'05','jun'=>'06','june'=>'06','jul'=>'07','july'=>'07',
    'aug'=>'08','august'=>'08','sep'=>'09','sept'=>'09','september'=>'09',
    'oct'=>'10','october'=>'10','nov'=>'11','november'=>'11','dec'=>'12','december'=>'12'
];

// ---------- AJAX fee handlers ----------
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => ''];

    if ($action === 'add_fee') {
        if (empty($perms['can_edit']) && empty($perms['can_add'])) {
            $response['message'] = 'You do not have permission to assign fees.';
            echo json_encode($response); exit;
        }

        $student_id    = intval($_POST['student_id']);
        $fee_type_id   = intval($_POST['fee_type_id']);
        $amount        = floatval($_POST['amount']);
        $assigned_date = trim($_POST['assigned_date']);

        if (!$student_id || !$fee_type_id || $amount <= 0 || empty($assigned_date)) {
            $response['message'] = 'All fields are required and amount must be greater than 0.';
            echo json_encode($response); exit;
        }

        $stmt = $conn->prepare("
            INSERT INTO student_fee_assignments (student_id, fee_type_id, amount, assigned_date, status)
            VALUES (?, ?, ?, ?, 1)
        ");
        $stmt->bind_param("iids", $student_id, $fee_type_id, $amount, $assigned_date);

        if ($stmt->execute()) {
            $new_id = $conn->insert_id;
            $ft = $conn->prepare("SELECT title FROM fee_types WHERE id = ?");
            $ft->bind_param("i", $fee_type_id);
            $ft->execute();
            $ft_row = $ft->get_result()->fetch_assoc();
            $ft->close();

            $response['success']        = true;
            $response['message']        = $translations[$lang]['fee_save_success'];
            $response['id']             = $new_id;
            $response['fee_type_title'] = htmlspecialchars($ft_row['title'] ?? '');
            $response['amount']         = number_format($amount, 2);
            $response['assigned_date']  = htmlspecialchars($assigned_date);
        } else {
            $response['message'] = 'DB Error: ' . $stmt->error;
        }
        $stmt->close();

    } elseif ($action === 'delete_fee') {
        if (empty($perms['can_edit']) && empty($perms['can_add'])) {
            $response['message'] = 'You do not have permission to modify fees.';
            echo json_encode($response); exit;
        }

        $fee_id = intval($_POST['fee_id']);
        $stmt = $conn->prepare("DELETE FROM student_fee_assignments WHERE id = ?");
        $stmt->bind_param("i", $fee_id);

        if ($stmt->execute()) {
            $response['success'] = true;
            $response['message'] = $translations[$lang]['fee_delete_success'];
        } else {
            $response['message'] = 'DB Error: ' . $stmt->error;
        }
        $stmt->close();
    }

    echo json_encode($response);
    exit;
}

// ---------- Edit mode ----------
$student_data = [
    'id' => '', 'branch_id' => '1', 'village_council_id' => '', 'reg_no' => '',
    'admission_no' => '', 'admission_date' => '', 'roll_no' => '',
    'session_id' => '', 'class_id' => '', 'class_section_id' => '', 'course_id' => '',
    'name' => '', 'dob_day' => '', 'dob_month' => '', 'dob_year' => '',
    'gender' => '', 'b_form_no' => '', 'student_mobile' => '', 'religion' => '', 'house' => '',
    'father_name' => '', 'father_occupation' => '', 'father_qualification' => '',
    'father_income' => '', 'father_phone' => '',
    'mobile' => '', 'cnic' => '',
    'current_address' => '', 'permanent_address' => '',
    'guardian_name' => '', 'guardian_mobile' => '', 'guardian_address' => '', 'guardian_cnic' => '',
    'emergency_name' => '', 'emergency_phone' => '', 'medical_history' => '',
    'previous_schools_description' => '', 'student_other_description' => '',
    'image_path' => '', 'transport_fee' => '0', 'is_transport' => '1',
    'registration_date' => date('Y-m-d'), 'is_old_dues' => '0', 'old_dues_amount' => '0',
    'is_admission' => '1'
];

$edit_mode = false;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $stmt = $conn->prepare("
        SELECT sr.*, sc.class_id, sc.class_section_id, sc.session_id, sc.promotion_date
        FROM student_registration sr
        LEFT JOIN (
            SELECT * FROM student_class
            WHERE student_registration_id = ?
            ORDER BY id DESC LIMIT 1
        ) sc ON sr.id = sc.student_registration_id
        WHERE sr.id = ?
    ");
    if ($stmt) {
        $stmt->bind_param("ii", $edit_id, $edit_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $student_data = $result->fetch_assoc();

            if (!empty($student_data['dob']) && $student_data['dob'] !== '0000-00-00') {
                $dob_str = trim($student_data['dob']);
                try {
                    $d = new DateTime($dob_str);
                    $student_data['dob_year']  = $d->format('Y');
                    $student_data['dob_month'] = $d->format('m');
                    $student_data['dob_day']   = $d->format('d');
                } catch (Exception $e) {
                    $p = explode('-', $dob_str);
                    if (count($p) === 3) {
                        $student_data['dob_year']  = str_pad($p[0], 4, '0', STR_PAD_LEFT);
                        $student_data['dob_month'] = str_pad($p[1], 2, '0', STR_PAD_LEFT);
                        $student_data['dob_day']   = str_pad($p[2], 2, '0', STR_PAD_LEFT);
                    }
                }
            } else {
                $student_data['dob_year'] = '';
                $student_data['dob_month'] = '';
                $student_data['dob_day'] = '';
            }

            $student_data['is_old_dues']      = isset($student_data['is_old_dues']) ? intval($student_data['is_old_dues']) : 0;
            $student_data['old_dues_amount']  = isset($student_data['old_dues_amount']) ? floatval($student_data['old_dues_amount']) : 0;
            $student_data['transport_fee']    = isset($student_data['transport_fee']) ? floatval($student_data['transport_fee']) : 0;
            $student_data['is_transport']     = isset($student_data['is_transport']) ? intval($student_data['is_transport']) : 1;
            $student_data['class_id']         = isset($student_data['class_id']) ? intval($student_data['class_id']) : 0;
            $student_data['class_section_id'] = isset($student_data['class_section_id']) ? intval($student_data['class_section_id']) : 0;

            $edit_mode = true;
        }
        $stmt->close();
    }
}

// Existing fees (edit mode)
$existing_fees = [];
if ($edit_mode && !empty($student_data['id'])) {
    $stmt = $conn->prepare("
        SELECT sfa.id, ft.title AS fee_type_title, sfa.amount, sfa.assigned_date, sfa.status
        FROM student_fee_assignments sfa
        JOIN fee_types ft ON sfa.fee_type_id = ft.id
        WHERE sfa.student_id = ?
        ORDER BY sfa.id DESC
    ");
    $stmt->bind_param("i", $student_data['id']);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $existing_fees[] = $row;
    $stmt->close();
}

// Existing parent links (edit mode)
$existing_parent_links = [];
if ($edit_mode && !empty($student_data['id'])) {
    $stmt = $conn->prepare("
        SELECT u.id, u.username, u.email, u.mobile_no, ps.relationship, ps.is_primary
        FROM parent_students ps
        JOIN users u ON u.id = ps.parent_user_id
        WHERE ps.student_id = ?
        ORDER BY ps.is_primary DESC, u.username
    ");
    $stmt->bind_param("i", $student_data['id']);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $existing_parent_links[] = $row;
    $stmt->close();
}

// ---------- Form submission ----------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['student_id'])) {

    $student_id = intval($_POST['student_id']);
    $branch_id = 1;
    $village_council_id = isset($_POST['village_council_id']) ? intval($_POST['village_council_id']) : 0;
    $reg_no = mysqli_real_escape_string($conn, trim($_POST['reg_no']));
    $session_id = isset($_POST['session_id']) ? intval($_POST['session_id']) : 0;

    // Hybrid class combo
    $combo = isset($_POST['class_combo']) ? trim($_POST['class_combo']) : '';
    if (strpos($combo, '|') !== false) {
        [$class_id, $class_section_id] = array_map('intval', explode('|', $combo, 2));
    } else {
        $class_id = intval($combo);
        $class_section_id = 0;
    }
    if ($class_section_id === 0) $class_section_id = null;

    // ─── NEW FIELDS ───
    $admission_no    = mysqli_real_escape_string($conn, trim($_POST['admission_no'] ?? ''));
    $admission_date  = trim($_POST['admission_date'] ?? '');
    $roll_no         = mysqli_real_escape_string($conn, trim($_POST['roll_no'] ?? ''));
    $b_form_no       = mysqli_real_escape_string($conn, trim($_POST['b_form_no'] ?? ''));
    $student_mobile  = mysqli_real_escape_string($conn, trim($_POST['student_mobile'] ?? ''));
    $religion        = mysqli_real_escape_string($conn, trim($_POST['religion'] ?? ''));
    $house           = mysqli_real_escape_string($conn, trim($_POST['house'] ?? ''));
    $father_occupation    = mysqli_real_escape_string($conn, trim($_POST['father_occupation'] ?? ''));
    $father_qualification = mysqli_real_escape_string($conn, trim($_POST['father_qualification'] ?? ''));
    $father_income        = ($_POST['father_income'] ?? '') !== '' ? floatval($_POST['father_income']) : null;
    $father_phone         = mysqli_real_escape_string($conn, trim($_POST['father_phone'] ?? ''));
    $emergency_name       = mysqli_real_escape_string($conn, trim($_POST['emergency_name'] ?? ''));
    $emergency_phone      = mysqli_real_escape_string($conn, trim($_POST['emergency_phone'] ?? ''));
    $medical_history      = mysqli_real_escape_string($conn, trim($_POST['medical_history'] ?? ''));

    $name = mysqli_real_escape_string($conn, trim($_POST['name']));
    $father_name = mysqli_real_escape_string($conn, trim($_POST['father_name']));
    $mobile = mysqli_real_escape_string($conn, trim($_POST['mobile']));
    $cnic = mysqli_real_escape_string($conn, trim($_POST['cnic']));
    $current_address = mysqli_real_escape_string($conn, trim($_POST['current_address']));
    $permanent_address = mysqli_real_escape_string($conn, trim($_POST['permanent_address']));
    $guardian_name = mysqli_real_escape_string($conn, trim($_POST['guardian_name']));
    $guardian_mobile = mysqli_real_escape_string($conn, trim($_POST['guardian_mobile']));
    $guardian_address = mysqli_real_escape_string($conn, trim($_POST['guardian_address']));
    $guardian_cnic = mysqli_real_escape_string($conn, trim($_POST['guardian_cnic']));
    $previous_schools_description = mysqli_real_escape_string($conn, trim($_POST['previous_schools_description']));
    $student_other_description = mysqli_real_escape_string($conn, trim($_POST['student_other_description']));
    $transport_fee = floatval($_POST['transport_fee']);
    $is_transport = isset($_POST['is_transport']) ? 0 : 1;
    $registration_date = mysqli_real_escape_string($conn, $_POST['registration_date']);
    $is_old_dues = isset($_POST['is_old_dues']) ? 1 : 0;
    $old_dues_amount = floatval($_POST['old_dues_amount']);
    $is_admission = 1;
    $promotion_date = date('Y-m-d');
    $gender = mysqli_real_escape_string($conn, trim($_POST['gender']));

    // Custom fees (new student)
    $custom_fees_to_insert = [];
    if ($student_id == 0 && isset($_POST['custom_fees']) && is_array($_POST['custom_fees'])) {
        foreach ($_POST['custom_fees'] as $fee) {
            if (!empty($fee['fee_type_id']) && !empty($fee['amount']) && !empty($fee['assigned_date'])) {
                $custom_fees_to_insert[] = [
                    'fee_type_id'   => intval($fee['fee_type_id']),
                    'amount'        => floatval($fee['amount']),
                    'assigned_date' => mysqli_real_escape_string($conn, $fee['assigned_date']),
                ];
            }
        }
    }

    // DOB
    $dob_day = isset($_POST['dob_day']) ? trim($_POST['dob_day']) : '';
    $dob_month = isset($_POST['dob_month']) ? strtolower(trim($_POST['dob_month'])) : '';
    $dob_year = isset($_POST['dob_year']) ? trim($_POST['dob_year']) : '';

    $dob = '';
    if (!empty($dob_day) && !empty($dob_month) && !empty($dob_year)) {
        if (isset($month_map[$dob_month])) {
            $month_num = $month_map[$dob_month];
        } elseif (is_numeric($dob_month) && intval($dob_month) >= 1 && intval($dob_month) <= 12) {
            $month_num = str_pad(intval($dob_month), 2, '0', STR_PAD_LEFT);
        } else {
            $month_num = '';
        }
        $day_num = str_pad(intval($dob_day), 2, '0', STR_PAD_LEFT);
        $year_num = intval($dob_year);
        if ($month_num && $year_num >= 1950 && checkdate(intval($month_num), intval($day_num), $year_num)) {
            $dob = sprintf('%04d-%02d-%02d', $year_num, intval($month_num), intval($day_num));
        } else {
            $_SESSION['message'] = $translations[$lang]['invalid_dob'];
            $_SESSION['message_type'] = 'danger';
            header("Location: " . $_SERVER['PHP_SELF'] . ($student_id > 0 ? '?edit=' . $student_id : ''));
            exit();
        }
    }

    // Image
    $image_path = '';
    $update_image = false;
    if (!empty($_FILES['image']['name'])) {
        $image_name = time() . '_' . basename($_FILES['image']['name']);
        $target_dir = "Uploads/students/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
        $target_file = $target_dir . $image_name;
        $ext = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif'])) {
            if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
                $image_path = $target_file;
                $update_image = true;
            }
        }
    }

    // Prepare SQL values
    $dob_sql            = $dob ? "'$dob'" : "NULL";
    $image_path_sql     = $image_path ? "'$image_path'" : "NULL";
    $cnic_sql           = $cnic ? "'$cnic'" : "NULL";
    $guardian_cnic_sql  = $guardian_cnic ? "'$guardian_cnic'" : "NULL";
    $admission_date_sql = $admission_date ? "'" . mysqli_real_escape_string($conn, $admission_date) . "'" : "NULL";
    $father_income_sql  = $father_income !== null ? $father_income : "NULL";

    $new_student_id = 0;

    if ($student_id > 0) {
        // UPDATE
        $image_clause = $update_image ? ", image_path = $image_path_sql" : "";
        $sql = "UPDATE student_registration SET
            branch_id = $branch_id,
            village_council_id = $village_council_id,
            reg_no = '$reg_no',
            admission_no = '$admission_no',
            admission_date = $admission_date_sql,
            roll_no = '$roll_no',
            name = '$name',
            dob = $dob_sql,
            gender = '$gender',
            b_form_no = '$b_form_no',
            student_mobile = '$student_mobile',
            religion = '$religion',
            house = '$house',
            father_name = '$father_name',
            father_occupation = '$father_occupation',
            father_qualification = '$father_qualification',
            father_income = $father_income_sql,
            father_phone = '$father_phone',
            mobile = '$mobile',
            cnic = $cnic_sql,
            current_address = '$current_address',
            permanent_address = '$permanent_address',
            guardian_name = '$guardian_name',
            guardian_mobile = '$guardian_mobile',
            guardian_address = '$guardian_address',
            guardian_cnic = $guardian_cnic_sql,
            emergency_name = '$emergency_name',
            emergency_phone = '$emergency_phone',
            medical_history = '$medical_history',
            previous_schools_description = '$previous_schools_description',
            student_other_description = '$student_other_description',
            registration_date = '$registration_date',
            is_transport = $is_transport,
            transport_fee = $transport_fee,
            is_old_dues = $is_old_dues,
            old_dues_amount = $old_dues_amount,
            is_admission = $is_admission
            $image_clause
            WHERE id = $student_id";

        if ($conn->query($sql)) {
            $check_stmt = $conn->prepare("SELECT id FROM student_class WHERE student_registration_id = ? ORDER BY id DESC LIMIT 1");
            $check_stmt->bind_param("i", $student_id);
            $check_stmt->execute();
            $cr = $check_stmt->get_result();

            if ($cr->num_rows > 0) {
                $row = $cr->fetch_assoc();
                $scid = (int)$row['id'];
                $upd = $conn->prepare("
                    UPDATE student_class
                    SET session_id = ?, class_id = ?, class_section_id = ?, promotion_date = ?
                    WHERE id = ?
                ");
                $upd->bind_param("iiiis", $session_id, $class_id, $class_section_id, $promotion_date, $scid);
                $upd->execute();
                $upd->close();
            } else {
                $ins = $conn->prepare("
                    INSERT INTO student_class
                        (student_registration_id, session_id, class_id, class_section_id, promotion_date)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $ins->bind_param("iiiis", $student_id, $session_id, $class_id, $class_section_id, $promotion_date);
                $ins->execute();
                $ins->close();
            }
            $check_stmt->close();

            $_SESSION['message'] = $translations[$lang]['update_success'];
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = $translations[$lang]['error'] . $conn->error;
            $_SESSION['message_type'] = 'danger';
        }

    } else {
        // INSERT
        $sql = "INSERT INTO student_registration (
            branch_id, village_council_id, reg_no, admission_no, admission_date, roll_no,
            name, dob, gender, b_form_no, student_mobile, religion, house,
            father_name, father_occupation, father_qualification, father_income, father_phone,
            mobile, cnic, current_address, permanent_address,
            guardian_name, guardian_mobile, guardian_address, guardian_cnic,
            emergency_name, emergency_phone, medical_history,
            previous_schools_description, student_other_description,
            image_path, registration_date, is_transport, transport_fee, is_old_dues, old_dues_amount,
            is_admission
        ) VALUES (
            $branch_id, $village_council_id, '$reg_no', '$admission_no', $admission_date_sql, '$roll_no',
            '$name', $dob_sql, '$gender', '$b_form_no', '$student_mobile', '$religion', '$house',
            '$father_name', '$father_occupation', '$father_qualification', $father_income_sql, '$father_phone',
            '$mobile', $cnic_sql, '$current_address', '$permanent_address',
            '$guardian_name', '$guardian_mobile', '$guardian_address', $guardian_cnic_sql,
            '$emergency_name', '$emergency_phone', '$medical_history',
            '$previous_schools_description', '$student_other_description',
            $image_path_sql, '$registration_date', $is_transport, $transport_fee, $is_old_dues, $old_dues_amount,
            $is_admission
        )";

        if ($conn->query($sql)) {
            $new_student_id = $conn->insert_id;

            $cstmt = $conn->prepare("
                INSERT INTO student_class
                    (student_registration_id, session_id, class_id, class_section_id, promotion_date)
                VALUES (?, ?, ?, ?, ?)
            ");
            $cstmt->bind_param("iiiis", $new_student_id, $session_id, $class_id, $class_section_id, $promotion_date);
            $cstmt->execute();
            $cstmt->close();

            if (!empty($custom_fees_to_insert)) {
                $fstmt = $conn->prepare("
                    INSERT INTO student_fee_assignments (student_id, fee_type_id, amount, assigned_date, status)
                    VALUES (?, ?, ?, ?, 1)
                ");
                foreach ($custom_fees_to_insert as $cf) {
                    $fstmt->bind_param("iids", $new_student_id, $cf['fee_type_id'], $cf['amount'], $cf['assigned_date']);
                    $fstmt->execute();
                }
                $fstmt->close();
            }

            $_SESSION['message'] = $translations[$lang]['success'];
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = $translations[$lang]['error'] . $conn->error;
            $_SESSION['message_type'] = 'danger';
        }
    }

    // ───────────── PARENT ACCOUNT CREATION ─────────────
    $create_parent = !empty($_POST['create_parent_account']);
    if ($create_parent) {
        $link_student_id = ($student_id > 0) ? $student_id : ($new_student_id ?? 0);

        $p_username   = trim($_POST['parent_username'] ?? '');
        $p_email      = trim($_POST['parent_email'] ?? '');
        $p_mobile     = trim($_POST['parent_mobile'] ?? '');
        $p_password   = trim($_POST['parent_password'] ?? '');
        $p_rel        = $_POST['parent_relationship'] ?? 'father';
        $p_is_primary = !empty($_POST['parent_is_primary']) ? 1 : 0;

        if (!in_array($p_rel, ['father','mother','guardian','other'], true)) $p_rel = 'father';

        if ($link_student_id > 0 && $p_username !== '') {
            $dupe = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $dupe->bind_param("s", $p_username);
            $dupe->execute();
            $dupe_row = $dupe->get_result()->fetch_assoc();
            $dupe->close();

            if ($dupe_row) {
                $_SESSION['message'] = 'Student saved, but parent username "' . htmlspecialchars($p_username) . '" already exists. Create the link manually.';
                $_SESSION['message_type'] = 'warning';
            } else {
                if ($p_password === '') $p_password = bin2hex(random_bytes(4));

                $stmt = $conn->prepare("
                    INSERT INTO users (role_id, username, password, mobile_no, email, salary, status, village_council_id)
                    VALUES (6, ?, ?, ?, ?, NULL, 0, NULL)
                ");
                $stmt->bind_param("ssss", $p_username, $p_password, $p_mobile, $p_email);
                $stmt->execute();
                $parent_user_id = $conn->insert_id;
                $stmt->close();

                if ($p_is_primary) {
                    $conn->query("UPDATE parent_students SET is_primary = 0 WHERE student_id = " . (int)$link_student_id);
                }

                $link_stmt = $conn->prepare("
                    INSERT INTO parent_students (parent_user_id, student_id, relationship, is_primary)
                    VALUES (?, ?, ?, ?)
                ");
                $link_stmt->bind_param("iisi", $parent_user_id, $link_student_id, $p_rel, $p_is_primary);

                if ($link_stmt->execute()) {
                    $_SESSION['message'] = 'Student saved. Parent account "' . htmlspecialchars($p_username) . '" created with password: ' . htmlspecialchars($p_password);
                    $_SESSION['message_type'] = 'success';
                } else {
                    $_SESSION['message'] = 'Student saved. Parent user created but link failed: ' . $link_stmt->error;
                    $_SESSION['message_type'] = 'danger';
                }
                $link_stmt->close();
            }
        }
    }

    header("Location: student_registration.php");
    exit();
}

// Delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = intval($_GET['delete']);

    $stmt = $conn->prepare("DELETE FROM student_fee_assignments WHERE student_id = ?");
    $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();

    $stmt = $conn->prepare("DELETE FROM parent_students WHERE student_id = ?");
    $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();

    $stmt = $conn->prepare("DELETE FROM student_class WHERE student_registration_id = ?");
    $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();

    $stmt = $conn->prepare("DELETE FROM student_registration WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $_SESSION['message'] = 'Student record deleted successfully!';
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = $translations[$lang]['error'] . $stmt->error;
        $_SESSION['message_type'] = 'danger';
    }
    $stmt->close();
    header("Location: ".$_SERVER['PHP_SELF']);
    exit();
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f5f7fa; }
        .panel { background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 30px; overflow: hidden; }
        .panel-heading { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; }
        .panel-title { margin: 0; font-weight: 600; font-size: 1.2rem; }
        .panel-body { padding: 25px; }
        .form-section { margin-bottom: 22px; padding: 18px; border: 1px solid #e0e0e0; border-radius: 10px; background-color: #fff; }
        .form-section h4 { font-size: 16px; font-weight: 600; margin-bottom: 15px; padding-bottom: 8px; border-bottom: 2px solid #007bff; color: #333; }
        .fee-section { margin-bottom: 22px; padding: 18px; border: 2px solid #d4edda; border-radius: 10px; background-color: #f8fff9; }
        .fee-section h4 { font-size: 16px; font-weight: 600; margin-bottom: 15px; padding-bottom: 8px; border-bottom: 2px solid #28a745; color: #28a745; }
        .form-group label { font-weight: 500; color: #555; margin-bottom: 5px; }
        .form-control { border-radius: 6px; border: 1px solid #ddd; padding: 9px 12px; }
        .form-control:focus { border-color: #667eea; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); }
        .form-row { display: flex; flex-wrap: wrap; gap: 10px; }
        .form-row .col-3, .form-row .col-4, .form-row .col-5 { flex: 1; min-width: 80px; }
        .student-image { max-width: 150px; max-height: 150px; object-fit: cover; border-radius: 8px; }
        .current-image { text-align: center; padding: 15px; background: #f8f9fa; border-radius: 8px; margin-top: 10px; }
        .checkbox-label { display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 500; }
        .custom-fee-row { background: #fff; padding: 12px; margin-bottom: 10px; border: 1px solid #ddd; border-radius: 6px; display: flex; flex-direction: column; gap: 10px; }
        @media (min-width: 768px) {
            .custom-fee-row { flex-direction: row; align-items: flex-end; gap: 12px; }
        }
        .custom-fee-row .form-group { margin-bottom: 0 !important; flex: 1; }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; padding: 10px 24px; font-weight: 500; border-radius: 6px; }
        .btn-primary:hover { background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%); transform: translateY(-1px); }
        .btn-secondary { padding: 10px 24px; border-radius: 6px; }
        .btn-danger { padding: 10px 24px; border-radius: 6px; }
        .alert { border-radius: 8px; margin-bottom: 20px; border: none; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        @media (max-width: 767px) {
            .form-row { flex-direction: column; }
            .form-row .col-3, .form-row .col-4, .form-row .col-5 { width: 100%; }
            .text-center .btn { display: block; width: 100%; margin: 8px 0; }
        }
    </style>
</head>
<body>
    <?php require_once('navbar.php'); ?>

    <div class="container" style="padding: 20px; max-width: 1200px; margin: 0 auto;">
        <div class="text-right" style="margin-bottom: 20px;">
            <a href="student_list.php" class="btn btn-info">
                <?php echo htmlspecialchars($translations[$lang]['view_students']); ?>
            </a>
        </div>

        <div class="row">
            <div class="col-md-12">
                <div class="panel">
                    <div class="panel-heading">
                        <h3 class="panel-title">
                            <?php echo $edit_mode ? htmlspecialchars($translations[$lang]['edit_title']) : htmlspecialchars($translations[$lang]['add_title']); ?>
                        </h3>
                    </div>
                    <div class="panel-body">
                        <?php if (isset($_SESSION['message'])): ?>
                        <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show">
                            <?php echo htmlspecialchars($_SESSION['message']); unset($_SESSION['message'], $_SESSION['message_type']); ?>
                            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                        </div>
                        <?php endif; ?>

                        <form id="studentForm" method="post" action="" enctype="multipart/form-data">
                            <input type="hidden" name="student_id" value="<?php echo intval($student_data['id']); ?>">

                            <div class="row">
                                <!-- LEFT COLUMN -->
                                <div class="col-md-6">
                                    <div class="form-section">
                                        <h4>Admission Information</h4>
                                        <div class="form-group">
                                            <label for="admission_no">Admission No</label>
                                            <input type="text" class="form-control" id="admission_no" name="admission_no" value="<?php echo htmlspecialchars($student_data['admission_no']); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="admission_date">Admission Date</label>
                                            <input type="date" class="form-control" id="admission_date" name="admission_date" value="<?php echo htmlspecialchars($student_data['admission_date']); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="roll_no">Roll No</label>
                                            <input type="text" class="form-control" id="roll_no" name="roll_no" value="<?php echo htmlspecialchars($student_data['roll_no']); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="reg_no"><?php echo htmlspecialchars($translations[$lang]['reg_no_label']); ?> <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="reg_no" name="reg_no" value="<?php echo htmlspecialchars($student_data['reg_no']); ?>" required>
                                        </div>
                                    </div>

                                    <div class="form-section">
                                        <h4>Basic Information</h4>
                                        <div class="form-group">
                                            <label for="name"><?php echo htmlspecialchars($translations[$lang]['name_label']); ?> <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($student_data['name']); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="gender"><?php echo htmlspecialchars($translations[$lang]['gender_label']); ?> <span class="text-danger">*</span></label>
                                            <select class="form-control" id="gender" name="gender" required>
                                                <option value=""><?php echo htmlspecialchars($translations[$lang]['select_option']); ?></option>
                                                <option value="Male"   <?php echo ($student_data['gender'] == 'Male')   ? 'selected' : ''; ?>>Male</option>
                                                <option value="Female" <?php echo ($student_data['gender'] == 'Female') ? 'selected' : ''; ?>>Female</option>
                                                <option value="Other"  <?php echo ($student_data['gender'] == 'Other')  ? 'selected' : ''; ?>>Other</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="b_form_no">B-Form No</label>
                                            <input type="text" class="form-control" id="b_form_no" name="b_form_no" value="<?php echo htmlspecialchars($student_data['b_form_no']); ?>" maxlength="20">
                                        </div>
                                        <div class="form-group">
                                            <label for="student_mobile">Student Mobile</label>
                                            <input type="text" class="form-control" id="student_mobile" name="student_mobile" value="<?php echo htmlspecialchars($student_data['student_mobile']); ?>" pattern="[0-9]{11}" maxlength="11" placeholder="03XXXXXXXXX"
                                                oninput="this.value=this.value.replace(/[^0-9]/g,''); if(this.value.length>11)this.value=this.value.slice(0,11);">
                                        </div>
                                        <div class="form-group">
                                            <label for="religion">Religion</label>
                                            <input type="text" class="form-control" id="religion" name="religion" value="<?php echo htmlspecialchars($student_data['religion']); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="house">House</label>
                                            <input type="text" class="form-control" id="house" name="house" value="<?php echo htmlspecialchars($student_data['house']); ?>">
                                        </div>

                                        <div class="form-group">
                                            <label><?php echo htmlspecialchars($translations[$lang]['dob_label']); ?></label>
                                            <div class="form-row">
                                                <div class="col-3">
                                                    <input type="text" class="form-control" id="dob_day" name="dob_day" value="<?php echo htmlspecialchars($student_data['dob_day'] ?? ''); ?>" placeholder="DD" maxlength="2"
                                                        oninput="this.value=this.value.replace(/[^0-9]/g,''); if(this.value.length>2)this.value=this.value.slice(0,2);">
                                                </div>
                                                <div class="col-5">
                                                    <select class="form-control" id="dob_month" name="dob_month">
                                                        <option value="">-- Select --</option>
                                                        <?php foreach (['01'=>'January','02'=>'February','03'=>'March','04'=>'April','05'=>'May','06'=>'June','07'=>'July','08'=>'August','09'=>'September','10'=>'October','11'=>'November','12'=>'December'] as $num => $label): ?>
                                                            <option value="<?= $num ?>" <?= (($student_data['dob_month'] ?? '') === $num) ? 'selected' : '' ?>><?= $label ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-4">
                                                    <input type="text" class="form-control" id="dob_year" name="dob_year" value="<?php echo htmlspecialchars($student_data['dob_year'] ?? ''); ?>" placeholder="YYYY" maxlength="4"
                                                        oninput="this.value=this.value.replace(/[^0-9]/g,''); if(this.value.length>4)this.value=this.value.slice(0,4);">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <label for="mobile"><?php echo htmlspecialchars($translations[$lang]['mobile_label']); ?> <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="mobile" name="mobile" value="<?php echo htmlspecialchars($student_data['mobile']); ?>" required pattern="[0-9]{11}" maxlength="11" placeholder="03XXXXXXXXX"
                                                oninput="this.value=this.value.replace(/[^0-9]/g,''); if(this.value.length>11)this.value=this.value.slice(0,11);">
                                        </div>
                                        <div class="form-group">
                                            <label for="cnic"><?php echo htmlspecialchars($translations[$lang]['cnic_label']); ?></label>
                                            <input type="text" class="form-control" id="cnic" name="cnic" value="<?php echo htmlspecialchars($student_data['cnic']); ?>" pattern="[0-9]{13}" maxlength="13"
                                                oninput="this.value=this.value.replace(/[^0-9]/g,''); if(this.value.length>13)this.value=this.value.slice(0,13);">
                                        </div>
                                    </div>

                                    <div class="form-section">
                                        <h4>Address Information</h4>
                                        <div class="form-group">
                                            <label for="current_address"><?php echo htmlspecialchars($translations[$lang]['current_address_label']); ?> <span class="text-danger">*</span></label>
                                            <textarea class="form-control" id="current_address" name="current_address" rows="2" required><?php echo htmlspecialchars($student_data['current_address']); ?></textarea>
                                        </div>
                                        <div class="form-group">
                                            <label for="permanent_address"><?php echo htmlspecialchars($translations[$lang]['permanent_address_label']); ?> <span class="text-danger">*</span></label>
                                            <textarea class="form-control" id="permanent_address" name="permanent_address" rows="2" required><?php echo htmlspecialchars($student_data['permanent_address']); ?></textarea>
                                        </div>
                                    </div>

                                    <div class="form-section">
                                        <h4>Additional Information</h4>
                                        <div class="form-group">
                                            <label for="previous_schools_description"><?php echo htmlspecialchars($translations[$lang]['previous_schools_label']); ?></label>
                                            <textarea class="form-control" id="previous_schools_description" name="previous_schools_description" rows="2"><?php echo htmlspecialchars($student_data['previous_schools_description']); ?></textarea>
                                        </div>
                                        <div class="form-group">
                                            <label for="student_other_description"><?php echo htmlspecialchars($translations[$lang]['other_info_label']); ?></label>
                                            <textarea class="form-control" id="student_other_description" name="student_other_description" rows="2"><?php echo htmlspecialchars($student_data['student_other_description']); ?></textarea>
                                        </div>
                                    </div>
                                </div>

                                <!-- RIGHT COLUMN -->
                                <div class="col-md-6">
                                    <div class="form-section">
                                        <h4>Father Details</h4>
                                        <div class="form-group">
                                            <label for="father_name"><?php echo htmlspecialchars($translations[$lang]['father_name_label']); ?> <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="father_name" name="father_name" value="<?php echo htmlspecialchars($student_data['father_name']); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="father_occupation">Father Occupation</label>
                                            <input type="text" class="form-control" id="father_occupation" name="father_occupation" value="<?php echo htmlspecialchars($student_data['father_occupation']); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="father_qualification">Father Qualification</label>
                                            <input type="text" class="form-control" id="father_qualification" name="father_qualification" value="<?php echo htmlspecialchars($student_data['father_qualification']); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="father_income">Father Monthly Income</label>
                                            <input type="number" class="form-control" id="father_income" name="father_income" value="<?php echo htmlspecialchars($student_data['father_income']); ?>" min="0" step="0.01" placeholder="0.00">
                                        </div>
                                        <div class="form-group">
                                            <label for="father_phone">Father Phone</label>
                                            <input type="text" class="form-control" id="father_phone" name="father_phone" value="<?php echo htmlspecialchars($student_data['father_phone']); ?>" pattern="[0-9]{11}" maxlength="11" placeholder="03XXXXXXXXX"
                                                oninput="this.value=this.value.replace(/[^0-9]/g,''); if(this.value.length>11)this.value=this.value.slice(0,11);">
                                        </div>
                                    </div>

                                    <div class="form-section">
                                        <h4>Guardian Information</h4>
                                        <div class="form-group">
                                            <label for="guardian_name"><?php echo htmlspecialchars($translations[$lang]['guardian_name_label']); ?></label>
                                            <input type="text" class="form-control" id="guardian_name" name="guardian_name" value="<?php echo htmlspecialchars($student_data['guardian_name']); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="guardian_mobile"><?php echo htmlspecialchars($translations[$lang]['guardian_mobile_label']); ?></label>
                                            <input type="text" class="form-control" id="guardian_mobile" name="guardian_mobile" value="<?php echo htmlspecialchars($student_data['guardian_mobile']); ?>" pattern="[0-9]{11}" maxlength="11"
                                                oninput="this.value=this.value.replace(/[^0-9]/g,''); if(this.value.length>11)this.value=this.value.slice(0,11);">
                                        </div>
                                        <div class="form-group">
                                            <label for="guardian_address"><?php echo htmlspecialchars($translations[$lang]['guardian_address_label']); ?></label>
                                            <textarea class="form-control" id="guardian_address" name="guardian_address" rows="2"><?php echo htmlspecialchars($student_data['guardian_address']); ?></textarea>
                                        </div>
                                        <div class="form-group">
                                            <label for="guardian_cnic"><?php echo htmlspecialchars($translations[$lang]['guardian_cnic_label']); ?></label>
                                            <input type="text" class="form-control" id="guardian_cnic" name="guardian_cnic" value="<?php echo htmlspecialchars($student_data['guardian_cnic']); ?>" pattern="[0-9]{13}" maxlength="13"
                                                oninput="this.value=this.value.replace(/[^0-9]/g,''); if(this.value.length>13)this.value=this.value.slice(0,13);">
                                        </div>
                                    </div>

                                    <div class="form-section">
                                        <h4>Emergency &amp; Medical</h4>
                                        <div class="form-group">
                                            <label for="emergency_name">Emergency Contact Name</label>
                                            <input type="text" class="form-control" id="emergency_name" name="emergency_name" value="<?php echo htmlspecialchars($student_data['emergency_name']); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="emergency_phone">Emergency Contact Phone</label>
                                            <input type="text" class="form-control" id="emergency_phone" name="emergency_phone" value="<?php echo htmlspecialchars($student_data['emergency_phone']); ?>" pattern="[0-9]{11}" maxlength="11" placeholder="03XXXXXXXXX"
                                                oninput="this.value=this.value.replace(/[^0-9]/g,''); if(this.value.length>11)this.value=this.value.slice(0,11);">
                                        </div>
                                        <div class="form-group">
                                            <label for="medical_history">Medical History</label>
                                            <textarea class="form-control" id="medical_history" name="medical_history" rows="2"><?php echo htmlspecialchars($student_data['medical_history']); ?></textarea>
                                        </div>
                                    </div>

                                    <div class="form-section">
                                        <h4>Session and Class</h4>
                                        <div class="form-group">
                                            <label for="session_id"><?php echo htmlspecialchars($translations[$lang]['session_label']); ?> <span class="text-danger">*</span></label>
                                            <select class="form-control" id="session_id" name="session_id" required>
                                                <option value="">-- Select --</option>
                                                <?php foreach ($sessions as $id => $name): ?>
                                                    <option value="<?php echo intval($id); ?>" <?php echo ($id == $student_data['session_id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($name); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="class_combo"><?php echo htmlspecialchars($translations[$lang]['class_label']); ?> <span class="text-danger">*</span></label>
                                            <select class="form-control" id="class_combo" name="class_combo" required>
                                                <option value="">-- Select --</option>
                                                <?php foreach ($class_options as $opt):
                                                    $optVal = $opt['class_id'] . '|' . $opt['class_section_id'];
                                                    $isSel = ((int)$student_data['class_id'] === (int)$opt['class_id']
                                                           && (int)$student_data['class_section_id'] === (int)$opt['class_section_id']);
                                                ?>
                                                    <option value="<?php echo $optVal; ?>" <?php echo $isSel ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($opt['display_label']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-section">
                                        <h4>Admission and Dues</h4>
                                        <div class="form-group">
                                            <label class="checkbox-label">
                                                <input type="checkbox" id="is_old_dues" name="is_old_dues" value="1" <?php echo ($student_data['is_old_dues'] == 1) ? 'checked' : ''; ?>>
                                                <?php echo htmlspecialchars($translations[$lang]['old_admission_label']); ?>
                                            </label>
                                            <small class="text-muted d-block mt-1">(Check if old admission with previous dues)</small>

                                            <div id="old_dues_amount_container" style="<?php echo ($student_data['is_old_dues'] == 1) ? 'display: block; margin-top: 10px;' : 'display: none;'; ?>">
                                                <label for="old_dues_amount"><?php echo htmlspecialchars($translations[$lang]['old_dues_amount_label']); ?></label>
                                                <input type="number" class="form-control" id="old_dues_amount" name="old_dues_amount" value="<?php echo htmlspecialchars($student_data['old_dues_amount']); ?>" min="0" step="0.01">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-section">
                                        <h4>Transport and Village Council</h4>
                                        <div class="form-group">
                                            <label for="village_council_id"><?php echo htmlspecialchars($translations[$lang]['village_council_label']); ?> <span class="text-danger">*</span></label>
                                            <select class="form-control" id="village_council_id" name="village_council_id" required>
                                                <option value="">-- Select --</option>
                                                <?php foreach ($village_councils as $id => $data): ?>
                                                    <option value="<?php echo intval($id); ?>" <?php echo ($id == $student_data['village_council_id']) ? 'selected' : ''; ?> data-transport-fee="<?php echo floatval($data['transport_fee']); ?>">
                                                        <?php echo htmlspecialchars($data['title']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="transport_fee"><?php echo htmlspecialchars($translations[$lang]['transport_fee_label']); ?></label>
                                            <input type="number" class="form-control" id="transport_fee" name="transport_fee" value="<?php echo htmlspecialchars($student_data['transport_fee']); ?>" min="0" step="0.01">
                                        </div>
                                        <div class="form-group">
                                            <label class="checkbox-label">
                                                <input type="checkbox" id="is_transport" name="is_transport" value="0" <?php echo ($student_data['is_transport'] == 0) ? 'checked' : ''; ?>>
                                                <?php echo htmlspecialchars($translations[$lang]['transport_label']); ?>
                                            </label>
                                        </div>
                                        <div class="form-group">
                                            <label for="registration_date"><?php echo htmlspecialchars($translations[$lang]['registration_date_label']); ?> <span class="text-danger">*</span></label>
                                            <input type="date" class="form-control" id="registration_date" name="registration_date" value="<?php echo htmlspecialchars($student_data['registration_date']); ?>" required>
                                        </div>
                                    </div>

                                    <div class="form-section">
                                        <h4>Student Image</h4>
                                        <div class="form-group">
                                            <label for="image"><?php echo htmlspecialchars($translations[$lang]['image_label']); ?></label>
                                            <input type="file" class="form-control-file" id="image" name="image" accept="image/*">
                                            <small class="text-muted">Upload new image to replace existing (optional)</small>

                                            <?php if (!empty($student_data['image_path'])): ?>
                                            <div class="current-image mt-3">
                                                <p class="text-muted mb-2"><strong>Current Image:</strong></p>
                                                <img src="<?php echo htmlspecialchars($student_data['image_path']); ?>" class="student-image img-thumbnail" alt="Student Photo">
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- CUSTOM FEE SECTION -->
                            <?php if (!empty($perms['can_edit']) || !empty($perms['can_add'])): ?>
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="fee-section">
                                        <h4><?php echo $translations[$lang]['custom_fee_title']; ?></h4>

                                        <?php if (!$edit_mode): ?>
                                            <div id="customFeesContainer"></div>
                                            <button type="button" class="btn btn-primary btn-sm" id="btnAddCustomFeeRow" style="margin-bottom: 15px;">
                                                + <?php echo $translations[$lang]['add_fee_btn']; ?>
                                            </button>
                                        <?php else: ?>
                                            <div class="row" style="margin-bottom: 12px;">
                                                <div class="col-sm-12 col-md-4">
                                                    <label><?php echo $translations[$lang]['fee_type_label']; ?></label>
                                                    <select class="form-control" id="fee_type_id">
                                                        <option value="">-- Select --</option>
                                                        <?php foreach ($fee_types as $ft_id => $ft): ?>
                                                            <option value="<?php echo $ft_id; ?>">
                                                                <?php echo htmlspecialchars($ft['title']); ?><?php if (!empty($ft['type'])): ?> (<?php echo htmlspecialchars($ft['type']); ?>)<?php endif; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-sm-12 col-md-3">
                                                    <label><?php echo $translations[$lang]['fee_amount_label']; ?></label>
                                                    <input type="number" class="form-control" id="fee_amount" step="0.01" min="0.01" placeholder="0.00">
                                                </div>
                                                <div class="col-sm-12 col-md-3">
                                                    <label><?php echo $translations[$lang]['fee_assigned_date_label']; ?></label>
                                                    <input type="date" class="form-control" id="fee_assigned_date" value="<?php echo date('Y-m-d'); ?>">
                                                </div>
                                                <div class="col-sm-12 col-md-2">
                                                    <label>&nbsp;</label>
                                                    <button type="button" class="btn btn-success btn-block" id="btnAddFee">+ Add</button>
                                                </div>
                                            </div>
                                            <div id="feeAlert" class="alert" style="display:none;"></div>
                                            <div style="margin-top: 15px;">
                                                <strong style="font-size: 15px; color: #333;"><?php echo $translations[$lang]['assigned_fees_title']; ?></strong>
                                                <div class="table-responsive" style="margin-top: 8px;">
                                                    <table class="table table-bordered table-striped" style="margin-bottom: 0;">
                                                        <thead>
                                                            <tr>
                                                                <th>#</th>
                                                                <th><?php echo $translations[$lang]['fee_type_label']; ?></th>
                                                                <th><?php echo $translations[$lang]['fee_amount_label']; ?></th>
                                                                <th><?php echo $translations[$lang]['fee_assigned_date_label']; ?></th>
                                                                <th><?php echo $translations[$lang]['actions']; ?></th>
                                                            </tr>
                                                        </thead>
                                                        <tbody id="feeTableBody">
                                                            <?php if (empty($existing_fees)): ?>
                                                                <tr id="noFeesRow"><td colspan="5" class="text-center text-muted"><?php echo $translations[$lang]['no_fees']; ?></td></tr>
                                                            <?php else: ?>
                                                                <?php foreach ($existing_fees as $idx => $fee): ?>
                                                                    <tr data-fee-id="<?php echo $fee['id']; ?>">
                                                                        <td><?php echo $idx + 1; ?></td>
                                                                        <td><?php echo htmlspecialchars($fee['fee_type_title']); ?></td>
                                                                        <td class="text-right"><?php echo number_format(floatval($fee['amount']), 2); ?></td>
                                                                        <td><?php echo htmlspecialchars($fee['assigned_date']); ?></td>
                                                                        <td class="text-center">
                                                                            <button type="button" class="btn btn-sm btn-danger delete-fee-btn" data-fee-id="<?php echo $fee['id']; ?>">Delete</button>
                                                                        </td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- PARENT LOGIN SECTION -->
                            <?php if (!empty($perms['can_add']) || !empty($perms['can_edit'])): ?>
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-section" style="background: #f0f9ff; border: 2px solid #bae6fd;">
                                        <h4 style="border-bottom-color: #0369a1; color: #0369a1;">Parent Login Account</h4>

                                        <?php if ($edit_mode && !empty($existing_parent_links)): ?>
                                            <p style="font-size: 13px; color: #334155; margin-bottom: 12px;">Linked parent accounts:</p>
                                            <ul style="margin: 0 0 16px 20px; font-size: 13px;">
                                                <?php foreach ($existing_parent_links as $pl): ?>
                                                    <li>
                                                        <strong><?= htmlspecialchars($pl['username']) ?></strong>
                                                        (<?= htmlspecialchars($pl['relationship']) ?><?= $pl['is_primary'] ? ', primary' : '' ?>)
                                                        — <?= htmlspecialchars($pl['email'] ?: 'no email') ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>

                                        <div class="form-group">
                                            <label class="checkbox-label">
                                                <input type="checkbox" id="create_parent_account" name="create_parent_account" value="1">
                                                Create a parent login account for this student
                                            </label>
                                            <small class="text-muted">Check to add a parent login. Existing links are never removed by this form.</small>
                                        </div>

                                        <div id="parentAccountFields" style="display: none;">
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label>Parent Username <span class="text-danger">*</span></label>
                                                        <input type="text" class="form-control" name="parent_username" id="parent_username" maxlength="50" placeholder="e.g. parent_ahmed">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label>Parent Email</label>
                                                        <input type="email" class="form-control" name="parent_email" placeholder="parent@example.com">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label>Parent Mobile</label>
                                                        <input type="text" class="form-control" name="parent_mobile" pattern="[0-9]{11}" maxlength="11" placeholder="03XXXXXXXXX"
                                                            oninput="this.value=this.value.replace(/[^0-9]/g,''); if(this.value.length>11)this.value=this.value.slice(0,11);">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label>Password <span class="text-danger">*</span></label>
                                                        <input type="text" class="form-control" name="parent_password" id="parent_password" placeholder="Min 4 chars, blank to auto-generate">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label>Relationship</label>
                                                        <select class="form-control" name="parent_relationship">
                                                            <option value="father">Father</option>
                                                            <option value="mother">Mother</option>
                                                            <option value="guardian">Guardian</option>
                                                            <option value="other">Other</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label class="checkbox-label" style="margin-top: 28px;">
                                                            <input type="checkbox" name="parent_is_primary" value="1" checked>
                                                            Primary contact
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-section" style="background: #f8f9fa; border: none;">
                                        <div class="form-group text-center mb-0">
                                            <button type="submit" class="btn btn-primary btn-lg">
                                                <?php echo $edit_mode ? $translations[$lang]['save'] : $translations[$lang]['submit']; ?>
                                            </button>
                                            <button type="reset" class="btn btn-secondary btn-lg"><?php echo $translations[$lang]['reset']; ?></button>
                                            <?php if ($edit_mode): ?>
                                                <a href="student_registration.php" class="btn btn-danger btn-lg"><?php echo $translations[$lang]['cancel']; ?></a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    $(document).ready(function() {
        $('#is_old_dues').change(function() {
            if ($(this).is(':checked')) $('#old_dues_amount_container').slideDown(300);
            else { $('#old_dues_amount_container').slideUp(300); $('#old_dues_amount').val('0'); }
        });

        $('#village_council_id').change(function() {
            var fee = $(this).find('option:selected').data('transport-fee') || 0;
            $('#transport_fee').val(fee);
        });
        <?php if ($edit_mode): ?>$('#village_council_id').trigger('change');<?php endif; ?>

        $('#studentForm').on('submit', function(e) {
            if (!$('#reg_no').val().trim() || !$('#name').val().trim() || !$('#gender').val()
                || !$('#father_name').val().trim() || !$('#mobile').val().trim()
                || !$('#session_id').val() || !$('#class_combo').val() || !$('#village_council_id').val()) {
                alert('Please fill in all required fields');
                e.preventDefault(); return false;
            }
            if ($('#mobile').val().trim().length !== 11) {
                alert('Mobile number must be 11 digits');
                e.preventDefault(); return false;
            }
        });

        // Parent account section — show/hide
        $('#create_parent_account').on('change', function() {
            if ($(this).is(':checked')) {
                $('#parentAccountFields').slideDown(200);
                $('#parent_username, #parent_password').prop('required', true);
                if (!$('#parent_username').val()) {
                    var regNo = $('#reg_no').val().trim();
                    if (regNo) $('#parent_username').val('parent_' + regNo);
                }
            } else {
                $('#parentAccountFields').slideUp(200);
                $('#parent_username, #parent_password').prop('required', false);
            }
        });
        $('#create_parent_account').trigger('change');

        var feeRowIndex = 0;
        function addCustomFeeRow() {
            var html = '<div class="custom-fee-row">' +
                '<div class="form-group"><label>Fee Type</label>' +
                    '<select class="form-control" name="custom_fees[' + feeRowIndex + '][fee_type_id]">' +
                        '<option value="">-- Select --</option>' +
                        <?php foreach ($fee_types as $ft_id => $ft): ?>
                            '<option value="<?php echo $ft_id; ?>"><?php echo addslashes(htmlspecialchars($ft['title'])); ?></option>' +
                        <?php endforeach; ?>
                    '</select></div>' +
                '<div class="form-group"><label>Amount</label>' +
                    '<input type="number" class="form-control" name="custom_fees[' + feeRowIndex + '][amount]" step="0.01" min="0.01" placeholder="0.00"></div>' +
                '<div class="form-group"><label>Assigned Date</label>' +
                    '<input type="date" class="form-control" name="custom_fees[' + feeRowIndex + '][assigned_date]" value="<?php echo date("Y-m-d"); ?>"></div>' +
                '<button type="button" class="btn btn-danger btn-sm btn-remove-fee">Remove</button>' +
            '</div>';
            $('#customFeesContainer').append(html);
            feeRowIndex++;
        }

        $('#btnAddCustomFeeRow').on('click', addCustomFeeRow);
        $(document).on('click', '.btn-remove-fee', function() {
            $(this).closest('.custom-fee-row').fadeOut(200, function(){ $(this).remove(); });
        });
        <?php if (!$edit_mode): ?>addCustomFeeRow();<?php endif; ?>

        <?php if ($edit_mode): ?>
        var studentId = <?php echo intval($student_data['id']); ?>;

        function showFeeAlert(msg, type) {
            var $a = $('#feeAlert');
            $a.removeClass('alert-success alert-danger alert-warning').addClass('alert-' + type).html(msg).fadeIn();
            setTimeout(function(){ $a.fadeOut(); }, 4000);
        }

        function reindexFeeRows() {
            $('#feeTableBody tr[data-fee-id]').each(function(i){ $(this).find('td:first').text(i+1); });
        }

        $('#btnAddFee').on('click', function() {
            var feeTypeId = $('#fee_type_id').val();
            var feeAmount = parseFloat($('#fee_amount').val());
            var assignedDate = $('#fee_assigned_date').val();

            if (!feeTypeId) { showFeeAlert('Select a fee type.', 'warning'); return; }
            if (!feeAmount || feeAmount <= 0) { showFeeAlert('Enter a valid amount.', 'warning'); return; }
            if (!assignedDate) { showFeeAlert('Select a date.', 'warning'); return; }

            var $btn = $(this).prop('disabled', true).text('Saving...');

            $.ajax({
                url: window.location.href, type: 'POST',
                data: { action:'add_fee', student_id:studentId, fee_type_id:feeTypeId, amount:feeAmount, assigned_date:assignedDate },
                dataType: 'json',
                success: function(r) {
                    if (r.success) {
                        $('#noFeesRow').remove();
                        var count = $('#feeTableBody tr[data-fee-id]').length + 1;
                        $('#feeTableBody').append(
                            '<tr data-fee-id="' + r.id + '"><td>' + count + '</td><td>' + r.fee_type_title + '</td>' +
                            '<td class="text-right">' + r.amount + '</td><td>' + r.assigned_date + '</td>' +
                            '<td class="text-center"><button type="button" class="btn btn-sm btn-danger delete-fee-btn" data-fee-id="' + r.id + '">Delete</button></td></tr>'
                        );
                        $('#fee_type_id').val(''); $('#fee_amount').val(''); $('#fee_assigned_date').val('<?php echo date("Y-m-d"); ?>');
                        showFeeAlert('<strong>✓</strong> ' + r.message, 'success');
                    } else {
                        showFeeAlert('<strong>✗</strong> ' + r.message, 'danger');
                    }
                },
                error: function() { showFeeAlert('Error saving fee.', 'danger'); },
                complete: function() { $btn.prop('disabled', false).text('+ Add'); }
            });
        });

        $(document).on('click', '.delete-fee-btn', function() {
            if (!confirm('Delete this fee assignment?')) return;
            var feeId = $(this).data('fee-id');
            var $row = $(this).closest('tr');
            $.ajax({
                url: window.location.href, type: 'POST',
                data: { action:'delete_fee', fee_id:feeId },
                dataType: 'json',
                success: function(r) {
                    if (r.success) {
                        $row.fadeOut(200, function() {
                            $(this).remove();
                            reindexFeeRows();
                            if ($('#feeTableBody tr[data-fee-id]').length === 0) {
                                $('#feeTableBody').html('<tr id="noFeesRow"><td colspan="5" class="text-center text-muted">No custom fees assigned yet.</td></tr>');
                            }
                        });
                        showFeeAlert('<strong>✓</strong> ' + r.message, 'success');
                    } else {
                        showFeeAlert('<strong>✗</strong> ' + r.message, 'danger');
                    }
                }
            });
        });
        <?php endif; ?>
    });
    </script>
</body>
</html>
<?php $conn->close(); ?>