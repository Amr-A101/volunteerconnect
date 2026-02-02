<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Database connection
$conn = new mysqli("localhost", "root", "", "volcon");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get basic statistics
$stats = [
    'total_users' => $conn->query("SELECT COUNT(*) as count FROM users WHERE status = 'verified'")->fetch_assoc()['count'],
    'total_organizations' => $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'org' AND status = 'verified'")->fetch_assoc()['count'],
    'total_volunteers' => $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'vol' AND status = 'verified'")->fetch_assoc()['count'],
    'total_opportunities' => $conn->query("SELECT COUNT(*) as count FROM opportunities WHERE status != 'deleted'")->fetch_assoc()['count'],
    'total_applications' => $conn->query("SELECT COUNT(*) as count FROM applications")->fetch_assoc()['count'],
    'pending_applications' => $conn->query("SELECT COUNT(*) as count FROM applications WHERE status = 'pending'")->fetch_assoc()['count'],
];

// Get recent activities for the table
$recentActivities = [];
$activityQuery = "
    (SELECT 'user' as type, username as title, created_at as date 
     FROM users 
     WHERE status = 'verified' 
     ORDER BY created_at DESC LIMIT 5)
    UNION
    (SELECT 'opportunity' as type, title, created_at as date 
     FROM opportunities 
     WHERE status != 'deleted' 
     ORDER BY created_at DESC LIMIT 5)
    UNION
    (SELECT 'application' as type, 
            CONCAT('Application #', application_id) as title, 
            applied_at as date 
     FROM applications 
     ORDER BY applied_at DESC LIMIT 5)
    ORDER BY date DESC LIMIT 10
";

$activityResult = $conn->query($activityQuery);
if ($activityResult) {
    while ($activity = $activityResult->fetch_assoc()) {
        $recentActivities[] = $activity;
    }
}

$conn->close();
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

        <!-- Main Content -->
        <main class="vc-main-content">
            <!-- Header -->
            <header class="vc-header">
                <div>
                    <h1 class="vc-header-title">Reports & Analytics</h1>
                    <p class="vc-header-subtitle">System analytics, insights, and reports</p>
                </div>
                <a href="dashboard_admin.php" class="vc-btn">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </header>
            
            <div class="vc-reports-container">
                <!-- Stats Overview -->
                <div class="vc-stats-overview">
                    <div class="vc-stat-card">
                        <div class="vc-stat-value" id="totalUsers"><?php echo $stats['total_users']; ?></div>
                        <div class="vc-stat-label">Total Users</div>
                    </div>
                    <div class="vc-stat-card">
                        <div class="vc-stat-value" id="totalOrganizations"><?php echo $stats['total_organizations']; ?></div>
                        <div class="vc-stat-label">Organizations</div>
                    </div>
                    <div class="vc-stat-card">
                        <div class="vc-stat-value" id="totalVolunteers"><?php echo $stats['total_volunteers']; ?></div>
                        <div class="vc-stat-label">Volunteers</div>
                    </div>
                    <div class="vc-stat-card">
                        <div class="vc-stat-value" id="totalOpportunities"><?php echo $stats['total_opportunities']; ?></div>
                        <div class="vc-stat-label">Opportunities</div>
                    </div>
                    <div class="vc-stat-card">
                        <div class="vc-stat-value" id="totalApplications"><?php echo $stats['total_applications']; ?></div>
                        <div class="vc-stat-label">Applications</div>
                    </div>
                    <div class="vc-stat-card">
                        <div class="vc-stat-value" id="pendingApplications"><?php echo $stats['pending_applications']; ?></div>
                        <div class="vc-stat-label">Pending Apps</div>
                    </div>
                </div>
                
                <!-- Filters -->
                <div class="vc-report-filters">
                    <h3 style="margin-bottom: 15px; color: var(--vc-dark);">Filter Reports</h3>
                    <div class="vc-filter-group">
                        <div class="vc-date-range">
                            <label class="vc-filter-label">Date Range:</label>
                            <input type="date" id="startDate" class="vc-date-input">
                            <span>to</span>
                            <input type="date" id="endDate" class="vc-date-input">
                        </div>
                        <select id="reportType" class="vc-filter-select">
                            <option value="overview">Overview</option>
                            <option value="users">User Analytics</option>
                            <option value="opportunities">Opportunity Analytics</option>
                            <option value="applications">Application Analytics</option>
                        </select>
                        <button id="applyFilters" class="vc-filter-btn">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                    </div>
                    <div class="vc-report-actions">
                        <button id="refreshData" class="vc-report-refresh">
                            <i class="fas fa-sync-alt"></i> Refresh Data
                        </button>
                        <button id="downloadReport" class="vc-report-download">
                            <i class="fas fa-download"></i> Download Report
                        </button>
                    </div>
                </div>
                
                <!-- Charts Grid -->
                <div class="vc-reports-grid">
                    <!-- User Registrations Chart -->
                    <div class="vc-report-card">
                        <div class="vc-report-card-header">
                            <h3 class="vc-report-title">User Registrations</h3>
                            <div class="vc-report-icon">
                                <i class="fas fa-users"></i>
                            </div>
                        </div>
                        <div class="vc-chart-container">
                            <canvas id="userRegistrationsChart" width="400" height="250"></canvas>
                        </div>
                    </div>
                    
                    <!-- Opportunity Status Distribution -->
                    <div class="vc-report-card">
                        <div class="vc-report-card-header">
                            <h3 class="vc-report-title">Opportunity Status</h3>
                            <div class="vc-report-icon">
                                <i class="fas fa-tasks"></i>
                            </div>
                        </div>
                        <div class="vc-chart-container">
                            <canvas id="opportunityStatusChart" width="400" height="250"></canvas>
                        </div>
                    </div>
                    
                    <!-- Application Status -->
                    <div class="vc-report-card">
                        <div class="vc-report-card-header">
                            <h3 class="vc-report-title">Application Status</h3>
                            <div class="vc-report-icon">
                                <i class="fas fa-file-alt"></i>
                            </div>
                        </div>
                        <div class="vc-chart-container">
                            <canvas id="applicationStatusChart" width="400" height="250"></canvas>
                        </div>
                    </div>
                    
                    <!-- Monthly Activity -->
                    <div class="vc-report-card vc-full-width">
                        <div class="vc-report-card-header">
                            <h3 class="vc-report-title">Monthly Activity</h3>
                            <div class="vc-report-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                        </div>
                        <div class="vc-chart-container">
                            <canvas id="monthlyActivityChart" width="800" height="300"></canvas>
                        </div>
                    </div>
                    
                    <!-- Top Interests -->
                    <div class="vc-report-card">
                        <div class="vc-report-card-header">
                            <h3 class="vc-report-title">Top Interests</h3>
                            <div class="vc-report-icon">
                                <i class="fas fa-heart"></i>
                            </div>
                        </div>
                        <div class="vc-chart-container">
                            <canvas id="topInterestsChart" width="400" height="250"></canvas>
                        </div>
                    </div>
                    
                    <!-- Top Skills -->
                    <div class="vc-report-card">
                        <div class="vc-report-card-header">
                            <h3 class="vc-report-title">Top Skills</h3>
                            <div class="vc-report-icon">
                                <i class="fas fa-tools"></i>
                            </div>
                        </div>
                        <div class="vc-chart-container">
                            <canvas id="topSkillsChart" width="400" height="250"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Activity Table -->
                <div class="vc-report-card vc-full-width">
                    <div class="vc-report-card-header">
                        <h3 class="vc-report-title">Recent Activity</h3>
                        <div class="vc-report-icon">
                            <i class="fas fa-history"></i>
                        </div>
                    </div>
                    <table class="vc-data-table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Title/Description</th>
                                <th>Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recentActivities)): ?>
                                <?php foreach ($recentActivities as $activity): ?>
                                    <tr>
                                        <td>
                                            <span class="vc-status-badge <?php 
                                                if ($activity['type'] === 'user') echo 'vc-status-approved';
                                                elseif ($activity['type'] === 'opportunity') echo 'vc-status-open';
                                                else echo 'vc-status-pending';
                                            ?>">
                                                <?php echo ucfirst($activity['type']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($activity['title']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($activity['date'])); ?></td>
                                        <td>
                                            <span class="vc-status-badge vc-status-approved">
                                                Active
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; padding: 40px; color: var(--vc-gray);">
                                        <i class="fas fa-info-circle" style="font-size: 24px; margin-bottom: 10px; display: block;"></i>
                                        No recent activity found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
    
    <!-- JavaScript -->
<script src="assets/js/vc-admin.js"></script>
<script src="assets/js/vc-reports.js"></script>
<script>
// Debug script
console.log('=== REPORTS PAGE DEBUG ===');
console.log('Chart.js loaded:', typeof Chart !== 'undefined');

// Check all canvas elements exist
const canvasIds = [
    'userRegistrationsChart',
    'opportunityStatusChart', 
    'applicationStatusChart',
    'monthlyActivityChart',
    'topInterestsChart',
    'topSkillsChart'
];

console.log('Canvas elements found:');
canvasIds.forEach(id => {
    const canvas = document.getElementById(id);
    console.log(`- ${id}:`, canvas ? '✓ Found' : '✗ MISSING');
});

// Test API endpoint
console.log('Testing API endpoint...');
fetch('api/get_reports.php?type=overview')
    .then(response => {
        console.log('API Status:', response.status, response.statusText);
        return response.json();
    })
    .then(data => {
        console.log('API Response:', data);
        if (data.success) {
            console.log('API Success! Data structure:', Object.keys(data.data));
        } else {
            console.error('API Error:', data.message);
        }
    })
    .catch(error => {
        console.error('Fetch Error:', error);
    });
</script>
</body>
</html>