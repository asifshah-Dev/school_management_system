<?php
/**
 * VoucherService.php
 *
 * Handles the lifecycle of vouchers:
 *   - createDraft()      Insert a new voucher in DRAFT state
 *   - updateDraft()      Update a draft voucher (only if not yet posted)
 *   - deleteDraft()      Delete a draft voucher (only if not yet posted)
 *   - postVoucher()      Post a voucher to the GL and mark it POSTED
 *   - cancelDraft()      Cancel a draft voucher without posting
 *   - reverseVoucher()   Reverse a posted voucher's GL entry
 *   - getVoucher()       Fetch a single voucher with details
 *   - listVouchers()     Fetch vouchers with filters
 *   - nextVoucherNumber() Reserve the next number from sp_next_voucher_number
 *
 * Requires: conn_inc.php (provides $conn — mysqli connection)
 *           gl.php        (provides post_journal_entry, reverse_journal_entry)
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once __DIR__ . '/conn_inc.php';
}

class VoucherService
{
    private const CASH_ACCOUNT_CODES = [
        'CPV' => '1010',
        'CRV' => '1010',
        'BPV' => '1020',
        'BRV' => '1020',
    ];

    public const BANK_PAYMENT_METHODS = [
        'cheque',
        'online',
        'deposit',
        'ATM',
        'other',
    ];

    public const VOUCHER_TYPES = ['CPV', 'CRV', 'BPV', 'BRV'];

    private mysqli $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    // =========================================================================
    // Number generation
    // =========================================================================

    public function nextVoucherNumber(string $type, int $year): string
    {
        if (!in_array($type, self::VOUCHER_TYPES, true)) {
            throw new InvalidArgumentException("Invalid voucher type: $type");
        }

        $stmt = $this->conn->prepare("CALL sp_next_voucher_number(?, ?, @num)");
        $stmt->bind_param("si", $type, $year);
        $stmt->execute();
        $stmt->close();

        $res = $this->conn->query("SELECT @num AS voucher_number");
        $row = $res->fetch_assoc();
        $res->free();

        $number = $row['voucher_number'] ?? '';
        if ($number === '') {
            throw new RuntimeException("Failed to generate voucher number for $type-$year");
        }
        return $number;
    }

    // =========================================================================
    // Draft lifecycle
    // =========================================================================

    public function createDraft(array $data): array
    {
        $this->validateVoucherData($data, true);

        $year   = (int)substr($data['entry_date'], 0, 4);
        $number = $this->nextVoucherNumber($data['voucher_type'], $year);

        $stmt = $this->conn->prepare("
            INSERT INTO vouchers
              (voucher_type, voucher_number, entry_date, session_id, amount,
               party_name, party_contact, party_id, payment_method, reference_number, bank_name,
               account_id, narration, attachment_path,
               status, created_by)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'DRAFT', ?)
        ");

        $partyId = isset($data['party_id']) && (int)$data['party_id'] > 0
                 ? (int)$data['party_id']
                 : null;

        // Type string: s s s i d s s i s s s i s s i  = "sssidssisssissi" (15 chars)
        $stmt->bind_param(
            "sssidssisssissi",
            $data['voucher_type'],
            $number,
            $data['entry_date'],
            $data['session_id'],
            $data['amount'],
            $data['party_name'],
            $data['party_contact'],
            $partyId,
            $data['payment_method'],
            $data['reference_number'],
            $data['bank_name'],
            $data['account_id'],
            $data['narration'],
            $data['attachment_path'],
            $data['created_by']
        );

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new RuntimeException("Insert failed: $err");
        }
        $id = (int)$this->conn->insert_id;
        $stmt->close();

        return ['id' => $id, 'voucher_number' => $number];
    }

    public function updateDraft(int $voucherId, array $data): void
    {
        $existing = $this->getVoucher($voucherId);
        if (!$existing) {
            throw new RuntimeException("Voucher #$voucherId not found");
        }
        if ($existing['status'] !== 'DRAFT') {
            throw new RuntimeException("Only DRAFT vouchers can be edited. Current status: " . $existing['status']);
        }

        $this->validateVoucherData($data, false);

        $stmt = $this->conn->prepare("
            UPDATE vouchers
               SET entry_date = ?, session_id = ?, amount = ?,
                   party_name = ?, party_contact = ?, party_id = ?,
                   payment_method = ?, reference_number = ?, bank_name = ?,
                   account_id = ?, narration = ?, attachment_path = ?
             WHERE id = ? AND status = 'DRAFT'
        ");

        $partyId = isset($data['party_id']) && (int)$data['party_id'] > 0
                 ? (int)$data['party_id']
                 : null;

        // Type string: s i d s s i s s s i s s i  = "sidsississisii" (13 chars)
        $stmt->bind_param(
            "sidsississisii",
            $data['entry_date'],
            $data['session_id'],
            $data['amount'],
            $data['party_name'],
            $data['party_contact'],
            $partyId,
            $data['payment_method'],
            $data['reference_number'],
            $data['bank_name'],
            $data['account_id'],
            $data['narration'],
            $data['attachment_path'],
            $voucherId
        );

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new RuntimeException("Update failed: $err");
        }
        $stmt->close();
    }

    public function deleteDraft(int $voucherId): void
    {
        $existing = $this->getVoucher($voucherId);
        if (!$existing) {
            throw new RuntimeException("Voucher #$voucherId not found");
        }
        if ($existing['status'] !== 'DRAFT') {
            throw new RuntimeException("Only DRAFT vouchers can be deleted.");
        }

        $stmt = $this->conn->prepare("DELETE FROM vouchers WHERE id = ? AND status = 'DRAFT'");
        $stmt->bind_param("i", $voucherId);
        $stmt->execute();
        $stmt->close();
    }

    // =========================================================================
    // Posting
    // =========================================================================

    public function postVoucher(int $voucherId, int $postedBy): int
    {
        $v = $this->getVoucher($voucherId);
        if (!$v) {
            throw new RuntimeException("Voucher #$voucherId not found");
        }
        if ($v['status'] !== 'DRAFT') {
            throw new RuntimeException("Only DRAFT vouchers can be posted. Current: " . $v['status']);
        }

        $cashCode = self::CASH_ACCOUNT_CODES[$v['voucher_type']];
        $cashAcc  = gl_get_account_by_code($this->conn, $cashCode);
        if (!$cashAcc) {
            throw new RuntimeException("Cash/bank account $cashCode not found in Chart of Accounts");
        }
        $cashAccountId = (int)$cashAcc['id'];

        $otherAcc = gl_get_account($this->conn, (int)$v['account_id']);
        if (!$otherAcc) {
            throw new RuntimeException("Account #{$v['account_id']} not found");
        }

        $amount = round((float)$v['amount'], 2);
        $isPayment = in_array($v['voucher_type'], ['CPV', 'BPV'], true);

        $partyId = isset($v['party_id']) && (int)$v['party_id'] > 0
                 ? (int)$v['party_id']
                 : null;

        $lines = [];
        if ($isPayment) {
            $lines[] = [
                'account_id' => (int)$v['account_id'],
                'debit'      => $amount,
                'credit'     => 0.00,
                'memo'       => $v['narration'] ?: ('Voucher ' . $v['voucher_number']),
                'party_id'   => $partyId,
            ];
            $lines[] = [
                'account_id' => $cashAccountId,
                'debit'      => 0.00,
                'credit'     => $amount,
                'memo'       => 'Paid via ' . strtoupper($v['voucher_type']) . ' ' . $v['voucher_number'],
            ];
        } else {
            $lines[] = [
                'account_id' => $cashAccountId,
                'debit'      => $amount,
                'credit'     => 0.00,
                'memo'       => 'Received via ' . strtoupper($v['voucher_type']) . ' ' . $v['voucher_number'],
            ];
            $lines[] = [
                'account_id' => (int)$v['account_id'],
                'debit'      => 0.00,
                'credit'     => $amount,
                'memo'       => $v['narration'] ?: ('Voucher ' . $v['voucher_number']),
                'party_id'   => $partyId,
            ];
        }

        $glError = null;
        $txnId = post_journal_entry($this->conn, [
            'entry_date'  => $v['entry_date'],
            'session_id'  => (int)$v['session_id'],
            'description' => strtoupper($v['voucher_type']) . ' ' . $v['voucher_number']
                           . ' — ' . $v['party_name']
                           . ($v['narration'] ? ' — ' . $v['narration'] : ''),
            'ref_type'    => 'voucher',
            'ref_id'      => $voucherId,
            'posted_by'   => $postedBy,
            'idempotency' => 'voucher-' . $voucherId,
            'lines'       => $lines,
        ], $glError);

        if ($txnId <= 0) {
            throw new RuntimeException("GL post failed: " . ($glError ?: 'unknown'));
        }

        $stmt = $this->conn->prepare("
            UPDATE vouchers
               SET status = 'POSTED',
                   gl_transaction_id = ?,
                   posted_at = NOW(),
                   posted_by = ?
             WHERE id = ? AND status = 'DRAFT'
        ");
        $stmt->bind_param("iii", $txnId, $postedBy, $voucherId);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new RuntimeException("Failed to mark voucher POSTED: $err");
        }
        $stmt->close();

        return $txnId;
    }

    // =========================================================================
    // Cancel / Reverse
    // =========================================================================

    public function cancelDraft(int $voucherId): void
    {
        $v = $this->getVoucher($voucherId);
        if (!$v) {
            throw new RuntimeException("Voucher #$voucherId not found");
        }
        if ($v['status'] !== 'DRAFT') {
            throw new RuntimeException("Only DRAFT vouchers can be cancelled.");
        }

        $stmt = $this->conn->prepare("UPDATE vouchers SET status = 'CANCELLED' WHERE id = ? AND status = 'DRAFT'");
        $stmt->bind_param("i", $voucherId);
        $stmt->execute();
        $stmt->close();
    }

    public function reverseVoucher(int $voucherId, string $reason, int $postedBy): void
    {
        $v = $this->getVoucher($voucherId);
        if (!$v) {
            throw new RuntimeException("Voucher #$voucherId not found");
        }
        if ($v['status'] !== 'POSTED') {
            throw new RuntimeException("Only POSTED vouchers can be reversed. Current: " . $v['status']);
        }
        if (empty($v['gl_transaction_id'])) {
            throw new RuntimeException("Voucher has no linked GL transaction.");
        }

        $glError = null;
        $revId = reverse_journal_entry(
            $this->conn,
            (int)$v['gl_transaction_id'],
            "Voucher {$v['voucher_number']} reversed: $reason",
            $postedBy,
            $glError
        );
        if ($revId <= 0) {
            throw new RuntimeException("GL reversal failed: " . ($glError ?: 'unknown'));
        }

        $stmt = $this->conn->prepare("
            UPDATE vouchers
               SET status = 'CANCELLED'
             WHERE id = ? AND status = 'POSTED'
        ");
        $stmt->bind_param("i", $voucherId);
        $stmt->execute();
        $stmt->close();
    }

    // =========================================================================
    // Read
    // =========================================================================

    public function getVoucher(int $voucherId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT v.*,
                   a.code AS account_code, a.name AS account_name, a.account_type,
                   s.title AS session_title,
                   cu.username AS created_by_name,
                   pu.username AS posted_by_name
            FROM vouchers v
            LEFT JOIN gl_accounts a ON a.id = v.account_id
            LEFT JOIN sessions s ON s.id = v.session_id
            LEFT JOIN users cu ON cu.id = v.created_by
            LEFT JOIN users pu ON pu.id = v.posted_by
            WHERE v.id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $voucherId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    public function listVouchers(array $filters = []): array
    {
        $where = " WHERE 1=1 ";
        $params = [];
        $types = "";

        if (!empty($filters['voucher_type']) && in_array($filters['voucher_type'], self::VOUCHER_TYPES, true)) {
            $where .= " AND v.voucher_type = ? ";
            $params[] = $filters['voucher_type'];
            $types .= "s";
        }
        if (!empty($filters['status']) && in_array($filters['status'], ['DRAFT','POSTED','CANCELLED'], true)) {
            $where .= " AND v.status = ? ";
            $params[] = $filters['status'];
            $types .= "s";
        }
        if (!empty($filters['from_date'])) {
            $where .= " AND v.entry_date >= ? ";
            $params[] = $filters['from_date'];
            $types .= "s";
        }
        if (!empty($filters['to_date'])) {
            $where .= " AND v.entry_date <= ? ";
            $params[] = $filters['to_date'];
            $types .= "s";
        }
        if (!empty($filters['session_id']) && ctype_digit((string)$filters['session_id'])) {
            $where .= " AND v.session_id = ? ";
            $params[] = (int)$filters['session_id'];
            $types .= "i";
        }
        if (!empty($filters['search'])) {
            $where .= " AND (v.voucher_number LIKE ? OR v.party_name LIKE ? OR v.narration LIKE ?) ";
            $like = '%' . $filters['search'] . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $types .= "sss";
        }

        $countSql = "SELECT COUNT(*) AS cnt FROM vouchers v" . $where;
        $countStmt = $this->conn->prepare($countSql);
        if (!empty($params)) $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = (int)$countStmt->get_result()->fetch_assoc()['cnt'];
        $countStmt->close();

        $page = max(1, (int)($filters['page'] ?? 1));
        $perPage = min(500, max(10, (int)($filters['per_page'] ?? 50)));
        $offset = ($page - 1) * $perPage;

        $listSql = "
            SELECT v.*,
                   a.code AS account_code, a.name AS account_name,
                   s.title AS session_title,
                   u.username AS created_by_name
            FROM vouchers v
            LEFT JOIN gl_accounts a ON a.id = v.account_id
            LEFT JOIN sessions s ON s.id = v.session_id
            LEFT JOIN users u ON u.id = v.created_by
            " . $where . "
            ORDER BY v.entry_date DESC, v.id DESC
            LIMIT ? OFFSET ?
        ";
        $listParams = array_merge($params, [$perPage, $offset]);
        $listTypes  = $types . "ii";
        $listStmt = $this->conn->prepare($listSql);
        $listStmt->bind_param($listTypes, ...$listParams);
        $listStmt->execute();
        $rows = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $listStmt->close();

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, (int)ceil($total / $perPage)),
        ];
    }

    // =========================================================================
    // Validation
    // =========================================================================

    private function validateVoucherData(array $data, bool $isCreate): void
    {
        if ($isCreate) {
            if (empty($data['voucher_type']) || !in_array($data['voucher_type'], self::VOUCHER_TYPES, true)) {
                throw new InvalidArgumentException("Invalid voucher type");
            }
        }

        if (empty($data['entry_date']) || !strtotime($data['entry_date'])) {
            throw new InvalidArgumentException("Valid entry date required");
        }
        if (empty($data['session_id']) || (int)$data['session_id'] <= 0) {
            throw new InvalidArgumentException("Session required");
        }
        if (!isset($data['amount']) || (float)$data['amount'] <= 0) {
            throw new InvalidArgumentException("Amount must be greater than zero");
        }
        if (empty($data['party_name'])) {
            throw new InvalidArgumentException("Party name required");
        }
        if (empty($data['account_id']) || (int)$data['account_id'] <= 0) {
            throw new InvalidArgumentException("Account required");
        }

        $type = $data['voucher_type'] ?? null;

        if (in_array($type, ['BPV','BRV'], true)) {
            if (empty($data['payment_method'])) {
                throw new InvalidArgumentException("Payment method required for bank vouchers");
            }
            if (!in_array($data['payment_method'], self::BANK_PAYMENT_METHODS, true)) {
                throw new InvalidArgumentException("Invalid payment method");
            }
        }

        if ($type !== null) {
            $acc = gl_get_account($this->conn, (int)$data['account_id']);
            if (!$acc) {
                throw new InvalidArgumentException("Account not found");
            }
            $accType = $acc['account_type'];

            if (in_array($type, ['CPV','BPV'], true)) {
                // Payments: debit side is Expense, Liability, or Asset
                // (Asset is allowed for fixed-asset purchases, prepayments, etc.)
                if (!in_array($accType, ['EXPENSE','LIABILITY','ASSET'], true)) {
                    throw new InvalidArgumentException("For a payment voucher, account must be Expense, Liability, or Asset");
                }
            } else {
                // Receipts: credit side is Revenue, Asset, or Liability
                // (Liability is allowed for loans received, advance fee collections, etc.)
                if (!in_array($accType, ['REVENUE','ASSET','LIABILITY'], true)) {
                    throw new InvalidArgumentException("For a receipt voucher, account must be Revenue, Asset, or Liability");
                }
            }
        }
    }
}