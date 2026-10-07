<?php
ob_start();
// ===========================
// user_attendance.php
// ===========================
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once('conn_inc.php');

// ---------------------------
// AJAX POST handler
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    try {
        $date = isset($_POST['date']) ? $_POST['date'] : date('Y-m-d');

        if (isset($_POST['present_user_id'])) {
            $user_id = intval($_POST['present_user_id']);
            if (!$user_id) throw new Exception("Invalid user ID");

            $stmt = $conn->prepare("UPDATE user_attendance SET status='P', remarks=NULL WHERE user_id=? AND dated=?");
            $stmt->bind_param("is", $user_id, $date);
            $stmt->execute();

            if ($stmt->affected_rows === 0) {
                $stmt2 = $conn->prepare("INSERT INTO user_attendance (user_id, dated, status) VALUES (?, ?, 'P')");
                $stmt2->bind_param("is", $user_id, $date);
                $stmt2->execute();
            }

            echo json_encode(['success' => true, 'message' => "Marked Present"]);
            exit;
        }

        if (isset($_POST['username'])) {
            $username = trim($_POST['username']);
            if (!$username) throw new Exception("Username is required");

            $stmt = $conn->prepare("SELECT id FROM users WHERE username=?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            if (!$user) {
                echo json_encode(['success' => false, 'message' => "No user found with username $username"]);
                exit;
            }

            $user_id = intval($user['id']);

            // CHECK IF ALREADY MARKED PRESENT TODAY
            $check_stmt = $conn->prepare("SELECT status FROM user_attendance WHERE user_id=? AND dated=?");
            $check_stmt->bind_param("is", $user_id, $date);
            $check_stmt->execute();
            $existing = $check_stmt->get_result()->fetch_assoc();

            if ($existing && $existing['status'] === 'P') {
                echo json_encode(['success' => true, 'message' => "Already marked Present for $username"]);
                exit;
            }

            // If not present (or other status), mark as Present
            $stmt2 = $conn->prepare("UPDATE user_attendance SET status='P', remarks=NULL WHERE user_id=? AND dated=?");
            $stmt2->bind_param("is", $user_id, $date);
            $stmt2->execute();

            if ($stmt2->affected_rows === 0) {
                $stmt3 = $conn->prepare("INSERT INTO user_attendance (user_id, dated, status) VALUES (?, ?, 'P')");
                $stmt3->bind_param("is", $user_id, $date);
                $stmt3->execute();
            }

            echo json_encode(['success' => true, 'message' => "Marked Present for $username"]);
            exit;
        }

        if (isset($_POST['reset_user_id'])) {
            $user_id = intval($_POST['reset_user_id']);
            if (!$user_id) throw new Exception("Invalid user ID");

            $stmt = $conn->prepare("DELETE FROM user_attendance WHERE user_id=? AND dated=?");
            $stmt->bind_param("is", $user_id, $date);
            $stmt->execute();

            echo json_encode(['success' => true, 'message' => "Reset to Absent"]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => "Invalid request"]);
        exit;

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit;
    }
}

// ---------------------------
// Regular page request
// ---------------------------
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'A';

// Fetch attendance summary counts for the selected date
$count_stmt = $conn->prepare("
    SELECT 
        SUM(CASE WHEN COALESCE(ua.status, 'A') = 'P' THEN 1 ELSE 0 END) AS present_count,
        SUM(CASE WHEN COALESCE(ua.status, 'A') = 'L' THEN 1 ELSE 0 END) AS leave_count,
        SUM(CASE WHEN COALESCE(ua.status, 'A') = 'A' THEN 1 ELSE 0 END) AS absent_count
    FROM users u
    LEFT JOIN user_attendance ua ON ua.user_id = u.id AND ua.dated = ?
");
$count_stmt->bind_param("s", $date);
$count_stmt->execute();
$counts = $count_stmt->get_result()->fetch_assoc();

$present_count = $counts['present_count'] ?? 0;
$leave_count   = $counts['leave_count'] ?? 0;
$absent_count  = $counts['absent_count'] ?? 0;

// If user requested Absent tab but there are zero absents → redirect to Present tab
if ($status_filter === 'A' && $absent_count == 0) {
    header("Location: ?date=$date&status=P");
    exit;
}

// Now fetch users for the current filter
$stmt = $conn->prepare("
    SELECT u.id AS user_id, u.username,
           COALESCE(ua.status, 'A') AS status, ua.remarks
    FROM users u
    LEFT JOIN user_attendance ua ON ua.user_id = u.id AND ua.dated = ?
    WHERE COALESCE(ua.status, 'A') = ?
    ORDER BY u.username
");
$stmt->bind_param("ss", $date, $status_filter);
$stmt->execute();
$users = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Teacher Attendance Management</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- jQuery 2.2.4 (compatible with Bootstrap 3) -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/2.2.4/jquery.min.js"></script>

    <!-- Bootstrap 3.4.1 JS -->
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>

    <link rel="stylesheet" href="css/mystyle.css" />
    <style>
        /* FORCE REMOVE ANY HORIZONTAL LINE UNDER TABS */
        .nav-tabs {
            border-bottom: none !important;
            margin-bottom: 20px;
        }
        .nav-tabs > li > a {
            border-bottom: none !important;

            border: 1px solid transparent;
            border-radius: 6px 6px 0 0;
            color: white;
            font-weight: 600;
            padding: 12px 25px;
            transition: all 0.3s ease;
            margin-bottom: 0 !important; /* Prevent any gap/line */
        }
        .nav-tabs > li > a:hover {
            background-color: #f5f5f5;
            border-color: #ddd #ddd transparent;
            color: #337ab7;
        }
        .nav-tabs > li.active > a,
        .nav-tabs > li.active > a:hover,
        .nav-tabs > li.active > a:focus {
            background-color: #337ab7 !important;
            color: #fff !important;
            border: 1px solid #337ab7;
            border-bottom-color: transparent !important;
        }

        /* Ensure no line from panel-heading or other elements */
        .panel-heading {
            border-bottom: none !important;
        }

        /* Attendance Counter */
        .attendance-summary {
            text-align: center;
            margin-bottom: 20px;
            font-size: 18px;
            font-weight: bold;
            color: #333;
        }
        .attendance-summary span {
            display: inline-block;
            margin: 0 20px;
        }
        .count-present { color: #3c763d; }
        .count-leave   { color: #8a6d3b; }
        .count-absent  { color: #a94442; }

        /* User Cards Grid */
        .user-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            justify-content: flex-start;
        }
        .user-card {
            flex: 1 1 calc(33.333% - 15px);
            min-width: 280px;
            max-width: calc(33.333% - 15px);
            box-sizing: border-box;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #ddd;
            background-color: #fff;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .user-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.1);
        }

        /* Status indicators - light background */
        .present { background-color: #1ddf202e; }
        .absent { background-color:  #e41e1b1f; }
        .leave { background-color: #eaa22738; }

        .search-feedback {
            margin-top: 8px;
            font-size: 14px;
            min-height: 20px;
        }

        /* Responsive */
        @media (max-width: 992px) {
            .user-card {
                flex: 1 1 calc(50% - 15px);
                max-width: calc(50% - 15px);
            }
        }
        @media (max-width: 768px) {
            .user-card {
                flex: 1 1 100%;
                max-width: 100%;
            }
            .nav-tabs > li > a {
                padding: 10px 15px;
                font-size: 14px;
            }
            .attendance-summary span {
                display: block;
                margin: 10px 0;
            }
        }
    </style>
</head>
<body>

    <?php require_once('navbar.php'); ?>

    <div class="container">
        <div class="row">
            <div class="col-md-12">
                <!-- Search Panel -->
                <div class="panel panel-primary">
                    <div class="panel-heading">
                        <h3 class="panel-title">Teacher Attendance Management</h3>
                    </div>
                    <div class="panel-body">
                        <div class="form-group">
                            <label for="attendance_date">Select Date:</label>
                            <input type="date" id="attendance_date" class="form-control" value="<?= htmlspecialchars($date) ?>">
                        </div>
                        <div class="form-group">
                            <input type="text" id="username_search" class="form-control input-lg"
                                placeholder="Scan or type username">
                            <div id="search_feedback" class="search-feedback"></div>
                        </div>
                         <div class="attendance-summary">
                            <span class="count-present">Present: <?= $present_count ?></span>
                            <span class="count-leave">Leave: <?= $leave_count ?></span>
                            <span class="count-absent">Absent: <?= $absent_count ?></span>
                        </div>
                    </div>
                </div>

                <!-- Tabs and Users Panel -->
                <div class="panel panel-info">
                    <div class="panel-heading">
                        <!-- Attendance Summary Counter -->
                       

                        <ul class="nav nav-tabs">
                            <li class="<?= ($status_filter == 'P' ? 'active' : '') ?>">
                                <a href="?date=<?= $date ?>&status=P">Present</a>
                            </li>
                            <li class="<?= ($status_filter == 'L' ? 'active' : '') ?>">
                                <a href="?date=<?= $date ?>&status=L">Leave</a>
                            </li>
                            <li class="<?= ($status_filter == 'A' ? 'active' : '') ?>">
                                <a href="?date=<?= $date ?>&status=A">Absent</a>
                            </li>
                        </ul>
                    </div>
                    <div class="panel-body">
                        <div class="user-grid">
                            <?php while ($user = $users->fetch_assoc()):
                                $status_class = $user['status'] == 'P' ? 'present' : ($user['status'] == 'L' ? 'leave' : 'absent');
                            ?>
                                <div class="user-card <?= $status_class ?>"
                                     data-user-id="<?= $user['user_id'] ?>"
                                     data-username="<?= htmlspecialchars($user['username']) ?>">
                                    <strong style="font-size: 1.2em;"><?= htmlspecialchars($user['username']) ?></strong><br>
                                    <span style="font-size: 0.9em; color: #555;">Status: <strong><?= $user['status'] ?></strong></span>
                                    <?php if ($user['status'] == 'L' && $user['remarks']): ?>
                                        <div style="margin-top: 8px;"><small><em>Remarks: <?= htmlspecialchars($user['remarks']) ?></em></small></div>
                                    <?php endif; ?>
                                    <div style="margin-top: 12px;">
                                        <?php if ($status_filter != 'P'): ?>
                                            <button class="btn btn-success btn-sm mark-present" style="margin-right: 5px; margin-bottom: 5px;">
                                                <span class="glyphicon glyphicon-ok"></span> Present
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($status_filter != 'L'): ?>
                                            <button class="btn btn-warning btn-sm mark-leave" style="margin-right: 5px; margin-bottom: 5px;">
                                                <span class="glyphicon glyphicon-exclamation-sign"></span> Leave
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($user['status'] != 'A'): ?>
                                            <button class="btn btn-danger btn-sm mark-absent" style="margin-bottom: 5px;">
                                                <span class="glyphicon glyphicon-remove"></span> Reset
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                            <?php if ($users->num_rows == 0): ?>
                                <div style="width: 100%; text-align: center; padding: 20px;">
                                    <div class="alert alert-info">No teachers found for this status.</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Leave Modal -->
        <div class="modal" id="leaveModal" tabindex="-1" role="dialog" aria-labelledby="leaveModalLabel" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        <h4 class="modal-title" id="leaveModalLabel">Leave Remarks</h4>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="leave_user_id">
                        <textarea id="leave_remarks" class="form-control" rows="4" placeholder="Enter reason for leave"></textarea>
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
    $(document).ready(function() {

        $('#attendance_date').change(function() {
            let newDate = $(this).val();
            window.location.href = '?date=' + newDate + '&status=<?= $status_filter ?>';
        });

        function focusSearchBox() {
            if (!$('#leaveModal').hasClass('in')) {
                $('#username_search').focus();
            }
        }
        focusSearchBox();

        function showFeedback(message, success) {
            $('#search_feedback')
                .html(message)
                .removeClass('text-success text-danger')
                .addClass(success ? 'text-success' : 'text-danger');
            setTimeout(() => $('#search_feedback').html(''), 3000);
        }

        $('.mark-present').click(function() {
            let user_id = $(this).closest('.user-card').data('user-id');
            $.post('', { present_user_id: user_id, date: '<?= $date ?>' })
                .done(function(res) {
                    if (res.success) {
                        showFeedback(res.message, true);
                        setTimeout(() => location.reload(), 100);
                    } else {
                        showFeedback(res.message, false);
                    }
                })
                .fail(() => showFeedback('Request failed', false));
        });

        $('.mark-absent').click(function() {
            let user_id = $(this).closest('.user-card').data('user-id');
            $.post('', { reset_user_id: user_id, date: '<?= $date ?>' })
                .done(function(res) {
                    if (res.success) {
                        showFeedback(res.message, true);
                        setTimeout(() => location.reload(), 100);
                    } else {
                        showFeedback(res.message, false);
                    }
                })
                .fail(() => showFeedback('Request failed', false));
        });

        $('.mark-leave').click(function() {
            let user_id = $(this).closest('.user-card').data('user-id');
            $('#leave_user_id').val(user_id);
            $('#leave_remarks').val('');
            $('#leaveModal').modal('show');
        });

        $('#leaveModal').on('shown.bs.modal', function() {
            $('#leave_remarks').focus();
        });

        $('#leaveModal').on('hidden.bs.modal', function() {
            focusSearchBox();
        });

        $('#saveLeave').click(function() {
            let user_id = $('#leave_user_id').val();
            let remarks = $('#leave_remarks').val().trim();
            if (!remarks) {
                alert('Please enter a reason for leave');
                return;
            }
            $(this).prop('disabled', true);

            $.post('leave_user.php', {
                user_id: user_id,
                date: '<?= $date ?>',
                remarks: remarks
            })
            .done(function(res) {
                if (res.success) {
                    showFeedback(res.message, true);
                    $('#leaveModal').modal('hide');
                    setTimeout(() => location.reload(), 100);
                } else {
                    showFeedback(res.message || 'Failed to save leave', false);
                }
            })
            .fail(() => showFeedback('Request failed', false))
            .always(() => $(this).prop('disabled', false));
        });

        let scanTimer = null;
        $('#username_search').on('input', function() {
            clearTimeout(scanTimer);
            let username = $(this).val().trim();

            scanTimer = setTimeout(function() {
                if (username) {
                    $('#username_search').val('');
                    showFeedback('Processing...', true);

                    $.post('', { username: username, date: '<?= $date ?>' })
                        .done(function(res) {
                            if (res.success) {
                                showFeedback(res.message, true);
                                location.reload();
                            } else {
                                showFeedback(res.message, false);
                            }
                        })
                        .fail(() => showFeedback('Request failed', false));
                }
            }, 900);
        });
    });
    </script>
</body>
</html>

<?php $conn->close(); ?>