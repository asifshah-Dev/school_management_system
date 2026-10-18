<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");

/* ── Is this an admin? ── */
$isAdmin = false;
if (isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === 1) {
    $isAdmin = true;
} elseif (isset($_SESSION['user_id'])) {
    /* fallback — look up role_id from DB */
    $uid = (int)$_SESSION['user_id'];
    $q = $conn->prepare("SELECT role_id FROM users WHERE id = ? LIMIT 1");
    if ($q) {
        $q->bind_param("i", $uid);
        $q->execute();
        $r = $q->get_result()->fetch_assoc();
        $q->close();
        if ($r && (int)$r['role_id'] === 1) $isAdmin = true;
    }
}

$filterType = null;
if (isset($_GET['type']) && in_array($_GET['type'], ['ASSET','LIABILITY','EQUITY','REVENUE','EXPENSE'], true)) {
    $filterType = $_GET['type'];
}

$accounts = gl_list_accounts($conn, $filterType, true);

$today = date('Y-m-d');
$accountBalances = [];
if ($isAdmin) {
    foreach ($accounts as $acc) {
        $raw = gl_account_balance($conn, (int)$acc['id'], $today);
        $isContra = !empty($acc['is_contra']);
        if (!$isContra && in_array($acc['account_type'], ['LIABILITY','EQUITY','REVENUE'], true)) {
            $raw = -$raw;
        }
        $accountBalances[(int)$acc['id']] = $raw;
    }
}

$byType = ['ASSET' => [], 'LIABILITY' => [], 'EQUITY' => [], 'REVENUE' => [], 'EXPENSE' => []];
foreach ($accounts as $a) $byType[$a['account_type']][] = $a;

$trees = [];
foreach ($byType as $type => $list) {
    $byId = [];
    foreach ($list as $a) $byId[(int)$a['id']] = $a;

    $tops = [];
    foreach ($list as $a) {
        if ($a['parent_id'] === null || !isset($byId[(int)$a['parent_id']])) $tops[] = $a;
    }
    $children = [];
    foreach ($list as $a) {
        if ($a['parent_id'] !== null && isset($byId[(int)$a['parent_id']])) {
            $children[(int)$a['parent_id']][] = $a;
        }
    }
    $trees[$type] = ['tops' => $tops, 'children' => $children, 'all' => $list];
}

$typeMeta = [
    'ASSET'     => ['label' => 'Asset',     'short' => 'A', 'color' => '#1e40af', 'bg' => '#dbeafe'],
    'LIABILITY' => ['label' => 'Liability', 'short' => 'L', 'color' => '#c2410c', 'bg' => '#ffedd5'],
    'EQUITY'    => ['label' => 'Equity',    'short' => 'E', 'color' => '#6b21a8', 'bg' => '#ede9fe'],
    'REVENUE'   => ['label' => 'Revenue',   'short' => 'R', 'color' => '#047857', 'bg' => '#d1fae5'],
    'EXPENSE'   => ['label' => 'Expense',   'short' => 'X', 'color' => '#b91c1c', 'bg' => '#fee2e2'],
];

$typeTotals = [];
foreach ($trees as $type => $data) {
    $total = 0.0;
    foreach ($data['tops'] as $top) {
        $total += $accountBalances[(int)$top['id']] ?? 0.0;
        if (isset($data['children'][(int)$top['id']])) {
            foreach ($data['children'][(int)$top['id']] as $child) {
                $total += $accountBalances[(int)$child['id']] ?? 0.0;
            }
        }
    }
    $typeTotals[$type] = $total;
}

$parentCount = 0;
$childCount  = 0;
foreach ($trees as $t) {
    $parentCount += count($t['tops']);
    foreach ($t['children'] as $c) $childCount += count($c);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Chart of Accounts</title>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    body { background: #f5f7fa; font-family: 'Inter', sans-serif; color: #1e293b; padding-bottom: 40px; }

    .coa-wrap { max-width: 1280px; margin: 20px auto; padding: 0 20px; }

    .coa-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 12px; }
    .coa-head h1 { margin: 0; font-size: 19px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 8px; }
    .coa-head h1 i { color: #2563eb; }
    .coa-head .sub { color: #64748b; font-size: 12px; margin-top: 3px; }

    .coa-controls { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .filter-select { height: 34px; padding: 4px 12px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 12.5px; background: #fff; font-family: inherit; font-weight: 600; color: #334155; }
    .filter-select:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.12); }
    .btn-action { height: 34px; padding: 0 14px; border-radius: 7px; font-size: 12.5px; font-weight: 600; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; font-family: inherit; }
    .btn-action.primary { background: #2563eb; color: #fff; }
    .btn-action.primary:hover { background: #1d4ed8; color: #fff; text-decoration: none; }
    .btn-action.ghost { background: #fff; border: 1px solid #cbd5e1; color: #475569; }
    .btn-action.ghost:hover { background: #f8fafc; color: #1e293b; text-decoration: none; }

    /* Search input */
    .search-box { position: relative; min-width: 220px; }
    .search-box i { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 11px; pointer-events: none; }
    .search-box input { width: 100%; height: 34px; padding: 0 12px 0 30px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 12.5px; font-family: inherit; background: #fff; }
    .search-box input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.12); }
    .search-box .clear-btn { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #94a3b8; cursor: pointer; font-size: 11px; padding: 2px; display: none; }
    .search-box.has-value .clear-btn { display: block; }
    .search-box .clear-btn:hover { color: #dc2626; }

    /* Summary strip */
    .summary-strip { display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; margin-bottom: 14px; }
    .sum-card { background: #fff; border-radius: 9px; padding: 10px 12px; border-left: 3px solid #2563eb; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .sum-card .lbl { font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; }
    .sum-card .val { font-size: 14px; font-weight: 700; color: #0f172a; font-family: 'SF Mono', monospace; margin-top: 2px; font-variant-numeric: tabular-nums; }

    /* Main table card */
    .main-card { background: #fff; border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; overflow: hidden; }

    .main-toolbar { padding: 10px 14px; border-bottom: 1px solid #e2e8f0; background: #f8fafc; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .main-toolbar .title { font-size: 13px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 6px; }
    .main-toolbar .title i { color: #2563eb; }
    .main-toolbar .count-chip { margin-left: auto; background: #eff6ff; color: #2563eb; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
    .main-toolbar .expand-toggle { background: #fff; border: 1px solid #cbd5e1; color: #475569; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; gap: 4px; }
    .main-toolbar .expand-toggle:hover { background: #f1f5f9; color: #1e293b; }

    table.coa-table { width: 100%; margin: 0; border-collapse: separate; border-spacing: 0; }
    table.coa-table thead th {
        background: #fff; color: #64748b;
        font-size: 10px; font-weight: 700; text-transform: uppercase;
        letter-spacing: 0.6px; padding: 9px 14px;
        border-bottom: 2px solid #e2e8f0; white-space: nowrap; text-align: left;
    }
    table.coa-table thead th.num { text-align: right; }
    table.coa-table thead th.act { text-align: right; }

    table.coa-table tbody td { padding: 8px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; font-size: 12.5px; color: #1e293b; }
    table.coa-table tbody tr:last-child td { border-bottom: none; }

    tr.parent-row { background: #fafbfc; cursor: pointer; user-select: none; }
    tr.parent-row:hover { background: #f1f5f9; }
    tr.parent-row td { padding: 10px 14px; border-bottom: 1px solid #e2e8f0; border-top: 1px solid #f1f5f9; font-weight: 600; }
    tr.parent-row .row-open { transition: transform 0.15s; color: #94a3b8; font-size: 11px; margin-right: 6px; }
    tr.parent-row.collapsed .row-open { transform: rotate(-90deg); }

    .type-badge { display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; border-radius: 4px; font-size: 9px; font-weight: 800; margin-right: 6px; vertical-align: middle; }

    .code { font-family: 'SF Mono', monospace; font-size: 12px; color: #475569; font-weight: 600; width: 70px; }
    .acc-name { font-weight: 500; color: #0f172a; }
    .acc-name.parent { font-weight: 700; color: #1e293b; }
    .acc-name.child { padding-left: 0; }

    .balance { text-align: right; font-family: 'SF Mono', monospace; font-variant-numeric: tabular-nums; font-weight: 600; color: #1e293b; font-size: 12.5px; white-space: nowrap; }
    .balance.zero { color: #cbd5e1; font-weight: 500; }
    .balance.parent-total { color: #0f172a; font-weight: 700; }

    tr.child-row { background: #fff; }
    tr.child-row:hover { background: #fafbfc; }
    tr.child-row td { padding: 7px 14px 7px 34px; position: relative; }
    tr.child-row td:first-child { padding-left: 34px; }
    tr.child-row td:first-child::before { content: ''; position: absolute; left: 20px; top: 50%; width: 8px; height: 1px; background: #cbd5e1; }

    tr.parent-row[data-type="ASSET"]     td:first-child { box-shadow: inset 3px 0 0 #1e40af; }
    tr.parent-row[data-type="LIABILITY"] td:first-child { box-shadow: inset 3px 0 0 #c2410c; }
    tr.parent-row[data-type="EQUITY"]    td:first-child { box-shadow: inset 3px 0 0 #6b21a8; }
    tr.parent-row[data-type="REVENUE"]   td:first-child { box-shadow: inset 3px 0 0 #047857; }
    tr.parent-row[data-type="EXPENSE"]   td:first-child { box-shadow: inset 3px 0 0 #b91c1c; }

    .actions-cell { text-align: right; white-space: nowrap; }

    .action-btn { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 5px; font-size: 10.5px; font-weight: 600; text-decoration: none; border: 1px solid; transition: all 0.15s; margin-left: 3px; }
    .action-btn.ledger { background: #eff6ff; color: #1e40af; border-color: #bfdbfe; }
    .action-btn.ledger:hover { background: #1e40af; color: #fff; border-color: #1e40af; text-decoration: none; }
    .action-btn.edit { background: #fefce8; color: #a16207; border-color: #fef08a; }
    .action-btn.edit:hover { background: #a16207; color: #fff; border-color: #a16207; text-decoration: none; }
    .action-btn.add { background: #059669; color: #fff; border-color: #059669; }
    .action-btn.add:hover { background: #047857; color: #fff; border-color: #047857; text-decoration: none; }

    .badge-contra { display: inline-block; padding: 1px 5px; border-radius: 4px; font-size: 8.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; background: #fef3c7; color: #92400e; margin-left: 4px; }

    .sub-group-toggle { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; color: #64748b; font-weight: 600; margin-left: 4px; }

    .no-accounts { padding: 50px 20px; text-align: center; color: #94a3b8; font-size: 13px; font-style: italic; }

    .search-empty { padding: 40px 20px; text-align: center; color: #94a3b8; font-size: 13px; }
    .search-empty .big { font-size: 32px; opacity: 0.4; margin-bottom: 8px; }

    @media (max-width: 768px) {
        .summary-strip { grid-template-columns: repeat(2, 1fr); }
        .search-box { min-width: 100%; }
        table.coa-table thead { display: none; }
        table.coa-table, table.coa-table tbody, table.coa-table tr, table.coa-table td { display: block; width: 100%; }
        table.coa-table tr { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; }
        table.coa-table td { padding: 3px 0 !important; border: none; display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        table.coa-table td::before { content: attr(data-label); font-weight: 700; font-size: 10px; text-transform: uppercase; color: #64748b; letter-spacing: 0.4px; flex-shrink: 0; }
        table.coa-table tr.child-row td:first-child::before,
        table.coa-table tr.parent-row td:first-child::before { display: none; }
        table.coa-table tr.child-row td { padding-left: 0 !important; }
        tr.parent-row[data-type] td:first-child { box-shadow: none; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="coa-wrap">

    <div class="coa-head">
        <div>
            <h1><i class="fas fa-sitemap"></i> Chart of Accounts</h1>
            <div class="sub">
                <?php if ($isAdmin): ?>
                    Balances as of <?= date('F j, Y') ?> &middot;
                <?php endif; ?>
                <?= count($accounts) ?> accounts &middot; <?= $parentCount ?> groups
            </div>
        </div>
        <div class="coa-controls">
            <div class="search-box" id="searchBox">
                <i class="fas fa-search"></i>
                <input type="text" id="coaSearch" placeholder="Search code or name..." autocomplete="off">
                <button type="button" class="clear-btn" id="searchClear" title="Clear"><i class="fas fa-times"></i></button>
            </div>
            <form method="get" action="" style="display: flex; gap: 6px; align-items: center; margin: 0;">
                <select name="type" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Types</option>
                    <?php foreach (['ASSET','LIABILITY','EQUITY','REVENUE','EXPENSE'] as $t): ?>
                        <option value="<?= $t ?>" <?= $filterType === $t ? 'selected' : '' ?>><?= $typeMeta[$t]['label'] ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($filterType): ?>
                    <a href="gl_accounts.php" class="btn-action ghost"><i class="fas fa-times"></i> Clear</a>
                <?php endif; ?>
            </form>
            <?php if (!empty($perms['can_add'])): ?>
                <a href="gl_account_add.php" class="btn-action primary">
                    <i class="fas fa-plus"></i> Add Account
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert alert-info" style="font-size: 12.5px; padding: 9px 13px;">
            <?= $_SESSION['message'] ?>
        </div>
        <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
    <div class="summary-strip">
        <?php foreach ($typeMeta as $type => $meta):
            if ($filterType && $filterType !== $type) continue;
            if (empty($trees[$type]['tops'])) continue;
        ?>
            <div class="sum-card" style="border-left-color: <?= $meta['color'] ?>;">
                <div class="lbl"><?= $meta['label'] ?>s</div>
                <div class="val"><?= number_format($typeTotals[$type], 2) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="main-card">
        <div class="main-toolbar">
            <div class="title"><i class="fas fa-list-ul"></i> All Accounts</div>
            <button type="button" class="expand-toggle" id="toggleAll">
                <i class="fas fa-compress-arrows-alt"></i> <span>Collapse All</span>
            </button>
           
        </div>

        <?php if (empty($accounts)): ?>
            <div class="no-accounts">
                <div style="font-size: 40px; opacity: 0.4; margin-bottom: 8px;"><i class="fas fa-sitemap"></i></div>
                No accounts found.
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="coa-table">
                    <thead>
                        <tr>
                            <th style="width: 90px;">Code</th>
                            <th>Account</th>
                            <th style="width: 120px;">Type</th>
                            <?php if ($isAdmin): ?>
                                <th class="num" style="width: 150px;">Balance</th>
                            <?php endif; ?>
                            <?php if (!empty($perms['can_edit']) || !empty($perms['can_add'])): ?>
                                <th class="act" style="width: 200px;">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody id="coaTbody">
                        <?php foreach ($trees as $type => $data):
                            if ($filterType && $filterType !== $type) continue;
                            if (empty($data['tops'])) continue;
                            $meta = $typeMeta[$type];

                            foreach ($data['tops'] as $parent):
                                $pBalance = $accountBalances[(int)$parent['id']] ?? 0.0;
                                $aggBalance = $pBalance;
                                if (isset($data['children'][(int)$parent['id']])) {
                                    foreach ($data['children'][(int)$parent['id']] as $c) {
                                        $aggBalance += $accountBalances[(int)$c['id']] ?? 0.0;
                                    }
                                }
                                $hasChildren = !empty($data['children'][(int)$parent['id']]);
                                $childId = 'children-' . (int)$parent['id'];
                        ?>
                            <tr class="parent-row"
                                data-type="<?= $type ?>"
                                data-children="<?= $childId ?>"
                                data-open="1"
                                data-code="<?= htmlspecialchars($parent['code']) ?>"
                                data-name="<?= htmlspecialchars(mb_strtolower($parent['name'])) ?>">
                                <td class="code" data-label="Code">
                                    <?php if ($hasChildren): ?>
                                        <i class="fas fa-chevron-down row-open"></i>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($parent['code']) ?>
                                </td>
                                <td class="acc-name parent" data-label="Account">
                                    <span class="type-badge" style="background: <?= $meta['bg'] ?>; color: <?= $meta['color'] ?>;"><?= $meta['short'] ?></span>
                                    <?= htmlspecialchars($parent['name']) ?>
                                    <?php if (!empty($parent['is_contra'])): ?>
                                        <span class="badge-contra">Contra</span>
                                    <?php endif; ?>
                                    <?php if ($hasChildren): ?>
                                        <span class="sub-group-toggle">(<?= count($data['children'][(int)$parent['id']]) ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Type">
                                    <span style="font-size: 11px; font-weight: 700; color: <?= $meta['color'] ?>;"><?= $meta['label'] ?></span>
                                </td>
                                <?php if ($isAdmin): ?>
                                    <td class="balance parent-total <?= abs($aggBalance) < 0.005 ? 'zero' : '' ?>" data-label="Balance">
                                        <?= number_format($aggBalance, 2) ?>
                                    </td>
                                <?php endif; ?>
                                <?php if (!empty($perms['can_edit']) || !empty($perms['can_add'])): ?>
                                    <td class="actions-cell" data-label="Actions">
                                        <?php if (!empty($perms['can_add'])): ?>
                                            <a href="gl_account_add.php?parent_id=<?= (int)$parent['id'] ?>" class="action-btn add" title="Add sub-account">
                                                <i class="fas fa-plus"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="gl_account_ledger.php?id=<?= (int)$parent['id'] ?>" class="action-btn ledger">
                                            <i class="fas fa-book"></i> Ledger
                                        </a>
                                        <?php if (!empty($perms['can_edit'])): ?>
                                            <a href="gl_account_edit.php?id=<?= (int)$parent['id'] ?>" class="action-btn edit">
                                                <i class="fas fa-pen"></i>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>

                            <?php if ($hasChildren): ?>
                                <?php foreach ($data['children'][(int)$parent['id']] as $child):
                                    $cBalance = $accountBalances[(int)$child['id']] ?? 0.0;
                                ?>
                                    <tr class="child-row"
                                        data-parent="<?= $childId ?>"
                                        data-code="<?= htmlspecialchars($child['code']) ?>"
                                        data-name="<?= htmlspecialchars(mb_strtolower($child['name'])) ?>">
                                        <td class="code" data-label="Code"><?= htmlspecialchars($child['code']) ?></td>
                                        <td class="acc-name child" data-label="Account">
                                            <?= htmlspecialchars($child['name']) ?>
                                            <?php if (!empty($child['is_contra'])): ?>
                                                <span class="badge-contra">Contra</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Type">
                                            <span style="font-size: 11px; color: #94a3b8;">&mdash;</span>
                                        </td>
                                        <?php if ($isAdmin): ?>
                                            <td class="balance <?= abs($cBalance) < 0.005 ? 'zero' : '' ?>" data-label="Balance">
                                                <?= number_format($cBalance, 2) ?>
                                            </td>
                                        <?php endif; ?>
                                        <?php if (!empty($perms['can_edit']) || !empty($perms['can_add'])): ?>
                                            <td class="actions-cell" data-label="Actions">
                                                <a href="gl_account_ledger.php?id=<?= (int)$child['id'] ?>" class="action-btn ledger">
                                                    <i class="fas fa-book"></i> Ledger
                                                </a>
                                                <?php if (!empty($perms['can_edit'])): ?>
                                                    <a href="gl_account_edit.php?id=<?= (int)$child['id'] ?>" class="action-btn edit">
                                                        <i class="fas fa-pen"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>

                        <?php endforeach; endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div id="searchEmpty" class="search-empty" style="display:none;">
                <div class="big"><i class="fas fa-search"></i></div>
                No accounts match your search.
            </div>
        <?php endif; ?>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {

    /* ---------- Collapse / expand ---------- */
    function updateToggleLabel() {
        var anyOpen = $('.parent-row[data-open="1"]').length > 0;
        $('#toggleAll span').text(anyOpen ? 'Collapse All' : 'Expand All');
        $('#toggleAll i').removeClass('fa-compress-arrows-alt fa-expand-arrows-alt')
            .addClass(anyOpen ? 'fa-compress-arrows-alt' : 'fa-expand-arrows-alt');
    }

    function setChildrenVisible(parentRow, visible) {
        var childId = parentRow.data('children');
        $('tr.child-row[data-parent="' + childId + '"]').toggle(visible);
        parentRow.attr('data-open', visible ? '1' : '0');
        parentRow.toggleClass('collapsed', !visible);
    }

    $(document).on('click', 'tr.parent-row', function(e) {
        if ($(e.target).closest('a, button').length) return;
        var isOpen = $(this).attr('data-open') === '1';
        setChildrenVisible($(this), !isOpen);
        updateToggleLabel();
    });

    $('#toggleAll').on('click', function() {
        var anyOpen = $('.parent-row[data-open="1"]').length > 0;
        $('.parent-row').each(function() {
            setChildrenVisible($(this), !anyOpen);
        });
        updateToggleLabel();
    });

    updateToggleLabel();

    /* ---------- Live search ---------- */
    var $tbody       = $('#coaTbody');
    var $searchInput = $('#coaSearch');
    var $searchBox   = $('#searchBox');
    var $clearBtn    = $('#searchClear');
    var $searchEmpty = $('#searchEmpty');
    var $countChip   = $('#countChip');
    var originalCountText = $countChip.text();

    function runSearch() {
        var q = ($searchInput.val() || '').trim().toLowerCase();

        /* clear button visibility */
        $searchBox.toggleClass('has-value', q.length > 0);

        if (q === '') {
            /* Reset — everything visible, restore collapse state */
            $tbody.find('tr.parent-row').each(function() {
                $(this).show();
                var open = $(this).attr('data-open') === '1';
                setChildrenVisible($(this), open);
            });
            $searchEmpty.hide();
            $countChip.text(originalCountText);
            updateToggleLabel();
            return;
        }

        var matchedParents = 0;
        var matchedChildren = 0;
        var parentChildMap = {};

        /* First pass — mark parent matches */
        $tbody.find('tr.parent-row').each(function() {
            var $p   = $(this);
            var code = String($p.data('code') || '').toLowerCase();
            var name = String($p.data('name') || '').toLowerCase();
            var match = code.indexOf(q) !== -1 || name.indexOf(q) !== -1;
            $p.data('search-parent-match', match);
            parentChildMap[$p.data('children')] = { row: $p, matched: match, anyChildMatched: false };
        });

        /* Second pass — mark child matches */
        $tbody.find('tr.child-row').each(function() {
            var $c   = $(this);
            var code = String($c.data('code') || '').toLowerCase();
            var name = String($c.data('name') || '').toLowerCase();
            var match = code.indexOf(q) !== -1 || name.indexOf(q) !== -1;
            var parentKey = $c.data('parent');
            if (match && parentChildMap[parentKey]) {
                parentChildMap[parentKey].anyChildMatched = true;
                matchedChildren++;
            }
            $c.data('search-child-match', match);
        });

        /* Apply visibility */
        $.each(parentChildMap, function(key, info) {
            var parentVisible = info.matched || info.anyChildMatched;
            info.row.toggle(parentVisible);
            if (parentVisible) matchedParents++;

            /* For matching parents show all children; for non-matching parents show only matching children */
            $tbody.find('tr.child-row[data-parent="' + key + '"]').each(function() {
                var $c = $(this);
                var childMatch = $c.data('search-child-match') === true;
                if (info.matched) {
                    /* parent matched — show all its children */
                    $c.show();
                    parentVisible = true;
                } else {
                    $c.toggle(childMatch);
                }
            });

            /* Force-open the parent row when it has any visible children */
            if (parentVisible) {
                info.row.attr('data-open', '1').removeClass('collapsed');
                info.row.find('.row-open').css('transform', '');
            }
        });

        var anyVisible = matchedParents > 0;
        $searchEmpty.toggle(!anyVisible);

        if (anyVisible) {
            $countChip.text(matchedParents + ' group' + (matchedParents === 1 ? '' : 's') +
                            (matchedChildren > 0 ? ' · ' + matchedChildren + ' match' + (matchedChildren === 1 ? '' : 'es') : ''));
        } else {
            $countChip.text('0 matches');
        }
    }

    $searchInput.on('input', runSearch);
    $clearBtn.on('click', function() {
        $searchInput.val('').trigger('input').focus();
    });

    /* ---------- Alerts auto-dismiss ---------- */
    setTimeout(function() { $('.alert').fadeOut('slow'); }, 5000);
});
</script>

</body>
</html>
<?php $conn->close(); ?>