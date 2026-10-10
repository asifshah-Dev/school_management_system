<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
$errors = [];

$form = [
    'gl_account_id'   => $_POST['gl_account_id']   ?? '',
    'statement_start' => $_POST['statement_start'] ?? date('Y-m-01'),
    'statement_date'  => $_POST['statement_date']  ?? date('Y-m-d'),
    'opening_balance' => $_POST['opening_balance'] ?? '0.00',
    'closing_balance' => $_POST['closing_balance'] ?? '0.00',
    'notes'           => $_POST['notes']           ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accountId      = (int)$form['gl_account_id'];
    $statementDate  = $form['statement_date'];
    $statementStart = $form['statement_start'] ?: null;
    $openingBalance = (float)$form['opening_balance'];
    $closingBalance = (float)$form['closing_balance'];
    $notes          = trim($form['notes']);

    if ($accountId <= 0) $errors[] = 'Select a bank account.';
    if (!strtotime($statementDate)) $errors[] = 'Valid statement end date required.';

    if (empty($errors)) {
        $bookBalance = gl_account_balance_signed($conn, $accountId, $statementDate);

        $stmt = $conn->prepare("
            INSERT INTO bank_reconciliations
              (gl_account_id, statement_date, statement_start, opening_balance, closing_balance,
               book_balance, adjusted_bank, adjusted_book, difference, status, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, 'DRAFT', ?, ?)
        ");
        $stmt->bind_param("issddd" . "si",
            $accountId, $statementDate, $statementStart, $openingBalance, $closingBalance,
            $bookBalance, $notes, $userId);

        if ($stmt->execute()) {
            $newId = $conn->insert_id;
            $stmt->close();
            $_SESSION['msg'] = 'Reconciliation created — now paste or add statement lines.';
            $_SESSION['msg_type'] = 'success';
            header("Location: gl_bank_recon_view.php?id=$newId");
            exit;
        } else {
            $errors[] = 'Insert failed: ' . $stmt->error;
            $stmt->close();
        }
    }
}

$bankAccounts = $conn->query("
    SELECT id, code, name FROM gl_accounts
    WHERE code = '1020' OR (name LIKE '%Bank%' AND account_type = 'ASSET' AND is_postable = 1)
    ORDER BY code
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>New Reconciliation</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    * { box-sizing: border-box; }
    body { background: #eef1f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 0; color: #1e293b; }
    .wrap { max-width: 900px; margin: 30px auto; padding: 0 20px; }

    .head {
        background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
        color: #fff; padding: 30px 40px 26px 40px;
        border-radius: 12px 12px 0 0; box-shadow: 0 4px 16px rgba(30,64,175,0.15);
        display: flex; justify-content: space-between; align-items: flex-end;
        gap: 20px; flex-wrap: wrap;
    }
    .head h1 { margin: 0; font-size: 30px; font-weight: 700; }
    .head .sub { font-size: 14px; opacity: 0.9; margin-top: 6px; }

    .btn-back {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 10px 20px;
        background: #ffffff; color: #1e40af;
        border: 1px solid #ffffff; border-radius: 8px;
        text-decoration: none; font-size: 14px; font-weight: 600;
        line-height: 1; font-family: inherit; white-space: nowrap;
    }
    .btn-back:hover { background: #e0e7ff; border-color: #e0e7ff; color: #1e40af; text-decoration: none; }

    .card { background: #fff; padding: 30px 40px 40px 40px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

    .section-title {
        font-size: 12px; font-weight: 800; color: #64748b; text-transform: uppercase;
        letter-spacing: 2px; margin: 24px 0 14px 0; padding-bottom: 8px;
        border-bottom: 1px solid #e2e8f0;
    }
    .section-title:first-child { margin-top: 0; }

    .field { margin-bottom: 18px; }
    .field label { display: block; font-size: 12px; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px; }
    .field .required { color: #dc2626; }
    .field input, .field select, .field textarea {
        width: 100%; height: 44px; padding: 10px 14px;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 15px; font-family: inherit; background: #fff; color: #0f172a;
    }
    .field textarea { height: auto; min-height: 80px; resize: vertical; font-family: inherit; }
    .field input:focus, .field select:focus, .field textarea:focus {
        outline: none; border-color: #1e40af; box-shadow: 0 0 0 3px rgba(30,64,175,0.1);
    }
    .field .help { font-size: 12px; color: #64748b; margin-top: 5px; }

    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    @media (max-width: 700px) { .grid-2 { grid-template-columns: 1fr; } }

    .error-box {
        background: #fee2e2; border-left: 4px solid #dc2626;
        padding: 14px 18px; border-radius: 8px; margin-bottom: 20px;
        color: #991b1b; font-size: 14px;
    }
    .error-box ul { margin: 6px 0 0 20px; padding: 0; }

    .actions {
        display: flex; justify-content: flex-end; gap: 12px;
        margin-top: 30px; padding-top: 24px; border-top: 1px solid #e2e8f0;
        flex-wrap: wrap;
    }

    .action-btn-primary,
    .action-btn-cancel {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        height: 48px;
        min-width: 170px;
        padding: 0 26px;
        border-radius: 8px;
        font-family: inherit;
        font-size: 15px;
        font-weight: 600;
        line-height: 1;
        text-decoration: none;
        cursor: pointer;
        border: 1px solid transparent;
        white-space: nowrap;
    }
    .action-btn-primary {
        background: #1e40af;
        color: #ffffff;
        border-color: #1e40af;
    }
    .action-btn-primary:hover {
        background: #1e3a8a;
        border-color: #1e3a8a;
        color: #ffffff;
        text-decoration: none;
    }
    .action-btn-cancel {
        background: #f1f5f9;
        color: #334155;
        border-color: #cbd5e1;
    }
    .action-btn-cancel:hover {
        background: #e2e8f0;
        color: #1e293b;
        text-decoration: none;
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <h1>New Bank Reconciliation</h1>
            <div class="sub">Start by entering the statement's opening and closing balances</div>
        </div>
        <a href="gl_bank_recon.php" class="btn-back">
            <span class="glyphicon glyphicon-arrow-left"></span> Back to List
        </a>
    </div>

    <div class="card">

        <?php if (!empty($errors)): ?>
            <div class="error-box">
                <strong>✗ Please fix the following:</strong>
                <ul>
                    <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post">

            <div class="section-title">Bank Account</div>

            <div class="field">
                <label>Which bank account? <span class="required">*</span></label>
                <select name="gl_account_id" required>
                    <option value="">— Select bank account —</option>
                    <?php foreach ($bankAccounts as $ba): ?>
                        <option value="<?= (int)$ba['id'] ?>" <?= ((int)$form['gl_account_id'] === (int)$ba['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ba['code'] . ' — ' . $ba['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="help">Usually 1020 Cash at Bank.</div>
            </div>

            <div class="section-title">Statement Period</div>

            <div class="grid-2">
                <div class="field">
                    <label>Start Date</label>
                    <input type="date" name="statement_start" value="<?= htmlspecialchars($form['statement_start']) ?>">
                </div>
                <div class="field">
                    <label>End Date <span class="required">*</span></label>
                    <input type="date" name="statement_date" required value="<?= htmlspecialchars($form['statement_date']) ?>">
                </div>
            </div>

            <div class="section-title">Balances per Bank Statement</div>

            <div class="grid-2">
                <div class="field">
                    <label>Opening Balance</label>
                    <input type="number" name="opening_balance" step="0.01" value="<?= htmlspecialchars($form['opening_balance']) ?>">
                </div>
                <div class="field">
                    <label>Closing Balance <span class="required">*</span></label>
                    <input type="number" name="closing_balance" step="0.01" required value="<?= htmlspecialchars($form['closing_balance']) ?>">
                </div>
            </div>
            <div class="help" style="font-size:12px; color:#64748b; margin-bottom:8px;">
                Copy these from the top and bottom of the bank statement.
            </div>

            <div class="section-title">Notes (optional)</div>

            <div class="field">
                <textarea name="notes" placeholder="e.g. February 2026 statement from Meezan Bank"><?= htmlspecialchars($form['notes']) ?></textarea>
            </div>

            <div class="actions">
                <a href="gl_bank_recon.php" class="action-btn-cancel">Cancel</a>
                <button type="submit" class="action-btn-primary">
                    <span class="glyphicon glyphicon-arrow-right"></span> Create &amp; Continue
                </button>
            </div>

        </form>
    </div>
</div>

</body>
</html>