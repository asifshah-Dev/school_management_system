<?php
require_once('security.php');
require_once('conn_inc.php');

// Language handling
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'];
} elseif (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = 'en'; // Default to English
}
$lang = $_SESSION['lang'];

// Language strings
$translations = [
    'en' => [
        'title' => 'Student Registration Report',
        'institution_name' => 'Al-Farooqia Educational Institute',
        'select_class' => 'Select Class/Course:',
        'no_class_selected' => 'Please select a class to view students.',
        'no_records' => 'No students found for the selected class.',
        'print' => 'Print Report',
        'sr_no' => '#',
        'name_label' => 'Student Name',
        'father_name_label' => 'Father Name',
        'reg_no_label' => 'Registration Number',
        'mobile_label' => 'Mobile',
        'branch_label' => 'Branch',
        'village_council_label' => 'Village Council',
        'registration_date_label' => 'Registration Date',
        'page' => 'Page',
        'select_option' => '-- Select --'
    ],
    'ur' => [
        'title' => 'طالب علم کی رجسٹریشن رپورٹ',
        'institution_name' => 'الفاروقیہ ایجوکیشنل انسٹی ٹیوٹ',
        'select_class' => 'کلاس/کورس منتخب کریں:',
        'no_class_selected' => 'براہ کرم طلباء دیکھنے کے لیے ایک کلاس منتخب کریں۔',
        'no_records' => 'منتخب کردہ کلاس کے لیے کوئی طالب علم نہیں ملا۔',
        'print' => 'رپورٹ پرنٹ کریں',
        'sr_no' => 'نمبر',
        'name_label' => 'طالب علم کا نام',
        'father_name_label' => 'والد کا نام',
        'reg_no_label' => 'رجسٹریشن نمبر',
        'mobile_label' => 'موبائل',
        'branch_label' => 'برانچ',
        'village_council_label' => 'ویلیج کونسل',
        'registration_date_label' => 'رجسٹریشن کی تاریخ',
        'page' => 'صفحہ',
        'select_option' => '-- منتخب کریں --'
    ]
];

// Fetch classes for dropdown
$classes = [];
$class_result = $conn->query("
    SELECT c.id, c.title AS class_title, cr.title AS course_title 
    FROM classes c
    INNER JOIN courses cr ON cr.id = c.course_id
    ORDER BY c.id DESC
");
while ($row = $class_result->fetch_assoc()) {
    $classes[$row['id']] = $row['course_title'] . ' - ' . $row['class_title'];
}

// Fetch students for selected class
$students = [];
$selected_class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;

if ($selected_class_id > 0) {
    $result = $conn->query("
        SELECT 
            sr.*, 
            vc.title AS village_council,
            b.title AS branch_title,
            c.title AS class_title,
            co.title AS course_title,
            s.title AS session_title
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
        LEFT JOIN classes c ON sc.class_id = c.id
        LEFT JOIN courses co ON c.course_id = co.id
        LEFT JOIN sessions s ON sc.session_id = s.id
        WHERE sc.class_id = $selected_class_id
        ORDER BY sr.id DESC
    ");
    
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
}

// Get selected class name for header
$selected_class_name = $selected_class_id > 0 && isset($classes[$selected_class_id]) ? $classes[$selected_class_id] : '';
?>

<!DOCTYPE html>
<html lang="en" dir="<?php echo ($lang == 'ur') ? 'rtl' : 'ltr'; ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $translations[$lang]['title']; ?></title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Naskh+Arabic:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: <?php echo ($lang == 'ur') ? '"Noto Naskh Arabic", Arial, sans-serif' : 'Arial, sans-serif'; ?>;
            font-size: 12pt;
            color: #000;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
        }
        .report-header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #000;
        }
        .report-header h1 {
            font-size: 20pt;
            margin: 0;
        }
        .report-header h2 {
            font-size: 16pt;
            margin: 5px 0;
        }
        .report-header p {
            font-size: 12pt;
            margin: 2px 0;
        }
        .print-btn {
            margin-bottom: 20px;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            table-layout: fixed;
        }
        .table th, .table td {
            border: 1px solid #000;
            padding: 8px;
            word-wrap: break-word;
            overflow-wrap: break-word;
            font-size: 10pt;
            vertical-align: middle;
        }
        .table th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        .table td {
            max-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        /* Column widths */
        .table th:nth-child(1), .table td:nth-child(1) { width: 5%; } /* Sr No */
        .table th:nth-child(2), .table td:nth-child(2) { width: 10%; } /* Reg No (1) */
        .table th:nth-child(3), .table td:nth-child(3) { width: 10%; } /* Reg No (2) */
        .table th:nth-child(4), .table td:nth-child(4) { width: 15%; } /* Name */
        .table th:nth-child(5), .table td:nth-child(5) { width: 15%; } /* Father Name */
        .table th:nth-child(6), .table td:nth-child(6) { width: 15%; } /* Mobile */
        .table th:nth-child(7), .table td:nth-child(7) { width: 15%; } /* Branch */
        .table th:nth-child(8), .table td:nth-child(8) { width: 15%; } /* Village Council */
        .table th:nth-child(9), .table td:nth-child(9) { width: 10%; } /* Registration Date */
        .no-print {
            margin-bottom: 20px;
        }
        <?php if ($lang == 'ur'): ?>
        body, .form-control, .btn, .alert, .table th, .table td {
            text-align: right;
            direction: rtl;
        }
        <?php else: ?>
        .table th, .table td {
            text-align: left;
        }
        <?php endif; ?>
        @media print {
            @page {
                size: A4;
                margin: 20mm;
            }
            .no-print {
                display: none !important;
            }
            .container {
                width: 100%;
                max-width: none;
            }
            .table {
                page-break-inside: auto;
            }
            .table tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
            .report-header {
                position: running(header);
            }
            .footer {
                position: running(footer);
                text-align: center;
                font-size: 10pt;
                color: #555;
            }
            @page {
                @top-center {
                    content: element(header);
                }
                @bottom-center {
                    content: element(footer);
                }
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Language switcher -->
        <div class="text-right no-print">
            <a href="?lang=en<?php echo $selected_class_id ? '&class_id=' . $selected_class_id : ''; ?>" class="btn btn-xs btn-default <?php echo ($lang == 'en') ? 'active' : ''; ?>">English</a>
            <a href="?lang=ur<?php echo $selected_class_id ? '&class_id=' . $selected_class_id : ''; ?>" class="btn btn-xs btn-default <?php echo ($lang == 'ur') ? 'active' : ''; ?>">اردو</a>
        </div>

        <!-- Report Header -->
        <div class="report-header">
           <?php require_once('rep_header.php'); ?>
            <p><?php echo $translations[$lang]['class_label'] . ': ' . htmlspecialchars($selected_class_name); ?></p>
            <p><?php echo date('d M Y'); ?></p>
        </div>

        <!-- Class Selection -->
        <div class="form-group no-print">
            <label for="class_id"><?php echo $translations[$lang]['select_class']; ?></label>
            <form action="" method="get">
                <input type="hidden" name="lang" value="<?php echo $lang; ?>">
                <select class="form-control" id="class_id" name="class_id" onchange="this.form.submit()">
                    <option value=""><?php echo $translations[$lang]['select_option']; ?></option>
                    <?php foreach ($classes as $id => $name): ?>
                        <option value="<?php echo $id; ?>" <?php echo ($id == $selected_class_id) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <!-- Print Button -->
        <?php if ($selected_class_id > 0): ?>
            <button onclick="window.print()" class="btn btn-primary print-btn no-print"><?php echo $translations[$lang]['print']; ?></button>
        <?php endif; ?>

        <!-- Student List -->
        <?php if ($selected_class_id == 0): ?>
            <div class="alert alert-info"><?php echo $translations[$lang]['no_class_selected']; ?></div>
        <?php elseif (empty($students)): ?>
            <div class="alert alert-info"><?php echo $translations[$lang]['no_records']; ?></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th><?php echo $translations[$lang]['sr_no']; ?></th>
                            <th><?php echo $translations[$lang]['reg_no_label']; ?></th>
                            <th><?php echo $translations[$lang]['reg_no_label']; ?></th>
                            <th><?php echo $translations[$lang]['name_label']; ?></th>
                            <th><?php echo $translations[$lang]['father_name_label']; ?></th>
                            <th><?php echo $translations[$lang]['mobile_label']; ?></th>
                            <th><?php echo $translations[$lang]['branch_label']; ?></th>
                            <th><?php echo $translations[$lang]['village_council_label']; ?></th>
                            <th><?php echo $translations[$lang]['registration_date_label']; ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $count = 1;
                        foreach ($students as $row): 
                        ?>
                            <tr>
                                <td><?php echo $count++; ?></td>
                                <td><?php echo htmlspecialchars($row['reg_no']); ?></td>
                                <td><?php echo htmlspecialchars($row['reg_no']); ?></td>
                                <td><?php echo htmlspecialchars($row['name']); ?></td>
                                <td><?php echo htmlspecialchars($row['father_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['mobile']); ?></td>
                                <td><?php echo htmlspecialchars($row['branch_title']); ?></td>
                                <td><?php echo htmlspecialchars($row['village_council']); ?></td>
                                <td><?php echo htmlspecialchars($row['registration_date']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="footer">
            <?php echo $translations[$lang]['page']; ?> <span class="page-number"></span>
        </div>
    </div>

    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
    <script>
        // Update page numbers dynamically
        document.querySelectorAll('.page-number').forEach(function(element, index) {
            element.textContent = index + 1;
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>