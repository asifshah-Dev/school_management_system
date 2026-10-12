<?php
session_start();
$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'user';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Access Denied — 403</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: #eef1f5;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            padding: 30px 20px;
        }
        .card {
            background: #fff;
            max-width: 520px; width: 100%;
            border-radius: 16px;
            box-shadow: 0 25px 70px rgba(15, 23, 42, 0.15);
            overflow: hidden;
            text-align: center;
        }
        .head {
            background: linear-gradient(135deg, #991b1b 0%, #dc2626 100%);
            color: #fff;
            padding: 34px 32px 28px 32px;
        }
        .head .big { font-size: 64px; font-weight: 800; line-height: 1; letter-spacing: -3px; }
        .head .label {
            font-size: 12px; font-weight: 800; text-transform: uppercase;
            letter-spacing: 2px; opacity: 0.85; margin-top: 8px;
        }
        .body { padding: 28px 32px 32px 32px; }
        .body h1 { font-size: 22px; font-weight: 700; color: #0f172a; margin-bottom: 10px; }
        .body p { font-size: 14px; color: #475569; line-height: 1.6; margin-bottom: 22px; }
        .actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            height: 44px; padding: 0 22px;
            border-radius: 10px; border: 1px solid;
            font-size: 14px; font-weight: 600;
            text-decoration: none; cursor: pointer;
            font-family: inherit; line-height: 1;
            transition: all 0.15s;
        }
        .btn-primary { background: #1e40af; color: #fff; border-color: #1e40af; }
        .btn-primary:hover { background: #1e3a8a; border-color: #1e3a8a; text-decoration: none; color: #fff; }
        .btn-ghost { background: #fff; color: #334155; border-color: #cbd5e1; }
        .btn-ghost:hover { background: #f1f5f9; border-color: #94a3b8; text-decoration: none; color: #1e293b; }
    </style>
</head>
<body>
    <div class="card">
        <div class="head">
            <div class="big">403</div>
            <div class="label">Access Denied</div>
        </div>
        <div class="body">
            <h1>You don't have permission for this page</h1>
            <p>
                Your account (<strong><?php echo htmlspecialchars($name); ?></strong>) doesn't have
                <code>can_view</code> on this module. Ask an admin to grant it from
                <strong>User Permissions</strong>.
            </p>
            <div class="actions">
                <a href="index.php" class="btn btn-primary">Go to Dashboard</a>
                <a href="javascript:history.back()" class="btn btn-ghost">Go Back</a>
            </div>
        </div>
    </div>
</body>
</html>