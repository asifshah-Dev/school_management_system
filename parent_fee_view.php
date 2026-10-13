<?php
header('Content-Type: text/html; charset=utf-8');
require_once('security.php');
require_once('conn_inc.php');
require_once('permissions.php');

$roleId = (int)($_SESSION['role_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = ($roleId === 1);
$isParent = ($roleId === 6);

if (!$isParent && !$isAdmin) { header("Location: 403.php"); exit(); }

$student_id = (int)($_GET['student_id'] ?? 0);
if ($student_id <= 0) { header("Location: parent_dashboard.php"); exit(); }

// Ownership check
if ($isParent && !$isAdmin) {
    $stmt = $conn->prepare("SELECT 1 FROM parent_students WHERE parent_user_id = ? AND student_id = ? LIMIT 1");
    $stmt->bind_param("ii", $userId, $student_id);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) { header("Location: 403.php"); exit(); }
    $stmt->close();
}

// Student header info
$stmt = $conn->prepare("
    SELECT sr.id, sr.name, sr.father_name, sr.reg_no, sr.image_path,
           c.title AS class_title, s.title AS section_title, ses.title AS session_title
    FROM student_registration sr
    LEFT JOIN (
        SELECT sc1.student_registration_id, sc1.class_id, sc1.class_section_id, sc1.session_id
        FROM student_class sc1
        INNER JOIN (SELECT student_registration_id, MAX(id) AS max_id FROM student_class GROUP BY student_registration_id) sc2
            ON sc1.id = sc2.max_id
    ) sc ON sr.id = sc.student_registration_id
    LEFT JOIN classes c ON sc.class_id = c.id
    LEFT JOIN class_sections cs ON sc.class_section_id = cs.id
    LEFT JOIN sections s ON cs.section_id = s.id
    LEFT JOIN sessions ses ON sc.session_id = ses.id
    WHERE sr.id = ?
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) { header("Location: parent_dashboard.php"); exit(); }

// Fee cards
$stmt = $conn->prepare("
    SELECT sfc.id, sfc.total_amount, sfc.paid_amount, sfc.discount_amount,
           sfc.due_date, sfc.month, sfc.status, sfc.remarks,
           ft.title AS fee_type_title, ft.type AS fee_type_kind,
           sc.session_id, ses.title AS session_title,
           c.title AS class_title
    FROM student_fee_card sfc
    INNER JOIN student_class sc ON sfc.student_class_id = sc.id
    INNER JOIN fee_types ft    ON sfc.fee_type_id = ft.id
    LEFT JOIN sessions ses     ON sfc.session_id = ses.id
    LEFT JOIN classes c        ON sc.class_id = c.id
    WHERE sc.student_registration_id = ?
    ORDER BY sfc.due_date DESC, sfc.id DESC
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$fee_cards = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Payments per card
$payments_by_card = [];
if (!empty($fee_cards)) {
    $ids = array_column($fee_cards, 'id');
    $in = implode(',', array_map('intval', $ids));
    $pr = $conn->query("
        SELECT fee_card_id, paid_amount, payment_date, payment_method, transaction_ref, remarks
        FROM student_fee_payments
        WHERE fee_card_id IN ($in)
        ORDER BY payment_date DESC, id DESC
    ");
    while ($row = $pr->fetch_assoc()) {
        $payments_by_card[(int)$row['fee_card_id']][] = $row;
    }
}

// Totals
$total_billed = 0; $total_paid = 0; $total_outstanding = 0;
foreach ($fee_cards as $fc) {
    $total_billed += (float)$fc['total_amount'];
    $total_paid   += (float)$fc['paid_amount'];
    $total_outstanding += ((float)$fc['total_amount'] - (float)$fc['paid_amount']);
}

$month_names = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];
$initials = strtoupper(substr(preg_replace('/\s+/', '', $student['name'] ?: 'S'), 0, 2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Fee Details — <?= htmlspecialchars($student['name']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Inter', sans-serif; background: #f5f7fa; }
    .pf-header {
        background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
        color: #fff; padding: 26px 30px; border-radius: 12px;
        margin-bottom: 22px; display: flex; align-items: center; gap: 20px;
    }
    .pf-photo {
        width: 64px; height: 64px; border-radius: 50%;
        background: rgba(255,255,255,0.2); border: 2px solid rgba(255,255,255,0.4);
        display: flex; align-items: center; justify-content: center;
        font-size: 22px; font-weight: 700; flex-shrink: 0;
        object-fit: cover;
    }
    .pf-header h1 { font-size: 22px; font-weight: 700; margin: 0; }
    .pf-header .sub { font-size: 13px; opacity: 0.9; margin-top: 4px; }

    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 22px; }
    .stat { background: #fff; border-radius: 10px; padding: 16px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border-left: 4px solid #1e40af; }
    .stat .lbl { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
    .stat .val { font-size: 22px; font-weight: 700; color: #0f172a; font-family: 'SF Mono', 'Monaco', monospace; }
    .stat.pending { border-left-color: #dc2626; }
    .stat.pending .val { color: #dc2626; }
    .stat.paid { border-left-color: #059669; }
    .stat.paid .val { color: #059669; }

    .card-block {
        background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        margin-bottom: 16px; overflow: hidden;
    }
    .card-head {
        padding: 16px 22px; border-bottom: 1px solid #f1f5f9;
        display: flex; justify-content: space-between; align-items: center;
        background: #fafbfc;
    }
    .card-head .title { font-size: 15px; font-weight: 700; color: #0f172a; }
    .card-head .meta { font-size: 12px; color: #64748b; margin-top: 3px; }
    .card-body-inner { padding: 18px 22px; }

    .status-pill {
        display: inline-block; padding: 3px 12px; border-radius: 12px;
        font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
    }
    .status-pill.pending { background: #fee2e2; color: #991b1b; }
    .status-pill.partial { background: #fef3c7; color: #92400e; }
    .status-pill.paid    { background: #d1fae5; color: #065f46; }

    .money-row {
        display: flex; gap: 24px; flex-wrap: wrap; margin-bottom: 8px;
        font-size: 13px;
    }
    .money-row span { color: #64748b; }
    .money-row strong { color: #0f172a; font-family: 'SF Mono', monospace; }

    .pay-table { width: 100%; margin-top: 14px; border-collapse: collapse; font-size: 12px; }
    .pay-table th { background: #f8fafc; padding: 8px 10px; text-align: left; color: #64748b; font-weight: 600; text-transform: uppercase; font-size: 10px; letter-spacing: 0.5px; border-bottom: 1px solid #e2e8f0; }
    .pay-table td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; }
    .pay-table .amount { text-align: right; font-family: 'SF Mono', monospace; font-weight: 600; color: #059669; }

    .empty-state { background: #fff; border-radius: 12px; padding: 50px 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .empty-state .big { font-size: 44px; opacity: 0.4; margin-bottom: 12px; }
    .empty-state h3 { font-size: 17px; color: #475569; font-weight: 600; }

    .back-btn {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 8px 16px; background: #fff; color: #334155;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 13px; font-weight: 600; text-decoration: none;
        margin-bottom: 14px;
    }
    .back-btn:hover { background: #f1f5f9; text-decoration: none; color: #1e293b; }
</style>
</head>
<body>
<?php require_once('navbar.php'); ?>

<div class="container" style="padding: 20px; max-width: 1000px; margin: 0 auto;">

    <a href="parent_dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to dashboard</a>

    <div class="pf-header">
        <?php if (!empty($student['image_path']) && file_exists($student['image_path'])): ?>
            <img src="<?= htmlspecialchars($student['image_path']) ?>" class="pf-photo" alt="">
        <?php else: ?>
            <div class="pf-photo"><?= htmlspecialchars($initials) ?></div>
        <?php endif; ?>
        <div style="flex:1;">
            <h1><?= htmlspecialchars($student['name']) ?></h1>
            <div class="sub">
                s/o <?= htmlspecialchars($student['father_name'] ?: '—') ?>
                · <?= htmlspecialchars($student['class_title'] ?: 'No class') ?><?= $student['section_title'] ? ' - ' . htmlspecialchars($student['section_title']) : '' ?>
                · Reg# <?= htmlspecialchars($student['reg_no']) ?>
            </div>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat">
            <div class="lbl">Total Billed</div>
            <div class="val">Rs. <?= number_format($total_billed, 0) ?></div>
        </div>
        <div class="stat paid">
            <div class="lbl">Total Paid</div>
            <div class="val">Rs. <?= number_format($total_paid, 0) ?></div>
        </div>
        <div class="stat <?= $total_outstanding > 0 ? 'pending' : '' ?>">
            <div class="lbl">Outstanding</div>
            <div class="val">Rs. <?= number_format($total_outstanding, 0) ?></div>
        </div>
        <div class="stat">
            <div class="lbl">Fee Cards</div>
            <div class="val"><?= count($fee_cards) ?></div>
        </div>
    </div>

    <?php if (empty($fee_cards)): ?>
        <div class="empty-state">
            <div class="big">💵</div>
            <h3>No fee records yet</h3>
        </div>
    <?php else: ?>
        <?php foreach ($fee_cards as $fc):
            $cid = (int)$fc['id'];
            $status = strtolower($fc['status'] ?: 'pending');
            $outstanding = (float)$fc['total_amount'] - (float)$fc['paid_amount'];
        ?>
        <div class="card-block">
            <div class="card-head">
                <div>
                    <div class="title"><?= htmlspecialchars($fc['fee_type_title']) ?></div>
                    <div class="meta">
                        <?= htmlspecialchars($month_names[(int)$fc['month']] ?? '') ?>
                        · Due <?= htmlspecialchars($fc['due_date']) ?>
                        <?php if ($fc['session_title']): ?> · <?= htmlspecialchars($fc['session_title']) ?><?php endif; ?>
                    </div>
                </div>
                <div>
                    <span class="status-pill <?= $status ?>"><?= htmlspecialchars($status) ?></span>
                </div>
            </div>
            <div class="card-body-inner">
                <div class="money-row">
                    <div>Total: <strong>Rs. <?= number_format((float)$fc['total_amount'], 0) ?></strong></div>
                    <div>Paid: <strong>Rs. <?= number_format((float)$fc['paid_amount'], 0) ?></strong></div>
                    <?php if ((float)$fc['discount_amount'] > 0): ?>
                        <div>Discount: <strong>Rs. <?= number_format((float)$fc['discount_amount'], 0) ?></strong></div>
                    <?php endif; ?>
                    <div>Outstanding: <strong style="color: <?= $outstanding > 0 ? '#dc2626' : '#059669' ?>;">Rs. <?= number_format($outstanding, 0) ?></strong></div>
                </div>

                <?php if ($fc['remarks']): ?>
                    <div style="font-size: 12px; color: #64748b; margin-top: 6px;">
                        <i class="fas fa-sticky-note"></i> <?= htmlspecialchars($fc['remarks']) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($payments_by_card[$cid])): ?>
                    <table class="pay-table">
                        <thead>
                            <tr><th>Date</th><th>Method</th><th>Reference</th><th style="text-align:right;">Amount</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments_by_card[$cid] as $p): ?>
                            <tr>
                                <td><?= htmlspecialchars($p['payment_date']) ?></td>
                                <td><?= htmlspecialchars($p['payment_method'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($p['transaction_ref'] ?: '—') ?></td>
                                <td class="amount">Rs. <?= number_format((float)$p['paid_amount'], 0) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>
</body>
</html>
<?php $conn->close(); ?>