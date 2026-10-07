<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');
require_once('VoucherService.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

$service = new VoucherService($conn);

// --- Filters ---
$fType    = $_GET['type']    ?? '';
$fStatus  = $_GET['status']  ?? '';
$fFrom    = $_GET['from']    ?? date('Y-m-01');
$fTo      = $_GET['to']      ?? date('Y-m-d');
$fSession = $_GET['session'] ?? '';
$fSearch  = trim($_GET['search'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));

$result = $service->listVouchers([
    'voucher_type' => $fType,
    'status'       => $fStatus,
    'from_date'    => $fFrom,
    'to_date'      => $fTo,
    'session_id'   => $fSession,
    'search'       => $fSearch,
    'page'         => $page,
    'per_page'     => 50,
]);

$vouchers    = $result['rows'];
$total       = $result['total'];
$totalPages  = $result['total_pages'];

// Sessions
$sessions = $conn->query("SELECT id, title FROM sessions ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);

// Page total
$pageTotal = 0.0;
foreach ($vouchers as $v) {
    if ($v['status'] === 'POSTED') $pageTotal += (float)$v['amount'];
}

// Voucher type meta
$typeMeta = [
    'CPV' => ['label' => 'Cash Payment',  'icon' => 'glyphicon-arrow-up',   'cls' => 'cpv'],
    'CRV' => ['label' => 'Cash Receipt',  'icon' => 'glyphicon-arrow-down', 'cls' => 'crv'],
    'BPV' => ['label' => 'Bank Payment',  'icon' => 'glyphicon-upload',     'cls' => 'bpv'],
    'BRV' => ['label' => 'Bank Receipt',  'icon' => 'glyphicon-download',   'cls' => 'brv'],
];

$statusMeta = [
    'DRAFT'     => ['label' => 'Draft',     'cls' => 'draft'],
    'POSTED'    => ['label' => 'Posted',    'cls' => 'posted'],
    'CANCELLED' => ['label' => 'Cancelled', 'cls' => 'cancelled'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    
    <title>Vouchers</title>
    <style>
        * { box-sizing: border-box; }
        body {
            background: #eef1f5;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0; padding: 0; color: #1e293b;
        }
        .wrap { max-width: 1400px; margin: 30px auto; padding: 0 20px; }

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
            background: #fff; color: #1e40af;
            border: 1px solid #fff;
            border-radius: 8px; text-decoration: none;
            font-size: 14px; font-weight: 600;
            transition: all 0.15s;
        }
        .btn-new:hover { background: rgba(255, 255, 255, 0.9); text-decoration: none; }

        .toolbar {
            background: #fff;
            padding: 22px 40px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            margin-bottom: 24px;
        }
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
            margin-bottom: 14px;
        }
        .filter-group { display: flex; flex-direction: column; gap: 6px; }
        .filter-group label {
            font-size: 11px; font-weight: 700; color: #64748b;
            text-transform: uppercase; letter-spacing: 1px; margin: 0;
        }
        .filter-group input,
        .filter-group select {
            height: 40px; padding: 8px 12px;
            border: 1px solid #cbd5e1; border-radius: 8px;
            font-size: 14px; background: #fff; color: #0f172a;
        }
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none; border-color: #1e40af;
            box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
        }
        .toolbar-actions {
            display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap;
        }
        .btn-tool {
            height: 40px; padding: 0 18px; border-radius: 8px;
            font-size: 13px; font-weight: 600; border: 1px solid;
            cursor: pointer; display: inline-flex; align-items: center;
            gap: 7px; text-decoration: none; transition: all 0.15s;
            white-space: nowrap;
        }
        .btn-tool.primary { background: #1e40af; color: #fff; border-color: #1e40af; }
        .btn-tool.primary:hover { background: #1e3a8a; text-decoration: none; }
        .btn-tool.ghost { background: #fff; color: #475569; border-color: #cbd5e1; }
        .btn-tool.ghost:hover { background: #f8fafc; color: #1e293b; text-decoration: none; }

        .table-card {
            background: #fff; border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            overflow: hidden; margin-bottom: 24px;
        }
        .table-header-bar {
            padding: 16px 26px; background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex; justify-content: space-between;
            align-items: center; flex-wrap: wrap; gap: 12px;
        }
        .table-header-bar .title {
            font-size: 14px; font-weight: 700; color: #1e293b;
            text-transform: uppercase; letter-spacing: 1.2px;
        }
        .table-header-bar .count {
            font-size: 13px; color: #64748b; font-weight: 500;
        }

        .v-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .v-table thead th {
            padding: 14px 16px; text-align: left;
            font-size: 11px; font-weight: 700; color: #64748b;
            text-transform: uppercase; letter-spacing: 1px;
            background: #f1f5f9; border-bottom: 2px solid #cbd5e1;
            white-space: nowrap;
        }
        .v-table thead th.amount-col { text-align: right; }
        .v-table thead th.action-col { text-align: center; width: 100px; }

        .v-table tbody tr { border-bottom: 1px solid #f1f5f9; transition: background 0.15s; }
        .v-table tbody tr:hover { background: #f8fafc; }
        .v-table tbody tr.cancelled-row { background: #fef5f5; opacity: 0.7; }
        .v-table tbody td { padding: 14px 16px; color: #1e293b; vertical-align: middle; }

        .v-num {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 13px; font-weight: 700; color: #1e40af;
            white-space: nowrap;
        }
        .v-type-pill {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 10px; border-radius: 12px;
            font-size: 10px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.5px; color: #fff;
        }
        .v-type-pill.cpv { background: #dc2626; }
        .v-type-pill.crv { background: #047857; }
        .v-type-pill.bpv { background: #c2410c; }
        .v-type-pill.brv { background: #1e40af; }

        .v-date { font-family: 'SF Mono', 'Monaco', monospace; font-size: 12px; color: #475569; white-space: nowrap; }
        .v-party { font-weight: 600; color: #0f172a; }
        .v-account { font-size: 13px; color: #64748b; margin-top: 2px; }
        .v-narration { font-size: 12px; color: #64748b; margin-top: 2px; font-style: italic; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        .v-amount {
            text-align: right;
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 15px; font-weight: 700;
            font-variant-numeric: tabular-nums;
            white-space: nowrap; color: #047857;
        }
        .v-amount.payment { color: #b91c1c; }

        .v-status {
            display: inline-block; padding: 4px 10px;
            border-radius: 10px; font-size: 10px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .v-status.draft     { background: #fef3c7; color: #92400e; }
        .v-status.posted    { background: #d1fae5; color: #065f46; }
        .v-status.cancelled { background: #fee2e2; color: #991b1b; }

        .v-action {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 6px 12px; border-radius: 6px;
            background: #eff6ff; color: #1e40af;
            text-decoration: none; border: 1px solid #bfdbfe;
            font-size: 12px; font-weight: 600; white-space: nowrap;
        }
        .v-action:hover { background: #1e40af; color: #fff; text-decoration: none; }

        .v-table tfoot td {
            padding: 16px; background: #f0f5ff;
            font-weight: 700; font-size: 15px;
            border-top: 2px solid #c9d7ef;
            border-bottom: 2px solid #c9d7ef;
        }

        .pagination-bar {
            padding: 20px 26px; background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex; justify-content: space-between;
            align-items: center; flex-wrap: wrap; gap: 14px;
        }
        .pagination-info { font-size: 13px; color: #64748b; }
        .pagination-controls { display: flex; gap: 4px; flex-wrap: wrap; }
        .page-btn {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 36px; height: 36px; padding: 0 10px;
            border-radius: 6px; border: 1px solid #cbd5e1;
            background: #fff; color: #475569;
            font-size: 13px; font-weight: 600;
            text-decoration: none; transition: all 0.15s;
            font-family: 'SF Mono', 'Monaco', monospace;
        }
        .page-btn:hover { background: #f1f5f9; color: #1e293b; text-decoration: none; }
        .page-btn.active { background: #1e40af; color: #fff; border-color: #1e40af; }
        .page-btn.disabled { opacity: 0.4; pointer-events: none; }

        .empty-state { padding: 60px 30px; text-align: center; color: #94a3b8; font-size: 15px; font-style: italic; }
        .empty-state .big-icon { font-size: 56px; color: #cbd5e1; display: block; margin-bottom: 16px; }
        .empty-state .hint { font-size: 13px; margin-top: 10px; color: #cbd5e1; font-style: normal; }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <h1>Vouchers</h1>
            <div class="sub">
                <?php echo number_format($total); ?> vouchers
                &middot;
                <?php echo date('M j, Y', strtotime($fFrom)); ?>
                through <?php echo date('M j, Y', strtotime($fTo)); ?>
            </div>
        </div>
        <a href="gl_voucher_add.php" class="btn-new">
            <span class="glyphicon glyphicon-plus"></span> New Voucher
        </a>
    </div>

    <!-- Filter toolbar -->
    <div class="toolbar">
        <form method="get" action="">
            <div class="filter-grid">
                <div class="filter-group">
                    <label for="type">Voucher Type</label>
                    <select id="type" name="type">
                        <option value="">All Types</option>
                        <?php foreach ($typeMeta as $code => $meta): ?>
                            <option value="<?php echo $code; ?>"
                                <?php echo ($fType === $code) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($meta['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">All</option>
                        <option value="DRAFT"     <?php echo ($fStatus === 'DRAFT')     ? 'selected' : ''; ?>>Draft</option>
                        <option value="POSTED"    <?php echo ($fStatus === 'POSTED')    ? 'selected' : ''; ?>>Posted</option>
                        <option value="CANCELLED" <?php echo ($fStatus === 'CANCELLED') ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="session">Session</label>
                    <select id="session" name="session">
                        <option value="">All Sessions</option>
                        <?php foreach ($sessions as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>"
                                <?php echo ((string)$fSession === (string)$s['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="from">From</label>
                    <input type="date" id="from" name="from" value="<?php echo htmlspecialchars($fFrom); ?>">
                </div>
                <div class="filter-group">
                    <label for="to">To</label>
                    <input type="date" id="to" name="to" value="<?php echo htmlspecialchars($fTo); ?>">
                </div>
                <div class="filter-group">
                    <label for="search">Search</label>
                    <input type="text" id="search" name="search"
                           placeholder="Number, party, narration..."
                           value="<?php echo htmlspecialchars($fSearch); ?>">
                </div>
            </div>
            <div class="toolbar-actions">
                <a href="gl_vouchers.php" class="btn-tool ghost">
                    <span class="glyphicon glyphicon-repeat"></span> Reset
                </a>
                <button type="submit" class="btn-tool primary">
                    <span class="glyphicon glyphicon-filter"></span> Apply
                </button>
            </div>
        </form>
    </div>

    <!-- Table -->
    <?php if (empty($vouchers)): ?>
        <div class="table-card">
            <div class="empty-state">
                <span class="glyphicon glyphicon-file big-icon"></span>
                No vouchers found.
                <div class="hint">
                    Try changing the date range or <a href="gl_voucher_add.php" style="color: #1e40af;">create a new voucher</a>.
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="table-card">
            <div class="table-header-bar">
                <div class="title">Voucher List</div>
                <div class="count">
                    Showing <strong><?php echo count($vouchers); ?></strong> of
                    <strong><?php echo number_format($total); ?></strong>
                </div>
            </div>

            <div style="overflow-x: auto;">
                <table class="v-table">
                    <thead>
                        <tr>
                            <th style="width: 150px;">Number</th>
                            <th style="width: 110px;">Date</th>
                            <th style="width: 130px;">Type</th>
                            <th>Party</th>
                            <th class="amount-col">Amount</th>
                            <th style="width: 110px;">Status</th>
                            <th class="action-col">View</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($vouchers as $v):
                            $typeCls = strtolower($v['voucher_type']);
                            $isPayment = in_array($v['voucher_type'], ['CPV', 'BPV'], true);
                            $status = $statusMeta[$v['status']] ?? ['label'=>$v['status'], 'cls'=>'draft'];
                        ?>
                            <tr class="<?php echo $v['status'] === 'CANCELLED' ? 'cancelled-row' : ''; ?>">
                                <td><span class="v-num"><?php echo htmlspecialchars($v['voucher_number']); ?></span></td>
                                <td class="v-date"><?php echo htmlspecialchars($v['entry_date']); ?></td>
                                <td>
                                    <span class="v-type-pill <?php echo $typeCls; ?>">
                                        <span class="glyphicon <?php echo $typeMeta[$v['voucher_type']]['icon']; ?>"></span>
                                        <?php echo htmlspecialchars($typeMeta[$v['voucher_type']]['label']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="v-party"><?php echo htmlspecialchars($v['party_name']); ?></div>
                                    <?php if (!empty($v['account_name'])): ?>
                                        <div class="v-account">
                                            <?php echo htmlspecialchars($v['account_code'] . ' — ' . $v['account_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($v['narration'])): ?>
                                        <div class="v-narration" title="<?php echo htmlspecialchars($v['narration']); ?>">
                                            <?php echo htmlspecialchars($v['narration']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="v-amount <?php echo $isPayment ? 'payment' : ''; ?>">
                                    <?php echo ($isPayment ? '− ' : '+ ') . number_format((float)$v['amount'], 2); ?>
                                </td>
                                <td><span class="v-status <?php echo $status['cls']; ?>"><?php echo $status['label']; ?></span></td>
                                <td style="text-align: center;">
                                    <a href="gl_voucher_view.php?id=<?php echo (int)$v['id']; ?>"
                                       class="v-action">
                                        <span class="glyphicon glyphicon-eye-open"></span> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" style="text-align: right;">Posted on this page:</td>
                            <td class="v-amount" style="color: #1e40af;"><?php echo number_format($pageTotal, 2); ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="pagination-bar">
                    <div class="pagination-info">
                        Page <strong><?php echo $page; ?></strong> of <strong><?php echo $totalPages; ?></strong>
                        &middot; <?php echo number_format($total); ?> total
                    </div>
                    <div class="pagination-controls">
                        <?php
                        $qs = $_GET;
                        $buildUrl = function($p) use ($qs) {
                            $qs['page'] = $p;
                            return 'gl_vouchers.php?' . http_build_query($qs);
                        };
                        $start = max(1, $page - 3);
                        $end   = min($totalPages, $page + 3);
                        ?>
                        <a href="<?php echo $page > 1 ? htmlspecialchars($buildUrl($page - 1)) : '#'; ?>"
                           class="page-btn <?php echo $page <= 1 ? 'disabled' : ''; ?>">&laquo;</a>
                        <?php if ($start > 1): ?>
                            <a href="<?php echo htmlspecialchars($buildUrl(1)); ?>" class="page-btn">1</a>
                            <?php if ($start > 2): ?><span class="page-btn disabled">…</span><?php endif; ?>
                        <?php endif; ?>
                        <?php for ($p = $start; $p <= $end; $p++): ?>
                            <a href="<?php echo htmlspecialchars($buildUrl($p)); ?>"
                               class="page-btn <?php echo $p === $page ? 'active' : ''; ?>"><?php echo $p; ?></a>
                        <?php endfor; ?>
                        <?php if ($end < $totalPages): ?>
                            <?php if ($end < $totalPages - 1): ?><span class="page-btn disabled">…</span><?php endif; ?>
                            <a href="<?php echo htmlspecialchars($buildUrl($totalPages)); ?>" class="page-btn"><?php echo $totalPages; ?></a>
                        <?php endif; ?>
                        <a href="<?php echo $page < $totalPages ? htmlspecialchars($buildUrl($page + 1)) : '#'; ?>"
                           class="page-btn <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">&raquo;</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
</body>
</html>
<?php $conn->close(); ?>