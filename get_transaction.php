<?php
require_once('conn_inc.php');

$id = intval($_GET['id']);
$query = $conn->prepare("SELECT * FROM detail_account WHERE id = ?");
$query->bind_param("i", $id);
$query->execute();
$result = $query->get_result();

if ($row = $result->fetch_assoc()) {
    $amount = floatval($row['amount']);
    $type = $row['type'] == 'cash_in' ? 'Cash In' : 'Cash Out';
    ?>
    <div style="line-height: 2;">
        <p><strong>Transaction ID:</strong> <?php echo $row['id']; ?></p>
        <p><strong>Master Account ID:</strong> <?php echo $row['master_account_id']; ?></p>
        <p><strong>Type:</strong> 
            <span class="badge-<?php echo $row['type']; ?>">
                <?php echo $type; ?>
            </span>
        </p>
        <p><strong>Amount:</strong> 
            <span class="<?php echo $row['type'] == 'cash_in' ? 'amount-positive' : 'amount-negative'; ?>">
                <?php echo $row['type'] == 'cash_in' ? '+' : '-'; ?>Rs. <?php echo number_format($amount, 2); ?>
            </span>
        </p>
        <p><strong>Running Balance:</strong> Rs. <?php echo number_format($row['balance'], 2); ?></p>
        <p><strong>Reference ID:</strong> <?php echo htmlspecialchars($row['ref_id']); ?></p>
        <p><strong>Reference Type:</strong> <?php echo htmlspecialchars($row['ref_type']); ?></p>
        <p><strong>Transaction Date:</strong> <?php echo date('d M Y, h:i:s A', strtotime($row['transaction_date'])); ?></p>
        <p><strong>Created At:</strong> <?php echo date('d M Y, h:i:s A', strtotime($row['created_at'])); ?></p>
    </div>
    
    <style>
        .badge-cash_in {
            background: #d1fae5;
            color: #065f46;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        .badge-cash_out {
            background: #fee2e2;
            color: #991b1b;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        .amount-positive {
            color: #10b981;
            font-weight: 700;
        }
        .amount-negative {
            color: #ef4444;
            font-weight: 700;
        }
    </style>
    <?php
} else {
    echo '<p>Transaction not found.</p>';
}
$query->close();
$conn->close();
?>