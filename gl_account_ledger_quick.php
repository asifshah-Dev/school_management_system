<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

header('Content-Type: application/json');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

$accountId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$fromDate  = isset($_GET['from']) && $_GET['from'] !== '' ? $_GET['from'] : date('Y-m-01');
$toDate    = isset($_GET['to'])   && $_GET['to']   !== '' ? $_GET['to']   : date('Y-m-d');
$limit     = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 20;

if ($accountId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid account id']);
    exit();
}

$account = gl_get_account($conn, $accountId);
if (!$account) {
    echo json_encode(['success' => false, 'message' => 'Account not found']);
    exit();
}

// Fetch balances and recent entries
$balances = gl_quick_ledger_balances($conn, $accountId, $fromDate, $toDate);
$entries  = gl_quick_ledger($conn, $accountId, $fromDate, $toDate, $limit);

// Determine sign convention
$isContra     = !empty($account['is_contra']);
$isDebitNormal = in_array($account['account_type'], ['ASSET','EXPENSE'], true) || $isContra;

// Format for JSON
$formatted = [];
foreach ($entries as $e) {
    $formatted[] = [
        'txn_id'         => (int)$e['txn_id'],
        'entry_date'     => $e['entry_date'],
        'description'    => $e['description'],
        'reference_type' => $e['reference_type'],
        'reference_id'   => $e['reference_id'],
        'status'         => $e['status'],
        'reversal_of'    => $e['reversal_of'],
        'debit'          => (float)$e['debit'],
        'credit'         => (float)$e['credit'],
        'memo'           => $e['memo'],
    ];
}

echo json_encode([
    'success'      => true,
    'account'      => [
        'id'           => (int)$account['id'],
        'code'         => $account['code'],
        'name'         => $account['name'],
        'account_type' => $account['account_type'],
        'is_contra'    => (int)$account['is_contra'],
    ],
    'from'         => $fromDate,
    'to'           => $toDate,
    'opening'      => $balances['opening'],
    'closing'      => $balances['closing'],
    'is_debit_normal' => $isDebitNormal,
    'entries'      => $formatted,
]);
exit();