<?php
session_start();
$conn = new mysqli("localhost", "root", "", "volcon");
if ($conn->connect_error) { 
    die("Connection failed: " . $conn->connect_error); 
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $conn->real_escape_string($_POST['email']);
    $password = $_POST['password'];

    // Query the users table (consolidated for all roles)
    $result = $conn->query("SELECT * FROM users WHERE email='$email'");
    
    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        
        // Verify password
        if (password_verify($password, $user['password'])) {
            
            // Check if email is verified
            if (!$user['email_verified']) {
                $error = "Please verify your email address before logging in.";
            } 
            // Check account status
            else if ($user['status'] == 'suspended') {
                $error = "Your account has been suspended. Please contact support at support@volunteerconnect.org.";
            }
            else if ($user['status'] == 'pending') {
                $error = "Your account is pending verification. Please wait for approval or contact support at support@volunteerconnect.org.";
            }
            else if ($user['status'] == 'verified') {
                // Set session variables based on role
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['status'] = $user['status'];
                
                // Redirect based on role
                switch($user['role']) {
                    case 'admin':
                        // Check if admin exists in admins table
                        $admin_check = $conn->query("SELECT * FROM admins WHERE adm_id = " . $user['user_id']);
                        if ($admin_check->num_rows == 1) {
                            $_SESSION['admin_id'] = $user['user_id'];
                            header("Location: dashboard_admin.php");
                            exit();
                        } else {
                            $error = "Admin account not properly configured.";
                        }
                        break;
                        
                    case 'org':
                        $_SESSION['organization_id'] = $user['user_id'];
                        header("Location: dashboard_organization.php");
                        exit();
                        break;
                        
                    case 'vol':
                        $_SESSION['volunteer_id'] = $user['user_id'];
                        header("Location: dashboard_volunteer.php");
                        exit();
                        break;
                        
                    default:
                        $error = "Invalid user role.";
                        break;
                }
            } else {
                $error = "Account not in valid state.";
            }
        } else {
            $error = "Incorrect password.";
        }
    } else {
        $error = "Email not found.";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Login</title>
    <link rel="icon" type="image/png" href="/volcon/assets/res/logo/favicon.png">
    <link rel="shortcut icon" href="/volcon/assets/res/logo/favicon.ico">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 450px;
            text-align: center;
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .logo-img {
            width: 120px;
            height: auto;
            margin-bottom: 20px;
        }
        
        .logo {
            color: #333;
            font-size: 28px;
            margin-bottom: 10px;
            font-weight: 700;
        }
        
        h2 {
            color: #555;
            margin-bottom: 30px;
            font-weight: 500;
        }
        
        .error-message {
            background: #fee;
            color: #c33;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #c33;
            text-align: left;
            font-size: 14px;
        }
        
        .success-message {
            background: #efe;
            color: #2a7;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #2a7;
            text-align: left;
            font-size: 14px;
        }
        
        form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        input[type="email"],
        input[type="password"] {
            padding: 16px;
            border: 2px solid #e1e5e9;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
            outline: none;
        }
        
        input[type="email"]:focus,
        input[type="password"]:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        button[type="submit"] {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 16px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        button[type="submit"]:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        
        button[type="submit"]:active {
            transform: translateY(0);
        }
        
        .small-text {
            color: #666;
            font-size: 14px;
            margin-top: 20px;
        }
        
        .small-text a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s ease;
        }
        
        .small-text a:hover {
            color: #764ba2;
            text-decoration: underline;
        }
        
        .links {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
        }
        
        .demo-hint {
            background: #f8f9fa;
            border: 1px dashed #dee2e6;
            border-radius: 8px;
            padding: 12px;
            margin-top: 25px;
            font-size: 13px;
            color: #6c757d;
            text-align: left;
        }
        
        .demo-hint strong {
            color: #495057;
        }
        
        .role-info {
            font-size: 13px;
            color: #6c757d;
            margin-top: 15px;
            text-align: left;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
        }
    </style>
</head>
<body>
<div class="container">
    <img src="assets/volcon-logo.png" alt="Volunteer Connect Logo" class="logo-img">
    <h1 class="logo">Administrator</h1>
    <h2>Sign In to Access</h2>
    
    <?php if (isset($_GET['msg']) && $_GET['msg'] == 'reset_success'): ?>
        <div class="success-message">
            ✓ Password reset successfully. Please sign in with your new password.
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['msg']) && $_GET['msg'] == 'verify_success'): ?>
        <div class="success-message">
            ✓ Email verified successfully! You can now sign in.
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['msg']) && $_GET['msg'] == 'registered'): ?>
        <div class="success-message">
            ✓ Registration successful! Please check your email to verify your account.
        </div>
    <?php endif; ?>
    
    <?php if (!empty($error)): ?>
        <div class="error-message">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="email" name="email" placeholder="Email Address" required autocomplete="email">
        <input type="password" name="password" placeholder="Password" required autocomplete="current-password">
        <button type="submit">Sign In</button>
    </form>
    
    <div class="links">
        <p class="small-text"><a href="reset_password.php">Forgot Password?</a></p>
        <p class="small-text"><a href="register.php">Create Account</a></p>
    </div>
    
    <div class="demo-hint">
        <strong>Demo Accounts (if pre-populated):</strong><br>
        • Admin: admin@volunteerconnect.org<br>
        • Organization: org@example.com<br>
        • Volunteer: volunteer@example.com
    </div>
    
    <div class="role-info">
        <strong>Note:</strong> This system uses role-based access:<br>
        • <strong>Admin</strong> - Full system access<br>
        • <strong>Organization</strong> - Post/manage opportunities<br>
        • <strong>Volunteer</strong> - Browse/apply for opportunities
    </div>
</div>

<script>
    // Add focus effects for better UX
    document.addEventListener('DOMContentLoaded', function() {
        const inputs = document.querySelectorAll('input[type="email"], input[type="password"]');
        
        inputs.forEach(input => {
            // Clear error when user starts typing
            input.addEventListener('input', function() {
                const errorMsg = document.querySelector('.error-message');
                if (errorMsg) {
                    errorMsg.style.display = 'none';
                }
            });
            
            // Add floating label effect
            input.addEventListener('focus', function() {
                this.parentElement?.classList?.add('focused');
            });
            
            input.addEventListener('blur', function() {
                if (!this.value) {
                    this.parentElement?.classList?.remove('focused');
                }
            });
        });
    });
</script>
</body>
</html>