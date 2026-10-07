<?php
require_once('conn_inc.php');

$class_id = intval($_GET['class_id']);
$session_id = intval($_GET['session_id']);
$student_id = intval($_GET['student_id']);

// Get student basic info
$query = "SELECT sr.*, sc.id AS student_class_id 
          FROM student_registration sr
          JOIN student_class sc ON sr.id = sc.student_registration_id
          WHERE sr.id = ? AND sc.class_id = ? AND sc.session_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("iii", $student_id, $class_id, $session_id);
$stmt->execute();
$result = $stmt->get_result();
$student = $result->fetch_assoc();

// Get fee details
$fee_query = "SELECT sfc.id, sfc.fee_type_id, sfc.total_amount, sfc.due_date, sfc.status,
                     ft.title AS fee_type_title
              FROM student_fee_card sfc
              JOIN fee_types ft ON sfc.fee_type_id = ft.id
              WHERE sfc.student_class_id = ?
              ORDER BY ft.title, sfc.due_date";
$fee_stmt = $conn->prepare($fee_query);
$fee_stmt->bind_param("i", $student['student_class_id']);
$fee_stmt->execute();
$fee_result = $fee_stmt->get_result();

$fee_details = [];
$total_due = 0;
while ($fee_row = $fee_result->fetch_assoc()) {
    // Calculate paid amount
    $paid_query = "SELECT COALESCE(SUM(paid_amount), 0) AS paid_amount 
                   FROM student_fee_payments 
                   WHERE fee_card_id = ?";
    $paid_stmt = $conn->prepare($paid_query);
    $paid_stmt->bind_param("i", $fee_row['id']);
    $paid_stmt->execute();
    $paid_result = $paid_stmt->get_result();
    $paid_row = $paid_result->fetch_assoc();
    
    $remaining = $fee_row['total_amount'] - $paid_row['paid_amount'];
    if ($remaining > 0) {
        $total_due += $remaining;
    }
    
    $fee_details[] = [
        'id' => $fee_row['id'],
        'title' => $fee_row['fee_type_title'],
        'total' => $fee_row['total_amount'],
        'paid' => $paid_row['paid_amount'],
        'remaining' => $remaining,
        'due_date' => $fee_row['due_date'],
        'status' => $fee_row['status']
    ];
}

// Get payment history
$history_query = "SELECT p.*, r.receipt_number 
                 FROM student_fee_payments p
                 LEFT JOIN payment_receipts r ON p.id = r.payment_id
                 WHERE p.fee_card_id = ?
                 ORDER BY p.payment_date DESC";
$history_stmt = $conn->prepare($history_query);
$history_stmt->bind_param("i", $fee_details[0]['id']); // Using first fee card ID
$history_stmt->execute();
$history_result = $history_stmt->get_result();

$history = [];
while ($history_row = $history_result->fetch_assoc()) {
    $history[] = $history_row;
}

// Generate HTML responses
$student_html = '
    <p><strong>Name:</strong> '.$student['name'].'</p>
    <p><strong>Father Name:</strong> '.$student['father_name'].'</p>
    <p><strong>Class:</strong> '.$class_id.'</p>
    <p><strong>Session:</strong> '.$session_id.'</p>
';

$fee_html = '<table class="table table-bordered">
    <thead>
        <tr>
            <th>Fee Type</th>
            <th>Total Amount</th>
            <th>Paid Amount</th>
            <th>Remaining</th>
            <th>Due Date</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>';
    
foreach ($fee_details as $fee) {
    $fee_html .= '
        <tr>
            <td>'.$fee['title'].'</td>
            <td>'.number_format($fee['total'], 2).'</td>
            <td>'.number_format($fee['paid'], 2).'</td>
            <td>'.number_format($fee['remaining'], 2).'</td>
            <td>'.$fee['due_date'].'</td>
            <td>'.$fee['status'].'</td>
        </tr>';
}

$fee_html .= '
        <tr class="total-row">
            <td colspan="3"><strong>Total Due</strong></td>
            <td colspan="3"><strong>'.number_format($total_due, 2).'</strong></td>
        </tr>
    </tbody>
</table>';

$history_html = '<table class="table table-bordered">
    <thead>
        <tr>
            <th>Date</th>
            <th>Amount</th>
            <th>Method</th>
            <th>Receipt #</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>';
    
foreach ($history as $payment) {
    $history_html .= '
        <tr>
            <td>'.$payment['payment_date'].'</td>
            <td>'.number_format($payment['paid_amount'], 2).'</td>
            <td>'.$payment['payment_method'].'</td>
            <td>'.$payment['receipt_number'].'</td>
            <td>'.$payment['status'].'</td>
        </tr>';
}

if (empty($history)) {
    $history_html .= '
        <tr>
            <td colspan="5">No payment history found</td>
        </tr>';
}

$history_html .= '</tbody></table>';

header('Content-Type: application/json');
echo json_encode([
    'student_html' => $student_html,
    'fee_html' => $fee_html,
    'history_html' => $history_html,
    'student_class_id' => $student['student_class_id'],
    'fee_card_id' => $fee_details[0]['id'], // Using first fee card ID
    'total_due' => $total_due
]);
?>