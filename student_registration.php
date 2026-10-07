<?php
header('Content-Type: text/html; charset=utf-8');
require_once('security.php');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Language handling
$_SESSION['lang'] = 'en'; // Default to English
$lang = $_SESSION['lang'];

// Complete Language strings
$translations = [
    'en' => [
        'dob_label' => 'Date of Birth:',
        'dob_day_label' => 'Day:',
        'dob_month_label' => 'Month:',
        'dob_year_label' => 'Year:',
        'title' => 'Student Registration',
        'add_title' => 'Register New Student',
        'edit_title' => 'Edit Student Registration',
        'branch_label' => 'Branch:',
        'village_council_label' => 'Village Council:',
        'reg_no_label' => 'Registration Number:',
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
        'mother_name_label' => 'Mother Name:',
        'mother_cnic_label' => 'Mother CNIC:',
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
        'view_students' => 'View Student List',
        'male' => 'Male',
        'female' => 'Female',
        'other' => 'Other'
    ]
];

// Create database connection
require_once('conn_inc.php');

// Get dropdown options
$branches = [];
$village_councils = [];
$sessions = [];
$classes = [];
$courses = [];

// Fetch branches
$branch_result = $conn->query("SELECT id, title FROM branches ORDER BY title");
while ($row = $branch_result->fetch_assoc()) {
    $branches[$row['id']] = $row['title'];
}

// Fetch village councils with transport fee
$vc_result = $conn->query("SELECT id, title, transport_fee FROM village_councils ORDER BY title");
while ($row = $vc_result->fetch_assoc()) {
    $village_councils[$row['id']] = ['title' => $row['title'], 'transport_fee' => $row['transport_fee']];
}

// Fetch sessions
$session_result = $conn->query("SELECT id, title FROM sessions ORDER BY id DESC");
while ($row = $session_result->fetch_assoc()) {
    $sessions[$row['id']] = $row['title'];
}

// Fetch classes
$class_result = $conn->query("
    SELECT 
        c.id AS class_id,
        c.title AS class_title,
        COALESCE(s.id, 0) AS section_id,
        COALESCE(s.title, '') AS section_title
    FROM classes c
    LEFT JOIN class_sections cs ON cs.class_id = c.id
    LEFT JOIN sections s ON s.id = cs.section_id
    ORDER BY c.title, s.title
");
while ($row = $class_result->fetch_assoc()) {
    $class_display = $row['class_title'];
    if (!empty($row['section_title'])) {
        $class_display .= ' - ' . $row['section_title'];
    }
    $classes[$row['class_id']] = $class_display;
}

// Fetch courses
$course_result = $conn->query("SELECT id, title FROM courses ORDER BY title");
while ($row = $course_result->fetch_assoc()) {
    $courses[$row['id']] = $row['title'];
}

// Month mapping for name-to-number conversion
$month_map = [
    'jan' => '01', 'january' => '01',
    'feb' => '02', 'february' => '02',
    'mar' => '03', 'march' => '03',
    'apr' => '04', 'april' => '04',
    'may' => '05',
    'jun' => '06', 'june' => '06',
    'jul' => '07', 'july' => '07',
    'aug' => '08', 'august' => '08',
    'sep' => '09', 'sept' => '09', 'september' => '09',
    'oct' => '10', 'october' => '10',
    'nov' => '11', 'november' => '11',
    'dec' => '12', 'december' => '12'
];

// Initialize edit mode variables
$edit_mode = false;
$student_data = [
    'id' => '', 
    'branch_id' => '1',
    'village_council_id' => '', 
    'reg_no' => '', 
    'session_id' => '', 
    'class_id' => '', 
    'course_id' => '', 
    'name' => '', 
    'dob_day' => '', 
    'dob_month' => '', 
    'dob_year' => '', 
    'father_name' => '', 
    'mobile' => '', 
    'cnic' => '', 
    'current_address' => '', 
    'permanent_address' => '', 
    'guardian_name' => '', 
    'guardian_mobile' => '', 
    'guardian_address' => '', 
    'guardian_cnic' => '', 
    'previous_schools_description' => '', 
    'student_other_description' => '', 
    'image_path' => '', 
    'transport_fee' => '0', 
    'is_transport' => '1',
    'registration_date' => date('Y-m-d'), 
    'is_old_dues' => '0', 
    'old_dues_amount' => '0',
    'is_admission' => '1',
    'gender' => '',
    'mname' => '',
    'mcnic' => ''
];

// Handle Edit Mode - Proper DOB Parsing
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    
    $stmt = $conn->prepare("
        SELECT 
            sr.*, 
            sc.class_id, 
            sc.session_id, 
            sc.promotion_date
        FROM student_registration sr
        LEFT JOIN (
            SELECT *
            FROM student_class
            WHERE student_registration_id = ?
            ORDER BY id DESC
            LIMIT 1
        ) sc ON sr.id = sc.student_registration_id
        WHERE sr.id = ?
    ");
    
    if ($stmt) {
        $stmt->bind_param("ii", $edit_id, $edit_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $student_data = $result->fetch_assoc();
            
            // Parse DOB
            if (!empty($student_data['dob']) && $student_data['dob'] !== '0000-00-00') {
                $dob_str = trim($student_data['dob']);
                
                try {
                    $date_obj = new DateTime($dob_str);
                    $student_data['dob_year'] = $date_obj->format('Y');
                    $student_data['dob_month'] = $date_obj->format('m');
                    $student_data['dob_day'] = $date_obj->format('d');
                } catch (Exception $e) {
                    $date_parts = explode('-', $dob_str);
                    if (count($date_parts) === 3) {
                        $student_data['dob_year'] = str_pad($date_parts[0], 4, '0', STR_PAD_LEFT);
                        $student_data['dob_month'] = str_pad($date_parts[1], 2, '0', STR_PAD_LEFT);
                        $student_data['dob_day'] = str_pad($date_parts[2], 2, '0', STR_PAD_LEFT);
                    }
                }
            } else {
                $student_data['dob_year'] = '';
                $student_data['dob_month'] = '';
                $student_data['dob_day'] = '';
            }
            
            // Ensure numeric fields have proper defaults
            $student_data['is_old_dues'] = isset($student_data['is_old_dues']) ? intval($student_data['is_old_dues']) : 0;
            $student_data['old_dues_amount'] = isset($student_data['old_dues_amount']) ? floatval($student_data['old_dues_amount']) : 0;
            $student_data['transport_fee'] = isset($student_data['transport_fee']) ? floatval($student_data['transport_fee']) : 0;
            $student_data['is_transport'] = isset($student_data['is_transport']) ? intval($student_data['is_transport']) : 1;
            
            $edit_mode = true;
        }
        $stmt->close();
    }
}

// Helper function to escape and quote strings
function escapeAndQuote($conn, $value) {
    if ($value === null || $value === '') {
        return "NULL";
    }
    return "'" . mysqli_real_escape_string($conn, $value) . "'";
}

// Handle form submission - USING ALTERNATIVE APPROACH
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['student_id'])) {
    
    // Grab and sanitize form data
    $student_id = intval($_POST['student_id']);
    $branch_id = 1;
    $village_council_id = isset($_POST['village_council_id']) ? intval($_POST['village_council_id']) : 0;
    $reg_no = mysqli_real_escape_string($conn, trim($_POST['reg_no']));
    $session_id = isset($_POST['session_id']) ? intval($_POST['session_id']) : 0;
    $class_id = isset($_POST['class_id']) ? intval($_POST['class_id']) : 0;
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
    
    // New fields
    $gender = mysqli_real_escape_string($conn, trim($_POST['gender']));
    $mname = mysqli_real_escape_string($conn, trim($_POST['mname']));
    $mcnic = mysqli_real_escape_string($conn, trim($_POST['mcnic']));
    
    // Handle DOB
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
    
    // Handle image upload
    $image_path = '';
    $update_image = false;
    if (!empty($_FILES['image']['name'])) {
        $image_name = time() . '_' . basename($_FILES['image']['name']);
        $target_dir = "Uploads/students/";
        
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0755, true);
        }
        
        $target_file = $target_dir . $image_name;
        $imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
        
        $valid_extensions = ['jpg', 'jpeg', 'png', 'gif'];
        if (in_array($imageFileType, $valid_extensions)) {
            if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
                $image_path = $target_file;
                $update_image = true;
            }
        }
    }
    
    // Escape values for SQL
    $dob_sql = $dob ? "'$dob'" : "NULL";
    $image_path_sql = $image_path ? "'$image_path'" : "NULL";
    $cnic_sql = $cnic ? "'$cnic'" : "NULL";
    $guardian_cnic_sql = $guardian_cnic ? "'$guardian_cnic'" : "NULL";
    $mcnic_sql = $mcnic ? "'$mcnic'" : "NULL";
    
    if ($student_id > 0) {
        // UPDATE existing student using direct query (simpler approach)
        if ($update_image) {
            $sql = "UPDATE student_registration SET 
                branch_id = $branch_id,
                village_council_id = $village_council_id,
                reg_no = '$reg_no',
                name = '$name',
                dob = $dob_sql,
                father_name = '$father_name',
                mobile = '$mobile',
                cnic = $cnic_sql,
                current_address = '$current_address',
                permanent_address = '$permanent_address',
                guardian_name = '$guardian_name',
                guardian_mobile = '$guardian_mobile',
                guardian_address = '$guardian_address',
                guardian_cnic = $guardian_cnic_sql,
                previous_schools_description = '$previous_schools_description',
                student_other_description = '$student_other_description',
                image_path = $image_path_sql,
                registration_date = '$registration_date',
                is_transport = $is_transport,
                transport_fee = $transport_fee,
                is_old_dues = $is_old_dues,
                old_dues_amount = $old_dues_amount,
                is_admission = $is_admission,
                gender = '$gender',
                mname = '$mname',
                mcnic = $mcnic_sql
                WHERE id = $student_id";
        } else {
            $sql = "UPDATE student_registration SET 
                branch_id = $branch_id,
                village_council_id = $village_council_id,
                reg_no = '$reg_no',
                name = '$name',
                dob = $dob_sql,
                father_name = '$father_name',
                mobile = '$mobile',
                cnic = $cnic_sql,
                current_address = '$current_address',
                permanent_address = '$permanent_address',
                guardian_name = '$guardian_name',
                guardian_mobile = '$guardian_mobile',
                guardian_address = '$guardian_address',
                guardian_cnic = $guardian_cnic_sql,
                previous_schools_description = '$previous_schools_description',
                student_other_description = '$student_other_description',
                registration_date = '$registration_date',
                is_transport = $is_transport,
                transport_fee = $transport_fee,
                is_old_dues = $is_old_dues,
                old_dues_amount = $old_dues_amount,
                is_admission = $is_admission,
                gender = '$gender',
                mname = '$mname',
                mcnic = $mcnic_sql
                WHERE id = $student_id";
        }
        
        if ($conn->query($sql)) {
            // Update or insert student_class record
            $check_stmt = $conn->prepare("SELECT id FROM student_class WHERE student_registration_id = ? order by id desc limit 1");
            $check_stmt->bind_param("i", $student_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $class_row = $check_result->fetch_assoc();
                $student_class_id = $class_row['id'];
                
                $update_class_stmt = $conn->prepare("UPDATE student_class SET 
                    session_id = ?, class_id = ?, promotion_date = ? WHERE id = ?");
                $update_class_stmt->bind_param("iisi", $session_id, $class_id, $promotion_date, $student_class_id);
                $update_class_stmt->execute();
                $update_class_stmt->close();
            } else {
                $insert_class_stmt = $conn->prepare("INSERT INTO student_class 
                    (student_registration_id, session_id, class_id, promotion_date) VALUES (?, ?, ?, ?)");
                $insert_class_stmt->bind_param("iiis", $student_id, $session_id, $class_id, $promotion_date);
                $insert_class_stmt->execute();
                $insert_class_stmt->close();
            }
            $check_stmt->close();
            
            $_SESSION['message'] = $translations[$lang]['update_success'];
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = $translations[$lang]['error'] . $conn->error;
            $_SESSION['message_type'] = 'danger';
        }
        
    } else {
        // INSERT new student using direct query
        $sql = "INSERT INTO student_registration (
            branch_id, village_council_id, reg_no, name, dob, father_name, mobile, cnic,
            current_address, permanent_address, guardian_name, guardian_mobile,
            guardian_address, guardian_cnic, previous_schools_description, student_other_description,
            image_path, registration_date, is_transport, transport_fee, is_old_dues, old_dues_amount, 
            is_admission, gender, mname, mcnic
        ) VALUES (
            $branch_id, $village_council_id, '$reg_no', '$name', $dob_sql, '$father_name', '$mobile', $cnic_sql,
            '$current_address', '$permanent_address', '$guardian_name', '$guardian_mobile',
            '$guardian_address', $guardian_cnic_sql, '$previous_schools_description', '$student_other_description',
            $image_path_sql, '$registration_date', $is_transport, $transport_fee, $is_old_dues, $old_dues_amount,
            $is_admission, '$gender', '$mname', $mcnic_sql
        )";
        
        if ($conn->query($sql)) {
            $new_student_id = $conn->insert_id;
            
            $class_stmt = $conn->prepare("INSERT INTO student_class (
                student_registration_id, session_id, class_id, promotion_date
            ) VALUES (?, ?, ?, ?)");
            $class_stmt->bind_param("iiis", $new_student_id, $session_id, $class_id, $promotion_date);
            $class_stmt->execute();
            $class_stmt->close();
            
            if ($is_old_dues == 0) {
                $fee_query = "SELECT amount FROM class_fee_types 
                             WHERE class_id = $class_id AND fee_type_id = 1 AND session_id = $session_id LIMIT 1";
                $fee_result = $conn->query($fee_query);
                
                if ($fee_result && $fee_result->num_rows > 0) {
                    $fee_row = $fee_result->fetch_assoc();
                    $admission_fee = $fee_row['amount'];
                    
                    $scid_stmt = $conn->prepare("SELECT id FROM student_class WHERE student_registration_id = ? ORDER BY id DESC LIMIT 1");
                    $scid_stmt->bind_param("i", $new_student_id);
                    $scid_stmt->execute();
                    $scid_result = $scid_stmt->get_result();
                    
                    if ($scid_result->num_rows > 0) {
                        $scid_row = $scid_result->fetch_assoc();
                        $student_class_id = $scid_row['id'];
                        
                        $fee_insert = $conn->prepare("INSERT INTO student_fee_card (
                            student_class_id, fee_type_id, total_amount, due_date
                        ) VALUES (?, 1, ?, ?)");
                        $fee_insert->bind_param("ids", $student_class_id, $admission_fee, $registration_date);
                        $fee_insert->execute();
                        $fee_insert->close();
                    }
                    $scid_stmt->close();
                }
            }
            
            $_SESSION['message'] = $translations[$lang]['success'];
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = $translations[$lang]['error'] . $conn->error;
            $_SESSION['message_type'] = 'danger';
        }
    }
    
    header("Location: student_registration.php");
    exit();
}

// Handle student deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = intval($_GET['delete']);
    
    $stmt = $conn->prepare("DELETE FROM student_class WHERE student_registration_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    
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
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f5f7fa;
        }
        .two-column-form .form-section {
            margin-bottom: 25px;
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            background-color: #ffffff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .two-column-form .form-section h4 {
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #007bff;
            color: #333;
            font-weight: 600;
        }
        .form-group label {
            font-weight: 500;
            color: #555;
            margin-bottom: 5px;
        }
        .student-image {
            max-width: 150px;
            max-height: 150px;
            object-fit: cover;
            border-radius: 8px;
        }
        .panel {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
            overflow: hidden;
            border: none;
        }
        .panel-heading {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
        }
        .panel-title {
            margin: 0;
            font-weight: 600;
            font-size: 1.25rem;
        }
        .panel-body {
            padding: 30px;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 12px 30px;
            font-weight: 500;
            border-radius: 6px;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        .btn-secondary {
            padding: 12px 30px;
            border-radius: 6px;
        }
        .btn-danger {
            padding: 12px 30px;
            border-radius: 6px;
        }
        .current-image {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-top: 10px;
        }
        .alert {
            border-radius: 8px;
            margin-bottom: 25px;
            border: none;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .form-control {
            border-radius: 6px;
            border: 1px solid #ddd;
            padding: 10px 12px;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-weight: 500;
        }
        .checkbox-label input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
    </style>
</head>

<body>
    <?php require_once('navbar.php'); ?>

    <div class="container-fluid" style="padding: 20px;">
        <div class="row">
            <div class="col-12">
                <div class="mb-4 text-right">
                    <a href="student_list.php" class="btn btn-info btn-lg">
                        <i class="fas fa-list"></i> <?php echo htmlspecialchars($translations[$lang]['view_students']); ?>
                    </a>
                </div>

                <div class="panel">
                    <div class="panel-heading">
                        <h3 class="panel-title">
                            <?php echo $edit_mode ? htmlspecialchars($translations[$lang]['edit_title']) : htmlspecialchars($translations[$lang]['add_title']); ?>
                        </h3>
                    </div>
                    <div class="panel-body">
                        <?php if (isset($_SESSION['message'])): ?>
                        <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show">
                            <?php 
                            echo htmlspecialchars($_SESSION['message']); 
                            unset($_SESSION['message'], $_SESSION['message_type']);
                            ?>
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <?php endif; ?>

                        <form id="studentForm" method="post" action="" enctype="multipart/form-data" class="two-column-form">
                            <input type="hidden" name="student_id" value="<?php echo intval($student_data['id']); ?>">

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-section">
                                        <h4>Basic Information</h4>
                                        <div class="form-group">
                                            <label for="reg_no"><?php echo htmlspecialchars($translations[$lang]['reg_no_label']); ?></label>
                                            <input type="text" class="form-control" id="reg_no" name="reg_no" 
                                                value="<?php echo htmlspecialchars($student_data['reg_no']); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="name"><?php echo htmlspecialchars($translations[$lang]['name_label']); ?></label>
                                            <input type="text" class="form-control" id="name" name="name" required
                                                value="<?php echo htmlspecialchars($student_data['name']); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="gender"><?php echo htmlspecialchars($translations[$lang]['gender_label']); ?></label>
                                            <select class="form-control" id="gender" name="gender" required>
                                                <option value=""><?php echo htmlspecialchars($translations[$lang]['select_option']); ?></option>
                                                <option value="Male" <?php echo ($student_data['gender'] == 'Male') ? 'selected' : ''; ?>><?php echo htmlspecialchars($translations[$lang]['male']); ?></option>
                                                <option value="Female" <?php echo ($student_data['gender'] == 'Female') ? 'selected' : ''; ?>><?php echo htmlspecialchars($translations[$lang]['female']); ?></option>
                                                <option value="Other" <?php echo ($student_data['gender'] == 'Other') ? 'selected' : ''; ?>><?php echo htmlspecialchars($translations[$lang]['other']); ?></option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="father_name"><?php echo htmlspecialchars($translations[$lang]['father_name_label']); ?></label>
                                            <input type="text" class="form-control" id="father_name" name="father_name" required
                                                value="<?php echo htmlspecialchars($student_data['father_name']); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="mname"><?php echo htmlspecialchars($translations[$lang]['mother_name_label']); ?></label>
                                            <input type="text" class="form-control" id="mname" name="mname" required
                                                value="<?php echo htmlspecialchars($student_data['mname']); ?>">
                                        </div>
                                        
                                        <!-- DOB Form Fields -->
                                        <div class="form-group">
                                            <label><?php echo htmlspecialchars($translations[$lang]['dob_label']); ?></label>
                                            <div class="form-row">
                                                <div class="col-3">
                                                    <input type="text" class="form-control" id="dob_day" name="dob_day" 
                                                        value="<?php echo htmlspecialchars($student_data['dob_day'] ?? ''); ?>"
                                                        placeholder="DD" maxlength="2"
                                                        oninput="this.value=this.value.replace(/[^0-9]/g,''); if(this.value.length > 2) this.value = this.value.slice(0,2);">
                                                </div>
                                                <div class="col-5">
                                                    <select class="form-control" id="dob_month" name="dob_month">
                                                        <option value=""><?php echo htmlspecialchars($translations[$lang]['select_option']); ?></option>
                                                        <option value="01" <?php echo (isset($student_data['dob_month']) && $student_data['dob_month'] === '01') ? 'selected' : ''; ?>>January</option>
                                                        <option value="02" <?php echo (isset($student_data['dob_month']) && $student_data['dob_month'] === '02') ? 'selected' : ''; ?>>February</option>
                                                        <option value="03" <?php echo (isset($student_data['dob_month']) && $student_data['dob_month'] === '03') ? 'selected' : ''; ?>>March</option>
                                                        <option value="04" <?php echo (isset($student_data['dob_month']) && $student_data['dob_month'] === '04') ? 'selected' : ''; ?>>April</option>
                                                        <option value="05" <?php echo (isset($student_data['dob_month']) && $student_data['dob_month'] === '05') ? 'selected' : ''; ?>>May</option>
                                                        <option value="06" <?php echo (isset($student_data['dob_month']) && $student_data['dob_month'] === '06') ? 'selected' : ''; ?>>June</option>
                                                        <option value="07" <?php echo (isset($student_data['dob_month']) && $student_data['dob_month'] === '07') ? 'selected' : ''; ?>>July</option>
                                                        <option value="08" <?php echo (isset($student_data['dob_month']) && $student_data['dob_month'] === '08') ? 'selected' : ''; ?>>August</option>
                                                        <option value="09" <?php echo (isset($student_data['dob_month']) && $student_data['dob_month'] === '09') ? 'selected' : ''; ?>>September</option>
                                                        <option value="10" <?php echo (isset($student_data['dob_month']) && $student_data['dob_month'] === '10') ? 'selected' : ''; ?>>October</option>
                                                        <option value="11" <?php echo (isset($student_data['dob_month']) && $student_data['dob_month'] === '11') ? 'selected' : ''; ?>>November</option>
                                                        <option value="12" <?php echo (isset($student_data['dob_month']) && $student_data['dob_month'] === '12') ? 'selected' : ''; ?>>December</option>
                                                    </select>
                                                </div>
                                                <div class="col-4">
                                                    <input type="text" class="form-control" id="dob_year" name="dob_year"
                                                        value="<?php echo htmlspecialchars($student_data['dob_year'] ?? ''); ?>"
                                                        placeholder="YYYY" maxlength="4"
                                                        oninput="this.value=this.value.replace(/[^0-9]/g,''); if(this.value.length > 4) this.value = this.value.slice(0,4);">
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="mobile"><?php echo htmlspecialchars($translations[$lang]['mobile_label']); ?></label>
                                            <input type="text" class="form-control" id="mobile" name="mobile" required
                                                value="<?php echo htmlspecialchars($student_data['mobile']); ?>"
                                                pattern="[0-9]{11}" maxlength="11" placeholder="03XXXXXXXXX"
                                                oninput="this.value=this.value.replace(/[^0-9]/g,''); if(this.value.length > 11) this.value = this.value.slice(0,11);">
                                        </div>
                                        <div class="form-group">
                                            <label for="cnic"><?php echo htmlspecialchars($translations[$lang]['cnic_label']); ?></label>
                                            <input type="text" class="form-control" id="cnic" name="cnic"
                                                value="<?php echo htmlspecialchars($student_data['cnic']); ?>"
                                                pattern="[0-9]{13}" maxlength="13" placeholder="13 digits without dashes"
                                                oninput="this.value=this.value.replace(/[^0-9]/g,''); if(this.value.length > 13) this.value = this.value.slice(0,13);">
                                        </div>
                                        <div class="form-group">
                                            <label for="mcnic"><?php echo htmlspecialchars($translations[$lang]['mother_cnic_label']); ?></label>
                                            <input type="text" class="form-control" id="mcnic" name="mcnic"
                                                value="<?php echo htmlspecialchars($student_data['mcnic']); ?>"
                                                pattern="[0-9]{13}" maxlength="13" placeholder="13 digits without dashes"
                                                oninput="this.value=this.value.replace(/[^0-9]/g,''); if(this.value.length > 13) this.value = this.value.slice(0,13);">
                                        </div>
                                    </div>

                                    <div class="form-section">
                                        <h4>Address Information</h4>
                                        <div class="form-group">
                                            <label for="current_address"><?php echo htmlspecialchars($translations[$lang]['current_address_label']); ?></label>
                                            <textarea class="form-control" id="current_address" name="current_address" rows="2" required><?php echo htmlspecialchars($student_data['current_address']); ?></textarea>
                                        </div>
                                        <div class="form-group">
                                            <label for="permanent_address"><?php echo htmlspecialchars($translations[$lang]['permanent_address_label']); ?></label>
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

                                <div class="col-md-6">
                                    <div class="form-section">
                                        <h4>Guardian Information</h4>
                                        <div class="form-group">
                                            <label for="guardian_name"><?php echo htmlspecialchars($translations[$lang]['guardian_name_label']); ?></label>
                                            <input type="text" class="form-control" id="guardian_name" name="guardian_name"
                                                value="<?php echo htmlspecialchars($student_data['guardian_name']); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="guardian_mobile"><?php echo htmlspecialchars($translations[$lang]['guardian_mobile_label']); ?></label>
                                            <input type="text" class="form-control" id="guardian_mobile" name="guardian_mobile"
                                                value="<?php echo htmlspecialchars($student_data['guardian_mobile']); ?>"
                                                pattern="[0-9]{11}" maxlength="11" placeholder="03XXXXXXXXX"
                                                oninput="this.value=this.value.replace(/[^0-9]/g,''); if(this.value.length > 11) this.value = this.value.slice(0,11);">
                                        </div>
                                        <div class="form-group">
                                            <label for="guardian_address"><?php echo htmlspecialchars($translations[$lang]['guardian_address_label']); ?></label>
                                            <textarea class="form-control" id="guardian_address" name="guardian_address" rows="2"><?php echo htmlspecialchars($student_data['guardian_address']); ?></textarea>
                                        </div>
                                        <div class="form-group">
                                            <label for="guardian_cnic"><?php echo htmlspecialchars($translations[$lang]['guardian_cnic_label']); ?></label>
                                            <input type="text" class="form-control" id="guardian_cnic" name="guardian_cnic"
                                                value="<?php echo htmlspecialchars($student_data['guardian_cnic']); ?>"
                                                pattern="[0-9]{13}" maxlength="13" placeholder="13 digits without dashes"
                                                oninput="this.value=this.value.replace(/[^0-9]/g,''); if(this.value.length > 13) this.value = this.value.slice(0,13);">
                                        </div>
                                    </div>

                                    <div class="form-section">
                                        <h4>Session and Class</h4>
                                        <div class="form-group">
                                            <label for="session_id"><?php echo htmlspecialchars($translations[$lang]['session_label']); ?></label>
                                            <select class="form-control" id="session_id" name="session_id" required>
                                                <option value=""><?php echo htmlspecialchars($translations[$lang]['select_option']); ?></option>
                                                <?php foreach ($sessions as $id => $name): ?>
                                                <option value="<?php echo intval($id); ?>" <?php echo ($id == $student_data['session_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($name); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="class_id"><?php echo htmlspecialchars($translations[$lang]['class_label']); ?></label>
                                            <select class="form-control" id="class_id" name="class_id" required>
                                                <option value=""><?php echo htmlspecialchars($translations[$lang]['select_option']); ?></option>
                                                <?php foreach ($classes as $id => $name): ?>
                                                <option value="<?php echo intval($id); ?>" <?php echo ($id == $student_data['class_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($name); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-section">
                                        <h4>Admission and Dues</h4>
                                        <div class="form-group">
                                            <label class="checkbox-label">
                                                <input type="checkbox" id="is_old_dues" name="is_old_dues" value="1"
                                                    <?php echo ($student_data['is_old_dues'] == 1) ? 'checked' : ''; ?>>
                                                <?php echo htmlspecialchars($translations[$lang]['old_admission_label']); ?>
                                            </label>
                                            <small class="text-muted d-block mt-1">(Check if old admission with previous dues)</small>
                                            
                                            <div id="old_dues_amount_container" style="<?php echo ($student_data['is_old_dues'] == 1) ? 'display: block; margin-top: 10px;' : 'display: none;'; ?>">
                                                <label for="old_dues_amount"><?php echo htmlspecialchars($translations[$lang]['old_dues_amount_label']); ?></label>
                                                <input type="number" class="form-control" id="old_dues_amount" name="old_dues_amount"
                                                    value="<?php echo htmlspecialchars($student_data['old_dues_amount']); ?>"
                                                    min="0" step="0.01" placeholder="0.00">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-section">
                                        <h4>Transport and Village Council</h4>
                                        <div class="form-group">
                                            <label for="village_council_id"><?php echo htmlspecialchars($translations[$lang]['village_council_label']); ?></label>
                                            <select class="form-control" id="village_council_id" name="village_council_id" required>
                                                <option value=""><?php echo htmlspecialchars($translations[$lang]['select_option']); ?></option>
                                                <?php foreach ($village_councils as $id => $data): ?>
                                                <option value="<?php echo intval($id); ?>"
                                                    <?php echo ($id == $student_data['village_council_id']) ? 'selected' : ''; ?>
                                                    data-transport-fee="<?php echo floatval($data['transport_fee']); ?>">
                                                    <?php echo htmlspecialchars($data['title']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="transport_fee"><?php echo htmlspecialchars($translations[$lang]['transport_fee_label']); ?></label>
                                            <input type="number" class="form-control" id="transport_fee" name="transport_fee"
                                                value="<?php echo htmlspecialchars($student_data['transport_fee']); ?>"
                                                min="0" step="0.01" required>
                                        </div>
                                        <div class="form-group">
                                            <label class="checkbox-label">
                                                <input type="checkbox" id="is_transport" name="is_transport" value="0"
                                                    <?php echo ($student_data['is_transport'] == 0) ? 'checked' : ''; ?>>
                                                <?php echo htmlspecialchars($translations[$lang]['transport_label']); ?>
                                            </label>
                                            <small class="text-muted">(Check if student uses transport)</small>
                                        </div>
                                        <div class="form-group">
                                            <label for="registration_date"><?php echo htmlspecialchars($translations[$lang]['registration_date_label']); ?></label>
                                            <input type="date" class="form-control" id="registration_date" name="registration_date"
                                                value="<?php echo htmlspecialchars($student_data['registration_date']); ?>" required>
                                        </div>
                                    </div>

                                    <div class="form-section">
                                        <h4>Student Image</h4>
                                        <div class="form-group">
                                            <label for="image"><?php echo htmlspecialchars($translations[$lang]['image_label']); ?></label>
                                            <input type="file" class="form-control-file" id="image" name="image" accept="image/*">
                                            <small class="form-text text-muted">Upload new image to replace existing one (optional)</small>
                                            
                                            <?php if (!empty($student_data['image_path'])): ?>
                                            <div class="current-image mt-3">
                                                <p class="text-muted mb-2"><strong>Current Image:</strong></p>
                                                <img src="<?php echo htmlspecialchars($student_data['image_path']); ?>" 
                                                    class="student-image img-thumbnail" alt="Student Photo">
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-12">
                                    <div class="form-section" style="background: #f8f9fa; border: none;">
                                        <div class="form-group text-center mb-0">
                                            <button type="submit" class="btn btn-primary btn-lg mr-2">
                                                <?php echo $edit_mode ? htmlspecialchars($translations[$lang]['save']) : htmlspecialchars($translations[$lang]['submit']); ?>
                                            </button>
                                            <button type="reset" class="btn btn-secondary btn-lg mr-2">
                                                <?php echo htmlspecialchars($translations[$lang]['reset']); ?>
                                            </button>
                                            <?php if ($edit_mode): ?>
                                            <a href="student_registration.php" class="btn btn-danger btn-lg">
                                                <?php echo htmlspecialchars($translations[$lang]['cancel']); ?>
                                            </a>
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
            if ($(this).is(':checked')) {
                $('#old_dues_amount_container').slideDown();
            } else {
                $('#old_dues_amount_container').slideUp();
                $('#old_dues_amount').val('0');
            }
        });

        $('#village_council_id').change(function() {
            var selectedOption = $(this).find('option:selected');
            var transportFee = selectedOption.data('transport-fee') || 0;
            $('#transport_fee').val(transportFee);
        });

        <?php if ($edit_mode): ?>
        $('#village_council_id').trigger('change');
        <?php endif; ?>
        
        $('#studentForm').on('submit', function(e) {
            var regNo = $('#reg_no').val().trim();
            var name = $('#name').val().trim();
            var gender = $('#gender').val();
            var fatherName = $('#father_name').val().trim();
            var mname = $('#mname').val().trim();
            var mobile = $('#mobile').val().trim();
            var sessionId = $('#session_id').val();
            var classId = $('#class_id').val();
            var villageCouncilId = $('#village_council_id').val();
            
            if (!regNo || !name || !gender || !fatherName || !mname || !mobile || !sessionId || !classId || !villageCouncilId) {
                alert('Please fill in all required fields');
                e.preventDefault();
                return false;
            }
            
            if (mobile.length !== 11) {
                alert('Mobile number must be exactly 11 digits');
                e.preventDefault();
                return false;
            }
        });
    });
    </script>

</body>

</html>
<?php
$conn->close();
?>