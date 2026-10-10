<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

$msg = $_SESSION['msg'] ?? ''; $msg_type = $_SESSION['msg_type'] ?? '';
unset($_SESSION['msg'], $_SESSION['msg_type']);

if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $check = $conn->prepare("SELECT status FROM bank_reconciliations WHERE id = ?");
    $check->bind_param("i", $id);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$row) {
        $_SESSION['msg'] = 'Reconciliation not found.'; $_SESSION['msg_type'] = 'danger';
    } elseif ($row['status'] !== 'DRAFT') {
        $_SESSION['msg'] = 'Only DRAFT reconciliations can be deleted.'; $_SESSION['msg_type'] = 'danger';
    } else {
        $conn->query("DELETE FROM bank_reconciliations WHERE id = $id");
        $_SESSION['msg'] = 'Draft reconciliation deleted.'; $_SESSION['msg_type'] = 'success';
    }
    header("Location: gl_bank_recon.php");
    exit;
}

$recons = $conn->query("
    SELECT r.*, a.code AS account_code, a.name AS account_name,
           (SELECT COUNT(*) FROM bank_statement_lines WHERE reconciliation_id = r.id) AS line_count,
           (SELECT COUNT(*) FROM bank_statement_lines WHERE reconciliation_id = r.id AND matched_line_id IS NOT NULL) AS matched_count
    FROM bank_reconciliations r
    LEFT JOIN gl_accounts a ON a.id = r.gl_account_id
    ORDER BY r.statement_date DESC, r.id DESC
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Bank Reconciliation</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    * { box-sizing: border-box; }
    body { background: #eef1f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 0; color: #1e293b; }
    .wrap { max-width: 1300px; margin: 30px auto; padding: 0 20px; }

    .head {
        background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
        color: #fff; padding: 30px 40px 26px 40px; border-radius: 12px 12px 0 0;
        box-shadow: 0 4px 16px rgba(30, 64, 175, 0.15);
        display: flex; justify-content: space-between; align-items: flex-end; gap: 20px; flex-wrap: wrap;
    }
    .head h1 { margin: 0; font-size: 30px; font-weight: 700; }
    .head .sub { font-size: 14px; opacity: 0.9; margin-top: 6px; }

    .btn-new {
        display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        padding: 10px 20px;
        background: #ffffff;
        color: #1e40af;
        border: 1px solid #ffffff;
        border-radius: 8px;
        text-decoration: none;
        font-size: 14px;
        font-weight: 600;
        line-height: 1;
        font-family: inherit;
        white-space: nowrap;
    }
    .btn-new:hover {
        background: #e0e7ff;
        border-color: #e0e7ff;
        color: #1e40af;
        text-decoration: none;
    }

    .card {
        background: #fff; padding: 30px 40px 40px 40px;
        border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    }
    .section-title {
        font-size: 12px; font-weight: 800; color: #64748b; text-transform: uppercase;
        letter-spacing: 2px; margin: 0 0 14px 0; padding-bottom: 8px;
        border-bottom: 1px solid #e2e8f0;
    }
    .alert-custom { padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; border-left: 4px solid; }
    .alert-custom.success { background: #d1fae5; border-color: #059669; color: #065f46; }
    .alert-custom.danger  { background: #fee2e2; border-color: #dc2626; color: #991b1b; }

    table.tbl { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; }
    table.tbl thead th {
        background: #f8fafc; font-size: 11px; color: #64748b; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px; padding: 12px 14px;
        text-align: left; border-bottom: 2px solid #e2e8f0;
    }
    table.tbl tbody td { padding: 12px 14px; font-size: 13px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    table.tbl tbody tr:hover td { background: #f8fafc; }
    .num { font-family: 'SF Mono','Monaco',monospace; text-align: right; font-variant-numeric: tabular-nums; }

    .pill { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
    .pill.draft { background: #fef3c7; color: #92400e; }
    .pill.completed { background: #d1fae5; color: #065f46; }

    .row-btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 5px;
        padding: 7px 14px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 700;
        text-decoration: none;
        border: 1px solid;
        font-family: inherit;
        line-height: 1;
        white-space: nowrap;
        cursor: pointer;
        transition: all 0.15s;
    }
    .row-btn-info { background: #1e40af; color: #ffffff; border-color: #1e40af; }
    .row-btn-info:hover { background: #1e3a8a; border-color: #1e3a8a; text-decoration: none; color: #ffffff; }
    .row-btn-danger { background: #dc2626; color: #ffffff; border-color: #dc2626; }
    .row-btn-danger:hover { background: #b91c1c; border-color: #b91c1c; text-decoration: none; color: #ffffff; }
    .row-btn .glyphicon { color: inherit; }

    .empty-state { padding: 40px; text-align: center; color: #94a3b8; font-style: italic; font-size: 14px; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <h1>Bank Reconciliation</h1>
            <div class="sub">Match your ledger (account 1020) against the bank's monthly statement</div>
        </div>
        <a href="gl_bank_recon_new.php" class="btn-new">
            <span class="glyphicon glyphicon-plus"></span> New Reconciliation
        </a>
    </div>

    <div class="card">

        <?php if ($msg): ?>
            <div class="alert-custom <?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <div class="section-title">All Reconciliations</div>

        <?php if (empty($recons)): ?>
            <div class="empty-state">No reconciliations yet. Click <strong>New Reconciliation</strong> to begin.</div>
        <?php else: ?>
            <table class="tbl">
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th>Account</th>
                        <th style="width:110px;">Statement Date</th>
                        <th class="num" style="width:130px;">Bank Closing</th>
                        <th class="num" style="width:130px;">Book Balance</th>
                        <th class="num" style="width:120px;">Difference</th>
                        <th style="width:120px;">Progress</th>
                        <th style="width:110px;">Status</th>
                        <th style="width:220px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recons as $r):
                        $balanced = abs((float)$r['difference']) < 0.01;
                    ?>
                        <tr>
                            <td>#<?= (int)$r['id'] ?></td>
                            <td>
                                
                                <?= htmlspecialchars($r['account_name']) ?>
                            </td>
                            <td><?= htmlspecialchars($r['statement_date']) ?></td>
                            <td class="num"><?= number_format((float)$r['closing_balance'], 2) ?></td>
                            <td class="num"><?= number_format((float)$r['book_balance'], 2) ?></td>
                            <td class="num" style="color:<?= $balanced ? '#047857' : '#b91c1c' ?>; font-weight:700;">
                                <?= number_format((float)$r['difference'], 2) ?>
                            </td>
                            <td>
                                <?= (int)$r['matched_count'] ?> / <?= (int)$r['line_count'] ?>
                                <div style="height:4px; background:#e2e8f0; border-radius:2px; margin-top:4px; overflow:hidden;">
                                    <?php $pct = $r['line_count'] > 0 ? ($r['matched_count'] / $r['line_count']) * 100 : 0; ?>
                                    <div style="width:<?= number_format($pct, 0) ?>%; height:100%; background:#1e40af;"></div>
                                </div>
                            </td>
                            <td><span class="pill <?= strtolower($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                            <td>
                                <a href="gl_bank_recon_view.php?id=<?= (int)$r['id'] ?>" class="row-btn row-btn-info">
                                    <span class="glyphicon glyphicon-cog"></span>
                                    <?= $r['status'] === 'DRAFT' ? 'Open' : 'View' ?>
                                </a>
                                <?php if ($r['status'] === 'DRAFT'): ?>
                                    <a href="?delete=<?= (int)$r['id'] ?>" class="row-btn row-btn-danger"
                                       onclick="return confirm('Delete this draft reconciliation?');">
                                        <span class="glyphicon glyphicon-trash"></span> Delete
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    </div>
</div>

</body>
</html>