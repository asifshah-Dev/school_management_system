<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
/* ==== AUTHORIZATION GATE ==== */
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
/* ==== END AUTHORIZATION GATE ==== */
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

$userId   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
$isAdmin  = ($userId === 1);
$msg      = $_SESSION['budget_msg'] ?? '';
$msg_type = $_SESSION['budget_msg_type'] ?? '';
unset($_SESSION['budget_msg'], $_SESSION['budget_msg_type']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $sessionId = (int)($_POST['session_id'] ?? 0);
        $title     = trim($_POST['title'] ?? '');
        if ($sessionId <= 0 || $title === '') {
            $_SESSION['budget_msg'] = 'Session and title required.';
            $_SESSION['budget_msg_type'] = 'danger';
        } else {
            $existing = gl_get_budget_for_session($conn, $sessionId);
            if ($existing) {
                $_SESSION['budget_msg'] = 'A budget already exists for this session.';
                $_SESSION['budget_msg_type'] = 'danger';
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO gl_budgets (session_id, title, status, created_by)
                    VALUES (?, ?, 'DRAFT', ?)
                ");
                $stmt->bind_param("isi", $sessionId, $title, $userId);
                if ($stmt->execute()) {
                    $newId = $conn->insert_id;
                    $stmt->close();
                    header("Location: gl_budget_edit.php?id=$newId");
                    exit;
                }
                $stmt->close();
                $_SESSION['budget_msg'] = 'Create failed.';
                $_SESSION['budget_msg_type'] = 'danger';
            }
        }
        header("Location: gl_budget.php");
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("SELECT status FROM gl_budgets WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $budget = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($budget && $budget['status'] === 'DRAFT') {
            $stmt = $conn->prepare("DELETE FROM gl_budgets WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['budget_msg'] = 'Draft budget deleted.';
            $_SESSION['budget_msg_type'] = 'success';
        } else {
            $_SESSION['budget_msg'] = 'Only DRAFT budgets can be deleted.';
            $_SESSION['budget_msg_type'] = 'danger';
        }
        header("Location: gl_budget.php");
        exit;
    }

    if ($action === 'approve') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("UPDATE gl_budgets SET status='APPROVED', approved_by=?, approved_at=NOW() WHERE id=? AND status='DRAFT'");
        $stmt->bind_param("ii", $userId, $id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['budget_msg'] = 'Budget approved.';
        $_SESSION['budget_msg_type'] = 'success';
        header("Location: gl_budget.php");
        exit;
    }

    if ($action === 'lock') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("UPDATE gl_budgets SET status='LOCKED', locked_by=?, locked_at=NOW() WHERE id=? AND status='APPROVED'");
        $stmt->bind_param("ii", $userId, $id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['budget_msg'] = 'Budget locked.';
        $_SESSION['budget_msg_type'] = 'success';
        header("Location: gl_budget.php");
        exit;
    }

    if ($action === 'unlock') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$isAdmin) {
            $_SESSION['budget_msg'] = 'Only admin can unlock a LOCKED budget.';
            $_SESSION['budget_msg_type'] = 'danger';
        } else {
            $stmt = $conn->prepare("UPDATE gl_budgets SET status='APPROVED', locked_by=NULL, locked_at=NULL WHERE id=? AND status='LOCKED'");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['budget_msg'] = 'Budget unlocked (back to APPROVED).';
            $_SESSION['budget_msg_type'] = 'success';
        }
        header("Location: gl_budget.php");
        exit;
    }
}

$budgets = $conn->query("
    SELECT b.*, s.title AS session_title, s.from_dated, s.to_dated,
           u.username AS created_by_name,
           (SELECT COUNT(*) FROM gl_budget_lines WHERE budget_id = b.id) AS line_count,
           (SELECT COALESCE(SUM(amount),0) FROM gl_budget_lines WHERE budget_id = b.id) AS total_amount
    FROM gl_budgets b
    LEFT JOIN sessions s ON s.id = b.session_id
    LEFT JOIN users u ON u.id = b.created_by
    ORDER BY b.id DESC
")->fetch_all(MYSQLI_ASSOC);

$sessions = $conn->query("
    SELECT s.id, s.title, s.from_dated, s.to_dated, s.status,
           (SELECT COUNT(*) FROM gl_budgets b WHERE b.session_id = s.id) AS has_budget
    FROM sessions s
    ORDER BY s.status ASC, s.id DESC
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Budgets — Dar-e-Arqm School</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #eef1f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #1e293b; }
    .wrap { max-width: 1400px; margin: 30px auto; padding: 0 20px; }

    .head {
        background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
        color: #fff; padding: 30px 40px 26px 40px; border-radius: 12px 12px 0 0;
        box-shadow: 0 4px 16px rgba(30,64,175,0.15);
        display: flex; justify-content: space-between; align-items: flex-end;
        gap: 20px; flex-wrap: wrap;
    }
    .head h1 { font-size: 30px; font-weight: 700; letter-spacing: -0.3px; }
    .head .sub { font-size: 14px; opacity: 0.85; margin-top: 6px; }

    .btn-white {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 11px 20px; background: #ffffff; color: #1e40af;
        border: 1px solid #ffffff; border-radius: 8px;
        text-decoration: none; font-size: 14px; font-weight: 700;
        line-height: 1; font-family: inherit; cursor: pointer;
        transition: all 0.15s;
    }
    .btn-white:hover { background: #dbeafe; border-color: #dbeafe; color: #1e3a8a; text-decoration: none; }

    .card { background: #fff; padding: 30px 40px 40px 40px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

    .section-title {
        font-size: 12px; font-weight: 800; color: #64748b; text-transform: uppercase;
        letter-spacing: 2px; margin: 0 0 16px 0; padding-bottom: 8px;
        border-bottom: 1px solid #e2e8f0;
    }

    .alert-custom { padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; border-left: 4px solid; }
    .alert-custom.success { background: #d1fae5; border-color: #059669; color: #065f46; }
    .alert-custom.danger  { background: #fee2e2; border-color: #dc2626; color: #991b1b; }

    .tbl-wrap { overflow-x: auto; }
    table.tbl { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; }
    table.tbl thead th {
        background: #f8fafc; font-size: 11px; color: #64748b; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px;
        padding: 12px; text-align: left;
        border-bottom: 2px solid #e2e8f0; white-space: nowrap;
    }
    table.tbl tbody td { padding: 12px; font-size: 13px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    table.tbl tbody tr:hover td { background: #f8fafc; }
    .num { font-family: 'SF Mono','Monaco',monospace; text-align: right; font-variant-numeric: tabular-nums; }

    .pill { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
    .pill.draft    { background: #fef3c7; color: #92400e; }
    .pill.approved { background: #dbeafe; color: #1e40af; }
    .pill.locked   { background: #d1fae5; color: #065f46; }

    /* ===== Action buttons — text + icon, forced visible ===== */
    .action-cell {
        white-space: nowrap;
        min-width: 460px;
    }
    .action-cell .row-btn {
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
        gap: 5px;
        padding: 6px 11px !important;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        text-decoration: none;
        cursor: pointer;
        font-family: inherit;
        border: 1px solid;
        transition: all 0.15s;
        white-space: nowrap;
        line-height: 1.2 !important;
        vertical-align: middle;
        min-height: 30px;
        box-sizing: border-box;
        text-shadow: none !important;
        margin: 0 !important;
        width: auto !important;
        height: auto !important;
        opacity: 1 !important;
        visibility: visible !important;
    }
    .action-cell .row-btn .glyphicon {
        font-size: 11px;
        top: 0;
        line-height: 1;
        margin-right: 1px;
    }

    .action-cell > form {
        display: inline-block !important;
        margin: 0 6px 4px 0 !important;
        padding: 0 !important;
        vertical-align: middle;
        width: auto !important;
    }
    .action-cell > a.row-btn {
        display: inline-flex !important;
        margin: 0 6px 4px 0 !important;
        vertical-align: middle;
    }

    .row-btn-info     { background: #eff6ff !important; color: #1e40af !important; border-color: #bfdbfe !important; }
    .row-btn-info:hover { background: #dbeafe !important; text-decoration: none; color: #1e40af !important; }
    .row-btn-approve  { background: #e0e7ff !important; color: #3730a3 !important; border-color: #c7d2fe !important; }
    .row-btn-approve:hover { background: #c7d2fe !important; text-decoration: none; color: #3730a3 !important; }
    .row-btn-lock     { background: #ecfdf5 !important; color: #065f46 !important; border-color: #a7f3d0 !important; }
    .row-btn-lock:hover { background: #a7f3d0 !important; text-decoration: none; color: #065f46 !important; }
    .row-btn-unlock   { background: #fef3c7 !important; color: #92400e !important; border-color: #fde68a !important; }
    .row-btn-unlock:hover { background: #fde68a !important; text-decoration: none; color: #92400e !important; }
    .row-btn-report   { background: #d1fae5 !important; color: #065f46 !important; border-color: #a7f3d0 !important; }
    .row-btn-report:hover { background: #a7f3d0 !important; text-decoration: none; color: #065f46 !important; }
    .row-btn-danger   { background: #fef2f2 !important; color: #991b1b !important; border-color: #fecaca !important; }
    .row-btn-danger:hover { background: #fee2e2 !important; text-decoration: none; color: #991b1b !important; }

    .empty-state { padding: 60px 20px; text-align: center; color: #94a3b8; }
    .empty-state .big { font-size: 48px; margin-bottom: 12px; opacity: 0.4; }
    .empty-state .title { font-size: 18px; color: #475569; font-weight: 600; margin-bottom: 6px; }

    /* ============ Modal ============ */
    .modal-overlay {
        display: none;
        position: fixed; inset: 0;
        background: rgba(15, 23, 42, 0.55);
        -webkit-backdrop-filter: blur(3px);
        backdrop-filter: blur(3px);
        z-index: 9999;
        align-items: center;
        justify-content: center;
        padding: 30px 20px;
        overflow-y: auto;
        animation: overlayFade 0.2s ease;
    }
    .modal-overlay.open { display: flex !important; }
    @keyframes overlayFade { from { opacity: 0; } to { opacity: 1; } }

    .modal-box {
        background: #fff !important;
        width: 100%;
        max-width: 560px;
        border-radius: 16px;
        box-shadow: 0 25px 80px rgba(15, 23, 42, 0.45), 0 0 0 1px rgba(15, 23, 42, 0.05);
        overflow: hidden;
        animation: modalSlideIn 0.28s cubic-bezier(0.16, 1, 0.3, 1);
    }
    @keyframes modalSlideIn {
        from { opacity: 0; transform: translateY(-20px) scale(0.96); }
        to   { opacity: 1; transform: translateY(0) scale(1); }
    }

    .modal-box .modal-head {
        display: flex !important;
        justify-content: space-between;
        align-items: flex-start;
        padding: 24px 32px 22px 32px !important;
        background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
        color: #fff;
        gap: 16px;
    }
    .modal-box .modal-head h3 {
        font-size: 22px; font-weight: 700; line-height: 1.2;
        letter-spacing: -0.2px; margin: 0 !important; padding: 0 !important;
    }
    .modal-box .modal-head .modal-sub {
        font-size: 13px; opacity: 0.8;
        margin-top: 5px !important; line-height: 1.4;
    }
    .modal-box .modal-close {
        background: rgba(255, 255, 255, 0.12);
        border: 1px solid rgba(255, 255, 255, 0.25);
        color: #fff;
        width: 36px; height: 36px;
        border-radius: 10px;
        font-size: 22px; line-height: 1;
        cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
        font-family: inherit;
        transition: all 0.2s ease;
        padding: 0 0 3px 0 !important;
        margin: 0 !important;
    }
    .modal-box .modal-close:hover {
        background: #fff; color: #1e40af; border-color: #fff;
        transform: rotate(90deg);
    }

    .modal-box .modal-body {
        padding: 28px 32px 16px 32px !important;
        margin: 0 !important;
    }
    .modal-box .modal-body > *:first-child { margin-top: 0 !important; }

    .modal-box .modal-foot {
        display: flex !important;
        justify-content: flex-end;
        gap: 10px;
        padding: 18px 32px 24px 32px !important;
        background: #f8fafc;
        border-top: 1px solid #e2e8f0;
        margin: 0 !important;
    }

    .modal-box .field { margin: 0 0 20px 0 !important; padding: 0 !important; }
    .modal-box .field:last-child { margin-bottom: 4px !important; }
    .modal-box .field label {
        display: block !important;
        font-size: 12px; font-weight: 700; color: #334155;
        text-transform: uppercase; letter-spacing: 0.8px;
        margin: 0 0 8px 0 !important; padding: 0 !important;
        line-height: 1.2;
    }
    .modal-box .field .required { color: #dc2626; font-weight: 800; }

    .modal-box .field input[type=text],
    .modal-box .field select {
        display: block !important;
        width: 100% !important;
        height: 46px !important;
        padding: 0 16px !important;
        border: 1px solid #cbd5e1 !important;
        border-radius: 10px !important;
        font-size: 14px;
        font-family: inherit;
        background-color: #fff;
        color: #0f172a;
        line-height: 46px;
        transition: border-color 0.15s, box-shadow 0.15s;
        -webkit-appearance: none;
        appearance: none;
        margin: 0 !important;
        box-shadow: none;
    }
    .modal-box .field select {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L2 4h8z'/%3E%3C/svg%3E") !important;
        background-repeat: no-repeat !important;
        background-position: right 16px center !important;
        padding-right: 40px !important;
    }
    .modal-box .field input::placeholder { color: #94a3b8; }
    .modal-box .field input:focus,
    .modal-box .field select:focus {
        outline: none !important;
        border-color: #1e40af !important;
        box-shadow: 0 0 0 4px rgba(30, 64, 175, 0.1) !important;
    }
    .modal-box .field .help {
        font-size: 12px; color: #64748b;
        margin: 7px 0 0 0 !important; line-height: 1.45;
    }

    .modal-box .info-note {
        display: flex !important;
        gap: 12px;
        background: #eff6ff;
        border: 1px solid #bfdbfe;
        border-left: 4px solid #1e40af;
        padding: 14px 18px !important;
        border-radius: 10px;
        font-size: 13px;
        color: #1e3a8a;
        line-height: 1.55;
        margin: 4px 0 8px 0 !important;
    }
    .modal-box .info-note .ico { flex-shrink: 0; font-size: 16px; line-height: 1.4; }
    .modal-box .info-note strong { font-weight: 700; }

    .modal-box .btn-primary {
        display: inline-flex !important;
        align-items: center; justify-content: center; gap: 8px;
        height: 44px;
        padding: 0 24px !important;
        background: #1e40af !important;
        color: #fff !important;
        border: 1px solid #1e40af !important;
        border-radius: 10px;
        font-size: 14px; font-weight: 700;
        font-family: inherit; cursor: pointer; line-height: 1;
        transition: all 0.15s; letter-spacing: 0.2px;
        margin: 0 !important; text-shadow: none;
    }
    .modal-box .btn-primary:hover {
        background: #1e3a8a !important;
        border-color: #1e3a8a !important;
        box-shadow: 0 4px 12px rgba(30, 64, 175, 0.25);
    }

    .modal-box .btn-ghost {
        display: inline-flex !important;
        align-items: center; justify-content: center; gap: 8px;
        height: 44px;
        padding: 0 22px !important;
        background: #fff !important;
        color: #334155 !important;
        border: 1px solid #cbd5e1 !important;
        border-radius: 10px;
        font-size: 14px; font-weight: 600;
        font-family: inherit; cursor: pointer; line-height: 1;
        transition: all 0.15s;
        margin: 0 !important; text-shadow: none;
    }
    .modal-box .btn-ghost:hover {
        background: #f1f5f9 !important;
        border-color: #94a3b8 !important;
        color: #1e293b !important;
    }

    .modal-box form { margin: 0 !important; padding: 0 !important; }

    @media print {
        .no-print { display: none !important; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <h1>Budgets</h1>
            <div class="sub">Fiscal-year budgets with approved-and-locked workflow</div>
        </div>
        <?php if (!empty($perms['can_add'])): ?>
        <button type="button" class="btn-white no-print" onclick="openCreate()">
            <span class="glyphicon glyphicon-plus"></span> New Budget
        </button>
        <?php endif; ?>
    </div>

    <div class="card">

        <?php if ($msg): ?>
            <div class="alert-custom <?= htmlspecialchars($msg_type) ?>"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <div class="section-title">All Budgets</div>

        <?php if (empty($budgets)): ?>
            <div class="empty-state">
                <div class="big">📊</div>
                <div class="title">No budgets yet</div>
                Click <strong>New Budget</strong> to create one for the current session.
            </div>
        <?php else: ?>
            <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr>
                        <th style="width:60px;">#</th>
                        <th>Title</th>
                        <th>Session</th>
                        <th class="num" style="width:100px;">Accounts</th>
                        <th class="num" style="width:150px;">Total Budget</th>
                        <th style="width:110px;">Status</th>
                        <th style="width:170px;">Created</th>
                        <?php if (!empty($perms['can_edit']) || !empty($perms['can_delete'])): ?>
<th class="no-print" style="width:460px;">Actions</th>
<?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($budgets as $b):
                        $statusCls = strtolower($b['status']);
                        $bid = (int)$b['id'];
                    ?>
                        <tr>
                            <td>#<?= $bid ?></td>
                            <td><strong><?= htmlspecialchars($b['title']) ?></strong></td>
                            <td>
                                <?= htmlspecialchars($b['session_title'] ?: '—') ?>
                                <br><small style="color:#94a3b8;"><?= htmlspecialchars($b['from_dated']) ?> → <?= htmlspecialchars($b['to_dated']) ?></small>
                            </td>
                            <td class="num"><?= (int)$b['line_count'] ?></td>
                            <td class="num" style="font-weight: 700;"><?= number_format((float)$b['total_amount'], 2) ?></td>
                            <td><span class="pill <?= $statusCls ?>"><?= htmlspecialchars($b['status']) ?></span></td>
                            <td>
                                <small>
                                    <?= htmlspecialchars($b['created_at']) ?>
                                    <br>by <?= htmlspecialchars($b['created_by_name'] ?: '—') ?>
                                </small>
                            </td>
                            <?php if (!empty($perms['can_edit']) || !empty($perms['can_delete'])): ?>
<td class="no-print action-cell">
                                <?php if ($b['status'] === 'DRAFT'): ?>
                                    <?php if (!empty($perms['can_edit'])): ?>
<a href="gl_budget_edit.php?id=<?= $bid ?>" class="row-btn row-btn-info">
                                        <span class="glyphicon glyphicon-pencil"></span> Edit
                                    </a>
<?php endif; ?>
                                    <form method="post" onsubmit="return confirm('Approve this budget?');">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="id" value="<?= $bid ?>">
                                        <button type="submit" class="row-btn row-btn-approve">
                                            <span class="glyphicon glyphicon-ok"></span> Approve
                                        </button>
                                    </form>
                                    <?php if (!empty($perms['can_delete'])): ?>
                                    <form method="post" onsubmit="return confirm('Delete this draft budget?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $bid ?>">
                                        <button type="submit" class="row-btn row-btn-danger">
                                            <span class="glyphicon glyphicon-trash"></span> Delete
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                <?php elseif ($b['status'] === 'APPROVED'): ?>
                                    <?php if ($isAdmin): ?>
                                        <?php if (!empty($perms['can_edit'])): ?>
<a href="gl_budget_edit.php?id=<?= $bid ?>" class="row-btn row-btn-info">
                                            <span class="glyphicon glyphicon-pencil"></span> Edit
                                        </a>
<?php endif; ?>
                                    <?php endif; ?>
                                    <form method="post" onsubmit="return confirm('Lock this budget?');">
                                        <input type="hidden" name="action" value="lock">
                                        <input type="hidden" name="id" value="<?= $bid ?>">
                                        <button type="submit" class="row-btn row-btn-lock">
                                            <span class="glyphicon glyphicon-lock"></span> Lock
                                        </button>
                                    </form>
                                <?php elseif ($b['status'] === 'LOCKED'): ?>
                                    <?php if ($isAdmin): ?>
                                        <form method="post" onsubmit="return confirm('Unlock this budget? It goes back to APPROVED.');">
                                            <input type="hidden" name="action" value="unlock">
                                            <input type="hidden" name="id" value="<?= $bid ?>">
                                            <button type="submit" class="row-btn row-btn-unlock">
                                                <span class="glyphicon glyphicon-lock"></span> Unlock
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <a href="gl_budget_report.php?id=<?= $bid ?>" class="row-btn row-btn-report">
                                    <span class="glyphicon glyphicon-signal"></span> Report
                                </a>
                            </td>
<?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>

    </div>
</div>

<!-- Create Budget Modal -->
<div class="modal-overlay" id="createModal">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="createModalTitle">
        <div class="modal-head">
            <div>
                <h3 id="createModalTitle">New Budget</h3>
                <div class="modal-sub">Create a fiscal-year budget for a session</div>
            </div>
            <button type="button" class="modal-close" onclick="closeCreate()" aria-label="Close">&times;</button>
        </div>
        <?php if (!empty($perms['can_add'])): ?>
        <form method="post">
            <div class="modal-body">
                <input type="hidden" name="action" value="create">

                <div class="field">
                    <label for="create_session">Session <span class="required">*</span></label>
                    <select name="session_id" id="create_session" required>
                        <option value="">— Select session —</option>
                        <?php foreach ($sessions as $s): ?>
                            <option value="<?= (int)$s['id'] ?>"
                                <?= (int)$s['has_budget'] > 0 ? 'disabled' : '' ?>
                                <?= ((int)$s['status'] === 0 && (int)$s['has_budget'] === 0) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['title']) ?>
                                (<?= htmlspecialchars($s['from_dated']) ?> → <?= htmlspecialchars($s['to_dated']) ?>)
                                <?= (int)$s['has_budget'] > 0 ? ' — already has budget' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="help">Sessions that already have a budget are disabled.</div>
                </div>

                <div class="field">
                    <label for="create_title">Budget Title <span class="required">*</span></label>
                    <input type="text" name="title" id="create_title" required maxlength="150"
                           placeholder="e.g. FY 2026-2027 Operating Budget">
                </div>

                <div class="info-note">
                    <div class="ico">💡</div>
                    <div>
                        <strong>Next step:</strong> fill in monthly amounts per account in a 12-month grid, then approve and lock. Until then it stays in <strong>DRAFT</strong>.
                    </div>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn-ghost" onclick="closeCreate()">Cancel</button>
                <button type="submit" class="btn-primary">
                    <span class="glyphicon glyphicon-plus"></span> Create Budget
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<script>
function openCreate() {
    var m = document.getElementById('createModal');
    m.classList.add('open');
    document.body.style.overflow = 'hidden';
    setTimeout(function(){
        var s = document.getElementById('create_session');
        if (s) s.focus();
    }, 260);
}
function closeCreate() {
    var m = document.getElementById('createModal');
    if (!m.classList.contains('open')) return;
    m.classList.remove('open');
    document.body.style.overflow = '';
}
document.getElementById('createModal').addEventListener('click', function(e){
    if (e.target === this) closeCreate();
});
document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') {
        var m = document.getElementById('createModal');
        if (m.classList.contains('open')) closeCreate();
    }
});
</script>

</body>
</html>