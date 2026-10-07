<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

// ---------------------------------------------------------------------------
// Parse filters
// ---------------------------------------------------------------------------
$filterFrom     = $_GET['from']      ?? date('Y-m-01');
$filterTo       = $_GET['to']        ?? date('Y-m-d');
$filterSession  = $_GET['session']   ?? '';
$filterRefType  = $_GET['ref_type']  ?? '';
$filterStatus   = $_GET['status']    ?? '';
$filterSearch   = trim($_GET['search'] ?? '');
$page           = max(1, (int)($_GET['page'] ?? 1));
$perPage        = 100;
$offset         = ($page - 1) * $perPage;

// Build WHERE and bound params
$where  = " WHERE t.entry_date BETWEEN ? AND ? ";
$params = [$filterFrom, $filterTo];
$types  = "ss";

if ($filterSession !== '' && ctype_digit((string)$filterSession)) {
    $where   .= " AND t.session_id = ? ";
    $params[] = (int)$filterSession;
    $types   .= "i";
}
if ($filterRefType !== '') {
    $where   .= " AND t.reference_type = ? ";
    $params[] = $filterRefType;
    $types   .= "s";
}
if ($filterStatus === 'POSTED' || $filterStatus === 'REVERSED') {
    $where   .= " AND t.status = ? ";
    $params[] = $filterStatus;
    $types   .= "s";
}
if ($filterSearch !== '') {
    $where   .= " AND (t.description LIKE ? OR EXISTS (
                       SELECT 1 FROM gl_journal_lines l2
                       WHERE l2.transaction_id = t.id AND l2.memo LIKE ?
                   )) ";
    $like = '%' . $filterSearch . '%';
    $params[] = $like;
    $params[] = $like;
    $types   .= "ss";
}

// Count total for pagination
$countSql = "SELECT COUNT(*) AS cnt FROM gl_transactions t" . $where;
$countStmt = $conn->prepare($countSql);
$countStmt->bind_param($types, ...$params);
$countStmt->execute();
$totalRows = (int)$countStmt->get_result()->fetch_assoc()['cnt'];
$countStmt->close();

$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Fetch page of transactions
$listSql = "
    SELECT t.id, t.entry_date, t.session_id, t.description,
           t.reference_type, t.reference_id, t.posted_by, t.posted_at,
           t.status, t.reversal_of,
           u.username AS posted_by_name,
           s.title    AS session_title,
           (SELECT COALESCE(SUM(l.debit), 0)
              FROM gl_journal_lines l
              WHERE l.transaction_id = t.id) AS total_debit
    FROM gl_transactions t
    LEFT JOIN users u ON u.id = t.posted_by
    LEFT JOIN sessions s ON s.id = t.session_id
    " . $where . "
    ORDER BY t.entry_date DESC, t.id DESC
    LIMIT ? OFFSET ?
";
$listParams = array_merge($params, [$perPage, $offset]);
$listTypes  = $types . "ii";
$listStmt = $conn->prepare($listSql);
$listStmt->bind_param($listTypes, ...$listParams);
$listStmt->execute();
$transactions = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$listStmt->close();

// Page totals
$pageDebit = 0.0;
foreach ($transactions as $t) {
    $pageDebit += (float)$t['total_debit'];
}

// Sessions for filter dropdown
$sessions = $conn->query("SELECT id, title FROM sessions ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);

// Distinct reference types for dropdown
$refTypes = $conn->query("
    SELECT DISTINCT reference_type
    FROM gl_transactions
    WHERE reference_type IS NOT NULL AND reference_type <> ''
    ORDER BY reference_type
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    
    <title>Journal Entries</title>
    <style>
        * { box-sizing: border-box; }

        body {
            background: #eef1f5;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            margin: 0;
            padding: 0;
            color: #1e293b;
        }

        .report-wrap {
            max-width: 1300px;
            margin: 30px auto;
            padding: 0 20px;
        }

        /* ---------- Header ---------- */
        .report-head {
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
            color: #fff;
            padding: 32px 40px 28px 40px;
            border-radius: 12px 12px 0 0;
            box-shadow: 0 4px 16px rgba(30, 64, 175, 0.15);
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 20px;
            flex-wrap: wrap;
        }
        .report-head h1 {
            margin: 0;
            font-size: 32px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        .report-head .sub {
            font-size: 14px;
            opacity: 0.9;
            margin-top: 6px;
        }
        .btn-new {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            background: rgba(255, 255, 255, 0.15);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.15s;
        }
        .btn-new:hover {
            background: #fff;
            color: #1e40af;
            text-decoration: none;
            border-color: #fff;
        }

        /* ---------- Toolbar ---------- */
        .toolbar {
            background: #fff;
            border-radius: 0 0 12px 12px;
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
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .filter-group label {
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 0;
        }
        .filter-group input,
        .filter-group select {
            height: 40px;
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            background: #fff;
            color: #0f172a;
            transition: all 0.15s;
        }
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #1e40af;
            box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
        }

        .toolbar-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }
        .btn-tool {
            height: 40px;
            padding: 0 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            text-decoration: none;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .btn-tool.primary { background: #1e40af; color: #fff; border-color: #1e40af; }
        .btn-tool.primary:hover { background: #1e3a8a; color: #fff; text-decoration: none; border-color: #1e3a8a; }
        .btn-tool.ghost { background: #fff; color: #475569; border-color: #cbd5e1; }
        .btn-tool.ghost:hover { background: #f8fafc; color: #1e293b; text-decoration: none; border-color: #94a3b8; }

        /* ---------- Table Card ---------- */
        .table-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            overflow: hidden;
            margin-bottom: 24px;
        }
        .table-header-bar {
            padding: 16px 26px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .table-header-bar .title {
            font-size: 14px;
            font-weight: 700;
            color: #1e293b;
            text-transform: uppercase;
            letter-spacing: 1.2px;
        }
        .table-header-bar .count {
            font-size: 13px;
            color: #64748b;
            font-weight: 500;
        }

        .entries-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        .entries-table thead th {
            padding: 14px 16px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1px;
            background: #f1f5f9;
            border-bottom: 2px solid #cbd5e1;
            white-space: nowrap;
        }
        .entries-table thead th.amount-col { text-align: right; }
        .entries-table thead th.action-col { text-align: center; width: 100px; }

        .entries-table tbody tr {
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.15s;
        }
        .entries-table tbody tr:hover { background: #f8fafc; }
        .entries-table tbody tr.reversed-row {
            background: #fef5f5;
            opacity: 0.75;
        }
        .entries-table tbody tr.reversed-row:hover { background: #fdecec; }
        .entries-table tbody td {
            padding: 14px 16px;
            color: #1e293b;
            vertical-align: middle;
        }

        .row-num {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 12px;
            color: #94a3b8;
            width: 50px;
        }

        .entry-date {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 13px;
            color: #475569;
            white-space: nowrap;
            width: 110px;
        }

        .ref-cell {
            width: 160px;
        }
        .ref-badge {
            display: inline-block;
            padding: 2px 8px;
            background: #e0e7ff;
            color: #3730a3;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-right: 4px;
        }
        .ref-id-badge {
            display: inline-block;
            padding: 2px 8px;
            background: #f1f5f9;
            color: #64748b;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 700;
            font-family: 'SF Mono', 'Monaco', monospace;
        }
        .reversal-note {
            display: block;
            font-size: 10px;
            color: #92400e;
            background: #fef3c7;
            padding: 1px 6px;
            border-radius: 3px;
            font-weight: 600;
            margin-top: 4px;
        }

        .desc-cell {
            font-size: 14px;
            color: #0f172a;
            font-weight: 500;
            max-width: 320px;
        }

        .posted-by-cell {
            font-size: 13px;
            color: #1e293b;
            width: 130px;
        }
        .posted-by-cell .time {
            display: block;
            font-size: 11px;
            color: #94a3b8;
            font-family: 'SF Mono', 'Monaco', monospace;
            margin-top: 2px;
        }

        .amount-col {
            text-align: right;
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 15px;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
            color: #047857;
            width: 130px;
        }

        .status-cell {
            width: 110px;
        }
        .status-pill {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-pill.posted   { background: #d1fae5; color: #065f46; }
        .status-pill.reversed { background: #fee2e2; color: #991b1b; }

        .action-col {
            text-align: center;
            width: 100px;
        }
        .action-col .row-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            padding: 6px 12px;
            border-radius: 6px;
            background: #eff6ff;
            color: #1e40af;
            text-decoration: none;
            transition: all 0.15s;
            border: 1px solid #bfdbfe;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }
        .action-col .row-action:hover {
            background: #1e40af;
            color: #fff;
            border-color: #1e40af;
            text-decoration: none;
        }

        .entries-table tfoot td {
            padding: 16px 16px;
            background: #f0f5ff;
            font-weight: 700;
            font-size: 15px;
            color: #1e293b;
            border-top: 2px solid #c9d7ef;
            border-bottom: 2px solid #c9d7ef;
        }
        .entries-table tfoot td.amount-col {
            font-size: 16px;
            font-weight: 800;
        }

        /* ---------- Pagination ---------- */
        .pagination-bar {
            padding: 20px 26px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
        }
        .pagination-info {
            font-size: 13px;
            color: #64748b;
        }
        .pagination-info strong {
            color: #1e293b;
        }
        .pagination-controls {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            align-items: center;
        }
        .page-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            height: 36px;
            padding: 0 10px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #475569;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.15s;
            font-family: 'SF Mono', 'Monaco', monospace;
        }
        .page-btn:hover {
            background: #f1f5f9;
            color: #1e293b;
            text-decoration: none;
            border-color: #94a3b8;
        }
        .page-btn.active {
            background: #1e40af;
            color: #fff;
            border-color: #1e40af;
        }
        .page-btn.disabled {
            opacity: 0.4;
            cursor: not-allowed;
            pointer-events: none;
        }
        .page-btn.ellipsis {
            pointer-events: none;
            border-color: transparent;
            background: transparent;
        }

        /* ---------- Empty State ---------- */
        .empty-state {
            padding: 60px 30px;
            text-align: center;
            color: #94a3b8;
            font-size: 15px;
            font-style: italic;
        }
        .empty-state .big-icon {
            font-size: 56px;
            color: #cbd5e1;
            display: block;
            margin-bottom: 16px;
        }
        .empty-state .hint {
            font-size: 13px;
            margin-top: 10px;
            color: #cbd5e1;
            font-style: normal;
        }

        /* ============================================================
           PRINT
           ============================================================ */
        @media print {
            @page {
                size: A4 landscape;
                margin: 10mm 8mm;
            }

            body {
                background: #fff;
                color: #000;
                font-size: 9pt;
                font-family: Calibri, Arial, sans-serif;
            }

            .report-wrap { max-width: 100%; margin: 0; padding: 0; }

            .toolbar, .action-col, .report-head .btn-new, .pagination-bar, .navbar {
                display: none !important;
            }

            .report-head {
                background: #fff !important;
                color: #000 !important;
                padding: 0 0 4pt 0;
                border-radius: 0;
                box-shadow: none;
                border-bottom: 2pt solid #000;
                margin-bottom: 8pt;
            }
            .report-head h1 {
                font-size: 14pt;
                font-weight: bold;
                margin: 0;
                letter-spacing: 0;
            }
            .report-head .sub {
                font-size: 9pt;
                opacity: 1;
                color: #333 !important;
                margin-top: 2pt;
                font-style: italic;
            }

            .table-card {
                box-shadow: none;
                border-radius: 0;
                margin-bottom: 0;
                border: 0.5pt solid #808080;
            }
            .table-header-bar {
                background: #e8e8e8 !important;
                padding: 4pt 8pt !important;
                border-bottom: 0.5pt solid #808080;
            }
            .table-header-bar .title {
                font-size: 10pt;
                font-weight: bold;
                text-transform: none;
                letter-spacing: 0;
                color: #000;
            }
            .table-header-bar .count {
                font-size: 9pt;
                color: #333;
            }

            .entries-table {
                font-size: 8pt;
                border-collapse: collapse;
            }
            .entries-table thead th {
                background: #e8e8e8 !important;
                color: #000 !important;
                font-size: 7.5pt;
                font-weight: bold;
                text-transform: none;
                letter-spacing: 0;
                padding: 3pt 5pt !important;
                border: 0.5pt solid #808080;
                text-align: left;
            }
            .entries-table tbody td {
                padding: 2.5pt 5pt !important;
                border: 0.5pt solid #c0c0c0;
                font-size: 8pt;
                color: #000;
            }
            .entries-table .entry-date,
            .entries-table .amount-col,
            .entries-table .row-num {
                font-family: Calibri, Arial, sans-serif;
                font-size: 8pt;
                color: #000 !important;
                font-weight: normal;
            }
            .entries-table .amount-col { font-weight: bold; }
            .entries-table tbody tr.reversed-row {
                background: #f5f5f5 !important;
                opacity: 1;
            }

            .entries-table tfoot td {
                background: #f2f2f2 !important;
                font-size: 8pt;
                font-weight: bold;
                padding: 3pt 5pt !important;
                border: 0.5pt solid #808080;
                color: #000;
            }

            .status-pill {
                background: none !important;
                color: #000 !important;
                padding: 0 !important;
                font-size: 7.5pt !important;
                font-weight: normal;
                text-transform: none;
            }
            .ref-badge, .ref-id-badge {
                background: none !important;
                color: #000 !important;
                padding: 0 !important;
                font-size: 7.5pt !important;
                border: none;
                font-weight: normal;
                text-transform: none;
            }
            .reversal-note {
                background: none !important;
                color: #333 !important;
                padding: 0 !important;
                font-size: 7pt !important;
                font-weight: normal;
            }

            .entries-table tr { page-break-inside: avoid; }
            .entries-table thead { display: table-header-group; }
            .entries-table tfoot { display: table-footer-group; }

            html, body { height: auto !important; }
        }

        @media (max-width: 800px) {
            .report-head { padding: 24px 20px; }
            .report-head h1 { font-size: 24px; }
            .toolbar { padding: 16px 20px; }
            .filter-grid { grid-template-columns: 1fr; }
            .entries-table { font-size: 12px; }
            .entries-table thead th { padding: 10px 10px; font-size: 10px; }
            .entries-table tbody td { padding: 10px 10px; }
            .desc-cell { max-width: 180px; font-size: 12px; }
            .pagination-bar { flex-direction: column; align-items: stretch; }
        }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="report-wrap">

    <!-- Header -->
    <div class="report-head">
        <div>
            <h1>Journal Entries</h1>
            <div class="sub">
                <?php echo number_format($totalRows); ?> entries
                &middot;
                <?php echo date('M j, Y', strtotime($filterFrom)); ?>
                through
                <?php echo date('M j, Y', strtotime($filterTo)); ?>
            </div>
        </div>
        <a href="gl_journal_add.php" class="btn-new">
            <span class="glyphicon glyphicon-plus"></span> New Journal Entry
        </a>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
        <form method="get" action="">
            <div class="filter-grid">
                <div class="filter-group">
                    <label for="from">From Date</label>
                    <input type="date" id="from" name="from" value="<?php echo htmlspecialchars($filterFrom); ?>">
                </div>
                <div class="filter-group">
                    <label for="to">To Date</label>
                    <input type="date" id="to" name="to" value="<?php echo htmlspecialchars($filterTo); ?>">
                </div>
                <div class="filter-group">
                    <label for="session">Session</label>
                    <select id="session" name="session">
                        <option value="">All Sessions</option>
                        <?php foreach ($sessions as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>"
                                <?php echo ((string)$filterSession === (string)$s['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="ref_type">Reference Type</label>
                    <select id="ref_type" name="ref_type">
                        <option value="">All Types</option>
                        <?php foreach ($refTypes as $r): ?>
                            <option value="<?php echo htmlspecialchars($r['reference_type']); ?>"
                                <?php echo ($filterRefType === $r['reference_type']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($r['reference_type']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">All</option>
                        <option value="POSTED"   <?php echo ($filterStatus === 'POSTED')   ? 'selected' : ''; ?>>Posted</option>
                        <option value="REVERSED" <?php echo ($filterStatus === 'REVERSED') ? 'selected' : ''; ?>>Reversed</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="search">Search</label>
                    <input type="text" id="search" name="search"
                           placeholder="Description or memo..."
                           value="<?php echo htmlspecialchars($filterSearch); ?>">
                </div>
            </div>

            <div class="toolbar-actions">
                <a href="gl_transactions.php" class="btn-tool ghost">
                    <span class="glyphicon glyphicon-repeat"></span> Reset
                </a>
                <button type="submit" class="btn-tool primary">
                    <span class="glyphicon glyphicon-filter"></span> Apply Filters
                </button>
                <button type="button" class="btn-tool ghost" onclick="window.print()">
                    <span class="glyphicon glyphicon-print"></span> Print
                </button>
            </div>
        </form>
    </div>

    <!-- Table -->
    <?php if (empty($transactions)): ?>
        <div class="table-card">
            <div class="empty-state">
                <span class="glyphicon glyphicon-inbox big-icon"></span>
                No journal entries found.
                <div class="hint">
                    Try changing the date range or removing some filters.
                </div>
            </div>
        </div>
    <?php else: ?>

        <div class="table-card">
            <div class="table-header-bar">
                <div class="title">Transactions</div>
                <div class="count">
                    Showing <strong><?php echo count($transactions); ?></strong>
                    of <strong><?php echo number_format($totalRows); ?></strong>
                </div>
            </div>

            <div style="overflow-x: auto;">
                <table class="entries-table">
                    <thead>
                        <tr>
                            <th class="row-num" style="width: 50px;">#</th>
                            <th style="width: 110px;">Date</th>
                            <th style="width: 160px;">Reference</th>
                            <th>Description</th>
                            <th style="width: 130px;">Posted By</th>
                            <th class="amount-col">Amount</th>
                            <th style="width: 110px;">Status</th>
                            <th class="action-col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $i => $t):
                            $isRev = $t['status'] === 'REVERSED';
                            $rowNum = $offset + $i + 1;
                        ?>
                            <tr class="<?php echo $isRev ? 'reversed-row' : ''; ?>">
                                <td class="row-num"><?php echo $rowNum; ?></td>
                                <td class="entry-date"><?php echo htmlspecialchars($t['entry_date']); ?></td>
                                <td class="ref-cell">
                                    <?php if ($t['reference_type']): ?>
                                        <span class="ref-badge"><?php echo htmlspecialchars($t['reference_type']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($t['reference_id']): ?>
                                        <span class="ref-id-badge">#<?php echo (int)$t['reference_id']; ?></span>
                                    <?php endif; ?>
                                    <?php if (!$t['reference_type'] && !$t['reference_id']): ?>
                                        <span style="color: #cbd5e1;">—</span>
                                    <?php endif; ?>
                                    <?php if ($t['reversal_of']): ?>
                                        <span class="reversal-note">↺ reverses #<?php echo (int)$t['reversal_of']; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="desc-cell">
                                    <?php echo htmlspecialchars($t['description']); ?>
                                </td>
                                <td class="posted-by-cell">
                                    <?php echo htmlspecialchars($t['posted_by_name'] ?? ('User #' . $t['posted_by'])); ?>
                                    <span class="time"><?php echo htmlspecialchars(substr($t['posted_at'], 0, 16)); ?></span>
                                </td>
                                <td class="amount-col">
                                    <?php echo number_format((float)$t['total_debit'], 2); ?>
                                </td>
                                <td class="status-cell">
                                    <?php if ($isRev): ?>
                                        <span class="status-pill reversed">Reversed</span>
                                    <?php else: ?>
                                        <span class="status-pill posted">Posted</span>
                                    <?php endif; ?>
                                </td>
                                <td class="action-col">
                                    <a href="gl_transaction_view.php?id=<?php echo (int)$t['id']; ?>"
                                       class="row-action" title="View transaction">
                                        <span class="glyphicon glyphicon-eye-open"></span> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="5" style="text-align: right;">Total on this page:</td>
                            <td class="amount-col"><?php echo number_format($pageDebit, 2); ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination-bar">
                    <div class="pagination-info">
                        Page <strong><?php echo $page; ?></strong>
                        of <strong><?php echo $totalPages; ?></strong>
                        &middot;
                        <?php echo number_format($totalRows); ?> total entries
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

                        <a href="<?php echo $page > 1 ? htmlspecialchars($buildUrl($page - 1)) : '#'; ?>"
                           class="page-btn <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            &laquo;
                        </a>

                        <?php if ($start > 1): ?>
                            <a href="<?php echo htmlspecialchars($buildUrl(1)); ?>" class="page-btn">1</a>
                            <?php if ($start > 2): ?>
                                <span class="page-btn ellipsis">…</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($p = $start; $p <= $end; $p++): ?>
                            <a href="<?php echo htmlspecialchars($buildUrl($p)); ?>"
                               class="page-btn <?php echo $p === $page ? 'active' : ''; ?>">
                                <?php echo $p; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($end < $totalPages): ?>
                            <?php if ($end < $totalPages - 1): ?>
                                <span class="page-btn ellipsis">…</span>
                            <?php endif; ?>
                            <a href="<?php echo htmlspecialchars($buildUrl($totalPages)); ?>" class="page-btn">
                                <?php echo $totalPages; ?>
                            </a>
                        <?php endif; ?>

                        <a href="<?php echo $page < $totalPages ? htmlspecialchars($buildUrl($page + 1)) : '#'; ?>"
                           class="page-btn <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                            &raquo;
                        </a>
                    </div>
                </div>
            <?php endif; ?>

        </div>

    <?php endif; ?>

</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
<script>
$(document).ready(function() {
    setTimeout(function() {
        $('.custom-alert').fadeOut('slow');
    }, 10000);
});
</script>

</body>
</html>
<?php if (isset($conn)) $conn->close(); ?>