<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

$errors  = [];
$success = null;

// ---------------------------------------------------------------------------
// Check ledger state
// ---------------------------------------------------------------------------
$ledgerCount = (int)$conn->query("
    SELECT COUNT(*) AS c FROM gl_transactions WHERE status = 'POSTED'
")->fetch_assoc()['c'];

$existingOpening = $conn->query("
    SELECT id, entry_date, description, status
    FROM gl_transactions
    WHERE reference_type = 'opening_balance'
    ORDER BY id DESC
    LIMIT 1
")->fetch_assoc();

// ---------------------------------------------------------------------------
// Pre-fill helper — pulls amounts from operational tables
// ---------------------------------------------------------------------------
function prefill_amounts(mysqli $conn): array {
    $out = [
        'cash_in_hand'       => 0.0,
        'cash_at_bank'       => 0.0,
        'fee_receivable'     => 0.0,
        'furniture'          => 0.0,
        'computers'          => 0.0,
        'vehicles'           => 0.0,
        'accounts_payable'   => 0.0,
        'advance_fee'        => 0.0,
        'salary_payable'     => 0.0,
        'bank_loans'         => 0.0,
    ];

    // Fee receivable
    $row = $conn->query("
        SELECT COALESCE(SUM(total_amount - paid_amount - COALESCE(discount_amount, 0)), 0) AS total
        FROM student_fee_card
        WHERE status IN ('pending', 'partial')
    ")->fetch_assoc();
    $out['fee_receivable'] = (float)$row['total'];

    // Advance fee held
    $row = $conn->query("
        SELECT COALESCE(SUM(remaining_amount), 0) AS total
        FROM advance_payments
        WHERE status = 'active'
    ")->fetch_assoc();
    $out['advance_fee'] = (float)$row['total'];

    return $out;
}

$prefill = prefill_amounts($conn);

// ---------------------------------------------------------------------------
// Current form values
// ---------------------------------------------------------------------------
$form = [
    'entry_date'         => $_POST['entry_date']         ?? date('Y-m-d'),
    'description'        => $_POST['description']        ?? 'Opening Balance',
    'cash_in_hand'       => $_POST['cash_in_hand']       ?? $prefill['cash_in_hand'],
    'cash_at_bank'       => $_POST['cash_at_bank']       ?? $prefill['cash_at_bank'],
    'fee_receivable'     => $_POST['fee_receivable']     ?? $prefill['fee_receivable'],
    'furniture'          => $_POST['furniture']          ?? $prefill['furniture'],
    'computers'          => $_POST['computers']          ?? $prefill['computers'],
    'vehicles'           => $_POST['vehicles']           ?? $prefill['vehicles'],
    'accounts_payable'   => $_POST['accounts_payable']   ?? $prefill['accounts_payable'],
    'advance_fee'        => $_POST['advance_fee']        ?? $prefill['advance_fee'],
    'salary_payable'     => $_POST['salary_payable']     ?? $prefill['salary_payable'],
    'bank_loans'         => $_POST['bank_loans']         ?? $prefill['bank_loans'],
];

// ---------------------------------------------------------------------------
// Resolve accounts
// ---------------------------------------------------------------------------
$codesNeeded = [
    'cash_in_hand'     => '1010',
    'cash_at_bank'     => '1020',
    'fee_receivable'   => '1030',
    'furniture'        => '1510',
    'computers'        => '1520',
    'vehicles'         => '1530',
    'accounts_payable' => '2010',
    'advance_fee'      => '2020',
    'salary_payable'   => '2030',
    'bank_loans'       => '2510',
    'retained'         => '3100',
];

$accountIds = [];
$accountInfo = [];
foreach ($codesNeeded as $key => $code) {
    $acc = gl_get_account_by_code($conn, $code);
    if (!$acc) {
        $errors[] = "Account with code $code not found in the Chart of Accounts. Seed the CoA first.";
    } else {
        $accountIds[$key]  = (int)$acc['id'];
        $accountInfo[$key] = $acc;
    }
}

// ---------------------------------------------------------------------------
// Compute totals
// ---------------------------------------------------------------------------
$totalDebits  = 0.0;
$totalCredits = 0.0;

$assetFields = ['cash_in_hand', 'cash_at_bank', 'fee_receivable', 'furniture', 'computers', 'vehicles'];
$liabFields  = ['accounts_payable', 'advance_fee', 'salary_payable', 'bank_loans'];

foreach ($assetFields as $f) {
    $totalDebits += max(0.0, (float)$form[$f]);
}
foreach ($liabFields as $f) {
    $totalCredits += max(0.0, (float)$form[$f]);
}

// Retained earnings = plug = assets - liabilities
$retainedPlug = round($totalDebits - $totalCredits, 2);

// ---------------------------------------------------------------------------
// Handle POST — post the entry
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_opening'])) {

    // Safety: refuse if the ledger already has transactions (unless forced)
    $force = isset($_POST['force_post']) && $_POST['force_post'] === '1';

    if ($ledgerCount > 0 && !$force) {
        $errors[] = "The ledger already contains $ledgerCount posted transaction(s). "
                  . "Posting an opening balance to a non-empty ledger will mix it with existing entries. "
                  . "If you are sure, tick the override checkbox and resubmit.";
    }

    if (empty($form['entry_date']) || !strtotime($form['entry_date'])) {
        $errors[] = "Valid entry date required.";
    }
    if (empty($form['description'])) {
        $errors[] = "Description required.";
    }
    if ($totalDebits <= 0) {
        $errors[] = "At least one opening balance amount must be greater than zero.";
    }
    if (empty($accountIds['retained'])) {
        $errors[] = "Retained Earnings account missing.";
    }

    if (empty($errors)) {
        // Build lines
        $lines = [];

        // Assets (debits)
        foreach ($assetFields as $f) {
            $amt = round((float)$form[$f], 2);
            if ($amt > 0.0 && !empty($accountIds[$f])) {
                $lines[] = [
                    'account_id' => $accountIds[$f],
                    'debit'      => $amt,
                    'credit'     => 0.00,
                    'memo'       => 'Opening balance — ' . $accountInfo[$f]['name'],
                ];
            }
        }

        // Liabilities (credits)
        foreach ($liabFields as $f) {
            $amt = round((float)$form[$f], 2);
            if ($amt > 0.0 && !empty($accountIds[$f])) {
                $lines[] = [
                    'account_id' => $accountIds[$f],
                    'debit'      => 0.00,
                    'credit'     => $amt,
                    'memo'       => 'Opening balance — ' . $accountInfo[$f]['name'],
                ];
            }
        }

        // Retained earnings plug
        if (abs($retainedPlug) > 0.005) {
            if ($retainedPlug > 0) {
                // Assets > Liabilities → credit retained earnings (equity increases)
                $lines[] = [
                    'account_id' => $accountIds['retained'],
                    'debit'      => 0.00,
                    'credit'     => $retainedPlug,
                    'memo'       => 'Opening balance — Retained Earnings (plug)',
                ];
            } else {
                // Liabilities > Assets → debit retained earnings (accumulated loss)
                $lines[] = [
                    'account_id' => $accountIds['retained'],
                    'debit'      => abs($retainedPlug),
                    'credit'     => 0.00,
                    'memo'       => 'Opening balance — Retained Earnings (accumulated loss)',
                ];
            }
        }

        if (count($lines) < 2) {
            $errors[] = "At least 2 non-zero amounts are needed to make a balanced entry.";
        }

        // Post it
        if (empty($errors)) {
            // Current session
            $sessionRow = $conn->query("
                SELECT id FROM sessions WHERE status = 0 ORDER BY id DESC LIMIT 1
            ")->fetch_assoc();
            $sessionId = $sessionRow ? (int)$sessionRow['id'] : 1;

            $postedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;

            $glError = null;
            $txnId = post_journal_entry($conn, [
                'entry_date'  => $form['entry_date'],
                'session_id'  => $sessionId,
                'description' => $form['description'],
                'ref_type'    => 'opening_balance',
                'ref_id'      => null,
                'posted_by'   => $postedBy,
                'idempotency' => 'opening_balance',
                'lines'       => $lines,
            ], $glError);

            if ($txnId > 0) {
                $_SESSION['message'] = "<div class='alert alert-success'>"
                    . "<strong>✓ Opening balance posted</strong>"
                    . "<br>Transaction #$txnId — " . htmlspecialchars($form['description'])
                    . "</div>";
                $_SESSION['message_type'] = 'success';
                header("Location: gl_transaction_view.php?id=" . $txnId);
                exit();
            } else {
                $errors[] = "GL post failed: " . htmlspecialchars($glError ?: 'unknown');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    
    <title>Opening Balance</title>
    <style>
        * { box-sizing: border-box; }
        body {
            background: #eef1f5;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            margin: 0; padding: 0; color: #1e293b;
        }
        .wrap { max-width: 1100px; margin: 30px auto; padding: 0 20px; }

        /* Header */
        .head {
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
            color: #fff;
            padding: 32px 40px 28px 40px;
            border-radius: 12px 12px 0 0;
            box-shadow: 0 4px 16px rgba(30, 64, 175, 0.15);
            display: flex; justify-content: space-between;
            align-items: flex-end; gap: 20px; flex-wrap: wrap;
        }
        .head h1 { margin: 0; font-size: 30px; font-weight: 700; letter-spacing: -0.5px; }
        .head .sub { font-size: 14px; opacity: 0.9; margin-top: 6px; }
        .btn-back {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 10px 18px;
            background: rgba(255, 255, 255, 0.15);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px; text-decoration: none;
            font-size: 14px; font-weight: 600;
        }
        .btn-back:hover { background: rgba(255, 255, 255, 0.25); color: #fff; text-decoration: none; }

        .card {
            background: #fff;
            padding: 30px 40px 40px 40px;
            border-radius: 0 0 12px 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        /* Info banner */
        .info-banner {
            padding: 14px 20px;
            border-radius: 10px;
            font-size: 14px;
            margin-bottom: 24px;
            line-height: 1.6;
        }
        .info-banner.info  { background: #dbeafe; border-left: 4px solid #1e40af; color: #1e3a8a; }
        .info-banner.warn  { background: #fef3c7; border-left: 4px solid #d97706; color: #92400e; }
        .info-banner.ok    { background: #d1fae5; border-left: 4px solid #059669; color: #065f46; }

        .info-banner strong { display: block; margin-bottom: 4px; }

        /* Error box */
        .error-box {
            background: #fee2e2; border-left: 4px solid #dc2626;
            padding: 14px 18px; border-radius: 8px; margin-bottom: 20px;
            color: #991b1b; font-size: 14px;
        }
        .error-box strong { display: block; margin-bottom: 6px; }

        /* Section title */
        .section-title {
            font-size: 12px; font-weight: 800; color: #64748b;
            text-transform: uppercase; letter-spacing: 2px;
            margin: 24px 0 14px 0;
            padding-bottom: 8px;
            border-bottom: 1px solid #e2e8f0;
        }
        .section-title:first-child { margin-top: 0; }

        /* Field layout */
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 18px; }
        @media (max-width: 700px) { .grid-2, .grid-3 { grid-template-columns: 1fr; } }

        .field label {
            display: block;
            font-size: 12px; font-weight: 700; color: #334155;
            text-transform: uppercase; letter-spacing: 0.8px;
            margin-bottom: 6px;
        }
        .field input[type=text],
        .field input[type=date],
        .field input[type=number],
        .field textarea {
            width: 100%; height: 44px;
            padding: 10px 14px;
            border: 1px solid #cbd5e1; border-radius: 8px;
            font-size: 15px; background: #fff; color: #0f172a;
        }
        .field input:focus, .field textarea:focus {
            outline: none; border-color: #1e40af;
            box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
        }
        .field .help { font-size: 12px; color: #64748b; margin-top: 5px; }
        .field .acc-code {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 12px; color: #64748b;
            font-weight: 600;
        }

        /* Amount inputs get monospace */
        .amount-input { font-family: 'SF Mono', 'Monaco', monospace; text-align: right; }

        /* Preview table */
        .preview-table-wrap {
            background: #fff; border: 1px solid #e2e8f0;
            border-radius: 10px; overflow: hidden; margin-top: 8px;
        }
        .preview-table-wrap .header {
            background: #1e293b; color: #fff;
            padding: 12px 20px; font-size: 12px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 1.2px;
        }
        .preview-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .preview-table th {
            padding: 10px 16px; text-align: left;
            font-size: 10px; font-weight: 700; color: #64748b;
            text-transform: uppercase; letter-spacing: 1px;
            background: #f8fafc; border-bottom: 1px solid #e2e8f0;
        }
        .preview-table th.amount-col { text-align: right; }
        .preview-table td {
            padding: 12px 16px; border-bottom: 1px solid #f1f5f9;
            color: #1e293b;
        }
        .preview-table td.amount-col {
            text-align: right; font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 14px; font-weight: 600;
            font-variant-numeric: tabular-nums;
        }
        .preview-table td.amount-col.debit { color: #047857; }
        .preview-table td.amount-col.credit { color: #b91c1c; }
        .preview-table td.acc-code {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 12px; color: #64748b;
        }
        .preview-table tr.total-row td {
            background: #f0f5ff; font-weight: 800;
            border-top: 2px solid #c9d7ef;
            border-bottom: 2px solid #c9d7ef;
            padding: 14px 16px;
        }
        .preview-table tr.total-row td.amount-col { font-size: 15px; }

        /* Balanced indicator */
        .balance-indicator {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 6px 14px; border-radius: 20px;
            font-size: 12px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .balance-indicator.ok  { background: #d1fae5; color: #065f46; }
        .balance-indicator.bad { background: #fee2e2; color: #991b1b; }

        /* Actions */
        .actions {
            display: flex; justify-content: flex-end; gap: 12px;
            margin-top: 30px; padding-top: 24px;
            border-top: 1px solid #e2e8f0;
        }
        .btn {
            height: 48px; padding: 0 24px; border-radius: 8px;
            font-size: 14px; font-weight: 600;
            border: none; cursor: pointer;
            display: inline-flex; align-items: center; gap: 8px;
            text-decoration: none; transition: all 0.15s;
        }
        .btn-ghost { background: #fff; border: 1px solid #cbd5e1; color: #475569; }
        .btn-ghost:hover { background: #f8fafc; color: #1e293b; text-decoration: none; border-color: #94a3b8; }
        .btn-post { background: #1e40af; color: #fff; }
        .btn-post:hover { background: #1e3a8a; }
        .btn-post:disabled { background: #94a3b8; cursor: not-allowed; }

        /* Force override checkbox */
        .force-box {
            background: #fef3c7; border-left: 4px solid #d97706;
            padding: 14px 18px; border-radius: 8px; margin-top: 20px;
            font-size: 13px; color: #92400e;
        }
        .force-box label { display: flex; align-items: flex-start; gap: 10px; cursor: pointer; }
        .force-box input[type=checkbox] { margin-top: 2px; }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <h1>Opening Balance</h1>
            <div class="sub">Record the school's starting financial position</div>
        </div>
        <a href="gl_dashboard.php" class="btn-back">
            <span class="glyphicon glyphicon-arrow-left"></span> Dashboard
        </a>
    </div>

    <div class="card">

        <?php if (isset($_SESSION['message'])): ?>
            <div style="margin-bottom: 20px;">
                <?php echo $_SESSION['message']; unset($_SESSION['message'], $_SESSION['message_type']); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="error-box">
                <strong>✗ Cannot post:</strong>
                <ul style="margin: 0; padding-left: 20px;">
                    <?php foreach ($errors as $e): ?>
                        <li><?php echo htmlspecialchars($e); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Ledger state banner -->
        <?php if ($ledgerCount === 0): ?>
            <div class="info-banner ok">
                <strong>✓ Ledger is empty</strong>
                This is the ideal time to post an opening balance. All accounts are ready.
            </div>
        <?php else: ?>
            <div class="info-banner warn">
                <strong>⚠ Ledger already has <?php echo number_format($ledgerCount); ?> posted transactions</strong>
                Posting an opening balance now will mix it with existing entries. Consider reversing existing transactions first,
                or use the override checkbox below to force-post anyway.
                <?php if ($existingOpening): ?>
                    <br><br>
                    A previous opening balance entry exists (Transaction #<?php echo (int)$existingOpening['id']; ?>
                    dated <?php echo htmlspecialchars($existingOpening['entry_date']); ?>
                    — <?php echo htmlspecialchars($existingOpening['status']); ?>).
                    <a href="gl_transaction_view.php?id=<?php echo (int)$existingOpening['id']; ?>" style="color: #92400e; font-weight: 700;">
                        View it →
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="" id="openingForm">

            <div class="section-title">Entry Details</div>

            <div class="grid-2">
                <div class="field">
                    <label for="entry_date">Entry Date <span style="color: #dc2626;">*</span></label>
                    <input type="date" id="entry_date" name="entry_date" required
                           value="<?php echo htmlspecialchars($form['entry_date']); ?>">
                    <div class="help">The date the school's financial position is being recorded</div>
                </div>
                <div class="field">
                    <label for="description">Description <span style="color: #dc2626;">*</span></label>
                    <input type="text" id="description" name="description" maxlength="500" required
                           value="<?php echo htmlspecialchars($form['description']); ?>"
                           placeholder="Opening Balance">
                </div>
            </div>

            <div class="section-title">Assets — What the School Owns</div>

            <div class="grid-3">
                <div class="field">
                    <label for="cash_in_hand">
                        Cash in Hand
                       
                    </label>
                    <input type="number" step="0.01" min="0"
                           class="amount-input" id="cash_in_hand" name="cash_in_hand"
                           value="<?php echo htmlspecialchars($form['cash_in_hand']); ?>"
                           oninput="recalc()">
                </div>
                <div class="field">
                    <label for="cash_at_bank">
                        Cash at Bank
                        
                    </label>
                    <input type="number" step="0.01" min="0"
                           class="amount-input" id="cash_at_bank" name="cash_at_bank"
                           value="<?php echo htmlspecialchars($form['cash_at_bank']); ?>"
                           oninput="recalc()">
                </div>
                <div class="field">
                    <label for="fee_receivable">
                        Student Fee Receivable
                        
                    </label>
                    <input type="number" step="0.01" min="0"
                           class="amount-input" id="fee_receivable" name="fee_receivable"
                           value="<?php echo htmlspecialchars($form['fee_receivable']); ?>"
                           oninput="recalc()">
                    <div class="help">Auto-filled from outstanding fee cards</div>
                </div>
            </div>

            <div class="grid-3">
                <div class="field">
                    <label for="furniture">
                        Furniture & Fixtures
                        
                    </label>
                    <input type="number" step="0.01" min="0"
                           class="amount-input" id="furniture" name="furniture"
                           value="<?php echo htmlspecialchars($form['furniture']); ?>"
                           oninput="recalc()">
                </div>
                <div class="field">
                    <label for="computers">
                        Computers & Equipment
                     
                    </label>
                    <input type="number" step="0.01" min="0"
                           class="amount-input" id="computers" name="computers"
                           value="<?php echo htmlspecialchars($form['computers']); ?>"
                           oninput="recalc()">
                </div>
                <div class="field">
                    <label for="vehicles">
                        Vehicles
                      
                    </label>
                    <input type="number" step="0.01" min="0"
                           class="amount-input" id="vehicles" name="vehicles"
                           value="<?php echo htmlspecialchars($form['vehicles']); ?>"
                           oninput="recalc()">
                </div>
            </div>

            <div class="section-title">Liabilities — What the School Owes</div>

            <div class="grid-3">
                <div class="field">
                    <label for="accounts_payable">
                        Accounts Payable
                       
                    </label>
                    <input type="number" step="0.01" min="0"
                           class="amount-input" id="accounts_payable" name="accounts_payable"
                           value="<?php echo htmlspecialchars($form['accounts_payable']); ?>"
                           oninput="recalc()">
                    <div class="help">Owed to suppliers</div>
                </div>
                <div class="field">
                    <label for="advance_fee">
                        Advance Fee Received
                        
                    </label>
                    <input type="number" step="0.01" min="0"
                           class="amount-input" id="advance_fee" name="advance_fee"
                           value="<?php echo htmlspecialchars($form['advance_fee']); ?>"
                           oninput="recalc()">
                    <div class="help">Auto-filled from active advances</div>
                </div>
                <div class="field">
                    <label for="salary_payable">
                        Salary Payable
                        
                    </label>
                    <input type="number" step="0.01" min="0"
                           class="amount-input" id="salary_payable" name="salary_payable"
                           value="<?php echo htmlspecialchars($form['salary_payable']); ?>"
                           oninput="recalc()">
                </div>
            </div>

            <div class="grid-3">
                <div class="field">
                    <label for="bank_loans">
                        Bank Loans
                      
                    </label>
                    <input type="number" step="0.01" min="0"
                           class="amount-input" id="bank_loans" name="bank_loans"
                           value="<?php echo htmlspecialchars($form['bank_loans']); ?>"
                           oninput="recalc()">
                </div>
            </div>

            <div class="section-title">Journal Entry Preview</div>

            <div class="preview-table-wrap">
                <div class="header">
                    <span class="glyphicon glyphicon-book"></span>
                    Opening Balance Entry
                    <span id="previewStatus" class="balance-indicator ok" style="float: right;">Balanced ✓</span>
                </div>
                <table class="preview-table">
                    <thead>
                        <tr>
                            <th style="width: 70px;">Code</th>
                            <th>Account</th>
                            <th class="amount-col" style="width: 150px;">Debit</th>
                            <th class="amount-col" style="width: 150px;">Credit</th>
                        </tr>
                    </thead>
                    <tbody id="previewBody">
                        <!-- Populated by JavaScript -->
                    </tbody>
                    <tfoot>
                        <tr class="total-row">
                            <td colspan="2" style="text-align: right;">TOTAL</td>
                            <td class="amount-col debit" id="totalDebit">0.00</td>
                            <td class="amount-col credit" id="totalCredit">0.00</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="help" style="margin-top: 12px;">
                <strong>Retained Earnings (3100)</strong> is auto-computed as the difference between total assets and total liabilities.
                This plug balances the entry so the accounting equation holds.
            </div>

            <?php if ($ledgerCount > 0): ?>
                <div class="force-box">
                    <label>
                        <input type="checkbox" name="force_post" value="1"
                               <?php echo (isset($_POST['force_post']) && $_POST['force_post'] === '1') ? 'checked' : ''; ?>>
                        <span>
                            <strong>I understand this will post an opening balance to a non-empty ledger.</strong>
                            This may mix opening balances with existing transactions. Only proceed if you know what you're doing.
                        </span>
                    </label>
                </div>
            <?php endif; ?>

            <div class="actions">
                <a href="gl_dashboard.php" class="btn btn-ghost">
                    Cancel
                </a>
                <button type="submit" name="submit_opening" value="1" class="btn btn-post" id="submitBtn">
                    <span class="glyphicon glyphicon-floppy-disk"></span>
                    Post Opening Balance
                </button>
            </div>

        </form>
    </div>
</div>

<script>
// Account metadata
var ACCOUNTS = {
    cash_in_hand:     { code: '1010', name: 'Cash in Hand',              type: 'ASSET' },
    cash_at_bank:     { code: '1020', name: 'Cash at Bank',              type: 'ASSET' },
    fee_receivable:   { code: '1030', name: 'Student Fee Receivable',    type: 'ASSET' },
    furniture:        { code: '1510', name: 'Furniture & Fixtures',      type: 'ASSET' },
    computers:        { code: '1520', name: 'Computers & Equipment',     type: 'ASSET' },
    vehicles:         { code: '1530', name: 'Vehicles',                  type: 'ASSET' },
    accounts_payable: { code: '2010', name: 'Accounts Payable',          type: 'LIABILITY' },
    advance_fee:      { code: '2020', name: 'Advance Fee Received',      type: 'LIABILITY' },
    salary_payable:   { code: '2030', name: 'Salary Payable',            type: 'LIABILITY' },
    bank_loans:       { code: '2510', name: 'Bank Loans',                type: 'LIABILITY' },
    retained:         { code: '3100', name: 'Retained Earnings',         type: 'EQUITY' }
};

// Order for rendering
var ORDER = [
    'cash_in_hand', 'cash_at_bank', 'fee_receivable', 'furniture', 'computers', 'vehicles',
    'accounts_payable', 'advance_fee', 'salary_payable', 'bank_loans',
    'retained'
];

function fmt(n) {
    if (!isFinite(n)) n = 0;
    return n.toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function recalc() {
    var tbody    = document.getElementById('previewBody');
    var totalDr  = 0;
    var totalCr  = 0;

    // Collect all amounts
    var amounts = {};
    ORDER.forEach(function(f) {
        if (f === 'retained') return;
        var el = document.getElementById(f);
        amounts[f] = el ? (parseFloat(el.value) || 0) : 0;
    });

    // Sum
    var assets = ['cash_in_hand','cash_at_bank','fee_receivable','furniture','computers','vehicles'];
    var liabs  = ['accounts_payable','advance_fee','salary_payable','bank_loans'];

    assets.forEach(function(f) { totalDr += Math.max(0, amounts[f]); });
    liabs.forEach(function(f)  { totalCr += Math.max(0, amounts[f]); });

    var plug = Math.round((totalDr - totalCr) * 100) / 100;

    // Build preview rows
    var html = '';

    // Assets (debits)
    assets.forEach(function(f) {
        if (amounts[f] > 0) {
            html += '<tr>';
            html += '<td class="acc-code">' + ACCOUNTS[f].code + '</td>';
            html += '<td>' + ACCOUNTS[f].name + '</td>';
            html += '<td class="amount-col debit">' + fmt(amounts[f]) + '</td>';
            html += '<td class="amount-col credit">—</td>';
            html += '</tr>';
        }
    });

    // Liabilities (credits)
    liabs.forEach(function(f) {
        if (amounts[f] > 0) {
            html += '<tr>';
            html += '<td class="acc-code">' + ACCOUNTS[f].code + '</td>';
            html += '<td>' + ACCOUNTS[f].name + '</td>';
            html += '<td class="amount-col debit">—</td>';
            html += '<td class="amount-col credit">' + fmt(amounts[f]) + '</td>';
            html += '</tr>';
        }
    });

    // Retained Earnings plug
    if (Math.abs(plug) > 0.005) {
        if (plug > 0) {
            html += '<tr>';
            html += '<td class="acc-code">' + ACCOUNTS.retained.code + '</td>';
            html += '<td>' + ACCOUNTS.retained.name + ' <em style="color: #64748b; font-size: 12px;">(plug)</em></td>';
            html += '<td class="amount-col debit">—</td>';
            html += '<td class="amount-col credit">' + fmt(plug) + '</td>';
            html += '</tr>';
            totalCr += plug;
        } else {
            var abs = Math.abs(plug);
            html += '<tr>';
            html += '<td class="acc-code">' + ACCOUNTS.retained.code + '</td>';
            html += '<td>' + ACCOUNTS.retained.name + ' <em style="color: #64748b; font-size: 12px;">(accumulated loss)</em></td>';
            html += '<td class="amount-col debit">' + fmt(abs) + '</td>';
            html += '<td class="amount-col credit">—</td>';
            html += '</tr>';
            totalDr += abs;
        }
    }

    if (html === '') {
        html = '<tr><td colspan="4" style="text-align: center; padding: 30px; color: #94a3b8; font-style: italic;">Enter amounts above to see the journal entry preview</td></tr>';
    }

    tbody.innerHTML = html;

    document.getElementById('totalDebit').textContent  = fmt(totalDr);
    document.getElementById('totalCredit').textContent = fmt(totalCr);

    // Balance indicator
    var status = document.getElementById('previewStatus');
    var balanced = Math.abs(totalDr - totalCr) < 0.01 && totalDr > 0;
    if (balanced) {
        status.className = 'balance-indicator ok';
        status.textContent = 'Balanced ✓';
    } else if (totalDr === 0 && totalCr === 0) {
        status.className = 'balance-indicator ok';
        status.textContent = 'Empty';
    } else {
        status.className = 'balance-indicator bad';
        status.textContent = 'Not Balanced ✗';
    }

    // Enable/disable submit
    var submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = !balanced;
}

document.addEventListener('DOMContentLoaded', function() {
    // Attach input listeners to all amount fields
    ORDER.forEach(function(f) {
        var el = document.getElementById(f);
        if (el) el.addEventListener('input', recalc);
    });

    recalc();
});
</script>

</body>
</html>
<?php $conn->close(); ?>