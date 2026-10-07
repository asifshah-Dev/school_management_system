<?php

require_once('security.php');
require_once('conn_inc.php');

// Fetch role modules for current user
$user_id = $_SESSION['user_id'] ?? 0; 

$role_modules = [];
$module_parents = [];

if ($user_id) {
    $qry = "
        SELECT m.id, m.parent_id, m.title, m.url 
        FROM role_modules rm
        INNER JOIN modules m ON rm.module_id = m.id
        INNER JOIN users u ON rm.role_id = u.role_id
        WHERE u.id = ?
        ORDER BY m.parent_id, m.title
    ";
    $stmt = $conn->prepare($qry);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    // Group modules by parent_id
    while ($row = $res->fetch_assoc()) {
        $parent_id = $row['parent_id'] ?? 0;
        if ($parent_id == 0) {
            // This is a parent module
            if (!isset($role_modules[$row['id']])) {
                $role_modules[$row['id']] = [
                    'title' => $row['title'],
                    'url' => $row['url'] ?? '#',
                    'children' => []
                ];
            }
        } else {
            // This is a child module - ensure parent exists
            if (!isset($role_modules[$parent_id])) {
                // Create parent if it doesn't exist (shouldn't happen but as fallback)
                $role_modules[$parent_id] = [
                    'title' => 'Parent Module',
                    'url' => '#',
                    'children' => []
                ];
            }
            $module_parents[$row['id']] = $parent_id;
            $role_modules[$parent_id]['children'][] = [
                'title' => $row['title'],
                'url' => $row['url'] ?? '#'
            ];
        }
    }
    $stmt->close();
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
    
    /* Quick links color - #3b3a85 */
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
  
  /* Top Quick Links Bar - Separate from Navbar */
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

  /* Hide quick links on mobile/tablet */
  @media (max-width: 991px) {
    .top-quick-bar {
      display: none;
    }
  }

  /* Main Navbar */
  .navbar { 
    background: var(--background);
    height: 55px;
    backdrop-filter: blur(20px);
    box-shadow: var(--shadow-sm);
    padding: 0.5rem 1.5rem;
    z-index: 1030;
    transition: var(--transition);
  }
  
  .navbar-main-content {
    display: flex;
    align-items: center;
    width: 100%;
  }
  
  .navbar-brand { 
    font-weight: 700; 
    color: var(--text-primary) !important; 
    display: flex; 
    align-items: center; 
    font-size: 1.1rem;
    transition: var(--transition);
    margin-right: 2rem;
  }
  
  .navbar-brand:hover {
    transform: translateY(-1px);
  }
  
  .navbar-brand img { 
    height: 36px;
    margin-right: 10px;
    border-radius: 8px;
    transition: var(--transition);
    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.1));
  }
  
  .navbar-brand:hover img {
    transform: scale(1.05);
  }
  
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
  
  .brand-text span {
    font-size: 20px !important;
  }
  
  .brand-text small {
    font-size: 8px !important;
  }
  
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
  
  .nav-link.active {
    font-weight: 600;
  }
  
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
  
  .dropdown-divider {
    border-color: var(--border);
    margin: 0.3rem 0;
  }
  
  .btn-logout { 
    font-weight: 600; 
    border-radius: 8px;
    padding: 0.4rem 1rem;
    font-size: 0.75rem;
    transition: var(--Transition);
    border: none;
    margin-left: 20px !important;
  }
  
  .btn-logout:hover { 
    background: #bab9b9 100%; 
    transform: translateY(-2px);
  }
  
  @keyframes slideIn { 
    from { 
      opacity: 0; 
      transform: translateY(-8px) scale(0.98); 
    } 
    to { 
      opacity: 1; 
      transform: translateY(0) scale(1); 
    } 
  }
  
  .navbar-toggler {
    border: none;
    padding: 0.4rem;
    border-radius: 8px;
    transition: var(--transition);
    background: var(--surface);
  }
  
  .navbar-toggler:focus {
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
  }
  
  .navbar-toggler-icon {
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba(30, 41, 59, 0.7)' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
    width: 1.2em;
    height: 1.2em;
  }
  
  @media (max-width: 991px) {
    .navbar { 
      padding: 0.5rem 1rem;
    }
    
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
    
    .dropdown-item { 
      padding: 0.5rem 0.8rem;
      font-size: 0.8rem;
    }
    
    .btn-logout { 
      margin-top: 0.8rem;
      width: 100%;
      text-align: center;
    }

    .navbar-brand {
      margin-right: 1rem;
    }

    .navbar-brand img {
      height: 32px;
    }

    .brand-text span {
      font-size: 18px !important;
    }

    .brand-text small {
      font-size: 7px !important;
    }
  }

  /* Extra small devices */
  @media (max-width: 575px) {
    .navbar {
      padding: 0.4rem 0.8rem;
    }

    .navbar-brand {
      font-size: 1rem;
    }

    .navbar-brand img {
      height: 28px;
      margin-right: 6px;
    }

    .brand-text span {
      font-size: 16px !important;
    }

    .brand-text small {
      font-size: 6px !important;
    }
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
  
  .user-info {
    flex: 1;
  }
  
  .user-name {
    font-weight: 600;
    color: var(--text-primary);
    font-size: 0.8rem;
  }
  
  .user-role {
    font-size: 0.7rem;
    color: var(--text-secondary);
  }

  /* Body styles */
  body {
    font-family: 'Inter', sans-serif;
    background-color: #f4f6f9;
    color: #2d3748;
    line-height: 1.4;
    font-size: 14px;
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

  .panel-body {
    padding: 15px;
    background-color: #f6f5f5;
  }

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

  .form-group {
    margin-bottom: 10px;
  }

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

  .form-group-inline .form-control {
    width: auto;
    min-width: 60px;
  }

  .form-group-inline select.form-control {
    min-width: 100px;
  }

  .form-group input[type="checkbox"] {
    transform: scale(1.2);
    margin-right: 8px;
  }

  .btn {
    border-radius: 5px;
    padding: 8px 15px;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.2s ease;
  }

  .btn-primary {
    background-color: #2b6cb0;
    border-color: #2b6cb0;
  }

  .btn-primary:hover {
    background-color: #2c5282;
    border-color: #2c5282;
  }

  .btn-secondary {
    background-color: #718096;
    border-color: #718096;
  }

  .btn-secondary:hover {
    background-color: #5a667a;
    border-color: #5a667a;
  }

  .btn-danger {
    background-color: #e53e3e;
    border-color: #e53e3e;
  }

  .btn-danger:hover {
    background-color: #c53030;
    border-color: #c53030;
  }

  .btn-info {
    background-color: #38b2ac;
    border-color: #38b2ac;
  }

  .btn-info:hover {
    background-color: #319795;
    border-color: #319795;
  }

  .table-responsive {
    border-radius: 6px;
    overflow: hidden;
  }

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

  .table-striped tbody tr:nth-of-type(odd) {
    background-color: #f7fafc;
  }

  .table-striped tbody tr:hover {
    background-color: #edf2f7;
  }

  .student-image {
    max-width: 60px;
    max-height: 60px;
    border-radius: 4px;
    object-fit: cover;
  }

  #old_dues_amount_container {
    margin-top: 10px;
  }

  .two-column-form .row > div {
    margin-bottom: 10px;
  }

  @media (max-width: 768px) {
    .two-column-form .row > div {
      margin-bottom: 10px;
    }

    .form-group-inline {
      flex-direction: column;
      align-items: flex-start;
    }

    .form-group-inline .form-control {
      width: 100%;
    }
  }
  
  body {
    background-color: #f4f6f9 !important;
  }
</style>

<!-- Top Quick Links Bar -->
<div class="top-quick-bar" style="background-color: #3b3a85 !important;">
  <div class="quick-links-horizontal">
    <a href="student_registration.php" class="quick-link-horizontal-btn">
      New Registration
    </a>
    <a href="student_list.php" class="quick-link-horizontal-btn">
      Student List
    </a>
    <a href="create_dmcs.php" class="quick-link-horizontal-btn">
      Create DMC
    </a>
    <a href="dmc_list.php" class="quick-link-horizontal-btn">
      DMC List
    </a>
    <a href="attendance_class.php" class="quick-link-horizontal-btn">
      Attendance
    </a>
    <a href="users_attendance.php" class="quick-link-horizontal-btn">
      Teacher Attendance
    </a>
    <a href="fee_card_create_monthly.php" class="quick-link-horizontal-btn">
      Fee Cards
    </a>
    <a href="student_feecards.php" class="quick-link-horizontal-btn">
      Print Fee Card
    </a>
    <a href="arrange_exam.php" class="quick-link-horizontal-btn">
      Arrange Exam
    </a>
    <a href="expense.php" class="quick-link-horizontal-btn">
      Expense
    </a>
  </div>
</div>

<!-- Main Navbar -->
<nav class="navbar navbar-expand-lg navbar-light sticky-top">
  <div class="container-fluid">
    <div class="navbar-main-content">
      <a class="navbar-brand" href="index.php">
        <img src="logo2.png" alt="Logo">
        <div class="brand-text"><span style="font-size:20px;color: #16253e !important;">DAR-E-ARQAM <small>SCHOOL AND COLLEGE</small></span> </div>
      </a>

      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar" aria-controls="mainNavbar" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>

      <div class="collapse navbar-collapse" id="mainNavbar">
        <ul class="navbar-nav me-auto mb-2 mb-lg-0">
          <?php foreach ($role_modules as $module_id => $module): ?>
            <?php
            // Skip if this is a child module (handled under parent)
            if (isset($module_parents[$module_id])) continue;
            
            // Determine if this is a single link or dropdown
            $is_dropdown = !empty($module['children']);
            
            // Safely get the title with a fallback
            $module_title = isset($module['title']) ? $module['title'] : '';
            $module_url = isset($module['url']) ? $module['url'] : '#';
            ?>
            <li class="nav-item <?php echo $is_dropdown ? 'dropdown' : ''; ?>">
              <?php if (!$is_dropdown && !empty($module_title) && !empty($module_url) && $module_url != '#'): ?>
                <!-- Single link -->
                <a class="nav-link <?php echo $module_url === 'index.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($module_url); ?>">
                  <?php echo htmlspecialchars($module_title); ?>
                </a>
              <?php elseif ($is_dropdown && !empty($module_title)): ?>
                <!-- Dropdown -->
                <a class="nav-link dropdown-toggle" href="#" id="<?php echo htmlspecialchars(strtolower(str_replace(' ', '', $module_title))); ?>Dropdown" role="button" data-bs-toggle="dropdown">
                  <?php echo htmlspecialchars($module_title); ?>
                </a>
                <ul class="dropdown-menu" aria-labelledby="<?php echo htmlspecialchars(strtolower(str_replace(' ', '', $module_title))); ?>Dropdown">
                  <?php foreach ($module['children'] as $child): ?>
                    <li>
                      <a class="dropdown-item" href="<?php echo isset($child['url']) ? htmlspecialchars($child['url']) : '#'; ?>">
                        <?php echo isset($child['title']) ? htmlspecialchars($child['title']) : 'Untitled'; ?>
                      </a>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>

        <ul class="navbar-nav ms-auto">
          <li class="nav-item">
            <a class="nav-link btn btn-logout rounded-5" href="logout.php">
              <i class="fa-solid fa-right-from-bracket me-2"></i>
              Logout
            </a>
          </li>
        </ul>
      </div>
    </div>
  </div>
</nav>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>