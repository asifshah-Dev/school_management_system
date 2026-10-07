<?php
require_once('security.php');
if (session_status() == PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['lang'])) $_SESSION['lang'] = 'en';
$lang = $_SESSION['lang'];

// 🏫 SCHOOL CONFIGURATION
$school_name = "DAR-E-ARQAM SCHOOLS";
$school_address = "Matta Campus";
$school_phone = "Ph: 0946-791555";

// Language strings - Urdu array kept 100% intact as requested
$translations = [
    'en' => [
        'receipt_title' => 'CASH SUMMARY',
        'date' => 'Date',
        'cash_in' => 'Cash In',
        'cash_out' => 'Cash Out',
        'balance' => 'Net Balance',
        'printed' => 'Printed',
        'no_data' => 'No filtered data',
        'thank_you' => 'THANK YOU'
    ],
    'ur' => [
        'receipt_title' => 'نقدی خلاصہ',
        'date' => 'تاریخ',
        'cash_in' => 'نقدی اندر',
        'cash_out' => 'نقدی باہر',
        'balance' => 'کل بیلنس',
        'printed' => 'پرنٹ شدہ',
        'no_data' => 'کوئی ڈیٹا نہیں ملا',
        'thank_you' => 'شکریہ'
    ]
];

require_once('conn_inc.php');

// Build WHERE clause from filters
$where_conditions = []; $params = []; $types = "";
if (!empty($_GET['search'])) { $s = '%' . $_GET['search'] . '%'; $where_conditions[] = "(ref_id LIKE ? OR ref_type LIKE ? OR type LIKE ?)"; $params[] = $s; $params[] = $s; $params[] = $s; $types .= "sss"; }
if (!empty($_GET['type']) && $_GET['type'] != 'all') { $where_conditions[] = "LOWER(TRIM(type)) = ?"; $params[] = strtolower(trim($_GET['type'])); $types .= "s"; }
if (!empty($_GET['category_filter']) && $_GET['category_filter'] != 'all') { $where_conditions[] = "ref_type = ?"; $params[] = $_GET['category_filter']; $types .= "s"; }
if (!empty($_GET['date_from'])) { $where_conditions[] = "DATE(transaction_date) >= ?"; $params[] = $_GET['date_from']; $types .= "s"; }
if (!empty($_GET['date_to'])) { $where_conditions[] = "DATE(transaction_date) <= ?"; $params[] = $_GET['date_to']; $types .= "s"; }

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get totals only (no transactions needed)
$totals_sql = "SELECT 
    SUM(CASE WHEN LOWER(TRIM(type)) IN ('cash_in','cash in') THEN amount ELSE 0 END) as cash_in,
    SUM(CASE WHEN LOWER(TRIM(type)) IN ('cash_out','cash out') THEN amount ELSE 0 END) as cash_out
    FROM detail_account $where_clause";
$totals_res = $conn->query($totals_sql);
$totals = $totals_res ? $totals_res->fetch_assoc() : ['cash_in'=>0, 'cash_out'=>0];
$cash_in = floatval($totals['cash_in']);
$cash_out = floatval($totals['cash_out']);
$net_balance = $cash_in - $cash_out;

// Get current master balance for verification
$master_res = $conn->query("SELECT balance FROM master_account ORDER BY id DESC LIMIT 1");
$master_balance = $master_res && $master_res->num_rows > 0 ? floatval($master_res->fetch_assoc()['balance']) : 0;
$master_res->close();
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" dir="<?php echo $lang == 'ur' ? 'rtl' : 'ltr'; ?>">
<head>
    <meta charset="utf-8">
    <title><?php echo htmlspecialchars($school_name); ?> - <?php echo $translations[$lang]['receipt_title']; ?></title>
    <style>
        /* ===== THERMAL PRINTER OPTIMIZED + LARGER BOLDER TEXT ===== */
        @media print { @page { size: 80mm auto; margin: 0; } }
        
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        
        body {
            font-family: 'DM Sans', sans-serif;
            font-size: 16px;
            font-weight: 1000;
            line-height: 1.6;
            color: #000;
            background: #fff;
            width: 72mm;
            margin: 0 auto;
            padding: 4mm 3mm;
            letter-spacing: 0.5px;
        }

        /* HEADER */
        .header {
            text-align: center;
            border-bottom: 4px double #000;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .school-name {
            font-size: 22px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 4px;
            line-height: 1.3;
        }
        .school-info {
            font-size: 14px;
            font-weight: 900;
            margin: 2px 0;
        }
        .report-title {
            font-size: 18px;
            font-weight: 900;
            margin-top: 8px;
            letter-spacing: 4px;
            text-transform: uppercase;
        }
        .meta-line {
            font-size: 13px;
            font-weight: 900;
            display: flex;
            justify-content: space-between;
            margin-top: 8px;
            padding-top: 6px;
            border-top: 2px solid #000;
        }

        /* SUMMARY BOXES */
        .summary-container {
            margin: 12px 0;
        }
        .summary-box {
            border: 3px solid #000;
            padding: 10px 8px;
            margin-bottom: 10px;
            text-align: center;
        }
        .summary-box:last-child { border-bottom: 4px double #000; }
        
        .box-label {
            font-size: 16px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 6px;
            display: block;
        }
        .box-value {
            font-size: 28px;
            font-weight: 900;
            font-family: monospace;
            display: block;
            line-height: 1.4;
        }
        .box-value.in { letter-spacing: 1px; }
        .box-value.out { letter-spacing: 1px; }
        
        .balance-box {
            border: 4px solid #000;
            padding: 12px;
            margin-top: 10px;
            background: #fff;
        }
        .balance-box .box-label { font-size: 18px; }
        .balance-box .box-value { font-size: 32px; font-weight: 900; }

        /* DIVIDER */
        .divider {
            border: none;
            border-top: 3px solid #000;
            margin: 12px 0;
        }

        /* FOOTER */
        .footer {
            text-align: center;
            margin-top: 15px;
            padding-top: 10px;
            border-top: 4px double #000;
            font-size: 14px;
            font-weight: 900;
        }
        .thank-you {
            font-size: 18px;
            font-weight: 900;
            letter-spacing: 3px;
            margin-bottom: 8px;
        }
        .powered-by {
            font-size: 11px;
            font-weight: 900;
            opacity: 0.95;
            margin-top: 5px;
        }

        /* RTL SUPPORT */
        [dir="rtl"] .meta-line, [dir="rtl"] .school-name { direction: rtl; }
        [dir="rtl"] .box-label { letter-spacing: 0; }
        
        /* SCREEN PREVIEW ONLY */
        @media screen { body { border: 1px dashed #999; } }
    </style>
</head>
<body onload="window.print()">

<div class="header">
    <div class="school-name"><?php echo htmlspecialchars($school_name); ?></div>
    <?php if ($school_address): ?><div class="school-info"><?php echo htmlspecialchars($school_address); ?></div><?php endif; ?>
    <?php if ($school_phone): ?><div class="school-info">📞 <?php echo htmlspecialchars($school_phone); ?></div><?php endif; ?>
    <div class="report-title"><?php echo $translations[$lang]['receipt_title']; ?></div>
    <div class="meta-line">
        <span><?php echo $translations[$lang]['date']; ?>: <?php echo date('d-M-Y'); ?></span>
      
    </div>
</div>

<div class="summary-container">
    <div class="summary-box">
        <span class="box-label"><?php echo $translations[$lang]['cash_in']; ?></span>
        <span class="box-value in">Rs. <?php echo number_format($cash_in, 0); ?></span>
    </div>

    <div class="summary-box">
        <span class="box-label"><?php echo $translations[$lang]['cash_out']; ?></span>
        <span class="box-value out">Rs. <?php echo number_format($cash_out, 0); ?></span>
    </div>

    <hr class="divider">

    <div class="summary-box balance-box">
        <span class="box-label"><?php echo $translations[$lang]['balance']; ?></span>
        <span class="box-value">Rs. <?php echo number_format($net_balance, 0); ?></span>
    </div>
</div>

<div class="footer">
    <div class="powered-by">Powered by SoftRayz</div>
</div>

</body>
</html>
<?php $conn->close(); ?>