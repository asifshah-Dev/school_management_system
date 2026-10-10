<?php
date_default_timezone_set('Asia/Karachi');
require_once('security.php');
require_once('conn_inc.php');
require_once('gl.php');

$conn->query("SET time_zone = '+05:00'");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$isAdmin = ($userId === 1);

$msg = ''; $msg_type = '';

/* ---------- Handle POST ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    $lockDate = trim($_POST['lock_date'] ?? '');

    // Empty is allowed = remove the lock
    if ($lockDate !== '' && !strtotime($lockDate)) {
        $msg = 'Invalid date. Use YYYY-MM-DD format or leave empty to remove the lock.';
        $msg_type = 'danger';
    } else {
        if (gl_set_setting($conn, 'lock_date', $lockDate, $userId)) {
            $msg = $lockDate === ''
                ? 'Lock removed. Ledger is now open for all dates.'
                : "Ledger locked. No transactions allowed before $lockDate.";
            $msg_type = 'success';
        } else {
            $msg = 'Failed to save.'; $msg_type = 'danger';
        }
    }
}

$currentLock = gl_get_lock_date($conn) ?: '';

/* Small diagnostic: how many txns would be affected by tightening the lock */
$wouldBlock = 0;
if ($currentLock) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM gl_transactions WHERE entry_date < ?");
    $stmt->bind_param("s", $currentLock);
    $stmt->execute();
    $wouldBlock = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Settings — Period Lock</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    * { box-sizing: border-box; }
    body { background: #eef1f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 0; color: #1e293b; }
    .wrap { max-width: 900px; margin: 30px auto; padding: 0 20px; }

    .head {
        background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
        color: #fff; padding: 30px 40px 26px 40px;
        border-radius: 12px 12px 0 0; box-shadow: 0 4px 16px rgba(30,64,175,0.15);
        display: flex; justify-content: space-between; align-items: flex-end;
        gap: 20px; flex-wrap: wrap;
    }
    .head h1 { margin: 0; font-size: 30px; font-weight: 700; }
    .head .sub { font-size: 14px; opacity: 0.9; margin-top: 6px; }

    .btn-back {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 10px 20px;
        background: #ffffff; color: #1e40af;
        border: 1px solid #ffffff; border-radius: 8px;
        text-decoration: none; font-size: 14px; font-weight: 600;
        line-height: 1; font-family: inherit;
    }
    .btn-back:hover { background: #e0e7ff; border-color: #e0e7ff; color: #1e40af; text-decoration: none; }

    .card { background: #fff; padding: 30px 40px 40px 40px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

    .section-title {
        font-size: 12px; font-weight: 800; color: #64748b; text-transform: uppercase;
        letter-spacing: 2px; margin: 24px 0 14px 0; padding-bottom: 8px;
        border-bottom: 1px solid #e2e8f0;
    }
    .section-title:first-child { margin-top: 0; }

    .alert-custom { padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; border-left: 4px solid; }
    .alert-custom.success { background: #d1fae5; border-color: #059669; color: #065f46; }
    .alert-custom.danger  { background: #fee2e2; border-color: #dc2626; color: #991b1b; }

    .state-box {
        padding: 20px 24px; border-radius: 10px; margin-bottom: 22px;
        border-left: 6px solid;
    }
    .state-box.locked { background: #fef3c7; border-color: #f59e0b; }
    .state-box.open   { background: #ecfdf5; border-color: #059669; }
    .state-box .lbl {
        font-size: 11px; text-transform: uppercase; letter-spacing: 1.5px;
        font-weight: 800; color: #64748b; margin-bottom: 6px;
    }
    .state-box .val { font-size: 20px; font-weight: 700; color: #0f172a; }
    .state-box.locked .val { color: #92400e; }
    .state-box.open .val { color: #065f46; }
    .state-box .sub { font-size: 13px; color: #64748b; margin-top: 6px; }

    .field { margin-bottom: 18px; }
    .field label {
        display: block; font-size: 12px; font-weight: 700; color: #334155;
        text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px;
    }
    .field input {
        width: 100%; height: 46px; padding: 10px 14px;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 15px; font-family: inherit; background: #fff; color: #0f172a;
    }
    .field input:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 3px rgba(30,64,175,0.1); }
    .field .help { font-size: 12px; color: #64748b; margin-top: 6px; line-height: 1.5; }

    .hint {
        background: #eff6ff; border: 1px solid #bfdbfe; border-left: 4px solid #1e40af;
        padding: 14px 18px; border-radius: 10px; font-size: 13px;
        color: #1e3a8a; line-height: 1.6;
    }
    .hint code { background: #dbeafe; padding: 2px 6px; border-radius: 4px; font-size: 12px; }

    .warn-box {
        background: #fef2f2; border: 1px solid #fecaca; border-left: 4px solid #dc2626;
        padding: 14px 18px; border-radius: 10px; font-size: 13px;
        color: #991b1b; line-height: 1.6; margin-top: 16px;
    }

    .form-actions {
        display: flex; justify-content: flex-end; gap: 12px;
        margin-top: 30px; padding-top: 24px; border-top: 1px solid #e2e8f0;
        flex-wrap: wrap;
    }
    .action-btn-primary {
        display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        height: 48px; min-width: 200px; padding: 0 26px;
        background: #1e40af; color: #ffffff;
        border: 1px solid #1e40af; border-radius: 8px;
        font-family: inherit; font-size: 15px; font-weight: 600;
        line-height: 1; text-decoration: none; cursor: pointer;
        white-space: nowrap;
    }
    .action-btn-primary:hover { background: #1e3a8a; border-color: #1e3a8a; }
    .action-btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }

    .readonly-note {
        padding: 20px 24px; background: #f8fafc; border: 1px solid #e2e8f0;
        border-radius: 10px; font-size: 14px; color: #64748b;
        text-align: center;
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <h1>Ledger Settings</h1>
            <div class="sub">Period lock and other system-wide settings</div>
        </div>
        <a href="gl_test_hub.php" class="btn-back">
            <span class="glyphicon glyphicon-arrow-left"></span> Test Hub
        </a>
    </div>

    <div class="card">

        <?php if ($msg): ?>
            <div class="alert-custom <?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <div class="section-title">Period Lock</div>

        <?php if ($currentLock): ?>
            <div class="state-box locked">
                <div class="lbl">Status</div>
                <div class="val">🔒 Ledger is LOCKED through <?= htmlspecialchars($currentLock) ?></div>
                <div class="sub">
                    Transactions with an entry date <strong>before</strong> <?= htmlspecialchars($currentLock) ?>
                    will be refused. Currently <strong><?= number_format($wouldBlock) ?></strong>
                    posted transaction<?= $wouldBlock === 1 ? '' : 's' ?> exist before that date
                    (they remain in place, but nothing new can be added).
                </div>
            </div>
        <?php else: ?>
            <div class="state-box open">
                <div class="lbl">Status</div>
                <div class="val">🔓 Ledger is OPEN — no period lock set</div>
                <div class="sub">
                    Any date is currently allowed. Set a lock date below to close a period.
                </div>
            </div>
        <?php endif; ?>

        <div class="hint">
            <strong>How period lock works:</strong>
            <br>
            When you set a lock date, any attempt to post a transaction <strong>with an earlier date</strong>
            is refused with an error message. This prevents accidentally posting to a closed month after you've
            already reported it.
            <br><br>
            The lock is enforced in <code>post_journal_entry()</code> — that means <strong>every</strong> page
            that writes to the ledger is protected: vouchers, purchases, expenses, salaries, fees, adjustments,
            manual journal entries.
            <br><br>
            To open a period back up, clear the lock date (leave the field empty and click Save).
        </div>

        <?php if ($isAdmin): ?>
            <form method="post" style="margin-top:24px;">
                <div class="field">
                    <label>Lock Date (YYYY-MM-DD)</label>
                    <input type="date" name="lock_date" value="<?= htmlspecialchars($currentLock) ?>">
                    <div class="help">
                        No transactions allowed <strong>before</strong> this date.
                        Leave empty to remove the lock.
                        <br>Example: after closing March, set this to <code>2026-04-01</code>.
                    </div>
                </div>

                <div class="warn-box">
                    <strong>⚠ Note:</strong>
                    Setting a lock date does NOT modify any existing transactions — it only prevents
                    <em>future</em> postings with dates before the lock. If you later need to post to a
                    locked period, temporarily clear the lock, post the entry, then set the lock again.
                </div>

                <div class="form-actions">
                    <a href="gl_settings.php" class="action-btn-primary" style="background:#f1f5f9; color:#334155; border-color:#cbd5e1; min-width:150px;">
                        Reset
                    </a>
                    <button type="submit" class="action-btn-primary">
                        <span class="glyphicon glyphicon-floppy-disk"></span> Save Settings
                    </button>
                </div>
            </form>
        <?php else: ?>
            <div class="readonly-note" style="margin-top:24px;">
                Only user #1 (admin) can change the lock date.
                <br>
                Current setting is shown above.
            </div>
        <?php endif; ?>

    </div>
</div>

</body>
</html>