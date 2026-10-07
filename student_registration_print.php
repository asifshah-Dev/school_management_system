<?php
require_once('security.php');
require_once('conn_inc.php');

// Language handling
$_SESSION['lang'] = 'en';
$lang = $_SESSION['lang'];

// Translations
$translations = [
    'en' => [
        'title' => 'Student Registration Form',
        'print_btn' => 'Print Form',
        'back_btn' => 'Back',
        'basic_info' => 'Basic Information',
        'address_info' => 'Address Information',
        'guardian_info' => 'Guardian Information',
        'academic_info' => 'Academic Information',
        'transport_info' => 'Transport Information',
        'other_info' => 'Other Information',
        'branch' => 'Branch',
        'village_council' => 'Village Council',
        'session' => 'Session',
        'class' => 'Class',
        'name' => 'Student Name',
        'father_name' => 'Father Name',
        'mobile' => 'Mobile',
        'cnic' => 'CNIC',
        'current_address' => 'Current Address',
        'permanent_address' => 'Permanent Address',
        'guardian_name' => 'Guardian Name',
        'guardian_mobile' => 'Guardian Mobile',
        'guardian_address' => 'Guardian Address',
        'guardian_cnic' => 'Guardian CNIC',
        'previous_schools' => 'Previous Schools',
        'other_details' => 'Other Details',
        'student_image' => 'Student Image',
        'transport_fee' => 'Transport Fee',
        'using_transport' => 'Using Transport',
        'registration_date' => 'Registration Date',
        'yes' => 'Yes',
        'no' => 'No',
        'signature' => 'Signature',
        'date' => 'Date',
        'place' => 'Place',
        'form_title' => 'STUDENT REGISTRATION FORM',
        'school_name' => 'Your School Name',
        'school_address' => '123 School Road, City, Country',
        'office_use' => 'For Office Use Only',
        'registration_no' => 'Registration No.',
        'form_footer' => 'I hereby declare that the information provided is correct to the best of my knowledge.'
    ]
];

// Get student data
$student_data = [];
if (isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $result = $conn->query("
        SELECT sr.*, 
               vc.title AS village_title, 
               b.title AS branch_title,
               s.title AS session_title,
               c.title AS class_title,
               co.title AS course_title
        FROM student_registration sr
        LEFT JOIN village_councils vc ON sr.village_council_id = vc.id
        LEFT JOIN branches b ON sr.branch_id = b.id
        LEFT JOIN (
            SELECT sc1.*
            FROM student_class sc1
            INNER JOIN (
                SELECT student_registration_id, MAX(id) AS max_id
                FROM student_class
                GROUP BY student_registration_id
            ) sc2 ON sc1.id = sc2.max_id
        ) sc ON sr.id = sc.student_registration_id
        LEFT JOIN sessions s ON sc.session_id = s.id
        LEFT JOIN classes c ON sc.class_id = c.id
        LEFT JOIN courses co ON c.course_id = co.id
        WHERE sr.id = '$id'
    ");
    
    if ($result->num_rows > 0) {
        $student_data = $result->fetch_assoc();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo $translations[$lang]['title']; ?></title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <style>
        body {
            font-family: Helvetica, Arial, sans-serif;
            color: #333;
            line-height: 1.4;
        }

        .print-container {
            max-width: 850px;
            margin: 10px auto;
            padding: 15px;
            border: 1px solid #2c3e50;
            background: #fff;
        }

        .print-header {
            text-align: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #2c3e50;
        }

        .school-logo {
            width: 60px;
            height: 60px;
            margin-bottom: 5px;
        }

        .print-title {
            font-size: 20px;
            font-weight: 700;
            color: #2c3e50;
            text-transform: uppercase;
            margin: 5px 0;
        }

        .school-info {
            font-size: 11px;
            color: #555;
        }

        .form-section {
            margin-bottom: 10px;
            padding: 8px;
            background: #fafafa;
            border-radius: 3px;
            page-break-inside: avoid;
        }

        .section-title {
            font-size: 15px;
            font-weight: 600;
            color: #fff;
            background: #2c3e50;
            padding: 5px 10px;
            margin-bottom: 8px;
            border-radius: 3px;
        }

        .form-row {
            display: flex;
            flex-wrap: wrap;
            margin-bottom: 6px;
        }

        .form-field {
            flex: 1;
            min-width: 180px;
            padding: 0 5px;
        }

        .label {
            font-weight: 600;
            font-size: 13px;
            color: #2c3e50;
        }

        .value {
            font-size: 13px;
            padding: 3px 0;
            border-bottom: 1px solid #ccc;
        }

        .photo-container {
            width: 100px;
            height: 120px;
            border: 1px solid #ccc;
            margin: 0 8px 5px 0;
            float: right;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fff;
        }

        .student-photo {
            max-width: 90px;
            max-height: 110px;
        }

        .table-info {
            width: 100%;
            border-collapse: collapse;
            margin-top: 5px;
        }

        .table-info th, .table-info td {
            border: 1px solid #ccc;
            padding: 4px 6px;
            font-size: 13px;
        }

        .table-info th {
            background: #e7f1fa;
            font-weight: 600;
            color: #2c3e50;
        }

        .signature-area {
            margin-top: 15px;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
        }

        .signature-box {
            width: 180px;
            text-align: center;
            padding-top: 3px;
        }

        .signature-line {
            border-top: 1px solid #2c3e50;
            margin-top: 20px;
        }

        .footer-note {
            margin: 15px 0 5px;
            font-size: 10px;
            font-style: italic;
            text-align: center;
            color: #555;
        }

        .office-use {
            border: 1px solid #2c3e50;
            padding: 8px;
            margin-bottom: 10px;
            background: #fafafa;
            border-radius: 3px;
        }

        @media print {
            body {
                margin: 0;
                background: white;
                font-size: 10pt;
            }
            .no-print {
                display: none;
            }
            .print-container {
                margin: 0;
                border: none;
                padding: 5px;
            }
            .form-section {
                background: none;
                padding: 5px;
            }
            .table-info th, .table-info td {
                border: 1px solid #000;
            }
            .page-break {
                page-break-after: always;
            }
        }
    </style>
</head>
<body>
<div class="container no-print" style="margin: 10px 0;">
    <div class="row">
        <div class="col-md-12 text-right">
            <button onclick="window.print();" class="btn btn-primary btn-sm">
                <span class="glyphicon glyphicon-print"></span> 
                <?php echo $translations[$lang]['print_btn']; ?>
            </button>
            <a href="student_registration.php" class="btn btn-default btn-sm">
                <span class="glyphicon glyphicon-arrow-left"></span> 
                <?php echo $translations[$lang]['back_btn']; ?>
            </a>
        </div>
    </div>
</div>

<div class="print-container">
    <!-- Header -->
    <div class="print-header">
        <img src="logo2.png" class="school-logo" alt="School Logo">
        <div class="print-title"><?php echo $translations[$lang]['form_title']; ?></div>
        <div class="school-info"><?php echo $translations[$lang]['school_name']; ?></div>
        <div class="school-info"><?php echo $translations[$lang]['school_address']; ?></div>
    </div>

    <!-- Office Use Only -->
    <div class="office-use">
        <div class="section-title"><?php echo $translations[$lang]['office_use']; ?></div>
        <div class="form-row">
            <div class="form-field">
                <div class="label"><?php echo $translations[$lang]['registration_no']; ?></div>
                <div class="value"><?php echo isset($student_data['id']) ? htmlspecialchars($student_data['reg_no']) : ''; ?></div>
            </div>
            <div class="form-field">
                <div class="label"><?php echo $translations[$lang]['registration_date']; ?></div>
                <div class="value"><?php echo isset($student_data['registration_date']) ? htmlspecialchars($student_data['registration_date']) : ''; ?></div>
            </div>
        </div>
    </div>

    <!-- Student Photo -->
    <div class="photo-container">
        <?php if (!empty($student_data['image_path'])): ?>
            <img src="<?php echo htmlspecialchars($student_data['image_path']); ?>" class="student-photo" alt="Student Photo">
        <?php else: ?>
            <span><?php echo $translations[$lang]['student_image']; ?></span>
        <?php endif; ?>
    </div>

    <!-- Basic Information -->
    <div class="form-section">
        <div class="section-title"><?php echo $translations[$lang]['basic_info']; ?></div>
        <div class="form-row">
            <div class="form-field">
                <div class="label"><?php echo $translations[$lang]['name']; ?></div>
                <div class="value"><?php echo isset($student_data['name']) ? htmlspecialchars($student_data['name']) : ''; ?></div>
            </div>
            <div class="form-field">
                <div class="label"><?php echo $translations[$lang]['father_name']; ?></div>
                <div class="value"><?php echo isset($student_data['father_name']) ? htmlspecialchars($student_data['father_name']) : ''; ?></div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-field">
                <div class="label"><?php echo $translations[$lang]['mobile']; ?></div>
                <div class="value"><?php echo isset($student_data['mobile']) ? htmlspecialchars($student_data['mobile']) : ''; ?></div>
            </div>
            <div class="form-field">
                <div class="label"><?php echo $translations[$lang]['cnic']; ?></div>
                <div class="value"><?php echo isset($student_data['cnic']) ? htmlspecialchars($student_data['cnic']) : ''; ?></div>
            </div>
        </div>
    </div>

    <!-- Address Information -->
    <div class="form-section">
        <div class="section-title"><?php echo $translations[$lang]['address_info']; ?></div>
        <div class="form-row">
            <div class="form-field">
                <div class="label"><?php echo $translations[$lang]['current_address']; ?></div>
                <div class="value" style="min-height: 30px;"><?php echo isset($student_data['current_address']) ? nl2br(htmlspecialchars($student_data['current_address'])) : ''; ?></div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-field">
                <div class="label"><?php echo $translations[$lang]['permanent_address']; ?></div>
                <div class="value" style="min-height: 30px;"><?php echo isset($student_data['permanent_address']) ? nl2br(htmlspecialchars($student_data['permanent_address'])) : ''; ?></div>
            </div>
        </div>
    </div>

    <!-- Guardian Information -->
    <div class="form-section">
        <div class="section-title"><?php echo $translations[$lang]['guardian_info']; ?></div>
        <div class="form-row">
            <div class="form-field">
                <div class="label"><?php echo $translations[$lang]['guardian_name']; ?></div>
                <div class="value"><?php echo isset($student_data['guardian_name']) ? htmlspecialchars($student_data['guardian_name']) : ''; ?></div>
            </div>
            <div class="form-field">
                <div class="label"><?php echo $translations[$lang]['guardian_mobile']; ?></div>
                <div class="value"><?php echo isset($student_data['guardian_mobile']) ? htmlspecialchars($student_data['guardian_mobile']) : ''; ?></div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-field">
                <div class="label"><?php echo $translations[$lang]['guardian_cnic']; ?></div>
                <div class="value"><?php echo isset($student_data['guardian_cnic']) ? htmlspecialchars($student_data['guardian_cnic']) : ''; ?></div>
            </div>
            <div class="form-field">
                <div class="label"><?php echo $translations[$lang]['guardian_address']; ?></div>
                <div class="value" style="min-height: 30px;"><?php echo isset($student_data['guardian_address']) ? nl2br(htmlspecialchars($student_data['guardian_address'])) : ''; ?></div>
            </div>
        </div>
    </div>

    <!-- Academic Information -->
    <div class="form-section">
        <div class="section-title"><?php echo $translations[$lang]['academic_info']; ?></div>
        <table class="table-info">
            <tr>
                <th><?php echo $translations[$lang]['branch']; ?></th>
                <td><?php echo isset($student_data['branch_title']) ? htmlspecialchars($student_data['branch_title']) : ''; ?></td>
            </tr>
            <tr>
                <th><?php echo $translations[$lang]['village_council']; ?></th>
                <td><?php echo isset($student_data['village_title']) ? htmlspecialchars($student_data['village_title']) : ''; ?></td>
            </tr>
            <tr>
                <th><?php echo $translations[$lang]['session']; ?></th>
                <td><?php echo isset($student_data['session_title']) ? htmlspecialchars($student_data['session_title']) : ''; ?></td>
            </tr>
            <tr>
                <th><?php echo $translations[$lang]['class']; ?></th>
                <td><?php echo isset($student_data['course_title']) && isset($student_data['class_title']) ? htmlspecialchars($student_data['course_title'] . ' - ' . $student_data['class_title']) : ''; ?></td>
            </tr>
        </table>
    </div>

    <!-- Transport Information -->
    <div class="form-section">
        <div class="section-title"><?php echo $translations[$lang]['transport_info']; ?></div>
        <table class="table-info">
            <tr>
                <th><?php echo $translations[$lang]['using_transport']; ?></th>
                <td><?php echo isset($student_data['is_transport']) ? ($student_data['is_transport'] == 0 ? $translations[$lang]['yes'] : $translations[$lang]['no']) : ''; ?></td>
            </tr>
            <tr>
                <th><?php echo $translations[$lang]['transport_fee']; ?></th>
                <td><?php echo isset($student_data['transport_fee']) ? htmlspecialchars($student_data['transport_fee']) : ''; ?></td>
            </tr>
        </table>
    </div>

    <!-- Other Information -->
    <div class="form-section">
        <div class="section-title"><?php echo $translations[$lang]['other_info']; ?></div>
        <div class="form-row">
            <div class="form-field">
                <div class="label"><?php echo $translations[$lang]['previous_schools']; ?></div>
                <div class="value" style="min-height: 40px;"><?php echo isset($student_data['previous_schools_description']) ? nl2br(htmlspecialchars($student_data['previous_schools_description'])) : ''; ?></div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-field">
                <div class="label"><?php echo $translations[$lang]['other_details']; ?></div>
                <div class="value" style="min-height: 40px;"><?php echo isset($student_data['student_other_description']) ? nl2br(htmlspecialchars($student_data['student_other_description'])) : ''; ?></div>
            </div>
        </div>
    </div>

    <!-- Signature Area -->
    <div class="signature-area">
        <div class="signature-box">
            <div class="signature-line"></div>
            <div><?php echo $translations[$lang]['signature']; ?></div>
        </div>
        <div class="signature-box">
            <div class="signature-line"></div>
            <div><?php echo $translations[$lang]['date']; ?></div>
        </div>
        <div class="signature-box">
            <div class="signature-line"></div>
            <div><?php echo $translations[$lang]['place']; ?></div>
        </div>
    </div>

    <!-- Footer Note -->
    <div class="footer-note">
        <?php echo $translations[$lang]['form_footer']; ?>
    </div>
</div>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
</body>
</html>
<?php
$conn->close();
?>