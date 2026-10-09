<?php
require_once 'security.php';
require_once 'conn_inc.php';
require_once 'gl.php';

$conn->set_charset('utf8mb4');
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

$page_title = 'Suppliers';
$msg = ''; $msg_type = '';

/* ---------- POST: add / edit / toggle ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $id      = (int)($_POST['id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $mobile  = trim($_POST['mobile'] ?? '');
        $contact = trim($_POST['contact'] ?? '');
        $tax_id  = trim($_POST['tax_id'] ?? '');

        if ($name === '') {
            $msg = 'Supplier name is required.'; $msg_type = 'danger';
        } else {
            if ($action === 'add') {
                $stmt = $conn->prepare("
                    INSERT INTO gl_parties
                        (party_type, name, phone, email, address, mobile, contact, tax_id, status)
                    VALUES ('SUPPLIER', ?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $stmt->bind_param("sssssss",
                    $name, $phone, $email, $address, $mobile, $contact, $tax_id);
                if ($stmt->execute()) {
                    $msg = 'Supplier added — ID ' . $conn->insert_id . '.';
                    $msg_type = 'success';
                } else {
                    $msg = 'Insert failed: ' . $stmt->error; $msg_type = 'danger';
                }
                $stmt->close();
            } else {
                $stmt = $conn->prepare("
                    UPDATE gl_parties
                    SET name=?, phone=?, email=?, address=?, mobile=?, contact=?, tax_id=?
                    WHERE id=? AND party_type='SUPPLIER'
                ");
                $stmt->bind_param("sssssssi",
                    $name, $phone, $email, $address, $mobile, $contact, $tax_id, $id);
                if ($stmt->execute()) {
                    $msg = 'Supplier updated.'; $msg_type = 'success';
                } else {
                    $msg = 'Update failed: ' . $stmt->error; $msg_type = 'danger';
                }
                $stmt->close();
            }
        }
    }

    if ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("
                UPDATE gl_parties
                SET status = IF(status = 1, 0, 1)
                WHERE id = ? AND party_type = 'SUPPLIER'
            ");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            $msg = 'Status updated.'; $msg_type = 'success';
        }
    }
}

$suppliers = gl_list_suppliers($conn, true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($page_title) ?> — Dar-e-Arqm School</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    * { box-sizing: border-box; }
    body {
        background: #eef1f5;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        margin: 0; padding: 0; color: #1e293b;
    }
    .wrap { max-width: 1300px; margin: 30px auto; padding: 0 20px; }

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
        cursor: pointer;
        transition: all 0.15s;
        font-family: inherit;
    }
    .btn-new:hover { background: #fff; color: #1e40af; border-color: #fff; }

    .btn-new.outline {
        background: transparent;
        border: 1px solid rgba(255, 255, 255, 0.5);
    }
    .btn-new.outline:hover { background: rgba(255, 255, 255, 0.15); color: #fff; }

    .card {
        background: #fff;
        padding: 30px 40px 40px 40px;
        border-radius: 0 0 12px 12px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    }

    .section-title {
        font-size: 12px; font-weight: 800; color: #64748b;
        text-transform: uppercase; letter-spacing: 2px;
        margin: 28px 0 14px 0;
        padding-bottom: 8px;
        border-bottom: 1px solid #e2e8f0;
    }
    .section-title:first-child { margin-top: 0; }

    .alert-custom {
        padding: 14px 18px; border-radius: 8px; margin-bottom: 20px;
        font-size: 14px; border-left: 4px solid;
    }
    .alert-custom.success { background: #d1fae5; border-color: #059669; color: #065f46; }
    .alert-custom.danger  { background: #fee2e2; border-color: #dc2626; color: #991b1b; }

    .toolbar {
        display: flex; gap: 10px; flex-wrap: wrap;
        align-items: center; margin-bottom: 16px;
    }
    .toolbar input[type=text] {
        height: 40px; padding: 8px 14px;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 14px; background: #fff; color: #0f172a;
        flex: 1; min-width: 200px; max-width: 320px;
        font-family: inherit;
    }
    .toolbar input[type=text]:focus {
        outline: none; border-color: #1e40af;
        box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
    }
    .toolbar .check {
        display: inline-flex; align-items: center; gap: 6px;
        font-size: 13px; color: #475569; cursor: pointer;
        padding: 8px 4px;
    }
    .btn-soft {
        height: 40px; padding: 0 16px;
        background: #f1f5f9; color: #334155;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: 13px; font-weight: 600;
        cursor: pointer; font-family: inherit;
        display: inline-flex; align-items: center; gap: 6px;
        transition: all 0.15s;
        text-decoration: none;
    }
    .btn-soft:hover { background: #e2e8f0; color: #1e293b; text-decoration: none; }

    table.tbl {
        width: 100%; border-collapse: collapse;
        background: #fff; border-radius: 8px; overflow: hidden;
        border: 1px solid #e2e8f0;
    }
    table.tbl thead th {
        background: #f8fafc;
        font-size: 11px; color: #64748b; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px;
        padding: 12px 14px; text-align: left;
        border-bottom: 2px solid #e2e8f0;
    }
    table.tbl tbody td {
        padding: 12px 14px;
        font-size: 14px; color: #1e293b;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }
    table.tbl tbody tr:hover td { background: #f8fafc; }
    table.tbl .num {
        font-family: 'SF Mono', 'Monaco', monospace;
        font-variant-numeric: tabular-nums;
        text-align: right;
    }
    table.tbl .bal-pos { color: #c62828; font-weight: 700; }
    table.tbl .bal-zero { color: #94a3b8; }
    .inactive-row { opacity: 0.55; }

    .status-toggle {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 4px 10px; border-radius: 12px;
        font-size: 11px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.5px;
        cursor: pointer; font-family: inherit;
        border: 1px solid transparent;
        transition: all 0.15s;
    }
    .status-toggle.on  { background: #d1fae5; color: #065f46; border-color: #a7f3d0; }
    .status-toggle.on:hover  { background: #a7f3d0; }
    .status-toggle.off { background: #f1f5f9; color: #64748b; border-color: #e2e8f0; }
    .status-toggle.off:hover { background: #e2e8f0; }

    .row-btn {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 5px 10px; border-radius: 6px;
        font-size: 12px; font-weight: 600;
        text-decoration: none; cursor: pointer;
        font-family: inherit;
        border: 1px solid;
        transition: all 0.15s;
        white-space: nowrap;
    }
    .row-btn-info { background: #eff6ff; color: #1e40af; border-color: #bfdbfe; }
    .row-btn-info:hover { background: #dbeafe; color: #1e40af; text-decoration: none; }
    .row-btn-stmt { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
    .row-btn-stmt:hover { background: #d1fae5; color: #065f46; text-decoration: none; }
    .row-btn-edit { background: #f8fafc; color: #334155; border-color: #e2e8f0; }
    .row-btn-edit:hover { background: #f1f5f9; color: #1e293b; }

    /* ============ Top-drop Panel ============ */
    .panel-backdrop {
        position: fixed; inset: 0;
        background: rgba(15, 23, 42, 0.35);
        opacity: 0; visibility: hidden;
        transition: opacity 0.2s, visibility 0.2s;
        z-index: 9998;
    }
    .panel-backdrop.open { opacity: 1; visibility: visible; }

    .panel {
        position: fixed; top: 0; left: 0; right: 0;
        width: 100%;
        max-height: 100vh;
        background: #fff;
        box-shadow: 0 12px 32px rgba(0,0,0,0.18);
        transform: translateY(-100%);
        transition: transform 0.28s ease-out;
        z-index: 9999;
        display: flex; flex-direction: column;
        border-radius: 0 0 12px 12px;
    }
    .panel.open { transform: translateY(0); }

    .panel-head {
        background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
        color: #fff;
        padding: 20px 40px;
        flex-shrink: 0;
        display: flex; justify-content: space-between; align-items: center;
        gap: 16px;
    }
    .panel-head h3 { margin: 0; font-size: 20px; font-weight: 700; }
    .panel-head .sub { font-size: 12px; opacity: 0.85; margin-top: 3px; }
    .panel-close {
        background: rgba(255,255,255,0.15);
        border: 1px solid rgba(255,255,255,0.3);
        color: #fff;
        width: 34px; height: 34px;
        border-radius: 8px;
        font-size: 18px; line-height: 1;
        cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        transition: all 0.15s;
        flex-shrink: 0;
    }
    .panel-close:hover { background: #fff; color: #1e40af; }

    .panel-body {
        flex: 1;
        overflow-y: auto;
        padding: 24px 40px 12px 40px;
        max-width: 1200px;
        margin: 0 auto;
        width: 100%;
    }

    .panel-foot {
        padding: 16px 40px 18px 40px;
        background: #f8fafc;
        border-top: 1px solid #e2e8f0;
        display: flex; gap: 10px; justify-content: flex-end;
        flex-shrink: 0;
    }

    .field { margin-bottom: 14px; }
    .field label {
        display: block; font-size: 11px; font-weight: 700; color: #334155;
        text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 5px;
    }
    .field .required { color: #dc2626; }
    .field input[type=text],
    .field input[type=email],
    .field textarea {
        width: 100%; height: 42px; padding: 9px 13px;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 14px; background: #fff; color: #0f172a;
        font-family: inherit;
        transition: all 0.15s;
    }
    .field textarea { height: auto; min-height: 60px; resize: vertical; }
    .field input:focus, .field textarea:focus {
        outline: none; border-color: #1e40af;
        box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
    }

    .form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 14px 18px;
    }
    .form-grid .span-2 { grid-column: span 2; }
    .form-grid .span-3 { grid-column: span 3; }
    @media (max-width: 900px) {
        .form-grid { grid-template-columns: 1fr 1fr; }
        .form-grid .span-3 { grid-column: span 2; }
    }
    @media (max-width: 600px) {
        .form-grid { grid-template-columns: 1fr; }
        .form-grid .span-2, .form-grid .span-3 { grid-column: span 1; }
    }

    .hint {
        background: #eff6ff; border: 1px solid #bfdbfe;
        padding: 10px 14px; border-radius: 8px;
        font-size: 12px; color: #1e40af;
        margin-top: 8px;
    }
    .hint code { background: #dbeafe; padding: 1px 5px; border-radius: 3px; }

    .btn-primary-custom {
        height: 42px; padding: 0 22px;
        background: #1e40af; color: #fff;
        border: none; border-radius: 8px;
        font-size: 14px; font-weight: 600;
        cursor: pointer; font-family: inherit;
        display: inline-flex; align-items: center; gap: 6px;
        transition: all 0.15s;
    }
    .btn-primary-custom:hover { background: #1e3a8a; }
    .btn-ghost-custom {
        height: 42px; padding: 0 22px;
        background: #fff; color: #334155;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 14px; font-weight: 600;
        cursor: pointer; font-family: inherit;
        display: inline-flex; align-items: center; gap: 6px;
        transition: all 0.15s;
    }
    .btn-ghost-custom:hover { background: #f1f5f9; }

    .recon-badge {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 4px 10px; border-radius: 12px;
        font-size: 11px; font-weight: 700;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        margin-left: 10px;
    }
    .recon-badge.ok  { background: #d1fae5; color: #065f46; }
    .recon-badge.bad { background: #fee2e2; color: #991b1b; }

    @media print {
        .no-print { display: none !important; }
        body { background: #fff; }
        .head { background: #fff; color: #000; border-bottom: 2px solid #000; box-shadow: none; }
        .card { box-shadow: none; padding: 0; }
        table.tbl thead th { background: #eee; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <h1>Suppliers
                <?php
                $recon = gl_ap_reconciliation($conn);
                if ($recon['ok']): ?>
                    <span class="recon-badge ok">✓ Reconciled</span>
                <?php else: ?>
                    <span class="recon-badge bad">✗ Broken (<?= number_format($recon['diff'], 2) ?>)</span>
                <?php endif; ?>
            </h1>
            <div class="sub">Register suppliers and trace what the school owes them</div>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="gl_ap_aging.php" class="btn-new outline no-print">
                <span class="glyphicon glyphicon-time"></span> AP Aging
            </a>
            <button type="button" class="btn-new no-print" id="btnAdd">
                <span class="glyphicon glyphicon-plus"></span> Add Supplier
            </button>
        </div>
    </div>

    <div class="card">

        <?php if ($msg): ?>
            <div class="alert-custom <?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <?php if (!$recon['ok']): ?>
            <div class="alert-custom danger">
                <strong>Sub-ledger broken.</strong>
                GL 2010 total = <?= number_format($recon['gl'], 2) ?>,
                sum of supplier balances = <?= number_format($recon['sub'], 2) ?>
                (diff <?= number_format($recon['diff'], 2) ?>).
                Some AP journal lines have no <code>party_id</code>.
            </div>
        <?php endif; ?>

        <div class="section-title">Supplier List</div>

        <div class="toolbar no-print">
            <input type="text" id="searchBox" placeholder="Search name / phone / email...">
            <label class="check">
                <input type="checkbox" id="showInactive"> Show inactive
            </label>
            <button type="button" class="btn-soft" onclick="window.print()">
                <span class="glyphicon glyphicon-print"></span> Print
            </button>
            <button type="button" class="btn-soft" onclick="exportCSV()">
                <span class="glyphicon glyphicon-download-alt"></span> CSV
            </button>
        </div>

        <table class="tbl" id="supTable">
            <thead>
                <tr>
                    <th style="width:60px;">ID</th>
                    <th>Name</th>
                    <th style="width:140px;">Phone</th>
                    <th>Email</th>
                    <th class="num" style="width:150px;">AP Balance (PKR)</th>
                    <th style="width:110px;">Status</th>
                    <th class="no-print" style="width:260px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($suppliers)): ?>
                <tr><td colspan="7" style="text-align:center; padding:30px; color:#94a3b8;">
                    No suppliers registered yet. Click <strong>Add Supplier</strong> to begin.
                </td></tr>
            <?php else: foreach ($suppliers as $s):
                $bal = (float)$s['ap_balance'];
                $balCls = abs($bal) < 0.01 ? 'bal-zero' : 'bal-pos';
                $isActive = (int)$s['status'] === 1;
                $phoneDisplay = $s['phone'] ?: $s['mobile'];
                $emailDisplay = $s['email'] ?: $s['contact'];
            ?>
                <tr class="<?= $isActive ? '' : 'inactive-row' ?>"
                    data-active="<?= $isActive ? 1 : 0 ?>"
                    data-search="<?= htmlspecialchars(strtolower($s['name'].' '.$phoneDisplay.' '.$emailDisplay)) ?>">
                    <td><?= (int)$s['id'] ?></td>
                    <td><strong><?= htmlspecialchars($s['name']) ?></strong></td>
                    <td><?= htmlspecialchars($phoneDisplay) ?></td>
                    <td><?= htmlspecialchars($emailDisplay) ?></td>
                    <td class="num <?= $balCls ?>"><?= number_format($bal, 2) ?></td>
                    <td>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Toggle active status?');">
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <button type="submit" class="status-toggle <?= $isActive ? 'on' : 'off' ?>" title="Click to toggle">
                                <?= $isActive ? '● Active' : '○ Inactive' ?>
                            </button>
                        </form>
                    </td>
                    <td class="no-print">
                        <a href="gl_party_ledger.php?id=<?= (int)$s['id'] ?>" class="row-btn row-btn-info">
                            <span class="glyphicon glyphicon-list-alt"></span> Ledger
                        </a>
                        <a href="gl_supplier_statement.php?id=<?= (int)$s['id'] ?>" class="row-btn row-btn-stmt">
                            <span class="glyphicon glyphicon-file"></span> Statement
                        </a>
                        <button type="button" class="row-btn row-btn-edit" onclick='openEdit(<?= json_encode([
                            "id"      => (int)$s["id"],
                            "name"    => $s["name"],
                            "phone"   => $s["phone"],
                            "email"   => $s["email"],
                            "address" => $s["address"],
                            "mobile"  => $s["mobile"],
                            "contact" => $s["contact"],
                            "tax_id"  => $s["tax_id"],
                            "status"  => (int)$s["status"],
                        ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                            <span class="glyphicon glyphicon-pencil"></span> Edit
                        </button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

    </div>
</div>

<!-- Top-drop panel backdrop -->
<div class="panel-backdrop" id="panelBackdrop" onclick="closePanel()"></div>

<!-- Top-drop panel -->
<div class="panel" id="supplierPanel" role="dialog" aria-modal="true">
    <div class="panel-head">
        <div>
            <h3 id="panelTitle">Add Supplier</h3>
            <div class="sub">All fields except Name are optional</div>
        </div>
        <button type="button" class="panel-close" onclick="closePanel()" aria-label="Close">&times;</button>
    </div>

    <form method="post" id="supForm" style="display:flex; flex-direction:column; flex:1; min-height:0;">
        <div class="panel-body">
            <input type="hidden" name="action" id="f_action" value="add">
            <input type="hidden" name="id" id="f_id" value="0">

            <div class="form-grid">
                <div class="field span-3">
                    <label>Name <span class="required">*</span></label>
                    <input type="text" name="name" id="f_name" required maxlength="150" placeholder="e.g. Ahmed Traders">
                </div>

                <div class="field">
                    <label>Phone</label>
                    <input type="text" name="phone" id="f_phone" maxlength="50" placeholder="0300-1234567">
                </div>
                <div class="field">
                    <label>Mobile</label>
                    <input type="text" name="mobile" id="f_mobile" maxlength="20" placeholder="Optional">
                </div>
                <div class="field">
                    <label>Email</label>
                    <input type="email" name="email" id="f_email" maxlength="150" placeholder="sales@supplier.com">
                </div>

                <div class="field">
                    <label>Contact Person</label>
                    <input type="text" name="contact" id="f_contact" maxlength="100" placeholder="Who you deal with">
                </div>
                <div class="field">
                    <label>Tax ID / NTN</label>
                    <input type="text" name="tax_id" id="f_tax_id" maxlength="50" placeholder="Optional">
                </div>
                <div class="field" style="display:flex; align-items:flex-end;">
                    <!-- spacer to keep grid alignment -->
                </div>

                <div class="field span-3" style="margin-bottom:6px;">
                    <label>Address</label>
                    <textarea name="address" id="f_address" rows="2" placeholder="Optional"></textarea>
                </div>
            </div>

            <div class="hint">
                <strong>Opening balance?</strong> Post it as a journal entry
                (Dr/Cr <code>2010 Accounts Payable</code> with this supplier selected),
                not here.
            </div>
        </div>

        <div class="panel-foot">
            <button type="button" class="btn-ghost-custom" onclick="closePanel()">Cancel</button>
            <button type="submit" class="btn-primary-custom">
                <span class="glyphicon glyphicon-ok"></span> Save Supplier
            </button>
        </div>
    </form>
</div>

<script>
/* ============ Top-drop Panel ============ */
var panel = document.getElementById('supplierPanel');
var backdrop = document.getElementById('panelBackdrop');

function openPanel() {
    panel.classList.add('open');
    backdrop.classList.add('open');
    document.body.style.overflow = 'hidden';
    setTimeout(function(){
        var first = panel.querySelector('input[name="name"]');
        if (first) first.focus();
    }, 300);
}
function closePanel() {
    panel.classList.remove('open');
    backdrop.classList.remove('open');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && panel.classList.contains('open')) closePanel();
});

/* ============ Add / Edit ============ */
function openEdit(d) {
    document.getElementById('panelTitle').textContent = 'Edit Supplier #' + d.id;
    document.getElementById('f_action').value  = 'edit';
    document.getElementById('f_id').value      = d.id;
    document.getElementById('f_name').value    = d.name    || '';
    document.getElementById('f_phone').value   = d.phone   || '';
    document.getElementById('f_email').value   = d.email   || '';
    document.getElementById('f_address').value = d.address || '';
    document.getElementById('f_mobile').value  = d.mobile  || '';
    document.getElementById('f_contact').value = d.contact || '';
    document.getElementById('f_tax_id').value  = d.tax_id  || '';
    openPanel();
}

document.getElementById('btnAdd').addEventListener('click', function(){
    document.getElementById('panelTitle').textContent = 'Add Supplier';
    document.getElementById('f_action').value = 'add';
    document.getElementById('f_id').value = '0';
    document.getElementById('supForm').reset();
    openPanel();
});

/* ============ Filters ============ */
function applyFilters() {
    var q = document.getElementById('searchBox').value.toLowerCase().trim();
    var showInactive = document.getElementById('showInactive').checked;
    document.querySelectorAll('#supTable tbody tr').forEach(function(tr){
        if (!tr.dataset.search) return;
        var matchesText = !q || tr.dataset.search.indexOf(q) !== -1;
        var matchesActive = showInactive || tr.dataset.active === '1';
        tr.style.display = (matchesText && matchesActive) ? '' : 'none';
    });
}
document.getElementById('searchBox').addEventListener('input', applyFilters);
document.getElementById('showInactive').addEventListener('change', applyFilters);

/* ============ CSV Export ============ */
function exportCSV() {
    var rows = [['ID','Name','Phone','Email','AP Balance','Status']];
    document.querySelectorAll('#supTable tbody tr').forEach(function(tr){
        if (tr.style.display === 'none' || !tr.dataset.search) return;
        var tds = tr.querySelectorAll('td');
        rows.push([tds[0].innerText.trim(),tds[1].innerText.trim(),tds[2].innerText.trim(),
                   tds[3].innerText.trim(),tds[4].innerText.trim(),tds[5].innerText.trim()]);
    });
    var csv = rows.map(r => r.map(c => '"' + c.replace(/"/g,'""') + '"').join(',')).join('\n');
    var blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'suppliers_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
}
</script>
</body>
</html>