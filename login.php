<?php
// login.php - Updated Version with Mobile Responsive Design
ob_start();
session_start();
require_once('conn_inc.php');

// If user is already logged in, redirect to index
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Process login form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    // Validate inputs
    if (empty($username) || empty($password)) {
        $_SESSION['error'] = "Please enter both username and password";
        header("Location: login.php");
        exit();
    }

    // Query for active users (status = 1)
    $sql = "SELECT id, role_id, username, password FROM users WHERE username = ? AND status = 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();

        // Verify password (in production, use password_verify() with hashed passwords)
        if ($row['password'] === $password) {
            // Set session variables
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['role_id'] = $row['role_id'];
            $_SESSION['canary'] = time(); // Security timestamp
            
            // Regenerate session ID for security
            session_regenerate_id(true);
            
            // Clear buffer and redirect
            ob_end_clean();
            header("Location: index.php");
            exit();
        } else {
            $_SESSION['error'] = "❌ Incorrect password.";
            ob_end_clean();
            header("Location: login.php");
            exit();
        }
    } else {
        $_SESSION['error'] = "❌ User not found or inactive.";
        ob_end_clean();
        header("Location: login.php");
        exit();
    }
}

// If not POST request, show login form
ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>Login - Dar-e-Arqam School System</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Noto+Nastaliq+Urdu&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(145deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            margin: 0;
            padding: 16px;
            position: relative;
        }

        /* Mobile app container */
        .app-container {
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
            position: relative;
        }

        /* Main card - like mobile app screen */
        .login-card {
            background: white;
            border-radius: 32px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            padding: 32px 24px;
            transition: transform 0.3s ease;
            animation: slideUp 0.5s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Logo section - app style */
        .logo-section {
            text-align: center;
            margin-bottom: 32px;
        }

        .logo-wrapper {
           
            width: 90px;
            height: 90px;
          
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .logo-wrapper img {
            width: 65px;
            height: 65px;
            border-radius: 18px;
            object-fit: cover;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .app-name {
            font-size: 26px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 4px;
            letter-spacing: -0.5px;
        }

        .app-subtitle {
            font-family: 'Noto Nastaliq Urdu', serif;
            font-size: 18px;
            color: #4a5568;
            margin-bottom: 8px;
        }

        .app-address {
            font-size: 14px;
            color: #718096;
            background: #f7fafc;
            padding: 6px 16px;
            border-radius: 30px;
            display: inline-block;
            font-weight: 500;
        }

        .app-address i {
            margin-right: 6px;
            color: #667eea;
        }

        /* Error message - mobile style */
        .error-message {
            background: #fff5f5;
            border: 1px solid #fed7d7;
            border-radius: 16px;
            padding: 14px 16px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: shake 0.4s ease-in-out;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-4px); }
            75% { transform: translateX(4px); }
        }

        .error-message i {
            color: #e53e3e;
            font-size: 18px;
        }

        .error-message span {
            color: #c53030;
            font-size: 14px;
            font-weight: 500;
            flex: 1;
        }

        /* Form fields - mobile native style */
        .form-group {
            margin-bottom: 20px;
        }

        .input-label {
            font-size: 13px;
            font-weight: 600;
            color: #4a5568;
            margin-bottom: 6px;
            margin-left: 16px;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            color: #a0aec0;
            font-size: 16px;
            transition: color 0.2s ease;
            pointer-events: none;
            z-index: 1;
        }

        .form-control {
            width: 100%;
            padding: 16px 16px 16px 48px;
            font-size: 15px;
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 30px;
            transition: all 0.2s ease;
            color: #1a202c;
            font-weight: 500;
            -webkit-appearance: none;
            appearance: none;
        }

        .form-control:focus {
            outline: none;
            border-color: #667eea;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-control:focus + .input-icon {
            color: #667eea;
        }

        .form-control::placeholder {
            color: #a0aec0;
            font-weight: 400;
            font-size: 14px;
        }

        /* Password visibility toggle */
        .password-toggle {
            position: absolute;
            right: 16px;
            color: #a0aec0;
            font-size: 16px;
            cursor: pointer;
            transition: color 0.2s ease;
            z-index: 1;
            padding: 8px;
        }

        .password-toggle:hover {
            color: #667eea;
        }

        .password-toggle:active {
            opacity: 0.7;
        }

        /* Login button - mobile style */
        .login-btn {
            background: linear-gradient(145deg, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 30px;
            padding: 16px;
            font-size: 16px;
            font-weight: 600;
            width: 100%;
            margin-top: 24px;
            margin-bottom: 24px;
            transition: all 0.2s ease;
            box-shadow: 0 8px 16px rgba(102, 126, 234, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            -webkit-appearance: none;
            appearance: none;
            cursor: pointer;
        }

        .login-btn:active {
            transform: scale(0.98);
            box-shadow: 0 4px 8px rgba(102, 126, 234, 0.3);
        }

        .login-btn i {
            font-size: 18px;
        }

        /* Footer - app style */
        .app-footer {
            margin-top: 16px;
            text-align: center;
        }

        .powered-by {
            font-size: 12px;
            color: #718096;
            line-height: 1.6;
        }

        .powered-by strong {
            color: #4a5568;
            font-weight: 600;
        }

        .phone-number {
            color: #667eea;
            font-weight: 600;
            display: inline-block;
            margin-left: 4px;
            padding: 2px 8px;
            background: #f0f4ff;
            border-radius: 20px;
        }

        /* Responsive adjustments for smaller phones */
        @media (max-width: 360px) {
            .login-card {
                padding: 24px 20px;
            }
            
            .logo-wrapper {
                width: 80px;
                height: 80px;
            }
            
            .logo-wrapper img {
                width: 58px;
                height: 58px;
            }
            
            .app-name {
                font-size: 24px;
            }
            
            .app-subtitle {
                font-size: 16px;
            }
            
            .form-control {
                padding: 14px 14px 14px 46px;
                font-size: 14px;
            }
            
            .login-btn {
                padding: 14px;
                font-size: 15px;
            }
        }

        /* Prevent zoom on input focus for iOS */
        @supports (-webkit-touch-callout: none) {
            .form-control {
                font-size: 16px;
            }
        }

        /* Touch-friendly hover states */
        @media (hover: hover) {
            .login-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 12px 20px rgba(102, 126, 234, 0.4);
            }
            
            .password-toggle:hover {
                color: #667eea;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <div class="login-card">
            <!-- Logo Section -->
            <div class="logo-section">
                <div class="logo-wrapper">
                    <img src="logo2.png" alt="Dar-e-Arqam Logo">
                </div>
                <h1 class="app-name">Dar-e-Arqam</h1>
                <div class="app-subtitle"></div>
                <div class="app-address">
                    <i class="fas fa-map-marker-alt"></i>Matta Swat
                </div>
            </div>

            <!-- Error Message -->
            <?php if (isset($_SESSION['error'])): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($_SESSION['error']); ?></span>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="POST" action="login.php">
                <div class="form-group">
                    <div class="input-label">
                        <i class="far fa-user-circle"></i> Username
                    </div>
                    <div class="input-wrapper">
                        <i class="fas fa-user input-icon"></i>
                        <input type="text" name="username" id="username" class="form-control" placeholder="Enter username" required autocomplete="off">
                    </div>
                </div>

                <div class="form-group">
                    <div class="input-label">
                        <i class="fas fa-lock"></i> Password
                    </div>
                    <div class="input-wrapper">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" name="password" id="password" class="form-control" placeholder="Enter password" required>
                        <i class="fas fa-eye password-toggle" id="togglePassword" onclick="togglePasswordVisibility()"></i>
                    </div>
                </div>

                <button type="submit" class="login-btn">
                    <i class="fas fa-sign-in-alt"></i>
                    Sign In
                </button>
            </form>

            <!-- Footer -->
            <div class="app-footer">
                <div class="powered-by">
                    Powered by <strong>Softrayz IT Solutions</strong> 
                    <span class="phone-number">0345-2134977</span>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Toggle password visibility
        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('togglePassword');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

        // Add touch feedback for buttons
        const loginBtn = document.querySelector('.login-btn');
        if (loginBtn) {
            loginBtn.addEventListener('touchstart', function() {
                this.style.opacity = '0.8';
            });
            loginBtn.addEventListener('touchend', function() {
                this.style.opacity = '1';
            });
        }

        // Prevent zoom on input focus for iOS
        document.addEventListener('touchstart', function(event) {
            if (event.target.nodeName === 'INPUT') {
                document.body.style.zoom = '1';
            }
        }, { passive: true });
    </script>

    <!-- Bootstrap JS (optional) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>