<?php
require_once('conn_inc.php');
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['search_term'])) {
    $search_term = '%' . trim($_POST['search_term']) . '%';
    
    $stmt = $conn->prepare("SELECT id, title, type, balance, mobile_no mobile FROM accounts 
                            WHERE (title LIKE ? OR mobile_no LIKE ?) 
                            AND type IN ('Supplier', 'Cash', 'Bank')");
    $stmt->bind_param("ss", $search_term, $search_term);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $accounts = [];
    while ($row = $result->fetch_assoc()) {
        $accounts[] = $row;
    }
    
    $stmt->close();
    
    if (count($accounts) === 1) {
        echo json_encode([
            'status' => 'success',
            'account' => $accounts[0]
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'No account found or multiple accounts match.'
        ]);
    }
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request.'
    ]);
}
?>