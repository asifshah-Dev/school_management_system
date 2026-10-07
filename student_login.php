<?php
// student_login.php - Corrected for Student Login
ob_start();
session_start();
require_once('conn_inc.php');

// If student already logged in, redirect to index
if (isset($_SESSION['user_id']) && $_SESSION['role_id'] == 4) {
    header("Location: student_dashboard.php");
    exit();
}

// Process login form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']); // student name
    $password = trim($_POST['password']); // mobile number

    if (empty($username) || empty($password)) {
        $_SESSION['error'] = "Please enter both username and password";
        header("Location: student_login.php");
        exit();
    }

    // 🔹 Find student by name
    $sql = "SELECT id, name, mobile 
            FROM student_registration 
            WHERE name = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();

        // 🔹 Password = mobile number
        if ($row['mobile'] === $password) {
            // ✅ Set session
            $_SESSION['student_user_id']   = $row['id'];
            $_SESSION['username']  = $row['name'];
            $_SESSION['role_id']   = 4; // fixed role_id for student
            $_SESSION['canary']    = time();

            session_regenerate_id(true);
            ob_end_clean();
         header("Location: student_dashboard.php");

            exit();
        } else {
            $_SESSION['error'] = "❌ Incorrect password.";
            ob_end_clean();
            header("Location: student_login.php");
            exit();
        }
    } else {
        $_SESSION['error'] = "❌ Student not found.";
        ob_end_clean();
        header("Location: student_login.php");
        exit();
    }
}

ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Login - Madrassa Al-Farooqia</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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
            --gradient-start: #0a3d62;
            --gradient-end: #3e92cc;
            --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, var(--gradient-start) 0%, var(--gradient-end) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Poppins', sans-serif;
            padding: 20px;
            overflow-x: hidden;
        }
        
        .login-container {
            max-width: 450px;
            width: 100%;
            background: #ffffff;
            border-radius: 20px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
            animation: fadeInUp 0.8s ease-out;
            position: relative;
        }
        
        @keyframes fadeInUp {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .login-header {
            background: linear-gradient(135deg, var(--gradient-start) 0%, var(--gradient-end) 100%);
            padding: 30px 20px;
            text-align: center;
            color: white;
            position: relative;
        }
        
        .login-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1440 320'%3E%3Cpath fill='%23ffffff' fill-opacity='0.1' d='M0,96L48,112C96,128,192,160,288,186.7C384,213,480,235,576,213.3C672,192,768,128,864,128C960,128,1056,192,1152,213.3C1248,235,1344,213,1392,202.7L1440,192L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z'%3E%3C/path%3E%3C/svg%3E");
            background-size: cover;
            opacity: 0.1;
        }
        
        .logo-container {
            position: relative;
            z-index: 1;
        }
        
        .logo {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            margin-bottom: 15px;
        }
        
        .madrassa-name {
            font-family: 'Noto Nastaliq Urdu', serif;
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.3rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }
        
        .madrassa-address {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-bottom: 0;
        }
        
        .login-body {
            padding: 30px;
        }
        
        .welcome-text {
            text-align: center;
            margin-bottom: 25px;
        }
        
        .welcome-text h3 {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 5px;
        }
        
        .welcome-text p {
            color: var(--secondary);
            font-size: 0.95rem;
        }
        
        .error-alert {
            background-color: #ffebee;
            color: #c62828;
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            border-left: 4px solid #f44336;
            display: flex;
            align-items: center;
            animation: shake 0.5s ease-in-out;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        
        .error-alert i {
            margin-right: 10px;
            font-size: 1.1rem;
        }
        
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }
        
        .form-label {
            font-weight: 500;
            margin-bottom: 8px;
            color: var(--dark);
            display: block;
        }
        
        .input-group {
            position: relative;
        }
        
        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary);
            z-index: 2;
        }
        
        .form-control {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 12px 15px 12px 45px;
            font-size: 0.95rem;
            transition: all 0.3s;
            height: 48px;
        }
        
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15);
        }
        
        .btn-login {
            background: linear-gradient(to right, var(--gradient-start), var(--gradient-end));
            border: none;
            border-radius: 10px;
            padding: 12px;
            font-size: 1rem;
            font-weight: 600;
            color: white;
            width: 100%;
            transition: all 0.3s;
            margin-top: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .login-footer {
            text-align: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            color: var(--secondary);
            font-size: 0.85rem;
        }
        .rounded-logo {
   
    width: 90px;         /* control size */
    height: 120px;        /* keep same width/height for perfect circle */
    object-fit: cover;  
    float: left;  /* crop nicely if not square */
}

        
        .support-text {
            margin-top: 15px;
            font-weight: 500;
        }
        
        /* Responsive adjustments */
        @media (max-width: 576px) {
            .login-container {
                border-radius: 15px;
            }
            
            .login-header {
                padding: 25px 15px;
            }
            
            .madrassa-name {
                font-size: 1.5rem;
            }
            
            .login-body {
                padding: 25px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo-container">
                
              <img src="logo2.png" alt="DAR-E-ARQAM SCHOOL AND COLLEGE Logo" class="rounded-logo">

<br>
                <h3 class="school-name">DAR-E-ARQAM SCHOOL AND COLLEGE</h3>
                <p class="madrassa-address">Matta Swat</p>
            </div>
        </div>
        
        <div class="login-body">
            <div class="welcome-text">
                <h3>Student Portal</h3>
                <p>Sign in </p>
            </div>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="error-alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($_SESSION['error']); ?></span>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <form method="POST" action="student_login.php">
                <div class="form-group">
                    <label class="form-label">Student Name</label>
                    <div class="input-group">
                        <i class="fas fa-user input-icon"></i>
                        <input type="text" name="username" class="form-control" placeholder="Enter your name" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Mobile Number</label>
                    <div class="input-group">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" name="password" class="form-control" placeholder="Enter your mobile number" required>
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt me-2"></i>Login to Dashboard
                </button>
            </form>

            <div class="login-footer">
                <div>DAR-E-ARQAM Student Portal</div>
                <div class="support-text">Powered by Softrayz IT Solutions | 03452134977</div>
            </div>
        </div>
    </div>

    <script>
        // Add animation to form elements
        document.addEventListener('DOMContentLoaded', function() {
            const formGroups = document.querySelectorAll('.form-group');
            formGroups.forEach((group, index) => {
                group.style.opacity = '0';
                group.style.transform = 'translateY(10px)';
                group.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                
                setTimeout(() => {
                    group.style.opacity = '1';
                    group.style.transform = 'translateY(0)';
                }, 100 + (index * 100));
            });
        });
    </script>
</body>
</html>