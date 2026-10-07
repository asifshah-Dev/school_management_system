<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');
require_once('VoucherService.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

$service = new VoucherService($conn);

// Current session
$currentSession = $conn->query("
    SELECT id, title FROM sessions WHERE status = 0 ORDER BY id DESC LIMIT 1
")->fetch_assoc();
$currentSessionId = $currentSession ? (int)$currentSession['id'] : 0;

$errors = [];
$success = null;

// Form state (for redisplay on error)
$form = [
    'voucher_type'     => $_POST['voucher_type']     ?? 'CPV',
    'entry_date'       => $_POST['entry_date']       ?? date('Y-m-d'),
    'session_id'       => $_POST['session_id']       ?? $currentSessionId,
    'amount'           => $_POST['amount']           ?? '',
    'party_name'       => $_POST['party_name']       ?? '',
    'party_contact'    => $_POST['party_contact']    ?? '',
    'payment_method'   => $_POST['payment_method']   ?? '',
    'reference_number' => $_POST['reference_number'] ?? '',
    'bank_name'        => $_POST['bank_name']        ?? '',
    'account_id'       => $_POST['account_id']       ?? '',
    'narration'        => $_POST['narration']        ?? '',
    'attachment_path'  => null,
];

// ---------------------------------------------------------------------------
// Handle POST
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_voucher'])) {

    // Handle file upload
    if (!empty($_FILES['attachment']['name'])) {
        $uploadDir = __DIR__ . '/uploads/vouchers/' . date('Y');
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }
        $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','pdf'];
        if (!in_array($ext, $allowed, true)) {
            $errors[] = "Attachment must be JPG, PNG, or PDF.";
        } elseif ($_FILES['attachment']['size'] > 5 * 1024 * 1024) {
            $errors[] = "Attachment too large (max 5 MB).";
        } else {
            $filename = uniqid('vch_', true) . '.' . $ext;
            $target = $uploadDir . '/' . $filename;
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $target)) {
                $form['attachment_path'] = 'uploads/vouchers/' . date('Y') . '/' . $filename;
            } else {
                $errors[] = "Failed to save uploaded file.";
            }
        }
    }

    $action = $_POST['save_action'] ?? 'draft';

    if (empty($errors)) {
        try {
            $data = [
                'voucher_type'     => $form['voucher_type'],
                'entry_date'       => $form['entry_date'],
                'session_id'       => (int)$form['session_id'],
                'amount'           => (float)$form['amount'],
                'party_name'       => trim($form['party_name']),
                'party_contact'    => trim($form['party_contact']) ?: null,
                'payment_method'   => trim($form['payment_method']) ?: null,
                'reference_number' => trim($form['reference_number']) ?: null,
                'bank_name'        => trim($form['bank_name']) ?: null,
                'account_id'       => (int)$form['account_id'],
                'narration'        => trim($form['narration']) ?: null,
                'attachment_path'  => $form['attachment_path'],
                'created_by'       => isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1,
            ];

            $result = $service->createDraft($data);

            if ($action === 'post') {
                $service->postVoucher($result['id'], $data['created_by']);
                $_SESSION['message'] = "<div class='alert alert-success'><strong>✓ Voucher posted</strong><br>{$result['voucher_number']}</div>";
            } else {
                $_SESSION['message'] = "<div class='alert alert-info'><strong>✓ Voucher saved as draft</strong><br>{$result['voucher_number']}</div>";
            }
            header("Location: gl_voucher_view.php?id=" . $result['id']);
            exit();

        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
}

// ---------------------------------------------------------------------------
// Load accounts for the dropdown
// ---------------------------------------------------------------------------
$allAccounts = gl_list_accounts($conn, null, true);

$paymentAccounts = [];   // For CPV/BPV: Expenses and Liabilities
$receiptAccounts = [];   // For CRV/BRV: Revenue and Assets

foreach ($allAccounts as $a) {
    if (in_array($a['account_type'], ['EXPENSE','LIABILITY'], true)) {
        $paymentAccounts[] = $a;
    }
    if (in_array($a['account_type'], ['REVENUE','ASSET'], true)) {
        $receiptAccounts[] = $a;
    }
}

// Suggest next voucher number for display (not reserved)
$previewNumber = null;
try {
    $year = (int)substr($form['entry_date'], 0, 4);
    // We don't actually call the procedure here — just show the format
    $previewNumber = $form['voucher_type'] . '-' . $year . '-XXXX';
} catch (Exception $e) {
    // silent
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    
    <title>New Voucher</title>
    <style>
        * { box-sizing: border-box; }
        body {
            background: #eef1f5;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0; padding: 0; color: #1e293b;
        }
        .wrap { max-width: 1000px; margin: 30px auto; padding: 0 20px; }

        .head {
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
            color: #fff;
            padding: 30px 40px 26px 40px;
            border-radius: 12px 12px 0 0;
            box-shadow: 0 4px 16px rgba(30, 64, 175, 0.15);
            display: flex; justify-content: space-between;
            align-items: flex-end; gap: 20px; flex-wrap: wrap;
        }
        .head h1 { margin: 0; font-size: 30px; font-weight: 700; letter-spacing: -0.5px; }
        .head .sub { font-size: 14px; opacity: 0.9; margin-top: 6px; }

        .btn-new {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 18px;
            background: rgba(255, 255, 255, 0.15);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px; text-decoration: none;
            font-size: 14px; font-weight: 600;
            transition: all 0.15s;
        }
        .btn-new:hover { background: #fff; color: #1e40af; text-decoration: none; border-color: #fff; }

        .card {
            background: #fff;
            padding: 30px 40px 40px 40px;
            border-radius: 0 0 12px 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .section-title {
            font-size: 12px; font-weight: 800; color: #64748b;
            text-transform: uppercase; letter-spacing: 2px;
            margin: 24px 0 14px 0;
            padding-bottom: 8px;
            border-bottom: 1px solid #e2e8f0;
        }
        .section-title:first-child { margin-top: 0; }

        .field { margin-bottom: 18px; }
        .field label {
            display: block;
            font-size: 12px; font-weight: 700; color: #334155;
            text-transform: uppercase; letter-spacing: 0.8px;
            margin-bottom: 6px;
        }
        .field .required { color: #dc2626; }

        .field input[type=text],
        .field input[type=date],
        .field input[type=number],
        .field select,
        .field textarea {
            width: 100%;
            height: 44px;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 15px;
            background: #fff;
            color: #0f172a;
            transition: all 0.15s;
        }
        .field textarea { height: auto; min-height: 80px; resize: vertical; font-family: inherit; }
        .field input:focus,
        .field select:focus,
        .field textarea:focus {
            outline: none; border-color: #1e40af;
            box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
        }
        .field .help { font-size: 12px; color: #64748b; margin-top: 5px; }

        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
        @media (max-width: 700px) {
            .grid-2, .grid-3 { grid-template-columns: 1fr; }
        }

        /* Type selector cards */
        .type-cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 18px;
        }
        @media (max-width: 800px) {
            .type-cards { grid-template-columns: repeat(2, 1fr); }
        }
        .type-card {
            display: flex; flex-direction: column; align-items: center;
            gap: 8px; padding: 16px 12px;
            background: #f8fafc; border: 2px solid #e2e8f0;
            border-radius: 10px; cursor: pointer;
            text-decoration: none; color: #1e293b;
            transition: all 0.15s;
        }
        .type-card:hover { background: #f0f5ff; border-color: #bfdbfe; }
        .type-card input { display: none; }
        .type-card.selected {
            background: #eff6ff; border-color: #1e40af;
            box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
        }
        .type-card .icon {
            width: 44px; height: 44px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 10px;
            font-size: 20px; color: #fff;
        }
        .type-card .label {
            font-size: 12px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .type-card.cpv .icon { background: #dc2626; }
        .type-card.crv .icon { background: #047857; }
        .type-card.bpv .icon { background: #c2410c; }
        .type-card.brv .icon { background: #1e40af; }

        /* Amount preview */
        .amount-preview {
            background: #f0f5ff; border: 1px solid #bfdbfe;
            border-radius: 10px; padding: 16px 20px;
            margin-bottom: 18px;
        }
        .amount-preview .lbl {
            font-size: 11px; color: #64748b; font-weight: 700;
            text-transform: uppercase; letter-spacing: 1px;
            margin-bottom: 6px;
        }
        .amount-preview .val {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 22px; font-weight: 700;
            color: #1e40af;
        }

        .error-box {
            background: #fee2e2; border-left: 4px solid #dc2626;
            padding: 14px 18px; border-radius: 8px; margin-bottom: 20px;
            color: #991b1b; font-size: 14px;
        }
        .error-box strong { display: block; margin-bottom: 6px; }

        .actions {
            display: flex; justify-content: flex-end; gap: 12px;
            flex-wrap: wrap; align-items: center;
            margin-top: 30px; padding-top: 24px;
            border-top: 1px solid #e2e8f0;
        }
        .btn {
            height: 48px; min-width: 150px; padding: 0 22px; border-radius: 8px;
            font-size: 14px; font-weight: 600; line-height: 1.2;
            border: none; cursor: pointer; white-space: nowrap;
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            text-decoration: none; transition: all 0.15s;
        }
        .actions .btn-ghost { background: #64748b; border: 1px solid #64748b; color: #fff !important; }
        .actions .btn-ghost:hover { background: #475569; color: #fff !important; text-decoration: none; border-color: #475569; }
        .actions .btn-draft { background: #475569; color: #fff !important; border: 1px solid #475569; }
        .actions .btn-draft:hover { background: #334155; color: #fff !important; }
        .actions .btn-post { background: #1e40af; color: #fff !important; }
        .actions .btn-post:hover { background: #1e3a8a; color: #fff !important; }

        @media (max-width: 600px) {
            .actions {
                display: grid;
                grid-template-columns: 1fr;
                justify-content: stretch;
            }
            .btn {
                width: 100%;
                min-width: 0;
            }
        }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <h1>New Voucher</h1>
            <div class="sub">Record a cash or bank transaction</div>
        </div>
        <a href="gl_vouchers.php" class="btn-new">
            <span class="glyphicon glyphicon-list"></span> All Vouchers
        </a>
    </div>

    <div class="card">

        <?php if (!empty($errors)): ?>
            <div class="error-box">
                <strong>✗ Please fix the following:</strong>
                <ul style="margin: 0; padding-left: 20px;">
                    <?php foreach ($errors as $e): ?>
                        <li><?php echo htmlspecialchars($e); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="" enctype="multipart/form-data" id="voucherForm">

            <div class="section-title">Voucher Type</div>

            <div class="type-cards">
                <?php
                $types = [
                    'CPV' => ['label' => 'Cash Payment',   'icon' => 'glyphicon-arrow-up',   'cls' => 'cpv'],
                    'CRV' => ['label' => 'Cash Receipt',   'icon' => 'glyphicon-arrow-down', 'cls' => 'crv'],
                    'BPV' => ['label' => 'Bank Payment',   'icon' => 'glyphicon-upload',     'cls' => 'bpv'],
                    'BRV' => ['label' => 'Bank Receipt',   'icon' => 'glyphicon-download',   'cls' => 'brv'],
                ];
                foreach ($types as $code => $meta):
                    $selected = ($form['voucher_type'] === $code);
                ?>
                    <label class="type-card <?php echo $meta['cls'] . ($selected ? ' selected' : ''); ?>" data-type="<?php echo $code; ?>">
                        <input type="radio" name="voucher_type" value="<?php echo $code; ?>"
                               <?php echo $selected ? 'checked' : ''; ?>
                               onchange="onTypeChange(this.value)">
                        <span class="icon glyphicon <?php echo $meta['icon']; ?>"></span>
                        <span class="label"><?php echo $meta['label']; ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="section-title">Voucher Details</div>

            <div class="grid-2">
                <div class="field">
                    <label for="entry_date">Entry Date <span class="required">*</span></label>
                    <input type="date" id="entry_date" name="entry_date" required
                           value="<?php echo htmlspecialchars($form['entry_date']); ?>">
                </div>
                <div class="field">
                    <label for="session_id">Session <span class="required">*</span></label>
                    <select id="session_id" name="session_id" required>
                        <?php
                        $sessions = $conn->query("SELECT id, title FROM sessions ORDER BY status ASC, id DESC")->fetch_all(MYSQLI_ASSOC);
                        foreach ($sessions as $s):
                        ?>
                            <option value="<?php echo (int)$s['id']; ?>"
                                <?php echo ((int)$form['session_id'] === (int)$s['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid-2">
                <div class="field">
                    <label for="amount">Amount <span class="required">*</span></label>
                    <input type="number" id="amount" name="amount" step="0.01" min="0.01" required
                           placeholder="0.00"
                           value="<?php echo htmlspecialchars($form['amount']); ?>"
                           oninput="updateAmountPreview()">
                </div>
                <div class="field">
                    <label for="party_name">Party Name <span class="required">*</span></label>
                    <input type="text" id="party_name" name="party_name" maxlength="150" required
                           placeholder="e.g. Ahmed Khan / Supplier Name"
                           value="<?php echo htmlspecialchars($form['party_name']); ?>">
                    <div class="help" id="party_help">Payee or Payer name</div>
                </div>
            </div>

            <div class="grid-2">
                <div class="field">
                    <label for="party_contact">Party Contact</label>
                    <input type="text" id="party_contact" name="party_contact" maxlength="100"
                           placeholder="Optional mobile / email"
                           value="<?php echo htmlspecialchars($form['party_contact']); ?>">
                </div>
                <div class="field">
                    <label for="reference_number">Reference Number</label>
                    <input type="text" id="reference_number" name="reference_number" maxlength="100"
                           placeholder="Cheque no / receipt no / transaction ref"
                           value="<?php echo htmlspecialchars($form['reference_number']); ?>">
                </div>
            </div>

            <div class="grid-2 bank-only" id="bank_fields" style="display: none;">
                <div class="field">
                    <label for="payment_method">Payment Method <span class="required">*</span></label>
                    <select id="payment_method" name="payment_method">
                        <option value="">— Select —</option>
                        <?php foreach (VoucherService::BANK_PAYMENT_METHODS as $m): ?>
                            <option value="<?php echo $m; ?>"
                                <?php echo ($form['payment_method'] === $m) ? 'selected' : ''; ?>>
                                <?php echo ucfirst($m); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="bank_name">Bank Name</label>
                    <input type="text" id="bank_name" name="bank_name" maxlength="100"
                           placeholder="e.g. Meezan Bank, HBL"
                           value="<?php echo htmlspecialchars($form['bank_name']); ?>">
                </div>
            </div>

            <div class="section-title">Accounting</div>

            <div class="field">
                <label for="account_id">
                    <span id="account_label">Account</span> <span class="required">*</span>
                </label>
                <select id="account_id" name="account_id" required>
                    <option value="">— Select Account —</option>
                    <optgroup label="Expenses" id="opt_expenses">
                        <?php foreach ($paymentAccounts as $a): if ($a['account_type'] !== 'EXPENSE') continue; ?>
                            <option value="<?php echo (int)$a['id']; ?>"
                                <?php echo ((int)$form['account_id'] === (int)$a['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($a['code'] . ' — ' . $a['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="Liabilities" id="opt_liabilities">
                        <?php foreach ($paymentAccounts as $a): if ($a['account_type'] !== 'LIABILITY') continue; ?>
                            <option value="<?php echo (int)$a['id']; ?>"
                                <?php echo ((int)$form['account_id'] === (int)$a['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($a['code'] . ' — ' . $a['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="Revenue" id="opt_revenue">
                        <?php foreach ($receiptAccounts as $a): if ($a['account_type'] !== 'REVENUE') continue; ?>
                            <option value="<?php echo (int)$a['id']; ?>"
                                <?php echo ((int)$form['account_id'] === (int)$a['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($a['code'] . ' — ' . $a['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="Assets" id="opt_assets">
                        <?php foreach ($receiptAccounts as $a): if ($a['account_type'] !== 'ASSET') continue; ?>
                            <option value="<?php echo (int)$a['id']; ?>"
                                <?php echo ((int)$form['account_id'] === (int)$a['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($a['code'] . ' — ' . $a['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
                <div class="help" id="account_help">The non-cash account for this transaction</div>
            </div>

            <div class="field">
                <label for="narration">Narration / Purpose</label>
                <textarea id="narration" name="narration" maxlength="500"
                          placeholder="Describe the purpose of this transaction..."><?php echo htmlspecialchars($form['narration']); ?></textarea>
            </div>

            <div class="field">
                <label for="attachment">Attachment (optional)</label>
                <input type="file" id="attachment" name="attachment" accept=".jpg,.jpeg,.png,.pdf">
                <div class="help">Scanned copy of the signed voucher. JPG, PNG, or PDF. Max 5 MB.</div>
            </div>

            <div class="amount-preview">
                <div class="lbl">Amount Preview</div>
                <div class="val" id="amount_preview">0.00</div>
            </div>

            <div class="actions">
                <a href="gl_vouchers.php" class="btn btn-ghost">Cancel</a>
                <button type="submit" name="submit_voucher" value="1"
                        onclick="document.getElementById('save_action').value='draft'"
                        class="btn btn-draft">
                    <span class="glyphicon glyphicon-floppy-disk"></span> Save as Draft
                </button>
                <button type="submit" name="submit_voucher" value="1"
                        onclick="document.getElementById('save_action').value='post'"
                        class="btn btn-post">
                    <span class="glyphicon glyphicon-ok"></span> Save &amp; Post
                </button>
            </div>
            <input type="hidden" name="save_action" id="save_action" value="draft">

        </form>
    </div>
</div>

<script>
function onTypeChange(type) {
    // Highlight selected card
    document.querySelectorAll('.type-card').forEach(el => {
        el.classList.toggle('selected', el.dataset.type === type);
    });

    // Show/hide bank fields
    const bankFields = document.getElementById('bank_fields');
    const isBank = (type === 'BPV' || type === 'BRV');
    bankFields.style.display = isBank ? '' : 'none';
    document.getElementById('payment_method').required = isBank;

    // Update account dropdown groups
    const isPayment = (type === 'CPV' || type === 'BPV');
    const showExpLiab = isPayment;
    const showRevAsset = !isPayment;

    document.getElementById('opt_expenses').style.display    = showExpLiab ? '' : 'none';
    document.getElementById('opt_liabilities').style.display = showExpLiab ? '' : 'none';
    document.getElementById('opt_revenue').style.display     = showRevAsset ? '' : 'none';
    document.getElementById('opt_assets').style.display      = showRevAsset ? '' : 'none';

    // Update labels
    const accountLabel = document.getElementById('account_label');
    const partyHelp    = document.getElementById('party_help');
    const accountHelp  = document.getElementById('account_help');

    if (isPayment) {
        accountLabel.textContent = 'Expense / Liability Account';
        partyHelp.textContent    = 'Who are you paying?';
        accountHelp.textContent  = 'The expense or liability being reduced';
    } else {
        accountLabel.textContent = 'Revenue / Asset Account';
        partyHelp.textContent    = 'Who is paying you?';
        accountHelp.textContent  = 'The revenue or receivable being recorded';
    }

    // Clear the account dropdown selection if the currently selected one
    // is no longer visible
    const sel = document.getElementById('account_id');
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.parentNode) {
        if (opt.parentNode.style.display === 'none') {
            sel.value = '';
        }
    }
}

function updateAmountPreview() {
    const amt = parseFloat(document.getElementById('amount').value) || 0;
    document.getElementById('amount_preview').textContent =
        amt.toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    const currentType = document.querySelector('input[name="voucher_type"]:checked').value;
    onTypeChange(currentType);
    updateAmountPreview();
});
</script>

</body>
</html>
<?php $conn->close(); ?>