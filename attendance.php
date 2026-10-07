<?php

require_once('conn_inc.php');



// Handle AJAX requests first

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Set JSON header only for AJAX responses

    if (isset($_POST['reg_no']) || isset($_POST['reset_student_id'])) {

        header('Content-Type: application/json');

    }

    

    $date = isset($_POST['date']) ? $_POST['date'] : date('Y-m-d');

    

    if (isset($_POST['reg_no'])) {

        $reg_no = trim($_POST['reg_no']);

        

        // Use prepared statement to prevent SQL injection

        $stmt = $conn->prepare("SELECT id FROM student_registration WHERE reg_no=?");

        $stmt->bind_param("s", $reg_no);

        $stmt->execute();

        $result = $stmt->get_result();

        $student = $result->fetch_assoc();

        

        if ($student) {

            $student_id = $student['id'];

            

            // Use prepared statement for insert/update

            $stmt2 = $conn->prepare("

                INSERT INTO attendance (student_id, date, status) 

                VALUES (?, ?, 'P')

                ON DUPLICATE KEY UPDATE status='P', remarks=NULL

            ");

            $stmt2->bind_param("is", $student_id, $date);

            $stmt2->execute();

            

            echo json_encode(['success' => true, 'message' => "Marked Present for $reg_no"]);

        } else {

            echo json_encode(['success' => false, 'message' => "No student found with Reg No: $reg_no"]);

        }

        exit;

    }

    

    // Handle reset (delete row → back to Absent)

    if (isset($_POST['reset_student_id'])) {

        $student_id = intval($_POST['reset_student_id']);

        

        // Use prepared statement

        $stmt = $conn->prepare("DELETE FROM attendance WHERE student_id=? AND date=?");

        $stmt->bind_param("is", $student_id, $date);

        

        if ($stmt->execute()) {

            echo json_encode(['success'=>true,'message'=>'Reset to Absent']);

        } else {

            echo json_encode(['success'=>false,'message'=>'Database error: '.$conn->error]);

        }

        exit;

    }

}



// Regular page request - fetch and display data

$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

$status_filter = isset($_GET['status']) ? $_GET['status'] : 'P'; // Default tab = Present



// Fetch class+sections with students filtered by status

$classes = $conn->query("

    SELECT cs.id as cs_id, c.title as class_name, s.title as section_name

    FROM class_sections cs

    JOIN classes c ON c.id = cs.class_id

    JOIN sections s ON s.id = cs.section_id

    ORDER BY c.title, s.title

");



$class_sections = [];

while ($row = $classes->fetch_assoc()) {

    $cs_id = $row['cs_id'];

    

    // Use prepared statement for student query

    $stmt = $conn->prepare("

        SELECT sc.student_registration_id as student_id, sr.reg_no, sr.name,

               COALESCE(a.status,'A') as status, a.remarks

        FROM student_class sc

        JOIN student_registration sr ON sr.id = sc.student_registration_id

        LEFT JOIN attendance a ON a.student_id = sr.id AND a.date=?

        WHERE sc.class_id = ?

        HAVING status=?

        ORDER BY sr.reg_no

    ");

    $stmt->bind_param("sis", $date, $cs_id, $status_filter);

    $stmt->execute();

    $students = $stmt->get_result();

    

    if ($students->num_rows > 0) {

        $class_sections[$cs_id] = [

            'title' => $row['class_name'] . " - " . $row['section_name'],

            'students' => $students

        ];

    }

}

?>



<!DOCTYPE html>

<html lang="en">

<head>

    <title>Attendance Management</title>

    <meta charset="utf-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap 3.4.1 CSS & JS -->

    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>

    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>

    <link rel="stylesheet" href="css/mystyle.css" />

    <style>

        .student-card { margin-bottom: 10px; padding: 10px; border-radius: 5px; color: #000; border: 1px solid #ddd; }

        .present { background-color: #dff0d8; }

        .absent { background-color: #f2dede; }

        .leave { background-color: #fcf8e3; }

        .search-feedback { margin-top: 5px; font-size: 14px; }

        .nav-tabs > li.active > a, .nav-tabs > li.active > a:hover, .nav-tabs > li.active > a:focus {

            background-color: #337ab7;

            color: #fff;

            border-color: #337ab7;

        }

        .nav-tabs > li > a {

            color: #337ab7;

        }

        .nav-tabs > li > a:hover {

            background-color: #e0e0e0;

        }

    </style>

</head>

<body>



<?php require_once('navbar.php'); ?>



<!-- Dashboard -->

<div class="container">

    <div class="row">

        <div class="col-md-12 ">

            <!-- Panel for Search Bar -->

            <div class="panel panel-primary">

                <div class="panel-heading">

                    <h3 class="panel-title">Attendance Management</h3>

                </div>

                <div class="panel-body">

                    <!-- Search Bar -->

                    <div class="form-group">

                        <input type="text" id="reg_no_search" class="form-control input-lg" placeholder="Enter Student Reg No and press Enter" autofocus>

                        <div id="search_feedback" class="search-feedback"></div>

                    </div>

                </div>

            </div>



            <!-- Panel for Tabs and Classes -->

            <div class="panel panel-info">

                <div class="panel-heading">

                    <ul class="nav nav-tabs gap-3">

                        <li class="<?=($status_filter=='P'?'active':'')?> text-white">

                            <a href="?date=<?=$date?>&status=P" class="text-white ">Present</a>

                        </li>

                        <li class="<?=($status_filter=='L'?'active':'')?> text-white">

                            <a href="?date=<?=$date?>&status=L" class="text-white">Leave</a>

                        </li>

                        <li class="<?=($status_filter=='A'?'active':'')?> text-white">

                            <a href="?date=<?=$date?>&status=A" class="text-white">Absent</a>

                        </li>

                    </ul>

                </div>

                <div class="panel-body">

                    <!-- Classes Grid -->

                    <div class="row">

                        <?php foreach($class_sections as $cs_id => $cs): ?>

                            <div class="col-md-6">

                                <div class="panel panel-default">

                                    <div class="panel-heading text-white"><?=$cs['title']?></div>

                                    <div class="panel-body">

                                        <div class="row">

                                            <?php while($stu = $cs['students']->fetch_assoc()): 

                                                $status_class = $stu['status']=='P' ? 'present' : ($stu['status']=='L' ? 'leave' : 'absent');

                                            ?>

                                            <div class="col-md-6 student-card <?=$status_class?>" 

                                                 data-student-id="<?=$stu['student_id']?>" 

                                                 data-reg-no="<?=$stu['reg_no']?>">

                                                <strong><?=$stu['reg_no']?></strong><br>

                                                <?=$stu['name']?><br>

                                                Status: <?=$stu['status']?>

                                                <?php if($stu['status']=='L'): ?>

                                                    <div><small>Remarks: <?=$stu['remarks']?></small></div>

                                                <?php endif; ?>

                                                <div class="mt-1">

                                                    <?php if ($status_filter != 'P'): ?>

                                                        <button class="btn btn-success btn-sm mark-present">

                                                            <span class="glyphicon glyphicon-ok"></span> Present

                                                        </button>

                                                    <?php endif; ?>

                                                    <?php if ($status_filter != 'L'): ?>

                                                        <button class="btn btn-light btn-sm mark-leave">

                                                            <span class="glyphicon glyphicon-exclamation-sign"></span> Leave

                                                        </button>

                                                    <?php endif; ?>

                                                    <?php if ($stu['status'] != 'A'): ?>

                                                        <button class="btn btn-danger btn-sm mark-absent">

                                                            <span class="glyphicon glyphicon-remove"></span> Reset

                                                        </button>

                                                    <?php endif; ?>

                                                </div>

                                            </div>

                                            <?php endwhile; ?>

                                        </div>

                                    </div>

                                </div>

                            </div>

                        <?php endforeach; ?>

                        <?php if(empty($class_sections)) echo "<div class='col-md-12'><div class='alert alert-info'>No students found for this status.</div></div>"; ?>

                    </div>

                </div>

            </div>

        </div>

    </div>



    <!-- Leave Modal -->

    <div class="modal fade" id="leaveModal" tabindex="-1">

        <div class="modal-dialog">

            <div class="modal-content">

                <div class="modal-header">

                    <button type="button" class="close" data-dismiss="modal">&times;</button>

                    <h4 class="modal-title">Leave Remarks</h4>

                </div>

                <div class="modal-body">

                    <input type="hidden" id="leave_student_id">

                    <textarea id="leave_remarks" class="form-control" placeholder="Enter reason for leave"></textarea>

                </div>

                <div class="modal-footer">

                    <button type="button" class="btn btn-primary" id="saveLeave">Save</button>

                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>

                </div>

            </div>

        </div>

    </div>

</div>



<script src="js/mobile_menu.js"></script>

<script>

$(document).ready(function(){



    // ✅ Smart focus handler

    function focusSearchBox() {

        if (!$('#leaveModal').hasClass('show')) {

            $('#reg_no_search').focus();

        }

    }



    // Initial focus

    focusSearchBox();



    // Re-focus if user clicks away, but not during Leave modal

    $(document).on('focusout', '#reg_no_search', function(){

        setTimeout(() => { focusSearchBox(); }, 200);

    });



    // Show feedback message

    function showFeedback(message, isSuccess) {

        $('#search_feedback').html(message)

            .removeClass('text-success text-danger')

            .addClass(isSuccess ? 'text-success' : 'text-danger');

        setTimeout(() => $('#search_feedback').html(''), 3000);

    }



    // Mark Present via grid button

    $('.mark-present').click(function(){

        let studentCard = $(this).closest('.student-card');

        let reg_no = studentCard.data('reg-no');

        

        $.post('', {reg_no: reg_no, date:'<?=$date?>'}, function(response){

            try {

                let res = typeof response === 'string' ? JSON.parse(response) : response;

                if (res.success) {

                    showFeedback(res.message, true);

                    setTimeout(() => location.reload(), 100);

                } else {

                    showFeedback(res.message, false);

                }

            } catch (e) {

                console.error('Error parsing response:', e, 'Response:', response);

                showFeedback('Error processing request: ' + e.message, false);

            }

        }, 'json').fail(function(xhr, status, error) {

            showFeedback('AJAX error: ' + error, false);

        });

    });

    

    // Reset back to Absent

    $('.mark-absent').click(function(){

        let studentCard = $(this).closest('.student-card');

        let student_id = studentCard.data('student-id');



        $.post('', {reset_student_id: student_id, date:'<?=$date?>'}, function(response){

            try {

                let res = typeof response === 'string' ? JSON.parse(response) : response;

                if (res.success) {

                    showFeedback(res.message, true);

                    setTimeout(() => location.reload(), 100);

                } else {

                    showFeedback(res.message, false);

                }

            } catch (e) {

                console.error('Error parsing response:', e, 'Response:', response);

                showFeedback('Error processing request: ' + e.message, false);

            }

        }, 'json').fail(function(xhr, status, error) {

            showFeedback('AJAX error: ' + error, false);

        });

    });



    // Mark Leave button opens modal

    $('.mark-leave').click(function(){

        let studentCard = $(this).closest('.student-card');

        let student_id = studentCard.data('student-id');

        $('#leave_student_id').val(student_id);

        $('#leave_remarks').val('');

        $('#leaveModal').modal('show');

    });



    // ✅ Focus remarks when modal is shown

    $('#leaveModal').on('shown.bs.modal', function () {

        $('#leave_remarks').focus();

    });



    // ✅ Return focus to scanner when modal closes

    $('#leaveModal').on('hidden.bs.modal', function () {

        focusSearchBox();

    });



    // Save leave with remarks

    $('#saveLeave').click(function(){

        let student_id = $('#leave_student_id').val();

        let remarks = $('#leave_remarks').val().trim();

        if (!remarks) {

            alert('Please enter a reason for leave');

            return;

        }

        let $button = $(this);

        $button.prop('disabled', true);

        

        $.post('leave.php', {student_id: student_id, date: '<?=$date?>', remarks: remarks}, function(response) {

            if (response.success) {

                showFeedback(response.message, true);

                $('#leaveModal').modal('hide');

                setTimeout(() => location.reload(), 100);

            } else {

                showFeedback(response.message, false);

            }

        }, 'json').fail(function(xhr, status, error) {

            showFeedback('AJAX error: ' + error, false);

        }).always(function() {

            $button.prop('disabled', false);

        });

    });



    // 🔥 Scanner input (no Enter key needed)

    let scanTimer = null;

    $('#reg_no_search').on('input', function(){

        clearTimeout(scanTimer);

        let reg_no = $(this).val().trim();



        scanTimer = setTimeout(function(){

            if (reg_no) {

                $('#reg_no_search').val('');

                showFeedback('Processing...', true);



                $.post('', {reg_no: reg_no, date:'<?=$date?>'}, function(response){

                    try {

                        let res = typeof response === 'string' ? JSON.parse(response) : response;

                        if (res.success) {

                            showFeedback(res.message, true);

                            location.reload(); // Auto reload immediately

                        } else {

                            showFeedback(res.message, false);

                        }

                    } catch (e) {

                        console.error('Error parsing response:', e, 'Response:', response);

                        showFeedback('Error: ' + e.message, false);

                    }

                }, 'json').fail(function(xhr, status, error) {

                    showFeedback('AJAX error: ' + error, false);

                });

            }

        }, 300); // 300ms pause = scan finished

    });

});

</script>



</body>

</html>

<?php $conn->close(); ?>