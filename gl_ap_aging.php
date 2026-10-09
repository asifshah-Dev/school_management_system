<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

$asOf = $_GET['as_of'] ?? date('Y-m-d');

/* ============================================================
   Build the AP aging data.
   For each supplier:
     1. Collect every CREDIT on 2010 (their bills) — dated
     2. Collect every DEBIT on 2010 (payments) — dated
     3. FIFO-apply payments to oldest bills first
     4. Anything still unpaid → age = as_of - bill_date
     5. Bucket into 0-30 / 31-60 / 61-90 / 90+
   ============================================================ */

$suppliers = gl_list_suppliers($conn, true);   // include inactive — you may still owe them

$aging = [];        // [supplier_id] => ['name', 'current', 'd30', 'd60', 'd90', 'total', 'bills' => [...]]
$totals = ['current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'total' => 0];

foreach ($suppliers as $s) {
    $sid = (int)$s['id'];

    // Fetch every line on 2010 for this supplier as of the given date
    $stmt = $conn->prepare("
        SELECT l.id, t.entry_date, l.debit, l.credit, t.description
        FROM gl_journal_lines l
        JOIN gl_transactions t ON t.id = l.transaction_id
        JOIN gl_accounts a ON a.id = l.account_id
        WHERE a.code = '2010'
          AND l.party_id = ?
          AND t.entry_date <= ?
        ORDER BY t.entry_date ASC, t.id ASC, l.id ASC
    ");
    $stmt->bind_param("is", $sid, $asOf);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Split into bills (credits) and payments (debits)
    $bills    = [];   // [ ['date'=>'...', 'remaining'=>float, 'desc'=>...] ]
    $payments = [];   // [ ['date'=>'...', 'amount'=>float] ]

    foreach ($rows as $r) {
        $cr = (float)$r['credit'];
        $dr = (float)$r['debit'];
        if ($cr > 0.005) {
            $bills[] = [
                'date'      => $r['entry_date'],
                'remaining' => $cr,
                'desc'      => $r['description'],
            ];
        }
        if ($dr > 0.005) {
            $payments[] = [
                'date'   => $r['entry_date'],
                'amount' => $dr,
            ];
        }
    }

    // Apply payments FIFO across bills
    foreach ($payments as $p) {
        $remaining = $p['amount'];
        foreach ($bills as &$b) {
            if ($remaining <= 0) break;
            if ($b['remaining'] <= 0) continue;
            $apply = min($remaining, $b['remaining']);
            $b['remaining'] -= $apply;
            $remaining      -= $apply;
        }
        unset($b);
    }

    // Bucket the still-unpaid bills
    $sAging = [
        'id' => $sid,
        'name' => $s['name'],
        'phone' => $s['phone'] ?: $s['mobile'],
        'current' => 0.0,   // 0–30 days
        'd30' => 0.0,       // 31–60
        'd60' => 0.0,       // 61–90
        'd90' => 0.0,       // 90+
        'total' => 0.0,
        'oldest' => null,
        'bills' => [],
    ];

    foreach ($bills as $b) {
        if ($b['remaining'] <= 0.005) continue;

        $days = (int)floor((strtotime($asOf) - strtotime($b['date'])) / 86400);
        if ($days < 0) $days = 0;

        if ($days <= 30)       $sAging['current'] += $b['remaining'];
        elseif ($days <= 60)   $sAging['d30']     += $b['remaining'];
        elseif ($days <= 90)   $sAging['d60']     += $b['remaining'];
        else                   $sAging['d90']     += $b['remaining'];

        $sAging['total'] += $b['remaining'];
        if ($sAging['oldest'] === null || $b['date'] < $sAging['oldest']) {
            $sAging['oldest'] = $b['date'];
        }
        $b['days'] = $days;
        $sAging['bills'][] = $b;
    }

    // Only show suppliers that actually have an unpaid balance
    if (abs($sAging['total']) > 0.005) {
        $aging[] = $sAging;
        $totals['current'] += $sAging['current'];
        $totals['d30']     += $sAging['d30'];
        $totals['d60']     += $sAging['d60'];
        $totals['d90']     += $sAging['d90'];
        $totals['total']   += $sAging['total'];
    }
}

// Sort by total DESC
usort($aging, fn($a, $b) => $b['total'] <=> $a['total']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AP Aging — Dar-e-Arqm School</title>
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
        cursor: pointer; font-family: inherit;
        transition: all 0.15s;
    }
    .btn-new:hover { background: #fff; color: #1e40af; text-decoration: none; border-color: #fff; }

    .card {
        background: #fff;
        padding: 30px 40px 40px 40px;
        border-radius: 0 0 12px 12px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    }

    .toolbar {
        display: flex; gap: 12px; flex-wrap: wrap;
        align-items: center; margin-bottom: 20px;
        padding-bottom: 20px; border-bottom: 1px solid #e2e8f0;
    }
    .toolbar label {
        font-size: 12px; font-weight: 700; color: #475569;
        text-transform: uppercase; letter-spacing: 1px;
    }
    .toolbar input[type=date] {
        height: 40px; padding: 8px 12px;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 14px; font-family: inherit;
    }
    .toolbar input[type=date]:focus {
        outline: none; border-color: #1e40af;
        box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
    }
    .btn-primary-custom {
        height: 40px; padding: 0 18px;
        background: #1e40af; color: #fff;
        border: none; border-radius: 8px;
        font-size: 13px; font-weight: 600;
        cursor: pointer; font-family: inherit;
        display: inline-flex; align-items: center; gap: 6px;
    }
    .btn-primary-custom:hover { background: #1e3a8a; }
    .btn-soft {
        height: 40px; padding: 0 16px;
        background: #f1f5f9; color: #334155;
        border: 1px solid #e2e8f0; border-radius: 8px;
        font-size: 13px; font-weight: 600;
        cursor: pointer; font-family: inherit;
        display: inline-flex; align-items: center; gap: 6px;
        text-decoration: none;
    }
    .btn-soft:hover { background: #e2e8f0; color: #1e293b; text-decoration: none; }

    table.tbl {
        width: 100%; border-collapse: collapse;
        background: #fff;
        border: 1px solid #e2e8f0; border-radius: 8px;
        overflow: hidden;
    }
    table.tbl thead th {
        background: #f8fafc;
        font-size: 11px; color: #64748b; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px;
        padding: 12px 14px; text-align: left;
        border-bottom: 2px solid #e2e8f0;
        white-space: nowrap;
    }
    table.tbl tbody td {
        padding: 11px 14px;
        font-size: 13px; color: #1e293b;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }
    table.tbl tbody tr:hover td { background: #f8fafc; }
    table.tbl tfoot td {
        padding: 12px 14px;
        font-weight: 700; background: #f1f5f9;
        border-top: 2px solid #cbd5e1;
        font-size: 13px;
    }
    .num {
        font-family: 'SF Mono', 'Monaco', monospace;
        font-variant-numeric: tabular-nums;
        text-align: right;
    }
    .col-current { color: #047857; }
    .col-d30     { color: #b45309; }
    .col-d60     { color: #b45309; }
    .col-d90     { color: #991b1b; font-weight: 700; }
    .total-col   { color: #0f172a; font-weight: 700; }

    .badge-days {
        display: inline-block; padding: 2px 8px;
        border-radius: 10px; font-size: 11px; font-weight: 700;
        background: #dbeafe; color: #1e40af;
    }

    .empty-state {
        padding: 40px; text-align: center;
        color: #94a3b8; font-style: italic;
    }

    .summary-cards {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 14px; margin-bottom: 24px;
    }
    .summary-card {
        background: #fff; border: 1px solid #e2e8f0;
        border-radius: 10px; padding: 16px 20px;
        border-left: 5px solid;
    }
    .summary-card .lbl {
        font-size: 11px; color: #64748b; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px;
        margin-bottom: 6px;
    }
    .summary-card .val {
        font-family: 'SF Mono', 'Monaco', monospace;
        font-size: 22px; font-weight: 700;
        font-variant-numeric: tabular-nums;
    }
    .summary-card.s-current { border-color: #059669; }
    .summary-card.s-d30     { border-color: #f59e0b; }
    .summary-card.s-d60     { border-color: #ea580c; }
    .summary-card.s-d90     { border-color: #dc2626; }
    .summary-card.s-total   { border-color: #1e40af; background: #f8fafc; }

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
            <h1>AP Aging Report</h1>
            <div class="sub">What the school owes each supplier, bucketed by age of unpaid bills</div>
        </div>
        <a href="gl_parties.php" class="btn-new">
            <span class="glyphicon glyphicon-user"></span> Suppliers
        </a>
    </div>

    <div class="card">

        <form class="toolbar no-print" method="get">
            <label>As of</label>
            <input type="date" name="as_of" value="<?= htmlspecialchars($asOf) ?>">
            <button type="submit" class="btn-primary-custom">
                <span class="glyphicon glyphicon-refresh"></span> Refresh
            </button>
            <a href="gl_ap_aging.php" class="btn-soft">Today</a>
            <button type="button" class="btn-soft" onclick="window.print()">
                <span class="glyphicon glyphicon-print"></span> Print
            </button>
        </form>

        <div class="summary-cards">
            <div class="summary-card s-current">
                <div class="lbl">0–30 days</div>
                <div class="val col-current"><?= number_format($totals['current'], 2) ?></div>
            </div>
            <div class="summary-card s-d30">
                <div class="lbl">31–60 days</div>
                <div class="val col-d30"><?= number_format($totals['d30'], 2) ?></div>
            </div>
            <div class="summary-card s-d60">
                <div class="lbl">61–90 days</div>
                <div class="val col-d60"><?= number_format($totals['d60'], 2) ?></div>
            </div>
            <div class="summary-card s-d90">
                <div class="lbl">90+ days</div>
                <div class="val col-d90"><?= number_format($totals['d90'], 2) ?></div>
            </div>
            <div class="summary-card s-total">
                <div class="lbl">Total Payable</div>
                <div class="val total-col"><?= number_format($totals['total'], 2) ?></div>
            </div>
        </div>

        <?php if (empty($aging)): ?>
            <div class="empty-state">No outstanding supplier balances as of <?= htmlspecialchars($asOf) ?>.</div>
        <?php else: ?>
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Supplier</th>
                        <th>Phone</th>
                        <th class="num" style="width:130px;">0–30 days</th>
                        <th class="num" style="width:130px;">31–60 days</th>
                        <th class="num" style="width:130px;">61–90 days</th>
                        <th class="num" style="width:130px;">90+ days</th>
                        <th class="num" style="width:140px;">Total</th>
                        <th style="width:120px;">Oldest Bill</th>
                        <th class="no-print" style="width:90px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($aging as $a):
                        $oldestDays = $a['oldest'] ? floor((strtotime($asOf) - strtotime($a['oldest'])) / 86400) : 0;
                    ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($a['name']) ?></strong></td>
                            <td><?= htmlspecialchars($a['phone']) ?></td>
                            <td class="num col-current"><?= $a['current'] > 0 ? number_format($a['current'], 2) : '—' ?></td>
                            <td class="num col-d30"><?= $a['d30'] > 0 ? number_format($a['d30'], 2) : '—' ?></td>
                            <td class="num col-d60"><?= $a['d60'] > 0 ? number_format($a['d60'], 2) : '—' ?></td>
                            <td class="num col-d90"><?= $a['d90'] > 0 ? number_format($a['d90'], 2) : '—' ?></td>
                            <td class="num total-col"><?= number_format($a['total'], 2) ?></td>
                            <td>
                                <?php if ($a['oldest']): ?>
                                    <span class="badge-days"><?= (int)$oldestDays ?> days</span>
                                    <small style="color:#94a3b8;"><?= htmlspecialchars($a['oldest']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="no-print">
                                <a href="gl_party_ledger.php?id=<?= (int)$a['id'] ?>" class="btn-soft" style="height:32px; padding:0 10px; font-size:12px;">
                                    Ledger
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2">TOTAL</td>
                        <td class="num"><?= number_format($totals['current'], 2) ?></td>
                        <td class="num"><?= number_format($totals['d30'], 2) ?></td>
                        <td class="num"><?= number_format($totals['d60'], 2) ?></td>
                        <td class="num"><?= number_format($totals['d90'], 2) ?></td>
                        <td class="num"><?= number_format($totals['total'], 2) ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>

            <p style="font-size:12px; color:#64748b; margin-top:16px;">
                <strong>Method:</strong> bills (credits to account 2010) are aged by invoice date.
                Payments are applied FIFO — oldest bills are paid off first.
                Anything still unpaid after applying all payments is what appears in each bucket.
            </p>
        <?php endif; ?>
    </div>
</div>

</body>
</html>