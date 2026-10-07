<?php
session_start();
if (!isset($_SESSION['student_user_id']) || $_SESSION['role_id'] != 4) {
    header("Location: student_login.php");
    exit();
}
require_once('conn_inc.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary: #4361ee;
      --secondary: #6c757d;
      --success: #198754;
      --info: #0dcaf0;
      --warning: #ffc107;
      --danger: #dc3545;
      --light: #f8f9fa;
      --dark: #212529;
      --sidebar-bg: #0a3d62;
      --sidebar-hover: #3e92cc;
      --card-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
    }
    
    body {
      background-color: #f5f7fb;
      font-family: 'Poppins', sans-serif;
      color: #495057;
    }
    
    /* Sidebar Styles */
    .sidebar {
      background: linear-gradient(180deg, var(--sidebar-bg) 0%, #1a5d9c 100%);
      color: white;
      height: 100vh;
      position: fixed;
      top: 0;
      left: 0;
      width: 250px;
      padding-top: 20px;
      box-shadow: 3px 0 10px rgba(0, 0, 0, 0.1);
      z-index: 1000;
      transition: all 0.3s ease;
    }
    
    .sidebar-brand {
      padding: 0 20px 20px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      margin-bottom: 20px;
    }
    
    .sidebar-brand h4 {
      font-weight: 600;
      margin-bottom: 0;
    }
    
    .sidebar-nav {
      list-style: none;
      padding: 0;
      margin: 0;
    }
    
    .sidebar-nav li {
      margin-bottom: 5px;
    }
    
    .sidebar-nav a {
      color: rgba(255, 255, 255, 0.8);
      text-decoration: none;
      display: flex;
      align-items: center;
      padding: 12px 20px;
      transition: all 0.3s;
      border-left: 3px solid transparent;
    }
    
    .sidebar-nav a:hover, .sidebar-nav a.active {
      background: rgba(255, 255, 255, 0.1);
      color: white;
      border-left: 3px solid white;
    }
    
    .sidebar-nav i {
      margin-right: 10px;
      width: 20px;
      text-align: center;
    }
    
    .logout-btn {
      margin-top: 20px;
      padding: 0 20px;
    }
    
    /* Main Content */
    .main-content {
      margin-left: 250px;
      padding: 20px;
    }
    
    /* Header */
    .header {
      background: white;
      border-radius: 12px;
      padding: 15px 25px;
      box-shadow: var(--card-shadow);
      margin-bottom: 25px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    
    .welcome-text h3 {
      font-weight: 600;
      margin-bottom: 5px;
      color: var(--dark);
    }
    
    .welcome-text p {
      color: var(--secondary);
      margin-bottom: 0;
    }
    
    .date-display {
      background: var(--light);
      padding: 8px 15px;
      border-radius: 20px;
      font-size: 14px;
      color: var(--secondary);
    }
    
    /* Dashboard Cards */
    .dashboard-card {
      background: white;
      border-radius: 12px;
      padding: 25px;
      box-shadow: var(--card-shadow);
      transition: all 0.3s ease;
      height: 100%;
      border: none;
      position: relative;
      overflow: hidden;
    }
    
    .dashboard-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
    }
    
    .dashboard-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 5px;
      height: 100%;
    }
    
    .card-profile::before { background: var(--primary); }
    .card-fee::before { background: var(--success); }
    .card-results::before { background: var(--warning); }
    .card-attendance::before { background: var(--info); }
    
    .card-icon {
      font-size: 2.5rem;
      margin-bottom: 15px;
      display: inline-block;
      padding: 15px;
      border-radius: 12px;
    }
    
    .card-profile .card-icon { background: rgba(67, 97, 238, 0.1); color: var(--primary); }
    .card-fee .card-icon { background: rgba(25, 135, 84, 0.1); color: var(--success); }
    .card-results .card-icon { background: rgba(255, 193, 7, 0.1); color: var(--warning); }
    .card-attendance .card-icon { background: rgba(13, 202, 240, 0.1); color: var(--info); }
    
    .dashboard-card h5 {
      font-weight: 600;
      margin-bottom: 10px;
      color: var(--dark);
    }
    
    .dashboard-card p {
      color: var(--secondary);
      margin-bottom: 20px;
    }
    
    .card-btn {
      padding: 8px 20px;
      border-radius: 6px;
      font-weight: 500;
      text-decoration: none;
      display: inline-block;
      transition: all 0.3s;
    }
    
    .card-profile .card-btn { background: var(--primary); color: white; }
    .card-fee .card-btn { background: var(--success); color: white; }
    .card-results .card-btn { background: var(--warning); color: white; }
    .card-attendance .card-btn { background: var(--info); color: white; }
    
    .card-btn:hover {
      opacity: 0.9;
      transform: translateY(-2px);
    }
    
    /* Responsive */
    @media (max-width: 992px) {
      .sidebar {
        width: 70px;
        overflow: hidden;
      }
      
      .sidebar-brand h4, .sidebar-nav span {
        display: none;
      }
      
      .sidebar-nav i {
        margin-right: 0;
        font-size: 1.2rem;
      }
      
      .main-content {
        margin-left: 70px;
      }
    }
    
    @media (max-width: 768px) {
      .sidebar {
        width: 0;
        padding-top: 60px;
      }
      
      .main-content {
        margin-left: 0;
      }
      
      .menu-toggle {
        display: block;
        position: fixed;
        top: 15px;
        left: 15px;
        z-index: 1100;
        background: var(--primary);
        color: white;
        border: none;
        border-radius: 50%;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
      }
    }
  </style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
  <div class="sidebar-brand">
    <h5><i class="fas fa-graduation-cap me-2"></i><span>Student Portal <br><span style="font-size:15px;" class=""></span></span> <br><span style="font-size:10px; "></span></h5>
  </div>
  
  <ul class="sidebar-nav">
    <li><a href="#" class="active"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
    <li><a href="#"><i class="fas fa-user"></i> <span>Profile</span></a></li>
    <li><a href="student_fee-details.php"><i class="fas fa-money-bill"></i> <span>Fee</span></a></li>
    <li><a href="student_view_result.php"><i class="fas fa-graduation-cap"></i> <span>Results</span></a></li>
    <li><a href="student_attendance_details.php"><i class="fas fa-calendar-check"></i> <span>Attendance</span></a></li>
  </ul>
  
  <div class="logout-btn">
    <a href="logout.php" class="card-btn" style="display: block; text-align: center;">
      <i class="fas fa-sign-out-alt me-2"></i> <span>Logout</span>
    </a>
  </div>
</div>

<!-- Main Content -->
<div class="main-content">
  <!-- Header -->
  <div class="header">
    <div class="welcome-text">
      <h3>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?> 👋</h3>
      <p>Here's what's happening with your account today</p>
    </div>
    <div class="date-display">
      <i class="fas fa-calendar-day me-2"></i> <?php echo date('F j, Y'); ?>
    </div>
  </div>
  
  <!-- Dashboard Cards -->
  <div class="row g-4">
    <!-- Profile -->
    <div class="col-xl-3 col-lg-6 col-md-6">
      <div class="dashboard-card card-profile">
        <i class="fas fa-user card-icon"></i>
        <h5>Profile</h5>
        <p>View and update your personal information and settings.</p>
        <a href="#" class="card-btn">View Profile</a>
      </div>
    </div>
    
    <!-- Fee -->
    <div class="col-xl-3 col-lg-6 col-md-6">
      <div class="dashboard-card card-fee">
        <i class="fas fa-money-bill card-icon"></i>
        <h5>Fee</h5>
        <p>Check your fee details, payment history, and due amounts.</p>
        <a href="student_fee-details.php" class="card-btn">View Details</a>
      </div>
    </div>
    
    <!-- Results -->
    <div class="col-xl-3 col-lg-6 col-md-6">
      <div class="dashboard-card card-results">
        <i class="fas fa-graduation-cap card-icon"></i>
        <h5>Results</h5>
        <p>View your exam results, grades, and academic performance.</p>
        <a href="student_view_result.php" class="card-btn">Check Results</a>
      </div>
    </div>
    
    <!-- Attendance -->
    <div class="col-xl-3 col-lg-6 col-md-6">
      <div class="dashboard-card card-attendance">
        <i class="fas fa-calendar-check card-icon"></i>
        <h5>Attendance</h5>
        <p>View your attendance records and statistics.</p>
        <a href="student_attendance_details.php" class="card-btn">View Attendance</a>
      </div>
    </div>
  </div>
  
  <!-- Recent Activity Section -->
  <div class="row mt-5">
    <div class="col-12">
      <div class="dashboard-card">
        <h5 class="mb-4"><i class="fas fa-history me-2 text-primary"></i> Recent Activity</h5>
        <div class="alert alert-info">
          <i class="fas fa-info-circle me-2"></i> Your recent activities will appear here once available.
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Mobile Menu Toggle -->
<button class="menu-toggle d-lg-none">
  <i class="fas fa-bars"></i>
</button>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Mobile menu toggle
  document.querySelector('.menu-toggle').addEventListener('click', function() {
    document.querySelector('.sidebar').style.width = 
      document.querySelector('.sidebar').style.width === '250px' ? '0' : '250px';
  });
  
  // Auto-hide sidebar on mobile after selection
  if (window.innerWidth < 768) {
    document.querySelectorAll('.sidebar-nav a').forEach(item => {
      item.addEventListener('click', function() {
        document.querySelector('.sidebar').style.width = '0';
      });
    });
  }
</script>
</body>
</html>