<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");

$_SESSION['lang'] = 'en';
$lang = $_SESSION['lang'];

$translations['en'] = [
    'title'         => 'Journal Entries',
    'list_title'    => 'Journal Entries',
    'filter'        => 'Filters',
    'from_date'     => 'From Date',
    'to_date'       => 'To Date',
    'session'       => 'Session',
    'ref_type'      => 'Reference Type',
    'status'        => 'Status',
    'search'        => 'Search',
    'search_ph'     => 'Search description or memo...',
    'apply'         => 'Apply',
    'reset'         => 'Reset',
    'all'           => 'All',
    'posted'        => 'Posted',
    'reversed'      => 'Reversed',
    'date'          => 'Date',
    'description'   => 'Description',
    'reference'     => 'Reference',
    'posted_by'     => 'Posted By',
    'amount'        => 'Amount',
    'lines'         => 'Lines',
    'actions'       => 'Actions',
    'view'          => 'View',
    'no_records'    => 'No journal entries found.',
    'showing'       => 'Showing',
    'of'            => 'of',
    'entries'       => 'entries',
    'prev'          => 'Prev',
    'next'          => 'Next',
    'page'          => 'Page',
    'add_journal'   => 'New Journal Entry',
    'amount_in'     => 'Debit',
    'amount_out'    => 'Credit',
    'total_shown'   => 'Total on this page',
];

// ---------------------------------------------------------------------------
// Parse filters
// ---------------------------------------------------------------------------
$filterFrom     = $_GET['from']      ?? date('Y-m-01');  // default: 1st of current month
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
              WHERE l.transaction_id = t.id) AS total_debit,
           (SELECT COUNT(*)
              FROM gl_journal_lines l
              WHERE l.transaction_id = t.id) AS line_count
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
    <?php require_once('meta_inc.php'); ?>
    <style>
        .filter-panel { background: #f9f9f9; padding: 15px; border: 1px solid #e3e3e3; border-radius: 4px; margin-bottom: 15px; }
        .filter-panel label { font-size: 12px; color: #555; margin-bottom: 2px; }
        .amount-column { text-align: right; font-family: monospace; }
        .date-col { white-space: nowrap; }
        .desc-col { max-width: 350px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .desc-col:hover { overflow: visible; white-space: normal; position: relative; background: #fff; z-index: 10; box-shadow: 0 0 5px rgba(0,0,0,0.1); }
        .badge-reversed { background-color: #999; }
        .badge-posted { background-color: #5cb85c; }
        .ref-badge { background: #337ab7; color: #fff; padding: 2px 6px; border-radius: 3px; font-size: 11px; }
        .ref-id-badge { background: #777; color: #fff; padding: 2px 6px; border-radius: 3px; font-size: 11px; margin-left: 3px; }
        .pagination-bar { text-align: center; margin-top: 15px; }
        .small-muted { font-size: 11px; color: #888; }
        tr.reversed-row td { background-color: #f5f5f5; }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="container-fluid" style="padding: 0 30px;">

    <?php if (isset($_SESSION['message'])): ?>
        <div style="margin-top: 15px;">
            <?php
            echo $_SESSION['message'];
            unset($_SESSION['message'], $_SESSION['message_type']);
            ?>
        </div>
    <?php endif; ?>

    <div class="panel panel-primary" style="margin-top: 15px;">
        <div class="panel-heading">
            <div class="row">
                <div class="col-md-8">
                    <h3 class="panel-title">
                        <span class="glyphicon glyphicon-book"></span>
                        <?php echo $translations[$lang]['list_title']; ?>
                        <small>— <?php echo number_format($totalRows); ?> <?php echo $translations[$lang]['entries']; ?></small>
                    </h3>
                </div>
                <div class="col-md-4 text-right">
                    <a href="gl_journal_add.php" class="btn btn-sm btn-success">
                        <span class="glyphicon glyphicon-plus"></span>
                        <?php echo $translations[$lang]['add_journal']; ?>
                    </a>
                </div>
            </div>
        </div>
        <div class="panel-body">

            <!-- Filter bar -->
            <form method="get" class="filter-panel">
                <div class="row">
                    <div class="col-md-2">
                        <label><?php echo $translations[$lang]['from_date']; ?></label>
                        <input type="date" name="from" class="form-control input-sm" value="<?php echo htmlspecialchars($filterFrom); ?>">
                    </div>
                    <div class="col-md-2">
                        <label><?php echo $translations[$lang]['to_date']; ?></label>
                        <input type="date" name="to" class="form-control input-sm" value="<?php echo htmlspecialchars($filterTo); ?>">
                    </div>
                    <div class="col-md-2">
                        <label><?php echo $translations[$lang]['session']; ?></label>
                        <select name="session" class="form-control input-sm">
                            <option value=""><?php echo $translations[$lang]['all']; ?></option>
                            <?php foreach ($sessions as $s): ?>
                                <option value="<?php echo (int)$s['id']; ?>"
                                    <?php echo ((string)$filterSession === (string)$s['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($s['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label><?php echo $translations[$lang]['ref_type']; ?></label>
                        <select name="ref_type" class="form-control input-sm">
                            <option value=""><?php echo $translations[$lang]['all']; ?></option>
                            <?php foreach ($refTypes as $r): ?>
                                <option value="<?php echo htmlspecialchars($r['reference_type']); ?>"
                                    <?php echo ($filterRefType === $r['reference_type']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($r['reference_type']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label><?php echo $translations[$lang]['status']; ?></label>
                        <select name="status" class="form-control input-sm">
                            <option value=""><?php echo $translations[$lang]['all']; ?></option>
                            <option value="POSTED"   <?php echo ($filterStatus === 'POSTED')   ? 'selected' : ''; ?>><?php echo $translations[$lang]['posted']; ?></option>
                            <option value="REVERSED" <?php echo ($filterStatus === 'REVERSED') ? 'selected' : ''; ?>><?php echo $translations[$lang]['reversed']; ?></option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label><?php echo $translations[$lang]['search']; ?></label>
                        <input type="text" name="search" class="form-control input-sm"
                               placeholder="<?php echo $translations[$lang]['search_ph']; ?>"
                               value="<?php echo htmlspecialchars($filterSearch); ?>">
                    </div>
                </div>
                <div class="row" style="margin-top: 10px;">
                    <div class="col-md-12 text-right">
                        <a href="gl_transactions.php" class="btn btn-sm btn-default">
                            <?php echo $translations[$lang]['reset']; ?>
                        </a>
                        <button type="submit" class="btn btn-sm btn-primary">
                            <span class="glyphicon glyphicon-filter"></span>
                            <?php echo $translations[$lang]['apply']; ?>
                        </button>
                    </div>
                </div>
            </form>

            <!-- Listing -->
            <?php if (empty($transactions)): ?>
                <div class="alert alert-info text-center">
                    <?php echo $translations[$lang]['no_records']; ?>
                </div>
            <?php else: ?>

                <div class="table-responsive">
                    <table class="table table-bordered table-hover table-condensed">
                        <thead>
                            <tr>
                                <th style="width: 40px;">#</th>
                                <th style="width: 110px;"><?php echo $translations[$lang]['date']; ?></th>
                                <th style="width: 130px;"><?php echo $translations[$lang]['reference']; ?></th>
                                <th><?php echo $translations[$lang]['description']; ?></th>
                                <th style="width: 120px;"><?php echo $translations[$lang]['posted_by']; ?></th>
                                <th style="width: 130px;" class="text-right"><?php echo $translations[$lang]['amount']; ?></th>
                                <th style="width: 60px;" class="text-center"><?php echo $translations[$lang]['lines']; ?></th>
                                <th style="width: 90px;"><?php echo $translations[$lang]['status']; ?></th>
                                <th style="width: 70px;" class="text-center"><?php echo $translations[$lang]['actions']; ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $i => $t): ?>
                                <?php
                                $isRev   = $t['status'] === 'REVERSED';
                                $rowCls  = $isRev ? 'reversed-row' : '';
                                $rowNum  = $offset + $i + 1;
                                ?>
                                <tr class="<?php echo $rowCls; ?>">
                                    <td><?php echo $rowNum; ?></td>
                                    <td class="date-col"><?php echo htmlspecialchars($t['entry_date']); ?></td>
                                    <td>
                                        <?php if ($t['reference_type']): ?>
                                            <span class="ref-badge"><?php echo htmlspecialchars($t['reference_type']); ?></span>
                                        <?php endif; ?>
                                        <?php if ($t['reference_id']): ?>
                                            <span class="ref-id-badge">#<?php echo (int)$t['reference_id']; ?></span>
                                        <?php endif; ?>
                                        <?php if ($t['reversal_of']): ?>
                                            <br><small class="small-muted">reverses #<?php echo (int)$t['reversal_of']; ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="desc-col" title="<?php echo htmlspecialchars($t['description']); ?>">
                                        <?php echo htmlspecialchars($t['description']); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($t['posted_by_name'] ?? ('User #' . $t['posted_by'])); ?>
                                        <br><small class="small-muted"><?php echo htmlspecialchars(substr($t['posted_at'], 0, 16)); ?></small>
                                    </td>
                                    <td class="amount-column">
                                        <?php echo number_format((float)$t['total_debit'], 2); ?>
                                    </td>
                                    <td class="text-center"><?php echo (int)$t['line_count']; ?></td>
                                    <td>
                                        <?php if ($isRev): ?>
                                            <span class="label badge-reversed"><?php echo $translations[$lang]['reversed']; ?></span>
                                        <?php else: ?>
                                            <span class="label badge-posted"><?php echo $translations[$lang]['posted']; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="gl_transaction_view.php?id=<?php echo (int)$t['id']; ?>"
                                           class="btn btn-xs btn-info">
                                            <?php echo $translations[$lang]['view']; ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="active">
                                <th colspan="5" class="text-right"><?php echo $translations[$lang]['total_shown']; ?>:</th>
                                <th class="amount-column"><?php echo number_format($pageDebit, 2); ?></th>
                                <th colspan="3"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination-bar">
                        <?php
                        $qs = $_GET;
                        $buildUrl = function($p) use ($qs) {
                            $qs['page'] = $p;
                            return 'gl_transactions.php?' . http_build_query($qs);
                        };
                        ?>
                        <nav>
                            <ul class="pagination pagination-sm" style="margin: 0;">
                                <li class="<?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a href="<?php echo $page > 1 ? htmlspecialchars($buildUrl($page - 1)) : '#'; ?>">
                                        &laquo; <?php echo $translations[$lang]['prev']; ?>
                                    </a>
                                </li>
                                <?php
                                // Show up to 7 page numbers around current
                                $start = max(1, $page - 3);
                                $end   = min($totalPages, $page + 3);
                                if ($start > 1) {
                                    echo '<li><a href="' . htmlspecialchars($buildUrl(1)) . '">1</a></li>';
                                    if ($start > 2) echo '<li class="disabled"><span>...</span></li>';
                                }
                                for ($p = $start; $p <= $end; $p++) {
                                    $cls = ($p === $page) ? 'active' : '';
                                    echo '<li class="' . $cls . '"><a href="' . htmlspecialchars($buildUrl($p)) . '">' . $p . '</a></li>';
                                }
                                if ($end < $totalPages) {
                                    if ($end < $totalPages - 1) echo '<li class="disabled"><span>...</span></li>';
                                    echo '<li><a href="' . htmlspecialchars($buildUrl($totalPages)) . '">' . $totalPages . '</a></li>';
                                }
                                ?>
                                <li class="<?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                    <a href="<?php echo $page < $totalPages ? htmlspecialchars($buildUrl($page + 1)) : '#'; ?>">
                                        <?php echo $translations[$lang]['next']; ?> &raquo;
                                    </a>
                                </li>
                            </ul>
                        </nav>
                        <div class="small-muted" style="margin-top: 5px;">
                            <?php echo $translations[$lang]['page']; ?>
                            <?php echo $page; ?>
                            <?php echo $translations[$lang]['of']; ?>
                            <?php echo $totalPages; ?>
                        </div>
                    </div>
                <?php endif; ?>

            <?php endif; ?>

        </div>
    </div>
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
<?php if (isset($conn)) $conn->close(); ?>
</html>