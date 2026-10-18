<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");

/* ───────── Filters ───────── */
$filterFrom    = $_GET['from']     ?? date('Y-m-01');
$filterTo      = $_GET['to']       ?? date('Y-m-d');
$filterSession = $_GET['session']  ?? '';
$filterRefType = $_GET['ref_type'] ?? '';
$filterStatus  = $_GET['status']   ?? '';
$filterSearch  = trim($_GET['search'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$perPage       = 100;
$offset        = ($page - 1) * $perPage;

$where  = " WHERE t.entry_date BETWEEN ? AND ? ";
$params = [$filterFrom, $filterTo];
$types  = "ss";

if ($filterSession !== '' && ctype_digit((string)$filterSession)) {
    $where .= " AND t.session_id = ? ";
    $params[] = (int)$filterSession;
    $types .= "i";
}
if ($filterRefType !== '') {
    $where .= " AND t.reference_type = ? ";
    $params[] = $filterRefType;
    $types .= "s";
}
if ($filterStatus === 'POSTED' || $filterStatus === 'REVERSED') {
    $where .= " AND t.status = ? ";
    $params[] = $filterStatus;
    $types .= "s";
}
if ($filterSearch !== '') {
    $where .= " AND (t.description LIKE ? OR EXISTS (SELECT 1 FROM gl_journal_lines l2 WHERE l2.transaction_id = t.id AND l2.memo LIKE ?)) ";
    $like = '%' . $filterSearch . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}

/* Count */
$countSql = "SELECT COUNT(*) AS cnt FROM gl_transactions t" . $where;
$countStmt = $conn->prepare($countSql);
$countStmt->bind_param($types, ...$params);
$countStmt->execute();
$totalRows = (int)$countStmt->get_result()->fetch_assoc()['cnt'];
$countStmt->close();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

/* List */
$listSql = "SELECT t.id, t.entry_date, t.session_id, t.description, t.reference_type, t.reference_id, t.posted_by, t.posted_at, t.status, t.reversal_of, u.username AS posted_by_name, s.title AS session_title, (SELECT COALESCE(SUM(l.debit), 0) FROM gl_journal_lines l WHERE l.transaction_id = t.id) AS total_debit FROM gl_transactions t LEFT JOIN users u ON u.id = t.posted_by LEFT JOIN sessions s ON s.id = t.session_id " . $where . " ORDER BY t.entry_date DESC, t.id DESC LIMIT ? OFFSET ?";
$listParams = array_merge($params, [$perPage, $offset]);
$listTypes  = $types . "ii";
$listStmt = $conn->prepare($listSql);
$listStmt->bind_param($listTypes, ...$listParams);
$listStmt->execute();
$transactions = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$listStmt->close();

$pageDebit = 0.0;
foreach ($transactions as $t) $pageDebit += (float)$t['total_debit'];

/* Dropdowns */
$sessions = [];
$r = $conn->query("SELECT id, title FROM sessions ORDER BY id DESC");
if ($r) { while ($row = $r->fetch_assoc()) $sessions[] = $row; }

$refTypes = [];
$r = $conn->query("SELECT DISTINCT reference_type FROM gl_transactions WHERE reference_type IS NOT NULL AND reference_type <> '' ORDER BY reference_type");
if ($r) { while ($row = $r->fetch_assoc()) $refTypes[] = $row; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Journal Entries</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    body { background: #f5f7fa; font-family: 'Inter', sans-serif; color: #1e293b; padding-bottom: 40px; }
    .wrap { max-width: 1300px; margin: 24px auto; padding: 0 20px; }

    /* Header */
    .head { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: #fff; padding: 20px 24px; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; }
    .head h1 { margin: 0; font-size: 19px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .head .sub { font-size: 12px; opacity: 0.88; margin-top: 4px; }
    .btn-new { padding: 8px 14px; border-radius: 7px; font-size: 12px; font-weight: 700; background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.3); text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
    .btn-new:hover { background: #fff; color: #1d4ed8; border-color: #fff; text-decoration: none; }

    /* Toolbar */
    .toolbar { background: #fff; border-radius: 0 0 12px 12px; padding: 16px 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); margin-bottom: 16px; }
    .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 12px; }
    .filter-group label { font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 4px; display: block; }
    .filter-group input, .filter-group select { width: 100%; height: 34px; padding: 5px 10px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 12.5px; font-family: inherit; background: #fff; color: #0f172a; }
    .filter-group input:focus, .filter-group select:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.12); }

    .toolbar-actions { display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; }
    .btn-tool { height: 34px; padding: 0 14px; border-radius: 7px; font-size: 12.5px; font-weight: 600; border: 1px solid; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; font-family: inherit; white-space: nowrap; }
    .btn-tool.primary { background: #2563eb; color: #fff; border-color: #2563eb; }
    .btn-tool.primary:hover { background: #1d4ed8; color: #fff; text-decoration: none; border-color: #1d4ed8; }
    .btn-tool.ghost { background: #fff; color: #475569; border-color: #cbd5e1; }
    .btn-tool.ghost:hover { background: #f8fafc; color: #1e293b; text-decoration: none; border-color: #94a3b8; }

    /* Table */
    .table-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); overflow: hidden; margin-bottom: 16px; }
    .table-head { padding: 12px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
    .table-head .title { font-size: 12.5px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 6px; }
    .table-head .title i { color: #2563eb; }
    .table-head .count { font-size: 11.5px; color: #64748b; font-weight: 500; }
    .table-head .count strong { color: #1e293b; }

    table.entries { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 12.5px; }
    table.entries thead th { padding: 9px 14px; text-align: left; font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.6px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
    table.entries thead th.amount-col { text-align: right; }
    table.entries thead th.action-col { text-align: right; }
    table.entries tbody td { padding: 9px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    table.entries tbody tr { transition: background 0.15s; }
    table.entries tbody tr:hover td { background: #fafbfc; }
    table.entries tbody tr.reversed-row td { background: #fef9f9; }
    table.entries tbody tr.reversed-row:hover td { background: #fef2f2; }
    table.entries tbody tr:last-child td { border-bottom: none; }

    .row-num { font-family: 'SF Mono', monospace; font-size: 11px; color: #94a3b8; width: 40px; }
    .entry-date { font-family: 'SF Mono', monospace; font-size: 12px; color: #475569; white-space: nowrap; }
    .ref-cell { width: 150px; }
    .ref-badge { display: inline-block; padding: 2px 7px; background: #e0e7ff; color: #3730a3; border-radius: 5px; font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; margin-right: 3px; }
    .ref-id-badge { display: inline-block; padding: 2px 7px; background: #f1f5f9; color: #64748b; border-radius: 5px; font-size: 9.5px; font-weight: 700; font-family: 'SF Mono', monospace; }
    .reversal-note { display: inline-block; font-size: 9.5px; color: #92400e; background: #fef3c7; padding: 1px 6px; border-radius: 4px; font-weight: 600; margin-left: 4px; }
    .desc-cell { font-size: 12.5px; color: #0f172a; font-weight: 500; max-width: 320px; }
    .posted-by-cell { font-size: 12px; color: #1e293b; }
    .posted-by-cell .time { display: block; font-size: 10px; color: #94a3b8; font-family: 'SF Mono', monospace; margin-top: 1px; }
    .amount-col { text-align: right; font-family: 'SF Mono', monospace; font-size: 12.5px; font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; color: #047857; }
    .status-pill { display: inline-block; padding: 3px 9px; border-radius: 10px; font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; }
    .status-pill.posted { background: #d1fae5; color: #065f46; }
    .status-pill.reversed { background: #fee2e2; color: #991b1b; }

    .row-action { display: inline-flex; align-items: center; gap: 4px; padding: 4px 9px; border-radius: 6px; background: #eff6ff; color: #2563eb; text-decoration: none; border: 1px solid #bfdbfe; font-size: 11px; font-weight: 600; white-space: nowrap; }
    .row-action:hover { background: #2563eb; color: #fff; border-color: #2563eb; text-decoration: none; }

    table.entries tfoot td { padding: 10px 14px; background: #f0f5ff; font-weight: 700; font-size: 12.5px; color: #1e293b; border-top: 2px solid #c9d7ef; border-bottom: 2px solid #c9d7ef; }
    table.entries tfoot td.amount-col { font-size: 13px; font-weight: 800; }

    /* Pagination */
    .pagination-bar { padding: 12px 20px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
    .pagination-info { font-size: 11.5px; color: #64748b; }
    .pagination-info strong { color: #1e293b; }
    .pagination-controls { display: flex; gap: 3px; flex-wrap: wrap; align-items: center; }
    .page-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 32px; height: 32px; padding: 0 8px; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff; color: #475569; font-size: 11.5px; font-weight: 600; text-decoration: none; font-family: 'SF Mono', monospace; }
    .page-btn:hover { background: #f1f5f9; color: #1e293b; text-decoration: none; border-color: #94a3b8; }
    .page-btn.active { background: #2563eb; color: #fff; border-color: #2563eb; }
    .page-btn.disabled { opacity: 0.4; cursor: not-allowed; pointer-events: none; }
    .page-btn.ellipsis { pointer-events: none; border-color: transparent; background: transparent; }

    /* Empty */
    .empty-state { padding: 60px 20px; text-align: center; color: #94a3b8; font-size: 13px; }
    .empty-state .big { font-size: 44px; color: #cbd5e1; display: block; margin-bottom: 10px; }
    .empty-state .hint { font-size: 11.5px; margin-top: 6px; color: #cbd5e1; }

    /* Print */
    @media print {
        @page { size: A4 landscape; margin: 10mm 8mm; }
        body * { visibility: hidden; }
        .print-area, .print-area * { visibility: visible; }
        .print-area { position: absolute; left: 0; top: 0; width: 100%; }
        .head, .toolbar, .pagination-bar, .row-action-col, nav, .navbar { display: none !important; }
        body { background: #fff; color: #000; font-size: 9pt; }
        .table-card { box-shadow: none; border-radius: 0; border: 0.5pt solid #808080; }
        table.entries thead th { background: #e8e8e8 !important; color: #000 !important; border: 0.5pt solid #808080; padding: 3pt 5pt !important; }
        table.entries tbody td { padding: 2.5pt 5pt !important; border: 0.5pt solid #c0c0c0; color: #000; }
        .status-pill, .ref-badge, .ref-id-badge, .reversal-note { background: none !important; color: #000 !important; border: none; padding: 0 !important; }
        .entries tr { page-break-inside: avoid; }
        table.entries thead { display: table-header-group; }
        table.entries tfoot { display: table-footer-group; }
    }

    @media (max-width: 800px) {
        .head { padding: 16px 20px; }
        .toolbar { padding: 14px 16px; }
        .filter-grid { grid-template-columns: 1fr; }
        table.entries thead { display: none; }
        table.entries, table.entries tbody, table.entries tr, table.entries td { display: block; width: 100%; }
        table.entries tr { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; }
        table.entries td { padding: 4px 0 !important; border: none; display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        table.entries td::before { content: attr(data-label); font-weight: 700; font-size: 10px; text-transform: uppercase; color: #64748b; letter-spacing: 0.4px; }
        .pagination-bar { flex-direction: column; align-items: stretch; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head no-print">
        <div>
            <h1><i class="fas fa-list"></i> Journal Entries</h1>
            <div class="sub">
                <?= number_format($totalRows) ?> entries &middot;
                <?= date('M j, Y', strtotime($filterFrom)) ?> through <?= date('M j, Y', strtotime($filterTo)) ?>
            </div>
        </div>
        <?php if (!empty($perms['can_add'])): ?>
            <a href="gl_journal_add.php" class="btn-new"><i class="fas fa-plus"></i> New Journal Entry</a>
        <?php endif; ?>
    </div>

    <div class="toolbar no-print">
        <form method="get" action="">
            <div class="filter-grid">
                <div class="filter-group">
                    <label for="from">From Date</label>
                    <input type="date" id="from" name="from" value="<?= htmlspecialchars($filterFrom) ?>">
                </div>
                <div class="filter-group">
                    <label for="to">To Date</label>
                    <input type="date" id="to" name="to" value="<?= htmlspecialchars($filterTo) ?>">
                </div>
                <div class="filter-group">
                    <label for="session">Session</label>
                    <select id="session" name="session">
                        <option value="">All Sessions</option>
                        <?php foreach ($sessions as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= ((string)$filterSession === (string)$s['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="ref_type">Reference Type</label>
                    <select id="ref_type" name="ref_type">
                        <option value="">All Types</option>
                        <?php foreach ($refTypes as $r): ?>
                            <option value="<?= htmlspecialchars($r['reference_type']) ?>" <?= ($filterRefType === $r['reference_type']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($r['reference_type']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">All</option>
                        <option value="POSTED" <?= ($filterStatus === 'POSTED') ? 'selected' : '' ?>>Posted</option>
                        <option value="REVERSED" <?= ($filterStatus === 'REVERSED') ? 'selected' : '' ?>>Reversed</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="search">Search</label>
                    <input type="text" id="search" name="search" placeholder="Description or memo..." value="<?= htmlspecialchars($filterSearch) ?>">
                </div>
            </div>

            <div class="toolbar-actions">
                <a href="gl_transactions.php" class="btn-tool ghost"><i class="fas fa-redo"></i> Reset</a>
                <button type="submit" class="btn-tool primary"><i class="fas fa-filter"></i> Apply Filters</button>
                <button type="button" class="btn-tool ghost" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            </div>
        </form>
    </div>

    <?php if (empty($transactions)): ?>
        <div class="table-card">
            <div class="empty-state">
                <span class="big"><i class="fas fa-inbox"></i></span>
                No journal entries found.
                <div class="hint">Try changing the date range or removing some filters.</div>
            </div>
        </div>
    <?php else: ?>

        <div class="table-card print-area">
            <div class="table-head">
                <div class="title"><i class="fas fa-list-ul"></i> Transactions</div>
                <div class="count">Showing <strong><?= count($transactions) ?></strong> of <strong><?= number_format($totalRows) ?></strong></div>
            </div>

            <div style="overflow-x:auto;">
                <table class="entries">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th style="width: 110px;">Date</th>
                            <th style="width: 150px;">Reference</th>
                            <th>Description</th>
                            <th style="width: 130px;">Posted By</th>
                            <th class="amount-col">Amount</th>
                            <th style="width: 100px;">Status</th>
                            <th class="action-col row-action-col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $i => $t):
                            $isRev = $t['status'] === 'REVERSED';
                            $rowNum = $offset + $i + 1;
                        ?>
                            <tr class="<?= $isRev ? 'reversed-row' : '' ?>">
                                <td class="row-num" data-label="#"><?= $rowNum ?></td>
                                <td class="entry-date" data-label="Date"><?= htmlspecialchars($t['entry_date']) ?></td>
                                <td class="ref-cell" data-label="Reference">
                                    <?php if ($t['reference_type']): ?>
                                        <span class="ref-badge"><?= htmlspecialchars($t['reference_type']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($t['reference_id']): ?>
                                        <span class="ref-id-badge">#<?= (int)$t['reference_id'] ?></span>
                                    <?php endif; ?>
                                    <?php if (!$t['reference_type'] && !$t['reference_id']): ?>
                                        <span style="color:#cbd5e1;">—</span>
                                    <?php endif; ?>
                                    <?php if ($t['reversal_of']): ?>
                                        <span class="reversal-note">reverses #<?= (int)$t['reversal_of'] ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="desc-cell" data-label="Description"><?= htmlspecialchars($t['description']) ?></td>
                                <td class="posted-by-cell" data-label="Posted By">
                                    <?= htmlspecialchars($t['posted_by_name'] ?? ('User #' . $t['posted_by'])) ?>
                                    <span class="time"><?= htmlspecialchars(substr($t['posted_at'], 0, 16)) ?></span>
                                </td>
                                <td class="amount-col" data-label="Amount"><?= number_format((float)$t['total_debit'], 2) ?></td>
                                <td data-label="Status">
                                    <?php if ($isRev): ?>
                                        <span class="status-pill reversed">Reversed</span>
                                    <?php else: ?>
                                        <span class="status-pill posted">Posted</span>
                                    <?php endif; ?>
                                </td>
                                <td class="action-col row-action-col" data-label="Action" style="text-align:right;">
                                    <a href="gl_transaction_view.php?id=<?= (int)$t['id'] ?>" class="row-action" title="View transaction">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="5" style="text-align:right;">Total on this page:</td>
                            <td class="amount-col"><?= number_format($pageDebit, 2) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="pagination-bar">
                    <div class="pagination-info">
                        Page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong> &middot;
                        <?= number_format($totalRows) ?> total entries
                    </div>
                    <div class="pagination-controls">
                        <?php
                        $qs = $_GET;
                        $buildUrl = function($p) use ($qs) {
                            $qs['page'] = $p;
                            return 'gl_transactions.php?' . http_build_query($qs);
                        };
                        $start = max(1, $page - 3);
                        $end   = min($totalPages, $page + 3);
                        ?>

                        <a href="<?= $page > 1 ? htmlspecialchars($buildUrl($page - 1)) : '#' ?>" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">&laquo;</a>

                        <?php if ($start > 1): ?>
                            <a href="<?= htmlspecialchars($buildUrl(1)) ?>" class="page-btn">1</a>
                            <?php if ($start > 2): ?><span class="page-btn ellipsis">&hellip;</span><?php endif; ?>
                        <?php endif; ?>

                        <?php for ($p = $start; $p <= $end; $p++): ?>
                            <a href="<?= htmlspecialchars($buildUrl($p)) ?>" class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
                        <?php endfor; ?>

                        <?php if ($end < $totalPages): ?>
                            <?php if ($end < $totalPages - 1): ?><span class="page-btn ellipsis">&hellip;</span><?php endif; ?>
                            <a href="<?= htmlspecialchars($buildUrl($totalPages)) ?>" class="page-btn"><?= $totalPages ?></a>
                        <?php endif; ?>

                        <a href="<?= $page < $totalPages ? htmlspecialchars($buildUrl($page + 1)) : '#' ?>" class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">&raquo;</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
<?php $conn->close(); ?>