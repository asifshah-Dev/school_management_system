<?php
require_once 'security.php';
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
require_once 'conn_inc.php';
require_once 'gl.php';

$conn->set_charset('utf8mb4');

/* ───────── Plain local variables — no array keys to miss ───────── */
$f_name    = '';
$f_phone   = '';
$f_email   = '';
$f_address = '';
$f_mobile  = '';
$f_contact = '';
$f_tax_id  = '';

$errors = [];
$isEdit = false;
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

/* ───────── Load for edit ───────── */
if ($id > 0) {
    $isEdit = true;
    $stmt = $conn->prepare("SELECT name, phone, email, address, mobile, contact, tax_id FROM gl_parties WHERE id = ? AND party_type = 'SUPPLIER' LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$existing) {
        $_SESSION['message'] = 'Supplier not found.';
        $_SESSION['message_type'] = 'danger';
        header("Location: gl_parties.php");
        exit();
    }
    $f_name    = isset($existing['name'])    ? (string)$existing['name']    : '';
    $f_phone   = isset($existing['phone'])   ? (string)$existing['phone']   : '';
    $f_email   = isset($existing['email'])   ? (string)$existing['email']   : '';
    $f_address = isset($existing['address']) ? (string)$existing['address'] : '';
    $f_mobile  = isset($existing['mobile'])  ? (string)$existing['mobile']  : '';
    $f_contact = isset($existing['contact']) ? (string)$existing['contact'] : '';
    $f_tax_id  = isset($existing['tax_id'])  ? (string)$existing['tax_id']  : '';
}

/* ───────── POST ───────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_supplier'])) {

    $f_name    = isset($_POST['name'])    ? trim((string)$_POST['name'])    : '';
    $f_phone   = isset($_POST['phone'])   ? trim((string)$_POST['phone'])   : '';
    $f_email   = isset($_POST['email'])   ? trim((string)$_POST['email'])   : '';
    $f_address = isset($_POST['address']) ? trim((string)$_POST['address']) : '';
    $f_mobile  = isset($_POST['mobile'])  ? trim((string)$_POST['mobile'])  : '';
    $f_contact = isset($_POST['contact']) ? trim((string)$_POST['contact']) : '';
    $f_tax_id  = isset($_POST['tax_id'])  ? trim((string)$_POST['tax_id'])  : '';

    if ($f_name === '') {
        $errors[] = 'Supplier name is required.';
    }

    if (empty($errors)) {
        if ($isEdit) {
            $stmt = $conn->prepare("UPDATE gl_parties SET name=?, phone=?, email=?, address=?, mobile=?, contact=?, tax_id=? WHERE id=? AND party_type='SUPPLIER'");
            if ($stmt) {
                $stmt->bind_param("sssssssi", $f_name, $f_phone, $f_email, $f_address, $f_mobile, $f_contact, $f_tax_id, $id);
                if ($stmt->execute()) {
                    $_SESSION['message'] = "Supplier updated — " . $f_name;
                    $_SESSION['message_type'] = 'success';
                    header("Location: gl_parties.php");
                    exit();
                } else {
                    $errors[] = 'Update failed: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $errors[] = 'Prepare failed: ' . $conn->error;
            }
        } else {
            $stmt = $conn->prepare("INSERT INTO gl_parties (party_type, name, phone, email, address, mobile, contact, tax_id, status) VALUES ('SUPPLIER', ?, ?, ?, ?, ?, ?, ?, 1)");
            if ($stmt) {
                $stmt->bind_param("sssssss", $f_name, $f_phone, $f_email, $f_address, $f_mobile, $f_contact, $f_tax_id);
                if ($stmt->execute()) {
                    $newId = $conn->insert_id;
                    $_SESSION['message'] = "Supplier added — " . $f_name . " (ID #" . $newId . ")";
                    $_SESSION['message_type'] = 'success';
                    header("Location: gl_parties.php");
                    exit();
                } else {
                    $errors[] = 'Insert failed: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $errors[] = 'Prepare failed: ' . $conn->error;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $isEdit ? 'Edit Supplier' : 'Add Supplier' ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    body { background: #f5f7fa; font-family: 'Inter', sans-serif; color: #1e293b; padding-bottom: 40px; }
    .wrap { max-width: 800px; margin: 24px auto; padding: 0 20px; }

    .head { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: #fff; padding: 20px 24px; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; }
    .head h1 { margin: 0; font-size: 19px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .head .sub { font-size: 12px; opacity: 0.88; margin-top: 4px; }
    .btn-back { padding: 8px 14px; border-radius: 7px; font-size: 12px; font-weight: 700; background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.3); text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
    .btn-back:hover { background: rgba(255,255,255,0.25); color: #fff; text-decoration: none; }

    .content { background: #fff; padding: 20px 22px 22px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

    .error-box { background: #fee2e2; border-left: 4px solid #dc2626; padding: 11px 14px; border-radius: 8px; margin-bottom: 14px; color: #991b1b; font-size: 12.5px; }
    .error-box strong { display: block; margin-bottom: 4px; }
    .error-box ul { margin: 0; padding-left: 18px; }

    .section-title { font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 1.2px; margin: 14px 0 8px; padding-bottom: 5px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; gap: 6px; }
    .section-title:first-child { margin-top: 0; }
    .section-title i { color: #2563eb; font-size: 10px; }

    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .grid-1 { display: grid; grid-template-columns: 1fr; gap: 10px; }
    @media (max-width: 600px) { .grid-2 { grid-template-columns: 1fr; } }

    .field label { display: block; font-size: 10.5px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 4px; }
    .field .required { color: #dc2626; }
    .field input[type=text], .field input[type=email], .field textarea { width: 100%; height: 34px; padding: 5px 10px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 12.5px; font-family: inherit; background: #fff; color: #0f172a; }
    .field textarea { height: auto; min-height: 56px; resize: vertical; padding: 7px 10px; }
    .field input:focus, .field textarea:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.12); }

    .hint { background: #eff6ff; border: 1px solid #bfdbfe; padding: 9px 12px; border-radius: 7px; font-size: 11.5px; color: #1e3a8a; margin-top: 12px; }
    .hint code { background: #dbeafe; padding: 1px 5px; border-radius: 3px; }

    .actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; padding-top: 14px; border-top: 1px solid #e2e8f0; }
    .btn { height: 36px; padding: 0 16px; border-radius: 7px; font-size: 12.5px; font-weight: 600; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; font-family: inherit; }
    .btn-primary { background: #2563eb; color: #fff; }
    .btn-primary:hover { background: #1d4ed8; color: #fff; text-decoration: none; }
    .btn-ghost { background: #fff; border: 1px solid #cbd5e1; color: #475569; }
    .btn-ghost:hover { background: #f8fafc; color: #1e293b; text-decoration: none; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <h1><i class="fas <?= $isEdit ? 'fa-pen' : 'fa-plus' ?>"></i> <?= $isEdit ? 'Edit Supplier' : 'Add Supplier' ?></h1>
            <div class="sub"><?= $isEdit ? 'Update supplier information' : 'Register a new supplier' ?></div>
        </div>
        <a href="gl_parties.php" class="btn-back"><i class="fas fa-arrow-left"></i> Suppliers</a>
    </div>

    <div class="content">

        <?php if (!empty($errors)): ?>
            <div class="error-box">
                <strong><i class="fas fa-exclamation-circle"></i> Please fix:</strong>
                <ul>
                    <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($perms['can_add']) || !empty($perms['can_edit'])): ?>
        <form method="post" action="" id="supForm">
            <input type="hidden" name="submit_supplier" value="1">

            <div class="section-title"><i class="fas fa-id-card"></i> Identity</div>
            <div class="grid-1">
                <div class="field">
                    <label>Name <span class="required">*</span></label>
                    <input type="text" name="name" maxlength="150" required value="<?= htmlspecialchars($f_name) ?>" placeholder="e.g. Ahmed Traders">
                </div>
            </div>

            <div class="section-title"><i class="fas fa-phone"></i> Contact</div>
            <div class="grid-2">
                <div class="field">
                    <label>Phone</label>
                    <input type="text" name="phone" maxlength="50" value="<?= htmlspecialchars($f_phone) ?>" placeholder="0300-1234567">
                </div>
                <div class="field">
                    <label>Mobile</label>
                    <input type="text" name="mobile" maxlength="20" value="<?= htmlspecialchars($f_mobile) ?>" placeholder="Optional">
                </div>
                <div class="field">
                    <label>Email</label>
                    <input type="email" name="email" maxlength="150" value="<?= htmlspecialchars($f_email) ?>" placeholder="sales@supplier.com">
                </div>
                <div class="field">
                    <label>Contact Person</label>
                    <input type="text" name="contact" maxlength="100" value="<?= htmlspecialchars($f_contact) ?>" placeholder="Who you deal with">
                </div>
            </div>

            <div class="section-title"><i class="fas fa-file-invoice"></i> Tax &amp; Address</div>
            <div class="grid-1">
                <div class="field">
                    <label>Tax ID / NTN</label>
                    <input type="text" name="tax_id" maxlength="50" value="<?= htmlspecialchars($f_tax_id) ?>" placeholder="Optional">
                </div>
            </div>
            <div class="grid-1">
                <div class="field">
                    <label>Address</label>
                    <textarea name="address" rows="2" placeholder="Optional"><?= htmlspecialchars($f_address) ?></textarea>
                </div>
            </div>

            <div class="hint">
                <strong>Opening balance?</strong> Post it as a journal entry (Dr/Cr <code>2010 Accounts Payable</code> with this supplier selected), not here.
            </div>

            <div class="actions">
                <a href="gl_parties.php" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> <?= $isEdit ? 'Save Changes' : 'Add Supplier' ?>
                </button>
            </div>
        </form>
        <?php endif; ?>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
<?php $conn->close(); ?>