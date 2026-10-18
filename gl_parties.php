<?php
require_once 'security.php';
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
require_once 'conn_inc.php';
require_once 'gl.php';

$conn->set_charset('utf8mb4');

$page_title = 'Suppliers';
$msg = ''; $msg_type = '';

/* Toggle status (only POST left on this page) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_status') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0 && !empty($perms['can_edit'])) {
        $stmt = $conn->prepare("UPDATE gl_parties SET status = IF(status = 1, 0, 1) WHERE id = ? AND party_type = 'SUPPLIER'");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $_SESSION['message'] = 'Status updated.';
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = 'Update failed: ' . $stmt->error;
            $_SESSION['message_type'] = 'danger';
        }
        $stmt->close();
    }
    header("Location: gl_parties.php");
    exit();
}

if (isset($_SESSION['message'])) {
    $msg = $_SESSION['message'];
    $msg_type = $_SESSION['message_type'] ?? 'info';
    unset($_SESSION['message'], $_SESSION['message_type']);
}

$suppliers = gl_list_suppliers($conn, true);
$recon = gl_ap_reconciliation($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Suppliers</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<style>
    body { background: #f5f7fa; font-family: 'Inter', sans-serif; color: #1e293b; padding-bottom: 40px; }
    .wrap { max-width: 1300px; margin: 24px auto; padding: 0 20px; }

    .head { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: #fff; padding: 20px 24px; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; }
    .head h1 { margin: 0; font-size: 19px; font-weight: 700; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .head .sub { font-size: 12px; opacity: 0.88; margin-top: 4px; }
    .head .actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .btn-head { padding: 8px 14px; border-radius: 7px; font-size: 12px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; border: 1px solid; cursor: pointer; font-family: inherit; }
    .btn-head.ghost { background: rgba(255,255,255,0.15); color: #fff; border-color: rgba(255,255,255,0.3); }
    .btn-head.ghost:hover { background: rgba(255,255,255,0.25); color: #fff; text-decoration: none; }
    .btn-head.solid { background: #fff; color: #1d4ed8; border-color: #fff; }
    .btn-head.solid:hover { background: #f1f5f9; color: #1d4ed8; text-decoration: none; }

    .recon-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 10px; font-size: 9.5px; font-weight: 700; letter-spacing: 0.3px; text-transform: uppercase; }
    .recon-badge.ok { background: rgba(255,255,255,0.25); color: #fff; }
    .recon-badge.bad { background: #dc2626; color: #fff; }

    .content { background: #fff; padding: 16px 20px 20px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

    .alert-custom { padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; font-size: 12.5px; border-left: 4px solid; }
    .alert-custom.success { background: #d1fae5; border-color: #059669; color: #065f46; }
    .alert-custom.danger  { background: #fee2e2; border-color: #dc2626; color: #991b1b; }
    .alert-custom.warn    { background: #fef3c7; border-color: #d97706; color: #92400e; }

    .toolbar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #e2e8f0; }
    .toolbar .search-box { position: relative; flex: 1; min-width: 220px; max-width: 320px; }
    .toolbar .search-box i { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 11px; }
    .toolbar .search-box input { width: 100%; height: 34px; padding: 0 12px 0 30px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 12.5px; font-family: inherit; background: #fff; }
    .toolbar .search-box input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.12); }

    .toolbar .check { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: #475569; cursor: pointer; padding: 0 4px; margin: 0; }
    .toolbar .check input { margin: 0; }

    .btn-tool { height: 34px; padding: 0 12px; border-radius: 7px; font-size: 12px; font-weight: 600; border: 1px solid; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; font-family: inherit; white-space: nowrap; }
    .btn-tool.ghost { background: #fff; color: #475569; border-color: #cbd5e1; }
    .btn-tool.ghost:hover { background: #f8fafc; color: #1e293b; text-decoration: none; border-color: #94a3b8; }

    .toolbar .count-inline { margin-left: auto; font-size: 11.5px; color: #64748b; font-weight: 600; }

    table.tbl { width: 100%; border-collapse: separate; border-spacing: 0; background: #fff; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; }
    table.tbl thead th { background: #f8fafc; font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; padding: 9px 12px; text-align: left; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
    table.tbl thead th.num { text-align: right; }
    table.tbl tbody td { padding: 9px 12px; font-size: 12.5px; color: #1e293b; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    table.tbl tbody tr:last-child td { border-bottom: none; }
    table.tbl tbody tr:hover td { background: #fafbfc; }

    .id-cell { font-family: 'SF Mono', monospace; font-size: 11px; color: #94a3b8; width: 50px; }
    .name-cell { font-weight: 700; color: #0f172a; }
    .name-cell small { display: block; font-weight: 500; font-size: 10.5px; color: #94a3b8; margin-top: 1px; }

    table.tbl .num { font-family: 'SF Mono', monospace; font-variant-numeric: tabular-nums; text-align: right; font-weight: 600; }
    .bal-pos { color: #b91c1c; font-weight: 700; }
    .bal-zero { color: #cbd5e1; font-weight: 500; }
    .inactive-row { opacity: 0.55; }

    .status-toggle { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 10px; font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; cursor: pointer; font-family: inherit; border: 1px solid transparent; transition: all 0.15s; }
    .status-toggle.on  { background: #d1fae5; color: #065f46; border-color: #a7f3d0; }
    .status-toggle.on:hover  { background: #a7f3d0; }
    .status-toggle.off { background: #f1f5f9; color: #64748b; border-color: #e2e8f0; }
    .status-toggle.off:hover { background: #e2e8f0; }

    .row-btn { display: inline-flex; align-items: center; gap: 3px; padding: 3px 8px; border-radius: 5px; font-size: 10.5px; font-weight: 600; text-decoration: none; cursor: pointer; font-family: inherit; border: 1px solid; transition: all 0.15s; white-space: nowrap; }
    .row-btn-info { background: #eff6ff; color: #1e40af; border-color: #bfdbfe; }
    .row-btn-info:hover { background: #dbeafe; color: #1e40af; text-decoration: none; }
    .row-btn-stmt { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
    .row-btn-stmt:hover { background: #d1fae5; color: #065f46; text-decoration: none; }
    .row-btn-edit { background: #fef3c7; color: #92400e; border-color: #fde68a; }
    .row-btn-edit:hover { background: #fde68a; color: #92400e; text-decoration: none; }

    .empty { padding: 40px 20px; text-align: center; color: #94a3b8; font-size: 12.5px; font-style: italic; }
    .empty i { display: block; font-size: 32px; opacity: 0.4; margin-bottom: 8px; }

    @media (max-width: 900px) {
        table.tbl thead { display: none; }
        table.tbl, table.tbl tbody, table.tbl tr, table.tbl td { display: block; width: 100%; }
        table.tbl tr { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; }
        table.tbl td { padding: 4px 0; border: none; display: flex; justify-content: space-between; gap: 10px; }
        table.tbl td::before { content: attr(data-label); font-weight: 700; font-size: 10px; text-transform: uppercase; color: #64748b; letter-spacing: 0.4px; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <h1>
                <i class="fas fa-users"></i> Suppliers
                <?php if ($recon['ok']): ?>
                    <span class="recon-badge ok"><i class="fas fa-check"></i> Reconciled</span>
                <?php else: ?>
                    <span class="recon-badge bad"><i class="fas fa-exclamation-triangle"></i> Broken <?= number_format($recon['diff'], 2) ?></span>
                <?php endif; ?>
            </h1>
            <div class="sub">Register suppliers and trace what the school owes them</div>
        </div>
        <div class="actions">
            <a href="gl_ap_aging.php" class="btn-head ghost"><i class="fas fa-hourglass-half"></i> AP Aging</a>
            <?php if (!empty($perms['can_add'])): ?>
                <a href="gl_supplier_add.php" class="btn-head solid"><i class="fas fa-plus"></i> Add Supplier</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="content">

        <?php if ($msg): ?>
            <div class="alert-custom <?= htmlspecialchars($msg_type) ?>"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <?php if (!$recon['ok']): ?>
            <div class="alert-custom danger">
                <strong>Sub-ledger broken.</strong>
                GL 2010 total = <?= number_format($recon['gl'], 2) ?>, supplier balances = <?= number_format($recon['sub'], 2) ?> (diff <?= number_format($recon['diff'], 2) ?>). Some AP journal lines have no party_id.
            </div>
        <?php endif; ?>

        <div class="toolbar">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchBox" placeholder="Search name / phone / email..." autocomplete="off">
            </div>
            <label class="check">
                <input type="checkbox" id="showInactive"> Show inactive
            </label>
            <button type="button" class="btn-tool ghost" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            <button type="button" class="btn-tool ghost" onclick="exportCSV()"><i class="fas fa-file-csv"></i> CSV</button>
            <button type="button" class="btn-tool ghost" onclick="exportXLSX()"><i class="fas fa-file-excel"></i> Excel</button>
            <div class="count-inline" id="countInline"><?= count($suppliers) ?> supplier<?= count($suppliers) === 1 ? '' : 's' ?></div>
        </div>

        <table class="tbl" id="supTable">
            <thead>
                <tr>
                    <th style="width:50px;">ID</th>
                    <th>Name</th>
                    <th style="width:140px;">Phone</th>
                    <th>Email</th>
                    <th class="num" style="width:140px;">AP Balance</th>
                    <th style="width:100px;">Status</th>
                    <th style="width:220px; text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($suppliers)): ?>
                <tr><td colspan="7"><div class="empty"><i class="fas fa-users"></i>No suppliers registered yet.</div></td></tr>
            <?php else: foreach ($suppliers as $s):
                $bal = (float)$s['ap_balance'];
                $balCls = abs($bal) < 0.01 ? 'bal-zero' : 'bal-pos';
                $isActive = (int)$s['status'] === 1;
                $phoneDisplay = $s['phone'] ?: $s['mobile'];
                $emailDisplay = $s['email'] ?: $s['contact'];
            ?>
                <tr class="<?= $isActive ? '' : 'inactive-row' ?>"
                    data-active="<?= $isActive ? 1 : 0 ?>"
                    data-name="<?= htmlspecialchars(strtolower($s['name'])) ?>"
                    data-phone="<?= htmlspecialchars(strtolower($phoneDisplay)) ?>"
                    data-email="<?= htmlspecialchars(strtolower($emailDisplay)) ?>">
                    <td class="id-cell" data-label="ID">#<?= (int)$s['id'] ?></td>
                    <td class="name-cell" data-label="Name">
                        <?= htmlspecialchars($s['name']) ?>
                        <?php if (!empty($s['contact'])): ?><small><?= htmlspecialchars($s['contact']) ?></small><?php endif; ?>
                    </td>
                    <td data-label="Phone"><?= htmlspecialchars($phoneDisplay ?: '—') ?></td>
                    <td data-label="Email"><?= htmlspecialchars($emailDisplay ?: '—') ?></td>
                    <td class="num <?= $balCls ?>" data-label="AP Balance"><?= abs($bal) < 0.01 ? '—' : number_format($bal, 2) ?></td>
                    <td data-label="Status">
                        <?php if (!empty($perms['can_edit'])): ?>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Toggle status?');">
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button type="submit" class="status-toggle <?= $isActive ? 'on' : 'off' ?>">
                                    <?= $isActive ? '<i class="fas fa-circle" style="font-size:5px;"></i> Active' : '<i class="far fa-circle" style="font-size:5px;"></i> Inactive' ?>
                                </button>
                            </form>
                        <?php else: ?>
                            <span class="status-toggle <?= $isActive ? 'on' : 'off' ?>"><?= $isActive ? 'Active' : 'Inactive' ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Actions" style="text-align:right; white-space:nowrap;">
                        <a href="gl_party_ledger.php?id=<?= (int)$s['id'] ?>" class="row-btn row-btn-info"><i class="fas fa-list-alt"></i> Ledger</a>
                        <a href="gl_supplier_statement.php?id=<?= (int)$s['id'] ?>" class="row-btn row-btn-stmt"><i class="fas fa-file-alt"></i> Statement</a>
                        <?php if (!empty($perms['can_edit'])): ?>
                            <a href="gl_supplier_add.php?id=<?= (int)$s['id'] ?>" class="row-btn row-btn-edit"><i class="fas fa-pen"></i> Edit</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

    </div>
</div>

<script>
function applyFilters() {
    var q = document.getElementById('searchBox').value.toLowerCase().trim();
    var showInactive = document.getElementById('showInactive').checked;
    var visible = 0, total = 0;
    document.querySelectorAll('#supTable tbody tr').forEach(function(tr){
        if (!tr.dataset.name) return;
        total++;
        var matchesText = !q
            || tr.dataset.name.indexOf(q) !== -1
            || tr.dataset.phone.indexOf(q) !== -1
            || tr.dataset.email.indexOf(q) !== -1;
        var matchesActive = showInactive || tr.dataset.active === '1';
        var ok = matchesText && matchesActive;
        tr.style.display = ok ? '' : 'none';
        if (ok) visible++;
    });
    var cnt = document.getElementById('countInline');
    if (cnt) cnt.textContent = visible + ' supplier' + (visible === 1 ? '' : 's');
}

document.getElementById('searchBox').addEventListener('input', applyFilters);
document.getElementById('showInactive').addEventListener('change', applyFilters);

/* ───── CSV ───── */
function buildRows() {
    var rows = [['ID', 'Name', 'Phone', 'Email', 'AP Balance', 'Status']];
    document.querySelectorAll('#supTable tbody tr').forEach(function(tr){
        if (tr.style.display === 'none' || !tr.dataset.name) return;
        var tds = tr.querySelectorAll('td');
        rows.push([
            tds[0].innerText.trim(),
            tds[1].innerText.trim(),
            tds[2].innerText.trim(),
            tds[3].innerText.trim(),
            tds[4].innerText.trim(),
            tds[5].innerText.trim()
        ]);
    });
    return rows;
}

function exportCSV() {
    var rows = buildRows();
    var csv = rows.map(function(r){
        return r.map(function(c){
            var v = String(c);
            if (v.indexOf(',') !== -1 || v.indexOf('"') !== -1 || v.indexOf('\n') !== -1) {
                v = '"' + v.replace(/"/g, '""') + '"';
            }
            return v;
        }).join(',');
    }).join('\n');
    var blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'suppliers_' + new Date().toISOString().slice(0, 10) + '.csv';
    a.click();
}

/* ───── Excel (SheetJS) ───── */
function exportXLSX() {
    var rows = buildRows();
    var ws = XLSX.utils.aoa_to_sheet(rows);
    var cols = [{wch:6},{wch:30},{wch:18},{wch:26},{wch:14},{wch:12}];
    ws['!cols'] = cols;
    var wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Suppliers');
    XLSX.writeFile(wb, 'suppliers_' + new Date().toISOString().slice(0, 10) + '.xlsx');
}
</script>

</body>
</html>