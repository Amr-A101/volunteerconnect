<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Volunteer Connect Admin</title>
    <link rel="icon" type="image/png" href="/volcon/assets/res/logo/favicon.png">
    <link rel="shortcut icon" href="/volcon/assets/res/logo/favicon.ico">
    <link rel="stylesheet" href="assets/css/vc-admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="vc-admin-wrapper">
        <!-- Same sidebar structure as dashboard -->
        <aside class="vc-sidebar">
            <div class="vc-sidebar-header">
                <div class="vc-sidebar-title">
                    <img src="assets/volcon-logo.png" alt="VolCon Logo" style="width: 128px;">
                </div>
                <button class="vc-sidebar-toggle">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
            
            <nav class="vc-nav">
                <a href="dashboard_admin.php" class="vc-nav-item">
                    <div class="vc-nav-icon"><i class="fas fa-tachometer-alt"></i></div>
                    <div class="vc-nav-text">Dashboard</div>
                </a>
                <a href="dashboard_admin.php#pending" class="vc-nav-item">
                    <div class="vc-nav-icon"><i class="fas fa-clock"></i></div>
                    <div class="vc-nav-text">Pending Approval</div>
                </a>
                <a href="dashboard_admin.php#active" class="vc-nav-item">
                    <div class="vc-nav-icon"><i class="fas fa-users"></i></div>
                    <div class="vc-nav-text">Active Users</div>
                </a>
                <a href="dashboard_admin.php#suspended" class="vc-nav-item">
                    <div class="vc-nav-icon"><i class="fas fa-ban"></i></div>
                    <div class="vc-nav-text">Suspended Users</div>
                </a>
                <a href="dashboard_admin.php#skills" class="vc-nav-item">
                    <div class="vc-nav-icon"><i class="fas fa-tools"></i></div>
                    <div class="vc-nav-text">Skills</div>
                </a>
                <a href="dashboard_admin.php#interests" class="vc-nav-item">
                    <div class="vc-nav-icon"><i class="fas fa-heart"></i></div>
                    <div class="vc-nav-text">Interests</div>
                </a>
                <a href="dashboard_admin.php#opportunities" class="vc-nav-item">
                    <div class="vc-nav-icon"><i class="fas fa-tasks"></i></div>
                    <div class="vc-nav-text">Opportunities</div>
                </a>
                <a href="announcement.php" class="vc-nav-item">
                    <div class="vc-nav-icon"><i class="fas fa-bullhorn"></i></div>
                    <div class="vc-nav-text">Announcement</div>
                </a>
                <a href="reports.php" class="vc-nav-item active">
                    <div class="vc-nav-icon"><i class="fas fa-chart-bar"></i></div>
                    <div class="vc-nav-text">Reports</div>
                </a>
                <a href="settings.php" class="vc-nav-item">
                    <div class="vc-nav-icon"><i class="fas fa-cog"></i></div>
                    <div class="vc-nav-text">Settings</div>
                </a>
            </nav>
            
            <div class="vc-user-info">
                <div class="vc-user-avatar">
                    <?php 
                    if (isset($_SESSION['username'])) {
                        echo strtoupper(substr($_SESSION['username'], 0, 1)); 
                    } else {
                        echo 'A';
                    }
                    ?>
                </div>
                <div class="vc-user-details">
                    <div class="vc-user-name">
                        <?php 
                        if (isset($_SESSION['username'])) {
                            echo htmlspecialchars($_SESSION['username']); 
                        } else {
                            echo 'Admin';
                        }
                        ?>
                    </div>
                    <div class="vc-user-role">Administrator</div>
                </div>
            </div>
        </aside>
        
        <main class="vc-main-content">
            <header class="vc-header">
                <div>
                    <h1 class="vc-header-title">Settings</h1>
                    <p class="vc-header-subtitle">Configure system settings and preferences</p>
                </div>
                <a href="dashboard_admin.php" class="vc-btn">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </header>
            
            <!-- Settings content here -->
            <div class="vc-empty-state">
                <div class="vc-empty-icon"><i class="fas fa-cog"></i></div>
                <h3>Settings Coming Soon</h3>
                <p>System configuration features will be available soon</p>
                <a href="dashboard_admin.php" class="vc-btn vc-btn-approve" style="margin-top: 20px;">
                    <i class="fas fa-tachometer-alt"></i> Return to Dashboard
                </a>
            </div>
        </main>
    </div>
</body>
</html>