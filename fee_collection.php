<?php 
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");


// Get student ID from URL
$student_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Fetch student information and current class
$student_info = null;
$current_class = null;
$student_class_id = null;
$fee_cards = [];
$class_fee_types = [];

if ($student_id > 0) {
    // Get student registration details
    $student_query = $conn->query("
        SELECT sr.*, vc.title as village_name 
        FROM student_registration sr 
        LEFT JOIN village_councils vc ON sr.village_council_id = vc.id 
        WHERE sr.id = $student_id
    ");
    $student_info = $student_query->fetch_assoc();
    
    // Get current class (status = 0 means active)
    $class_query = $conn->query("
        SELECT sc.*, c.title as class_name, s.title as session_title, sc.session_id
        FROM student_class sc 
        INNER JOIN classes c ON sc.class_id = c.id 
        INNER JOIN sessions s ON sc.session_id = s.id 
        WHERE sc.student_registration_id = $student_id AND sc.status = 0 
        ORDER BY sc.id DESC LIMIT 1
    ");
    
    if ($class_query->num_rows > 0) {
        $current_class = $class_query->fetch_assoc();
        $student_class_id = $current_class['id'];
        
        // Get fee cards for this student class (only pending ones with due amount > 0)
        $fee_card_query = $conn->query("
            SELECT sfc.*, ft.title as fee_type_title 
            FROM student_fee_card sfc 
            INNER JOIN fee_types ft ON sfc.fee_type_id = ft.id 
            WHERE sfc.student_class_id = $student_class_id 
            AND sfc.status = 'pending'
            ORDER BY sfc.due_date ASC
        ");
        
        while ($row = $fee_card_query->fetch_assoc()) {
            // Calculate paid amount and discount
            $payment_query = $conn->query("
                SELECT SUM(paid_amount) as total_paid, SUM(discount_amount) as total_discount 
                FROM student_fee_payments 
                WHERE fee_card_id = " . $row['id'] . " AND status = 'completed'
            ");
            $payment_data = $payment_query->fetch_assoc();
            
            $row['paid_amount'] = $payment_data['total_paid'] ?? 0;
            $row['total_discount'] = $payment_data['total_discount'] ?? 0;
            $row['due_amount'] = $row['total_amount'] - $row['paid_amount'] - $row['total_discount'];
            
            // Only include rows where due amount is greater than 0
            if ($row['due_amount'] > 0) {
                $fee_cards[] = $row;
            }
        }
        
        // Get fee types available for this class and session
        $fee_type_query = $conn->query("
            SELECT cft.*, ft.title as fee_type_title, ft.type
            FROM class_fee_types cft
            INNER JOIN fee_types ft ON cft.fee_type_id = ft.id
            WHERE cft.class_id = " . $current_class['class_id'] . " 
            AND cft.session_id = " . $current_class['session_id'] . "
            ORDER BY ft.title ASC
        ");
        
        while ($row = $fee_type_query->fetch_assoc()) {
            $class_fee_types[] = $row;
        }
    }
}

// Handle add new fee card
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_fee_card'])) {
    $fee_type_id = intval($_POST['fee_type_id']);
    $amount = floatval($_POST['amount']);
    $month = mysqli_real_escape_string($conn, $_POST['month']); // Format: YYYY-MM
    $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? '');
    
    // Calculate due date as last day of selected month
    $due_date = date('Y-m-t', strtotime($month . '-01'));
    
    // Validate inputs
    $errors = [];
    if ($fee_type_id <= 0) $errors[] = "Please select a fee type";
    if ($amount <= 0) $errors[] = "Amount must be greater than 0";
    if (empty($month)) $errors[] = "Please select a month";
    
    if (empty($errors) && $student_class_id) {
        // Check if a fee card already exists for this fee type and month (in any format)
        $month_formats = [
            $month,
            date('Y-m-d', strtotime($month . '-01')),
            date('F Y', strtotime($month . '-01')),
            date('M Y', strtotime($month . '-01')),
            date('m-Y', strtotime($month . '-01'))
        ];
        $month_formats = array_unique($month_formats);
        
        $exists = false;
        $existing_status = '';
        
        foreach ($month_formats as $format) {
            if (empty($format)) continue;
            
            $check_query = $conn->query("
                SELECT id, status FROM student_fee_card 
                WHERE student_class_id = $student_class_id 
                AND fee_type_id = $fee_type_id 
                AND month = '$format'
            ");
            
            if ($check_query->num_rows > 0) {
                $exists = true;
                $existing_row = $check_query->fetch_assoc();
                $existing_status = $existing_row['status'];
                break;
            }
        }
        
        if ($exists) {
            $status_text = ($existing_status == 'pending') ? 'Pending' : (($existing_status == 'paid') ? 'Paid' : 'Deleted');
            $error_message = "This fee type already exists for the selected month (Status: $status_text)! Cannot add duplicate.";
        } else {
            // No duplicate found, proceed with insert
            $insert_query = $conn->query("
                INSERT INTO student_fee_card 
                (student_class_id, fee_type_id, total_amount, discount_amount, discount_type, 
                 discount_note, due_date, month, session_id, status, remarks, dated, paid_amount) 
                VALUES 
                ($student_class_id, $fee_type_id, $amount, 0, NULL, NULL, 
                 '$due_date', '$month', " . $current_class['session_id'] . ", 'pending', '$remarks', NOW(), 0)
            ");
            
            if ($insert_query) {
                $new_card_id = $conn->insert_id;

                // === GL: Post receivable entry (accrual) ===
                if ($amount > 0 && $new_card_id > 0) {
                    $revenueAcc    = gl_revenue_account_for_fee_type($conn, $fee_type_id);
                    $receivableAcc = gl_receivable_account($conn);
                    $postedBy      = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;

                    if ($revenueAcc > 0 && $receivableAcc > 0) {
                        $glErr = null;
                        $txnId = post_journal_entry($conn, [
                            'entry_date'  => $due_date,
                            'session_id'  => $current_class['session_id'],
                            'description' => "Fee card #$new_card_id created (manual add) — $remarks",
                            'ref_type'    => 'fee_card',
                            'ref_id'      => $new_card_id,
                            'posted_by'   => $postedBy,
                            'idempotency' => 'fee_card_create-' . $new_card_id,
                            'lines'       => [
                                ['account_id' => $receivableAcc, 'debit'  => $amount, 'credit' => 0,
                                 'memo' => "Receivable for fee card #$new_card_id"],
                                ['account_id' => $revenueAcc,    'debit'  => 0, 'credit' => $amount,
                                 'memo' => "Fee revenue — fee_type #$fee_type_id"],
                            ],
                        ], $glErr);
                        if ($txnId <= 0) {
                            error_log("add_fee_card: GL post failed for card #$new_card_id — $glErr");
                        }
                    } else {
                        error_log("add_fee_card: missing GL accounts for fee_type #$fee_type_id");
                    }
                }

                $success_message = "Fee card added successfully!";
                header("Location: fee_collection.php?id=$student_id&add_success=1");
                exit();
            } else {
                $error_message = "Error adding fee card: " . $conn->error;
            }
        }
    } else {
        $error_message = implode("<br>", $errors);
    }
}

// Handle delete fee card request
if (isset($_GET['delete_fee_card'])) {
    $card_id = intval($_GET['delete_fee_card']);
    
    // Fetch card BEFORE any delete (for GL reversal)
    $card_stmt = $conn->prepare("SELECT id, total_amount, fee_type_id FROM student_fee_card WHERE id = ? LIMIT 1");
    $card_stmt->bind_param("i", $card_id);
    $card_stmt->execute();
    $card_data = $card_stmt->get_result()->fetch_assoc();
    $card_stmt->close();

    // Check if there are any payments for this fee card
    $check_payments = $conn->query("SELECT COUNT(*) as count FROM student_fee_payments WHERE fee_card_id = $card_id");
    $payments_count = $check_payments->fetch_assoc()['count'];
    
    if ($payments_count == 0) {
        // No payments, safe to delete
        $conn->query("DELETE FROM student_fee_card WHERE id = $card_id");
        $success_message = "Fee card deleted successfully!";

        // === GL: Reverse the fee card creation entry ===
        if ($card_data && (float)$card_data['total_amount'] > 0) {
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
                $postedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
                $revId = reverse_journal_entry($conn, (int)$origTxn['id'],
                                              "Fee card #$card_id deleted",
                                              $postedBy, $revErr);
                if ($revId <= 0) {
                    error_log("delete_fee_card: reversal failed for card #$card_id — $revErr");
                }
            }
        }
    } else {
        // Has payments, just update status to deleted
        $conn->query("UPDATE student_fee_card SET status = 'deleted' WHERE id = $card_id");
        $success_message = "Fee card marked as deleted (payments exist)!";
    }
    
    header("Location: fee_collection.php?id=$student_id&delete_success=1");
    exit();
}

// Handle restore transaction (reverse GL and restore fee card) — master_account removed
if (isset($_GET['delete_transaction'])) {
    $transaction_id = intval($_GET['delete_transaction']);
    $transaction_started = false;
    
    try {
        $conn->begin_transaction();
        $transaction_started = true;
        
        // Fetch payment transaction details
        $trans_query = $conn->prepare("
            SELECT sfp.*, sfc.student_class_id, sfc.fee_type_id, sfc.total_amount as card_total
            FROM student_fee_payments sfp
            INNER JOIN student_fee_card sfc ON sfp.fee_card_id = sfc.id
            WHERE sfp.id = ? AND sfp.status = 'completed'
            FOR UPDATE
        ");
        $trans_query->bind_param("i", $transaction_id);
        $trans_query->execute();
        $trans_result = $trans_query->get_result();
        
        if ($trans_result->num_rows === 0) {
            throw new Exception("Transaction not found or already restored.");
        }
        $trans_data = $trans_result->fetch_assoc();
        $trans_query->close();
        
        $fee_card_id = intval($trans_data['fee_card_id']);
        $paid_amount = floatval($trans_data['paid_amount']);
        $discount_amount = floatval($trans_data['discount_amount']);
        $student_class_id = intval($trans_data['student_class_id']);
        $card_total = floatval($trans_data['card_total']);
        
        // === GL: Reverse the original payment entry ===
        $origStmt = $conn->prepare("
            SELECT id FROM gl_transactions
            WHERE reference_type = 'fee_payment' AND reference_id = ?
              AND status = 'POSTED' AND reversal_of IS NULL
            ORDER BY id ASC LIMIT 1
        ");
        $origStmt->bind_param("i", $transaction_id);
        $origStmt->execute();
        $origTxn = $origStmt->get_result()->fetch_assoc();
        $origStmt->close();

        if ($origTxn) {
            $revErr = null;
            $postedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
            $revId = reverse_journal_entry($conn, (int)$origTxn['id'],
                                          "Fee payment #$transaction_id restored",
                                          $postedBy, $revErr);
            if ($revId <= 0) {
                throw new Exception("GL reversal failed: " . ($revErr ?? 'unknown'));
            }
        }
        
        // Mark Payment as Reversed (audit trail)
        $update_payment = $conn->prepare("
            UPDATE student_fee_payments 
            SET status = 'reversed', remarks = CONCAT(IFNULL(remarks, ''), ' | Restored on: ', ?)
            WHERE id = ?
        ");
        $restore_note = "Restored on " . date('Y-m-d H:i:s');
        $update_payment->bind_param("si", $restore_note, $transaction_id);
        if (!$update_payment->execute()) {
            throw new Exception("Failed to mark payment as reversed: " . $conn->error);
        }
        $update_payment->close();
        
        // Update Fee Card Status
        $remaining_query = $conn->prepare("
            SELECT COALESCE(SUM(paid_amount), 0) as paid, COALESCE(SUM(discount_amount), 0) as disc
            FROM student_fee_payments
            WHERE fee_card_id = ? AND status = 'completed'
        ");
        $remaining_query->bind_param("i", $fee_card_id);
        $remaining_query->execute();
        $remaining = $remaining_query->get_result()->fetch_assoc();
        $remaining_query->close();
        
        $total_paid = floatval($remaining['paid']);
        $total_disc = floatval($remaining['disc']);
        
        if ($total_paid + $total_disc >= $card_total) {
            $update_card = $conn->prepare("UPDATE student_fee_card SET status = 'paid' WHERE id = ?");
        } else {
            $update_card = $conn->prepare("UPDATE student_fee_card SET status = 'pending' WHERE id = ?");
        }
        $update_card->bind_param("i", $fee_card_id);
        if (!$update_card->execute()) {
            throw new Exception("Failed to update fee card status: " . $conn->error);
        }
        $update_card->close();
        
        $conn->commit();
        $transaction_started = false;
        
        $success_message = "Transaction restored successfully! Amount: " . number_format($paid_amount, 2) . 
                          " reversed from ledger.";
        
        header("Location: fee_collection.php?id=$student_id&restore_success=1");
        exit();
        
    } catch (Exception $e) {
        if ($transaction_started) {
            $conn->rollback();
        }
        
        error_log("Fee restore error (Transaction ID: $transaction_id): " . $e->getMessage());
        $error_message = "Restore failed: " . $e->getMessage();
    }
}

// Handle fee payment submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['process_payment'])) {
    $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method']);
    $transaction_ref = mysqli_real_escape_string($conn, $_POST['transaction_ref'] ?? '');
    $payment_remarks = mysqli_real_escape_string($conn, $_POST['payment_remarks'] ?? '');
    $payment_date = date('Y-m-d H:i:s');
    
    $total_payment = 0;
    $total_discount = 0;
    $processed_ids = [];
    $processed_cards = [];
    
    $transaction_started = false;
    
    try {
        $conn->begin_transaction();
        $transaction_started = true;
        
        // Process each fee card payment
        foreach ($_POST['payment'] as $card_id => $amount) {
            $card_id = intval($card_id);
            $paid_amount = floatval($amount);
            $discount = floatval($_POST['discount'][$card_id] ?? 0);
            $discount_note = mysqli_real_escape_string($conn, $_POST['discount_note'][$card_id] ?? '');
            
            if ($paid_amount <= 0 && $discount <= 0) {
                continue;
            }
            
            // Validate fee card
            $card_check = $conn->prepare("
                SELECT sfc.*, ft.title as fee_type_title 
                FROM student_fee_card sfc 
                INNER JOIN fee_types ft ON sfc.fee_type_id = ft.id 
                WHERE sfc.id = ? AND sfc.student_class_id = ? AND sfc.status = 'pending'
                FOR UPDATE
            ");
            $card_check->bind_param("ii", $card_id, $student_class_id);
            $card_check->execute();
            $card_result = $card_check->get_result();
            
            if ($card_result->num_rows === 0) {
                throw new Exception("Invalid or already processed fee card ID: $card_id");
            }
            $fee_card = $card_result->fetch_assoc();
            $card_check->close();
            
            // Calculate remaining due
            $already_paid_query = $conn->prepare("
                SELECT COALESCE(SUM(paid_amount), 0) as paid, COALESCE(SUM(discount_amount), 0) as disc 
                FROM student_fee_payments 
                WHERE fee_card_id = ? AND status = 'completed'
            ");
            $already_paid_query->bind_param("i", $card_id);
            $already_paid_query->execute();
            $already_paid = $already_paid_query->get_result()->fetch_assoc();
            $already_paid_query->close();
            
            $already_paid_amount = floatval($already_paid['paid']);
            $already_discount_amount = floatval($already_paid['disc']);
            $remaining_due = $fee_card['total_amount'] - $already_paid_amount - $already_discount_amount;
            
            if (($paid_amount + $discount) > $remaining_due + 0.01) {
                throw new Exception("Payment + Discount exceeds remaining due ({$remaining_due}) for fee card: {$fee_card['fee_type_title']}");
            }
            
            // Insert payment record
            $status = 'completed';
            
            $insert_payment = $conn->prepare("
                INSERT INTO student_fee_payments 
                (fee_card_id, paid_amount, discount_amount, discount_type, discount_note, 
                 payment_date, payment_method, transaction_ref, remarks, status, is_advance) 
                VALUES 
                (?, ?, ?, 'fixed', ?, ?, ?, ?, ?, ?, 0)
            ");
            
            $insert_payment->bind_param(
                "iddssssss", 
                $card_id,
                $paid_amount,
                $discount,
                $discount_note,
                $payment_date,
                $payment_method,
                $transaction_ref,
                $payment_remarks,
                $status
            );
            
            if (!$insert_payment->execute()) {
                throw new Exception("Failed to insert payment record: " . $conn->error);
            }
            
            $payment_id = $conn->insert_id;
            $insert_payment->close();
            $processed_ids[] = $payment_id;
            $total_payment += $paid_amount;
            $total_discount += $discount;
            
            $processed_cards[] = [
                'card_id' => $card_id,
                'payment_id' => $payment_id,
                'fee_type' => $fee_card['fee_type_title'],
                'paid' => $paid_amount,
                'discount' => $discount,
                'total_amount' => $fee_card['total_amount'],
                'month' => $fee_card['month'],
                'fee_type_id' => $fee_card['fee_type_id']
            ];
            
            // === GL: Post payment entry (per card, Option 2) ===
            $cashAcc       = gl_cash_account_for_method($conn, $payment_method);
            $receivableAcc = gl_receivable_account($conn);
            $discountAcc   = gl_discount_account($conn);
            $postedBy      = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;

            if ($cashAcc <= 0 || $receivableAcc <= 0) {
                throw new Exception("GL account missing (cash=$cashAcc, receivable=$receivableAcc).");
            }

            $glLines = [];
            $glCreditTotal = 0;

            // Debit: cash received
            if ($paid_amount > 0) {
                $glLines[] = [
                    'account_id' => $cashAcc,
                    'debit'      => $paid_amount,
                    'credit'     => 0,
                    'memo'       => "Payment for card #$card_id ({$fee_card['fee_type_title']}) — $payment_method",
                ];
                $glCreditTotal += $paid_amount;
            }

            // Debit: discount given (contra-revenue)
            if ($discount > 0) {
                if ($discountAcc > 0) {
                    $glLines[] = [
                        'account_id' => $discountAcc,
                        'debit'      => $discount,
                        'credit'     => 0,
                        'memo'       => "Discount on card #$card_id — $discount_note",
                    ];
                    $glCreditTotal += $discount;
                } else {
                    // No 4090 account — absorb discount silently. Log it.
                    error_log("process_payment: discount $discount on card #$card_id with no 4090 account");
                }
            }

            // Credit: clear receivable
            $glLines[] = [
                'account_id' => $receivableAcc,
                'debit'      => 0,
                'credit'     => $glCreditTotal,
                'memo'       => "Clear receivable — card #$card_id",
            ];

            $glErr = null;
            $txnId = post_journal_entry($conn, [
                'entry_date'  => date('Y-m-d'),
                'session_id'  => $current_class['session_id'],
                'description' => "Fee payment — card #$card_id ({$fee_card['fee_type_title']})",
                'ref_type'    => 'fee_payment',
                'ref_id'      => $payment_id,
                'posted_by'   => $postedBy,
                'idempotency' => 'fee_payment-' . $payment_id,
                'lines'       => $glLines,
            ], $glErr);

            if ($txnId <= 0) {
                throw new Exception("GL post failed for card #$card_id: " . ($glErr ?? 'unknown'));
            }
            
            // Update fee card status if fully paid
            $new_total_paid = $already_paid_amount + $paid_amount;
            $new_total_discount = $already_discount_amount + $discount;
            
            if ($new_total_paid + $new_total_discount >= $fee_card['total_amount'] - 0.01) {
                $update_card = $conn->prepare("UPDATE student_fee_card SET status = 'paid' WHERE id = ?");
                $update_card->bind_param("i", $card_id);
                $update_card->execute();
                $update_card->close();
            }
        }
        
        // Commit
        $conn->commit();
        $transaction_started = false;
        
        $success_message = "Payment processed successfully! Amount: " . number_format($total_payment, 2) . 
                          ", Discount: " . number_format($total_discount, 2);
        
        $receipt_id = "REC-" . date('Ymd') . "-" . rand(1000, 9999);
        $ids_param = implode(',', $processed_ids);
        
        header("Location: fee_collection.php?id=$student_id&success=1&receipt=$receipt_id&amount=$total_payment&discount=$total_discount&ids=$ids_param");
        exit();
        
    } catch (Exception $e) {
        if ($transaction_started) {
            $conn->rollback();
        }
        
        error_log("Fee payment error (Student ID: $student_id): " . $e->getMessage());
        $error_message = "Payment failed: " . $e->getMessage();
    }
}

// Handle Edit Fee Transaction (Reversal Method)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_fee_transaction'])) {
    $transaction_id = intval($_POST['transaction_id']);
    $new_paid_amount = floatval($_POST['new_paid_amount']);
    $new_discount = floatval($_POST['new_discount']);
    $new_payment_method = mysqli_real_escape_string($conn, $_POST['new_payment_method']);
    $new_transaction_ref = mysqli_real_escape_string($conn, $_POST['new_transaction_ref'] ?? '');
    $new_remarks = mysqli_real_escape_string($conn, $_POST['new_remarks'] ?? '');
    $payment_date = date('Y-m-d H:i:s');
    
    $transaction_started = false;
    
    try {
        $conn->begin_transaction();
        $transaction_started = true;
        
        // Validate original transaction
        $orig_query = $conn->prepare("
            SELECT sfp.*, sfc.student_class_id, sfc.fee_type_id, sfc.total_amount as card_total
            FROM student_fee_payments sfp
            INNER JOIN student_fee_card sfc ON sfp.fee_card_id = sfc.id
            WHERE sfp.id = ? AND sfp.status = 'completed'
            FOR UPDATE
        ");
        $orig_query->bind_param("i", $transaction_id);
        $orig_query->execute();
        $orig_result = $orig_query->get_result();
        
        if ($orig_result->num_rows === 0) {
            throw new Exception("Original transaction not found or already reversed.");
        }
        $original = $orig_result->fetch_assoc();
        $orig_query->close();
        
        $old_paid = floatval($original['paid_amount']);
        $old_discount = floatval($original['discount_amount']);
        $fee_card_id = intval($original['fee_card_id']);
        $student_class_id = intval($original['student_class_id']);
        $card_total = floatval($original['card_total']);
        
        // === GL: Reverse the original payment entry ===
        $origGlStmt = $conn->prepare("
            SELECT id FROM gl_transactions
            WHERE reference_type = 'fee_payment' AND reference_id = ?
              AND status = 'POSTED' AND reversal_of IS NULL
            ORDER BY id ASC LIMIT 1
        ");
        $origGlStmt->bind_param("i", $transaction_id);
        $origGlStmt->execute();
        $origGlTxn = $origGlStmt->get_result()->fetch_assoc();
        $origGlStmt->close();

        if ($origGlTxn) {
            $revErr = null;
            $postedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
            $revId = reverse_journal_entry($conn, (int)$origGlTxn['id'],
                                          "Fee payment #$transaction_id edited",
                                          $postedBy, $revErr);
            if ($revId <= 0) {
                throw new Exception("GL reversal failed: " . ($revErr ?? 'unknown'));
            }
        }
        
        // Mark original payment as reversed
        $update_orig_payment = $conn->prepare("
            UPDATE student_fee_payments 
            SET status = 'reversed', remarks = CONCAT(IFNULL(remarks, ''), ' | Reversed on edit: ', ?)
            WHERE id = ?
        ");
        $edit_note = "Edited on " . date('Y-m-d H:i:s');
        $update_orig_payment->bind_param("si", $edit_note, $transaction_id);
        if (!$update_orig_payment->execute()) {
            throw new Exception("Failed to mark original payment as reversed: " . $conn->error);
        }
        $update_orig_payment->close();
        
        // Validate new amount against card total
        $existing_query = $conn->prepare("
            SELECT COALESCE(SUM(paid_amount), 0) as paid, COALESCE(SUM(discount_amount), 0) as disc
            FROM student_fee_payments
            WHERE fee_card_id = ? AND status = 'completed'
        ");
        $existing_query->bind_param("i", $fee_card_id);
        $existing_query->execute();
        $existing = $existing_query->get_result()->fetch_assoc();
        $existing_query->close();
        
        $existing_paid = floatval($existing['paid']);
        $existing_disc = floatval($existing['disc']);
        
        if (($existing_paid + $new_paid_amount + $existing_disc + $new_discount) > $card_total + 0.01) {
            throw new Exception("New payment + discount exceeds fee card total.");
        }
        
        // Insert NEW corrected payment
        $new_status = 'completed';
        $new_remarks_text = "Edited transaction (was ID: $transaction_id)" . 
                            ($new_remarks !== '' ? " | $new_remarks" : '');
        
        $insert_new = $conn->prepare("
            INSERT INTO student_fee_payments 
            (fee_card_id, paid_amount, discount_amount, discount_type, discount_note, 
             payment_date, payment_method, transaction_ref, remarks, status, is_advance) 
            VALUES 
            (?, ?, ?, 'fixed', '', ?, ?, ?, ?, ?, 0)
        ");
        
        $insert_new->bind_param(
            "iddsssss",
            $fee_card_id,
            $new_paid_amount,
            $new_discount,
            $payment_date,
            $new_payment_method,
            $new_transaction_ref,
            $new_remarks_text,
            $new_status
        );
        
        if (!$insert_new->execute()) {
            throw new Exception("Failed to insert new payment record: " . $conn->error);
        }
        
        $new_payment_id = $conn->insert_id;
        $insert_new->close();
        
        // === GL: Post new corrected payment entry ===
        $cashAcc       = gl_cash_account_for_method($conn, $new_payment_method);
        $receivableAcc = gl_receivable_account($conn);
        $discountAcc   = gl_discount_account($conn);
        $postedBy      = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;

        if ($cashAcc <= 0 || $receivableAcc <= 0) {
            throw new Exception("GL account missing for edit (cash=$cashAcc, receivable=$receivableAcc).");
        }

        $glLines = [];
        $glCreditTotal = 0;

        if ($new_paid_amount > 0) {
            $glLines[] = [
                'account_id' => $cashAcc,
                'debit'      => $new_paid_amount,
                'credit'     => 0,
                'memo'       => "Corrected payment — card #$fee_card_id ($new_payment_method)",
            ];
            $glCreditTotal += $new_paid_amount;
        }
        if ($new_discount > 0 && $discountAcc > 0) {
            $glLines[] = [
                'account_id' => $discountAcc,
                'debit'      => $new_discount,
                'credit'     => 0,
                'memo'       => "Corrected discount — card #$fee_card_id",
            ];
            $glCreditTotal += $new_discount;
        }
        $glLines[] = [
            'account_id' => $receivableAcc,
            'debit'      => 0,
            'credit'     => $glCreditTotal,
            'memo'       => "Corrected receivable clearance — card #$fee_card_id",
        ];

        $glErr = null;
        $txnId = post_journal_entry($conn, [
            'entry_date'  => date('Y-m-d'),
            'session_id'  => $current_class['session_id'],
            'description' => "Corrected fee payment — was #$transaction_id, now #$new_payment_id",
            'ref_type'    => 'fee_payment',
            'ref_id'      => $new_payment_id,
            'posted_by'   => $postedBy,
            'idempotency' => 'fee_payment_edit-' . $new_payment_id,
            'lines'       => $glLines,
        ], $glErr);

        if ($txnId <= 0) {
            throw new Exception("GL post failed for edited payment: " . ($glErr ?? 'unknown'));
        }
        
        // Update Fee Card Status
        $total_paid_query = $conn->prepare("
            SELECT COALESCE(SUM(paid_amount), 0) as paid, COALESCE(SUM(discount_amount), 0) as disc
            FROM student_fee_payments
            WHERE fee_card_id = ? AND status = 'completed'
        ");
        $total_paid_query->bind_param("i", $fee_card_id);
        $total_paid_query->execute();
        $total_paid = $total_paid_query->get_result()->fetch_assoc();
        $total_paid_query->close();
        
        $final_paid = floatval($total_paid['paid']);
        $final_disc = floatval($total_paid['disc']);
        
        if ($final_paid + $final_disc >= $card_total - 0.01) {
            $update_card = $conn->prepare("UPDATE student_fee_card SET status = 'paid' WHERE id = ?");
        } else {
            $update_card = $conn->prepare("UPDATE student_fee_card SET status = 'pending' WHERE id = ?");
        }
        $update_card->bind_param("i", $fee_card_id);
        $update_card->execute();
        $update_card->close();
        
        $conn->commit();
        $transaction_started = false;
        
        $success_message = "Fee transaction edited successfully! " . 
                          "Paid: " . number_format($old_paid, 2) . " → " . number_format($new_paid_amount, 2) . 
                          " | Discount: " . number_format($old_discount, 2) . " → " . number_format($new_discount, 2);
        
        header("Location: fee_collection.php?id=$student_id&edit_success=1&trans_id=$new_payment_id");
        exit();
        
    } catch (Exception $e) {
        if ($transaction_started) {
            $conn->rollback();
        }
        
        error_log("Fee edit error (Transaction ID: $transaction_id): " . $e->getMessage());
        $error_message = "Edit failed: " . $e->getMessage();
    }
}

// Get recent transactions
$recent_transactions = [];
if ($student_class_id) {
    $trans_query = $conn->query("
        SELECT sfp.*, sfc.due_date, ft.title as fee_type_title, sfc.id as fee_card_id, sfc.month
        FROM student_fee_payments sfp 
        INNER JOIN student_fee_card sfc ON sfp.fee_card_id = sfc.id 
        INNER JOIN fee_types ft ON sfc.fee_type_id = ft.id 
        WHERE sfc.student_class_id = $student_class_id 
        ORDER BY sfp.id DESC LIMIT 20
    ");
    while ($row = $trans_query->fetch_assoc()) {
        $recent_transactions[] = $row;
    }
}

// Function to get month-year from due date (format: Jan-26)
function getMonthYearFromDueDate($due_date) {
    $timestamp = strtotime($due_date);
    return date('M-y', $timestamp);
}

// Function to format due date (format: 15 Jan 2025)
function formatDueDate($due_date) {
    return date('d M Y', strtotime($due_date));
}

// Function to generate month options
function getMonthOptions($selected = '') {
    $current_year = date('Y');
    $current_month = date('m');
    
    for ($i = 0; $i < 24; $i++) {
        $timestamp = mktime(0, 0, 0, $current_month + $i, 1, $current_year);
        $value = date('Y-m', $timestamp);
        $label = date('F Y', $timestamp);
        $selected_attr = ($selected == $value) ? 'selected' : '';
        echo "<option value=\"$value\" $selected_attr>$label</option>";
    }
}

// Calculate total due
$total_due = 0;
foreach ($fee_cards as $card) {
    $total_due += $card['due_amount'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <title>Fee Collection - Madrasa Management System</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
  <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
  <link rel="stylesheet" href="css/mystyle.css" />
  
  <style>
    .student-profile-header { background: white; color: black; padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
    .student-profile-header h2 { font-size: 1.3rem; margin-bottom: 0.2rem; }
    .student-profile-header h5 { font-size: 0.9rem; margin-bottom: 0.2rem; opacity: 0.9; }
    .profile-info-badge { padding: 3px 8px; border-radius: 15px; background: rgba(255,255,255,0.15); margin-right: 8px; display: inline-block; font-size: 0.7rem; margin-bottom: 2px; }
    .profile-info-badge i { margin-right: 3px; font-size: 0.65rem; }
    .fee-table { background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    .fee-table thead th { background: #3b3a85; color: white; border-bottom: none; padding: 12px 8px; font-size: 13px; font-weight: 500; white-space: nowrap; }
    .fee-table tbody tr { transition: all 0.3s ease; }
    .fee-table tbody tr:hover { background: #f8f9fa; }
    .fee-table tbody td { padding: 10px 8px; font-size: 13px; vertical-align: middle; }
    .fee-table tfoot tr { background: #e8f5e9; font-weight: 600; border-top: 2px solid #dee2e6; }
    .fee-table tfoot td { padding: 10px 8px; font-size: 13px; font-weight: 600; }
    .amount-input { font-size: 12px; padding: 6px 8px; border: 2px solid #e0e0e0; border-radius: 4px; transition: all 0.3s ease; width: 85px; height: 32px; }
    .amount-input:focus { border-color: #4285F4; box-shadow: 0 0 0 3px rgba(66,133,244,0.1); }
    .summary-card { background: #3b3a85; color: white; padding: 0; border-radius: 10px; margin-bottom: 25px; border: none; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
    .summary-card .card-header { background: rgba(0,0,0,0.1); border-bottom: 1px solid rgba(255,255,255,0.2); padding: 12px 20px; border-top-left-radius: 10px; border-top-right-radius: 10px; }
    .summary-card .card-header h5 { font-size: 16px; font-weight: 600; margin: 0; }
    .summary-card .card-body { padding: 20px; }
    .transaction-table { background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    .transaction-table thead th { background: #f8f9fa; color: #495057; border-bottom: 2px solid #dee2e6; padding: 12px 10px; font-size: 13px; font-weight: 600; white-space: nowrap; }
    .transaction-table tbody td { padding: 10px; font-size: 13px; vertical-align: middle; }
    .receipt-btn { background: none; border: 1px solid #4285F4; color: #4285F4; padding: 4px 8px; border-radius: 4px; transition: all 0.3s ease; font-size: 12px; }
    .receipt-btn:hover { background: #4285F4; color: white; }
    .badge-pending { background: #ffc107; color: #000; font-size: 11px; padding: 4px 8px; }
    .badge-paid { background: #28a745; color: #fff; font-size: 11px; padding: 4px 8px; }
    .cleared-badge { background: #28a745; color: white; padding: 4px 8px; border-radius: 20px; font-weight: 500; display: inline-block; font-size: 11px; }
    .cleared-badge i { margin-right: 4px; font-size: 10px; }
    .balance-amount { font-weight: 600; font-size: 13px; }
    .amount-due { color: #dc3545; font-weight: 600; font-size: 13px; }
    .amount-paid { color: #28a745; font-weight: 600; font-size: 13px; }
    .payment-summary-item { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.15); }
    .payment-summary-item:last-child { border-bottom: none; }
    .payment-summary-label { font-size: 13px; font-weight: 500; }
    .payment-summary-value { font-size: 18px; font-weight: 700; }
    .month-badge { background: #e9ecef; color: #495057; padding: 4px 8px; border-radius: 15px; font-size: 11px; font-weight: 600; display: inline-block; margin-right: 5px; }
    .container-custom { max-width: 1400px; margin: 0 auto; padding: 20px; }
    .due-date-badge { color: #6c757d; padding: 2px 6px; font-size: 10px; font-weight: 500; }
    .due-date-badge.overdue { color: #dc3545; font-weight: 600; }
    .discount-section { background: #fff3cd; border-left: 4px solid #ffc107; padding: 8px 12px; margin-bottom: 15px; font-size: 12px; border-radius: 4px; }
    .discount-note-input { font-size: 11px; padding: 4px 6px; margin-top: 5px; width: 100px; }
    .checkbox-col { width: 40px; text-align: center; }
    .print-selected-btn { margin-left: 10px; font-size: 12px; padding: 4px 10px; }
    .transaction-checkbox { cursor: pointer; width: 16px; height: 16px; }
    .action-col { width: 35px; text-align: center; }
    .month-date-cell { min-width: 140px; }
    .col-md-8 { flex: 0 0 auto; width: 80%; }
    .col-md-4 { flex: 0 0 auto; width: 20%; }
    .payment-form-label { font-size: 12px; font-weight: 600; margin-bottom: 4px; color: #495057; }
    .payment-form-control { font-size: 13px; padding: 6px 10px; border-radius: 4px; }
    .btn-process { font-size: 14px; padding: 10px; font-weight: 600; }
    .transactions-header { background: #6c757d; color: white; padding: 12px 20px; border-top-left-radius: 10px; border-top-right-radius: 10px; }
    .transactions-header h5 { font-size: 16px; font-weight: 600; margin: 0; }
    .transactions-header .btn-light { font-size: 12px; padding: 4px 10px; }
    .text-amount { font-weight: 600; font-size: 13px; }
    .card-header.bg-dark { background: #ffffff !important; padding: 12px 20px; }
    .card-header.bg-dark h5 { font-size: 16px; color: black; font-weight: 600; margin: 0; }
    .card-header.bg-secondary { background: #3b3a85 !important; padding: 12px 20px; }
    .card-header.bg-secondary h5 { font-size: 16px; font-weight: 600; margin: 0; }
    .modal-form-label { font-size: 12px; font-weight: 600; margin-bottom: 4px; color: #495057; }
    .modal-form-control, .modal-form-select { font-size: 13px; padding: 6px 10px; border-radius: 4px; }
    .btn-add-fee { background: #28a745; color: white; border: none; padding: 6px 15px; font-size: 13px; font-weight: 600; border-radius: 4px; transition: all 0.3s ease; margin-left: 15px; }
    .btn-add-fee:hover { background: #218838; color: white; }
    .btn-add-submit { background: #007bff; color: white; border: none; padding: 8px 20px; font-size: 13px; font-weight: 600; border-radius: 4px; }
    .btn-add-submit:hover { background: #0069d9; color: white; }
    .btn-cancel { background: #6c757d; color: white; border: none; padding: 8px 20px; font-size: 13px; font-weight: 600; border-radius: 4px; margin-left: 10px; }
    .btn-cancel:hover { background: #5a6268; color: white; }
    .select2-container--default .select2-selection--single { height: 35px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 35px; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 33px; }
    .select2-dropdown { font-size: 13px; }
    .due-date-message { font-size: 11px; color: #0d6efd; margin-top: 4px; }
    .due-date-message i { margin-right: 3px; }
    .restore-btn { color: #28a745; cursor: pointer; transition: all 0.3s ease; font-size: 14px; background: none; border: none; }
    .restore-btn:hover { color: #218838; transform: scale(1.1); }
    .restore-message { background: #d4edda; color: #155724; padding: 8px 15px; border-radius: 4px; margin-bottom: 15px; border-left: 4px solid #28a745; }
    .modal-header { background: #3b3a85; color: white; padding: 12px 20px; }
    .modal-header .btn-close { filter: brightness(0) invert(1); }
    .modal-title { font-size: 16px; font-weight: 600; }
    .modal-body { padding: 20px; }
    .modal-footer { padding: 12px 20px; background: #f8f9fa; }
  </style>
</head>
<body>
<div class="container-custom">
   
<style>
.dashboard-btn { background-color:#4CAF50; color:white; text-decoration:none; border-radius:5px; font-weight:bold; }
.dashboard-btn:hover { background-color:#45a049; }
</style>

  <?php if (isset($_GET['edit_success'])): ?>
  <div class="alert alert-success alert-dismissible fade show">
    <i class="fas fa-check-circle"></i> Fee transaction edited successfully!
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    <?php if (!empty($_GET['trans_id'])): ?>
    <button onclick="window.open('print_receipt.php?id=<?php echo $_GET['trans_id']; ?>&auto_print=1', '_blank')" class="btn btn-sm btn-success float-end">
      <i class="fas fa-print"></i> Print New Receipt
    </button>
    <?php endif; ?>
  </div>
<?php endif; ?>

  <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
      <i class="fas fa-check-circle"></i> Payment processed successfully! 
      Amount: <?php echo number_format($_GET['amount'], 2); ?> | 
      Discount: <?php echo number_format($_GET['discount'] ?? 0, 2); ?> | 
      Receipt #: <?php echo $_GET['receipt']; ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      <?php if (!empty($_GET['ids'])): ?>
      <button onclick="window.open('print_receipt.php?ids=<?php echo $_GET['ids']; ?>&auto_print=1', '_blank')" class="btn btn-sm btn-success float-end">
        <i class="fas fa-print"></i> Print Receipt
      </button>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  
  <?php if (isset($_GET['delete_success'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
      <i class="fas fa-check-circle"></i> Fee card deleted successfully!
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>
  
  <?php if (isset($_GET['add_success'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
      <i class="fas fa-check-circle"></i> Fee card added successfully!
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>
  
  <?php if (isset($_GET['restore_success'])): ?>
  <div class="alert alert-success alert-dismissible fade show restore-message">
    <i class="fas fa-undo-alt"></i> Transaction restored successfully! Ledger entry reversed and fee card restored.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>
  
  <?php if (isset($error_message)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
      <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>
  
  <?php if ($student_info): ?>
  
  <!-- Student Profile Header -->
  <div class="student-profile-header">
    <div class="d-flex align-items-center justify-content-between">
      <div>
        <h2><i class="fas fa-user-graduate me-1"></i><?php echo htmlspecialchars($student_info['name']); ?>
              <small style="font-size:11px !important;">S/O: <?php echo htmlspecialchars($student_info['father_name']); ?></small>
        </h2>
      </div>
      <div>
        <a href="advance_fee_payment.php?id=<?php echo $student_id; ?>" class="btn btn-warning btn-sm py-1 px-2" style="font-size:0.75rem;color:white;">
          <i class="fas fa-forward"></i> Advance
        </a>
         <a href="index.php" class="btn btn-success btn-sm py-1 px-2" style="font-size:0.75rem;">
          <i class="fas fa-home"></i> Dashboard
        </a>
      </div>
    </div>
    
    <div class="mt-2 d-flex flex-wrap align-items-center">
      <span class="profile-info-badge">
        <i class="fas fa-id-card"></i> <?php echo htmlspecialchars($student_info['reg_no']); ?>
      </span>
      
      <?php if ($current_class): ?>
      <span class="profile-info-badge">
        <i class="fas fa-book"></i> <?php echo htmlspecialchars($current_class['class_name']); ?>
      </span>
      <span class="profile-info-badge">
        <i class="fas fa-calendar"></i> <?php echo htmlspecialchars($current_class['session_title']); ?>
      </span>
      <?php endif; ?>
      
      <span class="profile-info-badge">
        <i class="fas fa-phone"></i> <?php echo htmlspecialchars($student_info['mobile'] ?? 'N/A'); ?>
      </span>
      
      <span class="profile-info-badge">
        <i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($student_info['guardian_name'] ?? 'N/A'); ?>
      </span>
    </div>
  </div>
  
  <?php if ($current_class): ?>
  
  <!-- Add Fee Card Modal -->
  <div class="modal fade" id="addFeeModal" tabindex="-1" aria-labelledby="addFeeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="addFeeModalLabel">
            <i class="fas fa-plus-circle me-2"></i>Add New Fee Card
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="POST" action="" id="addFeeForm">
          <div class="modal-body">
            <p class="text-muted small">Create a new fee card for <?php echo htmlspecialchars($current_class['class_name']); ?> - <?php echo htmlspecialchars($current_class['session_title']); ?></p>
            
            <div class="row g-3">
              <div class="col-md-6">
                <label class="modal-form-label">Fee Type *</label>
                <select name="fee_type_id" id="fee_type_id" class="form-select modal-form-select" required>
                  <option value="">Select Fee Type</option>
                  <?php foreach ($class_fee_types as $fee_type): ?>
                  <option value="<?php echo $fee_type['fee_type_id']; ?>" data-amount="<?php echo $fee_type['amount']; ?>">
                    <?php echo htmlspecialchars($fee_type['fee_type_title']); ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="modal-form-label">Amount *</label>
                <input type="number" name="amount" id="amount" class="form-control modal-form-control" step="0.01" min="0" required>
              </div>
              <div class="col-md-12">
                <label class="modal-form-label">Month *</label>
                <select name="month" id="month" class="form-select modal-form-select" required>
                  <option value="">Select Month</option>
                  <?php getMonthOptions(); ?>
                </select>
                <div id="dueDateDisplay" class="due-date-message"></div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
              <i class="fas fa-times"></i> Cancel
            </button>
            <button type="submit" name="add_fee_card" class="btn btn-primary">
              <i class="fas fa-save"></i> Save Fee Card
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Edit Fee Transaction Modal -->
<div class="modal fade" id="editFeeModal" tabindex="-1" aria-labelledby="editFeeModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background: #ffc107;">
        <h5 class="modal-title" id="editFeeModalLabel">
          <i class="fas fa-edit me-2"></i>Edit Fee Transaction (Reversal Method)
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST" action="" id="editFeeForm">
        <div class="modal-body">
          <input type="hidden" name="transaction_id" id="edit_transaction_id" value="">
          
          <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i> 
            <strong>Reversal Method:</strong> This will reverse the original transaction and create a new one. 
          </div>
          
          <div class="row g-3">
            <div class="col-md-6">
              <label class="modal-form-label">Original Amount *</label>
              <input type="text" id="edit_original_amount" class="form-control modal-form-control" readonly disabled>
            </div>
            <div class="col-md-6">
              <label class="modal-form-label">Original Discount *</label>
              <input type="text" id="edit_original_discount" class="form-control modal-form-control" readonly disabled>
            </div>
            <div class="col-md-6">
              <label class="modal-form-label">New Payment Amount *</label>
              <input type="number" name="new_paid_amount" id="edit_new_amount" class="form-control modal-form-control" step="0.01" min="0" required>
            </div>
            <div class="col-md-6">
              <label class="modal-form-label">New Discount *</label>
              <input type="number" name="new_discount" id="edit_new_discount" class="form-control modal-form-control" step="0.01" min="0" value="0">
            </div>
            <div class="col-md-6">
              <label class="modal-form-label">Payment Method *</label>
              <select name="new_payment_method" id="edit_payment_method" class="form-select modal-form-select" required>
                <option value="">Select Method</option>
                <option value="cash" selected>Cash</option>
                <option value="bank_transfer">Bank Transfer</option>
                <option value="cheque">Cheque</option>
                <option value="online">Online Payment</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="modal-form-label">Transaction Ref</label>
              <input type="text" name="new_transaction_ref" id="edit_transaction_ref" class="form-control modal-form-control" placeholder="Ref # (optional)">
            </div>
            <div class="col-md-12">
              <label class="modal-form-label">Remarks</label>
              <textarea name="new_remarks" id="edit_remarks" class="form-control modal-form-control" rows="2" placeholder="Edit remarks (optional)"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
            <i class="fas fa-times"></i> Cancel
          </button>
          <button type="submit" name="edit_fee_transaction" class="btn btn-warning">
            <i class="fas fa-sync-alt"></i> Reverse & Edit
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
  
  <!-- Fee Payment Form -->
  <form method="POST" action="" id="feePaymentForm">
    <div class="row">
      <div class="col-md-8">
        <div class="card mb-4">
          <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-money-check-alt"></i> Pending Fee Cards</h5>
            <button type="button" class="btn-add-fee" data-bs-toggle="modal" data-bs-target="#addFeeModal">
              <i class="fas fa-plus"></i> ADD NEW FEES
            </button>
          </div>
          <div class="card-body p-0">
            <?php if (empty($fee_cards)): ?>
              <div class="alert alert-info m-3">
                <i class="fas fa-info-circle"></i> No pending fee cards found for this student.
              </div>
            <?php else: ?>
            <div class="table-responsive">
              <table class="table fee-table mb-0">
                <thead>
                  <tr>
                    <th class="action-col"></th>
                    <th>Type</th>
                    <th>Month (Due Date)</th>
                    <th>Total</th>
                    <th>Paid</th>
                    <th>Dis</th>
                    <th>Due</th>
                    <th>Payment</th>
                    <th>Add Dis</th>
                    <th>Balance</th>
                  </tr>
                </thead>
                <tbody>
                  <?php 
                  $total_fee = 0;
                  $total_paid = 0;
                  $total_discount_given = 0;
                  $total_due = 0;
                  ?>
                  
                  <?php foreach ($fee_cards as $index => $card): 
                    $total_fee += $card['total_amount'];
                    $total_paid += $card['paid_amount'];
                    $total_discount_given += $card['total_discount'];
                    $due = $card['due_amount'];
                    $total_due += $due;
                    
                    $month_year = getMonthYearFromDueDate($card['due_date']);
                    $formatted_date = formatDueDate($card['due_date']);
                    
                    $is_overdue = (strtotime($card['due_date']) < time()) && ($due > 0);
                  ?>
                  <tr id="fee-row-<?php echo $card['id']; ?>">
                    <td class="action-col">
                      <a href="javascript:void(0);" onclick="confirmDelete(<?php echo $card['id']; ?>)" class="delete-btn" title="Delete Fee Card">
                        <i class="fas fa-trash-alt"></i>
                      </a>
                    </td>
                    <td>
                      <strong><?php echo htmlspecialchars($card['fee_type_title']); ?></strong>
                    </td>
                    <td class="month-date-cell">
                      <span class="month-badge"><?php echo $month_year; ?></span>
                      <span class="due-date-badge <?php echo $is_overdue ? 'overdue' : ''; ?>">
                        <i class="fas fa-calendar-alt"></i> <?php echo $formatted_date; ?>
                        <?php if ($is_overdue): ?>
                          <span class="badge bg-danger ms-1">Overdue</span>
                        <?php endif; ?>
                      </span>
                    </td>
                    <td class="text-amount"><?php echo number_format($card['total_amount'], 2); ?></td>
                    <td class="text-success"><?php echo number_format($card['paid_amount'], 2); ?></td>
                    <td class="text-warning"><?php echo number_format($card['total_discount'], 2); ?></td>
                    <td class="amount-due"><?php echo number_format($due, 2); ?></td>
                    <td>
                      <input type="number" 
                             name="payment[<?php echo $card['id']; ?>]" 
                             class="form-control amount-input payment-amount" 
                             data-due="<?php echo $due; ?>" 
                             data-card-id="<?php echo $card['id']; ?>"
                             step="0.01" 
                             min="0" 
                             max="<?php echo $due; ?>"
                             placeholder="Amount">
                    </td>
                    <td>
                      <input type="number" 
                             name="discount[<?php echo $card['id']; ?>]" 
                             class="form-control amount-input discount-input" 
                             data-card-id="<?php echo $card['id']; ?>"
                             step="0.01" 
                             min="0" 
                             max="<?php echo $due; ?>"
                             placeholder="Discount">
                    </td>
                    <td>
                      <span id="balance-<?php echo $card['id']; ?>" class="balance-amount fw-bold">
                        <?php if ($due <= 0): ?>
                          <span class="cleared-badge">
                            <i class="fas fa-check-circle"></i> Cleared
                          </span>
                        <?php else: ?>
                          <span id="balance-value-<?php echo $card['id']; ?>"><?php echo number_format($due, 2); ?></span>
                        <?php endif; ?>
                      </span>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
                <tfoot>
                  <tr>
                    <td colspan="2" class="text-end fw-bold">TOTALS:</td>
                    <td class="fw-bold"></td>
                    <td class="fw-bold" id="totalFeeFooter"><?php echo number_format($total_fee, 2); ?></td>
                    <td class="fw-bold text-success" id="totalPaidFooter"><?php echo number_format($total_paid, 2); ?></td>
                    <td class="fw-bold text-warning" id="totalDiscountFooter"><?php echo number_format($total_discount_given, 2); ?></td>
                    <td class="fw-bold text-danger" id="totalDueFooter"><?php echo number_format($total_due, 2); ?></td>
                    <td></td>
                    <td></td>
                    <td class="fw-bold" id="totalBalanceFooter"><?php echo number_format($total_due, 2); ?></td>
                  </tr>
                </tfoot>
              </table>
            </div>
            
            <div class="row p-3">
              <div class="col-md-4">
                <div class="form-group">
                  <label class="payment-form-label">Payment Method *</label>
                  <select name="payment_method" class="form-select payment-form-control" required>
                    <option value="">Select Method</option>
                    <option value="cash" selected>Cash</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="cheque">Cheque</option>
                    <option value="online">Online Payment</option>
                  </select>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-group">
                  <label class="payment-form-label">Transaction Ref</label>
                  <input type="text" name="transaction_ref" class="form-control payment-form-control" placeholder="Ref # (optional)">
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-group">
                  <label class="payment-form-label">Remarks</label>
                  <input type="text" name="payment_remarks" class="form-control payment-form-control" placeholder="Remarks (optional)">
                </div>
              </div>
            </div>
            
            <div class="p-3">
              <button type="submit" name="process_payment" class="btn btn-success btn-process w-100">
                <i class="fas fa-check-circle"></i> Process Payment
              </button>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      
      <div class="col-md-4">
        <div class="card summary-card mb-4">
          <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Payment Summary</h5>
          </div>
          <div class="card-body">
            <div class="payment-summary-item">
              <span class="payment-summary-label">Total Payment:</span>
              <strong class="payment-summary-value" id="selectedPayment">0.00</strong>
            </div>
            
            <div class="payment-summary-item">
              <span class="payment-summary-label">Total Discount:</span>
              <strong class="payment-summary-value text-warning" id="selectedDiscount">0.00</strong>
            </div>
            
            <hr class="border-white opacity-25 my-2">
            
            <div class="payment-summary-item">
              <span class="payment-summary-label fs-6">Remaining:</span><br>
              <strong class="payment-summary-value fs-5" id="selectedBalance"><?php echo number_format($total_due, 2); ?></strong>
            </div>
            
            <div class="mt-3 text-center">
              <small class="text-white-50">
                <i class="fas fa-info-circle"></i> Payment + Discount will clear the fee
              </small>
            </div>
          </div>
        </div>
      </div>
    </div>
  </form>
  
  <!-- Recent Transactions -->
  <div class="card mt-4">
    <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
      <h5 class="mb-0"><i class="fas fa-history"></i> Recent Transactions (Last 20)</h5>
      <div>
        <button class="btn btn-sm btn-light" id="selectAllBtn" onclick="toggleSelectAll()">Select All</button>
        <button class="btn btn-sm btn-warning print-selected-btn" onclick="printSelectedTransactions()">
          <i class="fas fa-print"></i> Print Selected
        </button>
      </div>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table transaction-table mb-0">
          <thead>
            <tr>
              <th class="checkbox-col"><i class="fas fa-check-square"></i></th>
              <th>Receipt #</th>
              <th>Date & Time</th>
              <th>Fee Type</th>
              <th>Month</th>
              <th>Paid Amount</th>
              <th>Discount</th>
              <th>Method</th>
              <th>Status</th>
              <th>Print</th>
              <th>Edit</th>
              <th>Restore</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($recent_transactions)): ?>
            <tr>
              <td colspan="12" class="text-center py-4">No transactions found</td>
            </tr>
            <?php else: ?>
              <?php foreach ($recent_transactions as $trans): 
                $receipt_id = "REC-" . date('Ymd', strtotime($trans['payment_date'])) . "-" . $trans['id'];
                $month_year = getMonthYearFromDueDate($trans['due_date']);
                $can_edit = ($trans['status'] == 'completed');
              ?>
              <tr id="trans-row-<?php echo $trans['id']; ?>">
                <td class="checkbox-col">
                  <input type="checkbox" class="transaction-checkbox" value="<?php echo $trans['id']; ?>" id="trans_<?php echo $trans['id']; ?>">
                </td>
                <td><strong><?php echo $receipt_id; ?></strong></td>
                <td><?php echo date('d M Y, h:i A', strtotime($trans['payment_date'])); ?></td>
                <td><?php echo htmlspecialchars($trans['fee_type_title']); ?></td>
                <td><span class="month-badge"><?php echo $month_year; ?></span></td>
                <td class="text-success fw-bold"><?php echo number_format($trans['paid_amount'], 2); ?></td>
                <td class="text-warning fw-bold"><?php echo number_format($trans['discount_amount'], 2); ?></td>
                <td><?php echo ucfirst(str_replace('_', ' ', $trans['payment_method'])); ?></td>
                <td>
                  <?php if ($trans['status'] == 'reversed'): ?>
                    <span class="badge bg-secondary">Reversed</span>
                  <?php else: ?>
                    <span class="badge bg-success">Paid</span>
                  <?php endif; ?>
                </td>
                <td>
                  <button class="btn btn-sm btn-outline-primary receipt-btn" onclick="printSingleTransaction(<?php echo $trans['id']; ?>)">
                    <i class="fas fa-print"></i>
                  </button>
                </td>
                <td>
                  <?php if ($can_edit): ?>
                  <button class="btn btn-sm btn-outline-warning" onclick="openEditModal(<?php echo $trans['id']; ?>, <?php echo $trans['paid_amount']; ?>, <?php echo $trans['discount_amount']; ?>, '<?php echo $trans['payment_method']; ?>', '<?php echo htmlspecialchars(addslashes($trans['transaction_ref'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($trans['remarks'] ?? '')); ?>')" title="Edit Transaction">
                    <i class="fas fa-edit"></i>
                  </button>
                  <?php else: ?>
                  <span class="text-muted small">Locked</span>
                  <?php endif; ?>
                </td>
                <td>
                  <button class="btn btn-sm btn-outline-danger" onclick="confirmRestoreTransaction(<?php echo $trans['id']; ?>)" title="Restore Fee Card">
                    <i class="fas fa-undo-alt"></i>
                  </button>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  
  <?php else: ?>
    <div class="alert alert-warning">
      <i class="fas fa-exclamation-triangle"></i> Student is not enrolled in any active class. Please enroll the student first.
    </div>
  <?php endif; ?>
  
  <?php else: ?>
    <div class="alert alert-warning text-center py-5">
      <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
      <h4>No student selected</h4>
      <p class="mb-3">Please go to Student List and select a student to collect fees.</p>
      <a href="student_list.php" class="btn btn-primary">
        <i class="fas fa-list"></i> View Student List
      </a>
    </div>
  <?php endif; ?>
  
</div>

<script>
$(document).ready(function() {
  $('#fee_type_id').select2({
    placeholder: 'Select Fee Type',
    allowClear: true,
    width: '100%',
    dropdownParent: $('#addFeeModal')
  });
  
  $('#fee_type_id').on('change', function() {
    var selectedOption = $(this).find('option:selected');
    var amount = selectedOption.data('amount');
    if (amount) {
      $('#amount').val(amount);
    } else {
      $('#amount').val('');
    }
  });
  
  $('#month').on('change', function() {
    var selectedMonth = $(this).val();
    if (selectedMonth) {
      var lastDay = getLastDayOfMonth(selectedMonth);
      var date = new Date(lastDay);
      var formattedDate = date.toLocaleDateString('en-US', { day: 'numeric', month: 'long', year: 'numeric' });
      $('#dueDateDisplay').html('<i class="fas fa-calendar-check"></i> Due date: ' + formattedDate + ' (last day of month)');
    } else {
      $('#dueDateDisplay').html('');
    }
  });
  
  function getLastDayOfMonth(yearMonth) {
    var date = new Date(yearMonth + '-01');
    date.setMonth(date.getMonth() + 1);
    date.setDate(date.getDate() - 1);
    return date.toISOString().split('T')[0];
  }
  
  function calculateBalance(cardId, changedField) {
    var paymentInput  = $('input[name="payment[' + cardId + ']"]');
    var discountInput = $('input[name="discount[' + cardId + ']"]');
    var due           = parseFloat(paymentInput.data('due')) || 0;
    var payment       = parseFloat(paymentInput.val()) || 0;
    var discount      = parseFloat(discountInput.val()) || 0;

    if (payment > due) { payment = due; paymentInput.val(payment.toFixed(2)); }
    if (discount > due) { discount = due; discountInput.val(discount.toFixed(2)); }

    if (payment + discount > due) {
      if (changedField === 'discount') {
        payment = due - discount;
        if (payment < 0) payment = 0;
        paymentInput.val(payment.toFixed(2));
      } else {
        discount = due - payment;
        if (discount < 0) discount = 0;
        discountInput.val(discount.toFixed(2));
      }
    }

    var balance = due - payment - discount;
    if (balance < 0) balance = 0;

    var balanceSpan = $('#balance-' + cardId);
    if (balance <= 0) {
      balanceSpan.html('<span class="cleared-badge"><i class="fas fa-check-circle"></i> Cleared</span>');
      $('#fee-row-' + cardId).addClass('table-cleared');
    } else {
      balanceSpan.html('<span id="balance-value-' + cardId + '">' + balance.toFixed(2) + '</span>');
      $('#fee-row-' + cardId).removeClass('table-cleared');
    }

    return { payment: payment, discount: discount, balance: balance };
  }

  $(document).on('input', '.payment-amount', function() {
    var cardId = $(this).data('card-id');
    calculateBalance(cardId, 'payment');
    updateTotals();
  });

  $(document).on('input', '.discount-input', function() {
    var cardId = $(this).data('card-id');
    calculateBalance(cardId, 'discount');
    updateTotals();
  });
  
  function updateTotals() {
    var totalPayment = 0;
    var totalDiscount = 0;
    var totalBalance = 0;
    
    $('.payment-amount').each(function() {
      var cardId  = $(this).data('card-id');
      var due     = parseFloat($(this).data('due')) || 0;
      var payment = parseFloat($(this).val()) || 0;
      var discount= parseFloat($('input[name="discount[' + cardId + ']"]').val()) || 0;
      
      totalPayment  += payment;
      totalDiscount += discount;
      
      var balance = due - payment - discount;
      if (balance < 0) balance = 0;
      totalBalance += balance;
    });
    
    $('#selectedPayment').text(totalPayment.toFixed(2));
    $('#selectedDiscount').text(totalDiscount.toFixed(2));
    $('#selectedBalance').text(totalBalance.toFixed(2));
    $('#totalBalanceFooter').text(totalBalance.toFixed(2));
  }
  
  $('#feePaymentForm').on('submit', function(e) {
    var totalPayment = 0;
    var totalDiscount = 0;
    
    $('.payment-amount').each(function() {
      totalPayment += parseFloat($(this).val()) || 0;
    });
    
    $('.discount-input').each(function() {
      totalDiscount += parseFloat($(this).val()) || 0;
    });
    
    if (totalPayment <= 0 && totalDiscount <= 0) {
      e.preventDefault();
      alert('Please enter at least one payment amount or discount.');
      return false;
    }
    
    if (!$('select[name="payment_method"]').val()) {
      e.preventDefault();
      alert('Please select a payment method.');
      return false;
    }
  });
  
  $('#addFeeForm').on('submit', function(e) {
    var feeType = $('#fee_type_id').val();
    var amount  = $('#amount').val();
    var month   = $('#month').val();
    
    if (!feeType) {
      e.preventDefault();
      alert('Please select a fee type.');
      return false;
    }
    if (!amount || parseFloat(amount) <= 0) {
      e.preventDefault();
      alert('Please enter a valid amount greater than 0.');
      return false;
    }
    if (!month) {
      e.preventDefault();
      alert('Please select a month.');
      return false;
    }
    return true;
  });
  
  $('#addFeeModal').on('hidden.bs.modal', function () {
    $('#addFeeForm')[0].reset();
    $('#fee_type_id').val(null).trigger('change');
    $('#dueDateDisplay').html('');
  });
});

function confirmDelete(cardId) {
  if (confirm('Are you sure you want to delete this fee card? This action cannot be undone.')) {
    window.location.href = 'fee_collection.php?id=<?php echo $student_id; ?>&delete_fee_card=' + cardId;
  }
}

function confirmRestoreTransaction(transactionId) {
    if (confirm('⚠️ RESTORE TRANSACTION\n\nThis will:\n• Reverse the ledger entry\n• Mark payment as "reversed"\n• Restore fee card to pending (if not fully paid)\n\nContinue?')) {
        window.location.href = 'fee_collection.php?id=<?php echo $student_id; ?>&delete_transaction=' + transactionId;
    }
}

function toggleSelectAll() {
    var checkboxes = document.querySelectorAll('.transaction-checkbox');
    var selectAllBtn = document.getElementById('selectAllBtn');
    
    var allSelected = true;
    checkboxes.forEach(function(checkbox) {
        if (!checkbox.checked) allSelected = false;
    });
    
    checkboxes.forEach(function(checkbox) {
        checkbox.checked = !allSelected;
    });
    
    selectAllBtn.textContent = allSelected ? 'Select All' : 'Deselect All';
}

function printSingleTransaction(id) {
    window.open('print_receipt.php?id=' + id + '&auto_print=1', '_blank');
}

function printSelectedTransactions() {
    var selectedIds = [];
    document.querySelectorAll('.transaction-checkbox:checked').forEach(function(checkbox) {
        selectedIds.push(checkbox.value);
    });
    
    if (selectedIds.length === 0) {
        alert('Please select at least one transaction to print.');
        return;
    }
    
    var idsParam = selectedIds.join(',');
    window.open('print_receipt.php?ids=' + idsParam + '&auto_print=1', '_blank');
}

function openEditModal(transId, paidAmount, discountAmount, paymentMethod, transRef, remarks) {
  $('#edit_transaction_id').val(transId);
  $('#edit_original_amount').val(paidAmount.toFixed(2));
  $('#edit_original_discount').val(discountAmount.toFixed(2));
  $('#edit_new_amount').val(paidAmount.toFixed(2));
  $('#edit_new_discount').val(discountAmount.toFixed(2));
  $('#edit_payment_method').val(paymentMethod);
  $('#edit_transaction_ref').val(transRef);
  $('#edit_remarks').val(remarks);
  updateEditDisplay();
  $('#editFeeModal').modal('show');
}

$('#edit_new_amount, #edit_new_discount').on('input', function() {
  updateEditDisplay();
});

function updateEditDisplay() {
  var origAmount = parseFloat($('#edit_original_amount').val()) || 0;
  var origDiscount = parseFloat($('#edit_original_discount').val()) || 0;
  var newAmount = parseFloat($('#edit_new_amount').val()) || 0;
  var newDiscount = parseFloat($('#edit_new_discount').val()) || 0;
  
  $('#edit_orig_display').text(origAmount.toFixed(2));
  $('#edit_new_display').text(newAmount.toFixed(2));
  
  var netChange = newAmount - origAmount;
  var netSpan = $('#edit_net_display');
  netSpan.text(netChange.toFixed(2));
  
  if (netChange > 0) {
    netSpan.removeClass('text-danger').addClass('text-success');
  } else if (netChange < 0) {
    netSpan.removeClass('text-success').addClass('text-danger');
  } else {
    netSpan.removeClass('text-success text-danger');
  }
}

$('#editFeeForm').on('submit', function(e) {
  var newAmount = parseFloat($('#edit_new_amount').val()) || 0;
  var newDiscount = parseFloat($('#edit_new_discount').val()) || 0;
  
  if (newAmount <= 0 && newDiscount <= 0) {
    e.preventDefault();
    alert('Please enter a valid payment amount or discount.');
    return false;
  }
  
  if (!$('#edit_payment_method').val()) {
    e.preventDefault();
    alert('Please select a payment method.');
    return false;
  }
  
  var origAmount = parseFloat($('#edit_original_amount').val()) || 0;
  if (newAmount !== origAmount || newDiscount !== parseFloat($('#edit_original_discount').val())) {
    var confirmMsg = 'This will REVERSE the original transaction and create a new one.\n\n';
    confirmMsg += 'Original: ' + origAmount.toFixed(2) + '\n';
    confirmMsg += 'New: ' + newAmount.toFixed(2) + '\n\n';
    confirmMsg += 'Continue?';
    
    if (!confirm(confirmMsg)) {
      e.preventDefault();
      return false;
    }
  }
  
  return true;
});

$('#editFeeModal').on('hidden.bs.modal', function () {
  $('#editFeeForm')[0].reset();
});
</script>

</body>
</html>
<?php $conn->close(); ?>