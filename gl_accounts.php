<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

// Filter
$filterType = null;
if (isset($_GET['type']) && in_array($_GET['type'], ['ASSET','LIABILITY','EQUITY','REVENUE','EXPENSE'], true)) {
    $filterType = $_GET['type'];
}

// Load accounts
$accounts = gl_list_accounts($conn, $filterType, true);

// Balances
$today = date('Y-m-d');
$accountBalances = [];
foreach ($accounts as $acc) {
    $raw = gl_account_balance($conn, (int)$acc['id'], $today);
    $isContra = !empty($acc['is_contra']);
    if (!$isContra && in_array($acc['account_type'], ['LIABILITY','EQUITY','REVENUE'], true)) {
        $raw = -$raw;
    }
    $accountBalances[(int)$acc['id']] = $raw;
}

// Group by type
$byType = ['ASSET' => [], 'LIABILITY' => [], 'EQUITY' => [], 'REVENUE' => [], 'EXPENSE' => []];
foreach ($accounts as $a) {
    $byType[$a['account_type']][] = $a;
}

// Build trees
$trees = [];
foreach ($byType as $type => $list) {
    $byId = [];
    foreach ($list as $a) $byId[(int)$a['id']] = $a;

    $tops = [];
    foreach ($list as $a) {
        if ($a['parent_id'] === null || !isset($byId[(int)$a['parent_id']])) {
            $tops[] = $a;
        }
    }
    $trees[$type] = ['tops' => $tops, 'all' => $list];
}

// Type metadata
$typeMeta = [
    'ASSET'     => ['label' => 'Assets',     'icon' => 'glyphicon-home',          'color' => '#1e40af'],
    'LIABILITY' => ['label' => 'Liabilities','icon' => 'glyphicon-transfer',      'color' => '#c2410c'],
    'EQUITY'    => ['label' => 'Equity',     'icon' => 'glyphicon-briefcase',     'color' => '#6b21a8'],
    'REVENUE'   => ['label' => 'Revenue',    'icon' => 'glyphicon-usd',           'color' => '#047857'],
    'EXPENSE'   => ['label' => 'Expenses',   'icon' => 'glyphicon-shopping-cart', 'color' => '#b91c1c'],
];

// Totals per type
$typeTotals = [];
foreach ($trees as $type => $data) {
    $total = 0.0;
    foreach ($data['tops'] as $top) {
        $total += $accountBalances[(int)$top['id']] ?? 0.0;
        foreach ($data['all'] as $child) {
            if ((int)$child['parent_id'] === (int)$top['id']) {
                $total += $accountBalances[(int)$child['id']] ?? 0.0;
            }
        }
    }
    $typeTotals[$type] = $total;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    
    <title>Chart of Accounts</title>
    <style>
        body { background: #eef1f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }

        .coa-wrap {
            max-width: 1500px;
            margin: 30px auto;
            padding: 0 24px;
        }

        .coa-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 28px;
            flex-wrap: wrap;
            gap: 18px;
        }
        .coa-head h1 {
            margin: 0;
            font-size: 34px;
            font-weight: 700;
            color: #1e293b;
            letter-spacing: -0.5px;
        }
        .coa-head .sub {
            color: #64748b;
            font-size: 15px;
            margin-top: 6px;
        }

        .coa-controls {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }
        .filter-select {
            height: 44px;
            padding: 8px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 15px;
            background: #fff;
        }
        .btn-action {
            height: 44px;
            padding: 0 20px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.15s;
        }
        .btn-action.primary { background: #1e40af; color: #fff; }
        .btn-action.primary:hover { background: #1e3a8a; color: #fff; text-decoration: none; }
        .btn-action.ghost { background: #fff; border: 1px solid #cbd5e1; color: #475569; }
        .btn-action.ghost:hover { background: #f8fafc; color: #1e293b; text-decoration: none; }

        .type-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            margin-bottom: 26px;
            overflow: hidden;
        }

        .type-card-header {
            padding: 20px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #e2e8f0;
            background: #f8fafc;
            gap: 14px;
            flex-wrap: wrap;
        }
        .type-card-header .title-block {
            display: flex;
            align-items: center;
            gap: 14px;
            flex: 1;
            min-width: 0;
        }
        .type-card-header .icon-circle {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 20px;
            flex-shrink: 0;
        }
        .type-card-header h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
            color: #1e293b;
        }
        .type-card-header .count {
            color: #64748b;
            font-size: 13px;
            font-weight: 500;
            margin-top: 3px;
        }
        .type-total {
            font-size: 22px;
            font-weight: 700;
            color: #0f172a;
            font-family: 'SF Mono', 'Monaco', monospace;
            font-variant-numeric: tabular-nums;
            text-align: right;
        }
        .type-total .lbl {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 3px;
        }

        .acc-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 15px;
        }
        .acc-table thead th {
            padding: 14px 18px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            background: #fff;
            border-bottom: 2px solid #e2e8f0;
        }
        .acc-table thead th.amount-col { text-align: right; }
        .acc-table thead th.actions-col { text-align: right; width: 220px; }

        .acc-table tbody tr { border-bottom: 1px solid #f1f5f9; }
        .acc-table tbody tr:last-child { border-bottom: none; }
        .acc-table tbody tr:hover { background: #f8fafc; }
        .acc-table tbody td { padding: 14px 18px; vertical-align: middle; color: #1e293b; }

        .acc-code {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 15px;
            color: #475569;
            font-weight: 600;
            width: 90px;
        }
        .acc-name {
            font-size: 15px;
            color: #0f172a;
            font-weight: 500;
        }
        .acc-name.parent-name {
            font-weight: 700;
            color: #1e293b;
            font-size: 16px;
        }
        .name-with-add {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .child-indent {
            padding-left: 48px !important;
            position: relative;
        }
        .child-indent::before {
            content: '';
            position: absolute;
            left: 26px;
            top: 50%;
            width: 14px;
            height: 2px;
            background: #cbd5e1;
            border-radius: 1px;
        }

        .amount-col {
            text-align: right;
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 15px;
            font-variant-numeric: tabular-nums;
            color: #1e293b;
        }
        .amount-col.zero { color: #94a3b8; }
        .amount-col.parent-balance {
            font-weight: 700;
            color: #0f172a;
            font-size: 16px;
        }

        .actions-col { text-align: right; white-space: nowrap; }

        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 7px 13px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid;
            transition: all 0.15s;
            margin-left: 4px;
        }
        .action-btn.ledger {
            background: #eff6ff;
            color: #1e40af;
            border-color: #bfdbfe;
        }
        .action-btn.ledger:hover {
            background: #1e40af;
            color: #fff;
            border-color: #1e40af;
            text-decoration: none;
        }
        .action-btn.edit {
            background: #fefce8;
            color: #a16207;
            border-color: #fef08a;
        }
        .action-btn.edit:hover {
            background: #a16207;
            color: #fff;
            border-color: #a16207;
            text-decoration: none;
        }

        /* Inline "Add" button next to parent name */
        .add-inline {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            background: #047857;
            color: #fff;
            border: none;
            transition: all 0.15s;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .add-inline:hover {
            background: #065f46;
            color: #fff;
            text-decoration: none;
            transform: translateY(-1px);
        }
        .add-inline .glyphicon { font-size: 11px; }

        .badge-pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            vertical-align: middle;
            margin-left: 6px;
        }
        .badge-pill.contra { background: #fef3c7; color: #92400e; }

        .parent-row {
            background: #f8fafc;
        }
        .parent-row td {
            padding: 15px 18px;
            border-bottom: 2px solid #e2e8f0;
        }

        .empty-state {
            padding: 40px;
            text-align: center;
            color: #94a3b8;
            font-size: 15px;
            font-style: italic;
        }
    </style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="coa-wrap">

    <div class="coa-head">
        <div>
            <h1>Chart of Accounts</h1>
            <div class="sub">
                Balances as of <?php echo date('F j, Y'); ?>
                &middot; <?php echo count($accounts); ?> accounts
            </div>
        </div>
        <div class="coa-controls">
            <form method="get" action="" style="display: flex; gap: 10px; align-items: center; margin: 0;">
                <label style="font-size: 15px; color: #475569; margin: 0; font-weight: 500;">Filter:</label>
                <select name="type" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Types</option>
                    <?php foreach (['ASSET','LIABILITY','EQUITY','REVENUE','EXPENSE'] as $t): ?>
                        <option value="<?php echo $t; ?>" <?php echo $filterType === $t ? 'selected' : ''; ?>>
                            <?php echo $typeMeta[$t]['label']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($filterType): ?>
                    <a href="gl_accounts.php" class="btn-action ghost">Clear</a>
                <?php endif; ?>
            </form>
            <a href="gl_account_add.php" class="btn-action primary">
                <span class="glyphicon glyphicon-plus"></span> Add New Account
            </a>
        </div>
    </div>

    <?php if (isset($_SESSION['message'])): ?>
        <div style="margin-bottom: 24px; font-size: 15px;">
            <?php
            echo $_SESSION['message'];
            unset($_SESSION['message'], $_SESSION['message_type']);
            ?>
        </div>
    <?php endif; ?>

    <div class="row">
        <?php foreach ($typeMeta as $type => $meta):
            if ($filterType && $filterType !== $type) continue;
            $data = $trees[$type];
            if (empty($data['tops'])) continue;
            $colClass = $filterType ? 'col-md-12' : 'col-md-6';
        ?>
            <div class="<?php echo $colClass; ?>">
                <div class="type-card">
                    <div class="type-card-header">
                        <div class="title-block">
                            <div class="icon-circle" style="background: <?php echo $meta['color']; ?>;">
                                <span class="glyphicon <?php echo $meta['icon']; ?>"></span>
                            </div>
                            <div>
                                <h2><?php echo $meta['label']; ?></h2>
                                <div class="count">
                                    <?php
                                    $parentCount = count($data['tops']);
                                    $childCount = count($data['all']) - $parentCount;
                                    echo $parentCount . ' group' . ($parentCount !== 1 ? 's' : '') . 
                                         ($childCount > 0 ? ', ' . $childCount . ' account' . ($childCount !== 1 ? 's' : '') : '');
                                    ?>
                                </div>
                            </div>
                        </div>
                        <div class="type-total">
                            <span class="lbl">Total</span>
                            <?php echo number_format($typeTotals[$type], 2); ?>
                        </div>
                    </div>

                    <table class="acc-table">
                        <thead>
                            <tr>
                                <th style="width: 90px;">Code</th>
                                <th>Account Name</th>
                                <th class="amount-col" style="width: 150px;">Balance</th>
                                <th class="actions-col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['tops'] as $parent):
                                $pBalance = $accountBalances[(int)$parent['id']] ?? 0.0;
                                $aggBalance = $pBalance;
                                foreach ($data['all'] as $c) {
                                    if ((int)$c['parent_id'] === (int)$parent['id']) {
                                        $aggBalance += $accountBalances[(int)$c['id']] ?? 0.0;
                                    }
                                }
                            ?>
                                <tr class="parent-row">
                                    <td class="acc-code"><?php echo htmlspecialchars($parent['code']); ?></td>
                                    <td class="acc-name parent-name">
                                        <div class="name-with-add">
                                            <span><?php echo htmlspecialchars($parent['name']); ?></span>
                                            <a href="gl_account_add.php?parent_id=<?php echo (int)$parent['id']; ?>"
                                               class="add-inline"
                                               title="Add a new account under <?php echo htmlspecialchars($parent['name']); ?>">
                                                <span class="glyphicon glyphicon-plus"></span> Add
                                            </a>
                                            <?php if (!empty($parent['is_contra'])): ?>
                                                <span class="badge-pill contra">Contra</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="amount-col parent-balance <?php echo abs($aggBalance) < 0.005 ? 'zero' : ''; ?>">
                                        <?php echo number_format($aggBalance, 2); ?>
                                    </td>
                                    <td class="actions-col">
                                        <a href="gl_account_ledger.php?id=<?php echo (int)$parent['id']; ?>"
                                           class="action-btn ledger">
                                            <span class="glyphicon glyphicon-book"></span> Ledger
                                        </a>
                                        <a href="gl_account_edit.php?id=<?php echo (int)$parent['id']; ?>"
                                           class="action-btn edit">
                                            <span class="glyphicon glyphicon-pencil"></span> Edit
                                        </a>
                                    </td>
                                </tr>

                                <?php
                                foreach ($data['all'] as $child):
                                    if ((int)$child['parent_id'] !== (int)$parent['id']) continue;
                                    $cBalance = $accountBalances[(int)$child['id']] ?? 0.0;
                                ?>
                                    <tr>
                                        <td class="acc-code"><?php echo htmlspecialchars($child['code']); ?></td>
                                        <td class="acc-name child-indent">
                                            <?php echo htmlspecialchars($child['name']); ?>
                                            <?php if (!empty($child['is_contra'])): ?>
                                                <span class="badge-pill contra">Contra</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="amount-col <?php echo abs($cBalance) < 0.005 ? 'zero' : ''; ?>">
                                            <?php echo number_format($cBalance, 2); ?>
                                        </td>
                                        <td class="actions-col">
                                            <a href="gl_account_ledger.php?id=<?php echo (int)$child['id']; ?>"
                                               class="action-btn ledger">
                                                <span class="glyphicon glyphicon-book"></span> Ledger
                                            </a>
                                            <a href="gl_account_edit.php?id=<?php echo (int)$child['id']; ?>"
                                               class="action-btn edit">
                                                <span class="glyphicon glyphicon-pencil"></span> Edit
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (empty($accounts)): ?>
        <div class="type-card">
            <div class="empty-state">No accounts found.</div>
        </div>
    <?php endif; ?>

</div>



<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

</body>
</html>
<?php $conn->close(); ?>