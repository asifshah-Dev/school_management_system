<?php
require_once('security.php');
require_once('conn_inc.php');

$user_id = (int)($_SESSION['user_id'] ?? 0);
$stmt = $conn->prepare("
    SELECT u.id, u.username, u.mobile_no, u.email, u.salary,
           r.title AS role_title, vc.title AS village_council_title
    FROM users u
    LEFT JOIN roles r ON r.id = u.role_id
    LEFT JOIN village_councils vc ON vc.id = u.village_council_id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$profile) {
    http_response_code(404);
    exit('User profile not found.');
}

$display_value = static function ($value): string {
    return $value === null || $value === '' ? '—' : (string)$value;
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Profile — School Management System</title>
    <style>
        .profile-page {
            max-width: 920px;
            margin: 2rem auto;
            padding: 0 1rem;
            color: #302c29;
            font-family: Inter, sans-serif;
        }

        .profile-heading {
            margin-bottom: 1.25rem;
        }

        .profile-heading h1 {
            margin: 0;
            font-size: 1.6rem;
            font-weight: 700;
        }

        .profile-heading p {
            margin: 0.4rem 0 0;
            color: #77736f;
        }

        .profile-card {
            overflow: hidden;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 8px 24px rgba(36, 31, 27, 0.06);
        }

        .profile-card-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1.25rem 1.5rem;
            background: #f8fafc;
            border-bottom: 1px solid #e5e7eb;
        }

        .profile-avatar {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 52px;
            height: 52px;
            flex: 0 0 52px;
            border-radius: 50%;
            background: #ed8b43;
            color: #fff;
            font-size: 1rem;
            font-weight: 700;
        }

        .profile-card-header h2 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 700;
        }

        .profile-card-header p {
            margin: 0.25rem 0 0;
            color: #77736f;
            font-size: 0.85rem;
        }

        .profile-details {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0;
            padding: 0.5rem 1.5rem 1rem;
        }

        .profile-detail {
            padding: 1rem 0.5rem;
            border-bottom: 1px solid #f0f1f3;
        }

        .profile-detail dt {
            margin-bottom: 0.35rem;
            color: #77736f;
            font-size: 0.75rem;
            font-weight: 650;
        }

        .profile-detail dd {
            margin: 0;
            overflow-wrap: anywhere;
            font-size: 0.92rem;
            font-weight: 550;
        }

        @media (max-width: 600px) {
            .profile-details {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<?php require_once('navbar.php'); ?>
<main class="profile-page">
    <header class="profile-heading">
        <h1>My Profile</h1>
        <p>Your current account information.</p>
    </header>

    <section class="profile-card" aria-labelledby="profile-name">
        <div class="profile-card-header">
            <div class="profile-avatar" aria-hidden="true">
                <?php echo htmlspecialchars(strtoupper(substr((string)$profile['username'], 0, 2)), ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <div>
                <h2 id="profile-name"><?php echo htmlspecialchars((string)$profile['username'], ENT_QUOTES, 'UTF-8'); ?></h2>
                <p><?php echo htmlspecialchars($display_value($profile['role_title']), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </div>

        <dl class="profile-details">
            <div class="profile-detail">
                <dt>Account ID</dt>
                <dd><?php echo (int)$profile['id']; ?></dd>
            </div>
            <div class="profile-detail">
                <dt>Username</dt>
                <dd><?php echo htmlspecialchars((string)$profile['username'], ENT_QUOTES, 'UTF-8'); ?></dd>
            </div>
            <div class="profile-detail">
                <dt>Email</dt>
                <dd><?php echo htmlspecialchars($display_value($profile['email']), ENT_QUOTES, 'UTF-8'); ?></dd>
            </div>
            <div class="profile-detail">
                <dt>Mobile</dt>
                <dd><?php echo htmlspecialchars($display_value($profile['mobile_no']), ENT_QUOTES, 'UTF-8'); ?></dd>
            </div>
            <div class="profile-detail">
                <dt>Role</dt>
                <dd><?php echo htmlspecialchars($display_value($profile['role_title']), ENT_QUOTES, 'UTF-8'); ?></dd>
            </div>
            <div class="profile-detail">
                <dt>Village Council</dt>
                <dd><?php echo htmlspecialchars($display_value($profile['village_council_title']), ENT_QUOTES, 'UTF-8'); ?></dd>
            </div>
            <div class="profile-detail">
                <dt>Salary</dt>
                <dd><?php echo htmlspecialchars($display_value($profile['salary']), ENT_QUOTES, 'UTF-8'); ?></dd>
            </div>
        </dl>
    </section>
</main>
</body>
</html>
