<?php
require_once('conn_inc.php');

$payment_id = intval($_GET['payment_id']);

$query = "SELECT p.*, r.receipt_number, 
                 sr.name AS student_name, sr.father_name,
                 c.title AS class_title, s.title AS session_title
          FROM student_fee_payments p
          JOIN payment_receipts r ON p.id = r.payment_id
          JOIN student_fee_card sfc ON p.fee_card_id = sfc.id
          JOIN student_class sc ON sfc.student_class_id = sc.id
          JOIN student_registration sr ON sc.student_registration_id = sr.id
          JOIN classes c ON sc.class_id = c.id
          JOIN sessions s ON sc.session_id = s.id
          WHERE p.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $payment_id);
$stmt->execute();
$result = $stmt->get_result();
$payment = $result->fetch_assoc();

$receipt_html = '
<div class="receipt">
    <div class="text-center">
        <h3>Madrasa Al-Farooqia</h3>
        <p>New Colony, Matta Swat, Pakistan</p>
        <p>Registration: 5892 | Affiliation: 08958</p>
    </div>
    
    <hr>
    
    <div class="row">
        <div class="col-xs-6">
            <p><strong>Receipt #:</strong> '.$payment['receipt_number'].'</p>
            <p><strong>Date:</strong> '.$payment['payment_date'].'</p>
        </div>
        <div class="col-xs-6 text-right">
            <p><strong>Student:</strong> '.$payment['student_name'].'</p>
            <p><strong>Father:</strong> '.$payment['father_name'].'</p>
        </div>
    </div>
    
    <hr>
    
    <table class="table">
        <thead>
            <tr>
                <th>Description</th>
                <th class="text-right">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Fee Payment ('.$payment['class_title'].' - '.$payment['session_title'].')</td>
                <td class="text-right">'.number_format($payment['paid_amount'], 2).'</td>
            </tr>
            <tr>
                <td><strong>Total</strong></td>
                <td class="text-right"><strong>'.number_format($payment['paid_amount'], 2).'</strong></td>
            </tr>
        </tbody>
    </table>
    
    <hr>
    
    <div class="row">
        <div class="col-xs-6">
            <p><strong>Payment Method:</strong> '.ucfirst($payment['payment_method']).'</p>
            '.($payment['transaction_ref'] ? '<p><strong>Reference:</strong> '.$payment['transaction_ref'].'</p>' : '').'
        </div>
        <div class="col-xs-6 text-right">
            <p>_________________________</p>
            <p>Cashier Signature</p>
        </div>
    </div>
    
    <div class="text-center" style="margin-top: 20px;">
        <p>Thank you for your payment</p>
    </div>
</div>';

echo $receipt_html;
?>