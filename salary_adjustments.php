<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
require_once('security.php');
if (session_status() == PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

$show_results = false;
$selected_month = isset($_POST['month']) ? trim($_POST['month']) : date('Y-m');
$users_data = [];
$message = '';
$message_type = '';

// =========================================================================
// HANDLER 1 — Generate salaries (read-only preview)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = 'Invalid request!';
        $message_type = 'danger';
    } else {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $selected_month = trim($_POST['month']);

        if (empty($selected_month)) {
            $message = 'Month is required!';
            $message_type = 'danger';
        } else {
            $show_results = true;

            $start_date = "$selected_month-01";
            $end_date = date('Y-m-t', strtotime($start_date));

            // Count working days (Mon-Sat), excluding Sunday
            $total_working_days = 0;
            $dt = new DateTime($start_date);
            $end_dt = new DateTime($end_date);
            while ($dt <= $end_dt) {
                if ($dt->format('N') != 7) $total_working_days++;
                $dt->modify('+1 day');
            }

            // Fetch users with role
            $res = $conn->query("SELECT id, username, salary, role_id FROM users ORDER BY username");
            while ($row = $res->fetch_assoc()) {
                $user_id = (int)$row['id'];
                $base_salary = (float)$row['salary'];
                $role_id = (int)$row['role_id'];

                // Existing salary record
                $stmt = $conn->prepare("SELECT id, amount, deduction, remarks FROM user_salary WHERE user_id=? AND month=?");
                $stmt->bind_param("is", $user_id, $selected_month);
                $stmt->execute();
                $sal_res = $stmt->get_result();
                $exists = $sal_res->num_rows > 0;
                $salary_id = 0;
                $deduction = 0;
                $net = $base_salary;
                $remarks = '';

                if ($exists) {
                    $sal_row = $sal_res->fetch_assoc();
                    $salary_id = (int)$sal_row['id'];
                    $deduction = (float)$sal_row['deduction'];
                    $net = (float)$sal_row['amount'];
                    $remarks = $sal_row['remarks'];
                }
                $stmt->close();

                // Attendance
                $presents = 0;
                $leaves = 0;
                $absents = 0;
                $recorded_days = 0;

                $stmt = $conn->prepare("SELECT status, COUNT(*) as count FROM user_attendance WHERE user_id=? AND DATE_FORMAT(dated, '%Y-%m') = ? GROUP BY status");
                $stmt->bind_param("is", $user_id, $selected_month);
                $stmt->execute();
                $att_res = $stmt->get_result();
                while ($att_row = $att_res->fetch_assoc()) {
                    if ($att_row['status'] === 'P') {
                        $presents = (int)$att_row['count'];
                        $recorded_days += (int)$att_row['count'];
                    }
                    if ($att_row['status'] === 'L') {
                        $leaves = (int)$att_row['count'];
                        $recorded_days += (int)$att_row['count'];
                    }
                }
                $stmt->close();

                $absents = $total_working_days - $recorded_days;
                if ($absents < 0) $absents = 0;

                if (!$exists) {
                    $deduction = 0;
                    $net = $base_salary;
                }

                $users_data[] = [
                    'id' => $user_id,
                    'salary_id' => $salary_id,
                    'username' => $row['username'],
                    'role_id' => $role_id,
                    'presents' => $presents,
                    'absents' => $absents,
                    'leaves' => $leaves,
                    'total_working_days' => $total_working_days,
                    'base_salary' => $base_salary,
                    'deduction' => $deduction,
                    'net' => $net,
                    'remarks' => $remarks,
                    'exists' => $exists
                ];
            }
        }
    }
}

// =========================================================================
// HANDLER 2 — Save new / Update existing salary (AJAX)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && ($_POST['action'] === 'save_user' || $_POST['action'] === 'update_user')) {

    header('Content-Type: application/json');

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid request!']);
        exit;
    }

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $user_id = intval($_POST['user_id']);
    $month = trim($_POST['month']);
    $deduction = floatval($_POST['deduction'] ?? 0);
    $net_salary = floatval($_POST['net_salary'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');
    $amount = $net_salary;
    $salary_id = isset($_POST['salary_id']) ? intval($_POST['salary_id']) : 0;

    // Fetch user info + role
    $user_stmt = $conn->prepare("SELECT id, username, salary, role_id FROM users WHERE id = ? LIMIT 1");
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_info = $user_stmt->get_result()->fetch_assoc();
    $user_stmt->close();

    if (!$user_info) {
        echo json_encode(['success' => false, 'message' => 'User not found!']);
        exit;
    }

    $base_salary = (float)$user_info['salary'];
    $role_id = (int)$user_info['role_id'];

    if ($deduction < 0 || $net_salary < 0 || $deduction > $base_salary || $net_salary > $base_salary) {
        echo json_encode(['success' => false, 'message' => 'Values must be between 0 and base salary (' . number_format($base_salary, 2) . ')!']);
        exit;
    }

    if (empty($month) || empty($user_id)) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields!']);
        exit;
    }

    // Resolve GL accounts
    $salaryAcc = gl_salary_account_for_role($conn, $role_id);
    if ($salaryAcc <= 0) {
        error_log("salary: no GL mapping for role_id=$role_id (user_id=$user_id, username={$user_info['username']})");
        echo json_encode([
            'success' => false,
            'message' => "Cannot process salary: user '{$user_info['username']}' has role_id=$role_id which is not mapped to a salary account. Update role_gl_map first."
        ]);
        exit;
    }

    $cashAcc = gl_get_account_by_code($conn, '1010');
    if (!$cashAcc) {
        echo json_encode(['success' => false, 'message' => 'GL cash account 1010 not found!']);
        exit;
    }
    $cashAccId = (int)$cashAcc['id'];

    $transaction_started = false;

    try {
        $conn->begin_transaction();
        $transaction_started = true;

        // Fetch session id (single active session)
        $sessionRow = $conn->query("SELECT id FROM sessions WHERE status = 0 ORDER BY id DESC LIMIT 1")->fetch_assoc();
        $sessionId = $sessionRow ? (int)$sessionRow['id'] : 1;

        $effective_salary_id = 0;

        if ($_POST['action'] === 'update_user') {
            // ---- UPDATE PATH ----
            if ($salary_id <= 0) {
                throw new Exception("Invalid salary_id ($salary_id) for update.");
            }

            // Ensure the row exists
            $checkStmt = $conn->prepare("SELECT id FROM user_salary WHERE id = ? LIMIT 1");
            $checkStmt->bind_param("i", $salary_id);
            $checkStmt->execute();
            $checkRow = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();

            if (!$checkRow) {
                throw new Exception("Salary record #$salary_id not found.");
            }

            // Reverse any existing GL entry for this salary
            $origGlStmt = $conn->prepare("
                SELECT id FROM gl_transactions
                WHERE reference_type = 'salary' AND reference_id = ?
                  AND status = 'POSTED' AND reversal_of IS NULL
                ORDER BY id ASC LIMIT 1
            ");
            $origGlStmt->bind_param("i", $salary_id);
            $origGlStmt->execute();
            $origGlTxn = $origGlStmt->get_result()->fetch_assoc();
            $origGlStmt->close();

            if ($origGlTxn) {
                $revErr = null;
                $postedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
                $revId = reverse_journal_entry($conn, (int)$origGlTxn['id'],
                                              "Salary #$salary_id updated (month $month)",
                                              $postedBy, $revErr);
                if ($revId <= 0) {
                    throw new Exception("Failed to reverse original salary GL entry: " . ($revErr ?? 'unknown'));
                }
            }

            // Update the row
            $stmt = $conn->prepare("UPDATE user_salary SET amount=?, deduction=?, remarks=? WHERE id=?");
            $stmt->bind_param("ddsi", $amount, $deduction, $remarks, $salary_id);
            if (!$stmt->execute()) {
                throw new Exception("Update user_salary failed: " . $stmt->error);
            }
            $stmt->close();

            $effective_salary_id = $salary_id;
        } else {
            // ---- INSERT PATH ----
            $stmt = $conn->prepare("INSERT INTO user_salary (user_id, month, amount, deduction, remarks) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("isdds", $user_id, $month, $amount, $deduction, $remarks);
            if (!$stmt->execute()) {
                throw new Exception("Insert user_salary failed: " . $stmt->error);
            }
            $effective_salary_id = (int)$conn->insert_id;
            $stmt->close();

            if ($effective_salary_id <= 0) {
                throw new Exception("Failed to obtain new user_salary id after INSERT.");
            }
        }

        // Post GL entry (skip if amount is 0)
        if ($amount > 0) {
            if ($effective_salary_id <= 0) {
                throw new Exception("Invalid effective_salary_id ($effective_salary_id) — refusing to post GL.");
            }

            $pay_date = date('Y-m-t', strtotime($month . '-01'));
            $postedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;

            $glErr = null;
            $txnId = post_journal_entry($conn, [
                'entry_date'  => $pay_date,
                'session_id'  => $sessionId,
                'description' => "Salary — {$user_info['username']} for $month (net " . number_format($amount, 2) . ")",
                'ref_type'    => 'salary',
                'ref_id'      => $effective_salary_id,
                'posted_by'   => $postedBy,
                'idempotency' => 'salary-' . $effective_salary_id,
                'lines'       => [
                    ['account_id' => $salaryAcc, 'debit'  => $amount, 'credit' => 0,
                     'memo'       => "Salary expense — {$user_info['username']} ($month)"],
                    ['account_id' => $cashAccId, 'debit'  => 0, 'credit' => $amount,
                     'memo'       => "Cash paid — {$user_info['username']} ($month)"],
                ],
            ], $glErr);

            if ($txnId <= 0) {
                throw new Exception("GL post failed: " . ($glErr ?? 'unknown'));
            }
        }

        $conn->commit();
        $transaction_started = false;

        echo json_encode([
            'success' => true,
            'message' => 'Salary ' . ($_POST['action'] === 'update_user' ? 'updated' : 'saved') . ' successfully!'
        ]);
        exit;

    } catch (Exception $e) {
        if ($transaction_started) $conn->rollback();
        error_log("Salary error (User ID: $user_id, Month: $month): " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit;
    }
}

// =========================================================================
// HANDLER 3 — Edit salary via modal (AJAX, reversal method)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_salary') {

    header('Content-Type: application/json');

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid request!']);
        exit;
    }

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $salary_id = intval($_POST['salary_id']);
    $user_id = intval($_POST['user_id']);
    $month = trim($_POST['month']);
    $new_deduction = floatval($_POST['new_deduction'] ?? 0);
    $new_net_salary = floatval($_POST['new_net_salary'] ?? 0);
    $new_remarks = trim($_POST['new_remarks'] ?? '');

    if ($salary_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid salary id.']);
        exit;
    }

    // Fetch user + role
    $user_stmt = $conn->prepare("SELECT id, username, salary, role_id FROM users WHERE id = ? LIMIT 1");
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_info = $user_stmt->get_result()->fetch_assoc();
    $user_stmt->close();

    if (!$user_info) {
        echo json_encode(['success' => false, 'message' => 'User not found!']);
        exit;
    }

    $base_salary = (float)$user_info['salary'];
    $role_id = (int)$user_info['role_id'];

    if ($new_deduction < 0 || $new_net_salary < 0 || $new_deduction > $base_salary || $new_net_salary > $base_salary) {
        echo json_encode(['success' => false, 'message' => 'Values must be between 0 and base salary (' . number_format($base_salary, 2) . ')!']);
        exit;
    }

    $salaryAcc = gl_salary_account_for_role($conn, $role_id);
    if ($salaryAcc <= 0) {
        echo json_encode(['success' => false, 'message' => "Role_id=$role_id is not mapped to a salary account."]);
        exit;
    }

    $cashAcc = gl_get_account_by_code($conn, '1010');
    if (!$cashAcc) {
        echo json_encode(['success' => false, 'message' => 'GL cash account 1010 not found!']);
        exit;
    }
    $cashAccId = (int)$cashAcc['id'];

    $transaction_started = false;

    try {
        $conn->begin_transaction();
        $transaction_started = true;

        // Fetch original salary record
        $orig_query = $conn->prepare("SELECT id, user_id, month, amount, deduction, remarks FROM user_salary WHERE id = ? FOR UPDATE");
        $orig_query->bind_param("i", $salary_id);
        $orig_query->execute();
        $orig_result = $orig_query->get_result();

        if ($orig_result->num_rows === 0) throw new Exception("Salary record not found.");
        $original = $orig_result->fetch_assoc();
        $orig_query->close();

        $old_net_salary = (float)$original['amount'];
        $old_deduction = (float)$original['deduction'];
        $old_remarks = $original['remarks'];

        // Reverse existing GL entry
        $origGlStmt = $conn->prepare("
            SELECT id FROM gl_transactions
            WHERE reference_type = 'salary' AND reference_id = ?
              AND status = 'POSTED' AND reversal_of IS NULL
            ORDER BY id ASC LIMIT 1
        ");
        $origGlStmt->bind_param("i", $salary_id);
        $origGlStmt->execute();
        $origGlTxn = $origGlStmt->get_result()->fetch_assoc();
        $origGlStmt->close();

        if ($origGlTxn) {
            $revErr = null;
            $postedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
            $revId = reverse_journal_entry($conn, (int)$origGlTxn['id'],
                                          "Salary #$salary_id edited (month $month)",
                                          $postedBy, $revErr);
            if ($revId <= 0) {
                throw new Exception("Failed to reverse original salary GL entry: " . ($revErr ?? 'unknown'));
            }
        }

        // Build new remarks with audit trail
        $timestamp = date('Y-m-d H:i');
        $editor = $_SESSION['username'] ?? 'Admin';
        $edit_note = "[Edited {$timestamp} by {$editor}: Old Net={$original['amount']}, Old Ded={$original['deduction']}] ";

        $final_remarks = $new_remarks;
        if (!empty($old_remarks)) {
            $final_remarks = $edit_note . $old_remarks;
            if (!empty($new_remarks) && $new_remarks !== $old_remarks) {
                $final_remarks .= " | " . $new_remarks;
            }
        } elseif (!empty($new_remarks)) {
            $final_remarks = $edit_note . $new_remarks;
        } else {
            $final_remarks = $edit_note . '(No remarks)';
        }

        // Update the row
        $update_salary = $conn->prepare("UPDATE user_salary SET amount=?, deduction=?, remarks=? WHERE id=?");
        $update_salary->bind_param("ddsi", $new_net_salary, $new_deduction, $final_remarks, $salary_id);
        if (!$update_salary->execute()) throw new Exception("Failed to update user_salary: " . $update_salary->error);
        $update_salary->close();

        // Post new GL entry
        if ($new_net_salary > 0) {
            $pay_date = date('Y-m-t', strtotime($month . '-01'));
            $sessionRow = $conn->query("SELECT id FROM sessions WHERE status = 0 ORDER BY id DESC LIMIT 1")->fetch_assoc();
            $sessionId = $sessionRow ? (int)$sessionRow['id'] : 1;
            $postedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;

            $glErr = null;
            $txnId = post_journal_entry($conn, [
                'entry_date'  => $pay_date,
                'session_id'  => $sessionId,
                'description' => "Salary (edited) — {$user_info['username']} for $month (net " . number_format($new_net_salary, 2) . ")",
                'ref_type'    => 'salary',
                'ref_id'      => $salary_id,
                'posted_by'   => $postedBy,
                'idempotency' => 'salary_edit-' . $salary_id . '-' . time(),
                'lines'       => [
                    ['account_id' => $salaryAcc, 'debit'  => $new_net_salary, 'credit' => 0,
                     'memo'       => "Salary (edited) — {$user_info['username']} ($month)"],
                    ['account_id' => $cashAccId, 'debit'  => 0, 'credit' => $new_net_salary,
                     'memo'       => "Cash paid (edited) — {$user_info['username']} ($month)"],
                ],
            ], $glErr);

            if ($txnId <= 0) {
                throw new Exception("GL post failed: " . ($glErr ?? 'unknown'));
            }
        }

        $conn->commit();
        $transaction_started = false;

        echo json_encode([
            'success' => true,
            'message' => 'Salary edited successfully! Old: ' . number_format($old_net_salary, 2) .
                        ' → New: ' . number_format($new_net_salary, 2) .
                        ' (Deduction: ' . number_format($old_deduction, 2) . ' → ' . number_format($new_deduction, 2) . ')'
        ]);
        exit;

    } catch (Exception $e) {
        if ($transaction_started) $conn->rollback();
        error_log("Salary edit error (Salary ID: $salary_id): " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Teacher Salary Management</title>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
    .form-control{ font-size: 1rem !important; height: 45px !important; }
    .form-group label{ font-size: 1rem !important; }
    
    @media print {
        @page { margin: 0; size: 80mm auto; }
        body { margin: 0; padding: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; font-family: 'Helvetica Neue', 'Arial', sans-serif; font-size: 15px; width: 80mm; background-color: #ffffff; color: #000000; line-height: 1.4; }
        body * { visibility: hidden; }
        #printContainer, #printContainer * { visibility: visible; font-weight: bold !important; }
        #printContainer { display: block !important; position: absolute !important; left: 0 !important; top: 0 !important; width: 80mm !important; margin: 0 !important; padding: 10px !important; background-color: #ffffff !important; color: #000000 !important; line-height: 1.4 !important; border: 3px solid #000000 !important; border-radius: 4px !important; box-sizing: border-box !important; }
        .no-print { display: none !important; }
        .salary-slip { width: 100%; padding: 0; margin: 0; page-break-after: always; }
        .salary-slip:last-child { page-break-after: auto; }
        .single-slip { page-break-after: auto !important; }
        .school-logo-section { text-align: center; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 3px double #000000; }
        .school-logo { max-width: 60mm; max-height: 25mm; margin: 0 auto 5px auto; display: block; }
        .school-name { font-size: 18px; font-weight: 800; color: #000000; text-transform: uppercase; letter-spacing: 1px; margin: 5px 0; text-decoration: underline; }
        .receipt-title { font-size: 19px; font-weight: 900; text-transform: uppercase; margin: 12px 0; text-align: center; color: #000000; text-decoration: underline; background-color: #f0f0f0; padding: 3px; border: 1px solid #000000; }
        .receipt-info { margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid #000000; }
        .info-row { display: flex; justify-content: space-between; margin-bottom: 6px; padding: 0 4px; font-size: 14px; font-weight: 600; }
        .info-label { font-weight: 700; flex: 1; color: #000000; }
        .info-value { text-align: right; flex: 1; color: #000000; font-weight: 600; }
        .amount-section { margin: 12px 0; padding: 10px 8px; background: #f0f0f0; border: 2px solid #000000; border-radius: 4px; }
        .amount-row { display: flex; justify-content: space-between; margin-bottom: 6px; padding: 3px; font-size: 14px; font-weight: 600; }
        .amount-label, .amount-value { font-weight: 700; }
        .amount-value { text-align: right; }
        .amount-positive { color: #000000; font-weight: 800; }
        .amount-negative { color: #000000; font-weight: 800; }
        .attendance-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 5px; margin: 12px 0; padding: 8px; background: #f0f0f0; border: 2px solid #000000; border-radius: 4px; text-align: center; font-weight: bold !important; }
        .attendance-item { border: 1px solid #000000; padding: 5px; font-weight: 700; }
        .attendance-item div:first-child { font-size: 12px; margin-bottom: 3px; }
        .attendance-item div:last-child { font-size: 16px; font-weight: 800; }
        .receipt-footer { text-align: center; margin-top: 15px; padding-top: 8px; border-top: 3px double #000000; font-weight: bold !important; }
        .footer-text { font-size: 12px; color: #000000; margin: 3px 0; font-weight: 600; }
        .thank-you { font-weight: 800; font-size: 14px; color: #000000; margin-bottom: 5px; text-decoration: underline; }
        .divider { height: 2px; background: #000000; margin: 10px 0; }
        .highlight { background: #e0e0e0; padding: 4px 6px; border-radius: 4px; border: 1px solid #000000; font-weight: 800; }
        .working-days-info { text-align: center; background: #e9ecef; padding: 3px; border-radius: 4px; font-size: 11px; margin-top: 5px; font-weight: 600; }
    }

    .attendance-badges { display: flex; gap: 10px; margin-top: 8px; justify-content: center; }
    .attendance-badge { padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; color: white; }
    .badge-present { background: #28a745; }
    .badge-absent { background: #dc3545; }
    .badge-leave { background: #ffc107; color: #000; }
    .alert-fixed { position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; }
    .working-days-info { text-align: center; background: #e9ecef; padding: 3px; border-radius: 4px; font-size: 11px; margin-top: 5px; }
    #printContainer { display: none; }
    .remarks-section { margin-top: 15px; }
    .deduction-warning { border-color: #dc3545 !important; box-shadow: 0 0 5px rgba(220, 53, 69, 0.3) !important; }
    .print-all-btn { margin-bottom: 20px; }

    @media (max-width: 991px) {
        .form-group { margin-bottom: 15px; }
        .attendance-badges { flex-wrap: wrap; justify-content: flex-start; }
        .attendance-badge { font-size: 10px; padding: 3px 6px; }
        .working-days-info { font-size: 10px; }
        .panel-heading h3 { font-size: 18px; }
        .btn { width: 100%; margin-bottom: 10px; }
        .text-right { text-align: center !important; }
        .print-all-btn { text-align: center; }
    }
    @media (max-width: 767px) {
        label { font-size: 1.2rem !important; }
        .form-control { font-size: 0.9rem !important; height: 40px !important; }
        .panel-title { font-size: 16px; }
        .attendance-badges { flex-direction: column; align-items: center; gap: 5px; }
        .attendance-badge { width: 100%; text-align: center; }
        .working-days-info { font-size: 9px; }
        .remarks-section textarea { height: 100px; }
        .alert-fixed { top: 10px; right: 10px; left: 10px; min-width: auto; width: calc(100% - 20px); }
    }
    @media (max-width: 480px) {
        label { font-size: 1rem !important; }
        .form-control { font-size: 0.85rem !important; height: 35px !important; }
        .panel { margin-bottom: 20px; }
        .btn { font-size: 14px; }
        .attendance-badge { font-size: 9px; }
    }
</style>
</head>

<body>
<?php require_once('navbar.php'); ?>

<div class="container">
<div class="row">
<div class="col-md-12">

<div class="panel panel-primary">
<div class="panel-heading">
<h3 class="panel-title">Generate Teacher Salaries</h3>
</div>
<div class="panel-body">
<?php if (!empty($message)): ?>
<div class="alert alert-<?php echo $message_type; ?>">
<?php echo $message; ?>
</div>
<?php endif; ?>

<form method="post" action="">
<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
<input type="hidden" name="action" value="generate">

<div class="form-group">
    <label>Select Month:</label>
    <input type="month" class="form-control" name="month" value="<?php echo $selected_month; ?>" required>
</div>

<div class="text-right">
    <button type="submit" class="btn btn-success">Generate Salaries</button>
</div>
</form>
</div>
</div>

<?php if ($show_results && !empty($users_data)): ?>
<?php
$has_saved_salaries = false;
foreach ($users_data as $user) {
    if ($user['exists']) {
        $has_saved_salaries = true;
        break;
    }
}
?>
<?php if ($has_saved_salaries): ?>
<div class="text-right no-print print-all-btn">
    <button type="button" class="btn btn-warning btn-lg" onclick="printAllSalaries()">
        <i class="fas fa-print"></i> Print All Salaries
    </button>
</div>
<?php endif; ?>

<?php foreach ($users_data as $user): ?>
<div class="panel panel-info">
<div class="panel-heading">
<h3 class="panel-title">Salary for <?php echo htmlspecialchars($user['username']); ?> - <?php echo date('F Y', strtotime($selected_month)); ?></h3>
</div>
<div class="panel-body">
<form method="post" action="" id="form_<?php echo $user['id']; ?>" class="salary-form">
<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
<input type="hidden" name="action" value="<?php echo $user['exists'] ? 'update_user' : 'save_user'; ?>">
<input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
<input type="hidden" name="month" value="<?php echo $selected_month; ?>">
<input type="hidden" name="salary_id" value="<?php echo $user['salary_id']; ?>">

<div class="row">
    <div class="col-md-6">
        <div class="form-group">
            <label>Teacher Name:</label>
            <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" readonly>
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            <label>Month:</label>
            <input type="text" class="form-control" value="<?php echo date('F Y', strtotime($selected_month)); ?>" readonly>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="form-group">
            <label>Base Salary :</label>
            <input type="number" step="0.01" class="form-control base_salary" id="base_salary_<?php echo $user['id']; ?>" 
                   value="<?php echo $user['base_salary']; ?>" readonly>
            
            <div class="attendance-badges">
                <span class="attendance-badge badge-present">Present: <?php echo $user['presents']; ?></span>
                <span class="attendance-badge badge-absent">Absent: <?php echo $user['absents']; ?></span>
                <span class="attendance-badge badge-leave">Leave: <?php echo $user['leaves']; ?></span>
            </div>
            <div class="working-days-info">
                Working Days (Mon-Sat): <?php echo $user['total_working_days']; ?> | 
                Recorded Days: <?php echo ($user['presents'] + $user['leaves']); ?>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            <label>Deduction :</label>
            <input type="number" step="0.01" class="form-control deduction" id="deduction_<?php echo $user['id']; ?>" 
                   name="deduction" value="<?php echo $user['deduction']; ?>" required>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            <label>Net Salary :</label>
            <input type="number" step="0.01" class="form-control net_salary" id="net_salary_<?php echo $user['id']; ?>" 
                   name="net_salary" value="<?php echo $user['net']; ?>" required>
        </div>
    </div>
</div>

<div class="form-group remarks-section">
    <label>Remarks:</label>
    <textarea class="form-control remarks_input" id="remarks_<?php echo $user['id']; ?>" 
              name="remarks" rows="3" placeholder="Enter remarks here..."><?php echo htmlspecialchars($user['remarks']); ?></textarea>
    <?php if (!empty($user['remarks'])): ?>
    <small class="text-muted">Existing remarks from database</small>
    <?php endif; ?>
</div>

<div class="text-right no-print">
    <button type="submit" class="btn btn-success">
        <?php echo $user['exists'] ? 'Update Salary' : 'Save Salary'; ?>
    </button>
    <?php if ($user['exists']): ?>
        <button type="button" class="btn btn-info" onclick="printSalary(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')">
            <i class="fas fa-print"></i> Print Salary Slip
        </button>
        <button type="button" class="btn btn-warning" onclick="openEditSalaryModal(
            <?php echo $user['salary_id']; ?>,
            <?php echo $user['id']; ?>,
            '<?php echo $selected_month; ?>',
            <?php echo $user['base_salary']; ?>,
            <?php echo $user['deduction']; ?>,
            <?php echo $user['net']; ?>,
            '<?php echo htmlspecialchars(addslashes($user['remarks'])); ?>'
        )">
            <i class="fas fa-edit"></i> Edit Salary
        </button>
    <?php endif; ?>
</div>
</form>
</div>
</div>
<?php endforeach; ?>
<?php elseif ($show_results): ?>
<div class="alert alert-info">No teachers found.</div>
<?php endif; ?>

</div>
</div>
</div>

<div class="modal fade" id="editSalaryModal" tabindex="-1" role="dialog" aria-labelledby="editSalaryModalLabel">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header" style="background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%); color: white;">
        <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="color: white;">
          <span aria-hidden="true">&times;</span>
        </button>
        <h4 class="modal-title" id="editSalaryModalLabel">
          <i class="fas fa-edit"></i> Edit Salary (Reversal Method)
        </h4>
      </div>
      <div class="modal-body">
        <input type="hidden" id="edit_salary_id" name="salary_id">
        <input type="hidden" id="edit_user_id" name="user_id">
        <input type="hidden" id="edit_month" name="month">
        <input type="hidden" id="edit_base_salary">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="action" value="edit_salary">
        
        <div class="alert alert-warning">
          <i class="fas fa-exclamation-triangle"></i> 
          <strong>Reversal Method:</strong> This will reverse the original salary entry and create a new one.
        </div>
        
        <div class="row">
          <div class="col-md-6">
            <div class="form-group">
              <label>Original Net Salary:</label>
              <input type="text" id="edit_original_net" class="form-control" readonly disabled>
            </div>
          </div>
          <div class="col-md-6">
            <div class="form-group">
              <label>Original Deduction:</label>
              <input type="text" id="edit_original_deduction" class="form-control" readonly disabled>
            </div>
          </div>
        </div>
        
        <div class="row">
          <div class="col-md-6">
            <div class="form-group">
              <label>New Net Salary *</label>
              <input type="number" step="0.01" id="edit_new_net" name="new_net_salary" class="form-control" required>
            </div>
          </div>
          <div class="col-md-6">
            <div class="form-group">
              <label>New Deduction *</label>
              <input type="number" step="0.01" id="edit_new_deduction" name="new_deduction" class="form-control" required>
            </div>
          </div>
        </div>
        
        <div class="form-group">
          <label>New Remarks:</label>
          <textarea id="edit_new_remarks" name="new_remarks" class="form-control" rows="3"></textarea>
        </div>
        
        <div class="alert alert-info">
          <strong>Accounting Impact:</strong><br>
          Original: <span id="edit_orig_display">0.00</span> → Reversed<br>
          New: <span id="edit_new_display">0.00</span> → Added<br>
          Net change: <span id="edit_net_display">0.00</span> <span id="edit_account_hint"></span>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-warning" onclick="submitEditSalaryForm()">
          <i class="fas fa-sync-alt"></i> Reverse & Update
        </button>
      </div>
    </div>
  </div>
</div>

<div id="printContainer" class="receipt-container"></div>

<script>
function openEditSalaryModal(salaryId, userId, month, baseSalary, deduction, netSalary, remarks) {
    $('#edit_salary_id').val(salaryId);
    $('#edit_user_id').val(userId);
    $('#edit_month').val(month);
    $('#edit_base_salary').val(baseSalary);
    $('#edit_original_net').val(parseFloat(netSalary).toFixed(2));
    $('#edit_original_deduction').val(parseFloat(deduction).toFixed(2));
    $('#edit_new_net').val(parseFloat(netSalary).toFixed(2));
    $('#edit_new_deduction').val(parseFloat(deduction).toFixed(2));
    $('#edit_new_remarks').val(remarks);
    
    var currentToken = $('input[name="csrf_token"]').first().val();
    $('#editSalaryModal input[name="csrf_token"]').val(currentToken);
    
    updateEditSalaryDisplay();
    $('#editSalaryModal').modal('show');
}

function updateEditSalaryDisplay() {
    var origNet = parseFloat($('#edit_original_net').val()) || 0;
    var newNet = parseFloat($('#edit_new_net').val()) || 0;
    
    $('#edit_orig_display').text(origNet.toFixed(2));
    $('#edit_new_display').text(newNet.toFixed(2));
    
    var netChange = newNet - origNet;
    var netSpan = $('#edit_net_display');
    netSpan.text(netChange.toFixed(2));
    
    if (netChange > 0) {
        netSpan.removeClass('text-danger').addClass('text-success');
    } else if (netChange < 0) {
        netSpan.removeClass('text-success').addClass('text-danger');
    } else {
        netSpan.removeClass('text-danger text-success');
    }
}

function submitEditSalaryForm() {
    var salaryId = $('#edit_salary_id').val();
    var userId = $('#edit_user_id').val();
    var month = $('#edit_month').val();
    var baseSalary = parseFloat($('#edit_base_salary').val()) || 0;
    var newNet = parseFloat($('#edit_new_net').val()) || 0;
    var newDeduction = parseFloat($('#edit_new_deduction').val()) || 0;
    var newRemarks = $('#edit_new_remarks').val();
    var csrfToken = $('#editSalaryModal input[name="csrf_token"]').val();
    
    if (newNet < 0 || newDeduction < 0 || newDeduction > baseSalary || newNet > baseSalary) {
        showAlert('Values must be between 0 and base salary (' + baseSalary.toFixed(2) + ')!', 'danger');
        return false;
    }
    
    var origNet = parseFloat($('#edit_original_net').val()) || 0;
    var origDeduction = parseFloat($('#edit_original_deduction').val()) || 0;
    
    if (newNet !== origNet || newDeduction !== origDeduction) {
        var confirmMsg = 'This will REVERSE the original salary entry and create a new one.\n\n';
        confirmMsg += 'Original Net: ' + origNet.toFixed(2) + ' | Deduction: ' + origDeduction.toFixed(2) + '\n';
        confirmMsg += 'New Net: ' + newNet.toFixed(2) + ' | Deduction: ' + newDeduction.toFixed(2) + '\n\n';
        confirmMsg += 'Continue?';
        if (!confirm(confirmMsg)) return false;
    }
    
    var submitBtn = $('#editSalaryModal').find('.btn-warning');
    submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Processing...');
    
    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: {
            action: 'edit_salary',
            salary_id: salaryId,
            user_id: userId,
            month: month,
            new_net_salary: newNet,
            new_deduction: newDeduction,
            new_remarks: newRemarks,
            csrf_token: csrfToken
        },
        dataType: 'json',
        timeout: 30000,
        success: function(response) {
            if (response.success) {
                showAlert(response.message, 'success');
                $('#editSalaryModal').modal('hide');
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                showAlert(response.message, 'danger');
                submitBtn.prop('disabled', false).html('<i class="fas fa-sync-alt"></i> Reverse & Update');
            }
        },
        error: function(xhr) {
            var errorMsg = 'An error occurred. Please try again.';
            if (xhr.responseText) {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.message) errorMsg = resp.message;
                } catch(e) { errorMsg += ' Server: ' + xhr.responseText.substring(0, 200); }
            }
            showAlert(errorMsg, 'danger');
            submitBtn.prop('disabled', false).html('<i class="fas fa-sync-alt"></i> Reverse & Update');
        }
    });
    return false;
}

function printSalary(userId, username) {
    var baseSalary = $('#base_salary_' + userId).val();
    var deduction = $('#deduction_' + userId).val();
    var netSalary = $('#net_salary_' + userId).val();
    var remarks = $('#remarks_' + userId).val();
    
    var presents = $('#base_salary_' + userId).closest('.row').find('.attendance-badges .badge-present').text().replace('Present: ', '');
    var absents = $('#base_salary_' + userId).closest('.row').find('.attendance-badges .badge-absent').text().replace('Absent: ', '');
    var leaves = $('#base_salary_' + userId).closest('.row').find('.attendance-badges .badge-leave').text().replace('Leave: ', '');
    var workingDaysText = $('#base_salary_' + userId).closest('.row').find('.working-days-info').text();
    var workingDays = workingDaysText.split('|')[0].replace('Working Days (Mon-Sat): ', '').trim();
    
    var printContent = `
        <div class="salary-slip single-slip">
            <div class="school-logo-section">
                <img src="logo2.png" alt="Dar-e-Arqm School Logo" class="school-logo" onerror="this.style.display='none'">
                <div class="school-name">DAR-E-ARQM SCHOOL</div>
            </div>
            <div class="receipt-title">TEACHER SALARY SLIP</div>
            <div class="divider"></div>
            <div class="receipt-info">
                <div class="info-row"><span class="info-label">Teacher Name:</span><span class="info-value">${username}</span></div>
                <div class="info-row"><span class="info-label">Month:</span><span class="info-value"><?php echo date('F Y', strtotime($selected_month)); ?></span></div>
                <div class="info-row"><span class="info-label">Date:</span><span class="info-value"><?php echo date('d/m/Y'); ?></span></div>
            </div>
            <div class="divider"></div>
            <div class="amount-section">
                <div class="amount-row"><span class="amount-label">Base Salary:</span><span class="amount-value amount-positive">${parseFloat(baseSalary).toFixed(2)}</span></div>
                <div class="amount-row"><span class="amount-label">Deduction:</span><span class="amount-value amount-negative">- ${parseFloat(deduction).toFixed(2)}</span></div>
                <div class="divider"></div>
                <div class="amount-row highlight"><span class="amount-label">NET SALARY:</span><span class="amount-value amount-positive">${parseFloat(netSalary).toFixed(2)}</span></div>
            </div>
            <div class="divider"></div>
            <div class="attendance-grid">
                <div class="attendance-item"><div>PRESENT</div><div>${presents}</div></div>
                <div class="attendance-item"><div>ABSENT</div><div>${absents}</div></div>
                <div class="attendance-item"><div>LEAVE</div><div>${leaves}</div></div>
            </div>
            <div class="working-days-info">Working Days (Mon-Sat): ${workingDays} | Recorded Days: ${parseInt(presents) + parseInt(leaves)}</div>
            <div class="divider"></div>
            <div class="receipt-info"><div class="info-row"><span class="info-label">Remarks:</span><span class="info-value">${remarks || 'No remarks'}</span></div></div>
            <div class="receipt-footer">
                <div class="thank-you">THANK YOU FOR YOUR DEDICATION</div>
                <div class="footer-text">Generated on: <?php echo date('d/m/Y H:i A'); ?></div>
                <div class="footer-text">Served by: <?php echo $_SESSION['username'] ?? 'Admin'; ?></div>
                <div class="footer-text">_________________________</div>
                <div class="footer-text">Principal's Signature</div>
                <div class="footer-text">✉️ contact@darearqm.edu.in</div>
            </div>
        </div>
    `;
    
    $('#printContainer').html(printContent).show();
    window.print();
    setTimeout(function() { $('#printContainer').hide(); }, 100);
}

function printAllSalaries() {
    var printContent = '';
    <?php foreach ($users_data as $user): ?>
    <?php if ($user['exists']): ?>
    {
        var username = '<?php echo htmlspecialchars($user['username']); ?>';
        var baseSalary = '<?php echo $user['base_salary']; ?>';
        var deduction = '<?php echo $user['deduction']; ?>';
        var netSalary = '<?php echo $user['net']; ?>';
        var remarks = '<?php echo htmlspecialchars($user['remarks']); ?>';
        var presents = <?php echo $user['presents']; ?>;
        var absents = <?php echo $user['absents']; ?>;
        var leaves = <?php echo $user['leaves']; ?>;
        var workingDays = <?php echo $user['total_working_days']; ?>;
        
        printContent += `
            <div class="salary-slip">
                <div class="school-logo-section">
                    <img src="logo2.png" alt="Dar-e-Arqm School Logo" class="school-logo" onerror="this.style.display='none'">
                    <div class="school-name">DAR-E-ARQM SCHOOL</div>
                </div>
                <div class="receipt-title">TEACHER SALARY SLIP</div>
                <div class="divider"></div>
                <div class="receipt-info">
                    <div class="info-row"><span class="info-label">Teacher Name:</span><span class="info-value">${username}</span></div>
                    <div class="info-row"><span class="info-label">Month:</span><span class="info-value"><?php echo date('F Y', strtotime($selected_month)); ?></span></div>
                    <div class="info-row"><span class="info-label">Date:</span><span class="info-value"><?php echo date('d/m/Y'); ?></span></div>
                </div>
                <div class="divider"></div>
                <div class="amount-section">
                    <div class="amount-row"><span class="amount-label">Base Salary:</span><span class="amount-value amount-positive">${parseFloat(baseSalary).toFixed(2)}</span></div>
                    <div class="amount-row"><span class="amount-label">Deduction:</span><span class="amount-value amount-negative">- ${parseFloat(deduction).toFixed(2)}</span></div>
                    <div class="divider"></div>
                    <div class="amount-row highlight"><span class="amount-label">NET SALARY:</span><span class="amount-value amount-positive">${parseFloat(netSalary).toFixed(2)}</span></div>
                </div>
                <div class="divider"></div>
                <div class="attendance-grid">
                    <div class="attendance-item"><div>PRESENT</div><div>${presents}</div></div>
                    <div class="attendance-item"><div>ABSENT</div><div>${absents}</div></div>
                    <div class="attendance-item"><div>LEAVE</div><div>${leaves}</div></div>
                </div>
                <div class="working-days-info">Working Days (Mon-Sat): ${workingDays} | Recorded Days: ${parseInt(presents) + parseInt(leaves)}</div>
                <div class="divider"></div>
                <div class="receipt-info"><div class="info-row"><span class="info-label">Remarks:</span><span class="info-value">${remarks || 'No remarks'}</span></div></div>
                <div class="receipt-footer">
                    <div class="thank-you">THANK YOU FOR YOUR DEDICATION</div>
                    <div class="footer-text">Generated on: <?php echo date('d/m/Y H:i A'); ?></div>
                    <div class="footer-text">Served by: <?php echo $_SESSION['username'] ?? 'Admin'; ?></div>
                    <div class="footer-text">_________________________</div>
                    <div class="footer-text">Principal's Signature</div>
                    <div class="footer-text">✉️ contact@darearqm.edu.in</div>
                </div>
            </div>
        `;
    }
    <?php endif; ?>
    <?php endforeach; ?>
    
    $('#printContainer').html(printContent).show();
    window.print();
    setTimeout(function() { $('#printContainer').hide(); }, 100);
}

function showAlert(message, type) {
    $('.alert-fixed').remove();
    var alert = $('<div class="alert alert-' + type + ' alert-fixed alert-dismissible"><button type="button" class="close" data-dismiss="alert">&times;</button>' + message + '</div>');
    $('body').append(alert);
    setTimeout(function() { alert.fadeOut(function() { $(this).remove(); }); }, 5000);
}

$(document).ready(function() {
    $('.deduction').on('input', function() {
        var userId = $(this).attr('id').replace('deduction_', '');
        updateNet(userId);
    });
    $('.net_salary').on('input', function() {
        var userId = $(this).attr('id').replace('net_salary_', '');
        updateDeduction(userId);
    });
    
    $('#edit_new_deduction').on('input', function() {
        var baseSalary = parseFloat($('#edit_base_salary').val()) || 0;
        var deduction = parseFloat($(this).val()) || 0;
        if (deduction > baseSalary) {
            deduction = baseSalary;
            $(this).val(deduction.toFixed(2));
        }
        var net = baseSalary - deduction;
        $('#edit_new_net').val(net.toFixed(2));
        updateEditSalaryDisplay();
    });
    
    $('#edit_new_net').on('input', function() {
        var baseSalary = parseFloat($('#edit_base_salary').val()) || 0;
        var net = parseFloat($(this).val()) || 0;
        if (net < 0) { net = 0; $(this).val('0.00'); }
        if (net > baseSalary) { net = baseSalary; $(this).val(net.toFixed(2)); }
        var deduction = baseSalary - net;
        $('#edit_new_deduction').val(deduction.toFixed(2));
        updateEditSalaryDisplay();
    });
    
    $('.salary-form').on('submit', function(e) {
        e.preventDefault();
        var form = $(this);
        var button = form.find('button[type="submit"]');
        var originalText = button.text();
        button.prop('disabled', true).text('Saving...');
        
        $.ajax({
            url: '',
            type: 'POST',
            data: form.serialize(),
            dataType: 'json',
            timeout: 30000,
            success: function(response) {
                if (response.success) {
                    showAlert(response.message, 'success');
                    if (form.find('input[name="action"]').val() === 'save_user') {
                        form.find('input[name="action"]').val('update_user');
                        button.text('Update Salary');
                        if (!form.find('.btn-info').length) {
                            var userId = form.find('input[name="user_id"]').val();
                            var username = form.find('input[type="text"]').first().val();
                            button.after(' <button type="button" class="btn btn-info" onclick="printSalary(' + userId + ', \'' + username + '\')"><i class="fas fa-print"></i> Print Salary Slip</button>');
                        }
                    }
                    if (!$('.print-all-btn').length) location.reload();
                } else {
                    showAlert(response.message, 'danger');
                }
            },
            error: function(xhr) {
                var errorMsg = 'An error occurred while saving.';
                if (xhr.responseText) {
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        if (resp.message) errorMsg = resp.message;
                    } catch(e) { errorMsg += ' Server: ' + xhr.responseText.substring(0, 200); }
                }
                showAlert(errorMsg, 'danger');
            },
            complete: function() { button.prop('disabled', false).text(originalText); }
        });
    });
    
    $('#editSalaryModal').on('hidden.bs.modal', function () {
        $('#editSalaryModal').find('.btn-warning').prop('disabled', false).html('<i class="fas fa-sync-alt"></i> Reverse & Update');
    });
});

function updateNet(userId) {
    var base = parseFloat($('#base_salary_' + userId).val()) || 0;
    var deduction = parseFloat($('#deduction_' + userId).val()) || 0;
    if (deduction > base) { deduction = base; $('#deduction_' + userId).val(deduction.toFixed(2)); }
    var net = base - deduction;
    $('#net_salary_' + userId).val(net.toFixed(2));
}

function updateDeduction(userId) {
    var base = parseFloat($('#base_salary_' + userId).val()) || 0;
    var net = parseFloat($('#net_salary_' + userId).val()) || 0;
    var deduction = base - net;
    if (deduction > base) { deduction = base; $('#net_salary_' + userId).val('0.00'); }
    $('#deduction_' + userId).val(deduction.toFixed(2));
}
</script>

</body>
</html>

<?php $conn->close(); ?>