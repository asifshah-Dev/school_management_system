<?php 
date_default_timezone_set('Asia/Karachi');

require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

$_SESSION['lang'] = 'en';
$lang = $_SESSION['lang'];

$translations = [
    'en' => [
        'title' => 'Expense Invoicing',
        'add_title' => 'Add New Expense',
        'edit_title' => 'Edit Expense',
        'payment_type' => 'Payment Type',
        'invoice_date' => 'Invoice Date',
        'description' => 'Description',
        'expense_head' => 'Expense Category',
        'amount' => 'Amount',
        'total_amount' => 'Total Amount',
        'submit' => 'Submit',
        'reset' => 'Reset',
        'success' => 'Expense saved successfully!',
        'update_success' => 'Expense updated successfully!',
        'delete_success' => 'Expense deleted successfully! Ledger reversed.',
        'error' => 'Error: ',
        'empty_error' => 'Required fields cannot be empty!',
        'invalid_amount' => 'Amount cannot be zero!',
        'transaction_error' => 'Transaction failed: ',
        'expense_not_found' => 'Expense not found!',
        'delete_confirm' => 'Are you sure you want to delete this expense? This will reverse the ledger entry.',
        'list_title' => 'Expenses List',
        'no_records' => 'No expenses found.',
        'sr_no' => '#',
        'actions' => 'Actions',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'cancel' => 'Cancel',
        'status' => 'Status',
        'status_active' => 'Active',
        'status_inactive' => 'Inactive',
        'status_deleted' => 'Deleted',
        'add_details' => 'Add Details',
        'product_details' => 'Product Details',
        'product_name' => 'Product Name',
        'category' => 'Category',
        'unit' => 'Unit',
        'quantity' => 'Quantity',
        'unit_price' => 'Unit Price',
        'total_price' => 'Total Price',
        'save_details' => 'Save Details',
        'close' => 'Close',
        'select_product' => 'Select Product',
        'add_new_product' => 'Add New Product',
        'product_list' => 'Product List',
        'inventory_details' => 'Inventory Details'
    ]
];

// ============================================================================
// HANDLER: Delete expense (reverse GL, soft-delete row)
// ============================================================================
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);
    $transaction_started = false;

    try {
        $conn->begin_transaction();
        $transaction_started = true;

        $check_expense = $conn->prepare("SELECT * FROM expenses WHERE id = ? AND status = 1");
        $check_expense->bind_param("i", $delete_id);
        $check_expense->execute();
        $expense_result = $check_expense->get_result();

        if ($expense_result->num_rows === 0) {
            throw new Exception("Expense not found or already deleted!");
        }

        $expense = $expense_result->fetch_assoc();
        $expense_amount = floatval($expense['total_amount']);
        $check_expense->close();

        // === GL: Reverse the original expense entry ===
        if ($expense_amount > 0) {
            $origGlStmt = $conn->prepare("
                SELECT id FROM gl_transactions
                WHERE reference_type = 'expense' AND reference_id = ?
                  AND status = 'POSTED' AND reversal_of IS NULL
                ORDER BY id ASC LIMIT 1
            ");
            $origGlStmt->bind_param("i", $delete_id);
            $origGlStmt->execute();
            $origGlTxn = $origGlStmt->get_result()->fetch_assoc();
            $origGlStmt->close();

            if ($origGlTxn) {
                $revErr = null;
                $postedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
                $revId = reverse_journal_entry($conn, (int)$origGlTxn['id'],
                                              "Expense #$delete_id deleted",
                                              $postedBy, $revErr);
                if ($revId <= 0) {
                    throw new Exception("GL reversal failed: " . ($revErr ?? 'unknown'));
                }
            }
        }

        $soft_delete = $conn->prepare("UPDATE expenses SET status = 0 WHERE id = ?");
        $soft_delete->bind_param("i", $delete_id);
        if (!$soft_delete->execute()) {
            throw new Exception("Failed to delete expense: " . $soft_delete->error);
        }
        $soft_delete->close();

        $delete_inv = $conn->prepare("DELETE FROM expanse_inventory_details WHERE expense_id = ?");
        $delete_inv->bind_param("i", $delete_id);
        $delete_inv->execute();
        $delete_inv->close();

        $conn->commit();
        $transaction_started = false;

        $_SESSION['message'] = "<div style='color: #3c763d; background-color: #dff0d8; border: 1px solid #d6e9c6; padding: 15px; border-radius: 4px;'><strong>✓ " . $translations[$lang]['delete_success'] . "</strong><br>Reversed Amount: " . number_format($expense_amount, 2) . "</div>";
        $_SESSION['message_type'] = "success";

    } catch (Exception $e) {
        if ($transaction_started) $conn->rollback();
        $_SESSION['message'] = "<div style='color: #a94442; background-color: #f2dede; border: 1px solid #ebccd1; padding: 15px; border-radius: 4px;'><strong>✗ Delete Failed!</strong><br>" . $e->getMessage() . "</div>";
        $_SESSION['message_type'] = "danger";
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// ============================================================================
// HANDLER: AJAX product/inventory operations (unchanged) + quick add supplier
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    $conn->query("SET time_zone = '+05:00'");

    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $response = ['success' => false, 'message' => ''];

    if ($action == 'get_products') {
        $result = $conn->query("SELECT id, product_name, category, unit FROM products WHERE status = 1 ORDER BY product_name");
        $products = [];
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
        $response['success'] = true;
        $response['products'] = $products;
    }
    elseif ($action == 'add_product') {
        $product_name = trim($_POST['product_name']);
        $category = trim($_POST['category']);
        $unit = trim($_POST['unit']);

        $valid_categories = ['Furniture', 'Stationery', 'Electronics', 'Clothing'];
        if (!in_array($category, $valid_categories)) {
            $response['message'] = 'Invalid category. Allowed values: ' . implode(', ', $valid_categories);
            header('Content-Type: application/json');
            echo json_encode($response);
            exit();
        }

        $stmt = $conn->prepare("INSERT INTO products (product_name, category, unit, status, created_at) VALUES (?, ?, ?, 1, NOW())");
        $stmt->bind_param("sss", $product_name, $category, $unit);

        if ($stmt->execute()) {
            $response['success'] = true;
            $response['message'] = 'Product added successfully';
            $response['product_id'] = $conn->insert_id;
            $response['product_name'] = $product_name;
            $response['category'] = $category;
            $response['unit'] = $unit;
        } else {
            $response['message'] = 'Failed to add product: ' . $stmt->error;
        }
        $stmt->close();
    }
    elseif ($action == 'get_inventory_details') {
        $expense_id = intval($_POST['expense_id']);
        $stmt = $conn->prepare("
            SELECT eid.*, p.product_name, p.category, p.unit 
            FROM expanse_inventory_details eid
            JOIN products p ON eid.product_id = p.id
            WHERE eid.expense_id = ?
        ");
        $stmt->bind_param("i", $expense_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $details = [];
        while ($row = $result->fetch_assoc()) {
            $details[] = $row;
        }
        $stmt->close();

        $response['success'] = true;
        $response['details'] = $details;
    }
    elseif ($action == 'add_inventory_detail') {
        $expense_id = intval($_POST['expense_id']);
        $product_id = intval($_POST['product_id']);
        $quantity = floatval($_POST['quantity']);
        $unit_price = floatval($_POST['unit_price']);
        $total_price = $quantity * $unit_price;
        $description = trim($_POST['description']);

        $stmt = $conn->prepare("
            INSERT INTO expanse_inventory_details (expense_id, product_id, quantity, unit_price, description) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iidds", $expense_id, $product_id, $quantity, $unit_price, $description);

        if ($stmt->execute()) {
            $response['success'] = true;
            $response['message'] = 'Detail added successfully';
            $response['detail_id'] = $conn->insert_id;
            $response['total_price'] = $total_price;
        } else {
            $response['message'] = 'Failed to add detail: ' . $stmt->error;
        }
        $stmt->close();
    }
    elseif ($action == 'delete_inventory_detail') {
        $detail_id = intval($_POST['detail_id']);

        $stmt = $conn->prepare("DELETE FROM expanse_inventory_details WHERE id = ?");
        $stmt->bind_param("i", $detail_id);

        if ($stmt->execute()) {
            $response['success'] = true;
            $response['message'] = 'Detail deleted successfully';
        } else {
            $response['message'] = 'Failed to delete detail: ' . $stmt->error;
        }
        $stmt->close();
    }
    elseif ($action == 'quick_add_supplier') {
        $name  = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if ($name === '') {
            $response['message'] = 'Supplier name is required.';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO gl_parties (party_type, name, phone, email, status)
                VALUES ('SUPPLIER', ?, ?, ?, 1)
            ");
            $stmt->bind_param("sss", $name, $phone, $email);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['id'] = $conn->insert_id;
                $response['name'] = $name;
            } else {
                $response['message'] = 'Insert failed: ' . $stmt->error;
            }
            $stmt->close();
        }
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// ============================================================================
// HANDLER: Add/Edit expense with GL integration (AP-aware)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_expense'])) {
    $expense_categories_id = isset($_POST['expense_categories_id']) ? intval($_POST['expense_categories_id']) : 0;
    $payment_type = isset($_POST['payment_type']) ? trim($_POST['payment_type']) : '';
    $invoice_date = !empty($_POST['invoice_date']) ? trim($_POST['invoice_date']) : date('Y-m-d');
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $supplier_id = isset($_POST['supplier_id']) ? intval($_POST['supplier_id']) : 0;

    $raw_amount = isset($_POST['total_amount']) ? floatval($_POST['total_amount']) : 0;
    $total_amount = abs($raw_amount);

    $status = 1;

    // Validate
    if (empty($expense_categories_id) || $expense_categories_id == 0) {
        $_SESSION['message'] = "Error: Expense category is required!";
        $_SESSION['message_type'] = "danger";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Payment type is required ONLY if no supplier (i.e., immediate payment)
    if ($supplier_id === 0 && empty($payment_type)) {
        $_SESSION['message'] = "Error: Payment type is required when not using a supplier!";
        $_SESSION['message_type'] = "danger";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    if ($total_amount == 0) {
        $_SESSION['message'] = "Error: Amount cannot be zero!";
        $_SESSION['message_type'] = "danger";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    $check_cat = $conn->prepare("SELECT id FROM expense_categories WHERE id = ? AND status = 1");
    $check_cat->bind_param("i", $expense_categories_id);
    $check_cat->execute();
    $cat_result = $check_cat->get_result();

    if ($cat_result->num_rows === 0) {
        $_SESSION['message'] = "Error: Invalid or inactive expense category (ID: " . $expense_categories_id . ")!";
        $_SESSION['message_type'] = "danger";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    $check_cat->close();

    // Resolve GL accounts
    $expenseAcc = gl_expense_account_for_category($conn, $expense_categories_id);
    if ($expenseAcc <= 0) {
        $_SESSION['message'] = "Error: No GL account mapped for expense category #$expense_categories_id. Update expense_category_gl_map.";
        $_SESSION['message_type'] = "danger";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Resolve supplier (if chosen)
    $supplierRow = null;
    if ($supplier_id > 0) {
        $supplierRow = gl_get_supplier($conn, $supplier_id);
        if (!$supplierRow) {
            $_SESSION['message'] = "Error: Supplier #$supplier_id not found.";
            $_SESSION['message_type'] = "danger";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    } else {
        // No supplier → resolve payment account for immediate cash/bank payment
        $paymentAcc = gl_payment_account_for_expense($conn, $payment_type);
        if ($paymentAcc <= 0) {
            $_SESSION['message'] = "Error: GL account not found for payment type: $payment_type";
            $_SESSION['message_type'] = "danger";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }

    // Session for GL posting
    $sessionRow = $conn->query("SELECT id FROM sessions WHERE status = 0 ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $sessionId = $sessionRow ? (int)$sessionRow['id'] : 1;
    $postedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;

    $expense_id = isset($_POST['expense_id']) ? intval($_POST['expense_id']) : 0;
    $is_edit = ($expense_id > 0);

    $transaction_started = false;

    try {
        $conn->begin_transaction();
        $transaction_started = true;

        if ($is_edit) {
            // === EDIT MODE ===
            $check_expense = $conn->prepare("SELECT * FROM expenses WHERE id = ? AND status = 1 FOR UPDATE");
            $check_expense->bind_param("i", $expense_id);
            $check_expense->execute();
            $old_expense_result = $check_expense->get_result();

            if ($old_expense_result->num_rows === 0) {
                throw new Exception($translations[$lang]['expense_not_found']);
            }

            $old_expense = $old_expense_result->fetch_assoc();
            $old_amount = floatval($old_expense['total_amount']);
            $check_expense->close();

            // Reverse the original GL entry
            $origGlStmt = $conn->prepare("
                SELECT id FROM gl_transactions
                WHERE reference_type = 'expense' AND reference_id = ?
                  AND status = 'POSTED' AND reversal_of IS NULL
                ORDER BY id ASC LIMIT 1
            ");
            $origGlStmt->bind_param("i", $expense_id);
            $origGlStmt->execute();
            $origGlTxn = $origGlStmt->get_result()->fetch_assoc();
            $origGlStmt->close();

            if ($origGlTxn) {
                $revErr = null;
                $revId = reverse_journal_entry($conn, (int)$origGlTxn['id'],
                                              "Expense #$expense_id edited (was $old_amount)",
                                              $postedBy, $revErr);
                if ($revId <= 0) {
                    throw new Exception("GL reversal failed: " . ($revErr ?? 'unknown'));
                }
            }

            // Update expense row (now includes supplier_id)
            $update_expense = $conn->prepare("
                UPDATE expenses SET 
                expense_categories_id = ?, supplier_id = ?, payment_type = ?, total_amount = ?, 
                description = ?, invoice_date = ?, status = ? 
                WHERE id = ?
            ");
            $supplierIdOrNull = $supplier_id > 0 ? $supplier_id : null;
            $update_expense->bind_param("iisdssii", 
                $expense_categories_id, $supplierIdOrNull, $payment_type, $total_amount, 
                $description, $invoice_date, $status, $expense_id
            );

            if (!$update_expense->execute()) {
                throw new Exception("Failed to update expense: " . $update_expense->error);
            }
            $update_expense->close();

            // Post new GL entry
            if ($supplier_id > 0) {
                $apAccRow = gl_get_account_by_code($conn, '2010');
                if (!$apAccRow) throw new Exception("AP account 2010 not found.");
                $apAccId = (int)$apAccRow['id'];

                $glErr = null;
                $txnId = post_journal_entry($conn, [
                    'entry_date'  => $invoice_date,
                    'session_id'  => $sessionId,
                    'description' => "Expense #$expense_id (edited, credit from {$supplierRow['name']}): $description",
                    'ref_type'    => 'expense',
                    'ref_id'      => $expense_id,
                    'posted_by'   => $postedBy,
                    'idempotency' => 'expense_edit-' . $expense_id . '-' . time(),
                    'lines'       => [
                        ['account_id' => $expenseAcc, 'debit' => $total_amount, 'credit' => 0,
                         'memo' => "Expense (edited) — $description"],
                        ['account_id' => $apAccId,   'debit' => 0, 'credit' => $total_amount,
                         'memo' => "Owed to {$supplierRow['name']}",
                         'party_id' => $supplier_id],
                    ],
                ], $glErr);
            } else {
                $glErr = null;
                $txnId = post_journal_entry($conn, [
                    'entry_date'  => $invoice_date,
                    'session_id'  => $sessionId,
                    'description' => "Expense #$expense_id edited: $description",
                    'ref_type'    => 'expense',
                    'ref_id'      => $expense_id,
                    'posted_by'   => $postedBy,
                    'idempotency' => 'expense_edit-' . $expense_id . '-' . time(),
                    'lines'       => [
                        ['account_id' => $expenseAcc, 'debit'  => $total_amount, 'credit' => 0,
                         'memo'       => "Expense (edited) — $description"],
                        ['account_id' => $paymentAcc, 'debit'  => 0, 'credit' => $total_amount,
                         'memo'       => "Paid via $payment_type"],
                    ],
                ], $glErr);
            }

            if ($txnId <= 0) {
                throw new Exception("GL post failed: " . ($glErr ?? 'unknown'));
            }

            $_SESSION['message'] = "<div style='color: #3c763d; background-color: #dff0d8; border: 1px solid #d6e9c6; padding: 15px; border-radius: 4px;'><strong>✓ Expense Updated Successfully!</strong>" . ($supplier_id > 0 ? " <em>(posted to AP — owed to {$supplierRow['name']})</em>" : "") . "</div>";
            $_SESSION['message_type'] = "success";

        } else {
            // === ADD MODE ===
            $expense_stmt = $conn->prepare("
                INSERT INTO expenses 
                (expense_categories_id, supplier_id, payment_type, total_amount, description, invoice_date, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            if (!$expense_stmt) {
                throw new Exception("Prepare expenses failed: " . $conn->error);
            }

            $supplierIdOrNull = $supplier_id > 0 ? $supplier_id : null;
            $expense_stmt->bind_param("iisdssi", 
                $expense_categories_id, $supplierIdOrNull, $payment_type, $total_amount, 
                $description, $invoice_date, $status
            );

            if (!$expense_stmt->execute()) {
                throw new Exception("Insert expenses failed: " . $expense_stmt->error);
            }

            $new_expense_id = $conn->insert_id;
            $expense_stmt->close();

            if ($new_expense_id <= 0) {
                throw new Exception("Failed to obtain new expense id after insert.");
            }

            // GL: Post the expense — AP-aware
            if ($supplier_id > 0) {
                // Credit purchase → Dr Expense, Cr 2010 AP with party_id
                $apAccRow = gl_get_account_by_code($conn, '2010');
                if (!$apAccRow) throw new Exception("AP account 2010 not found.");
                $apAccId = (int)$apAccRow['id'];

                $glErr = null;
                $txnId = post_journal_entry($conn, [
                    'entry_date'  => $invoice_date,
                    'session_id'  => $sessionId,
                    'description' => "Expense #$new_expense_id (credit from {$supplierRow['name']}): $description",
                    'ref_type'    => 'expense',
                    'ref_id'      => $new_expense_id,
                    'posted_by'   => $postedBy,
                    'idempotency' => 'expense-' . $new_expense_id,
                    'lines'       => [
                        ['account_id' => $expenseAcc, 'debit' => $total_amount, 'credit' => 0,
                         'memo' => "Expense — $description"],
                        ['account_id' => $apAccId,   'debit' => 0, 'credit' => $total_amount,
                         'memo' => "Owed to {$supplierRow['name']}",
                         'party_id' => $supplier_id],
                    ],
                ], $glErr);
            } else {
                // Immediate payment → Dr Expense, Cr cash/bank
                $glErr = null;
                $txnId = post_journal_entry($conn, [
                    'entry_date'  => $invoice_date,
                    'session_id'  => $sessionId,
                    'description' => "Expense #$new_expense_id: $description",
                    'ref_type'    => 'expense',
                    'ref_id'      => $new_expense_id,
                    'posted_by'   => $postedBy,
                    'idempotency' => 'expense-' . $new_expense_id,
                    'lines'       => [
                        ['account_id' => $expenseAcc, 'debit'  => $total_amount, 'credit' => 0,
                         'memo'       => "Expense — $description"],
                        ['account_id' => $paymentAcc, 'debit'  => 0, 'credit' => $total_amount,
                         'memo'       => "Paid via $payment_type"],
                    ],
                ], $glErr);
            }

            if ($txnId <= 0) {
                throw new Exception("GL post failed: " . ($glErr ?? 'unknown'));
            }

            $_SESSION['message'] = "<div style='color: #3c763d; background-color: #dff0d8; border: 1px solid #d6e9c6; padding: 15px; border-radius: 4px;'><strong>✓ Expense Saved Successfully!</strong>" . ($supplier_id > 0 ? " <em>(posted to AP — owed to {$supplierRow['name']})</em>" : "") . "</div>";
            $_SESSION['message_type'] = "success";
        }

        $conn->commit();
        $transaction_started = false;

    } catch (Exception $e) {
        if ($transaction_started) $conn->rollback();
        $_SESSION['message'] = "<div style='color: #a94442; background-color: #f2dede; border: 1px solid #ebccd1; padding: 15px; border-radius: 4px;'><strong>✗ Transaction Failed!</strong><br>" . $e->getMessage() . "</div>";
        $_SESSION['message_type'] = "danger";
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once('meta_inc.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        .amount-input { text-align: left; }
        .table-responsive { overflow-x: auto; }
        .table .amount-column { 
            text-align: right; 
            font-family: monospace;
        }
        .table .description-column {
            max-width: 250px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .table .date-column { white-space: nowrap; }
        input[type=number].amount-input { text-align: left; }
        input[type=number] { -moz-appearance: textfield; }
        input[type=number]::-webkit-inner-spin-button, 
        input[type=number]::-webkit-outer-spin-button { 
            -webkit-appearance: none; 
            margin: 0; 
        }
        .description-column:hover {
            overflow: visible;
            white-space: normal;
            word-wrap: break-word;
            background-color: #fff;
            position: relative;
            z-index: 1;
            box-shadow: 0 0 5px rgba(0,0,0,0.1);
        }
        .balance-info {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 10px 15px;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        .balance-negative {
            background: #f8d7da;
            border-color: #f5c6cb;
            color: #721c24;
        }
        .custom-alert {
            margin-bottom: 20px;
        }
        .deleted-row {
            background-color: #f2dede !important;
            opacity: 0.7;
        }
        .btn-delete {
            margin-left: 5px;
        }
        .product-details-table td, .product-details-table th {
            vertical-align: middle;
        }
        .modal-lg {
            width: 90%;
            max-width: 1200px;
        }
        .btn-add-details {
            margin-left: 10px;
        }
        .modal {
            overflow-y: auto;
        }
        /* Select2 theme match */
        .select2-container .select2-selection--single {
            height: 34px !important;
            border: 1px solid #ccc !important;
            border-radius: 4px !important;
            padding: 2px 8px;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 28px !important;
            color: #333 !important;
            font-size: 14px;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 32px !important;
        }
        .supplier-hint {
            background: #e8f4fd;
            border-left: 3px solid #1e40af;
            padding: 8px 12px;
            font-size: 12px;
            color: #1e40af;
            margin-top: 6px;
            border-radius: 4px;
        }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="container">
    <div class="row">
        <div class="col-md-12">

            <!-- Current cash balance from GL -->
            <?php
            $cashAcc = gl_get_account_by_code($conn, '1010');
            if ($cashAcc) {
                $cashBalance = gl_account_balance_signed($conn, (int)$cashAcc['id'], date('Y-m-d'));
                $balance_class = $cashBalance < 0 ? 'balance-negative' : '';
            ?>
            <div class="balance-info <?php echo $balance_class; ?>">
                <strong>Current Cash Balance (GL):</strong> <?php echo number_format($cashBalance, 2); ?>
                <?php if ($cashBalance < 0): ?>
                    <span class="label label-danger">Negative Balance</span>
                <?php endif; ?>
            </div>
            <?php } ?>

            <div class="panel panel-primary">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <?php 
                        echo isset($_GET['edit']) ? 
                            $translations[$lang]['edit_title'] . ' (ID: ' . intval($_GET['edit']) . ')' : 
                            $translations[$lang]['add_title']; 
                        ?>
                    </h3>
                </div>
                <div class="panel-body">

                    <?php if (isset($_SESSION['message'])): ?>
                        <div class="custom-alert">
                            <?php 
                            echo $_SESSION['message']; 
                            unset($_SESSION['message'], $_SESSION['message_type']);
                            ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="" id="expenseForm">
                        <?php
                        $edit_mode = isset($_GET['edit']);
                        $expense_data = [
                            'id' => '',
                            'expense_categories_id' => '',
                            'supplier_id' => '',
                            'payment_type' => 'Cash',
                            'total_amount' => '',
                            'description' => '',
                            'invoice_date' => date('Y-m-d'),
                            'status' => 1
                        ];
                        
                        if ($edit_mode) {
                            $id = intval($_GET['edit']);
                            $stmt = $conn->prepare("SELECT * FROM expenses WHERE id = ?");
                            $stmt->bind_param("i", $id);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            
                            if ($result->num_rows > 0) {
                                $expense_data = $result->fetch_assoc();
                                $expense_data['invoice_date'] = date('Y-m-d', strtotime($expense_data['invoice_date']));
                            } else {
                                echo "<div style='color: #a94442; background-color: #f2dede; border: 1px solid #ebccd1; padding: 10px; border-radius: 4px; margin-bottom: 15px;'><strong>Error:</strong> Expense record not found.</div>";
                            }
                            $stmt->close();
                        }
                        ?>
                        
                        <input type="hidden" name="expense_id" id="expense_id" value="<?php echo htmlspecialchars($expense_data['id']); ?>">
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="expense_categories_id"><?php echo $translations[$lang]['expense_head']; ?> *</label>
                                    <select class="form-control" id="expense_categories_id" name="expense_categories_id" required>
                                        <option value="">-- Select Expense Category --</option>
                                        <?php
                                        $categories_query = "SELECT id, title FROM expense_categories WHERE status = 1 ORDER BY title";
                                        $categories_result = $conn->query($categories_query);
                                        
                                        if ($categories_result && $categories_result->num_rows > 0) {
                                            while ($category = $categories_result->fetch_assoc()) {
                                                $selected = ($category['id'] == $expense_data['expense_categories_id']) ? 'selected' : '';
                                                echo "<option value='" . htmlspecialchars($category['id']) . "' $selected>" . 
                                                     htmlspecialchars($category['title']) . "</option>";
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>

                            <!-- NEW: Supplier dropdown -->
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="supplier_id">Supplier
                                        <small class="text-muted">(optional)</small>
                                    </label>
                                    <div style="display:flex; gap:6px;">
                                        <select class="form-control" id="supplier_id" name="supplier_id" style="flex:1;">
                                            <option value="">— None / cash-bank payment —</option>
                                            <?php
                                            $supList = gl_list_suppliers($conn, false);
                                            foreach ($supList as $sp):
                                                $selected = (isset($expense_data['supplier_id']) && (int)$expense_data['supplier_id'] === (int)$sp['id']) ? 'selected' : '';
                                            ?>
                                                <option value="<?= (int)$sp['id'] ?>" <?= $selected ?>>
                                                    <?= htmlspecialchars($sp['name']) ?>
                                                    <?php if (abs((float)$sp['ap_balance']) > 0.01): ?>
                                                        (Owed: <?= number_format((float)$sp['ap_balance'], 2) ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="btn btn-success btn-sm" id="btnQuickAddSupplier" title="Add new supplier">
                                            <span class="glyphicon glyphicon-plus"></span>
                                        </button>
                                    </div>
                                    <div class="supplier-hint" id="supplier_hint" style="display:none;">
                                        <strong>Credit purchase:</strong> will post to <code>2010 Accounts Payable</code> tagged to this supplier.
                                        <a href="gl_parties.php" target="_blank">Manage suppliers</a>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="invoice_date"><?php echo $translations[$lang]['invoice_date']; ?></label>
                                    <input type="date" class="form-control" id="invoice_date" name="invoice_date" 
                                           value="<?php echo htmlspecialchars($expense_data['invoice_date']); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="payment_type"><?php echo $translations[$lang]['payment_type']; ?> <span id="payment_required">*</span></label>
                                    <select class="form-control" id="payment_type" name="payment_type">
                                        <option value="Cash" <?php echo ($expense_data['payment_type'] == 'Cash') ? 'selected' : ''; ?>>Cash</option>
                                        <option value="Bank" <?php echo ($expense_data['payment_type'] == 'Bank') ? 'selected' : ''; ?>>Bank</option>
                                        <option value="Cheque" <?php echo ($expense_data['payment_type'] == 'Cheque') ? 'selected' : ''; ?>>Cheque</option>
                                    </select>
                                    <small class="text-muted" id="payment_hint">Ignored if a supplier is chosen</small>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <div class="form-group">
                                    <label for="description"><?php echo $translations[$lang]['description']; ?></label>
                                    <textarea class="form-control" id="description" name="description" rows="1"><?php 
                                        echo htmlspecialchars($expense_data['description']); 
                                    ?></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="total_amount">
                                        <?php echo $translations[$lang]['total_amount']; ?> * 
                                        <small class="text-muted">(Enter amount)</small>
                                    </label>
                                    <input type="number" class="form-control amount-input" id="total_amount" 
                                           name="total_amount" step="0.01" required
                                           value="<?php echo $expense_data['total_amount'] !== '' ? htmlspecialchars($expense_data['total_amount']) : ''; ?>"
                                           placeholder="Enter amount">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>&nbsp;</label>
                                    <div>
                                        <button type="button" class="btn btn-info btn-lg btn-add-details" 
                                                data-toggle="modal" data-target="#productDetailsModal"
                                                <?php echo empty($expense_data['id']) ? 'disabled' : ''; ?>>
                                            <span class="glyphicon glyphicon-list-alt"></span> <?php echo $translations[$lang]['add_details']; ?>
                                        </button>
                                        <small class="text-muted" style="display: block;">Save expense first to add product details</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-12 text-right">
                                <button type="submit" name="submit_expense" class="btn btn-success btn-lg">
                                    <?php echo $edit_mode ? 'Update' : 'Save'; ?> Expense
                                </button>
                                
                                <?php if ($edit_mode): ?>
                                    <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="btn btn-default btn-lg">
                                        Cancel
                                    </a>
                                <?php else: ?>
                                    <button type="reset" class="btn btn-default btn-lg">
                                        Reset
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="panel panel-info">
                <div class="panel-heading">
                    <h3 class="panel-title"><?php echo $translations[$lang]['list_title']; ?></h3>
                </div>
                <div class="panel-body">

                    <?php
                    $query = "SELECT e.*, ec.title as expense_category_title,
                                     p.name as supplier_name
                              FROM expenses e
                              LEFT JOIN expense_categories ec ON e.expense_categories_id = ec.id
                              LEFT JOIN gl_parties p ON p.id = e.supplier_id
                              ORDER BY e.status DESC, e.invoice_date DESC, e.id DESC";
                    
                    $result = $conn->query($query);
                    ?>

                    <?php if ($result && $result->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th><?php echo $translations[$lang]['sr_no']; ?></th>
                                        <th><?php echo $translations[$lang]['invoice_date']; ?></th>
                                        <th><?php echo $translations[$lang]['expense_head']; ?></th>
                                        <th>Supplier</th>
                                        <th><?php echo $translations[$lang]['payment_type']; ?></th>
                                        <th><?php echo $translations[$lang]['description']; ?></th>
                                        <th><?php echo $translations[$lang]['total_amount']; ?></th>
                                        <th><?php echo $translations[$lang]['inventory_details']; ?></th>
                                        <th><?php echo $translations[$lang]['actions']; ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $counter = 1; while ($row = $result->fetch_assoc()): 
                                        $is_deleted = ($row['status'] == 0);
                                        $row_class = $is_deleted ? 'deleted-row' : '';
                                    ?>
                                        <tr class="<?php echo $row_class; ?>">
                                            <td><?php echo $counter++; ?></td>
                                            <td class="date-column">
                                                <?php echo htmlspecialchars(date('Y-m-d', strtotime($row['invoice_date']))); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($row['expense_category_title'] ? $row['expense_category_title'] : 'N/A'); ?></td>
                                            <td>
                                                <?php if (!empty($row['supplier_name'])): ?>
                                                    <span style="color:#9d174d; font-weight:600;"><?= htmlspecialchars($row['supplier_name']) ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($row['payment_type']); ?></td>
                                            <td class="description-column" title="<?php echo htmlspecialchars($row['description']); ?>">
                                                <?php echo htmlspecialchars($row['description'] ? $row['description'] : '-'); ?>
                                            </td>
                                            <td class="amount-column"><?php echo number_format($row['total_amount'], 2); ?></td>
                                            <td class="text-center">
                                                <?php if (!$is_deleted && $row['id']): ?>
                                                    <button type="button" class="btn btn-xs btn-info view-details-btn" 
                                                            data-expense-id="<?php echo $row['id']; ?>"
                                                            data-expense-category="<?php echo htmlspecialchars($row['expense_category_title']); ?>">
                                                        <span class="glyphicon glyphicon-list-alt"></span> View Details
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!$is_deleted): ?>
                                                    <a href="?edit=<?php echo $row['id']; ?>" class="btn btn-xs btn-warning">
                                                        <?php echo $translations[$lang]['edit']; ?>
                                                    </a>
                                                    <a href="?delete=<?php echo $row['id']; ?>" 
                                                       class="btn btn-xs btn-danger btn-delete" 
                                                       onclick="return confirm('<?php echo $translations[$lang]['delete_confirm']; ?>\n\nAmount: <?php echo number_format($row['total_amount'], 2); ?>\nCategory: <?php echo htmlspecialchars($row['expense_category_title'] ?? 'N/A'); ?>');">
                                                        <?php echo $translations[$lang]['delete']; ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">Deleted</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="active">
                                        <th colspan="6" class="text-right">Total Active Expenses:</th>
                                        <th class="amount-column">
                                            <?php
                                            $total_query = $conn->query("SELECT SUM(total_amount) as grand_total FROM expenses WHERE status = 1");
                                            if ($total_query) {
                                                echo number_format($total_query->fetch_assoc()['grand_total'] ?? 0, 2);
                                            }
                                            ?>
                                        </th>
                                        <th colspan="2"></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info text-center">
                            <?php echo $translations[$lang]['no_records']; ?>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

        </div>
    </div>
</div>

<!-- Product Details Modal (unchanged) -->
<div class="modal fade" id="productDetailsModal" tabindex="-1" role="dialog" aria-labelledby="productDetailsModalLabel">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title" id="productDetailsModalLabel">
                    <span class="glyphicon glyphicon-list-alt"></span> <?php echo $translations[$lang]['product_details']; ?>
                    <small id="expenseCategoryLabel"></small>
                </h4>
            </div>
            <div class="modal-body">
                <input type="hidden" id="modal_expense_id" value="">
                
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h4 class="panel-title">Add Product to Expense</h4>
                    </div>
                    <div class="panel-body">
                        <div class="row">
                            <div class="col-md-5">
                                <div class="form-group">
                                    <label for="product_id"><?php echo $translations[$lang]['select_product']; ?> *</label>
                                    <select class="form-control" id="product_id" required>
                                        <option value="">-- Select Product --</option>
                                    </select>
                                    <button type="button" class="btn btn-xs btn-primary" style="margin-top: 5px;" id="btnOpenAddProductModal">
                                        <span class="glyphicon glyphicon-plus"></span> <?php echo $translations[$lang]['add_new_product']; ?>
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label for="quantity"><?php echo $translations[$lang]['quantity']; ?> *</label>
                                    <input type="number" class="form-control" id="quantity" step="0.01" value="1">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label for="unit_price"><?php echo $translations[$lang]['unit_price']; ?> *</label>
                                    <input type="number" class="form-control" id="unit_price" step="0.01">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>&nbsp;</label>
                                    <div>
                                        <button type="button" class="btn btn-success btn-block" id="btnAddDetail">
                                            <span class="glyphicon glyphicon-plus"></span> <?php echo $translations[$lang]['save_details']; ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label for="detail_description"><?php echo $translations[$lang]['description']; ?></label>
                                    <textarea class="form-control" id="detail_description" rows="2"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="panel panel-info">
                    <div class="panel-heading">
                        <h4 class="panel-title"><?php echo $translations[$lang]['inventory_details']; ?></h4>
                    </div>
                    <div class="panel-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped product-details-table" id="inventoryDetailsTable">
                                <thead>
                                    <tr>
                                        <th><?php echo $translations[$lang]['product_name']; ?></th>
                                        <th><?php echo $translations[$lang]['category']; ?></th>
                                        <th><?php echo $translations[$lang]['quantity']; ?></th>
                                        <th><?php echo $translations[$lang]['unit']; ?></th>
                                        <th><?php echo $translations[$lang]['unit_price']; ?></th>
                                        <th><?php echo $translations[$lang]['total_price']; ?></th>
                                        <th><?php echo $translations[$lang]['description']; ?></th>
                                        <th><?php echo $translations[$lang]['actions']; ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr id="noDetailsRow">
                                        <td colspan="8" class="text-center">No product details added yet</td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="active">
                                        <th colspan="5" class="text-right">Total Inventory Value:</th>
                                        <th id="inventoryTotal" class="amount-column">0.00</th>
                                        <th colspan="2"></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" id="btnCloseProductDetails" class="btn btn-default" data-dismiss="modal"><?php echo $translations[$lang]['close']; ?></button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addProductModal" tabindex="-1" role="dialog" aria-labelledby="addProductModalLabel">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="addProductModalLabel"><?php echo $translations[$lang]['add_new_product']; ?></h4>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="new_product_name"><?php echo $translations[$lang]['product_name']; ?> *</label>
                    <input type="text" class="form-control" id="new_product_name">
                </div>
                <div class="form-group">
                    <label for="new_category"><?php echo $translations[$lang]['category']; ?> *</label>
                    <select class="form-control" id="new_category">
                        <option value="">-- Select Category --</option>
                        <option value="Furniture">Furniture</option>
                        <option value="Stationery">Stationery</option>
                        <option value="Electronics">Electronics</option>
                        <option value="Clothing">Clothing</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="new_unit"><?php echo $translations[$lang]['unit']; ?> *</label>
                    <input type="text" class="form-control" id="new_unit" placeholder="e.g., Pcs, Kg, Liter">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" id="btnCancelProduct"><?php echo $translations[$lang]['cancel']; ?></button>
                <button type="button" class="btn btn-primary" id="btnSaveProduct"><?php echo $translations[$lang]['save_details']; ?></button>
            </div>
        </div>
    </div>
</div>

<!-- NEW: Quick Add Supplier modal -->
<div class="modal fade" id="quickSupplierModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header" style="background:#1e40af; color:#fff;">
                <button type="button" class="close" data-dismiss="modal" style="color:#fff; opacity:0.8;"><span>&times;</span></button>
                <h4 class="modal-title"><span class="glyphicon glyphicon-plus"></span> Quick Add Supplier</h4>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Name *</label>
                    <input type="text" class="form-control" id="qs_name" placeholder="e.g. Ahmed Traders">
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <input type="text" class="form-control" id="qs_phone" placeholder="0300-1234567">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="text" class="form-control" id="qs_email" placeholder="sales@supplier.com">
                </div>
                <div class="alert alert-info" style="margin-bottom:0; font-size:12px;">
                    <strong>Tip:</strong> Add full details (address, NTN) later from the
                    <a href="gl_parties.php" target="_blank">Suppliers page</a>.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="qs_save">Save Supplier</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    // === Select2 for supplier & category ===
    if ($.fn.select2) {
        $('#supplier_id').select2({ width: '100%', placeholder: '— None / cash-bank payment —' });
        $('#expense_categories_id').select2({ width: '100%', placeholder: '— Select Expense Category —' });
    }

    // === Show/hide supplier hint based on supplier selection ===
    function syncSupplierUI() {
        var sel = $('#supplier_id').val();
        var hasSupplier = sel && sel !== '';

        if (hasSupplier) {
            $('#supplier_hint').show();
            $('#payment_type').prop('disabled', false).css('opacity', 0.5);
            $('#payment_required').hide();
            $('#payment_hint').text('Not used — credit purchase');
        } else {
            $('#supplier_hint').hide();
            $('#payment_type').prop('disabled', false).css('opacity', 1);
            $('#payment_required').show();
            $('#payment_hint').text('Required for immediate payment');
        }
    }
    $('#supplier_id').on('change', syncSupplierUI);
    syncSupplierUI();

    // === Quick add supplier ===
    $('#btnQuickAddSupplier').on('click', function() {
        $('#qs_name').val('');
        $('#qs_phone').val('');
        $('#qs_email').val('');
        $('#quickSupplierModal').modal('show');
    });
    $('#qs_save').on('click', function() {
        var name = $('#qs_name').val().trim();
        if (!name) { alert('Name is required.'); return; }

        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                action: 'quick_add_supplier',
                name: name,
                phone: $('#qs_phone').val().trim(),
                email: $('#qs_email').val().trim()
            },
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    // Add to the dropdown and select it
                    var newOpt = new Option(resp.name + ' (Owed: 0.00)', resp.id, true, true);
                    $('#supplier_id').append(newOpt).trigger('change');
                    $('#quickSupplierModal').modal('hide');
                } else {
                    alert('Error: ' + (resp.message || 'unknown'));
                }
            }
        });
    });

    // === Existing product details logic (unchanged) ===
    function updateAddDetailsButton() {
        var expenseId = $('#expense_id').val();
        $('.btn-add-details').prop('disabled', !(expenseId && expenseId !== ''));
    }
    updateAddDetailsButton();

    $('#productDetailsModal').on('show.bs.modal', function(e) {
        var expenseId = $('#expense_id').val();
        var expenseCategory = $('#expense_categories_id option:selected').text();
        
        if (!expenseId || expenseId === '') {
            expenseId = $('#modal_expense_id').val();
            if (expenseId && expenseId !== '') {
                $('#expenseCategoryLabel').text(' - ' + expenseCategory);
                loadProducts();
                loadInventoryDetails(expenseId);
                return true;
            }
            alert('Please save the expense first before adding product details.');
            $('#productDetailsModal').modal('hide');
            return false;
        }
        
        $('#modal_expense_id').val(expenseId);
        $('#expenseCategoryLabel').text(' - ' + expenseCategory);
        loadProducts();
        loadInventoryDetails(expenseId);
    });
    
    $('#btnCloseProductDetails').click(function() {
        $('#productDetailsModal').modal('hide');
    });
    
    $('#btnOpenAddProductModal').click(function() {
        var currentExpenseId = $('#modal_expense_id').val();
        var currentCategory = $('#expenseCategoryLabel').text();
        
        $('#productDetailsModal').modal('hide');
        
        $('#addProductModal').data('parentExpenseId', currentExpenseId);
        $('#addProductModal').data('parentCategory', currentCategory);
        $('#addProductModal').data('sourceModal', 'productDetailsModal');
        
        setTimeout(function() { $('#addProductModal').modal('show'); }, 300);
    });
    
    $('#addProductModal').on('hidden.bs.modal', function() {
        var sourceModal = $('#addProductModal').data('sourceModal');
        var parentExpenseId = $('#addProductModal').data('parentExpenseId');
        
        if (sourceModal === 'productDetailsModal' && parentExpenseId) {
            setTimeout(function() {
                $('#productDetailsModal').modal('show');
                loadProducts();
            }, 300);
        }
        $('#addProductModal').removeData('sourceModal');
    });
    
    $('#btnCancelProduct').click(function() {
        $('#new_product_name').val('');
        $('#new_category').val('');
        $('#new_unit').val('');
        $('#addProductModal').modal('hide');
    });
    
    function loadProducts() {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { action: 'get_products' },
            dataType: 'json',
            success: function(response) {
                if (response.success && response.products) {
                    var $productSelect = $('#product_id');
                    var currentVal = $productSelect.val();
                    $productSelect.empty().append('<option value="">-- Select Product --</option>');
                    
                    $.each(response.products, function(i, product) {
                        $productSelect.append('<option value="' + product.id + '">' + escapeHtml(product.product_name) + ' (' + escapeHtml(product.category) + ' - ' + escapeHtml(product.unit) + ')</option>');
                    });
                    
                    if (currentVal) $productSelect.val(currentVal);
                }
            }
        });
    }
    
    function loadInventoryDetails(expenseId) {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { action: 'get_inventory_details', expense_id: expenseId },
            dataType: 'json',
            success: function(response) {
                if (response.success && response.details) {
                    var $tbody = $('#inventoryDetailsTable tbody');
                    $tbody.empty();
                    
                    if (response.details.length === 0) {
                        $tbody.append('<tr id="noDetailsRow"><td colspan="8" class="text-center">No product details added yet</td></tr>');
                        $('#inventoryTotal').text('0.00');
                    } else {
                        var total = 0;
                        $.each(response.details, function(i, detail) {
                            total += parseFloat(detail.total_price);
                            var row = '<tr data-detail-id="' + detail.id + '">';
                            row += '<td>' + escapeHtml(detail.product_name) + '</td>';
                            row += '<td>' + escapeHtml(detail.category) + '</td>';
                            row += '<td class="text-right">' + parseFloat(detail.quantity).toFixed(2) + '</td>';
                            row += '<td>' + escapeHtml(detail.unit) + '</td>';
                            row += '<td class="text-right">' + parseFloat(detail.unit_price).toFixed(2) + '</td>';
                            row += '<td class="text-right">' + parseFloat(detail.total_price).toFixed(2) + '</td>';
                            row += '<td>' + escapeHtml(detail.description || '-') + '</td>';
                            row += '<td class="text-center"><button type="button" class="btn btn-xs btn-danger delete-detail-btn" data-detail-id="' + detail.id + '"><span class="glyphicon glyphicon-trash"></span> Delete</button></td>';
                            row += '</tr>';
                            $tbody.append(row);
                        });
                        $('#inventoryTotal').text(total.toFixed(2));
                    }
                }
            }
        });
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        return String(text).replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }
    
    $('#btnAddDetail').click(function() {
        var expenseId = $('#modal_expense_id').val();
        var productId = $('#product_id').val();
        var quantity = $('#quantity').val();
        var unitPrice = $('#unit_price').val();
        var description = $('#detail_description').val();
        
        if (!expenseId) { alert('Expense ID not found.'); return; }
        if (!productId) { alert('Please select a product.'); return; }
        if (!quantity || parseFloat(quantity) <= 0) { alert('Please enter a valid quantity.'); return; }
        if (!unitPrice || parseFloat(unitPrice) <= 0) { alert('Please enter a valid unit price.'); return; }
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                action: 'add_inventory_detail',
                expense_id: expenseId,
                product_id: productId,
                quantity: quantity,
                unit_price: unitPrice,
                description: description
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#product_id').val('');
                    $('#quantity').val('1');
                    $('#unit_price').val('');
                    $('#detail_description').val('');
                    loadInventoryDetails(expenseId);
                } else {
                    alert('Error: ' + response.message);
                }
            }
        });
    });
    
    $(document).on('click', '.delete-detail-btn', function() {
        var detailId = $(this).data('detail-id');
        var expenseId = $('#modal_expense_id').val();
        
        if (!confirm('Are you sure you want to delete this product detail?')) return;
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { action: 'delete_inventory_detail', detail_id: detailId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    loadInventoryDetails(expenseId);
                } else {
                    alert('Error: ' + response.message);
                }
            }
        });
    });
    
    $('#btnSaveProduct').click(function() {
        var productName = $('#new_product_name').val().trim();
        var category = $('#new_category').val();
        var unit = $('#new_unit').val().trim();
        
        if (!productName) { alert('Please enter product name.'); return; }
        if (!category) { alert('Please select a category.'); return; }
        if (!unit) { alert('Please enter unit.'); return; }
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                action: 'add_product',
                product_name: productName,
                category: category,
                unit: unit
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#new_product_name').val('');
                    $('#new_category').val('');
                    $('#new_unit').val('');
                    $('#addProductModal').modal('hide');
                    $('#addProductModal').data('newProductId', response.product_id);
                    alert(response.message);
                } else {
                    alert('Error: ' + response.message);
                }
            }
        });
    });
    
    $(document).on('click', '.view-details-btn', function() {
        var expenseId = $(this).data('expense-id');
        var expenseCategory = $(this).data('expense-category');
        
        $('#modal_expense_id').val(expenseId);
        $('#expense_id').val(expenseId);
        $('#expenseCategoryLabel').text(' - ' + expenseCategory);
        
        loadProducts();
        loadInventoryDetails(expenseId);
        $('#productDetailsModal').modal('show');
    });
    
    $('#expenseForm').on('submit', function(e) {
        var totalAmount = parseFloat($('#total_amount').val()) || 0;
        var category = $('#expense_categories_id').val();
        var supplier = $('#supplier_id').val();
        var paymentType = $('#payment_type').val();
        
        if (!category || category === "") {
            e.preventDefault();
            alert('Please select an expense category');
            return false;
        }
        
        if (totalAmount == 0) {
            e.preventDefault();
            alert('Amount cannot be zero');
            return false;
        }

        // If no supplier, payment type required
        if ((!supplier || supplier === '') && (!paymentType || paymentType === '')) {
            e.preventDefault();
            alert('Payment type is required when not using a supplier');
            return false;
        }
        
        return true;
    });

    setTimeout(function() {
        $('.custom-alert').fadeOut('slow');
    }, 10000);
});
</script>

</body>
<?php if (isset($conn)) $conn->close(); ?>
</html>