<?php
require_once('conn_inc.php');

$session_id = isset($_POST['session_id']) ? intval($_POST['session_id']) : 0;

if ($session_id == 0) {
    echo json_encode(['collected' => [], 'pending' => []]);
    exit;
}

// Fetch session date range
$session_query = $conn->query("SELECT from_dated, to_dated FROM sessions WHERE id = $session_id");
$session = $session_query->fetch_assoc();
if (!$session) {
    echo json_encode(['collected' => [], 'pending' => []]);
    exit;
}

$from_date = new DateTime($session['from_dated']);
$to_date = new DateTime($session['to_dated']);
$months = [];
$current = clone $from_date;
while ($current <= $to_date) {
    $months[] = $current->format('Y-m');
    $current->modify('+1 month');
}

// Initialize arrays for collected and pending fees
$collected = array_fill(0, count($months), 0);
$pending = array_fill(0, count($months), 0);

// Fetch collected fees
$collected_query = $conn->query("
    SELECT DATE_FORMAT(sfp.payment_date, '%Y-%m') as month, SUM(sfp.paid_amount) as total
    FROM student_fee_payments sfp
    JOIN student_fee_card sfc ON sfp.fee_card_id = sfc.id
    JOIN student_class sc ON sfc.student_class_id = sc.id
    WHERE sc.session_id = $session_id
    GROUP BY DATE_FORMAT(sfp.payment_date, '%Y-%m')
");
while ($row = $collected_query->fetch_assoc()) {
    $month_index = array_search($row['month'], $months);
    if ($month_index !== false) {
        $collected[$month_index] = (float)$row['total'];
    }
}

// Fetch pending fees
$pending_query = $conn->query("
    SELECT DATE_FORMAT(sfc.due_date, '%Y-%m') as month, SUM(sfc.total_amount) as total
    FROM student_fee_card sfc
    JOIN student_class sc ON sfc.student_class_id = sc.id
    WHERE sc.session_id = $session_id AND sfc.status = 'pending'
    GROUP BY DATE_FORMAT(sfc.due_date, '%Y-%m')
");
while ($row = $pending_query->fetch_assoc()) {
    $month_index = array_search($row['month'], $months);
    if ($month_index !== false) {
        $pending[$month_index] = (float)$row['total'];
    }
}

echo json_encode(['collected' => $collected, 'pending' => $pending]);
$conn->close();
?>