<?php
ob_start();
require_once('security.php');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

// Fetch sessions and classes
$sessions = [];
$result = $conn->query("SELECT id, title FROM sessions WHERE status = 0 ORDER BY id DESC");
while ($row = $result->fetch_assoc()) {
    $sessions[$row['id']] = $row['title'];
}
$result->free();

$classes = [];
$result = $conn->query("SELECT id, title FROM classes WHERE status IS NULL ORDER BY title");
while ($row = $result->fetch_assoc()) {
    $classes[$row['id']] = $row['title'];
}
$result->free();

// Handle edit/delete actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action_type'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['message'] = 'Invalid request!';
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $action_type = $_POST['action_type'];

    if ($action_type === 'edit_fee') {
        $fee_card_id = intval($_POST['fee_card_id']);
        $new_amount = floatval($_POST['amount']);
        $remarks = $_POST['remarks'];

        // Fetch old card data BEFORE update (for ledger comparison)
        $old_stmt = $conn->prepare("SELECT id, total_amount, fee_type_id, session_id, due_date, student_class_id FROM student_fee_card WHERE id = ? LIMIT 1");
        $old_stmt->bind_param("i", $fee_card_id);
        $old_stmt->execute();
        $old_card = $old_stmt->get_result()->fetch_assoc();
        $old_stmt->close();

        $stmt = $conn->prepare("UPDATE student_fee_card SET total_amount = ?, remarks = ? WHERE id = ?");
        $stmt->bind_param("dsi", $new_amount, $remarks, $fee_card_id);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = 'Fee amount updated successfully!';
            $_SESSION['message_type'] = 'success';

            // === GL: If amount changed, reverse old and post new ===
            if ($old_card && (float)$old_card['total_amount'] !== (float)$new_amount) {
                $old_amount = (float)$old_card['total_amount'];
                $fee_type_id = (int)$old_card['fee_type_id'];
                $session_id = (int)$old_card['session_id'];
                $due_date = $old_card['due_date'];

                // 1. Reverse the original creation entry
                if ($old_amount > 0) {
                    $origStmt = $conn->prepare("
                        SELECT id FROM gl_transactions
                        WHERE reference_type = 'fee_card' AND reference_id = ?
                          AND status = 'POSTED' AND reversal_of IS NULL
                        ORDER BY id ASC LIMIT 1
                    ");
                    $origStmt->bind_param("i", $fee_card_id);
                    $origStmt->execute();
                    $origTxn = $origStmt->get_result()->fetch_assoc();
                    $origStmt->close();

                    if ($origTxn) {
                        $revErr = null;
                        $postedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
                        $revId = reverse_journal_entry(
                            $conn, (int)$origTxn['id'],
                            "Fee card #$fee_card_id edited: $old_amount → $new_amount",
                            $postedBy, $revErr
                        );
                        if ($revId <= 0) {
                            error_log("edit_fee: reversal failed for card #$fee_card_id — $revErr");
                        }
                    }
                }

                // 2. Post new entry at new amount
                if ($new_amount > 0) {
                    $revenueAcc = gl_revenue_account_for_fee_type($conn, $fee_type_id);
                    $receivableAcc = gl_receivable_account($conn);

                    if ($revenueAcc > 0 && $receivableAcc > 0) {
                        $glErr = null;
                        $postedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
                        $txnId = post_journal_entry($conn, [
                            'entry_date'  => $due_date,
                            'session_id'  => $session_id,
                            'description' => "Fee card #$fee_card_id edited to $new_amount",
                            'ref_type'    => 'fee_card',
                            'ref_id'      => $fee_card_id,
                            'posted_by'   => $postedBy,
                            'idempotency' => 'fee_card_edit-' . $fee_card_id . '-' . time(),
                            'lines'       => [
                                ['account_id' => $receivableAcc, 'debit' => $new_amount, 'credit' => 0,
                                 'memo' => "Edited receivable for card #$fee_card_id"],
                                ['account_id' => $revenueAcc, 'debit' => 0, 'credit' => $new_amount,
                                 'memo' => "Edited revenue for card #$fee_card_id"],
                            ],
                        ], $glErr);
                        if ($txnId <= 0) {
                            error_log("edit_fee: GL post failed for card #$fee_card_id — $glErr");
                        }
                    }
                }
            }
        } else {
            $_SESSION['message'] = 'Error updating fee amount.';
            $_SESSION['message_type'] = 'danger';
        }
        $stmt->close();

        $redirect_url = $_SERVER['PHP_SELF'] . "?session_id=" . intval($_POST['session_id']) . 
                       "&class_id=" . intval($_POST['class_id']) . "&month=" . $_POST['month'] . 
                       "#class-" . intval($_POST['class_id']);
        header("Location: " . $redirect_url);
        exit();

    } elseif ($action_type === 'delete_fee') {
        $fee_card_id = intval($_POST['fee_card_id']);

        // Fetch card data BEFORE delete (for ledger reversal)
        $old_stmt = $conn->prepare("SELECT id, total_amount, fee_type_id FROM student_fee_card WHERE id = ? LIMIT 1");
        $old_stmt->bind_param("i", $fee_card_id);
        $old_stmt->execute();
        $old_card = $old_stmt->get_result()->fetch_assoc();
        $old_stmt->close();

        $stmt = $conn->prepare("DELETE FROM student_fee_card WHERE id = ?");
        $stmt->bind_param("i", $fee_card_id);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = 'Fee record deleted successfully!';
            $_SESSION['message_type'] = 'success';

            // === GL: Reverse the fee card creation entry ===
            if ($old_card && (float)$old_card['total_amount'] > 0) {
                $origStmt = $conn->prepare("
                    SELECT id FROM gl_transactions
                    WHERE reference_type = 'fee_card' AND reference_id = ?
                      AND status = 'POSTED' AND reversal_of IS NULL
                    ORDER BY id ASC LIMIT 1
                ");
                $origStmt->bind_param("i", $fee_card_id);
                $origStmt->execute();
                $origTxn = $origStmt->get_result()->fetch_assoc();
                $origStmt->close();

                if ($origTxn) {
                    $revErr = null;
                    $postedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
                    $revId = reverse_journal_entry(
                        $conn, (int)$origTxn['id'],
                        "Fee card #$fee_card_id deleted",
                        $postedBy, $revErr
                    );
                    if ($revId <= 0) {
                        error_log("delete_fee: reversal failed for card #$fee_card_id — $revErr");
                    }
                }
            }
        } else {
            $_SESSION['message'] = 'Error deleting fee record.';
            $_SESSION['message_type'] = 'danger';
        }
        $stmt->close();

        $redirect_url = $_SERVER['PHP_SELF'] . "?session_id=" . intval($_POST['session_id']) . 
                       "&class_id=" . intval($_POST['class_id']) . "&month=" . $_POST['month'] . 
                       "#class-" . intval($_POST['class_id']);
        header("Location: " . $redirect_url);
        exit();
    }
}

// Handle form submission (create/update batch)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['action_type'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['message'] = 'Invalid request!';
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $session_id = intval($_POST['session_id']);
    $class_id = intval($_POST['class_id']);
    $month = $_POST['month'];
    $year = date('Y');
    $action = $_POST['action'] ?? 'create';

    if ($action === 'view') {
        $query_string = "?session_id=$session_id&class_id=$class_id&month=$month";
        header("Location: " . $_SERVER['PHP_SELF'] . $query_string);
        exit();
    }

    if (($action === 'create' || $action === 'update') && $session_id && $class_id && preg_match('/^(0[1-9]|1[0-2])$/', $month)) {
        if ($action === 'create' && (!isset($_POST['fee_types']) || empty($_POST['fee_types']))) {
            $_SESSION['message'] = 'Please select at least one fee type.';
            $_SESSION['message_type'] = 'warning';
            header("Location: " . $_SERVER['PHP_SELF'] . "?session_id=$session_id&class_id=$class_id&month=$month#class-$class_id");
            exit();
        }
        
        $selected_fee_types = isset($_POST['fee_types']) ? array_map('intval', $_POST['fee_types']) : [];
        $due_date = date('Y-m-t', strtotime("$year-$month-01"));

        // Get active students
        $stmt = $conn->prepare("
            SELECT sc.id AS student_class_id, sr.id AS student_registration_id, sr.name, sr.father_name, sr.mobile
            FROM student_class sc
            INNER JOIN student_registration sr ON sc.student_registration_id = sr.id
            WHERE sc.session_id = ? AND sc.class_id = ? AND sc.status = 0 AND sr.status = 0
            ORDER BY sr.name
        ");
        $stmt->bind_param("ii", $session_id, $class_id);
        $stmt->execute();
        $students_result = $stmt->get_result();
        $students = $students_result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $students_result->free();

        if (empty($students)) {
            $_SESSION['message'] = 'No active students found for the selected session and class.';
            $_SESSION['message_type'] = 'warning';
            header("Location: " . $_SERVER['PHP_SELF'] . "?session_id=$session_id&class_id=$class_id&month=$month#class-$class_id");
            exit();
        }

        // Build fee structures
        $fee_structures = [];
        foreach ($selected_fee_types as $fee_type_id) {
            if (isset($_POST['fee_amounts'][$fee_type_id])) {
                $amount = floatval($_POST['fee_amounts'][$fee_type_id]);
                $title = $_POST['fee_titles'][$fee_type_id] ?? '';
                
                if ($amount >= 0 && !empty($title)) {
                    $fee_structures[] = [
                        'fee_type_id' => $fee_type_id,
                        'amount' => $amount,
                        'title' => $title
                    ];
                }
            }
        }

        if ($action === 'create' && empty($fee_structures)) {
            $_SESSION['message'] = 'No fee types defined for the selected class and session.';
            $_SESSION['message_type'] = 'warning';
            header("Location: " . $_SERVER['PHP_SELF'] . "?session_id=$session_id&class_id=$class_id&month=$month#class-$class_id");
            exit();
        }

        $record_count = 0;
        $delete_count = 0;
        $duplicate_count = 0;
        $gl_failures = 0;
        $month_name = date('F', strtotime("$year-$month-01"));
        $postedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;

        if ($action === 'create') {
            $insert_stmt = $conn->prepare("
                INSERT INTO student_fee_card 
                (student_class_id, fee_type_id, total_amount, due_date, session_id, remarks)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $check_stmt = $conn->prepare("
                SELECT 1 FROM student_fee_card 
                WHERE student_class_id = ? AND fee_type_id = ? AND due_date = ? AND session_id = ?
            ");

            foreach ($students as $student) {
                $student_class_id = $student['student_class_id'];
                
                // Get student-specific assignments
                $student_fee_stmt = $conn->prepare("
                    SELECT fee_type_id, amount 
                    FROM student_fee_assignments 
                    WHERE student_id = ? AND status = 1
                ");
                $student_fee_stmt->bind_param("i", $student['student_registration_id']);
                $student_fee_stmt->execute();
                $student_fees_result = $student_fee_stmt->get_result();
                $student_fees = [];
                while ($fee_row = $student_fees_result->fetch_assoc()) {
                    $student_fees[$fee_row['fee_type_id']] = $fee_row['amount'];
                }
                $student_fee_stmt->close();
                $student_fees_result->free();
                
                foreach ($fee_structures as $fee) {
                    $fee_type_id = $fee['fee_type_id'];
                    $amount = isset($student_fees[$fee_type_id]) ? $student_fees[$fee_type_id] : $fee['amount'];
                    $remarks = $fee['title'] . " fee for $month_name $year";

                    $check_stmt->bind_param("iiss", $student_class_id, $fee_type_id, $due_date, $session_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    $exists = $check_result->num_rows > 0;
                    $check_result->free();

                    if (!$exists) {
                        $insert_stmt->bind_param("iidssi", $student_class_id, $fee_type_id, $amount, $due_date, $session_id, $remarks);
                        if ($insert_stmt->execute()) {
                            $new_card_id = $conn->insert_id;
                            $record_count++;

                            // === GL: Post receivable entry ===
                            if ($amount > 0 && $new_card_id > 0) {
                                $revenueAcc = gl_revenue_account_for_fee_type($conn, $fee_type_id);
                                $receivableAcc = gl_receivable_account($conn);

                                if ($revenueAcc > 0 && $receivableAcc > 0) {
                                    $glErr = null;
                                    $txnId = post_journal_entry($conn, [
                                        'entry_date'  => $due_date,
                                        'session_id'  => $session_id,
                                        'description' => "Fee card #$new_card_id created — $remarks",
                                        'ref_type'    => 'fee_card',
                                        'ref_id'      => $new_card_id,
                                        'posted_by'   => $postedBy,
                                        'idempotency' => 'fee_card_create-' . $new_card_id,
                                        'lines'       => [
                                            ['account_id' => $receivableAcc, 'debit' => $amount, 'credit' => 0,
                                             'memo' => "Receivable for card #$new_card_id"],
                                            ['account_id' => $revenueAcc, 'debit' => 0, 'credit' => $amount,
                                             'memo' => "Fee revenue — fee_type #$fee_type_id"],
                                        ],
                                    ], $glErr);
                                    if ($txnId <= 0) {
                                        $gl_failures++;
                                        error_log("fee_card_create: GL post failed for card #$new_card_id — $glErr");
                                    }
                                } else {
                                    $gl_failures++;
                                    error_log("fee_card_create: missing accounts for card #$new_card_id");
                                }
                            }
                        }
                    } else {
                        $duplicate_count++;
                    }
                }
            }

            $insert_stmt->close();
            $check_stmt->close();

            $message = 'Monthly fee records created successfully!';
            if ($record_count > 0) $message .= " Created: $record_count records.";
            if ($duplicate_count > 0) $message .= " Skipped: $duplicate_count duplicates.";
            if ($gl_failures > 0) $message .= " WARNING: $gl_failures ledger post(s) failed — check error log.";
            
            $_SESSION['message'] = $message;
            $_SESSION['message_type'] = $gl_failures > 0 ? 'warning' : 'success';
            
        } elseif ($action === 'update') {
            // Get all existing fee types for this class/month
            $existing_fee_types_stmt = $conn->prepare("
                SELECT DISTINCT fee_type_id 
                FROM student_fee_card sfc
                INNER JOIN student_class sc ON sfc.student_class_id = sc.id
                WHERE sfc.session_id = ? AND sc.class_id = ? AND sfc.due_date = ?
            ");
            $existing_fee_types_stmt->bind_param("iis", $session_id, $class_id, $due_date);
            $existing_fee_types_stmt->execute();
            $existing_result = $existing_fee_types_stmt->get_result();
            $all_existing_fee_types = [];
            while ($row = $existing_result->fetch_assoc()) {
                $all_existing_fee_types[] = $row['fee_type_id'];
            }
            $existing_fee_types_stmt->close();
            $existing_result->free();

            $update_stmt = $conn->prepare("
                UPDATE student_fee_card 
                SET total_amount = ?, remarks = ?
                WHERE student_class_id = ? AND fee_type_id = ? AND due_date = ? AND session_id = ?
            ");

            $insert_stmt = $conn->prepare("
                INSERT INTO student_fee_card 
                (student_class_id, fee_type_id, total_amount, due_date, session_id, remarks)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $check_stmt = $conn->prepare("
                SELECT id, total_amount FROM student_fee_card 
                WHERE student_class_id = ? AND fee_type_id = ? AND due_date = ? AND session_id = ?
                LIMIT 1
            ");

            $delete_stmt = $conn->prepare("
                DELETE FROM student_fee_card 
                WHERE student_class_id = ? AND fee_type_id = ? AND due_date = ? AND session_id = ?
            ");

            $lookup_stmt = $conn->prepare("
                SELECT id, total_amount FROM student_fee_card 
                WHERE student_class_id = ? AND fee_type_id = ? AND due_date = ? AND session_id = ?
                LIMIT 1
            ");

            foreach ($students as $student) {
                $student_class_id = $student['student_class_id'];
                
                $student_fee_stmt = $conn->prepare("
                    SELECT fee_type_id, amount 
                    FROM student_fee_assignments 
                    WHERE student_id = ? AND status = 1
                ");
                $student_fee_stmt->bind_param("i", $student['student_registration_id']);
                $student_fee_stmt->execute();
                $student_fees_result = $student_fee_stmt->get_result();
                $student_fees = [];
                while ($fee_row = $student_fees_result->fetch_assoc()) {
                    $student_fees[$fee_row['fee_type_id']] = $fee_row['amount'];
                }
                $student_fee_stmt->close();
                $student_fees_result->free();
                
                foreach ($fee_structures as $fee) {
                    $fee_type_id = $fee['fee_type_id'];
                    $amount = isset($student_fees[$fee_type_id]) ? $student_fees[$fee_type_id] : $fee['amount'];
                    $remarks = $fee['title'] . " fee for $month_name $year (Updated)";

                    // Get existing card data FIRST
                    $lookup_stmt->bind_param("iiss", $student_class_id, $fee_type_id, $due_date, $session_id);
                    $lookup_stmt->execute();
                    $existing_card = $lookup_stmt->get_result()->fetch_assoc();

                    $check_stmt->bind_param("iiss", $student_class_id, $fee_type_id, $due_date, $session_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    
                    if ($check_result->num_rows > 0) {
                        $update_stmt->bind_param("dsiiss", $amount, $remarks, $student_class_id, $fee_type_id, $due_date, $session_id);
                        if ($update_stmt->execute()) {
                            $record_count++;

                            // GL: if amount changed, reverse old, post new
                            if ($existing_card && (float)$existing_card['total_amount'] !== (float)$amount) {
                                $card_id = (int)$existing_card['id'];
                                $old_amount = (float)$existing_card['total_amount'];

                                if ($old_amount > 0) {
                                    $origStmt = $conn->prepare("
                                        SELECT id FROM gl_transactions
                                        WHERE reference_type = 'fee_card' AND reference_id = ?
                                          AND status = 'POSTED' AND reversal_of IS NULL
                                        ORDER BY id ASC LIMIT 1
                                    ");
                                    $origStmt->bind_param("i", $card_id);
                                    $origStmt->execute();
                                    $origTxn = $origStmt->get_result()->fetch_assoc();
                                    $origStmt->close();

                                    if ($origTxn) {
                                        $revErr = null;
                                        $revId = reverse_journal_entry(
                                            $conn, (int)$origTxn['id'],
                                            "Fee card #$card_id updated: $old_amount → $amount",
                                            $postedBy, $revErr
                                        );
                                        if ($revId <= 0) {
                                            $gl_failures++;
                                            error_log("fee_card_update: reversal failed for card #$card_id — $revErr");
                                        }
                                    }
                                }

                                if ($amount > 0) {
                                    $revenueAcc = gl_revenue_account_for_fee_type($conn, $fee_type_id);
                                    $receivableAcc = gl_receivable_account($conn);

                                    if ($revenueAcc > 0 && $receivableAcc > 0) {
                                        $glErr = null;
                                        $txnId = post_journal_entry($conn, [
                                            'entry_date'  => $due_date,
                                            'session_id'  => $session_id,
                                            'description' => "Fee card #$card_id updated to $amount",
                                            'ref_type'    => 'fee_card',
                                            'ref_id'      => $card_id,
                                            'posted_by'   => $postedBy,
                                            'idempotency' => 'fee_card_update-' . $card_id . '-' . time(),
                                            'lines'       => [
                                                ['account_id' => $receivableAcc, 'debit' => $amount, 'credit' => 0,
                                                 'memo' => "Updated receivable for card #$card_id"],
                                                ['account_id' => $revenueAcc, 'debit' => 0, 'credit' => $amount,
                                                 'memo' => "Updated revenue for card #$card_id"],
                                            ],
                                        ], $glErr);
                                        if ($txnId <= 0) {
                                            $gl_failures++;
                                            error_log("fee_card_update: post failed for card #$card_id — $glErr");
                                        }
                                    }
                                }
                            }
                        }
                    } else {
                        $insert_stmt->bind_param("iidssi", $student_class_id, $fee_type_id, $amount, $due_date, $session_id, $remarks);
                        if ($insert_stmt->execute()) {
                            $new_card_id = $conn->insert_id;
                            $record_count++;

                            if ($amount > 0 && $new_card_id > 0) {
                                $revenueAcc = gl_revenue_account_for_fee_type($conn, $fee_type_id);
                                $receivableAcc = gl_receivable_account($conn);

                                if ($revenueAcc > 0 && $receivableAcc > 0) {
                                    $glErr = null;
                                    $txnId = post_journal_entry($conn, [
                                        'entry_date'  => $due_date,
                                        'session_id'  => $session_id,
                                        'description' => "Fee card #$new_card_id created (via update) — $remarks",
                                        'ref_type'    => 'fee_card',
                                        'ref_id'      => $new_card_id,
                                        'posted_by'   => $postedBy,
                                        'idempotency' => 'fee_card_create-' . $new_card_id,
                                        'lines'       => [
                                            ['account_id' => $receivableAcc, 'debit' => $amount, 'credit' => 0,
                                             'memo' => "Receivable for card #$new_card_id"],
                                            ['account_id' => $revenueAcc, 'debit' => 0, 'credit' => $amount,
                                             'memo' => "Fee revenue — fee_type #$fee_type_id"],
                                        ],
                                    ], $glErr);
                                    if ($txnId <= 0) {
                                        $gl_failures++;
                                        error_log("fee_card_update: post failed for new card #$new_card_id — $glErr");
                                    }
                                }
                            }
                        }
                    }
                    $check_result->free();
                }
                
                // Delete unchecked fee types
                foreach ($all_existing_fee_types as $existing_fee_type_id) {
                    if (!in_array($existing_fee_type_id, $selected_fee_types)) {
                        // Lookup card first
                        $lookup_stmt->bind_param("iiss", $student_class_id, $existing_fee_type_id, $due_date, $session_id);
                        $lookup_stmt->execute();
                        $toDelete = $lookup_stmt->get_result()->fetch_assoc();

                        $delete_stmt->bind_param("iiss", $student_class_id, $existing_fee_type_id, $due_date, $session_id);
                        if ($delete_stmt->execute() && $delete_stmt->affected_rows > 0) {
                            $delete_count++;

                            // GL: reverse
                            if ($toDelete && (float)$toDelete['total_amount'] > 0) {
                                $card_id = (int)$toDelete['id'];
                                $origStmt = $conn->prepare("
                                    SELECT id FROM gl_transactions
                                    WHERE reference_type = 'fee_card' AND reference_id = ?
                                      AND status = 'POSTED' AND reversal_of IS NULL
                                    ORDER BY id ASC LIMIT 1
                                ");
                                $origStmt->bind_param("i", $card_id);
                                $origStmt->execute();
                                $origTxn = $origStmt->get_result()->fetch_assoc();
                                $origStmt->close();

                                if ($origTxn) {
                                    $revErr = null;
                                    $revId = reverse_journal_entry(
                                        $conn, (int)$origTxn['id'],
                                        "Fee card #$card_id removed during monthly update",
                                        $postedBy, $revErr
                                    );
                                    if ($revId <= 0) {
                                        $gl_failures++;
                                        error_log("fee_card_update: reversal failed for deleted card #$card_id — $revErr");
                                    }
                                }
                            }
                        }
                    }
                }
            }

            $update_stmt->close();
            $insert_stmt->close();
            $check_stmt->close();
            $delete_stmt->close();
            $lookup_stmt->close();

            $message = 'Fee records updated successfully!';
            if ($record_count > 0) $message .= " Updated: $record_count records.";
            if ($delete_count > 0) $message .= " Removed: $delete_count records.";
            if ($gl_failures > 0) $message .= " WARNING: $gl_failures ledger post(s) failed — check error log.";
            
            $_SESSION['message'] = $message;
            $_SESSION['message_type'] = $gl_failures > 0 ? 'warning' : 'success';
        }

        header("Location: " . $_SERVER['PHP_SELF'] . "?session_id=$session_id&class_id=$class_id&month=$month#class-$class_id");
        exit();
    } else {
        $_SESSION['message'] = 'Error: Invalid session, class, or month.';
        $_SESSION['message_type'] = 'danger';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Get parameters from URL
$selected_session = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
$selected_class = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$selected_month = isset($_GET['month']) ? $_GET['month'] : '';
$selected_year = date('Y');
$selected_due_date = $selected_month ? date('Y-m-t', strtotime("$selected_year-$selected_month-01")) : '';

// Validate session selection
$session_error = '';
if (isset($_GET['session_id']) && $_GET['session_id'] == '') {
    $session_error = 'Please select a session.';
}

// Fetch available fee types
$available_fee_types = [];
if ($selected_session && $selected_class) {
    $stmt = $conn->prepare("
        SELECT ft.id AS fee_type_id, ft.title, cft.amount, 'class' as source
        FROM class_fee_types cft
        INNER JOIN fee_types ft ON cft.fee_type_id = ft.id
        WHERE cft.session_id = ? AND cft.class_id = ? AND cft.status = 0
        ORDER BY ft.title
    ");
    $stmt->bind_param("ii", $selected_session, $selected_class);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $available_fee_types[] = $row;
    }
    $stmt->close();
    $result->free();
    
    $stmt = $conn->prepare("
        SELECT DISTINCT ft.id AS fee_type_id, ft.title, 0 as amount, 'student' as source
        FROM student_fee_assignments sfa
        INNER JOIN student_registration sr ON sfa.student_id = sr.id
        INNER JOIN student_class sc ON sr.id = sc.student_registration_id
        INNER JOIN fee_types ft ON sfa.fee_type_id = ft.id
        WHERE sc.session_id = ? AND sc.class_id = ? 
          AND sc.status = 0 AND sfa.status = 1 AND sr.status = 1
          AND ft.id NOT IN (
              SELECT fee_type_id FROM class_fee_types 
              WHERE session_id = ? AND class_id = ? AND status = 0
          )
        ORDER BY ft.title
    ");
    $stmt->bind_param("iiii", $selected_session, $selected_class, $selected_session, $selected_class);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $available_fee_types[] = $row;
    }
    $stmt->close();
    $result->free();
}

// Fetch existing fee records
$fee_records = [];
$existing_fee_amounts = [];
$has_existing_records = false;
$existing_fee_types_in_use = [];

if ($selected_session && $selected_class && $selected_due_date) {
    $check_stmt = $conn->prepare("
        SELECT COUNT(*) as count FROM student_fee_card sfc
        INNER JOIN student_class sc ON sfc.student_class_id = sc.id
        INNER JOIN student_registration sr ON sc.student_registration_id = sr.id
        WHERE sfc.session_id = ? AND sc.class_id = ? AND sfc.due_date = ? AND sr.status = 1
    ");
    $check_stmt->bind_param("iis", $selected_session, $selected_class, $selected_due_date);
    $check_stmt->execute();
    $count_row = $check_stmt->get_result()->fetch_assoc();
    $has_existing_records = ($count_row['count'] > 0);
    $check_stmt->close();
    
    $type_check_stmt = $conn->prepare("
        SELECT DISTINCT sfc.fee_type_id
        FROM student_fee_card sfc
        INNER JOIN student_class sc ON sfc.student_class_id = sc.id
        INNER JOIN student_registration sr ON sc.student_registration_id = sr.id
        WHERE sfc.session_id = ? AND sc.class_id = ? AND sfc.due_date = ? AND sr.status = 1
    ");
    $type_check_stmt->bind_param("iis", $selected_session, $selected_class, $selected_due_date);
    $type_check_stmt->execute();
    $type_check_result = $type_check_stmt->get_result();
    while ($row = $type_check_result->fetch_assoc()) {
        $existing_fee_types_in_use[] = $row['fee_type_id'];
    }
    $type_check_stmt->close();
    
    $amount_stmt = $conn->prepare("
        SELECT DISTINCT sfc.fee_type_id, sfc.total_amount, ft.title
        FROM student_fee_card sfc
        INNER JOIN fee_types ft ON sfc.fee_type_id = ft.id
        INNER JOIN student_class sc ON sfc.student_class_id = sc.id
        INNER JOIN student_registration sr ON sc.student_registration_id = sr.id
        WHERE sfc.session_id = ? AND sc.class_id = ? AND sfc.due_date = ? AND sr.status = 1
    ");
    $amount_stmt->bind_param("iis", $selected_session, $selected_class, $selected_due_date);
    $amount_stmt->execute();
    $amount_result = $amount_stmt->get_result();
    while ($row = $amount_result->fetch_assoc()) {
        $existing_fee_amounts[$row['fee_type_id']] = [
            'amount' => $row['total_amount'],
            'title' => $row['title']
        ];
    }
    $amount_stmt->close();

    $stmt = $conn->prepare("
        SELECT sfc.id as fee_card_id, sfc.total_amount, sfc.due_date, sfc.remarks, sfc.fee_type_id,
               sr.id AS student_registration_id, sr.name AS student_name, 
               sr.father_name, sr.mobile,
               c.title AS class_title, ft.title AS fee_type_title
        FROM student_fee_card sfc
        INNER JOIN student_class sc ON sfc.student_class_id = sc.id
        INNER JOIN student_registration sr ON sc.student_registration_id = sr.id
        INNER JOIN classes c ON sc.class_id = c.id
        INNER JOIN fee_types ft ON sfc.fee_type_id = ft.id
        WHERE sfc.session_id = ? AND sc.class_id = ? AND sfc.due_date = ? AND sr.status = 1
        ORDER BY c.title, sr.name, ft.title
    ");
    $stmt->bind_param("iis", $selected_session, $selected_class, $selected_due_date);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $class_key = $row['class_title'];
        $student_key = $row['student_name'] . '_' . $row['student_registration_id'];

        if (!isset($fee_records[$class_key])) {
            $fee_records[$class_key] = ['class_title' => $class_key, 'students' => []];
        }
        if (!isset($fee_records[$class_key]['students'][$student_key])) {
            $fee_records[$class_key]['students'][$student_key] = [
                'student_registration_id' => $row['student_registration_id'],
                'student_name' => $row['student_name'],
                'father_name' => $row['father_name'],
                'mobile' => $row['mobile'],
                'fees' => []
            ];
        }
        $fee_records[$class_key]['students'][$student_key]['fees'][] = [
            'fee_card_id' => $row['fee_card_id'],
            'fee_type_id' => $row['fee_type_id'],
            'fee_type_title' => $row['fee_type_title'],
            'total_amount' => $row['total_amount'],
            'due_date' => $row['due_date'],
            'remarks' => $row['remarks']
        ];
    }
    $stmt->close();
}

$months = [
    '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April',
    '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August',
    '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Student Monthly Fee Card Management</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
    <link rel="stylesheet" href="css/mystyle.css" />
    
    <style>
    .table th, .table td { text-align: left; vertical-align: top; }
    .class-header { margin-top: 20px; margin-bottom: 10px; font-size: 18px; font-weight: bold; padding: 10px; background-color: #f8f9fa; border-left: 4px solid #0052cc; display: flex; justify-content: space-between; align-items: center; scroll-margin-top: 20px; }
    .table tbody tr.odd-row { background-color: #ffffff !important; }
    .table tbody tr.even-row { background-color: #f0f0f0 !important; }
    .table tbody tr.student-row { background-color: #cce5ff !important; font-weight: bold; border-bottom: 2px solid #0052cc; }
    .table tbody tr.fee-detail-row.odd-row { background-color: #ffffff !important; }
    .table tbody tr.fee-detail-row.even-row { background-color: #f5f5f5 !important; }
    .table-hover tbody tr:hover td { background-color: #e6f7ff !important; transition: background-color 0.2s ease; }
    .table-hover tbody tr:hover td, .table-hover tbody tr:hover th { background-color: #e6f7ff !important; }
    .fee-detail-row td { border-top: none !important; padding-left: 30px; }
    .print-only { display: none; }
    .fee-type-item { display: flex; align-items: center; margin-bottom: 10px; padding: 10px; background: #f8f9fa; border-radius: 4px; border: 1px solid #ddd; }
    .fee-type-item:nth-child(odd) { background-color: #f9f9f9; }
    .fee-type-item:nth-child(even) { background-color: #ffffff; }
    .fee-type-item:hover { background-color: #f0f0f0; border-color: #999; transition: all 0.2s ease; }
    .fee-type-checkbox { margin-right: 15px; }
    .fee-type-details { flex-grow: 1; display: flex; align-items: center; justify-content: space-between; }
    .fee-type-title { font-weight: bold; min-width: 200px; }
    .fee-type-amount-input { width: 150px; padding: 5px 10px; border: 1px solid #ccc; border-radius: 4px; text-align: right; }
    .original-amount { color: #666; font-size: 12px; margin-left: 10px; }
    .existing-amount { color: #28a745; font-size: 12px; margin-left: 10px; font-weight: bold; }
    .checkbox-list { max-height: 400px; overflow-y: auto; padding: 10px; background: #fff; border: 1px solid #ddd; border-radius: 4px; }
    .btn-group-vertical { display: flex; flex-direction: column; gap: 10px; }
    .action-buttons { margin-top: 20px; }
    .update-note { background: #e7f3ff; border-left: 4px solid #007bff; padding: 10px; margin-bottom: 15px; font-size: 14px; }
    .action-btns { white-space: nowrap; }
    .action-btns .btn { margin: 0 2px; padding: 2px 8px; font-size: 12px; }
    .fee-amount { font-weight: bold; color: #28a745; }
    .modal-header { background-color: #f8f9fa; border-bottom: 2px solid #0052cc; }
    .modal-title { color: #0052cc; font-weight: bold; }
    .delete-modal .modal-header { background-color: #f2dede; border-bottom: 2px solid #d43f3a; }
    .delete-modal .modal-title { color: #d43f3a; }
    .form-group label { font-weight: 600; color: #555; }
    .form-control:focus { border-color: #0052cc; box-shadow: 0 0 5px rgba(0,82,204,0.2); }
    .btn-edit { background-color: #ffc107; border-color: #ffc107; color: #000; }
    .btn-edit:hover { background-color: #e0a800; border-color: #d39e00; }
    .btn-delete { background-color: #dc3545; border-color: #dc3545; color: #fff; }
    .btn-delete:hover { background-color: #c82333; border-color: #bd2130; }
    .amount-zero-note { color: #856404; background-color: #fff3cd; border: 1px solid #ffeeba; padding: 5px 10px; border-radius: 4px; margin-top: 5px; font-size: 12px; }
    .duplicate-info { color: #856404; background-color: #fff3cd; border: 1px solid #ffeeba; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 14px; }
    .session-error { color: #721c24; background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 14px; font-weight: bold; }
    .class-fee-amount { color: #0052cc; font-size: 12px; margin-left: 10px; font-weight: bold; }
    .student-fee-badge { color: #856404; background-color: #fff3cd; font-size: 11px; padding: 2px 6px; border-radius: 4px; margin-left: 10px; font-weight: bold; border: 1px solid #ffeeba; }
    @media print {
        body * { visibility: hidden; }
        .print-only, .print-only * { visibility: visible; }
        .print-only { display: block; position: absolute; top: 0; left: 0; width: 100%; }
        .no-print { display: none; }
        .print-header { text-align: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #0052cc; }
        .print-header h1 { font-size: 24px; margin: 5px 0; }
        .print-header h2 { font-size: 18px; margin: 5px 0; }
        .print-header p { margin: 3px 0; font-size: 14px; }
        .print-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        .print-table th, .print-table td { border: 1px solid #ddd; padding: 8px; font-size: 12px; }
        .print-table th { background-color: #f2f2f2 !important; }
        .print-class-header { margin-top: 20px; margin-bottom: 10px; font-size: 16px; font-weight: bold; padding: 8px; background-color: #f8f9fa; border-left: 4px solid #0052cc; }
        .print-student-row { background-color: #f8f9fa !important; font-weight: bold; }
        .print-fee-detail-row { background-color: #ffffff !important; }
        .print-footer { text-align: center; margin-top: 20px; font-size: 12px; color: #666; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="container">
    <div class="row">
        <div class="col-md-12">
            <div class="panel panel-primary no-print">
                <div class="panel-heading">
                    <h3 class="panel-title">Student Monthly Fee Card Management</h3>
                </div>
                <div class="panel-body">
                    <?php if (isset($_SESSION['message'])): ?>
                        <div class="alert alert-<?php echo $_SESSION['message_type']; ?>">
                            <?php 
                            echo $_SESSION['message']; 
                            unset($_SESSION['message'], $_SESSION['message_type']);
                            ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($session_error): ?>
                        <div class="session-error">
                            <span class="glyphicon glyphicon-exclamation-sign"></span> 
                            <?php echo $session_error; ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" id="fee-card-form">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" id="form-action" value="create">
                        <div class="form-group">
                            <label>Session <span class="text-danger">*</span></label>
                            <select name="session_id" id="session_id" class="form-control" required>
                                <option value="">-- Select Session --</option>
                                <?php foreach ($sessions as $id => $title): ?>
                                    <option value="<?php echo $id; ?>" <?php echo ($id == $selected_session) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Month <span class="text-danger">*</span></label>
                            <select name="month" id="month" class="form-control" required>
                                <option value="">-- Select Month --</option>
                                <?php foreach ($months as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo ($value == $selected_month) ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Class <span class="text-danger">*</span></label>
                            <select name="class_id" id="class_id" class="form-control" required>
                                <option value="">-- Select Class --</option>
                                <?php foreach ($classes as $id => $title): ?>
                                    <option value="<?php echo $id; ?>" <?php echo ($id == $selected_class) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <?php if ($selected_session && $selected_class && $selected_month): ?>
                            <?php if (!empty($available_fee_types)): ?>
                                <?php if ($has_existing_records): ?>
                                    <div class="update-note">
                                        <strong>Update Mode:</strong> Checked fee types will be updated. 
                                        Unchecked fee types will be <strong>removed</strong> for this month.
                                    </div>
                                <?php endif; ?>
                                
                                <div class="duplicate-info">
                                    <span class="glyphicon glyphicon-info-sign"></span> 
                                    <strong>Note:</strong> A student cannot have the same fee type twice for the same month.
                                </div>
                                
                                <div class="form-group">
                                    <label>Select Fee Types and Adjust Amounts</label>
                                    <div class="checkbox-list">
                                        <?php foreach ($available_fee_types as $ft): 
                                            $show_amount = $ft['amount'];
                                            $existing_note = '';
                                            
                                            if (isset($existing_fee_amounts[$ft['fee_type_id']])) {
                                                $show_amount = $existing_fee_amounts[$ft['fee_type_id']]['amount'];
                                                $existing_note = 'Existing: PKR ' . number_format($show_amount, 2);
                                            }
                                            
                                            $is_checked = false;
                                            if ($has_existing_records) {
                                                $is_checked = in_array($ft['fee_type_id'], $existing_fee_types_in_use);
                                            } else {
                                                $is_checked = true;
                                            }
                                            
                                            $source_label = ($ft['source'] == 'class') ? 
                                                '<span class="class-fee-amount">(Class Fee)</span>' : 
                                                '<span class="student-fee-badge">Student Assigned</span>';
                                        ?>
                                            <div class="fee-type-item">
                                                <div class="fee-type-checkbox">
                                                    <input type="checkbox" 
                                                           name="fee_types[]" 
                                                           value="<?php echo $ft['fee_type_id']; ?>" 
                                                           class="fee-type-checkbox-input"
                                                           <?php echo $is_checked ? 'checked' : ''; ?>
                                                           data-fee-id="<?php echo $ft['fee_type_id']; ?>"
                                                           data-original-amount="<?php echo $ft['amount']; ?>"
                                                           data-existing-amount="<?php echo isset($existing_fee_amounts[$ft['fee_type_id']]) ? $existing_fee_amounts[$ft['fee_type_id']]['amount'] : ''; ?>">
                                                </div>
                                                <div class="fee-type-details">
                                                    <div class="fee-type-title">
                                                        <?php echo htmlspecialchars($ft['title']); ?>
                                                        <?php echo $source_label; ?>
                                                        <input type="hidden" name="fee_titles[<?php echo $ft['fee_type_id']; ?>]" value="<?php echo htmlspecialchars($ft['title']); ?>">
                                                    </div>
                                                    <div>
                                                        <input type="number" 
                                                               name="fee_amounts[<?php echo $ft['fee_type_id']; ?>]" 
                                                               class="fee-type-amount-input"
                                                               value="<?php echo number_format($show_amount, 2, '.', ''); ?>"
                                                               step="0.01"
                                                               min="0"
                                                               required>
                                                        <?php if (isset($existing_fee_amounts[$ft['fee_type_id']])): ?>
                                                            <span class="existing-amount">(<?php echo $existing_note; ?>)</span>
                                                        <?php else: ?>
                                                            <span class="original-amount">(Original: PKR <?php echo number_format($ft['amount'], 2); ?>)</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="amount-zero-note">
                                        <span class="glyphicon glyphicon-info-sign"></span> 
                                        Note: You can enter 0 amount for free/waived fees.
                                    </div>
                                </div>

                                <div class="action-buttons">
                                    <div class="btn-group-vertical">
                                        <?php if ($has_existing_records): ?>
                                            <button type="button" id="update-btn" class="btn btn-warning">
                                                <span class="glyphicon glyphicon-refresh"></span> 
                                                Update Fee Cards
                                            </button>
                                        <?php endif; ?>
                                        <button type="button" id="create-btn" class="btn btn-primary">
                                            <span class="glyphicon glyphicon-plus"></span> 
                                            Generate Fee Cards
                                        </button>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning">
                                    No fee types found for the selected class and session.
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php if ($selected_session && $selected_class && $selected_month): ?>
                            <div class="text-right" style="margin-top: 10px;">
                                <button type="button" id="view-btn" class="btn btn-info">
                                    <span class="glyphicon glyphicon-eye-open"></span> 
                                    View Existing
                                </button>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <div class="panel panel-info no-print">
                <div class="panel-heading">
                    <h3 class="panel-title">Fee Card Records</h3>
                </div>
                <div class="panel-body">
                    <?php if (!empty($fee_records)): ?>
                        <div class="text-right" style="margin-bottom: 10px;">
                            <button class="btn btn-primary" onclick="window.print()">
                                <span class="glyphicon glyphicon-print"></span> Print All
                            </button>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($fee_records)): ?>
                        <?php foreach ($fee_records as $class_section_key => $class_section_data): ?>
                            <div class="class-header" id="class-<?php echo $selected_class; ?>">
                                <span>Class: <?php echo htmlspecialchars($class_section_data['class_title']); ?></span>
                                <button class="btn btn-default btn-sm" onclick="printClass('<?php echo htmlspecialchars($class_section_key); ?>')">
                                    <span class="glyphicon glyphicon-print"></span> Print Class
                                </button>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Student ID</th>
                                            <th>Student</th>
                                            <th>Father Name</th>
                                            <th>Mobile</th>
                                            <th>Fee Type</th>
                                            <th>Amount</th>
                                            <th>Due Date</th>
                                            <th>Remarks</th>
                                            <th class="no-print">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $counter = 1; 
                                        $row_counter = 0;
                                        foreach ($class_section_data['students'] as $student_key => $student_data): 
                                            $student_fees = $student_data['fees'];
                                            $rowspan = count($student_fees);
                                            $first_row = true;
                                        ?>
                                            <?php foreach ($student_fees as $index => $fee): 
                                                $row_class = ($row_counter % 2 == 0) ? 'odd-row' : 'even-row';
                                                $row_class .= $first_row ? ' student-row' : ' fee-detail-row';
                                            ?>
                                                <tr class="<?php echo $row_class; ?>">
                                                    <?php if ($first_row): ?>
                                                        <td rowspan="<?php echo $rowspan; ?>"><?php echo $counter++; ?></td>
                                                        <td rowspan="<?php echo $rowspan; ?>"><?php echo htmlspecialchars($student_data['student_registration_id']); ?></td>
                                                        <td rowspan="<?php echo $rowspan; ?>"><?php echo htmlspecialchars($student_data['student_name']); ?></td>
                                                        <td rowspan="<?php echo $rowspan; ?>"><?php echo htmlspecialchars($student_data['father_name']); ?></td>
                                                        <td rowspan="<?php echo $rowspan; ?>"><?php echo htmlspecialchars($student_data['mobile']); ?></td>
                                                    <?php endif; ?>
                                                    <td><?php echo htmlspecialchars($fee['fee_type_title']); ?></td>
                                                    <td class="fee-amount"><?php echo 'PKR ' . number_format($fee['total_amount'], 2); ?></td>
                                                    <td><?php echo htmlspecialchars($fee['due_date']); ?></td>
                                                    <td><?php echo htmlspecialchars($fee['remarks']); ?></td>
                                                    <td class="no-print action-btns">
                                                        <button type="button" class="btn btn-xs btn-edit" onclick="openEditModal(<?php echo $fee['fee_card_id']; ?>, <?php echo $fee['total_amount']; ?>, '<?php echo htmlspecialchars(addslashes($fee['remarks'])); ?>', <?php echo $selected_session; ?>, <?php echo $selected_class; ?>, '<?php echo $selected_month; ?>')">
                                                            <span class="glyphicon glyphicon-pencil"></span> Edit
                                                        </button>
                                                        <button type="button" class="btn btn-xs btn-delete" onclick="openDeleteModal(<?php echo $fee['fee_card_id']; ?>, '<?php echo htmlspecialchars(addslashes($fee['fee_type_title'])); ?>', '<?php echo htmlspecialchars(addslashes($student_data['student_name'])); ?>', <?php echo $selected_session; ?>, <?php echo $selected_class; ?>, '<?php echo $selected_month; ?>')">
                                                            <span class="glyphicon glyphicon-trash"></span> Delete
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php 
                                            $first_row = false;
                                            $row_counter++;
                                            endforeach; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php if ($selected_session && $selected_class && $selected_month): ?>
                            <div class="alert alert-info text-center">
                                No monthly fee records found for the selected session, class, and month.
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Fee Modal -->
<div id="editFeeModal" class="modal fade" role="dialog">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">Edit Fee Amount</h4>
            </div>
            <form method="post" action="">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action_type" value="edit_fee">
                    <input type="hidden" name="fee_card_id" id="edit_fee_card_id" value="">
                    <input type="hidden" name="session_id" id="edit_session_id" value="">
                    <input type="hidden" name="class_id" id="edit_class_id" value="">
                    <input type="hidden" name="month" id="edit_month" value="">
                    
                    <div class="form-group">
                        <label>Amount (PKR)</label>
                        <input type="number" name="amount" id="edit_amount" class="form-control" step="0.01" min="0" required>
                        <small class="text-muted">You can enter 0 for free/waived fees</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Remarks</label>
                        <textarea name="remarks" id="edit_remarks" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Fee</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteFeeModal" class="modal fade delete-modal" role="dialog">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">Confirm Delete</h4>
            </div>
            <form method="post" action="">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action_type" value="delete_fee">
                    <input type="hidden" name="fee_card_id" id="delete_fee_card_id" value="">
                    <input type="hidden" name="session_id" id="delete_session_id" value="">
                    <input type="hidden" name="class_id" id="delete_class_id" value="">
                    <input type="hidden" name="month" id="delete_month" value="">
                    
                    <p>Are you sure you want to delete this fee record?</p>
                    <p><strong>Student:</strong> <span id="delete_student_name"></span></p>
                    <p><strong>Fee Type:</strong> <span id="delete_fee_type"></span></p>
                    <p class="text-danger"><small>This action cannot be undone.</small></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Fee</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Print-Only Version -->
<div class="print-only">
    <div class="print-header">
        <h1>School Management System</h1>
        <h2>Student Monthly Fee Card Management</h2>
        <p>Session: <?php echo htmlspecialchars($sessions[$selected_session] ?? 'N/A'); ?></p>
        <p>Class: <?php echo htmlspecialchars($classes[$selected_class] ?? 'N/A'); ?></p>
        <p>Month: <?php echo htmlspecialchars($months[$selected_month] ?? 'N/A'); ?></p>
        <p>Due Date: <?php echo htmlspecialchars($selected_due_date); ?></p>
        <p>Generated on: <?php echo date('Y-m-d'); ?></p>
    </div>
    
    <?php if (!empty($fee_records)): ?>
        <?php foreach ($fee_records as $class_section_key => $class_section_data): ?>
            <div class="print-class-header">
                Class: <?php echo htmlspecialchars($class_section_data['class_title']); ?>
            </div>
            <table class="print-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student ID</th>
                        <th>Student</th>
                        <th>Father Name</th>
                        <th>Mobile</th>
                        <th>Fee Type</th>
                        <th>Amount</th>
                        <th>Due Date</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $counter = 1; 
                    $row_counter = 0;
                    foreach ($class_section_data['students'] as $student_key => $student_data): 
                        $student_fees = $student_data['fees'];
                        $rowspan = count($student_fees);
                        $first_row = true;
                    ?>
                        <?php foreach ($student_fees as $index => $fee): 
                            $row_class = ($row_counter % 2 == 0) ? 'odd-row' : 'even-row';
                            $row_class .= $first_row ? ' student-row' : ' fee-detail-row';
                        ?>
                            <tr class="<?php echo $row_class; ?>">
                                <?php if ($first_row): ?>
                                    <td rowspan="<?php echo $rowspan; ?>"><?php echo $counter++; ?></td>
                                    <td rowspan="<?php echo $rowspan; ?>"><?php echo htmlspecialchars($student_data['student_registration_id']); ?></td>
                                    <td rowspan="<?php echo $rowspan; ?>"><?php echo htmlspecialchars($student_data['student_name']); ?></td>
                                    <td rowspan="<?php echo $rowspan; ?>"><?php echo htmlspecialchars($student_data['father_name']); ?></td>
                                    <td rowspan="<?php echo $rowspan; ?>"><?php echo htmlspecialchars($student_data['mobile']); ?></td>
                                <?php endif; ?>
                                <td><?php echo htmlspecialchars($fee['fee_type_title']); ?></td>
                                <td class="fee-amount"><?php echo 'PKR ' . number_format($fee['total_amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($fee['due_date']); ?></td>
                                <td><?php echo htmlspecialchars($fee['remarks']); ?></td>
                            </tr>
                            <?php 
                            $first_row = false;
                            $row_counter++;
                            endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
    <?php else: ?>
        <p style="text-align: center;">No monthly fee records found.</p>
    <?php endif; ?>
    
    <div class="print-footer">
        <p>Generated by School Management System</p>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sessionSelect = document.getElementById('session_id');
    const classSelect = document.getElementById('class_id');
    const monthSelect = document.getElementById('month');
    const createBtn = document.getElementById('create-btn');
    const updateBtn = document.getElementById('update-btn');
    const viewBtn = document.getElementById('view-btn');
    const form = document.getElementById('fee-card-form');
    const formAction = document.getElementById('form-action');
    
    function toggleAmountInput(checkbox) {
        const feeId = checkbox.getAttribute('data-fee-id');
        const amountInput = document.querySelector(`input[name="fee_amounts[${feeId}]"]`);
        if (amountInput) {
            amountInput.disabled = !checkbox.checked;
            if (!checkbox.checked) {
                const existingAmount = checkbox.getAttribute('data-existing-amount');
                const originalAmount = checkbox.getAttribute('data-original-amount');
                amountInput.value = existingAmount || originalAmount;
            }
        }
    }
    
    document.querySelectorAll('.fee-type-checkbox-input').forEach(checkbox => {
        toggleAmountInput(checkbox);
        checkbox.addEventListener('change', function() {
            toggleAmountInput(this);
        });
    });
    
    if (classSelect) {
        classSelect.addEventListener('change', function() {
            const sessionId = sessionSelect.value;
            const classId = classSelect.value;
            const month = monthSelect.value;
            
            if (sessionId && classId && month) {
                const url = new URL(window.location);
                url.searchParams.set('session_id', sessionId);
                url.searchParams.set('class_id', classId);
                url.searchParams.set('month', month);
                window.location.href = url.toString();
            } else if (sessionId && classId) {
                const url = new URL(window.location);
                url.searchParams.set('session_id', sessionId);
                url.searchParams.set('class_id', classId);
                url.searchParams.delete('month');
                window.location.href = url.toString();
            }
        });
    }
    
    if (createBtn) {
        createBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!sessionSelect.value) {
                alert('Please select a session first.');
                sessionSelect.focus();
                return;
            }
            const feeTypeCheckboxes = document.querySelectorAll('.fee-type-checkbox-input:checked');
            if (feeTypeCheckboxes.length === 0) {
                alert('Please select at least one fee type.');
                return;
            }
            let hasErrors = false;
            document.querySelectorAll('.fee-type-checkbox-input:checked').forEach(checkbox => {
                const feeId = checkbox.getAttribute('data-fee-id');
                const amountInput = document.querySelector(`input[name="fee_amounts[${feeId}]"]`);
                if (amountInput && (amountInput.value === '' || parseFloat(amountInput.value) < 0)) {
                    alert('Please enter a valid amount (0 or greater) for all selected fee types.');
                    amountInput.focus();
                    hasErrors = true;
                    return false;
                }
            });
            if (hasErrors) return;
            
            if (confirm('Are you sure you want to generate monthly fee records? Student-specific assignments will override default amounts.')) {
                formAction.value = 'create';
                form.submit();
            }
        });
    }
    
    if (updateBtn) {
        updateBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!sessionSelect.value) {
                alert('Please select a session first.');
                sessionSelect.focus();
                return;
            }
            let hasErrors = false;
            document.querySelectorAll('.fee-type-checkbox-input:checked').forEach(checkbox => {
                const feeId = checkbox.getAttribute('data-fee-id');
                const amountInput = document.querySelector(`input[name="fee_amounts[${feeId}]"]`);
                if (amountInput && (amountInput.value === '' || parseFloat(amountInput.value) < 0)) {
                    alert('Please enter a valid amount (0 or greater) for all selected fee types.');
                    amountInput.focus();
                    hasErrors = true;
                    return false;
                }
            });
            if (hasErrors) return;
            
            if (confirm('Are you sure you want to update fee amounts? Unchecked fee types will be removed.')) {
                formAction.value = 'update';
                form.submit();
            }
        });
    }
    
    if (viewBtn) {
        viewBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!sessionSelect.value) {
                alert('Please select a session first.');
                sessionSelect.focus();
                return;
            }
            formAction.value = 'view';
            form.submit();
        });
    }
    
    function checkFormState() {
        const hasSession = sessionSelect && sessionSelect.value;
        const hasClass = classSelect && classSelect.value;
        const hasMonth = monthSelect && monthSelect.value;
        
        if (createBtn) createBtn.disabled = !(hasSession && hasClass && hasMonth);
        if (updateBtn) updateBtn.disabled = !(hasSession && hasClass && hasMonth);
        if (viewBtn) viewBtn.disabled = !(hasSession && hasClass && hasMonth);
    }
    
    checkFormState();
    
    if (window.location.hash) {
        const element = document.querySelector(window.location.hash);
        if (element) {
            setTimeout(() => {
                element.scrollIntoView({ behavior: 'smooth' });
            }, 100);
        }
    }
});

function openEditModal(feeCardId, amount, remarks, sessionId, classId, month) {
    document.getElementById('edit_fee_card_id').value = feeCardId;
    document.getElementById('edit_amount').value = amount;
    document.getElementById('edit_remarks').value = remarks;
    document.getElementById('edit_session_id').value = sessionId;
    document.getElementById('edit_class_id').value = classId;
    document.getElementById('edit_month').value = month;
    $('#editFeeModal').modal('show');
}

function openDeleteModal(feeCardId, feeType, studentName, sessionId, classId, month) {
    document.getElementById('delete_fee_card_id').value = feeCardId;
    document.getElementById('delete_fee_type').innerText = feeType;
    document.getElementById('delete_student_name').innerText = studentName;
    document.getElementById('delete_session_id').value = sessionId;
    document.getElementById('delete_class_id').value = classId;
    document.getElementById('delete_month').value = month;
    $('#deleteFeeModal').modal('show');
}

function printClass(classSectionKey) {
    var printWindow = window.open('', '_blank');
    var classPrintElement = document.getElementById('print-' + classSectionKey);
    if (classPrintElement) {
        printWindow.document.write('<!DOCTYPE html><html><head><title>Class Fee Report</title></head><body>' + classPrintElement.innerHTML + '</body></html>');
        printWindow.document.close();
        printWindow.onload = function() { printWindow.print(); };
    }
}
</script>
</body>
</html>
<?php
if (isset($conn)) $conn->close();
ob_end_flush();
?>