<?php
/**
 * gl.php — General Ledger helper library
 *
 * Requires: conn_inc.php (provides $conn — mysqli connection)
 *
 * All write operations go through postJournalEntry() which calls the
 * sp_post_transaction stored procedure. Never INSERT directly into
 * gl_transactions or gl_journal_lines from application code.
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once __DIR__ . '/conn_inc.php';
}

// ---------------------------------------------------------------------------
// Account helpers
// ---------------------------------------------------------------------------

/**
 * Fetch a single account by ID. Returns associative array or null.
 */
function gl_get_account(mysqli $conn, int $accountId): ?array {
    $stmt = $conn->prepare("
       SELECT id, code, name, account_type, parent_id, is_postable, is_system, is_contra, status
FROM gl_accounts
WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $accountId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Fetch a single account by code (e.g. '1010'). Returns associative array or null.
 */
function gl_get_account_by_code(mysqli $conn, string $code): ?array {
    $stmt = $conn->prepare("
SELECT id, code, name, account_type, parent_id, is_postable, is_system, is_contra, status
        FROM gl_accounts
        WHERE code = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Fetch all accounts, ordered by code. Optionally filter by type and/or status.
 *
 * @param string|null $type   One of ASSET, LIABILITY, EQUITY, REVENUE, EXPENSE — or null for all.
 * @param bool        $activeOnly  If true, only status = 1.
 */
function gl_list_accounts(mysqli $conn, ?string $type = null, bool $activeOnly = true): array {
$sql = "SELECT id, code, name, account_type, parent_id, is_postable, is_system, is_contra, status
            FROM gl_accounts
            WHERE 1=1";
    $params = [];
    $types  = "";

    if ($type !== null) {
        $sql .= " AND account_type = ?";
        $params[] = $type;
        $types .= "s";
    }
    if ($activeOnly) {
        $sql .= " AND status = 1";
    }
    $sql .= " ORDER BY code";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($r = $result->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
    return $rows;
}

/**
 * Suggest the next code under a given parent account.
 *
 * Convention: children share the parent's first 2 digits.
 *   Parent "1000" → children "1010, 1020, 1030, 1040, ..."
 *   Parent "1500" → children "1510, 1520, 1530, ..."
 *
 * When the suffix would exceed 99, rolls over to the next hundred.
 * Example: last child "5990" → suggests "6000"
 */
function gl_suggest_next_code(mysqli $conn, string $parentCode): ?string {
    $codeLen = strlen($parentCode);
    $sharedPrefix = substr($parentCode, 0, 2);

    $stmt = $conn->prepare("
        SELECT code FROM gl_accounts
        WHERE code LIKE CONCAT(?, '%')
          AND code <> ?
          AND LENGTH(code) = ?
        ORDER BY CAST(SUBSTRING(code, 3) AS UNSIGNED) DESC
        LIMIT 1
    ");
    $stmt->bind_param("ssi", $sharedPrefix, $parentCode, $codeLen);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Fallback: first child under this prefix
    if (!$row) {
        return $sharedPrefix . '10';
    }

    $lastCode  = $row['code'];
    $suffixStr = substr($lastCode, 2);
    $suffixNum = (int)$suffixStr;
    $nextNum   = $suffixNum + 10;

    // Rollover: if suffix exceeds 99, go to next hundred
    if ($nextNum > 99) {
        $baseCode = (int)$lastCode;
        $nextBase = (floor($baseCode / 100) + 1) * 100;
        return str_pad((string)$nextBase, $codeLen, '0', STR_PAD_LEFT);
    }

    return $sharedPrefix . str_pad((string)$nextNum, 2, '0', STR_PAD_LEFT);
}

// ---------------------------------------------------------------------------
// Balance / reporting helpers
// ---------------------------------------------------------------------------

/**
 * Balance of a single account as of a date. Positive = normal balance direction.
 * Returns a float. For ASSET/EXPENSE, positive = debit-heavy. For LIABILITY/
 * EQUITY/REVENUE, positive = credit-heavy.
 */
function gl_account_balance(mysqli $conn, int $accountId, string $asOfDate): float {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(l.debit - l.credit), 0) AS bal
        FROM gl_journal_lines l
        JOIN gl_transactions t ON t.id = l.transaction_id
        WHERE l.account_id = ?
          AND t.entry_date <= ?
    ");
    $stmt->bind_param("is", $accountId, $asOfDate);
    $stmt->execute();
    $bal = (float)$stmt->get_result()->fetch_assoc()['bal'];
    $stmt->close();
    return $bal;
}

/**
 * Balance of a single account, sign-adjusted for reporting.
 * ASSET / EXPENSE → positive means debit-heavy
 * LIABILITY / EQUITY / REVENUE → positive means credit-heavy (debit - credit is negated)
 */
function gl_account_balance_signed(mysqli $conn, int $accountId, string $asOfDate): float {
    $acc = gl_get_account($conn, $accountId);
    if (!$acc) return 0.0;
    $raw = gl_account_balance($conn, $accountId, $asOfDate);

    // Contra accounts are debit-normal regardless of type.
    // Normal accounts follow their type's natural direction.
    $isContra = !empty($acc['is_contra']);
    if ($isContra) {
        return $raw;   // debit - credit is already the correct sign
    }
    if (in_array($acc['account_type'], ['LIABILITY', 'EQUITY', 'REVENUE'], true)) {
        return -$raw;
    }
    return $raw;
}

/**
 * Full trial balance — every postable account with its balance as of a date.
 * Returns rows: code, name, account_type, debit_total, credit_total, balance.
 */
function gl_trial_balance(mysqli $conn, string $asOfDate): array {
    $stmt = $conn->prepare("
       SELECT
    a.id, a.code, a.name, a.account_type, a.is_contra,
    COALESCE(SUM(l.debit),  0) AS debit_total,
    COALESCE(SUM(l.credit), 0) AS credit_total,
    COALESCE(SUM(l.debit - l.credit), 0) AS net
FROM gl_accounts a
        LEFT JOIN gl_journal_lines l ON l.account_id = a.id
        LEFT JOIN gl_transactions t ON t.id = l.transaction_id
                                   AND t.entry_date <= ?
                                   AND t.status = 'POSTED'
        WHERE a.is_postable = 1 AND a.status = 1
GROUP BY a.id, a.code, a.name, a.account_type, a.is_contra
        ORDER BY a.code
    ");
    $stmt->bind_param("s", $asOfDate);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($r = $result->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
    return $rows;
}

// ---------------------------------------------------------------------------
// Posting helper — the only legal write path
// ---------------------------------------------------------------------------

/**
 * Post a journal entry via sp_post_transaction.
 *
 * @param mysqli $conn
 * @param array  $entry {
 *     entry_date:   'YYYY-MM-DD',
 *     session_id:   int,
 *     description:  string,
 *     ref_type:     string|null,
 *     ref_id:       int|null,
 *     posted_by:    int,
 *     idempotency:  string|null,   // auto-generated if null
 *     lines: [
 *         ['account_id' => int, 'debit' => float, 'credit' => float, 'memo' => string|null],
 *         ...
 *     ]
 * }
 * @return int   The transaction id, or 0 on failure (with $error populated).
 */
function post_journal_entry(mysqli $conn, array $entry, ?string &$error = null): int {
    // Generate idempotency key if not provided
    $idem = $entry['idempotency']
          ?? ('auto-' . bin2hex(random_bytes(16)));

    // Validate locally before hitting the DB (better error messages)
    if (empty($entry['lines']) || count($entry['lines']) < 2) {
        $error = 'At least 2 journal lines are required.';
        return 0;
    }
    $totalDebit = 0.0;
    $totalCredit = 0.0;
    foreach ($entry['lines'] as $i => $line) {
        $d = round((float)($line['debit']  ?? 0), 2);
        $c = round((float)($line['credit'] ?? 0), 2);
        if ($d < 0 || $c < 0) { $error = "Line " . ($i+1) . ": negative amount."; return 0; }
        if (!(($d > 0 && $c == 0) || ($d == 0 && $c > 0))) {
            $error = "Line " . ($i+1) . ": must be a debit XOR a credit.";
            return 0;
        }
        $totalDebit  += $d;
        $totalCredit += $c;
    }
    if (round($totalDebit, 2) !== round($totalCredit, 2)) {
        $error = "Unbalanced: debits = " . number_format($totalDebit, 2)
               . ", credits = " . number_format($totalCredit, 2);
        return 0;
    }

    // Build JSON array of lines for the procedure
    $lines = [];
    foreach ($entry['lines'] as $line) {
        $lines[] = [
            'account_id' => (int)($line['account_id'] ?? 0),
            'debit'      => round((float)($line['debit']  ?? 0), 2),
            'credit'     => round((float)($line['credit'] ?? 0), 2),
            'memo'       => $line['memo'] ?? null,
            'party_id'   => $line['party_id'] ?? null,
        ];
    }
    $linesJson = json_encode($lines, JSON_UNESCAPED_UNICODE);

    // Call the procedure
    $stmt = $conn->prepare("
        CALL sp_post_transaction(?, ?, ?, ?, ?, ?, ?, ?, @txn_id)
    ");
    $refType = $entry['ref_type'] ?? null;
    $refId   = $entry['ref_id']   ?? null;
    $stmt->bind_param(
        "ssissiis",
        $idem,
        $entry['entry_date'],
        $entry['session_id'],
        $entry['description'],
        $refType,
        $refId,
        $entry['posted_by'],
        $linesJson
    );
    if (!$stmt->execute()) {
        $error = 'DB call failed: ' . $stmt->error;
        $stmt->close();
        return 0;
    }
    $stmt->close();

    // Read OUT parameter
    $res = $conn->query("SELECT @txn_id AS txn_id");
    $row = $res->fetch_assoc();
    $txnId = (int)($row['txn_id'] ?? 0);

    if ($txnId <= 0) {
        $error = 'Transaction was not created (procedure returned id 0).';
        return 0;
    }
    return $txnId;
}

/**
 * Post a reversal of an existing transaction.
 * Returns the new (reversal) transaction id, or 0 with $error set.
 */
function reverse_journal_entry(mysqli $conn, int $originalTxnId, string $reason, int $postedBy, ?string &$error = null): int {
    $stmt = $conn->prepare("CALL sp_reverse_transaction(?, ?, ?, @rev_id)");
    $stmt->bind_param("isi", $originalTxnId, $reason, $postedBy);
    if (!$stmt->execute()) {
        $error = 'Reversal call failed: ' . $stmt->error;
        $stmt->close();
        return 0;
    }
    $stmt->close();

    $res = $conn->query("SELECT @rev_id AS rev_id");
    $row = $res->fetch_assoc();
    $revId = (int)($row['rev_id'] ?? 0);
    if ($revId <= 0) {
        $error = 'Reversal was not created (procedure returned id 0).';
        return 0;
    }
    return $revId;
}

// ---------------------------------------------------------------------------
// Misc helpers
// ---------------------------------------------------------------------------

/**
 * Human-readable label for an account_type.
 */
function gl_type_label(string $type): string {
    return [
        'ASSET'     => 'Asset',
        'LIABILITY' => 'Liability',
        'EQUITY'    => 'Equity',
        'REVENUE'   => 'Revenue',
        'EXPENSE'   => 'Expense',
    ][$type] ?? $type;
}

/**
 * Bootstrap label class for an account_type (used in UI).
 */
function gl_type_badge(string $type): string {
    return [
        'ASSET'     => 'primary',
        'LIABILITY' => 'warning',
        'EQUITY'    => 'success',
        'REVENUE'   => 'info',
        'EXPENSE'   => 'danger',
    ][$type] ?? 'default';
}

/**
 * Get the current active session id (from sessions table).
 * Returns null if none found.
 */
function gl_current_session_id(mysqli $conn): ?int {
    $res = $conn->query("SELECT id FROM sessions WHERE status = 0 ORDER BY id DESC LIMIT 1");
    if (!$res || $res->num_rows === 0) return null;
    return (int)$res->fetch_assoc()['id'];
}

// ---------------------------------------------------------------------------
// Fee type → Revenue account mapping
// ---------------------------------------------------------------------------

/**
 * Look up the revenue account id for a given fee_type_id.
 * Returns 0 if no mapping found AND no fallback available.
 */
function gl_revenue_account_for_fee_type(mysqli $conn, int $feeTypeId): int {
    $stmt = $conn->prepare("
        SELECT revenue_account_id FROM fee_type_gl_map
        WHERE fee_type_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $feeTypeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row && (int)$row['revenue_account_id'] > 0) {
        return (int)$row['revenue_account_id'];
    }
    // Fallback: Misc Income
    $fallback = gl_get_account_by_code($conn, '4530');
    return $fallback ? (int)$fallback['id'] : 0;
}

/**
 * Cash vs Bank based on payment method.
 */
function gl_cash_account_for_method(mysqli $conn, string $method): int {
    $method = strtolower(trim($method));
    $code = ($method === 'cash') ? '1010' : '1020';
    $acc = gl_get_account_by_code($conn, $code);
    return $acc ? (int)$acc['id'] : 0;
}

/**
 * Student fee receivable account (1030).
 */
function gl_receivable_account(mysqli $conn): int {
    $acc = gl_get_account_by_code($conn, '1030');
    return $acc ? (int)$acc['id'] : 0;
}

/**
 * Advance fee received account (2020).
 */
function gl_advance_received_account(mysqli $conn): int {
    $acc = gl_get_account_by_code($conn, '2020');
    return $acc ? (int)$acc['id'] : 0;
}

/**
 * Discounts given account (4090) — contra-revenue.
 */
function gl_discount_account(mysqli $conn): int {
    $acc = gl_get_account_by_code($conn, '4090');
    return $acc ? (int)$acc['id'] : 0;
}

/**
 * Look up the salary GL account for a given role_id.
 * Returns 0 if no mapping found (role should NOT be processed).
 */
function gl_salary_account_for_role(mysqli $conn, int $roleId): int {
    $stmt = $conn->prepare("
        SELECT salary_account_id FROM role_gl_map
        WHERE role_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $roleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ($row && (int)$row['salary_account_id'] > 0)
        ? (int)$row['salary_account_id']
        : 0;
}

/**
 * Get the users.id from users table, along with role_id, for salary processing.
 * (Small helper for the salary page.)
 */
function gl_get_user_role(mysqli $conn, int $userId): ?int {
    $stmt = $conn->prepare("SELECT role_id FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['role_id'] : null;
}

/**
 * Look up the expense GL account for a given expense_category_id.
 * Returns 0 if no mapping found.
 */
function gl_expense_account_for_category(mysqli $conn, int $categoryId): int {
    $stmt = $conn->prepare("
        SELECT expense_account_id FROM expense_category_gl_map
        WHERE expense_category_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $categoryId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ($row && (int)$row['expense_account_id'] > 0)
        ? (int)$row['expense_account_id']
        : 0;
}
/**
 * Return the GL account id for an expense payment method.
 *   Cash          → 1010 Cash in Hand
 *   Bank/Cheque   → 1020 Cash at Bank
 */
function gl_payment_account_for_expense(mysqli $conn, string $method): int {
    $method = strtolower(trim($method));
    $code = ($method === 'cash') ? '1010' : '1020';
    $acc = gl_get_account_by_code($conn, $code);
    return $acc ? (int)$acc['id'] : 0;
}

// ---------------------------------------------------------------------------
// Report helpers
// ---------------------------------------------------------------------------

/**
 * Get all postable accounts with their signed balances as of a date.
 * Contra accounts have their sign flipped.
 */
function gl_get_balances(mysqli $conn, string $asOfDate): array {
    $stmt = $conn->prepare("
        SELECT
            a.id, a.code, a.name, a.account_type, a.is_contra,
            COALESCE(SUM(l.debit),  0) AS total_debit,
            COALESCE(SUM(l.credit), 0) AS total_credit
        FROM gl_accounts a
        LEFT JOIN gl_journal_lines l ON l.account_id = a.id
        LEFT JOIN gl_transactions t ON t.id = l.transaction_id
                                   AND t.entry_date <= ?
        WHERE a.is_postable = 1 AND a.status = 1
        GROUP BY a.id, a.code, a.name, a.account_type, a.is_contra
        ORDER BY a.code
    ");
    $stmt->bind_param("s", $asOfDate);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$r) {
        $net = (float)$r['total_debit'] - (float)$r['total_credit'];
        $isDebitNormal = in_array($r['account_type'], ['ASSET','EXPENSE'], true) || (int)$r['is_contra'] === 1;
        $r['signed_balance'] = $isDebitNormal ? $net : -$net;
        $r['net_debit_credit'] = $net;
    }
    return $rows;
}

/**
 * Format a number for accounting display.
 */
function gl_fmt($n) {
    if (abs($n) < 0.005) return '';
    return number_format((float)$n, 2);
}

/**
 * Save or update a mapping.
 * Table names are whitelisted to prevent SQL injection.
 * Returns true on success, false on failure.
 */
function gl_save_mapping(mysqli $conn, string $table, string $masterCol, int $masterId, string $accountCol, int $accountId): bool {
    $allowedTables = [
        'fee_type_gl_map'           => ['master' => 'fee_type_id',           'account' => 'revenue_account_id'],
        'expense_category_gl_map'   => ['master' => 'expense_category_id',   'account' => 'expense_account_id'],
        'role_gl_map'               => ['master' => 'role_id',               'account' => 'salary_account_id'],
    ];

    if (!isset($allowedTables[$table])) {
        return false;
    }

    $masterColReal  = $allowedTables[$table]['master'];
    $accountColReal = $allowedTables[$table]['account'];

    // Check if a row already exists
    $check = $conn->prepare("SELECT id FROM `$table` WHERE `$masterColReal` = ? LIMIT 1");
    $check->bind_param("i", $masterId);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();

    if ($existing) {
        $upd = $conn->prepare("UPDATE `$table` SET `$accountColReal` = ? WHERE `$masterColReal` = ?");
        $upd->bind_param("ii", $accountId, $masterId);
        $ok = $upd->execute();
        $upd->close();
        return $ok;
    } else {
        $ins = $conn->prepare("INSERT INTO `$table` (`$masterColReal`, `$accountColReal`) VALUES (?, ?)");
        $ins->bind_param("ii", $masterId, $accountId);
        $ok = $ins->execute();
        $ins->close();
        return $ok;
    }
}
/**
 * Suggest the next top-level code for a given account type.
 * Used when adding a new top-level account (no parent chosen).
 *
 * Example: Assets have 1000, 1500 → suggests 1600
 */
function gl_suggest_next_code_top_level(mysqli $conn, string $accountType): ?string {
    $stmt = $conn->prepare("
        SELECT code FROM gl_accounts
        WHERE account_type = ?
          AND parent_id IS NULL
        ORDER BY CAST(code AS UNSIGNED) DESC
        LIMIT 1
    ");
    $stmt->bind_param("s", $accountType);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $typeStart = [
        'ASSET'     => '1000',
        'LIABILITY' => '2000',
        'EQUITY'    => '3000',
        'REVENUE'   => '4000',
        'EXPENSE'   => '5000',
    ];

    if (!$row) {
        return $typeStart[$accountType] ?? '1000';
    }

    // Top-level accounts increment by 100 to leave room for children
    $next = ((int)$row['code']) + 100;
    return str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

/**
 * Fetch recent journal lines for a given account, for the quick-ledger modal.
 * Returns an array of ['entry_date', 'description', 'reference_type', 'reference_id',
 *                      'debit', 'credit', 'memo', 'txn_id', 'status', 'reversal_of'].
 */
function gl_quick_ledger(mysqli $conn, int $accountId, string $fromDate, string $toDate, int $limit = 20): array {
    $stmt = $conn->prepare("
        SELECT
            t.id AS txn_id,
            t.entry_date,
            t.description,
            t.reference_type,
            t.reference_id,
            t.status,
            t.reversal_of,
            l.debit,
            l.credit,
            l.memo
        FROM gl_journal_lines l
        JOIN gl_transactions t ON t.id = l.transaction_id
        WHERE l.account_id = ?
          AND t.entry_date BETWEEN ? AND ?
        ORDER BY t.entry_date DESC, t.id DESC, l.line_no DESC
        LIMIT ?
    ");
    $stmt->bind_param("issi", $accountId, $fromDate, $toDate, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Compute opening and closing balances for an account within a date range.
 */
function gl_quick_ledger_balances(mysqli $conn, int $accountId, string $fromDate, string $toDate): array {
    // Opening = sum of everything before fromDate
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(l.debit - l.credit), 0) AS bal
        FROM gl_journal_lines l
        JOIN gl_transactions t ON t.id = l.transaction_id
        WHERE l.account_id = ? AND t.entry_date < ?
    ");
    $stmt->bind_param("is", $accountId, $fromDate);
    $stmt->execute();
    $opening = (float)$stmt->get_result()->fetch_assoc()['bal'];
    $stmt->close();

    // Closing = sum of everything up to and including toDate
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(l.debit - l.credit), 0) AS bal
        FROM gl_journal_lines l
        JOIN gl_transactions t ON t.id = l.transaction_id
        WHERE l.account_id = ? AND t.entry_date <= ?
    ");
    $stmt->bind_param("is", $accountId, $toDate);
    $stmt->execute();
    $closing = (float)$stmt->get_result()->fetch_assoc()['bal'];
    $stmt->close();

    return [
        'opening' => $opening,
        'closing' => $closing,
    ];
}