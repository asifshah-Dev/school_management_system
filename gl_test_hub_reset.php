<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required.']);
    exit;
}

$scope   = $_POST['scope']   ?? '';
$confirm = $_POST['confirm'] ?? '';

if (!in_array($scope, ['ledger_only', 'ledger_and_operational'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid scope.']);
    exit;
}
$expected = 'RESET-' . strtoupper($scope);
if ($confirm !== $expected) {
    echo json_encode(['ok' => false, 'error' => "Confirmation text must be exactly: $expected"]);
    exit;
}

/* ============================================================
   HARDCODED IMMUTABILITY TRIGGERS
   ============================================================ */
$IMMUTABILITY_TRIGGERS = [
    [
        'name' => 'trg_gl_lines_no_update',
        'sql'  => "CREATE TRIGGER `trg_gl_lines_no_update` BEFORE UPDATE ON `gl_journal_lines`
                   FOR EACH ROW
                   BEGIN
                     SIGNAL SQLSTATE '45000'
                       SET MESSAGE_TEXT = 'Journal lines are immutable. Post a reversal instead.';
                   END",
    ],
    [
        'name' => 'trg_gl_lines_no_delete',
        'sql'  => "CREATE TRIGGER `trg_gl_lines_no_delete` BEFORE DELETE ON `gl_journal_lines`
                   FOR EACH ROW
                   BEGIN
                     SIGNAL SQLSTATE '45000'
                       SET MESSAGE_TEXT = 'Journal lines are immutable. Post a reversal instead.';
                   END",
    ],
    [
        'name' => 'trg_gl_txn_no_update',
        'sql'  => "CREATE TRIGGER `trg_gl_txn_no_update` BEFORE UPDATE ON `gl_transactions`
                   FOR EACH ROW
                   BEGIN
                     IF OLD.status = 'POSTED' AND NEW.status = 'REVERSED' THEN
                       IF OLD.idempotency_key <> NEW.idempotency_key
                          OR OLD.entry_date     <> NEW.entry_date
                          OR OLD.session_id     <> NEW.session_id
                          OR OLD.description    <> NEW.description
                          OR OLD.posted_by      <> NEW.posted_by
                          OR (OLD.reference_type <=> NEW.reference_type) = 0
                          OR (OLD.reference_id   <=> NEW.reference_id)   = 0
                          OR (OLD.reversal_of    <=> NEW.reversal_of)    = 0 THEN
                         SIGNAL SQLSTATE '45000'
                           SET MESSAGE_TEXT = 'Only the status field may change on a posted transaction.';
                       END IF;
                     ELSEIF OLD.status = 'REVERSED' THEN
                       SIGNAL SQLSTATE '45000'
                         SET MESSAGE_TEXT = 'A REVERSED transaction cannot be modified.';
                     ELSE
                       SIGNAL SQLSTATE '45000'
                         SET MESSAGE_TEXT = 'Posted transactions are immutable. Post a reversal instead.';
                     END IF;
                   END",
    ],
    [
        'name' => 'trg_gl_txn_no_delete',
        'sql'  => "CREATE TRIGGER `trg_gl_txn_no_delete` BEFORE DELETE ON `gl_transactions`
                   FOR EACH ROW
                   BEGIN
                     SIGNAL SQLSTATE '45000'
                       SET MESSAGE_TEXT = 'Transactions are immutable. Post a reversal instead.';
                   END",
    ],
];

/* ============================================================
   HELPERS
   ============================================================ */
function wipe_table(mysqli $conn, string $table, array &$report): void {
    $t = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    if (!$t || $t->num_rows === 0) {
        $report[$table] = 'skipped (no such table)';
        return;
    }
    $r = $conn->query("SELECT COUNT(*) AS c FROM `$table`");
    $count = $r ? (int)$r->fetch_assoc()['c'] : 0;
    $conn->query("DELETE FROM `$table`");
    $conn->query("ALTER TABLE `$table` AUTO_INCREMENT = 1");
    $report[$table] = $count;
}

function drop_trigger(mysqli $conn, string $name): void {
    $conn->query("DROP TRIGGER IF EXISTS `" . $conn->real_escape_string($name) . "`");
}

function recreate_trigger(mysqli $conn, array $t): void {
    if (!$conn->query($t['sql'])) {
        throw new Exception("Failed to recreate trigger {$t['name']}: " . $conn->error);
    }
}

/* ============================================================
   RUN THE RESET
   ============================================================ */
$report      = [];
$droppedAll  = false;

try {
    // Step 1 — drop immutability triggers so DELETE is allowed.
    foreach ($IMMUTABILITY_TRIGGERS as $t) {
        drop_trigger($conn, $t['name']);
    }
    $droppedAll = true;

    // Step 2 — run all DELETEs inside a transaction.
    $conn->begin_transaction();

    /* ============================================================
       OPERATIONAL TABLES (full-reset scope only)
       ============================================================ */
    if ($scope === 'ledger_and_operational') {
        wipe_table($conn, 'fee_payments',              $report);
        wipe_table($conn, 'student_fee_card',          $report);
        wipe_table($conn, 'advance_fees',              $report);
        wipe_table($conn, 'user_salary',               $report);
        wipe_table($conn, 'expanse_inventory_details', $report);
        wipe_table($conn, 'expenses',                  $report);
    }

    /* ============================================================
       PETTY CASH
       ============================================================ */
    wipe_table($conn, 'petty_cash_counts', $report);

    /* ============================================================
       BANK RECONCILIATION TABLES (children first)
       ============================================================ */
    wipe_table($conn, 'bank_recon_ledger_marks', $report);
    wipe_table($conn, 'bank_statement_lines',    $report);
    wipe_table($conn, 'bank_reconciliations',    $report);

    /* ============================================================
       VOUCHERS
       ============================================================ */
    wipe_table($conn, 'vouchers',            $report);
    wipe_table($conn, 'voucher_counters',    $report);

    /* ============================================================
       SESSION + YEAR-END CLOSURES
       ============================================================ */
    wipe_table($conn, 'gl_session_closures',  $report);
    wipe_table($conn, 'gl_year_end_closures', $report);

    /* ============================================================
       JOURNAL LINES (child of gl_transactions)
       ============================================================ */
    wipe_table($conn, 'gl_journal_lines', $report);

    /* ============================================================
       GL TRANSACTIONS (child of itself via fk_gl_txn_reversal)
       ============================================================ */
    $tblExists = $conn->query("SHOW TABLES LIKE 'gl_transactions'");
    if ($tblExists && $tblExists->num_rows > 0) {
        $r = $conn->query("SELECT COUNT(*) AS c FROM gl_transactions");
        $report['gl_transactions'] = $r ? (int)$r->fetch_assoc()['c'] : 0;

        // Break the self-FK first: clear reversal_of on all reversal rows.
        $conn->query("UPDATE gl_transactions SET reversal_of = NULL WHERE reversal_of IS NOT NULL");
        // Then delete everything.
        $conn->query("DELETE FROM gl_transactions");
        $conn->query("ALTER TABLE gl_transactions AUTO_INCREMENT = 1");
    }

    /* ============================================================
       SETTINGS
       Reset the lock_date back to empty (no lock).
       ============================================================ */
    $settingsExists = $conn->query("SHOW TABLES LIKE 'gl_settings'");
    if ($settingsExists && $settingsExists->num_rows > 0) {
        $conn->query("UPDATE gl_settings SET setting_value = '' WHERE setting_key = 'lock_date'");
        $report['gl_settings'] = 'lock_date cleared';
    } else {
        $report['gl_settings'] = 'skipped (no such table)';
    }

    $conn->commit();

    // Step 3 — recreate the triggers.
    $recreateErrors = [];
    foreach ($IMMUTABILITY_TRIGGERS as $t) {
        try {
            recreate_trigger($conn, $t);
        } catch (Exception $ex) {
            $recreateErrors[] = $ex->getMessage();
        }
    }

    if (!empty($recreateErrors)) {
        echo json_encode([
            'ok'     => false,
            'error'  => 'Wipe succeeded but triggers could not be recreated. '
                       . 'Recreate them manually from SHOW CREATE TRIGGER. Errors: '
                       . implode(' | ', $recreateErrors),
            'report' => $report,
        ]);
        exit;
    }

    echo json_encode([
        'ok'               => true,
        'scope'            => $scope,
        'report'           => $report,
        'triggers_restored'=> count($IMMUTABILITY_TRIGGERS),
        'msg'              => 'Reset complete. Triggers restored.',
    ]);
    exit;

} catch (Exception $e) {
    try { $conn->rollback(); } catch (Exception $x) {}

    if ($droppedAll) {
        $restoreErrors = [];
        foreach ($IMMUTABILITY_TRIGGERS as $t) {
            try {
                $chk = $conn->query("SHOW TRIGGERS LIKE '" . $conn->real_escape_string($t['name']) . "'");
                if (!$chk || $chk->num_rows === 0) {
                    recreate_trigger($conn, $t);
                }
            } catch (Exception $ex) {
                $restoreErrors[] = $ex->getMessage();
            }
        }
        $msg = $e->getMessage();
        if (!empty($restoreErrors)) {
            $msg .= ' | Trigger restore errors: ' . implode(' | ', $restoreErrors);
        }
        echo json_encode(['ok' => false, 'error' => $msg]);
    } else {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}