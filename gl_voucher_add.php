<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
require_once('conn_inc.php');
require_once('gl.php');
require_once('VoucherService.php');

$conn->query("SET time_zone = '+05:00'");

$service = new VoucherService($conn);

/* ───────── Locked type via ?type=XXX ───────── */
$validTypes = ['CPV','CRV','BPV','BRV'];
$lockedType = '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_GET['type']) && in_array(strtoupper($_GET['type']), $validTypes, true)) {
    $lockedType = strtoupper($_GET['type']);
    $_POST['voucher_type'] = $lockedType;
}

/* Current session */
$currentSession = $conn->query("SELECT id, title FROM sessions WHERE status = 0 ORDER BY id DESC LIMIT 1")->fetch_assoc();
$currentSessionId = $currentSession ? (int)$currentSession['id'] : 0;

$errors = [];

$form = [
    'voucher_type'     => $_POST['voucher_type']     ?? 'CPV',
    'entry_date'       => $_POST['entry_date']       ?? date('Y-m-d'),
    'session_id'       => $_POST['session_id']       ?? $currentSessionId,
    'amount'           => $_POST['amount']           ?? '',
    'party_name'       => $_POST['party_name']       ?? '',
    'party_contact'    => $_POST['party_contact']    ?? '',
    'party_id'         => $_POST['party_id']         ?? '',
    'payment_method'   => $_POST['payment_method']   ?? '',
    'reference_number' => $_POST['reference_number'] ?? '',
    'bank_name'        => $_POST['bank_name']        ?? '',
    'account_id'       => $_POST['account_id']       ?? '',
    'narration'        => $_POST['narration']        ?? '',
    'attachment_path'  => null,
];

/* ───────── Handle POST ───────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_voucher'])) {

    if (!empty($_FILES['attachment']['name'])) {
        $uploadDir = __DIR__ . '/uploads/vouchers/' . date('Y');
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

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

    if (!empty($form['account_id'])) {
        $acc = gl_get_account($conn, (int)$form['account_id']);
        if ($acc && $acc['code'] === '2010' && (int)$form['party_id'] <= 0) {
            $errors[] = "Please select a supplier when posting to Accounts Payable (2010).";
        }
    }

    if (empty($errors)) {
        try {
            $data = [
                'voucher_type'     => $form['voucher_type'],
                'entry_date'       => $form['entry_date'],
                'session_id'       => (int)$form['session_id'],
                'amount'           => (float)$form['amount'],
                'party_name'       => trim($form['party_name']),
                'party_contact'    => trim($form['party_contact']) ?: null,
                'party_id'         => (int)$form['party_id'] > 0 ? (int)$form['party_id'] : null,
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
                $_SESSION['message'] = "Voucher posted — {$result['voucher_number']}";
            } else {
                $_SESSION['message'] = "Voucher saved as draft — {$result['voucher_number']}";
            }
            $_SESSION['message_type'] = 'success';
            header("Location: gl_voucher_view.php?id=" . $result['id']);
            exit();

        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
}

/* ───────── Accounts ───────── */
$allAccounts = gl_list_accounts($conn, null, true);

$paymentAccounts = [];
$receiptAccounts = [];
foreach ($allAccounts as $a) {
    if (in_array($a['account_type'], ['EXPENSE','LIABILITY','ASSET'], true)) $paymentAccounts[] = $a;
    if (in_array($a['account_type'], ['REVENUE','ASSET','LIABILITY'], true)) $receiptAccounts[] = $a;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>New Voucher</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
    body { background: #f5f7fa; font-family: 'Inter', sans-serif; color: #1e293b; padding-bottom: 40px; }
    .wrap { max-width: 1000px; margin: 24px auto; padding: 0 20px; }

    .head { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: #fff; padding: 20px 24px; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; }
    .head h1 { margin: 0; font-size: 19px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .head .sub { font-size: 12px; opacity: 0.88; margin-top: 4px; }
    .btn-new { padding: 8px 14px; border-radius: 7px; font-size: 12px; font-weight: 700; background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.3); text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
    .btn-new:hover { background: #fff; color: #1d4ed8; border-color: #fff; text-decoration: none; }

    .card { background: #fff; padding: 22px 24px 24px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

    .error-box { background: #fee2e2; border-left: 4px solid #dc2626; padding: 11px 14px; border-radius: 8px; margin-bottom: 16px; color: #991b1b; font-size: 12.5px; }
    .error-box strong { display: block; margin-bottom: 5px; }
    .error-box ul { margin: 0; padding-left: 18px; }

    .section-title { font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 1.5px; margin: 18px 0 10px; padding-bottom: 5px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; gap: 6px; }
    .section-title:first-child { margin-top: 0; }
    .section-title i { color: #2563eb; font-size: 10px; }

    .field { margin-bottom: 12px; }
    .field label { display: block; font-size: 10.5px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 4px; }
    .field .required { color: #dc2626; }
    .field input[type=text], .field input[type=date], .field input[type=number], .field select, .field textarea {
        width: 100%; height: 34px; padding: 5px 10px;
        border: 1px solid #cbd5e1; border-radius: 7px;
        font-size: 12.5px; background: #fff; color: #0f172a; font-family: inherit;
    }
    .field textarea { height: auto; min-height: 56px; resize: vertical; padding: 7px 10px; }
    .field input:focus, .field select:focus, .field textarea:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.12); }
    .field .help { font-size: 10.5px; color: #64748b; margin-top: 3px; line-height: 1.4; }

    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    @media (max-width: 700px) { .grid-2 { grid-template-columns: 1fr; } }

    /* Select2 */
    .select2-container .select2-selection--single { height: 34px !important; border: 1px solid #cbd5e1 !important; border-radius: 7px !important; padding: 1px 10px; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 30px !important; color: #0f172a !important; font-size: 12.5px; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 32px !important; }
    .select2-container--focus .select2-selection--single, .select2-container--open .select2-selection--single { border-color: #2563eb !important; box-shadow: 0 0 0 3px rgba(37,99,235,0.12) !important; }
    .select2-dropdown { border-color: #cbd5e1; border-radius: 7px; font-size: 12.5px; }

    /* Type selector cards */
    .type-cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 14px; }
    @media (max-width: 800px) { .type-cards { grid-template-columns: repeat(2, 1fr); } }
    .type-card { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 12px 10px; background: #f8fafc; border: 2px solid #e2e8f0; border-radius: 9px; cursor: pointer; text-decoration: none; color: #1e293b; transition: all 0.15s; }
    .type-card:hover { background: #f0f5ff; border-color: #bfdbfe; }
    .type-card input { display: none; }
    .type-card.selected { background: #eff6ff; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
    .type-card .icon { width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 8px; font-size: 14px; color: #fff; }
    .type-card .label { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; }
    .type-card.cpv .icon { background: #dc2626; }
    .type-card.crv .icon { background: #047857; }
    .type-card.bpv .icon { background: #c2410c; }
    .type-card.brv .icon { background: #2563eb; }

    /* Locked single type banner */
    .locked-type { display: flex; align-items: center; gap: 10px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 9px; padding: 10px 14px; margin-bottom: 14px; }
    .locked-type .icon { width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 8px; font-size: 14px; color: #fff; flex-shrink: 0; }
    .locked-type .icon.cpv { background: #dc2626; }
    .locked-type .icon.crv { background: #047857; }
    .locked-type .icon.bpv { background: #c2410c; }
    .locked-type .icon.brv { background: #2563eb; }
    .locked-type .lbl { font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; }
    .locked-type .val { font-size: 13.5px; font-weight: 700; color: #1e293b; }

    /* Amount preview */
    .amount-preview { background: #f0f5ff; border: 1px solid #bfdbfe; border-radius: 9px; padding: 12px 16px; margin-bottom: 14px; }
    .amount-preview .lbl { font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 4px; }
    .amount-preview .val { font-family: 'SF Mono', monospace; font-size: 18px; font-weight: 700; color: #2563eb; }

    .actions { display: flex; justify-content: flex-end; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 18px; padding-top: 14px; border-top: 1px solid #e2e8f0; }
    .btn { height: 36px; min-width: 130px; padding: 0 16px; border-radius: 7px; font-size: 12.5px; font-weight: 600; border: none; cursor: pointer; white-space: nowrap; display: inline-flex; align-items: center; justify-content: center; gap: 6px; text-decoration: none; font-family: inherit; }
    .btn-ghost { background: #fff; border: 1px solid #cbd5e1; color: #475569; }
    .btn-ghost:hover { background: #f8fafc; color: #1e293b; text-decoration: none; }
    .btn-draft { background: #475569; color: #fff; border: 1px solid #475569; }
    .btn-draft:hover { background: #334155; color: #fff; border-color: #334155; }
    .btn-post { background: #2563eb; color: #fff; border: 1px solid #2563eb; }
    .btn-post:hover { background: #1d4ed8; color: #fff; border-color: #1d4ed8; }

    @media (max-width: 600px) { .actions { display: grid; grid-template-columns: 1fr; justify-content: stretch; } .btn { width: 100%; min-width: 0; } }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <h1><i class="fas fa-receipt"></i> New Voucher</h1>
            <div class="sub">
                <?php if ($lockedType):
                    $labelMap = ['CPV' => 'Cash Payment', 'CRV' => 'Cash Receipt', 'BPV' => 'Bank Payment', 'BRV' => 'Bank Receipt'];
                    echo htmlspecialchars($labelMap[$lockedType]) . ' voucher';
                else: ?>
                    Record a cash or bank transaction
                <?php endif; ?>
            </div>
        </div>
        <a href="gl_vouchers.php" class="btn-new"><i class="fas fa-list"></i> All Vouchers</a>
    </div>

    <div class="card">

        <?php if (!empty($errors)): ?>
            <div class="error-box">
                <strong><i class="fas fa-exclamation-circle"></i> Please fix:</strong>
                <ul>
                    <?php foreach ($errors as $e): ?>
                        <li><?= htmlspecialchars($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($perms['can_add'])): ?>
        <form method="post" action="" enctype="multipart/form-data" id="voucherForm">

            <div class="section-title"><i class="fas fa-tag"></i> Voucher Type</div>

            <?php if ($lockedType):
                $labelMap = ['CPV' => 'Cash Payment', 'CRV' => 'Cash Receipt', 'BPV' => 'Bank Payment', 'BRV' => 'Bank Receipt'];
                $iconMap  = ['CPV' => 'fa-arrow-up', 'CRV' => 'fa-arrow-down', 'BPV' => 'fa-upload', 'BRV' => 'fa-download'];
            ?>
                <input type="hidden" name="voucher_type" value="<?= htmlspecialchars($lockedType) ?>">
                <div class="locked-type">
                    <div class="icon <?= strtolower($lockedType) ?>"><i class="fas <?= $iconMap[$lockedType] ?>"></i></div>
                    <div>
                        <div class="lbl">Voucher Type</div>
                        <div class="val"><?= htmlspecialchars($labelMap[$lockedType]) ?></div>
                    </div>
                </div>
            <?php else:
                $types = [
                    'CPV' => ['label' => 'Cash Payment',   'icon' => 'fa-arrow-up',   'cls' => 'cpv'],
                    'CRV' => ['label' => 'Cash Receipt',   'icon' => 'fa-arrow-down', 'cls' => 'crv'],
                    'BPV' => ['label' => 'Bank Payment',   'icon' => 'fa-upload',     'cls' => 'bpv'],
                    'BRV' => ['label' => 'Bank Receipt',   'icon' => 'fa-download',   'cls' => 'brv'],
                ];
                foreach ($types as $code => $meta):
                    $selected = ($form['voucher_type'] === $code);
            ?>
                <div class="type-cards" style="grid-template-columns: repeat(4, 1fr);">
                    <?php foreach ($types as $c2 => $m2): $sel2 = ($form['voucher_type'] === $c2); ?>
                        <label class="type-card <?= $m2['cls'] . ($sel2 ? ' selected' : '') ?>" data-type="<?= $c2 ?>">
                            <input type="radio" name="voucher_type" value="<?= $c2 ?>" <?= $sel2 ? 'checked' : '' ?> onchange="onTypeChange(this.value)">
                            <span class="icon"><i class="fas <?= $m2['icon'] ?>"></i></span>
                            <span class="label"><?= $m2['label'] ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php break; endforeach; endif; ?>

            <div class="section-title"><i class="fas fa-file-alt"></i> Voucher Details</div>

            <div class="grid-2">
                <div class="field">
                    <label for="entry_date">Entry Date <span class="required">*</span></label>
                    <input type="date" id="entry_date" name="entry_date" required value="<?= htmlspecialchars($form['entry_date']) ?>">
                </div>
                <div class="field">
                    <label for="session_id">Session <span class="required">*</span></label>
                    <select id="session_id" name="session_id" required>
                        <?php
                        $sessions = $conn->query("SELECT id, title FROM sessions ORDER BY status ASC, id DESC")->fetch_all(MYSQLI_ASSOC);
                        foreach ($sessions as $s):
                        ?>
                            <option value="<?= (int)$s['id'] ?>" <?= ((int)$form['session_id'] === (int)$s['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid-2">
                <div class="field">
                    <label for="amount">Amount <span class="required">*</span></label>
                    <input type="number" id="amount" name="amount" step="0.01" min="0.01" required placeholder="0.00" value="<?= htmlspecialchars($form['amount']) ?>" oninput="updateAmountPreview()">
                </div>
                <div class="field">
                    <label for="party_name">Party Name <span class="required">*</span></label>
                    <input type="text" id="party_name" name="party_name" maxlength="150" required placeholder="e.g. Ahmed Khan / Supplier Name" value="<?= htmlspecialchars($form['party_name']) ?>">
                    <div class="help" id="party_help">Payee or Payer name</div>
                </div>
            </div>

            <div class="grid-2">
                <div class="field">
                    <label for="party_contact">Party Contact</label>
                    <input type="text" id="party_contact" name="party_contact" maxlength="100" placeholder="Optional mobile / email" value="<?= htmlspecialchars($form['party_contact']) ?>">
                </div>
                <div class="field">
                    <label for="reference_number">Reference Number</label>
                    <input type="text" id="reference_number" name="reference_number" maxlength="100" placeholder="Cheque no / receipt no / transaction ref" value="<?= htmlspecialchars($form['reference_number']) ?>">
                </div>
            </div>

            <div class="grid-2 bank-only" id="bank_fields" style="display: none;">
                <div class="field">
                    <label for="payment_method">Payment Method <span class="required">*</span></label>
                    <select id="payment_method" name="payment_method">
                        <option value="">— Select —</option>
                        <?php foreach (VoucherService::BANK_PAYMENT_METHODS as $m): ?>
                            <option value="<?= $m ?>" <?= ($form['payment_method'] === $m) ? 'selected' : '' ?>><?= ucfirst($m) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="bank_name">Bank Name</label>
                    <input type="text" id="bank_name" name="bank_name" maxlength="100" placeholder="e.g. Meezan Bank, HBL" value="<?= htmlspecialchars($form['bank_name']) ?>">
                </div>
            </div>

            <div class="section-title"><i class="fas fa-book"></i> Accounting</div>

            <div class="field">
                <label for="account_id"><span id="account_label">Account</span> <span class="required">*</span></label>
                <select id="account_id" name="account_id" required>
                    <option value="">— Select Account —</option>

                    <optgroup label="Expenses" id="opt_expenses">
                        <?php foreach ($paymentAccounts as $a): if ($a['account_type'] !== 'EXPENSE') continue; ?>
                            <option value="<?= (int)$a['id'] ?>" data-code="<?= htmlspecialchars($a['code']) ?>" <?= ((int)$form['account_id'] === (int)$a['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($a['code'] . ' — ' . $a['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>

                    <optgroup label="Liabilities" id="opt_liabilities">
                        <?php foreach ($paymentAccounts as $a): if ($a['account_type'] !== 'LIABILITY') continue; ?>
                            <option value="<?= (int)$a['id'] ?>" data-code="<?= htmlspecialchars($a['code']) ?>" <?= ((int)$form['account_id'] === (int)$a['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($a['code'] . ' — ' . $a['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>

                    <optgroup label="Revenue" id="opt_revenue">
                        <?php foreach ($receiptAccounts as $a): if ($a['account_type'] !== 'REVENUE') continue; ?>
                            <option value="<?= (int)$a['id'] ?>" data-code="<?= htmlspecialchars($a['code']) ?>" <?= ((int)$form['account_id'] === (int)$a['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($a['code'] . ' — ' . $a['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>

                    <optgroup label="Assets" id="opt_assets">
                        <?php foreach ($receiptAccounts as $a): if ($a['account_type'] !== 'ASSET') continue; ?>
                            <option value="<?= (int)$a['id'] ?>" data-code="<?= htmlspecialchars($a['code']) ?>" <?= ((int)$form['account_id'] === (int)$a['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($a['code'] . ' — ' . $a['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
                <div class="help" id="account_help">The non-cash account for this transaction</div>
            </div>

            <div class="field" id="supplier_field" style="display:none;">
                <label for="party_id">Supplier <span class="required">*</span></label>
                <select id="party_id" name="party_id">
                    <option value="">— Select supplier —</option>
                    <?php
                    $supRes = $conn->query("SELECT id, name FROM gl_parties WHERE party_type='SUPPLIER' AND status=1 ORDER BY name COLLATE utf8mb4_general_ci");
                    while ($sp = $supRes->fetch_assoc()):
                    ?>
                        <option value="<?= (int)$sp['id'] ?>" <?= ((int)$form['party_id'] === (int)$sp['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sp['name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <div class="help">Required when posting to <code>2010 Accounts Payable</code></div>
            </div>

            <div class="field">
                <label for="narration">Narration / Purpose</label>
                <textarea id="narration" name="narration" maxlength="500" placeholder="Describe the purpose of this transaction..."><?= htmlspecialchars($form['narration']) ?></textarea>
            </div>

            <div class="field">
                <label for="attachment">Attachment (optional)</label>
                <input type="file" id="attachment" name="attachment" accept=".jpg,.jpeg,.png,.pdf" style="height:auto; padding:6px 10px;">
                <div class="help">Scanned copy of the signed voucher. JPG, PNG, or PDF. Max 5 MB.</div>
            </div>

            <div class="amount-preview">
                <div class="lbl">Amount Preview</div>
                <div class="val" id="amount_preview">0.00</div>
            </div>

            <div class="actions">
                <a href="gl_vouchers.php" class="btn btn-ghost">Cancel</a>
                <button type="submit" name="submit_voucher" value="1" onclick="document.getElementById('save_action').value='draft'" class="btn btn-draft">
                    <i class="fas fa-save"></i> Save as Draft
                </button>
                <button type="submit" name="submit_voucher" value="1" onclick="document.getElementById('save_action').value='post'" class="btn btn-post">
                    <i class="fas fa-check"></i> Save &amp; Post
                </button>
            </div>
            <input type="hidden" name="save_action" id="save_action" value="draft">

        </form>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
function onTypeChange(type) {
    document.querySelectorAll('.type-card').forEach(el => {
        el.classList.toggle('selected', el.dataset.type === type);
    });

    const bankFields = document.getElementById('bank_fields');
    const isBank = (type === 'BPV' || type === 'BRV');
    bankFields.style.display = isBank ? '' : 'none';
    const pm = document.getElementById('payment_method');
    if (pm) pm.required = isBank;

    const isPayment = (type === 'CPV' || type === 'BPV');

    document.getElementById('opt_expenses').style.display    = isPayment ? '' : 'none';
    document.getElementById('opt_liabilities').style.display = '';
    document.getElementById('opt_revenue').style.display     = isPayment ? 'none' : '';
    document.getElementById('opt_assets').style.display      = '';

    const accountLabel = document.getElementById('account_label');
    const partyHelp    = document.getElementById('party_help');
    const accountHelp  = document.getElementById('account_help');

    if (isPayment) {
        accountLabel.textContent = 'Account (Expense / Liability / Asset)';
        partyHelp.textContent    = 'Who are you paying?';
        accountHelp.textContent  = 'Expense, liability, or asset being debited';
    } else {
        accountLabel.textContent = 'Account (Revenue / Asset / Liability)';
        partyHelp.textContent    = 'Who is paying you?';
        accountHelp.textContent  = 'Revenue, asset, or liability being credited';
    }

    const sel = document.getElementById('account_id');
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.parentNode && opt.parentNode.style.display === 'none') {
        if (window.jQuery && jQuery(sel).data('select2')) jQuery(sel).val('').trigger('change');
        else sel.value = '';
    }

    syncSupplierField();
}

function updateAmountPreview() {
    const amt = parseFloat(document.getElementById('amount').value) || 0;
    document.getElementById('amount_preview').textContent =
        amt.toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function syncSupplierField() {
    var accSel = document.getElementById('account_id');
    var supField = document.getElementById('supplier_field');
    var supSel = document.getElementById('party_id');
    if (!accSel || !supField || !supSel) return;

    var opt = accSel.options[accSel.selectedIndex];
    var code = opt ? (opt.getAttribute('data-code') || '') : '';
    if (code === '2010') {
        supField.style.display = '';
        supSel.required = true;
    } else {
        supField.style.display = 'none';
        supSel.required = false;
        if (window.jQuery && jQuery(supSel).data('select2')) jQuery(supSel).val('').trigger('change');
        else supSel.value = '';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var typeInput = document.querySelector('input[name="voucher_type"]:checked');
    if (typeInput) onTypeChange(typeInput.value);
    updateAmountPreview();

    if (window.jQuery && jQuery.fn.select2) {
        jQuery('#session_id').select2({ width: '100%' });
        jQuery('#account_id').select2({ width: '100%', placeholder: '— Select Account —' });
        jQuery('#party_id').select2({ width: '100%', placeholder: '— Select supplier —', dropdownParent: jQuery('body') });
        jQuery('#account_id').on('change', function(){ syncSupplierField(); });
    } else {
        document.getElementById('account_id').addEventListener('change', syncSupplierField);
    }
});
</script>

</body>
</html>
<?php $conn->close(); ?>