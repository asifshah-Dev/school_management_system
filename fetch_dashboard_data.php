<?php
// Include database connection
require_once('conn_inc.php');

// Check for database connection errors
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error, 3, 'error_log.txt');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit();
}

// Get year and month from POST, default to July 2025
$year = isset($_POST['year']) ? intval($_POST['year']) : 2025;
$month = isset($_POST['month']) ? intval($_POST['month']) : 7;

// Validate year and month
if ($year < 1900 || $year > 2100 || $month < 1 || $month > 12) {
    error_log("Invalid year or month: year=$year, month=$month", 3, 'error_log.txt');
    echo json_encode(['status' => 'error', 'message' => 'Invalid year or month']);
    exit();
}

// Initialize response
$response = [
    'status' => 'success',
    'summary' => [
        'total_expenses' => 0,
        'total_paid' => 0,
        'pending_balance' => 0,
        'transaction_count' => 0
    ],
    'expense_distribution' => ['labels' => [], 'values' => []],
    'balance_trend' => ['dates' => [], 'balances' => []],
    'recent_transactions' => [],
    'debug' => ['year' => $year, 'month' => $month]
];

// Log the request for debugging
error_log("Fetching dashboard data for all accounts: year=$year, month=$month", 3, 'error_log.txt');

// Fetch summary data from expenses_master for the selected month
$query = "SELECT 
    COALESCE(SUM(total_amount), 0) as total_expenses,
    COALESCE(SUM(paid_amount), 0) as total_paid,
    COALESCE(SUM(balance_amount), 0) as pending_balance
FROM expenses_master 
WHERE YEAR(invoice_date) = ? AND MONTH(invoice_date) = ?";
$stmt = $conn->prepare($query);
if (!$stmt) {
    error_log("Prepare failed for summary: " . $conn->error, 3, 'error_log.txt');
    echo json_encode(['status' => 'error', 'message' => 'Database query preparation failed: ' . $conn->error]);
    exit();
}
$stmt->bind_param("ii", $year, $month);
if (!$stmt->execute()) {
    error_log("Error executing summary query: " . $stmt->error, 3, 'error_log.txt');
    echo json_encode(['status' => 'error', 'message' => 'Database query execution failed: ' . $stmt->error]);
    exit();
}
$result = $stmt->get_result();
$summary = $result->fetch_assoc();
$response['summary'] = [
    'total_expenses' => floatval($summary['total_expenses']),
    'total_paid' => floatval($summary['total_paid']),
    'pending_balance' => floatval($summary['pending_balance']),
    'transaction_count' => 0
];
$response['debug']['summary_rows'] = $result->num_rows;
$stmt->close();

// Fetch expense distribution by payment type for the selected month
$query = "SELECT payment_type, COALESCE(SUM(total_amount), 0) as total
FROM expenses_master 
WHERE YEAR(invoice_date) = ? AND MONTH(invoice_date) = ?
GROUP BY payment_type";
$stmt = $conn->prepare($query);
if (!$stmt) {
    error_log("Prepare failed for expense distribution: " . $conn->error, 3, 'error_log.txt');
    echo json_encode(['status' => 'error', 'message' => 'Database query preparation failed: ' . $conn->error]);
    exit();
}
$stmt->bind_param("ii", $year, $month);
if (!$stmt->execute()) {
    error_log("Error executing expense distribution query: " . $stmt->error, 3, 'error_log.txt');
    echo json_encode(['status' => 'error', 'message' => 'Database query execution failed: ' . $stmt->error]);
    exit();
}
$result = $stmt->get_result();
$labels = [];
$values = [];
while ($row = $result->fetch_assoc()) {
    $labels[] = $row['payment_type'] ?: 'Unknown';
    $values[] = floatval($row['total']);
}
$response['expense_distribution'] = ['labels' => $labels, 'values' => $values];
$response['debug']['expense_rows'] = $result->num_rows;
$stmt->close();

// Fetch balance trend for the selected month
$query = "SELECT dated, SUM(balance) as balance
FROM accounts_details 
WHERE YEAR(dated) = ? AND MONTH(dated) = ?
GROUP BY dated
ORDER BY dated ASC";
$stmt = $conn->prepare($query);
if (!$stmt) {
    error_log("Prepare failed for balance trend: " . $conn->error, 3, 'error_log.txt');
    echo json_encode(['status' => 'error', 'message' => 'Database query preparation failed: ' . $conn->error]);
    exit();
}
$stmt->bind_param("ii", $year, $month);
if (!$stmt->execute()) {
    error_log("Error executing balance trend query: " . $stmt->error, 3, 'error_log.txt');
    echo json_encode(['status' => 'error', 'message' => 'Database query execution failed: ' . $stmt->error]);
    exit();
}
$result = $stmt->get_result();
$dates = [];
$balances = [];
while ($row = $result->fetch_assoc()) {
    $dates[] = $row['dated'];
    $balances[] = floatval($row['balance']);
}
$response['balance_trend'] = ['dates' => $dates, 'balances' => $balances];
$response['debug']['balance_rows'] = $result->num_rows;
$stmt->close();

// Fetch recent transactions (last 5) for the selected month
$query = "SELECT account_id, dated, description, amount, balance
FROM accounts_details 
WHERE YEAR(dated) = ? AND MONTH(dated) = ?
ORDER BY dated DESC
LIMIT 5";
$stmt = $conn->prepare($query);
if (!$stmt) {
    error_log("Prepare failed for recent transactions: " . $conn->error, 3, 'error_log.txt');
    echo json_encode(['status' => 'error', 'message' => 'Database query preparation failed: ' . $conn->error]);
    exit();
}
$stmt->bind_param("ii", $year, $month);
if (!$stmt->execute()) {
    error_log("Error executing recent transactions query: " . $stmt->error, 3, 'error_log.txt');
    echo json_encode(['status' => 'error', 'message' => 'Database query execution failed: ' . $stmt->error]);
    exit();
}
$result = $stmt->get_result();
$transactions = [];
while ($row = $result->fetch_assoc()) {
    $transactions[] = [
        'account_id' => $row['account_id'],
        'dated' => $row['dated'],
        'description' => $row['description'] ?: 'N/A',
        'amount' => floatval($row['amount']),
        'balance' => floatval($row['balance'])
    ];
}
$response['summary']['transaction_count'] = count($transactions);
$response['recent_transactions'] = $transactions;
$response['debug']['transaction_rows'] = $result->num_rows;
$stmt->close();

// Log the response for debugging
error_log("Dashboard response: " . json_encode($response['debug']), 3, 'error_log.txt');

// Output JSON response
header('Content-Type: application/json');
echo json_encode($response);

// Close database connection
$conn->close();
?>
