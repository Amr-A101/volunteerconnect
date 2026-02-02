<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

function esc($v) { return htmlspecialchars($v, ENT_QUOTES); }

$dbc = new mysqli("localhost", "root", "", "volcon");
if ($dbc->connect_error) {
    die("Connection failed: " . $dbc->connect_error);
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// Fetch previous announcements
$stmt = $dbc->prepare("
    SELECT
        MIN(notification_id) AS announcement_id,
        title,
        message,
        type,
        role_target,
        action_url,
        created_at,
        COUNT(*) AS recipients
    FROM notifications
    WHERE created_by = 'admin'
      AND is_deleted = 0
    GROUP BY
        title,
        message,
        type,
        role_target,
        action_url,
        created_at
    ORDER BY created_at DESC
");
$stmt->execute();
$announcements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - Volunteer Connect Admin</title>
    <link rel="icon" type="image/png" href="/volcon/assets/res/logo/favicon.png">
    <link rel="shortcut icon" href="/volcon/assets/res/logo/favicon.ico">
    <link rel="stylesheet" href="assets/css/vc-admin.css">
    <link rel="stylesheet" href="assets/css/alerts.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Load Chart.js from CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="vc-admin-wrapper">
        <!-- Sidebar (same as dashboard) -->
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

        <!-- MAIN CONTENT -->
        <main class="vc-main-content">

            <!-- HEADER -->
            <header class="vc-header">
                <div>
                    <h1 class="vc-header-title">Announcements</h1>
                    <p class="vc-header-subtitle">
                        Broadcast system-wide messages to users
                    </p>
                </div>
                <a href="dashboard_admin.php" class="vc-btn">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </header>

            <!-- CREATE ANNOUNCEMENT -->
            <div class="vc-report-card vc-full-width">
                <div class="vc-report-card-header">
                    <h3 class="vc-report-title">Create Announcement</h3>
                    <div class="vc-report-icon">
                        <i class="fas fa-bullhorn"></i>
                    </div>
                </div>

                <form method="POST" action="api/create_announcement.php" class="vc-form">

                    <div class="vc-form-group">
                        <label>Target Audience</label>
                        <select name="role_target" class="vc-filter-select" required>
                            <option value="all">All Users</option>
                            <option value="volunteer">Volunteers</option>
                            <option value="organization">Organizations</option>
                        </select>
                    </div>

                    <div class="vc-form-group">
                        <label>Title</label>
                        <input type="text"
                            name="title"
                            maxlength="150"
                            required
                            class="vc-input">
                    </div>

                    <div class="vc-form-group">
                        <label>Message</label>
                        <textarea name="message"
                                rows="4"
                                required
                                class="vc-input"></textarea>
                    </div>

                    <div class="vc-form-group">
                        <label>Type</label>
                        <select name="type" class="vc-filter-select">
                            <option value="info">Info</option>
                            <option value="success">Success</option>
                            <option value="warning">Warning</option>
                            <option value="error">Error</option>
                            <option value="system">System</option>
                        </select>
                    </div>

                    <div class="vc-form-group">
                        <label>Action URL (optional)</label>
                        <input type="url"
                            name="action_url"
                            class="vc-input"
                            placeholder="https://...">
                    </div>

                    <button class="vc-btn vc-btn-success">
                        <i class="fas fa-paper-plane"></i> Publish Announcement
                    </button>
                </form>
            </div>

            <!-- PAST ANNOUNCEMENTS -->
            <div class="vc-report-card vc-full-width" style="margin-top: 32px;">
                <div class="vc-report-card-header">
                    <h3 class="vc-report-title">Past Announcements</h3>
                    <div class="vc-report-icon">
                        <i class="fas fa-history"></i>
                    </div>
                </div>

                <?php if (!empty($announcements)): ?>
                    <table class="vc-data-table">
                        <thead>
                        <tr>
                            <th>Audience</th>
                            <th>Title</th>
                            <th>Type</th>
                            <th>Recipients</th>
                            <th>Created</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($announcements as $a): ?>
                            <tr>
                                <td><?= ucfirst($a['role_target'] ?? 'all') ?></td>
                                <td><?= esc($a['title']) ?></td>
                                <td>
                                    <span class="vc-status-badge vc-status-<?= esc($a['type']) ?>">
                                        <?= ucfirst($a['type']) ?>
                                    </span>
                                </td>
                                <td><?= (int)$a['recipients'] ?></td>
                                <td><?= date('M d, Y g:i A', strtotime($a['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="vc-empty-state">
                        <i class="fas fa-bell-slash"></i>
                        <h3>No announcements yet</h3>
                        <p>Create your first system announcement above.</p>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>
<script src="assets/js/vc-admin.js"></script>
</body>
</html>

