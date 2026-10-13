<?php

require_once('security.php');
require_once('conn_inc.php');

// Load modules using the user's effective view permission: user override,
// then role default, with unconfigured modules remaining available by default.
$user_id = (int)($_SESSION['user_id'] ?? 0);
$role_modules = [];
$module_parents = [];

// ───── Parents get no module scanning ─────
$__is_parent_role = false;
if ($user_id > 0) {
    $__r = $conn->query("SELECT role_id FROM users WHERE id = " . (int)$user_id)->fetch_assoc();
    if ($__r && (int)$__r['role_id'] === 6) $__is_parent_role = true;
}

if ($user_id > 0 && !$__is_parent_role) {
    $qry = "
        SELECT m.id, m.parent_id, m.title, m.url,
               CASE
                   WHEN u.role_id = " . ADMIN_ROLE_ID . " THEN 1
                   ELSE COALESCE(um.can_view, rm.can_view, 1)
               END AS can_view
        FROM users u
        CROSS JOIN modules m
        LEFT JOIN role_modules rm
            ON rm.role_id = u.role_id AND rm.module_id = m.id
        LEFT JOIN user_modules um
            ON um.user_id = u.id AND um.module_id = m.id
        WHERE u.id = ? AND m.status = 1
        ORDER BY m.parent_id, (m.url IN ('finance_hub.php','gl_hub.php', 'academic_hub.php', 'exam_hub.php')) DESC, m.title
    ";
    $stmt = $conn->prepare($qry);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $modules = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $modules_by_id = [];
    foreach ($modules as $row) {
        $modules_by_id[(int)$row['id']] = $row;
    }

    foreach ($modules as $row) {
        if (!(int)$row['can_view'] || !empty($row['parent_id'])) {
            continue;
        }

        $role_modules[(int)$row['id']] = [
            'title' => $row['title'],
            'url' => $row['url'] ?? '#',
            'children' => []
        ];
    }

    foreach ($modules as $row) {
        $parent_id = (int)($row['parent_id'] ?? 0);
        if (!(int)$row['can_view'] || $parent_id === 0) {
            continue;
        }

        if (!isset($role_modules[$parent_id])) {
            $parent = $modules_by_id[$parent_id] ?? null;
            $role_modules[$parent_id] = [
                'title' => $parent['title'] ?? 'Parent Module',
                'url' => $parent['url'] ?? '#',
                'children' => []
            ];
        }

        $module_parents[(int)$row['id']] = $parent_id;
        $role_modules[$parent_id]['children'][] = [
            'title' => $row['title'],
            'url' => $row['url'] ?? '#'
        ];
    }
}
?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
  :root {
    --primary: #2563eb;
    --primary-light: #3b82f6;
    --primary-dark: #1d4ed8;
    --secondary: #64748b;
    --accent: #8b5cf6;
    --background: #ffffff;
    --surface: #f8fafc;
    --text-primary: #1e293b;
    --text-secondary: #64748b;
    --text-light: #94a3b8;
    --border: #e2e8f0;
    --hover-bg: #f1f5f9;
    --danger: #ef4444;
    --danger-hover: #dc2626;
    --success: #10b981;
    --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
    --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    --radius: 12px;
    --transition: all 0.2s ease-in-out;
    --quicklink-bg: #3b3a85;
    --quicklink-text: #ffffff;
    --quicklink-hover-bg: #000000;
    --quicklink-hover-text: #ffffff;
  }

  * {
    font-family: 'Inter', sans-serif;
    margin: 0;
    padding: 0;
    box-sizing: border-box;
  }

  .top-quick-bar {
    background: var(--background);
    min-height: 20px;
    padding: 0.5rem 1.5rem;
    position: relative;
    z-index: 1031;
    display: flex;
    justify-content: center;
  }

  .quick-links-horizontal {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
    justify-content: center;
  }

  .quick-link-horizontal-btn {
    display: inline-flex;
    align-items: center;
    padding: 0.3rem 1rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 500;
    text-decoration: none;
    transition: var(--transition);
    white-space: nowrap;
    background: var(--quicklink-bg);
    color: var(--quicklink-text);
  }

  .quick-link-horizontal-btn:hover {
    transform: translateY(-1px);
    box-shadow: var(--shadow-sm);
    background: var(--quicklink-hover-bg);
    color: var(--quicklink-hover-text);
  }

  @media (max-width: 991px) { .top-quick-bar { display: none; } }

  .navbar {
    background: var(--background);
    height: 55px;
    backdrop-filter: blur(20px);
    box-shadow: var(--shadow-sm);
    padding: 0.5rem 1.5rem;
    z-index: 1030;
    transition: var(--transition);
  }

  .navbar-main-content { display: flex; align-items: center; width: 100%; }

  .navbar-brand {
    font-weight: 700;
    color: var(--text-primary) !important;
    display: flex;
    align-items: center;
    font-size: 1.1rem;
    transition: var(--transition);
    margin-right: 2rem;
  }

  .navbar-brand:hover { transform: translateY(-1px); }

  .navbar-brand img {
    height: 36px;
    margin-right: 10px;
    border-radius: 8px;
    transition: var(--transition);
    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.1));
  }

  .navbar-brand:hover img { transform: scale(1.05); }

  .brand-text {
    font-size: 1.1rem;
    color: #000000;
    line-height: 1.2;
    font-weight: 700;
    background: #3b3a85;
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
  }

  .brand-text span { font-size: 20px !important; }
  .brand-text small { font-size: 8px !important; }

  .nav-link {
    color: var(--text-secondary) !important;
    font-weight: 500;
    margin: 0 0.15rem;
    transition: var(--transition);
    font-size: 0.8rem;
    padding: 0.4rem 0.8rem !important;
    border-radius: 8px;
    position: relative;
  }

  .nav-link:hover,
  .nav-link.active {
    color: var(--primary) !important;
    background: var(--hover-bg);
    transform: translateY(-1px);
  }

  .nav-link.active { font-weight: 600; }

  .nav-link.active::after {
    content: '';
    position: absolute;
    bottom: -1px;
    left: 50%;
    width: 16px;
    height: 2px;
    background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
    border-radius: 2px;
    transform: translateX(-50%);
  }

  .dropdown-menu {
    border-radius: var(--radius);
    box-shadow: var(--shadow-lg);
    animation: slideIn 0.25s ease-out;
    border: 1px solid var(--border);
    background: var(--background);
    padding: 0.4rem;
    margin-top: 0.4rem !important;
    min-width: 200px;
  }

  .dropdown-item {
    font-weight: 500;
    padding: 0.4rem 0.8rem;
    font-size: 0.75rem;
    color: var(--text-secondary) !important;
    border-radius: 6px;
    transition: var(--transition);
    margin: 0.1rem 0;
  }

  .dropdown-item:hover {
    background: var(--primary-light) !important;
    color: white !important;
    transform: translateX(3px);
  }

  .dropdown-divider { border-color: var(--border); margin: 0.3rem 0; }

  .btn-logout {
    font-weight: 600;
    border-radius: 8px;
    padding: 0.4rem 1rem;
    font-size: 0.75rem;
    transition: var(--Transition);
    border: none;
    margin-left: 20px !important;
  }

  .btn-logout:hover { background: #bab9b9 100%; transform: translateY(-2px); }

  @keyframes slideIn {
    from { opacity: 0; transform: translateY(-8px) scale(0.98); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
  }

  .navbar-toggler {
    border: none;
    padding: 0.4rem;
    border-radius: 8px;
    transition: var(--transition);
    background: var(--surface);
  }

  .navbar-toggler:focus { box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); }

  .navbar-toggler-icon {
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba(30, 41, 59, 0.7)' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
    width: 1.2em;
    height: 1.2em;
  }

  @media (max-width: 991px) {
    .navbar { padding: 0.5rem 1rem; }

    .navbar-collapse {
      background: var(--background);
      padding: 0.8rem;
      border-radius: var(--radius);
      margin-top: 0.8rem;
      box-shadow: var(--shadow-lg);
      border: 1px solid var(--border);
    }

    .nav-link {
      color: var(--text-primary) !important;
      font-size: 0.85rem;
      padding: 0.5rem 0.8rem !important;
      margin: 0.2rem 0;
    }

    .dropdown-menu {
      background: var(--surface);
      margin: 0.4rem 0 0.4rem 0.8rem;
      border: 1px solid var(--border);
    }

    .dropdown-item { padding: 0.5rem 0.8rem; font-size: 0.8rem; }

    .btn-logout { margin-top: 0.8rem; width: 100%; text-align: center; }

    .navbar-brand { margin-right: 1rem; }
    .navbar-brand img { height: 32px; }
    .brand-text span { font-size: 18px !important; }
    .brand-text small { font-size: 7px !important; }
  }

  @media (max-width: 575px) {
    .navbar { padding: 0.4rem 0.8rem; }
    .navbar-brand { font-size: 1rem; }
    .navbar-brand img { height: 28px; margin-right: 6px; }
    .brand-text span { font-size: 16px !important; }
    .brand-text small { font-size: 6px !important; }
  }

  .badge-notification {
    position: absolute;
    top: -5px;
    right: -5px;
    background: linear-gradient(135deg, var(--accent) 0%, #a855f7 100%);
    color: white;
    border-radius: 50%;
    width: 16px;
    height: 16px;
    font-size: 0.6rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
  }

  .user-profile {
    display: flex;
    align-items: center;
    padding: 0.4rem 0.8rem;
    margin: 0.4rem 0;
    border-radius: 8px;
    background: var(--surface);
    border: 1px solid var(--border);
  }

  .user-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
    margin-right: 8px;
    font-size: 0.9rem;
  }

  .user-info { flex: 1; }
  .user-name { font-weight: 600; color: var(--text-primary); font-size: 0.8rem; }
  .user-role { font-size: 0.7rem; color: var(--text-secondary); }

  body {
    font-family: 'Inter', sans-serif;
    background-color: #f4f6f9;
    color: #2d3748;
    line-height: 1.4;
    font-size: 14px;
    background-color: #f4f6f9 !important;
  }

  .container {
    max-width: 1200px;
    margin: 15px auto;
    padding: 0 15px;
  }

  .panel {
    background: #ffffff;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    margin-bottom: 15px;
    overflow: hidden;
  }

  .panel-heading {
    background: linear-gradient(135deg, #2b6cb0 0%, #3182ce 100%);
    padding: 10px 15px;
    border-bottom: none;
  }

  .panel-heading h3 {
    color: #ffffff;
    font-size: 16px;
    font-weight: 600;
    margin: 0;
  }

  .panel-body { padding: 15px; background-color: #f6f5f5; }

  .alert {
    border-radius: 6px;
    padding: 10px;
    margin-bottom: 10px;
    font-size: 13px;
  }

  .alert-success {
    background-color: #e6fffa;
    border-color: #b2f5ea;
    color: #2c7a7b;
  }

  .alert-danger {
    background-color: #fff5f5;
    border-color: #fed7d7;
    color: #c53030;
  }

  .form-section {
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #edf2f7;
  }

  .form-section h4 {
    font-size: 15px;
    font-weight: 600;
    color: #2b6cb0;
    margin-bottom: 10px;
  }

  .form-group { margin-bottom: 10px; }

  .form-group label {
    font-size: 13px;
    font-weight: 500;
    color: #2d3748;
    margin-bottom: 5px;
    display: block;
  }

  .form-control {
    border: 1px solid #e2e8f0;
    border-radius: 5px;
    padding: 8px;
    font-size: 13px;
    height: 34px;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
  }

  .form-control:focus {
    border-color: #3182ce;
    box-shadow: 0 0 0 2px rgba(49, 130, 206, 0.1);
    outline: none;
  }

  .form-group-inline {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
  }

  .form-group-inline .form-control { width: auto; min-width: 60px; }
  .form-group-inline select.form-control { min-width: 100px; }
  .form-group input[type="checkbox"] { transform: scale(1.2); margin-right: 8px; }

  .btn { border-radius: 5px; padding: 8px 15px; font-size: 13px; font-weight: 500; transition: all 0.2s ease; }
  .btn-primary { background-color: #2b6cb0; border-color: #2b6cb0; }
  .btn-primary:hover { background-color: #2c5282; border-color: #2c5282; }
  .btn-secondary { background-color: #718096; border-color: #718096; }
  .btn-secondary:hover { background-color: #5a667a; border-color: #5a667a; }
  .btn-danger { background-color: #e53e3e; border-color: #e53e3e; }
  .btn-danger:hover { background-color: #c53030; border-color: #c53030; }
  .btn-info { background-color: #38b2ac; border-color: #38b2ac; }
  .btn-info:hover { background-color: #319795; border-color: #319795; }

  .table-responsive { border-radius: 6px; overflow: hidden; }

  .table {
    margin-bottom: 0;
    border-collapse: separate;
    border-spacing: 0;
  }

  .table th {
    background: #2d3748;
    color: #ffffff;
    font-weight: 600;
    font-size: 13px;
    padding: 10px;
    text-transform: uppercase;
    border: none;
  }

  .table td {
    padding: 10px;
    border: none;
    vertical-align: middle;
    font-size: 13px;
    color: #2d3748;
  }

  .table-striped tbody tr:nth-of-type(odd) { background-color: #f7fafc; }
  .table-striped tbody tr:hover { background-color: #edf2f7; }

  .student-image { max-width: 60px; max-height: 60px; border-radius: 4px; object-fit: cover; }

  #old_dues_amount_container { margin-top: 10px; }
  .two-column-form .row > div { margin-bottom: 10px; }

  @media (max-width: 768px) {
    .two-column-form .row > div { margin-bottom: 10px; }
    .form-group-inline { flex-direction: column; align-items: flex-start; }
    .form-group-inline .form-control { width: 100%; }
  }
</style>

<?php if (basename($_SERVER['PHP_SELF']) === 'index.php'): ?>
<?php
$dashboard_username = trim((string) ($_SESSION['username'] ?? 'Account'));
$dashboard_initials = strtoupper(substr($dashboard_username, 0, 2));
$dashboard_icon_for_title = static function ($title) {
    $title = strtolower((string) $title);
    $icon_rules = [
        ['dashboard', 'fa-gauge-high'], ['student', 'fa-user-graduate'],
        ['admission', 'fa-user-plus'], ['parent', 'fa-people-roof'],
        ['teacher', 'fa-chalkboard-user'], ['staff', 'fa-people-group'],
        ['attendance', 'fa-calendar-check'], ['fee', 'fa-coins'],
        ['payment', 'fa-credit-card'], ['salary', 'fa-money-check-dollar'],
        ['finance', 'fa-wallet'], ['expense', 'fa-receipt'],
        ['class', 'fa-chalkboard'], ['section', 'fa-users'],
        ['subject', 'fa-book-open'], ['exam', 'fa-file-pen'],
        ['result', 'fa-square-poll-vertical'], ['timetable', 'fa-calendar-days'],
        ['report', 'fa-chart-pie'], ['transport', 'fa-bus'],
        ['vehicle', 'fa-truck'], ['library', 'fa-book'],
        ['book', 'fa-book-open'], ['inventory', 'fa-boxes-stacked'],
        ['hostel', 'fa-building'], ['message', 'fa-comments'],
        ['notice', 'fa-bullhorn'], ['event', 'fa-calendar-day'],
        ['website', 'fa-globe'], ['setting', 'fa-gear'],
        ['role', 'fa-shield-halved'], ['permission', 'fa-key'],
        ['user', 'fa-users'], ['account', 'fa-address-card'],
        ['certificate', 'fa-certificate'], ['subscription', 'fa-rotate'],
        ['gateway', 'fa-arrow-right-arrow-left'], ['app', 'fa-table-cells'],
        ['hr', 'fa-briefcase'], ['logout', 'fa-right-from-bracket']
    ];
    foreach ($icon_rules as [$keyword, $icon]) {
        if (strpos($title, $keyword) !== false) return $icon;
    }
    return 'fa-layer-group';
};
$dashboard_due_notifications = [];
if (isset($upcoming_dues_result) && $upcoming_dues_result instanceof mysqli_result) {
    while ($due_notification = $upcoming_dues_result->fetch_assoc()) {
        $dashboard_due_notifications[] = $due_notification;
    }
}
$dashboard_accessible_urls = [];
foreach ($role_modules as $dashboard_module) {
    if (!empty($dashboard_module['url']) && $dashboard_module['url'] !== '#') {
        $dashboard_accessible_urls[] = $dashboard_module['url'];
    }
    foreach (($dashboard_module['children'] ?? []) as $dashboard_child) {
        if (!empty($dashboard_child['url']) && $dashboard_child['url'] !== '#') {
            $dashboard_accessible_urls[] = $dashboard_child['url'];
        }
    }
}
$dashboard_quick_actions = [
    ['url' => 'student_registration.php', 'icon' => 'fa-user-plus', 'label' => 'New student'],
    ['url' => 'fee_collection.php', 'icon' => 'fa-money-bill-wave', 'label' => 'Collect fee'],
    ['url' => 'attendance.php', 'icon' => 'fa-calendar-check', 'label' => 'Attendance'],
    ['url' => 'expense.php', 'icon' => 'fa-receipt', 'label' => 'Add expense']
];
$dashboard_quick_actions = array_values(array_filter($dashboard_quick_actions, function ($action) use ($dashboard_accessible_urls) {
    return in_array($action['url'], $dashboard_accessible_urls, true);
}));
?>
<header class="dashboard-topbar">
  <button type="button" class="dashboard-menu-toggle" id="dashboardSidebarToggle" aria-label="Toggle navigation" aria-expanded="false">
    <i class="fa-solid fa-bars"></i>
  </button>
  <a class="dashboard-brand" href="index.php">
    <span>School Management System</span>
  </a>
  <div class="dashboard-topbar-actions">
    <button type="button" class="dashboard-topbar-icon" id="dashboardSearchToggle" aria-label="Search students" aria-expanded="false">
      <i class="fa-solid fa-magnifying-glass"></i>
    </button>
    <?php if (!empty($dashboard_quick_actions)): ?>
    <div class="dashboard-topbar-menu-wrap">
      <button type="button" class="dashboard-topbar-icon" data-dashboard-menu="quickActionsMenu" aria-label="Quick actions" aria-expanded="false">
        <i class="fa-solid fa-plus"></i>
      </button>
      <div class="dashboard-topbar-menu dashboard-quick-menu" id="quickActionsMenu" hidden>
        <div class="dashboard-menu-heading">Quick actions</div>
        <?php foreach ($dashboard_quick_actions as $quick_action): ?>
          <a href="<?php echo htmlspecialchars($quick_action['url'], ENT_QUOTES, 'UTF-8'); ?>">
            <i class="fa-solid <?php echo htmlspecialchars($quick_action['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
            <?php echo htmlspecialchars($quick_action['label'], ENT_QUOTES, 'UTF-8'); ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <div class="dashboard-topbar-menu-wrap">
      <button type="button" class="dashboard-topbar-icon dashboard-notification-button" data-dashboard-menu="notificationsMenu" aria-label="Upcoming fee reminders" aria-expanded="false">
        <i class="fa-regular fa-bell"></i>
        <?php if (!empty($dashboard_due_notifications)): ?>
          <span class="dashboard-notification-count"><?php echo count($dashboard_due_notifications); ?></span>
        <?php endif; ?>
      </button>
      <div class="dashboard-topbar-menu dashboard-notifications-menu" id="notificationsMenu" hidden>
        <div class="dashboard-menu-heading">Upcoming fee dues</div>
        <?php if (!empty($dashboard_due_notifications)): ?>
          <?php foreach (array_slice($dashboard_due_notifications, 0, 5) as $due_notification): ?>
            <a class="dashboard-notification-item" href="student_feecards.php">
              <span class="dashboard-notification-icon"><i class="fa-solid fa-calendar-day"></i></span>
              <span>
                <strong><?php echo (int) $due_notification['student_count']; ?> students</strong>
                <small><?php echo htmlspecialchars(date('M j, Y', strtotime($due_notification['due_date'])), ENT_QUOTES, 'UTF-8'); ?> · Rs. <?php echo number_format((float) $due_notification['total_amount']); ?></small>
              </span>
            </a>
          <?php endforeach; ?>
          <a class="dashboard-notifications-all" href="student_feecards.php">View fee cards</a>
        <?php else: ?>
          <p class="dashboard-notifications-empty">No upcoming fee dues.</p>
        <?php endif; ?>
      </div>
    </div>
    <button type="button" class="dashboard-topbar-icon" id="dashboardModulesToggle" aria-label="Browse modules">
      <i class="fa-solid fa-grip"></i>
    </button>
    <span class="dashboard-account-name"><?php echo htmlspecialchars($dashboard_username, ENT_QUOTES, 'UTF-8'); ?></span>
    <div class="dashboard-topbar-menu-wrap">
      <button type="button" class="dashboard-user-avatar" data-dashboard-menu="userMenu" aria-label="Account options" aria-expanded="false" title="<?php echo htmlspecialchars($dashboard_username, ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($dashboard_initials, ENT_QUOTES, 'UTF-8'); ?>
      </button>
      <div class="dashboard-topbar-menu dashboard-user-menu" id="userMenu" hidden>
        <div class="dashboard-menu-heading"><?php echo htmlspecialchars($dashboard_username, ENT_QUOTES, 'UTF-8'); ?></div>
        <a href="profile.php"><i class="fa-solid fa-user"></i> Profile</a>
        <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
      </div>
    </div>
  </div>
</header>

<?php if (!$__is_parent_role): ?>
<aside class="dashboard-sidebar" id="dashboardSidebar" aria-label="Main navigation">
  <nav class="dashboard-sidebar-nav">
    <label class="dashboard-sidebar-search">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="search" id="dashboardSidebarSearch" placeholder="Search menu" autocomplete="off">
    </label>
    <div class="dashboard-nav-caption">MAIN NAVIGATION</div>
    <a class="dashboard-sidebar-link active" href="index.php">
      <i class="fa-solid fa-gauge-high"></i>
      <span>Dashboard</span>
    </a>

    <div class="dashboard-nav-caption dashboard-nav-caption-spaced">MANAGEMENT</div>
    <?php foreach ($role_modules as $module_id => $module): ?>
      <?php
      if (isset($module_parents[$module_id])) continue;
      $module_title = (string) ($module['title'] ?? '');
      $module_url = (string) ($module['url'] ?? '#');
      $module_children = $module['children'] ?? [];
      $module_dom_id = 'dashboard-module-' . (int) $module_id;
      ?>
      <?php if (!empty($module_children)): ?>
        <section class="dashboard-sidebar-group">
          <button type="button" class="dashboard-sidebar-link dashboard-sidebar-group-toggle" aria-expanded="false" aria-controls="<?php echo htmlspecialchars($module_dom_id, ENT_QUOTES, 'UTF-8'); ?>">
            <i class="fa-solid <?php echo htmlspecialchars($dashboard_icon_for_title($module_title), ENT_QUOTES, 'UTF-8'); ?>"></i>
            <span><?php echo htmlspecialchars($module_title, ENT_QUOTES, 'UTF-8'); ?></span>
            <i class="fa-solid fa-chevron-down dashboard-sidebar-chevron"></i>
          </button>
          <div class="dashboard-sidebar-submenu" id="<?php echo htmlspecialchars($module_dom_id, ENT_QUOTES, 'UTF-8'); ?>" hidden>
            <?php foreach ($module_children as $child): ?>
              <a class="dashboard-sidebar-sublink" href="<?php echo htmlspecialchars((string)($child['url'] ?? '#'), ENT_QUOTES, 'UTF-8'); ?>">
                <i class="fa-solid <?php echo htmlspecialchars($dashboard_icon_for_title((string)($child['title'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>"></i>
                <span><?php echo htmlspecialchars((string)($child['title'] ?? 'Untitled'), ENT_QUOTES, 'UTF-8'); ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </section>
      <?php elseif ($module_url !== '#'): ?>
        <a class="dashboard-sidebar-link" href="<?php echo htmlspecialchars($module_url, ENT_QUOTES, 'UTF-8'); ?>">
          <i class="fa-solid <?php echo htmlspecialchars($dashboard_icon_for_title($module_title), ENT_QUOTES, 'UTF-8'); ?>"></i>
          <span><?php echo htmlspecialchars($module_title, ENT_QUOTES, 'UTF-8'); ?></span>
        </a>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>
  <div class="dashboard-sidebar-footer">
    <a class="dashboard-sidebar-link" href="logout.php">
      <i class="fa-solid fa-right-from-bracket"></i>
      <span>Logout</span>
    </a>
  </div>
</aside>
<button type="button" class="dashboard-sidebar-backdrop" id="dashboardSidebarBackdrop" aria-label="Close navigation"></button>
<?php endif; ?>

<script>
  (function () {
    var toggle = document.getElementById('dashboardSidebarToggle');
    var backdrop = document.getElementById('dashboardSidebarBackdrop');
    var isParent = <?php echo $__is_parent_role ? 'true' : 'false'; ?>;

    function syncNavigationForViewport() {
      var isMobile = window.matchMedia('(max-width: 767.98px)').matches;
      if (isMobile) document.body.classList.remove('dashboard-sidebar-collapsed');
      else document.body.classList.remove('dashboard-sidebar-open');
      if (toggle) {
        var isExpanded = isMobile
          ? document.body.classList.contains('dashboard-sidebar-open')
          : !document.body.classList.contains('dashboard-sidebar-collapsed');
        toggle.setAttribute('aria-expanded', String(isExpanded));
      }
    }

    syncNavigationForViewport();
    window.addEventListener('resize', syncNavigationForViewport);

    function closeMobileSidebar() {
      document.body.classList.remove('dashboard-sidebar-open');
      if (toggle && window.matchMedia('(max-width: 767.98px)').matches) {
        toggle.setAttribute('aria-expanded', 'false');
      }
    }

    // Parents: hide the toggle button entirely
    if (isParent && toggle) {
      toggle.style.display = 'none';
    }

    if (toggle && !isParent) {
      toggle.addEventListener('click', function () {
        if (window.matchMedia('(max-width: 767.98px)').matches) {
          var isOpen = document.body.classList.toggle('dashboard-sidebar-open');
          toggle.setAttribute('aria-expanded', String(isOpen));
        } else {
          var isCollapsed = document.body.classList.toggle('dashboard-sidebar-collapsed');
          toggle.setAttribute('aria-expanded', String(!isCollapsed));
        }
      });
    }

    if (backdrop) backdrop.addEventListener('click', closeMobileSidebar);

    document.querySelectorAll('.dashboard-sidebar-group-toggle').forEach(function (groupToggle) {
      groupToggle.addEventListener('click', function () {
        var submenu = document.getElementById(groupToggle.getAttribute('aria-controls'));
        var isExpanded = groupToggle.getAttribute('aria-expanded') === 'true';
        groupToggle.setAttribute('aria-expanded', String(!isExpanded));
        if (submenu) submenu.hidden = isExpanded;
      });
    });

    document.querySelectorAll('[data-dashboard-menu]').forEach(function (menuButton) {
      menuButton.addEventListener('click', function (event) {
        event.stopPropagation();
        var menu = document.getElementById(menuButton.getAttribute('data-dashboard-menu'));
        var shouldOpen = menu && menu.hidden;
        document.querySelectorAll('.dashboard-topbar-menu').forEach(function (item) { item.hidden = true; });
        document.querySelectorAll('[data-dashboard-menu]').forEach(function (button) { button.setAttribute('aria-expanded', 'false'); });
        if (menu && shouldOpen) {
          menu.hidden = false;
          menuButton.setAttribute('aria-expanded', 'true');
        }
      });
    });

    document.addEventListener('click', function (event) {
      if (!event.target.closest('.dashboard-topbar-menu-wrap')) {
        document.querySelectorAll('.dashboard-topbar-menu').forEach(function (menu) { menu.hidden = true; });
        document.querySelectorAll('[data-dashboard-menu]').forEach(function (button) { button.setAttribute('aria-expanded', 'false'); });
      }
    });

    var searchToggle = document.getElementById('dashboardSearchToggle');
    if (searchToggle) {
      searchToggle.addEventListener('click', function () {
        var searchInput = document.getElementById('searchInput');
        if (searchInput) {
          var isOpen = document.body.classList.toggle('dashboard-student-search-open');
          searchToggle.setAttribute('aria-expanded', String(isOpen));
          if (isOpen) { searchInput.focus(); searchInput.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
        }
      });
    }

    var modulesToggle = document.getElementById('dashboardModulesToggle');
    if (modulesToggle) {
      modulesToggle.addEventListener('click', function () {
        var moduleHub = document.getElementById('ecosystemHub');
        if (moduleHub) {
          moduleHub.scrollIntoView({ behavior: 'smooth', block: 'start' });
          var moduleSearch = document.getElementById('moduleSearch');
          if (moduleSearch) moduleSearch.focus({ preventScroll: true });
        }
      });
    }

    var sidebarSearch = document.getElementById('dashboardSidebarSearch');
    if (sidebarSearch) {
      sidebarSearch.addEventListener('input', function () {
        var searchTerm = sidebarSearch.value.trim().toLowerCase();
        document.querySelectorAll('.dashboard-sidebar-group').forEach(function (group) {
          var toggle = group.querySelector('.dashboard-sidebar-group-toggle');
          var submenu = group.querySelector('.dashboard-sidebar-submenu');
          var groupMatches = toggle && toggle.innerText.toLowerCase().includes(searchTerm);
          var visibleChildren = 0;
          group.querySelectorAll('.dashboard-sidebar-sublink').forEach(function (link) {
            var matches = !searchTerm || groupMatches || link.innerText.toLowerCase().includes(searchTerm);
            link.hidden = !matches;
            if (matches) visibleChildren += 1;
          });
          group.hidden = searchTerm !== '' && !groupMatches && visibleChildren === 0;
          if (searchTerm && !group.hidden && submenu && !groupMatches) {
            submenu.hidden = false;
            if (toggle) toggle.setAttribute('aria-expanded', 'true');
          }
        });
        document.querySelectorAll('.dashboard-sidebar-nav > .dashboard-sidebar-link').forEach(function (link) {
          link.hidden = searchTerm !== '' && !link.innerText.toLowerCase().includes(searchTerm);
        });
      });
    }

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        closeMobileSidebar();
        document.body.classList.remove('dashboard-student-search-open');
        if (searchToggle) searchToggle.setAttribute('aria-expanded', 'false');
        document.querySelectorAll('.dashboard-topbar-menu').forEach(function (menu) { menu.hidden = true; });
        document.querySelectorAll('[data-dashboard-menu]').forEach(function (button) { button.setAttribute('aria-expanded', 'false'); });
      }
    });
  }());
</script>

<?php else: ?>
<?php
$navigation_username = trim((string)($_SESSION['username'] ?? 'Account'));
$navigation_initials = strtoupper(substr($navigation_username, 0, 2));
$navigation_current_page = basename($_SERVER['PHP_SELF'] ?? '');
?>
<style>
  body:not(.dashboard-page) {
    padding-top: 64px !important;
    padding-left: 260px !important;
    transition: padding-left 0.22s ease;
  }

  <?php if ($__is_parent_role): ?>
  body:not(.dashboard-page) {
    padding-left: 0 !important;
  }
  <?php endif; ?>

  body:not(.dashboard-page) .dashboard-topbar {
    position: fixed;
    z-index: 1040;
    inset: 0 0 auto;
    display: flex;
    align-items: center;
    height: 64px;
    padding: 0 1.35rem;
    background: #fff;
    border-bottom: 1px solid #ebe9e7;
    box-shadow: 0 2px 8px rgba(36, 31, 27, 0.035);
  }

  body:not(.dashboard-page) .dashboard-menu-toggle,
  body:not(.dashboard-page) .dashboard-user-avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    flex: 0 0 40px;
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: #6f6c69;
    cursor: pointer;
    font-size: 1rem;
  }

  body:not(.dashboard-page) .dashboard-brand {
    display: inline-flex;
    align-items: center;
    gap: 0.65rem;
    margin-left: 0.8rem;
    color: #302c29;
    font-size: 0.95rem;
    font-weight: 750;
    text-decoration: none;
  }

  body:not(.dashboard-page) .dashboard-brand small {
    display: block;
    color: #77736f;
    font-size: 0.55rem;
    font-weight: 600;
    letter-spacing: 0.06em;
  }

  body:not(.dashboard-page) .dashboard-account-name {
    margin-left: auto;
    color: #686562;
    font-size: 0.85rem;
  }

  body:not(.dashboard-page) .dashboard-user-avatar {
    margin-left: 0.75rem;
    border: 3px solid #fff;
    border-radius: 50%;
    background: #ed8b43;
    box-shadow: 0 1px 6px rgba(36, 31, 27, 0.2);
    color: #fff;
    font-size: 0.74rem;
    font-weight: 700;
  }

  body:not(.dashboard-page) .dashboard-topbar-menu-wrap { position: relative; }

  body:not(.dashboard-page) .dashboard-topbar-menu {
    position: absolute;
    z-index: 1050;
    top: calc(100% + 0.65rem);
    right: 0;
    min-width: 190px;
    padding: 0.45rem;
    border: 1px solid #ebe9e7;
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 12px 30px rgba(36, 31, 27, 0.14);
  }

  body:not(.dashboard-page) .dashboard-topbar-menu[hidden] { display: none; }

  body:not(.dashboard-page) .dashboard-menu-heading {
    padding: 0.6rem 0.7rem;
    color: #77736f;
    font-size: 0.76rem;
    font-weight: 650;
  }

  body:not(.dashboard-page) .dashboard-topbar-menu a {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    padding: 0.65rem 0.7rem;
    border-radius: 8px;
    color: #302c29;
    font-size: 0.82rem;
    text-decoration: none;
  }

  body:not(.dashboard-page) .dashboard-topbar-menu a:hover { background: #f3f4f8; }

  body:not(.dashboard-page) .dashboard-sidebar {
    position: fixed;
    z-index: 1035;
    top: 64px;
    bottom: 0;
    left: 0;
    display: flex;
    flex-direction: column;
    width: 260px;
    overflow-x: hidden;
    overflow-y: auto;
    background: #292d39;
    color: #e6e7eb;
    transition: width 0.22s ease, transform 0.22s ease;
  }

  body:not(.dashboard-page) .dashboard-sidebar-nav { padding: 1.1rem 0.8rem 0.5rem; }

  body:not(.dashboard-page) .dashboard-sidebar-search {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    height: 40px;
    margin: 0 0.1rem 1rem;
    padding: 0 0.7rem;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 9px;
    background: rgba(255, 255, 255, 0.07);
    color: #aeb1bb;
  }

  body:not(.dashboard-page) .dashboard-sidebar-search input {
    width: 100%;
    min-width: 0;
    border: 0;
    outline: 0;
    background: transparent;
    color: #f4f4f6;
    font-size: 0.76rem;
  }

  body:not(.dashboard-page) .dashboard-nav-caption {
    padding: 0.35rem 0.8rem 0.55rem;
    color: #989ba5;
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 0.11em;
  }

  body:not(.dashboard-page) .dashboard-nav-caption-spaced { margin-top: 0.8rem; }

  body:not(.dashboard-page) .dashboard-sidebar-link {
    position: relative;
    display: flex;
    align-items: center;
    gap: 0.85rem;
    width: 100%;
    min-height: 43px;
    margin: 0.15rem 0;
    padding: 0.65rem 0.8rem;
    border: 0;
    border-radius: 9px;
    background: transparent;
    color: #d1d2d8;
    cursor: pointer;
    font: inherit;
    font-size: 0.83rem;
    text-align: left;
    text-decoration: none;
  }

  body:not(.dashboard-page) .dashboard-sidebar-link > i:first-child {
    width: 19px;
    color: #a8abb5;
    font-size: 0.94rem;
    text-align: center;
  }

  body:not(.dashboard-page) .dashboard-sidebar-link:hover,
  body:not(.dashboard-page) .dashboard-sidebar-sublink:hover {
    background: #353a48;
    color: #fff;
  }

  body:not(.dashboard-page) .dashboard-sidebar-link.active {
    background: #f09a50;
    color: #fff;
    font-weight: 650;
  }

  body:not(.dashboard-page) .dashboard-sidebar-group-toggle { appearance: none; }

  body:not(.dashboard-page) .dashboard-sidebar-chevron {
    margin-left: auto;
    font-size: 0.65rem;
    transition: transform 0.18s ease;
  }

  body:not(.dashboard-page) .dashboard-sidebar-group-toggle[aria-expanded="true"] .dashboard-sidebar-chevron {
    transform: rotate(180deg);
  }

  body:not(.dashboard-page) .dashboard-sidebar-submenu { padding: 0.15rem 0 0.35rem 1.1rem; }
  body:not(.dashboard-page) .dashboard-sidebar-submenu[hidden] { display: none; }

  body:not(.dashboard-page) .dashboard-sidebar-sublink {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    min-height: 36px;
    padding: 0.45rem 0.7rem 0.45rem 1.45rem;
    border-radius: 8px;
    color: #bfc1c9;
    font-size: 0.78rem;
    text-decoration: none;
  }

  body:not(.dashboard-page) .dashboard-sidebar-footer {
    margin-top: auto;
    padding: 0.6rem 0.8rem 0.9rem;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
  }

  body:not(.dashboard-page) .dashboard-sidebar-backdrop { display: none; }

  body:not(.dashboard-page).dashboard-sidebar-collapsed { padding-left: 76px !important; }
  body:not(.dashboard-page).dashboard-sidebar-collapsed .dashboard-sidebar { width: 76px; }

  body:not(.dashboard-page).dashboard-sidebar-collapsed .dashboard-nav-caption,
  body:not(.dashboard-page).dashboard-sidebar-collapsed .dashboard-sidebar-search,
  body:not(.dashboard-page).dashboard-sidebar-collapsed .dashboard-sidebar-link span,
  body:not(.dashboard-page).dashboard-sidebar-collapsed .dashboard-sidebar-chevron,
  body:not(.dashboard-page).dashboard-sidebar-collapsed .dashboard-sidebar-submenu,
  body:not(.dashboard-page).dashboard-sidebar-collapsed .dashboard-sidebar-footer span {
    display: none;
  }

  body:not(.dashboard-page).dashboard-sidebar-collapsed .dashboard-sidebar-link {
    justify-content: center;
    padding-right: 0.5rem;
    padding-left: 0.5rem;
  }

  @media (max-width: 767.98px) {
    body:not(.dashboard-page) { padding-left: 0 !important; }

    body:not(.dashboard-page) .dashboard-sidebar {
      width: 260px;
      transform: translateX(-100%);
    }

    body:not(.dashboard-page).dashboard-sidebar-open .dashboard-sidebar { transform: translateX(0); }

    body:not(.dashboard-page) .dashboard-sidebar-backdrop {
      position: fixed;
      z-index: 1030;
      inset: 64px 0 0;
      border: 0;
      background: rgba(24, 26, 33, 0.38);
    }

    body:not(.dashboard-page).dashboard-sidebar-open .dashboard-sidebar-backdrop { display: block; }
    body:not(.dashboard-page) .dashboard-topbar { padding: 0 0.75rem; }
    body:not(.dashboard-page) .dashboard-account-name { display: none; }
  }
</style>
<header class="dashboard-topbar">
  <?php if (!$__is_parent_role): ?>
  <button type="button" class="dashboard-menu-toggle" id="dashboardSidebarToggle" aria-label="Toggle navigation" aria-expanded="false">
    <i class="fa-solid fa-bars"></i>
  </button>
  <?php endif; ?>
  <a class="dashboard-brand" href="index.php">
    <span>School Management System</span>
  </a>
  <span class="dashboard-account-name"><?php echo htmlspecialchars($navigation_username, ENT_QUOTES, 'UTF-8'); ?></span>
  <div class="dashboard-topbar-menu-wrap">
    <button type="button" class="dashboard-user-avatar" data-dashboard-menu="userMenu" aria-label="Account options" aria-expanded="false" title="<?php echo htmlspecialchars($navigation_username, ENT_QUOTES, 'UTF-8'); ?>">
      <?php echo htmlspecialchars($navigation_initials, ENT_QUOTES, 'UTF-8'); ?>
    </button>
    <div class="dashboard-topbar-menu dashboard-user-menu" id="userMenu" hidden>
      <div class="dashboard-menu-heading"><?php echo htmlspecialchars($navigation_username, ENT_QUOTES, 'UTF-8'); ?></div>
      <a href="profile.php"><i class="fa-solid fa-user"></i> Profile</a>
      <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
  </div>
</header>

<?php if (!$__is_parent_role): ?>
<aside class="dashboard-sidebar" id="dashboardSidebar" aria-label="Main navigation">
  <nav class="dashboard-sidebar-nav">
    <label class="dashboard-sidebar-search">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="search" id="dashboardSidebarSearch" placeholder="Search menu" autocomplete="off">
    </label>
    <div class="dashboard-nav-caption">MAIN NAVIGATION</div>
    <a class="dashboard-sidebar-link<?php echo $navigation_current_page === 'index.php' ? ' active' : ''; ?>" href="index.php">
      <i class="fa-solid fa-gauge-high"></i>
      <span>Dashboard</span>
    </a>
    <div class="dashboard-nav-caption dashboard-nav-caption-spaced">MANAGEMENT</div>
    <?php foreach ($role_modules as $module_id => $module): ?>
      <?php
      if (isset($module_parents[$module_id])) continue;
      $module_title = (string)($module['title'] ?? '');
      $module_url = (string)($module['url'] ?? '#');
      $module_children = $module['children'] ?? [];
      $module_dom_id = 'dashboard-module-' . (int)$module_id;
      ?>
      <?php if (!empty($module_children)): ?>
        <section class="dashboard-sidebar-group">
          <button type="button" class="dashboard-sidebar-link dashboard-sidebar-group-toggle" aria-expanded="false" aria-controls="<?php echo htmlspecialchars($module_dom_id, ENT_QUOTES, 'UTF-8'); ?>">
            <i class="fa-solid fa-layer-group"></i>
            <span><?php echo htmlspecialchars($module_title, ENT_QUOTES, 'UTF-8'); ?></span>
            <i class="fa-solid fa-chevron-down dashboard-sidebar-chevron"></i>
          </button>
          <div class="dashboard-sidebar-submenu" id="<?php echo htmlspecialchars($module_dom_id, ENT_QUOTES, 'UTF-8'); ?>" hidden>
            <?php foreach ($module_children as $child): ?>
              <?php
              $child_title = (string)($child['title'] ?? 'Untitled');
              $child_url = (string)($child['url'] ?? '#');
              $child_is_active = basename(parse_url($child_url, PHP_URL_PATH) ?: $child_url) === $navigation_current_page;
              ?>
              <a class="dashboard-sidebar-sublink<?php echo $child_is_active ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($child_url, ENT_QUOTES, 'UTF-8'); ?>">
                <i class="fa-solid fa-circle"></i>
                <span><?php echo htmlspecialchars($child_title, ENT_QUOTES, 'UTF-8'); ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </section>
      <?php elseif ($module_url !== '#'): ?>
        <?php $module_is_active = basename(parse_url($module_url, PHP_URL_PATH) ?: $module_url) === $navigation_current_page; ?>
        <a class="dashboard-sidebar-link<?php echo $module_is_active ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($module_url, ENT_QUOTES, 'UTF-8'); ?>">
          <i class="fa-solid fa-layer-group"></i>
          <span><?php echo htmlspecialchars($module_title, ENT_QUOTES, 'UTF-8'); ?></span>
        </a>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>
  <div class="dashboard-sidebar-footer">
    <a class="dashboard-sidebar-link" href="logout.php">
      <i class="fa-solid fa-right-from-bracket"></i>
      <span>Logout</span>
    </a>
  </div>
</aside>
<button type="button" class="dashboard-sidebar-backdrop" id="dashboardSidebarBackdrop" aria-label="Close navigation"></button>
<?php endif; ?>

<script>
  (function () {
    var toggle = document.getElementById('dashboardSidebarToggle');
    var backdrop = document.getElementById('dashboardSidebarBackdrop');
    var search = document.getElementById('dashboardSidebarSearch');
    var isParent = <?php echo $__is_parent_role ? 'true' : 'false'; ?>;

    function syncNavigationForViewport() {
      var isMobile = window.matchMedia('(max-width: 767.98px)').matches;
      if (isMobile) document.body.classList.remove('dashboard-sidebar-collapsed');
      else document.body.classList.remove('dashboard-sidebar-open');
      if (toggle) {
        var isExpanded = isMobile
          ? document.body.classList.contains('dashboard-sidebar-open')
          : !document.body.classList.contains('dashboard-sidebar-collapsed');
        toggle.setAttribute('aria-expanded', String(isExpanded));
      }
    }

    syncNavigationForViewport();
    window.addEventListener('resize', syncNavigationForViewport);

    function closeMobileSidebar() {
      document.body.classList.remove('dashboard-sidebar-open');
      if (toggle && window.matchMedia('(max-width: 767.98px)').matches) {
        toggle.setAttribute('aria-expanded', 'false');
      }
    }

    if (toggle && !isParent) {
      toggle.addEventListener('click', function () {
        if (window.matchMedia('(max-width: 767.98px)').matches) {
          var isOpen = document.body.classList.toggle('dashboard-sidebar-open');
          toggle.setAttribute('aria-expanded', String(isOpen));
        } else {
          var isCollapsed = document.body.classList.toggle('dashboard-sidebar-collapsed');
          toggle.setAttribute('aria-expanded', String(!isCollapsed));
        }
      });
    }

    if (backdrop) backdrop.addEventListener('click', closeMobileSidebar);

    document.querySelectorAll('[data-dashboard-menu]').forEach(function (menuButton) {
      menuButton.addEventListener('click', function (event) {
        event.stopPropagation();
        var menu = document.getElementById(menuButton.getAttribute('data-dashboard-menu'));
        var shouldOpen = menu && menu.hidden;
        document.querySelectorAll('.dashboard-topbar-menu').forEach(function (item) { item.hidden = true; });
        document.querySelectorAll('[data-dashboard-menu]').forEach(function (button) { button.setAttribute('aria-expanded', 'false'); });
        if (menu && shouldOpen) {
          menu.hidden = false;
          menuButton.setAttribute('aria-expanded', 'true');
        }
      });
    });

    document.addEventListener('click', function (event) {
      if (!event.target.closest('.dashboard-topbar-menu-wrap')) {
        document.querySelectorAll('.dashboard-topbar-menu').forEach(function (menu) { menu.hidden = true; });
        document.querySelectorAll('[data-dashboard-menu]').forEach(function (button) { button.setAttribute('aria-expanded', 'false'); });
      }
    });

    document.querySelectorAll('.dashboard-sidebar-group-toggle').forEach(function (groupToggle) {
      groupToggle.addEventListener('click', function () {
        var submenu = document.getElementById(groupToggle.getAttribute('aria-controls'));
        var isExpanded = groupToggle.getAttribute('aria-expanded') === 'true';
        groupToggle.setAttribute('aria-expanded', String(!isExpanded));
        if (submenu) submenu.hidden = isExpanded;
      });
    });

    if (search) {
      search.addEventListener('input', function () {
        var searchTerm = search.value.trim().toLowerCase();
        document.querySelectorAll('.dashboard-sidebar-group').forEach(function (group) {
          var groupToggle = group.querySelector('.dashboard-sidebar-group-toggle');
          var submenu = group.querySelector('.dashboard-sidebar-submenu');
          var groupMatches = groupToggle && groupToggle.innerText.toLowerCase().includes(searchTerm);
          var visibleChildren = 0;
          group.querySelectorAll('.dashboard-sidebar-sublink').forEach(function (link) {
            var matches = !searchTerm || groupMatches || link.innerText.toLowerCase().includes(searchTerm);
            link.hidden = !matches;
            if (matches) visibleChildren++;
          });
          group.hidden = searchTerm !== '' && !groupMatches && visibleChildren === 0;
          if (searchTerm && !group.hidden && submenu && !groupMatches) {
            submenu.hidden = false;
            groupToggle.setAttribute('aria-expanded', 'true');
          }
        });
        document.querySelectorAll('.dashboard-sidebar-nav > .dashboard-sidebar-link').forEach(function (link) {
          link.hidden = searchTerm !== '' && !link.innerText.toLowerCase().includes(searchTerm);
        });
      });
    }
  }());
</script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>